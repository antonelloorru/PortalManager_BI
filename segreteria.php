<?php
/**
 * certV 2.4 — segreteria.php
 * Modulo Segreteria e Logistica
 * Workflow: Bozza → Inviata → Approvata → Prenotata → Completata
 * Collegamento a: esami pianificati, certificazioni, brand
 */
require_once('access_control.php');
require_once('functions.php');
require_once('SmtpMailer.php');

$u_id    = (int)$_SESSION['user_id'];
$u_role  = (int)($_SESSION['role_id'] ?? 99);
$emp_id  = (int)($_SESSION['employee_id'] ?? 0);
$can_manage = can('edit');
$can_create = can('create');

// Auto-migrate
try { $pdo->query("SELECT `id` FROM `logistics_requests` LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    $mf = __DIR__ . '/migration_integrations.sql';
    if (file_exists($mf)) {
        foreach (explode(";", file_get_contents($mf)) as $s) {
            $s = trim($s); if (!$s || strpos($s,'--')===0 || preg_match('/^(SELECT|SHOW)/i',$s)) continue;
            try { $pdo->exec($s); } catch (\Exception $ex) {}
        }
    }
}

/**
 * Invia notifica email per richieste logistiche.
 */
function notify_logistics(PDO $pdo, int $reqId, string $event, ?string $newStatus = null): void
{
    try {
        $q = $pdo->prepare(
            "SELECT lr.*, e.first_name emp_fn, e.last_name emp_ln,
                    u_req.email req_email, u_req.notifications_email req_notif,
                    c.name cert_name, b.name brand_name
             FROM logistics_requests lr
             JOIN employees e ON lr.employee_id = e.id
             LEFT JOIN users u_req ON lr.requested_by = u_req.id
             LEFT JOIN certifications c ON lr.certification_id = c.id
             LEFT JOIN brands b ON lr.brand_id = b.id
             WHERE lr.id=?"
        );
        $q->execute([$reqId]); $r = $q->fetch(); $q->closeCursor();
        if (!$r) return;

        $settings = load_settings();
        $app = $settings['app_name'] ?? 'certV';
        $type_labels = ['alloggio'=>'Alloggio','mezzo'=>'Mezzo di trasporto','attrezzatura'=>'Attrezzatura','aula'=>'Aula/Sala','catering'=>'Catering','altro'=>'Altro'];
        $st_labels = ['submitted'=>'inviata','approved'=>'approvata','booked'=>'prenotata','completed'=>'completata','cancelled'=>'annullata'];
        $tipo = $type_labels[$r['request_type']] ?? $r['request_type'];

        // HTML riepilogo
        $html = '<div style="font-family:Arial,sans-serif;max-width:580px;margin:0 auto">';
        $html .= '<div style="background:#0369a1;color:#fff;padding:16px 20px;border-radius:10px 10px 0 0"><h2 style="margin:0;font-size:16px">📋 ' . htmlspecialchars($r['title']) . '</h2></div>';
        $html .= '<div style="background:#fff;padding:20px;border:1px solid #e2e8f0">';
        $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        $rows = [
            ['Tipo richiesta', $tipo],
            ['Dipendente', htmlspecialchars($r['emp_fn'] . ' ' . $r['emp_ln'])],
            ['Data', date('d/m/Y', strtotime($r['date_from'])) . ($r['date_to'] ? ' → ' . date('d/m/Y', strtotime($r['date_to'])) : '')],
            ['Luogo', htmlspecialchars($r['city'] ?? ($r['location'] ?? '—'))],
        ];
        if ($r['cert_name']) $rows[] = ['Certificazione', htmlspecialchars($r['cert_name'])];
        if ($r['brand_name']) $rows[] = ['Brand', htmlspecialchars($r['brand_name'])];
        if ($r['budget_estimated']) $rows[] = ['Budget stimato', '€' . number_format($r['budget_estimated'], 2, ',', '.')];
        if ($newStatus) $rows[] = ['Nuovo stato', '<strong style="color:#059669">' . strtoupper($st_labels[$newStatus] ?? $newStatus) . '</strong>'];
        foreach ($rows as [$l, $v]) {
            $html .= "<tr><td style='padding:6px 10px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0;width:130px'>$l</td><td style='padding:6px 10px;border:1px solid #e2e8f0'>$v</td></tr>";
        }
        $html .= '</table>';
        if ($r['description']) $html .= '<div style="margin-top:12px;padding:10px;background:#f0f9ff;border-radius:6px;font-size:12px;color:#475569">' . nl2br(htmlspecialchars($r['description'])) . '</div>';
        $html .= '</div><div style="background:#f8fafc;padding:10px 20px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 10px 10px;font-size:10px;color:#94a3b8;text-align:center">Email automatica da ' . htmlspecialchars($app) . '</div></div>';

        $text = "Richiesta: {$r['title']}\nTipo: $tipo\nDipendente: {$r['emp_fn']} {$r['emp_ln']}\nData: " . date('d/m/Y', strtotime($r['date_from'])) . "\n";
        if ($r['city']) $text .= "Città: {$r['city']}\n";
        if ($newStatus) $text .= "Stato: " . strtoupper($st_labels[$newStatus] ?? $newStatus) . "\n";

        if ($event === 'new_request') {
            // Destinatario configurabile da config_notifiche.php
            $logistics_email = trim($settings['notify_logistics_email'] ?? '');
            $logistics_cc    = trim($settings['notify_logistics_cc'] ?? '');

            if ($logistics_email) {
                $cc = $logistics_cc ? [$logistics_cc] : [];
                send_certv_email($logistics_email, "[$app] Nuova richiesta logistica: {$r['title']}", $text, $html, $cc, 'segreteria', $reqId);
            } else {
                // Fallback: notifica a tutti i manager (ruoli 1-3)
                $mgrs = $pdo->query("SELECT u.email FROM users u WHERE u.role_id <= 3 AND u.status='active' AND u.email IS NOT NULL");
                foreach ($mgrs->fetchAll() as $m) {
                    send_certv_email($m['email'], "[$app] Nuova richiesta logistica: {$r['title']}", $text, $html, [], 'segreteria', $reqId);
                }
            }
        }

        if ($event === 'status_change' && $newStatus) {
            $to = $r['req_notif'] ?: $r['req_email'];
            $logistics_cc = trim($settings['notify_logistics_cc'] ?? '');
            if ($to) {
                $cc = $logistics_cc ? [$logistics_cc] : [];
                send_certv_email($to, "[$app] Richiesta \"{$r['title']}\" {$st_labels[$newStatus]}", $text, $html, $cc, 'segreteria', $reqId);
            }
        }
    } catch (\Exception $e) {
        write_log('Segreteria', 'error', "Errore notifica richiesta #$reqId: " . $e->getMessage());
    }
}

// ── CRUD (PRIMA di header.php per PRG) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_create) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['req_id'] ?? 0);
            $data = [
                (int)$_POST['employee_id'],
                !empty($_POST['planned_exam_id']) ? (int)$_POST['planned_exam_id'] : null,
                !empty($_POST['certification_id']) ? (int)$_POST['certification_id'] : null,
                !empty($_POST['brand_id']) ? (int)$_POST['brand_id'] : null,
                $_POST['request_type'] ?? 'alloggio',
                trim($_POST['title'] ?? ''),
                trim($_POST['description'] ?? '') ?: null,
                $_POST['date_from'] ?? date('Y-m-d'),
                !empty($_POST['date_to']) ? $_POST['date_to'] : null,
                !empty($_POST['time_from']) ? $_POST['time_from'] : null,
                !empty($_POST['time_to']) ? $_POST['time_to'] : null,
                trim($_POST['location'] ?? '') ?: null,
                trim($_POST['city'] ?? '') ?: null,
                max(1, (int)($_POST['num_people'] ?? 1)),
                !empty($_POST['budget_estimated']) ? (float)$_POST['budget_estimated'] : null,
                trim($_POST['supplier'] ?? '') ?: null,
                trim($_POST['booking_ref'] ?? '') ?: null,
            ];
            if (!$data[5]) throw new Exception("Titolo richiesta obbligatorio.");

            if ($id > 0) {
                $pdo->prepare("UPDATE logistics_requests SET employee_id=?,planned_exam_id=?,certification_id=?,brand_id=?,request_type=?,title=?,description=?,date_from=?,date_to=?,time_from=?,time_to=?,location=?,city=?,num_people=?,budget_estimated=?,supplier=?,booking_ref=? WHERE id=?")
                    ->execute([...$data, $id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Richiesta aggiornata.</div>";
            } else {
                $pdo->prepare("INSERT INTO logistics_requests (employee_id,planned_exam_id,certification_id,brand_id,request_type,title,description,date_from,date_to,time_from,time_to,location,city,num_people,budget_estimated,supplier,booking_ref,requested_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'submitted')")
                    ->execute([...$data, $u_id]);
                $newReqId = (int)$pdo->lastInsertId();
                notify_logistics($pdo, $newReqId, 'new_request');
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Richiesta inviata. Notifica email ai responsabili.</div>";
            }
            redirect('segreteria');
        }

        if ($action === 'change_status' && $can_manage) {
            $rid = (int)$_POST['req_id'];
            $new_status = $_POST['new_status'];
            $allowed = ['approved','booked','completed','cancelled'];
            if (in_array($new_status, $allowed)) {
                $set = "status=?";
                $params = [$new_status, $rid];
                if ($new_status === 'approved') { $set .= ",approved_by=?,approved_at=NOW()"; $params = [$new_status, $u_id, $rid]; }
                if ($new_status === 'booked') {
                    $set .= ",supplier=?,booking_ref=?,budget_actual=?";
                    $params = [$new_status, trim($_POST['supplier']??''), trim($_POST['booking_ref']??''), !empty($_POST['budget_actual'])?(float)$_POST['budget_actual']:null, $rid];
                }
                $pdo->prepare("UPDATE logistics_requests SET $set WHERE id=?")->execute($params);

                // Push + Email
                $req_info = $pdo->prepare("SELECT lr.title, lr.requested_by FROM logistics_requests lr WHERE lr.id=?");
                $req_info->execute([$rid]); $ri = $req_info->fetch(); $req_info->closeCursor();
                if ($ri && $ri['requested_by']) {
                    $st_labels = ['approved'=>'approvata','booked'=>'prenotata','completed'=>'completata','cancelled'=>'annullata'];
                    push_notification("Richiesta logistica {$st_labels[$new_status]}", "La richiesta \"{$ri['title']}\" è stata {$st_labels[$new_status]}.", 'system', $new_status==='cancelled'?'warning':'success', $ri['requested_by'], null, 'segreteria.php');
                }
                notify_logistics($pdo, $rid, 'status_change', $new_status);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Stato aggiornato. Notifica inviata.</div>";
            }
            redirect('segreteria');
        }

        if ($action === 'add_note' && $can_manage) {
            $rid = (int)$_POST['req_id'];
            $note = trim($_POST['notes_internal'] ?? '');
            $pdo->prepare("UPDATE logistics_requests SET notes_internal=CONCAT(IFNULL(notes_internal,''), ?) WHERE id=?")
                ->execute(["\n[" . date('d/m H:i') . " — " . ($_SESSION['user_name'] ?? '') . "] $note", $rid]);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Nota aggiunta.</div>";
            redirect('segreteria');
        }
    } catch (Exception $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        redirect('segreteria');
    }
}

