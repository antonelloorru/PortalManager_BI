<?php
/**
 * PortalManager — wp_ats_setup.php (v1.10.14; v1.10.16 diagnostica al passo 3)
 * Recruiting › Sito web › Configurazione guidata della connessione con il plugin WordPress pm-ats (solo Super Admin).
 *   1 Prerequisiti  curl, HMAC, .env.php scrivibile, tabelle, uscita HTTPS, versioni
 *   2 Connessione   codice di connessione del plugin (PMATS1.…) oppure URL, client ID e segreto a mano
 *   3 Verifica      test firmato su /sync/status, versione del plugin e compatibilità
 *   4 Opzioni       sincronizzazione automatica, invio immediato, lotto, timeout, TLS / CA / proxy
 *   5 Avvio         primo invio posizioni e prelievo candidature, pianificazione, completamento
 * Il segreto è salvato solo in .env.php (PM_WPATS_SECRET). La sincronizzazione si attiva al passo 5.
 * Stato: wpats.setup_step / wpats.setup_done / wpats.setup_at (app_settings).
 */
require_once('access_control.php');
require_once __DIR__ . '/app/WpAtsConfig.php';
require_once __DIR__ . '/app/WpAtsSync.php';
require_once __DIR__ . '/app/WpAtsDiag.php';

