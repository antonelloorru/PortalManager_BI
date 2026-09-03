<?php
/**
 * certV 2.2 — employee_profile.php
 * Dossier completo candidato: anagrafica, istruzione, contatti,
 * competenze, certificazioni acquisite, documenti allegati, inquadramento HR, storico
 */
require_once('access_control.php');
require_once(__DIR__ . '/app/RecycleBin.php');
require_once(__DIR__ . '/app/CostModel.php');
require_once('header.php');

$u_id     = (int)$_SESSION['user_id'];
$u_role   = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');
// v1.7.53: visibilità campi compensation/benefit
$can_compensation = can('view', 'manage_employees_compensation.php');
$can_hr   = can('delete');
$msg      = '';


$emp_id  = (int)($_GET['id'] ?? 0);
if (!$emp_id) { redirect('manage_employees'); }


// [PM_V1_9_27_APPLIED] Pre-fetch $emp per il branch POST che preserva i campi
// non modificati dal form (evita data-loss silenzioso). Il fetch principale
// piu' in basso sovrascrive $emp con la versione arricchita di JOIN per il render.
try {
    $__pm_pre = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $__pm_pre->execute([$emp_id]);
    $emp = $__pm_pre->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $__pm_e) {
    $emp = [];
}

// ── UPLOAD DOCUMENTI ──────────────────────────────────────────────────────────
$upload_base = APP_ROOT . '/uploads/cv_dipendenti/';
if (!is_dir($upload_base)) @mkdir($upload_base, 0755, true);

