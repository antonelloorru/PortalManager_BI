<?php
/**
 * certV 4.1 — verify_integrity.php (v2 — fix critico Windows)
 *
 * v2: Corretto bug critico dove su Windows il pattern 'app\\*.php' matchava
 * i file regolari dentro app/ e li trattava come orfani. La logica nuova:
 *   - usa scandir() invece di glob()
 *   - cerca solo nella ROOT del portale, mai dentro app/
 *   - identifica orfani come file il cui *nome* contiene un backslash
 *   - su Windows questo è impossibile (backslash è separatore), quindi
 *     orfani non possono mai esistere → la pulizia non fa nulla
 *
 * Strumento di diagnostica per Super Admin che:
 *   1. Verifica esistenza dei file linkati dal menu (header.php)
 *   2. Verifica esistenza dei moduli core in app/
 *   3. Identifica file orfani con backslash nel nome (solo Linux)
 *   4. Propone pulizia automatica dei file orfani (con whitelist + conferma)
 *
 * USO:
 *   - Aprire http://tuo-portale/verify_integrity.php
 *   - Loggarsi come Super Admin (richiesto)
 *   - Cliccare "Pulisci file orfani" se proposto
 *   - ELIMINARE questo file dopo l'uso
 */

require_once __DIR__ . '/access_control.php';

if ((int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    die('Accesso solo Super Admin.');
}

$root = __DIR__;
$action = $_POST['action'] ?? '';
$cleanup_done = [];
$cleanup_errors = [];

/**
 * Rileva file orfani in modo SICURO e cross-platform.
 *
 * Un file è "orfano" se:
 *   - sta nella ROOT del portale (non dentro sottocartelle)
 *   - il suo NOME (non il path) contiene un backslash letterale
 *
 * Su Windows il backslash è separatore di path, quindi nessun file può
 * legittimamente avere un backslash nel nome → questa funzione non
 * troverà mai nulla su Windows. È un comportamento corretto.
 *
 * Su Linux, dove un nome file può contenere backslash, questa funzione
 * identifica i file creati per errore da estrazione zip mal fatta.
 */
function detect_orphan_files(string $root): array {
    $orphans = [];
    $entries = @scandir($root);
    if ($entries === false) return [];

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_file($full)) continue;            // niente cartelle
        if (strpos($name, '\\') === false) continue;  // niente backslash → niente orfano
        $orphans[] = $full;
    }
    return $orphans;
}

/**
 * Whitelist dei moduli core attesi nella cartella app/.
 * Solo questi nomi sono considerati validi destinazioni di "spostamento orfani".
 */
function expected_app_modules(): array {
    return [
        'bootstrap.php', 'Csrf.php', 'EmailOtp.php', 'Env.php', 'RateLimiter.php',
        'RecoveryCodes.php', 'Router.php', 'Security.php', 'Session.php',
        'Totp.php', 'TwoFactor.php', 'UrlHelper.php',
    ];
}

