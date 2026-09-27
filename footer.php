<?php
/**
 * certV 5.03 — footer.php
 * Footer con copyright e indicazione release configurabili.
 */
$footer_copyright    = $settings['copyright_text']     ?? '';
// v1.7.8: se release_label non è impostato o è vuoto, fallback automatico ad app_version
$footer_release      = trim($settings['release_label'] ?? '');
if ($footer_release === '' || $footer_release === 'v5.03.00') {
    $auto_ver = trim($settings['app_version'] ?? '');
    if ($auto_ver !== '') $footer_release = 'v' . $auto_ver;
}
$footer_show_release = ($settings['release_show_footer'] ?? '1') === '1';
$has_footer = !empty($footer_copyright) || ($footer_show_release && !empty($footer_release));
?>

<?php if ($has_footer): ?>
<footer class="app-footer no-print" style="margin-top:40px;padding:18px 24px;border-top:1px solid var(--border);background:#fff;color:var(--muted);font-size:11px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <div>
    <?php if (!empty($footer_copyright)): ?>
      <?= h($footer_copyright) ?>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:14px;align-items:center">
    <?php if ($footer_show_release && !empty($footer_release)): ?>
      <span style="background:#f1f5f9;padding:3px 10px;border-radius:10px;font-family:monospace;font-size:10px;font-weight:700;color:#475569">
        <i class="fa-solid fa-code-branch"></i> <?= h($footer_release) ?>
      </span>
    <?php endif; ?>
  </div>
</footer>
<?php endif; ?>

<?php
// v1.9.71 — filtri tipizzati ed export (CSV/XLSX/PDF/DOCX/ODT) anche sulle pagine con
// filtri server-side: ListFilter si aggancia alla tabella risultati principale.
if (!defined('PM_PRINT_VIEW')) {
    try {
        require_once __DIR__ . '/app/ListFilter.php';
        ListFilter::renderAuto(function_exists('current_page') ? current_page() : basename($_SERVER['PHP_SELF'] ?? ''));
    } catch (Throwable $e) { /* mai bloccare il rendering della pagina */ }
}
?>
<?php
// v1.9.73 — profiler delle query: elenco con i tempi misurati da MariaDB
if (!empty($GLOBALS['PM_PROFILE_T0']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $pmProf = $pdo->query("SHOW PROFILES")->fetchAll(PDO::FETCH_ASSOC);
        $pmProf = array_values(array_filter($pmProf, fn($r) => stripos((string)$r['Query'], 'SHOW PROFILES') === false
                                                            && stripos((string)$r['Query'], 'SET profiling') === false));
        $pmDb   = array_sum(array_map(fn($r) => (float)$r['Duration'], $pmProf));
        usort($pmProf, fn($a, $b) => (float)$b['Duration'] <=> (float)$a['Duration']);
        $pmTot  = microtime(true) - $GLOBALS['PM_PROFILE_T0'];
        echo '<div style="margin:18px 0;border:2px solid #7c3aed;border-radius:8px;background:#faf5ff;padding:10px 12px;font-size:12px">'
           . '<b style="color:#6d28d9"><i class="fa-solid fa-gauge-high"></i> Profiler query</b> — '
           . count($pmProf) . ' query · database ' . number_format($pmDb * 1000, 0, ',', '.') . ' ms · pagina (PHP + DB) '
           . number_format($pmTot * 1000, 0, ',', '.') . ' ms'
           . (count($pmProf) >= 99 ? ' · <span style="color:#b45309">elenco limitato alle ultime 100 query</span>' : '')
           . '<table class="data-table" data-pm-nofilter style="width:100%;margin-top:6px;font-size:11px"><thead><tr><th style="width:70px;text-align:right">ms</th><th style="width:55px;text-align:right">%</th><th>Query</th></tr></thead><tbody>';
        foreach (array_slice($pmProf, 0, 40) as $r) {
            $ms = (float)$r['Duration'] * 1000;
            echo '<tr' . ($ms >= 100 ? ' style="background:#fee2e2"' : ($ms >= 20 ? ' style="background:#fef3c7"' : '')) . '>'
               . '<td style="text-align:right;font-weight:700">' . number_format($ms, 1, ',', '.') . '</td>'
               . '<td style="text-align:right">' . ($pmDb > 0 ? number_format(100 * (float)$r['Duration'] / $pmDb, 1, ',', '.') : '0') . '</td>'
               . '<td><code style="white-space:pre-wrap;word-break:break-word">' . htmlspecialchars(mb_substr(preg_replace('/\s+/', ' ', (string)$r['Query']), 0, 400)) . '</code></td></tr>';
        }
        echo '</tbody></table></div>';
    } catch (Throwable $e) { echo '<!-- profiler: ' . htmlspecialchars($e->getMessage()) . ' -->'; }
}
?>
</div><!-- /.content -->
</div><!-- /.main -->
</body>
</html>
