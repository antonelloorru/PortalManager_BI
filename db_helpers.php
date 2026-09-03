<?php
/**
 * ════════════════════════════════════════════════════════════════
 *  certV 2.4 — db_helpers.php
 *  Funzioni di introspezione DB che NON usano information_schema.
 *  Usano esclusivamente SHOW TABLES / SHOW COLUMNS / SHOW INDEX /
 *  SHOW CREATE TABLE — compatibili con QUALSIASI livello di
 *  permessi MySQL (anche utenti senza SELECT su information_schema).
 * ════════════════════════════════════════════════════════════════
 */

/**
 * Lista tabelle nel database corrente.
 * Sostituzione di: SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=...
 */
function db_tables(PDO $pdo, string $db = ''): array
{
    $rows = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
    return array_map(fn($r) => $r[0], $rows);
}

/**
 * Verifica se una tabella esiste.
 */
function db_table_exists(PDO $pdo, string $table): bool
{
    $stm = $pdo->prepare("SHOW TABLES LIKE ?");
    $stm->execute([$table]);
    return (bool)$stm->fetch();
}

/**
 * Lista colonne di una tabella.
 * Sostituzione di: SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME=...
 */
function db_columns(PDO $pdo, string $db, string $table): array
{
    try {
        $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => $r['Field'], $rows);
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * Verifica se una colonna esiste in una tabella.
 * Sostituzione di: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE COLUMN_NAME=...
 */
function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stm = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stm->execute([$column]);
        return (bool)$stm->fetch();
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Dettaglio completo colonne (Field, Type, Null, Key, Default, Extra).
 */
function db_columns_detail(PDO $pdo, string $table): array
{
    try {
        return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * Lista nomi indici di una tabella.
 * Sostituzione di: SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_NAME=...
 */
function db_indexes(PDO $pdo, string $db, string $table): array
{
    try {
        $rows = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_unique(array_map(fn($r) => $r['Key_name'], $rows)));
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * Verifica se un indice esiste.
 */
function db_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    return in_array($indexName, db_indexes($pdo, '', $table));
}

/**
 * Lista foreign keys di tutte le tabelle (tramite SHOW CREATE TABLE).
 * Sostituzione di: SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
 *                  WHERE CONSTRAINT_TYPE='FOREIGN KEY'
 * Ritorna: [constraint_name => table_name, ...]
 */
function db_fks(PDO $pdo, string $db = ''): array
{
    $fks = [];
    $tables = db_tables($pdo);
    foreach ($tables as $table) {
        $tableFks = db_table_fks($pdo, $table);
        foreach ($tableFks as $fkName => $info) {
            $fks[$fkName] = $table;
        }
    }
    return $fks;
}

/**
 * FK di una singola tabella (parse di SHOW CREATE TABLE).
 * Ritorna: [constraint_name => ['column'=>..., 'ref_table'=>..., 'ref_column'=>...], ...]
 */
function db_table_fks(PDO $pdo, string $table): array
{
    $fks = [];
    try {
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        if (!$row || empty($row[1])) return $fks;

        $createSql = $row[1];
        // Pattern: CONSTRAINT `name` FOREIGN KEY (`col`) REFERENCES `ref_table` (`ref_col`)
        preg_match_all(
            '/CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s+\(`([^`]+)`\)\s+REFERENCES\s+`([^`]+)`\s+\(`([^`]+)`\)/i',
            $createSql, $matches, PREG_SET_ORDER
        );
        foreach ($matches as $m) {
            $fks[$m[1]] = [
                'column'     => $m[2],
                'ref_table'  => $m[3],
                'ref_column' => $m[4],
            ];
        }
    } catch (\PDOException $e) {
        // Tabella potrebbe non esistere
    }
    return $fks;
}

/**
 * FK che vincolano una specifica colonna di una tabella.
 * Sostituzione di: SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
 *                  WHERE TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL
 * Ritorna: [constraint_name, ...]
 */
function db_column_fks(PDO $pdo, string $table, string $column): array
{
    $fks = db_table_fks($pdo, $table);
    $result = [];
    foreach ($fks as $name => $info) {
        if ($info['column'] === $column) {
            $result[] = $name;
        }
    }
    return $result;
}

/**
 * Conta le tabelle nel database corrente.
 * Sostituzione di: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
 */
function db_table_count(PDO $pdo): int
{
    return count(db_tables($pdo));
}

/**
 * Verifica rapidamente se una tabella ha una colonna specifica
 * provando un SELECT limitato (fallback universale).
 * Utile quando neanche SHOW COLUMNS è disponibile.
 */
function db_has_column_safe(PDO $pdo, string $table, string $column): bool
{
    try {
        $pdo->query("SELECT `$column` FROM `$table` LIMIT 0");
        return true;
    } catch (\PDOException $e) {
        return false;
    }
}
