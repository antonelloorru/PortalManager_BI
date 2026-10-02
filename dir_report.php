<?php
/**
 * dir_report.php — Report direzionale commesse e schede commerciale (v1.8.94)
 *
 * Una sola pagina per due destinazioni: senza `agente` mostra il quadro
 * complessivo con il confronto fra agenti; con `agente` diventa la scheda
 * personale, ristretta al suo perimetro e senza il confronto.
 *
 * Due pagine separate avrebbero significato due serie di query che divergono
 * alla prima modifica, e due documenti con numeri diversi sulla stessa commessa.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/DirModel.php');
require_once(__DIR__ . '/app/AlertEngine.php');

if (!can('view', 'dir_report.php')) { redirect('manage_projects'); }
$u_id = (int)$_SESSION['user_id'];

$dm = new DirModel($pdo);
$f  = $dm->normFilters($_GET);
$ag = $f['agente'];

$pronto = true; $errore = '';
try {
    $q     = $dm->quadro($f);
    $att   = $dm->attenzione($f, 200);
    $cmm   = $dm->commesse($f, 500);
    $trend = $dm->andamento($f, 12);
    $gMod  = $dm->perDimensione($f, 'modello_label', 10);
    $gLin  = $dm->perDimensione($f, 'linea_servizio', 10);
    $gSta  = $dm->perDimensione($f, 'stato');
    $agenti = $ag === '' ? $dm->agenti($f) : [];
    $per   = $ag !== '' ? $dm->perimetro($ag, $f) : [];
    $vAg   = $dm->elencoAgenti();
    $vSta  = $dm->valori('stato');
    $vLin  = $dm->valori('linea_servizio');
    $vCtr  = $dm->valoriContratti();   // v1.9.78 — filtro globale contratto
    $comp  = $dm->competenza($f);      // v1.9.94 — ordini cliente per competenza (pro-rata mensile)
    // v1.8.95 — stato dell'alerting, mostrato solo nella vista direzionale:
    // e' configurazione di sistema, non informazione operativa per l'agente
    $alert = $ag === '' ? (new AlertEngine($pdo))->stato() : [];
} catch (Throwable $e) {
    $pronto = false; $errore = $e->getMessage();
    $q = $per = []; $att = $cmm = $trend = $gMod = $gLin = $gSta = $agenti = [];
    $vAg = $vSta = $vLin = []; $alert = []; $vCtr = [];
    $comp = ['periodo' => ['da' => null, 'a' => null], 'anni' => [], 'commesse' => [], 'totale' => 0];
}

$COL = ['#2563eb','#16a34a','#f59e0b','#dc2626','#7c3aed','#0891b2','#db2777','#65a30d',
        '#ea580c','#0d9488','#9333ea','#4f46e5'];
$colPrio = [1 => '#dc2626', 2 => '#ea580c', 3 => '#f59e0b', 4 => '#0891b2', 5 => '#94a3b8'];

$n  = fn($v) => number_format((float)$v, 0, ',', '.');
$n1 = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');
// v1.9.94 — unità sempre esplicite: € importi, h ore, gg giornate
$eur  = fn($v) => number_format((float)$v, 0, ',', '.') . ' €';
$eur2 = fn($v) => number_format((float)$v, 2, ',', '.') . ' €';
$hrs  = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', '.') . ' h';
$gg   = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', '.') . ' gg';
$fidoBadge = function (array $r): string {
    if (empty($r['fido'])) return '<span style="color:#cbd5e1" title="nessun fido">—</span>';
    $t = 'Fido' . ((float)($r['fido_valore'] ?? 0) != 0 ? ' su valore: ' . number_format((float)$r['fido_valore'], 0, ',', '.') . ' €' : '')
       . ((float)($r['fido_costi'] ?? 0) != 0 ? ' · su costi: ' . number_format((float)$r['fido_costi'], 0, ',', '.') . ' €' : '');
    return '<span title="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '" style="background:#fef3c7;color:#92400e;border-radius:6px;padding:1px 6px;font-size:10px;font-weight:700">FIDO</span>';
};
// v1.9.95 — Link SP e Scheda Progetto, come in Commesse / Progetti
$linkSp = fn(array $r): string => !empty($r['external_link'])
    ? '<a href="' . htmlspecialchars((string)$r['external_link'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" title="Apri sul gestionale (SharePoint)" style="white-space:nowrap;font-size:10px;font-weight:700"><i class="fa-solid fa-arrow-up-right-from-square"></i> SP</a>'
    : '<span style="color:#cbd5e1">—</span>';
$schedaPrj = fn(array $r): string => !empty($r['project_id'])
    ? '<a class="btn btn-sm btn-blue" style="white-space:nowrap;font-size:10px;padding:2px 7px" href="' . url_safe('project_dashboard', ['id' => (int)$r['project_id']]) . '" title="Apri la scheda del progetto"><i class="fa-solid fa-chart-line"></i> Scheda Progetto</a>'
    : '<span style="color:#cbd5e1">—</span>';
$periodoTxt = ($f['from'] !== '' || $f['to'] !== '')
    ? 'dal ' . ($f['from'] !== '' ? date('d/m/Y', strtotime($f['from'])) : 'inizio') . ' al ' . ($f['to'] !== '' ? date('d/m/Y', strtotime($f['to'])) : 'fine')
    : 'intera durata delle commesse';

// ── export XLSX ─────────────────────────────────────────────────────────────
if ($pronto && ($_GET['export'] ?? '') === 'xlsx') {
    require_once(__DIR__ . '/app/XlsxWriter.php');
    $w = new XlsxWriter();

    $r1 = [['Commessa','Denominazione','Cliente','Agente','Stato','Modello','Linea',
            'Valore','Costo','Margine','Margine %','Ore','Consumo %','Avanzamento %',
            'Divergenza %','Giorni a scadenza','Giorni senza movimenti','Fido','Fido su valore €','Fido su costi €']];
    foreach ($cmm as $c) $r1[] = [$c['commessa'], $c['denominazione'], $c['cliente'],
        $c['agente'], $c['stato'], $c['modello_label'], $c['linea_servizio'],
        $c['valore'], $c['costo'], $c['margine'], $c['margine_pct'], $c['ore'],
        $c['consumo_valore_pct'], $c['avanzamento_pct'], $c['divergenza_pct'],
        $c['giorni_a_scadenza'], $c['giorni_senza_movimenti'], (int)($c['fido'] ?? 0) ? 'sì' : 'no',
        $c['fido_valore'] ?? null, $c['fido_costi'] ?? null];
    $w->addSheet('Commesse', $r1);

    // v1.9.94 — competenza pro-rata mensile degli ordini cliente
    $rc1 = [['Anno', 'Valore di competenza €', 'Commesse']];
    foreach ($comp['anni'] as $y => $x) $rc1[] = [(int)$y, $x['valore'], (int)$x['commesse']];
    $rc1[] = ['Totale periodo', $comp['totale'], count($comp['commesse'])];
    $w->addSheet('Competenza per anno', $rc1);
    $anniC = array_keys($comp['anni']);
    $hdr = ['Commessa','Denominazione','Cliente','Agente','Stato','Fido','Inizio','Fine','Importo ordini €'];
    foreach ($anniC as $y) { $hdr[] = "$y mesi"; $hdr[] = "$y €"; }
    $hdr[] = 'Valore nel periodo €';
    $rc2 = [$hdr];
    foreach ($comp['commesse'] as $c) {
        $row = [$c['commessa'], $c['denominazione'], $c['cliente'], $c['agente'], $c['stato'], $c['fido'] ? 'sì' : 'no',
                $c['inizio'], $c['fine'], round($c['importo'], 2)];
        foreach ($anniC as $y) { $row[] = (int)($c['anni'][$y]['mesi'] ?? 0); $row[] = round((float)($c['anni'][$y]['valore'] ?? 0), 2); }
        $row[] = round($c['valore_periodo'], 2);
        $rc2[] = $row;
    }
    $w->addSheet('Competenza per commessa', $rc2);
    $rc3 = [['Commessa','Fonte','Ordine','Data ordine','Importo €','Inizio competenza','Fine competenza','Mesi totali','Quota mensile €','Anno','Mesi nell\'anno','Dal','Al','Valore €']];
    foreach ($comp['commesse'] as $c) foreach ($c['ordini'] as $o) foreach ($o['anni'] as $y => $x)
        $rc3[] = [$c['commessa'], $o['fonte'], $o['codice'], $o['data'], $o['importo'], substr($o['inizio'], 0, 7), substr($o['fine'], 0, 7),
                  $o['mesi_totali'], $o['quota_mensile'], (int)$y, $x['mesi'], $x['da'], $x['a'], $x['valore']];
    $w->addSheet('Competenza per ordine', $rc3);

    $r2 = [['Priorità','Motivo','Commessa','Cliente','Agente','Valore',
            'Consumo %','Avanzamento %','Divergenza %','Margine %','Giorni a scadenza']];
    foreach ($att as $x) $r2[] = [(int)$x['priorita'], $x['motivo'], $x['commessa'],
        $x['cliente'], $x['agente'], $x['valore'], $x['consumo_valore_pct'],
        $x['avanzamento_pct'], $x['divergenza_pct'], $x['margine_pct'], $x['giorni_a_scadenza']];
    $w->addSheet('Da presidiare', $r2);

    if ($agenti) {
        $r3 = [['Agente','Commesse','Aperte','Clienti','Valore','Margine','Margine %',
                'Ore','Sforate','Divergenti','In scadenza','Ferme']];
        foreach ($agenti as $a) $r3[] = [$a['agente'], (int)$a['commesse'], (int)$a['aperte'],
            (int)$a['clienti'], $a['valore'], $a['margine'], $a['margine_pct'], $a['ore'],
            (int)$a['sforate'], (int)$a['divergenti'], (int)$a['in_scadenza'], (int)$a['ferme']];
        $w->addSheet('Agenti', $r3);
    }

    $r4 = [['Mese','Commesse movimentate','Interventi','Ore','Costo']];
    foreach ($trend as $t) $r4[] = [$t['ym'], (int)$t['commesse'], (int)$t['interventi'],
        $t['ore'], $t['costo']];
    $w->addSheet('Andamento', $r4);

    // v1.9.78 — perimetro dell'estrazione
    $rf = [['Parametro', 'Valore'], ['Perimetro', ['aperte' => 'solo commesse aperte', 'tutte' => 'tutte le commesse', 'ricavo' => 'solo a ricavo'][$f['solo']]]];
    if ($f['contratti']) $rf[] = ['Contratto / PM Project', implode(', ', array_map(fn($v) => PmContractFilter::label($v, $vCtr), $f['contratti']))];
    if ($ag !== '')        $rf[] = ['Agente', $ag];
    foreach (['stato' => 'Stato', 'linee' => 'Linee', 'aziende' => 'Aziende'] as $k => $l) if ($f[$k]) $rf[] = [$l, implode(', ', $f[$k])];
    if ($f['q'] !== '')       $rf[] = ['Ricerca', $f['q']];
    if ($f['cliente'] !== '') $rf[] = ['Cliente', $f['cliente']];
    $rf[] = ['Periodo', $periodoTxt];
    $rf[] = ['Generato il', date('d/m/Y H:i')];
    $w->addSheet('Filtri', $rf);

    $suff = $ag !== '' ? '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $ag) : '';
    write_log('Projects', 'info', 'Export report direzionale' . ($ag !== '' ? " ($ag)" : ''), $u_id);
    $w->download("report_commesse{$suff}_" . date('Y-m-d') . ".xlsx");
    exit;
}

/** Barre orizzontali: le etichette sono nomi e denominazioni, illeggibili ruotate. */
$barre = function (array $dati, string $campo, array $colori, int $w = 460) use ($n) {
    if (!$dati) return '';
    $max = 0.01; foreach ($dati as $d) $max = max($max, (float)$d[$campo]);
    $rh = 20; $h = count($dati) * $rh + 6; $lw = 165; $bw = $w - $lw - 78;
    $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;font-family:inherit">';
    foreach ($dati as $i => $d) {
        $y = $i * $rh + 3;
        $l = (float)$d[$campo] / $max * $bw;
        $c = $colori[$i % count($colori)] ?? '#2563eb';
        $o .= '<text x="' . ($lw - 6) . '" y="' . ($y + 11) . '" text-anchor="end" font-size="10" fill="#334155">'
            . htmlspecialchars(mb_strimwidth((string)$d['voce'], 0, 26, '…')) . '</text>'
            . '<rect x="' . $lw . '" y="' . ($y + 2) . '" width="' . round(max(1, $l), 1)
            . '" height="12" fill="' . $c . '" rx="2"><title>' . htmlspecialchars((string)$d['voce'])
            . ': ' . $n($d[$campo]) . ' €</title></rect>'
            . '<text x="' . ($lw + $l + 5) . '" y="' . ($y + 12) . '" font-size="9" fill="#64748b">'
            . $n($d[$campo]) . ' €</text>';
    }
    return $o . '</svg>';
};

