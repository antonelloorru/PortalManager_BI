<?php
/**
 * PortalManager — app/DocxWriter.php
 * Generatore .docx (Office Open XML) puro PHP, zero dipendenze (come XlsxWriter).
 *
 * v1.9.46 — vocabolario grafico allineato al report di stampa (PDF):
 *   titolo, heading con sottolineatura, meta, box (filtri/avviso), card KPI,
 *   tabelle con header scuro + righe alternate + allineamento colonne,
 *   note, grafici a barre (blocchi Unicode colorati), interruzione di pagina.
 *
 * USO:
 *   $d = new DocxWriter('Relazione di Servizio IT');
 *   $d->meta('Periodo ...'); $d->box('Filtri: ...','2563EB','F1F5F9');
 *   $d->kpi([['label'=>'Ore','value'=>'123','color'=>'16A34A','sub'=>'...']]);
 *   $d->heading('Sezione',1);
 *   $d->table(['A','B'], [['x','1']], ['right'=>[1]]);
 *   $d->barlist([['Voce', 12.0, '12 h']], ['color'=>'2563EB']);
 *   $d->note('...'); $d->pageBreak();
 *   $d->download('file.docx');
 */
final class DocxWriter
{
    /** @var string[] */
    private array $body = [];
    private const HDR = '1E293B';   // header tabella
    private const ZEB = 'F8FAFC';   // riga alternata

    public function __construct(string $title = '')
    {
        if ($title !== '') $this->heading($title, 0);
    }

    // ── testo ────────────────────────────────────────────────────────
    public function heading(string $text, int $level = 1): self
    {
        $style = $level <= 0 ? 'Title' : 'Heading' . min(3, $level);
        $this->body[] = '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>'
            . '<w:r><w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r></w:p>';
        return $this;
    }

    /**
     * @param array{size?:int,color?:string,bold?:bool,italic?:bool,fill?:string,left?:string,after?:int,before?:int} $o
     */
    public function paragraph(string $text, array $o = []): self
    {
        $this->body[] = self::para($text, $o);
        return $this;
    }

    public function meta(string $text): self
    {
        return $this->paragraph($text, ['size' => 16, 'color' => '64748B', 'after' => 120]);
    }

    public function note(string $text): self
    {
        return $this->paragraph($text, ['size' => 14, 'color' => '64748B', 'italic' => true, 'before' => 60, 'after' => 120]);
    }

    /** Box con barra a sinistra + sfondo (filtri, avviso). */
    public function box(string $text, string $border = '2563EB', string $fill = 'F1F5F9'): self
    {
        return $this->paragraph($text, ['size' => 16, 'fill' => $fill, 'left' => $border, 'after' => 160]);
    }

    public function spacer(): self { $this->body[] = '<w:p/>'; return $this; }

    public function pageBreak(): self
    {
        $this->body[] = '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        return $this;
    }

    // ── KPI band ──────────────────────────────────────────────────────
    /**
     * @param array<int,array{label:string,value:string,color?:string,sub?:string}> $cards
     */
    public function kpi(array $cards): self
    {
        if (!$cards) return $this;
        $n = count($cards);
        $tbl = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>'
             . '<w:tblBorders><w:insideV w:val="single" w:sz="2" w:space="0" w:color="FFFFFF"/></w:tblBorders>'
             . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/>'
             . '<w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr>';
        $cw = (int)floor(5000 / $n);
        $tbl .= '<w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $cw . '"/>', $n) . '</w:tblGrid><w:tr>';
        foreach ($cards as $c) {
            $color = $c['color'] ?? '334155';
            $val   = self::esc((string)($c['value'] ?? ''));
            $lbl   = self::esc(mb_strtoupper((string)($c['label'] ?? ''), 'UTF-8'));
            $sub   = self::esc((string)($c['sub'] ?? ''));
            $tbl .= '<w:tc><w:tcPr><w:tcW w:w="' . $cw . '" w:type="pct"/>'
                  . '<w:tcBorders><w:top w:val="single" w:sz="24" w:space="0" w:color="' . $color . '"/></w:tcBorders>'
                  . '<w:shd w:val="clear" w:color="auto" w:fill="' . self::ZEB . '"/></w:tcPr>'
                  . '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
                  . '<w:r><w:rPr><w:b/><w:color w:val="' . $color . '"/><w:sz w:val="30"/></w:rPr>'
                  . '<w:t xml:space="preserve">' . $val . '</w:t></w:r>'
                  . '<w:r><w:br/><w:rPr><w:b/><w:color w:val="475569"/><w:sz w:val="13"/></w:rPr>'
                  . '<w:t xml:space="preserve">' . $lbl . '</w:t></w:r>'
                  . ($sub !== '' ? '<w:r><w:br/><w:rPr><w:color w:val="94A3B8"/><w:sz w:val="13"/></w:rPr>'
                        . '<w:t xml:space="preserve">' . $sub . '</w:t></w:r>' : '')
                  . '</w:p></w:tc>';
        }
        $tbl .= '</w:tr></w:tbl><w:p><w:pPr><w:spacing w:after="60"/></w:pPr></w:p>';
        $this->body[] = $tbl;
        return $this;
    }

