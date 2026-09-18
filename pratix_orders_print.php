<?php
/**
 * pratix_orders_print.php — vista di stampa degli Ordinativi Pratix (v1.9.38).
 *
 * Incluso da pratix_orders.php quando ?export=pdf: NON e' una pagina autonoma,
 * riceve il contesto gia' filtrato ($quadro, $ordinativi, $righePer, $anomalie,
 * $filtriExp) invece di ricostruirlo, cosi' il foglio mostra esattamente cio'
 * che il pannello mostrava. La produzione del PDF e' delegata alla stampa del
 * browser (pulsante "Stampa" → Salva come PDF), coerente con gli altri
 * *_print.php del portale (nessuna dipendenza server-side).
 */
header('Content-Type: text/html; charset=utf-8');

$pn  = fn($v) => number_format((float)$v, 0, ',', '.');
$pn2 = fn($v) => $v === null ? '—' : number_format((float)$v, 2, ',', '.');
$ver = defined('PM_VERSION') ? PM_VERSION : trim(@file_get_contents(__DIR__ . '/VERSION') ?: '');
?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8">
<title>Ordinativi Pratix — <?=date('d/m/Y')?></title>
<style>
  @page { size: A4 landscape; margin: 10mm; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; color: #1e293b;
         font-size: 9pt; line-height: 1.35; margin: 0; }
  h1 { font-size: 15pt; margin: 0 0 1mm; }
  h2 { font-size: 10.5pt; margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: 2px solid #0f766e; }
  .meta { color: #64748b; font-size: 8pt; margin-bottom: 3mm; }
  .filtri { background: #f1f5f9; border-left: 3px solid #0f766e; padding: 1.5mm 2.5mm;
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
  .nota { font-size: 7pt; color: #64748b; margin: 2mm 0; }
  .mono { font-family: monospace; }
  @media print { .nostampa { display: none; } }
  .nostampa { position: fixed; top: 6px; right: 6px; z-index: 99; }
  .nostampa button { padding: 6px 14px; font-size: 12px; cursor: pointer; border: none;
                     background: #0f766e; color: #fff; border-radius: 4px; }
</style></head><body>
<div class="nostampa"><button onclick="window.print()">Stampa</button></div>

<h1>Ordinativi Pratix</h1>
<div class="meta">Generato il <?=date('d/m/Y H:i')?>
  · <?=$pn(count($ordinativi))?> ordinativi nel perimetro filtrato</div>
<?php if (!empty($filtriExp)): ?>
  <div class="filtri"><strong>Filtri:</strong> <?=h(implode(' · ', $filtriExp))?></div>
<?php endif; ?>

<div class="kpi">
  <div style="border-top-color:#0f766e"><div class="v"><?=$pn($quadro['ordinativi'] ?? 0)?></div>
    <div class="l">Ordinativi</div></div>
  <div style="border-top-color:#2563eb"><div class="v"><?=$pn2($quadro['importo_totale'] ?? 0)?> €</div>
    <div class="l">Importo totale</div></div>
  <div style="border-top-color:#334155"><div class="v"><?=$pn($quadro['commesse'] ?? 0)?></div>
    <div class="l">Commesse</div></div>
  <div style="border-top-color:#7c3aed"><div class="v"><?=$pn($quadro['ordinativi_multi_commessa'] ?? 0)?></div>
    <div class="l">Su più commesse</div></div>
  <div style="border-top-color:#dc2626"><div class="v"><?=$pn($quadro['con_codici_multipli'] ?? 0)?></div>
    <div class="l">Codici multipli</div></div>
  <div style="border-top-color:#f59e0b"><div class="v"><?=$pn($quadro['con_righe_vuote'] ?? 0)?></div>
    <div class="l">Righe senza importo</div></div>
</div>

<h2>Ordinativi (<?=$pn(count($ordinativi))?>)</h2>
<?php if (!$ordinativi): ?>
  <p class="nota">Nessun ordinativo corrisponde ai filtri.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Ordinativo</th><th class="r">Op.</th><th class="r">Commesse</th>
      <th class="r">Clienti</th><th class="r">Importo</th><th class="r">Fatturato</th>
      <th>Cod. multipli</th><th class="r">Senza importo</th><th>Esito</th><th>Periodo</th></tr></thead>
    <tbody>
    <?php foreach ($ordinativi as $x): ?>
      <tr><td class="mono"><?=h($x['order_code'])?></td>
        <td class="r"><?=$pn($x['operazioni'])?></td>
        <td class="r"><?=$pn($x['commesse'])?></td>
        <td class="r"><?=$pn($x['clienti'])?></td>
        <td class="r"><?=$pn2($x['importo_totale'])?></td>
        <td class="r"><?=$pn2($x['importo_fatturato'])?></td>
        <td><?=((int)$x['ha_codici_multipli']===1)?'SI':'—'?></td>
        <td class="r"><?=$pn($x['righe_senza_importo'])?></td>
        <td><?=h($x['esito_validazione'])?></td>
        <td><?=$x['dal'] ? date('d/m/Y', strtotime($x['dal'])).' – '.date('d/m/Y', strtotime($x['al'])) : '—'?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Commesse collegate</h2>
<?php $tot = 0; foreach ($righePer as $rr) $tot += count($rr); ?>
<?php if (!$tot): ?>
  <p class="nota">Nessuna riga di dettaglio nel perimetro.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Ordinativo</th><th>Commessa</th><th>Cliente in SP</th><th>Commerciale</th>
      <th>Tipo contratto</th><th class="r">Importo</th><th>Origine</th><th>Stato</th><th>Data</th></tr></thead>
    <tbody>
    <?php foreach ($righePer as $cod => $righe): foreach ($righe as $r): ?>
      <tr><td class="mono"><?=h($r['order_code'])?></td>
        <td class="mono"><?=h($r['commessa'])?></td>
        <td><?=h(mb_strimwidth((string)$r['cliente'], 0, 30, '…'))?></td>
        <td><?=h($r['commerciale'] ?? '')?></td>
        <td><?=h(mb_strimwidth((string)$r['tipo_contratto'], 0, 24, '…'))?></td>
        <td class="r"><?=$pn2($r['importo'])?></td>
        <td><?=h($r['origine_importo'])?></td>
        <td><?=h($r['stato_commessa'] ?? '—')?></td>
        <td><?=h($r['data_operazione'] ?? '—')?></td></tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<p class="nota" style="margin-top:5mm;border-top:1px solid #cbd5e1;padding-top:2mm">
  PortalManager <?=h($ver)?> — Ordinativi Pratix. Commerciale e Cliente sono
  ricavati dall'anagrafica commessa. L'importo di riga è il valore consolidato
  quando presente, quello previsto altrimenti.
</p>
</body></html>
