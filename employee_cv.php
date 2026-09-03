<?php
/**
 * PortalManager 1.6.0 — employee_cv.php
 *
 * Generazione CV in formato Europass (DOCX editabile).
 * - UI per selezionare quali sezioni includere
 * - Persistenza preferenze per dipendente
 * - Generazione DOCX nativa (OOXML, senza dipendenze esterne)
 *
 * URL: employee_cv.php?id=N
 */

require_once('access_control.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$u_emp  = (int)($_SESSION['employee_id'] ?? 0);
$emp_id = (int)($_GET['id'] ?? 0);

if (!$emp_id) { header('Location: manage_employees.php'); exit; }

// Permessi: HR/Admin tutti, dipendente solo se stesso
$is_self  = ($emp_id === $u_emp);
$can_edit = in_array($u_role, [1, 2], true) || $is_self;
$can_view = $can_edit || in_array($u_role, [4, 5], true);
if (!$can_view) { http_response_code(403); die('Accesso negato'); }

// ─────────────────────────────────────────────────────────────────────
// Carica dipendente + dati correlati
// ─────────────────────────────────────────────────────────────────────
$st = $pdo->prepare(
    "SELECT e.*,
            co.name AS company_name,
            loc.location_name, loc.address AS loc_address,
            wm.name AS mode_name
       FROM employees e
       LEFT JOIN companies co          ON co.id = e.company_id
       LEFT JOIN company_locations loc ON loc.id = e.location_id
       LEFT JOIN work_modes wm         ON wm.id = e.work_mode_id
      WHERE e.id = ?"
);
$st->execute([$emp_id]);
$emp = $st->fetch();
if (!$emp) { header('Location: manage_employees.php'); exit; }

// Preferenze persistite
$prefs_q = $pdo->prepare("SELECT * FROM emp_cv_preferences WHERE employee_id = ?");
$prefs_q->execute([$emp_id]);
$prefs = $prefs_q->fetch() ?: [
    'include_personal'         => 1, 'include_photo'    => 1,
    'include_experience'       => 1, 'include_education' => 1,
    'include_technical_skills' => 1, 'include_soft_skills' => 1,
    'include_certifications'   => 1, 'include_languages' => 1,
    'include_devices'          => 0, 'include_bio'      => 1,
    'cv_template'              => 'classic',
    'cv_anonymize'             => 0,
];
// Fallback per record esistenti che non hanno le nuove colonne
if (!isset($prefs['cv_template']))  $prefs['cv_template']  = 'classic';
if (!isset($prefs['cv_anonymize'])) $prefs['cv_anonymize'] = 0;

// Cartella upload foto
$photo_dir = APP_ROOT . '/uploads/cv_photos/';
if (!is_dir($photo_dir)) @mkdir($photo_dir, 0755, true);

$msg = '';

// ─────────────────────────────────────────────────────────────────────
// POST: gestione foto + lingue + preferenze
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    try {
        // Upload foto profilo
        if ($action === 'upload_photo' && !empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['photo']['size'] > 2 * 1024 * 1024) throw new Exception('Foto troppo grande (max 2MB).');
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) throw new Exception('Formato non valido (JPG, PNG).');
            $fname = "emp_{$emp_id}_photo_" . time() . ".$ext";
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $photo_dir . $fname)) {
                // Rimuovi vecchia
                if ($emp['photo_path'] && is_file($photo_dir . $emp['photo_path'])) {
                    @unlink($photo_dir . $emp['photo_path']);
                }
                $pdo->prepare("UPDATE employees SET photo_path=? WHERE id=?")->execute([$fname, $emp_id]);
                $emp['photo_path'] = $fname;
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Foto caricata.</div>";
            }
        }

        // Rimuovi foto
        elseif ($action === 'delete_photo') {
            if ($emp['photo_path'] && is_file($photo_dir . $emp['photo_path'])) {
                @unlink($photo_dir . $emp['photo_path']);
            }
            $pdo->prepare("UPDATE employees SET photo_path=NULL WHERE id=?")->execute([$emp_id]);
            $emp['photo_path'] = null;
            $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Foto rimossa.</div>";
        }

        // Aggiungi lingua
        elseif ($action === 'add_language') {
            $pdo->prepare(
                "INSERT INTO emp_languages
                    (employee_id, language_name, mother_tongue,
                     level_listening, level_reading,
                     level_spoken_interaction, level_spoken_production,
                     level_writing, certification, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $emp_id,
                trim($_POST['language_name'] ?? '') ?: 'Sconosciuta',
                !empty($_POST['mother_tongue']) ? 1 : 0,
                $_POST['level_listening'] ?: null,
                $_POST['level_reading'] ?: null,
                $_POST['level_spoken_interaction'] ?: null,
                $_POST['level_spoken_production'] ?: null,
                $_POST['level_writing'] ?: null,
                trim($_POST['certification'] ?? '') ?: null,
                trim($_POST['notes'] ?? '') ?: null,
                $u_id,
            ]);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Lingua aggiunta.</div>";
        }

        elseif ($action === 'delete_language') {
            $lid = (int)$_POST['lang_id'];
            $pdo->prepare("DELETE FROM emp_languages WHERE id=? AND employee_id=?")
                ->execute([$lid, $emp_id]);
            $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Lingua rimossa.</div>";
        }

        // Salva preferenze + genera DOCX
        elseif ($action === 'generate_cv') {
            // Valida modello CV
            $tpl = $_POST['cv_template'] ?? 'classic';
            if (!in_array($tpl, ['classic','modern','technical','europass'], true)) $tpl = 'classic';

            $new_prefs = [
                'include_personal'         => isset($_POST['inc_personal']) ? 1 : 0,
                'include_photo'            => isset($_POST['inc_photo']) ? 1 : 0,
                'include_experience'       => isset($_POST['inc_experience']) ? 1 : 0,
                'include_education'        => isset($_POST['inc_education']) ? 1 : 0,
                'include_technical_skills' => isset($_POST['inc_tech_skills']) ? 1 : 0,
                'include_soft_skills'      => isset($_POST['inc_soft_skills']) ? 1 : 0,
                'include_certifications'   => isset($_POST['inc_certifications']) ? 1 : 0,
                'include_languages'        => isset($_POST['inc_languages']) ? 1 : 0,
                'include_devices'          => isset($_POST['inc_devices']) ? 1 : 0,
                'include_bio'              => isset($_POST['inc_bio']) ? 1 : 0,
                'cv_template'              => $tpl,
                'cv_anonymize'             => isset($_POST['cv_anonymize']) ? 1 : 0,
            ];

            // Save_default: se l'utente ha spuntato "Salva come default", persisti
            // altrimenti aggiorna solo le checkbox sezioni (compatibilità retro)
            $save_default = isset($_POST['save_as_default']) ? 1 : 0;

            try {
                $pdo->prepare(
                    "INSERT INTO emp_cv_preferences
                      (employee_id, include_personal, include_photo, include_experience,
                       include_education, include_technical_skills, include_soft_skills,
                       include_certifications, include_languages, include_devices, include_bio,
                       cv_template, cv_anonymize)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       include_personal=VALUES(include_personal),
                       include_photo=VALUES(include_photo),
                       include_experience=VALUES(include_experience),
                       include_education=VALUES(include_education),
                       include_technical_skills=VALUES(include_technical_skills),
                       include_soft_skills=VALUES(include_soft_skills),
                       include_certifications=VALUES(include_certifications),
                       include_languages=VALUES(include_languages),
                       include_devices=VALUES(include_devices),
                       include_bio=VALUES(include_bio)"
                    . ($save_default ? ",
                       cv_template=VALUES(cv_template),
                       cv_anonymize=VALUES(cv_anonymize)" : "")
                )->execute([
                    $emp_id,
                    $new_prefs['include_personal'], $new_prefs['include_photo'],
                    $new_prefs['include_experience'], $new_prefs['include_education'],
                    $new_prefs['include_technical_skills'], $new_prefs['include_soft_skills'],
                    $new_prefs['include_certifications'], $new_prefs['include_languages'],
                    $new_prefs['include_devices'], $new_prefs['include_bio'],
                    $new_prefs['cv_template'], $new_prefs['cv_anonymize'],
                ]);
            } catch (Throwable $e) {
                // Fallback per DB pre-migration (senza colonne cv_template/cv_anonymize)
                $pdo->prepare(
                    "INSERT INTO emp_cv_preferences
                      (employee_id, include_personal, include_photo, include_experience,
                       include_education, include_technical_skills, include_soft_skills,
                       include_certifications, include_languages, include_devices, include_bio)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       include_personal=VALUES(include_personal),
                       include_photo=VALUES(include_photo),
                       include_experience=VALUES(include_experience),
                       include_education=VALUES(include_education),
                       include_technical_skills=VALUES(include_technical_skills),
                       include_soft_skills=VALUES(include_soft_skills),
                       include_certifications=VALUES(include_certifications),
                       include_languages=VALUES(include_languages),
                       include_devices=VALUES(include_devices),
                       include_bio=VALUES(include_bio)"
                )->execute([
                    $emp_id,
                    $new_prefs['include_personal'], $new_prefs['include_photo'],
                    $new_prefs['include_experience'], $new_prefs['include_education'],
                    $new_prefs['include_technical_skills'], $new_prefs['include_soft_skills'],
                    $new_prefs['include_certifications'], $new_prefs['include_languages'],
                    $new_prefs['include_devices'], $new_prefs['include_bio'],
                ]);
            }

            if (function_exists('write_log')) {
                $anon = $new_prefs['cv_anonymize'] ? ' ANONIMO' : '';
                write_log('CV', 'success', "CV generato emp=$emp_id tpl={$tpl}$anon", $u_id);
            }

            // Genera DOCX e download
            generate_europass_docx($pdo, $emp, $new_prefs, $photo_dir);
            exit;
        }
    } catch (Exception $e) {
        $msg = "<div class='alert alert-danger'>" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Carica dati per UI
$lang_q = $pdo->prepare("SELECT * FROM emp_languages WHERE employee_id=? ORDER BY mother_tongue DESC, id ASC");
$lang_q->execute([$emp_id]);
$languages = $lang_q->fetchAll();

// ═════════════════════════════════════════════════════════════════════
// GENERATORE DOCX EUROPASS (OOXML nativo, no dipendenze)
// ═════════════════════════════════════════════════════════════════════
function generate_europass_docx(PDO $pdo, array $emp, array $prefs, string $photo_dir): void {
    // ── Carica template config (colori, intestazione) ──
    $template  = $prefs['cv_template'] ?? 'classic';
    $anonymize = !empty($prefs['cv_anonymize']);
    $cfg = cv_template_config($template);

    // ── Anonimizzazione dati sensibili ──
    if ($anonymize) {
        $emp = cv_anonymize_data($emp);
        $prefs['include_photo'] = 0;
    }

    // ── Carica dati correlati ──
    $certs = [];
    if (!empty($prefs['include_certifications'])) {
        $q = $pdo->prepare(
            "SELECT uc.*, c.name AS cert_name, c.code AS cert_code, b.name AS brand_name
               FROM user_certifications uc
               JOIN certifications c ON c.id = uc.certification_id
               JOIN brands b ON b.id = c.brand_id
              WHERE uc.employee_id = ?
              ORDER BY (uc.expiry_date IS NULL), uc.issue_date DESC"
        );
        $q->execute([$emp['id']]);
        $certs = $q->fetchAll();
    }

    $languages = [];
    if (!empty($prefs['include_languages'])) {
        try {
            $q = $pdo->prepare("SELECT * FROM emp_languages WHERE employee_id=? ORDER BY mother_tongue DESC, id ASC");
            $q->execute([$emp['id']]);
            $languages = $q->fetchAll();
        } catch (Throwable $e) {}
    }

    $devices = [];
    if (!empty($prefs['include_devices']) && !$anonymize) {
        $tables_lookup = [
            'phone'    => ["emp_devices_phone",    "Telefoni aziendali",  ['brand','model']],
            'sim'      => ["emp_devices_sim",      "SIM aziendali",       ['sim_type','phone_number','operator']],
            'notebook' => ["emp_devices_notebook", "Notebook aziendali",  ['brand','model','os']],
            'vehicle'  => ["emp_devices_vehicle",  "Veicoli aziendali",   ['brand','model','plate']],
        ];
        foreach ($tables_lookup as [$tbl, $label, $fields]) {
            try {
                $q = $pdo->prepare("SELECT * FROM $tbl WHERE employee_id=? AND returned_at IS NULL ORDER BY assigned_at DESC");
                $q->execute([$emp['id']]);
                $rows = $q->fetchAll();
                if ($rows) $devices[$label] = ['rows' => $rows, 'fields' => $fields];
            } catch (Throwable $e) {}
        }
    }

    // Foto presente?
    $has_photo = !empty($prefs['include_photo']) && !empty($emp['photo_path']) && is_file($photo_dir . $emp['photo_path']);
    $photo_rid = $has_photo ? 'rId100' : null;

    $ctx = [
        'emp'        => $emp,
        'prefs'      => $prefs,
        'cfg'        => $cfg,
        'anonymize'  => $anonymize,
        'certs'      => $certs,
        'languages'  => $languages,
        'devices'    => $devices,
        'has_photo'  => $has_photo,
        'photo_rid'  => $photo_rid,
    ];

    // ── DISPATCH al renderer specifico del template ──
    switch ($template) {
        case 'modern':    $w = cv_render_modern($ctx); break;
        case 'technical': $w = cv_render_technical($ctx); break;
        case 'europass':  $w = cv_render_europass($ctx); break;
        case 'classic':
        default:          $w = cv_render_classic($ctx); break;
    }

    // ── Footer comune ──
    $footer_text = 'Documento generato da PortalManager · ' . date('d/m/Y H:i');
    if ($anonymize) $footer_text .= ' · CV ANONIMIZZATO';
    $w .= cv_paragraph(
        cv_run($footer_text, ['size' => 14, 'italic' => true, 'color' => '999999']),
        ['align' => 'right', 'spacing' => 360]
    );

    // ── Assembla document.xml ──
    $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<w:body>' . $w
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/>'
        . '</w:sectPr>'
        . '</w:body></w:document>';

    // ── Crea ZIP DOCX ──
    $tmpfile = tempnam(sys_get_temp_dir(), 'cv_');
    $zip = new ZipArchive();
    if ($zip->open($tmpfile, ZipArchive::OVERWRITE) !== true) {
        throw new Exception("Impossibile creare DOCX");
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
        . '<Default Extension="jpg" ContentType="image/jpeg"/>'
        . '<Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>');

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    if ($has_photo) {
        $photo_ext = strtolower(pathinfo($emp['photo_path'], PATHINFO_EXTENSION));
        $rels .= '<Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/photo.' . $photo_ext . '"/>';
    }
    $rels .= '</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $rels);

    $zip->addFromString('word/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $cfg['font'] . '" w:hAnsi="' . $cfg['font'] . '"/><w:sz w:val="20"/></w:rPr></w:rPrDefault></w:docDefaults>'
        . '</w:styles>');

    $zip->addFromString('word/document.xml', $document_xml);

    if ($has_photo) {
        $photo_ext = strtolower(pathinfo($emp['photo_path'], PATHINFO_EXTENSION));
        $zip->addFile($photo_dir . $emp['photo_path'], 'word/media/photo.' . $photo_ext);
    }

    $zip->close();

    $name_part = $anonymize ? ('Candidato_' . substr(md5($emp['id'] . $template), 0, 6))
                            : (($emp['last_name'] ?? 'dipendente') . '_' . ($emp['first_name'] ?? ''));
    $tpl_label = ['classic'=>'Classico','modern'=>'Moderno','technical'=>'Tecnico','europass'=>'Europass'][$template] ?? 'Classico';
    $filename = "CV_Europass_{$tpl_label}_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $name_part) . "_" . date('Ymd') . ".docx";

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpfile));
    readfile($tmpfile);
    @unlink($tmpfile);
}

