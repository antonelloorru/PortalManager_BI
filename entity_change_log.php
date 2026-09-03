<?php
/**
 * certV 5.7.0 — entity_change_log.php
 *
 * Viewer cross-tabella per audit trail.
 * Filtri: tabella, ID record, source (import/ui/api), utente, data range.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/EntityChangeLog.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role > 1) { header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php')); exit(); }

// ─── FILTRI ─────────────────────────────────────────────────────────────
$f_table   = trim((string)($_GET['f_table'] ?? ''));
$f_entity  = (int)($_GET['f_entity'] ?? 0);
$f_source  = trim((string)($_GET['f_source'] ?? ''));
$f_user    = (int)($_GET['f_user'] ?? 0);
$f_action  = trim((string)($_GET['f_action'] ?? ''));
$f_field   = trim((string)($_GET['f_field'] ?? ''));
$f_from    = trim((string)($_GET['f_from'] ?? ''));
$f_to      = trim((string)($_GET['f_to'] ?? ''));
$f_jobid   = (int)($_GET['f_jobid'] ?? 0);

$where = ['1=1'];
$params = [];
if ($f_table !== '')   { $where[] = 'ecl.entity_table = ?'; $params[] = $f_table; }
if ($f_entity > 0)     { $where[] = 'ecl.entity_id = ?'; $params[] = $f_entity; }
if ($f_source !== '')  { $where[] = 'ecl.change_source = ?'; $params[] = $f_source; }
if ($f_user > 0)       { $where[] = 'ecl.changed_by = ?'; $params[] = $f_user; }
if ($f_action !== '')  { $where[] = 'ecl.change_action = ?'; $params[] = $f_action; }
if ($f_field !== '')   { $where[] = 'ecl.field_name LIKE ?'; $params[] = '%' . $f_field . '%'; }
if ($f_from !== '')    { $where[] = 'ecl.changed_at >= ?'; $params[] = $f_from . ' 00:00:00'; }
if ($f_to !== '')      { $where[] = 'ecl.changed_at <= ?'; $params[] = $f_to . ' 23:59:59'; }
if ($f_jobid > 0)      { $where[] = "ecl.change_source = 'import' AND ecl.source_ref_id = ?"; $params[] = $f_jobid; }

$sql = "SELECT ecl.*,
               CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,'')) AS user_name
          FROM entity_change_log ecl
          LEFT JOIN users u ON u.id = ecl.changed_by
          LEFT JOIN employees e ON e.id = u.employee_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY ecl.changed_at DESC, ecl.id DESC
         LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distinct per dropdown
$tables_distinct = $pdo->query("SELECT DISTINCT entity_table FROM entity_change_log ORDER BY entity_table")->fetchAll(PDO::FETCH_COLUMN);
$users_distinct  = $pdo->query(
    "SELECT u.id, CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,'')) AS name
       FROM users u LEFT JOIN employees e ON e.id = u.employee_id
      INNER JOIN entity_change_log ecl ON ecl.changed_by = u.id
      GROUP BY u.id ORDER BY name"
)->fetchAll();

require_once('header.php');
?>

<style>
.log-table { width:100%; border-collapse:collapse; background:#fff; font-size:12px; }
.log-table th { background:#f8fafc; padding:8px; text-align:left; font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; border-bottom:2px solid var(--border); position:sticky; top:0; }
.log-table td { padding:7px 8px; border-bottom:1px solid var(--border); vertical-align:top; }
.action-pill { padding:2px 7px; border-radius:6px; font-size:10px; font-weight:800; text-transform:uppercase; color:#fff; }
.a-insert  { background:#10b981; }
.a-update  { background:#0ea5e9; }
.a-delete  { background:#ef4444; }
.a-approve { background:#16a34a; }
.a-reject  { background:#94a3b8; }
.source-pill { padding:1px 6px; border-radius:5px; font-size:9px; font-weight:700; background:#e5e7eb; color:#475569; }
.s-import { background:#fef3c7; color:#92400e; }
.s-ui     { background:#dbeafe; color:#1e40af; }
.s-api    { background:#ede9fe; color:#5b21b6; }
.s-system { background:#f1f5f9; color:#475569; }
.diff-cell { max-width:240px; }
.diff-old  { background:#fef2f2; color:#991b1b; padding:1px 4px; border-radius:3px; font-family:monospace; font-size:11px; text-decoration:line-through; }
.diff-new  { background:#f0fdf4; color:#166534; padding:1px 4px; border-radius:3px; font-family:monospace; font-size:11px; }
</style>

<div style="max-width:1400px;margin:0 auto">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-clock-rotate-left" style="color:#64748b"></i> Storico modifiche entità
      </h1>
      <div style="color:var(--muted);font-size:13px">Audit trail cross-tabella per tutte le modifiche tracciate</div>
    </div>
  </div>

  <!-- FILTRI -->
  <form method="GET" class="filter-bar">
    <?php if (!empty($_GET['r'])): ?><input type="hidden" name="r" value="<?= h($_GET['r']) ?>"><?php endif; ?>
    <div class="fg"><label>Tabella</label>
      <select name="f_table">
        <option value="">Tutte</option>
        <?php foreach ($tables_distinct as $t): ?>
          <option value="<?= h($t) ?>" <?= $f_table===$t?'selected':'' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>ID entità</label>
      <input type="number" name="f_entity" value="<?= $f_entity > 0 ? $f_entity : '' ?>" min="0" style="width:80px">
    </div>
    <div class="fg"><label>Azione</label>
      <select name="f_action">
        <option value="">Tutte</option>
        <option value="insert"  <?= $f_action==='insert'?'selected':''  ?>>insert</option>
        <option value="update"  <?= $f_action==='update'?'selected':''  ?>>update</option>
        <option value="approve" <?= $f_action==='approve'?'selected':'' ?>>approve</option>
        <option value="reject"  <?= $f_action==='reject'?'selected':''  ?>>reject</option>
        <option value="delete"  <?= $f_action==='delete'?'selected':''  ?>>delete</option>
      </select>
    </div>
    <div class="fg"><label>Source</label>
      <select name="f_source">
        <option value="">Tutte</option>
        <option value="import" <?= $f_source==='import'?'selected':''?>>import</option>
        <option value="ui"     <?= $f_source==='ui'?'selected':''    ?>>ui</option>
        <option value="api"    <?= $f_source==='api'?'selected':''   ?>>api</option>
        <option value="migration" <?= $f_source==='migration'?'selected':''?>>migration</option>
        <option value="system" <?= $f_source==='system'?'selected':''?>>system</option>
      </select>
    </div>
    <div class="fg"><label>Campo</label>
      <input type="text" name="f_field" value="<?= h($f_field) ?>" placeholder="es. status" style="width:120px">
    </div>
    <div class="fg"><label>Utente</label>
      <select name="f_user">
        <option value="0">Tutti</option>
        <?php foreach ($users_distinct as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $f_user===(int)$u['id']?'selected':'' ?>><?= h(trim($u['name'])) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>Job ID</label>
      <input type="number" name="f_jobid" value="<?= $f_jobid > 0 ? $f_jobid : '' ?>" min="0" style="width:80px">
    </div>
    <div class="fg"><label>Da</label><input type="date" name="f_from" value="<?= h($f_from) ?>"></div>
    <div class="fg"><label>A</label><input type="date" name="f_to" value="<?= h($f_to) ?>"></div>
    <div class="fg"><label>&nbsp;</label>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
    </div>
    <div class="fg" style="margin-left:auto"><label>&nbsp;</label>
      <span style="font-weight:700;color:var(--muted);font-size:12px"><?= count($logs) ?> eventi</span>
    </div>
  </form>

  <!-- TABELLA -->
  <div style="background:#fff;border-radius:12px;overflow:auto;box-shadow:0 2px 8px rgba(0,0,0,.04);max-height:75vh">
    <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('entity_change_log', '#lf-table-entity_change_log', ['export_filename' => 'entity_change_log', 'title' => 'Audit log modifiche']); ?>
<table id="lf-table-entity_change_log" class="log-table">
      <thead>
        <tr>
          <th>Quando</th>
          <th>Source</th>
          <th>Azione</th>
          <th>Tabella</th>
          <th>ID</th>
          <th>Campo</th>
          <th>Da → A</th>
          <th>Utente</th>
          <th>Ref</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $l):
          $isCreate = $l['field_name'] === '__create__';
          $isDelete = $l['field_name'] === '__delete__';
        ?>
          <tr>
            <td style="white-space:nowrap;color:#64748b;font-size:11px">
              <?= date('d/m/Y H:i:s', strtotime($l['changed_at'])) ?>
            </td>
            <td>
              <span class="source-pill s-<?= h($l['change_source']) ?>"><?= h($l['change_source']) ?></span>
            </td>
            <td>
              <span class="action-pill a-<?= h($l['change_action']) ?>"><?= h($l['change_action']) ?></span>
            </td>
            <td style="font-family:monospace;font-size:11px"><?= h($l['entity_table']) ?></td>
            <td style="font-family:monospace;font-weight:700">#<?= (int)$l['entity_id'] ?></td>
            <td style="font-family:monospace;font-size:11px;color:#475569">
              <?php if ($isCreate): ?>
                <em style="color:#10b981">[creazione]</em>
              <?php elseif ($isDelete): ?>
                <em style="color:#dc2626">[eliminazione]</em>
              <?php else: ?>
                <?= h($l['field_name']) ?>
              <?php endif; ?>
            </td>
            <td class="diff-cell">
              <?php if ($isCreate || $isDelete): ?>
                <em style="color:var(--muted);font-size:11px">—</em>
              <?php else: ?>
                <?php
                  $oldV = $l['old_value'];
                  $newV = $l['new_value'];
                  $oldShort = $oldV !== null ? mb_strimwidth($oldV, 0, 30, '…') : '∅';
                  $newShort = $newV !== null ? mb_strimwidth($newV, 0, 30, '…') : '∅';
                ?>
                <span class="diff-old"><?= h($oldShort) ?></span>
                <span style="color:var(--muted);margin:0 4px">→</span>
                <span class="diff-new"><?= h($newShort) ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:11px"><?= h(trim((string)$l['user_name'])) ?: '<em style="color:var(--muted)">system</em>' ?></td>
            <td style="font-size:11px;color:var(--muted)">
              <?php if ($l['source_ref_id']): ?>
                <?php if ($l['change_source'] === 'import'): ?>
                  <a href="<?= url_safe('mass_upload_review', ['job' => (int)$l['source_ref_id']]) ?>" title="Vai al job di import">Job #<?= (int)$l['source_ref_id'] ?></a>
                <?php else: ?>
                  ref #<?= (int)$l['source_ref_id'] ?>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
          <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">Nessun evento corrispondente ai filtri.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="font-size:11px;color:var(--muted);margin-top:8px;text-align:right">
    Limite visualizzazione: 500 eventi più recenti. Affina i filtri per cercare nel passato.
  </div>
</div>

<?php require_once('footer.php'); ?>
