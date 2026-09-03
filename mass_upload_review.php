<?php
/**
 * certV 5.4.0 — mass_upload_review.php
 *
 * Griglia editabile per la review di un job di importazione.
 * Permette di:
 *   - Vedere tutte le righe con stato (valid/invalid/imported/skipped)
 *   - Editare inline le righe in errore
 *   - Re-validare e committare
 *   - Eliminare singole righe dallo staging
 */
require_once('access_control.php');
require_once __DIR__ . '/app/ImportValidator.php';
require_once __DIR__ . '/app/ImportProcessor.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

if ($u_role > 2) {
    header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php')); exit();
}

$job_id = (int)($_GET['job'] ?? 0);
if ($job_id <= 0) {
    header('Location: ' . url('mass_upload')); exit();
}

// ─── CARICA JOB ────────────────────────────────────────────────────────
$j = $pdo->prepare(
    "SELECT j.*, CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS user_name
       FROM import_jobs j
       LEFT JOIN users u ON u.id = j.created_by
       LEFT JOIN employees e ON e.id = u.employee_id
      WHERE j.id = ?"
);
$j->execute([$job_id]);
$job = $j->fetch();
if (!$job) { header('Location: ' . url('mass_upload')); exit(); }

$type = $job['import_type'];
$schema = ImportValidator::getSchema($type);
$schema_keys = array_keys($schema);

