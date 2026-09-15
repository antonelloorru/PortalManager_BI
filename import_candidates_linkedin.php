<?php
/**
 * PortalManager v1.9.48 — import_candidates_linkedin.php
 * Recruiting & Agenzie → Importa candidati LinkedIn.
 *
 * Carica il "Report candidati" LinkedIn (.xlsx), associa ogni candidato alla
 * posizione tramite «ID offerta di lavoro» = job_positions.linkedin_code e, per
 * ogni candidato associato, genera l'anagrafica completa + la candidatura.
 *
 * RBAC: Super Admin (1), HR Director (2), Recruiter (5).
 */

require_once('access_control.php');
require_once __DIR__ . '/app/LinkedInApplicantImporter.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!in_array($u_role, [1, 2, 5], true)) { http_response_code(403); die('Accesso negato.'); }

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$msg = '';
$preview = null;   // ['summary'=>..,'rows'=>..]
$report  = null;

$imp = new LinkedInApplicantImporter($pdo, $u_id);
$imp->ensureSchema();

$tmp_dir = APP_ROOT . '/uploads/linkedin_import/';
if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);

// ── STEP 1: upload + anteprima (parse + match, nessuna scrittura) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    try {
        if (empty($_FILES['li_file']) || $_FILES['li_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Nessun file caricato o errore di upload.');
        }
        if ($_FILES['li_file']['size'] > 20 * 1024 * 1024) throw new Exception('File troppo grande (max 20 MB).');
        $ext = strtolower(pathinfo($_FILES['li_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') throw new Exception('Formato non valido: caricare il file .xlsx esportato da LinkedIn.');

        $stored = $tmp_dir . 'li_' . $u_id . '_' . time() . '.xlsx';
        if (!move_uploaded_file($_FILES['li_file']['tmp_name'], $stored)) throw new Exception('Impossibile salvare il file.');

        $rows = $imp->parse($stored);
        @unlink($stored);
        if (!$rows) throw new Exception('Nessun candidato trovato. Verificare che sia il "Report candidati" LinkedIn (foglio Candidati).');

        $preview = $imp->analyze($rows);
        $_SESSION['li_import_rows'] = $rows;           // per la conferma, evita il re-upload
        $_SESSION['li_import_potential'] = !empty($_POST['import_potential']) ? 1 : 0;
        write_log('Recruiting', 'info', 'Anteprima import LinkedIn: ' . count($rows) . ' candidati', $u_id);
    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . $h($e->getMessage()) . "</div>";
    }
}

// ── STEP 2: conferma import (dai dati in sessione) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $rows = $_SESSION['li_import_rows'] ?? [];
    $pot  = !empty($_SESSION['li_import_potential']);
    if (!$rows) {
        $msg = "<div class='alert alert-warning'>Sessione scaduta: ricarica il file.</div>";
    } else {
        $report = $imp->import($rows, $pot);
        unset($_SESSION['li_import_rows'], $_SESSION['li_import_potential']);
        if (!empty($report['errori'])) {
            $msg = "<div class='alert alert-danger'>Import interrotto: " . $h($report['errore_msg'] ?? 'errore') . "</div>";
        } else {
            write_log('Recruiting', 'success',
                "Import LinkedIn: {$report['creati']} creati, {$report['candidature']} candidature", $u_id);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Import completato.</div>";
        }
    }
}

