<?php
/**
 * prj_dashboard.php — Scheda progetto PRJ (v1.10.01)
 *
 * Analisi Gara & Dimensionamento di un Progetto PRJ, distinto dalle commesse SP.
 * Tab: Anagrafica · Collegamento commessa · Gara · Servizi & Tecnologie · Asset & Volumi · Profili · Costi · Scenari
 *      · KPI & Penali · Punteggio · Storico (v1.10.02).
 *
 * Permessi: view/edit su prj_dashboard.php; calcolo e salvataggio run su prj_dashboard_calc.php (edit);
 * collegamento alla commessa SP su prj_link.php (edit), separato dalla modifica dei dati.
 * Dati versionati: ogni modifica apre una nuova versione con decorrenza (PrjRepo::writeVersion);
 * con la stessa decorrenza della versione vigente è una rettifica. Ogni campo è tracciato in EntityChangeLog.
 * La commessa SP (cm_projects) è solo letta.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/PrjRepo.php');
require_once(__DIR__ . '/app/PrjLink.php');
require_once(__DIR__ . '/app/PrjUi.php');
require_once(__DIR__ . '/app/EntityChangeLog.php');
require_once(__DIR__ . '/app/RecycleBin.php');
require_once(__DIR__ . '/app/PmCharts.php');
require_once(__DIR__ . '/app/PrjActuals.php');

$u_id = (int)$_SESSION['user_id'];
$id   = (int)($_GET['id'] ?? 0);
$repo = new PrjRepo($pdo);
$lnk  = new PrjLink($pdo);
$st = $pdo->prepare("SELECT * FROM cm_prj WHERE id = ?");
$st->execute([$id]);
$prj = $st->fetch(PDO::FETCH_ASSOC);
if (!$prj) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Progetto PRJ non trovato.</div>"; redirect('manage_projects', ['view' => 'prj']); }

$can_edit = can('edit', 'prj_dashboard.php');
$can_calc = can('edit', 'prj_dashboard_calc.php');
$can_link = can('edit', 'prj_link.php');
$TABS = ['anag' => 'Anagrafica', 'link' => 'Collegamento commessa', 'gara' => 'Gara', 'svc' => 'Servizi & Tecnologie',
         'vol' => 'Asset & Volumi', 'prof' => 'Profili', 'costi' => 'Costi', 'scen' => 'Scenari',
         'kpi' => 'KPI & Penali', 'punt' => 'Punteggio', 'cons' => 'Stimato vs Consuntivo', 'stor' => 'Storico'];   // v1.10.02, cons v1.10.03
$tab = isset($TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'anag';
$dOk = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : null;
$iOk = fn($v) => ctype_digit((string)$v) && (int)$v > 0 ? (int)$v : null;
$ecl = new EntityChangeLog($pdo);

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $a = (string)($_POST['action'] ?? '');
    $back = isset($TABS[$_POST['tab'] ?? '']) ? $_POST['tab'] : $tab;
    $deny = fn() => $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
    try {
        switch ($a) {
        case 'save_anag':
            if (!$can_edit) { $deny(); break; }
            $new = [
                'nome' => trim((string)$_POST['nome']) ?: $prj['nome'],
                'client_id' => $iOk($_POST['client_id'] ?? ''), 'client_raw' => trim((string)($_POST['client_raw'] ?? '')) ?: null,
                'exec_company_id' => $iOk($_POST['exec_company_id'] ?? ''),
                'project_type' => in_array($_POST['project_type'] ?? '', PrjUi::TIPI, true) ? $_POST['project_type'] : null,
                'stato' => in_array($_POST['stato'] ?? '', PrjUi::STATI, true) ? $_POST['stato'] : $prj['stato'],
                'responsabile_user_id' => $iOk($_POST['responsabile_user_id'] ?? ''),
                'codice_gara' => mb_substr(trim((string)($_POST['codice_gara'] ?? '')), 0, 60) ?: null,
                'cig' => mb_substr(trim((string)($_POST['cig'] ?? '')), 0, 20) ?: null,
                'stazione_appaltante' => mb_substr(trim((string)($_POST['stazione_appaltante'] ?? '')), 0, 200) ?: null,
                'data_offerta' => $dOk($_POST['data_offerta'] ?? ''), 'data_aggiudicazione' => $dOk($_POST['data_aggiudicazione'] ?? ''),
                'start_date' => $dOk($_POST['start_date'] ?? ''), 'end_date' => $dOk($_POST['end_date'] ?? ''),
                'note' => trim((string)($_POST['note'] ?? '')) ?: null,
            ];
            $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($new)));
            $pdo->prepare("UPDATE cm_prj SET $set WHERE id = ?")->execute([...array_values($new), $id]);
            $n = $ecl->diffAndLog('cm_prj', $id, $prj, $new, 'update', 'ui', null, $u_id);
            write_log('Commesse', 'info', "PRJ {$prj['prj_code']}: anagrafica aggiornata ($n campi)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Anagrafica salvata ($n campi modificati).</div>";
            break;

        case 'save_presales':
            if (!$can_edit) { $deny(); break; }
            $cc = ['Ufficio Gare', 'Sicurezza', 'Ingegneria/Analisi Tecnica', 'Project Management'];
            $up = $pdo->prepare("INSERT INTO cm_prj_presales (prj_id, cost_center, hours, hourly_rate, notes) VALUES (?,?,?,?,?)
                                 ON DUPLICATE KEY UPDATE hours = VALUES(hours), hourly_rate = VALUES(hourly_rate), notes = VALUES(notes)");
            foreach ((array)($_POST['ps'] ?? []) as $c => $r) {
                if (!in_array($c, $cc, true)) continue;
                $up->execute([$id, $c, (float)(PrjUi::num($r['hours'] ?? '') ?? 0), PrjUi::num($r['rate'] ?? ''), mb_substr(trim((string)($r['notes'] ?? '')), 0, 255) ?: null]);
            }
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Effort di offerta salvato.</div>";
            break;

        case 'vsave':
            if (!$can_edit) { $deny(); break; }
            $r = PrjUi::saveGrid($pdo, $repo, $id, $_POST, $u_id);
            if ($r['saved']) write_log('Commesse', 'info', "PRJ {$prj['prj_code']}: {$r['saved']} righe versionate ($back)", $u_id);
            $_SESSION['flash_msg'] = PrjUi::flash($r);
            break;

        case 'link':
            if (!$can_link) { $deny(); break; }
            $sp = (int)($_POST['sp_project_id'] ?? 0);
            if (!$sp && ($code = trim((string)($_POST['sp_code'] ?? ''))) !== '') {
                $q = $pdo->prepare("SELECT id FROM cm_projects WHERE project_code = ? AND project_code NOT LIKE 'DGB-%'");
                $q->execute([$code]); $sp = (int)$q->fetchColumn();
            }
            if (!$sp) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Commessa SP non trovata: indicare il codice commessa esatto o sceglierla dai suggerimenti.</div>"; break; }
            $lnk->link($id, $sp, trim((string)($_POST['motivo'] ?? '')), $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Progetto collegato alla commessa SP.</div>";
            break;

        case 'unlink':
            if (!$can_link) { $deny(); break; }
            $lnk->unlink($id, trim((string)($_POST['motivo'] ?? '')), $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Collegamento rimosso.</div>";
            break;

        case 'source_add':
            if (!$can_edit) { $deny(); break; }
            $tipo = in_array($_POST['tipo'] ?? '', ['documento_gara', 'mercato', 'stima_interna'], true) ? $_POST['tipo'] : 'documento_gara';
            $cod = mb_substr(trim((string)($_POST['codice'] ?? '')), 0, 40); $tit = mb_substr(trim((string)($_POST['titolo'] ?? '')), 0, 255);
            $url = trim((string)($_POST['url'] ?? ''));
            if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
            if ($cod === '' || $tit === '') { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Codice e titolo della fonte sono obbligatori.</div>"; break; }
            $pdo->prepare("INSERT INTO cm_prj_source (prj_id, tipo, codice, titolo, url, data_riferimento) VALUES (?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE tipo = VALUES(tipo), titolo = VALUES(titolo), url = VALUES(url), data_riferimento = VALUES(data_riferimento)")
                ->execute([$id, $tipo, $cod, $tit, $url ?: null, $dOk($_POST['data_riferimento'] ?? '')]);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Fonte registrata.</div>";
            break;

        case 'tender_add':
            if (!$can_edit) { $deny(); break; }
            $y = (int)($_POST['year'] ?? 0);
            if ($y < 2000 || $y > 2100) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Anno non valido.</div>"; break; }
            $ex = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_tender_base WHERE prj_id = ? AND year = ? AND is_current = 1"); $ex->execute([$id, $y]);
            if ($ex->fetchColumn()) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>L'anno $y è già presente: modificarlo nella tabella.</div>"; break; }
            $repo->insertVersioned('cm_prj_tender_base', ['prj_id' => $id, 'year' => $y, 'canone_eur' => (float)(PrjUi::num($_POST['canone_eur'] ?? '') ?? 0),
                                   'uncommitted_eur' => (float)(PrjUi::num($_POST['uncommitted_eur'] ?? '') ?? 0)], date('Y-m-d'), $u_id, 'nuovo anno');
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Anno $y aggiunto alla base d'asta.</div>";
            break;

        case 'tech_add':
            if (!$can_edit) { $deny(); break; }
            $sid = (int)($_POST['service_id'] ?? 0);
            $ok = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_service WHERE prj_id = ? AND ent_id = ?"); $ok->execute([$id, $sid]);
            $raw = mb_substr(trim((string)($_POST['technology_raw'] ?? '')), 0, 200);
            if (!$ok->fetchColumn() || $raw === '') { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Servizio e tecnologia sono obbligatori.</div>"; break; }
            $tid = $pdo->prepare("SELECT id FROM technologies WHERE name = ? LIMIT 1"); $tid->execute([$raw]);
            $pdo->prepare("INSERT IGNORE INTO cm_prj_service_technology (prj_id, service_id, technology_id, technology_raw, versioni, h24) VALUES (?,?,?,?,?,?)")
                ->execute([$id, $sid, ($tid->fetchColumn() ?: null), $raw, mb_substr(trim((string)($_POST['versioni'] ?? '')), 0, 120) ?: null, !empty($_POST['h24']) ? 1 : 0]);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Tecnologia aggiunta.</div>";
            break;

        case 'tech_del':
            if (!$can_edit) { $deny(); break; }
            RecycleBin::capture($pdo, 'cm_prj_service_technology', 'id = ? AND prj_id = ?', [(int)($_POST['tech_id'] ?? 0), $id], $u_id, 'prj_dashboard.php');
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Tecnologia rimossa (recuperabile dal cestino).</div>";
            break;

        case 'profile_flags':
            if (!$can_edit) { $deny(); break; }
            $sel = $pdo->prepare("SELECT * FROM cm_prj_profile WHERE id = ? AND prj_id = ?");
            $upd = $pdo->prepare("UPDATE cm_prj_profile SET h24 = ?, nearshore_ammesso = ? WHERE id = ?");
            $n = 0;
            foreach ((array)($_POST['pf'] ?? []) as $pid => $fl) {
                $sel->execute([(int)$pid, $id]);
                if (!($old = $sel->fetch(PDO::FETCH_ASSOC))) continue;
                $nv = ['h24' => !empty($fl['h24']) ? 1 : 0, 'nearshore_ammesso' => !empty($fl['ns']) ? 1 : 0];
                if ((int)$old['h24'] === $nv['h24'] && (int)$old['nearshore_ammesso'] === $nv['nearshore_ammesso']) continue;
                $upd->execute([$nv['h24'], $nv['nearshore_ammesso'], (int)$pid]);
                $n += $ecl->diffAndLog('cm_prj_profile', (int)$pid, $old, $nv, 'update', 'ui', null, $u_id);
            }
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Flag dei profili salvati ($n modifiche).</div>";
            break;

        case 'assign_add':
            if (!$can_edit) { $deny(); break; }
            $pfid = (int)($_POST['profile_id'] ?? 0); $emp = $iOk($_POST['employee_id'] ?? ''); $pro = $iOk($_POST['professional_id'] ?? '');
            $ok = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_profile WHERE id = ? AND prj_id = ?"); $ok->execute([$pfid, $id]);
            if (!$ok->fetchColumn() || (bool)$emp === (bool)$pro) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Indicare il profilo e un dipendente oppure un professionista.</div>"; break; }
            $pct = PrjUi::num($_POST['pct'] ?? '100') ?? 100;
            $pdo->prepare("INSERT INTO cm_prj_profile_assignment (prj_id, profile_id, employee_id, professional_id, pct_allocazione, dal, al, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$id, $pfid, $emp, $pro, max(0, min(100, $pct)), $dOk($_POST['dal'] ?? ''), $dOk($_POST['al'] ?? ''), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, $u_id]);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Candidato assegnato al profilo.</div>";
            break;

        case 'assign_del':
            if (!$can_edit) { $deny(); break; }
            RecycleBin::capture($pdo, 'cm_prj_profile_assignment', 'id = ? AND prj_id = ?', [(int)($_POST['assign_id'] ?? 0), $id], $u_id, 'prj_dashboard.php');
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Assegnazione rimossa (recuperabile dal cestino).</div>";
            break;

        case 'scen_save':
            if (!$can_edit) { $deny(); break; }
            $sid = (int)($_POST['scenario_id'] ?? 0);
            $d = [
                'nome' => mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 120),
                'tipo' => in_array($_POST['tipo'] ?? '', ['sostenibile', 'completo', 'custom'], true) ? $_POST['tipo'] : 'custom',
                'zona_id' => $iOk($_POST['zona_id'] ?? ''), 'ral_mode' => in_array($_POST['ral_mode'] ?? '', PrjCalc::RAL_MODES, true) ? $_POST['ral_mode'] : 'media',
                'ribasso_pct' => min(1, max(0, (float)(PrjUi::num($_POST['ribasso_pct'] ?? '0') ?? 0) / 100)),
                'margine_target_pct' => ($m = PrjUi::num($_POST['margine_target_pct'] ?? '')) === null ? null : min(1, $m / 100),
                'nearshore_id' => $iOk($_POST['nearshore_id'] ?? ''), 'nearshore_scope' => $iOk($_POST['nearshore_id'] ?? '') ? 'supporto' : 'nessuno',
                'fte_supporto_sostenibile' => PrjUi::num($_POST['fte_supporto_sostenibile'] ?? ''),
                'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null,
            ];
            if ($d['nome'] === '' || !$d['zona_id']) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Nome e zona dello scenario sono obbligatori.</div>"; break; }
            if ($sid) {
                $o = $pdo->prepare("SELECT * FROM cm_prj_scenario WHERE id = ? AND prj_id = ?"); $o->execute([$sid, $id]);
                if (!($old = $o->fetch(PDO::FETCH_ASSOC))) break;
                $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
                $pdo->prepare("UPDATE cm_prj_scenario SET $set WHERE id = ?")->execute([...array_values($d), $sid]);
                $ecl->diffAndLog('cm_prj_scenario', $sid, $old, $d, 'update', 'ui', null, $u_id);
            } else {
                $d += ['prj_id' => $id, 'created_by' => $u_id];
                $pdo->prepare("INSERT INTO cm_prj_scenario (`" . implode('`,`', array_keys($d)) . "`) VALUES (" . implode(',', array_fill(0, count($d), '?')) . ")")->execute(array_values($d));
                $sid = (int)$pdo->lastInsertId();
                $ecl->diffAndLog('cm_prj_scenario', $sid, [], $d, 'insert', 'ui', null, $u_id);
            }
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Scenario «" . h($d['nome']) . "» salvato.</div>";
            redirect_self(['tab' => 'scen', 'sc' => $sid]);

        case 'scen_clone':
            if (!$can_edit) { $deny(); break; }
            $sid = (int)($_POST['scenario_id'] ?? 0);
            $o = $pdo->prepare("SELECT * FROM cm_prj_scenario WHERE id = ? AND prj_id = ?"); $o->execute([$sid, $id]);
            if (!($s = $o->fetch(PDO::FETCH_ASSOC))) break;
            $pdo->beginTransaction();
            $nome = mb_substr('Copia di ' . $s['nome'], 0, 110); $i = 1;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_scenario WHERE prj_id = ? AND nome = ?");
            while (true) { $chk->execute([$id, $nome . ($i > 1 ? " ($i)" : '')]); if (!$chk->fetchColumn()) break; $i++; }
            $nome .= $i > 1 ? " ($i)" : '';
            unset($s['id'], $s['created_at'], $s['updated_at']);
            $s = array_merge($s, ['nome' => $nome, 'cloned_from_id' => $sid, 'created_by' => $u_id]);
            $pdo->prepare("INSERT INTO cm_prj_scenario (`" . implode('`,`', array_keys($s)) . "`) VALUES (" . implode(',', array_fill(0, count($s), '?')) . ")")->execute(array_values($s));
            $nid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO cm_prj_scenario_profile (prj_id, scenario_id, profile_id, service_id, fte_override, zona_id, nearshore_id, remoto, h24, escluso)
                           SELECT prj_id, ?, profile_id, service_id, fte_override, zona_id, nearshore_id, remoto, h24, escluso FROM cm_prj_scenario_profile WHERE scenario_id = ?")->execute([$nid, $sid]);
            $pdo->commit();
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Scenario clonato: «" . h($nome) . "».</div>";
            redirect_self(['tab' => 'scen', 'sc' => $nid]);

        case 'scen_del':
            if (!$can_edit) { $deny(); break; }
            $sid = (int)($_POST['scenario_id'] ?? 0);
            if ((int)$prj['scenario_riferimento_id'] === $sid) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Lo scenario di riferimento non si può eliminare: impostarne prima un altro.</div>"; break; }
            RecycleBin::capture($pdo, 'cm_prj_scenario', 'id = ? AND prj_id = ?', [$sid, $id], $u_id, 'prj_dashboard.php');
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Scenario eliminato (i calc run già salvati restano consultabili).</div>";
            redirect_self(['tab' => 'scen']);

        case 'scen_ref':
            if (!$can_edit) { $deny(); break; }
            $sid = (int)($_POST['scenario_id'] ?? 0);
            $o = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_scenario WHERE id = ? AND prj_id = ?"); $o->execute([$sid, $id]);
            if (!$o->fetchColumn()) break;
            $pdo->prepare("UPDATE cm_prj SET scenario_riferimento_id = ? WHERE id = ?")->execute([$sid, $id]);
            $ecl->logField('cm_prj', $id, 'scenario_riferimento_id', $prj['scenario_riferimento_id'], $sid, 'update', 'ui', null, $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Scenario di riferimento aggiornato.</div>";
            redirect_self(['tab' => 'scen', 'sc' => $sid]);

        case 'scen_ov':
            if (!$can_edit) { $deny(); break; }
            $sid = (int)($_POST['scenario_id'] ?? 0);
            $o = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_scenario WHERE id = ? AND prj_id = ?"); $o->execute([$sid, $id]);
            if (!$o->fetchColumn()) break;
            $okp = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_profile WHERE id = ? AND prj_id = ?");
            $up = $pdo->prepare("INSERT INTO cm_prj_scenario_profile (prj_id, scenario_id, profile_id, service_id, fte_override, remoto, h24, escluso)
                                 VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE fte_override = VALUES(fte_override), remoto = VALUES(remoto), h24 = VALUES(h24), escluso = VALUES(escluso)");
            $del = $pdo->prepare("DELETE FROM cm_prj_scenario_profile WHERE scenario_id = ? AND profile_id = ? AND service_id = ?");
            $n = 0;
            foreach ((array)($_POST['ov'] ?? []) as $key => $o_) {
                if (!preg_match('/^(\d+):(\d+)$/', (string)$key, $m)) continue;
                $okp->execute([(int)$m[1], $id]); if (!$okp->fetchColumn()) continue;
                $fte = PrjUi::num($o_['fte'] ?? ''); $rem = !empty($o_['remoto']) ? 1 : 0; $esc = !empty($o_['escluso']) ? 1 : 0;
                $h = ($o_['h24'] ?? '') === '' ? null : (int)$o_['h24'];
                if ($fte === null && !$rem && !$esc && $h === null) { $del->execute([$sid, (int)$m[1], (int)$m[2]]); continue; }
                $up->execute([$id, $sid, (int)$m[1], (int)$m[2], $fte, $rem, $h, $esc]); $n++;
            }
            $ecl->logField('cm_prj_scenario', $sid, 'override_profili', null, (string)$n, 'update', 'ui', null, $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Personalizzazioni dello scenario salvate ($n righe).</div>";
            redirect_self(['tab' => 'scen', 'sc' => $sid]);

        case 'score_save':   // v1.10.02 — input del simulatore di punteggio (scenario NULL)
            if (!$can_edit) { $deny(); break; }
            $crit = $pdo->prepare("SELECT ent_id, codice, gruppo FROM cm_prj_criterion WHERE prj_id = ? AND is_current = 1");
            $crit->execute([$id]); $byCode = []; foreach ($crit->fetchAll(PDO::FETCH_ASSOC) as $c_) $byCode[$c_['codice']] = $c_;
            $rows = [];
            foreach ((array)($_POST['s'] ?? []) as $code => $vals) {
                if (!is_array($vals)) continue;
                if ($code === 'ECO') { $targets = ['I.K1' => ['s' => 's1', 'w' => 'w', 'n' => 'n1'], 'I.K2' => ['s' => 's2', 'w' => 'w', 'n' => 'n2']];
                    foreach ($targets as $cc => $mapK) if (isset($byCode[$cc])) foreach ($mapK as $k_ => $src_) { $v_ = PrjUi::num($vals[$src_] ?? ''); if ($v_ !== null) $rows[] = [(int)$byCode[$cc]['ent_id'], $k_, $k_ === 's' ? $v_ / 100 : $v_]; }
                    continue; }
                $c_ = null; foreach ($byCode as $cc => $cr) if ($cc === $code || $cr['gruppo'] === $code && in_array($code, ['C', 'D', 'E', 'F', 'G', 'H'], true)) { $c_ = $cr; break; }
                if (!$c_) continue;
                foreach ($vals as $k_ => $v_) {
                    if ($k_ === 'list') { $i_ = 0; foreach (preg_split('/[\s;]+/', trim((string)$v_)) as $piece) { $n_ = PrjUi::num($piece); if ($n_ !== null) $rows[] = [(int)$c_['ent_id'], 'p' . (++$i_), $n_]; } continue; }
                    if (!preg_match('/^[a-z0-9_]{1,30}$/', (string)$k_)) continue;
                    $n_ = PrjUi::num($v_); if ($n_ !== null) $rows[] = [(int)$c_['ent_id'], (string)$k_, $n_];
                }
            }
            $pdo->beginTransaction();
            $old = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_criterion_input WHERE prj_id = ? AND scenario_id IS NULL AND chiave NOT IN ('k')"); $old->execute([$id]); $nOld = (int)$old->fetchColumn();
            $pdo->prepare("DELETE FROM cm_prj_criterion_input WHERE prj_id = ? AND scenario_id IS NULL AND chiave NOT IN ('k')")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO cm_prj_criterion_input (prj_id, criterion_id, scenario_id, chiave, valore) VALUES (?,?,NULL,?,?) ON DUPLICATE KEY UPDATE valore = VALUES(valore)");
            foreach ($rows as [$cid, $k_, $v_]) $ins->execute([$id, $cid, $k_, $v_]);
            $ecl->logField('cm_prj_criterion_input', $id, 'input_simulatore', (string)$nOld, (string)count($rows), 'update', 'ui', null, $u_id);
            $pdo->commit();
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Simulazione del punteggio salvata (" . count($rows) . " valori).</div>";
            break;

        case 'actuals_refresh':   // v1.10.03 — ricalcolo dei consuntivi e degli scostamenti
            if (!$can_calc) { $deny(); break; }
            $r = (new PrjActuals($pdo))->refresh($id);
            write_log('Commesse', 'info', "PRJ {$prj['prj_code']}: consuntivi aggiornati ({$r['mesi']} mesi)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Consuntivi aggiornati: {$r['mesi']} mesi.</div>";
            break;

        case 'calc_run':
            if (!$can_calc) { $deny(); break; }
            $sid = (int)($_POST['scenario_id'] ?? 0);
            $o = $pdo->prepare("SELECT COUNT(*) FROM cm_prj_scenario WHERE id = ? AND prj_id = ?"); $o->execute([$sid, $id]);
            if (!$o->fetchColumn()) break;
            $r = $repo->saveRun($id, $sid, $dOk($_POST['as_of'] ?? '') ?: date('Y-m-d'), $u_id);
            write_log('Commesse', 'info', "PRJ {$prj['prj_code']}: calc run #{$r['run_id']} scenario #$sid", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Calcolo salvato (run #{$r['run_id']}): costo aziendale totale "
                . PrjUi::k($r['result']['totali']['costo_aziendale_totale']) . ', ' . PrjUi::pct($r['result']['totali']['pct_canone']) . " del canone.</div>";
            redirect_self(['tab' => 'scen', 'sc' => $sid]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        write_log('Commesse', 'error', "PRJ {$prj['prj_code']} ($a): " . $e->getMessage(), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Operazione non riuscita: " . h($e->getMessage()) . "</div>";
    }
    redirect_self(['tab' => $back]);
}

// ── Dati ─────────────────────────────────────────────────────────────────────
$today = date('Y-m-d');
$q = function (string $sql, array $a = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); };
$sp       = $prj['sp_project_id'] ? $lnk->commessa((int)$prj['sp_project_id']) : null;
$linkHist = $lnk->historyOf($id);
$sugg     = $tab === 'link' && $can_link ? $lnk->suggestions($id) : [];
$clients   = $q("SELECT id, name FROM clients WHERE is_active = 1 OR id = ? ORDER BY name", [(int)$prj['client_id']]);
$companies = $q("SELECT id, name FROM companies ORDER BY name");
$users     = $q("SELECT u.id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.last_name, e.first_name)),''), u.display_name, u.email) AS nome
                   FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.status = 'active' OR u.id = ? ORDER BY nome", [(int)$prj['responsabile_user_id']]);
$presales  = []; foreach ($q("SELECT * FROM cm_prj_presales WHERE prj_id = ?", [$id]) as $r) $presales[$r['cost_center']] = $r;
$sources   = $q("SELECT * FROM cm_prj_source WHERE prj_id = ? ORDER BY codice", [$id]);
$gara      = $q("SELECT * FROM cm_prj_gara WHERE prj_id = ? AND is_current = 1", [$id])[0] ?? null;
$tender    = $q("SELECT * FROM cm_prj_tender_base WHERE prj_id = ? AND is_current = 1 ORDER BY year", [$id]);
$rates     = $q("SELECT * FROM cm_prj_rate_card WHERE prj_id = ? AND is_current = 1 ORDER BY eur_giorno DESC, profilo", [$id]);
$services  = $q("SELECT s.*, a.codice AS area FROM cm_prj_service s LEFT JOIN cm_prj_area a ON a.id = s.area_id WHERE s.prj_id = ? AND s.is_current = 1 ORDER BY s.codice", [$id]);
$svcByEnt  = []; foreach ($services as $s) $svcByEnt[(int)$s['ent_id']] = $s;
$techs     = []; foreach ($q("SELECT * FROM cm_prj_service_technology WHERE prj_id = ? ORDER BY technology_raw", [$id]) as $t) $techs[(int)$t['service_id']][] = $t;
$catalogTech = $q("SELECT name FROM technologies WHERE is_active = 1 ORDER BY name");
$assets    = $q("SELECT * FROM cm_prj_asset_metric WHERE prj_id = ? AND is_current = 1 ORDER BY year DESC, id", [$id]);
$groups    = $q("SELECT * FROM cm_prj_ticket_group WHERE prj_id = ? ORDER BY ordine, nome", [$id]);
$volYears  = array_map('intval', array_column($q("SELECT DISTINCT year FROM cm_prj_ticket_volume WHERE prj_id = ? AND is_current = 1 ORDER BY year DESC", [$id]), 'year'));
$vy        = in_array((int)($_GET['vy'] ?? 0), $volYears, true) ? (int)$_GET['vy'] : ($volYears[0] ?? (int)date('Y'));
$vol = []; foreach ($q("SELECT * FROM cm_prj_ticket_volume WHERE prj_id = ? AND is_current = 1 AND year = ?", [$id, $vy]) as $v) $vol[(int)$v['group_id']][$v['tipo']] = $v;
$map = []; foreach ($q("SELECT * FROM cm_prj_ticket_mapping WHERE prj_id = ? AND is_current = 1", [$id]) as $m) $map[(int)$m['group_id']][] = $m;
$aht       = $q("SELECT * FROM cm_prj_aht WHERE prj_id = ? AND is_current = 1 ORDER BY service_id, FIELD(tipo,'CTASK','INC','SCTASK')", [$id]);
$prod      = $q("SELECT * FROM cm_prj_productivity WHERE prj_id = ? AND is_current = 1", [$id])[0] ?? null;
$profiles  = $q("SELECT pr.*, sp.id AS sp_id, sp.service_id, sp.n_minimo, sp.fte, sb.id AS sb_id, sb.ral_min, sb.ral_ideale, rq.id AS rq_id, rq.anni_min, rq.titolo_min, rq.lingue
                   FROM cm_prj_profile pr
                   LEFT JOIN cm_prj_service_profile sp ON sp.profile_id = pr.id AND sp.is_current = 1
                   LEFT JOIN cm_prj_salary_band sb ON sb.profile_id = pr.id AND sb.is_current = 1
                   LEFT JOIN cm_prj_profile_req rq ON rq.profile_id = pr.id AND rq.is_current = 1
                  WHERE pr.prj_id = ? ORDER BY pr.ordine, pr.id", [$id]);
$assign = []; foreach ($q("SELECT a.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.last_name, e.first_name)),''), TRIM(CONCAT_WS(' ', pf.last_name, pf.first_name))) AS persona,
                                  tp.seniority, tp.on_call_h24
                             FROM cm_prj_profile_assignment a LEFT JOIN employees e ON e.id = a.employee_id
                             LEFT JOIN cm_professionals pf ON pf.id = a.professional_id
                             LEFT JOIN cm_tech_profiles tp ON (tp.employee_id = a.employee_id AND a.employee_id IS NOT NULL) OR (tp.professional_id = a.professional_id AND a.professional_id IS NOT NULL)
                            WHERE a.prj_id = ? ORDER BY persona", [$id]) as $a) $assign[(int)$a['profile_id']][] = $a;
$certCount = []; foreach ($q("SELECT profile_id, COUNT(*) n, SUM(premiante) p FROM cm_prj_profile_cert WHERE prj_id = ? GROUP BY profile_id", [$id]) as $c) $certCount[(int)$c['profile_id']] = $c;
$employees = $tab === 'prof' && $can_edit ? $q("SELECT id, CONCAT(last_name, ' ', first_name) AS nome FROM employees ORDER BY last_name, first_name") : [];
$professionals = $tab === 'prof' && $can_edit ? $q("SELECT id, TRIM(CONCAT_WS(' ', last_name, first_name)) AS nome FROM cm_professionals WHERE active = 1 AND status <> 'unito' ORDER BY last_name, first_name") : [];
$zones     = $q("SELECT ent_id, nome FROM cm_prj_zone WHERE prj_key IN (0, ?) AND is_current = 1 ORDER BY indice_ral DESC", [$id]);
$nears     = $q("SELECT ent_id, paese FROM cm_prj_nearshore WHERE prj_key IN (0, ?) AND is_current = 1 ORDER BY paese", [$id]);
$scenarios = $q("SELECT s.*, z.nome AS zona, n.paese FROM cm_prj_scenario s
                   LEFT JOIN cm_prj_zone z ON z.ent_id = s.zona_id AND z.is_current = 1
                   LEFT JOIN cm_prj_nearshore n ON n.ent_id = s.nearshore_id AND n.is_current = 1
                  WHERE s.prj_id = ? ORDER BY (s.id = ?) DESC, s.tipo, s.nome", [$id, (int)$prj['scenario_riferimento_id']]);
$refId = (int)$prj['scenario_riferimento_id'] ?: (int)($scenarios[0]['id'] ?? 0);
$selSc = (int)($_GET['sc'] ?? 0);
if (!in_array($selSc, array_map(fn($s) => (int)$s['id'], $scenarios), true)) $selSc = $refId;
$exp = in_array($_GET['export'] ?? '', ['xlsx', 'docx'], true) ? $_GET['export'] : '';   // v1.10.03
$calc = []; $calcErr = [];
foreach ($scenarios as $s) {
    if (!in_array($tab, ['scen', 'costi', 'vol', 'prof'], true) && !$exp && (int)$s['id'] !== $selSc) continue;
    try { $calc[(int)$s['id']] = $repo->calc($id, (int)$s['id'], $today); }
    catch (Throwable $e) { $calcErr[(int)$s['id']] = $e->getMessage(); }
}
$C = $calc[$selSc] ?? null;
$lastRuns = []; foreach ($q("SELECT r.scenario_id, r.id, r.created_at, r.as_of FROM cm_prj_calc_run r
                             JOIN (SELECT scenario_id, MAX(id) mid FROM cm_prj_calc_run WHERE prj_id = ? GROUP BY scenario_id) x ON x.mid = r.id", [$id]) as $r) $lastRuns[(int)$r['scenario_id']] = $r;
$ovr = []; foreach ($q("SELECT * FROM cm_prj_scenario_profile WHERE scenario_id = ?", [$selSc]) as $o) $ovr[$o['profile_id'] . ':' . $o['service_id']] = $o;

// v1.10.02 — KPI & Penali, Punteggio, Storico
$kpis = $crits = $runs = $changes = $verCount = $sIn = $penIn = $penRes = $tecBy = $kpiSvc = []; $pen = $cong = $cmp = null;
$penPeriodo = ($_GET['periodo'] ?? 'mese') === 'anno' ? 'anno' : 'mese';
$ticketStimati = 0.0; $ra = (int)($_GET['ra'] ?? 0); $rb = (int)($_GET['rb'] ?? 0);
$tec = ['totale' => 0.0, 'righe' => []]; $eco = ['k1' => 0.0, 'k2' => 0.0, 'totale' => 0.0]; $ecoIn = []; $ptTec = 0.0; $ptEco = 0.0; $k1max = 20.0; $k2max = 10.0;
$sList = function (array $iv): array { $o = []; foreach ($iv as $k => $v) if (preg_match('/^p(\d+)$/', $k, $m)) $o[(int)$m[1]] = rtrim(rtrim(number_format((float)$v, 4, ',', ''), '0'), ','); ksort($o); return array_values($o); };
if ($tab === 'kpi') {
    $kpis = $q("SELECT * FROM cm_prj_kpi WHERE prj_id = ? AND is_current = 1 ORDER BY codice", [$id]);
    foreach ($q("SELECT k.kpi_id, s.codice FROM cm_prj_service_kpi k JOIN cm_prj_service s ON s.ent_id = k.service_id AND s.is_current = 1 WHERE k.prj_id = ? ORDER BY s.codice", [$id]) as $r_)
        $kpiSvc[(int)$r_['kpi_id']][] = $r_['codice'];
    foreach ((array)($_GET['pen'] ?? []) as $c_ => $v_) {
        if (!is_array($v_) || !preg_match('/^KPI_\d{2}$/', (string)$c_)) continue;
        if (isset($v_['q'])) { $n_ = PrjUi::num($v_['q']); if ($n_ !== null) $penIn[$c_] = $n_; continue; }
        $pp = []; foreach ($v_ as $kk => $vv) { $n_ = PrjUi::num($vv); if ($n_ !== null && in_array($kk, ['A', 'M', 'B', 'critiche', 'non_critiche'], true)) $pp[$kk] = $n_; }
        if ($pp) $penIn[$c_] = $pp;
    }
    $canMedio = $tender ? array_sum(array_map(fn($t) => (float)$t['canone_eur'], $tender)) / count($tender) : 0.0;
    if ($penIn) {
        $pen = PrjCalc::penalties($kpis, $penIn, $penPeriodo === 'anno' ? $canMedio : $canMedio / 12);
        foreach ($pen['righe'] as $r_) $penRes[$r_['codice']] = $r_['penale'];
    }
    $ticketStimati = (float)array_sum(array_map(fn($v) => (int)$v['quantita'], $q("SELECT quantita FROM cm_prj_ticket_volume WHERE prj_id = ? AND is_current = 1 AND year = ?", [$id, $vy])));
    if (($tr = PrjUi::num($_GET['ticket_reali'] ?? '')) !== null) $cong = PrjCalc::conguaglio($ticketStimati, $tr, $canMedio, (float)($prod['banda_volumi'] ?? 0.2));
}
if ($tab === 'punt') {
    $crits = $q("SELECT * FROM cm_prj_criterion WHERE prj_id = ? AND is_current = 1 ORDER BY FIELD(gruppo,'A','B','C','D','E','F','G','H','I'), codice", [$id]);
    foreach ($q("SELECT c.codice, i.chiave, i.valore FROM cm_prj_criterion_input i JOIN cm_prj_criterion c ON c.ent_id = i.criterion_id AND c.is_current = 1
                  WHERE i.prj_id = ? AND i.scenario_id IS NULL", [$id]) as $r_) $sIn[$r_['codice']][$r_['chiave']] = (float)$r_['valore'];
    $in = ['C' => [], 'D' => [], 'E' => [], 'E_n' => 11, 'F' => [], 'G' => 0, 'H' => 0, 'coeff' => []];
    foreach ($crits as $c_) {
        $iv = $sIn[$c_['codice']] ?? [];
        if ($c_['tipo'] === 'E') { $ptEco += (float)$c_['punti_max']; continue; }
        $ptTec += (float)$c_['punti_max'];
        switch ($c_['gruppo']) {
            case 'C': case 'D': foreach ($iv as $k => $v) if ($k[0] === 'p') $in[$c_['gruppo']][] = $v; break;
            case 'E': $in['E_n'] = (int)($iv['n_servizi'] ?? 11); foreach ($iv as $k => $v) if (preg_match('/^s(\d+)$/', $k, $m)) $in['E'][] = [$v, $iv['c' . $m[1]] ?? 0]; break;
            case 'F': for ($i_ = 1; $i_ <= 4; $i_++) $in['F'][] = $iv['c' . $i_] ?? 0; break;
            case 'G': $in['G'] = $iv['rtnc'] ?? 0; break;
            case 'H': $in['H'] = $iv['v'] ?? 0; break;
            default: $in['coeff'][$c_['codice']] = min(1, max(0, $iv['coeff'] ?? 0));
        }
    }
    try { $tec = PrjCalc::technicalScore($crits, $in); } catch (Throwable $e) { $tec = ['totale' => 0.0, 'righe' => []]; }
    foreach ($tec['righe'] as $r_) $tecBy[$r_['codice']] = $r_;
    foreach ($crits as $c_) { if ($c_['codice'] === 'I.K1') $k1max = (float)$c_['punti_max']; if ($c_['codice'] === 'I.K2') $k2max = (float)$c_['punti_max']; }
    $e1 = $sIn['I.K1'] ?? []; $e2 = $sIn['I.K2'] ?? [];
    $ecoIn = ['s1' => isset($e1['s']) ? $e1['s'] * 100 : '', 's2' => isset($e2['s']) ? $e2['s'] * 100 : '', 'w' => $e1['w'] ?? 1, 'n1' => $e1['n'] ?? 1, 'n2' => $e2['n'] ?? 1];
    $eco = PrjCalc::economicScore((float)($e1['s'] ?? 0), (float)($e2['s'] ?? 0), (float)($e1['w'] ?? 1), (float)($e1['n'] ?? 1), (float)($e2['n'] ?? 1), $k1max, $k2max);
}
// v1.10.03 — Stimato vs Consuntivo
$can_real = can('view', 'prj_costs_real.php');
$CV = null; $consAgg = null; $teamCmp = []; $slaRows = []; $kpiRef = [];
if (($tab === 'cons' || $exp) && $sp) {
    $pa = new PrjActuals($pdo);
    $consAgg = $q("SELECT MAX(computed_at) m FROM cm_prj_actual WHERE prj_id = ?", [$id])[0]['m'] ?? null;
    if (!$consAgg && $can_calc) { try { $pa->refresh($id); $consAgg = date('Y-m-d H:i:s'); } catch (Throwable $e) {} }
    $CV = $pa->compare($id);
    $teamCmp = $pa->team($id, (int)$sp['id']);
    $slaRows = $pa->sla((string)$sp['project_code']);
    $kpiRef = $q("SELECT codice, indicatore, livello_atteso FROM cm_prj_kpi WHERE prj_id = ? AND is_current = 1 AND codice IN ('KPI_05','KPI_09','KPI_12','KPI_13') ORDER BY codice", [$id]);
}

// v1.10.03 — export XLSX / DOCX della scheda (scenari, costi, carico, anni, stimato vs consuntivo)
if ($exp) {
    if (!can('export', 'prj_dashboard.php')) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Export non consentito.</div>"; redirect('prj_dashboard', ['id' => $id]); }
    require_once(__DIR__ . '/app/PrjExport.php');
    write_log('Commesse', 'info', "PRJ {$prj['prj_code']}: export $exp", $u_id);
    $ctxE = ['prj' => $prj, 'sp' => $sp, 'scenarios' => $scenarios, 'calc' => $calc, 'refId' => (int)$prj['scenario_riferimento_id'], 'cv' => $CV, 'can_real' => $can_real,
             'tender' => $tender, 'gara' => $gara];
    $exp === 'xlsx' ? PrjExport::xlsx($ctxE) : PrjExport::docx($ctxE);
    exit;
}

if ($tab === 'stor') {
    $runs = $q("SELECT r.id, r.created_at, r.as_of, r.scenario_nome, r.app_version, sp.project_code AS sp_code,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.last_name, e.first_name)),''), u.display_name, u.email) AS utente,
                       MAX(CASE WHEN x.metrica = 'fte_totali' THEN x.valore END) fte, MAX(CASE WHEN x.metrica = 'costo_aziendale_totale' THEN x.valore END) tot,
                       MAX(CASE WHEN x.metrica = 'pct_canone' THEN x.valore END) pct
                  FROM cm_prj_calc_run r LEFT JOIN cm_projects sp ON sp.id = r.sp_project_id LEFT JOIN users u ON u.id = r.user_id LEFT JOIN employees e ON e.id = u.employee_id
                  LEFT JOIN cm_prj_calc_result x ON x.run_id = r.id AND x.ambito = 'totale'
                 WHERE r.prj_id = ? GROUP BY r.id ORDER BY r.id DESC LIMIT 200", [$id]);
    $ids = array_map(fn($r) => (int)$r['id'], $runs);
    if ($ra && $rb && $ra !== $rb && in_array($ra, $ids, true) && in_array($rb, $ids, true))
        $cmp = array_values(array_filter($repo->compareRuns($ra, $rb), fn($c) => in_array($c['ambito'], ['totale', 'anno', 'servizio'], true)));
    $parts = ["(c.entity_table = 'cm_prj' AND c.entity_id = " . $id . ")", "(c.entity_table = 'cm_prj_scenario' AND c.entity_id IN (SELECT id FROM cm_prj_scenario WHERE prj_id = $id))",
              "(c.entity_table = 'cm_prj_profile' AND c.entity_id IN (SELECT id FROM cm_prj_profile WHERE prj_id = $id))"];
    foreach (array_keys(PrjRepo::VERSIONED) as $t_) {
        if (in_array($t_, ['cm_prj_param', 'cm_prj_zone', 'cm_prj_nearshore', 'cm_prj_equipment', 'cm_prj_site_cost', 'cm_prj_overhead'], true))
            $parts[] = "(c.entity_table = '$t_' AND c.entity_id IN (SELECT ent_id FROM `$t_` WHERE prj_id = $id))";
        else $parts[] = "(c.entity_table = '$t_' AND c.entity_id IN (SELECT ent_id FROM `$t_` WHERE prj_id = $id))";
        $vc = $q("SELECT SUM(is_current = 1) cur, SUM(is_current = 0) old FROM `$t_` WHERE prj_id = ?", [$id])[0];
        if ((int)$vc['cur'] + (int)$vc['old'] > 0) $verCount[$t_] = $vc;
    }
    $changes = $q("SELECT c.*, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.last_name, e.first_name)),''), u.display_name, u.email) AS utente
                     FROM entity_change_log c LEFT JOIN users u ON u.id = c.changed_by LEFT JOIN employees e ON e.id = u.employee_id
                    WHERE " . implode(' OR ', $parts) . " ORDER BY c.id DESC LIMIT 300");
}
$svcCode = fn($sid) => (int)$sid === 0 ? 'GOV' : ($svcByEnt[(int)$sid]['codice'] ?? '?');

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');
$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$tabUrl = fn(string $t, array $x = []) => url_safe('prj_dashboard', array_merge(['id' => $id, 'tab' => $t], $x));
?>
<style>
.prj-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.prj-badge{display:inline-block;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;color:#fff}
.prj-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:12px 0}
.prj-kpi .card{padding:10px 12px}.prj-kpi .l{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.prj-kpi .v{font-size:19px;font-weight:800;margin-top:3px}
.prj-tbl{width:100%;font-size:12px}.prj-tbl td,.prj-tbl th{white-space:nowrap}.prj-tbl td.r,.prj-tbl th.r{text-align:right}
.prj-sub{font-size:11px;color:var(--muted)}
.prj-grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
@media (max-width:900px){.prj-grid4{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<div class="page-header prj-head">
  <div>
    <h1><i class="fa-solid fa-compass-drafting"></i> <?=h($prj['prj_code'])?> — <?=h($prj['nome'])?></h1>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px">
      <span class="prj-badge" style="background:<?=PrjUi::statoColor($prj['stato'])?>"><?=h($prj['stato'])?></span>
      <?php if ($sp): ?>
        <span>Commessa SP: <a class="prj-badge" style="background:#0f766e" href="<?=url_safe('project_dashboard', ['id' => (int)$sp['id']])?>" title="Apri la scheda della commessa"><i class="fa-solid fa-link"></i> <?=h($sp['project_code'])?></a></span>
      <?php else: ?>
        <span class="prj-badge" style="background:#94a3b8">non collegato a commessa SP</span>
      <?php endif; ?>
      <?php if ($can_link): ?><a class="btn btn-sm" href="<?=$tabUrl('link')?>"><i class="fa-solid fa-link"></i> Collega a commessa SP</a><?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:6px">
    <?php if (can('export', 'prj_dashboard.php')): ?>
      <a class="btn btn-success btn-sm" href="<?=url_safe('prj_dashboard', ['id' => $id, 'export' => 'xlsx'])?>" title="Scenari, costi, carico, anni, stimato vs consuntivo"><i class="fa-solid fa-file-excel"></i> XLSX</a>
      <a class="btn btn-sm" href="<?=url_safe('prj_dashboard', ['id' => $id, 'export' => 'docx'])?>" title="Relazione di dimensionamento in Word"><i class="fa-solid fa-file-word"></i> DOCX</a>
    <?php endif; ?>
    <a class="btn btn-sm" href="<?=url_safe('manage_projects', ['view' => 'prj'])?>"><i class="fa-solid fa-arrow-left"></i> Progetti PRJ</a>
  </div>
</div>
<?= $msg ?>

<?php if ($C): $T = $C['totali']; ?>
<div class="prj-kpi">
  <?php foreach ([
    ['Scenario', h($C['scenario']['nome']), '#0f172a'], ['FTE', PrjUi::n($T['fte_totali'], 1), '#2563eb'],
    ['Costo aziendale totale', PrjUi::k($T['costo_aziendale_totale']), '#0f172a'], ['Canone medio', PrjUi::k($T['canone_medio']), '#0f172a'],
    ['% canone', PrjUi::pct($T['pct_canone']), ($T['pct_canone'] ?? 0) > 1 ? '#dc2626' : '#16a34a'],
    ['Margine', PrjUi::k($T['margine']), $T['margine'] < 0 ? '#dc2626' : '#16a34a'],
    ['Ribasso max a pareggio', PrjUi::pct($T['ribasso_max_pareggio'], 1), '#7c3aed'],
  ] as [$l, $v, $c]): ?>
    <div class="card"><div class="l"><?=$l?></div><div class="v" style="color:<?=$c?>;font-size:<?=$l === 'Scenario' ? '13px' : '19px'?>"><?=$v?></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tabs" style="display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--border);flex-wrap:wrap">
  <?php foreach ($TABS as $k => $l): ?>
    <a class="tab-btn <?=$tab === $k ? 'active' : ''?>" href="<?=$tabUrl($k, $selSc && $selSc !== $refId ? ['sc' => $selSc] : [])?>" style="text-decoration:none"><?=h($l)?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'anag'): /* ── ANAGRAFICA ── */ ?>
