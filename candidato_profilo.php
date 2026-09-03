<?php
/**
 * certV 2.2 — candidato_profilo.php
 * Dossier completo candidato: anagrafica, istruzione, contatti,
 * competenze, certificazioni esterne, documenti allegati, scorecard colloquio
 */
require_once('access_control.php');

$u_id     = (int)$_SESSION['user_id'];
$u_role   = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');
$can_hr   = can('delete');
$msg      = '';

$cand_id  = (int)($_GET['id'] ?? 0);
if (!$cand_id) { redirect('recruiting_candidati'); }

// ── UPLOAD DOCUMENTI ──────────────────────────────────────────────────────────
$upload_base = APP_ROOT . '/uploads/candidati/';
if (!is_dir($upload_base)) @mkdir($upload_base, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    // ── Salva anagrafica ──────────────────────────────────────────────────────
    if ($action === 'save_anagrafica') {
        try {
            $pdo->prepare(
                "UPDATE candidates SET
                    first_name=?, last_name=?, email=?, phone=?,
                    linkedin_url=?, ral_requested=?, notice_period=?,
                    skills_tags=?, source=?, agency_id=?,
                    status=?, notes=?,
                    education_level=?, education_field=?, education_institute=?,
                    education_year=?, soft_skills_notes=?
                 WHERE id=?"
            )->execute([
                trim($_POST['first_name']),
                trim($_POST['last_name']),
                trim($_POST['email'] ?? '') ?: null,
                trim($_POST['phone'] ?? '') ?: null,
                trim($_POST['linkedin_url'] ?? '') ?: null,
                $can_hr && !empty($_POST['ral_requested']) ? (float)$_POST['ral_requested'] : null,
                trim($_POST['notice_period'] ?? '') ?: null,
                trim($_POST['skills_tags'] ?? '') ?: null,
                $_POST['source'] ?? 'Altro',
                ((int)($_POST['agency_id'] ?? 0)) > 0 ? (int)$_POST['agency_id'] : null,
                $_POST['status'] ?? 'new',
                trim($_POST['notes'] ?? '') ?: null,
                trim($_POST['education_level'] ?? '') ?: null,
                trim($_POST['education_field'] ?? '') ?: null,
                trim($_POST['education_institute'] ?? '') ?: null,
                trim($_POST['education_year'] ?? '') ?: null,
                trim($_POST['soft_skills_notes'] ?? '') ?: null,
                $cand_id
            ]);
            write_log('Recruiting','success',"Aggiornato candidato #$cand_id",$u_id);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Dati salvati.</div>";
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }

    // ── Upload documento ──────────────────────────────────────────────────────
    // v1.7.38: oltre ai campi legacy (cv_path, test_path, lettera_path, doc_extra_path),
    //          scrive sempre un record in `candidate_documents` (registry storico).
    if ($action === 'upload_doc' && isset($_FILES['doc_file'])) {
        $doc_type_ui = $_POST['doc_type'] ?? 'altro';
        $allowed_types = ['cv','test_psicologico','lettera_presentazione','altro'];
        if (!in_array($doc_type_ui, $allowed_types)) $doc_type_ui = 'altro';

        // Mapping doc_type UI → enum candidate_documents
        $doc_type_db = match($doc_type_ui) {
            'cv'                    => 'cv',
            'test_psicologico'      => 'test',
            'lettera_presentazione' => 'lettera',
            default                 => 'altro',
        };

        $file  = $_FILES['doc_file'];
        $ok_ext = ['pdf','doc','docx','jpg','jpeg','png'];
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $orig_name = $file['name'];

        if ($file['error'] === UPLOAD_ERR_OK && in_array($ext, $ok_ext) && $file['size'] <= 10*1024*1024) {
            $fname = "cand_{$cand_id}_{$doc_type_ui}_" . time() . ".$ext";
            $dest  = $upload_base . $fname;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                try {
                    $pdo->beginTransaction();

                    // 1. Aggiorna campo legacy (back-compat)
                    $col = match($doc_type_ui) {
                        'cv'                    => 'cv_path',
                        'test_psicologico'      => 'test_path',
                        'lettera_presentazione' => 'lettera_path',
                        default                 => 'doc_extra_path',
                    };
                    $pdo->prepare("UPDATE candidates SET $col=? WHERE id=?")->execute([$fname, $cand_id]);

                    // 2. v1.7.38: INSERT registry centralizzato candidate_documents
                    $pdo->prepare("
                        INSERT INTO candidate_documents
                          (candidate_id, doc_type, file_path, original_filename, file_size, mime_type, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $cand_id,
                        $doc_type_db,
                        $fname,
                        $orig_name,
                        (int)$file['size'],
                        $file['type'] ?? null,
                        $u_id,
                    ]);

                    $pdo->commit();
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Documento caricato e registrato.</div>";
                    write_log('Recruiting','success',"Upload $doc_type_ui candidato #$cand_id: $orig_name",$u_id);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $msg = "<div class='alert alert-warning'>File caricato ma DB error: " . h($e->getMessage()) . "</div>";
                }
            } else {
                $msg = "<div class='alert alert-danger'>Errore nel caricamento del file.</div>";
            }
        } else {
            $msg = "<div class='alert alert-danger'>File non valido. Formati: PDF, DOC, DOCX, JPG, PNG. Max 10MB.</div>";
        }
    }

    // v1.7.38: elimina documento (soft-delete in candidate_documents)
    if ($action === 'delete_doc') {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        if ($doc_id > 0) {
            try {
                $pdo->prepare("UPDATE candidate_documents SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND candidate_id = ?")
                    ->execute([$u_id, $doc_id, $cand_id]);
                $msg = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Documento rimosso.</div>";
                write_log('Recruiting','success',"Soft-delete documento #$doc_id candidato #$cand_id",$u_id);
            } catch (Throwable $e) {
                $msg = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
            }
        }
    }


    // ── Salva scorecard ───────────────────────────────────────────────────────
    if ($action === 'save_scorecard') {
        $app_id = (int)$_POST['app_id'];
        $hs_avg = ((int)($_POST['hs_score_1']??0)+(int)($_POST['hs_score_2']??0)
                  +(int)($_POST['hs_score_3']??0)+(int)($_POST['hs_score_4']??0)) / 4;
        $ss_avg = ((int)($_POST['ss_ps']??0)+(int)($_POST['ss_ct']??0)
                  +(int)($_POST['ss_tw']??0)+(int)($_POST['ss_la']??0)) / 4;
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
            $app_id, $u_id, $_POST['interview_date'] ?: date('Y-m-d'), $_POST['int_type']??'Tecnico',
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
            push_notification('Scorecard inviata a HR', "Nuova scorecard per candidato #{$cand_id}.", 'recruiting', 'info', null, 2, "candidato_profilo.php?id={$cand_id}&tab=scorecard");
        }
        $msg = "<div class='alert alert-success'>Scorecard salvata. Score: <strong>{$total}/5</strong></div>";
    }
}