$badge = function (string $esito): string {
    switch ($esito) {
        case 'associato':     return "<span class='badge' style='background:#dcfce7;color:#15803d'>Associato</span>";
        case 'senza_offerta': return "<span class='badge' style='background:#f1f5f9;color:#64748b'>Senza offerta</span>";
        default:              return "<span class='badge' style='background:#fef3c7;color:#b45309'>Codice non trovato</span>";
    }
};
?>
<div class="container" style="max-width:1100px;margin:0 auto;padding:18px">
  <h1 style="font-size:20px;font-weight:800;margin-bottom:4px">
    <i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> Importa candidati LinkedIn
  </h1>
  <p style="color:var(--muted);font-size:13px;margin-bottom:16px">
    Carica il <strong>Report candidati</strong> LinkedIn (.xlsx). Ogni candidato viene associato alla
    posizione tramite il campo <strong>ID offerta di lavoro</strong> = <code>Codice Posizione LinkedIn</code>
    della posizione. Per ogni candidato associato viene creata l'anagrafica completa e la candidatura.
  </p>

  <?= $msg ?>

  <?php if (!$preview && !$report): ?>
  <div class="card" style="padding:18px">
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preview">
      <div class="form-group">
        <label>File Report candidati (.xlsx)</label>
        <input type="file" name="li_file" required accept=".xlsx">
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:8px 0">
        <input type="checkbox" name="import_potential" value="1">
        Importa anche i candidati <em>senza offerta</em> (ID = 0 o non associabili) come candidati potenziali, senza posizione
      </label>
      <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Analizza file</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($preview): $s = $preview['summary']; ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
    <?php foreach ([
        ['Candidati nel file', $s['totali'], '#334155'],
        ['Associati a posizione', $s['associati'], '#16a34a'],
        ['Senza offerta', $s['senza_offerta'], '#64748b'],
        ['Codice non trovato', $s['non_associati'], '#b45309'],
    ] as [$l,$v,$c]): ?>
      <div class="card" style="text-align:center;padding:13px;border-top:3px solid <?=$c?>">
        <div style="font-size:22px;font-weight:800;color:<?=$c?>"><?=(int)$v?></div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=$h($l)?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($s['posizioni']): ?>
  <div class="card" style="padding:12px 16px;margin-bottom:14px">
    <strong style="font-size:12px">Associazioni per posizione:</strong>
    <?php foreach ($s['posizioni'] as $t => $n): ?>
      <span class="badge" style="background:#dbeafe;color:#1d4ed8;margin-left:6px"><?=$h($t)?>: <?=$n?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" style="margin-bottom:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="confirm">
    <button class="btn btn-primary"><i class="fa-solid fa-database"></i> Conferma import (<?=$s['associati'] + ($_SESSION['li_import_potential']??0 ? $s['senza_offerta']+$s['non_associati'] : 0)?> candidati)</button>
    <a href="<?=url_safe('import_candidates_linkedin')?>" class="btn btn-sm">Annulla</a>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">Anteprima — nessun dato è stato ancora scritto.</span>
  </form>

  <div class="card" style="padding:0;overflow:auto;max-height:520px">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr style="position:sticky;top:0;background:#1e293b;color:#fff">
        <th style="text-align:left;padding:8px">Candidato</th><th style="text-align:left;padding:8px">Email</th>
        <th style="text-align:left;padding:8px">ID offerta</th><th style="text-align:left;padding:8px">Posizione</th>
        <th style="text-align:left;padding:8px">Esito</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_slice($preview['rows'], 0, 60) as $i => $r): ?>
        <tr style="border-top:1px solid var(--border);background:<?=$i%2?'#f8fafc':'#fff'?>">
          <td style="padding:7px 8px;font-weight:600"><?=$h($r['nome'])?></td>
          <td style="padding:7px 8px;color:var(--muted)"><?=$h($r['email'] ?? '—')?></td>
          <td style="padding:7px 8px"><?=$h($r['li_job_id'] ?? '—')?></td>
          <td style="padding:7px 8px"><?=$h($r['posizione'] ?? '—')?></td>
          <td style="padding:7px 8px"><?=$badge($r['esito'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($preview['rows']) > 60): ?>
    <p style="font-size:11px;color:var(--muted);margin-top:6px">Mostrate le prime 60 righe di <?=count($preview['rows'])?>.</p>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($report): ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
    <?php foreach ([
        ['Anagrafiche create', $report['creati'], '#16a34a'],
        ['Candidature create', $report['candidature'], '#2563eb'],
        ['Già presenti (arricchiti)', $report['arricchiti'], '#7c3aed'],
        ['Saltati', ($report['saltati_non_associati'] + $report['saltati_esistenti_app']), '#64748b'],
    ] as [$l,$v,$c]): ?>
      <div class="card" style="text-align:center;padding:13px;border-top:3px solid <?=$c?>">
        <div style="font-size:22px;font-weight:800;color:<?=$c?>"><?=(int)$v?></div>
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=$h($l)?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-bottom:14px">
    <a href="<?=url_safe('recruiting_candidati')?>" class="btn btn-blue btn-sm"><i class="fa-solid fa-users"></i> Vai alla pipeline candidati</a>
    <a href="<?=url_safe('import_candidates_linkedin')?>" class="btn btn-sm">Nuovo import</a>
  </div>
  <div class="card" style="padding:0;overflow:auto;max-height:480px">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr style="position:sticky;top:0;background:#1e293b;color:#fff">
        <th style="text-align:left;padding:8px">Candidato</th><th style="text-align:left;padding:8px">Email</th>
        <th style="text-align:left;padding:8px">Posizione</th><th style="text-align:left;padding:8px">Azione</th>
      </tr></thead>
      <tbody>
      <?php foreach ($report['dettaglio'] as $i => $r): ?>
        <tr style="border-top:1px solid var(--border);background:<?=$i%2?'#f8fafc':'#fff'?>">
          <td style="padding:7px 8px;font-weight:600"><?=$h($r['nome'])?></td>
          <td style="padding:7px 8px;color:var(--muted)"><?=$h($r['email'] ?? '—')?></td>
          <td style="padding:7px 8px"><?=$h($r['posizione'] ?? '—')?></td>
          <td style="padding:7px 8px"><?=$h($r['azione'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once('footer.php'); ?>
