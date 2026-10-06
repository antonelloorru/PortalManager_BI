<?php
/**
 * app/soc_consuntivo_panel.php — v1.10.11
 * Service SOC › Consuntivo attività SOC: moduli di intervento dei componenti dell'Unità Organizzativa SOC,
 * per Tipologia di contratto (linea di servizio) e per operatore, con il modello di calcolo e le colonne della
 * Relazione di Servizio IT (ItServiceModel: totali, aggrega, andamento — ore ordinarie, fuori orario,
 * reperibilità, non classificate; giornate-uomo = coppia incaricato + giorno).
 * Variabili attese da service_soc.php: $f, $cf, $cInfo, $cTot, $cTip, $cOp, $cPiv, $cTrend, $cIds, $n, $n1, $dd, $pill, $qs.
 */
declare(strict_types=1);

$pct = fn($a, $b) => (float)$b > 0 ? 100 * (float)$a / (float)$b : 0.0;
$tOre = (float)($cTot['ore'] ?? 0);
$cls = ['ore_ordinarie' => ['Ordinarie', '#16a34a'], 'ore_fuori_orario' => ['Fuori orario', '#f59e0b'], 'ore_reperibilita' => ['Reperibilità', '#7c3aed'], 'ore_non_classificate' => ['Non classificate', '#94a3b8']];
$dash = fn($v, $fmt) => (float)$v > 0 ? $fmt($v) : '<span style="color:#cbd5e1">—</span>';
$tipLabel = fn($r) => (string)($r['linea_label'] ?? '');
$modLbl = fn($m) => ItServiceModel::etichetta($m);

// componenti dell'unità senza moduli nel perimetro
$presenti = array_flip(array_values($cIds));
$senza = array_diff_key($cInfo['membri'], $presenti);
if ($cInfo['tec'] !== null || $f['tec'] !== '') $senza = [];

// collegamento alla Relazione di Servizio IT con lo stesso perimetro (incaricati del periodo)
$itUrl = null;
if (can('view', 'it_service.php') && $cIds) {
    $itUrl = url_safe('it_service', array_filter(['from' => $f['from'], 'to' => $f['to'], 'incaricati' => implode(',', array_keys($cIds)),
        'gb' => 'incaricato,linea_label', 'contratti' => implode(',', $f['contratti'])], fn($v) => $v !== ''));
}
?>
<div class="card" style="padding:14px 16px;margin-bottom:14px">
  <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-user-shield"></i> Consuntivo attività — Unità Organizzativa SOC</h3>
  <p style="font-size:11px;color:var(--muted);margin:0">
    Moduli di intervento del periodo <?=$dd($f['from'])?> – <?=$dd($f['to'])?> (data del modulo) dei
    <?php if ($cInfo['tec'] !== null || $f['tec'] !== ''): ?>
      componente <strong><?=h($f['tec'])?></strong><?= $cInfo['tec'] ? ' → ' . h($cInfo['tec']) : ' <span style="color:#dc2626">(non abbinato a un dipendente)</span>' ?>
    <?php else: ?>
      <strong><?=$n(count($cInfo['membri']))?></strong> componenti dell'unità SOC (<?=h(implode(', ', $cInfo['membri']))?>)
    <?php endif; ?>
    <?= $f['contratti'] ? ' · contratti selezionati' : '' ?>
    <?= $cInfo['ticket_filtrati'] !== null ? ' · solo i moduli che riportano i <strong>' . $n($cInfo['ticket_filtrati']) . '</strong> ticket dei filtri' : '' ?>.
    Calcolo della Relazione di Servizio IT: <strong>ordinarie + fuori orario + reperibilità (+ non classificate) = ore totali</strong>; giornate-uomo = coppia operatore + giorno.
    <?php if ($itUrl): ?><a href="<?=$itUrl?>">Apri nella Relazione di Servizio IT →</a><?php endif; ?>
  </p>
</div>

