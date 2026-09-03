<?php
/**
 * PortalManager 1.6.1 — device_manager.php
 *
 * Sezione dedicata alla gestione globale dei dispositivi aziendali.
 *
 * Funzionalità:
 *  - Vista riepilogativa per categoria (tabs: Telefoni, SIM, Notebook, Veicoli, Carte carburante, Carte credito)
 *  - Filtri: stato, categoria, dipendente, azienda/sede, periodo consegna/ritiro
 *  - Storico movimenti (consegne, ritiri, cambi stato) timeline
 *  - Export XLS / CSV / Stampa dei risultati filtrati
 *  - Accesso solo Admin/HR (RBAC role 1, 2)
 *
 * URL: device_manager.php[?cat=phone|sim|notebook|vehicle|fuel_card|credit_card|history]
 */

require_once('access_control.php');

$u_role = (int)($_SESSION['role_id'] ?? 99);
$u_id   = (int)($_SESSION['user_id'] ?? 0);
$can_edit = in_array($u_role, [1, 2], true);
$can_view = $can_edit || in_array($u_role, [4], true);
if (!$can_view) { http_response_code(403); die('Accesso negato'); }

// Tab attiva
$cat = $_GET['cat'] ?? 'phone';
$valid_cats = ['phone','sim','notebook','vehicle','fuel_card','credit_card','history'];
if (!in_array($cat, $valid_cats, true)) $cat = 'phone';

// ─────────────────────────────────────────────────────────────────────
// Definizione categorie e colonne visualizzate
// ─────────────────────────────────────────────────────────────────────
$categories = [
    'phone' => [
        'table' => 'emp_devices_phone',
        'label' => 'Telefoni aziendali',
        'icon'  => 'fa-mobile-screen',
        'color' => '#0ea5e9',
        'cols'  => ['brand'=>'Marca', 'model'=>'Modello', 'imei_1'=>'IMEI 1', 'serial_number'=>'S/N'],
        'status_enum' => ['assegnato','restituito','smarrito','rotto'],
    ],
    'sim' => [
        'table' => 'emp_devices_sim',
        'label' => 'SIM aziendali',
        'icon'  => 'fa-sim-card',
        'color' => '#10b981',
        'cols'  => ['sim_type'=>'Tipo', 'phone_number'=>'Numero', 'operator'=>'Operatore', 'serial_number'=>'ICCID'],
        'status_enum' => ['attiva','disattiva','smarrita','sostituita'],
    ],
    'notebook' => [
        'table' => 'emp_devices_notebook',
        'label' => 'Notebook aziendali',
        'icon'  => 'fa-laptop',
        'color' => '#6366f1',
        'cols'  => ['brand'=>'Marca', 'model'=>'Modello', 'os'=>'SO', 'serial_number'=>'S/N'],
        'status_enum' => ['assegnato','restituito','smarrito','rotto','in_riparazione'],
    ],
    'vehicle' => [
        'table' => 'emp_devices_vehicle',
        'label' => 'Veicoli aziendali',
        'icon'  => 'fa-car',
        'color' => '#dc2626',
        'cols'  => ['brand'=>'Marca', 'model'=>'Modello', 'plate'=>'Targa', 'acquisition_type'=>'Acquisizione'],
        'status_enum' => ['assegnato','restituito','incidente','rotto'],
    ],
    'fuel_card' => [
        'table' => 'emp_devices_fuel_card',
        'label' => 'Carte carburante',
        'icon'  => 'fa-gas-pump',
        'color' => '#f59e0b',
        'cols'  => ['circuit'=>'Circuito', 'card_number'=>'Numero carta'],
        'status_enum' => ['attiva','disattiva','smarrita','bloccata'],
    ],
    'credit_card' => [
        'table' => 'emp_devices_credit_card',
        'label' => 'Carte credito',
        'icon'  => 'fa-credit-card',
        'color' => '#8b5cf6',
        'cols'  => ['circuit'=>'Circuito', 'bank'=>'Banca', 'card_number_last4'=>'Ultime 4'],
        'status_enum' => ['attiva','disattiva','smarrita','bloccata'],
    ],
];