// ── LEGGI DATI CANDIDATO ──────────────────────────────────────────────────────
$cq = $pdo->prepare(
    "SELECT c.*, a.name agency_name
     FROM candidates c
     LEFT JOIN agencies a ON c.agency_id = a.id
     WHERE c.id = ?"
);
$cq->execute([$cand_id]);
$cand = $cq->fetch();
if (!$cand) { redirect('recruiting_candidati'); }

// v1.7.16: header incluso DOPO tutte le validazioni che possono richiedere redirect.
// In precedenza era a riga 8 → emetteva output prima dei redirect() → "headers already sent"
require_once('header.php');

// Candidature collegate
$apps_q = $pdo->prepare(
    "SELECT ca.*, jp.title pos_title, jp.department,
            sc.total_score, sc.recommendation, sc.hs_avg, sc.ss_avg,
            sc.summary_note, sc.sent_to_hr, sc.id sc_id, sc.interview_date, sc.type sc_type
     FROM candidate_applications ca
     JOIN job_positions jp ON ca.position_id = jp.id
     LEFT JOIN interview_scorecards sc ON sc.application_id = ca.id
     WHERE ca.candidate_id = ?
     ORDER BY ca.created_at DESC"
);
$apps_q->execute([$cand_id]);
$applications = $apps_q->fetchAll();

// Scorecard dell'ultima candidatura
$last_app  = $applications[0] ?? null;
$last_sc   = null;
if ($last_app) {
    $scq = $pdo->prepare("SELECT * FROM interview_scorecards WHERE application_id=? ORDER BY id DESC LIMIT 1");
    $scq->execute([$last_app['id']]);
    $last_sc = $scq->fetch();
}

// Agenzie per select
$agencies = $pdo->query("SELECT id,name FROM agencies WHERE status='active' ORDER BY name")->fetchAll();
$positions_open = $pdo->query("SELECT id,title FROM job_positions WHERE status='open' ORDER BY title")->fetchAll();

$active_tab = $_GET['tab'] ?? 'anagrafica';

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

// Documento helper
function doc_link(string $fname, string $label): string {
    if (!$fname) return '<span style="color:#94a3b8;font-size:12px">—</span>';
    return '<a href="download.php?file='.urlencode('candidati/'.$fname).'" target="_blank"
               style="display:inline-flex;align-items:center;gap:5px;color:#0369a1;font-size:12px;text-decoration:none;background:#e0f2fe;padding:3px 9px;border-radius:6px">
               <i class="fa-solid fa-file-arrow-down"></i> '.h($label).'</a>';
}
?>

<!-- HEADER CANDIDATO -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div style="display:flex;align-items:center;gap:16px">
    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--p),#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:800;flex-shrink:0">
      <?=strtoupper(substr($cand['first_name'],0,1).substr($cand['last_name'],0,1))?>
    </div>
    <div>
      <h1 style="font-size:20px;font-weight:800;margin-bottom:4px">
        <?=h($cand['first_name'].' '.$cand['last_name'])?>
      </h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?php if($cand['email']): ?>
        <a href="mailto:<?=h($cand['email'])?>" style="font-size:12px;color:var(--p)"><i class="fa-solid fa-envelope"></i> <?=h($cand['email'])?></a>
        <?php endif; ?>
        <?php if($cand['phone']): ?>
        <span style="font-size:12px;color:var(--muted)"><i class="fa-solid fa-phone"></i> <?=h($cand['phone'])?></span>
        <?php endif; ?>
        <?php if($cand['linkedin_url']): ?>
        <a href="<?=h($cand['linkedin_url'])?>" target="_blank" style="font-size:12px;color:#0077b5"><i class="fa-brands fa-linkedin"></i> LinkedIn</a>
        <?php endif; ?>
        <?php
          $status_colors = ['new'=>['#dbeafe','#1e40af'],'in_pipeline'=>['#ede9fe','#5b21b6'],'on_hold'=>['#fef3c7','#92400e'],'hired'=>['#d1fae5','#065f46'],'rejected'=>['#fee2e2','#991b1b'],'withdrawn'=>['#f1f5f9','#475569']];
          $status_labels = ['new'=>'Nuovo','in_pipeline'=>'In pipeline','on_hold'=>'In attesa','hired'=>'Assunto','rejected'=>'Non idoneo','withdrawn'=>'Ritirato'];
          [$sb,$sc] = $status_colors[$cand['status']] ?? ['#f1f5f9','#475569'];
        ?>
        <span style="background:<?=$sb?>;color:<?=$sc?>;padding:2px 10px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase"><?=$status_labels[$cand['status']]??$cand['status']?></span>
        <span class="badge badge-neutral" style="font-size:9px"><?=h($cand['source']??'—')?></span>
      </div>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a href="recruiting_candidati.php" class="btn btn-sm"><i class="fa-solid fa-arrow-left"></i> Pipeline</a>
    <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i></button>
  </div>
