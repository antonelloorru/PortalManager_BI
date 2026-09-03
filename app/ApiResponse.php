<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Helper output JSON per API pubbliche.
 * Applica header di sicurezza uniformi e CORS controllato.
 */
namespace App;

final class ApiResponse
{
    public static function json(int $status, array $payload, ?string $requestId = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        if ($requestId) header('X-Request-Id: ' . $requestId);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Preflight CORS solo per origin whitelisted. */
    public static function cors(string $allowedOriginsCsv): bool
    {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') return false;
        $allowed = array_filter(array_map('trim', explode(',', $allowedOriginsCsv)));
        if (!in_array($origin, $allowed, true) && !in_array('*', $allowed, true)) {
            return false;
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: X-PM-Client, X-PM-Timestamp, X-PM-Nonce, X-PM-Signature, Content-Type');
        header('Access-Control-Max-Age: 600');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        return true;
    }

    public static function newRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function readHeaders(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $out[$name] = (string)$v;
            }
        }
        return $out;
    }

    public static function rawBody(): string
    {
        $b = file_get_contents('php://input');
        return $b === false ? '' : $b;
    }
}
