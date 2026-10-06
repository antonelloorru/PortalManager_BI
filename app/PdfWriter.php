<?php
/**
 * PortalManager — app/PdfWriter.php  (v1.10.12)
 *
 * Generatore PDF puro PHP, zero dipendenze (come DocxWriter / XlsxWriter): PDF 1.4, font standard
 * Helvetica / Helvetica-Bold (WinAnsiEncoding, testo UTF-8 convertito in Windows-1252), A4 orizzontale
 * o verticale, intestazione e piè di pagina con numerazione «Pagina n di N».
 *
 * Vocabolario grafico allineato a DocxWriter: titolo, heading, meta, note, box, card KPI, tabelle con
 * header scuro + righe alternate + allineamento colonne + testo a capo + header ripetuto a ogni pagina,
 * grafico a barre orizzontali, interruzione di pagina.
 *
 * USO:
 *   $p = new PdfWriter('Report Service SOC', ['orientation' => 'L']);
 *   $p->meta('Periodo …'); $p->kpi([['label' => 'Ore', 'value' => '123', 'color' => '16A34A', 'sub' => '…']]);
 *   $p->heading('Sezione', 1); $p->table(['A', 'B'], [['x', '1']], ['right' => [1]]);
 *   $p->download('report.pdf');   // oppure $p->output() / $p->writeToFile($path)
 */
declare(strict_types=1);

final class PdfWriter
{
    private float $W; private float $H;
    private const M = 32.0;                 // margine
    private const HDR = [0x1E, 0x29, 0x3B];
    private const ZEB = [0xF8, 0xFA, 0xFC];
    private array $pages = [];              // contenuti (stream) delle pagine
    private string $cur = '';
    private float $y = 0;
    private string $title;

    /** Larghezze Helvetica / Helvetica-Bold (1/1000 em) dei caratteri 32..126. */
    private const WR = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,
        667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,
        556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const WB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,
        722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,
        556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function __construct(string $title = '', array $o = [])
    {
        [$this->W, $this->H] = (($o['orientation'] ?? 'L') === 'P') ? [595.28, 841.89] : [841.89, 595.28];
        $this->title = $title;
        $this->newPage();
        if ($title !== '') $this->heading($title, 0);
    }

    // ── testo ────────────────────────────────────────────────────────
    public function heading(string $text, int $level = 1): self
    {
        $size = [0 => 17, 1 => 13, 2 => 11, 3 => 10][max(0, min(3, $level))];
        $this->space($size + 14 + ($level >= 1 ? 70 : 0));          // il titolo resta con il contenuto che segue
        $this->y -= $level <= 1 ? 8 : 4;
        $this->text(self::M, $this->y - $size, $text, $size, true, $level === 0 ? [0x0F, 0x17, 0x2A] : [0x1E, 0x29, 0x3B]);
        $this->y -= $size + 3;
        if ($level <= 1) { $this->line(self::M, $this->y, $this->W - self::M, $this->y, $level === 0 ? [0x25, 0x63, 0xEB] : [0xCB, 0xD5, 0xE1], $level === 0 ? 1.4 : 0.6); $this->y -= 6; }
        return $this;
    }

    public function paragraph(string $text, array $o = []): self
    {
        $size = (float)($o['size'] ?? 9); $bold = !empty($o['bold']);
        $col = self::rgb($o['color'] ?? '334155');
        foreach ($this->wrap($text, $this->W - 2 * self::M, $size, $bold) as $ln) {
            $this->space($size + 3);
            $this->text(self::M, $this->y - $size, $ln, $size, $bold, $col);
            $this->y -= $size + 3;
        }
        $this->y -= (float)($o['after'] ?? 4);
        return $this;
    }

    public function meta(string $text): self { return $this->paragraph($text, ['size' => 8, 'color' => '64748B', 'after' => 4]); }
    public function note(string $text): self { return $this->paragraph($text, ['size' => 7.5, 'color' => '64748B', 'after' => 6]); }