    // ── tabella ───────────────────────────────────────────────────────
    /**
     * @param string[] $header
     * @param array<int,array<int,string|int|float|null>> $rows
     * @param array{right?:int[],zebra?:bool} $o
     */
    public function table(array $header, array $rows, array $o = []): self
    {
        $right = array_flip($o['right'] ?? []);
        $zebra = $o['zebra'] ?? true;
        $ncol = count($header);
        foreach ($rows as $r) $ncol = max($ncol, count($r));
        $ncol = max(1, $ncol);

        $tbl = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="5000" w:type="pct"/>'
             . '<w:tblBorders>'
             . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>'
             . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>'
             . '</w:tblBorders>'
             . '<w:tblCellMar><w:top w:w="30" w:type="dxa"/><w:bottom w:w="30" w:type="dxa"/>'
             . '<w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr>';
        $tbl .= '<w:tblGrid>' . str_repeat('<w:gridCol w:w="1200"/>', $ncol) . '</w:tblGrid>';

        if ($header) {
            $tbl .= '<w:trPr></w:trPr>' === '' ? '' : '';
            $tbl .= '<w:tr>';
            foreach ($header as $i => $c) {
                $tbl .= self::tc((string)$c, [
                    'bold' => true, 'color' => 'FFFFFF', 'fill' => self::HDR,
                    'align' => isset($right[$i]) ? 'right' : 'left', 'size' => 14, 'caps' => true,
                ]);
            }
            $tbl .= '</w:tr>';
        }
        foreach ($rows as $ri => $r) {
            $fill = ($zebra && ($ri % 2 === 1)) ? self::ZEB : null;
            $tbl .= '<w:tr>';
            for ($i = 0; $i < $ncol; $i++) {
                $val = $r[$i] ?? '';
                $tbl .= self::tc($val === null ? '' : (string)$val, [
                    'align' => isset($right[$i]) ? 'right' : 'left', 'size' => 15,
                    'fill' => $fill,
                ]);
            }
            $tbl .= '</w:tr>';
        }
        $tbl .= '</w:tbl><w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
        $this->body[] = $tbl;
        return $this;
    }

    // ── grafico a barre (barre proporzionali reali, celle colorate) ───
    private const BARMAX = 6200; // larghezza area barra in twips

    /**
     * Barre orizzontali monocolore.
     * @param array<int,array{0:string,1:float,2?:string}> $rows  [etichetta, valore, display?]
     * @param array{color?:string,title?:string} $o
     */
    public function bars(array $rows, array $o = []): self
    {
        if ($o['title'] ?? '') $this->heading($o['title'], 3);
        $color = $o['color'] ?? '2563EB';
        $max = 0.0; foreach ($rows as $r) $max = max($max, (float)$r[1]);
        if ($max <= 0) $max = 1;
        $tbl = self::barTableOpen();
        foreach ($rows as $r) {
            $v = max(0.0, (float)$r[1]);
            $barW = (int)round(self::BARMAX * $v / $max);
            if ($v > 0 && $barW < 12) $barW = 12;
            $host = self::barHost([[$barW, $color]], self::BARMAX);
            $tbl .= '<w:tr>'
                 . self::tc((string)$r[0], ['align' => 'left', 'size' => 14])
                 . '<w:tc><w:tcPr><w:tcW w:w="' . self::BARMAX . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $host . '</w:tc>'
                 . self::tc(isset($r[2]) ? (string)$r[2] : self::num($v), ['align' => 'right', 'size' => 14, 'color' => '475569'])
                 . '</w:tr>';
        }
        $tbl .= '</w:tbl><w:p><w:pPr><w:spacing w:after="140"/></w:pPr></w:p>';
        $this->body[] = $tbl;
        return $this;
    }

