<?php
/**
 * PortalManager — app/PmCharts.php  (v1.9.73)
 *
 * Grafici SVG lato server, senza librerie: stesso aspetto a schermo e in stampa/PDF.
 *
 *   $giorni = PmCharts::fillDays($righe, '2026-06-01', '2026-08-31', 'giorno', ['ore_ord', 'ore_rep']);
 *   echo PmCharts::dailyStacked($giorni, [
 *       ['key' => 'ore_ord', 'label' => 'Ore ordinarie', 'color' => '#2563eb'],
 *       ['key' => 'ore_rep', 'label' => 'Reperibilità',  'color' => '#7c3aed'],
 *   ], ['unit' => 'h', 'target' => 7.5, 'targetLabel' => 'media giornaliera']);
 */
declare(strict_types=1);

final class PmCharts
{
    /**
     * Serie giornaliera continua fra $from e $to (estremi compresi): i giorni senza
     * righe valgono 0, così l'asse del tempo non salta i giorni senza attività.
     * @return array<int,array<string,mixed>> ogni elemento ha 'd' (Y-m-d) e i campi richiesti
     */
    public static function fillDays(array $rows, string $from, string $to, string $dayKey, array $fields): array
    {
        $idx = [];
        foreach ($rows as $r) {
            $d = substr((string)($r[$dayKey] ?? ''), 0, 10);
            if ($d !== '') $idx[$d] = $r;
        }
        $out = [];
        try {
            $cur = new DateTimeImmutable($from);
            $end = new DateTimeImmutable($to);
        } catch (Throwable $e) { return []; }
        for ($n = 0; $cur <= $end && $n < 400; $cur = $cur->modify('+1 day'), $n++) {
            $d = $cur->format('Y-m-d');
            $row = ['d' => $d];
            foreach ($fields as $k) $row[$k] = isset($idx[$d][$k]) ? (float)$idx[$d][$k] : 0.0;
            $out[] = $row;
        }
        return $out;
    }

    /** Finestra degli ultimi $max giorni del periodo [from, to]. @return array{0:string,1:string} */
    public static function window(string $from, string $to, int $max = 92): array
    {
        try {
            $a = new DateTimeImmutable($from); $b = new DateTimeImmutable($to);
            $start = $b->modify('-' . ($max - 1) . ' day');
            return [($start > $a ? $start : $a)->format('Y-m-d'), $b->format('Y-m-d')];
        } catch (Throwable $e) { return [$from, $to]; }
    }

