<?php
/**
 * app/WpAtsClient.php — v1.10.05
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
        private string $proxy
    ) {}

    /** Valori wpats.* da app_settings. */
    public static function settings(PDO $pdo): array
    {
        $d = ['wpats.enabled' => '0', 'wpats.base_url' => '', 'wpats.client_id' => 'portalmanager', 'wpats.verify_tls' => '1',
              'wpats.timeout' => '20', 'wpats.ca_file' => '', 'wpats.proxy' => '', 'wpats.push_on_change' => '1', 'wpats.pull_batch' => '20'];
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
            $timeout ?? max(5, min(120, (int)$s['wpats.timeout'])), trim($s['wpats.ca_file']), trim($s['wpats.proxy']));
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
        if ($json !== null) $opt[CURLOPT_POSTFIELDS] = $body;
        if ($this->caFile !== '' && is_file($this->caFile)) $opt[CURLOPT_CAINFO] = $this->caFile;
        if ($this->proxy !== '') $opt[CURLOPT_PROXY] = $this->proxy;
        curl_setopt_array($ch, $opt);
        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $err = $raw === false ? curl_error($ch) : null;
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $raw = $raw === false ? '' : (string)$raw;
        $j = null;
        if (str_contains($resH['content-type'] ?? '', 'json')) { $d = json_decode($raw, true); $j = is_array($d) ? $d : null; }
        return ['status' => $status, 'json' => $j, 'body' => $raw, 'headers' => $resH, 'error' => $err, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }

    /** Messaggio d'errore leggibile da una risposta. */
    public static function describe(array $r): string
    {
        if ($r['error']) return 'Connessione non riuscita: ' . $r['error'];
        $code = (string)($r['json']['error'] ?? $r['json']['code'] ?? '');
        $map = [
            'bad_signature' => 'firma non valida (segreto diverso da quello del sito)', 'unknown_client' => 'client ID diverso da quello del sito',
            'clock_skew' => 'orologio del server fuori sincrono (oltre 5 minuti)', 'ip_not_allowed' => 'IP di PortalManager non consentito dal sito',
            'not_configured' => 'segreto non configurato nel plugin', 'too_many_failures' => 'troppi tentativi falliti: sito temporaneamente bloccato (15 min)',
            'rest_no_route' => 'plugin pm-ats non attivo o URL errato', 'replay' => 'richiesta duplicata',
        ];
        foreach ($map as $k => $v) if (str_contains($code, $k)) return "HTTP {$r['status']}: $v";
        return 'HTTP ' . $r['status'] . ($code !== '' ? ': ' . $code : '');
    }
}
