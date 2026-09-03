<?php
/**
 * PortalManager 1.5.1 — device_print.php
 *
 * Vista print-friendly modulo "Consegna/Ritiro dispositivi aziendali"
 * stampabile su carta (A4) come modulo cartaceo da firmare.
 *
 * URL: device_print.php?employee_id=N
 */

require_once('access_control.php');

$u_role  = (int)($_SESSION['role_id'] ?? 99);
$emp_id = (int)($_GET['employee_id'] ?? 0);
if (!$emp_id) { http_response_code(400); die('employee_id mancante'); }

$can_view = in_array($u_role, [1, 2, 4], true) || ((int)($_SESSION['employee_id'] ?? 0) === $emp_id);
if (!$can_view) { http_response_code(403); die('Accesso negato'); }

// Carico dati dipendente + azienda
$st = $pdo->prepare(
    "SELECT e.*, co.name AS company_name, loc.location_name
       FROM employees e
       LEFT JOIN companies co ON co.id = e.company_id
       LEFT JOIN company_locations loc ON loc.id = e.location_id
      WHERE e.id = ?"
);
$st->execute([$emp_id]);
$emp = $st->fetch();
if (!$emp) { http_response_code(404); die('Dipendente non trovato'); }

// Carico TUTTI i dispositivi (in uso E restituiti, separati)
$queries = [
    'phones'       => "SELECT * FROM emp_devices_phone WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC",
    'sims'         => "SELECT * FROM emp_devices_sim WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, sim_type, assigned_at DESC",
    'notebooks'    => "SELECT * FROM emp_devices_notebook WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC",
    'vehicles'     => "SELECT * FROM emp_devices_vehicle WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC",
    'fuel_cards'   => "SELECT * FROM emp_devices_fuel_card WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC",
    'credit_cards' => "SELECT * FROM emp_devices_credit_card WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC",
];
$data = [];
foreach ($queries as $key => $q) {
    $s = $pdo->prepare($q);
    $s->execute([$emp_id]);
    $data[$key] = $s->fetchAll();
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$fmt_date = function ($d) {
    if (!$d) return '—';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : $d;
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Scheda dispositivi — <?= $h($emp['first_name'].' '.$emp['last_name']) ?></title>
<style>
  @page { size: A4; margin: 1.5cm; }
  * { box-sizing: border-box; }
  body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11px; color: #1f2937; line-height: 1.4; margin: 0; padding: 0; }

  .hdr { border-bottom: 3px solid #1e40af; padding-bottom: 10px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: flex-end; }
  .hdr-title { font-size: 18px; font-weight: 800; color: #1e3a8a; margin: 0; }
  .hdr-sub { font-size: 11px; color: #64748b; margin-top: 4px; }
  .hdr-meta { font-size: 10px; color: #64748b; text-align: right; }

  .person-box { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; font-size: 11px; }
  .person-box strong { color: #1e40af; }

  .section { margin-bottom: 14px; page-break-inside: avoid; }
  .section h2 { font-size: 13px; font-weight: 800; color: #1e3a8a; background: #dbeafe; padding: 6px 10px; border-left: 4px solid #1e40af; margin: 0 0 6px; border-radius: 0 4px 4px 0; }

  table { width: 100%; border-collapse: collapse; font-size: 10px; }
  th { background: #e0f2fe; color: #075985; padding: 5px 6px; text-align: left; border: 1px solid #bae6fd; font-weight: 700; font-size: 9px; text-transform: uppercase; }
  td { padding: 5px 6px; border: 1px solid #e2e8f0; vertical-align: top; }
  td.numeric { font-family: 'Courier New', monospace; font-size: 10px; }
  tr.returned td { color: #9ca3af; background: #fafafa; text-decoration: line-through; }

  .empty-row { font-style: italic; color: #94a3b8; padding: 8px 6px; }

  .signature-area { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px; page-break-inside: avoid; }
  .signature-box { border-top: 1px solid #1f2937; padding-top: 6px; font-size: 10px; color: #64748b; text-align: center; }
  .signature-box strong { display: block; color: #1f2937; font-size: 11px; margin-bottom: 18px; }

  .footer-print { margin-top: 24px; padding-top: 10px; border-top: 1px solid #cbd5e1; font-size: 9px; color: #94a3b8; text-align: center; }

  .badge-active { background: #dcfce7; color: #166534; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 700; }
  .badge-returned { background: #fee2e2; color: #991b1b; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 700; }

  .print-btn { position: fixed; top: 14px; right: 14px; background: #1e40af; color: white; border: 0; border-radius: 6px; padding: 8px 16px; font-weight: 700; cursor: pointer; }
  @media print { .print-btn { display: none !important; } body { font-size: 10px; } }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">🖨️ Stampa</button>

<div class="hdr">
  <div>
    <h1 class="hdr-title">Modulo dispositivi aziendali</h1>
    <div class="hdr-sub">Riepilogo dotazione e assegnazioni</div>
  </div>
  <div class="hdr-meta">
    <div>Data emissione: <strong><?= date('d/m/Y') ?></strong></div>
    <div>Protocollo: PM-DEV-<?= $emp_id ?>-<?= date('Ymd') ?></div>
  </div>
</div>

<div class="person-box">
  <div><strong>Dipendente:</strong><br><?= $h($emp['first_name'].' '.$emp['last_name']) ?></div>
  <div><strong>Matricola:</strong> <?= $h($emp['employee_code'] ?? '—') ?><br>
       <strong>Cod. fiscale:</strong> <?= $h($emp['fiscal_code'] ?? '—') ?></div>
  <div><strong>Azienda:</strong> <?= $h($emp['company_name'] ?? '—') ?><br>
       <strong>Sede:</strong> <?= $h($emp['location_name'] ?? '—') ?></div>
</div>

<!-- TELEFONI -->
<div class="section">
  <h2>📱 Telefono aziendale</h2>
  <?php if (empty($data['phones'])): ?>
    <div class="empty-row">Nessun telefono assegnato.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Marca</th><th>Modello</th><th>IMEI 1</th><th>IMEI 2</th><th>S/N</th><th>Consegna</th><th>Ritiro</th><th>Stato</th></tr></thead>
      <tbody>
        <?php foreach ($data['phones'] as $d): ?>
        <tr class="<?= $d['returned_at'] ? 'returned' : '' ?>">
          <td><?= $h($d['brand']) ?></td>
          <td><?= $h($d['model']) ?></td>
          <td class="numeric"><?= $h($d['imei_1'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['imei_2'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['serial_number'] ?? '—') ?></td>
          <td><?= $fmt_date($d['assigned_at']) ?></td>
          <td><?= $d['returned_at'] ? $fmt_date($d['returned_at']) : '<span class="badge-active">in uso</span>' ?></td>
          <td><?= $h($d['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- SIM -->
<div class="section">
  <h2>📶 SIM aziendali</h2>
  <?php if (empty($data['sims'])): ?>
    <div class="empty-row">Nessuna SIM assegnata.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Tipo</th><th>Operatore</th><th>Numero</th><th>ICCID</th><th>PIN</th><th>PUK</th><th>Consegna</th><th>Ritiro</th><th>Stato</th></tr></thead>
      <tbody>
        <?php foreach ($data['sims'] as $d): ?>
        <tr class="<?= $d['returned_at'] ? 'returned' : '' ?>">
          <td><?= strtoupper($h($d['sim_type'])) ?></td>
          <td><?= $h($d['operator'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['phone_number'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['serial_number'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['pin_code'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['puk_code'] ?? '—') ?></td>
          <td><?= $fmt_date($d['assigned_at']) ?></td>
          <td><?= $d['returned_at'] ? $fmt_date($d['returned_at']) : '<span class="badge-active">attiva</span>' ?></td>
          <td><?= $h($d['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- NOTEBOOK -->
<div class="section">
  <h2>💻 Notebook aziendale</h2>
  <?php if (empty($data['notebooks'])): ?>
    <div class="empty-row">Nessun notebook assegnato.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Marca</th><th>Modello</th><th>S/N</th><th>SO</th><th>Caratteristiche</th><th>Consegna</th><th>Ritiro</th><th>Stato</th></tr></thead>
      <tbody>
        <?php foreach ($data['notebooks'] as $d): ?>
        <tr class="<?= $d['returned_at'] ? 'returned' : '' ?>">
          <td><?= $h($d['brand']) ?></td>
          <td><?= $h($d['model']) ?></td>
          <td class="numeric"><?= $h($d['serial_number'] ?? '—') ?></td>
          <td><?= $h($d['os'] ?? '—') ?></td>
          <td><?= $h($d['specs'] ?? '—') ?></td>
          <td><?= $fmt_date($d['assigned_at']) ?></td>
          <td><?= $d['returned_at'] ? $fmt_date($d['returned_at']) : '<span class="badge-active">in uso</span>' ?></td>
          <td><?= $h($d['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- VEICOLO -->
<div class="section">
  <h2>🚗 Veicolo aziendale</h2>
  <?php if (empty($data['vehicles'])): ?>
    <div class="empty-row">Nessun veicolo assegnato.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Marca / Modello</th><th>Targa</th><th>Alim.</th><th>Tipo</th><th>Contratto</th><th>Inizio</th><th>Fine</th><th>Rateo €</th><th>Km in.</th><th>Km att.</th><th>Consegna</th><th>Ritiro</th></tr></thead>
      <tbody>
        <?php foreach ($data['vehicles'] as $d): ?>
        <tr class="<?= $d['returned_at'] ? 'returned' : '' ?>">
          <td><?= $h($d['brand'].' '.$d['model']) ?></td>
          <td class="numeric"><?= $h($d['plate'] ?? '—') ?></td>
          <td><?= $h($d['fuel_type'] ?? '—') ?></td>
          <td><?= $h(ucfirst(str_replace('_',' ',$d['acquisition_type']))) ?></td>
          <td class="numeric"><?= $h($d['contract_ref'] ?? '—') ?></td>
          <td><?= $fmt_date($d['contract_start']) ?></td>
          <td><?= $fmt_date($d['contract_end']) ?></td>
          <td class="numeric"><?= $d['monthly_cost'] ? number_format($d['monthly_cost'], 2, ',', '.') : '—' ?></td>
          <td class="numeric"><?= $d['initial_km'] ? number_format($d['initial_km'], 0, ',', '.') : '—' ?></td>
          <td class="numeric"><?= $d['current_km'] ? number_format($d['current_km'], 0, ',', '.') : '—' ?></td>
          <td><?= $fmt_date($d['assigned_at']) ?></td>
          <td><?= $d['returned_at'] ? $fmt_date($d['returned_at']) : '<span class="badge-active">in uso</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- CARTE CARBURANTE -->
<div class="section">
  <h2>⛽ Carta carburante</h2>
  <?php if (empty($data['fuel_cards'])): ?>
    <div class="empty-row">Nessuna carta carburante assegnata.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Circuito</th><th>Numero</th><th>PIN</th><th>Consegna</th><th>Ritiro</th><th>Stato</th></tr></thead>
      <tbody>
        <?php foreach ($data['fuel_cards'] as $d): ?>
        <tr class="<?= $d['returned_at'] ? 'returned' : '' ?>">
          <td><?= $h($d['circuit'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['card_number'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['pin_code'] ?? '—') ?></td>
          <td><?= $fmt_date($d['assigned_at']) ?></td>
          <td><?= $d['returned_at'] ? $fmt_date($d['returned_at']) : '<span class="badge-active">attiva</span>' ?></td>
          <td><?= $h($d['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- CARTE CREDITO -->
<div class="section">
  <h2>💳 Carta di credito aziendale</h2>
  <?php if (empty($data['credit_cards'])): ?>
    <div class="empty-row">Nessuna carta di credito assegnata.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Circuito</th><th>Banca</th><th>Ultime 4</th><th>PIN</th><th>Plafond €</th><th>Consegna</th><th>Ritiro</th><th>Stato</th></tr></thead>
      <tbody>
        <?php foreach ($data['credit_cards'] as $d): ?>
        <tr class="<?= $d['returned_at'] ? 'returned' : '' ?>">
          <td><?= $h($d['circuit'] ?? '—') ?></td>
          <td><?= $h($d['bank'] ?? '—') ?></td>
          <td class="numeric">●●●● <?= $h($d['card_number_last4'] ?? '—') ?></td>
          <td class="numeric"><?= $h($d['pin_code'] ?? '—') ?></td>
          <td class="numeric"><?= $d['credit_limit'] ? number_format($d['credit_limit'], 2, ',', '.') : '—' ?></td>
          <td><?= $fmt_date($d['assigned_at']) ?></td>
          <td><?= $d['returned_at'] ? $fmt_date($d['returned_at']) : '<span class="badge-active">attiva</span>' ?></td>
          <td><?= $h($d['status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- DICHIARAZIONE FIRMA -->
<div style="margin-top: 20px; padding: 10px 14px; background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: 0 4px 4px 0; font-size: 10px; color: #78350f;">
  Il sottoscritto <strong><?= $h($emp['first_name'].' '.$emp['last_name']) ?></strong>
  dichiara di aver ricevuto in dotazione i dispositivi/strumenti aziendali sopra elencati,
  impegnandosi al loro corretto utilizzo, alla custodia diligente e alla restituzione in caso
  di cessazione del rapporto di lavoro o di richiesta dall'azienda.
</div>

<div class="signature-area">
  <div class="signature-box">
    <strong>Il dipendente</strong>
    <?= $h($emp['first_name'].' '.$emp['last_name']) ?>
  </div>
  <div class="signature-box">
    <strong>Per l'azienda</strong>
    Data: <?= date('d/m/Y') ?>
  </div>
</div>

<div class="footer-print">
  Documento generato da PortalManager · <?= date('d/m/Y H:i') ?> · Riservato e confidenziale
</div>

<script>
// Auto-print disabled: l'utente deve cliccare manualmente
</script>
</body>
</html>