<?php if (!$cIds): ?>
  <div class="alert alert-info">Nessun modulo di intervento dei componenti SOC nel perimetro selezionato.
    <?= !$cInfo['membri'] ? 'L\'Unità Organizzativa SOC non ha componenti: la pipeline di Sincronizzazione gestionale › SOC li assegna dai ticket.' : '' ?></div>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px">
  <?php foreach ([
    ['Ore totali', $n1($tOre) . ' h', '#334155', $n($cTot['interventi'] ?? 0) . ' interventi'],
    ['Ore ordinarie', $n1($cTot['ore_ordinarie'] ?? 0) . ' h', '#16a34a', $n1($pct($cTot['ore_ordinarie'] ?? 0, $tOre)) . '%'],
    ['Ore fuori orario', $n1($cTot['ore_fuori_orario'] ?? 0) . ' h', '#f59e0b', $n($cTot['fuori_orario'] ?? 0) . ' interventi'],
    ['Ore reperibilità', $n1($cTot['ore_reperibilita'] ?? 0) . ' h', '#7c3aed', $n($cTot['reperibilita'] ?? 0) . ' interventi'],
    ['Non classificate', $n1($cTot['ore_non_classificate'] ?? 0) . ' h', '#94a3b8', 'senza orario né fascia'],
    ['Giornate-uomo', $n($cTot['giornate_uomo'] ?? 0), '#2563eb', $n($cTot['incaricati'] ?? 0) . ' operatori · ' . ($cTot['ore_medie_giornata'] !== null ? $n1($cTot['ore_medie_giornata']) . ' h/giorno' : '—')],
    ['Ore a ricavo', $n1($cTot['ore_ricavo'] ?? 0) . ' h', '#0891b2', $n1($pct($cTot['ore_ricavo'] ?? 0, $tOre)) . '% · ' . $n($cTot['commesse'] ?? 0) . ' commesse'],
  ] as [$l, $v, $c, $sb]): ?>
    <div class="card" style="text-align:center;padding:11px;border-top:3px solid <?=$c?>">
      <div style="font-size:19px;font-weight:800;color:<?=$c?>"><?=$v?></div>
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
      <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
    </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(520px,1fr));gap:14px;margin-bottom:14px">
  <div class="card" style="padding:14px 16px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-chart-column"></i> Andamento mensile — ore per classe</h3>
    <?= PmCharts::groupedBars(array_map(fn($r) => substr($r['ym'], 5) . '/' . substr($r['ym'], 2, 2), $cTrend), [
          ['label' => 'Ordinarie', 'color' => '#16a34a', 'values' => array_map(fn($r) => (float)$r['ore_ordinarie'], $cTrend)],
          ['label' => 'Fuori orario', 'color' => '#f59e0b', 'values' => array_map(fn($r) => (float)$r['ore_fuori'], $cTrend)],
          ['label' => 'Reperibilità', 'color' => '#7c3aed', 'values' => array_map(fn($r) => (float)$r['ore_reperibilita'], $cTrend)],
          ['label' => 'Non classificate', 'color' => '#94a3b8', 'values' => array_map(fn($r) => max(0, (float)$r['ore'] - (float)$r['ore_ordinarie'] - (float)$r['ore_fuori'] - (float)$r['ore_reperibilita']), $cTrend)],
        ], ['height' => 220, 'stacked' => true, 'unit' => 'h', 'decimals' => 1]) ?>
  </div>
  <div class="card" style="padding:14px 16px">
    <h3 style="font-size:14px;margin:0 0 6px"><i class="fa-solid fa-users"></i> Ore per operatore e classe</h3>
    <?= PmCharts::groupedBars(array_map(fn($r) => mb_strimwidth((string)$r['incaricato'], 0, 16, '…'), $cOp), [
          ['label' => 'Ordinarie', 'color' => '#16a34a', 'values' => array_map(fn($r) => (float)$r['ore_ordinarie'], $cOp)],
          ['label' => 'Fuori orario', 'color' => '#f59e0b', 'values' => array_map(fn($r) => (float)$r['ore_fuori_orario'], $cOp)],
          ['label' => 'Reperibilità', 'color' => '#7c3aed', 'values' => array_map(fn($r) => (float)$r['ore_reperibilita'], $cOp)],
          ['label' => 'Non classificate', 'color' => '#94a3b8', 'values' => array_map(fn($r) => (float)$r['ore_non_classificate'], $cOp)],
        ], ['height' => 220, 'stacked' => true, 'unit' => 'h', 'decimals' => 1]) ?>
  </div>
</div>