</div>

<?=$msg?>

<!-- TAB NAV -->
<div class="no-print" style="display:flex;gap:2px;margin-bottom:22px;background:#f1f5f9;border-radius:10px;padding:4px;overflow-x:auto">
  <?php foreach([
    ['anagrafica',  'fa-id-card',         'Anagrafica'],
    ['competenze',  'fa-brain',            'Competenze'],
    ['documenti',   'fa-folder-open',      'Documenti'],
    ['candidature', 'fa-briefcase',        'Candidature'],
    ['scorecard',   'fa-clipboard-list',   'Scorecard colloquio'],
  ] as [$tab,$icon,$label]): ?>
  <a href="<?= qs_self_safe(['id'=>''.($cand_id).'', 'tab'=>''.($tab).'']) ?>"
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
        <div class="form-group"><label>Nome *</label><input type="text" name="first_name" value="<?=h($cand['first_name'])?>" required></div>
        <div class="form-group"><label>Cognome *</label><input type="text" name="last_name" value="<?=h($cand['last_name'])?>" required></div>
        <div class="form-group"><label>Email</label><input type="email" name="email" value="<?=h($cand['email']??'')?>"></div>
        <div class="form-group"><label>Telefono</label><input type="text" name="phone" value="<?=h($cand['phone']??'')?>"></div>
        <div class="form-group span-2"><label>LinkedIn</label><input type="url" name="linkedin_url" value="<?=h($cand['linkedin_url']??'')?>" placeholder="https://linkedin.com/in/..."></div>
        <div class="form-group"><label>Fonte</label>
          <select name="source">
            <?php foreach(['Agenzia','LinkedIn','Referral','Portale','Altro'] as $s): ?>
            <option value="<?=$s?>" <?=($cand['source']??'')===$s?'selected':''?>><?=$s?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Agenzia</label>
          <select name="agency_id">
            <option value="0">—</option>
            <?php foreach($agencies as $ag): ?>
            <option value="<?=$ag['id']?>" <?=($cand['agency_id']==$ag['id'])?'selected':''?>><?=h($ag['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Stato candidato</label>
          <select name="status">
            <?php foreach(['new'=>'Nuovo','in_pipeline'=>'In pipeline','on_hold'=>'In attesa','hired'=>'Assunto','rejected'=>'Non idoneo','withdrawn'=>'Ritirato'] as $v=>$l): ?>
            <option value="<?=$v?>" <?=($cand['status']===$v)?'selected':''?>><?=$l?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if($can_hr): ?>
        <div class="form-group"><label>RAL richiesta (€)</label>
          <input type="number" name="ral_requested" value="<?=$cand['ral_requested']??''?>" step="500" placeholder="Es. 45000">
        </div>
        <?php endif; ?>
        <div class="form-group span-2"><label>Preavviso</label>
          <input type="text" name="notice_period" value="<?=h($cand['notice_period']??'')?>" placeholder="Es. 1 mese, immediato...">
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
          <option value="<?=$v?>" <?=($cand['education_level']??'')===$v?'selected':''?>><?=$l?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Indirizzo / facoltà</label>
        <input type="text" name="education_field" value="<?=h($cand['education_field']??'')?>" placeholder="Es. Ingegneria Informatica, Economia...">
      </div>
      <div class="form-group">
        <label>Istituto / Università</label>
        <input type="text" name="education_institute" value="<?=h($cand['education_institute']??'')?>" placeholder="Es. Politecnico di Milano">
      </div>
      <div class="form-group">
        <label>Anno conseguimento</label>
        <input type="text" name="education_year" value="<?=h($cand['education_year']??'')?>" placeholder="Es. 2019">
      </div>
      <div class="form-group" style="margin:0">
        <label>Note aggiuntive su formazione</label>
        <textarea name="soft_skills_notes" rows="3" placeholder="Corsi extra, certificazioni, note su percorso formativo..."><?=h($cand['soft_skills_notes']??'')?></textarea>
      </div>
    </div>

    <!-- Note HR (full width) -->
    <div class="card" style="grid-column:span 2">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-lines" style="color:var(--muted)"></i> Note riservate HR</span></div>
      <textarea name="notes" rows="3" placeholder="Note interne — non visibili al candidato"><?=h($cand['notes']??'')?></textarea>
    </div>
  </div>

  <div style="margin-top:16px;display:flex;gap:12px">
    <button type="submit" class="btn btn-primary" style="padding:12px 28px"><i class="fa-solid fa-floppy-disk"></i> Salva anagrafica</button>
    <?php if($can_hr): ?>
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 16px;font-size:11px;color:#92400e;display:flex;align-items:center;gap:8px">
      <i class="fa-solid fa-scale-balanced"></i>
      <span><strong>GDPR:</strong> consenso <?=$cand['gdpr_consent']?'✅ acquisito':'⚠ mancante'?>
        <?php if($cand['gdpr_expiry']): ?> · scadenza <?=format_date($cand['gdpr_expiry'])?><?php endif; ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</form>

<!-- ══ TAB: COMPETENZE ═══════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'competenze'): ?>
<form method="POST">
        <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_anagrafica">
  <!-- include tutti i campi hidden necessari -->
  <input type="hidden" name="first_name" value="<?=h($cand['first_name'])?>">
  <input type="hidden" name="last_name"  value="<?=h($cand['last_name'])?>">
  <input type="hidden" name="email"      value="<?=h($cand['email']??'')?>">
  <input type="hidden" name="phone"      value="<?=h($cand['phone']??'')?>">
  <input type="hidden" name="linkedin_url" value="<?=h($cand['linkedin_url']??'')?>">
  <input type="hidden" name="ral_requested" value="<?=$cand['ral_requested']??''?>">
  <input type="hidden" name="notice_period" value="<?=h($cand['notice_period']??'')?>">
  <input type="hidden" name="source"     value="<?=h($cand['source']??'Altro')?>">
  <input type="hidden" name="agency_id"  value="<?=$cand['agency_id']??0?>">
  <input type="hidden" name="status"     value="<?=h($cand['status']??'new')?>">
  <input type="hidden" name="notes"      value="<?=h($cand['notes']??'')?>">
  <input type="hidden" name="education_level"    value="<?=h($cand['education_level']??'')?>">
  <input type="hidden" name="education_field"    value="<?=h($cand['education_field']??'')?>">
  <input type="hidden" name="education_institute" value="<?=h($cand['education_institute']??'')?>">
  <input type="hidden" name="education_year"     value="<?=h($cand['education_year']??'')?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Competenze tecniche -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-microchip" style="color:var(--p)"></i> Competenze tecniche</span></div>
      <div class="form-group" style="margin:0">
        <label>Skill tags (separate da virgola)</label>
        <textarea name="skills_tags" rows="5" placeholder="Es. PHP, MySQL, AWS, Docker, Kubernetes, Python..."><?=h($cand['skills_tags']??'')?></textarea>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">
          <?php foreach(array_filter(array_map('trim', explode(',', $cand['skills_tags']??''))) as $sk): ?>
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
      $sel_soft = array_filter(array_map('trim', explode(',', $cand['skills_tags']??'')));
      ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <?php foreach($soft_items as $sk): ?>
        <label style="cursor:pointer;user-select:none">
          <input type="checkbox" name="soft_check[]" value="<?=h($sk)?>" style="display:none" class="soft-cb">
          <span class="soft-tag" data-val="<?=h($sk)?>" style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;border:1px solid var(--border);background:#f8fafc;color:var(--muted);cursor:pointer;transition:.12s">
            <?=h($sk)?>
          </span>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="form-group" style="margin:0">
        <label>Note soft skills / valutazione carattere</label>
        <textarea name="soft_skills_notes" rows="4" placeholder="Impressioni su comunicazione, leadership, motivazione, cultural fit..."><?=h($cand['soft_skills_notes']??'')?></textarea>
      </div>
    </div>

    <!-- Certificazioni esterne dichiarate -->
    <div class="card" style="grid-column:span 2">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-certificate" style="color:var(--p)"></i> Certificazioni dichiarate dal candidato</span>
        <span style="font-size:11px;color:var(--muted)">Inserire le cert. che il candidato dichiara di possedere (da verificare con i documenti)</span>
      </div>
      <div id="cert-list">
        <?php
        $certs_raw = $cand['external_certs'] ?? '';
        $certs_arr = $certs_raw ? json_decode($certs_raw, true) : [];
        if (empty($certs_arr)) $certs_arr = [['name'=>'','vendor'=>'','year'=>'','code'=>'']];
        foreach ($certs_arr as $i => $cr): ?>
        <div class="cert-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;margin-bottom:10px;align-items:center">
          <input type="text" name="cert_name[]" value="<?=h($cr['name']??'')?>" placeholder="Nome certificazione" style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
          <input type="text" name="cert_vendor[]" value="<?=h($cr['vendor']??'')?>" placeholder="Vendor (es. AWS)" style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
          <input type="text" name="cert_year[]" value="<?=h($cr['year']??'')?>" placeholder="Anno" style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
          <input type="text" name="cert_code[]" value="<?=h($cr['code']??'')?>" placeholder="Codice / ID" style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
          <button type="button" onclick="this.closest('.cert-row').remove()" style="background:#fee2e2;border:none;border-radius:6px;padding:7px 10px;cursor:pointer;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" onclick="addCertRow()" class="btn btn-sm" style="margin-top:4px"><i class="fa-solid fa-plus"></i> Aggiungi certificazione</button>
    </div>
  </div>

  <div style="margin-top:16px">
    <button type="submit" class="btn btn-primary" style="padding:12px 28px"><i class="fa-solid fa-floppy-disk"></i> Salva competenze</button>
  </div>
</form>

<!-- ══ TAB: DOCUMENTI ════════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'documenti'): ?>

