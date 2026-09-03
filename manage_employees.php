<?php
/**
 * certV 2.0 v2.2 — manage_employees.php
 * Anagrafica dipendenti — separata dalle utenze di accesso
 * Ruoli ammessi: Super Admin (1), HR Director (2)
 */
require_once('access_control.php');
require_once('header.php');
?>

<!-- v5: Event delegation isolato. Caricato come script standalone così resta
     funzionante anche se DataTables/jQuery falliscono nel main script. -->
<script>
(function() {
  'use strict';
  document.addEventListener('click', function(ev) {
    var btn;

    btn = ev.target.closest('.js-edit-emp');
    if (btn) {
      ev.preventDefault();
      try {
        var data = JSON.parse(btn.getAttribute('data-emp') || 'null');
        if (typeof window.openModal === 'function') window.openModal(data);
        else console.error('openModal non definita — verificare che jQuery/DataTables siano caricati');
      } catch (e) {
        console.error('Errore parse data-emp:', e, btn.getAttribute('data-emp'));
        alert('Impossibile aprire la scheda: dati non validi.');
      }
      return;
    }

    btn = ev.target.closest('.js-link-emp');
    if (btn) {
      ev.preventDefault();
      var empId = parseInt(btn.getAttribute('data-emp-id'), 10);
      var empName = btn.getAttribute('data-emp-name') || '';
      if (typeof window.openLinkModal === 'function') window.openLinkModal(empId, empName);
      return;
    }

    btn = ev.target.closest('.js-cv-emp');
    if (btn) {
      ev.preventDefault();
      try {
        var empId = parseInt(btn.getAttribute('data-emp-id'), 10);
        var empName = btn.getAttribute('data-emp-name') || '';
        var cvPath = JSON.parse(btn.getAttribute('data-cv-path') || 'null');
        if (typeof window.openCvModal === 'function') window.openCvModal(empId, empName, cvPath);
      } catch (e) { console.error('Errore parse data-cv-path:', e); }
      return;
    }

    btn = ev.target.closest('.js-contr-emp');
    if (btn) {
      ev.preventDefault();
      try {
        var empId = parseInt(btn.getAttribute('data-emp-id'), 10);
        var empName = btn.getAttribute('data-emp-name') || '';
        var contr = JSON.parse(btn.getAttribute('data-contr') || 'null');
        if (typeof window.openContrModal === 'function') window.openContrModal(empId, empName, contr);
      } catch (e) { console.error('Errore parse data-contr:', e); }
      return;
    }
  });
})();
</script>

<?php

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

// v1.7.48: permesso vista dati sensibili HR (RAL, premio, km, fuori sede)
$can_compensation = can('view', 'manage_employees_compensation.php');



$msg = '';

