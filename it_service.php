<?php
/**
 * it_service.php — Relazione di Servizio IT (v1.8.90)
 *
 * Filtri combinabili su tutte le dimensioni, raggruppamento libero, grafici,
 * export XLSX con foglio pivot e report di stampa a colori.
 *
 * La classificazione non e' calcolata qui: viene da `v_cm_it_servizio`
 * (v1.8.89), che espone un intervento con tutte le sue dimensioni.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/ItServiceModel.php');

if (!can('view', 'it_service.php')) { redirect('manage_projects'); }
$u_id = (int)$_SESSION['user_id'];

$it = new ItServiceModel($pdo);
$f  = $it->normFilters($_GET);

$pronto = true; $errore = '';
try {
    $tot   = $it->totali($f);
    $righe = $it->aggrega($f, 500);
    $trend = $it->andamento($f);
    $km    = $it->statoKm($f);
    $gMod  = $it->perDimensione($f, 'modalita');
    $gLin  = $it->perDimensione($f, 'linea_label', 10);
    $gSet  = $it->perDimensione($f, 'settore', 10);
    $gDur  = $it->perDimensione($f, 'durata');
    $gFas  = $it->perDimensione($f, 'fascia_oraria');
    $vLin  = $it->valori('linea_label');
    $vCod  = $it->valori('linea_servizio');   // v1.8.92 — codici linea
    $vAz   = $it->valori('azienda');          // v1.8.93 — aziende esecutrici
    $gAz   = $it->perDimensione($f, 'azienda', 8);
    $gCod  = $it->perDimensione($f, 'linea_servizio', 12);
    $vSet  = $it->valori('settore');
    $vInc  = $it->valori('incaricato');
    $vSed  = $it->valori('sede_riferimento');

    // v1.9.15 — costi per fascia e contratto, stesse viste del Service Desk
    $cQ2   = $it->costiQuadro($f);
    $cRie2 = $it->costiRiepilogo($f);

    // v1.9.19 — giorni lavorati per persona
    $gQ    = $it->giorniQuadro($f);
    $gOp   = $it->giorniOperatore($f);
    $gAr   = $it->giorniArea($f);
    $gRic  = $it->giorniRiconcilia($f);
} catch (Throwable $e) {
    $pronto = false; $errore = $e->getMessage();
    $tot = $km = []; $righe = $trend = $gMod = $gLin = $gSet = $gDur = $gFas = [];
    // v1.9.19 — nel ramo di errore le variabili vanno AZZERATE, non ricalcolate.
    //
    // Erano finite qui per errore: se il caricamento principale fallisce,
    // rifare le stesse query nel catch le fa fallire di nuovo, e nel percorso
    // normale le variabili restano indefinite. Il template le usa comunque, e
    // PHP produce un avviso su ogni riferimento.
    $cQ2 = $gQ = []; $cRie2 = $gOp = $gAr = $gRic = [];
    $vLin = $vSet = $vInc = $vSed = $vCod = $vAz = []; $gCod = $gAz = [];
}

$COL = ['#2563eb','#16a34a','#f59e0b','#dc2626','#7c3aed','#0891b2','#db2777','#65a30d',
        '#ea580c','#0d9488','#9333ea','#4f46e5'];
$colMod = ['in sede'=>'#2563eb','da remoto'=>'#0d9488','presso cliente'=>'#f59e0b',
           'smart working'=>'#0891b2','reperibilita'=>'#dc2626'];

// ── export XLSX, con foglio pivot ───────────────────────────────────────────
if ($pronto && ($_GET['export'] ?? '') === 'xlsx') {
    require_once(__DIR__ . '/app/XlsxWriter.php');
    $w = new XlsxWriter();

    // foglio 1: l'aggregazione come mostrata a video
    $int = [array_merge(array_map(fn($g) => ItServiceModel::DIM[$g], $f['gb']),
        ['Interventi','Giornate-uomo','Ore','Ore extra','Ore viaggio','Km',
         'Giornate','Mezze giornate','Presso cliente','Da remoto','Smart working',
         'Reperibilità','Fuori orario','Ore a ricavo'])];
    foreach ($righe as $r) {
        $riga = [];
        foreach ($f['gb'] as $g) $riga[] = $r[$g];
        $int[] = array_merge($riga, [(int)$r['interventi'], (int)$r['giornate_uomo'],
            $r['ore'], $r['ore_extra'], $r['ore_viaggio'], $r['km'],
            (int)$r['giornate'], (int)$r['mezze_giornate'], (int)$r['presso_cliente'],
            (int)$r['da_remoto'], (int)$r['smart_working'], (int)$r['reperibilita'],
            (int)$r['fuori_orario'], $r['ore_ricavo']]);
    }
    // v1.9.19 — i giorni lavorati nell'export
    $rgo = [['Operatore','Giorni lavorati','Interventi','Ore','Giornate equiv.','Ore/giorno',
             'Giorni A','Giorni B','Giorni C','Giorni D','Giorni E','Giorni X',
             'Ore C','Ore D','Aree','Commesse','Clienti',
             'Produzione teorica','Produzione/giorno','Addebitato','Righe senza tariffa',
             'Dal','Al']];
    foreach ($it->giorniOperatore($f) as $x) $rgo[] = [$x['operatore'],
        (int)$x['giorni_lavorati'], (int)$x['interventi'], $x['ore'], $x['giornate_equiv'],
        $x['ore_per_giorno'], (int)$x['giorni_A'], (int)$x['giorni_B'], (int)$x['giorni_C'],
        (int)$x['giorni_D'], (int)$x['giorni_E'], (int)$x['giorni_X'], $x['ore_C'], $x['ore_D'],
        (int)$x['aree'], (int)$x['commesse'], (int)$x['clienti'], $x['produzione_teorica'],
        $x['produzione_per_giorno'], $x['valore_addebitato'], (int)$x['righe_senza_tariffa'],
        $x['dal'], $x['al']];
    $w->addSheet('Giorni per operatore', $rgo);

    $rga = [['Operatore','Area tecnologica','Giorni','Interventi','Ore','Quota ore %',
             'Commesse','Produzione teorica']];
    foreach ($it->giorniArea($f) as $x) $rga[] = [$x['operatore'], $x['area_tecnologica'],
        (int)$x['giorni'], (int)$x['interventi'], $x['ore'], $x['quota_ore_pct'],
        (int)$x['commesse'], $x['produzione_teorica']];
    $w->addSheet('Giorni per area', $rga);

    $rgr = $it->giorniRiconcilia($f);
    if ($rgr) {
        $rr = [['Operatore','Giorni totali','Su commesse attive','Su commesse chiuse',
                'Ore totali','Ore su attive']];
        foreach ($rgr as $x) $rr[] = [$x['operatore'], (int)$x['giorni_totali'],
            (int)$x['giorni_attive'], (int)$x['giorni_chiuse'], $x['ore_totali'], $x['ore_attive']];
        $w->addSheet('Giorni riconciliazione', $rr);
    }

    $w->addSheet('Dettaglio', $int);

    // foglio 2: matrice pivot incaricato x linea di servizio.
    //
    // Costruita qui e non a video: una tabella a doppia entrata su 145 incaricati
    // e 21 linee e' illeggibile in una pagina, mentre in un foglio di calcolo e'
    // esattamente cio' che serve per costruirci sopra un grafico pivot.
    $piv = $it->aggrega(['gb' => ['incaricato', 'linea_label']] + $f, 5000);
    $inc = []; $lin = [];
    foreach ($piv as $p) { $inc[$p['incaricato']] = 1; $lin[$p['linea_label']] = 1; }
    ksort($inc); ksort($lin);
    $linK = array_keys($lin);
    $mat = [array_merge(['Incaricato'], $linK, ['TOTALE'])];
    foreach (array_keys($inc) as $i) {
        $r = [$i]; $s = 0;
        foreach ($linK as $l) {
            $v = 0;
            foreach ($piv as $p) if ($p['incaricato'] === $i && $p['linea_label'] === $l) { $v = (float)$p['ore']; break; }
            $r[] = $v ?: null;   // celle vuote invece di zeri: un pivot con zeri
            $s += $v;            // ovunque nasconde i valori che contano
        }
        $r[] = $s;
        $mat[] = $r;
    }
    $w->addSheet('Pivot ore', $mat);

    $r3 = [['Mese','Interventi','Giornate-uomo','Ore','Ore viaggio','Ore fuori orario']];
    foreach ($trend as $t) $r3[] = [$t['ym'], (int)$t['interventi'], (int)$t['giornate_uomo'],
        $t['ore'], $t['ore_viaggio'], $t['ore_fuori']];
    $w->addSheet('Andamento', $r3);

    foreach ([['Modalità', $gMod], ['Linee di servizio', $gLin], ['Settori', $gSet],
              ['Codici linea', $gCod], ['Aziende esecutrici', $gAz]] as [$et, $dati]) {
        $rr = [[$et, 'Interventi', 'Ore', 'Giornate-uomo']];
        foreach ($dati as $d) $rr[] = [$d['voce'], (int)$d['interventi'], $d['ore'], (int)$d['giornate_uomo']];
        $w->addSheet(mb_substr($et, 0, 28), $rr);
    }

    write_log('Projects', 'info', "Export Relazione IT {$f['from']}..{$f['to']}", $u_id);
    $w->download("relazione_servizio_it_{$f['from']}_{$f['to']}.xlsx");
    exit;
}

$hh  = fn($v) => number_format((float)$v, 0, ',', '.');
$hh1 = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');

/**
 * Grafico a barre orizzontali in SVG.
 *
 * Orizzontali e non verticali: le etichette sono nomi di persone e di linee di
 * servizio, che verticalmente andrebbero ruotati o troncati.
 */