<?php
// v5.02.05: carica TUTTI i documenti del candidato dalla tabella moderna person_documents
$docs_q = $pdo->prepare(
    "SELECT pd.*,
            CONCAT(COALESCE(eu.first_name,''), ' ', COALESCE(eu.last_name,'')) AS uploaded_by_name
       FROM person_documents pd
       LEFT JOIN users u ON u.id = pd.uploaded_by
       LEFT JOIN employees eu ON eu.id = u.employee_id
      WHERE pd.candidate_id = ?
      ORDER BY pd.created_at DESC, pd.id DESC"
);
$docs_q->execute([$cand_id]);
$cand_documents = $docs_q->fetchAll();

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
    <strong><?= count($cand_documents) ?> documento/i</strong> nell'archivio del candidato
  </div>
  <a href="documenti.php?candidate_id=<?=$cand_id?>" class="btn btn-sm" style="background:#1e40af;color:#fff;border:none">
    <i class="fa-solid fa-arrow-right"></i> Apri archivio completo
  </a>
</div>

<!-- ════ ELENCO DOCUMENTI CARICATI (person_documents) ════════════════════════ -->
<?php if (empty($cand_documents)): ?>
  <div style="background:#fff;border:1px dashed var(--border);border-radius:10px;padding:40px;text-align:center;color:var(--muted);margin-bottom:18px">
    <i class="fa-solid fa-folder-open" style="font-size:32px;opacity:.4;margin-bottom:10px"></i>
    <p style="margin:0">Nessun documento caricato per questo candidato.</p>
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
        <?php foreach ($cand_documents as $doc): ?>
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
                  <input type="hidden" name="redirect" value="candidato_profilo.php?id=<?=$cand_id?>&tab=documenti">
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
  // v1.7.38: carico TUTTI i documenti dalla nuova tabella candidate_documents
  // Mantengo i 4 slot legacy (cv_path, test_path, ...) per back-compat ma la fonte
  // primaria di visualizzazione è ora candidate_documents (registry storico)
  $docs_q = $pdo->prepare("
      SELECT cd.*, COALESCE(u.display_name, SUBSTRING_INDEX(u.email,'@',1), CONCAT('User#',u.id)) AS uploader_name
        FROM candidate_documents cd
        LEFT JOIN users u ON cd.uploaded_by = u.id
       WHERE cd.candidate_id = ? AND cd.deleted_at IS NULL
       ORDER BY cd.doc_type, cd.uploaded_at DESC
  ");
  $docs_q->execute([$cand_id]);
  $all_docs = $docs_q->fetchAll();
  // Raggruppo per tipo
  $docs_by_type = ['cv' => [], 'test' => [], 'lettera' => [], 'altro' => []];
  foreach ($all_docs as $d) $docs_by_type[$d['doc_type']][] = $d;

  $slots = [
    ['cv',                    'cv',      'fa-file-user',     '#0ea5e9', 'Curriculum Vitae',        'PDF, DOC, DOCX'],
    ['test_psicologico',      'test',    'fa-brain',         '#8b5cf6', 'Test psicologico',        'PDF, JPG, PNG'],
    ['lettera_presentazione', 'lettera', 'fa-envelope-open', '#f59e0b', 'Lettera di presentazione','PDF, DOC'],
    ['altro',                 'altro',   'fa-file-circle-plus','#10b981','Documenti aggiuntivi',   'PDF, DOC, JPG'],
  ];
  foreach($slots as [$dtype_ui, $dtype_db, $dicon, $dcolor, $dlabel, $dfmt]):
    $files_in_slot = $docs_by_type[$dtype_db] ?? [];
  ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title">
        <i class="fa-solid <?=$dicon?>" style="color:<?=$dcolor?>"></i> <?=$dlabel?>
        <?php if(!empty($files_in_slot)): ?>
        <span style="background:<?=$dcolor?>15;color:<?=$dcolor?>;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;margin-left:6px"><?=count($files_in_slot)?></span>
        <?php endif; ?>
      </span>
    </div>

    <?php if(!empty($files_in_slot)): ?>
    <div style="margin-bottom:14px">
      <?php foreach($files_in_slot as $idx => $d):
        $is_latest = $idx === 0;
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:<?= $is_latest ? '#f0fdf4' : '#f8fafc' ?>;border:1px solid <?= $is_latest ? '#bbf7d0' : '#e2e8f0' ?>;border-radius:6px;margin-bottom:6px;font-size:12px">
        <i class="fa-solid fa-file" style="color:<?=$dcolor?>;font-size:16px"></i>
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h($d['original_filename'] ?? basename($d['file_path'])) ?>
            <?php if ($is_latest): ?><span style="color:#16a34a;font-size:9px;margin-left:4px;font-weight:700">● ULTIMO</span><?php endif; ?>
          </div>
          <div style="color:#64748b;font-size:10px;margin-top:2px">
            <?= h(date('d/m/Y H:i', strtotime($d['uploaded_at']))) ?>
            <?php if ($d['uploader_name']): ?> · da <?= h($d['uploader_name']) ?><?php endif; ?>
            <?php if ($d['file_size']): ?> · <?= number_format($d['file_size']/1024, 0, ',', '.') ?> KB<?php endif; ?>
          </div>
        </div>
        <?= doc_link($d['file_path'], $d['original_filename'] ?? $dlabel) ?>
        <?php if($can_edit): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere questo documento dal registro?\n(Il file resta sul server, ma non sarà più visibile)')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_doc">
          <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
          <button type="submit" class="btn btn-sm" title="Rimuovi dal registro" style="background:#fee2e2;color:#991b1b;border:0;padding:4px 8px">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="background:#f8fafc;border:1px dashed var(--border);border-radius:8px;padding:14px;margin-bottom:14px;text-align:center;font-size:12px;color:var(--muted)">
      <i class="fa-solid fa-cloud-arrow-up" style="font-size:24px;display:block;margin-bottom:6px;opacity:.4"></i>
      Nessun documento caricato
    </div>
    <?php endif; ?>

    <?php if($can_edit): ?>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_doc">
      <input type="hidden" name="doc_type" value="<?=$dtype_ui?>">
      <div style="display:flex;gap:8px;align-items:center">
        <input type="file" name="doc_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required
               style="flex:1;font-size:12px;padding:6px;border:1px solid var(--border);border-radius:7px;background:#fff">
        <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap">
          <i class="fa-solid fa-upload"></i> Carica
        </button>
      </div>
      <div style="font-size:10px;color:var(--muted);margin-top:4px">Formati: <?=$dfmt?> · Max 10 MB · I documenti precedenti restano nel registro</div>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ TAB: CANDIDATURE ══════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'candidature'): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-briefcase" style="color:var(--p)"></i> Candidature (<?=count($applications)?>)</span>
    <?php if($can_edit): ?>
    <button onclick="document.getElementById('mNewApp').style.display='flex'" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-plus"></i> Nuova candidatura
    </button>
    <?php endif; ?>
  </div>

  <?php if(empty($applications)): ?>
  <div style="text-align:center;padding:40px;color:var(--muted)">
    <i class="fa-solid fa-briefcase" style="font-size:32px;opacity:.3;display:block;margin-bottom:10px"></i>
    Nessuna candidatura associata a questo candidato.
  </div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="data-table">
    <thead><tr>
      <th>Posizione</th><th>Fase</th><th>Match</th>
      <th>Scorecard</th><th>Data</th><th class="no-print">Azioni</th>
    </tr></thead>
    <tbody>
    <?php
    // v1.7.38: definizione anticipata (usata sia per cambio stage che per "Assumi")
    $already_hired = ($cand['status'] ?? '') === 'hired' && !empty($cand['converted_to_employee_id']);
    foreach($applications as $app):
      [$sl,$sc_] = $stage_meta[$app['stage']] ?? [$app['stage'],'#94a3b8'];
    ?>
    <tr>
      <td>
        <div style="font-weight:600"><?=h($app['pos_title'])?></div>
        <div style="font-size:11px;color:var(--muted)"><?=h($app['department']??'')?></div>
      </td>
      <td><span style="background:<?=$sc_?>22;color:<?=$sc_?>;border:1px solid <?=$sc_?>44;border-radius:20px;padding:3px 9px;font-size:10px;font-weight:700"><?=$sl?></span>
        <?php if($can_edit && !$already_hired): ?>
        <!-- v1.7.38: cambio stage inline -->
        <form method="POST" action="recruiting_candidati.php" style="display:inline-block;margin-top:4px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_application">
          <input type="hidden" name="candidate_id" value="<?=$cand_id?>">
          <input type="hidden" name="position_id" value="<?=(int)$app['position_id']?>">
          <input type="hidden" name="match_score" value="<?=(int)($app['match_score'] ?? 0)?>">
          <input type="hidden" name="redirect" value="candidato_profilo.php?id=<?=$cand_id?>&tab=candidature">
          <select name="stage" onchange="this.form.submit()" style="font-size:10px;padding:2px 4px;border:1px solid var(--border);border-radius:4px;background:#fff">
            <?php foreach(['cv_received'=>'CV ricevuto','screening'=>'Screening','tech_test'=>'Test tecnico','hr_interview'=>'Colloquio HR','tech_interview'=>'Colloquio tecnico','offer_sent'=>'Offerta inviata'] as $sv=>$sl2): ?>
            <option value="<?=$sv?>" <?=$app['stage']===$sv?'selected':''?>><?=$sl2?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </td>
      <td>
        <?php if($app['match_score']): ?>
        <?php $mc=$app['match_score']>=80?'var(--success)':($app['match_score']>=60?'var(--warning)':'var(--danger)'); ?>
        <span style="font-weight:800;color:<?=$mc?>"><?=$app['match_score']?>%</span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td>
        <?php if($app['total_score']): ?>
        <div style="font-weight:700;color:<?=$app['total_score']>=4?'var(--success)':($app['total_score']>=3?'var(--warning)':'var(--danger)')?>"><?=number_format($app['total_score'],1)?>/5</div>
        <?php if($app['sent_to_hr']): ?><span class="badge badge-info" style="font-size:9px">Inviata HR</span><?php endif; ?>
        <?php else: ?><span style="color:var(--muted);font-size:12px">Non compilata</span><?php endif; ?>
      </td>
      <td style="font-size:12px;color:var(--muted)"><?=format_date($app['created_at'])?></td>
      <td class="no-print">
        <a href="<?= qs_self_safe(['id'=>''.($cand_id).'', 'tab'=>'scorecard', 'app_id'=>''.($app['id']).'']) ?>" class="btn btn-blue btn-sm" title="Scorecard">
          <i class="fa-solid fa-clipboard-list"></i>
        </a>
        <?php
          // v1.7.32: bottone Assumi se stage avanzato + candidato non già assunto
          $can_hire_stages = ['hr_interview','tech_interview','offer_sent'];
        ?>
        <?php if (in_array($app['stage'], $can_hire_stages, true) && !$already_hired && can('create','candidate_hire.php')): ?>
        <a href="<?= url_safe('candidate_hire', ['candidate_id' => (int)$cand_id, 'position_id' => (int)$app['position_id']]) ?>"
           class="btn btn-sm" title="Assumi → Crea dipendente"
           style="background:#16a34a;color:#fff">
          <i class="fa-solid fa-user-check"></i> Assumi
        </a>
        <?php elseif ($already_hired && (int)$app['position_id'] === (int)$cand['hired_position_id'] ?? 0): ?>
        <span class="badge badge-success" style="font-size:10px">
          <i class="fa-solid fa-check"></i> Assunto
        </span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- Modal nuova candidatura -->
