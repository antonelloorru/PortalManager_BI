<?php
/**
 * certV 5.4.0 — app/ImportProcessor.php
 *
 * Orchestratore importazione massiva:
 *   - Crea il job in import_jobs
 *   - Inserisce le righe in import_staging_rows
 *   - Valida e classifica (valid/invalid)
 *   - Esegue commit atomico delle righe valide
 *   - Aggiorna stato job
 *
 * Atomicità: ogni "commit" è in transazione. Se una INSERT fallisce in DB
 * (vincolo unique, FK, ...), la singola riga torna 'invalid' con errore
 * salvato, ma le altre righe procedono.
 */

require_once __DIR__ . '/ImportValidator.php';
require_once __DIR__ . '/EntityChangeLog.php';

final class ImportProcessor
{
    private PDO $pdo;
    private string $type;
    private ?int $jobId = null;
    private ?ImportValidator $validator = null;

    public function __construct(PDO $pdo, string $type)
    {
        $this->pdo = $pdo;
        $this->type = $type;
        $this->validator = new ImportValidator($pdo, $type);
    }

    /**
     * Step 1: Crea un job e carica le righe in staging.
     *
     * @param array $rows Array di righe (associative array key→value)
     * @param array $meta ['original_name'=>..., 'file_size'=>..., 'created_by'=>...]
     * @return int job_id
     */
    public function createJob(array $rows, array $meta): int
    {
        $allowPartial = !empty($meta['allow_partial']) ? 1 : 0;
        $stmt = $this->pdo->prepare(
            "INSERT INTO import_jobs (import_type, original_name, file_size, total_rows, status, created_by, allow_partial)
             VALUES (?, ?, ?, ?, 'uploaded', ?, ?)"
        );
        $stmt->execute([
            $this->type,
            $meta['original_name'] ?? 'unknown',
            $meta['file_size'] ?? null,
            count($rows),
            $meta['created_by'] ?? null,
            $allowPartial,
        ]);
        $this->jobId = (int)$this->pdo->lastInsertId();

        $ins = $this->pdo->prepare(
            "INSERT INTO import_staging_rows (job_id, row_number, payload, status)
             VALUES (?, ?, ?, 'pending')"
        );
        foreach ($rows as $i => $row) {
            $ins->execute([
                $this->jobId,
                $i + 1,
                json_encode($row, JSON_UNESCAPED_UNICODE),
            ]);
        }

        if (function_exists('write_log')) {
            write_log('Import', 'info',
                "Creato job #{$this->jobId} ({$this->type}): " . count($rows) . " righe",
                $meta['created_by'] ?? null);
        }

        return $this->jobId;
    }

    /**
     * Step 2: Valida tutte le righe pending del job.
     */
    public function validateJob(int $jobId): array
    {
        $this->jobId = $jobId;
        // v5.8: associa proposte enum al job in corso
        if (method_exists($this->validator, 'setJobIdForProposals')) {
            $this->validator->setJobIdForProposals($jobId);
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, payload FROM import_staging_rows
              WHERE job_id = ? AND status = 'pending'"
        );
        $stmt->execute([$jobId]);

        $valid = 0;
        $invalid = 0;
        $partial = 0;
        $upd = $this->pdo->prepare(
            "UPDATE import_staging_rows
                SET status = ?, errors = ?, missing_fields = ?, payload = ?
              WHERE id = ?"
        );

        // v5.6: validazione SEMPRE partial-aware. La distinzione strict vs ldb
        // viene fatta al momento dell'approvazione per riga.
        while ($staging = $stmt->fetch()) {
            $row = json_decode($staging['payload'], true) ?: [];
            $result = $this->validator->validateRowPartial($row);
            $missingJson = !empty($result['missing_fields'])
                ? json_encode($result['missing_fields'], JSON_UNESCAPED_UNICODE) : null;

            if (!empty($result['errors'])) {
                $status = 'invalid';
                $invalid++;
            } elseif (!empty($result['missing_fields'])) {
                $status = 'partial';
                $partial++;
            } else {
                $status = 'valid';
                $valid++;
            }
            $errorsJson = !empty($result['errors']) ? json_encode($result['errors'], JSON_UNESCAPED_UNICODE) : null;
            $newPayload = json_encode(array_merge($row, $result['normalized']), JSON_UNESCAPED_UNICODE);
            $upd->execute([$status, $errorsJson, $missingJson, $newPayload, (int)$staging['id']]);
        }

        // v5.5: in modalità LDB le righe 'partial' sono committabili (= valide ai fini del commit)
        $totalValid = $valid + $partial;
        $this->pdo->prepare(
            "UPDATE import_jobs
                SET valid_rows = ?, invalid_rows = ?, status = 'validated', validated_at = NOW()
              WHERE id = ?"
        )->execute([$totalValid, $invalid, $jobId]);

        if (function_exists('write_log')) {
            write_log('Import', 'info',
                "Validato job #$jobId: $valid valide, $partial parziali (LDB), $invalid invalide",
                $_SESSION['user_id'] ?? null);
        }

        return ['valid' => $valid, 'partial' => $partial, 'invalid' => $invalid, 'total' => $valid + $partial + $invalid];
    }