// ═════════════════════════════════════════════════════════════════════
// RENDER CLASSICO — Layout Europass tradizionale 2 colonne
//   Sinistra (30%): foto + dati personali + lingue
//   Destra (70%): bio + esperienze + istruzione + competenze + certificazioni
//   Header: banda blu piena larghezza in alto, font Arial serif-like
// ═════════════════════════════════════════════════════════════════════
function cv_render_classic(array $ctx): string {
    $emp = $ctx['emp']; $prefs = $ctx['prefs']; $cfg = $ctx['cfg'];
    $anonymize = $ctx['anonymize']; $certs = $ctx['certs']; $languages = $ctx['languages'];
    $devices = $ctx['devices']; $has_photo = $ctx['has_photo']; $photo_rid = $ctx['photo_rid'];

    $w = '';

    // ── HEADER: banda blu piena larghezza con "Curriculum Vitae · Europass" ──
    $w .= cv_paragraph(
        cv_run('Curriculum Vitae', ['size' => 36, 'bold' => true, 'color' => 'FFFFFF']),
        ['shading' => $cfg['primary'], 'align' => 'left', 'spacing' => 240]
    );
    $w .= cv_paragraph(
        cv_run('Europass', ['size' => 20, 'italic' => true, 'color' => 'FFFFFF']),
        ['shading' => $cfg['primary'], 'align' => 'left', 'spacing' => 60]
    );
    $w .= cv_empty_paragraph();

    // ── Nome candidato in grande ──
    if (!empty($emp['first_name']) || !empty($emp['last_name'])) {
        $name = $anonymize
            ? ('CANDIDATO #' . str_pad((string)($emp['id'] ?? 0), 4, '0', STR_PAD_LEFT))
            : strtoupper(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
        $w .= cv_paragraph(
            cv_run($name, ['size' => 32, 'bold' => true, 'color' => $cfg['primary']]),
            ['spacing' => 120]
        );
    }
    if (!empty($emp['job_title']) || !empty($emp['department'])) {
        $role = trim(($emp['job_title'] ?? '') . ($emp['department'] ? ' · ' . $emp['department'] : ''));
        $w .= cv_paragraph(
            cv_run($role, ['size' => 22, 'italic' => true, 'color' => '475569']),
            ['spacing' => 60]
        );
    }
    $w .= cv_empty_paragraph();

    // ── TABELLA PRINCIPALE 2 COLONNE (30/70) ──
    $left = cv_classic_left_column($ctx);
    $right = cv_classic_right_column($ctx);

    $w .= '<w:tbl>'
        . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders>'
        . '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>'
        . '<w:insideH w:val="nil"/><w:insideV w:val="single" w:sz="4" w:color="' . $cfg['primary'] . '"/>'
        . '</w:tblBorders></w:tblPr>'
        . '<w:tblGrid><w:gridCol w:w="2700"/><w:gridCol w:w="6300"/></w:tblGrid>'
        . '<w:tr>'
        . '<w:tc><w:tcPr><w:tcW w:w="2700" w:type="dxa"/></w:tcPr>'
        . $left
        . '</w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="6300" w:type="dxa"/></w:tcPr>'
        . $right
        . '</w:tc>'
        . '</w:tr></w:tbl>';

    return $w;
}

function cv_classic_left_column(array $ctx): string {
    $emp = $ctx['emp']; $cfg = $ctx['cfg']; $anonymize = $ctx['anonymize'];
    $has_photo = $ctx['has_photo']; $photo_rid = $ctx['photo_rid'];
    $w = '';

    // Foto in alto
    if ($has_photo) {
        $w .= cv_paragraph(cv_picture_run($photo_rid, 1500000, 2000000), ['align' => 'center']);
        $w .= cv_empty_paragraph();
    }

    // Sezione "Informazioni personali" con label verticali piccole
    $w .= cv_paragraph(
        cv_run('CONTATTI', ['size' => 18, 'bold' => true, 'color' => $cfg['primary']]),
        ['spacing' => 120]
    );
    $w .= cv_classic_left_field('Email', $emp['business_email'] ?? $emp['personal_email'] ?? null);
    $w .= cv_classic_left_field('Telefono', $emp['phone'] ?? null);
    $w .= cv_classic_left_field('Tel. personale', $emp['phone_personal'] ?? null);
    $w .= cv_classic_left_field('Indirizzo', $emp['address'] ?? null);
    $w .= cv_classic_left_field('Luogo nascita', $emp['city_of_birth'] ?? null);
    $w .= cv_classic_left_field('Data nascita', $emp['date_of_birth'] ?? null);
    if (!$anonymize) {
        $w .= cv_classic_left_field('Codice fiscale', $emp['fiscal_code'] ?? null);
        $w .= cv_classic_left_field('LinkedIn', $emp['linkedin_url'] ?? null);
        $w .= cv_classic_left_field('Credly', $emp['credly_url'] ?? null);
    }

    // Lingue compatte
    if (!empty($ctx['languages']) && !empty($ctx['prefs']['include_languages'])) {
        $w .= cv_empty_paragraph();
        $w .= cv_paragraph(
            cv_run('LINGUE', ['size' => 18, 'bold' => true, 'color' => $cfg['primary']]),
            ['spacing' => 120]
        );
        foreach ($ctx['languages'] as $lng) {
            $lvl = $lng['mother_tongue'] ? 'Madrelingua' : trim(
                ($lng['level_listening'] ?? '') . '/' .
                ($lng['level_reading'] ?? '') . '/' .
                ($lng['level_spoken_interaction'] ?? '') . '/' .
                ($lng['level_writing'] ?? ''), '/'
            );
            $w .= cv_paragraph(
                cv_run($lng['language_name'], ['bold' => true, 'size' => 18]) .
                cv_run(' ' . $lvl, ['size' => 16, 'color' => '64748b'])
            );
        }
    }

    return $w;
}

function cv_classic_left_field(string $label, ?string $value): string {
    if (empty($value)) return '';
    return cv_paragraph(
        cv_run($label, ['size' => 14, 'bold' => true, 'color' => '64748b'])
    ) . cv_paragraph(
        cv_run($value, ['size' => 16]),
        ['spacing' => 0]
    );
}

function cv_classic_right_column(array $ctx): string {
    $emp = $ctx['emp']; $prefs = $ctx['prefs']; $cfg = $ctx['cfg'];
    $anonymize = $ctx['anonymize']; $certs = $ctx['certs']; $devices = $ctx['devices'];
    $w = '';

    // Bio
    if (!empty($prefs['include_bio']) && !empty($emp['bio'])) {
        $w .= cv_section_header('Profilo professionale', $cfg);
        $w .= cv_paragraph(cv_run($emp['bio'], ['size' => 20]));
        $w .= cv_empty_paragraph();
    }

    // Esperienza
    if (!empty($prefs['include_experience'])) {
        $w .= cv_section_header('Esperienza professionale', $cfg);
        if (!empty($emp['hire_date'])) {
            $period = date('d/m/Y', strtotime($emp['hire_date']));
            $period .= ' — ' . (!empty($emp['end_date']) ? date('d/m/Y', strtotime($emp['end_date'])) : 'in corso');
            $w .= cv_paragraph(cv_run($period, ['bold' => true, 'color' => $cfg['primary'], 'size' => 18]));
        }
        if (!empty($emp['job_title'])) {
            $w .= cv_paragraph(cv_run($emp['job_title'], ['bold' => true, 'size' => 22]));
        }
        if (!$anonymize && !empty($emp['company_name'])) {
            $loc = $emp['company_name'];
            if (!empty($emp['location_name'])) $loc .= ' — ' . $emp['location_name'];
            $w .= cv_paragraph(cv_run($loc, ['italic' => true, 'color' => '475569', 'size' => 18]));
        }
        if (!empty($emp['contract_type']) || !empty($emp['department'])) {
            $bits = array_filter([$emp['contract_type'] ?? null, $emp['department'] ?? null, $emp['mode_name'] ?? null]);
            if ($bits) $w .= cv_paragraph(cv_run(implode(' · ', $bits), ['size' => 16, 'color' => '64748b']));
        }
        $w .= cv_empty_paragraph();
    }

    // Istruzione
    if (!empty($prefs['include_education'])) {
        $w .= cv_section_header('Istruzione e formazione', $cfg);
        $edu_bits = [];
        if (!empty($emp['education_year']))      $edu_bits[] = cv_run($emp['education_year'], ['bold' => true, 'color' => $cfg['primary'], 'size' => 18]);
        if (!empty($emp['education_level'])) {
            $edu_lev = $emp['education_level'];
            if (!empty($emp['education_field'])) $edu_lev .= ' in ' . $emp['education_field'];
            $w .= cv_paragraph(cv_run($edu_lev, ['bold' => true, 'size' => 22]));
        }
        if (!empty($emp['education_institute'])) {
            $w .= cv_paragraph(cv_run($emp['education_institute'], ['italic' => true, 'size' => 18]));
        }
        if (!empty($emp['education_year'])) {
            $w .= cv_paragraph(cv_run('Anno: ' . $emp['education_year'], ['size' => 16, 'color' => '64748b']));
        }
        $w .= cv_empty_paragraph();
    }

    // Competenze tecniche
    if (!empty($prefs['include_technical_skills']) && !empty($emp['technical_skills'])) {
        $w .= cv_section_header('Competenze tecniche', $cfg);
        $skills = array_filter(array_map('trim', explode(',', $emp['technical_skills'])));
        foreach ($skills as $sk) {
            $w .= cv_paragraph(cv_run('• ' . $sk, ['size' => 18]), ['indent' => 240]);
        }
        $w .= cv_empty_paragraph();
    }

    // Soft skills
    if (!empty($prefs['include_soft_skills']) && !empty($emp['soft_skills'])) {
        $w .= cv_section_header('Competenze trasversali', $cfg);
        $softs = array_filter(array_map('trim', explode(',', $emp['soft_skills'])));
        foreach ($softs as $sk) {
            $w .= cv_paragraph(cv_run('• ' . $sk, ['size' => 18]), ['indent' => 240]);
        }
        $w .= cv_empty_paragraph();
    }

    // Certificazioni
    if (!empty($prefs['include_certifications']) && !empty($certs)) {
        $w .= cv_section_header('Certificazioni (' . count($certs) . ')', $cfg);
        foreach ($certs as $c) {
            $line = $c['cert_name'];
            if (!empty($c['cert_code'])) $line .= ' (' . $c['cert_code'] . ')';
            $w .= cv_paragraph(
                cv_run($line, ['bold' => true, 'size' => 18]) .
                cv_run(' — ' . $c['brand_name'], ['size' => 16, 'color' => '475569'])
            );
            $sub = [];
            if (!empty($c['issue_date']))  $sub[] = 'Conseguita: ' . date('d/m/Y', strtotime($c['issue_date']));
            if (!empty($c['expiry_date'])) $sub[] = 'Scade: ' . date('d/m/Y', strtotime($c['expiry_date']));
            if ($sub) $w .= cv_paragraph(cv_run(implode(' · ', $sub), ['size' => 14, 'color' => '64748b']));
        }
        $w .= cv_empty_paragraph();
    }

    // Devices
    if (!empty($prefs['include_devices']) && !empty($devices) && !$anonymize) {
        $w .= cv_section_header('Dotazioni aziendali', $cfg);
        foreach ($devices as $label => $info) {
            $w .= cv_paragraph(cv_run($label . ':', ['bold' => true, 'size' => 18]));
            foreach ($info['rows'] as $r) {
                $parts = [];
                foreach ($info['fields'] as $f) if (!empty($r[$f])) $parts[] = $r[$f];
                $w .= cv_paragraph(cv_run('• ' . implode(' · ', $parts), ['size' => 16]), ['indent' => 240]);
            }
        }
    }

    return $w;
}

// ═════════════════════════════════════════════════════════════════════
// RENDER MODERNO — Layout 1 colonna pulito, header con nome enorme,
//   sezioni con barra laterale spessa colorata.
//   Stile editoriale, font Calibri sans-serif, dati personali in chip orizzontali.
// ═════════════════════════════════════════════════════════════════════
function cv_render_modern(array $ctx): string {
    $emp = $ctx['emp']; $prefs = $ctx['prefs']; $cfg = $ctx['cfg'];
    $anonymize = $ctx['anonymize']; $certs = $ctx['certs']; $languages = $ctx['languages'];
    $devices = $ctx['devices']; $has_photo = $ctx['has_photo']; $photo_rid = $ctx['photo_rid'];

    $w = '';

    // ── HEADER GIGANTE: nome candidato a tutta pagina ──
    $name = $anonymize
        ? ('CANDIDATO #' . str_pad((string)($emp['id'] ?? 0), 4, '0', STR_PAD_LEFT))
        : strtoupper(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));

    $w .= cv_paragraph(
        cv_run($name, ['size' => 60, 'bold' => true, 'color' => $cfg['primary']]),
        ['align' => 'center', 'spacing' => 240]
    );
    if (!empty($emp['job_title'])) {
        $w .= cv_paragraph(
            cv_run(strtoupper($emp['job_title']), ['size' => 22, 'color' => '64748b']),
            ['align' => 'center', 'spacing' => 0]
        );
    }

    // ── Linea decorativa colorata sotto il nome (3 caratteri spessi) ──
    $w .= cv_paragraph(
        cv_run('━━━━━━━━━', ['size' => 32, 'bold' => true, 'color' => $cfg['primary']]),
        ['align' => 'center', 'spacing' => 60]
    );
    $w .= cv_empty_paragraph();

    // ── Chip orizzontali contatti (separati da · ) ──
    $contacts = [];
    if (!empty($emp['business_email'])) $contacts[] = '✉ ' . $emp['business_email'];
    elseif (!empty($emp['personal_email'])) $contacts[] = '✉ ' . $emp['personal_email'];
    if (!empty($emp['phone'])) $contacts[] = '☎ ' . $emp['phone'];
    if (!$anonymize && !empty($emp['linkedin_url'])) $contacts[] = '🔗 LinkedIn';
    if (!$anonymize && !empty($emp['credly_url']))   $contacts[] = '🏅 Credly';
    if (!empty($emp['address']) && !$anonymize)      $contacts[] = '📍 ' . $emp['address'];

    if ($contacts) {
        $w .= cv_paragraph(
            cv_run(implode('   ·   ', $contacts), ['size' => 18, 'color' => '475569']),
            ['align' => 'center', 'spacing' => 120]
        );
        $w .= cv_empty_paragraph();
    }

    // ── Foto centrata (se presente) ──
    if ($has_photo) {
        $w .= cv_paragraph(cv_picture_run($photo_rid, 1800000, 2400000), ['align' => 'center']);
        $w .= cv_empty_paragraph();
    }

    // ── Sezioni con barra laterale colorata (3pt vertical bar) ──
    // Bio in evidenza
    if (!empty($prefs['include_bio']) && !empty($emp['bio'])) {
        $w .= cv_modern_section('Profilo professionale', $cfg);
        $w .= cv_paragraph(cv_run($emp['bio'], ['size' => 22, 'italic' => true]), ['indent' => 240]);
        $w .= cv_empty_paragraph();
    }

    // Certificazioni in evidenza (subito dopo bio per il moderno)
    if (!empty($prefs['include_certifications']) && !empty($certs)) {
        $w .= cv_modern_section('Certificazioni (' . count($certs) . ')', $cfg);
        foreach ($certs as $c) {
            $head = $c['cert_name'];
            if (!empty($c['cert_code'])) $head .= ' · ' . $c['cert_code'];
            $w .= cv_paragraph(
                cv_run($head, ['bold' => true, 'size' => 20, 'color' => $cfg['primary']]),
                ['indent' => 240]
            );
            $sub = $c['brand_name'];
            if (!empty($c['issue_date']))  $sub .= ' · Conseguita ' . date('m/Y', strtotime($c['issue_date']));
            if (!empty($c['expiry_date'])) $sub .= ' · Scade ' . date('m/Y', strtotime($c['expiry_date']));
            else $sub .= ' · Perpetua';
            $w .= cv_paragraph(cv_run($sub, ['size' => 16, 'color' => '64748b']), ['indent' => 240]);
        }
        $w .= cv_empty_paragraph();
    }

    // Competenze tecniche (in colonne pseudo-tag)
    if (!empty($prefs['include_technical_skills']) && !empty($emp['technical_skills'])) {
        $w .= cv_modern_section('Competenze tecniche', $cfg);
        $skills = array_filter(array_map('trim', explode(',', $emp['technical_skills'])));
        $w .= cv_paragraph(
            cv_run(implode('  •  ', $skills), ['size' => 20]),
            ['indent' => 240]
        );
        $w .= cv_empty_paragraph();
    }

    // Esperienza
    if (!empty($prefs['include_experience'])) {
        $w .= cv_modern_section('Esperienza professionale', $cfg);
        if (!empty($emp['hire_date'])) {
            $period = date('d/m/Y', strtotime($emp['hire_date'])) . ' — ' .
                      (!empty($emp['end_date']) ? date('d/m/Y', strtotime($emp['end_date'])) : 'in corso');
            $w .= cv_paragraph(cv_run($period, ['color' => $cfg['primary'], 'bold' => true, 'size' => 18]), ['indent' => 240]);
        }
        if (!empty($emp['job_title'])) {
            $w .= cv_paragraph(cv_run($emp['job_title'], ['bold' => true, 'size' => 24]), ['indent' => 240]);
        }
        if (!$anonymize && !empty($emp['company_name'])) {
            $loc = $emp['company_name'];
            if (!empty($emp['location_name'])) $loc .= ' · ' . $emp['location_name'];
            $w .= cv_paragraph(cv_run($loc, ['italic' => true, 'size' => 20, 'color' => '475569']), ['indent' => 240]);
        }
        $w .= cv_empty_paragraph();
    }

    // Istruzione
    if (!empty($prefs['include_education'])) {
        $w .= cv_modern_section('Istruzione', $cfg);
        if (!empty($emp['education_level'])) {
            $line = $emp['education_level'];
            if (!empty($emp['education_field'])) $line .= ' · ' . $emp['education_field'];
            $w .= cv_paragraph(cv_run($line, ['bold' => true, 'size' => 22]), ['indent' => 240]);
        }
        if (!empty($emp['education_institute'])) {
            $w .= cv_paragraph(cv_run($emp['education_institute'] . (!empty($emp['education_year']) ? ' · ' . $emp['education_year'] : ''),
                ['italic' => true, 'color' => '475569', 'size' => 18]), ['indent' => 240]);
        }
        $w .= cv_empty_paragraph();
    }

    // Lingue (in tabella compatta)
    if (!empty($prefs['include_languages']) && !empty($languages)) {
        $w .= cv_modern_section('Lingue', $cfg);
        foreach ($languages as $lng) {
            $name = $lng['language_name'];
            if ($lng['mother_tongue']) $name .= ' (madrelingua)';
            $lvls = [];
            foreach (['level_listening','level_reading','level_spoken_interaction','level_spoken_production','level_writing'] as $f) {
                if (!empty($lng[$f])) $lvls[] = $lng[$f];
            }
            $w .= cv_paragraph(
                cv_run($name, ['bold' => true, 'size' => 20]) .
                cv_run('  —  ' . implode(' / ', $lvls), ['size' => 18, 'color' => '64748b']),
                ['indent' => 240]
            );
        }
        $w .= cv_empty_paragraph();
    }

    // Soft skills
    if (!empty($prefs['include_soft_skills']) && !empty($emp['soft_skills'])) {
        $w .= cv_modern_section('Soft skills', $cfg);
        $softs = array_filter(array_map('trim', explode(',', $emp['soft_skills'])));
        $w .= cv_paragraph(
            cv_run(implode('  •  ', $softs), ['size' => 20]),
            ['indent' => 240]
        );
        $w .= cv_empty_paragraph();
    }

    return $w;
}

