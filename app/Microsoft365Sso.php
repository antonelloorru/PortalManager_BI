<?php
/**
 * PortalManager — app/Microsoft365Sso.php
 *
 * Single Sign-On con Microsoft 365 / Entra ID (Azure AD) via OpenID Connect
 * Authorization Code Flow + PKCE, in PHP nativo (openssl + cURL, zero dipendenze).
 *
 * La MFA è applicata da Microsoft (Conditional Access): l'app verifica il claim
 * `amr` dell'id_token e, se `MS_REQUIRE_MFA=1`, rifiuta i token senza 'mfa'.
 *
 * Sicurezza:
 *   - state (anti-CSRF), nonce (anti-replay), PKCE S256 (anti intercettazione code);
 *   - id_token verificato: firma RS256 via JWKS, iss, aud, exp/nbf, nonce, (amr);
 *   - client_secret SOLO in .env.php (fuori webroot), mai in DB o nel repo.
 *
 * Config (.env.php):
 *   SSO_MS_ENABLED=1
 *   MS_TENANT_ID=<tenant-guid o dominio>
 *   MS_CLIENT_ID=<app (client) id>
 *   MS_CLIENT_SECRET=<client secret>
 *   MS_REDIRECT_URI=https://server/portalmanager/auth_microsoft.php
 *   MS_ALLOWED_DOMAIN=wetechs.it        (opzionale: dominio email consentito)
 *   MS_REQUIRE_MFA=1                     (default 1)
 *   MS_AUTO_PROVISION=0                  (default 0: l'utente deve già esistere in `users`)
 */

final class Microsoft365Sso
{
    // ── configurazione ────────────────────────────────────────────────
    public static function enabled(): bool
    {
        return Env::get('SSO_MS_ENABLED', '0') === '1' && self::isConfigured();
    }

    public static function isConfigured(): bool
    {
        return self::cfg('MS_TENANT_ID') && self::cfg('MS_CLIENT_ID')
            && self::cfg('MS_CLIENT_SECRET') && self::cfg('MS_REDIRECT_URI');
    }

    private static function cfg(string $k, ?string $d = null): ?string { return Env::get($k, $d); }
    /** In modalità test l'MFA viene misurata e riportata, non imposta. */
    private static bool $testMode = false;
    private static function requireMfa(): bool { return !self::$testMode && self::cfg('MS_REQUIRE_MFA', '1') === '1'; }

    /** Override HTTP per i test automatici: fn(string $method, string $url, ?string $body): ?array */
    public static $httpOverride = null;
    private static function autoProvision(): bool { return self::cfg('MS_AUTO_PROVISION', '0') === '1'; }
    private static function authority(): string { return 'https://login.microsoftonline.com/' . rawurlencode((string)self::cfg('MS_TENANT_ID')); }

