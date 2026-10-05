<?php
/**
 * PortalManager — app/PrjRepo.php  (v1.10.00)
 *
 * Accesso ai dati dei Progetti PRJ:
 *  - lettura "as-of" delle tabelle versionate (valid_from <= d AND (valid_to IS NULL OR valid_to >= d));
 *  - costruzione dell'input del motore PrjCalc per progetto, scenario e data;
 *  - calc run immutabili (cm_prj_calc_run + cm_prj_calc_result) con hash SHA-256 dei parametri;
 *  - scrittura versionata (chiude la versione vigente, apre la nuova, EntityChangeLog);
 *  - generazione atomica del codice PRJ-AAAA-NNNN.
 * Nessuna scrittura su cm_projects.
 */
declare(strict_types=1);

require_once __DIR__ . '/PrjCalc.php';

final class PrjRepo
{
    /** Tabelle versionate e colonne della chiave naturale (oltre a version_no). */
    public const VERSIONED = [
        'cm_prj_gara' => ['prj_id'], 'cm_prj_tender_base' => ['prj_id', 'year'], 'cm_prj_rate_card' => ['prj_id', 'profilo'],
        'cm_prj_service' => ['prj_id', 'codice'], 'cm_prj_asset_metric' => ['prj_id', 'metrica', 'year'],
        'cm_prj_ticket_mapping' => ['group_id', 'service_id'], 'cm_prj_ticket_volume' => ['group_id', 'year', 'tipo'],
        'cm_prj_aht' => ['prj_id', 'tipo', 'service_id'], 'cm_prj_productivity' => ['prj_id'],
        'cm_prj_profile_req' => ['profile_id'], 'cm_prj_service_profile' => ['profile_id', 'service_id'],
        'cm_prj_salary_band' => ['profile_id'], 'cm_prj_param' => ['prj_key', 'chiave'], 'cm_prj_zone' => ['prj_key', 'nome'],
        'cm_prj_nearshore' => ['prj_key', 'paese'], 'cm_prj_equipment' => ['prj_key', 'voce'], 'cm_prj_site_cost' => ['prj_key', 'voce'],
        'cm_prj_overhead' => ['prj_key', 'voce'], 'cm_prj_kpi' => ['prj_id', 'codice'], 'cm_prj_criterion' => ['prj_id', 'codice'],
    ];

    public function __construct(private PDO $pdo) {}

    /**
     * Condizione as-of con un solo segnaposto (PDO con ATTR_EMULATE_PREPARES = false non ammette
     * lo stesso segnaposto nominale ripetuto): :d BETWEEN valid_from AND COALESCE(valid_to, '9999-12-31').
     */
    private static function asOf(string $a = '', string $ph = ':d'): string
    {
        $p = $a !== '' ? "$a." : '';
        return "$ph BETWEEN {$p}valid_from AND COALESCE({$p}valid_to, '9999-12-31')";
    }

    private function rows(string $sql, array $args): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Parametri globali sovrascritti da quelli di progetto (stessa chiave), as-of. */
    public function params(int $prjId, string $d): array
    {
        $p = [];
        foreach ($this->rows("SELECT prj_key, chiave, valore FROM cm_prj_param WHERE prj_key IN (0, :p) AND " . self::asOf() . " ORDER BY prj_key",
                             [':p' => $prjId, ':d' => $d]) as $r) $p[$r['chiave']] = (float)$r['valore'];
        if (!isset($p['oneri_pct'])) {
            // default: moltiplicatore costo pieno HR dell'anno (FullCost = RAL × mult_fc) − 1
            $mf = $this->rows("SELECT ref_value FROM hr_reference_values WHERE ref_key = 'hr_mult_fc' AND year <= :y ORDER BY year DESC LIMIT 1",
                              [':y' => (int)substr($d, 0, 4)]);
            if ($mf) $p['oneri_pct'] = (float)$mf[0]['ref_value'] - 1;
        }
        return $p;
    }

    /** Righe globali + di progetto con la stessa chiave: prevale il progetto. */
    private function scoped(string $table, string $key, int $prjId, string $d, string $cols = '*'): array
    {
        $out = [];
        foreach ($this->rows("SELECT $cols, prj_key FROM `$table` WHERE prj_key IN (0, :p) AND " . self::asOf() . " ORDER BY prj_key, id",
                             [':p' => $prjId, ':d' => $d]) as $r) $out[$r[$key]] = $r;
        return array_values($out);
    }

