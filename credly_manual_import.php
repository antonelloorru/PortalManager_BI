<?php
/**
 * PortalManager 1.6.8 — credly_manual_import.php
 *
 * Workaround per server senza connettività in uscita verso www.credly.com.
 *
 * Workflow:
 *   1. L'utente apre da un PC con connettività l'URL:
 *      https://www.credly.com/users/<username>/badges.json?page=1&page_size=48
 *   2. Salva la risposta JSON in un file (.json) - eventualmente concatena più pagine
 *   3. Apre questa pagina sul portale e carica il/i file JSON associandoli a un dipendente
 *   4. Il sistema importa i badge come se arrivassero direttamente dall'API
 *
 * In più, supporta una modalità "Proxy aziendale" via settings se disponibile.
 *
 * Sicurezza:
 *   - Solo Super Admin (1) e HR Director (2)
 *   - Validazione JSON, max 5 MB per file
 *   - CSRF protetto
 */

require_once('access_control.php');
require_once __DIR__ . '/app/CredlyImporter.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!in_array($u_role, [1, 2], true)) {
    http_response_code(403);
    die('Accesso negato.');
}

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$msg    = '';
$detail = null;

// ─────────────────────────────────────────────────────────────────────
// Lista dipendenti per dropdown (con username Credly collegato se presente)
// ─────────────────────────────────────────────────────────────────────
$employees = $pdo->query(
    "SELECT e.id, CONCAT(e.last_name, ' ', e.first_name) AS full_name,
            e.credly_url, ecl.credly_username
       FROM employees e
       LEFT JOIN employee_credly_link ecl ON ecl.employee_id = e.id
      WHERE e.status != 'terminated'
      ORDER BY e.last_name, e.first_name"
)->fetchAll();

// Settings proxy attuali
$current_proxy = null;
$current_proxy_user = null;
try {
    $current_proxy = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='credly_proxy_url' LIMIT 1")->fetchColumn() ?: null;
    $current_proxy_user = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='credly_proxy_userpwd' LIMIT 1")->fetchColumn() ?: null;
} catch (Throwable $e) {}

