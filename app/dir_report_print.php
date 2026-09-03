<?php
/**
 * dir_report_print.php — report di stampa direzionale e scheda commerciale.
 *
 * Incluso da dir_report.php, che ha già caricato i dati e definito i
 * formattatori e la funzione dei grafici. Riceve il contesto invece di
 * ricostruirlo: il foglio mostra esattamente ciò che il pannello mostrava.
 */
header('Content-Type: text/html; charset=utf-8');
$filtri = [];
if ($f['solo'] !== 'aperte') $filtri[] = 'Perimetro: ' . ($f['solo'] === 'tutte' ? 'tutte le commesse' : 'solo a ricavo');
if (!empty($f['stato'])) $filtri[] = 'Stato: ' . implode(', ', $f['stato']);
if (!empty($f['linee'])) $filtri[] = 'Linee: ' . implode(', ', $f['linee']);
?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8">
<title><?= $ag !== '' ? 'Scheda commerciale — ' . h($ag) : 'Report direzionale commesse' ?></title>
<style>
  @page { size: A4 landscape; margin: 10mm; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; color: #1e293b;
         font-size: 9pt; line-height: 1.35; margin: 0; }
  h1 { font-size: 15pt; margin: 0 0 1mm; }
  h2 { font-size: 10.5pt; margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: 2px solid #2563eb; }
  .meta { color: #64748b; font-size: 8pt; margin-bottom: 3mm; }
  .filtri { background: #f1f5f9; border-left: 3px solid #2563eb; padding: 1.5mm 2.5mm;
            font-size: 8pt; margin-bottom: 3mm; }
  table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
  th { background: #1e293b; color: #fff; text-align: left; padding: 1.2mm 1.5mm; font-size: 7pt;
       text-transform: uppercase; }
  td { padding: 1.2mm 1.5mm; border-bottom: 1px solid #e2e8f0; }
  tr:nth-child(even) td { background: #f8fafc; }
  .r { text-align: right; }
  .kpi { display: flex; gap: 2.5mm; margin-bottom: 3mm; }
  .kpi > div { flex: 1; border-radius: 1.5mm; padding: 2.5mm 2mm; text-align: center;
               border-top: 1mm solid; background: #f8fafc; }
  .kpi .v { font-size: 12.5pt; font-weight: 800; }
  .kpi .l { font-size: 6.5pt; text-transform: uppercase; color: #475569; font-weight: 700; }
  .kpi .s { font-size: 6.5pt; color: #94a3b8; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; }
  .box { border: 1px solid #e2e8f0; border-radius: 1.5mm; padding: 2.5mm; }
  .box h3 { font-size: 8.5pt; margin: 0 0 2mm; color: #334155; }
  .nota { font-size: 7pt; color: #64748b; margin: 2mm 0; }
  .sez { font-size: 8pt; font-weight: 700; color: #334155; margin-bottom: 1.5mm; }
  .blocco { page-break-inside: avoid; }
  @media print { .nostampa { display: none; } }
  .nostampa { position: fixed; top: 6px; right: 6px; z-index: 99; }
  .nostampa button { padding: 6px 14px; font-size: 12px; cursor: pointer; border: none;
                     background: #2563eb; color: #fff; border-radius: 4px; }
</style></head><body>
<div class="nostampa"><button onclick="window.print()">Stampa</button></div>

<h1><?= $ag !== '' ? 'Scheda commerciale — ' . h($ag) : 'Report direzionale commesse' ?></h1>
<div class="meta">
  Generato il <?=date('d/m/Y H:i')?>
  <?php if ($ag !== '' && $per): ?>
    · perimetro <?=$n($per['suo'])?> commesse su <?=$n($per['tot'])?>
    (<?=$n1($per['pct_commesse'])?>%), <?=$n1($per['pct_valore'])?>% del valore di portafoglio
  <?php endif; ?>
</div>
<?php if ($filtri): ?><div class="filtri"><strong>Filtri:</strong> <?=h(implode(' · ', $filtri))?></div><?php endif; ?>

<div class="blocco">
  <div class="sez">PORTAFOGLIO</div>
  <div class="kpi">
    <div style="border-top-color:#334155"><div class="v"><?=$n($q['commesse'])?></div>
      <div class="l">Commesse</div><div class="s"><?=$n($q['aperte'])?> aperte</div></div>
    <div style="border-top-color:#2563eb"><div class="v"><?=$eur($q['valore'])?></div>
      <div class="l">Valore</div><div class="s"><?=$n($q['clienti'])?> clienti</div></div>
    <div style="border-top-color:#16a34a"><div class="v"><?=$eur($q['margine'])?></div>
      <div class="l">Margine</div><div class="s"><?=$n1($q['margine_pct'])?>%</div></div>
    <div style="border-top-color:#7c3aed"><div class="v"><?=$eur($q['costo'])?></div>
      <div class="l">Costo lavoro</div><div class="s"><?=$n1($q['costo_orario'])?> €/h</div></div>
  </div>

  <div class="sez">RISCHIO — solo commesse aperte</div>
  <div class="kpi">
    <?php foreach ([['Sforate',$q['sforate'],'#dc2626'],['Prossime al limite',$q['prossime'],'#ea580c'],
                    ['Consumo in anticipo',$q['divergenti'],'#f59e0b'],
                    ['In scadenza 30 gg',$q['in_scadenza'],'#0891b2'],
                    ['Ferme da 90 gg',$q['ferme'],'#94a3b8']] as [$l,$v,$c]): ?>
      <div style="border-top-color:<?=$c?>"><div class="v" style="color:<?=(int)$v>0?$c:'#94a3b8'?>"><?=$n($v)?></div>
        <div class="l"><?=h($l)?></div></div>
    <?php endforeach; ?>
  </div>
  <p class="nota">Il margine è calcolato sulle sole commesse a ricavo: le linee interne consumano ore
    senza produrne per costruzione. Gli indicatori di rischio riguardano le sole commesse aperte —
    una commessa chiusa ha una storia, non un rischio.</p>
</div>

<div class="blocco">
  <h2>Composizione del portafoglio</h2>
  <div class="grid2">
    <div class="box"><h3>Valore per modello di contratto</h3><?= $barre($gMod, 'valore', $COL, 420) ?></div>
    <div class="box"><h3>Valore per linea di servizio</h3><?= $barre($gLin, 'valore', $COL, 420) ?></div>
  </div>
</div>

<?php if ($agenti): ?>
<div class="blocco">
  <h2>Agenti commerciali</h2>
  <table>
    <thead><tr><th>Agente</th><th class="r">Commesse</th><th class="r">Aperte</th>
      <th class="r">Clienti</th><th class="r">Valore</th><th class="r">Margine</th>
      <th class="r">Marg.%</th><th class="r">Ore</th><th class="r">Sforate</th>
      <th class="r">Divg.</th><th class="r">Scad.</th><th class="r">Ferme</th></tr></thead>
    <tbody>
    <?php foreach ($agenti as $a): ?>
      <tr><td><?=h($a['agente'])?></td>
        <td class="r"><?=$n($a['commesse'])?></td><td class="r"><?=$n($a['aperte'])?></td>
        <td class="r"><?=$n($a['clienti'])?></td><td class="r"><?=$n($a['valore'])?></td>
        <td class="r"><?=$n($a['margine'])?></td><td class="r"><?=$n1($a['margine_pct'])?>%</td>
        <td class="r"><?=$n($a['ore'])?></td><td class="r"><?=$n($a['sforate'])?></td>
        <td class="r"><?=$n($a['divergenti'])?></td><td class="r"><?=$n($a['in_scadenza'])?></td>
        <td class="r"><?=$n($a['ferme'])?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="nota">Il numero di commesse non misura il rendimento: un agente con poche commesse di
    grande valore e uno con molte piccole fanno lavori diversi.</p>
</div>
<?php endif; ?>

<div style="page-break-before:always"></div>
<h2>Commesse da presidiare (<?=count($att)?>)</h2>
<?php if (!$att): ?>
  <p class="nota">Nessuna commessa richiede attenzione nel perimetro.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Motivo</th><th>Commessa</th><th>Cliente</th>
      <?php if ($ag === ''): ?><th>Agente</th><?php endif; ?>
      <th class="r">Valore</th><th class="r">Consumo</th><th class="r">Avanz.</th>
      <th class="r">Divergenza</th><th class="r">Marg.%</th><th class="r">Scad.</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($att, 0, 120) as $x): ?>
      <tr><td><?=h($x['motivo'])?></td>
        <td><?=h($x['commessa'])?></td>
        <td><?=h(mb_strimwidth((string)$x['cliente'], 0, 26, '…'))?></td>
        <?php if ($ag === ''): ?><td><?=h($x['agente'])?></td><?php endif; ?>
        <td class="r"><?=$n($x['valore'])?></td>
        <td class="r"><?=$n1($x['consumo_valore_pct'])?>%</td>
        <td class="r"><?=$n1($x['avanzamento_pct'])?>%</td>
        <td class="r" style="font-weight:700"><?=$n1($x['divergenza_pct'])?></td>
        <td class="r"><?=$n1($x['margine_pct'])?>%</td>
        <td class="r"><?=$x['giorni_a_scadenza'] !== null ? $n($x['giorni_a_scadenza']).'g' : '—'?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($att) > 120): ?>
    <p class="nota">Mostrate le prime 120 di <?=$n(count($att))?>. L'export XLSX le contiene tutte.</p>
  <?php endif; ?>
<?php endif; ?>

<p class="nota" style="margin-top:5mm;border-top:1px solid #cbd5e1;padding-top:2mm">
  PortalManager <?=h(PM_VERSION)?> — <strong>Divergenza</strong> = consumo del budget meno
  avanzamento temporale: il rischio sta nella distanza fra i due, non nel singolo valore.
  Il <strong>margine</strong> è sulle sole commesse a ricavo. Gli <strong>indicatori di rischio</strong>
  sulle sole commesse aperte.
</p>
</body></html>