// ── report di stampa ────────────────────────────────────────────────────────
if ($pronto && ($_GET['print'] ?? '') === '1') {
    write_log('Projects', 'info', 'Stampa report direzionale' . ($ag !== '' ? " ($ag)" : ''), $u_id);
    include(__DIR__ . '/app/dir_report_print.php');
    exit;
}

// v1.9.91 — un solo blocco filtri (pannello della pagina, server-side): niente barra automatica di
// footer.php (ListFilter::renderAuto), che filtrava solo le righe a video di una tabella senza
// aggiornare totali, grafici, stampa ed export e non era sincronizzata con i filtri della pagina.
$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');

$qs = function (array $over = []) use ($f) {
    $p = ['agente' => $f['agente'], 'solo' => $f['solo'],
          'q' => $f['q'], 'cliente' => $f['cliente'], 'from' => $f['from'], 'to' => $f['to']];
    foreach (['stato','linee','aziende','contratti'] as $k) if (!empty($f[$k])) $p[$k] = implode(',', $f[$k]);
    return url_safe('dir_report', array_merge(array_filter($p, fn($v) => $v !== '' && $v !== []), $over));
};
?>

<div style="margin-bottom:14px">
  <h1 style="font-size:20px;font-weight:800">
    <i class="fa-solid fa-chart-pie"></i>
    <?= $ag !== '' ? 'Scheda commerciale — ' . h($ag) : 'Report direzionale commesse' ?>
  </h1>
  <?php if (class_exists('PmSnapshot')) echo PmSnapshot::badge(); ?>
  <?php if ($ag !== '' && $per): ?>
    <p style="color:var(--muted);font-size:12px;margin-top:2px">
      Perimetro: <strong><?=$n($per['suo'])?> commesse</strong> su <?=$n($per['tot'])?>
      (<?=$n1($per['pct_commesse'])?>%) — <strong><?=$n1($per['pct_valore'])?>%</strong>
      del valore di portafoglio. I dati di questa scheda riguardano solo le sue commesse.
    </p>
  <?php else: ?>
    <p style="color:var(--muted);font-size:12px;margin-top:2px">
      Andamento del portafoglio: margini, scostamenti e criticità.
      Il margine è calcolato sulle sole commesse a ricavo.
    </p>
  <?php endif; ?>
