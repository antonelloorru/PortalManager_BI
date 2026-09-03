<?php
/**
 * PortalManager 1.1.3 — app/CredlyImporter.php
 *
 * Importazione/aggiornamento certificazioni da profilo pubblico Credly.
 *
 * Modalità di gestione badge non mappati (controllata da app_settings):
 *   credly_auto_create_catalog = 1  →  crea automaticamente la cert nel catalogo
 *                                       (brand auto-creato se mancante, technology
 *                                        assegnata a "Generale" o creata)
 *   credly_auto_create_catalog = 0  →  registra in import_staging_rows per
 *                                       intervento manuale dell'admin
 *
 * Default: 1 (auto-create) per popolamento massivo iniziale.
 *
 * Endpoint pubblico:
 *   https://www.credly.com/users/<username>/badges.json?page=N&page_size=48
 */

class CredlyImporter
{
    private const ENDPOINT      = 'https://www.credly.com/users/%s/badges.json';
    private const TIMEOUT       = 20;
    private const USER_AGENT    = 'PortalManager/1.1 (+credly-importer)';
    private const PAGE_SIZE     = 48;
    private const MAX_PAGES     = 20;

    private PDO $pdo;
    private int $actorUserId;
    private bool $autoCreateCatalog;
    private array $stats = [];
    private array $brandCache = [];
    private ?int $defaultTechId = null;

    public function __construct(PDO $pdo, int $actorUserId)
    {
        $this->pdo = $pdo;
        $this->actorUserId = $actorUserId;

        try {
            $v = $pdo->query(
                "SELECT setting_value FROM app_settings
                  WHERE setting_key = 'credly_auto_create_catalog' LIMIT 1"
            )->fetchColumn();
            $this->autoCreateCatalog = ($v === false || $v === null) ? true : ($v === '1');
        } catch (Throwable $e) {
            $this->autoCreateCatalog = true;
        }
    }

    public static function parseUsername(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') return null;

        if (preg_match('~credly\.com/users/([^/?#\s]+)~i', $input, $m)) {
            return $m[1];
        }
        if (preg_match('~^[a-zA-Z0-9_\-]+$~', $input)) {
            return $input;
        }
        return null;
    }

