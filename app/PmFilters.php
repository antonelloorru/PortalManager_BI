<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.30 — Helper filtri multi-value + label anagrafiche.
 *
 * Uso tipico nei report:
 *   [$sql, $bind] = PmFilters::inClause('ao.id_operator', $_GET['operator'] ?? []);
 *   $wheres[] = $sql;  // 'ao.id_operator IN (?, ?, ?)'  oppure ''
 *   $binds    = array_merge($binds, $bind);
 */
final class PmFilters
{
    /**
     * Sanitizza input scalare o array in un array di interi > 0.
     * Accetta: null, '', '5', '1,2,3', [1,2,'3'].
     * @return int[]
     */
    public static function ints($input): array
    {
        if ($input === null || $input === '' || $input === 0 || $input === '0') return [];
        if (!is_array($input)) $input = preg_split('/[,\s]+/', (string)$input, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($input as $v) {
            $n = (int)$v;
            if ($n > 0) $out[$n] = true;
        }
        return array_keys($out);
    }

    /**
     * Costruisce clausola SQL "col IN (?, ?, ?)" per array di interi.
     * Se l'array è vuoto ritorna ['', []] → il chiamante ignora il pezzo.
     * @return array{0:string,1:int[]}
     */
    public static function inClause(string $column, $input): array
    {
        $ids = self::ints($input);
        if (!$ids) return ['', []];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return [$column . ' IN (' . $ph . ')', $ids];
    }

    /** "Cognome Nome" per label anagrafiche (regola v1.9.30). */
    public static function person(?string $first, ?string $last): string
    {
        $f = trim((string)$first);
        $l = trim((string)$last);
        if ($l === '' && $f === '') return '—';
        return trim($l . ' ' . $f);
    }

    /**
     * Snippet SQL equivalente (usabile nelle query):
     *   PmFilters::personSql('op.first_name', 'op.second_name') → "TRIM(CONCAT_WS(' ', op.second_name, op.first_name))"
     */
    public static function personSql(string $firstCol, string $lastCol): string
    {
        return "TRIM(CONCAT_WS(' ', $lastCol, $firstCol))";
    }
}
