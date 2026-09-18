<?php
/**
 * PortalManager v1.9.48 — app/LinkedInApplicantImporter.php
 *
 * Import del "Report candidati" LinkedIn (Job Applicant Report, .xlsx) verso
 * l'anagrafica candidati (`candidates`) del modulo Recruiting & Agenzie.
 *
 * Mapping: ogni candidato è associato alla posizione tramite il campo
 * «ID offerta di lavoro» del file = `job_positions.linkedin_code`.
 * Per ogni candidato associato viene generata un'anagrafica completa
 * (tutti i dettagli estratti dal file) e la relativa candidatura
 * (`candidate_applications`) sulla posizione mappata.
 *
 * Dipende solo da app/XlsxReader.php (parser XLSX nativo, zero librerie).
 */

require_once __DIR__ . '/XlsxReader.php';

final class LinkedInApplicantImporter
{
    private PDO $pdo;
    private int $actorUserId;

    /** Colonne LinkedIn (normalizzate) → chiave canonica interna. */
    private const MAP = [
        'nome'                                               => 'first_name',
        'cognome'                                            => 'last_name',
        'indirizzo email'                                    => 'email',
        'numero di telefono'                                 => 'phone',
        'località generica'                                  => 'city',
        'localita generica'                                  => 'city',
        'cap'                                                => 'postal_code',
        'sommario'                                           => 'headline',
        'qualifica attuale'                                  => 'current_title',
        'azienda attuale'                                    => 'current_company',
        'data di inizio della posizione lavorativa attuale'  => 'current_since',
        'titolo di studio'                                   => 'education_level',
        'istituto didattico'                                 => 'education_institute',
        'url profilo'                                        => 'linkedin_url',
        'data di candidatura'                                => 'applied_at',
        'fase attuale'                                        => 'li_stage',
        'id offerta di lavoro'                               => 'li_job_id',
        'qualifica'                                          => 'offer_title',
        'url offerta di lavoro'                              => 'offer_url',
        'id offerta di lavoro ats'                           => 'ats_id',
        'retribuzione minima'                                => 'pay_min',
        'retribuzione massima'                               => 'pay_max',
        'codice valuta'                                      => 'pay_ccy',
        'periodo di retribuzione'                            => 'pay_period',
        'id progetto di assunzione'                          => 'hiring_project_id',
        'titolo progetto di assunzione'                      => 'hiring_project_title',
        'id contratto'                                       => 'contract_id',
        'nome contratto'                                     => 'contract_name',
        'domande per la selezione'                           => 'screening_qa',
    ];