</div>

<?= PmContractFilter::banner($f['contratti'], $vCtr, $qs(['contratti' => null, 'contratti_set' => 1]),
        $f['solo'] === 'aperte' ? 'perimetro: solo commesse aperte' : '') ?>
<?php if (!$pronto): ?>
  <div class="alert alert-warning"><strong>Dati non disponibili.</strong>
    Eseguire la migration v1.8.94.
    <div style="font-size:11px;color:var(--muted);margin-top:4px"><?=h($errore)?></div></div>
  <?php require_once('footer.php'); exit; ?>
<?php endif; ?>

<?php // v1.9.8 — pannello uniformato al template di Commesse/Progetti:
      // <details> a scomparsa, gruppi con intestazione, griglia adattiva.
      //
      // `open` se un filtro e' attivo: un pannello chiuso che nasconde filtri
      // attivi fa credere di guardare tutti i dati. ?>
<?php
  $attivi = ($ag !== '') + ($f['q'] !== '') + ($f['cliente'] !== '')
          + (count($f['stato']) > 0) + (count($f['linee']) > 0)
          + (count($f['aziende']) > 0) + ($f['solo'] !== 'aperte') + (count($f['contratti']) > 0)
          + ($f['from'] !== '' || $f['to'] !== '');
?>
<details class="pm-panel" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary>
    <i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?=$attivi?></span><?php endif; ?>
    <span class="pm-hint"><?=$n($q['commesse'] ?? 0)?> commesse nel perimetro</span>
  </summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?>

      <div class="pm-group">
        <h4>Contratto</h4>
        <div class="pm-grid-auto">
          <?= PmContractFilter::field($vCtr, $f['contratti'], 'vale anche per Relazione IT, Service Desk, DGB') ?>
        </div>
      </div>

      <div class="pm-group">
        <h4>Periodo <span class="pm-multi">(commesse attive nel periodo · andamento · competenza)</span></h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Data Inizio</label>
            <input type="date" name="from" value="<?=h($f['from'])?>"></div>
          <div class="form-group"><label>Data Fine</label>
            <input type="date" name="to" value="<?=h($f['to'])?>"></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Ricerca</h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Cerca ovunque</label>
            <input type="text" name="q" value="<?=h($f['q'])?>"
                   placeholder="codice, denominazione, cliente"></div>
          <div class="form-group"><label>Cliente</label>
            <input type="text" name="cliente" value="<?=h($f['cliente'])?>"
                   placeholder="parte della ragione sociale"></div>
          <div class="form-group"><label>Agente commerciale</label>
            <select name="agente"><option value="">— tutti, vista direzionale —</option>
              <?php foreach ($vAg as $a): ?>
                <option value="<?=h($a)?>" <?=$ag===$a?'selected':''?>><?=h($a)?></option>
              <?php endforeach; ?></select></div>
          <div class="form-group"><label>Perimetro</label>
            <select name="solo">
              <option value="aperte" <?=$f['solo']==='aperte'?'selected':''?>>Solo commesse aperte</option>
              <option value="tutte"  <?=$f['solo']==='tutte'?'selected':''?>>Tutte, chiuse comprese</option>
              <option value="ricavo" <?=$f['solo']==='ricavo'?'selected':''?>>Solo commesse a ricavo</option>
            </select></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Classificazione</h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Stato operativo <span class="pm-multi">(multipla)</span></label>
            <select name="stato[]" multiple size="3">
              <?php foreach ($vSta as $v): ?>
                <option value="<?=h($v)?>" <?=in_array($v,$f['stato'],true)?'selected':''?>><?=h($v)?></option>
              <?php endforeach; ?></select></div>
          <div class="form-group"><label>Linea di servizio <span class="pm-multi">(multipla)</span></label>
            <select name="linee[]" multiple size="3">
              <?php foreach ($vLin as $v): ?>
                <option value="<?=h($v)?>" <?=in_array($v,$f['linee'],true)?'selected':''?>><?=h($v)?></option>
              <?php endforeach; ?></select></div>
          <div class="form-group"><label>Azienda esecutrice <span class="pm-multi">(multipla)</span></label>
            <select name="aziende[]" multiple size="3">
              <?php foreach ($dm->valori('azienda') as $v): ?>
                <option value="<?=h($v)?>" <?=in_array($v,$f['aziende'],true)?'selected':''?>><?=h($v)?></option>
              <?php endforeach; ?></select></div>
        </div>
      </div>

      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('dir_report', ['contratti_set' => 1])?>">Azzera</a>
        <a class="btn btn-sm" href="<?=$qs(['export'=>'xlsx'])?>">
          <i class="fa-solid fa-file-excel"></i> XLSX</a>
        <a class="btn btn-sm" href="<?=$qs(['print'=>'1'])?>" target="_blank">
          <i class="fa-solid fa-print"></i> <?= $ag !== '' ? 'Scheda di stampa' : 'Report di stampa' ?></a>
      </div>
    </form>
  </div>