<div class="card">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_anag"><input type="hidden" name="tab" value="anag">
    <fieldset <?= $can_edit ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
    <div class="prj-grid4">
      <div class="form-group"><label>Codice PRJ</label><input type="text" value="<?=h($prj['prj_code'])?>" readonly></div>
      <div class="form-group" style="grid-column:span 3"><label>Nome *</label><input type="text" name="nome" value="<?=h($prj['nome'])?>" required maxlength="200"></div>
      <div class="form-group"><label>Cliente (anagrafica)</label><select name="client_id"><option value="">—</option>
        <?php foreach ($clients as $c): ?><option value="<?=(int)$c['id']?>" <?=$sel($c['id'], $prj['client_id'])?>><?=h($c['name'])?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Cliente (testo)</label><input type="text" name="client_raw" value="<?=h((string)$prj['client_raw'])?>" maxlength="180"></div>
      <div class="form-group"><label>Società esecutrice</label><select name="exec_company_id"><option value="">—</option>
        <?php foreach ($companies as $c): ?><option value="<?=(int)$c['id']?>" <?=$sel($c['id'], $prj['exec_company_id'])?>><?=h($c['name'])?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Tipo</label><select name="project_type"><option value="">—</option>
        <?php foreach (PrjUi::TIPI as $t): ?><option <?=$sel($t, $prj['project_type'])?>><?=h($t)?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Stato</label><select name="stato">
        <?php foreach (PrjUi::STATI as $t): ?><option <?=$sel($t, $prj['stato'])?>><?=h($t)?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Responsabile</label><select name="responsabile_user_id"><option value="">—</option>
        <?php foreach ($users as $u): ?><option value="<?=(int)$u['id']?>" <?=$sel($u['id'], $prj['responsabile_user_id'])?>><?=h($u['nome'])?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Codice gara</label><input type="text" name="codice_gara" value="<?=h((string)$prj['codice_gara'])?>" maxlength="60"></div>
      <div class="form-group"><label>CIG</label><input type="text" name="cig" value="<?=h((string)$prj['cig'])?>" maxlength="20"></div>
      <div class="form-group" style="grid-column:span 2"><label>Stazione appaltante</label><input type="text" name="stazione_appaltante" value="<?=h((string)$prj['stazione_appaltante'])?>" maxlength="200"></div>
      <div class="form-group"><label>Data offerta</label><input type="date" name="data_offerta" value="<?=h((string)$prj['data_offerta'])?>"></div>
      <div class="form-group"><label>Data aggiudicazione</label><input type="date" name="data_aggiudicazione" value="<?=h((string)$prj['data_aggiudicazione'])?>"></div>
      <div class="form-group"><label>Inizio</label><input type="date" name="start_date" value="<?=h((string)$prj['start_date'])?>"></div>
      <div class="form-group"><label>Fine</label><input type="date" name="end_date" value="<?=h((string)$prj['end_date'])?>"></div>
      <div class="form-group" style="grid-column:span 4"><label>Note</label><textarea name="note" rows="2"><?=h((string)$prj['note'])?></textarea></div>
    </div>
    <?php if ($can_edit): ?><button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva anagrafica</button><?php endif; ?>
    </fieldset>
  </form>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-hourglass-half"></i> Effort di offerta (presales)</span>
    <span class="prj-sub">sommato all'effort presales della commessa SP collegata<?= $sp ? ' (' . h($sp['project_code']) . ')' : '' ?></span></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_presales"><input type="hidden" name="tab" value="anag">
  <table class="data-table prj-tbl"><thead><tr><th>Centro di costo</th><th class="r">Ore</th><th class="r">Tariffa €/h</th><th class="r">Valore</th><th>Note</th></tr></thead><tbody>
  <?php $tp = 0; foreach (['Ufficio Gare', 'Sicurezza', 'Ingegneria/Analisi Tecnica', 'Project Management'] as $cc): $r = $presales[$cc] ?? null; $val = $r ? (float)$r['hours'] * (float)$r['hourly_rate'] : 0; $tp += $val; ?>
    <tr><td><?=h($cc)?></td>
      <td class="r"><input type="text" name="ps[<?=h($cc)?>][hours]" value="<?=h((string)($r['hours'] ?? ''))?>" style="width:80px;text-align:right" <?=$can_edit ? '' : 'disabled'?>></td>
      <td class="r"><input type="text" name="ps[<?=h($cc)?>][rate]" value="<?=h((string)($r['hourly_rate'] ?? ''))?>" style="width:80px;text-align:right" <?=$can_edit ? '' : 'disabled'?>></td>
      <td class="r"><?=PrjUi::eur($val)?></td>
      <td><input type="text" name="ps[<?=h($cc)?>][notes]" value="<?=h((string)($r['notes'] ?? ''))?>" maxlength="255" <?=$can_edit ? '' : 'disabled'?>></td></tr>
  <?php endforeach; ?>
  </tbody><tfoot><tr style="font-weight:700"><td colspan="3">Totale</td><td class="r"><?=PrjUi::eur($tp)?></td><td></td></tr></tfoot></table>
  <?php if ($can_edit): ?><button class="btn btn-primary btn-sm" style="margin-top:8px"><i class="fa-solid fa-floppy-disk"></i> Salva effort</button><?php endif; ?>
  </form>
