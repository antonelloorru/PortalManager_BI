<?php
/**
 * it_service_print.php — report di stampa della Relazione di Servizio IT.
 *
 * Incluso da it_service.php, che ha gia' caricato tutti i dati e definito i
 * formattatori e la funzione dei grafici. Non e' una pagina autonoma: riceve il
 * contesto invece di ricostruirlo, cosi' il report mostra esattamente cio' che
 * il pannello mostrava.
 *
 * A COLORI: i grafici sono SVG, quindi si stampano come vettori senza perdita.
 * Le aree colorate richiedono pero' che il browser abbia attiva la stampa della
 * grafica di sfondo — `print-color-adjust: exact` lo chiede, ma resta una
 * preferenza dell'utente che il server non puo' forzare.
 */
header('Content-Type: text/html; charset=utf-8');

$filtri = [];
foreach ([['linee','Linee'],['settori','Settori'],['incaricati','Incaricati'],
          ['sedi','Sedi'],['modalita','Modalità'],['fasce','Fasce'],['durate','Durate']] as [$k,$l]) {
    if (!empty($f[$k])) $filtri[] = $l . ': ' . implode(', ', $f[$k]);
}
if ($f['ricavo'] !== '') $filtri[] = 'Natura: ' . ($f['ricavo'] === '1' ? 'a ricavo' : 'interne');

/**
 * v1.9.17 — report PERSONALE quando l'incaricato selezionato è uno solo.
 *
 * Non un parametro in più: la condizione è già nei filtri. Un flag separato
 * avrebbe permesso di chiedere il report personale con tre incaricati
 * selezionati, e il titolo avrebbe nominato una persona sola mostrando i dati
 * di tre.
 */
$isPers = (count($f['incaricati'] ?? []) === 1);
$persona = $isPers ? $f['incaricati'][0] : '';

// i costi: filtrati come tutto il resto
$cQ2   = $it->costiQuadro($f);
$cRie2 = $it->costiRiepilogo($f);
// v1.9.19 — giorni lavorati, in entrambe le destinazioni
$gQ   = $it->giorniQuadro($f);
$gOp  = $it->giorniOperatore($f);
$gAr  = $it->giorniArea($f);
$gRic = $it->giorniRiconcilia($f);
?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8">
<title>Relazione di Servizio IT<?= $isPers ? ' — ' . h($persona) : '' ?> —
  <?=h($f['from'])?> / <?=h($f['to'])?></title>