function cv_modern_section(string $title, array $cfg): string {
    // Header con quadratino colorato a sinistra (simulato con █ unicode)
    return cv_paragraph(
        cv_run('▌ ', ['size' => 28, 'bold' => true, 'color' => $cfg['primary']]) .
        cv_run(strtoupper($title), ['size' => 24, 'bold' => true, 'color' => '1e293b']),
        ['spacing' => 240]
    );
}

// ═════════════════════════════════════════════════════════════════════
// RENDER TECNICO — Layout dashboard sidebar+main come "code editor"
//   Sinistra (35%): sfondo verde scuro pieno, font Consolas chiaro,
//                   contiene: dati personali, competenze tecniche, lingue, certificazioni
//   Destra (65%): sfondo bianco, esperienza, istruzione, bio, soft skills
//   Header: barra terminale "user@cv:~$"
// ═════════════════════════════════════════════════════════════════════
function cv_render_technical(array $ctx): string {
    $emp = $ctx['emp']; $prefs = $ctx['prefs']; $cfg = $ctx['cfg'];
    $anonymize = $ctx['anonymize']; $certs = $ctx['certs']; $languages = $ctx['languages'];
    $devices = $ctx['devices']; $has_photo = $ctx['has_photo']; $photo_rid = $ctx['photo_rid'];

    $w = '';

    // ── HEADER terminale ──
    $username = $anonymize ? 'candidato' : strtolower(trim(($emp['first_name'] ?? 'cv') . '.' . ($emp['last_name'] ?? '')));
    $username = preg_replace('/[^a-z0-9.]/', '', $username);
    $w .= cv_paragraph(
        cv_run('$ ', ['size' => 24, 'bold' => true, 'color' => '4ADE80']) .
        cv_run($username . '@cv:~/profile ', ['size' => 24, 'bold' => true, 'color' => 'A7F3D0']) .
        cv_run('$ cat curriculum.md', ['size' => 22, 'color' => 'D1FAE5']),
        ['shading' => '064E3B', 'align' => 'left', 'spacing' => 180]
    );
    $w .= cv_empty_paragraph();

    // ── TABELLA SIDEBAR + MAIN ──
    $sidebar = cv_technical_sidebar($ctx);
    $main = cv_technical_main($ctx);

    $w .= '<w:tbl>'
        . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders>'
        . '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>'
        . '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
        . '</w:tblBorders></w:tblPr>'
        . '<w:tblGrid><w:gridCol w:w="3150"/><w:gridCol w:w="5850"/></w:tblGrid>'
        . '<w:tr>'
        . '<w:tc><w:tcPr><w:tcW w:w="3150" w:type="dxa"/>'
        . '<w:shd w:val="clear" w:color="auto" w:fill="064E3B"/>'
        . '</w:tcPr>'
        . $sidebar
        . '</w:tc>'
        . '<w:tc><w:tcPr><w:tcW w:w="5850" w:type="dxa"/></w:tcPr>'
        . $main
        . '</w:tc>'
        . '</w:tr></w:tbl>';

    return $w;
}