    /**
     * Barre orizzontali impilate (segmenti colorati) + legenda.
     * @param array<int,array{0:string,1:float[],2?:string}> $rows  [etichetta, [val per segmento], display?]
     * @param array<int,array{label:string,color:string}> $segs
     * @param array{title?:string} $o
     */
    public function stackedbars(array $rows, array $segs, array $o = []): self
    {
        if ($o['title'] ?? '') $this->heading($o['title'], 3);
        $max = 0.0; foreach ($rows as $r) { $s = 0.0; foreach ($r[1] as $x) $s += (float)$x; $max = max($max, $s); }
        if ($max <= 0) $max = 1;
        $tbl = self::barTableOpen();
        foreach ($rows as $r) {
            $segvals = [];
            foreach ($segs as $k => $sm) {
                $v = (float)($r[1][$k] ?? 0);
                $w = (int)round(self::BARMAX * $v / $max);
                if ($v > 0 && $w < 6) $w = 6;
                if ($w > 0) $segvals[] = [$w, $sm['color']];
            }
            $host = self::barHost($segvals, self::BARMAX);
            $sum = 0.0; foreach ($r[1] as $x) $sum += (float)$x;
            $tbl .= '<w:tr>'
                 . self::tc((string)$r[0], ['align' => 'left', 'size' => 14])
                 . '<w:tc><w:tcPr><w:tcW w:w="' . self::BARMAX . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $host . '</w:tc>'
                 . self::tc(isset($r[2]) ? (string)$r[2] : self::num($sum), ['align' => 'right', 'size' => 14, 'color' => '475569'])
                 . '</w:tr>';
        }
        $tbl .= '</w:tbl>';
        // legenda
        $leg = '<w:p><w:pPr><w:spacing w:after="140"/></w:pPr>';
        foreach ($segs as $sm) {
            $leg .= '<w:r><w:rPr><w:rFonts w:ascii="Segoe UI Symbol" w:hAnsi="Segoe UI Symbol"/><w:color w:val="' . $sm['color'] . '"/><w:sz w:val="16"/></w:rPr><w:t xml:space="preserve">■ </w:t></w:r>'
                  . '<w:r><w:rPr><w:color w:val="475569"/><w:sz w:val="14"/></w:rPr><w:t xml:space="preserve">' . self::esc($sm['label']) . '    </w:t></w:r>';
        }
        $leg .= '</w:p>';
        $this->body[] = $tbl . $leg;
        return $this;
    }

    private static function barTableOpen(): string
    {
        return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblLayout w:type="fixed"/>'
             . '<w:tblBorders></w:tblBorders>'
             . '<w:tblCellMar><w:top w:w="6" w:type="dxa"/><w:bottom w:w="6" w:type="dxa"/>'
             . '<w:left w:w="40" w:type="dxa"/><w:right w:w="40" w:type="dxa"/></w:tblCellMar></w:tblPr>'
             . '<w:tblGrid><w:gridCol w:w="2600"/><w:gridCol w:w="' . self::BARMAX . '"/><w:gridCol w:w="900"/></w:tblGrid>';
    }

    /**
     * Nested table 1 riga: segmenti colorati (barra) + eventuale resto trasparente.
     * @param array<int,array{0:int,1:string}> $segs  [larghezza twips, colore]
     */
    private static function barHost(array $segs, int $full): string
    {
        $used = 0; foreach ($segs as $sgv) $used += $sgv[0];
        $rest = max(0, $full - $used);
        $cells = '';
        foreach ($segs as $sgv) {
            [$w, $col] = $sgv;
            $cells .= '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>'
                    . '<w:shd w:val="clear" w:color="auto" w:fill="' . $col . '"/>'
                    . '<w:tcMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tcMar></w:tcPr>'
                    . '<w:p><w:pPr><w:spacing w:after="0" w:line="150" w:lineRule="exact"/></w:pPr></w:p></w:tc>';
        }
        if ($rest > 0) {
            $cells .= '<w:tc><w:tcPr><w:tcW w:w="' . $rest . '" w:type="dxa"/>'
                    . '<w:tcMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tcMar></w:tcPr>'
                    . '<w:p><w:pPr><w:spacing w:after="0" w:line="150" w:lineRule="exact"/></w:pPr></w:p></w:tc>';
        }
        $grid = '';
        foreach ($segs as $sgv) $grid .= '<w:gridCol w:w="' . $sgv[0] . '"/>';
        if ($rest > 0) $grid .= '<w:gridCol w:w="' . $rest . '"/>';
        // nested table + paragrafo di chiusura (richiesto: una cella non puo' terminare con una tabella)
        return '<w:tbl><w:tblPr><w:tblW w:w="' . $full . '" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
             . '<w:tblBorders></w:tblBorders>'
             . '<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/>'
             . '<w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr>'
             . '<w:tblGrid>' . $grid . '</w:tblGrid><w:tr>' . $cells . '</w:tr></w:tbl>'
             . '<w:p><w:pPr><w:spacing w:after="0" w:line="30" w:lineRule="exact"/></w:pPr></w:p>';
    }