    // ── STEP 1: URL di autorizzazione (PKCE + state + nonce) ──────────
    public static function authorizeUrl(): string
    {
        $verifier  = self::b64url(random_bytes(32));
        $challenge = self::b64url(hash('sha256', $verifier, true));
        $state     = bin2hex(random_bytes(16));
        $nonce     = bin2hex(random_bytes(16));
        $_SESSION['ms_sso'] = ['verifier' => $verifier, 'state' => $state, 'nonce' => $nonce, 'ts' => time()];

        $params = [
            'client_id'             => self::cfg('MS_CLIENT_ID'),
            'response_type'         => 'code',
            'redirect_uri'          => self::cfg('MS_REDIRECT_URI'),
            'response_mode'         => 'query',
            'scope'                 => 'openid profile email',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ];
        // Forza la MFA in fase di richiesta tramite Authentication Context di Entra
        // (MS_ACR_VALUE = id del contesto, es. 'c1', configurato in Entra per richiedere MFA).
        $acr = self::cfg('MS_ACR_VALUE');
        if ($acr) {
            $params['claims'] = json_encode(['id_token' => ['acrs' => ['essential' => true, 'values' => [$acr]]]]);
        }
        return self::authority() . '/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    // ── STEP 2: callback -> code -> id_token verificato -> claims ─────
    /**
     * @return array{ok:bool, claims?:array, error?:string}
     */
    public static function handleCallback(array $get, bool $test = false): array
    {
        self::$testMode = $test;
        $sess = $_SESSION['ms_sso'] ?? null;
        unset($_SESSION['ms_sso']);
        if (!$sess)                                   return self::err('Sessione SSO assente o scaduta.');
        if (time() - (int)$sess['ts'] > 600)          return self::err('Richiesta SSO scaduta, riprova.');
        if (!empty($get['error']))                    return self::err('Microsoft: ' . (string)($get['error_description'] ?? $get['error']));
        if (empty($get['code']) || empty($get['state'])) return self::err('Risposta SSO incompleta.');
        if (!hash_equals((string)$sess['state'], (string)$get['state'])) return self::err('State non valido (possibile CSRF).');

        $tok = self::exchangeCode((string)$get['code'], (string)$sess['verifier']);
        if (empty($tok['id_token'])) {
            return self::err('Scambio token fallito: ' . (string)($tok['error_description'] ?? $tok['error'] ?? 'errore sconosciuto'));
        }

        $v = self::verifyIdToken((string)$tok['id_token'], (string)$sess['nonce']);
        if (!$v['ok']) return $v;

        // dominio consentito (opzionale)
        $dom = self::cfg('MS_ALLOWED_DOMAIN');
        if ($dom && !str_ends_with(strtolower($v['claims']['email']), '@' . strtolower($dom))) {
            return self::err('Dominio email non autorizzato per l\'accesso SSO.');
        }
        return ['ok' => true, 'claims' => $v['claims']];
    }

    private static function exchangeCode(string $code, string $verifier): array
    {
        $post = http_build_query([
            'client_id'     => self::cfg('MS_CLIENT_ID'),
            'client_secret' => self::cfg('MS_CLIENT_SECRET'),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::cfg('MS_REDIRECT_URI'),
            'code_verifier' => $verifier,
            'scope'         => 'openid profile email',
        ]);
        $res = self::httpPost(self::authority() . '/oauth2/v2.0/token', $post);
        return is_array($res) ? $res : [];
    }

    // ── verifica id_token (firma RS256/JWKS + claims) ────────────────
    /**
     * @param array|null $jwksInject  per test: {keys:[{kid,n,e,kty}]}
     * @return array{ok:bool, claims?:array, error?:string}
     */
    public static function verifyIdToken(string $jwt, ?string $expectedNonce, ?array $jwksInject = null): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return self::err('Token malformato.');
        [$h64, $p64, $s64] = $parts;
        $head = json_decode(self::b64urlDecode($h64), true);
        $pay  = json_decode(self::b64urlDecode($p64), true);
        if (!is_array($head) || !is_array($pay)) return self::err('Token illeggibile.');
        if (($head['alg'] ?? '') !== 'RS256')    return self::err('Algoritmo di firma non supportato.');

        $jwks = $jwksInject ?? self::fetchJwks();
        $pem  = self::jwkToPem($jwks, (string)($head['kid'] ?? ''));
        if (!$pem) return self::err('Chiave di firma (kid) non trovata nel JWKS.');

        $ok = openssl_verify($h64 . '.' . $p64, self::b64urlDecode($s64), $pem, OPENSSL_ALGO_SHA256) === 1;
        if (!$ok) return self::err('Firma dell\'id_token non valida.');

        // claims di sicurezza
        if ((string)($pay['aud'] ?? '') !== (string)self::cfg('MS_CLIENT_ID')) return self::err('Audience non valida.');
        $iss = (string)($pay['iss'] ?? '');
        if (strpos($iss, 'login.microsoftonline.com') === false && strpos($iss, 'sts.windows.net') === false) {
            return self::err('Issuer non valido.');
        }
        $tid = self::cfg('MS_TENANT_ID');
        if ($tid && isset($pay['tid']) && !in_array($tid, ['common', 'organizations'], true)
            && strcasecmp((string)$pay['tid'], (string)$tid) !== 0) {
            return self::err('Tenant non autorizzato.');
        }
        $now = time();
        if ((int)($pay['exp'] ?? 0) < $now - 60)  return self::err('id_token scaduto.');
        if ((int)($pay['nbf'] ?? 0) > $now + 300) return self::err('id_token non ancora valido.');
        if ($expectedNonce !== null && !hash_equals((string)$expectedNonce, (string)($pay['nonce'] ?? ''))) {
            return self::err('Nonce non valido (possibile replay).');
        }
        if (self::requireMfa()) {
            // MFA obbligatoria: l'id_token DEVE riportare amr contenente 'mfa'.
            $amr = $pay['amr'] ?? [];
            if (!is_array($amr) || !in_array('mfa', $amr, true)) {
                return self::err('Autenticazione a più fattori (MFA) non soddisfatta.');
            }
        }
        $email = strtolower(trim((string)($pay['email'] ?? $pay['preferred_username'] ?? $pay['upn'] ?? '')));
        if ($email === '') return self::err('L\'id_token non contiene un\'email utilizzabile.');

        return ['ok' => true, 'claims' => [
            'email' => $email,
            'name'  => (string)($pay['name'] ?? ''),
            'oid'   => (string)($pay['oid'] ?? ''),
            'tid'   => (string)($pay['tid'] ?? ''),
            'amr'   => $pay['amr'] ?? [],
            'acrs'  => $pay['acrs'] ?? ($pay['acr'] ?? null),
        ]];
    }

