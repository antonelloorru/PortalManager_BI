<?php
/**
 * PortalManager — project_import.php
 *
 * Import massivo progetti da CSV con auto-compilazione anagrafica cliente.
 *
 * Flow a 3 fasi:
 *   1. UPLOAD    : form upload CSV + opzioni + download template
 *   2. PREVIEW   : parsing + validazione + tabella anteprima
 *   3. EXECUTE   : import effettivo in transazione, report finale
 *
 * Caratteristiche:
 *   - Auto-create entità mancanti: cliente, brand, tecnologia, certificazione
 *   - Match esistenti (case-insensitive, trim) per cliente/progetto
 *   - Update on duplicate: aggiornamento progetto se titolo+cliente già esistono
 *   - Idempotenza: rieseguibile sullo stesso CSV
 *   - Transazione per riga: rollback singola riga su errore, continua le altre
 *   - Report dettagliato finale con conteggi e log
 */
require_once('access_control.php');

$u_role  = (int)($_SESSION['role_id'] ?? 99);
$u_id    = (int)$_SESSION['user_id'];

// RBAC: solo ruoli 1-3 possono importare
if ($u_role > 3) { redirect('projects'); }

$msg = '';

// ────────────────────────────────────────────────────────────────────
// AZIONE: DOWNLOAD TEMPLATE CSV
// ────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_progetti.csv"');

    $headers = [
        'title','client_name','client_size','client_employees','client_users','client_industry',
        'executor_company','description','service_type','employees_involved','users_impacted',
        'date_start','date_end','is_in_progress','status','value_euro','confidential',
        // v1.7.33: nuovi campi commerciali
        'duration_text','commercial_agent','amount_services','amount_hw_sw','period_text',
        'tech_areas','notes',
        // pivot M:N
        'brands','technologies','certifications',
    ];

    $examples = [
        [
            'TRASLOCO CABLAGGIO ETICHETTATURA DATACENTER',
            'INFN CNAF Bologna','Enterprise',300,1000,'Ricerca scientifica',
            'TechServices Italia Srl',
            'Trasloco di circa 40 armadi rack del datacenter, cablaggio strutturato ed etichettatura completa',
            'Consolidamento',8,1000,
            '2024-03-01','2024-09-30',0,'completed',65000.00,0,
            // v1.7.33 fields:
            '6 MESI','Angelo Colletti',60000.00,5000.00,'2024',
            'TRASLOCO DC|CABLING','Progetto chiavi in mano con SLA 99,9%',
            // pivot:
            'Servizi WeTechs','','',
        ],
        [
            'Realizzazione Infrastruttura VDI per Esami Ammissione Universita\'',
            'CISIA Pisa','Enterprise',150,5000,'Education',
            'TechServices Italia Srl',
            'Implementazione VDI VMware Horizon per gestione esami ammissione universitari',
            'Virtualizzazione',5,5000,
            '2023-09-01','2024-08-31',0,'completed',62000.00,0,
            '9 MESI','Angelo Colletti',62000.00,null,'2023-2024',
            'INFRASTRUTTURE|SISTEMI|VDI','Picco utilizzo: settembre-ottobre, 5000  utenti concorrenti',
            'Servizi WeTechs|VMware','VMware:Horizon','VCAP-DCV',
        ],
    ];

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");   // BOM UTF-8 (Excel italiano)
    fputcsv($out, $headers, ';');
    foreach ($examples as $row) fputcsv($out, $row, ';');
    fclose($out);
    exit;
}

