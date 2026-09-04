<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.32 — Pagina diagnostica.
 * Apri nel browser: http://<host>/portalmanager/pm_diagnostic.php
 * Mostra: file candidati, vista v_rsi_dettaglio_commessa, tabelle DGB, righe.
 */
require_once('access_control.php');
require_once('functions.php');
if (!in_array((int)($_SESSION['role_id'] ?? 99), [1], true)) {
    echo '<p>Solo Super Admin.</p>'; exit;
}
require_once('header.php');

echo '<h1>Diagnostica Relazione di Servizio IT</h1>';

// 1) File candidati in webroot
echo '<h2>File PHP con "servizi"/"relazion"/"dgb"/"report" nel nome</h2><ul>';
foreach (glob(__DIR__ . '/*.php') as $f) {
    $b = basename($f);
    if (preg_match('/(servizi|relazion|dgb|report|desk|kpi)/i', $b)) {
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
    if ($r) { echo '<pre>' . h(implode("\n", $r)) . '</pre>'; }
} catch (Throwable $e) {
    echo '<p style="color:#a00">✘ vista mancante: ' . h($e->getMessage()) . '</p>';
    echo '<p>Fix: <code>mysql -uroot ' . h(defined('DB_NAME')?DB_NAME:'portalmanager') . ' &lt; sql/migration_v1_9_29.sql</code></p>';
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
echo '<h2>Migration log</h2>';
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

// 6) Ultime pagine chiamate (se log presente)
echo '<h2>Voci menu contenenti "servizi"/"relazion"</h2>';
try {
    $rows = $pdo->query("SELECT page_name FROM role_permissions WHERE role_id=1 AND (page_name LIKE '%servizi%' OR page_name LIKE '%relazion%' OR page_name LIKE '%dgb%' OR page_name LIKE '%report%')")->fetchAll(PDO::FETCH_COLUMN);
    echo '<ul>';
    foreach ($rows as $p) printf('<li><code>%s</code></li>', h($p));
    echo '</ul>';
} catch (Throwable) { echo '<p>—</p>'; }

require_once('footer.php');
