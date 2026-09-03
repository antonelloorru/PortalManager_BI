<?php
/**
 * app/XlsxReader.php — lettore XLSX nativo (ZipArchive + XMLReader, no dipendenze).
 *
 * v1.7.62: riscritto in streaming. La versione precedente caricava in memoria
 * l'intero sheet XML decompresso e ne costruiva il DOM con SimpleXML: su file
 * grandi (decine di MB compressi = centinaia di MB di XML) esauriva memory_limit.
 * Ora il foglio viene letto a flusso con XMLReader sullo stream zip://, con
 * consumo di memoria costante rispetto alla dimensione del foglio.
 *
 * v1.7.65: individuazione automatica della riga di intestazione. Prima veniva
 * assunta la prima riga non vuota: gli export con una riga di titolo iniziale
 * (es. "Rapporti intervento per il periodo fino al ...") facevano fallire la
 * mappatura ("Colonna N. non trovata"). Ora la riga viene RICONOSCIUTA, anche
 * in presenza di titoli e righe vuote sopra.
 *
 * API:
 *   XlsxReader::each($path, fn(array $row, int $n) => ..., 0, $headers, $opts) → streaming
 *   XlsxReader::read($path)                                                    → ['headers'=>[], 'rows'=>[]]
 *
 * $opts:
 *   'header_hints'     => string[]  intestazioni attese (match normalizzato); consigliato
 *   'header_scan_rows' => int       righe massime da scandire (default 25)
 *   'min_header_cells' => int       celle minime perché una riga sia intestazione (default 2)
 */
