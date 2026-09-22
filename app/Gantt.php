<?php
/**
 * app/Gantt.php — Dati e rendering del diagramma di Gantt (v1.7.69)
 *
 * Nessuna dipendenza esterna: le barre sono HTML/CSS posizionate in percentuale
 * sull'intervallo temporale complessivo, quindi stampabili e senza JavaScript.
 *
 * Per ogni commessa si confrontano:
 *  - PIANIFICATO: cm_projects.start_date → end_date
 *  - FASI:        cm_project_phases (con percentuale di avanzamento)
 *  - EFFETTIVO:   primo e ultimo rapporto di intervento registrati
 */
final class Gantt
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Fasi di una commessa. */
    public function phases(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM cm_project_phases WHERE project_id = ? ORDER BY sort_order, start_date, id"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Intervallo effettivo dai rapporti (primo/ultimo) + ore e conteggio. */
    public function actualRange(int $projectId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT MIN(report_date) AS dal, MAX(report_date) AS al,
                    ROUND(SUM(quantity_hours),2) AS ore, COUNT(*) AS n
               FROM cm_intervention_reports WHERE project_id = ? AND report_date IS NOT NULL"
        );
        $st->execute([$projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ($r && $r['dal']) ? $r : null;
    }

    /** Effettivo per singola risorsa del team (barra per tecnico). */
    public function actualByTechnician(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT ir.technician_id, CONCAT(e.last_name,' ',e.first_name) AS nome,
                    MIN(ir.report_date) AS dal, MAX(ir.report_date) AS al,
                    ROUND(SUM(ir.quantity_hours),2) AS ore, COUNT(*) AS n
               FROM cm_intervention_reports ir
               JOIN employees e ON e.id = ir.technician_id
              WHERE ir.project_id = ? AND ir.report_date IS NOT NULL
              GROUP BY ir.technician_id, nome ORDER BY dal, nome"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Carico mensile (ore) dai rapporti, per l'istogramma sotto al Gantt. */
    public function monthlyLoad(int $projectId): array
    {
        $st = $this->pdo->prepare(
            "SELECT DATE_FORMAT(report_date,'%Y-%m') AS mese, ROUND(SUM(quantity_hours),2) AS ore, COUNT(*) AS n
               FROM cm_intervention_reports WHERE project_id = ? AND report_date IS NOT NULL
              GROUP BY mese ORDER BY mese"
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Righe per il Gantt di portfolio (una barra per commessa). */
    public function portfolio(array $f = [], int $limit = 200): array
    {
        $where = []; $args = [];
        if (!empty($f['status']))     { $where[] = "p.operational_status = ?"; $args[] = $f['status']; }
        if (!empty($f['company_id'])) { $where[] = "p.exec_company_id = ?";    $args[] = (int)$f['company_id']; }
        if (!empty($f['q']))          { $where[] = "(p.project_code LIKE ? OR p.name LIKE ?)"; $args[] = "%{$f['q']}%"; $args[] = "%{$f['q']}%"; }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = max(1, (int)$limit);
        $st = $this->pdo->prepare(
            "SELECT p.id, p.project_code, p.name, p.start_date, p.end_date, p.operational_status,
                    p.economic_status, p.value_total, cl.name AS client_name, co.name AS company_name,
                    a.dal AS act_dal, a.al AS act_al, a.ore AS act_ore, a.n AS act_n
               FROM cm_projects p
               LEFT JOIN clients cl   ON cl.id = p.client_id
               LEFT JOIN companies co ON co.id = p.exec_company_id
               LEFT JOIN (SELECT project_id, MIN(report_date) dal, MAX(report_date) al,
                                 ROUND(SUM(quantity_hours),2) ore, COUNT(*) n
                            FROM cm_intervention_reports WHERE report_date IS NOT NULL AND project_id IS NOT NULL
                           GROUP BY project_id) a ON a.project_id = p.id
               $w
              ORDER BY COALESCE(p.start_date, a.dal) IS NULL, COALESCE(p.start_date, a.dal), p.project_code
              LIMIT $limit"
        );
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Scala temporale complessiva: [min, max] su tutte le date fornite. */
    public static function scale(array $dates): array
    {
        $ts = [];
        foreach ($dates as $d) { if ($d) { $t = strtotime((string)$d); if ($t) $ts[] = $t; } }
        if (!$ts) { $now = time(); return [$now - 86400 * 30, $now + 86400 * 30]; }
        $min = min($ts); $max = max($ts);
        if ($max - $min < 86400 * 14) { $min -= 86400 * 7; $max += 86400 * 7; }   // barre troppo strette
        $pad = (int)(($max - $min) * 0.02);
        return [$min - $pad, $max + $pad];
    }

    /** Posizione/larghezza percentuale di una barra nell'intervallo dato. */
    public static function bar(?string $from, ?string $to, int $min, int $max): ?array
    {
        if (!$from && !$to) return null;
        $a = strtotime((string)($from ?: $to));
        $b = strtotime((string)($to ?: $from));
        if (!$a || !$b) return null;
        if ($b < $a) [$a, $b] = [$b, $a];
        $b += 86399;                                   // include il giorno finale
        $span = max(1, $max - $min);
        $left  = max(0, min(100, ($a - $min) / $span * 100));
        $right = max(0, min(100, ($b - $min) / $span * 100));
        return ['left' => round($left, 3), 'width' => round(max(0.4, $right - $left), 3)];
    }

    /** Tacche temporali (mesi) da mostrare in testata. */
    public static function ticks(int $min, int $max, int $maxTicks = 14): array
    {
        $out = [];
        $span = max(1, $max - $min);
        $cur = strtotime(date('Y-m-01', $min));
        $step = 1;
        $months = max(1, (int)round($span / (86400 * 30.4)));
        if ($months > $maxTicks) $step = (int)ceil($months / $maxTicks);
        $i = 0;
        while ($cur <= $max) {
            if ($i % $step === 0 && $cur >= $min) {
                $out[] = ['label' => date('M y', $cur), 'left' => round(($cur - $min) / $span * 100, 3)];
            }
            $cur = strtotime('+1 month', $cur);
            $i++;
        }
        return $out;
    }

    /* ══════════════════════════════════════════════════════════════════════
     * v1.8.3 — Helper di rendering (grafica Gantt ridisegnata, leggibile)
     * ════════════════════════════════════════════════════════════════════ */

    /** Larghezza minima (px) della timeline: garantisce spazio alle barre e alle
     *  tacche mensili, così la vista scorre orizzontalmente invece di comprimersi. */
    public static function timelineMinWidth(int $min, int $max, int $pxPerMonth = 66, int $floor = 720, int $cap = 2600): int
    {
        $months = max(1, (int)round(($max - $min) / (86400 * 30.4)));
        return max($floor, min($cap, $months * $pxPerMonth));
    }

    /** Numero di etichette-tacca adeguato alla larghezza disponibile (una ogni ~92px). */
    public static function tickBudget(int $widthPx): int
    {
        return max(4, (int)floor($widthPx / 92));
    }

    /** Posizioni percentuali delle linee di griglia mensili (diradate se troppe). */
    public static function monthGridlines(int $min, int $max, int $maxLines = 72): array
    {
        $out = [];
        $span = max(1, $max - $min);
        $months = max(1, (int)round($span / (86400 * 30.4)));
        $step = ($months > $maxLines) ? (int)ceil($months / $maxLines) : 1;
        $cur = strtotime(date('Y-m-01', $min));
        $i = 0;
        while ($cur <= $max) {
            if ($cur >= $min && $i % $step === 0) $out[] = round(($cur - $min) / $span * 100, 3);
            $cur = strtotime('+1 month', $cur);
            $i++;
        }
        return $out;
    }

    /** Blocco CSS condiviso per il rendering del Gantt (incluso una volta per pagina). */
    public static function css(): string
    {
        return <<<CSS
<style>
.pm-gantt{--label:240px;font-size:12px;color:#0f172a}
.pm-gantt .g-scroll{overflow-x:auto;overflow-y:visible;border:1px solid #e2e8f0;border-radius:8px}
.pm-gantt .g-inner{position:relative}
.pm-gantt .g-inner{position:relative}
.pm-gantt .g-row{display:grid;grid-template-columns:var(--label) 1fr;align-items:stretch;border-top:1px solid #f1f5f9}
.pm-gantt .g-row:first-child{border-top:0}
.pm-gantt .g-row:hover .g-track{background:#fafcff}
.pm-gantt .g-head{position:sticky;top:0;z-index:4;background:#fff;border-bottom:1px solid #e2e8f0}
.pm-gantt .g-sub .g-label{font-weight:700;color:#475569;background:#f8fafc}
.pm-gantt .g-label{padding:9px 12px;border-right:1px solid #eef2f7;position:sticky;left:0;z-index:3;background:#fff;line-height:1.25}
.pm-gantt .g-row:hover .g-label{background:#fafcff}
.pm-gantt .g-label .code{font-weight:700;text-decoration:none}
.pm-gantt .g-label .sub{color:#64748b;font-size:10px;margin-top:1px}
.pm-gantt .g-track{position:relative;min-height:34px;background:#fff}
.pm-gantt .g-head .g-track{min-height:30px}
.pm-gantt .g-gridlayer{position:absolute;left:var(--label);top:0;bottom:0;right:0;pointer-events:none;z-index:0}
.pm-gantt .g-grid{position:absolute;top:0;bottom:0;width:1px;background:#eef2f7}
.pm-gantt .g-head .g-grid{background:#e8edf3}
.pm-gantt .g-tick{position:absolute;top:9px;font-size:10px;color:#64748b;transform:translateX(-50%);white-space:nowrap;font-weight:600}
.pm-gantt .g-today{position:absolute;top:0;bottom:0;width:2px;background:#dc2626;opacity:.55;z-index:2}
.pm-gantt .g-head .g-today{opacity:.8}
.pm-gantt .g-bar{position:absolute;border-radius:5px;box-shadow:0 1px 2px rgba(15,23,42,.10);z-index:1}
.pm-gantt .g-plan{background:#c7d2fe}
.pm-gantt .g-act{background:#2563eb}
.pm-gantt .g-act.late{background:#dc2626}
.pm-gantt .g-phase{background:#dcfce7;border:1px solid #16a34a;overflow:hidden}
.pm-gantt .g-phase>i{display:block;height:100%;background:#16a34a}
.pm-gantt .g-tech{background:#93c5fd}
.pm-gantt .g-lbl{position:absolute;font-size:9px;color:#475569;white-space:nowrap;z-index:1}
.pm-gantt .g-empty{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#cbd5e1;font-size:10px}
.pm-gantt .g-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:#64748b;margin:2px 2px 12px}
.pm-gantt .g-legend .k{display:inline-flex;align-items:center;gap:6px}
.pm-gantt .g-legend .sw{width:22px;height:10px;border-radius:3px;display:inline-block}
.pm-hist{display:flex;align-items:flex-end;gap:8px;height:150px;padding:18px 4px 4px;overflow-x:auto}
.pm-hist .col{min-width:34px;flex:1 0 34px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%}
.pm-hist .col .v{font-size:10px;color:#64748b;margin-bottom:3px;font-weight:600}
.pm-hist .col .b{width:66%;min-width:16px;background:#2563eb;border-radius:3px 3px 0 0;min-height:3px}
.pm-hist .col .m{font-size:9px;color:#94a3b8;margin-top:5px;white-space:nowrap}
@media print{.pm-gantt .g-scroll{overflow:visible}}
</style>
CSS;
    }
}