    /**
     * Barre giornaliere impilate.
     * @param array $days    output di fillDays()
     * @param array $series  [['key','label','color'], …] dal basso verso l'alto
     * @param array $opts    unit, target (float), targetLabel, height, decimals, holidays (Y-m-d[])
     */
    public static function dailyStacked(array $days, array $series, array $opts = []): string
    {
        if (!$days) return '<p style="color:#94a3b8;font-size:12px;margin:0">Nessun dato nel periodo.</p>';
        $h   = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $dec = (int)($opts['decimals'] ?? 1);
        $num = static fn($v) => number_format((float)$v, $dec, ',', '.');
        $unit = (string)($opts['unit'] ?? '');
        $target = isset($opts['target']) && (float)$opts['target'] > 0 ? (float)$opts['target'] : null;
        $holidays = array_flip($opts['holidays'] ?? []);

        $W = 900; $H = (int)($opts['height'] ?? 220);
        $pL = 46; $pR = 14; $pT = 12; $pB = 34;
        $pw = $W - $pL - $pR; $ph = $H - $pT - $pB;
        $n  = count($days);
        $bw = $pw / $n;
        $gap = $bw > 6 ? 1.2 : ($bw > 3 ? 0.6 : 0);

        $max = 0.0;
        foreach ($days as $d) { $s = 0.0; foreach ($series as $sr) $s += (float)$d[$sr['key']]; $max = max($max, $s); }
        if ($target !== null) $max = max($max, $target);
        $max = self::niceMax($max ?: 1);
        $y = static fn($v) => $pT + $ph - ($v / $max) * $ph;

        $o = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;min-width:600px;height:auto;font-family:inherit" role="img">';
        // fine settimana e festivi: fascia di sfondo
        foreach ($days as $i => $d) {
            $dow = (int)date('N', strtotime($d['d']));
            if ($dow >= 6 || isset($holidays[$d['d']])) {
                $o .= '<rect x="' . round($pL + $i * $bw, 2) . '" y="' . $pT . '" width="' . round($bw, 2) . '" height="' . $ph . '" fill="#f1f5f9"/>';
            }
        }
        // griglia e scala
        for ($g = 0; $g <= 4; $g++) {
            $v = $max * $g / 4; $yy = round($y($v), 1);
            $o .= '<line x1="' . $pL . '" y1="' . $yy . '" x2="' . ($W - $pR) . '" y2="' . $yy . '" stroke="#e2e8f0" stroke-width="1"/>'
                . '<text x="' . ($pL - 6) . '" y="' . ($yy + 3) . '" text-anchor="end" font-size="9" fill="#94a3b8">' . $h(self::short($v)) . '</text>';
        }
        // barre impilate
        foreach ($days as $i => $d) {
            $x = $pL + $i * $bw + $gap / 2; $w = max(0.8, $bw - $gap);
            $base = 0.0; $tip = date('d/m/Y', strtotime($d['d'])) . ' (' . self::dow($d['d']) . ')';
            $tot = 0.0;
            foreach ($series as $sr) {
                $v = (float)$d[$sr['key']];
                $tot += $v;
                if ($v <= 0) continue;
                $y1 = $y($base + $v); $y0 = $y($base);
                $o .= '<rect x="' . round($x, 2) . '" y="' . round($y1, 2) . '" width="' . round($w, 2) . '" height="' . round(max(0.5, $y0 - $y1), 2)
                    . '" fill="' . $h($sr['color']) . '"><title>' . $h($tip . ' — ' . $sr['label'] . ': ' . $num($v) . ($unit ? " $unit" : '')) . '</title></rect>';
                $base += $v;
            }
            if ($tot <= 0) {   // giorno senza attività: tooltip comunque disponibile
                $o .= '<rect x="' . round($x, 2) . '" y="' . $pT . '" width="' . round($w, 2) . '" height="' . $ph . '" fill="transparent"><title>'
                    . $h($tip . ' — nessuna attività') . '</title></rect>';
            }
        }
        // linea obiettivo
        if ($target !== null) {
            $yt = round($y($target), 1);
            $lbl = ($opts['targetLabel'] ?? 'obiettivo') . ' ' . $num($target) . ($unit ? " $unit" : '');
            $lw  = mb_strlen($lbl) * 5.2 + 8;                       // larghezza stimata a 9px
            $o .= '<line x1="' . $pL . '" y1="' . $yt . '" x2="' . ($W - $pR) . '" y2="' . $yt . '" stroke="#16a34a" stroke-width="1.5" stroke-dasharray="6 3"/>'
                . '<rect x="' . round($W - $pR - $lw, 1) . '" y="' . round($yt - 13, 1) . '" width="' . round($lw, 1) . '" height="12" rx="2" fill="#ffffff" fill-opacity="0.9"/>'
                . '<text x="' . ($W - $pR - 4) . '" y="' . ($yt - 4) . '" text-anchor="end" font-size="9" font-weight="600" fill="#16a34a">'
                . $h($lbl) . '</text>';
        }
        // etichette asse: al massimo ~16, sempre sul primo giorno del mese
        $every = max(1, (int)ceil($n / 16));
        $firsts = [];
        foreach ($days as $i => $d) if (substr($d['d'], 8, 2) === '01') $firsts[] = $i;
        $near = static function (int $i) use ($firsts, $every): bool {   // tacca troppo vicina a un inizio mese
            foreach ($firsts as $f) if ($f !== $i && abs($f - $i) < max(2, (int)ceil($every * 0.75))) return true;
            return false;
        };
        foreach ($days as $i => $d) {
            $first = in_array($i, $firsts, true);
            if (!$first && ($i % $every !== 0 || $near($i))) continue;
            $xc = round($pL + ($i + 0.5) * $bw, 1);
            $o .= '<text x="' . $xc . '" y="' . ($H - 18) . '" text-anchor="middle" font-size="9" fill="' . ($first ? '#334155' : '#94a3b8') . '"'
                . ($first ? ' font-weight="700"' : '') . '>' . $h(date('d/m', strtotime($d['d']))) . '</text>';
        }
        $o .= '<text x="' . $pL . '" y="' . ($H - 4) . '" font-size="9" fill="#94a3b8">' . $h(date('d/m/Y', strtotime($days[0]['d']))
            . ' – ' . date('d/m/Y', strtotime($days[$n - 1]['d'])) . ' · ' . $n . ' giorni') . '</text>';
        $o .= '</svg>';

        // legenda
        $o .= '<div style="display:flex;flex-wrap:wrap;gap:14px;font-size:11px;color:#64748b;margin-top:4px">';
        foreach ($series as $sr) {
            $o .= '<span><span style="display:inline-block;width:12px;height:9px;background:' . $h($sr['color']) . ';vertical-align:middle"></span> ' . $h($sr['label']) . '</span>';
        }
        if ($target !== null) {
            $o .= '<span><span style="display:inline-block;width:14px;border-top:2px dashed #16a34a;vertical-align:middle"></span> ' . $h($opts['targetLabel'] ?? 'obiettivo') . '</span>';
        }
        $o .= '<span><span style="display:inline-block;width:12px;height:9px;background:#f1f5f9;border:1px solid #e2e8f0;vertical-align:middle"></span> sabato e domenica</span>';
        return $o . '</div>';
    }