final class XlsxReader
{
    /** Legge tutto in memoria (compat). Per file grandi preferire each(). */
    public static function read(string $path, int $sheetIndex = 0, array $opts = []): array
    {
        $headers = [];
        $rows = [];
        self::each($path, function (array $row) use (&$rows) { $rows[] = $row; }, $sheetIndex, $headers, $opts);
        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Normalizzazione usata per il confronto delle intestazioni. */
    public static function norm(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** Decide se la riga (celle colonna=>valore) è l'intestazione. */
    private static function looksLikeHeader(array $cells, array $hints, int $minCells): bool
    {
        if ($hints) {
            $hit = 0;
            foreach ($cells as $v) if (in_array(self::norm((string)$v), $hints, true)) $hit++;
            if ($hit >= 2) return true;
            // intestazione con pochissime colonne attese: basta un match su riga "larga"
            if ($hit >= 1 && count($cells) >= $minCells && count($hints) <= 2) return true;
            return false;
        }
        return count($cells) >= $minCells;
    }

    /**
     * Scorre le righe dati (esclusa l'intestazione) invocando $cb($row, $n).
     * $row è associativo header => valore (stringa). Se $cb restituisce false, interrompe.
     * @return int numero di righe processate
     */
    public static function each(string $path, callable $cb, int $sheetIndex = 0, ?array &$headersOut = null, array $opts = []): int
    {
        if (!class_exists('ZipArchive')) throw new \RuntimeException('Estensione ZipArchive non disponibile.');
        if (!class_exists('XMLReader')) throw new \RuntimeException('Estensione XMLReader non disponibile.');
        if (!is_readable($path)) throw new \RuntimeException('XLSX non leggibile: ' . $path);

        $hints    = array_map([self::class, 'norm'], (array)($opts['header_hints'] ?? []));
        $scanMax  = (int)($opts['header_scan_rows'] ?? 25);
        $minCells = (int)($opts['min_header_cells'] ?? 2);

        $sheetPath = self::sheetPath($path, $sheetIndex);
        $shared    = self::sharedStrings($path);

        $xml = new \XMLReader();
        if (!@$xml->open('zip://' . $path . '#' . $sheetPath)) {
            throw new \RuntimeException('Foglio non apribile nel file XLSX.');
        }

        // ── Fase 1: individua la riga di intestazione entro le prime $scanMax righe ──
        // Le righe scandite prima dell'intestazione sono titoli/righe vuote e vengono
        // scartate; quelle eventualmente successive restano in buffer come dati.
        $buffer = [];
        $headerIdx = null;
        while ($xml->read()) {
            if ($xml->nodeType !== \XMLReader::ELEMENT || $xml->name !== 'row') continue;
            $cells = self::readRow($xml, $shared);
            if (!$cells) continue;
            $buffer[] = $cells;
            if (self::looksLikeHeader($cells, $hints, $minCells)) { $headerIdx = count($buffer) - 1; break; }
            if (count($buffer) >= $scanMax) break;
        }
        if ($headerIdx === null && $buffer) {
            // Nessun riscontro sugli hint: ripiega sulla riga più "larga" tra quelle scandite.
            $bestN = -1;
            foreach ($buffer as $k => $c) { if (count($c) > $bestN) { $bestN = count($c); $headerIdx = $k; } }
        }
        if ($headerIdx === null) { $xml->close(); $headersOut = []; return 0; }

        $headerCells = $buffer[$headerIdx];
        uksort($headerCells, function ($a, $b) { return self::colNum($a) <=> self::colNum($b); });
        $headerCols = array_keys($headerCells);
        $headers    = array_values($headerCells);

        $n = 0;
        $emit = function (array $cells) use (&$n, $cb, $headerCols, $headers) {
            $assoc = [];
            foreach ($headerCols as $i => $col) $assoc[$headers[$i]] = $cells[$col] ?? '';
            if (implode('', $assoc) === '') return true;
            $n++;
            return $cb($assoc, $n) !== false;
        };

        // ── Fase 2: righe già in buffer dopo l'intestazione ──
        $stop = false;
        foreach (array_slice($buffer, $headerIdx + 1) as $cells) {
            if (!$emit($cells)) { $stop = true; break; }
        }
        unset($buffer);

        // ── Fase 3: prosecuzione in streaming ──
        if (!$stop) {
            while ($xml->read()) {
                if ($xml->nodeType !== \XMLReader::ELEMENT || $xml->name !== 'row') continue;
                $cells = self::readRow($xml, $shared);
                if (!$cells) continue;
                if (!$emit($cells)) break;
            }
        }
        $xml->close();
        unset($shared);

        $headersOut = $headers;
        return $n;
    }

    /** Legge le celle della <row> corrente. @return array<string,string> colonna=>valore */
    private static function readRow(\XMLReader $xml, array $shared): array
    {
        $cells = [];
        if ($xml->isEmptyElement) return $cells;

        $depth = $xml->depth;
        while ($xml->read()) {
            if ($xml->nodeType === \XMLReader::END_ELEMENT && $xml->name === 'row' && $xml->depth === $depth) break;
            if ($xml->nodeType !== \XMLReader::ELEMENT || $xml->name !== 'c') continue;

            $ref  = (string)$xml->getAttribute('r');
            $type = (string)$xml->getAttribute('t');
            $col  = self::colOf($ref);

            $val = '';
            if (!$xml->isEmptyElement) {
                $cDepth = $xml->depth;
                while ($xml->read()) {
                    if ($xml->nodeType === \XMLReader::END_ELEMENT && $xml->name === 'c' && $xml->depth === $cDepth) break;
                    if ($xml->nodeType !== \XMLReader::ELEMENT) continue;
                    if ($xml->name === 'v') {
                        $raw = $xml->readString();
                        $val = ($type === 's') ? ($shared[(int)$raw] ?? '') : $raw;
                    } elseif ($xml->name === 'is') {
                        $val = self::plainText($xml->readInnerXml());
                    }
                }
            }
            $val = trim($val);
            if ($val !== '') $cells[$col] = $val;
        }
        return $cells;
    }

    /** sharedStrings.xml letto a flusso. @return array<int,string> */
    private static function sharedStrings(string $path): array
    {
        $out = [];
        $xml = new \XMLReader();
        if (!@$xml->open('zip://' . $path . '#xl/sharedStrings.xml')) return $out;
        while ($xml->read()) {
            if ($xml->nodeType === \XMLReader::ELEMENT && $xml->name === 'si') {
                $out[] = $xml->isEmptyElement ? '' : self::plainText($xml->readInnerXml());
            }
        }
        $xml->close();
        return $out;
    }

    /** Estrae il testo da un frammento <si>/<is> (gestisce i rich-text run <r><t>). */
    private static function plainText(string $innerXml): string
    {
        if ($innerXml === '') return '';
        if (strpos($innerXml, '<') === false) return html_entity_decode($innerXml, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $t = '';
        if (preg_match_all('#<t[^>]*>(.*?)</t>#s', $innerXml, $m)) {
            foreach ($m[1] as $chunk) $t .= $chunk;
        }
        return html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Individua il path del foglio dal workbook (file piccolo, lettura diretta). */
    private static function sheetPath(string $path, int $index): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('XLSX non apribile: ' . $path);
        $target = 'xl/worksheets/sheet' . ($index + 1) . '.xml';

        $wb = $zip->getFromName('xl/workbook.xml');
        if ($wb !== false) {
            $x = @simplexml_load_string($wb);
            if ($x && isset($x->sheets->sheet[$index])) {
                $ns  = $x->sheets->sheet[$index]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rid = isset($ns->id) ? (string)$ns->id : '';
                $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
                if ($rid !== '' && $rels !== false) {
                    $rx = @simplexml_load_string($rels);
                    if ($rx) foreach ($rx->Relationship as $rel) {
                        if ((string)$rel['Id'] === $rid) {
                            $t = (string)$rel['Target'];
                            $target = strpos($t, '/') === 0 ? ltrim($t, '/') : 'xl/' . $t;
                            break;
                        }
                    }
                }
            }
        }
        $zip->close();
        return $target;
    }

    private static function colOf(string $ref)
    {
        return preg_match('/^([A-Z]+)/', $ref, $m) ? $m[1] : 'A';
    }
    private static function colNum(string $col): int
    {
        $n = 0;
        foreach (str_split($col) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }
}
