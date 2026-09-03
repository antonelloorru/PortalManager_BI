<?php
/**
 * PortalManager 1.5.1 — device_import.php
 *
 * Importazione bulk dispositivi da CSV/Excel.
 * - Scarica template (CSV) per ogni categoria
 * - Upload CSV → preview → conferma import
 *
 * URL: device_import.php?employee_id=N
 */

require_once('access_control.php');
require_once('header.php');

$u_role  = (int)($_SESSION['role_id'] ?? 99);
$u_id    = (int)$_SESSION['user_id'];
$can_edit = in_array($u_role, [1, 2], true);
if (!$can_edit) { http_response_code(403); die('Solo Admin/HR possono importare dispositivi.'); }

$emp_id = (int)($_GET['employee_id'] ?? 0);
if (!$emp_id) { redirect('manage_employees'); }

$st = $pdo->prepare("SELECT id, first_name, last_name, employee_code FROM employees WHERE id=?");
$st->execute([$emp_id]);
$emp = $st->fetch();
if (!$emp) { redirect('manage_employees'); }

$msg = '';
$preview = null;
$category = $_REQUEST['category'] ?? 'phone';

// Definizione colonne attese per ogni categoria
$schemas = [
    'phone' => [
        'label'   => 'Telefoni aziendali',
        'table'   => 'emp_devices_phone',
        'columns' => ['brand','model','imei_1','imei_2','serial_number','assigned_at','returned_at','status','notes'],
        'headers' => ['Marca','Modello','IMEI 1','IMEI 2','S/N','Data consegna','Data ritiro','Stato','Note'],
    ],
    'sim' => [
        'label'   => 'SIM aziendali',
        'table'   => 'emp_devices_sim',
        'columns' => ['sim_type','phone_number','serial_number','pin_code','puk_code','operator','assigned_at','returned_at','status','notes'],
        'headers' => ['Tipo (voce/dati)','Numero','ICCID','PIN','PUK','Operatore','Data consegna','Data ritiro','Stato','Note'],
    ],
    'notebook' => [
        'label'   => 'Notebook',
        'table'   => 'emp_devices_notebook',
        'columns' => ['brand','model','serial_number','specs','os','assigned_at','returned_at','status','notes'],
        'headers' => ['Marca','Modello','S/N','Caratteristiche','Sistema operativo','Data consegna','Data ritiro','Stato','Note'],
    ],
    'vehicle' => [
        'label'   => 'Veicoli',
        'table'   => 'emp_devices_vehicle',
        'columns' => ['brand','model','plate','fuel_type','acquisition_type','contract_ref','contract_start','contract_end','monthly_cost','conditions','initial_km','current_km','assigned_at','returned_at','status','notes'],
        'headers' => ['Marca','Modello','Targa','Alimentazione','Acquisizione','Contratto','Inizio contratto','Fine contratto','Costo rateo','Condizioni','Km iniziali','Km attuali','Data consegna','Data ritiro','Stato','Note'],
    ],
    'fuel_card' => [
        'label'   => 'Carte carburante',
        'table'   => 'emp_devices_fuel_card',
        'columns' => ['circuit','card_number','pin_code','assigned_at','returned_at','status','notes'],
        'headers' => ['Circuito','Numero','PIN','Data consegna','Data ritiro','Stato','Note'],
    ],
    'credit_card' => [
        'label'   => 'Carte credito',
        'table'   => 'emp_devices_credit_card',
        'columns' => ['circuit','bank','card_number_last4','pin_code','credit_limit','assigned_at','returned_at','status','notes'],
        'headers' => ['Circuito','Banca','Ultime 4','PIN','Plafond','Data consegna','Data ritiro','Stato','Note'],
    ],
];

if (!isset($schemas[$category])) $category = 'phone';
$schema = $schemas[$category];

