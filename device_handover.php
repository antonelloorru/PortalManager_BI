<?php
/**
 * PortalManager 1.7.11 — device_handover.php
 *
 * Genera modulo consegna/restituzione dispositivi (DOCX scaricabile).
 *
 * Workflow:
 *  1. Seleziona dipendente dal dropdown
 *  2. Scegli tipo: consegna / restituzione / combinato (entrambe sezioni)
 *  3. Spunta i dispositivi da includere (phone, sim, notebook, vehicle, fuel_card)
 *  4. Aggiungi note + date
 *  5. Genera DOCX con dettagli completi dipendente + dispositivi + righe firma
 *
 * RBAC: Super Admin (1), HR Director (2), Team Leader (4)
 */

require_once('access_control.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!in_array($u_role, [1, 2, 4], true)) {
    http_response_code(403);
    die('Accesso negato.');
}

// ─────────────────────────────────────────────────────────────────────
// POST: genera DOCX
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_docx') {
    try {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $type   = $_POST['handover_type'] ?? 'combined';
        if (!in_array($type, ['delivery', 'return', 'combined'], true)) $type = 'combined';

        // Carico anagrafica dipendente completa
        $emp = $pdo->prepare(
            "SELECT e.*, c.name AS company_name, c.vat_number AS company_vat,
                    c.legal_representative AS company_rep,
                    cl.location_name, cl.address AS loc_address
               FROM employees e
               LEFT JOIN companies c ON c.id = e.company_id
               LEFT JOIN company_locations cl ON cl.id = e.location_id
              WHERE e.id = ? LIMIT 1"
        );
        $emp->execute([$emp_id]);
        $emp = $emp->fetch();
        if (!$emp) throw new RuntimeException('Dipendente non trovato.');

        // Carico device selezionati
        $selected = $_POST['devices'] ?? [];
        $devices = [];

        $device_types = [
            'phone'    => ['emp_devices_phone',    'Telefono aziendale', ['brand','model','imei_1','imei_2','serial_number']],
            'sim'      => ['emp_devices_sim',      'SIM aziendale',      ['sim_type','phone_number','operator','iccid','puk1','puk2']],
            'notebook' => ['emp_devices_notebook', 'Notebook',           ['brand','model','serial_number','os','asset_tag']],
            'vehicle'  => ['emp_devices_vehicle',  'Veicolo',            ['brand','model','plate','vin','color']],
            'fuel_card'=> ['emp_devices_fuel_card','Fuel card',          ['card_number','provider','expiry_date']],
        ];

        foreach ($selected as $sel) {
            // $sel = "phone:1" "sim:3" ecc.
            if (!preg_match('/^([a-z_]+):(\d+)$/', $sel, $m)) continue;
            $cat = $m[1]; $id = (int)$m[2];
            if (!isset($device_types[$cat])) continue;
            [$tbl, $label, $fields] = $device_types[$cat];
            try {
                $q = $pdo->prepare("SELECT * FROM $tbl WHERE id=? AND employee_id=?");
                $q->execute([$id, $emp_id]);
                $row = $q->fetch();
                if (!$row) continue;
                $devices[] = ['category' => $cat, 'label' => $label, 'fields' => $fields, 'data' => $row];
            } catch (Throwable $e) {}
        }

        if (empty($devices)) throw new RuntimeException('Selezionare almeno un dispositivo.');

        $delivery_date = $_POST['delivery_date'] ?? date('Y-m-d');
        $return_date   = $_POST['return_date']   ?? '';
        $delivery_notes = trim($_POST['delivery_notes'] ?? '');
        $return_notes   = trim($_POST['return_notes'] ?? '');

        // Log nella tabella device_handovers
        try {
            $device_list_json = json_encode(array_map(fn($d) => [
                'category' => $d['category'],
                'id'       => $d['data']['id'],
                'label'    => $d['label'],
            ], $devices));
            $pdo->prepare(
                "INSERT INTO device_handovers (employee_id, handover_type, delivery_date, return_date,
                    device_list, delivery_notes, return_notes, status, created_by)
                 VALUES (?,?,?,?,?,?,?,'delivered',?)"
            )->execute([
                $emp_id, $type,
                $delivery_date ?: null,
                $return_date ?: null,
                $device_list_json,
                $delivery_notes ?: null,
                $return_notes ?: null,
                $u_id,
            ]);
        } catch (Throwable $e) { /* log fallback */ }

        if (function_exists('write_log')) {
            write_log('DeviceHandover', 'success',
                "Generato modulo $type emp=$emp_id devices=" . count($devices), $u_id);
        }

        // v1.7.11d: carico logo aziendale se presente
        $logo_info = null;
        try {
            $logo_path = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='logo_path' LIMIT 1")->fetchColumn();
            if ($logo_path) {
                $abs = APP_ROOT . '/' . ltrim($logo_path, '/');
                if (is_file($abs)) {
                    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png','jpg','jpeg'], true)) {
                        $logo_info = [
                            'path' => $abs,
                            'ext'  => $ext === 'jpg' ? 'jpeg' : $ext,
                        ];
                    }
                }
            }
        } catch (Throwable $e) {}

        // Genera DOCX
        generate_handover_docx($emp, $type, $devices, $delivery_date, $return_date, $delivery_notes, $return_notes, $logo_info);
        exit;

    } catch (Throwable $e) {
        $err_msg = $e->getMessage();
        // Non blocco la pagina, mostro errore
    }
}

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Dipendenti
$employees = $pdo->query(
    "SELECT id, CONCAT(last_name, ' ', first_name) AS full_name, employee_code, status
       FROM employees
      WHERE status != 'terminated'
      ORDER BY last_name, first_name"
)->fetchAll();

