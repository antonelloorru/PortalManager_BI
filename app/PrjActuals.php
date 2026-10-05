<?php
/**
 * PortalManager — app/PrjActuals.php  (v1.10.03)
 *
 * Stimato vs Consuntivo di un Progetto PRJ collegato a una commessa SP.
 *
 * Periodo: dalla data di inizio della commessa SP (o, se assente, dalla data di collegamento) al mese corrente,
 * limitato alla fine della commessa. Mesi di calendario.
 *
 * Consuntivo mensile (cm_prj_actual, ricalcolato da refresh()):
 *   ore_report     Σ quantity_hours dei rapporti di intervento della commessa (cm_intervention_reports, data rapporto)
 *   ore_timesheet  Σ hours delle voci manuali di timesheet sulla commessa (Ordinario, Reperibilità, Trasferta)
 *   ore_dgb        Σ ore delle attività DGB del contratto (dgb_contract_id), usate SOLO se il mese non ha rapporti:
 *                  i rapporti sono già sincronizzati dalle attività DGB e sommarli conterebbe due volte le stesse ore
 *   ore            ore_report + ore_timesheet (+ ore_dgb dove mancano i rapporti)
 *   fte            ore / Workload::monthlyCapacity(mese)
 *   costo          dipendenti: ore × cm_employee_cost_year.costo_ora dell'anno (fallback costo del rapporto);
 *                  professionisti: costo aziendale del rapporto (company_cost_calc, altrimenti RateResolver sulla fascia)
 *   ticket         ticket distinti citati nei rapporti
 * Stimato mensile (scenario di riferimento del PRJ, as-of oggi): FTE e costo dell'anno di contratto / 12, canone netto / 12.
 * Scostamenti (cm_prj_deviation): % = (consuntivo − stimato) / stimato per FTE e costo, per mese; alimentano
 * v_cm_prj_alert_da_rilevare (regole prj_scost_fte, prj_scost_costo in cm_alert_rules) letta da AlertEngine.
 * cm_projects è solo letta.
 */
declare(strict_types=1);

require_once __DIR__ . '/PrjRepo.php';

final class PrjActuals
{
    public const METRICHE = ['ore_report', 'ore_timesheet', 'ore_dgb', 'ore', 'fte', 'costo', 'ticket'];

    public function __construct(private PDO $pdo) {}

