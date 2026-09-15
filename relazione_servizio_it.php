<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.41 — Relazione di Servizio IT (cruscotto performance personale)
 *
 * Target: Direttore IT. Raggruppamento PER PERSONA, quattro metriche:
 *   1. Giorni lavorati su commesse ATTIVE nel periodo   (COUNT DISTINCT giorno)
 *   2. Conteggio per Fascia professionale, focus C e D
 *   3. Area Tecnologica dai rapportini                  (linea/modello del contratto)
 *   4. Produzione attiva teorica                        (ore × tariffa di listino della fascia)
 *
 * Fonte dati: la vista SD canonica `v_cm_sd_moduli` (rapportini: costruita su
 * dgb_forms_activity + cm_intervention_reports, con _operator in LEFT JOIN, quindi
 * popolata anche con la tabella operatori vuota). Sostituisce le query dirette in
 * INNER JOIN su dgb_forms_activity_operator della versione precedente, che in
 * produzione (tabella vuota) restituivano 0 righe.
 *
 * Fascia professionale (A–F): risolta da `v_rsi_report_fascia` con le tre mappature
 * del portale in cascata — band_id del rapportino, alias cm_alias_band su band_raw,
 * catalogo tariffe di commessa — con traccia dell'origine.
 *
 * Listino: `cm_rate_band_rates.cost_type='Cliente'` (regime 'Ordinario').
 * Export CSV via ?export=csv, stampa via ?print=1 (rispettano i filtri correnti).
 */

require_once('access_control.php');
require_once('functions.php');

if (function_exists('can') && !can('view', 'relazione_servizio_it.php')) {
    if (!in_array((int)($_SESSION['role_id'] ?? 99), [1, 2, 3, 9], true)) {
        redirect('manage_projects');
    }
}

$isPrint = ($_GET['print']  ?? '') === '1';
$export  = ($_GET['export'] ?? '') === 'csv';

/* Stati di commessa considerati "attivi" per la metrica 1.
   Valori reali presenti a schema: APERTA / CHIUSA / SOSPESA.
   Assunzione corrente: attiva = APERTA. Per includere anche SOSPESA aggiungere
   'SOSPESA' a questa lista (unico punto da toccare). */
const RSI_STATI_ATTIVI = ['APERTA'];

/* ── Filtri ─────────────────────────────────────────────────────────────── */
function _pm_strs($x): array {
    if ($x === null || $x === '') return [];
    if (!is_array($x)) $x = [$x];
    $out = [];
    foreach ($x as $v) { $v = trim((string)$v); if ($v !== '') $out[$v] = true; }
    return array_keys($out);
}
$f = [
    'from'     => trim((string)($_GET['from'] ?? date('Y-m-01'))),
    'to'       => trim((string)($_GET['to']   ?? date('Y-m-d'))),
    'tecnico'  => _pm_strs($_GET['tecnico']  ?? []),
    'contract' => _pm_strs($_GET['contract'] ?? []),
];

function _pm_table_exists(PDO $pdo, string $t): bool {
    try {
        $s = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
        $s->execute([$t]);
        return (bool)$s->fetchColumn();
    } catch (Throwable) { return false; }
}
$required = ['v_cm_sd_moduli', 'v_rsi_report_fascia', 'cm_rate_bands', 'cm_rate_band_rates', 'cm_projects'];
$missing  = array_values(array_filter($required, fn($t) => !_pm_table_exists($pdo, $t)));

/* ── WHERE dinamico sui moduli ──────────────────────────────────────────── */
$w = ['1=1']; $b = [];
if ($f['from']) { $w[] = 'm.giorno >= ?'; $b[] = $f['from']; }
if ($f['to'])   { $w[] = 'm.giorno <= ?'; $b[] = $f['to']; }
if ($f['tecnico']) {
    $w[] = 'm.tecnico IN (' . implode(',', array_fill(0, count($f['tecnico']), '?')) . ')';
    $b   = array_merge($b, $f['tecnico']);
}
if ($f['contract']) {
    $w[] = 'm.contratto IN (' . implode(',', array_fill(0, count($f['contract']), '?')) . ')';
    $b   = array_merge($b, $f['contract']);
}
$whereSql = 'WHERE ' . implode(' AND ', $w);

