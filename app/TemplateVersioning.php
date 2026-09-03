<?php
/**
 * certV 5.00.00 — app/TemplateVersioning.php
 *
 * Gestione versionamento dei template di posizione.
 *
 * Modello:
 *   - Ogni template ha una "famiglia" identificata da (template_type, name)
 *   - Una sola versione per famiglia ha is_current=1
 *   - Tutte le versioni precedenti hanno is_current=0 e superseded_at/by valorizzati
 *   - Ogni modifica/eliminazione crea automaticamente una riga in
 *     position_templates_history per audit + restore
 *
 * Operazioni esposte:
 *   - createVersion()   : crea o aggiorna un template (nuova versione se esiste)
 *   - listVersions()    : tutte le versioni di un template
 *   - getCurrent()      : versione corrente (per uso runtime)
 *   - softDelete()      : disattiva (is_active=0) ma mantiene per audit
 *   - restore()         : riattiva una versione precedente come corrente
 */

final class TemplateVersioning
{
    /**
     * Crea o aggiorna un template.
     *
     * Se esiste già una versione corrente con stesso (template_type, name):
     *   1. Marca la corrente come superata (is_current=0, superseded_at, superseded_by)
     *   2. Inserisce nuova riga con version+1 e is_current=1
     *   3. Logga l'operazione in history
     *
     * Se NON esiste, crea version=1.
     */
    public static function createVersion(
        PDO $pdo,
        string $templateType,
        string $name,
        string $content,
        int $userId,
        ?string $notes = null
    ): int {
        $pdo->beginTransaction();
        try {
            // Trova la versione corrente, se esiste
            $stmt = $pdo->prepare(
                "SELECT id, version FROM position_templates
                  WHERE template_type = ? AND name = ? AND is_current = 1
                  LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$templateType, $name]);
            $current = $stmt->fetch();

            $newVersion = 1;
            $oldId = null;

            if ($current) {
                $newVersion = (int)$current['version'] + 1;
                $oldId = (int)$current['id'];
            }

            // Inserisci nuova versione
            $ins = $pdo->prepare(
                "INSERT INTO position_templates
                    (template_type, name, version, content, is_active, is_current, notes, created_by, created_at)
                 VALUES (?, ?, ?, ?, 1, 1, ?, ?, NOW())"
            );
            $ins->execute([$templateType, $name, $newVersion, $content, $notes, $userId]);
            $newId = (int)$pdo->lastInsertId();

            // Marca la vecchia come superata
            if ($oldId) {
                $pdo->prepare(
                    "UPDATE position_templates
                        SET is_current = 0,
                            superseded_at = NOW(),
                            superseded_by = ?
                      WHERE id = ?"
                )->execute([$newId, $oldId]);
            }

            // Audit nello storico
            $pdo->prepare(
                "INSERT INTO position_templates_history
                    (template_id, template_type, name, version, content, notes, action, changed_by, changed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $newId,
                $templateType,
                $name,
                $newVersion,
                $content,
                $notes,
                $oldId ? 'update' : 'create',
                $userId,
            ]);

            $pdo->commit();

            if (function_exists('write_log')) {
                write_log('Recruiting', 'info',
                    ($oldId ? 'Aggiornato' : 'Creato') . " template '$name' v$newVersion",
                    $userId);
            }

            return $newId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Tutte le versioni di un template (ordinate per version DESC = più recente prima).
     */
    public static function listVersions(PDO $pdo, string $templateType, string $name): array
    {
        $stmt = $pdo->prepare(
            "SELECT t.*,
                    CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS created_by_name
              FROM position_templates t
              LEFT JOIN users u ON u.id = t.created_by
              LEFT JOIN employees e ON e.id = u.employee_id
             WHERE t.template_type = ? AND t.name = ?
             ORDER BY t.version DESC"
        );
        $stmt->execute([$templateType, $name]);
        return $stmt->fetchAll();
    }

    /**
     * Versione corrente di un template (per uso a runtime).
     */
    public static function getCurrent(PDO $pdo, string $templateType, string $name): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM position_templates
              WHERE template_type = ? AND name = ? AND is_current = 1 AND is_active = 1
              LIMIT 1"
        );
        $stmt->execute([$templateType, $name]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lista tutte le famiglie di template (uniche per type+name) con la versione corrente.
     */
    public static function listAllCurrent(PDO $pdo, ?string $templateType = null): array
    {
        $where = "WHERE is_current = 1 AND is_active = 1";
        $params = [];
        if ($templateType !== null) {
            $where .= " AND template_type = ?";
            $params[] = $templateType;
        }
        $stmt = $pdo->prepare(
            "SELECT * FROM position_templates $where
             ORDER BY template_type, name"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Soft delete: disattiva un template (is_active=0) ma lo mantiene per audit.
     */
    public static function softDelete(PDO $pdo, int $templateId, int $userId): bool
    {
        $stmt = $pdo->prepare("SELECT * FROM position_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) return false;

        $pdo->prepare("UPDATE position_templates SET is_active = 0 WHERE id = ?")
            ->execute([$templateId]);

        // Audit
        $pdo->prepare(
            "INSERT INTO position_templates_history
                (template_id, template_type, name, version, content, notes, action, changed_by, changed_at)
             VALUES (?, ?, ?, ?, ?, ?, 'delete', ?, NOW())"
        )->execute([
            $templateId,
            $tpl['template_type'],
            $tpl['name'],
            $tpl['version'],
            $tpl['content'],
            'Soft delete',
            $userId,
        ]);

        if (function_exists('write_log')) {
            write_log('Recruiting', 'warning',
                "Disattivato template '{$tpl['name']}' v{$tpl['version']}",
                $userId);
        }
        return true;
    }

    /**
     * Ripristina una versione precedente come corrente.
     * Crea una nuova versione (con numero progressivo) avente lo stesso content.
     */
    public static function restore(PDO $pdo, int $oldVersionId, int $userId): ?int
    {
        $stmt = $pdo->prepare("SELECT * FROM position_templates WHERE id = ?");
        $stmt->execute([$oldVersionId]);
        $old = $stmt->fetch();
        if (!$old) return null;

        $newId = self::createVersion(
            $pdo,
            $old['template_type'],
            $old['name'],
            $old['content'],
            $userId,
            "Ripristinato da v{$old['version']}"
        );

        // Aggiorna l'audit log per indicare 'restore' invece di 'update'
        $pdo->prepare(
            "UPDATE position_templates_history
                SET action = 'restore'
              WHERE template_id = ? AND changed_by = ?
              ORDER BY id DESC LIMIT 1"
        )->execute([$newId, $userId]);

        return $newId;
    }

    /**
     * Conta le versioni di un template.
     */
    public static function countVersions(PDO $pdo, string $templateType, string $name): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM position_templates
              WHERE template_type = ? AND name = ?"
        );
        $stmt->execute([$templateType, $name]);
        return (int)$stmt->fetchColumn();
    }
}