<?php
// tabella con le colonne della Relazione IT (Dettaglio aggregato); $lead = colonne di raggruppamento
$tabella = function (string $titolo, string $icona, array $lead, array $righe, ?callable $extra = null) use ($n, $n1, $dash, $tOre, $pct) {
    $sum = []; foreach ($righe as $r) foreach (['interventi', 'giornate_uomo', 'ore', 'ore_ordinarie', 'ore_fuori_orario', 'ore_reperibilita', 'reperibilita', 'ore_non_classificate', 'ore_ricavo', 'ore_extra', 'ore_viaggio'] as $k) $sum[$k] = ($sum[$k] ?? 0) + (float)$r[$k];
    ?>
    <div class="card" style="padding:14px 16px;margin-bottom:14px;overflow-x:auto">
      <h3 style="font-size:14px;margin:0 0 8px"><i class="fa-solid <?=$icona?>"></i> <?=h($titolo)?> <span style="font-weight:400;font-size:11px;color:var(--muted)"><?=count($righe)?> righe</span></h3>
      <table class="data-table" style="width:100%;font-size:11px"><thead><tr>
        <?php foreach ($lead as $l => $_): ?><th><?=h($l)?></th><?php endforeach; ?>
        <th style="text-align:right">Interventi</th><th style="text-align:right">Giornate-uomo</th><th style="text-align:right">Ore totali</th>
        <th style="text-align:right;border-bottom:2px solid #16a34a">Ore ordinarie</th>
        <th style="text-align:right;border-bottom:2px solid #f59e0b">Ore fuori orario</th>
        <th style="text-align:right;border-bottom:2px solid #7c3aed">Ore reperibilità</th>
        <th style="text-align:right;border-bottom:2px solid #7c3aed" title="numero di interventi in reperibilità">N. interv. reperib.</th>
        <th style="text-align:right;border-bottom:2px solid #94a3b8">Non classificate</th>
        <th style="text-align:right">Quota ore</th><th style="text-align:right">Ore a ricavo</th>
        <th style="text-align:right">Ore extra</th><th style="text-align:right">Ore viaggio</th>
      </tr></thead><tbody>
      <?php foreach ($righe as $r): ?><tr>
        <?php foreach ($lead as $l => $fn): ?><td><?= $fn($r) ?></td><?php endforeach; ?>
        <td style="text-align:right"><?=$n($r['interventi'])?></td><td style="text-align:right"><?=$n($r['giornate_uomo'])?></td>
        <td style="text-align:right;font-weight:700"><?=$n1($r['ore'])?></td>
        <td style="text-align:right;color:#16a34a"><?=$n1($r['ore_ordinarie'])?></td>
        <td style="text-align:right;color:#b45309"><?=$dash($r['ore_fuori_orario'], $n1)?></td>
        <td style="text-align:right;color:#7c3aed;font-weight:600"><?=$dash($r['ore_reperibilita'], $n1)?></td>
        <td style="text-align:right;color:#7c3aed"><?=$dash($r['reperibilita'], $n)?></td>
        <td style="text-align:right;color:#64748b"><?=$dash($r['ore_non_classificate'], $n1)?></td>
        <td style="text-align:right"><?=$n1($pct($r['ore'], $tOre))?>%</td>
        <td style="text-align:right;color:#0891b2"><?=$dash($r['ore_ricavo'], $n1)?></td>
        <td style="text-align:right;color:var(--muted)"><?=$n1($r['ore_extra'])?></td><td style="text-align:right;color:var(--muted)"><?=$n1($r['ore_viaggio'])?></td>
      </tr><?php endforeach; ?>
      <?php if ($extra) $extra(); ?>
      </tbody>
      <?php if ($righe): ?><tfoot><tr style="font-weight:700;background:#f8fafc">
        <td colspan="<?=count($lead)?>">Totale</td>
        <td style="text-align:right"><?=$n($sum['interventi'])?></td><td style="text-align:right">—</td><td style="text-align:right"><?=$n1($sum['ore'])?></td>
        <td style="text-align:right;color:#16a34a"><?=$n1($sum['ore_ordinarie'])?></td><td style="text-align:right;color:#b45309"><?=$n1($sum['ore_fuori_orario'])?></td>
        <td style="text-align:right;color:#7c3aed"><?=$n1($sum['ore_reperibilita'])?></td><td style="text-align:right;color:#7c3aed"><?=$n($sum['reperibilita'])?></td>
        <td style="text-align:right;color:#64748b"><?=$n1($sum['ore_non_classificate'])?></td><td style="text-align:right">100%</td>
        <td style="text-align:right;color:#0891b2"><?=$n1($sum['ore_ricavo'])?></td><td style="text-align:right"><?=$n1($sum['ore_extra'])?></td><td style="text-align:right"><?=$n1($sum['ore_viaggio'])?></td>
      </tr></tfoot><?php endif; ?>
      </table>
    </div>
    <?php
};