// Pre-selezione dipendente da GET
$pre_emp = (int)($_GET['employee_id'] ?? 0);

// Se dipendente preselezionato, carico i suoi device attivi
$emp_devices = [];
if ($pre_emp) {
    $tables = [
        'phone'    => ['emp_devices_phone',    'Telefono',  'fa-mobile-screen',  ['brand','model','imei_1','serial_number']],
        'sim'      => ['emp_devices_sim',      'SIM',       'fa-sim-card',       ['sim_type','phone_number','operator']],
        'notebook' => ['emp_devices_notebook', 'Notebook',  'fa-laptop',         ['brand','model','serial_number']],
        'vehicle'  => ['emp_devices_vehicle',  'Veicolo',   'fa-car',            ['brand','model','plate']],
        'fuel_card'=> ['emp_devices_fuel_card','Fuel card', 'fa-gas-pump',       ['card_number','provider','expiry_date']],
    ];
    foreach ($tables as $cat => [$tbl, $label, $icon, $key_fields]) {
        try {
            $q = $pdo->prepare("SELECT * FROM $tbl WHERE employee_id=? ORDER BY id DESC");
            $q->execute([$pre_emp]);
            foreach ($q->fetchAll() as $row) {
                $emp_devices[] = [
                    'cat' => $cat, 'label' => $label, 'icon' => $icon,
                    'id' => $row['id'], 'data' => $row, 'key_fields' => $key_fields,
                    'returned' => !empty($row['returned_at']),
                ];
            }
        } catch (Throwable $e) {}
    }
}
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0">
      <i class="fa-solid fa-file-signature" style="color:#dc2626"></i> Modulo consegna / restituzione dispositivi
    </h1>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">
      Genera un modulo DOCX firmabile con dati completi dipendente e dettagli dispositivi.
    </div>
  </div>
  <a href="<?= function_exists('url_safe') ? url_safe('device_manager') : 'device_manager.php' ?>" class="btn btn-sm">
    <i class="fa-solid fa-arrow-left"></i> Gestione dispositivi
  </a>
</div>

