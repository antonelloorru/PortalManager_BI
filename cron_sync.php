<?php
/**
 * cron_sync.php — Sincronizzazione pianificata dal gestionale.
 *
 * Invocato dall'Utilita' di pianificazione di Windows:
 *
 *   P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_sync.php
 *
 * DECIDE DA SOLO SE E' IL MOMENTO. L'attivita' di Windows puo' girare ogni ora
 * senza rischio: fuori dalla finestra prevista lo script termina subito. Cosi'
 * l'orario si cambia dal portale, senza toccare la configurazione di Windows.
 *
 * OPZIONI
 *   --force    esegue subito, ignorando orario e giorni previsti
 *   --dry-run  simula: legge tutto e non scrive nulla
 *   --quiet    nessun output su schermo (l'esito resta nel log)
 *
 * CODICI DI USCITA — servono all'Utilita' di pianificazione per distinguere il
 * successo dal fallimento:
 *   0  eseguita con successo, oppure non era il momento (che non e' un errore)
 *   1  eseguita con errori su almeno un dataset
 *   2  errore di configurazione o di connessione
 */

declare(strict_types=1);

// Solo da riga di comando: esposto via web sarebbe un modo per far partire
// una sincronizzazione a chiunque conosca l'URL, senza autenticazione.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo script si esegue solo da riga di comando.\n");
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$opt     = $argv ?? [];
$force   = in_array('--force', $opt, true);
$dryRun  = in_array('--dry-run', $opt, true);
$quiet   = in_array('--quiet', $opt, true);

/** Scrive a schermo rispettando --quiet; l'esito importante va comunque in tabella. */
$say = function (string $msg) use ($quiet): void {
    if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL);
};
$fail = function (string $msg, int $code) use ($quiet): void {
    if (!$quiet) fwrite(STDERR, date('[Y-m-d H:i:s] ') . 'ERRORE: ' . $msg . PHP_EOL);
    exit($code);
};

require_once(__DIR__ . '/app/Version.php');
require_once(__DIR__ . '/app/Db.php');
require_once(__DIR__ . '/app/SourceDb.php');
require_once(__DIR__ . '/app/SyncDatasets.php');
require_once(__DIR__ . '/app/DatasetSync.php');

try {
    $pdo = Db::pdo();
} catch (Throwable $e) {
    $fail('connessione al database del portale non riuscita: ' . $e->getMessage(), 2);
}

// ── 1. Configurazione della pianificazione ──────────────────────────────────
try {
    $cfg = $pdo->query("SELECT * FROM `cm_sync_schedule` WHERE `id` = 1")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $fail('tabella cm_sync_schedule assente: eseguire la migration v1.8.75.', 2);
}
if (!$cfg) $fail('configurazione della pianificazione non trovata.', 2);

if (!$force && (int)$cfg['is_enabled'] !== 1) {
    $say('Pianificazione disattivata: nessuna azione.');
    exit(0);
}

// ── 2. E' il momento? ───────────────────────────────────────────────────────
//
// Due condizioni: il giorno deve essere fra quelli previsti, e l'ora corrente
// deve cadere nella finestra che inizia all'orario previsto. La finestra serve a
// recuperare un'esecuzione mancata — se il server era spento alle 02:00 e viene
// acceso alle 03:00, con finestra di 120 minuti la sincronizzazione parte
// comunque.
if (!$force) {
    $oggi = (int)date('N');                       // 1 = lunedi
    $giorni = array_map('intval', array_filter(explode(',', (string)$cfg['days_mask'])));
    if (!in_array($oggi, $giorni, true)) {
        $say('Oggi non e fra i giorni previsti: nessuna azione.');
        exit(0);
    }

    $previsto = strtotime(date('Y-m-d') . ' ' . $cfg['run_at']);
    $fine     = $previsto + max(1, (int)$cfg['window_minutes']) * 60;
    $ora      = time();
    if ($ora < $previsto || $ora > $fine) {
        $say(sprintf('Fuori dalla finestra prevista (%s + %d min): nessuna azione.',
            substr((string)$cfg['run_at'], 0, 5), (int)$cfg['window_minutes']));
        exit(0);
    }

    // gia' eseguita oggi con successo? La finestra dura ore e l'attivita' di
    // Windows puo' girare piu' volte al suo interno.
    if (!empty($cfg['last_run_at'])
        && date('Y-m-d', strtotime((string)$cfg['last_run_at'])) === date('Y-m-d')
        && in_array((string)$cfg['last_status'], ['ok', 'parziale'], true)) {
        $say('Gia eseguita oggi (' . $cfg['last_run_at'] . '): nessuna azione.');
        exit(0);
    }
}