$barre = function (array $dati, string $campo, array $colori, int $w = 460) use ($hh1) {
    if (!$dati) return '';
    $max = 0.01; foreach ($dati as $d) $max = max($max, (float)$d[$campo]);
    $rh = 20; $h = count($dati) * $rh + 6; $lw = 150; $bw = $w - $lw - 60;
    $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;font-family:inherit">';
    foreach ($dati as $i => $d) {
        $y = $i * $rh + 3;
        $l = (float)$d[$campo] / $max * $bw;
        $c = $colori[$d['voce']] ?? $colori[$i % count($colori)] ?? '#2563eb';
        $o .= '<text x="' . ($lw - 6) . '" y="' . ($y + 11) . '" text-anchor="end" font-size="10" fill="#334155">'
            . htmlspecialchars(mb_strimwidth((string)$d['voce'], 0, 24, '…')) . '</text>';
        $o .= '<rect x="' . $lw . '" y="' . ($y + 2) . '" width="' . round(max(1, $l), 1)
            . '" height="12" fill="' . $c . '" rx="2"><title>' . htmlspecialchars((string)$d['voce'])
            . ': ' . $hh1($d[$campo]) . '</title></rect>';
        $o .= '<text x="' . ($lw + $l + 5) . '" y="' . ($y + 12) . '" font-size="9" fill="#64748b">'
            . $hh1($d[$campo]) . '</text>';
    }
    return $o . '</svg>';
};