/* Stati attivi come lista di placeholder (per COUNT condizionale) */
$statiPh = implode(',', array_fill(0, count(RSI_STATI_ATTIVI), '?'));

/* ── Query per persona ──────────────────────────────────────────────────── */
$rows = []; $vTec = []; $vCtr = []; $perFascia = []; $rowsCommessa = [];
if (!$missing) {
    $sql = "
      SELECT
        m.tecnico,
        COUNT(DISTINCT CASE WHEN UPPER(COALESCE(p.operational_status,'')) IN ($statiPh)
                            THEN m.giorno END)                              AS giorni_attivi,
        COUNT(DISTINCT m.report_id)                                         AS interventi,
        COALESCE(SUM(rf.fascia = 'C'),0)                                    AS int_c,
        COALESCE(SUM(rf.fascia = 'D'),0)                                    AS int_d,
        COALESCE(SUM(rf.fascia IS NOT NULL AND rf.fascia NOT IN ('C','D')),0) AS int_altre,
        COALESCE(SUM(rf.fascia IS NULL),0)                                  AS int_nd,
        GROUP_CONCAT(DISTINCT COALESCE(NULLIF(m.modello,''), m.codice_linea)
                     ORDER BY 1 SEPARATOR ', ')                             AS aree_tec,
        ROUND(SUM(m.ore), 2)                                                AS ore_tot,
        ROUND(SUM(CASE WHEN UPPER(COALESCE(p.operational_status,'')) IN ($statiPh)
                       THEN m.ore * COALESCE(rc.rate_hour,0) ELSE 0 END), 2) AS prod_teorica,
        ROUND(SUM(CASE WHEN rf.fascia IS NULL THEN m.ore ELSE 0 END), 2)    AS ore_senza_fascia
      FROM v_cm_sd_moduli m
      LEFT JOIN v_rsi_report_fascia rf ON rf.report_id = m.report_id
      LEFT JOIN cm_rate_bands rb       ON rb.band_name = rf.fascia_etichetta
      LEFT JOIN cm_rate_band_rates rc  ON rc.band_id = rb.id
                                      AND rc.cost_type = 'Cliente' AND rc.regime = 'Ordinario'
      LEFT JOIN cm_projects p          ON p.project_code = m.commessa
      $whereSql
      GROUP BY m.tecnico
      ORDER BY giorni_attivi DESC, m.tecnico
    ";
    try {
        $st = $pdo->prepare($sql);
        // due gruppi di placeholder per gli stati attivi (giorni_attivi + prod_teorica), poi i filtri WHERE
        $st->execute(array_merge(RSI_STATI_ATTIVI, RSI_STATI_ATTIVI, $b));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $missing[] = 'query_persona: ' . $e->getMessage(); }

    /* Dettaglio conteggio per (persona, fascia) — per la seconda tabella */
    if (!$missing) {
        $sqlF = "
          SELECT m.tecnico,
                 COALESCE(rf.fascia, 'N/D') AS fascia,
                 COUNT(DISTINCT m.report_id) AS interventi,
                 ROUND(SUM(m.ore), 2)        AS ore
          FROM v_cm_sd_moduli m
          LEFT JOIN v_rsi_report_fascia rf ON rf.report_id = m.report_id
          $whereSql
          GROUP BY m.tecnico, COALESCE(rf.fascia, 'N/D')
          ORDER BY m.tecnico, fascia
        ";
        try {
            $st = $pdo->prepare($sqlF); $st->execute($b);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $perFascia[$r['tecnico']][$r['fascia']] = $r;
        } catch (Throwable $e) { /* la tabella principale resta valida */ }

        /* Liste filtri, dai moduli nel periodo */
        try {
            $vTec = $pdo->query("SELECT DISTINCT tecnico FROM v_cm_sd_moduli WHERE tecnico IS NOT NULL AND tecnico<>'' ORDER BY tecnico")->fetchAll(PDO::FETCH_COLUMN);
            $vCtr = $pdo->query("SELECT DISTINCT contratto FROM v_cm_sd_moduli WHERE contratto IS NOT NULL AND contratto<>'' ORDER BY contratto")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) { $vTec = $vCtr = []; }

        /* Riepilogo per Codice Contratto -> dettaglio per commessa.
           Ripristina la sezione persa nel refactor v1.9.40 usando la sorgente
           corretta (v_cm_sd_moduli): la versione storica faceva INNER JOIN sulla
           tabella operatori vuota e non mostrava alcuna commessa. */
        try {
            $sqlC = "
              SELECT m.contratto,
                     m.commessa,
                     COALESCE(NULLIF(m.modello,''), m.codice_linea) AS area,
                     COUNT(DISTINCT m.report_id)                    AS interventi,
                     COUNT(DISTINCT CONCAT(m.giorno,'#',m.tecnico)) AS giorni_uomo,
                     ROUND(SUM(m.ore), 2)                           AS ore,
                     ROUND(SUM(m.ore_extra), 2)                     AS ore_extra,
                     ROUND(SUM(m.ore * COALESCE(rc.rate_hour,0)), 2) AS prod_teorica
              FROM v_cm_sd_moduli m
              LEFT JOIN v_rsi_report_fascia rf ON rf.report_id = m.report_id
              LEFT JOIN cm_rate_bands rb       ON rb.band_name = rf.fascia_etichetta
              LEFT JOIN cm_rate_band_rates rc  ON rc.band_id = rb.id
                                              AND rc.cost_type='Cliente' AND rc.regime='Ordinario'
              $whereSql
              GROUP BY m.contratto, m.commessa, area
              ORDER BY m.contratto, ore DESC
            ";
            $stC = $pdo->prepare($sqlC); $stC->execute($b);
            foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rowsCommessa[$r['contratto'] === null || $r['contratto']==='' ? '(senza contratto)' : $r['contratto']][] = $r;
        } catch (Throwable $e) { $rowsCommessa = []; }
    }
}

