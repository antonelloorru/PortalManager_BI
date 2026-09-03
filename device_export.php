<?php
/**
 * PortalManager 1.5.1 — device_export.php
 *
 * Esporta in Excel multi-sheet TUTTI i dispositivi di un dipendente.
 * URL: device_export.php?employee_id=N&format=xlsx
 *
 * Output: file scaricabile con 9 fogli (Telefoni, SIM, Notebook, Veicoli,
 * Tagliandi, Carte carburante, Rifornimenti, Carte credito, Estratti conto).
 */

require_once('access_control.php');

$u_role  = (int)($_SESSION['role_id'] ?? 99);
$can_view = in_array($u_role, [1, 2, 4], true) || ((int)($_SESSION['employee_id'] ?? 0) === (int)($_GET['employee_id'] ?? 0));
if (!$can_view) {
    http_response_code(403);
    die('Accesso negato');
}

$emp_id = (int)($_GET['employee_id'] ?? 0);
if (!$emp_id) {
    http_response_code(400);
    die('employee_id mancante');
}

// Recupero dati dipendente
$st = $pdo->prepare("SELECT id, first_name, last_name, employee_code FROM employees WHERE id=?");
$st->execute([$emp_id]);
$emp = $st->fetch();
if (!$emp) {
    http_response_code(404);
    die('Dipendente non trovato');
}

$emp_label = trim($emp['first_name'] . ' ' . $emp['last_name']);
$emp_code  = $emp['employee_code'] ?: "ID{$emp_id}";

// Carico tutti i dispositivi
$datasets = [];

$datasets['Telefoni'] = [
    'headers' => ['ID','Marca','Modello','IMEI 1','IMEI 2','Seriale','Data consegna','Data ritiro','Stato','Note'],
    'rows'    => $pdo->prepare("SELECT id, brand, model, imei_1, imei_2, serial_number, assigned_at, returned_at, status, notes FROM emp_devices_phone WHERE employee_id=? ORDER BY assigned_at DESC"),
];
$datasets['SIM'] = [
    'headers' => ['ID','Tipo','Operatore','Numero telefono','Seriale (ICCID)','PIN','PUK','Data consegna','Data ritiro','Stato','Note'],
    'rows'    => $pdo->prepare("SELECT id, sim_type, operator, phone_number, serial_number, pin_code, puk_code, assigned_at, returned_at, status, notes FROM emp_devices_sim WHERE employee_id=? ORDER BY assigned_at DESC"),
];
$datasets['Notebook'] = [
    'headers' => ['ID','Marca','Modello','Seriale','Sistema operativo','Caratteristiche','Data consegna','Data ritiro','Stato','Note'],
    'rows'    => $pdo->prepare("SELECT id, brand, model, serial_number, os, specs, assigned_at, returned_at, status, notes FROM emp_devices_notebook WHERE employee_id=? ORDER BY assigned_at DESC"),
];
$datasets['Veicoli'] = [
    'headers' => ['ID','Marca','Modello','Targa','Alimentazione','Acquisizione','Contratto','Inizio contratto','Fine contratto','Costo rateo €','Km iniziali','Km attuali','Condizioni','Data consegna','Data ritiro','Stato','Note'],
    'rows'    => $pdo->prepare("SELECT id, brand, model, plate, fuel_type, acquisition_type, contract_ref, contract_start, contract_end, monthly_cost, initial_km, current_km, conditions, assigned_at, returned_at, status, notes FROM emp_devices_vehicle WHERE employee_id=? ORDER BY assigned_at DESC"),
];
$datasets['Tagliandi'] = [
    'headers' => ['ID','Veicolo','Targa','Data tagliando','Km','Costo €','Descrizione','Allegato'],
    'rows'    => $pdo->prepare("SELECT s.id, CONCAT(v.brand,' ',v.model) AS veicolo, v.plate, s.service_date, s.km, s.cost, s.description, s.document_path FROM emp_vehicle_service s JOIN emp_devices_vehicle v ON v.id=s.vehicle_id WHERE v.employee_id=? ORDER BY s.service_date DESC"),
];
$datasets['Carte carburante'] = [
    'headers' => ['ID','Circuito','Numero','PIN','Veicolo associato','Data consegna','Data ritiro','Stato','Note'],
    'rows'    => $pdo->prepare("SELECT fc.id, fc.circuit, fc.card_number, fc.pin_code, CONCAT(COALESCE(v.brand,''),' ',COALESCE(v.model,'')) AS veicolo, fc.assigned_at, fc.returned_at, fc.status, fc.notes FROM emp_devices_fuel_card fc LEFT JOIN emp_devices_vehicle v ON v.id=fc.vehicle_id WHERE fc.employee_id=? ORDER BY fc.assigned_at DESC"),
];
$datasets['Rifornimenti'] = [
    'headers' => ['ID','Circuito','Data','Km','Litri','Importo €','Località','Allegato'],
    'rows'    => $pdo->prepare("SELECT l.id, fc.circuit, l.refuel_date, l.km, l.liters, l.amount, l.location, l.document_path FROM emp_fuel_log l JOIN emp_devices_fuel_card fc ON fc.id=l.fuel_card_id WHERE fc.employee_id=? ORDER BY l.refuel_date DESC"),
];
$datasets['Carte credito'] = [
    'headers' => ['ID','Circuito','Banca','Ultime 4 cifre','PIN','Plafond €','Data consegna','Data ritiro','Stato','Note'],
    'rows'    => $pdo->prepare("SELECT id, circuit, bank, card_number_last4, pin_code, credit_limit, assigned_at, returned_at, status, notes FROM emp_devices_credit_card WHERE employee_id=? ORDER BY assigned_at DESC"),
];
$datasets['Estratti conto'] = [
    'headers' => ['ID','Carta','Anno','Mese','Totale €','Allegato','Note'],
    'rows'    => $pdo->prepare("SELECT s.id, CONCAT(cc.circuit,' ●●●●',cc.card_number_last4) AS carta, s.period_year, s.period_month, s.total_amount, s.document_path, s.notes FROM emp_credit_card_statement s JOIN emp_devices_credit_card cc ON cc.id=s.credit_card_id WHERE cc.employee_id=? ORDER BY s.period_year DESC, s.period_month DESC"),
];