// ── Output HTML ─────────────────────────────────────────────────────────────
require_once('header.php');
$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

// ── Dati ──────────────────────────────────────────────────────────────────────
$f_status = $_GET['f_st'] ?? '';
$f_type   = $_GET['f_type'] ?? '';

$where = "WHERE 1=1"; $prm = [];
if ($f_status) { $where .= " AND lr.status=?"; $prm[] = $f_status; }
if ($f_type)   { $where .= " AND lr.request_type=?"; $prm[] = $f_type; }
// Dipendente vede solo le sue
if ($u_role >= 5 && $emp_id) { $where .= " AND lr.employee_id=?"; $prm[] = $emp_id; }

$stm = $pdo->prepare(
    "SELECT lr.*,
            e.first_name emp_fn, e.last_name emp_ln,
            pe.planned_date exam_date, pe.status exam_status,
            c.name cert_name, b.name brand_name,
            u.display_name req_by_name
     FROM logistics_requests lr
     JOIN employees e ON lr.employee_id=e.id
     LEFT JOIN planned_exams pe ON lr.planned_exam_id=pe.id
     LEFT JOIN certifications c ON lr.certification_id=c.id
     LEFT JOIN brands b ON lr.brand_id=b.id
     LEFT JOIN users u ON lr.requested_by=u.id
     $where
     ORDER BY FIELD(lr.status,'submitted','approved','booked','draft','completed','cancelled'), lr.date_from ASC"
);
$stm->execute($prm);
$requests = $stm->fetchAll();

