<?php
/**
 * certV 2.0 v2.2 — upload_certificato.php
 * v2.2: employee_id invece di user_id in user_certifications
 *       lista persone da employees, uploaded_by rimane users.id
 */
require_once('access_control.php');
require_once('header.php');

$u_id     = (int)$_SESSION['user_id'];
$u_emp_id = (int)($_SESSION['employee_id'] ?? 0);
$u_role   = (int)($_SESSION['role_id'] ?? 99);
$msg      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cert_id    = (int)$_POST['certification_id'];
    // v2.2: employee_id per i manager, altrimenti l'employee dell'utente corrente
    $emp_id     = can('edit') ? (int)$_POST['employee_id'] : $u_emp_id;
    $issue      = $_POST['issue_date'] ?: null;
    $expiry     = $_POST['expiry_date'] ?: null;
    $score      = $_POST['score'] ? (int)$_POST['score'] : null;
    $code       = trim($_POST['certificate_code'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');

    if (!$cert_id || !$issue || !$emp_id) {
        $msg = "<div class='alert alert-danger'>Seleziona certificazione, collaboratore e data conseguimento.</div>";
    } else {
        $pdf_path = null;
        if (!empty($_FILES['pdf_file']['name'])) {
            $upload_dir = UPLOAD_DIR . 'certificati/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','jpg','jpeg','png'])) {
                $msg = "<div class='alert alert-danger'>Formato non consentito (PDF/JPG/PNG).</div>";
            } else {
                $fname    = 'cert_' . $emp_id . '_' . time() . '.' . $ext;
                $pdf_path = 'certificati/' . $fname;
                move_uploaded_file($_FILES['pdf_file']['tmp_name'], $upload_dir . $fname);
            }
        }
        if (!$msg) {
            $status = cert_status_from_date($expiry);
            $pdo->prepare(
                "INSERT INTO user_certifications
                    (employee_id, certification_id, issue_date, expiry_date,
                     status, score, certificate_code, document_path, notes, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([$emp_id, $cert_id, $issue, $expiry, $status, $score, $code, $pdf_path, $notes, $u_id]);

            if ($expiry && days_diff($expiry) <= 90) {
                push_notification('Nuova cert. in scadenza entro 90gg', 'Verificare piano di rinnovo', 'asset', 'warning', null, 3);
            }
            write_log('Certifications','success',"Upload cert. id=$cert_id per emp=$emp_id",$u_id);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Certificato caricato con successo.</div>";
        }
    }
}

$brands = $pdo->query("SELECT id,name FROM brands ORDER BY name")->fetchAll();
$certs  = $pdo->query(
    "SELECT c.id, c.name, c.code, c.validity_months, b.name brand_name, b.id brand_id
     FROM certifications c JOIN brands b ON c.brand_id=b.id
     WHERE c.is_active=1 ORDER BY b.name, c.name"
)->fetchAll();
// v2.2: lista da employees, non da users
$emps = can('edit')
    ? $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status='active' ORDER BY last_name")->fetchAll()
    : [];
?>

<div style="margin-bottom:22px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-upload" style="color:var(--p);margin-right:10px"></i>Carica certificato</h1>
  <p style="color:var(--muted);font-size:13px">Inserisci una nuova certificazione conseguita</p>
</div>