// ─── Download template ───────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'download_template') {
    $filename = "template_{$category}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    echo "\xEF\xBB\xBF"; // BOM per Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, $schema['headers'], ';');
    // Riga esempio
    $example = match ($category) {
        'phone'      => ['Samsung','Galaxy S24','350987654321001','350987654321002','SN-XYZ-001','2026-01-15','','assegnato','Nuovo dispositivo'],
        'sim'        => ['voce','+39 333 1234567','8939010100000000001','1234','12345678','TIM','2026-01-15','','attiva',''],
        'notebook'   => ['Lenovo','ThinkPad X1 Carbon Gen 11','SN-LN-001','Intel i7, 16GB RAM, 512GB SSD','Windows 11 Pro','2026-01-15','','assegnato',''],
        'vehicle'    => ['Fiat','500X','AB123CD','Benzina','noleggio','NLT-2026-001','2026-01-15','2029-01-15','450.00','30000 km/anno','15000','15000','2026-01-15','','assegnato',''],
        'fuel_card'  => ['ENI','7039201234567890','1234','2026-01-15','','attiva',''],
        'credit_card'=> ['Visa','Intesa Sanpaolo','1234','9876','5000.00','2026-01-15','','attiva',''],
        default      => array_fill(0, count($schema['columns']), '')
    };
    fputcsv($out, $example, ';');
    fclose($out);
    exit;
}

// ─── Upload CSV ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "<div class='alert alert-danger'>Errore caricamento file.</div>";
    } else {
        $rows = [];
        $h_fp = fopen($_FILES['csv_file']['tmp_name'], 'r');
        // Skip BOM
        $first = fgets($h_fp); rewind($h_fp);
        if (substr($first, 0, 3) === "\xEF\xBB\xBF") fread($h_fp, 3);

        $header = fgetcsv($h_fp, 0, ';');
        if (!$header || count($header) < count($schema['columns']) - 2) {
            // Prova con virgola
            rewind($h_fp);
            if (substr($first, 0, 3) === "\xEF\xBB\xBF") fread($h_fp, 3);
            $header = fgetcsv($h_fp, 0, ',');
        }
        while (($row = fgetcsv($h_fp, 0, ';')) !== false || ($row = fgetcsv($h_fp, 0, ',')) !== false) {
            if (count($row) === 1 && empty($row[0])) continue;
            $rows[] = $row;
        }
        fclose($h_fp);

        if (empty($rows)) {
            $msg = "<div class='alert alert-warning'>Il file non contiene righe dati.</div>";
        } else {
            $_SESSION['_dev_import'] = ['category' => $category, 'rows' => $rows, 'emp_id' => $emp_id];
            $preview = $rows;
            $msg = "<div class='alert alert-info'><i class='fa-solid fa-circle-info'></i> " . count($rows) . " riga/e in preview. Verifica e clicca 'Importa'.</div>";
        }
    }
}

// ─── Conferma import ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_import') {
    $session_data = $_SESSION['_dev_import'] ?? null;
    if (!$session_data || $session_data['emp_id'] !== $emp_id) {
        $msg = "<div class='alert alert-danger'>Sessione import scaduta. Ricarica il file.</div>";
    } else {
        $cat = $session_data['category'];
        $rows = $session_data['rows'];
        $sch = $schemas[$cat];
        $imported = 0; $errors = [];
        $cols = $sch['columns'];
        $placeholders = implode(',', array_fill(0, count($cols) + 2, '?'));
        $col_list = '`' . implode('`,`', $cols) . '`,`employee_id`,`created_by`';
        $sql = "INSERT INTO `{$sch['table']}` ({$col_list}) VALUES ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        foreach ($rows as $i => $row) {
            try {
                $vals = [];
                foreach ($cols as $idx => $col) {
                    $val = trim($row[$idx] ?? '');
                    // Date: empty → null
                    if (in_array($col, ['assigned_at','returned_at','contract_start','contract_end'], true) && $val === '') $val = null;
                    // Numeric: empty → null
                    if (in_array($col, ['monthly_cost','initial_km','current_km','credit_limit'], true) && $val === '') $val = null;
                    if ($val === '') $val = null;
                    $vals[] = $val;
                }
                $vals[] = $emp_id;
                $vals[] = $u_id;
                $stmt->execute($vals);
                $imported++;
            } catch (Throwable $e) {
                $errors[] = "Riga " . ($i+2) . ": " . $e->getMessage();
            }
        }
        unset($_SESSION['_dev_import']);
        if (function_exists('write_log')) write_log('Devices', 'success', "Import $cat emp=$emp_id: $imported righe", $u_id);

        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Importate <strong>{$imported}</strong> righe.</div>";
        if ($errors) {
            $msg .= "<div class='alert alert-warning'><strong>Errori:</strong><ul style='margin:6px 0 0 18px;font-size:11px'>";
            foreach ($errors as $e) $msg .= "<li>" . htmlspecialchars($e) . "</li>";
            $msg .= "</ul></div>";
        }
    }
}