$tabella('Consuntivo per Tipologia di contratto', 'fa-file-contract', [
    'Codice' => fn($r) => '<span style="font-family:monospace;font-size:10px;font-weight:700">' . h((string)$r['linea_servizio']) . '</span>',
    'Tipologia di contratto' => fn($r) => '<strong>' . h($tipLabel($r)) . '</strong>',
    'Modello' => fn($r) => '<span style="font-size:10px;color:var(--muted)">' . h($modLbl($r['modello_contratto'])) . '</span>',
], $cTip);

$tabella('Consuntivo per operatore', 'fa-user-clock', [
    'Operatore' => fn($r) => '<strong>' . h((string)$r['incaricato']) . '</strong>',
], $cOp, $senza ? function () use ($senza) {
    foreach ($senza as $nome) echo '<tr><td style="color:var(--muted)">' . h($nome) . ' <span style="font-size:10px">(unità SOC, nessun modulo nel periodo)</span></td>'
        . str_repeat('<td style="text-align:right;color:#cbd5e1">—</td>', 12) . '</tr>';
} : null);

// matrice operatore × tipologia di contratto (ore; dettaglio classi nel tooltip)
$tipi = []; foreach ($cPiv as $r) $tipi[$r['linea_label']] = ($tipi[$r['linea_label']] ?? 0) + (float)$r['ore'];
arsort($tipi); $tipi = array_keys($tipi);
$mx = []; foreach ($cPiv as $r) $mx[$r['incaricato']][$r['linea_label']] = $r;
$ordOp = array_column($cOp, 'incaricato');
?>
<div class="card" style="padding:14px 16px;margin-bottom:14px;overflow-x:auto">
  <h3 style="font-size:14px;margin:0 0 4px"><i class="fa-solid fa-table-cells"></i> Operatore × Tipologia di contratto — ore</h3>
  <p style="font-size:11px;color:var(--muted);margin:0 0 8px">Ore totali per cella; al passaggio del mouse la ripartizione ordinarie / fuori orario / reperibilità / non classificate e le giornate-uomo.</p>
  <table class="data-table" style="width:100%;font-size:11px"><thead><tr><th>Operatore</th>
    <?php foreach ($tipi as $t): ?><th style="text-align:right;max-width:120px;white-space:normal"><?=h($t)?></th><?php endforeach; ?><th style="text-align:right">Totale</th></tr></thead><tbody>
    <?php $colTot = []; foreach ($ordOp as $op): $rowTot = 0.0; ?><tr><td style="font-weight:600;white-space:nowrap"><?=h($op)?></td>
      <?php foreach ($tipi as $t): $c = $mx[$op][$t] ?? null; $v = $c ? (float)$c['ore'] : 0.0; $rowTot += $v; $colTot[$t] = ($colTot[$t] ?? 0) + $v; ?>
        <td style="text-align:right" <?php if ($c): ?>title="<?=h($t . ' — ordinarie ' . $n1($c['ore_ordinarie']) . ' h · fuori orario ' . $n1($c['ore_fuori_orario']) . ' h · reperibilità ' . $n1($c['ore_reperibilita']) . ' h · non classificate ' . $n1($c['ore_non_classificate']) . ' h · ' . $n($c['giornate_uomo']) . ' giornate-uomo')?>"<?php endif; ?>>
          <?= $c ? $n1($v) : '<span style="color:#cbd5e1">·</span>' ?></td>
      <?php endforeach; ?><td style="text-align:right;font-weight:700"><?=$n1($rowTot)?></td></tr>
    <?php endforeach; ?>
    <tr style="font-weight:700;background:#f8fafc"><td>Totale</td><?php foreach ($tipi as $t): ?><td style="text-align:right"><?=$n1($colTot[$t] ?? 0)?></td><?php endforeach; ?>
      <td style="text-align:right"><?=$n1(array_sum($colTot))?></td></tr>
  </tbody></table>
  <p style="font-size:11px;color:var(--muted);margin:8px 0 0">
    <strong>Tipologia di contratto</strong> = linea di servizio della commessa del modulo (cm_projects.service_line → cm_contract_models), come nella Relazione di Servizio IT.
    Classi di ore con la regola unica di PmOrario (sovrapposizione reale con le fasce ordinarie, reperibilità dal rapportino).</p>
</div>
<?php endif; ?>