<?=$msg?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:22px">
<div>
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-signature"></i> Dati certificazione</span></div>
  <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>

    <?php if(can("edit")): ?>
    <div class="form-group">
      <label>Assegnato a *</label>
      <select name="employee_id" required>
        <option value="">Seleziona collaboratore...</option>
        <?php foreach($emps as $emp): ?>
        <option value="<?=$emp['id']?>" <?=$emp['id']==$u_emp_id?'selected':''?>><?=h($emp['last_name'].' '.$emp['first_name'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="form-group">
      <label>Certificazione *</label>
      <select name="certification_id" id="cert_sel" required onchange="calcExpiry()">
        <option value="">Seleziona certificazione...</option>
        <?php foreach($certs as $c): ?>
        <option value="<?=$c['id']?>" data-months="<?=$c['validity_months']??''?>" data-brand="<?=$c['brand_id']?>">
          [<?=h($c['brand_name'])?>] <?=h($c['name'])?><?=$c['code']?' ('.$c['code'].')':''?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid-2">
      <div class="form-group">
        <label>Data conseguimento *</label>
        <input type="date" name="issue_date" id="issue_date" required onchange="calcExpiry()">
      </div>
      <div class="form-group">
        <label>Data scadenza</label>
        <input type="date" name="expiry_date" id="expiry_date">
      </div>
      <div class="form-group">
        <label>Codice certificato <span id="cc_hint" style="font-size:10px;color:var(--muted);font-weight:400"></span></label>
        <input type="text"
               name="certificate_code"
               id="cert_code"
               list="cert_code_list"
               autocomplete="off"
               placeholder="Es: MC-12345-A"
               disabled
               style="background:#f8fafc">
        <datalist id="cert_code_list"></datalist>
        <div id="cc_meta" style="font-size:11px;color:var(--muted);margin-top:4px;display:none"></div>
        <div id="cc_dup" style="font-size:11px;color:#b45309;margin-top:4px;display:none">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span></span>
        </div>
      </div>
      <div class="form-group">
        <label>Punteggio esame</label>
        <input type="number" name="score" min="0" max="1000" placeholder="Es: 850">
      </div>
    </div>

    <div class="form-group">
      <label>Documento (PDF / JPG)</label>
      <input type="file" name="pdf_file" accept=".pdf,.jpg,.jpeg,.png"
             style="padding:8px;border:1px dashed var(--border);border-radius:8px">
      <div style="font-size:11px;color:var(--muted);margin-top:4px">Max 10MB — salvato in uploads/certificati/</div>
    </div>

    <div class="form-group">
      <label>Note</label>
      <textarea name="notes" rows="2" placeholder="Note opzionali..."></textarea>
    </div>

    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:12px">
        <i class="fa-solid fa-floppy-disk"></i> Salva certificato
      </button>
      <a href="report_certificazioni.php" class="btn" style="flex:1;justify-content:center;padding:12px">Annulla</a>
    </div>
  </form>
</div>
</div>

<div>
<div class="card" style="margin-bottom:18px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-info" style="color:var(--info)"></i> Stato automatico</span></div>
  <div style="font-size:12px;line-height:1.8;color:#475569">
    <div>Scadenza &gt;90gg → <?=status_badge('active')?></div>
    <div>Scadenza ≤90gg → <?=status_badge('expiring')?></div>
    <div>Scadenza passata → <?=status_badge('expired')?></div>
    <div>Nessuna scadenza → <?=status_badge('active')?> (perpetua)</div>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Ultime 5 aggiunte</span></div>
  <?php
  // v2.2: JOIN employees invece di users
  $last5 = $pdo->query(
      "SELECT uc.issue_date, cert.name cn, e.first_name, e.last_name
       FROM user_certifications uc
       JOIN certifications cert ON uc.certification_id = cert.id
       JOIN employees e         ON uc.employee_id = e.id
       ORDER BY uc.created_at DESC LIMIT 5"
  );
  foreach($last5->fetchAll() as $l): ?>
  <div style="padding:8px 0;border-bottom:1px solid #f8fafc;font-size:12px">
    <div style="font-weight:600"><?=h($l['cn'])?></div>
    <div style="color:var(--muted)"><?=h($l['first_name'].' '.$l['last_name'])?> · <?=format_date($l['issue_date'])?></div>
  </div>
  <?php endforeach; ?>
</div>
</div>
</div>

<script>
function calcExpiry() {
    const sel = document.getElementById('cert_sel');
    const opt = sel.options[sel.selectedIndex];
    const months = parseInt(opt?.dataset?.months);
    const iss = document.getElementById('issue_date').value;
    if (months && iss) {
        const d = new Date(iss);
        d.setMonth(d.getMonth() + months);
        document.getElementById('expiry_date').value = d.toISOString().slice(0,10);
    }
}
document.getElementById('issue_date').addEventListener('change', calcExpiry);

/* ════════════════════════════════════════════════════════════════
 * Auto-popolamento Codice Certificato
 * Trigger: change sulla select Certificazione
 * Endpoint: api_cert_codes.php?cert_id=<id>
 * Effetti UI:
 *   - abilita/disabilita l'input
 *   - aggiorna placeholder con pattern derivato dal codice catalogo
 *   - popola <datalist> con codici già usati per quella cert
 *   - mostra count emissioni precedenti
 *   - warning live se il codice digitato è già presente
 * ════════════════════════════════════════════════════════════════ */
let _ccCache = new Map();    // cert_id → response (cache breve)
let _ccAbort = null;          // AbortController per request in flight
const _ccInput  = document.getElementById('cert_code');
const _ccList   = document.getElementById('cert_code_list');
const _ccHint   = document.getElementById('cc_hint');
const _ccMeta   = document.getElementById('cc_meta');
const _ccDup    = document.getElementById('cc_dup');

function _ccReset() {
    _ccInput.value = '';
    _ccInput.disabled = true;
    _ccInput.placeholder = 'Es: MC-12345-A';
    _ccInput.style.background = '#f8fafc';
    _ccList.innerHTML = '';
    _ccHint.textContent = '';
    _ccMeta.style.display = 'none';
    _ccMeta.textContent = '';
    _ccDup.style.display = 'none';
}

function _ccApply(data) {
    if (!data || !data.ok) {
        _ccReset();
        _ccHint.textContent = '(errore caricamento codici)';
        return;
    }
    _ccInput.disabled = false;
    _ccInput.style.background = '#fff';
    _ccInput.placeholder = data.placeholder || 'Es: MC-12345-A';

    /* Popola datalist con codici già usati */
    _ccList.innerHTML = '';
    if (Array.isArray(data.existing_codes) && data.existing_codes.length) {
        data.existing_codes.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.code;
            if (c.count > 1) opt.label = `${c.code} (${c.count}x)`;
            _ccList.appendChild(opt);
        });
        _ccHint.textContent = `(${data.existing_codes.length} codici già emessi)`;
    } else {
        _ccHint.textContent = '(nessun codice precedente)';
    }

    /* Meta: totale emissioni + codice catalogo */
    const parts = [];
    if (data.catalog_code)       parts.push(`Catalogo: <strong>${data.catalog_code}</strong>`);
    if (data.total_issued > 0)   parts.push(`${data.total_issued} emissione/i registrate`);
    if (parts.length) {
        _ccMeta.style.display = 'block';
        _ccMeta.innerHTML = parts.join(' &middot; ');
    }
}