$u_id = (int)$_SESSION['user_id'];
if ((int)($_SESSION['role_id'] ?? 99) !== 1) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>La configurazione guidata è riservata al Super Admin.</div>"; redirect('wp_ats_sync'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$STEPS = [1 => 'Prerequisiti', 2 => 'Connessione', 3 => 'Verifica', 4 => 'Opzioni', 5 => 'Avvio'];
$reached = max(1, (int)WpAtsConfig::setting($pdo, 'wpats.setup_step', '1'));
$step = max(1, min(5, (int)($_GET['step'] ?? (WpAtsConfig::setupDone($pdo) ? 1 : $reached))));
$go = function (int $s, string $type = '', string $msg = '') use ($pdo) {
    if ($msg !== '') $_SESSION['flash_msg'] = "<div class='alert alert-$type'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>";
    WpAtsConfig::set($pdo, ['wpats.setup_step' => (string)max($s, (int)WpAtsConfig::setting($pdo, 'wpats.setup_step', '1'))]);
    redirect('wp_ats_setup', ['step' => $s]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $s = (int)($_POST['step'] ?? 1);
    if ($s === 1) {
        $ko = in_array('ko', array_column(WpAtsConfig::prerequisites($pdo), 0), true);
        if ($ko && empty($_POST['force'])) $go(1, 'danger', 'Prerequisiti obbligatori non soddisfatti.');
        $go(2);
    }
    if ($s === 2) {
        $code = trim((string)($_POST['code'] ?? ''));
        if ($code !== '') {
            $c = WpAtsConfig::parseCode($code);
            if (!$c) $go(2, 'danger', 'Codice di connessione non valido: copiarlo per intero dal plugin (inizia con PMATS1.).');
            $err = WpAtsConfig::save($pdo, ['base_url' => $c['url'], 'client_id' => $c['client'], 'enabled' => '0'], $c['secret']);
            $src = 'codice di connessione (plugin ' . ($c['plugin'] ?: '?') . ')';
        } else {
            $err = WpAtsConfig::save($pdo, ['base_url' => (string)($_POST['base_url'] ?? ''), 'client_id' => (string)($_POST['client_id'] ?? ''), 'enabled' => '0'], trim((string)($_POST['secret'] ?? '')));
            if (!$err && WpAtsClient::secret() === '') $err[] = 'Segreto mancante';
            if (!$err && WpAtsClient::settings($pdo)['wpats.base_url'] === '') $err[] = 'URL mancante';
            $src = 'inserimento manuale';
        }
        if ($err) $go(2, 'danger', implode(' · ', $err));
        write_log('Recruiting', 'info', "Configurazione guidata sito WordPress: connessione salvata ($src)", $u_id);
        $go(3);
    }
    if ($s === 3 && ($_POST['do'] ?? '') === 'diag') {   // v1.10.16
        @set_time_limit(120);
        $d = WpAtsConfig::diag($pdo, $u_id);
        $go(3, $d['ok'] ? 'success' : 'danger', 'Diagnostica: ' . $d['summary']);
    }
    if ($s === 3) {
        @set_time_limit(120);
        $r = WpAtsConfig::test($pdo, $u_id);
        write_log('Recruiting', $r['ok'] ? 'success' : 'warning', 'Configurazione guidata: test connessione — ' . mb_substr($r['message'], 0, 300), $u_id);
        if (!$r['ok']) $go(3, 'danger', $r['message'] . (isset($r['compat']) ? ' — ' . $r['compat']['msg'] : '') . (isset($r['diag']) ? ' — dettaglio nella diagnostica sotto.' : ''));
        $go(4, ($r['compat']['level'] ?? 'ok') === 'ok' ? 'success' : 'warning', $r['message'] . ' — ' . ($r['compat']['msg'] ?? ''));
    }
    if ($s === 4) {
        $err = WpAtsConfig::save($pdo, ['push_on_change' => $_POST['push_on_change'] ?? '', 'pull_batch' => $_POST['pull_batch'] ?? 20, 'timeout' => $_POST['timeout'] ?? 20,
                                        'verify_tls' => $_POST['verify_tls'] ?? '', 'ca_file' => $_POST['ca_file'] ?? '', 'proxy' => $_POST['proxy'] ?? '', 'resolve_ip' => $_POST['resolve_ip'] ?? '']);
        if ($err) $go(4, 'danger', implode(' · ', $err));
        $go(5);
    }
    if ($s === 5) {
        $act = (string)($_POST['do'] ?? 'finish');
        if ($act === 'push' || $act === 'pull') {
            @set_time_limit(300);
            $sync = new WpAtsSync($pdo);
            $r = $act === 'push' ? $sync->pushJobs($u_id) : $sync->pullApplications($u_id);
            write_log('Recruiting', $r['ok'] ? 'success' : 'warning', 'Configurazione guidata: ' . $act . ' — ' . mb_substr($r['message'], 0, 300), $u_id);
            $go(5, $r['ok'] ? 'success' : 'warning', $r['message']);
        }
        $err = WpAtsConfig::save($pdo, ['enabled' => empty($_POST['enabled']) ? '0' : '1']);
        if ($err) $go(5, 'danger', implode(' · ', $err));
        WpAtsConfig::set($pdo, ['wpats.setup_done' => '1', 'wpats.setup_at' => date('Y-m-d H:i:s'), 'wpats.setup_step' => '5']);
        write_log('Recruiting', 'success', 'Configurazione guidata del sito WordPress completata', $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Configurazione completata. Le impostazioni restano modificabili in Sito web › Impostazioni.</div>";
        redirect('wp_ats_sync');
    }
}

$cfg = WpAtsClient::settings($pdo);
$secretSet = WpAtsClient::secret() !== '';
$info = WpAtsConfig::remoteInfo($pdo);
$pill = fn(string $t, string $c) => "<span style='display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:{$c}1a;color:$c'>" . $t . "</span>";
$ico = ['ok' => ['✔', '#16a34a'], 'warn' => ['!', '#d97706'], 'ko' => ['✘', '#dc2626']];
$php = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '\\php.exe' : PHP_BINARY;
$cron = __DIR__ . DIRECTORY_SEPARATOR . 'cron_wp_ats.php';
require_once('header.php');
?>
<div class="container" style="max-width:980px;margin:0 auto;padding:18px">
  <h1 style="font-size:20px;font-weight:800;margin:0 0 4px"><i class="fa-solid fa-wand-magic-sparkles"></i> Sito web — configurazione guidata</h1>
  <p style="color:var(--muted);font-size:13px;margin:0 0 12px">Collegamento con il plugin WordPress <b>pm-ats</b> (≥ <?= $h(WpAtsConfig::PLUGIN_MIN) ?>, API v<?= $h(WpAtsConfig::API_VERSION) ?>).
    Nel sito: <i>Lavora con noi › Configurazione guidata</i> genera il codice di connessione da incollare al passo 2.
    <a href="<?= url_safe('wp_ats_settings') ?>">Impostazioni</a> · <a href="<?= url_safe('wp_ats_sync') ?>">Sincronizzazione</a></p>

  <ol style="display:flex;flex-wrap:wrap;gap:6px;list-style:none;padding:0;margin:0 0 14px">
    <?php foreach ($STEPS as $i => $l): $cur = $i === $step; $done = $i < $reached || WpAtsConfig::setupDone($pdo); ?>
      <li><a href="<?= url_safe('wp_ats_setup', ['step' => $i]) ?>" style="display:flex;gap:6px;align-items:center;padding:6px 12px;border-radius:20px;text-decoration:none;font-size:13px;border:1px solid <?= $cur ? '#2563eb' : '#e2e8f0' ?>;color:<?= $cur ? '#1e293b' : '#64748b' ?>;font-weight:<?= $cur ? 700 : 400 ?>;background:#fff">
        <span style="display:inline-flex;width:20px;height:20px;border-radius:50%;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;background:<?= $cur ? '#2563eb' : ($done ? '#16a34a' : '#cbd5e1') ?>"><?= $i ?></span><?= $h($l) ?></a></li>
    <?php endforeach; ?>
  </ol>

  <?= $_SESSION['flash_msg'] ?? '' ?><?php unset($_SESSION['flash_msg']); ?>

  <form method="POST" class="card" style="padding:16px 18px" autocomplete="off">
    <?= Csrf::field() ?><input type="hidden" name="step" value="<?= $step ?>">
    <?php if ($step === 1): $pre = WpAtsConfig::prerequisites($pdo); ?>
      <h3 style="font-size:15px;margin:0 0 8px">1. Prerequisiti</h3>
      <p style="font-size:12px;color:var(--muted)">PortalManager avvia sempre la connessione verso il sito (invio posizioni, prelievo candidature): nessuna porta in ingresso da aprire.</p>
      <table class="data-table" style="width:100%;font-size:13px"><tbody>
        <?php foreach ($pre as [$st, $l, $d]): ?><tr><td style="width:28px;text-align:center;font-weight:800;color:<?= $ico[$st][1] ?>"><?= $ico[$st][0] ?></td><td><b><?= $h($l) ?></b></td><td style="color:var(--muted)"><?= $h($d) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php if (in_array('ko', array_column($pre, 0), true)): ?><label style="font-size:13px;display:block;margin-top:8px"><input type="checkbox" name="force" value="1"> Prosegui comunque</label><?php endif; ?>

    <?php elseif ($step === 2): ?>
      <h3 style="font-size:15px;margin:0 0 8px">2. Connessione</h3>
      <div class="form-group"><label>Codice di connessione del plugin <span style="font-weight:400;color:var(--muted)">(consigliato)</span></label>
        <textarea name="code" rows="3" style="font-family:monospace;font-size:12px" placeholder="PMATS1.eyJ2Ijox…"></textarea>
        <small style="color:var(--muted)">Generato nel sito in <i>Lavora con noi › Configurazione guidata › Connessione</i> (o Impostazioni › Connessione › Rigenera segreto). Contiene URL API, client ID e segreto: il segreto va in <code>.env.php</code>.</small></div>
      <details <?= $cfg['wpats.base_url'] !== '' ? 'open' : '' ?>><summary style="cursor:pointer;font-size:13px;font-weight:700">oppure inserimento manuale</summary>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px 14px;margin-top:8px">
          <div class="form-group" style="grid-column:1/-1"><label>URL API del plugin</label><input type="url" name="base_url" value="<?= $h($cfg['wpats.base_url']) ?>" placeholder="https://www.sito.it/wp-json/pm-ats/v1"></div>
          <div class="form-group"><label>Client ID</label><input type="text" name="client_id" value="<?= $h($cfg['wpats.client_id']) ?>"></div>
          <div class="form-group"><label>Segreto condiviso <?= $secretSet ? $pill('IMPOSTATO', '#16a34a') : $pill('MANCANTE', '#dc2626') ?></label>
            <input type="password" name="secret" placeholder="<?= $secretSet ? '•••••• (vuoto = invariato)' : '64 caratteri dal plugin' ?>" autocomplete="new-password"></div>
        </div></details>

    <?php elseif ($step === 3): ?>
      <h3 style="font-size:15px;margin:0 0 8px">3. Verifica</h3>
      <p style="font-size:13px">Chiamata firmata a <code><?= $h($cfg['wpats.base_url'] ?: '—') ?>/sync/status</code> con client ID <code><?= $h($cfg['wpats.client_id']) ?></code>.</p>
      <?php if ($info): ?>
        <table class="data-table" style="width:100%;font-size:13px"><tbody>
          <tr><td style="width:220px">Sito</td><td><?= $h($info['site'] ?? '') ?></td></tr>
          <tr><td>Plugin / API</td><td><?= $h(($info['plugin'] ?? '?') . ' / v' . ($info['api'] ?? '1')) ?> — <?= $pill(strtoupper($info['compat'] ?? '?'), ['ok' => '#16a34a', 'warn' => '#d97706', 'ko' => '#dc2626'][$info['compat'] ?? 'warn'] ?? '#64748b') ?> <?= $h($info['compat_msg'] ?? '') ?></td></tr>
          <tr><td>WordPress / PHP</td><td><?= $h(($info['wordpress'] ?? '') . ' / ' . ($info['php'] ?? '')) ?></td></tr>
          <tr><td>Configurazione del sito</td><td><?= $h(($info['onboarding'] ?? '') === 'done' ? 'completata' : (($info['onboarding'] ?? '') ?: '—')) ?></td></tr>
          <tr><td>Ultima verifica</td><td><?= $h($info['checked_at'] ?? '') ?></td></tr>
        </tbody></table>
      <?php endif; ?>
      <?php $diag = WpAtsConfig::lastDiag($pdo); if ($diag && (!$diag['ok'] || ($_GET['diag'] ?? '') === '1')): ?>
        <div style="margin-top:10px"><?= WpAtsDiag::html($diag) ?></div>
      <?php endif; ?>
      <p style="font-size:12px;color:var(--muted)">Se il test fallisce viene eseguita la diagnostica passo per passo con il codice d'errore effettivo (HTTP, codice del plugin, errore cURL) e il rimedio.
        <button class="btn btn-sm" name="do" value="diag" style="margin-left:6px"><i class="fa-solid fa-stethoscope"></i> Solo diagnostica</button></p>

    <?php elseif ($step === 4): ?>
      <h3 style="font-size:15px;margin:0 0 8px">4. Opzioni</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px 14px">
        <div class="form-group"><label>Candidature per richiesta</label><input type="number" name="pull_batch" min="1" max="100" value="<?= (int)$cfg['wpats.pull_batch'] ?>"></div>
        <div class="form-group"><label>Timeout (s)</label><input type="number" name="timeout" min="5" max="120" value="<?= (int)$cfg['wpats.timeout'] ?>"></div>
        <div class="form-group"><label>File CA (opzionale)</label><input type="text" name="ca_file" value="<?= $h($cfg['wpats.ca_file']) ?>" placeholder="P:\xampp\apache\bin\curl-ca-bundle.crt"></div>
        <div class="form-group"><label>Proxy in uscita (opzionale)</label><input type="text" name="proxy" value="<?= $h($cfg['wpats.proxy']) ?>" placeholder="proxy.azienda.local:8080"></div>
        <div class="form-group"><label>IP forzato (opzionale)</label><input type="text" name="resolve_ip" value="<?= $h($cfg['wpats.resolve_ip']) ?>" placeholder="IP interno del server web (NAT hairpin)"></div>
      </div>
      <label style="font-size:13px;display:block"><input type="checkbox" name="push_on_change" value="1" <?= $cfg['wpats.push_on_change'] === '1' ? 'checked' : '' ?>> Invia subito le posizioni a ogni modifica</label>
      <label style="font-size:13px;display:block"><input type="checkbox" name="verify_tls" value="1" <?= $cfg['wpats.verify_tls'] !== '0' ? 'checked' : '' ?>> Verifica certificato TLS (disattivare solo per prove)</label>

    <?php else: ?>
      <h3 style="font-size:15px;margin:0 0 8px">5. Avvio</h3>
      <p style="font-size:13px">Primo invio delle posizioni aperte e prelievo delle candidature già presenti (facoltativi), poi attivazione.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <button class="btn btn-sm" name="do" value="push"><i class="fa-solid fa-upload"></i> Invia posizioni ora</button>
        <button class="btn btn-sm" name="do" value="pull"><i class="fa-solid fa-download"></i> Preleva candidature ora</button>
      </div>
      <p style="font-size:12px;margin:0 0 4px">Pianificazione consigliata ogni 15 minuti (Utilità di pianificazione di Windows):</p>
      <pre style="font-size:11px;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow-x:auto">schtasks /Create /SC MINUTE /MO 15 /TN "PortalManager - Sito web" /TR "\"<?= $h($php) ?>\" \"<?= $h($cron) ?>\" --quiet" /RU SYSTEM</pre>
      <label style="font-size:13px;display:block"><input type="checkbox" name="enabled" value="1" checked> Attiva la sincronizzazione</label>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;gap:8px;border-top:1px solid #e2e8f0;padding-top:12px;margin-top:12px">
      <span><?php if ($step > 1): ?><a class="btn btn-sm" href="<?= url_safe('wp_ats_setup', ['step' => $step - 1]) ?>">&larr; Indietro</a><?php endif; ?></span>
      <button class="btn btn-primary btn-sm" name="do" value="finish"><?= [1 => 'Continua', 2 => 'Salva e continua', 3 => 'Esegui il test', 4 => 'Salva e continua', 5 => 'Completa la configurazione'][$step] ?> &rarr;</button>
    </div>
  </form>
</div>
<?php require_once('footer.php'); ?>
