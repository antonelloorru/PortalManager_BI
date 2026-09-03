<?php
/**
 * certV 2.0 — programmazione.php   Pianificazione esami
 * FIX: WHERE id=? AND (user_id=? OR ?<=4) → il 3° ? passava $u_role come valore
 *      SQL corretto: controlla user_id oppure verifica ruolo in PHP prima della query
 */
require_once('access_control.php');
require_once('functions.php');

$u_id       = (int)$_SESSION['user_id'];
$u_emp_id = (int)($_SESSION["employee_id"] ?? 0);
$u_role     = (int)($_SESSION['role_id'] ?? 99);
$is_manager = can('edit');

// ── Mappa tipi percorso ─────────────────────────────────────────────────────
$PLAN_TYPES = [
    'formazione'            => ['Formazione',            'fa-graduation-cap','#0369a1','#e0f2fe', true],
    'esame_certificazione'  => ['Esame di certificazione','fa-file-circle-check','#7c3aed','#ede9fe', true],
    'rinnovo'               => ['Rinnovo',               'fa-rotate',       '#059669','#ecfdf5', true],
    'workshop_tecnico'      => ['Workshop tecnico',      'fa-screwdriver-wrench','#d97706','#fffbeb', false],
    'workshop_commerciale'  => ['Workshop commerciale',  'fa-handshake',    '#dc2626','#fee2e2', false],
    'convegno'              => ['Convegno',              'fa-users-rectangle','#6366f1','#eef2ff', false],
]; // [label, icon, color, bg, cert_required]

// ── Auto-migration: plan_type su planned_exams ──────────────────────────────
try { $pdo->query("SELECT plan_type FROM planned_exams LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE planned_exams ADD COLUMN plan_type ENUM('formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno') NOT NULL DEFAULT 'esame_certificazione'"); } catch (\Exception $ex) {}
}
try { $pdo->exec("ALTER TABLE planned_exams MODIFY COLUMN certification_id INT DEFAULT NULL"); } catch (\Exception $e) {}
try { $pdo->query("SELECT plan_type FROM training_plans LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE training_plans ADD COLUMN plan_type ENUM('formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno') NOT NULL DEFAULT 'formazione'"); } catch (\Exception $ex) {}
    try { $pdo->exec("UPDATE training_plans SET plan_type='rinnovo' WHERE is_renewal=1"); } catch (\Exception $ex) {}
}