// ── report di stampa a colori ───────────────────────────────────────────────
if ($pronto && ($_GET['print'] ?? '') === '1') {
    write_log('Projects', 'info', "Report Relazione IT {$f['from']}..{$f['to']}", $u_id);
    include(__DIR__ . '/app/it_service_print.php');
    exit;
}

require_once('header.php');

$qs = function (array $over = []) use ($f) {
    $p = ['from' => $f['from'], 'to' => $f['to'], 'ricavo' => $f['ricavo'],
          'q' => $f['q'], 'cliente' => $f['cliente']];
    foreach (['linee','codici','settori','aziende','incaricati','modalita','fasce','durate','sedi','gb'] as $k)
        if (!empty($f[$k])) $p[$k] = implode(',', $f[$k]);
    return url_safe('it_service', array_merge(array_filter($p, fn($v) => $v !== '' && $v !== []), $over));
};
?>

<div style="margin-bottom:14px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-server"></i> Relazione di Servizio IT</h1>
  <p style="color:var(--muted);font-size:12px;margin-top:2px">
    Operatività per incaricato, linea di servizio, settore tecnologico, modalità e fascia oraria.
  </p>
</div>

<?php if (!$pronto): ?>
  <div class="alert alert-warning"><strong>Dati non disponibili.</strong>
    Eseguire la migration v1.8.89.
    <div style="font-size:11px;color:var(--muted);margin-top:4px"><?=h($errore)?></div></div>
  <?php require_once('footer.php'); exit; ?>
<?php endif; ?>

<?php // v1.9.8 — pannello uniformato al template di Commesse/Progetti ?>
<?php
  $attivi = ($f['q'] !== '') + ($f['cliente'] !== '') + ($f['ricavo'] !== '');
  foreach (['linee','codici','settori','aziende','incaricati','modalita','fasce','durate','sedi'] as $k)
      $attivi += (count($f[$k]) > 0) ? 1 : 0;
