<?php
/**
 * PortalManager — cert_import_cisco.php  (v1.7.55)
 *
 * Importer certificazioni Cisco dal report XLSX "Certifications by Individual"
 * (cpapp_admin_cnt_xls_report_CertInd.xlsx).
 *
 * Layout colonne atteso (riga 1 = header):
 *   A Be id | B Partner Name | C Site Id | D Contact Id | E First Name
 *   F Middle Initial | G Last Name | H Email | I CCO Login Id | J Training ID
 *   K Certification (codice) | L Certification Description | M Cert Date
 *   N Expiry Date | O Re-Cert Date | P Certification Contact
 *
 * Comportamento:
 *   - Brand fisso "Cisco" (auto-creato se assente).
 *   - Match dipendente: email (business/personal, case-insensitive) → fallback nome+cognome.
 *   - Righe con "Cert Date" pre-selezionate (certificazione acquisita).
 *     Le righe senza data (tracce/esami) sono importabili opzionalmente
 *     (v1.7.56): issue_date NULL, non azzera date preesistenti in update.
 *   - Cert catalogo: match (brand_id, code) → fallback (brand_id, name); auto-create.
 *   - user_certifications: UPSERT logico per (employee_id, certification_id):
 *       esiste → UPDATE (expiry/status/certificate_code); non esiste → INSERT.
 *   - Date formato "18-Oct-2023" (d-M-Y, mesi EN).
 *
 * Flow:
 *   Step 1 GET            → form upload XLSX + opzioni
 *   Step 2 POST upload    → parse + match → sessione → preview
 *   Step 3 POST execute   → transazione UPSERT
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/Csrf.php');

$u_id  = (int)($_SESSION['user_id'] ?? 0);
$can_e = can('create', 'cert_import_cisco.php') || can('create', 'upload_certificato.php');
if (!can('view', 'cert_import_cisco.php') && !can('view', 'upload_certificato.php')) {
    redirect('catalogo_certificazioni');
}

$msg  = '';
$step = 'upload';

// ── Mappa dipendenti (email + nome) ──────────────────────────────────────
$employees = $pdo->query("
    SELECT id, first_name, last_name, business_email, personal_email
      FROM employees
")->fetchAll();
$emp_by_email = [];
$emp_by_name  = [];
foreach ($employees as $e) {
    foreach ([$e['business_email'], $e['personal_email']] as $em) {
        $em = mb_strtolower(trim((string)$em));
        if ($em !== '') $emp_by_email[$em] = (int)$e['id'];
    }
    $nk = mb_strtolower(trim($e['first_name'] . ' ' . $e['last_name']));
    if ($nk !== ' ') $emp_by_name[$nk] = (int)$e['id'];
}

// ── Parser XLSX nativo (ZipArchive + SimpleXML, no dipendenze esterne) ────
function cisco_parse_xlsx_native(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("File XLSX non valido");

    $shared = [];
    if (($sx = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($sx);
        if ($xml) foreach ($xml->si as $si) {
            $txt = '';
            if (isset($si->t)) $txt = (string)$si->t;
            elseif (isset($si->r)) foreach ($si->r as $r) $txt .= (string)$r->t;
            $shared[] = $txt;
        }
    }
    // Individua il primo sheet reale via workbook rels (fallback sheet1.xml)
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet_xml === false) {
        for ($i = 1; $i <= 10; $i++) {
            $sheet_xml = $zip->getFromName("xl/worksheets/sheet$i.xml");
            if ($sheet_xml !== false) break;
        }
    }
    $zip->close();
    if (!$sheet_xml) throw new RuntimeException("Foglio di lavoro non trovato nel file XLSX");

    $xml  = simplexml_load_string($sheet_xml);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $r = [];
        $col_idx = 0;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            if ($ref) {
                preg_match('/^([A-Z]+)/', $ref, $m);
                $letters = $m[1] ?? 'A';
                $idx = 0;
                for ($i = 0; $i < strlen($letters); $i++) $idx = $idx * 26 + (ord($letters[$i]) - 64);
                $col_idx = $idx - 1;
            }
            $t   = (string)$c['t'];
            $val = (string)$c->v;
            if ($t === 's')             $val = $shared[(int)$val] ?? '';
            elseif ($t === 'inlineStr') $val = (string)$c->is->t;
            $r[$col_idx] = trim($val);
        }
        if (!empty($r)) {
            $max = max(array_keys($r));
            $out = [];
            for ($i = 0; $i <= $max; $i++) $out[] = $r[$i] ?? '';
            $rows[] = $out;
        }
    }
    return $rows;
}

// ── Date "18-Oct-2023" → "2023-10-18" (mesi inglesi, locale-independent) ──
function cisco_date_iso(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    $d = DateTime::createFromFormat('d-M-Y', $s);
    if ($d instanceof DateTime) return $d->format('Y-m-d');
    // fallback: prova altri formati comuni
    foreach (['Y-m-d', 'd/m/Y', 'm/d/Y'] as $f) {
        $d = DateTime::createFromFormat($f, $s);
        if ($d instanceof DateTime) return $d->format('Y-m-d');
    }
    return null;
}

// ── Inferenza livello/categoria da codice/descrizione ────────────────────
function cisco_infer_level(string $code, string $desc): ?string {
    $h = strtoupper($code . ' ' . $desc);
    if (str_contains($h, 'CCIE') || str_contains($h, 'EXPERT'))      return 'Expert';
    if (str_contains($h, 'CCNP') || str_contains($h, 'PROFESSIONAL')) return 'Professional';
    if (str_contains($h, 'CCNA') || str_contains($h, 'ASSOCIATE'))    return 'Associate';
    if (str_contains($h, 'SPECIALIST'))                              return 'Specialist';
    if (str_contains($h, 'SPECIALIST') || str_contains($h, 'CCS-'))  return 'Specialty';
    return null;
}
function cisco_infer_category(string $code, string $desc): string {
    $h = strtoupper($code . ' ' . $desc);
    // Esami sales/commerciali Cisco: serie 700-xxx, "Sales", "Success Manager", "SaaS Authorization"
    if (preg_match('/\b700-\d{3}\b/', $h) || str_contains($h, 'SALES')
        || str_contains($h, 'SUCCESS MANAGER') || str_contains($h, 'SAAS AUTHORIZATION')
        || str_contains($h, 'RENEWALS') || str_contains($h, 'SUSTAINABILITY')) {
        return 'commerciale';
    }
    return 'tecnica';
}

// ── STEP 2: upload + parse + match ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    Csrf::verify();
    if (empty($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "<div class='alert alert-danger'>Errore upload file.</div>";
    } else {
        $ext = strtolower(pathinfo($_FILES['xlsx_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            $msg = "<div class='alert alert-danger'>Formato non supportato: .$ext (atteso .xlsx).</div>";
        } else {
            try {
                $raw = cisco_parse_xlsx_native($_FILES['xlsx_file']['tmp_name']);
                if (count($raw) < 2) throw new RuntimeException("File vuoto o senza righe dati.");

                // Header map case-insensitive con sinonimi
                $hdr = array_map(fn($h) => mb_strtolower(trim((string)$h)), $raw[0]);
                $find = function(array $aliases) use ($hdr): int {
                    foreach ($hdr as $i => $h) if (in_array($h, $aliases, true)) return $i;
                    return -1;
                };
                $ci = [
                    'first'   => $find(['first name','firstname','nome']),
                    'last'    => $find(['last name','lastname','cognome']),
                    'email'   => $find(['email','e-mail','mail']),
                    'cco'     => $find(['cco login id','cco','cco login']),
                    'trainid' => $find(['training id','training','cisco id']),
                    'code'    => $find(['certification','cert','certification code','codice']),
                    'desc'    => $find(['certification description','description','descrizione']),
                    'cert'    => $find(['cert date','certification date','issue date','data']),
                    'expiry'  => $find(['expiry date','expiration date','scadenza']),
                    'recert'  => $find(['re-cert date','recert date','re-cert']),
                ];
                if ($ci['code'] < 0 && $ci['desc'] < 0) {
                    throw new RuntimeException("Header non riconosciuto: manca la colonna 'Certification'.");
                }

                $parsed = [];
                for ($i = 1; $i < count($raw); $i++) {
                    $row = $raw[$i];
                    $get = fn($k) => ($ci[$k] >= 0 && isset($row[$ci[$k]])) ? trim((string)$row[$ci[$k]]) : '';

                    $code = $get('code');
                    $desc = $get('desc');
                    if ($code === '' && $desc === '') continue;

                    $first = $get('first'); $last = $get('last');
                    $email = mb_strtolower($get('email'));
                    $name  = trim($first . ' ' . $last);

                    // Match dipendente: email → nome
                    $eid = 0; $match_by = '';
                    if ($email !== '' && isset($emp_by_email[$email]))              { $eid = $emp_by_email[$email]; $match_by = 'email'; }
                    elseif (isset($emp_by_name[mb_strtolower($name)]))             { $eid = $emp_by_name[mb_strtolower($name)]; $match_by = 'nome'; }

                    $issue = cisco_date_iso($get('cert'));

                    $parsed[] = [
                        'emp_id'    => $eid,
                        'match_by'  => $match_by,
                        'name'      => $name ?: '(sconosciuto)',
                        'email'     => $email,
                        'cco'       => $get('cco'),
                        'training'  => $get('trainid'),
                        'code'      => $code,
                        'desc'      => $desc ?: $code,
                        'issue'     => $issue,
                        'expiry'    => cisco_date_iso($get('expiry')),
                        'recert'    => cisco_date_iso($get('recert')),
                    ];
                }

                if (empty($parsed)) {
                    $msg = "<div class='alert alert-warning'>Nessuna riga certificazione riconosciuta.</div>";
                } else {
                    $_SESSION['cisco_import_data'] = $parsed;
                    $step = 'preview';
                }
            } catch (\Throwable $e) {
                $msg = "<div class='alert alert-danger'>Errore parsing: " . h($e->getMessage()) . "</div>";
            }
        }
    }
}

// ── STEP 3: execute ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    Csrf::verify();
    if (!$can_e) { redirect('cert_import_cisco'); }

    $rows      = $_SESSION['cisco_import_data'] ?? [];
    $sel       = $_POST['rows'] ?? [];
    $skip_dup  = !empty($_POST['skip_dup']);       // salta se già presente (invece di aggiornare)
    $imported = 0; $updated = 0; $created_certs = 0; $skipped = 0; $errors = [];

    try {
        $pdo->beginTransaction();

        // Brand Cisco (auto-create)
        $bq = $pdo->prepare("SELECT id FROM brands WHERE LOWER(name)='cisco' LIMIT 1");
        $bq->execute();
        $brand_id = (int)$bq->fetchColumn();
        if (!$brand_id) {
            $pdo->prepare("INSERT INTO brands (name, partnership_level, priority, priority_color) VALUES ('Cisco','Registered',2,'#049fd9')")->execute();
            $brand_id = (int)$pdo->lastInsertId();
        }

        // Technology fallback "Generic" (technology_id NOT NULL su certifications)
        $tq = $pdo->prepare("SELECT id FROM technologies WHERE name='Generic' LIMIT 1");
        $tq->execute();
        $tech_id = (int)$tq->fetchColumn();
        if (!$tech_id) {
            $pdo->prepare("INSERT INTO technologies (name, description) VALUES ('Generic','Tecnologia generica per cert importate')")->execute();
            $tech_id = (int)$pdo->lastInsertId();
        }

        foreach ($sel as $idx) {
            $idx = (int)$idx;
            if (!isset($rows[$idx])) continue;
            $r = $rows[$idx];

            if ($r['emp_id'] <= 0)  { $skipped++; $errors[] = "Riga " . ($idx+1) . ": dipendente '" . $r['name'] . "' non trovato in anagrafica."; continue; }
            // v1.7.56: righe senza data ammesse (issue_date NULL)

            // 1. Cert catalogo: match (brand, code) → (brand, name)
            $cert_id = 0;
            if ($r['code'] !== '') {
                $cq = $pdo->prepare("SELECT id FROM certifications WHERE brand_id=? AND code=? LIMIT 1");
                $cq->execute([$brand_id, $r['code']]); $cert_id = (int)$cq->fetchColumn(); $cq->closeCursor();
            }
            if (!$cert_id) {
                $cq = $pdo->prepare("SELECT id FROM certifications WHERE brand_id=? AND name=? LIMIT 1");
                $cq->execute([$brand_id, $r['desc']]); $cert_id = (int)$cq->fetchColumn(); $cq->closeCursor();
            }
            if (!$cert_id) {
                $lvl = cisco_infer_level($r['code'], $r['desc']);
                $lvl = in_array($lvl, ['Foundation','Associate','Professional','Expert','Specialty','Specialist'], true) ? $lvl : null;
                $pdo->prepare("
                    INSERT INTO certifications (brand_id, technology_id, name, code, category, level, is_active, notes)
                    VALUES (?, ?, ?, ?, ?, ?, 1, 'Auto-creata da import Cisco')
                ")->execute([$brand_id, $tech_id, $r['desc'], $r['code'] ?: null, cisco_infer_category($r['code'], $r['desc']), $lvl]);
                $cert_id = (int)$pdo->lastInsertId();
                $created_certs++;
            }

            // 2. Stato in base a expiry
            $status = 'active';
            if (!empty($r['expiry'])) {
                $days = (strtotime($r['expiry']) - time()) / 86400;
                if ($days < 0)       $status = 'expired';
                elseif ($days <= 90) $status = 'expiring';
            }
            $note = "Import Cisco\nCCO: " . ($r['cco'] ?: '-')
                  . "\nTraining ID: " . ($r['training'] ?: '-')
                  . ($r['recert'] ? "\nRe-Cert: " . $r['recert'] : '');

            // 3. UPSERT logico user_certifications per (employee_id, certification_id)
            $dq = $pdo->prepare("SELECT id FROM user_certifications WHERE employee_id=? AND certification_id=? LIMIT 1");
            $dq->execute([$r['emp_id'], $cert_id]);
            $uc_id = (int)$dq->fetchColumn(); $dq->closeCursor();

            if ($uc_id) {
                if ($skip_dup) { $skipped++; continue; }
                // COALESCE: una riga senza data non azzera un issue_date già presente
                $pdo->prepare("
                    UPDATE user_certifications
                       SET issue_date=COALESCE(?, issue_date),
                           expiry_date=COALESCE(?, expiry_date),
                           status=?, certificate_code=COALESCE(?, certificate_code), notes=?
                     WHERE id=?
                ")->execute([$r['issue'], $r['expiry'], $status, $r['training'] ?: null, $note, $uc_id]);
                $updated++;
            } else {
                $pdo->prepare("
                    INSERT INTO user_certifications
                      (employee_id, certification_id, issue_date, expiry_date, status, certificate_code, notes, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$r['emp_id'], $cert_id, $r['issue'], $r['expiry'], $status, $r['training'] ?: null, $note, $u_id]);
                $imported++;
            }
        }

        $pdo->commit();
        write_log('Certificazioni', 'success',
            "Import Cisco: $imported nuove, $updated aggiornate, $created_certs cert nuove a catalogo, $skipped saltate", $u_id);

        $err_html = $errors ? "<br><small>" . implode('<br>', array_map('h', array_slice($errors, 0, 30))) . "</small>" : '';
        $msg = "<div class='alert alert-success'>
            <i class='fa-solid fa-circle-check'></i> <strong>Import Cisco completato.</strong><br>
            • Certificazioni inserite: <strong>$imported</strong><br>
            • Certificazioni aggiornate: <strong>$updated</strong><br>
            • Nuove voci a catalogo: <strong>$created_certs</strong><br>
            • Saltate: <strong>$skipped</strong>$err_html
        </div>";
        unset($_SESSION['cisco_import_data']);
        $step = 'done';
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = "<div class='alert alert-danger'>Errore import: " . h($e->getMessage()) . "</div>";
        $step = 'preview';
    }
}

require_once('header.php');
$preview = $_SESSION['cisco_import_data'] ?? [];
?>
<div style="max-width:1200px;margin:0 auto">
  <h1 style="font-size:22px;margin-bottom:4px"><i class="fa-solid fa-file-import" style="color:#049fd9"></i> Import certificazioni Cisco</h1>
  <p style="color:#64748b;font-size:13px;margin-bottom:18px">Report XLSX Cisco "Certifications by Individual". Brand fisso <strong>Cisco</strong>, match dipendente per email/nome, le righe con data di conseguimento sono pre-selezionate; quelle senza data sono importabili opzionalmente.</p>

  <?= $msg ?>

  <?php if ($step === 'upload' || $step === 'done'): ?>
  <div class="card" style="padding:18px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;max-width:640px">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload">
      <div class="form-group">
        <label style="font-weight:600;font-size:13px">File XLSX Cisco</label>
        <input type="file" name="xlsx_file" accept=".xlsx" required
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff">
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:10px">
        <i class="fa-solid fa-magnifying-glass"></i> Analizza file
      </button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($step === 'preview' && !empty($preview)):
    $n_match = 0; $n_import = 0; $n_nomatch = 0; $n_nodate = 0;
    foreach ($preview as $p) {
        if ($p['emp_id'] > 0) $n_match++; else $n_nomatch++;
        if ($p['emp_id'] > 0 && !empty($p['issue'])) $n_import++;
        if (empty($p['issue'])) $n_nodate++;
    }
  ?>
  <div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px">
    Righe totali: <strong><?= count($preview) ?></strong> ·
    Dipendenti riconosciuti: <strong><?= $n_match ?></strong> ·
    Con data (pre-selezionate): <strong style="color:#059669"><?= $n_import ?></strong> ·
    Senza match: <strong style="color:#dc2626"><?= $n_nomatch ?></strong> ·
    Senza data (opzionali): <strong style="color:#b45309"><?= $n_nodate ?></strong>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="execute">
    <div style="display:flex;gap:16px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
      <label style="font-size:13px"><input type="checkbox" name="skip_dup" value="1"> Salta esistenti (non aggiornare)</label>
      <label style="font-size:13px;color:#b45309"><input type="checkbox" id="incl_nodate" onclick="toggleNoDate(this.checked)"> Includi certificazioni senza data</label>
      <button type="button" onclick="document.querySelectorAll('.rk:not(:disabled)').forEach(c=>c.checked=true)" class="btn" style="font-size:12px;padding:4px 10px">Seleziona tutti</button>
      <button type="button" onclick="document.querySelectorAll('.rk').forEach(c=>c.checked=false)" class="btn" style="font-size:12px;padding:4px 10px">Deseleziona</button>
    </div>
    <script>
    function toggleNoDate(on){
      document.querySelectorAll('.rk.nodate').forEach(c=>{ c.disabled=!on; if(!on) c.checked=false; else c.checked=true; });
    }
    </script>

    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px">
    <table style="width:100%;border-collapse:collapse;font-size:12px;background:#fff">
      <thead>
        <tr style="background:#f8fafc;text-align:left">
          <th style="padding:8px"><input type="checkbox" onclick="document.querySelectorAll('.rk:not(:disabled)').forEach(c=>c.checked=this.checked)"></th>
          <th style="padding:8px">Dipendente</th>
          <th style="padding:8px">Match</th>
          <th style="padding:8px">Codice</th>
          <th style="padding:8px">Certificazione</th>
          <th style="padding:8px">Conseguita</th>
          <th style="padding:8px">Scadenza</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $i => $p):
            $matched  = ($p['emp_id'] > 0);
            $has_date = !empty($p['issue']);
            // matched+data → importabile; matched senza data → attivabile via toggle; no match → mai
            $cls = 'rk' . ($matched && !$has_date ? ' nodate' : '');
            $checked  = $matched && $has_date ? 'checked' : '';
            $disabled = ($matched && $has_date) ? '' : 'disabled';
            $rowstyle = !$matched ? 'background:#fafafa;color:#94a3b8'
                      : (!$has_date ? 'background:#fffbeb' : '');
        ?>
        <tr style="border-top:1px solid #f1f5f9;<?= $rowstyle ?>">
          <td style="padding:7px"><input type="checkbox" class="<?= $cls ?>" name="rows[]" value="<?= $i ?>" <?= $checked ?> <?= $disabled ?>></td>
          <td style="padding:7px"><?= h($p['name']) ?><?php if ($p['email']): ?><br><span style="color:#94a3b8;font-size:11px"><?= h($p['email']) ?></span><?php endif; ?></td>
          <td style="padding:7px">
            <?php if ($p['emp_id']>0): ?>
              <span class="badge" style="background:#dcfce7;color:#166534;padding:2px 7px;border-radius:8px;font-size:10px"><?= h($p['match_by']) ?></span>
            <?php else: ?>
              <span class="badge" style="background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:8px;font-size:10px">no match</span>
            <?php endif; ?>
          </td>
          <td style="padding:7px;font-family:monospace"><?= h($p['code']) ?></td>
          <td style="padding:7px"><?= h($p['desc']) ?></td>
          <td style="padding:7px"><?= $p['issue'] ? h($p['issue']) : '<span style="color:#cbd5e1">—</span>' ?></td>
          <td style="padding:7px"><?= $p['expiry'] ? h($p['expiry']) : '<span style="color:#cbd5e1">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($can_e): ?>
    <button type="submit" class="btn btn-primary" style="margin-top:16px">
      <i class="fa-solid fa-database"></i> Importa selezionate
    </button>
    <?php else: ?>
    <p style="color:#dc2626;font-size:13px;margin-top:12px"><i class="fa-solid fa-lock"></i> Permesso di creazione non concesso.</p>
    <?php endif; ?>
    <a href="<?= url('cert_import_cisco') ?>" class="btn" style="margin-top:16px">Annulla</a>
  </form>
  <?php endif; ?>
</div>
<?php require_once('footer.php'); ?>
