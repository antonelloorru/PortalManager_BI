<?php
/**
 * app/WpAtsClient.php — v1.10.05 (v1.10.16: diagnostica dell'handshake, CA automatica, codici d'errore effettivi)
 * Client HTTP verso il plugin WordPress «PortalManager ATS» (pm-ats) del sito pubblico.
 *
 * La connessione parte SEMPRE da PortalManager (server locale) verso il sito: nessuna porta in ingresso.
 * Firma HMAC-SHA256 identica a App\PublicApiAuth:
 *   X-PM-Client, X-PM-Timestamp, X-PM-Nonce (32 hex), X-PM-Signature =
 *   hex(hmac_sha256(secret, METHOD \n ROUTE \n TIMESTAMP \n NONCE \n sha256(body)))
 * ROUTE = rotta REST senza prefisso e senza query (es. /pm-ats/v1/sync/jobs).
 *
 * Configurazione: app_settings wpats.* (URL, client id, TLS, proxy, timeout);
 * segreto SOLO in .env.php (PM_WPATS_SECRET), mai nel database o nel repository.
 */
declare(strict_types=1);

final class WpAtsClient
{
    public const NS = '/pm-ats/v1';

    private function __construct(
        private string $base,     // es. https://www.example.it/wp-json/pm-ats/v1  oppure  https://www.example.it/?rest_route=/pm-ats/v1
        private string $clientId,
        private string $secret,
        private bool $verifyTls,
        private int $timeout,
        private string $caFile,
        private string $proxy,
        private string $resolveIp = ''   // v1.10.17 — IP forzato per il nome host (NAT hairpin / DNS interno)
    ) {}

    /** Valori wpats.* da app_settings. */
    public static function settings(PDO $pdo): array
    {
        $d = ['wpats.enabled' => '0', 'wpats.base_url' => '', 'wpats.client_id' => 'portalmanager', 'wpats.verify_tls' => '1',
              'wpats.timeout' => '20', 'wpats.ca_file' => '', 'wpats.proxy' => '', 'wpats.push_on_change' => '1', 'wpats.pull_batch' => '20', 'wpats.resolve_ip' => ''];
        try {
            foreach ($pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'wpats.%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v)
                $d[$k] = (string)$v;
        } catch (Throwable $e) {}
        return $d;
    }

    public static function secret(): string
    {
        require_once __DIR__ . '/Env.php';
        return (string)(Env::get('PM_WPATS_SECRET', '') ?? '');
    }

    /** null se la configurazione è incompleta. */
    public static function fromSettings(PDO $pdo, ?int $timeout = null): ?self
    {
        $s = self::settings($pdo);
        $base = self::normalizeBase($s['wpats.base_url']);
        $sec = self::secret();
        if ($base === null || $sec === '' || trim($s['wpats.client_id']) === '') return null;
        return new self($base, trim($s['wpats.client_id']), $sec, $s['wpats.verify_tls'] !== '0',
            $timeout ?? max(5, min(120, (int)$s['wpats.timeout'])), trim($s['wpats.ca_file']), trim($s['wpats.proxy']), trim($s['wpats.resolve_ip']));
    }

    /** Accetta l'URL del sito, di /wp-json o dell'API; restituisce la base dell'API pm-ats/v1 o null. */
    public static function normalizeBase(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('~^https?://[^\s/?#]+~i', $url)) return null;
        if (str_contains($url, 'rest_route=')) {
            $p = strpos($url, 'rest_route=');
            return substr($url, 0, $p) . 'rest_route=' . self::NS;
        }
        $url = rtrim(preg_replace('#\?.*$#', '', $url), '/');
        if (str_ends_with($url, self::NS)) return $url;
        if (str_ends_with($url, '/wp-json')) return $url . self::NS;
        return $url . '/wp-json' . self::NS;
    }

    public function base(): string { return $this->base; }

    /**
     * @return array{status:int, json:?array, body:string, headers:array<string,string>, error:?string, ms:int}
     */
    public function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        $method = strtoupper($method);
        $route = self::NS . $path;
        $body = $json === null ? '' : (string)json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $ts = (string)time(); $nonce = bin2hex(random_bytes(16));
        $sig = hash_hmac('sha256', $method . "\n" . $route . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $this->secret);

        $url = $this->base . $path;
        if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        $hdr = ['X-PM-Client: ' . $this->clientId, 'X-PM-Timestamp: ' . $ts, 'X-PM-Nonce: ' . $nonce, 'X-PM-Signature: ' . $sig,
                'Accept: application/json, application/octet-stream', 'User-Agent: PortalManager-WpAts/' . (defined('PM_VERSION') ? PM_VERSION : '1')];
        if ($json !== null) $hdr[] = 'Content-Type: application/json; charset=utf-8';

