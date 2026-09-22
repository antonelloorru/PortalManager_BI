<?php
/**
 * app/AliasStore.php — Alias di riconciliazione import (v1.7.67)
 *
 * Gli export dei rapporti contengono valori grezzi (codice commessa, "Cognome Nome"
 * del tecnico, nome fascia) che non sempre corrispondono a un record del portale.
 * Invece di perdere l'informazione ad ogni import, le corrispondenze decise
 * dall'operatore vengono PERSISTITE qui e riutilizzate automaticamente dai
 * successivi import e dalla riapplicazione massiva.
 *
 * `ignored = 1` marca un valore come volutamente non mappabile: resta tracciato
 * ma non compare più tra le anomalie da lavorare.
 */
final class AliasStore
{
    public const T_PROJECT    = 'project';
    public const T_TECHNICIAN = 'technician';
    public const T_BAND       = 'band';

    /** tipo => [tabella, colonna_raw, colonna_target] */
    private const MAP = [
        self::T_PROJECT    => ['cm_alias_project',    'raw_code', 'project_id'],
        self::T_TECHNICIAN => ['cm_alias_technician', 'raw_name', 'employee_id'],
        self::T_BAND       => ['cm_alias_band',       'raw_band', 'band_id'],
    ];

    private PDO $pdo;
    private array $cache = [];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public static function types(): array { return array_keys(self::MAP); }
    public static function table(string $type): string { return self::MAP[$type][0]; }
    public static function rawCol(string $type): string { return self::MAP[$type][1]; }
    public static function targetCol(string $type): string { return self::MAP[$type][2]; }

    private static function norm(string $v): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v));
        return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    }

    /** Carica (una volta) tutti gli alias di un tipo. @return array<string,?int> chiave normalizzata => id */
    private function all(string $type): array
    {
        if (isset($this->cache[$type])) return $this->cache[$type];
        [$tbl, $raw, $tgt] = self::MAP[$type];
        $out = [];
        try {
            // v1.7.89: per le fasce esistono anche alias specifici della singola commessa
            // (project_id <> 0): qui vanno considerati SOLO quelli globali.
            $where = "`ignored` = 0";
            if ($type === self::T_BAND) {
                try {
                    $hasProj = (bool)$this->pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$tbl' AND COLUMN_NAME='project_id'")->fetchColumn();
                    if ($hasProj) $where .= " AND `project_id` = 0";
                } catch (Throwable $e) {}
            }
            $st = $this->pdo->query("SELECT `$raw` AS r, `$tgt` AS t FROM `$tbl` WHERE $where");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['t'] === null) continue;
                $out[self::norm((string)$row['r'])] = (int)$row['t'];
            }
        } catch (Throwable $e) { /* tabella non ancora presente */ }
        return $this->cache[$type] = $out;
    }

    /** Risolve un valore grezzo tramite alias. NULL se non mappato. */
    public function resolve(string $type, string $rawValue): ?int
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') return null;
        return $this->all($type)[self::norm($rawValue)] ?? null;
    }

    /** Salva/aggiorna un alias. $targetId NULL + $ignored=1 => valore da ignorare. */
    public function set(string $type, string $rawValue, ?int $targetId, bool $ignored = false, ?int $userId = null): bool
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') return false;
        [$tbl, $raw, $tgt] = self::MAP[$type];
        $st = $this->pdo->prepare(
            "INSERT INTO `$tbl` (`$raw`, `$tgt`, `ignored`, `created_by`) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE `$tgt` = VALUES(`$tgt`), `ignored` = VALUES(`ignored`)"
        );
        $st->execute([$rawValue, $targetId, $ignored ? 1 : 0, $userId]);
        unset($this->cache[$type]);
        return true;
    }

    /** Elenco alias di un tipo, con etichetta del record collegato. */
    public function listAll(string $type): array
    {
        [$tbl, $raw, $tgt] = self::MAP[$type];
        switch ($type) {
            case self::T_PROJECT:
                $sql = "SELECT a.*, a.`$raw` AS raw_value, CONCAT(p.project_code,' — ',p.name) AS target_label
                        FROM `$tbl` a LEFT JOIN cm_projects p ON p.id = a.`$tgt` ORDER BY a.`$raw`";
                break;
            case self::T_TECHNICIAN:
                $sql = "SELECT a.*, a.`$raw` AS raw_value, CONCAT(e.last_name,' ',e.first_name) AS target_label
                        FROM `$tbl` a LEFT JOIN employees e ON e.id = a.`$tgt` ORDER BY a.`$raw`";
                break;
            default:
                $sql = "SELECT a.*, a.`$raw` AS raw_value, b.band_name AS target_label
                        FROM `$tbl` a LEFT JOIN cm_rate_bands b ON b.id = a.`$tgt` ORDER BY a.`$raw`";
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