</div>

<?php elseif ($tab === 'link'): /* ── COLLEGAMENTO COMMESSA ── */ ?>
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-link"></i> Commessa SP collegata</span></div>
  <?php if ($sp): ?>
    <table class="data-table prj-tbl"><tbody>
      <tr><th>Codice commessa</th><td><a href="<?=url_safe('project_dashboard', ['id' => (int)$sp['id']])?>"><strong><?=h($sp['project_code'])?></strong></a> — <?=h($sp['name'])?></td></tr>
      <tr><th>Cliente</th><td><?=h((string)$sp['cliente'])?></td></tr>
      <tr><th>Stato</th><td><?=h((string)$sp['operational_status'])?> · <?=h((string)$sp['start_date'])?> → <?=h((string)$sp['end_date'])?></td></tr>
      <tr><th>Valore / costo / margine sincronizzati</th><td><?=PrjUi::eur($sp['value_total'] !== null ? (float)$sp['value_total'] : null)?> · <?=PrjUi::eur($sp['actual_cost'] !== null ? (float)$sp['actual_cost'] : null)?> · <?=PrjUi::eur($sp['margin_total'] !== null ? (float)$sp['margin_total'] : null)?></td></tr>
      <tr><th>Collegato il</th><td><?=h((string)$prj['sp_linked_at'])?></td></tr>
    </tbody></table>
    <?php if ($can_link): ?>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;margin-top:10px" onsubmit="return confirm('Scollegare il progetto dalla commessa SP?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="tab" value="link">
      <div class="form-group" style="margin:0;flex:1"><label>Motivo</label><input type="text" name="motivo" maxlength="255" required></div>
      <button class="btn btn-sm" style="background:#dc2626;color:#fff"><i class="fa-solid fa-link-slash"></i> Scollega</button>
    </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="prj-sub">Il progetto non è collegato: quando la commessa compare nel gestionale ed è sincronizzata, collegarla qui con il suo codice commessa. In alternativa, indicare il codice PRJ nel campo «commerciale» della commessa sul gestionale: la sincronizzazione successiva la collega in automatico.</p>
  <?php endif; ?>
</div>

<?php if ($can_link): ?>
<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-magnifying-glass"></i> <?= $sp ? 'Sostituisci la commessa' : 'Collega a una commessa SP' ?></span></div>
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="tab" value="link">
    <input type="hidden" name="sp_project_id" id="spId" value="">
    <div class="form-group" style="margin:0;min-width:260px;position:relative"><label>Codice commessa (ricerca su codice, nome, cliente, commerciale)</label>
      <input type="text" name="sp_code" id="spCode" autocomplete="off" placeholder="Es. WTS_3016">
      <div id="spRes" style="position:absolute;z-index:20;background:#fff;border:1px solid var(--border);border-radius:6px;max-height:260px;overflow:auto;display:none;width:520px;font-size:12px"></div></div>
    <div class="form-group" style="margin:0;flex:1;min-width:200px"><label>Motivo</label><input type="text" name="motivo" maxlength="255" placeholder="es. aggiudicazione, rinnovo, variante"></div>
    <button class="btn btn-primary btn-sm"><i class="fa-solid fa-link"></i> <?= $sp ? 'Sostituisci' : 'Collega' ?></button>
  </form>
  <?php if ($sugg): ?>
    <h4 style="font-size:12px;margin:14px 0 6px">Suggerimenti</h4>
    <table class="data-table prj-tbl"><thead><tr><th class="r">Punteggio</th><th>Codice commessa</th><th>Commessa</th><th>Cliente</th><th>Periodo</th><th>Motivi</th><th></th></tr></thead><tbody>
    <?php foreach ($sugg as $s): ?>
      <tr><td class="r"><strong><?=number_format($s['punteggio'], 0)?></strong></td><td><?=h($s['project_code'])?></td><td><?=h(mb_strimwidth((string)$s['name'], 0, 50, '…'))?></td>
        <td><?=h((string)$s['cliente'])?></td><td><?=h((string)$s['start_date'])?> → <?=h((string)$s['end_date'])?></td><td class="prj-sub"><?=h($s['motivi'])?></td>
        <td><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="tab" value="link">
          <input type="hidden" name="sp_project_id" value="<?=(int)$s['id']?>"><input type="hidden" name="motivo" value="suggerimento (<?=h($s['motivi'])?>)">
          <button class="btn btn-sm"><i class="fa-solid fa-link"></i> Collega</button></form></td></tr>
    <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div>
<script>
(function(){
  var inp=document.getElementById('spCode'), box=document.getElementById('spRes'), hid=document.getElementById('spId'), t=null;
  if(!inp) return;
  inp.addEventListener('input', function(){
    hid.value=''; clearTimeout(t);
    var v=inp.value.trim(); if(v.length<2){box.style.display='none';return;}
    t=setTimeout(function(){
      fetch('api_prj.php?action=sp_search&prj_id=<?=$id?>&q='+encodeURIComponent(v),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
        box.innerHTML='';
        (d.rows||[]).forEach(function(r){
          var a=document.createElement('div'); a.style.cssText='padding:6px 8px;cursor:pointer;border-bottom:1px solid #f1f5f9';
          a.textContent=r.project_code+' — '+(r.name||'')+(r.cliente?' · '+r.cliente:'');
          a.onclick=function(){inp.value=r.project_code; hid.value=r.id; box.style.display='none';};
          box.appendChild(a);
        });
        box.style.display=(d.rows||[]).length?'block':'none';
      }).catch(function(){box.style.display='none';});
    },250);
  });
  document.addEventListener('click',function(e){ if(e.target!==inp) box.style.display='none'; });
})();
</script>
<?php endif; ?>

