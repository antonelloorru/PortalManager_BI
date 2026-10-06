<?php
/**
 * app/SocSync.php — v1.10.07
 * Pipeline unica di sincronizzazione del Service SOC: un solo processo, un solo lock, un solo registro.
 *
 * Passi di ogni esecuzione:
 *   1. cartella di arrivo (soc.inbox_dir, predefinita uploads/soc_inbox): ogni export XLSX/CSV presente viene importato
 *      e spostato in «archivio» (o in «scartati» se illeggibile). Il caricamento dalla pagina deposita qui il file;
 *      un processo esterno può depositare gli export nella stessa cartella;
 *   2. DB SOC (cm_soc_source_db attiva): lettura incrementale della finestra configurata;
 *   3. ricostruzione dei ticket, abbinamenti persone/clienti, mappatura dei tecnici sull'Unità Organizzativa SOC.
 *
 * Inneschi (tutti verso SocSync::run):
 *   - scheduler applicativo senza cron (CronlessScheduler::tick → worker, task «soc») ogni soc.interval_min minuti;
 *   - sincronizzazione giornaliera del gestionale (SyncRunner::run, in coda ai dataset);
 *   - riga di comando cron_soc_sync.php (Utilità di pianificazione);
 *   - «Esegui ora» / caricamento file in Sincronizzazione gestionale › SOC.
 * Registro: cm_soc_sync_runs (esecuzioni) e cm_soc_batches (singole sorgenti).
 */
declare(strict_types=1);

require_once __DIR__ . '/SocIngest.php';

final class SocSync
{
    public const LOCK = 'pm_soc_sync';

