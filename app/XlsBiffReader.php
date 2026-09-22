<?php
/**
 * app/XlsBiffReader.php â€” Parser OLE2/BIFF8 nativo, zero dipendenze.
 *
 * Legge file Excel 97-2003 (.xls) senza estensioni PHP aggiuntive.
 * Supporta:
 *   - Container OLE2 (Compound File Binary)
 *   - Record BIFF8: SST, LABELSST, NUMBER, RK, MULRK, BLANK, FORMULA(string)
 *   - Stringhe Unicode (compressed e uncompressed) con CONTINUE records
 *   - Auto-detect riga intestazione (stesso algoritmo di XlsxReader)
 *
 * API:
 *   XlsBiffReader::read($path, $sheetIndex, $opts)        â†’ ['headers'=>[], 'rows'=>[]]
 *   XlsBiffReader::each($path, $cb, $sheetIndex, &$hdrs)  â†’ void
 *
 * v1.0 â€” 2026-09-18
 */
final class XlsBiffReader
{
    const REC_BOF        = 0x0809;
    const REC_EOF        = 0x000A;
    const REC_SST        = 0x00FC;
    const REC_CONTINUE   = 0x003C;
    const REC_SHEET      = 0x0085;
    const REC_LABELSST   = 0x00FD;
    const REC_NUMBER     = 0x0203;
    const REC_RK         = 0x027E;
    const REC_MULRK      = 0x00BD;
    const REC_BLANK      = 0x0201;
    const REC_FORMULA    = 0x0006;
    const REC_STRING     = 0x0207;
    const REC_LABEL      = 0x0204;

    public static function read(string $path, int $sheetIndex = 0, array $opts = []): array
    {
        $headers = [];
        $rows    = [];
        self::each($path, function (array $row) use (&$rows) { $rows[] = $row; }, $sheetIndex, $headers, $opts);
        return ['headers' => $headers, 'rows' => $rows];
    }

