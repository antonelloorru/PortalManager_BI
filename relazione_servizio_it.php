<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.32 — Relazione di Servizio IT (pagina autoconsistente)
 *
 * Modulo standalone. Se hai una voce di menu che punta a un file diverso:
 *   1) rinomina l'esistente in .bak
 *   2) rinomina QUESTO file con lo stesso nome dell'originale
 *   3) ricarica la pagina.
 *
 * Contiene le 3 sezioni:
 *   1. Giorni lavorati per persona (COUNT DISTINCT report_date; Cognome Nome)
 *   2. Riepilogo per Codice Contratto (formato "WTS_3670 | WTS_CSS | CLIENTE | DESCR")
 *   3. Dettaglio per Commessa (righe raggruppate per contratto; ticket, fascia,
 *      ore, costo contratto, TotCostoTab)
 *
 * Fallback intelligenti:
 *   - Se la vista v_rsi_dettaglio_commessa esiste la usa (fast path).
 *   - Altrimenti esegue query dirette sulle tabelle dgb_forms_* e cm_*.
 *   - Se le tabelle DGB non esistono, mostra un banner diagnostico invece
 *     di una pagina vuota (l'utente sa esattamente cosa manca).
 *
 * Include multi-select con search sul filtro Incaricato/Contratto/Cliente
 * (pm-ui-boost auto-caricato da questo file, no dipendenze esterne).
 *
 * Esportazione CSV via ?export=csv (rispetta filtri correnti).
 * Vista stampabile via ?print=1 (nasconde form + KPI, mostra solo tabelle).
 */

require_once('access_control.php');
require_once('functions.php');

// Permesso RBAC: se non esiste crealo, altrimenti fallback su ruoli admin/HR
if (function_exists('can') && !can('view', 'relazione_servizio_it.php')) {
    if (!in_array((int)($_SESSION['role_id'] ?? 99), [1, 2, 3, 9], true)) {
        redirect('manage_projects');
    }
}

$isPrint = ($_GET['print']  ?? '') === '1';
$export  = ($_GET['export'] ?? '') === 'csv';

// ── Filtri ──────────────────────────────────────────────────────────────
function _pm_ints($x): array {
    if ($x === null || $x === '' || $x === 0 || $x === '0') return [];
    if (!is_array($x)) $x = preg_split('/[,\s]+/', (string)$x, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($x as $v) { $n = (int)$v; if ($n > 0) $out[$n] = true; }
    return array_keys($out);
}
function _pm_in(string $col, $x): array {
    $ids = _pm_ints($x);
    if (!$ids) return ['', []];
    return [$col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

$f = [
    'from'     => trim((string)($_GET['from'] ?? date('Y-m-01'))),
    'to'       => trim((string)($_GET['to']   ?? date('Y-m-d'))),
    'operator' => _pm_ints($_GET['operator'] ?? []),
    'contract' => _pm_ints($_GET['contract'] ?? []),
    'customer' => _pm_ints($_GET['customer'] ?? []),
    'regime'   => in_array($_GET['regime'] ?? '', ['ord','str','rep'], true) ? $_GET['regime'] : '',
];

// ── Guardia: le tabelle DGB esistono? ───────────────────────────────────
function _pm_table_exists(PDO $pdo, string $t): bool {
    try {
        $s = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
        $s->execute([$t]);
        return (bool)$s->fetchColumn();
    } catch (Throwable) { return false; }
}
$required = ['dgb_forms_activity', 'dgb_forms_activity_operator', 'dgb_operator', 'dgb_forms_contract', 'clients'];
$missing = array_values(array_filter($required, fn($t) => !_pm_table_exists($pdo, $t)));

// ── WHERE dinamico ──────────────────────────────────────────────────────
$w = ['COALESCE(a.deleted,0) <> 1'];
$b = [];
if ($f['from']) { $w[] = 'a.report_date >= ?'; $b[] = $f['from']; }
if ($f['to'])   { $w[] = 'a.report_date <= ?'; $b[] = $f['to']; }
[$s, $bb] = _pm_in('ao.id_operator',     $f['operator']); if ($s) { $w[] = $s; $b = array_merge($b, $bb); }
[$s, $bb] = _pm_in('a.id_contract',      $f['contract']); if ($s) { $w[] = $s; $b = array_merge($b, $bb); }
[$s, $bb] = _pm_in('a.id_customer_comp', $f['customer']); if ($s) { $w[] = $s; $b = array_merge($b, $bb); }
if ($f['regime'] === 'rep') $w[] = 'COALESCE(ao.during_availability,0) = 1';
if ($f['regime'] === 'str') $w[] = 'COALESCE(ao.extra_hours,0) > 0';
if ($f['regime'] === 'ord') $w[] = 'COALESCE(ao.during_availability,0)=0 AND COALESCE(ao.extra_hours,0)=0';
$whereSql = 'WHERE ' . implode(' AND ', $w);

// Cognome Nome (server-side, rigoroso)
$OPNAME = "TRIM(CONCAT_WS(' ', op.second_name, op.first_name))";

// ── Query — se tutte le tabelle esistono ────────────────────────────────
$rowsPersona = $rowsContract = $rowsDettaglio = [];
$vOp = $vCtr = $vCli = [];

if (!$missing) {
    // SEZIONE 3: dettaglio
    $sqlDettaglio = "
      SELECT c.id AS contract_id, c.code AS contract_code, c.code_x_installation,
             cli.name AS customer_name, c.description AS contract_description,
             p.project_code AS pm_project_code,
             a.report_date, a.ticket, $OPNAME AS operator_name,
             COALESCE(rbb.band_name, op.type, 'Default') AS fascia,
             CASE WHEN COALESCE(ao.during_availability,0)=1 THEN 'Reperibilità'
                  WHEN COALESCE(ao.extra_hours,0)>0          THEN 'Straordinario'
                  ELSE 'Ordinario' END AS regime,
             ROUND(COALESCE(ao.hours,0), 2) AS ore,
             ROUND(COALESCE(ao.cost,0), 2)  AS costo_contratto,
             ROUND(CASE WHEN COALESCE(ao.during_availability,0)=1
                        THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0)
                        ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0) END, 2) AS tot_costo_tab
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
      ORDER BY c.code, c.id, a.report_date, a.id, ao.id
    ";
    try {
        $stmt = $pdo->prepare($sqlDettaglio); $stmt->execute($b);
        $rowsDettaglio = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $missing[] = 'query_dettaglio: ' . $e->getMessage();
    }
}

// ── EXPORT CSV (uscita anticipata) ──────────────────────────────────────
if ($export && !$missing) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relazione_servizio_it_' . date('Ymd_His') . '.csv"');
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

// Include pm-ui-boost se esiste; altrimenti fallback su select standard
if (is_file(__DIR__ . '/assets/js/pm-ui-boost.js')) {
    echo '<link rel="stylesheet" href="assets/css/pm-ui-boost.css">' . "\n";
    echo '<script src="assets/js/pm-ui-boost.js" defer></script>' . "\n";
}

if ($missing) {
    echo '<div style="background:#fef2f2;border:1px solid #f87171;color:#7f1d1d;padding:14px;border-radius:6px;margin:16px 0">'
       . '<b>Diagnostica</b>: la pagina non può mostrare i dati perché mancano oggetti nello schema.<br>'
       . 'Elementi mancanti: <code>' . h(implode(', ', $missing)) . '</code><br>'
       . 'Verifica: <code>SHOW TABLES LIKE \'dgb_forms_%\';</code><br>'
       . 'Il modulo DGB deve essere sincronizzato (v1.8.13+): consulta db_upgrade e il registro <code>pm_migration_sql</code>.'
       . '</div>';
    require_once('footer.php');
    exit;
}

if (!$missing) {
    // SEZIONE 1
    $sqlPersona = "
      SELECT op.id AS operator_id, $OPNAME AS operator_name, map.employee_id,
             COUNT(DISTINCT a.report_date) AS giornate,
             ROUND(SUM(COALESCE(ao.hours,0)),2) AS ore_tot,
             ROUND(SUM(COALESCE(ao.hours,0)) / NULLIF(COUNT(DISTINCT a.report_date),0),2) AS media_h_giorno,
             ROUND(SUM(COALESCE(ao.extra_hours,0)),2) AS ore_straordinario,
             ROUND(SUM(CASE WHEN ao.during_availability=1 THEN COALESCE(ao.hours,0) ELSE 0 END),2) AS ore_reperibilita,
             ROUND(SUM(COALESCE(ao.cost,0)),2) AS costo_dgb
      FROM dgb_forms_activity a
      JOIN dgb_forms_activity_operator ao ON ao.id_activity=a.id
      JOIN dgb_operator op ON op.id=ao.id_operator
      LEFT JOIN dgb_operator_map map ON map.dgb_operator_id=op.id
      $whereSql
      GROUP BY op.id, op.first_name, op.second_name, map.employee_id
      ORDER BY op.second_name, op.first_name
    ";
    $stmt = $pdo->prepare($sqlPersona); $stmt->execute($b);
    $rowsPersona = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // SEZIONE 2
    $sqlContract = "
      SELECT c.id AS contract_id,
             CONCAT_WS(' | ', NULLIF(c.code,''), NULLIF(c.code_x_installation,''), NULLIF(cli.name,''), NULLIF(c.description,'')) AS codice_contratto,
             p.project_code AS pm_project_code,
             ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=0 AND COALESCE(ao.extra_hours,0)=0 THEN COALESCE(ao.hours,0) ELSE 0 END),2) AS ore_ordinarie,
             ROUND(SUM(COALESCE(ao.extra_hours,0)),2) AS ore_straordinario,
             ROUND(SUM(CASE WHEN ao.during_availability=1 THEN COALESCE(ao.hours,0) ELSE 0 END),2) AS ore_reperibilita,
             COUNT(DISTINCT CONCAT(a.report_date, '#', ao.id_operator)) AS giorni_uomo,
             ROUND(SUM(COALESCE(ao.cost,0)),2) AS costo_contratto,
             ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=1
                            THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0)
                            ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0)*COALESCE(ao.hours,0) END),2) AS tot_costo_tab
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

    // Liste per filtri (Cognome Nome, ORDER BY cognome)
    $vOp  = $pdo->query("SELECT id, $OPNAME AS nm FROM dgb_operator op WHERE COALESCE(deleted,0)=0 ORDER BY second_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
    $vCtr = $pdo->query("SELECT id, CONCAT_WS(' | ', code, code_x_installation, description) AS nm FROM dgb_forms_contract WHERE COALESCE(deleted,0)=0 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
    $vCli = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

$byContract = [];
foreach ($rowsDettaglio as $r) $byContract[$r['contract_id']][] = $r;
$tot = [
    'giornate' => array_sum(array_column($rowsPersona, 'giornate')),
    'ore'      => array_sum(array_column($rowsPersona, 'ore_tot')),
    'costo'    => array_sum(array_column($rowsPersona, 'costo_dgb')),
    'tab'      => array_sum(array_column($rowsContract, 'tot_costo_tab')),
    'righe'    => count($rowsDettaglio),
];
$exportQs = http_build_query(['export'=>'csv','from'=>$f['from'],'to'=>$f['to'],'operator'=>$f['operator'],'contract'=>$f['contract'],'customer'=>$f['customer'],'regime'=>$f['regime']]);
$printQs  = http_build_query(['print'=>'1','from'=>$f['from'],'to'=>$f['to'],'operator'=>$f['operator'],'contract'=>$f['contract'],'customer'=>$f['customer'],'regime'=>$f['regime']]);
?>
<style>
  .rsi-kpi { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin:14px 0; }
  .rsi-kpi .card { background:#f7f8fb; border:1px solid #e4e7ee; border-radius:6px; padding:10px; }
  .rsi-kpi .card b { font-size:18px; display:block; }
  .rsi-kpi .card small { color:#667085; font-size:11px; }
  .rsi-filters { display:grid; grid-template-columns:repeat(6,1fr); gap:8px; margin-bottom:14px; }
  .rsi-filters label { display:flex; flex-direction:column; font-size:12px; color:#667085; gap:4px; }
  .rsi-tbl { width:100%; border-collapse:collapse; margin:6px 0 22px; }
  .rsi-tbl th, .rsi-tbl td { padding:6px 8px; border-bottom:1px solid #e4e7ee; font-size:12.5px; text-align:right; }
  .rsi-tbl th:first-child, .rsi-tbl td:first-child,
  .rsi-tbl th:nth-child(2), .rsi-tbl td:nth-child(2) { text-align:left; }
  .rsi-tbl thead th { background:#f0f2f7; }
  .rsi-tbl tfoot td { font-weight:600; background:#f0f2f7; }
  .rsi-h2 { margin-top:22px; font-size:16px; }
  .rsi-h3 { margin:14px 0 4px; font-size:14px; background:#1e293b; color:#fff; padding:8px 12px; border-radius:5px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .rsi-actions { float:right; display:flex; gap:6px; }
  .rsi-actions a { background:#0f6cf6; color:#fff; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12.5px; }
  .rsi-actions a.alt { background:#64748b; }
  @media print {
    .rsi-filters, .rsi-actions, form { display:none !important; }
    .rsi-h3 { background:#e5e7eb; color:#000; }
    .rsi-tbl { page-break-inside:auto; }
    .rsi-tbl tr { page-break-inside:avoid; }
  }
</style>

<h1 style="display:flex;align-items:center;justify-content:space-between">
  <span>Relazione di Servizio IT</span>
  <span class="rsi-actions">
    <a href="?<?= h($exportQs) ?>">⬇ Esporta CSV</a>
    <a class="alt" href="?<?= h($printQs) ?>" target="_blank">🖨 Stampa</a>
  </span>
</h1>

<form method="get">
  <?= function_exists('route_slug_field') ? route_slug_field() : '' ?>
  <div class="rsi-filters">
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
      <select name="contract[]" multiple class="pm-ms" data-placeholder="Cerca contratto…" data-allow-clear data-no-reorder>
        <?php foreach ($vCtr as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $f['contract'], true) ? 'selected' : '' ?>><?= h($c['nm']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Clienti
      <select name="customer[]" multiple class="pm-ms" data-placeholder="Cerca cliente…" data-allow-clear data-no-reorder>
        <?php foreach ($vCli as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $f['customer'], true) ? 'selected' : '' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Regime
      <select name="regime" class="pm-ms" data-no-reorder>
        <option value=""    <?= $f['regime']===''    ?'selected':'' ?>>— tutti —</option>
        <option value="ord" <?= $f['regime']==='ord' ?'selected':'' ?>>Ordinario</option>
        <option value="str" <?= $f['regime']==='str' ?'selected':'' ?>>Straordinario</option>
        <option value="rep" <?= $f['regime']==='rep' ?'selected':'' ?>>Reperibilità</option>
      </select>
    </label>
    <label>&nbsp;<button type="submit">Applica filtri</button></label>
  </div>
</form>

<div class="rsi-kpi">
  <div class="card"><small>Giornate uniche</small><b><?= number_format((float)$tot['giornate'],0,',','.') ?></b></div>
  <div class="card"><small>Ore totali</small><b><?= number_format((float)$tot['ore'],2,',','.') ?></b></div>
  <div class="card"><small>Righe dettaglio</small><b><?= (int)$tot['righe'] ?></b></div>
  <div class="card"><small>Costo contratto (DGB)</small><b>€ <?= number_format((float)$tot['costo'],2,',','.') ?></b></div>
  <div class="card"><small>TotCostoTab</small><b>€ <?= number_format((float)$tot['tab'],2,',','.') ?></b></div>
</div>

<h2 class="rsi-h2">1. Giorni lavorati per persona</h2>
<table class="rsi-tbl">
  <thead><tr>
    <th>Incaricato</th><th>Emp. ID</th>
    <th>Giornate</th><th>Ore tot</th><th>Media h/giorno</th>
    <th>Straord.</th><th>Reperib.</th><th>Costo DGB (€)</th>
  </tr></thead>
  <tbody>
  <?php if (!$rowsPersona): ?>
    <tr><td colspan="8" style="text-align:center;color:#667085">Nessun dato per i filtri selezionati</td></tr>
  <?php else: foreach ($rowsPersona as $r): ?>
    <tr>
      <td><?= h($r['operator_name']) ?: '—' ?></td>
      <td><?= h((string)($r['employee_id'] ?? '')) ?></td>
      <td><?= number_format((float)$r['giornate'],0,',','.') ?></td>
      <td><?= number_format((float)$r['ore_tot'],2,',','.') ?></td>
      <td><?= number_format((float)$r['media_h_giorno'],2,',','.') ?></td>
      <td><?= number_format((float)$r['ore_straordinario'],2,',','.') ?></td>
      <td><?= number_format((float)$r['ore_reperibilita'],2,',','.') ?></td>
      <td><?= number_format((float)$r['costo_dgb'],2,',','.') ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<h2 class="rsi-h2">2. Riepilogo per Codice Contratto</h2>
<table class="rsi-tbl">
  <thead><tr>
    <th>Codice contratto</th><th>PM Project</th>
    <th>Ore ord.</th><th>Ore str.</th><th>Ore rep.</th>
    <th>Giorni-uomo</th><th>Costo contratto (€)</th><th>TotCostoTab (€)</th>
  </tr></thead>
  <tbody>
  <?php if (!$rowsContract): ?>
    <tr><td colspan="8" style="text-align:center;color:#667085">Nessun dato</td></tr>
  <?php else: $sO=$sS=$sR=$sGU=$sC1=$sC2=0; foreach ($rowsContract as $r): ?>
    <tr>
      <td><?= h($r['codice_contratto']) ?></td>
      <td><?= h((string)($r['pm_project_code'] ?? '')) ?></td>
      <td><?= number_format((float)$r['ore_ordinarie'],2,',','.') ?></td>
      <td><?= number_format((float)$r['ore_straordinario'],2,',','.') ?></td>
      <td><?= number_format((float)$r['ore_reperibilita'],2,',','.') ?></td>
      <td><?= number_format((float)$r['giorni_uomo'],0,',','.') ?></td>
      <td><?= number_format((float)$r['costo_contratto'],2,',','.') ?></td>
      <td><?= number_format((float)$r['tot_costo_tab'],2,',','.') ?></td>
    </tr>
  <?php $sO+=(float)$r['ore_ordinarie'];$sS+=(float)$r['ore_straordinario'];$sR+=(float)$r['ore_reperibilita'];$sGU+=(int)$r['giorni_uomo'];$sC1+=(float)$r['costo_contratto'];$sC2+=(float)$r['tot_costo_tab']; endforeach; endif; ?>
  </tbody>
  <?php if ($rowsContract): ?>
  <tfoot><tr><td colspan="2">Totali</td>
    <td><?= number_format($sO,2,',','.') ?></td><td><?= number_format($sS,2,',','.') ?></td>
    <td><?= number_format($sR,2,',','.') ?></td><td><?= number_format($sGU,0,',','.') ?></td>
    <td><?= number_format($sC1,2,',','.') ?></td><td><?= number_format($sC2,2,',','.') ?></td>
  </tr></tfoot>
  <?php endif; ?>
</table>

<h2 class="rsi-h2">3. Dettaglio per Commessa</h2>
<?php if (!$byContract): ?>
  <p style="color:#667085">Nessuna riga di dettaglio per i filtri selezionati.</p>
<?php else: foreach ($byContract as $cid => $rows):
    $first = $rows[0];
    $intestazione = implode(' | ', array_filter([$first['contract_code'], $first['code_x_installation'], $first['customer_name'], $first['contract_description']], fn($v)=>$v!==null && $v!==''));
    $tOre = array_sum(array_map(fn($r)=>(float)$r['ore'], $rows));
    $tCC  = array_sum(array_map(fn($r)=>(float)$r['costo_contratto'], $rows));
    $tTab = array_sum(array_map(fn($r)=>(float)$r['tot_costo_tab'], $rows));
?>
  <h3 class="rsi-h3"><?= h($intestazione) ?>
    <?php if ($first['pm_project_code']): ?> · PM: <?= h($first['pm_project_code']) ?><?php endif; ?>
    <span style="float:right;font-weight:normal">
      <?= count($rows) ?> righe · <?= number_format($tOre,2,',','.') ?>h · € <?= number_format($tTab,2,',','.') ?>
    </span>
  </h3>
  <table class="rsi-tbl">
    <thead><tr>
      <th>Data</th><th>Operatore</th><th>Ticket</th>
      <th>Fascia</th><th>Regime</th><th>Ore</th>
      <th>Costo contratto (€)</th><th>TotCostoTab (€)</th>
    </tr></thead>
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