    /**
     * Step 3: Commit del job — importa solo le righe in stato 'valid' o 'corrected'.
     *
     * Ogni riga viene importata in una transazione individuale. Se fallisce,
     * la riga torna 'invalid' con errore di DB salvato.
     *
     * @param int $jobId
     * @param int|null $userId per audit
     * @return array{imported:int, failed:int, skipped:int}
     */
    public function commitJob(int $jobId, ?int $userId = null): array
    {
        $this->jobId = $jobId;

        // Carica tipo del job (per gestori specifici)
        $job = $this->pdo->prepare("SELECT * FROM import_jobs WHERE id = ?");
        $job->execute([$jobId]);
        $jobData = $job->fetch();
        if (!$jobData) throw new RuntimeException("Job non trovato.");
        if (!in_array($jobData['status'], ['validated','partial','queued','partial_lds'], true)) {
            throw new RuntimeException("Job non commitabile (status={$jobData['status']})");
        }

        // v5.8.1: log inizio commit (utile per diagnostica)
        if (function_exists('write_log')) {
            write_log('Import', 'info',
                "Commit job #$jobId start (type={$jobData['import_type']})", $userId);
        }

        // v5.5: marca come 'processing' per workflow async (FUORI da qualunque transazione)
        if ($this->pdo->inTransaction()) $this->pdo->commit();
        $this->pdo->prepare("UPDATE import_jobs SET status = 'processing' WHERE id = ?")->execute([$jobId]);

        $stmt = $this->pdo->prepare(
            "SELECT id, payload, status, missing_fields, approved_as
                FROM import_staging_rows
              WHERE job_id = ? AND status = 'approved'"
        );
        $stmt->execute([$jobId]);

        $imported = 0;
        $failed = 0;
        $skipped = 0;
        $upd = $this->pdo->prepare(
            "UPDATE import_staging_rows
                SET status = ?, errors = ?, result_id = ?, result_action = ?, imported_at = NOW()
              WHERE id = ?"
        );

        $partialCount = 0;
        $upd2 = $this->pdo->prepare(
            "UPDATE import_staging_rows
                SET status = ?, errors = ?, result_id = ?, result_action = ?, is_partial = ?, imported_at = NOW()
              WHERE id = ?"
        );
        while ($staging = $stmt->fetch()) {
            $row = json_decode($staging['payload'], true) ?: [];
            // v5.6: is_partial deriva da approved_as='ldb' (utente ha esplicitamente approvato in modalità LDB)
            $isPartial = ((string)($staging['approved_as'] ?? '') === 'ldb') ? 1 : 0;
            try {
                $this->pdo->beginTransaction();
                $r = $this->commitSingleRow($row, $jobData['import_type'], $userId);
                $this->pdo->commit();

                $action = $r['action'];  // 'insert' | 'update' | 'skip'
                if ($action === 'skip') {
                    $upd2->execute(['skipped', null, $r['id'] ?? null, 'skip', 0, (int)$staging['id']]);
                    $skipped++;
                } else {
                    $upd2->execute(['imported', null, $r['id'], $action, $isPartial, (int)$staging['id']]);
                    $imported++;
                    if ($isPartial) $partialCount++;
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $errorsJson = json_encode([
                    '_db' => $e->getMessage(),
                    '_class' => get_class($e),
                    '_file' => basename($e->getFile()) . ':' . $e->getLine(),
                ], JSON_UNESCAPED_UNICODE);
                try {
                    $upd2->execute(['invalid', $errorsJson, null, null, 0, (int)$staging['id']]);
                } catch (Throwable $upe) {
                    // Last resort: l'UPDATE staging ha fallito (probabilmente connessione persa)
                    if (function_exists('write_log')) {
                        write_log('Import', 'error',
                            "Commit job #$jobId staging #{$staging['id']}: UPDATE staging failed: " . $upe->getMessage(),
                            $userId);
                    }
                }
                if (function_exists('write_log')) {
                    write_log('Import', 'error',
                        "Commit job #$jobId staging #{$staging['id']}: " . get_class($e) . ': ' . $e->getMessage()
                        . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
                        $userId);
                }
                $failed++;
            }
        }

        // Aggiorna stato job
        $invalid = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM import_staging_rows WHERE job_id = $jobId AND status = 'invalid'"
        )->fetchColumn();

        // v5.5: stati granulari
        if ($invalid > 0) {
            $newStatus = 'partial';
        } elseif ($partialCount > 0) {
            $newStatus = 'partial_lds';  // tutti importati ma alcuni con LDB
        } else {
            $newStatus = 'imported';
        }

        $this->pdo->prepare(
            "UPDATE import_jobs
                SET imported_rows = imported_rows + ?, skipped_rows = skipped_rows + ?,
                    invalid_rows = ?, partial_rows = partial_rows + ?,
                    status = ?, imported_at = NOW(), processed_at = NOW(), processed_by = ?
              WHERE id = ?"
        )->execute([$imported, $skipped, $invalid, $partialCount, $newStatus, $userId, $jobId]);

        if (function_exists('write_log')) {
            $level = ($failed > 0) ? 'warning' : 'success';
            write_log('Import', $level,
                "Commit job #$jobId end: $imported importate, $failed fallite, $skipped saltate, $partialCount partial → status=$newStatus",
                $userId);
        }

        return ['imported' => $imported, 'failed' => $failed, 'skipped' => $skipped, 'partial' => $partialCount, 'status' => $newStatus];
    }

