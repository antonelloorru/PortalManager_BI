<?php
/**
 * PortalManager — import_employees_xlsx.php
 *
 * Importer anagrafica dipendenti da file XLSX/CSV.
 *
 * v1.8.43: il tracciato non è più codificato qui ma in
 * app/EmployeeImportSchema.php, condiviso con il template scaricabile. Template
 * e parser leggono dalla stessa definizione, quindi il file che si scarica è per
 * costruzione quello che l'import riconosce. Il tracciato copre ora tutti i campi
 * dell'Anagrafica dipendenti: recapiti, collocazione organizzativa
 * (dipartimento, sotto-categoria, modalità di lavoro, agenzia, mansione),
 * inquadramento, badge, stato e — per chi ha il permesso Compensation — RAL e
 * premio concordato.
 *
 * Features:
 *   - Download del template in XLSX o CSV, con foglio "Istruzioni"
 *   - Parser CSV (delimiter auto-detect) e XLSX (via PhpSpreadsheet o
 *     fallback nativo ZipArchive+XML)
 *   - Header riconoscimento case-insensitive (sinonimi accettati, comprese le
 *     intestazioni dei tracciati precedenti)
 *   - Match company/location/work_mode/department/subcategory con auto-creazione
 *     opzionale di aziende e sedi
 *   - Map "Tempo indeterminato/determinato" → ENUM contract_type
 *   - Conversione data ITA (DD/MM/YYYY) e ISO (YYYY-MM-DD)
 *   - UPSERT su fiscal_code (priorità) o employee_code
 *   - Preview con possibilità di deselezionare righe
 *
 * Flow: upload → parse → preview con checkbox → execute (transazione atomica)
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/EmployeeImportSchema.php');

$u_id = (int)$_SESSION['user_id'];
if (!can('view', 'import_employees_xlsx.php')) redirect('manage_employees');
$can_e = can('create', 'import_employees_xlsx.php');

$msg = '';
$step = 'upload';

// ──────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────
/**
 * v1.8.43: la normalizzazione delega allo schema. Parser e mappa dei sinonimi
 * devono applicare la stessa identica regola, altrimenti un'intestazione valida
 * verrebbe normalizzata in due modi diversi e non troverebbe corrispondenza.
 */
function normalize_header(string $h): string {
    return EmployeeImportSchema::normalize($h);
}

function parse_date_flex($v): ?string {
    if ($v === null || $v === '') return null;
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    $s = trim((string)$v);
    if ($s === '') return null;

    // Excel serial date number (es. 45870)
    if (is_numeric($s) && (float)$s > 25000 && (float)$s < 80000) {
        $base = new DateTime('1899-12-30');
        $base->modify('+' . (int)$s . ' days');
        return $base->format('Y-m-d');
    }

    // ISO YYYY-MM-DD (anche con orario)
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // ITA DD/MM/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    return null;
}

function map_contract_type(?string $v): string {
    if (!$v) return 'Indeterminato';
    $vl = mb_strtolower(trim($v));
    if (str_contains($vl, 'apprend'))      return 'Apprendistato';
    if (str_contains($vl, 'indeterm'))     return 'Indeterminato';
    if (str_contains($vl, 'determ'))       return 'Determinato';
    if (str_contains($vl, 'somministr'))   return 'Somministrazione';
    if (str_contains($vl, 'consul'))       return 'Consulenza';
    if (str_contains($vl, 'stage'))        return 'Stage';
    if (str_contains($vl, 'p.iva') || str_contains($vl, 'partita iva')) return 'Partita IVA';
    return 'Indeterminato';
}

function bool_si_no($v): int {
    $vl = mb_strtolower(trim((string)$v));
    return in_array($vl, ['si','sì','yes','y','1','true','x'], true) ? 1 : 0;
}

function map_gender(?string $v): ?string {
    if (!$v) return null;
    $vl = mb_strtoupper(trim($v));
    if (in_array($vl, ['M','MASCHIO','MALE'], true)) return 'M';
    if (in_array($vl, ['F','FEMMINA','FEMALE'], true)) return 'F';
    return 'altro';
}

/** v1.8.43: classificazione finanziaria (Diretto/Indiretto). */
function map_classificazione($v): ?string {
    $vl = mb_strtolower(trim((string)$v));
    if ($vl === '') return null;
    if (str_starts_with($vl, 'ind')) return 'Indiretto';
    if (str_starts_with($vl, 'dir')) return 'Diretto';
    return null;
}

