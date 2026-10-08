<?php
/**
 * service_soc.php — Gestione Commesse › Service SOC (v1.10.06)
 *
 * Rendicontazione del Security Operation Center sul pattern di Service Desk: i ticket del sistema di
 * gestione SOC (export XLSX/CSV o sincronizzazione dal DB SOC, istanza separata con lo stesso schema
 * del gestionale) aggregati con i dati del portale — moduli di intervento (ore, costi, ricavi, commesse),
 * anagrafica dipendenti e clienti, filtro globale Codice Contratto / PM Project.
 *
 * Schede: Cruscotto · Ticket · Team · Consuntivo attività SOC (v1.10.11) · Clienti e commesse.
 * v1.10.11 — Consuntivo: moduli di intervento dei componenti dell'Unità Organizzativa SOC per Tipologia di contratto e
 *            per operatore, con il modello di calcolo della Relazione di Servizio IT (ItServiceModel: classi di ore).
 * v1.10.07 — import da file, DB SOC, impostazioni e abbinamenti spostati in Sincronizzazione gestionale › SOC (pipeline unica, app/SocSync.php).
 * Permessi: view = consultazione; export = XLSX.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/SocModel.php');
require_once(__DIR__ . '/app/SocIngest.php');
require_once(__DIR__ . '/app/PmCharts.php');

if (!can('view', 'service_soc.php')) { redirect('manage_projects'); }
$u_id   = (int)$_SESSION['user_id'];
$TABS = ['cruscotto' => 'Cruscotto', 'ticket' => 'Ticket', 'team' => 'Team', 'consuntivo' => 'Consuntivo attività SOC', 'clienti' => 'Clienti e commesse'];
$tab = array_key_exists($_GET['tab'] ?? '', $TABS) ? $_GET['tab'] : 'cruscotto';

$soc = new SocModel($pdo);
require_once(__DIR__ . '/app/SocSync.php');
$syncUrl = url_safe('sync_commesse', ['tab' => 'soc']);
$canSync = can('view', 'sync_commesse.php');

// v1.10.07 — import, sincronizzazione DB, impostazioni e abbinamenti sono in Sincronizzazione gestionale › SOC (pipeline unica)
if ($_SERVER['REQUEST_METHOD'] === 'POST') { Csrf::verify(); redirect('sync_commesse', ['tab' => 'soc']); }

// ── dati ────────────────────────────────────────────────────────────────────
$f = $soc->normFilters($_GET);
$ready = $soc->ready();
$arch = $soc->archivio();
$vCtr = $soc->valoriContratti();
$ticketCode = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['ticket'] ?? '')));
$tk = $ticketCode !== '' ? $soc->ticket($ticketCode) : null;
if ($tk) $tab = 'ticket';


// v1.10.12 — report DOCX / XLSX / CSV / PDF dal solo filtro principale: generale, del componente filtrato o
// uno per componente (ZIP) quando non è attivo il filtro «Componente»
require_once(__DIR__ . '/app/SocReport.php');
$repFmt = (string)($_GET['rep'] ?? '');
if ($ready && isset(PmReport::FORMATS[$repFmt]) && can('export', 'service_soc.php')) {
    @set_time_limit(0);
    $base = 'service_soc_' . $f['from'] . '_' . $f['to'];
    if (!empty($_GET['rep_zip']) && $f['tec'] === '') {
        $items = SocReport::tecnici($soc, $f);
        write_log('Service SOC', 'info', 'Report per componente ' . strtoupper($repFmt) . ' (ZIP, ' . count($items) . ' componenti) ' . $f['from'] . ' → ' . $f['to'], $u_id);
        PmReport::bundle($repFmt, $items, fn($k) => SocReport::build($pdo, $soc, ['tec' => (string)$k] + $f, $repFmt), $base . '_componenti');
    }
    write_log('Service SOC', 'info', 'Report ' . strtoupper($repFmt) . ($f['tec'] !== '' ? ' componente ' . $f['tec'] : ' generale') . ' ' . $f['from'] . ' → ' . $f['to'], $u_id);
    SocReport::build($pdo, $soc, $f, $repFmt)->send($repFmt, $base . ($f['tec'] !== '' ? '_' . $f['tec'] : '_generale'));
}

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
    $r = [['Persona SOC', 'Dipendente', 'Ticket (incaricato)', 'Chiusi nel periodo', 'Aperti', 'Msg supporto', 'Note', 'Risposta media (h)', 'Ore moduli SOC', 'Ore moduli totali', 'Quota SOC %']];
    foreach ($soc->team($f) as $t) $r[] = [$t['nome'], $t['dipendente'], (int)$t['ticket'], (int)$t['chiusi'], (int)$t['aperti'], $t['msg_supporto'], $t['note'],
        $t['risposta_media_h'] !== null ? (float)$t['risposta_media_h'] : null, $t['ore_soc'], $t['ore_tot'], $t['quota_soc'] !== null ? round($t['quota_soc'], 1) : null];
    $w->addSheet('Team', $r);
    $tc = $soc->teamCategorie($f); $r = [array_merge(['Incaricato'], $tc['cats'], ['Totale'])];
    foreach ($tc['rows'] as $x) $r[] = array_merge([$x['nome']], array_map(fn($c) => (int)($x['c'][$c] ?? 0), $tc['cats']), [(int)$x['tot']]);
    $w->addSheet('Componenti x categoria', $r);
    $r = [['Commessa PM', 'Descrizione', 'Cliente', 'Moduli', 'Ticket', 'Ore', 'Costo (€)', 'Ricavo (€)', 'Tecnici']];
    foreach ($soc->commessePm($f) as $c) $r[] = [$c['codice'], $c['nome'], $c['cliente'], (int)$c['moduli'], (int)$c['ticket'], (float)$c['ore'], (float)$c['costo'], (float)$c['ricavo'], (int)$c['tecnici']];
    $w->addSheet('Commesse PM', $r);
    // v1.10.11 — consuntivo dei componenti dell'Unità Organizzativa SOC (modello Relazione di Servizio IT)
    require_once(__DIR__ . '/app/ItServiceModel.php');
    $itx = new ItServiceModel($pdo); [$cfx] = $soc->consuntivoFiltri($f, $itx);
    $ch = ['Interventi', 'Giornate-uomo', 'Ore totali', 'Ore ordinarie', 'Ore fuori orario', 'Ore reperibilità', 'N. interventi reperibilità', 'Ore non classificate', 'Ore a ricavo', 'Ore extra', 'Ore viaggio'];
    $cv = fn($r) => [(int)$r['interventi'], (int)$r['giornate_uomo'], (float)$r['ore'], (float)$r['ore_ordinarie'], (float)$r['ore_fuori_orario'], (float)$r['ore_reperibilita'],
                     (int)$r['reperibilita'], (float)$r['ore_non_classificate'], (float)$r['ore_ricavo'], (float)$r['ore_extra'], (float)$r['ore_viaggio']];
    $r = [array_merge(['Codice', 'Tipologia di contratto', 'Modello'], $ch)];
    foreach ($itx->aggrega(['gb' => ['linea_servizio', 'linea_label', 'modello_contratto']] + $cfx, 500) as $x) $r[] = array_merge([$x['linea_servizio'], $x['linea_label'], $x['modello_contratto']], $cv($x));
    $w->addSheet('Consuntivo tipologia', $r);
    $r = [array_merge(['Operatore'], $ch)];
    foreach ($itx->aggrega(['gb' => ['incaricato']] + $cfx, 500) as $x) $r[] = array_merge([$x['incaricato']], $cv($x));
    $w->addSheet('Consuntivo operatori', $r);
    $r = [array_merge(['Operatore', 'Tipologia di contratto'], $ch)];
    foreach ($itx->aggrega(['gb' => ['incaricato', 'linea_label']] + $cfx, 5000) as $x) $r[] = array_merge([$x['incaricato'], $x['linea_label']], $cv($x));
    $w->addSheet('Operatore x tipologia', $r);
    $w->addSheet('Filtri', [['Filtro', 'Valore'], ['Periodo', $f['from'] . ' → ' . $f['to']], ['Cliente', $f['cliente']], ['Commessa SOC', $f['commessa']],
        ['Categoria', implode(', ', $f['categoria'])], ['Componente', $f['tec']], ['Stato', $f['stato']], ['Esito', $f['esito']], ['Ricerca', $f['q']], ['Contratti', implode(', ', $f['contratti'])]]);
    write_log('Service SOC', 'info', 'Export XLSX ' . $f['from'] . ' → ' . $f['to'], $u_id);
    $w->download('service_soc_' . date('Ymd_Hi') . '.xlsx'); exit;
}

if ($ready) {
    $hl = $soc->headline($f);
    if ($tab === 'cruscotto') {
        $trend = $soc->trend($f, 12); $trG = $soc->trendGiornaliero($f);
        $bCat = $soc->breakdown($f, 'categoria'); $bEsito = array_values(array_filter($soc->breakdown($f, 'esito'), fn($r) => $r['k'] !== '(non indicato)'));
        $bStato = $soc->breakdown($f, 'stato'); $pres = $soc->presidio($f, 20); $trCat = $soc->trendCategorie($f, 12);
    }
    if ($tab === 'ticket' && !$tk) { $order = in_array($_GET['ord'] ?? '', ['recenti', 'vecchi', 'eventi', 'ore'], true) ? $_GET['ord'] : 'recenti'; $list = $soc->tickets($f, 500, $order); $nList = $soc->countTickets($f); }
    if ($tab === 'team') { $team = $soc->team($f); $tCat = $soc->teamCategorie($f); }
    if ($tab === 'consuntivo') {
        require_once(__DIR__ . '/app/ItServiceModel.php');
        $itm = new ItServiceModel($pdo);
        [$cf, $cInfo] = $soc->consuntivoFiltri($f, $itm);
        $cTot   = $itm->totali($cf);
        $cTip   = $itm->aggrega(['gb' => ['linea_servizio', 'linea_label', 'modello_contratto']] + $cf, 500);
        $cOp    = $itm->aggrega(['gb' => ['incaricato']] + $cf, 500);
        $cPiv   = $itm->aggrega(['gb' => ['incaricato', 'linea_label']] + $cf, 5000);
        $cTrend = $itm->andamento($cf);
        $cIds   = $itm->incaricatiDipendenti($cf);
    }
    if ($tab === 'clienti') { $cli = $soc->clienti($f); $bCom = $soc->breakdown($f, 'commessa'); $cpm = $soc->commessePm($f); }
}
$qs = function (array $over = []) use ($f, $tab) {
    $p = array_filter(['tab' => $tab, 'from' => $f['from'], 'to' => $f['to'], 'cliente' => $f['cliente'], 'commessa' => $f['commessa'], 'categoria' => $f['categoria'] ?: null,
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

// v1.10.10 — un solo blocco filtri (pattern Relazione di Servizio IT): il filtro automatico di footer.php
// (ListFilter::renderAuto) agganciava una seconda barra client-side (ricerca, filtri per colonna, viste, export)
// alla tabella più lunga, che filtrava solo le righe a video senza aggiornare indicatori, grafici ed export.
$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');
?>
<div style="margin-bottom:12px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-shield-halved"></i> Service SOC</h1>
  <p style="color:var(--muted);font-size:12px;margin-top:2px">
    Ticket del sistema di gestione SOC aggregati con i dati del portale (moduli di intervento, commesse, dipendenti, clienti).
    <?php if ($arch['ticket']): ?>Archivio: <strong><?=$n($arch['ticket'])?></strong> ticket, <?=$n($arch['eventi'])?> eventi dal <?=$dd($arch['dal'])?> al <?=$dd($arch['al'])?>
      · <?=$n($arch['con_moduli'])?> ticket con moduli di intervento.<?php endif; ?>
    <?php $lr = SocSync::setting($pdo, 'soc.last_run_at'); ?>
    Ultima sincronizzazione: <strong><?= $lr ? h(date('d/m/Y H:i', strtotime($lr))) : '—' ?></strong>
    <?php if ($canSync): ?>· <a href="<?=$syncUrl?>">Sincronizzazione gestionale › SOC</a><?php endif; ?>
  </p>
</div>
<?= $_SESSION['flash_msg'] ?? '' ?><?php unset($_SESSION['flash_msg']); ?>

<?php if (!$ready): ?>
  <div class="alert alert-warning"><strong>Nessun ticket SOC nel portale.</strong> Caricare l'export «lista eventi ticket» o configurare il DB SOC in
    <?= $canSync ? '<a href="' . $syncUrl . '">Sincronizzazione gestionale › SOC</a>' : 'Sincronizzazione gestionale › SOC' ?>.</div>
<?php else: ?>
<?php /* v1.10.26 — filtro globale: un solo componente (pannello «Filtri»), nessun blocco aggiuntivo */ ?>

