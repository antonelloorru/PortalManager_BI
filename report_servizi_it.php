<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.30 — Relazione Servizi IT
 *
 * Novità v1.9.30:
 *   - Filtri Incaricato / Contratto / Cliente convertiti in MULTI-SELECT con
 *     ricerca testuale (senza Ctrl). Basato sul componente pm-multiselect.
 *   - Etichette anagrafiche uniformate a "Cognome Nome" (via PmFilters::person).
 *
 * Rispetto a v1.9.29: query invariate a livello di logica; WHERE aggiornato
 * per accettare array di ID (IN (...)) tramite PmFilters::inClause.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/PmFilters.php');
if (is_file(__DIR__ . '/app/CostModel.php')) require_once(__DIR__ . '/app/CostModel.php');

if (!can('view', 'report_servizi_it.php')) { redirect('manage_projects'); }
$u_id = (int)$_SESSION['user_id'];

// ── Filtri (ora multi-valore per operator/contract/customer) ────────────
$f = [
    'from'      => trim((string)($_GET['from']  ?? date('Y-m-01'))),
    'to'        => trim((string)($_GET['to']    ?? date('Y-m-d'))),
    'operator'  => PmFilters::ints($_GET['operator'] ?? []),
    'contract'  => PmFilters::ints($_GET['contract'] ?? []),
    'customer'  => PmFilters::ints($_GET['customer'] ?? []),
    'regime'    => in_array($_GET['regime'] ?? '', ['ord','str','rep'], true) ? $_GET['regime'] : '',
];
$export = ($_GET['export'] ?? '') === 'csv';

// ── WHERE dinamico condiviso ────────────────────────────────────────────
$w = ['COALESCE(a.deleted,0) <> 1'];
$b = [];
if ($f['from']) { $w[] = 'a.report_date >= ?'; $b[] = $f['from']; }
if ($f['to'])   { $w[] = 'a.report_date <= ?'; $b[] = $f['to']; }
[$s, $bb] = PmFilters::inClause('ao.id_operator',    $f['operator']); if ($s) { $w[] = $s; $b = array_merge($b, $bb); }
[$s, $bb] = PmFilters::inClause('a.id_contract',     $f['contract']); if ($s) { $w[] = $s; $b = array_merge($b, $bb); }
[$s, $bb] = PmFilters::inClause('a.id_customer_comp',$f['customer']); if ($s) { $w[] = $s; $b = array_merge($b, $bb); }
if ($f['regime'] === 'rep') $w[] = 'COALESCE(ao.during_availability,0) = 1';
if ($f['regime'] === 'str') $w[] = 'COALESCE(ao.extra_hours,0) > 0';
if ($f['regime'] === 'ord') $w[] = 'COALESCE(ao.during_availability,0)=0 AND COALESCE(ao.extra_hours,0)=0';
$whereSql = 'WHERE ' . implode(' AND ', $w);

// Alias "Cognome Nome" per l'operatore
$OPNAME = PmFilters::personSql('op.first_name', 'op.second_name');

// ── SEZIONE 3 (Dettaglio per Commessa) ──────────────────────────────────
$sqlDettaglio = "
  SELECT
    c.id                                                       AS contract_id,
    c.code                                                     AS contract_code,
    c.code_x_installation,
    cli.name                                                   AS customer_name,
    c.description                                              AS contract_description,
    p.project_code                                             AS pm_project_code,
    a.report_date,
    a.ticket                                                   AS ticket,
    $OPNAME                                                    AS operator_name,
    COALESCE(rbb.band_name, op.type, 'Default')                AS fascia,
    CASE WHEN COALESCE(ao.during_availability,0)=1 THEN 'Reperibilità'
         WHEN COALESCE(ao.extra_hours,0)>0          THEN 'Straordinario'
         ELSE 'Ordinario' END                                  AS regime,
    ROUND(COALESCE(ao.hours,0), 2)                             AS ore,
    ROUND(COALESCE(ao.cost,0), 2)                              AS costo_contratto,
    ROUND(CASE WHEN COALESCE(ao.during_availability,0) = 1
           THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0) * COALESCE(ao.hours,0)
           ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0) * COALESCE(ao.hours,0)
      END, 2)                                                  AS tot_costo_tab
  FROM dgb_forms_activity a
  JOIN dgb_forms_activity_operator ao ON ao.id_activity   = a.id
  JOIN dgb_operator               op  ON op.id            = ao.id_operator
  JOIN dgb_forms_contract         c   ON c.id             = a.id_contract
  LEFT JOIN clients               cli ON cli.id           = c.id_customer_comp
  LEFT JOIN cm_projects           p   ON p.dgb_contract_id = c.id
  LEFT JOIN cm_rate_bands         rbb ON rbb.band_name    = COALESCE(op.type, 'Default')
  LEFT JOIN cm_rate_band_rates    rb_ord ON rb_ord.band_id = rbb.id
       AND rb_ord.cost_type = 'Aziendale' AND rb_ord.regime = 'Ordinario'
  LEFT JOIN cm_rate_band_rates    rb_rep ON rb_rep.band_id = rbb.id
       AND rb_rep.cost_type = 'Aziendale' AND rb_rep.regime = 'Reperibilità'
  $whereSql
  ORDER BY c.code, c.id, a.report_date, a.id, ao.id