    /**
     * v1.10.02 — barre raggruppate per categoria (es. canone vs costo per anno, metriche per calc run).
     * @param string[] $labels  etichette delle categorie (asse X)
     * @param array    $series  [['label'=>, 'color'=>, 'values'=>[float…]]] un valore per categoria
     * @param array    $opts    unit, decimals, height, divisor (es. 1000 per k€)
     */
    public static function groupedBars(array $labels, array $series, array $opts = []): string
    {
        if (!$labels || !$series) return '<p style="color:#94a3b8;font-size:12px;margin:0">Nessun dato.</p>';
        $h   = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $div = (float)($opts['divisor'] ?? 1) ?: 1.0;
        $dec = (int)($opts['decimals'] ?? 0);
        $unit = (string)($opts['unit'] ?? '');
        $num = static fn($v) => number_format($v, $dec, ',', '.');
        $W = 900; $H = (int)($opts['height'] ?? 220);
        $pL = 56; $pR = 14; $pT = 16; $pB = 30;
        $pw = $W - $pL - $pR; $ph = $H - $pT - $pB;
        $max = 0.0; $min = 0.0;
        foreach ($series as $sr) foreach ($sr['values'] as $v) { $max = max($max, (float)$v / $div); $min = min($min, (float)$v / $div); }
        $max = self::niceMax($max ?: 1);
        $min = $min < 0 ? -self::niceMax(-$min) : 0.0;
        $span = $max - $min ?: 1;
        $y = static fn($v) => $pT + $ph - (($v - $min) / $span) * $ph;
        $n = count($labels); $k = count($series);
        $gw = $pw / $n; $bw = max(2, ($gw * 0.72) / $k);
        $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" role="img" style="font-family:inherit">';
        for ($i = 0; $i <= 4; $i++) {
            $v = $min + $span * $i / 4; $yy = round($y($v), 1);
            $o .= '<line x1="' . $pL . '" x2="' . ($W - $pR) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e2e8f0"/>'
                . '<text x="' . ($pL - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" font-size="10" fill="#64748b">' . $h(number_format($v, abs($v) < 10 && floor($v) != $v ? 1 : 0, ',', '.')) . '</text>';
        }
        $y0 = round($y(0), 1);
        $o .= '<line x1="' . $pL . '" x2="' . ($W - $pR) . '" y1="' . $y0 . '" y2="' . $y0 . '" stroke="#94a3b8"/>';
        foreach ($labels as $i => $lab) {
            $x0 = $pL + $gw * $i + ($gw - $bw * $k) / 2;
            foreach ($series as $j => $sr) {
                $v = (float)($sr['values'][$i] ?? 0) / $div;
                $yv = $y($v); $top = min($yv, $y0); $hh = max(0.5, abs($y0 - $yv));
                $o .= '<rect x="' . round($x0 + $bw * $j, 1) . '" y="' . round($top, 1) . '" width="' . round($bw - 1, 1) . '" height="' . round($hh, 1) . '" fill="' . $h($sr['color']) . '">'
                    . '<title>' . $h($lab . ' · ' . $sr['label'] . ': ' . $num($v) . ($unit !== '' ? ' ' . $unit : '')) . '</title></rect>';
            }
            $o .= '<text x="' . round($pL + $gw * $i + $gw / 2, 1) . '" y="' . ($H - 10) . '" text-anchor="middle" font-size="11" fill="#475569">' . $h($lab) . '</text>';
        }
        $o .= '</svg><div style="display:flex;flex-wrap:wrap;gap:14px;font-size:11px;color:#64748b;margin-top:4px">';
        foreach ($series as $sr) $o .= '<span><span style="display:inline-block;width:12px;height:9px;background:' . $h($sr['color']) . ';vertical-align:middle"></span> ' . $h($sr['label']) . '</span>';
        return $o . ($unit !== '' ? '<span>valori in ' . $h($unit) . '</span>' : '') . '</div>';
    }

    private static function niceMax(float $v): float
    {
        $p = pow(10, floor(log10($v)));
        foreach ([1, 2, 2.5, 5, 10] as $m) if ($m * $p >= $v) return $m * $p;
        return 10 * $p;
    }

    private static function short(float $v): string
    {
        return $v >= 1000 ? number_format($v / 1000, $v >= 10000 ? 0 : 1, ',', '.') . 'k'
                          : number_format($v, ($v > 0 && $v < 10 && floor($v) != $v) ? 1 : 0, ',', '.');
    }

    private static function dow(string $d): string
    {
        return ['', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'][(int)date('N', strtotime($d))];
    }
}