    /** Colonne LinkedIn-specifiche aggiunte a `candidates` (idempotente). */
    private const EXTRA_COLS = [
        'city'                => "ALTER TABLE candidates ADD COLUMN city VARCHAR(120) DEFAULT NULL",
        'postal_code'         => "ALTER TABLE candidates ADD COLUMN postal_code VARCHAR(20) DEFAULT NULL",
        'headline'            => "ALTER TABLE candidates ADD COLUMN headline VARCHAR(255) DEFAULT NULL",
        'current_title'       => "ALTER TABLE candidates ADD COLUMN current_title VARCHAR(180) DEFAULT NULL",
        'current_company'     => "ALTER TABLE candidates ADD COLUMN current_company VARCHAR(180) DEFAULT NULL",
        'current_since'       => "ALTER TABLE candidates ADD COLUMN current_since VARCHAR(20) DEFAULT NULL",
        'li_job_id'           => "ALTER TABLE candidates ADD COLUMN li_job_id VARCHAR(60) DEFAULT NULL",
        'li_stage'            => "ALTER TABLE candidates ADD COLUMN li_stage VARCHAR(80) DEFAULT NULL",
        'applied_at'          => "ALTER TABLE candidates ADD COLUMN applied_at DATE DEFAULT NULL",
        'education_level'     => "ALTER TABLE candidates ADD COLUMN education_level VARCHAR(80) DEFAULT NULL",
        'education_institute' => "ALTER TABLE candidates ADD COLUMN education_institute VARCHAR(200) DEFAULT NULL",
        'offer_title'         => "ALTER TABLE candidates ADD COLUMN offer_title VARCHAR(200) DEFAULT NULL",
        'offer_url'           => "ALTER TABLE candidates ADD COLUMN offer_url VARCHAR(500) DEFAULT NULL",
        'li_ats_id'           => "ALTER TABLE candidates ADD COLUMN li_ats_id VARCHAR(80) DEFAULT NULL",
        'pay_min'             => "ALTER TABLE candidates ADD COLUMN pay_min DECIMAL(12,2) DEFAULT NULL",
        'pay_max'             => "ALTER TABLE candidates ADD COLUMN pay_max DECIMAL(12,2) DEFAULT NULL",
        'pay_currency'        => "ALTER TABLE candidates ADD COLUMN pay_currency VARCHAR(10) DEFAULT NULL",
        'pay_period'          => "ALTER TABLE candidates ADD COLUMN pay_period VARCHAR(30) DEFAULT NULL",
        'hiring_project_id'   => "ALTER TABLE candidates ADD COLUMN hiring_project_id VARCHAR(80) DEFAULT NULL",
        'hiring_project_title'=> "ALTER TABLE candidates ADD COLUMN hiring_project_title VARCHAR(200) DEFAULT NULL",
        'li_contract_id'      => "ALTER TABLE candidates ADD COLUMN li_contract_id VARCHAR(80) DEFAULT NULL",
        'li_contract_name'    => "ALTER TABLE candidates ADD COLUMN li_contract_name VARCHAR(200) DEFAULT NULL",
        'screening_qa'        => "ALTER TABLE candidates ADD COLUMN screening_qa TEXT DEFAULT NULL",
    ];

    public function __construct(PDO $pdo, int $actorUserId)
    {
        $this->pdo = $pdo;
        $this->actorUserId = $actorUserId;
    }

    /** Aggiunge le colonne LinkedIn a `candidates` se mancanti (idempotente). */
    public function ensureSchema(): void
    {
        foreach (self::EXTRA_COLS as $col => $sql) {
            try { $this->pdo->query("SELECT `$col` FROM candidates LIMIT 0")->closeCursor(); }
            catch (\Throwable $e) { try { $this->pdo->exec($sql); } catch (\Throwable $ex) {} }
        }
    }

    /** true se la colonna esiste in `candidates`. */
    private function hasCol(string $col): bool
    {
        try { $this->pdo->query("SELECT `$col` FROM candidates LIMIT 0")->closeCursor(); return true; }
        catch (\Throwable $e) { return false; }
    }

