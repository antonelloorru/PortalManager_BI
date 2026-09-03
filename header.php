<?php
// ── v1.7.22: Output buffer DEFENSIVO ──
// Garantisce che qualunque output di questo file (HTML <!DOCTYPE>, <head>, <body>)
// finisca in un buffer PHP invece che essere inviato direttamente al browser.
// Senza questo, redirect_self()/redirect() dopo header.php genererebbe il warning
// "Cannot modify header information - headers already sent".
// Funziona INDIPENDENTEMENTE da bootstrap.php (resistente a OPCache stale).
if (!ob_get_level()) {
    ob_start();
}

/**
 * certV 4.1 — header.php (versione completa integrata)
 *
 * Integra:
 *   - Patch sicurezza 4.0: link opachi via Router (url_safe), CSRF token meta
 *   - Patch 2FA 4.1: voce "Sicurezza account" nel menu utente
 *   - Voci sistema admin (Super Admin only): DB Upgrade, Verifica schema,
 *     Health check, Aggiorna sistema (con backup integrato)
 *
 * Note importanti:
 *   - I link al Router usano url_safe('<page>'), gli slug sono opachi
 *   - I tool admin sistema (system_update, db_upgrade, ecc.) usano path
 *     diretto perché sono pagine "restricted" (non nel Router whitelist)
 *   - La logica RBAC e' inalterata rispetto all'originale
 */

if (!class_exists('Session')) {
    require_once __DIR__ . '/app/bootstrap.php';
}

$u_id     = (int)($_SESSION['user_id']  ?? 0);
$u_role   = (int)($_SESSION['role_id']  ?? 99);
$is_modal = isset($_GET['modal']);

$settings     = load_settings();
$primary      = $settings['primary_color'] ?? '#0ea5e9';
$app_name     = $settings['app_name']      ?? 'certV';

// v5.03: branding completo
require_once __DIR__ . '/app/BrandingHelper.php';
$brand_accent       = $settings['accent_color']       ?? '#5b21b6';
$brand_success      = $settings['success_color']      ?? '#10b981';
$brand_warning      = $settings['warning_color']      ?? '#f59e0b';
$brand_danger       = $settings['danger_color']       ?? '#ef4444';
$brand_sb_bg        = $settings['sidebar_bg']         ?? '#0f172a';
$brand_sb_text      = $settings['sidebar_text']       ?? '#cbd5e1';
$brand_sb_hover     = $settings['sidebar_hover']      ?? '#1e293b';
$brand_font_key     = $settings['font_family']        ?? 'system';
$brand_layout       = $settings['layout_template']    ?? 'modern';
$brand_logo_path    = $settings['logo_path']          ?? '';
$brand_favicon_path = $settings['favicon_path']       ?? '';
$brand_app_tagline  = $settings['app_tagline']        ?? '';
$brand_font_css     = BrandingHelper::getFontCss($brand_font_key);
$brand_font_cdn     = BrandingHelper::getFontCdn($brand_font_key);

$is_admin     = ($u_role === 1);
$is_hr        = ($u_role <= 2);
$is_brand_mgr = ($u_role === 3);
$is_tl        = ($u_role === 4);
$is_recruiter = ($u_role === 5);
$is_employee  = ($u_role === 6);
$can_recruit  = ($u_role <= 5);

$role_label = match($u_role) {
    1 => 'Super Admin', 2 => 'HR Director', 3 => 'Brand Manager',
    4 => 'Team Leader', 5 => 'Recruiter',   6 => 'Dipendente', default => 'Utente'
};

$notif_count = unread_notifications();

/**
 * Marca come "active" la voce corrispondente alla pagina corrente.
 * Funziona sia con include diretto (PHP_SELF=brand.php) sia via router.
 */
function ia(string $p): string {
    $curr = current_page();
    $target = str_ends_with($p, '.php') ? substr($p, 0, -4) : $p;
    return $curr === $target ? 'active' : '';
}

