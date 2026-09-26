<?php
/**
 * PortalManager — app/PmSnapshot.php  (v1.9.73)
 *
 * Copie aggiornate ("snapshot") delle viste aggregate più lente usate da
 * Service Desk, Relazione di Servizio IT e Report direzionale.
 *
 * Perché: queste pagine interrogano a ogni apertura decine di volte viste con
 * join e aggregazioni (GROUP BY → la vista viene ricalcolata per intero a ogni
 * query). I dati cambiano con la sincronizzazione, non a ogni richiesta: leggere
 * una tabella già calcolata dà lo stesso risultato a una frazione del costo.
 *
 * Regole:
 *  - AUTOMATICO E MISURATO: a ogni aggiornamento la vista viene ricostruita e il
 *    tempo misurato; la copia si attiva sopra SLOW_MS e si disattiva solo sotto
 *    FAST_MS (isteresi). Le viste veloci restano lette direttamente. Per ogni vista: auto|sempre|mai.
 *  - FRESCHEZZA: rigenerate tutte dopo la sincronizzazione; oltre REFRESH_MIN
 *    minuti la pagina usa la copia e ne chiede l'aggiornamento in background;
 *    oltre MAX_AGE_H ore si torna alla vista originale.
 *  - SICUREZZA: sostituzione atomica (RENAME TABLE), lock contro esecuzioni
 *    concorrenti; qualunque errore → la pagina usa la vista originale.
 *
 * Uso nei modelli:  $this->v = PmSnapshot::names($pdo, ['v_cm_sd_ticket', …]);
 *                   "SELECT … FROM `{$this->v['v_cm_sd_ticket']}` …"
 */
declare(strict_types=1);

final class PmSnapshot
{
    public const PREFIX      = 'snap_';
    // Soglie sul tempo di calcolo della vista completa. Basse di proposito: le pagine
    // interrogano la stessa vista molte volte per apertura (Report direzionale 9,
    // Service Desk decine), quindi 50 ms per vista diventano secondi per pagina,
    // mentre la copia costa una ricostruzione ogni REFRESH_MIN minuti.
    public const SLOW_MS     = 50;    // sopra: la copia si attiva
    public const FAST_MS     = 20;    // sotto: la copia si disattiva (isteresi: niente oscillazioni)
    public const REFRESH_MIN = 60;
    public const MAX_AGE_H   = 26;

    /** Viste candidate: tutte quelle lette dalle tre pagine. */
    public const CANDIDATES = [
        // Service Desk
        'v_cm_sd_ticket', 'v_cm_sd_presa_carico', 'v_cm_nomi', 'v_cm_sd_team', 'v_cm_sd_messaggi',
        'v_cm_sd_nome_moduli', 'v_cm_sd_moduli', 'v_cm_assenze_serie', 'v_cm_sd_operativita',
        'v_cm_sd_attivita', 'v_cm_sd_tecnici_uo', 'v_cm_sd_scheda_tecnico', 'v_cm_sd_tecnico_mese',
        'v_cm_sd_obj2_quadro', 'v_cm_sd_obj2_linee', 'v_cm_sd_obj23_ripartizione', 'v_cm_sd_obj23_code',
        'v_cm_sd_obj21_quadro', 'v_cm_sd_costi_valorizzati', 'v_cm_sd_commesse', 'v_cm_sd_addetti_mese',
        // Report direzionale
        'v_cm_dir_commessa', 'v_cm_dir_attenzione', 'v_cm_dir_andamento',
        // Relazione di Servizio IT
        'v_cm_it_servizio', 'v_cm_it_giorni_base', 'v_cm_it_distanze_mancanti',
    ];

    /** Colonne su cui creare un indice, se presenti nella copia (filtri e join delle pagine). */
    private const INDEX_COLS = [
        'giorno', 'anno_mese', 'aperto_il', 'data', 'mese', 'ym', 'ticket', 'tecnico', 'incaricato',
        'commessa', 'project_code', 'commessa_id', 'project_id', 'codice_linea', 'linea_servizio',
        'coda', 'gestione', 'stato', 'azienda', 'agente', 'cliente',
    ];

    private static ?array $reg = null;
    private static bool $refreshQueued = false;
    private static ?string $oldestUsed = null;