// ── CRUD ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $emp_id = (int)($_POST['employee_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        // ── Salva anagrafica ─────────────────────────────────────────────────
        if ($action === 'save') {
            $co  = ((int)($_POST['company_id']   ?? 0)) ?: null;
            $loc = ((int)($_POST['location_id']  ?? 0)) ?: null;
            $wm  = ((int)($_POST['work_mode_id'] ?? 0)) ?: null;

            // Normalizza URL Credly e LinkedIn (accetta URL completo o username/vanity)
            $credly_in   = trim($_POST['credly_url']   ?? '');
            $linkedin_in = trim($_POST['linkedin_url'] ?? '');
            // Se non è un URL ma sembra uno username, ricostruisci
            if ($credly_in && !preg_match('~^https?://~i', $credly_in)) {
                $credly_in = 'https://www.credly.com/users/' . ltrim($credly_in, '/');
            }
            if ($linkedin_in && !preg_match('~^https?://~i', $linkedin_in)) {
                $linkedin_in = 'https://www.linkedin.com/in/' . ltrim($linkedin_in, '/');
            }

            $data = [
                trim($_POST['first_name']  ?? ''),
                trim($_POST['last_name']   ?? ''),
                $co, $loc, $wm,
                trim($_POST['job_title']   ?? '') ?: null,
                trim($_POST['department']  ?? '') ?: null,
                $_POST['contract_type']    ?? 'Indeterminato',
                trim($_POST['employee_code'] ?? '') ?: null,
                trim($_POST['fiscal_code'] ?? '') ?: null,
                $_POST['date_of_birth']    ?: null,
                $_POST['hire_date']  ?: null,
                $_POST['end_date']   ?: null,
                $_POST['status']     ?? 'active',
                trim($_POST['phone']          ?? '') ?: null,
                trim($_POST['phone_personal'] ?? '') ?: null,
                trim($_POST['personal_email'] ?? '') ?: null,
                trim($_POST['business_email'] ?? '') ?: null,
                $credly_in ?: null,
                $linkedin_in ?: null,
                trim($_POST['bio']            ?? '') ?: null,
                trim($_POST['technical_skills'] ?? '') ?: null,
                trim($_POST['soft_skills']    ?? '') ?: null,
                trim($_POST['notes']          ?? '') ?: null,
                // v1.7.46: CCNL / inquadramento / badge
                in_array($_POST['gender'] ?? '', ['M','F','altro'], true) ? $_POST['gender'] : null,
                trim($_POST['ccnl'] ?? '') ?: null,
                !empty($_POST['part_time']) ? 1 : 0,
                is_numeric($_POST['part_time_pct'] ?? null) ? (float)$_POST['part_time_pct'] : null,
                $_POST['apprenticeship_end_date'] ?: null,
                trim($_POST['qualification'] ?? '') ?: null,
                trim($_POST['contract_level'] ?? '') ?: null,
                trim($_POST['badge_number'] ?? '') ?: null,
                $_POST['badge_issue_date'] ?: null,
            ];
            // v1.7.48: campi compensation accettati SOLO se autorizzato
            //          (per evitare manomissione client-side dei valori)
            $comp_fields = [
                'ral'                => is_numeric(str_replace(',', '.', (string)($_POST['ral'] ?? ''))) ? (float)str_replace(',', '.', $_POST['ral']) : null,
                'premio_concordato'  => is_numeric(str_replace(',', '.', (string)($_POST['premio_concordato'] ?? ''))) ? (float)str_replace(',', '.', $_POST['premio_concordato']) : null,
                'km_concordati'      => is_numeric($_POST['km_concordati'] ?? null) ? (float)$_POST['km_concordati'] : null,
                'km_effettivi'       => is_numeric($_POST['km_effettivi'] ?? null) ? (float)$_POST['km_effettivi'] : null,
                'fuori_sede'         => !empty($_POST['fuori_sede']) ? 1 : 0,
                'fuori_sede_amount'  => is_numeric(str_replace(',', '.', (string)($_POST['fuori_sede_amount'] ?? ''))) ? (float)str_replace(',', '.', $_POST['fuori_sede_amount']) : null,
                'classificazione_finanziaria' => in_array($_POST['classificazione_finanziaria'] ?? '', ['Diretto','Indiretto'], true) ? $_POST['classificazione_finanziaria'] : null,
            ];

            if (!$data[0] || !$data[1]) throw new Exception("Nome e cognome obbligatori.");

            // v1.7.58: risoluzione Dipartimento da lookup (+ sync testo legacy)
            $department_id = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
            $dep_name = null;
            if ($department_id !== null) {
                $dchk = $pdo->prepare("SELECT name FROM departments WHERE id=? AND is_active=1");
                $dchk->execute([$department_id]);
                $dep_name = $dchk->fetchColumn();
                if ($dep_name === false) { $department_id = null; $dep_name = null; }
            }
            // Sotto-categoria: valida solo se appartiene alla categoria selezionata
            $subcategory_id = ($_POST['subcategory_id'] ?? '') !== '' ? (int)$_POST['subcategory_id'] : null;
            if ($subcategory_id !== null && $department_id !== null) {
                $scchk = $pdo->prepare("SELECT id FROM department_subcategories WHERE id=? AND department_id=? AND is_active=1");
                $scchk->execute([$subcategory_id, (int)$department_id]);
                if ($scchk->fetchColumn() === false) $subcategory_id = null;
            } else { $subcategory_id = null; }

            if ($emp_id > 0) {
                // UPDATE base (sempre)
                $pdo->prepare(
                    "UPDATE employees SET
                       first_name=?,last_name=?,company_id=?,location_id=?,work_mode_id=?,
                       job_title=?,department=?,contract_type=?,employee_code=?,fiscal_code=?,
                       date_of_birth=?,hire_date=?,end_date=?,status=?,
                       phone=?,phone_personal=?,personal_email=?,business_email=?,
                       credly_url=?,linkedin_url=?,
                       bio=?,technical_skills=?,soft_skills=?,notes=?,
                       gender=?,ccnl=?,part_time=?,part_time_pct=?,apprenticeship_end_date=?,
                       qualification=?,contract_level=?,badge_number=?,badge_issue_date=?
                     WHERE id=?"
                )->execute([...$data, $emp_id]);
                // v1.7.58: Dipartimento da lookup (department_id + sync testo department)
                $pdo->prepare("UPDATE employees SET department_id=?, subcategory_id=?, department=? WHERE id=?")
                    ->execute([$department_id, $subcategory_id, $dep_name, $emp_id]);
                // v1.7.48: compensation UPDATE separato, SOLO se autorizzato
                if ($can_compensation) {
                    $pdo->prepare("UPDATE employees SET
                        ral=?, premio_concordato=?, km_concordati=?, km_effettivi=?,
                        fuori_sede=?, fuori_sede_amount=?, classificazione_finanziaria=?
                      WHERE id=?")->execute([
                        $comp_fields['ral'], $comp_fields['premio_concordato'],
                        $comp_fields['km_concordati'], $comp_fields['km_effettivi'],
                        $comp_fields['fuori_sede'], $comp_fields['fuori_sede_amount'],
                        $comp_fields['classificazione_finanziaria'],
                        $emp_id,
                    ]);
                }
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Dipendente aggiornato.</div>";
                write_log('Employees','success',"Modifica dipendente #$emp_id",$u_id);
            } else {
                // INSERT
                $pdo->prepare(
                    "INSERT INTO employees
                     (first_name,last_name,company_id,location_id,work_mode_id,
                      job_title,department,contract_type,employee_code,fiscal_code,
                      date_of_birth,hire_date,end_date,status,
                      phone,phone_personal,personal_email,business_email,
                      credly_url,linkedin_url,
                      bio,technical_skills,soft_skills,notes,
                      gender,ccnl,part_time,part_time_pct,apprenticeship_end_date,
                      qualification,contract_level,badge_number,badge_issue_date)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute($data);
                $new_id = (int)$pdo->lastInsertId();
                // v1.7.58: Dipartimento da lookup (department_id + sync testo department)
                $pdo->prepare("UPDATE employees SET department_id=?, subcategory_id=?, department=? WHERE id=?")
                    ->execute([$department_id, $subcategory_id, $dep_name, $new_id]);
                // v1.7.48: compensation INSERT, SOLO se autorizzato
                if ($can_compensation) {
                    $pdo->prepare("UPDATE employees SET
                        ral=?, premio_concordato=?, km_concordati=?, km_effettivi=?,
                        fuori_sede=?, fuori_sede_amount=?, classificazione_finanziaria=?
                      WHERE id=?")->execute([
                        $comp_fields['ral'], $comp_fields['premio_concordato'],
                        $comp_fields['km_concordati'], $comp_fields['km_effettivi'],
                        $comp_fields['fuori_sede'], $comp_fields['fuori_sede_amount'],
                        $comp_fields['classificazione_finanziaria'],
                        $new_id,
                    ]);
                }
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Dipendente aggiunto (ID $new_id).</div>";
                write_log('Employees','success',"Nuovo dipendente #$new_id",$u_id);
                $emp_id_for_sync = $new_id;
            }

            // ── Sync auto dei link Credly/LinkedIn dai relativi URL ─────────
            $eid_sync = $emp_id > 0 ? $emp_id : ($emp_id_for_sync ?? 0);
            if ($eid_sync > 0) {
                // Credly: estrai username dall'URL e crea/aggiorna employee_credly_link
                if ($credly_in && preg_match('~credly\.com/users/([^/?#\s]+)~i', $credly_in, $cm)) {
                    try {
                        $pdo->prepare(
                            "INSERT INTO employee_credly_link (employee_id, credly_username, created_by, created_at)
                             VALUES (?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE credly_username = VALUES(credly_username), updated_at = NOW()"
                        )->execute([$eid_sync, $cm[1], $u_id]);
                    } catch (Throwable $e) { /* tabella assente in versioni vecchie - ok */ }
                } elseif (!$credly_in) {
                    // Se URL vuoto: rimuovi anche il link
                    try {
                        $pdo->prepare("DELETE FROM employee_credly_link WHERE employee_id = ?")->execute([$eid_sync]);
                    } catch (Throwable $e) { /* ok */ }
                }

                // LinkedIn: estrai vanity dall'URL e crea/aggiorna employee_linkedin_link
                if ($linkedin_in && preg_match('~linkedin\.com/in/([^/?#\s]+)~i', $linkedin_in, $lm)) {
                    try {
                        $pdo->prepare(
                            "INSERT INTO employee_linkedin_link (employee_id, linkedin_vanity, created_by, created_at)
                             VALUES (?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE linkedin_vanity = VALUES(linkedin_vanity), updated_at = NOW()"
                        )->execute([$eid_sync, $lm[1], $u_id]);
                    } catch (Throwable $e) { /* ok */ }
                } elseif (!$linkedin_in) {
                    try {
                        $pdo->prepare("DELETE FROM employee_linkedin_link WHERE employee_id = ?")->execute([$eid_sync]);
                    } catch (Throwable $e) { /* ok */ }
                }
            }
        }

        // ── Collega / scollega account utente ────────────────────────────────
        if ($action === 'link_user' && $emp_id > 0) {
            $link_uid = (int)($_POST['link_user_id'] ?? 0);
            if ($link_uid > 0) {
                // Verifica che lo user non sia già collegato a un altro employee
                $check = $pdo->prepare("SELECT employee_id FROM users WHERE id=?");
                $check->execute([$link_uid]);
                $existing = $check->fetchColumn();
                if ($existing && (int)$existing !== $emp_id) {
                    throw new Exception("Questo account è già collegato al dipendente #$existing.");
                }
                $pdo->prepare("UPDATE users SET employee_id=? WHERE id=?")->execute([$emp_id, $link_uid]);
                $msg = "<div class='alert alert-success'>Account collegato.</div>";
                write_log('Employees','success',"Collegato user #$link_uid a employee #$emp_id",$u_id);
            } else {
                // Scollega
                $pdo->prepare("UPDATE users SET employee_id=NULL WHERE employee_id=?")->execute([$emp_id]);
                $msg = "<div class='alert alert-success'>Account scollegato.</div>";
            }
        }

        // ── Cambia stato ─────────────────────────────────────────────────────
        if ($action === 'set_status' && $emp_id > 0) {
            $new_status = $_POST['new_status'] ?? 'inactive';
            if (!in_array($new_status, ['active','inactive','terminated'])) throw new Exception("Stato non valido.");
            $pdo->prepare("UPDATE employees SET status=? WHERE id=?")->execute([$new_status, $emp_id]);
            // Se terminato, scollega automaticamente l'account
            if ($new_status === 'terminated') {
                $pdo->prepare("UPDATE users SET employee_id=NULL WHERE employee_id=?")->execute([$emp_id]);
            }
            $msg = "<div class='alert alert-success'>Stato aggiornato.</div>";
            write_log('Employees','info',"Stato dipendente #$emp_id → $new_status",$u_id);
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
}

// ── Upload CV — form separato con enctype multipart ───────────────────────────
// ── Auto-migrazione: aggiungi version + is_current a person_documents ────────
try {
    $pdo->query("SELECT version FROM person_documents LIMIT 0")->closeCursor();
} catch (\Exception $e) {
    try {
        $pdo->exec("ALTER TABLE person_documents
            ADD COLUMN IF NOT EXISTS `version` INT NOT NULL DEFAULT 1 COMMENT 'Numero versione',
            ADD COLUMN IF NOT EXISTS `is_current` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=versione corrente, 0=archiviata',
            ADD COLUMN IF NOT EXISTS `signed_date` DATE DEFAULT NULL COMMENT 'Data firma documento'");
    } catch (\Exception $e2) {}
}

// ── Upload contratto firmato dipendente (con versionamento) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_contract') {
    $emp_id = (int)($_POST['employee_id'] ?? 0);
    if ($emp_id > 0 && isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] === UPLOAD_ERR_OK) {
        $contr_dir = APP_ROOT . '/uploads/documenti/';
        if (!is_dir($contr_dir)) @mkdir($contr_dir, 0755, true);
        $file = $_FILES['contract_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx','jpg','jpeg','png','zip'];
        if (!in_array($ext, $allowed) || $file['size'] > 15*1024*1024) {
            $msg = "<div class='alert alert-danger'>Formato non valido. PDF, DOC, DOCX, JPG, PNG, ZIP — max 15 MB.</div>";
        } else {
            try {
                $pdo->beginTransaction();
                // Archivia versione corrente esistente
                $pdo->prepare("UPDATE person_documents SET is_current=0 WHERE employee_id=? AND doc_type='contratto' AND is_current=1")
                    ->execute([$emp_id]);
                // Calcola prossima versione
                $vq = $pdo->prepare("SELECT COALESCE(MAX(version),0)+1 FROM person_documents WHERE employee_id=? AND doc_type='contratto'");
                $vq->execute([$emp_id]); $next_ver = (int)$vq->fetchColumn(); $vq->closeCursor();

                $fname = "emp_{$emp_id}_contratto_v{$next_ver}_" . time() . ".$ext";
                if (move_uploaded_file($file['tmp_name'], $contr_dir . $fname)) {
                    $pdo->prepare(
                        "INSERT INTO person_documents
                         (employee_id, doc_type, file_name, original_name, file_size, mime_type,
                          title, signed_date, notes, version, is_current,
                          visibility, min_role_view, min_role_download, uploaded_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,1,'restricted',2,2,?)"
                    )->execute([
                        $emp_id, 'contratto', $fname, $file['name'], $file['size'], $file['type'],
                        trim($_POST['contract_title'] ?? '') ?: "Contratto v$next_ver",
                        !empty($_POST['signed_date']) ? $_POST['signed_date'] : null,
                        trim($_POST['contract_notes'] ?? '') ?: null,
                        $next_ver, $u_id
                    ]);
                    $pdo->commit();
                    write_log('Employees','success',"Upload contratto v$next_ver dipendente #$emp_id",$u_id);
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Contratto v{$next_ver} caricato. " . ($next_ver > 1 ? "Versione precedente archiviata automaticamente." : "") . "</div>";
                } else {
                    $pdo->rollBack();
                    $msg = "<div class='alert alert-danger'>Errore upload file.</div>";
                }
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_cv') {
    $emp_id = (int)($_POST['employee_id'] ?? 0);
    if ($emp_id > 0 && isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] === UPLOAD_ERR_OK) {
        $cv_dir = APP_ROOT . '/uploads/cv_dipendenti/';
        if (!is_dir($cv_dir)) @mkdir($cv_dir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['cv_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','doc','docx'];
        if (!in_array($ext, $allowed) || $_FILES['cv_file']['size'] > 10*1024*1024) {
            $msg = "<div class='alert alert-danger'>Formato non valido. Accettati: PDF, DOC, DOCX — max 10 MB.</div>";
        } else {
            // Rimuovi vecchio CV se presente
            $old_cv = $pdo->prepare("SELECT cv_path FROM employees WHERE id=?");
            $old_cv->execute([$emp_id]);
            $old_path = $old_cv->fetchColumn();
            if ($old_path && file_exists($cv_dir . $old_path)) @unlink($cv_dir . $old_path);

            $fname = "cv_emp{$emp_id}_" . time() . ".{$ext}";
            if (move_uploaded_file($_FILES['cv_file']['tmp_name'], $cv_dir . $fname)) {
                $pdo->prepare("UPDATE employees SET cv_path=? WHERE id=?")->execute([$fname, $emp_id]);
                write_log('Employees','success',"Upload CV dipendente #$emp_id",$u_id);
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> CV caricato.</div>";
            } else {
                $msg = "<div class='alert alert-danger'>Errore durante il caricamento del file.</div>";
            }
        }
    }
}

// ── Elimina CV ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_cv') {
    $emp_id = (int)($_POST['employee_id'] ?? 0);
    if ($emp_id > 0) {
        $old_cv = $pdo->prepare("SELECT cv_path FROM employees WHERE id=?");
        $old_cv->execute([$emp_id]);
        $old_path = $old_cv->fetchColumn();
        if ($old_path) @unlink(APP_ROOT . '/uploads/cv_dipendenti/' . $old_path);
        $pdo->prepare("UPDATE employees SET cv_path=NULL WHERE id=?")->execute([$emp_id]);
        $msg = "<div class='alert alert-success'>CV rimosso.</div>";
    }
}

// ── Filtri e lista ─────────────────────────────────────────────────────────────
$s    = trim($_GET['s']    ?? '');
$f_co = (int)($_GET['f_co'] ?? 0);
$f_st = $_GET['f_st'] ?? 'active';
$f_ct = $_GET['f_ct'] ?? '';

$where = ["1=1"]; $params = [];
if ($s)    { $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_code LIKE ? OR e.fiscal_code LIKE ? OR e.badge_number LIKE ?)"; array_push($params,"%$s%","%$s%","%$s%","%$s%","%$s%"); }
if ($f_co) { $where[] = "e.company_id=?";   $params[] = $f_co; }
if ($f_st) { $where[] = "e.status=?";        $params[] = $f_st; }
if ($f_ct) { $where[] = "e.contract_type=?"; $params[] = $f_ct; }

$emp_q = $pdo->prepare(
    "SELECT e.*,
            co.name company_name,
            loc.location_name,
            wm.name mode_name, wm.color_hex mode_color,
            dep.name dept_name, sub.name subcat_name,
            u.id user_id, u.email user_email, u.role_id,
            r.name role_name,
            (SELECT COUNT(*) FROM user_certifications uc WHERE uc.employee_id=e.id AND uc.status='active') cert_count,
            (SELECT COUNT(*) FROM training_plans tp WHERE tp.employee_id=e.id AND tp.status NOT IN('completed','cancelled')) plans_count
     FROM employees e
     LEFT JOIN companies co         ON e.company_id   = co.id
     LEFT JOIN company_locations loc ON e.location_id = loc.id
     LEFT JOIN work_modes wm         ON e.work_mode_id = wm.id
     LEFT JOIN departments dep       ON e.department_id = dep.id
     LEFT JOIN department_subcategories sub ON e.subcategory_id = sub.id
     LEFT JOIN users u               ON u.employee_id  = e.id
     LEFT JOIN roles r               ON u.role_id      = r.id
     WHERE " . implode(" AND ", $where) . "
     ORDER BY e.last_name, e.first_name"
);
$emp_q->execute($params);
$emp_list = $emp_q->fetchAll();

// v1.7.48: se utente NON autorizzato, rimuovo i campi sensibili dall'array
// (per evitare leak via JSON data-emp inviato al client)
if (!$can_compensation) {
    $sensitive_fields = ['ral','premio_concordato','km_concordati','km_effettivi','fuori_sede','fuori_sede_amount','classificazione_finanziaria'];
    foreach ($emp_list as &$emp_row) {
        foreach ($sensitive_fields as $sf) unset($emp_row[$sf]);
    }
    unset($emp_row);
}

// ── Carica info contratti correnti per ogni dipendente ──────────────────────
$contracts_map = [];
try {
    $cq = $pdo->query(
        "SELECT employee_id, version, file_name, signed_date, created_at,
                (SELECT COUNT(*) FROM person_documents pd2 WHERE pd2.employee_id=pd.employee_id AND pd2.doc_type='contratto') tot_versions
         FROM person_documents pd
         WHERE doc_type='contratto' AND is_current=1 AND employee_id IS NOT NULL"
    );
    foreach ($cq->fetchAll() as $r) $contracts_map[(int)$r['employee_id']] = $r;
} catch (\Exception $e) { /* tabella o colonne non ancora create */ }

// Dati per form
$all_companies = $pdo->query("SELECT * FROM companies ORDER BY name")->fetchAll();
$all_locations = $pdo->query("SELECT id, location_name, company_id FROM company_locations ORDER BY location_name")->fetchAll();
$all_modes     = $pdo->query("SELECT * FROM work_modes ORDER BY name")->fetchAll();
// v1.7.58: lookup dipartimenti (solo attivi) per la select Inquadramento HR
$departments = [];
try { $departments = $pdo->query("SELECT id, name, value_type FROM departments WHERE is_active=1 ORDER BY name")->fetchAll(); }
catch (\Exception $e) { }
$subcategories = [];
try { $subcategories = $pdo->query("SELECT s.id, s.department_id, s.name, COALESCE(s.value_type, d.value_type) AS value_type FROM department_subcategories s JOIN departments d ON d.id=s.department_id WHERE s.is_active=1 ORDER BY s.name")->fetchAll(); }
catch (\Exception $e) { /* tabella non ancora creata (migration pendente) */ }
// Account senza dipendente associato (per il collegamento)
$free_users    = $pdo->query(
    "SELECT u.id, u.email, r.name role_name FROM users u
     JOIN roles r ON u.role_id = r.id
     WHERE u.employee_id IS NULL AND u.status='active'
     ORDER BY u.email"
)->fetchAll();

// KPI
$tot_active     = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
$tot_no_account = (int)$pdo->query("SELECT COUNT(*) FROM employees e WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.employee_id=e.id) AND e.status='active'")->fetchColumn();
$tot_terminated = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='terminated'")->fetchColumn();

$contract_types = ['Indeterminato','Determinato','Apprendistato','Interinale','Somministrazione','Consulenza','Stage','Partita IVA'];
$status_opts    = ['active'=>'Attivi','inactive'=>'Inattivi','terminated'=>'Cessati',''=>'Tutti'];

// ══════════════════════════════════════════════════════════════════════════════
// SCHEDA DIPENDENTE — attivata da ?scheda=ID
// Renderizza e termina l'esecuzione (nessun HTML della lista)
// ══════════════════════════════════════════════════════════════════════════════
if (isset($_GET['scheda'])) {
    $eid = (int)$_GET['scheda'];
    $eq  = $pdo->prepare(
        "SELECT e.*, co.name company_name, loc.location_name, loc.address loc_address,
                wm.name mode_name, wm.color_hex mode_color,
                u.email user_email, r.name role_name
         FROM employees e
         LEFT JOIN companies co          ON e.company_id   = co.id
         LEFT JOIN company_locations loc ON e.location_id  = loc.id
         LEFT JOIN work_modes wm         ON e.work_mode_id = wm.id
         LEFT JOIN users u               ON u.employee_id  = e.id
         LEFT JOIN roles r               ON u.role_id      = r.id
         WHERE e.id = ?"
    );
    $eq->execute([$eid]);
    $emp = $eq->fetch();
    if (!$emp) { echo "<div style='padding:40px;text-align:center'>Dipendente non trovato.</div>"; require_once('footer.php'); exit(); }

    // Certificazioni
    $certs = $pdo->prepare(
        "SELECT uc.*, c.name cert_name, c.code cert_code, c.category, c.level,
                b.name brand_name, uc.expiry_date
         FROM user_certifications uc
         JOIN certifications c ON uc.certification_id = c.id
         JOIN brands b ON c.brand_id = b.id
         WHERE uc.employee_id = ?
         ORDER BY uc.status ASC, uc.expiry_date ASC"
    );
    $certs->execute([$eid]);
    $cert_list = $certs->fetchAll();

    // Piani formativi
    $plans = $pdo->prepare(
        "SELECT tp.*, c.name cert_name, b.name brand_name
         FROM training_plans tp
         JOIN certifications c ON tp.certification_id = c.id
         JOIN brands b ON c.brand_id = b.id
         WHERE tp.employee_id = ? AND tp.status NOT IN('completed','cancelled')
         ORDER BY tp.target_date ASC"
    );
    $plans->execute([$eid]);
    $plan_list = $plans->fetchAll();

    $show_notes = ($u_role === 1);  // solo Super Admin vede le note riservate
    $show_cv    = can('view');   // Admin e HR vedono il link CV
    $show_hr    = can('edit');   // Admin e HR vedono dati contrattuali completi
    $show_email = can('view');   // Brand Manager può vedere email

    $status_labels = ['active'=>'Attivo','inactive'=>'Inattivo','terminated'=>'Cessato'];
    $status_colors = ['active'=>'#065f46','inactive'=>'#475569','terminated'=>'#991b1b'];
    $st_bg         = ['active'=>'#d1fae5','inactive'=>'#f1f5f9','terminated'=>'#fee2e2'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Scheda dipendente — <?=h($emp['last_name'].' '.$emp['first_name'])?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',sans-serif;background:#f1f5f9;color:#1e293b;font-size:13px}
  .page{max-width:860px;margin:0 auto;padding:20px}
  .card{background:#fff;border-radius:10px;border:1px solid #e2e8f0;padding:18px;margin-bottom:14px}
  .section-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#64748b;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #e2e8f0}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  .field label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:2px}
  .field span{font-size:13px;color:#334155}
  .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase}
  .chip{display:inline-block;background:#e0f2fe;color:#0369a1;border-radius:10px;padding:2px 9px;font-size:10px;font-weight:700;margin:2px}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th{padding:8px 10px;text-align:left;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase}
  td{padding:8px 10px;border-bottom:1px solid #f1f5f9}
  .noprint{} .printbar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center}
  .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #e2e8f0;cursor:pointer;background:#fff;color:#334155;text-decoration:none;transition:.15s}
  .btn-primary{background:#0ea5e9;color:#fff;border-color:#0ea5e9}.btn-primary:hover{background:#0284c7}
  .btn-green{background:#d1fae5;color:#065f46;border-color:#10b981}
  .watermark{position:fixed;bottom:20px;right:20px;font-size:10px;color:#cbd5e1;font-style:italic}
  @media print{
    body{background:#fff}.page{padding:0;max-width:100%}
    .noprint,.printbar{display:none!important}
    .card{break-inside:avoid;border:1px solid #e2e8f0;box-shadow:none}
    .watermark{display:none}
  }
</style>
</head>
<body>
<div class="page">

  <!-- Toolbar stampa -->
  <div class="printbar noprint">
    <button onclick="window.print()" class="btn btn-primary"><i class="fa-solid fa-print"></i> Stampa</button>
    <?php if($show_cv && $emp['cv_path']): ?>
    <a href="download.php?f=<?=urlencode('cv_dipendenti/'.$emp['cv_path'])?>" target="_blank" class="btn btn-green">
      <i class="fa-solid fa-file-user"></i> Scarica CV
    </a>
    <?php endif; ?>
    <a href="manage_employees.php" class="btn"><i class="fa-solid fa-arrow-left"></i> Torna alla lista</a>
    <span style="margin-left:auto;font-size:11px;color:#94a3b8">
      Scheda generata il <?=date('d/m/Y H:i')?> · Visibile a: <?=$u_role===1?'Super Admin':($u_role===2?'HR Director':'Ruolo '.h(strval($u_role)))?>
    </span>
  </div>

  <!-- Intestazione dipendente -->
  <div class="card" style="display:flex;align-items:center;gap:18px;background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;border:none">
    <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#0ea5e9,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;flex-shrink:0">
      <?=strtoupper(substr($emp['first_name'],0,1).substr($emp['last_name'],0,1))?>
    </div>
    <div style="flex:1">
      <div style="font-size:22px;font-weight:800;margin-bottom:4px"><?=h($emp['last_name'].' '.$emp['first_name'])?></div>
      <div style="font-size:14px;opacity:.8"><?=h($emp['job_title']??'—')?><?=$emp['department']?' &nbsp;·&nbsp; '.h($emp['department']):''?></div>
      <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if($emp['employee_code']): ?>
        <span style="background:rgba(255,255,255,.15);padding:2px 9px;border-radius:5px;font-size:10px;font-weight:700">
          <?=h($emp['employee_code'])?>
        </span>
        <?php endif; ?>
        <span style="background:<?=$st_bg[$emp['status']]??'#f1f5f9'?>;color:<?=$status_colors[$emp['status']]??'#475569'?>;padding:2px 10px;border-radius:20px;font-size:10px;font-weight:800">
          <?=$status_labels[$emp['status']]??$emp['status']?>
        </span>
        <?php if($emp['mode_name']): ?>
        <span style="background:<?=h($emp['mode_color']??'#f1f5f9')?>;color:#334155;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:700">
          <?=h($emp['mode_name'])?>
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php if($show_cv && $emp['cv_path']): ?>
    <div class="noprint">
      <a href="download.php?f=<?=urlencode('cv_dipendenti/'.$emp['cv_path'])?>" target="_blank"
         style="display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);padding:8px 14px;border-radius:8px;color:#fff;text-decoration:none;font-size:12px;font-weight:600">
        <i class="fa-solid fa-file-user"></i> CV
      </a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Dati anagrafici e contrattuali -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

    <div class="card">
      <div class="section-title"><i class="fa-solid fa-user" style="margin-right:5px"></i>Dati anagrafici</div>
      <div class="grid-2" style="gap:10px">
        <?php if($emp['fiscal_code']): ?>
        <div class="field span-2"><label>Codice fiscale</label><span><?=h($emp['fiscal_code'])?></span></div>
        <?php endif; ?>
        <?php if($emp['phone']): ?>
        <div class="field"><label><i class="fa-solid fa-phone-flip" style="color:#0ea5e9"></i> Telefono aziendale</label><span><?=h($emp['phone'])?></span></div>
        <?php endif; ?>
        <?php if(!empty($emp['phone_personal'])): ?>
        <div class="field"><label><i class="fa-solid fa-mobile-screen" style="color:#10b981"></i> Telefono personale</label><span><?=h($emp['phone_personal'])?></span></div>
        <?php endif; ?>
        <?php if(!empty($emp['business_email'])): ?>
        <div class="field"><label><i class="fa-solid fa-envelope" style="color:#0ea5e9"></i> Email aziendale</label><span><a href="mailto:<?=h($emp['business_email'])?>" style="color:#0a66c2;text-decoration:none"><?=h($emp['business_email'])?></a></span></div>
        <?php endif; ?>
        <?php if($show_email && $emp['personal_email']): ?>
        <div class="field"><label><i class="fa-solid fa-envelope-open" style="color:#10b981"></i> Email personale</label><span><a href="mailto:<?=h($emp['personal_email'])?>" style="color:#0a66c2;text-decoration:none"><?=h($emp['personal_email'])?></a></span></div>
        <?php endif; ?>
        <?php if($emp['user_email']): ?>
        <div class="field"><label><i class="fa-solid fa-key" style="color:#64748b"></i> Email portale</label><span><?=h($emp['user_email'])?></span></div>
        <?php endif; ?>
        <?php if(!empty($emp['credly_url'])): ?>
        <div class="field"><label><i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> Profilo Credly</label><span><a href="<?=h($emp['credly_url'])?>" target="_blank" rel="noopener" style="color:#7c3aed;text-decoration:none;font-family:monospace;font-size:12px"><?=h(preg_replace('~^https?://(www\.)?credly\.com/users/~i','',$emp['credly_url']))?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:9px"></i></a></span></div>
        <?php endif; ?>
        <?php if(!empty($emp['linkedin_url'])): ?>
        <div class="field"><label><i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> Profilo LinkedIn</label><span><a href="<?=h($emp['linkedin_url'])?>" target="_blank" rel="noopener" style="color:#0a66c2;text-decoration:none;font-family:monospace;font-size:12px"><?=h(preg_replace('~^https?://(www\.|it\.)?linkedin\.com/in/~i','',$emp['linkedin_url']))?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:9px"></i></a></span></div>
        <?php endif; ?>
        <?php if($emp['role_name']): ?>
        <div class="field"><label>Ruolo portale</label><span><?=h($emp['role_name'])?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if($show_hr): ?>
    <div class="card">
      <div class="section-title"><i class="fa-solid fa-briefcase" style="margin-right:5px"></i>Inquadramento HR</div>
      <div class="grid-2" style="gap:10px">
        <div class="field"><label>Azienda</label><span><?=h($emp['company_name']??'—')?></span></div>
        <div class="field"><label>Sede</label><span><?=h($emp['location_name']??'—')?><?=$emp['loc_address']?' · '.h($emp['loc_address']):''?></span></div>
        <div class="field"><label>Tipo contratto</label><span><?=h($emp['contract_type']??'—')?></span></div>
        <div class="field"><label>Assunzione</label><span><?=format_date($emp['hire_date']??null)?><?=$emp['end_date']?' → '.format_date($emp['end_date']):''?></span></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Profilo professionale -->
  <?php if($emp['bio'] || $emp['technical_skills'] || $emp['soft_skills']): ?>
  <div class="card">
    <div class="section-title"><i class="fa-solid fa-brain" style="margin-right:5px"></i>Profilo professionale</div>
    <?php if($emp['bio']): ?><p style="font-size:13px;color:#475569;margin-bottom:12px;line-height:1.6"><?=h($emp['bio'])?></p><?php endif; ?>
    <?php if($emp['technical_skills']): ?>
    <div style="margin-bottom:8px">
      <label style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:4px">Skill tecniche</label>
      <?php foreach(array_filter(array_map('trim', explode(',', $emp['technical_skills']))) as $sk): ?>
      <span class="chip"><?=h($sk)?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if($emp['soft_skills']): ?>
    <div>
      <label style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;display:block;margin-bottom:4px">Soft skills</label>
      <?php foreach(array_filter(array_map('trim', explode(',', $emp['soft_skills']))) as $sk): ?>
      <span class="chip" style="background:#f0fdf4;color:#065f46"><?=h($sk)?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Certificazioni -->
  <?php if(!empty($cert_list)): ?>
  <div class="card">
    <div class="section-title"><i class="fa-solid fa-certificate" style="margin-right:5px"></i>Certificazioni (<?=count($cert_list)?>)</div>
    <table>
      <thead><tr><th>Certificazione</th><th>Brand</th><th>Categoria</th><th>Conseguita</th><th>Scadenza</th><th>Stato</th></tr></thead>
      <tbody>
      <?php foreach($cert_list as $cert):
        $sc_map = ['active'=>['#d1fae5','#065f46'],'expiring'=>['#fef3c7','#92400e'],'expired'=>['#fee2e2','#991b1b'],'revoked'=>['#f1f5f9','#475569']];
        [$sb,$sf] = $sc_map[$cert['status']] ?? ['#f1f5f9','#475569'];
      ?>
      <tr>
        <td><div style="font-weight:600"><?=h($cert['cert_name'])?></div><?php if($cert['cert_code']): ?><code style="font-size:10px;color:#94a3b8"><?=h($cert['cert_code'])?></code><?php endif; ?></td>
        <td><?=h($cert['brand_name'])?></td>
        <td><?=h($cert['category']??'—')?></td>
        <td><?=format_date($cert['issue_date'])?></td>
        <td><?=$cert['expiry_date']?format_date($cert['expiry_date']):'<span style="color:#94a3b8">—</span>'?></td>
        <td><span class="badge" style="background:<?=$sb?>;color:<?=$sf?>"><?=ucfirst($cert['status'])?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Piani formativi -->
  <?php if(!empty($plan_list)): ?>
  <div class="card">
    <div class="section-title"><i class="fa-solid fa-calendar-days" style="margin-right:5px"></i>Piani formativi attivi (<?=count($plan_list)?>)</div>
    <table>
      <thead><tr><th>Certificazione</th><th>Brand</th><th>Priorità</th><th>Target</th><th>Stato</th></tr></thead>
      <tbody>
      <?php foreach($plan_list as $pl): ?>
      <tr>
        <td style="font-weight:600"><?=h($pl['cert_name'])?></td>
        <td><?=h($pl['brand_name'])?></td>
        <td><span class="badge" style="background:<?=$pl['priority']==='Alta'?'#fef3c7':($pl['priority']==='Urgente'?'#fee2e2':'#f1f5f9')?>;color:<?=$pl['priority']==='Alta'?'#92400e':($pl['priority']==='Urgente'?'#991b1b':'#475569')?>"><?=h($pl['priority'])?></span></td>
        <td><?=format_date($pl['target_date'])?></td>
        <td><?=ucfirst(h($pl['status']))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Note HR riservate -->
  <?php if($show_notes && $emp['notes']): ?>
  <div class="card" style="border-color:#f59e0b;background:#fffbeb">
    <div class="section-title" style="color:#92400e"><i class="fa-solid fa-lock" style="margin-right:5px"></i>Note riservate HR — solo Super Admin</div>
    <p style="font-size:13px;color:#78350f;line-height:1.6"><?=h($emp['notes'])?></p>
  </div>
  <?php endif; ?>

  <!-- CV riservato -->
  <?php if($show_cv && $emp['cv_path']): ?>
  <div class="card noprint" style="border-color:#10b981">
    <div class="section-title" style="color:#065f46"><i class="fa-solid fa-file-user" style="margin-right:5px"></i>Curriculum Vitae</div>
    <a href="download.php?f=<?=urlencode('cv_dipendenti/'.$emp['cv_path'])?>" target="_blank"
       style="display:inline-flex;align-items:center;gap:8px;background:#d1fae5;color:#065f46;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none">
      <i class="fa-solid fa-download"></i> Scarica CV — <?=h($emp['cv_path'])?>
    </a>
  </div>
  <?php endif; ?>

  <!-- Footer scheda -->
  <div style="margin-top:20px;text-align:center;font-size:10px;color:#94a3b8;padding-top:14px;border-top:1px solid #e2e8f0">
    certV v2.2 — Scheda generata il <?=date('d/m/Y \l\l\e H:i')?> da <?=h($_SESSION['user_name']??'—')?>
    &nbsp;·&nbsp; Documento riservato — distribuzione soggetta a GDPR
  </div>
</div>
<script>
  // Auto-apri stampa se viene da redirect con ?print=1
  if (new URLSearchParams(location.search).get('print') === '1') window.print();
</script>
</body>
</html>
<?php
    exit();
} // fine if ?scheda
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-id-card" style="color:var(--p);margin-right:10px"></i>Anagrafica dipendenti
    </h1>
    <p style="color:var(--muted);font-size:13px">Archivio HR — separato dalle utenze di accesso al portale</p>
  </div>
  <button onclick="openModal()" class="btn btn-primary">
    <i class="fa-solid fa-user-plus"></i> Nuovo dipendente
  </button>
</div>

<!-- KPI -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
  <div class="stat-card" style="border-color:var(--success)">
    <div class="sl">Attivi</div>
    <div class="sv" style="color:var(--success)"><?=$tot_active?></div>
  </div>
  <div class="stat-card" style="border-color:var(--warning)">
    <div class="sl">Senza account</div>
    <div class="sv" style="color:var(--warning)"><?=$tot_no_account?></div>
    <?php if($tot_no_account>0): ?>
    <div style="font-size:10px;color:var(--warning);margin-top:3px">Collegamento disponibile</div>
    <?php endif; ?>
  </div>
  <div class="stat-card" style="border-color:var(--muted)">
    <div class="sl">Cessati</div>
    <div class="sv" style="color:var(--muted)"><?=$tot_terminated?></div>
  </div>
  <div class="stat-card" style="border-color:var(--p)">
    <div class="sl">Account liberi</div>
    <div class="sv" style="color:var(--p)"><?=count($free_users)?></div>
    <div style="font-size:10px;color:var(--muted);margin-top:3px">Senza dipendente associato</div>
  </div>
</div>

<?=$msg?>

<?php if($tot_no_account > 0): ?>
<div class="alert alert-info" style="margin-bottom:18px">
  <i class="fa-solid fa-circle-info"></i>
  <strong><?=$tot_no_account?> dipendenti attivi</strong> non hanno un account di accesso.
  Clicca <i class="fa-solid fa-link"></i> nella tabella per collegare un account esistente o crearne uno nuovo in
  <a href="manager_users.php" style="color:inherit;font-weight:700">Gestione utenti</a>.
</div>
<?php endif; ?>

<!-- Filtri -->
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg">
    <label>Cerca</label>
    <input type="text" name="s" value="<?=h($s)?>" placeholder="Nome, matricola, CF, badge…" style="min-width:180px">
  </div>
  <div class="fg">
    <label>Azienda</label>
    <select name="f_co">
      <option value="0">Tutte</option>
      <?php foreach($all_companies as $c): ?>
      <option value="<?=$c['id']?>" <?=$f_co==$c['id']?'selected':''?>><?=h($c['name'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fg">
    <label>Stato</label>
    <select name="f_st">
      <?php foreach($status_opts as $v=>$l): ?>
      <option value="<?=$v?>" <?=$f_st===$v?'selected':''?>><?=$l?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fg">
    <label>Contratto</label>
    <select name="f_ct">
      <option value="">Tutti</option>
      <?php foreach($contract_types as $ct): ?>
      <option value="<?=$ct?>" <?=$f_ct===$ct?'selected':''?>><?=$ct?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Filtra</button>
  <a href="manage_employees.php" class="btn">Reset</a>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<!-- Tabella -->
<div class="card" style="overflow-x:auto">
  <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('manage_employees', '#tEmp', ['export_filename' => 'manage_employees', 'title' => 'Anagrafica dipendenti']); ?>
<table class="data-table" id="tEmp">
    <thead>
      <tr>
        <th>Dipendente</th>
        <th>Qualifica</th>
        <th>Contratto</th>
        <th>Sede / Modalità</th>
        <th style="text-align:center">Cert.</th>
        <th style="text-align:center">Account</th>
        <th style="text-align:center">Stato</th>
        <th style="display:none">Matricola</th>
        <th style="display:none">Codice fiscale</th>
        <th style="display:none">Email aziendale</th>
        <th style="display:none">Genere</th>
        <th style="display:none">Azienda</th>
        <th style="display:none">Dipartimento</th>
        <th style="display:none">Sotto-categoria</th>
        <th style="display:none">Sede</th>
        <th style="display:none">Modalità</th>
        <th style="display:none">CCNL</th>
        <th style="display:none">Qualifica CCNL</th>
        <th style="display:none">Livello CCNL</th>
        <th style="display:none">Tipo contratto</th>
        <th style="display:none">Part-time</th>
        <th style="display:none">% Part-time</th>
        <th style="display:none">Scad. apprendistato</th>
        <th style="display:none">Assunzione</th>
        <th style="display:none">Fine rapporto</th>
        <th style="display:none">Badge</th>
        <th style="display:none">Ruolo</th>
        <th style="display:none">Email account</th>
        <?php if ($can_compensation): ?><th style="display:none">RAL</th><?php endif; ?>
        <th style="text-align:center" class="no-print">Azioni</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($emp_list as $e):
      $has_account = !empty($e['user_id']);
      $row_bg = $e['status']==='terminated' ? 'background:#f8fafc;' : '';
    ?>
    <tr style="<?=$row_bg?>">
      <td>
        <div style="font-weight:700"><?=h($e['last_name'].' '.$e['first_name'])?></div>
        <div style="font-size:11px;color:var(--muted)">
          <?php if($e['employee_code']): ?><code style="font-size:10px;background:#f1f5f9;padding:1px 5px;border-radius:3px"><?=h($e['employee_code'])?></code> · <?php endif; ?>
          <?=h($e['company_name']??'—')?>
        </div>
      </td>
      <td>
        <div style="font-size:13px"><?=h($e['job_title']??'—')?></div>
        <?php if($e['department']): ?><div style="font-size:11px;color:var(--muted)"><?=h($e['department'])?></div><?php endif; ?>
      </td>
      <td>
        <span class="badge badge-neutral" style="font-size:9px"><?=h($e['contract_type']??'—')?></span>
        <?php if($e['hire_date']): ?>
        <div style="font-size:10px;color:var(--muted);margin-top:2px"><?=format_date($e['hire_date'])?><?=$e['end_date']?' → '.format_date($e['end_date']):''?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if($e['mode_name']): ?>
        <span style="background:<?=h($e['mode_color']??'#eee')?>;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700"><?=h($e['mode_name'])?></span>
        <?php endif; ?>
        <div style="font-size:11px;color:var(--muted);margin-top:2px"><?=h($e['location_name']??'—')?></div>
      </td>
      <td style="text-align:center">
        <div style="font-weight:800;font-size:14px;color:<?=$e['cert_count']>0?'var(--success)':'var(--muted)'?>">
          <?=$e['cert_count']?>
        </div>
        <?php if($e['plans_count']>0): ?>
        <div style="font-size:9px;color:var(--p)"><?=$e['plans_count']?> piani</div>
        <?php endif; ?>
      </td>
      <td style="text-align:center">
        <?php if($has_account): ?>
        <div>
          <span class="badge badge-success" style="font-size:9px">Collegato</span>
          <div style="font-size:10px;color:var(--muted);margin-top:2px"><?=h($e['role_name']??'—')?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($e['user_email']??'')?></div>
        </div>
        <?php else: ?>
        <button class="btn btn-sm js-link-emp" data-emp-id="<?=$e['id']?>" data-emp-name="<?=h($e['first_name'].' '.$e['last_name'])?>"
                style="font-size:10px" title="Collega account">
          <i class="fa-solid fa-link"></i> Collega
        </button>
        <?php endif; ?>
      </td>
      <td style="text-align:center">
        <?php
        $st_map = ['active'=>['badge-success','Attivo'],'inactive'=>['badge-neutral','Inattivo'],'terminated'=>['badge-danger','Cessato']];
        [$bc,$bl] = $st_map[$e['status']] ?? ['badge-neutral',$e['status']];
        ?>
        <span class="badge <?=$bc?>" style="font-size:9px"><?=$bl?></span>
      </td>
      <td style="display:none"><?=h($e['employee_code']??'')?></td>
      <td style="display:none"><?=h($e['fiscal_code']??'')?></td>
      <td style="display:none"><?=h($e['business_email']??'')?></td>
      <td style="display:none"><?=h($e['gender']??'')?></td>
      <td style="display:none"><?=h($e['company_name']??'')?></td>
      <td style="display:none"><?=h($e['dept_name']??($e['department']??''))?></td>
      <td style="display:none"><?=h($e['subcat_name']??'')?></td>
      <td style="display:none"><?=h($e['location_name']??'')?></td>
      <td style="display:none"><?=h($e['mode_name']??'')?></td>
      <td style="display:none"><?=h($e['ccnl']??'')?></td>
      <td style="display:none"><?=h($e['qualification']??'')?></td>
      <td style="display:none"><?=h($e['contract_level']??'')?></td>
      <td style="display:none"><?=h($e['contract_type']??'')?></td>
      <td style="display:none"><?=!empty($e['part_time'])?'Sì':'No'?></td>
      <td style="display:none"><?=h($e['part_time_pct']??'')?></td>
      <td style="display:none"><?=$e['apprenticeship_end_date']?format_date($e['apprenticeship_end_date']):''?></td>
      <td style="display:none"><?=$e['hire_date']?format_date($e['hire_date']):''?></td>
      <td style="display:none"><?=$e['end_date']?format_date($e['end_date']):''?></td>
      <td style="display:none"><?=h($e['badge_number']??'')?></td>
      <td style="display:none"><?=h($e['role_name']??'')?></td>
      <td style="display:none"><?=h($e['user_email']??'')?></td>
      <?php if ($can_compensation): ?><td style="display:none"><?=h($e['ral']??'')?></td><?php endif; ?>
      <td style="text-align:center;white-space:nowrap" class="no-print">
        <a href="employee_profile.php?id=<?=$e['id']?>" class="btn btn-sm" title="Apri scheda completa">
          <i class="fa-solid fa-pen"></i>
        </a>
        <button class="btn btn-sm js-cv-emp <?=$e['cv_path'] ? 'btn-success' : ''?>"
                data-emp-id="<?=$e['id']?>"
                data-emp-name="<?=h($e['last_name'].' '.$e['first_name'])?>"
                data-cv-path='<?=htmlspecialchars(json_encode($e['cv_path']??null),ENT_QUOTES,"UTF-8")?>'
                title="<?=$e['cv_path'] ? 'CV presente — clicca per gestire' : 'Carica CV dipendente'?>">
          <i class="fa-solid fa-file-user"></i>
        </button>
        <?php
        $contr = $contracts_map[(int)$e['id']] ?? null;
        $ctitle = $contr ? "Contratto v{$contr['version']} (" . (int)$contr['tot_versions'] . " versioni totali) — clicca per nuova versione" : "Carica contratto firmato";
        ?>
        <button class="btn btn-sm js-contr-emp"
                data-emp-id="<?=$e['id']?>"
                data-emp-name="<?=h($e['last_name'].' '.$e['first_name'])?>"
                data-contr='<?=htmlspecialchars(json_encode($contr ?: null),ENT_QUOTES,"UTF-8")?>'
                style="<?=$contr ? 'background:#ecfdf5;color:#059669;border-color:#a7f3d0' : ''?>"
                title="<?=h($ctitle)?>">
          <i class="fa-solid fa-file-signature"></i><?=$contr ? '<sup style="font-size:8px;font-weight:800">v'.$contr['version'].'</sup>' : ''?>
        </button>
        <a href="documenti.php?employee_id=<?=$e['id']?>" class="btn btn-sm" style="background:#6366f120;color:#6366f1;border-color:#6366f130" title="Archivio documenti">
          <i class="fa-solid fa-folder-open"></i>
        </a>
        <a href="manage_employees.php?scheda=<?=$e['id']?>" target="_blank"
           class="btn btn-sm btn-blue" title="Stampa / Esporta scheda">
          <i class="fa-solid fa-print"></i>
        </a>
        <a href="employee_profile.php?id=<?=$e['id']?>" class="btn btn-sm" title="Dossier dipendente (scheda completa)">
          <i class="fa-solid fa-id-card"></i>
        </a>
        <a href="employee_cv.php?id=<?=$e['id']?>" class="btn btn-sm" title="Genera CV Europass" style="background:#e0e7ff;color:#1e40af">
          <i class="fa-solid fa-file-word"></i>
        </a>
        <?php if($e['status'] !== 'terminated'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Segnare come cessato?')">
            <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_status">
          <input type="hidden" name="employee_id" value="<?=$e['id']?>">
          <input type="hidden" name="new_status" value="terminated">
          <button type="submit" class="btn btn-sm btn-danger" title="Cessa rapporto">
            <i class="fa-solid fa-user-minus"></i>
          </button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(empty($emp_list)): ?>
    <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Nessun dipendente trovato.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Modal anagrafica -->
<div id="mEmp" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:780px">
    <div style="display:flex;justify-content:space-between;margin-bottom:20px">
      <h3 style="margin:0;font-size:16px" id="mEmpTitle">Nuovo dipendente</h3>
      <button onclick="closeModal('mEmp')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST" id="empForm">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="employee_id" id="e_id" value="0">

      <!-- Sezione anagrafica -->
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:10px">Dati anagrafici</div>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="form-group" style="margin:0"><label>Nome *</label><input type="text" name="first_name" id="e_fn" required></div>
        <div class="form-group" style="margin:0"><label>Cognome *</label><input type="text" name="last_name" id="e_ln" required></div>
        <div class="form-group" style="margin:0"><label>Matricola</label><input type="text" name="employee_code" id="e_ec" placeholder="Es. EMP-0042"></div>
        <div class="form-group" style="margin:0"><label>Codice fiscale</label><input type="text" name="fiscal_code" id="e_cf" placeholder="RSSMRA80A01H501T"></div>
        <div class="form-group" style="margin:0"><label>Data di nascita</label><input type="date" name="date_of_birth" id="e_dob"></div>
        <div class="form-group" style="margin:0"><label>Telefono aziendale</label><input type="tel" name="phone" id="e_ph" placeholder="+39 055 ..."></div>
        <div class="form-group" style="margin:0"><label>Telefono personale</label><input type="tel" name="phone_personal" id="e_pp" placeholder="+39 333 ..."></div>
        <div class="form-group" style="margin:0"><label>Email aziendale</label><input type="email" name="business_email" id="e_be" placeholder="nome.cognome@azienda.it"></div>
        <div class="form-group" style="margin:0"><label>Email personale</label><input type="email" name="personal_email" id="e_pe" placeholder="nome@gmail.com"></div>
      </div>

      <!-- Sezione Profili Pubblici / Social -->
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:10px">Profili pubblici</div>
      <div class="grid-2" style="margin-bottom:14px;gap:10px">
        <div class="form-group" style="margin:0">
          <label><i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> URL Credly</label>
          <input type="text" name="credly_url" id="e_credly"
                 placeholder="https://www.credly.com/users/nome-cognome">
          <div style="font-size:10px;color:#94a3b8;margin-top:3px">Accetta URL completo o solo username.</div>
        </div>
        <div class="form-group" style="margin:0">
          <label><i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> URL LinkedIn</label>
          <input type="text" name="linkedin_url" id="e_linkedin"
                 placeholder="https://www.linkedin.com/in/nome-cognome">
          <div style="font-size:10px;color:#94a3b8;margin-top:3px">Accetta URL completo o solo vanity.</div>
        </div>
      </div>

      <!-- Sezione HR (contiene Inquadramento contrattuale e Compensation & Benefit) -->
      <div style="border:1px solid #e2e8f0;border-radius:10px;padding:14px 14px 4px;margin-bottom:14px;background:#fafbfc">
      <div style="font-size:12px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid #e2e8f0"><i class="fa-solid fa-briefcase" style="margin-right:6px;color:#64748b"></i>Inquadramento HR</div>
      <div class="grid-3" style="margin-bottom:10px">
        <div class="form-group" style="margin:0"><label>Qualifica</label><input type="text" name="job_title" id="e_jt" placeholder="Es. Cloud Engineer"></div>
        <div class="form-group" style="margin:0"><label>Dipartimento</label>
          <select name="department_id" id="e_dep">
            <option value="">— Nessuno —</option>
            <?php foreach($departments as $__d): ?>
              <option value="<?=$__d['id']?>"><?=h($__d['name'])?> (<?=h($__d['value_type'])?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Sotto-categoria</label>
          <select name="subcategory_id" id="e_subcat">
            <option value="">— Nessuna —</option>
            <?php foreach($subcategories as $__s): ?>
              <option value="<?=$__s['id']?>" data-dep="<?=$__s['department_id']?>"><?=h($__s['name'])?> (<?=h($__s['value_type'])?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <script>
        (function(){
          var dep=document.getElementById('e_dep'), sc=document.getElementById('e_subcat');
          if(!dep||!sc) return;
          function filt(){var d=dep.value; var cur=sc.value, keep=false;
            Array.prototype.forEach.call(sc.options,function(o){ if(!o.value){o.hidden=false;return;} var m=(o.getAttribute('data-dep')===d && d!==''); o.hidden=!m; if(m&&o.value===cur)keep=true;});
            if(!keep) sc.value='';
          }
          dep.addEventListener('change',filt); filt();
        })();
        </script>
        <div class="form-group" style="margin:0">
          <label>Tipo contratto</label>
          <select name="contract_type" id="e_ct">
            <?php foreach($contract_types as $ct): ?>
            <option value="<?=$ct?>"><?=$ct?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Azienda</label>
          <select name="company_id" id="e_co" data-cascade="e_loc" data-entity="locations" data-param="company_id">
            <option value="">—</option>
            <?php foreach($all_companies as $c): ?>
            <option value="<?=$c['id']?>"><?=h($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Sede</label>
          <select name="location_id" id="e_loc">
            <option value="">— Seleziona prima l'azienda —</option>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Modalità lavoro</label>
          <select name="work_mode_id" id="e_wm">
            <option value="0">—</option>
            <?php foreach($all_modes as $wm): ?>
            <option value="<?=$wm['id']?>"><?=h($wm['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Data assunzione</label><input type="date" name="hire_date" id="e_hd"></div>
        <div class="form-group" style="margin:0"><label>Data fine rapporto</label><input type="date" name="end_date" id="e_ed"></div>
        <div class="form-group" style="margin:0"><label>Stato</label>
          <select name="status" id="e_st">
            <option value="active">Attivo</option>
            <option value="inactive">Inattivo</option>
            <option value="terminated">Cessato</option>
          </select>
        </div>
      </div>

      <!-- v1.7.46: Dati CCNL / Inquadramento — sottosezione di Inquadramento HR -->
      <div style="font-size:10px;font-weight:700;color:#0ea5e9;text-transform:uppercase;letter-spacing:0.4px;margin:2px 0 8px 14px">
        <i class="fa-solid fa-file-contract"></i> Inquadramento contrattuale
      </div>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="form-group" style="margin:0"><label>CCNL</label>
          <input type="text" name="ccnl" id="e_ccnl" placeholder="Es. Terziario Confcommercio"></div>
        <div class="form-group" style="margin:0"><label>Qualifica</label>
          <select name="qualification" id="e_qual">
            <option value="">—</option>
            <option value="Operaio">Operaio</option>
            <option value="Impiegato">Impiegato</option>
            <option value="Quadro">Quadro</option>
            <option value="Dirigente">Dirigente</option>
            <option value="Apprendista">Apprendista</option>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Livello CCNL</label>
          <input type="text" name="contract_level" id="e_lvl" placeholder="Es. 4S, 3, 2, Quadro"></div>
        <div class="form-group" style="margin:0"><label>Part-time</label>
          <select name="part_time" id="e_pt">
            <option value="0">No</option>
            <option value="1">Sì</option>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>% Part-time</label>
          <input type="number" name="part_time_pct" id="e_ptp" step="0.01" min="0" max="100" placeholder="50"></div>
        <div class="form-group" style="margin:0"><label>Scadenza apprendistato</label>
          <input type="date" name="apprenticeship_end_date" id="e_appr"></div>
        <div class="form-group" style="margin:0"><label>Sesso</label>
          <select name="gender" id="e_gen">
            <option value="">—</option>
            <option value="M">Maschio</option>
            <option value="F">Femmina</option>
            <option value="altro">Altro</option>
          </select>
        </div>
        <div class="form-group" style="margin:0"></div>
        <div class="form-group" style="margin:0"></div>
      </div>

      <?php if ($can_compensation): ?>
      <!-- v1.7.48: Compensation & Benefit (sottosezione riservata di Inquadramento HR) -->
      <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:0.4px;margin:2px 0 8px 14px;display:flex;justify-content:space-between;align-items:center">
        <span><i class="fa-solid fa-euro-sign"></i> Compensation &amp; Benefit</span>
        <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:9px;letter-spacing:1px"><i class="fa-solid fa-lock"></i> RISERVATO HR</span>
      </div>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="form-group" style="margin:0"><label>RAL annua (€)</label>
          <input type="number" name="ral" id="e_ral" step="0.01" min="0" placeholder="24972.50"></div>
        <div class="form-group" style="margin:0"><label>Premio concordato (€)</label>
          <input type="number" name="premio_concordato" id="e_premio" step="0.01" min="0" placeholder="3000.00"></div>
        <div class="form-group" style="margin:0"></div>
        <div class="form-group" style="margin:0"><label>Km concordati (annui)</label>
          <input type="number" name="km_concordati" id="e_kmc" step="0.01" min="0" placeholder="30000"></div>
        <div class="form-group" style="margin:0"><label>Km effettivi (annui)</label>
          <input type="number" name="km_effettivi" id="e_kme" step="0.01" min="0" placeholder="0"></div>
        <div class="form-group" style="margin:0"></div>
        <div class="form-group" style="margin:0"><label>Indennità fuori sede</label>
          <select name="fuori_sede" id="e_fs">
            <option value="0">No</option>
            <option value="1">Sì</option>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Importo fuori sede (€ annui)</label>
          <input type="number" name="fuori_sede_amount" id="e_fsa" step="0.01" min="0" placeholder="0.00"></div>
        <div class="form-group" style="margin:0"><label>Classificazione finanziaria</label>
          <select name="classificazione_finanziaria" id="e_clf">
            <option value="">—</option>
            <option value="Diretto">Diretto</option>
            <option value="Indiretto">Indiretto</option>
          </select>
        </div>
      </div>
      <?php endif; ?>
      </div><!-- /contenitore Inquadramento HR -->

      <!-- v1.7.46: Badge accesso/timbratura -->
      <div style="font-size:11px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:0.4px;margin:6px 0 8px 0;padding-top:8px;border-top:1px dashed #e2e8f0">
        <i class="fa-solid fa-id-badge"></i> Badge accesso / timbratura
      </div>
      <div class="grid-3" style="margin-bottom:14px">
        <div class="form-group" style="margin:0"><label>Numero badge</label>
          <input type="text" name="badge_number" id="e_badge" placeholder="Es. 000206"></div>
        <div class="form-group" style="margin:0"><label>Data rilascio badge</label>
          <input type="date" name="badge_issue_date" id="e_badgedate"></div>
        <div class="form-group" style="margin:0"></div>
      </div>

      <!-- Sezione profilo -->
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:10px">Profilo professionale</div>
      <div class="form-group"><label>Bio</label><textarea name="bio" id="e_bio" rows="2"></textarea></div>
      <div class="grid-2">
        <div class="form-group"><label>Skill tecniche (virgola)</label><input type="text" name="technical_skills" id="e_sk" placeholder="AWS, Python, Cisco..."></div>
        <div class="form-group"><label>Soft skills (virgola)</label><input type="text" name="soft_skills" id="e_ss" placeholder="Leadership, Teamwork..."></div>
      </div>
      <?php if($u_role === 1): ?>
      <div class="form-group"><label>Note HR <span style="color:var(--muted);font-weight:400">(riservate — non visibili al dipendente)</span></label>
        <textarea name="notes" id="e_notes" rows="2"></textarea>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">
          <i class="fa-solid fa-floppy-disk"></i> Salva
        </button>
        <button type="button" onclick="closeModal('mEmp')" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal collegamento account -->
<div id="mLink" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:440px">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 style="margin:0;font-size:15px"><i class="fa-solid fa-link" style="color:var(--p);margin-right:8px"></i>Collega account utente</h3>
      <button onclick="closeModal('mLink')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <p id="mLinkName" style="font-weight:700;margin-bottom:16px;color:var(--p)"></p>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="link_user">
      <input type="hidden" name="employee_id" id="link_emp_id">
      <div class="form-group">
        <label>Account da collegare</label>
        <select name="link_user_id">
          <option value="0">— Scollega account esistente —</option>
          <?php foreach($free_users as $fu): ?>
          <option value="<?=$fu['id']?>"><?=h($fu['email'])?> (<?=h($fu['role_name'])?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="background:#e0f2fe;border-left:4px solid #3b82f6;padding:11px 14px;border-radius:0 8px 8px 0;font-size:12px;color:#0369a1;margin-bottom:16px">
        <strong>Nota:</strong> Mostra solo gli account non ancora collegati ad alcun dipendente.
        Per creare un nuovo account vai in <a href="manager_users.php" style="color:inherit;font-weight:700">Gestione utenti</a>.
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:11px">Salva collegamento</button>
        <button type="button" onclick="closeModal('mLink')" class="btn" style="flex:1;justify-content:center;padding:11px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL UPLOAD CV ══════════════════════════════════════════════════════ -->
<div id="mCv" class="modal-overlay" style="z-index:1002">
  <div class="modal-box" style="width:480px">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px;align-items:center">
      <h3 style="margin:0;font-size:15px"><i class="fa-solid fa-file-user" style="color:var(--p);margin-right:8px"></i>Curriculum Vitae</h3>
      <button onclick="closeModal('mCv')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <p id="mCvName" style="font-weight:700;color:var(--p);margin-bottom:14px"></p>

    <!-- CV esistente -->
    <div id="mCvExisting" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:12px 16px;margin-bottom:16px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <div>
          <div style="font-size:12px;font-weight:700;color:#065f46"><i class="fa-solid fa-circle-check" style="margin-right:5px"></i>CV presente</div>
          <div id="mCvFileName" style="font-size:11px;color:var(--muted);margin-top:2px"></div>
        </div>
        <div style="display:flex;gap:6px">
          <a id="mCvDownloadLink" href="#" target="_blank" class="btn btn-sm btn-success">
            <i class="fa-solid fa-download"></i> Scarica
          </a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare il CV?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_cv">
            <input type="hidden" name="employee_id" id="mCvDeleteEmpId">
            <button type="submit" class="btn btn-sm btn-danger" title="Elimina CV">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
    <div id="mCvEmpty" style="display:none;background:#f8fafc;border:1px dashed var(--border);border-radius:9px;padding:14px;text-align:center;font-size:12px;color:var(--muted);margin-bottom:16px">
      <i class="fa-solid fa-file-circle-xmark" style="font-size:24px;display:block;margin-bottom:6px;opacity:.35"></i>
      Nessun CV caricato per questo dipendente
    </div>

    <!-- Upload nuovo CV -->
    <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_cv">
      <input type="hidden" name="employee_id" id="mCvEmpId">
      <div class="form-group">
        <label><?=$e['cv_path']??false ? 'Sostituisci CV' : 'Carica CV'?></label>
        <input type="file" name="cv_file" accept=".pdf,.doc,.docx" required
               style="width:100%;padding:10px;border:2px dashed var(--border);border-radius:8px;background:#fff;font-size:13px">
        <div style="font-size:10px;color:var(--muted);margin-top:4px">PDF, DOC, DOCX — max 10 MB</div>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:11px">
          <i class="fa-solid fa-upload"></i> Carica
        </button>
        <button type="button" onclick="closeModal('mCv')" class="btn" style="flex:1;justify-content:center;padding:11px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL UPLOAD CONTRATTO ═══════════════════════════════════════════════ -->
<div id="mContr" class="modal-overlay" style="z-index:1002">
  <div class="modal-box" style="width:520px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
      <h3 style="margin:0;font-size:16px"><i class="fa-solid fa-file-signature" style="color:#059669;margin-right:8px"></i> Contratto firmato</h3>
      <button onclick="closeModal('mContr')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <p id="mContrName" style="font-weight:700;color:var(--p);margin-bottom:14px"></p>

    <!-- Box versione corrente -->
    <div id="mContrExisting" style="display:none;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:9px;padding:14px;margin-bottom:16px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div id="mContrVersionBadge" style="width:38px;height:38px;border-radius:8px;background:#059669;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex-shrink:0">v1</div>
        <div style="flex:1">
          <div style="font-size:13px;font-weight:700;color:#065f46">Versione corrente</div>
          <div id="mContrInfo" style="font-size:11px;color:#047857"></div>
        </div>
        <a id="mContrDownload" href="#" target="_blank" class="btn btn-sm" style="background:#059669;color:#fff;border:none"><i class="fa-solid fa-download"></i></a>
      </div>
      <div id="mContrVersionsLink" style="font-size:11px;text-align:center;margin-top:6px"></div>
    </div>

    <div id="mContrEmpty" style="display:none;background:#f8fafc;border:1px dashed var(--border);border-radius:9px;padding:14px;text-align:center;font-size:12px;color:var(--muted);margin-bottom:16px">
      Nessun contratto firmato caricato.
    </div>

    <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_contract">
      <input type="hidden" name="employee_id" id="mContrEmpId">

      <div id="mContrUploadTitle" style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px"><i class="fa-solid fa-cloud-arrow-up"></i> Carica contratto firmato</div>

      <div class="form-group">
        <label>File contratto *</label>
        <input type="file" name="contract_file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip">
        <div style="font-size:10px;color:var(--muted);margin-top:4px">PDF, DOC, DOCX, JPG, PNG, ZIP — max 15 MB</div>
      </div>
      <div class="grid-2">
        <div class="form-group" style="margin:0"><label>Data firma</label><input type="date" name="signed_date"></div>
        <div class="form-group" style="margin:0"><label>Titolo versione</label><input type="text" name="contract_title" placeholder="Es. Contratto base, Addendum..."></div>
      </div>
      <div class="form-group"><label>Note</label><input type="text" name="contract_notes" placeholder="Note opzionali sulla versione"></div>

      <div id="mContrWarning" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:11px;color:#92400e;margin-bottom:12px">
        <i class="fa-solid fa-circle-info"></i> Caricando un nuovo file la versione corrente verrà <strong>archiviata automaticamente</strong> ma resterà accessibile dallo storico.
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px"><i class="fa-solid fa-upload"></i> Carica contratto</button>
        <button type="button" onclick="closeModal('mContr')" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- v5: Fallback jQuery + DataTables se non caricati altrove -->
<script>
if (typeof window.jQuery === 'undefined') {
  document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"><\/script>');
}
</script>
<script>
if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.DataTable === 'undefined') {
  document.write('<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"><\/script>');
}
</script>

<!-- v5: ScopeFilter helper (cascading dropdown azienda → sede). 
     Necessario per openModal in modalità modifica. -->
<script src="scope_filters.js"></script>

<script>
// v1.6.7 FIX: cascade Azienda→Sede ROBUSTA con fallback locale.
// Le sedi sono pre-cariche dal PHP, il select viene popolato istantaneamente
// senza AJAX. Risolve casi di sessione scaduta / errore api_filters / cache.
window.ALL_LOCATIONS = <?= json_encode($all_locations, JSON_UNESCAPED_UNICODE) ?>;

function fillLocationsFor(companyId, targetSelectId, selectedLocationId) {
  const sel = document.getElementById(targetSelectId);
  if (!sel) return;
  const cid = parseInt(companyId, 10) || 0;
  const sid = parseInt(selectedLocationId, 10) || 0;

  // Reset
  sel.innerHTML = '';
  const ph = document.createElement('option');
  ph.value = '';
  ph.textContent = cid ? '— Seleziona sede —' : '— Seleziona prima l\'azienda —';
  sel.appendChild(ph);

  if (!cid) return;

  const filtered = (window.ALL_LOCATIONS || []).filter(l => parseInt(l.company_id, 10) === cid);
  if (filtered.length === 0) {
    const noOpt = document.createElement('option');
    noOpt.value = '';
    noOpt.disabled = true;
    noOpt.textContent = '— Nessuna sede registrata per questa azienda —';
    sel.appendChild(noOpt);
    return;
  }
  filtered.forEach(l => {
    const opt = document.createElement('option');
    opt.value = l.id;
    opt.textContent = l.location_name;
    if (sid && parseInt(l.id, 10) === sid) opt.selected = true;
    sel.appendChild(opt);
  });
}

// Bind onChange diretto sul select azienda del modal dipendente
document.addEventListener('DOMContentLoaded', function() {
  const coSel = document.getElementById('e_co');
  if (coSel) {
    coSel.addEventListener('change', function() {
      fillLocationsFor(this.value, 'e_loc', 0);
    });
  }
  // Lo stesso anche per i filtri della lista (se esistono)
  const filterCoSel = document.getElementById('f_co');
  if (filterCoSel) {
    filterCoSel.addEventListener('change', function() {
      fillLocationsFor(this.value, 'f_loc', filterCoSel.dataset.preselectLoc || 0);
    });
  }
});

$('#tEmp').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[0,'asc']]});

function openModal(e=null){
  document.getElementById('empForm').reset();
  document.getElementById('e_id').value = 0;
  document.getElementById('mEmpTitle').textContent = 'Nuovo dipendente';
  // Reset esplicito della select Sede (form.reset() non basta perché viene popolata dinamicamente)
  const locSel = document.getElementById('e_loc');
  if (locSel) {
    locSel.innerHTML = '<option value="">— Seleziona prima l\'azienda —</option>';
  }
  if(e){
    document.getElementById('e_id').value = e.id;
    document.getElementById('mEmpTitle').textContent = 'Modifica: ' + e.first_name + ' ' + e.last_name;
    const map = {fn:'first_name',ln:'last_name',ec:'employee_code',cf:'fiscal_code',
                 dob:'date_of_birth',
                 ph:'phone',pp:'phone_personal',pe:'personal_email',be:'business_email',
                 credly:'credly_url',linkedin:'linkedin_url',
                 jt:'job_title',dep:'department_id',
                 ct:'contract_type',co:'company_id',wm:'work_mode_id',
                 hd:'hire_date',ed:'end_date',st:'status',bio:'bio',sk:'technical_skills',
                 ss:'soft_skills',notes:'notes',
                 // v1.7.46: nuovi campi
                 gen:'gender',ccnl:'ccnl',pt:'part_time',ptp:'part_time_pct',
                 appr:'apprenticeship_end_date',qual:'qualification',lvl:'contract_level',
                 ral:'ral',badge:'badge_number',badgedate:'badge_issue_date',
                 // v1.7.48: compensation (campi popolati solo se autorizzato)
                 premio:'premio_concordato',kmc:'km_concordati',kme:'km_effettivi',
                 fs:'fuori_sede',fsa:'fuori_sede_amount',
                 // v1.7.54: classificazione finanziaria
                 clf:'classificazione_finanziaria'};
    for(const[f,k] of Object.entries(map)){
      const el = document.getElementById('e_'+f);
      if(el && e[k]!==null && e[k]!==undefined) el.value = e[k];
    }
    // v1.6.7: cascade locale (no AJAX) - più robusta
    if(e.company_id){
      fillLocationsFor(e.company_id, 'e_loc', e.location_id || 0);
    }
  }
  document.getElementById('mEmp').style.display = 'flex';
}

function openLinkModal(emp_id, name){
  document.getElementById('link_emp_id').value = emp_id;
  document.getElementById('mLinkName').textContent = name;
  document.getElementById('mLink').style.display = 'flex';
}

function openCvModal(emp_id, name, cv_path) {
  document.getElementById('mCvEmpId').value      = emp_id;
  document.getElementById('mCvDeleteEmpId').value = emp_id;
  document.getElementById('mCvName').textContent  = name;

  const existing = document.getElementById('mCvExisting');
  const empty    = document.getElementById('mCvEmpty');

  if (cv_path) {
    existing.style.display = 'block';
    empty.style.display    = 'none';
    document.getElementById('mCvFileName').textContent  = cv_path;
    document.getElementById('mCvDownloadLink').href =
      'download.php?f=' + encodeURIComponent('cv_dipendenti/' + cv_path);
  } else {
    existing.style.display = 'none';
    empty.style.display    = 'block';
  }
  document.getElementById('mCv').style.display = 'flex';
}

function openContrModal(emp_id, name, contr) {
  document.getElementById('mContrEmpId').value = emp_id;
  document.getElementById('mContrName').textContent = name;

  const existing = document.getElementById('mContrExisting');
  const empty    = document.getElementById('mContrEmpty');
  const warning  = document.getElementById('mContrWarning');
  const uplTitle = document.getElementById('mContrUploadTitle');

  if (contr && contr.file_name) {
    existing.style.display = 'block';
    empty.style.display    = 'none';
    warning.style.display  = 'block';
    uplTitle.innerHTML     = '<i class="fa-solid fa-arrow-up"></i> Carica nuova versione';

    document.getElementById('mContrVersionBadge').textContent = 'v' + contr.version;
    var info = '<i class="fa-solid fa-file" style="margin-right:4px"></i>' + (contr.file_name || '');
    if (contr.signed_date) info += ' &middot; <i class="fa-solid fa-pen-nib"></i> Firmato: ' + contr.signed_date;
    if (contr.created_at) info += ' &middot; Caricato: ' + contr.created_at.substring(0,10);
    document.getElementById('mContrInfo').innerHTML = info;
    document.getElementById('mContrDownload').href = 'uploads/documenti/' + contr.file_name;

    if (parseInt(contr.tot_versions) > 1) {
      document.getElementById('mContrVersionsLink').innerHTML =
        '<a href="documenti.php?employee_id=' + emp_id + '&f_type=contratto" style="color:#059669;font-weight:600"><i class="fa-solid fa-clock-rotate-left"></i> Vedi storico ' + contr.tot_versions + ' versioni</a>';
    } else {
      document.getElementById('mContrVersionsLink').innerHTML = '';
    }
  } else {
    existing.style.display = 'none';
    empty.style.display    = 'block';
    warning.style.display  = 'none';
    uplTitle.innerHTML     = '<i class="fa-solid fa-cloud-arrow-up"></i> Carica contratto firmato';
  }
  document.getElementById('mContr').style.display = 'flex';
}

</script>
<?php require_once('footer.php'); ?>