    /**
     * Input completo del motore per un progetto e uno scenario alla data $asOf.
     * @throws RuntimeException se progetto o scenario non esistono
     */
    public function input(int $prjId, int $scenarioId, ?string $asOf = null): array
    {
        $d = $asOf ?: date('Y-m-d');
        $prj = $this->rows("SELECT * FROM cm_prj WHERE id = :p", [':p' => $prjId])[0] ?? null;
        if (!$prj) throw new RuntimeException("Progetto PRJ #$prjId inesistente.");
        $sc = $this->rows("SELECT * FROM cm_prj_scenario WHERE id = :s AND prj_id = :p", [':s' => $scenarioId, ':p' => $prjId])[0] ?? null;
        if (!$sc) throw new RuntimeException("Scenario #$scenarioId inesistente per il progetto.");
        $A = [':p' => $prjId, ':d' => $d];

        $services = [];
        foreach ($this->rows("SELECT ent_id, codice, nome, modalita, h24, avvio_anno FROM cm_prj_service WHERE prj_id = :p AND " . self::asOf() . " ORDER BY codice", $A) as $s)
            $services[(int)$s['ent_id']] = $s;

        $zones = [];
        foreach ($this->scoped('cm_prj_zone', 'nome', $prjId, $d, 'ent_id, nome, indice_ral, affitto_mq_mese') as $z) $zones[(int)$z['ent_id']] = $z;
        $near = [];
        foreach ($this->scoped('cm_prj_nearshore', 'paese', $prjId, $d, 'ent_id, paese, indice_ral, oneri_pct') as $n) $near[(int)$n['ent_id']] = $n;

        $lines = $this->rows(
            "SELECT pr.id AS profile_id, pr.codice, pr.nome, pr.tipo, pr.h24, pr.nearshore_ammesso,
                    sp.service_id, sp.n_minimo, sp.fte, sb.ral_min, sb.ral_ideale, sb.zona_base_id
               FROM cm_prj_profile pr
               JOIN cm_prj_service_profile sp ON sp.profile_id = pr.id AND " . self::asOf('sp', ':d1') . "
               LEFT JOIN cm_prj_salary_band sb ON sb.profile_id = pr.id AND " . self::asOf('sb', ':d2') . "
              WHERE pr.prj_id = :p ORDER BY pr.ordine, pr.id", [':p' => $prjId, ':d1' => $d, ':d2' => $d]);
        foreach ($lines as &$l) $l['servizio'] = (int)$l['service_id'] ? ($services[(int)$l['service_id']]['codice'] ?? '?') : 'GOV';
        unset($l);

        $ov = [];
        foreach ($this->rows("SELECT * FROM cm_prj_scenario_profile WHERE scenario_id = :s", [':s' => $scenarioId]) as $o)
            $ov[$o['profile_id'] . ':' . $o['service_id']] = $o;

        $tender = [];
        foreach ($this->rows("SELECT year, canone_eur, uncommitted_eur FROM cm_prj_tender_base WHERE prj_id = :p AND " . self::asOf() . " ORDER BY year", $A) as $t)
            $tender[(int)$t['year']] = $t;

        $volumes = $this->rows("SELECT group_id, year, tipo, quantita FROM cm_prj_ticket_volume WHERE prj_id = :p AND " . self::asOf(), $A);
        $years = array_map(fn($v) => (int)$v['year'], $volumes);

        return [
            'prj'          => ['id' => (int)$prj['id'], 'prj_code' => $prj['prj_code'], 'nome' => $prj['nome'], 'sp_project_id' => $prj['sp_project_id'] !== null ? (int)$prj['sp_project_id'] : null],
            'as_of'        => $d,
            'year'         => $years ? max($years) : 0,
            'params'       => $this->params($prjId, $d),
            'productivity' => $this->rows("SELECT ore_utili_fte, giorni_fte, uplift, banda_volumi FROM cm_prj_productivity WHERE prj_id = :p AND " . self::asOf(), $A)[0]
                              ?? ['ore_utili_fte' => 1600, 'giorni_fte' => 220, 'uplift' => 0, 'banda_volumi' => 0.2],
            'gara'         => $this->rows("SELECT durata_mesi, mesi_operativi, phase_in_giorni, handover_giorni, rinnovo_mesi FROM cm_prj_gara WHERE prj_id = :p AND " . self::asOf(), $A)[0] ?? [],
            'services'     => $services,
            'volumes'      => $volumes,
            'mapping'      => $this->rows("SELECT group_id, service_id, quota FROM cm_prj_ticket_mapping WHERE prj_id = :p AND " . self::asOf(), $A),
            'aht'          => $this->rows("SELECT tipo, service_id, ore FROM cm_prj_aht WHERE prj_id = :p AND " . self::asOf(), $A),
            'lines'        => $lines,
            'zones'        => $zones,
            'nearshore'    => $near,
            'equipment'    => $this->scoped('cm_prj_equipment', 'voce', $prjId, $d, 'voce, prezzo, anni_ammortamento'),
            'site'         => $this->scoped('cm_prj_site_cost', 'voce', $prjId, $d, 'voce, eur_mese'),
            'overhead'     => $this->scoped('cm_prj_overhead', 'voce', $prjId, $d, 'voce, tipo, importo'),
            'tender'       => $tender,
            'scenario'     => [
                'id' => (int)$sc['id'], 'nome' => $sc['nome'], 'tipo' => $sc['tipo'], 'zona_id' => $sc['zona_id'] !== null ? (int)$sc['zona_id'] : null,
                'ral_mode' => $sc['ral_mode'], 'ribasso_pct' => (float)$sc['ribasso_pct'],
                'margine_target_pct' => $sc['margine_target_pct'] !== null ? (float)$sc['margine_target_pct'] : null,
                'nearshore_id' => $sc['nearshore_id'] !== null ? (int)$sc['nearshore_id'] : null, 'nearshore_scope' => $sc['nearshore_scope'],
                'fte_supporto_sostenibile' => $sc['fte_supporto_sostenibile'] !== null ? (float)$sc['fte_supporto_sostenibile']
                                              : (float)($this->params($prjId, $d)['fte_supporto_sostenibile'] ?? 0),
                'overrides' => $ov,
            ],
        ];
    }

    /** Calcolo senza salvataggio. */
    public function calc(int $prjId, int $scenarioId, ?string $asOf = null): array
    {
        return PrjCalc::scenario($this->input($prjId, $scenarioId, $asOf));
    }

    /**
     * Calc run immutabile: input (snapshot JSON + hash), versione software/schema, risultati appiattiti.
     * @return array{run_id:int, result:array}
     */
    public function saveRun(int $prjId, int $scenarioId, ?string $asOf, ?int $userId): array
    {
        $in  = $this->input($prjId, $scenarioId, $asOf);
        $res = PrjCalc::scenario($in);
        $ver = $this->pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('app_version','schema_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT INTO cm_prj_calc_run (prj_id, scenario_id, scenario_nome, sp_project_id, as_of, params_hash, input_json, app_version, schema_version, user_id)
                                 VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$prjId, $scenarioId, $in['scenario']['nome'], $in['prj']['sp_project_id'], $in['as_of'], PrjCalc::hash($in),
                           json_encode($in, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                           defined('PM_VERSION') ? PM_VERSION : (string)($ver['app_version'] ?? ''), (string)($ver['schema_version'] ?? ''), $userId]);
            $runId = (int)$this->pdo->lastInsertId();
            $ins = $this->pdo->prepare("INSERT INTO cm_prj_calc_result (run_id, ambito, ambito_ref, metrica, valore) VALUES (?,?,?,?,?)");
            foreach ($res['totali'] as $k => $v) if (is_numeric($v)) $ins->execute([$runId, 'totale', '', $k, $v]);
            foreach ($res['ticket']['rows'] as $r)
                foreach (['ticket', 'ore', 'fte_ticket', 'fte_uplift', 'fte_allocati', 'fte_finale'] as $k) $ins->execute([$runId, 'servizio', $r['codice'], $k, $r[$k]]);
            $agg = [];
            foreach ($res['profili'] as $r) {
                $ref = $r['codice'] . '@' . $r['servizio'];
                foreach (['fte', 'ral_zona', 'costo_az_fte', 'costo_aziendale', 'strutturale'] as $k) $agg[$ref][$k] = ($agg[$ref][$k] ?? 0) + $r[$k];
            }
            foreach ($agg as $ref => $m) foreach ($m as $k => $v) $ins->execute([$runId, 'profilo', $ref, $k, $v]);
            foreach ($res['anni'] as $a) foreach (['fte', 'costo', 'canone', 'margine', 'pct_canone'] as $k) $ins->execute([$runId, 'anno', (string)$a['anno'], $k, $a[$k]]);
            $ins->execute([$runId, 'zona', $res['scenario']['zona'], 'strutturale_ufficio_fte', $res['strutturale_zona']['ufficio']]);
            if ($own) $this->pdo->commit();
        } catch (Throwable $e) {
            if ($own) $this->pdo->rollBack();
            throw $e;
        }
        return ['run_id' => $runId, 'result' => $res];
    }

