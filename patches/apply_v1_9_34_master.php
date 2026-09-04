<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.34 — MASTER PATCH: Service Desk + Relazione di Servizio IT
 *
 * Patcha in-place tutti i file coinvolti (idempotente):
 *   1) it_service.php                     — view Relazione di Servizio IT
 *   2) app/ItServiceModel.php             — model (aggiunge dettaglioCommessa)
 *   3) app/it_service_print.php           — vista stampa (dettaglio commessa)
 *   4) service_desk.php                   — view Service Desk (pm-ms sui filtri)
 *
 * Modifiche unificate:
 *   - Filtri: class="pm-ms" a ogni <select> del form → multi-select con search,
 *     click semplice per selezionare (senza Ctrl).
 *   - Include CSS/JS pm-ui-boost + <meta name="pm-ui-boost"> target.
 *   - Cognome Nome nelle etichette (heuristica client-side + fallback server).
 *   - Sezione "Dettaglio per Commessa" in it_service.php e stampa.
 *
 * Backup automatico timestampato prima di ogni scrittura.
 * php -l post-patch con rollback su fallimento.
 * Marker PM_V1_9_34_APPLIED per idempotenza.
 *
 * Uso:
 *   php patches\apply_v1_9_34_master.php <path\portalmanager>
 *   php patches\apply_v1_9_34_master.php <path\portalmanager> --dry-run
 *   php patches\apply_v1_9_34_master.php <path\portalmanager> --only=service_desk
 *   php patches\apply_v1_9_34_master.php <path\portalmanager> --only=it_service
 */

$dry = false; $root = null; $only = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif (preg_match('/^--only=(.+)$/', $a, $m)) $only = $m[1];
    else $root = $a;
}
$root = $root ?: realpath(__DIR__ . '/..');
if (!is_dir($root)) { fwrite(STDERR, "[ERRORE] Root non trovata: $root\n"); exit(1); }

echo "══════════════════════════════════════════════════════════════════\n";
echo "  PortalManager v1.9.34 — Master Patch (Service Desk + IT Service)\n";
echo "══════════════════════════════════════════════════════════════════\n";
echo "→ Root: $root\n";
echo "→ Modalità: " . ($dry ? "DRY-RUN" : "APPLICA") . ($only ? " · SOLO $only" : "") . "\n\n";

$MARKER = 'PM_V1_9_34_APPLIED';
$fatti  = [];
$errori = [];

// ═══════════════════════════════════════════════════════════════════════
// SUBROUTINE: patch generica di un singolo file
// ═══════════════════════════════════════════════════════════════════════
function pm_patch(string $path, callable $transform, string $marker, bool $dry): string {
    global $fatti, $errori;
    if (!is_file($path)) return "SKIP (file non trovato): $path";
    $src = file_get_contents($path);
    if ($src === false) { $errori[] = "Lettura fallita: $path"; return "ERRORE lettura"; }
    if (strpos($src, $marker) !== false) return "SKIP (già patchato): " . basename($path);

    $out = ['changes' => [], 'src' => $src];
    $new = $transform($src, $out);
    $changes = $out['changes'];
    if ($new === $src) return "NO-OP: " . basename($path);

    if ($dry) return "DRY-RUN: " . basename($path) . " (" . count($changes) . " modifiche)";

    $bak = $path . '.bak_v1_9_34_' . date('Ymd_His');
    if (!copy($path, $bak)) { $errori[] = "Backup fallito: $path"; return "ERRORE backup"; }
    $tmp = $path . '.tmp_v1_9_34';
    if (file_put_contents($tmp, $new) === false) { $errori[] = "Scrittura fallita: $path"; return "ERRORE scrittura"; }
    if (!rename($tmp, $path)) { $errori[] = "Rename fallito: $path"; return "ERRORE rename"; }

    if (substr($path, -4) === '.php') {
        $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        if (strpos((string)$lint, 'No syntax errors') === false) {
            copy($bak, $path);
            $errori[] = "Lint fallito, rollback: $path\n$lint";
            return "ROLLBACK (sintassi non valida)";
        }
    }
    $fatti[] = $path;
    return "OK — " . basename($path) . " (" . count($changes) . " modifiche) · backup: " . basename($bak);
}

