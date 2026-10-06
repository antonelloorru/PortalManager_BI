<?php
/**
 * service_soc.php — Gestione Commesse › Service SOC (v1.10.06)
 *
 * Rendicontazione del Security Operation Center sul pattern di Service Desk: i ticket del sistema di
 * gestione SOC (export XLSX/CSV o sincronizzazione dal DB SOC, istanza separata con lo stesso schema
 * del gestionale) aggregati con i dati del portale — moduli di intervento (ore, costi, ricavi, commesse),
 * anagrafica dipendenti e clienti, filtro globale Codice Contratto / PM Project.
 *
 * Schede: Cruscotto · Ticket · Team · Clienti e commesse · Ingestion.
 * Permessi: view = consultazione; edit = import, sincronizzazione, abbinamenti; Super Admin = connessione DB SOC.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/SocModel.php');
require_once(__DIR__ . '/app/SocIngest.php');
require_once(__DIR__ . '/app/SourceDb.php');
require_once(__DIR__ . '/app/PmCharts.php');

if (!can('view', 'service_soc.php')) { redirect('manage_projects'); }
$u_id   = (int)$_SESSION['user_id'];
$isSA   = (int)($_SESSION['role_id'] ?? 99) === 1;
$canRun = can('edit', 'service_soc.php');
$TABS = ['cruscotto' => 'Cruscotto', 'ticket' => 'Ticket', 'team' => 'Team', 'clienti' => 'Clienti e commesse', 'ingestion' => 'Ingestion'];
$tab = array_key_exists($_GET['tab'] ?? '', $TABS) ? $_GET['tab'] : 'cruscotto';

$soc = new SocModel($pdo);
$ing = new SocIngest($pdo);
$flash = function (string $type, string $msg): void { $_SESSION['flash_msg'] = "<div class='alert alert-$type'>" . h($msg) . "</div>"; };
$back = fn(array $extra = []) => redirect('service_soc', array_filter(['tab' => 'ingestion'] + $extra, fn($v) => $v !== null));

// ── azioni (POST → redirect) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $act = (string)($_POST['action'] ?? '');
    if (!$canRun) { $flash('danger', 'Operazione non consentita.'); $back(); }
    @set_time_limit(600);

    if ($act === 'upload') {
        $fl = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo((string)($fl['name'] ?? ''), PATHINFO_EXTENSION));
        $err = (int)($fl['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) $flash('danger', 'File oltre il limite del server (upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size') . '): aumentarlo in php.ini o importare un CSV.');
        elseif (!$fl || $err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$fl['tmp_name'])) $flash('danger', 'Caricamento del file non riuscito.');
        elseif (!in_array($ext, ['xlsx', 'csv'], true)) $flash('danger', 'Formato non ammesso: usare l\'export XLSX o un CSV.');
        elseif ((int)$fl['size'] > 30 * 1048576) $flash('danger', 'File oltre 30 MB.');
        else {
            $r = $ing->importFile((string)$fl['tmp_name'], basename((string)$fl['name']), $u_id);
            write_log('Service SOC', $r['ok'] ? 'success' : 'warning', 'Import file ' . basename((string)$fl['name']) . ': ' . $r['message'], $u_id);
            $flash($r['ok'] ? 'success' : 'danger', $r['message']);
        }
        $back();
    }
    if ($act === 'db_sync' || $act === 'db_sync_all') {
        $r = $ing->importDb($u_id, 'manuale', $act === 'db_sync_all' ? 0 : null);
        write_log('Service SOC', $r['ok'] ? 'success' : 'warning', 'Sincronizzazione DB SOC: ' . $r['message'], $u_id);
        $flash($r['ok'] ? 'success' : 'danger', $r['message']);
        $back();
    }
    if ($act === 'rebuild') {
        $n = $ing->rebuild(); $m = $ing->autoMap();
        $flash('success', "Ticket ricostruiti: $n · persone abbinate {$m['people']} · clienti abbinati {$m['clients']}");
        $back();
    }
    if (in_array($act, ['db_save', 'db_test', 'db_preview'], true)) {
        if (!$isSA) { $flash('danger', 'Solo il Super Admin configura la connessione al DB SOC.'); $back(); }
        $cur = $pdo->query("SELECT * FROM cm_soc_source_db ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        $in = fn($k) => trim((string)($_POST[$k] ?? ''));
        $row = [
            'label' => $in('label') ?: 'Gestionale SOC', 'driver' => array_key_exists($in('driver'), SourceDb::DRIVERS) ? $in('driver') : 'mysql',
            'host' => $in('host'), 'port' => (int)$in('port') ?: 3306, 'dbname' => $in('dbname'), 'username' => $in('username'),
            'source_schema' => $in('source_schema') ?: null, 'timeout' => max(3, min(60, (int)$in('timeout') ?: 10)),
            'window_days' => max(0, min(3650, (int)$in('window_days'))), 'ticket_prefix' => preg_replace('/[^A-Za-z0-9_]/', '', $in('ticket_prefix')) ?: null,
            'extract_sql' => $in('extract_sql') ?: null, 'is_active' => empty($_POST['is_active']) ? 0 : 1,
        ];
        $pw = (string)($_POST['password'] ?? '');
        try { $row['password_enc'] = $pw !== '' ? SourceDb::encrypt($pw) : ($cur['password_enc'] ?? ''); }
        catch (Throwable $e) { $flash('danger', $e->getMessage()); $back(); }
        if ($row['host'] === '' || $row['dbname'] === '' || $row['username'] === '') { $flash('danger', 'Host, database e utente sono obbligatori.'); $back(); }
        if ($row['extract_sql'] !== null && !preg_match('/^\s*(SELECT|WITH)\b/i', (string)$row['extract_sql'])) { $flash('danger', 'La query di estrazione deve essere una SELECT.'); $back(); }
        if ($act === 'db_save') {
            $cols = array_keys($row);
            if ($cur) {
                $pdo->prepare("UPDATE cm_soc_source_db SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . " WHERE id = ?")->execute([...array_values($row), $cur['id']]);
            } else {
                $pdo->prepare("INSERT INTO cm_soc_source_db (" . implode(', ', $cols) . ", created_by) VALUES (" . implode(',', array_fill(0, count($cols) + 1, '?')) . ")")->execute([...array_values($row), $u_id]);
            }
            write_log('Service SOC', 'info', 'Connessione DB SOC salvata (' . $row['host'] . '/' . $row['dbname'] . ')', $u_id);
            $flash('success', 'Connessione al DB SOC salvata.');
            $back();
        }
        try {
            $src = SourceDb::connect(SourceDb::configFromRow($row));
            if ($act === 'db_test') {
                $flash('success', 'Connessione riuscita: ' . SourceDb::DRIVERS[$row['driver']]['label'] . ' ' . $src->serverVersion());
            } else {
                $pv = $ing->previewDb($row, 10);
                $_SESSION['soc_preview'] = $pv;
                $flash('success', 'Anteprima (ultimi 7 giorni): ' . $pv['count'] . ' eventi letti, colonne riconosciute: ' . implode(', ', $pv['columns']));
            }
        } catch (Throwable $e) { $flash('danger', 'DB SOC: ' . $e->getMessage()); }
        $back(['pv' => $act === 'db_preview' ? 1 : null]);
    }
    if ($act === 'settings') {
        $st = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $st->execute(['soc.sla_risposta_ore', (string)max(1, min(240, (int)($_POST['sla'] ?? 4)))]);
        $st->execute(['soc.presidio_ore', (string)max(1, min(720, (int)($_POST['presidio'] ?? 24)))]);
        $st->execute(['soc.sync_enabled', empty($_POST['sync_enabled']) ? '0' : '1']);
        $cs = implode(',', array_filter(array_map(fn($s) => strtoupper(trim($s)), explode(',', (string)($_POST['closed'] ?? '')))));
        $st->execute(['soc.closed_states', $cs ?: 'CHIUSO,CHIUSO DAL CLIENTE']);
        (new SocIngest($pdo))->rebuild();
        $flash('success', 'Impostazioni salvate, ticket ricalcolati.');
        $back();
    }
    if ($act === 'map_people' || $act === 'map_clients') {
        $tbl = $act === 'map_people' ? ['cm_soc_people', 'employee_id', 'employees'] : ['cm_soc_clients', 'client_id', 'clients'];
        $st = $pdo->prepare("UPDATE {$tbl[0]} SET {$tbl[1]} = ?, is_manual = 1 WHERE name = ?");
        $ok = $pdo->prepare("SELECT COUNT(*) FROM {$tbl[2]} WHERE id = ?");
        $n = 0;
        foreach ((array)($_POST['map'] ?? []) as $name => $id) {
            $id = (int)$id; $orig = (string)($_POST['orig'][$name] ?? '');
            if ((string)$id === $orig) continue;
            if ($id > 0) { $ok->execute([$id]); if (!$ok->fetchColumn()) continue; }
            $st->execute([$id > 0 ? $id : null, (string)$name]); $n++;
        }
        $ing->autoMap();
        $flash('success', "Abbinamenti aggiornati: $n.");
        $back();
    }
    $back();
}

// ── dati ────────────────────────────────────────────────────────────────────
$f = $soc->normFilters($_GET);
$ready = $soc->ready();
$arch = $soc->archivio();
$vCtr = $soc->valoriContratti();
$ticketCode = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['ticket'] ?? '')));
$tk = $ticketCode !== '' ? $soc->ticket($ticketCode) : null;
if ($tk) $tab = 'ticket';
if (!$ready) $tab = 'ingestion';

// export XLSX: perimetro e filtri della pagina
if ($ready && ($_GET['export'] ?? '') === 'xlsx' && can('export', 'service_soc.php')) {
    require_once(__DIR__ . '/app/XlsxWriter.php');
    $w = new XlsxWriter();
    $rows = [['Ticket', 'Titolo', 'Cliente', 'Commessa SOC', 'Categoria', 'Tipo', 'Responsabile', 'Incaricato', 'Aperto il', 'Ultimo evento', 'Chiuso il',
              'Stato', 'Esito', 'Eventi', 'Msg supporto', 'Msg cliente', 'Note', 'Riaperture', 'Risposta media (h)', 'Durata gestionale (gg)',
              'Moduli', 'Ore moduli', 'Commesse PM']];
    foreach ($soc->tickets($f, 20000) as $t) $rows[] = [$t['ticket_code'], $t['title'], $t['client_name'], $t['soc_contract'], $t['category'], $t['ticket_type'],
        $t['owner_name'], $t['assignee_name'], $t['opened_at'], $t['last_event_at'], $t['closed_at'], $t['status_now'], $t['resolution'],
        (int)$t['n_events'], (int)$t['n_support'], (int)$t['n_customer'], (int)$t['n_notes'], (int)$t['n_reopen'],
        $t['avg_reply_min'] !== null ? round($t['avg_reply_min'] / 60, 2) : null, $t['duration_min'] !== null ? round($t['duration_min'] / 1440, 1) : null,
        (int)$t['moduli'], (float)$t['ore'], $t['commesse']];
    $w->addSheet('Ticket', $rows);
    $hdr = ['Valore', 'Ticket attivi', 'Aperti', 'Chiusi', 'Risolti', 'Ancora aperti', 'Risposta media (h)', 'Chiusura media (gg)', 'Eventi', 'Ore moduli', 'Costo (€)', 'Ricavo (€)'];
    foreach (['categoria' => 'Categorie', 'cliente' => 'Clienti', 'esito' => 'Esiti', 'stato' => 'Stati'] as $d => $sheet) {
        $r = [$hdr]; foreach ($soc->breakdown($f, $d) as $b) $r[] = [$b['k'], (int)$b['attivi'], (int)$b['aperti'], (int)$b['chiusi'], (int)$b['risolti'], (int)$b['ancora_aperti'],
            $b['risposta_media_h'] !== null ? (float)$b['risposta_media_h'] : null, $b['chiusura_media_g'] !== null ? (float)$b['chiusura_media_g'] : null, (int)$b['eventi'], (float)$b['ore_moduli'], (float)$b['costo'], (float)$b['ricavo']];
        $w->addSheet($sheet, $r);
    }
    $r = [['Persona SOC', 'Dipendente', 'Ticket', 'Chiusi nel periodo', 'Aperti', 'Msg supporto', 'Note', 'Risposta media (h)', 'Ore moduli SOC', 'Ore moduli totali', 'Quota SOC %']];
    foreach ($soc->team($f) as $t) $r[] = [$t['nome'], $t['dipendente'], (int)$t['ticket'], (int)$t['chiusi'], (int)$t['aperti'], $t['msg_supporto'], $t['note'],
        $t['risposta_media_h'] !== null ? (float)$t['risposta_media_h'] : null, $t['ore_soc'], $t['ore_tot'], $t['quota_soc'] !== null ? round($t['quota_soc'], 1) : null];
    $w->addSheet('Team', $r);
    $r = [['Commessa PM', 'Descrizione', 'Cliente', 'Moduli', 'Ticket', 'Ore', 'Costo (€)', 'Ricavo (€)', 'Tecnici']];
    foreach ($soc->commessePm($f) as $c) $r[] = [$c['codice'], $c['nome'], $c['cliente'], (int)$c['moduli'], (int)$c['ticket'], (float)$c['ore'], (float)$c['costo'], (float)$c['ricavo'], (int)$c['tecnici']];
    $w->addSheet('Commesse PM', $r);
    $w->addSheet('Filtri', [['Filtro', 'Valore'], ['Periodo', $f['from'] . ' → ' . $f['to']], ['Cliente', $f['cliente']], ['Commessa SOC', $f['commessa']],
        ['Categoria', $f['categoria']], ['Componente', $f['tec']], ['Stato', $f['stato']], ['Esito', $f['esito']], ['Ricerca', $f['q']], ['Contratti', implode(', ', $f['contratti'])]]);
    write_log('Service SOC', 'info', 'Export XLSX ' . $f['from'] . ' → ' . $f['to'], $u_id);
    $w->download('service_soc_' . date('Ymd_Hi') . '.xlsx'); exit;
}

if ($ready) {
    $hl = $soc->headline($f);
    if ($tab === 'cruscotto') {
        $trend = $soc->trend($f, 12); $trG = $soc->trendGiornaliero($f);
        $bCat = $soc->breakdown($f, 'categoria'); $bEsito = array_values(array_filter($soc->breakdown($f, 'esito'), fn($r) => $r['k'] !== '(non indicato)'));
        $bStato = $soc->breakdown($f, 'stato'); $pres = $soc->presidio($f, 20);
    }
    if ($tab === 'ticket' && !$tk) { $order = in_array($_GET['ord'] ?? '', ['recenti', 'vecchi', 'eventi', 'ore'], true) ? $_GET['ord'] : 'recenti'; $list = $soc->tickets($f, 500, $order); $nList = $soc->countTickets($f); }
    if ($tab === 'team') $team = $soc->team($f);
    if ($tab === 'clienti') { $cli = $soc->clienti($f); $bCom = $soc->breakdown($f, 'commessa'); $cpm = $soc->commessePm($f); }
}
if ($tab === 'ingestion') {
    $batches = $soc->batches(20);
    $dbCfg = $pdo->query("SELECT * FROM cm_soc_source_db ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    $people = $soc->people(); $cmap = $soc->clientMap();
    $emps = $pdo->query("SELECT id, CONCAT_WS(' ', last_name, first_name) n, status FROM employees ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
    $clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $preview = !empty($_GET['pv']) ? ($_SESSION['soc_preview'] ?? null) : null; unset($_SESSION['soc_preview']);
    $drivers = SourceDb::availableDrivers();
    $set = ['sla' => $soc->setting('soc.sla_risposta_ore', '4'), 'presidio' => $soc->setting('soc.presidio_ore', '24'),
            'sync' => $soc->setting('soc.sync_enabled', '0'), 'closed' => $soc->setting('soc.closed_states', 'CHIUSO,CHIUSO DAL CLIENTE')];
}

$qs = function (array $over = []) use ($f, $tab) {
    $p = array_filter(['tab' => $tab, 'from' => $f['from'], 'to' => $f['to'], 'cliente' => $f['cliente'], 'commessa' => $f['commessa'], 'categoria' => $f['categoria'],
                       'tec' => $f['tec'], 'stato' => $f['stato'], 'esito' => $f['esito'], 'q' => $f['q'], 'contratti' => implode(',', $f['contratti'])], fn($v) => $v !== '' && $v !== null);
    return url_safe('service_soc', array_filter(array_merge($p, $over), fn($v) => $v !== null));
};
$n  = fn($v) => number_format((float)$v, 0, ',', '.');
$n1 = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');
$eur = fn($v) => number_format((float)$v, 0, ',', '.') . ' €';
$dt = fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '—';
$dd = fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$colStato = fn($s) => match (true) { in_array($s, ['CHIUSO', 'CHIUSO DAL CLIENTE'], true) => '#16a34a', $s === 'ATTESA CLIENTE' => '#2563eb', $s === 'LAV. SUPPORTO' => '#f59e0b', $s === 'SOSPESO' => '#64748b', str_contains((string)$s, 'ESCALATION') => '#7c3aed', default => '#dc2626' };
$pill = fn($txt, $c) => "<span style='display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:700;background:{$c}1a;color:$c;white-space:nowrap'>" . h((string)$txt) . "</span>";
$kindLbl = ['supporto' => ['Supporto', '#2563eb'], 'cliente' => ['Cliente', '#f59e0b'], 'nota' => ['Nota interna', '#64748b'], 'apertura' => ['Apertura', '#16a34a'], 'altro' => ['Altro', '#94a3b8']];

require_once('header.php');
?>
<div style="margin-bottom:12px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-shield-halved"></i> Service SOC</h1>
  <p style="color:var(--muted);font-size:12px;margin-top:2px">
    Ticket del sistema di gestione SOC aggregati con i dati del portale (moduli di intervento, commesse, dipendenti, clienti).
    <?php if ($arch['ticket']): ?>Archivio: <strong><?=$n($arch['ticket'])?></strong> ticket, <?=$n($arch['eventi'])?> eventi dal <?=$dd($arch['dal'])?> al <?=$dd($arch['al'])?>
      · <?=$n($arch['con_moduli'])?> ticket con moduli di intervento.<?php endif; ?>
  </p>
</div>
<?= $_SESSION['flash_msg'] ?? '' ?><?php unset($_SESSION['flash_msg']); ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning"><strong>Nessun ticket SOC nel portale.</strong> Importare l'export «lista eventi ticket» o configurare la sincronizzazione dal DB SOC nella scheda Ingestion.</div>
<?php else: ?>
<?= PmContractFilter::banner($f['contratti'], $vCtr, $qs(['contratti' => null, 'contratti_set' => 1]), 'ticket con moduli di intervento sulle commesse selezionate') ?>

<?php $attivi = ($f['cliente'] !== '') + ($f['commessa'] !== '') + ($f['categoria'] !== '') + ($f['tec'] !== '') + ($f['stato'] !== '') + ($f['esito'] !== '') + ($f['q'] !== '') + (count($f['contratti']) > 0); ?>
<details class="pm-panel" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary><i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?=$attivi?></span><?php endif; ?>
    <span class="pm-hint"><?=$n($hl['attivi'])?> ticket nel periodo <?=$dd($f['from'])?> – <?=$dd($f['to'])?></span></summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?><input type="hidden" name="tab" value="<?=h($tab === 'ingestion' ? 'cruscotto' : $tab)?>">
      <div class="pm-group"><h4>Contratto</h4><div class="pm-grid-auto"><?= PmContractFilter::field($vCtr, $f['contratti'], 'ticket con moduli di intervento sulle commesse') ?></div></div>
      <div class="pm-group"><h4>Periodo</h4><div class="pm-grid-auto">
        <div class="form-group"><label>Dal</label><input type="date" name="from" value="<?=h($f['from'])?>"></div>
        <div class="form-group"><label>Al</label><input type="date" name="to" value="<?=h($f['to'])?>"></div></div></div>
      <div class="pm-group"><h4>Selezione</h4><div class="pm-grid-auto">
        <?php foreach (['cliente' => 'Cliente', 'commessa' => 'Commessa SOC', 'categoria' => 'Categoria', 'tec' => 'Componente (responsabile o incaricato)', 'esito' => 'Esito'] as $k => $l): ?>
          <div class="form-group"><label><?=h($l)?></label><select name="<?=$k?>" class="pm-ms"><option value="">— tutti —</option>
            <?php foreach ($soc->valori($k) as $v): ?><option value="<?=h($v)?>" <?=$f[$k] === $v ? 'selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="form-group"><label>Stato</label><select name="stato" class="pm-ms"><option value="">— tutti —</option>
          <?php foreach (['aperti' => 'Ancora aperti', 'chiusi' => 'Chiusi', 'presidio' => 'Da presidiare (attesa supporto)'] as $k => $l): ?><option value="<?=$k?>" <?=$f['stato'] === $k ? 'selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Ricerca (codice o titolo)</label><input type="text" name="q" value="<?=h($f['q'])?>" placeholder="WES_000000282, certificato…"></div>
      </div></div>
      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('service_soc', ['tab' => $tab === 'ingestion' ? 'cruscotto' : $tab, 'contratti_set' => 1])?>">Azzera</a>
        <?php if (can('export', 'service_soc.php')): ?><a class="btn btn-sm" href="<?=$qs(['export' => 'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a><?php endif; ?>
      </div>
    </form>
  </div>
</details>

<!-- indicatori -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:14px">
  <?php foreach ([
    ['Ticket del periodo', $n($hl['attivi']), '#334155', $n($hl['aperti']) . ' aperti · ' . $n($hl['chiusi']) . ' chiusi'],
    ['Risposta al cliente', $hl['presa_mediana_h'] === null ? '—' : $n1($hl['presa_mediana_h']) . ' h', '#2563eb',
      ($hl['sla_pct'] === null ? '—' : $n1($hl['sla_pct']) . '%') . ' entro ' . $n($hl['sla_ore']) . ' h · ' . $n($hl['senza_risposta']) . ' in attesa'],
    ['Tempo di chiusura', $hl['chiusura_mediana_g'] === null ? '—' : $n1($hl['chiusura_mediana_g']) . ' gg', '#16a34a', $n($hl['risolti']) . ' risolti · ' . $n($hl['riaperture']) . ' riaperture'],
    ['Backlog a fine periodo', $n($hl['backlog']), '#f59e0b', 'aperti e non chiusi al ' . $dd($f['to'])],
    ['Da presidiare', $n($hl['presidio']), $hl['presidio'] > 0 ? '#dc2626' : '#16a34a', 'attesa supporto oltre ' . $n($soc->setting('soc.presidio_ore', '24')) . ' h'],
    ['Moduli di intervento', $n1($hl['moduli']['ore']) . ' h', '#7c3aed', $n($hl['moduli']['moduli']) . ' moduli su ' . $n($hl['moduli']['ticket']) . ' ticket · ' . $eur($hl['moduli']['costo'])],
  ] as [$lbl, $val, $col, $sub]): ?>
    <div class="card" style="text-align:center;padding:12px;border-top:3px solid <?=$col?>">
      <div style="font-size:24px;font-weight:800;color:<?=$col?>"><?=$val?></div>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($lbl)?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:3px"><?=h($sub)?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tabs" style="margin-bottom:12px;border-bottom:1px solid #e2e8f0">
  <?php foreach ($TABS as $k => $l): if (!$ready && $k !== 'ingestion') continue; ?>
    <a class="tab-btn <?=$tab === $k ? 'active' : ''?>" href="<?=$qs(['tab' => $k, 'ticket' => null])?>" style="text-decoration:none"><?=h($l)?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'cruscotto' && $ready): ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(520px,1fr));gap:14px;margin-bottom:14px">
    <div class="card" style="padding:14px 16px">
      <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-chart-column"></i> Andamento mensile — ticket aperti e chiusi</h3>
      <?= PmCharts::groupedBars(array_map(fn($r) => substr($r['ym'], 5) . '/' . substr($r['ym'], 2, 2), $trend), [
            ['label' => 'Aperti', 'color' => '#2563eb', 'values' => array_column($trend, 'aperti')],
            ['label' => 'Chiusi', 'color' => '#16a34a', 'values' => array_column($trend, 'chiusi')]], ['height' => 200]) ?>
      <table class="data-table" style="width:100%;font-size:11px;margin-top:6px"><tr><th>Mese</th><?php foreach ($trend as $r): ?><th style="text-align:right"><?=h(substr($r['ym'], 5) . '/' . substr($r['ym'], 2, 2))?></th><?php endforeach; ?></tr>
        <tr><td>Backlog fine mese</td><?php foreach ($trend as $r): ?><td style="text-align:right"><?=$n($r['backlog'])?></td><?php endforeach; ?></tr>
        <tr><td>Messaggi supporto</td><?php foreach ($trend as $r): ?><td style="text-align:right"><?=$n($r['supporto'])?></td><?php endforeach; ?></tr>
        <tr><td>Messaggi cliente</td><?php foreach ($trend as $r): ?><td style="text-align:right"><?=$n($r['cliente'])?></td><?php endforeach; ?></tr></table>
    </div>
    <div class="card" style="padding:14px 16px">
      <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-calendar-day"></i> Attività giornaliera — eventi per tipo
        <span style="font-weight:400;color:var(--muted);font-size:11px">(<?=$dd($trG['from'])?> – <?=$dd($trG['to'])?>)</span></h3>
      <?= PmCharts::dailyStacked(PmCharts::fillDays($trG['rows'], $trG['from'], $trG['to'], 'giorno', ['supporto', 'cliente', 'nota', 'altro']), [
            ['key' => 'supporto', 'label' => 'Supporto', 'color' => '#2563eb'], ['key' => 'cliente', 'label' => 'Cliente', 'color' => '#f59e0b'],
            ['key' => 'nota', 'label' => 'Note interne', 'color' => '#94a3b8'], ['key' => 'altro', 'label' => 'Altro', 'color' => '#16a34a']], ['unit' => 'eventi', 'decimals' => 0, 'height' => 230]) ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px;margin-bottom:14px">
    <?php foreach ([['Per categoria', $bCat, 'categoria'], ['Esito dei ticket chiusi', $bEsito, 'esito'], ['Stato attuale', $bStato, null]] as [$tt, $rows, $fk]): ?>
      <div class="card" style="padding:14px 16px;overflow-x:auto">
        <h3 style="font-size:14px;margin:0 0 8px"><?=h($tt)?></h3>
        <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th></th><th style="text-align:right">Ticket</th><th style="text-align:right">Chiusi</th><th style="text-align:right">Risposta media</th><th style="text-align:right">Ore moduli</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="5" style="color:var(--muted)">Nessun dato.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr>
          <td><?= $fk ? '<a href="' . $qs([$fk => $r['k']]) . '">' . h($r['k']) . '</a>' : $pill($r['k'], $colStato($r['k'])) ?></td>
          <td style="text-align:right"><?=$n($r['attivi'])?></td><td style="text-align:right"><?=$n($r['chiusi'])?></td>
          <td style="text-align:right"><?=$r['risposta_media_h'] === null ? '—' : $n1($r['risposta_media_h']) . ' h'?></td><td style="text-align:right"><?=$n1($r['ore_moduli'])?></td></tr><?php endforeach; ?>
        </tbody></table>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card" style="padding:14px 16px;overflow-x:auto">
    <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-triangle-exclamation"></i> Ticket da presidiare (<?=$n($hl['presidio'])?>)</h3>
    <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Aperti, mai presi in carico o con l'ultimo messaggio del cliente senza risposta da oltre <?=$n($soc->setting('soc.presidio_ore', '24'))?> ore. Ordinati dal più vecchio.</p>
    <?php include_once __DIR__ . '/app/soc_ticket_table.php'; soc_ticket_table($pres, $qs, $pill, $colStato, $dt, $n, $n1, true); ?>
    <?php if ($hl['presidio'] > count($pres)): ?><p style="font-size:12px"><a href="<?=$qs(['tab' => 'ticket', 'stato' => 'presidio'])?>">Tutti i <?=$n($hl['presidio'])?> ticket da presidiare →</a></p><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($tab === 'ticket' && $ready): ?>
  <?php if ($tk): ?>
    <div class="card" style="padding:14px 16px;margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <div><h3 style="font-size:16px;margin:0"><?=h($tk['ticket_code'])?> <?=$pill($tk['status_now'] ?? '—', $colStato($tk['status_now'] ?? ''))?> <?=$tk['resolution'] ? $pill($tk['resolution'], '#16a34a') : ''?></h3>
          <div style="font-size:13px;margin-top:4px"><?=h($tk['title'])?></div></div>
        <a class="btn btn-sm" href="<?=$qs(['ticket' => null])?>">&larr; Elenco ticket</a>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px 16px;font-size:12px;margin-top:10px">
        <?php foreach (['Cliente' => $tk['client_name'] . ($tk['cliente_pm'] ? ' (' . $tk['cliente_pm'] . ')' : ''), 'Commessa SOC' => $tk['soc_contract'], 'Categoria' => $tk['category'], 'Tipo' => $tk['ticket_type'],
                        'Responsabile' => $tk['owner_name'], 'Incaricato' => $tk['assignee_name'] . ($tk['dipendente'] ? ' → ' . $tk['dipendente'] : ''), 'Coda' => $tk['queue_name'], 'Casella' => $tk['mailbox'],
                        'Aperto' => $dt($tk['opened_at']), 'Ultimo evento' => $dt($tk['last_event_at']), 'Chiuso' => $dt($tk['closed_at']),
                        'Risposta media al cliente' => $tk['avg_reply_min'] === null ? '—' : $n1($tk['avg_reply_min'] / 60) . ' h',
                        'Eventi' => $tk['n_events'] . ' (' . $tk['n_support'] . ' supporto, ' . $tk['n_customer'] . ' cliente, ' . $tk['n_notes'] . ' note)', 'Riaperture' => $tk['n_reopen'],
                        'Durata (gestionale)' => $tk['duration_min'] === null ? '—' : $n1($tk['duration_min'] / 1440) . ' gg'] as $k => $v): ?>
          <div><div style="color:var(--muted);font-size:10px;text-transform:uppercase;font-weight:700"><?=h($k)?></div><?=h($v === null || $v === '' ? '—' : (string)$v)?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card" style="padding:14px 16px;margin-bottom:14px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 8px">Moduli di intervento del portale (<?=count($tk['moduli'])?>)</h3>
      <?php if (!$tk['moduli']): ?><p style="font-size:12px;color:var(--muted);margin:0">Nessun modulo di intervento riporta questo ticket.</p><?php else: ?>
      <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Data</th><th>Modulo</th><th>Commessa</th><th>Tecnico</th><th style="text-align:right">Ore</th><th style="text-align:right">Costo</th><th style="text-align:right">Ricavo</th><th>Note</th></tr></thead><tbody>
        <?php foreach ($tk['moduli'] as $m): ?><tr><td><?=$dd($m['report_date'])?></td><td><?=h($m['report_code'])?></td><td><?=h($m['project_code'])?> <span style="color:var(--muted)"><?=h($m['progetto'])?></span></td>
          <td><?=h($m['technician_raw'])?></td><td style="text-align:right"><?=$n1($m['quantity_hours'])?></td><td style="text-align:right"><?=$eur($m['company_cost_import'])?></td><td style="text-align:right"><?=$eur($m['client_revenue_import'])?></td>
          <td><?=$m['on_call'] ? $pill('reperibilità', '#7c3aed') : ''?> <?=$m['remote'] ? $pill('remoto', '#0891b2') : ''?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 8px">Cronologia degli eventi (<?=count($tk['eventi'])?>)</h3>
      <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Data</th><th>Evento</th><th>Autore</th><th>Stato</th><th>Oggetto</th><th style="text-align:right">Risposta</th><th>Fonte</th></tr></thead><tbody>
        <?php foreach ($tk['eventi'] as $e): [$kl, $kc] = $kindLbl[$e['event_kind']] ?? ['—', '#94a3b8']; ?><tr>
          <td style="white-space:nowrap"><?=$dt($e['event_at'])?></td><td><?=$pill($kl, $kc)?></td><td><?=h($e['author_name'])?></td>
          <td style="white-space:nowrap;font-size:11px"><?=h($e['status_before'] ?: '—')?> → <strong><?=h($e['status_after'] ?: '—')?></strong></td>
          <td><?=h(mb_strimwidth((string)$e['subject'], 0, 110, '…'))?></td>
          <td style="text-align:right;white-space:nowrap"><?=$e['event_kind'] === 'cliente' ? ($e['reply_min'] === null ? $pill('in attesa', '#dc2626') : $n1($e['reply_min'] / 60) . ' h') : ''?></td>
          <td style="font-size:11px;color:var(--muted)"><?=h($e['source'])?></td></tr><?php endforeach; ?></tbody></table>
    </div>
  <?php else: ?>
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
        <h3 style="font-size:14px;margin:0">Ticket <?=$f['stato'] === 'presidio' ? 'da presidiare' : 'del periodo'?> — <?=$n($nList)?><?=$nList > count($list) ? ' (primi ' . count($list) . ', export completo in XLSX)' : ''?></h3>
        <div style="font-size:12px">Ordina:
          <?php foreach (['recenti' => 'ultimo evento', 'vecchi' => 'più vecchi', 'eventi' => 'più eventi', 'ore' => 'più ore moduli'] as $k => $l): ?>
            <a href="<?=$qs(['ord' => $k])?>" style="<?=$order === $k ? 'font-weight:700' : ''?>"><?=h($l)?></a><?= $k !== 'ore' ? ' · ' : '' ?>
          <?php endforeach; ?></div>
      </div>
      <?php include_once __DIR__ . '/app/soc_ticket_table.php'; soc_ticket_table($list, $qs, $pill, $colStato, $dt, $n, $n1, false); ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'team' && $ready): ?>
  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-users"></i> Ticket per componente (incaricato)</h3>
    <?php $tm = array_values(array_filter($team, fn($t) => mb_strtolower($t['nome']) !== 'non assegnato')); ?>
    <?= PmCharts::groupedBars(array_map(fn($t) => explode(' ', $t['nome'])[0], $tm), [
          ['label' => 'Ticket del periodo', 'color' => '#2563eb', 'values' => array_map(fn($t) => (int)$t['ticket'], $tm)],
          ['label' => 'Chiusi nel periodo', 'color' => '#16a34a', 'values' => array_map(fn($t) => (int)$t['chiusi'], $tm)],
          ['label' => 'Messaggi al cliente', 'color' => '#f59e0b', 'values' => array_map(fn($t) => (int)$t['msg_supporto'], $tm)]], ['height' => 210]) ?>
  </div>
  <div class="card" style="padding:14px 16px;overflow-x:auto">
    <h3 style="font-size:14px;margin:0 0 4px">Il team SOC e i dati del portale</h3>
    <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Ore dai moduli di intervento del dipendente abbinato nel periodo: «SOC» = moduli che riportano un ticket SOC; «totali» = tutti i suoi moduli. Abbinamenti nella scheda Ingestion.</p>
    <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Componente SOC</th><th>Dipendente</th><th style="text-align:right">Ticket</th><th style="text-align:right">Chiusi</th><th style="text-align:right">Aperti</th>
      <th style="text-align:right">Msg supporto</th><th style="text-align:right">Note</th><th style="text-align:right">Risposta media</th><th style="text-align:right">Ore SOC</th><th style="text-align:right">Ore totali</th><th style="text-align:right">Quota SOC</th></tr></thead><tbody>
    <?php foreach ($team as $t): ?><tr>
      <td><a href="<?=$qs(['tec' => $t['nome'], 'tab' => 'ticket'])?>"><?=h($t['nome'])?></a></td>
      <td><?= $t['dipendente'] ? h($t['dipendente']) : '<span style="color:var(--muted)">non abbinato</span>' ?></td>
      <td style="text-align:right"><?=$n($t['ticket'])?></td><td style="text-align:right"><?=$n($t['chiusi'])?></td><td style="text-align:right"><?=$n($t['aperti'])?></td>
      <td style="text-align:right"><?=$n($t['msg_supporto'])?></td><td style="text-align:right"><?=$n($t['note'])?></td>
      <td style="text-align:right"><?=$t['risposta_media_h'] === null ? '—' : $n1($t['risposta_media_h']) . ' h'?></td>
      <td style="text-align:right"><?=$n1($t['ore_soc'])?></td><td style="text-align:right"><?=$n1($t['ore_tot'])?></td><td style="text-align:right"><?=$t['quota_soc'] === null ? '—' : $n1($t['quota_soc']) . '%'?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
<?php endif; ?>

<?php if ($tab === 'clienti' && $ready): ?>
  <div class="card" style="padding:14px 16px;margin-bottom:14px;overflow-x:auto">
    <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-building"></i> Per cliente</h3>
    <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Cliente SOC</th><th>Cliente portale</th><th style="text-align:right">Ticket</th><th style="text-align:right">Aperti</th><th style="text-align:right">Chiusi</th>
      <th style="text-align:right">Ancora aperti</th><th style="text-align:right">Risposta media</th><th style="text-align:right">Chiusura media</th><th style="text-align:right">Eventi</th><th style="text-align:right">Ore moduli</th><th style="text-align:right">Costo</th><th style="text-align:right">Ricavo</th></tr></thead><tbody>
    <?php foreach ($cli as $r): ?><tr><td><a href="<?=$qs(['cliente' => $r['k']])?>"><?=h($r['k'])?></a></td><td><?= $r['cliente_pm'] ? h($r['cliente_pm']) : '<span style="color:var(--muted)">non abbinato</span>' ?></td>
      <td style="text-align:right"><?=$n($r['attivi'])?></td><td style="text-align:right"><?=$n($r['aperti'])?></td><td style="text-align:right"><?=$n($r['chiusi'])?></td><td style="text-align:right"><?=$n($r['ancora_aperti'])?></td>
      <td style="text-align:right"><?=$r['risposta_media_h'] === null ? '—' : $n1($r['risposta_media_h']) . ' h'?></td><td style="text-align:right"><?=$r['chiusura_media_g'] === null ? '—' : $n1($r['chiusura_media_g']) . ' gg'?></td>
      <td style="text-align:right"><?=$n($r['eventi'])?></td><td style="text-align:right"><?=$n1($r['ore_moduli'])?></td><td style="text-align:right"><?=$eur($r['costo'])?></td><td style="text-align:right"><?=$eur($r['ricavo'])?></td></tr><?php endforeach; ?></tbody></table>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(480px,1fr));gap:14px">
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 8px">Per commessa SOC</h3>
      <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Commessa SOC</th><th style="text-align:right">Ticket</th><th style="text-align:right">Chiusi</th><th style="text-align:right">Ancora aperti</th><th style="text-align:right">Ore moduli</th></tr></thead><tbody>
      <?php foreach ($bCom as $r): ?><tr><td><a href="<?=$qs(['commessa' => $r['k'] === '(non indicato)' ? null : $r['k']])?>"><?=h($r['k'])?></a></td><td style="text-align:right"><?=$n($r['attivi'])?></td>
        <td style="text-align:right"><?=$n($r['chiusi'])?></td><td style="text-align:right"><?=$n($r['ancora_aperti'])?></td><td style="text-align:right"><?=$n1($r['ore_moduli'])?></td></tr><?php endforeach; ?></tbody></table>
    </div>
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 4px">Commesse del portale (dai moduli di intervento)</h3>
      <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Moduli di intervento del periodo che riportano un ticket del perimetro.</p>
      <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Commessa</th><th>Cliente</th><th style="text-align:right">Moduli</th><th style="text-align:right">Ticket</th><th style="text-align:right">Ore</th><th style="text-align:right">Costo</th><th style="text-align:right">Ricavo</th></tr></thead><tbody>
      <?php if (!$cpm): ?><tr><td colspan="7" style="color:var(--muted)">Nessun modulo di intervento sui ticket del periodo.</td></tr><?php endif; ?>
      <?php foreach ($cpm as $c): ?><tr><td><?= $c['project_id'] ? '<a href="' . url_safe('project_dashboard', ['id' => $c['project_id']]) . '">' . h($c['codice']) . '</a>' : h($c['codice']) ?> <span style="color:var(--muted)"><?=h($c['nome'])?></span></td>
        <td><?=h($c['cliente'])?></td><td style="text-align:right"><?=$n($c['moduli'])?></td><td style="text-align:right"><?=$n($c['ticket'])?></td><td style="text-align:right"><?=$n1($c['ore'])?></td>
        <td style="text-align:right"><?=$eur($c['costo'])?></td><td style="text-align:right"><?=$eur($c['ricavo'])?></td></tr><?php endforeach; ?></tbody></table>
    </div>
  </div>
<?php endif; ?>

<?php if ($tab === 'ingestion'): ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px;margin-bottom:14px">
    <div class="card" style="padding:14px 16px">
      <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-file-excel"></i> Import da file</h3>
      <p style="font-size:12px;color:var(--muted);margin:0 0 8px">Export «lista eventi ticket» del sistema di gestione SOC, XLSX o CSV, con le intestazioni del gestionale
        (Data evento, Codice, Evento, Stato prima, Stato dopo, Autore, Titolo; facoltative Responsabile, Incaricato, Tipo, Categoria, Coda, Risoluzione, Cliente, Commessa, Durata, Casella di posta).
        Reimportare lo stesso file non crea doppioni; file successivi aggiornano i ticket.</p>
      <?php if ($canRun): ?>
      <p style="font-size:11px;color:var(--muted);margin:0 0 6px">Limite di caricamento del server: <?=h(ini_get('upload_max_filesize'))?>.</p>
      <form method="POST" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?= Csrf::field() ?><input type="hidden" name="action" value="upload">
        <input type="file" name="file" accept=".xlsx,.csv" required style="flex:1;min-width:220px">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-upload"></i> Importa</button>
      </form><?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px;font-size:12px">
        <div><div style="color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase">Eventi</div><b><?=$n($arch['eventi'])?></b> <span style="color:var(--muted)">(prima fonte: file <?=$n($arch['da_file'])?> · DB <?=$n($arch['da_db'])?>)</span></div>
        <div><div style="color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase">Ticket</div><b><?=$n($arch['ticket'])?></b></div>
        <div><div style="color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase">Periodo</div><?=$dd($arch['dal'])?> – <?=$dd($arch['al'])?></div>
      </div>
      <?php if ($canRun && $arch['ticket']): ?><form method="POST" style="margin-top:10px"><?= Csrf::field() ?><input type="hidden" name="action" value="rebuild">
        <button class="btn btn-sm"><i class="fa-solid fa-rotate"></i> Ricostruisci ticket e abbinamenti</button></form><?php endif; ?>
    </div>

    <div class="card" style="padding:14px 16px">
      <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-database"></i> Sincronizzazione dal DB SOC</h3>
      <p style="font-size:12px;color:var(--muted);margin:0 0 8px">Istanza separata con lo stesso schema del gestionale, utenza di sola lettura. Password cifrata con APP_SECRET.
        Stato: <?= $dbCfg ? ($dbCfg['is_active'] ? $pill('CONFIGURATO', '#16a34a') : $pill('DISATTIVO', '#64748b')) . ' ' . h($dbCfg['host'] . '/' . $dbCfg['dbname']) . ' · ultima: ' . $dt($dbCfg['last_sync_at']) . ' — ' . h((string)$dbCfg['last_sync_note']) : $pill('NON CONFIGURATO', '#d97706') ?></p>
      <?php if ($canRun && $dbCfg): ?>
        <form method="POST" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="action" value="db_sync"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-rotate"></i> Sincronizza ora (ultimi <?=$n($dbCfg['window_days'])?> gg)</button></form>
        <form method="POST" style="display:inline"><?= Csrf::field() ?><input type="hidden" name="action" value="db_sync_all"><button class="btn btn-sm" onclick="return confirm('Lettura completa del DB SOC: può richiedere alcuni minuti. Continuare?')">Sincronizzazione completa</button></form>
      <?php endif; ?>
      <?php if ($isSA): $c = $dbCfg ?: ['label' => 'Gestionale SOC', 'driver' => 'mysql', 'host' => '', 'port' => 3306, 'dbname' => '', 'username' => '', 'source_schema' => '', 'timeout' => 10, 'window_days' => 30, 'ticket_prefix' => 'WES_', 'extract_sql' => '', 'is_active' => 1, 'password_enc' => '']; ?>
      <details style="margin-top:10px" <?= $dbCfg ? '' : 'open' ?>><summary style="cursor:pointer;font-size:13px;font-weight:700">Connessione (Super Admin)</summary>
        <form method="POST" autocomplete="off" style="margin-top:8px">
          <?= Csrf::field() ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px 10px">
            <div class="form-group"><label>Nome</label><input name="label" value="<?=h($c['label'])?>"></div>
            <div class="form-group"><label>Driver</label><select name="driver"><?php foreach (SourceDb::DRIVERS as $k => $d): ?><option value="<?=$k?>" <?=$c['driver'] === $k ? 'selected' : ''?> <?=isset($drivers[$k]) ? '' : 'disabled'?>><?=h($d['label'])?><?=isset($drivers[$k]) ? '' : ' (non disponibile)'?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Host</label><input name="host" value="<?=h($c['host'])?>" placeholder="10.100.7.65"></div>
            <div class="form-group"><label>Porta</label><input type="number" name="port" value="<?=(int)$c['port']?>"></div>
            <div class="form-group"><label>Database</label><input name="dbname" value="<?=h($c['dbname'])?>" placeholder="sp-soc"></div>
            <div class="form-group"><label>Utente (sola lettura)</label><input name="username" value="<?=h($c['username'])?>"></div>
            <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="<?=$c['password_enc'] ? '•••••• (vuoto = invariata)' : ''?>" autocomplete="new-password"></div>
            <div class="form-group"><label>Schema (facoltativo)</label><input name="source_schema" value="<?=h((string)$c['source_schema'])?>"></div>
            <div class="form-group"><label>Timeout (s)</label><input type="number" name="timeout" min="3" max="60" value="<?=(int)$c['timeout']?>"></div>
            <div class="form-group"><label>Finestra (giorni, 0 = tutto)</label><input type="number" name="window_days" min="0" value="<?=(int)$c['window_days']?>"></div>
            <div class="form-group"><label>Prefisso ticket</label><input name="ticket_prefix" value="<?=h((string)$c['ticket_prefix'])?>" placeholder="WES_"></div>
          </div>
          <div class="form-group"><label>Query di estrazione (vuota = predefinita)</label>
            <textarea name="extract_sql" rows="6" style="font-family:monospace;font-size:11px" placeholder="<?=h(SocIngest::DEFAULT_SQL)?>"><?=h((string)$c['extract_sql'])?></textarea>
            <small style="color:var(--muted)">Solo SELECT. Le colonne devono avere i nomi delle intestazioni dell'export (alias `data evento`, `codice`, `evento`, `stato prima`, `stato dopo`, `autore`, `titolo`, e se disponibili `responsabile`, `incaricato`, `categoria`, `cliente`, `commessa`, `risoluzione`…). Il primo <code>?</code> riceve la data minima della finestra, il secondo il prefisso ticket (LIKE).</small></div>
          <label style="font-size:13px"><input type="checkbox" name="is_active" value="1" <?=$c['is_active'] ? 'checked' : ''?>> Connessione attiva</label>
          <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
            <button class="btn btn-primary btn-sm" name="action" value="db_save"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
            <button class="btn btn-sm" name="action" value="db_test"><i class="fa-solid fa-plug"></i> Test connessione</button>
            <button class="btn btn-sm" name="action" value="db_preview"><i class="fa-solid fa-eye"></i> Anteprima</button>
          </div>
          <small style="color:var(--muted)">Test e anteprima usano i valori del modulo (password vuota = quella salvata) senza salvarli.</small>
        </form>
      </details>
      <?php endif; ?>
      <?php if ($preview): ?>
        <div style="overflow-x:auto;margin-top:10px"><table class="data-table" style="width:100%;font-size:11px"><thead><tr><th>Data</th><th>Ticket</th><th>Tipo</th><th>Stato</th><th>Autore</th><th>Titolo</th></tr></thead><tbody>
          <?php foreach ($preview['rows'] as $r): ?><tr><td><?=h($r['event_at'] ?? '')?></td><td><?=h($r['ticket_code'] ?? '')?></td><td><?=h($r['event_kind'] ?? 'scartata')?></td>
            <td><?=h(($r['status_before'] ?? '') . ' → ' . ($r['status_after'] ?? ''))?></td><td><?=h($r['author_name'] ?? '')?></td><td><?=h(mb_strimwidth((string)($r['subject'] ?? ''), 0, 70, '…'))?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canRun): ?>
  <div class="card" style="padding:14px 16px;margin-bottom:14px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-sliders"></i> Impostazioni e pianificazione</h3>
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 12px;align-items:end">
      <?= Csrf::field() ?><input type="hidden" name="action" value="settings">
      <div class="form-group"><label>Risposta al cliente entro (ore)</label><input type="number" name="sla" min="1" max="240" value="<?=h($set['sla'])?>"></div>
      <div class="form-group"><label>Da presidiare dopo (ore senza risposta)</label><input type="number" name="presidio" min="1" max="720" value="<?=h($set['presidio'])?>"></div>
      <div class="form-group"><label>Stati di chiusura</label><input name="closed" value="<?=h($set['closed'])?>"></div>
      <label style="font-size:13px"><input type="checkbox" name="sync_enabled" value="1" <?=$set['sync'] === '1' ? 'checked' : ''?>> Sincronizzazione pianificata dal DB SOC</label>
      <div><button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Salva</button></div>
    </form>
    <pre style="font-size:11px;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:6px;overflow-x:auto;margin:10px 0 0">schtasks /Create /SC MINUTE /MO 30 /TN "PortalManager - Service SOC" /TR "\"<?=h(PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '\\php.exe' : PHP_BINARY)?>\" \"<?=h(__DIR__ . DIRECTORY_SEPARATOR . 'cron_soc_sync.php')?>\" --quiet" /RU SYSTEM</pre>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(460px,1fr));gap:14px;margin-bottom:14px">
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-user-check"></i> Abbinamento persone → dipendenti</h3>
      <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Automatico quando tutte le parole del nome SOC compaiono in un solo dipendente; le scelte manuali non vengono più modificate.</p>
      <form method="POST"><?= Csrf::field() ?><input type="hidden" name="action" value="map_people">
        <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Nome SOC</th><th style="text-align:right">Ticket</th><th>Dipendente</th><th></th></tr></thead><tbody>
        <?php foreach ($people as $p): ?><tr><td><?=h($p['name'])?></td><td style="text-align:right"><?=$n($p['ticket'])?></td>
          <td><input type="hidden" name="orig[<?=h($p['name'])?>]" value="<?=(int)$p['employee_id']?>"><select name="map[<?=h($p['name'])?>]" style="min-width:200px"><option value="0">— nessuno —</option>
            <?php foreach ($emps as $e): ?><option value="<?=$e['id']?>" <?=(int)$p['employee_id'] === (int)$e['id'] ? 'selected' : ''?>><?=h($e['n'])?><?=$e['status'] !== 'active' ? ' (' . h($e['status']) . ')' : ''?></option><?php endforeach; ?></select></td>
          <td><?=$p['is_manual'] ? $pill('manuale', '#2563eb') : ($p['employee_id'] ? $pill('auto', '#16a34a') : '')?></td></tr><?php endforeach; ?></tbody></table>
        <?php if ($people): ?><button class="btn btn-primary btn-sm" style="margin-top:8px">Salva abbinamenti</button><?php endif; ?></form>
    </div>
    <div class="card" style="padding:14px 16px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-building-circle-check"></i> Abbinamento clienti</h3>
      <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Automatico sul nome (es. «ESTAR - TOSCANA CENTRO» → «ESTAR CENTRO»); le scelte manuali restano.</p>
      <form method="POST"><?= Csrf::field() ?><input type="hidden" name="action" value="map_clients">
        <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Cliente SOC</th><th style="text-align:right">Ticket</th><th>Cliente portale</th><th></th></tr></thead><tbody>
        <?php foreach ($cmap as $p): ?><tr><td><?=h($p['name'])?></td><td style="text-align:right"><?=$n($p['ticket'])?></td>
          <td><input type="hidden" name="orig[<?=h($p['name'])?>]" value="<?=(int)$p['client_id']?>"><select name="map[<?=h($p['name'])?>]" style="min-width:200px"><option value="0">— nessuno —</option>
            <?php foreach ($clients as $id => $nm): ?><option value="<?=$id?>" <?=(int)$p['client_id'] === (int)$id ? 'selected' : ''?>><?=h($nm)?></option><?php endforeach; ?></select></td>
          <td><?=$p['is_manual'] ? $pill('manuale', '#2563eb') : ($p['client_id'] ? $pill('auto', '#16a34a') : '')?></td></tr><?php endforeach; ?></tbody></table>
        <?php if ($cmap): ?><button class="btn btn-primary btn-sm" style="margin-top:8px">Salva abbinamenti</button><?php endif; ?></form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card" style="padding:14px 16px;overflow-x:auto">
    <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid fa-list"></i> Registro import e sincronizzazioni</h3>
    <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Avvio</th><th>Fonte</th><th>Origine</th><th>Esito</th><th style="text-align:right">Righe</th><th style="text-align:right">Nuove</th><th style="text-align:right">Aggiornate</th><th>Dettaglio</th><th>Utente</th></tr></thead><tbody>
    <?php if (!$batches): ?><tr><td colspan="9" style="color:var(--muted)">Nessun import eseguito.</td></tr><?php endif; ?>
    <?php foreach ($batches as $b): ?><tr><td style="white-space:nowrap"><?=$dt($b['started_at'])?></td><td><?=$b['source'] === 'file' ? 'File' : 'DB SOC'?></td><td><?=h($b['origin'])?><br><small style="color:var(--muted)"><?=h($b['trigger_type'])?></small></td>
      <td><?=['ok' => $pill('OK', '#16a34a'), 'warn' => $pill('ATTENZIONE', '#d97706'), 'error' => $pill('ERRORE', '#dc2626'), 'running' => $pill('IN CORSO', '#2563eb')][$b['status']] ?? ''?></td>
      <td style="text-align:right"><?=$n($b['rows_read'])?></td><td style="text-align:right"><?=$n($b['rows_inserted'])?></td><td style="text-align:right"><?=$n($b['rows_updated'])?></td>
      <td><?=h($b['message'])?></td><td><?=h($b['utente'] ?? ($b['trigger_type'] === 'pianificata' ? 'pianificazione' : '—'))?></td></tr><?php endforeach; ?></tbody></table>
  </div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