    // ── uso nelle pagine ─────────────────────────────────────────────────
    /** Nome da interrogare per ciascuna vista: la copia se valida, altrimenti la vista. */
    public static function names(PDO $pdo, array $views): array
    {
        $map = array_combine($views, $views);
        if (!empty($_GET['pm_nosnap']) || !empty($GLOBALS['PM_NO_SNAPSHOT'])) return $map;
        $reg = self::registry($pdo);
        if ($reg === null) return $map;                         // tabella di registro assente
        $now = time();
        foreach ($views as $v) {
            $r = $reg[$v] ?? null;
            if ($r === null) { self::queueRefresh($pdo); continue; }   // mai misurata
            if ((int)$r['enabled'] !== 1 || $r['status'] !== 'ok' || empty($r['refreshed_at'])) continue;
            $age = $now - (int)strtotime((string)$r['refreshed_at']);
            if ($age > self::MAX_AGE_H * 3600) { self::queueRefresh($pdo); continue; }   // troppo vecchia: vista
            if ($age > self::refreshMin($pdo) * 60) self::queueRefresh($pdo);           // valida, ma da rinfrescare
            $map[$v] = self::PREFIX . $v;
            if (self::$oldestUsed === null || $r['refreshed_at'] < self::$oldestUsed) self::$oldestUsed = (string)$r['refreshed_at'];
        }
        return $map;
    }

    /** Data/ora della copia più vecchia usata in questa richiesta (null = dati letti in tempo reale). */
    public static function oldestUsed(): ?string { return self::$oldestUsed; }

    /** Riga informativa per le pagine. */
    public static function badge(): string
    {
        if (self::$oldestUsed === null) return '';
        return '<span style="font-size:11px;color:var(--muted,#64748b)" title="Per velocità, le sintesi usano una copia dei dati aggiornata dopo ogni sincronizzazione e ogni ' . self::REFRESH_MIN . ' minuti.">'
             . '<i class="fa-solid fa-bolt"></i> dati aggiornati alle ' . date('H:i', (int)strtotime(self::$oldestUsed))
             . (date('Y-m-d', (int)strtotime(self::$oldestUsed)) !== date('Y-m-d') ? ' del ' . date('d/m', (int)strtotime(self::$oldestUsed)) : '')
             . '</span>';
    }

    private static function registry(PDO $pdo): ?array
    {
        if (self::$reg !== null) return self::$reg;
        try {
            self::$reg = [];
            foreach ($pdo->query("SELECT * FROM `pm_snapshot`")->fetchAll(PDO::FETCH_ASSOC) as $r) self::$reg[$r['view_name']] = $r;
        } catch (Throwable $e) { self::$reg = null; }
        return self::$reg;
    }

    private static function refreshMin(PDO $pdo): int
    {
        try {
            $v = $pdo->query("SELECT `setting_value` FROM `app_settings` WHERE `setting_key` = 'pm_snapshot_refresh_min'")->fetchColumn();
            if (is_numeric($v) && (int)$v >= 5) return (int)$v;
        } catch (Throwable $e) {}
        return self::REFRESH_MIN;
    }