// Esegui le query
foreach ($datasets as &$ds) {
    $ds['rows']->execute([$emp_id]);
    $ds['data'] = $ds['rows']->fetchAll(PDO::FETCH_NUM);
}
unset($ds);

// ─────────────────────────────────────────────────────────────────────
// Genero Excel via PhpSpreadsheet (se disponibile) oppure XML SpreadsheetML (fallback nativo)
// Strategia: fallback XML SpreadsheetML 2003 — apre con Excel/LibreOffice senza dipendenze
// ─────────────────────────────────────────────────────────────────────
$filename = "Dispositivi_{$emp_code}_" . date('Ymd') . ".xls";

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

echo '<Styles>' . "\n";
echo ' <Style ss:ID="HeaderStyle"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1e40af" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>' . "\n";
echo ' <Style ss:ID="TitleStyle"><Font ss:Bold="1" ss:Size="14" ss:Color="#1e3a8a"/></Style>' . "\n";
echo ' <Style ss:ID="SubtitleStyle"><Font ss:Italic="1" ss:Color="#64748b"/></Style>' . "\n";
echo '</Styles>' . "\n";

$xml_escape = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');

foreach ($datasets as $sheetName => $ds) {
    $rowCount = count($ds['data']);
    echo '<Worksheet ss:Name="' . $xml_escape($sheetName) . '">' . "\n";
    echo ' <Table>' . "\n";

    // Titolo
    echo '  <Row><Cell ss:MergeAcross="' . (count($ds['headers']) - 1) . '" ss:StyleID="TitleStyle"><Data ss:Type="String">' . $xml_escape("Dispositivi · {$emp_label} · " . $sheetName) . '</Data></Cell></Row>' . "\n";
    echo '  <Row><Cell ss:StyleID="SubtitleStyle"><Data ss:Type="String">Esportato: ' . date('d/m/Y H:i') . '</Data></Cell></Row>' . "\n";
    echo '  <Row></Row>' . "\n";

    // Headers
    echo '  <Row>';
    foreach ($ds['headers'] as $h) {
        echo '<Cell ss:StyleID="HeaderStyle"><Data ss:Type="String">' . $xml_escape($h) . '</Data></Cell>';
    }
    echo '</Row>' . "\n";

    // Data
    foreach ($ds['data'] as $row) {
        echo '  <Row>';
        foreach ($row as $val) {
            if ($val === null || $val === '') {
                echo '<Cell><Data ss:Type="String"></Data></Cell>';
            } elseif (is_numeric($val) && !preg_match('/^0\d/', (string)$val)) {
                echo '<Cell><Data ss:Type="Number">' . $val . '</Data></Cell>';
            } else {
                echo '<Cell><Data ss:Type="String">' . $xml_escape($val) . '</Data></Cell>';
            }
        }
        echo '</Row>' . "\n";
    }

    if ($rowCount === 0) {
        echo '  <Row><Cell ss:MergeAcross="' . (count($ds['headers']) - 1) . '"><Data ss:Type="String">— Nessun record —</Data></Cell></Row>' . "\n";
    }

    echo ' </Table>' . "\n";
    echo '</Worksheet>' . "\n";
}

echo '</Workbook>' . "\n";
exit;
