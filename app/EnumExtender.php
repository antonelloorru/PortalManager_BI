<?php
/**
 * certV 5.8.0 — EnumExtender
 *
 * Helper per:
 *   - Leggere a runtime i valori ENUM correnti di una colonna (no hardcode)
 *   - Registrare proposte di estensione (valori CSV non in lista)
 *   - Approvare proposte → ALTER TABLE estende l'ENUM
 *   - Mappare proposte → ritorna il valore canonico da scrivere
 *
 * Le proposte sono indicizzate UNIQUE su (target_table, target_column, proposed_value)
 * → idempotenza: re-importi non duplicano, ma incrementano `occurrences`.
 */
class EnumExtender
{
    private PDO $pdo;
    private static array $enumCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Restituisce i valori ammessi dell'ENUM letti da information_schema.
     * Cached per request.
     *
     * @return string[] Lista valori (case-sensitive come dichiarati nello schema)
     */
    public function getEnumValues(string $table, string $column): array
    {
        $key = "$table.$column";
        if (isset(self::$enumCache[$key])) return self::$enumCache[$key];

        // Whitelist tabelle/colonne autorizzate (anti-injection)
        if (!self::isWhitelisted($table, $column)) {
            throw new InvalidArgumentException("ENUM non whitelistato: $table.$column");
        }

        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        $type = (string)$stmt->fetchColumn();

        if (!preg_match("/^enum\\((.*)\\)$/i", $type, $m)) {
            throw new RuntimeException("Colonna $table.$column non è ENUM (type=$type)");
        }

        // Estraggo i valori tra apici singoli
        preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vals);
        $values = array_map(fn($v) => str_replace("''", "'", $v), $vals[1]);

