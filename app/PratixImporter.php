<?php
/**
 * PortalManager — app/PratixImporter.php
 *
 * Pipeline ETL per l'ingestione del report "Pratix" (.xls / .xlsx) e
 * l'arricchimento delle viste applicative ("Ordini Pratix" e "Commesse/Progetti").
 *
 * Business key di join:  Excel `Codice`  <->  DB `order_code` (cm_pratix_ext.order_code).
 *
 * Caratteristiche:
 *  - Parser: PhpSpreadsheet se disponibile (legge sia .xls BIFF sia .xlsx),
 *    altrimenti fallback al reader XLSX nativo del progetto (app/XlsxReader.php, solo .xlsx).
 *  - Validazione preliminare dello schema (header obbligatori presenti).
 *  - Pulizia stringhe (trim, collasso spazi, rimozione newline), cast numerico/valuta
 *    su `Totale` (formati IT/EN/€), gestione date (seriali Excel).
 *  - Persistenza transazionale (ACID) con UPSERT idempotente sulla chiave `order_code`.
 *  - Valori nulli e chiavi senza corrispondenza gestiti senza interrompere il processo.
 *
 * NOTA: `upsertRows()` è separato dal parser per consentire test unitari deterministici.
 */

final class PratixImporter
{
    private PDO $pdo;
    private int $actorUserId;

    /** Header Excel (normalizzati) -> campo canonico DB. */
    private const MAP = [
        'codice'                     => 'order_code',           // business key
        'cliente effettivo'          => 'cliente_effettivo',
        'cliente di fatturazione'    => 'cliente_fatturazione',
        'progetto'                   => 'progetto',
        'descrizione'                => 'descrizione',
        'stato'                      => 'stato',
        'azienda'                    => 'azienda',
        'numero documento'           => 'numero_documento',
        'tipologia'                  => 'tipologia',
        'totale'                     => 'totale',               // decimale
        'firma tecnica'              => 'firma_tecnica',
        'firma commerciale'          => 'firma_commerciale',
        'linea di business'          => 'linea_business',
    ];

    /** Header obbligatori per considerare valido il tracciato. */
    private const REQUIRED = ['codice', 'totale', 'cliente effettivo'];

    /** Colonne testuali (per truncation di sicurezza). */
    private const TEXT_LEN = [
        'order_code' => 64, 'cliente_effettivo' => 255, 'cliente_fatturazione' => 255,
        'progetto' => 255, 'descrizione' => 1000, 'stato' => 100, 'azienda' => 255,
        'numero_documento' => 255, 'tipologia' => 100, 'firma_tecnica' => 100,
        'firma_commerciale' => 100, 'linea_business' => 255,
    ];

    public function __construct(PDO $pdo, int $actorUserId = 0)
    {
        $this->pdo = $pdo;
        $this->actorUserId = $actorUserId;
    }