$all_employees = $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status='active' ORDER BY last_name")->fetchAll();
$all_exams = $pdo->query("SELECT pe.id, pe.planned_date, e.first_name, e.last_name, c.name cert_name FROM planned_exams pe JOIN employees e ON pe.employee_id=e.id JOIN certifications c ON pe.certification_id=c.id WHERE pe.status='planned' ORDER BY pe.planned_date")->fetchAll();
$all_certs = $pdo->query("SELECT c.id, c.name, b.name brand_name FROM certifications c JOIN brands b ON c.brand_id=b.id ORDER BY b.name, c.name")->fetchAll();
$all_brands = $pdo->query("SELECT id, name FROM brands ORDER BY priority, name")->fetchAll();

// KPI
$k_sub = count(array_filter($requests, fn($r) => $r['status']==='submitted'));
$k_app = count(array_filter($requests, fn($r) => $r['status']==='approved'));
$k_bok = count(array_filter($requests, fn($r) => $r['status']==='booked'));
$k_don = count(array_filter($requests, fn($r) => $r['status']==='completed'));
$k_tot_budget = array_sum(array_map(fn($r) => (float)($r['budget_actual'] ?? $r['budget_estimated'] ?? 0), $requests));

$STATUS = [
    'draft'     => ['Bozza',      '#94a3b8', '#f8fafc', 'fa-file-pen'],
    'submitted' => ['Inviata',    '#f59e0b', '#fffbeb', 'fa-paper-plane'],
    'approved'  => ['Approvata',  '#0ea5e9', '#e0f2fe', 'fa-circle-check'],
    'booked'    => ['Prenotata',  '#8b5cf6', '#f3e8ff', 'fa-calendar-check'],
    'completed' => ['Completata', '#059669', '#ecfdf5', 'fa-flag-checkered'],
    'cancelled' => ['Annullata',  '#dc2626', '#fef2f2', 'fa-ban'],
];
$TYPES = [
    'alloggio'    => ['Alloggio',     '#f59e0b', 'fa-bed'],
    'mezzo'       => ['Mezzo',        '#3b82f6', 'fa-car'],
    'attrezzatura'=> ['Attrezzatura', '#8b5cf6', 'fa-toolbox'],
    'aula'        => ['Aula',         '#059669', 'fa-chalkboard-teacher'],
    'catering'    => ['Catering',     '#ec4899', 'fa-utensils'],
    'altro'       => ['Altro',        '#64748b', 'fa-ellipsis'],
];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-concierge-bell" style="color:var(--p);margin-right:10px"></i>Segreteria & Logistica
    </h1>
    <p style="color:var(--muted);font-size:13px">Prenotazione alloggi, mezzi, attrezzature — collegato all'iter di certificazione</p>
  </div>
  <?php if($can_create): ?>
  <button onclick="openReq()" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Nuova richiesta</button>
  <?php endif; ?>