        self::$enumCache[$key] = $values;
        return $values;
    }

    /**
     * Whitelist: solo questi (table, column) possono essere estesi via UI.
     */
    public static function isWhitelisted(string $table, string $column): bool
    {
        $allowed = [
            'certifications.level',
            'certifications.category',
            'employee_skills.level',
        ];
        return in_array("$table.$column", $allowed, true);
    }

    /**
     * Lista delle ENUM whitelistate per la UI di gestione.
     */
    public static function getWhitelistedTargets(): array
    {
        return [
            'certifications.level'    => ['label' => 'Catalogo certificazioni — Livello',
                                          'description' => 'Livello della certificazione (Foundation, Associate, ...)'],
            'certifications.category' => ['label' => 'Catalogo certificazioni — Categoria',
                                          'description' => 'Tipologia: aziendale / commerciale / tecnica'],
            'employee_skills.level'   => ['label' => 'Skill dipendenti — Livello',
                                          'description' => 'Livello competenza (beginner / intermediate / ...)'],
        ];
    }

    /**
     * Verifica se un valore è già nell'ENUM (case-sensitive) o se è proponibile.
     *
     * @return array{exact:string|null, fuzzy:string|null}
     *  - exact: valore canonico se match esatto, null altrimenti
     *  - fuzzy: valore canonico se match case-insensitive (es. "associate" → "Associate"), null altrimenti
     */
    public function resolve(string $table, string $column, ?string $value): array
    {
        if ($value === null || $value === '') return ['exact' => null, 'fuzzy' => null];
        $values = $this->getEnumValues($table, $column);

        if (in_array($value, $values, true)) {
            return ['exact' => $value, 'fuzzy' => $value];
        }
        $lower = mb_strtolower($value);
        foreach ($values as $v) {
            if (mb_strtolower($v) === $lower) {
                return ['exact' => null, 'fuzzy' => $v];
            }
        }
        return ['exact' => null, 'fuzzy' => null];
    }

    /**
     * Registra una proposta di estensione ENUM. Idempotente:
     * se esiste già la stessa (table, column, value), incrementa occurrences.
     *
     * @return int  ID della proposta
     */
    public function recordProposal(string $table, string $column, string $value, ?int $sourceJobId = null): int
    {
        if (!self::isWhitelisted($table, $column)) {
            throw new InvalidArgumentException("ENUM non whitelistato: $table.$column");
        }
        $value = trim($value);
        if ($value === '') throw new InvalidArgumentException("Valore vuoto");

        // Insert idempotente
        $this->pdo->prepare(
            "INSERT INTO enum_proposals
                (target_table, target_column, proposed_value, occurrences, first_source_ref)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
                occurrences = occurrences + 1,
                last_seen_at = NOW()"
        )->execute([$table, $column, $value, $sourceJobId]);

        $id = $this->pdo->prepare(
            "SELECT id FROM enum_proposals
              WHERE target_table = ? AND target_column = ? AND proposed_value = ?"
        );
        $id->execute([$table, $column, $value]);
        return (int)$id->fetchColumn();
    }

    /**
     * Approva una proposta: estende l'ENUM con il nuovo valore.
     * Operazione DDL → richiede privilegi ALTER sull'utente DB.
     */
    public function approveProposal(int $proposalId, ?int $userId = null): array
    {
        $p = $this->pdo->prepare("SELECT * FROM enum_proposals WHERE id = ? AND status = 'pending'");
        $p->execute([$proposalId]);
        $row = $p->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Proposta non trovata o già processata.");

        $table  = $row['target_table'];
        $column = $row['target_column'];
        $value  = $row['proposed_value'];

        if (!self::isWhitelisted($table, $column)) {
            throw new RuntimeException("ENUM non whitelistato.");
        }

        // Leggo enum corrente e ricostruisco il tipo
        $current = $this->getEnumValues($table, $column);
        if (in_array($value, $current, true)) {
            // Già presente (corsa concorrente?)
            $this->pdo->prepare(
                "UPDATE enum_proposals SET status = 'approved', decided_at = NOW(), decided_by = ?
                  WHERE id = ?"
            )->execute([$userId, $proposalId]);
            self::$enumCache = [];
            return ['ok' => true, 'action' => 'already_present', 'enum' => $current];
        }

        $newList = array_merge($current, [$value]);
        $escaped = array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $newList);
        $enumDef = 'ENUM(' . implode(',', $escaped) . ')';

        // Lettura definizione completa per preservare DEFAULT/NULL
        $info = $this->pdo->prepare(
            "SELECT IS_NULLABLE, COLUMN_DEFAULT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $info->execute([$table, $column]);
        $meta = $info->fetch(PDO::FETCH_ASSOC);

        $nullable = ($meta['IS_NULLABLE'] === 'YES') ? 'DEFAULT NULL' : 'NOT NULL';
        $default = '';
        if ($meta['COLUMN_DEFAULT'] !== null && $meta['IS_NULLABLE'] !== 'YES') {
            $default = "DEFAULT '" . str_replace("'", "''", $meta['COLUMN_DEFAULT']) . "'";
        }

        $sql = "ALTER TABLE `$table` MODIFY COLUMN `$column` $enumDef $nullable $default";
        $this->pdo->exec($sql);

        $this->pdo->prepare(
            "UPDATE enum_proposals SET status = 'approved', decided_at = NOW(), decided_by = ?
              WHERE id = ?"
        )->execute([$userId, $proposalId]);

        self::$enumCache = [];

        if (function_exists('write_log')) {
            write_log('EnumExtender', 'success',
                "ENUM esteso: $table.$column += '$value'", $userId);
        }

        return ['ok' => true, 'action' => 'extended', 'enum' => $newList, 'sql' => $sql];
    }

    /**
     * Mappa una proposta a un valore ESISTENTE dell'enum.
     * Aggiorna anche eventuali staging rows che attendevano il completamento.
     */
    public function mapProposal(int $proposalId, string $mappedTo, ?int $userId = null): array
    {
        $p = $this->pdo->prepare("SELECT * FROM enum_proposals WHERE id = ? AND status = 'pending'");
        $p->execute([$proposalId]);
        $row = $p->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Proposta non trovata o già processata.");

        $table  = $row['target_table'];
        $column = $row['target_column'];
        $current = $this->getEnumValues($table, $column);
        if (!in_array($mappedTo, $current, true)) {
            throw new RuntimeException("Valore di mappatura '$mappedTo' non valido per $table.$column.");
        }

        $this->pdo->prepare(
            "UPDATE enum_proposals
                SET status = 'mapped', mapped_to = ?, decided_at = NOW(), decided_by = ?
              WHERE id = ?"
        )->execute([$mappedTo, $userId, $proposalId]);

        if (function_exists('write_log')) {
            write_log('EnumExtender', 'info',
                "Proposta '{$row['proposed_value']}' mappata a '$mappedTo' su $table.$column", $userId);
        }

        return ['ok' => true, 'action' => 'mapped'];
    }

    /**
     * Rifiuta una proposta (non estende, non mappa: la proposta resta come storia).
     */
    public function rejectProposal(int $proposalId, ?int $userId = null, string $reason = ''): array
    {
        $this->pdo->prepare(
            "UPDATE enum_proposals
                SET status = 'rejected', decided_at = NOW(), decided_by = ?, notes = ?
              WHERE id = ? AND status = 'pending'"
        )->execute([$userId, $reason, $proposalId]);
        return ['ok' => true];
    }

    /**
     * Lista proposte (per UI).
     */
    public function listProposals(?string $status = null, ?string $tableFilter = null): array
    {
        $where = ['1=1'];
        $params = [];
        if ($status !== null && $status !== '')      { $where[] = "ep.status = ?"; $params[] = $status; }
        if ($tableFilter !== null && $tableFilter !== '') { $where[] = "ep.target_table = ?"; $params[] = $tableFilter; }

        $sql = "SELECT ep.*,
                       CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,'')) AS user_name
                  FROM enum_proposals ep
                  LEFT JOIN users u ON u.id = ep.decided_by
                  LEFT JOIN employees e ON e.id = u.employee_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY ep.status = 'pending' DESC, ep.last_seen_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