// Auto-migration: notification tracking
try { $pdo->query("SELECT notified_at FROM planned_exams LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    try { $pdo->exec("ALTER TABLE planned_exams ADD COLUMN notified_at DATETIME DEFAULT NULL COMMENT 'Data invio notifica email'"); } catch (\Exception $ex) {}
    try { $pdo->exec("ALTER TABLE planned_exams ADD COLUMN reminder_7d_sent TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Exception $ex) {}
    try { $pdo->exec("ALTER TABLE planned_exams ADD COLUMN reminder_1d_sent TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Exception $ex) {}
}

/**
 * Invia notifica email + ICS al dipendente per un evento pianificato.
 */
function send_exam_notification(PDO $pdo, int $examId, string $action_type, array $PLAN_TYPES): void
{
    require_once __DIR__ . '/CalendarHelper.php';
    require_once __DIR__ . '/SmtpMailer.php';

    try {
        $q = $pdo->prepare(
            "SELECT pe.*, e.first_name, e.last_name, e.personal_email,
                    u.email user_email, u.notifications_email,
                    c.name cert_name, b.name brand_name,
                    pe.plan_type
             FROM planned_exams pe
             JOIN employees e ON pe.employee_id = e.id
             LEFT JOIN users u ON u.employee_id = e.id AND u.status='active'
             LEFT JOIN certifications c ON pe.certification_id = c.id
             LEFT JOIN brands b ON c.brand_id = b.id
             WHERE pe.id=?"
        );
        $q->execute([$examId]); $exam = $q->fetch(); $q->closeCursor();
        if (!$exam) return;

        $to = $exam['notifications_email'] ?: $exam['user_email'] ?: $exam['personal_email'];
        if (!$to) return;

        $pt = $PLAN_TYPES[$exam['plan_type'] ?? 'esame_certificazione'] ?? $PLAN_TYPES['esame_certificazione'];
        $eventTitle = $pt[0] . ': ' . ($exam['cert_name'] ?? $exam['notes'] ?? 'Evento formativo');
        $empName = $exam['first_name'] . ' ' . $exam['last_name'];
        $location = $exam['exam_location'] ?? $exam['exam_center'] ?? '';

        $settings = load_settings();
        $portalUrl = rtrim($settings['app_url'] ?? '', '/') . '/programmazione.php';
        $organizerEmail = $settings['mail_from'] ?? 'noreply@certv.local';

        // Genera ICS
        $icsContent = CalendarHelper::generateICS(
            $eventTitle,
            $exam['planned_date'],
            '09:00', 2,
            $location,
            "Brand: " . ($exam['brand_name'] ?? 'N/A') . "\nNote: " . ($exam['notes'] ?? ''),
            $organizerEmail
        );

        // Google Calendar link
        $googleUrl = CalendarHelper::googleCalendarUrl(
            $eventTitle, $exam['planned_date'], '09:00', 2,
            $location,
            "Brand: " . ($exam['brand_name'] ?? 'N/A') . " | Note: " . ($exam['notes'] ?? '')
        );

        // HTML email
        $htmlBody = CalendarHelper::buildNotificationHtml(
            $empName, $eventTitle, $pt[0], $exam['planned_date'],
            $exam['brand_name'] ?? '', $exam['cert_name'] ?? '',
            $location, $exam['notes'] ?? '',
            $googleUrl, $portalUrl
        );

        // Testo plain
        $textBody = "Gentile $empName,\r\n\r\n";
        $textBody .= ($action_type === 'nuovo' ? "È stato pianificato un nuovo impegno" : "Un impegno è stato modificato") . ":\r\n\r\n";
        $textBody .= "Tipologia: {$pt[0]}\r\n";
        $textBody .= "Data: " . date('d/m/Y', strtotime($exam['planned_date'])) . "\r\n";
        if ($exam['brand_name']) $textBody .= "Brand: {$exam['brand_name']}\r\n";
        if ($exam['cert_name']) $textBody .= "Certificazione: {$exam['cert_name']}\r\n";
        if ($location) $textBody .= "Luogo: $location\r\n";
        if ($exam['notes']) $textBody .= "Note: {$exam['notes']}\r\n";
        $textBody .= "\r\nVerifica i dettagli nel portale.\r\n";

        $subject = ($action_type === 'nuovo' ? '📅 Nuovo' : '✏️ Modifica') . ": $eventTitle — " . date('d/m/Y', strtotime($exam['planned_date']));

        // Invio con ICS allegato
        $ok = send_certv_email(
            $to, $subject, $textBody, $htmlBody,
            [], 'programmazione', $examId,
            [['name' => 'evento.ics', 'content' => $icsContent, 'mime' => 'text/calendar; method=PUBLISH']]
        );

        if ($ok) {
            try { $pdo->prepare("UPDATE planned_exams SET notified_at=NOW() WHERE id=?")->execute([$examId]); } catch (\Exception $e) {}
        }

        // Notifica in-app
        if ($exam['user_email']) {
            $uid_q = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $uid_q->execute([$exam['user_email']]); $target_uid = $uid_q->fetchColumn(); $uid_q->closeCursor();
            if ($target_uid) {
                push_notification(
                    ($action_type === 'nuovo' ? 'Nuovo impegno' : 'Impegno modificato') . ": {$pt[0]}",
                    ($exam['cert_name'] ?? $exam['notes'] ?? $eventTitle) . " — " . date('d/m/Y', strtotime($exam['planned_date'])),
                    'asset', 'info', (int)$target_uid, null, 'programmazione.php'
                );
            }
        }

    } catch (\Exception $e) {
        write_log('Programmazione', 'error', "Errore notifica evento #$examId: " . $e->getMessage());
    }
}

// ── CRUD (PRIMA di header.php per PRG) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Aggiungi evento ─────────────────────────────────────────────────────
    if ($action === 'add') {
        $target_id = $is_manager ? (int)($_POST["target_employee_id"] ?? $u_emp_id) : $u_emp_id;
        $cert_id   = !empty($_POST['certification_id']) ? (int)$_POST['certification_id'] : null;
        $date      = $_POST['planned_date'] ?? '';
        $notes     = trim($_POST['notes'] ?? '');
        $plan_type = $_POST['plan_type'] ?? 'esame_certificazione';
        if (!isset($PLAN_TYPES[$plan_type])) $plan_type = 'esame_certificazione';
        $cert_required = $PLAN_TYPES[$plan_type][4];

        if ($date && ($cert_id || !$cert_required)) {
            $pdo->prepare(
                "INSERT INTO planned_exams (employee_id, certification_id, planned_date, notes, plan_type) VALUES (?,?,?,?,?)"
            )->execute([$target_id, $cert_id, $date, $notes ?: null, $plan_type]);
            $new_exam_id = (int)$pdo->lastInsertId();
            write_log('Exams','success',"Pianificato $plan_type cert=" . ($cert_id ?: 'N/A') . " emp=$target_id",$u_id);

            // ── NOTIFICA EMAIL + ICS ─────────────────────────────
            send_exam_notification($pdo, $new_exam_id, 'nuovo', $PLAN_TYPES);

            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-calendar-check'></i> " . $PLAN_TYPES[$plan_type][0] . " inserito in roadmap. Notifica inviata al dipendente.</div>";
        } else {
            $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Compila i campi obbligatori (data" . ($cert_required ? " e certificazione" : "") . ").</div>";
        }
        redirect('programmazione');
    }

    // ── Modifica evento pianificato ──────────────────────────────────────────
    if ($action === 'edit' && isset($_POST['exam_id'])) {
        $eid = (int)$_POST['exam_id'];

        // Verifica permessi: manager può modificare tutto, dipendente solo i propri
        $owner_q = $pdo->prepare("SELECT employee_id, status FROM planned_exams WHERE id=?");
        $owner_q->execute([$eid]); $row = $owner_q->fetch(); $owner_q->closeCursor();

        if (!$row) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Evento non trovato.</div>";
            redirect('programmazione');
        }
        if ($row['status'] !== 'planned') {
            $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Solo gli eventi con stato 'pianificato' possono essere modificati.</div>";
            redirect('programmazione');
        }
        if (!$is_manager && (int)$row['employee_id'] !== $u_emp_id) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Non autorizzato a modificare questo evento.</div>";
            redirect('programmazione');
        }

        $new_cert    = !empty($_POST['certification_id']) ? (int)$_POST['certification_id'] : null;
        $new_date    = $_POST['planned_date'] ?? '';
        $new_notes   = trim($_POST['notes'] ?? '');
        $new_type    = $_POST['plan_type'] ?? 'esame_certificazione';
        if (!isset($PLAN_TYPES[$new_type])) $new_type = 'esame_certificazione';
        $new_emp     = $is_manager && !empty($_POST['target_employee_id']) ? (int)$_POST['target_employee_id'] : (int)$row['employee_id'];

        if ($new_date) {
            $pdo->prepare(
                "UPDATE planned_exams SET employee_id=?, certification_id=?, planned_date=?, notes=?, plan_type=? WHERE id=?"
            )->execute([$new_emp, $new_cert, $new_date, $new_notes ?: null, $new_type, $eid]);
            write_log('Exams','success',"Modificato evento #$eid tipo=$new_type",$u_id);
            send_exam_notification($pdo, $eid, 'modifica', $PLAN_TYPES);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Evento aggiornato. Notifica inviata.</div>";
        } else {
            $_SESSION['flash_msg'] = "<div class='alert alert-warning'>La data è obbligatoria.</div>";
        }
        redirect('programmazione');
    }

    // ── Registra esito ────────────────────────────────────────────────────────
    if ($action === 'complete' && isset($_POST['exam_id'])) {
        $eid    = (int)$_POST['exam_id'];
        $result = in_array($_POST['result']??'', ['passed','failed']) ? $_POST['result'] : 'passed';

        $owner_check = $pdo->prepare("SELECT employee_id FROM planned_exams WHERE id=?");
        $owner_check->execute([$eid]);
        $owner = (int)($owner_check->fetchColumn() ?: 0);
        $owner_check->closeCursor();

        if ($owner === $u_emp_id || $is_manager) {
            $pdo->prepare("UPDATE planned_exams SET status='completed', result=? WHERE id=?")
                ->execute([$result, $eid]);
            if ($result === 'passed') {
                push_notification(
                    'Esame superato — carica il certificato',
                    'Hai superato l\'esame pianificato. Carica il PDF del certificato.',
                    'asset', 'success', $u_id, null, 'upload_certificato.php'
                );
            }
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Esame marcato come " . ($result==='passed'?'<strong>superato ✓</strong>':'<strong>non superato</strong>') . ".</div>";
        } else {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Non autorizzato a modificare questo esame.</div>";
        }
        header("Location: programmazione.php?f_st=completed" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }

    // ── Annulla pianificazione ────────────────────────────────────────────────
    if ($action === 'cancel' && isset($_POST['exam_id'])) {
        $eid = (int)$_POST['exam_id'];
        if ($is_manager) {
            $pdo->prepare("UPDATE planned_exams SET status='cancelled' WHERE id=?")->execute([$eid]);
        } else {
            $pdo->prepare("UPDATE planned_exams SET status='cancelled' WHERE id=? AND employee_id=?")
                ->execute([$eid, $u_emp_id]);
        }
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Pianificazione annullata.</div>";
        header("Location: programmazione.php?f_st=cancelled" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }
}

// ── Output HTML ─────────────────────────────────────────────────────────────
require_once('header.php');

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

// ── Dati ──────────────────────────────────────────────────────────────────────
$f_status = $_GET['f_st'] ?? 'planned';
$f_user   = $is_manager ? (int)($_GET["f_us"] ?? 0) : $u_emp_id;

$where  = ["pe.status=?"]; $params = [$f_status];
if ($f_user && $is_manager) { $where[] = "pe.employee_id=?"; $params[] = $f_user; }
if (!$is_manager)            { $where[] = "pe.employee_id=?"; $params[] = $u_emp_id; }

$exams = $pdo->prepare(
    "SELECT pe.*, e.first_name, e.last_name, c.name cert_name, b.name brand_name,
            DATEDIFF(pe.planned_date, CURDATE()) days_left
     FROM planned_exams pe
     JOIN employees e         ON pe.employee_id = e.id
     LEFT JOIN certifications c ON pe.certification_id = c.id
     LEFT JOIN brands b       ON c.brand_id = b.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY pe.planned_date ASC"
);
$exams->execute($params);
$exam_list = $exams->fetchAll();

$certs = $pdo->query(
    "SELECT c.id, c.name, b.name brand_name FROM certifications c
     JOIN brands b ON c.brand_id=b.id WHERE c.is_active=1 ORDER BY b.name, c.name"
)->fetchAll();

$users = $is_manager
    ? $pdo->query("SELECT id,first_name,last_name FROM employees WHERE status='active' ORDER BY last_name")->fetchAll()
    : [];

$cnt_planned   = (int)$pdo->query("SELECT COUNT(*) FROM planned_exams WHERE status='planned' AND planned_date>=CURDATE()")->fetchColumn();
$cnt_overdue   = (int)$pdo->query("SELECT COUNT(*) FROM planned_exams WHERE status='planned' AND planned_date<CURDATE()")->fetchColumn();
$cnt_completed = (int)$pdo->query("SELECT COUNT(*) FROM planned_exams WHERE status='completed' AND YEAR(planned_date)=YEAR(CURDATE())")->fetchColumn();
?>

<div style="margin-bottom:22px">
  <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
    <i class="fa-solid fa-calendar-plus" style="color:var(--p);margin-right:10px"></i>Pianificazione esami
  </h1>
  <p style="color:var(--muted);font-size:13px">Roadmap personale e di team per il conseguimento delle certificazioni</p>
</div>

<!-- KPI -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px">
  <div class="stat-card" style="border-color:var(--p)">
    <div class="sl">Pianificati</div>
    <div class="sv" style="color:var(--p)"><?=$cnt_planned?></div>
  </div>
  <div class="stat-card" style="border-color:var(--danger)">
    <div class="sl">In ritardo</div>
    <div class="sv" style="color:var(--danger)"><?=$cnt_overdue?></div>
    <?php if($cnt_overdue>0): ?><div style="font-size:10px;color:var(--danger);margin-top:3px">Data passata senza esito</div><?php endif; ?>
  </div>
  <div class="stat-card" style="border-color:var(--success)">
    <div class="sl">Completati (<?=date('Y')?>)</div>
    <div class="sv" style="color:var(--success)"><?=$cnt_completed?></div>
  </div>
</div>

<?=$msg?>

<div style="display:grid;grid-template-columns:310px 1fr;gap:24px">

  <!-- Form pianificazione -->
  <div class="card" style="height:fit-content">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-calendar-plus" style="color:var(--p)"></i> Pianifica attività</span></div>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">

      <div class="form-group">
        <label>Tipo percorso *</label>
        <select name="plan_type" id="prog_plan_type" onchange="toggleCertRequired(this.value)" required>
          <?php foreach($PLAN_TYPES as $k=>[$lbl,$ico,$col,$bg,$req]): ?>
          <option value="<?=$k?>" data-cert-req="<?=$req?1:0?>"><?=$lbl?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if($is_manager): ?>
      <div class="form-group">
        <label>Collaboratore</label>
        <select name="target_employee_id" required>
          <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>" <?=$u['id']===$u_id?'selected':''?>><?=h($u['last_name'].' '.$u['first_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Brand</label>
        <select id="prog_brand" data-cascade="prog_cert" data-entity="certifications" data-param="brand_id">
          <option value="">— Tutti i brand —</option>
          <?php
          // FIX: mostra TUTTI i brand censiti, non solo quelli con certificazioni
          $prog_brands = $pdo->query(
              "SELECT b.id, b.name,
                      (SELECT COUNT(*) FROM certifications c WHERE c.brand_id=b.id AND c.is_active=1) cert_count
               FROM brands b ORDER BY b.name"
          )->fetchAll();
          foreach($prog_brands as $pb): ?>
          <option value="<?=$pb['id']?>"><?=h($pb['name'])?><?=$pb['cert_count']?' ('.$pb['cert_count'].' cert.)':' (nessuna cert.)'?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Certificazione target * <span style="font-size:10px;font-weight:400;color:var(--muted)">— cerca per nome, codice o testo parziale</span></label>
        <input type="hidden" name="certification_id" id="prog_cert_id" required>
        <div style="position:relative">
          <input type="text" id="prog_cert_search" autocomplete="off"
                 placeholder="Digita nome o codice (es. AZ-305, CCNA, Azure...)"
                 style="padding-right:30px">
          <i class="fa-solid fa-magnifying-glass" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:12px;pointer-events:none"></i>
          <div id="prog_cert_results" style="display:none;position:absolute;z-index:100;left:0;right:0;top:100%;max-height:220px;overflow-y:auto;background:#fff;border:1px solid var(--border);border-radius:0 0 8px 8px;box-shadow:0 8px 20px rgba(0,0,0,.12)"></div>
        </div>
        <div id="prog_cert_selected" style="display:none;margin-top:6px;padding:8px 12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;font-size:12px;display:none">
          <span id="prog_cert_label" style="font-weight:700;color:#065f46"></span>
          <span id="prog_cert_ttl" style="margin-left:8px;font-size:10px;color:#047857"></span>
          <button type="button" onclick="clearCertSearch()" style="float:right;border:none;background:none;color:#dc2626;cursor:pointer;font-size:14px">&times;</button>
        </div>
        <!-- Fallback: dropdown tradizionale se JS non carica -->
        <noscript>
          <select name="certification_id" required>
            <option value="">Seleziona...</option>
            <?php foreach($certs as $c): ?>
            <option value="<?=$c['id']?>">[<?=h($c['brand_name'])?>] <?=h($c['name'])?></option>
            <?php endforeach; ?>
          </select>
        </noscript>
      </div>
      <div class="form-group">
        <label>Data prevista esame *</label>
        <input type="date" name="planned_date" required min="<?=date('Y-m-d')?>">
      </div>
      <div class="form-group">
        <label>Note / obiettivo</label>
        <textarea name="notes" rows="2" placeholder="Es: Rinnovo partnership Cisco Gold..."></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:11px">
        <i class="fa-solid fa-calendar-plus"></i> Inserisci in roadmap
      </button>
    </form>
  </div>

  <!-- Lista esami -->
  <div>
    <!-- Tab stato -->
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
      <?php foreach(['planned'=>'Pianificati','completed'=>'Completati','cancelled'=>'Annullati'] as $st=>$lbl): ?>
      <a href="<?= qs_self_safe(['f_st' => $st] + ($f_user ? ['f_us' => $f_user] : [])) ?>"
         style="padding:7px 15px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;
                background:<?=$f_status===$st?'var(--p)':'#f1f5f9'?>;color:<?=$f_status===$st?'#fff':'#475569'?>">
        <?=$lbl?>
      </a>
      <?php endforeach; ?>
      <?php if($is_manager): ?>
      <select onchange="window.location='<?= qs_self_safe(['f_st'=>$f_status]) ?>&f_us='+this.value"
              style="margin-left:auto;padding:6px 10px;border:1px solid var(--border);border-radius:8px;font-size:12px">
        <option value="0">Tutti i collaboratori</option>
        <?php foreach($users as $u): ?>
        <option value="<?=$u['id']?>" <?=$f_user==$u['id']?'selected':''?>><?=h($u['last_name'].' '.$u['first_name'])?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </div>

    <?php if(empty($exam_list)): ?>
    <div style="text-align:center;padding:60px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
      <i class="fa-solid fa-calendar-xmark" style="font-size:40px;margin-bottom:14px;display:block;opacity:.4"></i>
      Nessun esame <?=$f_status==='planned'?'pianificato':$f_status?> trovato.
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach($exam_list as $e):
        $dl    = (int)$e['days_left'];
        $late  = ($dl < 0 && $e['status']==='planned');
        $acc   = $late ? 'var(--danger)' : ($dl<=7&&$e['status']==='planned' ? 'var(--warning)' : ($e['status']==='completed'&&$e['result']==='passed' ? 'var(--success)' : 'var(--p)'));
        $pt    = $PLAN_TYPES[$e['plan_type'] ?? 'esame_certificazione'] ?? $PLAN_TYPES['esame_certificazione'];
      ?>
      <div style="display:flex;align-items:center;gap:14px;padding:16px;background:#fff;border-radius:12px;border:1px solid var(--border);border-left:5px solid <?=$acc?>">
        <!-- Data box -->
        <div style="min-width:70px;background:#f8fafc;padding:10px;border-radius:8px;text-align:center;flex-shrink:0">
          <div style="font-size:20px;font-weight:800;color:<?=$acc?>"><?=date('d',strtotime($e['planned_date']))?></div>
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted)"><?=date('M Y',strtotime($e['planned_date']))?></div>
        </div>
        <!-- Info -->
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px">
            <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:6px;background:<?=$pt[3]?>;color:<?=$pt[2]?>;font-size:10px;font-weight:800">
              <i class="fa-solid <?=$pt[1]?>" style="font-size:9px"></i> <?=$pt[0]?>
            </span>
          </div>
          <div style="font-weight:700;font-size:14px;color:#1e293b"><?=h($e['cert_name'] ?? $e['notes'] ?? '—')?></div>
          <div style="font-size:12px;color:var(--muted);margin-top:3px">
            <?=$e['brand_name'] ? h($e['brand_name']).' · ' : ''?><?=h($e['last_name'].' '.$e['first_name'])?>
          </div>
          <?php if($e['notes'] && $e['cert_name']): ?><div style="font-size:11px;color:var(--muted);font-style:italic;margin-top:4px">"<?=h($e['notes'])?>"</div><?php endif; ?>
          <?php if($e['status']==='completed'): ?>
          <span class="badge <?=$e['result']==='passed'?'badge-success':'badge-danger'?>" style="margin-top:6px">
            <?=$e['result']==='passed'?'✓ Superato':'✗ Non superato'?>
          </span>
          <?php endif; ?>
        </div>
        <!-- Delta giorni -->
        <?php if($e['status']==='planned'): ?>
        <div style="text-align:center;flex-shrink:0;min-width:52px">
          <div style="font-size:18px;font-weight:800;color:<?=$acc?>"><?=$late?'-'.abs($dl):$dl?></div>
          <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase"><?=$late?'RITARDO':'GG'?></div>
        </div>
        <?php endif; ?>
        <!-- Azioni -->
        <div style="display:flex;gap:7px;flex-shrink:0">
          <?php
            $can_edit_this = ($e['status'] === 'planned') && ($is_manager || (int)$e['employee_id'] === $u_emp_id);
          ?>
          <?php if($can_edit_this): ?>
          <button onclick='openEditModal(<?=htmlspecialchars(json_encode($e),ENT_QUOTES,"UTF-8")?>)' class="btn btn-blue btn-sm" title="Modifica">
            <i class="fa-solid fa-pen"></i>
          </button>
          <?php endif; ?>
          <?php if($e['status']==='planned'): ?>
          <button onclick="openCompleteModal(<?=$e['id']?>)" class="btn btn-success btn-sm" title="Registra esito">
            <i class="fa-solid fa-check"></i>
          </button>
          <?php if($can_edit_this): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Annullare questa pianificazione?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="exam_id" value="<?=$e['id']?>">
            <button type="submit" class="btn btn-sm" title="Annulla"><i class="fa-solid fa-xmark"></i></button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
          <?php if($e['result']==='passed'): ?>
          <a href="upload_certificato.php" class="btn btn-primary btn-sm" title="Carica certificato">
            <i class="fa-solid fa-upload"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal esito -->
<div id="mComplete" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:360px">
    <h3 style="margin:0 0 18px;font-size:16px">Registra esito esame</h3>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="complete">
      <input type="hidden" name="exam_id" id="complete_eid">
      <div class="form-group">
        <label>Risultato</label>
        <select name="result">
          <option value="passed">✓ Superato</option>
          <option value="failed">✗ Non superato</option>
        </select>
      </div>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:11px;margin-bottom:18px;font-size:12px;color:#065f46">
        Se superato riceverai un promemoria per caricare il PDF del certificato.
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Conferma</button>
        <button type="button" onclick="document.getElementById('mComplete').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal modifica -->
<div id="mEdit" class="modal-overlay" style="z-index:1001">
  <div class="modal-box" style="width:600px;max-height:90vh;overflow-y:auto">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 id="mEditTitle" style="margin:0;font-size:16px"><i class="fa-solid fa-pen" style="color:var(--p)"></i> Modifica evento</h3>
      <button onclick="document.getElementById('mEdit').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
    </div>
    <form method="POST">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="exam_id" id="edit_id">

      <div class="form-group">
        <label>Tipo percorso *</label>
        <select name="plan_type" id="edit_plan_type" required>
          <?php foreach($PLAN_TYPES as $k=>[$lbl,$ico,$col,$bg,$req]): ?>
          <option value="<?=$k?>"><?=$lbl?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if($is_manager): ?>
      <div class="form-group">
        <label>Collaboratore</label>
        <select name="target_employee_id" id="edit_emp">
          <?php foreach($users as $u): ?>
          <option value="<?=$u['id']?>"><?=h($u['last_name'].' '.$u['first_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Certificazione</label>
        <select name="certification_id" id="edit_cert">
          <option value="">— Nessuna (workshop/convegno) —</option>
          <?php foreach($certs as $c): ?>
          <option value="<?=$c['id']?>">[<?=h($c['brand_name'])?>] <?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Data prevista *</label>
        <input type="date" name="planned_date" id="edit_date" required>
      </div>

      <div class="form-group">
        <label>Note / obiettivo</label>
        <textarea name="notes" id="edit_notes" rows="2"></textarea>
      </div>

      <div id="edit_info" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px;margin-bottom:14px;font-size:11px;color:#1e40af;display:none">
        <i class="fa-solid fa-circle-info"></i> <span id="edit_info_text"></span>
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px"><i class="fa-solid fa-floppy-disk"></i> Salva modifiche</button>
        <button type="button" onclick="document.getElementById('mEdit').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCompleteModal(id) {
  document.getElementById('complete_eid').value = id;
  document.getElementById('mComplete').style.display = 'flex';
}

function openEditModal(data) {
  document.getElementById('edit_id').value = data.id;
  document.getElementById('edit_plan_type').value = data.plan_type || 'esame_certificazione';
  document.getElementById('edit_date').value = data.planned_date || '';
  document.getElementById('edit_notes').value = data.notes || '';

  // Certificazione
  var certSel = document.getElementById('edit_cert');
  if (certSel) {
    certSel.value = data.certification_id || '';
  }

  // Collaboratore (solo manager)
  var empSel = document.getElementById('edit_emp');
  if (empSel) {
    empSel.value = data.employee_id || '';
  }

  // Info contestuale
  var info = document.getElementById('edit_info');
  var infoText = document.getElementById('edit_info_text');
  var certName = data.cert_name || '(nessuna certificazione)';
  var empName = (data.last_name || '') + ' ' + (data.first_name || '');
  infoText.textContent = 'Evento originale: ' + certName + ' — ' + empName.trim() + ' — ' + (data.planned_date || '');
  info.style.display = 'block';

  // Titolo
  document.getElementById('mEditTitle').innerHTML = '<i class="fa-solid fa-pen" style="color:var(--p)"></i> Modifica: ' + certName;

  document.getElementById('mEdit').style.display = 'flex';
}
</script>

<!-- Smart Search Certificazioni -->
<script>
(function(){
  var input = document.getElementById('prog_cert_search');
  var results = document.getElementById('prog_cert_results');
  var hiddenId = document.getElementById('prog_cert_id');
  var selectedBox = document.getElementById('prog_cert_selected');
  var labelEl = document.getElementById('prog_cert_label');
  var ttlEl = document.getElementById('prog_cert_ttl');
  var brandSel = document.getElementById('prog_brand');
  var timer = null;

  if (!input) return;

  input.addEventListener('input', function(){
    clearTimeout(timer);
    var q = this.value.trim();
    if (q.length < 2 && !brandSel.value) { results.style.display='none'; return; }
    timer = setTimeout(function(){
      var url = 'api_cert_search.php?limit=15&q=' + encodeURIComponent(q);
      if (brandSel && brandSel.value) url += '&brand_id=' + brandSel.value;
      fetch(url).then(function(r){return r.json()}).then(function(data){
        if (!data.length) {
          results.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--muted);text-align:center">Nessun risultato. <a href="catalogo_certificazioni.php" style="color:var(--p)">Crea nuova certificazione →</a></div>';
          results.style.display = 'block';
          return;
        }
        var html = '';
        data.forEach(function(item){
          html += '<div class="cert-result" data-id="'+item.id+'" data-label="'+escA(item.label)+'" data-ttl="'+escA(item.ttl)+'" '
               +  'style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:12px;transition:.1s"'
               +  ' onmouseover="this.style.background=\'#eff6ff\'" onmouseout="this.style.background=\'#fff\'">'
               +  '<div style="font-weight:600">'+escH(item.label)+'</div>'
               +  '<div style="font-size:10px;color:var(--muted)">'+escH(item.category||'')+' · '+escH(item.ttl)+'</div>'
               +  '</div>';
        });
        results.innerHTML = html;
        results.style.display = 'block';
        // Click handler
        results.querySelectorAll('.cert-result').forEach(function(el){
          el.addEventListener('click', function(){
            selectCert(this.dataset.id, this.dataset.label, this.dataset.ttl);
          });
        });
      }).catch(function(){});
    }, 250);
  });

  input.addEventListener('focus', function(){
    if (this.value.length >= 2 || (brandSel && brandSel.value)) {
      this.dispatchEvent(new Event('input'));
    }
  });

  // Brand change: resetta e rilancia ricerca
  if (brandSel) {
    brandSel.addEventListener('change', function(){
      if (input.value.length >= 1 || this.value) {
        input.dispatchEvent(new Event('input'));
      }
    });
  }

  // Click fuori chiude
  document.addEventListener('click', function(e){
    if (!input.contains(e.target) && !results.contains(e.target)) results.style.display='none';
  });

  window.selectCert = function(id, label, ttl) {
    hiddenId.value = id;
    input.value = '';
    input.style.display = 'none';
    results.style.display = 'none';
    labelEl.textContent = label;
    ttlEl.textContent = '⏱ ' + ttl;
    selectedBox.style.display = 'block';
  };

  window.clearCertSearch = function() {
    hiddenId.value = '';
    input.value = '';
    input.style.display = 'block';
    selectedBox.style.display = 'none';
    input.focus();
  };

  function escH(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''; }
  function escA(s) { return s ? String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;') : ''; }
})();

function toggleCertRequired(type) {
  var certReqTypes = <?=json_encode(array_keys(array_filter($PLAN_TYPES, fn($v) => $v[4])))?>;
  var certBlock = document.getElementById('prog_cert_search');
  var certHidden = document.getElementById('prog_cert_id');
  var brandBlock = document.getElementById('prog_brand');
  var isReq = certReqTypes.indexOf(type) !== -1;

  // Certificazione opzionale per workshop/convegni
  if (certHidden) certHidden.required = isReq;
  if (certBlock) certBlock.placeholder = isReq
    ? 'Digita nome o codice (obbligatorio)'
    : 'Opzionale — cerca certificazione collegata...';

  // Visual feedback
  var certLabel = certBlock ? certBlock.closest('.form-group') : null;
  if (certLabel) {
    certLabel.style.opacity = isReq ? '1' : '0.65';
  }
}
</script>
<?php require_once('footer.php'); ?>
