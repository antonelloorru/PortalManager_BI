<?php
/**
 * PortalManager 1.2.0 — linkedin_sync.php
 *
 * Importazione/sincronizzazione profilo e certificazioni da LinkedIn.
 *
 * Metodo: upload dell'export ufficiale LinkedIn del dipendente.
 *   - ZIP archive  (Settings → Data Privacy → Get a copy of your data)
 *   - PDF profilo  (profilo → More → Save to PDF)
 *   - singolo CSV  (Certifications.csv / Profile.csv)
 *
 * Lo scraping HTTP diretto NON è implementato per ragioni legali (LinkedIn
 * User Agreement vieta lo scraping anche dei profili pubblici).
 *
 * Sicurezza:
 *   - Solo Super Admin (1) e HR Director (2)
 *   - Validazione MIME + estensione + dimensione file upload
 *   - File caricato salvato in tmp, processato, poi rimosso
 */
require_once('access_control.php');
require_once __DIR__ . '/app/LinkedInImporter.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!in_array($u_role, [1, 2], true)) {
    header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php'));
    exit;
}

$msg    = '';
$detail = null;

const LI_MAX_UPLOAD = 25 * 1024 * 1024;  // 25 MB
const LI_ALLOWED_EXT = ['zip', 'pdf', 'csv'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ─── Collega vanity LinkedIn a dipendente ───
        if ($action === 'link') {
            $empId  = (int)($_POST['employee_id'] ?? 0);
            $vanity = LinkedInImporter::parseVanity(trim($_POST['linkedin_url'] ?? ''));
            if (!$empId || !$vanity) {
                throw new RuntimeException('Dipendente e URL/username LinkedIn richiesti.');
            }
            $pdo->prepare(
                "INSERT INTO employee_linkedin_link (employee_id, linkedin_vanity, created_by, created_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE linkedin_vanity = VALUES(linkedin_vanity), updated_at = NOW()"
            )->execute([$empId, $vanity, $u_id]);

            if (function_exists('write_log')) {
                write_log('LinkedInImport', 'success',
                    "Linkato LinkedIn vanity=$vanity a employee_id=$empId", $u_id);
            }
            $msg = "<div class='alert alert-success'><i class='fa-brands fa-linkedin'></i> Profilo LinkedIn collegato al dipendente.</div>";
        }

        // ─── Rimuovi collegamento ───
        elseif ($action === 'unlink') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $pdo->prepare("DELETE FROM employee_linkedin_link WHERE employee_id = ?")->execute([$empId]);
            $msg = "<div class='alert alert-info'><i class='fa-solid fa-link-slash'></i> Collegamento LinkedIn rimosso.</div>";
        }

        // ─── Import da file caricato ───
        elseif ($action === 'import') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            if (!$empId) throw new RuntimeException('Dipendente non specificato.');

            // Recupera vanity collegato (per profileUrl nelle note)
            $s = $pdo->prepare("SELECT linkedin_vanity FROM employee_linkedin_link WHERE employee_id = ?");
            $s->execute([$empId]);
            $vanity = $s->fetchColumn() ?: '';

            if (empty($_FILES['li_file']) || $_FILES['li_file']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['li_file']['error'] ?? -1;
                $errMap = [
                    UPLOAD_ERR_INI_SIZE => 'File troppo grande (limite server).',
                    UPLOAD_ERR_FORM_SIZE => 'File troppo grande.',
                    UPLOAD_ERR_PARTIAL => 'Upload incompleto, riprova.',
                    UPLOAD_ERR_NO_FILE => 'Nessun file selezionato.',
                ];
                throw new RuntimeException($errMap[$errCode] ?? 'Errore upload file.');
            }

            $file = $_FILES['li_file'];
            if ($file['size'] > LI_MAX_UPLOAD) {
                throw new RuntimeException('File troppo grande. Massimo 25 MB.');
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, LI_ALLOWED_EXT, true)) {
                throw new RuntimeException('Formato non valido. Ammessi: ZIP, PDF, CSV.');
            }

            // Sposta in tmp sicuro
            $tmpDir = __DIR__ . '/tmp';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
            $tmpPath = $tmpDir . '/li_' . $u_id . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
                throw new RuntimeException('Impossibile salvare il file caricato.');
            }

            try {
                $importer = new LinkedInImporter($pdo, $u_id);
                $detail = $importer->syncFromFile($empId, $tmpPath, $vanity);
            } finally {
                @unlink($tmpPath);
            }

            if (function_exists('write_log')) {
                write_log('LinkedInImport', 'success',
                    sprintf('Import emp=%d da %s: %d imp, %d auto-cat, %d upd, %d err',
                        $empId, $ext, $detail['imported'], $detail['created_cert'],
                        $detail['updated'], $detail['errors']),
                    $u_id);
            }

            $msg = sprintf(
                "<div class='alert alert-success'><i class='fa-brands fa-linkedin'></i> Import completato: <strong>%d</strong> cert. importate · <strong>%d</strong> create a catalogo · <strong>%d</strong> aggiornate · <strong>%d</strong> invariate · <strong>%d</strong> errori.%s</div>",
                $detail['imported'], $detail['created_cert'], $detail['updated'],
                $detail['unchanged'], $detail['errors'],
                !empty($detail['profile_updated']) ? ' Profilo/CV aggiornato.' : ''
            );
        }

    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " .
               htmlspecialchars($e->getMessage()) . "</div>";
        if (function_exists('write_log')) {
            write_log('LinkedInImport', 'error', $e->getMessage(), $u_id);
        }
    }
}

