<?php
/**
 * certV 2.4 — recruiting_posizioni.php
 * Posizioni con master text storicizzati, templates, preview e export multiposting
 */
require_once('access_control.php');
require_once('functions.php');
require_once __DIR__ . '/app/PositionHistory.php';
require_once __DIR__ . '/app/TemplateVersioning.php';

$u_id        = (int)$_SESSION['user_id'];
$u_role      = (int)($_SESSION['role_id'] ?? 99);
$can_edit    = can('edit');
$can_approve = can('delete');
$can_see_sal = can('view','recruiting_contratti.php');

// ── Auto-migration robusta (colonna per colonna) ────────────────────────────
$cols_to_add = [
    'presentation_text' => "ALTER TABLE job_positions ADD COLUMN presentation_text TEXT DEFAULT NULL",
    'gender_disclaimer' => "ALTER TABLE job_positions ADD COLUMN gender_disclaimer TEXT DEFAULT NULL",
    'offer_info'        => "ALTER TABLE job_positions ADD COLUMN offer_info TEXT DEFAULT NULL",
    'hard_skills'       => "ALTER TABLE job_positions ADD COLUMN hard_skills TEXT DEFAULT NULL",
    'soft_skills'       => "ALTER TABLE job_positions ADD COLUMN soft_skills TEXT DEFAULT NULL",
    'we_offer'          => "ALTER TABLE job_positions ADD COLUMN we_offer TEXT DEFAULT NULL",
    'master_version_id' => "ALTER TABLE job_positions ADD COLUMN master_version_id INT DEFAULT NULL",
];
foreach ($cols_to_add as $col => $sql) {
    try { $pdo->query("SELECT `$col` FROM job_positions LIMIT 0")->closeCursor(); }
    catch (\Exception $e) {
        try { $pdo->exec($sql); } catch (\Exception $ex) {}
    }
}

// Tabelle templates + master texts
try { $pdo->query("SELECT id FROM position_master_texts LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `position_master_texts` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `text_type` ENUM('presentation','gender_disclaimer') NOT NULL,
            `version` INT NOT NULL DEFAULT 1,
            `is_current` TINYINT(1) NOT NULL DEFAULT 1,
            `content` TEXT NOT NULL,
            `notes` VARCHAR(255) DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `superseded_at` DATETIME DEFAULT NULL,
            `superseded_by` INT DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_pmt_type_current` (`text_type`,`is_current`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Seed default
        $pdo->exec("INSERT INTO position_master_texts (text_type,version,is_current,content,notes,created_by) VALUES
            ('presentation',1,1,'Siamo un\\'azienda italiana specializzata nei servizi IT con presenza consolidata sul territorio nazionale. Ci occupiamo di consulenza tecnologica, sistemi informativi e servizi gestiti per clienti enterprise di vari settori.','Versione iniziale',1),
            ('gender_disclaimer',1,1,'La ricerca è rivolta a candidate e candidati senza distinzione di genere (L. 903/77 e L. 125/91). I dati personali saranno trattati ai sensi del GDPR (Reg. UE 679/2016) per finalità di selezione del personale.','Disclaimer GDPR iniziale',1)");
    } catch (\Exception $ex) {}
}

try { $pdo->query("SELECT id FROM position_templates LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `position_templates` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `template_type` ENUM('hard_skills','soft_skills','we_offer','offer_info','description','nice_to_have') NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `content` TEXT NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `usage_count` INT NOT NULL DEFAULT 0,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            PRIMARY KEY (`id`),
            KEY `idx_ptpl_type` (`template_type`,`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Exception $ex) {}
}