</details>

<?php // ── v1.8.95 — stato dell'alerting ─────────────────────────────────── ?>
<?php if ($alert): ?>
  <?php
    $attivo = !empty($alert['attivo']);
    $prova  = !empty($alert['dry_run']);
    $colA   = !$attivo ? '#94a3b8' : ($prova ? '#f59e0b' : '#16a34a');
    $stato  = !$attivo ? 'disattivato' : ($prova ? 'in prova' : 'attivo');
    $senzaDest = (int)($alert['destinatari'] ?? 0) === 0;
  ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid <?=$colA?>">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-bell"></i> Alerting commesse</span>
      <span style="background:<?=$colA?>;color:#fff;border-radius:10px;padding:2px 10px;
            font-size:11px;font-weight:700;margin-left:8px"><?=h($stato)?></span>
      <span style="font-size:11px;color:var(--muted);margin-left:auto">
        mittente: <strong><?=h($alert['alias_nome'] ?? '')?></strong>
        &lt;<?=h($alert['alias'] ?? '(non impostato)')?>&gt;</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
      <?php foreach ([
        ['Regole attive', $alert['regole_attive'] ?? 0, '#334155'],
        ['Condizioni rilevabili', $alert['da_rilevare'] ?? 0, '#2563eb'],
        ['Eventi aperti', $alert['eventi_aperti'] ?? 0, '#f59e0b'],
        ['In attesa di invio', $alert['in_attesa_invio'] ?? 0,
         ((int)($alert['in_attesa_invio'] ?? 0)) > 0 ? '#ea580c' : '#94a3b8'],
        ['Inviate 7 gg', $alert['inviate_7gg'] ?? 0, '#16a34a'],
        ['Errori 7 gg', $alert['errori_7gg'] ?? 0,
         ((int)($alert['errori_7gg'] ?? 0)) > 0 ? '#dc2626' : '#94a3b8'],
      ] as [$l, $v, $c]): ?>
        <div style="text-align:center;padding:10px;background:#f8fafc;border-radius:8px">
          <div style="font-size:17px;font-weight:800;color:<?=$c?>"><?=$n($v)?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($senzaDest): ?>
      <div class="alert alert-warning" style="font-size:11px;margin-top:10px;margin-bottom:0">
        <strong>Nessun destinatario configurato.</strong> Gli alert vengono rilevati ma non
        possono essere inviati: servono gli indirizzi degli agenti in
        <code>cm_alert_recipients</code> e quello del direttore in
        <code>alert_director_email</code>.
      </div>
    <?php elseif ((int)($alert['destinatari'] ?? 0) < (int)($alert['agenti_totali'] ?? 0)): ?>
      <p style="font-size:11px;color:var(--muted);margin:8px 0 0">
        <strong><?=$n($alert['destinatari'])?> destinatari configurati</strong> su
        <?=$n($alert['agenti_totali'])?> agenti: gli alert degli agenti senza indirizzo
        arrivano solo al direttore.
      </p>
    <?php endif; ?>

    <?php if ($attivo && $prova): ?>
      <p style="font-size:11px;color:#b45309;margin:8px 0 0">
        <strong>Modalità prova attiva</strong>: le segnalazioni vengono registrate come
        <em>simulate</em> senza essere spedite. Impostare <code>alert_dry_run</code> a 0
        per l'invio reale.
      </p>
    <?php endif; ?>

    <p style="font-size:11px;color:var(--muted);margin:8px 0 0">
      Ogni segnalazione viene inviata <strong>una sola volta per livello di soglia</strong>:
      una commessa che resta all'85% non genera un promemoria quotidiano. Un nuovo invio avviene
      solo se la situazione peggiora di fascia — dall'80% al 90% sì, dall'85% all'86% no.
    </p>
  </div>
