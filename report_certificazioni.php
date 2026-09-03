<?php
/**
 * certV 2.0 v2.2 — report_certificazioni.php
 * v2.2: uc.employee_id → employees, lista persone da employees
 */
require_once('access_control.php');
require_once(__DIR__ . '/app/RecycleBin.php');
// NB: header.php viene incluso DOPO i POST handler (save/delete) per non
// emettere output prima di redirect_self() — vedi fondo blocco PHP.

$u_role   = (int)($_SESSION['role_id'] ?? 99);
$u_id     = (int)$_SESSION['user_id'];
$u_emp_id = (int)($_SESSION['employee_id'] ?? 0);
$can_edit = can('edit');
$msg      = '';

// Dipendente vede solo il proprio (tramite employee_id)
$restrict_emp = ($u_role === 6) ? $u_emp_id : 0;

// ── Salvataggio ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['save_cert'])) {
    Csrf::verify();
    $id   = (int)$_POST['cert_id'];
    $exp  = $_POST['expiry_date'] ?: null;
    $note = $_POST['notes'] ?: null;
    $old  = $pdo->prepare("SELECT * FROM user_certifications WHERE id=?");
    $old->execute([$id]); $old_data = $old->fetch();
    if ($old_data) {
        $pdo->prepare("INSERT INTO brand_contacts_history (brand_id,archived_data,archived_by) VALUES (NULL,?,?)")
            ->execute([json_encode(['type'=>'uc_edit','old'=>$old_data]), $u_id]);
    }
    $pdo->prepare("UPDATE user_certifications SET expiry_date=?,status=?,notes=? WHERE id=?")
        ->execute([$exp, $exp ? cert_status_from_date($exp) : 'active', $note, $id]);
    write_log('Certifications','success',"Cert #$id aggiornata",$u_id);
    $msg = "<div class='alert alert-success'>Certificazione aggiornata.</div>";
}

// ── v1.7.20: Eliminazione certificazione caricata erroneamente ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['delete_cert'])) {
    Csrf::verify();
    $id = (int)$_POST['cert_id'];

    if ($id <= 0) {
        $msg = "<div class='alert alert-danger'>ID certificazione non valido.</div>";
    } else {
        // Carico la certificazione PRIMA di cancellare (per audit log e rimozione file)
        $stmt = $pdo->prepare(
            "SELECT uc.*, c.name AS cert_name, c.code AS cert_code,
                    e.first_name, e.last_name
               FROM user_certifications uc
               JOIN certifications c ON uc.certification_id = c.id
               JOIN employees e      ON uc.employee_id = e.id
              WHERE uc.id = ?"
        );
        $stmt->execute([$id]);
        $cert_data = $stmt->fetch();

        if (!$cert_data) {
            $msg = "<div class='alert alert-danger'>Certificazione non trovata.</div>";
        } else {
            // Dipendente (role 6) può eliminare SOLO le proprie certificazioni
            if ($u_role === 6 && (int)$cert_data['employee_id'] !== $u_emp_id) {
                $msg = "<div class='alert alert-danger'>Non autorizzato a eliminare questa certificazione.</div>";
                write_log('Certifications','warning',"Tentativo eliminazione non autorizzato cert #$id da utente $u_id",$u_id);
            } else {
                try {
                    $pdo->beginTransaction();

                    // 1) Audit log: salvo snapshot della cert eliminata
                    $pdo->prepare("INSERT INTO brand_contacts_history (brand_id,archived_data,archived_by) VALUES (NULL,?,?)")
                        ->execute([json_encode(['type'=>'uc_delete','data'=>$cert_data]), $u_id]);

                    // 2) Rimuovo file PDF allegato (se presente)
                    $doc_path = $cert_data['document_path'] ?? null;
                    if ($doc_path) {
                        $full_path = __DIR__ . '/' . ltrim($doc_path, '/');
                        if (is_file($full_path)) {
                            @unlink($full_path);
                        }
                    }

                    // 3) Cancello la riga
                    RecycleBin::capture($pdo, 'user_certifications', 'id=?', [$id], $u_id, 'report_certificazioni.php');

                    $pdo->commit();

                    $cert_label = $cert_data['cert_name'] . ($cert_data['cert_code'] ? ' (' . $cert_data['cert_code'] . ')' : '');
                    $emp_label  = $cert_data['first_name'] . ' ' . $cert_data['last_name'];
                    write_log('Certifications','success',"Cert #$id eliminata: $cert_label di $emp_label",$u_id);
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-trash'></i> Certificazione <strong>" . h($cert_label) . "</strong> di <strong>" . h($emp_label) . "</strong> eliminata con successo.</div>";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $msg = "<div class='alert alert-danger'>Errore durante l'eliminazione: " . h($e->getMessage()) . "</div>";
                    write_log('Certifications','error',"Errore eliminazione cert #$id: " . $e->getMessage(),$u_id);
                }
            }
        }
    }

    // PRG: redirect per evitare doppi submit
    if (function_exists('redirect_self')) {
        $_SESSION['flash_msg'] = $msg;
        redirect_self();
    }
}

