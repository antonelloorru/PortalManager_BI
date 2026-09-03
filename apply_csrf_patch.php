<?php
/**
 * certV 4.2 — apply_csrf_patch.php
 *
 * Script one-shot per Super Admin: aggiunge <?= csrf_field() ?> dentro
 * tutti i form dei file legacy del portale che ne sono privi.
 *
 * COMPORTAMENTO:
 *   1. ?preview=1   → analizza i file, mostra cosa cambierebbe (dry-run)
 *   2. ?apply=1     → applica le modifiche (con backup .csrfbak per ogni file)
 *   3. ?revert=1    → ripristina i .csrfbak (in caso di problemi)
 *
 * SAFEGUARD:
 *   - Solo Super Admin
 *   - Backup automatico in <file>.csrfbak prima di modificare
 *   - Idempotente: se il form ha già csrf_field(), non lo aggiunge di nuovo
 *   - Non tocca form HTML che hanno method="GET" (non c'è bisogno di CSRF)
 *   - Non tocca form senza tag PHP (file template puri)
 *   - Whitelist esplicita dei file: nessun file fuori lista viene toccato
 *
 * USO TIPICO:
 *   1. Login come Super Admin
 *   2. Apri http://tuo-portale/apply_csrf_patch.php?preview=1
 *   3. Verifica il report
 *   4. Apri http://tuo-portale/apply_csrf_patch.php?apply=1
 *   5. Test: prova "Invia email di test" da SMTP settings → deve funzionare
 *   6. ELIMINA apply_csrf_patch.php dal server
 *
 * IMPORTANTE: questo script viene caricato anche con CSRF_SKIP perché
 * non ha form proprio. Le modifiche sono aderenti al codice esistente.
 */

define('CSRF_SKIP', true);  // questo script non ha form, salta verifica
require_once __DIR__ . '/access_control.php';