";
$stmt = $pdo->prepare($sqlDettaglio);
$stmt->execute($b);
$rowsDettaglio = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── EXPORT CSV (uscita anticipata) ──────────────────────────────────────
if ($export) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relazione_servizi_it_' . date('Ymd_His') . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Codice','Installazione','Cliente','Descrizione','PM Project','Ticket','Fascia','Regime','Data','Operatore','Ore','Costo contratto','TotCostoTab'], ';');
    foreach ($rowsDettaglio as $r) {
        fputcsv($fh, [
            $r['contract_code'], $r['code_x_installation'], $r['customer_name'], $r['contract_description'],
            $r['pm_project_code'], $r['ticket'], $r['fascia'], $r['regime'],
            $r['report_date'], $r['operator_name'],
            number_format((float)$r['ore'], 2, ',', ''),
            number_format((float)$r['costo_contratto'], 2, ',', ''),
            number_format((float)$r['tot_costo_tab'], 2, ',', ''),
        ], ';');
    }
    fclose($fh);
    exit;
}

require_once('header.php');

// ── SEZ 1 e SEZ 2 (invariate v1.9.29, con "Cognome Nome" e IN(...)) ─────
$sqlPersona = "
  SELECT
    op.id                                                       AS operator_id,
    $OPNAME                                                     AS operator_name,
    map.employee_id,
    COUNT(DISTINCT a.report_date)                               AS giornate,
    ROUND(SUM(COALESCE(ao.hours,0)), 2)                         AS ore_tot,
    ROUND(SUM(COALESCE(ao.hours,0)) / NULLIF(COUNT(DISTINCT a.report_date),0), 2) AS media_h_giorno,
    ROUND(SUM(COALESCE(ao.extra_hours,0)), 2)                   AS ore_straordinario,
    ROUND(SUM(CASE WHEN ao.during_availability=1 THEN COALESCE(ao.hours,0) ELSE 0 END), 2) AS ore_reperibilita,
    ROUND(SUM(COALESCE(ao.cost,0)), 2)                          AS costo_dgb
  FROM dgb_forms_activity a
  JOIN dgb_forms_activity_operator ao ON ao.id_activity      = a.id
  JOIN dgb_operator               op  ON op.id               = ao.id_operator
  LEFT JOIN dgb_operator_map      map ON map.dgb_operator_id = op.id
  $whereSql
  GROUP BY op.id, op.first_name, op.second_name, map.employee_id
  ORDER BY giornate DESC, ore_tot DESC
