<?php
/**
 * certV — app/XlsxWriter.php
 * Generatore di file Office Open XML (.xlsx) puro PHP, zero dipendenze.
 *
 * Genera un file XLSX standard compatibile con Excel 2007+, LibreOffice,
 * Google Sheets, Numbers (Apple) e qualsiasi reader OOXML.
 *
 * USO:
 *   $w = new XlsxWriter();
 *   $w->addSheet('Lista', [
 *      ['Nome', 'Età', 'Città'],          // header
 *      ['Mario', 30, 'Roma'],
 *      ['Luigi', 25, 'Milano'],
 *   ]);
 *   $w->addSheet('Dettagli', $altroArray);
 *   $w->download('export.xlsx');
 *
 * Limiti volutamente ridotti per semplicità:
 *   - solo stringhe e numeri (date come stringhe formattate)
 *   - styling base: bold per la prima riga, larghezza colonne autocalcolata
 *   - nessuna formula, nessun chart, nessuna immagine
 *
 * Per file con > 10.000 righe valutare PhpSpreadsheet via Composer.
 */

final class XlsxWriter
{
    /** @var array<string, array> sheets: name => array di righe */
    private array $sheets = [];

    /** @var array<int, string> shared strings (deduplicate) */
    private array $sharedStrings = [];
    private array $stringIndex = [];

    public function addSheet(string $name, array $rows): self
    {
        // Sanitizza nome sheet (max 31 char, no caratteri proibiti)
        $name = self::sanitizeSheetName($name);
        // Evita duplicati
        $base = $name;
        $i = 2;
        while (isset($this->sheets[$name])) {
            $name = substr($base, 0, 28) . " ($i)";
            $i++;
        }
        $this->sheets[$name] = $rows;
        return $this;
    }

    /**
     * Salva il file XLSX e invia al browser come download.
     */
    public function download(string $filename): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->writeToFile($tmpFile);

        // v1.8.17: scarta qualsiasi output/buffer accumulato prima del binario
        // (BOM, spazi, HTML da file inclusi/footer) che altrimenti corromperebbe
        // il file XLSX, e disabilita la compressione per non falsare Content-Length.
        while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');