<?php if (!empty($err_msg)): ?>
<div class="alert alert-danger" style="margin-bottom:16px">
  <i class="fa-solid fa-triangle-exclamation"></i> <?= $h($err_msg) ?>
</div>
<?php endif; ?>

<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="generate_docx">

  <!-- DIPENDENTE -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-user" style="color:#0ea5e9"></i> Dipendente</span>
    </div>
    <select name="employee_id" id="empSelect" required
            onchange="changeEmployee(this.value)"
            style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px">
      <option value="">— Seleziona dipendente —</option>
      <?php foreach ($employees as $e): ?>
      <option value="<?= $e['id'] ?>" <?= $pre_emp === (int)$e['id'] ? 'selected' : '' ?>>
        <?= $h($e['full_name']) ?><?= $e['employee_code'] ? ' · ' . $h($e['employee_code']) : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
    <script>
      // v1.7.11b: usa qs_self_safe per preservare il param router opaco r=...
      function changeEmployee(empId) {
        if (!empId) return;
        // Costruisco URL preservando i query params esistenti (es. r=slug)
        const url = new URL(window.location.href);
        url.searchParams.set('employee_id', empId);
        // Rimuovo eventuali altri parametri specifici di questa pagina che non vogliamo
        url.searchParams.delete('msg');
        window.location.href = url.toString();
      }
    </script>
  </div>

  <?php if ($pre_emp && !empty($emp_devices)): ?>

  <!-- TIPO MODULO -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-file-lines" style="color:#7c3aed"></i> Tipo modulo</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px">
      <label style="cursor:pointer">
        <input type="radio" name="handover_type" value="delivery" style="display:none">
        <div class="tgt-card" data-tgt="delivery" style="border:2px solid var(--border);background:#fafbfc;border-radius:10px;padding:12px;transition:.15s;height:100%">
          <i class="fa-solid fa-arrow-down" style="font-size:22px;color:#16a34a"></i>
          <div style="font-weight:700;margin-top:6px;font-size:13px">Solo consegna</div>
          <div style="font-size:10px;color:var(--muted);margin-top:3px">Modulo di sola consegna con firma ricevimento</div>
        </div>
      </label>
      <label style="cursor:pointer">
        <input type="radio" name="handover_type" value="return" style="display:none">
        <div class="tgt-card" data-tgt="return" style="border:2px solid var(--border);background:#fafbfc;border-radius:10px;padding:12px;transition:.15s;height:100%">
          <i class="fa-solid fa-arrow-up" style="font-size:22px;color:#dc2626"></i>
          <div style="font-weight:700;margin-top:6px;font-size:13px">Solo restituzione</div>
          <div style="font-size:10px;color:var(--muted);margin-top:3px">Modulo di sola restituzione con firma scarico</div>
        </div>
      </label>
      <label style="cursor:pointer">
        <input type="radio" name="handover_type" value="combined" checked style="display:none">
        <div class="tgt-card" data-tgt="combined" style="border:2px solid #7c3aed;background:#ede9fe;border-radius:10px;padding:12px;transition:.15s;height:100%">
          <i class="fa-solid fa-arrows-up-down" style="font-size:22px;color:#7c3aed"></i>
          <div style="font-weight:700;margin-top:6px;font-size:13px;color:#5b21b6">Combinato</div>
          <div style="font-size:10px;color:var(--muted);margin-top:3px">Consegna + restituzione (firma in 2 momenti)</div>
        </div>
      </label>
    </div>
  </div>

  <!-- DEVICE SELECTOR -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-laptop" style="color:#dc2626"></i> Dispositivi da includere (<?= count($emp_devices) ?> disponibili)</span>
      <button type="button" onclick="document.querySelectorAll('.dev-cb').forEach(c=>c.checked=true)"
              class="btn btn-sm" style="padding:3px 8px">Seleziona tutti</button>
    </div>

    <?php foreach ($emp_devices as $d): ?>
    <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:<?= $d['returned'] ? '#fef3c7' : '#fafbfc' ?>;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;cursor:pointer">
      <input type="checkbox" class="dev-cb" name="devices[]" value="<?= $d['cat'] ?>:<?= $d['id'] ?>"
             <?= $d['returned'] ? '' : 'checked' ?>
             style="margin-top:4px">
      <div style="flex:1">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
          <i class="fa-solid <?= $h($d['icon']) ?>" style="color:var(--p)"></i>
          <strong style="font-size:12px"><?= $h($d['label']) ?></strong>
          <?php if ($d['returned']): ?>
          <span style="background:#fbbf24;color:#7c2d12;padding:2px 7px;border-radius:8px;font-size:9px;font-weight:800">GIÀ RESTITUITO</span>
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--muted)">
          <?php
          $bits = [];
          foreach ($d['key_fields'] as $f) {
              if (!empty($d['data'][$f])) $bits[] = '<strong>' . $h($f) . '</strong>: ' . $h($d['data'][$f]);
          }
          echo implode(' · ', $bits);
          ?>
        </div>
      </div>
    </label>
    <?php endforeach; ?>
  </div>

  <!-- DATE + NOTE -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-pen-to-square" style="color:#0ea5e9"></i> Date e note</span>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
      <div>
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Data consegna</label>
        <input type="date" name="delivery_date" value="<?= date('Y-m-d') ?>"
               style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Data restituzione prevista (opzionale)</label>
        <input type="date" name="return_date"
               style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;box-sizing:border-box">
      </div>
    </div>
    <div style="margin-bottom:10px">
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Note consegna</label>
      <textarea name="delivery_notes" rows="2" placeholder="Eventuali accessori, condizioni, accordi particolari..."
                style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;box-sizing:border-box;resize:vertical;font-family:inherit"></textarea>
    </div>
    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Note restituzione</label>
      <textarea name="return_notes" rows="2" placeholder="Da compilare al momento della restituzione (stato, danni, integrità)..."
                style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;box-sizing:border-box;resize:vertical;font-family:inherit"></textarea>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;gap:10px">
    <a href="<?= function_exists('url_safe') ? url_safe('manage_employees') : 'manage_employees.php' ?>" class="btn btn-sm">Annulla</a>
    <button type="submit" class="btn btn-primary" style="background:#16a34a;border:0;padding:11px 24px;font-weight:700">
      <i class="fa-solid fa-file-arrow-down"></i> Genera modulo DOCX
    </button>
  </div>

  <?php elseif ($pre_emp): ?>
  <div class="alert" style="background:#fef3c7;color:#92400e;padding:14px 18px;border-radius:8px">
    <i class="fa-solid fa-info-circle"></i> Questo dipendente non ha dispositivi registrati.
    <a href="<?= function_exists('url_safe') ? url_safe('employee_profile', ['id'=>$pre_emp, 'tab'=>'dispositivi']) : 'employee_profile.php?id='.$pre_emp.'&tab=dispositivi' ?>"
       style="color:#92400e;text-decoration:underline;font-weight:700;margin-left:8px">
      Assegna prima un dispositivo →
    </a>
  </div>
  <?php endif; ?>