";
$stmt = $pdo->prepare($sqlPersona); $stmt->execute($b);
$rowsPersona = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sqlContract = "
  SELECT c.id AS contract_id,
    CONCAT_WS(' | ', NULLIF(c.code,''), NULLIF(c.code_x_installation,''), NULLIF(cli.name,''), NULLIF(c.description,'')) AS codice_contratto,
    p.project_code AS pm_project_code,
    ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=0 AND COALESCE(ao.extra_hours,0)=0 THEN COALESCE(ao.hours,0) ELSE 0 END), 2) AS ore_ordinarie,
    ROUND(SUM(COALESCE(ao.extra_hours,0)), 2) AS ore_straordinario,
    ROUND(SUM(CASE WHEN ao.during_availability=1 THEN COALESCE(ao.hours,0) ELSE 0 END), 2) AS ore_reperibilita,
    COUNT(DISTINCT CONCAT(a.report_date, '#', ao.id_operator)) AS giorni_uomo,
    ROUND(SUM(COALESCE(ao.cost,0)), 2) AS costo_contratto,
    ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=1
                   THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0) * COALESCE(ao.hours,0)
                   ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0) * COALESCE(ao.hours,0) END), 2) AS tot_costo_tab
  FROM dgb_forms_activity a
  JOIN dgb_forms_activity_operator ao ON ao.id_activity=a.id
  JOIN dgb_operator op ON op.id=ao.id_operator
  JOIN dgb_forms_contract c ON c.id=a.id_contract
  LEFT JOIN clients cli ON cli.id=c.id_customer_comp
  LEFT JOIN cm_projects p ON p.dgb_contract_id=c.id
  LEFT JOIN cm_rate_bands rbb ON rbb.band_name=COALESCE(op.type,'Default')
  LEFT JOIN cm_rate_band_rates rb_ord ON rb_ord.band_id=rbb.id AND rb_ord.cost_type='Aziendale' AND rb_ord.regime='Ordinario'
  LEFT JOIN cm_rate_band_rates rb_rep ON rb_rep.band_id=rbb.id AND rb_rep.cost_type='Aziendale' AND rb_rep.regime='Reperibilità'
  $whereSql
  GROUP BY c.id, c.code, c.code_x_installation, cli.name, c.description, p.project_code
  ORDER BY (SUM(COALESCE(ao.hours,0))) DESC