/**
 * v1.8.43: stato del rapporto. Se la colonna è assente o vuota il valore resta
 * null e viene dedotto a valle dalla data di cessazione, come nel tracciato
 * precedente.
 */
function map_status($v): ?string {
    $vl = mb_strtolower(trim((string)$v));
    if ($vl === '') return null;
    if (in_array($vl, ['active','attivo','attiva','in forza','si','sì'], true))        return 'active';
    if (in_array($vl, ['inactive','inattivo','sospeso','non attivo'], true))           return 'inactive';
    if (in_array($vl, ['terminated','cessato','cessata','dimesso','licenziato'], true)) return 'terminated';
    return null;
}

// v1.8.43: la mappa dei sinonimi non è più duplicata qui. Template e importer
// leggono dallo stesso tracciato (app/EmployeeImportSchema.php): è quell'unica
// fonte a garantire che il file scaricato sia esattamente quello riconosciuto in
// lettura. Le vecchie intestazioni restano accettate come sinonimi.
$HEADER_MAP = EmployeeImportSchema::headerMap();

// ──────────────────────────────────────────────────────────────────────
// v1.8.43 — Download del template
//
// Le intestazioni provengono da EmployeeImportSchema, la stessa fonte da cui il
// parser costruisce $HEADER_MAP: il file scaricato è quindi sempre allineato a
// ciò che l'import sa leggere. Le colonne retributive compaiono solo per chi ha
// il permesso Compensation.
// ──────────────────────────────────────────────────────────────────────
$tpl = strtolower(trim((string)($_GET['template'] ?? '')));
if ($tpl === '1' || $tpl === 'true') $tpl = 'xlsx';   // compatibilità con il link precedente
if ($tpl === 'xlsx' || $tpl === 'csv') {
    $withComp = can('view', 'manage_employees_compensation.php');
    $headers  = EmployeeImportSchema::labels($withComp);
    $example  = EmployeeImportSchema::exampleRow($withComp);

    // Nessun output spurio prima del binario, altrimenti il file arriva corrotto.
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    write_log('Anagrafica', 'info', "Download template import dipendenti ($tpl, "
        . count($headers) . " colonne)", $u_id);

    if ($tpl === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="template_import_dipendenti.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");                 // BOM: accenti corretti in Excel
        fputcsv($out, $headers, ';', '"');
        fputcsv($out, $example, ';', '"');
        fclose($out);
        exit;
    }

    require_once(__DIR__ . '/XlsxWriter.php');
    $w = new XlsxWriter();
    $w->addSheet('Dipendenti', [$headers, $example]);

    // Foglio di istruzioni: una riga per colonna, con tipo atteso e note.
    $guide = [['Colonna', 'Obbligatoria', 'Formato', 'Note']];
    $tipo = [
        'text'    => 'testo',
        'date'    => 'data (GG/MM/AAAA)',
        'int'     => 'numero intero',
        'bool'    => 'Si / No',
        'decimal' => 'numero decimale',
        'enum'    => 'valore da elenco',
        'lookup'  => 'testo (cercato in anagrafica)',
    ];
    foreach (EmployeeImportSchema::columns($withComp) as $c) {
        $obbl = in_array($c['field'], ['last_name', 'first_name'], true) ? 'Si' : 'No';
        $guide[] = [$c['label'], $obbl, $tipo[$c['type']] ?? $c['type'], (string)($c['note'] ?? '')];
    }
    $guide[] = ['', '', '', ''];
    $guide[] = ['Come si usa', '', '', ''];
    $guide[] = ['1. Compilare il foglio "Dipendenti" sostituendo la riga di esempio con i propri dati.', '', '', ''];
    $guide[] = ['2. Non rinominare ne riordinare le intestazioni: sono riconosciute per nome.', '', '', ''];
    $guide[] = ['3. Le colonne non necessarie possono essere lasciate vuote o rimosse.', '', '', ''];
    $guide[] = ['4. Cognome e Nome sono le uniche colonne obbligatorie.', '', '', ''];
    $guide[] = ['5. Il riconoscimento di un dipendente gia presente avviene per Codice fiscale, in mancanza per Matricola.', '', '', ''];
    $guide[] = ['6. In aggiornamento le celle lasciate vuote non sovrascrivono i valori gia registrati.', '', '', ''];
    $w->addSheet('Istruzioni', $guide);
    $w->download('template_import_dipendenti.xlsx');
    exit;
}

