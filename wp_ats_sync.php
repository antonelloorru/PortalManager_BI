<?php
/**
 * PortalManager — wp_ats_sync.php (v1.10.05)
 * Recruiting → Sito web (WordPress): sincronizzazione con il plugin pm-ats del sito pubblico.
 *   - stato, test di connessione, invio posizioni, prelievo candidature, registro;
 *   - configurazione (solo Super Admin): URL API, client ID, TLS/proxy, segreto in .env.php (PM_WPATS_SECRET).
 * Permessi: view = consultazione, edit = avvio sincronizzazioni.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/WpAtsSync.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$isSA   = $u_role === 1;
$canRun = can('edit', 'wp_ats_sync.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save' && $isSA) {
        $url = trim((string)($_POST['base_url'] ?? ''));
        $norm = $url === '' ? '' : (WpAtsClient::normalizeBase($url) ?? '');
        $err = [];
        if ($url !== '' && $norm === '') $err[] = 'URL non valido';
        // HTTPS obbligatorio (il segreto e i CV viaggiano su questa connessione); HTTP solo verso questo stesso computer (prove)
        if ($norm !== '' && stripos($norm, 'https://') !== 0 && !preg_match('~^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?/~i', $norm)) $err[] = 'L\'URL deve essere HTTPS';
        $cid = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($_POST['client_id'] ?? '')) ?: 'portalmanager';
        $ca = trim((string)($_POST['ca_file'] ?? ''));
        if ($ca !== '' && !is_file($ca)) $err[] = 'File CA non trovato: ' . $ca;
        $proxy = trim((string)($_POST['proxy'] ?? ''));
        if ($proxy !== '' && !preg_match('#^(https?://)?[A-Za-z0-9.\-\[\]:]+(:\d{2,5})?$#', $proxy)) $err[] = 'Proxy non valido';
        $sec = trim((string)($_POST['secret'] ?? ''));
        if ($sec !== '' && !preg_match('/^[A-Za-z0-9]{32,128}$/', $sec)) $err[] = 'Segreto non valido (32-128 caratteri alfanumerici)';
        $enabled = !empty($_POST['enabled']) ? '1' : '0';
        if ($enabled === '1' && ($norm === '' || ($sec === '' && WpAtsClient::secret() === ''))) $err[] = 'Per attivare servono URL e segreto';
        if ($err) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . $h(implode(' · ', $err)) . "</div>";
        } else {
            $st = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach (['wpats.enabled' => $enabled, 'wpats.base_url' => $norm, 'wpats.client_id' => $cid,
                      'wpats.verify_tls' => !empty($_POST['verify_tls']) ? '1' : '0', 'wpats.ca_file' => $ca, 'wpats.proxy' => $proxy,
                      'wpats.timeout' => (string)max(5, min(120, (int)($_POST['timeout'] ?? 20))),
                      'wpats.push_on_change' => !empty($_POST['push_on_change']) ? '1' : '0',
                      'wpats.pull_batch' => (string)max(1, min(100, (int)($_POST['pull_batch'] ?? 20)))] as $k => $v) $st->execute([$k, $v]);
            $okSec = true;
            if ($sec !== '') $okSec = Env::persist(['PM_WPATS_SECRET' => $sec]);
            if (!empty($_POST['secret_clear'])) $okSec = Env::persist(['PM_WPATS_SECRET' => null]);
            write_log('Recruiting', 'info', 'Configurazione sito WordPress aggiornata' . ($sec !== '' ? ' (segreto sostituito)' : ''), $u_id);
            $_SESSION['flash_msg'] = $okSec ? "<div class='alert alert-success'>Configurazione salvata.</div>"
                                            : "<div class='alert alert-danger'>Impostazioni salvate, ma .env.php non è scrivibile: segreto non aggiornato.</div>";
        }
        redirect_self();
    }
    if (in_array($act, ['test', 'push', 'pull', 'all'], true) && $canRun) {
        @set_time_limit(300);
        $sync = new WpAtsSync($pdo);
        $r = match ($act) {
            'test' => $sync->test($u_id),
            'push' => $sync->pushJobs($u_id),
            'pull' => $sync->pullApplications($u_id),
            'all'  => $sync->run($u_id, 'manuale'),
        };
        write_log('Recruiting', $r['ok'] ? 'success' : 'warning', 'Sito WordPress — ' . $act . ': ' . mb_substr($r['message'], 0, 400), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-" . ($r['ok'] ? 'success' : 'warning') . "'>" . $h($r['message']) . "</div>";
        redirect_self();
    }
}

$cfg = WpAtsClient::settings($pdo);
$secretSet = WpAtsClient::secret() !== '';
$complete = WpAtsClient::fromSettings($pdo) !== null;
$last = fn(string $op) => $pdo->query("SELECT * FROM wp_ats_sync_log WHERE operation = '$op' AND status <> 'running' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
$lp = $last('push'); $lq = $last('pull'); $lt = $last('test');
$log = $pdo->query("SELECT l.*, COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) AS utente
                      FROM wp_ats_sync_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$pubs = $pdo->query("SELECT v.id, v.title, v.location, v.contract_type, pp.channel_url, pp.status AS pub_status, pp.published_at
                       FROM v_public_open_positions v
                       LEFT JOIN position_publications pp ON pp.id = (SELECT MAX(x.id) FROM position_publications x WHERE x.position_id = v.id AND x.channel = 'wordpress')
                      ORDER BY v.opened_at DESC, v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$imps = $pdo->query("SELECT i.*, c.first_name, c.last_name, c.email, jp.title
                       FROM wp_ats_imports i LEFT JOIN candidates c ON c.id = i.candidate_id LEFT JOIN job_positions jp ON jp.id = i.position_id
                      ORDER BY i.imported_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
$nImp = (int)$pdo->query("SELECT COUNT(*) FROM wp_ats_imports WHERE status = 'imported'")->fetchColumn();
$dt = fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '—';
$pill = fn(string $txt, string $c) => "<span style='display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:{$c}1a;color:$c'>" . $txt . "</span>";
$stPill = fn(?string $s) => match ($s) { 'ok' => $pill('OK', '#16a34a'), 'warn' => $pill('ATTENZIONE', '#d97706'), 'error' => $pill('ERRORE', '#dc2626'), 'running' => $pill('IN CORSO', '#2563eb'), default => '—' };
$opLbl = ['test' => 'Test', 'push' => 'Invio posizioni', 'pull' => 'Prelievo candidature'];
$php = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '\\php.exe' : PHP_BINARY;
$cron = __DIR__ . DIRECTORY_SEPARATOR . 'cron_wp_ats.php';

require_once('header.php');
?>
<div class="container" style="max-width:1200px;margin:0 auto;padding:18px">
  <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <h1 style="font-size:20px;font-weight:800;margin:0 0 4px"><i class="fa-brands fa-wordpress"></i> Sito web — Lavora con noi</h1>
      <p style="color:var(--muted);font-size:13px;margin:0">PortalManager pubblica le posizioni aperte sul sito WordPress e preleva le candidature con il CV. La connessione parte sempre da qui: il sito non chiama mai PortalManager.</p>
    </div>
    <?php if ($canRun): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach (['test' => ['fa-plug-circle-check', 'Test connessione', ''], 'push' => ['fa-upload', 'Invia posizioni', ''], 'pull' => ['fa-download', 'Preleva candidature', ''], 'all' => ['fa-rotate', 'Sincronizza tutto', 'btn-primary']] as $a => [$ic, $lb, $cl]): ?>
        <form method="POST" style="margin:0"><?= Csrf::field() ?><input type="hidden" name="action" value="<?= $a ?>">
          <button class="btn btn-sm <?= $cl ?>" <?= $complete ? '' : 'disabled title="Configurazione incompleta"' ?>><i class="fa-solid <?= $ic ?>"></i> <?= $lb ?></button></form>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?= $_SESSION['flash_msg'] ?? '' ?><?php unset($_SESSION['flash_msg']); ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:14px 0">
    <?php foreach ([
      ['Stato', ($cfg['wpats.enabled'] === '1' ? $pill('ATTIVA', '#16a34a') : $pill('NON ATTIVA', '#64748b')) . ' ' . ($complete ? '' : $pill('CONFIG. INCOMPLETA', '#d97706')), ''],
      ['Posizioni aperte', (string)count($pubs), count(array_filter($pubs, fn($p) => $p['pub_status'] === 'published')) . ' pubblicate sul sito'],
      ['Ultimo invio posizioni', $dt($lp['finished_at'] ?? null), $lp ? strip_tags($stPill($lp['status'])) : ''],
      ['Ultimo prelievo', $dt($lq['finished_at'] ?? null), $lq ? strip_tags($stPill($lq['status'])) : ''],
      ['Candidature importate', (string)$nImp, 'dal sito web'],
    ] as [$l, $v, $s]): ?>
      <div class="card" style="padding:12px 14px"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700"><?= $h($l) ?></div>
        <div style="font-size:18px;font-weight:800;margin-top:4px"><?= $v ?></div><div style="font-size:11px;color:var(--muted)"><?= $h($s) ?></div></div>
    <?php endforeach; ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(520px,1fr));gap:14px">
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-briefcase"></i> Posizioni aperte e pubblicazione</h3>
      <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Sono pubblicate le posizioni in stato <b>aperta</b>, già avviate e non scadute. Chiudere o mettere in pausa una posizione la ritira dal sito al prossimo invio<?= $cfg['wpats.push_on_change'] === '1' ? ' (immediato)' : '' ?>.</p>
      <table class="data-table" style="width:100%;font-size:12px">
        <thead><tr><th>Posizione</th><th>Sede</th><th>Contratto</th><th>Sul sito</th></tr></thead><tbody>
        <?php if (!$pubs): ?><tr><td colspan="4" style="color:var(--muted)">Nessuna posizione aperta.</td></tr><?php endif; ?>
        <?php foreach ($pubs as $p): ?>
          <tr><td><a href="<?= $h(url_safe('publish_posizione', ['pos_id' => $p['id']])) ?>"><?= $h($p['title']) ?></a></td><td><?= $h($p['location']) ?></td><td><?= $h($p['contract_type']) ?></td>
            <td><?= $p['pub_status'] === 'published' && $p['channel_url'] ? "<a href='" . $h($p['channel_url']) . "' target='_blank' rel='noopener'>Apri <i class='fa-solid fa-arrow-up-right-from-square'></i></a>" : $pill('DA INVIARE', '#d97706') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>

    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-user-plus"></i> Ultime candidature dal sito</h3>
      <table class="data-table" style="width:100%;font-size:12px">
        <thead><tr><th>Importata</th><th>Candidato</th><th>Posizione</th><th>Rif.</th></tr></thead><tbody>
        <?php if (!$imps): ?><tr><td colspan="4" style="color:var(--muted)">Nessuna candidatura importata.</td></tr><?php endif; ?>
        <?php foreach ($imps as $i): ?>
          <tr><td><?= $dt($i['imported_at']) ?></td>
            <td><?php if ($i['candidate_id']): ?><a href="<?= $h(url_safe('candidato_profilo', ['id' => $i['candidate_id']])) ?>"><?= $h(trim($i['first_name'] . ' ' . $i['last_name'])) ?></a><br><small style="color:var(--muted)"><?= $h($i['email']) ?></small><?php else: ?>—<?php endif; ?></td>
            <td><?= $i['position_id'] ? $h($i['title']) : '<i>spontanea</i>' ?></td><td><code><?= $h(strtoupper(substr($i['wp_uuid'], 0, 8))) ?></code></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
  </div>

  <?php if ($isSA): ?>
  <div class="card" style="padding:14px 16px;margin-top:14px">
    <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-gear"></i> Configurazione <span style="font-weight:400;color:var(--muted);font-size:12px">— solo Super Admin</span></h3>
    <form method="POST" autocomplete="off">
      <?= Csrf::field() ?><input type="hidden" name="action" value="save">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px 16px">
        <div class="form-group" style="grid-column:1/-1"><label>URL API del plugin</label>
          <input type="url" name="base_url" value="<?= $h($cfg['wpats.base_url']) ?>" placeholder="https://www.sito.it/wp-json/pm-ats/v1">
          <small style="color:var(--muted)">Indicato nel sito in <b>Lavora con noi › Impostazioni</b>. Basta anche l'indirizzo del sito: il percorso viene completato.</small></div>
        <div class="form-group"><label>Client ID</label><input type="text" name="client_id" value="<?= $h($cfg['wpats.client_id']) ?>" maxlength="64"></div>
        <div class="form-group"><label>Segreto condiviso <?= $secretSet ? $pill('IMPOSTATO', '#16a34a') : $pill('MANCANTE', '#dc2626') ?></label>
          <input type="password" name="secret" value="" placeholder="<?= $secretSet ? '•••••• (vuoto = invariato)' : 'generato nel plugin WordPress' ?>" autocomplete="new-password">
          <small style="color:var(--muted)">Salvato in <code>.env.php</code> (PM_WPATS_SECRET), mai nel database.</small>
          <?php if ($secretSet): ?><div style="font-size:12px;margin-top:4px"><input type="checkbox" id="wpats-sc" name="secret_clear" value="1" style="width:auto;vertical-align:middle"> <span style="vertical-align:middle">rimuovi segreto</span></div><?php endif; ?></div>
        <div class="form-group"><label>Timeout (s) · candidature per richiesta</label>
          <div style="display:flex;gap:8px"><input type="number" name="timeout" min="5" max="120" value="<?= (int)$cfg['wpats.timeout'] ?>"><input type="number" name="pull_batch" min="1" max="100" value="<?= (int)$cfg['wpats.pull_batch'] ?>"></div></div>
        <div class="form-group"><label>File CA (opzionale)</label><input type="text" name="ca_file" value="<?= $h($cfg['wpats.ca_file']) ?>" placeholder="P:\xampp\apache\bin\curl-ca-bundle.crt">
          <small style="color:var(--muted)">Solo se PHP non trova i certificati (errore «SSL certificate problem»).</small></div>
        <div class="form-group"><label>Proxy in uscita (opzionale)</label><input type="text" name="proxy" value="<?= $h($cfg['wpats.proxy']) ?>" placeholder="proxy.azienda.local:8080"></div>
      </div>
      <div style="display:flex;gap:18px;flex-wrap:wrap;margin:6px 0 10px;font-size:13px">
        <label><input type="checkbox" name="enabled" value="1" <?= $cfg['wpats.enabled'] === '1' ? 'checked' : '' ?>> Sincronizzazione attiva</label>
        <label><input type="checkbox" name="push_on_change" value="1" <?= $cfg['wpats.push_on_change'] === '1' ? 'checked' : '' ?>> Invia subito le posizioni a ogni modifica</label>
        <label><input type="checkbox" name="verify_tls" value="1" <?= $cfg['wpats.verify_tls'] !== '0' ? 'checked' : '' ?>> Verifica certificato TLS</label>
      </div>
      <button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card" style="padding:14px 16px;margin-top:14px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-clock"></i> Sincronizzazione pianificata</h3>
    <p style="font-size:12px;color:var(--muted);margin:0 0 6px">Le candidature arrivano in PortalManager quando vengono prelevate. Pianificare l'esecuzione ogni 15 minuti (Utilità di pianificazione di Windows, come utente con accesso alla cartella):</p>
    <pre style="font-size:11px;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow-x:auto;margin:0">schtasks /Create /SC MINUTE /MO 15 /TN "PortalManager - Sito web" /TR "\"<?= $h($php) ?>\" \"<?= $h($cron) ?>\" --quiet" /RU SYSTEM</pre>
    <p style="font-size:11px;color:var(--muted);margin:6px 0 0">Il comando esegue invio posizioni + prelievo candidature solo se la sincronizzazione è attiva; codice di uscita 0 = ok, 1 = errori, 2 = configurazione.</p>
  </div>

  <div class="card" style="padding:14px 16px;margin-top:14px;overflow-x:auto">
    <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-list"></i> Registro</h3>
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Avvio</th><th>Operazione</th><th>Origine</th><th>Esito</th><th>Dettaglio</th><th>Utente</th></tr></thead><tbody>
      <?php if (!$log): ?><tr><td colspan="6" style="color:var(--muted)">Nessuna sincronizzazione eseguita.</td></tr><?php endif; ?>
      <?php foreach ($log as $l): ?>
        <tr><td style="white-space:nowrap"><?= $dt($l['started_at']) ?></td><td><?= $h($opLbl[$l['operation']] ?? $l['operation']) ?></td><td><?= $h($l['trigger_type']) ?></td>
          <td><?= $stPill($l['status']) ?></td><td><?= $h($l['message']) ?></td><td><?= $h($l['utente'] ?? ($l['trigger_type'] === 'pianificata' ? 'pianificazione' : '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
  </div>
</div>
<?php require_once('footer.php'); ?>