";
$stmt = $pdo->prepare($sqlContract); $stmt->execute($b);
$rowsContract = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Liste per multi-select (opzioni con label "Cognome Nome") ───────────
$vOp  = $pdo->query("SELECT id, $OPNAME AS nm FROM dgb_operator WHERE COALESCE(deleted,0)=0 ORDER BY second_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
$vCtr = $pdo->query("SELECT id, CONCAT_WS(' | ', code, code_x_installation, description) AS nm FROM dgb_forms_contract WHERE COALESCE(deleted,0)=0 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$vCli = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$byContract = [];
foreach ($rowsDettaglio as $r) $byContract[$r['contract_id']][] = $r;

$tot = [
    'giornate_uniche' => array_sum(array_column($rowsPersona, 'giornate')),
    'ore_tot'         => array_sum(array_column($rowsPersona, 'ore_tot')),
    'costo_dgb'       => array_sum(array_column($rowsPersona, 'costo_dgb')),
    'tot_costo_tab'   => array_sum(array_column($rowsContract, 'tot_costo_tab')),
];

// Query string per export (con array espansi)
$exportQs = http_build_query([
    'export' => 'csv', 'from' => $f['from'], 'to' => $f['to'],
    'operator' => $f['operator'], 'contract' => $f['contract'],
    'customer' => $f['customer'], 'regime' => $f['regime'],
]);
?>
<link rel="stylesheet" href="assets/css/pm-multiselect.css">
<script src="assets/js/pm-multiselect.js" defer></script>
<style>
  .rsi-kpi { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin:16px 0; }
  .rsi-kpi .card { background:#f7f8fb; border:1px solid #e4e7ee; border-radius:8px; padding:14px; }
  .rsi-kpi .card b { font-size:20px; display:block; }
  .rsi-kpi .card small { color:#667085; }
  .rsi-filters { display:grid; grid-template-columns:repeat(6,1fr); gap:8px; margin-bottom:16px; }
  .rsi-filters label { display:flex; flex-direction:column; font-size:12px; color:#667085; gap:4px; }
  .rsi-tbl { width:100%; border-collapse:collapse; margin-bottom:24px; }
  .rsi-tbl th, .rsi-tbl td { padding:8px 10px; border-bottom:1px solid #e4e7ee; font-size:13px; text-align:right; }
  .rsi-tbl th:first-child, .rsi-tbl td:first-child,
  .rsi-tbl th:nth-child(2), .rsi-tbl td:nth-child(2) { text-align:left; }
  .rsi-tbl thead th { background:#f0f2f7; }
  .rsi-tbl tfoot td { font-weight:600; background:#f0f2f7; }
  .rsi-h2 { margin-top:28px; }
  .rsi-badge { background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:999px; font-size:11px; }
  .rsi-grp-head { background:#1e293b; color:#fff; padding:10px 12px; border-radius:6px 6px 0 0; margin-top:18px; font-size:13px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .rsi-grp-head b { color:#93c5fd; }
  .rsi-grp-tbl { width:100%; border-collapse:collapse; margin-bottom:8px; }
  .rsi-grp-tbl th, .rsi-grp-tbl td { padding:6px 10px; border-bottom:1px solid #e4e7ee; font-size:12px; text-align:right; }
  .rsi-grp-tbl th:nth-child(1), .rsi-grp-tbl td:nth-child(1),
  .rsi-grp-tbl th:nth-child(2), .rsi-grp-tbl td:nth-child(2),
  .rsi-grp-tbl th:nth-child(3), .rsi-grp-tbl td:nth-child(3) { text-align:left; }
  .rsi-grp-tbl thead th { background:#f0f2f7; }
  .rsi-grp-tbl tfoot td { font-weight:600; background:#eef4ff; }
  .rsi-export { float:right; text-decoration:none; background:#0f6cf6; color:#fff; padding:6px 12px; border-radius:6px; font-size:13px; }
</style>

<h1>Relazione Servizi IT
  <a class="rsi-export" href="?<?= h($exportQs) ?>">⬇ Esporta dettaglio CSV</a>
</h1>

<form method="get" class="rsi-filters">
  <label>Dal <input type="date" name="from" value="<?= h($f['from']) ?>"></label>
  <label>Al  <input type="date" name="to"   value="<?= h($f['to']) ?>"></label>
  <label>Incaricati
    <select name="operator[]" multiple class="pm-ms" data-placeholder="Cerca incaricato…" data-allow-clear>
      <?php foreach ($vOp as $o): ?>
        <option value="<?= (int)$o['id'] ?>" <?= in_array((int)$o['id'], $f['operator'], true) ? 'selected' : '' ?>><?= h($o['nm']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Contratti
    <select name="contract[]" multiple class="pm-ms" data-placeholder="Cerca contratto…" data-allow-clear>
      <?php foreach ($vCtr as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $f['contract'], true) ? 'selected' : '' ?>><?= h($c['nm']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Clienti
    <select name="customer[]" multiple class="pm-ms" data-placeholder="Cerca cliente…" data-allow-clear>
      <?php foreach ($vCli as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $f['customer'], true) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Regime
    <select name="regime">
      <option value=""    <?= $f['regime']===''    ?'selected':'' ?>>— tutti —</option>
      <option value="ord" <?= $f['regime']==='ord' ?'selected':'' ?>>Ordinario</option>
      <option value="str" <?= $f['regime']==='str' ?'selected':'' ?>>Straordinario</option>
      <option value="rep" <?= $f['regime']==='rep' ?'selected':'' ?>>Reperibilità</option>
    </select>
  </label>
  <label>&nbsp;<button type="submit">Applica filtri</button></label>
</form>

<div class="rsi-kpi">
  <div class="card"><small>Giornate lavorate (uniche)</small><b><?= number_format((float)$tot['giornate_uniche'], 0, ',', '.') ?></b></div>
  <div class="card"><small>Ore totali</small><b><?= number_format((float)$tot['ore_tot'], 2, ',', '.') ?></b></div>
  <div class="card"><small>Costo da contratto (DGB)</small><b>€ <?= number_format((float)$tot['costo_dgb'], 2, ',', '.') ?></b></div>
  <div class="card"><small>Costo parametrato (TotCostoTab)</small><b>€ <?= number_format((float)$tot['tot_costo_tab'], 2, ',', '.') ?></b></div>
</div>

<h2 class="rsi-h2">Giorni lavorati per persona <span class="rsi-badge">v1.9.30 · Cognome Nome</span></h2>
<table class="rsi-tbl">
  <thead><tr>
    <th>Incaricato</th><th>Emp. ID</th>
    <th>Giornate</th><th>Ore tot</th><th>Media h/giorno</th>
    <th>Straord.</th><th>Reperib.</th><th>Costo DGB (€)</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rowsPersona as $r): ?>
    <tr>
      <td><?= h($r['operator_name']) ?: '—' ?></td>
      <td><?= h((string)($r['employee_id'] ?? '')) ?></td>
      <td><?= number_format((float)$r['giornate'], 0, ',', '.') ?></td>
      <td><?= number_format((float)$r['ore_tot'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['media_h_giorno'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['ore_straordinario'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['ore_reperibilita'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['costo_dgb'], 2, ',', '.') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2 class="rsi-h2">Riepilogo per Codice Contratto</h2>
<table class="rsi-tbl">
  <thead><tr>
    <th>Codice contratto</th><th>PM Project</th>
    <th>Ore ord.</th><th>Ore straord.</th><th>Ore reperib.</th>
    <th>Giorni-uomo</th>
    <th>Costo contratto (€)</th><th>TotCostoTab (€)</th>
  </tr></thead>
  <tbody>
  <?php $sO=$sS=$sR=$sGU=$sC1=$sC2=0; foreach ($rowsContract as $r): ?>
    <tr>
      <td><?= h($r['codice_contratto']) ?: '—' ?></td>
      <td><?= h((string)($r['pm_project_code'] ?? '')) ?></td>
      <td><?= number_format((float)$r['ore_ordinarie'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['ore_straordinario'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['ore_reperibilita'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['giorni_uomo'], 0, ',', '.') ?></td>
      <td><?= number_format((float)$r['costo_contratto'], 2, ',', '.') ?></td>
      <td><?= number_format((float)$r['tot_costo_tab'], 2, ',', '.') ?></td>
    </tr>
  <?php $sO+=(float)$r['ore_ordinarie'];$sS+=(float)$r['ore_straordinario'];$sR+=(float)$r['ore_reperibilita'];$sGU+=(int)$r['giorni_uomo'];$sC1+=(float)$r['costo_contratto'];$sC2+=(float)$r['tot_costo_tab']; endforeach; ?>
  </tbody>
  <tfoot><tr><td colspan="2">Totali</td>
    <td><?= number_format($sO,2,',','.') ?></td><td><?= number_format($sS,2,',','.') ?></td>
    <td><?= number_format($sR,2,',','.') ?></td><td><?= number_format($sGU,0,',','.') ?></td>
    <td><?= number_format($sC1,2,',','.') ?></td><td><?= number_format($sC2,2,',','.') ?></td>
  </tr></tfoot>
</table>

<h2 class="rsi-h2">Dettaglio per Commessa</h2>
<?php if (!$byContract): ?>
  <p style="color:#667085">Nessuna riga per i filtri selezionati.</p>
<?php else: foreach ($byContract as $cid => $rows):
    $first = $rows[0];
    $intestazione = implode(' | ', array_filter([$first['contract_code'], $first['code_x_installation'], $first['customer_name'], $first['contract_description']], fn($v) => $v !== null && $v !== ''));
    $tOre = array_sum(array_map(fn($r) => (float)$r['ore'], $rows));
    $tCC  = array_sum(array_map(fn($r) => (float)$r['costo_contratto'], $rows));
    $tTab = array_sum(array_map(fn($r) => (float)$r['tot_costo_tab'], $rows));
?>
  <div class="rsi-grp-head"><b><?= h($intestazione) ?></b>
    <?php if ($first['pm_project_code']): ?> · PM Project: <?= h($first['pm_project_code']) ?><?php endif; ?>
    · <?= count($rows) ?> righe · <?= number_format($tOre,2,',','.') ?>h · € <?= number_format($tTab,2,',','.') ?>
  </div>
  <table class="rsi-grp-tbl">
    <thead><tr><th>Data</th><th>Operatore</th><th>Ticket</th><th>Fascia</th><th>Regime</th><th>Ore</th><th>Costo contratto (€)</th><th>TotCostoTab (€)</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h((string)$r['report_date']) ?></td>
        <td><?= h($r['operator_name']) ?></td>
        <td><code><?= h((string)$r['ticket']) ?: '—' ?></code></td>
        <td><?= h($r['fascia']) ?></td>
        <td><?= h($r['regime']) ?></td>
        <td><?= number_format((float)$r['ore'],2,',','.') ?></td>
        <td><?= number_format((float)$r['costo_contratto'],2,',','.') ?></td>
        <td><?= number_format((float)$r['tot_costo_tab'],2,',','.') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="5">Totali commessa</td>
      <td><?= number_format($tOre,2,',','.') ?></td>
      <td><?= number_format($tCC,2,',','.') ?></td>
      <td><?= number_format($tTab,2,',','.') ?></td>
    </tr></tfoot>
  </table>
<?php endforeach; endif; ?>

<?php require_once('footer.php'); ?>