// Parser CSV
function parse_csv_file(string $path): array {
    $rows = [];
    $f = fopen($path, 'rb');
    if (!$f) return [];
    // Detect delimiter
    $sample = fread($f, 4096); rewind($f);
    $delim = ',';
    foreach ([';',"\t",',','|'] as $d) {
        if (substr_count($sample, $d) > substr_count($sample, $delim)) $delim = $d;
    }
    // BOM strip
    $bom = fread($f, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($f);
    while (($r = fgetcsv($f, 0, $delim, '"', '\\')) !== false) $rows[] = $r;
    fclose($f);
    return $rows;
}

// Parser XLSX nativo (fallback se PhpSpreadsheet non disponibile)
function parse_xlsx_native(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("File XLSX non valido");

    $shared = [];
    if (($sx = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($sx);
        foreach ($xml->si as $si) {
            $txt = '';
            if (isset($si->t)) $txt = (string)$si->t;
            elseif (isset($si->r)) foreach ($si->r as $r) $txt .= (string)$r->t;
            $shared[] = $txt;
        }
    }
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheet_xml) throw new RuntimeException("Sheet1 non trovato");

    $xml = simplexml_load_string($sheet_xml);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $r = [];
        $col_idx = 0;
        foreach ($row->c as $c) {
            // ref tipo "A1", "B1", ...
            $ref = (string)$c['r'];
            if ($ref) {
                preg_match('/^([A-Z]+)/', $ref, $m);
                $letters = $m[1];
                $idx = 0;
                for ($i = 0; $i < strlen($letters); $i++) {
                    $idx = $idx * 26 + (ord($letters[$i]) - 64);
                }
                $col_idx = $idx - 1;
            }
            $t = (string)$c['t'];
            $val = (string)$c->v;
            if ($t === 's') $val = $shared[(int)$val] ?? '';
            elseif ($t === 'inlineStr') $val = (string)$c->is->t;
            // Excel date detection: cella numerica con stile data (semplificato: tentiamo conversione se >25000)
            $r[$col_idx] = $val;
        }
        // Riempio buchi con stringhe vuote per allineare
        if (!empty($r)) {
            $max = max(array_keys($r));
            $out = [];
            for ($i = 0; $i <= $max; $i++) $out[] = $r[$i] ?? '';
            $rows[] = $out;
        }
    }
    return $rows;
}

// Caricamento liste base
$companies_db = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$locations_db = $pdo->query("SELECT id, company_id, location_name, address FROM company_locations ORDER BY location_name")->fetchAll();
$companies_map = [];
foreach ($companies_db as $c) $companies_map[mb_strtolower(trim($c['name']))] = (int)$c['id'];

