<?php
/**
 * PortalManager — app/PmReport.php  (v1.10.12)
 *
 * Report indipendente dal formato: una sola descrizione del contenuto (titolo, filtri, KPI, sezioni,
 * tabelle, barre, note) resa in DOCX (DocxWriter), PDF (PdfWriter), XLSX (XlsxWriter: un foglio per
 * tabella + «Riepilogo») e CSV (sezioni separate, «;», UTF-8 con BOM). Le pagine Service Desk,
 * Service SOC e Attività & Rendicontazione DGB costruiscono il contenuto dal solo filtro principale.
 *
 * Report per tecnico: PmReport::bundle() produce un archivio ZIP con un report per ciascuna risorsa,
 * generato dallo stesso costruttore della pagina con il filtro principale + il tecnico.
 *
 * Valori delle tabelle: grezzi (int/float/string/null). DOCX/PDF/CSV li formattano all'italiana
 * (float con 'dec' decimali, default 1); XLSX li scrive come numeri.
 */
declare(strict_types=1);

final class PmReport
{
    public const FORMATS = ['docx' => 'DOCX', 'xlsx' => 'XLSX', 'csv' => 'CSV', 'pdf' => 'PDF'];
    private array $blocks = [];

    public function __construct(public string $title, public string $subtitle = '', public string $orientation = 'L') {}

    public function meta(string $t): self   { $this->blocks[] = ['meta', $t]; return $this; }
    public function note(string $t): self   { $this->blocks[] = ['note', $t]; return $this; }
    public function box(string $t): self    { $this->blocks[] = ['box', $t]; return $this; }
    public function heading(string $t, int $level = 1): self { $this->blocks[] = ['h', $t, $level]; return $this; }
    public function pageBreak(): self       { $this->blocks[] = ['br']; return $this; }

    /** @param array<int,array{label:string,value:string,color?:string,sub?:string}> $cards */
    public function kpi(array $cards): self { if ($cards) $this->blocks[] = ['kpi', $cards]; return $this; }

    /**
     * @param string $title  titolo della sezione (anche nome del foglio XLSX)
     * @param array{right?:int[],dec?:int|array<int,int>,total?:bool} $o  right = colonne numeriche a destra; total = ultima riga totale
     */
    public function table(string $title, array $header, array $rows, array $o = []): self
    {
        $this->blocks[] = ['table', $title, $header, array_values($rows), $o];
        return $this;
    }

    /** @param array<int,array{0:string,1:float}> $rows */
    public function bars(string $title, array $rows, string $unit = '', string $color = '2563EB'): self
    {
        if ($rows) $this->blocks[] = ['bars', $title, $rows, $unit, $color];
        return $this;
    }

    /** v1.10.13 — paragrafo con stile (size in half-point DOCX, color, bold). */
    public function para(string $t, array $o = []): self { $this->blocks[] = ['para', $t, $o]; return $this; }

    /**
     * v1.10.13 — barre impilate: righe [etichetta, [valori per segmento], testo]; segmenti [label, color].
     */
    public function stacked(string $title, array $rows, array $segs): self
    {
        if ($rows) $this->blocks[] = ['stacked', $title, $rows, $segs];
        return $this;
    }