    // ── SCHEMA ────────────────────────────────────────────────────────
    /** Crea (idempotente) la tabella di destinazione. */
    public function ensureSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `cm_pratix_ext` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `order_code` VARCHAR(64) NOT NULL,
              `cliente_effettivo` VARCHAR(255) DEFAULT NULL,
              `cliente_fatturazione` VARCHAR(255) DEFAULT NULL,
              `progetto` VARCHAR(255) DEFAULT NULL,
              `descrizione` VARCHAR(1000) DEFAULT NULL,
              `stato` VARCHAR(100) DEFAULT NULL,
              `azienda` VARCHAR(255) DEFAULT NULL,
              `numero_documento` VARCHAR(255) DEFAULT NULL,
              `tipologia` VARCHAR(100) DEFAULT NULL,
              `totale` DECIMAL(14,2) DEFAULT NULL,
              `firma_tecnica` VARCHAR(100) DEFAULT NULL,
              `firma_commerciale` VARCHAR(100) DEFAULT NULL,
              `linea_business` VARCHAR(255) DEFAULT NULL,
              `source_file` VARCHAR(255) DEFAULT NULL,
              `imported_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_pratix_ext_order_code` (`order_code`),
              KEY `idx_pratix_ext_cliente` (`cliente_effettivo`),
              KEY `idx_pratix_ext_stato` (`stato`),
              KEY `idx_pratix_ext_lb` (`linea_business`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ── ORCHESTRAZIONE ────────────────────────────────────────────────
    /**
     * Import completo: legge il file, valida, arricchisce e persiste in transazione.
     * @return array{letti:int,validi:int,upsert:int,saltati_no_codice:int,errori:int,messaggi:string[]}
     */
    public function import(string $path, ?string $sourceLabel = null): array
    {
        $this->ensureSchema();
        $parsed = $this->readRows($path);                 // ['headers'=>[], 'rows'=>[assoc]]
        $this->validateSchema($parsed['headers']);
        return $this->upsertRows($parsed['rows'], $sourceLabel ?? basename($path));
    }

    /** Verifica che gli header obbligatori siano presenti (schema-validation). */
    public function validateSchema(array $headers): void
    {
        $norm = array_map([self::class, 'norm'], $headers);
        $missing = [];
        foreach (self::REQUIRED as $h) {
            if (!in_array($h, $norm, true)) $missing[] = $h;
        }
        if ($missing) {
            throw new RuntimeException(
                'Tracciato Pratix non valido: colonne obbligatorie mancanti: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Transform + persistenza transazionale (ACID) con UPSERT su order_code.
     * Accetta righe associative con chiavi = header Excel (qualunque case/spazi).
     */
    public function upsertRows(array $rows, ?string $sourceLabel = null): array
    {
        $rep = ['letti' => 0, 'validi' => 0, 'upsert' => 0, 'saltati_no_codice' => 0, 'errori' => 0, 'messaggi' => []];

        $cols = ['order_code','cliente_effettivo','cliente_fatturazione','progetto','descrizione',
                 'stato','azienda','numero_documento','tipologia','totale',
                 'firma_tecnica','firma_commerciale','linea_business','source_file'];
        $ph  = implode(',', array_fill(0, count($cols), '?'));
        // UPSERT idempotente: alla ri-esecuzione aggiorna i campi (non l'id, non imported_at manuale).
        $upd = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)",
                array_slice($cols, 1))); // tutte tranne order_code (chiave)
        $sql = "INSERT INTO `cm_pratix_ext` (`" . implode('`,`', $cols) . "`) VALUES ($ph)
                ON DUPLICATE KEY UPDATE $upd";

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare($sql);
            foreach ($rows as $raw) {
                $rep['letti']++;
                $rec = $this->mapRow($raw);                       // header -> campo canonico
                $code = self::cut($rec['order_code'] ?? null, 64);
                if ($code === null || $code === '') {             // chiave assente -> skip sicuro
                    $rep['saltati_no_codice']++;
                    continue;
                }
                $rep['validi']++;
                try {
                    $st->execute([
                        $code,
                        self::cut($rec['cliente_effettivo'] ?? null, 255),
                        self::cut($rec['cliente_fatturazione'] ?? null, 255),
                        self::cut($rec['progetto'] ?? null, 255),
                        self::cut($rec['descrizione'] ?? null, 1000),
                        self::cut($rec['stato'] ?? null, 100),
                        self::cut($rec['azienda'] ?? null, 255),
                        self::cut($rec['numero_documento'] ?? null, 255),
                        self::cut($rec['tipologia'] ?? null, 100),
                        self::toDecimal($rec['totale'] ?? null),
                        self::cut($rec['firma_tecnica'] ?? null, 100),
                        self::cut($rec['firma_commerciale'] ?? null, 100),
                        self::cut($rec['linea_business'] ?? null, 255),
                        $sourceLabel,
                    ]);
                    $rep['upsert']++;
                } catch (Throwable $eRow) {
                    // errore su singola riga: non interrompe l'elaborazione
                    $rep['errori']++;
                    if (count($rep['messaggi']) < 20) {
                        $rep['messaggi'][] = "Riga '$code': " . $eRow->getMessage();
                    }
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;                                             // fallimento globale -> rollback ACID
        }
        return $rep;
    }

    // ── PARSER ────────────────────────────────────────────────────────
    /**
     * Legge il file e restituisce header + righe associative.
     * PhpSpreadsheet (xls/xlsx) se presente, altrimenti XlsxReader nativo (solo xlsx).
     * @return array{headers:string[],rows:array<int,array<string,mixed>>}
     */
    public function readRows(string $path): array
    {
        if (!is_file($path)) throw new RuntimeException("File non trovato: $path");
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $hints = ['codice', 'totale', 'cliente effettivo', 'linea di business'];

        // 1) .xls (BIFF8) -> lettore nativo XlsReader (zero dipendenze)
        if ($ext === 'xls') {
            $f = __DIR__ . '/XlsReader.php';
            if (is_file($f)) require_once $f;
            if (class_exists('XlsReader')) {
                $res = XlsReader::read($path, 0, ['header_hints' => $hints, 'header_scan_rows' => 30]);
                return ['headers' => $res['headers'] ?? [], 'rows' => $res['rows'] ?? []];
            }
        }

        // 2) .xlsx -> lettore nativo XlsxReader (zero dipendenze)
        if ($ext === 'xlsx') {
            $f = __DIR__ . '/XlsxReader.php';
            if (is_file($f)) require_once $f;
            if (class_exists('XlsxReader')) {
                $res = XlsxReader::read($path, 0, ['header_hints' => $hints, 'header_scan_rows' => 30]);
                return ['headers' => $res['headers'] ?? [], 'rows' => $res['rows'] ?? []];
            }
        }

        // 3) Fallback opzionale: PhpSpreadsheet, se presente (altri formati/edge case)
        if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return $this->readWithPhpSpreadsheet($path);
        }

        throw new RuntimeException("Formato '$ext' non leggibile: usare un file .xls o .xlsx del report Pratix.");
    }

    private function readWithPhpSpreadsheet(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);
        $matrix = $sheet->toArray(null, true, false, false);      // valori grezzi
        if (!$matrix) return ['headers' => [], 'rows' => []];

        // individua la riga header (prima riga che contiene 'codice' + 'totale')
        $hIdx = 0;
        foreach ($matrix as $i => $row) {
            $norm = array_map([self::class, 'norm'], array_map('strval', $row));
            if (in_array('codice', $norm, true) && in_array('totale', $norm, true)) { $hIdx = $i; break; }
            if ($i > 25) break;
        }
        $headers = array_map('strval', $matrix[$hIdx]);
        $rows = [];
        foreach (array_slice($matrix, $hIdx + 1) as $row) {
            $assoc = [];
            foreach ($headers as $c => $h) {
                if ($h === '') continue;
                $assoc[$h] = $row[$c] ?? null;
            }
            // la riga dei totali (Pratix) non ha Codice: la scarta a valle upsertRows()
            $rows[] = $assoc;
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Rimappa una riga (header Excel -> campo canonico) usando MAP normalizzata. */
    private function mapRow(array $raw): array
    {
        $out = [];
        foreach ($raw as $h => $v) {
            $key = self::MAP[self::norm((string)$h)] ?? null;
            if ($key !== null) $out[$key] = $v;
        }
        return $out;
    }

    // ── HELPER DI PULIZIA / CAST ──────────────────────────────────────
    /** Normalizza un header: minuscolo, niente newline, spazi collassati, trim. */
    public static function norm(string $h): string
    {
        $h = str_replace(["\r", "\n"], ' ', $h);
        $h = preg_replace('/\s+/u', ' ', $h);
        $h = trim($h);
        return function_exists('mb_strtolower') ? mb_strtolower($h, 'UTF-8') : strtolower($h);
    }

    /** Pulisce una stringa e la tronca; '', 'N/A', 'null' -> null. */
    private static function cut($v, int $len): ?string
    {
        if ($v === null) return null;
        $s = str_replace(["\r", "\n"], ' ', (string)$v);
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);
        if ($s === '' || strcasecmp($s, 'N/A') === 0 || strcasecmp($s, 'null') === 0) return null;
        return function_exists('mb_substr') ? mb_substr($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
    }

    /**
     * Cast numerico/valuta robusto (IT/EN/€) -> stringa decimale "###.##" o null.
     *   "€ 1.234,56" -> 1234.56 ; "133224255.71" -> 133224255.71 ; "1.234" -> 1234 (EN) ; "" -> null
     */
    private static function toDecimal($v): ?string
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return number_format((float)$v, 2, '.', '');
        $s = trim((string)$v);
        if ($s === '' || strcasecmp($s, 'N/A') === 0) return null;
        // tieni solo cifre, separatori e segno
        $s = preg_replace('/[^0-9,.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.' || $s === ',') return null;
        $lastComma = strrpos($s, ',');
        $lastDot   = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // il separatore piu' a destra è il decimale
            if ($lastComma > $lastDot) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); } // IT
            else                       { $s = str_replace(',', '', $s); }                                   // EN
        } elseif ($lastComma !== false) {
            $s = str_replace(',', '.', $s);                       // solo virgola -> decimale IT
        }
        // solo punto o nessun separatore: già EN
        if (!is_numeric($s)) return null;
        return number_format((float)$s, 2, '.', '');
    }

    /** Converte un seriale Excel o una data testuale in 'Y-m-d' (helper generico). */
    public static function toDate($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) {
            // seriale Excel (base 1899-12-30)
            $days = (int)floor((float)$v);
            $ts = ($days - 25569) * 86400;
            return gmdate('Y-m-d', $ts);
        }
        $ts = strtotime((string)$v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
