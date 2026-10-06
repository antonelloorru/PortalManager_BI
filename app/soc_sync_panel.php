<?php
/**
 * app/soc_sync_panel.php — v1.10.07 (v1.10.08: credenziali ereditate dalla Connessione al gestionale)
 * Sincronizzazione gestionale › SOC: pipeline unica del Service SOC (cartella di arrivo + DB SOC), configurazione,
 * Unità Organizzativa SOC, abbinamenti, registro. Incluso da sync_commesse.php (header già emesso).
 * Variabili attese: $pdo, $can_run.
 */
declare(strict_types=1);

require_once __DIR__ . '/SocSync.php';
require_once __DIR__ . '/SocModel.php';
require_once __DIR__ . '/SourceDb.php';

$isSA  = (int)($_SESSION['role_id'] ?? 99) === 1;
$soc   = new SocModel($pdo);
$arch  = $soc->archivio();
$S     = fn(string $k, string $d = '') => SocSync::setting($pdo, $k, $d);
$dbCfg = $pdo->query("SELECT * FROM cm_soc_source_db ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
$pend  = SocSync::pending($pdo);
$runs  = SocSync::runs($pdo, 15);
$batches = $soc->batches(12);
$techs = SocSync::serviceTechnicians($pdo);
$uoId  = SocSync::unitId($pdo);
$uoMembers = $pdo->query("SELECT e.id, CONCAT_WS(' ', e.last_name, e.first_name) n FROM cm_tech_profiles tp JOIN employees e ON e.id = tp.employee_id
                           WHERE tp.unit_id = $uoId AND tp.is_active = 1 ORDER BY e.last_name, e.first_name")->fetchAll(PDO::FETCH_ASSOC);
$people = $soc->people(); $cmap = $soc->clientMap();
$emps = $pdo->query("SELECT id, CONCAT_WS(' ', last_name, first_name) n, status FROM employees ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$preview = !empty($_GET['pv']) ? ($_SESSION['soc_preview'] ?? null) : null; unset($_SESSION['soc_preview']);
$drivers = SourceDb::availableDrivers();
$gest = null; try { $gest = $pdo->query("SELECT driver, host, port, username, dbname, password_enc FROM cm_source_db WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
$sched = $pdo->query("SELECT exec_mode, is_enabled, run_at, last_tick_at FROM cm_sync_schedule WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$enabled = $S('soc.sync_enabled', '0') === '1'; $interval = (int)$S('soc.interval_min', '60');
$lastAt = $S('soc.last_run_at'); $dbNow = strtotime((string)$pdo->query("SELECT NOW()")->fetchColumn());
$nextAt = $enabled ? (strtotime($lastAt ?: '1970-01-01') ?: 0) + $interval * 60 : null;      // orologio del database, come il registro

$n  = fn($v) => number_format((float)$v, 0, ',', '.');
$dt = fn($v) => $v ? date('d/m/Y H:i', is_numeric($v) ? (int)$v : strtotime((string)$v)) : '—';
$dd = fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$pill = fn($txt, $c) => "<span style='display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:700;background:{$c}1a;color:$c;white-space:nowrap'>" . h((string)$txt) . "</span>";
$stPill = fn(?string $s) => match ($s) { 'ok' => $pill('OK', '#16a34a'), 'parziale', 'warn' => $pill('PARZIALE', '#d97706'), 'errore', 'error' => $pill('ERRORE', '#dc2626'), 'running' => $pill('IN CORSO', '#2563eb'), default => '—' };
$php = PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '\\php.exe' : PHP_BINARY;
$root = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
?>
<div class="card" style="margin-bottom:14px;padding:14px 16px">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap">
    <div>
      <h3 style="font-size:15px;margin:0"><i class="fa-solid fa-shield-halved"></i> Service SOC — pipeline di sincronizzazione</h3>
      <p style="font-size:12px;color:var(--muted);margin:4px 0 0">Un unico processo: <b>cartella di arrivo</b> (export XLSX/CSV) → <b>DB SOC</b> → ricostruzione ticket →
        abbinamenti → <b>Unità Organizzativa SOC</b>. Automatico ogni <?=$n($interval)?> minuti con lo scheduler del portale e in coda alla sincronizzazione giornaliera.
        <a href="<?=url_safe('service_soc')?>">Apri Service SOC →</a></p>
    </div>
    <?php if ($can_run): ?><form method="POST" style="margin:0"><?= Csrf::field() ?><input type="hidden" name="action" value="soc_run">
      <button class="btn btn-primary btn-sm"><i class="fa-solid fa-play"></i> Esegui ora</button></form><?php endif; ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:12px">
    <?php foreach ([
      ['Stato', $enabled ? $pill('AUTOMATICA', '#16a34a') : $pill('SOLO MANUALE', '#64748b'), $enabled ? 'ogni ' . $n($interval) . ' min · scheduler ' . h($sched['exec_mode'] ?? 'cronless') : 'attivare nelle impostazioni'],
      ['Ultima esecuzione', $dt($lastAt) . ' ' . $stPill($S('soc.last_status')), h(mb_strimwidth($S('soc.last_note'), 0, 90, '…'))],
      ['Prossima', $nextAt ? ($nextAt <= $dbNow ? 'appena possibile' : $dt($nextAt)) : '—', $enabled ? 'alla prima attività sul portale dopo questo orario' : ''],
      ['Cartella di arrivo', $n(count($pend)) . ' file in attesa', h(str_replace($root . '/', '', SocSync::inbox($pdo)))],
      ['DB SOC', $dbCfg ? ($dbCfg['is_active'] ? $pill('ATTIVO', '#16a34a') : $pill('DISATTIVO', '#64748b')) : $pill('NON CONFIGURATO', '#d97706'), $dbCfg ? h((!empty($dbCfg['use_gestionale']) ? ($gest ? $gest['host'] . ' (gestionale)' : 'gestionale non configurato') : $dbCfg['host']) . '/' . $dbCfg['dbname'] . ' · ' . $dbCfg['window_days'] . ' gg') : ''],
      ['Archivio', $n($arch['ticket']) . ' ticket', $n($arch['eventi']) . ' eventi · ' . $dd($arch['dal']) . ' – ' . $dd($arch['al'])],
    ] as [$l, $v, $s]): ?>
      <div style="background:#f8fafc;border-radius:8px;padding:10px"><div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase"><?=h($l)?></div>
        <div style="font-size:14px;font-weight:700;margin-top:2px"><?=$v?></div><div style="font-size:11px;color:var(--muted)"><?=$s?></div></div>
    <?php endforeach; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px;margin-bottom:14px">
  <div class="card" style="padding:14px 16px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-file-excel"></i> Import da file</h3>
    <p style="font-size:12px;color:var(--muted);margin:0 0 8px">Export «lista eventi ticket» del sistema SOC (XLSX o CSV). Il file entra nella cartella di arrivo e la pipeline
      lo elabora subito; dopo l'elaborazione è spostato in <code>archivio</code> (o <code>scartati</code>). Un processo esterno può depositare gli export
      nella stessa cartella: saranno acquisiti alla prossima esecuzione automatica. Reimportare lo stesso file non crea doppioni.</p>
    <?php if ($can_run): ?>
      <p style="font-size:11px;color:var(--muted);margin:0 0 6px">Limite di caricamento del server: <?=h(ini_get('upload_max_filesize'))?>.</p>
      <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?= Csrf::field() ?><input type="hidden" name="action" value="soc_upload">
        <input type="file" name="file" accept=".xlsx,.csv" required style="flex:1;min-width:220px">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-upload"></i> Carica e sincronizza</button>
      </form>
    <?php endif; ?>
    <?php if ($pend): ?><p style="font-size:12px;margin:8px 0 0">In attesa: <?=h(implode(', ', array_map('basename', $pend)))?></p><?php endif; ?>
  </div>

  <div class="card" style="padding:14px 16px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-database"></i> Sincronizzazione dal DB SOC</h3>
    <p style="font-size:12px;color:var(--muted);margin:0 0 8px">Istanza separata con lo stesso schema del gestionale, utenza di sola lettura, password cifrata con APP_SECRET.
      Ogni esecuzione della pipeline legge gli ultimi <?=$dbCfg ? $n($dbCfg['window_days']) : '30'?> giorni (0 = tutto).
      <?php if ($dbCfg): ?>Ultima lettura: <?=$dt($dbCfg['last_sync_at'])?> — <?=h((string)$dbCfg['last_sync_note'])?><?php endif; ?></p>
    <?php if ($isSA): $c = $dbCfg ?: ['label' => 'Gestionale SOC', 'driver' => 'mysql', 'host' => '', 'port' => 3306, 'dbname' => '', 'username' => '', 'source_schema' => '', 'timeout' => 10, 'window_days' => 30, 'ticket_prefix' => 'WES_', 'extract_sql' => '', 'is_active' => 1, 'password_enc' => '', 'use_gestionale' => $gest ? 1 : 0]; $useG = !empty($c['use_gestionale']); ?>
    <details <?= $dbCfg ? '' : 'open' ?>><summary style="cursor:pointer;font-size:13px;font-weight:700">Connessione (Super Admin)</summary>
      <form method="POST" autocomplete="off" style="margin-top:8px">
        <?= Csrf::field() ?>
        <label style="font-size:13px;display:block;margin-bottom:6px"><input type="checkbox" name="use_gestionale" value="1" id="socUseG" <?=$useG ? 'checked' : ''?> <?=$gest ? '' : 'disabled'?>>
          Usa server e credenziali della <b>Connessione al gestionale</b> (cambia solo il database)</label>
        <p style="font-size:12px;margin:0 0 8px;color:var(--muted)"><?php if ($gest): ?>Gestionale: <code><?=h($gest['username'] . '@' . $gest['host'] . ':' . $gest['port'])?></code> (<?=h(SourceDb::DRIVERS[$gest['driver']]['label'] ?? $gest['driver'])?>, database <code><?=h($gest['dbname'])?></code>, password <?=$gest['password_enc'] ? 'impostata' : 'assente'?>) — stessa logica di connessione (SourceDb, sola lettura, password cifrata con APP_SECRET).
          <?php else: ?>Connessione al gestionale non configurata: indicare server e credenziali propri.<?php endif; ?></p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px 10px">
          <div class="form-group"><label>Nome</label><input name="label" value="<?=h($c['label'])?>"></div>
          <div class="form-group"><label>Driver</label><select name="driver" data-own><?php foreach (SourceDb::DRIVERS as $k => $d): ?><option value="<?=$k?>" <?=$c['driver'] === $k ? 'selected' : ''?> <?=isset($drivers[$k]) ? '' : 'disabled'?>><?=h($d['label'])?><?=isset($drivers[$k]) ? '' : ' (non disponibile)'?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Host</label><input name="host" data-own value="<?=h($c['host'])?>" placeholder="10.100.7.65"></div>
          <div class="form-group"><label>Porta</label><input type="number" name="port" data-own value="<?=(int)$c['port']?>"></div>
          <div class="form-group"><label>Database</label><input name="dbname" value="<?=h($c['dbname'])?>"></div>
          <div class="form-group"><label>Utente (sola lettura)</label><input name="username" data-own value="<?=h($c['username'])?>"></div>
          <div class="form-group"><label>Password</label><input type="password" name="password" data-own placeholder="<?=$c['password_enc'] ? '•••••• (vuoto = invariata)' : ''?>" autocomplete="new-password"></div>
          <div class="form-group"><label>Schema (facoltativo)</label><input name="source_schema" value="<?=h((string)$c['source_schema'])?>"></div>
          <div class="form-group"><label>Timeout (s)</label><input type="number" name="timeout" min="3" max="60" value="<?=(int)$c['timeout']?>"></div>
          <div class="form-group"><label>Finestra (giorni, 0 = tutto)</label><input type="number" name="window_days" min="0" value="<?=(int)$c['window_days']?>"></div>
          <div class="form-group"><label>Prefisso ticket</label><input name="ticket_prefix" value="<?=h((string)$c['ticket_prefix'])?>" placeholder="WES_"></div>
        </div>
        <div class="form-group"><label>Query di estrazione (vuota = predefinita: messaggi tt_article + categoria da tt_ticket.id_tt_category → tt_category, se presenti)</label>
          <textarea name="extract_sql" rows="6" style="font-family:monospace;font-size:11px" placeholder="<?=h(SocIngest::DEFAULT_SQL)?>"><?=h((string)$c['extract_sql'])?></textarea>
          <small style="color:var(--muted)">Solo SELECT, colonne con i nomi delle intestazioni dell'export (alias). Il primo <code>?</code> riceve la data minima della finestra, il secondo il prefisso ticket (LIKE).</small></div>
        <label style="font-size:13px"><input type="checkbox" name="is_active" value="1" <?=$c['is_active'] ? 'checked' : ''?>> Connessione attiva (usata dalla pipeline)</label>
        <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
          <button class="btn btn-primary btn-sm" name="action" value="soc_db_save"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
          <button class="btn btn-sm" name="action" value="soc_db_test"><i class="fa-solid fa-plug"></i> Test connessione</button>
          <button class="btn btn-sm" name="action" value="soc_db_preview"><i class="fa-solid fa-eye"></i> Anteprima</button>
        </div>
      </form>
      <script>(function(){var c=document.getElementById('socUseG');if(!c)return;var f=function(){document.querySelectorAll('[data-own]').forEach(function(e){e.readOnly=c.checked;e.style.opacity=c.checked?.45:1;if(e.tagName==='SELECT')e.style.pointerEvents=c.checked?'none':'';});};c.addEventListener('change',f);f();})();</script>
    </details>
    <?php elseif (!$dbCfg): ?><p style="font-size:12px">La connessione al DB SOC è configurata dal Super Admin.</p><?php endif; ?>
    <?php if ($preview): ?>
      <div style="overflow-x:auto;margin-top:10px"><table class="data-table" style="width:100%;font-size:11px"><thead><tr><th>Data</th><th>Ticket</th><th>Tipo</th><th>Stato</th><th>Autore</th><th>Titolo</th></tr></thead><tbody>
        <?php foreach ($preview['rows'] as $r): ?><tr><td><?=h($r['event_at'] ?? '')?></td><td><?=h($r['ticket_code'] ?? '')?></td><td><?=h($r['event_kind'] ?? 'scartata')?></td>
          <td><?=h(($r['status_before'] ?? '') . ' → ' . ($r['status_after'] ?? ''))?></td><td><?=h($r['author_name'] ?? '')?></td><td><?=h(mb_strimwidth((string)($r['subject'] ?? ''), 0, 70, '…'))?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<?php if ($can_run): ?>
<div class="card" style="padding:14px 16px;margin-bottom:14px">
  <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-sliders"></i> Pianificazione e regole</h3>
  <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:6px 12px;align-items:end">
    <?= Csrf::field() ?><input type="hidden" name="action" value="soc_settings">
    <label style="font-size:13px"><input type="checkbox" name="sync_enabled" value="1" <?=$enabled ? 'checked' : ''?>> Esecuzione automatica</label>
    <div class="form-group"><label>Ogni (minuti)</label><input type="number" name="interval" min="5" max="1440" value="<?=$interval?>"></div>
    <div class="form-group"><label>Risposta al cliente entro (ore)</label><input type="number" name="sla" min="1" max="240" value="<?=h($S('soc.sla_risposta_ore', '4'))?>"></div>
    <div class="form-group"><label>Da presidiare dopo (ore)</label><input type="number" name="presidio" min="1" max="720" value="<?=h($S('soc.presidio_ore', '24'))?>"></div>
    <div class="form-group"><label>Stati di chiusura</label><input name="closed" value="<?=h($S('soc.closed_states', 'CHIUSO,CHIUSO DAL CLIENTE'))?>"></div>
    <label style="font-size:13px"><input type="checkbox" name="uo_auto" value="1" <?=$S('soc.uo_auto', '1') === '1' ? 'checked' : ''?>> Assegna i tecnici all'unità SOC</label>
    <?php if ($isSA): ?><div class="form-group"><label>Cartella di arrivo</label><input name="inbox" value="<?=h($S('soc.inbox_dir', 'uploads/soc_inbox'))?>"></div><?php endif; ?>
    <div><button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva</button></div>
  </form>
  <p style="font-size:11px;color:var(--muted);margin:10px 0 4px">Inneschi: scheduler del portale (<?=h(($sched['exec_mode'] ?? 'cronless') === 'cronless' ? 'senza cron, attivo' : 'esterno: usare il comando')?>),
    sincronizzazione giornaliera del gestionale (<?=!empty($sched['is_enabled']) ? 'alle ' . h(substr((string)$sched['run_at'], 0, 5)) : 'disattivata'?>), comando pianificato facoltativo:</p>
  <pre style="font-size:11px;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow-x:auto;margin:0">schtasks /Create /SC MINUTE /MO <?=max(5, min(60, $interval))?> /TN "PortalManager - Service SOC" /TR "\"<?=h($php)?>\" \"<?=h($root . DIRECTORY_SEPARATOR . 'cron_soc_sync.php')?>\" --quiet" /RU SYSTEM</pre>
</div>
<?php endif; ?>

<div class="card" style="padding:14px 16px;margin-bottom:14px;overflow-x:auto">
  <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-sitemap"></i> Unità Organizzativa SOC</h3>
  <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Tecnici che erogano il servizio negli ultimi 12 mesi (responsabili o incaricati di ticket, autori di messaggi o note),
    abbinati a un dipendente. Ad ogni esecuzione la pipeline li assegna all'unità <b><?=h($S('soc.uo_code', 'SOC'))?></b> se non hanno scheda tecnica o non hanno unità;
    chi appartiene a un'altra unità resta invariato finché non viene spostato. Componenti dell'unità: <?=$n(count($uoMembers))?>
    (<?=h(implode(', ', array_column($uoMembers, 'n')))?>).</p>
  <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Dipendente</th><th>Nome nel sistema SOC</th><th>Unità attuale</th><th>Esito</th><th></th></tr></thead><tbody>
  <?php if (!$techs): ?><tr><td colspan="5" style="color:var(--muted)">Nessun tecnico abbinato: completare gli abbinamenti persone.</td></tr><?php endif; ?>
  <?php foreach ($techs as $t): $inSoc = (int)$t['unit_id'] === $uoId; ?><tr>
    <td><?=h($t['dipendente'])?></td><td><?=h($t['nomi_soc'])?></td><td><?=h($t['unita'] ?? '— nessuna —')?></td>
    <td><?= $inSoc ? $pill('IN SOC', '#16a34a') : ($t['unit_id'] === null ? $pill('ALLA PROSSIMA ESECUZIONE', '#2563eb') : $pill('IN ALTRA UNITÀ', '#d97706')) ?></td>
    <td><?php if ($can_run && !$inSoc && $t['unit_id'] !== null): ?><form method="POST" style="margin:0"><?= Csrf::field() ?><input type="hidden" name="action" value="soc_uo_force"><input type="hidden" name="employee_id" value="<?=(int)$t['employee_id']?>">
      <button class="btn btn-sm" onclick="return confirm('Spostare <?=h(addslashes((string)$t['dipendente']))?> dall\'unità <?=h(addslashes((string)$t['unita']))?> all\'unità SOC?')">Sposta su SOC</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>

<?php if ($can_run): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px;margin-bottom:14px">
  <?php foreach ([['soc_map_people', 'Abbinamento persone → dipendenti', 'fa-user-check', $people, 'employee_id', array_column($emps, 'n', 'id'), 'Automatico quando tutte le parole del nome SOC compaiono in un solo dipendente.'],
                  ['soc_map_clients', 'Abbinamento clienti', 'fa-building-circle-check', $cmap, 'client_id', $clients, 'Automatico sul nome (es. «ESTAR - TOSCANA CENTRO» → «ESTAR CENTRO»).']] as [$act, $title, $ic, $rows, $col, $opts, $hint]): ?>
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid <?=$ic?>"></i> <?=h($title)?></h3>
      <p style="font-size:11px;color:var(--muted);margin:0 0 8px"><?=h($hint)?> Le scelte manuali restano anche dopo nuove sincronizzazioni.</p>
      <form method="POST"><?= Csrf::field() ?><input type="hidden" name="action" value="<?=$act?>">
        <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Nome SOC</th><th style="text-align:right">Ticket</th><th>Abbinato a</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $p): ?><tr><td><?=h($p['name'])?></td><td style="text-align:right"><?=$n($p['ticket'])?></td>
          <td><input type="hidden" name="orig[<?=h($p['name'])?>]" value="<?=(int)$p[$col]?>"><select name="map[<?=h($p['name'])?>]" style="min-width:200px"><option value="0">— nessuno —</option>
            <?php foreach ($opts as $id => $nm): ?><option value="<?=$id?>" <?=(int)$p[$col] === (int)$id ? 'selected' : ''?>><?=h($nm)?></option><?php endforeach; ?></select></td>
          <td><?=$p['is_manual'] ? $pill('manuale', '#2563eb') : ($p[$col] ? $pill('auto', '#16a34a') : '')?></td></tr><?php endforeach; ?></tbody></table>
        <?php if ($rows): ?><button class="btn btn-primary btn-sm" style="margin-top:8px">Salva abbinamenti</button><?php endif; ?></form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="padding:14px 16px;overflow-x:auto">
  <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-list"></i> Registro della pipeline SOC</h3>
  <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Avvio</th><th>Innesco</th><th>Esito</th><th style="text-align:right">Durata</th><th style="text-align:right">File</th><th>DB SOC</th>
    <th style="text-align:right">Nuove</th><th style="text-align:right">Aggiornate</th><th style="text-align:right">UO</th><th>Dettaglio</th><th>Utente</th></tr></thead><tbody>
  <?php if (!$runs): ?><tr><td colspan="11" style="color:var(--muted)">Nessuna esecuzione.</td></tr><?php endif; ?>
  <?php foreach ($runs as $r): ?><tr><td style="white-space:nowrap"><?=$dt($r['started_at'])?></td><td><?=h($r['trigger_type'])?></td><td><?=$stPill($r['status'])?></td>
    <td style="text-align:right"><?=h((string)$r['seconds'])?> s</td><td style="text-align:right"><?=$n($r['files'])?><?=$r['files_err'] ? ' (' . $n($r['files_err']) . ' err.)' : ''?></td><td><?=h((string)$r['db_status'])?></td>
    <td style="text-align:right"><?=$n($r['rows_new'])?></td><td style="text-align:right"><?=$n($r['rows_updated'])?></td><td style="text-align:right"><?=$r['uo_assigned'] ? '+' . $n($r['uo_assigned']) : '—'?></td>
    <td title="<?=h((string)$r['detail'])?>"><?=h((string)$r['message'])?></td><td><?=h($r['utente'] ?? 'pianificazione')?></td></tr><?php endforeach; ?></tbody></table>
  <details style="margin-top:10px"><summary style="cursor:pointer;font-size:12px;font-weight:700">Dettaglio per sorgente (ultimi <?=count($batches)?> lotti)</summary>
    <table class="data-table" style="width:100%;font-size:11px;margin-top:6px"><thead><tr><th>Avvio</th><th>Fonte</th><th>Origine</th><th>Esito</th><th style="text-align:right">Righe</th><th>Messaggio</th></tr></thead><tbody>
    <?php foreach ($batches as $b): ?><tr><td><?=$dt($b['started_at'])?></td><td><?=$b['source'] === 'file' ? 'File' : 'DB SOC'?></td><td><?=h($b['origin'])?></td><td><?=$stPill($b['status'])?></td>
      <td style="text-align:right"><?=$n($b['rows_read'])?></td><td><?=h($b['message'])?></td></tr><?php endforeach; ?></tbody></table></details>
</div>