<div id="mNewApp" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:420px">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px">
      <h3 style="margin:0;font-size:16px">Collega a posizione</h3>
      <button onclick="closeModal('mNewApp')" style="border:none;background:none;font-size:20px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <form method="POST" action="recruiting_candidati.php">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_application">
      <input type="hidden" name="candidate_id" value="<?=$cand_id?>">
      <input type="hidden" name="redirect" value="candidato_profilo.php?id=<?=$cand_id?>&tab=candidature">
      <div class="form-group">
        <label>Posizione aperta</label>
        <select name="position_id" required>
          <option value="">Seleziona...</option>
          <?php foreach($positions_open as $p): ?>
          <option value="<?=$p['id']?>"><?=h($p['title'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Stage iniziale</label>
        <select name="stage" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px">
          <option value="cv_received">CV ricevuto</option>
          <option value="screening">Screening</option>
          <option value="tech_test">Test tecnico</option>
          <option value="hr_interview">Colloquio HR</option>
          <option value="tech_interview">Colloquio tecnico</option>
          <option value="offer_sent">Offerta inviata</option>
        </select>
      </div>
      <div class="form-group">
        <label>Match score (%)</label>
        <input type="number" name="match_score" min="0" max="100" placeholder="0–100">
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:11px">Collega</button>
        <button type="button" onclick="closeModal('mNewApp')" class="btn" style="flex:1;justify-content:center;padding:11px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ TAB: SCORECARD ════════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'scorecard'): ?>
<?php
$sc_app_id = (int)($_GET['app_id'] ?? ($last_app['id'] ?? 0));
$sc_app    = null;
$sc        = null;
if ($sc_app_id) {
    $sq = $pdo->prepare("SELECT ca.*,jp.title pos_title FROM candidate_applications ca JOIN job_positions jp ON ca.position_id=jp.id WHERE ca.id=? AND ca.candidate_id=?");
    $sq->execute([$sc_app_id, $cand_id]);
    $sc_app = $sq->fetch();
    if ($sc_app) {
        $scq2 = $pdo->prepare("SELECT * FROM interview_scorecards WHERE application_id=? ORDER BY id DESC LIMIT 1");
        $scq2->execute([$sc_app_id]);
        $sc = $scq2->fetch();
    }
}
?>

<?php if(empty($applications)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
  <i class="fa-solid fa-clipboard-list" style="font-size:40px;opacity:.3;display:block;margin-bottom:12px"></i>
  Associa prima il candidato a una posizione nella tab Candidature.
</div>
<?php else: ?>

<?php if(count($applications) > 1): ?>
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach($applications as $app): ?>
  <a href="<?= qs_self_safe(['id'=>''.($cand_id).'', 'tab'=>'scorecard', 'app_id'=>''.($app['id']).'']) ?>"
     style="padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;
            <?=$sc_app_id==$app['id']?'background:var(--p);color:#fff':'background:#f1f5f9;color:var(--muted)'?>">
    <?=h($app['pos_title'])?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if($sc): ?>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:18px 24px;margin-bottom:20px;display:flex;align-items:center;gap:24px;flex-wrap:wrap">
  <div style="text-align:center;min-width:80px">
    <div style="font-size:40px;font-weight:800;line-height:1;color:<?=$sc['total_score']>=4?'var(--success)':($sc['total_score']>=3?'var(--warning)':'var(--danger)')?>"><?=number_format($sc['total_score'],1)?></div>
    <div style="font-size:10px;color:var(--muted);font-weight:700">/ 5.0</div>
  </div>
  <div>
    <div style="font-weight:700;font-size:14px;margin-bottom:4px">Score pesato: 60% Hard Skills + 40% Soft Skills</div>
    <div style="font-size:12px;color:var(--muted)">HS: <?=number_format($sc['hs_avg'],2)?>/5 · SS: <?=number_format($sc['ss_avg'],2)?>/5</div>
    <div style="font-size:11px;color:var(--muted);margin-top:3px">
      Compilata: <?=format_date($sc['created_at'],'d/m/Y H:i')?> · Tipo: <?=h($sc['type'])?>
      <?php if($sc['sent_to_hr']): ?> · <span class="badge badge-info" style="font-size:9px">Inviata a HR</span><?php endif; ?>
    </div>
  </div>
  <?php
    $rec_map = ['proceed'=>['✅ Procedi','badge-success'],'hold'=>['🔶 Riserva','badge-warning'],'reject'=>['❌ Non idoneo','badge-danger']];
    [$rl,$rb] = $rec_map[$sc['recommendation']??''] ?? ['—','badge-neutral'];
  ?>
  <div style="margin-left:auto"><span class="badge <?=$rb?>" style="font-size:11px;padding:5px 14px"><?=$rl?></span></div>
</div>
<?php endif; ?>

<?php if($sc_app): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-clipboard-list" style="color:var(--p)"></i>
      Scorecard — <?=h($sc_app['pos_title'])?>
    </span>
  </div>
  <form method="POST">
        <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_scorecard">
    <input type="hidden" name="app_id"  value="<?=$sc_app_id?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
      <!-- Hard Skills -->
      <div>
        <div style="font-weight:700;font-size:13px;padding:10px 14px;background:#eff6ff;border-radius:8px;border-left:3px solid var(--info);margin-bottom:16px">
          Competenze tecniche <span style="font-weight:400;color:var(--muted);font-size:12px">(peso 60%)</span>
        </div>
        <?php for($n=1;$n<=4;$n++): ?>
        <div style="margin-bottom:16px;padding:12px;background:#f8fafc;border-radius:8px">
          <div style="display:flex;gap:10px;margin-bottom:7px;align-items:center">
            <input type="text" name="hs_label_<?=$n?>" value="<?=h($sc["hs_label_$n"]??'Competenza '.$n)?>"
                   style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-weight:600">
            <select name="hs_score_<?=$n?>" style="width:75px;padding:7px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-weight:700">
              <?php for($v=0;$v<=5;$v++): ?>
              <option value="<?=$v?>" <?=($sc["hs_score_$n"]??0)==$v?'selected':''?>><?=$v?> ★</option>
              <?php endfor; ?>
            </select>
          </div>
          <textarea name="hs_note_<?=$n?>" rows="2" placeholder="Dettagli / osservazioni..."
                    style="width:100%;padding:7px;border:1px solid var(--border);border-radius:6px;font-size:11px;resize:none"><?=h($sc["hs_note_$n"]??'')?></textarea>
        </div>
        <?php endfor; ?>
      </div>

      <!-- Soft Skills -->
      <div>
        <div style="font-weight:700;font-size:13px;padding:10px 14px;background:#f0fdf4;border-radius:8px;border-left:3px solid var(--success);margin-bottom:16px">
          Soft skills &amp; cultural fit <span style="font-weight:400;color:var(--muted);font-size:12px">(peso 40%)</span>
        </div>
        <?php $ss_fields = [
          'ss_ps' => ['ss_score_problem_solving', 'Problem solving',        'fa-puzzle-piece'],
          'ss_ct' => ['ss_score_communication',   'Comunicazione',          'fa-comments'],
          'ss_tw' => ['ss_score_teamwork',         'Team working / Agile',  'fa-people-group'],
          'ss_la' => ['ss_score_learning_agility', 'Learning agility',      'fa-lightbulb'],
        ];
        foreach($ss_fields as $skey => [$dbcol, $slabel, $sicon]): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 14px;margin-bottom:8px;background:#f8fafc;border-radius:8px">
          <div style="display:flex;align-items:center;gap:8px;font-size:13px">
            <i class="fa-solid <?=$sicon?>" style="width:16px;color:var(--muted)"></i>
            <?=$slabel?>
          </div>
          <div class="stars-row" style="display:flex;gap:3px">
            <?php for($v=1;$v<=5;$v++):
              $checked = ($sc[$dbcol]??0) >= $v;
            ?>
            <label style="cursor:pointer;font-size:22px;color:<?=$checked?'#f59e0b':'#d1d5db'?>;line-height:1;transition:.1s">
              <input type="radio" name="<?=$skey?>" value="<?=$v?>" <?=($sc[$dbcol]??0)==$v?'checked':''?> style="display:none">
              ★
            </label>
            <?php endfor; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:16px">
          <div class="grid-2" style="gap:12px;margin-bottom:12px">
            <div class="form-group" style="margin:0">
              <label>Data colloquio</label>
              <input type="date" name="interview_date" value="<?=$sc['interview_date']??date('Y-m-d')?>">
            </div>
            <div class="form-group" style="margin:0">
              <label>Tipo colloquio</label>
              <select name="int_type">
                <?php foreach(['Tecnico','HR','Finale'] as $it): ?>
                <option value="<?=$it?>" <?=($sc['type']??'Tecnico')===$it?'selected':''?>><?=$it?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Raccomandazione</label>
            <select name="recommendation" style="font-size:14px;font-weight:600">
              <option value="proceed" <?=($sc['recommendation']??'')==='proceed'?'selected':''?>>✅ Procedi con offerta</option>
              <option value="hold"    <?=($sc['recommendation']??'')==='hold'   ?'selected':''?>>🔶 Riserva (2° colloquio)</option>
              <option value="reject"  <?=($sc['recommendation']??'')==='reject' ?'selected':''?>>❌ Non procedere</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="form-group" style="margin-top:16px">
      <label>Commento del valutatore</label>
      <textarea name="summary_note" rows="4" placeholder="Punti di forza, aree di miglioramento, motivazione della raccomandazione, impressioni generali..."><?=h($sc['summary_note']??'')?></textarea>
    </div>

    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary" style="flex:1;min-width:200px;justify-content:center;padding:13px">
        <i class="fa-solid fa-floppy-disk"></i> Salva scorecard
      </button>
      <button type="submit" name="send_to_hr" value="1" class="btn btn-blue" style="flex:1;min-width:200px;justify-content:center;padding:13px">
        <i class="fa-solid fa-paper-plane"></i> Salva e invia a HR
      </button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
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

// ── Aggiungi riga certificazione ─────────────────────────────
function addCertRow() {
  const list = document.getElementById('cert-list');
  const row  = document.createElement('div');
  row.className = 'cert-row';
  row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;margin-bottom:10px;align-items:center';
  row.innerHTML = `
    <input type="text" name="cert_name[]"   placeholder="Nome certificazione" style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
    <input type="text" name="cert_vendor[]" placeholder="Vendor"  style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
    <input type="text" name="cert_year[]"   placeholder="Anno"    style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
    <input type="text" name="cert_code[]"   placeholder="Codice"  style="padding:8px;border:1px solid var(--border);border-radius:7px;font-size:12px">
    <button type="button" onclick="this.closest('.cert-row').remove()" style="background:#fee2e2;border:none;border-radius:6px;padding:7px 10px;cursor:pointer;color:#991b1b"><i class="fa-solid fa-trash"></i></button>
  `;
  list.appendChild(row);
}
</script>

<?php require_once('footer.php'); ?>