    /** Risultati di un run: [ambito][ambito_ref][metrica] = valore. */
    public function runResults(int $runId): array
    {
        $o = [];
        foreach ($this->rows("SELECT ambito, ambito_ref, metrica, valore FROM cm_prj_calc_result WHERE run_id = :r", [':r' => $runId]) as $r)
            $o[$r['ambito']][$r['ambito_ref']][$r['metrica']] = $r['valore'] !== null ? (float)$r['valore'] : null;
        return $o;
    }

    /** Confronto tra run: delta assoluto e % sulle metriche comuni. */
    public function compareRuns(int $runA, int $runB): array
    {
        $a = $this->runResults($runA); $b = $this->runResults($runB); $o = [];
        foreach ($a as $amb => $refs) foreach ($refs as $ref => $ms) foreach ($ms as $m => $va) {
            if (!isset($b[$amb][$ref]) || !array_key_exists($m, $b[$amb][$ref])) continue;
            $vb = $b[$amb][$ref][$m];
            $o[] = ['ambito' => $amb, 'ref' => $ref, 'metrica' => $m, 'a' => $va, 'b' => $vb,
                    'delta' => ($va !== null && $vb !== null) ? $vb - $va : null,
                    'delta_pct' => ($va && $vb !== null) ? ($vb - $va) / abs($va) : null];
        }
        return $o;
    }