// ─── Dati UI ───
$rows = $pdo->query(
    "SELECT e.id AS employee_id, e.first_name, e.last_name,
            COALESCE(u.email, e.personal_email, '') AS email,
            l.linkedin_vanity, l.last_sync_at,
            l.last_sync_imported, l.last_sync_updated, l.last_sync_unmatched,
            (SELECT COUNT(*) FROM user_certifications uc WHERE uc.employee_id = e.id) AS cert_count
       FROM employees e
       LEFT JOIN users u ON u.employee_id = e.id AND u.status = 'active'
       LEFT JOIN employee_linkedin_link l ON l.employee_id = e.id
      WHERE e.status = 'active'
      GROUP BY e.id
      ORDER BY (l.linkedin_vanity IS NULL), e.last_name, e.first_name"
)->fetchAll(PDO::FETCH_ASSOC);

$totalLinked = 0;
foreach ($rows as $r) if (!empty($r['linkedin_vanity'])) $totalLinked++;

require_once('header.php');
?>

<style>
.li-card { background:#fff; border-radius:12px; box-shadow:0 2px 6px rgba(0,0,0,.06); padding:18px; margin-bottom:14px }
.li-stat { display:inline-block; background:#eff6ff; color:#1e40af; padding:3px 10px; border-radius:6px; font-size:11px; font-weight:700; margin-right:6px }
.li-stat.ok { background:#dcfce7; color:#166534 }
.li-stat.warn { background:#fef3c7; color:#92400e }
.li-tbl { width:100%; border-collapse:collapse; font-size:13px }
.li-tbl th { background:#f1f5f9; padding:10px; text-align:left; font-size:11px; text-transform:uppercase; color:#475569; font-weight:700 }
.li-tbl td { padding:10px; border-bottom:1px solid #e2e8f0; vertical-align:middle }
.li-tbl tr:hover td { background:#f8fafc }
.li-pill { display:inline-flex; align-items:center; gap:4px; background:#0a66c2; color:#fff; padding:2px 9px; border-radius:5px; font-size:11px; font-weight:600; text-decoration:none }
.li-pill:hover { background:#004182; color:#fff }
.li-no-link { color:#94a3b8; font-size:11px; font-style:italic }
.li-btn-sm { padding:5px 10px; font-size:11px; border-radius:6px; border:0; cursor:pointer; font-weight:600 }
.li-btn-import { background:#0a66c2; color:#fff }
.li-btn-import:hover { background:#004182 }
.li-btn-link { background:#0a66c2; color:#fff }
.li-btn-del { background:#ef4444; color:#fff }
.li-result { background:#f8fafc; border-radius:8px; padding:12px; margin-top:10px; font-size:12px; max-height:320px; overflow-y:auto }
.li-result-row { padding:5px 0; border-bottom:1px solid #e2e8f0 }
.li-result-row:last-child { border-bottom:0 }
.li-howto { background:linear-gradient(135deg,#eff6ff,#f0f9ff); border:1px solid #bfdbfe; border-radius:10px; padding:14px; font-size:12px; color:#1e3a5f; line-height:1.7 }
.li-howto ol { margin:6px 0 0 18px }
.li-howto code { background:#dbeafe; padding:1px 5px; border-radius:4px; font-size:11px }
</style>

<div style="max-width:1200px;margin:0 auto">

  <div style="margin-bottom:18px">
    <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
      <i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> Integrazione LinkedIn
    </h1>
    <div style="color:var(--muted);font-size:13px">
      Importa certificazioni e curriculum dei dipendenti dall'export ufficiale LinkedIn.
    </div>
  </div>

  <?= $msg ?>

  <!-- Istruzioni -->
  <div class="li-card">
    <h3 style="font-size:14px;font-weight:800;margin-bottom:8px">
      <i class="fa-solid fa-circle-info" style="color:#0a66c2"></i> Come ottenere l'export LinkedIn
    </h3>
    <div class="li-howto">
      <strong>Lo scraping diretto dei profili LinkedIn non è consentito dai termini di servizio LinkedIn.</strong>
      Il dipendente deve fornire il proprio export ufficiale, in uno di questi modi:
      <ol>
        <li><strong>Archivio dati completo (consigliato)</strong>: su LinkedIn →
          <code>Impostazioni e Privacy</code> → <code>Privacy dei dati</code> →
          <code>Ottieni una copia dei tuoi dati</code> → seleziona <em>"Vuoi qualcosa in particolare"</em> →
          spunta almeno <em>Certifications</em>, <em>Profile</em>, <em>Positions</em>, <em>Skills</em> →
          <code>Richiedi archivio</code>. Pronto in ~10 minuti, arriva via email come file <code>.zip</code>.</li>
        <li><strong>PDF del profilo</strong>: sul proprio profilo →
          <code>Altro</code> → <code>Salva come PDF</code>. Carica il <code>.pdf</code> ottenuto.</li>
        <li><strong>Singolo CSV</strong>: se hai già estratto <code>Certifications.csv</code> o
          <code>Profile.csv</code>, puoi caricare direttamente quello.</li>
      </ol>
    </div>
  </div>

  <!-- Statistiche -->
  <div class="li-card">
    <span class="li-stat"><?= count($rows) ?> dipendenti attivi</span>
    <span class="li-stat ok"><?= $totalLinked ?> con LinkedIn collegato</span>
    <?php if (count($rows) - $totalLinked > 0): ?>
      <span class="li-stat warn"><?= count($rows) - $totalLinked ?> da collegare</span>
    <?php endif; ?>
  </div>

  <!-- Dettaglio esito -->
  <?php if ($detail && !empty($detail['detail'])): ?>
  <div class="li-card">
    <h3 style="font-size:14px;font-weight:800;margin-bottom:10px">Dettaglio import</h3>
    <div class="li-result">
      <?php foreach ($detail['detail'] as $d):
        $colors = [
          'imported'=>'#166534','created_cert'=>'#0a66c2','updated'=>'#1e40af',
          'unchanged'=>'#64748b','unmatched'=>'#92400e','error'=>'#991b1b'
        ];
        $color = $colors[$d['result']] ?? '#475569';
        $typeIcon = [
          'certification'=>'fa-certificate','profile'=>'fa-id-card',
          'cv'=>'fa-file-pdf'
        ][$d['type']] ?? 'fa-circle';
      ?>
        <div class="li-result-row">
          <i class="fa-solid <?= $typeIcon ?>" style="color:#94a3b8;width:14px"></i>
          <span style="color:<?= $color ?>;font-weight:700;text-transform:uppercase;font-size:10px;display:inline-block;width:95px"><?= h($d['result']) ?></span>
          <?= h($d['name']) ?>
          <?php if (!empty($d['note'])): ?>
            <span style="color:#991b1b;font-size:11px"> &mdash; <?= h($d['note']) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tabella dipendenti -->
  <div class="li-card" style="padding:0;overflow:hidden">
    <table class="li-tbl">
      <thead>
        <tr>
          <th>Dipendente</th>
          <th>Profilo LinkedIn</th>
          <th>Ultimo import</th>
          <th>Cert. totali</th>
          <th style="text-align:right">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <strong><?= h($r['last_name'] . ' ' . $r['first_name']) ?></strong>
            <div style="font-size:11px;color:#94a3b8"><?= h($r['email']) ?></div>
          </td>
          <td>
            <?php if (!empty($r['linkedin_vanity'])): ?>
              <a href="https://www.linkedin.com/in/<?= h($r['linkedin_vanity']) ?>" target="_blank" rel="noopener" class="li-pill" title="Apri profilo LinkedIn">
                <i class="fa-brands fa-linkedin" style="font-size:11px"></i>
                <?= h($r['linkedin_vanity']) ?>
                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:8px"></i>
              </a>
            <?php else: ?>
              <span class="li-no-link">— non collegato —</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['last_sync_at'])): ?>
              <div style="font-size:11px"><?= date('d/m/Y H:i', strtotime($r['last_sync_at'])) ?></div>
              <div style="font-size:10px;color:#64748b">
                +<?= (int)$r['last_sync_imported'] ?> nuove
                · &Delta;<?= (int)$r['last_sync_updated'] ?>
              </div>
            <?php else: ?>
              <span class="li-no-link">mai</span>
            <?php endif; ?>
          </td>
          <td><strong><?= (int)$r['cert_count'] ?></strong></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (!empty($r['linkedin_vanity'])): ?>
              <button type="button" class="li-btn-sm li-btn-import"
                      onclick="openImport(<?= (int)$r['employee_id'] ?>, '<?= h(addslashes($r['last_name'] . ' ' . $r['first_name'])) ?>')">
                <i class="fa-solid fa-file-import"></i> Importa
              </button>
              <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Scollegare LinkedIn da questo dipendente? Le certificazioni già importate restano.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unlink">
                <input type="hidden" name="employee_id" value="<?= (int)$r['employee_id'] ?>">
                <button type="submit" class="li-btn-sm li-btn-del" title="Scollega">
                  <i class="fa-solid fa-link-slash"></i>
                </button>
              </form>
            <?php else: ?>
              <button type="button" class="li-btn-sm li-btn-link"
                      onclick="openLink(<?= (int)$r['employee_id'] ?>, '<?= h(addslashes($r['last_name'] . ' ' . $r['first_name'])) ?>')">
                <i class="fa-brands fa-linkedin"></i> Collega
              </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Modal: collega vanity -->
  <div id="linkModal" class="li-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;max-width:500px;width:90%;padding:24px">
      <h3 style="font-size:16px;font-weight:800;margin-bottom:10px">
        <i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> Collega profilo LinkedIn
      </h3>
      <div style="font-size:12px;color:#64748b;margin-bottom:14px">
        Dipendente: <strong id="linkEmpName"></strong>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="link">
        <input type="hidden" name="employee_id" id="linkEmpId">
        <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:4px">URL profilo LinkedIn o username</label>
        <input type="text" name="linkedin_url" required
               placeholder="es. https://www.linkedin.com/in/a-orru750122"
               style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px">
        <div style="font-size:11px;color:#64748b;margin-top:6px">
          Accetta URL completo o solo lo username (la parte dopo <code>/in/</code>).
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
          <button type="button" onclick="closeModal('linkModal')" class="btn">Annulla</button>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-link"></i> Salva</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: import file -->
  <div id="importModal" class="li-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;max-width:560px;width:90%;padding:24px">
      <h3 style="font-size:16px;font-weight:800;margin-bottom:10px">
        <i class="fa-solid fa-file-import" style="color:#0a66c2"></i> Importa export LinkedIn
      </h3>
      <div style="font-size:12px;color:#64748b;margin-bottom:14px">
        Dipendente: <strong id="importEmpName"></strong>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="employee_id" id="importEmpId">

        <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:6px">
          File export LinkedIn (ZIP / PDF / CSV — max 25 MB)
        </label>
        <input type="file" name="li_file" required accept=".zip,.pdf,.csv"
               style="width:100%;padding:9px;border:1px dashed #94a3b8;border-radius:7px;font-size:13px;background:#f8fafc">

        <div style="font-size:11px;color:#64748b;margin-top:8px;line-height:1.6">
          <strong>ZIP</strong>: archivio dati completo (consigliato, importa cert + CV + skill).<br>
          <strong>PDF</strong>: profilo salvato come PDF (importa cert + summary, salva il PDF come CV).<br>
          <strong>CSV</strong>: singolo file <code>Certifications.csv</code> o <code>Profile.csv</code>.
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
          <button type="button" onclick="closeModal('importModal')" class="btn">Annulla</button>
          <button type="submit" class="btn btn-primary" id="importSubmitBtn">
            <i class="fa-solid fa-cloud-arrow-up"></i> Carica e importa
          </button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
function openLink(id, name) {
  document.getElementById('linkEmpId').value = id;
  document.getElementById('linkEmpName').textContent = name;
  document.getElementById('linkModal').style.display = 'flex';
}
function openImport(id, name) {
  document.getElementById('importEmpId').value = id;
  document.getElementById('importEmpName').textContent = name;
  document.getElementById('importModal').style.display = 'flex';
}
function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}
document.querySelectorAll('.li-modal').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
});
// Disabilita bottone submit durante upload (evita doppi invii)
document.querySelector('#importModal form')?.addEventListener('submit', () => {
  const b = document.getElementById('importSubmitBtn');
  b.disabled = true;
  b.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Import in corso...';
});
</script>

<?php require_once('footer.php'); ?>