/* ── EXPORT CSV ─────────────────────────────────────────────────────────── */
if ($export && !$missing) {
    while (ob_get_level()) ob_end_clean();
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relazione_servizio_it_' . date('Ymd_His') . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Tecnico','Giorni su commesse attive','Interventi','Fascia C','Fascia D',
                  'Altre fasce','Fascia N/D','Aree tecnologiche','Ore totali',
                  'Produzione teorica (listino)','Ore senza fascia'], ';');
    foreach ($rows as $r) fputcsv($fh, [
        $r['tecnico'], (int)$r['giorni_attivi'], (int)$r['interventi'],
        (int)$r['int_c'], (int)$r['int_d'], (int)$r['int_altre'], (int)$r['int_nd'],
        $r['aree_tec'],
        number_format((float)$r['ore_tot'], 2, ',', ''),
        number_format((float)$r['prod_teorica'], 2, ',', ''),
        number_format((float)$r['ore_senza_fascia'], 2, ',', ''),
    ], ';');
    fclose($fh);
    exit;
}

require_once('header.php');
if (is_file(__DIR__ . '/assets/js/pm-ui-boost.js')) {
    echo '<link rel="stylesheet" href="assets/css/pm-ui-boost.css">' . "\n";
    echo '<script src="assets/js/pm-ui-boost.js" defer></script>' . "\n";
}