// ── Pulizia file orfani con backslash nel nome ────────────────────
if ($action === 'cleanup' && ($_POST['confirm'] ?? '') === 'YES') {
    // Verifica CSRF se disponibile
    if (function_exists('csrf_token')) {
        $expected_csrf = $_SESSION['_csrf']['value'] ?? '';
        $provided_csrf = $_POST['_csrf'] ?? '';
        if (!$expected_csrf || !$provided_csrf || !hash_equals($expected_csrf, $provided_csrf)) {
            die('CSRF token invalido.');
        }
    }

    $orphans = detect_orphan_files($root);
    $whitelist = expected_app_modules();

    foreach ($orphans as $file) {
        $name_with_backslash = basename($file);    // "app\bootstrap.php" su Linux

        // SAFEGUARD 1: deve davvero contenere un backslash nel nome
        if (strpos($name_with_backslash, '\\') === false) {
            $cleanup_errors[] = "Saltato: $name_with_backslash (nessun backslash, non è orfano)";
            continue;
        }

        // SAFEGUARD 2: deve iniziare con "app\"
        if (strpos($name_with_backslash, 'app\\') !== 0) {
            $cleanup_errors[] = "Saltato: $name_with_backslash (non inizia con 'app\\')";
            continue;
        }

        // Estrai il nome pulito: "app\bootstrap.php" -> "bootstrap.php"
        $clean = substr($name_with_backslash, strlen('app\\'));

        // SAFEGUARD 3: il nome pulito deve essere in whitelist
        if (!in_array($clean, $whitelist, true)) {
            $cleanup_errors[] = "Saltato: $name_with_backslash (modulo sconosciuto, non in whitelist)";
            continue;
        }

        // Crea cartella app/ se non esiste
        if (!is_dir($root . '/app')) {
            if (!@mkdir($root . '/app', 0755, true)) {
                $cleanup_errors[] = "Errore creazione cartella app/";
                continue;
            }
        }

        $target = $root . '/app/' . $clean;

        if (!file_exists($target)) {
            // app/ non ha questo file: sposta l'orfano
            if (@rename($file, $target)) {
                $cleanup_done[] = "Spostato '$name_with_backslash' in 'app/$clean'";
            } else {
                $cleanup_errors[] = "Errore spostamento '$name_with_backslash'";
            }
        } else {
            // app/ ha già il file: confronta dimensione/contenuto prima di rimuovere
            $size_target = @filesize($target);
            $size_orphan = @filesize($file);
            if ($size_target === false || $size_orphan === false) {
                $cleanup_errors[] = "Saltato '$name_with_backslash' (errore lettura)";
                continue;
            }
            // Se identici, rimuovi l'orfano. Altrimenti, lascia stare e segnala.
            if ($size_target === $size_orphan && md5_file($target) === md5_file($file)) {
                if (@unlink($file)) {
                    $cleanup_done[] = "Rimosso '$name_with_backslash' (identico a app/$clean)";
                } else {
                    $cleanup_errors[] = "Errore rimozione '$name_with_backslash'";
                }
            } else {
                $cleanup_errors[] = "Saltato '$name_with_backslash': contenuto DIVERSO da app/$clean — controllo manuale richiesto";
            }
        }
    }
}

// ── 1. File linkati dal menu ──────────────────────────────────────
$menu_pages = [
    '2fa_settings.php', '2fa_verify.php',
    'brand.php', 'brand_distributors.php', 'brand_referents.php', 'brand_technologies.php',
    'catalogo_certificazioni.php', 'config_notifiche.php',
    'db_upgrade.php', 'documenti.php', 'gap_analysis.php', 'health_check.php',
    'index.php', 'logout.php',
    'manage_companies.php', 'manage_employees.php', 'manage_permissions.php',
    'manage_roles.php', 'manage_work_modes.php', 'manager_users.php',
    'mass_upload.php', 'notifications.php', 'programmazione.php', 'publish_posizione.php',
    'recruiting_agenzie.php', 'recruiting_candidati.php', 'recruiting_contratti.php',
    'recruiting_posizioni.php', 'report_certificazioni.php',
    'schema_check_upgrade.php', 'segreteria.php', 'settings.php', 'smtp_settings.php',
    'system_update.php', 'training_plans.php', 'upload_certificato.php',
    'view_logs.php', 'visualizza_storico.php',
];
$missing_pages = [];
foreach ($menu_pages as $p) {
    if (!file_exists($root . '/' . $p)) $missing_pages[] = $p;
}

// ── 2. Moduli core in app/ ────────────────────────────────────────
$app_modules = expected_app_modules();
$missing_modules = [];
$app_dir_exists = is_dir($root . '/app');
foreach ($app_modules as $m) {
    if (!file_exists($root . '/app/' . $m)) $missing_modules[] = $m;
}

// ── 3. File orfani con backslash nel nome ─────────────────────────
$orphan_files = detect_orphan_files($root);

