<?php
/**
 * PortalManager — app/PmOrario.php  (v1.9.75)
 *
 * Regola UNICA di ripartizione delle ore fra orario ordinario e fuori orario,
 * usata da Attività & Rendicontazione DGB, Relazione di Servizio IT e Service Desk.
 *
 * Ore ordinarie di un intervento = sovrapposizione REALE, giorno per giorno, fra
 * [inizio, fine] e le fasce ordinarie dei giorni lun–ven (default 09:00–13:00 e
 * 14:00–18:00), limitata alle ore dichiarate. Fuori orario = ore dichiarate − ordinarie.
 *
 * Casi particolari (compatibili con la regola precedente):
 *  - nessun orario di inizio → ore interamente ordinarie;
 *  - fine assente o non successiva all'inizio → decide l'ora di inizio (tutto o niente);
 *  - intervalli oltre 3 giorni di calendario: conta la sovrapposizione dei primi 3.
 *
 * Perché sovrapposizione in ORE e non frazione dell'intervallo: il gestionale dichiara
 * ore nette (es. turno 12:00–00:00 = 12 h di intervallo, 11 h dichiarate con la pausa).
 * Con la frazione 5/12 × 11 = 4,58 h; con la sovrapposizione 5 h ordinarie e 6 h fuori
 * orario, pari alle 6 h extra dichiarate.
 *
 * Fasce configurabili: app_settings.pm_orario_fasce = "09:00-13:00,14:00-18:00".
 */
declare(strict_types=1);

final class PmOrario
{
    public const DEFAULT_FASCE = '09:00-13:00,14:00-18:00';
    private const MAX_GIORNI   = 3;
    private static ?array $fasce = null;

    /** Fasce ordinarie [[hh:mm:ss, hh:mm:ss], …] da app_settings (o predefinite). */
    public static function fasce(?PDO $pdo = null): array
    {
        if (self::$fasce !== null) return self::$fasce;
        $raw = self::DEFAULT_FASCE;
        if ($pdo) {
            try {
                $v = $pdo->query("SELECT `setting_value` FROM `app_settings` WHERE `setting_key` = 'pm_orario_fasce'")->fetchColumn();
                if (is_string($v) && trim($v) !== '') $raw = $v;
            } catch (Throwable $e) {}
        }
        $out = [];
        foreach (explode(',', $raw) as $p) {
            if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $p, $m)) {
                $a = sprintf('%02d:%02d:00', $m[1], $m[2]); $b = sprintf('%02d:%02d:00', $m[3], $m[4]);
                if ($a < $b) $out[] = [$a, $b];
            }
        }
        return self::$fasce = $out ?: [['09:00:00', '13:00:00'], ['14:00:00', '18:00:00']];
    }

    /** Solo per i test: forza le fasce. */
    public static function setFasce(?array $f): void { self::$fasce = $f; }

    /**
     * Espressione SQL delle ORE ORDINARIE di una riga.
     * @param string $s espressione DATETIME di inizio   (es. "a.`date_start`")
     * @param string $e espressione DATETIME di fine     (es. "a.`date_dead_line`")
     * @param string $h espressione delle ore dichiarate (es. "ao.`hours`")
     */
    public static function ordinarieSql(string $s, string $e, string $h, ?PDO $pdo = null): string
    {
        $f = self::fasce($pdo);
        // inizio dentro una fascia di un giorno feriale (per i casi senza fine valida)
        $startIn = [];
        foreach ($f as [$a, $b]) $startIn[] = "(TIME($s) >= '$a' AND TIME($s) < '$b')";
        $startInSql = "(WEEKDAY($s) < 5 AND (" . implode(' OR ', $startIn) . "))";

        // sovrapposizione in secondi, giorno per giorno
        $terms = [];
        for ($k = 0; $k < self::MAX_GIORNI; $k++) {
            $d = "(DATE($s) + INTERVAL $k DAY)";
            $parts = [];
            foreach ($f as [$a, $b]) {
                $parts[] = "GREATEST(0, TIMESTAMPDIFF(SECOND, GREATEST($s, TIMESTAMP($d, '$a')), LEAST($e, TIMESTAMP($d, '$b'))))";
            }
            $terms[] = "IF(WEEKDAY($d) < 5, " . implode(' + ', $parts) . ", 0)";
        }
        $overlapH = "((" . implode(' + ', $terms) . ") / 3600)";

        return "(CASE
            WHEN $h IS NULL OR $h <= 0 THEN 0
            WHEN $s IS NULL THEN $h
            WHEN $e IS NULL OR $e <= $s THEN CASE WHEN $startInSql THEN $h ELSE 0 END
            ELSE LEAST($h, $overlapH)
        END)";
    }

    /**
     * Stessa regola in PHP (verifiche e test). Restituisce le ore ordinarie.
     */
    public static function ordinarie(?string $start, ?string $end, float $hours, ?array $fasce = null): float
    {
        $f = $fasce ?? self::fasce();
        if ($hours <= 0) return 0.0;
        if (!$start) return $hours;
        $ts = strtotime($start); $te = $end ? strtotime($end) : false;
        $inStart = static function (int $t) use ($f): bool {
            if ((int)date('N', $t) >= 6) return false;
            $h = date('H:i:s', $t);
            foreach ($f as [$a, $b]) if ($h >= $a && $h < $b) return true;
            return false;
        };
        if ($te === false || $te <= $ts) return $inStart($ts) ? $hours : 0.0;
        $sec = 0;
        for ($k = 0; $k < self::MAX_GIORNI; $k++) {
            $day = strtotime(date('Y-m-d', $ts) . " +$k day");
            if ((int)date('N', $day) >= 6) continue;
            foreach ($f as [$a, $b]) {
                $wa = strtotime(date('Y-m-d', $day) . ' ' . $a); $wb = strtotime(date('Y-m-d', $day) . ' ' . $b);
                $sec += max(0, min($te, $wb) - max($ts, $wa));
            }
        }
        return min($hours, $sec / 3600);
    }
}
