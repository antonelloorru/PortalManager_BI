<?php
/**
 * PortalManager 1.1.0 — credly_cron_sync.php
 *
 * Worker schedulato (cron / Task Scheduler) per sync periodica
 * dei profili Credly collegati ai dipendenti.
 *
 * Esecuzione manuale (test):
 *   php credly_cron_sync.php
 *
 * Schedula esempi:
 *   Linux:    0 3 * * * /usr/bin/php /var/www/html/portalbrand/credly_cron_sync.php
 *   Windows:  Task Scheduler giornaliero → php.exe credly_cron_sync.php
 *
 * Sicurezza:
 *   - Esegue SOLO da CLI (no web access)
 *   - Verifica setting credly_auto_sync_cron = 1
 *   - Lock file per evitare esecuzioni sovrapposte
 *   - Audit completo in app_logs
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Questo script può essere eseguito solo da CLI.\n");
}

// Bootstrap
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/CredlyImporter.php';

// Lock file (no esecuzioni concorrenti)
$lockFile = __DIR__ . '/uploads/.ratelimit/credly_cron.lock';
@mkdir(dirname($lockFile), 0775, true);

$fp = fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] Altro processo cron già attivo, skip.\n";
    exit(0);
}

try {
    // Check abilitazione
    $enabled = $pdo->query(
        "SELECT setting_value FROM app_settings
          WHERE setting_key IN ('credly_enabled', 'credly_auto_sync_cron')
          ORDER BY setting_key"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    if (($enabled['credly_enabled'] ?? '0') !== '1') {
        echo "[" . date('c') . "] credly_enabled = 0, exit.\n";
        exit(0);
    }
    if (($enabled['credly_auto_sync_cron'] ?? '0') !== '1') {
        echo "[" . date('c') . "] credly_auto_sync_cron = 0, exit.\n";
        exit(0);
    }

    // Carica dipendenti collegati attivi
    $rows = $pdo->query(
        "SELECT l.employee_id, l.credly_username, l.last_sync_at,
                e.first_name, e.last_name
           FROM employee_credly_link l
           JOIN employees e ON e.id = l.employee_id
          WHERE e.status = 'active'
          ORDER BY (l.last_sync_at IS NULL) DESC, l.last_sync_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "[" . date('c') . "] Nessun collegamento Credly attivo, exit.\n";
        exit(0);
    }

    echo "[" . date('c') . "] Sync di " . count($rows) . " dipendenti...\n";

    $actorId = 0;  // system user
    $importer = new CredlyImporter($pdo, $actorId);

    $totals = ['imported'=>0,'updated'=>0,'unchanged'=>0,'unmatched'=>0,'errors'=>0];

    foreach ($rows as $lk) {
        $name = $lk['last_name'] . ' ' . $lk['first_name'];
        echo "  → $name (" . $lk['credly_username'] . ")... ";
        try {
            $r = $importer->syncEmployee((int)$lk['employee_id'], $lk['credly_username']);
            foreach (['imported','updated','unchanged','unmatched','errors'] as $k) {
                $totals[$k] += $r[$k];
            }
            echo sprintf("imp=%d upd=%d unm=%d err=%d\n",
                $r['imported'], $r['updated'], $r['unmatched'], $r['errors']);
        } catch (Throwable $e) {
            $totals['errors']++;
            echo "ERRORE: " . $e->getMessage() . "\n";
            if (function_exists('write_log')) {
                write_log('CredlyImport', 'error',
                    "Cron sync fallita per emp=" . $lk['employee_id'] . ": " . $e->getMessage(),
                    $actorId);
            }
        }
        // Pausa breve tra una richiesta e l'altra (rispetto verso Credly)
        usleep(500000); // 0.5s
    }

    $summary = sprintf(
        "Cron sync completato: %d dipendenti, %d imp, %d upd, %d unmatch, %d err",
        count($rows), $totals['imported'], $totals['updated'],
        $totals['unmatched'], $totals['errors']
    );
    echo "[" . date('c') . "] " . $summary . "\n";

    if (function_exists('write_log')) {
        write_log('CredlyImport', 'success', $summary, 0);
    }

} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lockFile);
}