// ─────────────────────────────────────────────────────────────────────
// POST handlers
// ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ─── 1. Upload file JSON ───
        if ($action === 'import_json') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            if (!$empId) throw new RuntimeException('Selezionare un dipendente.');

            $emp = null;
            foreach ($employees as $e) {
                if ((int)$e['id'] === $empId) { $emp = $e; break; }
            }
            if (!$emp) throw new RuntimeException('Dipendente non valido.');

            // Concatena il contenuto di tutti i file caricati
            $json_combined = '';
            $sources = [];

            if (!empty($_FILES['json_files']) && is_array($_FILES['json_files']['name'])) {
                foreach ($_FILES['json_files']['name'] as $i => $name) {
                    if ($_FILES['json_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($_FILES['json_files']['size'][$i] > 5 * 1024 * 1024) continue;
                    $content = file_get_contents($_FILES['json_files']['tmp_name'][$i]);
                    if ($content === false) continue;
                    $sources[] = $name . ' (' . strlen($content) . ' bytes)';
                    // Se sono più file, li mettiamo in un array JSON di pagine
                    if (count($_FILES['json_files']['name']) > 1) {
                        if ($json_combined === '') $json_combined = '[';
                        else $json_combined .= ',';
                        $json_combined .= $content;
                    } else {
                        $json_combined = $content;
                    }
                }
                if (count($_FILES['json_files']['name']) > 1 && $json_combined !== '') {
                    $json_combined .= ']';
                }
            }

            // Se anche paste manuale è presente
            $paste = trim($_POST['json_paste'] ?? '');
            if ($paste !== '' && $json_combined === '') {
                $json_combined = $paste;
                $sources[] = 'paste manuale (' . strlen($paste) . ' bytes)';
            }

            if ($json_combined === '') throw new RuntimeException('Nessun JSON caricato (upload file o incolla).');

            // Parser + import
            $importer = new CredlyImporter($pdo, $u_id);
            $badges = $importer->parseBadgesFromJson($json_combined);

            $username = $emp['credly_username'] ?: ($_POST['credly_username'] ?? null);
            if ($username) $username = trim($username);

            $detail = $importer->syncEmployeeFromBadges($empId, $badges, $username ?: null);
            $detail['_sources'] = $sources;
            $detail['_badge_count'] = count($badges);

            if (function_exists('write_log')) {
                write_log('CredlyImport', 'success',
                    "Import manuale JSON emp=$empId: " . $detail['_badge_count'] . " badge ricevuti, "
                    . ($detail['imported'] + $detail['created_cert']) . ' importati', $u_id);
            }

            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Import completato: <strong>"
                 . ($detail['imported'] + $detail['created_cert']) . "</strong> certificazioni importate, "
                 . "<strong>" . $detail['updated'] . "</strong> aggiornate, "
                 . "<strong>" . $detail['unmatched'] . "</strong> non riconosciute, "
                 . "<strong>" . ($detail['errors'] ?? 0) . "</strong> errori.</div>";
        }

        // ─── 2. Imposta/rimuovi proxy ───
        elseif ($action === 'save_proxy') {
            $url = trim($_POST['proxy_url'] ?? '');
            $userpwd = trim($_POST['proxy_userpwd'] ?? '');

            $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, description)
                           VALUES ('credly_proxy_url', ?, 'Proxy HTTP per chiamate Credly')
                           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$url]);
            $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, description)
                           VALUES ('credly_proxy_userpwd', ?, 'Credenziali user:pass per il proxy Credly')
                           ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
                ->execute([$userpwd]);

            $current_proxy = $url ?: null;
            $current_proxy_user = $userpwd ?: null;

            if (function_exists('write_log')) {
                write_log('CredlyImport', 'success',
                    'Proxy Credly aggiornato: ' . ($url ?: '(rimosso)'), $u_id);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Configurazione proxy salvata.</div>";
        }

        // ─── 3. Test connettività ───
        elseif ($action === 'test_connection') {
            $username = trim($_POST['test_username'] ?? '');
            if (!$username) throw new RuntimeException('Inserisci uno username Credly per il test.');

            $importer = new CredlyImporter($pdo, $u_id);
            try {
                $badges = $importer->fetchBadges($username);
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> "
                     . "Connessione OK. <strong>" . count($badges) . "</strong> badge ricevuti per <code>" . $h($username) . "</code>"
                     . ($current_proxy ? ' tramite proxy <code>' . $h($current_proxy) . '</code>' : ' (connessione diretta)')
                     . ".</div>";
            } catch (Throwable $e) {
                $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> "
                     . "<strong>Test fallito:</strong> " . $h($e->getMessage())
                     . "<br><small style='opacity:.8'>Suggerimento: usare l'import manuale JSON in basso.</small></div>";
            }
        }

    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . $h($e->getMessage()) . "</div>";
    }
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0">
      <i class="fa-solid fa-file-arrow-up" style="color:#ff6900"></i> Credly — Import offline / via proxy
    </h1>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">
      Soluzioni alternative quando il server non raggiunge <code>www.credly.com</code> direttamente
    </div>
  </div>
  <a href="credly_sync.php" class="btn btn-sm"><i class="fa-solid fa-arrow-left"></i> Torna alla sync standard</a>
</div>