</div>
<?=$msg?>

<!-- KPI -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:22px">
  <div class="stat-card" style="border-color:#f59e0b"><div class="sl">Da gestire</div><div class="sv" style="color:#f59e0b"><?=$k_sub?></div></div>
  <div class="stat-card" style="border-color:#0ea5e9"><div class="sl">Approvate</div><div class="sv" style="color:#0ea5e9"><?=$k_app?></div></div>
  <div class="stat-card" style="border-color:#8b5cf6"><div class="sl">Prenotate</div><div class="sv" style="color:#8b5cf6"><?=$k_bok?></div></div>
  <div class="stat-card" style="border-color:#059669"><div class="sl">Completate</div><div class="sv" style="color:#059669"><?=$k_don?></div></div>
  <div class="stat-card" style="border-color:var(--p)"><div class="sl">Budget totale</div><div class="sv" style="color:var(--p);font-size:16px">&euro;<?=number_format($k_tot_budget,0,',','.')?></div></div>
</div>

<!-- Filtri -->
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Stato</label>
    <select name="f_st"><option value="">Tutti</option>
    <?php foreach($STATUS as $k=>$v): ?><option value="<?=$k?>" <?=$f_status===$k?'selected':''?>><?=$v[0]?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="fg"><label>Tipo</label>
    <select name="f_type"><option value="">Tutti</option>
    <?php foreach($TYPES as $k=>$v): ?><option value="<?=$k?>" <?=$f_type===$k?'selected':''?>><?=$v[0]?></option><?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary" style="margin-top:20px">Filtra</button>
  <?php if($f_status||$f_type): ?><a href="segreteria.php" class="btn btn-sm" style="margin-top:20px">Reset</a><?php endif; ?>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<!-- Pipeline visiva -->