// ── 3. Lock ─────────────────────────────────────────────────────────────────
//
// La sincronizzazione dura minuti e l'attivita' di Windows puo' ripartire nel
// frattempo. Il lock ha una SCADENZA perche' un processo interrotto
// brutalmente non lo rilascerebbe mai, bloccando la pianificazione per sempre.
$owner  = gethostname() . '#' . getmypid();
$scade  = date('Y-m-d H:i:s', time() + 3 * 3600);

$stLock = $pdo->prepare(
    "UPDATE `cm_sync_schedule`
        SET `lock_owner` = ?, `lock_expires` = ?
      WHERE `id` = 1 AND (`lock_expires` IS NULL OR `lock_expires` < NOW())");
$stLock->execute([$owner, $scade]);
if ($stLock->rowCount() === 0) {
    $say('Un altra esecuzione e in corso: nessuna azione.');
    exit(0);
}

$logId = null;
$rilasciaLock = function () use ($pdo): void {
    try {
        $pdo->prepare("UPDATE `cm_sync_schedule` SET `lock_owner` = NULL, `lock_expires` = NULL WHERE `id` = 1")
            ->execute();
    } catch (Throwable $e) { /* il lock scade da solo */ }
};
// il lock va rilasciato anche se lo script muore per un errore fatale
register_shutdown_function($rilasciaLock);

try {
    // ── 4. Sorgente ─────────────────────────────────────────────────────────
    $src = $pdo->query("SELECT * FROM `cm_source_db` ORDER BY `id` DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$src) throw new Exception('Nessuna connessione al gestionale configurata.');

    $stLog = $pdo->prepare(
        "INSERT INTO `cm_sync_schedule_log` (`trigger_type`, `status`) VALUES (?, NULL)");
    $stLog->execute([$force ? 'manuale' : 'pianificata']);
    $logId = (int)$pdo->lastInsertId();

    $cfgSrc = ['driver' => $src['driver'], 'host' => $src['host'], 'port' => $src['port'],
               'dbname' => $src['dbname'], 'username' => $src['username'],
               'password' => $src['password'] ?? '', 'schema' => $src['source_schema'] ?? null];
    $source = SourceDb::connect($cfgSrc);
    $sync   = new DatasetSync($pdo);

    $say(sprintf('Avvio sincronizzazione %s su %s@%s/%s',
        $dryRun ? '(SIMULAZIONE)' : '', $src['driver'], $src['host'], $src['dbname']));

    // ── 5. I dataset, nell'ordine ───────────────────────────────────────────
    $t0 = microtime(true);
    $ok = 0; $err = 0;
    $tot = ['total' => 0, 'ins' => 0, 'upd' => 0, 'removed' => 0];
    $note = [];

    foreach (SyncDatasets::syncOrder() as $k) {
        $d = SyncDatasets::get($k);
        $etichetta = $d['label'] ?? $k;
        try {
            $rows  = $sync->readSource($source, $k, 0);
            $batch = $dryRun ? 0 : $sync->openBatch($k, $src['dbname'], 0);
            $r     = $sync->writeRows($k, $rows, 0, $dryRun, $batch);
            if (!$dryRun) $sync->closeBatch($batch, $r);
            unset($rows);   // il dataset successivo non deve pagare la memoria di questo

            $tot['total'] += (int)($r['total'] ?? 0);
            $tot['ins']   += (int)($r['ins'] ?? 0);
            $tot['upd']   += (int)($r['upd'] ?? 0);
            $ok++;
            $say(sprintf('  %-38s %7d lette, %6d nuove, %6d aggiornate',
                $etichetta, $r['total'] ?? 0, $r['ins'] ?? 0, $r['upd'] ?? 0));
        } catch (Throwable $e) {
            // una transazione lasciata aperta farebbe fallire tutti i dataset
            // successivi: e' il difetto corretto nella v1.8.64
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable $i) {}
            }
            $err++;
            $note[] = $etichetta . ': ' . $e->getMessage();
            $say(sprintf('  %-38s ERRORE: %s', $etichetta, $e->getMessage()));
        }
    }

    // ── 6. Riconciliazione, se richiesta ────────────────────────────────────
    if ((int)$cfg['reconcile'] === 1) {
        $say('Riconciliazione con il gestionale...');
        foreach (SyncDatasets::syncOrder() as $k) {
            try {
                $r = $sync->reconcile($source, $k, 0, $dryRun);
                $tot['removed'] += (int)($r['removed'] ?? 0);
                if (($r['orphans'] ?? 0) > 0) {
                    $say(sprintf('  %-38s %d orfane, %d rimosse',
                        SyncDatasets::get($k)['label'] ?? $k, $r['orphans'], $r['removed']));
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
                $err++;
                $note[] = 'riconciliazione ' . $k . ': ' . $e->getMessage();
            }
        }
    }

    $sec    = round(microtime(true) - $t0, 1);
    $stato  = $err === 0 ? 'ok' : ($ok > 0 ? 'parziale' : 'errore');
    $testo  = sprintf('%d dataset ok, %d in errore, %s righe lette, %s nuove, %s aggiornate%s',
        $ok, $err, number_format($tot['total'], 0, ',', '.'),
        number_format($tot['ins'], 0, ',', '.'), number_format($tot['upd'], 0, ',', '.'),
        $tot['removed'] ? ', ' . $tot['removed'] . ' rimosse' : '');
    if ($note) $testo .= ' — ' . mb_substr(implode(' | ', $note), 0, 700);

    $pdo->prepare(
        "UPDATE `cm_sync_schedule_log`
            SET `finished_at` = NOW(), `status` = ?, `datasets_ok` = ?, `datasets_err` = ?,
                `rows_read` = ?, `rows_new` = ?, `rows_updated` = ?, `rows_removed` = ?,
                `seconds` = ?, `note` = ?
          WHERE `id` = ?")
        ->execute([$stato, $ok, $err, $tot['total'], $tot['ins'], $tot['upd'],
                   $tot['removed'], $sec, $testo, $logId]);

    // in simulazione non si aggiorna l'ultima esecuzione: altrimenti una prova
    // farebbe saltare la sincronizzazione vera del giorno
    if (!$dryRun) {
        $pdo->prepare(
            "UPDATE `cm_sync_schedule`
                SET `last_run_at` = NOW(), `last_status` = ?, `last_note` = ?, `last_seconds` = ?
              WHERE `id` = 1")
            ->execute([$stato, mb_substr($testo, 0, 500), $sec]);
    }

    $say(sprintf('Completata in %ss — %s', $sec, $testo));
    $rilasciaLock();
    exit($err === 0 ? 0 : 1);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
    if ($logId) {
        try {
            $pdo->prepare(
                "UPDATE `cm_sync_schedule_log` SET `finished_at` = NOW(), `status` = 'errore', `note` = ?
                  WHERE `id` = ?")->execute([mb_substr($e->getMessage(), 0, 900), $logId]);
        } catch (Throwable $i) {}
    }
    try {
        $pdo->prepare("UPDATE `cm_sync_schedule` SET `last_run_at` = NOW(), `last_status` = 'errore', `last_note` = ? WHERE `id` = 1")
            ->execute([mb_substr($e->getMessage(), 0, 500)]);
    } catch (Throwable $i) {}
    $rilasciaLock();
    $fail($e->getMessage(), 2);
}