    public function fetchBadges(string $username): array
    {
        $username = self::parseUsername($username) ?? $username;
        if (!preg_match('~^[a-zA-Z0-9_\-]+$~', $username)) {
            throw new RuntimeException("Username Credly non valido: $username");
        }

        $all = [];
        $page = 1;
        do {
            $url = sprintf(self::ENDPOINT, urlencode($username))
                 . '?page=' . $page . '&page_size=' . self::PAGE_SIZE;
            $raw = $this->httpGet($url);
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['data'])) {
                throw new RuntimeException("Risposta Credly non valida (pagina $page).");
            }
            foreach ($data['data'] as $b) {
                $norm = $this->normalizeBadge($b);
                if ($norm) $all[] = $norm;
            }
            $meta = $data['metadata'] ?? [];
            $totalPages  = (int)($meta['total_pages'] ?? 1);
            $currentPage = (int)($meta['current_page'] ?? $page);
            if ($currentPage >= $totalPages) break;
            $page++;
        } while ($page <= self::MAX_PAGES);

        return $all;
    }

    public function normalizeBadge(array $b): ?array
    {
        $tpl = $b['badge_template'] ?? null;
        if (!$tpl) return null;

        $issuer = '';
        if (!empty($tpl['owner']['name'])) {
            $issuer = $tpl['owner']['name'];
        } elseif (!empty($b['issuer']['entities'][0]['entity']['name'])) {
            $issuer = $b['issuer']['entities'][0]['entity']['name'];
        }

        $skills = [];
        foreach (($tpl['skills'] ?? []) as $s) {
            if (is_array($s) && !empty($s['name'])) $skills[] = $s['name'];
            elseif (is_string($s)) $skills[] = $s;
        }

        return [
            'credly_badge_id'    => $b['id'] ?? null,
            'credly_template_id' => $tpl['id'] ?? null,
            'name'               => trim($tpl['name'] ?? ''),
            'description'        => $tpl['description'] ?? '',
            'issuer_name'        => trim($issuer),
            'issued_at'          => $b['issued_at_date'] ?? null,
            'expires_at'         => $b['expires_at_date'] ?? null,
            'image_url'          => $b['image_url'] ?? ($tpl['image_url'] ?? null),
            'badge_url'          => 'https://www.credly.com/badges/' . ($b['id'] ?? ''),
            'template_url'       => $tpl['url'] ?? null,
            'vanity_slug'        => $tpl['vanity_slug'] ?? null,
            'type_category'      => $tpl['type_category'] ?? null,
            'skills'             => $skills,
            'state'              => $b['state'] ?? 'accepted',
            'is_public'          => !empty($b['public']),
        ];
    }

    public function syncEmployee(int $employeeId, string $credlyUsername): array
    {
        $this->stats = [
            'imported' => 0, 'updated' => 0, 'unchanged' => 0,
            'created_cert' => 0, 'unmatched' => 0, 'errors' => 0,
            'detail' => [],
        ];

        $badges = $this->fetchBadges($credlyUsername);

        foreach ($badges as $b) {
            try {
                $result = $this->importBadge($employeeId, $b);
                $this->stats[$result] = ($this->stats[$result] ?? 0) + 1;
                $this->stats['detail'][] = [
                    'badge'  => $b['name'],
                    'result' => $result,
                ];
            } catch (Throwable $e) {
                $this->stats['errors']++;
                $this->stats['detail'][] = [
                    'badge'  => $b['name'] ?? '?',
                    'result' => 'error',
                    'note'   => $e->getMessage(),
                ];
                if (function_exists('write_log')) {
                    write_log('CredlyImport', 'error',
                        'Badge "' . ($b['name'] ?? '?') . '": ' . $e->getMessage(),
                        $this->actorUserId);
                }
            }
        }

        $this->pdo->prepare(
            "UPDATE employee_credly_link
                SET last_sync_at = NOW(),
                    last_sync_imported = ?,
                    last_sync_updated  = ?,
                    last_sync_unmatched = ?
              WHERE employee_id = ?"
        )->execute([
            $this->stats['imported'] + $this->stats['created_cert'],
            $this->stats['updated'],
            $this->stats['unmatched'],
            $employeeId
        ]);

        return $this->stats;
    }

    /**
     * v1.6.8 — Sincronizzazione partendo da badges già scaricati (es. JSON manuale).
     * Permette di bypassare completamente la chiamata a Credly se il server non
     * ha connettività in uscita.
     */
    public function syncEmployeeFromBadges(int $employeeId, array $badges, ?string $credlyUsername = null): array
    {
        $this->stats = [
            'imported' => 0, 'updated' => 0, 'unchanged' => 0,
            'created_cert' => 0, 'unmatched' => 0, 'errors' => 0,
            'detail' => [],
        ];

        foreach ($badges as $b) {
            try {
                $result = $this->importBadge($employeeId, $b);
                $this->stats[$result] = ($this->stats[$result] ?? 0) + 1;
                $this->stats['detail'][] = ['badge' => $b['name'] ?? '?', 'result' => $result];
            } catch (Throwable $e) {
                $this->stats['errors']++;
                $this->stats['detail'][] = [
                    'badge'  => $b['name'] ?? '?',
                    'result' => 'error',
                    'note'   => $e->getMessage(),
                ];
                if (function_exists('write_log')) {
                    write_log('CredlyImport', 'error',
                        'Badge "' . ($b['name'] ?? '?') . '": ' . $e->getMessage(),
                        $this->actorUserId);
                }
            }
        }

        // Update last_sync solo se username fornito (collegamento esistente)
        if ($credlyUsername !== null) {
            try {
                $this->pdo->prepare(
                    "UPDATE employee_credly_link
                        SET last_sync_at = NOW(),
                            last_sync_imported = ?,
                            last_sync_updated  = ?,
                            last_sync_unmatched = ?
                      WHERE employee_id = ?"
                )->execute([
                    $this->stats['imported'] + $this->stats['created_cert'],
                    $this->stats['updated'],
                    $this->stats['unmatched'],
                    $employeeId
                ]);
            } catch (Throwable $e) { /* ignora se record non presente */ }
        }

        return $this->stats;
    }

    /**
     * v1.6.8 — Parser robusto per JSON Credly.
     * Accetta:
     *   - JSON nativo Credly: {"data":[{"badge_template":{...},...}], "metadata":{...}}
     *   - Array di badge raw: [{"badge_template":{...},...}, ...]
     *   - Array di pagine concatenate: [{"data":[...]}, {"data":[...]}]
     *   - Stringa singola URL JSON (es. https://www.credly.com/users/xxx/badges.json)
     */
    /**
     * Estrae i badge dal JSON e li normalizza. Metodo di istanza (usa $this).
     */
    public function parseBadgesFromJson(string $json): array
    {
        $json = trim($json);
        if ($json === '') throw new RuntimeException('JSON vuoto.');

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('JSON non valido: ' . json_last_error_msg());
        }

        $raw_badges = [];
        if (isset($data['data']) && is_array($data['data'])) {
            $raw_badges = $data['data'];
        } elseif (isset($data[0]['data'])) {
            foreach ($data as $page) {
                if (isset($page['data']) && is_array($page['data'])) {
                    $raw_badges = array_merge($raw_badges, $page['data']);
                }
            }
        } elseif (isset($data[0]['badge_template']) || isset($data[0]['name'])) {
            $raw_badges = $data;
        } else {
            throw new RuntimeException('Struttura JSON non riconosciuta. Atteso: oggetto Credly con campo "data", oppure array di badge.');
        }

        $out = [];
        foreach ($raw_badges as $b) {
            if (!is_array($b)) continue;
            $norm = $this->normalizeBadge($b);
            if ($norm) $out[] = $norm;
        }
        if (empty($out)) {
            throw new RuntimeException('Nessun badge valido estratto dal JSON.');
        }
        return $out;
    }

    /**
     * Inserisce/aggiorna una singola certificazione per un dipendente.
     * Ritorna: 'imported' | 'updated' | 'unchanged' | 'created_cert' | 'unmatched'
     */
    private function importBadge(int $employeeId, array $badge): string
    {
        $certId = $this->matchBadgeToCertification($badge);
        $autoCreated = false;

        if ($certId === null) {
            if ($this->autoCreateCatalog) {
                $certId = $this->createCatalogEntry($badge);
                $autoCreated = true;
            } else {
                $this->registerUnmatched($employeeId, $badge);
                return 'unmatched';
            }
        }

        // Cerca user_certifications esistente
        $stmt = $this->pdo->prepare(
            "SELECT id, issue_date, expiry_date, status, certificate_code
               FROM user_certifications
              WHERE employee_id = ?
                AND certification_id = ?
                AND (certificate_code = ? OR notes LIKE ?)
              LIMIT 1"
        );
        $stmt->execute([
            $employeeId, $certId,
            $badge['credly_badge_id'],
            '%credly_badge_id:' . $badge['credly_badge_id'] . '%'
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $issueDate  = $badge['issued_at']  ?: date('Y-m-d');
        $expiryDate = $badge['expires_at'] ?: null;
        $status     = $this->computeStatus($expiryDate);
        $code       = $badge['credly_badge_id'];
        $notes      = sprintf(
            "Importato da Credly il %s\ncredly_badge_id:%s\ncredly_template_id:%s\nbadge_url:%s",
            date('Y-m-d H:i'),
            $badge['credly_badge_id'],
            $badge['credly_template_id'],
            $badge['badge_url']
        );

        if ($existing) {
            $changed = false;
            $diff = [];
            foreach (['issue_date' => $issueDate, 'expiry_date' => $expiryDate, 'status' => $status] as $col => $new) {
                if ((string)$existing[$col] !== (string)$new) {
                    $diff[$col] = ['old' => $existing[$col], 'new' => $new];
                    $changed = true;
                }
            }
            if (!$changed) return $autoCreated ? 'created_cert' : 'unchanged';

            $this->pdo->prepare(
                "UPDATE user_certifications
                    SET issue_date = ?, expiry_date = ?, status = ?, notes = ?
                  WHERE id = ?"
            )->execute([$issueDate, $expiryDate, $status, $notes, $existing['id']]);

            $this->auditChange('user_certifications', (int)$existing['id'], 'update', $diff);
            return $autoCreated ? 'created_cert' : 'updated';
        }

        // INSERT nuova
        $this->pdo->prepare(
            "INSERT INTO user_certifications
                (employee_id, certification_id, issue_date, expiry_date,
                 status, certificate_code, notes, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $employeeId, $certId, $issueDate, $expiryDate,
            $status, $code, $notes, $this->actorUserId
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $this->auditChange('user_certifications', $newId, 'insert', [
            'source' => 'credly',
            'credly_badge_id' => $badge['credly_badge_id'],
            'auto_created_cert' => $autoCreated,
        ]);

        return $autoCreated ? 'created_cert' : 'imported';
    }

    public function matchBadgeToCertification(array $badge): ?int
    {
        // 1. Match esatto su credly_template_id
        if (!empty($badge['credly_template_id'])) {
            $s = $this->pdo->prepare(
                "SELECT id FROM certifications
                  WHERE credly_template_id = ? AND is_active = 1
                  LIMIT 1"
            );
            $s->execute([$badge['credly_template_id']]);
            $id = $s->fetchColumn();
            if ($id) return (int)$id;
        }

        // 2. Match esatto nome + brand
        if (!empty($badge['name']) && !empty($badge['issuer_name'])) {
            $s = $this->pdo->prepare(
                "SELECT c.id
                   FROM certifications c
                   JOIN brands b ON c.brand_id = b.id
                  WHERE LOWER(TRIM(c.name)) = LOWER(TRIM(?))
                    AND LOWER(TRIM(b.name)) = LOWER(TRIM(?))
                    AND c.is_active = 1
                  LIMIT 1"
            );
            $s->execute([$badge['name'], $badge['issuer_name']]);
            $id = $s->fetchColumn();
            if ($id) {
                if (!empty($badge['credly_template_id'])) {
                    $this->pdo->prepare(
                        "UPDATE certifications
                            SET credly_template_id = ?
                          WHERE id = ?
                            AND (credly_template_id IS NULL OR credly_template_id = '')"
                    )->execute([$badge['credly_template_id'], $id]);
                }
                return (int)$id;
            }
        }

        // 3. Match per vanity_slug come code
        if (!empty($badge['vanity_slug'])) {
            $s = $this->pdo->prepare(
                "SELECT id FROM certifications
                  WHERE LOWER(TRIM(code)) = LOWER(TRIM(?))
                    AND is_active = 1
                  LIMIT 1"
            );
            $s->execute([$badge['vanity_slug']]);
            $id = $s->fetchColumn();
            if ($id) return (int)$id;
        }

        return null;
    }

    /**
     * Crea nel catalogo una certificazione corrispondente al badge.
     * Auto-crea brand e technology se necessari.
     */
    private function createCatalogEntry(array $badge): int
    {
        // 1. Brand
        $brandId = $this->ensureBrand($badge['issuer_name'] ?: 'Credly');

        // 2. Technology default
        $techId = $this->ensureDefaultTechnology();

        // 3. Codice univoco
        $code = $badge['vanity_slug'] ?? null;
        if (!$code) {
            $code = preg_replace('~[^a-z0-9]+~', '-', strtolower($badge['name']));
            $code = trim($code, '-');
            $code = substr($code, 0, 50);
        }
        $origCode = $code;
        $i = 2;
        while (true) {
            $s = $this->pdo->prepare("SELECT id FROM certifications WHERE code = ? LIMIT 1");
            $s->execute([$code]);
            if (!$s->fetchColumn()) break;
            $code = substr($origCode, 0, 45) . '-' . $i++;
            if ($i > 20) {
                $code = $origCode . '-' . substr($badge['credly_template_id'] ?? 'xxx', 0, 6);
                break;
            }
        }

        // 4. Level
        $level = $this->guessLevel($badge);

        // 5. Description
        $desc = $badge['description'] ?: '';
        if ($badge['template_url']) {
            $desc = trim($desc . "\n\nFonte: " . $badge['template_url']);
        }

        // 6. INSERT
        $this->pdo->prepare(
            "INSERT INTO certifications
                (brand_id, technology_id, name, code, category, level,
                 description, exam_url, credly_template_id,
                 is_active, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,1,?)"
        )->execute([
            $brandId,
            $techId,
            $badge['name'],
            $code,
            'tecnica',
            $level,
            $desc,
            $badge['template_url'],
            $badge['credly_template_id'],
            $this->actorUserId
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $this->auditChange('certifications', $newId, 'insert', [
            'source'             => 'credly_auto',
            'credly_template_id' => $badge['credly_template_id'],
            'brand'              => $badge['issuer_name'],
            'name'               => $badge['name'],
        ]);

        return $newId;
    }

    private function ensureBrand(string $name): int
    {
        $key = strtolower(trim($name));
        if (isset($this->brandCache[$key])) return $this->brandCache[$key];

        $s = $this->pdo->prepare(
            "SELECT id FROM brands WHERE LOWER(TRIM(name)) = ? LIMIT 1"
        );
        $s->execute([$key]);
        $id = $s->fetchColumn();
        if ($id) {
            $this->brandCache[$key] = (int)$id;
            return (int)$id;
        }

        $this->pdo->prepare(
            "INSERT INTO brands (name, description, partnership_level)
             VALUES (?, ?, 'Registered')"
        )->execute([$name, 'Brand creato automaticamente da import Credly']);

        $newId = (int)$this->pdo->lastInsertId();
        $this->brandCache[$key] = $newId;

        $this->auditChange('brands', $newId, 'insert', [
            'source' => 'credly_auto', 'name' => $name,
        ]);

        return $newId;
    }

    private function ensureDefaultTechnology(): int
    {
        if ($this->defaultTechId !== null) return $this->defaultTechId;

        $id = $this->pdo->query(
            "SELECT id FROM technologies
              WHERE LOWER(name) IN ('generale','generic','generica','altro','various')
              ORDER BY id ASC LIMIT 1"
        )->fetchColumn();

        if ($id) {
            $this->defaultTechId = (int)$id;
            return (int)$id;
        }

        $this->pdo->prepare(
            "INSERT INTO technologies (name, description, slug, icon, color, is_active)
             VALUES ('Generale', 'Tecnologia generica (placeholder)', 'generale', 'fa-tag', '#94a3b8', 1)"
        )->execute();
        $newId = (int)$this->pdo->lastInsertId();
        $this->defaultTechId = $newId;

        $this->auditChange('technologies', $newId, 'insert', [
            'source' => 'credly_auto', 'name' => 'Generale',
        ]);

        return $newId;
    }

    private function guessLevel(array $badge): ?string
    {
        $name = strtolower($badge['name']);
        if (preg_match('~\b(expert|architect|master)\b~', $name))            return 'Expert';
        if (preg_match('~\b(professional|advanced|senior|ccnp|ccie)\b~', $name)) return 'Professional';
        if (preg_match('~\b(associate|intermediate|ccna)\b~', $name))         return 'Associate';
        if (preg_match('~\b(foundation|fundamental|basic|essentials|entry)\b~', $name)) return 'Foundation';
        if (preg_match('~\b(specialist|specialty)\b~', $name))                return 'Specialist';
        return null;
    }

    /**
     * Registra badge non mappabile in import_staging_rows (modalità manuale).
     * Schema corretto: import_jobs.original_name (NON source_file),
     *                  import_staging_rows.payload + errors + is_partial.
     */
    private function registerUnmatched(int $employeeId, array $badge): void
    {
        $jobName = 'credly_unmatched_emp_' . $employeeId;

        $s = $this->pdo->prepare(
            "SELECT id FROM import_jobs
              WHERE import_type = 'credly_badges'
                AND original_name = ?
                AND status IN ('partial','validated','partial_lds')
              ORDER BY id DESC LIMIT 1"
        );
        $s->execute([$jobName]);
        $jobId = $s->fetchColumn();

        if (!$jobId) {
            $this->pdo->prepare(
                "INSERT INTO import_jobs
                    (import_type, original_name, status, created_by,
                     started_at, total_rows, allow_partial)
                 VALUES ('credly_badges', ?, 'partial_lds', ?, NOW(), 0, 1)"
            )->execute([$jobName, $this->actorUserId]);
            $jobId = (int)$this->pdo->lastInsertId();
        }

        // Dedupe
        $s = $this->pdo->prepare(
            "SELECT id FROM import_staging_rows
              WHERE job_id = ? AND payload LIKE ?
              LIMIT 1"
        );
        $s->execute([$jobId, '%' . $badge['credly_badge_id'] . '%']);
        if ($s->fetchColumn()) return;

        $payload = json_encode($badge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $missing = json_encode(['certification_mapping']);
        $errors  = json_encode([[
            'field' => 'certification_id',
            'message' => 'Nessuna corrispondenza nel catalogo certificazioni'
        ]]);

        $this->pdo->prepare(
            "INSERT INTO import_staging_rows
                (job_id, row_number, payload, status, missing_fields,
                 errors, is_partial)
             VALUES (?, ?, ?, 'partial', ?, ?, 1)"
        )->execute([
            $jobId,
            $this->getNextRowNumber((int)$jobId),
            $payload, $missing, $errors,
        ]);

        $this->pdo->prepare(
            "UPDATE import_jobs
                SET total_rows = total_rows + 1, partial_rows = partial_rows + 1
              WHERE id = ?"
        )->execute([$jobId]);
    }

    private function getNextRowNumber(int $jobId): int
    {
        $s = $this->pdo->prepare(
            "SELECT COALESCE(MAX(row_number),0)+1
               FROM import_staging_rows WHERE job_id = ?"
        );
        $s->execute([$jobId]);
        return (int)$s->fetchColumn();
    }

    private function computeStatus(?string $expiryDate): string
    {
        if (!$expiryDate) return 'active';
        $days = (strtotime($expiryDate) - time()) / 86400;
        if ($days < 0)   return 'expired';
        if ($days <= 90) return 'expiring';
        return 'active';
    }

    private function auditChange(string $table, int $recordId, string $action, array $diff): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO entity_change_log
                    (table_name, record_id, change_action, changes_json,
                     source, changed_by, changed_at)
                 VALUES (?, ?, ?, ?, 'credly', ?, NOW())"
            )->execute([
                $table, $recordId, $action,
                json_encode($diff, JSON_UNESCAPED_UNICODE),
                $this->actorUserId
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }

    /**
     * Legge un valore da app_settings (o restituisce null).
     */
    private function getSetting(string $key): ?string
    {
        try {
            $s = $this->pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v !== false && $v !== '' ? (string)$v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function httpGet(string $url): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Estensione PHP curl non disponibile.');
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate',
            ],
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        // v1.6.8: supporto proxy HTTP aziendale via app_settings
        try {
            $proxy = $this->getSetting('credly_proxy_url');
            if ($proxy) {
                $opts[CURLOPT_PROXY] = $proxy;
                // CURLPROXY_HTTP è il default; per SOCKS bisogna mettere socks5://host:port nella URL
                $proxy_user = $this->getSetting('credly_proxy_userpwd');
                if ($proxy_user) $opts[CURLOPT_PROXYUSERPWD] = $proxy_user;
            }
        } catch (Throwable $e) { /* setting non disponibile: ignora */ }
        curl_setopt_array($ch, $opts);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) throw new RuntimeException("Errore di rete chiamando Credly: $errmsg");
        if ($code === 404) throw new RuntimeException("Profilo Credly non trovato (404). Verifica username/URL.");
        if ($code !== 200) throw new RuntimeException("Credly ha risposto HTTP $code");
        return $body ?: '';
    }
}
