<?php
/**
 * project_dashboard.php — Scheda commessa a tab (v1.7.59)
 * Tab: Anagrafica | Effort Presales | Team | Redditività | Consuntivo (Rapporti)
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/ProjectModel.php');
require_once(__DIR__ . '/app/Gantt.php');
require_once(__DIR__ . '/app/RecycleBin.php');
require_once(__DIR__ . '/app/ProjectWorkflow.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/DgbModel.php');

if (!can('view', 'project_dashboard.php')) { redirect('manage_projects'); }
$can_edit = can('edit', 'project_dashboard.php');
$u_id  = (int)$_SESSION['user_id'];
$model = new ProjectModel($pdo);
$rates = new RateResolver($pdo);   // v1.7.68: ricalcolo costi in modifica rapporto
$wf    = new ProjectWorkflow($pdo);

$pid = (int)($_GET['id'] ?? 0);

// v1.8.5: download di un allegato di report (prima di header.php)
if (($fid = (int)($_GET['dl_upfile'] ?? 0)) > 0) {
    $file = $wf->file($fid);
    if (!$file || (int)$file['project_id'] !== $pid) { http_response_code(404); exit('File non trovato.'); }
    $path = __DIR__ . '/uploads/commesse/' . $pid . '/' . $file['stored_name'];
    if (!is_file($path)) { http_response_code(404); exit('File mancante sul server.'); }
    header('Content-Type: ' . ($file['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg']="<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('project_dashboard',['id'=>$pid]); }
    $act = $_POST['action'] ?? '';

    if ($act === 'save_presales') {
        foreach (['Ufficio Gare','Sicurezza','Ingegneria/Analisi Tecnica','Project Management'] as $cc) {
            $h = (float)str_replace(',', '.', $_POST['hours'][$cc] ?? '0');
            $r = ($_POST['rate'][$cc] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['rate'][$cc]) : null;
            $pdo->prepare("INSERT INTO cm_presales_effort (project_id,cost_center,hours,hourly_rate) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE hours=VALUES(hours),hourly_rate=VALUES(hourly_rate)")
                ->execute([$pid,$cc,$h,$r]);
        }
        write_log('Projects','success',"Effort presales aggiornato commessa #$pid",$u_id);
        $_SESSION['flash_msg']="<div class='alert alert-success'>Effort presales salvato.</div>";
        redirect('project_dashboard',['id'=>$pid]);
    }

    if ($act === 'add_member') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        $hrs = (float)str_replace(',', '.', $_POST['allocated_hours'] ?? '0');
        if ($eid) {
            $pdo->prepare("INSERT INTO cm_team (project_id,employee_id,allocated_hours,role_in_project) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE allocated_hours=VALUES(allocated_hours),role_in_project=VALUES(role_in_project)")
                ->execute([$pid,$eid,$hrs, trim($_POST['role_in_project'] ?? '') ?: null]);
            write_log('Projects','success',"Team: assegnato dip #$eid a commessa #$pid",$u_id);
        }
        redirect('project_dashboard',['id'=>$pid]);
    }

    // v1.7.89: tariffe di fascia specifiche per questa commessa (ed eventuale professionista)
    if ($act === 'add_band_rate') {
        $bandId = (int)($_POST['band_id'] ?? 0);
        $profId = (int)($_POST['professional_id'] ?? 0);
        $ct     = in_array($_POST['cost_type'] ?? '', ['Aziendale','Cliente','Commerciale'], true) ? $_POST['cost_type'] : 'Cliente';
        $rg     = ($_POST['regime'] ?? '') === 'Reperibilità' ? 'Reperibilità' : 'Ordinario';
        $rate   = (float)str_replace(',', '.', (string)($_POST['rate_hour'] ?? '0'));
        if ($bandId > 0) {
            $pdo->prepare(
                "INSERT INTO cm_project_band_rates (project_id,band_id,professional_id,cost_type,regime,rate_hour,note,created_by)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE rate_hour=VALUES(rate_hour), note=VALUES(note)"
            )->execute([$pid, $bandId, $profId, $ct, $rg, $rate, trim((string)($_POST['note'] ?? '')) ?: null, $u_id]);
            write_log('Projects','success',"Tariffa fascia #$bandId (prof #$profId, $ct/$rg) impostata su commessa #$pid",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Tariffa di fascia salvata per questa commessa.</div>";
        }
        redirect('project_dashboard', ['id' => $pid]);
    }
    if ($act === 'del_band_rate') {
        $rid = (int)($_POST['rate_id'] ?? 0);
        (new RecycleBin($pdo))->softDelete('cm_project_band_rates', 'id=? AND project_id=?', [$rid, $pid], null, $u_id, 'project_dashboard.php');
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Tariffa di fascia rimossa.</div>";
        redirect('project_dashboard', ['id' => $pid]);
    }

    if ($act === 'del_member') {
        $tid = (int)($_POST['team_id'] ?? 0);
        (new RecycleBin($pdo))->softDelete('cm_team', 'id=? AND project_id=?', [$tid,$pid], null, $u_id, 'project_dashboard.php');
        redirect('project_dashboard',['id'=>$pid]);
    }

    // v1.7.68: modifica di un rapporto di intervento
    if ($act === 'save_report') {
        $rid = (int)($_POST['report_id'] ?? 0);
        $cur = $model->intervention($rid);
        if (!$cur || (int)$cur['project_id'] !== $pid) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Rapporto non trovato per questa commessa.</div>";
            redirect('project_dashboard', ['id' => $pid]);
        }
        $num  = fn($k) => ($_POST[$k] ?? '') !== '' ? (float)str_replace(',', '.', (string)$_POST[$k]) : 0.0;
        $nn   = fn($v) => ($v === '' || $v === null) ? null : $v;
        $bandId  = (int)($_POST['band_id'] ?? 0) ?: null;
        $onCall  = isset($_POST['on_call']) ? 1 : 0;
        $qty     = $num('quantity_hours');
        $calc    = $rates->calcCosts($bandId, $qty, (bool)$onCall);   // ricalcolo coerente con RateResolver

        $newProj = (int)($_POST['project_id'] ?? $pid) ?: $pid;
        $pdo->prepare(
            "UPDATE cm_intervention_reports SET
               report_date=?, start_at=?, end_at=?, approved=?, project_id=?,
               technician_id=?, band_id=?, client_id=?, client_location_id=?,
               service_type=?, tech_sector=?, ticket=?, client_reference=?,
               request_text=?, work_done=?, remote=?, on_call=?,
               planned_hours=?, quantity_hours=?, diff_hours=?, extra_hours=?,
               client_revenue_import=?, company_cost_import=?,
               client_revenue_calc=?, company_cost_calc=?, commercial_cost_calc=?
             WHERE id=?"
        )->execute([
            $nn($_POST['report_date'] ?? ''), $nn($_POST['start_at'] ?? ''), $nn($_POST['end_at'] ?? ''),
            isset($_POST['approved']) ? 1 : 0, $newProj,
            (int)($_POST['technician_id'] ?? 0) ?: null, $bandId,
            (int)($_POST['client_id'] ?? 0) ?: null, (int)($_POST['client_location_id'] ?? 0) ?: null,
            $nn(trim($_POST['service_type'] ?? '')), $nn(trim($_POST['tech_sector'] ?? '')),
            $nn(trim($_POST['ticket'] ?? '')), $nn(trim($_POST['client_reference'] ?? '')),
            $nn(trim($_POST['request_text'] ?? '')), $nn(trim($_POST['work_done'] ?? '')),
            isset($_POST['remote']) ? 1 : 0, $onCall,
            $num('planned_hours'), $qty, $num('diff_hours'), $num('extra_hours'),
            $num('client_revenue_import'), $num('company_cost_import'),
            $calc['client_revenue_calc'], $calc['company_cost_calc'], $calc['commercial_cost_calc'],
            $rid,
        ]);
        write_log('Interventions','success',"Rapporto {$cur['report_code']} (#$rid) modificato su commessa #$pid",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Rapporto " . h($cur['report_code']) . " aggiornato."
            . ($newProj !== $pid ? " Riassegnato ad altra commessa." : "") . "</div>";
        redirect('project_dashboard', ['id' => $pid]);
    }

    // v1.7.68: allinea il team ai rapporti di intervento
    if ($act === 'sync_team') {
        $r = $model->syncTeamFromReports($pid, $u_id);
        write_log('Projects','success',"Team allineato dai rapporti su commessa #$pid (team: {$r['team_size']})",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Team allineato dai rapporti di intervento: "
            . "{$r['team_size']} risorse in team. Le righe inserite manualmente non sono state modificate.</div>";
        redirect('project_dashboard', ['id' => $pid]);
    }

    // v1.7.69: gestione fasi di commessa (per il Gantt)
    if ($act === 'add_phase') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $nn = fn($v) => ($v === '' || $v === null) ? null : $v;
            $pdo->prepare(
                "INSERT INTO cm_project_phases (project_id, name, start_date, end_date, progress_pct, sort_order, notes)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $pid, $name, $nn($_POST['start_date'] ?? ''), $nn($_POST['end_date'] ?? ''),
                max(0, min(100, (int)($_POST['progress_pct'] ?? 0))),
                (int)($_POST['sort_order'] ?? 0), $nn(trim($_POST['notes'] ?? '')),
            ]);
            write_log('Projects','success',"Fase '$name' aggiunta a commessa #$pid",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Fase aggiunta.</div>";
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'gantt']);
    }
    if ($act === 'update_phase') {
        $phid = (int)($_POST['phase_id'] ?? 0);
        $nn = fn($v) => ($v === '' || $v === null) ? null : $v;
        $pdo->prepare(
            "UPDATE cm_project_phases SET name=?, start_date=?, end_date=?, progress_pct=?, sort_order=?, notes=?
              WHERE id=? AND project_id=?"
        )->execute([
            trim($_POST['name'] ?? ''), $nn($_POST['start_date'] ?? ''), $nn($_POST['end_date'] ?? ''),
            max(0, min(100, (int)($_POST['progress_pct'] ?? 0))), (int)($_POST['sort_order'] ?? 0),
            $nn(trim($_POST['notes'] ?? '')), $phid, $pid,
        ]);
        write_log('Projects','success',"Fase #$phid aggiornata (commessa #$pid)",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Fase aggiornata.</div>";
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'gantt']);
    }
    if ($act === 'del_phase') {
        (new RecycleBin($pdo))->softDelete('cm_project_phases', 'id=? AND project_id=?', [(int)($_POST['phase_id'] ?? 0), $pid], null, $u_id, 'project_dashboard.php');
        write_log('Projects','success',"Fase eliminata (commessa #$pid)",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Fase eliminata.</div>";
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'gantt']);
    }

    /* ── v1.8.5: Report & Avanzamento (note, valutazioni, allegati) ──────── */
    $store_files = function (int $updateId) use ($pdo, $pid, $u_id): int {
        if (empty($_FILES['attachments']) || !is_array($_FILES['attachments']['name'])) return 0;
        $dir = __DIR__ . '/uploads/commesse/' . $pid . '/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $okExt = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','jpg','jpeg','png','gif','zip','msg','eml','odt','ods'];
        $maxB  = min(UploadGuard::maxUploadBytes(), 20 * 1024 * 1024);
        $n = 0; $c = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $c; $i++) {
            if ((int)$_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = (string)$_FILES['attachments']['name'][$i];
            $tmp  = (string)$_FILES['attachments']['tmp_name'][$i];
            $size = (int)$_FILES['attachments']['size'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, $okExt, true) || $size <= 0 || $size > $maxB || !is_uploaded_file($tmp)) continue;
            $stored = 'upd' . $updateId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (@move_uploaded_file($tmp, $dir . $stored)) {
                $pdo->prepare("INSERT INTO cm_project_update_files (update_id, project_id, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$updateId, $pid, mb_substr($orig, 0, 255), $stored,
                               mb_substr((string)($_FILES['attachments']['type'][$i] ?? ''), 0, 120), $size, $u_id]);
                $n++;
            }
        }
        return $n;
    };

    if ($act === 'add_update') {
        $nn = fn($v) => ($v === '' || $v === null) ? null : $v;
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['update_date'] ?? '') ? $_POST['update_date'] : date('Y-m-d');
        $kind = array_key_exists($_POST['kind'] ?? '', ProjectWorkflow::KINDS) ? $_POST['kind'] : 'nota';
        $rating = ($_POST['rating'] ?? '') !== '' ? max(1, min(5, (int)$_POST['rating'])) : null;
        $prog   = ($_POST['progress_pct'] ?? '') !== '' ? max(0, min(100, (int)$_POST['progress_pct'])) : null;
        $phase  = (int)($_POST['phase_id'] ?? 0) ?: null;
        $step   = (int)($_POST['step_id'] ?? 0) ?: null;
        $body   = trim($_POST['body'] ?? '');
        if ($body !== '' || trim($_POST['title'] ?? '') !== '' || !empty($_FILES['attachments']['name'][0])) {
            $pdo->prepare("INSERT INTO cm_project_updates (project_id, update_date, kind, title, body, rating, progress_pct, phase_id, step_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$pid, $date, $kind, $nn(trim($_POST['title'] ?? '')), $nn($body), $rating, $prog, $phase, $step, $u_id]);
            $uid = (int)$pdo->lastInsertId();
            $nf = $store_files($uid);
            write_log('Projects', 'success', "Report avanzamento #$uid su commessa #$pid ($nf allegati)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Report salvato" . ($nf ? " con $nf allegato/i" : '') . ".</div>";
        } else {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Inserisci un testo, un titolo o un allegato.</div>";
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'report']);
    }

    if ($act === 'update_update') {
        $uid = (int)($_POST['update_id'] ?? 0);
        if ($wf->update($uid, $pid)) {
            $nn = fn($v) => ($v === '' || $v === null) ? null : $v;
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['update_date'] ?? '') ? $_POST['update_date'] : date('Y-m-d');
            $kind = array_key_exists($_POST['kind'] ?? '', ProjectWorkflow::KINDS) ? $_POST['kind'] : 'nota';
            $rating = ($_POST['rating'] ?? '') !== '' ? max(1, min(5, (int)$_POST['rating'])) : null;
            $prog   = ($_POST['progress_pct'] ?? '') !== '' ? max(0, min(100, (int)$_POST['progress_pct'])) : null;
            $pdo->prepare("UPDATE cm_project_updates SET update_date=?, kind=?, title=?, body=?, rating=?, progress_pct=?, phase_id=?, step_id=? WHERE id=? AND project_id=?")
                ->execute([$date, $kind, $nn(trim($_POST['title'] ?? '')), $nn(trim($_POST['body'] ?? '')), $rating, $prog,
                           (int)($_POST['phase_id'] ?? 0) ?: null, (int)($_POST['step_id'] ?? 0) ?: null, $uid, $pid]);
            $nf = $store_files($uid);
            write_log('Projects', 'success', "Report #$uid modificato (commessa #$pid, +$nf allegati)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Report aggiornato" . ($nf ? " (+$nf allegato/i)" : '') . ".</div>";
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'report']);
    }

    if ($act === 'del_update') {
        $uid = (int)($_POST['update_id'] ?? 0);
        if ($wf->update($uid, $pid)) {
            $pdo->prepare("UPDATE cm_project_updates SET deleted_at=NOW() WHERE id=? AND project_id=?")->execute([$uid, $pid]);
            write_log('Projects', 'success', "Report #$uid eliminato (commessa #$pid)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Report eliminato.</div>";
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'report']);
    }

    if ($act === 'del_upfile') {
        $fid = (int)($_POST['file_id'] ?? 0);
        $file = $wf->file($fid);
        if ($file && (int)$file['project_id'] === $pid) {
            @unlink(__DIR__ . '/uploads/commesse/' . $pid . '/' . $file['stored_name']);
            $pdo->prepare("DELETE FROM cm_project_update_files WHERE id=?")->execute([$fid]);
            write_log('Projects', 'success', "Allegato #$fid rimosso (commessa #$pid)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Allegato rimosso.</div>";
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'report']);
    }

    /* ── v1.8.5: Workflow programmabile (step agganciati al Gantt) ────────── */
    if ($act === 'add_wstep' || $act === 'update_wstep') {
        $nn = fn($v) => ($v === '' || $v === null) ? null : $v;
        $name = trim($_POST['name'] ?? '');
        $status = array_key_exists($_POST['status'] ?? '', ProjectWorkflow::STATUS) ? $_POST['status'] : 'da_fare';
        $prog = max(0, min(100, (int)($_POST['progress_pct'] ?? 0)));
        if ($status === 'completato') $prog = 100;
        $phase = (int)($_POST['phase_id'] ?? 0) ?: null;
        $args = [$name, $nn(trim($_POST['description'] ?? '')), $phase, $status,
                 $nn($_POST['start_date'] ?? ''), $nn($_POST['due_date'] ?? ''),
                 (int)($_POST['assignee_employee_id'] ?? 0) ?: null, $prog,
                 isset($_POST['is_gate']) ? 1 : 0, (int)($_POST['sort_order'] ?? 0)];
        if ($name !== '') {
            if ($act === 'add_wstep') {
                $pdo->prepare("INSERT INTO cm_workflow_steps (name,description,phase_id,status,start_date,due_date,assignee_employee_id,progress_pct,is_gate,sort_order,project_id,created_by,completed_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute(array_merge($args, [$pid, $u_id, $status === 'completato' ? date('Y-m-d H:i:s') : null]));
                $sid = (int)$pdo->lastInsertId();
                write_log('Projects', 'success', "Step workflow '$name' aggiunto (commessa #$pid)", $u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Step di workflow aggiunto.</div>";
            } else {
                $sid = (int)($_POST['step_id'] ?? 0);
                $prev = $wf->step($sid, $pid);
                if ($prev) {
                    $completedAt = $status === 'completato' ? ($prev['completed_at'] ?: date('Y-m-d H:i:s')) : null;
                    $pdo->prepare("UPDATE cm_workflow_steps SET name=?,description=?,phase_id=?,status=?,start_date=?,due_date=?,assignee_employee_id=?,progress_pct=?,is_gate=?,sort_order=?,completed_at=? WHERE id=? AND project_id=?")
                        ->execute(array_merge($args, [$completedAt, $sid, $pid]));
                    write_log('Projects', 'success', "Step workflow #$sid aggiornato (commessa #$pid)", $u_id);
                    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Step di workflow aggiornato.</div>";
                    // aggancio Gantt: ricalcola la fase eventualmente scollegata
                    if (!empty($prev['phase_id']) && (int)$prev['phase_id'] !== (int)$phase) $wf->recomputePhaseProgress($pid, (int)$prev['phase_id']);
                }
            }
            if ($phase) $wf->recomputePhaseProgress($pid, $phase); // aggancio Gantt
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'report']);
    }

    if ($act === 'set_wstep_status') {
        $sid = (int)($_POST['step_id'] ?? 0);
        $s = $wf->step($sid, $pid);
        $status = array_key_exists($_POST['status'] ?? '', ProjectWorkflow::STATUS) ? $_POST['status'] : null;
        if ($s && $status) {
            $prog = $status === 'completato' ? 100 : ($status === 'da_fare' ? 0 : (int)$s['progress_pct']);
            $completedAt = $status === 'completato' ? ($s['completed_at'] ?: date('Y-m-d H:i:s')) : null;
            $pdo->prepare("UPDATE cm_workflow_steps SET status=?, progress_pct=?, completed_at=? WHERE id=? AND project_id=?")
                ->execute([$status, $prog, $completedAt, $sid, $pid]);
            if (!empty($s['phase_id'])) $wf->recomputePhaseProgress($pid, (int)$s['phase_id']); // aggancio Gantt
            write_log('Projects', 'success', "Step workflow #$sid → $status (commessa #$pid)", $u_id);
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'report']);
    }

    if ($act === 'del_wstep') {
        $sid = (int)($_POST['step_id'] ?? 0);
        $s = $wf->step($sid, $pid);
        if ($s) {
            $pdo->prepare("UPDATE cm_workflow_steps SET deleted_at=NOW() WHERE id=? AND project_id=?")->execute([$sid, $pid]);
            if (!empty($s['phase_id'])) $wf->recomputePhaseProgress($pid, (int)$s['phase_id']); // aggancio Gantt
            write_log('Projects', 'success', "Step workflow #$sid eliminato (commessa #$pid)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Step eliminato.</div>";
        }
        redirect('project_dashboard', ['id' => $pid, 'tab' => 'report']);
    }

    if ($act === 'save_workflow') {
        $pdo->prepare("UPDATE cm_projects SET commercial_status=?, loss_allocation=?, material_costs=? WHERE id=?")
            ->execute([
                $_POST['commercial_status'] ?: null,
                $_POST['loss_allocation'] ?: null,
                (float)str_replace(',', '.', $_POST['material_costs'] ?? '0'),
                $pid,
            ]);
        $_SESSION['flash_msg']="<div class='alert alert-success'>Workflow aggiornato.</div>";
        redirect('project_dashboard',['id'=>$pid]);
    }
    redirect('project_dashboard',['id'=>$pid]);
}