<?php endif; ?>

<?php // ── portafoglio ──────────────────────────────────────────────────── ?>
<div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:6px">PORTAFOGLIO</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
  <?php foreach ([
    ['Commesse', $n($q['commesse']), '#334155',
     $n($q['aperte']).' aperte · '.$n($q['a_ricavo']).' a ricavo · '.$n($q['con_fido'] ?? 0).' con fido'],
    ['Valore contrattato', $eur($q['valore']), '#2563eb', $n($q['clienti']).' clienti'],
    ['Margine', $eur($q['margine']), ((float)($q['margine_pct'] ?? 0)) < 20 ? '#dc2626' : '#16a34a',
     $q['margine_pct'] !== null ? $n1($q['margine_pct']).'% sul contrattato' : ''],
    ['Costo del lavoro', $eur($q['costo']), '#7c3aed',
     $q['costo_orario'] !== null ? $n1($q['costo_orario']).' €/h su '.$hrs($q['ore']) : ''],
  ] as [$l,$v,$c,$s]): ?>
    <div class="card" style="text-align:center;padding:13px;border-top:3px solid <?=$c?>">
      <div style="font-size:20px;font-weight:800;color:<?=$c?>"><?=$v?></div>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
      <div style="font-size:10px;color:var(--muted)"><?=h($s)?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php // ── rischio: solo commesse aperte ────────────────────────────────── ?>