?>
<details class="pm-panel" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary>
    <i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?=$attivi?></span><?php endif; ?>
    <span class="pm-hint"><?=$hh($tot['interventi'] ?? 0)?> interventi nel periodo</span>
  </summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?>

      <div class="pm-group">
        <h4>Periodo e ricerca</h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Dal</label>
            <input type="date" name="from" value="<?=h($f['from'])?>"></div>
          <div class="form-group"><label>Al</label>
            <input type="date" name="to" value="<?=h($f['to'])?>"></div>
          <div class="form-group"><label>Cerca ovunque</label>
            <input type="text" name="q" value="<?=h($f['q'])?>"
                   placeholder="commessa, cliente, modulo"></div>
          <div class="form-group"><label>Cliente</label>
            <input type="text" name="cliente" value="<?=h($f['cliente'])?>"
                   placeholder="parte della ragione sociale"></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Servizio</h4>
        <div class="pm-grid-auto">
          <?php foreach ([
            ['linee', 'Linea di servizio', $vLin], ['codici', 'Codice linea', $vCod],
            ['settori', 'Settore tecnologico', $vSet], ['aziende', 'Azienda esecutrice', $vAz],
          ] as [$k, $lbl, $vals]): ?>
            <div class="form-group"><label><?=h($lbl)?> <span class="pm-multi">(multipla)</span></label>
              <select name="<?=$k?>[]" multiple size="3">
                <?php foreach ($vals as $v): ?>
                  <option value="<?=h($v)?>" <?=in_array($v,$f[$k],true)?'selected':''?>><?=h($v)?></option>
                <?php endforeach; ?></select></div>
          <?php endforeach; ?>
          <div class="form-group"><label>Natura</label>
            <select name="ricavo"><option value="">— tutte —</option>
              <option value="1" <?=$f['ricavo']==='1'?'selected':''?>>Commesse a ricavo</option>
              <option value="0" <?=$f['ricavo']==='0'?'selected':''?>>Commesse interne</option>
            </select></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Erogazione</h4>
        <div class="pm-grid-auto">
          <?php foreach ([
            ['incaricati', 'Incaricato', $vInc], ['sedi', 'Sede di riferimento', $vSed],
            ['modalita', 'Modalità', ['in sede','da remoto','presso cliente','smart working','reperibilita']],
            ['fasce', 'Fascia oraria', ['in orario','fuori orario','non rilevata']],
            ['durate', 'Durata', ['giornata','mezza giornata','non rilevata']],
          ] as [$k, $lbl, $vals]): ?>
            <div class="form-group"><label><?=h($lbl)?> <span class="pm-multi">(multipla)</span></label>
              <select name="<?=$k?>[]" multiple size="3">
                <?php foreach ($vals as $v): ?>
                  <option value="<?=h($v)?>" <?=in_array($v,$f[$k],true)?'selected':''?>><?=h($v)?></option>
                <?php endforeach; ?></select></div>
          <?php endforeach; ?>
          <div class="form-group"><label>Raggruppa per <span class="pm-multi">(multipla)</span></label>
            <select name="gb[]" multiple size="3">
              <?php foreach (ItServiceModel::DIM as $k => $lbl): ?>
                <option value="<?=$k?>" <?=in_array($k,$f['gb'],true)?'selected':''?>><?=h($lbl)?></option>
              <?php endforeach; ?></select></div>
        </div>
      </div>

      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('it_service')?>">Azzera</a>
        <a class="btn btn-sm" href="<?=$qs(['export'=>'xlsx'])?>">
          <i class="fa-solid fa-file-excel"></i> XLSX + pivot</a>
        <?php // v1.9.17 — l'etichetta dice quale report esce. Con un incaricato
              // solo selezionato il report è personale: un pulsante che dice
              // "generale" e produce una scheda personale fa dubitare dei dati. ?>
        <a class="btn btn-sm" href="<?=$qs(['print'=>'1'])?>" target="_blank">
          <i class="fa-solid fa-print"></i>
          <?= count($f['incaricati'] ?? []) === 1 ? 'Report personale' : 'Report generale' ?></a>
      </div>
    </form>
  </div>
</details>

<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px">
  <?php foreach ([
    ['Interventi', $hh($tot['interventi']), '#334155', $hh($tot['commesse']).' commesse'],
    ['Giornate-uomo', $hh($tot['giornate_uomo']), '#2563eb',
     $hh($tot['incaricati']).' incaricati'],
    ['Ore', $hh1($tot['ore']), '#16a34a',
     $tot['ore_medie_giornata'] !== null ? $hh1($tot['ore_medie_giornata']).' h/giornata' : ''],
    ['Ore a ricavo', $hh1($tot['ore_ricavo']), '#0d9488',
     (float)$tot['ore'] > 0 ? $hh1(100*(float)$tot['ore_ricavo']/(float)$tot['ore']).'%' : ''],
    ['Ore di viaggio', $hh1($tot['ore_viaggio']), '#f59e0b',
     $hh($km['trasferte'] ?? 0).' trasferte'],
    ['Km percorsi', (float)($tot['km'] ?? 0) > 0 ? $hh1($tot['km']) : '—',
     (float)($tot['km'] ?? 0) > 0 ? '#7c3aed' : '#94a3b8',
     $km['copertura_pct'] !== null ? 'copertura '.$hh1($km['copertura_pct']).'%' : 'nessuna distanza'],
  ] as [$l, $v, $c, $s]): ?>
    <div class="card" style="text-align:center;padding:12px;border-top:3px solid <?=$c?>">
      <div style="font-size:20px;font-weight:800;color:<?=$c?>"><?=$v?></div>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
      <div style="font-size:10px;color:var(--muted)"><?=h($s)?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ((int)($km['trasferte'] ?? 0) > 0 && (int)($km['con_km'] ?? 0) === 0): ?>
  <div class="alert alert-warning" style="font-size:11px">
    <strong>Nessuna distanza chilometrica disponibile</strong> per le
    <?=$hh($km['trasferte'])?> trasferte del periodo. Sono comunque registrate
    <?=$hh1($km['ore_viaggio'])?> ore di viaggio, che misurano lo stesso fenomeno con un dato
    reale. Per attivare i chilometri servono gli indirizzi di sedi e clienti in anagrafica.
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
  <div class="card">
    <div class="card-header"><span class="card-title">Ore per modalità</span></div>
    <?= $barre($gMod, 'ore', $colMod) ?>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Ore per linea di servizio</span></div>
    <?= $barre($gLin, 'ore', $COL) ?>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Ore per settore tecnologico</span></div>
    <?= $barre($gSet, 'ore', $COL) ?>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Ore per azienda esecutrice</span>
      <span style="font-size:10px;color:var(--muted);margin-left:6px">dal prefisso del codice commessa</span></div>
    <?= $barre($gAz, 'ore', $COL) ?>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Ore per codice linea</span>
      <span style="font-size:10px;color:var(--muted);margin-left:6px">come sul gestionale</span></div>
    <?= $barre($gCod, 'ore', $COL) ?>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Durata e fascia oraria</span></div>
    <?= $barre($gDur, 'interventi', ['giornata'=>'#2563eb','mezza giornata'=>'#0891b2','non rilevata'=>'#cbd5e1']) ?>
    <?= $barre($gFas, 'interventi', ['in orario'=>'#16a34a','fuori orario'=>'#f59e0b','non rilevata'=>'#cbd5e1']) ?>
  </div>