<style>
  /* Il foglio e' l'unita' di misura: millimetri per i margini, punti per il
     testo. Un pixel in stampa dipende dalla risoluzione scelta dal browser. */
  @page { size: A4 landscape; margin: 10mm; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact;
      color-adjust: exact; box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; color: #1e293b;
         font-size: 9pt; line-height: 1.35; margin: 0; }
  h1 { font-size: 15pt; margin: 0 0 1mm; }
  h2 { font-size: 10.5pt; margin: 5mm 0 2mm; padding-bottom: 1mm;
       border-bottom: 2px solid #2563eb; }
  .meta { color: #64748b; font-size: 8pt; margin-bottom: 3mm; }
  .filtri { background: #f1f5f9; border-left: 3px solid #2563eb; padding: 1.5mm 2.5mm;
            font-size: 8pt; margin-bottom: 3mm; }
  table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
  th { background: #1e293b; color: #fff; text-align: left; padding: 1.2mm 1.5mm;
       font-size: 7pt; text-transform: uppercase; }
  td { padding: 1.2mm 1.5mm; border-bottom: 1px solid #e2e8f0; }
  tr:nth-child(even) td { background: #f8fafc; }
  .r { text-align: right; }
  .kpi { display: flex; gap: 2.5mm; margin-bottom: 4mm; }
  .kpi > div { flex: 1; border-radius: 1.5mm; padding: 2.5mm 2mm; text-align: center;
               border-top: 1mm solid; background: #f8fafc; }
  .kpi .v { font-size: 13pt; font-weight: 800; }
  .kpi .l { font-size: 6.5pt; text-transform: uppercase; color: #475569; font-weight: 700; }
  .kpi .s { font-size: 6.5pt; color: #94a3b8; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; }
  .box { border: 1px solid #e2e8f0; border-radius: 1.5mm; padding: 2.5mm; }
  .box h3 { font-size: 8.5pt; margin: 0 0 2mm; color: #334155; }
  .nota { font-size: 7pt; color: #64748b; margin: 2mm 0; }
  /* Una sezione spezzata fra due pagine lascia il titolo orfano: si evita. */
  .blocco { page-break-inside: avoid; }
  @media print { .nostampa { display: none; } }
  .nostampa { position: fixed; top: 6px; right: 6px; z-index: 99; }
  .nostampa button { padding: 6px 14px; font-size: 12px; cursor: pointer; border: none;
                     background: #2563eb; color: #fff; border-radius: 4px; }
  .avviso { background: #fffbeb; border-left: 3px solid #f59e0b; padding: 1.5mm 2.5mm;
            font-size: 7.5pt; margin-bottom: 3mm; }
</style></head><body>

<div class="nostampa"><button onclick="window.print()">Stampa</button></div>

<h1>Relazione di Servizio IT<?= $isPers ? ' — scheda personale' : '' ?></h1>
<?php if ($isPers): ?>
  <div style="font-size:13pt;font-weight:700;color:#0f766e;margin:-2mm 0 2mm"><?=h($persona)?></div>
<?php endif; ?>
<div class="meta">
  Periodo <?=date('d/m/Y', strtotime($f['from']))?> – <?=date('d/m/Y', strtotime($f['to']))?>
  · generato il <?=date('d/m/Y H:i')?>
  · raggruppamento: <?=h(implode(' × ', array_map(fn($g) => ItServiceModel::DIM[$g], $f['gb'])))?>
</div>

<?php if ($filtri): ?>
  <div class="filtri"><strong>Filtri applicati:</strong> <?=h(implode(' · ', $filtri))?></div>
<?php endif; ?>

<div class="blocco">
  <div class="kpi">
    <?php foreach ([
      ['Interventi', $hh($tot['interventi']), '#334155', $hh($tot['commesse']).' commesse'],
      ['Giornate-uomo', $hh($tot['giornate_uomo']), '#2563eb', $hh($tot['incaricati']).' incaricati'],
      ['Ore', $hh1($tot['ore']), '#16a34a',
       $tot['ore_medie_giornata'] !== null ? $hh1($tot['ore_medie_giornata']).' h/giornata' : ''],
      ['Ore a ricavo', $hh1($tot['ore_ricavo']), '#0d9488',
       (float)$tot['ore'] > 0 ? $hh1(100*(float)$tot['ore_ricavo']/(float)$tot['ore']).'%' : ''],
      ['Ore di viaggio', $hh1($tot['ore_viaggio']), '#f59e0b', $hh($km['trasferte'] ?? 0).' trasferte'],
      ['Km percorsi', (float)($tot['km'] ?? 0) > 0 ? $hh1($tot['km']) : '—',
       (float)($tot['km'] ?? 0) > 0 ? '#7c3aed' : '#94a3b8',
       $km['copertura_pct'] !== null ? 'copertura '.$hh1($km['copertura_pct']).'%' : 'non rilevati'],
    ] as [$l, $v, $c, $s]): ?>
      <div style="border-top-color:<?=$c?>">
        <div class="v" style="color:<?=$c?>"><?=$v?></div>
        <div class="l"><?=h($l)?></div><div class="s"><?=h($s)?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ((int)($km['trasferte'] ?? 0) > 0 && (int)($km['con_km'] ?? 0) === 0): ?>
    <div class="avviso"><strong>Chilometri non rilevati</strong> per le
      <?=$hh($km['trasferte'])?> trasferte del periodo. Sono registrate
      <?=$hh1($km['ore_viaggio'])?> ore di viaggio, che misurano lo stesso fenomeno con un dato reale.</div>
  <?php endif; ?>
</div>

<div class="blocco">
  <h2>Ripartizione dell'operatività</h2>
  <div class="grid2">
    <div class="box"><h3>Ore per modalità</h3><?= $barre($gMod, 'ore', $colMod, 420) ?></div>
    <div class="box"><h3>Ore per linea di servizio</h3><?= $barre($gLin, 'ore', $COL, 420) ?></div>
    <div class="box"><h3>Ore per settore tecnologico</h3><?= $barre($gSet, 'ore', $COL, 420) ?></div>
    <div class="box"><h3>Durata e fascia oraria</h3>
      <?= $barre($gDur, 'interventi', ['giornata'=>'#2563eb','mezza giornata'=>'#0891b2','non rilevata'=>'#cbd5e1'], 420) ?>
      <?= $barre($gFas, 'interventi', ['in orario'=>'#16a34a','fuori orario'=>'#f59e0b','non rilevata'=>'#cbd5e1'], 420) ?>
    </div>
  </div>
</div>

<?php if (count($trend) > 1): ?>
<div class="blocco">
  <h2>Andamento mensile</h2>
  <?php
    $mx = 0.01; foreach ($trend as $t) $mx = max($mx, (float)$t['ore']);
    $W=1000; $H=170; $pL=52; $pR=10; $pT=8; $pB=22;
    $pw=$W-$pL-$pR; $ph=$H-$pT-$pB; $nb=max(1,count($trend)); $bw=$pw/$nb;
  ?>
  <svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:100%;height:auto;font-family:inherit">
    <?php for($g=0;$g<=4;$g++): $y=$pT+$ph-$g*$ph/4; ?>
      <line x1="<?=$pL?>" y1="<?=round($y,1)?>" x2="<?=$W-$pR?>" y2="<?=round($y,1)?>" stroke="#e2e8f0"/>
      <text x="<?=$pL-4?>" y="<?=round($y+3,1)?>" text-anchor="end" font-size="8" fill="#94a3b8">
        <?=$hh(round($mx*$g/4))?></text>
    <?php endfor; ?>
    <?php foreach($trend as $i=>$t):
      $ho=(float)$t['ore']/$mx*$ph; $hv=(float)$t['ore_fuori']/$mx*$ph;
      $x=$pL+$i*$bw+$bw*0.15; $bx=max(1.5,$bw*0.7); ?>
      <rect x="<?=round($x,1)?>" y="<?=round($pT+$ph-$ho,1)?>" width="<?=round($bx,1)?>"
            height="<?=round($ho,1)?>" fill="#2563eb"/>
      <rect x="<?=round($x,1)?>" y="<?=round($pT+$ph-$hv,1)?>" width="<?=round($bx,1)?>"
            height="<?=round($hv,1)?>" fill="#f59e0b"/>
      <?php if($i % max(1,intdiv($nb,12))===0): ?>
        <text x="<?=round($x+$bx/2,1)?>" y="<?=$H-6?>" text-anchor="middle" font-size="7.5" fill="#64748b">
          <?=h(substr((string)$t['ym'],2))?></text>
      <?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <p class="nota">
    <span style="display:inline-block;width:9px;height:6px;background:#2563eb"></span> ore totali
    <span style="display:inline-block;width:9px;height:6px;background:#f59e0b;margin-left:8px"></span> di cui fuori orario
  </p>
</div>
<?php endif; ?>

<div style="page-break-before:always"></div>
<h2>Dettaglio — <?=h(implode(' × ', array_map(fn($g) => ItServiceModel::DIM[$g], $f['gb'])))?></h2>
<table>
  <thead><tr>
    <?php foreach ($f['gb'] as $g): ?><th><?=h(ItServiceModel::DIM[$g])?></th><?php endforeach; ?>
    <th class="r">Interv.</th><th class="r">Giornate</th><th class="r">Ore</th>
    <th class="r">Extra</th><th class="r">Viaggio</th><th class="r">Km</th>
    <th class="r">Giorn.</th><th class="r">Mezze</th><th class="r">Cliente</th>
    <th class="r">Remoto</th><th class="r">Smart</th><th class="r">Reper.</th><th class="r">F.orario</th>
  </tr></thead>
  <tbody>
  <?php foreach (array_slice($righe, 0, 300) as $r): ?>
    <tr>
      <?php foreach ($f['gb'] as $g): ?><td><?=h(mb_strimwidth((string)$r[$g], 0, 30, '…'))?></td><?php endforeach; ?>
      <td class="r"><?=$hh($r['interventi'])?></td>
      <td class="r"><?=$hh($r['giornate_uomo'])?></td>
      <td class="r" style="font-weight:700"><?=$hh1($r['ore'])?></td>
      <td class="r"><?=$hh1($r['ore_extra'])?></td>
      <td class="r" style="color:#b45309"><?=$hh1($r['ore_viaggio'])?></td>
      <td class="r"><?=(float)$r['km'] > 0 ? $hh1($r['km']) : '—'?></td>
      <td class="r"><?=$hh($r['giornate'])?></td>
      <td class="r"><?=$hh($r['mezze_giornate'])?></td>
      <td class="r"><?=$hh($r['presso_cliente'])?></td>
      <td class="r"><?=$hh($r['da_remoto'])?></td>
      <td class="r"><?=$hh($r['smart_working'])?></td>
      <td class="r"><?=$hh($r['reperibilita'])?></td>
      <td class="r" style="color:#b45309"><?=$hh($r['fuori_orario'])?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (count($righe) > 300): ?>
  <p class="nota">Mostrate le prime 300 righe di <?=$hh(count($righe))?>. L'export XLSX le contiene tutte.</p>
<?php endif; ?>

<p class="nota" style="margin-top:5mm;border-top:1px solid #cbd5e1;padding-top:2mm">
  PortalManager <?=h(PM_VERSION)?> — <strong>Giornate-uomo</strong>: coppia incaricato + giorno.
  <strong>Le ore extra sono comprese nelle ore</strong>, non aggiuntive.
  <strong>Km</strong>: presenti solo dove la distanza sede-cliente è stata rilevata; una cella vuota
  indica dato assente, non distanza nulla. La modalità è esclusiva e assegnata per precedenza:
  reperibilità, smart working, da remoto, presso cliente, in sede.
</p>

<?php // v1.9.19 — giorni lavorati per persona, in entrambe le destinazioni ?>
<?php if ($gOp): ?>
  <?php $areeOp2 = []; foreach ($gAr as $x) $areeOp2[$x['operatore']][] = $x; ?>
  <div class="blocco">
    <h2>Giorni lavorati<?= $isPers ? ' — ' . h($persona) : ' per persona' ?></h2>

    <div class="kpi">
      <?php foreach ([
        ['Operatori', number_format((float)($gQ['operatori'] ?? 0), 0, ',', '.'), '#0f766e'],
        ['Giorni-uomo', number_format((float)($gQ['giorni_uomo'] ?? 0), 0, ',', '.'), '#2563eb'],
        ['Ore', number_format((float)($gQ['ore'] ?? 0), 1, ',', '.'), '#334155'],
        ['Giornate eq.', number_format((float)($gQ['giornate_equiv'] ?? 0), 1, ',', '.'), '#64748b'],
        ['Fascia C', number_format((float)($gQ['giorni_uomo_C'] ?? 0), 0, ',', '.'), '#16a34a'],
        ['Fascia D', number_format((float)($gQ['giorni_uomo_D'] ?? 0), 0, ',', '.'), '#f59e0b'],
      ] as [$lg, $vg, $cg]): ?>
        <div style="border-top-color:<?=$cg?>">
          <div class="v" style="color:<?=$cg?>"><?=$vg?></div>
          <div class="l"><?=h($lg)?></div></div>
      <?php endforeach; ?>
    </div>

    <table>
      <thead><tr><th>Operatore</th><th class="r">Giorni lavorati</th>
        <th class="r">Giornate eq.</th><th class="r">h/giorno</th>
        <th class="r">Fascia C</th><th class="r">Fascia D</th>
        <th class="r">Produzione teorica</th><th class="r">€/giorno</th>
        <th class="r">Commesse</th></tr></thead>
      <tbody>
      <?php foreach ($gOp as $x): ?>
        <tr><td><?=h($x['operatore'])?></td>
          <td class="r" style="font-weight:700"><?=number_format((float)$x['giorni_lavorati'], 0, ',', '.')?></td>
          <td class="r"><?=number_format((float)$x['giornate_equiv'], 1, ',', '.')?></td>
          <td class="r"><?=$x['ore_per_giorno'] !== null
                ? number_format((float)$x['ore_per_giorno'], 1, ',', '.') : '—'?></td>
          <td class="r"><?=number_format((float)$x['giorni_C'], 0, ',', '.')?></td>
          <td class="r"><?=number_format((float)$x['giorni_D'], 0, ',', '.')?></td>
          <td class="r" style="font-weight:700"><?=$x['produzione_teorica'] !== null
                ? number_format((float)$x['produzione_teorica'], 2, ',', '.') : '—'?></td>
          <td class="r"><?=$x['produzione_per_giorno'] !== null
                ? number_format((float)$x['produzione_per_giorno'], 2, ',', '.') : '—'?></td>
          <td class="r"><?=number_format((float)$x['commesse'], 0, ',', '.')?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($gAr): ?>
      <h3 style="font-size:9pt;margin:3mm 0 1mm">Ripartizione per area tecnologica</h3>
      <table>
        <thead><tr><th>Operatore</th><th>Area tecnologica</th><th class="r">Giorni</th>
          <th class="r">Interventi</th><th class="r">Ore</th><th class="r">Quota</th>
          <th class="r">Produzione teorica</th></tr></thead>
        <tbody>
        <?php foreach ($gAr as $x): ?>
          <tr><td><?=h($x['operatore'])?></td><td><?=h($x['area_tecnologica'])?></td>
            <td class="r"><?=number_format((float)$x['giorni'], 0, ',', '.')?></td>
            <td class="r"><?=number_format((float)$x['interventi'], 0, ',', '.')?></td>
            <td class="r"><?=number_format((float)$x['ore'], 2, ',', '.')?></td>
            <td class="r"><?=number_format((float)$x['quota_ore_pct'], 1, ',', '.')?>%</td>
            <td class="r"><?=$x['produzione_teorica'] !== null
                  ? number_format((float)$x['produzione_teorica'], 2, ',', '.') : '—'?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($gRic): ?>
      <h3 style="font-size:9pt;margin:3mm 0 1mm">Giorni esclusi perché su commesse oggi chiuse</h3>
      <table>
        <thead><tr><th>Operatore</th><th class="r">Giorni totali</th><th class="r">Su attive</th>
          <th class="r">Su chiuse</th><th class="r">Ore totali</th>
          <th class="r">Ore su attive</th></tr></thead>
        <tbody>
        <?php foreach ($gRic as $x): ?>
          <tr><td><?=h($x['operatore'])?></td>
            <td class="r"><?=number_format((float)$x['giorni_totali'], 0, ',', '.')?></td>
            <td class="r" style="font-weight:700"><?=number_format((float)$x['giorni_attive'], 0, ',', '.')?></td>
            <td class="r"><?=number_format((float)$x['giorni_chiuse'], 0, ',', '.')?></td>
            <td class="r"><?=number_format((float)$x['ore_totali'], 2, ',', '.')?></td>
            <td class="r"><?=number_format((float)$x['ore_attive'], 2, ',', '.')?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nota">Il filtro guarda lo stato della commessa <strong>oggi</strong>, non alla data
        dell'intervento: questo report ristampato dopo la chiusura di una commessa darà numeri più
        bassi senza che nulla sia cambiato nei moduli.</p>
    <?php endif; ?>

    <p class="nota"><strong>«Giorni lavorati» sono giorni distinti</strong>: due interventi nello
      stesso giorno contano una volta sola. Un giorno con interventi in due fasce conta in entrambe,
      quindi C + D può superare i giorni totali. La <strong>produzione teorica</strong> è
      ore × listino: ciò che il lavoro varrebbe, non ciò che è stato fatturato.
      <?php if ((float)($gQ['fascia_letta_pct'] ?? 0) < 50): ?>
        Solo il <?=number_format((float)$gQ['fascia_letta_pct'], 1, ',', '.')?>% dei moduli ha la
        fascia letta dall'attività: per gli altri è dedotta dall'orario.
      <?php endif; ?>
    </p>
  </div>
<?php endif; ?>

<?php // v1.9.17 — riepilogo costi, in entrambe le destinazioni.
      //
      // Nel report generale sono i costi del perimetro, nel personale quelli
      // della persona: la query e' la stessa, cambia il filtro gia' applicato. ?>
<?php if (!empty($cRie2)): ?>
  <?php $perL4 = []; foreach ($cRie2 as $x) $perL4[$x['codice_linea']][] = $x; ?>
  <div class="blocco">
    <h2>Riepilogo costi per fascia e contratto<?= $isPers ? ' — ' . h($persona) : '' ?></h2>

    <div class="kpi">
      <?php foreach ([
        ['Interventi', number_format((float)($cQ2['interventi'] ?? 0), 0, ',', '.'), '#065f46'],
        ['Ore', number_format((float)($cQ2['ore'] ?? 0), 1, ',', '.'), '#334155'],
        ['Valore totale', number_format((float)($cQ2['valore'] ?? 0), 2, ',', '.'), '#0f766e'],
        ['Orario ordinario', number_format((float)($cQ2['valore_ordinario'] ?? 0), 2, ',', '.'), '#16a34a'],
        ['Extra-orario', number_format((float)($cQ2['valore_extra'] ?? 0), 2, ',', '.'), '#f59e0b'],
        ['Commesse', number_format((float)($cQ2['commesse'] ?? 0), 0, ',', '.'), '#7c3aed'],
      ] as [$l4, $v4, $c4]): ?>
        <div style="border-top-color:<?=$c4?>">
          <div class="v" style="color:<?=$c4?>"><?=$v4?></div>
          <div class="l"><?=h($l4)?></div></div>
      <?php endforeach; ?>
    </div>

    <?php foreach ($perL4 as $cod4 => $righe4): ?>
      <?php $o4 = 0; $w4 = 0; foreach ($righe4 as $x) { $o4 += (float)$x['ore']; $w4 += (float)$x['valore']; } ?>
      <h3 style="font-size:9pt;margin:3mm 0 1mm">RIEPILOGO PER TIPO CONTRATTO —
        <?=h($cod4)?> <span style="font-weight:400"><?=h($righe4[0]['contratto'])?></span></h3>
      <table>
        <thead><tr><th>Descrizione tariffa</th><th class="r">N. interventi</th>
          <th>In reperibilità</th><th class="r">Totale ore</th><th class="r">Tariffa</th>
          <th class="r">Totale valore</th></tr></thead>
        <tbody>
        <?php foreach ($righe4 as $x): ?>
          <tr><td><?=h($x['descrizione_tariffa'])?></td>
            <td class="r"><?=number_format((float)$x['interventi'], 0, ',', '.')?></td>
            <td><?=h($x['reperibilita'])?></td>
            <td class="r"><?=number_format((float)$x['ore'], 2, ',', '.')?></td>
            <td class="r"><?=$x['tariffa_ora'] !== null
                  ? number_format((float)$x['tariffa_ora'], 2, ',', '.') : '—'?></td>
            <td class="r" style="font-weight:700"><?=$x['valore'] !== null
                  ? number_format((float)$x['valore'], 2, ',', '.') : '—'?></td></tr>
        <?php endforeach; ?>
          <tr style="font-weight:700;background:#f1f5f9">
            <td>TOTALE</td><td></td><td></td>
            <td class="r"><?=number_format($o4, 2, ',', '.')?></td><td></td>
            <td class="r"><?=number_format($w4, 2, ',', '.')?></td></tr>
        </tbody>
      </table>
    <?php endforeach; ?>

    <p class="nota">Le tariffe vengono dal <strong>listino contrattuale</strong> del gestionale.
      Gli scaglioni dipendono dalla durata del singolo intervento: fino a 4 ore la tariffa oraria,
      da 4 a 8 la mezza giornata, da 8 la giornata. Il valore è ore × tariffa.</p>
  </div>
<?php endif; ?>

</body></html>
