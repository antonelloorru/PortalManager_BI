<?php
/**
 * prj_dashboard.php — Scheda progetto PRJ (v1.10.01)
 *
 * Analisi Gara & Dimensionamento di un Progetto PRJ, distinto dalle commesse SP.
 * Tab: Anagrafica · Collegamento commessa · Gara · Servizi & Tecnologie · Asset & Volumi · Profili · Costi · Scenari.
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
         'vol' => 'Asset & Volumi', 'prof' => 'Profili', 'costi' => 'Costi', 'scen' => 'Scenari'];
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
$calc = []; $calcErr = [];
foreach ($scenarios as $s) {
    if (!in_array($tab, ['scen', 'costi', 'vol', 'prof'], true) && (int)$s['id'] !== $selSc) continue;
    try { $calc[(int)$s['id']] = $repo->calc($id, (int)$s['id'], $today); }
    catch (Throwable $e) { $calcErr[(int)$s['id']] = $e->getMessage(); }
}
$C = $calc[$selSc] ?? null;
$lastRuns = []; foreach ($q("SELECT r.scenario_id, r.id, r.created_at, r.as_of FROM cm_prj_calc_run r
                             JOIN (SELECT scenario_id, MAX(id) mid FROM cm_prj_calc_run WHERE prj_id = ? GROUP BY scenario_id) x ON x.mid = r.id", [$id]) as $r) $lastRuns[(int)$r['scenario_id']] = $r;
$ovr = []; foreach ($q("SELECT * FROM cm_prj_scenario_profile WHERE scenario_id = ?", [$selSc]) as $o) $ovr[$o['profile_id'] . ':' . $o['service_id']] = $o;
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
  <a class="btn btn-sm" href="<?=url_safe('manage_projects', ['view' => 'prj'])?>"><i class="fa-solid fa-arrow-left"></i> Progetti PRJ</a>
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
<?php endif; ?>

<?php require_once('footer.php'); ?>