// ─────────────────────────────────────────────────────────────────────
// Lookups per filtri (popolano dropdown)
// ─────────────────────────────────────────────────────────────────────
$employees = $pdo->query(
    "SELECT e.id, CONCAT(e.last_name, ' ', e.first_name) AS full_name
       FROM employees e
      WHERE e.status != 'terminated'
      ORDER BY e.last_name, e.first_name"
)->fetchAll();

$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$locations = $pdo->query("SELECT id, location_name, company_id FROM company_locations ORDER BY location_name")->fetchAll();

// ─────────────────────────────────────────────────────────────────────
// Filtri da $_GET
// ─────────────────────────────────────────────────────────────────────
$f_status   = $_GET['f_status']   ?? '';
$f_employee = (int)($_GET['f_employee'] ?? 0);
$f_company  = (int)($_GET['f_company']  ?? 0);
$f_location = (int)($_GET['f_location'] ?? 0);
$f_from     = $_GET['f_from'] ?? '';
$f_to       = $_GET['f_to']   ?? '';
$f_field    = $_GET['f_field'] ?? 'assigned_at'; // o returned_at
if (!in_array($f_field, ['assigned_at','returned_at'], true)) $f_field = 'assigned_at';

$export = $_GET['export'] ?? '';

// ─────────────────────────────────────────────────────────────────────
// Tab "Storico" / "Movimenti": vista unificata cross-tabella
// ─────────────────────────────────────────────────────────────────────
function build_history_query(PDO $pdo, array $filters): array {
    $employee_id = $filters['employee_id'] ?? 0;
    $company_id  = $filters['company_id']  ?? 0;
    $location_id = $filters['location_id'] ?? 0;
    $from        = $filters['from'] ?? '';
    $to          = $filters['to']   ?? '';
    $field       = $filters['field'] ?? 'assigned_at';

    // UNION di tutte le tabelle device
    $unions = [];
    $tables = [
        'phone'       => ['emp_devices_phone',       'Telefono',    "CONCAT(COALESCE(brand,''),' ',COALESCE(model,''))"],
        'sim'         => ['emp_devices_sim',         'SIM',         "CONCAT(sim_type,' - ',COALESCE(phone_number,''))"],
        'notebook'    => ['emp_devices_notebook',    'Notebook',    "CONCAT(COALESCE(brand,''),' ',COALESCE(model,''))"],
        'vehicle'     => ['emp_devices_vehicle',     'Veicolo',     "CONCAT(COALESCE(brand,''),' ',COALESCE(model,''),' (',COALESCE(plate,''),')')"],
        'fuel_card'   => ['emp_devices_fuel_card',   'Carta carburante', "CONCAT(COALESCE(circuit,''),' ',COALESCE(card_number,''))"],
        'credit_card' => ['emp_devices_credit_card', 'Carta credito',    "CONCAT(COALESCE(circuit,''),' ●●●●',COALESCE(card_number_last4,''))"],
    ];
    foreach ($tables as $cat => [$tbl, $cat_label, $desc_expr]) {
        $unions[] = "SELECT id AS rec_id, '$cat' AS category, '$cat_label' AS category_label,
                            $desc_expr AS description,
                            employee_id, status,
                            assigned_at, returned_at, created_at, created_by
                       FROM $tbl";
    }
    $base = "(" . implode(" UNION ALL ", $unions) . ") AS dev";

    $wheres = ['1=1'];
    $params = [];
    if ($employee_id > 0) {
        $wheres[] = 'dev.employee_id = ?';
        $params[] = $employee_id;
    }
    if ($company_id > 0) {
        $wheres[] = 'e.company_id = ?';
        $params[] = $company_id;
    }
    if ($location_id > 0) {
        $wheres[] = 'e.location_id = ?';
        $params[] = $location_id;
    }
    if ($from) {
        $wheres[] = "dev.$field >= ?";
        $params[] = $from;
    }
    if ($to) {
        $wheres[] = "dev.$field <= ?";
        $params[] = $to;
    }

    $sql = "SELECT dev.*,
                   CONCAT(e.last_name, ' ', e.first_name) AS employee_name,
                   e.employee_code, co.name AS company_name, loc.location_name
              FROM $base
              LEFT JOIN employees e ON e.id = dev.employee_id
              LEFT JOIN companies co ON co.id = e.company_id
              LEFT JOIN company_locations loc ON loc.id = e.location_id
             WHERE " . implode(' AND ', $wheres) . "
             ORDER BY COALESCE(dev.returned_at, dev.assigned_at, dev.created_at) DESC
             LIMIT 500";
    return [$sql, $params];
}

