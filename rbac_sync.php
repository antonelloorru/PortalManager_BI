<?php
/**
 * PortalManager — rbac_sync.php (v1.9.83) — Sistema → Sincronizzazione permessi.
 * Solo Super Admin. Percorso diretto (Router::RESTRICTED).
 *
 * - Esegue la sincronizzazione RBAC (app/RbacSync.php) in simulazione o applicazione;
 * - gestisce le regole di seeding per le pagine nuove (al posto delle migration con INSERT);
 * - mostra catalogo pagine, avvisi e registro delle esecuzioni.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/RbacSync.php';
require_once __DIR__ . '/app/PermissionCatalog.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) { http_response_code(403); die('Accesso negato: solo Super Admin.'); }

$flash = static function (string $html): void { $_SESSION['flash_msg'] = $html; };
$back  = static function (): void {
    $t = class_exists('Router') ? Router::url('rbac_sync') : 'rbac_sync.php';
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: ' . $t); exit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'run' || $act === 'simulate') {
            $r = RbacSync::run($pdo, $act === 'run', 'manuale', $u_id);
            $_SESSION['rbac_last_report'] = $r;
            $flash("<div class='alert alert-" . ($r['status'] === 'ok' ? 'success' : 'danger') . "'>"
                . ($act === 'run' ? 'Sincronizzazione eseguita' : 'Simulazione eseguita (nessuna modifica)') . ': '
                . count($r['changes']) . ' modifiche, ' . count($r['warnings']) . ' avvisi.</div>');
        } elseif ($act === 'toggle_auto') {
            $v = RbacSync::setting($pdo, 'rbac_autosync_enabled', '1') === '1' ? '0' : '1';
            $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('rbac_autosync_enabled', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
            write_log('Permissions', 'info', 'Sincronizzazione RBAC automatica ' . ($v === '1' ? 'attivata' : 'disattivata'), $u_id);
            $flash("<div class='alert alert-success'>Sincronizzazione automatica " . ($v === '1' ? 'attivata' : 'disattivata') . '.</div>');
        } elseif ($act === 'add_rule') {
            $rid   = (int)($_POST['role_id'] ?? 0);
            $scope = in_array($_POST['scope'] ?? '', ['all', 'section', 'page'], true) ? $_POST['scope'] : 'section';
            $tgt   = $scope === 'all' ? null : mb_substr(trim((string)($_POST['target_' . $scope] ?? '')), 0, 150);
            if ($rid <= 1) throw new RuntimeException('Scegliere un ruolo diverso dal Super Admin.');
            if ($scope !== 'all' && $tgt === '') throw new RuntimeException('Indicare sezione o pagina.');
            $fl = fn($k) => !empty($_POST[$k]) ? 1 : 0;
            if (!$fl('can_view') && ($fl('can_create') || $fl('can_edit') || $fl('can_delete') || $fl('can_export'))) {
                throw new RuntimeException('Le azioni richiedono anche la visualizzazione.');
            }
            $pdo->prepare("INSERT INTO rbac_seed_rules (role_id, scope, target, can_view, can_create, can_edit, can_delete, can_export, note, created_by)
                           VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$rid, $scope, $tgt, $fl('can_view'), $fl('can_create'), $fl('can_edit'), $fl('can_delete'), $fl('can_export'),
                           mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, $u_id]);
            write_log('Permissions', 'success', "Regola di seeding aggiunta: ruolo #$rid, $scope " . ($tgt ?? '*'), $u_id);
            $flash("<div class='alert alert-success'>Regola aggiunta. Si applica alle pagine che compariranno d'ora in poi.</div>");
        } elseif ($act === 'toggle_rule' || $act === 'delete_rule') {
            $id = (int)($_POST['rule_id'] ?? 0);
            if ($act === 'delete_rule') $pdo->prepare("DELETE FROM rbac_seed_rules WHERE id = ?")->execute([$id]);
            else $pdo->prepare("UPDATE rbac_seed_rules SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            write_log('Permissions', 'info', "Regola di seeding #$id: " . ($act === 'delete_rule' ? 'eliminata' : 'stato cambiato'), $u_id);
            $flash("<div class='alert alert-success'>Regola aggiornata.</div>");
        }
    } catch (Throwable $e) {
        $flash("<div class='alert alert-danger'>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>');
    }
    $back();
}

$msg = $_SESSION['flash_msg'] ?? ''; unset($_SESSION['flash_msg']);
$last = $_SESSION['rbac_last_report'] ?? null; unset($_SESSION['rbac_last_report']);

$ready = true;
try {
    $cat   = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM role_permissions rp WHERE rp.page_name = p.name AND rp.role_id <> 1 AND rp.can_view = 1) AS ruoli
                            FROM permissions p WHERE p.is_page = 1 ORDER BY p.is_active DESC, p.module, p.sort_order, p.name")->fetchAll(PDO::FETCH_ASSOC);
    $rules = $pdo->query("SELECT r.*, ro.name AS role_name FROM rbac_seed_rules r LEFT JOIN roles ro ON ro.id = r.role_id ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC);
    $logs  = $pdo->query("SELECT l.*, u.email FROM rbac_sync_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $ready = false; $cat = $rules = $logs = []; }
$roles    = $pdo->query("SELECT id, name FROM roles WHERE id > 1 ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$sections = array_values(array_unique(array_merge(array_keys(PermissionCatalog::sections()), array_column($cat, 'module'))));
$auto     = RbacSync::setting($pdo, 'rbac_autosync_enabled', '1') === '1';
$lastAt   = RbacSync::setting($pdo, 'rbac_last_sync_at', '');
$baseline = RbacSync::setting($pdo, 'rbac_baseline_at', '');
if ($last === null && $logs) $last = json_decode((string)$logs[0]['report'], true) ?: null;
$newNoRole = array_filter($cat, fn($c) => (int)$c['is_active'] === 1 && $baseline !== '' && $c['first_seen_at'] > $baseline && (int)$c['ruoli'] === 0);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$yn = fn($v) => (int)$v ? '<i class="fa-solid fa-check" style="color:#16a34a"></i>' : '<span style="color:#cbd5e1">—</span>';
require_once('header.php');
?>
<div style="margin-bottom:16px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-arrows-rotate"></i> Sincronizzazione permessi</h1>
  <p style="color:var(--muted);font-size:12px;margin-top:2px">
    Catalogo pagine, ruoli, utenti e permessi allineati in automatico a ogni aggiornamento del portale.
    Le pagine nuove nascono <strong>negate a tutti</strong> (tranne il Super Admin) salvo le regole qui sotto.
  </p>
</div>
<?= $msg ?>
<?php if (!$ready): ?>
  <div class="alert alert-warning">Tabelle non presenti: eseguire <code>sql/migration_v1_9_83.sql</code>.</div>
  <?php require_once('footer.php'); exit; ?>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:14px">
  <?php foreach ([['Pagine nel catalogo', count(array_filter($cat, fn($c) => (int)$c['is_active'] === 1)), '#334155'],
                  ['Nuove senza ruoli', count($newNoRole), count($newNoRole) ? '#d97706' : '#16a34a'],
                  ['Regole di seeding', count(array_filter($rules, fn($r) => (int)$r['is_active'] === 1)), '#2563eb'],
                  ['Avvisi ultima esecuzione', $last ? count($last['warnings'] ?? []) : 0, ($last && ($last['warnings'] ?? [])) ? '#dc2626' : '#16a34a']] as [$l, $v, $c]): ?>
    <div class="card" style="padding:10px 12px;border-top:3px solid <?= $c ?>">
      <div style="font-size:20px;font-weight:800;color:<?= $c ?>"><?= (int)$v ?></div>
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700"><?= $h($l) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title">Esecuzione</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">ultima: <?= $lastAt !== '' ? $h(date('d/m/Y H:i', strtotime($lastAt))) : 'mai' ?></span></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="simulate">
      <button class="btn btn-sm"><i class="fa-solid fa-flask"></i> Simula</button></form>
    <form method="POST" style="display:inline" onsubmit="return confirm('Applicare la sincronizzazione?')"><?= csrf_field() ?><input type="hidden" name="action" value="run">
      <button class="btn btn-primary btn-sm"><i class="fa-solid fa-arrows-rotate"></i> Sincronizza ora</button></form>
    <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_auto">
      <button class="btn btn-sm"><?= $auto ? '<i class="fa-solid fa-toggle-on" style="color:#16a34a"></i> Automatica attiva' : '<i class="fa-solid fa-toggle-off"></i> Automatica disattivata' ?></button></form>
  </div>
  <?php if ($last): ?>
    <div style="margin-top:12px;font-size:12px">
      <strong>Ultima esecuzione (<?= $h($last['mode'] ?? '') ?>, <?= $h($last['status'] ?? '') ?>)</strong>
      <?php if (!empty($last['changes'])): ?><ul style="margin:6px 0 6px 18px"><?php foreach ($last['changes'] as $c): ?><li><?= $h($c) ?></li><?php endforeach; ?></ul>
      <?php else: ?><div style="color:var(--muted)">Nessuna modifica necessaria.</div><?php endif; ?>
      <?php if (!empty($last['warnings'])): ?><div style="color:#b45309;font-weight:700;margin-top:6px">Avvisi</div>
        <ul style="margin:6px 0 0 18px;color:#b45309"><?php foreach ($last['warnings'] as $w): ?><li><?= $h($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title">Regole di seeding per le pagine nuove</span></div>
  <p style="font-size:12px;color:var(--muted);margin:0 0 10px">Si applicano una sola volta, quando una pagina compare per la prima volta
    (dopo un aggiornamento). Non toccano le pagine esistenti né i permessi già decisi. Senza regole una pagina nuova resta negata a tutti.</p>
  <table class="data-table" style="font-size:12px">
    <thead><tr><th>#</th><th>Ruolo</th><th>Ambito</th><th>Permessi</th><th>Nota</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rules): ?><tr><td colspan="7" style="color:var(--muted)">Nessuna regola: le pagine nuove nascono negate.</td></tr><?php endif; ?>
    <?php foreach ($rules as $r): ?>
      <tr><td><?= (int)$r['id'] ?></td><td><?= $h($r['role_name'] ?? ('#' . $r['role_id'])) ?></td>
        <td><?= $r['scope'] === 'all' ? 'tutte le pagine nuove' : ($r['scope'] === 'section' ? 'sezione «' . $h($r['target']) . '»' : 'pagina ' . $h($r['target'])) ?></td>
        <td><?= implode(' ', array_filter([(int)$r['can_view'] ? 'vista' : '', (int)$r['can_create'] ? 'crea' : '', (int)$r['can_edit'] ? 'modifica' : '',
                                            (int)$r['can_delete'] ? 'elimina' : '', (int)$r['can_export'] ? 'esporta' : ''])) ?: '—' ?></td>
        <td><?= $h($r['note'] ?? '') ?></td><td><?= (int)$r['is_active'] ? 'attiva' : 'sospesa' ?></td>
        <td style="white-space:nowrap">
          <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm"><?= (int)$r['is_active'] ? 'Sospendi' : 'Attiva' ?></button></form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare la regola?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm" style="color:#dc2626"><i class="fa-solid fa-trash"></i></button></form>
        </td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;align-items:end;margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_rule">
    <div class="form-group" style="margin:0"><label>Ruolo</label><select name="role_id" required>
      <?php foreach ($roles as $id => $n): ?><option value="<?= (int)$id ?>"><?= $h($n) ?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label>Ambito</label><select name="scope" id="rs_scope" onchange="rsScope()">
      <option value="section">Sezione</option><option value="page">Pagina</option><option value="all">Tutte le pagine nuove</option></select></div>
    <div class="form-group" style="margin:0" id="rs_sec"><label>Sezione</label><select name="target_section">
      <?php foreach ($sections as $s): ?><option value="<?= $h($s) ?>"><?= $h($s) ?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0;display:none" id="rs_pag"><label>Pagina (anche futura)</label>
      <input type="text" name="target_page" placeholder="es. nuova_pagina.php" list="rs_pages">
      <datalist id="rs_pages"><?php foreach ($cat as $c): ?><option value="<?= $h($c['name']) ?>"><?php endforeach; ?></datalist></div>
    <div class="form-group" style="margin:0"><label>Permessi</label>
      <div style="display:flex;gap:8px;font-size:12px;flex-wrap:wrap">
        <?php foreach (['can_view' => 'vista', 'can_create' => 'crea', 'can_edit' => 'modifica', 'can_delete' => 'elimina', 'can_export' => 'esporta'] as $k => $l): ?>
          <label style="display:flex;gap:3px;align-items:center"><input type="checkbox" name="<?= $k ?>" value="1" <?= $k === 'can_view' ? 'checked' : '' ?>><?= $l ?></label>
        <?php endforeach; ?></div></div>
    <div class="form-group" style="margin:0"><label>Nota</label><input type="text" name="note" maxlength="255"></div>
    <div><button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Aggiungi regola</button></div>
  </form>
  <script>function rsScope(){var v=document.getElementById('rs_scope').value;document.getElementById('rs_sec').style.display=v==='section'?'':'none';document.getElementById('rs_pag').style.display=v==='page'?'':'none';}</script>
</div>

<div class="card" style="margin-bottom:14px;overflow-x:auto">
  <div class="card-header"><span class="card-title">Catalogo pagine</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">dal codice (menu, router, catalogo curato) e dai permessi registrati</span></div>
  <table class="data-table" id="tRbacCat" style="font-size:12px">
    <thead><tr><th>Pagina</th><th>Etichetta</th><th>Sezione</th><th>Menu</th><th>Router</th><th>Descritta</th><th>Limite codice</th><th>Ruoli con vista</th><th>Comparsa</th><th>Stato</th></tr></thead>
    <tbody>
    <?php foreach ($cat as $c): $isNew = $baseline !== '' && $c['first_seen_at'] > $baseline; ?>
      <tr><td><code><?= $h($c['name']) ?></code><?php if ($isNew): ?> <span style="background:#dcfce7;color:#166534;font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px">NUOVA</span><?php endif; ?></td>
        <td><?= $h($c['label']) ?></td><td><?= $h($c['module']) ?></td>
        <td style="text-align:center"><?= $yn($c['in_menu']) ?></td><td style="text-align:center"><?= $yn($c['in_router']) ?></td><td style="text-align:center"><?= $yn($c['in_curated']) ?></td>
        <td style="text-align:center"><?= $c['hard_gate_max_role'] !== null ? '≤ ' . (int)$c['hard_gate_max_role'] : '—' ?></td>
        <td style="text-align:center;<?= (int)$c['ruoli'] === 0 ? 'color:#d97706;font-weight:700' : '' ?>"><?= (int)$c['ruoli'] ?></td>
        <td><?= $c['first_seen_at'] ? $h(date('d/m/Y', strtotime($c['first_seen_at']))) : '' ?></td>
        <td><?= (int)$c['is_active'] ? 'attiva' : '<span style="color:var(--muted)">non più nel codice</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title">Registro esecuzioni</span></div>
  <table class="data-table" style="font-size:12px">
    <thead><tr><th>Quando</th><th>Origine</th><th>Modalità</th><th>Esito</th><th>Modifiche</th><th>Avvisi</th><th>Utente</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr><td><?= $h(date('d/m/Y H:i', strtotime($l['run_at']))) ?></td><td><?= $h($l['trigger_type']) ?></td><td><?= $h($l['mode']) ?></td>
        <td><?= $h($l['status']) ?></td><td><?= (int)$l['changes'] ?></td><td><?= (int)$l['warnings'] ?></td><td><?= $h($l['email'] ?? '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>if (window.jQuery && jQuery.fn.DataTable) jQuery('#tRbacCat').DataTable({pageLength: 50, order: []});</script>
<?php require_once('footer.php'); ?>
