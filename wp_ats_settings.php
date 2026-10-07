<?php
/**
 * PortalManager — wp_ats_settings.php (v1.10.14)
 * Recruiting › Sito web › Impostazioni della connessione con il plugin WordPress pm-ats (solo Super Admin).
 *   Connessione       URL API, client ID, segreto (solo .env.php: PM_WPATS_SECRET), compilazione da codice PMATS1.
 *   Rete              verifica TLS, file CA, proxy, timeout
 *   Sincronizzazione  attivazione, invio immediato a ogni modifica, candidature per richiesta
 *   Versioni          PortalManager / plugin / API / WordPress / PHP, compatibilità (ultimo test)
 *   Azioni            test connessione, rimozione segreto, configurazione guidata, comando di pianificazione
 */
require_once('access_control.php');
require_once __DIR__ . '/app/WpAtsConfig.php';

$u_id = (int)$_SESSION['user_id'];
if ((int)($_SESSION['role_id'] ?? 99) !== 1) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Le impostazioni del sito web sono riservate al Super Admin.</div>"; redirect('wp_ats_sync'); }
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$flash = function (string $type, string $msg): void { $_SESSION['flash_msg'] = "<div class='alert alert-$type'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>"; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        $in = ['base_url' => $_POST['base_url'] ?? '', 'client_id' => $_POST['client_id'] ?? '', 'ca_file' => $_POST['ca_file'] ?? '', 'proxy' => $_POST['proxy'] ?? '',
               'timeout' => $_POST['timeout'] ?? 20, 'pull_batch' => $_POST['pull_batch'] ?? 20,
               'verify_tls' => $_POST['verify_tls'] ?? '', 'push_on_change' => $_POST['push_on_change'] ?? '', 'enabled' => $_POST['enabled'] ?? ''];
        $secret = trim((string)($_POST['secret'] ?? ''));
        $code = trim((string)($_POST['code'] ?? ''));
        $src = '';
        if ($code !== '') {
            $c = WpAtsConfig::parseCode($code);
            if (!$c) { $flash('danger', 'Codice di connessione non valido (deve iniziare con PMATS1.).'); redirect_self(); }
            $in['base_url'] = $c['url']; $in['client_id'] = $c['client']; $secret = $c['secret'];
            $src = ' da codice di connessione (plugin ' . ($c['plugin'] ?: '?') . ')';
        }
        $err = WpAtsConfig::save($pdo, $in, $secret, !empty($_POST['secret_clear']) && $secret === '');
        if ($err) { $flash('danger', implode(' · ', $err)); redirect_self(); }
        write_log('Recruiting', 'info', 'Impostazioni sito WordPress aggiornate' . $src . ($secret !== '' ? ' (segreto sostituito)' : (!empty($_POST['secret_clear']) ? ' (segreto rimosso)' : '')), $u_id);
        $flash('success', 'Impostazioni salvate.' . ($secret !== '' ? ' Eseguire il test di connessione.' : ''));
        redirect_self();
    }
    if ($act === 'test') {
        @set_time_limit(120);
        $r = WpAtsConfig::test($pdo, $u_id);
        write_log('Recruiting', $r['ok'] ? 'success' : 'warning', 'Impostazioni sito WordPress: test — ' . mb_substr($r['message'], 0, 300), $u_id);
        $lvl = !$r['ok'] ? 'danger' : (($r['compat']['level'] ?? 'ok') === 'ok' ? 'success' : 'warning');
        $flash($lvl, $r['message'] . (isset($r['compat']) ? ' — ' . $r['compat']['msg'] : ''));
        redirect_self();
    }
    if ($act === 'restart') {
        WpAtsConfig::set($pdo, ['wpats.setup_done' => '0', 'wpats.setup_step' => '1']);
        write_log('Recruiting', 'info', 'Configurazione guidata sito WordPress riavviata', $u_id);
        redirect('wp_ats_setup', ['step' => 1]);
    }
}