    public function box(string $text, string $border = '2563EB', string $fill = 'F1F5F9'): self
    {
        $size = 8.0; $w = $this->W - 2 * self::M;
        $lines = $this->wrap($text, $w - 14, $size, false);
        $h = count($lines) * ($size + 3) + 8;
        $this->space($h + 6);
        $this->rect(self::M, $this->y - $h, $w, $h, self::rgb($fill));
        $this->rect(self::M, $this->y - $h, 3, $h, self::rgb($border));
        $yy = $this->y - 4;
        foreach ($lines as $ln) { $this->text(self::M + 9, $yy - $size, $ln, $size, false, [0x33, 0x41, 0x55]); $yy -= $size + 3; }
        $this->y -= $h + 6;
        return $this;
    }

    public function pageBreak(): self { $this->newPage(); return $this; }

    // ── KPI ───────────────────────────────────────────────────────────
    /** @param array<int,array{label:string,value:string,color?:string,sub?:string}> $cards */
    public function kpi(array $cards): self
    {
        if (!$cards) return $this;
        $perRow = min(count($cards), $this->W > 700 ? 6 : 4);
        $gap = 6.0; $w = ($this->W - 2 * self::M - ($perRow - 1) * $gap) / $perRow; $h = 46.0;
        foreach (array_chunk($cards, $perRow) as $row) {
            $this->space($h + $gap);
            foreach ($row as $i => $c) {
                $x = self::M + $i * ($w + $gap); $col = self::rgb($c['color'] ?? '334155');
                $this->rect($x, $this->y - $h, $w, $h, self::ZEB);
                $this->rect($x, $this->y - 2.5, $w, 2.5, $col);
                $this->textC($x + $w / 2, $this->y - 19, self::fit((string)($c['value'] ?? ''), $w - 6, 13, true), 13, true, $col);
                $this->textC($x + $w / 2, $this->y - 30, self::fit(mb_strtoupper((string)($c['label'] ?? ''), 'UTF-8'), $w - 6, 6.5, true), 6.5, true, [0x47, 0x55, 0x69]);
                if (($c['sub'] ?? '') !== '') $this->textC($x + $w / 2, $this->y - 40, self::fit((string)$c['sub'], $w - 6, 6.5, false), 6.5, false, [0x94, 0xA3, 0xB8]);
            }
            $this->y -= $h + $gap;
        }
        $this->y -= 2;
        return $this;
    }

