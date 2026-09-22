<?php
/**
 * certV 5.7.0 — app/EntityChangeLog.php
 *
 * Storicizzazione cross-tabella di tutti i cambi a livello di campo.
 *
 * Caso d'uso primario: import in modalità "upsert" (insert se non esiste,
 * update con storicizzazione se esiste). Per ogni record toccato,
 * registriamo i campi cambiati con vecchio/nuovo valore.
 *
 * Use case secondario: tracking cambiamenti UI manuali (CRUD operatori).
 */

final class EntityChangeLog
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Confronta vecchio vs nuovo record e registra solo i campi cambiati.
     *
     * @param string $table        Nome tabella entità
     * @param int    $entityId     ID record
     * @param array  $oldRow       Stato precedente (key→value)
     * @param array  $newRow       Stato nuovo (key→value)
     * @param string $action       insert | update | approve | reject
     * @param string $source       import | ui | api | migration | system
     * @param int|null $sourceRefId  es. import_jobs.id
     * @param int|null $userId
     * @return int Numero di campi cambiati registrati
     */
    public function diffAndLog(
        string $table,
        int $entityId,
        array $oldRow,
        array $newRow,
        string $action = 'update',
        string $source = 'ui',
        ?int $sourceRefId = null,
        ?int $userId = null,
        array $skipFields = ['updated_at','created_at','updated_by']
    ): int {
        $changed = 0;
        $stmt = $this->pdo->prepare(
            "INSERT INTO entity_change_log
              (entity_table, entity_id, field_name, old_value, new_value,
               change_action, change_source, source_ref_id, changed_by)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($newRow as $field => $newVal) {
            if (in_array($field, $skipFields, true)) continue;
            $oldVal = $oldRow[$field] ?? null;
            if ($this->valuesEqual($oldVal, $newVal)) continue;

            $stmt->execute([
                $table, $entityId, $field,
                $this->serializeValue($oldVal),
                $this->serializeValue($newVal),
                $action, $source, $sourceRefId, $userId,
            ]);
            $changed++;
        }
        return $changed;
    }

    /**
     * Log diretto di un singolo cambio campo.
     */
    public function logField(
        string $table,
        int $entityId,
        string $field,
        $oldValue,
        $newValue,
        string $action = 'update',
        string $source = 'ui',
        ?int $sourceRefId = null,
        ?int $userId = null
    ): void {
        $this->pdo->prepare(
            "INSERT INTO entity_change_log
              (entity_table, entity_id, field_name, old_value, new_value,
               change_action, change_source, source_ref_id, changed_by)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $table, $entityId, $field,
            $this->serializeValue($oldValue),
            $this->serializeValue($newValue),
            $action, $source, $sourceRefId, $userId,
        ]);
    }

    /**
     * Recupera la storia di un record.
     *
     * @return array di cambi ordinati cronologicamente
     */
    public function getHistory(string $table, int $entityId, int $limit = 200): array
    {
        $s = $this->pdo->prepare(
            "SELECT ecl.*,
                    CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS user_name
               FROM entity_change_log ecl
               LEFT JOIN users u    ON u.id = ecl.changed_by
               LEFT JOIN employees e ON e.id = u.employee_id
              WHERE ecl.entity_table = ? AND ecl.entity_id = ?
              ORDER BY ecl.changed_at DESC, ecl.id DESC
              LIMIT $limit"
        );
        $s->execute([$table, $entityId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tutti i cambi di un job di import (trail completo).
     */
    public function getByImportJob(int $jobId): array
    {
        $s = $this->pdo->prepare(
            "SELECT ecl.*,
                    CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS user_name
               FROM entity_change_log ecl
               LEFT JOIN users u    ON u.id = ecl.changed_by
               LEFT JOIN employees e ON e.id = u.employee_id
              WHERE ecl.change_source = 'import' AND ecl.source_ref_id = ?
              ORDER BY ecl.changed_at, ecl.id"
        );
        $s->execute([$jobId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function valuesEqual($a, $b): bool
    {
        // NULL == '' considerato uguale (DB caso comune)
        if (($a === null || $a === '') && ($b === null || $b === '')) return true;
        // Numerico: confronto loose
        if (is_numeric($a) && is_numeric($b)) return (float)$a === (float)$b;
        return (string)$a === (string)$b;
    }

    private function serializeValue($v): ?string
    {
        if ($v === null) return null;
        if (is_scalar($v)) return (string)$v;
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }
}
