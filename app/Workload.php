<?php
/**
 * app/Workload.php — Carico risorse e sovrapposizioni (v1.7.71)
 *
 * Ricostruisce, dai rapporti di intervento consuntivati, l'impegno di ogni
 * persona su ogni commessa nel tempo (granularità mensile) e ne deriva:
 *   • il carico persona × mese, suddiviso per commessa;
 *   • le SOVRAPPOSIZIONI TRA COMMESSE per la stessa persona nello stesso mese
 *     (una persona che lavora su più commesse contemporaneamente);
 *   • il SOVRACCARICO, quando le ore del mese superano la capacità disponibile
 *     (giorni feriali × ore/giorno), indice di conflitto di pianificazione;
 *   • le COPPIE DI COMMESSE che condividono risorse negli stessi mesi.
 *
 * La capacità mensile usa lo stesso parametro del timesheet (ts_daily_hours).
 */
final class Workload
{
    private PDO $pdo;
    private float $dailyHours = 8.0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        try {
            $v = $this->pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='ts_daily_hours' LIMIT 1")->fetchColumn();
            if ($v !== false && (float)$v > 0) $this->dailyHours = (float)$v;
        } catch (Throwable $e) {}
    }

    public function dailyHours(): float { return $this->dailyHours; }

    /**
     * v1.8.6 — filtri comuni aggiuntivi (società di appartenenza, cliente, stato
     * operativo, tipologia). Richiede l'alias employees `e` (per la società) e/o
     * cm_projects `p` (per cliente/stato/tipologia) nella query chiamante.
     */
    private function addCommonFilters(array &$where, array &$args, array $f, bool $hasEmp, bool $hasProj): void
    {
        if ($hasEmp && !empty($f['company_id']))          { $where[] = "e.company_id = ?";          $args[] = (int)$f['company_id']; }
        if ($hasProj && !empty($f['client_id']))          { $where[] = "p.client_id = ?";           $args[] = (int)$f['client_id']; }
        if ($hasProj && !empty($f['operational_status'])) { $where[] = "p.operational_status = ?";   $args[] = (string)$f['operational_status']; }
        if ($hasProj && !empty($f['project_type']))       { $where[] = "p.project_type = ?";         $args[] = (string)$f['project_type']; }
    }

    /** Vero se sono attivi filtri che richiedono la JOIN a cm_projects. */
    private function needsProjectJoin(array $f): bool
    {
        return !empty($f['service_line']) || !empty($f['client_id'])
            || !empty($f['operational_status']) || !empty($f['project_type']);
    }

    /** Giorni feriali (lun-ven) di un mese 'YYYY-MM'. */
    public static function workingDaysOfMonth(string $ym): int
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        if (!$y || !$m) return 22;
        $n = 0; $days = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        for ($d = 1; $d <= $days; $d++) if ((int)date('N', mktime(0, 0, 0, $m, $d, $y)) <= 5) $n++;
        return $n;
    }

    public function monthlyCapacity(string $ym): float
    {
        return self::workingDaysOfMonth($ym) * $this->dailyHours;
    }

    /** Elenco mesi 'YYYY-MM' tra due estremi inclusi. */
    public static function monthRange(string $from, string $to): array
    {
        $out = [];
        $a = strtotime($from . '-01'); $b = strtotime($to . '-01');
        if (!$a || !$b || $a > $b) return $out;
        for ($t = $a; $t <= $b; $t = strtotime('+1 month', $t)) $out[] = date('Y-m', $t);
        return $out;
    }

    /**
     * Matrice impegno: per ogni persona, per ogni mese, ore totali e dettaglio per commessa.
     * @return array[employee_id] = ['nome','tot'=>float,'months'=>[ym=>['ore','projects'=>[pid=>['code','name','ore']],'n_proj']]]
     */
    public function matrix(string $from, string $to, array $f = []): array
    {
        $where = ["ir.technician_id IS NOT NULL", "ir.report_date IS NOT NULL",
                  "DATE_FORMAT(ir.report_date,'%Y-%m') BETWEEN ? AND ?"];
        $args = [$from, $to];
        if (!empty($f['project_id']))  { $where[] = "ir.project_id = ?";    $args[] = (int)$f['project_id']; }
        if (!empty($f['employee_id'])) { $where[] = "ir.technician_id = ?";  $args[] = (int)$f['employee_id']; }
        // v1.7.73: filtro su più risorse contemporaneamente.
        if (!empty($f['employee_ids']) && is_array($f['employee_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $f['employee_ids'])));
            if ($ids) { $where[] = "ir.technician_id IN (" . implode(',', $ids) . ")"; }
        }
        // v1.7.79: filtro per linea di servizio (cm_projects.service_line).
        if (!empty($f['service_line'])) { $where[] = "p.service_line = ?"; $args[] = (string)$f['service_line']; }
        // v1.8.6: società di appartenenza, cliente, stato operativo, tipologia.
        $this->addCommonFilters($where, $args, $f, true, true);

        $st = $this->pdo->prepare(
            "SELECT ir.technician_id AS eid, CONCAT(e.last_name,' ',e.first_name) AS nome,
                    DATE_FORMAT(ir.report_date,'%Y-%m') AS ym,
                    ir.project_id AS pid, p.project_code, p.name AS pname,
                    ROUND(SUM(ir.quantity_hours),2) AS ore
               FROM cm_intervention_reports ir
               JOIN employees e   ON e.id = ir.technician_id
               LEFT JOIN cm_projects p ON p.id = ir.project_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY ir.technician_id, nome, ym, ir.project_id, p.project_code, p.name"
        );
        $st->execute($args);

        $m = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eid = (int)$r['eid']; $ym = $r['ym']; $pid = (int)$r['pid'];
            if (!isset($m[$eid])) $m[$eid] = ['nome' => $r['nome'], 'tot' => 0.0, 'months' => []];
            if (!isset($m[$eid]['months'][$ym])) $m[$eid]['months'][$ym] = ['ore' => 0.0, 'projects' => [], 'n_proj' => 0];
            $cell = &$m[$eid]['months'][$ym];
            $cell['ore'] += (float)$r['ore'];
            $cell['projects'][$pid] = ['code' => $r['project_code'] ?? '—', 'name' => $r['pname'] ?? '(non risolta)', 'ore' => (float)$r['ore']];
            $cell['n_proj'] = count($cell['projects']);
            $m[$eid]['tot'] += (float)$r['ore'];
            unset($cell);
        }
        uasort($m, fn($a, $b) => $b['tot'] <=> $a['tot']);
        // v1.7.73: ordinamento selezionabile.
        $sort = $f['sort'] ?? 'hours_desc';
        if ($sort === 'name')        uasort($m, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
        elseif ($sort === 'hours_asc') uasort($m, fn($a, $b) => $a['tot'] <=> $b['tot']);
        else                         uasort($m, fn($a, $b) => $b['tot'] <=> $a['tot']); // hours_desc (default)
        return $m;
    }

    /**
     * Sovrapposizioni per persona: mesi in cui lavora su ≥2 commesse (contemporaneità)
     * e/o supera la capacità (sovraccarico). Deriva dalla matrice per non riquerire.
     * @return array di ['eid','nome','ym','ore','capacity','sat','projects'=>[...],'overload'=>bool]
     */
    public function personOverlaps(array $matrix, int $minProjects = 2, bool $onlyOverload = false): array
    {
        $out = [];
        foreach ($matrix as $eid => $row) {
            foreach ($row['months'] as $ym => $cell) {
                $cap = $this->monthlyCapacity($ym);
                $sat = $cap > 0 ? round($cell['ore'] / $cap * 100, 1) : 0;
                $overload = $cap > 0 && $cell['ore'] > $cap;
                $multi = $cell['n_proj'] >= $minProjects;
                if (!$multi && !$overload) continue;
                if ($onlyOverload && !$overload) continue;
                $projects = $cell['projects'];
                uasort($projects, fn($a, $b) => $b['ore'] <=> $a['ore']);
                $out[] = [
                    'eid' => $eid, 'nome' => $row['nome'], 'ym' => $ym,
                    'ore' => round($cell['ore'], 2), 'capacity' => $cap, 'sat' => $sat,
                    'n_proj' => $cell['n_proj'], 'projects' => $projects, 'overload' => $overload,
                ];
            }
        }
        usort($out, fn($a, $b) => [$b['overload'], $b['sat']] <=> [$a['overload'], $a['sat']]);
        return $out;
    }

    /**
     * Coppie di commesse che condividono risorse nello stesso mese, entro il periodo.
     * v1.7.74: riporta la FASCIA TEMPORALE effettiva della sovrapposizione (primo/ultimo
     * mese in comune), le ore di ciascuna commessa e le ore totali coinvolte.
     * @return array di [
     *   'a'=>['pid','code','name'], 'b'=>[...], 'people'=>[eid=>nome], 'n_people',
     *   'months'=>[ym...], 'n_months', 'first_month','last_month',
     *   'hours_a','hours_b','shared_hours'
     * ]
     */
    public function projectOverlaps(string $from, string $to, int $limit = 100, array $f = []): array
    {
        $where = ["ir.technician_id IS NOT NULL", "ir.project_id IS NOT NULL", "ir.report_date IS NOT NULL",
                  "DATE_FORMAT(ir.report_date,'%Y-%m') BETWEEN ? AND ?"];
        $args = [$from, $to];
        // v1.7.74: rispetta anche i filtri risorsa/e (la coppia deve coinvolgere le persone filtrate).
        if (!empty($f['employee_id'])) { $where[] = "ir.technician_id = ?"; $args[] = (int)$f['employee_id']; }
        if (!empty($f['employee_ids']) && is_array($f['employee_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $f['employee_ids'])));
            if ($ids) $where[] = "ir.technician_id IN (" . implode(',', $ids) . ")";
        }
        // v1.7.79/v1.8.6: filtri su cm_projects — richiedono la JOIN.
        $joinProj = '';
        if ($this->needsProjectJoin($f)) {
            $joinProj = " JOIN cm_projects p ON p.id = ir.project_id";
            if (!empty($f['service_line'])) { $where[] = "p.service_line = ?"; $args[] = (string)$f['service_line']; }
        }
        $this->addCommonFilters($where, $args, $f, true, $joinProj !== '');
        $st = $this->pdo->prepare(
            "SELECT ir.technician_id AS eid, CONCAT(e.last_name,' ',e.first_name) AS nome,
                    DATE_FORMAT(ir.report_date,'%Y-%m') AS ym, ir.project_id AS pid,
                    ROUND(SUM(ir.quantity_hours),2) AS ore
               FROM cm_intervention_reports ir
               JOIN employees e ON e.id = ir.technician_id" . $joinProj . "
              WHERE " . implode(' AND ', $where) . "
              GROUP BY ir.technician_id, nome, ym, ir.project_id"
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // filtro commessa: se attivo, tieni solo le coppie che includono quella commessa
        $focusPid = (int)($f['project_id'] ?? 0);

        // indicizza per (persona, mese) => [pid => ['ore','nome']]
        $byPM = [];
        foreach ($rows as $r) {
            $byPM[$r['eid'] . '|' . $r['ym']][(int)$r['pid']] = ['ore' => (float)$r['ore'], 'nome' => $r['nome']];
        }

        $pairs = [];
        foreach ($byPM as $key => $projs) {
            if (count($projs) < 2) continue;
            [$eid, $ym] = explode('|', $key);
            $pids = array_keys($projs);
            for ($i = 0; $i < count($pids); $i++) {
                for ($j = $i + 1; $j < count($pids); $j++) {
                    $pa = $pids[$i]; $pb = $pids[$j];
                    $a = min($pa, $pb); $b = max($pa, $pb);
                    $pk = $a . '-' . $b;
                    if (!isset($pairs[$pk])) {
                        $pairs[$pk] = ['a' => $a, 'b' => $b, 'people' => [], 'months' => [],
                                       'hours_a' => 0.0, 'hours_b' => 0.0];
                    }
                    $pairs[$pk]['people'][(int)$eid] = $projs[$pa]['nome'];
                    $pairs[$pk]['months'][$ym] = true;
                    // ore attribuite alla commessa con id minore ($a) e maggiore ($b)
                    $pairs[$pk]['hours_a'] += $projs[$a]['ore'];
                    $pairs[$pk]['hours_b'] += $projs[$b]['ore'];
                }
            }
        }
        if (!$pairs) return [];

        $ids = [];
        foreach ($pairs as $p) { $ids[$p['a']] = 1; $ids[$p['b']] = 1; }
        $in = implode(',', array_map('intval', array_keys($ids)));
        $labels = $this->pdo->query("SELECT id, project_code, name FROM cm_projects WHERE id IN ($in)")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        $out = [];
        foreach ($pairs as $p) {
            // filtro commessa attivo: mostra solo le coppie che la includono
            if ($focusPid && $p['a'] !== $focusPid && $p['b'] !== $focusPid) continue;
            $months = array_keys($p['months']);
            sort($months);
            $out[] = [
                'a' => ['pid' => $p['a'], 'code' => $labels[$p['a']]['project_code'] ?? '—', 'name' => $labels[$p['a']]['name'] ?? ''],
                'b' => ['pid' => $p['b'], 'code' => $labels[$p['b']]['project_code'] ?? '—', 'name' => $labels[$p['b']]['name'] ?? ''],
                'people' => $p['people'],
                'n_people' => count($p['people']),
                'months' => $months,
                'n_months' => count($months),
                'first_month' => $months[0] ?? null,
                'last_month'  => end($months) ?: null,
                'hours_a' => round($p['hours_a'], 2),
                'hours_b' => round($p['hours_b'], 2),
                'shared_hours' => round($p['hours_a'] + $p['hours_b'], 2),
            ];
        }
        usort($out, fn($x, $y) => [$y['n_people'], $y['n_months'], $y['shared_hours']] <=> [$x['n_people'], $x['n_months'], $x['shared_hours']]);
        return array_slice($out, 0, $limit);
    }

    /** Impegno per commessa: risorse coinvolte, mesi attivi, ore totali (per la vista per-commessa). */
    /**
     * v1.7.73: serie per il grafico — ore per risorsa e mese, limitate alle risorse date.
     * @return array['months'=>[ym...], 'series'=>[['eid','nome','points'=>[ym=>ore],'tot']], 'peak'=>float]
     */
    public function chartSeries(array $matrix, array $months): array
    {
        $series = [];
        $peak = 0.0;
        foreach ($matrix as $eid => $row) {
            $points = [];
            foreach ($months as $ym) {
                $v = round($row['months'][$ym]['ore'] ?? 0, 2);
                $points[$ym] = $v;
                if ($v > $peak) $peak = $v;
            }
            $series[] = ['eid' => $eid, 'nome' => $row['nome'], 'points' => $points, 'tot' => round($row['tot'], 2)];
        }
        return ['months' => $months, 'series' => $series, 'peak' => $peak];
    }

    /** v1.7.73: capacità mensile per la linea di riferimento del grafico. */
    public function capacitySeries(array $months): array
    {
        $out = [];
        foreach ($months as $ym) $out[$ym] = $this->monthlyCapacity($ym);
        return $out;
    }

    public function byProject(string $from, string $to, array $f = []): array
    {
        $where = ["ir.project_id IS NOT NULL", "ir.technician_id IS NOT NULL", "ir.report_date IS NOT NULL",
                  "DATE_FORMAT(ir.report_date,'%Y-%m') BETWEEN ? AND ?"];
        $args = [$from, $to];
        if (!empty($f['project_id'])) { $where[] = "ir.project_id = ?"; $args[] = (int)$f['project_id']; }
        if (!empty($f['service_line'])) { $where[] = "p.service_line = ?"; $args[] = (string)$f['service_line']; }
        $st = $this->pdo->prepare(
            "SELECT ir.project_id AS pid, p.project_code, p.name,
                    COUNT(DISTINCT ir.technician_id) AS n_people,
                    COUNT(DISTINCT DATE_FORMAT(ir.report_date,'%Y-%m')) AS n_months,
                    ROUND(SUM(ir.quantity_hours),2) AS ore,
                    MIN(ir.report_date) AS dal, MAX(ir.report_date) AS al
               FROM cm_intervention_reports ir
               LEFT JOIN cm_projects p ON p.id = ir.project_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY ir.project_id, p.project_code, p.name
              ORDER BY ore DESC"
        );
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ══════════════════════════════════════════════════════════════════════
     * v1.8.2 — GRANULARITÀ GIORNALIERA (dettaglio di un singolo mese)
     * ════════════════════════════════════════════════════════════════════ */

    /** Elenco giorni 'YYYY-MM-DD' di un mese 'YYYY-MM'. */
    public static function daysOfMonth(string $ym): array
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        if (!$y || !$m) return [];
        $out = []; $days = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        for ($d = 1; $d <= $days; $d++) $out[] = sprintf('%04d-%02d-%02d', $y, $m, $d);
        return $out;
    }

    /** Vero se la data 'YYYY-MM-DD' è un giorno feriale (lun-ven). */
    public static function isWorkingDay(string $date): bool
    {
        $t = strtotime($date);
        return $t ? ((int)date('N', $t) <= 5) : false;
    }

    /** Capacità giornaliera per un mese: [date => ore/giorno feriale, 0 nei weekend]. */
    public function dailyCapacity(string $ym): array
    {
        $out = [];
        foreach (self::daysOfMonth($ym) as $d) $out[$d] = self::isWorkingDay($d) ? $this->dailyHours : 0.0;
        return $out;
    }

    /**
     * Matrice impegno GIORNALIERA per un singolo mese: per ogni persona, per ogni
     * giorno, ore totali e dettaglio per commessa. Stessi filtri di matrix().
     * @return array[eid] = ['nome','tot'=>float,'days'=>[date=>['ore','projects'=>[pid=>['code','name','ore']],'n_proj']]]
     */
    public function dailyMatrix(string $ym, array $f = []): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return [];
        $where = ["ir.technician_id IS NOT NULL", "ir.report_date IS NOT NULL",
                  "DATE_FORMAT(ir.report_date,'%Y-%m') = ?"];
        $args = [$ym];
        if (!empty($f['project_id']))  { $where[] = "ir.project_id = ?";   $args[] = (int)$f['project_id']; }
        if (!empty($f['employee_id'])) { $where[] = "ir.technician_id = ?"; $args[] = (int)$f['employee_id']; }
        if (!empty($f['employee_ids']) && is_array($f['employee_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $f['employee_ids'])));
            if ($ids) $where[] = "ir.technician_id IN (" . implode(',', $ids) . ")";
        }
        if (!empty($f['service_line'])) { $where[] = "p.service_line = ?"; $args[] = (string)$f['service_line']; }
        $this->addCommonFilters($where, $args, $f, true, true);

        $st = $this->pdo->prepare(
            "SELECT ir.technician_id AS eid, CONCAT(e.last_name,' ',e.first_name) AS nome,
                    DATE(ir.report_date) AS d,
                    ir.project_id AS pid, p.project_code, p.name AS pname,
                    ROUND(SUM(ir.quantity_hours),2) AS ore
               FROM cm_intervention_reports ir
               JOIN employees e   ON e.id = ir.technician_id
               LEFT JOIN cm_projects p ON p.id = ir.project_id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY ir.technician_id, nome, d, ir.project_id, p.project_code, p.name"
        );
        $st->execute($args);

        $m = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eid = (int)$r['eid']; $d = $r['d']; $pid = (int)$r['pid'];
            if (!isset($m[$eid])) $m[$eid] = ['nome' => $r['nome'], 'tot' => 0.0, 'days' => []];
            if (!isset($m[$eid]['days'][$d])) $m[$eid]['days'][$d] = ['ore' => 0.0, 'projects' => [], 'n_proj' => 0];
            $cell = &$m[$eid]['days'][$d];
            $cell['ore'] += (float)$r['ore'];
            $cell['projects'][$pid] = ['code' => $r['project_code'] ?? '—', 'name' => $r['pname'] ?? '(non risolta)', 'ore' => (float)$r['ore']];
            $cell['n_proj'] = count($cell['projects']);
            $m[$eid]['tot'] += (float)$r['ore'];
            unset($cell);
        }
        $sort = $f['sort'] ?? 'hours_desc';
        if ($sort === 'name')          uasort($m, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));
        elseif ($sort === 'hours_asc') uasort($m, fn($a, $b) => $a['tot'] <=> $b['tot']);
        else                           uasort($m, fn($a, $b) => $b['tot'] <=> $a['tot']);
        return $m;
    }

    /**
     * v1.8.2 — serie per il grafico giornaliero: ore per risorsa e giorno.
     * @return array['days'=>[date...], 'series'=>[['eid','nome','points'=>[date=>ore],'tot']], 'peak'=>float]
     */
    public function dailyChartSeries(array $dailyMatrix, array $days): array
    {
        $series = []; $peak = 0.0;
        foreach ($dailyMatrix as $eid => $row) {
            $points = [];
            foreach ($days as $d) {
                $v = round($row['days'][$d]['ore'] ?? 0, 2);
                $points[$d] = $v;
                if ($v > $peak) $peak = $v;
            }
            $series[] = ['eid' => $eid, 'nome' => $row['nome'], 'points' => $points, 'tot' => round($row['tot'], 2)];
        }
        return ['days' => $days, 'series' => $series, 'peak' => $peak];
    }
}
