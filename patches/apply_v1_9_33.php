<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.33 — Auto-patch it_service.php + ItServiceModel.php
 *
 * Aggiunge:
 *   1) Metodo ItServiceModel::dettaglioCommessa() — query dettaglio raggruppabile
 *      per contratto DGB con formato "CODICE | INSTALLAZIONE | CLIENTE | DESCR"
 *      + Ticket, Fascia, Ore, Costo contratto, TotCostoTab.
 *   2) In it_service.php: include CSS/JS pm-ui-boost + meta target (multi-select
 *      con search, senza Ctrl); class="pm-ms" ai <select>; chiamata
 *      dettaglioCommessa(); nuova sezione HTML "Dettaglio per Commessa"
 *      inserita prima di footer.php.
 *
 * Idempotente (marker PM_V1_9_33_APPLIED). Backup automatico + validazione
 * php -l + rollback su fallimento sintassi.
 *
 * Uso:
 *   php patches\apply_v1_9_33.php <path\portalmanager>
 *   php patches\apply_v1_9_33.php <path\portalmanager> --dry-run
 */

$dry = false; $root = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else $root = $a;
}
$root = $root ?: realpath(__DIR__ . '/..');
if (!is_dir($root)) { fwrite(STDERR, "[ERRORE] Root non trovata: $root\n"); exit(1); }

$fileView  = $root . DIRECTORY_SEPARATOR . 'it_service.php';
$fileModel = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'ItServiceModel.php';
$filePrint = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'it_service_print.php';

foreach ([$fileView, $fileModel] as $f) {
    if (!is_file($f)) { fwrite(STDERR, "[ERRORE] File non trovato: $f\n"); exit(1); }
}
$hasPrint = is_file($filePrint);
echo "→ Root:  $root\n→ View:  $fileView\n→ Model: $fileModel\n" . ($hasPrint ? "→ Print: $filePrint\n" : "→ Print: NOT FOUND (skip)\n") . "\n";

$srcView  = file_get_contents($fileView);
$srcModel = file_get_contents($fileModel);
$srcPrint = $hasPrint ? file_get_contents($filePrint) : '';
$newView  = $srcView;
$newModel = $srcModel;
$newPrint = $srcPrint;
$changes  = [];