// v1.5.2: check più robusto - ammette anche ruoli admin/HR come fallback se can() fallisce
$_can_post = $can_edit || in_array((int)($_SESSION['role_id'] ?? 99), [1, 2], true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_can_post) {
    $action = $_POST['action'] ?? '';

    // ── Salva anagrafica ──────────────────────────────────────────────────────
    if ($action === 'save_anagrafica') {
        try {
            // Normalizza URL Credly/LinkedIn (accetta URL completo o username/vanity)
            $credly_in   = trim($_POST['credly_url']   ?? '');
            $linkedin_in = trim($_POST['linkedin_url'] ?? '');
            if ($credly_in && !preg_match('~^https?://~i', $credly_in)) {
                $credly_in = 'https://www.credly.com/users/' . ltrim($credly_in, '/');
            }
            if ($linkedin_in && !preg_match('~^https?://~i', $linkedin_in)) {
                $linkedin_in = 'https://www.linkedin.com/in/' . ltrim($linkedin_in, '/');
            }

            $pdo->prepare(
                "UPDATE employees SET
                    first_name=?, last_name=?, fiscal_code=?, date_of_birth=?, employee_code=?,
                    phone=?, phone_personal=?,
                    personal_email=?, business_email=?,
                    credly_url=?, linkedin_url=?,
                    education_level=?, education_field=?,
                    education_institute=?, education_year=?,
                    technical_skills=?, soft_skills=?,
                    bio=?, notes=?,
                    contract_type=?, agency=?, ccnl=?, qualification=?, contract_level=?,
                    part_time=?, part_time_pct=?, hire_date=?, end_date=?,
                    apprenticeship_end_date=?, gender=?,
                    badge_number=?, badge_issue_date=?
                 WHERE id=?"
            )->execute([
                trim($_POST['first_name']),
                trim($_POST['last_name']),
                trim($_POST['fiscal_code'] ?? '') ?: null,
                $_POST['date_of_birth'] ?: null,
                trim($_POST['employee_code'] ?? '') ?: null,
                trim($_POST['phone'] ?? '') ?: null,
                trim($_POST['phone_personal'] ?? '') ?: null,
                trim($_POST['personal_email'] ?? '') ?: null,
                trim($_POST['business_email'] ?? '') ?: null,
                $credly_in   ?: null,
                $linkedin_in ?: null,
                trim($_POST['education_level'] ?? '') ?: null,
                trim($_POST['education_field'] ?? '') ?: null,
                trim($_POST['education_institute'] ?? '') ?: null,
                trim($_POST['education_year'] ?? '') ?: null,
                trim($_POST['technical_skills'] ?? '') ?: null,
                isset($_POST['soft_check']) && is_array($_POST['soft_check']) ? implode(', ', $_POST['soft_check']) : (trim($_POST['soft_skills'] ?? '') ?: null),
                trim($_POST['bio'] ?? '') ?: null,
                $can_hr ? (trim($_POST['notes'] ?? '') ?: null) : ($emp['notes'] ?? null),
                // v1.8.36: campi inquadramento/badge ora gestiti nel tab Inquadramento HR — qui preservati per non azzerarli
                $emp['contract_type'] ?? 'Indeterminato',
                $emp['agency'] ?? null,
                $emp['ccnl'] ?? null,
                $emp['qualification'] ?? null,
                $emp['contract_level'] ?? null,
                (int)($emp['part_time'] ?? 0),
                (isset($emp['part_time_pct']) && $emp['part_time_pct'] !== '') ? $emp['part_time_pct'] : null,
                $emp['hire_date'] ?: null,
                $emp['end_date'] ?: null,
                $emp['apprenticeship_end_date'] ?: null,
                $emp['gender'] ?? null,
                $emp['badge_number'] ?? null,
                $emp['badge_issue_date'] ?: null,
                $emp_id
            ]);

            // v1.7.53: campi compensation - update separato se autorizzato
            if ($can_compensation && isset($_POST['ral'])) {
                $numf = fn($k) => is_numeric(str_replace(',', '.', (string)($_POST[$k] ?? ''))) ? (float)str_replace(',', '.', (string)$_POST[$k]) : null;
                $pdo->prepare("UPDATE employees SET
                    ral=?, premio_concordato=?, km_concordati=?, km_effettivi=?,
                    fuori_sede=?, fuori_sede_amount=?, classificazione_finanziaria=?,
                    moltiplicatore_fc=?, qt_trasferte_annue=?, qt_buoni_pasto=?, valore_tabp=?,
                    val_km=?, incentivazione_extra=?, valore_medio_anno_auto=?,
                    overhead_aziendale=?, moltiplicatore_fte=?
                  WHERE id=?")->execute([
                    $numf('ral'),
                    $numf('premio_concordato'),
                    is_numeric($_POST['km_concordati'] ?? null) ? (float)$_POST['km_concordati'] : null,
                    is_numeric($_POST['km_effettivi'] ?? null) ? (float)$_POST['km_effettivi'] : null,
                    !empty($_POST['fuori_sede']) ? 1 : 0,
                    $numf('fuori_sede_amount'),
                    in_array($_POST['classificazione_finanziaria'] ?? '', ['Diretto','Indiretto'], true) ? $_POST['classificazione_finanziaria'] : null,
                    // v1.7.93: costo pieno e FTE (vuoto = usa il riferimento globale)
                    $numf('moltiplicatore_fc'), $numf('qt_trasferte_annue'), $numf('qt_buoni_pasto'), $numf('valore_tabp'),
                    $numf('val_km'), $numf('incentivazione_extra'), $numf('valore_medio_anno_auto'),
                    $numf('overhead_aziendale'), $numf('moltiplicatore_fte'),
                    $emp_id,
                ]);
            }

            // ── Sync auto employee_credly_link / employee_linkedin_link
            if ($credly_in && preg_match('~credly\.com/users/([^/?#\s]+)~i', $credly_in, $cm)) {
                try {
                    $pdo->prepare(
                        "INSERT INTO employee_credly_link (employee_id, credly_username, created_by, created_at)
                         VALUES (?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE credly_username=VALUES(credly_username), updated_at=NOW()"
                    )->execute([$emp_id, $cm[1], $u_id]);
                } catch (Throwable $e) {}
            } elseif (!$credly_in) {
                try { $pdo->prepare("DELETE FROM employee_credly_link WHERE employee_id=?")->execute([$emp_id]); } catch (Throwable $e) {}
            }
            if ($linkedin_in && preg_match('~linkedin\.com/in/([^/?#\s]+)~i', $linkedin_in, $lm)) {
                try {
                    $pdo->prepare(
                        "INSERT INTO employee_linkedin_link (employee_id, linkedin_vanity, created_by, created_at)
                         VALUES (?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE linkedin_vanity=VALUES(linkedin_vanity), updated_at=NOW()"
                    )->execute([$emp_id, $lm[1], $u_id]);
                } catch (Throwable $e) {}
            } elseif (!$linkedin_in) {
                try { $pdo->prepare("DELETE FROM employee_linkedin_link WHERE employee_id=?")->execute([$emp_id]); } catch (Throwable $e) {}
            }
            write_log('Employees','success',"Aggiornato dipendente #$emp_id",$u_id);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Dati salvati.</div>";
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }

    // ── v1.7.11: LINGUE — Add/Update/Delete ─────────────────────────────────
    if ($action === 'lang_save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $params = [
                trim($_POST['language_name'] ?? ''),
                isset($_POST['mother_tongue']) ? 1 : 0,
                $_POST['level_listening'] ?: null,
                $_POST['level_reading'] ?: null,
                $_POST['level_spoken_interaction'] ?: null,
                $_POST['level_spoken_production'] ?: null,
                $_POST['level_writing'] ?: null,
                trim($_POST['certification'] ?? '') ?: null,
            ];
            if (empty($params[0])) throw new Exception('Nome lingua obbligatorio');
            if ($id > 0) {
                $params[] = $id; $params[] = $emp_id;
                $pdo->prepare("UPDATE emp_languages SET language_name=?, mother_tongue=?, level_listening=?,
                    level_reading=?, level_spoken_interaction=?, level_spoken_production=?, level_writing=?,
                    certification=? WHERE id=? AND employee_id=?")->execute($params);
            } else {
                array_unshift($params, $emp_id);
                $params[] = $u_id;
                $pdo->prepare("INSERT INTO emp_languages (employee_id, language_name, mother_tongue,
                    level_listening, level_reading, level_spoken_interaction, level_spoken_production,
                    level_writing, certification, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($params);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Lingua salvata.</div>";
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }
    if ($action === 'lang_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) RecycleBin::capture($pdo, 'emp_languages', 'id=? AND employee_id=?', [$id, $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Lingua rimossa.</div>";
    }

    // ── v1.7.11: TITOLI DI STUDIO ────────────────────────────────────────────
    if ($action === 'edu_save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $params = [
                trim($_POST['level'] ?? '') ?: null,
                trim($_POST['field'] ?? '') ?: null,
                trim($_POST['institute'] ?? '') ?: null,
                ($_POST['year_from'] ?? '') !== '' ? (int)$_POST['year_from'] : null,
                ($_POST['year_to'] ?? '') !== '' ? (int)$_POST['year_to'] : null,
                trim($_POST['grade'] ?? '') ?: null,
                isset($_POST['is_current']) ? 1 : 0,
                trim($_POST['notes'] ?? '') ?: null,
            ];
            if (empty($params[0]) && empty($params[1]) && empty($params[2])) {
                throw new Exception('Specificare almeno livello, campo o istituto');
            }
            if ($id > 0) {
                $params[] = $id; $params[] = $emp_id;
                $pdo->prepare("UPDATE emp_education SET level=?, field=?, institute=?, year_from=?, year_to=?,
                    grade=?, is_current=?, notes=? WHERE id=? AND employee_id=?")->execute($params);
            } else {
                array_unshift($params, $emp_id);
                $params[] = $u_id;
                $pdo->prepare("INSERT INTO emp_education (employee_id, level, field, institute, year_from, year_to,
                    grade, is_current, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($params);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Titolo salvato.</div>";
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }
    if ($action === 'edu_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) RecycleBin::capture($pdo, 'emp_education', 'id=? AND employee_id=?', [$id, $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Titolo rimosso.</div>";
    }

    // ── v1.7.11: ESPERIENZE LAVORATIVE ───────────────────────────────────────
    if ($action === 'exp_save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $is_current = isset($_POST['is_current']) ? 1 : 0;
            $params = [
                ($_POST['period_from'] ?? '') ?: null,
                $is_current ? null : (($_POST['period_to'] ?? '') ?: null),
                $is_current,
                trim($_POST['job_title'] ?? '') ?: null,
                trim($_POST['company'] ?? '') ?: null,
                trim($_POST['location'] ?? '') ?: null,
                trim($_POST['contract_type'] ?? '') ?: null,
                trim($_POST['description'] ?? '') ?: null,
            ];
            if (empty($params[3]) && empty($params[4])) {
                throw new Exception('Specificare almeno qualifica o azienda');
            }
            if ($id > 0) {
                $params[] = $id; $params[] = $emp_id;
                $pdo->prepare("UPDATE emp_experiences SET period_from=?, period_to=?, is_current=?, job_title=?,
                    company=?, location=?, contract_type=?, description=? WHERE id=? AND employee_id=?")->execute($params);
            } else {
                array_unshift($params, $emp_id);
                $params[] = $u_id;
                $pdo->prepare("INSERT INTO emp_experiences (employee_id, period_from, period_to, is_current,
                    job_title, company, location, contract_type, description, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($params);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Esperienza salvata.</div>";
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }
    if ($action === 'exp_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) RecycleBin::capture($pdo, 'emp_experiences', 'id=? AND employee_id=?', [$id, $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Esperienza rimossa.</div>";
    }

    // ── Upload documento ──────────────────────────────────────────────────────
    if ($action === 'upload_doc' && isset($_FILES['doc_file'])) {
        $doc_type = $_POST['doc_type'] ?? 'altro';
        $allowed_types = ['cv','test_psicologico','lettera_presentazione','altro'];
        if (!in_array($doc_type, $allowed_types)) $doc_type = 'altro';

        $file  = $_FILES['doc_file'];
        $ok_ext = ['pdf','doc','docx','jpg','jpeg','png'];
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file['error'] === UPLOAD_ERR_OK && in_array($ext, $ok_ext) && $file['size'] <= 10*1024*1024) {
            $fname = "cand_{$emp_id}_{$doc_type}_" . time() . ".$ext";
            $dest  = $upload_base . $fname;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                // Aggiorna campo corrispondente nel DB
                $col = match($doc_type) {
                    'cv'                 => 'cv_path',
                    'test_psicologico'   => 'test_path',
                    'lettera_presentazione' => 'lettera_path',
                    default              => 'doc_extra_path',
                };
                try {
                    $pdo->prepare("UPDATE employees SET $col=? WHERE id=?")->execute([$fname, $emp_id]);
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Documento caricato.</div>";
                } catch (Exception $e) {
                    $msg = "<div class='alert alert-warning'>File caricato ma colonna DB non trovata. Eseguire ALTER TABLE candidati.</div>";
                }
                write_log('Employees','success',"Upload $doc_type dipendente #$emp_id",$u_id);
            } else {
                $msg = "<div class='alert alert-danger'>Errore nel caricamento del file.</div>";
            }
        } else {
            $msg = "<div class='alert alert-danger'>File non valido. Formati: PDF, DOC, DOCX, JPG, PNG. Max 10MB.</div>";
        }
    }

    // ── Salva inquadramento HR (admin only) ──────────────────────────────────
    if ($action === 'save_inquadramento') {
        $co  = ((int)($_POST['company_id'] ?? 0)) ?: null;
        $loc = ((int)($_POST['location_id'] ?? 0)) ?: null;
        $wm  = ((int)($_POST['work_mode_id'] ?? 0)) ?: null;
        try {
            $pdo->prepare(
                "UPDATE employees SET
                    company_id=?, location_id=?, work_mode_id=?,
                    job_title=?, department=?, contract_type=?,
                    hire_date=?, end_date=?, status=?,
                    agency=?, ccnl=?, qualification=?, contract_level=?,
                    part_time=?, part_time_pct=?, apprenticeship_end_date=?, gender=?,
                    badge_number=?, badge_issue_date=?
                 WHERE id=?"
            )->execute([
                $co, $loc, $wm,
                trim($_POST['job_title']  ?? '') ?: null,
                trim($_POST['department'] ?? '') ?: null,
                $_POST['contract_type']   ?? 'Indeterminato',
                $_POST['hire_date'] ?: null,
                $_POST['end_date']  ?: null,
                $_POST['status']    ?? 'active',
                trim($_POST['agency'] ?? '') ?: null,
                trim($_POST['ccnl'] ?? '') ?: null,
                trim($_POST['qualification'] ?? '') ?: null,
                trim($_POST['contract_level'] ?? '') ?: null,
                !empty($_POST['part_time']) ? 1 : 0,
                is_numeric($_POST['part_time_pct'] ?? null) ? (float)$_POST['part_time_pct'] : null,
                $_POST['apprenticeship_end_date'] ?: null,
                in_array($_POST['gender'] ?? '', ['M','F','altro'], true) ? $_POST['gender'] : null,
                trim($_POST['badge_number'] ?? '') ?: null,
                $_POST['badge_issue_date'] ?: null,
                $emp_id
            ]);
            // v1.7.58: Dipartimento da lookup (department_id + sync testo department)
            $department_id = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
            $dep_name = null;
            if ($department_id !== null) {
                $dchk = $pdo->prepare("SELECT name FROM departments WHERE id=? AND is_active=1");
                $dchk->execute([$department_id]);
                $dep_name = $dchk->fetchColumn();
                if ($dep_name === false) { $department_id = null; $dep_name = null; }
            }
            $subcategory_id = ($_POST['subcategory_id'] ?? '') !== '' ? (int)$_POST['subcategory_id'] : null;
            if ($subcategory_id !== null && $department_id !== null) {
                $scchk = $pdo->prepare("SELECT id FROM department_subcategories WHERE id=? AND department_id=? AND is_active=1");
                $scchk->execute([$subcategory_id, (int)$department_id]);
                if ($scchk->fetchColumn() === false) $subcategory_id = null;
            } else { $subcategory_id = null; }
            $pdo->prepare("UPDATE employees SET department_id=?, subcategory_id=?, department=? WHERE id=?")
                ->execute([$department_id, $subcategory_id, $dep_name, $emp_id]);
            write_log('Employees','success',"Inquadramento HR aggiornato #$emp_id",$u_id);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Inquadramento HR aggiornato.</div>";
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    } // v1.5.4 FIX BUG CRITICO: chiusura mancante if($action==='save_inquadramento')
      // Senza questa graffa, TUTTO il blocco handler dispositivi era annidato
      // dentro un if che era SEMPRE false per le action di tipo save_phone/sim/etc.
      // Questo spiega perché i dispositivi non venivano mai salvati.

    // ════════════════════════════════════════════════════════════════════
    // POST HANDLERS DISPOSITIVI (v1.5.0)
    // ════════════════════════════════════════════════════════════════════
    $dev_upload_dir = APP_ROOT . '/uploads/devices/';
    if (!is_dir($dev_upload_dir)) @mkdir($dev_upload_dir, 0755, true);

    $_save_dev_file = function($field, $prefix) use ($dev_upload_dir) {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
        if ($_FILES[$field]['size'] > 10 * 1024 * 1024) return null;
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf','jpg','jpeg','png','doc','docx','xlsx'], true)) return null;
        $fname = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES[$field]['tmp_name'], $dev_upload_dir . $fname)) return $fname;
        return null;
    };

    // v1.5.1: wrap tutti i handler dispositivi in try/catch per errori visibili
    try {

    // ── 1. Telefono aziendale ────────────────────────────────────────────
    if ($action === 'save_phone' && $_can_post) {
        $dev_id = (int)($_POST['device_id'] ?? 0);
        $data = [
            trim($_POST['brand']         ?? '') ?: null,
            trim($_POST['model']         ?? '') ?: null,
            trim($_POST['imei_1']        ?? '') ?: null,
            trim($_POST['imei_2']        ?? '') ?: null,
            trim($_POST['serial_number'] ?? '') ?: null,
            $_POST['assigned_at'] ?: null,
            $_POST['returned_at'] ?: null,
            $_POST['status'] ?? 'assegnato',
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($dev_id > 0) {
            $pdo->prepare("UPDATE emp_devices_phone SET brand=?,model=?,imei_1=?,imei_2=?,serial_number=?,assigned_at=?,returned_at=?,status=?,notes=? WHERE id=? AND employee_id=?")
                ->execute([...$data, $dev_id, $emp_id]);
        } else {
            $pdo->prepare("INSERT INTO emp_devices_phone (brand,model,imei_1,imei_2,serial_number,assigned_at,returned_at,status,notes,employee_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([...$data, $emp_id, $u_id]);
        }
        write_log('Devices','success',"Telefono salvato emp=$emp_id",$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Telefono salvato.</div>";
    }
    elseif ($action === 'delete_phone' && $_can_post) {
        RecycleBin::capture($pdo, 'emp_devices_phone', 'id=? AND employee_id=?', [(int)$_POST['device_id'], $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Telefono rimosso.</div>";
    }

    // ── 2. SIM ──────────────────────────────────────────────────────────
    elseif ($action === 'save_sim' && $_can_post) {
        $dev_id = (int)($_POST['device_id'] ?? 0);
        $data = [
            $_POST['sim_type'] ?? 'voce',
            trim($_POST['phone_number']  ?? '') ?: null,
            trim($_POST['serial_number'] ?? '') ?: null,
            trim($_POST['pin_code']      ?? '') ?: null,
            trim($_POST['puk_code']      ?? '') ?: null,
            trim($_POST['operator']      ?? '') ?: null,
            $_POST['assigned_at'] ?: null,
            $_POST['returned_at'] ?: null,
            $_POST['status'] ?? 'attiva',
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($dev_id > 0) {
            $pdo->prepare("UPDATE emp_devices_sim SET sim_type=?,phone_number=?,serial_number=?,pin_code=?,puk_code=?,operator=?,assigned_at=?,returned_at=?,status=?,notes=? WHERE id=? AND employee_id=?")
                ->execute([...$data, $dev_id, $emp_id]);
        } else {
            $pdo->prepare("INSERT INTO emp_devices_sim (sim_type,phone_number,serial_number,pin_code,puk_code,operator,assigned_at,returned_at,status,notes,employee_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([...$data, $emp_id, $u_id]);
        }
        write_log('Devices','success',"SIM salvata emp=$emp_id",$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> SIM salvata.</div>";
    }
    elseif ($action === 'delete_sim' && $_can_post) {
        RecycleBin::capture($pdo, 'emp_devices_sim', 'id=? AND employee_id=?', [(int)$_POST['device_id'], $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> SIM rimossa.</div>";
    }

    // ── 3. Notebook ─────────────────────────────────────────────────────
    elseif ($action === 'save_notebook' && $_can_post) {
        $dev_id = (int)($_POST['device_id'] ?? 0);
        $data = [
            trim($_POST['brand']         ?? '') ?: null,
            trim($_POST['model']         ?? '') ?: null,
            trim($_POST['serial_number'] ?? '') ?: null,
            trim($_POST['specs']         ?? '') ?: null,
            trim($_POST['os']            ?? '') ?: null,
            $_POST['assigned_at'] ?: null,
            $_POST['returned_at'] ?: null,
            $_POST['status'] ?? 'assegnato',
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($dev_id > 0) {
            $pdo->prepare("UPDATE emp_devices_notebook SET brand=?,model=?,serial_number=?,specs=?,os=?,assigned_at=?,returned_at=?,status=?,notes=? WHERE id=? AND employee_id=?")
                ->execute([...$data, $dev_id, $emp_id]);
        } else {
            $pdo->prepare("INSERT INTO emp_devices_notebook (brand,model,serial_number,specs,os,assigned_at,returned_at,status,notes,employee_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([...$data, $emp_id, $u_id]);
        }
        write_log('Devices','success',"Notebook salvato emp=$emp_id",$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Notebook salvato.</div>";
    }
    elseif ($action === 'delete_notebook' && $_can_post) {
        RecycleBin::capture($pdo, 'emp_devices_notebook', 'id=? AND employee_id=?', [(int)$_POST['device_id'], $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Notebook rimosso.</div>";
    }

    // ── 4. Veicolo ──────────────────────────────────────────────────────
    elseif ($action === 'save_vehicle' && $_can_post) {
        $dev_id = (int)($_POST['device_id'] ?? 0);
        $data = [
            trim($_POST['brand']  ?? '') ?: null,
            trim($_POST['model']  ?? '') ?: null,
            trim($_POST['plate']  ?? '') ?: null,
            trim($_POST['fuel_type'] ?? '') ?: null,
            $_POST['acquisition_type'] ?? 'noleggio',
            trim($_POST['contract_ref'] ?? '') ?: null,
            $_POST['contract_start'] ?: null,
            $_POST['contract_end']   ?: null,
            ($_POST['monthly_cost'] !== '' ? (float)$_POST['monthly_cost'] : null),
            trim($_POST['conditions'] ?? '') ?: null,
            ($_POST['initial_km'] !== '' ? (int)$_POST['initial_km'] : null),
            ($_POST['current_km'] !== '' ? (int)$_POST['current_km'] : null),
            $_POST['assigned_at'] ?: null,
            $_POST['returned_at'] ?: null,
            $_POST['status'] ?? 'assegnato',
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($dev_id > 0) {
            $pdo->prepare("UPDATE emp_devices_vehicle SET brand=?,model=?,plate=?,fuel_type=?,acquisition_type=?,contract_ref=?,contract_start=?,contract_end=?,monthly_cost=?,conditions=?,initial_km=?,current_km=?,assigned_at=?,returned_at=?,status=?,notes=? WHERE id=? AND employee_id=?")
                ->execute([...$data, $dev_id, $emp_id]);
        } else {
            $pdo->prepare("INSERT INTO emp_devices_vehicle (brand,model,plate,fuel_type,acquisition_type,contract_ref,contract_start,contract_end,monthly_cost,conditions,initial_km,current_km,assigned_at,returned_at,status,notes,employee_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([...$data, $emp_id, $u_id]);
        }
        write_log('Devices','success',"Veicolo salvato emp=$emp_id",$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Veicolo salvato.</div>";
    }
    elseif ($action === 'delete_vehicle' && $_can_post) {
        RecycleBin::capture($pdo, 'emp_devices_vehicle', 'id=? AND employee_id=?', [(int)$_POST['device_id'], $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Veicolo rimosso.</div>";
    }

    // ── 4b. Tagliando veicolo ──────────────────────────────────────────
    elseif ($action === 'add_service' && $_can_post) {
        $vid = (int)$_POST['vehicle_id'];
        $doc = $_save_dev_file('service_doc', "vehicle_{$vid}_service");
        $pdo->prepare("INSERT INTO emp_vehicle_service (vehicle_id,service_date,km,cost,description,document_path,created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([
                $vid,
                $_POST['service_date'] ?: date('Y-m-d'),
                ($_POST['km'] !== '' ? (int)$_POST['km'] : null),
                ($_POST['cost'] !== '' ? (float)$_POST['cost'] : null),
                trim($_POST['description'] ?? '') ?: null,
                $doc,
                $u_id,
            ]);
        // Aggiorna km correnti veicolo se km nel tagliando > current_km
        if (!empty($_POST['km'])) {
            $pdo->prepare("UPDATE emp_devices_vehicle SET current_km=GREATEST(COALESCE(current_km,0), ?) WHERE id=?")
                ->execute([(int)$_POST['km'], $vid]);
        }
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Tagliando registrato.</div>";
    }
    elseif ($action === 'delete_service' && $_can_post) {
        $sid = (int)$_POST['service_id'];
        $s = $pdo->prepare("SELECT document_path FROM emp_vehicle_service WHERE id=?");
        $s->execute([$sid]);
        if ($r = $s->fetch()) {
            if ($r['document_path'] && is_file($dev_upload_dir . $r['document_path'])) @unlink($dev_upload_dir . $r['document_path']);
        }
        RecycleBin::capture($pdo, 'emp_vehicle_service', 'id=?', [$sid], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Tagliando rimosso.</div>";
    }

    // ── 5. Carta carburante ─────────────────────────────────────────────
    elseif ($action === 'save_fuel_card' && $_can_post) {
        $dev_id = (int)($_POST['device_id'] ?? 0);
        $data = [
            trim($_POST['circuit']     ?? '') ?: null,
            trim($_POST['card_number'] ?? '') ?: null,
            trim($_POST['pin_code']    ?? '') ?: null,
            ((int)($_POST['vehicle_id'] ?? 0)) ?: null,
            $_POST['assigned_at'] ?: null,
            $_POST['returned_at'] ?: null,
            $_POST['status'] ?? 'attiva',
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($dev_id > 0) {
            $pdo->prepare("UPDATE emp_devices_fuel_card SET circuit=?,card_number=?,pin_code=?,vehicle_id=?,assigned_at=?,returned_at=?,status=?,notes=? WHERE id=? AND employee_id=?")
                ->execute([...$data, $dev_id, $emp_id]);
        } else {
            $pdo->prepare("INSERT INTO emp_devices_fuel_card (circuit,card_number,pin_code,vehicle_id,assigned_at,returned_at,status,notes,employee_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([...$data, $emp_id, $u_id]);
        }
        write_log('Devices','success',"Carta carburante salvata emp=$emp_id",$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Carta carburante salvata.</div>";
    }
    elseif ($action === 'delete_fuel_card' && $_can_post) {
        RecycleBin::capture($pdo, 'emp_devices_fuel_card', 'id=? AND employee_id=?', [(int)$_POST['device_id'], $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Carta rimossa.</div>";
    }

    // ── 5b. Rifornimento ───────────────────────────────────────────────
    elseif ($action === 'add_fuel_log' && $_can_post) {
        $fid = (int)$_POST['fuel_card_id'];
        $doc = $_save_dev_file('fuel_doc', "fuelcard_{$fid}_refuel");
        $pdo->prepare("INSERT INTO emp_fuel_log (fuel_card_id,refuel_date,km,liters,amount,location,document_path,created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([
                $fid,
                $_POST['refuel_date'] ?: date('Y-m-d'),
                ($_POST['km']     !== '' ? (int)$_POST['km'] : null),
                ($_POST['liters'] !== '' ? (float)$_POST['liters'] : null),
                ($_POST['amount'] !== '' ? (float)$_POST['amount'] : null),
                trim($_POST['location'] ?? '') ?: null,
                $doc,
                $u_id,
            ]);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Rifornimento registrato.</div>";
    }
    elseif ($action === 'delete_fuel_log' && $_can_post) {
        $rid = (int)$_POST['log_id'];
        $s = $pdo->prepare("SELECT document_path FROM emp_fuel_log WHERE id=?");
        $s->execute([$rid]);
        if ($r = $s->fetch()) {
            if ($r['document_path'] && is_file($dev_upload_dir . $r['document_path'])) @unlink($dev_upload_dir . $r['document_path']);
        }
        RecycleBin::capture($pdo, 'emp_fuel_log', 'id=?', [$rid], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Rifornimento rimosso.</div>";
    }

    // ── 6. Carta credito ────────────────────────────────────────────────
    elseif ($action === 'save_credit_card' && $_can_post) {
        $dev_id = (int)($_POST['device_id'] ?? 0);
        $data = [
            trim($_POST['circuit']            ?? '') ?: null,
            trim($_POST['card_number_last4']  ?? '') ?: null,
            trim($_POST['pin_code']           ?? '') ?: null,
            trim($_POST['bank']               ?? '') ?: null,
            ($_POST['credit_limit'] !== '' ? (float)$_POST['credit_limit'] : null),
            $_POST['assigned_at'] ?: null,
            $_POST['returned_at'] ?: null,
            $_POST['status'] ?? 'attiva',
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($dev_id > 0) {
            $pdo->prepare("UPDATE emp_devices_credit_card SET circuit=?,card_number_last4=?,pin_code=?,bank=?,credit_limit=?,assigned_at=?,returned_at=?,status=?,notes=? WHERE id=? AND employee_id=?")
                ->execute([...$data, $dev_id, $emp_id]);
        } else {
            $pdo->prepare("INSERT INTO emp_devices_credit_card (circuit,card_number_last4,pin_code,bank,credit_limit,assigned_at,returned_at,status,notes,employee_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([...$data, $emp_id, $u_id]);
        }
        write_log('Devices','success',"Carta credito salvata emp=$emp_id",$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Carta credito salvata.</div>";
    }
    elseif ($action === 'delete_credit_card' && $_can_post) {
        RecycleBin::capture($pdo, 'emp_devices_credit_card', 'id=? AND employee_id=?', [(int)$_POST['device_id'], $emp_id], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Carta rimossa.</div>";
    }

    // ── 6b. Estratto conto ─────────────────────────────────────────────
    elseif ($action === 'add_cc_statement' && $_can_post) {
        $cid = (int)$_POST['credit_card_id'];
        $doc = $_save_dev_file('statement_doc', "creditcard_{$cid}_statement");
        try {
            $pdo->prepare("INSERT INTO emp_credit_card_statement (credit_card_id,period_year,period_month,total_amount,document_path,notes,created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    $cid,
                    (int)$_POST['period_year'],
                    (int)$_POST['period_month'],
                    ($_POST['total_amount'] !== '' ? (float)$_POST['total_amount'] : null),
                    $doc,
                    trim($_POST['notes'] ?? '') ?: null,
                    $u_id,
                ]);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Estratto conto registrato.</div>";
        } catch (PDOException $e) {
            $msg = "<div class='alert alert-danger'>Periodo già presente per questa carta.</div>";
        }
    }
    elseif ($action === 'delete_cc_statement' && $_can_post) {
        $sid = (int)$_POST['statement_id'];
        $s = $pdo->prepare("SELECT document_path FROM emp_credit_card_statement WHERE id=?");
        $s->execute([$sid]);
        if ($r = $s->fetch()) {
            if ($r['document_path'] && is_file($dev_upload_dir . $r['document_path'])) @unlink($dev_upload_dir . $r['document_path']);
        }
        RecycleBin::capture($pdo, 'emp_credit_card_statement', 'id=?', [$sid], $u_id, 'employee_profile.php');
        $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Estratto rimosso.</div>";
    }

    } catch (Throwable $_dev_e) {
        // v1.5.1: ERRORE VISIBILE
        $msg = "<div class='alert alert-danger'>"
             . "<i class='fa-solid fa-triangle-exclamation'></i> "
             . "<strong>Errore dispositivo:</strong> " . h($_dev_e->getMessage())
             . "<br><small style='opacity:.7'>action=<code>" . h($action) . "</code> "
             . h(basename($_dev_e->getFile())) . ":" . $_dev_e->getLine() . "</small>"
             . "</div>";
        if (function_exists('write_log')) {
            write_log('Devices', 'error', "$action emp=$emp_id: " . $_dev_e->getMessage(), $u_id);
        }
    }
}

// ── LEGGI DATI DIPENDENTE ─────────────────────────────────────────────────────
$cq = $pdo->prepare(
    "SELECT e.*,
            co.name AS company_name,
            loc.location_name, loc.address AS loc_address,
            wm.name AS mode_name, wm.color_hex AS mode_color,
            u.id AS user_id, u.email AS user_email, r.name AS role_name
       FROM employees e
       LEFT JOIN companies co          ON e.company_id   = co.id
       LEFT JOIN company_locations loc ON e.location_id  = loc.id
       LEFT JOIN work_modes wm         ON e.work_mode_id = wm.id
       LEFT JOIN users u               ON u.employee_id  = e.id AND u.status = 'active'
       LEFT JOIN roles r               ON u.role_id      = r.id
      WHERE e.id = ?"
);
$cq->execute([$emp_id]);
$emp = $cq->fetch();
if (!$emp) { redirect('manage_employees'); }

// v1.7.53: filtro defensive dati sensibili se non autorizzato
if (!$can_compensation) {
    foreach (['ral','premio_concordato','km_concordati','km_effettivi','fuori_sede','fuori_sede_amount','classificazione_finanziaria',
              'moltiplicatore_fc','qt_trasferte_annue','qt_buoni_pasto','valore_tabp','val_km',
              'incentivazione_extra','valore_medio_anno_auto','overhead_aziendale','moltiplicatore_fte'] as $sf) {
        unset($emp[$sf]);
    }
}

// Certificazioni acquisite dal dipendente
$cs_q = $pdo->prepare(
    "SELECT uc.*, c.name AS cert_name, c.code AS cert_code, b.name AS brand_name
       FROM user_certifications uc
       JOIN certifications c ON uc.certification_id = c.id
       JOIN brands b ON c.brand_id = b.id
      WHERE uc.employee_id = ?
      ORDER BY (uc.expiry_date IS NULL), uc.expiry_date DESC, uc.issue_date DESC"
);
$cs_q->execute([$emp_id]);
$certs = $cs_q->fetchAll();

$tot_credly = 0; $tot_linkedin = 0;
foreach ($certs as $c) {
    if (!empty($c['notes']) && strpos($c['notes'], 'credly_badge_id:') !== false) $tot_credly++;
    elseif (!empty($c['notes']) && strpos($c['notes'], 'Importato da LinkedIn') !== false) $tot_linkedin++;
}

// Lookups per inquadramento HR
$companies     = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
// v1.7.58: lookup dipartimenti (solo attivi) per la select Inquadramento HR
$departments = [];
try { $departments = $pdo->query("SELECT id, name, value_type FROM departments WHERE is_active=1 ORDER BY name")->fetchAll(); }
catch (\Exception $e) { }
$subcategories = [];
try { $subcategories = $pdo->query("SELECT s.id, s.department_id, s.name, COALESCE(s.value_type, d.value_type) AS value_type FROM department_subcategories s JOIN departments d ON d.id=s.department_id WHERE s.is_active=1 ORDER BY s.name")->fetchAll(); }
catch (\Exception $e) { /* tabella non ancora creata (migration pendente) */ }
$work_modes    = $pdo->query("SELECT id, name, color_hex FROM work_modes ORDER BY name")->fetchAll();
$all_locations = $pdo->query("SELECT id, location_name, company_id FROM company_locations ORDER BY location_name")->fetchAll();

// Storico modifiche da entity_change_log
$history = [];
try {
    $hq = $pdo->prepare(
        "SELECT ecl.*, CONCAT(COALESCE(eu.first_name,''),' ',COALESCE(eu.last_name,'')) AS by_name
           FROM entity_change_log ecl
           LEFT JOIN users uh ON uh.id = ecl.changed_by
           LEFT JOIN employees eu ON eu.id = uh.employee_id
          WHERE ecl.table_name = 'employees' AND ecl.record_id = ?
          ORDER BY ecl.changed_at DESC LIMIT 50"
    );
    $hq->execute([$emp_id]);
    $history = $hq->fetchAll();
} catch (Throwable $e) {}

// ── DISPOSITIVI (v1.5.0) ────────────────────────────────────────────────
$dev_phones    = [];
$dev_sims      = [];
$dev_notebooks = [];
$dev_vehicles  = [];
$dev_fuel_cards = [];
$dev_credit_cards = [];
$dev_services  = [];   // per vehicle_id
$dev_fuel_logs = [];   // per fuel_card_id
$dev_cc_stmts  = [];   // per credit_card_id
try {
    $q = $pdo->prepare("SELECT * FROM emp_devices_phone WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC, id DESC");
    $q->execute([$emp_id]); $dev_phones = $q->fetchAll();

    $q = $pdo->prepare("SELECT * FROM emp_devices_sim WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, sim_type ASC, assigned_at DESC");
    $q->execute([$emp_id]); $dev_sims = $q->fetchAll();

    $q = $pdo->prepare("SELECT * FROM emp_devices_notebook WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC, id DESC");
    $q->execute([$emp_id]); $dev_notebooks = $q->fetchAll();

    $q = $pdo->prepare("SELECT * FROM emp_devices_vehicle WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC, id DESC");
    $q->execute([$emp_id]); $dev_vehicles = $q->fetchAll();

    $q = $pdo->prepare("SELECT fc.*, v.brand AS veh_brand, v.model AS veh_model, v.plate
                          FROM emp_devices_fuel_card fc
                          LEFT JOIN emp_devices_vehicle v ON v.id = fc.vehicle_id
                         WHERE fc.employee_id=?
                         ORDER BY (fc.returned_at IS NULL) DESC, fc.assigned_at DESC, fc.id DESC");
    $q->execute([$emp_id]); $dev_fuel_cards = $q->fetchAll();

    $q = $pdo->prepare("SELECT * FROM emp_devices_credit_card WHERE employee_id=? ORDER BY (returned_at IS NULL) DESC, assigned_at DESC, id DESC");
    $q->execute([$emp_id]); $dev_credit_cards = $q->fetchAll();

    // Sub-eventi: indicizzati per parent_id
    if ($dev_vehicles) {
        $ids = array_column($dev_vehicles, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT * FROM emp_vehicle_service WHERE vehicle_id IN ($ph) ORDER BY service_date DESC, id DESC");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) $dev_services[$r['vehicle_id']][] = $r;
    }
    if ($dev_fuel_cards) {
        $ids = array_column($dev_fuel_cards, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT * FROM emp_fuel_log WHERE fuel_card_id IN ($ph) ORDER BY refuel_date DESC, id DESC");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) $dev_fuel_logs[$r['fuel_card_id']][] = $r;
    }
    if ($dev_credit_cards) {
        $ids = array_column($dev_credit_cards, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT * FROM emp_credit_card_statement WHERE credit_card_id IN ($ph) ORDER BY period_year DESC, period_month DESC");
        $q->execute($ids);
        foreach ($q->fetchAll() as $r) $dev_cc_stmts[$r['credit_card_id']][] = $r;
    }
} catch (Throwable $e) {
    // Tabelle non ancora create (migration v1.5.0 non applicata) - ignora
}

$active_tab = $_GET['tab'] ?? 'anagrafica';

// Status badge per dipendente
$status_meta = [
    'active'     => ['Attivo',  '#d1fae5', '#065f46'],
    'inactive'   => ['Inattivo','#f1f5f9', '#475569'],
    'terminated' => ['Cessato', '#fee2e2', '#991b1b'],
];

// Documento helper
function doc_link(string $fname, string $label): string {
    if (!$fname) return '<span style="color:#94a3b8;font-size:12px">—</span>';
    return '<a href="download.php?file='.urlencode('cv_dipendenti/'.$fname).'" target="_blank"
               style="display:inline-flex;align-items:center;gap:5px;color:#0369a1;font-size:12px;text-decoration:none;background:#e0f2fe;padding:3px 9px;border-radius:6px">
               <i class="fa-solid fa-file-arrow-down"></i> '.h($label).'</a>';
}
?>

<!-- HEADER CANDIDATO -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div style="display:flex;align-items:center;gap:16px">
    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--p),#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:800;flex-shrink:0">
      <?=strtoupper(substr($emp['first_name'],0,1).substr($emp['last_name'],0,1))?>
    </div>
    <div>
      <h1 style="font-size:20px;font-weight:800;margin-bottom:4px">
        <?=h($emp['first_name'].' '.$emp['last_name'])?>
      </h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if($emp['business_email']): ?>
        <a href="mailto:<?=h($emp['business_email'])?>" style="font-size:12px;color:var(--p)"><i class="fa-solid fa-envelope"></i> <?=h($emp['business_email'])?></a>
        <?php elseif($emp['user_email']): ?>
        <a href="mailto:<?=h($emp['user_email'])?>" style="font-size:12px;color:var(--p)"><i class="fa-solid fa-envelope"></i> <?=h($emp['user_email'])?></a>
        <?php endif; ?>
        <?php if($emp['phone']): ?>
        <span style="font-size:12px;color:var(--muted)"><i class="fa-solid fa-phone"></i> <?=h($emp['phone'])?></span>
        <?php endif; ?>
        <?php if($emp['linkedin_url']): ?>
        <a href="<?=h($emp['linkedin_url'])?>" target="_blank" style="font-size:12px;color:#0077b5"><i class="fa-brands fa-linkedin"></i> LinkedIn</a>
        <?php endif; ?>
        <?php if($emp['credly_url']): ?>
        <a href="<?=h($emp['credly_url'])?>" target="_blank" style="font-size:12px;color:#7c3aed"><i class="fa-solid fa-shield-halved"></i> Credly</a>
        <?php endif; ?>
        <?php
          [$st_lbl,$st_bg,$st_col] = $status_meta[$emp['status']] ?? ['?', '#f1f5f9','#475569'];
        ?>
        <span style="background:<?=$st_bg?>;color:<?=$st_col?>;padding:2px 10px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase"><?=$st_lbl?></span>
        <?php if($emp['employee_code']): ?>
        <span class="badge badge-neutral" style="font-size:9px">#<?=h($emp['employee_code'])?></span>
        <?php endif; ?>
        <?php if($emp['job_title']): ?>
        <span class="badge badge-neutral" style="font-size:9px"><?=h($emp['job_title'])?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="manage_employees.php" class="btn btn-sm"><i class="fa-solid fa-arrow-left"></i> Anagrafica</a>
    <a href="employee_cv.php?id=<?= $emp_id ?>" class="btn btn-sm" style="background:#1e40af;color:#fff" title="Genera CV Europass">
      <i class="fa-solid fa-file-word"></i> Genera CV
    </a>
    <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i></button>
  </div>
</div>

<?=$msg?>

<!-- TAB NAV -->
<div class="no-print" style="display:flex;gap:2px;margin-bottom:22px;background:#f1f5f9;border-radius:10px;padding:4px;overflow-x:auto">
  <?php foreach([
    ['anagrafica',     'fa-id-card',            'Anagrafica'],
    ['background',     'fa-graduation-cap',     'Background'],
    ['inquadramento',  'fa-briefcase',          'Inquadramento HR'],
    ['competenze',     'fa-brain',              'Competenze'],
    ['documenti',      'fa-folder-open',        'Documenti'],
    ['dispositivi',    'fa-laptop-mobile',      'Dispositivi'],
    ['storico',        'fa-clock-rotate-left',  'Storico'],
  ] as [$tab,$icon,$label]): ?>
  <a href="<?= qs_self_safe(['id'=>''.($emp_id).'', 'tab'=>''.($tab).'']) ?>"
     style="flex:1;text-align:center;padding:8px 14px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;white-space:nowrap;
            <?=$active_tab===$tab?'background:#fff;color:var(--p);box-shadow:0 1px 3px rgba(0,0,0,.08)':'color:var(--muted)'?>">
    <i class="fa-solid <?=$icon?>" style="margin-right:5px"></i><?=$label?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ══ TAB: ANAGRAFICA ════════════════════════════════════════════════════════ -->
<?php if($active_tab === 'anagrafica'): ?>
<form method="POST">
        <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_anagrafica">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Dati personali -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-user" style="color:var(--p)"></i> Dati personali</span></div>
      <div class="grid-2">
        <div class="form-group"><label>Nome *</label><input type="text" name="first_name" value="<?=h($emp['first_name'])?>" required></div>
        <div class="form-group"><label>Cognome *</label><input type="text" name="last_name" value="<?=h($emp['last_name'])?>" required></div>
        <div class="form-group"><label>Email aziendale</label><input type="email" name="business_email" value="<?=h($emp['business_email']??'')?>" placeholder="nome.cognome@azienda.it"></div>
        <div class="form-group"><label>Telefono aziendale</label><input type="tel" name="phone" value="<?=h($emp['phone']??'')?>"></div>
        <div class="form-group"><label>Email personale</label><input type="email" name="personal_email" value="<?=h($emp['personal_email']??'')?>" placeholder="nome@gmail.com"></div>
        <div class="form-group"><label>Telefono personale</label><input type="tel" name="phone_personal" value="<?=h($emp['phone_personal']??'')?>"></div>
        <div class="form-group span-2"><label><i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> URL LinkedIn</label><input type="url" name="linkedin_url" value="<?=h($emp['linkedin_url']??'')?>" placeholder="https://www.linkedin.com/in/..."></div>
        <div class="form-group span-2"><label><i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> URL Credly</label><input type="url" name="credly_url" value="<?=h($emp['credly_url']??'')?>" placeholder="https://www.credly.com/users/..."></div>
        <div class="form-group"><label>Matricola</label><input type="text" name="employee_code" value="<?=h($emp['employee_code']??'')?>" placeholder="EMP-0042"></div>
        <div class="form-group"><label>Codice fiscale</label><input type="text" name="fiscal_code" value="<?=h($emp['fiscal_code']??'')?>" placeholder="RSSMRA80A01H501T"></div>
        <div class="form-group"><label>Data di nascita</label><input type="date" name="date_of_birth" value="<?=h($emp['date_of_birth']??'')?>"></div>
        <div class="form-group"></div>
        <div class="form-group span-2" style="margin:0">
          <label>Bio / Presentazione</label>
          <textarea name="bio" rows="3" placeholder="Breve descrizione professionale, focus area, esperienze chiave..."><?=h($emp['bio']??'')?></textarea>
        </div>
      </div>
    </div>

    <!-- Istruzione e formazione -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-graduation-cap" style="color:var(--p)"></i> Istruzione & formazione</span></div>
      <div class="form-group">
        <label>Titolo di studio</label>
        <select name="education_level">
          <?php foreach([
            ''                        => '— Non specificato',
            'Licenza media'           => 'Licenza media',
            'Diploma'                 => 'Diploma (scuola superiore)',
            'Laurea triennale'        => 'Laurea triennale (L)',
            'Laurea magistrale'       => 'Laurea magistrale (LM)',
            'Laurea magistrale ciclo unico' => 'Laurea ciclo unico',
            'Dottorato'               => 'Dottorato di ricerca (PhD)',
            'Master I livello'        => 'Master I livello',
            'Master II livello'       => 'Master II livello',
            'Corso professionale'     => 'Corso / certificazione professionale',
          ] as $v=>$l): ?>
          <option value="<?=$v?>" <?=($emp['education_level']??'')===$v?'selected':''?>><?=$l?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Indirizzo / facoltà</label>
        <input type="text" name="education_field" value="<?=h($emp['education_field']??'')?>" placeholder="Es. Ingegneria Informatica, Economia...">
      </div>
      <div class="form-group">
        <label>Istituto / Università</label>
        <input type="text" name="education_institute" value="<?=h($emp['education_institute']??'')?>" placeholder="Es. Politecnico di Milano">
      </div>
      <div class="form-group">
        <label>Anno conseguimento</label>
        <input type="text" name="education_year" value="<?=h($emp['education_year']??'')?>" placeholder="Es. 2019">
      </div>

    </div>

    <!-- Note HR (full width) -->
    <?php if($can_hr): ?>
    

    <div class="card" style="grid-column:span 2">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-lines" style="color:var(--muted)"></i> Note riservate HR</span></div>
      <textarea name="notes" rows="3" placeholder="Note interne — visibili solo a HR/Admin"><?=h($emp['notes']??'')?></textarea>
    </div>
    <?php endif; ?>
  </div>

  <div style="margin-top:16px">
    <button type="submit" class="btn btn-primary" style="padding:12px 28px"><i class="fa-solid fa-floppy-disk"></i> Salva anagrafica</button>
  </div>
</form>

<!-- ══ TAB: COMPETENZE ═══════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'background'): ?>
<!-- ══ TAB: BACKGROUND (Lingue + Titoli + Esperienze) ════════════════════════ -->
<?php
// Carico dati
$languages = $pdo->prepare("SELECT * FROM emp_languages WHERE employee_id=? ORDER BY mother_tongue DESC, id ASC");
$languages->execute([$emp_id]);
$languages = $languages->fetchAll();

try {
    $education = $pdo->prepare("SELECT * FROM emp_education WHERE employee_id=? ORDER BY is_current DESC, year_to DESC, year_from DESC, id DESC");
    $education->execute([$emp_id]);
    $education = $education->fetchAll();
} catch (Throwable $e) { $education = []; }

try {
    $experiences = $pdo->prepare("SELECT * FROM emp_experiences WHERE employee_id=? ORDER BY is_current DESC, period_to DESC, period_from DESC, id DESC");
    $experiences->execute([$emp_id]);
    $experiences = $experiences->fetchAll();
} catch (Throwable $e) { $experiences = []; }

// Lingua/edu/exp in modifica (se passati GET)
$edit_lang_id = (int)($_GET['edit_lang'] ?? 0);
$edit_edu_id  = (int)($_GET['edit_edu'] ?? 0);
$edit_exp_id  = (int)($_GET['edit_exp'] ?? 0);
$edit_lang = null; $edit_edu = null; $edit_exp = null;
foreach ($languages as $l) if ((int)$l['id'] === $edit_lang_id) $edit_lang = $l;
foreach ($education as $e) if ((int)$e['id'] === $edit_edu_id) $edit_edu = $e;
foreach ($experiences as $e) if ((int)$e['id'] === $edit_exp_id) $edit_exp = $e;

$cefr_levels = ['A1','A2','B1','B2','C1','C2'];
?>

<!-- ════ LINGUE ════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-language" style="color:#f59e0b"></i> Lingue conosciute (<?= count($languages) ?>)</span>
  </div>

  <?php if (!empty($languages)): ?>
  <table class="data-table" style="font-size:11px;margin-bottom:14px">
    <thead><tr>
      <th>Lingua</th><th>Madrelingua</th>
      <th title="Ascolto">Asc.</th><th title="Lettura">Lett.</th>
      <th title="Interaz.">Int.</th><th title="Prod. orale">Prod.</th>
      <th title="Scrittura">Scr.</th>
      <th>Certificazione</th><th width="80">Azioni</th>
    </tr></thead>
    <tbody>
    <?php foreach ($languages as $l): ?>
      <tr>
        <td><strong><?= h($l['language_name']) ?></strong></td>
        <td><?= $l['mother_tongue'] ? '<span style="color:#16a34a;font-weight:700">SI</span>' : '—' ?></td>
        <td><?= h($l['level_listening'] ?? '—') ?></td>
        <td><?= h($l['level_reading'] ?? '—') ?></td>
        <td><?= h($l['level_spoken_interaction'] ?? '—') ?></td>
        <td><?= h($l['level_spoken_production'] ?? '—') ?></td>
        <td><?= h($l['level_writing'] ?? '—') ?></td>
        <td><?= h($l['certification'] ?? '—') ?></td>
        <td>
          <a href="<?= qs_self_safe(['id'=>$emp_id, 'tab'=>'background', 'edit_lang'=>$l['id']]) ?>"
             class="btn btn-sm" style="padding:3px 7px"><i class="fa-solid fa-pen"></i></a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere <?= h($l['language_name']) ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="lang_delete">
            <input type="hidden" name="id" value="<?= $l['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:0;padding:3px 7px"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <details <?= $edit_lang ? 'open' : '' ?> style="border:1px solid var(--border);border-radius:8px;padding:12px;background:#fafbfc">
    <summary style="cursor:pointer;font-weight:700;font-size:12px;color:#7c3aed">
      <i class="fa-solid fa-<?= $edit_lang ? 'pen' : 'plus' ?>"></i> <?= $edit_lang ? 'Modifica lingua' : 'Aggiungi nuova lingua' ?>
    </summary>
    <form method="POST" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="lang_save">
      <input type="hidden" name="id" value="<?= $edit_lang['id'] ?? '' ?>">
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:8px;margin-bottom:8px">
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Lingua *</label>
          <input type="text" name="language_name" required placeholder="es. Inglese, Tedesco"
                 value="<?= h($edit_lang['language_name'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Madrelingua</label>
          <label style="display:flex;align-items:center;gap:6px;padding:6px 0">
            <input type="checkbox" name="mother_tongue" value="1" <?= !empty($edit_lang['mother_tongue']) ? 'checked' : '' ?>>
            <span style="font-size:11px">Lingua madre</span>
          </label>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-bottom:8px">
        <?php
        $cefr_fields = [
            'level_listening' => 'Ascolto',
            'level_reading' => 'Lettura',
            'level_spoken_interaction' => 'Interaz.',
            'level_spoken_production' => 'Prod. orale',
            'level_writing' => 'Scrittura',
        ];
        foreach ($cefr_fields as $f => $lbl):
        ?>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase"><?= $lbl ?></label>
          <select name="<?= $f ?>" style="width:100%;padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px;box-sizing:border-box">
            <option value="">—</option>
            <?php foreach ($cefr_levels as $lv): ?>
            <option value="<?= $lv ?>" <?= (($edit_lang[$f] ?? '') === $lv) ? 'selected' : '' ?>><?= $lv ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-bottom:10px">
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Certificazione (opzionale)</label>
        <input type="text" name="certification" placeholder="es. Cambridge FCE, IELTS 7.0, TOEFL 100"
               value="<?= h($edit_lang['certification'] ?? '') ?>"
               style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <?php if ($edit_lang): ?>
        <a href="<?= qs_self_safe(['id'=>$emp_id, 'tab'=>'background']) ?>" class="btn btn-sm">Annulla</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="background:#f59e0b;border:0">
          <i class="fa-solid fa-floppy-disk"></i> <?= $edit_lang ? 'Aggiorna' : 'Aggiungi' ?>
        </button>
      </div>
    </form>
  </details>
</div>

<!-- ════ TITOLI DI STUDIO ═════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-graduation-cap" style="color:#0ea5e9"></i> Titoli di studio (<?= count($education) ?>)</span>
  </div>

  <?php if (!empty($education)): ?>
  <?php foreach ($education as $ed): ?>
  <div style="border-left:3px solid #0ea5e9;background:#f0f9ff;padding:10px 14px;margin-bottom:8px;border-radius:0 6px 6px 0;display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;color:#1e40af">
        <?= h($ed['level'] ?? '—') ?>
        <?php if (!empty($ed['field'])): ?> · <?= h($ed['field']) ?><?php endif; ?>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:3px">
        <?php if (!empty($ed['institute'])): ?>
        <i class="fa-solid fa-school"></i> <?= h($ed['institute']) ?>
        <?php endif; ?>
        <?php if ($ed['year_from'] || $ed['year_to']): ?>
        · <i class="fa-solid fa-calendar"></i>
        <?= h($ed['year_from'] ?? '?') ?> — <?= $ed['is_current'] ? '<strong style="color:#16a34a">in corso</strong>' : h($ed['year_to'] ?? '?') ?>
        <?php endif; ?>
        <?php if (!empty($ed['grade'])): ?>
        · <strong>Voto: <?= h($ed['grade']) ?></strong>
        <?php endif; ?>
      </div>
      <?php if (!empty($ed['notes'])): ?>
      <div style="font-size:11px;color:#475569;margin-top:4px;font-style:italic"><?= h($ed['notes']) ?></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:4px">
      <a href="<?= qs_self_safe(['id'=>$emp_id, 'tab'=>'background', 'edit_edu'=>$ed['id']]) ?>"
         class="btn btn-sm" style="padding:3px 7px"><i class="fa-solid fa-pen"></i></a>
      <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere titolo?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edu_delete">
        <input type="hidden" name="id" value="<?= $ed['id'] ?>">
        <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:0;padding:3px 7px"><i class="fa-solid fa-trash"></i></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <details <?= $edit_edu ? 'open' : '' ?> style="border:1px solid var(--border);border-radius:8px;padding:12px;background:#fafbfc;margin-top:10px">
    <summary style="cursor:pointer;font-weight:700;font-size:12px;color:#0ea5e9">
      <i class="fa-solid fa-<?= $edit_edu ? 'pen' : 'plus' ?>"></i> <?= $edit_edu ? 'Modifica titolo' : 'Aggiungi nuovo titolo di studio' ?>
    </summary>
    <form method="POST" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edu_save">
      <input type="hidden" name="id" value="<?= $edit_edu['id'] ?? '' ?>">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Titolo / Livello</label>
          <input type="text" name="level" placeholder="es. Laurea magistrale, Diploma, Master, PhD"
                 value="<?= h($edit_edu['level'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Campo / Indirizzo</label>
          <input type="text" name="field" placeholder="es. Ingegneria Informatica"
                 value="<?= h($edit_edu['field'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
      </div>
      <div style="margin-bottom:8px">
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Istituto / Università</label>
        <input type="text" name="institute" placeholder="es. Università degli Studi di Firenze"
               value="<?= h($edit_edu['institute'] ?? '') ?>"
               style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;margin-bottom:8px">
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Anno dal</label>
          <input type="number" name="year_from" min="1950" max="2100" value="<?= h($edit_edu['year_from'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Anno al</label>
          <input type="number" name="year_to" min="1950" max="2100" value="<?= h($edit_edu['year_to'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Voto / Grade</label>
          <input type="text" name="grade" placeholder="es. 110/110" value="<?= h($edit_edu['grade'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">In corso</label>
          <label style="display:flex;align-items:center;gap:6px;padding:6px 0">
            <input type="checkbox" name="is_current" value="1" <?= !empty($edit_edu['is_current']) ? 'checked' : '' ?>>
            <span style="font-size:11px">Ancora in corso</span>
          </label>
        </div>
      </div>
      <div style="margin-bottom:10px">
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Note</label>
        <textarea name="notes" rows="2" placeholder="Tesi, specializzazione, riconoscimenti..."
                  style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box;resize:vertical"><?= h($edit_edu['notes'] ?? '') ?></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <?php if ($edit_edu): ?>
        <a href="<?= qs_self_safe(['id'=>$emp_id, 'tab'=>'background']) ?>" class="btn btn-sm">Annulla</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="background:#0ea5e9;border:0">
          <i class="fa-solid fa-floppy-disk"></i> <?= $edit_edu ? 'Aggiorna' : 'Aggiungi' ?>
        </button>
      </div>
    </form>
  </details>
</div>

<!-- ════ ESPERIENZE LAVORATIVE ════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-briefcase" style="color:#7c3aed"></i> Esperienze lavorative (<?= count($experiences) ?>)</span>
  </div>

  <?php if (!empty($experiences)): ?>
  <?php foreach ($experiences as $exp): ?>
  <div style="border-left:3px solid #7c3aed;background:#faf5ff;padding:10px 14px;margin-bottom:8px;border-radius:0 6px 6px 0;display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;color:#5b21b6">
        <?= h($exp['job_title'] ?? '—') ?>
        <?php if ($exp['is_current']): ?>
        <span style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:8px;font-size:9px;font-weight:800;margin-left:6px">IN CORSO</span>
        <?php endif; ?>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:3px">
        <?php if (!empty($exp['company'])): ?>
        <i class="fa-solid fa-building"></i> <?= h($exp['company']) ?>
        <?php endif; ?>
        <?php if (!empty($exp['location'])): ?>
        · <i class="fa-solid fa-location-dot"></i> <?= h($exp['location']) ?>
        <?php endif; ?>
        <?php if (!empty($exp['contract_type'])): ?>
        · <?= h($exp['contract_type']) ?>
        <?php endif; ?>
      </div>
      <?php if ($exp['period_from'] || $exp['period_to']): ?>
      <div style="font-size:11px;color:var(--muted);margin-top:2px">
        <i class="fa-solid fa-calendar"></i>
        <?= $exp['period_from'] ? date('m/Y', strtotime($exp['period_from'])) : '?' ?>
        →
        <?= $exp['is_current'] ? '<strong style="color:#16a34a">in corso</strong>' : ($exp['period_to'] ? date('m/Y', strtotime($exp['period_to'])) : '?') ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($exp['description'])): ?>
      <div style="font-size:11px;color:#475569;margin-top:4px;font-style:italic"><?= nl2br(h($exp['description'])) ?></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:4px">
      <a href="<?= qs_self_safe(['id'=>$emp_id, 'tab'=>'background', 'edit_exp'=>$exp['id']]) ?>"
         class="btn btn-sm" style="padding:3px 7px"><i class="fa-solid fa-pen"></i></a>
      <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere esperienza?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="exp_delete">
        <input type="hidden" name="id" value="<?= $exp['id'] ?>">
        <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:0;padding:3px 7px"><i class="fa-solid fa-trash"></i></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <details <?= $edit_exp ? 'open' : '' ?> style="border:1px solid var(--border);border-radius:8px;padding:12px;background:#fafbfc;margin-top:10px">
    <summary style="cursor:pointer;font-weight:700;font-size:12px;color:#7c3aed">
      <i class="fa-solid fa-<?= $edit_exp ? 'pen' : 'plus' ?>"></i> <?= $edit_exp ? 'Modifica esperienza' : 'Aggiungi nuova esperienza' ?>
    </summary>
    <form method="POST" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="exp_save">
      <input type="hidden" name="id" value="<?= $edit_exp['id'] ?? '' ?>">
      <div style="display:grid;grid-template-columns:2fr 2fr 1fr;gap:8px;margin-bottom:8px">
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Qualifica / Ruolo</label>
          <input type="text" name="job_title" placeholder="es. Senior Network Engineer"
                 value="<?= h($edit_exp['job_title'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Azienda</label>
          <input type="text" name="company" placeholder="es. WeTech S.p.A."
                 value="<?= h($edit_exp['company'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Sede</label>
          <input type="text" name="location" placeholder="es. Firenze"
                 value="<?= h($edit_exp['location'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px;margin-bottom:8px">
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Dal</label>
          <input type="date" name="period_from" value="<?= h($edit_exp['period_from'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Al</label>
          <input type="date" name="period_to" value="<?= h($edit_exp['period_to'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Tipo contratto</label>
          <input type="text" name="contract_type" placeholder="es. Indeterminato"
                 value="<?= h($edit_exp['contract_type'] ?? '') ?>"
                 style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">In corso</label>
          <label style="display:flex;align-items:center;gap:6px;padding:6px 0">
            <input type="checkbox" name="is_current" value="1" <?= !empty($edit_exp['is_current']) ? 'checked' : '' ?>>
            <span style="font-size:11px">Ancora attiva</span>
          </label>
        </div>
      </div>
      <div style="margin-bottom:10px">
        <label style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase">Descrizione attività</label>
        <textarea name="description" rows="3" placeholder="Mansioni, progetti, responsabilità..."
                  style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:5px;font-size:12px;box-sizing:border-box;resize:vertical"><?= h($edit_exp['description'] ?? '') ?></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <?php if ($edit_exp): ?>
        <a href="<?= qs_self_safe(['id'=>$emp_id, 'tab'=>'background']) ?>" class="btn btn-sm">Annulla</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary" style="background:#7c3aed;border:0">
          <i class="fa-solid fa-floppy-disk"></i> <?= $edit_exp ? 'Aggiorna' : 'Aggiungi' ?>
        </button>
      </div>
    </form>
  </details>
</div>

<?php elseif($active_tab === 'competenze'): ?>
<form method="POST">
        <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_anagrafica">
  <!-- campi hidden per preservare campi anagrafica durante update competenze -->
  <input type="hidden" name="first_name"     value="<?=h($emp['first_name'])?>">
  <input type="hidden" name="last_name"      value="<?=h($emp['last_name'])?>">
  <input type="hidden" name="fiscal_code"    value="<?=h($emp['fiscal_code']??'')?>">
  <input type="hidden" name="date_of_birth"  value="<?=h($emp['date_of_birth']??'')?>">
  <input type="hidden" name="employee_code"  value="<?=h($emp['employee_code']??'')?>">
  <input type="hidden" name="phone"          value="<?=h($emp['phone']??'')?>">
  <input type="hidden" name="phone_personal" value="<?=h($emp['phone_personal']??'')?>">
  <input type="hidden" name="personal_email" value="<?=h($emp['personal_email']??'')?>">
  <input type="hidden" name="business_email" value="<?=h($emp['business_email']??'')?>">
  <input type="hidden" name="credly_url"     value="<?=h($emp['credly_url']??'')?>">
  <input type="hidden" name="linkedin_url"   value="<?=h($emp['linkedin_url']??'')?>">
  <input type="hidden" name="education_level"    value="<?=h($emp['education_level']??'')?>">
  <input type="hidden" name="education_field"    value="<?=h($emp['education_field']??'')?>">
  <input type="hidden" name="education_institute" value="<?=h($emp['education_institute']??'')?>">
  <input type="hidden" name="education_year"     value="<?=h($emp['education_year']??'')?>">
  <input type="hidden" name="bio"            value="<?=h($emp['bio']??'')?>">
  <input type="hidden" name="notes"          value="<?=h($emp['notes']??'')?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Competenze tecniche -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-microchip" style="color:var(--p)"></i> Competenze tecniche</span></div>
      <div class="form-group" style="margin:0">
        <label>Skill tags (separate da virgola)</label>
        <textarea name="technical_skills" rows="5" placeholder="Es. PHP, MySQL, AWS, Docker, Kubernetes, Python..."><?=h($emp['technical_skills']??'')?></textarea>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">
          <?php foreach(array_filter(array_map('trim', explode(',', $emp['technical_skills']??''))) as $sk): ?>
          <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;margin:2px;display:inline-block"><?=h($sk)?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Soft skills -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-people-arrows" style="color:var(--p)"></i> Soft skills</span></div>
      <?php
      $soft_items = [
        'Comunicazione',         'Lavoro in team',         'Problem solving',
        'Leadership',            'Gestione del tempo',     'Creatività',
        'Adattabilità',          'Proattività',            'Empatia',
        'Pensiero critico',      'Orientamento al cliente','Apprendimento continuo',
      ];
      $sel_soft = array_map('trim', array_filter(explode(',', $emp['soft_skills']??'')));
      ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <?php foreach($soft_items as $sk):
          $is_sel = in_array($sk, $sel_soft, true);
        ?>
        <label style="cursor:pointer;user-select:none">
          <input type="checkbox" name="soft_check[]" value="<?=h($sk)?>" <?=$is_sel?'checked':''?> style="display:none" class="soft-cb">
          <span class="soft-tag" data-val="<?=h($sk)?>" style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;border:1px solid <?=$is_sel?'var(--p)':'var(--border)'?>;background:<?=$is_sel?'var(--p)':'#f8fafc'?>;color:<?=$is_sel?'#fff':'var(--muted)'?>;cursor:pointer;transition:.12s">
            <?=h($sk)?>
          </span>
        </label>
        <?php endforeach; ?>
      </div>

    </div>

    <!-- Certificazioni acquisite (read-only, sync auto da Credly/LinkedIn + manuali) -->
    <div class="card" style="grid-column:span 2">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-certificate" style="color:var(--p)"></i> Certificazioni acquisite (<?=count($certs)?>)</span>
        <div style="display:flex;gap:6px;align-items:center">
          <?php if($tot_credly > 0): ?>
            <span style="background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">
              <i class="fa-solid fa-shield-halved"></i> <?=$tot_credly?> Credly
            </span>
          <?php endif; ?>
          <?php if($tot_linkedin > 0): ?>
            <span style="background:#0a66c2;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">
              <i class="fa-brands fa-linkedin"></i> <?=$tot_linkedin?> LinkedIn
            </span>
          <?php endif; ?>
          <?php if($can_edit): ?>
            <a href="upload_certificato.php" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> Carica</a>
          <?php endif; ?>
        </div>
      </div>
      <?php if(empty($certs)): ?>
        <div style="text-align:center;padding:30px;color:var(--muted)">
          <i class="fa-solid fa-graduation-cap" style="font-size:32px;opacity:.3;display:block;margin-bottom:10px"></i>
          Nessuna certificazione registrata. Caricale manualmente o sincronizza il profilo Credly/LinkedIn.
        </div>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="data-table">
            <thead>
              <tr><th>Certificazione</th><th>Brand</th><th>Conseguita</th><th>Scadenza</th><th style="text-align:center">Stato</th></tr>
            </thead>
            <tbody>
              <?php foreach($certs as $c):
                $is_credly   = !empty($c['notes']) && strpos($c['notes'], 'credly_badge_id:') !== false;
                $is_linkedin = !empty($c['notes']) && strpos($c['notes'], 'Importato da LinkedIn') !== false;
              ?>
              <tr>
                <td>
                  <strong style="font-size:13px"><?=h($c['cert_name'])?></strong>
                  <?php if($is_credly): ?>
                    <span style="background:#ede9fe;color:#6d28d9;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;margin-left:4px"><i class="fa-solid fa-shield-halved"></i> Credly</span>
                  <?php elseif($is_linkedin): ?>
                    <span style="background:#dbeafe;color:#0a66c2;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;margin-left:4px"><i class="fa-brands fa-linkedin"></i> LinkedIn</span>
                  <?php endif; ?>
                  <?php if($c['cert_code']): ?><br><code style="font-size:10px;color:var(--muted)"><?=h($c['cert_code'])?></code><?php endif; ?>
                </td>
                <td><span class="badge badge-neutral" style="font-size:9px"><?=h($c['brand_name'])?></span></td>
                <td style="font-size:12px"><?=format_date($c['issue_date'])?></td>
                <td style="font-size:12px"><?=$c['expiry_date'] ? format_date($c['expiry_date']) : '<span style="color:var(--muted)">Perpetua</span>'?></td>
                <td style="text-align:center"><?=status_badge($c['status'])?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-top:16px">
    <button type="submit" class="btn btn-primary" style="padding:12px 28px"><i class="fa-solid fa-floppy-disk"></i> Salva competenze</button>
  </div>
</form>

<!-- ══ TAB: DOCUMENTI ════════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'documenti'): ?>

<?php
// v1.7.5: carica TUTTI i documenti del dipendente E dei candidati associati
// (match per email business/personale o codice fiscale)
$linked_candidate_ids = [];
try {
    $emp_match_q = $pdo->prepare(
        "SELECT c.id FROM candidates c
          WHERE (c.email IS NOT NULL AND c.email != '' AND c.email IN (?, ?))
             OR (c.last_name = ? AND c.first_name = ?)"
    );
    $emp_match_q->execute([
        $emp['business_email'] ?? '',
        $emp['personal_email'] ?? '',
        $emp['last_name']      ?? '__none__',
        $emp['first_name']     ?? '__none__',
    ]);
    while ($row = $emp_match_q->fetch()) {
        $linked_candidate_ids[] = (int)$row['id'];
    }
} catch (Throwable $e) {}

// Query: documenti collegati al dipendente OR ai candidati associati
$where_parts = ['pd.employee_id = ?'];
$query_params = [$emp_id];
if (!empty($linked_candidate_ids)) {
    $placeholders = implode(',', array_fill(0, count($linked_candidate_ids), '?'));
    $where_parts[] = "pd.candidate_id IN ($placeholders)";
    foreach ($linked_candidate_ids as $cid) $query_params[] = $cid;
}

$docs_q = $pdo->prepare(
    "SELECT pd.*,
            CONCAT(COALESCE(eu.first_name,''), ' ', COALESCE(eu.last_name,'')) AS uploaded_by_name
       FROM person_documents pd
       LEFT JOIN users u ON u.id = pd.uploaded_by
       LEFT JOIN employees eu ON eu.id = u.employee_id
      WHERE (" . implode(' OR ', $where_parts) . ")
      ORDER BY pd.created_at DESC, pd.id DESC"
);
$docs_q->execute($query_params);
$emp_documents = $docs_q->fetchAll();

// Conta quanti documenti sono ancora SOLO sul candidato (non trasferiti)
$pending_transfer = 0;
foreach ($emp_documents as $d) {
    if (empty($d['employee_id']) && !empty($d['candidate_id'])) $pending_transfer++;
}

// Mappa label tipi documento (più leggibile)
$doc_type_labels = [
    'cv'                    => 'Curriculum Vitae',
    'lettera_presentazione' => 'Lettera di presentazione',
    'note_selezione'        => 'Note di selezione',
    'test_tecnico'          => 'Test tecnico',
    'test_psicologico'      => 'Test psicologico',
    'valutazione'           => 'Valutazione',
    'contratto'             => 'Contratto',
    'certificato_formazione'=> 'Certificato formazione',
    'documento_identita'    => 'Documento identità',
    'altro'                 => 'Altro',
];
$doc_type_icons = [
    'cv'                    => ['fa-file-user', '#0ea5e9'],
    'lettera_presentazione' => ['fa-envelope-open', '#f59e0b'],
    'note_selezione'        => ['fa-clipboard', '#64748b'],
    'test_tecnico'          => ['fa-flask', '#ef4444'],
    'test_psicologico'      => ['fa-brain', '#8b5cf6'],
    'valutazione'           => ['fa-star', '#f59e0b'],
    'contratto'             => ['fa-file-signature', '#059669'],
    'certificato_formazione'=> ['fa-graduation-cap', '#0284c7'],
    'documento_identita'    => ['fa-id-card', '#dc2626'],
    'altro'                 => ['fa-file-circle-plus', '#10b981'],
];

// Helper: dimensione file leggibile
$fmtSize = function($bytes) {
    if (!$bytes) return '—';
    if ($bytes >= 1048576) return round($bytes/1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes/1024, 1)    . ' KB';
    return $bytes . ' B';
};

// Helper: estensione → icona
$fileIcon = function($mime, $name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (str_contains((string)$mime, 'pdf') || $ext === 'pdf')                 return ['fa-file-pdf', '#dc2626'];
    if (str_contains((string)$mime, 'image') || in_array($ext, ['jpg','jpeg','png','gif','webp'])) return ['fa-file-image', '#8b5cf6'];
    if (in_array($ext, ['doc','docx']))                                       return ['fa-file-word', '#2563eb'];
    if (in_array($ext, ['xls','xlsx','csv']))                                 return ['fa-file-excel', '#059669'];
    return ['fa-file', '#64748b'];
};
?>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div style="font-size:13px;color:#1e40af">
    <i class="fa-solid fa-folder-open" style="margin-right:6px"></i>
    <strong><?= count($emp_documents) ?> documento/i</strong> nell'archivio del dipendente
    <?php if (!empty($linked_candidate_ids)): ?>
      <span style="font-size:11px;color:#1e40af;margin-left:6px">
        (collegamento candidato #<?= implode(', #', $linked_candidate_ids) ?>)
      </span>
    <?php endif; ?>
  </div>
  <a href="documenti.php?employee_id=<?=$emp_id?>" class="btn btn-sm" style="background:#1e40af;color:#fff;border:none">
    <i class="fa-solid fa-arrow-right"></i> Apri archivio completo
  </a>
</div>

<?php if ($pending_transfer > 0 && in_array($u_role, [1, 2], true)): ?>
<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div style="font-size:12px;color:#92400e">
    <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px"></i>
    <strong><?= $pending_transfer ?> documento/i</strong> sono ancora associati solo al candidato originale.
    Trasferiscili definitivamente nel record del dipendente per consolidare l'archivio.
  </div>
  <form method="POST" action="documenti.php" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="transfer_to_employee">
    <input type="hidden" name="candidate_id" value="<?= $linked_candidate_ids[0] ?? 0 ?>">
    <input type="hidden" name="employee_id" value="<?= $emp_id ?>">
    <button type="submit" class="btn btn-sm" style="background:#d97706;color:#fff;border:0"
            onclick="return confirm('Trasferire <?= $pending_transfer ?> documento/i del candidato a questo dipendente?')">
      <i class="fa-solid fa-right-left"></i> Collega documenti al dipendente
    </button>
  </form>
</div>
<?php endif; ?>

<!-- ════ ELENCO DOCUMENTI CARICATI (person_documents) ════════════════════════ -->
<?php if (empty($emp_documents)): ?>
  <div style="background:#fff;border:1px dashed var(--border);border-radius:10px;padding:40px;text-align:center;color:var(--muted);margin-bottom:18px">
    <i class="fa-solid fa-folder-open" style="font-size:32px;opacity:.4;margin-bottom:10px"></i>
    <p style="margin:0">Nessun documento caricato per questo dipendente.</p>
    <p style="font-size:11px;margin-top:6px">Usa i form qui sotto per caricare i documenti principali.</p>
  </div>
<?php else: ?>
  <div style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:24px">
    <div style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px">
      <i class="fa-solid fa-folder-open" style="color:var(--p)"></i>
      <h3 style="margin:0;font-size:13px;font-weight:800">Documenti caricati</h3>
    </div>
    <table class="data-table" style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="background:#f8fafc">
          <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700;width:40px"></th>
          <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Tipo / Titolo</th>
          <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">File</th>
          <th style="padding:10px;text-align:right;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Dimensione</th>
          <th style="padding:10px;text-align:left;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700">Caricato</th>
          <th style="padding:10px;text-align:center;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:700;width:160px" class="no-print">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($emp_documents as $doc): ?>
          <?php
            [$ticon, $tcolor] = $doc_type_icons[$doc['doc_type']] ?? ['fa-file', '#64748b'];
            [$ficon, $fcolor] = $fileIcon($doc['mime_type'], $doc['original_name']);
            $type_label = $doc_type_labels[$doc['doc_type']] ?? ucfirst($doc['doc_type']);
            $is_image = str_contains((string)$doc['mime_type'], 'image');
            $is_pdf   = str_contains((string)$doc['mime_type'], 'pdf');
            $can_inline_view = $is_pdf || $is_image;
          ?>
          <tr style="border-top:1px solid var(--border)">
            <td style="padding:10px;text-align:center">
              <i class="fa-solid <?=$ticon?>" style="color:<?=$tcolor?>;font-size:18px"></i>
            </td>
            <td style="padding:10px">
              <div style="font-weight:700;font-size:13px;color:#1e293b"><?=h($type_label)?></div>
              <?php if (!empty($doc['title'])): ?>
                <div style="font-size:11px;color:var(--muted);margin-top:2px"><?=h($doc['title'])?></div>
              <?php endif; ?>
              <?php if ((int)$doc['version'] > 1): ?>
                <span style="background:#ede9fe;color:#5b21b6;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;margin-top:4px;display:inline-block">v<?=$doc['version']?></span>
              <?php endif; ?>
            </td>
            <td style="padding:10px;font-size:12px">
              <div style="display:flex;align-items:center;gap:6px">
                <i class="fa-solid <?=$ficon?>" style="color:<?=$fcolor?>"></i>
                <span style="font-family:monospace;color:#475569"><?=h($doc['original_name'])?></span>
              </div>
            </td>
            <td style="padding:10px;text-align:right;font-size:11px;color:var(--muted);font-family:monospace">
              <?=$fmtSize((int)$doc['file_size'])?>
            </td>
            <td style="padding:10px;font-size:11px;color:var(--muted)">
              <?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?>
              <?php if (!empty(trim($doc['uploaded_by_name']))): ?>
                <div style="margin-top:2px"><i class="fa-solid fa-user"></i> <?=h(trim($doc['uploaded_by_name']))?></div>
              <?php endif; ?>
            </td>
            <td style="padding:10px;text-align:center;white-space:nowrap" class="no-print">
              <?php if ($can_inline_view): ?>
                <a href="doc_download.php?id=<?=(int)$doc['id']?>&inline=1" target="_blank"
                   class="btn btn-sm" style="background:#dbeafe;color:#1e40af;border-color:#93c5fd"
                   title="Visualizza in nuova scheda">
                  <i class="fa-solid fa-eye"></i>
                </a>
              <?php endif; ?>
              <a href="doc_download.php?id=<?=(int)$doc['id']?>"
                 class="btn btn-sm" style="background:#f0fdf4;color:#15803d;border-color:#86efac"
                 title="Scarica">
                <i class="fa-solid fa-download"></i>
              </a>
              <?php if ($can_edit && ($u_role === 1 || (int)$doc['uploaded_by'] === $u_id)): ?>
                <form method="POST" action="documenti.php" style="display:inline" onsubmit="return confirm('Eliminare il documento <?=h(addslashes($doc['original_name']))?>?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="doc_id" value="<?=(int)$doc['id']?>">
                  <input type="hidden" name="redirect" value="employee_profile.php?id=<?=$emp_id?>&tab=documenti">
                  <button type="submit" class="btn btn-danger btn-sm" title="Elimina">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- ════ UPLOAD VELOCE (4 TIPI BASE — usa colonne legacy candidates) ═════════ -->
<div style="margin-bottom:14px">
  <h3 style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px">
    <i class="fa-solid fa-cloud-arrow-up" style="color:var(--p)"></i> Upload veloce
    <span style="font-size:11px;font-weight:400;color:var(--muted)">— per documenti più completi (con titolo, note, versione) usa l'archivio</span>
  </h3>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

  <?php
  $docs = [
    ['cv',                    'fa-file-user',     '#0ea5e9', 'Curriculum Vitae',       'cv_path',       'PDF, DOC, DOCX'],
    ['test_psicologico',      'fa-brain',         '#8b5cf6', 'Test psicologico',        'test_path',     'PDF, JPG, PNG'],
    ['lettera_presentazione', 'fa-envelope-open', '#f59e0b', 'Lettera di presentazione','lettera_path',  'PDF, DOC'],
    ['altro',                 'fa-file-circle-plus','#10b981','Documento aggiuntivo',   'doc_extra_path','PDF, DOC, JPG'],
  ];
  foreach($docs as [$dtype, $dicon, $dcolor, $dlabel, $dfield, $dfmt]):
    $existing = $emp[$dfield] ?? null;
  ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title">
        <i class="fa-solid <?=$dicon?>" style="color:<?=$dcolor?>"></i> <?=$dlabel?>
      </span>
      <?php if($existing): echo doc_link($existing, $dlabel); endif; ?>
    </div>

    <?php if($existing): ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#065f46">
      <i class="fa-solid fa-circle-check"></i> Documento legacy presente:
      <strong><?=h($existing)?></strong>
    </div>
    <?php else: ?>
    <div style="background:#f8fafc;border:1px dashed var(--border);border-radius:8px;padding:14px;margin-bottom:14px;text-align:center;font-size:12px;color:var(--muted)">
      <i class="fa-solid fa-cloud-arrow-up" style="font-size:24px;display:block;margin-bottom:6px;opacity:.4"></i>
      Nessun documento legacy
    </div>
    <?php endif; ?>

    <?php if($can_edit): ?>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_doc">
      <input type="hidden" name="doc_type" value="<?=$dtype?>">
      <div style="display:flex;gap:8px;align-items:center">
        <input type="file" name="doc_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
               style="flex:1;font-size:12px;padding:6px;border:1px solid var(--border);border-radius:7px;background:#fff">
        <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap">
          <i class="fa-solid fa-upload"></i> Carica
        </button>
      </div>
      <div style="font-size:10px;color:var(--muted);margin-top:4px">Formati: <?=$dfmt?> · Max 10 MB</div>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ TAB: CANDIDATURE ══════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'inquadramento'): ?>
<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_inquadramento">

  <?php if(!$can_edit): ?>
    <div class="alert alert-info" style="margin-bottom:16px">
      <i class="fa-solid fa-lock"></i> Vista di sola lettura. L'inquadramento HR è modificabile solo da Amministrazione.
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Azienda e sede -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-building" style="color:var(--p)"></i> Azienda e sede</span></div>
      <div class="grid-2">
        <div class="form-group">
          <label>Azienda</label>
          <select name="company_id" id="e_co" data-cascade="e_loc" data-entity="locations" data-param="company_id" <?=$can_edit?'':'disabled'?>>
            <option value="">—</option>
            <?php foreach($companies as $c): ?>
              <option value="<?=$c['id']?>" <?=($emp['company_id']==$c['id'])?'selected':''?>><?=h($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Sede</label>
          <select name="location_id" id="e_loc" <?=$can_edit?'':'disabled'?>>
            <option value="">— Seleziona prima l'azienda —</option>
            <?php foreach($all_locations as $l): if($l['company_id']==$emp['company_id']): ?>
              <option value="<?=$l['id']?>" <?=($emp['location_id']==$l['id'])?'selected':''?>><?=h($l['location_name'])?></option>
            <?php endif; endforeach; ?>
          </select>
        </div>
        <div class="form-group span-2">
          <label>Modalità di lavoro</label>
          <select name="work_mode_id" <?=$can_edit?'':'disabled'?>>
            <option value="">—</option>
            <?php foreach($work_modes as $wm): ?>
              <option value="<?=$wm['id']?>" <?=($emp['work_mode_id']==$wm['id'])?'selected':''?>><?=h($wm['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <!-- Ruolo e contratto -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-briefcase" style="color:var(--p)"></i> Ruolo e contratto</span></div>
      <div class="grid-2">
        <div class="form-group span-2"><label>Qualifica / Ruolo</label><input type="text" name="job_title" value="<?=h($emp['job_title']??'')?>" <?=$can_edit?'':'readonly'?>></div>
        <div class="form-group"><label>Dipartimento</label>
          <select name="department_id" <?=$can_edit?'':'disabled'?>>
            <option value="">— Nessuno —</option>
            <?php foreach($departments as $__d): ?>
              <option value="<?=$__d['id']?>" <?=((int)($emp['department_id']??0)===(int)$__d['id'])?'selected':''?>><?=h($__d['name'])?> (<?=h($__d['value_type'])?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Sotto-categoria</label>
          <select name="subcategory_id" id="ep_subcat" <?=$can_edit?'':'disabled'?>>
            <option value="">— Nessuna —</option>
            <?php foreach($subcategories as $__s): ?>
              <option value="<?=$__s['id']?>" data-dep="<?=$__s['department_id']?>" <?=((int)($emp['subcategory_id']??0)===(int)$__s['id'])?'selected':''?>><?=h($__s['name'])?> (<?=h($__s['value_type'])?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <script>
        (function(){
          var dep=document.querySelector('select[name="department_id"]'), sc=document.getElementById('ep_subcat');
          if(!dep||!sc) return;
          function filt(){var d=dep.value, cur=sc.value, keep=false;
            Array.prototype.forEach.call(sc.options,function(o){ if(!o.value){o.hidden=false;return;} var m=(o.getAttribute('data-dep')===d && d!==''); o.hidden=!m; if(m&&o.value===cur)keep=true;});
            if(!keep) sc.value='';
          }
          dep.addEventListener('change',filt); filt();
        })();
        </script>
        <div class="form-group">
          <label>Tipo contratto</label>
          <select name="contract_type" <?=$can_edit?'':'disabled'?>>
            <?php foreach(['Indeterminato','Determinato','Somministrazione','Consulenza','Stage','Partita IVA'] as $ct): ?>
              <option value="<?=$ct?>" <?=($emp['contract_type']===$ct)?'selected':''?>><?=$ct?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Stato</label>
          <select name="status" <?=$can_edit?'':'disabled'?>>
            <option value="active"     <?=$emp['status']==='active'?'selected':''?>>Attivo</option>
            <option value="inactive"   <?=$emp['status']==='inactive'?'selected':''?>>Inattivo</option>
            <option value="terminated" <?=$emp['status']==='terminated'?'selected':''?>>Cessato</option>
          </select>
        </div>
        <div class="form-group"><label>Data assunzione</label><input type="date" name="hire_date" value="<?=h($emp['hire_date']??'')?>" <?=$can_edit?'':'readonly'?>></div>
        <div class="form-group"><label>Data fine</label><input type="date" name="end_date" value="<?=h($emp['end_date']??'')?>" <?=$can_edit?'':'readonly'?>></div>
      </div>
    </div>

    <!-- v1.8.36: Inquadramento contrattuale (spostato nel tab Inquadramento HR) -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-contract" style="color:#0ea5e9"></i> Inquadramento contrattuale</span></div>
      <div class="grid-2">
        <div class="form-group"><label>Azienda o Agenzia (se esterna)</label>
          <input type="text" name="agency" value="<?=h($emp['agency']??'')?>" <?=$can_edit?'':'readonly'?> placeholder="es. Agenzia Hays"></div>
        <div class="form-group"><label>CCNL</label>
          <input type="text" name="ccnl" value="<?=h($emp['ccnl']??'')?>" <?=$can_edit?'':'readonly'?> placeholder="es. Terziario Confcommercio"></div>
        <div class="form-group"><label>Qualifica</label>
          <select name="qualification" <?=$can_edit?'':'disabled'?>>
            <option value="">—</option>
            <?php foreach (['Operaio','Impiegato','Quadro','Dirigente','Apprendista'] as $q): ?>
            <option value="<?= $q ?>" <?= ($emp['qualification']??'')===$q?'selected':'' ?>><?= $q ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Livello CCNL</label>
          <input type="text" name="contract_level" value="<?=h($emp['contract_level']??'')?>" <?=$can_edit?'':'readonly'?> placeholder="es. 4S, 3, 2, Quadro"></div>
        <div class="form-group"><label>Part-time</label>
          <select name="part_time" <?=$can_edit?'':'disabled'?>>
            <option value="0" <?= (int)($emp['part_time']??0)===0?'selected':'' ?>>No</option>
            <option value="1" <?= (int)($emp['part_time']??0)===1?'selected':'' ?>>Sì</option>
          </select>
        </div>
        <div class="form-group"><label>% Part-time</label>
          <input type="number" name="part_time_pct" step="0.01" min="0" max="100" value="<?=h($emp['part_time_pct']??'')?>" <?=$can_edit?'':'readonly'?> placeholder="es. 50"></div>
        <div class="form-group"><label>Data scadenza apprendistato</label>
          <input type="date" name="apprenticeship_end_date" value="<?=h($emp['apprenticeship_end_date']??'')?>" <?=$can_edit?'':'readonly'?>></div>
        <div class="form-group"><label>Sesso</label>
          <select name="gender" <?=$can_edit?'':'disabled'?>>
            <option value="">—</option>
            <option value="M" <?= ($emp['gender']??'')==='M'?'selected':'' ?>>Maschio</option>
            <option value="F" <?= ($emp['gender']??'')==='F'?'selected':'' ?>>Femmina</option>
            <option value="altro" <?= ($emp['gender']??'')==='altro'?'selected':'' ?>>Altro</option>
          </select>
        </div>
      </div>
    </div>

    <!-- v1.8.36: Badge (spostato nel tab Inquadramento HR) -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-id-badge" style="color:#7c3aed"></i> Badge accesso / timbratura</span></div>
      <div class="grid-2">
        <div class="form-group"><label>Numero badge</label>
          <input type="text" name="badge_number" value="<?=h($emp['badge_number']??'')?>" <?=$can_edit?'':'readonly'?> placeholder="es. 000206"></div>
        <div class="form-group"><label>Data rilascio badge</label>
          <input type="date" name="badge_issue_date" value="<?=h($emp['badge_issue_date']??'')?>" <?=$can_edit?'':'readonly'?>></div>
      </div>
    </div>

    <?php if ($can_compensation): ?>
    <!-- v1.8.36: Compensation & Benefit (link, spostato nel tab Inquadramento HR) -->
    <div class="card" style="grid-column:span 2;border:2px solid #fca5a5;background:#fef9f9;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <div>
        <span class="card-title" style="color:#dc2626"><i class="fa-solid fa-euro-sign"></i> Compensation &amp; Benefit</span>
        <span style="background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:10px;font-size:10px;letter-spacing:1px;font-weight:700"><i class="fa-solid fa-lock"></i> RISERVATO HR</span>
        <div style="font-size:12px;color:var(--muted);margin-top:4px">Dati economici, costo pieno e valore FTE sono gestiti in una scheda dedicata.</div>
      </div>
      <a class="btn btn-primary" href="<?= url_safe('employee_compensation', ['id' => $emp_id]) ?>">
        <i class="fa-solid fa-arrow-up-right-from-square"></i> Apri scheda Compensation
      </a>
    </div>
    <?php endif; ?>

  </div>

  <?php if($can_edit): ?>
  <div style="margin-top:16px">
    <button type="submit" class="btn btn-primary" style="padding:12px 28px">
      <i class="fa-solid fa-floppy-disk"></i> Salva inquadramento
    </button>
  </div>
  <?php endif; ?>
</form>

<!-- ══ TAB: STORICO ══════════════════════════════════════════════════════════ -->
<!-- ══ TAB: DISPOSITIVI ═══════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'dispositivi'): ?>

<?php
// Helpers
$_status_pill = function($status, $palette) {
    $p = $palette[$status] ?? ['#f1f5f9','#475569'];
    return "<span style='background:{$p[0]};color:{$p[1]};padding:2px 8px;border-radius:10px;font-size:9px;font-weight:800;text-transform:uppercase'>" . h($status) . "</span>";
};
$pal_assegnato = ['assegnato'=>['#dcfce7','#166534'], 'restituito'=>['#f1f5f9','#475569'], 'smarrito'=>['#fee2e2','#991b1b'], 'rotto'=>['#fef3c7','#92400e'], 'in_riparazione'=>['#dbeafe','#1e40af']];
$pal_attiva    = ['attiva'=>['#dcfce7','#166534'], 'disattiva'=>['#f1f5f9','#475569'], 'smarrita'=>['#fee2e2','#991b1b'], 'sostituita'=>['#fef3c7','#92400e'], 'bloccata'=>['#fee2e2','#991b1b']];
$pal_vehicle   = ['assegnato'=>['#dcfce7','#166534'], 'restituito'=>['#f1f5f9','#475569'], 'incidente'=>['#fee2e2','#991b1b'], 'rotto'=>['#fef3c7','#92400e']];

$device_section_anchor = (string)($_GET['section'] ?? '');
?>

<!-- ─── Toolbar export/import/print ────────────────────────────────── -->
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div style="font-size:13px;color:#1e40af">
    <i class="fa-solid fa-laptop-mobile" style="margin-right:6px"></i>
    <strong>Dispositivi assegnati al dipendente</strong>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap" class="no-print">
    <a href="device_export.php?employee_id=<?=$emp_id?>&format=xlsx" class="btn btn-sm" style="background:#059669;color:#fff;border:none">
      <i class="fa-solid fa-file-excel"></i> Esporta Excel
    </a>
    <a href="device_print.php?employee_id=<?=$emp_id?>" target="_blank" class="btn btn-sm">
      <i class="fa-solid fa-print"></i> Stampa scheda
    </a>
    <?php if ($can_edit): ?>
    <a href="device_import.php?employee_id=<?=$emp_id?>" class="btn btn-sm">
      <i class="fa-solid fa-file-import"></i> Importa
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ TELEFONO AZIENDALE ═══ -->
<div class="card" style="margin-bottom:18px" id="sec-phone">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-mobile-screen" style="color:#0ea5e9"></i> Telefono aziendale (<?=count($dev_phones)?>)</span>
    <?php if ($can_edit): ?>
    <button type="button" class="btn btn-sm btn-primary" onclick="dev_open('phone')"><i class="fa-solid fa-plus"></i> Nuovo</button>
    <?php endif; ?>
  </div>
  <?php if (empty($dev_phones)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px"><i class="fa-solid fa-circle-info" style="opacity:.5"></i> Nessun telefono assegnato.</div>
  <?php else: ?>
    <div style="overflow-x:auto"><table class="data-table">
      <thead><tr><th>Marca / Modello</th><th>IMEI 1</th><th>IMEI 2</th><th>S/N</th><th>Consegna</th><th>Ritiro</th><th>Stato</th><th style="width:90px"></th></tr></thead>
      <tbody>
        <?php foreach($dev_phones as $d): ?>
        <tr>
          <td><strong style="font-size:12px"><?=h($d['brand'])?> <?=h($d['model'])?></strong>
              <?php if($d['notes']): ?><br><small style="color:var(--muted)"><?=h($d['notes'])?></small><?php endif; ?></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['imei_1']??'—')?></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['imei_2']??'—')?></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['serial_number']??'—')?></td>
          <td style="font-size:11px"><?=$d['assigned_at']?format_date($d['assigned_at']):'—'?></td>
          <td style="font-size:11px"><?=$d['returned_at']?format_date($d['returned_at']):'<span style="color:#16a34a">in uso</span>'?></td>
          <td><?=$_status_pill($d['status'], $pal_assegnato)?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if($can_edit): ?>
            <button type="button" class="btn btn-sm" onclick='dev_open("phone", <?=json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT)?>)' title="Modifica"><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_phone">
              <input type="hidden" name="device_id" value="<?=$d['id']?>">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<!-- ═══ SIM AZIENDALE ═══ -->
<div class="card" style="margin-bottom:18px" id="sec-sim">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-sim-card" style="color:#10b981"></i> SIM aziendali (<?=count($dev_sims)?>)</span>
    <?php if ($can_edit): ?>
    <button type="button" class="btn btn-sm btn-primary" onclick="dev_open('sim')"><i class="fa-solid fa-plus"></i> Nuova SIM</button>
    <?php endif; ?>
  </div>
  <?php if (empty($dev_sims)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Nessuna SIM assegnata.</div>
  <?php else: ?>
    <div style="overflow-x:auto"><table class="data-table">
      <thead><tr><th>Tipo</th><th>Numero</th><th>Operatore</th><th>S/N</th><th>PIN</th><th>PUK</th><th>Consegna</th><th>Ritiro</th><th>Stato</th><th style="width:90px"></th></tr></thead>
      <tbody>
        <?php foreach($dev_sims as $d): ?>
        <tr>
          <td><span style="background:<?=$d['sim_type']==='voce'?'#dbeafe':'#fef3c7'?>;color:<?=$d['sim_type']==='voce'?'#1e40af':'#92400e'?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase"><?=$d['sim_type']==='voce'?'Voce':'Dati'?></span></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['phone_number']??'—')?></td>
          <td style="font-size:11px"><?=h($d['operator']??'—')?></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['serial_number']??'—')?></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['pin_code']??'—')?></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['puk_code']??'—')?></td>
          <td style="font-size:11px"><?=$d['assigned_at']?format_date($d['assigned_at']):'—'?></td>
          <td style="font-size:11px"><?=$d['returned_at']?format_date($d['returned_at']):'<span style="color:#16a34a">in uso</span>'?></td>
          <td><?=$_status_pill($d['status'], $pal_attiva)?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if($can_edit): ?>
            <button type="button" class="btn btn-sm" onclick='dev_open("sim", <?=json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_sim">
              <input type="hidden" name="device_id" value="<?=$d['id']?>">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<!-- ═══ NOTEBOOK ═══ -->
<div class="card" style="margin-bottom:18px" id="sec-notebook">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-laptop" style="color:#6366f1"></i> Notebook aziendali (<?=count($dev_notebooks)?>)</span>
    <?php if ($can_edit): ?>
    <button type="button" class="btn btn-sm btn-primary" onclick="dev_open('notebook')"><i class="fa-solid fa-plus"></i> Nuovo</button>
    <?php endif; ?>
  </div>
  <?php if (empty($dev_notebooks)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Nessun notebook assegnato.</div>
  <?php else: ?>
    <div style="overflow-x:auto"><table class="data-table">
      <thead><tr><th>Marca / Modello</th><th>S/N</th><th>SO</th><th>Caratteristiche</th><th>Consegna</th><th>Ritiro</th><th>Stato</th><th style="width:90px"></th></tr></thead>
      <tbody>
        <?php foreach($dev_notebooks as $d): ?>
        <tr>
          <td><strong style="font-size:12px"><?=h($d['brand'])?> <?=h($d['model'])?></strong></td>
          <td style="font-family:monospace;font-size:11px"><?=h($d['serial_number']??'—')?></td>
          <td style="font-size:11px"><?=h($d['os']??'—')?></td>
          <td style="font-size:11px;max-width:300px;color:var(--muted)"><?=h($d['specs']??'—')?></td>
          <td style="font-size:11px"><?=$d['assigned_at']?format_date($d['assigned_at']):'—'?></td>
          <td style="font-size:11px"><?=$d['returned_at']?format_date($d['returned_at']):'<span style="color:#16a34a">in uso</span>'?></td>
          <td><?=$_status_pill($d['status'], $pal_assegnato)?></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if($can_edit): ?>
            <button type="button" class="btn btn-sm" onclick='dev_open("notebook", <?=json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_notebook">
              <input type="hidden" name="device_id" value="<?=$d['id']?>">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<!-- ═══ VEICOLO ═══ -->
<div class="card" style="margin-bottom:18px" id="sec-vehicle">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-car" style="color:#dc2626"></i> Veicoli aziendali (<?=count($dev_vehicles)?>)</span>
    <?php if ($can_edit): ?>
    <button type="button" class="btn btn-sm btn-primary" onclick="dev_open('vehicle')"><i class="fa-solid fa-plus"></i> Nuovo veicolo</button>
    <?php endif; ?>
  </div>
  <?php if (empty($dev_vehicles)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Nessun veicolo assegnato.</div>
  <?php else: ?>
    <?php foreach($dev_vehicles as $d):
      $services = $dev_services[$d['id']] ?? [];
    ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px;background:#fafbfc">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <div>
          <div style="font-size:14px;font-weight:800"><?=h($d['brand'])?> <?=h($d['model'])?>
            <?php if($d['plate']): ?><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:11px;margin-left:8px"><?=h($d['plate'])?></span><?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px">
            <i class="fa-solid fa-gas-pump"></i> <?=h($d['fuel_type']??'—')?> ·
            <i class="fa-solid fa-file-contract"></i> <?=h($d['acquisition_type'])?>
            <?php if($d['contract_ref']): ?> · <?=h($d['contract_ref'])?><?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">
            <?php if($d['contract_start']): ?>Inizio: <?=format_date($d['contract_start'])?><?php endif; ?>
            <?php if($d['contract_end']): ?> · Fine: <?=format_date($d['contract_end'])?><?php endif; ?>
            <?php if($d['monthly_cost']): ?> · Rateo: <strong>€ <?=number_format($d['monthly_cost'],2,',','.')?></strong><?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">
            Km iniziali: <strong><?=number_format($d['initial_km']??0,0,',','.')?></strong> ·
            Km attuali: <strong><?=number_format($d['current_km']??0,0,',','.')?></strong>
          </div>
          <?php if($d['conditions']): ?>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;background:#fff;padding:6px 10px;border-radius:6px;border-left:3px solid #fbbf24">
            <strong>Condizioni:</strong> <?=h($d['conditions'])?>
          </div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
          <?=$_status_pill($d['status'], $pal_vehicle)?>
          <div style="font-size:10px;color:var(--muted)">
            <?=$d['assigned_at']?'Consegna '.format_date($d['assigned_at']):''?>
            <?=$d['returned_at']?' · Ritiro '.format_date($d['returned_at']):''?>
          </div>
          <?php if($can_edit): ?>
          <div>
            <button type="button" class="btn btn-sm" onclick='dev_open("vehicle", <?=json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare il veicolo e tutti i tagliandi?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_vehicle">
              <input type="hidden" name="device_id" value="<?=$d['id']?>">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tagliandi -->
      <details <?=count($services)?'open':''?> style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px">
        <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#475569">
          <i class="fa-solid fa-wrench"></i> Tagliandi (<?=count($services)?>)
        </summary>
        <?php if($services): ?>
        <table class="data-table" style="margin-top:8px">
          <thead><tr><th>Data</th><th>Km</th><th>Costo</th><th>Descrizione</th><th>Allegato</th><th style="width:50px"></th></tr></thead>
          <tbody>
            <?php foreach($services as $s): ?>
            <tr>
              <td style="font-size:11px"><?=format_date($s['service_date'])?></td>
              <td style="font-size:11px"><?=$s['km']?number_format($s['km'],0,',','.'):'—'?></td>
              <td style="font-size:11px"><?=$s['cost']?'€ '.number_format($s['cost'],2,',','.'):'—'?></td>
              <td style="font-size:11px;color:var(--muted);max-width:300px"><?=h($s['description']??'—')?></td>
              <td><?php if($s['document_path']): ?><a href="download.php?file=<?=urlencode('devices/'.$s['document_path'])?>" target="_blank" style="font-size:11px"><i class="fa-solid fa-paperclip"></i> Apri</a><?php else: ?>—<?php endif; ?></td>
              <td>
                <?php if($can_edit): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_service">
                  <input type="hidden" name="service_id" value="<?=$s['id']?>">
                  <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <?php if($can_edit): ?>
        <form method="POST" enctype="multipart/form-data" style="margin-top:8px;background:#fff;padding:10px;border-radius:6px;border:1px dashed var(--border)">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_service">
          <input type="hidden" name="vehicle_id" value="<?=$d['id']?>">
          <div style="display:grid;grid-template-columns:130px 110px 110px 1fr 150px auto;gap:8px;align-items:center">
            <input type="date" name="service_date" required value="<?=date('Y-m-d')?>" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="number" name="km" placeholder="Km" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="number" step="0.01" name="cost" placeholder="Costo €" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="text" name="description" placeholder="Descrizione intervento" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="file" name="service_doc" accept=".pdf,.jpg,.png" style="font-size:10px">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
          </div>
        </form>
        <?php endif; ?>
      </details>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ═══ CARTA CARBURANTE ═══ -->
<div class="card" style="margin-bottom:18px" id="sec-fuel">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-gas-pump" style="color:#f59e0b"></i> Carte carburante (<?=count($dev_fuel_cards)?>)</span>
    <?php if ($can_edit): ?>
    <button type="button" class="btn btn-sm btn-primary" onclick="dev_open('fuel_card')"><i class="fa-solid fa-plus"></i> Nuova carta</button>
    <?php endif; ?>
  </div>
  <?php if (empty($dev_fuel_cards)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Nessuna carta carburante assegnata.</div>
  <?php else: ?>
    <?php foreach($dev_fuel_cards as $d):
      $logs = $dev_fuel_logs[$d['id']] ?? [];
    ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px;background:#fafbfc">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <div>
          <div style="font-size:14px;font-weight:800"><?=h($d['circuit']??'Carta carburante')?>
            <?php if($d['card_number']): ?><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:11px;margin-left:8px"><?=h($d['card_number'])?></span><?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px">
            <?php if($d['pin_code']): ?>PIN: <strong style="font-family:monospace"><?=h($d['pin_code'])?></strong> · <?php endif; ?>
            <?php if($d['veh_brand']): ?>Veicolo: <?=h($d['veh_brand'])?> <?=h($d['veh_model'])?> (<?=h($d['plate'])?>)<?php endif; ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
          <?=$_status_pill($d['status'], $pal_attiva)?>
          <div style="font-size:10px;color:var(--muted)">
            <?=$d['assigned_at']?'Consegna '.format_date($d['assigned_at']):''?>
            <?=$d['returned_at']?' · Ritiro '.format_date($d['returned_at']):''?>
          </div>
          <?php if($can_edit): ?>
          <div>
            <button type="button" class="btn btn-sm" onclick='dev_open("fuel_card", <?=json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_fuel_card">
              <input type="hidden" name="device_id" value="<?=$d['id']?>">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <details <?=count($logs)?'open':''?> style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px">
        <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#475569">
          <i class="fa-solid fa-gas-pump"></i> Rifornimenti / letture km (<?=count($logs)?>)
        </summary>
        <?php if($logs): ?>
        <table class="data-table" style="margin-top:8px">
          <thead><tr><th>Data</th><th>Km</th><th>Litri</th><th>Importo</th><th>Località</th><th>Allegato</th><th style="width:50px"></th></tr></thead>
          <tbody>
            <?php foreach($logs as $r): ?>
            <tr>
              <td style="font-size:11px"><?=format_date($r['refuel_date'])?></td>
              <td style="font-size:11px"><?=$r['km']?number_format($r['km'],0,',','.'):'—'?></td>
              <td style="font-size:11px"><?=$r['liters']?number_format($r['liters'],2,',','.').' L':'—'?></td>
              <td style="font-size:11px"><?=$r['amount']?'€ '.number_format($r['amount'],2,',','.'):'—'?></td>
              <td style="font-size:11px;color:var(--muted)"><?=h($r['location']??'—')?></td>
              <td><?php if($r['document_path']): ?><a href="download.php?file=<?=urlencode('devices/'.$r['document_path'])?>" target="_blank" style="font-size:11px"><i class="fa-solid fa-paperclip"></i></a><?php else: ?>—<?php endif; ?></td>
              <td>
                <?php if($can_edit): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_fuel_log">
                  <input type="hidden" name="log_id" value="<?=$r['id']?>">
                  <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <?php if($can_edit): ?>
        <form method="POST" enctype="multipart/form-data" style="margin-top:8px;background:#fff;padding:10px;border-radius:6px;border:1px dashed var(--border)">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_fuel_log">
          <input type="hidden" name="fuel_card_id" value="<?=$d['id']?>">
          <div style="display:grid;grid-template-columns:130px 90px 90px 110px 1fr 130px auto;gap:6px;align-items:center">
            <input type="date" name="refuel_date" required value="<?=date('Y-m-d')?>" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="number" name="km" placeholder="Km" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="number" step="0.01" name="liters" placeholder="Litri" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="number" step="0.01" name="amount" placeholder="€" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="text" name="location" placeholder="Località" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="file" name="fuel_doc" accept=".pdf,.jpg,.png" style="font-size:10px">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
          </div>
        </form>
        <?php endif; ?>
      </details>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ═══ CARTA CREDITO ═══ -->
<div class="card" style="margin-bottom:18px" id="sec-credit">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-credit-card" style="color:#8b5cf6"></i> Carte di credito aziendali (<?=count($dev_credit_cards)?>)</span>
    <?php if ($can_edit): ?>
    <button type="button" class="btn btn-sm btn-primary" onclick="dev_open('credit_card')"><i class="fa-solid fa-plus"></i> Nuova carta</button>
    <?php endif; ?>
  </div>
  <?php if (empty($dev_credit_cards)): ?>
    <div style="text-align:center;padding:20px;color:var(--muted);font-size:13px">Nessuna carta di credito assegnata.</div>
  <?php else: ?>
    <?php foreach($dev_credit_cards as $d):
      $stmts = $dev_cc_stmts[$d['id']] ?? [];
    ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:14px;background:#fafbfc">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:10px">
        <div>
          <div style="font-size:14px;font-weight:800"><?=h($d['circuit']??'Carta credito')?>
            <?php if($d['card_number_last4']): ?><span style="background:#ede9fe;color:#6d28d9;padding:2px 8px;border-radius:6px;font-family:monospace;font-size:11px;margin-left:8px">●●●● <?=h($d['card_number_last4'])?></span><?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px">
            <?php if($d['bank']): ?>Banca: <?=h($d['bank'])?> · <?php endif; ?>
            <?php if($d['pin_code']): ?>PIN: <strong style="font-family:monospace"><?=h($d['pin_code'])?></strong> · <?php endif; ?>
            <?php if($d['credit_limit']): ?>Plafond: <strong>€ <?=number_format($d['credit_limit'],2,',','.')?></strong><?php endif; ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end">
          <?=$_status_pill($d['status'], $pal_attiva)?>
          <div style="font-size:10px;color:var(--muted)">
            <?=$d['assigned_at']?'Consegna '.format_date($d['assigned_at']):''?>
            <?=$d['returned_at']?' · Ritiro '.format_date($d['returned_at']):''?>
          </div>
          <?php if($can_edit): ?>
          <div>
            <button type="button" class="btn btn-sm" onclick='dev_open("credit_card", <?=json_encode($d, JSON_HEX_APOS|JSON_HEX_QUOT)?>)'><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare carta e tutti gli estratti conto?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_credit_card">
              <input type="hidden" name="device_id" value="<?=$d['id']?>">
              <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <details <?=count($stmts)?'open':''?> style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px">
        <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#475569">
          <i class="fa-solid fa-file-invoice-dollar"></i> Estratti conto (<?=count($stmts)?>)
        </summary>
        <?php if($stmts): ?>
        <table class="data-table" style="margin-top:8px">
          <thead><tr><th>Periodo</th><th>Totale</th><th>Note</th><th>Allegato</th><th style="width:50px"></th></tr></thead>
          <tbody>
            <?php foreach($stmts as $s): ?>
            <tr>
              <td style="font-size:11px"><strong><?=sprintf('%02d/%d', $s['period_month'], $s['period_year'])?></strong></td>
              <td style="font-size:11px"><?=$s['total_amount']?'€ '.number_format($s['total_amount'],2,',','.'):'—'?></td>
              <td style="font-size:11px;color:var(--muted)"><?=h($s['notes']??'')?></td>
              <td><?php if($s['document_path']): ?><a href="download.php?file=<?=urlencode('devices/'.$s['document_path'])?>" target="_blank" style="font-size:11px"><i class="fa-solid fa-file-pdf"></i> Apri</a><?php else: ?>—<?php endif; ?></td>
              <td>
                <?php if($can_edit): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_cc_statement">
                  <input type="hidden" name="statement_id" value="<?=$s['id']?>">
                  <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <?php if($can_edit): ?>
        <form method="POST" enctype="multipart/form-data" style="margin-top:8px;background:#fff;padding:10px;border-radius:6px;border:1px dashed var(--border)">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_cc_statement">
          <input type="hidden" name="credit_card_id" value="<?=$d['id']?>">
          <div style="display:grid;grid-template-columns:80px 130px 110px 1fr 130px auto;gap:6px;align-items:center">
            <input type="number" name="period_year" required value="<?=date('Y')?>" min="2020" max="2100" placeholder="Anno" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <select name="period_month" required style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
              <?php foreach (['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'] as $i => $m): ?>
                <option value="<?=$i+1?>" <?=$i+1===(int)date('n')?'selected':''?>><?=sprintf('%02d', $i+1)?> - <?=$m?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" step="0.01" name="total_amount" placeholder="Totale €" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="text" name="notes" placeholder="Note" style="padding:6px;border:1px solid var(--border);border-radius:5px;font-size:11px">
            <input type="file" name="statement_doc" accept=".pdf" style="font-size:10px">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i></button>
          </div>
        </form>
        <?php endif; ?>
      </details>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ═══ MODAL DEVICE FORM ═══ -->
<?php if ($can_edit): ?>
<div id="devModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;overflow-y:auto;padding:30px 14px">
  <div style="background:#fff;border-radius:12px;max-width:780px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 id="devModalTitle" style="margin:0;font-size:17px;font-weight:800"></h3>
      <button type="button" onclick="dev_close()" style="border:0;background:none;font-size:22px;cursor:pointer;color:#94a3b8">&times;</button>
    </div>
    <form method="POST" id="devForm" onsubmit="return dev_submit(this)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" id="devFormAction">
      <input type="hidden" name="device_id" id="devFormId" value="0">
      <div id="devFormFields" style="display:grid;grid-template-columns:1fr 1fr;gap:12px"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
        <button type="button" onclick="dev_close()" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
      </div>
    </form>
  </div>
</div>

<script>
// ═══ DEFINIZIONE FORM PER TIPO DISPOSITIVO ═══
const devForms = {
  phone: {
    title: 'Telefono aziendale',
    action: 'save_phone',
    fields: [
      {n:'brand', l:'Marca', t:'text', ph:'es. Samsung, Apple'},
      {n:'model', l:'Modello', t:'text', ph:'es. Galaxy S24'},
      {n:'imei_1', l:'IMEI 1', t:'text', ph:'15 cifre'},
      {n:'imei_2', l:'IMEI 2', t:'text', ph:'15 cifre (opzionale, per dual SIM)'},
      {n:'serial_number', l:'Numero seriale', t:'text', span:2},
      {n:'assigned_at', l:'Data consegna', t:'date'},
      {n:'returned_at', l:'Data ritiro', t:'date'},
      {n:'status', l:'Stato', t:'select', opts:[['assegnato','Assegnato'],['restituito','Restituito'],['smarrito','Smarrito'],['rotto','Rotto']], span:2},
      {n:'notes', l:'Note', t:'textarea', span:2},
    ]
  },
  sim: {
    title: 'SIM aziendale',
    action: 'save_sim',
    fields: [
      {n:'sim_type', l:'Tipo SIM', t:'select', opts:[['voce','Voce'],['dati','Dati']], required:true},
      {n:'operator', l:'Operatore', t:'text', ph:'TIM, Vodafone, WindTre, ...'},
      {n:'phone_number', l:'Numero telefono', t:'tel', ph:'+39 ...'},
      {n:'serial_number', l:'ICCID / Seriale', t:'text'},
      {n:'pin_code', l:'PIN', t:'text', ph:'4 cifre'},
      {n:'puk_code', l:'PUK', t:'text', ph:'8 cifre'},
      {n:'assigned_at', l:'Data consegna', t:'date'},
      {n:'returned_at', l:'Data ritiro', t:'date'},
      {n:'status', l:'Stato', t:'select', opts:[['attiva','Attiva'],['disattiva','Disattiva'],['smarrita','Smarrita'],['sostituita','Sostituita']], span:2},
      {n:'notes', l:'Note', t:'textarea', span:2},
    ]
  },
  notebook: {
    title: 'Notebook aziendale',
    action: 'save_notebook',
    fields: [
      {n:'brand', l:'Marca', t:'text', ph:'Dell, HP, Lenovo, Apple, ...'},
      {n:'model', l:'Modello', t:'text', ph:'es. ThinkPad X1 Carbon'},
      {n:'serial_number', l:'Numero seriale', t:'text'},
      {n:'os', l:'Sistema operativo', t:'text', ph:'Windows 11 Pro, macOS 14, Ubuntu 24.04'},
      {n:'specs', l:'Caratteristiche tecniche', t:'textarea', ph:'CPU, RAM, SSD, GPU, display', span:2},
      {n:'assigned_at', l:'Data consegna', t:'date'},
      {n:'returned_at', l:'Data ritiro', t:'date'},
      {n:'status', l:'Stato', t:'select', opts:[['assegnato','Assegnato'],['restituito','Restituito'],['smarrito','Smarrito'],['rotto','Rotto'],['in_riparazione','In riparazione']], span:2},
      {n:'notes', l:'Note', t:'textarea', span:2},
    ]
  },
  vehicle: {
    title: 'Veicolo aziendale',
    action: 'save_vehicle',
    fields: [
      {n:'brand', l:'Marca', t:'text'},
      {n:'model', l:'Modello', t:'text'},
      {n:'plate', l:'Targa', t:'text', ph:'AB123CD'},
      {n:'fuel_type', l:'Alimentazione', t:'select', opts:[['','—'],['Benzina','Benzina'],['Diesel','Diesel'],['GPL','GPL'],['Metano','Metano'],['Elettrico','Elettrico'],['Ibrido','Ibrido'],['Ibrido plug-in','Ibrido plug-in']]},
      {n:'acquisition_type', l:'Tipo acquisizione', t:'select', opts:[['noleggio','Noleggio'],['leasing','Leasing'],['finanziamento','Finanziamento'],['acquisto_diretto','Acquisto diretto'],['altro','Altro']]},
      {n:'contract_ref', l:'Riferimento contratto', t:'text', ph:'es. NLT-2026-001'},
      {n:'contract_start', l:'Inizio contratto', t:'date'},
      {n:'contract_end', l:'Fine contratto', t:'date'},
      {n:'monthly_cost', l:'Costo rateo mensile (€)', t:'number', step:'0.01'},
      {n:'initial_km', l:'Km iniziali', t:'number'},
      {n:'current_km', l:'Km attuali', t:'number'},
      {n:'conditions', l:'Condizioni contratto', t:'textarea', ph:'Es. 30.000 km/anno, franchigia €500, riconsegna...', span:2},
      {n:'assigned_at', l:'Data consegna', t:'date'},
      {n:'returned_at', l:'Data ritiro', t:'date'},
      {n:'status', l:'Stato', t:'select', opts:[['assegnato','Assegnato'],['restituito','Restituito'],['incidente','Incidente'],['rotto','Rotto']], span:2},
      {n:'notes', l:'Note', t:'textarea', span:2},
    ]
  },
  fuel_card: {
    title: 'Carta carburante',
    action: 'save_fuel_card',
    fields: [
      {n:'circuit', l:'Circuito', t:'text', ph:'ENI, Q8, Esso, IP, DKV, UTA, ...'},
      {n:'card_number', l:'Numero carta', t:'text'},
      {n:'pin_code', l:'PIN', t:'text'},
      {n:'vehicle_id', l:'Veicolo associato', t:'select', opts: <?= json_encode(array_merge([['','— Nessuno —']], array_map(fn($v) => [(string)$v['id'], "{$v['brand']} {$v['model']} (".($v['plate']??'?').')'], $dev_vehicles))) ?>},
      {n:'assigned_at', l:'Data consegna', t:'date'},
      {n:'returned_at', l:'Data ritiro', t:'date'},
      {n:'status', l:'Stato', t:'select', opts:[['attiva','Attiva'],['disattiva','Disattiva'],['smarrita','Smarrita'],['bloccata','Bloccata']], span:2},
      {n:'notes', l:'Note', t:'textarea', span:2},
    ]
  },
  credit_card: {
    title: 'Carta di credito aziendale',
    action: 'save_credit_card',
    fields: [
      {n:'circuit', l:'Circuito', t:'select', opts:[['','—'],['Visa','Visa'],['Mastercard','Mastercard'],['American Express','American Express'],['Diners','Diners']]},
      {n:'bank', l:'Banca emittente', t:'text', ph:'es. Intesa Sanpaolo, Unicredit'},
      {n:'card_number_last4', l:'Ultime 4 cifre', t:'text', ph:'XXXX'},
      {n:'pin_code', l:'PIN', t:'text'},
      {n:'credit_limit', l:'Plafond (€)', t:'number', step:'0.01'},
      {n:'assigned_at', l:'Data consegna', t:'date'},
      {n:'returned_at', l:'Data ritiro', t:'date'},
      {n:'status', l:'Stato', t:'select', opts:[['attiva','Attiva'],['disattiva','Disattiva'],['smarrita','Smarrita'],['bloccata','Bloccata']], span:2},
      {n:'notes', l:'Note', t:'textarea', span:2},
    ]
  }
};

function dev_submit(form) {
  // v1.5.2 DIAGNOSI: verifica che action sia popolata prima del submit
  const actionInput = form.querySelector('#devFormAction');
  if (!actionInput || !actionInput.value) {
    alert('ERRORE: action non impostata. Il modal forse non si è aperto correttamente. Aggiorna la pagina e riprova.');
    return false;
  }
  // Verifica CSRF token presente
  const csrf = form.querySelector('input[name="_csrf"]');
  if (!csrf || !csrf.value) {
    alert('ERRORE: token CSRF mancante. Aggiorna la pagina (F5) e riprova.');
    return false;
  }
  // Tutto OK, permetti il submit
  // Disabilito il bottone per evitare doppio submit
  const btn = form.querySelector('button[type=submit]');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvataggio...'; }
  return true;
}

function dev_open(type, data = null) {
  const cfg = devForms[type];
  if (!cfg) return;
  document.getElementById('devModalTitle').innerHTML =
    '<i class="fa-solid fa-laptop-mobile" style="color:var(--p)"></i> ' + (data ? 'Modifica ' : 'Nuovo ') + cfg.title;
  document.getElementById('devFormAction').value = cfg.action;
  document.getElementById('devFormId').value = data?.id || 0;
  const fields = document.getElementById('devFormFields');
  fields.innerHTML = cfg.fields.map(f => {
    const v = data?.[f.n] ?? '';
    const span = f.span === 2 ? 'grid-column:span 2' : '';
    let input;
    if (f.t === 'textarea') {
      input = `<textarea name="${f.n}" rows="2" placeholder="${f.ph||''}" style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit">${v}</textarea>`;
    } else if (f.t === 'select') {
      input = `<select name="${f.n}" ${f.required?'required':''} style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">`
        + f.opts.map(o => `<option value="${o[0]}" ${String(v)===String(o[0])?'selected':''}>${o[1]}</option>`).join('')
        + `</select>`;
    } else {
      input = `<input type="${f.t}" name="${f.n}" value="${v}" placeholder="${f.ph||''}" ${f.step?`step="${f.step}"`:''} ${f.required?'required':''} style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">`;
    }
    return `<div style="${span}"><label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:4px">${f.l}</label>${input}</div>`;
  }).join('');
  document.getElementById('devModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function dev_close() {
  document.getElementById('devModal').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('devModal')?.addEventListener('click', e => {
  if (e.target.id === 'devModal') dev_close();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    const m = document.getElementById('devModal');
    if (m && m.style.display === 'flex') dev_close();
  }
});
</script>
<?php endif; ?>



<?php elseif($active_tab === 'storico'): ?>

<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--p)"></i> Cronologia modifiche (<?=count($history)?>)</span>
  </div>
  <?php if(empty($history)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted)">
      <i class="fa-solid fa-clock-rotate-left" style="font-size:32px;opacity:.3;display:block;margin-bottom:10px"></i>
      Nessuna modifica registrata.
    </div>
  <?php else: ?>
    <div style="max-height:600px;overflow-y:auto">
      <table class="data-table">
        <thead>
          <tr><th>Data</th><th>Azione</th><th>Origine</th><th>Utente</th><th>Dettagli</th></tr>
        </thead>
        <tbody>
          <?php foreach($history as $h):
            $action_colors = [
              'insert' => ['#dcfce7', '#166534'],
              'update' => ['#dbeafe', '#1e40af'],
              'delete' => ['#fee2e2', '#991b1b'],
            ];
            [$ab,$ac] = $action_colors[$h['change_action']] ?? ['#f1f5f9','#475569'];
            $src_colors = [
              'credly'   => ['#ede9fe', '#6d28d9'],
              'linkedin' => ['#dbeafe', '#0a66c2'],
              'ui'       => ['#e0f2fe', '#0369a1'],
            ];
            [$sb_,$sc_] = $src_colors[$h['source']] ?? ['#f1f5f9','#475569'];
          ?>
          <tr>
            <td style="font-size:11px;white-space:nowrap"><?=date('d/m/Y H:i', strtotime($h['changed_at']))?></td>
            <td><span style="background:<?=$ab?>;color:<?=$ac?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;text-transform:uppercase"><?=h($h['change_action'])?></span></td>
            <td><span style="background:<?=$sb_?>;color:<?=$sc_?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700"><?=h($h['source'])?></span></td>
            <td style="font-size:11px;color:var(--muted)"><?=h(trim($h['by_name']) ?: '—')?></td>
            <td style="font-size:11px;color:var(--muted);max-width:500px;overflow-x:auto">
              <?php
                $diff = json_decode($h['changes_json'] ?? '', true);
                if (is_array($diff)) {
                  $shown = [];
                  foreach ($diff as $k => $v) {
                    if (is_array($v) && isset($v['old'], $v['new'])) {
                      $shown[] = "<strong>$k</strong>: <span style='color:#dc2626'>" . h(mb_strimwidth((string)$v['old'], 0, 30, '…')) . "</span> → <span style='color:#16a34a'>" . h(mb_strimwidth((string)$v['new'], 0, 30, '…')) . "</span>";
                    } elseif (!is_array($v)) {
                      $shown[] = "<strong>$k</strong>: " . h(mb_strimwidth((string)$v, 0, 50, '…'));
                    }
                  }
                  echo implode(' · ', array_slice($shown, 0, 5));
                  if (count($shown) > 5) echo ' <em>(+' . (count($shown) - 5) . ' altri)</em>';
                } else {
                  echo '<em>—</em>';
                }
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; ?>

<script>
// ── Stelle interattive ───────────────────────────────────────
document.querySelectorAll('.stars-row').forEach(row => {
  const radios = row.querySelectorAll('input[type=radio]');
  const labels = row.querySelectorAll('label');
  function paintStars(val) {
    labels.forEach((l, i) => l.style.color = i < val ? '#f59e0b' : '#d1d5db');
  }
  // Init
  let curVal = 0;
  radios.forEach(r => { if(r.checked) curVal = parseInt(r.value); });
  paintStars(curVal);
  // Hover
  labels.forEach((l, i) => {
    l.addEventListener('mouseenter', () => paintStars(i+1));
    l.addEventListener('mouseleave', () => {
      let v = 0;
      radios.forEach(r => { if(r.checked) v = parseInt(r.value); });
      paintStars(v);
    });
  });
  // Click
  radios.forEach(r => r.addEventListener('change', () => paintStars(parseInt(r.value))));
});

// addCertRow rimosso: le certificazioni dipendente sono read-only nella scheda (gestione da upload_certificato.php)
</script>

<script src="scope_filters.js"></script>

<?php require_once('footer.php'); ?>
