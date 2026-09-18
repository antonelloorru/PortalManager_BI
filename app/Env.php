<?php
/**
 * certV 4.0 — app/Env.php
 * Caricamento di variabili d'ambiente da .env.php (fuori dal repo pubblico).
 * Se .env.php manca, i secret vengono generati e persistiti al primo avvio.
 */

final class Env
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;

        $appBase = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
        $envFile = $appBase . '/.env.php';

        if (!file_exists($envFile)) {
            self::bootstrapSecrets($envFile);
        }

        if (file_exists($envFile)) {
            $data = require $envFile;
            if (is_array($data)) {
                self::$data = array_merge(self::$data, $data);
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string)$val;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        self::load();
        return self::$data[$key] ?? $default;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    public static function isDebug(): bool
    {
        return self::get('APP_DEBUG', '0') === '1';
    }

    /**
     * Genera al primo avvio i secret necessari (CSRF, HMAC URL, session).
     * Il file viene reso non-web-accessible dal .htaccess.
     */
    private static function bootstrapSecrets(string $envFile): void
    {
        $secrets = [
            'APP_ENV'        => 'production',
            'APP_DEBUG'      => '0',
            'APP_SECRET'     => bin2hex(random_bytes(32)),
            'CSRF_SECRET'    => bin2hex(random_bytes(32)),
            'URL_SECRET'     => bin2hex(random_bytes(32)),
            'SESSION_SECRET' => bin2hex(random_bytes(32)),
            'COOKIE_SECURE'  => '0', // impostare a 1 quando HTTPS è attivo
        ];

        $lines = ["<?php\n// certV 4.0 — .env.php (auto-generato il " . date('c') . ")\n// NON committare questo file nel repository.\nreturn [\n"];
        foreach ($secrets as $k => $v) {
            $lines[] = "    '" . $k . "' => '" . addslashes($v) . "',\n";
        }
        $lines[] = "];\n";

        $written = @file_put_contents($envFile, implode('', $lines), LOCK_EX);
        if ($written === false) {
            // Fallback: se non si può scrivere su disco, tieni in memoria per questa request
            self::$data = $secrets;
            return;
        }
        @chmod($envFile, 0600);
    }
}