<div style="font-size:12px;font-weight:700;color:#334155;margin-bottom:6px">
  RISCHIO <span style="font-weight:400;color:var(--muted)">— solo commesse aperte:
  una commessa chiusa ha una storia, non un rischio</span></div>
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px">
  <?php foreach ([
    ['Budget sforato', $q['sforate'], '#dc2626', 'consumo oltre il contrattato'],
    ['Prossime al limite', $q['prossime'], '#ea580c', 'consumo fra 75% e 100%'],
    ['Consumo in anticipo', $q['divergenti'], '#f59e0b', 'oltre 20 punti sui tempi'],
    ['In scadenza 30 gg', $q['in_scadenza'], '#0891b2', 'fine contratto vicina'],
    ['Ferme da 90 gg', $q['ferme'], '#94a3b8', 'nessun intervento'],
  ] as [$l,$v,$c,$s]): ?>
    <div class="card" style="text-align:center;padding:11px;border-left:4px solid <?=$c?>">
      <div style="font-size:19px;font-weight:800;color:<?=(int)$v>0?$c:'#94a3b8'?>"><?=$n($v)?></div>
      <div style="font-size:10px;font-weight:700;color:#334155"><?=h($l)?></div>
      <div style="font-size:9px;color:var(--muted)"><?=h($s)?></div>
    </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
  <div class="card"><div class="card-header"><span class="card-title">Valore per modello di contratto</span></div>
    <?= $barre($gMod, 'valore', $COL) ?></div>
  <div class="card"><div class="card-header"><span class="card-title">Valore per linea di servizio</span></div>
    <?= $barre($gLin, 'valore', $COL) ?></div>
</div>

<?php if ($agenti): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title">
    <i class="fa-solid fa-user-tie"></i> Agenti commerciali</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">
      clic sul nome per la scheda personale</span></div>
  <div style="overflow-x:auto">
  <table class="data-table" style="width:100%;font-size:11px">
    <thead><tr><th>Agente</th><th style="text-align:right">Commesse</th>
      <th style="text-align:right">Aperte</th><th style="text-align:right">Clienti</th>
      <th style="text-align:right">Valore</th><th style="text-align:right">Margine</th>
      <th style="text-align:right">Marg. %</th><th style="text-align:right">Ore</th>
      <th style="text-align:right">Sforate</th><th style="text-align:right">Divg.</th>
      <th style="text-align:right">Scad.</th><th style="text-align:right">Ferme</th></tr></thead>
    <tbody>
    <?php foreach ($agenti as $a): ?>
      <tr>
        <td><a href="<?=$qs(['agente'=>$a['agente']])?>" style="font-weight:600"><?=h($a['agente'])?></a></td>
        <td style="text-align:right"><?=$n($a['commesse'])?></td>
        <td style="text-align:right"><?=$n($a['aperte'])?></td>
        <td style="text-align:right"><?=$n($a['clienti'])?></td>
        <td style="text-align:right;font-weight:700"><?=$eur($a['valore'])?></td>
        <td style="text-align:right;color:#16a34a"><?=$eur($a['margine'])?></td>
        <td style="text-align:right;color:<?=((float)$a['margine_pct'])<20?'#dc2626':'#334155'?>">
          <?=$n1($a['margine_pct'])?>%</td>
        <td style="text-align:right;color:var(--muted)"><?=$hrs($a['ore'])?></td>
        <td style="text-align:right;color:<?=(int)$a['sforate']>0?'#dc2626':'var(--muted)'?>"><?=$n($a['sforate'])?></td>
        <td style="text-align:right;color:<?=(int)$a['divergenti']>0?'#f59e0b':'var(--muted)'?>"><?=$n($a['divergenti'])?></td>
        <td style="text-align:right"><?=$n($a['in_scadenza'])?></td>
        <td style="text-align:right;color:var(--muted)"><?=$n($a['ferme'])?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="font-size:11px;color:var(--muted);margin-top:6px">
    Valore e margine sulle sole commesse <strong>a ricavo</strong>; gli indicatori di rischio sulle
    sole <strong>aperte</strong>. Il numero di commesse da solo non misura il rendimento: un agente
    con poche commesse di grande valore e uno con molte piccole fanno lavori diversi.
  </p>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px<?= $att ? ';border-left:4px solid #dc2626' : '' ?>">
  <div class="card-header"><span class="card-title">
    <i class="fa-solid fa-triangle-exclamation"></i> Commesse da presidiare</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px"><?=count($att)?> voci</span></div>
  <?php if (!$att): ?>
    <p style="color:#16a34a;font-size:12px;margin:0">
      <i class="fa-solid fa-check"></i> Nessuna commessa richiede attenzione nel perimetro.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:11px">
      <thead><tr><th>Motivo</th><th>Commessa</th><th>Link SP</th><th>Scheda Progetto</th><th>Cliente</th>
        <?php if ($ag === ''): ?><th>Agente</th><?php endif; ?>
        <th style="text-align:right">Valore</th><th style="text-align:right">Consumo</th>
        <th style="text-align:right">Avanz.</th><th style="text-align:right">Divergenza</th>
        <th style="text-align:right">Marg.%</th><th style="text-align:right">Scad.</th><th>Fido</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($att, 0, 60) as $x): ?>
        <tr>
          <td><span style="display:inline-block;width:8px;height:8px;border-radius:2px;
                background:<?=$colPrio[(int)$x['priorita']] ?? '#94a3b8'?>;margin-right:5px"></span>
            <?=h($x['motivo'])?></td>
          <td style="font-family:monospace;font-size:10px"><?=h($x['commessa'])?></td>
          <td style="text-align:center"><?=$linkSp($x)?></td>
          <td><?=$schedaPrj($x)?></td>
          <td><?=h(mb_strimwidth((string)$x['cliente'], 0, 24, '…'))?></td>
          <?php if ($ag === ''): ?><td style="font-size:10px"><?=h($x['agente'])?></td><?php endif; ?>
          <td style="text-align:right"><?=$eur($x['valore'])?></td>
          <td style="text-align:right;font-weight:<?=((float)$x['consumo_valore_pct'])>=100?'700':'400'?>;
                color:<?=((float)$x['consumo_valore_pct'])>=100?'#dc2626':'#334155'?>">
            <?=$n1($x['consumo_valore_pct'])?>%</td>
          <td style="text-align:right;color:var(--muted)"><?=$n1($x['avanzamento_pct'])?>%</td>
          <td style="text-align:right;font-weight:700;
                color:<?=((float)$x['divergenza_pct'])>=20?'#dc2626':'#334155'?>">
            <?=$n1($x['divergenza_pct'])?></td>
          <td style="text-align:right"><?=$n1($x['margine_pct'])?>%</td>
          <td style="text-align:right"><?=$x['giorni_a_scadenza'] !== null ? $gg($x['giorni_a_scadenza']) : '—'?></td>
          <td><?=$fidoBadge($x)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p style="font-size:11px;color:var(--muted);margin-top:6px">
      <strong>Divergenza</strong> = consumo del budget meno avanzamento temporale. Il rischio non sta
      nell'uno o nell'altro presi da soli, ma nella loro distanza: 80% di budget al 40% del tempo va
      guardato, 80% e 80% è regolare. Ordinate per gravità e, a parità, per valore.
    </p>
  <?php endif; ?>