// ─── HANDLE POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $proc = new ImportProcessor($pdo, $type);

        if ($action === 'update_row') {
            $stagingId = (int)($_POST['staging_id'] ?? 0);
            $newPayload = $_POST['payload'] ?? [];
            // Pulisce
            $newPayload = array_map(fn($v) => is_string($v) ? trim($v) : $v, (array)$newPayload);
            $result = $proc->updateStagingRow($stagingId, $newPayload, $u_id);
            if ($result['valid']) {
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Riga corretta e validata.</div>";
            } else {
                $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Riga ancora invalida: " . count($result['errors']) . " errori.</div>";
            }
        }
        elseif ($action === 'delete_row') {
            $stagingId = (int)($_POST['staging_id'] ?? 0);
            $pdo->prepare("DELETE FROM import_staging_rows WHERE id = ? AND job_id = ? AND status NOT IN ('imported')")
                ->execute([$stagingId, $job_id]);
            $proc->recalcJobStats($job_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Riga eliminata dallo staging.</div>";
        }
        elseif ($action === 'commit') {
            $stats = $proc->commitJob($job_id, $u_id);
            $imp = (int)($stats['imported'] ?? 0);
            $fld = (int)($stats['failed'] ?? 0);
            $skp = (int)($stats['skipped'] ?? 0);
            $par = (int)($stats['partial'] ?? 0);
            $newStatus = $stats['status'] ?? '?';

            if ($fld > 0 && $imp === 0) {
                $changelog = url_safe('entity_change_log');
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> Commit non riuscito: <strong>$fld righe fallite</strong>, 0 importate. Controllare dettaglio errori in tabella sotto (filtro Solo invalide) o in <a href='$changelog'>Storico modifiche</a>.</div>";
            } elseif ($fld > 0) {
                $extra = ($skp > 0 ? ", $skp saltate" : '') . ($par > 0 ? " ($par in LDB)" : '');
                $_SESSION['flash_msg'] = "<div class='alert alert-warning'><i class='fa-solid fa-check'></i> Commit parziale: <strong>$imp importate</strong>, <strong style='color:#ef4444'>$fld fallite</strong>$extra — status finale: <strong>$newStatus</strong></div>";
            } else {
                $extra = ($skp > 0 ? ", $skp saltate" : '') . ($par > 0 ? " ($par in LDB da completare)" : '');
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Commit riuscito: <strong>$imp importate</strong>$extra — status finale: <strong>$newStatus</strong></div>";
            }
        }
        elseif ($action === 'revalidate') {
            // Re-valida tutte le pending (post-correzioni)
            $pdo->prepare("UPDATE import_staging_rows SET status='pending', errors=NULL, missing_fields=NULL WHERE job_id = ? AND status IN ('valid','invalid','corrected','partial','approved','rejected')")
                ->execute([$job_id]);
            $stats = $proc->validateJob($job_id);
            $extra = isset($stats['partial']) && $stats['partial'] > 0 ? ", {$stats['partial']} parziali" : '';
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Ri-validate: {$stats['valid']} valide{$extra}, {$stats['invalid']} invalide.</div>";
        }
        // ─── v5.6: APPROVAZIONE PER RIGA ─────────────────────────────────
        elseif ($action === 'approve_row') {
            $stagingId = (int)($_POST['staging_id'] ?? 0);
            $mode = $_POST['mode'] ?? 'strict';
            $r = $proc->approveRow($stagingId, $mode, $u_id);
            $modeLabel = $mode === 'ldb' ? 'LDB (campi mancanti)' : 'strict';
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Riga approvata in modalità $modeLabel.</div>";
        }
        elseif ($action === 'reject_row') {
            $stagingId = (int)($_POST['staging_id'] ?? 0);
            $proc->rejectRow($stagingId, $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-ban'></i> Riga rifiutata e esclusa dal commit.</div>";
        }
        elseif ($action === 'unapprove_row') {
            $stagingId = (int)($_POST['staging_id'] ?? 0);
            $proc->unapproveRow($stagingId, $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Approvazione annullata.</div>";
        }
        elseif ($action === 'approve_bulk') {
            $scope = $_POST['scope'] ?? 'all';
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
            $r = $proc->approveBulk($job_id, $scope, $ids, $u_id);
            $tot = $r['approved_strict'] + $r['approved_ldb'];
            $msg = "<i class='fa-solid fa-check-double'></i> Approvazione bulk: <strong>$tot righe</strong>";
            if ($r['approved_ldb'] > 0) $msg .= " ({$r['approved_strict']} strict + {$r['approved_ldb']} LDB)";
            if ($r['skipped'] > 0)      $msg .= ", {$r['skipped']} saltate";
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>$msg</div>";
        }
        elseif ($action === 'abort') {
            $pdo->prepare("UPDATE import_jobs SET status = 'aborted' WHERE id = ?")->execute([$job_id]);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Job annullato.</div>";
            header('Location: ' . url('mass_upload')); exit();
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
    // v5.8.1: redirect con path RELATIVO (PHP_SELF è riscritto da r.php in subdir → 404)
    $qs = '?job=' . $job_id . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '');
    header('Location: mass_upload_review.php' . $qs);
    exit();
}

// ─── FILTRI VISTA ──────────────────────────────────────────────────────
$f_status = $_GET['f_status'] ?? 'all';
$where_extra = '';
$params = [$job_id];
if ($f_status !== 'all') {
    $where_extra = ' AND status = ?';
    $params[] = $f_status;
}

// ─── CARICA RIGHE ──────────────────────────────────────────────────────
$rows = $pdo->prepare(
    "SELECT * FROM import_staging_rows
      WHERE job_id = ?$where_extra
      ORDER BY row_number ASC"
);
$rows->execute($params);
$staging_rows = $rows->fetchAll();

// Conta per status
$counts = $pdo->prepare(
    "SELECT status, COUNT(*) AS n FROM import_staging_rows WHERE job_id = ? GROUP BY status"
);
$counts->execute([$job_id]);
$status_counts = [];
foreach ($counts->fetchAll() as $c) $status_counts[$c['status']] = (int)$c['n'];

require_once('header.php');
?>

<style>
.stage-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.stage-table th { background: #f8fafc; padding: 8px; text-align: left; font-size: 10px; text-transform: uppercase; color: var(--muted); font-weight: 700; border-bottom: 2px solid var(--border); }
.stage-table td { padding: 6px 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
.stage-row.invalid { background: #fef2f2; }
.stage-row.valid, .stage-row.corrected { background: #f0fdf4; }
.stage-row.partial { background: #fffbeb; border-left: 3px solid #f59e0b; }
.stage-row.approved { background: #ecfdf5; border-left: 3px solid #10b981; }
.stage-row.rejected { background: #f8fafc; opacity: .55; border-left: 3px solid #94a3b8; }
.stage-row.rejected td { text-decoration: line-through; }
.stage-row.imported { background: #f8fafc; opacity: .6; }
.stage-row.skipped { background: #fffbeb; opacity: .7; }
.stage-cell { padding: 4px; min-width: 100px; }
.stage-cell input, .stage-cell select { width: 100%; padding: 4px 6px; border: 1px solid var(--border); border-radius: 4px; font-size: 11px; font-family: inherit; }
.stage-cell input.error, .stage-cell select.error { border-color: #ef4444; background: #fee2e2; }
.stage-cell .err-tooltip { font-size: 10px; color: #991b1b; margin-top: 2px; display: block; font-weight: 600; }
.row-status { font-size: 9px; font-weight: 800; text-transform: uppercase; padding: 2px 6px; border-radius: 8px; color: #fff; }
.s-valid { background: #10b981; }
.s-invalid { background: #ef4444; }
.s-corrected { background: #0ea5e9; }
.s-imported { background: #64748b; }
.s-skipped { background: #f59e0b; }
.s-pending { background: #94a3b8; }
.s-partial { background: #f59e0b; }
.s-approved { background: #10b981; }
.s-rejected { background: #94a3b8; }
.compact { font-family: monospace; font-size: 10px; }
</style>

<div style="max-width:100%;margin:0 auto">

  <!-- HEADER JOB -->
  <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:18px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
      <div>
        <h1 style="font-size:20px;font-weight:800;margin-bottom:4px">
          <i class="fa-solid fa-cloud-arrow-up" style="color:var(--p)"></i>
          Job #<?=$job_id?> — <?=h($type)?>
        </h1>
        <div style="font-size:12px;color:var(--muted)">
          <i class="fa-solid fa-file-csv"></i> <?=h($job['original_name'])?>
          · <?=number_format($job['file_size']/1024, 1)?> KB
          · da <?=h(trim($job['user_name'])) ?: 'sconosciuto'?>
          · <?=date('d/m/Y H:i', strtotime($job['started_at']))?>
        </div>
      </div>
      <div>
        <a href="<?= url_safe('mass_upload') ?>" class="btn btn-sm">← Importazione massiva</a>
      </div>
    </div>

    <!-- STATISTICHE -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-top:16px">
      <?php
        $stats_box = [
          ['Totale', $job['total_rows'], '#64748b'],
          ['Valide', ($status_counts['valid'] ?? 0) + ($status_counts['corrected'] ?? 0), '#10b981'],
          ['Parziali', $status_counts['partial'] ?? 0, '#f59e0b'],
          ['Invalide', $status_counts['invalid'] ?? 0, '#ef4444'],
          ['Approvate', $status_counts['approved'] ?? 0, '#059669'],
          ['Rifiutate', $status_counts['rejected'] ?? 0, '#94a3b8'],
          ['Importate', $status_counts['imported'] ?? 0, '#0ea5e9'],
          ['Saltate', $status_counts['skipped'] ?? 0, '#fbbf24'],
        ];
        foreach ($stats_box as [$lbl,$val,$col]):
      ?>
        <div style="background:<?=$col?>15;padding:10px 12px;border-radius:8px;border-left:3px solid <?=$col?>">
          <div style="font-size:10px;text-transform:uppercase;font-weight:700;color:<?=$col?>"><?=$lbl?></div>
          <div style="font-size:22px;font-weight:800;color:<?=$col?>;margin-top:2px"><?=$val?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- AZIONI JOB v5.6 — flusso a 2 fasi: APPROVA poi COMMITTA -->
    <?php if (in_array($job['status'], ['validated','partial','partial_lds'], true)): ?>
    <?php
      $count_valid    = ($status_counts['valid']    ?? 0) + ($status_counts['corrected'] ?? 0);
      $count_partial  = $status_counts['partial']   ?? 0;
      $count_approved = $status_counts['approved']  ?? 0;
      $count_rejected = $status_counts['rejected']  ?? 0;
      $count_invalid  = $status_counts['invalid']   ?? 0;
    ?>

    <!-- FASE 1: APPROVAZIONE -->
    <?php if ($count_valid > 0 || $count_partial > 0): ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin-top:14px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <i class="fa-solid fa-1" style="background:#0ea5e9;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800"></i>
        <strong style="color:#1e40af;font-size:13px">FASE 1 — Approvazione delle righe</strong>
        <span style="color:var(--muted);font-size:11px">Approva ogni riga (anche con campi mancanti) prima del commit</span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($count_valid > 0): ?>
          <form method="POST" onsubmit="return confirm('Approvare TUTTE le <?= $count_valid ?> righe complete (modalità strict)?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_bulk">
            <input type="hidden" name="scope" value="valid">
            <button type="submit" class="btn btn-sm" style="background:#10b981;color:#fff;border-color:#10b981">
              <i class="fa-solid fa-check"></i> Approva tutte le valide (<?= $count_valid ?>)
            </button>
          </form>
        <?php endif; ?>
        <?php if ($count_partial > 0): ?>
          <form method="POST" onsubmit="return confirm('Approvare TUTTE le <?= $count_partial ?> righe parziali in modalità LDB?\nI campi mancanti dovranno essere completati dopo il commit.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_bulk">
            <input type="hidden" name="scope" value="partial">
            <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:#fff;border-color:#f59e0b">
              <i class="fa-solid fa-puzzle-piece"></i> Approva parziali in LDB (<?= $count_partial ?>)
            </button>
          </form>
        <?php endif; ?>
        <?php if ($count_valid > 0 && $count_partial > 0): ?>
          <form method="POST" onsubmit="return confirm('Approvare TUTTE le righe (<?= $count_valid + $count_partial ?>): le complete in strict, le parziali in LDB?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_bulk">
            <input type="hidden" name="scope" value="all">
            <button type="submit" class="btn btn-primary btn-sm">
              <i class="fa-solid fa-check-double"></i> Approva tutte (<?= $count_valid + $count_partial ?>)
            </button>
          </form>
        <?php endif; ?>
        <span style="color:var(--muted);font-size:11px;align-self:center;margin-left:auto">
          <i class="fa-solid fa-circle-info"></i> Oppure approva riga per riga nella tabella sotto
        </span>
      </div>
    </div>
    <?php endif; ?>

    <!-- FASE 2: COMMIT -->
    <div style="background:<?= $count_approved > 0 ? '#f0fdf4' : '#f8fafc' ?>;border:1px solid <?= $count_approved > 0 ? '#86efac' : '#e2e8f0' ?>;border-radius:10px;padding:12px 16px;margin-top:8px">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
        <i class="fa-solid fa-2" style="background:<?= $count_approved > 0 ? '#10b981' : '#94a3b8' ?>;color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800"></i>
        <strong style="color:<?= $count_approved > 0 ? '#065f46' : '#475569' ?>;font-size:13px">FASE 2 — Commit delle righe approvate</strong>
        <?php if ($count_approved === 0): ?>
          <span style="color:var(--muted);font-size:11px">Approva almeno una riga per abilitare il commit</span>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if ($count_approved > 0): ?>
          <form method="POST" onsubmit="return confirm('Importare <?= $count_approved ?> righe approvate nel database?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="commit">
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-database"></i> Commit (<?= $count_approved ?> righe approvate)
            </button>
          </form>
        <?php else: ?>
          <button type="button" disabled class="btn" style="opacity:.5;cursor:not-allowed">
            <i class="fa-solid fa-database"></i> Commit (0 approvate)
          </button>
        <?php endif; ?>
        <?php if ($count_rejected > 0): ?>
          <span style="color:#92400e;font-size:11px;padding:4px 8px;background:#fef3c7;border-radius:6px">
            <i class="fa-solid fa-ban"></i> <?= $count_rejected ?> rifiutate (escluse dal commit)
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="revalidate">
        <button type="submit" class="btn btn-sm"><i class="fa-solid fa-rotate"></i> Ri-valida tutte</button>
      </form>
      <form method="POST" onsubmit="return confirm('Annullare il job? Le righe in staging vengono eliminate.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="abort">
        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-xmark"></i> Annulla job</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?>
    <?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?>
  <?php endif; ?>

  <!-- FILTRI -->
  <form method="GET" class="filter-bar" style="margin-bottom:14px">
    <?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
    <input type="hidden" name="job" value="<?=$job_id?>">
    <div class="fg">
      <label>Filtra stato</label>
      <select name="f_status" onchange="this.form.submit()">
        <option value="all" <?=$f_status==='all'?'selected':''?>>Tutte (<?=$job['total_rows']?>)</option>
        <option value="valid" <?=$f_status==='valid'?'selected':''?>>Solo valide (<?=$status_counts['valid']??0?>)</option>
        <option value="partial" <?=$f_status==='partial'?'selected':''?>>Solo parziali (<?=$status_counts['partial']??0?>)</option>
        <option value="invalid" <?=$f_status==='invalid'?'selected':''?>>Solo invalide (<?=$status_counts['invalid']??0?>)</option>
        <option value="corrected" <?=$f_status==='corrected'?'selected':''?>>Solo corrette (<?=$status_counts['corrected']??0?>)</option>
        <option value="approved" <?=$f_status==='approved'?'selected':''?>>Solo approvate (<?=$status_counts['approved']??0?>)</option>
        <option value="rejected" <?=$f_status==='rejected'?'selected':''?>>Solo rifiutate (<?=$status_counts['rejected']??0?>)</option>
        <option value="imported" <?=$f_status==='imported'?'selected':''?>>Solo importate (<?=$status_counts['imported']??0?>)</option>
        <option value="skipped" <?=$f_status==='skipped'?'selected':''?>>Solo saltate (<?=$status_counts['skipped']??0?>)</option>
      </select>
    </div>
    <div class="fg" style="margin-left:auto">
      <label style="visibility:hidden">.</label>
      <button type="button" onclick="window.print()" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border-color:#fde68a">
        <i class="fa-solid fa-print"></i> Stampa
      </button>
    </div>
  </form>

  <!-- GRIGLIA RIGHE -->
  <?php if (empty($staging_rows)): ?>
    <div style="background:#fff;padding:40px;border-radius:12px;text-align:center;color:var(--muted)">
      Nessuna riga corrispondente al filtro.
    </div>
  <?php else: ?>
    <div style="background:#fff;border-radius:12px;overflow:auto;box-shadow:0 2px 8px rgba(0,0,0,.04);max-height:70vh">
      <table class="stage-table">
        <thead style="position:sticky;top:0;z-index:1">
          <tr>
            <th style="width:50px">#</th>
            <th style="width:90px">Stato</th>
            <?php foreach ($schema_keys as $field):
              $rules = $schema[$field];
              $req = !empty($rules['required']);
              $label = $rules['label'] ?? $field;
              $hint  = $rules['hint']  ?? '';
              $tooltip = "$field" . ($hint ? " — $hint" : '');
            ?>
              <th title="<?= h($tooltip) ?>" style="min-width:140px">
                <div><?= h($label) ?><?= $req ? ' <span style="color:#ef4444">*</span>' : '' ?></div>
                <div class="compact" style="font-weight:400;color:#94a3b8;text-transform:none;margin-top:2px"><?= h($field) ?></div>
              </th>
            <?php endforeach; ?>
            <th style="width:170px;text-align:center" class="no-print">Azioni</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staging_rows as $row):
            $payload = json_decode($row['payload'], true) ?: [];
            $errors  = json_decode($row['errors'] ?: 'null', true) ?: [];
            $editable = in_array($row['status'], ['invalid','valid','corrected','partial','pending'], true);
            $approvable = in_array($row['status'], ['valid','corrected','partial'], true);
            $is_approved = $row['status'] === 'approved';
            $is_rejected = $row['status'] === 'rejected';
            $missing_count = !empty($row['missing_fields']) ? count(json_decode($row['missing_fields'], true) ?: []) : 0;
          ?>
            <form method="POST" class="stage-row <?= h($row['status']) ?>" style="display:table-row">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_row">
              <input type="hidden" name="staging_id" value="<?= (int)$row['id'] ?>">
              <td class="compact" style="font-weight:700"><?= (int)$row['row_number'] ?></td>
              <td>
                <span class="row-status s-<?= h($row['status']) ?>"><?= h($row['status']) ?></span>
                <?php if ($row['status'] === 'approved' && !empty($row['approved_as'])): ?>
                  <div class="compact" style="margin-top:3px">
                    <span style="background:<?= $row['approved_as']==='ldb' ? '#fef3c7' : '#dcfce7' ?>;color:<?= $row['approved_as']==='ldb' ? '#92400e' : '#166534' ?>;padding:1px 6px;border-radius:6px;font-weight:700;font-size:9px;text-transform:uppercase">
                      <?= $row['approved_as'] === 'ldb' ? '⚠ LDB' : '✓ STRICT' ?>
                    </span>
                  </div>
                <?php endif; ?>
                <?php if ($row['status'] === 'partial' && $missing_count > 0): ?>
                  <div class="compact" style="margin-top:3px;color:#92400e;font-weight:600">
                    <?= $missing_count ?> campi mancanti
                  </div>
                <?php endif; ?>
                <?php if ($row['result_id'] && $row['result_action'] !== 'skip'): ?>
                  <div class="compact" style="margin-top:2px;color:#64748b">→ ID <?= (int)$row['result_id'] ?> (<?= $row['result_action'] ?>)</div>
                <?php endif; ?>
              </td>
              <?php foreach ($schema_keys as $field):
                $val = $payload[$field] ?? '';
                $err = $errors[$field] ?? null;
                $rules = $schema[$field];
                $type = $rules['type'] ?? 'string';
              ?>
                <td class="stage-cell">
                  <?php if (!$editable): ?>
                    <span class="compact"><?= h(is_array($val) ? json_encode($val) : (string)$val) ?></span>
                  <?php elseif (!empty($rules['enum'])): ?>
                    <select name="payload[<?= h($field) ?>]" class="<?= $err ? 'error' : '' ?>">
                      <option value="">—</option>
                      <?php foreach ($rules['enum'] as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $val===$opt?'selected':'' ?>><?= h($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($type === 'date'): ?>
                    <input type="date" name="payload[<?= h($field) ?>]" value="<?= h($val) ?>" class="<?= $err ? 'error' : '' ?>">
                  <?php elseif ($type === 'int' || $type === 'decimal'): ?>
                    <input type="number" step="<?= $type==='int'?'1':'0.01' ?>" name="payload[<?= h($field) ?>]" value="<?= h($val) ?>" class="<?= $err ? 'error' : '' ?>">
                  <?php elseif ($type === 'email'): ?>
                    <input type="email" name="payload[<?= h($field) ?>]" value="<?= h($val) ?>" class="<?= $err ? 'error' : '' ?>">
                  <?php elseif ($type === 'bool'): ?>
                    <select name="payload[<?= h($field) ?>]" class="<?= $err ? 'error' : '' ?>">
                      <option value="1" <?= in_array(strtolower((string)$val),['1','true','si','sì','yes'],true)?'selected':'' ?>>Sì</option>
                      <option value="0" <?= in_array(strtolower((string)$val),['0','false','no'],true)?'selected':'' ?>>No</option>
                    </select>
                  <?php else: ?>
                    <input type="text" name="payload[<?= h($field) ?>]" value="<?= h($val) ?>" class="<?= $err ? 'error' : '' ?>">
                  <?php endif; ?>
                  <?php if ($err): ?>
                    <span class="err-tooltip">⚠ <?= h($err) ?></span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td style="text-align:center;white-space:nowrap" class="no-print">
                <div style="display:flex;gap:3px;justify-content:center;align-items:center;flex-wrap:nowrap">
                  <?php if ($editable): ?>
                    <button type="submit" class="btn btn-sm" title="Salva modifiche e ri-valida" style="background:#dbeafe;color:#1e40af;border-color:#93c5fd;padding:4px 7px">
                      <i class="fa-solid fa-floppy-disk"></i>
                    </button>
                  <?php endif; ?>
                  <!-- I bottoni approvazione sono fuori dal form (form separati sotto) -->
                  <?php if ($approvable && $row['status'] !== 'partial'): ?>
                    <button type="submit" form="app_strict_<?= (int)$row['id'] ?>" class="btn btn-sm" title="Approva (modalità strict)" style="background:#10b981;color:#fff;border-color:#10b981;padding:4px 7px">
                      <i class="fa-solid fa-check"></i>
                    </button>
                  <?php endif; ?>
                  <?php if ($approvable && $row['status'] === 'partial'): ?>
                    <button type="submit" form="app_ldb_<?= (int)$row['id'] ?>" class="btn btn-sm" title="Approva in modalità LDB (<?= $missing_count ?> campi mancanti, da completare dopo)" style="background:#f59e0b;color:#fff;border-color:#f59e0b;padding:4px 7px">
                      <i class="fa-solid fa-puzzle-piece"></i>
                    </button>
                  <?php endif; ?>
                  <?php if ($approvable): ?>
                    <button type="submit" form="rej_<?= (int)$row['id'] ?>" class="btn btn-sm" title="Rifiuta riga (esclude dal commit)" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;padding:4px 7px">
                      <i class="fa-solid fa-ban"></i>
                    </button>
                  <?php endif; ?>
                  <?php if ($is_approved || $is_rejected): ?>
                    <button type="submit" form="unapp_<?= (int)$row['id'] ?>" class="btn btn-sm" title="Annulla approvazione/rifiuto" style="background:#fef3c7;color:#92400e;border-color:#fde68a;padding:4px 7px">
                      <i class="fa-solid fa-rotate-left"></i>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </form>
            <?php
              // v5.6: form ausiliari per approvazione (esterni al form di update)
              $aux_forms = [];
              if ($approvable && $row['status'] !== 'partial') $aux_forms['app_strict'] = ['action' => 'approve_row', 'extra' => ['mode' => 'strict']];
              if ($approvable && $row['status'] === 'partial') $aux_forms['app_ldb']    = ['action' => 'approve_row', 'extra' => ['mode' => 'ldb']];
              if ($approvable)                                  $aux_forms['rej']       = ['action' => 'reject_row', 'extra' => []];
              if ($is_approved || $is_rejected)                $aux_forms['unapp']     = ['action' => 'unapprove_row', 'extra' => []];
            ?>
            <?php foreach ($aux_forms as $key => $cfg): ?>
              <tr style="display:none"><td><form method="POST" id="<?= $key ?>_<?= (int)$row['id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= h($cfg['action']) ?>">
                <input type="hidden" name="staging_id" value="<?= (int)$row['id'] ?>">
                <?php foreach ($cfg['extra'] as $k => $v): ?>
                  <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                <?php endforeach; ?>
              </form></td></tr>
            <?php endforeach; ?>
            <?php if ($editable): ?>
              <form method="POST" style="display:none" id="del_<?=$row['id']?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_row">
                <input type="hidden" name="staging_id" value="<?= (int)$row['id'] ?>">
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once('footer.php'); ?>