    // ── tabella ───────────────────────────────────────────────────────
    /**
     * @param string[] $header
     * @param array<int,array<int,string|int|float|null>> $rows   valori già formattati per la stampa
     * @param array{right?:int[],size?:float,total?:bool,widths?:float[]} $o  total = ultima riga in grassetto
     */
    public function table(array $header, array $rows, array $o = []): self
    {
        $ncol = count($header); foreach ($rows as $r) $ncol = max($ncol, count($r));
        if ($ncol === 0) return $this;
        $right = array_flip($o['right'] ?? []);
        $size = (float)($o['size'] ?? ($ncol > 12 ? 6.5 : ($ncol > 8 ? 7.0 : 7.5)));
        $pad = 3.0; $avail = $this->W - 2 * self::M;
        $cell = static fn($v) => $v === null ? '' : (string)$v;
        // larghezze: naturale (max 240 pt), minimo 26 pt, poi riduzione proporzionale
        $nat = array_fill(0, $ncol, 26.0);
        for ($c = 0; $c < $ncol; $c++) {
            $nat[$c] = max($nat[$c], min(240.0, $this->sw($cell($header[$c] ?? ''), $size, true) + 2 * $pad));
            foreach (array_slice($rows, 0, 400) as $r) $nat[$c] = max($nat[$c], min(240.0, $this->sw($cell($r[$c] ?? ''), $size, false) + 2 * $pad));
        }
        $w = $o['widths'] ?? null;
        if (!$w) {
            $sum = array_sum($nat);
            if ($sum <= $avail) { $extra = ($avail - $sum) / $ncol; $w = array_map(fn($x) => $x + min($extra, 30.0), $nat); }
            else {
                $fixed = 0.0; $flex = [];
                foreach ($nat as $c => $x) { if ($x <= 60) $fixed += $x; else $flex[$c] = $x; }
                $rest = max($avail - $fixed, 60.0 * max(1, count($flex)));
                $fs = array_sum($flex) ?: 1;
                $w = $nat; foreach ($flex as $c => $x) $w[$c] = max(40.0, $x * $rest / $fs);
                $tot = array_sum($w); if ($tot > $avail) $w = array_map(fn($x) => $x * $avail / $tot, $w);
            }
        }
        $lh = $size + 2.2;
        $drawRow = function (array $r, bool $head, bool $zebra, bool $bold) use ($ncol, $w, $size, $pad, $lh, $right, $cell): void {
            $lines = []; $max = 1;
            for ($c = 0; $c < $ncol; $c++) { $lines[$c] = $this->wrap($cell($r[$c] ?? ''), $w[$c] - 2 * $pad, $size, $head || $bold); $max = max($max, count($lines[$c])); }
            $h = $max * $lh + 2 * $pad - 1;
            $tw = array_sum($w);
            if ($head) $this->rect(self::M, $this->y - $h, $tw, $h, self::HDR);
            elseif ($zebra) $this->rect(self::M, $this->y - $h, $tw, $h, self::ZEB);
            $x = self::M;
            for ($c = 0; $c < $ncol; $c++) {
                $yy = $this->y - $pad - $size + 0.5;
                foreach ($lines[$c] as $ln) {
                    $col = $head ? [0xFF, 0xFF, 0xFF] : [0x1E, 0x29, 0x3B];
                    if (isset($right[$c])) $this->text($x + $w[$c] - $pad - $this->sw($ln, $size, $head || $bold), $yy, $ln, $size, $head || $bold, $col);
                    else $this->text($x + $pad, $yy, $ln, $size, $head || $bold, $col);
                    $yy -= $lh;
                }
                $x += $w[$c];
            }
            if (!$head) $this->line(self::M, $this->y - $h, self::M + $tw, $this->y - $h, [0xE2, 0xE8, 0xF0], 0.4);
            $this->y -= $h;
        };
        $rowH = function (array $r, bool $b) use ($ncol, $w, $size, $pad, $lh, $cell): float {
            $max = 1; for ($c = 0; $c < $ncol; $c++) $max = max($max, count($this->wrap($cell($r[$c] ?? ''), $w[$c] - 2 * $pad, $size, $b)));
            return $max * $lh + 2 * $pad - 1;
        };
        $hh = $rowH($header, true);
        $this->space($hh + $rowH($rows[0] ?? [], false) + 2);
        $drawRow($header, true, false, false);
        $n = count($rows);
        foreach ($rows as $i => $r) {
            $last = !empty($o['total']) && $i === $n - 1;
            $h = $rowH($r, $last);
            if ($this->y - $h < self::M + 18) { $this->newPage(); $drawRow($header, true, false, false); }
            $drawRow($r, false, $i % 2 === 1, $last);
        }
        $this->y -= 8;
        return $this;
    }