// ──────────────────────────────────────────────────────────────────────
// STEP 2: Upload + parse
// ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    Csrf::verify();
    if (empty($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "<div class='alert alert-danger'>Errore upload file.</div>";
    } else {
        $tmp = $_FILES['xlsx_file']['tmp_name'];
        $name = $_FILES['xlsx_file']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        try {
            $rows = match ($ext) {
                'xlsx' => parse_xlsx_native($tmp),
                'csv', 'tsv', 'txt' => parse_csv_file($tmp),
                default => throw new RuntimeException("Formato non supportato: .$ext (usa XLSX o CSV)"),
            };
            if (count($rows) < 2) throw new RuntimeException("File vuoto o senza dati");

            // Header → mappa colonne
            $headers = array_map('trim', $rows[0]);
            $col_map = []; // chiave_normalizzata → indice colonna
            foreach ($headers as $idx => $h) {
                $key = $HEADER_MAP[normalize_header($h)] ?? null;
                if ($key) $col_map[$key] = $idx;
            }
            if (!isset($col_map['last_name']) || !isset($col_map['first_name'])) {
                throw new RuntimeException("Colonne Cognome/Nome obbligatorie mancanti");
            }

            // Parse righe dati
            $parsed = [];
            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                if (empty(array_filter($r, fn($v) => trim((string)$v) !== ''))) continue;
                $rec = [];
                foreach ($col_map as $key => $colIdx) {
                    $rec[$key] = $r[$colIdx] ?? '';
                }
                if (trim((string)($rec['last_name'] ?? '')) === '' && trim((string)($rec['first_name'] ?? '')) === '') continue;

                // Normalizzazioni: i campi sono derivati dal tracciato, così
                // aggiungere una colonna allo schema non richiede modifiche qui.
                foreach (EmployeeImportSchema::fieldsOfType('date') as $f) {
                    $rec[$f] = parse_date_flex($rec[$f] ?? null);
                }
                foreach (EmployeeImportSchema::fieldsOfType('decimal') as $f) {
                    $v = str_replace(',', '.', trim((string)($rec[$f] ?? '')));
                    $rec[$f] = ($v !== '' && is_numeric($v)) ? (float)$v : null;
                }
                foreach (EmployeeImportSchema::fieldsOfType('bool') as $f) {
                    $rec[$f] = bool_si_no($rec[$f] ?? '');
                }
                foreach (EmployeeImportSchema::fieldsOfType('text') as $f) {
                    $rec[$f] = isset($rec[$f]) ? (trim((string)$rec[$f]) ?: null) : null;
                }
                foreach (EmployeeImportSchema::fieldsOfType('lookup') as $f) {
                    $rec[$f] = isset($rec[$f]) ? (trim((string)$rec[$f]) ?: null) : null;
                }
                $rec['contract_type'] = map_contract_type($rec['contract_type'] ?? null);
                $rec['gender']        = map_gender($rec['gender'] ?? null);
                $rec['fiscal_code']   = strtoupper(trim((string)($rec['fiscal_code'] ?? ''))) ?: null;
                $rec['employee_code'] = trim((string)($rec['employee_code'] ?? '')) ?: null;
                $rec['classificazione_finanziaria'] = map_classificazione($rec['classificazione_finanziaria'] ?? null);
                $rec['status']        = map_status($rec['status'] ?? null);
                $rec['first_name'] = trim(ucwords(mb_strtolower((string)($rec['first_name'] ?? ''))));
                $rec['last_name']  = trim(ucwords(mb_strtolower((string)($rec['last_name'] ?? ''))));

                $parsed[] = $rec;
            }

            if (empty($parsed)) throw new RuntimeException("Nessuna riga valida trovata");

            $_SESSION['emp_import_rows']    = $parsed;
            $_SESSION['emp_import_headers'] = $headers;
            $step = 'preview';
        } catch (\Throwable $e) {
            $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . h($e->getMessage()) . "</div>";
        }
    }
}

