<?php
/**
 * PortalManager — wp_ats_sync.php (v1.10.14)
 * Recruiting → Sito web (WordPress): sincronizzazione con il plugin pm-ats del sito pubblico.
 *   - stato, test di connessione, invio posizioni, prelievo candidature, registro;
 *   - configurazione (solo Super Admin): v1.10.14 spostata in wp_ats_settings.php (impostazioni) e wp_ats_setup.php (guidata).
 * v1.10.18 — pubblicazione puntuale per posizione (Pubblicata / Bozza sul sito / Non pubblicare, job_positions.web_status) con invio
 *           immediato della sola posizione, e anteprima della scheda annuncio (dal plugin ≥ 1.2.0, altrimenti anteprima locale).
 * Permessi: view = consultazione e anteprima, edit = avvio sincronizzazioni e stato di pubblicazione.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/WpAtsSync.php';
require_once __DIR__ . '/app/WpAtsConfig.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$isSA   = $u_role === 1;
$canRun = can('edit', 'wp_ats_sync.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

const WPATS_WEB = ['publish' => ['Pubblicata', '#16a34a'], 'draft' => ['Bozza sul sito', '#d97706'], 'off' => ['Non pubblicare', '#64748b']];

// ── v1.10.18 — anteprima della scheda annuncio (GET ?preview=<id posizione>) ──
if (isset($_GET['preview'])) {
    require_once __DIR__ . '/app/WpAtsPreview.php';
    $pv = (int)$_GET['preview'];
    $sync = new WpAtsSync($pdo);
    $item = $sync->positionItem($pv);
    if (!$item) { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Posizione non trovata.</div>"; redirect('wp_ats_sync'); }
    $r = $sync->configured() ? $sync->preview($pv) : ['ok' => false, 'message' => 'connessione al sito non configurata'];
    write_log('Recruiting', $r['ok'] ? 'info' : 'warning', 'Anteprima annuncio POS-' . $pv . ($r['ok'] ? ' (sito)' : ' (locale: ' . mb_substr($r['message'], 0, 200) . ')'), $u_id);
    $base = (string)parse_url((string)WpAtsClient::normalizeBase(WpAtsClient::settings($pdo)['wpats.base_url']), PHP_URL_HOST);
    // solo verso il sito configurato: nessun redirect aperto
    if ($r['ok'] && preg_match('~^https?://~i', $r['url']) && strcasecmp((string)parse_url($r['url'], PHP_URL_HOST), $base) === 0) {
        header('Cache-Control: no-store');
        header('Location: ' . $r['url'], true, 302);
        exit;
    }
    $pubSt = $pdo->prepare("SELECT status FROM position_publications WHERE position_id = ? AND channel = 'wordpress' ORDER BY id DESC LIMIT 1");
    $pubSt->execute([$pv]);
    $state = ['published' => 'pubblicata', 'draft' => 'bozza', 'removed' => 'ritirata'][(string)$pubSt->fetchColumn()] ?? 'mai pubblicata';
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    echo WpAtsPreview::html($item, $state . ' · impostazione PortalManager: ' . WPATS_WEB[$item['web_status'] ?? 'publish'][0], $r['ok'] ? '' : $r['message']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $act = (string)($_POST['action'] ?? '');
    // ── v1.10.18 — stato di pubblicazione della singola posizione + invio immediato della sola posizione ──
    if ($act === 'web_status' && $canRun) {
        $pid = (int)($_POST['pos_id'] ?? 0);
        $ws = (string)($_POST['web_status'] ?? '');
        $back = ($_POST['back'] ?? '') === 'pos' ? fn() => redirect('publish_posizione', ['pos_id' => $pid]) : fn() => redirect_self();
        if ($pid <= 0 || !isset(WPATS_WEB[$ws])) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Richiesta non valida.</div>"; $back(); }
        $pdo->prepare("UPDATE job_positions SET web_status = ?, web_status_at = NOW(), web_status_by = ? WHERE id = ?")->execute([$ws, $u_id, $pid]);
        $code = 'POS-' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT);
        $sync = new WpAtsSync($pdo);
        if ($sync->configured()) {
            @set_time_limit(120);
            $r = $sync->pushOne($pid, $u_id);
            write_log('Recruiting', $r['ok'] ? 'success' : 'warning', "Sito web: $code → " . WPATS_WEB[$ws][0] . ' — ' . mb_substr($r['message'], 0, 300), $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-" . ($r['ok'] ? 'success' : 'warning') . "'>" . $h($code . ' · ' . WPATS_WEB[$ws][0] . ': ' . $r['message']) . "</div>";
        } else {
            write_log('Recruiting', 'info', "Sito web: $code → " . WPATS_WEB[$ws][0] . ' (applicato al prossimo invio)', $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-info'>" . $h("$code: " . WPATS_WEB[$ws][0] . ' — salvato; sarà applicato al prossimo invio (connessione non configurata).') . "</div>";
        }
        $back();
    }
    if (in_array($act, ['test', 'push', 'pull', 'all'], true) && $canRun) {
        @set_time_limit(300);
        $sync = new WpAtsSync($pdo);
        $r = match ($act) {
            'test' => WpAtsConfig::test($pdo, $u_id),   // v1.10.16 — in caso di errore esegue la diagnostica
            'push' => $sync->pushJobs($u_id),
            'pull' => $sync->pullApplications($u_id),
            'all'  => $sync->run($u_id, 'manuale'),
        };
        write_log('Recruiting', $r['ok'] ? 'success' : 'warning', 'Sito WordPress — ' . $act . ': ' . mb_substr($r['message'], 0, 400), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-" . ($r['ok'] ? 'success' : 'warning') . "'>" . $h($r['message'])
            . (!$r['ok'] && $isSA ? " — <a href='" . url_safe('wp_ats_settings') . "#wpats-diag'>diagnostica dell'handshake</a>" : '') . "</div>";
        redirect_self();
    }
}

$cfg = WpAtsClient::settings($pdo);
$secretSet = WpAtsClient::secret() !== '';
$complete = WpAtsClient::fromSettings($pdo) !== null;
$setupDone = WpAtsConfig::setupDone($pdo);
$rinfo = WpAtsConfig::remoteInfo($pdo);
$last = fn(string $op) => $pdo->query("SELECT * FROM wp_ats_sync_log WHERE operation = '$op' AND status <> 'running' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
$lp = $last('push'); $lq = $last('pull'); $lt = $last('test');
$log = $pdo->query("SELECT l.*, COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) AS utente
                      FROM wp_ats_sync_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
// v1.10.18 — posizioni in bozza, aperte o in pausa (anche non ancora inviabili, per l'anteprima) + stato sul sito
$pubs = $pdo->query("SELECT jp.id, jp.title, jp.location, jp.contract_type, jp.status AS pm_status, COALESCE(jp.web_status, 'publish') AS web_status,
                            (v.id IS NOT NULL) AS inviabile, pp.channel_url, pp.status AS pub_status, pp.published_at
                       FROM job_positions jp
                       LEFT JOIN v_public_open_positions v ON v.id = jp.id
                       LEFT JOIN position_publications pp ON pp.id = (SELECT MAX(x.id) FROM position_publications x WHERE x.position_id = jp.id AND x.channel = 'wordpress')
                      WHERE jp.status IN ('draft','open','paused') OR pp.status IN ('published','draft')
                      ORDER BY (v.id IS NOT NULL) DESC, jp.opened_at DESC, jp.id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
  <?php if (!$setupDone): ?>
    <div class="alert alert-info" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px">
      <span><i class="fa-solid fa-wand-magic-sparkles"></i> Connessione con il sito non ancora configurata.<?= $isSA ? '' : ' Rivolgersi al Super Admin.' ?></span>
      <?php if ($isSA): ?><a class="btn btn-primary btn-sm" href="<?= url_safe('wp_ats_setup') ?>">Avvia la configurazione guidata</a><?php endif; ?></div>
  <?php elseif (($rinfo['compat'] ?? '') !== '' && $rinfo['compat'] !== 'ok'): ?>
    <div class="alert alert-warning" style="margin-top:10px"><?= $h($rinfo['compat_msg'] ?? '') ?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:14px 0">
    <?php foreach ([
      ['Stato', ($cfg['wpats.enabled'] === '1' ? $pill('ATTIVA', '#16a34a') : $pill('NON ATTIVA', '#64748b')) . ' ' . ($complete ? '' : $pill('CONFIG. INCOMPLETA', '#d97706')), ''],
      ['Posizioni aperte', (string)count(array_filter($pubs, fn($p) => $p['inviabile'])), count(array_filter($pubs, fn($p) => $p['pub_status'] === 'published')) . ' pubblicate · ' . count(array_filter($pubs, fn($p) => $p['pub_status'] === 'draft')) . ' in bozza sul sito'],
      ['Ultimo invio posizioni', $dt($lp['finished_at'] ?? null), $lp ? strip_tags($stPill($lp['status'])) : ''],
      ['Ultimo prelievo', $dt($lq['finished_at'] ?? null), $lq ? strip_tags($stPill($lq['status'])) : ''],
      ['Candidature importate', (string)$nImp, 'dal sito web'],
    ] as [$l, $v, $s]): ?>
      <div class="card" style="padding:12px 14px"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700"><?= $h($l) ?></div>
        <div style="font-size:18px;font-weight:800;margin-top:4px"><?= $v ?></div><div style="font-size:11px;color:var(--muted)"><?= $h($s) ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="card" style="padding:14px 16px;overflow-x:auto;margin-bottom:14px">
      <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-briefcase"></i> Pubblicazione per posizione</h3>
      <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Sono inviabili le posizioni in stato <b>aperta</b>, già avviate e non scadute. Per ciascuna:
        <b>Pubblicata</b> = visibile sul sito · <b>Bozza sul sito</b> = inviata ma non visibile (solo anteprima) · <b>Non pubblicare</b> = ritirata o mai inviata.
        «Applica» invia subito la sola posizione. <b>Anteprima</b> mostra la scheda annuncio come sul sito, anche prima della pubblicazione.</p>
      <table class="data-table" data-pm-nofilter style="width:100%;font-size:12px">
        <thead><tr><th>Posizione</th><th>Sede</th><th>Contratto</th><th>Stato PM</th><th>Sul sito</th><th>Pubblicazione</th><th>Anteprima</th></tr></thead><tbody>
        <?php if (!$pubs): ?><tr><td colspan="7" style="color:var(--muted)">Nessuna posizione in bozza, aperta o in pausa.</td></tr><?php endif; ?>
        <?php foreach ($pubs as $p): $ws = $p['web_status']; ?>
          <tr><td><a href="<?= url_safe('publish_posizione', ['pos_id' => $p['id']]) ?>"><?= $h($p['title']) ?></a><br><small style="color:var(--muted)">POS-<?= str_pad((string)$p['id'], 4, '0', STR_PAD_LEFT) ?></small></td>
            <td><?= $h($p['location']) ?></td><td><?= $h($p['contract_type']) ?></td>
            <td><?= $h(['draft' => 'Bozza', 'open' => 'Aperta', 'paused' => 'In pausa', 'closed' => 'Chiusa', 'cancelled' => 'Annullata'][$p['pm_status']] ?? $p['pm_status']) ?><?= $p['inviabile'] ? '' : '<br><small style="color:var(--muted)">non inviabile</small>' ?></td>
            <td><?= match ($p['pub_status']) {
                    'published' => ($p['channel_url'] ? "<a href='" . $h($p['channel_url']) . "' target='_blank' rel='noopener'>" . $pill('PUBBLICATA', '#16a34a') . " <i class='fa-solid fa-arrow-up-right-from-square'></i></a>" : $pill('PUBBLICATA', '#16a34a')),
                    'draft' => $pill('BOZZA', '#d97706'), 'removed' => $pill('RITIRATA', '#64748b'),
                    default => $p['inviabile'] && $ws !== 'off' ? $pill('DA INVIARE', '#2563eb') : '—' } ?></td>
            <td><?php if ($canRun): ?><form method="POST" style="margin:0;display:flex;gap:4px;align-items:center"><?= Csrf::field() ?><input type="hidden" name="action" value="web_status"><input type="hidden" name="pos_id" value="<?= (int)$p['id'] ?>">
                <select name="web_status" style="font-size:12px;padding:2px 4px;width:auto"><?php foreach (WPATS_WEB as $k => [$l]): ?><option value="<?= $k ?>" <?= $ws === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm" title="Salva e invia subito la sola posizione">Applica</button></form>
              <?php else: ?><?= $pill(strtoupper(WPATS_WEB[$ws][0]), WPATS_WEB[$ws][1]) ?><?php endif; ?></td>
            <td><a class="btn btn-sm" target="_blank" rel="noopener" href="<?= url_safe('wp_ats_sync', ['preview' => (int)$p['id']]) ?>"><i class="fa-solid fa-eye"></i> Anteprima</a></td></tr>
        <?php endforeach; ?>
        </tbody></table>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(520px,1fr));gap:14px">

    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-user-plus"></i> Ultime candidature dal sito</h3>
      <table class="data-table" style="width:100%;font-size:12px">
        <thead><tr><th>Importata</th><th>Candidato</th><th>Posizione</th><th>Rif.</th></tr></thead><tbody>
        <?php if (!$imps): ?><tr><td colspan="4" style="color:var(--muted)">Nessuna candidatura importata.</td></tr><?php endif; ?>
        <?php foreach ($imps as $i): ?>
          <tr><td><?= $dt($i['imported_at']) ?></td>
            <td><?php if ($i['candidate_id']): ?><a href="<?= url_safe('candidato_profilo', ['id' => $i['candidate_id']]) ?>"><?= $h(trim($i['first_name'] . ' ' . $i['last_name'])) ?></a><br><small style="color:var(--muted)"><?= $h($i['email']) ?></small><?php else: ?>—<?php endif; ?></td>
            <td><?= $i['position_id'] ? $h($i['title']) : '<i>spontanea</i>' ?></td><td><code><?= $h(strtoupper(substr($i['wp_uuid'], 0, 8))) ?></code></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
  </div>


  <?php if ($isSA): ?>
  <div class="card" style="padding:14px 16px;margin-top:14px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div><h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-gear"></i> Configurazione <span style="font-weight:400;color:var(--muted);font-size:12px">— solo Super Admin</span></h3>
      <div style="font-size:12px;color:var(--muted)"><?= $h($cfg['wpats.base_url'] ?: 'URL non impostato') ?> · client <code><?= $h($cfg['wpats.client_id']) ?></code> · segreto <?= $secretSet ? $pill('IMPOSTATO', '#16a34a') : $pill('MANCANTE', '#dc2626') ?>
        <?= isset($rinfo['plugin']) ? ' · plugin ' . $h($rinfo['plugin']) . ' (API v' . $h($rinfo['api'] ?? '1') . ')' : '' ?></div></div>
    <div style="display:flex;gap:6px"><a class="btn btn-sm" href="<?= url_safe('wp_ats_settings') ?>"><i class="fa-solid fa-sliders"></i> Impostazioni</a>
      <a class="btn btn-sm" href="<?= url_safe('wp_ats_setup') ?>"><i class="fa-solid fa-wand-magic-sparkles"></i> Configurazione guidata</a></div>
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
