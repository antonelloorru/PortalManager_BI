<?php
/**
 * certV 4.0 — app/RateLimiter.php
 * Rate limiting basato su filesystem (nessuna dipendenza da Redis/APCu).
 * Usato principalmente per proteggere il login da brute-force.
 *
 * Storage: APP_BASE/uploads/.ratelimit/<sha256(key)>.json
 */

final class RateLimiter
{
    private static function storageDir(): string
    {
        $dir = APP_BASE . '/uploads/.ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // Protezione accesso web
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
        return $dir;
    }

    /**
     * Incrementa il contatore. Ritorna true se la chiave può procedere,
     * false se è oltre il limite.
     *
     * @param string $key       identificativo (es. "login:admin@foo.com" o IP)
     * @param int    $max       tentativi massimi nella finestra
     * @param int    $window    secondi della finestra scorrevole
     * @param int    $lockout   secondi di blocco dopo aver superato max (0 = solo rolling)
     */
    public static function attempt(string $key, int $max = 5, int $window = 900, int $lockout = 900): bool
    {
        $file = self::storageDir() . '/' . hash('sha256', $key) . '.json';
        $now  = time();

        $data = ['attempts' => [], 'locked_until' => 0];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $j = json_decode($raw ?: '[]', true);
            if (is_array($j)) $data = array_merge($data, $j);
        }

        // Ancora in lockout?
        if ($data['locked_until'] > $now) return false;

        // Pulisci tentativi fuori finestra
        $data['attempts'] = array_filter($data['attempts'], fn($t) => $t >= ($now - $window));

        // Se limite già raggiunto senza che ci siano nuovi tentativi validi
        if (count($data['attempts']) >= $max) {
            $data['locked_until'] = $now + $lockout;
            self::save($file, $data);
            return false;
        }

        // Registra il nuovo tentativo
        $data['attempts'][] = $now;
        if (count($data['attempts']) >= $max) {
            $data['locked_until'] = $now + $lockout;
        }
        self::save($file, $data);
        return true;
    }

    /**
     * Reset contatore (es. dopo login valido).
     */
    public static function reset(string $key): void
    {
        $file = self::storageDir() . '/' . hash('sha256', $key) . '.json';
        if (file_exists($file)) @unlink($file);
    }

    /**
     * Secondi rimanenti di lockout (0 = non bloccato).
     */
    public static function lockedFor(string $key): int
    {
        $file = self::storageDir() . '/' . hash('sha256', $key) . '.json';
        if (!file_exists($file)) return 0;
        $j = json_decode(@file_get_contents($file) ?: '[]', true);
        if (!is_array($j)) return 0;
        return max(0, ($j['locked_until'] ?? 0) - time());
    }

    private static function save(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data), LOCK_EX);
        @chmod($file, 0600);
    }
}