// ────────────────────────────────────────────────────────────────────
// FASE 3: ESECUZIONE IMPORT (POST con file_token confermato)
// ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_import'])) {
    Csrf::verify();

    $file_token = (string)($_POST['file_token'] ?? '');
    $options = [
        'create_client'   => !empty($_POST['opt_create_client']),
        'update_client'   => !empty($_POST['opt_update_client']),
        'update_project'  => !empty($_POST['opt_update_project']),
        'create_brand'    => !empty($_POST['opt_create_brand']),
        'create_tech'     => !empty($_POST['opt_create_tech']),
        'create_cert'     => !empty($_POST['opt_create_cert']),
    ];

    $csv_path = sys_get_temp_dir() . '/pm_import_' . preg_replace('/[^a-z0-9]/i', '', $file_token) . '.csv';
    if (!is_file($csv_path)) {
        $msg = "<div class='alert alert-danger'>File temporaneo scaduto o non trovato. Ricarica il CSV.</div>";
    } else {
        $report = pm_execute_import($pdo, $csv_path, $options, $u_id);
        @unlink($csv_path);

        $color = $report['errors'] > 0 ? 'warning' : 'success';
        $icon  = $report['errors'] > 0 ? 'circle-exclamation' : 'check';
        $msg = "<div class='alert alert-$color'>
            <h4 style='margin:0 0 8px 0'><i class='fa-solid fa-$icon'></i> Import completato</h4>
            <table style='font-size:13px;width:100%'>
              <tr><td>📁 Progetti <strong>creati</strong>:</td><td><strong>" . $report['created_proj'] . "</strong></td></tr>
              <tr><td>🔄 Progetti <strong>aggiornati</strong>:</td><td><strong>" . $report['updated_proj'] . "</strong></td></tr>
              <tr><td>⏭ Progetti <strong>skippati</strong>:</td><td><strong>" . $report['skipped_proj'] . "</strong></td></tr>
              <tr><td>👤 Clienti <strong>creati</strong>:</td><td><strong>" . $report['created_client'] . "</strong></td></tr>
              <tr><td>♻ Clienti <strong>aggiornati</strong>:</td><td><strong>" . $report['updated_client'] . "</strong></td></tr>
              <tr><td>🏷 Brand creati / Tecnologie create / Cert. create:</td><td><strong>" . $report['created_brand'] . " / " . $report['created_tech'] . " / " . $report['created_cert'] . "</strong></td></tr>
              <tr><td style='color:#dc2626'>⚠ <strong>Errori</strong>:</td><td><strong style='color:#dc2626'>" . $report['errors'] . "</strong></td></tr>
            </table>";

        if (!empty($report['log'])) {
            $msg .= "<details style='margin-top:10px'><summary style='cursor:pointer;font-weight:700'>📋 Log dettagliato (" . count($report['log']) . " righe)</summary>";
            $msg .= "<pre style='max-height:300px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:10px;font-size:11px;border-radius:6px;margin-top:6px'>";
            $msg .= h(implode("\n", $report['log']));
            $msg .= "</pre></details>";
        }
        $msg .= "</div>";

        write_log('Projects', 'success',
            "Import massivo: {$report['created_proj']} creati, {$report['updated_proj']} aggiornati, {$report['errors']} errori",
            $u_id);
    }
}

// ────────────────────────────────────────────────────────────────────
// FASE 2: PREVIEW (POST con file uploaded)
// ────────────────────────────────────────────────────────────────────
$preview_rows = null;
$preview_token = null;
$preview_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    Csrf::verify();

    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "<div class='alert alert-danger'>Errore upload file: codice " . ($_FILES['csv_file']['error'] ?? '?') . "</div>";
    } else {
        $tmp_uploaded = $_FILES['csv_file']['tmp_name'];
        $file_size = filesize($tmp_uploaded);

        if ($file_size > 5 * 1024 * 1024) {
            $msg = "<div class='alert alert-danger'>File troppo grande (max 5MB).</div>";
        } else {
            // Genera token e salva in /tmp
            $token = bin2hex(random_bytes(8));
            $csv_path = sys_get_temp_dir() . '/pm_import_' . $token . '.csv';
            if (!move_uploaded_file($tmp_uploaded, $csv_path)) {
                $msg = "<div class='alert alert-danger'>Impossibile salvare file temporaneo.</div>";
            } else {
                $parsed = pm_parse_csv($csv_path);
                if (!empty($parsed['errors'])) {
                    $preview_errors = $parsed['errors'];
                    $msg = "<div class='alert alert-danger'><strong>Errori critici di parsing:</strong><ul style='margin:5px 0 0 20px'>"
                         . implode('', array_map(fn($e) => "<li>$e</li>", $parsed['errors']))
                         . "</ul></div>";
                    @unlink($csv_path);
                } else {
                    $preview_rows = $parsed['rows'];
                    $preview_token = $token;
                }
            }
        }
    }
}