    // ── JWKS ──────────────────────────────────────────────────────────
    private static function fetchJwks(): array
    {
        // discovery -> jwks_uri (con fallback all'endpoint standard)
        $conf = self::httpGet(self::authority() . '/v2.0/.well-known/openid-configuration');
        $uri  = is_array($conf) && !empty($conf['jwks_uri'])
              ? (string)$conf['jwks_uri']
              : self::authority() . '/discovery/v2.0/keys';
        $jwks = self::httpGet($uri);
        return is_array($jwks) ? $jwks : ['keys' => []];
    }

    /** Trova la chiave per kid e costruisce il PEM (SubjectPublicKeyInfo) da n/e. */
    private static function jwkToPem(array $jwks, string $kid): ?string
    {
        foreach (($jwks['keys'] ?? []) as $k) {
            if (($k['kty'] ?? '') !== 'RSA') continue;
            if ($kid !== '' && ($k['kid'] ?? '') !== $kid) continue;
            $n = self::b64urlDecode((string)($k['n'] ?? ''));
            $e = self::b64urlDecode((string)($k['e'] ?? ''));
            if ($n === '' || $e === '') continue;
            return self::rsaPublicKeyPem($n, $e);
        }
        return null;
    }

    /** Costruisce un PEM RSA public key da modulo/esponente binari (DER minimale). */
    private static function rsaPublicKeyPem(string $modulus, string $exponent): string
    {
        $mod = self::derPositiveInteger($modulus);
        $exp = self::derPositiveInteger($exponent);
        $rsaKey = self::derSequence($mod . $exp);                       // RSAPublicKey
        $algId  = self::derSequence(
            self::derOid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") .      // rsaEncryption 1.2.840.113549.1.1.1
            "\x05\x00"                                                   // NULL
        );
        $spki = self::derSequence($algId . self::derBitString($rsaKey));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    // DER helpers
    private static function derLen(int $len): string
    {
        if ($len < 0x80) return chr($len);
        $b = ltrim(pack('N', $len), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }
    private static function derPositiveInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') $bytes = "\x00";
        if (ord($bytes[0]) & 0x80) $bytes = "\x00" . $bytes;            // evita numero negativo
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }
    private static function derSequence(string $c): string { return "\x30" . self::derLen(strlen($c)) . $c; }
    private static function derBitString(string $c): string { $c = "\x00" . $c; return "\x03" . self::derLen(strlen($c)) . $c; }
    private static function derOid(string $oid): string { return "\x06" . self::derLen(strlen($oid)) . $oid; }

    // ── HTTP (cURL) ───────────────────────────────────────────────────
    private static function httpPost(string $url, string $body): ?array
    {
        if (is_callable(self::$httpOverride)) return (self::$httpOverride)('POST', $url, $body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $out = curl_exec($ch); curl_close($ch);
        return $out === false ? null : json_decode((string)$out, true);
    }
    private static function httpGet(string $url): ?array
    {
        if (is_callable(self::$httpOverride)) return (self::$httpOverride)('GET', $url, null);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $out = curl_exec($ch); curl_close($ch);
        return $out === false ? null : json_decode((string)$out, true);
    }


    // ── TEST / DIAGNOSTICA CONFIGURAZIONE ─────────────────────────────
    /**
     * Verifica la configurazione senza login utente.
     * @return array<int,array{check:string,status:string,detail:string}>  status: ok|warn|fail|skip
     */
    public static function diagnostics(): array
    {
        $r = [];
        $add = function (string $c, string $st, string $d) use (&$r) { $r[] = ['check' => $c, 'status' => $st, 'detail' => $d]; };

        // 1. prerequisiti PHP
        $add('Estensione OpenSSL', extension_loaded('openssl') ? 'ok' : 'fail',
            extension_loaded('openssl') ? 'presente (verifica firma RS256)' : 'mancante: abilitare extension=openssl in php.ini');
        $curl = function_exists('curl_init');
        $add('Estensione cURL', $curl ? 'ok' : (is_callable(self::$httpOverride) ? 'skip' : 'fail'),
            $curl ? 'presente' : (is_callable(self::$httpOverride) ? 'simulata (test automatico)' : 'mancante: abilitare extension=curl in php.ini'));

        $ss = strtolower((string)self::cfg('COOKIE_SAMESITE', 'Lax'));
        $add('Cookie di sessione (SameSite)', $ss === 'strict' ? 'fail' : 'ok',
            $ss === 'strict' ? 'Strict: il ritorno da Microsoft perde la sessione. Impostare COOKIE_SAMESITE=Lax'
                             : ucfirst($ss) . ': compatibile con il redirect da Microsoft');

        // 2. campi obbligatori
        $miss = [];
        foreach (['MS_TENANT_ID', 'MS_CLIENT_ID', 'MS_CLIENT_SECRET', 'MS_REDIRECT_URI'] as $k) if (!self::cfg($k)) $miss[] = $k;
        $add('Parametri obbligatori', $miss ? 'fail' : 'ok', $miss ? 'mancano: ' . implode(', ', $miss) : 'tenant, client ID, secret, redirect URI presenti');

        $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $cid = (string)self::cfg('MS_CLIENT_ID', '');
        if ($cid !== '') $add('Formato Client ID', preg_match($guid, $cid) ? 'ok' : 'warn',
            preg_match($guid, $cid) ? 'GUID valido' : 'atteso un GUID (Application (client) ID)');

        // 3. redirect URI
        $ru = (string)self::cfg('MS_REDIRECT_URI', '');
        if ($ru !== '') {
            $https = stripos($ru, 'https://') === 0;
            $end   = (bool)preg_match('~/auth_microsoft\.php$~', parse_url($ru, PHP_URL_PATH) ?? '');
            $host  = $_SERVER['HTTP_HOST'] ?? '';
            $same  = $host === '' || strcasecmp((string)parse_url($ru, PHP_URL_HOST) . (parse_url($ru, PHP_URL_PORT) ? ':' . parse_url($ru, PHP_URL_PORT) : ''), $host) === 0
                                  || strcasecmp((string)parse_url($ru, PHP_URL_HOST), explode(':', $host)[0]) === 0;
            $st = (!$https || !$end) ? 'fail' : ($same ? 'ok' : 'warn');
            $add('Redirect URI', $st, !$https ? 'deve essere HTTPS' : (!$end ? 'deve terminare con /auth_microsoft.php'
                 : ($same ? 'HTTPS, endpoint corretto, host coerente' : "host diverso da quello in uso ($host): verificare")));
        }

        // 4. MFA / ACR
        $mfa = self::cfg('MS_REQUIRE_MFA', '1') === '1';
        $acr = (string)self::cfg('MS_ACR_VALUE', '');
        $add('Richiesta MFA', $mfa ? 'ok' : 'warn', $mfa ? 'attiva: token senza amr=mfa rifiutati' : 'DISATTIVA: gli accessi SSO non richiedono MFA');
        if ($acr !== '') {
            $okAcr = (bool)preg_match('/^c([1-9]|[1-9][0-9])$/', $acr);
            $add('Authentication Context', $okAcr ? 'ok' : 'warn', $okAcr ? "'$acr' inviato nella richiesta (MFA forzata al login)" : "'$acr' non nel formato Entra c1..c99");
        } else {
            $add('Authentication Context', 'skip', 'non impostato: la MFA dipende dal Conditional Access del tenant');
        }

        if ($miss) return $r;

        // 5. tenant: OpenID discovery
        $conf = self::httpGet(self::authority() . '/v2.0/.well-known/openid-configuration');
        if (!is_array($conf) || empty($conf['issuer'])) {
            $e = is_array($conf) ? (string)($conf['error_description'] ?? $conf['error'] ?? '') : '';
            $add('Tenant (discovery OpenID)', 'fail', $e !== '' ? self::short($e) : 'endpoint non raggiungibile: verificare tenant ID e uscita HTTPS del server');
            return $r;
        }
        $add('Tenant (discovery OpenID)', 'ok', 'raggiungibile · issuer ' . $conf['issuer']);

        // 6. JWKS
        $jwks = self::httpGet((string)($conf['jwks_uri'] ?? self::authority() . '/discovery/v2.0/keys'));
        $n = is_array($jwks) ? count($jwks['keys'] ?? []) : 0;
        $add('Chiavi di firma (JWKS)', $n > 0 ? 'ok' : 'fail', $n > 0 ? "$n chiavi RSA disponibili" : 'nessuna chiave: impossibile verificare gli id_token');

        // 7. credenziali applicazione (client credentials: valida client ID + secret senza utente)
        $tok = self::httpPost(self::authority() . '/oauth2/v2.0/token', http_build_query([
            'client_id'     => self::cfg('MS_CLIENT_ID'),
            'client_secret' => self::cfg('MS_CLIENT_SECRET'),
            'grant_type'    => 'client_credentials',
            'scope'         => 'https://graph.microsoft.com/.default',
        ]));
        if (is_array($tok) && !empty($tok['access_token'])) {
            $add('Client ID + secret', 'ok', 'credenziali valide (token applicativo emesso)');
        } else {
            $e = is_array($tok) ? (string)($tok['error_description'] ?? $tok['error'] ?? '') : '';
            $hint = match (true) {
                str_contains($e, 'AADSTS7000215') => 'client secret errato (copiare il VALUE, non il Secret ID)',
                str_contains($e, 'AADSTS7000222') => 'client secret scaduto: generarne uno nuovo',
                str_contains($e, 'AADSTS700016')  => 'applicazione non trovata nel tenant: verificare Client ID / tenant',
                str_contains($e, 'AADSTS90002')   => 'tenant non trovato',
                default                           => $e !== '' ? self::short($e) : 'nessuna risposta dal token endpoint',
            };
            $add('Client ID + secret', 'fail', $hint);
        }
        return $r;
    }

    private static function short(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > 220 ? mb_substr($s, 0, 220) . '…' : $s;
    }

    // ── util ──────────────────────────────────────────────────────────
    private static function err(string $m): array { return ['ok' => false, 'error' => $m]; }
    private static function b64url(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
    private static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return (string)base64_decode($s, true);
    }
}
