<?php
/**
 * PortalManager 1.2.0 — app/LinkedInImporter.php
 *
 * Importazione/aggiornamento profilo LinkedIn da export ufficiale del dipendente.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  NOTA LEGALE E ARCHITETTURALE
 * ───────────────────────────────────────────────────────────────────────
 *  LinkedIn vieta lo scraping HTTP automatizzato anche dei profili pubblici
 *  (User Agreement, cause hiQ Labs 2017–2022 con esito permanent injunction).
 *  Implementare scraping diretto da www.linkedin.com/in/<vanity> espone
 *  l'azienda a rischio legale e blocchi IP del server.
 *
 *  Il metodo ufficiale, GDPR-compliant, è l'export personale:
 *    LinkedIn → Settings & Privacy → Data Privacy → Get a copy of your data
 *  Produce uno ZIP con CSV: Profile.csv, Positions.csv, Education.csv,
 *  Certifications.csv, Skills.csv, Languages.csv, Projects.csv, ecc.
 *  Disponibile in ~10 minuti per "Want something in particular" o ~24h per "The Works".
 *
 *  Alternativa rapida: il PDF generato da More → Save to PDF sul profilo pubblico.
 *
 *  Questa classe gestisce ENTRAMBE le sorgenti:
 *    - ZIP archive (cartella estratta con i CSV)
 *    - PDF profilo (parser testuale euristico)
 *    - singolo CSV (Certifications.csv o Profile.csv)
 * ───────────────────────────────────────────────────────────────────────
 */