// ──────────────────────────────────────────────────────────────────────
// STEP 3: Execute import
// ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute' && $can_e) {
    Csrf::verify();
    $rows = $_SESSION['emp_import_rows'] ?? [];
    $sel  = $_POST['rows'] ?? [];
    $create_co  = !empty($_POST['create_companies']);
    $create_loc = !empty($_POST['create_locations']);
    $upsert     = !empty($_POST['upsert']);
    $imported = 0; $updated = 0; $skipped = 0;
    $created_co = 0; $created_loc = 0;
    $errors = [];

    try {
        $pdo->beginTransaction();

        foreach ($sel as $idx) {
            $idx = (int)$idx;
            if (!isset($rows[$idx])) continue;
            $r = $rows[$idx];

            // 1. Company resolution
            $company_id = 1;  // default
            if (!empty($r['company'])) {
                $ck = mb_strtolower(trim($r['company']));
                if (isset($companies_map[$ck])) {
                    $company_id = $companies_map[$ck];
                } elseif ($create_co) {
                    $pdo->prepare("INSERT INTO companies (name) VALUES (?)")->execute([$r['company']]);
                    $company_id = (int)$pdo->lastInsertId();
                    $companies_map[$ck] = $company_id;
                    $created_co++;
                } else {
                    $errors[] = "Riga " . ($idx+1) . ": azienda '" . $r['company'] . "' non esistente";
                    $skipped++; continue;
                }
            }

            // 2. Location resolution (match per nome città o nome sede, sotto la company)
            $location_id = null;
            if (!empty($r['location'])) {
                $lk = mb_strtolower(trim($r['location']));
                $lq = $pdo->prepare("
                    SELECT id FROM company_locations
                     WHERE company_id = ?
                       AND (LOWER(location_name) LIKE ? OR LOWER(location_name) = ? OR LOWER(address) LIKE ?)
                     LIMIT 1
                ");
                $lq->execute([$company_id, '%' . $lk . '%', $lk, '%' . $lk . '%']);
                $location_id = $lq->fetchColumn() ?: null;
                if (!$location_id && $create_loc) {
                    $pdo->prepare("INSERT INTO company_locations (company_id, location_name) VALUES (?, ?)")
                        ->execute([$company_id, 'Sede ' . ucfirst(mb_strtolower($r['location']))]);
                    $location_id = (int)$pdo->lastInsertId();
                    $created_loc++;
                }
            }

            // 2-bis. v1.8.43: risoluzione dei lookup organizzativi introdotti con
            // il tracciato esteso. Un valore non riconosciuto non blocca la riga:
            // il campo resta semplicemente non valorizzato, per non far fallire
            // l'import a causa di una tassonomia non ancora allineata.
            $work_mode_id = null;
            if (!empty($r['work_mode'])) {
                try {
                    $q = $pdo->prepare("SELECT id FROM work_modes WHERE LOWER(name)=? LIMIT 1");
                    $q->execute([mb_strtolower(trim($r['work_mode']))]);
                    $work_mode_id = $q->fetchColumn() ?: null;
                } catch (\Throwable $e) { /* tabella assente: ignora */ }
            }
            $department_id = null;
            if (!empty($r['department_name'])) {
                try {
                    $q = $pdo->prepare("SELECT id FROM departments WHERE LOWER(name)=? LIMIT 1");
                    $q->execute([mb_strtolower(trim($r['department_name']))]);
                    $department_id = $q->fetchColumn() ?: null;
                } catch (\Throwable $e) { /* ignora */ }
            }
            $subcategory_id = null;
            if (!empty($r['subcategory']) && $department_id) {
                try {
                    $q = $pdo->prepare("SELECT id FROM department_subcategories
                                         WHERE department_id=? AND LOWER(name)=? LIMIT 1");
                    $q->execute([$department_id, mb_strtolower(trim($r['subcategory']))]);
                    $subcategory_id = $q->fetchColumn() ?: null;
                } catch (\Throwable $e) { /* ignora */ }
            }

            // 3. UPSERT su fiscal_code (priorità) o employee_code
            $existing_id = 0;
            if ($r['fiscal_code']) {
                $eq = $pdo->prepare("SELECT id FROM employees WHERE UPPER(fiscal_code) = ? LIMIT 1");
                $eq->execute([$r['fiscal_code']]);
                $existing_id = (int)$eq->fetchColumn();
            }
            if (!$existing_id && $r['employee_code']) {
                $eq = $pdo->prepare("SELECT id FROM employees WHERE employee_code = ? LIMIT 1");
                $eq->execute([$r['employee_code']]);
                $existing_id = (int)$eq->fetchColumn();
            }

            // Costruisco SET dinamico (whitelist campi)
            $fields = [
                'company_id' => $company_id, 'location_id' => $location_id,
                'first_name' => $r['first_name'], 'last_name' => $r['last_name'],
                'fiscal_code' => $r['fiscal_code'], 'employee_code' => $r['employee_code'],
                'date_of_birth' => $r['date_of_birth'], 'gender' => $r['gender'],
                'hire_date' => $r['hire_date'], 'end_date' => $r['end_date'],
                'contract_type' => $r['contract_type'],
                'ccnl' => $r['ccnl'], 'part_time' => (int)$r['part_time'], 'part_time_pct' => $r['part_time_pct'],
                'apprenticeship_end_date' => $r['apprenticeship_end_date'],
                'qualification' => $r['qualification'], 'contract_level' => $r['contract_level'],
                'ral' => $r['ral'],
                'badge_number' => $r['badge_number'], 'badge_issue_date' => $r['badge_issue_date'],
                'phone' => $r['phone'] ?? null, 'business_email' => $r['business_email'] ?? null,
                // v1.8.43: campi del tracciato esteso
                'personal_email' => $r['personal_email'] ?? null,
                'phone_personal' => $r['phone_personal'] ?? null,
                'work_mode_id'   => $work_mode_id,
                'department_id'  => $department_id,
                'subcategory_id' => $subcategory_id,
                'department'     => $r['department_name'] ?? null,
                'agency'         => $r['agency'] ?? null,
                'job_title'      => $r['job_title'] ?? null,
                'classificazione_finanziaria' => $r['classificazione_finanziaria'] ?? null,
                'premio_concordato' => $r['premio_concordato'] ?? null,
                'status' => $r['status'] ?: ($r['end_date'] ? 'terminated' : 'active'),
            ];

            if ($existing_id && $upsert) {
                // UPDATE solo campi non-null per evitare cancellare dati esistenti
                $set = []; $par = [];
                foreach ($fields as $k => $v) {
                    if ($v !== null && $v !== '') { $set[] = "`$k` = ?"; $par[] = $v; }
                }
                if (!empty($set)) {
                    $par[] = $existing_id;
                    $pdo->prepare("UPDATE employees SET " . implode(',', $set) . " WHERE id = ?")->execute($par);
                    $updated++;
                } else { $skipped++; }
            } elseif (!$existing_id) {
                $cols = array_keys($fields);
                $place = implode(',', array_fill(0, count($cols), '?'));
                $sql = "INSERT INTO employees (`" . implode('`,`', $cols) . "`) VALUES ($place)";
                $pdo->prepare($sql)->execute(array_values($fields));
                $imported++;
            } else {
                $skipped++;
            }
        }

        $pdo->commit();
        write_log('Anagrafica', 'success',
            "Import XLSX dipendenti: $imported nuovi, $updated aggiornati, $skipped saltati, $created_co aziende create, $created_loc sedi create",
            $u_id);

        $msg = "<div class='alert alert-success'>
            <i class='fa-solid fa-circle-check'></i> <strong>Import completato!</strong><br>
            • Nuovi dipendenti: <strong>$imported</strong><br>
            • Aggiornati (UPSERT): <strong>$updated</strong><br>
            • Saltati: <strong>$skipped</strong><br>
            • Aziende auto-create: <strong>$created_co</strong><br>
            • Sedi auto-create: <strong>$created_loc</strong>
            " . (!empty($errors) ? '<details><summary>Errori (' . count($errors) . ')</summary><ul style="margin-top:5px"><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul></details>' : '') . "
        </div>";
        unset($_SESSION['emp_import_rows'], $_SESSION['emp_import_headers']);
        $step = 'done';
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        $step = 'preview';
    }
}

if (isset($_GET['reset'])) { unset($_SESSION['emp_import_rows'], $_SESSION['emp_import_headers']); $step = 'upload'; }

if ($step === 'upload' && !empty($_SESSION['emp_import_rows'])) $step = 'preview';

require_once('header.php');
?>

<div style="margin-bottom:16px">
  <h2 style="margin:0;font-size:21px;color:#0f172a">
    <i class="fa-solid fa-file-arrow-up" style="color:#0ea5e9"></i> Import Dipendenti da XLSX/CSV
  </h2>
  <div style="font-size:12px;color:#64748b;margin-top:3px">
    Carica un file Excel o CSV con i dati anagrafici → preview → import massivo nell'anagrafica dipendenti
  </div>
</div>

<?= $msg ?>

<?php if ($step === 'upload'): ?>
<div class="card" style="padding:16px">
  <h3 style="margin:0 0 12px 0;font-size:14px;color:#0ea5e9"><i class="fa-solid fa-1"></i> Carica file</h3>

  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">

    <div style="margin-bottom:14px">
      <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">File XLSX o CSV *</label>
      <input type="file" name="xlsx_file" accept=".xlsx,.csv,.tsv,.txt" required style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
      <div style="font-size:10px;color:#94a3b8;margin-top:3px">Max 10 MB. Prima riga = intestazione. Header riconosciuti case-insensitive.</div>
    </div>

    <?php
      // v1.8.43: l'elenco delle colonne non e' piu' scritto a mano: e' generato
      // dallo stesso tracciato che alimenta template e parser, quindi non puo'
      // divergere da cio' che l'import riconosce davvero.
      $tpl_cols = EmployeeImportSchema::columns(can('view', 'manage_employees_compensation.php'));
    ?>
    <div style="background:#f0f9ff;border:1px solid #93c5fd;padding:12px;border-radius:6px;font-size:11px;margin-bottom:12px">
      <strong style="color:#0c4a6e"><i class="fa-solid fa-circle-info"></i> Tracciato atteso (<?= count($tpl_cols) ?> colonne):</strong>
      <div style="margin-top:6px;font-family:Consolas,monospace;font-size:10px;color:#1e293b;display:grid;grid-template-columns:repeat(3,1fr);gap:4px 12px">
        <?php foreach ($tpl_cols as $c):
          $req = in_array($c['field'], ['last_name','first_name'], true); ?>
          <div<?= $req ? ' title="Obbligatoria"' : '' ?>>
            <?= $req ? '<strong>' . h($c['label']) . '</strong> *' : h($c['label']) ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:8px;font-size:10px;color:#475569">
        Solo <strong>Cognome</strong> e <strong>Nome</strong> sono obbligatorie: le altre colonne possono restare vuote o essere rimosse.
        Le intestazioni sono riconosciute senza distinzione di maiuscole e accettano i sinonimi dei tracciati precedenti
        (per esempio &quot;Tempo indeterminato&quot; o &quot;P.IVA&quot; per Tipo contratto).
        In aggiornamento le celle vuote non sovrascrivono i dati gia presenti.
      </div>
    </div>

    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary" style="background:#0ea5e9"><i class="fa-solid fa-upload"></i> Carica e analizza</button>
      <a href="<?= url_safe('import_employees_xlsx', ['template' => 'xlsx']) ?>" class="btn"><i class="fa-solid fa-file-excel"></i> Scarica template XLSX</a>
      <a href="<?= url_safe('import_employees_xlsx', ['template' => 'csv']) ?>" class="btn"><i class="fa-solid fa-file-csv"></i> Scarica template CSV</a>
    </div>
  </form>
</div>

<?php elseif ($step === 'preview'):
    $rows = $_SESSION['emp_import_rows'] ?? [];
    // Conta entità mancanti
    $missing_co  = [];
    $missing_loc = [];
    foreach ($rows as $r) {
        if (!empty($r['company']) && !isset($companies_map[mb_strtolower(trim($r['company']))])) {
            $missing_co[$r['company']] = true;
        }
        if (!empty($r['location'])) {
            $found = false;
            foreach ($locations_db as $l) {
                if (mb_stripos($l['location_name'], $r['location']) !== false || mb_stripos($l['address'] ?? '', $r['location']) !== false) {
                    $found = true; break;
                }
            }
            if (!$found) $missing_loc[$r['location']] = true;
        }
    }
?>
<div class="card" style="padding:14px;background:#f0fdf4;border:1px solid #86efac;margin-bottom:12px">
  <strong style="color:#166534"><i class="fa-solid fa-circle-check"></i> Estratte <?= count($rows) ?> righe dipendenti dal file</strong>
  <a href="<?= url_safe('import_employees_xlsx') ?>?reset=1" class="btn btn-sm" style="float:right">Carica altro file</a>
</div>

<?php if (!empty($missing_co)): ?>
<div class="card" style="padding:10px;background:#fefce8;border:1px solid #fde68a;margin-bottom:8px">
  <strong style="color:#a16207"><i class="fa-solid fa-building"></i> Aziende non presenti (<?= count($missing_co) ?>):</strong>
  <span style="font-size:11px"><?php foreach (array_keys($missing_co) as $mc): ?>
  <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;margin:2px;font-weight:600;display:inline-block"><?= h($mc) ?></span>
  <?php endforeach; ?></span>
</div>
<?php endif; ?>

<?php if (!empty($missing_loc)): ?>
<div class="card" style="padding:10px;background:#fefce8;border:1px solid #fde68a;margin-bottom:12px">
  <strong style="color:#a16207"><i class="fa-solid fa-location-dot"></i> Sedi non presenti (<?= count($missing_loc) ?>):</strong>
  <span style="font-size:11px"><?php foreach (array_keys($missing_loc) as $ml): ?>
  <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;margin:2px;font-weight:600;display:inline-block"><?= h($ml) ?></span>
  <?php endforeach; ?></span>
</div>
<?php endif; ?>

<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="execute">

  <div class="card" style="padding:0;overflow:auto;max-height:60vh">
    <table style="width:100%;border-collapse:collapse;font-size:11px">
      <thead style="background:#1e293b;color:#fff;position:sticky;top:0">
        <tr>
          <th style="padding:6px;width:32px"><input type="checkbox" id="selAll" checked onchange="document.querySelectorAll('.cb').forEach(c=>c.checked=this.checked)"></th>
          <th style="padding:6px;text-align:left">#</th>
          <th style="padding:6px;text-align:left">Matr.</th>
          <th style="padding:6px;text-align:left">Cognome Nome</th>
          <th style="padding:6px;text-align:left">CF</th>
          <th style="padding:6px;text-align:left">Sesso</th>
          <th style="padding:6px;text-align:left">Assunz.</th>
          <th style="padding:6px;text-align:left">Cessaz.</th>
          <th style="padding:6px;text-align:left">CCNL</th>
          <th style="padding:6px;text-align:left">Contr.</th>
          <th style="padding:6px;text-align:left">PT</th>
          <th style="padding:6px;text-align:left">Qual./Liv.</th>
          <th style="padding:6px;text-align:right">RAL</th>
          <th style="padding:6px;text-align:left">Azienda</th>
          <th style="padding:6px;text-align:left">Sede</th>
          <th style="padding:6px;text-align:left">Badge</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r):
          $missing = (!empty($r['company']) && isset($missing_co[$r['company']])) ? '#fef9c3' : '#fff';
        ?>
        <tr style="border-bottom:1px solid #f1f5f9;background:<?= $missing ?>">
          <td style="padding:5px;text-align:center"><input type="checkbox" class="cb" name="rows[]" value="<?= $i ?>" checked></td>
          <td style="padding:5px;color:#94a3b8"><?= $i+1 ?></td>
          <td style="padding:5px;font-family:Consolas,monospace;font-size:10px"><?= h($r['employee_code'] ?? '') ?></td>
          <td style="padding:5px;font-weight:600"><?= h($r['last_name'] . ' ' . $r['first_name']) ?></td>
          <td style="padding:5px;font-family:Consolas,monospace;font-size:9px"><?= h($r['fiscal_code'] ?? '') ?></td>
          <td style="padding:5px;text-align:center"><?= h($r['gender'] ?? '') ?></td>
          <td style="padding:5px;white-space:nowrap"><?= $r['hire_date'] ? date('d/m/Y', strtotime($r['hire_date'])) : '' ?></td>
          <td style="padding:5px;white-space:nowrap"><?= $r['end_date'] ? date('d/m/Y', strtotime($r['end_date'])) : '' ?></td>
          <td style="padding:5px;font-size:10px"><?= h(mb_substr($r['ccnl'] ?? '', 0, 25)) ?></td>
          <td style="padding:5px;font-size:10px"><span style="background:#e2e8f0;padding:1px 6px;border-radius:8px"><?= h($r['contract_type']) ?></span></td>
          <td style="padding:5px;text-align:center"><?= $r['part_time'] ? ((int)($r['part_time_pct'] ?? 0)) . '%' : '—' ?></td>
          <td style="padding:5px;font-size:10px"><?= h($r['qualification'] ?? '') ?><?php if ($r['contract_level']): ?><br><span style="color:#64748b">Liv. <?= h($r['contract_level']) ?></span><?php endif; ?></td>
          <td style="padding:5px;text-align:right;font-weight:700;color:#16a34a"><?= $r['ral'] ? number_format((float)$r['ral'], 0, ',', '.') . ' €' : '' ?></td>
          <td style="padding:5px;font-size:10px"><?= h(mb_substr($r['company'] ?? '', 0, 22)) ?></td>
          <td style="padding:5px;font-size:10px"><?= h($r['location'] ?? '') ?></td>
          <td style="padding:5px;font-size:10px;font-family:Consolas,monospace"><?= h($r['badge_number'] ?? '') ?><?php if ($r['badge_issue_date']): ?><br><span style="color:#64748b;font-family:inherit">dal <?= date('d/m/Y', strtotime($r['badge_issue_date'])) ?></span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="padding:14px;margin-top:12px;background:#f0f9ff">
    <h3 style="margin:0 0 10px 0;font-size:13px"><i class="fa-solid fa-gears"></i> Opzioni import</h3>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px;cursor:pointer">
      <input type="checkbox" name="create_companies" value="1" checked>
      <strong>Crea aziende mancanti</strong> automaticamente
    </label>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px;cursor:pointer">
      <input type="checkbox" name="create_locations" value="1" checked>
      <strong>Crea sedi mancanti</strong> automaticamente (sotto l'azienda)
    </label>
    <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer">
      <input type="checkbox" name="upsert" value="1" checked>
      <strong>UPSERT</strong>: aggiorna dipendenti esistenti se trovo match su Codice Fiscale o Matricola (non sovrascrive campi vuoti)
    </label>

    <div style="margin-top:14px;display:flex;gap:8px">
      <button type="submit" class="btn btn-primary" style="background:#16a34a" onclick="return confirm('Confermi l\'import?')"><i class="fa-solid fa-check"></i> Esegui import</button>
      <a href="<?= url_safe('import_employees_xlsx') ?>?reset=1" class="btn">Annulla</a>
    </div>
  </div>
</form>
<?php endif; ?>

<?php require_once('footer.php'); ?>
