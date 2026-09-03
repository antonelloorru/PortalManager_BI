<?php
/**
 * ════════════════════════════════════════════════════════════════
 *  certV 4.1 — r.php (Front Controller)
 *  v4.1: aggiunge 2fa_verify come pagina "semi-pubblica" (accessibile
 *        senza session user_id, ma SOLO se c'è una 2FA pending)
 * ════════════════════════════════════════════════════════════════
 */
require_once __DIR__ . '/app/bootstrap.php';

$slug = trim((string)($_GET['r'] ?? ''));

if (!Security::isSlug($slug, 32)) {
    http_response_code(404);
    die(render404());
}

$page = Router::resolve($slug);

if (!$page || Router::isRestricted($page)) {
    http_response_code(404);
    if (function_exists('write_log')) {
        write_log('Router', 'warning', "Slug non valido: $slug",
            $_SESSION['user_id'] ?? null,
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    }
    die(render404());
}

$targetFile = APP_BASE . '/' . $page . '.php';
if (!is_file($targetFile)) {
    http_response_code(500);
    die(render500());
}

// ── Pagine pubbliche: nessun controllo ─────────────────────────
$public = ['login', 'unauthorized'];

// ── Pagine "semi-pubbliche" (v4.1): accessibili senza user_id
//    SOLO se c'è uno stato pending corrispondente
$semiPublic = ['2fa_verify'];

if (!in_array($page, $public, true) && empty($_SESSION['user_id'])) {
    // 2fa_verify accessibile solo se c'è una 2FA pending in sessione
    if (in_array($page, $semiPublic, true)) {
        require_once __DIR__ . '/app/TwoFactor.php';
        require_once __DIR__ . '/app/Totp.php';
        require_once __DIR__ . '/app/EmailOtp.php';
        require_once __DIR__ . '/app/RecoveryCodes.php';
        if (!TwoFactor::getPending()) {
            redirect('login');
        }
        // Pending valido → procedi senza controllo RBAC
    } else {
        redirect('login');
    }
}

// ── Verifica permessi RBAC ─────────────────────────────────────
$alwaysAllowed = ['index', 'user_profile', 'notifications', 'logout', 'login', 'unauthorized',
                  '2fa_verify', '2fa_settings'];
if (!in_array($page, $alwaysAllowed, true) && !empty($_SESSION['user_id'])) {
    $role = (int)($_SESSION['role_id'] ?? 99);
    if ($role !== 1 && function_exists('can')) {
        if (!can('view', $page . '.php')) {
            redirect('unauthorized');
        }
    }
}

// ── Pagina corrente accessibile via current_page() ─────────────
$GLOBALS['_router_current_page'] = $page;
$_SERVER['PHP_SELF'] = '/' . $page . '.php';
$_SERVER['SCRIPT_NAME'] = '/' . $page . '.php';

require $targetFile;

function render404(): string
{
    return '<!DOCTYPE html><html lang="it"><meta charset="UTF-8"><title>404</title>
    <body style="font-family:system-ui,sans-serif;padding:60px 20px;text-align:center;background:#f1f5f9;color:#1e293b">
      <div style="max-width:420px;margin:0 auto;background:#fff;padding:40px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.06)">
        <div style="font-size:48px;margin-bottom:16px">🕵</div>
        <h1 style="font-size:22px;margin:0 0 8px">Pagina non trovata</h1>
        <p style="color:#64748b;font-size:14px">Il link richiesto non esiste o non è più disponibile.</p>
      </div>
    </body></html>';
}

function render500(): string
{
    return '<!DOCTYPE html><html lang="it"><meta charset="UTF-8"><title>500</title>
    <body style="font-family:system-ui,sans-serif;padding:60px 20px;text-align:center;background:#fef2f2;color:#991b1b">
      <h1>Errore interno</h1>
      <p>Contatta l\'amministratore.</p>
    </body></html>';
}