async function loadCertCodes(certId) {
    _ccDup.style.display = 'none';
    if (!certId) { _ccReset(); return; }

    /* Cache HIT */
    if (_ccCache.has(certId)) {
        _ccApply(_ccCache.get(certId));
        return;
    }

    /* Abort precedente in flight */
    if (_ccAbort) _ccAbort.abort();
    _ccAbort = new AbortController();

    _ccHint.textContent = '(caricamento...)';
    let status = 0;
    let bodyTxt = '';
    try {
        const r = await fetch('api_cert_codes.php?cert_id=' + encodeURIComponent(certId),
                              { credentials: 'same-origin', signal: _ccAbort.signal });
        status = r.status;
        bodyTxt = await r.text();
        if (!r.ok) {
            /* Provo a parsare JSON di errore strutturato */
            let detail = '';
            try {
                const j = JSON.parse(bodyTxt);
                detail = j.error || j.hint || '';
            } catch { /* response non JSON */ }
            throw new Error(`HTTP ${status}${detail ? ': ' + detail : ''}`);
        }
        const data = JSON.parse(bodyTxt);
        _ccCache.set(certId, data);
        _ccApply(data);
    } catch (e) {
        if (e.name === 'AbortError') return;  /* request superseded */
        _ccReset();
        const msg = e.message || 'errore sconosciuto';
        _ccHint.textContent = `(errore: ${msg})`;
        _ccHint.style.color = '#dc2626';
        console.error('api_cert_codes:', e, '\nResponse body:', bodyTxt);
    }
}

/* Handler change su select Certificazione */
const _ccSel = document.getElementById('cert_sel');
_ccSel.addEventListener('change', () => {
    const id = parseInt(_ccSel.value);
    loadCertCodes(id || null);
});

/* Live duplicate warning quando l'utente digita */
_ccInput.addEventListener('input', () => {
    const certId = parseInt(_ccSel.value);
    const cache  = _ccCache.get(certId);
    if (!cache || !cache.ok) { _ccDup.style.display = 'none'; return; }
    const val = _ccInput.value.trim();
    if (!val) { _ccDup.style.display = 'none'; return; }
    const match = (cache.existing_codes || []).find(c => c.code.toLowerCase() === val.toLowerCase());
    if (match) {
        _ccDup.style.display = 'block';
        _ccDup.querySelector('span').textContent =
            `Codice già presente per questa certificazione (${match.count} occorrenza/e). Verifica che non sia un duplicato.`;
    } else {
        _ccDup.style.display = 'none';
    }
});

/* Inizializza in caso di certificazione pre-selezionata (POST con errori) */
if (_ccSel.value) loadCertCodes(parseInt(_ccSel.value));
</script>
<?php require_once('footer.php'); ?>
