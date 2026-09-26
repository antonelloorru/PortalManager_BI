<?php
/**
 * PmReportLink — collegamento rapportino → attività DGB (v1.9.79).
 *
 * Le viste della Relazione IT, del Service Desk e dei costi ricavano MODALITÀ
 * (in sede / da remoto / presso cliente / smart working / reperibilità) e FASCIA
 * ORARIA dall'attività DGB agganciata con `cm_intervention_reports.dgb_activity_id`.
 *
 * Il dataset «Rapporti di intervento» della sincronizzazione dal gestionale non
 * valorizzava quella colonna: dal luglio 2026 i rapportini arrivano da lì e
 * restavano scollegati (settembre: 1.379 su 1.380). Senza attività la vista li
 * classificava tutti «in sede» / «non rilevata»: filtrando Smart working o
 * Reperibilità non restava nulla.
 *
 * La chiave affidabile è `dgb_source_id`, che in entrambe le origini (sync dal
 * gestionale e DgbSync) è l'id dell'ALLOCAZIONE (`dgb_forms_activity_operator.id`):
 * individua attività e tecnico. In subordine il codice modulo (`report_code` =
 * `dgb_forms_activity.code`), solo dove il codice è univoco.
 *
 * Idempotente: tocca solo le righe ancora senza collegamento.
 */

declare(strict_types=1);

final class PmReportLink
{
    /** @return array{by_allocation:int, by_code:int, unlinked:int} */
    public static function relink(PDO $pdo): array
    {
        $out = ['by_allocation' => 0, 'by_code' => 0, 'unlinked' => 0];
        try {
            $out['by_allocation'] = (int)$pdo->exec(
                "UPDATE `cm_intervention_reports` r
                   JOIN `dgb_forms_activity_operator` ao ON ao.`id` = r.`dgb_source_id`
                   JOIN `dgb_forms_activity` a ON a.`id` = ao.`id_activity`
                    SET r.`dgb_activity_id` = a.`id`, r.`dgb_activity_code` = a.`code`
                  WHERE r.`dgb_activity_id` IS NULL AND r.`dgb_source_id` IS NOT NULL
                    AND a.`code` = r.`report_code`");
            $out['by_code'] = (int)$pdo->exec(
                "UPDATE `cm_intervention_reports` r
                   JOIN (SELECT MIN(`id`) AS `id`, `code` FROM `dgb_forms_activity`
                          WHERE `code` IS NOT NULL AND `code` <> '' AND COALESCE(`deleted`, 0) = 0
                          GROUP BY `code` HAVING COUNT(*) = 1) a ON a.`code` = r.`report_code`
                    SET r.`dgb_activity_id` = a.`id`, r.`dgb_activity_code` = a.`code`
                  WHERE r.`dgb_activity_id` IS NULL");
            $out['unlinked'] = self::unlinked($pdo);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    /** Rapportini senza attività DGB (nel periodo, se indicato). */
    public static function unlinked(PDO $pdo, ?string $from = null, ?string $to = null): int
    {
        try {
            if ($from !== null && $to !== null) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `cm_intervention_reports`
                                      WHERE `dgb_activity_id` IS NULL AND `report_date` BETWEEN ? AND ?");
                $st->execute([$from, $to]);
                return (int)$st->fetchColumn();
            }
            return (int)$pdo->query("SELECT COUNT(*) FROM `cm_intervention_reports` WHERE `dgb_activity_id` IS NULL")->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
}
