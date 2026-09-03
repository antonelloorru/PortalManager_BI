<?php
/**
 * PortalManager — cert_import_cv.php
 *
 * Importer certificazioni da "Allegato CV" formato struttural-testuale:
 *   Data esame DD/MM/YYYY
 *   • Nome produttore <BRAND>
 *   • Principali materie <NOME_CERT>
 *   • Qualifica Personale conseguita <PERS_QUAL>
 *   • Qualifica Aziendale conseguita <AZ_QUAL>
 *
 * Supporta:
 *   - Upload PDF/TXT
 *   - Estrazione testo da PDF via CvParser::extractPdfTextNative()
 *   - Match brand esistenti (case-insensitive, normalizzazione)
 *   - Auto-creazione brand mancanti (opzionale)
 *   - Auto-creazione tecnologia generica per brand
 *   - Selezione employee target
 *   - Anteprima con possibilità di deselezionare singole righe
 *   - Categoria/level inferiti euristicamente
 *
 * Flow:
 *   Step 1: GET → form upload + select employee
 *   Step 2: POST upload → estrazione + parsing + sessione → mostra preview
 *   Step 3: POST execute → INSERT IGNORE per certifications + INSERT user_certifications
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/CvParser.php');

$u_id = (int)$_SESSION['user_id'];
$can_e = can('create', 'cert_import_cv.php') || can('create', 'upload_certificato.php');
if (!can('view', 'cert_import_cv.php') && !can('view', 'upload_certificato.php')) redirect('catalogo_certificazioni');

$msg = '';
$step = 'upload'; // upload | preview | done

// ── Liste base ──
$employees = $pdo->query("
    SELECT e.id, e.first_name, e.last_name, e.business_email, e.personal_email
      FROM employees e
     WHERE e.status='active'
     ORDER BY e.last_name, e.first_name
")->fetchAll();
$brands_db = $pdo->query("SELECT id, name FROM brands ORDER BY name")->fetchAll();
$brands_map = []; // lower → id
foreach ($brands_db as $b) $brands_map[mb_strtolower(trim($b['name']))] = (int)$b['id'];

// ──────────────────────────────────────────────────────────────────────
// Parser certificazioni da testo
// ──────────────────────────────────────────────────────────────────────
function parse_cert_blocks(string $text): array {
    // Normalizza spazi (no NBSP, no doppi spazi)
    $text = preg_replace('/\xC2\xA0/', ' ', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Split su "Data esame"
    $parts = preg_split('/(?=Data esame\s+\d{1,2}\/\d{1,2}\/\d{4})/u', $text);
    $out = [];
    foreach ($parts as $b) {
        $b = trim($b);
        if (!str_contains($b, 'Data esame')) continue;

        if (!preg_match('/Data esame\s+(\d{1,2})\/(\d{1,2})\/(\d{4})/u', $b, $md)) continue;
        $iso = sprintf('%04d-%02d-%02d', (int)$md[3], (int)$md[2], (int)$md[1]);

        // Brand, materia, personale, aziendale
        $brand = '';
        $topic = '';
        $pers  = '';
        $az    = '';
        if (preg_match('/Nome produttore\s+(.+?)(?=\n|$)/u', $b, $m)) $brand = trim($m[1]);
        if (preg_match('/Principali materie\s*(.*?)(?=\n|$)/u', $b, $m)) $topic = trim($m[1]);
        if (preg_match('/Qualifica Personale conseguita\s*(.*?)(?=\n|$)/u', $b, $m)) $pers = trim($m[1]);
        if (preg_match('/Qualifica Aziendale conseguita\s*(.*?)(?=\n|$)/u', $b, $m)) $az = trim($m[1]);

        if ($brand === '') continue;

        // Heuristic categoria
        $cmb = mb_strtolower($topic . ' ' . $pers);
        if (str_contains($cmb, 'sales') || str_contains($cmb, 'commercial')) $category = 'commerciale';
        elseif (str_contains($cmb, 'partner') && !str_contains($cmb, 'tech')) $category = 'commerciale';
        else $category = 'tecnica';

        // Heuristic level
        $level = '';
        foreach (['Expert','Professional','Associate','Specialist','Specialty','Foundation'] as $L) {
            if (stripos($topic . ' ' . $pers, $L) !== false) { $level = $L; break; }
        }

        // Code
        $code = '';
        if (preg_match('/\[([A-Z0-9\-]{3,30})\]/', $topic, $cm)) $code = $cm[1];
        elseif (preg_match('/\[([A-Z0-9\-]{3,30})\]/', $pers, $cm)) $code = $cm[1];

        // Nome cert: prima da topic, altrimenti da pers troncato
        $name = $topic !== '' ? $topic : mb_substr($pers, 0, 150);
        if ($name === '') continue;

        $out[] = [
            'issue_date' => $iso, 'brand' => $brand, 'name' => $name,
            'code' => $code, 'category' => $category, 'level' => $level,
            'personal_qual' => $pers, 'company_qual' => $az,
        ];
    }
    return $out;
}

// ──────────────────────────────────────────────────────────────────────
// STEP 2: Upload + parse
// ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    Csrf::verify();
    $emp_id = (int)($_POST['employee_id'] ?? 0);
    if ($emp_id <= 0) {
        $msg = "<div class='alert alert-danger'>Seleziona prima il dipendente target.</div>";
    } elseif (empty($_FILES['cv_file']) || $_FILES['cv_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "<div class='alert alert-danger'>Errore upload file.</div>";
    } else {
        $tmp = $_FILES['cv_file']['tmp_name'];
        $name = $_FILES['cv_file']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $content = file_get_contents($tmp);
        $txt = '';
        try {
            if ($ext === 'pdf') $txt = CvParser::extractPdfTextNative($content);
            elseif (in_array($ext, ['txt','md'])) $txt = $content;
            else throw new RuntimeException("Formato non supportato: $ext (usa PDF o TXT)");
        } catch (\Throwable $e) {
            $msg = "<div class='alert alert-danger'>Errore parsing: " . h($e->getMessage()) . "</div>";
        }
        if ($txt !== '') {
            $parsed = parse_cert_blocks($txt);
            if (empty($parsed)) {
                $msg = "<div class='alert alert-warning'>Nessuna certificazione riconosciuta nel formato atteso. Verifica il file.</div>";
            } else {
                $_SESSION['cert_import_data'] = $parsed;
                $_SESSION['cert_import_emp']  = $emp_id;
                $step = 'preview';
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────────────
// STEP 3: Execute import
// ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    Csrf::verify();
    $emp_id = (int)($_SESSION['cert_import_emp'] ?? 0);
    $rows   = $_SESSION['cert_import_data'] ?? [];
    $sel    = $_POST['rows'] ?? [];   // array di indici selezionati
    $create_brands = !empty($_POST['create_brands']);
    $skip_dup = !empty($_POST['skip_dup']);
    $imported = 0; $created_brands = 0; $created_certs = 0; $skipped = 0;
    $errors = [];

    try {
        $pdo->beginTransaction();

        // Get/create technology fallback "Generic"
        $tech_id_default = 0;
        $tq = $pdo->prepare("SELECT id FROM technologies WHERE name = ? LIMIT 1");
        $tq->execute(['Generic']);
        $tid = $tq->fetchColumn();
        if (!$tid) {
            $pdo->prepare("INSERT INTO technologies (name, description) VALUES ('Generic','Tecnologia generica per cert importate da CV')")->execute();
            $tech_id_default = (int)$pdo->lastInsertId();
        } else {
            $tech_id_default = (int)$tid;
        }

        foreach ($sel as $idx) {
            $idx = (int)$idx;
            if (!isset($rows[$idx])) continue;
            $r = $rows[$idx];

            // 1. Brand match (case-insensitive)
            $bkey = mb_strtolower(trim($r['brand']));
            $brand_id = $brands_map[$bkey] ?? 0;
            if (!$brand_id) {
                if ($create_brands) {
                    $pdo->prepare("INSERT INTO brands (name, partnership_level, priority, priority_color) VALUES (?, 'Registered', 5, '#94a3b8')")
                        ->execute([$r['brand']]);
                    $brand_id = (int)$pdo->lastInsertId();
                    $brands_map[$bkey] = $brand_id;
                    $created_brands++;
                } else {
                    $errors[] = "Riga " . ($idx+1) . ": brand '" . $r['brand'] . "' non esistente (abilita Crea brand)";
                    $skipped++;
                    continue;
                }
            }

            // 2. Cert match by (brand_id, name) — UPSERT logico
            $cq = $pdo->prepare("SELECT id FROM certifications WHERE brand_id = ? AND name = ? LIMIT 1");
            $cq->execute([$brand_id, $r['name']]);
            $cert_id = (int)$cq->fetchColumn();
            $cq->closeCursor();
            if (!$cert_id) {
                $level_clean = in_array($r['level'], ['Foundation','Associate','Professional','Expert','Specialty','Specialist'], true) ? $r['level'] : null;
                $pdo->prepare("
                    INSERT INTO certifications (brand_id, technology_id, name, code, category, level, is_active, notes)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                ")->execute([
                    $brand_id, $tech_id_default, $r['name'],
                    $r['code'] ?: null, $r['category'], $level_clean,
                    "Auto-creata da import CV. Qualifica aziendale: " . ($r['company_qual'] ?: '-'),
                ]);
                $cert_id = (int)$pdo->lastInsertId();
                $created_certs++;
            }

            // 3. user_certifications: skip se duplicato
            if ($skip_dup) {
                $dq = $pdo->prepare("SELECT id FROM user_certifications WHERE employee_id=? AND certification_id=? AND issue_date=?");
                $dq->execute([$emp_id, $cert_id, $r['issue_date']]);
                if ($dq->fetchColumn()) { $skipped++; $dq->closeCursor(); continue; }
                $dq->closeCursor();
            }

            $note = "Qualifica Personale: " . ($r['personal_qual'] ?: '-')
                  . "\nQualifica Aziendale: " . ($r['company_qual'] ?: '-');

            $pdo->prepare("
                INSERT INTO user_certifications
                  (employee_id, certification_id, issue_date, status, certificate_code, notes, uploaded_by)
                VALUES (?, ?, ?, 'active', ?, ?, ?)
            ")->execute([$emp_id, $cert_id, $r['issue_date'], $r['code'] ?: null, $note, $u_id]);
            $imported++;
        }

        $pdo->commit();
        write_log('Certificazioni', 'success',
            "Import CV: emp #$emp_id, $imported cert importate, $created_certs cert nuove nel catalogo, $created_brands brand creati",
            $u_id);

        $msg = "<div class='alert alert-success'>
            <i class='fa-solid fa-circle-check'></i> <strong>Import completato!</strong><br>
            • Certificazioni importate: <strong>$imported</strong><br>
            • Nuove cert aggiunte al catalogo: <strong>$created_certs</strong><br>
            • Brand auto-creati: <strong>$created_brands</strong><br>
            • Righe saltate (duplicati/errori): <strong>$skipped</strong>
            " . (!empty($errors) ? '<details><summary>Errori (' . count($errors) . ')</summary><ul style="margin-top:5px"><li>' . implode('</li><li>', array_map('h', $errors)) . '</li></ul></details>' : '') . "
        </div>";
        unset($_SESSION['cert_import_data'], $_SESSION['cert_import_emp']);
        $step = 'done';
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        $step = 'preview';
    }
}

if ($step === 'upload' && !empty($_SESSION['cert_import_data'])) {
    $step = 'preview';
}

require_once('header.php');
?>

<div style="margin-bottom:16px">
  <h2 style="margin:0;font-size:21px;color:#0f172a">
    <i class="fa-solid fa-file-import" style="color:#dc2626"></i> Import Certificazioni da CV
  </h2>
  <div style="font-size:12px;color:#64748b;margin-top:3px">
    Carica un PDF allegato CV in formato "Certificazioni acquisite" → estrazione automatica e import nel catalogo
  </div>
</div>

<?= $msg ?>

<?php if ($step === 'upload'): ?>
<!-- ─── STEP 1: Upload ─────────────────────────────────────────── -->
<div class="card" style="padding:16px">
  <h3 style="margin:0 0 12px 0;font-size:14px;color:#dc2626"><i class="fa-solid fa-1"></i> Carica CV allegato</h3>

  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Dipendente target *</label>
        <select name="employee_id" required style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
          <option value="">— Seleziona dipendente —</option>
          <?php foreach ($employees as $e): ?>
          <option value="<?= (int)$e['id'] ?>">
            <?= h($e['last_name'] . ' ' . $e['first_name']) ?>
            <?php $em = $e['business_email'] ?: $e['personal_email']; if ($em): ?>
            — <?= h($em) ?>
            <?php endif; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">File CV (PDF o TXT) *</label>
        <input type="file" name="cv_file" accept=".pdf,.txt" required style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
        <div style="font-size:10px;color:#94a3b8;margin-top:3px">Max 10 MB. Formato atteso: "Data esame DD/MM/YYYY", "Nome produttore", "Principali materie", "Qualifica Personale/Aziendale conseguita"</div>
      </div>
    </div>

    <div style="background:#fffbeb;border:1px solid #fde68a;padding:10px 12px;border-radius:6px;font-size:11px;color:#92400e;margin-bottom:12px">
      <strong><i class="fa-solid fa-circle-info"></i> Formato atteso del CV:</strong>
      <pre style="margin:6px 0 0 0;font-family:Consolas,monospace;font-size:10px;color:#78350f">Data esame 19/03/2026
• Nome produttore Acronis
• Principali materie Acronis Cyber Protect - Tech Pro
• Qualifica Personale conseguita Acronis Academy
• Qualifica Aziendale conseguita Platinum service provider</pre>
    </div>

    <button type="submit" class="btn btn-primary" style="background:#dc2626"><i class="fa-solid fa-upload"></i> Carica e analizza</button>
  </form>
</div>

<?php elseif ($step === 'preview'):
    $rows = $_SESSION['cert_import_data'] ?? [];
    $emp_id = (int)($_SESSION['cert_import_emp'] ?? 0);
    $emp_row = null;
    if ($emp_id) {
        $eq = $pdo->prepare("SELECT first_name, last_name, business_email FROM employees WHERE id = ?");
        $eq->execute([$emp_id]); $emp_row = $eq->fetch();
    }
    // Conta brand mancanti
    $missing_brands = [];
    foreach ($rows as $r) {
        $bk = mb_strtolower(trim($r['brand']));
        if (!isset($brands_map[$bk])) $missing_brands[$r['brand']] = true;
    }
?>
<!-- ─── STEP 2: Preview ────────────────────────────────────────── -->
<div class="card" style="padding:14px;background:#f0fdf4;border:1px solid #86efac;margin-bottom:12px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <strong style="color:#166534"><i class="fa-solid fa-circle-check"></i> Estratte <?= count($rows) ?> certificazioni</strong> per
      <?php if ($emp_row): ?>
      <strong><?= h($emp_row['last_name'] . ' ' . $emp_row['first_name']) ?></strong>
      <?php if ($emp_row['business_email']): ?> (<?= h($emp_row['business_email']) ?>)<?php endif; ?>
      <?php endif; ?>
    </div>
    <a href="<?= url_safe('cert_import_cv') ?>?reset=1" class="btn btn-sm" onclick="sessionStorage.clear()">Carica altro file</a>
  </div>
</div>

<?php if (!empty($missing_brands)): ?>
<div class="card" style="padding:12px;background:#fefce8;border:1px solid #fde68a;margin-bottom:12px">
  <strong style="color:#a16207"><i class="fa-solid fa-triangle-exclamation"></i> Brand non presenti nel sistema (<?= count($missing_brands) ?>):</strong>
  <div style="margin-top:6px;font-size:11px">
    <?php foreach (array_keys($missing_brands) as $mb): ?>
    <span style="display:inline-block;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;margin:2px;font-weight:600"><?= h($mb) ?></span>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:8px;font-size:11px;color:#78350f">Abilita "Crea brand mancanti" nella sezione conferma per importarli comunque (con partnership_level=Registered).</div>
</div>
<?php endif; ?>

<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="execute">

  <div class="card" style="padding:0;overflow:hidden">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead style="background:#1e293b;color:#fff">
        <tr>
          <th style="padding:8px;width:36px"><input type="checkbox" id="selAll" checked onchange="document.querySelectorAll('.cb-row').forEach(c=>c.checked=this.checked)"></th>
          <th style="padding:8px;text-align:left;font-size:10px">#</th>
          <th style="padding:8px;text-align:left;font-size:10px">Data esame</th>
          <th style="padding:8px;text-align:left;font-size:10px">Brand</th>
          <th style="padding:8px;text-align:left;font-size:10px">Certificazione</th>
          <th style="padding:8px;text-align:left;font-size:10px">Codice</th>
          <th style="padding:8px;text-align:left;font-size:10px">Categoria</th>
          <th style="padding:8px;text-align:left;font-size:10px">Level</th>
          <th style="padding:8px;text-align:left;font-size:10px">Qualifica Personale / Aziendale</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r):
          $bk = mb_strtolower(trim($r['brand']));
          $brand_ok = isset($brands_map[$bk]);
          $cat_colors = ['tecnica'=>'#16a34a','commerciale'=>'#0ea5e9','aziendale'=>'#7c3aed'];
          $cc = $cat_colors[$r['category']] ?? '#64748b';
        ?>
        <tr style="border-bottom:1px solid #f1f5f9;background:<?= $brand_ok ? '#fff' : '#fef9c3' ?>">
          <td style="padding:6px 8px;text-align:center"><input type="checkbox" class="cb-row" name="rows[]" value="<?= $i ?>" checked></td>
          <td style="padding:6px 8px;color:#94a3b8;font-size:10px"><?= $i+1 ?></td>
          <td style="padding:6px 8px;white-space:nowrap;font-weight:600"><?= h(date('d/m/Y', strtotime($r['issue_date']))) ?></td>
          <td style="padding:6px 8px">
            <?= h($r['brand']) ?>
            <?php if (!$brand_ok): ?><span style="font-size:9px;color:#a16207"> ⚠ nuovo</span><?php endif; ?>
          </td>
          <td style="padding:6px 8px;max-width:300px;font-size:11px"><?= h($r['name']) ?></td>
          <td style="padding:6px 8px;font-family:Consolas,monospace;font-size:10px;color:#64748b"><?= h($r['code']) ?: '—' ?></td>
          <td style="padding:6px 8px"><span style="background:<?= $cc ?>15;color:<?= $cc ?>;padding:2px 6px;border-radius:8px;font-size:9px;font-weight:700;text-transform:uppercase"><?= h($r['category']) ?></span></td>
          <td style="padding:6px 8px;font-size:10px;color:#64748b"><?= h($r['level']) ?: '—' ?></td>
          <td style="padding:6px 8px;font-size:10px;max-width:280px">
            <?php if ($r['personal_qual']): ?><div><strong>P:</strong> <?= h(mb_substr($r['personal_qual'], 0, 90)) ?></div><?php endif; ?>
            <?php if ($r['company_qual']):  ?><div style="color:#64748b"><strong>A:</strong> <?= h(mb_substr($r['company_qual'], 0, 90)) ?></div><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="padding:14px;margin-top:12px;background:#f0f9ff">
    <h3 style="margin:0 0 10px 0;font-size:13px"><i class="fa-solid fa-gears"></i> Opzioni import</h3>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px;cursor:pointer">
      <input type="checkbox" name="create_brands" value="1" checked>
      <span><strong>Crea brand mancanti</strong> automaticamente (con partnership_level = Registered)</span>
    </label>
    <label style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer">
      <input type="checkbox" name="skip_dup" value="1" checked>
      <span><strong>Salta duplicati</strong>: se esiste già la stessa cert/dipendente/data, non duplicare</span>
    </label>

    <div style="margin-top:14px;display:flex;gap:8px">
      <button type="submit" class="btn btn-primary" style="background:#16a34a" onclick="return confirm('Confermi l\'import delle certificazioni selezionate?')"><i class="fa-solid fa-check"></i> Esegui import</button>
      <a href="<?= url_safe('cert_import_cv') ?>?reset=1" class="btn">Annulla</a>
    </div>
  </div>
</form>

<?php endif; ?>

<?php
if (isset($_GET['reset'])) { unset($_SESSION['cert_import_data'], $_SESSION['cert_import_emp']); }
require_once('footer.php');
?>