<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Storico collegamenti</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Data</th><th>Azione</th><th>Codice commessa</th><th>Motivo</th><th>Utente</th></tr></thead><tbody>
  <?php if (!$linkHist): ?><tr><td colspan="5" class="prj-sub" style="text-align:center;padding:12px">Nessun collegamento registrato.</td></tr><?php endif; ?>
  <?php foreach ($linkHist as $hh): ?>
    <tr><td><?=h(date('d/m/Y H:i', strtotime($hh['created_at'])))?></td><td><?=h(str_replace('_', ' ', $hh['azione']))?></td><td><?=h((string)$hh['sp_project_code'])?></td><td><?=h((string)$hh['motivo'])?></td><td><?=h((string)($hh['utente'] ?? 'sistema'))?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>

<?php elseif ($tab === 'gara'): /* ── GARA ── */ ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vsave"><input type="hidden" name="tab" value="gara">
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-gavel"></i> Durata e fasi</span></div>
  <?php if ($gara): ?>
  <div class="prj-grid4">
    <?php foreach (['durata_mesi' => 'Durata (mesi)', 'mesi_operativi' => 'Mesi operativi', 'phase_in_giorni' => 'Phase In (giorni)', 'phase_in_retribuito' => 'Phase In retribuito',
                    'handover_giorni' => 'Handover (giorni)', 'rinnovo_mesi' => 'Rinnovo opzionale (mesi)'] as $c => $l): ?>
      <div class="form-group"><label><?=h($l)?></label><?=PrjUi::input('cm_prj_gara', (int)$gara['id'], $c, $gara[$c], $can_edit)?></div>
    <?php endforeach; ?>
    <div class="form-group"><label>Fonte</label><div class="prj-sub"><?=h((string)$gara['source_ref'])?> · versione <?=(int)$gara['version_no']?> dal <?=h($gara['valid_from'])?></div></div>
  </div>
  <?php endif; ?>
</div>
<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-coins"></i> Base d'asta per anno</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Anno</th><th class="r">Canone €</th><th class="r">Uncommitted €</th><th>Versione</th><th>Fonte</th></tr></thead><tbody>
  <?php $tc = 0; $tu = 0; foreach ($tender as $t): $tc += (float)$t['canone_eur']; $tu += (float)$t['uncommitted_eur']; ?>
    <tr><td><?=(int)$t['year']?></td><td class="r"><?=PrjUi::input('cm_prj_tender_base', (int)$t['id'], 'canone_eur', $t['canone_eur'], $can_edit)?></td>
      <td class="r"><?=PrjUi::input('cm_prj_tender_base', (int)$t['id'], 'uncommitted_eur', $t['uncommitted_eur'], $can_edit)?></td>
      <td class="prj-sub">v<?=(int)$t['version_no']?> dal <?=h($t['valid_from'])?></td><td class="prj-sub"><?=h((string)$t['source_ref'])?></td></tr>
  <?php endforeach; ?></tbody>
  <tfoot><tr style="font-weight:700"><td>Totale · media</td><td class="r"><?=PrjUi::eur($tc)?> · <?=PrjUi::eur($tender ? $tc / count($tender) : null)?></td><td class="r"><?=PrjUi::eur($tu)?></td><td colspan="2"></td></tr></tfoot></table>
</div>
<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-tags"></i> Tariffario Uncommitted (€/giorno)</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Profilo</th><th class="r">€/giorno</th><th>Versione</th></tr></thead><tbody>
  <?php foreach ($rates as $r): ?><tr><td><?=h($r['profilo'])?></td><td class="r"><?=PrjUi::input('cm_prj_rate_card', (int)$r['id'], 'eur_giorno', $r['eur_giorno'], $can_edit)?></td><td class="prj-sub">v<?=(int)$r['version_no']?> dal <?=h($r['valid_from'])?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($can_edit) echo PrjUi::versionFields(); ?>
</div>
</form>
<?php if ($can_edit): ?>
<details class="pm-panel" style="margin-top:14px"><summary><i class="fa-solid fa-chevron-right pm-chev"></i> Aggiungi un anno alla base d'asta</summary><div class="pm-panel-body">
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="tender_add"><input type="hidden" name="tab" value="gara">
    <div class="form-group" style="margin:0"><label>Anno</label><input type="number" name="year" min="2000" max="2100" required></div>
    <div class="form-group" style="margin:0"><label>Canone €</label><input type="text" name="canone_eur"></div>
    <div class="form-group" style="margin:0"><label>Uncommitted €</label><input type="text" name="uncommitted_eur"></div>
    <button class="btn btn-primary btn-sm">Aggiungi</button></form></div></details>
<?php endif; ?>
<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-lines"></i> Documenti di gara e fonti</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Codice</th><th>Tipo</th><th>Titolo</th><th>Data</th><th>Link</th></tr></thead><tbody>
  <?php foreach ($sources as $s): ?><tr><td><?=h($s['codice'])?></td><td><?=h(str_replace('_', ' ', $s['tipo']))?></td><td><?=h($s['titolo'])?></td><td><?=h((string)$s['data_riferimento'])?></td>
    <td><?php if ($s['url']): ?><a href="<?=h($s['url'])?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i></a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($can_edit): ?>
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="source_add"><input type="hidden" name="tab" value="gara">
    <div class="form-group" style="margin:0"><label>Codice</label><input type="text" name="codice" maxlength="40" required style="width:80px"></div>
    <div class="form-group" style="margin:0"><label>Tipo</label><select name="tipo"><option value="documento_gara">documento di gara</option><option value="mercato">mercato</option><option value="stima_interna">stima interna</option></select></div>
    <div class="form-group" style="margin:0;flex:1;min-width:200px"><label>Titolo</label><input type="text" name="titolo" maxlength="255" required></div>
    <div class="form-group" style="margin:0"><label>Data</label><input type="date" name="data_riferimento"></div>
    <div class="form-group" style="margin:0;min-width:200px"><label>URL</label><input type="url" name="url" placeholder="https://…"></div>
    <button class="btn btn-sm">Aggiungi fonte</button></form>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'svc'): /* ── SERVIZI & TECNOLOGIE ── */ ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vsave"><input type="hidden" name="tab" value="svc">
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-layer-group"></i> Servizi</span><span class="prj-sub"><?=count($services)?> servizi</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Codice</th><th>Area</th><th>Servizio</th><th>Modalità</th><th class="r">Max in sede/anno</th><th class="r">Fuori orario remoto</th><th class="r">Fuori orario sede</th><th>Note fuori orario</th><th>H24</th><th>Tecnologie H24</th><th class="r">Avvio (anno)</th><th class="r">Tecnologie</th></tr></thead><tbody>
  <?php foreach ($services as $s): $sid = (int)$s['id']; ?>
    <tr><td><strong><?=h($s['codice'])?></strong></td><td><?=h((string)$s['area'])?></td>
      <td><?=PrjUi::input('cm_prj_service', $sid, 'nome', $s['nome'], $can_edit)?></td>
      <td><?=PrjUi::input('cm_prj_service', $sid, 'modalita', $s['modalita'], $can_edit)?></td>
      <td class="r"><?=PrjUi::input('cm_prj_service', $sid, 'max_interventi_sede_anno', $s['max_interventi_sede_anno'], $can_edit)?></td>
      <td class="r"><?=PrjUi::input('cm_prj_service', $sid, 'fuori_orario_remoto', $s['fuori_orario_remoto'], $can_edit)?></td>
      <td class="r"><?=PrjUi::input('cm_prj_service', $sid, 'fuori_orario_sede', $s['fuori_orario_sede'], $can_edit)?></td>
      <td><?=PrjUi::input('cm_prj_service', $sid, 'fuori_orario_note', $s['fuori_orario_note'], $can_edit)?></td>
      <td style="text-align:center"><?=PrjUi::input('cm_prj_service', $sid, 'h24', (int)$s['h24'], $can_edit)?></td>
      <td class="prj-sub"><?=h((string)$s['h24_note'])?></td>
      <td class="r"><?=PrjUi::input('cm_prj_service', $sid, 'avvio_anno', $s['avvio_anno'], $can_edit)?><?= $s['avvio_note'] ? '<div class="prj-sub">' . h($s['avvio_note']) . '</div>' : '' ?></td>
      <td class="r"><?=count($techs[(int)$s['ent_id']] ?? [])?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if ($can_edit) echo PrjUi::versionFields(); ?>
