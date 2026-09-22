<?php
/**
 * PortalManager — app/XlsReader.php
 *
 * Lettore .xls (Excel 97-2003, BIFF8) in PHP puro, ZERO dipendenze
 * (stessa filosofia di XlsxReader). Legge il contenitore OLE2/CFB, estrae lo
 * stream "Workbook" e interpreta i record BIFF8 necessari per ricostruire la
 * griglia del foglio: SST (stringhe condivise), LABELSST, LABEL, RK, MULRK,
 * NUMBER, BOUNDSHEET.
 *
 * API compatibile con XlsxReader::read():
 *   $res = XlsReader::read($path, 0, ['header_hints'=>[...], 'header_scan_rows'=>30]);
 *   // $res = ['headers'=>[...], 'rows'=>[ ['Header'=>valore, ...], ... ]]
 */
final class XlsReader
{
    /**
     * @return array{headers:string[],rows:array<int,array<string,mixed>>}
     */
    public static function read(string $path, int $sheetIndex = 0, array $opts = []): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) throw new RuntimeException("Impossibile leggere: $path");
        if (substr($raw, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('Non è un file .xls (OLE2) valido.');
        }
        $wb = self::extractWorkbookStream($raw);
        $grid = self::parseBiff($wb, $sheetIndex);

        // costruzione headers/rows con individuazione riga intestazione
        $hints = array_map('self::norm', $opts['header_hints'] ?? []);
        $scan  = (int)($opts['header_scan_rows'] ?? 30);
        $maxRow = 0; $maxCol = 0;
        foreach ($grid as $r => $cols) { $maxRow = max($maxRow, $r); foreach ($cols as $c => $_) $maxCol = max($maxCol, $c); }

        $hdrRow = 0; $best = -1;
        for ($r = 0; $r <= min($scan, $maxRow); $r++) {
            $cells = $grid[$r] ?? [];
            $vals = array_map(fn($v) => self::norm((string)$v), $cells);
            $nonEmpty = count(array_filter($cells, fn($v) => $v !== null && $v !== ''));
            $hit = $hints ? count(array_intersect($hints, $vals)) : 0;
            $score = $hit * 1000 + $nonEmpty;
            if ($score > $best) { $best = $score; $hdrRow = $r; }
            if ($hints && $hit >= max(2, (int)floor(count($hints) / 2))) { $hdrRow = $r; break; }
        }

        $headers = [];
        for ($c = 0; $c <= $maxCol; $c++) $headers[$c] = trim((string)($grid[$hdrRow][$c] ?? ''));

        $rows = [];
        for ($r = $hdrRow + 1; $r <= $maxRow; $r++) {
            $assoc = []; $any = false;
            foreach ($headers as $c => $h) {
                if ($h === '') continue;
                $v = $grid[$r][$c] ?? null;
                $assoc[$h] = $v;
                if ($v !== null && $v !== '') $any = true;
            }
            if ($any) $rows[] = $assoc;
        }
        return ['headers' => array_values($headers), 'rows' => $rows];
    }

    // ── OLE2 / CFB ────────────────────────────────────────────────────
    private static function extractWorkbookStream(string $d): string
    {
        $u16 = fn($o) => unpack('v', substr($d, $o, 2))[1];
        $u32 = fn($o) => unpack('V', substr($d, $o, 4))[1];

        $secShift = $u16(30);  $secSize  = 1 << $secShift;   // tipicamente 512
        $miniShift = $u16(32); $miniSize = 1 << $miniShift;  // tipicamente 64
        $numFat   = $u32(44);
        $dirStart = $u32(48);
        $miniCutoff = $u32(56);
        $miniFatStart = $u32(60); $miniFatCount = $u32(64);
        $difatStart = $u32(68);   $difatCount = $u32(72);

        $secOff = fn($s) => 512 + $s * $secSize;

        // DIFAT: 109 entry nell'header + eventuali sector DIFAT
        $difat = array_values(unpack('V109', substr($d, 76, 436)));
        $sid = $difatStart;
        for ($i = 0; $i < $difatCount && $sid !== 0xFFFFFFFE && $sid !== 0xFFFFFFFF; $i++) {
            $sect = substr($d, $secOff($sid), $secSize);
            $ent  = array_values(unpack('V' . ($secSize / 4), $sect));
            $next = array_pop($ent);
            foreach ($ent as $e) $difat[] = $e;
            $sid = $next;
        }

        // FAT
        $fat = [];
        $seen = 0;
        foreach ($difat as $fs) {
            if ($fs === 0xFFFFFFFF || $fs === 0xFFFFFFFE) continue;
            if ($seen++ >= $numFat) break;
            $sect = substr($d, $secOff($fs), $secSize);
            foreach (array_values(unpack('V' . ($secSize / 4), $sect)) as $e) $fat[] = $e;
        }

        $readChain = function (int $start) use ($d, $fat, $secOff, $secSize): string {
            $out = ''; $s = $start; $guard = 0;
            while ($s !== 0xFFFFFFFE && $s !== 0xFFFFFFFF && isset($fat[$s]) && $guard++ < 2000000) {
                $out .= substr($d, $secOff($s), $secSize);
                $s = $fat[$s];
            }
            return $out;
        };

        // directory
        $dir = $readChain($dirStart);
        $entries = [];
        for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
            $nameLen = unpack('v', substr($dir, $o + 64, 2))[1];
            if ($nameLen <= 0) continue;
            $nameU = substr($dir, $o, max(0, $nameLen - 2));
            $name = @iconv('UTF-16LE', 'UTF-8//IGNORE', $nameU) ?: '';
            $entries[] = [
                'name'  => $name,
                'type'  => ord($dir[$o + 66]),
                'start' => unpack('V', substr($dir, $o + 116, 4))[1],
                'size'  => unpack('V', substr($dir, $o + 120, 4))[1],
            ];
        }

        // root entry (contiene il mini-stream) + workbook
        $root = null; $wbe = null;
        foreach ($entries as $e) {
            if ($e['type'] === 5) $root = $e;
            if ($e['type'] === 2 && ($e['name'] === 'Workbook' || $e['name'] === 'Book')) $wbe = $e;
        }
        if (!$wbe) throw new RuntimeException('Stream "Workbook" non trovato nel file .xls.');

        if ($wbe['size'] >= $miniCutoff) {
            return substr($readChain($wbe['start']), 0, $wbe['size']);
        }

        // stream piccolo: mini-FAT
        $miniStream = $root ? $readChain($root['start']) : '';
        $miniFat = [];
        foreach (str_split($readChain($miniFatStart), 4) as $b) {
            if (strlen($b) === 4) $miniFat[] = unpack('V', $b)[1];
        }
        $out = ''; $s = $wbe['start']; $g = 0;
        while ($s !== 0xFFFFFFFE && $s !== 0xFFFFFFFF && isset($miniFat[$s]) && $g++ < 2000000) {
            $out .= substr($miniStream, $s * $miniSize, $miniSize);
            $s = $miniFat[$s];
        }
        return substr($out, 0, $wbe['size']);
    }

    // ── BIFF8 ─────────────────────────────────────────────────────────
    /** @return array<int,array<int,mixed>> grid[row][col] */
    private static function parseBiff(string $wb, int $sheetIndex): array
    {
        // Pass 1 (globals): raccogli SST e BOUNDSHEET
        [$sst, $sheets] = self::parseGlobals($wb);

        // scegli lo stream del foglio richiesto
        $sheetOffsets = array_column($sheets, 'pos');
        $start = $sheetOffsets[$sheetIndex] ?? ($sheetOffsets[0] ?? null);
        if ($start === null) {
            // nessun BOUNDSHEET: parse dell'intero stream come unico foglio
            return self::parseCells($wb, 0, strlen($wb), $sst);
        }
        // fine foglio = inizio del successivo o fine stream
        $ends = $sheetOffsets; sort($ends);
        $end = strlen($wb);
        foreach ($ends as $p) { if ($p > $start) { $end = $p; break; } }
        return self::parseCells($wb, $start, $end, $sst);
    }

    /** @return array{0:string[],1:array<int,array{pos:int,name:string}>} */
    private static function parseGlobals(string $wb): array
    {
        $len = strlen($wb);
        $pos = 0;
        $sst = [];
        $sheets = [];
        // il primo record è il BOF dei globals; leggiamo finché non incontriamo
        // il primo BOF di foglio (i BOUNDSHEET stanno nei globals, prima).
        while ($pos + 4 <= $len) {
            $type = unpack('v', substr($wb, $pos, 2))[1];
            $size = unpack('v', substr($wb, $pos + 2, 2))[1];
            $data = substr($wb, $pos + 4, $size);

            if ($type === 0x0085) { // BOUNDSHEET
                $streamPos = unpack('V', substr($data, 0, 4))[1];
                // nome: offset 6 (cch u8, grbit u8, chars)
                $name = self::readShortString($data, 6);
                $sheets[] = ['pos' => $streamPos, 'name' => $name];
            } elseif ($type === 0x00FC) { // SST (+ CONTINUE)
                // accorpa SST + eventuali CONTINUE consecutivi mantenendo i confini
                $chunks = [$data];
                $p2 = $pos + 4 + $size;
                while ($p2 + 4 <= $len) {
                    $t2 = unpack('v', substr($wb, $p2, 2))[1];
                    $s2 = unpack('v', substr($wb, $p2 + 2, 2))[1];
                    if ($t2 !== 0x003C) break; // CONTINUE
                    $chunks[] = substr($wb, $p2 + 4, $s2);
                    $p2 += 4 + $s2;
                }
                $sst = self::parseSST($chunks);
                $pos = $p2;
                continue;
            } elseif ($type === 0x000A && $sheets) {
                // EOF dei globals: i record successivi sono nei fogli
                $pos += 4 + $size;
                break;
            }
            $pos += 4 + $size;
        }
        return [$sst, $sheets];
    }

    /** Parsa le celle di un foglio nell'intervallo [start,end). */
    private static function parseCells(string $wb, int $start, int $end, array $sst): array
    {
        $grid = [];
        $pos = $start;
        while ($pos + 4 <= $end) {
            $type = unpack('v', substr($wb, $pos, 2))[1];
            $size = unpack('v', substr($wb, $pos + 2, 2))[1];
            $data = substr($wb, $pos + 4, $size);
            $pos += 4 + $size;

            switch ($type) {
                case 0x000A: // EOF foglio
                    return $grid;
                case 0x00FD: // LABELSST
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $isst = unpack('V', substr($data, 6, 4))[1];
                    $grid[$row][$col] = $sst[$isst] ?? '';
                    break;
                case 0x0204: // LABEL (stringa inline BIFF8)
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $grid[$row][$col] = self::readUnicodeString($data, 6);
                    break;
                case 0x0203: // NUMBER (double)
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $grid[$row][$col] = unpack('d', substr($data, 6, 8))[1];
                    break;
                case 0x027E: // RK
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    $grid[$row][$col] = self::rkValue(unpack('V', substr($data, 6, 4))[1]);
                    break;
                case 0x00BD: // MULRK
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $colFirst = unpack('v', substr($data, 2, 2))[1];
                    $n = (int)((strlen($data) - 6) / 6);
                    for ($i = 0; $i < $n; $i++) {
                        $rk = unpack('V', substr($data, 4 + $i * 6 + 2, 4))[1];
                        $grid[$row][$colFirst + $i] = self::rkValue($rk);
                    }
                    break;
                case 0x0006: // FORMULA — risultato numerico inline; le stringhe arrivano via STRING
                    $row = unpack('v', substr($data, 0, 2))[1];
                    $col = unpack('v', substr($data, 2, 2))[1];
                    // se i 2 byte @12-13 sono 0xFFFF il risultato è testo/bool/errore
                    if (substr($data, 12, 2) !== "\xFF\xFF") {
                        $grid[$row][$col] = unpack('d', substr($data, 6, 8))[1];
                    } else {
                        $grid[$row][$col] = ''; // il valore reale segue nel record STRING (0x0207)
                        $lastFormulaCell = [$row, $col];
                    }
                    break;
                case 0x0207: // STRING (risultato testuale della formula precedente)
                    if (!empty($lastFormulaCell)) {
                        [$r, $c] = $lastFormulaCell;
                        $grid[$r][$c] = self::readUnicodeString($data, 0);
                        $lastFormulaCell = null;
                    }
                    break;
                default:
                    // BLANK/MULBLANK/ROW/altri: ignora
                    break;
            }
        }
        return $grid;
    }

    // ── SST / stringhe BIFF8 ──────────────────────────────────────────
    /**
     * Parsa la SST attraverso i chunk (record SST + CONTINUE), gestendo il
     * flag di compressione ripetuto al confine dei CONTINUE.
     * @param string[] $chunks
     * @return string[]
     */
    private static function parseSST(array $chunks): array
    {
        // buffer unico + lista dei confini (offset assoluti dove inizia un CONTINUE)
        $buf = '';
        $bounds = [];
        foreach ($chunks as $i => $c) {
            if ($i > 0) $bounds[strlen($buf)] = true;
            $buf .= $c;
        }
        $len = strlen($buf);
        $p = 0;
        $cstUnique = unpack('V', substr($buf, 4, 4))[1];
        $p = 8;

        $strings = [];
        for ($n = 0; $n < $cstUnique && $p + 3 <= $len; $n++) {
            $cch = unpack('v', substr($buf, $p, 2))[1]; $p += 2;
            $grbit = ord($buf[$p]); $p += 1;
            $rich = ($grbit & 0x08) ? unpack('v', substr($buf, $p, 2))[1] : 0;
            if ($grbit & 0x08) $p += 2;
            $extsz = ($grbit & 0x04) ? unpack('V', substr($buf, $p, 4))[1] : 0;
            if ($grbit & 0x04) $p += 4;

            $high = ($grbit & 0x01) === 0x01; // 1 = 16-bit (uncompressed)
            $out = '';
            $rem = $cch;
            while ($rem > 0) {
                // quanti caratteri restano prima del prossimo confine CONTINUE?
                $nextBound = self::nextBoundary($bounds, $p, $len);
                $bytesToBound = $nextBound - $p;
                $charSize = $high ? 2 : 1;
                $canChars = intdiv($bytesToBound, $charSize);
                if ($canChars <= 0) {
                    // siamo esattamente al confine: leggi il nuovo flag e continua
                    if (isset($bounds[$p])) { $high = (ord($buf[$p]) & 0x01) === 0x01; $p += 1; continue; }
                    break;
                }
                $take = min($rem, $canChars);
                $slice = substr($buf, $p, $take * $charSize);
                $p += $take * $charSize;
                $rem -= $take;
                $out .= $high
                    ? (@iconv('UTF-16LE', 'UTF-8//IGNORE', $slice) ?: '')
                    : self::latin1ToUtf8($slice);
                if ($rem > 0 && isset($bounds[$p])) { // confine nel mezzo della stringa
                    $high = (ord($buf[$p]) & 0x01) === 0x01; $p += 1;
                }
            }
            // salta rich runs (4 byte cad.) ed ext phonetic
            $p += $rich * 4 + $extsz;
            $strings[] = $out;
        }
        return $strings;
    }

    private static function nextBoundary(array $bounds, int $from, int $len): int
    {
        foreach ($bounds as $b => $_) if ($b > $from) return $b;
        return $len;
    }

    /** Stringa BIFF8 con cch a 16 bit (LABEL/STRING). */
    private static function readUnicodeString(string $d, int $off): string
    {
        $cch = unpack('v', substr($d, $off, 2))[1]; $off += 2;
        $grbit = ord($d[$off]); $off += 1;
        $rich = ($grbit & 0x08) ? unpack('v', substr($d, $off, 2))[1] : 0;
        if ($grbit & 0x08) $off += 2;
        $extsz = ($grbit & 0x04) ? unpack('V', substr($d, $off, 4))[1] : 0;
        if ($grbit & 0x04) $off += 4;
        $high = ($grbit & 0x01) === 0x01;
        if ($high) {
            $s = @iconv('UTF-16LE', 'UTF-8//IGNORE', substr($d, $off, $cch * 2)) ?: '';
        } else {
            $s = self::latin1ToUtf8(substr($d, $off, $cch));
        }
        return $s;
    }

    /** Stringa con cch a 8 bit (nome BOUNDSHEET). */
    private static function readShortString(string $d, int $off): string
    {
        $cch = ord($d[$off]); $off += 1;
        $grbit = ord($d[$off]); $off += 1;
        $high = ($grbit & 0x01) === 0x01;
        return $high
            ? (@iconv('UTF-16LE', 'UTF-8//IGNORE', substr($d, $off, $cch * 2)) ?: '')
            : self::latin1ToUtf8(substr($d, $off, $cch));
    }

    private static function latin1ToUtf8(string $s): string
    {
        // BIFF8 "compressed" = code page Windows-1252
        return @iconv('CP1252', 'UTF-8//IGNORE', $s) ?: (function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'Windows-1252') : $s);
    }

    private static function rkValue(int $rk): float
    {
        $cents = ($rk & 0x01) === 0x01;
        if ($rk & 0x02) {
            $v = $rk >> 2;
            if ($v & 0x20000000) $v -= 0x40000000; // sign-extend 30 bit
            $num = (float)$v;
        } else {
            // i 30 bit alti sono la parte alta di un double IEEE (4 byte bassi = 0)
            $bytes = "\x00\x00\x00\x00" . pack('V', $rk & 0xFFFFFFFC);
            $num = unpack('d', $bytes)[1];
        }
        return $cents ? $num / 100.0 : $num;
    }

    public static function norm(string $h): string
    {
        $h = str_replace(["\r", "\n"], ' ', $h);
        $h = preg_replace('/\s+/u', ' ', $h);
        $h = trim($h);
        return function_exists('mb_strtolower') ? mb_strtolower($h, 'UTF-8') : strtolower($h);
    }
}
