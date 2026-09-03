<?php
/**
 * certV 5.5.0 — cron_import_worker.php
 *
 * Worker per processare i job di importazione accodati in modalità asincrona.
 * Da schedulare nel Task Scheduler di Windows ogni 1-5 minuti:
 *
 *   php D:\portalbrand\cron_import_worker.php
 *
 * Logica:
 *   1. Acquisisce un job in stato 'queued' (FIFO, lock soft via UPDATE atomico)
 *   2. Lo passa in 'processing'
 *   3. Esegue commitJob() (commit atomico per riga)
 *   4. Aggiorna a 'imported' / 'partial' / 'partial_lds'
 *
 * Il lock è ottenuto con UPDATE ... WHERE status='queued' LIMIT 1 e
 * controllo di rowCount: garantisce che due worker concorrenti non
 * processino lo stesso job.
 */

if (PHP_SAPI !== 'cli' && !defined('ALLOW_WEB_WORKER')) {
    http_response_code(403);
    exit("CLI only.\n");
}

chdir(__DIR__);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/app/ImportValidator.php';
require_once __DIR__ . '/app/ImportProcessor.php';

// ─── CONFIGURAZIONE ────────────────────────────────────────────────────
$MAX_JOBS_PER_RUN  = 5;     // limite job per esecuzione (evita run troppo lunghi)
$WORKER_ID         = gethostname() . ':' . getmypid();
$START_TIME        = time();
$MAX_DURATION_SEC  = 240;   // 4 min, sotto i 5 min di scheduling tipico

function log_worker(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

log_worker("Worker start (id=$WORKER_ID, max_jobs=$MAX_JOBS_PER_RUN)");

$processed = 0;
while ($processed < $MAX_JOBS_PER_RUN) {
    if (time() - $START_TIME > $MAX_DURATION_SEC) {
        log_worker("Time limit reached, stopping.");
        break;
    }

    // ── Acquisisci un job (lock atomico via UPDATE) ──
    $pdo->beginTransaction();
    try {
        $sel = $pdo->query(
            "SELECT id, import_type, original_name, total_rows
               FROM import_jobs
              WHERE status = 'queued'
              ORDER BY queued_at ASC
              LIMIT 1
              FOR UPDATE"
        );
        $job = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            $pdo->commit();
            log_worker("No queued jobs.");
            break;
        }

        $upd = $pdo->prepare(
            "UPDATE import_jobs
                SET status = 'processing'
              WHERE id = ? AND status = 'queued'"
        );
        $upd->execute([$job['id']]);
        if ($upd->rowCount() === 0) {
            // Race: un altro worker l'ha preso
            $pdo->commit();
            continue;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_worker("Lock error: " . $e->getMessage());
        break;
    }

    log_worker("Picked job #{$job['id']} ({$job['import_type']}, {$job['total_rows']} rows, file={$job['original_name']})");

    // ── Esegui commit ──
    try {
        $proc = new ImportProcessor($pdo, $job['import_type']);
        $stats = $proc->commitJob((int)$job['id'], null);
        log_worker("Job #{$job['id']} done: imported={$stats['imported']}, failed={$stats['failed']}, skipped={$stats['skipped']}");
        if (function_exists('write_log')) {
            write_log('Import', 'success',
                "Worker async: job #{$job['id']} completato " .
                "({$stats['imported']} importate, {$stats['failed']} fallite)",
                null);
        }
    } catch (Throwable $e) {
        // Riporta lo stato a 'queued' per retry
        $pdo->prepare(
            "UPDATE import_jobs
                SET status = 'queued', notes = CONCAT(COALESCE(notes,''), ?, '\n')
              WHERE id = ?"
        )->execute(['[' . date('c') . '] Worker error: ' . $e->getMessage(), $job['id']]);
        log_worker("ERROR job #{$job['id']}: " . $e->getMessage());
        if (function_exists('write_log')) {
            write_log('Import', 'error',
                "Worker async: errore su job #{$job['id']}: " . $e->getMessage(),
                null);
        }
    }

    $processed++;
}

log_worker("Worker end (processed=$processed jobs in " . (time() - $START_TIME) . "s)");