</form>

<script>
// Highlight card tipo
document.querySelectorAll('input[name="handover_type"]').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.tgt-card').forEach(c => {
      c.style.borderColor = 'var(--border)';
      c.style.background = '#fafbfc';
    });
    if (radio.checked) {
      const card = radio.parentElement.querySelector('.tgt-card');
      const colors = { delivery: ['#16a34a','#dcfce7'], return: ['#dc2626','#fee2e2'], combined: ['#7c3aed','#ede9fe'] };
      const c = colors[radio.value] || colors.combined;
      card.style.borderColor = c[0];
      card.style.background = c[1];
    }
  });
});
</script>

<?php require_once('footer.php'); ?>

<?php
// ════════════════════════════════════════════════════════════════════════
// GENERATORE DOCX
// ════════════════════════════════════════════════════════════════════════
function generate_handover_docx(array $emp, string $type, array $devices, string $delivery_date, string $return_date, string $delivery_notes, string $return_notes, ?array $logo_info = null): void {
    $w = '';

    // ── Logo in testata (se presente) ──
    if ($logo_info) {
        // Tabella 2 colonne: logo a sinistra, ragione sociale + dati a destra
        $logo_run = hd_picture_run('rId100', 1800000, 600000); // ~5cm x 1.6cm
        $right_runs = hd_run(($emp['company_name'] ?? 'Azienda'), ['bold' => true, 'size' => 24, 'color' => '1e3a8a']);
        if (!empty($emp['company_vat'])) {
            $right_runs .= '<w:br/>' . hd_run('P.IVA ' . $emp['company_vat'], ['size' => 16, 'color' => '64748b']);
        }
        if (!empty($emp['loc_address'])) {
            $right_runs .= '<w:br/>' . hd_run($emp['loc_address'], ['size' => 16, 'color' => '64748b']);
        }
        $w .= '<w:tbl>'
            . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/>'
            . '<w:tblBorders>'
            . '<w:bottom w:val="single" w:sz="12" w:color="1e3a8a"/>'
            . '</w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="6000"/></w:tblGrid>'
            . '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'
            . hd_paragraph($logo_run, ['align' => 'left'])
            . '</w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="6000" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>'
            . hd_paragraph($right_runs, ['align' => 'right'])
            . '</w:tc>'
            . '</w:tr></w:tbl>';
        $w .= hd_empty();
    }

    // ── Titolo modulo ──
    $title = $type === 'delivery' ? 'MODULO DI CONSEGNA DISPOSITIVI AZIENDALI'
           : ($type === 'return'  ? 'MODULO DI RESTITUZIONE DISPOSITIVI AZIENDALI'
                                  : 'MODULO DI CONSEGNA E RESTITUZIONE DISPOSITIVI AZIENDALI');

    $w .= hd_paragraph(hd_run($title, ['size' => 28, 'bold' => true, 'color' => 'FFFFFF']),
                       ['shading' => '1e3a8a', 'align' => 'center', 'spacing' => 240]);
    $w .= hd_empty();

    // Riferimento + data
    $w .= hd_paragraph(
        hd_run('Riferimento: ', ['bold' => true, 'size' => 18]) .
        hd_run('HND-' . date('Ymd') . '-' . str_pad((string)$emp['id'], 4, '0', STR_PAD_LEFT), ['size' => 18]) .
        hd_run('     Data: ', ['bold' => true, 'size' => 18]) .
        hd_run(date('d/m/Y'), ['size' => 18])
    );
    $w .= hd_empty();

    // ── SEZIONE: DATI AZIENDA ──
    $w .= hd_section_header('1. Datore di lavoro (Azienda)');
    $rows = [];
    if (!empty($emp['company_name']))   $rows[] = ['Ragione sociale', $emp['company_name']];
    if (!empty($emp['company_vat']))    $rows[] = ['P.IVA / C.F.', $emp['company_vat']];
    if (!empty($emp['company_rep']))    $rows[] = ['Legale rappresentante', $emp['company_rep']];
    if (!empty($emp['location_name']))  $rows[] = ['Filiale operativa', $emp['location_name']];
    if (!empty($emp['loc_address']))    $rows[] = ['Sede', $emp['loc_address']];
    $w .= hd_kv_table($rows);
    $w .= hd_empty();

    // ── SEZIONE: DATI DIPENDENTE ──
    $w .= hd_section_header('2. Dipendente (consegnatario)');
    $rows = [];
    $rows[] = ['Cognome e nome', strtoupper(($emp['last_name'] ?? '') . ' ' . ($emp['first_name'] ?? ''))];
    if (!empty($emp['fiscal_code']))    $rows[] = ['Codice fiscale', $emp['fiscal_code']];
    if (!empty($emp['date_of_birth']))  $rows[] = ['Data di nascita', date('d/m/Y', strtotime($emp['date_of_birth']))];
    if (!empty($emp['employee_code']))  $rows[] = ['Matricola', $emp['employee_code']];
    if (!empty($emp['job_title']))      $rows[] = ['Qualifica', $emp['job_title']];
    if (!empty($emp['department']))     $rows[] = ['Dipartimento', $emp['department']];
    if (!empty($emp['contract_type']))  $rows[] = ['Tipo contratto', $emp['contract_type']];
    if (!empty($emp['hire_date']))      $rows[] = ['Data assunzione', date('d/m/Y', strtotime($emp['hire_date']))];
    if (!empty($emp['business_email'])) $rows[] = ['Email aziendale', $emp['business_email']];
    if (!empty($emp['phone']))          $rows[] = ['Telefono', $emp['phone']];
    $w .= hd_kv_table($rows);
    $w .= hd_empty();

    // ── SEZIONE: DISPOSITIVI ──
    $sec_num = 3;
    $w .= hd_section_header($sec_num . '. Beni aziendali oggetto del modulo (' . count($devices) . ')');

    foreach ($devices as $idx => $d) {
        $w .= hd_paragraph(
            hd_run('▸ ' . ($idx + 1) . '. ' . $d['label'], ['bold' => true, 'size' => 22, 'color' => '1e3a8a']),
            ['spacing' => 180]
        );

        $rows = [];
        // Tutti i campi non vuoti del device
        foreach ($d['data'] as $k => $v) {
            if (in_array($k, ['id','employee_id','created_at','created_by','updated_at'], true)) continue;
            if ($v === null || $v === '' || $v === '0000-00-00') continue;
            $label = ucfirst(str_replace('_', ' ', $k));
            $value = $v;
            if (in_array($k, ['assigned_at','returned_at','expiry_date','start_date','end_date'], true)) {
                $ts = strtotime((string)$v);
                if ($ts) $value = date('d/m/Y', $ts);
            }
            $rows[] = [$label, (string)$value];
        }
        $w .= hd_kv_table($rows);
        $w .= hd_empty();
    }

    // ── SEZIONE: CONSEGNA ──
    if (in_array($type, ['delivery', 'combined'], true)) {
        $sec_num++;
        $w .= hd_section_header($sec_num . '. Dichiarazione di CONSEGNA');
        $w .= hd_paragraph(hd_run(
            "Il sottoscritto datore di lavoro consegna in data " . ($delivery_date ? date('d/m/Y', strtotime($delivery_date)) : '_____________') .
            " al dipendente sopra identificato i beni aziendali elencati al punto 3, " .
            "in stato di perfetta efficienza e completi degli accessori d'uso. " .
            "Il dipendente prende in carico tali beni e si impegna a utilizzarli esclusivamente " .
            "per fini di servizio, a custodirli con la diligenza del buon padre di famiglia e " .
            "a restituirli all'azienda alla cessazione del rapporto di lavoro o su richiesta.",
            ['size' => 20]
        ), ['align' => 'both']);

        if (!empty($delivery_notes)) {
            $w .= hd_empty();
            $w .= hd_paragraph(hd_run('Note: ', ['bold' => true, 'size' => 18]) . hd_run($delivery_notes, ['size' => 18, 'italic' => true]));
        }
        $w .= hd_empty();

        // Firme consegna
        $w .= hd_signatures_table([
            'Luogo, data', $delivery_date ? date('d/m/Y', strtotime($delivery_date)) : '____________________',
            'Firma del datore di lavoro', '________________________',
            'Firma del dipendente (per ricevuta)', '________________________',
        ]);
        $w .= hd_empty();
    }

    // ── SEZIONE: RESTITUZIONE ──
    if (in_array($type, ['return', 'combined'], true)) {
        $sec_num++;
        $w .= hd_section_header($sec_num . '. Dichiarazione di RESTITUZIONE');
        $w .= hd_paragraph(hd_run(
            "In data " . ($return_date ? date('d/m/Y', strtotime($return_date)) : '_____________') .
            " il dipendente restituisce all'azienda i beni aziendali elencati al punto 3 " .
            "nello stato indicato nelle note di restituzione. Il datore di lavoro, ricevuti i beni, " .
            "ne accetta la riconsegna e libera il dipendente da ogni vincolo di custodia.",
            ['size' => 20]
        ), ['align' => 'both']);

        $w .= hd_empty();
        $w .= hd_paragraph(hd_run('Stato dei beni al momento della restituzione: ', ['bold' => true, 'size' => 18]) .
                          hd_run($return_notes ?: '__________________________________________________________________', ['size' => 18, 'italic' => true]));
        $w .= hd_empty();

        // Firme restituzione
        $w .= hd_signatures_table([
            'Luogo, data', $return_date ? date('d/m/Y', strtotime($return_date)) : '____________________',
            'Firma del dipendente', '________________________',
            'Firma del datore di lavoro (per ricevuta)', '________________________',
        ]);
    }

    // Footer
    $w .= hd_empty();
    $w .= hd_paragraph(hd_run(
        'Documento generato da PortalManager il ' . date('d/m/Y H:i') .
        ' · Riferimento HND-' . date('Ymd') . '-' . str_pad((string)$emp['id'], 4, '0', STR_PAD_LEFT),
        ['size' => 14, 'italic' => true, 'color' => '999999']
    ), ['align' => 'right']);

    // Assemblo document.xml
    $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<w:body>' . $w
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/>'
        . '</w:sectPr></w:body></w:document>';

    $tmpfile = tempnam(sys_get_temp_dir(), 'hnd_');
    $zip = new ZipArchive();
    $zip->open($tmpfile, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/>'
        . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
        . '<Default Extension="jpg" ContentType="image/jpeg"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>');

    // Relationship per logo (se presente)
    $rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    if ($logo_info) {
        $rels_xml .= '<Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.' . $logo_info['ext'] . '"/>';
    }
    $rels_xml .= '</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $rels_xml);

    $zip->addFromString('word/document.xml', $document_xml);

    if ($logo_info) {
        $zip->addFile($logo_info['path'], 'word/media/logo.' . $logo_info['ext']);
    }

    $zip->close();

    $type_label = ['delivery'=>'Consegna','return'=>'Restituzione','combined'=>'ConsegnaRestituzione'][$type];
    $filename = sprintf('Modulo_%s_%s_%s_%s.docx',
        $type_label,
        preg_replace('/[^A-Za-z0-9]/', '_', $emp['last_name'] ?? 'dip'),
        preg_replace('/[^A-Za-z0-9]/', '_', $emp['first_name'] ?? ''),
        date('Ymd')
    );

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpfile));
    readfile($tmpfile);
    @unlink($tmpfile);
}

