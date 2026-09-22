<?php
/**
 * app/Timesheet.php — Timesheet risorse (v1.7.69)
 *
 * Il timesheet unisce due fonti:
 *  - ore CONSUNTIVATE dai rapporti di intervento (cm_intervention_reports.quantity_hours),
 *    che restano di sola lettura: la fonte di verità è l'import;
 *  - voci MANUALI (cm_timesheet_entries) per ciò che non passa dai rapporti
 *    (ferie, permessi, formazione, trasferte, attività interne).
 *
 * Le ore attese del mese sono calcolate sui giorni feriali (lun-ven), coerentemente
 * con la colonna generata in_working_hours dei rapporti.
 */
final class Timesheet
{
    public const ACTIVITIES = ['Ordinario','Reperibilità','Trasferta','Formazione','Ferie','Permesso','Malattia','Altro'];

    private PDO $pdo;
    private float $dailyHours = 8.0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        try {
            $v = $this->pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='ts_daily_hours' LIMIT 1")->fetchColumn();
            if ($v !== false && (float)$v > 0) $this->dailyHours = (float)$v;
        } catch (Throwable $e) { /* default */ }
    }

    public function dailyHours(): float { return $this->dailyHours; }

    /** Giorni feriali (lun-ven) del mese. */
    public static function workingDays(int $year, int $month): int
    {
        $n = 0;
        $days = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        for ($d = 1; $d <= $days; $d++) {
            $w = (int)date('N', mktime(0, 0, 0, $month, $d, $year));
            if ($w <= 5) $n++;
        }
        return $n;
    }

    /** Ore da rapporti per dipendente/giorno. @return array[employee_id][giorno] = ore */
    public function reportHours(int $year, int $month, array $f = []): array
    {
        $where = ["ir.technician_id IS NOT NULL", "YEAR(ir.report_date) = ?", "MONTH(ir.report_date) = ?"];
        $args  = [$year, $month];
        if (!empty($f['project_id']))  { $where[] = "ir.project_id = ?";   $args[] = (int)$f['project_id']; }
        if (!empty($f['employee_id'])) { $where[] = "ir.technician_id = ?"; $args[] = (int)$f['employee_id']; }
        $st = $this->pdo->prepare(
            "SELECT ir.technician_id AS eid, DAY(ir.report_date) AS g, ROUND(SUM(ir.quantity_hours),2) AS ore,
                    COUNT(*) AS n
               FROM cm_intervention_reports ir
              WHERE " . implode(' AND ', $where) . "
              GROUP BY ir.technician_id, DAY(ir.report_date)"
        );
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['eid']][(int)$r['g']] = ['ore' => (float)$r['ore'], 'n' => (int)$r['n']];
        }
        return $out;
    }

    /** Voci manuali per dipendente/giorno. */
    public function manualHours(int $year, int $month, array $f = []): array
    {
        $where = ["YEAR(t.work_date) = ?", "MONTH(t.work_date) = ?"];
        $args  = [$year, $month];
        if (!empty($f['project_id']))  { $where[] = "t.project_id = ?";  $args[] = (int)$f['project_id']; }
        if (!empty($f['employee_id'])) { $where[] = "t.employee_id = ?"; $args[] = (int)$f['employee_id']; }
        $st = $this->pdo->prepare(
            "SELECT t.employee_id AS eid, DAY(t.work_date) AS g, ROUND(SUM(t.hours),2) AS ore, COUNT(*) AS n
               FROM cm_timesheet_entries t
              WHERE " . implode(' AND ', $where) . "
              GROUP BY t.employee_id, DAY(t.work_date)"
        );
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['eid']][(int)$r['g']] = ['ore' => (float)$r['ore'], 'n' => (int)$r['n']];
        }
        return $out;
    }

    /** Dipendenti da mostrare: quelli con attività nel mese (o tutti se richiesto). */
    public function employees(int $year, int $month, array $f = [], bool $onlyActive = true): array
    {
        if (!$onlyActive) {
            $sql = "SELECT e.id, CONCAT(e.last_name,' ',e.first_name) AS nome, e.ral, d.value_type,
                           e.classificazione_finanziaria AS classe
                      FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                     ORDER BY e.last_name, e.first_name";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        $args = [$year, $month, $year, $month];
        $pf = ''; $mf = '';
        if (!empty($f['project_id'])) {
            $pf = " AND ir.project_id = ?"; $mf = " AND t.project_id = ?";
        }
        $sql = "SELECT e.id, CONCAT(e.last_name,' ',e.first_name) AS nome, e.ral, d.value_type,
                       e.classificazione_finanziaria AS classe
                  FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                 WHERE EXISTS (SELECT 1 FROM cm_intervention_reports ir
                                WHERE ir.technician_id = e.id AND YEAR(ir.report_date)=? AND MONTH(ir.report_date)=?$pf)
                    OR EXISTS (SELECT 1 FROM cm_timesheet_entries t
                                WHERE t.employee_id = e.id AND YEAR(t.work_date)=? AND MONTH(t.work_date)=?$mf)
                 ORDER BY e.last_name, e.first_name";
        if (!empty($f['project_id'])) {
            $args = [$year, $month, (int)$f['project_id'], $year, $month, (int)$f['project_id']];
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Voci manuali di un dipendente in un giorno (per il pannello di dettaglio). */
    public function entriesOfDay(int $employeeId, string $date): array
    {
        $st = $this->pdo->prepare(
            "SELECT t.*, p.project_code, p.name AS project_name
               FROM cm_timesheet_entries t
               LEFT JOIN cm_projects p ON p.id = t.project_id
              WHERE t.employee_id = ? AND t.work_date = ? ORDER BY t.id"
        );
        $st->execute([$employeeId, $date]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Rapporti di un dipendente in un giorno (sola lettura). */
    public function reportsOfDay(int $employeeId, string $date): array
    {
        $st = $this->pdo->prepare(
            "SELECT ir.id, ir.report_code, ir.quantity_hours, ir.on_call, ir.project_code, p.name AS project_name
               FROM cm_intervention_reports ir
               LEFT JOIN cm_projects p ON p.id = ir.project_id
              WHERE ir.technician_id = ? AND ir.report_date = ? ORDER BY ir.start_at"
        );
        $st->execute([$employeeId, $date]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addEntry(int $employeeId, string $date, float $hours, string $activity, ?int $projectId, ?string $notes, ?int $userId): void
    {
        if (!in_array($activity, self::ACTIVITIES, true)) $activity = 'Altro';
        $this->pdo->prepare(
            "INSERT INTO cm_timesheet_entries (employee_id, work_date, project_id, hours, activity_type, notes, created_by)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$employeeId, $date, $projectId ?: null, $hours, $activity, $notes ?: null, $userId]);
    }

    public function deleteEntry(int $id): void
    {
        $this->pdo->prepare("DELETE FROM cm_timesheet_entries WHERE id = ?")->execute([$id]);
    }
}