$p = $model->find($pid);
if (!$p) { $_SESSION['flash_msg']="<div class='alert alert-danger'>Commessa non trovata.</div>"; redirect('manage_projects'); }

// v1.7.68: rapporti paginati/filtrabili + supporto modifica
$rp_page   = max(1, (int)($_GET['rp'] ?? 1));
$rp_per    = 50;
$rp_filter = ['q' => trim($_GET['q'] ?? ''), 'approved' => $_GET['appr'] ?? '', 'on_call' => $_GET['rep'] ?? ''];
$rp        = $model->interventionsPaged($pid, $rp_filter, $rp_per, ($rp_page - 1) * $rp_per);
$rp_pages  = max(1, (int)ceil($rp['total'] / $rp_per));
$edit_id   = (int)($_GET['edit_report'] ?? 0);
$edit_rep  = $edit_id ? $model->intervention($edit_id) : null;
if ($edit_rep && (int)$edit_rep['project_id'] !== $pid) $edit_rep = null;
$missing_tech = $model->techniciansMissingFromTeam($pid);
$all_clients  = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$all_bands    = $pdo->query("SELECT id, band_name FROM cm_rate_bands ORDER BY band_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$all_projects = $pdo->query("SELECT id, CONCAT(project_code,' — ',name) l FROM cm_projects ORDER BY project_code")->fetchAll(PDO::FETCH_KEY_PAIR);
// v1.8.11: riconciliazione con i dati DGB (DogoBit) tramite dgb_contract_id
$dgb = new DgbModel($pdo);
$dgb_cid = (int)($p['dgb_contract_id'] ?? (int)$pdo->query("SELECT dgb_contract_id FROM cm_projects WHERE id=" . (int)$pid)->fetchColumn());
$dgb_roll = $dgb_cid ? $dgb->rollupForContract($dgb_cid) : null;
$dgb_acts = $dgb_cid ? $dgb->activitiesForContract($dgb_cid, 30) : [];
// v1.7.89: tariffe di fascia specifiche di questa commessa (ed eventuale professionista)
$band_list = $pdo->query("SELECT id, band_name FROM cm_rate_bands ORDER BY band_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$prof_list = [];
$proj_rates = [];
try {
    $prof_list = $pdo->query("SELECT id, CONCAT(last_name,' ',first_name) l FROM cm_professionals WHERE deleted_src=0 ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $str = $pdo->prepare(
        "SELECT r.*, b.band_name, CONCAT(COALESCE(p.last_name,''),' ',COALESCE(p.first_name,'')) AS prof_name
           FROM cm_project_band_rates r
           JOIN cm_rate_bands b ON b.id = r.band_id
           LEFT JOIN cm_professionals p ON p.id = r.professional_id
          WHERE r.project_id = ? ORDER BY b.band_name, r.professional_id, r.cost_type, r.regime");
    $str->execute([$pid]);
    $proj_rates = $str->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* schema precedente */ }
$edit_locs    = [];
if ($edit_rep && $edit_rep['client_id']) {
    $stl = $pdo->prepare("SELECT id, location_name FROM client_locations WHERE client_id=? ORDER BY location_name");
    $stl->execute([$edit_rep['client_id']]);
    $edit_locs = $stl->fetchAll(PDO::FETCH_KEY_PAIR);
}

$team     = $model->team($pid);

// v1.7.69: dati Gantt della commessa
$gantt        = new Gantt($pdo);
$gph          = $gantt->phases($pid);
$gact         = $gantt->actualRange($pid);
$gtech        = $gantt->actualByTechnician($pid);
$gload        = $gantt->monthlyLoad($pid);
$g_edit_phase = (int)($_GET['edit_phase'] ?? 0);
// v1.8.5: dati Report & Avanzamento + Workflow
$updates      = $wf->updates($pid);
$upFiles      = $wf->filesByProject($pid);
$wsteps       = $wf->steps($pid);
$wsummary     = $wf->summary($pid);
$wf_edit_upd  = (int)($_GET['edit_update'] ?? 0) ? $wf->update((int)$_GET['edit_update'], $pid) : null;
$wf_edit_step = (int)($_GET['edit_wstep'] ?? 0) ? $wf->step((int)$_GET['edit_wstep'], $pid) : null;
$wf_gantt_steps = $wf->stepsForGantt($pid);
$presales = $model->presales($pid);
$prof     = $model->profitability($pid);
$acts     = $model->actuals($pid);
$interv   = $model->interventions($pid);
$employees = $pdo->query("SELECT id, first_name, last_name FROM employees ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
$presByCC = []; foreach ($presales as $pe) $presByCC[$pe['cost_center']] = $pe;

$msg='';
if (!empty($_SESSION['flash_msg'])) { $msg=$_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');
$eur = fn($v)=> $v===null?'—':number_format((float)$v,2,',','.').' €';
?>
<div class="page-header"><h1><i class="fa-solid fa-briefcase"></i> <?=h($p['project_code'])?> — <?=h($p['name'])?></h1></div>
<?= $msg ?>

<div class="tabs" style="display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--border)">
  <button class="tab-btn active" data-tab="anag">Anagrafica</button>
  <button class="tab-btn" data-tab="pres">Effort Presales</button>
  <button class="tab-btn" data-tab="team">Team</button>
  <button class="tab-btn" data-tab="redd">Redditività</button>
  <button class="tab-btn" data-tab="cons">Consuntivo</button>
  <button class="tab-btn" data-tab="gantt">Gantt</button>
  <button class="tab-btn" data-tab="report">Report &amp; Avanzamento</button>
  <button class="tab-btn" data-tab="dgb">DGB<?=$dgb_roll?' <span style="background:#0891b2;color:#fff;border-radius:8px;padding:0 6px;font-size:10px">'.number_format((int)$dgb_roll['activities'],0,',','.').'</span>':''?></button>
</div>

<div id="tab-anag" class="tab-pane">
  <div class="card">
    <table class="data-table" style="width:100%">
      <tr><th>Cliente</th><td><?=h($p['client_name'] ?? $p['client_raw'] ?? '—')?></td>
          <th>Az. esecutrice</th><td><?=h($p['exec_company_name'] ?? '—')?></td></tr>
      <tr><th>Linea servizio</th><td><?=h($p['service_line'] ?? '—')?></td>
          <th>Stato operativo</th><td><?=h($p['operational_status'] ?? '—')?></td></tr>
      <tr><th>Stato economico</th><td><?=h($p['economic_status'] ?? '—')?></td>
          <th>Periodo</th><td><?=h($p['start_date'] ?? '—')?> → <?=h($p['end_date'] ?? '—')?></td></tr>
      <tr><th>Valore</th><td><?=$eur($p['value_total'])?></td>
          <th>Consuntivato</th><td><?=$eur($p['actual_cost'])?></td></tr>
      <tr><th>Margine (import)</th><td><?=$eur($p['margin_total'])?></td>
          <th>Anomalie (aperte/bloccanti)</th><td><?=(int)$p['anomalies_open']?> / <?=(int)$p['anomalies_blocking']?></td></tr>
    </table>
  </div>
  <div class="card" style="margin-top:12px">
    <div class="card-header"><span class="card-title">Workflow commerciale / perdita</span></div>
    <form method="post" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_workflow">
      <div class="form-group"><label>Stato commerciale</label>
        <select name="commercial_status" <?=$can_edit?'':'disabled'?>>
          <option value="">—</option>
          <?php foreach(['In Approvazione','Offerta Presentata','Acquisita','Persa'] as $s):?>
            <option <?=$p['commercial_status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?>
        </select></div>
      <div class="form-group"><label>Riallocazione se Persa</label>
        <select name="loss_allocation" <?=$can_edit?'':'disabled'?>>
          <option value="">—</option>
          <?php foreach(['Rischio Impresa 100%','Budget Commerciale 100%','Ripartizione 50/50'] as $s):?>
            <option <?=$p['loss_allocation']===$s?'selected':''?>><?=$s?></option><?php endforeach;?>
        </select></div>
      <div class="form-group"><label>Costi materiali (€)</label>
        <input type="number" step="0.01" name="material_costs" value="<?=h($p['material_costs'])?>" <?=$can_edit?'':'readonly'?>></div>
      <?php if($can_edit):?><div style="grid-column:1/-1"><button class="btn btn-primary">Salva</button></div><?php endif;?>
    </form>
  </div>
</div>

<div id="tab-pres" class="tab-pane" style="display:none">
  <div class="card">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_presales">
      <table class="data-table" style="width:100%">
        <thead><tr><th>Centro di costo</th><th>Ore</th><th>€/ora (opz.)</th><th>Costo</th></tr></thead>
        <tbody>
        <?php foreach (['Ufficio Gare','Sicurezza','Ingegneria/Analisi Tecnica','Project Management'] as $cc):
          $row=$presByCC[$cc]??null; ?>
          <tr>
            <td><?=h($cc)?></td>
            <td><input type="number" step="0.01" name="hours[<?=h($cc)?>]" value="<?=h($row['hours']??'0')?>" <?=$can_edit?'':'readonly'?> style="width:100px"></td>
            <td><input type="number" step="0.01" name="rate[<?=h($cc)?>]" value="<?=h($row['hourly_rate']??'')?>" <?=$can_edit?'':'readonly'?> style="width:100px"></td>
            <td style="text-align:right"><?=$eur($row['cost']??0)?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if($can_edit):?><button class="btn btn-success" style="margin-top:10px">Salva effort</button><?php endif;?>
    </form>
  </div>
</div>

<div id="tab-team" class="tab-pane" style="display:none">
  <?php if($can_edit):?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title"><i class="fa-solid fa-users"></i> Composizione team</span>
      <form method="post" style="display:inline" onsubmit="return confirm('Allineare il team ai rapporti di intervento?\n\nLe risorse presenti nei rapporti verranno aggiunte e le ore delle righe di origine \'Rapporti\' ricalcolate. Le righe inserite manualmente non verranno toccate.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="sync_team">
        <button class="btn btn-sm btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Popola team dai rapporti</button>
      </form>
    </div>
    <?php if($missing_tech): ?>
      <p style="margin:0 0 10px;color:#d97706"><i class="fa-solid fa-triangle-exclamation"></i>
        <strong><?= count($missing_tech) ?></strong> tecnici presenti nei rapporti non sono ancora in team:
        <?php $names=array_map(fn($m)=>h($m['nome']).' ('.$m['ore'].' h)', array_slice($missing_tech,0,6));
              echo implode(', ', $names); if(count($missing_tech)>6) echo ' …'; ?>
      </p>
    <?php endif; ?>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_member">
      <div class="form-group" style="margin:0"><label>Aggiungi risorsa</label>
        <select name="employee_id" required>
          <option value="">—</option>
          <?php foreach($employees as $e):?><option value="<?=$e['id']?>"><?=h($e['last_name'].' '.$e['first_name'])?></option><?php endforeach;?>
        </select></div>
      <div class="form-group" style="margin:0"><label>Ore allocate</label><input type="number" step="0.01" name="allocated_hours" value="0"></div>
      <div class="form-group" style="margin:0"><label>Ruolo</label><input type="text" name="role_in_project"></div>
      <button class="btn"><i class="fa-solid fa-plus"></i> Aggiungi</button>
    </form>
  </div>
  <?php endif;?>
  <div class="card">
    <table class="data-table" style="width:100%">
      <thead><tr><th>Risorsa</th><th>Tipo</th><th>Ruolo</th><th>Origine</th><th>Ore allocate</th><th>Ore da rapporti</th><th>Rapporti</th><th>€/h</th><th>Costo su allocato</th><th>Costo su rapporti</th><th>Valore</th><th>Class.</th><?php if($can_edit):?><th></th><?php endif;?></tr></thead>
      <tbody>
      <?php if(!$team): ?><tr><td colspan="13" style="text-align:center;color:var(--muted);padding:16px">Nessuna risorsa allocata. Usa <em>Popola team dai rapporti</em> per ricavarla dal consuntivo.</td></tr>
      <?php else: $tot_all=0;$tot_rep=0;$tot_ca=0;$tot_cr=0; foreach ($team as $t): $tot_all+=(float)$t['allocated_hours'];$tot_rep+=(float)$t['report_hours'];$tot_ca+=(float)$t['hr_cost'];$tot_cr+=(float)$t['report_cost']; ?>
        <tr>
          <td><?=h($t['last_name'].' '.$t['first_name'])?></td>
          <td><?php if(($t['member_type']??'dipendente')==='esterno'): ?>
                <span style="background:#cffafe;color:#0e7490;border-radius:8px;padding:1px 8px;font-size:11px;font-weight:700"><i class="fa-solid fa-user-tie"></i> Esterno</span>
              <?php else: ?>
                <span style="background:#ede9fe;color:#6d28d9;border-radius:8px;padding:1px 8px;font-size:11px;font-weight:700"><i class="fa-solid fa-id-badge"></i> Dipendente</span>
              <?php endif; ?></td>
          <td><?=h($t['role_in_project'] ?? '—')?></td>
          <td><span style="font-size:11px;color:<?= ($t['source']??'Manuale')==='Rapporti' ? '#0369a1' : 'var(--muted)' ?>"><?=h($t['source'] ?? 'Manuale')?></span></td>
          <td style="text-align:right"><?=h($t['allocated_hours'])?></td>
          <td style="text-align:right"><strong><?=h($t['report_hours'])?></strong></td>
          <td style="text-align:right"><?=(int)$t['report_count']?></td>
          <td style="text-align:right"><?=$eur($t['hourly_cost'])?></td>
          <td style="text-align:right"><?=$eur($t['hr_cost'])?></td>
          <td style="text-align:right"><?=$eur($t['report_cost'])?></td>
          <td><span style="color:<?=($t['value_type']==='Servizio a Valore')?'#16a34a':'#6b7280'?>;font-weight:600"><?=h($t['value_type'] ?? 'N/D')?></span></td>
          <td><?=h($t['employment_type'] ?? 'N/D')?></td>
          <?php if($can_edit):?>
          <td><form method="post" onsubmit="return confirm('Rimuovere?')" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="del_member"><input type="hidden" name="team_id" value="<?=$t['id']?>">
            <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button></form></td>
          <?php endif;?>
        </tr>
      <?php endforeach; ?>
        <tr style="background:#f8fafc;font-weight:700">
          <td colspan="4">Totali</td>
          <td style="text-align:right"><?=round($tot_all,2)?></td>
          <td style="text-align:right"><?=round($tot_rep,2)?></td>
          <td colspan="2"></td>
          <td style="text-align:right"><?=$eur($tot_ca)?></td>
          <td style="text-align:right"><?=$eur($tot_cr)?></td>
          <td colspan="<?=$can_edit?3:2?>"></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="margin-top:14px">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-euro-sign"></i> Tariffe di fascia per questa commessa</span></div>
    <p style="color:var(--muted);font-size:12px;margin:4px 0 12px">
      Sovrascrivono le tariffe generali <strong>solo su questa commessa</strong>. Lascia <em>Professionista</em> vuoto per applicarle a tutta la commessa
      (es. fascia <code>E</code> con valore diverso per commessa); indica un professionista per una fascia dedicata a chi esegue l'attività (es. fascia <code>X</code>).
      Priorità: commessa + professionista → commessa → tariffa generale.
    </p>
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Fascia</th><th>Professionista</th><th>Tipo costo</th><th>Regime</th><th style="text-align:right">€/h</th><th>Note</th><?php if($can_edit):?><th></th><?php endif;?></tr></thead>
      <tbody>
      <?php if(!$proj_rates): ?>
        <tr><td colspan="<?=$can_edit?7:6?>" style="text-align:center;color:var(--muted);padding:12px">Nessuna tariffa specifica: valgono le tariffe generali di fascia.</td></tr>
      <?php else: foreach($proj_rates as $pr): ?>
        <tr>
          <td><strong><?=h($pr['band_name'])?></strong></td>
          <td><?= ((int)$pr['professional_id'] === 0)
                ? '<span style="color:var(--muted)">tutta la commessa</span>'
                : '<span style="background:#cffafe;color:#0e7490;border-radius:8px;padding:1px 8px;font-size:11px;font-weight:700">' . h(trim((string)$pr['prof_name'])) . '</span>' ?></td>
          <td><?=h($pr['cost_type'])?></td>
          <td><?=h($pr['regime'])?></td>
          <td style="text-align:right"><strong><?=$eur($pr['rate_hour'])?></strong></td>
          <td style="color:var(--muted)"><?=h($pr['note'] ?? '—')?></td>
          <?php if($can_edit):?>
          <td><form method="post" onsubmit="return confirm('Rimuovere questa tariffa?')" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="del_band_rate"><input type="hidden" name="rate_id" value="<?=$pr['id']?>">
            <button class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button></form></td>
          <?php endif;?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?php if($can_edit): ?>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_band_rate">
      <div class="form-group" style="margin:0"><label>Fascia</label>
        <select name="band_id" required><option value="">—</option>
          <?php foreach($band_list as $bid=>$bn):?><option value="<?=$bid?>"><?=h($bn)?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0"><label>Professionista (opz.)</label>
        <select name="professional_id"><option value="0">— tutta la commessa —</option>
          <?php foreach($prof_list as $prid=>$pn):?><option value="<?=$prid?>"><?=h($pn)?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0"><label>Tipo costo</label>
        <select name="cost_type"><option>Cliente</option><option>Aziendale</option><option>Commerciale</option></select></div>
      <div class="form-group" style="margin:0"><label>Regime</label>
        <select name="regime"><option>Ordinario</option><option>Reperibilità</option></select></div>
      <div class="form-group" style="margin:0"><label>€/h</label><input type="text" name="rate_hour" value="0" style="width:90px"></div>
      <div class="form-group" style="margin:0"><label>Note</label><input type="text" name="note" style="width:180px"></div>
      <button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Aggiungi tariffa</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div id="tab-redd" class="tab-pane" style="display:none">
  <div class="card">
    <table class="data-table" style="width:100%">
      <tr><th>Ricavi (valore commessa)</th><td style="text-align:right"><?=$eur($prof['revenue']??0)?></td></tr>
      <tr><th>Costi materiali</th><td style="text-align:right"><?=$eur($prof['materials']??0)?></td></tr>
      <tr><th>Costo risorse HR</th><td style="text-align:right"><?=$eur($prof['hr_cost']??0)?></td></tr>
      <tr><th>Costi nascosti presales</th><td style="text-align:right"><?=$eur($prof['presales_cost']??0)?></td></tr>
      <tr><th>Costo totale</th><td style="text-align:right"><strong><?=$eur($prof['total_cost']??0)?></strong></td></tr>
      <tr><th>Margine previsionale</th><td style="text-align:right;color:<?=($prof['margin']??0)>=0?'#16a34a':'#dc2626'?>"><strong><?=$eur($prof['margin']??0)?> (<?=$prof['margin_pct']??0?>%)</strong></td></tr>
      <?php if(($p['commercial_status']??'')==='Persa'):?>
      <tr><th>↳ Presales su Rischio Impresa</th><td style="text-align:right"><?=$eur($prof['loss_rischio']??0)?></td></tr>
      <tr><th>↳ Presales su Budget Commerciale</th><td style="text-align:right"><?=$eur($prof['loss_commerciale']??0)?></td></tr>
      <?php endif;?>
    </table>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px">
    <div class="card"><div class="card-header"><span class="card-title">Ore/Costi per Valore</span></div>
      <table class="data-table" style="width:100%"><thead><tr><th>Categoria</th><th>Ore</th><th>Costo</th></tr></thead><tbody>
      <?php foreach(($prof['by_value_type']??[]) as $k=>$v):?><tr><td><?=h($k)?></td><td style="text-align:right"><?=$v['hours']?></td><td style="text-align:right"><?=$eur($v['cost'])?></td></tr><?php endforeach;?>
      </tbody></table></div>
    <div class="card"><div class="card-header"><span class="card-title">Ore/Costi Diretto/Indiretto</span></div>
      <table class="data-table" style="width:100%"><thead><tr><th>Categoria</th><th>Ore</th><th>Costo</th></tr></thead><tbody>
      <?php foreach(($prof['by_classification']??[]) as $k=>$v):?><tr><td><?=h($k)?></td><td style="text-align:right"><?=$v['hours']?></td><td style="text-align:right"><?=$eur($v['cost'])?></td></tr><?php endforeach;?>
      </tbody></table></div>
  </div>
</div>

<div id="tab-cons" class="tab-pane" style="display:none">
  <div class="card" style="margin-bottom:12px">
    <table class="data-table" style="width:100%">
      <tr><th>Rapporti</th><td><?=$acts['count']?></td><th>Ore totali</th><td><?=$acts['hours_total']?></td></tr>
      <tr><th>Ricavo (cliente)</th><td><?=$eur($acts['client_total'])?></td><th>Costo (aziendale)</th><td><?=$eur($acts['company_total'])?></td></tr>
      <tr><th>Margine effettivo</th><td colspan="3" style="color:<?=$acts['actual_margin']>=0?'#16a34a':'#dc2626'?>"><strong><?=$eur($acts['actual_margin'])?></strong></td></tr>
      <tr><th>Ordinario (ore/costo/ricavo)</th><td><?=$acts['ordinary']['hours']?> / <?=$eur($acts['ordinary']['company'])?> / <?=$eur($acts['ordinary']['client'])?></td>
          <th>Reperibilità</th><td><?=$acts['oncall']['hours']?> / <?=$eur($acts['oncall']['company'])?> / <?=$eur($acts['oncall']['client'])?></td></tr>
    </table>
  </div>

<?php if ($edit_rep): ?>
  <div class="card" style="margin-bottom:12px;border:2px solid var(--p)">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-pen-to-square"></i> Modifica rapporto <?=h($edit_rep['report_code'])?></span></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_report">
      <input type="hidden" name="report_id" value="<?=(int)$edit_rep['id']?>">
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
        <div class="form-group"><label>Data rapporto</label><input type="date" name="report_date" value="<?=h($edit_rep['report_date'])?>"></div>
        <div class="form-group"><label>Inizio intervento</label><input type="datetime-local" name="start_at" value="<?=h($edit_rep['start_at'] ? str_replace(' ','T',substr($edit_rep['start_at'],0,16)) : '')?>"></div>
        <div class="form-group"><label>Fine intervento</label><input type="datetime-local" name="end_at" value="<?=h($edit_rep['end_at'] ? str_replace(' ','T',substr($edit_rep['end_at'],0,16)) : '')?>"></div>
        <div class="form-group"><label>Commessa</label>
          <select name="project_id"><?php foreach($all_projects as $id=>$l):?><option value="<?=$id?>" <?=$edit_rep['project_id']==$id?'selected':''?>><?=h($l)?></option><?php endforeach;?></select></div>

        <div class="form-group"><label>Tecnico</label>
          <select name="technician_id"><option value="">— non risolto (<?=h($edit_rep['technician_raw'] ?? '—')?>) —</option>
            <?php foreach($employees as $e):?><option value="<?=$e['id']?>" <?=$edit_rep['technician_id']==$e['id']?'selected':''?>><?=h($e['last_name'].' '.$e['first_name'])?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Fascia</label>
          <select name="band_id"><option value="">— non risolta (<?=h($edit_rep['band_raw'] ?? '—')?>) —</option>
            <?php foreach($all_bands as $id=>$bn):?><option value="<?=$id?>" <?=$edit_rep['band_id']==$id?'selected':''?>><?=h($bn)?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Cliente</label>
          <select name="client_id" id="rep_client"><option value="">— (<?=h($edit_rep['client_raw'] ?? '—')?>) —</option>
            <?php foreach($all_clients as $id=>$cn):?><option value="<?=$id?>" <?=$edit_rep['client_id']==$id?'selected':''?>><?=h($cn)?></option><?php endforeach;?></select></div>
        <div class="form-group"><label>Sede cliente</label>
          <select name="client_location_id" id="rep_loc"><option value="">— (<?=h($edit_rep['site_raw'] ?? '—')?>) —</option>
            <?php foreach($edit_locs as $id=>$ln):?><option value="<?=$id?>" <?=$edit_rep['client_location_id']==$id?'selected':''?>><?=h($ln)?></option><?php endforeach;?></select></div>

        <div class="form-group"><label>Tipo servizio</label><input type="text" name="service_type" value="<?=h($edit_rep['service_type'])?>"></div>
        <div class="form-group"><label>Settore tecnologico</label><input type="text" name="tech_sector" value="<?=h($edit_rep['tech_sector'])?>"></div>
        <div class="form-group"><label>Ticket</label><input type="text" name="ticket" value="<?=h($edit_rep['ticket'])?>"></div>
        <div class="form-group"><label>Riferimento cliente</label><input type="text" name="client_reference" value="<?=h($edit_rep['client_reference'])?>"></div>

        <div class="form-group"><label>Pianificato (ore)</label><input type="number" step="0.01" name="planned_hours" value="<?=h($edit_rep['planned_hours'])?>"></div>
        <div class="form-group"><label>Quantità (ore)</label><input type="number" step="0.01" name="quantity_hours" value="<?=h($edit_rep['quantity_hours'])?>"></div>
        <div class="form-group"><label>Diff. (ore)</label><input type="number" step="0.01" name="diff_hours" value="<?=h($edit_rep['diff_hours'])?>"></div>
        <div class="form-group"><label>Di cui extra (ore)</label><input type="number" step="0.01" name="extra_hours" value="<?=h($edit_rep['extra_hours'])?>"></div>

        <div class="form-group"><label>Ricavo cliente (€)</label><input type="number" step="0.01" name="client_revenue_import" value="<?=h($edit_rep['client_revenue_import'])?>"></div>
        <div class="form-group"><label>Costo aziendale (€)</label><input type="number" step="0.01" name="company_cost_import" value="<?=h($edit_rep['company_cost_import'])?>"></div>
        <div class="form-group" style="display:flex;gap:16px;align-items:center;padding-top:20px">
          <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="approved" <?=((int)$edit_rep['approved'])?'checked':''?>> Approvato</label>
          <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="remote" <?=((int)$edit_rep['remote'])?'checked':''?>> Da remoto</label>
          <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="on_call" <?=((int)$edit_rep['on_call'])?'checked':''?>> Reperibilità</label>
        </div>
        <div class="form-group" style="padding-top:20px;color:var(--muted);font-size:11px">
          In orario di lavoro: <strong><?=((int)$edit_rep['in_working_hours'])?'Sì':'No'?></strong> (derivato da inizio intervento)
        </div>

        <div class="form-group" style="grid-column:1/-1"><label>Richiesta intervento</label>
          <textarea name="request_text" rows="2"><?=h($edit_rep['request_text'])?></textarea></div>
        <div class="form-group" style="grid-column:1/-1"><label>Lavoro eseguito</label>
          <textarea name="work_done" rows="4"><?=h($edit_rep['work_done'])?></textarea></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button class="btn btn-success"><i class="fa-solid fa-floppy-disk"></i> Salva rapporto</button>
        <a class="btn" href="<?=url_safe('project_dashboard',['id'=>$pid])?>">Annulla</a>
        <span style="color:var(--muted);font-size:11px;align-self:center">Al salvataggio i valori calcolati da fascia×ore×regime vengono ricalcolati. I valori importati restano la fonte di verità.</span>
      </div>
    </form>
  </div>
  <script>
  (function(){
    var c=document.getElementById('rep_client'), l=document.getElementById('rep_loc');
    if(!c||!l) return;
    c.addEventListener('change', function(){
      l.innerHTML='<option value="">—</option>';
      if(!c.value) return;
      fetch('<?=url_safe('ajax_client_locations')?>' + (('<?=url_safe('ajax_client_locations')?>'.indexOf('?')>-1)?'&':'?') + 'client_id=' + encodeURIComponent(c.value))
        .then(r=>r.json()).then(function(rows){
          rows.forEach(function(x){ var o=document.createElement('option'); o.value=x.id; o.textContent=x.location_name; l.appendChild(o); });
        }).catch(function(){});
    });
  })();
  </script>
<?php endif; ?>

  <div class="card">
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:12px;flex-wrap:wrap">
      <?php // v1.8.98 — conserva lo slug opaco della pagina.
            //
            // Senza, premendo "Cerca" l'URL perdeva il riferimento alla pagina e
            // il router rispondeva "pagina non trovata": il form portava `id` e
            // `q` ma non l'indicazione di DOVE andare.
            //
            // E' lo stesso difetto della v1.8.85 su service_desk.php. Il
            // controllo introdotto allora verificava i metodi statici
            // inesistenti, non i form GET privi dello slug: due modi diversi di
            // rompere il routing, e il primo controllo non vedeva il secondo. ?>
      <?= route_slug_field() ?>
      <input type="hidden" name="id" value="<?=$pid?>">
      <div class="form-group" style="margin:0"><label>Cerca</label>
        <input type="text" name="q" value="<?=h($rp_filter['q'])?>" placeholder="rapporto, ticket, tecnico, lavoro…" style="width:240px"></div>
      <div class="form-group" style="margin:0"><label>Approvato</label>
        <select name="appr"><option value="">tutti</option>
          <option value="1" <?=$rp_filter['approved']==='1'?'selected':''?>>sì</option>
          <option value="0" <?=$rp_filter['approved']==='0'?'selected':''?>>no</option></select></div>
      <div class="form-group" style="margin:0"><label>Reperibilità</label>
        <select name="rep"><option value="">tutti</option>
          <option value="1" <?=$rp_filter['on_call']==='1'?'selected':''?>>sì</option>
          <option value="0" <?=$rp_filter['on_call']==='0'?'selected':''?>>no</option></select></div>
      <button class="btn">Filtra</button>
      <span style="color:var(--muted);font-size:12px;align-self:center"><strong><?=$rp['total']?></strong> rapporti — pagina <?=$rp_page?>/<?=$rp_pages?></span>
    </form>

    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr>
        <th style="width:26px"></th><th>Rapporto / Codice DGB</th><th>Data</th><th>Tecnico</th><th>Fascia</th><th>Ore</th>
        <th>Ricavo</th><th>Costo</th><th>Appr.</th><th>Rem.</th><th>Rep.</th><th>Orario</th><?php if($can_edit):?><th></th><?php endif;?>
      </tr></thead>
      <tbody>
      <?php if(!$rp['rows']): ?><tr><td colspan="13" style="text-align:center;color:var(--muted);padding:16px">Nessun rapporto per i filtri impostati.</td></tr>
      <?php else: foreach ($rp['rows'] as $ir): $rid=(int)$ir['id']; ?>
        <tr>
          <td><button type="button" class="btn btn-sm rep-toggle" data-t="<?=$rid?>" title="Dettaglio completo"><i class="fa-solid fa-chevron-down"></i></button></td>
          <td><?php
            // v1.8.48: il codice rapporto e il codice attivita DGB coincidono.
            // Dove la riconciliazione ha agganciato l'attivita, il codice diventa
            // un collegamento diretto ad Attivita & Rendicontazione, filtrata su
            // quel codice: si passa dal consuntivo al dettaglio DGB senza cercarlo.
            $dgbCode = trim((string)($ir['dgb_activity_code'] ?? ''));
            $dgbId   = (int)($ir['dgb_activity_id'] ?? 0);
            if ($dgbCode !== '' && $dgbId): ?>
              <a href="<?=url_safe('dgb_activities', ['q' => $dgbCode])?>"
                 title="Apri l'attività DGB <?=h($dgbCode)?> (id <?=$dgbId?>)">
                <code><?=h($ir['report_code'])?></code>
                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:9px;color:#0891b2"></i>
              </a>
            <?php else: ?>
              <code><?=h($ir['report_code'])?></code>
              <span title="Nessuna attività DGB corrispondente" style="color:#cbd5e1;font-size:10px">—</span>
            <?php endif; ?></td>
          <td><?=h($ir['report_date'] ?? '—')?></td>
          <td><?=h(trim(($ir['last_name']??'').' '.($ir['first_name']??'')) ?: '⚠ '.($ir['technician_raw']??'—'))?></td>
          <td><?=h($ir['band_name'] ?? ($ir['band_raw'] ? '⚠ '.$ir['band_raw'] : '—'))?></td>
          <td style="text-align:right"><?=h($ir['quantity_hours'])?></td>
          <td style="text-align:right"><?=$eur($ir['client_revenue_import'])?></td>
          <td style="text-align:right"><?=$eur($ir['company_cost_import'])?></td>
          <td><?=((int)$ir['approved'])?'✔':'—'?></td>
          <td><?=((int)$ir['remote'])?'✔':'—'?></td>
          <td><?=((int)$ir['on_call'])?'✔':'—'?></td>
          <td><?=((int)$ir['in_working_hours'])?'✔':'—'?></td>
          <?php if($can_edit):?><td><a class="btn btn-sm btn-blue" href="<?=url_safe('project_dashboard',['id'=>$pid,'edit_report'=>$rid,'rp'=>$rp_page])?>"><i class="fa-solid fa-pen"></i></a></td><?php endif;?>
        </tr>
        <tr class="rep-detail" id="rep-<?=$rid?>" style="display:none;background:#f8fafc">
          <td colspan="13">
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px 18px;padding:8px 4px">
              <div><small style="color:var(--muted)">Cliente</small><br><?=h($ir['client_name'] ?? $ir['client_raw'] ?? '—')?></div>
              <div><small style="color:var(--muted)">Sede</small><br><?=h($ir['location_name'] ?? $ir['site_raw'] ?? '—')?></div>
              <div><small style="color:var(--muted)">Riferimento cliente</small><br><?=h($ir['client_reference'] ?? '—')?></div>
              <div><small style="color:var(--muted)">Ticket</small><br><?=h($ir['ticket'] ?? '—')?></div>
              <div><small style="color:var(--muted)">Tipo servizio</small><br><?=h($ir['service_type'] ?? '—')?></div>
              <div><small style="color:var(--muted)">Settore tecnologico</small><br><?=h($ir['tech_sector'] ?? '—')?></div>
              <div><small style="color:var(--muted)">Inizio → Fine</small><br><?=h($ir['start_at'] ?? '—')?> → <?=h($ir['end_at'] ?? '—')?></div>
              <div><small style="color:var(--muted)">Ore pian./qtà/diff./extra</small><br><?=h($ir['planned_hours'])?> / <strong><?=h($ir['quantity_hours'])?></strong> / <?=h($ir['diff_hours'])?> / <?=h($ir['extra_hours'])?></div>
              <div><small style="color:var(--muted)">Ricavo importato / calcolato</small><br><?=$eur($ir['client_revenue_import'])?> / <span style="color:var(--muted)"><?=$eur($ir['client_revenue_calc'])?></span></div>
              <div><small style="color:var(--muted)">Costo importato / calcolato</small><br><?=$eur($ir['company_cost_import'])?> / <span style="color:var(--muted)"><?=$eur($ir['company_cost_calc'])?></span></div>
              <div><small style="color:var(--muted)">Costo commerciale calcolato</small><br><?=$eur($ir['commercial_cost_calc'])?></div>
              <div><small style="color:var(--muted)">Batch import</small><br>#<?=(int)$ir['import_batch_id']?> — <?=h($ir['imported_at'] ?? '—')?></div>
              <div style="grid-column:1/-1"><small style="color:var(--muted)">Richiesta intervento</small><br><?=nl2br(h($ir['request_text'] ?? '—'))?></div>
              <div style="grid-column:1/-1"><small style="color:var(--muted)">Lavoro eseguito</small><br><?=nl2br(h($ir['work_done'] ?? '—'))?></div>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php if($rp_pages > 1): $qs = fn($p) => url_safe('project_dashboard', array_filter(['id'=>$pid,'rp'=>$p,'q'=>$rp_filter['q'],'appr'=>$rp_filter['approved'],'rep'=>$rp_filter['on_call']], fn($v)=>$v!=='')); ?>
    <div style="display:flex;gap:6px;justify-content:center;margin-top:12px;align-items:center">
      <?php if($rp_page>1):?><a class="btn btn-sm" href="<?=$qs(1)?>">« prima</a><a class="btn btn-sm" href="<?=$qs($rp_page-1)?>">‹</a><?php endif;?>
      <span style="color:var(--muted);font-size:12px">pagina <?=$rp_page?> di <?=$rp_pages?></span>
      <?php if($rp_page<$rp_pages):?><a class="btn btn-sm" href="<?=$qs($rp_page+1)?>">›</a><a class="btn btn-sm" href="<?=$qs($rp_pages)?>">ultima »</a><?php endif;?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div id="tab-gantt" class="tab-pane" style="display:none">
  <?php
    // scala temporale su fasi + pianificato commessa + effettivo
    $g_dates = [$p['start_date'] ?? null, $p['end_date'] ?? null];
    if ($gact) { $g_dates[] = $gact['dal']; $g_dates[] = $gact['al']; }
    foreach ($gph as $ph) { $g_dates[] = $ph['start_date']; $g_dates[] = $ph['end_date']; }
    foreach ($gtech as $t) { $g_dates[] = $t['dal']; $g_dates[] = $t['al']; }
    [$gmin, $gmax] = Gantt::scale($g_dates);
    $g_trackMin = Gantt::timelineMinWidth($gmin, $gmax);
    $gticks   = Gantt::ticks($gmin, $gmax, Gantt::tickBudget($g_trackMin));
    $ggrid    = Gantt::monthGridlines($gmin, $gmax);
    $gtoday   = Gantt::bar(date('Y-m-d'), date('Y-m-d'), $gmin, $gmax);
    $bar_plan = Gantt::bar($p['start_date'] ?? null, $p['end_date'] ?? null, $gmin, $gmax);
    $bar_act  = $gact ? Gantt::bar($gact['dal'], $gact['al'], $gmin, $gmax) : null;
    echo Gantt::css();
  ?>
  <div class="card" style="margin-bottom:12px">
    <div class="pm-gantt" style="--label:230px">
      <div class="g-legend">
        <span class="k"><span class="sw" style="background:#c7d2fe"></span> pianificato</span>
        <span class="k"><span class="sw" style="background:#2563eb"></span> effettivo (rapporti)</span>
        <span class="k"><span class="sw" style="background:#16a34a"></span> fase (avanzamento)</span>
        <span class="k"><span class="sw" style="background:#93c5fd"></span> attività risorsa</span>
        <span class="k"><span class="sw" style="width:2px;background:#dc2626"></span> oggi</span>
      </div>
      <div class="g-scroll">
        <div class="g-inner" style="min-width:calc(230px + <?=$g_trackMin?>px)">
          <div class="g-gridlayer">
            <?php foreach($ggrid as $gx): ?><div class="g-grid" style="left:<?=$gx?>%"></div><?php endforeach; ?>
            <?php if($gtoday):?><div class="g-today" style="left:<?=$gtoday['left']?>%"></div><?php endif;?>
          </div>
          <!-- testata -->
          <div class="g-row g-head">
            <div class="g-label" style="font-weight:700">Elemento</div>
            <div class="g-track">
              <?php foreach($gticks as $t):?><div class="g-tick" style="left:<?=$t['left']?>%"><?=h($t['label'])?></div><?php endforeach;?>
            </div>
          </div>
          <!-- commessa: piano vs effettivo -->
          <div class="g-row">
            <div class="g-label"><span class="code">Commessa</span><div class="sub">piano vs effettivo</div></div>
            <div class="g-track" style="min-height:44px">
              <?php if($bar_plan):?><div class="g-bar g-plan" title="Pianificato: <?=h($p['start_date']??'—')?> → <?=h($p['end_date']??'—')?>" style="left:<?=$bar_plan['left']?>%;width:<?=$bar_plan['width']?>%;top:8px;height:13px"></div><?php endif;?>
              <?php if($bar_act):?><div class="g-bar g-act" title="Effettivo: <?=h($gact['dal'])?> → <?=h($gact['al'])?> (<?=h($gact['ore'])?> h)" style="left:<?=$bar_act['left']?>%;width:<?=$bar_act['width']?>%;top:24px;height:13px"></div><?php endif;?>
              <?php if(!$bar_plan && !$bar_act):?><span class="g-empty">nessuna data — imposta start/end in Anagrafica o aggiungi fasi</span><?php endif;?>
            </div>
          </div>
          <!-- fasi -->
          <?php foreach($gph as $ph): $bp=Gantt::bar($ph['start_date'],$ph['end_date'],$gmin,$gmax); $pc=max(0,min(100,(int)$ph['progress_pct'])); ?>
          <div class="g-row">
            <div class="g-label">
              <?=h($ph['name'])?> <small style="color:#64748b">· <?=$pc?>%</small>
              <?php if($can_edit):?>
                <a href="<?=url_safe('project_dashboard',['id'=>$pid,'tab'=>'gantt','edit_phase'=>(int)$ph['id']])?>" style="margin-left:4px;color:#2563eb" title="Modifica"><i class="fa-solid fa-pen" style="font-size:10px"></i></a>
                <form method="post" style="display:inline" onsubmit="return confirm('Eliminare la fase?')">
                  <?= csrf_field() ?><input type="hidden" name="action" value="del_phase"><input type="hidden" name="phase_id" value="<?=(int)$ph['id']?>">
                  <button class="btn-link" style="border:0;background:none;color:#dc2626;cursor:pointer;font-size:10px"><i class="fa-solid fa-trash"></i></button></form>
              <?php endif;?>
            </div>
            <div class="g-track" style="min-height:30px">
              <?php if($bp):?>
                <div class="g-bar g-phase" title="<?=h($ph['name'])?>: <?=h($ph['start_date'])?> → <?=h($ph['end_date'])?> (<?=$pc?>%)" style="left:<?=$bp['left']?>%;width:<?=$bp['width']?>%;top:8px;height:14px"><i style="width:<?=$pc?>%"></i></div>
                <span class="g-lbl" style="left:calc(<?=$bp['left']?>% + <?=$bp['width']?>%);top:9px;margin-left:6px"><?=$pc?>%</span>
              <?php else:?><span class="g-empty">senza date</span><?php endif;?>
            </div>
          </div>
          <?php endforeach; ?>
          <!-- attività per risorsa -->
          <?php if($gtech): ?>
          <div class="g-row g-sub">
            <div class="g-label">Attività per risorsa <small style="font-weight:400;color:#94a3b8">(da rapporti)</small></div>
            <div class="g-track" style="min-height:26px"></div>
          </div>
          <?php foreach($gtech as $t): $bt=Gantt::bar($t['dal'],$t['al'],$gmin,$gmax); ?>
          <div class="g-row">
            <div class="g-label"><?=h($t['nome'])?><div class="sub"><?=h($t['ore'])?> h · <?=(int)$t['n']?> rapp.</div></div>
            <div class="g-track" style="min-height:26px">
              <?php if($bt):?><div class="g-bar g-tech" title="<?=h($t['dal'])?> → <?=h($t['al'])?>" style="left:<?=$bt['left']?>%;width:<?=$bt['width']?>%;top:8px;height:11px"></div><?php endif;?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          <?php if($wf_gantt_steps): $WFCOL=['da_fare'=>'#94a3b8','in_corso'=>'#2563eb','completato'=>'#16a34a','bloccato'=>'#dc2626']; ?>
          <div class="g-row g-sub">
            <div class="g-label">Workflow <small style="font-weight:400;color:#94a3b8">(scadenze step)</small></div>
            <div class="g-track" style="min-height:30px">
              <?php foreach($wf_gantt_steps as $ws): $wb=Gantt::bar($ws['due_date'],$ws['due_date'],$gmin,$gmax); if(!$wb) continue; $wc=$WFCOL[$ws['status']]??'#94a3b8'; ?>
                <div title="<?=h($ws['name'])?> — scadenza <?=h(date('d/m/Y',strtotime($ws['due_date'])))?> (<?=h(ProjectWorkflow::STATUS[$ws['status']]??$ws['status'])?>)<?=$ws['is_gate']?' · GATE':''?>"
                     style="position:absolute;left:<?=$wb['left']?>%;top:8px;width:13px;height:13px;background:<?=$wc?>;transform:translateX(-50%) rotate(45deg);border:1px solid #fff;border-radius:2px;z-index:1;<?=$ws['is_gate']?'box-shadow:0 0 0 2px #b45309':''?>"></div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if($gload): $maxl=max(array_map(fn($x)=>(float)$x['ore'],$gload)); ?>
  <div class="card" style="margin-bottom:12px">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-column"></i> Carico mensile (ore da rapporti)</span></div>
    <div class="pm-hist">
      <?php foreach($gload as $l): $hh=$maxl>0?round((float)$l['ore']/$maxl*100):0; ?>
        <div class="col" title="<?=h($l['mese'])?>: <?=h($l['ore'])?> h">
          <span class="v"><?=round((float)$l['ore'])?></span>
          <div class="b" style="height:<?=$hh?>%"></div>
          <span class="m"><?=h(substr($l['mese'],2))?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
    $ep = null;
    if ($can_edit && $g_edit_phase) {
        foreach ($gph as $x) { if ((int)$x['id'] === $g_edit_phase) { $ep = $x; break; } }
    }
    if ($can_edit):
  ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-<?=$ep?'pen-to-square':'plus'?>"></i> <?=$ep?'Modifica fase':'Nuova fase'?></span></div>
    <form method="post" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?=$ep?'update_phase':'add_phase'?>">
      <?php if($ep):?><input type="hidden" name="phase_id" value="<?=(int)$ep['id']?>"><?php endif;?>
      <div class="form-group"><label>Nome fase *</label><input type="text" name="name" value="<?=h($ep['name'] ?? '')?>" required></div>
      <div class="form-group"><label>Inizio</label><input type="date" name="start_date" value="<?=h($ep['start_date'] ?? '')?>"></div>
      <div class="form-group"><label>Fine</label><input type="date" name="end_date" value="<?=h($ep['end_date'] ?? '')?>"></div>
      <div class="form-group"><label>Avanz. %</label><input type="number" min="0" max="100" name="progress_pct" value="<?=(int)($ep['progress_pct'] ?? 0)?>"></div>
      <div class="form-group"><label>Ordine</label><input type="number" name="sort_order" value="<?=(int)($ep['sort_order'] ?? 0)?>"></div>
      <div class="form-group" style="grid-column:1/-1"><label>Note</label><input type="text" name="notes" value="<?=h($ep['notes'] ?? '')?>"></div>
      <div style="grid-column:1/-1;display:flex;gap:8px">
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> <?=$ep?'Salva fase':'Aggiungi fase'?></button>
        <?php if($ep):?><a class="btn" href="<?=url_safe('project_dashboard',['id'=>$pid,'tab'=>'gantt'])?>">Annulla</a><?php endif;?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php
/* ── v1.8.5: Report & Avanzamento + Workflow ─────────────────────────────── */
$KINDCOL = ['nota'=>'#475569','avanzamento'=>'#2563eb','rischio'=>'#dc2626','decisione'=>'#0891b2','milestone'=>'#7c3aed'];
$STCOL   = ['da_fare'=>'#94a3b8','in_corso'=>'#2563eb','completato'=>'#16a34a','bloccato'=>'#dc2626'];
$stars   = function($n){ $n=(int)$n; return $n? str_repeat('★',$n).str_repeat('☆',5-$n) : '—'; };
$fdate   = fn($d)=> $d ? date('d/m/Y', strtotime($d)) : '—';
$fsize   = fn($b)=> $b>=1048576 ? round($b/1048576,1).' MB' : ($b>=1024 ? round($b/1024).' KB' : $b.' B');
?>
<div id="tab-report" class="tab-pane" style="display:none">

  <!-- Workflow -->
  <div class="card" style="margin-bottom:14px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <span class="card-title"><i class="fa-solid fa-diagram-project"></i> Workflow di commessa</span>
      <span style="font-size:12px;color:var(--muted)">
        <?=$wsummary['count']?> step · avanzamento medio <strong><?=$wsummary['avg']?>%</strong>
        <?php foreach(ProjectWorkflow::STATUS as $k=>$lbl): if(($wsummary['by_status'][$k]??0)>0): ?>
          · <span style="color:<?=$STCOL[$k]?>;font-weight:600"><?=$wsummary['by_status'][$k]?> <?=h($lbl)?></span>
        <?php endif; endforeach; ?>
      </span>
    </div>
    <p style="font-size:11px;color:var(--muted);margin:0 0 10px"><i class="fa-solid fa-link"></i> Gli step collegati a una fase aggiornano automaticamente l'avanzamento della fase mostrato nel <strong>Gantt</strong>.</p>
    <div style="overflow-x:auto">
      <table class="data-table" style="width:100%;font-size:12px;white-space:nowrap">
        <thead><tr><th>#</th><th>Step</th><th>Fase (Gantt)</th><th>Responsabile</th><th>Scadenza</th><th>Avanz.</th><th>Stato</th><th></th></tr></thead>
        <tbody>
        <?php if(!$wsteps): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:14px">Nessuno step. Aggiungine uno qui sotto per costruire il workflow.</td></tr>
        <?php else: foreach($wsteps as $s): ?>
          <tr<?=($wf_edit_step && (int)$wf_edit_step['id']===(int)$s['id'])?' style="background:#eff6ff"':''?>>
            <td style="color:var(--muted)"><?=(int)$s['sort_order']?></td>
            <td><strong><?=h($s['name'])?></strong><?php if($s['is_gate']):?> <span title="Gate" style="color:#b45309"><i class="fa-solid fa-flag-checkered"></i></span><?php endif;?>
              <?php if($s['description']):?><div style="color:var(--muted);font-size:10px;white-space:normal"><?=h(mb_strimwidth($s['description'],0,80,'…'))?></div><?php endif;?></td>
            <td><?=$s['phase_name']?'<i class="fa-solid fa-link" style="color:#16a34a;font-size:10px"></i> '.h($s['phase_name']):'<span style="color:#cbd5e1">—</span>'?></td>
            <td><?=h(trim($s['assignee_name']) ?: '—')?></td>
            <td><?=$fdate($s['due_date'])?></td>
            <td style="text-align:right"><?=(int)$s['progress_pct']?>%</td>
            <td>
              <?php if($can_edit): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="set_wstep_status"><input type="hidden" name="step_id" value="<?=(int)$s['id']?>">
                <select name="status" onchange="this.form.submit()" style="font-size:11px;font-weight:600;color:<?=$STCOL[$s['status']]?>">
                  <?php foreach(ProjectWorkflow::STATUS as $k=>$lbl):?><option value="<?=$k?>" <?=$s['status']===$k?'selected':''?>><?=h($lbl)?></option><?php endforeach;?>
                </select>
              </form>
              <?php else: ?><span style="color:<?=$STCOL[$s['status']]?>;font-weight:600"><?=h(ProjectWorkflow::STATUS[$s['status']])?></span><?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <?php if($can_edit): ?>
                <a class="btn btn-sm" href="<?=url_safe('project_dashboard',['id'=>$pid,'tab'=>'report','edit_wstep'=>(int)$s['id']])?>" title="Modifica"><i class="fa-solid fa-pen"></i></a>
                <form method="post" style="display:inline" onsubmit="return confirm('Eliminare lo step?')"><?= csrf_field() ?><input type="hidden" name="action" value="del_wstep"><input type="hidden" name="step_id" value="<?=(int)$s['id']?>"><button class="btn btn-sm" style="background:#fee2e2;color:#991b1b"><i class="fa-solid fa-trash"></i></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($can_edit): $es=$wf_edit_step; ?>
    <form method="post" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px;border-top:1px solid #f1f5f9;padding-top:12px">
      <?= csrf_field() ?><input type="hidden" name="action" value="<?=$es?'update_wstep':'add_wstep'?>">
      <?php if($es):?><input type="hidden" name="step_id" value="<?=(int)$es['id']?>"><?php endif;?>
      <div class="form-group" style="margin:0;grid-column:span 2"><label><?=$es?'Modifica step':'Nuovo step'?> — nome *</label><input type="text" name="name" value="<?=h($es['name']??'')?>" required></div>
      <div class="form-group" style="margin:0"><label>Fase (Gantt)</label>
        <select name="phase_id"><option value="">— nessuna —</option>
          <?php foreach($gph as $ph):?><option value="<?=(int)$ph['id']?>" <?=($es && (int)$es['phase_id']===(int)$ph['id'])?'selected':''?>><?=h($ph['name'])?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0"><label>Responsabile</label>
        <select name="assignee_employee_id"><option value="">—</option>
          <?php foreach($employees as $e):?><option value="<?=(int)$e['id']?>" <?=($es && (int)$es['assignee_employee_id']===(int)$e['id'])?'selected':''?>><?=h($e['last_name'].' '.$e['first_name'])?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0"><label>Stato</label>
        <select name="status"><?php foreach(ProjectWorkflow::STATUS as $k=>$lbl):?><option value="<?=$k?>" <?=(($es['status']??'da_fare')===$k)?'selected':''?>><?=h($lbl)?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0"><label>Inizio</label><input type="date" name="start_date" value="<?=h($es['start_date']??'')?>"></div>
      <div class="form-group" style="margin:0"><label>Scadenza</label><input type="date" name="due_date" value="<?=h($es['due_date']??'')?>"></div>
      <div class="form-group" style="margin:0"><label>Avanz. %</label><input type="number" min="0" max="100" name="progress_pct" value="<?=(int)($es['progress_pct']??0)?>"></div>
      <div class="form-group" style="margin:0"><label>Ordine</label><input type="number" name="sort_order" value="<?=(int)($es['sort_order']??0)?>"></div>
      <div class="form-group" style="margin:0"><label>Gate (blocca)</label><select name="is_gate"><option value="0" <?=empty($es['is_gate'])?'selected':''?>>No</option><option value="1" <?=!empty($es['is_gate'])?'selected':''?>>Sì</option></select></div>
      <div class="form-group" style="margin:0;grid-column:span 2"><label>Descrizione</label><input type="text" name="description" value="<?=h($es['description']??'')?>" maxlength="500"></div>
      <div style="grid-column:1/-1;display:flex;gap:8px"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> <?=$es?'Salva step':'Aggiungi step'?></button>
        <?php if($es):?><a class="btn" href="<?=url_safe('project_dashboard',['id'=>$pid,'tab'=>'report'])?>">Annulla</a><?php endif;?></div>
    </form>
    <?php endif; ?>
  </div>

  <!-- Report / Note di avanzamento -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-list"></i> Report &amp; note di avanzamento</span></div>

    <?php if($can_edit): $eu=$wf_edit_upd; ?>
    <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px">
      <?= csrf_field() ?><input type="hidden" name="action" value="<?=$eu?'update_update':'add_update'?>">
      <?php if($eu):?><input type="hidden" name="update_id" value="<?=(int)$eu['id']?>"><?php endif;?>
      <div class="form-group" style="margin:0"><label>Data</label><input type="date" name="update_date" value="<?=h($eu['update_date']??date('Y-m-d'))?>"></div>
      <div class="form-group" style="margin:0"><label>Tipo</label>
        <select name="kind"><?php foreach(ProjectWorkflow::KINDS as $k=>$lbl):?><option value="<?=$k?>" <?=(($eu['kind']??'nota')===$k)?'selected':''?>><?=h($lbl)?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0"><label>Valutazione</label>
        <select name="rating"><option value="">—</option><?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>" <?=((int)($eu['rating']??0)===$i)?'selected':''?>><?=str_repeat('★',$i)?> (<?=$i?>)</option><?php endfor;?></select></div>
      <div class="form-group" style="margin:0"><label>Avanzamento %</label><input type="number" min="0" max="100" name="progress_pct" value="<?=h($eu['progress_pct']??'')?>" placeholder="—"></div>
      <div class="form-group" style="margin:0;grid-column:span 2"><label>Titolo</label><input type="text" name="title" value="<?=h($eu['title']??'')?>" maxlength="180"></div>
      <div class="form-group" style="margin:0"><label>Fase collegata (Gantt)</label>
        <select name="phase_id"><option value="">—</option><?php foreach($gph as $ph):?><option value="<?=(int)$ph['id']?>" <?=($eu && (int)$eu['phase_id']===(int)$ph['id'])?'selected':''?>><?=h($ph['name'])?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0"><label>Step collegato</label>
        <select name="step_id"><option value="">—</option><?php foreach($wsteps as $s):?><option value="<?=(int)$s['id']?>" <?=($eu && (int)$eu['step_id']===(int)$s['id'])?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></div>
      <div class="form-group" style="margin:0;grid-column:1/-1"><label>Nota / testo</label><textarea name="body" rows="3" style="width:100%"><?=h($eu['body']??'')?></textarea></div>
      <div class="form-group" style="margin:0;grid-column:span 3"><label>Allegati <small style="color:var(--muted)">(più file; <?=h(UploadGuard::limitsNote())?>)</small></label>
        <input type="file" name="attachments[]" multiple></div>
      <div style="grid-column:1/-1;display:flex;gap:8px"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?=$eu?'Salva report':'Aggiungi report'?></button>
        <?php if($eu):?><a class="btn" href="<?=url_safe('project_dashboard',['id'=>$pid,'tab'=>'report'])?>">Annulla</a><?php endif;?></div>
    </form>
    <?php endif; ?>

    <?php if(!$updates): ?>
      <p style="text-align:center;color:var(--muted);padding:16px">Nessun report registrato per questa commessa.</p>
    <?php else: foreach($updates as $u): $fs=$upFiles[(int)$u['id']]??[]; ?>
      <div style="border:1px solid #e2e8f0;border-left:3px solid <?=$KINDCOL[$u['kind']]??'#475569'?>;border-radius:8px;padding:10px 12px;margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;flex-wrap:wrap">
          <div>
            <span style="background:<?=$KINDCOL[$u['kind']]??'#475569'?>;color:#fff;font-size:10px;font-weight:700;padding:1px 8px;border-radius:10px"><?=h(ProjectWorkflow::KINDS[$u['kind']]??$u['kind'])?></span>
            <strong style="margin-left:6px"><?=$fdate($u['update_date'])?></strong>
            <?php if($u['title']):?> · <?=h($u['title'])?><?php endif;?>
            <?php if($u['rating']):?> · <span style="color:#f59e0b" title="Valutazione <?=$u['rating']?>/5"><?=$stars($u['rating'])?></span><?php endif;?>
            <?php if($u['progress_pct']!==null):?> · <span style="color:#2563eb;font-weight:600"><?=(int)$u['progress_pct']?>%</span><?php endif;?>
          </div>
          <div style="font-size:11px;color:var(--muted)">
            <?php if($u['phase_name']):?><span title="Fase"><i class="fa-solid fa-link"></i> <?=h($u['phase_name'])?></span><?php endif;?>
            <?php if($u['step_name']):?> · <span title="Step"><i class="fa-solid fa-diagram-project"></i> <?=h($u['step_name'])?></span><?php endif;?>
            <?php if($u['author']):?> · <?=h($u['author'])?><?php endif;?>
            <?php if($can_edit): ?>
              · <a href="<?=url_safe('project_dashboard',['id'=>$pid,'tab'=>'report','edit_update'=>(int)$u['id']])?>" title="Modifica"><i class="fa-solid fa-pen"></i></a>
              <form method="post" style="display:inline" onsubmit="return confirm('Eliminare il report?')"><?= csrf_field() ?><input type="hidden" name="action" value="del_update"><input type="hidden" name="update_id" value="<?=(int)$u['id']?>"><button class="btn-link" style="border:0;background:none;color:#dc2626;cursor:pointer"><i class="fa-solid fa-trash"></i></button></form>
            <?php endif; ?>
          </div>
        </div>
        <?php if($u['body']):?><div style="margin-top:6px;white-space:pre-wrap;font-size:13px"><?=nl2br(h($u['body']))?></div><?php endif;?>
        <?php if($fs): ?>
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach($fs as $file): ?>
              <span style="display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;border-radius:6px;padding:3px 8px;font-size:11px">
                <i class="fa-solid fa-paperclip"></i>
                <a href="<?=url_safe('project_dashboard',['id'=>$pid,'dl_upfile'=>(int)$file['id']])?>"><?=h(mb_strimwidth($file['original_name'],0,36,'…'))?></a>
                <span style="color:var(--muted)"><?=$fsize((int)$file['size_bytes'])?></span>
                <?php if($can_edit): ?><form method="post" style="display:inline" onsubmit="return confirm('Rimuovere l\'allegato?')"><?= csrf_field() ?><input type="hidden" name="action" value="del_upfile"><input type="hidden" name="file_id" value="<?=(int)$file['id']?>"><button class="btn-link" style="border:0;background:none;color:#dc2626;cursor:pointer;font-size:10px"><i class="fa-solid fa-xmark"></i></button></form><?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<div id="tab-dgb" class="tab-pane" style="display:none">
  <?php if(!$dgb_cid): ?>
    <div class="card"><p style="color:var(--muted);margin:0">Nessun contratto DogoBit collegato a questa commessa. Il collegamento è dedotto da <code>external_link</code> (formato <code>.../contract/editV2/&lt;id&gt;</code>).</p></div>
  <?php elseif(!$dgb_roll): ?>
    <div class="card"><p style="color:var(--muted);margin:0">Contratto DogoBit collegato: <strong>#<?=$dgb_cid?></strong>, ma nessuna attività DGB importata per questo contratto.</p></div>
  <?php else: $eur2=fn($v)=>$v!==null?number_format((float)$v,2,',','.'):'—'; ?>
    <div class="card" style="margin-bottom:12px">
      <div class="card-header" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
        <span class="card-title">Rendicontazione DogoBit — contratto #<?=$dgb_cid?></span>
        <a class="btn btn-sm btn-primary" href="<?=url_safe('dgb_activities',['contract'=>$dgb_cid])?>"><i class="fa-solid fa-diagram-project"></i> Apri analisi DGB completa</a>
      </div>
      <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
        <?php
        $dc = [
          ['Attività', number_format((int)$dgb_roll['activities'],0,',','.'), '#0f172a'],
          ['Ore consuntivate', $eur2($dgb_roll['actual_hours']), '#2563eb'],
          ['Straordinario', $eur2($dgb_roll['overtime_hours']), '#f59e0b'],
          ['Trasferta', $eur2($dgb_roll['trip_hours']), '#7c3aed'],
          ['Costo DGB', $eur2($dgb_roll['total_cost']), '#dc2626'],
          ['Ricavo DGB', $eur2($dgb_roll['total_revenue']), '#16a34a'],
        ];
        foreach($dc as [$l,$v,$c]): ?>
          <div style="padding:8px;border:1px solid var(--border);border-radius:6px"><div style="font-size:10px;color:var(--muted);text-transform:uppercase"><?=h($l)?></div>
            <div style="font-size:18px;font-weight:800;color:<?=$c?>"><?=$v?></div></div>
        <?php endforeach; ?>
      </div>
      <p style="font-size:11px;color:var(--muted);margin-top:8px">Incaricati distinti: <strong><?=(int)$dgb_roll['operators']?></strong> · Periodo: <?=h($dgb_roll['first_date'] ?? '—')?> → <?=h($dgb_roll['last_date'] ?? '—')?> · Attività senza piano: <?=(int)$dgb_roll['orphan_activities']?>.
        Confronto: valore commessa <?=$eur($p['value_total'])?> · consuntivo import <?=$eur($p['actual_cost'])?> · costo DGB <?=$eur2($dgb_roll['total_cost'])?>.</p>
    </div>
    <div class="card" style="overflow-x:auto">
      <div class="card-header"><span class="card-title">Attività DGB (ultime 30)</span></div>
      <table class="data-table" style="width:100%;font-size:12px;white-space:nowrap">
        <thead><tr><th>ID</th><th>Codice</th><th>Ticket</th><th>Stato</th><th>Data</th><th style="text-align:right">Ore</th><th style="text-align:right">Costo</th><th style="text-align:right">Ricavo</th></tr></thead>
        <tbody>
        <?php foreach($dgb_acts as $a): ?>
          <tr><td><?=(int)$a['id']?></td><td style="font-weight:600"><?=h($a['code'])?></td>
            <td><?=h(mb_strimwidth((string)$a['ticket'],0,26,'…'))?></td><td><?=h($a['status'])?></td>
            <td><?=h($a['report_date'] ?: substr((string)$a['date_start'],0,10))?></td>
            <td style="text-align:right"><?=$eur2($a['actual_hours'])?></td>
            <td style="text-align:right"><?=$eur2($a['total_cost'])?></td>
            <td style="text-align:right"><?=$eur2($a['total_revenue'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(function(b){
  b.addEventListener('click', function(){
    document.querySelectorAll('.tab-btn').forEach(x=>x.classList.remove('active'));
    b.classList.add('active');
    document.querySelectorAll('.tab-pane').forEach(p=>p.style.display='none');
    document.getElementById('tab-'+b.dataset.tab).style.display='';
  });
});
document.querySelectorAll('.rep-toggle').forEach(function(b){
  b.addEventListener('click', function(){
    var r=document.getElementById('rep-'+b.dataset.t);
    var open = r.style.display==='none';
    r.style.display = open ? '' : 'none';
    b.querySelector('i').style.transform = open ? 'rotate(180deg)' : '';
  });
});
// v1.7.68: apre direttamente il Consuntivo quando si sta modificando un rapporto o si filtra
(function(){
  var params = new URLSearchParams(window.location.search);
  var forced = params.get('tab');
  var goCons = params.has('edit_report') || params.has('rp') || params.has('q') || params.has('appr') || params.has('rep');
  var goGantt = params.has('edit_phase') || forced === 'gantt';
  var goReport = params.has('edit_update') || params.has('edit_wstep') || forced === 'report';
  var tab = goReport ? 'report' : (goGantt ? 'gantt' : (goCons ? 'cons' : (forced || 'anag')));
  if (!document.getElementById('tab-'+tab)) tab = 'anag';
  document.querySelectorAll('.tab-btn').forEach(function(x){ x.classList.toggle('active', x.dataset.tab===tab); });
  document.querySelectorAll('.tab-pane').forEach(function(p){ p.style.display='none'; });
  document.getElementById('tab-'+tab).style.display='';
})();
</script>
<?php require_once('footer.php'); ?>