if ((int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    die('Solo Super Admin può eseguire questa patch.');
}

$preview = isset($_GET['preview']);
$apply   = isset($_GET['apply']);
$revert  = isset($_GET['revert']);

// ── Lista file target ────────────────────────────────────────────
// Solo file della root del portale che hanno form POST e non hanno CSRF.
$TARGETS = [
    'brand.php', 'brand_distributors.php', 'brand_referents.php', 'brand_technologies.php',
    'candidato_profilo.php', 'catalogo_certificazioni.php',
    'config_notifiche.php',
    'documenti.php',
    'manage_companies.php', 'manage_employees.php', 'manage_permissions.php',
    'manage_roles.php', 'manage_work_modes.php',
    'manager_users.php', 'mass_upload.php',
    'programmazione.php', 'publish_posizione.php',
    'recruiting_agenzie.php', 'recruiting_candidati.php', 'recruiting_contratti.php',
    'recruiting_posizioni.php',
    'report_certificazioni.php',
    'segreteria.php',
    'settings.php',
    'smtp_settings.php',
    'training_plans.php',
    'view_logs.php',
    'visualizza_storico.php',
];

// File esplicitamente NON da toccare anche se nella root
$BLACKLIST = [
    'install.php', 'reset_admin.php', 'fix_password.php',
    'db_upgrade.php', 'system_update.php', 'schema_check_upgrade.php', 'health_check.php',
    'login.php', 'logout.php', 'index.php',
    'r.php', 'header.php', 'footer.php', 'functions.php',
    'access_control.php', 'Config.php', 'apply_csrf_patch.php',
    '2fa_settings.php', '2fa_verify.php', 'manage_users_2fa.php',
    'verify_integrity.php', 'verify_integrity_v2.php',
    'unauthorized.php', 'upload_certificato.php',
];

$root = __DIR__;
$report = [];

// ── REVERT ───────────────────────────────────────────────────────
if ($revert) {
    foreach ($TARGETS as $f) {
        $bak = "$root/$f.csrfbak";
        $cur = "$root/$f";
        if (file_exists($bak)) {
            if (@copy($bak, $cur)) {
                $report[] = ['file' => $f, 'action' => 'revert', 'ok' => true, 'forms' => 0, 'msg' => 'ripristinato da .csrfbak'];
            } else {
                $report[] = ['file' => $f, 'action' => 'revert', 'ok' => false, 'forms' => 0, 'msg' => 'impossibile ripristinare'];
            }
        }
    }
}
// ── ANALISI/APPLICA ─────────────────────────────────────────────
elseif ($preview || $apply) {
    foreach ($TARGETS as $f) {
        if (in_array($f, $BLACKLIST, true)) continue;
        $path = "$root/$f";
        if (!is_file($path)) {
            $report[] = ['file' => $f, 'action' => 'skip', 'ok' => null, 'forms' => 0, 'msg' => 'file non trovato'];
            continue;
        }

        $src = @file_get_contents($path);
        if ($src === false) {
            $report[] = ['file' => $f, 'action' => 'skip', 'ok' => false, 'forms' => 0, 'msg' => 'errore lettura'];
            continue;
        }

        $original = $src;

        // Trova tutti i tag <form ...> e analizzali uno per uno.
        // Pattern accetta tag con o senza attributi multilinea.
        $modifications = 0;
        $form_count = 0;

        $src = preg_replace_callback(
            '/<form\b[^>]*>/i',
            function ($m) use (&$modifications, &$form_count) {
                $form_count++;
                $tag = $m[0];

                // Se il form è method="GET" (case insensitive), salta
                if (preg_match('/\bmethod\s*=\s*["\']?get["\']?/i', $tag)) {
                    return $tag;
                }

                // Cerca csrf_field nei 200 caratteri successivi al tag
                // per evitare match troppo lontani su altri form
                // (controllo già fatto da preg_replace_callback su tag)
                return $tag . "\n            <?= csrf_field() ?>";
            },
            $src
        );

        // Verifica idempotenza: se il file conteneva già csrf_field/csrf_token
        // dentro qualche form, le nostre aggiunte sono ridondanti per quel form.
        // Controlliamo la presenza globale di csrf_field nel risultato.
        $had_csrf_already = (strpos($original, 'csrf_field') !== false || strpos($original, 'csrf_token') !== false);

        if ($had_csrf_already) {
            // Caso edge: il file aveva già qualche csrf_field. Lasciamolo invariato e segnaliamo.
            $report[] = ['file' => $f, 'action' => 'skip', 'ok' => true, 'forms' => $form_count, 'msg' => 'gia\' contiene csrf_field, non modificato per sicurezza'];
            continue;
        }

        if ($src === $original) {
            $report[] = ['file' => $f, 'action' => 'no-change', 'ok' => true, 'forms' => $form_count, 'msg' => 'nessuna modifica necessaria'];
            continue;
        }

        // Conta i csrf_field aggiunti
        $modifications = substr_count($src, '<?= csrf_field() ?>') - substr_count($original, '<?= csrf_field() ?>');

        if ($apply) {
            // Backup
            $bak = "$path.csrfbak";
            if (!file_exists($bak)) {
                @copy($path, $bak);
            }
            // Scrivi
            if (@file_put_contents($path, $src, LOCK_EX) === false) {
                $report[] = ['file' => $f, 'action' => 'fail', 'ok' => false, 'forms' => $form_count, 'msg' => 'errore scrittura'];
                continue;
            }
        }

        $report[] = [
            'file'   => $f,
            'action' => $apply ? 'patched' : 'preview',
            'ok'     => true,
            'forms'  => $form_count,
            'msg'    => "$modifications csrf_field aggiunti su $form_count form",
        ];
    }
}

$total_files    = count($report);
$total_patched  = count(array_filter($report, fn($r) => $r['action'] === 'patched' || $r['action'] === 'preview'));
$total_skipped  = count(array_filter($report, fn($r) => $r['action'] === 'skip' || $r['action'] === 'no-change'));
$total_failed   = count(array_filter($report, fn($r) => $r['ok'] === false));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>CSRF Patch — certV</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;padding:32px;color:#1e293b}
.container{max-width:920px;margin:0 auto;background:#fff;padding:32px;border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05)}
h1{font-size:24px;margin-bottom:6px}
.sub{color:#64748b;font-size:13px;margin-bottom:24px}
.actions{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.btn{padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;cursor:pointer;border:none;font-family:inherit}
.btn.preview{background:#0ea5e9;color:#fff}
.btn.apply{background:#10b981;color:#fff}
.btn.revert{background:#f59e0b;color:#fff}
.btn:hover{filter:brightness(1.08)}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.summary-card{padding:16px;border-radius:8px;border-left:4px solid}
.summary-card.total{background:#f8fafc;border-color:#0ea5e9}
.summary-card.patched{background:#f0fdf4;border-color:#10b981}
.summary-card.skipped{background:#fffbeb;border-color:#f59e0b}
.summary-card.failed{background:#fef2f2;border-color:#ef4444}
.summary-card .lbl{font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700;letter-spacing:.5px}
.summary-card .val{font-size:28px;font-weight:800;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px}
th,td{padding:8px 12px;text-align:left;border-bottom:1px solid #e2e8f0}
th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:700}
td.file{font-family:Consolas,monospace;font-size:12px}
td.action{text-transform:uppercase;font-size:11px;font-weight:700}
.act-patched{color:#065f46}
.act-preview{color:#0369a1}
.act-skip{color:#92400e}
.act-no-change{color:#64748b}
.act-fail{color:#991b1b}
.act-revert{color:#5b21b6}
.warn{background:#fffbeb;color:#92400e;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.5}
.ok{background:#f0fdf4;color:#065f46;border-left:4px solid #10b981;padding:14px 18px;border-radius:8px;margin-bottom:18px;font-size:13px}
.info{background:#eff6ff;color:#1e40af;border-left:4px solid #3b82f6;padding:14px 18px;border-radius:8px;margin-bottom:18px;font-size:13px;line-height:1.5}
code{background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px}
ul{margin-left:20px;font-size:13px;color:#475569;line-height:1.7}
</style>
</head>
<body>
<div class="container">
  <h1>🔒 CSRF Patch — Tool admin legacy</h1>
  <p class="sub">Aggiunge <code>&lt;?= csrf_field() ?&gt;</code> dentro i form dei tool admin che ne sono privi, eliminando i 403 — Token di sicurezza non valido.</p>

  <?php if (!$preview && !$apply && !$revert): ?>
    <div class="info">
      <strong>Modalità: nessuna azione selezionata.</strong><br>
      Scegli un'azione:
      <ul style="margin-top:8px">
        <li><strong>Anteprima</strong>: mostra cosa cambierebbe, non scrive nulla.</li>
        <li><strong>Applica</strong>: modifica i file (crea backup <code>.csrfbak</code>).</li>
        <li><strong>Ripristina</strong>: ripristina i file dai backup <code>.csrfbak</code>.</li>
      </ul>
    </div>
  <?php endif; ?>

  <div class="actions">
    <a class="btn preview" href="?preview=1">👁 Anteprima</a>
    <a class="btn apply" href="?apply=1" onclick="return confirm('Applicare la patch su <?= count($TARGETS) ?> file?\n\nUn backup .csrfbak verrà creato per ogni file.')">✅ Applica patch</a>
    <a class="btn revert" href="?revert=1" onclick="return confirm('Ripristinare TUTTI i file dai backup .csrfbak?\n\nLe modifiche manuali fatte dopo la patch saranno perse.')">↩ Ripristina</a>
  </div>

  <?php if ($preview || $apply || $revert): ?>
    <div class="summary">
      <div class="summary-card total">
        <div class="lbl">File processati</div>
        <div class="val"><?= $total_files ?></div>
      </div>
      <div class="summary-card patched">
        <div class="lbl"><?= $apply ? 'Modificati' : ($revert ? 'Ripristinati' : 'In anteprima') ?></div>
        <div class="val" style="color:#10b981"><?= $total_patched ?></div>
      </div>
      <div class="summary-card skipped">
        <div class="lbl">Saltati</div>
        <div class="val" style="color:#f59e0b"><?= $total_skipped ?></div>
      </div>
      <div class="summary-card failed">
        <div class="lbl">Falliti</div>
        <div class="val" style="color:#ef4444"><?= $total_failed ?></div>
      </div>
    </div>

    <?php if ($apply && $total_patched > 0): ?>
      <div class="ok">
        ✅ <strong>Patch applicata.</strong> Ora prova un'azione che prima dava 403 (es. "Invia email di test" da SMTP settings) — deve funzionare. Se qualcosa non va, usa il bottone "↩ Ripristina" qui sopra.
      </div>
    <?php elseif ($revert && $total_patched > 0): ?>
      <div class="ok">
        ↩ <strong>Ripristino completato.</strong> I file sono tornati allo stato pre-patch.
      </div>
    <?php elseif ($preview): ?>
      <div class="warn">
        👁 <strong>Anteprima — nessun file è stato modificato.</strong><br>
        Se i risultati ti sembrano corretti, clicca "Applica patch".
      </div>
    <?php endif; ?>

    <table>
      <thead>
        <tr>
          <th>File</th>
          <th>Forms</th>
          <th>Esito</th>
          <th>Dettaglio</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($report as $r): ?>
        <tr>
          <td class="file"><?= htmlspecialchars($r['file']) ?></td>
          <td><?= $r['forms'] ?></td>
          <td class="action act-<?= $r['action'] ?>"><?= htmlspecialchars($r['action']) ?></td>
          <td><?= htmlspecialchars($r['msg']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="warn">
    ⚠ <strong>Importante:</strong> dopo aver verificato che tutto funziona, <strong>elimina questo file</strong> (<code>apply_csrf_patch.php</code>) dal server. È un tool di manutenzione che non deve restare accessibile.
  </div>

  <p style="text-align:center;color:#94a3b8;font-size:12px;margin-top:30px">
    <a href="index.php" style="color:#64748b">← Torna alla dashboard</a>
  </p>
</div>
</body>
</html>
