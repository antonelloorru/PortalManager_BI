<?php
/**
 * app/RecycleBin.php — Cestino / ripristino record cancellati (v1.7.76)
 *
 * Prima di eliminare uno o più record, ne archivia lo stato completo (JSON) in
 * cm_deleted_records. Da lì è possibile ripristinarli o eliminarli in via
 * definitiva. Pensato per un uso trasparente: `softDelete()` sostituisce un
 * `DELETE` diretto e restituisce il numero di righe rimosse, avendole prima salvate.
 *
 * Il ripristino re-inserisce la riga con la PK originale; se un record con quella
 * chiave esiste già (PK riutilizzata), il ripristino viene segnalato come conflitto
 * e non sovrascrive nulla.
 */
final class RecycleBin
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Whitelist delle tabelle gestibili dal cestino (sicurezza: no tabelle arbitrarie). */
    /**
     * v1.7.77: il cestino copre ORA ogni record del portale.
     * Invece di una whitelist, si usa una DENYLIST di tabelle di sistema/infrastruttura
     * che non devono mai essere intercettate (log, impostazioni, permessi/config, tabelle
     * gestite con pattern "cancella-e-reinserisci" durante le sincronizzazioni).
     */
    private const DENY = [
        'cm_deleted_records',      // il cestino stesso
        'app_logs',               // audit di sistema
        'app_settings',           // configurazione
        'sessions', 'php_sessions',
        'role_permissions',        // riscritti in blocco (delete-all + reinsert)
        'user_permissions',        // idem
        'menu_preferences',        // reset preferenze menu
        'employee_credly_link',    // sync: delete-then-reinsert
        'employee_linkedin_link',  // sync: delete-then-reinsert
    ];

    /** Etichette amichevoli per i tipi noti; per gli altri si "umanizza" il nome tabella. */
    private const LABELS = [
        'cm_team'                 => 'Membro team commessa',
        'cm_project_phases'       => 'Fase di commessa',
        'cm_timesheet_entries'    => 'Voce timesheet',
        'cm_intervention_reports' => 'Rapporto di intervento',
        'cm_projects'             => 'Commessa',
        'cm_presales_effort'      => 'Effort presales',
        'cm_alias_project'        => 'Alias commessa',
        'cm_alias_technician'     => 'Alias tecnico',
        'cm_alias_band'           => 'Alias fascia',
        'user_certifications'     => 'Certificazione',
        'departments'             => 'Reparto / dipartimento',
        'emp_languages'           => 'Lingua conosciuta',
        'emp_education'           => 'Titolo di studio',
        'emp_experiences'         => 'Esperienza lavorativa',
        'emp_devices_phone'       => 'Telefono assegnato',
        'emp_devices_sim'         => 'SIM assegnata',
        'emp_devices_notebook'    => 'Notebook assegnato',
        'emp_devices_vehicle'     => 'Veicolo assegnato',
        'emp_vehicle_service'     => 'Intervento su veicolo',
        'emp_devices_fuel_card'   => 'Carta carburante',
        'emp_fuel_log'            => 'Rifornimento carburante',
        'emp_devices_credit_card' => 'Carta di credito',
        'emp_credit_card_statement' => 'Estratto conto carta',
        'employees'               => 'Dipendente',
    ];

    public static function allowed(): array { return self::LABELS; }
    public static function isAllowed(string $t): bool { return !in_array($t, self::DENY, true); }
    public static function labelFor(string $t): string { return self::LABELS[$t] ?? ucfirst(str_replace('_', ' ', $t)); }

    /** Colonna chiave primaria della tabella (cache), per un ripristino affidabile. */
    private array $pkCache = [];
    private function detectPk(string $table): string
    {
        if (isset($this->pkCache[$table])) return $this->pkCache[$table];
        $pk = 'id';
        try {
            $st = $this->pdo->prepare(
                "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
                  ORDER BY ORDINAL_POSITION LIMIT 1"
            );
            $st->execute([$table]);
            $c = $st->fetchColumn();
            if ($c) $pk = $c;
        } catch (Throwable $e) {}
        return $this->pkCache[$table] = $pk;
    }

    /**
     * Archivia le righe che soddisfano $where e poi le elimina.
     * @return int righe eliminate
     */
    public function softDelete(string $table, string $where, array $args, ?string $label, ?int $userId, string $context, ?string $pkCol = null): int
    {
        if (!self::isAllowed($table)) {
            // tabella di sistema: esegui la delete diretta, senza archiviare
            $st = $this->pdo->prepare("DELETE FROM `$table` WHERE $where");
            $st->execute($args);
            return $st->rowCount();
        }
        // colonna PK: se non indicata, rilevala dallo schema (fallback 'id')
        $pkCol = $pkCol ?: $this->detectPk($table);

        // 1) leggi le righe da eliminare
        $sel = $this->pdo->prepare("SELECT * FROM `$table` WHERE $where");
        $sel->execute($args);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;

        // 2) archivia ciascuna riga
        $ins = $this->pdo->prepare(
            "INSERT INTO cm_deleted_records (table_name, pk_column, record_pk, payload, label, deleted_by, context)
             VALUES (?,?,?,?,?,?,?)"
        );
        foreach ($rows as $row) {
            $pk = $row[$pkCol] ?? null;
            $ins->execute([
                $table, $pkCol, (string)$pk,
                json_encode($row, JSON_UNESCAPED_UNICODE),
                $label !== null ? $label : $this->autoLabel($table, $row),
                $userId, $context,
            ]);
        }

        // 3) elimina
        $del = $this->pdo->prepare("DELETE FROM `$table` WHERE $where");
        $del->execute($args);
        return $del->rowCount();
    }

    /**
     * Helper statico: aggancia una cancellazione al cestino con una sola riga.
     * Uso: RecycleBin::capture($pdo, 'tabella', 'id=?', [$id], $u_id, 'pagina.php');
     */
    public static function capture(PDO $pdo, string $table, string $where, array $args, ?int $userId, string $context, ?string $label = null): int
    {
        return (new self($pdo))->softDelete($table, $where, $args, $label, $userId, $context);
    }

    /** Etichetta descrittiva automatica in base alla tabella. */
    private function autoLabel(string $table, array $row): string
    {
        switch ($table) {
            case 'cm_projects':             return trim(($row['project_code'] ?? '') . ' — ' . ($row['name'] ?? ''), ' —');
            case 'cm_intervention_reports': return 'Rapporto ' . ($row['report_code'] ?? ('#' . ($row['id'] ?? '')));
            case 'cm_project_phases':       return 'Fase: ' . ($row['name'] ?? '');
            case 'cm_timesheet_entries':    return 'Timesheet ' . ($row['work_date'] ?? '') . ' — ' . ($row['hours'] ?? '') . 'h';
            case 'cm_team':                 return 'Team dip. #' . ($row['employee_id'] ?? '');
            case 'departments':             return 'Reparto: ' . ($row['name'] ?? '');
            case 'user_certifications':     return 'Certificazione dip. #' . ($row['employee_id'] ?? '') . ' — cat. #' . ($row['certification_id'] ?? '');
            case 'emp_languages':           return 'Lingua: ' . ($row['language'] ?? ($row['language_name'] ?? '')) . ' (dip. #' . ($row['employee_id'] ?? '') . ')';
            case 'emp_education':            return 'Studio: ' . ($row['title'] ?? ($row['degree'] ?? '')) . ' (dip. #' . ($row['employee_id'] ?? '') . ')';
            case 'emp_experiences':          return 'Esperienza: ' . ($row['role'] ?? ($row['company'] ?? '')) . ' (dip. #' . ($row['employee_id'] ?? '') . ')';
            case 'emp_devices_phone':
            case 'emp_devices_sim':
            case 'emp_devices_notebook':
            case 'emp_devices_vehicle':
            case 'emp_devices_fuel_card':
            case 'emp_devices_credit_card':  return self::labelFor($table) . ' (dip. #' . ($row['employee_id'] ?? '') . ')';
            case 'emp_vehicle_service':      return 'Intervento veicolo #' . ($row['id'] ?? '');
            case 'emp_fuel_log':             return 'Rifornimento #' . ($row['id'] ?? '');
            case 'emp_credit_card_statement':return 'Estratto carta #' . ($row['id'] ?? '');
            case 'employees':                return 'Dipendente: ' . trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            default:                        return self::labelFor($table) . ' #' . ($row['id'] ?? '');
        }
    }

    /**
     * Ripristina un elemento del cestino.
     * @return array ['ok'=>bool, 'msg'=>string]
     */
    public function restore(int $binId, ?int $userId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM cm_deleted_records WHERE id=? AND restored=0");
        $st->execute([$binId]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rec) return ['ok' => false, 'msg' => 'Elemento non trovato o già ripristinato.'];

        $table = $rec['table_name'];
        if (!self::isAllowed($table)) return ['ok' => false, 'msg' => 'Tabella non gestita dal cestino.'];

        $row = json_decode($rec['payload'], true);
        if (!is_array($row) || !$row) return ['ok' => false, 'msg' => 'Dati archiviati non validi.'];

        $pkCol = $rec['pk_column'] ?: 'id';

        // conflitto: la PK esiste già?
        if (isset($row[$pkCol]) && $row[$pkCol] !== null && $row[$pkCol] !== '') {
            $chk = $this->pdo->prepare("SELECT 1 FROM `$table` WHERE `$pkCol`=? LIMIT 1");
            $chk->execute([$row[$pkCol]]);
            if ($chk->fetchColumn()) {
                return ['ok' => false, 'msg' => "Esiste già un record con la stessa chiave ($pkCol={$row[$pkCol]}). Ripristino annullato per non sovrascrivere dati."];
            }
        }

        // reinserimento con le colonne archiviate
        $cols = array_keys($row);
        $place = implode(',', array_fill(0, count($cols), '?'));
        $colSql = '`' . implode('`,`', $cols) . '`';
        try {
            $ins = $this->pdo->prepare("INSERT INTO `$table` ($colSql) VALUES ($place)");
            $ins->execute(array_values($row));
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => 'Errore nel ripristino: ' . $e->getMessage()];
        }

        $this->pdo->prepare("UPDATE cm_deleted_records SET restored=1, restored_at=NOW(), restored_by=? WHERE id=?")
            ->execute([$userId, $binId]);
        return ['ok' => true, 'msg' => 'Record ripristinato: ' . $rec['label']];
    }

    /** Elimina definitivamente un elemento del cestino. */
    public function purge(int $binId): bool
    {
        $st = $this->pdo->prepare("DELETE FROM cm_deleted_records WHERE id=?");
        $st->execute([$binId]);
        return $st->rowCount() > 0;
    }

    /** Svuota il cestino degli elementi più vecchi di N giorni (solo non ancora ripristinati e ripristinati). */
    public function purgeOlderThan(int $days): int
    {
        $st = $this->pdo->prepare("DELETE FROM cm_deleted_records WHERE deleted_at < (NOW() - INTERVAL ? DAY)");
        $st->execute([$days]);
        return $st->rowCount();
    }

    /** Elenco filtrato per la vista Cestino. */
    public function listItems(array $f = [], int $limit = 100, int $offset = 0): array
    {
        $where = []; $args = [];
        if (!empty($f['table']))    { $where[] = "d.table_name = ?"; $args[] = $f['table']; }
        if (isset($f['restored']) && $f['restored'] !== '') { $where[] = "d.restored = ?"; $args[] = (int)$f['restored']; }
        if (!empty($f['q']))        { $where[] = "d.label LIKE ?";   $args[] = '%' . $f['q'] . '%'; }
        $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stC = $this->pdo->prepare("SELECT COUNT(*) FROM cm_deleted_records d $wsql");
        $stC->execute($args);
        $total = (int)$stC->fetchColumn();

        $limit = max(1, $limit); $offset = max(0, $offset);
        // nome utente da employees (via users.employee_id); fallback su users.display_name/email.
        // La JOIN con users/employees può fallire su schemi ridotti: in tal caso si degrada a query base.
        try {
            $st = $this->pdo->prepare(
                "SELECT d.*, TRIM(CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,''))) AS deleted_by_name,
                        u.display_name AS deleted_by_alt, u.email AS deleted_by_email,
                        TRIM(CONCAT(COALESCE(er.first_name,''),' ',COALESCE(er.last_name,''))) AS restored_by_name
                   FROM cm_deleted_records d
                   LEFT JOIN users u   ON u.id = d.deleted_by      LEFT JOIN employees e  ON e.id = u.employee_id
                   LEFT JOIN users ur  ON ur.id = d.restored_by    LEFT JOIN employees er ON er.id = ur.employee_id
                 $wsql ORDER BY d.id DESC LIMIT $limit OFFSET $offset"
            );
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $st = $this->pdo->prepare("SELECT d.* FROM cm_deleted_records d $wsql ORDER BY d.id DESC LIMIT $limit OFFSET $offset");
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        // normalizza il nome eliminatore
        foreach ($rows as &$r) {
            $r['deleted_by_name'] = trim($r['deleted_by_name'] ?? '') ?: ($r['deleted_by_alt'] ?? '') ?: ($r['deleted_by_email'] ?? '');
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total];
    }

    public function tables(): array
    {
        return $this->pdo->query("SELECT DISTINCT table_name FROM cm_deleted_records ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    }
}