</div>
</form>
<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-microchip"></i> Tecnologie per servizio</span>
    <span class="prj-sub">in grassetto le tecnologie collegate al catalogo Tecnologie del portale</span></div>
  <?php foreach ($services as $s): $tl = $techs[(int)$s['ent_id']] ?? []; ?>
    <div style="margin-bottom:8px;font-size:12px"><strong><?=h($s['codice'])?></strong> <span class="prj-sub"><?=h($s['nome'])?></span><br>
    <?php if (!$tl): ?><span class="prj-sub">—</span><?php endif; ?>
    <?php foreach ($tl as $t): ?>
      <span style="display:inline-flex;align-items:center;gap:4px;border:1px solid var(--border);border-radius:12px;padding:1px 8px;margin:2px;<?=$t['technology_id'] ? 'font-weight:700' : ''?>">
        <?=h($t['technology_raw'])?><?= $t['h24'] ? ' <span style="color:#dc2626;font-size:10px">H24</span>' : '' ?>
        <?php if ($can_edit): ?><form method="post" style="display:inline;margin:0" onsubmit="return confirm('Rimuovere la tecnologia?')"><?= csrf_field() ?><input type="hidden" name="action" value="tech_del"><input type="hidden" name="tab" value="svc"><input type="hidden" name="tech_id" value="<?=(int)$t['id']?>"><button style="border:0;background:none;color:#94a3b8;cursor:pointer;padding:0" title="Rimuovi">×</button></form><?php endif; ?>
      </span>
    <?php endforeach; ?></div>
  <?php endforeach; ?>
  <?php if ($can_edit): ?>
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="tech_add"><input type="hidden" name="tab" value="svc">
    <div class="form-group" style="margin:0"><label>Servizio</label><select name="service_id"><?php foreach ($services as $s): ?><option value="<?=(int)$s['ent_id']?>"><?=h($s['codice'])?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0;min-width:220px"><label>Tecnologia</label><input type="text" name="technology_raw" list="techDl" maxlength="200" required>
      <datalist id="techDl"><?php foreach ($catalogTech as $t): ?><option value="<?=h($t['name'])?>"><?php endforeach; ?></datalist></div>
    <div class="form-group" style="margin:0"><label>Versioni</label><input type="text" name="versioni" maxlength="120"></div>
    <label style="font-size:12px;display:flex;gap:4px;align-items:center"><input type="checkbox" name="h24" value="1"> H24</label>
    <button class="btn btn-sm">Aggiungi</button></form>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'vol'): /* ── ASSET & VOLUMI ── */ ?>
<?php if ($C): $tl = $C['ticket']; ?>
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-calculator"></i> Carico da ticket per servizio (<?=$vy?>)</span>
    <span class="prj-sub">ore = ticket × quota gruppo→servizio × ore medie del tipo · FTE = ore / <?=PrjUi::n((float)($prod['ore_utili_fte'] ?? 1600), 0)?> h · uplift <?=PrjUi::pct((float)($prod['uplift'] ?? 0))?> · FTE allocati: scenario «<?=h($C['scenario']['nome'])?>»</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Servizio</th><th class="r">CTASK</th><th class="r">INC</th><th class="r">SCTASK</th><th class="r">Ticket</th><th class="r">Ore</th><th class="r">FTE da ticket</th><th class="r">FTE con uplift</th><th class="r">FTE allocati</th><th class="r">Scostamento</th></tr></thead><tbody>
  <?php foreach ($tl['rows'] as $r): ?>
    <tr><td><strong><?=h($r['codice'])?></strong> <span class="prj-sub"><?=h(mb_strimwidth((string)$r['nome'], 0, 40, '…'))?></span></td>
      <?php foreach (['CTASK', 'INC', 'SCTASK'] as $tp): ?><td class="r"><?=PrjUi::n($r['ticket_per_tipo'][$tp], 0)?></td><?php endforeach; ?>
      <td class="r"><?=PrjUi::n($r['ticket'], 0)?></td><td class="r"><?=PrjUi::n($r['ore'], 0, 'h')?></td><td class="r"><?=PrjUi::n($r['fte_ticket'])?></td>
      <td class="r"><?=PrjUi::n($r['fte_uplift'])?></td><td class="r"><?=PrjUi::n($r['fte_allocati'])?></td>
      <td class="r" style="color:<?=$r['scostamento'] < 0 ? '#dc2626' : '#16a34a'?>"><?=PrjUi::n($r['scostamento'])?></td></tr>
  <?php endforeach; $T_ = $tl['totali']; ?></tbody>
  <tfoot><tr style="font-weight:700"><td>Totale</td><td colspan="3"></td><td class="r"><?=PrjUi::n($T_['ticket'], 0)?></td><td class="r"><?=PrjUi::n($T_['ore'], 0, 'h')?></td><td class="r"><?=PrjUi::n($T_['fte_ticket'])?></td>
    <td class="r"><?=PrjUi::n($T_['fte_uplift'])?></td><td class="r"><?=PrjUi::n($T_['fte_allocati'])?></td><td class="r"><?=PrjUi::n($T_['scostamento'])?></td></tr></tfoot></table>
  <?php if ($T_['ticket_non_mappati'] > 0): ?><p class="prj-sub" style="color:#d97706">Ticket di gruppi senza mapping verso un servizio: <?=PrjUi::n($T_['ticket_non_mappati'], 0)?> (esclusi dal carico).</p><?php endif; ?>
</div>
<?php endif; ?>

<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vsave"><input type="hidden" name="tab" value="vol">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-stopwatch"></i> Ore medie per ticket</span><span class="prj-sub">servizio «tutti» = valore generale; un valore per servizio prevale</span></div>
    <table class="data-table prj-tbl"><thead><tr><th>Tipo</th><th>Servizio</th><th class="r">Ore</th><th>Versione</th></tr></thead><tbody>
    <?php foreach ($aht as $a_): ?><tr><td><?=h($a_['tipo'])?></td><td><?=(int)$a_['service_id'] ? h($svcCode($a_['service_id'])) : 'tutti'?></td>
      <td class="r"><?=PrjUi::input('cm_prj_aht', (int)$a_['id'], 'ore', $a_['ore'], $can_edit)?></td><td class="prj-sub">v<?=(int)$a_['version_no']?> dal <?=h($a_['valid_from'])?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-gauge"></i> Produttività</span></div>
    <?php if ($prod): ?>
    <table class="data-table prj-tbl"><tbody>
      <?php foreach (['ore_utili_fte' => 'Ore utili per FTE', 'giorni_fte' => 'Giorni per FTE', 'uplift' => 'Uplift attività non a ticket (0-1)', 'banda_volumi' => 'Banda volumi ± (0-1)'] as $c => $l): ?>
        <tr><th><?=h($l)?></th><td class="r"><?=PrjUi::input('cm_prj_productivity', (int)$prod['id'], $c, $prod[$c], $can_edit)?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-top:14px;overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-ticket"></i> Volumi ticket per gruppo e mapping verso i servizi</span>
    <span class="prj-sub">Anno: <?php foreach ($volYears as $y): ?><a href="<?=$tabUrl('vol', ['vy' => $y])?>" style="<?=$y === $vy ? 'font-weight:700' : ''?>"><?=$y?></a> <?php endforeach; ?> · il mapping è una stima interna, modificabile</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Area</th><th>Gruppo</th><th class="r">CTASK</th><th class="r">INC</th><th class="r">SCTASK</th><th class="r">Totale</th><th>Servizio → quota</th></tr></thead><tbody>
  <?php $tt = ['CTASK' => 0, 'INC' => 0, 'SCTASK' => 0]; foreach ($groups as $g): $gv = $vol[(int)$g['id']] ?? []; $sum = 0; ?>
    <tr><td><?=h((string)$g['area'])?></td><td><?=h($g['nome'])?></td>
      <?php foreach (['CTASK', 'INC', 'SCTASK'] as $tp): $v_ = $gv[$tp] ?? null; $sum += (int)($v_['quantita'] ?? 0); $tt[$tp] += (int)($v_['quantita'] ?? 0); ?>
        <td class="r"><?= $v_ ? PrjUi::input('cm_prj_ticket_volume', (int)$v_['id'], 'quantita', $v_['quantita'], $can_edit) : '—' ?></td>
      <?php endforeach; ?>
      <td class="r"><strong><?=number_format($sum, 0, ',', '.')?></strong></td>
      <td><?php foreach ($map[(int)$g['id']] ?? [] as $m): ?><span style="white-space:nowrap;margin-right:8px"><?=h($svcCode($m['service_id']))?> <?=PrjUi::input('cm_prj_ticket_mapping', (int)$m['id'], 'quota', $m['quota'], $can_edit, 'style="width:60px;text-align:right"')?></span><?php endforeach; ?></td></tr>
  <?php endforeach; ?></tbody>
  <tfoot><tr style="font-weight:700"><td colspan="2">Totale</td><?php foreach ($tt as $v_): ?><td class="r"><?=number_format($v_, 0, ',', '.')?></td><?php endforeach; ?><td class="r"><?=number_format(array_sum($tt), 0, ',', '.')?></td><td></td></tr></tfoot></table>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-server"></i> Asset</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Metrica</th><th class="r">Valore</th><th>Unità</th><th>Anno</th><th>Fonte</th></tr></thead><tbody>
  <?php foreach ($assets as $m): ?><tr><td><?=h($m['metrica'])?></td><td class="r"><?=PrjUi::input('cm_prj_asset_metric', (int)$m['id'], 'valore', $m['valore'], $can_edit)?></td><td><?=h((string)$m['unita'])?></td><td><?=(int)$m['year']?></td><td class="prj-sub"><?=h((string)$m['source_ref'])?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($can_edit) echo PrjUi::versionFields(); ?>
</div>
</form>

<?php elseif ($tab === 'prof'): /* ── PROFILI ── */ ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vsave"><input type="hidden" name="tab" value="prof">
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-user-gear"></i> Profili richiesti, FTE e fasce RAL</span>
    <span class="prj-sub">FTE obbligatori <?=PrjUi::n(array_sum(array_map(fn($p) => $p['tipo'] === 'obbligatorio' ? (float)$p['fte'] : 0, $profiles)), 2)?> · totali <?=PrjUi::n(array_sum(array_map(fn($p) => (float)$p['fte'], $profiles)), 2)?></span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Cod.</th><th>Servizio</th><th>Profilo</th><th>Tipo</th><th class="r">N. min</th><th class="r">FTE</th><th class="r">RAL min €</th><th class="r">RAL ideale €</th>
    <th class="r">Anni min</th><th>Lingue</th><th>H24</th><th>Nearshore</th><th class="r">Cert.</th><th>Candidati / assegnati</th></tr></thead><tbody>
  <?php foreach ($profiles as $p): $pid = (int)$p['id']; ?>
    <tr><td><?=h($p['codice'])?></td><td><?=h($svcCode($p['service_id']))?></td><td><?=h($p['nome'])?></td>
      <td><span style="color:<?=$p['tipo'] === 'obbligatorio' ? '#2563eb' : ($p['tipo'] === 'governance' ? '#7c3aed' : '#64748b')?>;font-weight:600"><?=h($p['tipo'])?></span></td>
      <td class="r"><?= $p['sp_id'] ? PrjUi::input('cm_prj_service_profile', (int)$p['sp_id'], 'n_minimo', $p['n_minimo'], $can_edit, 'style="width:50px;text-align:right"') : '—' ?></td>
      <td class="r"><?= $p['sp_id'] ? PrjUi::input('cm_prj_service_profile', (int)$p['sp_id'], 'fte', $p['fte'], $can_edit, 'style="width:60px;text-align:right"') : '—' ?></td>
      <td class="r"><?= $p['sb_id'] ? PrjUi::input('cm_prj_salary_band', (int)$p['sb_id'], 'ral_min', $p['ral_min'], $can_edit, 'style="width:80px;text-align:right"') : '—' ?></td>
      <td class="r"><?= $p['sb_id'] ? PrjUi::input('cm_prj_salary_band', (int)$p['sb_id'], 'ral_ideale', $p['ral_ideale'], $can_edit, 'style="width:80px;text-align:right"') : '—' ?></td>
      <td class="r"><?= $p['rq_id'] ? PrjUi::input('cm_prj_profile_req', (int)$p['rq_id'], 'anni_min', $p['anni_min'], $can_edit, 'style="width:44px;text-align:right"') : '—' ?></td>
      <td><?= $p['rq_id'] ? PrjUi::input('cm_prj_profile_req', (int)$p['rq_id'], 'lingue', $p['lingue'], $can_edit, 'style="width:90px"') : '—' ?></td>
      <td style="text-align:center"><input type="checkbox" form="pfForm" name="pf[<?=$pid?>][h24]" value="1" <?=$p['h24'] ? 'checked' : ''?> <?=$can_edit ? '' : 'disabled'?>></td>
      <td style="text-align:center"><input type="checkbox" form="pfForm" name="pf[<?=$pid?>][ns]" value="1" <?=$p['nearshore_ammesso'] ? 'checked' : ''?> <?=$can_edit ? '' : 'disabled'?>></td>
      <td class="r"><?= isset($certCount[$pid]) ? (int)$certCount[$pid]['n'] . ' (' . (int)$certCount[$pid]['p'] . ' prem.)' : '—' ?></td>
      <td><?php foreach ($assign[$pid] ?? [] as $a_): ?>
          <span style="white-space:nowrap;display:inline-flex;gap:4px;align-items:center;margin-right:6px"><?=h((string)$a_['persona'])?> <span class="prj-sub"><?=PrjUi::n((float)$a_['pct_allocazione'], 0)?>%<?= $a_['on_call_h24'] ? ' · H24' : '' ?></span>
          <?php if ($can_edit): ?><button type="submit" form="delA<?=(int)$a_['id']?>" style="border:0;background:none;color:#94a3b8;cursor:pointer;padding:0" title="Rimuovi">×</button><?php endif; ?></span>
        <?php endforeach; ?><?= empty($assign[$pid]) ? '<span class="prj-sub">—</span>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if ($can_edit) echo PrjUi::versionFields(); ?>
</div>
</form>
<?php if ($can_edit): ?>
<form method="post" id="pfForm" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="profile_flags"><input type="hidden" name="tab" value="prof">
  <?php foreach ($profiles as $p): ?><input type="hidden" name="pf[<?=(int)$p['id']?>][_]" value="1"><?php endforeach; ?>
  <button class="btn btn-sm"><i class="fa-solid fa-flag"></i> Salva flag H24 e nearshore</button>
  <span class="prj-sub">H24: il profilo riceve l'indennità H24. Nearshore: il profilo di supporto può essere erogato in nearshore negli scenari che lo prevedono.</span></form>
<?php foreach ($assign as $list) foreach ($list as $a_): ?>
  <form method="post" id="delA<?=(int)$a_['id']?>" onsubmit="return confirm('Rimuovere l\'assegnazione?')" style="display:none"><?= csrf_field() ?><input type="hidden" name="action" value="assign_del"><input type="hidden" name="tab" value="prof"><input type="hidden" name="assign_id" value="<?=(int)$a_['id']?>"></form>
<?php endforeach; ?>
<details class="pm-panel" style="margin-top:14px"><summary><i class="fa-solid fa-chevron-right pm-chev"></i> <i class="fa-solid fa-user-plus" style="color:#3b82f6"></i> Assegna un candidato interno o un professionista a un profilo</summary><div class="pm-panel-body">
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="assign_add"><input type="hidden" name="tab" value="prof">
    <div class="form-group" style="margin:0"><label>Profilo</label><select name="profile_id"><?php foreach ($profiles as $p): ?><option value="<?=(int)$p['id']?>"><?=h($p['codice'] . ' · ' . $svcCode($p['service_id']) . ' · ' . $p['nome'])?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label>Dipendente</label><select name="employee_id"><option value="">—</option><?php foreach ($employees as $e): ?><option value="<?=(int)$e['id']?>"><?=h($e['nome'])?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label>oppure Professionista</label><select name="professional_id"><option value="">—</option><?php foreach ($professionals as $e): ?><option value="<?=(int)$e['id']?>"><?=h($e['nome'])?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label>% allocazione</label><input type="text" name="pct" value="100" style="width:60px"></div>
    <div class="form-group" style="margin:0"><label>Dal</label><input type="date" name="dal"></div>
    <div class="form-group" style="margin:0"><label>Al</label><input type="date" name="al"></div>
    <div class="form-group" style="margin:0;min-width:160px"><label>Note</label><input type="text" name="note" maxlength="255"></div>
    <button class="btn btn-primary btn-sm">Assegna</button></form></div></details>
