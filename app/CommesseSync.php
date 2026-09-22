<?php
/**
 * PortalManager — app/CommesseSync.php  (v1.8.45)
 *
 * Sincronizzazione delle commesse dal database gestionale esterno.
 *
 * La mappatura delle colonne è la stessa già collaudata sull'import del CSV
 * nativo (import_commesse_db.php): l'export di quel file proviene dalla tabella
 * `contract`, quindi leggere direttamente dalla tabella significa usare gli
 * stessi nomi di colonna. Le due strade restano allineate perché la mappa è
 * definita qui una sola volta e l'importer da file la riusa.
 *
 * La scrittura sul portale avviene in UPSERT su project_code, quindi la
 * sincronizzazione è ripetibile senza generare duplicati. Come nell'import da
 * file, i record marcati deleted vengono saltati salvo richiesta esplicita, e
 * i segnaposto della sincronizzazione DGB vengono assorbiti (v1.8.41).
 */
final class CommesseSync
{
    /** Colonna sorgente => campo logico. Chiave di tutta la sincronizzazione. */
    public const COLUMN_MAP = [
        'code'                     => 'project_code',
        'name'                     => 'name',
        'customer_comp_name'       => 'client_raw',
        'status'                   => 'operational_status',
        'description'              => 'description',
        'internal_description'     => 'internal_description',
        'start_date'               => 'start_date',
        'end_date'                 => 'end_date',
        'economic_value'           => 'value_total',
        'economic_value_till_now'  => 'value_todate',
        'time_material_costs'      => 'actual_cost',
        'margin_value'             => 'margin_total',
        'margin_value_till_now'    => 'margin_todate',
        'residual_value'           => 'residual_total',
        'residual_value_till_now'  => 'residual_todate',
        'n_ano_open'               => 'anomalies_open',
        'n_ano_open_block'         => 'anomalies_blocking',
        'deleted'                  => '__deleted',
        'id'                       => '__source_id',
    ];

    /** Colonne senza le quali la sorgente non è utilizzabile. */
    public const REQUIRED = ['code', 'name'];

    private const STATUS_MAP = ['OPEN' => 'Aperta', 'CLOSED' => 'Chiusa', 'SUSPENDED' => 'Sospesa'];

    private PDO $pdo;
    private ProjectModel $model;
    private PrefixResolver $prefix;

    public function __construct(PDO $pdo, ProjectModel $model, PrefixResolver $prefix)
    {
        $this->pdo = $pdo;
        $this->model = $model;
        $this->prefix = $prefix;
    }

    // ── Conversioni, identiche a quelle dell'import da file ─────────────────

    public static function dec($v): ?float
    {
        if ($v === '' || $v === null) return null;
        $s = str_replace([' ', ','], ['', '.'], (string)$v);
        return is_numeric($s) ? (float)$s : null;
    }

    public static function intv($v): ?int
    {
        return ($v === '' || $v === null) ? null : (int)$v;
    }