// ────────────────────────────────────────────────────────────────────
// FUNZIONI PARSING + IMPORT
// ────────────────────────────────────────────────────────────────────
function pm_parse_csv(string $path): array
{
    $rows = [];
    $errors = [];

    $fh = fopen($path, 'r');
    if (!$fh) return ['rows' => [], 'errors' => ['Impossibile aprire file CSV']];

    // Auto-detect BOM UTF-8
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    // Detect separator: ; (italiano) or , (inglese)
    $first_line = fgets($fh);
    rewind($fh);
    if (substr($bom, 0, 3) === "\xEF\xBB\xBF") fread($fh, 3);
    $sep = (substr_count($first_line, ';') >= substr_count($first_line, ',')) ? ';' : ',';

    $headers = fgetcsv($fh, 0, $sep);
    if (!$headers) {
        fclose($fh);
        return ['rows' => [], 'errors' => ['CSV vuoto o malformato']];
    }
    $headers = array_map(fn($h) => trim(strtolower($h)), $headers);

    $required = ['title', 'client_name'];
    foreach ($required as $r) {
        if (!in_array($r, $headers, true)) {
            $errors[] = "Colonna obbligatoria mancante: <code>$r</code>";
        }
    }
    if (!empty($errors)) {
        fclose($fh);
        return ['rows' => [], 'errors' => $errors];
    }

    $line_num = 1;
    while (($cols = fgetcsv($fh, 0, $sep)) !== false) {
        $line_num++;
        if (count(array_filter($cols, fn($c) => trim((string)$c) !== '')) === 0) continue;   // skip vuote

        $row = [];
        foreach ($headers as $i => $h) $row[$h] = trim((string)($cols[$i] ?? ''));
        $row['_line'] = $line_num;
        $row['_warnings'] = [];

        // Validation minima per riga
        if ($row['title'] === '') {
            $row['_warnings'][] = 'titolo vuoto → riga skippata';
        }
        if (empty($row['client_name'])) {
            $row['_warnings'][] = 'cliente vuoto → riga skippata';
        }
        $valid_sizes = ['PMI','Enterprise','Core/Infrastruttura Datacenter'];
        if (!empty($row['client_size']) && !in_array($row['client_size'], $valid_sizes, true)) {
            $row['_warnings'][] = "client_size non valido: '{$row['client_size']}' → default PMI";
        }
        $valid_statuses = ['draft','active','completed','cancelled','on_hold'];
        if (!empty($row['status']) && !in_array($row['status'], $valid_statuses, true)) {
            $row['_warnings'][] = "status non valido: '{$row['status']}' → default active";
        }

        $rows[] = $row;
    }
    fclose($fh);

    if (empty($rows)) {
        $errors[] = 'Nessuna riga dati trovata nel CSV';
    }

    return ['rows' => $rows, 'errors' => $errors];
}