<?php endif; ?>

<?php elseif ($tab === 'costi'): /* ── COSTI ── */ ?>
<div class="card" style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px">
  Scenario: <?php foreach ($scenarios as $s): ?><a class="btn btn-sm <?=(int)$s['id'] === $selSc ? 'btn-primary' : ''?>" href="<?=$tabUrl('costi', ['sc' => (int)$s['id']])?>"><?=h($s['nome'])?><?=(int)$s['id'] === (int)$prj['scenario_riferimento_id'] ? ' ★' : ''?></a><?php endforeach; ?>
</div>
<?php if (!$C): ?><div class="alert alert-warning"><?=h($calcErr[$selSc] ?? 'Nessuno scenario: crearne uno nella tab Scenari.')?></div>
<?php else: $T = $C['totali']; $sz = $C['strutturale_zona']; ?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-users"></i> Costo per profilo — <?=h($C['scenario']['nome'])?></span><span class="prj-sub">RAL <?=h($C['scenario']['ral_mode'])?> · zona <?=h($C['scenario']['zona'])?><?= $C['scenario']['nearshore'] ? ' · supporto in ' . h($C['scenario']['nearshore']) : '' ?></span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Profilo</th><th>Serv.</th><th class="r">FTE</th><th>Zona</th><th class="r">RAL rif.</th><th class="r">RAL zona</th><th class="r">Oneri</th><th>H24</th><th class="r">Costo az./FTE</th><th class="r">€/giorno</th><th class="r">Costo aziendale</th><th class="r">Strutturale</th></tr></thead><tbody>
  <?php foreach ($C['profili'] as $r): ?>
    <tr><td><?=h($r['codice'])?> <span class="prj-sub"><?=h(mb_strimwidth($r['nome'], 0, 34, '…'))?></span></td><td><?=h($r['servizio'])?></td><td class="r"><?=PrjUi::n($r['fte'])?></td>
      <td><?=h($r['zona'])?><?= $r['remoto'] ? ' <span class="prj-sub">remoto</span>' : '' ?></td><td class="r"><?=PrjUi::eur($r['ral_rif'], 0)?></td><td class="r"><?=PrjUi::eur($r['ral_zona'], 0)?></td>
      <td class="r"><?=PrjUi::pct($r['oneri_pct'])?></td><td><?=$r['h24'] ? '✔' : ''?></td><td class="r"><?=PrjUi::eur($r['costo_az_fte'], 0)?></td><td class="r"><?=PrjUi::eur($r['costo_giornaliero'], 0)?></td>
      <td class="r"><?=PrjUi::k($r['costo_aziendale'])?></td><td class="r"><?=PrjUi::k($r['strutturale'])?></td></tr>
  <?php endforeach; ?></tbody>
  <tfoot><tr style="font-weight:700"><td colspan="2">Totale</td><td class="r"><?=PrjUi::n($T['fte_totali'])?></td><td colspan="7"></td><td class="r"><?=PrjUi::k($T['costo_aziendale_personale'])?></td><td class="r"><?=PrjUi::k($T['strutturali_fte'])?></td></tr></tfoot></table>
</div>
<div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-receipt"></i> Composizione del costo</span></div>
    <table class="data-table prj-tbl"><tbody>
      <?php foreach ([['Costo del personale (RAL)', $T['costo_personale']], ['Oneri', $T['oneri']], ['Indennità H24 (' . PrjUi::n($T['fte_h24'], 2) . ' FTE)', $T['indennita']],
                      ['= Costo aziendale personale', $T['costo_aziendale_personale']], ['Strutturali postazioni', $T['strutturali_fte']], ['Costi di sede', $T['costi_sede']],
                      ['Overhead', $T['overhead']], ['= Costo aziendale totale', $T['costo_aziendale_totale']]] as [$l, $v]): ?>
        <tr style="<?=str_starts_with($l, '=') ? 'font-weight:700' : ''?>"><td><?=h($l)?></td><td class="r"><?=PrjUi::k($v)?></td></tr>
      <?php endforeach; ?>
      <tr><td>Costo medio per FTE · giornaliero</td><td class="r"><?=PrjUi::eur($T['costo_medio_fte'], 0)?> · <?=PrjUi::eur($T['costo_giornaliero_medio'], 0)?></td></tr>
      <tr><td>Peso strutturali</td><td class="r"><?=PrjUi::pct($T['peso_strutturali_pct'], 1)?></td></tr>
    </tbody></table>
  </div>
  <div class="card" style="margin-top:14px">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-building"></i> Strutturale per FTE — <?=h($C['scenario']['zona'])?></span></div>
    <table class="data-table prj-tbl"><tbody>
      <tr><td>Dotazione</td><td class="r"><?=PrjUi::eur($sz['dotazione'])?></td></tr><tr><td>Affitto</td><td class="r"><?=PrjUi::eur($sz['affitto'])?></td></tr>
      <tr><td>Energia</td><td class="r"><?=PrjUi::eur($sz['energia'])?></td></tr><tr style="font-weight:700"><td>Ufficio</td><td class="r"><?=PrjUi::eur($sz['ufficio'])?></td></tr>
      <tr><td>Remoto (solo dotazione)</td><td class="r"><?=PrjUi::eur($sz['remoto'])?></td></tr>
      <tr><td>FTE ufficio · remoto · nearshore</td><td class="r"><?=PrjUi::n($T['fte_ufficio'], 1)?> · <?=PrjUi::n($T['fte_remoto'], 1)?> · <?=PrjUi::n($T['fte_nearshore'], 1)?></td></tr>
    </tbody></table>
  </div>
  <div class="card" style="margin-top:14px">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-briefcase"></i> Overhead di progetto</span></div>
    <table class="data-table prj-tbl"><tbody><?php foreach ($C['overhead'] as $o): ?><tr><td><?=h($o['voce'])?> <span class="prj-sub"><?=h($o['tipo'])?></span></td><td class="r"><?=PrjUi::k($o['importo'])?></td></tr><?php endforeach; ?></tbody></table>
    <p class="prj-sub">Parametri globali (oneri, H24, zone, dotazioni, sede, overhead): <a href="<?=url_safe('prj_parameters')?>">Parametri dimensionamento</a>.</p>
  </div>
</div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'scen'): /* ── SCENARI ── */ ?>
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-scale-balanced"></i> Confronto scenari</span><span class="prj-sub">calcolo alla data odierna · ★ scenario di riferimento · importi annui</span></div>
  <?php if (!$scenarios): ?><p class="prj-sub">Nessuno scenario.</p><?php else: ?>
  <table class="data-table prj-tbl"><thead><tr><th>Indicatore</th>
    <?php foreach ($scenarios as $s): ?><th class="r"><a href="<?=$tabUrl('scen', ['sc' => (int)$s['id']])?>" style="<?=(int)$s['id'] === $selSc ? 'text-decoration:underline' : ''?>"><?=h($s['nome'])?></a><?=(int)$s['id'] === (int)$prj['scenario_riferimento_id'] ? ' ★' : ''?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ([['fte_totali', 'FTE', 'n'], ['costo_personale', 'Costo personale (RAL)', 'k'], ['costo_aziendale_personale', 'Costo aziendale personale', 'k'], ['strutturali', 'Strutturali', 'k'],
                  ['overhead', 'Overhead', 'k'], ['costo_aziendale_totale', 'Costo aziendale totale', 'k'], ['canone_netto', 'Canone netto', 'k'], ['pct_canone', '% canone', 'p'],
                  ['margine', 'Margine', 'k'], ['margine_pct', 'Margine %', 'p'], ['valore_punto_ribasso', 'Valore punto di ribasso', 'k'], ['ribasso_max_pareggio', 'Ribasso max a pareggio', 'p1'],
                  ['fte_finanziabili', 'FTE finanziabili', 'n'], ['costo_medio_fte', 'Costo medio per FTE', 'e'], ['valore_unitario_ticket', 'Valore unitario ticket', 'e2']] as [$k, $l, $f]): ?>
    <tr style="<?=$k === 'costo_aziendale_totale' || $k === 'pct_canone' ? 'font-weight:700' : ''?>"><td><?=h($l)?></td>
    <?php foreach ($scenarios as $s): $v = $calc[(int)$s['id']]['totali'][$k] ?? null; ?>
      <td class="r" style="<?=$k === 'pct_canone' && $v !== null ? 'color:' . ($v > 1 ? '#dc2626' : '#16a34a') : ''?>"><?= isset($calcErr[(int)$s['id']]) ? '<span class="prj-sub" title="' . h($calcErr[(int)$s['id']]) . '">errore</span>'
        : match ($f) { 'n' => PrjUi::n($v, 1), 'k' => PrjUi::k($v), 'p' => PrjUi::pct($v), 'p1' => PrjUi::pct($v, 1), 'e' => PrjUi::eur($v, 0), default => PrjUi::eur($v) } ?></td>
    <?php endforeach; ?></tr>
  <?php endforeach; ?>
    <tr><td>Ultimo calcolo salvato</td><?php foreach ($scenarios as $s): $lr = $lastRuns[(int)$s['id']] ?? null; ?><td class="r prj-sub"><?= $lr ? '#' . (int)$lr['id'] . ' · ' . h(date('d/m/Y H:i', strtotime($lr['created_at']))) : '—' ?></td><?php endforeach; ?></tr>
  </tbody></table>
  <?php endif; ?>
</div>

<?php $S = null; foreach ($scenarios as $s) if ((int)$s['id'] === $selSc) $S = $s; $E = ($_GET['sc'] ?? '') === '-1' ? null : $S; ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px">
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-sliders"></i> <?= $E ? 'Scenario «' . h($E['nome']) . '»' : 'Nuovo scenario' ?></span>
    <span><a class="btn btn-sm" href="<?=$tabUrl('scen', ['sc' => -1])?>"><i class="fa-solid fa-plus"></i> Nuovo</a></span></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="scen_save"><input type="hidden" name="tab" value="scen"><input type="hidden" name="scenario_id" value="<?=(int)($E['id'] ?? 0)?>">
  <fieldset <?= $can_edit ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
  <div class="prj-grid4" style="grid-template-columns:repeat(2,minmax(0,1fr))">
    <div class="form-group"><label>Nome *</label><input type="text" name="nome" value="<?=h((string)($E['nome'] ?? ''))?>" maxlength="120" required></div>
    <div class="form-group"><label>Tipo</label><select name="tipo"><?php foreach (['sostenibile' => 'Sostenibile (obbligatori + supporto ridotto + governance)', 'completo' => 'Completo (tutti i profili)', 'custom' => 'Personalizzato'] as $k => $l): ?><option value="<?=$k?>" <?=$sel($k, $E['tipo'] ?? 'custom')?>><?=h($l)?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Zona *</label><select name="zona_id"><?php foreach ($zones as $z): ?><option value="<?=(int)$z['ent_id']?>" <?=$sel($z['ent_id'], $E['zona_id'] ?? '')?>><?=h($z['nome'])?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>RAL di riferimento</label><select name="ral_mode"><?php foreach (['media' => 'Media tra minima e ideale', 'min' => 'Minima', 'ideale' => 'Ideale'] as $k => $l): ?><option value="<?=$k?>" <?=$sel($k, $E['ral_mode'] ?? 'media')?>><?=h($l)?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Ribasso offerto %</label><input type="text" name="ribasso_pct" value="<?=h(rtrim(rtrim(number_format((float)($E['ribasso_pct'] ?? 0) * 100, 2, '.', ''), '0'), '.'))?>"></div>
    <div class="form-group"><label>Margine obiettivo %</label><input type="text" name="margine_target_pct" value="<?= isset($E['margine_target_pct']) && $E['margine_target_pct'] !== null ? h(rtrim(rtrim(number_format((float)$E['margine_target_pct'] * 100, 2, '.', ''), '0'), '.')) : '' ?>" placeholder="globale"></div>
    <div class="form-group"><label>Supporto in nearshore</label><select name="nearshore_id"><option value="">— no —</option><?php foreach ($nears as $n_): ?><option value="<?=(int)$n_['ent_id']?>" <?=$sel($n_['ent_id'], $E['nearshore_id'] ?? '')?>><?=h($n_['paese'])?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>FTE supporto (sostenibile)</label><input type="text" name="fte_supporto_sostenibile" value="<?=h((string)($E['fte_supporto_sostenibile'] ?? ''))?>" placeholder="globale"></div>
    <div class="form-group" style="grid-column:span 2"><label>Note</label><input type="text" name="note" value="<?=h((string)($E['note'] ?? ''))?>" maxlength="255"></div>
  </div>
  <?php if ($can_edit): ?><button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva scenario</button><?php endif; ?>
  </fieldset></form>
  <?php if ($E): ?>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;border-top:1px solid var(--border);padding-top:10px">
    <?php if ($can_calc): ?><form method="post" style="margin:0;display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="calc_run"><input type="hidden" name="tab" value="scen"><input type="hidden" name="scenario_id" value="<?=(int)$E['id']?>">
      <input type="date" name="as_of" value="<?=$today?>" title="Data dei parametri (as-of)" style="padding:3px"><button class="btn btn-success btn-sm"><i class="fa-solid fa-play"></i> Calcola e salva</button></form><?php endif; ?>
    <?php if ($can_edit): ?>
      <?php if ((int)$E['id'] !== (int)$prj['scenario_riferimento_id']): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="scen_ref"><input type="hidden" name="tab" value="scen"><input type="hidden" name="scenario_id" value="<?=(int)$E['id']?>"><button class="btn btn-sm">★ Riferimento</button></form><?php endif; ?>
      <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="scen_clone"><input type="hidden" name="tab" value="scen"><input type="hidden" name="scenario_id" value="<?=(int)$E['id']?>"><button class="btn btn-sm"><i class="fa-solid fa-clone"></i> Clona</button></form>
      <form method="post" style="margin:0" onsubmit="return confirm('Eliminare lo scenario?')"><?= csrf_field() ?><input type="hidden" name="action" value="scen_del"><input type="hidden" name="tab" value="scen"><input type="hidden" name="scenario_id" value="<?=(int)$E['id']?>"><button class="btn btn-sm" style="color:#dc2626"><i class="fa-solid fa-trash"></i> Elimina</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($E && $C): ?>
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-calendar"></i> Andamento per anno — <?=h($E['nome'])?></span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Anno</th><th class="r">FTE</th><th class="r">Costo</th><th class="r">Canone netto</th><th class="r">Margine</th><th class="r">% canone</th></tr></thead><tbody>
  <?php foreach ($C['anni'] as $a_): ?><tr><td><?=$a_['anno']?> <span class="prj-sub">(anno <?=$a_['n']?>)</span></td><td class="r"><?=PrjUi::n($a_['fte'], 1)?></td><td class="r"><?=PrjUi::k($a_['costo'])?></td><td class="r"><?=PrjUi::k($a_['canone'])?></td>
    <td class="r" style="color:<?=$a_['margine'] < 0 ? '#dc2626' : '#16a34a'?>"><?=PrjUi::k($a_['margine'])?></td><td class="r"><?=PrjUi::pct($a_['pct_canone'])?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?= PmCharts::groupedBars(array_map(fn($a_) => (string)$a_['anno'], $C['anni']), [
        ['label' => 'Costo', 'color' => '#2563eb', 'values' => array_map(fn($a_) => $a_['costo'], $C['anni'])],
        ['label' => 'Canone netto', 'color' => '#16a34a', 'values' => array_map(fn($a_) => $a_['canone'], $C['anni'])],
        ['label' => 'Margine', 'color' => '#d97706', 'values' => array_map(fn($a_) => $a_['margine'], $C['anni'])],
      ], ['unit' => 'k€', 'divisor' => 1000, 'decimals' => 1, 'height' => 180]) ?>
  <p class="prj-sub">I servizi con avvio dal secondo anno sono esclusi dagli anni precedenti.</p>
</div>
<?php endif; ?>
</div>