</div>

<?php if (count($trend) > 1): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title">Andamento — <?=count($trend)?> mesi</span></div>
  <?php
    $mx = 0.01; foreach ($trend as $t) $mx = max($mx, (float)$t['ore']);
    $W=900; $H=200; $pL=48; $pR=12; $pT=10; $pB=26;
    $pw=$W-$pL-$pR; $ph=$H-$pT-$pB; $nb=max(1,count($trend)); $bw=$pw/$nb;
  ?>
  <svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:100%;min-width:600px;height:auto;font-family:inherit">
    <?php for($g=0;$g<=4;$g++): $y=$pT+$ph-$g*$ph/4; ?>
      <line x1="<?=$pL?>" y1="<?=round($y,1)?>" x2="<?=$W-$pR?>" y2="<?=round($y,1)?>" stroke="#e2e8f0"/>
      <text x="<?=$pL-5?>" y="<?=round($y+3,1)?>" text-anchor="end" font-size="9" fill="#94a3b8">
        <?=$hh(round($mx*$g/4))?></text>
    <?php endfor; ?>
    <?php foreach($trend as $i=>$t):
      $ho=(float)$t['ore']/$mx*$ph; $hv=(float)$t['ore_fuori']/$mx*$ph;
      $x=$pL+$i*$bw+$bw*0.15; $bx=max(2,$bw*0.7); ?>
      <rect x="<?=round($x,1)?>" y="<?=round($pT+$ph-$ho,1)?>" width="<?=round($bx,1)?>"
            height="<?=round($ho,1)?>" fill="#2563eb" rx="1">
        <title><?=h($t['ym'])?>: <?=$hh1($t['ore'])?> h · <?=$hh($t['giornate_uomo'])?> giornate-uomo</title></rect>
      <rect x="<?=round($x,1)?>" y="<?=round($pT+$ph-$hv,1)?>" width="<?=round($bx,1)?>"
            height="<?=round($hv,1)?>" fill="#f59e0b" rx="1">
        <title>fuori orario: <?=$hh1($t['ore_fuori'])?> h</title></rect>
      <?php if($i % max(1,intdiv($nb,10))===0): ?>
        <text x="<?=round($x+$bx/2,1)?>" y="<?=$H-8?>" text-anchor="middle" font-size="9" fill="#64748b">
          <?=h(substr((string)$t['ym'],2))?></text>
      <?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <div style="font-size:11px;color:var(--muted)">
    <span style="display:inline-block;width:12px;height:8px;background:#2563eb"></span> ore totali
    <span style="display:inline-block;width:12px;height:8px;background:#f59e0b;margin-left:12px"></span> di cui fuori orario
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title">Dettaglio — <?=h(implode(' × ', array_map(fn($g)=>ItServiceModel::DIM[$g], $f['gb'])))?></span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px"><?=count($righe)?> righe</span>
  </div>
  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:11px">
      <thead><tr>
        <?php foreach ($f['gb'] as $g): ?><th><?=h(ItServiceModel::DIM[$g])?></th><?php endforeach; ?>
        <th class="r" style="text-align:right">Interventi</th>
        <th style="text-align:right">Giornate</th><th style="text-align:right">Ore</th>
        <th style="text-align:right">Extra</th><th style="text-align:right">Viaggio</th>
        <th style="text-align:right">Km</th><th style="text-align:right">Giorn.</th>
        <th style="text-align:right">Mezze</th><th style="text-align:right">Cliente</th>
        <th style="text-align:right">Remoto</th><th style="text-align:right">Smart</th>
        <th style="text-align:right">Reper.</th><th style="text-align:right">F.orario</th>
      </tr></thead>
      <tbody>
      <?php foreach ($righe as $r): ?>
        <tr>
          <?php foreach ($f['gb'] as $g): ?><td><?=h((string)$r[$g])?></td><?php endforeach; ?>
          <td style="text-align:right"><?=$hh($r['interventi'])?></td>
          <td style="text-align:right"><?=$hh($r['giornate_uomo'])?></td>
          <td style="text-align:right;font-weight:700"><?=$hh1($r['ore'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$hh1($r['ore_extra'])?></td>
          <td style="text-align:right;color:#f59e0b"><?=$hh1($r['ore_viaggio'])?></td>
          <td style="text-align:right"><?=(float)$r['km'] > 0 ? $hh1($r['km']) : '—'?></td>
          <td style="text-align:right"><?=$hh($r['giornate'])?></td>
          <td style="text-align:right"><?=$hh($r['mezze_giornate'])?></td>
          <td style="text-align:right"><?=$hh($r['presso_cliente'])?></td>
          <td style="text-align:right"><?=$hh($r['da_remoto'])?></td>
          <td style="text-align:right"><?=$hh($r['smart_working'])?></td>
          <td style="text-align:right"><?=$hh($r['reperibilita'])?></td>
          <td style="text-align:right;color:#f59e0b"><?=$hh($r['fuori_orario'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="font-size:11px;color:var(--muted);margin-top:8px">
    <strong>Giornate-uomo</strong>: coppia incaricato + giorno. Chi svolge cinque interventi in un
    giorno ha lavorato una giornata. <strong>Le ore extra sono comprese nelle ore</strong>, non
    aggiuntive. <strong>Km</strong> compare solo dove la distanza sede-cliente è stata rilevata:
    una cella vuota significa dato assente, non distanza nulla.
  </p>
</div>

<?php // ── v1.9.19 — giorni lavorati per persona ───────────────────────────── ?>
<?php if ($gOp): ?>
  <?php
    // le aree raggruppate per operatore, per la colonna di dettaglio
    $areeOp = [];
    foreach ($gAr as $x) $areeOp[$x['operatore']][] = $x;
    $colA = ['#0f766e','#2563eb','#f59e0b','#7c3aed','#db2777','#16a34a','#dc2626','#334155'];
    // una tinta stabile per area: lo stesso colore in tutte le righe
    $areeTutte = array_values(array_unique(array_column($gAr, 'area_tecnologica')));
    $tintaArea = fn($a) => $colA[array_search($a, $areeTutte, true) % count($colA)];
    $senzaTar = (int)($gQ['righe_senza_tariffa'] ?? 0);
    $fLetta   = (float)($gQ['fascia_letta_pct'] ?? 0);
  ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #0f766e">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-user-clock"></i>
        Giorni lavorati per persona</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        solo commesse attive a produzione · WTS-ACM, WTS-CSS, WTS-CC, WTS-MEG</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:10px">
      <?php foreach ([
        ['Operatori', $hh($gQ['operatori'] ?? 0), '#0f766e', $hh($gQ['commesse'] ?? 0) . ' commesse'],
        ['Giorni-uomo', $hh($gQ['giorni_uomo'] ?? 0), '#2563eb',
         $hh($gQ['giorni_calendario'] ?? 0) . ' gg di calendario'],
        ['Ore', $hh1($gQ['ore'] ?? 0), '#334155',
         $hh1($gQ['giornate_equiv'] ?? 0) . ' giornate eq.'],
        ['Giorni in fascia C', $hh($gQ['giorni_uomo_C'] ?? 0), '#16a34a', 'orario ordinario'],
        ['Giorni in fascia D', $hh($gQ['giorni_uomo_D'] ?? 0), '#f59e0b', 'extra-orario'],
        ['Produzione teorica', $hh1($gQ['produzione_teorica'] ?? 0), '#7c3aed', 'a listino'],
      ] as [$l, $v, $c, $sb]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:16px;font-weight:800;color:<?=$c?>"><?=$v?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php // due avvertenze, entrambe sulla affidabilità di ciò che si legge ?>
    <?php if ($fLetta < 50): ?>
      <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 11px;
                  border-radius:0 6px 6px 0;font-size:11px;margin-bottom:8px">
        <strong>Solo il <?=$hh1($fLetta)?>% dei moduli ha la fascia letta dall'attività</strong>: per
        gli altri è dedotta dall'orario di inizio. Il conteggio per fascia eredita questa
        incertezza — i giorni in fascia D potrebbero essere in parte supposti.
      </div>
    <?php endif; ?>
    <?php if ($senzaTar > 0): ?>
      <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 11px;
                  border-radius:0 6px 6px 0;font-size:11px;margin-bottom:8px">
        <strong><?=$hh($senzaTar)?> interventi senza tariffa di listino</strong>: la produzione
        teorica è parziale. Le combinazioni fascia × durata non previste dal contratto hanno
        tariffa a zero nel gestionale.
      </div>
    <?php endif; ?>

    <table class="data-table" style="width:100%;font-size:11px">
      <thead>
        <tr style="font-size:9px;color:var(--muted)">
          <th></th>
          <th colspan="3" style="text-align:center;border-bottom:2px solid #2563eb">GIORNI</th>
          <th colspan="2" style="text-align:center;border-bottom:2px solid #16a34a">PER FASCIA</th>
          <th colspan="2" style="text-align:center;border-bottom:2px solid #7c3aed">PRODUZIONE</th>
          <th></th>
        </tr>
        <tr><th>Operatore</th>
          <th style="text-align:right">Lavorati</th>
          <th style="text-align:right">Giornate eq.</th>
          <th style="text-align:right">h/giorno</th>
          <th style="text-align:right">C</th><th style="text-align:right">D</th>
          <th style="text-align:right">Teorica</th>
          <th style="text-align:right">€/giorno</th>
          <th>Aree tecnologiche</th></tr>
      </thead>
      <tbody>
      <?php foreach ($gOp as $x): ?>
        <tr>
          <td style="font-weight:600"><?=h($x['operatore'])?>
            <span style="font-size:9px;color:var(--muted)">
              · <?=$hh($x['interventi'])?> interventi · <?=$hh($x['commesse'])?> commesse</span></td>
          <td style="text-align:right;font-weight:700;font-size:13px"><?=$hh($x['giorni_lavorati'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$hh1($x['giornate_equiv'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$hh1($x['ore_per_giorno'])?></td>
          <td style="text-align:right;color:#16a34a"><?=$hh($x['giorni_C'])?></td>
          <td style="text-align:right;color:#f59e0b;
                font-weight:<?=((int)$x['giorni_D'])>0?'700':'400'?>"><?=$hh($x['giorni_D'])?></td>
          <td style="text-align:right;font-weight:700">
            <?=$x['produzione_teorica']!==null?$hh1($x['produzione_teorica']):'—'?></td>
          <td style="text-align:right;color:var(--muted)">
            <?=$x['produzione_per_giorno']!==null?$hh1($x['produzione_per_giorno']):'—'?></td>
          <td>
            <?php foreach ($areeOp[$x['operatore']] ?? [] as $a): ?>
              <span style="display:inline-block;font-size:9px;padding:1px 6px;border-radius:8px;
                    color:#fff;background:<?=$tintaArea($a['area_tecnologica'])?>;margin:1px 2px 1px 0"
                    title="<?=h($a['area_tecnologica'])?>: <?=$hh($a['giorni'])?> giorni, <?=$hh1($a['ore'])?> h">
                <?=h($a['area_tecnologica'])?> <?=$hh1($a['quota_ore_pct'])?>%</span>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php // la riconciliazione compare solo se serve ?>
    <?php if ($gRic): ?>
      <div style="margin-top:12px;background:#f8fafc;border-radius:8px;padding:10px">
        <div style="font-size:11px;font-weight:700;margin-bottom:5px">
          <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i>
          Giorni esclusi perché su commesse oggi chiuse</div>
        <table class="data-table" style="width:100%;font-size:11px;margin:0">
          <thead><tr><th>Operatore</th><th style="text-align:right">Giorni totali</th>
            <th style="text-align:right">Su attive</th><th style="text-align:right">Su chiuse</th>
            <th style="text-align:right">Ore totali</th>
            <th style="text-align:right">Ore su attive</th></tr></thead>
          <tbody>
          <?php foreach ($gRic as $x): ?>
            <tr><td><?=h($x['operatore'])?></td>
              <td style="text-align:right"><?=$hh($x['giorni_totali'])?></td>
              <td style="text-align:right;font-weight:700"><?=$hh($x['giorni_attive'])?></td>
              <td style="text-align:right;color:#dc2626"><?=$hh($x['giorni_chiuse'])?></td>
              <td style="text-align:right"><?=$hh1($x['ore_totali'])?></td>
              <td style="text-align:right;font-weight:700"><?=$hh1($x['ore_attive'])?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:10px;color:var(--muted);margin:5px 0 0">
          Il filtro guarda lo stato della commessa <strong>oggi</strong>, non alla data
          dell'intervento: un report ristampato dopo la chiusura di una commessa dà numeri più
          bassi senza che nulla sia cambiato nei moduli.
        </p>
      </div>
    <?php endif; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:8px;padding-top:8px;
              border-top:1px solid #f1f5f9">
      <strong>«Giorni lavorati» sono giorni distinti</strong>: due interventi nello stesso giorno
      contano una volta sola. Le <strong>giornate equivalenti</strong> sono le ore diviso 8 —
      chi lavora due ore al giorno per venti giorni ha 20 giorni lavorati e 5 giornate.
      Un giorno con interventi in due fasce <strong>conta in entrambe</strong>, quindi C + D può
      superare i giorni totali. La <strong>produzione teorica</strong> è ore × listino: ciò che il
      lavoro varrebbe, non ciò che è stato fatturato.
    </p>
  </div>
<?php endif; ?>

<?php // ── v1.9.15 — costi per fascia e contratto ──────────────────────────── ?>
<?php if (!empty($cRie2)): ?>
  <?php $perL3 = []; foreach ($cRie2 as $x) $perL3[$x['codice_linea']][] = $x;
        $colF3 = ['C' => '#16a34a', 'D' => '#f59e0b']; ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #065f46">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-calculator"></i>
        Riepilogo costi per fascia e contratto</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=h(implode(', ', array_keys($perL3)))?> · stesso calcolo della sezione Service Desk</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:10px">
      <?php foreach ([
        ['Interventi', $hh($cQ2['interventi'] ?? 0), '#065f46', $hh1($cQ2['ore'] ?? 0) . ' ore'],
        ['Valore totale', $hh1($cQ2['valore'] ?? 0), '#0f766e', ''],
        ['Orario ordinario', $hh1($cQ2['valore_ordinario'] ?? 0), '#16a34a',
         $hh1($cQ2['ore_ordinario'] ?? 0) . ' h'],
        ['Extra-orario', $hh1($cQ2['valore_extra'] ?? 0), '#f59e0b',
         $hh1($cQ2['ore_extra'] ?? 0) . ' h'],
        ['Commesse', $hh($cQ2['commesse'] ?? 0), '#334155',
         $hh($cQ2['tecnici'] ?? 0) . ' incaricati'],
      ] as [$l3, $v3, $c3, $s3]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:16px;font-weight:800;color:<?=$c3?>"><?=$v3?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l3)?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($s3)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php foreach ($perL3 as $cod3 => $righe3): ?>
      <?php $so3 = 0; $sv3 = 0; foreach ($righe3 as $x) { $so3 += (float)$x['ore']; $sv3 += (float)$x['valore']; } ?>
      <div style="margin-bottom:12px">
        <div style="font-size:12px;font-weight:700;padding:6px 9px;background:#f1f5f9;
                    border-radius:6px 6px 0 0;border-bottom:2px solid #065f46">
          RIEPILOGO PER TIPO CONTRATTO — <span style="font-family:monospace"><?=h($cod3)?></span>
          <span style="font-weight:400;color:var(--muted)"><?=h($righe3[0]['contratto'])?></span>
        </div>
        <table class="data-table" style="width:100%;font-size:11px;margin:0">
          <thead><tr><th>Descrizione tariffa</th><th style="text-align:right">N. interventi</th>
            <th style="text-align:center">In reperibilità</th><th style="text-align:right">Totale ore</th>
            <th style="text-align:right">Tariffa</th><th style="text-align:right">Totale valore</th></tr></thead>
          <tbody>
          <?php foreach ($righe3 as $x): ?>
            <tr>
              <td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                    background:<?=$colF3[$x['fascia']] ?? '#94a3b8'?>;margin-right:5px"></span>
                <?=h($x['descrizione_tariffa'])?></td>
              <td style="text-align:right"><?=$hh($x['interventi'])?></td>
              <td style="text-align:center;color:var(--muted)"><?=h($x['reperibilita'])?></td>
              <td style="text-align:right"><?=$hh1($x['ore'])?></td>
              <td style="text-align:right;color:var(--muted)">
                <?=$x['tariffa_ora']!==null?$hh1($x['tariffa_ora']):'—'?></td>
              <td style="text-align:right;font-weight:700">
                <?=$x['valore']!==null?$hh1($x['valore']):'—'?></td>
            </tr>
          <?php endforeach; ?>
            <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #cbd5e1">
              <td>TOTALE</td><td></td><td></td>
              <td style="text-align:right"><?=$hh1($so3)?></td><td></td>
              <td style="text-align:right"><?=$hh1($sv3)?></td></tr>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:8px">
      Stesso calcolo della sezione <strong>Service Desk</strong>: le due sezioni condividono le
      viste, così lo stesso intervento non può valere due cifre diverse.
      Gli scaglioni dipendono dalla durata del singolo intervento; il valore è ore × tariffa.
    </p>
  </div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