    // ── grafico a barre orizzontali ───────────────────────────────────
    /** @param array<int,array{0:string,1:float,2?:string}> $rows [etichetta, valore, testo] */
    public function bars(array $rows, array $o = []): self
    {
        if (!$rows) return $this;
        if (($o['title'] ?? '') !== '') $this->heading((string)$o['title'], 3);
        $col = self::rgb($o['color'] ?? '2563EB');
        $max = max(array_map(fn($r) => (float)$r[1], $rows)) ?: 1.0;
        $lw = 170.0; $bw = $this->W - 2 * self::M - $lw - 70; $h = 11.0;
        foreach ($rows as $r) {
            $this->space($h + 2);
            $this->text(self::M, $this->y - 8, self::fit((string)$r[0], $lw - 6, 7.5, false), 7.5, false, [0x33, 0x41, 0x55]);
            $len = max(0.5, $bw * max(0.0, (float)$r[1]) / $max);
            $this->rect(self::M + $lw, $this->y - $h + 2, $len, $h - 3, $col);
            $this->text(self::M + $lw + $len + 4, $this->y - 8, (string)($r[2] ?? $r[1]), 7.5, true, [0x33, 0x41, 0x55]);
            $this->y -= $h + 1;
        }
        $this->y -= 6;
        return $this;
    }

    // ── output ────────────────────────────────────────────────────────
    public function output(): string
    {
        $pages = $this->pages; $pages[] = $this->cur;
        $n = count($pages);
        $objs = [];                                     // oggetto n → contenuto
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = []; $next = 5;
        foreach ($pages as $i => $c) {
            $foot = $this->footer($i + 1, $n);
            $stream = gzcompress($c . $foot);
            $po = $next++; $co = $next++;
            $kids[] = "$po 0 R";
            $objs[$po] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n($this->W) . ' ' . self::n($this->H) . '] '
                       . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $co . ' 0 R >>';
            $objs[$co] = "<< /Length " . strlen($stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $info = $next;
        $objs[$info] = '<< /Title <FEFF' . strtoupper(bin2hex((string)mb_convert_encoding($this->title, 'UTF-16BE', 'UTF-8'))) . '> /Producer (PortalManager PdfWriter) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $xref = [];
        foreach ($objs as $k => $v) { $xref[$k] = strlen($out); $out .= "$k 0 obj\n$v\nendobj\n"; }
        $x = strlen($out);
        $out .= "xref\n0 " . ($info + 1) . "\n0000000000 65535 f \n";
        for ($k = 1; $k <= $info; $k++) $out .= sprintf("%010d 00000 n \n", $xref[$k]);
        $out .= "trailer\n<< /Size " . ($info + 1) . " /Root 1 0 R /Info $info 0 R >>\nstartxref\n$x\n%%EOF\n";
        return $out;
    }

    public function writeToFile(string $path): void { file_put_contents($path, $this->output()); }

    public function download(string $filename): void
    {
        $data = $this->output();
        while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Content-Length: ' . strlen($data));
            header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        }
        echo $data;
        exit;
    }

    // ── primitive ─────────────────────────────────────────────────────
    private function newPage(): void
    {
        if ($this->cur !== '') $this->pages[] = $this->cur;
        $this->cur = ' ';
        $this->y = $this->H - self::M;
    }

    private function space(float $h): void { if ($this->y - $h < self::M + 18) $this->newPage(); }

    private function footer(int $i, int $n): string
    {
        $s = 7.0; $y = self::M - 14;
        $o = sprintf("%s %s %s RG 0.4 w %s %s m %s %s l S\n", ...array_merge(array_map(fn($v) => self::n($v / 255), [0xCB, 0xD5, 0xE1]),
              [self::n(self::M), self::n($y + 9), self::n($this->W - self::M), self::n($y + 9)]));
        $left = self::fit($this->title, $this->W / 2, $s, false) . ' · ' . date('d/m/Y H:i');
        $right = "Pagina $i di $n";
        $o .= $this->textOp(self::M, $y, $left, $s, false, [0x94, 0xA3, 0xB8]);
        $o .= $this->textOp($this->W - self::M - $this->sw($right, $s, false), $y, $right, $s, false, [0x94, 0xA3, 0xB8]);
        return $o;
    }

    private function text(float $x, float $y, string $s, float $size, bool $bold, array $rgb): void { $this->cur .= $this->textOp($x, $y, $s, $size, $bold, $rgb); }
    private function textC(float $cx, float $y, string $s, float $size, bool $bold, array $rgb): void { $this->text($cx - $this->sw($s, $size, $bold) / 2, $y, $s, $size, $bold, $rgb); }

    private function textOp(float $x, float $y, string $s, float $size, bool $bold, array $rgb): string
    {
        if ($s === '') return '';
        return sprintf("BT %s %s %s rg /%s %s Tf %s %s Td (%s) Tj ET\n", self::n($rgb[0] / 255), self::n($rgb[1] / 255), self::n($rgb[2] / 255),
            $bold ? 'F2' : 'F1', self::n($size), self::n($x), self::n($y), self::esc(self::enc($s)));
    }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->cur .= sprintf("%s %s %s rg %s %s %s %s re f\n", self::n($rgb[0] / 255), self::n($rgb[1] / 255), self::n($rgb[2] / 255), self::n($x), self::n($y), self::n($w), self::n($h));
    }

