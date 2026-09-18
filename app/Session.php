<?php
/**
 * certV 4.0 — app/Session.php
 * Gestione sessione sicura: cookie HttpOnly/Secure/SameSite,
 * rigenerazione periodica, fingerprint binding, idle timeout.
 */

final class Session
{
    private const IDLE_TIMEOUT = 1800;  // 30 min di inattività
    private const ABS_LIFETIME = 28800; // 8 ore di sessione assoluta
    private const REGEN_EVERY  = 900;   // rigenera ID ogni 15 min

    /**
     * Nome univoco del cookie di sessione per questa istanza.
     * Previene collisioni di cookie tra istanze diverse sullo stesso host/IP.
     */
    public static function name(): string
    {
        $configured = Env::get('SESSION_COOKIE_NAME');
        if ($configured !== null && $configured !== '') {
            return $configured;
        }
        $appBase = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
        $realPath = realpath($appBase) ?: $appBase;
        $instanceHash = substr(hash('sha256', $realPath), 0, 8);
        return 'PMSESS_' . $instanceHash;
    }

    /**
     * Calcola dinamicamente il cookie path vincolato alla sottocartella web dell'istanza.
     */
    public static function cookiePath(): string
    {
        $configured = Env::get('SESSION_COOKIE_PATH');
        if ($configured !== null && $configured !== '') {
            return rtrim($configured, '/') . '/';
        }
        if (class_exists('Router')) {
            $base = Router::base();
            if ($base !== '') {
                return rtrim($base, '/') . '/';
            }
        }
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir = dirname($script);
        if ($dir === '.' || $dir === '/' || $dir === '\\') {
            return '/';
        }
        if (preg_match('#/(app|tools|cron|public)$#i', $dir)) {
            $dir = dirname($dir);
        }
        return ($dir === '/' || $dir === '\\' || $dir === '.') ? '/' : rtrim(str_replace('\\', '/', $dir), '/') . '/';
    }