// ─── UI ──────────────────────────────────────────────────────────────
$emp_label = trim($emp['first_name'] . ' ' . $emp['last_name']);
?>

<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
  <a href="employee_profile.php?id=<?= $emp_id ?>&tab=dispositivi" class="btn btn-sm">
    <i class="fa-solid fa-arrow-left"></i> Torna ai dispositivi
  </a>
  <div>
    <h1 style="font-size:18px;font-weight:800;margin:0">
      <i class="fa-solid fa-file-import" style="color:var(--p)"></i> Importa dispositivi
    </h1>
    <div style="font-size:12px;color:var(--muted)">
      Dipendente: <strong><?= htmlspecialchars($emp_label) ?></strong>
      <?php if ($emp['employee_code']): ?> · matricola <?= htmlspecialchars($emp['employee_code']) ?><?php endif; ?>
    </div>
  </div>
</div>

<?= $msg ?>

<!-- Selettore categoria -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-list-check"></i> 1. Scegli categoria da importare</span>
  </div>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach ($schemas as $key => $sc):
      $active = ($category === $key);
    ?>
    <a href="?employee_id=<?= $emp_id ?>&category=<?= $key ?>"
       style="padding:8px 16px;border-radius:8px;text-decoration:none;font-weight:700;font-size:12px;
              <?= $active ? 'background:var(--p);color:#fff' : 'background:#f1f5f9;color:#475569;border:1px solid #cbd5e1' ?>">
      <?= htmlspecialchars($sc['label']) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Step 2: Template -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-download"></i> 2. Scarica template</span>
    <a href="?employee_id=<?= $emp_id ?>&category=<?= $category ?>&action=download_template" class="btn btn-sm btn-primary">
      <i class="fa-solid fa-file-csv"></i> Scarica template CSV
    </a>
  </div>
  <div style="font-size:12px;color:var(--muted);padding:6px 0">
    Il template CSV contiene le colonne attese per <strong><?= htmlspecialchars($schema['label']) ?></strong>:
  </div>
  <div style="background:#f8fafc;padding:8px 10px;border-radius:6px;border:1px solid #e2e8f0;font-family:monospace;font-size:11px;overflow-x:auto;white-space:nowrap">
    <?= htmlspecialchars(implode(' ; ', $schema['headers'])) ?>
  </div>
  <div style="font-size:11px;color:var(--muted);margin-top:6px">
    Separatore: <code>;</code> (punto e virgola). Date in formato <code>YYYY-MM-DD</code>. Lasciare vuoto per "nessun dato".
  </div>
</div>

<!-- Step 3: Upload -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-cloud-arrow-up"></i> 3. Carica CSV compilato</span>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
    <div style="display:flex;gap:10px;align-items:center">
      <input type="file" name="csv_file" accept=".csv,.txt" required
             style="flex:1;padding:9px;border:1px dashed var(--border);border-radius:7px;background:#f8fafc">
      <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-eye"></i> Anteprima
      </button>
    </div>
  </form>
</div>

<!-- Preview -->
<?php if ($preview): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-list-check"></i> 4. Anteprima (<?= count($preview) ?> righe)</span>
    <form method="POST" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="confirm_import">
      <button type="submit" class="btn btn-primary" onclick="return confirm('Confermare l\'import di <?= count($preview) ?> dispositivi?')">
        <i class="fa-solid fa-check"></i> Conferma e importa
      </button>
    </form>
  </div>
  <div style="overflow-x:auto;max-height:400px">
    <table class="data-table">
      <thead><tr><th style="width:30px">#</th>
        <?php foreach ($schema['headers'] as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($preview as $i => $row): ?>
        <tr>
          <td style="color:var(--muted);font-size:10px"><?= $i+1 ?></td>
          <?php foreach ($schema['headers'] as $j => $hd): ?>
            <td style="font-size:11px"><?= htmlspecialchars($row[$j] ?? '') ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