    /**
     * Esegue l'INSERT/UPDATE per una singola riga normalizzata.
     * Restituisce ['action' => 'insert|update|skip', 'id' => N]
     */
    private function commitSingleRow(array $row, string $type, ?int $userId): array
    {
        // v5.8: sgancia il marker enum_proposals dal payload prima di scrivere
        if (isset($row['__enum_proposals__'])) {
            unset($row['__enum_proposals__']);
        }

        switch ($type) {
            case 'dipendenti':
                return $this->commitEmployee($row, $userId);
            case 'accessi':
                return $this->commitUser($row, $userId);
            case 'brand':
                return $this->commitBrand($row, $userId);
            case 'tecnologie':
                return $this->commitTechnology($row, $userId);
            case 'catalogo':
                return $this->commitCertification($row, $userId);
            case 'sedi':
                return $this->commitLocation($row, $userId);
            case 'agenzie':
                return $this->commitAgency($row, $userId);
            case 'candidati':
                return $this->commitCandidate($row, $userId);
            case 'clienti':
                return $this->commitClient($row, $userId);
            case 'templates':
                return $this->commitTemplate($row, $userId);
            case 'contatti_agenzie':
                return $this->commitAgencyContact($row, $userId);
            case 'certificati':
                return $this->commitUserCertification($row, $userId);
            case 'piani_formativi':
                return $this->commitTrainingPlan($row, $userId);
            case 'esami':
                return $this->commitPlannedExam($row, $userId);
            case 'tech_brand_links':
                return $this->commitTechBrandLink($row, $userId);
            case 'tech_cert_links':
                return $this->commitTechCertLink($row, $userId);
            case 'employee_skills':
                return $this->commitEmployeeSkill($row, $userId);
            default:
                throw new RuntimeException("Tipo import non supportato: $type");
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // HANDLERS PER OGNI TIPO
    // ────────────────────────────────────────────────────────────────────



    /**
     * v5.7 — UPSERT con storicizzazione granulare per campo.
     *
     * Pattern unificato per tutti i commit handler:
     *   1. SELECT del record esistente per matchKeys
     *   2. Se esiste: confronta payload vs valori attuali; UPDATE solo i campi diversi;
     *      logga ogni campo cambiato in entity_change_log via EntityChangeLog::diffAndLog().
     *   3. Se non esiste: INSERT; logga lo "snapshot" iniziale come singolo evento "create".
     *
     * @param string $table         Tabella target (es. employees, brands)
     * @param array  $matchKeys     Coppie key=>val da provare in OR per il match (es. ['fiscal_code'=>'XYZ', 'employee_code'=>'EMP1'])
     * @param array  $payload       Dati normalizzati da scrivere
     * @param array  $allowedFields Lista campi scrivibili (whitelist)
     * @param array  $insertExtra   Campi extra solo per INSERT (es. created_by, password_hash)
     * @param int|null $userId      Utente che effettua l'operazione (per audit)
     * @param string $sourceTable   Tabella di provenienza per audit (default: target table)
     * @return array{action:string, id:int, fields_changed:int}
     */
    private function upsertWithHistory(
        string $table,
        array $matchKeys,
        array $payload,
        array $allowedFields,
        array $insertExtra = [],
        ?int $userId = null,
        string $changeSource = 'import',
        ?int $sourceRefId = null
    ): array {
        // 1. Match
        $existingId = null;
        $existingRow = null;
        foreach ($matchKeys as $col => $val) {
            if ($val === null || $val === '') continue;
            $s = $this->pdo->prepare("SELECT * FROM `$table` WHERE `$col` = ? LIMIT 1");
            $s->execute([$val]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $existingId = (int)$row['id'];
                $existingRow = $row;
                break;
            }
        }

        $log = new EntityChangeLog($this->pdo);

        // Filtro payload sui soli campi consentiti
        $cleanPayload = [];
        foreach ($allowedFields as $f) {
            if (array_key_exists($f, $payload)) {
                $cleanPayload[$f] = $payload[$f];
            }
        }

        // 2. UPDATE
        if ($existingId !== null) {
            $diff = [];
            foreach ($cleanPayload as $f => $v) {
                $oldV = $existingRow[$f] ?? null;
                if (!$this->coalesceCompare($oldV, $v)) {
                    $diff[$f] = $v;
                }
            }

            if (empty($diff)) {
                return ['action' => 'skip', 'id' => $existingId, 'fields_changed' => 0];
            }

            $set = implode('=?, ', array_keys($diff)) . '=?';
            $vals = array_values($diff);
            $vals[] = $existingId;
            $this->pdo->prepare("UPDATE `$table` SET $set WHERE id = ?")->execute($vals);

            // Audit: 1 riga di log per ogni campo cambiato
            $log->diffAndLog($table, $existingId, $existingRow, array_merge($existingRow, $diff),
                             'update', $changeSource, $sourceRefId, $userId);

            return ['action' => 'update', 'id' => $existingId, 'fields_changed' => count($diff)];
        }

        // 3. INSERT
        $insertData = array_merge($cleanPayload, $insertExtra);
        $cols = array_keys($insertData);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $colsList = '`' . implode('`,`', $cols) . '`';
        $this->pdo->prepare("INSERT INTO `$table` ($colsList) VALUES ($ph)")
                  ->execute(array_values($insertData));
        $newId = (int)$this->pdo->lastInsertId();

        // Audit creazione
        $log->logField($table, $newId, '__create__', null, '1',
                       'insert', $changeSource, $sourceRefId, $userId);

        return ['action' => 'insert', 'id' => $newId, 'fields_changed' => count($insertData)];
    }

    /**
     * Confronto loose-equal per campi DB: NULL ~ '' ~ 0, normalizza case, ignora trailing spaces.
     */
    private function coalesceCompare($a, $b): bool
    {
        if ($a === null && ($b === '' || $b === null)) return true;
        if ($b === null && ($a === '' || $a === null)) return true;
        if (is_numeric($a) && is_numeric($b)) return (float)$a == (float)$b;
        return (string)$a === (string)$b;
    }

    private function commitEmployee(array $r, ?int $userId): array
    {
        return $this->upsertWithHistory(
            'employees',
            ['fiscal_code' => $r['fiscal_code'] ?? null, 'employee_code' => $r['employee_code'] ?? null],
            $r,
            ['first_name','last_name','fiscal_code','date_of_birth','phone','personal_email',
             'employee_code','job_title','department','company_id','location_id','work_mode_id',
             'contract_type','hire_date','end_date','status'],
            [],
            $userId,
            'import',
            $this->jobId
        );
    }

    private function commitUser(array $r, ?int $userId): array
    {
        $s = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $s->execute([$r['email']]);
        $existingId = $s->fetchColumn();

        if ($existingId) {
            $this->pdo->prepare(
                "UPDATE users SET role_id = ?, employee_id = ?, is_active = ? WHERE id = ?"
            )->execute([$r['role_id'], $r['employee_id'] ?? null, $r['is_active'] ?? 1, (int)$existingId]);
            return ['action' => 'update', 'id' => (int)$existingId];
        }
        // Password temporanea (l'admin la cambierà)
        $tempPassword = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $this->pdo->prepare(
            "INSERT INTO users (email, password_hash, role_id, employee_id, is_active)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$r['email'], $tempPassword, $r['role_id'], $r['employee_id'] ?? null, $r['is_active'] ?? 1]);
        return ['action' => 'insert', 'id' => (int)$this->pdo->lastInsertId()];
    }

    private function commitBrand(array $r, ?int $userId): array
    {
        return $this->upsertWithHistory(
            'brands',
            ['name' => $r['name'] ?? null],
            $r,
            ['name','description','website','is_active'],
            [],
            $userId, 'import', $this->jobId
        );
    }

    private function commitTechnology(array $r, ?int $userId): array
    {
        $result = $this->upsertWithHistory(
            'technologies',
            ['name' => $r['name'] ?? null],
            $r,
            ['name','description','category_id','slug','icon','color','is_active'],
            [],
            $userId, 'import', $this->jobId
        );
        // v5.7: se brand_id presente nel payload, aggiungi link N:M tech_brands
        if (!empty($r['brand_id']) && $result['id'] > 0) {
            $this->pdo->prepare(
                "INSERT IGNORE INTO tech_brands (technology_id, brand_id, created_by) VALUES (?, ?, ?)"
            )->execute([$result['id'], $r['brand_id'], $userId]);
        }
        return $result;
    }

    private function commitCertification(array $r, ?int $userId): array
    {
        $result = $this->upsertWithHistory(
            'certifications',
            ['code' => $r['code'] ?? null],
            $r,
            ['code','name','brand_id','technology_id','category','level','validity_months',
             'description','notes','exam_url','is_active','renewal_policy','exam_cost','updated_by'],
            [],
            $userId, 'import', $this->jobId
        );
        // v5.7: collega anche al pivot N:M tech_certifications con relevance='primary'
        if (!empty($r['technology_id']) && $result['id'] > 0) {
            $this->pdo->prepare(
                "INSERT IGNORE INTO tech_certifications (technology_id, certification_id, relevance, created_by)
                 VALUES (?, ?, 'primary', ?)"
            )->execute([$r['technology_id'], $result['id'], $userId]);
        }
        return $result;
    }

    private function commitLocation(array $r, ?int $userId): array
    {
        // Match composto: name + company_id (le sedi sono uniche per azienda)
        $existingId = null;
        if (!empty($r['name']) && !empty($r['company_id'])) {
            $s = $this->pdo->prepare("SELECT id FROM locations WHERE name = ? AND company_id = ?");
            $s->execute([$r['name'], $r['company_id']]);
            $existingId = $s->fetchColumn();
        }
        return $this->upsertWithHistory(
            'locations',
            $existingId ? ['id' => $existingId] : ['___nomatch___' => null],
            $r,
            ['name','company_id','address','city','province','country','is_active'],
            [],
            $userId, 'import', $this->jobId
        );
    }

    private function commitAgency(array $r, ?int $userId): array
    {
        return $this->upsertWithHistory(
            'agencies',
            ['name' => $r['name'] ?? null],
            $r,
            ['name','vat_number','email','phone','website','address','city','is_active'],
            [],
            $userId, 'import', $this->jobId
        );
    }

    private function commitCandidate(array $r, ?int $userId): array
    {
        return $this->upsertWithHistory(
            'candidates',
            ['email' => $r['email'] ?? null],
            $r,
            ['first_name','last_name','email','phone','linkedin_url',
             'ral_richiesta_k','preavviso_giorni','source','agency_id'],
            [],
            $userId, 'import', $this->jobId
        );
    }

    private function commitClient(array $r, ?int $userId): array
    {
        return $this->upsertWithHistory(
            'clients',
            ['name' => $r['name'] ?? null],
            $r,
            ['name','vat_number','fiscal_code','sector','city','phone','email','is_active'],
            ['created_by' => $userId],
            $userId, 'import', $this->jobId
        );
    }

    private function commitTemplate(array $r, ?int $userId): array
    {
        // Verifica se TemplateVersioning è disponibile
        if (file_exists(__DIR__ . '/TemplateVersioning.php')) {
            require_once __DIR__ . '/TemplateVersioning.php';
            // Skip se identico a versione corrente
            $check = $this->pdo->prepare("SELECT id, content FROM position_templates WHERE template_type=? AND name=? AND is_current=1 LIMIT 1");
            $check->execute([$r['tipo'], $r['nome']]);
            $existing = $check->fetch();
            if ($existing && $existing['content'] === $r['contenuto']) {
                return ['action' => 'skip', 'id' => (int)$existing['id']];
            }
            $newId = TemplateVersioning::createVersion($this->pdo, $r['tipo'], $r['nome'], $r['contenuto'], $userId ?? 0, $r['note'] ?? 'Import massivo');
            return ['action' => $existing ? 'update' : 'insert', 'id' => $newId];
        }
        // Fallback diretto
        $s = $this->pdo->prepare("SELECT id FROM position_templates WHERE template_type=? AND name=?");
        $s->execute([$r['tipo'], $r['nome']]);
        $existingId = $s->fetchColumn();
        if ($existingId) {
            $this->pdo->prepare("UPDATE position_templates SET content=? WHERE id=?")->execute([$r['contenuto'], (int)$existingId]);
            return ['action' => 'update', 'id' => (int)$existingId];
        }
        $this->pdo->prepare("INSERT INTO position_templates (template_type, name, content, created_by) VALUES (?,?,?,?)")
            ->execute([$r['tipo'], $r['nome'], $r['contenuto'], $userId]);
        return ['action' => 'insert', 'id' => (int)$this->pdo->lastInsertId()];
    }


    private function commitAgencyContact(array $r, ?int $userId): array
    {
        $s = $this->pdo->prepare("SELECT id FROM agency_contacts WHERE agency_id = ? AND email = ?");
        $s->execute([$r['agency_id'], $r['email'] ?? '']);
        $existingId = $s->fetchColumn();
        $fields = ['agency_id','first_name','last_name','role','email','phone','is_primary'];
        $vals = [];
        foreach ($fields as $f) $vals[] = $r[$f] ?? null;
        if ($existingId) {
            $set = implode('=?, ', $fields) . '=?';
            $vals[] = (int)$existingId;
            $this->pdo->prepare("UPDATE agency_contacts SET $set WHERE id = ?")->execute($vals);
            return ['action' => 'update', 'id' => (int)$existingId];
        }
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $cols = implode(',', $fields);
        $this->pdo->prepare("INSERT INTO agency_contacts ($cols) VALUES ($ph)")->execute($vals);
        return ['action' => 'insert', 'id' => (int)$this->pdo->lastInsertId()];
    }

    private function commitUserCertification(array $r, ?int $userId): array
    {
        // Match: stesso dipendente + stessa certificazione + stessa data rilascio
        $s = $this->pdo->prepare(
            "SELECT id FROM user_certifications
              WHERE employee_id = ? AND certification_id = ? AND issue_date = ?"
        );
        $s->execute([$r['employee_id'], $r['certification_id'], $r['issue_date']]);
        $existingId = $s->fetchColumn();

        $fields = ['employee_id','certification_id','issue_date','expiry_date','status',
                   'score','certificate_code','notes','uploaded_by'];
        $vals = [
            $r['employee_id'], $r['certification_id'], $r['issue_date'],
            $r['expiry_date'] ?? null, $r['status'] ?? 'active',
            $r['score'] ?? null, $r['certificate_code'] ?? null,
            $r['notes'] ?? null, $userId,
        ];
        if ($existingId) {
            $set = implode('=?, ', $fields) . '=?';
            $vals[] = (int)$existingId;
            $this->pdo->prepare("UPDATE user_certifications SET $set WHERE id = ?")->execute($vals);
            return ['action' => 'update', 'id' => (int)$existingId];
        }
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $cols = implode(',', $fields);
        $this->pdo->prepare("INSERT INTO user_certifications ($cols) VALUES ($ph)")->execute($vals);
        return ['action' => 'insert', 'id' => (int)$this->pdo->lastInsertId()];
    }

    private function commitTrainingPlan(array $r, ?int $userId): array
    {
        // Match: stesso dipendente + stessa cert + non completato
        $s = $this->pdo->prepare(
            "SELECT id FROM training_plans
              WHERE employee_id = ? AND certification_id = ? AND status IN ('planned','in_progress')"
        );
        $s->execute([$r['employee_id'], $r['certification_id']]);
        $existingId = $s->fetchColumn();

        $fields = ['employee_id','certification_id','target_date','planned_exam_date',
                   'status','priority','plan_type','budget','is_renewal','notes'];
        $vals = [];
        foreach ($fields as $f) {
            $vals[] = $r[$f] ?? match($f) {
                'status'    => 'planned',
                'priority'  => 'Media',
                'plan_type' => 'formazione',
                'is_renewal'=> 0,
                default     => null,
            };
        }
        if ($existingId) {
            $set = implode('=?, ', $fields) . '=?';
            $vals[] = (int)$existingId;
            $this->pdo->prepare("UPDATE training_plans SET $set WHERE id = ?")->execute($vals);
            return ['action' => 'update', 'id' => (int)$existingId];
        }
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $cols = implode(',', $fields);
        $this->pdo->prepare("INSERT INTO training_plans ($cols) VALUES ($ph)")->execute($vals);
        return ['action' => 'insert', 'id' => (int)$this->pdo->lastInsertId()];
    }

    private function commitPlannedExam(array $r, ?int $userId): array
    {
        // Match: stesso dipendente + stessa data + stessa cert
        $s = $this->pdo->prepare(
            "SELECT id FROM planned_exams
              WHERE employee_id = ? AND planned_date = ?
                AND (certification_id = ? OR (certification_id IS NULL AND ? IS NULL))"
        );
        $s->execute([$r['employee_id'], $r['planned_date'], $r['certification_id'] ?? null, $r['certification_id'] ?? null]);
        $existingId = $s->fetchColumn();

        $fields = ['employee_id','certification_id','planned_date','plan_type','status',
                   'result','exam_center','exam_location','booking_code','needs_logistics','notes'];
        $vals = [];
        foreach ($fields as $f) {
            $vals[] = $r[$f] ?? match($f) {
                'plan_type'      => 'esame_certificazione',
                'status'         => 'planned',
                'needs_logistics'=> 0,
                default          => null,
            };
        }
        if ($existingId) {
            $set = implode('=?, ', $fields) . '=?';
            $vals[] = (int)$existingId;
            $this->pdo->prepare("UPDATE planned_exams SET $set WHERE id = ?")->execute($vals);
            return ['action' => 'update', 'id' => (int)$existingId];
        }
        $ph = implode(',', array_fill(0, count($fields), '?'));
        $cols = implode(',', $fields);
        $this->pdo->prepare("INSERT INTO planned_exams ($cols) VALUES ($ph)")->execute($vals);
        return ['action' => 'insert', 'id' => (int)$this->pdo->lastInsertId()];
    }

    /**
     * v5.7 — Link N:M tecnologia ↔ brand
     */
    private function commitTechBrandLink(array $r, ?int $userId): array
    {
        if (empty($r['technology_id']) || empty($r['brand_id'])) {
            throw new RuntimeException("technology_id e brand_id obbligatori");
        }
        $s = $this->pdo->prepare("SELECT id FROM tech_brands WHERE technology_id = ? AND brand_id = ?");
        $s->execute([$r['technology_id'], $r['brand_id']]);
        $existingId = $s->fetchColumn();
        if ($existingId) {
            return $this->upsertWithHistory(
                'tech_brands',
                ['id' => $existingId],
                $r,
                ['technology_id','brand_id','is_primary','notes'],
                [],
                $userId, 'import', $this->jobId
            );
        }
        $this->pdo->prepare(
            "INSERT INTO tech_brands (technology_id, brand_id, is_primary, notes, created_by)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$r['technology_id'], $r['brand_id'], $r['is_primary'] ?? 0, $r['notes'] ?? null, $userId]);
        $newId = (int)$this->pdo->lastInsertId();
        (new EntityChangeLog($this->pdo))->logField(
            'tech_brands', $newId, '__create__', null, '1',
            'insert', 'import', $this->jobId, $userId
        );
        return ['action' => 'insert', 'id' => $newId, 'fields_changed' => 4];
    }

    /**
     * v5.7 — Link N:M tecnologia ↔ certificazione (catalogo)
     */
    private function commitTechCertLink(array $r, ?int $userId): array
    {
        if (empty($r['technology_id']) || empty($r['certification_id'])) {
            throw new RuntimeException("technology_id e certification_id obbligatori");
        }
        $s = $this->pdo->prepare("SELECT id FROM tech_certifications WHERE technology_id = ? AND certification_id = ?");
        $s->execute([$r['technology_id'], $r['certification_id']]);
        $existingId = $s->fetchColumn();
        if ($existingId) {
            $result = $this->upsertWithHistory(
                'tech_certifications',
                ['id' => $existingId],
                $r,
                ['technology_id','certification_id','relevance'],
                [],
                $userId, 'import', $this->jobId
            );
            // Re-propaga ai certificati posseduti se relevance è cambiato a 'primary'
            if (($r['relevance'] ?? '') === 'primary') {
                $this->propagateTechToUserCerts((int)$r['technology_id'], (int)$r['certification_id']);
            }
            return $result;
        }
        $this->pdo->prepare(
            "INSERT INTO tech_certifications (technology_id, certification_id, relevance, created_by)
             VALUES (?, ?, ?, ?)"
        )->execute([$r['technology_id'], $r['certification_id'], $r['relevance'] ?? 'primary', $userId]);
        $newId = (int)$this->pdo->lastInsertId();
        (new EntityChangeLog($this->pdo))->logField(
            'tech_certifications', $newId, '__create__', null, '1',
            'insert', 'import', $this->jobId, $userId
        );
        // Propaga automaticamente ai certificati già posseduti dai dipendenti
        $this->propagateTechToUserCerts((int)$r['technology_id'], (int)$r['certification_id']);
        return ['action' => 'insert', 'id' => $newId, 'fields_changed' => 3];
    }

    /**
     * v5.7 — Skill di un dipendente (con auto-creazione employee_skill se assente).
     */
    private function commitEmployeeSkill(array $r, ?int $userId): array
    {
        if (empty($r['employee_id']) || empty($r['skill_name'])) {
            throw new RuntimeException("employee_id e skill_name obbligatori");
        }
        $s = $this->pdo->prepare(
            "SELECT id FROM employee_skills WHERE employee_id = ? AND skill_name = ?"
        );
        $s->execute([$r['employee_id'], $r['skill_name']]);
        $existingId = $s->fetchColumn();
        if ($existingId) {
            return $this->upsertWithHistory(
                'employee_skills',
                ['id' => $existingId],
                $r,
                ['employee_id','skill_name','level','years','last_used','self_assessed','notes'],
                [],
                $userId, 'import', $this->jobId
            );
        }
        return $this->upsertWithHistory(
            'employee_skills',
            ['___nomatch___' => null],
            $r,
            ['employee_id','skill_name','level','years','last_used','self_assessed','notes'],
            [],
            $userId, 'import', $this->jobId
        );
    }

    /**
     * v5.7 — Helper: quando si crea un link tech↔cert, propaga ai certificati posseduti.
     * Inserisce in tech_user_certifications un record per ogni user_certifications matching.
     */
    private function propagateTechToUserCerts(int $techId, int $certId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO tech_user_certifications (technology_id, user_certification_id, auto_inferred)
             SELECT ?, uc.id, 1
               FROM user_certifications uc
              WHERE uc.certification_id = ?"
        );
        $stmt->execute([$techId, $certId]);
        return $stmt->rowCount();
    }

    /**
     * Aggiorna una riga di staging (correzione manuale via UI).
     */
    public function updateStagingRow(int $stagingId, array $newPayload, ?int $userId): array
    {
        // Carica
        $s = $this->pdo->prepare("SELECT * FROM import_staging_rows WHERE id = ?");
        $s->execute([$stagingId]);
        $staging = $s->fetch();
        if (!$staging) throw new RuntimeException("Riga non trovata.");

        // Carica tipo del job
        $j = $this->pdo->prepare("SELECT import_type FROM import_jobs WHERE id = ?");
        $j->execute([(int)$staging['job_id']]);
        $type = $j->fetchColumn();

        $validator = new ImportValidator($this->pdo, $type);
        $result = $validator->validateRow($newPayload);
        $status = $result['valid'] ? 'corrected' : 'invalid';
        $errorsJson = !empty($result['errors']) ? json_encode($result['errors'], JSON_UNESCAPED_UNICODE) : null;
        $newPayloadJson = json_encode(array_merge($newPayload, $result['normalized']), JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare(
            "UPDATE import_staging_rows
                SET status = ?, errors = ?, payload = ?, last_edit_at = NOW(), last_edit_by = ?
              WHERE id = ?"
        )->execute([$status, $errorsJson, $newPayloadJson, $userId, $stagingId]);

        // Riconta nel job
        $this->recalcJobStats((int)$staging['job_id']);

        return ['valid' => $result['valid'], 'errors' => $result['errors']];
    }

    /**
     * Ricalcola valid/invalid del job dopo correzioni manuali.
     */
    public function recalcJobStats(int $jobId): void
    {
        $stats = $this->pdo->prepare(
            "SELECT
                SUM(status IN ('valid','corrected')) AS valid_count,
                SUM(status = 'partial') AS partial_count,
                SUM(status = 'invalid') AS invalid_count,
                SUM(status = 'approved') AS approved_count,
                SUM(status = 'rejected') AS rejected_count,
                SUM(status = 'imported') AS imported_count,
                SUM(status = 'skipped') AS skipped_count
              FROM import_staging_rows WHERE job_id = ?"
        );
        $stats->execute([$jobId]);
        $s = $stats->fetch();

        // valid_rows = righe pronte / approvate per il commit (incluse approvate, valid, corrected)
        // partial_rows = LIVE = righe partial NON ancora approvate
        $this->pdo->prepare(
            "UPDATE import_jobs
                SET valid_rows     = ?,
                    invalid_rows   = ?,
                    approved_rows  = ?,
                    rejected_rows  = ?,
                    imported_rows  = ?,
                    skipped_rows   = ?
              WHERE id = ?"
        )->execute([
            (int)$s['valid_count'] + (int)$s['partial_count'],
            (int)$s['invalid_count'],
            (int)$s['approved_count'],
            (int)$s['rejected_count'],
            (int)$s['imported_count'],
            (int)$s['skipped_count'],
            $jobId,
        ]);
    }



    /**
     * v5.6 — Approva una singola riga di staging.
     *
     * @param int $stagingId  ID staging row
     * @param string $mode    'strict' (solo se valid/corrected) | 'ldb' (anche partial)
     * @param int|null $userId
     * @return array{ok:bool, new_status:string, missing_fields:array}
     */
    public function approveRow(int $stagingId, string $mode = 'strict', ?int $userId = null): array
    {
        if (!in_array($mode, ['strict', 'ldb'], true)) {
            throw new InvalidArgumentException("Modalità approvazione non valida: $mode");
        }

        $s = $this->pdo->prepare("SELECT * FROM import_staging_rows WHERE id = ?");
        $s->execute([$stagingId]);
        $row = $s->fetch();
        if (!$row) throw new RuntimeException("Riga staging non trovata.");

        // Regole di transizione:
        //  - status=valid     → ok in strict o ldb
        //  - status=corrected → ok in strict o ldb
        //  - status=partial   → solo in ldb (campi mancanti)
        //  - status=invalid   → mai (correggere prima)
        //  - status=approved  → idempotent (cambia solo modo se diverso)
        //  - status=imported  → blocca (già committato)
        $current = (string)$row['status'];
        $missing = json_decode((string)$row['missing_fields'], true) ?: [];

        if (in_array($current, ['imported','skipped'], true)) {
            throw new RuntimeException("Riga già committata, non approvabile.");
        }
        if ($current === 'invalid') {
            throw new RuntimeException("Riga con errori bloccanti: correggere prima di approvare.");
        }
        if ($current === 'partial' && $mode === 'strict') {
            throw new RuntimeException("Riga ha campi mancanti: usa modalità LDB o completa i campi.");
        }
        if (!in_array($current, ['valid','corrected','partial','approved','rejected'], true)) {
            throw new RuntimeException("Stato '$current' non approvabile.");
        }

        $this->pdo->prepare(
            "UPDATE import_staging_rows
                SET status = 'approved', approved_as = ?, approved_at = NOW(), approved_by = ?
              WHERE id = ?"
        )->execute([$mode, $userId, $stagingId]);

        $this->recalcJobStats((int)$row['job_id']);

        if (function_exists('write_log')) {
            write_log('Import', 'info',
                "Riga staging #$stagingId approvata in modalità $mode" .
                ($mode === 'ldb' && !empty($missing) ? " (LDB: " . count($missing) . " campi mancanti)" : ""),
                $userId);
        }

        return [
            'ok'             => true,
            'new_status'     => 'approved',
            'approved_as'    => $mode,
            'missing_fields' => $missing,
        ];
    }

    /**
     * v5.6 — Rifiuta esplicitamente una riga (la esclude dal commit).
     */
    public function rejectRow(int $stagingId, ?int $userId = null): array
    {
        $s = $this->pdo->prepare("SELECT job_id, status FROM import_staging_rows WHERE id = ?");
        $s->execute([$stagingId]);
        $row = $s->fetch();
        if (!$row) throw new RuntimeException("Riga staging non trovata.");
        if (in_array($row['status'], ['imported','skipped'], true)) {
            throw new RuntimeException("Riga già committata.");
        }

        $this->pdo->prepare(
            "UPDATE import_staging_rows
                SET status = 'rejected', approved_at = NOW(), approved_by = ?
              WHERE id = ?"
        )->execute([$userId, $stagingId]);

        $this->recalcJobStats((int)$row['job_id']);

        if (function_exists('write_log')) {
            write_log('Import', 'info', "Riga staging #$stagingId rifiutata", $userId);
        }
        return ['ok' => true, 'new_status' => 'rejected'];
    }

    /**
     * v5.6 — Annulla l'approvazione (riporta a valid/partial in base ai missing).
     */
    public function unapproveRow(int $stagingId, ?int $userId = null): array
    {
        $s = $this->pdo->prepare("SELECT job_id, status, missing_fields FROM import_staging_rows WHERE id = ?");
        $s->execute([$stagingId]);
        $row = $s->fetch();
        if (!$row) throw new RuntimeException("Riga staging non trovata.");

        // v5.8.1: blocca unapprove su righe già committate
        if (in_array($row['status'], ['imported','skipped'], true)) {
            throw new RuntimeException("Riga già committata, non è possibile annullare.");
        }

        $missing = json_decode((string)$row['missing_fields'], true) ?: [];
        $newStatus = !empty($missing) ? 'partial' : 'valid';

        $this->pdo->prepare(
            "UPDATE import_staging_rows
                SET status = ?, approved_as = NULL, approved_at = NULL, approved_by = NULL
              WHERE id = ?"
        )->execute([$newStatus, $stagingId]);

        $this->recalcJobStats((int)$row['job_id']);

        if (function_exists('write_log')) {
            write_log('Import', 'info', "Annullata approvazione riga staging #$stagingId", $userId);
        }
        return ['ok' => true, 'new_status' => $newStatus];
    }

    /**
     * v5.6 — Approvazione bulk: approva tutte le righe di un job in stato target.
     *
     * @param int $jobId
     * @param string $scope  'valid'    → solo valid/corrected (modalità strict)
     *                       'partial'  → solo partial (modalità ldb)
     *                       'all'      → valid+partial (strict per valid, ldb per partial)
     *                       'selected' → solo array di staging_id forniti
     * @param array $stagingIds  Solo se scope='selected'
     * @param int|null $userId
     * @return array{approved_strict:int, approved_ldb:int, skipped:int}
     */
    public function approveBulk(int $jobId, string $scope = 'all', array $stagingIds = [], ?int $userId = null): array
    {
        $approvedStrict = 0;
        $approvedLdb = 0;
        $skipped = 0;

        // Costruisco WHERE clause in base allo scope
        $where = "job_id = ?";
        $params = [$jobId];

        switch ($scope) {
            case 'valid':
                $where .= " AND status IN ('valid','corrected')";
                break;
            case 'partial':
                $where .= " AND status = 'partial'";
                break;
            case 'all':
                $where .= " AND status IN ('valid','corrected','partial')";
                break;
            case 'selected':
                if (empty($stagingIds)) return ['approved_strict' => 0, 'approved_ldb' => 0, 'skipped' => 0];
                $ids = array_map('intval', $stagingIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where .= " AND status IN ('valid','corrected','partial') AND id IN ($placeholders)";
                $params = array_merge($params, $ids);
                break;
            default:
                throw new InvalidArgumentException("Scope non valido: $scope");
        }

        $sel = $this->pdo->prepare("SELECT id, status FROM import_staging_rows WHERE $where");
        $sel->execute($params);

        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $mode = ((string)$r['status'] === 'partial') ? 'ldb' : 'strict';
            try {
                $this->approveRow((int)$r['id'], $mode, $userId);
                if ($mode === 'ldb') $approvedLdb++; else $approvedStrict++;
            } catch (Throwable $e) {
                $skipped++;
            }
        }

        $this->recalcJobStats($jobId);

        if (function_exists('write_log')) {
            write_log('Import', 'info',
                "Bulk approve job #$jobId scope=$scope: $approvedStrict strict + $approvedLdb LDB, $skipped skip",
                $userId);
        }

        return [
            'approved_strict' => $approvedStrict,
            'approved_ldb'    => $approvedLdb,
            'skipped'         => $skipped,
        ];
    }

    /**
     * v5.5 — Mette il job in coda per processing asincrono.
     * Restituisce true se il job è stato accodato.
     */
    public function enqueueJob(int $jobId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE import_jobs
                SET status = 'queued', queued_at = NOW()
              WHERE id = ? AND status IN ('uploaded','validated','partial')"
        );
        $stmt->execute([$jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * v5.5 — Late Data Binding completion API.
     *
     * Completa un singolo campo di un record importato in modalità parziale.
     * Aggiorna sia il record target (es. employees.date_of_birth) sia il
     * tracking nello staging (rimuove il campo da missing_fields).
     * Salva audit in import_partial_completions.
     *
     * @return array{ok:bool, completed:bool, remaining:int}
     *   - ok: true se update riuscito
     *   - completed: true se TUTTI i missing_fields sono ora compilati
     *   - remaining: campi ancora da completare
     */
    public function completePartialField(int $stagingId, string $fieldName, $newValue, ?int $userId = null): array
    {
        // Carica staging row
        $s = $this->pdo->prepare("SELECT * FROM import_staging_rows WHERE id = ? AND is_partial = 1");
        $s->execute([$stagingId]);
        $staging = $s->fetch();
        if (!$staging) throw new RuntimeException("Riga staging non trovata o non è LDB.");
        if (empty($staging['result_id'])) throw new RuntimeException("Riga senza target record.");

        // Carica tipo job e schema target table
        $j = $this->pdo->prepare("SELECT import_type FROM import_jobs WHERE id = ?");
        $j->execute([(int)$staging['job_id']]);
        $type = $j->fetchColumn();
        if (!$type) throw new RuntimeException("Job non trovato.");

        // Mappa tipo → tabella target
        $tableMap = self::getTargetTable($type);
        if (!$tableMap) throw new RuntimeException("Tipo $type non supportato per LDB.");

        // Verifica che il campo sia tra i missing_fields
        $missing = json_decode((string)$staging['missing_fields'], true) ?: [];
        if (!in_array($fieldName, $missing, true)) {
            throw new RuntimeException("Campo '$fieldName' non risulta tra quelli mancanti.");
        }

        // Validazione singolo campo
        $validator = new ImportValidator($this->pdo, $type);
        $schema = $validator->getFullSchema();
        if (!isset($schema[$fieldName])) {
            throw new RuntimeException("Campo '$fieldName' non in schema.");
        }
        $rules = $schema[$fieldName];

        // Mini-valid sul singolo campo
        $singleResult = $validator->validateRow([$fieldName => $newValue]);
        if (!empty($singleResult['errors'][$fieldName])) {
            throw new RuntimeException("Validazione fallita: " . $singleResult['errors'][$fieldName]);
        }
        $normalizedValue = $singleResult['normalized'][$fieldName] ?? $newValue;

        // Se è una FK, scrivi sul target column (fk_target), non su fieldName
        $targetColumn = $rules['fk_target'] ?? $fieldName;
        $targetValue = $normalizedValue;
        if (isset($rules['fk_target'])) {
            // Il valore normalizzato per FK è già l'ID
            $targetValue = $singleResult['normalized'][$rules['fk_target']] ?? null;
            if ($targetValue === null) {
                throw new RuntimeException("FK non risolto.");
            }
        }

        $targetTable = $tableMap;
        $targetId = (int)$staging['result_id'];

        $this->pdo->beginTransaction();
        try {
            // Leggi vecchio valore per audit
            $oldQ = $this->pdo->prepare("SELECT `$targetColumn` FROM `$targetTable` WHERE id = ?");
            $oldQ->execute([$targetId]);
            $oldValue = $oldQ->fetchColumn();

            // UPDATE record reale
            $this->pdo->prepare("UPDATE `$targetTable` SET `$targetColumn` = ? WHERE id = ?")
                      ->execute([$targetValue, $targetId]);

            // Aggiorna missing_fields in staging
            $missingNew = array_values(array_diff($missing, [$fieldName]));
            $payload = json_decode((string)$staging['payload'], true) ?: [];
            $payload[$fieldName] = $newValue;
            if (isset($rules['fk_target'])) {
                $payload[$rules['fk_target']] = $targetValue;
            }

            $newIsPartial = empty($missingNew) ? 0 : 1;

            $this->pdo->prepare(
                "UPDATE import_staging_rows
                    SET missing_fields = ?, payload = ?, is_partial = ?,
                        last_edit_at = NOW(), last_edit_by = ?
                  WHERE id = ?"
            )->execute([
                empty($missingNew) ? null : json_encode($missingNew, JSON_UNESCAPED_UNICODE),
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $newIsPartial,
                $userId,
                $stagingId,
            ]);

            // Audit log
            $this->pdo->prepare(
                "INSERT INTO import_partial_completions
                 (staging_id, target_table, target_id, field_name, old_value, new_value, completed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$stagingId, $targetTable, $targetId, $fieldName,
                        $oldValue !== false ? (string)$oldValue : null,
                        is_scalar($targetValue) ? (string)$targetValue : json_encode($targetValue),
                        $userId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        if (function_exists('write_log')) {
            write_log('Import', 'info', "LDB: completato $fieldName su $targetTable#$targetId (staging #$stagingId)", $userId);
        }

        return [
            'ok'        => true,
            'completed' => empty($missingNew),
            'remaining' => count($missingNew),
        ];
    }


    /**
     * v5.8 — Applica decisioni di approvazione/mappatura ENUM alle righe LDB.
     *
     * Dopo che un admin ha approvato una proposta (es. catalogo.level += "Senior"),
     * cerca tutte le righe staging in stato 'partial' che hanno quella proposta
     * pendente e completa il loro campo con il valore canonico.
     *
     * @param int $proposalId  ID della proposta enum (status='approved' o 'mapped')
     * @param int|null $userId
     * @return array{updated:int}
     */
    public function applyEnumDecision(int $proposalId, ?int $userId = null): array
    {
        $p = $this->pdo->prepare("SELECT * FROM enum_proposals WHERE id = ?");
        $p->execute([$proposalId]);
        $proposal = $p->fetch(PDO::FETCH_ASSOC);
        if (!$proposal) throw new RuntimeException("Proposta non trovata.");
        if (!in_array($proposal['status'], ['approved','mapped'], true)) {
            throw new RuntimeException("Proposta non risolta (status={$proposal['status']}).");
        }

        $valueToWrite = $proposal['status'] === 'approved'
            ? $proposal['proposed_value']
            : $proposal['mapped_to'];

        // Cerca tutte le righe staging is_partial=1 con questa proposta nel payload
        // (il marker __enum_proposals__ è stato sganciato al commit, ma il missing_fields
        //  contiene il nome campo; cross-matching via target table)
        $sel = $this->pdo->prepare(
            "SELECT isr.id, isr.payload, isr.missing_fields, isr.result_id, j.import_type
               FROM import_staging_rows isr
               JOIN import_jobs j ON j.id = isr.job_id
              WHERE isr.is_partial = 1"
        );
        $sel->execute();
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

        $updated = 0;
        foreach ($rows as $r) {
            $missing = json_decode((string)$r['missing_fields'], true) ?: [];
            $payload = json_decode((string)$r['payload'], true) ?: [];
            $proposals = $payload['__enum_proposals__'] ?? null;
            if (!is_array($proposals)) continue;

            foreach ($proposals as $field => $info) {
                if (!is_array($info)) continue;
                if (($info['target'] ?? '') !== $proposal['target_table'] . '.' . $proposal['target_column']) continue;
                if (($info['proposed_value'] ?? '') !== $proposal['proposed_value']) continue;

                // Trovato match: completa il campo
                try {
                    $this->completePartialField((int)$r['id'], $field, $valueToWrite, $userId);
                    $updated++;
                } catch (Throwable $e) {
                    if (function_exists('write_log')) {
                        write_log('Import', 'warning',
                            "applyEnumDecision: errore su staging #{$r['id']} field=$field: " . $e->getMessage(),
                            $userId);
                    }
                }
            }
        }

        if (function_exists('write_log')) {
            write_log('Import', 'info',
                "applyEnumDecision proposta #$proposalId: completate $updated righe LDB", $userId);
        }
        return ['updated' => $updated];
    }

    /**
     * Mappa tipo import → tabella target per LDB.
     */
    private static function getTargetTable(string $type): ?string
    {
        return match ($type) {
            'dipendenti'        => 'employees',
            'accessi'           => 'users',
            'brand'             => 'brands',
            'tecnologie'        => 'technologies',
            'catalogo'          => 'certifications',
            'sedi'              => 'locations',
            'agenzie'           => 'agencies',
            'contatti_agenzie'  => 'agency_contacts',
            'candidati'         => 'candidates',
            'clienti'           => 'clients',
            'certificati'       => 'user_certifications',
            'piani_formativi'   => 'training_plans',
            'esami'             => 'planned_exams',
            default             => null,
        };
    }

    /**
     * v5.5 — Lista record con LDB attivo (campi mancanti) per pagina dedicata.
     */
    public static function listPartialRecords(PDO $pdo, ?string $importType = null, ?int $userId = null, int $limit = 200): array
    {
        $where = ["isr.is_partial = 1", "isr.missing_fields IS NOT NULL"];
        $params = [];
        if ($importType !== null) { $where[] = "j.import_type = ?"; $params[] = $importType; }
        if ($userId !== null)     { $where[] = "j.created_by = ?";  $params[] = $userId; }
        $sql = "SELECT isr.id AS staging_id, isr.job_id, isr.row_number, isr.payload,
                       isr.missing_fields, isr.result_id, isr.imported_at,
                       isr.last_edit_at, isr.last_edit_by,
                       j.import_type, j.original_name, j.created_by AS job_created_by
                  FROM import_staging_rows isr
                  JOIN import_jobs j ON j.id = isr.job_id
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY isr.imported_at DESC, isr.id DESC
                 LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

}