    public static function setting(PDO $pdo, string $k, string $d = ''): string
    {
        try { $v = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = " . $pdo->quote($k))->fetchColumn(); } catch (Throwable $e) { $v = false; }
        return is_string($v) && $v !== '' ? $v : $d;
    }

    public static function set(PDO $pdo, string $k, string $v): void
    {
        $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$k, mb_substr($v, 0, 500)]);
    }

    /** Cartella di arrivo assoluta (creata e protetta se manca). */
    public static function inbox(PDO $pdo): string
    {
        $root = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
        $rel = trim(str_replace('\\', '/', self::setting($pdo, 'soc.inbox_dir', 'uploads/soc_inbox')), '/');
        if ($rel === '' || str_contains($rel, '..')) $rel = 'uploads/soc_inbox';
        $dir = preg_match('#^([A-Za-z]:/|/)#', $rel) ? $rel : $root . '/' . $rel;
        foreach (['', '/archivio', '/scartati'] as $sub) if (!is_dir($dir . $sub)) @mkdir($dir . $sub, 0750, true);
        if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
        return $dir;
    }

    /** File in attesa nella cartella di arrivo (più vecchio per primo). */
    public static function pending(PDO $pdo): array
    {
        $dir = self::inbox($pdo);
        $f = array_values(array_filter((array)glob($dir . '/*'), fn($p) => is_file($p) && preg_match('/\.(xlsx|csv)$/i', $p)));
        usort($f, fn($a, $b) => filemtime($a) <=> filemtime($b));
        return $f;
    }

    /** Deposita un file caricato nella cartella di arrivo. @return string percorso */
    public static function enqueue(PDO $pdo, string $tmp, string $origName): string
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) throw new RuntimeException('Formato non ammesso: usare XLSX o CSV.');
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($origName, PATHINFO_FILENAME)) ?: 'export';
        $dest = self::inbox($pdo) . '/' . date('Ymd_His') . '_' . mb_substr($base, 0, 80) . '.' . $ext;
        if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest))) throw new RuntimeException('Impossibile salvare il file nella cartella di arrivo.');
        @chmod($dest, 0640);
        return $dest;
    }

    /** È il momento di un'esecuzione automatica? */
    public static function isDue(PDO $pdo, ?string &$why = null): bool
    {
        if (self::setting($pdo, 'soc.sync_enabled', '0') !== '1') { $why = 'sincronizzazione SOC disattivata'; return false; }
        $every = max(5, (int)self::setting($pdo, 'soc.interval_min', '60')) * 60;
        // stessa sorgente temporale del registro (orologio del database)
        $st = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, NOW())"); $st->execute([self::setting($pdo, 'soc.last_run_at', '1970-01-01 00:00:00')]);
        if ((int)$st->fetchColumn() < $every) { $why = 'eseguita da meno di ' . ($every / 60) . ' minuti'; return false; }
        $hasSource = (bool)self::pending($pdo);
        try { $hasSource = $hasSource || (int)$pdo->query("SELECT COUNT(*) FROM cm_soc_source_db WHERE is_active = 1")->fetchColumn() > 0; } catch (Throwable $e) {}
        if (!$hasSource) { $why = 'nessuna sorgente: DB SOC non configurato e cartella di arrivo vuota'; return false; }
        return true;
    }

    /**
     * Esecuzione completa della pipeline.
     * @return array{ran:bool, ok:bool, status:string, message:string, run_id?:int}
     */
    public static function run(PDO $pdo, string $trigger = 'pianificata', bool $force = false, ?int $userId = null, ?callable $say = null): array
    {
        $say ??= static function (string $m): void {};
        $why = '';
        if (!$force && !self::isDue($pdo, $why)) { $say("SOC: nessuna azione ($why)."); return ['ran' => false, 'ok' => true, 'status' => 'saltata', 'message' => $why]; }
        if ((int)$pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() !== 1) {
            $say('SOC: esecuzione già in corso.'); return ['ran' => false, 'ok' => true, 'status' => 'saltata', 'message' => 'esecuzione già in corso'];
        }
        $trig = in_array($trigger, ['manuale', 'pianificata', 'giornaliera', 'caricamento'], true) ? $trigger : 'pianificata';
        $pdo->prepare("INSERT INTO cm_soc_sync_runs (trigger_type, status, started_at, user_id) VALUES (?, 'running', NOW(), ?)")->execute([$trig, $userId]);
        $runId = (int)$pdo->lastInsertId();
        $t0 = microtime(true);
        $ing = new SocIngest($pdo); $ing->deferFinalize = true; $ing->trigger = $trig === 'manuale' || $trig === 'caricamento' ? 'manuale' : 'pianificata';
        $tot = ['files' => 0, 'files_err' => 0, 'new' => 0, 'upd' => 0, 'read' => 0]; $notes = []; $errors = 0; $db = 'non configurato';
        try {
            // 1. cartella di arrivo
            $dir = self::inbox($pdo);
            foreach (self::pending($pdo) as $path) {
                $name = basename($path);
                $r = $ing->importFile($path, preg_replace('/^\d{8}_\d{6}_/', '', $name), $userId);
                $ok = (bool)$r['ok'];
                @rename($path, $dir . ($ok ? '/archivio/' : '/scartati/') . $name);
                $tot['files']++; if (!$ok) { $tot['files_err']++; $errors++; }
                $tot['new'] += (int)($r['rows_inserted'] ?? 0); $tot['upd'] += (int)($r['rows_updated'] ?? 0); $tot['read'] += (int)($r['rows_read'] ?? 0);
                $notes[] = $name . ': ' . $r['message'];
                $say(($ok ? '  file ' : '  ERRORE file ') . $r['message']);
            }
            // 2. DB SOC
            if ((int)$pdo->query("SELECT COUNT(*) FROM cm_soc_source_db WHERE is_active = 1")->fetchColumn() > 0) {
                $r = $ing->importDb($userId, $ing->trigger);
                $db = $r['ok'] ? 'ok' : 'errore';
                if (!$r['ok']) $errors++;
                $tot['new'] += (int)($r['rows_inserted'] ?? 0); $tot['upd'] += (int)($r['rows_updated'] ?? 0); $tot['read'] += (int)($r['rows_read'] ?? 0);
                $notes[] = $r['message'];
                $say(($r['ok'] ? '  ' : '  ERRORE ') . $r['message']);
            }
            // 3. ricostruzione unica, abbinamenti, Unità Organizzativa SOC
            $nt = $ing->rebuild();
            $map = $ing->autoMap();
            $uo = self::assignUnit($pdo);
            $msg = sprintf('%d file, DB SOC %s · %s righe lette, %s nuove, %s aggiornate · %d ticket · UO SOC: %d nuovi tecnici, %d già presenti, %d in altra unità',
                $tot['files'], $db, number_format($tot['read'], 0, ',', '.'), number_format($tot['new'], 0, ',', '.'), number_format($tot['upd'], 0, ',', '.'),
                $nt, $uo['assigned'], $uo['already'], count($uo['conflicts']));
            $status = $errors === 0 ? 'ok' : (($tot['files'] - $tot['files_err']) > 0 || $db === 'ok' ? 'parziale' : 'errore');
            $pdo->prepare("UPDATE cm_soc_sync_runs SET status = ?, finished_at = NOW(), seconds = ?, files = ?, files_err = ?, db_status = ?, rows_read = ?,
                                  rows_new = ?, rows_updated = ?, tickets = ?, uo_assigned = ?, uo_conflicts = ?, message = ?, detail = ? WHERE id = ?")
                ->execute([$status, round(microtime(true) - $t0, 1), $tot['files'], $tot['files_err'], $db, $tot['read'], $tot['new'], $tot['upd'], $nt,
                           $uo['assigned'], count($uo['conflicts']), mb_substr($msg, 0, 1000), mb_substr(implode("\n", $notes), 0, 4000), $runId]);
            self::set($pdo, 'soc.last_run_at', (string)$pdo->query("SELECT NOW()")->fetchColumn());
            self::set($pdo, 'soc.last_status', $status);
            self::set($pdo, 'soc.last_note', $msg);
            $say("SOC: $msg");
            return ['ran' => true, 'ok' => $status !== 'errore', 'status' => $status, 'message' => $msg, 'run_id' => $runId, 'uo' => $uo];
        } catch (Throwable $e) {
            error_log('[SocSync] ' . $e->getMessage());
            $msg = 'Errore: ' . $e->getMessage();
            $pdo->prepare("UPDATE cm_soc_sync_runs SET status = 'errore', finished_at = NOW(), seconds = ?, message = ? WHERE id = ?")->execute([round(microtime(true) - $t0, 1), mb_substr($msg, 0, 1000), $runId]);
            self::set($pdo, 'soc.last_run_at', (string)$pdo->query("SELECT NOW()")->fetchColumn()); self::set($pdo, 'soc.last_status', 'errore'); self::set($pdo, 'soc.last_note', $msg);
            $say("SOC: $msg");
            return ['ran' => true, 'ok' => false, 'status' => 'errore', 'message' => $msg, 'run_id' => $runId];
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')")->fetchAll(); } catch (Throwable $e) {}
        }
    }

    /* ── Unità Organizzativa SOC ─────────────────────────────────────── */

    /** Unità «SOC» (codice soc.uo_code), creata se manca. */
    public static function unitId(PDO $pdo): int
    {
        $code = self::setting($pdo, 'soc.uo_code', 'SOC');
        $st = $pdo->prepare("SELECT id FROM cm_tech_units WHERE code = ?"); $st->execute([$code]);
        $id = (int)$st->fetchColumn();
        if ($id) return $id;
        $pdo->prepare("INSERT INTO cm_tech_units (code, name, description, color, is_active, sort_order) VALUES (?, ?, 'Security Operation Center (creata dal Service SOC)', '#7c3aed', 1, 70)")
            ->execute([$code, $code]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Tecnici che erogano il servizio: dipendenti abbinati a persone SOC che negli ultimi 12 mesi sono responsabili o incaricati
     * di un ticket o hanno scritto messaggi del supporto o note interne.
     */
    public static function serviceTechnicians(PDO $pdo): array
    {
        return $pdo->query("SELECT p.employee_id, GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') nomi_soc,
                                   CONCAT_WS(' ', e.last_name, e.first_name) dipendente, tp.id profile_id, tp.unit_id, u.name unita
                              FROM cm_soc_people p
                              JOIN employees e ON e.id = p.employee_id
                              LEFT JOIN cm_tech_profiles tp ON tp.employee_id = p.employee_id
                              LEFT JOIN cm_tech_units u ON u.id = tp.unit_id
                             WHERE p.employee_id IS NOT NULL
                               AND (EXISTS (SELECT 1 FROM cm_soc_tickets t WHERE (t.assignee_name = p.name OR t.owner_name = p.name) AND t.last_event_at >= (NOW() - INTERVAL 12 MONTH))
                                 OR EXISTS (SELECT 1 FROM cm_soc_events ev WHERE ev.author_name = p.name AND ev.event_kind IN ('supporto','nota') AND ev.event_at >= (NOW() - INTERVAL 12 MONTH)))
                             GROUP BY p.employee_id, e.last_name, e.first_name, tp.id, tp.unit_id, u.name
                             ORDER BY e.last_name, e.first_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mappa i tecnici del servizio sull'Unità Organizzativa SOC (soc.uo_auto = 1):
     * senza scheda tecnica → creata nell'unità SOC; scheda senza unità → assegnata a SOC;
     * scheda in un'altra unità → non modificata (conflitto da risolvere in Unità Organizzative Tecniche o con «forza»).
     * @param int[] $force employee_id da spostare comunque su SOC
     * @return array{unit_id:int, assigned:int, already:int, conflicts:array}
     */
    public static function assignUnit(PDO $pdo, array $force = []): array
    {
        $out = ['unit_id' => 0, 'assigned' => 0, 'already' => 0, 'conflicts' => []];
        if (self::setting($pdo, 'soc.uo_auto', '1') !== '1' && !$force) return $out;
        $uid = self::unitId($pdo); $out['unit_id'] = $uid;
        $ins = $pdo->prepare("INSERT INTO cm_tech_profiles (employee_id, unit_id, valid_from, is_active, notes) VALUES (?, ?, CURDATE(), 1, ?)");
        $upd = $pdo->prepare("UPDATE cm_tech_profiles SET unit_id = ?, subunit_id = NULL, notes = CONCAT_WS('\n', NULLIF(notes, ''), ?) WHERE id = ?");
        $log = function (int $emp, string $what) use ($pdo): void {
            if (function_exists('write_log')) write_log('Service SOC', 'info', "Tecnico #$emp: $what (Unità Organizzativa SOC)", null);
        };
        foreach (self::serviceTechnicians($pdo) as $t) {
            $emp = (int)$t['employee_id'];
            $note = date('d/m/Y') . ' — assegnato all\'unità SOC dal Service SOC (eroga il servizio: ' . $t['nomi_soc'] . ')';
            if ($t['profile_id'] === null) { $ins->execute([$emp, $uid, $note]); $out['assigned']++; $log($emp, 'scheda tecnica creata'); }
            elseif ($t['unit_id'] === null) { $upd->execute([$uid, $note, (int)$t['profile_id']]); $out['assigned']++; $log($emp, 'assegnato'); }
            elseif ((int)$t['unit_id'] === $uid) $out['already']++;
            elseif (in_array($emp, array_map('intval', $force), true)) { $upd->execute([$uid, $note . ' (da ' . $t['unita'] . ')', (int)$t['profile_id']]); $out['assigned']++; $log($emp, 'spostato da ' . $t['unita']); }
            else $out['conflicts'][] = ['employee_id' => $emp, 'dipendente' => $t['dipendente'], 'unita' => $t['unita'], 'nomi_soc' => $t['nomi_soc']];
        }
        return $out;
    }

    public static function runs(PDO $pdo, int $limit = 15): array
    {
        return $pdo->query("SELECT r.*, COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) utente FROM cm_soc_sync_runs r LEFT JOIN users u ON u.id = r.user_id
                             ORDER BY r.id DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    }
}
