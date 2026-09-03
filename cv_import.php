<?php
/**
 * PortalManager 1.7.4 — cv_import.php
 *
 * Workflow:
 *  STEP 1: Upload file CV (DOCX/PDF/JPG/PNG)
 *  STEP 2: Parsing automatico + anteprima editabile dei campi
 *  STEP 3: Scelta target (nuovo candidato / nuovo dipendente / aggiorna esistente)
 *  STEP 4: Salvataggio in DB
 *
 * RBAC: Super Admin (1), HR Director (2), Recruiter (5)
 */

require_once('access_control.php');
require_once __DIR__ . '/app/CvParser.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!in_array($u_role, [1, 2, 5], true)) {
    http_response_code(403);
    die('Accesso negato.');
}

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$msg = '';
$parsed = null;          // dati estratti
$step = (int)($_GET['step'] ?? 1);

// Cartella temporanea per i file CV caricati
$tmp_dir = APP_ROOT . '/uploads/cv_imports/';
if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);

// ─────────────────────────────────────────────────────────────────────
// STEP 1 → STEP 2: upload e parsing
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_cv') {
    try {
        if (empty($_FILES['cv_file']) || $_FILES['cv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Errore upload file.');
        }
        if ($_FILES['cv_file']['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('File troppo grande (max 10 MB).');
        }
        $ext = strtolower(pathinfo($_FILES['cv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'pdf', 'jpg', 'jpeg', 'png', 'txt', 'rtf'], true)) {
            throw new RuntimeException('Formato non supportato. Usa: DOCX, PDF, JPG, PNG, TXT, RTF.');
        }

        // Salvo il file con nome univoco
        $stored_name = 'cv_' . $u_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $stored_path = $tmp_dir . $stored_name;
        if (!move_uploaded_file($_FILES['cv_file']['tmp_name'], $stored_path)) {
            throw new RuntimeException('Salvataggio file fallito.');
        }

        // Parsing
        $parser = new CvParser();
        $parsed = $parser->parseFile($stored_path, $_FILES['cv_file']['name']);
        $parsed['_filename']    = $_FILES['cv_file']['name'];
        $parsed['_stored_name'] = $stored_name;
        $parsed['_raw_text']    = mb_substr($parser->getRawText(), 0, 5000);

        $_SESSION['_cv_parsed'] = $parsed;
        $step = 2;

        if (function_exists('write_log')) {
            write_log('CvImport', 'success',
                'Parsing CV: ' . $_FILES['cv_file']['name'] . ' - ' .
                'campi trovati: ' . count(array_filter($parsed, fn($v) => $v !== null && $v !== [] && $v !== '')),
                $u_id);
        }

    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . $h($e->getMessage()) . "</div>";
    }
}

// ─────────────────────────────────────────────────────────────────────
// STEP 2 → STEP 3: salvataggio
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_import') {
    try {
        $target = $_POST['target'] ?? 'candidate';
        if (!in_array($target, ['candidate', 'employee', 'update_employee'], true)) {
            throw new RuntimeException('Target non valido.');
        }

        $data = [
            'first_name'          => trim($_POST['first_name'] ?? '') ?: null,
            'last_name'           => trim($_POST['last_name'] ?? '') ?: null,
            'email'               => trim($_POST['email'] ?? '') ?: null,
            'phone'               => trim($_POST['phone'] ?? '') ?: null,
            'fiscal_code'         => trim($_POST['fiscal_code'] ?? '') ?: null,
            'date_of_birth'       => trim($_POST['date_of_birth'] ?? '') ?: null,
            'city_of_birth'       => trim($_POST['city_of_birth'] ?? '') ?: null,
            'address'             => trim($_POST['address'] ?? '') ?: null,
            'linkedin_url'        => trim($_POST['linkedin_url'] ?? '') ?: null,
            'credly_url'          => trim($_POST['credly_url'] ?? '') ?: null,
            'job_title'           => trim($_POST['job_title'] ?? '') ?: null,
            'bio'                 => trim($_POST['bio'] ?? '') ?: null,
            'education_level'     => trim($_POST['education_level'] ?? '') ?: null,
            'education_field'     => trim($_POST['education_field'] ?? '') ?: null,
            'education_institute' => trim($_POST['education_institute'] ?? '') ?: null,
            'education_year'      => trim($_POST['education_year'] ?? '') ?: null,
            'technical_skills'    => trim($_POST['technical_skills'] ?? '') ?: null,
            'soft_skills'         => trim($_POST['soft_skills'] ?? '') ?: null,
        ];

        if (!$data['first_name'] || !$data['last_name']) {
            throw new RuntimeException('Nome e cognome sono obbligatori.');
        }

        $languages = json_decode($_POST['languages_json'] ?? '[]', true) ?: [];
        $experiences = json_decode($_POST['experiences_json'] ?? '[]', true) ?: [];
        $certifications = json_decode($_POST['certifications_json'] ?? '[]', true) ?: [];

        $pdo->beginTransaction();
        $target_emp_id = null;

        if ($target === 'candidate') {
            // ─── Crea candidato in candidates (recruiting pipeline) ───
            try {
                // Costruisco le note
                $notes = "Importato da CV: {$_SESSION['_cv_parsed']['_filename']}\n";
                if (!empty($data['bio'])) $notes .= "\nProfilo: {$data['bio']}\n";
                if (!empty($data['job_title'])) $notes .= "\nRuolo attuale: {$data['job_title']}\n";
                if (!empty($experiences)) {
                    $notes .= "\nEsperienze:\n";
                    foreach ($experiences as $e) {
                        $notes .= "- " . ($e['period'] ?? '?') . ' | ' . ($e['title'] ?? '?') . ' @ ' . ($e['company'] ?? '?') . "\n";
                    }
                }
                if (!empty($certifications)) {
                    $notes .= "\nCertificazioni:\n";
                    foreach ($certifications as $c) {
                        $notes .= "- " . ($c['name'] ?? '?') . ($c['issue_year'] ? " ({$c['issue_year']})" : '') . "\n";
                    }
                }

                // External certs in formato leggibile
                $external_certs = '';
                if (!empty($certifications)) {
                    $ec_lines = [];
                    foreach ($certifications as $c) {
                        $line = $c['name'] ?? '';
                        if (!empty($c['brand']))      $line .= ' [' . $c['brand'] . ']';
                        if (!empty($c['issue_year'])) $line .= ' (' . $c['issue_year'] . ')';
                        $ec_lines[] = $line;
                    }
                    $external_certs = implode("\n", $ec_lines);
                }

                // Skills tags combinati
                $skills_tags = '';
                if (!empty($data['technical_skills'])) $skills_tags .= $data['technical_skills'];

                // Soft skills come stringa separata
                $soft_skills_notes = $data['soft_skills'] ?? null;

                // Determino le colonne realmente disponibili (sicurezza schema)
                $cols_avail = [];
                try {
                    foreach ($pdo->query("SHOW COLUMNS FROM candidates") as $r) $cols_avail[$r['Field']] = true;
                } catch (Throwable $e) {
                    throw new RuntimeException('Tabella candidates non trovata.');
                }

                // Costruisco l'INSERT solo con colonne esistenti
                $cv_filename = $_SESSION['_cv_parsed']['_stored_name'] ?? null;
                $candidate_map = [
                    'first_name'          => $data['first_name'],
                    'last_name'           => $data['last_name'],
                    'email'               => $data['email'],
                    'phone'               => $data['phone'],
                    'linkedin_url'        => $data['linkedin_url'],
                    'cv_path'             => $cv_filename ? 'cv_imports/' . $cv_filename : null,
                    'skills_tags'         => $skills_tags ?: null,
                    'source'              => 'CV Import',
                    'gdpr_consent'        => 0,  // NON acquisito automaticamente da CV
                    'status'              => 'new',
                    'notes'               => $notes,
                    'education_level'     => $data['education_level'],
                    'education_field'     => $data['education_field'],
                    'education_institute' => $data['education_institute'],
                    'education_year'      => $data['education_year'],
                    'external_certs'      => $external_certs ?: null,
                    'soft_skills_notes'   => $soft_skills_notes,
                    'added_by'            => $u_id,
                    'created_at'          => null, // gestito separatamente con NOW()
                ];

                $insert_cols = [];
                $placeholders = [];
                $params = [];
                foreach ($candidate_map as $col => $val) {
                    if (!isset($cols_avail[$col])) continue;
                    $insert_cols[] = "`$col`";
                    if ($col === 'created_at') {
                        $placeholders[] = 'NOW()';  // niente placeholder per NOW()
                    } else {
                        $placeholders[] = '?';
                        $params[] = $val;
                    }
                }

                if (empty($insert_cols)) {
                    throw new RuntimeException('Nessuna colonna mappabile in candidates.');
                }

                $sql = "INSERT INTO candidates (" . implode(',', $insert_cols) . ")
                        VALUES (" . implode(',', $placeholders) . ")";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $candidate_id = (int)$pdo->lastInsertId();

                // v1.7.38: registra il CV nel registry candidate_documents
                if ($cv_filename) {
                    try {
                        $cv_full_path = ($_SESSION['_cv_parsed']['_stored_path'] ?? '');
                        $file_size = $cv_full_path && is_file($cv_full_path) ? filesize($cv_full_path) : null;
                        $orig_name = $_SESSION['_cv_parsed']['_original_name'] ?? $cv_filename;
                        $pdo->prepare("
                            INSERT INTO candidate_documents
                              (candidate_id, doc_type, file_path, original_filename, file_size, uploaded_by)
                            VALUES (?, 'cv', ?, ?, ?, ?)
                        ")->execute([
                            $candidate_id,
                            'cv_imports/' . $cv_filename,
                            $orig_name,
                            $file_size,
                            (int)$_SESSION['user_id'],
                        ]);
                    } catch (Throwable $e) {
                        // Non blocco: il candidato è stato creato
                        write_log('Recruiting','warning',"CV import: registry document fallito per #$candidate_id: " . $e->getMessage(), (int)$_SESSION['user_id']);
                    }
                }

                $created_msg = "Candidato #$candidate_id creato con successo.";
            } catch (Throwable $e) {
                throw new RuntimeException('Errore creazione candidato: ' . $e->getMessage());
            }
        }
        elseif ($target === 'employee' || $target === 'update_employee') {
            // ─── Crea o aggiorna dipendente ───
            $target_emp_id = null;
            if ($target === 'update_employee') {
                $target_emp_id = (int)($_POST['target_employee_id'] ?? 0);
                if (!$target_emp_id) throw new RuntimeException('Dipendente target non selezionato.');
                $exists = $pdo->prepare("SELECT id FROM employees WHERE id=?");
                $exists->execute([$target_emp_id]);
                if (!$exists->fetchColumn()) throw new RuntimeException('Dipendente non trovato.');
            }

            // Scopro le colonne effettivamente esistenti in employees
            $existing_cols = [];
            try {
                foreach ($pdo->query("SHOW COLUMNS FROM employees") as $r) {
                    $existing_cols[$r['Field']] = true;
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Tabella employees non trovata: ' . $e->getMessage());
            }

            // Path del CV caricato (relativo a /uploads/)
            $cv_filename = $_SESSION['_cv_parsed']['_stored_name'] ?? null;
            $cv_path_rel = $cv_filename ? 'cv_imports/' . $cv_filename : null;

            // Mappa colonna employees -> valore (solo colonne realmente in DB)
            // Usa la chiave business_email se esiste, altrimenti personal_email (non entrambe)
            $email_col = isset($existing_cols['business_email']) ? 'business_email'
                       : (isset($existing_cols['personal_email']) ? 'personal_email' : null);

            $row_values = [];
            $maybe = [
                'first_name'          => $data['first_name'],
                'last_name'           => $data['last_name'],
                'phone'               => $data['phone'],
                'fiscal_code'         => $data['fiscal_code'],
                'date_of_birth'       => $data['date_of_birth'] ?: null,
                'linkedin_url'        => $data['linkedin_url'],
                'credly_url'          => $data['credly_url'],
                'job_title'           => $data['job_title'],
                'bio'                 => $data['bio'],
                'education_level'     => $data['education_level'],
                'education_field'     => $data['education_field'],
                'education_institute' => $data['education_institute'],
                'education_year'      => $data['education_year'],
                'technical_skills'    => $data['technical_skills'],
                'soft_skills'         => $data['soft_skills'],
                'cv_path'             => $cv_path_rel,
            ];
            // Email gestita separatamente per evitare duplicati
            if ($email_col && !empty($data['email'])) {
                $maybe[$email_col] = $data['email'];
            }
            // Campi opzionali se esistono (city_of_birth e address NON sono in employees standard)
            if (isset($existing_cols['city_of_birth']))  $maybe['city_of_birth']  = $data['city_of_birth'];
            if (isset($existing_cols['address']))        $maybe['address']        = $data['address'];
            if (isset($existing_cols['phone_personal']) && empty($maybe['phone'])) {
                $maybe['phone_personal'] = $data['phone'];
            }

            // Filtro alle sole colonne effettivamente esistenti
            foreach ($maybe as $col => $val) {
                if (isset($existing_cols[$col])) $row_values[$col] = $val;
            }

            if ($target === 'employee') {
                // INSERT
                if (empty($row_values['first_name']) || empty($row_values['last_name'])) {
                    throw new RuntimeException('Nome e cognome sono obbligatori per creare il dipendente.');
                }
                $insert_cols = array_keys($row_values);
                $placeholders = array_fill(0, count($insert_cols), '?');
                $params = array_values($row_values);

                // Aggiungo status + created_at se esistono
                $extra_cols = [];
                $extra_phs  = [];
                if (isset($existing_cols['status']))     { $extra_cols[] = 'status';     $extra_phs[] = "'active'"; }
                if (isset($existing_cols['created_at'])) { $extra_cols[] = 'created_at'; $extra_phs[] = 'NOW()'; }

                $all_cols  = array_merge(array_map(fn($c) => "`$c`", $insert_cols), array_map(fn($c) => "`$c`", $extra_cols));
                $all_phs   = array_merge($placeholders, $extra_phs);
                $sql = "INSERT INTO employees (" . implode(',', $all_cols) . ") VALUES (" . implode(',', $all_phs) . ")";
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $target_emp_id = (int)$pdo->lastInsertId();
                $created_msg = "Dipendente #$target_emp_id creato con successo.";
            } else {
                // UPDATE selettivo - solo campi non vuoti per non sovrascrivere con null
                $set_parts = [];
                $params = [];
                foreach ($row_values as $col => $val) {
                    if ($val === null || $val === '') continue;
                    $set_parts[] = "`$col` = ?";
                    $params[] = $val;
                }
                if (empty($set_parts)) {
                    $created_msg = "Dipendente #$target_emp_id: nessun dato da aggiornare.";
                } else {
                    $params[] = $target_emp_id;
                    $sql = "UPDATE employees SET " . implode(', ', $set_parts) . " WHERE id = ?";
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $created_msg = "Dipendente #$target_emp_id aggiornato con successo (" . count($set_parts) . " campi).";
                }
            }

            // ─── Salva lingue ───
            if (!empty($languages) && $target_emp_id) {
                try {
                    $pdo->prepare("DELETE FROM emp_languages WHERE employee_id=?")->execute([$target_emp_id]);
                    $lst = $pdo->prepare(
                        "INSERT INTO emp_languages (employee_id, language_name, mother_tongue,
                                                    level_listening, level_reading, level_spoken_interaction,
                                                    level_spoken_production, level_writing, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    );
                    foreach ($languages as $l) {
                        if (empty($l['language_name'])) continue;
                        $lst->execute([
                            $target_emp_id,
                            $l['language_name'],
                            !empty($l['mother_tongue']) ? 1 : 0,
                            $l['level_listening']          ?: null,
                            $l['level_reading']            ?: null,
                            $l['level_spoken_interaction'] ?: null,
                            $l['level_spoken_production']  ?: null,
                            $l['level_writing']            ?: null,
                            $u_id,
                        ]);
                    }
                } catch (Throwable $e) { /* tabella potrebbe non esistere */ }
            }

            // ─── Salva certificazioni (match con catalogo) ───
            $cert_imported = 0;
            $cert_unmatched = 0;
            if (!empty($certifications) && $target_emp_id) {
                try {
                    foreach ($certifications as $c) {
                        $name = trim($c['name'] ?? '');
                        if (!$name) continue;
                        $year = trim($c['issue_year'] ?? '');

                        // Cerca certificazione nel catalogo per name LIKE
                        $f = $pdo->prepare("SELECT id FROM certifications WHERE name LIKE ? LIMIT 1");
                        $f->execute(['%' . $name . '%']);
                        $cert_id = $f->fetchColumn();
                        if (!$cert_id) {
                            $f2 = $pdo->prepare("SELECT id FROM certifications WHERE code LIKE ? LIMIT 1");
                            $f2->execute(['%' . $name . '%']);
                            $cert_id = $f2->fetchColumn();
                        }
                        if (!$cert_id) { $cert_unmatched++; continue; }

                        $issue_date = $year ? "$year-01-01" : null;
                        try {
                            $pdo->prepare(
                                "INSERT INTO user_certifications
                                    (employee_id, certification_id, issue_date, status, created_at, created_by)
                                 VALUES (?,?,?, 'active', NOW(), ?)"
                            )->execute([$target_emp_id, $cert_id, $issue_date, $u_id]);
                            $cert_imported++;
                        } catch (Throwable $e) { /* duplicato */ }
                    }
                } catch (Throwable $e) {}
            }

            if ($cert_imported || $cert_unmatched) {
                $created_msg .= " Certificazioni: $cert_imported importate";
                if ($cert_unmatched) $created_msg .= ", $cert_unmatched non trovate nel catalogo";
                $created_msg .= '.';
            }
        }

        $pdo->commit();

        // Pulizia
        $stored = $_SESSION['_cv_parsed']['_stored_name'] ?? null;
        if ($stored && is_file($tmp_dir . $stored)) {
            // Lascio il file per audit, ma potrei rimuoverlo
        }
        unset($_SESSION['_cv_parsed']);

        if (function_exists('write_log')) {
            write_log('CvImport', 'success', "Import CV completato: $target -> emp#" . ($target_emp_id ?? '?'), $u_id);
        }

        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check-circle'></i> <strong>" . $h($created_msg) . "</strong>";
        if ($target_emp_id) {
            $link = function_exists('url_safe') ? url_safe('employee_profile', ['id' => $target_emp_id]) : 'employee_profile.php?id=' . $target_emp_id;
            $msg .= " <a href=\"$link\" style='color:#fff;text-decoration:underline;margin-left:8px'>Apri scheda dipendente →</a>";
        } elseif (isset($candidate_id)) {
            $link = function_exists('url_safe') ? url_safe('recruiting_candidati') : 'recruiting_candidati.php';
            $msg .= " <a href=\"$link\" style='color:#fff;text-decoration:underline;margin-left:8px'>Apri pipeline candidati →</a>";
        }
        $msg .= "</div>";
        $step = 1;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . $h($e->getMessage()) . "</div>";
        $step = 2;
        $parsed = $_SESSION['_cv_parsed'] ?? null;
    }
}

// Recupera dati parsed da sessione (per re-render dopo errore)
if ($step === 2 && empty($parsed) && !empty($_SESSION['_cv_parsed'])) {
    $parsed = $_SESSION['_cv_parsed'];
}

// Dipendenti per dropdown update
$employees = $pdo->query(
    "SELECT id, CONCAT(last_name, ' ', first_name) AS full_name, employee_code
       FROM employees
      WHERE status != 'terminated'
      ORDER BY last_name, first_name"
)->fetchAll();
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0">
      <i class="fa-solid fa-file-import" style="color:#7c3aed"></i> Importa anagrafica da CV
    </h1>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">
      Carica un CV in formato DOCX, PDF o immagine: i dati vengono estratti automaticamente e popolati nei campi
    </div>
  </div>
  <a href="<?= function_exists('url_safe') ? url_safe('manage_employees') : 'manage_employees.php' ?>" class="btn btn-sm">
    <i class="fa-solid fa-arrow-left"></i> Anagrafica
  </a>
</div>

<!-- Wizard steps -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;font-size:12px">
  <?php
  $steps = [1 => 'Carica CV', 2 => 'Verifica dati', 3 => 'Salvataggio'];
  foreach ($steps as $n => $label):
      $active = ($step === $n);
      $done = ($step > $n);
  ?>
  <div style="display:flex;align-items:center;gap:6px">
    <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;<?= $active || $done ? 'background:#7c3aed;color:#fff' : 'background:#e2e8f0;color:#64748b' ?>">
      <?= $done ? '✓' : $n ?>
    </div>
    <span style="font-weight:<?= $active ? '800' : '600' ?>;color:<?= $active ? '#7c3aed' : '#64748b' ?>"><?= $label ?></span>
  </div>
  <?php if ($n < 3): ?><i class="fa-solid fa-chevron-right" style="color:var(--muted);font-size:10px"></i><?php endif; ?>
  <?php endforeach; ?>
</div>

<?= $msg ?>

<?php if ($step === 1): ?>
<!-- ════════════════════════════════════════════════════════════════ -->
<!-- STEP 1: UPLOAD                                                     -->
<!-- ════════════════════════════════════════════════════════════════ -->
<div class="card" style="max-width:700px;margin:0 auto">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-cloud-arrow-up" style="color:#7c3aed"></i> Carica file CV</span>
  </div>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_cv">

    <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:12px;padding:30px;text-align:center;margin-bottom:14px">
      <i class="fa-solid fa-file-arrow-up" style="font-size:48px;color:#7c3aed;margin-bottom:12px;display:block"></i>
      <input type="file" name="cv_file" required accept=".docx,.pdf,.jpg,.jpeg,.png,.txt,.rtf"
             style="font-size:13px;width:100%;max-width:400px">
      <div style="font-size:11px;color:var(--muted);margin-top:10px">
        Formati: <strong>DOCX, PDF, JPG, PNG, TXT, RTF</strong> · Max 10 MB
      </div>
    </div>

    <div style="background:#eff6ff;border-left:3px solid #2563eb;padding:10px 14px;border-radius:6px;font-size:11px;color:#1e40af;margin-bottom:14px">
      <strong><i class="fa-solid fa-info-circle"></i> Compatibilità:</strong>
      <ul style="margin:6px 0 0 16px;line-height:1.7">
        <li><strong>DOCX</strong> — supporto nativo, massima precisione</li>
        <li><strong>PDF</strong> — supporto nativo PHP (FlateDecode + parser BT/ET), funziona su qualsiasi sistema. Opzionalmente <code>pdftotext</code> (poppler-utils) per PDF complessi con CMap personalizzate.</li>
        <li><strong>JPG/PNG</strong> — richiede <code>tesseract OCR</code> con lingua italiana installato sul server</li>
        <li>Migliore risultato con CV formato <strong>Europass</strong> strutturato (sezioni "Esperienza professionale", "Istruzione", "Competenze", "Lingue", "Certificazioni")</li>
      </ul>
    </div>

    <button type="submit" class="btn btn-primary" style="width:100%;padding:12px;font-size:14px;background:#7c3aed;border:0">
      <i class="fa-solid fa-magnifying-glass-chart"></i> Analizza CV e mostra anteprima
    </button>
  </form>
</div>

<?php elseif ($step === 2 && $parsed): ?>
<!-- ════════════════════════════════════════════════════════════════ -->
<!-- STEP 2: PREVIEW EDITABILE                                          -->
<!-- ════════════════════════════════════════════════════════════════ -->

<?php
  // Statistiche estrazione
  $extracted_count = 0;
  foreach (['first_name','last_name','email','phone','fiscal_code','date_of_birth','city_of_birth','address','linkedin_url','credly_url','job_title','bio','education_level','education_field','education_institute','education_year','technical_skills','soft_skills'] as $k) {
      if (!empty($parsed[$k])) $extracted_count++;
  }
?>

<div style="background:#ecfdf5;border-left:4px solid #16a34a;padding:12px 16px;border-radius:8px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div>
    <strong style="color:#166534"><i class="fa-solid fa-check-circle"></i> Estrazione completata</strong>
    <div style="font-size:12px;color:#166534;margin-top:3px">
      File: <code style="background:#fff;padding:1px 6px;border-radius:4px"><?= $h($parsed['_filename']) ?></code> ·
      <strong><?= $extracted_count ?>/18</strong> campi anagrafici ·
      <strong><?= count($parsed['languages'] ?? []) ?></strong> lingue ·
      <strong><?= count($parsed['experiences'] ?? []) ?></strong> esperienze ·
      <strong><?= count($parsed['certifications'] ?? []) ?></strong> certificazioni
    </div>
  </div>
  <a href="?step=1" class="btn btn-sm"><i class="fa-solid fa-rotate-left"></i> Carica un altro CV</a>
</div>

<form method="POST" id="saveForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_import">

  <!-- TARGET SELECTION -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-bullseye" style="color:#dc2626"></i> Cosa vuoi fare con questi dati?</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
      <label style="cursor:pointer">
        <input type="radio" name="target" value="candidate" checked style="display:none" onchange="updateTargetUI(this)">
        <div class="tgt-card" data-tgt="candidate" style="border:2px solid #7c3aed;background:#ede9fe;border-radius:10px;padding:12px;transition:.15s;height:100%">
          <div style="font-size:24px;color:#7c3aed"><i class="fa-solid fa-user-plus"></i></div>
          <div style="font-weight:800;font-size:13px;color:#5b21b6;margin-top:6px">Nuovo candidato</div>
          <div style="font-size:10px;color:var(--muted);margin-top:4px">Aggiungi alla pipeline recruiting come nuovo candidato</div>
        </div>
      </label>
      <label style="cursor:pointer">
        <input type="radio" name="target" value="employee" style="display:none" onchange="updateTargetUI(this)">
        <div class="tgt-card" data-tgt="employee" style="border:2px solid var(--border);background:#fafbfc;border-radius:10px;padding:12px;transition:.15s;height:100%">
          <div style="font-size:24px;color:#64748b"><i class="fa-solid fa-id-card"></i></div>
          <div style="font-weight:800;font-size:13px;color:#64748b;margin-top:6px">Nuovo dipendente</div>
          <div style="font-size:10px;color:var(--muted);margin-top:4px">Crea anagrafica completa dipendente con lingue, certificazioni e dati estratti</div>
        </div>
      </label>
      <label style="cursor:pointer">
        <input type="radio" name="target" value="update_employee" style="display:none" onchange="updateTargetUI(this)">
        <div class="tgt-card" data-tgt="update_employee" style="border:2px solid var(--border);background:#fafbfc;border-radius:10px;padding:12px;transition:.15s;height:100%">
          <div style="font-size:24px;color:#64748b"><i class="fa-solid fa-arrows-rotate"></i></div>
          <div style="font-weight:800;font-size:13px;color:#64748b;margin-top:6px">Aggiorna dipendente</div>
          <div style="font-size:10px;color:var(--muted);margin-top:4px">Aggiungi/aggiorna i dati nel record di un dipendente esistente</div>
        </div>
      </label>
    </div>

    <!-- Dropdown dipendente esistente (visibile solo se update_employee) -->
    <div id="empSelect" style="margin-top:12px;display:none">
      <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:4px">Dipendente da aggiornare</label>
      <select name="target_employee_id" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:13px">
        <option value="">— Seleziona dipendente —</option>
        <?php foreach ($employees as $e): ?>
        <option value="<?= $e['id'] ?>"><?= $h($e['full_name']) ?><?= $e['employee_code'] ? ' (' . $h($e['employee_code']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <div style="font-size:10px;color:var(--muted);margin-top:4px">
        <i class="fa-solid fa-info-circle"></i> Solo i campi non vuoti del CV sovrascriveranno i dati esistenti.
      </div>
    </div>
  </div>

  <!-- DATI ANAGRAFICI -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-id-card" style="color:var(--p)"></i> Anagrafica</span>
      <span style="font-size:11px;color:var(--muted)">Campi editabili — verifica e correggi prima di salvare</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:10px">
      <?php
      $fields = [
          'first_name'      => ['Nome *', 'text'],
          'last_name'       => ['Cognome *', 'text'],
          'email'           => ['Email', 'email'],
          'phone'           => ['Telefono', 'text'],
          'fiscal_code'     => ['Codice fiscale', 'text'],
          'date_of_birth'   => ['Data di nascita', 'date'],
          'city_of_birth'   => ['Luogo di nascita', 'text'],
          'address'         => ['Indirizzo', 'text'],
          'linkedin_url'    => ['LinkedIn', 'url'],
          'credly_url'      => ['Credly', 'url'],
          'job_title'       => ['Ruolo / Qualifica', 'text'],
      ];
      foreach ($fields as $k => [$label, $type]):
          $value = $parsed[$k] ?? '';
          $is_filled = !empty($value);
      ?>
      <div>
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">
          <?= $label ?>
          <?php if ($is_filled): ?><span style="color:#16a34a;text-transform:none;font-size:9px;font-weight:600">✓ rilevato</span><?php endif; ?>
        </label>
        <input type="<?= $type ?>" name="<?= $k ?>" value="<?= $h($value) ?>"
               style="width:100%;padding:7px 9px;border:1px solid <?= $is_filled ? '#86efac' : 'var(--border)' ?>;border-radius:5px;font-size:12px;background:<?= $is_filled ? '#f0fdf4' : '#fff' ?>;box-sizing:border-box">
      </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:14px">
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">
        Profilo professionale (bio)
        <?php if (!empty($parsed['bio'])): ?><span style="color:#16a34a;text-transform:none;font-size:9px;font-weight:600">✓ rilevato</span><?php endif; ?>
      </label>
      <textarea name="bio" rows="3"
                style="width:100%;padding:8px;border:1px solid <?= !empty($parsed['bio']) ? '#86efac' : 'var(--border)' ?>;border-radius:5px;font-size:12px;background:<?= !empty($parsed['bio']) ? '#f0fdf4' : '#fff' ?>;resize:vertical;box-sizing:border-box;font-family:inherit"><?= $h($parsed['bio'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- ISTRUZIONE -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-graduation-cap" style="color:#0ea5e9"></i> Istruzione e formazione</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:10px">
      <?php
      $edu_fields = [
          'education_level'     => 'Titolo conseguito',
          'education_field'     => 'Indirizzo / Facoltà',
          'education_institute' => 'Istituto / Università',
          'education_year'      => 'Anno conseguimento',
      ];
      foreach ($edu_fields as $k => $label):
          $value = $parsed[$k] ?? '';
          $is_filled = !empty($value);
      ?>
      <div>
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">
          <?= $label ?>
          <?php if ($is_filled): ?><span style="color:#16a34a;text-transform:none;font-size:9px;font-weight:600">✓</span><?php endif; ?>
        </label>
        <input type="text" name="<?= $k ?>" value="<?= $h($value) ?>"
               style="width:100%;padding:7px 9px;border:1px solid <?= $is_filled ? '#86efac' : 'var(--border)' ?>;border-radius:5px;font-size:12px;background:<?= $is_filled ? '#f0fdf4' : '#fff' ?>;box-sizing:border-box">
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- COMPETENZE -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-brain" style="color:#10b981"></i> Competenze</span>
    </div>
    <div style="margin-bottom:10px">
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">
        Competenze tecniche
        <?php if (!empty($parsed['technical_skills'])): ?><span style="color:#16a34a;text-transform:none;font-size:9px;font-weight:600">✓ rilevato</span><?php endif; ?>
      </label>
      <textarea name="technical_skills" rows="2"
                style="width:100%;padding:8px;border:1px solid <?= !empty($parsed['technical_skills']) ? '#86efac' : 'var(--border)' ?>;border-radius:5px;font-size:12px;background:<?= !empty($parsed['technical_skills']) ? '#f0fdf4' : '#fff' ?>;resize:vertical;box-sizing:border-box;font-family:inherit"><?= $h($parsed['technical_skills'] ?? '') ?></textarea>
    </div>
    <div>
      <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:3px">
        Soft skills
        <?php if (!empty($parsed['soft_skills'])): ?><span style="color:#16a34a;text-transform:none;font-size:9px;font-weight:600">✓ rilevato</span><?php endif; ?>
      </label>
      <textarea name="soft_skills" rows="2"
                style="width:100%;padding:8px;border:1px solid <?= !empty($parsed['soft_skills']) ? '#86efac' : 'var(--border)' ?>;border-radius:5px;font-size:12px;background:<?= !empty($parsed['soft_skills']) ? '#f0fdf4' : '#fff' ?>;resize:vertical;box-sizing:border-box;font-family:inherit"><?= $h($parsed['soft_skills'] ?? '') ?></textarea>
    </div>
  </div>

  <!-- LINGUE -->
  <?php if (!empty($parsed['languages'])): ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-language" style="color:#f59e0b"></i> Lingue (<?= count($parsed['languages']) ?>)</span>
      <span style="font-size:11px;color:var(--muted)">Salvate solo se target è "dipendente"</span>
    </div>
    <table class="data-table" style="font-size:11px">
      <thead><tr><th>Lingua</th><th>Madrelingua</th><th>Ascolto</th><th>Lettura</th><th>Interaz.</th><th>Prod. orale</th><th>Scrittura</th></tr></thead>
      <tbody>
      <?php foreach ($parsed['languages'] as $l): ?>
        <tr>
          <td><strong><?= $h($l['language_name']) ?></strong></td>
          <td><?= $l['mother_tongue'] ? '<span style="color:#16a34a;font-weight:700">SI</span>' : '—' ?></td>
          <td><?= $h($l['level_listening'] ?? '—') ?></td>
          <td><?= $h($l['level_reading'] ?? '—') ?></td>
          <td><?= $h($l['level_spoken_interaction'] ?? '—') ?></td>
          <td><?= $h($l['level_spoken_production'] ?? '—') ?></td>
          <td><?= $h($l['level_writing'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <input type="hidden" name="languages_json" value="<?= $h(json_encode($parsed['languages'], JSON_UNESCAPED_UNICODE)) ?>">
  <?php endif; ?>

  <!-- ESPERIENZE -->
  <?php if (!empty($parsed['experiences'])): ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-briefcase" style="color:#7c3aed"></i> Esperienze professionali (<?= count($parsed['experiences']) ?>)</span>
      <span style="font-size:11px;color:var(--muted)">Salvate in note del candidato / non importate come record separati</span>
    </div>
    <div style="font-size:12px">
      <?php foreach ($parsed['experiences'] as $exp): ?>
      <div style="border-left:3px solid #7c3aed;padding:8px 12px;margin-bottom:8px;background:#fafbfc">
        <div style="font-weight:700"><?= $h($exp['title'] ?? '—') ?></div>
        <div style="color:var(--muted);font-size:11px">
          <?php if (!empty($exp['period'])): ?><i class="fa-solid fa-calendar"></i> <?= $h($exp['period']) ?><?php endif; ?>
          <?php if (!empty($exp['company'])): ?> · <i class="fa-solid fa-building"></i> <?= $h($exp['company']) ?><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <input type="hidden" name="experiences_json" value="<?= $h(json_encode($parsed['experiences'], JSON_UNESCAPED_UNICODE)) ?>">
  <?php endif; ?>

  <!-- CERTIFICAZIONI -->
  <?php if (!empty($parsed['certifications'])): ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-certificate" style="color:#dc2626"></i> Certificazioni rilevate (<?= count($parsed['certifications']) ?>)</span>
      <span style="font-size:11px;color:var(--muted)">Match automatico col catalogo certificazioni del portale</span>
    </div>
    <table class="data-table" style="font-size:11px">
      <thead><tr><th>Nome</th><th>Brand</th><th>Anno</th></tr></thead>
      <tbody>
      <?php foreach ($parsed['certifications'] as $c): ?>
        <tr>
          <td><?= $h($c['name'] ?? '—') ?></td>
          <td><?php if ($c['brand']): ?><span class="badge"><?= $h($c['brand']) ?></span><?php else: ?>—<?php endif; ?></td>
          <td><?= $h($c['issue_year'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <input type="hidden" name="certifications_json" value="<?= $h(json_encode($parsed['certifications'], JSON_UNESCAPED_UNICODE)) ?>">
  <?php endif; ?>

  <!-- DEBUG: testo estratto (collapsable) -->
  <details style="margin-bottom:14px;font-size:11px">
    <summary style="cursor:pointer;color:var(--muted);font-weight:700">
      <i class="fa-solid fa-code"></i> Mostra testo estratto dal CV (<?= strlen($parsed['_raw_text']) ?> chars)
    </summary>
    <pre style="background:#1e293b;color:#cbd5e1;padding:12px;border-radius:6px;font-size:10px;max-height:300px;overflow:auto;margin-top:8px;white-space:pre-wrap;font-family:'Consolas',monospace"><?= $h($parsed['_raw_text']) ?></pre>
  </details>

  <!-- ACTIONS -->
  <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap">
    <a href="?step=1" class="btn btn-sm"><i class="fa-solid fa-xmark"></i> Annulla</a>
    <button type="submit" class="btn btn-primary" style="background:#16a34a;border:0;padding:11px 24px;font-weight:700">
      <i class="fa-solid fa-floppy-disk"></i> Conferma e salva in anagrafica
    </button>
  </div>
</form>

<script>
const targetColors = {
  candidate:       { color: '#7c3aed', bg: '#ede9fe' },
  employee:        { color: '#0ea5e9', bg: '#e0f2fe' },
  update_employee: { color: '#dc2626', bg: '#fee2e2' },
};
function updateTargetUI(radio) {
  document.querySelectorAll('.tgt-card').forEach(card => {
    const k = card.dataset.tgt;
    const c = targetColors[k] || targetColors.candidate;
    if (radio && radio.value === k) {
      card.style.borderColor = c.color;
      card.style.backgroundColor = c.bg;
    } else {
      card.style.borderColor = 'var(--border)';
      card.style.backgroundColor = '#fafbfc';
    }
  });
  document.getElementById('empSelect').style.display = (radio && radio.value === 'update_employee') ? 'block' : 'none';
}
</script>

<?php endif; ?>

<?php require_once('footer.php'); ?>
