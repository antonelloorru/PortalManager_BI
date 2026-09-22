<?php
/**
 * certV 5.00.00 — app/PositionHistory.php
 *
 * Gestione storico delle posizioni:
 *   - Cambi di stato (apertura, pausa, chiusura, riapertura)
 *   - Variazioni di compenso (RAL min/max, benefits)
 *
 * Usato da recruiting_posizioni.php quando salva una posizione esistente:
 *   PositionHistory::recordIfStatusChanged($pdo, $oldData, $newData, $userId);
 *   PositionHistory::recordIfCompensationChanged($pdo, $oldData, $newData, $userId);
 */

final class PositionHistory
{
    /**
     * Registra un cambio di stato se è effettivamente cambiato.
     */
    public static function recordIfStatusChanged(PDO $pdo, array $old, array $new, int $userId, ?string $notes = null): bool
    {
        $oldStatus = $old['status'] ?? null;
        $newStatus = $new['status'] ?? null;

        if ($oldStatus === $newStatus) return false;

        // Snapshot delle date importanti al momento del cambio
        $opened = $new['opened_at'] ?? $old['opened_at'] ?? null;
        $closed = $new['closed_at'] ?? $old['closed_at'] ?? null;

        $stmt = $pdo->prepare(
            "INSERT INTO position_status_history
                (position_id, old_status, new_status, opened_at_snapshot, closed_at_snapshot, changed_by, changed_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->execute([
            (int)($new['id'] ?? $old['id']),
            $oldStatus,
            $newStatus,
            $opened ?: null,
            $closed ?: null,
            $userId,
            $notes,
        ]);

        if (function_exists('write_log')) {
            write_log('Recruiting', 'info',
                "Cambio status posizione #{$new['id']}: {$oldStatus} → {$newStatus}",
                $userId);
        }
        return true;
    }

    /**
     * Registra una variazione di compenso se almeno uno dei 3 campi è cambiato.
     */
    public static function recordIfCompensationChanged(PDO $pdo, array $old, array $new, int $userId, ?string $notes = null): bool
    {
        $changed = (
            (string)($old['ral_min']  ?? '') !== (string)($new['ral_min']  ?? '') ||
            (string)($old['ral_max']  ?? '') !== (string)($new['ral_max']  ?? '') ||
            (string)($old['benefits'] ?? '') !== (string)($new['benefits'] ?? '')
        );
        if (!$changed) return false;

        $stmt = $pdo->prepare(
            "INSERT INTO position_compensation_history
                (position_id, ral_min, ral_max, benefits, changed_by, changed_at, notes)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)"
        );
        $stmt->execute([
            (int)($new['id'] ?? $old['id']),
            $new['ral_min']  !== '' ? $new['ral_min']  : null,
            $new['ral_max']  !== '' ? $new['ral_max']  : null,
            $new['benefits'] !== '' ? $new['benefits'] : null,
            $userId,
            $notes,
        ]);

        if (function_exists('write_log')) {
            write_log('Recruiting', 'info',
                "Cambio compenso posizione #{$new['id']}",
                $userId);
        }
        return true;
    }

    /**
     * Restituisce la timeline degli stati per una posizione (ordine cronologico).
     */
    public static function getStatusTimeline(PDO $pdo, int $positionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT h.*,
                    CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS changed_by_name
              FROM position_status_history h
              LEFT JOIN users u ON u.id = h.changed_by
              LEFT JOIN employees e ON e.id = u.employee_id
             WHERE h.position_id = ?
             ORDER BY h.changed_at ASC, h.id ASC"
        );
        $stmt->execute([$positionId]);
        return $stmt->fetchAll();
    }

    /**
     * Restituisce lo storico compenso per una posizione.
     */
    public static function getCompensationTimeline(PDO $pdo, int $positionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT h.*,
                    CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS changed_by_name
              FROM position_compensation_history h
              LEFT JOIN users u ON u.id = h.changed_by
              LEFT JOIN employees e ON e.id = u.employee_id
             WHERE h.position_id = ?
             ORDER BY h.changed_at ASC, h.id ASC"
        );
        $stmt->execute([$positionId]);
        return $stmt->fetchAll();
    }

    /**
     * Calcola statistiche aggregate dalla timeline:
     *   - Numero totale di cambi
     *   - Tempo trascorso in ciascuno stato
     *   - Durata media "open" (se chiusa)
     */
    public static function computeStatistics(PDO $pdo, int $positionId): array
    {
        $timeline = self::getStatusTimeline($pdo, $positionId);
        if (empty($timeline)) return [];

        $stats = [
            'total_changes'  => count($timeline) - 1,  // -1 perché il primo è il "seed"
            'time_in_status' => [],
            'first_opened'   => null,
            'last_closed'    => null,
            'reopens_count'  => 0,
        ];

        $prev = null;
        foreach ($timeline as $row) {
            if ($prev) {
                $hours = (strtotime($row['changed_at']) - strtotime($prev['changed_at'])) / 3600;
                $st = $prev['new_status'];
                $stats['time_in_status'][$st] = ($stats['time_in_status'][$st] ?? 0) + $hours;
            }
            if ($row['new_status'] === 'open' && $row['old_status'] === 'paused') {
                $stats['reopens_count']++;
            }
            if ($row['new_status'] === 'open' && !$stats['first_opened']) {
                $stats['first_opened'] = $row['changed_at'];
            }
            if ($row['new_status'] === 'closed') {
                $stats['last_closed'] = $row['changed_at'];
            }
            $prev = $row;
        }

        return $stats;
    }
}
