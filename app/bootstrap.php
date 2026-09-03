<?php
/**
 * ════════════════════════════════════════════════════════════════
 *  certV 4.1 — app/bootstrap.php (con 2FA)
 *  Entry point unico per il livello applicativo sicuro.
 *
 *  v4.1: aggiunge caricamento dei moduli 2FA (TOTP, EmailOTP, RecoveryCodes,
 *        TwoFactor) ma in modalità "lazy": vengono caricati solo se richiesti
 *        dalle pagine che ne fanno uso.
 * ════════════════════════════════════════════════════════════════
 */

if (!defined('APP_BASE')) define('APP_BASE', dirname(__DIR__));

// ── v1.7.21: Output buffer per consentire redirect anche dopo header.php ──
//   Senza questo, qualsiasi pagina che include header.php (che produce HTML)
//   e poi esegue redirect_self()/redirect() genera il warning
//   "Cannot modify header information - headers already sent".
//   L'output buffer mantiene tutto in memoria fino al termine della richiesta,
//   permettendo a redirect_self() di chiamare ob_end_clean() e poi header(Location).
if (!ob_get_level()) {
    ob_start();
}

// 1. Config (DB + costanti base) -------------------------------------
if (!file_exists(APP_BASE . '/Config.php')) {
    http_response_code(503);
    die('<div style="font-family:sans-serif;padding:40px;background:#fee2e2;color:#991b1b;border-radius:8px;margin:40px auto;max-width:500px"><h2>Config.php mancante</h2><p>Eseguire <code>install.php</code>.</p></div>');
}
require_once APP_BASE . '/Config.php';

// 2. Env (secret, env) ------------------------------------------------
require_once __DIR__ . '/Env.php';

// 3. Sessione hardened ------------------------------------------------
require_once __DIR__ . '/Session.php';
Session::start();

// 4. Headers di sicurezza ---------------------------------------------
require_once __DIR__ . '/Security.php';
Security::sendHeaders();

// 5. CSRF -------------------------------------------------------------
require_once __DIR__ . '/Csrf.php';

// 6. Router / URL helper ---------------------------------------------
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/UrlHelper.php';

// 7. Rate limiter -----------------------------------------------------
require_once __DIR__ . '/RateLimiter.php';

// 8. Funzioni legacy --------------------------------------------------
require_once APP_BASE . '/functions.php';

// 9. Access control ---------------------------------------------------
if (!function_exists('can')) {
    require_once APP_BASE . '/access_control.php';
}

// ── 2FA modules (v4.1) ───────────────────────────────────────────────
// Auto-loader minimale: classi caricate on-demand.
spl_autoload_register(function (string $class) {
    $map = [
        'Totp'           => __DIR__ . '/Totp.php',
        'EmailOtp'       => __DIR__ . '/EmailOtp.php',
        'RecoveryCodes'  => __DIR__ . '/RecoveryCodes.php',
        'TwoFactor'      => __DIR__ . '/TwoFactor.php',
    ];
    if (isset($map[$class]) && file_exists($map[$class])) {
        require_once $map[$class];
    }
});

// ── v1.7.16: Auto-bump versione spostato in Config.php
//   (era qui ma falliva perché lo scope di $pdo non era garantito).
//   Version.php caricato solo per esporre la classe Version a chi la usa.
if (file_exists(__DIR__ . '/Version.php')) {
    require_once __DIR__ . '/Version.php';

    // v1.7.61: innesco dell'auto-bump. Storicamente era demandato a Config.php,
    // che però è un file protetto e mai sovrascritto dai pacchetti di update:
    // la chiamata non è mai arrivata sulle installazioni e le versioni a DB
    // (schema_version / release_label) restavano congelate.
    // Qui $pdo è già stato creato da Config.php: lo leggiamo da $GLOBALS per
    // non dipendere dallo scope. Eseguito una sola volta per sessione/versione.
    if (PHP_SAPI !== 'cli'
        && class_exists('Version')
        && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO
        && (($_SESSION['pm_version_synced'] ?? null) !== PM_VERSION)) {
        try {
            Version::autoBumpIfNeeded($GLOBALS['pdo']);
            $_SESSION['pm_version_synced'] = PM_VERSION;
        } catch (Throwable $e) { /* no-op: mai bloccare il bootstrap */ }
    }
}