$cfg = WpAtsClient::settings($pdo);
$secretSet = WpAtsClient::secret() !== '';
$info = WpAtsConfig::remoteInfo($pdo);
$done = WpAtsConfig::setupDone($pdo);
$pill = fn(string $t, string $c) => "<span style='display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;background:{$c}1a;color:$c'>" . $t . "</span>";
$cc = ['ok' => '#16a34a', 'warn' => '#d97706', 'ko' => '#dc2626'];
$php = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '\\php.exe' : PHP_BINARY;
$cron = __DIR__ . DIRECTORY_SEPARATOR . 'cron_wp_ats.php';
$wpSecHead = fn(string $ic, string $t) => "<h3 style='font-size:14px;margin:14px 0 8px;padding-top:10px;border-top:1px solid #e2e8f0'><i class='fa-solid $ic'></i> " . $t . "</h3>";
require_once('header.php');
?>
<div class="container" style="max-width:1100px;margin:0 auto;padding:18px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <h1 style="font-size:20px;font-weight:800;margin:0 0 4px"><i class="fa-solid fa-sliders"></i> Sito web — impostazioni connessione</h1>
      <p style="color:var(--muted);font-size:13px;margin:0">Plugin WordPress <b>pm-ats</b> · <a href="<?= url_safe('wp_ats_sync') ?>">Sincronizzazione</a> · <a href="<?= url_safe('wp_ats_setup') ?>">Configurazione guidata</a></p>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <form method="POST" style="margin:0"><?= Csrf::field() ?><input type="hidden" name="action" value="test">
        <button class="btn btn-sm" <?= WpAtsClient::fromSettings($pdo) ? '' : 'disabled title="URL o segreto mancante"' ?>><i class="fa-solid fa-plug-circle-check"></i> Test connessione</button></form>
      <form method="POST" style="margin:0" onsubmit="return confirm('Riavviare la configurazione guidata? Le impostazioni attuali restano salvate.')"><?= Csrf::field() ?><input type="hidden" name="action" value="restart">
        <button class="btn btn-sm"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $done ? 'Riavvia' : 'Avvia' ?> configurazione guidata</button></form>
    </div>
  </div>

  <?= $_SESSION['flash_msg'] ?? '' ?><?php unset($_SESSION['flash_msg']); ?>
  <?php if (!$done): ?><div class="alert alert-info" style="margin-top:10px">Configurazione guidata non completata: <a href="<?= url_safe('wp_ats_setup') ?>">avviala</a> per collegare il sito in 5 passi.</div><?php endif; ?>

  <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:14px;margin-top:12px">
    <form method="POST" class="card" style="padding:14px 16px" autocomplete="off" id="wpats-settings">
      <?= Csrf::field() ?><input type="hidden" name="action" value="save">
      <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-link"></i> Connessione</h3>
      <details style="margin-bottom:8px"><summary style="cursor:pointer;font-size:13px">Compila da codice di connessione del plugin</summary>
        <textarea name="code" rows="3" style="font-family:monospace;font-size:12px;margin-top:6px" placeholder="PMATS1.eyJ2Ijox…"></textarea>
        <small style="color:var(--muted)">Sostituisce URL, client ID e segreto con quelli del codice. Le altre impostazioni restano quelle sotto.</small></details>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px 14px">
        <div class="form-group" style="grid-column:1/-1"><label>URL API del plugin</label>
          <input type="url" name="base_url" value="<?= $h($cfg['wpats.base_url']) ?>" placeholder="https://www.sito.it/wp-json/pm-ats/v1">
          <small style="color:var(--muted)">HTTPS obbligatorio (HTTP solo verso localhost). Basta l'indirizzo del sito: il percorso viene completato.</small></div>
        <div class="form-group"><label>Client ID</label><input type="text" name="client_id" value="<?= $h($cfg['wpats.client_id']) ?>" maxlength="64"></div>
        <div class="form-group"><label>Segreto condiviso <?= $secretSet ? $pill('IMPOSTATO', '#16a34a') : $pill('MANCANTE', '#dc2626') ?></label>
          <input type="password" name="secret" value="" placeholder="<?= $secretSet ? '•••••• (vuoto = invariato)' : 'generato nel plugin WordPress' ?>" autocomplete="new-password">
          <small style="color:var(--muted)">Salvato solo in <code>.env.php</code> (PM_WPATS_SECRET), mai nel database.</small>
          <?php if ($secretSet): ?><label style="font-size:12px;display:block;margin-top:4px;font-weight:400"><input type="checkbox" name="secret_clear" value="1" style="width:auto"> rimuovi segreto</label><?php endif; ?></div>
      </div>

      <?= $wpSecHead('fa-network-wired', 'Rete') ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px 14px">
        <div class="form-group"><label>File CA (opzionale)</label><input type="text" name="ca_file" value="<?= $h($cfg['wpats.ca_file']) ?>" placeholder="P:\xampp\apache\bin\curl-ca-bundle.crt">
          <small style="color:var(--muted)">Solo se PHP non trova i certificati («SSL certificate problem»).</small></div>
        <div class="form-group"><label>Proxy in uscita (opzionale)</label><input type="text" name="proxy" value="<?= $h($cfg['wpats.proxy']) ?>" placeholder="proxy.azienda.local:8080"></div>
        <div class="form-group"><label>Timeout (s)</label><input type="number" name="timeout" min="5" max="120" value="<?= (int)$cfg['wpats.timeout'] ?>"></div>
      </div>
      <label style="font-size:13px;display:block"><input type="checkbox" name="verify_tls" value="1" <?= $cfg['wpats.verify_tls'] !== '0' ? 'checked' : '' ?>> Verifica certificato TLS (disattivare solo per prove)</label>

      <?= $wpSecHead('fa-rotate', 'Sincronizzazione') ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px 14px">
        <div class="form-group"><label>Candidature per richiesta</label><input type="number" name="pull_batch" min="1" max="100" value="<?= (int)$cfg['wpats.pull_batch'] ?>"></div>
      </div>
      <label style="font-size:13px;display:block"><input type="checkbox" name="enabled" value="1" <?= $cfg['wpats.enabled'] === '1' ? 'checked' : '' ?>> Sincronizzazione attiva (manuale e pianificata)</label>
      <label style="font-size:13px;display:block"><input type="checkbox" name="push_on_change" value="1" <?= $cfg['wpats.push_on_change'] === '1' ? 'checked' : '' ?>> Invia subito le posizioni a ogni modifica</label>

      <div style="border-top:1px solid #e2e8f0;margin-top:12px;padding-top:12px"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva impostazioni</button></div>
    </form>

    <div>
      <div class="card" style="padding:14px 16px">
        <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-code-branch"></i> Versioni e compatibilità</h3>
        <table class="data-table" style="width:100%;font-size:12px"><tbody>
          <tr><td>PortalManager</td><td><b><?= $h(defined('PM_VERSION') ? PM_VERSION : '?') ?></b></td></tr>
          <tr><td>Plugin richiesto</td><td>≥ <?= $h(WpAtsConfig::PLUGIN_MIN) ?> (minimo <?= $h(WpAtsConfig::PLUGIN_BASE) ?>)</td></tr>
          <tr><td>Protocollo API</td><td>v<?= $h(WpAtsConfig::API_VERSION) ?> (pm-ats/v1)</td></tr>
          <tr><td>Plugin sul sito</td><td><?= $info ? '<b>' . $h($info['plugin'] ?? '?') . '</b> · API v' . $h($info['api'] ?? '1') : '—' ?></td></tr>
          <tr><td>WordPress / PHP</td><td><?= $info ? $h(($info['wordpress'] ?? '') . ' / ' . ($info['php'] ?? '')) : '—' ?></td></tr>
          <tr><td>Sito</td><td><?= $h($info['site'] ?? '—') ?></td></tr>
          <tr><td>Configurazione sito</td><td><?= $h(($info['onboarding'] ?? '') === 'done' ? 'completata' : (($info['onboarding'] ?? '') ?: '—')) ?></td></tr>
          <tr><td>Compatibilità</td><td><?= $info ? $pill(strtoupper($info['compat'] ?? '?'), $cc[$info['compat'] ?? ''] ?? '#64748b') . ' <span style="color:var(--muted)">' . $h($info['compat_msg'] ?? '') . '</span>' : '<span style="color:var(--muted)">eseguire il test</span>' ?></td></tr>
          <tr><td>Ultimo test</td><td><?= $h($info['checked_at'] ?? '—') ?></td></tr>
          <tr><td>Configurazione guidata</td><td><?= $done ? $pill('COMPLETATA', '#16a34a') . ' ' . $h(WpAtsConfig::setting($pdo, 'wpats.setup_at', '')) : $pill('DA COMPLETARE', '#d97706') ?></td></tr>
        </tbody></table>
      </div>
      <div class="card" style="padding:14px 16px;margin-top:14px">
        <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-clock"></i> Pianificazione</h3>
        <pre style="font-size:11px;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:0">schtasks /Create /SC MINUTE /MO 15 /TN "PortalManager - Sito web" /TR "\"<?= $h($php) ?>\" \"<?= $h($cron) ?>\" --quiet" /RU SYSTEM</pre>
        <p style="font-size:11px;color:var(--muted);margin:6px 0 0">Uscita 0 = ok, 1 = errori, 2 = configurazione.</p>
      </div>
    </div>
  </div>
</div>
<?php require_once('footer.php'); ?>
