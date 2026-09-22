<?php
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
    ? url('user_profile', ['emp_id' => (int)$_SESSION['employee_id']])
    : url('user_profile', ['id' => $u_id]);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($app_name) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<style>
:root {
  --p: <?= h($primary) ?>;
  --pd: <?= h($primary_dark) ?>;
  --sb-w: 240px;
  --topbar-h: 52px;
  --sb: #0f172a;
  --sbh: #1e293b;
  --sba: #1e3a5f;
  --muted: #64748b;
  --light: #94a3b8;
  --bg: #f1f5f9;
  --border: #e2e8f0;
  --danger: #ef4444;
  --warning: #f59e0b;
  --success: #10b981;
  --info: #3b82f6;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
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
@media print {
  .sidebar, .topbar, .no-print, .sb-overlay, .ham-btn { display: none !important; }
  .main { margin-left: 0 !important; }
  .content { padding: 0 !important; }
}
</style>
</head>
<body>
<?php if (!$is_modal): ?>

<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<nav class="sidebar no-print" id="sidebar">
  <a href="<?= url_safe('index') ?>" class="sidebar-logo">
    <div class="logo-icon"><i class="fa-solid fa-graduation-cap"></i></div>
    <div>
      <div class="logo-text"><?= h($app_name) ?></div>
      <span class="logo-sub">v4.2</span>
    </div>
  </a>

  <div class="sb-scroll">

  <!-- ════ HOME / PROFILO / SICUREZZA ACCOUNT ════ -->
  <ul class="smenu" style="margin-top:6px">
    <li><a href="<?= url_safe('index') ?>" class="<?= ia('index') ?>"><i class="fa-solid fa-gauge-high"></i>Dashboard</a></li>
    <li><a href="<?= h($emp_link) ?>" class="<?= ia('user_profile') ?>"><i class="fa-solid fa-id-badge"></i>Il mio dossier</a></li>
    <?php if ($show_2fa_link): ?>
    <li><a href="<?= url_safe('2fa_settings') ?>" class="<?= ia('2fa_settings') ?>"><i class="fa-solid fa-shield-halved"></i>Sicurezza account</a></li>
    <?php endif; ?>
  </ul>

  <!-- ════ BRAND & PARTNERSHIP ════ -->
  <?php if (check_ui_permission('brand.php') || $is_admin): ?>
  <div class="msec">Brand &amp; Partnership</div>
  <ul class="smenu">
    <?php if (check_ui_permission('brand.php')): ?>
    <li><a href="<?= url_safe('brand') ?>" class="<?= ia('brand') ?>"><i class="fa-solid fa-tags"></i>Directory brand</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('brand_referents.php')): ?>
    <li><a href="<?= url_safe('brand_referents') ?>" class="<?= ia('brand_referents') ?>"><i class="fa-solid fa-user-shield"></i>Referenti &amp; requisiti</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('gap_analysis.php')): ?>
    <li><a href="<?= url_safe('gap_analysis') ?>" class="<?= ia('gap_analysis') ?>"><i class="fa-solid fa-chart-bar"></i>Gap analysis</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('brand_technologies.php')): ?>
    <li><a href="<?= url_safe('brand_technologies') ?>" class="<?= ia('brand_technologies') ?>"><i class="fa-solid fa-microchip"></i>Tecnologie &amp; Servizi</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('brand_distributors.php')): ?>
    <li><a href="<?= url_safe('brand_distributors') ?>" class="<?= ia('brand_distributors') ?>"><i class="fa-solid fa-truck-field"></i>Distributori</a></li>
    <?php endif; ?>
  </ul>
  <?php endif; ?>

  <!-- ════ COMPETENZE & FORMAZIONE ════ -->
  <div class="msec">Competenze &amp; Formazione</div>
  <ul class="smenu">
    <?php if (check_ui_permission('catalogo_certificazioni.php')): ?>
    <li><a href="<?= url_safe('catalogo_certificazioni') ?>" class="<?= ia('catalogo_certificazioni') ?>"><i class="fa-solid fa-award"></i>Catalogo certificazioni</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('report_certificazioni.php')): ?>
    <li><a href="<?= url_safe('report_certificazioni') ?>" class="<?= ia('report_certificazioni') ?>"><i class="fa-solid fa-chart-pie"></i>Report certificazioni</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('visualizza_storico.php')): ?>
    <li><a href="<?= url_safe('visualizza_storico') ?>" class="<?= ia('visualizza_storico') ?>"><i class="fa-solid fa-clock-rotate-left"></i>Storico competenze</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('training_plans.php')): ?>
    <li><a href="<?= url_safe('training_plans') ?>" class="<?= ia('training_plans') ?>"><i class="fa-solid fa-calendar-days"></i>Master calendar</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('upload_certificato.php')): ?>
    <li><a href="<?= url_safe('upload_certificato') ?>" class="<?= ia('upload_certificato') ?>"><i class="fa-solid fa-upload"></i>Carica certificato</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('programmazione.php')): ?>
    <li><a href="<?= url_safe('programmazione') ?>" class="<?= ia('programmazione') ?>"><i class="fa-solid fa-calendar-plus"></i>Pianifica esame</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('segreteria.php')): ?>
    <li><a href="<?= url_safe('segreteria') ?>" class="<?= ia('segreteria') ?>"><i class="fa-solid fa-concierge-bell"></i>Segreteria &amp; Logistica</a></li>
    <?php endif; ?>
  </ul>

  <!-- ════ RECRUITING ════ -->
  <?php if ($can_recruit): ?>
  <div class="msec">Recruiting &amp; Agenzie</div>
  <ul class="smenu">
    <?php if (check_ui_permission('recruiting_posizioni.php')): ?>
    <li><a href="<?= url_safe('recruiting_posizioni') ?>" class="<?= ia('recruiting_posizioni') ?>"><i class="fa-solid fa-briefcase"></i>Posizioni aperte</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('recruiting_candidati.php')): ?>
    <li><a href="<?= url_safe('recruiting_candidati') ?>" class="<?= ia('recruiting_candidati') ?>"><i class="fa-solid fa-users-line"></i>Pipeline candidati</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('publish_posizione.php')): ?>
    <li><a href="<?= url_safe('recruiting_posizioni') ?>" class="<?= ia('publish_posizione') ?>"><i class="fa-solid fa-share-nodes"></i>Pubblica su portali</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('candidato_profilo.php')): ?>
    <li><a href="<?= url_safe('recruiting_candidati') ?>" class="<?= ia('candidato_profilo') ?>"><i class="fa-solid fa-id-card-clip"></i>Dossier candidati</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('documenti.php')): ?>
    <li><a href="<?= url_safe('documenti') ?>" class="<?= ia('documenti') ?>"><i class="fa-solid fa-folder-open"></i>Archivio documenti</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('recruiting_agenzie.php')): ?>
    <li><a href="<?= url_safe('recruiting_agenzie') ?>" class="<?= ia('recruiting_agenzie') ?>"><i class="fa-solid fa-building"></i>Agenzie</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('recruiting_contratti.php')): ?>
    <li><a href="<?= url_safe('recruiting_contratti') ?>" class="<?= ia('recruiting_contratti') ?>"><i class="fa-solid fa-file-signature"></i>Contratti agenzie</a></li>
    <?php endif; ?>
  </ul>
  <?php endif; ?>

  <!-- ════ AMMINISTRAZIONE ════ -->
  <?php if ($is_hr || $is_admin): ?>
  <div class="msec">Amministrazione</div>
  <ul class="smenu">
    <?php if (check_ui_permission('manager_users.php')): ?>
    <li><a href="<?= url_safe('manager_users') ?>" class="<?= ia('manager_users') ?>"><i class="fa-solid fa-key"></i>Accessi portale</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('manage_employees.php')): ?>
    <li><a href="<?= url_safe('manage_employees') ?>" class="<?= ia('manage_employees') ?>"><i class="fa-solid fa-id-card"></i>Anagrafica dipendenti</a></li>
    <?php endif; ?>
    <?php if ($is_admin): ?>
    <li><a href="<?= url_safe('manage_users_2fa') ?>" class="<?= ia('manage_users_2fa') ?>"><i class="fa-solid fa-user-shield"></i>Gestione 2FA Utenti</a></li>
    <li><a href="<?= url_safe('manage_roles') ?>" class="<?= ia('manage_roles') ?>"><i class="fa-solid fa-user-tag"></i>Ruoli</a></li>
    <li><a href="<?= url_safe('manage_permissions') ?>" class="<?= ia('manage_permissions') ?>"><i class="fa-solid fa-shield-halved"></i>Permessi</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('manage_companies.php')): ?>
    <li><a href="<?= url_safe('manage_companies') ?>" class="<?= ia('manage_companies') ?>"><i class="fa-solid fa-building-user"></i>Aziende &amp; sedi</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('manage_work_modes.php')): ?>
    <li><a href="<?= url_safe('manage_work_modes') ?>" class="<?= ia('manage_work_modes') ?>"><i class="fa-solid fa-laptop-house"></i>Modalità lavoro</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('mass_upload.php')): ?>
    <li><a href="<?= url_safe('mass_upload') ?>" class="<?= ia('mass_upload') ?>"><i class="fa-solid fa-file-import"></i>Import massivo</a></li>
    <?php endif; ?>
    <?php if (check_ui_permission('config_notifiche.php')): ?>
    <li><a href="<?= url_safe('config_notifiche') ?>" class="<?= ia('config_notifiche') ?>"><i class="fa-solid fa-bell-slash"></i>Config notifiche</a></li>
    <?php endif; ?>
    <?php if ($is_admin): ?>
    <li><a href="<?= url_safe('smtp_settings') ?>" class="<?= ia('smtp_settings') ?>"><i class="fa-solid fa-envelope-circle-check"></i>Config SMTP</a></li>
    <li><a href="<?= url_safe('settings') ?>" class="<?= ia('settings') ?>"><i class="fa-solid fa-gear"></i>Impostazioni</a></li>
    <li><a href="<?= url_safe('view_logs') ?>" class="<?= ia('view_logs') ?>"><i class="fa-solid fa-list-ul"></i>Log sistema</a></li>
    <?php endif; ?>
  </ul>
  <?php endif; ?>

  <!-- ════ SISTEMA (solo Super Admin — path diretti, non via Router) ════ -->
  <?php if ($is_admin): ?>
  <div class="msec">Sistema</div>
  <ul class="smenu">
    <li><a href="db_upgrade.php" class="<?= ia('db_upgrade') ?>"><i class="fa-solid fa-database"></i>DB Upgrade</a></li>
    <li><a href="schema_check_upgrade.php" class="<?= ia('schema_check_upgrade') ?>"><i class="fa-solid fa-shield-virus"></i>Verifica schema DB</a></li>
    <li><a href="health_check.php" class="<?= ia('health_check') ?>"><i class="fa-solid fa-heart-pulse"></i>Health check</a></li>
    <li><a href="system_update.php" class="<?= ia('system_update') ?>"><i class="fa-solid fa-cloud-arrow-up"></i>Aggiorna sistema</a></li>
  </ul>
  <?php endif; ?>

  <!-- ════ LOGOUT ════ -->
  <ul class="smenu" style="margin-top:8px;padding-bottom:4px">
    <li><a href="logout.php" style="color:#fb7185"><i class="fa-solid fa-power-off"></i>Esci</a></li>
  </ul>

  </div><!-- /sb-scroll -->

  <div class="user-box">
    <div class="un"><?= h($_SESSION['user_name'] ?? 'Utente') ?></div>
    <div class="ur"><?= h($role_label) ?></div>
  </div>
</nav>

<div class="main">
  <header class="topbar no-print">
    <button class="ham-btn" id="hamBtn" onclick="openSidebar()" aria-label="Apri menu" title="Menu">
      <i class="fa-solid fa-bars"></i>
    </button>

    <div class="topbar-breadcrumb">
      <i class="fa-solid fa-house" style="color:var(--light)"></i>
      <span style="color:var(--light)">/</span>
      <span><?= h(ucwords(str_replace('_', ' ', current_page()))) ?></span>
    </div>

    <div class="topbar-right">
      <a href="<?= url_safe('notifications') ?>" class="notif-btn" title="Notifiche">
        <i class="fa-solid fa-bell"></i>
        <?php if ($notif_count > 0): ?>
        <span class="notif-dot"><?= (int)$notif_count ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= h($emp_link) ?>" class="btn btn-sm">
        <i class="fa-solid fa-circle-user"></i><?= h(explode(' ', $_SESSION['user_name'] ?? 'Utente')[0]) ?>
      </a>
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