    /** Il gestionale usa 'YYYY-MM-DD HH:MM:SS.mmm'; accetta anche oggetti data. */
    public static function date($v): ?string
    {
        if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
        $t = trim((string)$v);
        if ($t === '' || str_starts_with($t, '0000')) return null;
        $d = substr($t, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
    }

    public static function status($v): ?string
    {
        $raw = trim((string)$v);
        return self::STATUS_MAP[strtoupper($raw)] ?? ($raw !== '' ? $raw : null);
    }

    /**
     * Costruisce la SELECT sulla sorgente, chiedendo solo le colonne mappate
     * effettivamente presenti: una sorgente con qualche colonna in meno resta
     * utilizzabile invece di far fallire l'intera sincronizzazione.
     *
     * @param string[] $available colonne realmente esposte dalla tabella
     */
    public static function buildSelect(SourceDb $src, string $table, string $schema,
                                       array $available, int $limit = 0): array
    {
        $availLower = array_change_key_case(array_flip(array_map('strval', $available)), CASE_LOWER);
        $use = [];
        foreach (array_keys(self::COLUMN_MAP) as $c) {
            if (isset($availLower[strtolower($c)])) $use[] = $c;
        }
        $missing = array_diff(self::REQUIRED, $use);
        if ($missing) {
            throw new RuntimeException('Colonne obbligatorie assenti nella tabella sorgente: '
                . implode(', ', $missing) . '. Verificare tabella e schema indicati.');
        }
        $cols = implode(', ', array_map(fn($c) => $src->quoteIdent($c), $use));
        $lim  = $limit > 0 ? $src->limitClause($limit) : ['prefix' => '', 'suffix' => ''];
        $sql  = "SELECT {$lim['prefix']}$cols FROM " . $src->qualify($table, $schema) . $lim['suffix'];
        return [$sql, $use];
    }

    /**
     * Esegue la sincronizzazione.
     *
     * @param callable|null $onProgress ricevuto ogni 500 righe, per il log
     * @return array<string,int|string> riepilogo
     */
    public function run(SourceDb $src, array $cfg, int $userId, bool $includeDeleted = false,
                        int $limit = 0, bool $dryRun = false): array
    {
        $table  = (string)($cfg['source_table'] ?? 'contract');
        $schema = (string)($cfg['source_schema'] ?? '');

        $available = $src->columnsOf($table, $schema);
        [$sql, $used] = self::buildSelect($src, $table, $schema, $available, $limit);

        $batchId = 0;
        if (!$dryRun) {
            $this->pdo->prepare("INSERT INTO cm_import_batches (filename,kind,rows_total,created_by) VALUES (?,?,?,?)")
                ->execute([sprintf('%s@%s/%s.%s', $cfg['driver'] ?? '', $cfg['host'] ?? '', $cfg['dbname'] ?? '', $table),
                           'commesse_db', 0, $userId]);
            $batchId = (int)$this->pdo->lastInsertId();
        }

        $stExists = $this->pdo->prepare("SELECT id FROM cm_projects WHERE project_code=?");
        $up = $this->pdo->prepare(
            "INSERT INTO cm_projects
               (project_code,name,client_id,client_raw,exec_company_id,operational_status,
                description,internal_description,start_date,end_date,
                value_total,value_todate,actual_cost,margin_total,margin_todate,
                residual_total,residual_todate,anomalies_open,anomalies_blocking,
                dgb_contract_id,import_batch_id,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               name=VALUES(name),client_id=COALESCE(VALUES(client_id),client_id),
               client_raw=VALUES(client_raw),
               exec_company_id=COALESCE(VALUES(exec_company_id),exec_company_id),
               operational_status=VALUES(operational_status),
               description=VALUES(description),internal_description=VALUES(internal_description),
               start_date=VALUES(start_date),end_date=VALUES(end_date),
               value_total=VALUES(value_total),value_todate=VALUES(value_todate),
               actual_cost=VALUES(actual_cost),
               margin_total=VALUES(margin_total),margin_todate=VALUES(margin_todate),
               residual_total=VALUES(residual_total),residual_todate=VALUES(residual_todate),
               anomalies_open=VALUES(anomalies_open),anomalies_blocking=VALUES(anomalies_blocking),
               dgb_contract_id=COALESCE(VALUES(dgb_contract_id),dgb_contract_id),
               import_batch_id=VALUES(import_batch_id)"
        );

        // v1.8.41: assorbimento dei segnaposto della sincronizzazione DGB
        $stPlaceholder = $this->pdo->prepare(
            "SELECT id FROM cm_projects WHERE dgb_contract_id=? AND project_code LIKE 'DGB-%' AND id<>?");
        $DEPS = ['cm_intervention_reports','cm_team','cm_timesheet_entries','cm_presales_effort',
                 'cm_workflow_steps','cm_project_band_rates','cm_project_updates',
                 'cm_project_update_files','cm_project_phases','cm_alias_project','cm_alias_band'];
        $stMove = [];
        foreach ($DEPS as $t) {
            try { $stMove[$t] = $this->pdo->prepare("UPDATE `$t` SET project_id=? WHERE project_id=?"); }
            catch (Throwable $e) { /* tabella assente */ }
        }
        $stDrop = $this->pdo->prepare("DELETE FROM cm_projects WHERE id=? AND project_code LIKE 'DGB-%'");

        $total = 0; $ins = 0; $upd = 0; $skip = 0; $skipDeleted = 0; $absorbed = 0;
        $preview = [];

        $st = $src->query($sql);
        if (!$dryRun) $this->pdo->beginTransaction();

        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $total++;
            $g = function (string $c) use ($row) {
                foreach ($row as $k => $v) if (strcasecmp((string)$k, $c) === 0) return $v;
                return '';
            };

            $code = trim((string)$g('code'));
            if ($code === '') { $skip++; continue; }
            if (!$includeDeleted && trim((string)$g('deleted')) === '1') { $skipDeleted++; continue; }

            $stExists->execute([$code]);
            $exists = (bool)$stExists->fetchColumn();

            $clientRaw = trim((string)$g('customer_comp_name'));
            $sourceId  = self::intv($g('id'));

            if ($dryRun) {
                if (count($preview) < 25) {
                    $preview[] = [
                        'code' => $code, 'name' => trim((string)$g('name')) ?: $code,
                        'client' => $clientRaw, 'status' => self::status($g('status')),
                        'start' => self::date($g('start_date')), 'end' => self::date($g('end_date')),
                        'value' => self::dec($g('economic_value')),
                        'azione' => $exists ? 'aggiorna' : 'inserisce',
                    ];
                }
                $exists ? $upd++ : $ins++;
                continue;
            }

            $clientId = $clientRaw !== '' ? $this->model->upsertClient($clientRaw) : null;

            $up->execute([
                $code,
                trim((string)$g('name')) ?: $code,
                $clientId, $clientRaw ?: null, $this->prefix->companyId($code),
                self::status($g('status')),
                ((string)$g('description') !== '' ? (string)$g('description') : null),
                ((string)$g('internal_description') !== '' ? (string)$g('internal_description') : null),
                self::date($g('start_date')), self::date($g('end_date')),
                self::dec($g('economic_value')), self::dec($g('economic_value_till_now')),
                self::dec($g('time_material_costs')),
                self::dec($g('margin_value')), self::dec($g('margin_value_till_now')),
                self::dec($g('residual_value')), self::dec($g('residual_value_till_now')),
                self::intv($g('n_ano_open')) ?? 0, self::intv($g('n_ano_open_block')) ?? 0,
                $sourceId, $batchId, $userId,
            ]);
            $exists ? $upd++ : $ins++;

            if ($sourceId) {
                $stExists->execute([$code]);
                $realId = (int)$stExists->fetchColumn();
                if ($realId) {
                    $stPlaceholder->execute([$sourceId, $realId]);
                    foreach ($stPlaceholder->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                        foreach ($stMove as $m) {
                            try { $m->execute([$realId, (int)$oldId]); } catch (Throwable $e) {}
                        }
                        $stDrop->execute([(int)$oldId]);
                        $absorbed++;
                    }
                }
            }

            if (($ins + $upd) % 500 === 0) { $this->pdo->commit(); $this->pdo->beginTransaction(); }
        }
        $st->closeCursor();

        if (!$dryRun) {
            if ($this->pdo->inTransaction()) $this->pdo->commit();
            $this->pdo->prepare("UPDATE cm_import_batches SET rows_total=?, rows_ok=?, rows_unmatched=? WHERE id=?")
                ->execute([$total, $ins + $upd, $skip + $skipDeleted, $batchId]);
        }

        return [
            'total' => $total, 'ins' => $ins, 'upd' => $upd,
            'skip' => $skip, 'skip_deleted' => $skipDeleted,
            'absorbed' => $absorbed, 'batch' => $batchId,
            'columns_used' => implode(', ', $used),
            'preview' => $preview,
        ];
    }
}