// ═══════════════════════════════════════════════════════════════════════
// PATCH 1: ItServiceModel — aggiunge metodo dettaglioCommessa()
// ═══════════════════════════════════════════════════════════════════════
if (strpos($srcModel, 'PM_V1_9_33_APPLIED') === false) {
    $newMethod = <<<'PHP'

    /* [PM_V1_9_33_APPLIED] Dettaglio per Commessa (v1.9.33)
     * Righe puntuali raggruppabili per contratto DGB. Applica solo filtri
     * from/to/incaricato di $f (i più significativi per il dettaglio).
     */
    public function dettaglioCommessa(array $f): array
    {
        $w = ['COALESCE(a.deleted,0) <> 1'];
        $b = [];
        if (!empty($f['from'])) { $w[] = 'a.report_date >= ?'; $b[] = $f['from']; }
        if (!empty($f['to']))   { $w[] = 'a.report_date <= ?'; $b[] = $f['to']; }
        if (!empty($f['incaricato']) && is_array($f['incaricato'])) {
            $ph = implode(',', array_fill(0, count($f['incaricato']), '?'));
            $w[] = "(TRIM(CONCAT_WS(' ', op.first_name, op.second_name)) IN ($ph)
                   OR TRIM(CONCAT_WS(' ', op.second_name, op.first_name)) IN ($ph))";
            $b = array_merge($b, $f['incaricato'], $f['incaricato']);
        }
        $where = 'WHERE ' . implode(' AND ', $w);
        $sql = "
          SELECT c.id AS contract_id, c.code AS contract_code, c.code_x_installation,
                 cli.name AS customer_name, c.description AS contract_description,
                 p.project_code AS pm_project_code,
                 a.report_date, a.ticket,
                 TRIM(CONCAT_WS(' ', op.second_name, op.first_name)) AS operator_name,
                 COALESCE(rbb.band_name, op.type, 'Default') AS fascia,
                 CASE WHEN COALESCE(ao.during_availability,0)=1 THEN 'Reperibilità'
                      WHEN COALESCE(ao.extra_hours,0)>0          THEN 'Straordinario'
                      ELSE 'Ordinario' END AS regime,
                 ROUND(COALESCE(ao.hours,0),2) AS ore,
                 ROUND(COALESCE(ao.cost,0),2)  AS costo_contratto,
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
          $where
          ORDER BY c.code, c.id, a.report_date, a.id, ao.id
        ";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($b);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
PHP;

    // Inserisce il metodo prima dell'ULTIMA graffa chiusa (fine classe)
    if (preg_match('/(\})[\s]*\z/', $newModel, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[1][1];
        $newModel = substr($newModel, 0, $pos) . $newMethod . "\n" . substr($newModel, $pos);
        $changes[] = "MODEL: metodo dettaglioCommessa() aggiunto";
    } else {
        $changes[] = "MODEL: fine classe non individuata → skip (verifica manuale)";
    }
} else {
    $changes[] = "MODEL: già patchato (marker presente) → skip";
}

// ═══════════════════════════════════════════════════════════════════════
// PATCH 2: it_service.php — include, class pm-ms, chiamata metodo, sezione HTML
// ═══════════════════════════════════════════════════════════════════════
if (strpos($srcView, 'PM_V1_9_33_APPLIED') === false) {

    // 2A) include CSS/JS pm-ui-boost + meta target
    $includeBlock = <<<'PHP'

// [PM_V1_9_33_APPLIED] UI boost: multi-select con search (no Ctrl) + Cognome Nome
if (!isset($GLOBALS['__pm_ui_boost_v1933'])) {
    $GLOBALS['__pm_ui_boost_v1933'] = true;
    echo '<link rel="stylesheet" href="assets/css/pm-ui-boost.css">' . "\n";
    echo '<script src="assets/js/pm-ui-boost.js" defer></script>' . "\n";
    echo '<meta name="pm-ui-boost" content=\'form select[multiple], form select[name="ricavo"]\'>' . "\n";
}
PHP;
    if (preg_match('/(require_once\s*\(\s*[\'"]header\.php[\'"]\s*\)\s*;)/', $newView, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $newView = substr($newView, 0, $pos) . $includeBlock . substr($newView, $pos);
        $changes[] = "VIEW: include CSS/JS/meta pm-ui-boost inserito dopo require_once('header.php')";
    }

    // 2B) class="pm-ms" a tutti i <select> del form (ignora quelli già con pm-ms)
    $countMs = 0;
    $newView = preg_replace_callback(
        '/<select(\s+[^>]*?)(?<!class="pm-ms")>/i',
        function ($m) use (&$countMs) {
            $attrs = $m[1];
            if (strpos($attrs, 'class="pm-ms"') !== false || strpos($attrs, "class='pm-ms'") !== false) return $m[0];
            if (strpos($attrs, 'class=') !== false) {
                // Aggiunge pm-ms a class esistente
                $newAttrs = preg_replace('/class="([^"]*)"/', 'class="$1 pm-ms"', $attrs, 1);
                if ($newAttrs === $attrs) $newAttrs = preg_replace("/class='([^']*)'/", "class='$1 pm-ms'", $attrs, 1);
            } else {
                $newAttrs = $attrs . ' class="pm-ms"';
            }
            $countMs++;
            return '<select' . $newAttrs . '>';
        },
        $newView
    );
    $changes[] = "VIEW: class=\"pm-ms\" aggiunta a $countMs <select>";

    // 2C) chiamata a $it->dettaglioCommessa($f) nel try block
    if (preg_match('/(\$gRic\s*=\s*\$it->giorniRiconcilia\(\$f\)\s*;)/', $newView, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $call = "\n    // [PM_V1_9_33_APPLIED] Dettaglio per Commessa\n    \$dettCommessa = \$it->dettaglioCommessa(\$f);";
        $newView = substr($newView, 0, $pos) . $call . substr($newView, $pos);
        $changes[] = "VIEW: chiamata dettaglioCommessa() inserita dopo \$gRic";
    } elseif (preg_match('/(try\s*\{[\s\S]*?)(\}\s*catch\s*\()/', $newView, $m, PREG_OFFSET_CAPTURE)) {
        // Fallback: dentro il try prima del catch
        $pos = $m[2][1];
        $call = "    \$dettCommessa = \$it->dettaglioCommessa(\$f);\n    ";
        $newView = substr($newView, 0, $pos) . $call . substr($newView, $pos);
        $changes[] = "VIEW: chiamata dettaglioCommessa() inserita nel try (fallback)";
    }
    // Assicura variabile in ramo error (append al catch se possibile)
    if (preg_match('/(\$cQ2\s*=\s*\$gQ\s*=\s*\[\]\s*;\s*\$cRie2\s*=\s*\$gOp\s*=\s*\$gAr\s*=\s*\$gRic\s*=\s*\[\]\s*;)/', $newView, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $newView = substr($newView, 0, $pos) . "\n    \$dettCommessa = [];" . substr($newView, $pos);
        $changes[] = "VIEW: \$dettCommessa=[] aggiunto al ramo catch";
    }

    // 2D) sezione HTML "Dettaglio per Commessa" prima di require_once('footer.php')
    $section = <<<'HTML'

<?php // [PM_V1_9_33_APPLIED] Sezione: Dettaglio per Commessa (v1.9.33) ?>
<?php if (!empty($dettCommessa)):
    $__byC = [];
    foreach ($dettCommessa as $r) $__byC[$r['contract_id']][] = $r;
?>
<style>
  .rsi33-h2 { margin:22px 0 8px; font-size:16px; }
  .rsi33-badge { background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:999px; font-size:11px; }
  .rsi33-h3 { margin:14px 0 4px; font-size:13.5px; background:#1e293b; color:#fff; padding:8px 12px; border-radius:5px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .rsi33-tbl { width:100%; border-collapse:collapse; margin:4px 0 20px; }
  .rsi33-tbl th, .rsi33-tbl td { padding:5px 8px; border-bottom:1px solid #e4e7ee; font-size:12.5px; text-align:right; }
  .rsi33-tbl th:nth-child(-n+3), .rsi33-tbl td:nth-child(-n+3) { text-align:left; }
  .rsi33-tbl thead th { background:#f0f2f7; }
  .rsi33-tbl tfoot td { font-weight:600; background:#eef4ff; }
</style>
<h2 class="rsi33-h2">Dettaglio per Commessa <span class="rsi33-badge">v1.9.33</span></h2>
<?php foreach ($__byC as $cid => $rows):
    $first = $rows[0];
    $intest = implode(' | ', array_filter([
        $first['contract_code'], $first['code_x_installation'],
        $first['customer_name'], $first['contract_description']
    ], fn($v) => $v !== null && $v !== ''));
    $tOre = array_sum(array_map(fn($r)=>(float)$r['ore'], $rows));
    $tCC  = array_sum(array_map(fn($r)=>(float)$r['costo_contratto'], $rows));
    $tTab = array_sum(array_map(fn($r)=>(float)$r['tot_costo_tab'], $rows));
?>
  <h3 class="rsi33-h3"><?= h($intest) ?>
    <?php if ($first['pm_project_code']): ?> · PM: <?= h($first['pm_project_code']) ?><?php endif; ?>
    <span style="float:right;font-weight:normal">
      <?= count($rows) ?> righe · <?= number_format($tOre,2,',','.') ?>h · € <?= number_format($tTab,2,',','.') ?>
    </span>
  </h3>
  <table class="rsi33-tbl">
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
<?php endforeach; ?>
<?php endif; ?>

HTML;
    if (preg_match('/(<\?php\s+require_once\s*\(\s*[\'"]footer\.php[\'"]\s*\)\s*;\s*\?>)/', $newView, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $newView = substr($newView, 0, $pos) . $section . "\n" . substr($newView, $pos);
        $changes[] = "VIEW: sezione HTML 'Dettaglio per Commessa' inserita prima del footer";
    }
} else {
    $changes[] = "VIEW: già patchato (marker presente) → skip";
}

// ═══════════════════════════════════════════════════════════════════════
// PATCH 3: it_service_print.php — dettaglio commessa nel report di stampa
// ═══════════════════════════════════════════════════════════════════════
if ($hasPrint && strpos($srcPrint, 'PM_V1_9_33_APPLIED') === false) {
    // 3A) chiamata metodo dopo $gOp = $it->giorniOperatore($f);
    if (preg_match('/(\$gOp\s*=\s*\$it->giorniOperatore\(\$f\)\s*;)/', $newPrint, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $newPrint = substr($newPrint, 0, $pos) . "\n// [PM_V1_9_33_APPLIED]\n\$dettCommessa = \$it->dettaglioCommessa(\$f);" . substr($newPrint, $pos);
        $changes[] = "PRINT: chiamata dettaglioCommessa() inserita dopo \$gOp";
    }

    // 3B) sezione HTML stampa prima di </body>
    $printSection = <<<'HTML'

<?php if (!empty($dettCommessa)):
    $__byC = [];
    foreach ($dettCommessa as $r) $__byC[$r['contract_id']][] = $r;
?>
<style>
  .pr33-h2 { margin:18px 0 6px; font-size:14px; page-break-before:auto; }
  .pr33-h3 { margin:10px 0 3px; font-size:12px; background:#e5e7eb; color:#111; padding:6px 10px; border-radius:3px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .pr33-tbl { width:100%; border-collapse:collapse; margin:3px 0 12px; page-break-inside:avoid; }
  .pr33-tbl th, .pr33-tbl td { padding:3px 6px; border-bottom:1px solid #d0d5dd; font-size:10.5px; text-align:right; }
  .pr33-tbl th:nth-child(-n+3), .pr33-tbl td:nth-child(-n+3) { text-align:left; }
  .pr33-tbl thead th { background:#f0f2f7; }
  .pr33-tbl tfoot td { font-weight:600; background:#eef4ff; }
</style>
<h2 class="pr33-h2">Dettaglio per Commessa</h2>
<?php foreach ($__byC as $cid => $rows):
    $first = $rows[0];
    $intest = implode(' | ', array_filter([$first['contract_code'], $first['code_x_installation'], $first['customer_name'], $first['contract_description']], fn($v) => $v !== null && $v !== ''));
    $tOre = array_sum(array_map(fn($r)=>(float)$r['ore'], $rows));
    $tTab = array_sum(array_map(fn($r)=>(float)$r['tot_costo_tab'], $rows));
?>
  <h3 class="pr33-h3"><?= htmlspecialchars($intest, ENT_QUOTES, 'UTF-8') ?>
    <span style="float:right;font-weight:normal"><?= count($rows) ?> · <?= number_format($tOre,2,',','.') ?>h · € <?= number_format($tTab,2,',','.') ?></span></h3>
  <table class="pr33-tbl">
    <thead><tr><th>Data</th><th>Operatore</th><th>Ticket</th><th>Fascia</th><th>Regime</th><th>Ore</th><th>Costo (€)</th><th>Tab (€)</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars((string)$r['report_date'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($r['operator_name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)$r['ticket'], ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
        <td><?= htmlspecialchars($r['fascia'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($r['regime'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= number_format((float)$r['ore'],2,',','.') ?></td>
        <td><?= number_format((float)$r['costo_contratto'],2,',','.') ?></td>
        <td><?= number_format((float)$r['tot_costo_tab'],2,',','.') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>
<?php endif; ?>

HTML;
    if (preg_match('/(<\/body>)/', $newPrint, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1];
        $newPrint = substr($newPrint, 0, $pos) . $printSection . substr($newPrint, $pos);
        $changes[] = "PRINT: sezione HTML stampa 'Dettaglio per Commessa' inserita prima di </body>";
    }
} elseif ($hasPrint) {
    $changes[] = "PRINT: già patchato (marker presente) → skip";
}

// ═══════════════════════════════════════════════════════════════════════
// OUTPUT
// ═══════════════════════════════════════════════════════════════════════
echo "Modifiche:\n"; foreach ($changes as $c) echo "  - $c\n";

if ($newView === $srcView && $newModel === $srcModel && $newPrint === $srcPrint) { echo "\n[NO-OP] Nessuna modifica.\n"; exit(0); }
if ($dry) { echo "\n[DRY-RUN] File NON scritti.\n"; exit(0); }

$targets = [[$fileView, $srcView, $newView], [$fileModel, $srcModel, $newModel]];
if ($hasPrint) $targets[] = [$filePrint, $srcPrint, $newPrint];

// Backup + scrittura atomica + lint
foreach ($targets as [$path, $orig, $new]) {
    if ($new === $orig) continue;
    $bak = $path . '.bak_v1_9_33_' . date('Ymd_His');
    if (!copy($path, $bak)) { fwrite(STDERR, "[ERRORE] Backup fallito: $path\n"); exit(1); }
    echo "→ Backup: $bak\n";
    $tmp = $path . '.tmp_v1_9_33';
    if (file_put_contents($tmp, $new) === false) { fwrite(STDERR, "[ERRORE] Scrittura fallita: $path\n"); exit(1); }
    if (!rename($tmp, $path)) { fwrite(STDERR, "[ERRORE] Rename fallito: $path\n"); exit(1); }
    $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
    if (strpos((string)$lint, 'No syntax errors') === false) {
        fwrite(STDERR, "[ROLLBACK] Sintassi non valida su $path. Ripristino backup.\n$lint\n");
        copy($bak, $path);
        exit(2);
    }
}

echo "\n[OK] Patch v1.9.33 applicata. php -l pulito su entrambi i file.\n";
echo "\nProssimi passi:\n";
echo " 1) Copia assets/js/pm-ui-boost.js e assets/css/pm-ui-boost.css in webroot\n";
echo " 2) mysql -uroot portalmanager < sql/migration_v1_9_33.sql\n";
echo " 3) net stop Apache2.4 & net start Apache2.4\n";
exit(0);