<?= $msg ?>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- TEST CONNETTIVITÀ -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-network-wired" style="color:#0ea5e9"></i> 1. Test connettività diretta verso Credly</span>
  </div>
  <div style="font-size:12px;color:var(--muted);margin-bottom:10px">
    Verifica se il server riesce a raggiungere <code>www.credly.com</code>. Se fallisce, usa una delle 2 soluzioni sotto.
  </div>
  <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_connection">
    <input type="text" name="test_username" placeholder="Username Credly per test (es. john-doe)" required
           style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;flex:1;min-width:200px">
    <button type="submit" class="btn btn-primary">
      <i class="fa-solid fa-bolt"></i> Test
    </button>
  </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- SOLUZIONE A: PROXY HTTP -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:18px;border-left:4px solid #0ea5e9">
  <div class="card-header" style="background:#eff6ff">
    <span class="card-title" style="color:#1e40af">
      <i class="fa-solid fa-server"></i> Soluzione A — Proxy HTTP aziendale
    </span>
    <?php if ($current_proxy): ?>
      <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:800">
        ✓ Configurato
      </span>
    <?php else: ?>
      <span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:800">Non attivo</span>
    <?php endif; ?>
  </div>
  <div style="font-size:12px;color:#1e40af;background:#dbeafe;padding:10px 14px;border-radius:6px;margin-bottom:12px">
    <i class="fa-solid fa-info-circle"></i>
    Se la rete aziendale fornisce un proxy HTTP/SOCKS per l'accesso a Internet, configuralo qui. Tutte le chiamate verso <code>www.credly.com</code> passeranno attraverso il proxy.
  </div>

  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_proxy">
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:10px">
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:4px">URL Proxy</label>
        <input type="text" name="proxy_url" value="<?= $h($current_proxy ?? '') ?>"
               placeholder="es. http://proxy.azienda.local:8080 oppure socks5://10.0.0.5:1080"
               style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-family:monospace;font-size:12px">
        <small style="color:var(--muted);font-size:10px;display:block;margin-top:4px">Formati supportati: <code>http://host:port</code>, <code>https://host:port</code>, <code>socks5://host:port</code>. Vuoto = connessione diretta.</small>
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:4px">Credenziali (opzionale)</label>
        <input type="text" name="proxy_userpwd" value="<?= $h($current_proxy_user ?? '') ?>"
               placeholder="user:password"
               style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-family:monospace;font-size:12px">
        <small style="color:var(--muted);font-size:10px;display:block;margin-top:4px">Solo se il proxy richiede autenticazione</small>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">
      <i class="fa-solid fa-floppy-disk"></i> Salva configurazione proxy
    </button>
    <?php if ($current_proxy): ?>
      <button type="submit" name="proxy_url" value="" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;margin-left:8px"
              onclick="this.form.proxy_url.value=''; this.form.proxy_userpwd.value=''; return confirm('Rimuovere il proxy?')">
        <i class="fa-solid fa-xmark"></i> Rimuovi proxy
      </button>
    <?php endif; ?>
  </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- SOLUZIONE B: IMPORT MANUALE JSON -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:18px;border-left:4px solid #ff6900">
  <div class="card-header" style="background:#fff7ed">
    <span class="card-title" style="color:#9a3412">
      <i class="fa-solid fa-file-arrow-up"></i> Soluzione B — Import manuale via JSON (sempre funzionante)
    </span>
  </div>

  <div style="background:#fff7ed;border:1px solid #fed7aa;padding:12px 14px;border-radius:6px;margin-bottom:14px;font-size:12px;color:#9a3412">
    <strong>Funziona anche senza connettività dal server.</strong>
    Procedura:
    <ol style="margin:8px 0 0 18px;line-height:1.7;color:#7c2d12">
      <li>Da un PC <strong>con accesso a Internet</strong>, apri nel browser:<br>
        <code style="background:#fff;padding:3px 7px;border-radius:4px;display:inline-block;margin:4px 0">https://www.credly.com/users/<strong>USERNAME</strong>/badges.json?page=1&amp;page_size=48</code>
      </li>
      <li>Salva la pagina come file <code>.json</code> (Ctrl+S o "Salva con nome")</li>
      <li>Se ha più pagine (vedi <code>"total_pages"</code> nel JSON), ripeti per ogni pagina cambiando <code>page=N</code></li>
      <li>Carica qui sotto tutti i file JSON insieme</li>
    </ol>
  </div>

  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_json">

    <div style="margin-bottom:12px">
      <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:6px">Dipendente destinatario</label>
      <select name="employee_id" required
              style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px">
        <option value="">— Seleziona dipendente —</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?= $e['id'] ?>">
            <?= $h($e['full_name']) ?>
            <?php if ($e['credly_username']): ?>· Credly: <?= $h($e['credly_username']) ?><?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:6px">Username Credly (opzionale)</label>
      <input type="text" name="credly_username" placeholder="solo se vuoi salvare/aggiornare il collegamento"
             style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:monospace">
      <small style="color:var(--muted);font-size:10px">Se il dipendente è già collegato, viene mantenuto il collegamento esistente</small>
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:6px">File JSON Credly (1 o più)</label>
      <input type="file" name="json_files[]" accept=".json,application/json,text/plain" multiple
             style="width:100%;padding:9px;border:1px dashed var(--border);border-radius:6px;background:#fafbfc">
      <small style="color:var(--muted);font-size:10px;display:block;margin-top:4px">Max 5 MB per file. Selezione multipla supportata (Ctrl+click)</small>
    </div>

    <div style="margin-bottom:14px">
      <label style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:6px">Oppure incolla JSON direttamente</label>
      <textarea name="json_paste" rows="4" placeholder='{"data":[{"badge_template":{...},...}],"metadata":{...}}'
                style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-family:monospace;font-size:11px;resize:vertical"></textarea>
    </div>

    <button type="submit" class="btn btn-primary" style="background:#ff6900;border:0;padding:11px 22px;font-weight:700">
      <i class="fa-solid fa-cloud-arrow-up"></i> Importa badge dal JSON
    </button>
  </form>
