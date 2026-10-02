<?php
/**
 * PortalManager — app/ProRata.php  (v1.9.94)
 *
 * Ripartizione pro-rata temporis MENSILE di un importo sulla durata di una commessa.
 * Funzioni pure (nessun accesso al DB): input → output, verificabili a mano.
 *
 * Regola
 *   inizio effettivo = max(data inizio commessa, data ordine)       (mese di calendario)
 *   fine             = data fine commessa                            (mese di calendario)
 *   mesi totali  N   = (anno_fine - anno_inizio) * 12 + (mese_fine - mese_inizio) + 1
 *                      (mesi interi di calendario, estremi inclusi; se fine < inizio → N = 1 nel mese di inizio)
 *   quota mensile    = importo / N
 *   competenza nel periodo [Da, A] = quota mensile × mesi di [inizio, fine] ∩ [Da, A], raggruppati per anno.
 *   Arrotondamento al centesimo per anno; il residuo di arrotondamento va all'ultimo anno della commessa,
 *   così la somma su tutta la durata è esattamente l'importo.
 *
 * Esempio: 100.000 € da giugno 2024 a settembre 2026 → N = 28, quota 3.571,43 €/mese
 *   2024: 7 mesi = 25.000,00 · 2025: 12 mesi = 42.857,14 · 2026: 9 mesi = 32.142,86 (totale 100.000,00)
 *
 * Traduzione SQL (MariaDB 10.4) della durata in mesi:
 *   PERIOD_DIFF(DATE_FORMAT(fine,'%Y%m'), DATE_FORMAT(GREATEST(inizio, data_ordine),'%Y%m')) + 1
 */
declare(strict_types=1);

final class ProRata
{
    /** Indice mese assoluto (anno*12 + mese-1) di una data Y-m-d. */
    public static function mi(string $ymd): int
    {
        return (int)substr($ymd, 0, 4) * 12 + (int)substr($ymd, 5, 2) - 1;
    }

    /** Mesi interi di calendario fra due date, estremi inclusi (minimo 1). */
    public static function mesi(string $da, string $a): int
    {
        return max(1, self::mi($a) - self::mi($da) + 1);
    }

    /**
     * Ripartizione di un importo.
     *
     * @param float       $importo
     * @param string      $inizio     data inizio commessa (Y-m-d)
     * @param string      $fine       data fine commessa (Y-m-d)
     * @param string|null $ordine     data ordine (Y-m-d): se successiva all'inizio, sposta l'inizio
     * @param string|null $pDa        inizio periodo selezionato (Y-m-d), null = nessun limite
     * @param string|null $pA         fine periodo selezionato (Y-m-d), null = nessun limite
     * @return array{inizio:string, fine:string, mesi_totali:int, quota_mensile:float,
     *               anni:array<int,array{mesi:int, da:string, a:string, valore:float}>,
     *               mesi_periodo:int, valore_periodo:float}
     */
    public static function ripartisci(float $importo, string $inizio, string $fine, ?string $ordine = null,
                                      ?string $pDa = null, ?string $pA = null): array
    {
        $s = ($ordine !== null && $ordine > $inizio) ? $ordine : $inizio;
        $sI = self::mi($s);
        $eI = self::mi($fine);
        if ($eI < $sI) $eI = $sI;                       // ordine dopo la fine: tutto nel mese dell'ordine
        $n   = $eI - $sI + 1;
        $q   = $importo / $n;

        // valore per anno sull'INTERA durata, arrotondato; residuo all'ultimo anno
        $perAnno = [];
        for ($m = $sI; $m <= $eI; $m++) {
            $y = intdiv($m, 12);
            $perAnno[$y]['mesi'] = ($perAnno[$y]['mesi'] ?? 0) + 1;
        }
        $tot = 0.0; $anni = array_keys($perAnno); $ultimo = end($anni);
        foreach ($perAnno as $y => &$r) {
            $r['valore'] = round($q * $r['mesi'], 2);
            $tot += $r['valore'];
        }
        unset($r);
        $perAnno[$ultimo]['valore'] = round($perAnno[$ultimo]['valore'] + ($importo - $tot), 2);

        // intersezione con il periodo selezionato, per anno
        $pS = $pDa !== null ? self::mi($pDa) : PHP_INT_MIN;
        $pE = $pA  !== null ? self::mi($pA)  : PHP_INT_MAX;
        $out = []; $mp = 0; $vp = 0.0;
        foreach ($perAnno as $y => $r) {
            $yS = max($sI, $y * 12, $pS);
            $yE = min($eI, $y * 12 + 11, $pE);
            if ($yE < $yS) continue;
            $k = $yE - $yS + 1;
            // anno intero nel periodo: valore dell'anno (con il residuo); anno parziale: quota × mesi
            $v = ($k === $r['mesi']) ? $r['valore'] : round($q * $k, 2);
            $out[$y] = ['mesi' => $k, 'da' => self::label($yS), 'a' => self::label($yE), 'valore' => $v];
            $mp += $k; $vp += $v;
        }
        return [
            'inizio' => sprintf('%04d-%02d-01', intdiv($sI, 12), $sI % 12 + 1),
            'fine'   => sprintf('%04d-%02d-01', intdiv($eI, 12), $eI % 12 + 1),
            'mesi_totali' => $n, 'quota_mensile' => round($q, 2),
            'anni' => $out, 'mesi_periodo' => $mp, 'valore_periodo' => round($vp, 2),
        ];
    }

    /** «06/2024» da indice mese. */
    public static function label(int $mi): string
    {
        return sprintf('%02d/%04d', $mi % 12 + 1, intdiv($mi, 12));
    }
}