    private function line(float $x1, float $y1, float $x2, float $y2, array $rgb, float $w): void
    {
        $this->cur .= sprintf("%s %s %s RG %s w %s %s m %s %s l S\n", self::n($rgb[0] / 255), self::n($rgb[1] / 255), self::n($rgb[2] / 255), self::n($w), self::n($x1), self::n($y1), self::n($x2), self::n($y2));
    }

    /** Larghezza del testo in punti (Windows-1252, metriche Helvetica). */
    private function sw(string $s, float $size, bool $bold): float
    {
        $t = self::enc($s); $tab = $bold ? self::WB : self::WR; $w = 0;
        $len = strlen($t);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($t[$i]);
            if ($o >= 32 && $o <= 126) $w += $tab[$o - 32];
            elseif ($o === 0x80 || $o === 0x96) $w += 556;           // € –
            elseif ($o === 0x97 || $o === 0x85) $w += 1000;          // — …
            elseif ($o >= 0xC0) $w += $bold ? 640 : 590;             // lettere accentate
            else $w += 400;
        }
        return $w * $size / 1000;
    }

    /** @return string[] righe che stanno in $width */
    private function wrap(string $s, float $width, float $size, bool $bold): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $s) as $para) {
            $words = preg_split('/ +/', trim($para)); $line = '';
            if ($words === [''] || $words === false) { $out[] = ''; continue; }
            foreach ($words as $wd) {
                $try = $line === '' ? $wd : "$line $wd";
                if ($this->sw($try, $size, $bold) <= $width) { $line = $try; continue; }
                if ($line !== '') $out[] = $line;
                while ($this->sw($wd, $size, $bold) > $width && mb_strlen($wd) > 1) {    // parola più lunga della cella
                    $k = mb_strlen($wd);
                    while ($k > 1 && $this->sw(mb_substr($wd, 0, $k), $size, $bold) > $width) $k--;
                    $out[] = mb_substr($wd, 0, $k); $wd = mb_substr($wd, $k);
                }
                $line = $wd;
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }

    private static function fit(string $s, float $w, float $size, bool $bold): string
    {
        static $p = null; $p ??= new self('');
        if ($p->sw($s, $size, $bold) <= $w) return $s;
        while (mb_strlen($s) > 1 && $p->sw($s . '…', $size, $bold) > $w) $s = mb_substr($s, 0, -1);
        return $s . '…';
    }

    private static function enc(string $s): string
    {
        $s = strtr($s, ['→' => '->', '←' => '<-', '✔' => 'v', '✘' => 'x', '≥' => '>=', '≤' => '<=', '×' => 'x', '›' => '>', '‹' => '<', "\t" => ' ']);
        $r = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return is_string($r) ? $r : preg_replace('/[^\x20-\x7E]/', '?', $s);
    }

    private static function esc(string $s): string { return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s); }
    private static function n(float $v): string { return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0'; }
    private static function rgb(string $hex): array { $hex = ltrim($hex, '#'); return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))]; }
}