// ─────────────────────────────────────────────────────────────────────
// Query per categoria specifica
// ─────────────────────────────────────────────────────────────────────
function build_category_query(string $cat, array $catdef, array $filters): array {
    $tbl = $catdef['table'];
    $wheres = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        $wheres[] = 'd.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['employee_id'])) {
        $wheres[] = 'd.employee_id = ?';
        $params[] = $filters['employee_id'];
    }
    if (!empty($filters['company_id'])) {
        $wheres[] = 'e.company_id = ?';
        $params[] = $filters['company_id'];
    }
    if (!empty($filters['location_id'])) {
        $wheres[] = 'e.location_id = ?';
        $params[] = $filters['location_id'];
    }
    if (!empty($filters['from'])) {
        $wheres[] = "d.{$filters['field']} >= ?";
        $params[] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $wheres[] = "d.{$filters['field']} <= ?";
        $params[] = $filters['to'];
    }

    $sql = "SELECT d.*,
                   CONCAT(e.last_name, ' ', e.first_name) AS employee_name,
                   e.employee_code, co.name AS company_name, loc.location_name
              FROM $tbl d
              LEFT JOIN employees e ON e.id = d.employee_id
              LEFT JOIN companies co ON co.id = e.company_id
              LEFT JOIN company_locations loc ON loc.id = e.location_id
             WHERE " . implode(' AND ', $wheres) . "
             ORDER BY (d.returned_at IS NULL) DESC, d.assigned_at DESC, d.id DESC";
    return [$sql, $params];
}

// ─────────────────────────────────────────────────────────────────────
// Esecuzione query
// ─────────────────────────────────────────────────────────────────────
$filters = [
    'status'      => $f_status,
    'employee_id' => $f_employee,
    'company_id'  => $f_company,
    'location_id' => $f_location,
    'from'        => $f_from,
    'to'          => $f_to,
    'field'       => $f_field,
];

$rows = [];
if ($cat === 'history') {
    [$sql, $params] = build_history_query($pdo, $filters);
} else {
    [$sql, $params] = build_category_query($cat, $categories[$cat], $filters);
}
try {
    $stm = $pdo->prepare($sql);
    $stm->execute($params);
    $rows = $stm->fetchAll();
} catch (Throwable $e) {
    $rows = [];
    $query_error = $e->getMessage();
}