<?php if ($E): ?>
<form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="scen_ov"><input type="hidden" name="tab" value="scen"><input type="hidden" name="scenario_id" value="<?=(int)$E['id']?>">
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-user-pen"></i> Personalizzazioni per profilo — <?=h($E['nome'])?></span>
    <span class="prj-sub">FTE vuoto = FTE del profilo (o ripartizione sostenibile) · Remoto = costo strutturale solo dotazione · H24 vuoto = flag del profilo</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Profilo</th><th>Serv.</th><th>Tipo</th><th class="r">FTE profilo</th><th class="r">FTE scenario</th><th class="r">FTE override</th><th>Remoto</th><th>H24</th><th>Escluso</th></tr></thead><tbody>
  <?php $fteSc = []; foreach ($C['profili'] ?? [] as $r) $fteSc[$r['profile_id'] . ':' . $r['service_id']] = $r['fte'];
  foreach ($profiles as $p): $key = (int)$p['id'] . ':' . (int)$p['service_id']; $o = $ovr[$key] ?? []; ?>
    <tr><td><?=h($p['codice'])?> <span class="prj-sub"><?=h(mb_strimwidth($p['nome'], 0, 34, '…'))?></span></td><td><?=h($svcCode($p['service_id']))?></td><td><?=h($p['tipo'])?></td>
      <td class="r"><?=PrjUi::n((float)$p['fte'])?></td><td class="r"><?=PrjUi::n($fteSc[$key] ?? 0.0)?></td>
      <td class="r"><input type="text" name="ov[<?=$key?>][fte]" value="<?=h((string)($o['fte_override'] ?? ''))?>" style="width:60px;text-align:right" <?=$can_edit ? '' : 'disabled'?>></td>
      <td style="text-align:center"><input type="checkbox" name="ov[<?=$key?>][remoto]" value="1" <?=!empty($o['remoto']) ? 'checked' : ''?> <?=$can_edit ? '' : 'disabled'?>></td>
      <td><select name="ov[<?=$key?>][h24]" <?=$can_edit ? '' : 'disabled'?>><option value="">profilo (<?=$p['h24'] ? 'sì' : 'no'?>)</option><option value="1" <?=(string)($o['h24'] ?? '') === '1' ? 'selected' : ''?>>sì</option><option value="0" <?=(string)($o['h24'] ?? '') === '0' ? 'selected' : ''?>>no</option></select></td>
      <td style="text-align:center"><input type="checkbox" name="ov[<?=$key?>][escluso]" value="1" <?=!empty($o['escluso']) ? 'checked' : ''?> <?=$can_edit ? '' : 'disabled'?>></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if ($can_edit): ?><button class="btn btn-primary btn-sm" style="margin-top:8px"><i class="fa-solid fa-floppy-disk"></i> Salva personalizzazioni</button><?php endif; ?>
</div>
</form>
<?php endif; ?>
<?php elseif ($tab === 'kpi'): /* ── KPI & PENALI ── */ ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vsave"><input type="hidden" name="tab" value="kpi">
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-bullseye"></i> Catalogo KPI e penali</span><span class="prj-sub"><?=count($kpis)?> KPI · servizi associati per KPI</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>KPI</th><th>Indicatore</th><th>Livello atteso</th><th class="r">Penale €</th><th>Per priorità A/M/B</th><th>Unità</th><th class="r">Blocco</th><th>Servizi</th><th>Fonte</th></tr></thead><tbody>
  <?php foreach ($kpis as $k_): ?>
    <tr><td><strong><?=h($k_['codice'])?></strong></td><td><?=h($k_['indicatore'])?></td>
      <td><?=PrjUi::input('cm_prj_kpi', (int)$k_['id'], 'livello_atteso', $k_['livello_atteso'], $can_edit, 'style="width:200px"')?></td>
      <td class="r"><?=PrjUi::input('cm_prj_kpi', (int)$k_['id'], 'penale_importo', $k_['penale_importo'], $can_edit, 'style="width:80px;text-align:right"')?></td>
      <td><?=PrjUi::input('cm_prj_kpi', (int)$k_['id'], 'penale_importi_priorita', $k_['penale_importi_priorita'], $can_edit, 'style="width:100px"')?></td>
      <td><?=h(str_replace('_', ' ', $k_['penale_unita']))?></td><td class="r"><?=h((string)($k_['blocco'] ?? ''))?></td>
      <td class="prj-sub" title="<?=h(implode(', ', $kpiSvc[(int)$k_['ent_id']] ?? []))?>"><?=count($kpiSvc[(int)$k_['ent_id']] ?? [])?> servizi</td>
      <td class="prj-sub"><?=h((string)$k_['source_ref'])?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if ($can_edit) echo PrjUi::versionFields(); ?>
</div>
</form>

<div class="card" style="margin-top:14px;overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-scale-unbalanced"></i> Simulatore penali</span>
    <span class="prj-sub">quantità fuori soglia nel periodo · canone del periodo = canone medio <?= $penPeriodo === 'anno' ? 'annuo' : '/ 12' ?></span></div>
  <form method="get"><?= route_slug_field('prj_dashboard') ?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tab" value="kpi">
  <div style="display:flex;gap:10px;align-items:flex-end;margin-bottom:8px;font-size:12px">
    <div class="form-group" style="margin:0"><label>Periodo</label><select name="periodo"><option value="mese" <?=$sel('mese', $penPeriodo)?>>Mese</option><option value="anno" <?=$sel('anno', $penPeriodo)?>>Anno</option></select></div>
    <span class="prj-sub">Per i KPI a blocchi di ticket indicare i ticket fuori SLA per priorità (A, M, B); per la patch compliance i punti % mancanti (critiche, non critiche); per i target di spesa lo sforamento in €.</span>
  </div>
  <table class="data-table prj-tbl"><thead><tr><th>KPI</th><th>Indicatore</th><th>Quantità</th><th class="r">Penale</th></tr></thead><tbody>
  <?php foreach ($kpis as $k_): $c_ = $k_['codice']; $in_ = $penIn[$c_] ?? null; $pr_ = $penRes[$c_] ?? null; ?>
    <tr><td><?=h($c_)?></td><td><?=h($k_['indicatore'])?> <span class="prj-sub"><?=h((string)$k_['livello_atteso'])?></span></td>
      <td><?php if ($k_['penale_unita'] === 'blocco_ticket' && $k_['penale_importi_priorita']): foreach (['A', 'M', 'B'] as $pp): ?>
            <?=$pp?> <input type="text" name="pen[<?=h($c_)?>][<?=$pp?>]" value="<?=h((string)($in_[$pp] ?? ''))?>" style="width:50px;text-align:right">
          <?php endforeach; elseif ($k_['penale_unita'] === 'punto_pct' && $k_['penale_importi_priorita']): ?>
            critiche <input type="text" name="pen[<?=h($c_)?>][critiche]" value="<?=h((string)($in_['critiche'] ?? ''))?>" style="width:50px;text-align:right">
            non critiche <input type="text" name="pen[<?=h($c_)?>][non_critiche]" value="<?=h((string)($in_['non_critiche'] ?? ''))?>" style="width:50px;text-align:right">
          <?php elseif ($k_['penale_unita'] !== 'nessuna'): ?>
            <input type="text" name="pen[<?=h($c_)?>][q]" value="<?=h(is_array($in_) ? '' : (string)($in_ ?? ''))?>" style="width:80px;text-align:right"> <span class="prj-sub"><?=h(str_replace('_', ' ', $k_['penale_unita']))?></span>
          <?php else: ?><span class="prj-sub">monitoraggio</span><?php endif; ?></td>
      <td class="r"><?= $pr_ !== null ? PrjUi::eur($pr_, 0) : '' ?></td></tr>
  <?php endforeach; ?></tbody>
  <?php if ($pen): ?><tfoot><tr style="font-weight:700"><td colspan="3">Totale penali (<?=h($penPeriodo)?>)</td><td class="r"><?=PrjUi::eur($pen['totale'], 0)?> · <?=PrjUi::pct($pen['pct_canone'], 2)?> del canone</td></tr></tfoot><?php endif; ?>
  </table>
  <button class="btn btn-primary btn-sm" style="margin-top:8px"><i class="fa-solid fa-calculator"></i> Simula</button>
  </form>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-arrows-left-right"></i> Banda volumi ±<?=PrjUi::pct((float)($prod['banda_volumi'] ?? 0.2))?> e conguaglio</span></div>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap"><?= route_slug_field('prj_dashboard') ?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tab" value="kpi">
    <div class="form-group" style="margin:0"><label>Ticket stimati (anno <?=$vy?>)</label><input type="text" value="<?=PrjUi::n($ticketStimati, 0)?>" readonly style="width:110px"></div>
    <div class="form-group" style="margin:0"><label>Ticket reali dell'anno</label><input type="text" name="ticket_reali" value="<?=h((string)($_GET['ticket_reali'] ?? ''))?>" style="width:110px"></div>
    <button class="btn btn-sm">Calcola</button>
    <?php if ($cong): ?><span style="font-size:12px">Valore unitario <strong><?=PrjUi::eur($cong['valore_unitario'])?></strong> · banda <?=PrjUi::n($cong['soglia_inf'], 0)?>–<?=PrjUi::n($cong['soglia_sup'], 0)?> ·
      fuori banda <strong><?=PrjUi::n($cong['ticket_fuori_banda'], 0)?></strong> · conguaglio <strong style="color:<?=$cong['conguaglio'] < 0 ? '#dc2626' : '#16a34a'?>"><?=PrjUi::eur($cong['conguaglio'], 0)?></strong></span><?php endif; ?>
  </form>
  <p class="prj-sub">Eccedenza o difetto oltre la banda valorizzati a canone medio / ticket stimati [regola contrattuale della gara].</p>
</div>

<?php elseif ($tab === 'punt'): /* ── PUNTEGGIO ── */ ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vsave"><input type="hidden" name="tab" value="punt">
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-ranking-star"></i> Criteri di valutazione</span>
    <span class="prj-sub">tecnica <?=PrjUi::n($ptTec, 0)?> · economica <?=PrjUi::n($ptEco, 0)?> · totale <?=PrjUi::n($ptTec + $ptEco, 0)?></span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Criterio</th><th>Descrizione</th><th>Tipo</th><th class="r">Punti max</th><th>Formula</th><th>Incongruenza bando</th></tr></thead><tbody>
  <?php foreach ($crits as $c_): ?>
    <tr><td><strong><?=h($c_['codice'])?></strong></td><td><?=h((string)$c_['descrizione'])?></td><td><?=h(['Q' => 'quantitativo', 'D' => 'discrezionale', 'T' => 'tabellare', 'E' => 'economico'][$c_['tipo']] ?? $c_['tipo'])?></td>
      <td class="r"><?=PrjUi::input('cm_prj_criterion', (int)$c_['id'], 'punti_max', $c_['punti_max'], $can_edit, 'style="width:60px;text-align:right"')?></td>
      <td><?=PrjUi::input('cm_prj_criterion', (int)$c_['id'], 'formula', $c_['formula'], $can_edit, 'style="width:170px"')?></td>
      <td><?= $c_['flag_incongruenza'] ? '<span style="color:#d97706" title="' . h((string)$c_['nota_incongruenza']) . '"><i class="fa-solid fa-triangle-exclamation"></i> ' . h((string)$c_['nota_incongruenza']) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if ($can_edit) echo PrjUi::versionFields(); ?>
</div>
</form>

<form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="score_save"><input type="hidden" name="tab" value="punt">
<fieldset <?= $can_edit ? '' : 'disabled' ?> style="border:0;padding:0;margin:0">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-check"></i> Simulatore tecnico</span><span class="prj-sub">punteggio <?=PrjUi::n($tec['totale'], 2)?> / <?=PrjUi::n($ptTec, 0)?></span></div>
    <table class="data-table prj-tbl"><tbody>
    <?php foreach ($crits as $c_): if ($c_['tipo'] === 'E') continue; $cc = $c_['codice']; $iv = $sIn[$cc] ?? []; $res_ = $tecBy[$cc] ?? null; ?>
      <tr><td style="width:60px"><strong><?=h($cc)?></strong></td><td>
      <?php switch ($c_['gruppo']):
        case 'C': ?>Pi delle referenze (C.1+C.2+C.3+C.4), separati da punto e virgola<br><input type="text" name="s[C][list]" value="<?=h(implode('; ', $sList($iv)))?>" style="width:100%"><?php break;
        case 'D': ?>Pi dei CV (D.1+D.2+D.3+D.4), separati da punto e virgola<br><input type="text" name="s[D][list]" value="<?=h(implode('; ', $sList($iv)))?>" style="width:100%"><?php break;
        case 'E': ?>Servizi con certificazione premiante: S (soglia) e C (copertura) 0-1 · N = <input type="text" name="s[E][n_servizi]" value="<?=h((string)($iv['n_servizi'] ?? 11))?>" style="width:40px">
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px;margin-top:4px"><?php foreach ($services as $i_ => $sv): ?>
            <span style="white-space:nowrap"><?=h($sv['codice'])?> S<input type="text" name="s[E][s<?=$i_ + 1?>]" value="<?=h((string)($iv['s' . ($i_ + 1)] ?? ''))?>" style="width:34px"> C<input type="text" name="s[E][c<?=$i_ + 1?>]" value="<?=h((string)($iv['c' . ($i_ + 1)] ?? ''))?>" style="width:34px"></span>
          <?php endforeach; ?></div><?php break;
        case 'F': ?>Certificazioni aziendali (1 = possesso, 0,5 = possesso parziale RTI):
          <?php for ($i_ = 1; $i_ <= 4; $i_++): ?><select name="s[F][c<?=$i_?>]"><?php foreach (['0' => '0', '0.5' => '0,5', '1' => '1'] as $vv => $ll): ?><option value="<?=$vv?>" <?=$sel((string)(float)($iv['c' . $i_] ?? 0), (string)(float)$vv)?>><?=$ll?></option><?php endforeach; ?></select><?php endfor; ?><?php break;
        case 'G': ?>RTNc (0-100) <input type="text" name="s[G][rtnc]" value="<?=h((string)($iv['rtnc'] ?? ''))?>" style="width:60px"><?php break;
        case 'H': ?>Possesso <select name="s[H][v]"><?php foreach (['0' => 'no', '0.5' => 'parziale (0,5)', '1' => 'sì (1)'] as $vv => $ll): ?><option value="<?=$vv?>" <?=$sel((string)(float)($iv['v'] ?? 0), (string)(float)$vv)?>><?=$ll?></option><?php endforeach; ?></select><?php break;
        default: ?>Coefficiente atteso 0-1 <input type="text" name="s[<?=h($cc)?>][coeff]" value="<?=h((string)($iv['coeff'] ?? ''))?>" style="width:60px"><?php if ($c_['flag_incongruenza']): ?> <span class="prj-sub" style="color:#d97706"><?=h((string)$c_['nota_incongruenza'])?></span><?php endif; ?>
      <?php endswitch; ?></td><td class="r" style="width:90px"><?= $res_ ? PrjUi::n($res_['punti'], 2) . ' / ' . PrjUi::n($res_['punti_max'], 0) : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-euro-sign"></i> Simulatore economico</span><span class="prj-sub">punteggio <?=PrjUi::n($eco['totale'], 2)?> / <?=PrjUi::n($ptEco, 0)?></span></div>
    <p class="prj-sub">PE = K1·(1−((1−s1)/(1+w·s1))^n1) + K2·(1−((1−s2)/(1+w·s2))^n2). w, n1, n2 non sono definiti nel bando: indicare i valori da simulare.</p>
    <div class="prj-grid4" style="grid-template-columns:repeat(3,minmax(0,1fr))">
      <div class="form-group"><label>Ribasso canone s1 %</label><input type="text" name="s[ECO][s1]" value="<?=h((string)($ecoIn['s1'] ?? ''))?>"></div>
      <div class="form-group"><label>Ribasso Uncommitted s2 %</label><input type="text" name="s[ECO][s2]" value="<?=h((string)($ecoIn['s2'] ?? ''))?>"></div>
      <div class="form-group"><label>w</label><input type="text" name="s[ECO][w]" value="<?=h((string)($ecoIn['w'] ?? 1))?>"></div>
      <div class="form-group"><label>n1</label><input type="text" name="s[ECO][n1]" value="<?=h((string)($ecoIn['n1'] ?? 1))?>"></div>
      <div class="form-group"><label>n2</label><input type="text" name="s[ECO][n2]" value="<?=h((string)($ecoIn['n2'] ?? 1))?>"></div>
    </div>
    <table class="data-table prj-tbl"><tbody>
      <tr><td>K1 canone (max <?=PrjUi::n($k1max, 0)?>)</td><td class="r"><?=PrjUi::n($eco['k1'], 2)?></td></tr>
      <tr><td>K2 Uncommitted (max <?=PrjUi::n($k2max, 0)?>)</td><td class="r"><?=PrjUi::n($eco['k2'], 2)?></td></tr>
      <tr style="font-weight:700"><td>Punteggio totale (tecnico + economico)</td><td class="r"><?=PrjUi::n($tec['totale'] + $eco['totale'], 2)?> / <?=PrjUi::n($ptTec + $ptEco, 0)?></td></tr>
      <?php if ($C): ?><tr><td>Ribasso s1 rispetto al pareggio dello scenario «<?=h($C['scenario']['nome'])?>»</td><td class="r"><?=PrjUi::pct($C['totali']['ribasso_max_pareggio'], 1)?> massimo</td></tr><?php endif; ?>
    </tbody></table>
  </div>
</div>
<?php if ($can_edit): ?><button class="btn btn-primary btn-sm" style="margin-top:10px"><i class="fa-solid fa-floppy-disk"></i> Salva e ricalcola</button><?php endif; ?>
</fieldset>
</form>

