<?php
/**
 * app/ProfessionalStore.php — Anagrafica Professionisti (v1.7.81)
 *
 * Gestisce gli operatori importati dal gestionale che non figurano tra i
 * dipendenti: import (senza credenziali), ricerca, suggerimenti di merge per
 * nome/email e merge effettivo verso employees. Dati riservati: nessuna
 * password/rfid viene mai letta o salvata.
 */
final class ProfessionalStore
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }

    /** UPSERT di un professionista dall'export operator (chiave: source_operator_id). */
    public function upsert(array $d, ?int $batchId, ?int $userId): string
    {
        $st = $this->pdo->prepare("SELECT id, employee_id, status FROM cm_professionals WHERE source_operator_id = ?");
        $st->execute([$d['source_operator_id']]);
        $ex = $st->fetch(PDO::FETCH_ASSOC);

        if ($ex) {
            // non tocca employee_id/status se gia' lavorati (unito/confermato/ignorato)
            $this->pdo->prepare(
                "UPDATE cm_professionals SET username=?, email=?, first_name=?, last_name=?, abbr=?, company_abbr=?,
                        exec_company_id=?, phone=?, badge=?, hourly_cost=?, full_cost=?, skills=?, notes=?,
                        operator_type=?, active=?, deleted_src=?, import_batch_id=?
                  WHERE id=?"
            )->execute([
                $d['username'], $d['email'], $d['first_name'], $d['last_name'], $d['abbr'], $d['company_abbr'],
                $d['exec_company_id'], $d['phone'], $d['badge'], $d['hourly_cost'], $d['full_cost'], $d['skills'], $d['notes'],
                $d['operator_type'], $d['active'], $d['deleted_src'], $batchId, $ex['id'],
            ]);
            return 'updated';
        }

        $this->pdo->prepare(
            "INSERT INTO cm_professionals
               (source_operator_id,username,email,first_name,last_name,abbr,company_abbr,exec_company_id,
                phone,badge,hourly_cost,full_cost,skills,notes,operator_type,active,deleted_src,import_batch_id,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $d['source_operator_id'], $d['username'], $d['email'], $d['first_name'], $d['last_name'], $d['abbr'],
            $d['company_abbr'], $d['exec_company_id'], $d['phone'], $d['badge'], $d['hourly_cost'], $d['full_cost'],
            $d['skills'], $d['notes'], $d['operator_type'], $d['active'], $d['deleted_src'], $batchId, $userId,
        ]);
        return 'inserted';
    }

    /**
     * Cerca un dipendente corrispondente (email esatta, poi nome+cognome).
     * @return array|null ['id','first_name','last_name','match' => 'email'|'name'|'name_swapped']
     */
    public function suggestEmployee(array $prof): ?array
    {
        $email = self::norm((string)($prof['email'] ?? ''));
        if ($email !== '') {
            $r = null;
            foreach (["personal_email", "work_email"] as $ecol) {
                try {
                    $st = $this->pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE LOWER(`$ecol`)=? LIMIT 1");
                    $st->execute([$email]);
                    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $r['match'] = 'email'; return $r; }
                } catch (Throwable $e) { /* colonna assente: prova la successiva */ }
            }
        }
        $fn = self::norm((string)($prof['first_name'] ?? ''));
        $ln = self::norm((string)($prof['last_name'] ?? ''));
        if ($fn !== '' && $ln !== '') {
            $st = $this->pdo->prepare(
                "SELECT id, first_name, last_name FROM employees
                  WHERE LOWER(first_name)=? AND LOWER(last_name)=? LIMIT 1"
            );
            $st->execute([$fn, $ln]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $r['match'] = 'name'; return $r; }
            $st->execute([$ln, $fn]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $r['match'] = 'name_swapped'; return $r; }
        }
        return null;
    }

    /** Collega (merge) un professionista a un dipendente esistente. */
    public function linkToEmployee(int $profId, int $employeeId, ?int $userId): array
    {
        $emp = $this->pdo->prepare("SELECT id FROM employees WHERE id=?");
        $emp->execute([$employeeId]);
        if (!$emp->fetchColumn()) return ['ok' => false, 'msg' => 'Dipendente inesistente.'];

        $this->pdo->prepare("UPDATE cm_professionals SET employee_id=?, status='unito' WHERE id=?")
            ->execute([$employeeId, $profId]);

        // propaga il collegamento agli alias tecnico, cosi' i rapporti si agganciano al dipendente
        try {
            $p = $this->pdo->prepare("SELECT username, first_name, last_name, abbr FROM cm_professionals WHERE id=?");
            $p->execute([$profId]); $pr = $p->fetch(PDO::FETCH_ASSOC);
            $names = array_filter([
                trim(($pr['first_name'] ?? '') . ' ' . ($pr['last_name'] ?? '')),
                trim(($pr['last_name'] ?? '') . ' ' . ($pr['first_name'] ?? '')),
                $pr['username'] ?? '', $pr['abbr'] ?? '',
            ]);
            $ins = $this->pdo->prepare(
                "INSERT INTO cm_alias_technician (raw_name, employee_id, created_by)
                 VALUES (?,?,?) ON DUPLICATE KEY UPDATE employee_id=VALUES(employee_id)"
            );
            foreach (array_unique($names) as $nm) if ($nm !== '') $ins->execute([$nm, $employeeId, $userId]);
        } catch (Throwable $e) { /* alias facoltativo */ }

        return ['ok' => true, 'msg' => 'Professionista collegato al dipendente #' . $employeeId . '.'];
    }

    public function setStatus(int $profId, string $status): void
    {
        if (!in_array($status, ['nuovo', 'confermato', 'unito', 'ignorato'], true)) return;
        $this->pdo->prepare("UPDATE cm_professionals SET status=? WHERE id=?")->execute([$status, $profId]);
    }

    public function unlink(int $profId): void
    {
        $this->pdo->prepare("UPDATE cm_professionals SET employee_id=NULL, status='nuovo' WHERE id=?")->execute([$profId]);
    }

    /** Elenco paginato con filtri (q, status, company, only_active). */
    public function listItems(array $f, int $limit, int $offset): array
    {
        $w = ['1=1']; $a = [];
        if (!empty($f['q'])) {
            $w[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ? OR p.username LIKE ? OR p.abbr LIKE ?)";
            $like = '%' . $f['q'] . '%'; array_push($a, $like, $like, $like, $like, $like);
        }
        if (!empty($f['status'])) { $w[] = "p.status=?"; $a[] = $f['status']; }
        if (!empty($f['company'])) { $w[] = "p.company_abbr=?"; $a[] = $f['company']; }
        if (!empty($f['only_active'])) { $w[] = "p.active=1 AND p.deleted_src=0"; }
        // v1.7.82: filtro per tipo — esterni (nessuna corrispondenza) vs dipendenti (collegati o rilevati).
        if (($f['type'] ?? '') === 'esterni')     { $w[] = "p.employee_id IS NULL AND p.employee_match=0"; }
        elseif (($f['type'] ?? '') === 'dipendenti') { $w[] = "(p.employee_id IS NOT NULL OR p.employee_match=1)"; }
        $wsql = implode(' AND ', $w);

        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM cm_professionals p WHERE $wsql");
        $cnt->execute($a); $total = (int)$cnt->fetchColumn();

        $st = $this->pdo->prepare(
            "SELECT p.*, CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,'')) AS emp_name
               FROM cm_professionals p LEFT JOIN employees e ON e.id=p.employee_id
              WHERE $wsql ORDER BY p.last_name, p.first_name LIMIT $limit OFFSET $offset"
        );
        $st->execute($a);
        return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function get(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_professionals WHERE id=?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Registra sul professionista l'esito del confronto con l'anagrafica dipendenti. */
    public function setMatch(int $profId, ?array $sug): void
    {
        if ($sug) {
            $this->pdo->prepare("UPDATE cm_professionals SET employee_match=1, matched_employee_id=?, match_type=? WHERE id=?")
                ->execute([(int)$sug['id'], $sug['match'], $profId]);
        } else {
            $this->pdo->prepare("UPDATE cm_professionals SET employee_match=0, matched_employee_id=NULL, match_type=NULL WHERE id=?")
                ->execute([$profId]);
        }
    }

    /**
     * (Ri)valuta tutti i professionisti non ancora collegati rispetto ai dipendenti,
     * aggiornando il flag employee_match. Restituisce il numero di corrispondenze trovate.
     */
    public function detectEmployees(): int
    {
        $rows = $this->pdo->query("SELECT id, email, first_name, last_name FROM cm_professionals WHERE employee_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $r) {
            $sug = $this->suggestEmployee($r);
            $this->setMatch((int)$r['id'], $sug);
            if ($sug) $n++;
        }
        return $n;
    }

    /** True se il professionista è (o corrisponde a) un dipendente. */
    public static function isEmployee(array $r): bool
    {
        return !empty($r['employee_id']) || (int)($r['employee_match'] ?? 0) === 1;
    }

    /**
     * Promuove un professionista creando un nuovo record in employees e collegandolo.
     * v1.7.83: importa il professionista nell'anagrafica dipendenti. Se non attivo,
     * imposta la data di cessazione (end_date). Rileva le colonne realmente presenti
     * in employees per adattarsi allo schema.
     * @return array ['ok'=>bool,'msg'=>string,'employee_id'=>?int]
     */
    public function promoteToEmployee(int $profId, ?string $endDate, ?int $userId): array
    {
        $p = $this->get($profId);
        if (!$p) return ['ok' => false, 'msg' => 'Professionista inesistente.'];
        if (!empty($p['employee_id'])) return ['ok' => false, 'msg' => 'Già collegato a un dipendente.'];

        // colonne disponibili in employees
        try {
            $cols = $this->pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { $cols = ['first_name', 'last_name']; }
        $has = fn($c) => in_array($c, $cols, true);

        $data = [];
        if ($has('first_name'))    $data['first_name']    = $p['first_name'];
        if ($has('last_name'))     $data['last_name']     = $p['last_name'];
        if ($has('personal_email') && $p['email']) $data['personal_email'] = $p['email'];
        elseif ($has('work_email') && $p['email']) $data['work_email'] = $p['email'];
        if ($has('phone') && $p['phone'])          $data['phone'] = $p['phone'];
        if ($has('company_id') && $p['exec_company_id']) $data['company_id'] = $p['exec_company_id'];
        if ($has('employee_code') && $p['abbr'])   $data['employee_code'] = $p['abbr'];
        if ($has('status'))        $data['status'] = ((int)$p['active'] === 1 && !$endDate) ? 'active' : 'inactive';
        if ($has('end_date') && $endDate)          $data['end_date'] = $endDate;
        if ($has('notes'))         $data['notes'] = 'Importato da Anagrafica Professionisti (operatore #' . $p['source_operator_id'] . ')';
        if (empty($data['first_name']) && empty($data['last_name'])) return ['ok' => false, 'msg' => 'Nome/cognome mancanti: impossibile creare il dipendente.'];

        $keys = array_keys($data);
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $sql  = "INSERT INTO employees (`" . implode('`,`', $keys) . "`) VALUES ($ph)";
        try {
            $this->pdo->prepare($sql)->execute(array_values($data));
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => 'Creazione dipendente fallita: ' . $e->getMessage()];
        }
        $eid = (int)$this->pdo->lastInsertId();

        // collega e propaga gli alias tecnico
        $this->linkToEmployee($profId, $eid, $userId);
        return ['ok' => true, 'msg' => 'Dipendente creato (#' . $eid . ') e collegato' . ($endDate ? ' con cessazione al ' . $endDate : '') . '.', 'employee_id' => $eid];
    }

    public function companies(): array
    {
        return $this->pdo->query("SELECT DISTINCT company_abbr FROM cm_professionals WHERE company_abbr<>'' ORDER BY company_abbr")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function counts(): array
    {
        $r = ['tot' => 0, 'nuovo' => 0, 'confermato' => 0, 'unito' => 0, 'ignorato' => 0];
        foreach ($this->pdo->query("SELECT status, COUNT(*) n FROM cm_professionals GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $r[$k] = (int)$v; $r['tot'] += (int)$v;
        }
        // v1.7.82: esterni vs dipendenti (collegati o rilevati)
        $r['dipendenti'] = (int)$this->pdo->query("SELECT COUNT(*) FROM cm_professionals WHERE employee_id IS NOT NULL OR employee_match=1")->fetchColumn();
        $r['esterni']    = $r['tot'] - $r['dipendenti'];
        return $r;
    }
}
