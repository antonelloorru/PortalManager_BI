<?php
/**
 * app/DgbImporter.php — Ingestion batch dei modelli DogoBit (v1.8.8)
 *
 * Task 1: importazione batch dei 5 CSV (separatore '|', enclosure '"'),
 *   casting rigido dei campi Datetime e gestione dei NULL.
 * Task 5: al re-import calcola il diff (nuovi / modificati / invariati) tramite
 *   una firma per riga (colonna sig) e produce un report scaricabile, registrato
 *   in dgb_import_log.
 *
 * Nessuna dipendenza esterna: usa fgetcsv (gestisce i campi quotati multi-riga,
 * es. JSON di repetition_type_data o note con a-capo).
 */
final class DgbImporter
{
    private PDO $pdo;
    private int $chunk = 500;

    /** Mappa file logico -> [tabella, colonne]. Le colonne seguono i modelli sorgente. */
    private const MAP = [
        'dgb_operator' => ['table' => 'dgb_operator', 'cols' => [
            'id','username','abbr','first_name','second_name','email','company_abbr','type',
            'hourly_cost','full_cost','active','deleted','id_role','id_company',
            'created_at','updated_at','deleted_at',
        ]],
        'dgb_operator_allocation' => ['table' => 'dgb_operator_allocation', 'cols' => [
            'id','id_operator','id_division','id_contract','id_activitytype',
            'start_date','end_date','type','created_at','updated_at',
        ]],
        'dgb_forms_activity_planning' => ['table' => 'dgb_forms_activity_planning', 'cols' => [
            'id','name','repetition_type','fisrt_activity_start_date_time','first_activity_end_date_time',
            'start_repetition_date','end_repetition_date','dutation_in_minutes','num_of_planned',
            'planned_remote','planned_smart_working','deleted','id_operator','id_contract',
            'id_customer_comp','id_place','id_zone','id_activitytype','created_at','updated_at','deleted_at',
        ]],
        'dgb_forms_activity' => ['table' => 'dgb_forms_activity', 'cols' => [
            'id','code','ticket','date_start','date_dead_line','status','planned_hours','diff_hours',
            'human_resource_hours','human_resource_cost','human_resource_revenue','total_cost','total_revenue',
            'total_from_remote','total_smart_working','report_date','assigned_at','in_progress_at','completed_at',
            'closed_at','approved_at','aborted_at','deleted','id_operator','id_activity_planning','id_contract',
            'id_customer_comp','id_report_author','id_place','id_zone','id_activitytype','created_at','updated_at','deleted_at',
        ]],
        'dgb_forms_activity_operator' => ['table' => 'dgb_forms_activity_operator', 'cols' => [
            'id','exec_report_type','hours','cost','used_hourly_cost','revenue','quantity','um',
            'to_recover_hours','extra_hours','trip_hours','from_remote','smart_working','during_availability',
            'id_activity','id_operator','created_at','updated_at',
        ]],
        'dgb_forms_contract' => ['table' => 'dgb_forms_contract', 'cols' => [
            'id','code','code_x_installation','description','id_customer_comp','id_company',
            'start_date','end_date','active','deleted','created_at','updated_at','deleted_at',
        ]],
    ];

    /** Colonne di tipo datetime / date per il casting rigido. */
    private const DT = [
        'created_at','updated_at','deleted_at','start_date','end_date','date_start','date_dead_line',
        'fisrt_activity_start_date_time','first_activity_end_date_time','start_repetition_date','end_repetition_date',
        'assigned_at','in_progress_at','completed_at','closed_at','approved_at','aborted_at','password_expires_at',
    ];
    private const DATE_ONLY = ['report_date'];
    private const NUM = [
        'hourly_cost','full_cost','planned_hours','diff_hours','human_resource_hours','human_resource_cost',
        'human_resource_revenue','total_cost','total_revenue','total_from_remote','total_smart_working',
        'hours','cost','used_hourly_cost','revenue','quantity','to_recover_hours','extra_hours','trip_hours',
    ];
    private const INT = [
        'id','id_role','id_company','id_operator','id_division','id_contract','id_activitytype','dutation_in_minutes',
        'num_of_planned','id_customer_comp','id_place','id_zone','id_activity_planning','id_report_author','id_activity',
        'active','deleted','planned_remote','planned_smart_working','from_remote','smart_working','during_availability',
    ];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function models(): array { return array_keys(self::MAP); }

    /** Deduce il modello dal nome file (es. forms_activity_planning_2026....csv). */
    public static function detectModel(string $filename): ?string
    {
        $n = strtolower($filename);
        // ordine dal più specifico al più generico
        $cands = [
            'dgb_operator_allocations_on_forms_contract' => 'dgb_operator_allocation',
            'forms_activity_has_dgb_operator'            => 'dgb_forms_activity_operator',
            'forms_activity_planning'                    => 'dgb_forms_activity_planning',
            'forms_activity'                             => 'dgb_forms_activity',
            'forms_contract'                             => 'dgb_forms_contract',
            'dgb_operator'                               => 'dgb_operator',
        ];
        foreach ($cands as $needle => $model) if (strpos($n, $needle) !== false) return $model;
        return null;
    }

    /* ── Casting ─────────────────────────────────────────────────────────── */

