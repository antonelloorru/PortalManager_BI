<?php
/**
 * PortalManager — app/PrjLink.php  (v1.10.01)
 *
 * Collegamento Progetto PRJ ↔ commessa SP (cm_projects, identificata dal codice commessa).
 *  - un PRJ è collegato al massimo a una commessa SP alla volta; una commessa può avere più PRJ;
 *  - ogni azione è storicizzata in cm_prj_link_history (chi, quando, perché) e in write_log();
 *  - cm_projects è solo letta: il modulo non la scrive mai;
 *  - afterSync(): dopo la sincronizzazione del gestionale registra gli orfani (commessa eliminata,
 *    FK ON DELETE SET NULL) e collega i PRJ citati nel commercial_ref della commessa.
 */
declare(strict_types=1);

final class PrjLink
{
    public function __construct(private PDO $pdo) {}

    /** Commessa SP per id (solo commesse reali, esclusi i segnaposto DGB-). */
    public function commessa(int $spId): ?array
    {
        $st = $this->pdo->prepare("SELECT p.id, p.project_code, p.name, p.client_id, COALESCE(c.name, p.client_raw) AS cliente, p.exec_company_id,
                                          p.commercial_ref, p.start_date, p.end_date, p.operational_status, p.value_total, p.actual_cost, p.margin_total
                                     FROM cm_projects p LEFT JOIN clients c ON c.id = p.client_id
                                    WHERE p.id = ? AND p.project_code NOT LIKE 'DGB-%'");
        $st->execute([$spId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Ricerca per il selettore: codice commessa, nome, cliente, commercial_ref. */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . $q . '%';
        $st = $this->pdo->prepare(
            "SELECT p.id, p.project_code, p.name, COALESCE(c.name, p.client_raw) AS cliente, p.operational_status, p.start_date, p.end_date
               FROM cm_projects p LEFT JOIN clients c ON c.id = p.client_id
              WHERE p.project_code NOT LIKE 'DGB-%'
                AND (p.project_code LIKE ? OR p.name LIKE ? OR p.client_raw LIKE ? OR c.name LIKE ? OR p.commercial_ref LIKE ?)
              ORDER BY (p.project_code = ?) DESC, (p.project_code LIKE ?) DESC, p.project_code
              LIMIT " . max(1, min(100, $limit)));
        $st->execute([$like, $like, $like, $like, $like, $q, $q . '%']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Similarità tra nomi (Jaccard su trigrammi), 0..1. */
    private static function sim(string $a, string $b): float
    {
        $tri = function (string $s): array {
            $s = '  ' . preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)) . '  ';
            $o = [];
            for ($i = 0; $i < strlen($s) - 2; $i++) $o[substr($s, $i, 3)] = true;
            return $o;
        };
        $x = $tri($a); $y = $tri($b);
        if (!$x || !$y) return 0.0;
        $i = count(array_intersect_key($x, $y));
        return $i / (count($x) + count($y) - $i);
    }

    /**
     * Suggerimenti ordinati per punteggio (0-100):
     * stesso cliente 30 · commercial_ref con codice PRJ o CIG 30 · similarità nome ≤15 · sovrapposizione date ≤15 · stessa società 10.
     */
    public function suggestions(int $prjId, int $limit = 10): array
    {
        $prj = $this->pdo->prepare("SELECT * FROM cm_prj WHERE id = ?");
        $prj->execute([$prjId]);
        $p = $prj->fetch(PDO::FETCH_ASSOC);
        if (!$p) return [];
        $w = ["p.project_code NOT LIKE 'DGB-%'"]; $a = []; $or = [];
        if ($p['client_id'])       { $or[] = 'p.client_id = ?'; $a[] = (int)$p['client_id']; }
        if ($p['exec_company_id']) { $or[] = 'p.exec_company_id = ?'; $a[] = (int)$p['exec_company_id']; }
        $or[] = 'p.commercial_ref LIKE ?'; $a[] = '%' . $p['prj_code'] . '%';
        if ($p['cig'])             { $or[] = 'p.commercial_ref LIKE ?'; $a[] = '%' . $p['cig'] . '%'; }
        if ($p['client_raw'])      { $or[] = 'p.client_raw LIKE ?'; $a[] = '%' . mb_substr((string)$p['client_raw'], 0, 20) . '%'; }
        $w[] = '(' . implode(' OR ', $or) . ')';
        $st = $this->pdo->prepare("SELECT p.id, p.project_code, p.name, p.client_id, COALESCE(c.name, p.client_raw) AS cliente, p.exec_company_id,
                                          p.commercial_ref, p.start_date, p.end_date, p.operational_status
                                     FROM cm_projects p LEFT JOIN clients c ON c.id = p.client_id
                                    WHERE " . implode(' AND ', $w) . " LIMIT 500");
        $st->execute($a);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $s = 0.0; $why = [];
            if ($p['client_id'] && (int)$c['client_id'] === (int)$p['client_id']) { $s += 30; $why[] = 'stesso cliente'; }
            $cr = (string)$c['commercial_ref'];
            if ($cr !== '' && (stripos($cr, $p['prj_code']) !== false || ($p['cig'] && stripos($cr, (string)$p['cig']) !== false))) { $s += 30; $why[] = 'riferimento PRJ/CIG'; }
            $sim = self::sim((string)$p['nome'], (string)$c['name']);
            if ($sim > 0) { $s += 15 * $sim; if ($sim >= 0.2) $why[] = 'nome simile'; }
            if ($p['start_date'] && $p['end_date'] && $c['start_date'] && $c['end_date']) {
                $ov = min(strtotime($p['end_date']), strtotime($c['end_date'])) - max(strtotime($p['start_date']), strtotime($c['start_date']));
                $len = max(1, strtotime($p['end_date']) - strtotime($p['start_date']));
                if ($ov > 0) { $s += 15 * min(1, $ov / $len); $why[] = 'date sovrapposte'; }
            }
            if ($p['exec_company_id'] && (int)$c['exec_company_id'] === (int)$p['exec_company_id']) { $s += 10; $why[] = 'stessa società'; }
            if ($s < 10) continue;
            $out[] = $c + ['punteggio' => round($s, 1), 'motivi' => implode(', ', $why)];
        }
        usort($out, fn($x, $y) => $y['punteggio'] <=> $x['punteggio']);
        return array_slice($out, 0, $limit);
    }

    /** Collega (o sostituisce) la commessa SP del PRJ. */
    public function link(int $prjId, int $spId, string $motivo, ?int $userId): void
    {
        $sp = $this->commessa($spId);
        if (!$sp) throw new InvalidArgumentException('Commessa SP non valida (inesistente o segnaposto DGB).');
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT prj_code, sp_project_id FROM cm_prj WHERE id = ? FOR UPDATE");
            $st->execute([$prjId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new InvalidArgumentException('Progetto PRJ inesistente.');
            if ((int)$cur['sp_project_id'] === $spId) { if ($own) $this->pdo->commit(); return; }
            $azione = $cur['sp_project_id'] ? 'sostituito' : 'collegato';
            $this->pdo->prepare("UPDATE cm_prj SET sp_project_id = ?, sp_linked_at = NOW() WHERE id = ?")->execute([$spId, $prjId]);
            $this->history($prjId, $spId, $sp['project_code'], $azione, $motivo, $userId);
            $this->changeLog($prjId, $cur['sp_project_id'], $spId, $userId);
            if ($own) $this->pdo->commit();
        } catch (Throwable $e) { if ($own) $this->pdo->rollBack(); throw $e; }
        if (function_exists('write_log')) write_log('Commesse', 'info', "PRJ {$cur['prj_code']} $azione alla commessa SP {$sp['project_code']}", $userId);
    }

    /** Scollega la commessa SP. */
    public function unlink(int $prjId, string $motivo, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT p.prj_code, p.sp_project_id, s.project_code FROM cm_prj p LEFT JOIN cm_projects s ON s.id = p.sp_project_id WHERE p.id = ?");
        $st->execute([$prjId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur || !$cur['sp_project_id']) return;
        $this->pdo->prepare("UPDATE cm_prj SET sp_project_id = NULL, sp_linked_at = NULL WHERE id = ?")->execute([$prjId]);
        $this->history($prjId, (int)$cur['sp_project_id'], $cur['project_code'], 'scollegato', $motivo, $userId);
        $this->changeLog($prjId, $cur['sp_project_id'], null, $userId);
        if (function_exists('write_log')) write_log('Commesse', 'info', "PRJ {$cur['prj_code']} scollegato dalla commessa SP {$cur['project_code']}", $userId);
    }

    public function historyOf(int $prjId): array
    {
        $st = $this->pdo->prepare("SELECT h.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.first_name, e.last_name)),''), u.display_name, u.email) AS utente
                                     FROM cm_prj_link_history h LEFT JOIN users u ON u.id = h.user_id LEFT JOIN employees e ON e.id = u.employee_id
                                    WHERE h.prj_id = ? ORDER BY h.created_at DESC, h.id DESC");
        $st->execute([$prjId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function history(int $prjId, ?int $spId, ?string $code, string $azione, string $motivo, ?int $userId): void
    {
        $this->pdo->prepare("INSERT INTO cm_prj_link_history (prj_id, sp_project_id, sp_project_code, azione, motivo, user_id) VALUES (?,?,?,?,?,?)")
            ->execute([$prjId, $spId, $code, $azione, mb_substr($motivo, 0, 255) ?: null, $userId]);
    }

    private function changeLog(int $prjId, $old, $new, ?int $userId): void
    {
        if (!is_file(__DIR__ . '/EntityChangeLog.php')) return;
        require_once __DIR__ . '/EntityChangeLog.php';
        try { (new EntityChangeLog($this->pdo))->logField('cm_prj', $prjId, 'sp_project_id', $old, $new, 'update', 'ui', null, $userId); }
        catch (Throwable $e) { /* audit best-effort */ }
    }

    /**
     * Dopo la sincronizzazione del gestionale:
     *  1. PRJ rimasti senza commessa perché la commessa è stata eliminata (SET NULL) → evento orfano_da_sync;
     *  2. commesse con un codice PRJ-AAAA-NNNN nel commercial_ref → collegamento automatico dei PRJ non collegati.
     * @return array{orfani:int, collegati:int}
     */
    public function afterSync(?int $userId = null): array
    {
        $orf = 0; $col = 0;
        try {
            $rows = $this->pdo->query(
                "SELECT p.id, p.prj_code, h.sp_project_id, h.sp_project_code
                   FROM cm_prj p
                   JOIN cm_prj_link_history h ON h.id = (SELECT MAX(h2.id) FROM cm_prj_link_history h2 WHERE h2.prj_id = p.id)
                  WHERE p.sp_project_id IS NULL AND h.azione IN ('collegato','sostituito')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $this->history((int)$r['id'], (int)$r['sp_project_id'], $r['sp_project_code'], 'orfano_da_sync', 'commessa eliminata dalla sincronizzazione', $userId);
                if (function_exists('write_log')) write_log('Commesse', 'warning', "PRJ {$r['prj_code']}: commessa SP {$r['sp_project_code']} eliminata, progetto non collegato", $userId);
                $orf++;
            }
            $cand = $this->pdo->query("SELECT id, commercial_ref FROM cm_projects WHERE commercial_ref REGEXP 'PRJ-[0-9]{4}-[0-9]{4}' AND project_code NOT LIKE 'DGB-%'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cand as $c) {
                preg_match_all('/PRJ-\d{4}-\d{4}/', (string)$c['commercial_ref'], $m);
                foreach (array_unique($m[0]) as $code) {
                    $st = $this->pdo->prepare("SELECT id FROM cm_prj WHERE prj_code = ? AND sp_project_id IS NULL");
                    $st->execute([$code]);
                    if ($pid = (int)$st->fetchColumn()) { $this->link($pid, (int)$c['id'], 'commercial_ref (sync)', $userId); $col++; }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('write_log')) write_log('Commesse', 'error', 'PRJ afterSync: ' . $e->getMessage(), $userId);
        }
        return ['orfani' => $orf, 'collegati' => $col];
    }
}