</div>

<?php if (count($trend) > 1): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Andamento — <?= ($f['from'] !== '' || $f['to'] !== '') ? h($periodoTxt) : 'ultimi ' . count($trend) . ' mesi' ?></span></div>
  <?php
    $mx = 0.01; foreach ($trend as $t) $mx = max($mx, (float)$t['ore']);
    $W=900; $H=180; $pL=52; $pR=12; $pT=10; $pB=24;
    $pw=$W-$pL-$pR; $ph=$H-$pT-$pB; $nb=max(1,count($trend)); $bw=$pw/$nb;
  ?>
  <svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:100%;min-width:600px;height:auto;font-family:inherit">
    <?php for($g=0;$g<=4;$g++): $y=$pT+$ph-$g*$ph/4; ?>
      <line x1="<?=$pL?>" y1="<?=round($y,1)?>" x2="<?=$W-$pR?>" y2="<?=round($y,1)?>" stroke="#e2e8f0"/>
      <text x="<?=$pL-5?>" y="<?=round($y+3,1)?>" text-anchor="end" font-size="9" fill="#94a3b8">
        <?=$n(round($mx*$g/4))?> h</text>
    <?php endfor; ?>
    <?php foreach($trend as $i=>$t): $h2=(float)$t['ore']/$mx*$ph;
      $x=$pL+$i*$bw+$bw*0.15; $bx=max(2,$bw*0.7); ?>
      <rect x="<?=round($x,1)?>" y="<?=round($pT+$ph-$h2,1)?>" width="<?=round($bx,1)?>"
            height="<?=round($h2,1)?>" fill="#2563eb" rx="1">
        <title><?=h($t['ym'])?>: <?=$n1($t['ore'])?> h · <?=$n($t['commesse'])?> commesse · <?=$eur($t['costo'])?></title></rect>
      <?php if($i % max(1,intdiv($nb,10))===0): ?>
        <text x="<?=round($x+$bx/2,1)?>" y="<?=$H-7?>" text-anchor="middle" font-size="9" fill="#64748b">
          <?=h(substr((string)$t['ym'],2))?></text>
      <?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <div style="font-size:11px;color:var(--muted)">Ore consuntivate per mese sul perimetro selezionato.</div>
</div>
<?php endif; ?>