function cv_technical_sidebar(array $ctx): string {
    $emp = $ctx['emp']; $prefs = $ctx['prefs']; $anonymize = $ctx['anonymize'];
    $certs = $ctx['certs']; $languages = $ctx['languages'];
    $has_photo = $ctx['has_photo']; $photo_rid = $ctx['photo_rid'];
    $w = '';

    // Padding superiore
    $w .= cv_paragraph('', ['shading' => '064E3B']);

    // Foto centrata
    if ($has_photo) {
        $w .= cv_paragraph(cv_picture_run($photo_rid, 1200000, 1600000), ['align' => 'center', 'shading' => '064E3B']);
        $w .= cv_paragraph('', ['shading' => '064E3B']);
    }

    // Nome
    $name = $anonymize
        ? ('CANDIDATO #' . str_pad((string)($emp['id'] ?? 0), 4, '0', STR_PAD_LEFT))
        : (($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
    $w .= cv_paragraph(
        cv_run('# ' . trim($name), ['size' => 24, 'bold' => true, 'color' => '4ADE80']),
        ['shading' => '064E3B', 'spacing' => 60]
    );
    if (!empty($emp['job_title'])) {
        $w .= cv_paragraph(
            cv_run('// ' . $emp['job_title'], ['size' => 16, 'italic' => true, 'color' => 'A7F3D0']),
            ['shading' => '064E3B', 'spacing' => 0]
        );
    }
    $w .= cv_paragraph('', ['shading' => '064E3B']);

    // === CONTACTS ===
    $w .= cv_tech_sidebar_header('// CONTACTS');
    $w .= cv_tech_sidebar_kv('email', $emp['business_email'] ?? $emp['personal_email'] ?? null);
    $w .= cv_tech_sidebar_kv('phone', $emp['phone'] ?? null);
    if (!$anonymize) {
        $w .= cv_tech_sidebar_kv('addr', $emp['address'] ?? null);
        $w .= cv_tech_sidebar_kv('cf', $emp['fiscal_code'] ?? null);
        $w .= cv_tech_sidebar_kv('linkedin', $emp['linkedin_url'] ? '/in/...' : null);
        $w .= cv_tech_sidebar_kv('credly', $emp['credly_url'] ? '/users/...' : null);
    }
    $w .= cv_paragraph('', ['shading' => '064E3B']);

    // === TECH STACK ===
    if (!empty($prefs['include_technical_skills']) && !empty($emp['technical_skills'])) {
        $w .= cv_tech_sidebar_header('# TECH STACK');
        $skills = array_filter(array_map('trim', explode(',', $emp['technical_skills'])));
        foreach ($skills as $sk) {
            $w .= cv_paragraph(
                cv_run('  ▸ ' . $sk, ['size' => 16, 'color' => 'D1FAE5']),
                ['shading' => '064E3B', 'spacing' => 0]
            );
        }
        $w .= cv_paragraph('', ['shading' => '064E3B']);
    }

    // === CERTIFICATIONS (versione compatta nel sidebar) ===
    if (!empty($prefs['include_certifications']) && !empty($certs)) {
        $w .= cv_tech_sidebar_header('# CERTIFICATIONS [' . count($certs) . ']');
        foreach ($certs as $c) {
            $line = '  ▸ ' . $c['cert_name'];
            if (!empty($c['cert_code'])) $line .= ' (' . $c['cert_code'] . ')';
            $w .= cv_paragraph(
                cv_run($line, ['size' => 15, 'color' => 'D1FAE5']),
                ['shading' => '064E3B', 'spacing' => 0]
            );
            $sub = '    ' . $c['brand_name'];
            if (!empty($c['issue_date'])) $sub .= ' · ' . date('Y', strtotime($c['issue_date']));
            $w .= cv_paragraph(
                cv_run($sub, ['size' => 13, 'italic' => true, 'color' => '6EE7B7']),
                ['shading' => '064E3B', 'spacing' => 0]
            );
        }
        $w .= cv_paragraph('', ['shading' => '064E3B']);
    }

    // === LANGUAGES ===
    if (!empty($prefs['include_languages']) && !empty($languages)) {
        $w .= cv_tech_sidebar_header('# LANGUAGES');
        foreach ($languages as $lng) {
            $name = $lng['language_name'];
            $lvl = $lng['mother_tongue'] ? '[native]' : '[' . trim(
                ($lng['level_listening'] ?? '-') . '/' .
                ($lng['level_reading'] ?? '-') . '/' .
                ($lng['level_spoken_interaction'] ?? '-') . '/' .
                ($lng['level_writing'] ?? '-'), '/') . ']';
            $w .= cv_paragraph(
                cv_run('  ▸ ' . $name . ' ', ['size' => 16, 'color' => 'D1FAE5']) .
                cv_run($lvl, ['size' => 14, 'color' => '6EE7B7']),
                ['shading' => '064E3B', 'spacing' => 0]
            );
        }
        $w .= cv_paragraph('', ['shading' => '064E3B']);
    }

    // Padding inferiore (più paragrafi vuoti per "estendere" il fondo verde)
    for ($i = 0; $i < 8; $i++) {
        $w .= cv_paragraph('', ['shading' => '064E3B']);
    }

    return $w;
}

function cv_tech_sidebar_header(string $text): string {
    return cv_paragraph(
        cv_run($text, ['size' => 18, 'bold' => true, 'color' => '4ADE80']),
        ['shading' => '064E3B', 'spacing' => 120]
    );
}

function cv_tech_sidebar_kv(string $key, ?string $value): string {
    if (empty($value)) return '';
    return cv_paragraph(
        cv_run('  ' . $key . ': ', ['size' => 15, 'color' => '6EE7B7']) .
        cv_run($value, ['size' => 15, 'color' => 'D1FAE5']),
        ['shading' => '064E3B', 'spacing' => 0]
    );
}

function cv_technical_main(array $ctx): string {
    $emp = $ctx['emp']; $prefs = $ctx['prefs']; $cfg = $ctx['cfg'];
    $anonymize = $ctx['anonymize']; $devices = $ctx['devices'];
    $w = '';

    // Bio
    if (!empty($prefs['include_bio']) && !empty($emp['bio'])) {
        $w .= cv_tech_main_header('/* Profile */', $cfg);
        $w .= cv_paragraph(cv_run($emp['bio'], ['size' => 18]));
        $w .= cv_empty_paragraph();
    }

    // Esperienza
    if (!empty($prefs['include_experience'])) {
        $w .= cv_tech_main_header('// experience', $cfg);
        if (!empty($emp['hire_date'])) {
            $period = '[' . date('d/m/Y', strtotime($emp['hire_date'])) . ' → ' .
                      (!empty($emp['end_date']) ? date('d/m/Y', strtotime($emp['end_date'])) : 'NOW') . ']';
            $w .= cv_paragraph(cv_run($period, ['size' => 16, 'color' => $cfg['primary'], 'bold' => true]));
        }
        if (!empty($emp['job_title'])) {
            $w .= cv_paragraph(cv_run('> ' . $emp['job_title'], ['bold' => true, 'size' => 22]));
        }
        if (!$anonymize && !empty($emp['company_name'])) {
            $loc = $emp['company_name'];
            if (!empty($emp['location_name'])) $loc .= ' @ ' . $emp['location_name'];
            $w .= cv_paragraph(cv_run('  ' . $loc, ['italic' => true, 'color' => '475569', 'size' => 18]));
        }
        $bits = array_filter([$emp['contract_type'] ?? null, $emp['department'] ?? null]);
        if ($bits) $w .= cv_paragraph(cv_run('  // ' . implode(' · ', $bits), ['size' => 16, 'color' => '64748b']));
        $w .= cv_empty_paragraph();
    }

    // Istruzione
    if (!empty($prefs['include_education'])) {
        $w .= cv_tech_main_header('// education', $cfg);
        if (!empty($emp['education_year'])) {
            $w .= cv_paragraph(cv_run('[' . $emp['education_year'] . ']', ['size' => 16, 'color' => $cfg['primary'], 'bold' => true]));
        }
        if (!empty($emp['education_level'])) {
            $line = $emp['education_level'];
            if (!empty($emp['education_field'])) $line .= ' in ' . $emp['education_field'];
            $w .= cv_paragraph(cv_run('> ' . $line, ['bold' => true, 'size' => 20]));
        }
        if (!empty($emp['education_institute'])) {
            $w .= cv_paragraph(cv_run('  ' . $emp['education_institute'], ['italic' => true, 'size' => 18]));
        }
        $w .= cv_empty_paragraph();
    }

    // Soft skills
    if (!empty($prefs['include_soft_skills']) && !empty($emp['soft_skills'])) {
        $w .= cv_tech_main_header('// soft_skills', $cfg);
        $softs = array_filter(array_map('trim', explode(',', $emp['soft_skills'])));
        foreach ($softs as $sk) {
            $w .= cv_paragraph(cv_run('  ▸ ' . $sk, ['size' => 18]));
        }
        $w .= cv_empty_paragraph();
    }

    // Devices
    if (!empty($prefs['include_devices']) && !empty($devices) && !$anonymize) {
        $w .= cv_tech_main_header('// company_assets', $cfg);
        foreach ($devices as $label => $info) {
            $w .= cv_paragraph(cv_run('> ' . $label, ['bold' => true, 'size' => 18]));
            foreach ($info['rows'] as $r) {
                $parts = [];
                foreach ($info['fields'] as $f) if (!empty($r[$f])) $parts[] = $r[$f];
                $w .= cv_paragraph(cv_run('    ▸ ' . implode(' · ', $parts), ['size' => 16]));
            }
        }
    }

    return $w;
}

function cv_tech_main_header(string $text, array $cfg): string {
    return cv_paragraph(
        cv_run($text, ['size' => 24, 'bold' => true, 'color' => $cfg['primary']]),
        ['spacing' => 240]
    ) . '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="' . $cfg['primary'] . '"/></w:pBdr></w:pPr></w:p>';
}

// ═════════════════════════════════════════════════════════════════════
// HELPER: foto inline (utility)
// ═════════════════════════════════════════════════════════════════════
function cv_picture_run(string $rid, int $cx = 2160000, int $cy = 2880000): string {
    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
         . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'
         . '<wp:docPr id="1" name="Foto"/><wp:cNvGraphicFramePr/>'
         . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
         . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
         . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
         . '<pic:nvPicPr><pic:cNvPr id="1" name="photo"/><pic:cNvPicPr/></pic:nvPicPr>'
         . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
         . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
         . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
         . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

// ═════════════════════════════════════════════════════════════════════
// RENDER EUROPASS — Layout ufficiale UE Europass online (fedele)
//   Header: nome a sinistra + box "europass" blu a destra
//   Foto opzionale a sinistra del nome (cerchio/quadrato)
//   Sezioni con pallino blu • + titolo MAIUSCOLO BLU + linea grigia
//   Esperienze con sub-header MAIUSCOLO + azienda + date separate da –
// ═════════════════════════════════════════════════════════════════════
function cv_render_europass(array $ctx): string {
    $emp = $ctx['emp']; $prefs = $ctx['prefs']; $cfg = $ctx['cfg'];
    $anonymize = $ctx['anonymize']; $certs = $ctx['certs']; $languages = $ctx['languages'];
    $devices = $ctx['devices']; $has_photo = $ctx['has_photo']; $photo_rid = $ctx['photo_rid'];

    $w = '';

    // ── HEADER: tabella 2 colonne — foto+nome a sx, logo europass a dx ──
    $name = $anonymize
        ? ('CANDIDATO #' . str_pad((string)($emp['id'] ?? 0), 4, '0', STR_PAD_LEFT))
        : (($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));

    // Cella sinistra: foto + nome
    $left = '';
    if ($has_photo) {
        $left .= cv_paragraph(cv_picture_run($photo_rid, 1100000, 1100000), ['align' => 'left']);
    }

    // Cella destra: nome grande + logo europass
    $right_top = ''; $right_bot = '';

    $right_top .= cv_paragraph(
        cv_run($name, ['size' => 36, 'bold' => true, 'color' => '000000']),
        ['align' => 'left', 'spacing' => 0]
    );

    // Logo "europass" simulato come testo bianco su sfondo blu (rettangolo)
    $logo_run = cv_run('★ ', ['size' => 20, 'color' => 'FFD700', 'bold' => true]) .
                cv_run('europass', ['size' => 22, 'color' => 'FFFFFF', 'bold' => true]);

    $w .= '<w:tbl>'
        . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/>'
        . '<w:tblBorders>'
        . '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>'
        . '<w:insideH w:val="nil"/><w:insideV w:val="nil"/>'
        . '</w:tblBorders></w:tblPr>'
        . '<w:tblGrid>'
        . ($has_photo ? '<w:gridCol w:w="1800"/><w:gridCol w:w="5100"/><w:gridCol w:w="2100"/>' : '<w:gridCol w:w="6900"/><w:gridCol w:w="2100"/>')
        . '</w:tblGrid>'
        . '<w:tr>';
    if ($has_photo) {
        $w .= '<w:tc><w:tcPr><w:tcW w:w="1800" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $left . '</w:tc>';
        $w .= '<w:tc><w:tcPr><w:tcW w:w="5100" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $right_top . '</w:tc>';
    } else {
        $w .= '<w:tc><w:tcPr><w:tcW w:w="6900" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' . $right_top . '</w:tc>';
    }
    // Cella logo europass
    $w .= '<w:tc>'
        . '<w:tcPr><w:tcW w:w="2100" w:type="dxa"/><w:vAlign w:val="center"/>'
        . '<w:shd w:val="clear" w:color="auto" w:fill="' . $cfg['primary'] . '"/>'
        . '<w:tcMar><w:top w:w="100" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:left w:w="150" w:type="dxa"/><w:right w:w="150" w:type="dxa"/></w:tcMar>'
        . '</w:tcPr>'
        . cv_paragraph($logo_run, ['align' => 'center'])
        . '</w:tc>';
    $w .= '</w:tr></w:tbl>';

    // Linea orizzontale separatrice sotto header
    $w .= cv_europass_hr($cfg);
    $w .= cv_empty_paragraph();

    // ── DATI ANAGRAFICI INLINE (separati da |) ──
    $info_runs = '';
    $fields = [];
    if (!$anonymize) {
        if (!empty($emp['date_of_birth']))  $fields[] = ['Data di nascita', date('d/m/Y', strtotime($emp['date_of_birth']))];
        if (!empty($emp['city_of_birth']))  $fields[] = ['Luogo di nascita', $emp['city_of_birth']];
        if (!empty($emp['nationality']) || true) $fields[] = ['Nazionalità', $emp['nationality'] ?? 'Italiana'];
        if (!empty($emp['gender']))         $fields[] = ['Sesso', $emp['gender']];
        if (!empty($emp['phone']))          $fields[] = ['Numero di telefono', $emp['phone'] . ' (Cellulare)'];
        if (!empty($emp['business_email'])) $fields[] = ['Indirizzo e-mail', $emp['business_email']];
        elseif (!empty($emp['personal_email'])) $fields[] = ['Indirizzo e-mail', $emp['personal_email']];
        if (!empty($emp['linkedin_url']))   $fields[] = ['LinkedIn', basename($emp['linkedin_url'])];
        if (!empty($emp['address']))        $fields[] = ['Indirizzo', $emp['address'] . ' (Abitazione)'];
    } else {
        $fields[] = ['Riferimento', 'Candidato #' . str_pad((string)($emp['id'] ?? 0), 4, '0', STR_PAD_LEFT)];
    }
    $i = 0;
    foreach ($fields as [$label, $value]) {
        if ($i > 0) $info_runs .= cv_run('  |  ', ['size' => 20, 'color' => 'BFBFBF']);
        $info_runs .= cv_run($label . ': ', ['size' => 20, 'bold' => true]);
        $info_runs .= cv_run((string)$value, ['size' => 20]);
        $i++;
    }
    if ($info_runs !== '') {
        $w .= cv_paragraph($info_runs, ['align' => 'left', 'spacing' => 60]);
    }
    $w .= cv_empty_paragraph();

    // ── SEZIONE: PRESENTAZIONE (bio) ──
    if (!empty($prefs['include_bio']) && !empty($emp['bio'])) {
        $w .= cv_europass_section('PRESENTAZIONE', $cfg);
        $w .= cv_paragraph(cv_run($emp['bio'], ['size' => 20]), ['align' => 'both']);
        $w .= cv_empty_paragraph();
    }

    // ── SEZIONE: ESPERIENZA LAVORATIVA ──
    if (!empty($prefs['include_experience'])) {
        $w .= cv_europass_section('ESPERIENZA LAVORATIVA', $cfg);

        // Carico esperienze multiple dalla tabella emp_experiences
        $experiences = cv_load_experiences($emp['id']);

        if (empty($experiences)) {
            // Fallback: usa i campi base dell'employee come "esperienza attuale"
            $experiences = [[
                'job_title'     => $emp['job_title'] ?? '',
                'company'       => !$anonymize ? ($emp['company_name'] ?? '') : '[Azienda riservata]',
                'period_from'   => $emp['hire_date'] ?? null,
                'period_to'     => $emp['end_date'] ?? null,
                'is_current'    => empty($emp['end_date']),
                'location'      => !$anonymize ? ($emp['location_name'] ?? '') : '',
                'contract_type' => $emp['contract_type'] ?? '',
                'description'   => '',
            ]];
        }

        foreach ($experiences as $exp) {
            // Sub-header: JOB TITLE – COMPANY – DATE_FROM – DATE_TO – LOCATION
            $head_parts = [];
            if (!empty($exp['job_title'])) {
                $head_parts[] = cv_run(strtoupper($exp['job_title']), ['size' => 20, 'bold' => true]);
            }
            if (!empty($exp['company'])) {
                $head_parts[] = cv_run(strtoupper($exp['company']), ['size' => 20, 'bold' => true]);
            }
            $period = '';
            if (!empty($exp['period_from'])) {
                $period = date('d/m/Y', strtotime($exp['period_from']));
                $period .= ' – ';
                $period .= $exp['is_current']
                    ? 'Attuale'
                    : (!empty($exp['period_to']) ? date('d/m/Y', strtotime($exp['period_to'])) : '?');
            }
            if ($period) $head_parts[] = cv_run($period, ['size' => 20]);
            if (!empty($exp['location'])) $head_parts[] = cv_run(strtoupper($exp['location']), ['size' => 20]);

            $head_run = '';
            foreach ($head_parts as $i => $p) {
                if ($i > 0) $head_run .= cv_run(' – ', ['size' => 20]);
                $head_run .= $p;
            }
            $w .= cv_paragraph($head_run, ['spacing' => 180]);

            // Linea sottile sotto il sub-header
            $w .= cv_europass_hr_thin($cfg);

            // Descrizione: bullet list se contiene newline/bullet
            if (!empty($exp['description'])) {
                $lines = preg_split('/\r\n|\r|\n/', trim($exp['description']));
                foreach ($lines as $ln) {
                    $ln = trim($ln);
                    if ($ln === '') continue;
                    // Rimuovi eventuale '-' o '•' iniziale
                    $clean = preg_replace('/^[\s\-•·]+/u', '', $ln);
                    $w .= cv_paragraph(cv_run('• ' . $clean, ['size' => 20]), ['indent' => 240]);
                }
            }
            $w .= cv_empty_paragraph();
        }
    }

    // ── SEZIONE: ISTRUZIONE E FORMAZIONE ──
    if (!empty($prefs['include_education'])) {
        $w .= cv_europass_section('ISTRUZIONE E FORMAZIONE', $cfg);

        $educations = cv_load_education($emp['id']);

        if (empty($educations)) {
            // Fallback dai campi base
            $educations = [[
                'year_from' => $emp['education_year_from'] ?? null,
                'year_to'   => $emp['education_year'] ?? null,
                'level'     => $emp['education_level'] ?? '',
                'field'     => $emp['education_field'] ?? '',
                'institute' => $emp['education_institute'] ?? '',
                'grade'     => $emp['education_grade'] ?? '',
                'is_current'=> false,
                'notes'     => '',
            ]];
        }

        foreach ($educations as $edu) {
            // Periodo
            $period = '';
            if (!empty($edu['year_from']) || !empty($edu['year_to'])) {
                $period = (!empty($edu['year_from']) ? $edu['year_from'] : '?');
                $period .= ' – ';
                $period .= $edu['is_current']
                    ? 'Attuale'
                    : (!empty($edu['year_to']) ? $edu['year_to'] : '?');
            }
            if ($period) {
                $w .= cv_paragraph(cv_run($period, ['size' => 18]), ['spacing' => 120]);
            }
            // Livello + campo (MAIUSCOLO GRASSETTO) + istituto
            $title_run = '';
            if (!empty($edu['level'])) {
                $title_run .= cv_run(strtoupper($edu['level']), ['size' => 22, 'bold' => true]);
            }
            if (!empty($edu['field'])) {
                $title_run .= cv_run(' ' . strtoupper($edu['field']), ['size' => 22, 'bold' => true]);
            }
            if (!empty($edu['institute'])) {
                if ($title_run) $title_run .= cv_run(' ', ['size' => 22]);
                $title_run .= cv_run($edu['institute'], ['size' => 20]);
            }
            if ($title_run) {
                $w .= cv_paragraph($title_run);
                $w .= cv_europass_hr_thin($cfg);
            }
            // Voto + livello EQF
            if (!empty($edu['grade'])) {
                $w .= cv_paragraph(
                    cv_run('Voto finale: ', ['size' => 18, 'bold' => true]) .
                    cv_run($edu['grade'], ['size' => 18])
                );
            }
            if (!empty($edu['notes'])) {
                $w .= cv_paragraph(cv_run($edu['notes'], ['size' => 18, 'italic' => true]));
            }
            $w .= cv_empty_paragraph();
        }
    }

    // ── SEZIONE: COMPETENZE LINGUISTICHE ──
    if (!empty($prefs['include_languages']) && !empty($languages)) {
        $w .= cv_europass_section('COMPETENZE LINGUISTICHE', $cfg);

        // Lingua madre (se presente)
        $mother = null;
        $others = [];
        foreach ($languages as $l) {
            if (!empty($l['mother_tongue'])) $mother = $l;
            else $others[] = $l;
        }
        if ($mother) {
            $w .= cv_paragraph(
                cv_run('Lingua madre: ', ['size' => 20, 'bold' => true]) .
                cv_run(strtoupper($mother['language_name']), ['size' => 20]),
                ['spacing' => 120]
            );
        }

        if (!empty($others)) {
            $w .= cv_paragraph(cv_run('Altre lingue:', ['size' => 20]), ['spacing' => 0]);
            $w .= cv_europass_lang_table($others, $cfg);
        }

        // Legenda
        $w .= cv_paragraph(
            cv_run('Livelli: ', ['size' => 16, 'italic' => true]) .
            cv_run('A1 e A2: Livello elementare · B1 e B2: Livello intermedio · C1 e C2: Livello avanzato', ['size' => 16, 'italic' => true]),
            ['spacing' => 120]
        );
        $w .= cv_empty_paragraph();
    }

    // ── SEZIONE: CERTIFICAZIONI ──
    if (!empty($prefs['include_certifications']) && !empty($certs)) {
        $w .= cv_europass_section('CERTIFICAZIONI', $cfg);
        $w .= cv_paragraph(cv_run('Certificazioni', ['size' => 20, 'bold' => true]), ['spacing' => 120]);
        foreach ($certs as $c) {
            $bits = [$c['cert_name'] ?? '—'];
            if (!empty($c['cert_code'])) $bits[] = '(' . $c['cert_code'] . ')';
            if (!empty($c['brand_name'])) $bits[] = ' — ' . $c['brand_name'];
            if (!empty($c['issue_date']))  $bits[] = ' | rilasciata ' . date('d F Y', strtotime($c['issue_date']));
            if (!empty($c['expiry_date'])) $bits[] = ' | scadenza ' . date('d F Y', strtotime($c['expiry_date']));
            $w .= cv_paragraph(cv_run('• ' . implode(' ', $bits), ['size' => 20]), ['indent' => 240]);
        }
        $w .= cv_empty_paragraph();
    }

    // ── SEZIONE: COMPETENZE TECNICHE (se popolate) ──
    if (!empty($prefs['include_technical_skills']) && !empty($emp['technical_skills'])) {
        $w .= cv_europass_section('COMPETENZE', $cfg);
        $skills = array_filter(array_map('trim', explode(',', $emp['technical_skills'])));
        $w .= cv_paragraph(
            cv_run(implode(' | ', $skills), ['size' => 20]),
            ['align' => 'both']
        );
        $w .= cv_empty_paragraph();
    }

    // ── SEZIONE: SOFT SKILLS (se popolate) ──
    if (!empty($prefs['include_soft_skills']) && !empty($emp['soft_skills'])) {
        $w .= cv_europass_section('COMPETENZE TRASVERSALI', $cfg);
        $softs = array_filter(array_map('trim', explode(',', $emp['soft_skills'])));
        foreach ($softs as $sk) {
            $w .= cv_paragraph(cv_run('• ' . $sk, ['size' => 20]), ['indent' => 240]);
        }
        $w .= cv_empty_paragraph();
    }

    // ── FOOTER GDPR ──
    $w .= cv_europass_hr($cfg);
    $w .= cv_paragraph(
        cv_run(
            'Autorizzo il trattamento dei miei dati personali presenti nel CV ai sensi dell\'art. 13 d. lgs. 30 giugno 2003 n. 196 - "Codice in materia di protezione dei dati personali" e dell\'art. 13 GDPR 679/16 - "Regolamento europeo sulla protezione dei dati personali".',
            ['size' => 14, 'italic' => true, 'color' => '595959']
        ),
        ['align' => 'both', 'spacing' => 240]
    );

    return $w;
}

// ── Helper: pallino + titolo sezione Europass ──
function cv_europass_section(string $title, array $cfg): string {
    $dot_color = $cfg['section_dot'] ?? $cfg['primary'];
    $hr = cv_europass_hr($cfg);
    return cv_paragraph(
        cv_run('● ', ['size' => 28, 'bold' => true, 'color' => $dot_color]) .
        cv_run($title, ['size' => 24, 'bold' => true, 'color' => $cfg['primary']]),
        ['spacing' => 240]
    ) . $hr;
}

// ── Helper: linea orizzontale spessa Europass ──
function cv_europass_hr(array $cfg): string {
    $color = $cfg['rule_color'] ?? 'BFBFBF';
    return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="' . $color . '"/></w:pBdr></w:pPr></w:p>';
}

// ── Helper: linea orizzontale sottile sotto sub-header esperienze ──
function cv_europass_hr_thin(array $cfg): string {
    $color = $cfg['rule_color'] ?? 'D9D9D9';
    return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="4" w:space="1" w:color="' . $color . '"/></w:pBdr></w:pPr></w:p>';
}

// ── Helper: tabella lingue CEFR standard Europass ──
function cv_europass_lang_table(array $languages, array $cfg): string {
    $bg = $cfg['table_header_bg'] ?? 'F2F2F2';
    $border = $cfg['rule_color'] ?? 'BFBFBF';

    $xml = '<w:tbl>'
         . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/>'
         . '<w:tblBorders>'
         . '<w:top w:val="single" w:sz="4" w:color="' . $border . '"/>'
         . '<w:left w:val="single" w:sz="4" w:color="' . $border . '"/>'
         . '<w:bottom w:val="single" w:sz="4" w:color="' . $border . '"/>'
         . '<w:right w:val="single" w:sz="4" w:color="' . $border . '"/>'
         . '<w:insideH w:val="single" w:sz="4" w:color="' . $border . '"/>'
         . '<w:insideV w:val="single" w:sz="4" w:color="' . $border . '"/>'
         . '</w:tblBorders></w:tblPr>'
         . '<w:tblGrid>'
         . '<w:gridCol w:w="1500"/>'
         . '<w:gridCol w:w="1300"/><w:gridCol w:w="1300"/>'
         . '<w:gridCol w:w="1300"/><w:gridCol w:w="1300"/>'
         . '<w:gridCol w:w="1300"/>'
         . '</w:tblGrid>';

    // Header riga 1 (raggruppato)
    $xml .= '<w:tr>'
         . '<w:tc><w:tcPr><w:tcW w:w="1500" w:type="dxa"/><w:vMerge w:val="restart"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>' . cv_paragraph(cv_run('', [])) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="2600" w:type="dxa"/><w:gridSpan w:val="2"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>'
         .   cv_paragraph(cv_run('COMPRENSIONE', ['size' => 16, 'bold' => true]), ['align' => 'center']) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="2600" w:type="dxa"/><w:gridSpan w:val="2"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>'
         .   cv_paragraph(cv_run('ESPRESSIONE ORALE', ['size' => 16, 'bold' => true]), ['align' => 'center']) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/><w:vMerge w:val="restart"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/><w:vAlign w:val="center"/></w:tcPr>'
         .   cv_paragraph(cv_run('SCRITTURA', ['size' => 16, 'bold' => true]), ['align' => 'center']) . '</w:tc>'
         . '</w:tr>';

    // Header riga 2
    $xml .= '<w:tr>'
         . '<w:tc><w:tcPr><w:tcW w:w="1500" w:type="dxa"/><w:vMerge/></w:tcPr>' . cv_paragraph(cv_run('', [])) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>'
         .   cv_paragraph(cv_run('Ascolto', ['size' => 14, 'italic' => true]), ['align' => 'center']) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>'
         .   cv_paragraph(cv_run('Lettura', ['size' => 14, 'italic' => true]), ['align' => 'center']) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>'
         .   cv_paragraph(cv_run('Produzione orale', ['size' => 14, 'italic' => true]), ['align' => 'center']) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/></w:tcPr>'
         .   cv_paragraph(cv_run('Interazione orale', ['size' => 14, 'italic' => true]), ['align' => 'center']) . '</w:tc>'
         . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/><w:vMerge/></w:tcPr>' . cv_paragraph(cv_run('', [])) . '</w:tc>'
         . '</w:tr>';

    // Righe lingue
    foreach ($languages as $l) {
        $xml .= '<w:tr>'
             . '<w:tc><w:tcPr><w:tcW w:w="1500" w:type="dxa"/></w:tcPr>'
             .   cv_paragraph(cv_run(strtoupper($l['language_name'] ?? ''), ['size' => 18, 'bold' => true])) . '</w:tc>'
             . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/></w:tcPr>'
             .   cv_paragraph(cv_run($l['level_listening'] ?? '—', ['size' => 18]), ['align' => 'center']) . '</w:tc>'
             . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/></w:tcPr>'
             .   cv_paragraph(cv_run($l['level_reading'] ?? '—', ['size' => 18]), ['align' => 'center']) . '</w:tc>'
             . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/></w:tcPr>'
             .   cv_paragraph(cv_run($l['level_spoken_production'] ?? '—', ['size' => 18]), ['align' => 'center']) . '</w:tc>'
             . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/></w:tcPr>'
             .   cv_paragraph(cv_run($l['level_spoken_interaction'] ?? '—', ['size' => 18]), ['align' => 'center']) . '</w:tc>'
             . '<w:tc><w:tcPr><w:tcW w:w="1300" w:type="dxa"/></w:tcPr>'
             .   cv_paragraph(cv_run($l['level_writing'] ?? '—', ['size' => 18]), ['align' => 'center']) . '</w:tc>'
             . '</w:tr>';
    }
    $xml .= '</w:tbl>';
    return $xml;
}

// ── Helper: carica esperienze multiple da emp_experiences ──
function cv_load_experiences(int $emp_id): array {
    global $pdo;
    try {
        $q = $pdo->prepare(
            "SELECT * FROM emp_experiences
              WHERE employee_id = ?
              ORDER BY is_current DESC, period_to DESC, period_from DESC, id DESC"
        );
        $q->execute([$emp_id]);
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ── Helper: carica titoli di studio multipli da emp_education ──
function cv_load_education(int $emp_id): array {
    global $pdo;
    try {
        $q = $pdo->prepare(
            "SELECT * FROM emp_education
              WHERE employee_id = ?
              ORDER BY is_current DESC, year_to DESC, year_from DESC, id DESC"
        );
        $q->execute([$emp_id]);
        return $q->fetchAll();
    } catch (Throwable $e) { return []; }
}

// ─────────────────────────────────────────────────────────────────────
// HELPER: configurazione template CV
// ─────────────────────────────────────────────────────────────────────
function cv_template_config(string $template): array {
    $configs = [
        'classic' => [
            'primary'      => '0073C7',
            'header_bg'    => '0073C7',
            'header_color' => 'FFFFFF',
            'header_title' => 'Curriculum Vitae',
            'header_sub'   => 'Europass',
            'section_color' => '0073C7',
            'table_header_bg' => 'DEEAF6',
            'font'         => 'Arial',
        ],
        'modern' => [
            'primary'      => '7C3AED',
            'header_bg'    => '7C3AED',
            'header_color' => 'FFFFFF',
            'header_title' => 'CURRICULUM VITAE',
            'header_sub'   => 'Profilo professionale',
            'section_color' => '7C3AED',
            'table_header_bg' => 'EDE9FE',
            'font'         => 'Calibri',
        ],
        'technical' => [
            'primary'      => '047857',
            'header_bg'    => '064E3B',
            'header_color' => 'FFFFFF',
            'header_title' => 'CV Tecnico',
            'header_sub'   => 'Competenze IT & Certificazioni',
            'section_color' => '047857',
            'table_header_bg' => 'D1FAE5',
            'font'         => 'Consolas',
        ],
        'europass' => [
            // Europass ufficiale UE — palette identica al template online
            'primary'      => '003399',  // blu UE
            'header_bg'    => 'FFFFFF',
            'header_color' => '000000',
            'header_title' => 'Curriculum Vitae',
            'header_sub'   => 'europass',
            'section_color' => '003399',
            'section_dot'  => '4472C4',  // pallino blu lato sezione
            'table_header_bg' => 'F2F2F2',
            'rule_color'   => 'BFBFBF',  // grigio linea separatrice
            'font'         => 'Calibri',
        ],
    ];
    return $configs[$template] ?? $configs['classic'];
}

// ─────────────────────────────────────────────────────────────────────
// HELPER: header DOCX in base al template
// ─────────────────────────────────────────────────────────────────────
function cv_header_for_template(string $template, array $emp, array $cfg, bool $anonymize): string {
    $w = '';

    // Title del CV: nome completo o "Candidato" se anonimo
    $candidate_name = '';
    if ($anonymize) {
        $candidate_name = 'CANDIDATO #' . str_pad((string)($emp['id'] ?? 0), 4, '0', STR_PAD_LEFT);
    } else {
        $candidate_name = strtoupper(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
    }

    if ($template === 'classic') {
        $w .= cv_paragraph(
            cv_run($cfg['header_title'], ['size' => 32, 'bold' => true, 'color' => $cfg['header_color']]),
            ['shading' => $cfg['header_bg'], 'align' => 'left', 'spacing' => 240]
        );
        $w .= cv_paragraph(
            cv_run($cfg['header_sub'], ['size' => 18, 'italic' => true, 'color' => $cfg['header_color']]),
            ['shading' => $cfg['header_bg'], 'align' => 'left', 'spacing' => 120]
        );
        $w .= cv_empty_paragraph();
    }

    if ($template === 'modern') {
        $w .= cv_paragraph(
            cv_run($cfg['header_title'], ['size' => 36, 'bold' => true, 'color' => $cfg['header_color']]),
            ['shading' => $cfg['header_bg'], 'align' => 'center', 'spacing' => 240]
        );
        $w .= cv_paragraph(
            cv_run($candidate_name, ['size' => 28, 'bold' => true, 'color' => $cfg['header_color']]),
            ['shading' => $cfg['header_bg'], 'align' => 'center', 'spacing' => 60]
        );
        $w .= cv_paragraph(
            cv_run($cfg['header_sub'], ['size' => 16, 'italic' => true, 'color' => $cfg['header_color']]),
            ['shading' => $cfg['header_bg'], 'align' => 'center', 'spacing' => 120]
        );
        $w .= cv_empty_paragraph();
    }

    if ($template === 'technical') {
        $w .= cv_paragraph(
            cv_run('> ' . $cfg['header_title'] . '_', ['size' => 30, 'bold' => true, 'color' => $cfg['header_color']]),
            ['shading' => $cfg['header_bg'], 'align' => 'left', 'spacing' => 240]
        );
        $w .= cv_paragraph(
            cv_run('# ' . $candidate_name, ['size' => 22, 'bold' => true, 'color' => $cfg['header_color']]),
            ['shading' => $cfg['header_bg'], 'align' => 'left', 'spacing' => 60]
        );
        $w .= cv_paragraph(
            cv_run('// ' . $cfg['header_sub'], ['size' => 16, 'italic' => true, 'color' => 'A7F3D0']),
            ['shading' => $cfg['header_bg'], 'align' => 'left', 'spacing' => 120]
        );
        $w .= cv_empty_paragraph();
    }

    return $w;
}

// ─────────────────────────────────────────────────────────────────────
// HELPER: ordine sezioni in base al template
// ─────────────────────────────────────────────────────────────────────
function cv_section_order(string $template): array {
    if ($template === 'modern') {
        return ['personal', 'bio', 'certifications', 'tech_skills', 'experience', 'education', 'languages', 'soft_skills', 'devices'];
    }
    if ($template === 'technical') {
        return ['personal', 'certifications', 'tech_skills', 'languages', 'experience', 'education', 'bio', 'soft_skills', 'devices'];
    }
    // classic
    return ['personal', 'bio', 'experience', 'education', 'tech_skills', 'soft_skills', 'languages', 'certifications', 'devices'];
}

// ─────────────────────────────────────────────────────────────────────
// HELPER: anonimizzazione dati sensibili
// ─────────────────────────────────────────────────────────────────────
function cv_anonymize_data(array $emp): array {
    // CAMPI ANONIMIZZATI: telefono, CF, data nascita, indirizzo/luogo nascita, social
    // CAMPI MANTENUTI: nome, cognome, email (richiesti per identificare il professionista)
    $emp['phone']           = !empty($emp['phone']) ? '+39 *** *** ****' : null;
    $emp['phone_personal']  = !empty($emp['phone_personal']) ? '+39 *** *** ****' : null;
    $emp['fiscal_code']     = !empty($emp['fiscal_code']) ? '*** mascherato ***' : null;
    if (!empty($emp['date_of_birth'])) {
        // Mostra solo l'anno
        $year = date('Y', strtotime($emp['date_of_birth']));
        $emp['date_of_birth'] = 'anno ' . $year;
    }
    $emp['address']         = !empty($emp['address']) ? '*** indirizzo riservato ***' : null;
    $emp['city_of_birth']   = !empty($emp['city_of_birth']) ? '*** riservato ***' : null;
    $emp['linkedin_url']    = null;
    $emp['credly_url']      = null;
    $emp['photo_path']      = null;
    return $emp;
}


// ─────────────────────────────────────────────────────────────────────
// HELPER OOXML — paragrafi, run, tabelle
// ─────────────────────────────────────────────────────────────────────
function cv_run(string $text, array $opts = []): string {
    $esc = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $rPr = '';
    if (!empty($opts['bold']))   $rPr .= '<w:b/>';
    if (!empty($opts['italic'])) $rPr .= '<w:i/>';
    if (!empty($opts['size']))   $rPr .= '<w:sz w:val="' . (int)$opts['size'] . '"/>';
    if (!empty($opts['color']))  $rPr .= '<w:color w:val="' . $opts['color'] . '"/>';
    $rPr = $rPr ? "<w:rPr>$rPr</w:rPr>" : '';
    return "<w:r>$rPr<w:t xml:space=\"preserve\">$esc</w:t></w:r>";
}

function cv_paragraph(string $runs, array $opts = []): string {
    $pPr = '';
    if (!empty($opts['shading'])) $pPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $opts['shading'] . '"/>';
    if (!empty($opts['align']))   $pPr .= '<w:jc w:val="' . $opts['align'] . '"/>';
    if (!empty($opts['spacing'])) $pPr .= '<w:spacing w:before="' . $opts['spacing'] . '" w:after="60"/>';
    if (!empty($opts['indent']))  $pPr .= '<w:ind w:left="' . $opts['indent'] . '"/>';
    $pPr = $pPr ? "<w:pPr>$pPr</w:pPr>" : '';
    return "<w:p>$pPr$runs</w:p>";
}

function cv_empty_paragraph(): string {
    return '<w:p></w:p>';
}

function cv_section_header(string $title, ?array $cfg = null): string {
    $color = $cfg['section_color'] ?? '0073C7';
    return cv_paragraph(
        cv_run(strtoupper($title), ['size' => 22, 'bold' => true, 'color' => $color]),
        ['spacing' => 240]
    ) . '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="8" w:space="1" w:color="' . $color . '"/></w:pBdr></w:pPr></w:p>';
}

function cv_two_col_table(array $rows, ?string $photo_rid = null, ?array $cfg = null): string {
    if (empty($rows)) return '';
    $label_color = $cfg['section_color'] ?? '0073C7';
    $xml = '<w:tbl>';
    $xml .= '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders>'
          . '<w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/>'
          . '<w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr>';
    $xml .= '<w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="6000"/></w:tblGrid>';
    foreach ($rows as $r) {
        $xml .= '<w:tr>';
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="3000" w:type="dxa"/></w:tcPr>'
              . cv_paragraph(cv_run($r[0], ['bold' => true, 'color' => $label_color]))
              . '</w:tc>';
        $xml .= '<w:tc><w:tcPr><w:tcW w:w="6000" w:type="dxa"/></w:tcPr>'
              . cv_paragraph(cv_run($r[1]))
              . '</w:tc>';
        $xml .= '</w:tr>';
    }
    $xml .= '</w:tbl>';

    // Se foto, inserisco anteprima a destra come paragrafo flottante semplificato (sotto la tabella per compatibilità)
    if ($photo_rid) {
        $xml .= cv_paragraph(
            '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="2160000" cy="2880000"/><wp:effectExtent l="0" t="0" r="0" b="0"/>'
            . '<wp:docPr id="1" name="Foto"/><wp:cNvGraphicFramePr/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="1" name="photo"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $photo_rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="2160000" cy="2880000"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>',
            ['align' => 'right']
        );
    }
    return $xml;
}

function cv_grid_table(array $rows, ?array $cfg = null): string {
    if (empty($rows)) return '';
    $header_color = $cfg['section_color'] ?? '0073C7';
    $header_bg    = $cfg['table_header_bg'] ?? 'DEEAF6';
    $ncols = count($rows[0]);
    $col_width = (int)(9000 / $ncols);
    $xml = '<w:tbl>';
    $xml .= '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/><w:tblBorders>'
          . '<w:top w:val="single" w:sz="4" w:color="CCCCCC"/>'
          . '<w:left w:val="single" w:sz="4" w:color="CCCCCC"/>'
          . '<w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>'
          . '<w:right w:val="single" w:sz="4" w:color="CCCCCC"/>'
          . '<w:insideH w:val="single" w:sz="4" w:color="CCCCCC"/>'
          . '<w:insideV w:val="single" w:sz="4" w:color="CCCCCC"/>'
          . '</w:tblBorders></w:tblPr>';
    $xml .= '<w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $col_width . '"/>', $ncols) . '</w:tblGrid>';
    foreach ($rows as $r) {
        $xml .= '<w:tr>';
        foreach ($r as $cell) {
            $text = $cell[0] ?? '';
            $is_header = $cell[1] ?? false;
            $is_caption = $cell[2] ?? false;
            $tcPr = '<w:tcW w:w="' . $col_width . '" w:type="dxa"/>';
            if ($is_header) $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $header_bg . '"/>';
            $xml .= '<w:tc><w:tcPr>' . $tcPr . '</w:tcPr>'
                  . cv_paragraph(cv_run($text, [
                      'bold' => $is_header,
                      'italic' => $is_caption,
                      'color' => $is_header ? $header_color : '000000',
                      'size' => $is_caption ? 16 : 20,
                  ]))
                  . '</w:tc>';
        }
        $xml .= '</w:tr>';
    }
    $xml .= '</w:tbl>';
    return $xml;
}

// ─────────────────────────────────────────────────────────────────────
// UI
// ─────────────────────────────────────────────────────────────────────
require_once('header.php');

$cert_count = (int)$pdo->query("SELECT COUNT(*) FROM user_certifications WHERE employee_id = " . (int)$emp_id)->fetchColumn();
?>

<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap">
  <a href="<?= function_exists('url_safe') ? url_safe('employee_profile', ['id' => $emp_id]) : 'employee_profile.php?id=' . $emp_id ?>" class="btn btn-sm">
    <i class="fa-solid fa-arrow-left"></i> Torna alla scheda
  </a>
  <div style="flex:1">
    <h1 style="font-size:20px;font-weight:800;margin:0">
      <i class="fa-solid fa-file-word" style="color:#1e40af"></i> Curriculum Vitae Europass
    </h1>
    <div style="font-size:12px;color:var(--muted)">
      Dipendente: <strong><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></strong>
      <?php if ($emp['employee_code']): ?> · matricola <?= htmlspecialchars($emp['employee_code']) ?><?php endif; ?>
    </div>
  </div>
</div>

<?= $msg ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:20px">

  <!-- COLONNA SX: opzioni CV -->
  <div>
    <form method="POST" id="cvForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="generate_cv">

      <!-- ═══ SCELTA MODELLO CV ═══ -->
      <div class="card" style="margin-bottom:18px">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-palette" style="color:#7c3aed"></i> Modello CV</span>
          <span style="font-size:11px;color:var(--muted)">Default attuale: <strong><?= ['classic'=>'Classico','modern'=>'Moderno','technical'=>'Tecnico','europass'=>'Europass UE'][$prefs['cv_template']] ?? 'Classico' ?></strong></span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:10px;margin-bottom:10px" data-tpl-grid>
          <?php
          $templates = [
              'classic' => [
                  'label'    => 'Classico',
                  'subtitle' => 'Header blu, 2 colonne',
                  'desc'     => 'Layout tradizionale Europass, formale e neutro. Ideale per candidature pubbliche.',
                  'color'    => '#0073C7',
                  'bg'       => '#dbeafe',
                  'icon'     => 'fa-file-lines',
              ],
              'modern' => [
                  'label'    => 'Moderno',
                  'subtitle' => 'Viola, centrato, pulito',
                  'desc'     => 'Design contemporaneo con titolo centrale prominente. Certificazioni e tech skills in evidenza.',
                  'color'    => '#7C3AED',
                  'bg'       => '#ede9fe',
                  'icon'     => 'fa-wand-magic-sparkles',
              ],
              'technical' => [
                  'label'    => 'Tecnico',
                  'subtitle' => 'Verde scuro, mono',
                  'desc'     => 'Stile IT/code-friendly. Certificazioni e competenze in cima, font monospace. Ideale per profili tecnici.',
                  'color'    => '#047857',
                  'bg'       => '#d1fae5',
                  'icon'     => 'fa-code',
              ],
              'europass' => [
                  'label'    => 'Europass UE',
                  'subtitle' => 'Standard UE ufficiale',
                  'desc'     => 'Layout fedele al CV Europass online dell\'Unione Europea. Sezioni con pallino blu, linee orizzontali, esperienze in MAIUSCOLO. Riconosciuto da PA e datori esteri.',
                  'color'    => '#003399',
                  'bg'       => '#e0e7ff',
                  'icon'     => 'fa-flag',
              ],
          ];
          foreach ($templates as $key => $tpl):
              $is_selected = ($prefs['cv_template'] === $key);
          ?>
          <label style="cursor:pointer;display:block">
            <input type="radio" name="cv_template" value="<?= $key ?>" <?= $is_selected ? 'checked' : '' ?>
                   style="display:none" onchange="updateTemplateSel(this)">
            <div class="tpl-card" data-tpl="<?= $key ?>"
                 style="border:2px solid <?= $is_selected ? $tpl['color'] : 'var(--border)' ?>;background:<?= $is_selected ? $tpl['bg'] : '#fafbfc' ?>;border-radius:10px;padding:14px;transition:.15s;height:100%">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $tpl['color'] ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0">
                  <i class="fa-solid <?= $tpl['icon'] ?>"></i>
                </div>
                <div>
                  <div style="font-weight:800;font-size:13px;color:<?= $tpl['color'] ?>"><?= $tpl['label'] ?></div>
                  <div style="font-size:10px;color:var(--muted)"><?= $tpl['subtitle'] ?></div>
                </div>
              </div>
              <div style="font-size:10px;color:var(--text);line-height:1.4"><?= $tpl['desc'] ?></div>
              <?php if ($is_selected): ?>
              <div style="margin-top:8px;font-size:9px;font-weight:800;color:<?= $tpl['color'] ?>;text-transform:uppercase">
                <i class="fa-solid fa-check-circle"></i> Selezionato
              </div>
              <?php endif; ?>
            </div>
          </label>
          <?php endforeach; ?>
        </div>

        <label style="display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted);background:#f8fafc;padding:8px 12px;border-radius:6px;cursor:pointer">
          <input type="checkbox" name="save_as_default" value="1">
          <span><i class="fa-solid fa-floppy-disk" style="color:#7c3aed"></i> Salva come modello default per questo dipendente (altrimenti override one-shot)</span>
        </label>
      </div>

      <!-- ═══ ANONIMIZZAZIONE ═══ -->
      <div class="card" style="margin-bottom:18px">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-user-secret" style="color:#dc2626"></i> Anonimizzazione dati sensibili</span>
        </div>
        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px;background:<?= $prefs['cv_anonymize'] ? '#fee2e2' : '#fafbfc' ?>;border:1px solid <?= $prefs['cv_anonymize'] ? '#fecaca' : 'var(--border)' ?>;border-radius:8px;cursor:pointer;transition:.15s" id="anonLabel">
          <input type="checkbox" name="cv_anonymize" value="1" <?= $prefs['cv_anonymize'] ? 'checked' : '' ?>
                 style="margin-top:3px;cursor:pointer" id="anonCheckbox">
          <div style="flex:1">
            <div style="font-weight:700;font-size:13px;color:#991b1b">
              <i class="fa-solid fa-shield-halved"></i> Genera versione anonimizzata
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px;line-height:1.6">
              Quando attivo, vengono mascherati: <strong>telefono</strong> (+39 *** *** ****), <strong>codice fiscale</strong> (*** mascherato ***),
              <strong>data di nascita</strong> (solo anno), <strong>indirizzo</strong> e <strong>luogo di nascita</strong>,
              <strong>LinkedIn/Credly</strong>, <strong>foto profilo</strong> rimossa, <strong>azienda/sede</strong> attuale nascoste.
              <br>Restano visibili: nome, cognome, email, competenze, certificazioni, lingue, formazione, esperienza (ruolo).
            </div>
          </div>
        </label>
      </div>

      <div class="card" style="margin-bottom:18px">
        <div class="card-header">
          <span class="card-title"><i class="fa-solid fa-list-check" style="color:var(--p)"></i> Sezioni da includere nel CV</span>
        </div>
        <p style="font-size:12px;color:var(--muted);margin-bottom:14px">
          Seleziona le sezioni che vuoi includere nel CV Europass. Le opzioni vengono salvate per il futuro.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <?php
          $sections = [
              ['inc_personal',    'include_personal',         '👤 Dati personali',                   'Nome, contatti, CF, data nascita, LinkedIn, Credly'],
              ['inc_photo',       'include_photo',            '📸 Foto profilo',                      'Mostrata in alto a destra (richiede upload sotto)'],
              ['inc_bio',         'include_bio',              '📝 Profilo professionale',             'Testo bio/presentazione'],
              ['inc_experience',  'include_experience',       '💼 Esperienza professionale',          'Inquadramento HR: azienda, ruolo, contratto, date'],
              ['inc_education',   'include_education',        '🎓 Istruzione e formazione',           'Titolo studio, facoltà, istituto, anno'],
              ['inc_tech_skills', 'include_technical_skills', '⚙️ Competenze tecniche',                'Skill tags da tab Competenze'],
              ['inc_soft_skills', 'include_soft_skills',      '🤝 Soft skills',                       'Skill trasversali selezionate'],
              ['inc_certifications','include_certifications', '🏆 Certificazioni (' . $cert_count . ')', 'Tutte le certificazioni acquisite con scadenza'],
              ['inc_languages',   'include_languages',        '🌍 Lingue (' . count($languages) . ')', 'Tabella CEFR Europass A1-C2 (5 livelli)'],
              ['inc_devices',     'include_devices',          '💻 Dotazioni aziendali',               'Telefono, SIM, notebook, veicolo in uso'],
          ];
          foreach ($sections as [$key, $pref_key, $label, $desc]):
              $checked = !empty($prefs[$pref_key]);
          ?>
          <label style="background:#f8fafc;border:1px solid <?= $checked ? 'var(--p)' : 'var(--border)' ?>;border-radius:8px;padding:10px 12px;cursor:pointer;transition:.12s;display:block">
            <div style="display:flex;align-items:flex-start;gap:8px">
              <input type="checkbox" name="<?= $key ?>" <?= $checked ? 'checked' : '' ?> style="margin-top:3px;cursor:pointer">
              <div style="flex:1">
                <div style="font-weight:700;font-size:12px;color:var(--text)"><?= $label ?></div>
                <div style="font-size:10px;color:var(--muted);line-height:1.3;margin-top:2px"><?= htmlspecialchars($desc) ?></div>
              </div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="padding:14px 32px;font-size:14px">
        <i class="fa-solid fa-file-word"></i> Genera CV Europass DOCX
      </button>
      <span style="margin-left:12px;font-size:11px;color:var(--muted)">
        Il file .docx verrà scaricato e potrà essere aperto/modificato con Word, LibreOffice o Google Docs.
      </span>
    </form>
  </div>

  <!-- COLONNA DX: foto + lingue -->
  <div>
    <!-- FOTO -->
    <div class="card" style="margin-bottom:16px">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-camera" style="color:#0ea5e9"></i> Foto profilo</span>
      </div>
      <?php if ($emp['photo_path'] && is_file($photo_dir . $emp['photo_path'])): ?>
        <div style="text-align:center;margin-bottom:10px">
          <img src="download.php?file=<?= urlencode('cv_photos/' . $emp['photo_path']) ?>"
               style="max-width:160px;border-radius:8px;border:2px solid var(--border)">
        </div>
        <?php if ($can_edit): ?>
        <form method="POST" onsubmit="return confirm('Rimuovere la foto?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_photo">
          <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;width:100%">
            <i class="fa-solid fa-trash"></i> Rimuovi foto
          </button>
        </form>
        <?php endif; ?>
      <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--muted);font-size:12px">
          <i class="fa-solid fa-user-circle" style="font-size:60px;opacity:.3;display:block;margin-bottom:8px"></i>
          Nessuna foto caricata
        </div>
      <?php endif; ?>
      <?php if ($can_edit): ?>
      <form method="POST" enctype="multipart/form-data" style="margin-top:10px;border-top:1px solid var(--border);padding-top:10px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_photo">
        <input type="file" name="photo" accept=".jpg,.jpeg,.png" required
               style="font-size:11px;width:100%;margin-bottom:6px">
        <button type="submit" class="btn btn-sm btn-primary" style="width:100%">
          <i class="fa-solid fa-cloud-arrow-up"></i> Carica
        </button>
        <div style="font-size:10px;color:var(--muted);margin-top:4px">JPG/PNG, max 2MB</div>
      </form>
      <?php endif; ?>
    </div>

    <!-- LINGUE -->
    <div class="card">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-language" style="color:#10b981"></i> Lingue (<?= count($languages) ?>)</span>
      </div>

      <?php if (empty($languages)): ?>
        <div style="font-size:11px;color:var(--muted);text-align:center;padding:14px 0">
          Nessuna lingua registrata.
        </div>
      <?php else: ?>
        <div style="max-height:300px;overflow-y:auto">
        <?php foreach ($languages as $lng): ?>
        <div style="border:1px solid var(--border);border-radius:6px;padding:8px 10px;margin-bottom:8px;font-size:11px;background:#fafbfc">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <strong style="font-size:12px"><?= htmlspecialchars($lng['language_name']) ?>
              <?php if ($lng['mother_tongue']): ?><span style="background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:8px;font-size:9px;margin-left:4px">madrelingua</span><?php endif; ?>
            </strong>
            <?php if ($can_edit): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_language">
              <input type="hidden" name="lang_id" value="<?= $lng['id'] ?>">
              <button type="submit" style="background:none;border:0;color:#991b1b;cursor:pointer"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </div>
          <?php if (!$lng['mother_tongue']):
            $levels = array_filter([
              'Asc' => $lng['level_listening'], 'Let' => $lng['level_reading'],
              'Int' => $lng['level_spoken_interaction'], 'Prd' => $lng['level_spoken_production'],
              'Scr' => $lng['level_writing']
            ]);
            if ($levels): ?>
            <div style="color:var(--muted);font-size:10px;font-family:monospace">
              <?php foreach ($levels as $k => $v): ?>
                <span style="background:#dbeafe;color:#1e40af;padding:1px 5px;border-radius:4px;margin-right:2px"><?= $k ?>:<?= $v ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; endif; ?>
          <?php if ($lng['certification']): ?>
            <div style="font-size:10px;color:var(--muted);margin-top:4px"><i class="fa-solid fa-certificate"></i> <?= htmlspecialchars($lng['certification']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($can_edit): ?>
      <details style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px">
        <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--p)">
          <i class="fa-solid fa-plus"></i> Aggiungi lingua
        </summary>
        <form method="POST" style="margin-top:10px;font-size:11px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_language">
          <input type="text" name="language_name" placeholder="Nome lingua (es. Inglese)" required
                 style="width:100%;padding:7px;border:1px solid var(--border);border-radius:5px;margin-bottom:6px">
          <label style="display:flex;align-items:center;gap:6px;font-size:11px;margin-bottom:8px">
            <input type="checkbox" name="mother_tongue" value="1"> Lingua madre
          </label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px">
            <?php foreach ([
              'level_listening'          => 'Ascolto',
              'level_reading'            => 'Lettura',
              'level_spoken_interaction' => 'Interazione',
              'level_spoken_production'  => 'Produzione orale',
              'level_writing'            => 'Scrittura',
            ] as $field => $label): ?>
            <label style="display:block;font-size:10px;color:var(--muted)">
              <?= $label ?>
              <select name="<?= $field ?>" style="width:100%;padding:5px;border:1px solid var(--border);border-radius:4px;font-size:11px">
                <option value="">—</option>
                <?php foreach (['A1','A2','B1','B2','C1','C2'] as $lv): ?>
                  <option value="<?= $lv ?>"><?= $lv ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php endforeach; ?>
          </div>
          <input type="text" name="certification" placeholder="Certificazione (opz)"
                 style="width:100%;padding:7px;border:1px solid var(--border);border-radius:5px;margin-bottom:6px">
          <button type="submit" class="btn btn-sm btn-primary" style="width:100%">
            <i class="fa-solid fa-plus"></i> Aggiungi
          </button>
        </form>
      </details>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
// Toggle visual highlight delle card sezioni
document.querySelectorAll('input[type=checkbox][name^="inc_"]').forEach(cb => {
  cb.addEventListener('change', () => {
    const label = cb.closest('label');
    label.style.borderColor = cb.checked ? 'var(--p)' : 'var(--border)';
  });
});

// Aggiorna visivamente la card template selezionata
const templateColors = {
  classic:   { color: '#0073C7', bg: '#dbeafe' },
  modern:    { color: '#7C3AED', bg: '#ede9fe' },
  technical: { color: '#047857', bg: '#d1fae5' },
};
function updateTemplateSel(radio) {
  document.querySelectorAll('.tpl-card').forEach(card => {
    const k = card.dataset.tpl;
    const c = templateColors[k] || templateColors.classic;
    if (radio && radio.value === k) {
      card.style.borderColor = c.color;
      card.style.backgroundColor = c.bg;
    } else {
      card.style.borderColor = 'var(--border)';
      card.style.backgroundColor = '#fafbfc';
    }
  });
}

// Toggle visual anonimo
const anonCb = document.getElementById('anonCheckbox');
const anonLabel = document.getElementById('anonLabel');
if (anonCb && anonLabel) {
  anonCb.addEventListener('change', () => {
    if (anonCb.checked) {
      anonLabel.style.background = '#fee2e2';
      anonLabel.style.borderColor = '#fecaca';
    } else {
      anonLabel.style.background = '#fafbfc';
      anonLabel.style.borderColor = 'var(--border)';
    }
  });
}
</script>

<?php require_once('footer.php'); ?>