// ── Helper OOXML semplificati ──
function hd_picture_run(string $rid, int $cx = 1800000, int $cy = 600000): string {
    // cx/cy in EMU (914400 EMU = 1 inch). 1800000 ≈ 5cm, 600000 ≈ 1.6cm
    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"'
         . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
         . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
         . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
         . '<wp:docPr id="1" name="Logo"/>'
         . '<wp:cNvGraphicFramePr/>'
         . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
         . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
         . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
         . '<pic:nvPicPr><pic:cNvPr id="1" name="logo"/><pic:cNvPicPr/></pic:nvPicPr>'
         . '<pic:blipFill>'
         . '<a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $rid . '"/>'
         . '<a:stretch><a:fillRect/></a:stretch>'
         . '</pic:blipFill>'
         . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
         . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
         . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

function hd_run(string $text, array $opts = []): string {
    $rpr = '';
    if (!empty($opts['bold']))   $rpr .= '<w:b/>';
    if (!empty($opts['italic'])) $rpr .= '<w:i/>';
    if (!empty($opts['size']))   $rpr .= '<w:sz w:val="' . (int)$opts['size'] . '"/>';
    if (!empty($opts['color']))  $rpr .= '<w:color w:val="' . htmlspecialchars($opts['color']) . '"/>';
    if ($rpr) $rpr = '<w:rPr>' . $rpr . '</w:rPr>';
    $text_xml = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<w:r>' . $rpr . '<w:t xml:space="preserve">' . $text_xml . '</w:t></w:r>';
}

function hd_paragraph(string $runs, array $opts = []): string {
    $ppr = '';
    if (!empty($opts['align']))    $ppr .= '<w:jc w:val="' . htmlspecialchars($opts['align']) . '"/>';
    if (!empty($opts['shading']))  $ppr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . htmlspecialchars($opts['shading']) . '"/>';
    if (isset($opts['spacing']))   $ppr .= '<w:spacing w:before="' . (int)$opts['spacing'] . '" w:after="60"/>';
    if ($ppr) $ppr = '<w:pPr>' . $ppr . '</w:pPr>';
    return '<w:p>' . $ppr . $runs . '</w:p>';
}

function hd_empty(): string { return '<w:p/>'; }

function hd_section_header(string $title): string {
    return hd_paragraph(hd_run($title, ['size' => 24, 'bold' => true, 'color' => '1e3a8a']),
                        ['shading' => 'dbeafe', 'spacing' => 180]);
}

function hd_kv_table(array $rows): string {
    if (empty($rows)) return '';
    $xml = '<w:tbl>'
         . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/>'
         . '<w:tblBorders>'
         . '<w:top w:val="single" w:sz="4" w:color="d4d4d8"/>'
         . '<w:left w:val="single" w:sz="4" w:color="d4d4d8"/>'
         . '<w:bottom w:val="single" w:sz="4" w:color="d4d4d8"/>'
         . '<w:right w:val="single" w:sz="4" w:color="d4d4d8"/>'
         . '<w:insideH w:val="single" w:sz="4" w:color="d4d4d8"/>'
         . '<w:insideV w:val="single" w:sz="4" w:color="d4d4d8"/>'
         . '</w:tblBorders></w:tblPr>'
         . '<w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="6000"/></w:tblGrid>';
    foreach ($rows as $r) {
        $xml .= '<w:tr>';
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="f1f5f9"/></w:tcPr>'
              . hd_paragraph(hd_run($r[0], ['bold' => true, 'size' => 18, 'color' => '1e293b']))
              . '</w:tc>';
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="6000" w:type="dxa"/></w:tcPr>'
              . hd_paragraph(hd_run($r[1], ['size' => 18]))
              . '</w:tc>';
        $xml .= '</w:tr>';
    }
    $xml .= '</w:tbl>';
    return $xml;
}

function hd_signatures_table(array $cells): string {
    // $cells = [label1, val1, label2, val2, ...]
    $xml = '<w:tbl>'
         . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/>'
         . '<w:tblBorders>'
         . '<w:bottom w:val="single" w:sz="4" w:color="000000"/>'
         . '<w:insideH w:val="single" w:sz="4" w:color="d4d4d8"/>'
         . '</w:tblBorders></w:tblPr>'
         . '<w:tblGrid><w:gridCol w:w="4500"/><w:gridCol w:w="4500"/></w:tblGrid>';

    for ($i = 0; $i < count($cells); $i += 2) {
        $xml .= '<w:tr>';
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="4500" w:type="dxa"/></w:tcPr>'
              . hd_paragraph(hd_run($cells[$i], ['bold' => true, 'size' => 18]))
              . '</w:tc>';
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="4500" w:type="dxa"/></w:tcPr>'
              . hd_paragraph(hd_run($cells[$i+1] ?? '________________', ['size' => 18]))
              . '</w:tc>';
        $xml .= '</w:tr>';
    }
    $xml .= '</w:tbl>';
    return $xml;
}
?>