/* Totali per il quadro */
$T = ['giorni'=>0,'interventi'=>0,'c'=>0,'d'=>0,'nd'=>0,'ore'=>0,'prod'=>0];
foreach ($rows as $r) {
    $T['giorni'] += (int)$r['giorni_attivi']; $T['interventi'] += (int)$r['interventi'];
    $T['c'] += (int)$r['int_c']; $T['d'] += (int)$r['int_d']; $T['nd'] += (int)$r['int_nd'];
    $T['ore'] += (float)$r['ore_tot']; $T['prod'] += (float)$r['prod_teorica'];
}
$n  = fn($v) => number_format((float)$v, 0, ',', '.');
$n2 = fn($v) => number_format((float)$v, 2, ',', '.');
$qs = fn(array $ov) => '?' . http_build_query(array_merge(
    ['from'=>$f['from'],'to'=>$f['to'],'tecnico'=>$f['tecnico'],'contract'=>$f['contract']], $ov));
?>
<style>
  .rsi-kpi { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin:14px 0; }
  .rsi-kpi .card { background:#f7f8fb; border:1px solid #e4e7ee; border-radius:6px; padding:10px; text-align:center; }
  .rsi-kpi .card b { font-size:18px; display:block; }
  .rsi-kpi .card small { color:#667085; font-size:11px; }
  .rsi-filters { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:14px; align-items:end; }
  .rsi-filters label { display:flex; flex-direction:column; font-size:12px; color:#667085; gap:4px; }
  .rsi-tbl { width:100%; border-collapse:collapse; margin:6px 0 22px; }
  .rsi-tbl th, .rsi-tbl td { padding:6px 8px; border-bottom:1px solid #e4e7ee; font-size:12.5px; text-align:right; }
  .rsi-tbl th:first-child, .rsi-tbl td:first-child { text-align:left; }
  .rsi-tbl td.area { text-align:left; color:#475569; font-size:11.5px; }
  .rsi-tbl thead th { background:#f0f2f7; position:sticky; top:0; }
  .rsi-tbl tfoot td { font-weight:600; background:#f0f2f7; }
  .rsi-cd { font-weight:700; }
  .rsi-c  { color:#0f766e; } .rsi-d { color:#b45309; }
  .rsi-nd { color:#94a3b8; }
  .rsi-h2 { margin-top:22px; font-size:16px; }
  .rsi-actions { float:right; display:flex; gap:6px; }
  .rsi-actions a { background:#0f6cf6; color:#fff; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:12.5px; }
  .rsi-actions a.alt { background:#64748b; }
  .rsi-note { font-size:11.5px; color:#667085; margin:4px 0 14px; }
  .rsi-badge { font-size:9px; padding:1px 6px; border-radius:8px; background:#e2e8f0; color:#334155; }
  @media print { .rsi-filters, .rsi-actions, form { display:none !important; } .rsi-tbl tr { page-break-inside:avoid; } }
</style>

<h1 style="display:flex;align-items:center;justify-content:space-between">
  <span>Relazione di Servizio IT — Performance personale</span>
  <span class="rsi-actions">
    <a href="<?= h($qs(['export'=>'csv'])) ?>">⬇ Esporta CSV</a>
    <a class="alt" target="_blank" href="<?= h($qs(['print'=>'1'])) ?>">🖨 Stampa</a>
  </span>
</h1>

<?php if ($missing): ?>
  <div style="background:#fef2f2;border:1px solid #f87171;color:#7f1d1d;padding:14px;border-radius:6px;margin:16px 0">
    <b>Diagnostica</b>: mancano oggetti nello schema: <code><?= h(implode(', ', $missing)) ?></code>.<br>
    Applicare la migrazione della release (crea/riallinea <code>v_rsi_report_fascia</code>) e verificare
    che le viste SD (<code>v_cm_sd_moduli</code>) siano presenti.
  </div>
  <?php require_once('footer.php'); exit; ?>
<?php endif; ?>

<form method="get">
  <?= function_exists('route_slug_field') ? route_slug_field() : '' ?>
  <div class="rsi-filters">
    <label>Dal <input type="date" name="from" value="<?= h($f['from']) ?>"></label>
    <label>Al  <input type="date" name="to"   value="<?= h($f['to']) ?>"></label>
    <label>Tecnici
      <select name="tecnico[]" multiple class="pm-ms" data-placeholder="Cerca tecnico…" data-allow-clear>
        <?php foreach ($vTec as $t): ?>
          <option value="<?= h($t) ?>" <?= in_array($t, $f['tecnico'], true) ? 'selected' : '' ?>><?= h($t) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Contratti
      <select name="contract[]" multiple class="pm-ms" data-placeholder="Cerca contratto…" data-allow-clear data-no-reorder>
        <?php foreach ($vCtr as $c): ?>
          <option value="<?= h($c) ?>" <?= in_array($c, $f['contract'], true) ? 'selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <div><button class="btn btn-primary btn-sm">Applica</button>
       <a class="btn btn-sm" href="?">Azzera</a></div>
</form>

<div class="rsi-kpi">
  <div class="card"><b><?= $n(count($rows)) ?></b><small>Tecnici</small></div>
  <div class="card"><b><?= $n($T['giorni']) ?></b><small>Giorni su commesse attive</small></div>
  <div class="card"><b><?= $n($T['interventi']) ?></b><small>Interventi</small></div>
  <div class="card"><b class="rsi-c"><?= $n($T['c']) ?></b><small>Interventi Fascia C</small></div>
  <div class="card"><b class="rsi-d"><?= $n($T['d']) ?></b><small>Interventi Fascia D</small></div>
  <div class="card"><b><?= $n2($T['prod']) ?> €</b><small>Produzione teorica</small></div>
</div>
<p class="rsi-note">
  Giorni su commesse attive = giornate distinte su commesse in stato
  <?= h(implode('/', RSI_STATI_ATTIVI)) ?>. Produzione teorica = ore × tariffa di listino
  della fascia (<code>cost_type='Cliente'</code>). Gli interventi senza fascia risolta
  (<span class="rsi-nd">N/D</span>) non producono valore teorico:
  <b><?= $n($T['nd']) ?></b> interventi.
</p>

<h2 class="rsi-h2">Performance per persona</h2>
<?php if (!$rows): ?>
  <p class="rsi-note">Nessun dato nel periodo/filtri selezionati.</p>
<?php else: ?>
<table class="rsi-tbl">
  <thead><tr>
    <th>Tecnico</th><th>Giorni attivi</th><th>Interventi</th>
    <th class="rsi-c">Fascia C</th><th class="rsi-d">Fascia D</th><th>Altre</th><th>N/D</th>
    <th>Area tecnologica</th><th>Ore</th><th>Produzione teorica</th>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['tecnico']) ?></td>
      <td><?= $n($r['giorni_attivi']) ?></td>
      <td><?= $n($r['interventi']) ?></td>
      <td class="rsi-cd rsi-c"><?= $n($r['int_c']) ?></td>
      <td class="rsi-cd rsi-d"><?= $n($r['int_d']) ?></td>
      <td><?= $n($r['int_altre']) ?></td>
      <td class="rsi-nd"><?= $n($r['int_nd']) ?></td>
      <td class="area"><?= h($r['aree_tec'] ?? '—') ?></td>
      <td><?= $n2($r['ore_tot']) ?></td>
      <td><?= $n2($r['prod_teorica']) ?> €</td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot><tr>
    <td>Totale</td><td><?= $n($T['giorni']) ?></td><td><?= $n($T['interventi']) ?></td>
    <td class="rsi-c"><?= $n($T['c']) ?></td><td class="rsi-d"><?= $n($T['d']) ?></td>
    <td></td><td><?= $n($T['nd']) ?></td><td></td>
    <td><?= $n2($T['ore']) ?></td><td><?= $n2($T['prod']) ?> €</td>
  </tr></tfoot>
</table>

<h2 class="rsi-h2">Conteggio per Fascia (dettaglio)</h2>
<p class="rsi-note">Interventi e ore per fascia professionale, per persona. Focus su C e D.</p>
<table class="rsi-tbl">
  <thead><tr><th>Tecnico</th>
    <?php $fasceCol = ['A','B','C','D','E','F','N/D']; foreach ($fasceCol as $fc): ?>
      <th class="<?= $fc==='C'?'rsi-c':($fc==='D'?'rsi-d':($fc==='N/D'?'rsi-nd':'')) ?>"><?= h($fc) ?></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $r): $tn=$r['tecnico']; ?>
      <tr><td><?= h($tn) ?></td>
        <?php foreach ($fasceCol as $fc):
          $cell = $perFascia[$tn][$fc] ?? null;
          $cls  = $fc==='C'?'rsi-c':($fc==='D'?'rsi-d':($fc==='N/D'?'rsi-nd':'')); ?>
          <td class="<?= $cls ?>"><?= $cell ? $n($cell['interventi']) . '<span class="rsi-badge">' . $n2($cell['ore']) . 'h</span>' : '—' ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<p class="rsi-note">
  Fascia risolta con tre fonti in cascata (origine tracciata in <code>v_rsi_report_fascia</code>):
  <span class="rsi-badge">report</span> band del rapportino ·
  <span class="rsi-badge">alias</span> normalizzazione <code>band_raw</code> ·
  <span class="rsi-badge">catalogo</span> fascia unica di listino della commessa.
  Dove nessuna fonte risolve, la fascia è <span class="rsi-nd">N/D</span> (non inventata).
</p>
<?php endif; ?>

<h2 class="rsi-h2">Riepilogo per Codice Contratto</h2>
<p class="rsi-note">Dettaglio per commessa: interventi, giorni-uomo, ore e produzione teorica a listino.</p>
<?php if (!$rowsCommessa): ?>
  <p class="rsi-note">Nessun rapportino nel periodo/filtri selezionati.</p>
<?php else: ?>
<table class="rsi-tbl">
  <thead><tr><th>Contratto / Commessa</th><th>Area tecnologica</th><th>Interventi</th>
    <th>Giorni-uomo</th><th>Ore</th><th>Ore extra</th><th>Produzione teorica</th></tr></thead>
  <tbody>
  <?php foreach ($rowsCommessa as $contr => $cs):
    $sI=$sGu=0; $sO=$sOe=$sP=0.0;
    foreach ($cs as $c){ $sI+=(int)$c['interventi']; $sGu+=(int)$c['giorni_uomo'];
      $sO+=(float)$c['ore']; $sOe+=(float)$c['ore_extra']; $sP+=(float)$c['prod_teorica']; } ?>
    <tr style="background:#eef2f7;font-weight:600">
      <td style="font-family:ui-monospace,Menlo,monospace"><?= h($contr) ?></td>
      <td></td>
      <td><?= $n($sI) ?></td><td><?= $n($sGu) ?></td>
      <td><?= $n2($sO) ?></td><td><?= $n2($sOe) ?></td><td><?= $n2($sP) ?> &euro;</td>
    </tr>
    <?php foreach ($cs as $c): ?>
      <tr>
        <td style="padding-left:22px"><?= h(($c['commessa'] ?? '') !== '' ? $c['commessa'] : '—') ?></td>
        <td class="area"><?= h($c['area'] ?? '—') ?></td>
        <td><?= $n($c['interventi']) ?></td>
        <td><?= $n($c['giorni_uomo']) ?></td>
        <td><?= $n2($c['ore']) ?></td>
        <td><?= $n2($c['ore_extra']) ?></td>
        <td><?= $n2($c['prod_teorica']) ?> &euro;</td>
      </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<?php require_once('footer.php'); ?>