        // Headers HTTP per download
        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . self::asciiSafe($filename) . '"');
            header('Content-Length: ' . filesize($tmpFile));
            header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        readfile($tmpFile);
        @unlink($tmpFile);
        exit; // impedisce che HTML successivo (es. footer) venga appeso e corrompa il file
    }

    /**
     * Scrive il file XLSX su disco. Usa ZipArchive (built-in PHP).
     */
    public function writeToFile(string $path): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive non disponibile. Abilitare estensione zip in PHP.');
        }

        // v1.7.99: i fogli vengono generati PRIMA di sharedStrings.xml, perché è la
        // costruzione dei fogli a popolare la tabella delle stringhe condivise.
        // (In precedenza le stringhe interneate durante la scrittura dei fogli
        //  finivano fuori dalla tabella già serializzata, producendo indici non
        //  risolvibili e quindi un file segnalato come danneggiato da Excel.)
        $sheetXml = [];
        foreach ($this->sheets as $rows) $sheetXml[] = $this->buildSheetXml($rows);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossibile creare zip: $path");
        }

        // [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', $this->buildContentTypes());

        // _rels/.rels
        $zip->addFromString('_rels/.rels', $this->buildRootRels());

        // xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRels());

        // xl/workbook.xml
        $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());

        // xl/styles.xml (stile bold per la prima riga)
        $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

        // xl/sharedStrings.xml (ora completa: i fogli sono già stati costruiti)
        $zip->addFromString('xl/sharedStrings.xml', $this->buildSharedStringsXml());

        // xl/worksheets/sheetN.xml
        foreach ($sheetXml as $i => $xml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }

        $zip->close();
    }

    // ─── Costruzione XML ────────────────────────────────────────────

    private function buildContentTypes(): string
    {
        $overrides = '';
        $idx = 1;
        foreach ($this->sheets as $_) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $idx . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $idx++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
' . $overrides . '
</Types>';
    }

    private function buildRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private function buildWorkbookRels(): string
    {
        $rels = '';
        $idx = 1;
        $nextId = 1;
        foreach ($this->sheets as $_) {
            $rels .= '<Relationship Id="rId' . $nextId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $idx . '.xml"/>';
            $idx++;
            $nextId++;
        }
        $rels .= '<Relationship Id="rId' . $nextId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $nextId++;
        $rels .= '<Relationship Id="rId' . $nextId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function buildWorkbookXml(): string
    {
        $sheets = '';
        $idx = 1;
        foreach ($this->sheets as $name => $_) {
            $sheets .= '<sheet name="' . self::xmlAttr($name) . '" sheetId="' . $idx . '" r:id="rId' . $idx . '"/>';
            $idx++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets>' . $sheets . '</sheets>
</workbook>';
    }

    private function buildStylesXml(): string
    {
        // 2 stili: 0 = default, 1 = bold (per header)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="2">
  <font><sz val="11"/><name val="Calibri"/></font>
  <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>
</fonts>
<fills count="3">
  <fill><patternFill patternType="none"/></fill>
  <fill><patternFill patternType="gray125"/></fill>
  <fill><patternFill patternType="solid"><fgColor rgb="FF0EA5E9"/><bgColor indexed="64"/></patternFill></fill>
</fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="2">
  <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
  <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
<dxfs count="0"/>
<tableStyles count="0" defaultTableStyle="TableStyleMedium9" defaultPivotStyle="PivotStyleLight16"/>
</styleSheet>';
    }

    private function buildSharedStringsXml(): string
    {
        $items = '';
        foreach ($this->sharedStrings as $s) {
            $items .= '<si><t xml:space="preserve">' . self::xmlText($s) . '</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
     count="' . count($this->sharedStrings) . '"
     uniqueCount="' . count($this->sharedStrings) . '">' . $items . '</sst>';
    }

    private function buildSheetXml(array $rows): string
    {
        // Calcola larghezze colonne per autosizing
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $col => $val) {
                $len = strlen((string)$val);
                if (!isset($widths[$col]) || $widths[$col] < $len) {
                    $widths[$col] = $len;
                }
            }
        }
        $cols = '';
        if ($widths) {
            $cols = '<cols>';
            foreach ($widths as $col => $len) {
                $width = max(10, min(60, $len + 2));
                $cols .= '<col min="' . ($col + 1) . '" max="' . ($col + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        $rowsXml = '';
        foreach ($rows as $rIdx => $row) {
            $cells = '';
            foreach ($row as $cIdx => $val) {
                $cellRef = self::cellRef($cIdx, $rIdx);
                $style = ($rIdx === 0) ? ' s="1"' : '';

                if ($val === null || $val === '') {
                    continue;
                }

                if (self::isNumericCell($val)) {
                    // Numero: normalizzato in notazione decimale accettata da OOXML
                    $cells .= '<c r="' . $cellRef . '"' . $style . '><v>' . self::numericValue($val) . '</v></c>';
                } else {
                    // Stringa (via shared string)
                    $idx = $this->internString((string)$val);
                    $cells .= '<c r="' . $cellRef . '"' . $style . ' t="s"><v>' . $idx . '</v></c>';
                }
            }
            $rowsXml .= '<row r="' . ($rIdx + 1) . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
' . $cols . '
<sheetData>' . $rowsXml . '</sheetData>
</worksheet>';
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * v1.7.99: una cella è numerica se è int/float, oppure una stringa in forma
     * decimale canonica. Restano testo i codici con zeri iniziali significativi
     * (matricole "007", telefoni "0552…"), che Excel altrimenti troncherebbe.
     */
    private static function isNumericCell($val): bool
    {
        if (is_int($val) || is_float($val)) return is_finite((float)$val);
        if (!is_string($val)) return false;
        return (bool)preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $val);
    }

    /** Rappresentazione numerica sicura per il tag <v>. */
    private static function numericValue($val): string
    {
        if (is_int($val)) return (string)$val;
        if (is_float($val)) return rtrim(rtrim(number_format($val, 6, '.', ''), '0'), '.') ?: '0';
        return (string)$val;
    }

    /**
     * Restituisce indice della stringa nella shared strings table, creandola se serve.
     */
    private function internString(string $s): int
    {
        if (isset($this->stringIndex[$s])) return $this->stringIndex[$s];
        $idx = count($this->sharedStrings);
        $this->sharedStrings[] = $s;
        $this->stringIndex[$s] = $idx;
        return $idx;
    }

    /**
     * Converte (col, row) zero-indexed in riferimento Excel (es. "A1", "B5", "AA10").
     */
    private static function cellRef(int $col, int $row): string
    {
        $colName = '';
        $n = $col;
        do {
            $colName = chr(65 + ($n % 26)) . $colName;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return $colName . ($row + 1);
    }

    /**
     * Sanitizza il nome di un foglio: max 31 char, no caratteri proibiti.
     */
    private static function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\*\?\[\]:]/', ' ', $name);
        $name = trim($name);
        if (strlen($name) > 31) $name = substr($name, 0, 31);
        if ($name === '') $name = 'Sheet';
        return $name;
    }

    private static function xmlText(string $s): string
    {
        // XML 1.0 vieta alcuni caratteri di controllo. Li rimuovo.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function xmlAttr(string $s): string
    {
        return self::xmlText($s);
    }

    private static function asciiSafe(string $name): string
    {
        // Per Content-Disposition attributo legacy: convertiamo caratteri non-ASCII
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }
}