<?php $attivi = ($f['cliente'] !== '') + ($f['commessa'] !== '') + (count($f['categoria']) > 0) + ($f['tec'] !== '') + ($f['stato'] !== '') + ($f['esito'] !== '') + ($f['q'] !== '') + (count($f['contratti']) > 0); ?>
<details class="pm-panel" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary><i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?=$attivi?></span><?php endif; ?>
    <span class="pm-hint"><?=$n($hl['attivi'])?> ticket nel periodo <?=$dd($f['from'])?> – <?=$dd($f['to'])?></span></summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?><input type="hidden" name="tab" value="<?=h($tab)?>">
      <div class="pm-group"><h4>Contratto</h4><div class="pm-grid-auto"><?= PmContractFilter::field($vCtr, $f['contratti'], 'ticket con moduli di intervento sulle commesse') ?></div></div>
      <div class="pm-group"><h4>Periodo</h4><div class="pm-grid-auto">
        <div class="form-group"><label>Dal</label><input type="date" name="from" value="<?=h($f['from'])?>"></div>
        <div class="form-group"><label>Al</label><input type="date" name="to" value="<?=h($f['to'])?>"></div></div></div>
      <div class="pm-group"><h4>Selezione</h4><div class="pm-grid-auto">
        <div class="form-group"><label>Categoria</label><select name="categoria[]" multiple class="pm-ms" data-placeholder="— tutte —" data-allow-clear>
          <?php foreach ($soc->valori('categoria') as $v): ?><option value="<?=h($v)?>" <?=in_array($v, $f['categoria'], true) ? 'selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div>
        <?php foreach (['cliente' => 'Cliente', 'commessa' => 'Commessa SOC', 'tec' => 'Componente (incaricato, responsabile o autore)', 'esito' => 'Esito'] as $k => $l): ?>
          <div class="form-group"><label><?=h($l)?></label><select name="<?=$k?>" class="pm-ms"><option value="">— tutti —</option>
            <?php foreach ($soc->valori($k) as $v): ?><option value="<?=h($v)?>" <?=$f[$k] === $v ? 'selected' : ''?>><?=h($v)?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="form-group"><label>Stato</label><select name="stato" class="pm-ms"><option value="">— tutti —</option>
          <?php foreach (['aperti' => 'Ancora aperti', 'chiusi' => 'Chiusi', 'presidio' => 'Da presidiare (attesa supporto)'] as $k => $l): ?><option value="<?=$k?>" <?=$f['stato'] === $k ? 'selected' : ''?>><?=h($l)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Ricerca (codice o titolo)</label><input type="text" name="q" value="<?=h($f['q'])?>" placeholder="WES_000000282, certificato…"></div>
      </div></div>
      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('service_soc', ['tab' => $tab, 'contratti_set' => 1])?>">Azzera</a>
        <?php if (can('export', 'service_soc.php')): ?><a class="btn btn-sm" href="<?=$qs(['export' => 'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> Dati XLSX</a><?php endif; ?>
      </div>
    </form>
  </div>