function pm_execute_import(PDO $pdo, string $csv_path, array $options, int $u_id): array
{
    $parsed = pm_parse_csv($csv_path);
    $rows = $parsed['rows'];

    $r = [
        'created_proj' => 0, 'updated_proj' => 0, 'skipped_proj' => 0,
        'created_client' => 0, 'updated_client' => 0,
        'created_brand' => 0, 'created_tech' => 0, 'created_cert' => 0,
        'errors' => 0,
        'log' => [],
    ];

    foreach ($rows as $row) {
        $line = $row['_line'];
        if (empty($row['title']) || empty($row['client_name'])) {
            $r['skipped_proj']++;
            $r['log'][] = "Riga $line SKIP: titolo o cliente vuoti";
            continue;
        }

        try {
            $pdo->beginTransaction();

            // ── 1. Risolvi/crea CLIENTE ──
            $client = pm_find_or_create_client($pdo, $row, $options, $r, $line);
            if (!$client) throw new RuntimeException("cliente non risolvibile");

            // ── 2. Risolvi azienda esecutrice ──
            $executor_id = null;
            if (!empty($row['executor_company'])) {
                $s = $pdo->prepare("SELECT id FROM companies WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                $s->execute([$row['executor_company']]);
                $executor_id = (int)$s->fetchColumn() ?: null;
                if (!$executor_id) {
                    $r['log'][] = "Riga $line WARN: azienda esecutrice '{$row['executor_company']}' non trovata in 'Aziende & Sedi' → ignorata";
                }
            }

            // ── 3. Esistente o nuovo progetto? ──
            $proj_stmt = $pdo->prepare(
                "SELECT id FROM projects WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) AND client_id = ? LIMIT 1"
            );
            $proj_stmt->execute([$row['title'], $client['id']]);
            $existing_proj_id = (int)$proj_stmt->fetchColumn() ?: 0;

            $proj_data = [
                'title'              => $row['title'],
                'description'        => $row['description'] ?? null,
                'client_id'          => $client['id'],
                'executing_company_id'=> $executor_id,
                'service_type'       => $row['service_type'] ?? null,
                'employees_involved' => ($row['employees_involved'] ?? '') !== '' ? (int)$row['employees_involved'] : null,
                'users_impacted'     => ($row['users_impacted']     ?? '') !== '' ? (int)$row['users_impacted']     : null,
                'date_start'         => pm_parse_date($row['date_start'] ?? ''),
                'date_end'           => pm_parse_date($row['date_end']   ?? ''),
                'is_in_progress'     => !empty($row['is_in_progress']) && $row['is_in_progress'] != '0' ? 1 : 0,
                'status'             => in_array($row['status'] ?? '', ['draft','active','completed','cancelled','on_hold'], true) ? $row['status'] : 'active',
                'value_euro'         => ($row['value_euro']      ?? '') !== '' ? (float)str_replace(',', '.', $row['value_euro'])      : null,
                'amount_services'    => ($row['amount_services'] ?? '') !== '' ? (float)str_replace(',', '.', $row['amount_services']) : null,
                'amount_hw_sw'       => ($row['amount_hw_sw']    ?? '') !== '' ? (float)str_replace(',', '.', $row['amount_hw_sw'])    : null,
                'duration_text'      => !empty($row['duration_text'])    ? trim($row['duration_text'])    : null,
                'commercial_agent'   => !empty($row['commercial_agent']) ? trim($row['commercial_agent']) : null,
                'period_text'        => !empty($row['period_text'])      ? trim($row['period_text'])      : null,
                'tech_areas'         => !empty($row['tech_areas'])       ? trim($row['tech_areas'])       : null,
                'notes'              => !empty($row['notes'])            ? trim($row['notes'])            : null,
                'confidential'       => !empty($row['confidential']) && $row['confidential'] != '0' ? 1 : 0,
            ];

            if ($existing_proj_id > 0) {
                if (!$options['update_project']) {
                    $r['skipped_proj']++;
                    $r['log'][] = "Riga $line SKIP: progetto '{$row['title']}' già presente (update_project disattivato)";
                    $pdo->commit();
                    continue;
                }
                $sql = "UPDATE projects SET
                          description=?, executing_company_id=?, service_type=?,
                          employees_involved=?, users_impacted=?,
                          date_start=?, date_end=?, is_in_progress=?, status=?,
                          value_euro=?, amount_services=?, amount_hw_sw=?,
                          duration_text=?, commercial_agent=?, period_text=?, tech_areas=?, notes=?,
                          confidential=?
                        WHERE id=?";
                $pdo->prepare($sql)->execute([
                    $proj_data['description'], $proj_data['executing_company_id'], $proj_data['service_type'],
                    $proj_data['employees_involved'], $proj_data['users_impacted'],
                    $proj_data['date_start'], $proj_data['date_end'], $proj_data['is_in_progress'], $proj_data['status'],
                    $proj_data['value_euro'], $proj_data['amount_services'], $proj_data['amount_hw_sw'],
                    $proj_data['duration_text'], $proj_data['commercial_agent'], $proj_data['period_text'],
                    $proj_data['tech_areas'], $proj_data['notes'],
                    $proj_data['confidential'],
                    $existing_proj_id,
                ]);
                $project_id = $existing_proj_id;
                $r['updated_proj']++;
                $r['log'][] = "Riga $line UPDATE: progetto #{$project_id} '{$row['title']}'";
            } else {
                $sql = "INSERT INTO projects
                          (title, description, client_id, executing_company_id, service_type,
                           employees_involved, users_impacted,
                           date_start, date_end, is_in_progress, status,
                           value_euro, amount_services, amount_hw_sw,
                           duration_text, commercial_agent, period_text, tech_areas, notes,
                           confidential, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $pdo->prepare($sql)->execute([
                    $proj_data['title'], $proj_data['description'], $proj_data['client_id'],
                    $proj_data['executing_company_id'], $proj_data['service_type'],
                    $proj_data['employees_involved'], $proj_data['users_impacted'],
                    $proj_data['date_start'], $proj_data['date_end'], $proj_data['is_in_progress'], $proj_data['status'],
                    $proj_data['value_euro'], $proj_data['amount_services'], $proj_data['amount_hw_sw'],
                    $proj_data['duration_text'], $proj_data['commercial_agent'], $proj_data['period_text'],
                    $proj_data['tech_areas'], $proj_data['notes'],
                    $proj_data['confidential'],
                    $u_id,
                ]);
                $project_id = (int)$pdo->lastInsertId();
                $r['created_proj']++;
                $r['log'][] = "Riga $line CREATE: progetto #{$project_id} '{$row['title']}'";
            }

            // ── 4. Pivot M:N: re-sync ──
            $brands_list = pm_split_list($row['brands'] ?? '');
            $techs_list  = pm_split_list($row['technologies'] ?? '');
            $certs_list  = pm_split_list($row['certifications'] ?? '');

            $pdo->prepare("DELETE FROM project_brands         WHERE project_id = ?")->execute([$project_id]);
            $pdo->prepare("DELETE FROM project_technologies   WHERE project_id = ?")->execute([$project_id]);
            $pdo->prepare("DELETE FROM project_certifications WHERE project_id = ?")->execute([$project_id]);

            // Mappa brand_name → brand_id (per il match tecnologie)
            $brand_map = [];

            foreach ($brands_list as $b_name) {
                $bid = pm_find_or_create_brand($pdo, $b_name, $options, $r, $line);
                if ($bid) {
                    $pdo->prepare("INSERT IGNORE INTO project_brands (project_id, brand_id) VALUES (?, ?)")->execute([$project_id, $bid]);
                    $brand_map[strtolower(trim($b_name))] = $bid;
                }
            }

            foreach ($techs_list as $t_raw) {
                // Formato accettato: "Brand:Nome" oppure "Nome"
                $bid = null; $tname = $t_raw;
                if (strpos($t_raw, ':') !== false) {
                    [$b_part, $t_part] = explode(':', $t_raw, 2);
                    $b_part = trim($b_part); $tname = trim($t_part);
                    if (isset($brand_map[strtolower($b_part)])) {
                        $bid = $brand_map[strtolower($b_part)];
                    } else {
                        // Risolvo dal DB
                        $s = $pdo->prepare("SELECT id FROM brands WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                        $s->execute([$b_part]);
                        $bid = (int)$s->fetchColumn() ?: null;
                    }
                }
                $tid = pm_find_or_create_tech($pdo, $tname, $bid, $options, $r, $line);
                if ($tid) {
                    $pdo->prepare("INSERT IGNORE INTO project_technologies (project_id, brand_technology_id) VALUES (?, ?)")->execute([$project_id, $tid]);
                }
            }

            foreach ($certs_list as $c_name) {
                $cid = pm_find_or_create_cert($pdo, $c_name, $options, $r, $line);
                if ($cid) {
                    $pdo->prepare("INSERT IGNORE INTO project_certifications (project_id, certification_id) VALUES (?, ?)")->execute([$project_id, $cid]);
                }
            }

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $r['errors']++;
            $r['log'][] = "Riga $line ERRORE: " . $e->getMessage();
        }
    }

    return $r;
}

function pm_find_or_create_client(PDO $pdo, array $row, array $opts, array &$r, int $line): ?array
{
    $name = trim($row['client_name']);
    if ($name === '') return null;

    $stmt = $pdo->prepare("SELECT * FROM project_clients WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$name]);
    $existing = $stmt->fetch();

    $size = in_array($row['client_size'] ?? '', ['PMI','Enterprise','Core/Infrastruttura Datacenter'], true)
        ? $row['client_size'] : 'PMI';
    $emp_count = ($row['client_employees'] ?? '') !== '' ? (int)$row['client_employees'] : null;
    $usr_count = ($row['client_users']     ?? '') !== '' ? (int)$row['client_users']     : null;
    $industry  = $row['client_industry'] ?? null;

    if ($existing) {
        if ($opts['update_client']) {
            $pdo->prepare("UPDATE project_clients SET size_category=?, employees_count=?, users_count=?, industry=? WHERE id=?")
                ->execute([$size, $emp_count, $usr_count, $industry, $existing['id']]);
            $r['updated_client']++;
            $r['log'][] = "Riga $line: cliente '$name' aggiornato (#{$existing['id']})";
        }
        return $existing;
    }

    if (!$opts['create_client']) {
        $r['log'][] = "Riga $line WARN: cliente '$name' non esistente e create_client disattivato";
        return null;
    }

    $pdo->prepare("INSERT INTO project_clients (name, size_category, employees_count, users_count, industry) VALUES (?,?,?,?,?)")
        ->execute([$name, $size, $emp_count, $usr_count, $industry]);
    $id = (int)$pdo->lastInsertId();
    $r['created_client']++;
    $r['log'][] = "Riga $line: cliente '$name' creato (#$id) — $size, $emp_count dip., $usr_count utenti";
    return ['id' => $id, 'name' => $name, 'size_category' => $size];
}

function pm_find_or_create_brand(PDO $pdo, string $name, array $opts, array &$r, int $line): ?int
{
    $name = trim($name);
    if ($name === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM brands WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$name]);
    $id = (int)$stmt->fetchColumn();
    if ($id) return $id;
    if (!$opts['create_brand']) {
        $r['log'][] = "Riga $line WARN: brand '$name' non trovato e create_brand disattivato";
        return null;
    }
    $pdo->prepare("INSERT INTO brands (name) VALUES (?)")->execute([$name]);
    $id = (int)$pdo->lastInsertId();
    $r['created_brand']++;
    $r['log'][] = "Riga $line: brand '$name' creato (#$id)";
    return $id;
}

function pm_find_or_create_tech(PDO $pdo, string $name, ?int $brand_id, array $opts, array &$r, int $line): ?int
{
    $name = trim($name);
    if ($name === '') return null;
    if (!$brand_id) {
        $r['log'][] = "Riga $line WARN: tecnologia '$name' senza brand specificato → skippata";
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM brand_technologies WHERE brand_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$brand_id, $name]);
    $id = (int)$stmt->fetchColumn();
    if ($id) return $id;
    if (!$opts['create_tech']) {
        $r['log'][] = "Riga $line WARN: tecnologia '$name' non trovata e create_tech disattivato";
        return null;
    }
    $pdo->prepare("INSERT INTO brand_technologies (brand_id, name, status, category) VALUES (?, ?, 'active', 'imported')")
        ->execute([$brand_id, $name]);
    $id = (int)$pdo->lastInsertId();
    $r['created_tech']++;
    $r['log'][] = "Riga $line: tecnologia '$name' (brand #$brand_id) creata (#$id)";
    return $id;
}

function pm_find_or_create_cert(PDO $pdo, string $name, array $opts, array &$r, int $line): ?int
{
    $name = trim($name);
    if ($name === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM certifications WHERE LOWER(TRIM(code)) = LOWER(TRIM(?)) OR LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmt->execute([$name, $name]);
    $id = (int)$stmt->fetchColumn();
    if ($id) return $id;
    if (!$opts['create_cert']) {
        $r['log'][] = "Riga $line WARN: certificazione '$name' non trovata e create_cert disattivato";
        return null;
    }
    $pdo->prepare("INSERT INTO certifications (name, code) VALUES (?, ?)")->execute([$name, $name]);
    $id = (int)$pdo->lastInsertId();
    $r['created_cert']++;
    $r['log'][] = "Riga $line: certificazione '$name' creata (#$id)";
    return $id;
}

function pm_split_list(string $s): array
{
    if (trim($s) === '') return [];
    $parts = array_map('trim', preg_split('/\s*\|\s*/', $s));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

function pm_parse_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    // Accetta YYYY-MM-DD o DD/MM/YYYY
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    return null;
}

require_once('header.php');
?>

<div style="margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:#0f172a;font-size:22px">
      <i class="fa-solid fa-file-import" style="color:#7c3aed"></i> Import massivo progetti
    </h2>
    <div style="font-size:12px;color:#64748b;margin-top:3px">
      <a href="<?= url_safe('projects') ?>" style="color:#64748b"><i class="fa-solid fa-arrow-left"></i> Torna ai progetti</a>
    </div>
  </div>
  <a href="<?= qs_self_safe(['action' => 'template']) ?>" class="btn">
    <i class="fa-solid fa-file-csv"></i> Scarica template CSV
  </a>
</div>

<?= $msg ?>

<?php if ($preview_rows === null): ?>
<!-- ═════ FASE 1: UPLOAD ═════ -->
<div class="card" style="padding:20px;margin-bottom:14px">
  <h3 style="margin:0 0 14px 0;font-size:15px;color:#7c3aed">
    <i class="fa-solid fa-cloud-arrow-up"></i> 1. Carica file CSV
  </h3>

  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="upload_csv" value="1">

    <div style="border:2px dashed #cbd5e1;border-radius:8px;padding:24px;text-align:center;background:#f8fafc;margin-bottom:14px">
      <i class="fa-solid fa-file-csv" style="font-size:42px;color:#7c3aed"></i>
      <p style="margin:10px 0 5px;font-weight:700">Trascina qui un file CSV o seleziona</p>
      <p style="margin:0 0 10px;font-size:11px;color:#64748b">Max 5MB · Separatore ; o , · UTF-8</p>
      <input type="file" name="csv_file" accept=".csv,text/csv" required
             style="margin:8px 0;padding:8px;border:1px solid #cbd5e1;border-radius:6px;background:white">
    </div>

    <h4 style="margin:14px 0 8px 0;font-size:13px;color:#475569">
      <i class="fa-solid fa-sliders"></i> Opzioni import
    </h4>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;background:#f8fafc;padding:12px;border-radius:6px">
      <label style="cursor:pointer">
        <input type="checkbox" name="opt_create_client" value="1" checked>
        <strong>Crea clienti</strong> nuovi automaticamente
      </label>
      <label style="cursor:pointer">
        <input type="checkbox" name="opt_update_client" value="1">
        <strong>Aggiorna</strong> clienti esistenti con dati CSV
      </label>
      <label style="cursor:pointer">
        <input type="checkbox" name="opt_update_project" value="1" checked>
        <strong>Aggiorna</strong> progetti se titolo+cliente già esistono
      </label>
      <label style="cursor:pointer">
        <input type="checkbox" name="opt_create_brand" value="1" checked>
        <strong>Crea brand</strong> nuovi se non in anagrafica
      </label>
      <label style="cursor:pointer">
        <input type="checkbox" name="opt_create_tech" value="1" checked>
        <strong>Crea tecnologie</strong> nuove se non in anagrafica
      </label>
      <label style="cursor:pointer">
        <input type="checkbox" name="opt_create_cert" value="1" checked>
        <strong>Crea certificazioni</strong> nuove se non in anagrafica
      </label>
    </div>

    <div style="margin-top:14px">
      <button type="submit" class="btn btn-primary" style="background:#7c3aed">
        <i class="fa-solid fa-eye"></i> Carica e anteprima
      </button>
    </div>
  </form>
</div>

<div class="card" style="padding:18px;background:#fefce8;border:1px solid #fde68a">
  <h4 style="margin:0 0 8px 0;font-size:13px;color:#a16207">
    <i class="fa-solid fa-circle-info"></i> Formato CSV
  </h4>
  <p style="margin:0 0 8px 0;font-size:12px;color:#475569">
    Colonne accettate (obbligatorie: <strong>title</strong>, <strong>client_name</strong>):
  </p>
  <pre style="background:#1e293b;color:#e2e8f0;padding:10px;font-size:11px;border-radius:6px;overflow-x:auto;margin:0">title;client_name;client_size;client_employees;client_users;client_industry;
executor_company;description;service_type;employees_involved;users_impacted;
date_start;date_end;is_in_progress;status;value_euro;confidential;
<span style="color:#fbbf24">duration_text;commercial_agent;amount_services;amount_hw_sw;period_text;tech_areas;notes;</span>
brands;technologies;certifications</pre>

  <ul style="margin:8px 0 0 18px;font-size:11px;color:#475569;line-height:1.7">
    <li><strong>client_size</strong>: <code>PMI</code> | <code>Enterprise</code> | <code>Core/Infrastruttura Datacenter</code></li>
    <li><strong>status</strong>: <code>draft</code> | <code>active</code> | <code>completed</code> | <code>on_hold</code> | <code>cancelled</code></li>
    <li><strong>brands</strong>: lista separata da <code>|</code> &nbsp;(es: <code>Cisco|VMware|Fortinet</code>)</li>
    <li><strong>technologies</strong>: formato <code>Brand:Nome</code> separato da <code>|</code> &nbsp;(es: <code>Cisco:Catalyst|VMware:vSphere</code>)</li>
    <li><strong>certifications</strong>: nome o codice separato da <code>|</code> &nbsp;(es: <code>CCNA|VCAP-DCV</code>)</li>
    <li><strong>date_start</strong>, <strong>date_end</strong>: formato <code>YYYY-MM-DD</code> o <code>DD/MM/YYYY</code></li>
    <li><strong>is_in_progress</strong>, <strong>confidential</strong>: <code>0</code> o <code>1</code></li>
    <li><strong>executor_company</strong>: nome esatto azienda in <em>Aziende & Sedi</em></li>
    <li style="color:#a16207"><strong>duration_text</strong>: durata libera, es: <code>6 MESI</code>, <code>Progetto una tantum</code>, <code>10 anni</code></li>
    <li style="color:#a16207"><strong>commercial_agent</strong>: nome agente/referente commerciale (testo libero)</li>
    <li style="color:#a16207"><strong>amount_services</strong>, <strong>amount_hw_sw</strong>: subtotali di <code>value_euro</code> (es. <code>60000</code>, <code>5000</code>)</li>
    <li style="color:#a16207"><strong>period_text</strong>: periodo testuale, es: <code>2024</code>, <code>2023-2024</code>, <code>nov 2023-ott 2026</code></li>
    <li style="color:#a16207"><strong>tech_areas</strong>: aree tecnologiche libere separate da <code>|</code>, es: <code>TRASLOCO DC|CABLING|VDI</code></li>
    <li style="color:#a16207"><strong>notes</strong>: note operative aggiuntive</li>
  </ul>
</div>
<?php else: ?>
<!-- ═════ FASE 2: PREVIEW ═════ -->
<div class="card" style="padding:14px;margin-bottom:14px;background:#dbeafe;border:1px solid #93c5fd">
  <h3 style="margin:0 0 6px 0;font-size:15px;color:#1e40af">
    <i class="fa-solid fa-table-list"></i> 2. Anteprima — <?= count($preview_rows) ?> righe trovate
  </h3>
  <p style="margin:0;font-size:12px;color:#1e3a8a">
    Controlla le righe sotto. Le righe con <strong style="color:#dc2626">warning</strong> saranno
    importate con i valori di default. Le righe senza titolo o cliente saranno skippate.
  </p>
</div>

<div class="card" style="overflow-x:auto;margin-bottom:14px;padding:0">
  <table style="width:100%;border-collapse:collapse;font-size:11px;min-width:1200px">
    <thead style="background:#1e293b;color:#fff;position:sticky;top:0">
      <tr>
        <th style="padding:8px;text-align:left">#</th>
        <th style="padding:8px;text-align:left">Titolo</th>
        <th style="padding:8px;text-align:left">Cliente</th>
        <th style="padding:8px;text-align:left">Dim.</th>
        <th style="padding:8px;text-align:left">Esecutore</th>
        <th style="padding:8px;text-align:left">Servizio</th>
        <th style="padding:8px;text-align:left">Durata</th>
        <th style="padding:8px;text-align:left">Agente</th>
        <th style="padding:8px;text-align:center">Periodo</th>
        <th style="padding:8px;text-align:right">Dip.</th>
        <th style="padding:8px;text-align:right">Utenti</th>
        <th style="padding:8px;text-align:left">Brand</th>
        <th style="padding:8px;text-align:left">Tech</th>
        <th style="padding:8px;text-align:left">Cert</th>
        <th style="padding:8px;text-align:left">⚠</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($preview_rows as $row): ?>
      <?php $skip = empty($row['title']) || empty($row['client_name']); ?>
      <tr style="border-bottom:1px solid #e2e8f0<?= $skip ? ';background:#fee2e2' : '' ?>">
        <td style="padding:6px 8px;color:#94a3b8"><?= (int)$row['_line'] ?></td>
        <td style="padding:6px 8px;font-weight:600"><?= h($row['title'] ?: '— vuoto —') ?></td>
        <td style="padding:6px 8px"><?= h($row['client_name'] ?: '—') ?></td>
        <td style="padding:6px 8px;font-size:10px"><?= h($row['client_size'] ?? 'PMI') ?></td>
        <td style="padding:6px 8px;font-size:10px"><?= h($row['executor_company'] ?? '—') ?></td>
        <td style="padding:6px 8px;font-size:10px"><?= h($row['service_type'] ?? '—') ?></td>
        <td style="padding:6px 8px;font-size:10px"><?= h($row['duration_text'] ?? '—') ?></td>
        <td style="padding:6px 8px;font-size:10px"><?= h($row['commercial_agent'] ?? '—') ?></td>
        <td style="padding:6px 8px;font-size:10px;white-space:nowrap">
          <?= h($row['period_text'] ?? (($row['date_start'] ?? '') . ' → ' . ($row['date_end'] ?? '?'))) ?>
        </td>
        <td style="padding:6px 8px;text-align:right"><?= h($row['employees_involved'] ?? '—') ?></td>
        <td style="padding:6px 8px;text-align:right"><?= h($row['users_impacted'] ?? '—') ?></td>
        <td style="padding:6px 8px;font-size:10px;max-width:120px;word-wrap:break-word"><?= h($row['brands'] ?? '—') ?></td>
        <td style="padding:6px 8px;font-size:10px;max-width:150px;word-wrap:break-word"><?= h($row['technologies'] ?? '—') ?></td>
        <td style="padding:6px 8px;font-size:10px;max-width:120px;word-wrap:break-word"><?= h($row['certifications'] ?? '—') ?></td>
        <td style="padding:6px 8px">
          <?php if (!empty($row['_warnings'])): ?>
          <?php foreach ($row['_warnings'] as $w): ?>
          <div style="font-size:9px;color:#dc2626" title="<?= h($w) ?>"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <?php endforeach; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="padding:14px;background:#dcfce7;border:1px solid #86efac">
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="execute_import" value="1">
    <input type="hidden" name="file_token" value="<?= h($preview_token) ?>">
    <!-- Riporto le opzioni dell'utente -->
    <input type="hidden" name="opt_create_client"  value="<?= !empty($_POST['opt_create_client'])?1:0 ?>">
    <input type="hidden" name="opt_update_client"  value="<?= !empty($_POST['opt_update_client'])?1:0 ?>">
    <input type="hidden" name="opt_update_project" value="<?= !empty($_POST['opt_update_project'])?1:0 ?>">
    <input type="hidden" name="opt_create_brand"   value="<?= !empty($_POST['opt_create_brand'])?1:0 ?>">
    <input type="hidden" name="opt_create_tech"    value="<?= !empty($_POST['opt_create_tech'])?1:0 ?>">
    <input type="hidden" name="opt_create_cert"    value="<?= !empty($_POST['opt_create_cert'])?1:0 ?>">

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary" style="background:#16a34a"
              onclick="return confirm('Confermi l\'import di <?= count($preview_rows) ?> righe?\n\nL\'operazione è reversibile solo manualmente.')">
        <i class="fa-solid fa-rocket"></i> 3. Esegui import
      </button>
      <a href="<?= url_safe('project_import') ?>" class="btn">
        <i class="fa-solid fa-xmark"></i> Annulla / Carica altro file
      </a>
      <span style="margin-left:auto;font-size:11px;color:#166534;font-weight:600">
        Token file: <code><?= h($preview_token) ?></code> · valido per questa sessione
      </span>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
