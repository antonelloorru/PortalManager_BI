<?php
/**
 * PortalManager — perf_center.php  (v1.9.73)
 * Sistema → Prestazioni (Super Admin)
 *  - copie aggiornate delle viste lente (app/PmSnapshot.php): stato, modalità, aggiornamento
 *  - profiler delle query per la propria sessione (tempi reali misurati da MariaDB)
 */
require_once('access_control.php');
require_once __DIR__ . '/app/PmSnapshot.php';
require_once __DIR__ . '/app/CronlessScheduler.php';

$u_id = (int)$_SESSION['user_id'];
if ((int)($_SESSION['role_id'] ?? 0) !== 1) { http_response_code(403); die('Accesso negato: solo Super Admin.'); }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'profiler') {
        $_SESSION['pm_profile'] = empty($_SESSION['pm_profile']) ? 1 : 0;
        $msg = $_SESSION['pm_profile']
            ? "<div class='alert alert-success'>Profiler attivo per la tua sessione: apri Service Desk, Relazione di Servizio IT, Ordinativi Pratix o Report direzionale e scorri in fondo alla pagina.</div>"
            : "<div class='alert alert-info'>Profiler disattivato.</div>";
        write_log('Security', 'info', 'Profiler query ' . ($_SESSION['pm_profile'] ? 'attivato' : 'disattivato'), $u_id);
    } elseif ($a === 'refresh') {
        $d = CronlessScheduler::dispatch($pdo, true, false, 'snapshot');
        $msg = $d['ok'] ? "<div class='alert alert-success'>Aggiornamento delle copie avviato in background: ricarica la pagina tra qualche minuto.</div>"
                        : "<div class='alert alert-danger'>Avvio non riuscito: " . h($d['note']) . "</div>";
        write_log('Projects', $d['ok'] ? 'info' : 'warning', 'Aggiornamento copie viste: ' . $d['note'], $u_id);
    } elseif ($a === 'mode') {
        $v = (string)($_POST['view'] ?? ''); $m = (string)($_POST['mode'] ?? 'auto');
        if (in_array($v, PmSnapshot::CANDIDATES, true) && in_array($m, ['auto', 'sempre', 'mai'], true)) {
            $pdo->prepare("INSERT INTO `pm_snapshot` (`view_name`, `mode`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `mode` = VALUES(`mode`)")->execute([$v, $m]);
            PmSnapshot::refresh($pdo, true, [$v]);          // applica subito, sulla sola vista
            $msg = "<div class='alert alert-success'>Modalità di " . h($v) . " impostata su «" . h($m) . "» e applicata.</div>";
            write_log('Projects', 'info', "Copia vista $v: modalità $m", $u_id);
        }
    }
}

$reg = [];
try { foreach ($pdo->query("SELECT * FROM `pm_snapshot`")->fetchAll(PDO::FETCH_ASSOC) as $r) $reg[$r['view_name']] = $r; } catch (Throwable $e) {}
$attive = count(array_filter($reg, fn($r) => (int)$r['enabled'] === 1));
$prof = !empty($_SESSION['pm_profile']);