// ═══════════════════════════════════════════════════════════════════════
// PATCH A — it_service.php (view + model + print)
// ═══════════════════════════════════════════════════════════════════════
if (!$only || $only === 'it_service') {
    echo "── it_service.php + ItServiceModel.php + it_service_print.php ──\n";

    // A1 — ItServiceModel: aggiungi metodo dettaglioCommessa()
    $newMethod = <<<'PHP'

    /* [PM_V1_9_34_APPLIED] Dettaglio per Commessa (righe puntuali x contratto DGB) */
    public function dettaglioCommessa(array $f): array
    {
        $w = ['COALESCE(a.deleted,0) <> 1'];
        $b = [];
        if (!empty($f['from'])) { $w[] = 'a.report_date >= ?'; $b[] = $f['from']; }
        if (!empty($f['to']))   { $w[] = 'a.report_date <= ?'; $b[] = $f['to']; }
        if (!empty($f['incaricati']) && is_array($f['incaricati'])) {
            $ph = implode(',', array_fill(0, count($f['incaricati']), '?'));
            $w[] = "(TRIM(CONCAT_WS(' ', op.first_name, op.second_name)) IN ($ph)
                   OR TRIM(CONCAT_WS(' ', op.second_name, op.first_name)) IN ($ph))";
            $b = array_merge($b, $f['incaricati'], $f['incaricati']);
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

    echo pm_patch("$root/app/ItServiceModel.php", function ($src, &$out) use ($newMethod) {
        if (preg_match('/(\})[\s]*\z/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[1][1];
            $out['changes'][] = 'aggiunto dettaglioCommessa()';
            return substr($src, 0, $pos) . $newMethod . "\n" . substr($src, $pos);
        }
        return $src;
    }, 'PM_V1_9_34_APPLIED', $dry) . "\n";

    // A2 — it_service.php: include + pm-ms + chiamata + sezione
    $includeIT = <<<'PHP'

// [PM_V1_9_34_APPLIED] pm-ui-boost: multi-select con search (no Ctrl) + Cognome Nome
if (!isset($GLOBALS['__pm_boost_v1934'])) {
    $GLOBALS['__pm_boost_v1934'] = true;
    echo '<link rel="stylesheet" href="assets/css/pm-ui-boost.css">' . "\n";
    echo '<script src="assets/js/pm-ui-boost.js" defer></script>' . "\n";
    echo '<meta name="pm-ui-boost" content=\'form select[multiple], form select[name="ricavo"]\'>' . "\n";
}
PHP;
    $sectionIT = <<<'HTML'

<?php // [PM_V1_9_34_APPLIED] Sezione Dettaglio per Commessa ?>
<?php if (!empty($dettCommessa)):
    $__byC = [];
    foreach ($dettCommessa as $r) $__byC[$r['contract_id']][] = $r;
?>
<style>
  .rsi34-h2 { margin:22px 0 8px; font-size:16px; }
  .rsi34-badge { background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:999px; font-size:11px; }
  .rsi34-h3 { margin:14px 0 4px; font-size:13.5px; background:#1e293b; color:#fff; padding:8px 12px; border-radius:5px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .rsi34-tbl { width:100%; border-collapse:collapse; margin:4px 0 20px; }
  .rsi34-tbl th, .rsi34-tbl td { padding:5px 8px; border-bottom:1px solid #e4e7ee; font-size:12.5px; text-align:right; }
  .rsi34-tbl th:nth-child(-n+3), .rsi34-tbl td:nth-child(-n+3) { text-align:left; }
  .rsi34-tbl thead th { background:#f0f2f7; }
  .rsi34-tbl tfoot td { font-weight:600; background:#eef4ff; }
</style>
<h2 class="rsi34-h2">Dettaglio per Commessa <span class="rsi34-badge">v1.9.34</span></h2>
<?php foreach ($__byC as $cid => $rows):
    $first = $rows[0];
    $intest = implode(' | ', array_filter([$first['contract_code'], $first['code_x_installation'], $first['customer_name'], $first['contract_description']], fn($v)=>$v!==null && $v!==''));
    $tOre = array_sum(array_map(fn($r)=>(float)$r['ore'], $rows));
    $tCC  = array_sum(array_map(fn($r)=>(float)$r['costo_contratto'], $rows));
    $tTab = array_sum(array_map(fn($r)=>(float)$r['tot_costo_tab'], $rows));
?>
  <h3 class="rsi34-h3"><?= h($intest) ?>
    <?php if ($first['pm_project_code']): ?> · PM: <?= h($first['pm_project_code']) ?><?php endif; ?>
    <span style="float:right;font-weight:normal"><?= count($rows) ?> righe · <?= number_format($tOre,2,',','.') ?>h · € <?= number_format($tTab,2,',','.') ?></span>
  </h3>
  <table class="rsi34-tbl">
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
<?php endforeach; ?>
<?php endif; ?>

HTML;
    echo pm_patch("$root/it_service.php", function ($src, &$out) use ($includeIT, $sectionIT) {
        $new = $src;
        // include dopo header
        if (preg_match('/(require_once\s*\(\s*[\'"]header\.php[\'"]\s*\)\s*;)/', $new, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $new = substr($new, 0, $pos) . $includeIT . substr($new, $pos);
            $out['changes'][] = 'include pm-ui-boost';
        }
        // class="pm-ms" sui select
        $cnt = 0;
        $new = preg_replace_callback('/<select(\s+[^>]*?)>/i', function ($m) use (&$cnt) {
            $a = $m[1];
            if (strpos($a, 'pm-ms') !== false) return $m[0];
            if (strpos($a, 'class=') !== false) {
                $na = preg_replace('/class="([^"]*)"/', 'class="$1 pm-ms"', $a, 1, $c);
                if ($c === 0) $na = preg_replace("/class='([^']*)'/", "class='$1 pm-ms'", $a, 1);
            } else { $na = $a . ' class="pm-ms"'; }
            $cnt++;
            return '<select' . $na . '>';
        }, $new);
        if ($cnt) $out['changes'][] = "class=\"pm-ms\" x$cnt";
        // chiamata dettaglioCommessa
        if (preg_match('/(\$gRic\s*=\s*\$it->giorniRiconcilia\(\$f\)\s*;)/', $new, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $new = substr($new, 0, $pos) . "\n    // [PM_V1_9_34_APPLIED]\n    \$dettCommessa = \$it->dettaglioCommessa(\$f);" . substr($new, $pos);
            $out['changes'][] = 'chiamata dettaglioCommessa()';
        }
        // catch: $dettCommessa = []
        if (preg_match('/(\$cQ2\s*=\s*\$gQ\s*=\s*\[\]\s*;\s*\$cRie2\s*=\s*\$gOp\s*=\s*\$gAr\s*=\s*\$gRic\s*=\s*\[\]\s*;)/', $new, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $new = substr($new, 0, $pos) . "\n    \$dettCommessa = [];" . substr($new, $pos);
            $out['changes'][] = '$dettCommessa=[] nel catch';
        }
        // sezione HTML prima di footer
        if (preg_match('/(<\?php\s+require_once\s*\(\s*[\'"]footer\.php[\'"]\s*\)\s*;\s*\?>)/', $new, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            $new = substr($new, 0, $pos) . $sectionIT . "\n" . substr($new, $pos);
            $out['changes'][] = 'sezione HTML Dettaglio';
        }
        return $new;
    }, 'PM_V1_9_34_APPLIED', $dry) . "\n";

    // A3 — it_service_print.php
    $sectionPrint = <<<'HTML'

<?php // [PM_V1_9_34_APPLIED] Dettaglio per Commessa (stampa) ?>
<?php if (!empty($dettCommessa)):
    $__byC = [];
    foreach ($dettCommessa as $r) $__byC[$r['contract_id']][] = $r;
?>
<style>
  .pr34-h2 { margin:18px 0 6px; font-size:14px; page-break-before:auto; }
  .pr34-h3 { margin:10px 0 3px; font-size:12px; background:#e5e7eb; color:#111; padding:6px 10px; border-radius:3px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .pr34-tbl { width:100%; border-collapse:collapse; margin:3px 0 12px; page-break-inside:avoid; }
  .pr34-tbl th, .pr34-tbl td { padding:3px 6px; border-bottom:1px solid #d0d5dd; font-size:10.5px; text-align:right; }
  .pr34-tbl th:nth-child(-n+3), .pr34-tbl td:nth-child(-n+3) { text-align:left; }
  .pr34-tbl thead th { background:#f0f2f7; }
  .pr34-tbl tfoot td { font-weight:600; background:#eef4ff; }
</style>
<h2 class="pr34-h2">Dettaglio per Commessa</h2>
<?php foreach ($__byC as $cid => $rows):
    $first = $rows[0];
    $intest = implode(' | ', array_filter([$first['contract_code'], $first['code_x_installation'], $first['customer_name'], $first['contract_description']], fn($v)=>$v!==null && $v!==''));
    $tOre = array_sum(array_map(fn($r)=>(float)$r['ore'], $rows));
    $tTab = array_sum(array_map(fn($r)=>(float)$r['tot_costo_tab'], $rows));
?>
  <h3 class="pr34-h3"><?= htmlspecialchars($intest, ENT_QUOTES, 'UTF-8') ?>
    <span style="float:right;font-weight:normal"><?= count($rows) ?> · <?= number_format($tOre,2,',','.') ?>h · € <?= number_format($tTab,2,',','.') ?></span></h3>
  <table class="pr34-tbl">
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
    echo pm_patch("$root/app/it_service_print.php", function ($src, &$out) use ($sectionPrint) {
        $new = $src;
        if (preg_match('/(\$gOp\s*=\s*\$it->giorniOperatore\(\$f\)\s*;)/', $new, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $new = substr($new, 0, $pos) . "\n// [PM_V1_9_34_APPLIED]\n\$dettCommessa = \$it->dettaglioCommessa(\$f);" . substr($new, $pos);
            $out['changes'][] = 'chiamata metodo';
        }
        if (preg_match('/(<\/body>)/', $new, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            $new = substr($new, 0, $pos) . $sectionPrint . substr($new, $pos);
            $out['changes'][] = 'sezione stampa';
        }
        return $new;
    }, 'PM_V1_9_34_APPLIED', $dry) . "\n";
}

// ═══════════════════════════════════════════════════════════════════════
// PATCH B — service_desk.php
// ═══════════════════════════════════════════════════════════════════════
if (!$only || $only === 'service_desk') {
    echo "\n── service_desk.php ─────────────────────────────────────────────\n";
    $includeSD = <<<'PHP'

// [PM_V1_9_34_APPLIED] pm-ui-boost: multi-select con search + Cognome Nome
if (!isset($GLOBALS['__pm_boost_v1934'])) {
    $GLOBALS['__pm_boost_v1934'] = true;
    echo '<link rel="stylesheet" href="assets/css/pm-ui-boost.css">' . "\n";
    echo '<script src="assets/js/pm-ui-boost.js" defer></script>' . "\n";
    echo '<meta name="pm-ui-boost" content=\'form select\'>' . "\n";
}
PHP;
    echo pm_patch("$root/service_desk.php", function ($src, &$out) use ($includeSD) {
        $new = $src;
        if (preg_match('/(require_once\s*\(\s*[\'"]header\.php[\'"]\s*\)\s*;)/', $new, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            $new = substr($new, 0, $pos) . $includeSD . substr($new, $pos);
            $out['changes'][] = 'include pm-ui-boost';
        }
        // class="pm-ms" a tutti i <select>
        $cnt = 0;
        $new = preg_replace_callback('/<select(\s+[^>]*?)>/i', function ($m) use (&$cnt) {
            $a = $m[1];
            if (strpos($a, 'pm-ms') !== false) return $m[0];
            if (strpos($a, 'class=') !== false) {
                $na = preg_replace('/class="([^"]*)"/', 'class="$1 pm-ms"', $a, 1, $c);
                if ($c === 0) $na = preg_replace("/class='([^']*)'/", "class='$1 pm-ms'", $a, 1);
            } else { $na = $a . ' class="pm-ms"'; }
            $cnt++;
            return '<select' . $na . '>';
        }, $new);
        if ($cnt) $out['changes'][] = "class=\"pm-ms\" x$cnt";
        return $new;
    }, 'PM_V1_9_34_APPLIED', $dry) . "\n";
}

// ═══════════════════════════════════════════════════════════════════════
// COPIA ASSET
// ═══════════════════════════════════════════════════════════════════════
if (!$dry) {
    echo "\n── Copia asset pm-ui-boost ────────────────────────────────────────\n";
    $srcRoot = realpath(__DIR__ . '/..');
    foreach ([
        ['/assets/js/pm-ui-boost.js',  "$root/assets/js/pm-ui-boost.js"],
        ['/assets/css/pm-ui-boost.css', "$root/assets/css/pm-ui-boost.css"],
    ] as [$s, $d]) {
        $ss = $srcRoot . $s;
        if (is_file($ss)) {
            @mkdir(dirname($d), 0755, true);
            if (@copy($ss, $d)) echo "✔ $d\n"; else { $errori[] = "Copia fallita: $d"; echo "✘ $d\n"; }
        } else {
            echo "SKIP (sorgente non trovata): $ss\n";
        }
    }
}

echo "\n══════════════════════════════════════════════════════════════════\n";
if ($errori) {
    echo "⚠ Errori: " . count($errori) . "\n";
    foreach ($errori as $e) echo "   - $e\n";
    exit(2);
}
if ($dry) echo "DRY-RUN completato. Rilancia senza --dry-run per applicare.\n";
else {
    echo "[OK] " . count($fatti) . " file patchati.\n";
    echo "\nProssimi passi:\n";
    echo " 1) mysql -uroot portalmanager < sql/migration_v1_9_34.sql\n";
    echo " 2) net stop Apache2.4 & net start Apache2.4\n";
}
exit(0);