    /** Stringa numerica all'italiana («1.234,5», «12», «-3,25») → numero; altrimenti invariata (XLSX). */
    public static function itNum($v)
    {
        if (!is_string($v)) return $v;
        $t = trim($v);
        if ($t === '' || !preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+(,\d+)?$/', $t)) return $v;
        $n = str_replace(',', '.', str_replace('.', '', $t));
        return str_contains($n, '.') ? (float)$n : (int)$n;
    }

    // ── formattazione ────────────────────────────────────────────────
    private static function fmt($v, int $dec = 1): string
    {
        if ($v === null || $v === '') return $v === null ? '—' : '';
        if (is_int($v)) return number_format($v, 0, ',', '.');
        if (is_float($v)) return number_format($v, $dec, ',', '.');
        return (string)$v;
    }

    private static function barDec(array $rows): int
    {
        foreach ($rows as $r) if (abs((float)$r[1] - round((float)$r[1])) > 0.0001) return 1;
        return 0;
    }

    private static function fmtRow(array $r, array $o): array
    {
        $out = [];
        foreach (array_values($r) as $i => $v) {
            $d = $o['dec'] ?? 1; $d = is_array($d) ? ($d[$i] ?? 1) : (int)$d;
            $out[] = self::fmt($v, $d);
        }
        return $out;
    }

    /** Colonne numeriche dedotte dalle righe, se non indicate. */
    private static function rightCols(array $header, array $rows, array $o): array
    {
        if (isset($o['right'])) return $o['right'];
        $r = [];
        for ($i = 0; $i < count($header); $i++) {
            $num = 0; $tot = 0;
            foreach (array_slice($rows, 0, 50) as $x) { $v = array_values($x)[$i] ?? null; if ($v === null || $v === '') continue; $tot++; if (is_int($v) || is_float($v)) $num++; }
            if ($tot > 0 && $num === $tot) $r[] = $i;
        }
        return $r;
    }

    // ── rendering ────────────────────────────────────────────────────
    public function toDocx(): DocxWriter
    {
        if (!class_exists('DocxWriter')) require_once __DIR__ . '/DocxWriter.php';
        $d = new DocxWriter($this->title);
        if ($this->subtitle !== '') $d->meta($this->subtitle);
        foreach ($this->blocks as $b) {
            switch ($b[0]) {
                case 'meta': $d->meta($b[1]); break;
                case 'note': $d->note($b[1]); break;
                case 'box':  $d->box($b[1]); break;
                case 'h':    $d->heading($b[1], $b[2]); break;
                case 'br':   $d->pageBreak(); break;
                case 'kpi':  $d->kpi(array_map(fn($c) => ['color' => ltrim($c['color'] ?? '334155', '#')] + $c, $b[1])); break;
                case 'para': $d->paragraph($b[1], $b[2]); break;
                case 'stacked': if ($b[1] !== '') $d->heading($b[1], 3); $d->stackedbars($b[2], array_map(fn($g) => ['label' => $g['label'], 'color' => ltrim($g['color'], '#')], $b[3])); break;
                case 'table':
                    if ($b[1] !== '' && empty($b[4]['notitle'])) $d->heading($b[1], 2);
                    if (!$b[3]) { $d->note('Nessun dato.'); break; }
                    $d->table($b[2], array_map(fn($r) => self::fmtRow($r, $b[4]), $b[3]), ['right' => self::rightCols($b[2], $b[3], $b[4])]);
                    break;
                case 'bars':
                    $dec = self::barDec($b[2]);
                    $d->bars(array_map(fn($r) => [(string)$r[0], (float)$r[1], $r[2] ?? (self::fmt((float)$r[1], $dec) . ($b[3] !== '' ? ' ' . $b[3] : ''))], $b[2]), ['title' => $b[1], 'color' => ltrim($b[4], '#')]);
                    break;
            }
        }
        return $d;
    }

    public function toPdf(): PdfWriter
    {
        require_once __DIR__ . '/PdfWriter.php';
        $p = new PdfWriter($this->title, ['orientation' => $this->orientation]);
        if ($this->subtitle !== '') $p->meta($this->subtitle);
        foreach ($this->blocks as $b) {
            switch ($b[0]) {
                case 'meta': $p->meta($b[1]); break;
                case 'note': $p->note($b[1]); break;
                case 'box':  $p->box($b[1]); break;
                case 'h':    $p->heading($b[1], $b[2]); break;
                case 'br':   $p->pageBreak(); break;
                case 'kpi':  $p->kpi($b[1]); break;
                case 'para': $p->paragraph($b[1], ['size' => isset($b[2]['size']) ? $b[2]['size'] / 2 : 9, 'bold' => !empty($b[2]['bold']), 'color' => $b[2]['color'] ?? '334155']); break;
                case 'stacked': $p->stackedbars($b[2], $b[3], ['title' => $b[1]]); break;
                case 'table':
                    if ($b[1] !== '' && empty($b[4]['notitle'])) $p->heading($b[1], 2);
                    if (!$b[3]) { $p->note('Nessun dato.'); break; }
                    $p->table($b[2], array_map(fn($r) => self::fmtRow($r, $b[4]), $b[3]), ['right' => self::rightCols($b[2], $b[3], $b[4]), 'total' => !empty($b[4]['total'])]);
                    break;
                case 'bars':
                    $dec = self::barDec($b[2]);
                    $p->bars(array_map(fn($r) => [(string)$r[0], (float)$r[1], $r[2] ?? (self::fmt((float)$r[1], $dec) . ($b[3] !== '' ? ' ' . $b[3] : ''))], $b[2]), ['title' => $b[1], 'color' => $b[4]]);
                    break;
            }
        }
        return $p;
    }

    public function toXlsx(): XlsxWriter
    {
        if (!class_exists('XlsxWriter')) require_once __DIR__ . '/XlsxWriter.php';   // root/XlsxWriter.php può essere già caricato
        $w = new XlsxWriter();
        $sum = [[$this->title], [$this->subtitle]];
        foreach ($this->blocks as $b) {
            if ($b[0] === 'meta' || $b[0] === 'box' || $b[0] === 'note' || $b[0] === 'para') $sum[] = [$b[1]];
            if ($b[0] === 'kpi') { $sum[] = []; $sum[] = ['Indicatore', 'Valore', 'Dettaglio']; foreach ($b[1] as $c) $sum[] = [$c['label'], $c['value'], $c['sub'] ?? '']; $sum[] = []; }
        }
        $w->addSheet('Riepilogo', $sum);
        $used = ['riepilogo' => 1];
        // tabelle raggruppate (opzione group = [sezione, gruppo]) con la stessa intestazione → un solo foglio
        $merged = []; $blocks = [];
        foreach ($this->blocks as $b) {
            if ($b[0] === 'table' && !empty($b[4]['group'])) {
                $key = $b[4]['group'][0] . '|' . implode('|', $b[2]);
                $rows = array_map(fn($r) => array_merge([$b[4]['group'][1]], array_values($r)), $b[3]);
                if (isset($merged[$key])) { $blocks[$merged[$key]][3] = array_merge($blocks[$merged[$key]][3], $rows); continue; }
                $merged[$key] = count($blocks);
                $blocks[] = ['table', $b[4]['group'][0], array_merge(['Gruppo'], $b[2]), $rows, ['right' => []]];
                continue;
            }
            $blocks[] = $b;
        }
        foreach ($blocks as $b) {
            if ($b[0] !== 'table' && $b[0] !== 'bars' && $b[0] !== 'stacked') continue;
            $name = trim(preg_replace('/[\\\\\/\?\*\[\]:]+/', ' ', $b[1])) ?: 'Tabella';
            $name = mb_substr($name, 0, 28); $base = $name; $k = 2;
            while (isset($used[mb_strtolower($name)])) $name = mb_substr($base, 0, 25) . ' ' . $k++;
            $used[mb_strtolower($name)] = 1;
            if ($b[0] === 'table') $w->addSheet($name, array_merge([$b[2]], array_map(fn($r) => array_map(fn($v) => $v === null ? '' : self::itNum($v), array_values($r)), $b[3])));
            elseif ($b[0] === 'stacked') $w->addSheet($name, array_merge([array_merge(['Voce'], array_column($b[3], 'label'), ['Totale'])], array_map(fn($r) => array_merge([(string)$r[0]], array_map('floatval', $r[1]), [array_sum($r[1])]), $b[2])));
            else $w->addSheet($name, array_merge([['Voce', 'Valore' . ($b[3] !== '' ? " ({$b[3]})" : '')]], array_map(fn($r) => [(string)$r[0], (float)$r[1]], $b[2])));
        }
        return $w;
    }

    public function toCsv(): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [$this->title], ';', '"');
        if ($this->subtitle !== '') fputcsv($fh, [$this->subtitle], ';', '"');
        foreach ($this->blocks as $b) {
            switch ($b[0]) {
                case 'meta': case 'box': case 'note': case 'para': fputcsv($fh, [$b[1]], ';', '"'); break;
                case 'stacked':
                    fwrite($fh, "\r\n"); fputcsv($fh, ['# ' . ($b[1] !== '' ? $b[1] : 'Grafico')], ';', '"'); fputcsv($fh, array_merge(['Voce'], array_column($b[3], 'label'), ['Totale']), ';', '"');
                    foreach ($b[2] as $r) fputcsv($fh, array_map(fn($v) => is_float($v) ? str_replace('.', ',', (string)round($v, 4)) : (string)$v, array_merge([(string)$r[0]], array_map('floatval', $r[1]), [(float)array_sum($r[1])])), ';', '"');
                    break;
                case 'h': fwrite($fh, "\r\n"); fputcsv($fh, ['## ' . $b[1]], ';', '"'); break;
                case 'kpi': fwrite($fh, "\r\n"); fputcsv($fh, ['Indicatore', 'Valore', 'Dettaglio'], ';', '"'); foreach ($b[1] as $c) fputcsv($fh, [$c['label'], $c['value'], $c['sub'] ?? ''], ';', '"'); break;
                case 'table':
                    fwrite($fh, "\r\n"); fputcsv($fh, ['# ' . $b[1]], ';', '"'); fputcsv($fh, $b[2], ';', '"');
                    foreach ($b[3] as $r) fputcsv($fh, array_map(fn($v) => is_float($v) ? str_replace('.', ',', (string)round($v, 4)) : ($v === null ? '' : (string)$v), array_values($r)), ';', '"');
                    break;
                case 'bars':
                    fwrite($fh, "\r\n"); fputcsv($fh, ['# ' . $b[1]], ';', '"'); fputcsv($fh, ['Voce', 'Valore'], ';', '"');
                    foreach ($b[2] as $r) fputcsv($fh, [(string)$r[0], str_replace('.', ',', (string)round((float)$r[1], 4))], ';', '"');
                    break;
            }
        }
        rewind($fh); $s = stream_get_contents($fh); fclose($fh);
        return (string)$s;
    }

    /**
     * v1.10.15 — HTML: stesso contenuto dei file, per la vista a schermo ($standalone=false: frammento)
     * e per la stampa ($standalone=true: pagina completa che apre la finestra di stampa).
     */
    public function toHtml(bool $standalone = false, int $maxRows = 0): string
    {
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $c = static fn($x) => '#' . ltrim((string)$x, '#');
        $o = '<div class="pm-rep">';
        if ($standalone) $o .= '<h1>' . $h($this->title) . '</h1>' . ($this->subtitle !== '' ? '<div class="pm-rep-meta">' . $h($this->subtitle) . '</div>' : '');
        $bars = function (array $rows, string $unit, string $color) use ($h, $c): string {
            $max = 0.0; foreach ($rows as $r) $max = max($max, abs((float)$r[1]));
            $dec = self::barDec($rows); $o = '<div class="pm-rep-bars">';
            foreach ($rows as $r) {
                $w = $max > 0 ? round(abs((float)$r[1]) / $max * 100, 2) : 0;
                $o .= '<div class="pm-rep-bar"><span class="l" title="' . $h($r[0]) . '">' . $h($r[0]) . '</span><span class="t"><i style="width:' . $w . '%;background:' . $c($color) . '"></i></span><span class="v">'
                    . $h($r[2] ?? (self::fmt((float)$r[1], $dec) . ($unit !== '' ? ' ' . $unit : ''))) . '</span></div>';
            }
            return $o . '</div>';
        };
        foreach ($this->blocks as $b) {
            switch ($b[0]) {
                case 'meta': $o .= '<div class="pm-rep-meta">' . $h($b[1]) . '</div>'; break;
                case 'note': $o .= '<div class="pm-rep-note">' . $h($b[1]) . '</div>'; break;
                case 'box':  $o .= '<div class="pm-rep-box">' . $h($b[1]) . '</div>'; break;
                case 'para': $o .= '<p>' . $h($b[1]) . '</p>'; break;
                case 'h':    $l = min(4, max(2, (int)$b[2] + 1)); $o .= "<h$l>" . $h($b[1]) . "</h$l>"; break;
                case 'br':   $o .= '<div class="pm-rep-br"></div>'; break;
                case 'kpi':
                    $o .= '<div class="pm-rep-kpi">';
                    foreach ($b[1] as $k) $o .= '<div style="border-top-color:' . $c($k['color'] ?? '334155') . '"><b style="color:' . $c($k['color'] ?? '334155') . '">' . $h($k['value']) . '</b><span>' . $h($k['label']) . '</span>' . (!empty($k['sub']) ? '<small>' . $h($k['sub']) . '</small>' : '') . '</div>';
                    $o .= '</div>'; break;
                case 'bars': $o .= '<div class="pm-rep-chart"><h4>' . $h($b[1]) . '</h4>' . $bars($b[2], $b[3], $b[4]) . '</div>'; break;
                case 'stacked':
                    $max = 0.0; foreach ($b[2] as $r) $max = max($max, array_sum(array_map('floatval', $r[1])));
                    $o .= '<div class="pm-rep-chart"><h4>' . $h($b[1]) . '</h4><div class="pm-rep-leg">';
                    foreach ($b[3] as $g) $o .= '<span><i style="background:' . $c($g['color']) . '"></i>' . $h($g['label']) . '</span>';
                    $o .= '</div><div class="pm-rep-bars">';
                    foreach ($b[2] as $r) {
                        $o .= '<div class="pm-rep-bar"><span class="l" title="' . $h($r[0]) . '">' . $h($r[0]) . '</span><span class="t">';
                        foreach (array_values($r[1]) as $i => $v) if ((float)$v > 0 && $max > 0) $o .= '<i style="width:' . round((float)$v / $max * 100, 2) . '%;background:' . $c($b[3][$i]['color'] ?? '94A3B8') . '" title="' . $h(($b[3][$i]['label'] ?? '') . ': ' . self::fmt((float)$v)) . '"></i>';
                        $o .= '</span><span class="v">' . $h($r[2] ?? self::fmt(array_sum(array_map('floatval', $r[1])))) . '</span></div>';
                    }
                    $o .= '</div></div>'; break;
                case 'table':
                    if ($b[1] !== '' && empty($b[4]['notitle'])) $o .= '<h3>' . $h($b[1]) . ' <small>(' . count($b[3]) . ' righe)</small></h3>';
                    if (!$b[3]) { $o .= '<div class="pm-rep-note">Nessun dato.</div>'; break; }
                    $right = array_flip(self::rightCols($b[2], $b[3], $b[4]));
                    $rows = $maxRows > 0 && count($b[3]) > $maxRows ? array_slice($b[3], 0, $maxRows) : $b[3];
                    $tot = !empty($b[4]['total']);
                    if ($tot && $rows !== $b[3]) $rows[] = end($b[3]);
                    $o .= '<div class="pm-rep-tw"><table class="data-table pm-rep-t" data-pm-nofilter><thead><tr>';
                    foreach ($b[2] as $i => $x) $o .= '<th' . (isset($right[$i]) ? ' class="r"' : '') . '>' . $h($x) . '</th>';
                    $o .= '</tr></thead><tbody>';
                    $n = count($rows);
                    foreach ($rows as $ri => $r) {
                        $o .= '<tr' . ($tot && $ri === $n - 1 ? ' class="tot"' : '') . '>';
                        foreach (self::fmtRow($r, $b[4]) as $i => $v) $o .= '<td' . (isset($right[$i]) ? ' class="r"' : '') . '>' . $h($v) . '</td>';
                        $o .= '</tr>';
                    }
                    $o .= '</tbody></table></div>';
                    if (count($rows) < count($b[3])) $o .= '<div class="pm-rep-note">Mostrate ' . ($maxRows) . ' righe su ' . count($b[3]) . ': stampa ed export le contengono tutte.</div>';
                    break;
            }
        }
        $o .= '</div>';
        $css = '.pm-rep h1{font-size:19px;margin:0 0 4px}.pm-rep h3{font-size:14px;margin:16px 0 6px}.pm-rep h3 small{font-weight:400;color:#64748b}.pm-rep h4{font-size:12px;margin:0 0 6px;color:#334155}'
             . '.pm-rep-meta{font-size:11px;color:#64748b;margin-bottom:6px}.pm-rep-note{font-size:11px;color:#475569;background:#f8fafc;border-left:3px solid #cbd5e1;padding:6px 10px;margin:6px 0 10px}.pm-rep-box{border:1px solid #bfdbfe;background:#eff6ff;padding:8px 10px;font-size:12px;margin:6px 0}'
             . '.pm-rep-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin:8px 0 12px}.pm-rep-kpi>div{border:1px solid #e2e8f0;border-top:3px solid;border-radius:8px;padding:9px;text-align:center;background:#fff}'
             . '.pm-rep-kpi b{display:block;font-size:17px}.pm-rep-kpi span{display:block;font-size:10px;font-weight:700;text-transform:uppercase;color:#334155}.pm-rep-kpi small{display:block;font-size:10px;color:#64748b}'
             . '.pm-rep-chart{border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin:0 0 10px;background:#fff;break-inside:avoid}.pm-rep-bar{display:flex;align-items:center;gap:8px;font-size:11px;margin:2px 0}'
             . '.pm-rep-bar .l{flex:0 0 230px;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#334155}.pm-rep-bar .t{flex:1;display:flex;height:12px;background:#f1f5f9;border-radius:2px;overflow:hidden}.pm-rep-bar .t i{display:block;height:100%}'
             . '.pm-rep-bar .v{flex:0 0 90px;color:#64748b}.pm-rep-leg{display:flex;flex-wrap:wrap;gap:10px;font-size:11px;margin-bottom:6px}.pm-rep-leg i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;vertical-align:-1px}'
             . '.pm-rep-tw{overflow-x:auto}.pm-rep-t{width:100%;font-size:11px;border-collapse:collapse}.pm-rep-t th,.pm-rep-t td{padding:3px 6px;border-bottom:1px solid #e2e8f0;vertical-align:top}.pm-rep-t th{background:#f1f5f9;text-align:left;white-space:nowrap}'
             . '.pm-rep-t .r{text-align:right;white-space:nowrap}.pm-rep-t tr.tot td{font-weight:700;background:#f8fafc}.pm-rep-br{height:0}';
        if (!$standalone) return '<style>' . $css . '</style>' . $o;
        return '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($this->title) . '</title><style>'
             . 'body{font-family:Segoe UI,Arial,sans-serif;color:#0f172a;margin:18px;background:#fff}' . $css
             . '.pm-rep-br{page-break-after:always}@page{size:A4 ' . ($this->orientation === 'L' ? 'landscape' : 'portrait') . ';margin:12mm}@media print{.pm-rep-t thead{display:table-header-group}.pm-rep-t tr{break-inside:avoid}.noprint{display:none}}'
             . '</style></head><body><div class="noprint" style="margin-bottom:10px"><button onclick="window.print()">Stampa</button></div>' . $o . '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)})</script></body></html>';
    }

    /** Scrive il report nel formato richiesto. */
    public function writeToFile(string $fmt, string $path): void
    {
        match ($fmt) {
            'docx' => $this->toDocx()->writeToFile($path),
            'pdf'  => $this->toPdf()->writeToFile($path),
            'xlsx' => $this->toXlsx()->writeToFile($path),
            'csv'  => file_put_contents($path, $this->toCsv()),
            default => throw new InvalidArgumentException('Formato non supportato: ' . $fmt),
        };
    }

    /** Invia il report al browser ed esce. */
    public function send(string $fmt, string $basename): void
    {
        $fn = self::safe($basename) . '.' . $fmt;
        if ($fmt === 'docx') $this->toDocx()->download($fn);
        if ($fmt === 'pdf')  $this->toPdf()->download($fn);
        if ($fmt === 'xlsx') $this->toXlsx()->download($fn);
        if ($fmt === 'csv') {
            $data = $this->toCsv();
            while (ob_get_level() > 0) { @ob_end_clean(); }
            @ini_set('zlib.output_compression', '0');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $fn . '"');
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
            echo $data;
            exit;
        }
        throw new InvalidArgumentException('Formato non supportato: ' . $fmt);
    }

    /**
     * Archivio ZIP con un report per risorsa. $build(chiave) → PmReport; $items = [chiave => etichetta].
     */
    public static function bundle(string $fmt, array $items, callable $build, string $basename): void
    {
        if (!isset(self::FORMATS[$fmt])) throw new InvalidArgumentException('Formato non supportato');
        @set_time_limit(0);
        $zipPath = tempnam(sys_get_temp_dir(), 'pmrep_');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Impossibile creare lo ZIP');
        $tmp = []; $used = [];
        foreach ($items as $key => $label) {
            $t = tempnam(sys_get_temp_dir(), 'pmr_'); $tmp[] = $t;
            $build($key)->writeToFile($fmt, $t);
            $name = self::safe((string)$label) ?: 'tecnico'; $base = $name; $k = 2;
            while (isset($used[$name])) $name = $base . '_' . $k++;
            $used[$name] = 1;
            $zip->addFile($t, $name . '.' . $fmt);
        }
        $zip->close();
        foreach ($tmp as $t) @unlink($t);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . self::safe($basename) . '_' . $fmt . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    public static function safe(string $s): string
    {
        $s = strtr($s, ['à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'À' => 'A', 'È' => 'E', 'É' => 'E']);
        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $s), '_');
    }

    /**
     * Barra degli export + report per tecnico (HTML). $url(array $extra) costruisce il link con il filtro principale.
     * $techs = [valore filtro => etichetta]; vuoto o $techActive = true → nessun elenco per tecnico.
     */
    public static function toolbar(callable $url, array $techs, bool $techActive, string $techParam, string $techLabel = 'tecnico', bool $canExport = true, array $rowLinks = []): string
    {
        if (!$canExport) return '';
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $ico = ['docx' => 'fa-file-word', 'xlsx' => 'fa-file-excel', 'csv' => 'fa-file-csv', 'pdf' => 'fa-file-pdf'];
        $o = '<div class="pm-report-bar" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 12px">'
           . '<span style="font-size:12px;font-weight:700;color:#334155"><i class="fa-solid fa-file-export"></i> Report ' . ($techActive ? '(' . $h($techLabel) . ' selezionato)' : 'generale') . ':</span>';
        foreach (self::FORMATS as $f => $l) $o .= '<a class="btn btn-sm" href="' . $url(['rep' => $f]) . '"><i class="fa-solid ' . $ico[$f] . '"></i> ' . $l . '</a>';
        $o .= '</div>';
        if ($techActive || !$techs) return $o;
        $o .= '<details class="pm-panel" style="margin-bottom:12px"><summary><i class="fa-solid fa-chevron-right pm-chev"></i> Report singoli per ' . $h($techLabel)
            . ' <span class="pm-hint">' . count($techs) . ' risorse nel perimetro del filtro · stesso contenuto del report generale, limitato alla risorsa</span></summary>'
            . '<div class="pm-panel-body"><div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px"><span style="font-size:12px">Tutti (ZIP, un file per risorsa):</span>';
        foreach (self::FORMATS as $f => $l) $o .= '<a class="btn btn-sm btn-primary" href="' . $url(['rep' => $f, 'rep_zip' => 1]) . '"><i class="fa-solid fa-file-zipper"></i> ' . $l . '</a>';
        $o .= '</div><table class="data-table" data-pm-nofilter style="width:100%;font-size:12px"><thead><tr><th>' . $h(ucfirst($techLabel)) . '</th><th>Report</th><th>Vista / stampa</th></tr></thead><tbody>';
        foreach ($techs as $v => $lbl) {
            $o .= '<tr><td>' . $h($lbl) . '</td><td style="white-space:nowrap">';
            foreach (self::FORMATS as $f => $l) $o .= '<a href="' . $url(['rep' => $f, $techParam => $v]) . '" style="margin-right:8px"><i class="fa-solid ' . $ico[$f] . '"></i> ' . $l . '</a>';
            $o .= '</td><td><a href="' . $url([$techParam => $v]) . '">apri con il filtro →</a>';
            foreach ($rowLinks as $lbl => $extra) $o .= ' · <a target="_blank" href="' . $url(array_merge($extra, [$techParam => $v])) . '"><i class="fa-solid fa-print"></i> ' . $h($lbl) . '</a>';
            $o .= '</td></tr>';
        }
        return $o . '</tbody></table></div></details>';
    }
}
