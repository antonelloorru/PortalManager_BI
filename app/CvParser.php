<?php
/**
 * PortalManager 1.7.4 — app/CvParser.php
 *
 * Estrae dati strutturati da CV in formato DOCX, PDF, JPG/PNG (OCR).
 * Usa pattern matching regex orientato al formato Europass italiano.
 *
 * Output: array associativo con campi compatibili con employees / candidates.
 */

class CvParser
{
    private string $rawText = '';
    private array $extracted = [];

    /**
     * Estrae testo dal file e lo elabora.
     * @return array dati estratti con chiavi: first_name, last_name, email, phone,
     *               fiscal_code, date_of_birth, city_of_birth, address,
     *               linkedin_url, credly_url, bio, education_level, education_field,
     *               education_institute, education_year, job_title, technical_skills,
     *               soft_skills, languages[], experiences[], certifications[]
     * @throws RuntimeException
     */
    public function parseFile(string $filepath, string $original_name): array
    {
        if (!is_file($filepath)) throw new RuntimeException("File non trovato: $filepath");
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'docx':
                $this->rawText = self::extractDocxText($filepath);
                break;
            case 'pdf':
                $this->rawText = self::extractPdfText($filepath);
                break;
            case 'jpg':
            case 'jpeg':
            case 'png':
                $this->rawText = self::extractImageText($filepath);
                break;
            case 'txt':
            case 'rtf':
                $this->rawText = file_get_contents($filepath);
                break;
            default:
                throw new RuntimeException("Formato non supportato: .$ext");
        }

        if (trim($this->rawText) === '') {
            throw new RuntimeException("Impossibile estrarre testo dal file. Per immagini, verificare che tesseract OCR sia installato.");
        }