    /** Commessa SP collegata e periodo di confronto. */
    public function context(int $prjId): ?array
    {
        $st = $this->pdo->prepare("SELECT p.id, p.prj_code, p.sp_project_id, p.sp_linked_at, p.scenario_riferimento_id, s.project_code, s.name, s.start_date, s.end_date,
                                          s.dgb_contract_id, s.value_total, s.value_todate, s.actual_cost, s.margin_total, s.margin_todate, s.commercial_ref
                                     FROM cm_prj p JOIN cm_projects s ON s.id = p.sp_project_id WHERE p.id = ?");
        $st->execute([$prjId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $from = $c['start_date'] ?: ($c['sp_linked_at'] ? substr($c['sp_linked_at'], 0, 10) : date('Y-m-01'));
        $to = date('Y-m-t');
        if ($c['end_date'] && $c['end_date'] < $to) $to = $c['end_date'];
        if ($to < $from) $to = $from;
        $c['from'] = substr($from, 0, 7) . '-01'; $c['to'] = $to;
        $c['months'] = [];
        for ($m = new DateTimeImmutable($c['from']); $m->format('Y-m') <= substr($to, 0, 7) && count($c['months']) < 120; $m = $m->modify('+1 month')) $c['months'][] = $m->format('Y-m');
        return $c;
    }

    /**
     * Ricalcola i consuntivi mensili e gli scostamenti del PRJ.
     * @return array{mesi:int, righe:int}
     */
    public function refresh(int $prjId): array
    {
        $c = $this->context($prjId);
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM cm_prj_actual WHERE prj_id = ? AND origine <> 'manuale'")->execute([$prjId]);
            $this->pdo->prepare("DELETE FROM cm_prj_deviation WHERE prj_id = ?")->execute([$prjId]);
            if (!$c) { if ($own) $this->pdo->commit(); return ['mesi' => 0, 'righe' => 0]; }
            $act = $this->computeActuals($c);
            $ins = $this->pdo->prepare("INSERT INTO cm_prj_actual (prj_id, year, month, service_id, metrica, valore, origine) VALUES (?,?,?,0,?,?,?)
                                        ON DUPLICATE KEY UPDATE valore = VALUES(valore), computed_at = NOW()");
            $n = 0;
            foreach ($act as $ym => $m) {
                [$y, $mm] = array_map('intval', explode('-', $ym));
                foreach ($m as $k => $v) {
                    $orig = match ($k) { 'ore_timesheet' => 'timesheet', 'ore_dgb' => 'dgb', default => 'report' };
                    $ins->execute([$prjId, $y, $mm, $k, round((float)$v, 4), $orig]); $n++;
                }
            }
            $cmp = $this->compare($prjId, $c, $act);
            $dv = $this->pdo->prepare("INSERT INTO cm_prj_deviation (prj_id, sp_project_id, ym, metrica, stimato, consuntivo, scostamento_pct) VALUES (?,?,?,?,?,?,?)");
            foreach ($cmp['mesi'] as $r) foreach (['fte', 'costo'] as $k)
                if ($r['stimato'][$k] !== null && $r['stimato'][$k] > 0 && $r['ore'] > 0)
                    $dv->execute([$prjId, (int)$c['sp_project_id'], $r['ym'], $k, $r['stimato'][$k], $r['consuntivo'][$k], round(($r['consuntivo'][$k] - $r['stimato'][$k]) / $r['stimato'][$k] * 100, 2)]);
            if ($own) $this->pdo->commit();
            return ['mesi' => count($act), 'righe' => $n];
        } catch (Throwable $e) { if ($own) $this->pdo->rollBack(); throw $e; }
    }

    /** Consuntivo mensile dalle fonti del portale. @return array<string,array<string,float>> ym => metrica => valore */
    public function computeActuals(array $c): array
    {
        $sp = (int)$c['sp_project_id']; $from = $c['from']; $to = $c['to'];
        $out = []; foreach ($c['months'] as $ym) $out[$ym] = array_fill_keys(self::METRICHE, 0.0);
        // rapporti di intervento
        $st = $this->pdo->prepare("SELECT DATE_FORMAT(report_date,'%Y-%m') ym, technician_id, technician_professional_id, band_id, on_call,
                                          SUM(quantity_hours) h, SUM(company_cost_calc) cc, SUM(company_cost_import) ci
                                     FROM cm_intervention_reports WHERE project_id = ? AND report_date BETWEEN ? AND ?
                                    GROUP BY ym, technician_id, technician_professional_id, band_id, on_call");
        $st->execute([$sp, $from, $to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $cy = $this->pdo->prepare("SELECT costo_ora FROM cm_employee_cost_year WHERE employee_id = ? AND year <= ? AND costo_ora > 0 ORDER BY year DESC LIMIT 1");
        $rr = null;
        foreach ($rows as $r) {
            if (!isset($out[$r['ym']])) continue;
            $h = (float)$r['h']; $cost = null;
            if ($r['technician_id']) {
                $cy->execute([(int)$r['technician_id'], (int)substr($r['ym'], 0, 4)]);
                $co = $cy->fetchColumn();
                if ($co !== false) $cost = $h * (float)$co;
            }
            if ($cost === null) $cost = (float)$r['cc'] > 0 ? (float)$r['cc'] : (float)$r['ci'];
            if ($cost <= 0 && $r['band_id']) {
                if (!$rr && is_file(__DIR__ . '/RateResolver.php')) { require_once __DIR__ . '/RateResolver.php'; $rr = new RateResolver($this->pdo); }
                if ($rr) $cost = $rr->calcCostsFor((int)$r['band_id'], $h, (bool)$r['on_call'], $sp, $r['technician_professional_id'] ? (int)$r['technician_professional_id'] : null)['company_cost_calc'];
            }
            $out[$r['ym']]['ore_report'] += $h;
            $out[$r['ym']]['costo'] += $cost;
        }
        $st = $this->pdo->prepare("SELECT DATE_FORMAT(report_date,'%Y-%m') ym, COUNT(DISTINCT NULLIF(TRIM(ticket),'')) t FROM cm_intervention_reports
                                    WHERE project_id = ? AND report_date BETWEEN ? AND ? GROUP BY ym");
        $st->execute([$sp, $from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $ym => $t) if (isset($out[$ym])) $out[$ym]['ticket'] = (float)$t;
        // timesheet manuale
        try {
            $st = $this->pdo->prepare("SELECT DATE_FORMAT(t.work_date,'%Y-%m') ym, t.employee_id, SUM(t.hours) h FROM cm_timesheet_entries t
                                        WHERE t.project_id = ? AND t.work_date BETWEEN ? AND ? AND t.activity_type IN ('Ordinario','Reperibilità','Trasferta')
                                        GROUP BY ym, t.employee_id");
            $st->execute([$sp, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($out[$r['ym']])) continue;
                $out[$r['ym']]['ore_timesheet'] += (float)$r['h'];
                $cy->execute([(int)$r['employee_id'], (int)substr($r['ym'], 0, 4)]);
                $co = $cy->fetchColumn();
                if ($co !== false) $out[$r['ym']]['costo'] += (float)$r['h'] * (float)$co;
            }
        } catch (Throwable $e) { /* timesheet assente */ }
        // DGB: solo nei mesi senza rapporti
        if ($c['dgb_contract_id']) {
            try {
                $st = $this->pdo->prepare("SELECT DATE_FORMAT(COALESCE(a.report_date, DATE(a.date_start)),'%Y-%m') ym, SUM(COALESCE(ao.hours,0)) h, SUM(COALESCE(ao.cost,0)) c,
                                                  COUNT(DISTINCT NULLIF(a.ticket,'')) t
                                             FROM dgb_forms_activity a JOIN dgb_forms_activity_operator ao ON ao.id_activity = a.id
                                            WHERE a.deleted = 0 AND a.id_contract = ? AND COALESCE(a.report_date, DATE(a.date_start)) BETWEEN ? AND ? GROUP BY ym");
                $st->execute([(int)$c['dgb_contract_id'], $from, $to]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!isset($out[$r['ym']]) || $out[$r['ym']]['ore_report'] > 0) continue;
                    $out[$r['ym']]['ore_dgb'] = (float)$r['h'];
                    $out[$r['ym']]['costo'] += (float)$r['c'];
                    if ($out[$r['ym']]['ticket'] == 0) $out[$r['ym']]['ticket'] = (float)$r['t'];
                }
            } catch (Throwable $e) { /* DGB assente */ }
        }
        require_once __DIR__ . '/Workload.php';
        $wl = new Workload($this->pdo);
        foreach ($out as $ym => &$m) {
            $m['ore'] = $m['ore_report'] + $m['ore_timesheet'] + $m['ore_dgb'];
            $cap = $wl->monthlyCapacity($ym);
            $m['fte'] = $cap > 0 ? $m['ore'] / $cap : 0.0;
        }
        unset($m);
        return $out;
    }

    /**
     * Confronto mensile e totale.
     * @param array|null $c   contesto (context()) — calcolato se null
     * @param array|null $act consuntivo (computeActuals) — letto da cm_prj_actual se null
     */
    public function compare(int $prjId, ?array $c = null, ?array $act = null): ?array
    {
        $c ??= $this->context($prjId);
        if (!$c) return null;
        if ($act === null) {
            $act = []; foreach ($c['months'] as $ym) $act[$ym] = array_fill_keys(self::METRICHE, 0.0);
            $st = $this->pdo->prepare("SELECT year, month, metrica, SUM(valore) v FROM cm_prj_actual WHERE prj_id = ? AND service_id = 0 GROUP BY year, month, metrica");
            $st->execute([$prjId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $ym = sprintf('%04d-%02d', $r['year'], $r['month']); if (isset($act[$ym][$r['metrica']])) $act[$ym][$r['metrica']] = (float)$r['v']; }
        }
        $stim = null;
        if ($c['scenario_riferimento_id']) {
            try { $stim = (new PrjRepo($this->pdo))->calc($prjId, (int)$c['scenario_riferimento_id'], date('Y-m-d')); } catch (Throwable $e) { $stim = null; }
        }
        $anni = []; if ($stim) foreach ($stim['anni'] as $a) $anni[$a['anno']] = $a;
        $mesi = []; $tot = ['ore' => 0.0, 'costo' => 0.0, 'ticket' => 0.0, 'costo_stimato' => 0.0, 'canone_stimato' => 0.0, 'fte_medio' => 0.0, 'fte_stimato_medio' => 0.0];
        $nM = 0;
        foreach ($act as $ym => $m) {
            $y = (int)substr($ym, 0, 4);
            $a = $anni[$y] ?? null;
            if (!$a && $stim) $a = ['fte' => $stim['totali']['fte_totali'], 'costo' => $stim['totali']['costo_aziendale_totale'], 'canone' => $stim['totali']['canone_netto']];
            $s = ['fte' => $a ? (float)$a['fte'] : null, 'costo' => $a ? (float)$a['costo'] / 12 : null, 'canone' => $a ? (float)$a['canone'] / 12 : null];
            $mesi[] = ['ym' => $ym, 'ore' => $m['ore'], 'ore_report' => $m['ore_report'], 'ore_timesheet' => $m['ore_timesheet'], 'ore_dgb' => $m['ore_dgb'], 'ticket' => $m['ticket'],
                       'stimato' => $s, 'consuntivo' => ['fte' => $m['fte'], 'costo' => $m['costo']],
                       'scost_fte' => $s['fte'] ? ($m['fte'] - $s['fte']) / $s['fte'] : null, 'scost_costo' => $s['costo'] ? ($m['costo'] - $s['costo']) / $s['costo'] : null];
            $tot['ore'] += $m['ore']; $tot['costo'] += $m['costo']; $tot['ticket'] += $m['ticket'];
            $tot['costo_stimato'] += (float)$s['costo']; $tot['canone_stimato'] += (float)$s['canone'];
            $tot['fte_medio'] += $m['fte']; $tot['fte_stimato_medio'] += (float)$s['fte']; $nM++;
        }
        if ($nM) { $tot['fte_medio'] /= $nM; $tot['fte_stimato_medio'] /= $nM; }
        $tot['scost_costo'] = $tot['costo_stimato'] > 0 ? ($tot['costo'] - $tot['costo_stimato']) / $tot['costo_stimato'] : null;
        return ['context' => $c, 'mesi' => $mesi, 'totali' => $tot, 'stimato' => $stim, 'soglie' => $this->soglie()];
    }

    /** Soglie di scostamento (cm_alert_rules prj_scost_*), in %. */
    public function soglie(): array
    {
        $o = ['fte' => [10.0, 20.0], 'costo' => [10.0, 20.0]];
        try {
            foreach ($this->pdo->query("SELECT code, threshold_warn, threshold_alarm FROM cm_alert_rules WHERE code IN ('prj_scost_fte','prj_scost_costo')")->fetchAll(PDO::FETCH_ASSOC) as $r)
                $o[$r['code'] === 'prj_scost_fte' ? 'fte' : 'costo'] = [(float)$r['threshold_warn'], (float)$r['threshold_alarm']];
        } catch (Throwable $e) {}
        return $o;
    }

    /** Team della commessa (cm_team) vs assegnazioni previste nel PRJ. */
    public function team(int $prjId, int $spId): array
    {
        $t = $this->pdo->prepare("SELECT t.employee_id, t.professional_id, t.role_in_project, t.allocated_hours,
                                         COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.last_name, e.first_name)),''), TRIM(CONCAT_WS(' ', pf.last_name, pf.first_name))) AS persona
                                    FROM cm_team t LEFT JOIN employees e ON e.id = t.employee_id LEFT JOIN cm_professionals pf ON pf.id = t.professional_id WHERE t.project_id = ?");
        $t->execute([$spId]);
        $a = $this->pdo->prepare("SELECT a.employee_id, a.professional_id, a.pct_allocazione, pr.codice, pr.nome AS profilo,
                                         COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.last_name, e.first_name)),''), TRIM(CONCAT_WS(' ', pf.last_name, pf.first_name))) AS persona
                                    FROM cm_prj_profile_assignment a JOIN cm_prj_profile pr ON pr.id = a.profile_id
                                    LEFT JOIN employees e ON e.id = a.employee_id LEFT JOIN cm_professionals pf ON pf.id = a.professional_id WHERE a.prj_id = ?");
        $a->execute([$prjId]);
        $k = fn($r) => $r['employee_id'] ? 'e' . $r['employee_id'] : 'p' . $r['professional_id'];
        $out = [];
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$k($r)] = ['persona' => $r['persona'], 'team' => $r, 'prj' => []];
        foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[$k($r)] ??= ['persona' => $r['persona'], 'team' => null, 'prj' => []]; $out[$k($r)]['prj'][] = $r; }
        uasort($out, fn($x, $y) => strcmp((string)$x['persona'], (string)$y['persona']));
        return array_values($out);
    }

    /** SLA reali configurati per la commessa (cm_sd_sla) — confronto con i KPI di gara. */
    public function sla(string $projectCode): array
    {
        try {
            $st = $this->pdo->prepare("SELECT COALESCE(project_code,'(default)') project_code, queue_name, label, take_charge_min, resolution_min FROM cm_sd_sla
                                        WHERE is_active = 1 AND (project_code = ? OR project_code IS NULL) ORDER BY project_code IS NULL, queue_name, label");
            $st->execute([$projectCode]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