    // ── output ────────────────────────────────────────────────────────
    public function download(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx_');
        $this->writeToFile($tmp);
        while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');
        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . self::asciiSafe($filename) . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public function writeToFile(string $path): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive non disponibile. Abilitare estensione zip in PHP.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossibile creare docx: $path");
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('word/_rels/document.xml.rels', $this->docRels());
        $zip->addFromString('word/styles.xml', $this->stylesXml());
        $zip->addFromString('word/document.xml', $this->documentXml());
        $zip->close();
    }

    // ── helper di composizione ────────────────────────────────────────
    private static function para(string $text, array $o): string
    {
        $ppr = '<w:spacing w:after="' . (int)($o['after'] ?? 80) . '" w:before="' . (int)($o['before'] ?? 0) . '"/>';
        if (!empty($o['fill'])) $ppr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fill'] . '"/>';
        if (!empty($o['left'])) $ppr .= '<w:pBdr><w:left w:val="single" w:sz="18" w:space="6" w:color="' . $o['left'] . '"/></w:pBdr>';
        $rpr = '';
        if (!empty($o['bold']))   $rpr .= '<w:b/>';
        if (!empty($o['italic'])) $rpr .= '<w:i/>';
        if (!empty($o['color']))  $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
        if (!empty($o['size']))   $rpr .= '<w:sz w:val="' . (int)$o['size'] . '"/>';
        return '<w:p><w:pPr>' . $ppr . '</w:pPr><w:r>'
            . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '')
            . '<w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r></w:p>';
    }

    /** cella di tabella con opzioni. */
    private static function tc(string $text, array $o): string
    {
        $align = $o['align'] ?? 'left';
        $rpr = '';
        if (!empty($o['bold']))  $rpr .= '<w:b/>';
        if (!empty($o['color'])) $rpr .= '<w:color w:val="' . $o['color'] . '"/>';
        if (!empty($o['size']))  $rpr .= '<w:sz w:val="' . (int)$o['size'] . '"/>';
        $txt = !empty($o['caps']) ? mb_strtoupper($text, 'UTF-8') : $text;
        $tcpr = '';
        if (!empty($o['fill'])) $tcpr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $o['fill'] . '"/>';
        return '<w:tc><w:tcPr>' . $tcpr . '</w:tcPr>'
            . '<w:p><w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/>'
            . '<w:jc w:val="' . ($align === 'right' ? 'right' : ($align === 'center' ? 'center' : 'left')) . '"/></w:pPr>'
            . '<w:r>' . ($rpr !== '' ? '<w:rPr>' . $rpr . '</w:rPr>' : '')
            . '<w:t xml:space="preserve">' . self::esc($txt) . '</w:t></w:r></w:p></w:tc>';
    }

    private static function num(float $v): string { return number_format($v, 2, ',', '.'); }

    // ── parti OOXML ───────────────────────────────────────────────────
    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private function docRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        $h = function (string $id, string $name, int $sz, string $color, string $extraPpr = '') : string {
            return '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/>'
                . '<w:pPr><w:spacing w:before="200" w:after="80"/>' . $extraPpr . '</w:pPr>'
                . '<w:rPr><w:b/><w:color w:val="' . $color . '"/><w:sz w:val="' . $sz . '"/></w:rPr></w:style>';
        };
        $blueUnderline = '<w:pBdr><w:bottom w:val="single" w:sz="18" w:space="2" w:color="2563EB"/></w:pBdr>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="18"/><w:color w:val="1E293B"/></w:rPr></w:rPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . $h('Title', 'Title', 40, '0F172A')
            . $h('Heading1', 'heading 1', 26, '0F172A', $blueUnderline)
            . $h('Heading2', 'heading 2', 22, '334155')
            . $h('Heading3', 'heading 3', 18, '334155')
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style>'
            . '</w:styles>';
    }

    private function documentXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . implode('', $this->body)
            . '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
            . '<w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function asciiSafe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }
}