<?php // ── v1.9.94 — ordini cliente per competenza (pro-rata temporis mensile) ──────── ?>
<?php if ($comp['commesse']): $anniC = array_keys($comp['anni']); ?>
<div class="card" style="margin-top:14px;border-left:4px solid #2563eb">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-calendar-days"></i> Valore ordini per competenza</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">pro-rata mensile · <?=h($periodoTxt)?> ·
      <?=$n(count($comp['commesse']))?> commesse a ricavo</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:10px">
    <?php foreach ($comp['anni'] as $y => $x): ?>
      <div style="text-align:center;padding:10px;background:#f8fafc;border-radius:8px;border-top:3px solid #2563eb">
        <div style="font-size:17px;font-weight:800;color:#1d4ed8"><?=$eur2($x['valore'])?></div>
        <div style="font-size:11px;font-weight:700"><?=(int)$y?></div>
        <div style="font-size:10px;color:var(--muted)"><?=$n($x['commesse'])?> commesse</div>
      </div>
    <?php endforeach; ?>
    <div style="text-align:center;padding:10px;background:#eff6ff;border-radius:8px;border-top:3px solid #334155">
      <div style="font-size:17px;font-weight:800"><?=$eur2($comp['totale'])?></div>
      <div style="font-size:11px;font-weight:700">Totale nel periodo</div>
    </div>
  </div>
  <div style="max-height:520px;overflow:auto">
  <table class="data-table" style="width:100%;font-size:11px">
    <thead><tr><th>Commessa</th><th>Link SP</th><th>Scheda Progetto</th><th>Cliente</th><?php if ($ag === ''): ?><th>Agente</th><?php endif; ?><th>Fido</th>
      <th>Durata</th><th style="text-align:right">Importo ordini</th>
      <?php foreach ($anniC as $y): ?><th style="text-align:right"><?=(int)$y?></th><?php endforeach; ?>
      <th style="text-align:right">Nel periodo</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($comp['commesse'], 0, 300) as $c): ?>
      <tr>
        <td><details><summary style="cursor:pointer;font-family:monospace;font-size:10px"><?=h($c['commessa'])?></summary>
          <div style="font-size:10px;color:var(--muted);white-space:normal;max-width:520px;margin-top:4px">
            <?php foreach ($c['ordini'] as $o): ?>
              <div><strong><?=h($o['fonte'])?></strong><?= $o['codice'] ? ' ' . h((string)$o['codice']) : '' ?>
                del <?=h(date('d/m/Y', strtotime((string)$o['data'])))?>: <?=$eur2($o['importo'])?> /
                <?=$n($o['mesi_totali'])?> mesi (<?=h(substr($o['inizio'], 5, 2) . '/' . substr($o['inizio'], 0, 4))?>–<?=h(substr($o['fine'], 5, 2) . '/' . substr($o['fine'], 0, 4))?>)
                = <?=$eur2($o['quota_mensile'])?>/mese<?php foreach ($o['anni'] as $y => $x): ?> · <?=(int)$y?>: <?=$n($x['mesi'])?> mesi = <?=$eur2($x['valore'])?><?php endforeach; ?></div>
            <?php endforeach; ?>
          </div></details></td>
        <td style="text-align:center"><?=$linkSp($c)?></td>
        <td><?=$schedaPrj($c)?></td>
        <td><?=h(mb_strimwidth((string)$c['cliente'], 0, 28, '…'))?></td>
        <?php if ($ag === ''): ?><td style="font-size:10px"><?=h((string)$c['agente'])?></td><?php endif; ?>
        <td><?=$fidoBadge($c)?></td>
        <td style="font-size:10px;color:var(--muted)"><?=h(date('m/Y', strtotime((string)$c['inizio'])))?>–<?=h(date('m/Y', strtotime((string)$c['fine'])))?></td>
        <td style="text-align:right"><?=$eur2($c['importo'])?></td>
        <?php foreach ($anniC as $y): $x = $c['anni'][$y] ?? null; ?>
          <td style="text-align:right"><?php if ($x): ?><?=$eur2($x['valore'])?><br><span style="font-size:9px;color:var(--muted)"><?=$n($x['mesi'])?> mesi</span><?php else: ?>—<?php endif; ?></td>
        <?php endforeach; ?>
        <td style="text-align:right;font-weight:700"><?=$eur2($c['valore_periodo'])?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="font-size:11px;color:var(--muted);margin-top:8px">
    <strong>Pro-rata temporis mensile</strong>: ogni ordine cliente (o riporto da contratto precedente; in mancanza di ordini il valore
    della commessa) è diviso per i <strong>mesi di calendario</strong> della durata della commessa, a partire dalla <strong>data
    dell'ordine se successiva all'inizio</strong>. La quota mensile × i mesi che cadono nel periodo dà il valore di competenza, raggruppato
    per anno. Es. 100.000 € su 28 mesi (06/2024–09/2026): 2024 = 7 mesi = 25.000,00 € · 2025 = 12 mesi = 42.857,14 € ·
    2026 = 9 mesi = 32.142,86 €. Apri il codice commessa per il calcolo di ciascun ordine.
    <?php if (count($comp['commesse']) > 300): ?>Mostrate le prime 300 di <?=$n(count($comp['commesse']))?>: l'export XLSX le contiene tutte.<?php endif; ?>
  </p>
</div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
