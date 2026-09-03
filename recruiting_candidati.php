<?php
/**
 * certV 2.0 — recruiting_candidati.php   Pipeline ATS + Scorecard colloquio
 */
require_once('access_control.php');
require_once('functions.php');

$u_id      = (int)$_SESSION['user_id'];
$u_role    = (int)($_SESSION['role_id'] ?? 99);
$can_edit  = can('edit');
$can_see_sal = can('view','recruiting_contratti.php');
$msg       = '';

// Auto-migration robusta: aggiungi colonne soft delete una per una
foreach (['deleted_at'=>"ALTER TABLE candidates ADD COLUMN deleted_at DATETIME DEFAULT NULL",
          'deleted_by'=>"ALTER TABLE candidates ADD COLUMN deleted_by INT DEFAULT NULL"] as $col => $sql) {
    try { $pdo->query("SELECT `$col` FROM candidates LIMIT 0")->closeCursor(); }
    catch (\Exception $e) {
        try { $pdo->exec($sql); } catch (\Exception $ex) {}
    }
}

// ── CRUD (PRIMA di header.php per permettere redirect PRG) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    // ════ v1.7.38: NUOVO handler dedicato per associare candidato esistente
    //              a una posizione (chiamato dal modal in candidato_profilo.php) ════
    if ($action === 'add_application') {
        try {
            Csrf::verify();
            $cand_id = (int)($_POST['candidate_id'] ?? 0);
            $pos_id  = (int)($_POST['position_id']  ?? 0);
            $match_score = isset($_POST['match_score']) && is_numeric($_POST['match_score'])
                ? max(0, min(100, (int)$_POST['match_score'])) : null;
            $stage = $_POST['stage'] ?? 'cv_received';
            $allowed_stages = ['cv_received','screening','tech_test','hr_interview','tech_interview','offer_sent'];
            if (!in_array($stage, $allowed_stages, true)) $stage = 'cv_received';

            if ($cand_id <= 0 || $pos_id <= 0) {
                throw new RuntimeException("Candidato o posizione mancanti");
            }
            // Verifica esistenza entità
            $c = $pdo->prepare("SELECT id FROM candidates WHERE id = ? AND deleted_at IS NULL");
            $c->execute([$cand_id]); $c_ok = (bool)$c->fetchColumn(); $c->closeCursor();
            if (!$c_ok) throw new RuntimeException("Candidato #$cand_id non trovato");
            $p = $pdo->prepare("SELECT id, status FROM job_positions WHERE id = ?");
            $p->execute([$pos_id]); $p_row = $p->fetch(); $p->closeCursor();
            if (!$p_row) throw new RuntimeException("Posizione #$pos_id non trovata");

            // UPSERT: se esiste già (UNIQUE candidate_id+position_id) aggiorna stage/match_score
            $upsert = $pdo->prepare("
                INSERT INTO candidate_applications (candidate_id, position_id, stage, match_score)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    stage = VALUES(stage),
                    match_score = COALESCE(VALUES(match_score), match_score),
                    stage_updated_at = CURRENT_TIMESTAMP
            ");
            $upsert->execute([$cand_id, $pos_id, $stage, $match_score]);
            $app_id = (int)$pdo->lastInsertId();
            $was_insert = $app_id > 0;

            // Aggiorna stato candidato → in_pipeline se è ancora 'new'
            $pdo->prepare("
                UPDATE candidates SET status = 'in_pipeline'
                 WHERE id = ? AND status = 'new'
            ")->execute([$cand_id]);

            $verb = $was_insert ? 'creata' : 'aggiornata';
            write_log('Recruiting', 'success',
                "Candidatura $verb: candidato #$cand_id ↔ posizione #$pos_id (stage: $stage)",
                $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>
                <i class='fa-solid fa-check'></i> Candidatura $verb su posizione <strong>" . h($p_row['status']) . "</strong>.
            </div>";

            // Redirect a destinazione richiesta o di default
            $redir = $_POST['redirect'] ?? 'candidato_profilo.php?id=' . $cand_id . '&tab=candidature';
            // Sicurezza: solo path locali permessi
            if (!preg_match('#^[a-zA-Z0-9_./?&=-]+$#', $redir)) $redir = 'candidato_profilo.php?id=' . $cand_id . '&tab=candidature';
            header("Location: $redir"); exit;
        } catch (Throwable $e) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
            $redir = $_POST['redirect'] ?? 'recruiting_candidati.php';
            if (!preg_match('#^[a-zA-Z0-9_./?&=-]+$#', $redir)) $redir = 'recruiting_candidati.php';
            header("Location: $redir"); exit;
        }
    }

    if ($action === 'add_candidate') {
        $diag = []; // raccoglie diagnostica
        try {
            $pdo->beginTransaction();
            $gdpr_exp = date('Y-m-d', strtotime('+1 year'));

            // Step 1: inserisci candidato
            $ins_c = $pdo->prepare(
                "INSERT INTO candidates
                    (first_name,last_name,email,phone,ral_requested,notice_period,
                     skills_tags,source,agency_id,gdpr_consent,gdpr_date,gdpr_expiry,notes,added_by)
                 VALUES (?,?,?,?,?,?,?,?,?,1,CURDATE(),?,?,?)"
            );
            $ok1 = $ins_c->execute([
                $_POST['first_name'] ?? '', $_POST['last_name'] ?? '',
                !empty($_POST['email']) ? $_POST['email'] : null,
                !empty($_POST['phone']) ? $_POST['phone'] : null,
                $can_see_sal && !empty($_POST['ral_requested']) ? (float)$_POST['ral_requested'] : null,
                !empty($_POST['notice_period']) ? $_POST['notice_period'] : null,
                !empty($_POST['skills_tags']) ? $_POST['skills_tags'] : null,
                $_POST['source'] ?? 'Altro',
                !empty($_POST['agency_id']) && (int)$_POST['agency_id'] > 0 ? (int)$_POST['agency_id'] : null,
                $gdpr_exp,
                !empty($_POST['notes']) ? $_POST['notes'] : null,
                $u_id
            ]);
            $cand_id = (int)$pdo->lastInsertId();
            $diag[] = "Candidato inserito con id=$cand_id";

            // Step 2: crea applicazione se richiesta
            $pos_id_for_app = !empty($_POST['position_id']) ? (int)$_POST['position_id'] : 0;
            $app_created = false;
            if ($pos_id_for_app > 0 && $cand_id > 0) {
                // Verifica che la posizione esista
                $chk = $pdo->prepare("SELECT id, status FROM job_positions WHERE id=?");
                $chk->execute([$pos_id_for_app]); $pos_row = $chk->fetch(); $chk->closeCursor();
                if (!$pos_row) {
                    throw new Exception("Posizione #$pos_id_for_app non trovata nel database");
                }

                $match_score = isset($_POST['match_score']) && is_numeric($_POST['match_score'])
                    ? max(0, min(100, (int)$_POST['match_score'])) : null;

                $ins_a = $pdo->prepare(
                    "INSERT INTO candidate_applications (candidate_id,position_id,stage,match_score) VALUES (?,?,'cv_received',?)"
                );
                $ok2 = $ins_a->execute([$cand_id, $pos_id_for_app, $match_score]);
                $app_id_new = (int)$pdo->lastInsertId();
                if ($app_id_new > 0) {
                    $app_created = true;
                    $diag[] = "Candidatura #$app_id_new creata su posizione #$pos_id_for_app (stato: {$pos_row['status']})";
                } else {
                    throw new Exception("INSERT candidate_applications non ha generato lastInsertId");
                }
            }

            $pdo->commit();
            write_log('Recruiting','success',"Candidato aggiunto id=$cand_id" . ($app_created ? " con candidatura su pos #$pos_id_for_app" : ' (senza candidatura)'),$u_id);

            $diag_msg = '<br><small style="opacity:.8">' . implode(' · ', $diag) . '</small>';
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Candidato salvato" . ($app_created ? " e associato alla posizione" : "") . ".$diag_msg</div>";
            redirect('recruiting_candidati');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            write_log('Recruiting','error',"Errore add_candidate: " . $e->getMessage(),$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'><strong>Errore salvataggio:</strong> " . h($e->getMessage()) . "<br><small>" . implode(' · ', $diag) . "</small></div>";
            redirect('recruiting_candidati');
        }
    }

    // ── Soft delete candidato ────────────────────────────────────────────────
    if ($action === 'delete_candidate' && can('delete')) {
        $cid = (int)($_POST['candidate_id'] ?? 0);
        if ($cid > 0) {
            try {
                $pdo->prepare("UPDATE candidates SET deleted_at=NOW(), deleted_by=? WHERE id=?")->execute([$u_id, $cid]);
                write_log('Recruiting','warning',"Candidato #$cid soft-deleted",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Candidato eliminato. È possibile ripristinarlo dal log.</div>";
            } catch (Exception $e) {
                $_SESSION['flash_msg'] = "<div class='alert alert-danger'>".h($e->getMessage())."</div>";
            }
        }
        redirect('recruiting_candidati');
    }

    if ($action === 'update_stage') {
        $pdo->prepare("UPDATE candidate_applications SET stage=? WHERE id=?")
            ->execute([$_POST['stage'], (int)$_POST['app_id']]);
        $q = http_build_query([
            'f_pos' => $_GET['f_pos'] ?? '',
            'f_st'  => $_GET['f_st']  ?? '',
            'f_sc'  => $_GET['f_sc']  ?? '',
            'updated' => 1,
        ]);
        header("Location: recruiting_candidati.php?" . $q); exit();
    }

    if ($action === 'save_scorecard') {
        $app_id = (int)$_POST['app_id'];
        $hs_avg = (($_POST['hs_score_1']??0)+($_POST['hs_score_2']??0)+($_POST['hs_score_3']??0)+($_POST['hs_score_4']??0)) / 4;
        $ss_avg = (($_POST['ss_ps']??0)+($_POST['ss_ct']??0)+($_POST['ss_tw']??0)+($_POST['ss_la']??0)) / 4;
        $total  = round($hs_avg * 0.6 + $ss_avg * 0.4, 2);

        $pdo->prepare(
            "INSERT INTO interview_scorecards
                (application_id,interviewer_id,interview_date,type,
                 hs_label_1,hs_score_1,hs_note_1, hs_label_2,hs_score_2,hs_note_2,
                 hs_label_3,hs_score_3,hs_note_3, hs_label_4,hs_score_4,hs_note_4,
                 ss_score_problem_solving,ss_score_communication,ss_score_teamwork,ss_score_learning_agility,
                 hs_avg,ss_avg,total_score,recommendation,summary_note,sent_to_hr)
             VALUES (?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                hs_score_1=VALUES(hs_score_1),hs_note_1=VALUES(hs_note_1),
                hs_score_2=VALUES(hs_score_2),hs_note_2=VALUES(hs_note_2),
                hs_score_3=VALUES(hs_score_3),hs_note_3=VALUES(hs_note_3),
                hs_score_4=VALUES(hs_score_4),hs_note_4=VALUES(hs_note_4),
                ss_score_problem_solving=VALUES(ss_score_problem_solving),
                ss_score_communication=VALUES(ss_score_communication),
                ss_score_teamwork=VALUES(ss_score_teamwork),
                ss_score_learning_agility=VALUES(ss_score_learning_agility),
                hs_avg=VALUES(hs_avg),ss_avg=VALUES(ss_avg),total_score=VALUES(total_score),
                recommendation=VALUES(recommendation),summary_note=VALUES(summary_note),
                sent_to_hr=VALUES(sent_to_hr)"
        )->execute([
            $app_id, $u_id, date('Y-m-d'), $_POST['int_type']??'Tecnico',
            $_POST['hs_label_1']??'', $_POST['hs_score_1']??0, $_POST['hs_note_1']??'',
            $_POST['hs_label_2']??'', $_POST['hs_score_2']??0, $_POST['hs_note_2']??'',
            $_POST['hs_label_3']??'', $_POST['hs_score_3']??0, $_POST['hs_note_3']??'',
            $_POST['hs_label_4']??'', $_POST['hs_score_4']??0, $_POST['hs_note_4']??'',
            $_POST['ss_ps']??0, $_POST['ss_ct']??0, $_POST['ss_tw']??0, $_POST['ss_la']??0,
            round($hs_avg,2), round($ss_avg,2), $total,
            $_POST['recommendation']??'hold', $_POST['summary_note']??'',
            isset($_POST['send_to_hr']) ? 1 : 0
        ]);

        if (isset($_POST['send_to_hr'])) {
            push_notification('Scorecard inviata a HR', "Nuova scorecard disponibile per la posizione.", 'recruiting', 'info', null, 2, "recruiting_candidati.php?app_id=$app_id");
        }
        header("Location: recruiting_candidati.php?app_id=$app_id&score_saved=1" . (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '')); exit();
    }
}

// ── Output HTML inizia da qui ───────────────────────────────────────────────
require_once('header.php');

// Leggi flash message (PRG pattern)
if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// ── DATI ──────────────────────────────────────────────────────────────────────
$f_pos   = (int)($_GET['f_pos'] ?? 0);
$f_stage = $_GET['f_st'] ?? '';
$f_score = $_GET['f_sc'] ?? '';
$view_app= (int)($_GET['app_id'] ?? 0);

$where  = ["1=1"]; $params = [];
$where[] = "(c.deleted_at IS NULL)";
if ($f_pos)   { $where[] = "ca.position_id=?"; $params[] = $f_pos; }
if ($f_stage) { $where[] = "ca.stage=?";       $params[] = $f_stage; }
if ($f_score) { $where[] = "ca.match_score>=?"; $params[] = (int)$f_score; }
// Team Leader vede: (a) candidati senza candidature OR (b) candidati che hanno ALMENO una candidatura su una sua posizione
if ($u_role === 4) {
    $where[] = "(
        NOT EXISTS (SELECT 1 FROM candidate_applications ca2 WHERE ca2.candidate_id=c.id)
        OR EXISTS (SELECT 1 FROM candidate_applications ca3 JOIN job_positions jp3 ON ca3.position_id=jp3.id WHERE ca3.candidate_id=c.id AND jp3.team_leader_id=?)
    )";
    $params[] = $u_id;
}
// Esclude candidature rifiutate ma mantiene candidati senza candidatura
$where[] = "(ca.id IS NULL OR ca.stage NOT IN('rejected'))";

$apps = $pdo->prepare(
    "SELECT c.id AS candidate_id, c.first_name, c.last_name, c.email, c.phone,
            c.skills_tags, c.source, c.ral_requested, c.notice_period, c.cv_path,
            c.status AS cand_status, c.created_at AS cand_created,
            ag.name agency_name,
            ca.id AS id, ca.position_id, ca.stage, ca.match_score, ca.stage_updated_at,
            jp.title pos_title,
            sc.total_score, sc.recommendation, sc.sent_to_hr
     FROM candidates c
     LEFT JOIN candidate_applications ca ON ca.candidate_id = c.id
     LEFT JOIN job_positions jp          ON ca.position_id = jp.id
     LEFT JOIN agencies ag               ON c.agency_id = ag.id
     LEFT JOIN interview_scorecards sc   ON sc.application_id = ca.id
     WHERE ".implode(' AND ', $where)."
     ORDER BY ca.match_score DESC, c.created_at DESC"
);
$apps->execute($params);
$applications = $apps->fetchAll();

// Diagnostica: confronta con conteggio totale grezzo
$tot_cand_db = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
$tot_app_db  = (int)$pdo->query("SELECT COUNT(*) FROM candidate_applications")->fetchColumn();

$positions = $pdo->query("SELECT id,title FROM job_positions WHERE status='open' ORDER BY title")->fetchAll();
$agencies  = $pdo->query("SELECT id,name FROM agencies WHERE status='active' ORDER BY name")->fetchAll();

$stage_meta = [
    'cv_received'    => ['CV ricevuto',      '#6366f1'],
    'screening'      => ['Screening',         '#8b5cf6'],
    'tech_test'      => ['Test tecnico',      '#0ea5e9'],
    'hr_interview'   => ['Colloquio HR',      '#f59e0b'],
    'tech_interview' => ['Colloquio tecnico', '#10b981'],
    'offer_sent'     => ['Offerta inviata',   '#059669'],
    'hired'          => ['Assunto',           '#065f46'],
    'rejected'       => ['Non idoneo',        '#dc2626'],
];

$rec_badge = fn($r) => match($r??'') {
    'proceed' => '<span class="badge badge-success">Procedi</span>',
    'hold'    => '<span class="badge badge-warning">Riserva</span>',
    'reject'  => '<span class="badge badge-danger">Non idoneo</span>',
    default   => '<span class="badge badge-neutral">—</span>',
};

// Scorecard esistente per la vista dettaglio
$cur_sc = null;
if ($view_app > 0) {
    $s = $pdo->prepare("SELECT * FROM interview_scorecards WHERE application_id=? ORDER BY id DESC LIMIT 1");
    $s->execute([$view_app]);
    $cur_sc = $s->fetch();
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-users-line" style="color:var(--p);margin-right:10px"></i>Pipeline candidati
    </h1>
    <p style="color:var(--muted);font-size:13px"><?=count($applications)?> candidature trovate</p>
  </div>
  <?php if($can_edit): ?>
  <button onclick="document.getElementById('mAddCand').style.display='flex'" class="btn btn-primary">
    <i class="fa-solid fa-user-plus"></i> Nuovo candidato
  </button>
  <?php endif; ?>
</div>

<?php
if (!isset($msg) || !$msg) {
    if (isset($_GET['saved']))       $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Candidato aggiunto con successo.</div>";
    if (isset($_GET['updated']))     $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Fase aggiornata.</div>";
    if (isset($_GET['score_saved'])) $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Scorecard salvata.</div>";
}
?>
<?=$msg?>

<!-- Diagnostica sempre visibile -->
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:12px;color:#1e40af;display:flex;gap:18px;flex-wrap:wrap;align-items:center">
  <div><i class="fa-solid fa-database"></i> <strong><?=$tot_cand_db?></strong> candidati totali (<strong><?=count($applications)?></strong> mostrati)</div>
  <div><i class="fa-solid fa-briefcase"></i> <strong><?=$tot_app_db?></strong> candidature totali</div>
  <?php if (count($applications) < $tot_cand_db): ?>
  <div style="color:#b45309"><i class="fa-solid fa-triangle-exclamation"></i> <?=$tot_cand_db - count($applications)?> candidati nascosti da filtri/ruolo</div>
  <?php endif; ?>
</div>

<!-- Filtri -->
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg">
    <label>Posizione</label>
    <select name="f_pos">
      <option value="0">Tutte</option>
      <?php foreach($positions as $p): ?>
      <option value="<?=$p['id']?>" <?=$f_pos==$p['id']?'selected':''?>><?=h($p['title'])?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fg">
    <label>Fase</label>
    <select name="f_st">
      <option value="">Tutte</option>
      <?php foreach(array_slice($stage_meta,0,6,true) as $k=>[$l]): ?>
      <option value="<?=$k?>" <?=$f_stage===$k?'selected':''?>><?=$l?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fg">
    <label>Match min.</label>
    <select name="f_sc">
      <option value="">Qualunque</option>
      <option value="80" <?=$f_score=='80'?'selected':''?>>≥ 80%</option>
      <option value="70" <?=$f_score=='70'?'selected':''?>>≥ 70%</option>
      <option value="60" <?=$f_score=='60'?'selected':''?>>≥ 60%</option>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Filtra</button>
  <a href="recruiting_candidati.php" class="btn">Reset</a>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>

<!-- Tabella candidature -->
<div class="card" style="overflow-x:auto;margin-bottom:24px">
  <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('recruiting_candidati', '#tCand', ['export_filename' => 'recruiting_candidati', 'title' => 'Pipeline candidati']); ?>
<table class="data-table" id="tCand">
    <thead>
      <tr>
        <th>Candidato</th><th>Posizione</th><th>Fonte</th>
        <th style="text-align:center">Match</th><th>Fase</th>
        <th>Scorecard</th>
        <?php if($can_see_sal): ?><th>RAL</th><?php endif; ?>
        <th style="text-align:center" class="no-print">Azioni</th>
      </tr>
    </thead>
    <tbody>
    <?php if(empty($applications)): ?>
    <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">
      Nessun candidato trovato con i filtri attuali.
      <?php if($tot_cand_db > 0): ?>
      <div style="margin-top:10px;font-size:11px">
        <strong style="color:var(--p)"><?=$tot_cand_db?></strong> candidati totali nel database,
        <strong style="color:var(--p)"><?=$tot_app_db?></strong> candidature.
        <?php if($f_pos || $f_stage || $f_score): ?>
        <br><a href="recruiting_candidati.php" style="color:var(--p);font-weight:600">Reset filtri</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </td></tr>
    <?php endif; ?>
    <?php foreach($applications as $a):
      $has_app = !empty($a['id']); // candidato senza candidatura → niente stage/scorecard
      [$sl,$sc] = $stage_meta[$a['stage'] ?? ''] ?? ['Solo anagrafica','#94a3b8'];
      $ms_col = ($a['match_score']??0)>=80?'var(--success)':((($a['match_score']??0)>=60)?'var(--warning)':'var(--danger)');
    ?>
    <tr>
      <td>
        <div style="font-weight:700"><?=h($a['first_name'].' '.$a['last_name'])?></div>
        <div style="font-size:11px;color:var(--muted)"><?=h($a['email']??'')?></div>
      </td>
      <td style="font-size:13px"><?=$a['pos_title'] ? h($a['pos_title']) : '<span style="color:var(--muted);font-style:italic">— Senza candidatura —</span>'?></td>
      <td>
        <span class="badge badge-neutral" style="font-size:9px"><?=h($a['source']??'')?></span>
        <?php if($a['agency_name']): ?><br><span style="font-size:10px;color:var(--muted)"><?=h($a['agency_name'])?></span><?php endif; ?>
      </td>
      <td style="text-align:center">
        <?php if($a['match_score']): ?>
        <span style="font-size:16px;font-weight:800;color:<?=$ms_col?>"><?=$a['match_score']?>%</span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td>
        <?php if($has_app): ?>
        <span style="background:<?=$sc?>22;color:<?=$sc?>;border:1px solid <?=$sc?>44;border-radius:20px;padding:3px 9px;font-size:10px;font-weight:700"><?=$sl?></span>
        <?php else: ?>
        <span style="font-size:10px;color:var(--muted)">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?=($rec_badge)($a['recommendation'] ?? null)?>
        <?php if(!empty($a['total_score'])): ?><span style="font-size:11px;color:var(--muted);margin-left:4px"><?=number_format($a['total_score'],1)?>/5</span><?php endif; ?>
        <?php if(!empty($a['sent_to_hr'])): ?><span class="badge badge-info" style="font-size:9px;margin-left:4px">HR</span><?php endif; ?>
      </td>
      <?php if($can_see_sal): ?>
      <td style="font-size:12px"><?=$a['ral_requested']?'€'.number_format($a['ral_requested'],0,',','.'):'—'?></td>
      <?php endif; ?>
      <td style="text-align:center;white-space:nowrap" class="no-print">
        <a href="candidato_profilo.php?id=<?=$a['candidate_id']?>" class="btn btn-sm" title="Dossier candidato" style="background:#ede9fe;color:#5b21b6;border-color:#c4b5fd">
          <i class="fa-solid fa-id-card"></i>
        </a>
        <?php if($has_app): ?>
        <a href="candidato_profilo.php?id=<?=$a['candidate_id']?>&tab=scorecard&app_id=<?=$a['id']?>" class="btn btn-blue btn-sm" title="Scorecard">
          <i class="fa-solid fa-clipboard-list"></i>
        </a>
        <?php if($can_edit): ?>
        <button onclick="openStageModal(<?=$a['id']?>,'<?=$a['stage']?>')" class="btn btn-sm" title="Cambia fase">
          <i class="fa-solid fa-arrows-rotate"></i>
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <?php if(can("delete")): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare il candidato <?=h(addslashes($a['first_name'].' '.$a['last_name']))?>?\n\nVerrà nascosto dalla lista (soft delete). Le sue candidature e documenti restano nel database.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_candidate">
          <input type="hidden" name="candidate_id" value="<?=$a['candidate_id']?>">
          <button type="submit" class="btn btn-danger btn-sm" title="Elimina candidato"><i class="fa-solid fa-trash"></i></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── SCORECARD DETTAGLIO ── -->
<?php if($view_app > 0):
  $ad = $pdo->prepare(
      "SELECT ca.*, c.first_name, c.last_name, jp.title pos_title
       FROM candidate_applications ca
       JOIN candidates c ON ca.candidate_id=c.id
       JOIN job_positions jp ON ca.position_id=jp.id
       WHERE ca.id=?"
  );
  $ad->execute([$view_app]);
  $ad = $ad->fetch();
  if ($ad):
?>
<div class="card" style="border-color:var(--p)">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-clipboard-list" style="color:var(--p)"></i>
      Scorecard — <?=h($ad['first_name'].' '.$ad['last_name'])?> · <?=h($ad['pos_title'])?>
    </span>
    <a href="recruiting_candidati.php" class="btn btn-sm">← Lista</a>
  </div>

  <?php if($cur_sc): ?>
  <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px;margin-bottom:20px;display:flex;align-items:center;gap:20px">
    <div style="text-align:center;min-width:90px">
      <div style="font-size:36px;font-weight:800;color:<?=$cur_sc['total_score']>=4?'var(--success)':($cur_sc['total_score']>=3?'var(--warning)':'var(--danger)')?>;line-height:1"><?=number_format($cur_sc['total_score'],1)?></div>
      <div style="font-size:10px;color:var(--muted);font-weight:700">/ 5.0</div>
    </div>
    <div>
      <div style="font-weight:700;color:#065f46;font-size:14px">Score totale pesato (60% HS + 40% SS)</div>
      <div style="font-size:12px;color:var(--muted)">HS media: <?=number_format($cur_sc['hs_avg'],2)?>/5 · SS media: <?=number_format($cur_sc['ss_avg'],2)?>/5</div>
      <div style="font-size:11px;color:var(--muted);margin-top:3px">Ultima compilazione: <?=format_date($cur_sc['created_at'],'d/m/Y H:i')?></div>
    </div>
  </div>
  <?php endif; ?>

  <form method="POST">
        <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_scorecard">
    <input type="hidden" name="app_id"  value="<?=$view_app?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
      <!-- Hard Skills -->
      <div>
        <div style="font-weight:700;font-size:13px;padding:10px 14px;background:#eff6ff;border-radius:8px;border-left:3px solid #3b82f6;margin-bottom:14px">
          Competenze tecniche <span style="font-weight:400;color:var(--muted)">(peso 60%)</span>
        </div>
        <?php for($n=1;$n<=4;$n++): ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;gap:10px;margin-bottom:5px">
            <input type="text" name="hs_label_<?=$n?>" value="<?=h($cur_sc["hs_label_$n"]??'Competenza '.$n)?>"
                   placeholder="Es: Architettura Cloud" style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px">
            <select name="hs_score_<?=$n?>" style="width:68px;padding:7px;border:1px solid var(--border);border-radius:6px;font-size:12px">
              <?php for($v=0;$v<=5;$v++): ?>
              <option value="<?=$v?>" <?=($cur_sc["hs_score_$n"]??0)==$v?'selected':''?>><?=$v?></option>
              <?php endfor; ?>
            </select>
          </div>
          <textarea name="hs_note_<?=$n?>" rows="2" placeholder="Note..."
                    style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:11px;resize:none"><?=h($cur_sc["hs_note_$n"]??'')?></textarea>
        </div>
        <?php endfor; ?>
      </div>

      <!-- Soft Skills + riepilogo -->
      <div>
        <div style="font-weight:700;font-size:13px;padding:10px 14px;background:#f0fdf4;border-radius:8px;border-left:3px solid #10b981;margin-bottom:14px">
          Soft skills &amp; cultural fit <span style="font-weight:400;color:var(--muted)">(peso 40%)</span>
        </div>
        <?php $ss_fields = ['ss_ps'=>'Problem solving','ss_ct'=>'Comunicazione tecnica','ss_tw'=>'Team working / Agile','ss_la'=>'Learning agility'];
        $ss_db_map = ['ss_ps'=>'ss_score_problem_solving','ss_ct'=>'ss_score_communication','ss_tw'=>'ss_score_teamwork','ss_la'=>'ss_score_learning_agility'];
        foreach($ss_fields as $skey => $slabel): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f8fafc">
          <span style="font-size:13px;color:#334155"><?=$slabel?></span>
          <div style="display:flex;gap:4px">
            <?php for($v=1;$v<=5;$v++): $checked=($cur_sc[$ss_db_map[$skey]]??0)==$v; ?>
            <label style="cursor:pointer;font-size:20px;color:<?=$checked?'#f59e0b':'#d1d5db'?>" title="<?=$v?>">
              <input type="radio" name="<?=$skey?>" value="<?=$v?>" <?=$checked?'checked':''?> style="display:none" onchange="this.closest('.ss-row')?.querySelectorAll('label').forEach((l,i)=>{l.style.color=i<this.value?'#f59e0b':'#d1d5db'})">
              &#9733;
            </label>
            <?php endfor; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:18px">
          <div class="form-group" style="margin-bottom:12px">
            <label>Tipo colloquio</label>
            <select name="int_type">
              <option value="Tecnico" <?=($cur_sc['type']??'Tecnico')==='Tecnico'?'selected':''?>>Tecnico</option>
              <option value="HR"      <?=($cur_sc['type']??'')==='HR'     ?'selected':''?>>HR</option>
              <option value="Finale"  <?=($cur_sc['type']??'')==='Finale' ?'selected':''?>>Finale</option>
            </select>
          </div>
          <div class="form-group">
            <label>Raccomandazione</label>
            <select name="recommendation">
              <option value="proceed" <?=($cur_sc['recommendation']??'')==='proceed'?'selected':''?>>✅ Procedi con offerta</option>
              <option value="hold"    <?=($cur_sc['recommendation']??'')==='hold'   ?'selected':''?>>🔶 Riserva (2° colloquio)</option>
              <option value="reject"  <?=($cur_sc['recommendation']??'')==='reject' ?'selected':''?>>❌ Non procedere</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="form-group" style="margin-top:16px">
      <label>Commento sintetico del valutatore</label>
      <textarea name="summary_note" rows="3" placeholder="Punti di forza, aree di miglioramento, motivazione della raccomandazione..."><?=h($cur_sc['summary_note']??'')?></textarea>
    </div>

    <div style="display:flex;gap:12px;margin-top:4px">
      <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">
        <i class="fa-solid fa-floppy-disk"></i> Salva scorecard
      </button>
      <button type="submit" name="send_to_hr" value="1" class="btn btn-blue" style="flex:1;justify-content:center;padding:12px">
        <i class="fa-solid fa-paper-plane"></i> Salva e invia a HR
      </button>
    </div>
  </form>
</div>
<?php endif; endif; ?>

<!-- Modal cambio fase -->
<div id="mStage" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:380px">
    <h3 style="margin:0 0 18px;font-size:16px">Aggiorna fase candidato</h3>
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_stage">
      <input type="hidden" name="app_id" id="stage_app_id">
      <div class="form-group">
        <label>Nuova fase</label>
        <select name="stage" id="stage_sel">
          <?php foreach($stage_meta as $k=>[$l]): ?>
          <option value="<?=$k?>"><?=$l?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Salva</button>
        <button type="button" onclick="document.getElementById('mStage').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal nuovo candidato -->
<div id="mAddCand" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:680px">
    <div style="display:flex;justify-content:space-between;margin-bottom:20px">
      <h3 style="margin:0;font-size:16px">Aggiungi candidato</h3>
      <button onclick="document.getElementById('mAddCand').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_candidate">
      <div class="grid-2">
        <div class="form-group"><label>Nome *</label><input type="text" name="first_name" required></div>
        <div class="form-group"><label>Cognome *</label><input type="text" name="last_name" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email"></div>
        <div class="form-group"><label>Telefono</label><input type="text" name="phone"></div>
        <div class="form-group"><label>Posizione target</label>
          <select name="position_id">
            <option value="">—</option>
            <?php foreach($positions as $p): ?><option value="<?=$p['id']?>"><?=h($p['title'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Match score (%)</label><input type="number" name="match_score" min="0" max="100" placeholder="0–100"></div>
        <div class="form-group"><label>Fonte</label>
          <select name="source">
            <?php foreach(['Agenzia','LinkedIn','Referral','Portale','Altro'] as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Agenzia</label>
          <select name="agency_id">
            <option value="0">—</option>
            <?php foreach($agencies as $ag): ?><option value="<?=$ag['id']?>"><?=h($ag['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <?php if($can_see_sal): ?>
        <div class="form-group"><label>RAL richiesta (€)</label><input type="number" name="ral_requested" step="500"></div>
        <?php endif; ?>
        <div class="form-group"><label>Preavviso</label><input type="text" name="notice_period" placeholder="Es. 2 mesi"></div>
        <div class="form-group span-2"><label>Skills (virgola)</label><input type="text" name="skills_tags" placeholder="AWS, Terraform, Python..."></div>
        <div class="form-group span-2"><label>Note</label><textarea name="notes" rows="2"></textarea></div>
      </div>
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:14px;font-size:11px;color:#92400e">
        <i class="fa-solid fa-scale-balanced"></i> <strong>GDPR:</strong> Il consenso viene registrato con data odierna. Scadenza automatica a 12 mesi.
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">Aggiungi candidato</button>
        <button type="button" onclick="document.getElementById('mAddCand').style.display='none'" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
$('#tCand').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[3,'desc']]});
function openStageModal(appId, current) {
  document.getElementById('stage_app_id').value = appId;
  document.getElementById('stage_sel').value = current;
  document.getElementById('mStage').style.display = 'flex';
}
// Init stelle scorecard
document.querySelectorAll('input[type=radio][name^="ss_"]').forEach(r=>{
  if(r.checked){
    r.closest('div')?.querySelectorAll('label').forEach((l,i)=>{l.style.color=i<parseInt(r.value)?'#f59e0b':'#d1d5db'});
  }
  r.addEventListener('change',()=>{
    r.closest('div')?.querySelectorAll('label').forEach((l,i)=>{l.style.color=i<parseInt(r.value)?'#f59e0b':'#d1d5db'});
  });
});
</script>
<?php require_once('footer.php'); ?>