// ─────────────────────────────────────────────────────────────────────
// EXPORT (xls / csv)
// ─────────────────────────────────────────────────────────────────────
if ($export === 'xls' || $export === 'csv') {
    if ($cat === 'history') {
        $headers = ['Data','Categoria','Descrizione','Dipendente','Matricola','Azienda','Sede','Stato','Consegna','Ritiro'];
        $get_row = function ($r) {
            return [
                date('Y-m-d H:i', strtotime($r['created_at'] ?? 'now')),
                $r['category_label'] ?? '',
                trim($r['description'] ?? ''),
                $r['employee_name'] ?? '',
                $r['employee_code'] ?? '',
                $r['company_name'] ?? '',
                $r['location_name'] ?? '',
                $r['status'] ?? '',
                $r['assigned_at'] ?? '',
                $r['returned_at'] ?? '',
            ];
        };
        $sheet_name = 'Storico_movimenti';
    } else {
        $catdef = $categories[$cat];
        $headers = ['ID','Dipendente','Matricola','Azienda','Sede'];
        foreach ($catdef['cols'] as $k => $label) $headers[] = $label;
        $headers = array_merge($headers, ['Stato','Consegna','Ritiro','Note']);

        $get_row = function ($r) use ($catdef) {
            $row = [
                $r['id'],
                $r['employee_name'] ?? '',
                $r['employee_code'] ?? '',
                $r['company_name'] ?? '',
                $r['location_name'] ?? '',
            ];
            foreach ($catdef['cols'] as $k => $label) {
                $row[] = $r[$k] ?? '';
            }
            $row[] = $r['status'] ?? '';
            $row[] = $r['assigned_at'] ?? '';
            $row[] = $r['returned_at'] ?? '';
            $row[] = $r['notes'] ?? '';
            return $row;
        };
        $sheet_name = $catdef['label'];
    }

    $sheet_name_clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $sheet_name);
    $filename = "Dispositivi_{$sheet_name_clean}_" . date('Ymd_His');

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
        echo "\xEF\xBB\xBF"; // BOM UTF-8
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers, ';');
        foreach ($rows as $r) fputcsv($out, $get_row($r), ';');
        fclose($out);
        exit;
    }

    // XLS (SpreadsheetML 2003)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.xls\"");
    $xml_escape = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    echo '<Styles>'
       . '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1e40af" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>'
       . '<Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14" ss:Color="#1e3a8a"/></Style>'
       . '</Styles>' . "\n";
    echo '<Worksheet ss:Name="' . $xml_escape(mb_substr($sheet_name, 0, 31)) . '"><Table>' . "\n";
    echo '<Row><Cell ss:MergeAcross="' . (count($headers) - 1) . '" ss:StyleID="Title"><Data ss:Type="String">' . $xml_escape($sheet_name . ' · Esportato il ' . date('d/m/Y H:i') . ' · ' . count($rows) . ' record') . '</Data></Cell></Row>' . "\n";
    echo '<Row></Row>' . "\n";
    echo '<Row>';
    foreach ($headers as $h) echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . $xml_escape($h) . '</Data></Cell>';
    echo '</Row>' . "\n";
    foreach ($rows as $r) {
        echo '<Row>';
        foreach ($get_row($r) as $val) {
            if ($val === '' || $val === null) {
                echo '<Cell><Data ss:Type="String"></Data></Cell>';
            } elseif (is_numeric($val) && !preg_match('/^0\d|^\+/', (string)$val)) {
                echo '<Cell><Data ss:Type="Number">' . $val . '</Data></Cell>';
            } else {
                echo '<Cell><Data ss:Type="String">' . $xml_escape($val) . '</Data></Cell>';
            }
        }
        echo '</Row>' . "\n";
    }
    echo '</Table></Worksheet></Workbook>' . "\n";
    exit;
}

// ─────────────────────────────────────────────────────────────────────
// UI
// ─────────────────────────────────────────────────────────────────────
require_once('header.php');

// Conta record per categoria (badge nel tab)
$counts = [];
foreach ($categories as $key => $catdef) {
    try {
        $counts[$key] = (int)$pdo->query("SELECT COUNT(*) FROM {$catdef['table']}")->fetchColumn();
    } catch (Throwable $e) { $counts[$key] = 0; }
}

// Helper: pill di stato colorata
function status_pill(string $status): string {
    $palette = [
        'assegnato' => ['#dcfce7','#166534'], 'attiva'       => ['#dcfce7','#166534'],
        'restituito'=> ['#f1f5f9','#475569'], 'disattiva'    => ['#f1f5f9','#475569'],
        'smarrito'  => ['#fee2e2','#991b1b'], 'smarrita'     => ['#fee2e2','#991b1b'],
        'rotto'     => ['#fef3c7','#92400e'], 'sostituita'   => ['#fef3c7','#92400e'],
        'in_riparazione' => ['#dbeafe','#1e40af'], 'bloccata' => ['#fee2e2','#991b1b'],
        'incidente' => ['#fee2e2','#991b1b'],
    ];
    [$bg,$col] = $palette[$status] ?? ['#f1f5f9','#475569'];
    return "<span style='background:$bg;color:$col;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:800;text-transform:uppercase'>" . h($status) . "</span>";
}