<div style="display:flex;gap:6px;margin-bottom:22px;overflow-x:auto;padding-bottom:8px">
  <?php foreach($STATUS as $sk=>$sv):
    $cnt = count(array_filter($requests, fn($r) => $r['status']===$sk));
    if ($cnt === 0 && in_array($sk, ['draft','cancelled'])) continue;
  ?>
  <div style="flex:1;min-width:100px;padding:12px;border-radius:10px;background:<?=$sv[2]?>;border:1.5px solid <?=$sv[1]?>20;text-align:center">
    <div style="font-size:22px;font-weight:800;color:<?=$sv[1]?>"><?=$cnt?></div>
    <div style="font-size:10px;font-weight:700;color:<?=$sv[1]?>;text-transform:uppercase;letter-spacing:.3px;margin-top:2px"><i class="fa-solid <?=$sv[3]?>" style="margin-right:3px"></i> <?=$sv[0]?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Lista richieste -->
<?php foreach($requests as $r):
  $st = $STATUS[$r['status']] ?? $STATUS['draft'];
  $tp = $TYPES[$r['request_type']] ?? $TYPES['altro'];
?>
<div class="card" style="margin-bottom:14px;border-left:4px solid <?=$st[1]?>">
  <div style="padding:16px 20px;display:flex;gap:16px;align-items:flex-start">
    <!-- Icona tipo -->
    <div style="width:42px;height:42px;border-radius:10px;background:<?=$tp[1]?>15;color:<?=$tp[1]?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:18px">
      <i class="fa-solid <?=$tp[2]?>"></i>
    </div>

    <!-- Contenuto -->
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap">
        <strong style="font-size:14px"><?=h($r['title'])?></strong>
        <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:5px;background:<?=$st[2]?>;color:<?=$st[1]?>;font-size:10px;font-weight:800;text-transform:uppercase">
          <i class="fa-solid <?=$st[3]?>"></i> <?=$st[0]?>
        </span>
        <span style="padding:2px 8px;border-radius:5px;background:<?=$tp[1]?>15;color:<?=$tp[1]?>;font-size:10px;font-weight:700"><?=$tp[0]?></span>
      </div>

      <div style="font-size:12px;color:var(--muted);display:flex;gap:16px;flex-wrap:wrap;margin-bottom:6px">
        <span><i class="fa-solid fa-user" style="width:14px"></i> <?=h($r['emp_fn'].' '.$r['emp_ln'])?></span>
        <span><i class="fa-solid fa-calendar" style="width:14px"></i> <?=date('d/m/Y', strtotime($r['date_from']))?><?=$r['date_to']?' → '.date('d/m/Y', strtotime($r['date_to'])):''?></span>
        <?php if($r['location']): ?><span><i class="fa-solid fa-location-dot" style="width:14px"></i> <?=h($r['location'])?><?=$r['city']?', '.h($r['city']):''?></span><?php endif; ?>
        <?php if($r['num_people'] > 1): ?><span><i class="fa-solid fa-users" style="width:14px"></i> <?=$r['num_people']?> persone</span><?php endif; ?>
      </div>

      <!-- Collegamento certificazione/esame -->
      <?php if($r['cert_name'] || $r['exam_date']): ?>
      <div style="display:flex;gap:10px;margin-bottom:6px;flex-wrap:wrap">
        <?php if($r['cert_name']): ?><span style="font-size:11px;padding:2px 8px;border-radius:5px;background:#eff6ff;color:#1e40af"><i class="fa-solid fa-certificate" style="margin-right:3px"></i> <?=h($r['cert_name'])?></span><?php endif; ?>
        <?php if($r['brand_name']): ?><span style="font-size:11px;padding:2px 8px;border-radius:5px;background:#f0fdf4;color:#166534"><i class="fa-solid fa-tag" style="margin-right:3px"></i> <?=h($r['brand_name'])?></span><?php endif; ?>
        <?php if($r['exam_date']): ?><span style="font-size:11px;padding:2px 8px;border-radius:5px;background:#fef3c7;color:#92400e"><i class="fa-solid fa-file-signature" style="margin-right:3px"></i> Esame: <?=date('d/m/Y', strtotime($r['exam_date']))?></span><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if($r['supplier'] || $r['booking_ref']): ?>
      <div style="font-size:11px;color:#475569">
        <?php if($r['supplier']): ?><span><i class="fa-solid fa-building" style="margin-right:3px"></i> <?=h($r['supplier'])?></span><?php endif; ?>
        <?php if($r['booking_ref']): ?><span style="margin-left:12px"><i class="fa-solid fa-hashtag" style="margin-right:3px"></i> <?=h($r['booking_ref'])?></span><?php endif; ?>
        <?php if($r['budget_actual'] ?? $r['budget_estimated']): ?><span style="margin-left:12px"><i class="fa-solid fa-euro-sign" style="margin-right:3px"></i> <?=number_format((float)($r['budget_actual'] ?? $r['budget_estimated']),2,',','.')?></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Azioni -->
    <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0">
      <?php if($can_manage && $r['status']==='submitted'): ?>
      <form method="POST" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="req_id" value="<?=$r['id']?>"><input type="hidden" name="new_status" value="approved">
        <button type="submit" class="btn btn-sm" style="background:#e0f2fe;color:#0369a1;border-color:#bae6fd" title="Approva"><i class="fa-solid fa-check"></i></button></form>
      <form method="POST" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="req_id" value="<?=$r['id']?>"><input type="hidden" name="new_status" value="cancelled">
        <button type="submit" class="btn btn-danger btn-sm" title="Annulla" onclick="return confirm('Annullare?')"><i class="fa-solid fa-ban"></i></button></form>
      <?php endif; ?>
      <?php if($can_manage && $r['status']==='approved'): ?>
      <button onclick='openBook(<?=htmlspecialchars(json_encode($r),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm" style="background:#f3e8ff;color:#7c3aed;border-color:#d8b4fe" title="Prenota"><i class="fa-solid fa-calendar-check"></i></button>
      <?php endif; ?>
      <?php if($can_manage && $r['status']==='booked'): ?>
      <form method="POST" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="req_id" value="<?=$r['id']?>"><input type="hidden" name="new_status" value="completed">
        <button type="submit" class="btn btn-sm" style="background:#ecfdf5;color:#059669;border-color:#a7f3d0" title="Completa"><i class="fa-solid fa-flag-checkered"></i></button></form>
      <?php endif; ?>
      <?php if($can_create && in_array($r['status'],['draft','submitted'])): ?>
      <button onclick='openReq(<?=htmlspecialchars(json_encode($r),ENT_QUOTES,"UTF-8")?>)' class="btn btn-sm" title="Modifica"><i class="fa-solid fa-pen"></i></button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if(empty($requests)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:14px;border:1px dashed var(--border);color:var(--muted)">
  <i class="fa-solid fa-concierge-bell" style="font-size:40px;margin-bottom:16px;display:block;opacity:.3"></i>
  Nessuna richiesta logistica trovata.
</div>
<?php endif; ?>

<!-- ═══ MODAL: NUOVA RICHIESTA ═══ -->
<div id="mReq" class="modal-overlay">
<div class="modal-box" style="width:700px">
  <div style="display:flex;justify-content:space-between;margin-bottom:18px">
    <h3 id="mRT" style="margin:0;font-size:16px">Nuova richiesta logistica</h3>
    <button onclick="document.getElementById('mReq').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
  </div>
  <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="req_id" id="rq_id" value="0">

    <div class="grid-2">
      <div class="form-group"><label>Collaboratore *</label>
        <select name="employee_id" id="rq_emp" required
                data-cascade="rq_exam" data-entity="exams" data-param="employee_id">
          <option value="">— Seleziona —</option>
          <?php foreach($all_employees as $e): ?><option value="<?=$e['id']?>" <?=$e['id']===$emp_id?'selected':''?>><?=h($e['last_name'].' '.$e['first_name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Tipo richiesta *</label>
        <select name="request_type" id="rq_type">
          <?php foreach($TYPES as $k=>$v): ?><option value="<?=$k?>"><?=$v[0]?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group"><label>Titolo *</label><input type="text" name="title" id="rq_title" required placeholder="Es. Hotel per esame AZ-900 a Milano"></div>

    <div style="background:#eff6ff;padding:12px;border-radius:10px;border:1px solid #bfdbfe;margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:#1e40af;text-transform:uppercase;margin-bottom:8px"><i class="fa-solid fa-link"></i> Collegamento certificazione</div>
      <div class="grid-3">
        <div class="form-group" style="margin:0"><label>Esame pianificato</label>
          <select name="planned_exam_id" id="rq_exam"><option value="">— Seleziona prima il collaboratore —</option>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Certificazione</label>
          <select name="certification_id" id="rq_cert"
                  data-reverse="rq_brand" data-reverse-entity="brand_for_cert" data-reverse-param="certification_id">
            <option value="">— Nessuna —</option>
          <?php foreach($all_certs as $c): ?><option value="<?=$c['id']?>"><?=h($c['brand_name'])?> — <?=h($c['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0"><label>Brand</label>
          <select name="brand_id" id="rq_brand"
                  data-cascade="rq_cert" data-entity="certifications" data-param="brand_id">
            <option value="">— Tutti —</option>
          <?php foreach($all_brands as $b): ?><option value="<?=$b['id']?>"><?=h($b['name'])?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="grid-2">
      <div class="form-group"><label>Data inizio *</label><input type="date" name="date_from" id="rq_df" required></div>
      <div class="form-group"><label>Data fine</label><input type="date" name="date_to" id="rq_dt"></div>
    </div>
    <div class="grid-2">
      <div class="form-group"><label>Ora inizio</label><input type="time" name="time_from" id="rq_tf"></div>
      <div class="form-group"><label>Ora fine</label><input type="time" name="time_to" id="rq_tt"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label>Luogo</label><input type="text" name="location" id="rq_loc"></div>
      <div class="form-group"><label>Città</label><input type="text" name="city" id="rq_city"></div>
      <div class="form-group"><label>N. persone</label><input type="number" name="num_people" id="rq_np" min="1" value="1"></div>
    </div>
    <div class="grid-3">
      <div class="form-group"><label>Budget stimato</label><input type="number" name="budget_estimated" id="rq_be" step="0.01" min="0"></div>
      <div class="form-group"><label>Fornitore</label><input type="text" name="supplier" id="rq_sup"></div>
      <div class="form-group"><label>Rif. prenotazione</label><input type="text" name="booking_ref" id="rq_br"></div>
    </div>
    <div class="form-group"><label>Descrizione</label><textarea name="description" id="rq_desc" rows="2"></textarea></div>

    <div style="display:flex;gap:10px"><button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Invia richiesta</button><button type="button" onclick="document.getElementById('mReq').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button></div>
  </form>
</div></div>

<!-- ═══ MODAL: PRENOTA ═══ -->
<div id="mBook" class="modal-overlay">
<div class="modal-box" style="width:440px">
  <h3 style="margin:0 0 18px;font-size:16px"><i class="fa-solid fa-calendar-check" style="color:#8b5cf6;margin-right:8px"></i>Conferma prenotazione</h3>
  <form method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="change_status"><input type="hidden" name="new_status" value="booked"><input type="hidden" name="req_id" id="bk_id" value="0">
    <div class="form-group"><label>Fornitore</label><input type="text" name="supplier" id="bk_sup" required></div>
    <div class="grid-2">
      <div class="form-group"><label>Codice prenotazione</label><input type="text" name="booking_ref" id="bk_ref"></div>
      <div class="form-group"><label>Costo effettivo</label><input type="number" name="budget_actual" id="bk_cost" step="0.01" min="0"></div>
    </div>
    <div style="display:flex;gap:10px"><button type="submit" class="btn" style="flex:1;justify-content:center;background:#8b5cf6;color:#fff;border:none">Conferma prenotazione</button><button type="button" onclick="document.getElementById('mBook').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button></div>
  </form>
</div></div>

<script>
const FM_SIMPLE = {rq_type:'request_type',rq_title:'title',rq_desc:'description',rq_df:'date_from',rq_dt:'date_to',rq_tf:'time_from',rq_tt:'time_to',rq_loc:'location',rq_city:'city',rq_np:'num_people',rq_be:'budget_estimated',rq_sup:'supplier',rq_br:'booking_ref'};
function openReq(d){
  document.querySelector('#mReq form').reset();
  document.getElementById('rq_id').value=0;
  document.getElementById('mRT').textContent=d?'Modifica richiesta':'Nuova richiesta logistica';
  if(d){
    document.getElementById('rq_id').value=d.id;
    // Campi semplici
    Object.entries(FM_SIMPLE).forEach(([e,k])=>{var el=document.getElementById(e);if(el&&d[k]!=null)el.value=d[k];});
    // Dipendente → carica esami filtrati, poi imposta esame
    if(d.employee_id){
      document.getElementById('rq_emp').value=d.employee_id;
      ScopeFilter.fetch('exams',{employee_id:d.employee_id},function(data){
        ScopeFilter.populate(document.getElementById('rq_exam'),data,d.planned_exam_id);
      });
    }
    // Brand → carica certificazioni filtrate, poi imposta certificazione
    if(d.brand_id){
      document.getElementById('rq_brand').value=d.brand_id;
      ScopeFilter.fetch('certifications',{brand_id:d.brand_id},function(data){
        ScopeFilter.populate(document.getElementById('rq_cert'),data,d.certification_id);
      });
    } else if(d.certification_id){
      document.getElementById('rq_cert').value=d.certification_id;
    }
  }
  document.getElementById('mReq').style.display='flex';
}
function openBook(d){
  document.getElementById('bk_id').value=d.id;
  document.getElementById('bk_sup').value=d.supplier||'';
  document.getElementById('bk_ref').value=d.booking_ref||'';
  document.getElementById('bk_cost').value=d.budget_estimated||'';
  document.getElementById('mBook').style.display='flex';
}
</script>
<?php require_once('footer.php'); ?>