require_once('header.php');
?>
<div class="container" style="max-width:1100px;margin:0 auto;padding:18px">
  <h1 style="font-size:20px;font-weight:800;margin-bottom:4px"><i class="fa-solid fa-gauge-high"></i> Prestazioni</h1>
  <p style="color:var(--muted);font-size:13px;margin-bottom:14px">Copie aggiornate delle viste lente e misura dei tempi delle query.</p>
  <?= $msg ?>

  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-stopwatch"></i> Profiler delle query</h3>
    <p style="font-size:12px;color:var(--muted);margin:0 0 8px">Solo per la tua sessione: in fondo a ogni pagina compare l'elenco delle query
      con i tempi misurati dal database (evidenziate in giallo oltre 20 ms, in rosso oltre 100 ms).</p>
    <form method="POST" style="margin:0"><?= Csrf::field() ?><input type="hidden" name="action" value="profiler">
      <button class="btn btn-sm <?= $prof ? '' : 'btn-primary' ?>"><?= $prof ? 'Disattiva profiler' : 'Attiva profiler' ?></button>
      <?php if ($prof): ?><span style="color:#16a34a;font-size:12px;font-weight:700;margin-left:6px">attivo</span><?php endif; ?>
    </form>
  </div>

  <?php
    // v1.9.75 — stato della ripartizione oraria (ordinarie / fuori orario)
    require_once __DIR__ . '/app/PmOrario.php';
    $pmFasce = implode(', ', array_map(fn($x) => substr($x[0], 0, 5) . '–' . substr($x[1], 0, 5), PmOrario::fasce($pdo)));
    $pmCol = function (string $view, array $cols) use ($pdo): string {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                              AND COLUMN_NAME IN ('" . implode("','", $cols) . "') LIMIT 1");
        $st->execute([$view]); return (string)($st->fetchColumn() ?: '');
    };
    $pmIt = $pmCol('v_cm_it_servizio', ['modulo']);
    $pmSd = $pmCol('v_cm_sd_moduli', ['modulo', 'report_code', 'codice_modulo']);
    $pmSt = fn($c) => $c !== '' ? "<b style='color:#16a34a'>ore ripartite</b> (rapportino agganciato tramite <code>" . h($c) . "</code>)"
                                : "<b style='color:#b45309'>fascia del modulo intero</b> (la vista non espone il codice del modulo)";
  ?>
  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-clock"></i> Ripartizione oraria</h3>
    <p style="font-size:12px;color:var(--muted);margin:0 0 6px">Ore ordinarie = sovrapposizione reale con le fasce <b><?= h($pmFasce) ?></b> (lun–ven),
      limitata alle ore dichiarate; il resto è fuori orario. Fasce modificabili in <code>app_settings.pm_orario_fasce</code>.</p>
    <table class="data-table" data-pm-nofilter style="font-size:12px">
      <tr><td>Attività &amp; Rendicontazione DGB</td><td><b style="color:#16a34a">ore ripartite</b> (inizio e fine del modulo DGB)</td></tr>
      <tr><td>Relazione di Servizio IT</td><td><?= $pmSt($pmIt) ?></td></tr>
      <tr><td>Service Desk</td><td><?= $pmSt($pmSd) ?></td></tr>
    </table>
  </div>

  <div class="card" style="padding:14px 16px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <h3 style="font-size:14px;margin:0"><i class="fa-solid fa-bolt"></i> Copie delle viste — <?= $attive ?> attive</h3>
      <form method="POST" style="margin:0"><?= Csrf::field() ?><input type="hidden" name="action" value="refresh">
        <button class="btn btn-sm btn-primary"><i class="fa-solid fa-rotate"></i> Aggiorna ora (in background)</button></form>
    </div>
    <p style="font-size:12px;color:var(--muted);margin:6px 0 10px">
      Modalità <b>auto</b>: la copia si attiva se il calcolo della vista supera <?= PmSnapshot::SLOW_MS ?> ms e si disattiva sotto <?= PmSnapshot::FAST_MS ?> ms.
      Aggiornamento: dopo ogni sincronizzazione e ogni <?= (int)($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='pm_snapshot_refresh_min'")->fetchColumn() ?: PmSnapshot::REFRESH_MIN) ?> minuti;
      oltre <?= PmSnapshot::MAX_AGE_H ?> ore le pagine tornano alla vista originale.</p>
    <table class="data-table" data-pm-nofilter style="width:100%;font-size:12px">
      <thead><tr><th>Vista</th><th>Modalità</th><th>Copia</th><th style="text-align:right">Calcolo vista</th><th style="text-align:right">Righe</th><th>Aggiornata</th><th>Nota</th></tr></thead>
      <tbody>
      <?php foreach (PmSnapshot::CANDIDATES as $v): $r = $reg[$v] ?? null; ?>
        <tr<?= ($r['status'] ?? '') === 'errore' ? ' style="background:#fee2e2"' : '' ?>>
          <td><code><?= h($v) ?></code></td>
          <td><form method="POST" style="margin:0"><?= Csrf::field() ?><input type="hidden" name="action" value="mode"><input type="hidden" name="view" value="<?= h($v) ?>">
            <select name="mode" onchange="this.form.submit()" data-pm-ms="off" style="font-size:12px">
              <?php foreach (['auto', 'sempre', 'mai'] as $m): ?><option value="<?= $m ?>"<?= ($r['mode'] ?? 'auto') === $m ? ' selected' : '' ?>><?= $m ?></option><?php endforeach; ?>
            </select></form></td>
          <td><?= !$r ? '<span style="color:#94a3b8">non misurata</span>'
                 : ((int)$r['enabled'] === 1 ? '<b style="color:#16a34a">attiva</b>' : '<span style="color:#64748b">no</span>') ?></td>
          <td style="text-align:right;<?= (int)($r['build_ms'] ?? 0) >= 1000 ? 'color:#dc2626;font-weight:700' : '' ?>"><?= $r ? number_format((int)$r['build_ms'], 0, ',', '.') . ' ms' : '—' ?></td>
          <td style="text-align:right"><?= $r && $r['rows_count'] !== null ? number_format((int)$r['rows_count'], 0, ',', '.') : '—' ?></td>
          <td><?= !empty($r['refreshed_at']) ? date('d/m H:i', strtotime($r['refreshed_at'])) : '—' ?></td>
          <td style="font-size:11px;color:var(--muted)"><?= h($r['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once('footer.php'); ?>