    /**
     * Impronta crittografica dell'ambiente (directory fisica + schema DB).
     */
    public static function instanceHash(): string
    {
        $appBase = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
        $realPath = realpath($appBase) ?: $appBase;
        $dbName = defined('DB_NAME') ? DB_NAME : '';
        return substr(hash('sha256', $realPath . '|' . $dbName), 0, 16);
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        // Blocca output di ini_get in caso di error_reporting alto
        $secure = Env::get('COOKIE_SECURE', '0') === '1'
                  || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        $sameSite = Env::get('COOKIE_SAMESITE', 'Lax');

        session_name(self::name());
        session_set_cookie_params([
            'lifetime' => 0,                    // scade alla chiusura del browser
            'path'     => self::cookiePath(),   // vincolato alla subfolder dell'istanza
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        // Previeni sessioni con ID arbitrari (cookie injection)
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_trans_sid', '0');

        session_start();

        self::enforceLifecycle();
    }

    /**
     * Controlla timeout idle, absolute lifetime, fingerprint.
     * Se qualcosa non torna, distrugge la sessione e forza re-login.
     */
    private static function enforceLifecycle(): void
    {
        $now = time();
        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
        $isLogin = in_array($currentPage, ['login.php', 'install.php', 'unauthorized.php'], true);

        // Prima volta che vediamo questa sessione: inizializza i marker
        if (!isset($_SESSION['_sec'])) {
            $_SESSION['_sec'] = [
                'created'       => $now,
                'last_seen'     => $now,
                'last_regen'    => $now,
                'fingerprint'   => self::fingerprint(),
                'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
                'instance_hash' => self::instanceHash(),
            ];
            return;
        }

        $sec = $_SESSION['_sec'];

        // 0. Isolamento Istanza / Ambiente (Anti Cross-Instance Auth Leak)
        // Se la sessione proviene da un'altra cartella o da un altro database, invalidala subito
        $currentInst = self::instanceHash();
        if (!empty($sec['instance_hash']) && !hash_equals($sec['instance_hash'], $currentInst)) {
            self::destroy();
            if (!$isLogin) self::redirectLogin('instance_mismatch');
            return;
        }

        // 1. Scadenza assoluta
        if ($now - $sec['created'] > self::ABS_LIFETIME) {
            self::destroy();
            if (!$isLogin) self::redirectLogin('session_expired');
            return;
        }

        // 2. Idle timeout — solo se l'utente è loggato
        if (!empty($_SESSION['user_id']) && ($now - $sec['last_seen'] > self::IDLE_TIMEOUT)) {
            self::destroy();
            if (!$isLogin) self::redirectLogin('idle');
            return;
        }

        // 3. Fingerprint mismatch (session hijacking preventivo)
        if (!empty($_SESSION['user_id']) && $sec['fingerprint'] !== self::fingerprint()) {
            self::destroy();
            if (!$isLogin) self::redirectLogin('invalid');
            return;
        }

        // 4. Rigenera ID periodicamente per ridurre la finestra di fixation
        if ($now - $sec['last_regen'] > self::REGEN_EVERY) {
            @session_regenerate_id(true);
            $_SESSION['_sec']['last_regen'] = $now;
        }

        $_SESSION['_sec']['last_seen'] = $now;
    }

    /**
     * Fingerprint basato su User-Agent (non su IP: i NAT/mobili cambiano IP).
     * Usiamo HMAC con SESSION_SECRET così il valore non è manipolabile lato client.
     */
    private static function fingerprint(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $al = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $secret = Env::get('SESSION_SECRET', 'fallback');
        return hash_hmac('sha256', $ua . '|' . $al, $secret);
    }

    /**
     * Distrugge la sessione completamente (cookie + storage server).
     */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'] ?: self::cookiePath(),
                'domain'   => $p['domain'] ?? '',
                'secure'   => $p['secure'] ?? false,
                'httponly' => $p['httponly'] ?? true,
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        @session_destroy();
    }

    /**
     * Chiamare dopo login valido: rigenera ID e imposta marker.
     */
    public static function onLogin(int $userId, int $roleId, array $extra = []): void
    {
        @session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['role_id'] = $roleId;
        foreach ($extra as $k => $v) $_SESSION[$k] = $v;

        $_SESSION['_sec'] = [
            'created'       => time(),
            'last_seen'     => time(),
            'last_regen'    => time(),
            'fingerprint'   => self::fingerprint(),
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? '',
            'instance_hash' => self::instanceHash(),
        ];
    }

    /**
     * v1.9.51 — Riallinea l'identità di sessione all'assegnazione corrente a DB.
     *
     * Il ruolo (`role_id`) viene fissato al login e, senza questa sincronizzazione,
     * un cambio ruolo effettuato dall'amministratore NON ha effetto finché l'utente
     * non rifà login: la validazione RBAC a runtime continua a usare il ruolo vecchio
     * (utente con assegnazione corretta a DB ma bloccato con "Accesso negato").
     *
     * Va invocata dal middleware a ogni richiesta per un utente loggato. Esegue una
     * sola volta per richiesta. Se l'utente non esiste più o è disattivato, distrugge
     * la sessione (il middleware reindirizza poi al login).
     */
    public static function syncRole(\PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (empty($_SESSION['user_id'])) return;

        try {
            $st = $pdo->prepare('SELECT role_id, status FROM users WHERE id = ?');
            $st->execute([(int)$_SESSION['user_id']]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            $st->closeCursor();
        } catch (\Throwable $e) {
            return; // in caso di errore DB non blocchiamo la richiesta
        }

        if (!$row) { self::destroy(); return; }                    // utente rimosso
        if (($row['status'] ?? 'active') !== 'active') {           // utente disattivato
            self::destroy(); return;
        }
        $_SESSION['role_id'] = (int)$row['role_id'];               // allinea al DB
    }

    private static function redirectLogin(string $reason): void
    {
        // Usiamo un URL opaco se il router è già caricato
        $target = class_exists('Router') ? Router::url('login') : 'login.php';
        $sep = strpos($target, '?') !== false ? '&' : '?';
        header('Location: ' . $target . $sep . 'r=' . urlencode($reason));
        exit();
    }
}
