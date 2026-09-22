<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.34 — Pagina diagnostica (versione tools-safe).
 * Path assoluti ai file di base: funziona anche se il file è in tools/.
 *
 * Apri: http://<host>/portalmanager/tools/pm_diagnostic.php
 */
$ROOT = dirname(__DIR__);   // sale da tools/ a webroot
require_once $ROOT . '/access_control.php';
require_once $ROOT . '/functions.php';

if (!in_array((int)($_SESSION['role_id'] ?? 99), [1], true)) {
    echo '<p>Solo Super Admin.</p>'; exit;
}
require_once $ROOT . '/header.php';

echo '<h1>Diagnostica Relazione di Servizio IT · v1.9.34</h1>';

// 1) File candidati in webroot
echo '<h2>File PHP nel gestionale con "servizi"/"relazion"/"dgb"/"report"/"desk"</h2><ul>';
foreach (glob($ROOT . '/*.php') as $f) {
    $b = basename($f);
    if (preg_match('/(servizi|relazion|dgb|report|desk|kpi|it_)/i', $b)) {
        printf('<li><code>%s</code> — %d KB</li>', h($b), (int)(filesize($f)/1024));
    }
}
echo '</ul>';

// 2) Vista v_rsi_dettaglio_commessa
echo '<h2>Vista <code>v_rsi_dettaglio_commessa</code></h2>';
try {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM v_rsi_dettaglio_commessa")->fetchColumn();
    echo "<p>✔ presente — <b>$c</b> righe totali.</p>";
    $r = $pdo->query("SELECT riga_formattata FROM v_rsi_dettaglio_commessa LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    if ($r) echo '<pre>' . h(implode("\n", $r)) . '</pre>';
} catch (Throwable $e) {
    echo '<p style="color:#a00">✘ vista mancante: ' . h($e->getMessage()) . '</p>';
    echo '<p>Fix: <code>mysql -uroot portalmanager &lt; sql/migration_v1_9_34.sql</code></p>';
}

// 3) Tabelle DGB
echo '<h2>Tabelle DGB richieste</h2><table border=1 cellpadding=6 style="border-collapse:collapse">';
foreach (['dgb_forms_activity','dgb_forms_activity_operator','dgb_operator','dgb_forms_contract','dgb_operator_map','clients','cm_projects','cm_rate_bands','cm_rate_band_rates'] as $t) {
    try {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "<tr><td>$t</td><td style=color:#080>✔</td><td>$c righe</td></tr>";
    } catch (Throwable $e) {
        echo "<tr><td>$t</td><td style=color:#a00>✘</td><td>" . h($e->getMessage()) . "</td></tr>";
    }
}
echo '</table>';

// 4) Migration log
echo '<h2>Migration log (ultime 15)</h2>';
try {
    $rows = $pdo->query("SELECT version, filename, applied_at FROM pm_migration_sql ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    echo '<table border=1 cellpadding=6 style=border-collapse:collapse>';
    echo '<tr><th>version</th><th>filename</th><th>applied_at</th></tr>';
    foreach ($rows as $r) echo '<tr><td>' . h($r['version']) . '</td><td>' . h($r['filename']) . '</td><td>' . h($r['applied_at']) . '</td></tr>';
    echo '</table>';
} catch (Throwable $e) { echo '<p style="color:#a00">' . h($e->getMessage()) . '</p>'; }

// 5) app_version
echo '<h2>app_version</h2>';
try {
    $v = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version'")->fetchColumn();
    echo '<p><code>' . h((string)$v) . '</code></p>';
} catch (Throwable) {}

// 6) Marker patch v1.9.34 nei file interessati
echo '<h2>Marker patch v1.9.34</h2><table border=1 cellpadding=6 style=border-collapse:collapse>';
echo '<tr><th>File</th><th>Marker PM_V1_9_34_APPLIED</th></tr>';
foreach (['it_service.php', 'app/ItServiceModel.php', 'app/it_service_print.php', 'service_desk.php'] as $rel) {
    $p = $ROOT . '/' . $rel;
    if (is_file($p)) {
        $has = strpos((string)@file_get_contents($p), 'PM_V1_9_34_APPLIED') !== false;
        echo '<tr><td><code>' . h($rel) . '</code></td><td style="color:' . ($has ? '#080' : '#a00') . '">' . ($has ? '✔ presente' : '✘ ASSENTE (patch non applicata)') . '</td></tr>';
    } else {
        echo '<tr><td><code>' . h($rel) . '</code></td><td style="color:#a00">✘ file non trovato</td></tr>';
    }
}
echo '</table>';

// 7) Asset pm-ui-boost
echo '<h2>Asset pm-ui-boost</h2><ul>';
foreach (['assets/js/pm-ui-boost.js', 'assets/css/pm-ui-boost.css'] as $rel) {
    $p = $ROOT . '/' . $rel;
    echo '<li><code>' . h($rel) . '</code> — ' . (is_file($p) ? '✔ presente (' . filesize($p) . ' byte)' : '<span style="color:#a00">✘ ASSENTE</span>') . '</li>';
}
echo '</ul>';

// 8) Voci menu correlate
echo '<h2>Voci role_permissions correlate</h2>';
try {
    $rows = $pdo->query("SELECT page_name FROM role_permissions WHERE role_id=1 AND (page_name LIKE '%servizi%' OR page_name LIKE '%relazion%' OR page_name LIKE '%dgb%' OR page_name LIKE '%report%' OR page_name LIKE '%desk%' OR page_name LIKE '%it_%')")->fetchAll(PDO::FETCH_COLUMN);
    echo '<ul>';
    foreach ($rows as $p) printf('<li><code>%s</code></li>', h($p));
    echo '</ul>';
} catch (Throwable) { echo '<p>—</p>'; }

require_once $ROOT . '/footer.php';
