<?php
/**
 * certV 2.4 — SmartImport.php
 * Motore di Smart Ingestion per import massivi.
 *
 * PRINCIPI:
 * 1. Friendly Name Matching — risolve nomi testuali → ID tramite lookup
 * 2. Auto-Create — se il nome non esiste, crea automaticamente il record
 * 3. Data Patching — sanitizza, default 3000-12-31 per date vuote, enum fallback
 * 4. Schema-Agnostic — strato di mediazione tra dati grezzi e DB
 */

class SmartImport
{
    private PDO $pdo;
    private int $userId;
    private array $cache = [];   // Cache lookup per sessione
    private array $autoCreated = []; // Record creati automaticamente
    private array $patches = [];     // Campi patchati con default

    const DATE_FOREVER = '3000-12-31'; // Data convenzionale "nessuna scadenza"

    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }

    public function getAutoCreated(): array { return $this->autoCreated; }
    public function getPatches(): array { return $this->patches; }

    // ══════════════════════════════════════════════════════════════
    //  FRIENDLY NAME MATCHING — Risolve nome → ID
    // ══════════════════════════════════════════════════════════════

    /**
     * Risolve un valore in un ID.
     * Se è numerico, lo usa direttamente (retrocompatibilità).
     * Se è testo, cerca per nome nella tabella di riferimento.
     * Se non trovato e $autoCreate=true, crea il record.
     *
     * @return int|null ID risolto o null se irrisolvibile
     */
    public function resolve(string $table, string $nameCol, $rawValue, bool $autoCreate = true, array $defaults = []): ?int
    {
        $raw = trim($rawValue ?? '');
        if ($raw === '') return null;

        // Se è un numero puro, è già un ID
        if (ctype_digit($raw)) {
            $id = (int)$raw;
            // Verifica che esista
            if ($this->idExists($table, $id)) return $id;
            // ID non trovato: se autoCreate è off, ritorna null
            if (!$autoCreate) return null;
        }

        // Cerca per nome (case-insensitive, trim)
        $cacheKey = "$table:$nameCol:" . mb_strtolower($raw);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $s = $this->pdo->prepare("SELECT id FROM `$table` WHERE LOWER(TRIM(`$nameCol`)) = LOWER(?) LIMIT 1");
        $s->execute([$raw]);
        $id = $s->fetchColumn();
        $s->closeCursor();

        if ($id) {
            $this->cache[$cacheKey] = (int)$id;
            return (int)$id;
        }

        // Non trovato → Auto-Create
        if ($autoCreate) {
            $fields = [$nameCol => $raw] + $defaults;
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($fields)));
            $vals = implode(',', array_fill(0, count($fields), '?'));
            $this->pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($vals)")
                ->execute(array_values($fields));
            $newId = (int)$this->pdo->lastInsertId();
            $this->cache[$cacheKey] = $newId;
            $this->autoCreated[] = ['table' => $table, 'name' => $raw, 'id' => $newId];
            return $newId;
        }

        return null;
    }

    /**
     * Risolve un'azienda per nome. Auto-crea se non esiste.
     */
    public function resolveCompany($raw): ?int
    {
        return $this->resolve('companies', 'name', $raw, true, ['status' => 'active']);
    }

    /**
     * Risolve una sede per nome, filtrata per azienda.
     */
    public function resolveLocation($raw, ?int $companyId): ?int
    {
        $raw = trim($raw ?? '');
        if ($raw === '') return null;
        if (ctype_digit($raw)) {
            if ($this->idExists('company_locations', (int)$raw)) return (int)$raw;
        }

        $cacheKey = "location:$companyId:" . mb_strtolower($raw);
        if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];

        if ($companyId) {
            $s = $this->pdo->prepare("SELECT id FROM company_locations WHERE company_id=? AND LOWER(TRIM(location_name))=LOWER(?) LIMIT 1");
            $s->execute([$companyId, $raw]);
        } else {
            $s = $this->pdo->prepare("SELECT id FROM company_locations WHERE LOWER(TRIM(location_name))=LOWER(?) LIMIT 1");
            $s->execute([$raw]);
        }
        $id = $s->fetchColumn(); $s->closeCursor();

        if ($id) {
            $this->cache[$cacheKey] = (int)$id;
            return (int)$id;
        }

        // Auto-create sede
        if ($companyId) {
            $this->pdo->prepare("INSERT INTO company_locations (company_id, location_name) VALUES (?,?)")
                ->execute([$companyId, $raw]);
            $newId = (int)$this->pdo->lastInsertId();
            $this->cache[$cacheKey] = $newId;
            $this->autoCreated[] = ['table' => 'company_locations', 'name' => $raw, 'id' => $newId];
            return $newId;
        }

        return null;
    }

    /**
     * Risolve un brand per nome.
     */
    public function resolveBrand($raw): ?int
    {
        return $this->resolve('brands', 'name', $raw, true, ['partnership_level' => 'Registered', 'priority' => 3, 'priority_color' => '#3b82f6']);
    }

    /**
     * Risolve una tecnologia per nome.
     */
    public function resolveTechnology($raw): ?int
    {
        return $this->resolve('technologies', 'name', $raw, true);
    }

    /**
     * Risolve una certificazione per nome, opzionalmente filtrata per brand.
     */
    public function resolveCertification($raw, ?int $brandId = null): ?int
    {
        $raw = trim($raw ?? '');
        if ($raw === '') return null;
        if (ctype_digit($raw) && $this->idExists('certifications', (int)$raw)) return (int)$raw;

        $cacheKey = "cert:$brandId:" . mb_strtolower($raw);
        if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];

        if ($brandId) {
            $s = $this->pdo->prepare("SELECT id FROM certifications WHERE brand_id=? AND LOWER(TRIM(name))=LOWER(?) LIMIT 1");
            $s->execute([$brandId, $raw]);
        } else {
            $s = $this->pdo->prepare("SELECT id FROM certifications WHERE LOWER(TRIM(name))=LOWER(?) LIMIT 1");
            $s->execute([$raw]);
        }
        $id = $s->fetchColumn(); $s->closeCursor();

        if ($id) { $this->cache[$cacheKey] = (int)$id; return (int)$id; }

        // Auto-create certificazione
        if ($brandId) {
            $this->pdo->prepare("INSERT INTO certifications (brand_id, name, category, is_active) VALUES (?,?,'tecnica',1)")
                ->execute([$brandId, $raw]);
            $newId = (int)$this->pdo->lastInsertId();
            $this->cache[$cacheKey] = $newId;
            $this->autoCreated[] = ['table' => 'certifications', 'name' => $raw, 'id' => $newId];
            return $newId;
        }

        return null;
    }

    /**
     * Risolve un dipendente per nome completo, matricola o codice fiscale.
     */
    public function resolveEmployee($raw): ?int
    {
        $raw = trim($raw ?? '');
        if ($raw === '') return null;
        if (ctype_digit($raw) && $this->idExists('employees', (int)$raw)) return (int)$raw;

        $cacheKey = "emp:" . mb_strtolower($raw);
        if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];

        // Cerca per matricola
        $s = $this->pdo->prepare("SELECT id FROM employees WHERE LOWER(TRIM(employee_code))=LOWER(?) LIMIT 1");
        $s->execute([$raw]); $id = $s->fetchColumn(); $s->closeCursor();
        if ($id) { $this->cache[$cacheKey] = (int)$id; return (int)$id; }

        // Cerca per codice fiscale
        $s = $this->pdo->prepare("SELECT id FROM employees WHERE LOWER(TRIM(fiscal_code))=LOWER(?) LIMIT 1");
        $s->execute([$raw]); $id = $s->fetchColumn(); $s->closeCursor();
        if ($id) { $this->cache[$cacheKey] = (int)$id; return (int)$id; }

        // Cerca per "Cognome Nome" o "Nome Cognome"
        if (str_contains($raw, ' ')) {
            $parts = preg_split('/\s+/', $raw, 2);
            $s = $this->pdo->prepare("SELECT id FROM employees WHERE
                (LOWER(TRIM(first_name))=LOWER(?) AND LOWER(TRIM(last_name))=LOWER(?)) OR
                (LOWER(TRIM(first_name))=LOWER(?) AND LOWER(TRIM(last_name))=LOWER(?)) LIMIT 1");
            $s->execute([$parts[0], $parts[1], $parts[1], $parts[0]]);
            $id = $s->fetchColumn(); $s->closeCursor();
            if ($id) { $this->cache[$cacheKey] = (int)$id; return (int)$id; }
        }

        return null;
    }

    /**
     * Risolve un'agenzia per nome.
     */
    public function resolveAgency($raw): ?int
    {
        return $this->resolve('agencies', 'name', $raw, true, ['type' => 'Misto', 'status' => 'active']);
    }

    /**
     * Risolve una modalità lavoro per nome.
     */
    public function resolveWorkMode($raw): ?int
    {
        return $this->resolve('work_modes', 'name', $raw, true);
    }

    // ══════════════════════════════════════════════════════════════
    //  DATA PATCHING — Sanitizzazione e default
    // ══════════════════════════════════════════════════════════════

    /**
     * Normalizza una data. Accetta: YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, testo libero.
     * Se vuota e $required=true, ritorna DATE_FOREVER (31/12/3000).
     * Se vuota e $required=false, ritorna null.
     */
    public function patchDate(?string $raw, bool $required = false, string $fieldName = ''): ?string
    {
        $raw = trim($raw ?? '');
        if ($raw === '' || mb_strtolower($raw) === 'n/a' || $raw === '-') {
            if ($required) {
                $this->patches[] = "Campo '$fieldName': data mancante → impostata a 31/12/3000";
                return self::DATE_FOREVER;
            }
            return null;
        }

        // ISO: 2024-01-15
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $d = DateTime::createFromFormat('Y-m-d', $raw);
            return $d ? $d->format('Y-m-d') : ($required ? self::DATE_FOREVER : null);
        }
        // Italiano: 15/01/2024 o 15-01-2024
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $raw, $m)) {
            $d = DateTime::createFromFormat('d/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}");
            return $d ? $d->format('Y-m-d') : ($required ? self::DATE_FOREVER : null);
        }
        // Fallback: strtotime
        $ts = strtotime($raw);
        if ($ts !== false) return date('Y-m-d', $ts);

        // Non parsabile
        if ($required) {
            $this->patches[] = "Campo '$fieldName': data non valida '$raw' → impostata a 31/12/3000";
            return self::DATE_FOREVER;
        }
        return null;
    }

    /**
     * Sanitizza un valore enum. Se non valido, usa il default.
     */
    public function patchEnum(?string $raw, array $valid, string $default, string $fieldName = ''): string
    {
        $raw = trim($raw ?? '');
        if ($raw === '') {
            $this->patches[] = "Campo '$fieldName': vuoto → default '$default'";
            return $default;
        }
        // Match esatto
        if (in_array($raw, $valid)) return $raw;
        // Match case-insensitive
        foreach ($valid as $v) {
            if (mb_strtolower($raw) === mb_strtolower($v)) return $v;
        }
        // Non trovato
        $this->patches[] = "Campo '$fieldName': valore '$raw' non valido → default '$default'";
        return $default;
    }

    /**
     * Sanitizza un campo testo. Trim + null se vuoto.
     */
    public function patchText(?string $raw, ?string $default = null): ?string
    {
        $raw = trim($raw ?? '');
        return $raw !== '' ? $raw : $default;
    }

    /**
     * Sanitizza un numero. 0 o null se vuoto.
     */
    public function patchInt(?string $raw, ?int $default = null): ?int
    {
        $raw = trim($raw ?? '');
        if ($raw === '' || !is_numeric($raw)) return $default;
        return (int)$raw;
    }

    /**
     * Sanitizza un decimale.
     */
    public function patchDecimal(?string $raw, ?float $default = null): ?float
    {
        $raw = trim(str_replace(',', '.', $raw ?? ''));
        if ($raw === '' || !is_numeric($raw)) return $default;
        return (float)$raw;
    }

    /**
     * Sanitizza un'email.
     */
    public function patchEmail(?string $raw): ?string
    {
        $raw = trim(mb_strtolower($raw ?? ''));
        if ($raw && filter_var($raw, FILTER_VALIDATE_EMAIL)) return $raw;
        return null;
    }

    // ══════════════════════════════════════════════════════════════
    //  HELPER INTERNI
    // ══════════════════════════════════════════════════════════════

    private function idExists(string $table, int $id): bool
    {
        $cacheKey = "exists:$table:$id";
        if (isset($this->cache[$cacheKey])) return $this->cache[$cacheKey];
        $s = $this->pdo->prepare("SELECT 1 FROM `$table` WHERE id=? LIMIT 1");
        $s->execute([$id]); $exists = (bool)$s->fetchColumn(); $s->closeCursor();
        $this->cache[$cacheKey] = $exists;
        return $exists;
    }

    /**
     * Reset cache (utile tra import diversi).
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->autoCreated = [];
        $this->patches = [];
    }
}
