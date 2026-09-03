<?php
/**
 * app/ProjectWorkflow.php — Report/avanzamento e workflow di commessa (v1.8.5)
 *
 * Supporta la sezione "Report & Avanzamento" della scheda commessa:
 *  - note datate con valutazione (rating), avanzamento e allegati;
 *  - workflow programmabile di step, ciascuno agganciabile a una fase del
 *    Gantt: l'avanzamento/stato degli step ricalcola la percentuale della
 *    fase collegata (cm_project_phases.progress_pct), riflessa nel Gantt.
 */
final class ProjectWorkflow
{
    public const KINDS  = ['nota' => 'Nota', 'avanzamento' => 'Avanzamento', 'rischio' => 'Rischio', 'decisione' => 'Decisione', 'milestone' => 'Milestone'];
    public const STATUS = ['da_fare' => 'Da fare', 'in_corso' => 'In corso', 'completato' => 'Completato', 'bloccato' => 'Bloccato'];

    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /* ── Note / report di avanzamento ─────────────────────────────────────── */

    public function updates(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT u.*, us.display_name AS author,
                    ph.name AS phase_name, ws.name AS step_name,
                    (SELECT COUNT(*) FROM cm_project_update_files f WHERE f.update_id = u.id) AS n_files
               FROM cm_project_updates u
               LEFT JOIN users us              ON us.id = u.created_by
               LEFT JOIN cm_project_phases ph  ON ph.id = u.phase_id
               LEFT JOIN cm_workflow_steps ws  ON ws.id = u.step_id
              WHERE u.project_id = ? AND u.deleted_at IS NULL
              ORDER BY u.update_date DESC, u.id DESC"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update(int $id, int $projectId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_project_updates WHERE id=? AND project_id=? AND deleted_at IS NULL");
        $st->execute([$id, $projectId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Allegati raggruppati per update: [update_id => [file, ...]]. */
    public function filesByProject(int $projectId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_project_update_files WHERE project_id=? ORDER BY id");
        $st->execute([$projectId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) $out[(int)$f['update_id']][] = $f;
        return $out;
    }

    public function file(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_project_update_files WHERE id=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ── Workflow ─────────────────────────────────────────────────────────── */

    public function steps(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT s.*, ph.name AS phase_name,
                    CONCAT(COALESCE(e.last_name,''),' ',COALESCE(e.first_name,'')) AS assignee_name
               FROM cm_workflow_steps s
               LEFT JOIN cm_project_phases ph ON ph.id = s.phase_id
               LEFT JOIN employees e          ON e.id = s.assignee_employee_id
              WHERE s.project_id = ? AND s.deleted_at IS NULL
              ORDER BY s.sort_order, s.due_date IS NULL, s.due_date, s.id"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function step(int $id, int $projectId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_workflow_steps WHERE id=? AND project_id=? AND deleted_at IS NULL");
        $st->execute([$id, $projectId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Riepilogo workflow: conteggi per stato e completamento medio. */
    public function summary(int $projectId): array
    {
        $rows = $this->steps($projectId);
        $by = ['da_fare' => 0, 'in_corso' => 0, 'completato' => 0, 'bloccato' => 0];
        $tot = 0; $sum = 0;
        foreach ($rows as $s) { $by[$s['status']] = ($by[$s['status']] ?? 0) + 1; $tot++; $sum += (int)$s['progress_pct']; }
        return ['count' => $tot, 'by_status' => $by, 'avg' => $tot ? (int)round($sum / $tot) : 0,
                'done' => $by['completato'], 'blocked' => $by['bloccato']];
    }

    /**
     * Aggancio al Gantt: ricalcola la percentuale della fase dagli step ad essa
     * collegati. Progress fase = media dei progress_pct degli step (uno step
     * 'completato' vale 100). Se non ci sono step collegati, la fase non è toccata.
     */
    public function recomputePhaseProgress(int $projectId, ?int $phaseId): void
    {
        if (!$phaseId) return;
        $st = $this->pdo->prepare(
            "SELECT AVG(CASE WHEN status='completato' THEN 100 ELSE progress_pct END) AS avg_pct, COUNT(*) n
               FROM cm_workflow_steps WHERE project_id=? AND phase_id=? AND deleted_at IS NULL"
        );
        $st->execute([$projectId, $phaseId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && (int)$r['n'] > 0) {
            $pct = max(0, min(100, (int)round((float)$r['avg_pct'])));
            $this->pdo->prepare("UPDATE cm_project_phases SET progress_pct=? WHERE id=? AND project_id=?")
                      ->execute([$pct, $phaseId, $projectId]);
        }
    }

    /** Step con scadenza, per i marcatori sul Gantt. */
    public function stepsForGantt(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT id, name, status, due_date, phase_id, is_gate FROM cm_workflow_steps
              WHERE project_id=? AND deleted_at IS NULL AND due_date IS NOT NULL
              ORDER BY due_date, sort_order, id"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