// Pre-costruisco query string filtri (per export link e link tab)
$qs_filters = http_build_query(array_filter([
    'f_status'   => $f_status,
    'f_employee' => $f_employee ?: null,
    'f_company'  => $f_company  ?: null,
    'f_location' => $f_location ?: null,
    'f_from'     => $f_from,
    'f_to'       => $f_to,
    'f_field'    => $f_field !== 'assigned_at' ? $f_field : null,
]));
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0">
      <i class="fa-solid fa-laptop-mobile" style="color:var(--p)"></i> Gestione Dispositivi
    </h1>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">
      Vista globale di telefoni, SIM, notebook, veicoli, carte carburante e di credito aziendali con filtri e storico movimenti
    </div>
  </div>
  <?php if (!empty($rows)): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= qs_self_safe(['cat'=>$cat, 'export'=>'xls']) . ($qs_filters ? '&' . $qs_filters : '') ?>" class="btn btn-sm" style="background:#059669;color:#fff;border:0">
      <i class="fa-solid fa-file-excel"></i> Esporta XLS
    </a>
    <a href="<?= qs_self_safe(['cat'=>$cat, 'export'=>'csv']) . ($qs_filters ? '&' . $qs_filters : '') ?>" class="btn btn-sm" style="background:#16a34a;color:#fff;border:0">
      <i class="fa-solid fa-file-csv"></i> Esporta CSV
    </a>
    <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i> Stampa</button>
  </div>
  <?php endif; ?>
</div>

<!-- TAB NAV CATEGORIE -->
<div class="no-print" style="display:flex;gap:2px;margin-bottom:18px;background:#f1f5f9;border-radius:10px;padding:4px;overflow-x:auto">
  <?php foreach ($categories as $key => $catdef):
    $is_active = ($cat === $key);
  ?>
  <a href="<?= qs_self_safe(['cat'=>$key]) . ($qs_filters ? '&' . $qs_filters : '') ?>"
     style="display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:8px;text-decoration:none;font-weight:700;font-size:12px;white-space:nowrap;<?= $is_active ? 'background:#fff;color:' . $catdef['color'] . ';box-shadow:0 1px 3px rgba(0,0,0,.08)' : 'color:var(--muted)' ?>">
    <i class="fa-solid <?= $catdef['icon'] ?>"></i>
    <?= h($catdef['label']) ?>
    <span style="background:<?= $is_active ? $catdef['color'] : '#cbd5e1' ?>;color:#fff;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:800"><?= $counts[$key] ?></span>
  </a>
  <?php endforeach; ?>
  <a href="<?= qs_self_safe(['cat'=>'history']) . ($qs_filters ? '&' . $qs_filters : '') ?>"
     style="display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:8px;text-decoration:none;font-weight:700;font-size:12px;white-space:nowrap;<?= $cat === 'history' ? 'background:#fff;color:var(--p);box-shadow:0 1px 3px rgba(0,0,0,.08)' : 'color:var(--muted)' ?>">
    <i class="fa-solid fa-clock-rotate-left"></i> Storico movimenti
  </a>
</div>

