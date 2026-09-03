<?php
/**
 * certV 5.4.0 — mass_upload_jobs.php
 * Storico completo dei job di importazione massiva con filtri e dettaglio.
 */
require_once('access_control.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

if ($u_role > 2) {
    header('Location: unauthorized.php'); exit();
}

// ─── DELETE JOB (cancella anche staging in cascata) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $jid = (int)$_POST['job_id'];
    if ($jid > 0 && $u_role === 1) {
        $pdo->prepare("DELETE FROM import_jobs WHERE id = ?")->execute([$jid]);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Job #$jid eliminato.</div>";
    }
    redirect_self();
}

// ─── FILTRI ────────────────────────────────────────────────────────────
$f_type = $_GET['f_type'] ?? '';
$f_status = $_GET['f_status'] ?? '';
$f_user = $_GET['f_user'] ?? '';
$f_date_from = $_GET['f_date_from'] ?? '';
$f_date_to = $_GET['f_date_to'] ?? '';

$where = ['1=1'];
$params = [];
if ($f_type) { $where[] = 'j.import_type = ?'; $params[] = $f_type; }
if ($f_status) { $where[] = 'j.status = ?'; $params[] = $f_status; }
if ($f_user) { $where[] = 'j.created_by = ?'; $params[] = (int)$f_user; }
if ($f_date_from) { $where[] = 'j.started_at >= ?'; $params[] = $f_date_from . ' 00:00:00'; }
if ($f_date_to) { $where[] = 'j.started_at <= ?'; $params[] = $f_date_to . ' 23:59:59'; }

$jobs = $pdo->prepare(
    "SELECT j.*,
            CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS user_name
       FROM import_jobs j
       LEFT JOIN users u ON u.id = j.created_by
       LEFT JOIN employees e ON e.id = u.employee_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY j.started_at DESC
      LIMIT 500"
);
$jobs->execute($params);
$jobs_list = $jobs->fetchAll();

// Tipi e utenti distinti per filtri
$types_distinct = $pdo->query("SELECT DISTINCT import_type FROM import_jobs ORDER BY import_type")->fetchAll(PDO::FETCH_COLUMN);
$users_distinct = $pdo->query(
    "SELECT u.id, CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS name
       FROM users u
       LEFT JOIN employees e ON e.id = u.employee_id
       INNER JOIN import_jobs j ON j.created_by = u.id
       GROUP BY u.id ORDER BY name"
)->fetchAll();

require_once('header.php');
?>

<div style="max-width:1300px;margin:0 auto">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-clock-rotate-left" style="color:var(--p)"></i> Storico job di importazione
      </h1>
      <div style="color:var(--muted);font-size:13px">Tutti i job di importazione massiva con dettaglio</div>
    </div>
    <a href="<?= url_safe('mass_upload') ?>" class="btn btn-primary"><i class="fa-solid fa-cloud-arrow-up"></i> Nuovo import</a>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?>
    <?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?>
  <?php endif; ?>

  <!-- FILTRI -->
  <form method="GET" class="filter-bar">
    <?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
    <div class="fg">
      <label>Tipo</label>
      <select name="f_type">
        <option value="">Tutti</option>
        <?php foreach ($types_distinct as $t): ?>
          <option value="<?=h($t)?>" <?=$f_type===$t?'selected':''?>><?=h($t)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>Stato</label>
      <select name="f_status">
        <option value="">Tutti</option>
        <?php foreach (['uploaded','validated','partial','imported','aborted','rolled_back'] as $s): ?>
          <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>Utente</label>
      <select name="f_user">
        <option value="">Tutti</option>
        <?php foreach ($users_distinct as $usr): ?>
          <option value="<?=$usr['id']?>" <?=(string)$f_user===(string)$usr['id']?'selected':''?>><?=h(trim($usr['name']))?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg">
      <label>Da data</label>
      <input type="date" name="f_date_from" value="<?=h($f_date_from)?>">
    </div>
    <div class="fg">
      <label>A data</label>
      <input type="date" name="f_date_to" value="<?=h($f_date_to)?>">
    </div>
    <div class="fg">
      <label>&nbsp;</label>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Filtra</button>
    </div>
    <div class="fg" style="margin-left:auto">
      <label style="visibility:hidden">.</label>
      <button type="button" onclick="window.print()" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border-color:#fde68a">
        <i class="fa-solid fa-print"></i> Stampa
      </button>
    </div>
  </form>

  <!-- TABELLA JOB -->
  <?php if (empty($jobs_list)): ?>
    <div style="background:#fff;padding:40px;border-radius:12px;text-align:center;color:var(--muted)">
      Nessun job trovato.
    </div>
  <?php else: ?>
    <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)">
      <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('mass_upload_jobs', '#lf-table-mass_upload_jobs', ['export_filename' => 'mass_upload_jobs', 'title' => 'Caricamenti massivi']); ?>