</div>

<!-- ═══ DETTAGLIO IMPORT (se appena eseguito) ═══ -->
<?php if ($detail): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-list-check" style="color:var(--p)"></i> Dettaglio import</span>
    <span style="font-size:11px;color:var(--muted)">
      <?= count($detail['_sources'] ?? []) ?> sorgente/i ·
      <?= $detail['_badge_count'] ?? '?' ?> badge ricevuti
    </span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));gap:10px;margin-bottom:14px">
    <div style="text-align:center;background:#dcfce7;padding:10px;border-radius:8px">
      <div style="font-size:24px;font-weight:800;color:#166534"><?= $detail['imported'] + $detail['created_cert'] ?></div>
      <div style="font-size:10px;color:#166534;font-weight:700;text-transform:uppercase">Importati</div>
    </div>
    <div style="text-align:center;background:#dbeafe;padding:10px;border-radius:8px">
      <div style="font-size:24px;font-weight:800;color:#1e40af"><?= $detail['updated'] ?></div>
      <div style="font-size:10px;color:#1e40af;font-weight:700;text-transform:uppercase">Aggiornati</div>
    </div>
    <div style="text-align:center;background:#f1f5f9;padding:10px;border-radius:8px">
      <div style="font-size:24px;font-weight:800;color:#475569"><?= $detail['unchanged'] ?></div>
      <div style="font-size:10px;color:#475569;font-weight:700;text-transform:uppercase">Invariati</div>
    </div>
    <div style="text-align:center;background:#fef3c7;padding:10px;border-radius:8px">
      <div style="font-size:24px;font-weight:800;color:#92400e"><?= $detail['unmatched'] ?></div>
      <div style="font-size:10px;color:#92400e;font-weight:700;text-transform:uppercase">Non riconosciuti</div>
    </div>
    <?php if (($detail['errors'] ?? 0) > 0): ?>
    <div style="text-align:center;background:#fee2e2;padding:10px;border-radius:8px">
      <div style="font-size:24px;font-weight:800;color:#991b1b"><?= $detail['errors'] ?></div>
      <div style="font-size:10px;color:#991b1b;font-weight:700;text-transform:uppercase">Errori</div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($detail['detail'])): ?>
  <details>
    <summary style="cursor:pointer;font-size:12px;font-weight:700;color:var(--p)">
      <i class="fa-solid fa-list"></i> Log per badge (<?= count($detail['detail']) ?> righe)
    </summary>
    <table class="data-table" style="margin-top:10px">
      <thead><tr><th>Badge</th><th>Esito</th><th>Note</th></tr></thead>
      <tbody>
        <?php
        $result_pal = [
            'imported'     => ['#dcfce7','#166534','IMPORTATO'],
            'created_cert' => ['#dcfce7','#166534','+CAT.'],
            'updated'      => ['#dbeafe','#1e40af','AGGIORNATO'],
            'unchanged'    => ['#f1f5f9','#475569','INVARIATO'],
            'unmatched'    => ['#fef3c7','#92400e','NO MATCH'],
            'error'        => ['#fee2e2','#991b1b','ERRORE'],
        ];
        foreach ($detail['detail'] as $d):
            $pal = $result_pal[$d['result']] ?? ['#f1f5f9','#475569',strtoupper($d['result'])];
        ?>
        <tr>
          <td style="font-size:11px"><?= $h($d['badge']) ?></td>
          <td><span style="background:<?= $pal[0] ?>;color:<?= $pal[1] ?>;padding:2px 8px;border-radius:8px;font-size:9px;font-weight:800"><?= $pal[2] ?></span></td>
          <td style="font-size:10px;color:var(--muted)"><?= $h($d['note'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
