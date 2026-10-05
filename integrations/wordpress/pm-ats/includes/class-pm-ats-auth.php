<?php
/**
 * Autenticazione delle chiamate di PortalManager (stesso schema di PortalManager PublicApiAuth).
 *
 *   X-PM-Client:    <client_id>
 *   X-PM-Timestamp: <unix seconds, tolleranza ±300 s>
 *   X-PM-Nonce:     <32 hex, monouso per 10 minuti>
 *   X-PM-Signature: hex(hmac_sha256(secret, "<METHOD>\n<ROUTE>\n<TIMESTAMP>\n<NONCE>\n<sha256(body)>"))
 *
 * ROUTE è la rotta REST senza prefisso e senza query string, es. "/pm-ats/v1/sync/jobs".
 * Controlli: IP consentiti (CIDR), finestra temporale, nonce anti-replay, firma a tempo costante,
 * blocco temporaneo dopo ripetuti fallimenti dallo stesso IP.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Auth
{
    public const SKEW = 300;
    public const NONCE_TTL = 600;
    public const MAX_FAIL = 20;          // fallimenti per IP nella finestra
    public const FAIL_WINDOW = 900;

    /** @return true|WP_Error */
    public static function verify(WP_REST_Request $req)
    {
        $ip = self::clientIp();
        $failKey = 'pm_ats_f_' . md5($ip);
        if ((int)get_transient($failKey) >= self::MAX_FAIL) return self::err('too_many_failures', 429);

        $secret = PM_ATS_Settings::secret();
        if ($secret === null || $secret === '') return self::fail($failKey, 'not_configured', 503);

        if (!self::ipAllowed((string)PM_ATS_Settings::get('allowed_ips'), $ip)) return self::fail($failKey, 'ip_not_allowed', 403);

        $client = trim((string)$req->get_header('x_pm_client'));
        $ts     = trim((string)$req->get_header('x_pm_timestamp'));
        $nonce  = strtolower(trim((string)$req->get_header('x_pm_nonce')));
        $sig    = strtolower(trim((string)$req->get_header('x_pm_signature')));
        if ($client === '' || $ts === '' || $nonce === '' || $sig === '') return self::fail($failKey, 'missing_auth_headers', 401);
        if (!hash_equals((string)PM_ATS_Settings::get('client_id'), $client)) return self::fail($failKey, 'unknown_client', 401);
        if (!ctype_digit($ts) || abs(time() - (int)$ts) > self::SKEW) return self::fail($failKey, 'clock_skew', 401);
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) return self::fail($failKey, 'bad_nonce', 401);

        $canonical = strtoupper($req->get_method()) . "\n" . $req->get_route() . "\n" . $ts . "\n" . $nonce . "\n"
                   . hash('sha256', (string)$req->get_body());
        if (!hash_equals(hash_hmac('sha256', $canonical, $secret), $sig)) return self::fail($failKey, 'bad_signature', 401);

        $nKey = 'pm_ats_n_' . md5($client . '|' . $nonce);
        if (get_transient($nKey)) return self::fail($failKey, 'replay', 401);
        set_transient($nKey, 1, self::NONCE_TTL);

        update_option('pm_ats_last_contact', ['at' => time(), 'ip' => $ip, 'route' => $req->get_route()], false);
        return true;
    }

    private static function fail(string $key, string $code, int $status): WP_Error
    {
        set_transient($key, (int)get_transient($key) + 1, self::FAIL_WINDOW);
        PM_ATS_Log::add('auth', $status, $code);
        return self::err($code, $status);
    }

    private static function err(string $code, int $status): WP_Error
    {
        return new WP_Error('pm_ats_' . $code, $code, ['status' => $status]);
    }

    public static function clientIp(): string
    {
        $src = (string)PM_ATS_Settings::get('ip_source');
        $v = (string)($_SERVER[$src] ?? '');
        if ($src === 'HTTP_X_FORWARDED_FOR' && $v !== '') $v = trim(explode(',', $v)[0]);
        if (!filter_var($v, FILTER_VALIDATE_IP)) $v = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($v, FILTER_VALIDATE_IP) ? $v : '0.0.0.0';
    }

    /** Lista vuota = nessuna restrizione. */
    public static function ipAllowed(string $csv, string $ip): bool
    {
        $csv = trim($csv);
        if ($csv === '') return true;
        foreach (explode(',', $csv) as $cidr) if (self::inCidr($ip, trim($cidr))) return true;
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') return false;
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $a = @inet_pton($ip); $b = @inet_pton((string)$net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
        $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
        $bytes = intdiv($bits, 8); $rem = $bits % 8;
        if ($bytes && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
        if ($rem === 0) return true;
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return ((ord($a[$bytes]) & ord($mask)) === (ord($b[$bytes]) & ord($mask)));
    }

    /** Firma una richiesta (usato dagli strumenti di test e documentato per il client PortalManager). */
    public static function sign(string $secret, string $method, string $route, string $body, ?int $ts = null, ?string $nonce = null): array
    {
        $ts = $ts ?? time(); $nonce = $nonce ?? bin2hex(random_bytes(16));
        $sig = hash_hmac('sha256', strtoupper($method) . "\n" . $route . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
        return ['X-PM-Timestamp' => (string)$ts, 'X-PM-Nonce' => $nonce, 'X-PM-Signature' => $sig];
    }
}