<?php elseif ($tab === 'cons'): /* ── STIMATO VS CONSUNTIVO (v1.10.03) ── */ ?>
<?php if (!$sp): ?>
  <div class="card"><p class="prj-sub" style="margin:0">Il confronto è attivo quando il progetto è collegato a una commessa SP (tab «Collegamento commessa»).</p></div>
<?php elseif (!$CV): ?>
  <div class="alert alert-warning">Confronto non disponibile.</div>
<?php else: $ctx = $CV['context']; $TV = $CV['totali']; $sg = $CV['soglie'];
  $col = function (?float $pct, string $k) use ($sg) { if ($pct === null) return 'inherit'; $a = abs($pct * 100); return $a >= $sg[$k][1] ? '#dc2626' : ($a >= $sg[$k][0] ? '#d97706' : '#16a34a'); }; ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-scale-balanced"></i> Commessa <?=h($ctx['project_code'])?> — periodo <?=h(date('m/Y', strtotime($ctx['from'])))?> → <?=h(date('m/Y', strtotime($ctx['to'])))?></span>
    <span style="display:flex;gap:6px;align-items:center"><span class="prj-sub">consuntivi aggiornati al <?=h($consAgg ? date('d/m/Y H:i', strtotime($consAgg)) : 'mai')?></span>
    <?php if ($can_calc): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="actuals_refresh"><input type="hidden" name="tab" value="cons"><button class="btn btn-sm btn-primary"><i class="fa-solid fa-rotate"></i> Aggiorna consuntivi</button></form><?php endif; ?></span></div>
  <div class="prj-kpi" style="margin:0">
    <?php foreach ([
      ['Valore commessa (sincr.)', PrjUi::eur($ctx['value_total'] !== null ? (float)$ctx['value_total'] : null, 0), '#0f172a'],
      ['Valore a oggi', PrjUi::eur($ctx['value_todate'] !== null ? (float)$ctx['value_todate'] : null, 0), '#0f172a'],
      ['Costo consuntivato (sincr.)', PrjUi::eur($ctx['actual_cost'] !== null ? (float)$ctx['actual_cost'] : null, 0), '#0f172a'],
      ['Margine (sincr.)', PrjUi::eur($ctx['margin_total'] !== null ? (float)$ctx['margin_total'] : null, 0), ((float)$ctx['margin_total']) < 0 ? '#dc2626' : '#16a34a'],
      ['Canone stimato nel periodo', PrjUi::eur($TV['canone_stimato'], 0), '#2563eb'],
      ['Costo stimato nel periodo', PrjUi::eur($TV['costo_stimato'], 0), '#2563eb'],
      ['Costo reale nel periodo', $can_real ? PrjUi::eur($TV['costo'], 0) : 'riservato', $can_real ? $col($TV['scost_costo'], 'costo') : '#94a3b8'],
      ['FTE medi stimati / reali', PrjUi::n($TV['fte_stimato_medio'], 1) . ' / ' . PrjUi::n($TV['fte_medio'], 1), '#0f172a'],
    ] as [$l, $v, $c]): ?><div class="card"><div class="l"><?=h($l)?></div><div class="v" style="color:<?=$c?>;font-size:16px"><?=$v?></div></div><?php endforeach; ?>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-column"></i> FTE per mese: stimati vs reali</span></div>
  <?= PmCharts::groupedBars(array_map(fn($m) => substr($m['ym'], 5) . '/' . substr($m['ym'], 2, 2), $CV['mesi']), [
        ['label' => 'FTE stimati', 'color' => '#93c5fd', 'values' => array_map(fn($m) => (float)$m['stimato']['fte'], $CV['mesi'])],
        ['label' => 'FTE reali', 'color' => '#2563eb', 'values' => array_map(fn($m) => (float)$m['consuntivo']['fte'], $CV['mesi'])],
      ], ['unit' => 'FTE', 'decimals' => 1, 'height' => 200]) ?>
</div>

<div class="card" style="overflow-x:auto;margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-table"></i> Dettaglio mensile</span>
    <span class="prj-sub">soglie scostamento: attenzione ≥ <?=PrjUi::n($sg['fte'][0], 0)?>% · allarme ≥ <?=PrjUi::n($sg['fte'][1], 0)?>% (FTE) — <?=PrjUi::n($sg['costo'][0], 0)?>% / <?=PrjUi::n($sg['costo'][1], 0)?>% (costo)</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Mese</th><th class="r">Ore rapporti</th><th class="r">Ore timesheet</th><th class="r">Ore DGB</th><th class="r">Ore totali</th><th class="r">Ticket</th>
    <th class="r">FTE stimati</th><th class="r">FTE reali</th><th class="r">Scost. FTE</th><th class="r">Costo stimato</th><th class="r">Costo reale</th><th class="r">Scost. costo</th></tr></thead><tbody>
  <?php foreach ($CV['mesi'] as $m): ?>
    <tr><td><?=h(date('m/Y', strtotime($m['ym'] . '-01')))?></td><td class="r"><?=PrjUi::n($m['ore_report'], 1)?></td><td class="r"><?=PrjUi::n($m['ore_timesheet'], 1)?></td><td class="r"><?=PrjUi::n($m['ore_dgb'], 1)?></td>
      <td class="r"><strong><?=PrjUi::n($m['ore'], 1)?></strong></td><td class="r"><?=PrjUi::n($m['ticket'], 0)?></td>
      <td class="r"><?=PrjUi::n($m['stimato']['fte'], 2)?></td><td class="r"><?=PrjUi::n($m['consuntivo']['fte'], 2)?></td>
      <td class="r" style="color:<?=$m['ore'] > 0 ? $col($m['scost_fte'], 'fte') : 'inherit'?>"><?= $m['ore'] > 0 ? PrjUi::pct($m['scost_fte'], 0) : '—' ?></td>
      <td class="r"><?=PrjUi::eur($m['stimato']['costo'], 0)?></td><td class="r"><?= $can_real ? PrjUi::eur($m['consuntivo']['costo'], 0) : '—' ?></td>
      <td class="r" style="color:<?=$m['ore'] > 0 && $can_real ? $col($m['scost_costo'], 'costo') : 'inherit'?>"><?= $m['ore'] > 0 && $can_real ? PrjUi::pct($m['scost_costo'], 0) : '—' ?></td></tr>
  <?php endforeach; ?></tbody>
  <tfoot><tr style="font-weight:700"><td>Totale</td><td colspan="3"></td><td class="r"><?=PrjUi::n($TV['ore'], 1)?></td><td class="r"><?=PrjUi::n($TV['ticket'], 0)?></td>
    <td class="r"><?=PrjUi::n($TV['fte_stimato_medio'], 2)?></td><td class="r"><?=PrjUi::n($TV['fte_medio'], 2)?></td><td></td>
    <td class="r"><?=PrjUi::eur($TV['costo_stimato'], 0)?></td><td class="r"><?= $can_real ? PrjUi::eur($TV['costo'], 0) : '—' ?></td><td class="r"><?= $can_real ? PrjUi::pct($TV['scost_costo'], 0) : '—' ?></td></tr></tfoot></table>
  <p class="prj-sub">Ore: rapporti di intervento della commessa + voci manuali di timesheet; le ore delle attività DGB contano solo nei mesi senza rapporti (i rapporti ne sono già la sincronizzazione).
    FTE reali = ore / capacità del mese. Costo reale: dipendenti a costo orario dell'anno, professionisti al costo aziendale del rapporto o della fascia.
    Stimato: scenario di riferimento, costo e canone dell'anno di contratto / 12.<?= $can_real ? '' : ' Costi reali riservati (permesso «Costi reali dipendenti (PRJ)»).' ?></p>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
  <div class="card" style="overflow-x:auto">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-users"></i> Team della commessa vs assegnazioni previste</span></div>
    <table class="data-table prj-tbl"><thead><tr><th>Persona</th><th>Team commessa</th><th>Profili PRJ previsti</th><th>Esito</th></tr></thead><tbody>
    <?php if (!$teamCmp): ?><tr><td colspan="4" class="prj-sub" style="text-align:center;padding:12px">Nessun membro del team né assegnazione prevista.</td></tr><?php endif; ?>
    <?php foreach ($teamCmp as $tm): ?>
      <tr><td><?=h((string)$tm['persona'])?></td>
        <td><?= $tm['team'] ? h(trim((string)$tm['team']['role_in_project']) ?: 'membro') . ($tm['team']['allocated_hours'] > 0 ? ' · ' . PrjUi::n((float)$tm['team']['allocated_hours'], 0, 'h') : '') : '—' ?></td>
        <td><?= $tm['prj'] ? h(implode(', ', array_map(fn($a) => $a['codice'] . ' ' . $a['profilo'] . ' ' . PrjUi::n((float)$a['pct_allocazione'], 0) . '%', $tm['prj']))) : '—' ?></td>
        <td><?= $tm['team'] && $tm['prj'] ? '<span style="color:#16a34a">previsto e in team</span>' : ($tm['team'] ? '<span style="color:#d97706">in team, non previsto</span>' : '<span style="color:#dc2626">previsto, non in team</span>') ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <div class="card" style="overflow-x:auto">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-stopwatch"></i> SLA reali (Service Desk) e KPI di gara</span></div>
    <table class="data-table prj-tbl"><thead><tr><th>Ambito</th><th>Coda / livello</th><th class="r">Presa in carico</th><th class="r">Risoluzione</th></tr></thead><tbody>
    <?php if (!$slaRows): ?><tr><td colspan="4" class="prj-sub" style="text-align:center;padding:12px">Nessuno SLA configurato per la commessa.</td></tr><?php endif; ?>
    <?php foreach ($slaRows as $s_): ?><tr><td><?=h($s_['project_code'])?></td><td><?=h(trim($s_['queue_name'] . ' ' . $s_['label']))?></td>
      <td class="r"><?= $s_['take_charge_min'] !== null ? PrjUi::n($s_['take_charge_min'] / 60, 1, 'h') : '—' ?></td><td class="r"><?= $s_['resolution_min'] !== null ? PrjUi::n($s_['resolution_min'] / 60, 1, 'h') : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="prj-sub" style="margin-top:8px">KPI di gara di riferimento:
      <?php foreach ($kpiRef as $k_): ?><span style="display:block"><?=h($k_['codice'] . ' ' . $k_['indicatore'] . ': ' . $k_['livello_atteso'])?></span><?php endforeach; ?></p>
    <p class="prj-sub">Banda volumi: ticket reali nel periodo <?=PrjUi::n($TV['ticket'], 0)?> — conguaglio annuale nella tab «KPI & Penali».</p>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'stor'): /* ── STORICO ── */ ?>
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-flask"></i> Calcoli salvati</span><span class="prj-sub">selezionare due calcoli per il confronto (il primo è la base)</span></div>
  <form method="get"><?= route_slug_field('prj_dashboard') ?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tab" value="stor">
  <table class="data-table prj-tbl"><thead><tr><th>A</th><th>B</th><th>Run</th><th>Data</th><th>As-of</th><th>Scenario</th><th>Commessa SP</th><th class="r">FTE</th><th class="r">Costo totale</th><th class="r">% canone</th><th>Versione</th><th>Utente</th></tr></thead><tbody>
  <?php if (!$runs): ?><tr><td colspan="12" class="prj-sub" style="text-align:center;padding:12px">Nessun calcolo salvato: usare «Calcola e salva» nella tab Scenari.</td></tr><?php endif; ?>
  <?php foreach ($runs as $r_): ?>
    <tr><td><input type="radio" name="ra" value="<?=(int)$r_['id']?>" <?=$sel($r_['id'], $ra) ? 'checked' : ''?>></td><td><input type="radio" name="rb" value="<?=(int)$r_['id']?>" <?=$sel($r_['id'], $rb) ? 'checked' : ''?>></td>
      <td>#<?=(int)$r_['id']?></td><td><?=h(date('d/m/Y H:i', strtotime($r_['created_at'])))?></td><td><?=h($r_['as_of'])?></td><td><?=h((string)$r_['scenario_nome'])?></td>
      <td><?=h((string)($r_['sp_code'] ?? '—'))?></td><td class="r"><?=PrjUi::n($r_['fte'] !== null ? (float)$r_['fte'] : null, 1)?></td><td class="r"><?=PrjUi::k($r_['tot'] !== null ? (float)$r_['tot'] : null)?></td>
      <td class="r"><?=PrjUi::pct($r_['pct'] !== null ? (float)$r_['pct'] : null)?></td><td class="prj-sub"><?=h($r_['app_version'])?></td><td class="prj-sub"><?=h((string)($r_['utente'] ?? ''))?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php if (count($runs) > 1): ?><button class="btn btn-sm" style="margin-top:8px"><i class="fa-solid fa-code-compare"></i> Confronta</button><?php endif; ?>
  </form>
  <?php if ($cmp): ?>
    <h4 style="font-size:12px;margin:14px 0 6px">Confronto run #<?=$ra?> → #<?=$rb?></h4>
    <table class="data-table prj-tbl"><thead><tr><th>Ambito</th><th>Metrica</th><th class="r">#<?=$ra?></th><th class="r">#<?=$rb?></th><th class="r">Delta</th><th class="r">Delta %</th></tr></thead><tbody>
    <?php foreach ($cmp as $c_): if (abs((float)($c_['delta'] ?? 0)) < 1e-9 && $c_['ambito'] !== 'totale') continue; ?>
      <tr><td><?=h($c_['ambito'] . ($c_['ref'] !== '' ? ' ' . $c_['ref'] : ''))?></td><td><?=h(str_replace('_', ' ', $c_['metrica']))?></td>
        <td class="r"><?=PrjUi::n($c_['a'], 2)?></td><td class="r"><?=PrjUi::n($c_['b'], 2)?></td>
        <?php $up = in_array($c_['metrica'], ['margine', 'margine_pct', 'ribasso_max_pareggio', 'fte_finanziabili', 'canone_medio', 'canone_netto', 'canone', 'valore_punto_ribasso', 'valore_unitario_ticket'], true); $dd = (float)($c_['delta'] ?? 0); ?>
        <td class="r" style="color:<?=abs($dd) < 1e-9 ? 'inherit' : (($dd > 0) !== $up ? '#dc2626' : '#16a34a')?>"><?=PrjUi::n($c_['delta'], 2)?></td><td class="r"><?=PrjUi::pct($c_['delta_pct'], 1)?></td></tr>
    <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-top:14px">
<div class="card" style="overflow-x:auto">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Modifiche ai dati</span><span class="prj-sub">ultime 300 · EntityChangeLog</span></div>
  <table class="data-table prj-tbl"><thead><tr><th>Data</th><th>Tabella</th><th>Record</th><th>Campo</th><th>Prima</th><th>Dopo</th><th>Fonte</th><th>Utente</th></tr></thead><tbody>
  <?php if (!$changes): ?><tr><td colspan="8" class="prj-sub" style="text-align:center;padding:12px">Nessuna modifica registrata.</td></tr><?php endif; ?>
  <?php foreach ($changes as $c_): ?>
    <tr><td><?=h(date('d/m/Y H:i', strtotime($c_['changed_at'])))?></td><td><?=h(str_replace('cm_prj_', '', $c_['entity_table']))?></td><td>#<?=(int)$c_['entity_id']?></td><td><?=h($c_['field_name'])?></td>
      <td title="<?=h((string)$c_['old_value'])?>"><?=h(mb_strimwidth((string)$c_['old_value'], 0, 30, '…'))?></td><td title="<?=h((string)$c_['new_value'])?>"><?=h(mb_strimwidth((string)$c_['new_value'], 0, 30, '…'))?></td>
      <td class="prj-sub"><?=h($c_['change_action'] . ' · ' . $c_['change_source'])?></td><td class="prj-sub"><?=h((string)($c_['utente'] ?? ''))?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
<div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-layer-group"></i> Versioni dei dati</span></div>
    <table class="data-table prj-tbl"><thead><tr><th>Dati</th><th class="r">Vigenti</th><th class="r">Storiche</th></tr></thead><tbody>
    <?php foreach ($verCount as $t_ => $vc): ?><tr><td><?=h(str_replace(['cm_prj_', '_'], ['', ' '], $t_))?></td><td class="r"><?=(int)$vc['cur']?></td><td class="r"><?=(int)$vc['old']?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="card" style="margin-top:14px">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-link"></i> Collegamenti</span></div>
    <table class="data-table prj-tbl"><tbody>
    <?php if (!$linkHist): ?><tr><td class="prj-sub">Nessun collegamento.</td></tr><?php endif; ?>
    <?php foreach ($linkHist as $hh): ?><tr><td><?=h(date('d/m/Y', strtotime($hh['created_at'])))?></td><td><?=h(str_replace('_', ' ', $hh['azione']))?></td><td><?=h((string)$hh['sp_project_code'])?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
</div>
</div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
