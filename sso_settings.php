<?php
/**
 * PortalManager — sso_settings.php
 * Sistema → SSO Microsoft 365 / MFA
 * Configurazione Single Sign-On (Entra ID) e abilitazione MFA.
 * I valori sono salvati in .env.php (fuori dal DB). Accesso: Super Admin.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/Microsoft365Sso.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) { http_response_code(403); die('Accesso negato: solo Super Admin.'); }

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    Csrf::verify();   // termina la richiesta se il token non è valido
    {
        $in = fn($k) => trim((string)($_POST[$k] ?? ''));
        $kv = [
            'SSO_MS_ENABLED'    => !empty($_POST['SSO_MS_ENABLED']) ? '1' : '0',
            'MS_TENANT_ID'      => $in('MS_TENANT_ID'),
            'MS_CLIENT_ID'      => $in('MS_CLIENT_ID'),
            'MS_REDIRECT_URI'   => $in('MS_REDIRECT_URI'),
            'MS_ALLOWED_DOMAIN' => $in('MS_ALLOWED_DOMAIN'),
            'MS_REQUIRE_MFA'    => !empty($_POST['MS_REQUIRE_MFA']) ? '1' : '0',
            'MS_ACR_VALUE'      => $in('MS_ACR_VALUE'),
            'MS_AUTO_PROVISION' => '0',
        ];
        // il secret si aggiorna solo se digitato (campo vuoto = mantieni l'attuale)
        if ($in('MS_CLIENT_SECRET') !== '') $kv['MS_CLIENT_SECRET'] = $in('MS_CLIENT_SECRET');

        $err = [];
        if ($kv['SSO_MS_ENABLED'] === '1') {
            foreach (['MS_TENANT_ID','MS_CLIENT_ID','MS_REDIRECT_URI'] as $k) if ($kv[$k] === '') $err[] = "$k obbligatorio";
            if (($kv['MS_CLIENT_SECRET'] ?? Env::get('MS_CLIENT_SECRET', '')) === '') $err[] = 'MS_CLIENT_SECRET obbligatorio';
            if ($kv['MS_REDIRECT_URI'] !== '' && stripos($kv['MS_REDIRECT_URI'], 'https://') !== 0) $err[] = 'Redirect URI deve essere HTTPS';
        }
        if ($err) {
            $msg = "<div class='alert alert-danger'>" . $h(implode(' · ', $err)) . "</div>";
        } elseif (Env::persist($kv)) {
            if (function_exists('write_log')) write_log('Security', 'info', 'Configurazione SSO/MFA aggiornata', $u_id,
                ['sso' => $kv['SSO_MS_ENABLED'], 'mfa' => $kv['MS_REQUIRE_MFA'], 'acr' => $kv['MS_ACR_VALUE'] !== '' ? 1 : 0]);
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Configurazione salvata.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Impossibile scrivere .env.php: verificare i permessi della cartella dell'applicazione.</div>";
        }
    }
}

$diag = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'diag') {
    Csrf::verify();
    $diag = Microsoft365Sso::diagnostics();
    $fails = count(array_filter($diag, fn($d) => $d['status'] === 'fail'));
    if (function_exists('write_log')) write_log('Security', $fails ? 'warning' : 'info', "Diagnostica SSO/MFA: $fails errori", $u_id);
}
$testRes = null;
if (($_GET['t'] ?? '') === 'done' && !empty($_SESSION['ms_sso_test_result'])) {
    $testRes = $_SESSION['ms_sso_test_result'];
    unset($_SESSION['ms_sso_test_result']);
}
if (($_GET['t'] ?? '') === 'noconf') {
    $msg .= "<div class='alert alert-warning'>Configurazione incompleta: compilare e salvare i dati Entra ID prima del test.</div>";
}

$v = fn($k, $d = '') => (string)(Env::get($k, $d) ?? $d);
$secretSet = $v('MS_CLIENT_SECRET') !== '';
$defRedirect = (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://<host>')
             . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/auth_microsoft.php';

require_once('header.php');
?>
<div class="container" style="max-width:900px;margin:0 auto;padding:18px">
  <h1 style="font-size:20px;font-weight:800;margin-bottom:4px"><i class="fa-solid fa-right-to-bracket"></i> SSO Microsoft 365 / MFA</h1>
  <p style="color:var(--muted);font-size:13px;margin-bottom:14px">
    Accesso Single Sign-On con Microsoft Entra ID. I valori sono salvati in <code>.env.php</code> (fuori dal database).
  </p>
  <?= $msg ?>

  <div class="card" style="padding:12px 16px;margin-bottom:14px;display:flex;gap:18px;flex-wrap:wrap;font-size:13px">
    <div>SSO: <?= Microsoft365Sso::enabled() ? "<b style='color:#16a34a'>ATTIVO</b>" : "<b style='color:#94a3b8'>NON ATTIVO</b>" ?></div>
    <div>Configurazione: <?= Microsoft365Sso::isConfigured() ? "<b style='color:#16a34a'>completa</b>" : "<b style='color:#b45309'>incompleta</b>" ?></div>
    <div>MFA: <?= $v('MS_REQUIRE_MFA', '1') === '1' ? "<b style='color:#16a34a'>obbligatoria</b>" : "<b style='color:#b91c1c'>non richiesta</b>" ?></div>
    <div>Authentication Context: <?= $v('MS_ACR_VALUE') !== '' ? "<b>" . $h($v('MS_ACR_VALUE')) . "</b>" : '—' ?></div>
  </div>

  <?php
    $badge = fn($st) => [
        'ok'   => "<span style='background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px'>OK</span>",
        'warn' => "<span style='background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px'>ATTENZIONE</span>",
        'fail' => "<span style='background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px'>ERRORE</span>",
        'skip' => "<span style='background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px'>N/D</span>",
    ][$st] ?? $st;
  ?>
  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-stethoscope"></i> Test configurazione</h3>
    <p style="color:var(--muted);font-size:12px;margin:0 0 10px">Usa la configurazione <b>salvata</b>. Salva prima eventuali modifiche.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="POST" style="margin:0">
        <?= Csrf::field() ?><input type="hidden" name="action" value="diag">
        <button class="btn btn-sm"><i class="fa-solid fa-list-check"></i> 1. Diagnostica configurazione</button>
      </form>
      <a class="btn btn-sm btn-primary" href="<?= $h(url_safe('auth_microsoft', ['action' => 'test'])) ?>">
        <i class="fa-brands fa-microsoft"></i> 2. Test accesso con Microsoft (MFA)</a>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:6px">
      Il test 2 esegue un accesso reale con il tuo account Microsoft (con richiesta MFA) ma <b>non</b> cambia la sessione:
      verifica token, firma, claim MFA e corrispondenza con un utente del portale.
    </div>

    <?php if ($diag !== null): ?>
      <table class="data-table" style="width:100%;font-size:12px;margin-top:12px">
        <thead><tr><th style="width:190px">Controllo</th><th style="width:100px">Esito</th><th>Dettaglio</th></tr></thead>
        <tbody>
        <?php foreach ($diag as $d): ?>
          <tr><td><?= $h($d['check']) ?></td><td><?= $badge($d['status']) ?></td><td><?= $h($d['detail']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php $nf = count(array_filter($diag, fn($d) => $d['status'] === 'fail')); ?>
      <div style="margin-top:8px;font-size:12px;font-weight:700;color:<?= $nf ? '#b91c1c' : '#15803d' ?>">
        <?= $nf ? "$nf controlli in errore: correggere prima di abilitare l'SSO." : 'Configurazione valida: procedere con il test 2 (accesso MFA).' ?>
      </div>
    <?php endif; ?>

    <?php if ($testRes !== null): ?>
      <div style="margin-top:12px;border-top:1px solid var(--border);padding-top:10px">
        <b style="font-size:13px">Esito test accesso Microsoft</b>
        <?php if (!$testRes['ok']): ?>
          <div style="margin-top:6px"><?= $badge('fail') ?> <?= $h($testRes['error']) ?></div>
        <?php else: ?>
          <table class="data-table" style="width:100%;font-size:12px;margin-top:8px">
            <tbody>
              <tr><td style="width:190px">Autenticazione Microsoft</td><td><?= $badge('ok') ?> token valido (firma, audience, issuer, nonce)</td></tr>
              <tr><td>Account</td><td><?= $h($testRes['name']) ?> &lt;<?= $h($testRes['email']) ?>&gt;</td></tr>
              <tr><td>MFA (claim amr)</td><td>
                <?= $testRes['mfa_ok'] ? $badge('ok') . ' MFA eseguita' : $badge(Env::get('MS_REQUIRE_MFA','1')==='1' ? 'fail' : 'warn') . ' MFA NON eseguita' ?>
                <span style="color:var(--muted)"> · amr = [<?= $h(implode(', ', $testRes['amr'])) ?>]</span>
                <?php if (!$testRes['mfa_ok'] && Env::get('MS_REQUIRE_MFA','1')==='1'): ?>
                  <div style="color:#b91c1c;margin-top:3px">Con "Richiedi MFA" attivo questo accesso verrebbe rifiutato: attivare il Conditional Access sull'app o impostare l'Authentication Context.</div>
                <?php endif; ?>
              </td></tr>
              <tr><td>Authentication Context</td><td>
                <?php $acr = Env::get('MS_ACR_VALUE',''); $got = $testRes['acrs'];
                      $gotArr = is_array($got) ? $got : ($got !== null && $got !== '' ? [$got] : []); ?>
                <?php if ($acr === ''): ?><?= $badge('skip') ?> non configurato
                <?php elseif (in_array($acr, $gotArr, true)): ?><?= $badge('ok') ?> '<?= $h($acr) ?>' soddisfatto
                <?php else: ?><?= $badge('warn') ?> '<?= $h($acr) ?>' richiesto ma non presente nel token (verificare il contesto in Entra)
                <?php endif; ?>
              </td></tr>
              <tr><td>Utente del portale</td><td>
                <?php if (!$testRes['user']): ?><?= $badge('fail') ?> nessun utente con questa email (users.email / employees.business_email)
                <?php elseif ($testRes['user']['status'] !== 'active'): ?><?= $badge('warn') ?> utente #<?= (int)$testRes['user']['id'] ?> non attivo
                <?php else: ?><?= $badge('ok') ?> utente #<?= (int)$testRes['user']['id'] ?> (via <?= $h($testRes['user']['via']) ?>)
                <?php endif; ?>
              </td></tr>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <form method="POST" class="card" style="padding:18px" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">

    <h3 style="font-size:14px;margin:0 0 10px">Abilitazione</h3>
    <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:6px">
      <input type="checkbox" name="SSO_MS_ENABLED" value="1" <?= $v('SSO_MS_ENABLED') === '1' ? 'checked' : '' ?>>
      Abilita "Accedi con Microsoft 365" nella pagina di login
    </label>

    <h3 style="font-size:14px;margin:16px 0 10px">Autenticazione a più fattori (MFA)</h3>
    <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:8px">
      <input type="checkbox" name="MS_REQUIRE_MFA" value="1" <?= $v('MS_REQUIRE_MFA', '1') === '1' ? 'checked' : '' ?>>
      Richiedi MFA: rifiuta gli accessi il cui token non riporta la MFA (claim <code>amr</code> = <code>mfa</code>)
    </label>
    <div class="form-group">
      <label>Authentication Context ID (opzionale)</label>
      <input type="text" name="MS_ACR_VALUE" value="<?= $h($v('MS_ACR_VALUE')) ?>" placeholder="es. c1">
      <small style="color:var(--muted)">Id del contesto di autenticazione Entra configurato per richiedere MFA: la MFA viene forzata già al login, anche senza Conditional Access.</small>
    </div>

    <h3 style="font-size:14px;margin:16px 0 10px">Registrazione applicazione Entra ID</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="form-group"><label>Directory (tenant) ID</label>
        <input type="text" name="MS_TENANT_ID" value="<?= $h($v('MS_TENANT_ID')) ?>" placeholder="00000000-0000-0000-0000-000000000000"></div>
      <div class="form-group"><label>Application (client) ID</label>
        <input type="text" name="MS_CLIENT_ID" value="<?= $h($v('MS_CLIENT_ID')) ?>" placeholder="11111111-1111-1111-1111-111111111111"></div>
      <div class="form-group"><label>Client secret <?= $secretSet ? "<span style='color:#16a34a'>(impostato)</span>" : "<span style='color:#b45309'>(non impostato)</span>" ?></label>
        <input type="password" name="MS_CLIENT_SECRET" value="" placeholder="<?= $secretSet ? 'lascia vuoto per mantenere' : 'incolla il valore del secret' ?>" autocomplete="new-password"></div>
      <div class="form-group"><label>Dominio email consentito (opzionale)</label>
        <input type="text" name="MS_ALLOWED_DOMAIN" value="<?= $h($v('MS_ALLOWED_DOMAIN')) ?>" placeholder="wetechs.it"></div>
    </div>
    <div class="form-group"><label>Redirect URI</label>
      <input type="text" name="MS_REDIRECT_URI" value="<?= $h($v('MS_REDIRECT_URI', $defRedirect)) ?>">
      <small style="color:var(--muted)">Da registrare identico in Entra ID → App registration → Authentication → Web.</small>
    </div>

    <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva configurazione</button>
  </form>

  <div class="card" style="padding:14px 16px;margin-top:14px;font-size:12px;color:#334155">
    <b>Setup Entra ID</b>
    <ol style="margin:6px 0 0 18px;line-height:1.7">
      <li>Entra ID → App registrations → New registration.</li>
      <li>Authentication → Web → Redirect URI = valore sopra.</li>
      <li>Certificates &amp; secrets → New client secret → incolla qui.</li>
      <li>MFA: policy di Conditional Access sull'app, oppure Authentication Context + ID sopra.</li>
    </ol>
  </div>
</div>
<?php require_once('footer.php'); ?>
