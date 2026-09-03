<?php
/**
 * certV 2.4 — documenti.php
 * Gestione documenti unificata candidati + dipendenti
 * Controllo accesso per tipo documento e ruolo
 * I documenti seguono il candidato se diventa dipendente
 *
 * Parametri GET: ?candidate_id=X  oppure  ?employee_id=X  oppure  entrambi vuoti = vista globale
 */
require_once('access_control.php');
require_once('functions.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$u_emp  = (int)($_SESSION['employee_id'] ?? 0);

$upload_base = __DIR__ . '/uploads/documenti/';
if (!is_dir($upload_base)) @mkdir($upload_base, 0755, true);

// Auto-migrate
try { $pdo->query("SELECT id FROM person_documents LIMIT 0")->closeCursor(); }
catch (\Exception $e) {
    $mf = __DIR__ . '/migration_documents.sql';
    if (file_exists($mf)) {
        foreach (explode(";", file_get_contents($mf)) as $s) {
            $s = trim($s); if (!$s || strpos($s,'--')===0 || preg_match('/^\s*(SELECT|SHOW)/i',$s)) continue;
            try { $pdo->exec($s); } catch (\Exception $ex) {}
        }
    }
}

// ── Tipi documento con metadati ─────────────────────────────────────────────
$DOC_TYPES = [
    'cv'                    => ['Curriculum Vitae',          'fa-file-user',     '#0ea5e9'],
    'lettera_presentazione' => ['Lettera di presentazione',  'fa-envelope-open', '#8b5cf6'],
    'note_selezione'        => ['Note selezione',            'fa-clipboard',     '#f59e0b'],
    'test_tecnico'          => ['Test tecnico',              'fa-code',          '#059669'],
    'test_psicologico'      => ['Test psicologico',          'fa-brain',         '#dc2626'],
    'valutazione'           => ['Scheda valutazione',        'fa-star-half-stroke','#d97706'],
    'contratto'             => ['Contratto',                 'fa-file-signature','#475569'],
    'certificato_formazione'=> ['Certificato formazione',    'fa-award',         '#6366f1'],
    'documento_identita'    => ['Documento identità',        'fa-id-card',       '#0284c7'],
    'altro'                 => ['Altro documento',           'fa-file',          '#94a3b8'],
];

// ── Carica matrice accesso per il ruolo corrente ─────────────────────────────
$access = [];
try {
    $aq = $pdo->prepare("SELECT doc_type, can_view, can_download, can_upload, can_delete FROM document_access_rules WHERE role_id=?");
    $aq->execute([$u_role]);
    foreach ($aq->fetchAll() as $r) $access[$r['doc_type']] = $r;
} catch (\Exception $e) {}

function canDo(string $docType, string $action, array $access, int $role): bool {
    if ($role <= 1) return true; // Super Admin sempre
    return (int)($access[$docType]["can_$action"] ?? 0) === 1;
}

// ── Contesto: candidato o dipendente ─────────────────────────────────────────
$cand_id = (int)($_GET['candidate_id'] ?? 0);
$emp_id  = (int)($_GET['employee_id'] ?? 0);
$msg = '';

// Dipendente vede solo i propri documenti
if ($u_role >= 6) {
    $emp_id = $u_emp;
    $cand_id = 0;
}

// ── CRUD (prima di header.php per redirect) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Upload documento ─────────────────────────────────────
    if ($action === 'upload' && isset($_FILES['doc_file'])) {
        $doc_type = $_POST['doc_type'] ?? 'altro';
        if (!isset($DOC_TYPES[$doc_type])) $doc_type = 'altro';

        if (!canDo($doc_type, 'upload', $access, $u_role)) {
            $msg = "<div class='alert alert-danger'>Non hai i permessi per caricare documenti di tipo '{$DOC_TYPES[$doc_type][0]}'.</div>";
        } else {
            $file = $_FILES['doc_file'];
            $ok_ext = ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','zip'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($file['error'] === UPLOAD_ERR_OK && in_array($ext, $ok_ext) && $file['size'] <= 15*1024*1024) {
                $target_cand = (int)($_POST['candidate_id'] ?? 0) ?: null;
                $target_emp  = (int)($_POST['employee_id'] ?? 0) ?: null;
                $prefix = $target_cand ? "cand_{$target_cand}" : "emp_{$target_emp}";
                $fname = "{$prefix}_{$doc_type}_" . time() . ".$ext";

                if (move_uploaded_file($file['tmp_name'], $upload_base . $fname)) {
                    $pdo->prepare(
                        "INSERT INTO person_documents
                         (candidate_id, employee_id, doc_type, file_name, original_name, file_size, mime_type,
                          title, compilation_date, notes, visibility, min_role_view, min_role_download, uploaded_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([
                        $target_cand, $target_emp, $doc_type, $fname, $file['name'],
                        $file['size'], $file['type'],
                        trim($_POST['title'] ?? '') ?: $DOC_TYPES[$doc_type][0],
                        !empty($_POST['compilation_date']) ? $_POST['compilation_date'] : null,
                        trim($_POST['doc_notes'] ?? '') ?: null,
                        $_POST['visibility'] ?? 'restricted',
                        (int)($_POST['min_role_view'] ?? 2),
                        (int)($_POST['min_role_download'] ?? 2),
                        $u_id
                    ]);
                    write_log('Documenti', 'success', "Upload $doc_type per " . ($target_cand ? "candidato #$target_cand" : "dipendente #$target_emp"), $u_id);
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Documento caricato.</div>";
                } else {
                    $msg = "<div class='alert alert-danger'>Errore nel caricamento del file.</div>";
                }
            } else {
                $msg = "<div class='alert alert-danger'>File non valido. Formati: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP. Max 15MB.</div>";
            }
        }
    }

    // ── Elimina documento ─────────────────────────────────────
    if ($action === 'delete') {
        $doc_id = (int)$_POST['doc_id'];
        $doc = $pdo->prepare("SELECT * FROM person_documents WHERE id=?");
        $doc->execute([$doc_id]); $d = $doc->fetch(); $doc->closeCursor();
        if ($d && canDo($d['doc_type'], 'delete', $access, $u_role)) {
            @unlink($upload_base . $d['file_name']);
            $pdo->prepare("DELETE FROM person_documents WHERE id=?")->execute([$doc_id]);
            write_log('Documenti', 'info', "Eliminato doc #{$doc_id} ({$d['doc_type']})", $u_id);
            // v5.02.05: supporta redirect post-delete (es. tornare al profilo candidato)
            if (!empty($_POST['redirect'])) {
                $redir = trim((string)$_POST['redirect']);
                // Sicurezza: solo path relativi, no URL esterni
                if (preg_match('#^[a-z_]+\.php(\?[^"]*)?$#', $redir)) {
                    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Documento eliminato.</div>";
                    header('Location: ' . $redir);
                    exit();
                }
            }
            $msg = "<div class='alert alert-success'>Documento eliminato.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Permesso negato.</div>";
        }
    }

    // ── Trasferisci documenti candidato → dipendente ─────────
    if ($action === 'transfer_to_employee' && $u_role <= 2) {
        $from_cand = (int)$_POST['candidate_id'];
        $to_emp    = (int)$_POST['employee_id'];
        if ($from_cand && $to_emp) {
            // Copia i documenti: non li sposta, li duplica con employee_id impostato
            $docs = $pdo->prepare("SELECT * FROM person_documents WHERE candidate_id=?");
            $docs->execute([$from_cand]); $all = $docs->fetchAll();
            $transferred = 0;
            foreach ($all as $d) {
                // Verifica che non esista già lo stesso file per il dipendente
                $chk = $pdo->prepare("SELECT id FROM person_documents WHERE employee_id=? AND file_name=?");
                $chk->execute([$to_emp, $d['file_name']]); $exists = $chk->fetchColumn(); $chk->closeCursor();
                if (!$exists) {
                    $pdo->prepare("UPDATE person_documents SET employee_id=? WHERE id=? AND employee_id IS NULL")
                        ->execute([$to_emp, $d['id']]);
                    $transferred++;
                }
            }
            write_log('Documenti', 'success', "Trasferiti $transferred documenti da candidato #$from_cand a dipendente #$to_emp", $u_id);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-right-left'></i> $transferred documenti collegati al dipendente.</div>";
        }
    }
}

// ── Da qui output HTML ──────────────────────────────────────────────────────
require_once('header.php');

// ── Carica documenti ─────────────────────────────────────────────────────────
$where = "WHERE 1=1"; $prm = [];
if ($cand_id) { $where .= " AND pd.candidate_id=?"; $prm[] = $cand_id; }
if ($emp_id)  { $where .= " AND pd.employee_id=?";  $prm[] = $emp_id; }
// Filtra per accesso ruolo
if ($u_role > 2) {
    $viewable = array_keys(array_filter($access, fn($a) => (int)$a['can_view'] === 1));
    if (empty($viewable)) $where .= " AND 0"; // nessun accesso
    else { $where .= " AND pd.doc_type IN(" . implode(',', array_fill(0, count($viewable), '?')) . ")"; $prm = array_merge($prm, $viewable); }
}

$f_type = $_GET['f_type'] ?? '';
if ($f_type) { $where .= " AND pd.doc_type=?"; $prm[] = $f_type; }

$dq = $pdo->prepare(
    "SELECT pd.*,
            CONCAT(c.first_name,' ',c.last_name) cand_name, c.status cand_status,
            CONCAT(e.first_name,' ',e.last_name) emp_name, e.employee_code,
            u.display_name uploader_name
     FROM person_documents pd
     LEFT JOIN candidates c ON pd.candidate_id=c.id
     LEFT JOIN employees e ON pd.employee_id=e.id
     LEFT JOIN users u ON pd.uploaded_by=u.id
     $where
     ORDER BY pd.created_at DESC"
);
$dq->execute($prm);
$documents = $dq->fetchAll();

// Persona info per il contesto
$person_name = '';
$person_type = '';
if ($cand_id) {
    $p = $pdo->prepare("SELECT first_name, last_name, status FROM candidates WHERE id=?");
    $p->execute([$cand_id]); $pi = $p->fetch(); $p->closeCursor();
    if ($pi) { $person_name = $pi['first_name'] . ' ' . $pi['last_name']; $person_type = 'Candidato'; }
}
if ($emp_id) {
    $p = $pdo->prepare("SELECT first_name, last_name, employee_code FROM employees WHERE id=?");
    $p->execute([$emp_id]); $pi = $p->fetch(); $p->closeCursor();
    if ($pi) { $person_name = $pi['first_name'] . ' ' . $pi['last_name'] . ($pi['employee_code'] ? " ({$pi['employee_code']})" : ''); $person_type = 'Dipendente'; }
}

// Liste per form
$all_candidates = ($u_role <= 5) ? $pdo->query("SELECT id, first_name, last_name FROM candidates WHERE status NOT IN('rejected','withdrawn') ORDER BY last_name")->fetchAll() : [];
$all_employees  = ($u_role <= 4) ? $pdo->query("SELECT id, first_name, last_name, employee_code FROM employees WHERE status='active' ORDER BY last_name")->fetchAll() : [];

// KPI
$kpi_total = count($documents);
$kpi_by_type = [];
foreach ($documents as $d) { $kpi_by_type[$d['doc_type']] = ($kpi_by_type[$d['doc_type']] ?? 0) + 1; }
$kpi_size = array_sum(array_map(fn($d) => (int)($d['file_size'] ?? 0), $documents));
?>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-folder-open" style="color:var(--p);margin-right:10px"></i>
      <?=$person_name ? "Documenti — " . h($person_name) : 'Archivio Documenti'?>
    </h1>
    <p style="color:var(--muted);font-size:13px">
      <?=$person_type ? h($person_type) . ' — ' : ''?>Gestione documentale con controllo accesso per ruolo
    </p>
  </div>
  <div style="display:flex;gap:8px">
    <?php
    $can_upload_any = false;
    foreach ($DOC_TYPES as $dt => $info) { if (canDo($dt, 'upload', $access, $u_role)) { $can_upload_any = true; break; } }
    if ($can_upload_any): ?>
    <button onclick="document.getElementById('mUpload').style.display='flex'" class="btn btn-primary"><i class="fa-solid fa-cloud-arrow-up"></i> Carica documento</button>
    <?php endif; ?>
    <?php if ($cand_id && $u_role <= 2): ?>
    <button onclick="document.getElementById('mTransfer').style.display='flex'" class="btn" style="background:#6366f1;color:#fff;border:none"><i class="fa-solid fa-right-left"></i> Trasferisci a dipendente</button>
    <?php endif; ?>
  </div>
</div>
<?=$msg?>

<!-- KPI -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:12px;margin-bottom:22px">
  <div class="stat-card" style="border-color:var(--p)"><div class="sl">Documenti</div><div class="sv" style="color:var(--p)"><?=$kpi_total?></div></div>
  <div class="stat-card" style="border-color:var(--success)"><div class="sl">Dimensione</div><div class="sv" style="color:var(--success);font-size:14px"><?=$kpi_size > 1048576 ? round($kpi_size/1048576,1).' MB' : round($kpi_size/1024).' KB'?></div></div>
  <?php foreach(array_slice($kpi_by_type, 0, 5) as $dt => $cnt):
    $di = $DOC_TYPES[$dt] ?? $DOC_TYPES['altro']; ?>
  <div class="stat-card" style="border-color:<?=$di[2]?>"><div class="sl"><?=$di[0]?></div><div class="sv" style="color:<?=$di[2]?>"><?=$cnt?></div></div>
  <?php endforeach; ?>
</div>

<!-- Filtro tipo -->
<?php if (!$cand_id && !$emp_id): ?>
<form method="GET" class="filter-bar">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg"><label>Tipo documento</label>
    <select name="f_type"><option value="">Tutti</option>
    <?php foreach($DOC_TYPES as $k=>$v): ?><option value="<?=$k?>" <?=$f_type===$k?'selected':''?>><?=$v[0]?></option><?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary" style="margin-top:20px">Filtra</button>
  <?php if($f_type): ?><a href="documenti.php" class="btn btn-sm" style="margin-top:20px">Reset</a><?php endif; ?>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>
<?php endif; ?>

<!-- Lista documenti -->
<?php if(empty($documents)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:14px;border:1px dashed var(--border);color:var(--muted)">
  <i class="fa-solid fa-folder-open" style="font-size:40px;margin-bottom:16px;display:block;opacity:.3"></i>
  Nessun documento trovato.
</div>
<?php else: ?>
<div class="card">
<?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('documenti', '#tDocs', ['export_filename' => 'documenti', 'title' => 'Archivio documenti']); ?>
<table id="tDocs" class="display" style="width:100%">
<thead><tr><th>Tipo</th><th>Titolo</th><th>Persona</th><th>Data compilazione</th><th>Caricato il</th><th>Caricato da</th><th>Dimensione</th><th>Azioni</th></tr></thead>
<tbody>
<?php foreach($documents as $d):
    $di = $DOC_TYPES[$d['doc_type']] ?? $DOC_TYPES['altro'];
    $can_dl  = canDo($d['doc_type'], 'download', $access, $u_role);
    $can_del = canDo($d['doc_type'], 'delete', $access, $u_role);
    $person  = $d['emp_name'] ?: $d['cand_name'] ?: '—';
    $badge   = $d['employee_id'] ? 'badge-success' : ($d['candidate_id'] ? 'badge-info' : 'badge-neutral');
    $badge_t = $d['employee_id'] ? 'Dipendente' : ($d['candidate_id'] ? 'Candidato' : '');
    $size    = $d['file_size'] > 1048576 ? round($d['file_size']/1048576,1).' MB' : round(($d['file_size']??0)/1024).' KB';
?>
<tr>
  <td>
    <span style="display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:6px;background:<?=$di[2]?>12;color:<?=$di[2]?>;font-size:11px;font-weight:700">
      <i class="fa-solid <?=$di[1]?>" style="font-size:10px"></i> <?=$di[0]?>
    </span>
  </td>
  <td>
    <div style="font-weight:600;font-size:12px"><?=h($d['title'] ?? $d['original_name'])?></div>
    <?php if($d['notes']): ?><div style="font-size:10px;color:var(--muted)"><?=h(mb_substr($d['notes'],0,60))?></div><?php endif; ?>
  </td>
  <td>
    <div style="font-size:12px"><?=h($person)?></div>
    <?php if($badge_t): ?><span class="badge <?=$badge?>" style="font-size:9px"><?=$badge_t?></span><?php endif; ?>
    <?php if($d['employee_id'] && $d['candidate_id']): ?><span class="badge badge-info" style="font-size:9px">+Candidato</span><?php endif; ?>
  </td>
  <td style="font-size:12px;color:var(--muted)"><?=$d['compilation_date'] ? date('d/m/Y', strtotime($d['compilation_date'])) : '—'?></td>
  <td style="font-size:12px;color:var(--muted)"><?=date('d/m/Y H:i', strtotime($d['created_at']))?></td>
  <td style="font-size:11px"><?=h($d['uploader_name'] ?? '—')?></td>
  <td style="font-size:11px;color:var(--muted)"><?=$size?></td>
  <td>
    <?php if($can_dl): ?>
    <a href="doc_download.php?id=<?=$d['id']?>" class="btn btn-sm" title="Scarica"><i class="fa-solid fa-download"></i></a>
    <?php endif; ?>
    <?php if($can_del): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare documento?')">
            <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="doc_id" value="<?=$d['id']?>">
      <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
    </form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<!-- ═══ MODAL: UPLOAD ═══ -->
<div id="mUpload" class="modal-overlay">
<div class="modal-box" style="width:600px">
  <div style="display:flex;justify-content:space-between;margin-bottom:18px">
    <h3 style="margin:0;font-size:16px"><i class="fa-solid fa-cloud-arrow-up" style="color:var(--p)"></i> Carica documento</h3>
    <button onclick="document.getElementById('mUpload').style.display='none'" style="border:none;background:none;font-size:20px;cursor:pointer">&times;</button>
  </div>
  <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <?php if($cand_id): ?><input type="hidden" name="candidate_id" value="<?=$cand_id?>"><?php endif; ?>
    <?php if($emp_id): ?><input type="hidden" name="employee_id" value="<?=$emp_id?>"><?php endif; ?>

    <?php if(!$cand_id && !$emp_id): ?>
    <div class="grid-2">
      <div class="form-group"><label>Candidato</label>
        <select name="candidate_id"><option value="">— Nessuno —</option>
        <?php foreach($all_candidates as $c): ?><option value="<?=$c['id']?>"><?=h($c['last_name'].' '.$c['first_name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Dipendente</label>
        <select name="employee_id"><option value="">— Nessuno —</option>
        <?php foreach($all_employees as $e): ?><option value="<?=$e['id']?>"><?=h($e['last_name'].' '.$e['first_name'])?><?=$e['employee_code']?' ('.$e['employee_code'].')':''?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php endif; ?>

    <div class="grid-2">
      <div class="form-group"><label>Tipo documento *</label>
        <select name="doc_type" id="upl_type" required>
          <?php foreach($DOC_TYPES as $k=>$v):
            if (!canDo($k, 'upload', $access, $u_role)) continue; ?>
          <option value="<?=$k?>"><?=$v[0]?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Data compilazione</label>
        <input type="date" name="compilation_date">
      </div>
    </div>
    <div class="form-group"><label>Titolo / descrizione</label>
      <input type="text" name="title" placeholder="Es. CV aggiornato marzo 2025">
    </div>
    <div class="form-group"><label>File *</label>
      <input type="file" name="doc_file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip">
      <div style="font-size:10px;color:var(--muted);margin-top:4px">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP — max 15MB</div>
    </div>
    <div class="form-group"><label>Note</label><textarea name="doc_notes" rows="2"></textarea></div>

    <?php if($u_role <= 2): ?>
    <div style="background:#f8fafc;padding:12px;border-radius:10px;border:1px solid var(--border);margin-bottom:14px">
      <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px"><i class="fa-solid fa-shield-halved"></i> Controllo accesso</div>
      <div class="grid-2">
        <div class="form-group" style="margin:0"><label>Visibilità</label>
          <select name="visibility"><option value="restricted">Riservato (solo ruoli autorizzati)</option><option value="all">Visibile a tutti</option></select>
        </div>
        <div class="form-group" style="margin:0"><label>Ruolo minimo per visualizzare</label>
          <select name="min_role_view">
            <option value="1">1 — Super Admin</option><option value="2" selected>2 — HR Director</option>
            <option value="3">3 — Brand Manager</option><option value="4">4 — Team Leader</option>
            <option value="5">5 — Recruiter</option><option value="6">6 — Dipendente</option>
          </select>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center"><i class="fa-solid fa-upload"></i> Carica</button>
      <button type="button" onclick="document.getElementById('mUpload').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button>
    </div>
  </form>
</div></div>

<?php if($cand_id && $u_role <= 2): ?>
<!-- ═══ MODAL: TRASFERISCI A DIPENDENTE ═══ -->
<div id="mTransfer" class="modal-overlay">
<div class="modal-box" style="width:440px">
  <h3 style="margin:0 0 18px;font-size:16px"><i class="fa-solid fa-right-left" style="color:#6366f1"></i> Trasferisci documenti a dipendente</h3>
  <p style="font-size:12px;color:var(--muted);margin-bottom:14px">I documenti del candidato <?=h($person_name)?> verranno collegati al dipendente selezionato. I file restano gli stessi, viene aggiunto il riferimento employee_id.</p>
  <form method="POST">
            <?= csrf_field() ?>
    <input type="hidden" name="action" value="transfer_to_employee">
    <input type="hidden" name="candidate_id" value="<?=$cand_id?>">
    <div class="form-group"><label>Dipendente destinatario *</label>
      <select name="employee_id" required>
        <option value="">— Seleziona —</option>
        <?php foreach($all_employees as $e): ?><option value="<?=$e['id']?>"><?=h($e['last_name'].' '.$e['first_name'])?><?=$e['employee_code']?' ('.$e['employee_code'].')':''?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:10px">
      <button type="submit" class="btn" style="flex:1;justify-content:center;background:#6366f1;color:#fff;border:none"><i class="fa-solid fa-right-left"></i> Trasferisci</button>
      <button type="button" onclick="document.getElementById('mTransfer').style.display='none'" class="btn" style="flex:1;justify-content:center">Annulla</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<script>
$(function(){ $('#tDocs').DataTable({pageLength:15, order:[[4,'desc']], language:{url:'//cdn.datatables.net/plug-ins/1.13.6/i18n/it-IT.json'}}); });
</script>
<?php require_once('footer.php'); ?>
