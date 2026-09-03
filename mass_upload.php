<?php
/**
 * certV 5.4.0 — mass_upload.php
 *
 * Importazione massiva refactored:
 *   1. Upload CSV  → crea job + carica righe in staging
 *   2. Auto-validate → marca righe valid/invalid
 *   3. Review (mass_upload_review.php) → editor griglia per correggere
 *   4. Commit       → importa righe valid/corrected con transazione per riga
 *
 * Tipi supportati: dipendenti, accessi, brand, tecnologie, catalogo, sedi,
 * agenzie, candidati, clienti, templates.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/ImportValidator.php';
require_once __DIR__ . '/app/ImportProcessor.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

if ($u_role > 2) {
    header('Location: unauthorized.php'); exit();
}

// ─── METADATA TIPI ─────────────────────────────────────────────────────
// 13 tipi di import organizzati per area
// [icon, color, label, hint, category]
$TYPES = [
    // Anagrafica
    'dipendenti'       => ['fa-users',          '#0ea5e9', 'Anagrafica dipendenti',   'Match: codice fiscale o employee_code', 'Anagrafica'],
    'accessi'          => ['fa-key',            '#5b21b6', 'Accessi (utenti login)',  'Match: email · password casuale generata', 'Anagrafica'],
    'sedi'             => ['fa-location-dot',   '#f59e0b', 'Sedi aziendali',          'Match: nome + azienda', 'Anagrafica'],
    // Catalogo certificazioni
    'brand'            => ['fa-tags',           '#8b5cf6', 'Brand certificazioni',    'Match: nome brand', 'Catalogo'],
    'tecnologie'       => ['fa-microchip',      '#10b981', 'Tecnologie (cross-brand)','Match: nome univoco · entità trasversale', 'Catalogo'],
    'tech_brand_links' => ['fa-link',           '#16a34a', 'Link tecnologia ↔ brand', 'Pivot N:M (un brand può coprire più tech, viceversa)', 'Catalogo'],
    'tech_cert_links'  => ['fa-diagram-project','#16a34a', 'Link tecnologia ↔ cert',  'Pivot N:M con relevance (primary/secondary/related)', 'Catalogo'],
    'catalogo'         => ['fa-certificate',    '#0284c7', 'Catalogo certificazioni', 'Match: codice univoco', 'Catalogo'],
    // Competenze dipendenti
    'certificati'      => ['fa-award',          '#10b981', 'Certificazioni conseguite','Match: dipendente + certificazione + data', 'Competenze'],
    'employee_skills'  => ['fa-wand-magic-sparkles', '#a855f7', 'Skill dipendenti',  'Skill matrix (tech, level, anni). Match: dipendente + skill', 'Competenze'],
    'piani_formativi'  => ['fa-graduation-cap', '#0ea5e9', 'Piani formativi',         'Match: dipendente + cert (se non completato)', 'Competenze'],
    'esami'            => ['fa-flask',          '#dc2626', 'Esami pianificati',       'Match: dipendente + data + cert', 'Competenze'],
    // Recruiting
    'agenzie'          => ['fa-building',       '#dc2626', 'Agenzie recruiting',      'Match: nome agenzia', 'Recruiting'],
    'contatti_agenzie' => ['fa-address-card',   '#92400e', 'Contatti agenzie',        'Match: agenzia + email referente', 'Recruiting'],
    'candidati'        => ['fa-users-line',     '#7c3aed', 'Candidati',               'Match: email', 'Recruiting'],
    'clienti'          => ['fa-building-user',  '#1e40af', 'Anagrafica clienti',      'Match: ragione sociale', 'Recruiting'],
    // Configurazione
    'templates'        => ['fa-layer-group',    '#5b21b6', 'Template posizioni',      'Smart versioning automatico', 'Configurazione'],
];

// ─── DOWNLOAD TEMPLATE CSV con metadati ricchi ─────────────────────────
if (isset($_GET['dl']) && isset($TYPES[$_GET['dl']])) {
    $type = $_GET['dl'];
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=template_$type.csv");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");  // BOM utf-8 per Excel

    $schema = ImportValidator::getSchema($type);
    $tinfo = $TYPES[$type];

    // ── Intestazione documentale (commenti # ignorati dal parser) ──
    fputcsv($out, ['# certV ' . ($tinfo[2] ?? $type) . ' — template di importazione massiva']);
    fputcsv($out, ['# ' . ($tinfo[3] ?? '')]);
    fputcsv($out, ['# Generato il ' . date('Y-m-d H:i')]);
    fputcsv($out, ['#']);
    fputcsv($out, ['# ISTRUZIONI:']);
    fputcsv($out, ['# 1. Le righe che iniziano con # vengono ignorate (commenti).']);
    fputcsv($out, ['# 2. NON modificare l\'ordine dei campi né l\'intestazione.']);
    fputcsv($out, ['# 3. Cancella la riga di esempio prima di caricare il file.']);
    fputcsv($out, ['# 4. Encoding: UTF-8. Separatore: virgola (auto-detect ; pure).']);
    fputcsv($out, ['# 5. Campi marcati [OBBLIGATORIO] non possono essere vuoti.']);
    fputcsv($out, ['# 6. I campi FK (es. brand_name, cert_code) devono ESISTERE in anagrafica.']);
    fputcsv($out, ['#']);
    fputcsv($out, ['# LEGENDA CAMPI:']);
    foreach ($schema as $field => $rules) {
        $label   = $rules['label']   ?? $field;
        $type_d  = $rules['type']    ?? 'string';
        $req     = !empty($rules['required']) ? '[OBBLIGATORIO]' : '[opzionale]';
        $hint    = $rules['hint']    ?? '';
        $enum    = isset($rules['enum'])    ? ' — Valori: ' . implode('|', $rules['enum'])     : '';
        $maxlen  = isset($rules['max_length']) ? " (max {$rules['max_length']} car.)"          : '';
        $fk      = isset($rules['fk'])      ? " — FK: {$rules['fk']}"                         : '';
        $default = isset($rules['default']) ? " — default: {$rules['default']}"               : '';
        fputcsv($out, ["#   $field — $label  $req  ($type_d$maxlen)$enum$fk$default  $hint"]);
    }
    fputcsv($out, ['#']);

    // ── Header reale CSV ──
    $headers = array_keys($schema);
    fputcsv($out, $headers);

    // ── Riga di esempio precompilata da metadati ──
    $example = [];
    foreach ($schema as $field => $rules) {
        if (isset($rules['example'])) {
            $example[] = $rules['example'];
        } else {
            $example[] = match ($rules['type'] ?? 'string') {
                'email'   => 'esempio@example.com',
                'date'    => '2024-01-31',
                'int'     => isset($rules['min']) ? (string)$rules['min'] : '0',
                'decimal' => '0.00',
                'bool'    => '1',
                'phone'   => '+39 333 1234567',
                'cf'      => 'RSSMRA85M01H501Z',
                'piva'    => '12345678901',
                'enum'    => $rules['enum'][0] ?? '',
                'url'     => 'https://example.com',
                default   => !empty($rules['required']) ? '...' : '',
            };
        }
    }
    fputcsv($out, $example);

    exit();
}

// ─── Skip righe commento (#) durante la lettura CSV ───────────────────

// ─── UPLOAD CSV → CREA JOB ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $type = $_POST['type'] ?? '';
    if (!isset($TYPES[$type])) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Tipo non valido.</div>";
        redirect_self();
    }
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore upload: " . ($_FILES['file']['error'] ?? '?') . "</div>";
        redirect_self();
    }
    if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>File troppo grande (max 10 MB).</div>";
        redirect_self();
    }

    $tmp = $_FILES['file']['tmp_name'];
    $h = @fopen($tmp, 'r');
    if (!$h) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Impossibile leggere il file.</div>";
        redirect_self();
    }

    // Rimuovi BOM se presente
    $first = fread($h, 3);
    if ($first !== "\xEF\xBB\xBF") rewind($h);

    // Auto-detect separatore (, o ;)
    $sample = fread($h, 4096);
    rewind($h);
    if ($first !== "\xEF\xBB\xBF") {
        // Reset header BOM check
    } else {
        fseek($h, 3);
    }
    $sep = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';

    // Skip righe commento (iniziano con #) prima di trovare header
    do {
        $pos = ftell($h);
        $headers = fgetcsv($h, 0, $sep);
        if ($headers === false) break;
        $first_cell = trim((string)($headers[0] ?? ''));
    } while ($first_cell !== '' && str_starts_with($first_cell, '#'));

    if (!$headers || count($headers) < 1) {
        fclose($h);
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>CSV vuoto o senza intestazioni.</div>";
        redirect_self();
    }
    $headers = array_map(fn($h) => trim($h), $headers);

    $rows = [];
    while (($r = fgetcsv($h, 0, $sep)) !== false) {
        if (count(array_filter($r, fn($v) => $v !== '' && $v !== null)) === 0) continue;  // skip righe vuote
        // Skip commenti
        $first = trim((string)($r[0] ?? ''));
        if ($first !== '' && str_starts_with($first, '#')) continue;
        $assoc = [];
        foreach ($headers as $i => $col) {
            $assoc[$col] = $r[$i] ?? null;
        }
        $rows[] = $assoc;
    }
    fclose($h);

    if (empty($rows)) {
        $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Il CSV non contiene righe dati.</div>";
        redirect_self();
    }

    try {
        // v5.5: flag Late Data Binding + accodamento async
        $allowPartial = !empty($_POST['allow_partial']);
        $asyncMode    = !empty($_POST['async_mode']);

        $proc = new ImportProcessor($pdo, $type);
        $jobId = $proc->createJob($rows, [
            'original_name' => $_FILES['file']['name'],
            'file_size'     => $_FILES['file']['size'],
            'created_by'    => $u_id,
            'allow_partial' => $allowPartial,
        ]);
        $stats = $proc->validateJob($jobId);

        // v5.5: in async mode, accoda il job (verrà committato dal worker)
        if ($asyncMode) {
            $proc->enqueueJob($jobId);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>" .
                "<i class='fa-solid fa-clock'></i> Job <strong>#$jobId</strong> accodato per processing asincrono. " .
                "Verrà importato in background dal worker. " .
                "<a href='mass_upload_jobs.php" . (!empty($_GET['r']) ? '?r=' . urlencode($_GET['r']) : '') . "'>Vedi storico</a>." .
                "</div>";
            header('Location: mass_upload.php' . (!empty($_GET['r']) ? '?r=' . urlencode($_GET['r']) : ''));
            exit();
        }

        $partialMsg = isset($stats['partial']) && $stats['partial'] > 0
            ? " + <strong style='color:#f59e0b'>{$stats['partial']} parziali</strong>"
            : '';

        $_SESSION['flash_msg'] = "<div class='alert alert-success'>" .
            "<i class='fa-solid fa-check'></i> Caricate <strong>{$stats['total']}</strong> righe: " .
            "<strong style='color:#10b981'>{$stats['valid']} complete</strong>$partialMsg, " .
            "<strong style='color:#ef4444'>{$stats['invalid']} con errori</strong>. " .
            "Approva le righe (anche parziali in modalità LDB) e poi committale." .
            "</div>";
        header('Location: mass_upload_review.php?job=' . $jobId .
               (!empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : ''));
        exit();
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        redirect_self();
    }
}

// ─── ULTIMI JOB IN CORSO (per ripresa rapida) ──────────────────────────
$recent_jobs = $pdo->prepare(
    "SELECT j.*, COUNT(s.id) AS staging_count,
            CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS user_name
       FROM import_jobs j
       LEFT JOIN import_staging_rows s ON s.job_id = j.id
       LEFT JOIN users u ON u.id = j.created_by
       LEFT JOIN employees e ON e.id = u.employee_id
      WHERE j.status IN ('uploaded','validated','partial')
      GROUP BY j.id
      ORDER BY j.started_at DESC
      LIMIT 10"
);
$recent_jobs->execute();
$open_jobs = $recent_jobs->fetchAll();

require_once('header.php');
?>

<div style="max-width:1200px;margin:0 auto">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-cloud-arrow-up" style="color:var(--p)"></i> Importazione massiva
      </h1>
      <div style="color:var(--muted);font-size:13px">Carica CSV con validazione, staging errori e correzione manuale</div>
    </div>
    <a href="<?= url_safe('mass_upload_jobs') ?>" class="btn btn-sm">
      <i class="fa-solid fa-clock-rotate-left"></i> Storico job
    </a>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?>
    <?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?>
  <?php endif; ?>

  <!-- ═══ JOB IN CORSO ═══════════════════════════════════════════════ -->
  <?php if (!empty($open_jobs)): ?>
  <div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:8px;margin-bottom:24px">
    <div style="font-weight:800;font-size:13px;color:#92400e;margin-bottom:10px">
      <i class="fa-solid fa-circle-exclamation"></i> Job aperti che richiedono attenzione
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse">
      <thead>
        <tr style="background:#fef3c7;font-size:10px;text-transform:uppercase">
          <th style="padding:6px 8px;text-align:left;color:#92400e">Tipo</th>
          <th style="padding:6px 8px;text-align:left;color:#92400e">File</th>
          <th style="padding:6px 8px;text-align:right;color:#92400e">Tot</th>
          <th style="padding:6px 8px;text-align:right;color:#92400e">Valide</th>
          <th style="padding:6px 8px;text-align:right;color:#92400e">Invalide</th>
          <th style="padding:6px 8px;text-align:left;color:#92400e">Stato</th>
          <th style="padding:6px 8px;text-align:left;color:#92400e">Quando</th>
          <th style="padding:6px 8px;text-align:center;color:#92400e">Azione</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($open_jobs as $j):
          $tinfo = $TYPES[$j['import_type']] ?? ['fa-file', '#64748b', $j['import_type'], ''];
        ?>
          <tr style="border-bottom:1px solid #fef3c7">
            <td style="padding:6px 8px"><i class="fa-solid <?=$tinfo[0]?>" style="color:<?=$tinfo[1]?>"></i> <?=h($tinfo[2])?></td>
            <td style="padding:6px 8px;font-family:monospace;color:#475569;font-size:11px"><?=h($j['original_name'])?></td>
            <td style="padding:6px 8px;text-align:right;font-weight:700"><?=$j['total_rows']?></td>
            <td style="padding:6px 8px;text-align:right;color:#10b981;font-weight:700"><?=$j['valid_rows']?></td>
            <td style="padding:6px 8px;text-align:right;color:#ef4444;font-weight:700"><?=$j['invalid_rows']?></td>
            <td style="padding:6px 8px">
              <?php
                $sLabel = ['uploaded'=>'Caricato','validated'=>'Validato','partial'=>'Parziale'];
                $sColor = ['uploaded'=>'#64748b','validated'=>'#0ea5e9','partial'=>'#f59e0b'];
              ?>
              <span style="background:<?=$sColor[$j['status']]?>;color:#fff;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700"><?=$sLabel[$j['status']] ?? $j['status']?></span>
            </td>
            <td style="padding:6px 8px;color:#64748b;font-size:11px"><?=date('d/m H:i', strtotime($j['started_at']))?></td>
            <td style="padding:6px 8px;text-align:center">
              <a href="mass_upload_review.php?job=<?=$j['id']?><?= !empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '' ?>" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-arrow-right"></i> Apri
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ═══ TIPI DI IMPORT (organizzati per categoria) ═══════════════ -->
  <?php
    // Raggruppa per categoria
    $byCategory = [];
    foreach ($TYPES as $key => $info) {
        $cat = $info[4] ?? 'Altro';
        $byCategory[$cat][$key] = $info;
    }
    $catIcons = [
        'Anagrafica'    => ['fa-id-card-clip', '#0ea5e9'],
        'Catalogo'      => ['fa-book', '#0284c7'],
        'Competenze'    => ['fa-award', '#10b981'],
        'Recruiting'    => ['fa-user-tie', '#7c3aed'],
        'Configurazione'=> ['fa-cogs', '#5b21b6'],
    ];
  ?>
  <?php foreach ($byCategory as $cat => $items):
    [$cIcon, $cColor] = $catIcons[$cat] ?? ['fa-folder', '#64748b'];
  ?>
  <div style="margin-bottom:24px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid <?=$cColor?>33">
      <div style="width:32px;height:32px;border-radius:8px;background:<?=$cColor?>;color:#fff;display:flex;align-items:center;justify-content:center">
        <i class="fa-solid <?=$cIcon?>"></i>
      </div>
      <h2 style="font-size:15px;font-weight:800;margin:0;color:<?=$cColor?>"><?=h($cat)?></h2>
      <span style="color:var(--muted);font-size:11px;font-weight:600">(<?=count($items)?> tipi)</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
      <?php foreach ($items as $key => [$icon, $color, $label, $hint]): ?>
        <div style="background:#fff;border-radius:12px;padding:16px;box-shadow:0 2px 8px rgba(0,0,0,.04);border-top:3px solid <?=$color?>">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:36px;height:36px;border-radius:8px;background:<?=$color?>20;color:<?=$color?>;display:flex;align-items:center;justify-content:center">
              <i class="fa-solid <?=$icon?>" style="font-size:16px"></i>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:800;font-size:13px;color:#1e293b"><?=h($label)?></div>
              <div style="font-size:10px;color:var(--muted);margin-top:2px;line-height:1.3"><i class="fa-solid fa-key" style="font-size:8px"></i> <?=h($hint)?></div>
            </div>
          </div>

          <a href="<?= !empty($_GET['r']) ? 'r.php?r=' . urlencode($_GET['r']) . '&dl=' . urlencode($key) : '?dl=' . urlencode($key) ?>"
             style="font-size:11px;color:<?=$color?>;font-weight:700;display:inline-block;margin-bottom:8px;text-decoration:none">
            <i class="fa-solid fa-download"></i> Template CSV (con guida)
          </a>

          <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="type" value="<?=$key?>">
            <div style="display:flex;gap:6px">
              <input type="file" name="file" accept=".csv,.txt" required
                     style="flex:1;font-size:11px;padding:5px;border:1px solid var(--border);border-radius:5px">
              <button type="submit" class="btn btn-sm" style="background:<?=$color?>;color:#fff;border-color:<?=$color?>;white-space:nowrap" title="Carica CSV">
                <i class="fa-solid fa-upload"></i>
              </button>
            </div>
            <!-- v5.6: niente più toggle qui — l'approvazione (strict/LDB) è per riga nella review -->
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div style="background:#eff6ff;border-left:4px solid #0ea5e9;padding:14px 18px;border-radius:8px;margin-top:24px;font-size:12px;color:#1e40af">
    <strong><i class="fa-solid fa-circle-info"></i> Come funziona:</strong>
    <ol style="margin-left:18px;margin-top:6px;line-height:1.8">
      <li><strong>Carica CSV</strong> — il file viene letto e ogni riga salvata in staging.</li>
      <li><strong>Validazione automatica</strong> — tipo, formato, vincoli FK. Le righe con campi obbligatori vuoti vengono marcate <em>parziali</em>, non bloccate.</li>
      <li><strong>Review e approvazione per riga</strong> — vedi tutte le righe in una griglia editabile.
        <ul style="margin-top:4px;margin-left:14px">
          <li>✓ <strong>Approva strict</strong> (verde): per righe complete</li>
          <li><i class="fa-solid fa-puzzle-piece" style="color:#f59e0b"></i> <strong>Approva in LDB</strong> (arancione): per righe con campi mancanti — verranno completati dopo</li>
          <li><i class="fa-solid fa-ban"></i> <strong>Rifiuta</strong>: esclude la riga dal commit</li>
        </ul>
      </li>
      <li><strong>Commit</strong> — importa <em>solo</em> le righe approvate, ognuna in transazione separata.</li>
      <li><strong>Completamento LDB</strong> — i record approvati con LDB vengono completati manualmente nella pagina dedicata.</li>
    </ol>
    Encoding: <strong>UTF-8</strong>. Separatore auto-detect (<code>,</code> o <code>;</code>). Max <strong>10 MB</strong>.
  </div>
</div>

<?php require_once('footer.php'); ?>
