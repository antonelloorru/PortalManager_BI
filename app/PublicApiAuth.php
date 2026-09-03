<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24
 * PublicApiAuth — Autenticazione HMAC-SHA256 per API pubbliche careers.
 *
 * Schema firma (header richiesti):
 *   X-PM-Client:    <client_id>
 *   X-PM-Timestamp: <unix seconds, tolleranza ±300s>
 *   X-PM-Nonce:     <hex 32 chars, unico per client entro finestra>
 *   X-PM-Signature: <hex hmac_sha256(secret, "<METHOD>\n<PATH>\n<TIMESTAMP>\n<NONCE>\n<sha256(body)>")>
 *
 * Il body per GET è stringa vuota. Per multipart, il sha256 è del raw body ricevuto.
 */
namespace App;

use PDO;
use RuntimeException;

final class PublicApiAuth
{
    public const CLOCK_SKEW = 300;
    public const NONCE_TTL  = 600;

    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Verifica firma della request e restituisce l'array client autorizzato.
     * @throws RuntimeException con code = HTTP status e message = error_code
     */
    public function authenticate(string $method, string $path, string $rawBody, array $headers): array
    {
        $clientId  = trim((string)($headers['X-PM-Client']    ?? ''));
        $ts        = trim((string)($headers['X-PM-Timestamp'] ?? ''));
        $nonce     = trim((string)($headers['X-PM-Nonce']     ?? ''));
        $signature = trim((string)($headers['X-PM-Signature'] ?? ''));

        if ($clientId === '' || $ts === '' || $nonce === '' || $signature === '') {
            throw new RuntimeException('missing_auth_headers', 401);
        }
        if (!ctype_digit($ts)) {
            throw new RuntimeException('bad_timestamp', 401);
        }
        $tsInt = (int)$ts;
        if (abs(time() - $tsInt) > self::CLOCK_SKEW) {
            throw new RuntimeException('clock_skew', 401);
        }
        if (!preg_match('/^[a-f0-9]{32}$/i', $nonce)) {
            throw new RuntimeException('bad_nonce', 401);
        }

        $client = $this->loadClient($clientId);
        if ($client === null || (int)$client['is_active'] !== 1) {
            throw new RuntimeException('unknown_client', 401);
        }

        $ip = self::clientIp();
        if (!self::ipAllowed((string)($client['allowed_ips'] ?? ''), $ip)) {
            throw new RuntimeException('ip_not_allowed', 403);
        }

        $secret = $this->resolveSecret((string)$client['client_id']);
        if ($secret === null || hash('sha256', $secret) !== $client['client_secret_hash']) {
            throw new RuntimeException('bad_secret_binding', 500);
        }

        $bodyHash = hash('sha256', $rawBody);
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . $bodyHash;
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            throw new RuntimeException('bad_signature', 401);
        }

        // Nonce replay guard (in-memory table riutilizza rate_limit con endpoint '__nonce__')
        $this->assertNonceUnique($clientId, $nonce);

        $this->touchClient((int)$client['id']);
        return $client;
    }

    public function requireScope(array $client, string $scope): void
    {
        $scopes = array_map('trim', explode(',', (string)$client['scopes']));
        if (!in_array($scope, $scopes, true)) {
            throw new RuntimeException('scope_denied', 403);
        }
    }

    /** Rate limit sliding window: max $limit hits di $endpoint nel $windowSec per $bucket. */
    public function rateLimit(string $bucket, string $endpoint, int $limit, int $windowSec): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM public_api_rate_limit
             WHERE bucket_key = ? AND endpoint = ? AND hit_at >= (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$bucket, $endpoint, $windowSec]);
        $hits = (int)$stmt->fetchColumn();
        if ($hits >= $limit) {
            throw new RuntimeException('rate_limited', 429);
        }
        $ins = $this->pdo->prepare(
            "INSERT INTO public_api_rate_limit (bucket_key, endpoint, ip) VALUES (?, ?, ?)"
        );
        $ins->execute([$bucket, $endpoint, self::packIp(self::clientIp())]);
    }

    public function audit(?string $clientId, string $endpoint, string $method, int $status, ?string $requestId = null, ?string $errorCode = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO public_api_audit (client_id, endpoint, method, http_status, ip, user_agent, request_id, error_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $clientId, $endpoint, strtoupper($method), $status,
            self::packIp(self::clientIp()),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $requestId, $errorCode,
        ]);
    }

    public static function clientIp(): string
    {
        $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function packIp(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        return $bin === false ? null : $bin;
    }

    private function loadClient(string $clientId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM public_api_clients WHERE client_id = ? LIMIT 1");
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function touchClient(int $id): void
    {
        $this->pdo->prepare("UPDATE public_api_clients SET last_used_at = NOW() WHERE id = ?")
                  ->execute([$id]);
    }

    /**
     * Il secret NON è mai in DB: solo il suo sha256. Il secret è caricato da:
     *  - variabile d'ambiente PM_API_SECRET_<CLIENT_ID_UPPER>
     *  - fallback: file config/api_secrets.php che ritorna ['<client_id>' => '<secret>']
     */
    private function resolveSecret(string $clientId): ?string
    {
        $envKey = 'PM_API_SECRET_' . strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', $clientId));
        $env = getenv($envKey);
        if ($env !== false && $env !== '') return (string)$env;

        $file = dirname(__DIR__) . '/config/api_secrets.php';
        if (is_file($file)) {
            /** @var array<string,string> $map */
            $map = require $file;
            if (isset($map[$clientId]) && is_string($map[$clientId]) && $map[$clientId] !== '') {
                return $map[$clientId];
            }
        }
        return null;
    }

    private function assertNonceUnique(string $clientId, string $nonce): void
    {
        $bucket = 'nonce:' . $clientId . ':' . $nonce;
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM public_api_rate_limit
             WHERE bucket_key = ? AND endpoint = '__nonce__' AND hit_at >= (NOW() - INTERVAL ? SECOND) LIMIT 1"
        );
        $stmt->execute([$bucket, self::NONCE_TTL]);
        if ($stmt->fetchColumn() !== false) {
            throw new RuntimeException('nonce_replay', 401);
        }
        $this->pdo->prepare(
            "INSERT INTO public_api_rate_limit (bucket_key, endpoint, ip) VALUES (?, '__nonce__', ?)"
        )->execute([$bucket, self::packIp(self::clientIp())]);
    }

    private static function ipAllowed(string $cidrList, string $ip): bool
    {
        $list = array_filter(array_map('trim', explode(',', $cidrList)));
        if (!$list) return true; // vuoto = tutti ammessi
        foreach ($list as $cidr) {
            if (self::ipMatch($ip, $cidr)) return true;
        }
        return false;
    }

    private static function ipMatch(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) return hash_equals($cidr, $ip);
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip); $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) return false;
        $bits = (int)$bits; $bytes = intdiv($bits, 8); $rem = $bits % 8;
        if ($bytes && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) return false;
        if ($rem === 0) return true;
        $mask = chr(0xFF << (8 - $rem) & 0xFF);
        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subBin[$bytes]) & ord($mask));
    }
}