    /** Legge il file e restituisce le righe mappate (chiavi canoniche). */
    public function parse(string $path): array
    {
        $hints = ['nome', 'cognome', 'id offerta di lavoro', 'indirizzo email'];
        // Il foglio "Candidati" è tipicamente il 2° (indice 1); si ripiega sugli altri.
        $rows = [];
        foreach ([1, 0, 2, 3] as $idx) {
            try {
                $res = XlsxReader::read($path, $idx, ['header_hints' => $hints, 'header_scan_rows' => 30]);
            } catch (\Throwable $e) { continue; }
            $hdrNorm = array_map([XlsxReader::class, 'norm'], $res['headers'] ?? []);
            if (in_array('id offerta di lavoro', $hdrNorm, true)
                && in_array('nome', $hdrNorm, true)) {
                $rows = $res['rows'];
                break;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $rec = [];
            foreach ($r as $h => $v) {
                $key = self::MAP[XlsxReader::norm((string)$h)] ?? null;
                if ($key !== null) $rec[$key] = self::clean($v);
            }
            // scarta righe senza nome e cognome
            if (($rec['first_name'] ?? '') === '' && ($rec['last_name'] ?? '') === '') continue;
            $out[] = $rec;
        }
        return $out;
    }

    /**
     * Analizza (senza scrivere): risolve le posizioni e classifica ogni riga.
     * @return array{summary:array,rows:array}
     */
    public function analyze(array $rows): array
    {
        $posByCode = $this->positionsByCode($rows);
        $out = [];
        $s = ['totali' => 0, 'associati' => 0, 'non_associati' => 0, 'senza_offerta' => 0, 'posizioni' => []];
        foreach ($rows as $r) {
            $s['totali']++;
            $code = self::jobCode($r['li_job_id'] ?? null);
            $pos  = $code !== null ? ($posByCode[$code] ?? null) : null;
            if ($code === null) { $esito = 'senza_offerta'; $s['senza_offerta']++; }
            elseif ($pos)       { $esito = 'associato';     $s['associati']++; $s['posizioni'][$pos['title']] = ($s['posizioni'][$pos['title']] ?? 0) + 1; }
            else                { $esito = 'non_associato'; $s['non_associati']++; }
            $out[] = [
                'nome'      => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'email'     => $r['email'] ?? null,
                'li_job_id' => $code,
                'posizione' => $pos['title'] ?? null,
                'esito'     => $esito,
                'rec'       => $r,
                'pos_id'    => $pos['id'] ?? null,
            ];
        }
        return ['summary' => $s, 'rows' => $out];
    }

    /**
     * Esegue l'import in transazione.
     * @param bool $importPotential importa anche i candidati senza offerta/associazione (senza posizione)
     * @return array report
     */
    public function import(array $rows, bool $importPotential = false): array
    {
        $this->ensureSchema();
        $an = $this->analyze($rows);
        $rep = ['creati' => 0, 'arricchiti' => 0, 'candidature' => 0,
                'saltati_esistenti_app' => 0, 'saltati_non_associati' => 0, 'errori' => 0, 'dettaglio' => []];
        $hasDeleted = $this->hasCol('deleted_at');

        $this->pdo->beginTransaction();
        try {
            foreach ($an['rows'] as $row) {
                $rec = $row['rec'];
                $posId = $row['pos_id'];
                if ($posId === null && !$importPotential) {
                    if ($row['esito'] !== 'associato') { $rep['saltati_non_associati']++; continue; }
                }

                // ── dedup per email ──
                $candId = null; $enriched = false;
                $email = $rec['email'] ?? null;
                if ($email) {
                    $q = "SELECT id FROM candidates WHERE LOWER(email)=LOWER(?)"
                       . ($hasDeleted ? " AND deleted_at IS NULL" : "") . " LIMIT 1";
                    $st = $this->pdo->prepare($q); $st->execute([$email]);
                    $candId = $st->fetchColumn() ?: null; $st->closeCursor();
                }

                if ($candId) {
                    $this->enrich((int)$candId, $rec, $posId !== null);
                    $rep['arricchiti']++; $enriched = true;
                } else {
                    $candId = $this->insertCandidate($rec, $posId !== null);
                    $rep['creati']++;
                }

                // ── candidatura sulla posizione mappata ──
                if ($posId !== null && $candId) {
                    if ($this->linkApplication((int)$candId, (int)$posId)) $rep['candidature']++;
                    else $rep['saltati_esistenti_app']++;
                }

                if (count($rep['dettaglio']) < 300) {
                    $rep['dettaglio'][] = [
                        'nome' => $row['nome'], 'email' => $email,
                        'posizione' => $row['posizione'],
                        'azione' => ($enriched ? 'aggiornato' : 'creato')
                                    . ($posId !== null ? ' + candidatura' : ' (potenziale)'),
                    ];
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $rep['errori']++;
            $rep['errore_msg'] = $e->getMessage();
        }
        $rep['summary'] = $an['summary'];
        return $rep;
    }

    // ── scrittura ────────────────────────────────────────────────────
    private function insertCandidate(array $rec, bool $matched): int
    {
        $fields = $this->candidateFields($rec, $matched, true);
        $cols = array_keys($fields);
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        $sql  = "INSERT INTO candidates (`" . implode('`,`', $cols) . "`) VALUES ($ph)";
        $this->pdo->prepare($sql)->execute(array_values($fields));
        return (int)$this->pdo->lastInsertId();
    }

    private function enrich(int $candId, array $rec, bool $matched): void
    {
        // v1.9.52 — ISSUE 2 FIX: l'UPDATE del candidato NON tocca mai l'email.
        // L'email e' un dato del candidato (dal file XLSX) e in modifica va preservata:
        // non deve MAI essere sovrascritta con l'email dell'utente in sessione o con
        // qualsiasi altra variabile. Qui l'email e' esplicitamente esclusa dal SET.
        $fields = $this->candidateFields($rec, $matched, false);
        unset(
            $fields['first_name'], $fields['last_name'],
            $fields['email'],          // <-- email isolata: mai in UPDATE
            $fields['source'], $fields['added_by'], $fields['gdpr_consent'], $fields['status']
        );
        // salvaguardia difensiva: qualunque cosa accada sopra, l'email resta fuori dal SET
        unset($fields['email']);
        $set = []; $vals = [];
        foreach ($fields as $c => $v) {
            if ($v === null || $v === '') continue;
            $set[] = "`$c`=COALESCE(`$c`,?)"; $vals[] = $v; // riempie solo se vuoto
        }
        if (!$set) return;
        $vals[] = $candId;
        $this->pdo->prepare("UPDATE candidates SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
    }

    /** Costruisce l'insieme campo=>valore intersecato con le colonne reali. */
    private function candidateFields(array $rec, bool $matched, bool $forInsert): array
    {
        $notes = $this->buildNotes($rec);
        $cand = [
            'first_name'          => self::cut($rec['first_name'] ?? '', 100),
            'last_name'           => self::cut($rec['last_name'] ?? '', 100),
            'email'               => self::cut($rec['email'] ?? null, 150),
            'phone'               => self::cut($rec['phone'] ?? null, 30),
            'linkedin_url'        => self::cut($rec['linkedin_url'] ?? null, 255),
            'city'                => self::cut($rec['city'] ?? null, 120),
            'postal_code'         => self::cut($rec['postal_code'] ?? null, 20),
            'headline'            => self::cut($rec['headline'] ?? null, 255),
            'current_title'       => self::cut($rec['current_title'] ?? null, 180),
            'current_company'     => self::cut($rec['current_company'] ?? null, 180),
            'current_since'       => self::cut($rec['current_since'] ?? null, 20),
            'education_level'     => self::cut($rec['education_level'] ?? null, 80),
            'education_institute' => self::cut($rec['education_institute'] ?? null, 200),
            // full mapping v1.9.52 — ogni colonna XLSX ha una destinazione dedicata
            'offer_title'         => self::cut($rec['offer_title'] ?? null, 200),
            'offer_url'           => self::cut($rec['offer_url'] ?? null, 500),
            'li_ats_id'           => self::cut($rec['ats_id'] ?? null, 80),
            'pay_min'             => self::numOrNull($rec['pay_min'] ?? null),
            'pay_max'             => self::numOrNull($rec['pay_max'] ?? null),
            'pay_currency'        => self::cut($rec['pay_ccy'] ?? null, 10),
            'pay_period'          => self::cut($rec['pay_period'] ?? null, 30),
            'hiring_project_id'   => self::cut($rec['hiring_project_id'] ?? null, 80),
            'hiring_project_title'=> self::cut($rec['hiring_project_title'] ?? null, 200),
            'li_contract_id'      => self::cut($rec['contract_id'] ?? null, 80),
            'li_contract_name'    => self::cut($rec['contract_name'] ?? null, 200),
            'screening_qa'        => self::clean($rec['screening_qa'] ?? null),
            'li_job_id'           => self::jobCode($rec['li_job_id'] ?? null),
            'li_stage'            => self::cut($rec['li_stage'] ?? null, 80),
            'applied_at'          => self::parseDate($rec['applied_at'] ?? null),
            'source'              => 'LinkedIn',
            'gdpr_consent'        => 0,
            'added_by'            => $this->actorUserId,
            'status'              => $matched ? 'in_pipeline' : 'new',
            'notes'               => $notes,
        ];
        // interseca con le colonne effettivamente presenti
        $out = [];
        foreach ($cand as $c => $v) {
            if ($this->hasCol($c)) $out[$c] = $v;
        }
        return $out;
    }

    private function linkApplication(int $candId, int $posId): bool
    {
        $chk = $this->pdo->prepare("SELECT 1 FROM candidate_applications WHERE candidate_id=? AND position_id=? LIMIT 1");
        $chk->execute([$candId, $posId]);
        if ($chk->fetchColumn()) { $chk->closeCursor(); return false; }
        $chk->closeCursor();
        $this->pdo->prepare(
            "INSERT INTO candidate_applications (candidate_id,position_id,stage) VALUES (?,?,'cv_received')"
        )->execute([$candId, $posId]);
        return true;
    }

    private function buildNotes(array $r): ?string
    {
        $L = [];
        $add = function ($label, $key) use (&$L, $r) {
            $v = self::clean($r[$key] ?? null);
            if ($v !== null && $v !== '' && $v !== '0') $L[] = "$label: $v";
        };
        $pay = trim(implode(' ', array_filter([
            self::clean($r['pay_min'] ?? null), self::clean($r['pay_max'] ?? null),
            self::clean($r['pay_ccy'] ?? null), self::clean($r['pay_period'] ?? null),
        ], fn($x) => $x !== null && $x !== '' && $x !== '0')));
        if ($pay !== '') $L[] = "Retribuzione offerta: $pay";
        $add('Offerta', 'offer_title');
        $add('URL offerta', 'offer_url');
        $add('ID offerta ATS', 'ats_id');
        $add('Progetto assunzione', 'hiring_project_title');
        $add('ID contratto', 'contract_id');
        $add('Contratto', 'contract_name');
        $add('Fase LinkedIn', 'li_stage');
        $add('Q&A selezione', 'screening_qa');
        if (!$L) return null;
        return "— Import LinkedIn " . date('Y-m-d') . " —\n" . implode("\n", $L);
    }

    // ── letture ──────────────────────────────────────────────────────
    private function positionsByCode(array $rows): array
    {
        $codes = [];
        foreach ($rows as $r) { $c = self::jobCode($r['li_job_id'] ?? null); if ($c !== null) $codes[$c] = true; }
        if (!$codes) return [];
        $codes = array_keys($codes);
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $this->pdo->prepare(
            "SELECT id, title, linkedin_code FROM job_positions WHERE linkedin_code IN ($ph)"
        );
        $st->execute($codes);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $map[(string)$p['linkedin_code']] = ['id' => (int)$p['id'], 'title' => $p['title']];
        }
        $st->closeCursor();
        return $map;
    }

    // ── util ─────────────────────────────────────────────────────────
    private static function clean($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || strcasecmp($s, 'N/A') === 0) return null;
        return $s;
    }

    private static function cut(?string $v, int $len): ?string
    {
        $v = self::clean($v);
        if ($v === null) return null;
        return function_exists('mb_substr') ? mb_substr($v, 0, $len, 'UTF-8') : substr($v, 0, $len);
    }

    /** Normalizza il codice offerta: '0'/vuoto → null. */
    /** Numero decimale IT/EN; vuoto/N/A/0 -> null (LinkedIn usa 0 = non specificato). */
    private static function numOrNull($v): ?string
    {
        $v = self::clean(is_string($v) ? $v : (string)$v);
        if ($v === null) return null;
        $v = str_replace(['.', ' '], ['', ''], $v);   // separatore migliaia
        $v = str_replace(',', '.', $v);               // decimale IT
        if (!is_numeric($v)) return null;
        $f = (float)$v;
        return $f == 0.0 ? null : number_format($f, 2, '.', '');
    }

    private static function jobCode($v): ?string
    {
        $v = self::clean(is_string($v) ? $v : (string)$v);
        if ($v === null) return null;
        $v = preg_replace('/\.0$/', '', $v); // 4427193793.0 → 4427193793
        if ($v === '' || $v === '0') return null;
        return $v;
    }

    private static function parseDate(?string $v): ?string
    {
        $v = self::clean($v);
        if ($v === null) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        if (preg_match('/^\d{4}-\d{2}$/', $v)) return $v . '-01';
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