    /** Chiede un aggiornamento in background a fine richiesta (al massimo uno ogni 10 minuti). */
    private static function queueRefresh(PDO $pdo): void
    {
        if (self::$refreshQueued || PHP_SAPI === 'cli') return;
        self::$refreshQueued = true;
        register_shutdown_function(static function () use ($pdo): void {
            try {
                $st = $pdo->prepare("UPDATE `app_settings` SET `setting_value` = ? WHERE `setting_key` = 'pm_snapshot_requested_at'
                                      AND (`setting_value` = '' OR `setting_value` < ?)");
                $st->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - 600)]);
                if ($st->rowCount() === 0) return;                    // già richiesto da poco
                require_once __DIR__ . '/CronlessScheduler.php';
                CronlessScheduler::dispatch($pdo, false, false, 'snapshot');
            } catch (Throwable $e) {}
        });
    }

    // ── aggiornamento ───────────────────────────────────────────────────
    /**
     * Ricostruisce le copie. $all=false: solo le viste in uso (o mai misurate);
     * $all=true (dopo la sincronizzazione): tutte, rimisurando anche quelle veloci.
     * @return array<string,array{status:string,ms:int,rows:int,enabled:int,note:string}>
     */
    public static function refresh(PDO $pdo, bool $all = true, ?array $only = null): array
    {
        $out = [];
        if ((int)$pdo->query("SELECT GET_LOCK('pm_snapshot', 0)")->fetchColumn() !== 1) {
            return ['*' => ['status' => 'occupato', 'ms' => 0, 'rows' => 0, 'enabled' => 0, 'note' => 'aggiornamento già in corso']];
        }
        try {
            @set_time_limit(0);
            self::$reg = null; $reg = self::registry($pdo) ?? [];
            $existing = array_flip($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
                                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN));
            foreach ($only ?? self::CANDIDATES as $v) {
                if (!isset($existing[$v])) { $out[$v] = self::save($pdo, $v, 'assente', 0, 0, 0, 'vista non presente nel database'); continue; }
                $r = $reg[$v] ?? null; $mode = $r['mode'] ?? 'auto';
                if ($mode === 'mai') { self::drop($pdo, $v); $out[$v] = self::save($pdo, $v, 'ok', 0, 0, 0, 'disattivata manualmente'); continue; }
                if (!$all && $r !== null && (int)$r['enabled'] !== 1 && $mode !== 'sempre') { $out[$v] = ['status' => 'saltata', 'ms' => (int)$r['build_ms'], 'rows' => 0, 'enabled' => 0, 'note' => 'veloce']; continue; }
                $out[$v] = self::build($pdo, $v, $mode, (int)($r['enabled'] ?? 0) === 1);
            }
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('pm_snapshot')");
            self::$reg = null;
        }
        return $out;
    }

    private static function build(PDO $pdo, string $v, string $mode, bool $wasEnabled = false): array
    {
        $snap = self::PREFIX . $v; $new = $snap . '__new'; $old = $snap . '__old';
        try {
            $pdo->exec("DROP TABLE IF EXISTS `$new`");
            $t = microtime(true);
            $pdo->exec("CREATE TABLE `$new` ENGINE=InnoDB AS SELECT * FROM `$v`");
            $ms = (int)round((microtime(true) - $t) * 1000);
            $rows = (int)$pdo->query("SELECT COUNT(*) FROM `$new`")->fetchColumn();
            // isteresi: una copia già attiva resta tale finché la vista non diventa davvero veloce
            $enabled = ($mode === 'sempre' || $ms >= self::SLOW_MS || ($wasEnabled && $ms >= self::FAST_MS)) ? 1 : 0;
            if (!$enabled) {
                $pdo->exec("DROP TABLE IF EXISTS `$new`");
                self::drop($pdo, $v);
                return self::save($pdo, $v, 'ok', $ms, $rows, 0, 'vista veloce: letta direttamente');
            }
            $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($new))->fetchAll(PDO::FETCH_COLUMN);
            $idx = array_values(array_intersect(self::INDEX_COLS, $cols));
            foreach (array_slice($idx, 0, 8) as $c) {
                try { $pdo->exec("ALTER TABLE `$new` ADD INDEX `ix_$c` (`$c`(64))"); }
                catch (Throwable $e) { try { $pdo->exec("ALTER TABLE `$new` ADD INDEX `ix_$c` (`$c`)"); } catch (Throwable $e2) {} }
            }
            $exists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($snap))->fetchColumn();
            $pdo->exec("DROP TABLE IF EXISTS `$old`");
            $pdo->exec($exists ? "RENAME TABLE `$snap` TO `$old`, `$new` TO `$snap`" : "RENAME TABLE `$new` TO `$snap`");   // atomico
            $pdo->exec("DROP TABLE IF EXISTS `$old`");
            return self::save($pdo, $v, 'ok', $ms, $rows, 1, count($idx) ? 'indici: ' . implode(', ', array_slice($idx, 0, 8)) : '');
        } catch (Throwable $e) {
            try { $pdo->exec("DROP TABLE IF EXISTS `$new`"); } catch (Throwable $e2) {}
            return self::save($pdo, $v, 'errore', 0, 0, 0, mb_substr($e->getMessage(), 0, 250));
        }
    }

    private static function drop(PDO $pdo, string $v): void
    {
        try { $pdo->exec("DROP TABLE IF EXISTS `" . self::PREFIX . $v . "`"); } catch (Throwable $e) {}
    }

    private static function save(PDO $pdo, string $v, string $status, int $ms, int $rows, int $enabled, string $note): array
    {
        $pdo->prepare("INSERT INTO `pm_snapshot` (`view_name`, `enabled`, `status`, `rows_count`, `build_ms`, `refreshed_at`, `note`)
                       VALUES (?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `status` = VALUES(`status`),
                         `rows_count` = VALUES(`rows_count`), `build_ms` = VALUES(`build_ms`),
                         `refreshed_at` = VALUES(`refreshed_at`), `note` = VALUES(`note`)")
            ->execute([$v, $enabled, $status, $rows, $ms, date('Y-m-d H:i:s'), $note]);
        return ['status' => $status, 'ms' => $ms, 'rows' => $rows, 'enabled' => $enabled, 'note' => $note];
    }
}