// ── CRUD (PRIMA di header.php) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    $pos_id = (int)($_POST['position_id'] ?? 0);

    if ($action === 'save') {
        // Master version: usa quella corrente al momento del salvataggio
        $mv = null;
        try {
            $mvq = $pdo->query("SELECT MAX(id) FROM position_master_texts WHERE is_current=1");
            $mv = (int)$mvq->fetchColumn() ?: null;
        } catch (\Exception $e) {}

        // ── v5: snapshot dati precedenti per storicizzazione ──
        $previous_data = null;
        if ($pos_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM job_positions WHERE id = ?");
            $stmt->execute([$pos_id]);
            $previous_data = $stmt->fetch();
        }

        $ral_min  = (isset($_POST['ral_min'])  && $_POST['ral_min']  !== '') ? (float)$_POST['ral_min']  : null;
        $ral_max  = (isset($_POST['ral_max'])  && $_POST['ral_max']  !== '') ? (float)$_POST['ral_max']  : null;
        $benefits = !empty($_POST['benefits']) ? trim((string)$_POST['benefits']) : null;
        $positions_expected = max(1, (int)($_POST['positions_expected'] ?? 1));

        $data = [
            $_POST['title'] ?? '',
            !empty($_POST['department']) ? $_POST['department'] : null,
            !empty($_POST['brand_id']) && (int)$_POST['brand_id'] > 0 ? (int)$_POST['brand_id'] : null,
            $u_id,
            !empty($_POST['team_leader_id']) && (int)$_POST['team_leader_id'] > 0 ? (int)$_POST['team_leader_id'] : null,
            $_POST['status'] ?? 'draft',
            $_POST['priority'] ?? 'Media',
            !empty($_POST['description']) ? $_POST['description'] : null,
            !empty($_POST['required_skills']) ? $_POST['required_skills'] : null,
            !empty($_POST['nice_to_have']) ? $_POST['nice_to_have'] : null,
            $_POST['contract_type'] ?? 'Indeterminato',
            !empty($_POST['location']) ? $_POST['location'] : null,
            $_POST['remote_policy'] ?? 'Ibrido',
            !empty($_POST['target_date']) ? $_POST['target_date'] : null,
            // NUOVI CAMPI
            !empty($_POST['presentation_text']) ? $_POST['presentation_text'] : null,
            !empty($_POST['gender_disclaimer']) ? $_POST['gender_disclaimer'] : null,
            !empty($_POST['offer_info']) ? $_POST['offer_info'] : null,
            !empty($_POST['hard_skills']) ? $_POST['hard_skills'] : null,
            !empty($_POST['soft_skills']) ? $_POST['soft_skills'] : null,
            !empty($_POST['we_offer']) ? $_POST['we_offer'] : null,
            $ral_min,
            $ral_max,
            $benefits,
            $positions_expected,
            $mv,
        ];
        try {
            if ($pos_id > 0) {
                $pdo->prepare(
                    "UPDATE job_positions SET title=?,department=?,brand_id=?,requested_by=?,team_leader_id=?,status=?,priority=?,description=?,required_skills=?,nice_to_have=?,contract_type=?,location=?,remote_policy=?,target_date=?,
                     presentation_text=?,gender_disclaimer=?,offer_info=?,hard_skills=?,soft_skills=?,we_offer=?,ral_min=?,ral_max=?,benefits=?,positions_expected=?,master_version_id=? WHERE id=?"
                )->execute([...$data, $pos_id]);

                // ── v5: registra storico se status o compenso sono cambiati ──
                if ($previous_data) {
                    $new_data = ['id' => $pos_id, 'status' => $_POST['status'] ?? 'draft',
                                 'opened_at' => $previous_data['opened_at'], 'closed_at' => $previous_data['closed_at'],
                                 'ral_min' => $ral_min, 'ral_max' => $ral_max, 'benefits' => $benefits];
                    PositionHistory::recordIfStatusChanged($pdo, $previous_data, $new_data, $u_id);
                    PositionHistory::recordIfCompensationChanged($pdo, $previous_data, $new_data, $u_id);
                }

                // v5.02: sync clienti associati (N:M)
                $client_ids = isset($_POST['client_ids']) && is_array($_POST['client_ids'])
                              ? array_map('intval', $_POST['client_ids'])
                              : [];
                $pdo->prepare("DELETE FROM position_clients WHERE position_id = ?")->execute([$pos_id]);
                if (!empty($client_ids)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO position_clients (position_id, client_id) VALUES (?, ?)");
                    foreach ($client_ids as $cid) {
                        if ($cid > 0) $ins->execute([$pos_id, $cid]);
                    }
                }

                write_log('Recruiting','success',"Posizione #$pos_id aggiornata",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Posizione aggiornata.</div>";
                redirect('recruiting_posizioni');
            } else {
                $pdo->prepare(
                    "INSERT INTO job_positions (title,department,brand_id,requested_by,team_leader_id,status,priority,description,required_skills,nice_to_have,contract_type,location,remote_policy,target_date,
                     presentation_text,gender_disclaimer,offer_info,hard_skills,soft_skills,we_offer,ral_min,ral_max,benefits,positions_expected,master_version_id,opened_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())"
                )->execute($data);
                $new_id = (int)$pdo->lastInsertId();

                // v5.02: clienti associati (N:M)
                $client_ids = isset($_POST['client_ids']) && is_array($_POST['client_ids'])
                              ? array_map('intval', $_POST['client_ids'])
                              : [];
                if (!empty($client_ids)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO position_clients (position_id, client_id) VALUES (?, ?)");
                    foreach ($client_ids as $cid) {
                        if ($cid > 0) $ins->execute([$new_id, $cid]);
                    }
                }

                // ── v5: registra primo evento nello storico ──
                PositionHistory::recordIfStatusChanged($pdo,
                    ['id' => $new_id, 'status' => null],
                    ['id' => $new_id, 'status' => $_POST['status'] ?? 'draft',
                     'opened_at' => date('Y-m-d'), 'closed_at' => null],
                    $u_id, 'Posizione creata');
                if ($ral_min !== null || $ral_max !== null || $benefits !== null) {
                    PositionHistory::recordIfCompensationChanged($pdo,
                        ['id' => $new_id, 'ral_min' => null, 'ral_max' => null, 'benefits' => null],
                        ['id' => $new_id, 'ral_min' => $ral_min, 'ral_max' => $ral_max, 'benefits' => $benefits],
                        $u_id, 'Compenso iniziale');
                }

                write_log('Recruiting','success',"Posizione #$new_id creata",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Posizione creata.</div>";
                redirect('recruiting_posizioni');
            }
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
            redirect('recruiting_posizioni');
        }
    }

    if ($action === 'approve' && $can_approve) {
        // Log history
        $old = $pdo->prepare("SELECT status FROM job_positions WHERE id=?"); $old->execute([$pos_id]);
        $old_st = $old->fetchColumn(); $old->closeCursor();
        $pdo->prepare("UPDATE job_positions SET status='open', approved_by=?, opened_at=CURDATE() WHERE id=?")->execute([$u_id, $pos_id]);
        $pdo->prepare("INSERT INTO position_status_history (position_id,old_status,new_status,opened_at_snapshot,changed_by,notes) VALUES (?,?,?,CURDATE(),?,?)")
            ->execute([$pos_id, $old_st, 'open', $u_id, 'Approvazione e apertura']);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Posizione approvata.</div>";
        header("Location: recruiting_posizioni.php?f_st=open" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    // v1.7.32: nuova azione PAUSE
    if ($action === 'pause') {
        $old = $pdo->prepare("SELECT status FROM job_positions WHERE id=?"); $old->execute([$pos_id]);
        $old_st = $old->fetchColumn(); $old->closeCursor();
        $pdo->prepare("UPDATE job_positions SET status='paused' WHERE id=?")->execute([$pos_id]);
        $pdo->prepare("INSERT INTO position_status_history (position_id,old_status,new_status,changed_by,notes) VALUES (?,?,?,?,?)")
            ->execute([$pos_id, $old_st, 'paused', $u_id, trim((string)($_POST['notes'] ?? '')) ?: 'Posizione messa in pausa']);
        $_SESSION['flash_msg'] = "<div class='alert alert-info'><i class='fa-solid fa-pause'></i> Posizione messa in pausa.</div>";
        header("Location: recruiting_posizioni.php?f_st=paused" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    // v1.7.32: nuova azione REOPEN (da paused o closed)
    if ($action === 'reopen') {
        $old = $pdo->prepare("SELECT status FROM job_positions WHERE id=?"); $old->execute([$pos_id]);
        $old_st = $old->fetchColumn(); $old->closeCursor();
        $pdo->prepare("UPDATE job_positions SET status='open', closed_at=NULL WHERE id=?")->execute([$pos_id]);
        $pdo->prepare("INSERT INTO position_status_history (position_id,old_status,new_status,changed_by,notes) VALUES (?,?,?,?,?)")
            ->execute([$pos_id, $old_st, 'open', $u_id, trim((string)($_POST['notes'] ?? '')) ?: 'Riapertura posizione']);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-play'></i> Posizione riaperta.</div>";
        header("Location: recruiting_posizioni.php?f_st=open" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    if ($action === 'close') {
        $old = $pdo->prepare("SELECT status FROM job_positions WHERE id=?"); $old->execute([$pos_id]);
        $old_st = $old->fetchColumn(); $old->closeCursor();
        $pdo->prepare("UPDATE job_positions SET status='closed', closed_at=CURDATE() WHERE id=?")->execute([$pos_id]);
        $pdo->prepare("INSERT INTO position_status_history (position_id,old_status,new_status,closed_at_snapshot,changed_by,notes) VALUES (?,?,?,CURDATE(),?,?)")
            ->execute([$pos_id, $old_st, 'closed', $u_id, trim((string)($_POST['notes'] ?? '')) ?: 'Chiusura manuale']);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Posizione chiusa.</div>";
        header("Location: recruiting_posizioni.php?f_st=closed" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    if ($action === 'delete' && $can_approve) {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM candidate_applications WHERE position_id=?");
        $cnt->execute([$pos_id]); $n_app = (int)$cnt->fetchColumn(); $cnt->closeCursor();
        if ($n_app > 0) {
            $pdo->prepare("UPDATE job_positions SET status='cancelled', closed_at=CURDATE() WHERE id=?")->execute([$pos_id]);
            $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Posizione annullata (aveva $n_app candidature).</div>";
            header("Location: recruiting_posizioni.php?f_st=cancelled" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
        } else {
            $pdo->prepare("DELETE FROM job_positions WHERE id=?")->execute([$pos_id]);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Posizione eliminata.</div>";
            redirect('recruiting_posizioni');
        }
    }

    // ── SALVA NUOVA VERSIONE MASTER TEXT ─────────────────────────────────────
    if ($action === 'save_master_text' && $can_approve) {
        $type = $_POST['text_type'] ?? '';
        $content = trim($_POST['content'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (in_array($type, ['presentation','gender_disclaimer']) && $content) {
            try {
                $pdo->beginTransaction();
                // Storicizza vecchie versioni
                $pdo->prepare("UPDATE position_master_texts SET is_current=0, superseded_at=NOW(), superseded_by=? WHERE text_type=? AND is_current=1")
                    ->execute([$u_id, $type]);
                // Calcola prossima versione
                $vq = $pdo->prepare("SELECT COALESCE(MAX(version),0)+1 FROM position_master_texts WHERE text_type=?");
                $vq->execute([$type]); $nv = (int)$vq->fetchColumn(); $vq->closeCursor();
                $pdo->prepare("INSERT INTO position_master_texts (text_type,version,is_current,content,notes,created_by) VALUES (?,?,1,?,?,?)")
                    ->execute([$type, $nv, $content, $notes ?: null, $u_id]);
                $pdo->commit();
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Nuova versione master text salvata (v$nv). Versione precedente storicizzata.</div>";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
            }
        }
        header("Location: recruiting_posizioni.php?master=1" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    // ── SALVA NUOVO TEMPLATE ─────────────────────────────────────────────────
    if ($action === 'save_template') {
        $type = $_POST['template_type'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
        if (in_array($type, ['hard_skills','soft_skills','we_offer','offer_info','description','nice_to_have']) && $name && $content) {
            try {
                // ── v5: gestione versionamento via TemplateVersioning ──
                TemplateVersioning::createVersion($pdo, $type, $name, $content, $u_id, $notes);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Template salvato (versione storicizzata).</div>";
            } catch (Throwable $e) {
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
            }
        }
        header("Location: recruiting_posizioni.php?templates=1" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    if ($action === 'restore_template' && $can_approve) {
        $tpl_id = (int)($_POST['template_id'] ?? 0);
        if ($tpl_id > 0) {
            try {
                TemplateVersioning::restore($pdo, $tpl_id, $u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Versione ripristinata.</div>";
            } catch (Throwable $e) {
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
            }
        }
        header("Location: recruiting_posizioni.php?templates=1" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    if ($action === 'delete_template' && $can_approve) {
        $tpl_id = (int)($_POST['template_id'] ?? 0);
        if ($tpl_id > 0) {
            try {
                TemplateVersioning::softDelete($pdo, $tpl_id, $u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Template disattivato.</div>";
            } catch (Throwable $e) {
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
            }
        }
        header("Location: recruiting_posizioni.php?templates=1" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }
}

// ── Output HTML ─────────────────────────────────────────────────────────────
require_once('header.php');

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

// Filtri
$f_status = $_GET['f_st'] ?? '';
$f_prio   = $_GET['f_pr'] ?? '';
$f_brand  = (int)($_GET['f_br'] ?? 0);

$where = ["1=1"]; $params = [];
if ($f_status) { $where[] = "jp.status=?";   $params[] = $f_status; }
if ($f_prio)   { $where[] = "jp.priority=?"; $params[] = $f_prio; }
if ($f_brand)  { $where[] = "jp.brand_id=?"; $params[] = $f_brand; }
if ($u_role === 4) { $where[] = "jp.team_leader_id=?"; $params[] = $u_id; }
if ($u_role === 5) { $where[] = "jp.status IN('open','paused')"; }

$pos_q = $pdo->prepare(
    "SELECT jp.*, b.name brand_name, etl.first_name tl_fn, etl.last_name tl_ln,
            erq.first_name rq_fn, erq.last_name rq_ln,
            (SELECT COUNT(*) FROM candidate_applications ca WHERE ca.position_id=jp.id) pip,
            (SELECT COUNT(*) FROM candidate_applications ca WHERE ca.position_id=jp.id AND ca.stage='hired') hired
     FROM job_positions jp
     LEFT JOIN brands b ON jp.brand_id=b.id
     LEFT JOIN users tl ON jp.team_leader_id=tl.id
     LEFT JOIN employees etl ON tl.employee_id=etl.id
     LEFT JOIN users rq ON jp.requested_by=rq.id
     LEFT JOIN employees erq ON rq.employee_id=erq.id
     WHERE " . implode(" AND ", $where) . "
     ORDER BY FIELD(jp.status,'open','draft','paused','closed','cancelled'), jp.priority DESC, jp.opened_at DESC"
);
$pos_q->execute($params);
$pos_list = $pos_q->fetchAll();

$brands = $pdo->query("SELECT id,name FROM brands ORDER BY name")->fetchAll();

// v5.02: clienti attivi e mappa posizione → clienti associati
$clients_list = $pdo->query("SELECT id, name, is_internal_company FROM clients WHERE is_active=1 ORDER BY name")->fetchAll();
$pcMap = $pdo->query("SELECT pc.position_id, pc.client_id, c.name FROM position_clients pc JOIN clients c ON c.id = pc.client_id ORDER BY c.name")->fetchAll();
$position_clients_map = [];
foreach ($pcMap as $row) {
    $position_clients_map[(int)$row['position_id']][] = ['id' => (int)$row['client_id'], 'name' => $row['name']];
}
$tls = $pdo->query("SELECT u.id, e.first_name, e.last_name FROM users u JOIN employees e ON u.employee_id=e.id WHERE u.role_id=4 AND u.status='active' ORDER BY e.last_name")->fetchAll();

// Master texts correnti
$master_pres = ''; $master_gen = '';
try {
    $mq = $pdo->query("SELECT text_type, content FROM position_master_texts WHERE is_current=1");
    foreach ($mq->fetchAll() as $r) {
        if ($r['text_type'] === 'presentation') $master_pres = $r['content'];
        if ($r['text_type'] === 'gender_disclaimer') $master_gen = $r['content'];
    }
} catch (\Exception $e) {}

// Templates raggruppati per tipo
$templates_by_type = [];
try {
    $tq = $pdo->query("SELECT * FROM position_templates WHERE is_active=1 ORDER BY template_type, name");
    foreach ($tq->fetchAll() as $t) $templates_by_type[$t['template_type']][] = $t;
} catch (\Exception $e) {}

// Storico master text per modale
$master_history = [];
try {
    $mhq = $pdo->query("SELECT pmt.*, u.display_name author FROM position_master_texts pmt LEFT JOIN users u ON pmt.created_by=u.id ORDER BY text_type, version DESC");
    foreach ($mhq->fetchAll() as $m) $master_history[$m['text_type']][] = $m;
} catch (\Exception $e) {}

$status_label = ['draft'=>'Bozza','open'=>'Aperta','paused'=>'In pausa','closed'=>'Chiusa','cancelled'=>'Annullata'];
$status_badge = ['draft'=>'badge-neutral','open'=>'badge-success','paused'=>'badge-warning','closed'=>'badge-info','cancelled'=>'badge-danger'];
$prio_style = ['Bassa'=>['#e0f2fe','#0369a1'],'Media'=>['#dbeafe','#1d4ed8'],'Alta'=>['#fef3c7','#a16207'],'Urgente'=>['#fee2e2','#b91c1c']];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-briefcase" style="color:var(--p);margin-right:10px"></i>Posizioni aperte</h1>
    <p style="color:var(--muted);font-size:13px">Master text storicizzati, template riutilizzabili, preview e export multiposting</p>
  </div>
  <div style="display:flex;gap:8px">
    <?php if($can_approve): ?>
    <button onclick="document.getElementById('mMaster').style.display='flex'" class="btn btn-sm" style="background:#fef3c7;color:#a16207;border-color:#fde68a"><i class="fa-solid fa-clipboard-list"></i> Master text</button>
    <?php endif; ?>
    <button onclick="document.getElementById('mTemplates').style.display='flex'" class="btn btn-sm" style="background:#ede9fe;color:#5b21b6;border-color:#c4b5fd"><i class="fa-solid fa-layer-group"></i> Template</button>
    <?php
    // Costruisce la query string con i filtri attivi per passarli agli export
    $export_qs = http_build_query(array_filter([
        'f_st' => $_GET['f_st'] ?? null,
        'f_br' => $_GET['f_br'] ?? null,
        'f_pr' => $_GET['f_pr'] ?? null,
    ], fn($v) => $v !== null && $v !== ''));
    $qsep = $export_qs ? '?' . $export_qs : '';
    ?>
    <a href="export_positions_xlsx.php<?= $qsep ?>" class="btn btn-sm" style="background:#d1fae5;color:#065f46;border-color:#10b981" title="Esporta in Excel: indice + dettaglio per ogni posizione">
        <i class="fa-solid fa-file-excel"></i> Esporta XLSX
    </a>
    <a href="export_positions_pdf.php<?= $qsep ?>" target="_blank" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border-color:#fecaca" title="Stampa o salva PDF: scheda formattata per ciascuna posizione">
        <i class="fa-solid fa-file-pdf"></i> Stampa PDF
    </a>
    <?php if($can_edit): ?><button onclick="openPosModal()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuova posizione</button><?php endif; ?>
  </div>
</div>

<?=$msg?>

<form method="GET" class="filter-bar no-print">
  <?= route_slug_field() ?>
  <div class="fg"><label>Stato</label>
    <select name="f_st"><option value="">Tutti</option>
    <?php foreach(['draft'=>'Bozze','open'=>'Aperte','paused'=>'In pausa','closed'=>'Chiuse'] as $v=>$l): ?>
      <option value="<?=$v?>" <?=$f_status===$v?'selected':''?>><?=$l?></option>
    <?php endforeach; ?>
    </select>
  </div>
  <div class="fg"><label>Priorità</label><select name="f_pr"><option value="">Tutte</option>
    <?php foreach(['Urgente','Alta','Media','Bassa'] as $p): ?><option value="<?=$p?>" <?=$f_prio===$p?'selected':''?>><?=$p?></option><?php endforeach; ?>
  </select></div>
  <div class="fg"><label>Brand</label><select name="f_br"><option value="0">Tutti</option>
    <?php foreach($brands as $b): ?><option value="<?=$b['id']?>" <?=$f_brand==$b['id']?'selected':''?>><?=h($b['name'])?></option><?php endforeach; ?>
  </select></div>
  <button type="submit" class="btn btn-primary">Filtra</button>
  <a href="recruiting_posizioni.php" class="btn">Reset</a>
</form>

<?php if(empty($pos_list)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
  <i class="fa-solid fa-briefcase" style="font-size:40px;margin-bottom:14px;display:block;opacity:.4"></i>
  Nessuna posizione trovata.
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:18px">
<?php foreach($pos_list as $p):
  [$pb,$pc] = $prio_style[$p['priority']] ?? ['#f1f5f9','#475569'];
  $days_open = $p['opened_at'] ? (new DateTime())->diff(new DateTime($p['opened_at']))->days : null;
?>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow:hidden;display:flex;flex-direction:column">
  <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:flex-start">
    <div style="flex:1;min-width:0">
      <div style="font-weight:800;font-size:14px;color:#1e293b;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h($p['title'])?></div>
      <div style="font-size:11px;color:var(--muted)"><?=$p['department']?h($p['department']).' · ':''?><?=h($p['contract_type'])?><?=$p['brand_name']?' · '.h($p['brand_name']):''?></div>
      <?php
        // v5.02: badge clienti associati
        $pos_clients = $position_clients_map[$p['id']] ?? [];
        if (!empty($pos_clients)):
      ?>
      <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px">
        <?php foreach ($pos_clients as $cli): ?>
          <span title="Cliente: <?=h($cli['name'])?>"
                style="background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:600;display:inline-flex;align-items:center;gap:3px">
            <i class="fa-solid fa-building-user" style="font-size:9px"></i> <?=h(mb_strimwidth($cli['name'], 0, 20, '…'))?>
          </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:5px;flex-direction:column;align-items:flex-end;margin-left:10px">
      <span class="badge <?=$status_badge[$p['status']]??'badge-neutral'?>"><?=$status_label[$p['status']]??$p['status']?></span>
      <span style="background:<?=$pb?>;color:<?=$pc?>;padding:2px 7px;border-radius:10px;font-size:9px;font-weight:800;text-transform:uppercase"><?=$p['priority']?></span>
    </div>
  </div>
  <div style="padding:14px 18px;flex:1">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px">
      <div style="background:#f8fafc;padding:10px;border-radius:8px;text-align:center"><div style="font-size:18px;font-weight:800;color:#8b5cf6"><?=$p['pip']?></div><div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase">Pipeline</div></div>
      <?php
        $expected = (int)($p['positions_expected'] ?? 1);
        $hired_n  = (int)$p['hired'];
        $pct = $expected > 0 ? min(100, round(($hired_n / $expected) * 100)) : 0;
        $bar_color = $pct >= 100 ? '#10b981' : ($pct >= 50 ? '#f59e0b' : '#94a3b8');
      ?>
      <div style="background:#f8fafc;padding:10px;border-radius:8px;text-align:center" title="<?=$hired_n?> assunti su <?=$expected?> attesi (<?=$pct?>%)">
        <div style="font-size:18px;font-weight:800;color:var(--success)"><?=$hired_n?>/<?=$expected?></div>
        <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase">Assunti / Attesi</div>
        <div style="height:3px;background:#e2e8f0;border-radius:2px;margin-top:6px;overflow:hidden">
          <div style="width:<?=$pct?>%;height:100%;background:<?=$bar_color?>;transition:width .3s"></div>
        </div>
      </div>
      <div style="background:#f8fafc;padding:10px;border-radius:8px;text-align:center"><div style="font-size:18px;font-weight:800;color:<?=$days_open>60?'var(--warning)':'#1e293b'?>"><?=$days_open??'—'?></div><div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase">Giorni</div></div>
    </div>
    <div style="font-size:11px;color:var(--muted)">
      <?php if($p['tl_fn']): ?><i class="fa-solid fa-user-tie" style="width:14px"></i> <?=h($p['tl_fn'].' '.$p['tl_ln'])?><br><?php endif; ?>
      <i class="fa-solid fa-house-laptop" style="width:14px"></i> <?=h($p['remote_policy'])?>
      <?php if($p['target_date']): ?> · Target: <?=format_date($p['target_date'])?><?php endif; ?>
    </div>
  </div>
  <div style="padding:11px 18px;border-top:1px solid var(--border);display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
    <button onclick='openPreview(<?=htmlspecialchars(json_encode(array_merge($p, ['_clients' => $position_clients_map[$p['id']] ?? []])),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm" style="background:#dcfce7;color:#15803d;border-color:#86efac" title="Anteprima"><i class="fa-solid fa-eye"></i></button>
    <button onclick='openExport(<?=htmlspecialchars(json_encode($p),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm" style="background:#dbeafe;color:#1d4ed8;border-color:#93c5fd" title="Export multiposting"><i class="fa-solid fa-share-nodes"></i></button>
    <a href="position_history.php?id=<?=$p['id']?>" class="btn btn-sm" style="background:#ede9fe;color:#5b21b6;border-color:#c4b5fd" title="Storico cambi"><i class="fa-solid fa-clock-rotate-left"></i></a>
    <a href="recruiting_candidati.php?f_pos=<?=$p['id']?>" class="btn btn-blue btn-sm"><i class="fa-solid fa-users"></i></a>
    <?php if($can_edit): ?>
    <button onclick='openPosModal(<?=htmlspecialchars(json_encode(array_merge($p, ['_clients' => $position_clients_map[$p['id']] ?? []])),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm"><i class="fa-solid fa-pen"></i></button>
    <?php endif; ?>
    <?php // v1.7.32: bottoni workflow status ?>
    <?php if ($p['status'] === 'open' && $can_edit): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Mettere in pausa la posizione?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="pause"><input type="hidden" name="position_id" value="<?=$p['id']?>">
      <button type="submit" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border-color:#fcd34d" title="Pausa"><i class="fa-solid fa-pause"></i></button>
    </form>
    <form method="POST" style="display:inline" onsubmit="return confirm('Chiudere manualmente la posizione?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="close"><input type="hidden" name="position_id" value="<?=$p['id']?>">
      <button type="submit" class="btn btn-sm" style="background:#dbeafe;color:#1d4ed8;border-color:#93c5fd" title="Chiudi posizione"><i class="fa-solid fa-lock"></i></button>
    </form>
    <?php elseif (in_array($p['status'], ['paused','closed'], true) && $can_edit): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Riaprire la posizione?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reopen"><input type="hidden" name="position_id" value="<?=$p['id']?>">
      <button type="submit" class="btn btn-sm" style="background:#d1fae5;color:#065f46;border-color:#86efac" title="Riapri"><i class="fa-solid fa-play"></i></button>
    </form>
    <?php endif; ?>
    <?php if($can_approve): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare la posizione?')">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="position_id" value="<?=$p['id']?>">
      <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ═══ MODAL: NUOVA/MODIFICA POSIZIONE ═══ -->
<?php if($can_edit): ?>
<div id="mPos" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:820px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 style="margin:0;font-size:16px" id="mPosTitle">Nuova posizione</h3>
      <button onclick="document.getElementById('mPos').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="position_id" id="p_id" value="0">

      <!-- Sezione 1: Info base -->
      <div style="background:#f8fafc;padding:14px;border-radius:10px;border:1px solid var(--border);margin-bottom:14px">
        <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-briefcase"></i> Informazioni base</div>
        <div class="grid-2">
          <div class="form-group span-2"><label>Titolo posizione *</label><input type="text" name="title" id="p_title" required></div>
          <div class="form-group"><label>Dipartimento</label><input type="text" name="department" id="p_dept"></div>
          <div class="form-group"><label>Brand</label><select name="brand_id" id="p_brand"><option value="0">Nessuno</option>
            <?php foreach($brands as $b): ?><option value="<?=$b['id']?>"><?=h($b['name'])?></option><?php endforeach; ?>
          </select></div>
          <div class="form-group"><label>Team Leader</label><select name="team_leader_id" id="p_tl"><option value="0">Non assegnato</option>
            <?php foreach($tls as $t): ?><option value="<?=$t['id']?>"><?=h(($t['last_name']??'').' '.($t['first_name']??''))?></option><?php endforeach; ?>
          </select></div>
          <div class="form-group"><label>Priorità</label><select name="priority" id="p_prio">
            <?php foreach(['Bassa','Media','Alta','Urgente'] as $pr): ?><option value="<?=$pr?>"><?=$pr?></option><?php endforeach; ?>
          </select></div>
          <div class="form-group">
            <label>Posizioni attese <span style="color:#94a3b8;font-weight:400;font-size:10px">(numero figure da assumere)</span></label>
            <input type="number" name="positions_expected" id="p_pe" min="1" max="999" step="1" value="1" placeholder="1">
          </div>
          <div class="form-group"><label>Stato</label><select name="status" id="p_st">
            <option value="draft">Bozza</option><?php if($can_approve): ?><option value="open">Aperta</option><?php endif; ?>
          </select></div>
          <div class="form-group"><label>Tipo contratto</label><select name="contract_type" id="p_ct">
            <?php foreach(['Indeterminato','Determinato','Somministrazione','Consulenza','Stage'] as $ct): ?><option value="<?=$ct?>"><?=$ct?></option><?php endforeach; ?>
          </select></div>
          <div class="form-group"><label>Modalità</label><select name="remote_policy" id="p_rp">
            <?php foreach(['In sede','Ibrido','Full Remote'] as $rp): ?><option value="<?=$rp?>"><?=$rp?></option><?php endforeach; ?>
          </select></div>
          <div class="form-group"><label>Sede</label><input type="text" name="location" id="p_loc"></div>
          <div class="form-group"><label>Data target</label><input type="date" name="target_date" id="p_td"></div>
        </div>
      </div>

      <!-- Sezione compenso (v5: storicizzato) -->
      <div style="background:#f0fdf4;padding:14px;border-radius:10px;border:1px solid #bbf7d0;margin-bottom:14px">
        <div style="font-size:10px;font-weight:700;color:#065f46;text-transform:uppercase;margin-bottom:10px">
          <i class="fa-solid fa-coins"></i> Compenso (modifiche storicizzate automaticamente)
        </div>
        <div class="grid-2" style="margin-bottom:10px">
          <div class="form-group" style="margin:0">
            <label>RAL min (€)</label>
            <input type="number" step="100" min="0" name="ral_min" id="p_ral_min" placeholder="es. 30000">
          </div>
          <div class="form-group" style="margin:0">
            <label>RAL max (€)</label>
            <input type="number" step="100" min="0" name="ral_max" id="p_ral_max" placeholder="es. 45000">
          </div>
        </div>
        <div class="form-group" style="margin:0">
          <label>Benefits</label>
          <textarea name="benefits" id="p_benefits" rows="2" placeholder="Auto aziendale, buoni pasto 7€/giorno, smart working 3gg/sett, formazione 2000€/anno, ..."></textarea>
        </div>
      </div>

      <!-- Sezione clienti (v5.02: posizione aperta per uno o più clienti) -->
      <div style="background:#eff6ff;padding:14px;border-radius:10px;border:1px solid #bfdbfe;margin-bottom:14px">
        <div style="font-size:10px;font-weight:700;color:#1e40af;text-transform:uppercase;margin-bottom:10px">
          <i class="fa-solid fa-building-user"></i> Clienti per cui è aperta la selezione
        </div>
        <div class="form-group" style="margin:0">
          <label>Aggiungi cliente</label>
          <select id="p_client_picker" onchange="addClient(this.value)">
            <option value="">— seleziona cliente —</option>
            <?php foreach ($clients_list as $cli): ?>
              <option value="<?=$cli['id']?>" data-name="<?=h($cli['name'])?>" data-internal="<?=$cli['is_internal_company']?>">
                <?=h($cli['name'])?><?= $cli['is_internal_company'] ? ' (gruppo)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:11px;color:#1e40af;margin-top:6px">
            <i class="fa-solid fa-circle-info"></i> Più clienti possibili. <a href="manage_clients.php" target="_blank" style="color:#1e40af;text-decoration:underline">Gestisci anagrafica clienti</a>
          </div>
        </div>
        <div id="p_clients_chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;min-height:30px;padding:6px;background:#fff;border-radius:6px;border:1px dashed #bfdbfe"></div>
      </div>

      <!-- Sezione 2: Master text (auto-precaricati, modificabili) -->
      <div style="background:#fffbeb;padding:14px;border-radius:10px;border:1px solid #fde68a;margin-bottom:14px">
        <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-clipboard-list"></i> Testi preimpostati (modificabili — gestiti centralmente)</div>
        <div class="form-group"><label>Presentazione azienda</label><textarea name="presentation_text" id="p_pres" rows="4"><?=h($master_pres)?></textarea></div>
        <div class="form-group" style="margin-bottom:0"><label>Riferimenti di genere / GDPR</label><textarea name="gender_disclaimer" id="p_gen" rows="3"><?=h($master_gen)?></textarea></div>
      </div>

      <!-- Sezione 3: Campi con templates -->
      <div style="background:#eff6ff;padding:14px;border-radius:10px;border:1px solid #bfdbfe;margin-bottom:14px">
        <div style="font-size:10px;font-weight:700;color:#1e40af;text-transform:uppercase;margin-bottom:10px"><i class="fa-solid fa-pen-to-square"></i> Contenuti specifici (libero o da template)</div>

        <div class="form-group">
          <label style="display:flex;justify-content:space-between;align-items:center">
            <span><i class="fa-solid fa-circle-info" style="color:#1e40af"></i> Informazioni offerta lavoro <strong style="color:#dc2626">★ in evidenza</strong></span>
            <select onchange="loadTpl('p_offer',this.value)" style="font-size:10px;padding:3px 6px"><option value="">📋 Carica template...</option>
            <?php foreach($templates_by_type['offer_info'] ?? [] as $t): ?><option value="<?=h($t['content'])?>"><?=h($t['name'])?></option><?php endforeach; ?>
            </select>
          </label>
          <textarea name="offer_info" id="p_offer" rows="4" placeholder="Sede, RAL, smart working, benefit principali..."></textarea>
        </div>

        <div class="form-group">
          <label style="display:flex;justify-content:space-between;align-items:center">
            <span>Hard Skills</span>
            <select onchange="loadTpl('p_hard',this.value)" style="font-size:10px;padding:3px 6px"><option value="">📋 Carica template...</option>
            <?php foreach($templates_by_type['hard_skills'] ?? [] as $t): ?><option value="<?=h($t['content'])?>"><?=h($t['name'])?></option><?php endforeach; ?>
            </select>
          </label>
          <textarea name="hard_skills" id="p_hard" rows="4" placeholder="Competenze tecniche richieste..."></textarea>
        </div>

        <div class="form-group">
          <label style="display:flex;justify-content:space-between;align-items:center">
            <span>Soft Skills</span>
            <select onchange="loadTpl('p_soft',this.value)" style="font-size:10px;padding:3px 6px"><option value="">📋 Carica template...</option>
            <?php foreach($templates_by_type['soft_skills'] ?? [] as $t): ?><option value="<?=h($t['content'])?>"><?=h($t['name'])?></option><?php endforeach; ?>
            </select>
          </label>
          <textarea name="soft_skills" id="p_soft" rows="3" placeholder="Competenze trasversali..."></textarea>
        </div>

        <div class="form-group">
          <label>Nice to have</label>
          <textarea name="nice_to_have" id="p_nth" rows="2" placeholder="Requisiti preferenziali..."></textarea>
        </div>

        <div class="form-group" style="margin-bottom:0">
          <label style="display:flex;justify-content:space-between;align-items:center">
            <span>We Offer</span>
            <select onchange="loadTpl('p_weoff',this.value)" style="font-size:10px;padding:3px 6px"><option value="">📋 Carica template...</option>
            <?php foreach($templates_by_type['we_offer'] ?? [] as $t): ?><option value="<?=h($t['content'])?>"><?=h($t['name'])?></option><?php endforeach; ?>
            </select>
          </label>
          <textarea name="we_offer" id="p_weoff" rows="4" placeholder="Cosa offriamo..."></textarea>
        </div>
      </div>

      <!-- Campi tecnici legacy -->
      <div class="form-group"><label>Skills richieste (tag virgola, per filtri)</label><input type="text" name="required_skills" id="p_sk" placeholder="AWS, Python, Docker..."></div>
      <div class="form-group"><label>Descrizione breve (interna)</label><textarea name="description" id="p_desc" rows="2"></textarea></div>

      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px"><i class="fa-solid fa-floppy-disk"></i> Salva posizione</button>
        <button type="button" onclick="document.getElementById('mPos').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: PREVIEW ═══ -->
<div id="mPrev" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:780px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 style="margin:0;font-size:16px"><i class="fa-solid fa-eye" style="color:#15803d"></i> Anteprima annuncio</h3>
      <button onclick="document.getElementById('mPrev').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <div id="mPrevBody"></div>
  </div>
</div>

<!-- ═══ MODAL: EXPORT MULTIPOSTING ═══ -->
<div id="mExp" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:780px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 style="margin:0;font-size:16px"><i class="fa-solid fa-share-nodes" style="color:#1d4ed8"></i> Export per multiposting</h3>
      <button onclick="document.getElementById('mExp').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-bottom:10px">Blocco di testo concatenato pronto per essere copiato sui portali (LinkedIn, InfoJobs, Indeed, ecc.)</p>
    <textarea id="mExpText" rows="22" style="width:100%;font-family:Consolas,Monaco,monospace;font-size:11px;padding:12px;border:1px solid var(--border);border-radius:8px"></textarea>
    <div style="display:flex;gap:10px;margin-top:10px">
      <button onclick="copyExp()" class="btn btn-primary" style="flex:1;justify-content:center"><i class="fa-solid fa-copy"></i> Copia negli appunti</button>
      <button onclick="document.getElementById('mExp').style.display='none'" class="btn" style="flex:1;justify-content:center">Chiudi</button>
    </div>
  </div>
</div>

<!-- ═══ MODAL: GESTIONE MASTER TEXT (HR) ═══ -->
<?php if($can_approve): ?>
<div id="mMaster" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:760px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 style="margin:0;font-size:16px"><i class="fa-solid fa-clipboard-list" style="color:#a16207"></i> Master text — Storico e nuova versione</h3>
      <button onclick="document.getElementById('mMaster').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-bottom:14px">Modificare uno di questi testi crea una nuova versione. Le posizioni esistenti continueranno a usare la versione che avevano al momento del salvataggio.</p>

    <?php foreach(['presentation'=>'Presentazione azienda','gender_disclaimer'=>'Riferimenti di genere / GDPR'] as $tt=>$tlabel):
      $current = null;
      foreach($master_history[$tt] ?? [] as $h) { if ($h['is_current']) { $current = $h; break; } }
    ?>
    <div style="background:#fffbeb;padding:14px;border-radius:10px;border:1px solid #fde68a;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div style="font-weight:700;font-size:13px;color:#92400e"><?=$tlabel?></div>
        <div style="font-size:10px;color:#92400e">v<?=$current['version']??'—'?> attiva · <?=count($master_history[$tt]??[])?> versioni totali</div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_master_text">
        <input type="hidden" name="text_type" value="<?=$tt?>">
        <textarea name="content" rows="5" required style="margin-bottom:8px"><?=h($current['content']??'')?></textarea>
        <input type="text" name="notes" placeholder="Note sulla modifica (es. 'Aggiornamento privacy GDPR 2025')" style="margin-bottom:8px">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva nuova versione</button>
      </form>

      <?php if(count($master_history[$tt]??[]) > 1): ?>
      <details style="margin-top:10px">
        <summary style="font-size:11px;font-weight:600;color:#92400e;cursor:pointer">📜 Vedi storico versioni</summary>
        <div style="margin-top:8px;font-size:11px">
          <?php foreach($master_history[$tt] as $h): if($h['is_current']) continue; ?>
          <div style="padding:8px;background:#fff;border-radius:6px;margin-bottom:6px;border:1px solid #fed7aa">
            <div style="font-weight:700">v<?=$h['version']?> · <?=date('d/m/Y H:i', strtotime($h['created_at']))?> · <?=h($h['author']??'')?></div>
            <?php if($h['notes']): ?><div style="color:#92400e;font-style:italic;font-size:10px">"<?=h($h['notes'])?>"</div><?php endif; ?>
            <div style="margin-top:4px;color:#475569;font-size:10px;max-height:60px;overflow:hidden"><?=h(mb_substr($h['content'],0,200))?>...</div>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: TEMPLATES ═══ -->
<div id="mTemplates" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:760px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 style="margin:0;font-size:16px"><i class="fa-solid fa-layer-group" style="color:#5b21b6"></i> Catalogo template</h3>
      <button onclick="document.getElementById('mTemplates').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>

    <form method="POST" id="tplForm" style="background:#ede9fe;padding:14px;border-radius:10px;margin-bottom:18px">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="template_id" id="tpl_edit_id" value="">
      <div style="font-weight:700;font-size:13px;color:#5b21b6;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center">
        <span id="tplFormTitle"><i class="fa-solid fa-plus"></i> Nuovo template</span>
        <button type="button" id="tplResetBtn" onclick="resetTplForm()" style="display:none;background:none;border:none;color:#5b21b6;font-size:11px;cursor:pointer;text-decoration:underline">
          <i class="fa-solid fa-xmark"></i> annulla modifica
        </button>
      </div>
      <div class="grid-2">
        <div class="form-group"><label>Tipo</label><select name="template_type" id="tpl_type" required>
          <option value="hard_skills">Hard Skills</option>
          <option value="soft_skills">Soft Skills</option>
          <option value="we_offer">We Offer</option>
          <option value="offer_info">Informazioni offerta</option>
          <option value="description">Descrizione</option>
          <option value="nice_to_have">Nice to have</option>
        </select></div>
        <div class="form-group"><label>Nome breve</label><input type="text" name="name" id="tpl_name" required placeholder="Es. Sviluppatore Java Senior"></div>
      </div>
      <div class="form-group" style="margin-bottom:10px"><label>Contenuto</label><textarea name="content" id="tpl_content_field" rows="5" required></textarea></div>
      <div class="form-group" style="margin-bottom:10px"><label>Note versione <span style="color:#94a3b8;font-weight:400;font-size:10px">(opzionale)</span></label><input type="text" name="notes" id="tpl_notes" placeholder="Es. revisione GDPR, aggiornamento RAL"></div>
      <button type="submit" class="btn btn-primary btn-sm" id="tplSaveBtn"><i class="fa-solid fa-floppy-disk"></i> Salva template</button>
      <span style="font-size:11px;color:#5b21b6;margin-left:10px"><i class="fa-solid fa-circle-info"></i> Modificando un template con stesso nome viene creata una nuova versione storicizzata</span>
    </form>

    <?php
    $type_labels = ['hard_skills'=>'Hard Skills','soft_skills'=>'Soft Skills','we_offer'=>'We Offer','offer_info'=>'Informazioni offerta','description'=>'Descrizione','nice_to_have'=>'Nice to have'];
    foreach($type_labels as $tt=>$tl):
      if(empty($templates_by_type[$tt])) continue;
    ?>
    <div style="margin-bottom:14px">
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:6px"><?=$tl?> (<?=count($templates_by_type[$tt])?>)</div>
      <?php foreach($templates_by_type[$tt] as $t):
        $tpl_id = (int)$t['id'];
        $version = (int)($t['version'] ?? 1);
      ?>
      <div style="padding:10px;background:#f8fafc;border-radius:8px;border:1px solid var(--border);margin-bottom:6px" id="tpl_card_<?=$tpl_id?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:12px;color:#5b21b6">
              <?=h($t['name'])?>
              <span style="background:#5b21b6;color:#fff;font-size:9px;padding:1px 6px;border-radius:8px;margin-left:6px">v<?=$version?></span>
            </div>
            <div id="tpl_content_<?=$tpl_id?>" style="font-size:11px;color:#64748b;margin-top:4px;max-height:60px;overflow:hidden;white-space:pre-wrap"><?=h($t['content'])?></div>
          </div>
          <div style="display:flex;gap:4px;flex-shrink:0">
            <button type="button" class="btn btn-sm js-tpl-edit"
                    data-tpl-id="<?=$tpl_id?>"
                    data-tpl-type="<?=h($tt)?>"
                    data-tpl-name="<?=h($t['name'])?>"
                    data-tpl-content="<?=h($t['content'])?>"
                    title="Modifica template"
                    style="padding:4px 8px;background:#fff;color:#5b21b6;border-color:#c4b5fd">
              <i class="fa-solid fa-pen"></i>
            </button>
            <?php if ($can_approve): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare il template <?=h(addslashes($t['name']))?>?\nLa versione resterà nello storico per audit.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_template">
              <input type="hidden" name="template_id" value="<?=$tpl_id?>">
              <button type="submit" class="btn btn-danger btn-sm" style="padding:4px 8px" title="Elimina template (soft delete)">
                <i class="fa-solid fa-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function loadTpl(targetId, content) {
  if (content) document.getElementById(targetId).value = content;
}

function openPosModal(d=null){
  const fields={id:'id',title:'title',dept:'department',brand:'brand_id',tl:'team_leader_id',
                prio:'priority',st:'status',ct:'contract_type',rp:'remote_policy',
                loc:'location',td:'target_date',sk:'required_skills',nth:'nice_to_have',desc:'description',
                pres:'presentation_text',gen:'gender_disclaimer',offer:'offer_info',
                hard:'hard_skills',soft:'soft_skills',weoff:'we_offer',
                /* v5: campi compenso storicizzati */
                ral_min:'ral_min',ral_max:'ral_max',benefits:'benefits',
                pe:'positions_expected'};
  for(const[f] of Object.entries(fields)){const el=document.getElementById('p_'+f);if(el)el.value='';}
  document.getElementById('p_id').value=0;
  document.getElementById('mPosTitle').textContent='Nuova posizione';
  document.getElementById('p_prio').value='Media';
  document.getElementById('p_st').value='draft';
  if(document.getElementById('p_pe')) document.getElementById('p_pe').value=1;
  document.getElementById('p_rp').value='Ibrido';
  document.getElementById('p_ct').value='Indeterminato';
  // v5.02: reset chips clienti
  if(typeof clearClients==='function') clearClients();
  // Precarica master text per nuove posizioni
  if(!d){
    document.getElementById('p_pres').value=<?=json_encode($master_pres)?>;
    document.getElementById('p_gen').value=<?=json_encode($master_gen)?>;
  }
  if(d){
    document.getElementById('p_id').value=d.id;
    document.getElementById('mPosTitle').textContent='Modifica: '+d.title;
    for(const[f,k] of Object.entries(fields)){
      const el=document.getElementById('p_'+f);
      if(el&&d[k]!==null&&d[k]!==undefined)el.value=d[k];
    }
    // v5.02: popola chips clienti dalla mappa
    if (d._clients && typeof setClients==='function') setClients(d._clients);
  }
  document.getElementById('mPos').style.display='flex';
}

function openPreview(d) {
  var html = '';

  /* ─── Header ─────────────────────────────────────────────────── */
  /* Riga status + priorità in alto */
  var statusMap = {draft:['Bozza','#64748b'],open:['Aperta','#10b981'],paused:['In pausa','#f59e0b'],closed:['Chiusa','#0ea5e9'],cancelled:['Annullata','#ef4444']};
  var statusInfo = statusMap[d.status] || ['—','#94a3b8'];
  var prioColors = {'Bassa':'#64748b','Media':'#0ea5e9','Alta':'#f59e0b','Urgente':'#ef4444'};
  var prioColor = prioColors[d.priority] || '#94a3b8';

  html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px;flex-wrap:wrap">';
  html += '<div style="flex:1;min-width:240px">';
  html += '<h2 style="margin:0 0 4px;color:var(--p);font-size:20px">' + escHtml(d.title) + '</h2>';
  if (d.id) html += '<div style="font-size:11px;color:#94a3b8">Posizione #' + parseInt(d.id, 10) + '</div>';
  html += '</div>';
  html += '<div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end">';
  html += '<span style="background:' + statusInfo[1] + ';color:#fff;padding:3px 10px;border-radius:12px;font-size:10px;font-weight:800;text-transform:uppercase">' + statusInfo[0] + '</span>';
  if (d.priority) html += '<span style="background:' + prioColor + ';color:#fff;padding:3px 10px;border-radius:12px;font-size:10px;font-weight:800;text-transform:uppercase">' + escHtml(d.priority) + '</span>';
  html += '</div>';
  html += '</div>';

  /* Riga meta (dipartimento, brand, sede, contratto, modalità) */
  html += '<div style="font-size:12px;color:#64748b;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:6px">';
  var meta = [];
  if (d.department)    meta.push('<i class="fa-solid fa-building-columns"></i> ' + escHtml(d.department));
  if (d.brand_name)    meta.push('<i class="fa-solid fa-tag"></i> ' + escHtml(d.brand_name));
  if (d.location)      meta.push('<i class="fa-solid fa-location-dot"></i> ' + escHtml(d.location));
  if (d.contract_type) meta.push('<i class="fa-solid fa-file-contract"></i> ' + escHtml(d.contract_type));
  if (d.remote_policy) meta.push('<i class="fa-solid fa-laptop-house"></i> ' + escHtml(d.remote_policy));
  html += meta.join(' &nbsp;·&nbsp; ');
  html += '</div>';

  /* ─── Box info chiave (date + statistiche + figure) ────────── */
  html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:16px">';

  var pe = parseInt(d.positions_expected || 1, 10);
  var hired = parseInt(d.hired || 0, 10);
  var pct = pe > 0 ? Math.min(100, Math.round((hired / pe) * 100)) : 0;
  var barColor = pct >= 100 ? '#10b981' : (pct >= 50 ? '#f59e0b' : '#94a3b8');

  html += statBox('🎯 Figure cercate', '<strong>' + hired + '</strong> / ' + pe, '#5b21b6', '#ede9fe');
  html += '<div style="background:#f8fafc;padding:8px 12px;border-radius:8px;border-left:3px solid ' + barColor + '">';
  html += '<div style="font-size:9px;color:#64748b;font-weight:700;text-transform:uppercase">Avanzamento</div>';
  html += '<div style="height:6px;background:#e2e8f0;border-radius:3px;margin-top:6px;overflow:hidden"><div style="width:' + pct + '%;height:100%;background:' + barColor + '"></div></div>';
  html += '<div style="font-size:11px;color:#64748b;margin-top:4px;font-weight:600">' + pct + '%</div>';
  html += '</div>';

  html += statBox('📥 Candidati ricevuti', String(parseInt(d.pip || 0, 10)), '#1e40af', '#dbeafe');

  if (d.opened_at) html += statBox('📅 Aperta il', fmtDate(d.opened_at), '#065f46', '#dcfce7');
  if (d.target_date) html += statBox('🎯 Target chiusura', fmtDate(d.target_date), '#92400e', '#fef3c7');
  if (d.closed_at) html += statBox('🏁 Chiusa il', fmtDate(d.closed_at), '#0c4a6e', '#dbeafe');

  html += '</div>';

  /* ─── Persone coinvolte ──────────────────────────────────────── */
  var people = [];
  if (d.rq_fn || d.rq_ln) people.push({lbl:'Richiedente', val:(d.rq_fn||'') + ' ' + (d.rq_ln||'')});
  if (d.tl_fn || d.tl_ln) people.push({lbl:'Team Leader', val:(d.tl_fn||'') + ' ' + (d.tl_ln||'')});
  if (people.length > 0) {
    html += '<div style="background:#f8fafc;padding:10px 14px;border-radius:8px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:18px">';
    people.forEach(function(p) {
      html += '<div><div style="font-size:9px;color:#64748b;font-weight:700;text-transform:uppercase">' + p.lbl + '</div>';
      html += '<div style="font-size:13px;font-weight:600;color:#1e293b;margin-top:2px"><i class="fa-solid fa-user"></i> ' + escHtml(p.val.trim()) + '</div></div>';
    });
    html += '</div>';
  }

  /* ─── Clienti ───────────────────────────────────────────────── */
  if (d._clients && d._clients.length > 0) {
    html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;padding:12px 14px;border-radius:8px;margin-bottom:14px">';
    html += '<div style="font-size:10px;font-weight:800;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🏢 Clienti per cui è aperta la selezione</div>';
    html += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
    d._clients.forEach(function(c) {
      html += '<span style="background:#dbeafe;color:#1e40af;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600">' + escHtml(c.name) + '</span>';
    });
    html += '</div></div>';
  }

  /* ─── Offer info in evidenza ────────────────────────────────── */
  if (d.offer_info) {
    html += '<div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #f59e0b;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 4px 12px rgba(245,158,11,.2)">';
    html += '<div style="font-size:10px;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">⭐ Informazioni dell\'offerta di lavoro</div>';
    html += '<div style="font-size:13px;color:#451a03;white-space:pre-wrap;font-weight:500">' + escHtml(d.offer_info) + '</div>';
    html += '</div>';
  }

  /* ─── Sezioni descrittive ──────────────────────────────────── */
  if (d.presentation_text) html += sec('🏢 Chi siamo',                d.presentation_text);
  if (d.description)       html += sec('📋 Descrizione del ruolo',    d.description);
  if (d.hard_skills)       html += sec('🔧 Hard Skills',              d.hard_skills);
  if (d.soft_skills)       html += sec('💡 Soft Skills',              d.soft_skills);
  if (d.required_skills)   html += sec('✅ Requisiti',                d.required_skills);
  if (d.nice_to_have)      html += sec('✨ Nice to have',             d.nice_to_have);

  /* ─── Compenso & Benefits ───────────────────────────────────── */
  if (d.ral_min || d.ral_max || d.benefits) {
    html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:14px;border-radius:10px;margin-bottom:16px">';
    html += '<div style="font-size:13px;font-weight:800;color:#065f46;margin-bottom:8px">💰 Compenso & Benefits</div>';
    if (d.ral_min || d.ral_max) {
      html += '<div style="font-size:13px;color:#065f46;margin-bottom:6px"><strong>RAL:</strong> ';
      if (d.ral_min && d.ral_max)      html += '€ ' + fmtMoney(d.ral_min) + ' – € ' + fmtMoney(d.ral_max);
      else if (d.ral_min)              html += 'da € ' + fmtMoney(d.ral_min);
      else                              html += 'fino a € ' + fmtMoney(d.ral_max);
      html += '</div>';
    }
    if (d.benefits) {
      html += '<div style="font-size:12px;color:#065f46;white-space:pre-wrap"><strong>Benefits:</strong>\n' + escHtml(d.benefits) + '</div>';
    }
    html += '</div>';
  }

  if (d.we_offer) html += sec('🎁 Cosa offriamo', d.we_offer);

  /* ─── Disclaimer GDPR/parità ───────────────────────────────── */
  if (d.gender_disclaimer) {
    html += '<div style="margin-top:20px;padding:12px;background:#f8fafc;border-left:3px solid #94a3b8;font-size:11px;color:#64748b;font-style:italic;white-space:pre-wrap">' + escHtml(d.gender_disclaimer) + '</div>';
  }

  document.getElementById('mPrevBody').innerHTML = html;
  document.getElementById('mPrev').style.display = 'flex';
}

function statBox(lbl, val, color, bg) {
  return '<div style="background:' + bg + ';padding:8px 12px;border-radius:8px">' +
         '<div style="font-size:9px;color:' + color + ';font-weight:700;text-transform:uppercase">' + lbl + '</div>' +
         '<div style="font-size:14px;font-weight:700;color:' + color + ';margin-top:2px">' + val + '</div>' +
         '</div>';
}

function fmtDate(iso) {
  if (!iso) return '—';
  var parts = String(iso).substring(0,10).split('-');
  return parts.length === 3 ? parts[2]+'/'+parts[1]+'/'+parts[0] : iso;
}

function fmtMoney(v) {
  var n = parseFloat(v);
  if (isNaN(n)) return '0';
  return n.toLocaleString('it-IT', {minimumFractionDigits:0, maximumFractionDigits:0});
}

function sec(title, content) {
  return '<div style="margin-bottom:16px"><div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:6px">' + title + '</div><div style="font-size:12px;color:#475569;white-space:pre-wrap;line-height:1.5">' + escHtml(content) + '</div></div>';
}

function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function openExport(d) {
  // Concatenazione in ordine logico per multiposting
  var parts = [];
  parts.push('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
  parts.push((d.title || '').toUpperCase());
  parts.push('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
  parts.push('');
  if (d.location || d.contract_type || d.remote_policy) {
    var meta = [];
    if (d.location) meta.push('📍 ' + d.location);
    if (d.contract_type) meta.push('💼 ' + d.contract_type);
    if (d.remote_policy) meta.push('🏠 ' + d.remote_policy);
    parts.push(meta.join('  ·  '));
    parts.push('');
  }
  if (d.offer_info) {
    parts.push('⭐ INFORMAZIONI DELL\'OFFERTA DI LAVORO');
    parts.push('────────────────────────────────────');
    parts.push(d.offer_info);
    parts.push('');
  }
  if (d.presentation_text) {
    parts.push('🏢 CHI SIAMO');
    parts.push(d.presentation_text);
    parts.push('');
  }
  if (d.description) {
    parts.push('📋 DESCRIZIONE DEL RUOLO');
    parts.push(d.description);
    parts.push('');
  }
  if (d.hard_skills) {
    parts.push('🔧 HARD SKILLS RICHIESTE');
    parts.push(d.hard_skills);
    parts.push('');
  }
  if (d.soft_skills) {
    parts.push('💡 SOFT SKILLS');
    parts.push(d.soft_skills);
    parts.push('');
  }
  if (d.nice_to_have) {
    parts.push('✨ NICE TO HAVE');
    parts.push(d.nice_to_have);
    parts.push('');
  }
  if (d.we_offer) {
    parts.push('🎁 COSA OFFRIAMO');
    parts.push(d.we_offer);
    parts.push('');
  }
  if (d.gender_disclaimer) {
    parts.push('────────────────────────────────────');
    parts.push(d.gender_disclaimer);
  }
  document.getElementById('mExpText').value = parts.join('\n');
  document.getElementById('mExp').style.display = 'flex';
}

function copyExp() {
  var t = document.getElementById('mExpText');
  t.select(); document.execCommand('copy');
  alert('Testo copiato negli appunti! Incolla sui portali di multiposting.');
}


/* ──────────────────────────────────────────────────────────────────
   v5: Edit template — popola form quando si clicca "Modifica"
   ────────────────────────────────────────────────────────────────── */
function resetTplForm() {
  document.getElementById('tplForm').reset();
  document.getElementById('tpl_edit_id').value = '';
  document.getElementById('tplFormTitle').innerHTML = '<i class="fa-solid fa-plus"></i> Nuovo template';
  document.getElementById('tplSaveBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salva template';
  document.getElementById('tplResetBtn').style.display = 'none';
  document.getElementById('tpl_type').disabled = false;
  document.getElementById('tpl_name').readOnly = false;
}

document.addEventListener('click', function(ev) {
  var btn = ev.target.closest('.js-tpl-edit');
  if (!btn) return;
  ev.preventDefault();

  var name = btn.getAttribute('data-tpl-name') || '';
  var type = btn.getAttribute('data-tpl-type') || '';
  var content = btn.getAttribute('data-tpl-content') || '';

  document.getElementById('tpl_edit_id').value = '';  /* createVersion crea nuova versione, non aggiorna in place */
  document.getElementById('tpl_type').value = type;
  document.getElementById('tpl_type').disabled = true;  /* Tipo non modificabile in edit */
  document.getElementById('tpl_name').value = name;
  document.getElementById('tpl_name').readOnly = true;  /* Nome non modificabile = stessa famiglia */
  document.getElementById('tpl_content_field').value = content;
  document.getElementById('tpl_notes').value = '';
  document.getElementById('tpl_notes').focus();

  document.getElementById('tplFormTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Modifica: <strong>' + name.replace(/[<>&]/g,'') + '</strong>';
  document.getElementById('tplSaveBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Salva nuova versione';
  document.getElementById('tplResetBtn').style.display = 'inline-block';

  /* Scroll al form per mobile */
  document.getElementById('tplForm').scrollIntoView({behavior:'smooth', block:'start'});
});



/* v5.02: gestione chips multi-select clienti nel modal posizione */
function addClient(id) {
  if (!id) return;
  if (document.querySelector('#p_clients_chips [data-client-id="' + id + '"]')) {
    document.getElementById('p_client_picker').value = '';
    return;
  }
  const opt = document.querySelector('#p_client_picker option[value="' + id + '"]');
  if (!opt) return;
  const name = opt.getAttribute('data-name');
  const internal = opt.getAttribute('data-internal') === '1';

  const chip = document.createElement('span');
  chip.setAttribute('data-client-id', id);
  chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:' + (internal ? '#ede9fe' : '#dbeafe') +
                       ';color:' + (internal ? '#5b21b6' : '#1e40af') +
                       ';padding:3px 6px 3px 10px;border-radius:14px;font-size:12px;font-weight:600';
  chip.innerHTML = '<input type="hidden" name="client_ids[]" value="' + id + '">' +
                   (internal ? '<i class="fa-solid fa-building"></i> ' : '') +
                   name.replace(/[<>&]/g, '') +
                   ' <button type="button" onclick="removeClient(this)" style="background:none;border:none;color:inherit;cursor:pointer;padding:0;font-size:14px;line-height:1">&times;</button>';
  document.getElementById('p_clients_chips').appendChild(chip);
  document.getElementById('p_client_picker').value = '';
}

function removeClient(btn) {
  btn.closest('[data-client-id]').remove();
}

function clearClients() {
  document.getElementById('p_clients_chips').innerHTML = '';
}

function setClients(clients) {
  clearClients();
  if (Array.isArray(clients)) {
    clients.forEach(c => addClient(String(c.id)));
  }
}

</script>
<?php require_once('footer.php'); ?>
