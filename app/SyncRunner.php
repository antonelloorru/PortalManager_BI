<?php
/**
 * PortalManager — app/SyncRunner.php  (v1.9.70)
 *
 * Esecuzione della sincronizzazione giornaliera dal gestionale, indipendente dal
 * punto di innesco: scheduler applicativo senza cron (CronlessScheduler + worker web),
 * riga di comando (cron_sync.php, opzionale) o avvio manuale.
 *
 * Logica estratta da cron_sync.php, stessa semantica:
 *   - decisione "è il momento?" (giorni, orario, finestra o recupero in giornata);
 *   - lock in DB con SCADENZA (un processo interrotto non blocca per sempre);
 *   - dataset in SyncDatasets::syncOrder(), rollback per dataset, riconciliazione;
 *   - esito in cm_sync_schedule_log e cm_sync_schedule.last_*.
 */
declare(strict_types=1);

final class SyncRunner
{
    public const LOCK_TTL    = 3 * 3600;   // secondi
    public const RETRY_AFTER = 30 * 60;    // dopo un errore, nuovo tentativo non prima di (s)

    public static function config(PDO $pdo): ?array
    {
        $r = $pdo->query("SELECT * FROM `cm_sync_schedule` WHERE `id` = 1")->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Timestamp di inizio dell'esecuzione prevista per il giorno di $ts. */
    private static function slot(array $cfg, int $ts): int
    {
        return (int)strtotime(date('Y-m-d', $ts) . ' ' . substr((string)$cfg['run_at'], 0, 8));
    }

    private static function dayAllowed(array $cfg, int $ts): bool
    {
        $gg = array_map('intval', array_filter(explode(',', (string)$cfg['days_mask'])));
        return in_array((int)date('N', $ts), $gg, true);
    }

    /**
     * È il momento di eseguire?
     * catchup=1 (default): dall'orario previsto fino a fine giornata, alla prima
     *   occasione utile (senza cron non esiste un innesco garantito all'ora esatta).
     * catchup=0: solo dentro la finestra [run_at, run_at + window_minutes].
     */
    public static function isDue(array $cfg, ?int $now = null, ?string &$reason = null): bool
    {
        $now ??= time();
        if ((int)$cfg['is_enabled'] !== 1)      { $reason = 'pianificazione disattivata';     return false; }
        if (!self::dayAllowed($cfg, $now))      { $reason = 'giorno non previsto';            return false; }
        $start = self::slot($cfg, $now);
        if ($now < $start)                      { $reason = "non ancora l'orario previsto";   return false; }
        $catchup = (int)($cfg['catchup'] ?? 1) === 1;
        if (!$catchup && $now > $start + max(1, (int)$cfg['window_minutes']) * 60) {
            $reason = 'fuori dalla finestra prevista'; return false;
        }
        if (!empty($cfg['last_run_at'])) {
            $last = (int)strtotime((string)$cfg['last_run_at']);
            if ($last >= $start && in_array((string)$cfg['last_status'], ['ok', 'parziale'], true)) {
                $reason = 'già eseguita oggi'; return false;
            }
            // errore oggi: ritenta, ma con intervallo (evita tentativi a ogni controllo)
            if ($last >= $start && (string)$cfg['last_status'] === 'errore' && $now < $last + self::RETRY_AFTER) {
                $reason = 'ultimo tentativo fallito, nuovo tentativo dalle ' . date('H:i', $last + self::RETRY_AFTER);
                return false;
            }
        }
        if (!empty($cfg['lock_expires']) && strtotime((string)$cfg['lock_expires']) > $now) {
            $reason = 'esecuzione in corso'; return false;
        }
        $reason = 'da eseguire';
        return true;
    }

    /**
     * DatasetSync registra le rimozioni della riconciliazione con write_log(), definita in
     * functions.php. Worker e CLI non includono functions.php (avvierebbe una sessione):
     * senza questo fallback la riconciliazione pianificata terminava con errore fatale.
     */
    private static function ensureLogFunction(): void
    {
        if (function_exists('write_log')) return;
        eval('function write_log(string $category, string $level, string $message, ?int $user_id = null, array $context = []): void {
            $pdo = $GLOBALS["pdo"] ?? null;
            if (!$pdo instanceof PDO) return;
            try {
                $pdo->prepare("INSERT INTO app_logs (category, level, message, user_id, context, ip_address) VALUES (?, ?, ?, ?, ?, NULL)")
                    ->execute([$category, $level, $message, $user_id ?: null, $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null]);
            } catch (Throwable $e) { error_log("write_log: " . $e->getMessage()); }
        }');
    }

    /** Prossimo avvio previsto (timestamp) o null se disattivata. */
    public static function nextRunAt(array $cfg, ?int $now = null): ?int
    {
        $now ??= time();
        if ((int)$cfg['is_enabled'] !== 1) return null;
        if (self::isDue($cfg, $now)) return $now;
        for ($d = 0; $d <= 7; $d++) {
            $ts = strtotime("+$d day", $now);
            if (!self::dayAllowed($cfg, $ts)) continue;
            $slot = self::slot($cfg, $ts);
            if ($slot <= $now) continue;
            return $slot;
        }
        return null;
    }

    /**
     * Esegue la sincronizzazione se è il momento (o sempre, con $force).
     * @return array{ran:bool, status:string, note:string, code:int}
     *   code: 0 ok/non dovuta, 1 errori su dataset, 2 configurazione/connessione
     */
    public static function run(PDO $pdo, string $trigger = 'pianificata', bool $force = false,
                               bool $dryRun = false, ?callable $say = null): array
    {
        $say ??= static function (string $m): void {};
        $cfg = self::config($pdo);
        if (!$cfg) return ['ran' => false, 'status' => 'errore', 'note' => 'configurazione pianificazione assente', 'code' => 2];

        $why = '';
        if (!$force && !self::isDue($cfg, time(), $why)) {
            $say("Nessuna azione: $why.");
            return ['ran' => false, 'status' => 'saltata', 'note' => $why, 'code' => 0];
        }

        // lock con scadenza (acquisizione atomica)
        $owner = substr(gethostname() . '#' . getmypid() . '#' . $trigger, 0, 80);
        // un'unica sorgente temporale (PHP): i fusi di PHP e MySQL possono differire
        $st = $pdo->prepare("UPDATE `cm_sync_schedule` SET `lock_owner` = ?, `lock_expires` = ?
                              WHERE `id` = 1 AND (`lock_expires` IS NULL OR `lock_expires` < ?)");
        $st->execute([$owner, date('Y-m-d H:i:s', time() + self::LOCK_TTL), date('Y-m-d H:i:s')]);
        if ($st->rowCount() === 0) {
            $say("Un'altra esecuzione è in corso: nessuna azione.");
            return ['ran' => false, 'status' => 'saltata', 'note' => 'esecuzione in corso', 'code' => 0];
        }
        $release = static function () use ($pdo, $owner): void {
            try {
                $pdo->prepare("UPDATE `cm_sync_schedule` SET `lock_owner` = NULL, `lock_expires` = NULL
                                WHERE `id` = 1 AND `lock_owner` = ?")->execute([$owner]);
            } catch (Throwable $e) { /* il lock scade da solo */ }
        };
        register_shutdown_function($release);   // anche in caso di errore fatale

        $logId = null;
        try {
            foreach (['SourceDb', 'SyncDatasets', 'ProjectModel', 'PrefixResolver', 'DatasetSync'] as $c) {
                if (!class_exists($c)) require_once __DIR__ . "/$c.php";
            }
            self::ensureLogFunction();
            // stessa connessione della sincronizzazione manuale: quella ATTIVA
            $src = $pdo->query("SELECT * FROM `cm_source_db` WHERE `is_active` = 1 ORDER BY `id` DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$src) throw new RuntimeException('Nessuna connessione attiva al gestionale configurata.');

            $pdo->prepare("INSERT INTO `cm_sync_schedule_log` (`started_at`, `trigger_type`, `status`) VALUES (?, ?, NULL)")
                ->execute([date('Y-m-d H:i:s'), $force ? 'manuale' : $trigger]);
            $logId = (int)$pdo->lastInsertId();

            // v1.9.72: la password è in `password_enc` (cifrata). Prima si leggeva
            // $src['password'], colonna inesistente → connessione senza password (errore 1045).
            $srcCfg = SourceDb::configFromRow($src);
            $source = SourceDb::connect($srcCfg);
            // v1.9.84 — la connessione al gestionale resta inattiva mentre il portale scrive i dataset
            // (minuti, su 400.000 righe): il server la chiude e la riconciliazione, che parte dopo
            // l'ultimo dataset, falliva su tutti con «MySQL server has gone away». La sincronizzazione
            // manuale non lo mostrava perche' esegue ogni dataset in una richiesta separata.
            // Prima di ogni lettura dalla sorgente: verifica e, se serve, riconnessione.
            $fresh = static function () use (&$source, $srcCfg, $say): void {
                if ($source->alive()) return;
                $say('  (connessione al gestionale scaduta: riconnessione)');
                $source = SourceDb::connect($srcCfg);
            };
            $lost = static fn(Throwable $e): bool => (bool)preg_match('/gone away|Lost connection|\b2006\b|\b2013\b|server closed/i', $e->getMessage());
            // anche la connessione del portale deve reggere l'intera esecuzione
            try { $pdo->exec('SET SESSION wait_timeout = 28800, net_read_timeout = 600, net_write_timeout = 600'); } catch (Throwable $e) {}
            // stesse dipendenze della sincronizzazione manuale: senza di esse clienti e
            // azienda esecutrice delle commesse non venivano agganciati
            $sync = new DatasetSync($pdo, new ProjectModel($pdo), new PrefixResolver($pdo));
            $say(sprintf('Avvio sincronizzazione %s su %s@%s/%s', $dryRun ? '(SIMULAZIONE)' : '',
                $src['driver'], $src['host'], $src['dbname']));

            $t0 = microtime(true); $ok = 0; $err = 0; $note = [];
            $tot = ['total' => 0, 'ins' => 0, 'upd' => 0, 'removed' => 0];

            foreach (SyncDatasets::syncOrder() as $k) {
                $lbl = SyncDatasets::get($k)['label'] ?? $k;
                try {
                    $fresh();
                    $rows  = $sync->readSource($source, $k, 0);
                    $batch = $dryRun ? 0 : $sync->openBatch($k, (string)$src['dbname'], 0);
                    $r     = $sync->writeRows($k, $rows, 0, $dryRun, $batch);
                    if (!$dryRun) $sync->closeBatch($batch, $r);
                    unset($rows);
                    foreach (['total', 'ins', 'upd'] as $x) $tot[$x] += (int)($r[$x] ?? 0);
                    $ok++;
                    $say(sprintf('  %-38s %7d lette, %6d nuove, %6d aggiornate', $lbl, $r['total'] ?? 0, $r['ins'] ?? 0, $r['upd'] ?? 0));
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
                    $err++; $note[] = "$lbl: " . $e->getMessage();
                    $say(sprintf('  %-38s ERRORE: %s', $lbl, $e->getMessage()));
                }
            }
            if ((int)$cfg['reconcile'] === 1) {
                foreach (SyncDatasets::syncOrder() as $k) {
                    try {
                        $fresh();
                        try {
                            $r = $sync->reconcile($source, $k, 0, $dryRun);
                        } catch (Throwable $e1) {
                            // connessione persa durante la lettura: una sola ripetizione su connessione nuova
                            if (!$lost($e1)) throw $e1;
                            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
                            $say("  (riconciliazione $k: connessione persa, nuovo tentativo)");
                            $source = SourceDb::connect($srcCfg);
                            $r = $sync->reconcile($source, $k, 0, $dryRun);
                        }
                        $tot['removed'] += (int)($r['removed'] ?? 0);
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
                        $err++; $note[] = "riconciliazione $k: " . $e->getMessage();
                    }
                }
            }

            $sec   = round(microtime(true) - $t0, 1);
            $stato = $err === 0 ? 'ok' : ($ok > 0 ? 'parziale' : 'errore');
            $testo = sprintf('%d dataset ok, %d in errore, %s righe lette, %s nuove, %s aggiornate%s',
                $ok, $err, number_format($tot['total'], 0, ',', '.'), number_format($tot['ins'], 0, ',', '.'),
                number_format($tot['upd'], 0, ',', '.'), $tot['removed'] ? ', ' . $tot['removed'] . ' rimosse' : '');
            if ($note) $testo .= ' — ' . mb_substr(implode(' | ', $note), 0, 700);

            $pdo->prepare("UPDATE `cm_sync_schedule_log`
                              SET `finished_at` = ?, `status` = ?, `datasets_ok` = ?, `datasets_err` = ?,
                                  `rows_read` = ?, `rows_new` = ?, `rows_updated` = ?, `rows_removed` = ?,
                                  `seconds` = ?, `note` = ?
                            WHERE `id` = ?")
                ->execute([date('Y-m-d H:i:s'), $stato, $ok, $err, $tot['total'], $tot['ins'], $tot['upd'], $tot['removed'], $sec, $testo, $logId]);
            if (!$dryRun) {
                try {
                    $pdo->prepare("UPDATE `cm_source_db` SET `last_sync_at` = ?, `last_sync_note` = ? WHERE `id` = ?")
                        ->execute([date('Y-m-d H:i:s'), mb_substr('Pianificata: ' . $testo, 0, 255), (int)$src['id']]);
                } catch (Throwable $e) { /* informativo */ }
            }
            if (!$dryRun) {   // una simulazione non deve far saltare l'esecuzione vera del giorno
                $pdo->prepare("UPDATE `cm_sync_schedule`
                                  SET `last_run_at` = ?, `last_status` = ?, `last_note` = ?, `last_seconds` = ?
                                WHERE `id` = 1")->execute([date('Y-m-d H:i:s'), $stato, mb_substr($testo, 0, 500), $sec]);
            }
            $say("Completata in {$sec}s — $testo");
            // v1.9.79 — rapportini senza attivita DGB: collegamento da id allocazione / codice
            if (!$dryRun && $ok > 0) {
                try {
                    require_once __DIR__ . '/PmReportLink.php';
                    $lk = PmReportLink::relink($pdo);
                    $say(sprintf('Rapportini collegati alle attività DGB: %d (allocazione) + %d (codice); ancora scollegati: %d.',
                        $lk['by_allocation'], $lk['by_code'], $lk['unlinked']));
                } catch (Throwable $e) { $say('Collegamento rapportini non eseguito: ' . $e->getMessage()); }
            }
            // v1.10.07 — pipeline del Service SOC in coda alla sincronizzazione giornaliera (se attiva)
            if (!$dryRun) {
                try {
                    require_once __DIR__ . '/SocSync.php';
                    if (SocSync::setting($pdo, 'soc.sync_enabled', '0') === '1') {
                        $sr = SocSync::run($pdo, 'giornaliera', true, null, $say);
                        if (!$sr['ok']) $say('Service SOC: ' . $sr['message']);
                    }
                } catch (Throwable $e) { $say('Service SOC non sincronizzato: ' . $e->getMessage()); }
            }
            // v1.9.73 — dati cambiati: si ricostruiscono le copie delle viste lente
            if (!$dryRun && $ok > 0) {
                try {
                    require_once __DIR__ . '/PmSnapshot.php';
                    $sn = PmSnapshot::refresh($pdo, true);
                    $attive = count(array_filter($sn, fn($x) => ($x['enabled'] ?? 0) === 1));
                    $say("Copie delle viste aggiornate: $attive attive su " . count($sn) . '.');
                } catch (Throwable $e) { $say('Copie delle viste non aggiornate: ' . $e->getMessage()); }
            }
            $release();
            return ['ran' => true, 'status' => $stato, 'note' => $testo, 'code' => $err === 0 ? 0 : 1];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
            $msg = mb_substr($e->getMessage(), 0, 900);
            try {
                if ($logId) {
                    $pdo->prepare("UPDATE `cm_sync_schedule_log` SET `finished_at` = ?, `status` = 'errore', `note` = ?
                                    WHERE `id` = ?")->execute([date('Y-m-d H:i:s'), $msg, $logId]);
                }
                $pdo->prepare("UPDATE `cm_sync_schedule` SET `last_run_at` = ?, `last_status` = 'errore', `last_note` = ?
                                WHERE `id` = 1")->execute([date('Y-m-d H:i:s'), mb_substr($msg, 0, 500)]);
            } catch (Throwable $i) {}
            $release();
            $say('ERRORE: ' . $msg);
            return ['ran' => true, 'status' => 'errore', 'note' => $msg, 'code' => 2];
        }
    }
}
