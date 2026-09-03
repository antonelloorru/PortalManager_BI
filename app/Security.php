<?php
/**
 * certV 4.0 — app/Security.php
 * Invia header HTTP di sicurezza a livello applicativo
 * (fallback nel caso il .htaccess non venga onorato dal webserver).
 */

final class Security
{
    public static function sendHeaders(): void
    {
        if (headers_sent()) return;

        // Click-jacking
        header('X-Frame-Options: SAMEORIGIN');

        // MIME sniffing
        header('X-Content-Type-Options: nosniff');

        // Referrer
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissions-Policy: disabilita feature non usate
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');

        // HSTS (solo se HTTPS attivo)
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // CSP — policy bilanciata per i CDN già usati (Chart.js, FontAwesome, DataTables, jQuery)
        // Manteniamo 'unsafe-inline' per gli style/JS legacy inline del portale:
        // rimuoverlo richiederebbe rifattorizzare tutti gli script inline nei file PHP.
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://cdn.datatables.net https://code.jquery.com",
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.datatables.net https://fonts.googleapis.com",
            "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));

        // Rimuovi header che rivelano info server
        header_remove('X-Powered-By');
    }

    /**
     * Hash sicuro di una password (usa Argon2id se disponibile, altrimenti bcrypt).
     */
    public static function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Confronto sicuro (timing-attack safe) di due stringhe.
     */
    public static function constantTimeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Valida una stringa come slug alfanumerico (uso per input query).
     */
    public static function isSlug(string $s, int $max = 64): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{1,' . $max . '}$/', $s);
    }
}