    /**
     * Scrittura versionata: chiude la versione vigente il giorno prima di $validFrom e ne apre una nuova.
     * $id = id della riga vigente; $data = solo i campi che cambiano. Ritorna l'id della nuova versione.
     */
    public function writeVersion(string $table, int $id, array $data, string $validFrom, ?int $userId, string $note = '', string $source = 'ui'): int
    {
        if (!isset(self::VERSIONED[$table])) throw new InvalidArgumentException("Tabella non versionata: $table");
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM `$table` WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old || (int)$old['is_current'] !== 1) throw new RuntimeException('Versione vigente non trovata.');
            if ($validFrom <= $old['valid_from']) throw new RuntimeException('La nuova versione deve decorrere dopo il ' . $old['valid_from'] . '.');
            $cols = []; $st = $this->pdo->query("SHOW COLUMNS FROM `$table`");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) if (stripos((string)$c['Extra'], 'GENERATED') === false && $c['Field'] !== 'id') $cols[] = $c['Field'];
            foreach (array_keys($data) as $k) if (!in_array($k, $cols, true) || in_array($k, ['ent_id', 'valid_from', 'valid_to', 'version_no', 'is_current', 'created_by', 'created_at'], true))
                throw new InvalidArgumentException("Campo non modificabile: $k");
            $this->pdo->prepare("UPDATE `$table` SET valid_to = DATE_SUB(?, INTERVAL 1 DAY), is_current = 0 WHERE id = ?")->execute([$validFrom, $id]);
            $new = array_intersect_key($old, array_flip($cols));
            $new = array_merge($new, $data, ['ent_id' => $old['ent_id'] ?? $id, 'valid_from' => $validFrom, 'valid_to' => null,
                                             'version_no' => (int)$old['version_no'] + 1, 'is_current' => 1, 'created_by' => $userId,
                                             'created_at' => date('Y-m-d H:i:s'), 'change_note' => $note !== '' ? $note : null]);
            $this->pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', array_keys($new)) . "`) VALUES (" . implode(',', array_fill(0, count($new), '?')) . ")")
                ->execute(array_values($new));
            $newId = (int)$this->pdo->lastInsertId();
            if (class_exists('EntityChangeLog') || is_file(__DIR__ . '/EntityChangeLog.php')) {
                require_once __DIR__ . '/EntityChangeLog.php';
                (new EntityChangeLog($this->pdo))->diffAndLog($table, (int)($old['ent_id'] ?? $id), $old, $new + $old, 'update', $source, null, $userId,
                    ['updated_at', 'created_at', 'updated_by', 'id', 'valid_from', 'valid_to', 'version_no', 'is_current', 'created_by', 'change_note', 'ent_id']);
            }
            if ($own) $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            if ($own) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Codice PRJ-AAAA-NNNN atomico (SELECT … FOR UPDATE); il numero non viene mai riutilizzato. */
    public function nextCode(int $year): string
    {
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("INSERT IGNORE INTO cm_prj_sequence (year, last_no) VALUES (?, 0)")->execute([$year]);
            $st = $this->pdo->prepare("SELECT last_no FROM cm_prj_sequence WHERE year = ? FOR UPDATE");
            $st->execute([$year]);
            $n = (int)$st->fetchColumn() + 1;
            $this->pdo->prepare("UPDATE cm_prj_sequence SET last_no = ? WHERE year = ?")->execute([$n, $year]);
            if ($own) $this->pdo->commit();
        } catch (Throwable $e) {
            if ($own) $this->pdo->rollBack();
            throw $e;
        }
        $code = sprintf('PRJ-%04d-%04d', $year, $n);
        if (!preg_match('/^PRJ-[0-9]{4}-[0-9]{4}$/', $code)) throw new RuntimeException('Progressivo annuale esaurito.');
        return $code;
    }
}
