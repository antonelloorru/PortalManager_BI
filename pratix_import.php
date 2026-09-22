<?php
/**
 * PortalManager — pratix_import.php
 * Upload ed esecuzione della pipeline ETL Pratix (app/PratixImporter.php).
 * Accesso: Super Admin (1), HR Director (2), Finance (11) — o via RBAC can('view').
 */
require_once('access_control.php');
require_once __DIR__ . '/app/PratixImporter.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1 && !can('view', 'pratix_import.php') && !in_array($u_role, [2, 11], true)) {
    http_response_code(403);
    die('Accesso negato.');
}

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = '';
$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    try {
        if (empty($_FILES['pratix_file']) || $_FILES['pratix_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Nessun file caricato o errore di upload.');
        }
        if ($_FILES['pratix_file']['size'] > 40 * 1024 * 1024) throw new RuntimeException('File troppo grande (max 40 MB).');
        $ext = strtolower(pathinfo($_FILES['pratix_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx'], true)) throw new RuntimeException('Formato non valido: caricare un file .xls o .xlsx.');

        $dir = APP_ROOT . '/uploads/pratix/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $stored = $dir . 'pratix_' . $u_id . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['pratix_file']['tmp_name'], $stored)) {
            throw new RuntimeException('Impossibile salvare il file.');
        }

        $imp = new PratixImporter($pdo, $u_id);
        $report = $imp->import($stored, $_FILES['pratix_file']['name']);
        @unlink($stored);

        if (function_exists('write_log')) {
            write_log('Pratix', 'success',
                "Import Pratix: {$report['upsert']} record (letti {$report['letti']})", $u_id);
        }
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Import completato.</div>";
    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . $h($e->getMessage()) . "</div>";
    }
}
?>
<div class="container" style="max-width:840px;margin:0 auto;padding:18px">
  <h1 style="font-size:20px;font-weight:800;margin-bottom:4px">
    <i class="fa-solid fa-file-import"></i> Import Pratix</h1>
  <p style="color:var(--muted);font-size:13px;margin-bottom:16px">
    Carica il report Pratix (.xls / .xlsx). I dati vengono associati agli ordinativi/commesse
    tramite <strong>Codice</strong> = <code>order_code</code> e arricchiscono le viste
    "Ordini Pratix" e "Commesse / Progetti".
  </p>
  <?= $msg ?>

  <div class="card" style="padding:18px;margin-bottom:16px">
    <form method="POST" enctype="multipart/form-data">
      <?= function_exists('csrf_field') ? csrf_field() : '' ?>
      <input type="hidden" name="action" value="import">
      <div class="form-group">
        <label>File Pratix (.xls / .xlsx)</label>
        <input type="file" name="pratix_file" required accept=".xls,.xlsx">
      </div>
      <button class="btn btn-primary"><i class="fa-solid fa-database"></i> Importa</button>
    </form>
  </div>

  <?php if ($report): ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
    <?php foreach ([
        ['Righe lette', $report['letti'], '#334155'],
        ['Upsert', $report['upsert'], '#16a34a'],
        ['Senza Codice', $report['saltati_no_codice'], '#f59e0b'],
        ['Errori riga', $report['errori'], '#dc2626'],
    ] as [$l, $v, $c]): ?>
      <div class="card" style="text-align:center;padding:13px;border-top:3px solid <?=$c?>">
        <div style="font-size:22px;font-weight:800;color:<?=$c?>"><?=(int)$v?></div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=$h($l)?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($report['messaggi'])): ?>
    <div class="card" style="padding:12px 16px;font-size:12px;color:#b91c1c">
      <strong>Avvisi:</strong>
      <ul style="margin:6px 0 0 18px"><?php foreach ($report['messaggi'] as $m): ?><li><?=$h($m)?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once('footer.php'); ?>