<table id="lf-table-mass_upload_jobs" class="data-table" style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
          <tr style="background:#f8fafc">
            <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">ID</th>
            <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Tipo</th>
            <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">File</th>
            <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Utente</th>
            <th style="padding:10px;text-align:right;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Tot</th>
            <th style="padding:10px;text-align:right;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Imp.</th>
            <th style="padding:10px;text-align:right;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Inv.</th>
            <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Stato</th>
            <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Avviato</th>
            <th style="padding:10px;text-align:center;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700;width:120px" class="no-print">Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jobs_list as $j):
            $statusInfo = match ($j['status']) {
              'uploaded'    => ['Caricato',    '#64748b'],
              'validated'   => ['Validato',    '#0ea5e9'],
              'partial'     => ['Parziale',    '#f59e0b'],
              'imported'    => ['Importato',   '#10b981'],
              'aborted'     => ['Annullato',   '#ef4444'],
              'rolled_back' => ['Rollback',    '#92400e'],
              default       => [$j['status'], '#94a3b8'],
            };
            $can_open = in_array($j['status'], ['uploaded','validated','partial'], true);
          ?>
            <tr style="border-top:1px solid var(--border)">
              <td style="padding:8px 10px;font-family:monospace;font-weight:700">#<?=$j['id']?></td>
              <td style="padding:8px 10px"><?=h($j['import_type'])?></td>
              <td style="padding:8px 10px;font-family:monospace;color:#475569;font-size:11px"><?=h(mb_strimwidth($j['original_name'], 0, 50, '…'))?></td>
              <td style="padding:8px 10px"><?=h(trim($j['user_name'])) ?: '—'?></td>
              <td style="padding:8px 10px;text-align:right;font-weight:700"><?=$j['total_rows']?></td>
              <td style="padding:8px 10px;text-align:right;color:#10b981;font-weight:700"><?=$j['imported_rows']?></td>
              <td style="padding:8px 10px;text-align:right;color:<?= $j['invalid_rows']>0 ? '#ef4444' : '#94a3b8' ?>;font-weight:700"><?=$j['invalid_rows']?></td>
              <td style="padding:8px 10px">
                <span style="background:<?=$statusInfo[1]?>;color:#fff;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700"><?=$statusInfo[0]?></span>
              </td>
              <td style="padding:8px 10px;color:#64748b;font-size:11px"><?=date('d/m/Y H:i', strtotime($j['started_at']))?></td>
              <td style="padding:8px 10px;text-align:center;white-space:nowrap" class="no-print">
                <?php if ($can_open): ?>
                  <a href="mass_upload_review.php?job=<?=$j['id']?><?= !empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '' ?>" class="btn btn-sm btn-primary" title="Apri">
                    <i class="fa-solid fa-arrow-right"></i>
                  </a>
                <?php else: ?>
                  <a href="mass_upload_review.php?job=<?=$j['id']?><?= !empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '' ?>" class="btn btn-sm" title="Dettaglio">
                    <i class="fa-solid fa-eye"></i>
                  </a>
                <?php endif; ?>
                <?php if ($u_role === 1): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare il job #<?=$j['id']?> e tutto lo staging?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="job_id" value="<?=$j['id']?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Elimina">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once('footer.php'); ?>