// Recupero eventuale flash message dopo redirect
if (empty($msg) && !empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// ── Filtri ────────────────────────────────────────────────────────────────────
$f_brands = $_GET['f_br'] ?? [];
$f_emps   = $_GET['f_us'] ?? [];   // contiene employee.id
$f_status = $_GET['f_st'] ?? [];

$where  = ["1=1"]; $params = [];
if ($restrict_emp)    { $where[] = "uc.employee_id=?"; $params[] = $restrict_emp; }
if (!empty($f_brands)){ $where[] = "cert.brand_id IN(".implode(',',array_fill(0,count($f_brands),'?')).")"; $params=array_merge($params,$f_brands); }
if (!empty($f_emps) && !$restrict_emp) { $where[] = "uc.employee_id IN(".implode(',',array_fill(0,count($f_emps),'?')).")"; $params=array_merge($params,$f_emps); }
if (!empty($f_status)){ $where[] = "uc.status IN(".implode(',',array_fill(0,count($f_status),'?')).")"; $params=array_merge($params,$f_status); }

$sql = "SELECT uc.*, cert.name cert_name, cert.code cert_code, b.name brand_name,
               e.first_name, e.last_name, t.name tech_name
        FROM user_certifications uc
        JOIN certifications cert ON uc.certification_id = cert.id
        JOIN brands b            ON cert.brand_id = b.id
        JOIN employees e         ON uc.employee_id = e.id
        JOIN technologies t      ON cert.technology_id = t.id
        WHERE ".implode(' AND ',$where)."
        ORDER BY uc.expiry_date ASC";
$s = $pdo->prepare($sql);
$s->execute($params);
$results = $s->fetchAll();

$all_brands = $pdo->query("SELECT id,name FROM brands ORDER BY name")->fetchAll();
// v2.2: lista dipendenti da employees (non da users)
$all_emps = $pdo->query("SELECT id,first_name,last_name FROM employees WHERE status='active' ORDER BY last_name")->fetchAll();

$edit_cert = null;
if (isset($_GET['edit'])) {
    $es = $pdo->prepare("SELECT uc.*,cert.name cert_name FROM user_certifications uc JOIN certifications cert ON uc.certification_id=cert.id WHERE uc.id=?");
    $es->execute([(int)$_GET['edit']]);
    $edit_cert = $es->fetch();
}

// Header incluso qui: tutti i POST handler (con eventuale redirect_self) sono già eseguiti
require_once('header.php');
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-chart-pie" style="color:var(--p);margin-right:10px"></i>Report certificazioni</h1>
    <p style="color:var(--muted);font-size:13px"><?=count($results)?> record trovati</p>
  </div>
  <div style="display:flex;gap:8px" class="no-print">
    <button onclick="window.print()" class="btn btn-sm"><i class="fa-solid fa-print"></i></button>
    <?php if(check_ui_permission('upload_certificato.php')): ?>
    <a href="upload_certificato.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Aggiungi</a>
    <?php endif; ?>
  </div>
</div>

<?=$msg?>

<?php if(!$restrict_emp): ?>
<form method="GET" class="filter-bar" style="align-items:flex-start">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <div class="fg">
    <label>Brand/Vendor</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;height:100px;overflow-y:auto;padding:8px;min-width:200px">
      <?php foreach($all_brands as $b): ?>
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:3px;cursor:pointer;align-items:center">
        <input type="checkbox" name="f_br[]" value="<?=$b['id']?>" <?=in_array($b['id'],$f_brands)?'checked':''?>>
        <?=h($b['name'])?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg">
    <label>Collaboratori</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;height:100px;overflow-y:auto;padding:8px;min-width:200px">
      <?php foreach($all_emps as $e): ?>
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:3px;cursor:pointer;align-items:center">
        <input type="checkbox" name="f_us[]" value="<?=$e['id']?>" <?=in_array($e['id'],$f_emps)?'checked':''?>>
        <?=h($e['first_name'].' '.$e['last_name'])?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg">
    <label>Stato</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px;min-width:140px">
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:6px;cursor:pointer"><input type="checkbox" name="f_st[]" value="active" <?=in_array('active',$f_status)?'checked':''?>> Attiva</label>
      <label style="display:flex;gap:7px;font-size:12px;margin-bottom:6px;cursor:pointer"><input type="checkbox" name="f_st[]" value="expiring" <?=in_array('expiring',$f_status)?'checked':''?>> In scadenza</label>
      <label style="display:flex;gap:7px;font-size:12px;cursor:pointer"><input type="checkbox" name="f_st[]" value="expired" <?=in_array('expired',$f_status)?'checked':''?>> Scaduta</label>
    </div>
  </div>
  <div style="display:flex;flex-direction:column;gap:6px;padding-top:22px">
    <button type="submit" class="btn btn-primary">Filtra</button>
    <a href="report_certificazioni.php" class="btn btn-sm" style="text-align:center">Reset</a>
  </div>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>
<?php endif; ?>

<?php if($edit_cert && $can_edit): ?>
<div class="card" style="margin-bottom:20px;border-color:var(--p)">
  <div class="card-header"><span class="card-title">Modifica scadenza — <?=h($edit_cert['cert_name'])?></span></div>
  <form method="POST" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
            <?= csrf_field() ?>
    <input type="hidden" name="save_cert" value="1">
    <input type="hidden" name="cert_id" value="<?=$edit_cert['id']?>">
    <div class="fg" style="margin:0;flex:1;min-width:150px">
      <label>Nuova data scadenza</label>
      <input type="date" name="expiry_date" value="<?=h($edit_cert['expiry_date']??'')?>">
    </div>
    <div class="fg" style="margin:0;flex:2;min-width:200px">
      <label>Note</label>
      <input type="text" name="notes" value="<?=h($edit_cert['notes']??'')?>" placeholder="Note opzionali...">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary">Salva</button>
      <a href="report_certificazioni.php" class="btn">Annulla</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="overflow-x:auto">
<?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('report_certificazioni', '#tCert', ['export_filename' => 'report_certificazioni', 'title' => 'Report certificazioni']); ?>
<table class="data-table" id="tCert">
  <thead>
    <tr>
      <th>Collaboratore</th><th>Certificazione</th><th>Brand</th><th>Tecnologia</th>
      <th>Conseguimento</th><th>Scadenza</th>
      <th style="text-align:center">PDF</th><th style="text-align:center">Stato</th>
      <?php if($can_edit): ?><th style="text-align:center">Azioni</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($results)): ?>
  <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">Nessun certificato trovato.</td></tr>
  <?php endif; ?>
  <?php foreach($results as $r): ?>
  <tr>
    <td><strong><?=h($r['first_name'].' '.$r['last_name'])?></strong></td>
    <td><?=h($r['cert_name'])?><?php if($r['cert_code']): ?><br><code style="font-size:10px;color:var(--muted)"><?=h($r['cert_code'])?></code><?php endif; ?></td>
    <td><span class="badge badge-neutral"><?=h($r['brand_name'])?></span></td>
    <td style="font-size:12px;color:var(--muted)"><?=h($r['tech_name'])?></td>
    <td><?=format_date($r['issue_date'])?></td>
    <td><?=$r['expiry_date']?format_date($r['expiry_date']):'Perpetua'?></td>
    <td style="text-align:center">
      <?php if($r['document_path']): ?>
      <a href="download.php?file=<?=urlencode($r['document_path'])?>" target="_blank" style="color:#e11d48"><i class="fa-solid fa-file-pdf" style="font-size:16px"></i></a>
      <?php else: ?><i class="fa-solid fa-file-circle-xmark" style="color:#cbd5e1"></i><?php endif; ?>
    </td>
    <td style="text-align:center"><?=status_badge($r['status'])?></td>
    <?php if($can_edit): ?>
    <td style="text-align:center;white-space:nowrap">
      <a href="<?= qs_self_safe(['edit'=>''.($r['id']).'']) ?>"
         class="btn btn-blue btn-sm"
         title="Modifica"><i class="fa-solid fa-pen"></i></a>
      <form method="POST" style="display:inline-block;margin-left:4px"
            onsubmit="return confirm('Eliminare definitivamente la certificazione di <?= h(addslashes($r['first_name'].' '.$r['last_name'])) ?>?\n\n<?= h(addslashes($r['cert_name'])) ?>\n\nQuesta azione è irreversibile.');">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_cert" value="1">
        <input type="hidden" name="cert_id" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="btn btn-sm"
                style="background:#dc2626;color:#fff;border:0"
                title="Elimina certificazione (irreversibile)">
          <i class="fa-solid fa-trash"></i>
        </button>
      </form>
    </td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<script>$('#tCert').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[5,'asc']]});</script>
<?php require_once('footer.php'); ?>