function darken_hex(string $hex, float $factor = 0.8): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    [$r,$g,$b] = [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
    return sprintf('#%02x%02x%02x',(int)($r*$factor),(int)($g*$factor),(int)($b*$factor));
}
$primary_dark = darken_hex($primary, 0.82);
// v5.5: brand attivi per filtro gerarchico nel menu Catalogo
$nav_brands_sidebar = [];
try {
    $nav_brands_sidebar = $pdo->query(
        "SELECT b.id, b.name,
                (SELECT COUNT(*) FROM certifications c WHERE c.brand_id = b.id AND c.is_active = 1) AS cert_count
           FROM brands b
          WHERE EXISTS (SELECT 1 FROM certifications c2 WHERE c2.brand_id = b.id AND c2.is_active = 1)
          ORDER BY b.name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $nav_brands_sidebar = []; }
$nav_current_brand = isset($_GET['f_br']) ? (int)$_GET['f_br'] : 0;

// v4.2: visibilità condizionale di "Sicurezza account"
// Mostra il link solo se l'admin ha autorizzato 2FA per questo utente.
// Il Super Admin lo vede sempre (per gestire la propria 2FA).
$show_2fa_link = false;
if ($u_id > 0) {
    if ($is_admin) {
        $show_2fa_link = true;
    } else {
        if (class_exists('TwoFactor')) {
            try {
                $show_2fa_link = TwoFactor::hasAuthorization($pdo, $u_id);
            } catch (Throwable $e) {
                $show_2fa_link = false;
            }
        }
    }
}

$emp_link = !empty($_SESSION['employee_id'])
    ? url('employee_profile', ['id' => (int)$_SESSION['employee_id']])
    : url('user_profile', ['id' => $u_id]);

// v1.7.9: carico struttura menu personalizzabile
if (!class_exists('MenuManager')) {
    @require_once __DIR__ . '/app/MenuManager.php';
}
$menu_structure = [];
if (class_exists('MenuManager') && $u_id > 0) {
    try {
        $menu_mgr = new MenuManager($pdo);
        $menu_structure = $menu_mgr->loadMenuFor($u_id, $u_role);
    } catch (Throwable $e) {
        $menu_structure = [];
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($app_name) ?></title>

<?php /* v5.03: favicon dinamica */ ?>
<?php if ($brand_favicon_path && file_exists(__DIR__ . '/' . $brand_favicon_path)): ?>
<link rel="icon" type="<?= str_ends_with($brand_favicon_path,'.svg') ? 'image/svg+xml' : 'image/png' ?>" href="<?= h($brand_favicon_path) ?>?v=<?= filemtime(__DIR__ . '/' . $brand_favicon_path) ?>">
<?php else: ?>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='<?= urlencode($primary) ?>'/><text x='50' y='68' font-size='60' font-family='sans-serif' font-weight='800' text-anchor='middle' fill='white'>C</text></svg>">
<?php endif; ?>

<?php /* v5.03: CDN font se non system */ ?>
<?php if ($brand_font_cdn): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?= h($brand_font_cdn) ?>">
<?php endif; ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<style>
:root {
  --p: <?= h($primary) ?>;
  --pd: <?= h($primary_dark) ?>;
  --accent: <?= h($brand_accent) ?>;
  --sb-w: 240px;
  --topbar-h: 52px;
  --sb: <?= h($brand_sb_bg) ?>;
  --sbh: <?= h($brand_sb_hover) ?>;
  --sb-text: <?= h($brand_sb_text) ?>;
  --sba: <?= h(BrandingHelper::darkenHex($brand_sb_hover, 1.15)) ?>;
  --muted: #64748b;
  --light: #94a3b8;
  --bg: #f1f5f9;
  --border: #e2e8f0;
  --danger: <?= h($brand_danger) ?>;
  --warning: <?= h($brand_warning) ?>;
  --success: <?= h($brand_success) ?>;
  --info: #3b82f6;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: <?= $brand_font_css ?>;
  background: var(--bg);
  font-size: 14px;
  color: #1e293b;
  overflow-x: hidden;
}
.layout { display: flex; min-height: 100vh; }
.sidebar {
  width: var(--sb-w); background: var(--sb); color: #cbd5e1;
  display: flex; flex-direction: column;
  position: fixed; top: 0; left: 0;
  height: 100vh; height: 100dvh; overflow: hidden;
  z-index: 200;
  transition: transform .25s cubic-bezier(.4,0,.2,1), box-shadow .25s;
  flex-shrink: 0;
}
.sb-scroll { flex: 1; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; scrollbar-color: #334155 #0f172a; }
.sb-scroll::-webkit-scrollbar { width: 6px; }
.sb-scroll::-webkit-scrollbar-track { background: #0f172a; }
.sb-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
.sidebar-logo { padding: 16px 18px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #1e293b; text-decoration: none; flex-shrink: 0; }
.sidebar-logo .logo-icon { width: 34px; height: 34px; background: var(--p); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 15px; flex-shrink: 0; }
.sidebar-logo .logo-text { font-size: 15px; font-weight: 800; color: var(--p); }
.sidebar-logo .logo-sub  { font-size: 9px; color: var(--light); display: block; margin-top: 1px; }
.msec { padding: 14px 16px 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: #475569; flex-shrink: 0; }
.smenu { list-style: none; padding: 0 6px; }
.smenu li a { display: flex; align-items: center; gap: 9px; padding: 8px 12px; border-radius: 8px; color: #94a3b8; text-decoration: none; font-size: 12.5px; font-weight: 500; transition: background .12s, color .12s; margin-bottom: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.smenu li a i { width: 14px; text-align: center; font-size: 11px; flex-shrink: 0; }
.smenu li a:hover  { background: var(--sbh); color: #e2e8f0; }
.smenu li a.active { background: var(--sba); color: var(--p); font-weight: 600; }
.user-box { padding: 12px 16px; background: #060f1e; border-top: 1px solid #1e293b; flex-shrink: 0; }
.user-box .un { font-size: 12px; font-weight: 600; color: #e2e8f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-box .ur { display: inline-block; background: #1e293b; color: var(--p); border-radius: 4px; padding: 2px 6px; font-size: 9px; font-weight: 700; text-transform: uppercase; margin-top: 4px; }
.sb-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 190; cursor: pointer; }
.sb-overlay.open { display: block; }
.ham-btn { display: none; background: none; border: 1px solid var(--border); border-radius: 7px; padding: 6px 10px; cursor: pointer; color: #475569; font-size: 16px; line-height: 1; flex-shrink: 0; }
.ham-btn:hover { background: var(--bg); }
.main { margin-left: var(--sb-w); flex: 1; min-width: 0; display: flex; flex-direction: column; min-height: 100vh; }

/* ═══ v1.7.9: TOPBAR DROPDOWN MENU ═══ */
.layout-topbar .sidebar { display: none !important; }
.layout-topbar .main    { margin-left: 0 !important; }
.layout-topbar .topbar  { height: auto !important; padding: 0 !important; flex-direction: column; align-items: stretch !important; gap: 0 !important; }
.tb-row1 { display: flex; align-items: center; justify-content: space-between; padding: 8px 22px; gap: 12px; min-height: 50px; }
.tb-row1 .tb-logo { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
.tb-row1 .tb-logo .logo-icon { width: 32px; height: 32px; background: var(--p); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; }
.tb-row1 .tb-logo .logo-text { font-size: 15px; font-weight: 800; color: var(--p); }
.tb-row1 .tb-logo .logo-sub  { font-size: 9px; color: var(--light); display: block; line-height: 1; margin-top: 1px; }
.tb-row1 .tb-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.tb-row2 { background: var(--sb); padding: 0 22px; display: flex; align-items: stretch; gap: 0; border-top: 1px solid #1e293b; }
.tb-section { position: relative; }
.tb-section > .tb-trigger {
    background: transparent; border: 0; color: #cbd5e1;
    padding: 11px 16px; font-size: 12.5px; font-weight: 600;
    display: flex; align-items: center; gap: 7px;
    cursor: pointer; transition: background .15s, color .15s; height: 100%;
    border-bottom: 3px solid transparent;
    font-family: inherit;
}
.tb-section > .tb-trigger:hover    { background: var(--sbh); color: #fff; }
.tb-section.has-active > .tb-trigger { background: var(--sbh); color: #fff; border-bottom-color: var(--p); }
.tb-section.open > .tb-trigger     { background: var(--sbh); color: #fff; }
.tb-section > .tb-trigger i.fa-chevron-down { font-size: 9px; transition: transform .15s; opacity: .7; }
.tb-section.open > .tb-trigger i.fa-chevron-down { transform: rotate(180deg); }

.tb-dropdown {
    position: absolute; top: 100%; left: 0;
    background: #fff; border: 1px solid var(--border);
    border-radius: 0 0 10px 10px; box-shadow: 0 8px 24px rgba(0,0,0,.15);
    min-width: 230px; padding: 6px 0;
    opacity: 0; visibility: hidden; transform: translateY(-6px);
    transition: opacity .15s, transform .15s, visibility .15s;
    z-index: 600;
}
.tb-section.open .tb-dropdown {
    opacity: 1; visibility: visible; transform: translateY(0);
}
.tb-dropdown a {
    display: flex; align-items: center; gap: 9px;
    padding: 8px 16px; color: #1e293b;
    text-decoration: none; font-size: 12.5px;
    transition: background .12s, color .12s;
    white-space: nowrap;
}
.tb-dropdown a i { width: 14px; text-align: center; font-size: 11px; color: var(--muted); }
.tb-dropdown a:hover  { background: #f1f5f9; color: var(--p); }
.tb-dropdown a:hover i { color: var(--p); }
.tb-dropdown a.active { background: #e0f2fe; color: var(--p); font-weight: 700; }
.tb-dropdown a.active i { color: var(--p); }

@media (max-width: 900px) {
    .layout-topbar .tb-row2 { display: none; }
    .layout-topbar .ham-btn { display: flex; align-items: center; justify-content: center; }
}
.topbar { background: #fff; border-bottom: 1px solid var(--border); padding: 0 22px; height: var(--topbar-h); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 150; gap: 10px; }
.topbar-breadcrumb { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 6px; min-width: 0; overflow: hidden; }
.topbar-breadcrumb span:last-child { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.topbar-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.notif-btn { position: relative; background: none; border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; cursor: pointer; color: var(--muted); font-size: 13px; text-decoration: none; transition: .12s; white-space: nowrap; }
.notif-btn:hover { background: var(--bg); color: var(--p); }
.notif-dot { position: absolute; top: -4px; right: -4px; background: var(--danger); color: #fff; border-radius: 10px; padding: 0 5px; font-size: 9px; font-weight: 700; line-height: 15px; }
.content { padding: 22px 24px 40px; flex: 1; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600; border: 1px solid var(--border); cursor: pointer; background: #fff; color: #334155; text-decoration: none; transition: .12s; white-space: nowrap; line-height: 1.4; font-family: inherit; }
.btn:hover { background: var(--bg); }
.btn-primary { background: var(--p); color: #fff; border-color: var(--p); }
.btn-primary:hover { background: var(--pd); border-color: var(--pd); }
.btn-blue { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; } .btn-blue:hover { background: #bae6fd; }
.btn-success { background: #d1fae5; color: #065f46; border-color: #10b981; }
.btn-danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; } .btn-danger:hover { background: #fecaca; }
.btn-sm { padding: 5px 10px; font-size: 11px; }
.btn-green { background: #d1fae5; color: #065f46; border-color: #10b981; }
.card { background: #fff; border-radius: 12px; border: 1px solid var(--border); padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
.card-title { font-size: 14px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 8px; }
.stat-card { background: #fff; padding: 16px; border-radius: 12px; border: 1px solid var(--border); border-left: 4px solid; }
.stat-card .sl { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
.stat-card .sv { font-size: 26px; font-weight: 800; margin-top: 4px; line-height: 1; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.badge-success { background: #d1fae5; color: #065f46; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-danger  { background: #fee2e2; color: #991b1b; }
.badge-info    { background: #dbeafe; color: #1e40af; }
.badge-neutral { background: #f1f5f9; color: #475569; }
.badge-purple  { background: #ede9fe; color: #5b21b6; }
.alert { padding: 12px 16px; border-radius: 9px; border-left: 4px solid; margin-bottom: 18px; font-size: 13px; line-height: 1.6; }
.alert-success { background: #f0fdf4; color: #065f46; border-color: var(--success); }
.alert-warning { background: #fffbeb; color: #92400e; border-color: var(--warning); }
.alert-danger  { background: #fef2f2; color: #991b1b; border-color: var(--danger); }
.alert-info    { background: #eff6ff; color: #1e40af; border-color: var(--info); }
.filter-bar { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.filter-bar .fg { display: flex; flex-direction: column; gap: 3px; }
.filter-bar label { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; }
.filter-bar input, .filter-bar select { padding: 7px 11px; border: 1px solid var(--border); border-radius: 7px; font-size: 12px; background: #fff; color: #1e293b; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 11px; font-weight: 700; color: var(--muted); margin-bottom: 5px; text-transform: uppercase; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; color: #1e293b; background: #fff; transition: border-color .12s, box-shadow .12s; font-family: inherit; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--p); box-shadow: 0 0 0 3px <?= h($primary) ?>22; }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 999; justify-content: center; align-items: center; backdrop-filter: blur(2px); }
.modal-overlay.open { display: flex; }
.modal-box { background: #fff; border-radius: 14px; padding: 26px; box-shadow: 0 20px 50px rgba(0,0,0,.25); overflow-y: auto; max-height: 92vh; max-width: calc(100vw - 32px); width: 100%; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
.grid-4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; }
.span-2 { grid-column: span 2; }
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table th { background: #f8fafc; padding: 10px 12px; text-align: left; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; border-bottom: 2px solid var(--border); white-space: nowrap; }
.data-table td { padding: 10px 12px; border-bottom: 1px solid #f8fafc; vertical-align: top; }
.data-table tr:hover td { background: #fafbfc; }
@media (max-width: 1024px) {
  :root { --sb-w: 210px; }
  .content { padding: 16px 18px 32px; }
  .grid-3 { grid-template-columns: 1fr 1fr; }
  .grid-4 { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 768px) {
  :root { --sb-w: 260px; }
  .sidebar { transform: translateX(calc(-1 * var(--sb-w))); box-shadow: none; }
  .sidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(0,0,0,.35); }
  .main { margin-left: 0; }
  .ham-btn { display: flex; align-items: center; justify-content: center; }
  .content { padding: 14px 14px 28px; }
  .grid-2, .grid-3 { grid-template-columns: 1fr; }
  .grid-4 { grid-template-columns: 1fr 1fr; }
  .span-2 { grid-column: span 1; }
  .topbar { padding: 0 14px; }
  .modal-box { padding: 18px; border-radius: 10px; }
}
@media (max-width: 480px) {
  .grid-4 { grid-template-columns: 1fr; }
  .topbar-breadcrumb { display: none; }
  .btn-sm { font-size: 10px; padding: 4px 8px; }
}
/* v5.03: template layout overrides */
.layout-compact .content { padding: 16px !important; }
.layout-compact .card, .layout-compact .stat-card { padding: 12px !important; }
.layout-compact .data-table th, .layout-compact .data-table td { padding: 6px 10px !important; font-size: 12px !important; }
.layout-compact .btn { padding: 6px 10px !important; font-size: 12px !important; }
.layout-compact h1 { font-size: 18px !important; }
.layout-compact h2, .layout-compact h3 { font-size: 14px !important; }

.layout-classic .sidebar { background: #ffffff !important; border-right: 1px solid var(--border); box-shadow: 2px 0 8px rgba(0,0,0,.04); }
.layout-classic .smenu li a { color: #475569 !important; }
.layout-classic .smenu li a.active { background: #f0f9ff !important; color: var(--p) !important; }
.layout-classic .smenu li a:hover { background: #f8fafc !important; color: #1e293b !important; }
.layout-classic .msec { color: #64748b !important; }
.layout-classic .sidebar-logo .logo-text { color: var(--p) !important; }
.layout-classic .user-box { background: #f8fafc !important; border-top: 1px solid var(--border); }
.layout-classic .user-box .un, .layout-classic .user-box .ue { color: #1e293b !important; }


/* v5.5: filtro gerarchico Catalogo nel menu */
.smenu li.has-sub > a { display: flex; align-items: center; gap: 6px; }
.smenu li.has-sub > a .chev { transition: transform .2s ease; }
.smenu li.has-sub.open > a .chev { transform: rotate(180deg); }
.smenu .submenu { list-style: none; padding: 0; margin: 0 0 6px 0; background: rgba(0,0,0,.18); border-radius: 6px; overflow: hidden; }
.smenu .submenu li a { padding: 7px 14px 7px 36px; font-size: 12px; font-weight: 500; color: var(--sb-text, #cbd5e1); display: flex; align-items: center; gap: 6px; opacity: .85; }
.smenu .submenu li a:hover { background: rgba(255,255,255,.06); opacity: 1; }
.smenu .submenu li a.active { background: var(--p); color: #fff; opacity: 1; font-weight: 700; }
.smenu .submenu .brand-count { margin-left: auto; background: rgba(255,255,255,.12); padding: 1px 7px; border-radius: 9px; font-size: 10px; font-weight: 700; }
.smenu .submenu li a.active .brand-count { background: rgba(255,255,255,.25); }

@media print {
  .sidebar, .topbar, .no-print, .sb-overlay, .ham-btn { display: none !important; }
  .main { margin-left: 0 !important; }
  .content { padding: 0 !important; }
}
</style>

<?php // v1.9.3 — tabelle con intestazione fissa e corpo scorrevole.
      //
      // Inclusi qui e non nelle singole viste: sono una quindicina, e toccarle
      // una per una avrebbe significato quindici occasioni di dimenticarne una.
      // Lo script agisce sul DOM e non richiede modifiche alle pagine.
      //
      // `filemtime` nella querystring evita che il browser serva una versione
      // vecchia del foglio dopo un aggiornamento. ?>
<?php // v1.9.8 — stile condiviso dei filtri, estratto da manage_projects.php ?>
<link rel="stylesheet" href="assets/pm-filters.css?v=<?= @filemtime(__DIR__.'/assets/pm-filters.css') ?: '198' ?>">
<link rel="stylesheet" href="assets/pm-tables.css?v=<?= @filemtime(__DIR__.'/assets/pm-tables.css') ?: '193' ?>">
<script src="assets/pm-tables.js?v=<?= @filemtime(__DIR__.'/assets/pm-tables.js') ?: '193' ?>" defer></script>
</head>
<body class="layout-<?= h($brand_layout) ?> layout-topbar">
<?php if (!$is_modal): ?>

<!-- v1.7.9: Sidebar drawer per mobile -->
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<nav class="sidebar no-print" id="sidebar">
  <a href="<?= url_safe('index') ?>" class="sidebar-logo">
    <?php if (!empty($brand_logo_path) && is_file(APP_ROOT . '/' . ltrim($brand_logo_path, '/'))): ?>
    <img src="<?= h($brand_logo_path) ?>?v=<?= filemtime(APP_ROOT . '/' . ltrim($brand_logo_path, '/')) ?>"
         alt="Logo" style="height:38px;max-width:160px;object-fit:contain">
    <?php else: ?>
    <div class="logo-icon"><i class="fa-solid fa-bolt"></i></div>
    <?php endif; ?>
    <div>
      <span class="logo-text"><?= h($app_name) ?></span>
      <span class="logo-sub"><?= h($brand_tagline ?? '') ?></span>
    </div>
  </a>
  <div class="sb-scroll">
    <?php foreach ($menu_structure as $sec): ?>
      <div class="msec"><?= h($sec['label']) ?></div>
      <ul class="smenu">
        <?php foreach ($sec['items'] as $it): ?>
        <li><a href="<?= url_safe($it['page']) ?>" class="<?= ia($it['page']) ?>">
          <i class="fa-solid <?= h($it['icon']) ?>"></i><?= h($it['label']) ?>
        </a></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </div>
</nav>

<div class="main">
  <header class="topbar no-print">
    <!-- Riga 1: Logo + utente -->
    <div class="tb-row1">
      <button class="ham-btn" id="hamBtn" onclick="openSidebar()" aria-label="Apri menu" title="Menu" style="display:none">
        <i class="fa-solid fa-bars"></i>
      </button>

      <a href="<?= url_safe('index') ?>" class="tb-logo">
        <?php if (!empty($brand_logo_path) && is_file(APP_ROOT . '/' . ltrim($brand_logo_path, '/'))): ?>
        <img src="<?= h($brand_logo_path) ?>?v=<?= filemtime(APP_ROOT . '/' . ltrim($brand_logo_path, '/')) ?>"
             alt="Logo" class="logo-img"
             style="height:34px;max-width:160px;object-fit:contain">
        <?php else: ?>
        <div class="logo-icon"><i class="fa-solid fa-bolt"></i></div>
        <?php endif; ?>
        <div>
          <span class="logo-text"><?= h($app_name) ?></span>
          <span class="logo-sub"><?= h($brand_tagline ?? '') ?></span>
        </div>
      </a>

      <div class="tb-right">
        <a href="<?= url_safe('notifications') ?>" class="notif-btn" title="Notifiche">
          <i class="fa-solid fa-bell"></i>
          <?php if ($notif_count > 0): ?>
          <span class="notif-dot"><?= (int)$notif_count ?></span>
          <?php endif; ?>
        </a>
        <a href="<?= url_safe('menu_customizer') ?>" class="btn btn-sm" title="Personalizza menu" style="padding:6px 10px">
          <i class="fa-solid fa-bars-staggered"></i>
        </a>
        <a href="<?= h($emp_link) ?>" class="btn btn-sm" title="Profilo">
          <i class="fa-solid fa-circle-user"></i><?= h(explode(' ', $_SESSION['user_name'] ?? 'Utente')[0]) ?>
          <span style="background:#1e293b;color:#fff;border-radius:4px;padding:1px 5px;font-size:9px;font-weight:700;margin-left:4px"><?= h($role_label) ?></span>
        </a>
        <a href="logout.php" class="btn btn-sm" title="Esci" style="padding:6px 10px">
          <i class="fa-solid fa-right-from-bracket"></i>
        </a>
      </div>
    </div>

    <!-- Riga 2: Menu dropdown principale -->
    <div class="tb-row2">
      <?php
        $curr_page = current_page();
        foreach ($menu_structure as $sec):
          $has_active = false;
          foreach ($sec['items'] as $it) {
              $tgt = str_ends_with($it['page'], '.php') ? substr($it['page'], 0, -4) : $it['page'];
              if ($curr_page === $tgt) { $has_active = true; break; }
          }
      ?>
      <div class="tb-section <?= $has_active ? 'has-active' : '' ?>" data-section="<?= h($sec['key']) ?>">
        <button type="button" class="tb-trigger" onclick="tbToggle(this)">
          <i class="fa-solid <?= h($sec['icon']) ?>"></i>
          <span><?= h($sec['label']) ?></span>
          <i class="fa-solid fa-chevron-down"></i>
        </button>
        <div class="tb-dropdown">
          <?php foreach ($sec['items'] as $it): ?>
          <a href="<?= url_safe($it['page']) ?>" class="<?= ia($it['page']) ?>">
            <i class="fa-solid <?= h($it['icon']) ?>"></i><?= h($it['label']) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </header>
<?php endif; ?>
<div class="content">

<script>
function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sbOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeSidebar(); });
document.querySelectorAll('.sidebar .smenu li a').forEach(function(a) {
  a.addEventListener('click', function() { if (window.innerWidth <= 768) closeSidebar(); });
});

// v1.7.9: Topbar dropdown toggle
function tbToggle(btn) {
  const sec = btn.closest('.tb-section');
  const wasOpen = sec.classList.contains('open');
  // Chiudi tutti gli altri
  document.querySelectorAll('.tb-section.open').forEach(s => s.classList.remove('open'));
  if (!wasOpen) sec.classList.add('open');
}
// Chiudi se click fuori
document.addEventListener('click', function(e) {
  if (!e.target.closest('.tb-section')) {
    document.querySelectorAll('.tb-section.open').forEach(s => s.classList.remove('open'));
  }
});
// Chiudi su Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.tb-section.open').forEach(s => s.classList.remove('open'));
  }
});
window.addEventListener('resize', function() {
  if (window.innerWidth > 768) {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sbOverlay').classList.remove('open');
    document.body.style.overflow = '';
  }
});
function closeModal(id) { var el = document.getElementById(id); if (el) el.style.display = 'none'; }

/* Inject CSRF token in tutti gli XHR/fetch automatici */
(function() {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  if (!csrf) return;
  const _fetch = window.fetch;
  window.fetch = function(url, opts = {}) {
    opts.headers = Object.assign({}, opts.headers || {}, { 'X-CSRF-Token': csrf });
    return _fetch(url, opts);
  };
})();
</script>