<!-- FILTRI -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-filter" style="color:var(--p)"></i> Filtri</span>
    <a href="<?= qs_self_safe(['cat'=>$cat]) ?>" class="btn btn-sm" style="background:#f1f5f9"><i class="fa-solid fa-rotate-left"></i> Reset</a>
  </div>
  <form method="GET" action="" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:10px">
    <?php if (!empty($_GET['r'])): ?><input type="hidden" name="r" value="<?= h($_GET['r']) ?>"><?php endif; ?>
    <input type="hidden" name="cat" value="<?= h($cat) ?>">

    <?php if ($cat !== 'history'): ?>
    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Stato</label>
      <select name="f_status" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
        <option value="">— Tutti gli stati —</option>
        <?php foreach ($categories[$cat]['status_enum'] as $s): ?>
        <option value="<?= h($s) ?>" <?= $f_status === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Dipendente</label>
      <select name="f_employee" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
        <option value="0">— Tutti —</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $f_employee === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Azienda</label>
      <select name="f_company" id="f_company" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
        <option value="0">— Tutte —</option>
        <?php foreach ($companies as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $f_company === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Sede</label>
      <select name="f_location" id="f_location" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
        <option value="0">— Tutte —</option>
        <?php foreach ($locations as $l):
            if ($f_company > 0 && (int)$l['company_id'] !== $f_company) continue;
        ?>
        <option value="<?= $l['id'] ?>" <?= $f_location === (int)$l['id'] ? 'selected' : '' ?>><?= h($l['location_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Periodo per</label>
      <select name="f_field" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
        <option value="assigned_at" <?= $f_field === 'assigned_at' ? 'selected' : '' ?>>Data consegna</option>
        <option value="returned_at" <?= $f_field === 'returned_at' ? 'selected' : '' ?>>Data ritiro</option>
      </select>
    </div>

    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Dal</label>
      <input type="date" name="f_from" value="<?= h($f_from) ?>" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
    </div>

    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">Al</label>
      <input type="date" name="f_to" value="<?= h($f_to) ?>" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
    </div>

    <div style="display:flex;align-items:flex-end">
      <button type="submit" class="btn btn-primary" style="width:100%;padding:7px"><i class="fa-solid fa-magnifying-glass"></i> Filtra</button>
    </div>
  </form>
</div>

<!-- RISULTATI -->
<div class="card">
  <div class="card-header">
    <span class="card-title">
      <i class="fa-solid <?= $cat === 'history' ? 'fa-clock-rotate-left' : $categories[$cat]['icon'] ?>" style="color:<?= $cat === 'history' ? 'var(--p)' : $categories[$cat]['color'] ?>"></i>
      <?= $cat === 'history' ? 'Storico movimenti' : h($categories[$cat]['label']) ?>
      <span style="font-size:11px;color:var(--muted);font-weight:600">(<?= count($rows) ?> risultati<?= count($rows) >= 500 ? ', limite raggiunto' : '' ?>)</span>
    </span>
  </div>

  <?php if (!empty($query_error)): ?>
    <div class="alert alert-danger" style="font-size:11px;margin-bottom:8px">
      <i class="fa-solid fa-triangle-exclamation"></i> Errore query: <?= h($query_error) ?>
    </div>
  <?php endif; ?>

  <?php if (empty($rows)): ?>
    <div style="text-align:center;padding:40px;color:var(--muted)">
      <i class="fa-solid fa-inbox" style="font-size:36px;opacity:.3;display:block;margin-bottom:12px"></i>
      <p style="margin:0;font-size:13px">Nessun record corrispondente ai filtri impostati.</p>
    </div>

  <?php elseif ($cat === 'history'): ?>
    <!-- STORICO MOVIMENTI -->
    <div style="overflow-x:auto">
    <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('device_manager', '#lf-table-device_manager', ['export_filename' => 'device_manager', 'title' => 'Gestione dispositivi']); ?>
<table id="lf-table-device_manager" class="data-table">
      <thead><tr>
        <th>Categoria</th><th>Descrizione</th><th>Dipendente</th><th>Azienda / Sede</th>
        <th>Stato</th><th>Consegna</th><th>Ritiro</th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r):
          $cat_meta = $categories[$r['category']] ?? null;
        ?>
        <tr>
          <td>
            <?php if ($cat_meta): ?>
            <span style="background:<?= $cat_meta['color'] ?>22;color:<?= $cat_meta['color'] ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700">
              <i class="fa-solid <?= $cat_meta['icon'] ?>"></i> <?= h($r['category_label']) ?>
            </span>
            <?php else: ?>
            <span class="badge"><?= h($r['category_label']) ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:11px"><?= h(trim($r['description']) ?: '—') ?></td>
          <td style="font-size:11px">
            <?php if ($r['employee_name']): ?>
              <strong><?= h($r['employee_name']) ?></strong>
              <?php if ($r['employee_code']): ?><br><small style="color:var(--muted)"><?= h($r['employee_code']) ?></small><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="font-size:11px;color:var(--muted)"><?= h($r['company_name'] ?? '—') ?><br><small><?= h($r['location_name'] ?? '') ?></small></td>
          <td><?= status_pill($r['status'] ?? '') ?></td>
          <td style="font-size:11px"><?= $r['assigned_at'] ? date('d/m/Y', strtotime($r['assigned_at'])) : '—' ?></td>
          <td style="font-size:11px"><?= $r['returned_at'] ? date('d/m/Y', strtotime($r['returned_at'])) : '<span style="color:#16a34a;font-weight:700">in uso</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

  <?php else:
    $catdef = $categories[$cat];
  ?>
    <!-- VISTA CATEGORIA -->
    <div style="overflow-x:auto">
    <table class="data-table">
      <thead><tr>
        <th>Dipendente</th>
        <?php foreach ($catdef['cols'] as $k => $label): ?><th><?= h($label) ?></th><?php endforeach; ?>
        <th>Azienda / Sede</th>
        <th>Consegna</th>
        <th>Ritiro</th>
        <th>Stato</th>
        <th style="width:60px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td style="font-size:11px">
            <?php if ($r['employee_name']): ?>
              <strong><?= h($r['employee_name']) ?></strong>
              <?php if ($r['employee_code']): ?><br><small style="color:var(--muted)"><?= h($r['employee_code']) ?></small><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <?php foreach ($catdef['cols'] as $k => $label):
            $v = $r[$k] ?? '';
            $is_mono = in_array($k, ['imei_1','serial_number','phone_number','card_number','card_number_last4','plate'], true);
          ?>
          <td style="font-size:11px;<?= $is_mono ? 'font-family:monospace' : '' ?>"><?= h($v ?: '—') ?></td>
          <?php endforeach; ?>
          <td style="font-size:11px;color:var(--muted)"><?= h($r['company_name'] ?? '—') ?><br><small><?= h($r['location_name'] ?? '') ?></small></td>
          <td style="font-size:11px"><?= $r['assigned_at'] ? date('d/m/Y', strtotime($r['assigned_at'])) : '—' ?></td>
          <td style="font-size:11px"><?= $r['returned_at'] ? date('d/m/Y', strtotime($r['returned_at'])) : '<span style="color:#16a34a;font-weight:700">in uso</span>' ?></td>
          <td><?= status_pill($r['status']) ?></td>
          <td style="text-align:right">
            <?php if ($r['employee_id']): ?>
            <a href="employee_profile.php?id=<?= $r['employee_id'] ?>&tab=dispositivi" class="btn btn-sm" title="Apri scheda dipendente">
              <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script>
// Cascade Azienda → Sede
const f_company  = document.getElementById('f_company');
const f_location = document.getElementById('f_location');
if (f_company && f_location) {
  // Memorizzo i locations con company_id
  const all_locations = <?= json_encode($locations) ?>;
  f_company.addEventListener('change', () => {
    const cid = parseInt(f_company.value, 10);
    const selected = f_location.value;
    f_location.innerHTML = '<option value="0">— Tutte —</option>';
    all_locations.forEach(l => {
      if (cid === 0 || parseInt(l.company_id, 10) === cid) {
        const opt = document.createElement('option');
        opt.value = l.id;
        opt.textContent = l.location_name;
        if (String(l.id) === selected) opt.selected = true;
        f_location.appendChild(opt);
      }
    });
  });
}
</script>

<?php require_once('footer.php'); ?>