// ── 4. Duplicati legacy nella root ────────────────────────────────
// Solo per informativa — NON proponiamo cancellazione automatica
$root_duplicates = [];
$root_modules_to_check = ['Csrf.php', 'Env.php', 'RateLimiter.php', 'Router.php',
                          'Security.php', 'Session.php', 'UrlHelper.php', 'bootstrap.php'];
foreach ($root_modules_to_check as $f) {
    // Solo file in ROOT, non dentro app/
    $candidate = $root . DIRECTORY_SEPARATOR . $f;
    if (is_file($candidate) && dirname($candidate) === $root) {
        $root_duplicates[] = $f;
    }
}

// ── 5. Statistiche generali ───────────────────────────────────────
$total_php = count(glob($root . '/*.php') ?: []);
$total_app = $app_dir_exists ? count(glob($root . '/app/*.php') ?: []) : 0;

$ok_pages = empty($missing_pages);
$ok_modules = empty($missing_modules) && $app_dir_exists;
$has_orphans = !empty($orphan_files);
$has_duplicates = !empty($root_duplicates);

$overall_ok = $ok_pages && $ok_modules && !$has_orphans;

// Token CSRF per il form
$csrf_token = $_SESSION['_csrf']['value'] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Verifica integrità — certV</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;padding:24px;color:#1e293b}
.container{max-width:900px;margin:0 auto}
h1{font-size:24px;margin-bottom:6px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
.summary{padding:16px 20px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:14px;font-weight:600}
.summary.ok{background:#d1fae5;color:#065f46;border-left:4px solid #10b981}
.summary.warn{background:#fef3c7;color:#92400e;border-left:4px solid #f59e0b}
.summary.err{background:#fee2e2;color:#991b1b;border-left:4px solid #ef4444}
.summary .icon{font-size:24px}
.section{background:#fff;border-radius:10px;padding:20px;margin-bottom:16px;border:1px solid #e2e8f0}
.section h2{font-size:16px;margin-bottom:12px;display:flex;align-items:center;gap:10px}
.section h2 .badge{padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase}
.badge.ok{background:#d1fae5;color:#065f46}
.badge.warn{background:#fef3c7;color:#92400e}
.badge.err{background:#fee2e2;color:#991b1b}
.section p{font-size:13px;color:#64748b;margin-bottom:8px;line-height:1.5}
ul.files{list-style:none;font-size:12px;font-family:Consolas,Monaco,monospace}
ul.files li{padding:4px 8px;background:#f8fafc;margin-bottom:3px;border-radius:5px;color:#475569}
ul.files li.bad{background:#fee2e2;color:#991b1b}
ul.files li.good{background:#f0fdf4;color:#065f46}
.stat{display:inline-block;background:#f1f5f9;padding:6px 12px;border-radius:6px;font-size:12px;margin-right:8px}
.stat strong{color:#0ea5e9;font-weight:700}
.actions{margin-top:20px;padding:16px;background:#fffbeb;border:1px solid #fbbf24;border-radius:8px}
.btn{padding:10px 18px;background:#0ea5e9;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
.btn:hover{filter:brightness(1.08)}
.btn.danger{background:#ef4444}
.warn-text{font-size:12px;color:#92400e;margin-top:6px}
pre{background:#0f172a;color:#cbd5e1;padding:12px 16px;border-radius:6px;font-size:12px;overflow-x:auto;margin-top:8px}
.cleanup-result{background:#f0fdf4;color:#065f46;padding:14px;border-radius:8px;margin-bottom:20px;border-left:4px solid #10b981}
.cleanup-result.err{background:#fef2f2;color:#991b1b;border-left-color:#ef4444}
.safeguard-info{font-size:11px;background:#eff6ff;color:#1e40af;padding:10px 14px;border-radius:6px;margin-top:10px;border-left:3px solid #3b82f6}
</style>
</head>
<body>
<div class="container">
  <h1>🔍 Verifica integrità portale</h1>
  <p class="sub">Diagnostica della struttura file. Eseguire dopo aggiornamenti per verificare consistenza.</p>

  <?php if ($cleanup_done || $cleanup_errors): ?>
    <div class="cleanup-result <?= $cleanup_errors ? 'err' : '' ?>">
      <strong>Pulizia eseguita:</strong>
      <ul style="margin-top:8px;font-size:12px">
        <?php foreach ($cleanup_done as $msg): ?>
          <li>✓ <?= htmlspecialchars($msg) ?></li>
        <?php endforeach; ?>
        <?php foreach ($cleanup_errors as $msg): ?>
          <li>✗ <?= htmlspecialchars($msg) ?></li>
        <?php endforeach; ?>
      </ul>
      <p style="margin-top:8px;font-size:12px">
        <a href="?" style="color:inherit;text-decoration:underline">Ricarica per nuovo controllo</a>
      </p>
    </div>
  <?php endif; ?>

  <?php if ($overall_ok && !$has_duplicates): ?>
    <div class="summary ok"><span class="icon">✅</span> Portale integro: nessun problema rilevato.</div>
  <?php elseif ($has_orphans): ?>
    <div class="summary err"><span class="icon">⚠️</span> Rilevati file orfani da estrazione ZIP — richiede pulizia.</div>
  <?php elseif (!$ok_modules || !$ok_pages): ?>
    <div class="summary warn"><span class="icon">⚠</span> Alcuni file/moduli risultano mancanti.</div>
  <?php else: ?>
    <div class="summary warn"><span class="icon">ℹ</span> Verifica funzionalità: moduli duplicati nella root.</div>
  <?php endif; ?>

  <div style="margin-bottom:20px">
    <span class="stat">PHP root: <strong><?= $total_php ?></strong></span>
    <span class="stat">Moduli /app: <strong><?= $total_app ?></strong></span>
    <span class="stat">Versione: <strong><?= htmlspecialchars(@file_get_contents($root . '/VERSION') ?: '?') ?></strong></span>
    <span class="stat">SO: <strong><?= htmlspecialchars(PHP_OS_FAMILY) ?></strong></span>
  </div>

  <!-- ═══ Sezione 1: pagine linkate dal menu ═══ -->
  <div class="section">
    <h2>1. Pagine linkate dal menu
      <span class="badge <?= $ok_pages ? 'ok' : 'err' ?>">
        <?= $ok_pages ? 'OK' : count($missing_pages) . ' mancanti' ?>
      </span>
    </h2>
    <p>Verifica che tutti i 38 file linkati dall'header esistano nel filesystem.</p>
    <?php if (!$ok_pages): ?>
      <ul class="files">
        <?php foreach ($missing_pages as $p): ?>
          <li class="bad">✗ MANCANTE: <?= htmlspecialchars($p) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <ul class="files"><li class="good">✓ Tutti i 38 file linkati sono presenti.</li></ul>
    <?php endif; ?>
  </div>

  <!-- ═══ Sezione 2: moduli /app ═══ -->
  <div class="section">
    <h2>2. Moduli core in /app
      <span class="badge <?= $ok_modules ? 'ok' : 'err' ?>">
        <?= $ok_modules ? 'OK' : (!$app_dir_exists ? 'cartella mancante' : count($missing_modules) . ' mancanti') ?>
      </span>
    </h2>
    <p>Cartella <code>/app</code> con i 12 moduli di sicurezza (bootstrap, CSRF, sessione, 2FA, ecc.)</p>
    <?php if (!$app_dir_exists): ?>
      <ul class="files"><li class="bad">✗ CRITICO: la cartella /app non esiste! Il portale non può funzionare.</li></ul>
    <?php elseif ($missing_modules): ?>
      <ul class="files">
        <?php foreach ($missing_modules as $m): ?>
          <li class="bad">✗ MANCANTE: app/<?= htmlspecialchars($m) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <ul class="files"><li class="good">✓ Tutti i 12 moduli /app sono presenti.</li></ul>
    <?php endif; ?>
  </div>

  <!-- ═══ Sezione 3: file orfani ═══ -->
  <div class="section">
    <h2>3. File orfani da estrazione ZIP
      <span class="badge <?= $has_orphans ? 'err' : 'ok' ?>">
        <?= $has_orphans ? count($orphan_files) . ' orfani' : 'OK' ?>
      </span>
    </h2>
    <p>File con backslash <em>nel nome</em> (es. <code>app\Csrf.php</code>) creati per errore quando un ZIP Windows viene estratto da un tool che non gestisce le sottocartelle.</p>

    <div class="safeguard-info">
      <strong>Note tecnica:</strong> su <strong>Windows</strong> il backslash è separatore di path quindi <em>nessun file</em> può legittimamente avere un backslash nel nome — questa sezione sarà sempre vuota su sistemi Windows. Il controllo serve per portali installati su sistemi Linux/Mac dove un'estrazione zip mal fatta può creare file letterali tipo <code>app\Csrf.php</code> nella root.
    </div>

    <?php if ($has_orphans): ?>
      <ul class="files" style="margin-top:10px">
        <?php foreach ($orphan_files as $f): ?>
          <li class="bad">⚠ <?= htmlspecialchars(basename($f)) ?> (<?= number_format(filesize($f)) ?> byte)</li>
        <?php endforeach; ?>
      </ul>

      <div class="actions">
        <strong>Pulizia automatica con safeguard:</strong>
        <ul style="font-size:12px;margin:8px 0 12px 18px">
          <li>Solo file il cui nome inizia con <code>app\</code> e termina in <code>.php</code></li>
          <li>Solo se il nome pulito è in whitelist (12 moduli noti)</li>
          <li>Se <code>app/&lt;file&gt;</code> non esiste → sposta l'orfano lì</li>
          <li>Se <code>app/&lt;file&gt;</code> esiste E ha hash MD5 identico → rimuove l'orfano</li>
          <li>Se <code>app/&lt;file&gt;</code> esiste ma è DIVERSO → <strong>non tocca nulla</strong>, segnala</li>
        </ul>
        <p class="warn-text">⚠ Eseguire backup del filesystem prima di procedere.</p>
        <form method="POST" style="margin-top:12px" onsubmit="return confirm('Confermi la pulizia? Verranno applicate le safeguard, ma fai un backup preventivo se non lo hai già fatto.')">
          <input type="hidden" name="action" value="cleanup">
          <input type="hidden" name="confirm" value="YES">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
          <button type="submit" class="btn danger">🧹 Pulisci file orfani (safe)</button>
        </form>
      </div>
    <?php else: ?>
      <ul class="files"><li class="good">✓ Nessun file orfano rilevato.</li></ul>
    <?php endif; ?>
  </div>

  <!-- ═══ Sezione 4: duplicati nella root ═══ -->
  <?php if ($has_duplicates): ?>
  <div class="section">
    <h2>4. Moduli duplicati nella root
      <span class="badge warn"><?= count($root_duplicates) ?> duplicati</span>
    </h2>
    <p>Questi file dovrebbero stare SOLO in <code>/app/</code>, ma sono stati copiati anche nella root del portale (non causa errori, ma è confuso). Possono essere rimossi a mano dopo aver verificato che <code>/app/</code> contenga le versioni aggiornate.</p>
    <ul class="files">
      <?php foreach ($root_duplicates as $f): ?>
        <li>📄 <?= htmlspecialchars($f) ?> (root) — esiste anche in app/?
          <?= file_exists($root . '/app/' . $f) ? '<span style="color:#065f46">SÌ</span>' : '<span style="color:#991b1b">NO</span>' ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="warn-text">Suggerimento: rimuovere a mano i file di root SOLO se la versione in <code>app/</code> è confermata funzionante. <strong>Questo tool NON propone rimozione automatica</strong> per i duplicati nella root, perché potrebbero avere contenuto diverso e cancellarli senza confronto sarebbe rischioso.</p>
  </div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:30px;color:#94a3b8;font-size:11px">
    <a href="index.php" style="color:#64748b">← Torna alla dashboard</a> ·
    Eliminare questo file dopo l'uso (<code>verify_integrity.php</code>) ·
    v2 fix Windows
  </div>
</div>
</body>
</html>