        return $this->extractFields();
    }

    /**
     * Restituisce il testo grezzo estratto (utile per debug).
     */
    public function getRawText(): string
    {
        return $this->rawText;
    }

    // ═════════════════════════════════════════════════════════════════
    // ESTRAZIONE TESTO PER FORMATO
    // ═════════════════════════════════════════════════════════════════

    /**
     * DOCX: legge word/document.xml dallo ZIP, estrae il testo.
     */
    public static function extractDocxText(string $filepath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new RuntimeException("Impossibile aprire DOCX");
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!$xml) throw new RuntimeException("DOCX malformato: manca word/document.xml");

        // Estrai tutto il testo dai tag <w:t>
        $dom = new DOMDocument();
        @$dom->loadXML($xml);
        $nodes = $dom->getElementsByTagName('*');
        $text = '';
        $last_para = '';
        foreach ($dom->getElementsByTagName('p') as $p) {
            $line = '';
            foreach ($p->getElementsByTagName('t') as $t) {
                $line .= $t->nodeValue;
            }
            if (trim($line) !== '') $text .= $line . "\n";
        }
        return $text;
    }

    /**
     * PDF: estrae testo con cascata di 3 strategie agnostiche al sistema operativo:
     *
     *   1. pdftotext via shell  — se presente (massima qualità)
     *   2. Parser PHP-puro v1.7.29 — FlateDecode + tj/TJ + escape sequence
     *      (zero dipendenze binarie, funziona ovunque PHP gira)
     *   3. Regex semplice su BT...ET — solo PDF non compressi (legacy)
     *
     * Throws solo se TUTTE le strategie falliscono.
     */
    public static function extractPdfText(string $filepath): string
    {
        // ── Tentativo 1: pdftotext via shell (più affidabile) ──
        $pdftotext = self::findExecutable(['pdftotext', 'pdftotext.exe']);
        if ($pdftotext) {
            $tmp_out = tempnam(sys_get_temp_dir(), 'pdftxt_');
            $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($filepath) . ' ' . escapeshellarg($tmp_out);
            @shell_exec($cmd . ' 2>&1');
            if (is_file($tmp_out)) {
                $txt = file_get_contents($tmp_out);
                @unlink($tmp_out);
                if (trim($txt) !== '') return $txt;
            }
        }

        // ── Tentativo 2: parser PHP-puro (v1.7.29) ──
        // Estrae tutti gli stream PDF, applica FlateDecode (gzuncompress) se necessario,
        // poi parsa gli operatori Tj/TJ/' /" all'interno dei blocchi BT...ET.
        $content = @file_get_contents($filepath);
        if ($content === false || strpos($content, '%PDF-') === false) {
            throw new RuntimeException("File non riconosciuto come PDF valido.");
        }
        $text = self::extractPdfTextNative($content);
        if (trim($text) !== '') return $text;

        // ── Tentativo 3: regex grezza su BT...ET (PDF non compressi) ──
        $text = '';
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
            foreach ($matches[1] as $block) {
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $block, $tj_matches)) {
                    foreach ($tj_matches[1] as $t) {
                        $text .= stripcslashes($t) . ' ';
                    }
                }
                $text .= "\n";
            }
        }
        if (trim($text) !== '') return $text;

        // Tutti i tentativi falliti
        throw new RuntimeException(
            "Impossibile estrarre testo dal PDF. " .
            "Il file potrebbe essere un'immagine scansionata (richiede OCR via Tesseract) " .
            "oppure usare un encoding non standard. " .
            "Per supporto completo a PDF complessi installare opzionalmente 'poppler-utils' (pdftotext)."
        );
    }

    /**
     * v1.7.31 — Parser PDF PHP-puro con SUPPORTO CMap (font CID encoding).
     *
     * Architettura migliorata rispetto a v1.7.29:
     *   1. Indicizza tutti gli oggetti del PDF (N → contenuto)
     *   2. Per ogni Font object con /ToUnicode, decomprime e parsa la CMap
     *      (beginbfchar / beginbfrange) → mappa code_byte → carattere Unicode
     *   3. Per ogni content stream (pagina), risolve /Resources/Font
     *      (es. /F1 → object 10) → CMap del font
     *   4. Durante il parsing, traccia l'operatore `Tf` per cambiare CMap attiva
     *   5. Applica la CMap del font corrente a ogni stringa estratta
     *
     * Risolve il problema dei PDF Word/LibreOffice con font incorporati che
     * usano CMap personalizzate (i codici nel content stream non sono ASCII
     * standard, vanno mappati attraverso il /ToUnicode CMap).
     */
    public static function extractPdfTextNative(string $content): string
    {
        // ── 1. Indice oggetti PDF (N → raw content) ──
        $objects = self::pdfBuildObjectIndex($content);

        // ── 2. Indice CMaps per object_number ──
        //    Per ogni font con /ToUnicode <N> 0 R, decomprime il CMap
        //    e costruisce mappa: charcode (int) → string UTF-8.
        $cmap_by_font_obj = [];
        foreach ($objects as $obj_num => $obj_content) {
            // Verifica se questo oggetto è un Font con /ToUnicode
            if (!preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $obj_content, $m)) continue;
            $cmap_obj_num = (int)$m[1];
            if (!isset($objects[$cmap_obj_num])) continue;

            $cmap_stream = self::pdfDecodeStream($objects[$cmap_obj_num]);
            if ($cmap_stream === '') continue;

            $cmap = self::pdfParseCMap($cmap_stream);
            if (!empty($cmap)) $cmap_by_font_obj[$obj_num] = $cmap;
        }

        // ── 3. Per ogni content stream, mappa font_name (/Fxxx) → cmap ──
        //    Si trovano i page object (con /Type /Page) o direttamente font
        //    name inline → cmap object_number tramite Resources.
        //
        //    Costruisco mappa: page_obj_num → [font_name => cmap_array]
        $cmap_by_page = [];
        foreach ($objects as $obj_num => $obj_content) {
            // Trova /Font << /F1 10 0 R /F2 11 0 R ... >> dentro Resources
            if (!preg_match('#/Font\s*<<(.*?)>>#s', $obj_content, $m)) continue;
            $font_dict = $m[1];
            $fonts_for_page = [];
            if (preg_match_all('#/(F\d+|[A-Za-z0-9_]+)\s+(\d+)\s+\d+\s+R#', $font_dict, $fm, PREG_SET_ORDER)) {
                foreach ($fm as $fmatch) {
                    $font_name = $fmatch[1];
                    $font_obj = (int)$fmatch[2];
                    if (isset($cmap_by_font_obj[$font_obj])) {
                        $fonts_for_page[$font_name] = $cmap_by_font_obj[$font_obj];
                    }
                }
            }
            if (!empty($fonts_for_page)) {
                $cmap_by_page[$obj_num] = $fonts_for_page;
            }
        }

        // ── 4. Per ogni content stream non-immagine, estrai testo applicando CMap ──
        if (!preg_match_all(
            '/(<<[^<>]*(?:<<[^<>]*>>[^<>]*)*>>)\s*stream\r?\n(.*?)\r?\nendstream/s',
            $content,
            $stream_matches,
            PREG_SET_ORDER
        )) {
            return '';
        }

        $text = '';
        foreach ($stream_matches as $sm) {
            $dict = $sm[1];
            $stream_raw = $sm[2];

            // Skip stream non testuali
            if (preg_match('#/Subtype\s*/Image#', $dict)) continue;
            if (preg_match('#/Type\s*/XObject#', $dict) && !preg_match('#/Subtype\s*/Form#', $dict)) continue;
            // Skip CMap stessi (li ho già processati sopra)
            if (preg_match('#/Type\s*/(?:CMap|Metadata|FontDescriptor|Font)#', $dict)) continue;

            $decoded = self::pdfDecodeStreamData($dict, $stream_raw);
            if ($decoded === '') continue;

            // Cerca CMap candidate (font del page corrente).
            // Algoritmo semplificato: uso TUTTE le CMap della pagina; durante parsing
            // di BT...ET, traccio l'operatore Tf per scegliere quella attiva.
            // Per find la giusta pagina, cerco quale page obj punta a questo content stream
            // — approccio approssimato: passo tutte le CMap disponibili.
            $candidate_cmaps = $cmap_by_page;   // tutte le mappe pagina disponibili

            $text .= self::pdfParseTextObjectsWithCMap($decoded, $candidate_cmaps);
        }

        // Normalizza spazi
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n\s+\n/", "\n\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Costruisce indice oggetti PDF: object_number → contenuto raw.
     */
    private static function pdfBuildObjectIndex(string $content): array
    {
        $objects = [];
        if (preg_match_all('/(\d+)\s+\d+\s+obj\s*(.*?)\s*endobj/s', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $objects[(int)$x[1]] = $x[2];
            }
        }
        return $objects;
    }

    /**
     * Decomprime lo stream di un oggetto PDF (gestisce FlateDecode).
     */
    private static function pdfDecodeStream(string $obj_content): string
    {
        // Estrai dictionary + stream
        if (!preg_match('/(<<.*?>>)\s*stream\r?\n(.*?)\r?\nendstream/s', $obj_content, $m)) {
            return '';
        }
        return self::pdfDecodeStreamData($m[1], $m[2]);
    }

    /**
     * Decodifica stream data in base ai filtri specificati nel dictionary.
     */
    private static function pdfDecodeStreamData(string $dict, string $stream): string
    {
        $is_flate = (bool)preg_match('#/Filter\s*(?:/FlateDecode|\[\s*/FlateDecode)#', $dict);
        $is_ascii85 = (bool)preg_match('#/Filter\s*/ASCII85Decode#', $dict);
        $is_asciihex = (bool)preg_match('#/Filter\s*/ASCIIHexDecode#', $dict);

        if ($is_ascii85)  $stream = self::pdfDecodeAscii85($stream);
        if ($is_asciihex) $stream = @hex2bin(preg_replace('/[^0-9a-fA-F]/', '', $stream)) ?: $stream;
        if ($is_flate) {
            $unc = @gzuncompress($stream);
            if ($unc === false) $unc = @gzinflate($stream);
            if ($unc === false) return '';
            return $unc;
        }
        return $stream;
    }

    /**
     * Parsa un /ToUnicode CMap PDF e restituisce mappa: charcode_int → string UTF-8.
     *
     * Riconosce:
     *   - beginbfchar / endbfchar  : <code> <unicode_hex>
     *   - beginbfrange / endbfrange:
     *       <code_start> <code_end> <unicode_start_hex>       (sequenziale)
     *       <code_start> <code_end> [<u1> <u2> ...]           (lista esplicita)
     */
    private static function pdfParseCMap(string $cmap_stream): array
    {
        $map = [];

        // ── beginbfchar entries ──
        if (preg_match_all('/beginbfchar\s*(.*?)\s*endbfchar/s', $cmap_stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $block, $entries, PREG_SET_ORDER)) {
                    foreach ($entries as $e) {
                        $code = hexdec($e[1]);
                        $unicode = self::pdfHexToUnicode($e[2]);
                        if ($unicode !== '') $map[$code] = $unicode;
                    }
                }
            }
        }

        // ── beginbfrange entries ──
        if (preg_match_all('/beginbfrange\s*(.*?)\s*endbfrange/s', $cmap_stream, $blocks)) {
            foreach ($blocks[1] as $block) {
                // Pattern A: <start> <end> <unicode_start> (sequenziale)
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $block, $entries, PREG_SET_ORDER)) {
                    foreach ($entries as $e) {
                        $start = hexdec($e[1]);
                        $end = hexdec($e[2]);
                        $u_start = hexdec($e[3]);
                        for ($i = $start; $i <= $end; $i++) {
                            $u_hex = dechex($u_start + ($i - $start));
                            $map[$i] = self::pdfHexToUnicode(str_pad($u_hex, 4, '0', STR_PAD_LEFT));
                        }
                    }
                }
                // Pattern B: <start> <end> [<u1> <u2> ...] (lista esplicita)
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*\[([^\]]*)\]/', $block, $entries, PREG_SET_ORDER)) {
                    foreach ($entries as $e) {
                        $start = hexdec($e[1]);
                        $list_str = $e[3];
                        if (preg_match_all('/<([0-9a-fA-F]+)>/', $list_str, $items)) {
                            foreach ($items[1] as $i => $hex) {
                                $map[$start + $i] = self::pdfHexToUnicode($hex);
                            }
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Converte una stringa hex UTF-16BE in UTF-8.
     * Es: "0041" → "A", "0050" → "P", "00e8" → "è"
     */
    private static function pdfHexToUnicode(string $hex): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-f]/', '', $hex));
        if ($hex === '') return '';
        if (strlen($hex) % 4 !== 0) $hex = str_pad($hex, ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT);
        $bin = hex2bin($hex);
        if ($bin === false) return '';
        return mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE') ?: '';
    }

    /**
     * Parser BT...ET con tracciamento font e applicazione CMap.
     */
    private static function pdfParseTextObjectsWithCMap(string $stream, array $cmap_by_page): string
    {
        $out = '';
        if (!preg_match_all('/BT\s+(.*?)\s+ET/s', $stream, $blocks)) {
            return '';
        }

        // Costruisco una mappa globale font_name → cmap (unione di tutte le pagine)
        // perché non sappiamo a quale pagina appartiene questo content stream
        $global_fonts = [];
        foreach ($cmap_by_page as $page_fonts) {
            foreach ($page_fonts as $fname => $cmap) {
                if (!isset($global_fonts[$fname])) {
                    $global_fonts[$fname] = $cmap;
                }
            }
        }

        foreach ($blocks[1] as $block) {
            $current_cmap = null;
            $line = '';
            $pos = 0;
            $len = strlen($block);

            while ($pos < $len) {
                $c = $block[$pos];

                // ── Stringa letterale (...) ──
                if ($c === '(') {
                    $str_bytes = '';
                    $depth = 1;
                    $pos++;
                    while ($pos < $len && $depth > 0) {
                        $ch = $block[$pos];
                        if ($ch === '\\' && $pos + 1 < $len) {
                            $next = $block[$pos + 1];
                            if ($next === 'n') { $str_bytes .= "\n"; $pos += 2; }
                            elseif ($next === 'r') { $str_bytes .= "\r"; $pos += 2; }
                            elseif ($next === 't') { $str_bytes .= "\t"; $pos += 2; }
                            elseif ($next === 'b') { $str_bytes .= "\x08"; $pos += 2; }
                            elseif ($next === 'f') { $str_bytes .= "\x0c"; $pos += 2; }
                            elseif ($next === '(' || $next === ')' || $next === '\\') {
                                $str_bytes .= $next; $pos += 2;
                            }
                            elseif (ctype_digit($next)) {
                                $oct = ''; $pos++;
                                while ($pos < $len && ctype_digit($block[$pos]) && strlen($oct) < 3) {
                                    $oct .= $block[$pos++];
                                }
                                $str_bytes .= chr(octdec($oct));
                            }
                            else { $pos += 2; }
                        }
                        elseif ($ch === '(') { $depth++; $str_bytes .= $ch; $pos++; }
                        elseif ($ch === ')') { $depth--; if ($depth > 0) $str_bytes .= $ch; $pos++; }
                        else { $str_bytes .= $ch; $pos++; }
                    }
                    $line .= self::pdfApplyCMap($str_bytes, $current_cmap);
                }
                // ── Stringa esadecimale <ABCD> ──
                elseif ($c === '<' && $pos + 1 < $len && $block[$pos + 1] !== '<') {
                    $pos++;
                    $hex = '';
                    while ($pos < $len && $block[$pos] !== '>') {
                        if (ctype_xdigit($block[$pos])) $hex .= $block[$pos];
                        $pos++;
                    }
                    if ($pos < $len) $pos++;
                    if (strlen($hex) % 2 === 1) $hex .= '0';
                    $bytes = @hex2bin($hex) ?: '';
                    $line .= self::pdfApplyCMap($bytes, $current_cmap);
                }
                // ── Operatore Tf: cambio font attivo ──
                elseif ($c === '/' && $pos + 1 < $len) {
                    // Estrai nome font: /F1 12 Tf
                    $name_end = $pos + 1;
                    while ($name_end < $len && (ctype_alnum($block[$name_end]) || $block[$name_end] === '_')) {
                        $name_end++;
                    }
                    $font_name = substr($block, $pos + 1, $name_end - $pos - 1);
                    // Cerca " Tf" dopo (dimensione font è opzionale, almeno c'è Tf)
                    $after = substr($block, $name_end, 30);
                    if (preg_match('/^\s+[\d.]+\s+Tf/', $after)) {
                        $current_cmap = $global_fonts[$font_name] ?? null;
                    }
                    $pos = $name_end;
                }
                // ── Operatori testuali T*, Tj, TJ, ', " ──
                elseif ($c === 'T') {
                    if ($pos + 1 < $len && $block[$pos + 1] === '*') {
                        $line .= "\n"; $pos += 2; continue;
                    }
                    $pos++;
                }
                elseif ($c === "'" || $c === '"') {
                    $line .= "\n"; $pos++;
                }
                else {
                    $pos++;
                }
            }
            $out .= $line . "\n";
        }
        return $out;
    }

    /**
     * Applica una CMap a una sequenza di bytes.
     * Se CMap è null o byte non mappato, fa il fallback al decoding standard.
     */
    private static function pdfApplyCMap(string $bytes, ?array $cmap): string
    {
        if (empty($cmap)) {
            // Nessuna CMap → decoding standard
            return self::pdfStringDecode($bytes);
        }

        // Determina se i charcode sono 1 o 2 byte
        $is_2byte = (max(array_keys($cmap)) > 255);

        $out = '';
        $len = strlen($bytes);
        $i = 0;
        while ($i < $len) {
            if ($is_2byte && $i + 1 < $len) {
                $code = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
                $i += 2;
            } else {
                $code = ord($bytes[$i]);
                $i++;
            }
            $out .= $cmap[$code] ?? '';
        }
        return $out;
    }

    /**
     * Parsa i blocchi BT...ET (Text Object) estraendo le stringhe.
     * Riconosce operatori: Tj (singola stringa), TJ (array di stringhe + spacing),
     * ' (newline + Tj), " (newline + spacing + Tj).
     */
    private static function pdfParseTextObjects(string $stream): string
    {
        $out = '';
        // Estrae ogni blocco BT...ET
        if (!preg_match_all('/BT\s+(.*?)\s+ET/s', $stream, $blocks)) {
            return '';
        }

        foreach ($blocks[1] as $block) {
            $line = '';
            $pos = 0;
            $len = strlen($block);

            while ($pos < $len) {
                $c = $block[$pos];

                if ($c === '(') {
                    // Stringa letterale: leggi fino a ')' bilanciato, gestendo escape
                    $depth = 1;
                    $pos++;
                    $str = '';
                    while ($pos < $len && $depth > 0) {
                        $ch = $block[$pos];
                        if ($ch === '\\' && $pos + 1 < $len) {
                            // Escape sequence
                            $next = $block[$pos + 1];
                            if ($next === 'n') { $str .= "\n"; $pos += 2; }
                            elseif ($next === 'r') { $str .= "\r"; $pos += 2; }
                            elseif ($next === 't') { $str .= "\t"; $pos += 2; }
                            elseif ($next === 'b') { $str .= "\x08"; $pos += 2; }
                            elseif ($next === 'f') { $str .= "\x0c"; $pos += 2; }
                            elseif ($next === '(' || $next === ')' || $next === '\\') {
                                $str .= $next; $pos += 2;
                            }
                            elseif (ctype_digit($next)) {
                                // Sequenza ottale \ddd (1-3 cifre)
                                $oct = '';
                                $pos++;
                                while ($pos < $len && ctype_digit($block[$pos]) && strlen($oct) < 3) {
                                    $oct .= $block[$pos++];
                                }
                                $str .= chr(octdec($oct));
                            }
                            else { $pos += 2; }
                        }
                        elseif ($ch === '(') { $depth++; $str .= $ch; $pos++; }
                        elseif ($ch === ')') { $depth--; if ($depth > 0) $str .= $ch; $pos++; }
                        else { $str .= $ch; $pos++; }
                    }
                    $line .= self::pdfStringDecode($str);
                }
                elseif ($c === '<' && $pos + 1 < $len && $block[$pos + 1] !== '<') {
                    // Stringa esadecimale <414243>
                    $pos++;
                    $hex = '';
                    while ($pos < $len && $block[$pos] !== '>') {
                        if (ctype_xdigit($block[$pos])) $hex .= $block[$pos];
                        $pos++;
                    }
                    if ($pos < $len) $pos++;   // skip '>'
                    if (strlen($hex) % 2 === 1) $hex .= '0';
                    $line .= @hex2bin($hex) ?: '';
                }
                elseif ($c === 'T') {
                    // Operatori T*, Tj, TJ, Td, TD, Tm
                    if ($pos + 1 < $len) {
                        $op = $block[$pos + 1];
                        if ($op === '*') { $line .= "\n"; $pos += 2; continue; }
                        if ($op === 'j' || $op === 'J' || $op === 'd' || $op === 'D') {
                            // Già processata l'ultima stringa, niente da fare
                            $pos += 2;
                            continue;
                        }
                    }
                    $pos++;
                }
                elseif ($c === "'" || $c === '"') {
                    // ' = next-line + Tj, " = next-line con spacing + Tj
                    $line .= "\n";
                    $pos++;
                }
                else {
                    $pos++;
                }
            }
            $out .= $line . "\n";
        }
        return $out;
    }

    /**
     * Decodifica una stringa PDF: gestisce PDFDocEncoding/UTF-16BE/Latin-1.
     */
    private static function pdfStringDecode(string $s): string
    {
        if ($s === '') return '';
        // UTF-16BE BOM
        if (substr($s, 0, 2) === "\xFE\xFF") {
            $conv = @mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
            return $conv ?: $s;
        }
        // UTF-8 BOM
        if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
            return substr($s, 3);
        }
        // Tenta UTF-8 valido
        if (mb_check_encoding($s, 'UTF-8')) return $s;
        // Fallback Latin-1 → UTF-8
        $conv = @mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        return $conv ?: $s;
    }

    /**
     * Decodifica ASCII85 (raro nei PDF moderni).
     */
    private static function pdfDecodeAscii85(string $s): string
    {
        $s = preg_replace('/\s+/', '', $s);
        if (strpos($s, '~>') !== false) $s = substr($s, 0, strpos($s, '~>'));
        if (substr($s, 0, 2) === '<~') $s = substr($s, 2);
        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            if ($s[$i] === 'z') {
                $out .= "\0\0\0\0";
                $i++;
                continue;
            }
            $chunk = substr($s, $i, 5);
            $pad = 5 - strlen($chunk);
            $chunk .= str_repeat('u', $pad);
            $sum = 0;
            for ($j = 0; $j < 5; $j++) {
                $sum = $sum * 85 + (ord($chunk[$j]) - 33);
            }
            $bytes = pack('N', $sum);
            $out .= substr($bytes, 0, 4 - $pad);
            $i += 5;
        }
        return $out;
    }

    /**
     * Immagine: usa tesseract OCR via shell.
     */
    public static function extractImageText(string $filepath): string
    {
        $tesseract = self::findExecutable(['tesseract', 'tesseract.exe']);
        if (!$tesseract) {
            throw new RuntimeException(
                "Tesseract OCR non installato. " .
                "Su Windows: scaricare da https://github.com/UB-Mannheim/tesseract/wiki " .
                "Su Linux: apt install tesseract-ocr tesseract-ocr-ita"
            );
        }

        $tmp_base = tempnam(sys_get_temp_dir(), 'ocr_');
        @unlink($tmp_base);
        $cmd = escapeshellcmd($tesseract) . ' ' . escapeshellarg($filepath) . ' ' . escapeshellarg($tmp_base) . ' -l ita+eng 2>&1';
        @shell_exec($cmd);

        $out_file = $tmp_base . '.txt';
        if (!is_file($out_file)) {
            throw new RuntimeException("OCR fallito. Output file non creato.");
        }
        $txt = file_get_contents($out_file);
        @unlink($out_file);
        return $txt;
    }

    /**
     * Cerca un eseguibile nel PATH del sistema.
     */
    private static function findExecutable(array $candidates): ?string
    {
        $cmd = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
        foreach ($candidates as $exe) {
            $out = @shell_exec($cmd . ' ' . escapeshellarg($exe) . ' 2>&1');
            if ($out) {
                $lines = preg_split('/[\r\n]+/', trim($out));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '' && (is_file($line) || stripos($line, $exe) !== false)) {
                        return $line;
                    }
                }
            }
        }
        return null;
    }

    // ═════════════════════════════════════════════════════════════════
    // ESTRAZIONE CAMPI DA TESTO
    // ═════════════════════════════════════════════════════════════════

    private function extractFields(): array
    {
        $t = $this->rawText;
        // Normalizza spazi/caratteri
        $t_clean = preg_replace('/[ \t]+/', ' ', $t);
        $t_clean = preg_replace('/\r\n?/', "\n", $t_clean);   // CR/CRLF -> LF
        $t_clean = preg_replace('/\n{3,}/', "\n\n", $t_clean); // comprimi 3+ in 2 (preserva paragrafi)
        $lines_keep_blank = explode("\n", $t_clean);
        $lines = array_values(array_filter(array_map('trim', $lines_keep_blank), fn($l) => $l !== ''));
        // \$full per i regex di sezione mantiene le righe vuote (doppi \n)
        $full = $t_clean;

        $out = [
            'first_name'          => $this->extractFirstName($lines, $full),
            'last_name'           => $this->extractLastName($lines, $full),
            'email'               => $this->extractEmail($full),
            'phone'               => $this->extractPhone($full),
            'fiscal_code'         => $this->extractFiscalCode($full),
            'date_of_birth'       => $this->extractDateOfBirth($full),
            'city_of_birth'       => $this->extractCityOfBirth($full),
            'address'             => $this->extractAddress($full),
            'linkedin_url'        => $this->extractUrl($full, 'linkedin'),
            'credly_url'          => $this->extractUrl($full, 'credly'),
            'job_title'           => $this->extractJobTitle($lines),
            'bio'                 => $this->extractBio($full),
            'education_level'     => $this->extractEducationLevel($full),
            'education_field'     => $this->extractEducationField($full),
            'education_institute' => $this->extractEducationInstitute($full),
            'education_year'      => $this->extractEducationYear($full),
            'technical_skills'    => $this->extractTechnicalSkills($full),
            'soft_skills'         => $this->extractSoftSkills($full),
            'languages'           => $this->extractLanguages($full),
            'experiences'         => $this->extractExperiences($full),
            'certifications'      => $this->extractCertifications($full),
        ];

        return $out;
    }

    private function extractEmail(string $t): ?string
    {
        if (preg_match('/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/', $t, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractPhone(string $t): ?string
    {
        // Pattern italiano: +39, 39, prefisso 3xx, 0xxx
        if (preg_match('/(?:Telefono|Cellulare|Tel\.?|Mobile|Phone)[:\s]*((?:\+?39[\s.\-]?)?[\d\s.\-]{8,16})/i', $t, $m)) {
            return preg_replace('/[\s.\-]/', ' ', trim($m[1]));
        }
        // Fallback: numero italiano standalone
        if (preg_match('/(?<!\d)(\+?39[\s.\-]?[\d\s.\-]{8,14}|3\d{2}[\s.\-]?\d{6,7})(?!\d)/', $t, $m)) {
            return preg_replace('/[\s.\-]+/', ' ', trim($m[1]));
        }
        return null;
    }

    private function extractFiscalCode(string $t): ?string
    {
        if (preg_match('/\b([A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z])\b/', strtoupper($t), $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractDateOfBirth(string $t): ?string
    {
        // Pattern: "Data di nascita: 15/03/1980" o "Nato il 15 marzo 1980"
        $months = ['gennaio'=>'01','febbraio'=>'02','marzo'=>'03','aprile'=>'04','maggio'=>'05','giugno'=>'06',
                   'luglio'=>'07','agosto'=>'08','settembre'=>'09','ottobre'=>'10','novembre'=>'11','dicembre'=>'12'];

        if (preg_match('/(?:Data di nascita|Nato(?:\/a)? il|Born on|Date of birth)[:\s]*(\d{1,2})[\/\.\s\-]+(\d{1,2}|[a-zA-Zà]+)[\/\.\s\-]+(\d{2,4})/iu', $t, $m)) {
            $d = (int)$m[1];
            $mo_raw = strtolower($m[2]);
            $y = (int)$m[3];
            if ($y < 100) $y += ($y < 30 ? 2000 : 1900);
            $mo = is_numeric($mo_raw) ? (int)$mo_raw : ($months[$mo_raw] ?? null);
            if ($mo && $d >= 1 && $d <= 31 && $y >= 1900 && $y <= date('Y')) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // Fallback: deriva data dal codice fiscale se presente
        $cf = $this->extractFiscalCode($t);
        if ($cf) return $this->birthDateFromFiscalCode($cf);

        return null;
    }

    private function birthDateFromFiscalCode(string $cf): ?string
    {
        if (!preg_match('/^[A-Z]{6}(\d{2})([A-Z])(\d{2})/', $cf, $m)) return null;
        $y = (int)$m[1];
        $month_letter = $m[2];
        $d = (int)$m[3];
        if ($d > 40) $d -= 40; // donna
        $months = ['A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'H'=>6,'L'=>7,'M'=>8,'P'=>9,'R'=>10,'S'=>11,'T'=>12];
        if (!isset($months[$month_letter])) return null;
        $full_year = $y + ($y < 30 ? 2000 : 1900);
        if ($d < 1 || $d > 31) return null;
        return sprintf('%04d-%02d-%02d', $full_year, $months[$month_letter], $d);
    }

    private function extractCityOfBirth(string $t): ?string
    {
        if (preg_match('/(?:Luogo di nascita|Nato\/a a|Nato a|Place of birth)[:\s]*([A-ZÀ-Ÿ][a-zA-Zà-ÿ\s\']+?)(?:\s*\(|,|\n|$)/u', $t, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractAddress(string $t): ?string
    {
        if (preg_match('/(?:Indirizzo|Address|Residenza)[:\s]*([\w\sÀ-ÿ\d,.\'\-\/]+?)(?:\n|Email|Telefono|Tel|Cellulare|$)/iu', $t, $m)) {
            $addr = trim($m[1]);
            if (strlen($addr) >= 6 && strlen($addr) <= 200) return $addr;
        }
        return null;
    }

    private function extractUrl(string $t, string $domain): ?string
    {
        if (preg_match('/(https?:\/\/(?:www\.)?' . preg_quote($domain) . '\.com\/[a-zA-Z0-9_\-\/.]+)/i', $t, $m)) {
            return rtrim($m[1], '.,;');
        }
        return null;
    }

    private function extractFirstName(array $lines, string $t): ?string
    {
        if (preg_match('/(?:Nome|First name|Given name)[:\s]+([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s[A-ZÀ-Ÿ][a-zà-ÿ]+)*)/u', $t, $m)) {
            return trim($m[1]);
        }
        // Heuristic: prima riga del CV se contiene due parole capitalizzate
        foreach (array_slice($lines, 0, 8) as $line) {
            // Skip header Europass
            if (preg_match('/curriculum|europass|vitae|profile/i', $line)) continue;
            if (preg_match('/^([A-ZÀ-Ÿ][a-zà-ÿ\']+)\s+([A-ZÀ-Ÿ][a-zà-ÿ\']+)\s*$/u', $line, $m)) {
                return $m[1];
            }
            // Tutto maiuscolo (anche frequente in Europass): "MARIO ROSSI"
            if (preg_match('/^([A-ZÀ-Ÿ]+)\s+([A-ZÀ-Ÿ]+)\s*$/u', $line, $m)) {
                return ucfirst(strtolower($m[1]));
            }
        }
        return null;
    }

    private function extractLastName(array $lines, string $t): ?string
    {
        if (preg_match('/(?:Cognome|Last name|Surname|Family name)[:\s]+([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s[A-ZÀ-Ÿ][a-zà-ÿ]+)*)/u', $t, $m)) {
            return trim($m[1]);
        }
        foreach (array_slice($lines, 0, 8) as $line) {
            if (preg_match('/curriculum|europass|vitae/i', $line)) continue;
            if (preg_match('/^([A-ZÀ-Ÿ][a-zà-ÿ\']+)\s+([A-ZÀ-Ÿ][a-zà-ÿ\']+)\s*$/u', $line, $m)) {
                return $m[2];
            }
            if (preg_match('/^([A-ZÀ-Ÿ]+)\s+([A-ZÀ-Ÿ]+)\s*$/u', $line, $m)) {
                return ucfirst(strtolower($m[2]));
            }
        }
        return null;
    }

    private function extractJobTitle(array $lines): ?string
    {
        $section_re = '/Esperienza\s+professionale|Esperienze\s+lavorative|Esperienza\s+lavorativa|Work\s+experience|Professional\s+experience/iu';
        $idx = null;
        foreach ($lines as $i => $l) {
            if (preg_match($section_re, $l)) { $idx = $i; break; }
        }
        if ($idx !== null) {
            // Cerca "Qualifica/Posizione/Ruolo" nelle prime 5 righe
            $window = array_slice($lines, $idx + 1, 5);
            foreach ($window as $w) {
                if (preg_match('/(?:Qualifica|Posizione|Ruolo|Job title|Role|Position)[:\s]+(.+)/i', $w, $m)) {
                    return trim($m[1]);
                }
            }
            // Fallback: trova la PRIMA riga subito DOPO il periodo che sembra un job title
            for ($i = $idx + 1; $i < min($idx + 8, count($lines)); $i++) {
                $w = $lines[$i];
                // Skip date
                if (preg_match('/\d{4}.*[—\-–].*(?:\d{4}|presente|attuale|current)/iu', $w)) {
                    // La prossima riga dovrebbe essere il job title
                    if (isset($lines[$i+1]) && strlen($lines[$i+1]) >= 4 && strlen($lines[$i+1]) <= 100) {
                        return trim($lines[$i+1]);
                    }
                }
            }
        }
        return null;
    }

    private function extractBio(string $t): ?string
    {
        // Profilo professionale / About me / Profilo personale - cerca paragrafo fino a sezione successiva
        if (preg_match('/(?:Profilo professionale|Profilo personale|Profilo|Profile|About me|Personal statement|Su di me)\s*\n+(.+?)\n\s*\n\s*(?:Esperienza|Esperienze|Istruzione|Education|Competenze|Skills|Lingue|Languages|Certificazioni|$)/isu', $t, $m)) {
            $bio = trim(preg_replace('/\s+/u', ' ', $m[1]));
            if (strlen($bio) >= 30 && strlen($bio) <= 2000) return $bio;
        }
        return null;
    }

    private function extractEducationLevel(string $t): ?string
    {
        $patterns = [
            '/(Dottorato di ricerca|PhD)/iu',
            '/(Laurea magistrale|Master of Science|MSc|Master\'?s degree)/iu',
            '/(Laurea triennale|Bachelor of Science|BSc|Bachelor\'?s degree)/iu',
            '/(Laurea(?: in)?)/iu',
            '/(Diploma di maturità|Diploma|High school diploma)/iu',
            '/(Master(?: di)? (?:I|II|primo|secondo) livello)/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $t, $m)) return $m[1];
        }
        return null;
    }

    private function extractEducationField(string $t): ?string
    {
        if (preg_match('/Laurea(?:\s+\w+)?\s+in\s+([A-Za-zà-ÿ\s,\-\']+?)(?:\n|presso|@|presso|–|-)/iu', $t, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(?:Indirizzo|Specializzazione|Field of study|Major)[:\s]+([A-Za-zà-ÿ\s,\-\']+)/iu', $t, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractEducationInstitute(string $t): ?string
    {
        if (preg_match('/(Università (?:degli Studi )?(?:di|del|della|degli) [A-Za-zà-ÿ\s\-\']+?)(?:\n|,|–|-)/iu', $t, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(Politecnico di [A-Za-zà-ÿ]+)/iu', $t, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/(?:Istituto|Scuola|School|Institute|University|Università)[:\s]+([A-Za-zà-ÿ\s,\-\']+?)(?:\n|,|$)/iu', $t, $m)) {
            $v = trim($m[1]);
            if (strlen($v) >= 5 && strlen($v) <= 200) return $v;
        }
        return null;
    }

    private function extractEducationYear(string $t): ?string
    {
        // Cerca pattern anno vicino a "Laurea" o "Diploma" (anche su righe diverse)
        if (preg_match('/(?:Laurea|Diploma|Master|PhD|Dottorato).{0,300}?(19\d{2}|20\d{2})/isu', $t, $m)) {
            return $m[1];
        }
        if (preg_match('/(19\d{2}|20\d{2}).{0,200}(?:Laurea|Diploma|Master|PhD|Dottorato)/isu', $t, $m)) {
            return $m[1];
        }
        // Pattern Europass: anno standalone in sezione Istruzione (riga di 4 cifre)
        if (preg_match('/(?:Istruzione e formazione|Education)[:\s\n]+(?:.{0,500}?)\b(19\d{2}|20\d{2})\b/isu', $t, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractTechnicalSkills(string $t): ?string
    {
        if (preg_match('/(?:Competenze tecniche|Competenze digitali|Conoscenze tecniche|Technical skills|Hard skills|Digital skills)[:\s\n]+(.+?)(?:\n\s*\n|Competenze trasversali|Soft skills|Lingue|Esperienza|Istruzione|Certificazioni|$)/isu', $t, $m)) {
            $block = preg_replace('/\s+/u', ' ', $m[1]);
            // Estrai lista separata da virgole, bullet •, pipe |, newline, /
            $items = preg_split('/[•·,;\|]\s*|\n+/u', $block);
            $items = array_filter(array_map('trim', $items), fn($i) => strlen($i) >= 2 && strlen($i) <= 60);
            $items = array_unique(array_slice($items, 0, 30));
            if (!empty($items)) return implode(', ', $items);
        }
        return null;
    }

    private function extractSoftSkills(string $t): ?string
    {
        if (preg_match('/(?:Competenze trasversali|Competenze personali|Soft skills|Personal skills|Capacità personali)[:\s\n]+(.+?)(?:\n\s*\n|Competenze tecniche|Lingue|Esperienza|Istruzione|Certificazioni|$)/isu', $t, $m)) {
            $block = preg_replace('/\s+/u', ' ', $m[1]);
            $items = preg_split('/[•·,;\|]\s*|\n+/u', $block);
            $items = array_filter(array_map('trim', $items), fn($i) => strlen($i) >= 2 && strlen($i) <= 80);
            $items = array_unique(array_slice($items, 0, 20));
            if (!empty($items)) return implode(', ', $items);
        }
        return null;
    }

    private function extractLanguages(string $t): array
    {
        $out = [];
        // Pattern Europass: "Inglese B2/B2/C1/B2/B2" o "Inglese — livello B2"
        if (preg_match('/(?:Lingue|Lingua|Competenze linguistiche|Languages|Language skills|Conoscenze linguistiche)[:\s\n]+(.+?)(?:\n\s*\n|Competenze|Esperienza|Istruzione|Certificazioni|$)/isu', $t, $m)) {
            $block = $m[1];
            $lang_names = ['Italiano','Inglese','Francese','Tedesco','Spagnolo','Portoghese','Russo','Arabo','Cinese','Giapponese','Olandese','Greco','Polacco','Rumeno','Albanese','Ucraino','Turco','English','French','German','Spanish','Italian','Portuguese','Chinese','Japanese','Russian'];

            foreach ($lang_names as $ln) {
                if (preg_match('/\b' . preg_quote($ln) . '\b(.{0,180})/iu', $block, $lm)) {
                    $context = $lm[1];
                    $mother = preg_match('/madrelingua|mother\s*tongue|native/iu', $context) ? 1 : 0;
                    // Cerca livelli CEFR (A1/A2/B1/B2/C1/C2)
                    $levels = [];
                    if (preg_match_all('/\b([ABC][12])\b/i', $context, $lvs)) {
                        $levels = array_slice(array_map('strtoupper', $lvs[1]), 0, 5);
                    }
                    while (count($levels) < 5) $levels[] = null;

                    $out[] = [
                        'language_name' => $ln,
                        'mother_tongue' => $mother,
                        'level_listening'          => $levels[0],
                        'level_reading'            => $levels[1],
                        'level_spoken_interaction' => $levels[2],
                        'level_spoken_production'  => $levels[3],
                        'level_writing'            => $levels[4],
                    ];
                }
            }
        }
        // Dedup case-insensitive
        $seen = []; $result = [];
        foreach ($out as $l) {
            $k = strtolower($l['language_name']);
            if (!isset($seen[$k])) { $seen[$k] = true; $result[] = $l; }
        }
        return $result;
    }

    private function extractExperiences(string $t): array
    {
        $out = [];
        if (!preg_match('/(?:Esperienza professionale|Esperienze lavorative|Esperienza lavorativa|Work experience|Professional experience)[:\s\n]+(.+?)(?:\n\s*\n\s*(?:Istruzione|Education|Competenze|Skills|Lingue|Languages|Certificazioni|$))/isu', $t, $m)) {
            return $out;
        }
        $block = $m[1];

        // Trovo TUTTI i pattern periodo nel blocco
        $period_re = '/((?:\d{1,2}[\/\-\.])?\d{4})\s*[\-—–]\s*((?:\d{1,2}[\/\-\.])?(?:\d{4}|presente|attuale|current))/iu';
        preg_match_all($period_re, $block, $periods, PREG_OFFSET_CAPTURE);

        if (empty($periods[0])) {
            // Nessun periodo trovato: ritorno il blocco intero come una sola esperienza
            $clean = trim(preg_replace('/\s+/u', ' ', $block));
            if (strlen($clean) >= 5) {
                $out[] = ['period' => null, 'title' => substr($clean, 0, 120), 'company' => null];
            }
            return $out;
        }

        // Per ogni periodo, estraggo le righe dopo (fino al prossimo periodo)
        for ($i = 0; $i < count($periods[0]); $i++) {
            $start = $periods[0][$i][1];
            $end = isset($periods[0][$i + 1]) ? $periods[0][$i + 1][1] : strlen($block);
            $entry_text = substr($block, $start, $end - $start);
            $entry_lines = array_filter(array_map('trim', explode("\n", $entry_text)));
            $entry_lines = array_values($entry_lines);

            $period = trim($periods[1][$i][0]) . ' – ' . trim($periods[2][$i][0]);
            $title = null;
            $company = null;

            // Prima riga = periodo (la salto); cerco titolo e azienda nelle successive
            foreach (array_slice($entry_lines, 1) as $l) {
                if (preg_match($period_re, $l)) continue;
                if (!$title && strlen($l) >= 4 && strlen($l) <= 120) { $title = $l; continue; }
                if (!$company && strlen($l) >= 3 && strlen($l) <= 200) { $company = $l; break; }
            }

            // Se title contiene un periodo, era inglobato sulla stessa riga
            if ($title === null && !empty($entry_lines[0])) {
                $first_clean = trim(preg_replace($period_re, '', $entry_lines[0]));
                if (strlen($first_clean) >= 4) $title = $first_clean;
            }

            if ($period || $title || $company) {
                $out[] = [
                    'period'  => $period,
                    'title'   => $title,
                    'company' => $company,
                ];
            }
            if (count($out) >= 10) break;
        }

        return $out;
    }

    private function extractCertifications(string $t): array
    {
        $out = [];
        if (!preg_match('/(?:Certificazioni|Certifications|Attestati|Certificates)[:\s\n]+(.+?)(?:\n\s*\n|Competenze|Lingue|Esperienza|Istruzione|$)/isu', $t, $m)) {
            return $out;
        }
        $block = $m[1];
        $lines = array_filter(array_map('trim', explode("\n", $block)));

        // Pattern Europass: "Cisco CCNA — 2024" oppure "AWS Solutions Architect (2023)"
        foreach ($lines as $line) {
            // Skip righe troppo corte
            if (strlen($line) < 8 || strlen($line) > 250) continue;
            // Cerca anno
            $year = null;
            if (preg_match('/(19\d{2}|20\d{2})/', $line, $ym)) $year = $ym[1];

            // Brand: prima parola/sigla nota
            $brand = null;
            $known = ['Cisco','Microsoft','AWS','Amazon','Google','Oracle','VMware','Red Hat','RedHat','IBM','Salesforce','HP','HPE','Dell','Fortinet','Palo Alto','Juniper','Citrix','SAP','PMI','Scrum','Cloudera','Splunk','Acronis','Broadcom','Symantec','McAfee','Sophos','Veeam','NetApp','Pure Storage'];
            foreach ($known as $b) {
                if (stripos($line, $b) !== false) { $brand = $b; break; }
            }

            // Pulisci nome (rimuovi anno e brand)
            $name = trim($line);
            if ($year)  $name = trim(preg_replace('/[\(\[\{]?\s*' . preg_quote($year) . '\s*[\)\]\}]?/', '', $name));
            $name = trim($name, " \t\n\r\0\x0B-—–:•·");

            if (strlen($name) >= 4) {
                $out[] = [
                    'name'        => $name,
                    'brand'       => $brand,
                    'issue_year'  => $year,
                ];
            }
            if (count($out) >= 50) break;
        }

        return $out;
    }
}