    public static function norm(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    public static function each(string $path, callable $cb, int $sheetIndex = 0, array &$headers = [], array $opts = []): void
    {
        $hints    = array_map([self::class, 'norm'], $opts['header_hints']     ?? []);
        $scanRows = (int)($opts['header_scan_rows'] ?? 25);
        $minCells = (int)($opts['min_header_cells'] ?? 2);

        $data = @file_get_contents($path);
        if ($data === false) throw new RuntimeException("XlsBiffReader: impossibile aprire '$path'");
        if (substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
            throw new RuntimeException("XlsBiffReader: il file '$path' non e' in formato OLE2/XLS");

        $wb = self::extractWorkbook($data);
        [$sst, $sheets] = self::parseGlobals($wb);

        if (!isset($sheets[$sheetIndex]))
            throw new RuntimeException("XlsBiffReader: foglio #$sheetIndex non trovato");

        self::parseSheet($wb, $sheets[$sheetIndex]['offset'], $sst, $cb, $headers, $hints, $scanRows, $minCells);
    }

    // â”€â”€ OLE2 â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private static function extractWorkbook(string $data): string
    {
        $secSz   = 1 << self::u16($data, 0x1E);
        $fatCnt  = self::u32($data, 0x2C);
        $dirStart= self::u32($data, 0x30);
        $miniCut = self::u32($data, 0x38);
        $mfStart = self::u32($data, 0x3C);

        // FAT sectors (DIFAT nella header, primi 109)
        $fatSecs = [];
        for ($i = 0; $i < min($fatCnt, 109); $i++) {
            $sec = self::u32($data, 0x4C + $i * 4);
            if ($sec >= 0xFFFFFFFE) break;
            $fatSecs[] = $sec;
        }
        $fat = '';
        foreach ($fatSecs as $sec) $fat .= substr($data, ($sec + 1) * $secSz, $secSz);

        // Directory
        $dir     = self::readChain($data, $fat, $dirStart, $secSz);
        $numEnt  = (int)(strlen($dir) / 128);
        $wbEntry = null;
        $rootEntry = null;
        for ($i = 0; $i < $numEnt; $i++) {
            $e      = substr($dir, $i * 128, 128);
            $nLen   = self::u16($e, 0x40);
            $nRaw   = substr($e, 0, max(0, $nLen - 2));
            $name   = mb_convert_encoding($nRaw, 'UTF-8', 'UTF-16LE');
            $type   = ord($e[0x42]);
            if ($type === 0) continue;
            if ($i === 0) $rootEntry = $e;
            if (in_array($name, ['Workbook', 'Book'], true)) { $wbEntry = $e; }
        }
        if (!$wbEntry) throw new RuntimeException("XlsBiffReader: stream Workbook non trovato");

        $wbStart = self::u32($wbEntry, 0x74);
        $wbSz    = self::u32($wbEntry, 0x78);

        if ($wbSz < $miniCut) {
            // Mini-stream
            $rootStart = $rootEntry ? self::u32($rootEntry, 0x74) : 0;
            $container = self::readChain($data, $fat, $rootStart, $secSz);
            $miniFat   = self::readChain($data, $fat, $mfStart, $secSz);
            return self::readMiniChain($container, $miniFat, $wbStart, $wbSz);
        }
        return self::readChain($data, $fat, $wbStart, $secSz, $wbSz);
    }

    private static function readChain(string $data, string $fat, int $start, int $sz, int $max = PHP_INT_MAX): string
    {
        $out = ''; $sec = $start; $lim = 20000;
        while ($sec < 0xFFFFFFFE && $lim-- > 0 && strlen($out) < $max) {
            $off = ($sec + 1) * $sz;
            if ($off >= strlen($data)) break;
            $out .= substr($data, $off, min($sz, $max - strlen($out)));
            $fo   = $sec * 4;
            if ($fo + 4 > strlen($fat)) break;
            $sec  = self::u32($fat, $fo);
        }
        return $out;
    }

    private static function readMiniChain(string $container, string $miniFat, int $start, int $max, int $msz = 64): string
    {
        $out = ''; $sec = $start; $lim = 100000;
        while ($sec < 0xFFFFFFFE && $lim-- > 0 && strlen($out) < $max) {
            $out .= substr($container, $sec * $msz, min($msz, $max - strlen($out)));
            $fo   = $sec * 4;
            if ($fo + 4 > strlen($miniFat)) break;
            $sec  = self::u32($miniFat, $fo);
        }
        return $out;
    }

    // â”€â”€ BIFF8 globals â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private static function parseGlobals(string $wb): array
    {
        $sst = []; $sheets = [];
        $pos = 0; $len = strlen($wb);
        $sstPos = -1; $sstLen = 0;

        while ($pos + 4 <= $len) {
            $t = self::u16($wb, $pos);
            $l = self::u16($wb, $pos + 2);
            $d = substr($wb, $pos + 4, $l);
            $curPos = $pos;
            $pos   += 4 + $l;

            if ($t === self::REC_SST) {
                $sstPos = $curPos + 4; $sstLen = $l;
                $sst = self::parseSst($wb, $sstPos, $sstLen);
            } elseif ($t === self::REC_SHEET && strlen($d) >= 6) {
                $shOff  = self::u32($d, 0);
                $nLen   = ord($d[4]);
                $flag   = ord($d[5]);
                $nRaw   = substr($d, 6, ($flag & 1) ? $nLen * 2 : $nLen);
                $name   = ($flag & 1) ? mb_convert_encoding($nRaw, 'UTF-8', 'UTF-16LE') : $nRaw;
                $sheets[] = ['name' => $name, 'offset' => $shOff];
            } elseif ($t === self::REC_EOF) {
                break;
            }
        }
        return [$sst, $sheets];
    }

    private static function parseSst(string $wb, int $sstStart, int $firstLen): array
    {
        // Concatena SST + tutti i CONTINUE successivi
        $raw = substr($wb, $sstStart, $firstLen);
        $p   = $sstStart + $firstLen + 4; // salta header record (4 byte)
        $wl  = strlen($wb);
        while ($p + 4 <= $wl && self::u16($wb, $p) === self::REC_CONTINUE) {
            $cl   = self::u16($wb, $p + 2);
            $raw .= substr($wb, $p + 4, $cl);
            $p   += 4 + $cl;
        }

        if (strlen($raw) < 8) return [];
        $total = self::u32($raw, 4);
        $sst   = [];
        $rp    = 8; $rl = strlen($raw);

        for ($i = 0; $i < $total && $rp < $rl; $i++) {
            if ($rp + 3 > $rl) break;
            $sLen  = self::u16($raw, $rp);
            $flags = ord($raw[$rp + 2]);
            $rp   += 3;
            $rich  = ($flags >> 3) & 1;
            $ext   = ($flags >> 2) & 1;
            $uni   = $flags & 1;
            $rc    = 0; $ec = 0;
            if ($rich && $rp + 2 <= $rl) { $rc = self::u16($raw, $rp); $rp += 2; }
            if ($ext  && $rp + 4 <= $rl) { $ec = self::u32($raw, $rp); $rp += 4; }

            $bpc  = $uni ? 2 : 1;
            $need = $sLen * $bpc;
            $chunk= substr($raw, $rp, min($need, $rl - $rp));
            $rp  += $need;

            $str = $uni ? mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE')
                        : mb_convert_encoding($chunk, 'UTF-8', 'Windows-1252');
            $sst[] = $str;
            $rp   += $rc * 4 + $ec;
        }
        return $sst;
    }

    // â”€â”€ Sheet â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private static function parseSheet(string $wb, int $start, array $sst, callable $cb, array &$headers, array $hints, int $scanRows, int $minCells): void
    {
        $pos    = $start;
        $wl     = strlen($wb);
        $cells  = [];
        $inSh   = false;

        while ($pos + 4 <= $wl) {
            $t = self::u16($wb, $pos);
            $l = self::u16($wb, $pos + 2);
            $d = substr($wb, $pos + 4, $l);
            $pos += 4 + $l;

            if ($t === self::REC_BOF) { $inSh = true; continue; }
            if (!$inSh) continue;
            if ($t === self::REC_EOF) break;

            if ($t === self::REC_LABELSST && strlen($d) >= 10) {
                $r = self::u16($d, 0); $c = self::u16($d, 2);
                $cells[$r][$c] = $sst[self::u32($d, 6)] ?? '';

            } elseif ($t === self::REC_NUMBER && strlen($d) >= 14) {
                $r = self::u16($d, 0); $c = self::u16($d, 2);
                $cells[$r][$c] = self::fmtNum(self::ieee64($d, 6));

            } elseif ($t === self::REC_RK && strlen($d) >= 10) {
                $r = self::u16($d, 0); $c = self::u16($d, 2);
                $cells[$r][$c] = self::fmtNum(self::decodeRk(self::u32($d, 6)));

            } elseif ($t === self::REC_MULRK && strlen($d) >= 10) {
                $r = self::u16($d, 0); $cf = self::u16($d, 2);
                $cnt = (strlen($d) - 6) / 6;
                for ($e = 0; $e < $cnt; $e++) {
                    $cells[$r][$cf + $e] = self::fmtNum(self::decodeRk(self::u32($d, 4 + $e * 6 + 2)));
                }

            } elseif ($t === self::REC_LABEL && strlen($d) >= 8) {
                $r = self::u16($d, 0); $c = self::u16($d, 2);
                $sLen = self::u16($d, 6);
                $cells[$r][$c] = mb_convert_encoding(substr($d, 8, $sLen), 'UTF-8', 'Windows-1252');

            } elseif ($t === self::REC_FORMULA && strlen($d) >= 14) {
                $r = self::u16($d, 0); $c = self::u16($d, 2);
                if (ord($d[12]) === 0xFF && ord($d[13]) === 0xFF) {
                    $cells[$r][$c] = '__STR__';
                } else {
                    $cells[$r][$c] = self::fmtNum(self::ieee64($d, 6));
                }

            } elseif ($t === self::REC_STRING && strlen($d) >= 3) {
                $sLen = self::u16($d, 0); $flag = ord($d[2]);
                $raw  = substr($d, 3, ($flag & 1) ? $sLen * 2 : $sLen);
                $str  = ($flag & 1) ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                                    : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
                // rimpiazza l'ultimo placeholder __STR__
                foreach (array_reverse(array_keys($cells), true) as $ri) {
                    foreach (array_reverse(array_keys($cells[$ri]), true) as $ci) {
                        if ($cells[$ri][$ci] === '__STR__') { $cells[$ri][$ci] = $str; break 2; }
                    }
                }
            }
        }

        if (empty($cells)) return;
        ksort($cells);

        // Trova header
        $hRow = null; $scanned = 0;
        foreach ($cells as $ri => $cols) {
            ksort($cols);
            if (self::looksLikeHeader(array_values($cols), $hints, $minCells)) { $hRow = $ri; break; }
            if (++$scanned >= $scanRows) break;
        }
        if ($hRow === null) return;

        ksort($cells[$hRow]);
        $colMap = [];
        foreach ($cells[$hRow] as $ci => $v) {
            $v = trim((string)$v);
            if ($v !== '') { $colMap[$ci] = $v; $headers[] = $v; }
        }

        $n = 0;
        foreach ($cells as $ri => $cols) {
            if ($ri <= $hRow) continue;
            $hasVal = false;
            foreach ($cols as $v) { if (trim((string)$v) !== '') { $hasVal = true; break; } }
            if (!$hasVal) continue;
            $row = [];
            foreach ($colMap as $ci => $hdr) $row[$hdr] = trim((string)($cols[$ci] ?? ''));
            if ($cb($row, $n) === false) return;
            $n++;
        }
    }

    // â”€â”€ Utility â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private static function looksLikeHeader(array $cells, array $hints, int $minCells): bool
    {
        $ne = array_filter($cells, fn($v) => trim((string)$v) !== '');
        if (count($ne) < $minCells) return false;
        if (!$hints) return count($ne) >= $minCells;
        $hit = 0;
        foreach ($ne as $v) { if (in_array(self::norm((string)$v), $hints, true)) $hit++; }
        return $hit >= 2 || ($hit >= 1 && count($ne) >= $minCells && count($hints) <= 2);
    }

    private static function u16(string $d, int $o): int
    {
        if ($o + 2 > strlen($d)) return 0;
        return ord($d[$o]) | (ord($d[$o + 1]) << 8);
    }

    private static function u32(string $d, int $o): int
    {
        if ($o + 4 > strlen($d)) return 0;
        return ord($d[$o]) | (ord($d[$o+1]) << 8) | (ord($d[$o+2]) << 16) | (ord($d[$o+3]) << 24);
    }

    private static function ieee64(string $d, int $o): float
    {
        if ($o + 8 > strlen($d)) return 0.0;
        $r = unpack('d', strrev(substr($d, $o, 8)));
        return (float)($r[1] ?? 0.0);
    }

    private static function decodeRk(int $rk): float
    {
        $div = $rk & 1;
        $int = ($rk >> 1) & 1;
        $val = $rk >> 2;
        if ($int) {
            if ($val & 0x20000000) $val |= (-1 << 30);
            $result = (float)$val;
        } else {
            $hi  = $rk & 0xFFFFFFFC;
            $arr = unpack('d', strrev(pack('N', $hi) . "\x00\x00\x00\x00"));
            $result = (float)($arr[1] ?? 0.0);
        }
        return $div ? $result / 100.0 : $result;
    }

    private static function fmtNum(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) return '';
        if (floor($v) === $v && abs($v) < 1e15) return (string)(int)$v;
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}