    private static function castDateTime(?string $v): ?string
    {
        $v = trim((string)$v);
        if ($v === '' || $v === '""' || $v === 'NULL' || $v === '0000-00-00 00:00:00') return null;
        $v = str_replace('T', ' ', $v);
        if (($p = strpos($v, '.')) !== false) $v = substr($v, 0, $p); // toglie i millisecondi
        $ts = strtotime($v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private static function castDate(?string $v): ?string
    {
        $v = trim((string)$v);
        if ($v === '' || $v === '0000-00-00') return null;
        if (($p = strpos($v, ' ')) !== false) $v = substr($v, 0, $p);
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private static function castNum(?string $v): ?float
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        return (float)str_replace(',', '.', $v);
    }

    private static function castInt(?string $v): ?int
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!is_numeric($v)) return null;
        return (int)$v;
    }

    private static function castVal(string $col, ?string $v)
    {
        if (in_array($col, self::DATE_ONLY, true)) return self::castDate($v);
        if (in_array($col, self::DT, true))        return self::castDateTime($v);
        if (in_array($col, self::INT, true))       return self::castInt($v);
        if (in_array($col, self::NUM, true))       return self::castNum($v);
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    /* ── Import di un singolo file ───────────────────────────────────────── */

    /**
     * @return array report per la tabella (read, inserted, updated, unchanged, skipped, table, changed_sample)
     */
    public function importFile(string $path, string $model, string $batchUuid, ?int $userId = null): array
    {
        if (!isset(self::MAP[$model])) throw new InvalidArgumentException("Modello sconosciuto: $model");
        $table = self::MAP[$model]['table'];
        $cols  = self::MAP[$model]['cols'];

        $fh = @fopen($path, 'r');
        if (!$fh) throw new RuntimeException("Impossibile aprire il file: $path");

        // header -> indici di colonna (case-insensitive, BOM rimosso)
        $header = fgetcsv($fh, 0, '|', '"', "\0");
        if (!$header) { fclose($fh); throw new RuntimeException("File vuoto o header illeggibile"); }
        $header = array_map(function ($h) {
            $h = trim((string)$h, " \"\r\n\t");
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // BOM UTF-8
            return strtolower($h);
        }, $header);
        $idx = array_flip($header);
        foreach ($cols as $c) if (!array_key_exists($c, $idx)) { /* colonna assente: verrà NULL */ }

        // stato precedente per il diff: id -> sig
        $prevSig = [];
        $q = $this->pdo->query("SELECT id, sig FROM `$table`");
        foreach ($q->fetchAll(PDO::FETCH_NUM) as $r) $prevSig[(int)$r[0]] = (string)$r[1];

        $read = 0; $ins = 0; $upd = 0; $unch = 0; $skip = 0; $changedSample = [];
        $buf = [];
        $ph = '(' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')'; // +1 = sig
        $updateAssign = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols)) . ',`sig`=VALUES(`sig`)';

        $flush = function () use (&$buf, $table, $cols, $ph, $updateAssign) {
            if (!$buf) return;
            $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`,`sig`) VALUES "
                 . implode(',', array_fill(0, count($buf), $ph))
                 . " ON DUPLICATE KEY UPDATE $updateAssign";
            $st = $this->pdo->prepare($sql);
            $flat = [];
            foreach ($buf as $row) foreach ($row as $v) $flat[] = $v;
            $st->execute($flat);
            $buf = [];
        };

        $this->pdo->beginTransaction();
        while (($row = fgetcsv($fh, 0, '|', '"', "\0")) !== false) {
            if ($row === [null] || $row === false) continue;
            $read++;
            $vals = [];
            foreach ($cols as $c) {
                $raw = isset($idx[$c]) && array_key_exists($idx[$c], $row) ? $row[$idx[$c]] : null;
                $vals[$c] = self::castVal($c, $raw);
            }
            $id = $vals['id'] ?? null;
            if ($id === null) { $skip++; continue; }
            // firma riga (esclude sig)
            $sig = md5(implode('|', array_map(fn($v) => $v === null ? "\1" : (string)$v, $vals)));
            // classificazione diff
            if (!array_key_exists((int)$id, $prevSig)) { $ins++; }
            elseif ($prevSig[(int)$id] !== $sig)       { $upd++; if (count($changedSample) < 50) $changedSample[] = (int)$id; }
            else                                        { $unch++; }

            $buf[] = array_merge(array_values($vals), [$sig]);
            if (count($buf) >= $this->chunk) $flush();
        }
        $flush();
        $this->pdo->commit();
        fclose($fh);

        $report = ['table' => $table, 'model' => $model, 'source_file' => basename($path),
                   'read' => $read, 'inserted' => $ins, 'updated' => $upd, 'unchanged' => $unch,
                   'skipped' => $skip, 'changed_sample' => $changedSample];

        $st = $this->pdo->prepare(
            "INSERT INTO dgb_import_log (batch_uuid, table_name, source_file, rows_read, rows_inserted, rows_updated, rows_unchanged, rows_skipped, diff_json, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([$batchUuid, $table, basename($path), $read, $ins, $upd, $unch, $skip, json_encode($report), $userId]);
        return $report;
    }

    /** UUID v4 semplice per il batch. */
    public static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** Ultimi batch con riepilogo aggregato (per lo storico import). */
    public function recentBatches(int $limit = 10): array
    {
        $st = $this->pdo->prepare(
            "SELECT batch_uuid, MIN(created_at) AS created_at,
                    SUM(rows_read) read_tot, SUM(rows_inserted) ins_tot, SUM(rows_updated) upd_tot, SUM(rows_unchanged) unch_tot
               FROM dgb_import_log GROUP BY batch_uuid ORDER BY created_at DESC LIMIT " . (int)$limit
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function batchDetail(string $uuid): array
    {
        $st = $this->pdo->prepare("SELECT * FROM dgb_import_log WHERE batch_uuid=? ORDER BY id");
        $st->execute([$uuid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