class LinkedInImporter
{
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
                  WHERE setting_key = 'linkedin_auto_create_catalog' LIMIT 1"
            )->fetchColumn();
            $this->autoCreateCatalog = ($v === false || $v === null) ? true : ($v === '1');
        } catch (Throwable $e) {
            $this->autoCreateCatalog = true;
        }
    }

    /**
     * Estrae lo username/vanity LinkedIn da un URL profilo.
     * Accetta:
     *   - https://www.linkedin.com/in/a-orru750122
     *   - https://www.linkedin.com/in/a-orru750122/
     *   - https://it.linkedin.com/in/mario-rossi
     *   - linkedin.com/in/username
     *   - "a-orru750122" (passa così com'è)
     */
    public static function parseVanity(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') return null;

        if (preg_match('~linkedin\.com/in/([a-zA-Z0-9_\-]+)~i', $input, $m)) {
            return $m[1];
        }
        if (preg_match('~^[a-zA-Z0-9_\-]+$~', $input)) {
            return $input;
        }
        return null;
    }

    /**
     * Costruisce l'URL pubblico del profilo a partire dalla vanity.
     */
    public static function profileUrl(string $vanity): string
    {
        return 'https://www.linkedin.com/in/' . urlencode($vanity);
    }

    /**
     * Importa l'intero archivio LinkedIn da un file caricato.
     * Accetta: ZIP archive, PDF profilo, singolo CSV.
     *
     * @param int    $employeeId
     * @param string $filePath   path al file caricato (validato dal chiamante)
     * @param string $vanity     username/vanity LinkedIn associato al dipendente
     * @return array stats con detail per ogni elemento processato
     */
    public function syncFromFile(int $employeeId, string $filePath, string $vanity): array
    {
        $this->stats = [
            'imported' => 0, 'updated' => 0, 'unchanged' => 0,
            'created_cert' => 0, 'unmatched' => 0, 'errors' => 0,
            'profile_updated' => false,
            'detail' => [],
        ];

        if (!is_file($filePath)) {
            throw new RuntimeException("File non trovato: $filePath");
        }

        $mime = $this->detectMime($filePath);
        $data = [];

        if (in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)
            || strtolower(substr($filePath, -4)) === '.zip') {
            $data = $this->parseZipArchive($filePath);
        } elseif ($mime === 'application/pdf'
                  || strtolower(substr($filePath, -4)) === '.pdf') {
            $data = $this->parsePdfProfile($filePath);
        } elseif ($mime === 'text/csv'
                  || strtolower(substr($filePath, -4)) === '.csv') {
            $data = $this->parseSingleCsv($filePath);
        } else {
            throw new RuntimeException("Formato non supportato: $mime. Caricare un ZIP, PDF o CSV LinkedIn ufficiale.");
        }

        // Processa certificazioni
        if (!empty($data['certifications'])) {
            foreach ($data['certifications'] as $cert) {
                try {
                    $result = $this->importCertification($employeeId, $cert);
                    $this->stats[$result] = ($this->stats[$result] ?? 0) + 1;
                    $this->stats['detail'][] = [
                        'type' => 'certification',
                        'name' => $cert['name'],
                        'result' => $result,
                    ];
                } catch (Throwable $e) {
                    $this->stats['errors']++;
                    $this->stats['detail'][] = [
                        'type' => 'certification',
                        'name' => $cert['name'] ?? '?',
                        'result' => 'error',
                        'note' => $e->getMessage(),
                    ];
                }
            }
        }

        // Aggiorna profilo (bio, skills) se i dati lo consentono
        if (!empty($data['profile']) || !empty($data['skills']) || !empty($data['positions'])) {
            try {
                $changed = $this->updateEmployeeProfile($employeeId, $data);
                if ($changed) {
                    $this->stats['profile_updated'] = true;
                    $this->stats['detail'][] = [
                        'type' => 'profile',
                        'name' => 'CV / bio / skills',
                        'result' => 'updated',
                    ];
                }
            } catch (Throwable $e) {
                $this->stats['errors']++;
                $this->stats['detail'][] = [
                    'type' => 'profile',
                    'name' => 'CV',
                    'result' => 'error',
                    'note' => $e->getMessage(),
                ];
            }
        }

        // Salva CV PDF originale se è un PDF
        if (strtolower(substr($filePath, -4)) === '.pdf') {
            try {
                $this->storeCvPdf($employeeId, $filePath);
                $this->stats['detail'][] = [
                    'type' => 'cv',
                    'name' => 'CV PDF salvato come allegato',
                    'result' => 'imported',
                ];
            } catch (Throwable $e) {
                // best-effort
            }
        }

        // Aggiorna last sync sul link
        $this->pdo->prepare(
            "UPDATE employee_linkedin_link
                SET last_sync_at = NOW(),
                    last_sync_imported = ?,
                    last_sync_updated = ?,
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

    // ═══════════════════════════════════════════════════════════════════
    //  PARSER ZIP ARCHIVE LINKEDIN
    // ═══════════════════════════════════════════════════════════════════
    /**
     * LinkedIn ZIP standard contiene CSV come:
     *   Profile.csv, Positions.csv, Education.csv, Skills.csv,
     *   Certifications.csv, Languages.csv, Projects.csv, Publications.csv
     */
    private function parseZipArchive(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione PHP zip non disponibile.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Impossibile aprire lo ZIP LinkedIn.');
        }

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $base = basename($name);
            if (strtolower(substr($base, -4)) !== '.csv') continue;
            // Normalizza il nome (Linkedin a volte usa case diverso)
            $key = strtolower(preg_replace('~\.csv$~i', '', $base));
            $content = $zip->getFromIndex($i);
            if ($content !== false) $files[$key] = $content;
        }
        $zip->close();

        if (!$files) {
            throw new RuntimeException('Nessun CSV LinkedIn trovato nello ZIP.');
        }

        $out = ['certifications' => [], 'positions' => [], 'education' => [],
                'skills' => [], 'profile' => null];

        // Certifications.csv
        if (isset($files['certifications'])) {
            $out['certifications'] = $this->parseCertificationsCsv($files['certifications']);
        }
        // Positions.csv
        if (isset($files['positions'])) {
            $out['positions'] = $this->parsePositionsCsv($files['positions']);
        }
        // Education.csv
        if (isset($files['education'])) {
            $out['education'] = $this->parseEducationCsv($files['education']);
        }
        // Skills.csv
        if (isset($files['skills'])) {
            $out['skills'] = $this->parseSkillsCsv($files['skills']);
        }
        // Profile.csv
        if (isset($files['profile'])) {
            $out['profile'] = $this->parseProfileCsv($files['profile']);
        }
        // Languages.csv
        if (isset($files['languages'])) {
            $out['languages'] = $this->parseLanguagesCsv($files['languages']);
        }

        return $out;
    }

    private function parseSingleCsv(string $csvPath): array
    {
        $content = file_get_contents($csvPath);
        if ($content === false) throw new RuntimeException('Impossibile leggere CSV.');

        // Auto-detect: il file ha header tipo Certifications?
        $firstLine = strtolower(substr($content, 0, 500));
        if (preg_match('~name.*authority|certification|license~', $firstLine)) {
            return ['certifications' => $this->parseCertificationsCsv($content)];
        }
        if (preg_match('~first.?name|last.?name|headline|summary~', $firstLine)) {
            return ['profile' => $this->parseProfileCsv($content)];
        }
        if (preg_match('~company.?name|position|title|role~', $firstLine)) {
            return ['positions' => $this->parsePositionsCsv($content)];
        }
        if (preg_match('~skill.?name|^skill~', $firstLine)) {
            return ['skills' => $this->parseSkillsCsv($content)];
        }

        throw new RuntimeException('Tipo CSV LinkedIn non riconosciuto. Carica lo ZIP completo o usa Certifications.csv / Profile.csv.');
    }

    /**
     * Certifications.csv (header tipico LinkedIn):
     *   Name, Authority, Started On, Finished On, License Number, Url
     */
    private function parseCertificationsCsv(string $content): array
    {
        $rows = $this->parseCsv($content);
        $out = [];
        foreach ($rows as $r) {
            $name = $r['Name'] ?? $r['name'] ?? '';
            if (!$name) continue;
            $out[] = [
                'name'        => trim($name),
                'authority'   => trim($r['Authority'] ?? $r['authority'] ?? ''),
                'issued_at'   => $this->parseLinkedInDate($r['Started On'] ?? $r['started_on'] ?? ''),
                'expires_at'  => $this->parseLinkedInDate($r['Finished On'] ?? $r['finished_on'] ?? ''),
                'license_num' => trim($r['License Number'] ?? $r['license_number'] ?? ''),
                'url'         => trim($r['Url'] ?? $r['url'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Profile.csv (header tipico):
     *   First Name, Last Name, Maiden Name, Address, Birth Date, Headline, Summary, Industry
     */
    private function parseProfileCsv(string $content): ?array
    {
        $rows = $this->parseCsv($content);
        if (!$rows) return null;
        $r = $rows[0];
        return [
            'first_name' => trim($r['First Name']      ?? $r['first_name']  ?? ''),
            'last_name'  => trim($r['Last Name']       ?? $r['last_name']   ?? ''),
            'headline'   => trim($r['Headline']        ?? $r['headline']    ?? ''),
            'summary'    => trim($r['Summary']         ?? $r['summary']     ?? ''),
            'industry'   => trim($r['Industry']        ?? $r['industry']    ?? ''),
            'location'   => trim($r['Geo Location']    ?? $r['geo_location']?? ''),
        ];
    }

    /**
     * Positions.csv (header tipico):
     *   Company Name, Title, Description, Location, Started On, Finished On
     */
    private function parsePositionsCsv(string $content): array
    {
        $rows = $this->parseCsv($content);
        $out = [];
        foreach ($rows as $r) {
            $company = trim($r['Company Name'] ?? $r['company_name'] ?? '');
            $title   = trim($r['Title']        ?? $r['title']        ?? '');
            if (!$company && !$title) continue;
            $out[] = [
                'company'    => $company,
                'title'      => $title,
                'description'=> trim($r['Description'] ?? $r['description'] ?? ''),
                'location'   => trim($r['Location']    ?? $r['location']    ?? ''),
                'started_at' => $this->parseLinkedInDate($r['Started On'] ?? $r['started_on'] ?? ''),
                'ended_at'   => $this->parseLinkedInDate($r['Finished On'] ?? $r['finished_on'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Education.csv (header tipico):
     *   School Name, Start Date, End Date, Notes, Degree Name, Activities
     */
    private function parseEducationCsv(string $content): array
    {
        $rows = $this->parseCsv($content);
        $out = [];
        foreach ($rows as $r) {
            $school = trim($r['School Name'] ?? $r['school_name'] ?? '');
            if (!$school) continue;
            $out[] = [
                'school'     => $school,
                'degree'     => trim($r['Degree Name'] ?? $r['degree_name'] ?? ''),
                'started_at' => $this->parseLinkedInDate($r['Start Date'] ?? $r['start_date'] ?? ''),
                'ended_at'   => $this->parseLinkedInDate($r['End Date']   ?? $r['end_date']   ?? ''),
                'notes'      => trim($r['Notes'] ?? $r['notes'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Skills.csv (header tipico):
     *   Name
     */
    private function parseSkillsCsv(string $content): array
    {
        $rows = $this->parseCsv($content);
        $out = [];
        foreach ($rows as $r) {
            $name = trim($r['Name'] ?? $r['name'] ?? '');
            if ($name) $out[] = $name;
        }
        return array_values(array_unique($out));
    }

    /**
     * Languages.csv: Name, Proficiency
     */
    private function parseLanguagesCsv(string $content): array
    {
        $rows = $this->parseCsv($content);
        $out = [];
        foreach ($rows as $r) {
            $name = trim($r['Name'] ?? $r['name'] ?? '');
            if (!$name) continue;
            $out[] = [
                'name' => $name,
                'level' => trim($r['Proficiency'] ?? $r['proficiency'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * CSV parser robusto: BOM, encoding, separatore auto.
     */
    private function parseCsv(string $content): array
    {
        // Rimuovi BOM
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);

        // Normalizza line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Auto-detect separatore (LinkedIn usa virgola di solito)
        $firstLine = strstr($content, "\n", true) ?: $content;
        $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $content);
        rewind($fh);

        $header = fgetcsv($fh, 0, $sep);
        if (!$header) { fclose($fh); return []; }
        // Trim header
        $header = array_map(fn($h) => trim($h), $header);

        while (($r = fgetcsv($fh, 0, $sep)) !== false) {
            if (count($r) === 1 && trim($r[0]) === '') continue;
            // Pad se mancano colonne
            while (count($r) < count($header)) $r[] = '';
            $rows[] = array_combine($header, array_slice($r, 0, count($header)));
        }
        fclose($fh);
        return $rows;
    }

    /**
     * LinkedIn date format: "Jan 2024", "Mar 2018", "2020", "Jan 1, 2024"
     */
    private function parseLinkedInDate(string $s): ?string
    {
        $s = trim($s);
        if (!$s) return null;

        $months = [
            'jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
            'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12',
            'gen'=>'01','mag'=>'05','giu'=>'06','lug'=>'07','ago'=>'08','set'=>'09','ott'=>'10','dic'=>'12'
        ];

        if (preg_match('~^([A-Za-z]{3})\s+(\d{1,2}),?\s+(\d{4})$~', $s, $m)) {
            $mm = $months[strtolower($m[1])] ?? null;
            if ($mm) return sprintf('%04d-%s-%02d', $m[3], $mm, $m[2]);
        }
        if (preg_match('~^([A-Za-z]{3})\s+(\d{4})$~', $s, $m)) {
            $mm = $months[strtolower($m[1])] ?? null;
            if ($mm) return $m[2] . '-' . $mm . '-01';
        }
        if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('~^(\d{4})$~', $s)) {
            return $s . '-01-01';
        }
        // Fallback strtotime
        $t = strtotime($s);
        return $t !== false ? date('Y-m-d', $t) : null;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PARSER PDF PROFILO
    // ═══════════════════════════════════════════════════════════════════
    /**
     * Parsing euristico del PDF "Save to PDF" di LinkedIn.
     * Estrae sezioni: Summary, Experience, Education, Licenses & certifications, Skills.
     */
    private function parsePdfProfile(string $pdfPath): array
    {
        $text = $this->extractPdfText($pdfPath);
        if (!$text) {
            throw new RuntimeException('Impossibile estrarre testo dal PDF. Carica lo ZIP CSV LinkedIn invece.');
        }

        $out = ['certifications' => [], 'positions' => [], 'education' => [],
                'skills' => [], 'profile' => null];

        // Sezione Summary
        if (preg_match('~Summary\s*\n+(.*?)(?=\n(?:Experience|Education|Licenses|Skills|Languages|Honors)\b)~si', $text, $m)) {
            $out['profile'] = ['summary' => trim($m[1])];
        }

        // Sezione Licenses & certifications
        if (preg_match('~Licenses?\s*(?:&|and)\s*[Cc]ertifications?\s*\n+(.*?)(?=\n(?:Skills|Languages|Education|Honors|Publications|Projects)\b|$)~si', $text, $m)) {
            $section = $m[1];
            // Pattern: "Nome cert\nIssuing Authority\nIssued Mese Anno"
            $lines = preg_split('~\n+~', $section);
            $i = 0;
            while ($i < count($lines)) {
                $name = trim($lines[$i] ?? '');
                if (strlen($name) < 5 || strlen($name) > 200) { $i++; continue; }
                if (preg_match('~^(Issued|Issuer|Credential|Skills)~i', $name)) { $i++; continue; }

                $authority = trim($lines[$i + 1] ?? '');
                $issuedLine = trim($lines[$i + 2] ?? '');

                $issuedAt = null;
                if (preg_match('~(?:Issued|Conseguita)\s+([A-Za-z]{3}\s+\d{4})~i', $issuedLine, $dm)) {
                    $issuedAt = $this->parseLinkedInDate($dm[1]);
                }

                if ($name && $authority && !preg_match('~Issued|Skills|^$~i', $authority)) {
                    $out['certifications'][] = [
                        'name'        => $name,
                        'authority'   => $authority,
                        'issued_at'   => $issuedAt,
                        'expires_at'  => null,
                        'license_num' => '',
                        'url'         => '',
                    ];
                    $i += 3;
                } else {
                    $i++;
                }
            }
        }

        // Sezione Skills
        if (preg_match('~Skills\s*\n+(.*?)(?=\n(?:Languages|Education|Honors|Publications)\b|$)~si', $text, $m)) {
            $skills = preg_split('~\n+~', trim($m[1]));
            $out['skills'] = array_values(array_filter(array_map('trim', $skills), fn($s) => $s && strlen($s) < 80));
        }

        return $out;
    }

    /**
     * Estrae testo da PDF.
     * Usa pdftotext se disponibile (più affidabile), altrimenti fallback PHP puro.
     */
    private function extractPdfText(string $pdfPath): string
    {
        // Tentativo 1: pdftotext (poppler) - più affidabile
        $cmd = (PHP_OS_FAMILY === 'Windows') ? 'where pdftotext' : 'which pdftotext';
        @exec($cmd, $out, $rc);
        if ($rc === 0) {
            $tmp = tempnam(sys_get_temp_dir(), 'lipdf_');
            @exec(escapeshellcmd('pdftotext') . ' -layout ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmp));
            if (is_file($tmp)) {
                $text = (string)file_get_contents($tmp);
                @unlink($tmp);
                if (trim($text) !== '') return $text;
            }
        }

        // Tentativo 2: parser PHP puro minimale (estrae stringhe BT/ET dei PDF testuali)
        $raw = (string)file_get_contents($pdfPath);
        if (preg_match_all('~BT\s+(.*?)\s+ET~s', $raw, $m)) {
            $text = '';
            foreach ($m[1] as $block) {
                if (preg_match_all('~\((.*?)\)\s*Tj~s', $block, $tm)) {
                    foreach ($tm[1] as $s) {
                        $s = str_replace(['\(', '\)', '\\\\', '\n', '\r', '\t'],
                                        ['(', ')', '\\', "\n", "\r", "\t"], $s);
                        $text .= $s . ' ';
                    }
                }
                $text .= "\n";
            }
            if (trim($text) !== '') return $text;
        }

        return '';
    }

    // ═══════════════════════════════════════════════════════════════════
    //  IMPORT CERTIFICAZIONE
    // ═══════════════════════════════════════════════════════════════════
    private function importCertification(int $employeeId, array $cert): string
    {
        $certId = $this->matchToCatalog($cert);
        $autoCreated = false;

        if ($certId === null) {
            if ($this->autoCreateCatalog) {
                $certId = $this->createCatalogEntry($cert);
                $autoCreated = true;
            } else {
                return 'unmatched';
            }
        }

        // Cerca user_certifications esistente
        $stmt = $this->pdo->prepare(
            "SELECT id, issue_date, expiry_date, status, certificate_code
               FROM user_certifications
              WHERE employee_id = ?
                AND certification_id = ?
                AND (notes LIKE ? OR (? <> '' AND certificate_code = ?))
              LIMIT 1"
        );
        $licNum = $cert['license_num'] ?? '';
        $marker = 'linkedin_cert:' . md5($cert['name'] . '|' . $cert['authority']);
        $stmt->execute([
            $employeeId, $certId,
            '%' . $marker . '%',
            $licNum, $licNum,
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $issueDate  = $cert['issued_at']  ?: date('Y-m-d');
        $expiryDate = $cert['expires_at'] ?: null;
        $status     = $this->computeStatus($expiryDate);
        $code       = $licNum ?: $marker;
        $notes      = sprintf(
            "Importato da LinkedIn il %s\n%s\nlinkedin_authority:%s%s",
            date('Y-m-d H:i'),
            $marker,
            $cert['authority'],
            !empty($cert['url']) ? "\nlinkedin_cred_url:" . $cert['url'] : ''
        );

        if ($existing) {
            $changed = false;
            $diff = [];
            foreach (['issue_date'=>$issueDate, 'expiry_date'=>$expiryDate, 'status'=>$status] as $col => $new) {
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

        // INSERT
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
            'source' => 'linkedin',
            'name' => $cert['name'],
            'authority' => $cert['authority'],
            'auto_created_cert' => $autoCreated,
        ]);

        return $autoCreated ? 'created_cert' : 'imported';
    }

    private function matchToCatalog(array $cert): ?int
    {
        if (empty($cert['name'])) return null;

        // 1. Esatto nome + brand
        if (!empty($cert['authority'])) {
            $s = $this->pdo->prepare(
                "SELECT c.id FROM certifications c
                   JOIN brands b ON c.brand_id = b.id
                  WHERE LOWER(TRIM(c.name)) = LOWER(TRIM(?))
                    AND LOWER(TRIM(b.name)) = LOWER(TRIM(?))
                    AND c.is_active = 1
                  LIMIT 1"
            );
            $s->execute([$cert['name'], $cert['authority']]);
            $id = $s->fetchColumn();
            if ($id) return (int)$id;
        }

        // 2. Solo nome esatto (può creare ambiguità ma è raro)
        $s = $this->pdo->prepare(
            "SELECT id FROM certifications
              WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
                AND is_active = 1
              LIMIT 1"
        );
        $s->execute([$cert['name']]);
        $id = $s->fetchColumn();
        if ($id) return (int)$id;

        return null;
    }

    private function createCatalogEntry(array $cert): int
    {
        $brandId = $this->ensureBrand($cert['authority'] ?: 'LinkedIn');
        $techId  = $this->ensureDefaultTechnology();

        // Codice univoco
        $code = preg_replace('~[^a-z0-9]+~', '-', strtolower($cert['name']));
        $code = trim($code, '-');
        $code = substr($code, 0, 50);
        $origCode = $code;
        $i = 2;
        while (true) {
            $s = $this->pdo->prepare("SELECT id FROM certifications WHERE code = ? LIMIT 1");
            $s->execute([$code]);
            if (!$s->fetchColumn()) break;
            $code = substr($origCode, 0, 45) . '-' . $i++;
            if ($i > 20) { $code = $origCode . '-li' . substr(md5($cert['name'].$cert['authority']), 0, 4); break; }
        }

        $level = $this->guessLevel($cert['name']);
        $desc = !empty($cert['url']) ? ("Verifica: " . $cert['url']) : '';

        $this->pdo->prepare(
            "INSERT INTO certifications
                (brand_id, technology_id, name, code, category, level,
                 description, exam_url, is_active, updated_by)
             VALUES (?,?,?,?,?,?,?,?,1,?)"
        )->execute([
            $brandId, $techId, $cert['name'], $code, 'tecnica',
            $level, $desc, $cert['url'] ?: null, $this->actorUserId
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        $this->auditChange('certifications', $newId, 'insert', [
            'source' => 'linkedin_auto',
            'authority' => $cert['authority'],
            'name' => $cert['name'],
        ]);
        return $newId;
    }

    private function ensureBrand(string $name): int
    {
        $key = strtolower(trim($name));
        if (isset($this->brandCache[$key])) return $this->brandCache[$key];

        $s = $this->pdo->prepare("SELECT id FROM brands WHERE LOWER(TRIM(name)) = ? LIMIT 1");
        $s->execute([$key]);
        $id = $s->fetchColumn();
        if ($id) { $this->brandCache[$key] = (int)$id; return (int)$id; }

        $this->pdo->prepare(
            "INSERT INTO brands (name, description, partnership_level)
             VALUES (?, ?, 'Registered')"
        )->execute([$name, 'Brand creato automaticamente da import LinkedIn']);
        $newId = (int)$this->pdo->lastInsertId();
        $this->brandCache[$key] = $newId;

        $this->auditChange('brands', $newId, 'insert', [
            'source' => 'linkedin_auto', 'name' => $name,
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

        if ($id) { $this->defaultTechId = (int)$id; return (int)$id; }

        $this->pdo->prepare(
            "INSERT INTO technologies (name, description, slug, icon, color, is_active)
             VALUES ('Generale','Tecnologia generica (placeholder)','generale','fa-tag','#94a3b8',1)"
        )->execute();
        $newId = (int)$this->pdo->lastInsertId();
        $this->defaultTechId = $newId;
        return $newId;
    }

    private function guessLevel(string $name): ?string
    {
        $n = strtolower($name);
        if (preg_match('~\b(expert|architect|master)\b~', $n))             return 'Expert';
        if (preg_match('~\b(professional|advanced|senior|ccnp|ccie)\b~', $n)) return 'Professional';
        if (preg_match('~\b(associate|intermediate|ccna)\b~', $n))          return 'Associate';
        if (preg_match('~\b(foundation|fundamental|basic|essentials|entry)\b~', $n)) return 'Foundation';
        if (preg_match('~\b(specialist|specialty)\b~', $n))                 return 'Specialist';
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  UPDATE PROFILO DIPENDENTE (CV)
    // ═══════════════════════════════════════════════════════════════════
    /**
     * Aggiorna bio, technical_skills, soft_skills SENZA sovrascrivere
     * dati esistenti del portale. Merge: aggiunge solo se vuoto o
     * se la versione LinkedIn è più completa.
     */
    private function updateEmployeeProfile(int $employeeId, array $data): bool
    {
        $s = $this->pdo->prepare("SELECT bio, technical_skills, soft_skills FROM employees WHERE id = ?");
        $s->execute([$employeeId]);
        $emp = $s->fetch(PDO::FETCH_ASSOC);
        if (!$emp) return false;

        $changed = false;
        $diff = [];
        $update = [];

        // BIO: combina headline + summary dal profilo LinkedIn
        $linkedinBio = '';
        if (!empty($data['profile']['headline'])) {
            $linkedinBio .= $data['profile']['headline'];
        }
        if (!empty($data['profile']['summary'])) {
            $linkedinBio .= ($linkedinBio ? "\n\n" : '') . $data['profile']['summary'];
        }

        // Aggiungi cronologia esperienze come testo nel CV
        if (!empty($data['positions'])) {
            $exp = "\n\n— Esperienze (LinkedIn) —";
            foreach (array_slice($data['positions'], 0, 10) as $p) {
                $period = ($p['started_at'] ?? '') . ' → ' . ($p['ended_at'] ?: 'in corso');
                $exp .= "\n• " . $p['title'] . ' @ ' . $p['company'] . ' (' . $period . ')';
                if ($p['description']) {
                    $exp .= "\n  " . substr($p['description'], 0, 200);
                }
            }
            $linkedinBio .= $exp;
        }

        if ($linkedinBio && (empty($emp['bio']) || strlen($linkedinBio) > strlen($emp['bio']) * 0.8)) {
            $update['bio'] = $linkedinBio;
            $diff['bio'] = ['old' => $emp['bio'], 'new' => substr($linkedinBio, 0, 200) . '...'];
            $changed = true;
        }

        // Skills tecniche: merge
        if (!empty($data['skills'])) {
            $current = array_filter(array_map('trim', explode(',', $emp['technical_skills'] ?? '')));
            $merged = array_values(array_unique(array_merge($current, $data['skills'])));
            if (count($merged) !== count($current)) {
                $update['technical_skills'] = implode(', ', $merged);
                $diff['technical_skills_added'] = array_values(array_diff($merged, $current));
                $changed = true;
            }
        }

        if (!$changed) return false;

        $sets  = [];
        $vals  = [];
        foreach ($update as $col => $val) { $sets[] = "$col = ?"; $vals[] = $val; }
        $vals[] = $employeeId;

        $this->pdo->prepare(
            "UPDATE employees SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = ?"
        )->execute($vals);

        $this->auditChange('employees', $employeeId, 'update', array_merge(
            ['source' => 'linkedin'], $diff
        ));

        return true;
    }

    /**
     * Salva il PDF del profilo come allegato CV del dipendente.
     * Lo memorizza in uploads/cv_dipendenti/ e aggiorna employees.cv_path.
     */
    private function storeCvPdf(int $employeeId, string $sourcePath): void
    {
        $destDir = dirname(__DIR__) . '/uploads/cv_dipendenti';
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $fname = 'linkedin_emp' . $employeeId . '_' . date('Ymd_His') . '.pdf';
        $destPath = $destDir . '/' . $fname;
        if (!@copy($sourcePath, $destPath)) {
            throw new RuntimeException('Impossibile salvare il PDF nel portale.');
        }
        @chmod($destPath, 0644);

        $relPath = 'cv_dipendenti/' . $fname;
        $s = $this->pdo->prepare("SELECT cv_path FROM employees WHERE id = ?");
        $s->execute([$employeeId]);
        $oldCv = $s->fetchColumn();

        $this->pdo->prepare("UPDATE employees SET cv_path = ?, updated_at = NOW() WHERE id = ?")
                  ->execute([$relPath, $employeeId]);

        $this->auditChange('employees', $employeeId, 'update', [
            'source' => 'linkedin', 'cv_path' => ['old' => $oldCv, 'new' => $relPath],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  UTILITY
    // ═══════════════════════════════════════════════════════════════════
    private function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $m = $f ? (finfo_file($f, $path) ?: 'application/octet-stream') : 'application/octet-stream';
            if ($f) finfo_close($f);
            return $m;
        }
        return mime_content_type($path) ?: 'application/octet-stream';
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
                 VALUES (?, ?, ?, ?, 'linkedin', ?, NOW())"
            )->execute([
                $table, $recordId, $action,
                json_encode($diff, JSON_UNESCAPED_UNICODE),
                $this->actorUserId
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }
}