</details>

<?= PmReport::toolbar($qs, SocReport::tecnici($soc, $f), $f['tec'] !== '', 'tec', 'componente', can('export', 'service_soc.php')) ?>

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
  <?php foreach ($TABS as $k => $l): if (!$ready) continue; ?>
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

  <?php $catPal = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777', '#65a30d', '#ea580c', '#475569'];
        $catTop = array_slice($bCat, 0, 12); ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(520px,1fr));gap:14px;margin-bottom:14px">
    <div class="card" style="padding:14px 16px">
      <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-tags"></i> Ticket per categoria — periodo <?=$dd($f['from'])?> – <?=$dd($f['to'])?></h3>
      <?= $catTop ? PmCharts::groupedBars(array_map(fn($r) => mb_strimwidth($r['k'], 0, 18, '…'), $catTop), [
            ['label' => 'Ticket del periodo', 'color' => '#2563eb', 'values' => array_map(fn($r) => (int)$r['attivi'], $catTop)],
            ['label' => 'Chiusi', 'color' => '#16a34a', 'values' => array_map(fn($r) => (int)$r['chiusi'], $catTop)],
            ['label' => 'Ancora aperti', 'color' => '#f59e0b', 'values' => array_map(fn($r) => (int)$r['ancora_aperti'], $catTop)]], ['height' => 220]) : '<p style="font-size:12px;color:var(--muted)">Nessun dato.</p>' ?>
    </div>
    <div class="card" style="padding:14px 16px">
      <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-chart-column"></i> Ticket aperti per mese e categoria</h3>
      <?php $trSeries = array_slice($trCat['series'], 0, 9);
            if (count($trCat['series']) > 9) $trSeries[] = ['cat' => 'Altre', 'values' => array_map(fn(...$v) => array_sum($v), ...array_column(array_slice($trCat['series'], 9), 'values'))]; ?>
      <?= $trSeries ? PmCharts::groupedBars(array_map(fn($ym) => substr($ym, 5) . '/' . substr($ym, 2, 2), $trCat['months']),
            array_map(fn($x, $i) => ['label' => $x['cat'], 'color' => $catPal[$i % count($catPal)], 'values' => $x['values']], $trSeries, array_keys($trSeries)), ['height' => 220, 'stacked' => true]) : '<p style="font-size:12px;color:var(--muted)">Nessun dato.</p>' ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px;margin-bottom:14px">
    <?php foreach ([['Per categoria', $bCat, 'categoria'], ['Esito dei ticket chiusi', $bEsito, 'esito'], ['Stato attuale', $bStato, null]] as [$tt, $rows, $fk]): ?>
      <div class="card" style="padding:14px 16px;overflow-x:auto">
        <h3 style="font-size:14px;margin:0 0 8px"><?=h($tt)?></h3>
        <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th></th><th style="text-align:right">Ticket</th><th style="text-align:right">Chiusi</th><th style="text-align:right">Risposta media</th><th style="text-align:right">Ore moduli</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="5" style="color:var(--muted)">Nessun dato.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr>
          <td><?= $fk ? '<a href="' . $qs([$fk => $fk === 'categoria' ? [$r['k']] : $r['k']]) . '">' . h($r['k']) . '</a>' : $pill($r['k'], $colStato($r['k'])) ?></td>
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
                        'Responsabile' => $tk['owner_name'], 'Incaricato' => $tk['assignee_name'] . (($tk['assignee_source'] ?? '') === 'dedotto' ? ' (dedotto dai messaggi)' : '') . ($tk['dipendente'] ? ' → ' . $tk['dipendente'] : ''), 'Coda' => $tk['queue_name'], 'Casella' => $tk['mailbox'],
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
    <?php $tm = array_values(array_filter($team, fn($t) => mb_strtolower($t['nome']) !== 'non assegnato' && empty($t['senza_ticket']))); ?>
    <?= PmCharts::groupedBars(array_map(fn($t) => explode(' ', $t['nome'])[0], $tm), [
          ['label' => 'Ticket del periodo', 'color' => '#2563eb', 'values' => array_map(fn($t) => (int)$t['ticket'], $tm)],
          ['label' => 'Chiusi nel periodo', 'color' => '#16a34a', 'values' => array_map(fn($t) => (int)$t['chiusi'], $tm)],
          ['label' => 'Messaggi al cliente', 'color' => '#f59e0b', 'values' => array_map(fn($t) => (int)$t['msg_supporto'], $tm)]], ['height' => 210]) ?>
  </div>
  <div class="card" style="padding:14px 16px;overflow-x:auto">
    <h3 style="font-size:14px;margin:0 0 4px">Il team SOC e i dati del portale</h3>
    <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Incaricato = dalla sorgente (export) o, se la sorgente non lo riporta (DB SOC), dedotto dai messaggi: il primo operatore che risponde o scrive una nota sul ticket. Seguiti = ticket su cui ha scritto risposte o note. Ore dai moduli di intervento del dipendente abbinato nel periodo: «SOC» = moduli che riportano un ticket SOC; «totali» = tutti i suoi moduli. Unità = Unità Organizzativa del dipendente (i tecnici del servizio sono assegnati all'unità SOC dalla sincronizzazione); abbinamenti in Sincronizzazione gestionale › SOC.</p>
    <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Componente SOC</th><th>Dipendente</th><th>Unità</th><th style="text-align:right" title="Ticket di cui è incaricato (fra parentesi: incaricato dedotto dai messaggi)">Incaricato</th><th style="text-align:right" title="Ticket su cui ha scritto risposte o note">Seguiti</th><th style="text-align:right">Chiusi</th><th style="text-align:right">Aperti</th>
      <th style="text-align:right">Msg supporto</th><th style="text-align:right">Note</th><th style="text-align:right">Risposta media</th><th style="text-align:right">Ore SOC</th><th style="text-align:right">Ore totali</th><th style="text-align:right">Quota SOC</th></tr></thead><tbody>
    <?php foreach ($team as $t): ?><tr>
      <td><?= !empty($t['senza_ticket']) ? '<span style="color:var(--muted)">unità SOC, nessun ticket nel periodo</span>' : '<a href="' . $qs(['tec' => $t['nome'], 'tab' => 'ticket']) . '">' . h($t['nome']) . '</a>' ?></td>
      <td><?= $t['dipendente'] ? h($t['dipendente']) : '<span style="color:var(--muted)">non abbinato</span>' ?></td>
      <td><?= $t['in_uo_soc'] ? $pill('SOC', '#7c3aed') : ($t['unita'] ? h($t['unita']) : '<span style="color:var(--muted)">—</span>') ?></td>
      <td style="text-align:right"><?=$n($t['ticket'])?><?= !empty($t['dedotti']) ? ' <span style="color:var(--muted);font-size:10px" title="incaricato dedotto dai messaggi">(' . $n($t['dedotti']) . ' ded.)</span>' : '' ?></td>
      <td style="text-align:right"><?=$n($t['seguiti'] ?? 0)?></td><td style="text-align:right"><?=$n($t['chiusi'])?></td><td style="text-align:right"><?=$n($t['aperti'])?></td>
      <td style="text-align:right"><?=$n($t['msg_supporto'])?></td><td style="text-align:right"><?=$n($t['note'])?></td>
      <td style="text-align:right"><?=$t['risposta_media_h'] === null ? '—' : $n1($t['risposta_media_h']) . ' h'?></td>
      <td style="text-align:right"><?=$n1($t['ore_soc'])?></td><td style="text-align:right"><?=$n1($t['ore_tot'])?></td><td style="text-align:right"><?=$t['quota_soc'] === null ? '—' : $n1($t['quota_soc']) . '%'?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <?php if ($tCat['rows']): $cats = array_slice($tCat['cats'], 0, 12); $altre = array_slice($tCat['cats'], 12); ?>
  <div class="card" style="padding:14px 16px;overflow-x:auto;margin-top:14px">
    <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-tags"></i> Ticket per componente e categoria</h3>
    <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Ticket del periodo per incaricato e categoria (tt_ticket → tt_category), con gli stessi filtri principali. Clic sul numero → elenco dei ticket.</p>
    <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Incaricato</th>
      <?php foreach ($cats as $c): ?><th style="text-align:right"><a href="<?=$qs(['categoria' => [$c]])?>"><?=h($c)?></a></th><?php endforeach; ?>
      <?php if ($altre): ?><th style="text-align:right">Altre</th><?php endif; ?><th style="text-align:right">Totale</th></tr></thead><tbody>
      <?php foreach ($tCat['rows'] as $r): ?><tr><td><?=h($r['nome'])?></td>
        <?php foreach ($cats as $c): $v = $r['c'][$c] ?? 0; ?><td style="text-align:right"><?= $v ? '<a href="' . $qs(['tab' => 'ticket', 'categoria' => [$c], 'tec' => $r['nome'][0] === '(' ? null : $r['nome']]) . '">' . $n($v) . '</a>' : '<span style="color:#cbd5e1">·</span>' ?></td><?php endforeach; ?>
        <?php if ($altre): ?><td style="text-align:right"><?=$n(array_sum(array_intersect_key($r['c'], array_flip($altre))))?></td><?php endif; ?>
        <td style="text-align:right;font-weight:700"><?=$n($r['tot'])?></td></tr><?php endforeach; ?>
      <tr style="font-weight:700;background:#f8fafc"><td>Totale</td><?php foreach ($cats as $c): ?><td style="text-align:right"><?=$n($tCat['tot'][$c])?></td><?php endforeach; ?>
        <?php if ($altre): ?><td style="text-align:right"><?=$n(array_sum(array_intersect_key($tCat['tot'], array_flip($altre))))?></td><?php endif; ?><td style="text-align:right"><?=$n(array_sum($tCat['tot']))?></td></tr>
    </tbody></table>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'consuntivo' && $ready) include __DIR__ . '/app/soc_consuntivo_panel.php'; ?>

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
      <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Commessa SOC</th><th style="text-align:right" title="Ticket di cui è incaricato (fra parentesi: incaricato dedotto dai messaggi)">Incaricato</th><th style="text-align:right" title="Ticket su cui ha scritto risposte o note">Seguiti</th><th style="text-align:right">Chiusi</th><th style="text-align:right">Ancora aperti</th><th style="text-align:right">Ore moduli</th></tr></thead><tbody>
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

<?php require_once('footer.php'); ?>