        return $this->exec($method, $url, $hdr, $json !== null ? $body : null);
    }

    /**
     * v1.10.16 — Chiamata NON firmata (diagnostica: raggiungibilità, TLS, redirect, REST attivo, plugin presente).
     * $url assoluto oppure percorso relativo alla base dell'API.
     */
    public function probe(string $url): array
    {
        if (!preg_match('~^https?://~i', $url)) $url = $this->base . $url;
        return $this->exec('GET', $url, ['Accept: application/json', 'User-Agent: PortalManager-WpAts/' . (defined('PM_VERSION') ? PM_VERSION : '1')], null);
    }

    /** Radice REST del sito (…/wp-json oppure …/?rest_route=/) dalla base dell'API. */
    public function restRoot(): string
    {
        return str_contains($this->base, 'rest_route=') ? substr($this->base, 0, (int)strpos($this->base, 'rest_route=')) . 'rest_route=/' : substr($this->base, 0, -strlen(self::NS)) . '/';
    }

    /**
     * v1.10.16 — File CA da usare: impostato > curl.cainfo / openssl.cafile di php.ini > bundle di XAMPP / PHP trovato
     * accanto all'eseguibile. '' = archivio del sistema (su Windows anche l'archivio certificati nativo).
     * @return array{0:string,1:string} [percorso, origine]
     */
    public static function caBundle(string $configured = ''): array
    {
        if ($configured !== '' && is_file($configured)) return [$configured, 'impostazioni'];
        foreach (['curl.cainfo', 'openssl.cafile'] as $k) { $v = (string)ini_get($k); if ($v !== '' && is_file($v)) return [$v, "php.ini $k"]; }
        $d = dirname(PHP_BINARY);
        foreach ([$d . '/extras/ssl/cacert.pem', $d . '/cacert.pem', dirname($d) . '/apache/bin/curl-ca-bundle.crt', $d . '/../apache/bin/curl-ca-bundle.crt',
                  dirname(__DIR__) . '/cacert.pem'] as $c)
            if (is_file($c)) return [(string)realpath($c), 'rilevato automaticamente'];
        return ['', 'archivio del sistema'];
    }

    /** v1.10.16 — impronta del segreto, identica a PM_ATS_Settings::fingerprint() del plugin (≥ 1.1.1). */
    public static function fingerprint(string $secret): string
    {
        return $secret === '' ? '' : substr(hash('sha256', 'pm-ats-fp|' . $secret), 0, 12);
    }

    public function secretFingerprint(): string { return self::fingerprint($this->secret); }
    public function clientId(): string { return $this->clientId; }
    public function verifiesTls(): bool { return $this->verifyTls; }
    public function caFile(): string { return $this->caFile; }
    public function resolveIp(): string { return $this->resolveIp; }
    public function proxy(): string { return $this->proxy; }

    private function exec(string $method, string $url, array $hdr, ?string $body): array
    {
        $resH = [];
        $ch = curl_init($url);
        $opt = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout), CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls, CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$resH): int {
                $p = strpos($line, ':');
                if ($p !== false) $resH[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ];
        if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $body;
        // v1.10.16 — CA: su XAMPP/Windows PHP spesso non ha un bundle (errore 60 «unable to get local issuer certificate»)
        [$ca] = self::caBundle($this->caFile);
        if ($this->verifyTls && $ca !== '') $opt[CURLOPT_CAINFO] = $ca;
        if ($this->verifyTls && $ca === '' && PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA'))
            $opt[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        if ($this->proxy !== '') $opt[CURLOPT_PROXY] = $this->proxy;
        // v1.10.17 — IP forzato: si collega all'IP indicato mantenendo nome host, SNI e verifica del certificato
        if ($this->resolveIp !== '' && filter_var($this->resolveIp, FILTER_VALIDATE_IP)) {
            $p = parse_url($url);
            $port = (int)($p['port'] ?? (strtolower($p['scheme'] ?? 'https') === 'https' ? 443 : 80));
            $ip = str_contains($this->resolveIp, ':') ? '[' . $this->resolveIp . ']' : $this->resolveIp;
            $opt[CURLOPT_RESOLVE] = [($p['host'] ?? '') . ':' . $port . ':' . $ip];
        }
        curl_setopt_array($ch, $opt);
        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $err = $raw === false ? curl_error($ch) : null;
        $errno = $raw === false ? curl_errno($ch) : 0;
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $info = ['redirect' => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL), 'ip' => (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP),
                 'ssl_verify' => (int)curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT), 'url' => $url, 'ca' => $ca];
        curl_close($ch);
        $raw = $raw === false ? '' : (string)$raw;
        $j = null;
        if (str_contains($resH['content-type'] ?? '', 'json')) { $d = json_decode($raw, true); $j = is_array($d) ? $d : null; }
        return ['status' => $status, 'json' => $j, 'body' => $raw, 'headers' => $resH, 'error' => $err, 'errno' => $errno, 'info' => $info,
                'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }

    /** Messaggio d'errore leggibile da una risposta (codice effettivo + causa + rimedio). */
    public static function describe(array $r): string
    {
        $a = self::analyze($r);
        return $a['code'] . ' — ' . $a['cause'] . ($a['fix'] !== '' ? ' → ' . $a['fix'] : '');
    }

    /** Errori di trasporto curl: errno → [causa, rimedio]. */
    private const CURL = [
        6  => ['nome host non risolto (DNS)', 'verificare l\'URL e il DNS del server PortalManager'],
        7  => ['connessione rifiutata o porta chiusa', 'verificare firewall in uscita, proxy e porta 443 del sito'],
        28 => ['tempo scaduto', 'aumentare il timeout o verificare proxy/firewall'],
        35 => ['handshake TLS fallito', 'protocollo/cifrari non compatibili o intercettazione TLS del proxy'],
        51 => ['certificato del sito non valido per il nome host', 'usare l\'URL con il nome presente nel certificato'],
        52 => ['risposta vuota dal server', 'verificare proxy/WAF davanti al sito'],
        56 => ['connessione interrotta in ricezione', 'verificare proxy/WAF davanti al sito'],
        58 => ['certificato client non valido', 'rimuovere la configurazione del certificato client'],
        60 => ['certificato del sito non verificabile (CA mancante o catena incompleta)', 'impostare il File CA (es. P:\\xampp\\apache\\bin\\curl-ca-bundle.crt) oppure completare la catena sul sito'],
        77 => ['file CA non leggibile', 'correggere il percorso del File CA'],
    ];

    /**
     * v1.10.16 — Analisi di una risposta: codice effettivo, origine (rete | tls | plugin | wordpress | intermediario | http),
     * causa e rimedio. Usa X-PM-ATS-Version / X-PM-ATS-Error (plugin ≥ 1.1.1) per distinguere il plugin da WAF/CDN.
     * @return array{code:string,origin:string,cause:string,fix:string,data:array}
     */
    public static function analyze(array $r): array
    {
        $st = (int)$r['status']; $h = $r['headers'] ?? []; $j = $r['json'] ?? null;
        if (!empty($r['error'])) {
            $n = (int)($r['errno'] ?? 0);
            [$c, $f] = self::CURL[$n] ?? ['errore di rete', ''];
            if (($n === 60 || $n === 51) && preg_match('/subject name|does not match/i', (string)$r['error']))
                [$c, $f] = ['il certificato del sito non corrisponde al nome host dell\'URL', 'usare nell\'URL il nome presente nel certificato (es. con/senza www), non l\'indirizzo IP'];
            // v1.10.17 — cURL 28 in fase di connessione: la porta non risponde (non è lentezza del sito)
            if ($n === 28 && preg_match('/Failed to connect|Connection timed out|connect/i', (string)$r['error']))
                [$c, $f] = ['connessione TCP non stabilita: nessuna risposta dalla porta del sito',
                            'il pacchetto non arriva al sito (non è un problema di timeout): firewall in uscita, proxy aziendale obbligatorio, NAT hairpin se il sito è nella stessa rete, sito non raggiungibile su questa porta — vedere il passo «3b Rete»'];
            return ['code' => 'cURL ' . $n, 'origin' => in_array($n, [35, 51, 58, 60, 77], true) ? 'tls' : 'rete', 'cause' => $c . ': ' . $r['error'], 'fix' => $f, 'data' => []];
        }
        if ($st >= 300 && $st < 400) {
            $loc = (string)($r['info']['redirect'] ?? $h['location'] ?? '');
            $nb = $loc !== '' ? (self::normalizeBase($loc) ?? '') : '';
            return ['code' => "HTTP $st", 'origin' => 'http', 'cause' => 'il sito reindirizza' . ($loc !== '' ? ' a ' . $loc : ''),
                    'fix' => $nb !== '' ? 'impostare come URL API: ' . preg_replace('~/sync/.*$~', '', $nb) : 'usare l\'URL finale (https, www) del sito', 'data' => ['location' => $loc]];
        }
        $fromPlugin = isset($h['x-pm-ats-version']);
        $reason = (string)($h['x-pm-ats-error'] ?? (is_array($j) ? ($j['data']['reason'] ?? '') : ''));
        $wpCode = is_array($j) ? (string)($j['code'] ?? $j['error'] ?? '') : '';
        if ($reason === '' && str_starts_with($wpCode, 'pm_ats_')) { $reason = substr($wpCode, 7); $fromPlugin = true; }
        $d = is_array($j) && is_array($j['data'] ?? null) ? $j['data'] : [];
        if ($reason !== '') {
            $fx = match ($reason) {
                'bad_signature'     => 'segreto diverso fra i due lati: confrontare l\'impronta del segreto (PortalManager › Impostazioni e plugin › Connessione) o reincollare il codice di connessione'
                                       . (isset($d['route']) ? '; rotta vista dal sito ' . $d['route'] : ''),
                'unknown_client'    => 'allineare il Client ID con quello del plugin (Impostazioni › Connessione)',
                'clock_skew'        => 'sincronizzare l\'orologio del server PortalManager (w32tm /resync)' . (isset($d['server_time']) ? '; scarto ' . (time() - (int)$d['server_time']) . ' s' : ''),
                'ip_not_allowed'    => 'aggiungere ' . ($d['client_ip'] ?? 'l\'IP pubblico di PortalManager') . ' in «IP consentiti» del plugin'
                                       . (($d['ip_source'] ?? '') === 'REMOTE_ADDR' ? ' (se il sito è dietro CDN/proxy impostare «Origine IP client»)' : ''),
                'not_configured'    => 'generare il segreto nel plugin (Impostazioni › Connessione) e incollare il codice in PortalManager',
                'too_many_failures' => 'attendere 15 minuti (blocco per tentativi falliti) dopo aver corretto la causa',
                'missing_auth_headers' => 'un proxy/firewall rimuove le intestazioni X-PM-*: consentirle',
                'replay'            => 'ripetere il test',
                default             => '',
            };
            return ['code' => "HTTP $st $reason", 'origin' => 'plugin', 'cause' => (string)($j['message'] ?? $reason), 'fix' => $fx, 'data' => $d];
        }
        if ($wpCode !== '') {
            $m = [
                'rest_no_route'        => ['rotta pm-ats inesistente', 'plugin pm-ats non attivo, URL errato o versione del plugin precedente'],
                'rest_not_logged_in'   => ['l\'API REST richiede l\'accesso', 'un plugin di sicurezza blocca la REST API agli anonimi: escludere il namespace pm-ats/v1'],
                'rest_forbidden'       => ['accesso negato da WordPress', 'un plugin di sicurezza limita la REST API: escludere pm-ats/v1'],
                'rest_cannot_access'   => ['REST API disattivata', 'riattivare la REST API o escludere pm-ats/v1 dal blocco'],
                'rest_disabled'        => ['REST API disattivata', 'riattivare la REST API o escludere pm-ats/v1 dal blocco'],
            ];
            [$c, $f] = $m[$wpCode] ?? [(string)($j['message'] ?? $wpCode), ''];
            return ['code' => "HTTP $st $wpCode", 'origin' => 'wordpress', 'cause' => $c, 'fix' => $f, 'data' => []];
        }
        if ($st >= 400 || $st === 0 || !is_array($j)) {
            $title = preg_match('~<title[^>]*>(.*?)</title>~is', (string)$r['body'], $mm) ? trim(html_entity_decode(strip_tags($mm[1]))) : '';
            $srv = trim(($h['server'] ?? '') . (isset($h['cf-ray']) ? ' (Cloudflare ' . $h['cf-ray'] . ')' : ''));
            $who = $fromPlugin ? 'plugin' : 'intermediario';
            $c = ($st >= 400 ? 'risposta non generata dal plugin pm-ats' : 'risposta non JSON') . ($title !== '' ? ': «' . mb_substr($title, 0, 80) . '»' : '')
               . ($srv !== '' ? ' — server ' . $srv : '') . ' — ' . ($h['content-type'] ?? 'senza Content-Type');
            $f = match (true) {
                $st === 403 => 'firewall/WAF/CDN o plugin di sicurezza davanti a WordPress: consentire /wp-json/pm-ats/ all\'IP di PortalManager',
                $st === 401 => 'autenticazione HTTP (htpasswd) sul sito: escludere /wp-json/pm-ats/',
                $st === 404 => 'URL errato o permalink disattivati: usare l\'URL API indicato nel plugin (anche ?rest_route=/pm-ats/v1)',
                $st === 503 => 'sito in manutenzione o sovraccarico: escludere /wp-json/pm-ats/ dalla modalità manutenzione',
                $st >= 500  => 'errore del server WordPress: vedere il log PHP del sito',
                default     => 'verificare l\'URL API',
            };
            return ['code' => 'HTTP ' . $st, 'origin' => $who, 'cause' => $c, 'fix' => $f, 'data' => []];
        }
        return ['code' => 'HTTP ' . $st, 'origin' => 'http', 'cause' => 'risposta inattesa', 'fix' => '', 'data' => []];
    }
}
