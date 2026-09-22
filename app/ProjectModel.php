<?php
/**
 * app/ProjectModel.php — Modulo Gestione Commesse (v1.7.59)
 * Model dati + calcolo redditività previsionale e consuntivo.
 */
require_once __DIR__ . '/RateResolver.php';
require_once __DIR__ . '/PrefixResolver.php';
require_once __DIR__ . '/AliasStore.php';

final class ProjectModel
{
    private PDO $pdo;
    private float $annualHours = 1720.0;
    private float $oneriMult   = 1.42;
    private array $presalesRates = [
        'Ufficio Gare' => 35.0, 'Sicurezza' => 40.0,
        'Ingegneria/Analisi Tecnica' => 55.0, 'Project Management' => 60.0,
    ];

    private ?AliasStore $alias = null;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->loadConfig(); }

    /** v1.7.67: alias di riconciliazione (lazy). */
    private function alias(): AliasStore { return $this->alias ??= new AliasStore($this->pdo); }

    private function loadConfig(): void
    {
        try {
            $rows = $this->pdo->query(
                "SELECT setting_key, setting_value FROM app_settings
                 WHERE setting_key IN ('proj_annual_hours','proj_oneri_mult')"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            if (isset($rows['proj_annual_hours'])) $this->annualHours = (float)$rows['proj_annual_hours'];
            if (isset($rows['proj_oneri_mult']))   $this->oneriMult   = (float)$rows['proj_oneri_mult'];
        } catch (\Exception $e) { /* default */ }
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────
    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT p.*, cl.name AS client_name, co.name AS exec_company_name
             FROM cm_projects p
             LEFT JOIN clients   cl ON cl.id = p.client_id
             LEFT JOIN companies co ON co.id = p.exec_company_id
             WHERE p.id = ?"
        );
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByCode(string $code): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_projects WHERE project_code = ?");
        $st->execute([$code]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * v1.8.40: SELECT esteso a tutti i campi dell'export standard "Lista commesse"
     * (29 colonne allineate al file `export_lista_commesse.xlsx`), con filtri
     * aggiuntivi su stato economico, compliance, anomalie e stato commerciale
     * "aperto/persa/etc.". Retro-compatibile con le viste esistenti.
     */
    public function listAll(array $filters = []): array
    {
        $sql = "SELECT p.id, p.project_code, p.name, p.abbr, p.commercial_ref, p.external_link,
                       p.service_line, p.project_type, p.description, p.internal_description,
                       p.operational_status, p.commercial_status,
                       p.economic_status, p.economic_status_todate,
                       p.compliance_to_verify, p.compliance_preauth,
                       p.anomalies_open, p.anomalies_blocking,
                       p.start_date, p.end_date, p.first_billing_date, p.billing_freq_months,
                       p.value_total, p.value_todate, p.actual_cost,
                       p.margin_total, p.margin_todate,
                       p.residual_total, p.residual_todate,
                       p.credit_on_value, p.credit_on_costs,
                       p.material_costs,
                       p.client_id, p.client_raw, p.exec_company_id,
                       cl.name AS client_name, co.name AS exec_company_name
                FROM cm_projects p
                LEFT JOIN clients cl   ON cl.id = p.client_id
                LEFT JOIN companies co ON co.id = p.exec_company_id";
        $where = []; $args = [];
        if (!empty($filters['status']))      { $where[] = "p.operational_status = ?";  $args[] = $filters['status']; }
        if (!empty($filters['commercial']))  { $where[] = "p.commercial_status = ?";   $args[] = $filters['commercial']; }
        if (!empty($filters['type']))        { $where[] = "p.project_type = ?";        $args[] = $filters['type']; }
        if (!empty($filters['service_line'])){ $where[] = "p.service_line = ?";         $args[] = $filters['service_line']; }
        if (!empty($filters['company_id']))  { $where[] = "p.exec_company_id = ?";      $args[] = (int)$filters['company_id']; }
        if (!empty($filters['client_id']))   { $where[] = "p.client_id = ?";            $args[] = (int)$filters['client_id']; }
        if (isset($filters['value_min']) && $filters['value_min'] !== '' && $filters['value_min'] !== null) { $where[] = "p.value_total >= ?"; $args[] = (float)$filters['value_min']; }
        if (isset($filters['value_max']) && $filters['value_max'] !== '' && $filters['value_max'] !== null) { $where[] = "p.value_total <= ?"; $args[] = (float)$filters['value_max']; }
        if (!empty($filters['from']))        { $where[] = "p.start_date >= ?";         $args[] = $filters['from']; }
        if (!empty($filters['to']))          { $where[] = "p.start_date <= ?";         $args[] = $filters['to']; }
        if (!empty($filters['q']))           { $where[] = "(p.project_code LIKE ? OR p.name LIKE ? OR p.abbr LIKE ? OR p.description LIKE ? OR p.internal_description LIKE ?)"; for($i=0;$i<5;$i++) $args[]="%{$filters['q']}%"; }
        if (!empty($filters['econ']))        { $where[] = "p.economic_status = ?";      $args[] = $filters['econ']; }
        if (!empty($filters['econ_today']))  { $where[] = "p.economic_status_todate = ?"; $args[] = $filters['econ_today']; }
        if (!empty($filters['commercial_ref'])){ $where[] = "p.commercial_ref LIKE ?"; $args[]="%{$filters['commercial_ref']}%"; }
        if (isset($filters['compliance_to_verify']) && $filters['compliance_to_verify'] !== '') { $where[] = "p.compliance_to_verify = ?"; $args[] = (int)$filters['compliance_to_verify']; }
        if (isset($filters['compliance_preauth']) && $filters['compliance_preauth'] !== '')     { $where[] = "p.compliance_preauth = ?";   $args[] = (int)$filters['compliance_preauth']; }
        if (!empty($filters['anom_blocking'])) { $where[] = "p.anomalies_blocking > 0"; }
        if (!empty($filters['anom_open']))     { $where[] = "p.anomalies_open > 0"; }

        // ── v1.8.47: copertura completa delle 29 colonne standard ────────────
        // Ogni colonna dell'elenco è ora filtrabile, così il pannello filtri e la
        // tabella espongono lo stesso insieme di informazioni.
        if (!empty($filters['abbr']))        { $where[] = "p.abbr LIKE ?";             $args[]="%{$filters['abbr']}%"; }
        if (!empty($filters['client_raw']))  { $where[] = "(cl.name LIKE ? OR p.client_raw LIKE ?)"; $args[]="%{$filters['client_raw']}%"; $args[]="%{$filters['client_raw']}%"; }
        if (!empty($filters['descr']))       { $where[] = "(p.description LIKE ? OR p.internal_description LIKE ?)"; $args[]="%{$filters['descr']}%"; $args[]="%{$filters['descr']}%"; }
        // presenza del collegamento al gestionale
        if (($filters['has_link'] ?? '') === '1') { $where[] = "(p.external_link IS NOT NULL AND p.external_link <> '')"; }
        if (($filters['has_link'] ?? '') === '0') { $where[] = "(p.external_link IS NULL OR p.external_link = '')"; }
        // finestra sulla data di fine, distinta da quella sulla data di inizio
        if (!empty($filters['end_from']))    { $where[] = "p.end_date >= ?";           $args[] = $filters['end_from']; }
        if (!empty($filters['end_to']))      { $where[] = "p.end_date <= ?";           $args[] = $filters['end_to']; }
        // commesse senza data di fine, cioè a tempo indeterminato
        if (!empty($filters['no_end']))      { $where[] = "p.end_date IS NULL"; }
        // scadenza entro N giorni: utile per il monitoraggio dei rinnovi
        if (!empty($filters['ending_days'])) { $where[] = "p.end_date IS NOT NULL AND p.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)"; $args[] = (int)$filters['ending_days']; }
        // soglie sulle grandezze economiche
        foreach ([
            'margin_min'   => ['p.margin_total >= ?',   'f'],
            'margin_max'   => ['p.margin_total <= ?',   'f'],
            'residual_min' => ['p.residual_total >= ?', 'f'],
            'residual_max' => ['p.residual_total <= ?', 'f'],
            'cost_min'     => ['p.actual_cost >= ?',    'f'],
            'cost_max'     => ['p.actual_cost <= ?',    'f'],
        ] as $k => [$cond, $t]) {
            if (isset($filters[$k]) && $filters[$k] !== '' && $filters[$k] !== null) {
                $where[] = $cond; $args[] = (float)$filters[$k];
            }
        }
        // margine negativo: commesse in perdita
        if (!empty($filters['margin_neg']))  { $where[] = "p.margin_total < 0"; }
        // fidi superati
        if (!empty($filters['overdraft']))   { $where[] = "(COALESCE(p.credit_on_value,0) > 0 OR COALESCE(p.credit_on_costs,0) > 0)"; }
        // fatturazione
        if (!empty($filters['bill_freq']))   { $where[] = "p.billing_freq_months = ?"; $args[] = (int)$filters['bill_freq']; }
        if (!empty($filters['bill_from']))   { $where[] = "p.first_billing_date >= ?"; $args[] = $filters['bill_from']; }
        if (!empty($filters['bill_to']))     { $where[] = "p.first_billing_date <= ?"; $args[] = $filters['bill_to']; }
        // commesse importate da un batch specifico, per verifiche post-import
        if (!empty($filters['batch']))       { $where[] = "p.import_batch_id = ?";     $args[] = (int)$filters['batch']; }
        // solo commesse riconciliate con il gestionale
        if (($filters['has_dgb'] ?? '') === '1') { $where[] = "p.dgb_contract_id IS NOT NULL"; }
        if (($filters['has_dgb'] ?? '') === '0') { $where[] = "p.dgb_contract_id IS NULL"; }

        if ($where) $sql .= " WHERE ".implode(' AND ', $where);

        $order = [
            'code'          => 'p.project_code',
            'code_desc'     => 'p.project_code DESC',
            'name'          => 'p.name',
            'client'        => 'cl.name, p.client_raw',
            'value_desc'    => 'p.value_total DESC',
            'value_asc'     => 'p.value_total ASC',
            'margin_desc'   => 'p.margin_total DESC',
            'margin_asc'    => 'p.margin_total ASC',
            'residual_desc' => 'p.residual_total DESC',
            'cost_desc'     => 'p.actual_cost DESC',
            'start_desc'    => 'p.start_date DESC',
            'start_asc'     => 'p.start_date ASC',
            'end_asc'       => 'p.end_date IS NULL, p.end_date ASC',
            'anom_desc'     => 'p.anomalies_blocking DESC, p.anomalies_open DESC',
        ][$filters['sort'] ?? 'code'] ?? 'p.project_code';
        $sql .= " ORDER BY $order";
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Costo orario pieno dipendente (da RAL) ────────────────────────────────
    public function employeeHourlyCost(float $ral): float
    {
        return $this->annualHours > 0 ? round(($ral * $this->oneriMult) / $this->annualHours, 2) : 0.0;
    }

    // ── Team con classificazioni (Servizio/Non a Valore + Diretto/Indiretto) ──
    public function team(int $projectId): array
    {
        // v1.7.68/87: team con ore effettive dai rapporti; include i professionisti ESTERNI.
        // Rileva se le colonne professionista sono presenti (schema >= 1.7.87).
        $hasProf = false;
        try {
            $hasProf = (bool)$this->pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cm_team' AND COLUMN_NAME='professional_id'")->fetchColumn();
        } catch (Throwable $e) {}

        if ($hasProf) {
            $st = $this->pdo->prepare(
                "SELECT t.id, t.employee_id, t.professional_id, t.allocated_hours, t.role_in_project, t.source,
                        COALESCE(t.member_type, IF(t.professional_id IS NOT NULL,'esterno','dipendente')) AS member_type,
                        COALESCE(e.first_name, p.first_name) AS first_name,
                        COALESCE(e.last_name,  p.last_name)  AS last_name,
                        e.ral,
                        COALESCE(t.employment_type, e.classificazione_finanziaria, IF(t.professional_id IS NOT NULL,'Esterno',NULL)) AS employment_type,
                        d.value_type,
                        COALESCE(re.ore_rapporti, rp.ore_rapporti, 0) AS report_hours,
                        COALESCE(re.n_rapporti,  rp.n_rapporti,  0) AS report_count,
                        p.hourly_cost AS prof_hourly_cost
                 FROM cm_team t
                 LEFT JOIN employees e        ON e.id = t.employee_id
                 LEFT JOIN cm_professionals p ON p.id = t.professional_id
                 LEFT JOIN departments d      ON d.id = e.department_id
                 LEFT JOIN (SELECT technician_id, SUM(quantity_hours) AS ore_rapporti, COUNT(*) AS n_rapporti
                              FROM cm_intervention_reports WHERE project_id = ? AND technician_id IS NOT NULL
                             GROUP BY technician_id) re ON re.technician_id = t.employee_id
                 LEFT JOIN (SELECT technician_professional_id, SUM(quantity_hours) AS ore_rapporti, COUNT(*) AS n_rapporti
                              FROM cm_intervention_reports WHERE project_id = ? AND technician_professional_id IS NOT NULL
                             GROUP BY technician_professional_id) rp ON rp.technician_professional_id = t.professional_id
                 WHERE t.project_id = ?
                 ORDER BY last_name, first_name"
            );
            $st->execute([$projectId, $projectId, $projectId]);
        } else {
            $st = $this->pdo->prepare(
                "SELECT t.id, t.employee_id, NULL AS professional_id, t.allocated_hours, t.role_in_project, t.source,
                        'dipendente' AS member_type,
                        e.first_name, e.last_name, e.ral,
                        COALESCE(t.employment_type, e.classificazione_finanziaria) AS employment_type,
                        d.value_type,
                        COALESCE(r.ore_rapporti, 0) AS report_hours,
                        COALESCE(r.n_rapporti, 0)   AS report_count,
                        NULL AS prof_hourly_cost
                 FROM cm_team t
                 JOIN employees e        ON e.id = t.employee_id
                 LEFT JOIN departments d ON d.id = e.department_id
                 LEFT JOIN (SELECT technician_id, SUM(quantity_hours) AS ore_rapporti, COUNT(*) AS n_rapporti
                              FROM cm_intervention_reports WHERE project_id = ? AND technician_id IS NOT NULL
                             GROUP BY technician_id) r ON r.technician_id = t.employee_id
                 WHERE t.project_id = ?
                 ORDER BY e.last_name, e.first_name"
            );
            $st->execute([$projectId, $projectId]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            // costo orario: dipendente da RAL; esterno dal costo orario del professionista
            if (($r['member_type'] ?? '') === 'esterno' && $r['prof_hourly_cost'] !== null) {
                $r['hourly_cost'] = (float)$r['prof_hourly_cost'];
            } else {
                $r['hourly_cost'] = $this->employeeHourlyCost((float)($r['ral'] ?? 0));
            }
            $r['hr_cost']     = round($r['hourly_cost'] * (float)$r['allocated_hours'], 2);
            $r['report_cost'] = round($r['hourly_cost'] * (float)$r['report_hours'], 2);
        }
        return $rows;
    }

    // ── Effort presales / back-office ─────────────────────────────────────────
    public function presales(int $projectId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_presales_effort WHERE project_id = ? ORDER BY cost_center");
        $st->execute([$projectId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $rate = ($r['hourly_rate'] !== null && $r['hourly_rate'] !== '')
                ? (float)$r['hourly_rate']
                : ($this->presalesRates[$r['cost_center']] ?? 0.0);
            $r['effective_rate'] = $rate;
            $r['cost'] = round($rate * (float)$r['hours'], 2);
        }
        return $rows;
    }

    // ── Rapporti di intervento (consuntivo) ───────────────────────────────────
    public function interventions(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT ir.*, e.first_name, e.last_name
             FROM cm_intervention_reports ir
             LEFT JOIN employees e ON e.id = ir.technician_id
             WHERE ir.project_id = ?
             ORDER BY ir.start_at"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** v1.7.68: rapporti paginati e filtrabili, con tutti i campi per il dettaglio. */
    public function interventionsPaged(int $projectId, array $f = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['ir.project_id = ?']; $args = [$projectId];
        if (!empty($f['q'])) {
            $where[] = "(ir.report_code LIKE ? OR ir.ticket LIKE ? OR ir.technician_raw LIKE ? OR ir.work_done LIKE ?)";
            $like = '%' . $f['q'] . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if (isset($f['approved']) && $f['approved'] !== '') { $where[] = "ir.approved = ?"; $args[] = (int)$f['approved']; }
        if (isset($f['on_call']) && $f['on_call'] !== '')   { $where[] = "ir.on_call = ?";  $args[] = (int)$f['on_call']; }
        $w = implode(' AND ', $where);

        $stC = $this->pdo->prepare("SELECT COUNT(*) FROM cm_intervention_reports ir WHERE $w");
        $stC->execute($args);
        $total = (int)$stC->fetchColumn();

        $limit = max(1, $limit); $offset = max(0, $offset);
        $st = $this->pdo->prepare(
            "SELECT ir.*, e.first_name, e.last_name, cl.name AS client_name, cloc.location_name,
                    b.band_name
             FROM cm_intervention_reports ir
             LEFT JOIN employees e            ON e.id = ir.technician_id
             LEFT JOIN clients cl             ON cl.id = ir.client_id
             LEFT JOIN client_locations cloc  ON cloc.id = ir.client_location_id
             LEFT JOIN cm_rate_bands b        ON b.id = ir.band_id
             WHERE $w ORDER BY ir.start_at DESC, ir.id DESC LIMIT $limit OFFSET $offset"
        );
        $st->execute($args);
        return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    /** v1.7.68: singolo rapporto (per la modifica). */
    public function intervention(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_intervention_reports WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * v1.7.68: allinea il team ai rapporti di intervento.
     * Le righe di origine 'Rapporti' vengono ricalcolate; quelle inserite a mano
     * restano intatte (si aggiorna solo l'eventuale assenza dal team).
     */
    public function syncTeamFromReports(int $projectId, ?int $userId = null): array
    {
        // Dipendenti (technician_id) → team con member_type 'dipendente'
        $st = $this->pdo->prepare(
            "INSERT INTO cm_team (project_id, employee_id, allocated_hours, source, member_type)
             SELECT ir.project_id, ir.technician_id, ROUND(SUM(ir.quantity_hours), 2), 'Rapporti', 'dipendente'
               FROM cm_intervention_reports ir
              WHERE ir.project_id = ? AND ir.technician_id IS NOT NULL
              GROUP BY ir.project_id, ir.technician_id
             ON DUPLICATE KEY UPDATE
               allocated_hours = IF(cm_team.source = 'Rapporti', VALUES(allocated_hours), cm_team.allocated_hours),
               member_type = 'dipendente'"
        );
        $st->execute([$projectId]);
        $affected = $st->rowCount();

        // v1.7.87: Professionisti esterni (technician_professional_id) → team con member_type 'esterno'
        try {
            $sp = $this->pdo->prepare(
                "INSERT INTO cm_team (project_id, professional_id, allocated_hours, source, member_type)
                 SELECT ir.project_id, ir.technician_professional_id, ROUND(SUM(ir.quantity_hours), 2), 'Rapporti', 'esterno'
                   FROM cm_intervention_reports ir
                  WHERE ir.project_id = ? AND ir.technician_professional_id IS NOT NULL
                    AND ir.technician_id IS NULL
                  GROUP BY ir.project_id, ir.technician_professional_id
                 ON DUPLICATE KEY UPDATE
                   allocated_hours = IF(cm_team.source = 'Rapporti', VALUES(allocated_hours), cm_team.allocated_hours),
                   member_type = 'esterno'"
            );
            $sp->execute([$projectId]);
            $affected += $sp->rowCount();
        } catch (Throwable $e) { /* colonne professionista assenti: ignora */ }

        $tot = $this->pdo->prepare("SELECT COUNT(*) FROM cm_team WHERE project_id = ?");
        $tot->execute([$projectId]);
        return ['affected' => $affected, 'team_size' => (int)$tot->fetchColumn()];
    }

    /** v1.7.68: tecnici presenti nei rapporti ma non ancora nel team. */
    public function techniciansMissingFromTeam(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT ir.technician_id, CONCAT(e.last_name,' ',e.first_name) AS nome,
                    ROUND(SUM(ir.quantity_hours),2) AS ore, COUNT(*) AS rapporti
               FROM cm_intervention_reports ir
               JOIN employees e ON e.id = ir.technician_id
              WHERE ir.project_id = ? AND ir.technician_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM cm_team t WHERE t.project_id = ir.project_id AND t.employee_id = ir.technician_id)
              GROUP BY ir.technician_id, nome ORDER BY ore DESC"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function actuals(int $projectId, bool $onlyApproved = false): array
    {
        $sql = "SELECT * FROM cm_intervention_reports WHERE project_id = ?".($onlyApproved ? " AND approved=1" : "");
        $st = $this->pdo->prepare($sql);
        $st->execute([$projectId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = [
            'client_total'=>0.0,'company_total'=>0.0,'hours_total'=>0.0,'count'=>count($rows),
            'ordinary'=>['hours'=>0.0,'company'=>0.0,'client'=>0.0],
            'oncall'  =>['hours'=>0.0,'company'=>0.0,'client'=>0.0],
        ];
        foreach ($rows as $r) {
            $h  = (float)$r['quantity_hours'];
            $cc = (float)$r['company_cost_import'];
            $cl = (float)$r['client_revenue_import'];
            $b  = ((int)$r['on_call'] === 1) ? 'oncall' : 'ordinary';
            $out[$b]['hours'] += $h; $out[$b]['company'] += $cc; $out[$b]['client'] += $cl;
            $out['hours_total'] += $h; $out['company_total'] += $cc; $out['client_total'] += $cl;
        }
        $out['actual_margin'] = round($out['client_total'] - $out['company_total'], 2);
        return $out;
    }

    // ── Redditività previsionale ──────────────────────────────────────────────
    public function profitability(int $projectId): array
    {
        $p = $this->find($projectId); if (!$p) return [];
        $team = $this->team($projectId); $presales = $this->presales($projectId);

        $hrCost       = array_sum(array_column($team, 'hr_cost'));
        $presalesCost = array_sum(array_column($presales, 'cost'));
        $materials    = (float)$p['material_costs'];
        $revenue      = (float)($p['value_total'] ?? 0);   // valore contratto come ricavo

        $lossRischio = 0.0; $lossCommerciale = 0.0;
        if ($p['commercial_status'] === 'Persa') {
            switch ($p['loss_allocation'] ?? 'Rischio Impresa 100%') {
                case 'Budget Commerciale 100%': $lossCommerciale = $presalesCost; break;
                case 'Ripartizione 50/50': $lossRischio = $lossCommerciale = $presalesCost / 2; break;
                default: $lossRischio = $presalesCost;
            }
        }

        $totalCost = $materials + $hrCost + $presalesCost;
        $margin = $revenue - $totalCost;

        $byValue = ['Servizio a Valore'=>['hours'=>0,'cost'=>0],'Non a Valore'=>['hours'=>0,'cost'=>0],'N/D'=>['hours'=>0,'cost'=>0]];
        $byClass = ['Diretto'=>['hours'=>0,'cost'=>0],'Indiretto'=>['hours'=>0,'cost'=>0],'N/D'=>['hours'=>0,'cost'=>0]];
        foreach ($team as $t) {
            $vk = $t['value_type'] ?: 'N/D'; $ck = $t['employment_type'] ?: 'N/D';
            $byValue[$vk]['hours'] += (float)$t['allocated_hours']; $byValue[$vk]['cost'] += (float)$t['hr_cost'];
            $byClass[$ck]['hours'] += (float)$t['allocated_hours']; $byClass[$ck]['cost'] += (float)$t['hr_cost'];
        }

        return [
            'revenue'=>$revenue,'materials'=>$materials,'hr_cost'=>round($hrCost,2),
            'presales_cost'=>round($presalesCost,2),'total_cost'=>round($totalCost,2),
            'margin'=>round($margin,2),'margin_pct'=>$revenue>0?round($margin/$revenue*100,2):0.0,
            'loss_rischio'=>round($lossRischio,2),'loss_commerciale'=>round($lossCommerciale,2),
            'by_value_type'=>$byValue,'by_classification'=>$byClass,
        ];
    }

    // ── Risoluzione tecnico "Cognome Nome" ────────────────────────────────────
    public function resolveTechnician(string $raw): ?int
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw));
        if ($raw === '') return null;
        // v1.7.67: gli alias decisi dall'operatore hanno la precedenza.
        if ($id = $this->alias()->resolve(AliasStore::T_TECHNICIAN, $raw)) return $id;
        $st = $this->pdo->prepare(
            "SELECT id FROM employees
             WHERE CONCAT(last_name,' ',first_name)=? OR CONCAT(first_name,' ',last_name)=? LIMIT 1"
        );
        $st->execute([$raw, $raw]);
        if ($id = $st->fetchColumn()) return (int)$id;
        $st = $this->pdo->prepare("SELECT id FROM employees WHERE CONCAT(last_name,' ',first_name) LIKE ? LIMIT 1");
        $st->execute([$raw.'%']);
        return ($x = $st->fetchColumn()) ? (int)$x : null;
    }

    /** v1.7.67: risoluzione del codice commessa con alias. */
    public function resolveProjectId(string $code): ?int
    {
        $code = trim($code);
        if ($code === '') return null;
        $st = $this->pdo->prepare("SELECT id FROM cm_projects WHERE project_code=?");
        $st->execute([$code]);
        if ($id = $st->fetchColumn()) return (int)$id;
        return $this->alias()->resolve(AliasStore::T_PROJECT, $code);
    }

    // ── Upsert cliente/sede (anagrafica clienti dedicata) ─────────────────────
    public function upsertClient(string $name): ?int
    {
        $name = trim($name); if ($name === '') return null;
        $this->pdo->prepare("INSERT IGNORE INTO clients (name) VALUES (?)")->execute([$name]);
        $st = $this->pdo->prepare("SELECT id FROM clients WHERE name=?");
        $st->execute([$name]);
        return ($id = $st->fetchColumn()) ? (int)$id : null;
    }
    public function upsertClientLocation(int $clientId, string $loc): ?int
    {
        $loc = trim($loc); if ($loc === '' || !$clientId) return null;
        $this->pdo->prepare("INSERT IGNORE INTO client_locations (client_id, location_name) VALUES (?,?)")
             ->execute([$clientId, $loc]);
        $st = $this->pdo->prepare("SELECT id FROM client_locations WHERE client_id=? AND location_name=?");
        $st->execute([$clientId, $loc]);
        return ($id = $st->fetchColumn()) ? (int)$id : null;
    }
}
