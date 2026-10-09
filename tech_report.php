<?php
/**
 * tech_report.php — Gestione Commesse › Relazione Tecnici (v1.10.25; v1.10.28: colonna «Linea di servizio» dopo «Codice linea»)
 *
 * Filtri unificati della Relazione di Servizio IT (ItServiceModel::normFilters: contratto, stato commessa, periodo, ricerca,
 * cliente, linea, codice linea, settore, azienda, natura, incaricato, sede, modalità, fascia, durata) + tipologia di contratto
 * e provenienza del modulo. Due schede:
 *   Tecnici                — una riga per tecnico × codice linea (+ totale del tecnico), metriche di dettaglio,
 *                            moduli di intervento valorizzati / non valorizzati;
 *   Rapporti di intervento — moduli per tipologia di contratto e per commessa con la provenienza ticket, drill-down sulla
 *                            commessa (moduli, link alla scheda, export della singola commessa).
 *   Controllo Reperibilità — v1.10.32: interventi in reperibilità (inizio 18:01–08:59) con il primo intervento ordinario
 *                            (09:00–18:00) del giorno lavorativo successivo; filtri globali + filtri di colonna.
 * Stampa (HTML) ed export CSV / XLSX / DOCX / PDF dallo stesso report (app/TechReport.php).
 * Permessi: tech_report.php (view / export) · tech_report_economics.php (produzione teorica e valore addebitato).
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/TechReport.php');

if (!can('view', 'tech_report.php')) { redirect('dashboard'); }
$u_id       = (int)$_SESSION['user_id'];
$can_export = can('export', 'tech_report.php');
$eco        = can('view', 'tech_report_economics.php');
$can_pd     = can('view', 'project_dashboard.php');

$m  = new ItServiceModel($pdo);
$f  = $m->normFilters($_GET);
$TR = new TechReport($m, $eco);
$tab = isset(TechReport::TABS[$_GET['tab'] ?? '']) ? (string)$_GET['tab'] : 'tecnici';
// v1.10.32 — filtri di colonna del Controllo Reperibilità (cf[i]): vista, stampa ed export
$f['cf'] = $tab === 'reperibilita' ? TechReport::colFiltri($_GET['cf'] ?? []) : [];
$det = ($_GET['det'] ?? '') === '1';
$cmx = isset($_GET['commessa']) ? mb_substr(trim((string)$_GET['commessa']), 0, 60) : null;
if ($cmx === '') $cmx = null;

$qs = function (array $over = []) use ($f, $tab, $det) {
    $p = ['from' => $f['from'], 'to' => $f['to'], 'ricavo' => $f['ricavo'], 'q' => $f['q'], 'cliente' => $f['cliente'], 'tab' => $tab !== 'tecnici' ? $tab : '', 'det' => $det ? '1' : ''];
    foreach (['contratti', 'linee', 'codici', 'settori', 'aziende', 'incaricati', 'modalita', 'fasce', 'durate', 'sedi', 'tipologie', 'prov', 'tariffe', 'uo'] as $k)
        if (!empty($f[$k])) $p[$k] = implode(',', $f[$k]);
    if (!empty($f['stati'])) $p['stato_commessa'] = implode(',', $f['stati']);
    if (!empty($f['cf']) && !array_key_exists('tab', $over)) $p['cf'] = $f['cf'];   // v1.10.32 — solo nella stessa scheda
    $p = array_merge($p, $over);
    return url_safe('tech_report', array_filter($p, fn($v) => $v !== '' && $v !== [] && $v !== null));
};

$h2 = fn($v) => $v === null || $v === '' ? '—' : number_format((float)$v, 2, ',', '.');
$h0 = fn($v) => $v === null || $v === '' ? '—' : number_format((float)$v, 0, ',', '.');
$pc = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.') . '%';
$dt = fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';

// ── drill-down: moduli di una commessa (frammento HTML) ─────────────────────
if (($_GET['ajax'] ?? '') === 'moduli' && $cmx !== null) {
    header('Content-Type: text/html; charset=utf-8');
    $n = $m->contaModuli($f, $cmx);
    $rows = $m->rapportiModuli($f, $cmx, 300);
    if (!$rows) { echo '<div class="tr-note">Nessun modulo.</div>'; exit; }
    echo '<table class="tr-t tr-sub"><thead><tr><th>Modulo</th><th>Data</th><th>Tecnico</th><th>Codice linea</th><th>Linea di servizio</th><th>Modalità</th><th class="r">Ore</th><th>Provenienza</th><th>Ticket</th><th>Attività DGB</th></tr></thead><tbody>';
    foreach ($rows as $r) echo '<tr><td>' . h($r['modulo']) . '</td><td>' . h($dt($r['giorno'])) . '</td><td>' . h($r['tecnico']) . '</td><td>' . h($r['codice_linea']) . '</td><td>' . h((string)($r['linea_label'] ?? '')) . '</td><td>'
        . h(ItServiceModel::etichetta($r['modalita'])) . '</td><td class="r">' . $h2($r['ore']) . '</td><td><span class="tr-pv tr-pv-' . h($r['provenienza']) . '">' . h(ItServiceModel::PROV[$r['provenienza']] ?? $r['provenienza']) . '</span></td><td>'
        . h(mb_strimwidth((string)$r['ticket'], 0, 60, '…')) . '</td><td>' . h((string)$r['attivita_dgb']) . '</td></tr>';
    echo '</tbody></table>';
    if ($n > count($rows)) echo '<div class="tr-note">Mostrati ' . $h0(count($rows)) . ' moduli su ' . $h0($n) . ': l\'export della commessa li contiene tutti.</div>';
    exit;
}

$vCtr = $m->valoriContratti();
$filtriTxt = implode(' · ', $m->descrizioneFiltri($f, $vCtr));

// ── stampa ed export ────────────────────────────────────────────────────────
$repFmt = (string)($_GET['rep'] ?? '');
$print  = ($_GET['print'] ?? '') === '1';
if ($repFmt !== '' || $print) {
    if ($repFmt !== '' && (!$can_export || !isset(PmReport::FORMATS[$repFmt]))) { http_response_code(403); exit('Export non consentito.'); }
    @set_time_limit(300); @ini_set('memory_limit', '1024M');
    $fmt  = $print ? 'print' : $repFmt;
    $data = $TR->data($tab, $f, $tab === 'rapporti' && $det, $tab === 'rapporti' ? $cmx : null,
                      in_array($fmt, ['docx', 'pdf', 'print'], true) ? TechReport::MAX_DOC : TechReport::MAX_FILE);
    $rep  = $TR->build($tab, $f, $data, $filtriTxt, $fmt, $tab === 'rapporti' ? $cmx : null);
    $base = 'relazione_tecnici_' . ($tab === 'reperibilita' ? 'controllo_reperibilita_' : '') . ($tab === 'rapporti' ? 'rapporti_' . ($cmx !== null ? PmReport::safe($cmx) . '_' : '') : '') . $f['from'] . '_' . $f['to'];
    write_log('TechReport', 'info', ($print ? 'Stampa' : 'Export ' . $repFmt) . ' Relazione Tecnici › ' . TechReport::TABS[$tab] . ($cmx !== null ? " (commessa $cmx)" : ''), $u_id,
        ['scheda' => $tab, 'formato' => $fmt, 'periodo' => $f['from'] . '..' . $f['to'], 'commessa' => $cmx, 'dettaglio' => $det]);
    if ($print) { header('Content-Type: text/html; charset=utf-8'); echo $rep->toHtml(true); exit; }
    $rep->send($repFmt, $base);
    exit;
}

// ── dati della vista ────────────────────────────────────────────────────────
$err = '';
try {
    // v1.10.32 — nella vista i filtri di colonna sono applicati dal browser (righe complete, filtri modificabili al volo)
    $D = $TR->data($tab, $tab === 'reperibilita' ? ['cf' => []] + $f : $f, false);
    if ($tab === 'reperibilita') $D['cf'] = $f['cf'];
    $vLin = $m->valori('linea_label'); $vCod = $m->valori('linea_servizio'); $vSet = $m->valori('settore'); $vAz = $m->valori('azienda');
    $vInc = $m->valori('incaricato'); $vSed = $m->valori('sede_riferimento'); $vTip = $m->valoriTipologie(); $vTar = $m->valoriTariffe();
} catch (Throwable $e) {
    $err = $e->getMessage(); $D = []; $vLin = $vCod = $vSet = $vAz = $vInc = $vSed = $vTip = $vTar = [];
}

$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');
?>
<style>
.tr-tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:2px solid #e2e8f0;margin:0 0 12px}
.tr-tabs a{padding:7px 14px;font-size:12px;font-weight:700;text-decoration:none;border-radius:6px 6px 0 0;margin-bottom:-2px;color:#475569;border:1px solid transparent}
.tr-tabs a.on{background:#fff;color:#0f172a;border-color:#e2e8f0 #e2e8f0 #fff}
.tr-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 12px}
.tr-bar .lbl{font-size:12px;font-weight:700;color:#334155}
.tr-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:0 0 14px}
.tr-kpi>div{background:#fff;border:1px solid #e2e8f0;border-top:3px solid var(--c);border-radius:10px;padding:10px 12px}
.tr-kpi b{display:block;font-size:20px;color:var(--c);font-variant-numeric:tabular-nums}
.tr-kpi span{display:block;font-size:11px;font-weight:700;color:#334155;text-transform:uppercase;letter-spacing:.3px}
.tr-kpi small{color:var(--muted);font-size:11px}
.tr-sec{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin:0 0 14px}
.tr-sec h2{font-size:15px;margin:0 0 8px;display:flex;gap:8px;align-items:center}
.tr-sec h2 small{font-weight:400;color:var(--muted);font-size:12px}
.tr-wrap{overflow:auto;max-height:72vh}
.tr-t{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}
.tr-t th,.tr-t td{padding:5px 8px;border-bottom:1px solid #f1f5f9;white-space:nowrap;text-align:left}
.tr-t thead th{position:sticky;top:0;background:#1e293b;color:#fff;font-weight:600;z-index:1}
.tr-t .r{text-align:right;font-variant-numeric:tabular-nums}
.tr-t tr.first td{border-top:1px solid #cbd5e1}
.tr-t tr.sub td{background:#f1f5f9;font-weight:700}
.tr-t tr.tot td{background:#e2e8f0;font-weight:800;position:sticky;bottom:0}
.tr-t td.muted{color:#94a3b8}
.tr-t a{color:#1d4ed8;text-decoration:none;font-weight:600}
.tr-t a:hover{text-decoration:underline}
.tr-sub{font-size:11px;margin:4px 0 8px}
.tr-sub thead th{position:static;background:#f1f5f9;color:#334155}
.tr-pv{display:inline-block;border-radius:10px;padding:1px 8px;font-size:10.5px;font-weight:700}
.tr-pv-ticket{background:#fee2e2;color:#b91c1c}.tr-pv-testo{background:#fef3c7;color:#92400e}.tr-pv-commessa{background:#dcfce7;color:#166534}
.tr-flag{display:inline-flex;gap:3px;align-items:center}
.tr-mini{display:inline-block;height:8px;width:90px;background:#f1f5f9;border-radius:4px;overflow:hidden;vertical-align:middle;display:inline-flex}
.tr-mini i{display:block;height:100%}
.tr-note{font-size:11px;color:#475569;background:#f8fafc;border-left:3px solid #cbd5e1;padding:6px 10px;margin:8px 0 0}
.tr-drill{cursor:pointer;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:0 6px;font-size:12px;line-height:18px}
tr.tr-dd>td{background:#fbfdff;padding:4px 10px 10px 28px;white-space:normal}
@media print{.pm-panel,.tr-bar,.tr-tabs{display:none}.tr-wrap{max-height:none;overflow:visible}}
</style>

<div style="margin-bottom:12px">
  <h1 style="font-size:20px;font-weight:800;margin:0"><i class="fa-solid fa-user-gear"></i> Relazione Tecnici</h1>
  <?php if (class_exists('PmSnapshot')) echo PmSnapshot::badge(); ?>
  <p style="color:var(--muted);font-size:12px;margin:2px 0 0">Operatività per tecnico e codice linea, metriche di dettaglio, moduli valorizzati e non valorizzati; rapporti di intervento per tipologia di contratto e commessa con la provenienza ticket. Stessi filtri e perimetro della Relazione di Servizio IT.</p>
</div>

<?php /* v1.10.26 — filtro globale: un solo componente (pannello «Filtri»), nessun blocco aggiuntivo */ ?>
<?php if ($err !== ''): ?>
  <div class="alert alert-warning"><strong>Dati non disponibili.</strong> <span style="font-size:11px"><?= h($err) ?></span></div>
  <?php require_once('footer.php'); exit; ?>
<?php endif; ?>

<?php
  $attivi = ($f['q'] !== '') + ($f['cliente'] !== '') + ($f['ricavo'] !== '');
  foreach (['contratti', 'stati', 'linee', 'codici', 'settori', 'aziende', 'incaricati', 'modalita', 'fasce', 'durate', 'sedi', 'tipologie', 'prov', 'tariffe', 'uo'] as $k) $attivi += count($f[$k]) > 0 ? 1 : 0;
?>
<details class="pm-panel" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary><i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?= $attivi ?></span><?php endif; ?>
    <span class="pm-hint">periodo <?= h($dt($f['from'])) ?> – <?= h($dt($f['to'])) ?></span></summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?>
      <?php if ($tab !== 'tecnici'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
      <?php foreach ($f['cf'] as $ci => $cv): ?><input type="hidden" class="tr-cf-h" name="cf[<?= (int)$ci ?>]" value="<?= h($cv) ?>"><?php endforeach; ?>
      <div class="pm-group">
        <h4>Contratto e stato commessa <span class="pm-multi">(filtro globale, come nella Relazione di Servizio IT)</span></h4>
        <div class="pm-grid-auto">
          <?= PmContractFilter::field($vCtr, $f['contratti'], 'vale anche per Relazione IT, Service Desk, Report direzionale, DGB') ?>
          <div class="form-group"><label>Stato commessa <span class="pm-multi">(multipla)</span></label>
            <select name="stato_commessa[]" multiple size="4" class="pm-ms" data-placeholder="Tutti">
              <?php foreach (ItServiceModel::STATI as $sk => $sl): ?><option value="<?= $sk ?>" <?= in_array($sk, $f['stati'], true) ? 'selected' : '' ?>><?= h($sl) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Tipologia contratto <span class="pm-multi">(multipla)</span></label>
            <select name="tipologie[]" multiple size="4" class="pm-ms" data-placeholder="Tutte">
              <?php foreach ($vTip as $k => $l): ?><option value="<?= h($k) ?>" <?= in_array($k, $f['tipologie'], true) ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Descrizione tariffa <span class="pm-multi">(multipla)</span></label>
            <select name="tariffe[]" multiple size="4" class="pm-ms" data-placeholder="Tutte">
              <?php foreach ($vTar as $v): ?><option value="<?= h($v) ?>" <?= in_array($v, $f['tariffe'], true) ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
              <?php foreach (array_diff($f['tariffe'], $vTar) as $v): ?><option value="<?= h($v) ?>" selected><?= h($v) ?> · (fuori elenco)</option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Provenienza modulo <span class="pm-multi">(multipla)</span></label>
            <select name="prov[]" multiple size="3" class="pm-ms" data-placeholder="Tutte">
              <?php foreach (ItServiceModel::PROV as $k => $l): ?><option value="<?= $k ?>" <?= in_array($k, $f['prov'], true) ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="pm-group">
        <h4>Periodo e ricerca</h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Dal</label><input type="date" name="from" value="<?= h($f['from']) ?>"></div>
          <div class="form-group"><label>Al</label><input type="date" name="to" value="<?= h($f['to']) ?>"></div>
          <div class="form-group"><label>Cerca ovunque</label><input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="commessa, cliente, modulo"></div>
          <div class="form-group"><label>Cliente</label><input type="text" name="cliente" value="<?= h($f['cliente']) ?>" placeholder="parte della ragione sociale"></div>
        </div>
      </div>
      <div class="pm-group">
        <h4>Servizio</h4>
        <div class="pm-grid-auto">
          <?php foreach ([['linee', 'Linea di servizio', $vLin], ['codici', 'Codice linea', $vCod], ['settori', 'Settore tecnologico', $vSet], ['aziende', 'Azienda esecutrice', $vAz]] as [$k, $lbl, $vals]): ?>
            <div class="form-group"><label><?= h($lbl) ?> <span class="pm-multi">(multipla)</span></label>
              <select name="<?= $k ?>[]" multiple size="3" class="pm-ms">
                <?php foreach ($vals as $v): ?><option value="<?= h($v) ?>" <?= in_array($v, $f[$k], true) ? 'selected' : '' ?>><?= h(ItServiceModel::etichetta($v)) ?></option><?php endforeach; ?></select></div>
          <?php endforeach; ?>
          <div class="form-group"><label>Natura</label>
            <select name="ricavo" class="pm-ms"><option value="">— tutte —</option>
              <option value="1" <?= $f['ricavo'] === '1' ? 'selected' : '' ?>>Commesse a ricavo</option>
              <option value="0" <?= $f['ricavo'] === '0' ? 'selected' : '' ?>>Commesse interne</option></select></div>
        </div>
      </div>
      <div class="pm-group">
        <h4>Erogazione</h4>
        <div class="pm-grid-auto">
          <?= PmUoFilter::field(PmUoFilter::options($pdo), $f['uo'], 'tecnico') ?>
          <?php foreach ([['incaricati', 'Tecnico / incaricato', $vInc], ['sedi', 'Sede di riferimento', $vSed],
                          ['modalita', 'Modalità', ['in sede', 'da remoto', 'presso cliente', 'smart working', 'reperibilita']],
                          ['fasce', 'Fascia oraria', ['in orario', 'fuori orario', 'non rilevata']], ['durate', 'Durata', ['giornata', 'mezza giornata', 'non rilevata']]] as [$k, $lbl, $vals]): ?>
            <div class="form-group"><label><?= h($lbl) ?> <span class="pm-multi">(multipla)</span></label>
              <select name="<?= $k ?>[]" multiple size="3" class="pm-ms">
                <?php foreach ($vals as $v): ?><option value="<?= h($v) ?>" <?= in_array($v, $f[$k], true) ? 'selected' : '' ?>><?= h(ItServiceModel::etichetta($v)) ?></option><?php endforeach; ?></select></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="pm-group">
        <h4>Dettagli da includere <span class="pm-multi">(stampa / export della scheda Rapporti di intervento)</span></h4>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px"><input type="checkbox" name="det" value="1" <?= $det ? 'checked' : '' ?>> Dettaglio dei moduli di intervento</label>
      </div>
      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?= url_safe('tech_report', array_filter(['contratti_set' => 1, 'tab' => $tab !== 'tecnici' ? $tab : null])) ?>">Azzera</a>
      </div>
    </form>
  </div>
</details>

<nav class="tr-tabs" aria-label="Schede">
  <?php foreach (TechReport::TABS as $k => $l): ?>
    <a class="<?= $k === $tab ? 'on' : '' ?>" href="<?= $qs(['tab' => $k === 'tecnici' ? null : $k]) ?>"><?= h($l) ?></a>
  <?php endforeach; ?>
</nav>

<div class="tr-bar">
  <a class="btn btn-sm" href="<?= $qs(['print' => '1']) ?>" target="_blank"><i class="fa-solid fa-print"></i> Stampa</a>
  <?php if ($can_export): ?>
    <span class="lbl" style="margin-left:6px"><i class="fa-solid fa-file-export"></i> Esporta <?= h(TechReport::TABS[$tab]) ?>:</span>
    <?php foreach (['csv' => ['CSV', 'fa-file-csv'], 'xlsx' => ['XLSX', 'fa-file-excel'], 'docx' => ['DOCX', 'fa-file-word'], 'pdf' => ['PDF', 'fa-file-pdf']] as $fx => [$fl, $fi]): ?>
      <a class="btn btn-sm" href="<?= $qs(['rep' => $fx]) ?>"><i class="fa-solid <?= $fi ?>"></i> <?= $fl ?></a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if ($tab === 'tecnici'):
    $tl = $D['tl']; $t = $tl['totale']; $gg = (int)$tl['gg_lavorabili']; ?>
  <div class="tr-kpi">
    <div style="--c:#2563eb"><b><?= $h0($t['tecnici'] ?? 0) ?></b><span>Tecnici</span><small><?= $h0($t['linee'] ?? 0) ?> codici linea</small></div>
    <div style="--c:#0891b2"><b><?= $h0($t['attivita'] ?? 0) ?></b><span>Attività</span><small><?= $h0($t['ticket'] ?? 0) ?> ticket</small></div>
    <div style="--c:#64748b"><b><?= $h0($gg) ?></b><span>GG lavorabili</span><small>lun–ven esclusi i festivi</small></div>
    <div style="--c:#7c3aed"><b><?= $h0($t['giornate_uomo'] ?? 0) ?></b><span>Giornate-uomo</span><small><?= $t['tecnici'] ?? 0 ? $h2(($t['giornate_uomo'] ?? 0) / max(1, $t['tecnici'])) . ' per tecnico' : '' ?></small></div>
    <div style="--c:#16a34a"><b><?= $h2($t['ore'] ?? 0) ?></b><span>Ore lavorate</span><small><?= $h2($t['ore_fuori_orario'] ?? 0) ?> h fuori orario</small></div>
  </div>

  <section class="tr-sec">
    <h2>Riepilogo per tecnico e codice linea <small><?= count($tl['tecnici']) ?> tecnici · <?= count($tl['righe']) ?> righe</small></h2>
    <div class="tr-wrap"><table class="tr-t">
      <thead><tr><?php foreach (TechReport::H_MAIN as $i => $hd): ?><th class="<?= in_array($i, [3, 4, 5, 6, 7], true) ? 'r' : '' ?>"><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if (!$D['righe']): ?><tr><td colspan="9" class="muted" style="text-align:center;padding:18px">Nessun modulo di intervento nel periodo con i filtri impostati.</td></tr><?php endif; ?>
      <?php $prev = null; foreach ($D['righe'] as $x): $v = TechReport::main($x, $gg); $sub = $x['tipo'] === 'sub'; $first = !$sub && $x['tecnico'] !== $prev; $prev = $x['tecnico']; ?>
        <tr class="<?= $sub ? 'sub' : ($first ? 'first' : '') ?>">
          <td><?= $sub || $first ? h($v[0]) : '<span class="muted">〃</span>' ?></td><td><?= h($v[1]) ?></td><td><?= h($v[2]) ?></td>
          <td class="r"><?= $h0($v[3]) ?></td><td class="r"><?= $h0($v[4]) ?></td><td class="r"><?= $h0($v[5]) ?></td>
          <td class="r"><?= $h0($v[6]) ?></td><td class="r"><?= $h2($v[7]) ?></td><td><?= h($v[8]) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($D['righe']): ?>
        <tr class="tot"><td>Totale</td><td><?= $h0($t['linee'] ?? 0) ?> linee</td><td></td><td class="r"><?= $h0($t['attivita'] ?? 0) ?></td><td class="r"><?= $h0($t['ticket'] ?? 0) ?></td>
          <td class="r"><?= $h0($gg) ?></td><td class="r"><?= $h0($t['giornate_uomo'] ?? 0) ?></td><td class="r"><?= $h2($t['ore'] ?? 0) ?></td><td></td></tr>
      <?php endif; ?>
      </tbody></table></div>
    <div class="tr-note">N. attività = moduli di intervento · N. ticket = riferimenti ticket distinti · GG lavorabili = lun–ven del periodo esclusi i festivi nazionali · GG uomo lavorati = giorni distinti con almeno un modulo (nel totale del tecnico un giorno lavorato su più linee conta una volta) · Descrizione tariffa = fascia oraria e unità dei moduli (es. «Fascia C (Ora)»), filtrabile dal pannello.</div>
  </section>

  <section class="tr-sec">
    <h2>Metriche di dettaglio <small>ore in h · Presso cl. / Remoto / Smart = n. interventi</small></h2>
    <div class="tr-wrap"><table class="tr-t">
      <thead><tr><?php foreach (TechReport::H_DET as $i => $hd): ?><th class="<?= $i >= 3 ? 'r' : '' ?>"><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php $prev = null; foreach ($D['righe'] as $x): $v = TechReport::det($x); $sub = $x['tipo'] === 'sub'; $first = !$sub && $x['tecnico'] !== $prev; $prev = $x['tecnico']; ?>
        <tr class="<?= $sub ? 'sub' : ($first ? 'first' : '') ?>">
          <td><?= $sub || $first ? h($v[0]) : '<span class="muted">〃</span>' ?></td><td><?= h($v[1]) ?></td><td><?= h($v[2]) ?></td>
          <td class="r"><?= $h0($v[3]) ?></td>
          <?php for ($i = 4; $i <= 8; $i++): ?><td class="r<?= (float)$v[$i] == 0 ? ' muted' : '' ?>"><?= $h2($v[$i]) ?></td><?php endfor; ?>
          <?php for ($i = 9; $i <= 11; $i++): ?><td class="r<?= (int)$v[$i] === 0 ? ' muted' : '' ?>"><?= $h0($v[$i]) ?></td><?php endfor; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($D['righe']): ?>
        <tr class="tot"><td>Totale</td><td><?= $h0($t['linee'] ?? 0) ?> linee</td><td></td><td class="r"><?= $h0($t['giornate_uomo'] ?? 0) ?></td>
          <?php foreach (['ore', 'ore_ordinarie', 'ore_fuori_orario', 'ore_reperibilita', 'ore_extra'] as $k): ?><td class="r"><?= $h2($t[$k] ?? 0) ?></td><?php endforeach; ?>
          <?php foreach (['presso_cliente', 'da_remoto', 'smart_working'] as $k): ?><td class="r"><?= $h0($t[$k] ?? 0) ?></td><?php endforeach; ?></tr>
      <?php endif; ?>
      </tbody></table></div>
  </section>

  <section class="tr-sec">
    <h2>Moduli di intervento <small>valorizzati (con tariffa di listino) e non valorizzati</small></h2>
    <?php $V = $D['val']; ?>
    <div class="tr-wrap"><table class="tr-t" style="max-width:<?= $eco ? '980' : '760' ?>px">
      <thead><tr><th></th><th class="r">Moduli</th><th class="r">%</th><th class="r">Ore</th><th class="r">Giornate-uomo</th><th class="r">Tecnici</th><th class="r">Commesse</th>
        <?php if ($eco): ?><th class="r">Produzione teorica</th><th class="r">Valore addebitato</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach (['val' => ['Valorizzati', '#16a34a'], 'nv' => ['Non valorizzati', '#dc2626'], 'tot' => ['Totale', '']] as $k => [$l, $c]): $x = $V[$k] ?? []; ?>
        <tr class="<?= $k === 'tot' ? 'tot' : '' ?>">
          <td><?php if ($c): ?><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:<?= $c ?>;margin-right:6px"></span><?php endif; ?><?= h($l) ?></td>
          <td class="r"><?= $h0($x['moduli'] ?? 0) ?></td><td class="r"><?= $pc(TechReport::pct($x['moduli'] ?? 0, $V['tot']['moduli'] ?? 0)) ?></td>
          <td class="r"><?= $h2($x['ore'] ?? 0) ?></td><td class="r"><?= $h0($x['giornate_uomo'] ?? 0) ?></td><td class="r"><?= $h0($x['tecnici'] ?? 0) ?></td><td class="r"><?= $h0($x['commesse'] ?? 0) ?></td>
          <?php if ($eco): ?><td class="r"><?= $k === 'nv' ? '—' : $h2($x['produzione_teorica'] ?? 0) . ' €' ?></td><td class="r"><?= $h2($x['valore_addebitato'] ?? 0) ?> €</td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>

    <details style="margin-top:10px" <?= count($D['valTec']) <= 15 ? 'open' : '' ?>>
      <summary style="cursor:pointer;font-size:12.5px;font-weight:700">Per tecnico (<?= count($D['valTec']) ?>)</summary>
      <div class="tr-wrap" style="margin-top:6px"><table class="tr-t">
        <thead><tr><th>Tecnico</th><th class="r">Valorizzati</th><th class="r">Ore val.</th><th class="r">Non valorizzati</th><th class="r">Ore non val.</th><th>Quota ore non valorizzate</th><?php if ($eco): ?><th class="r">Produzione teorica</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($D['valTec'] as $x): $q = TechReport::pct($x['ore_nv'], (float)$x['ore_val'] + (float)$x['ore_nv']); ?>
          <tr><td><?= h($x['tecnico']) ?></td><td class="r"><?= $h0($x['moduli_val']) ?></td><td class="r"><?= $h2($x['ore_val']) ?></td>
            <td class="r<?= (int)$x['moduli_nv'] === 0 ? ' muted' : '' ?>"><?= $h0($x['moduli_nv']) ?></td><td class="r<?= (float)$x['ore_nv'] == 0 ? ' muted' : '' ?>"><?= $h2($x['ore_nv']) ?></td>
            <td><span class="tr-mini"><i style="width:<?= (float)($q ?? 0) ?>%;background:#dc2626"></i></span> <span style="font-size:11px"><?= $pc($q) ?></span></td>
            <?php if ($eco): ?><td class="r"><?= $h2($x['produzione_teorica']) ?> €</td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </details>
    <div class="tr-note">Valorizzati = moduli con tariffa di listino della commessa; non valorizzati = commessa senza listino o combinazione fascia/unità non prevista (dettaglio dei motivi nella Relazione di Servizio IT).</div>
  </section>

<?php elseif ($tab === 'reperibilita'):
    $RR = $D['righe']; $dh = fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
    $vis = array_slice($RR, 0, 5000); ?>
  <div class="tr-kpi">
    <div style="--c:#a0442c"><b><?= $h0($D['notturni']) ?></b><span>Interventi in reperibilità</span><small><?= $h0($D['tecnici_notte']) ?> tecnici · modalità Reperibilità, 18:01–08:59</small></div>
    <div style="--c:#15803d"><b><?= $h0(count($RR)) ?></b><span>Con attività il giorno succ.</span><small><?= $pc(TechReport::pct(count($RR), $D['notturni'])) ?> degli interventi</small></div>
    <div style="--c:#2563eb"><b><?= $h0($D['tecnici']) ?></b><span>Tecnici</span><small>con almeno un caso</small></div>
  </div>

  <section class="tr-sec">
    <h2>Controllo Reperibilità <small>intervento in reperibilità → primo intervento ordinario (09:00–18:00) del giorno lavorativo successivo · <span id="trRepN"><?= $h0(count($vis)) ?></span> righe</small></h2>
    <div class="tr-wrap"><table class="tr-t" id="trRep">
      <thead>
        <tr><th class="tr-n-h"></th><th colspan="5" class="tr-rep-h">Intervento in reperibilità (modalità Reperibilità, 18:01–08:59)</th><th colspan="5" class="tr-gs-h">Primo intervento ordinario del giorno lavorativo successivo (09:00–18:00)</th></tr>
        <tr><?php foreach (TechReport::H_REP as $i => $hd): ?><th class="tr-<?= TechReport::H_REP_GRUPPO[$i] ?>-h"><?= h($hd) ?></th><?php endforeach; ?></tr>
        <tr class="tr-cf"><?php foreach (TechReport::H_REP as $i => $hd): ?><th><input type="search" data-col="<?= $i ?>" value="<?= h($D['cf'][$i] ?? '') ?>" placeholder="filtra…" aria-label="Filtra <?= h(TechReport::hRepEstese()[$i]) ?>"></th><?php endforeach; ?></tr>
      </thead>
      <tbody>
      <?php if (!$RR): ?><tr class="tr-empty"><td colspan="<?= count(TechReport::H_REP) ?>" class="muted" style="text-align:center;padding:18px">Nessun intervento in reperibilità seguito da attività ordinaria il giorno lavorativo successivo, con i filtri impostati.</td></tr><?php endif; ?>
      <?php foreach ($vis as $x): $v = TechReport::rep($x);   // v1.10.33 — 11 colonne, cliente / commessa / tipo per ciascun intervento ?>
        <tr>
          <td><?= h($v[0]) ?></td>
          <td class="tr-rep" title="Turno notturno del <?= h($dt($x['turno'])) ?>"><?= h($v[1]) ?><?php if (substr($x['rep_inizio'], 0, 10) !== $x['turno']): ?> <span class="muted" style="font-size:10.5px">· notte del <?= h(date('d/m', strtotime($x['turno']))) ?></span><?php endif; ?></td>
          <td class="tr-rep"><?= h($v[2]) ?></td>
          <td class="tr-rep" title="<?= h($v[3]) ?>"><?= h(mb_strimwidth($v[3], 0, 30, '…')) ?></td>
          <td class="tr-rep"><?php if ($can_pd && $x['project_id'] > 0): ?><a href="<?= url_safe('project_dashboard', ['id' => $x['project_id']]) ?>" title="Scheda commessa"><?= h($v[4]) ?></a><?php else: ?><?= h($v[4]) ?><?php endif; ?></td>
          <td class="tr-rep" title="<?= h($x['tipo_label']) ?>"><?= h($v[5]) ?></td>
          <td class="tr-gs"><?= h($v[6]) ?></td>
          <td class="tr-gs"><?= h($v[7]) ?></td>
          <td class="tr-gs" title="<?= h($v[8]) ?>"><?= h(mb_strimwidth($v[8], 0, 30, '…')) ?></td>
          <td class="tr-gs"><?php if ($can_pd && $x['succ_project_id'] > 0): ?><a href="<?= url_safe('project_dashboard', ['id' => $x['succ_project_id']]) ?>" title="Scheda commessa"><?= h($v[9]) ?></a><?php else: ?><?= h($v[9]) ?><?php endif; ?></td>
          <td class="tr-gs"><?= h($v[10]) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php if (count($RR) > count($vis) || $D['troncato']): ?><div class="tr-note">Vista limitata: <?= $h0(count($vis)) ?> righe su <?= $h0(count($RR)) ?><?= $D['troncato'] ? ' (interventi in reperibilità oltre il limite di elaborazione: restringere il periodo)' : '' ?>. L'export XLSX / CSV contiene tutte le righe.</div><?php endif; ?>
    <div class="tr-note">Reperibilità = modulo in modalità Reperibilità (come il filtro Modalità) con inizio fra le 18:01 e le 08:59 (turno del giorno di inizio se dopo le 18:01, del giorno precedente se prima delle 09:00) · Giorno succ. = primo giorno lavorativo (lun–ven, esclusi i festivi nazionali) dopo il turno: si riporta il primo modulo NON in reperibilità dello stesso tecnico con inizio 09:00–18:00 e non prima della fine dell'intervento in reperibilità · Cliente, Codice Commessa e Tipo (linea di servizio) sono riportati per ciascuno dei due interventi · i filtri del pannello si applicano agli interventi in reperibilità, i filtri di colonna alla tabella.</div>
  </section>
  <style>/* v1.10.34 — gruppi: tecnico neutro, reperibilità rosso mattone, giorno successivo verde */
  .tr-rep{background:#fbf1ee}.tr-gs{background:#f0fdf4}
  .tr-t thead th.tr-n-h{background:#<?= TechReport::C_NEUTRO ?>}.tr-t thead th.tr-r-h,.tr-t thead th.tr-rep-h{background:#<?= TechReport::C_REP ?>}.tr-t thead th.tr-g-h,.tr-t thead th.tr-gs-h{background:#<?= TechReport::C_GS ?>}
  .tr-t thead tr:nth-child(2) th{top:25px}.tr-t thead tr.tr-cf th{top:50px;background:#334155;padding:3px 4px}.tr-cf input{width:100%;min-width:90px;font-size:11px;padding:2px 5px;border:1px solid #cbd5e1;border-radius:4px}</style>
  <script>
  (function () {
    var t = document.getElementById('trRep'); if (!t) return;
    var ins = t.querySelectorAll('.tr-cf input'), rows = Array.prototype.slice.call(t.tBodies[0].rows).filter(function (r) { return !r.classList.contains('tr-empty'); }), out = document.getElementById('trRepN');
    function norm(s) { return (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    function apply() {
      var f = Array.prototype.map.call(ins, function (i) { return norm(i.value.trim()); }), n = 0;
      rows.forEach(function (r) {
        var ok = f.every(function (v, c) { return v === '' || norm(r.cells[c].textContent).indexOf(v) >= 0; });
        r.style.display = ok ? '' : 'none'; if (ok) n++;
      });
      if (out) out.textContent = n.toLocaleString('it-IT');
      // stampa ed export riportano gli stessi filtri di colonna (cf[i])
      document.querySelectorAll('.tr-bar a[href]').forEach(function (a) {
        var u = new URL(a.getAttribute('href'), document.baseURI);
        Array.prototype.slice.call(u.searchParams.keys()).filter(function (k) { return /^cf\[/.test(k); }).forEach(function (k) { u.searchParams.delete(k); });
        ins.forEach(function (i) { if (i.value.trim() !== '') u.searchParams.set('cf[' + i.dataset.col + ']', i.value.trim()); });
        a.setAttribute('href', u.toString());
      });
    }
    ins.forEach(function (i) { i.addEventListener('input', apply); });
    var pf = document.querySelector('.pm-panel form');
    function syncForm() {
      if (!pf) return;
      pf.querySelectorAll('.tr-cf-h').forEach(function (h) { h.remove(); });
      ins.forEach(function (i) { if (i.value.trim() === '') return; var h = document.createElement('input'); h.type = 'hidden'; h.className = 'tr-cf-h'; h.name = 'cf[' + i.dataset.col + ']'; h.value = i.value.trim(); pf.appendChild(h); });
    }
    ins.forEach(function (i) { i.addEventListener('input', syncForm); });
    if (Array.prototype.some.call(ins, function (i) { return i.value.trim() !== ''; })) apply();
  })();
  </script>
<?php elseif ($tab === 'rapporti'):
    $tp = $D['tipologie']; $cm = $D['commesse'];
    $S = fn(array $rows, string $k) => array_sum(array_map(fn($x) => (float)$x[$k], $rows));
    $mod = $S($tp, 'moduli'); ?>
  <div class="tr-kpi">
    <div style="--c:#2563eb"><b><?= $h0($mod) ?></b><span>Moduli di intervento</span><small><?= $h0(count($cm)) ?> commesse</small></div>
    <div style="--c:#dc2626"><b><?= $h0($S($tp, 'da_ticket')) ?></b><span>Da ticket (codice)</span><small><?= $pc(TechReport::pct($S($tp, 'da_ticket'), $mod)) ?></small></div>
    <div style="--c:#f59e0b"><b><?= $h0($S($tp, 'da_testo')) ?></b><span>Riferimento libero</span><small><?= $pc(TechReport::pct($S($tp, 'da_testo'), $mod)) ?></small></div>
    <div style="--c:#16a34a"><b><?= $h0($S($tp, 'da_commessa')) ?></b><span>Da commessa</span><small><?= $pc(TechReport::pct($S($tp, 'da_commessa'), $mod)) ?></small></div>
    <div style="--c:#0891b2"><b><?= $h2($S($tp, 'ore')) ?></b><span>Ore</span></div>
  </div>

  <section class="tr-sec">
    <h2>Per tipologia di contratto <small>filtrabile dal pannello «Filtri» (Tipologia contratto)</small></h2>
    <div class="tr-wrap"><table class="tr-t">
      <thead><tr><th>Tipologia contratto</th><th class="r">Commesse</th><th class="r">Moduli</th><th class="r">Ticket (codice)</th><th class="r">Rif. libero</th><th class="r">Da commessa</th><th>Provenienza</th><th class="r">Ticket distinti</th><th class="r">Tecnici</th><th class="r">Ore</th></tr></thead>
      <tbody>
      <?php if (!$tp): ?><tr><td colspan="10" class="muted" style="text-align:center;padding:18px">Nessun modulo nel periodo con i filtri impostati.</td></tr><?php endif; ?>
      <?php foreach ($tp as $x): $n = max(1, (int)$x['moduli']); ?>
        <tr><td><?= h(ItServiceModel::tipologia($x['tipologia'])) ?></td>
          <td class="r"><?= $h0($x['commesse']) ?></td><td class="r"><?= $h0($x['moduli']) ?></td>
          <td class="r"><?= $h0($x['da_ticket']) ?></td><td class="r"><?= $h0($x['da_testo']) ?></td><td class="r"><?= $h0($x['da_commessa']) ?></td>
          <td><span class="tr-mini" title="ticket / rif. libero / da commessa"><i style="width:<?= round($x['da_ticket'] / $n * 100, 2) ?>%;background:#dc2626"></i><i style="width:<?= round($x['da_testo'] / $n * 100, 2) ?>%;background:#f59e0b"></i><i style="width:<?= round($x['da_commessa'] / $n * 100, 2) ?>%;background:#16a34a"></i></span>
            <span style="font-size:11px"><?= $pc(TechReport::pct($x['da_ticket'], $x['moduli'])) ?> ticket</span></td>
          <td class="r"><?= $h0($x['ticket']) ?></td><td class="r"><?= $h0($x['tecnici']) ?></td><td class="r"><?= $h2($x['ore']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($tp): ?><tr class="tot"><td>Totale</td><td class="r"><?= $h0($S($tp, 'commesse')) ?></td><td class="r"><?= $h0($mod) ?></td><td class="r"><?= $h0($S($tp, 'da_ticket')) ?></td><td class="r"><?= $h0($S($tp, 'da_testo')) ?></td><td class="r"><?= $h0($S($tp, 'da_commessa')) ?></td><td><?= $pc(TechReport::pct($S($tp, 'da_ticket'), $mod)) ?> ticket</td><td></td><td></td><td class="r"><?= $h2($S($tp, 'ore')) ?></td></tr><?php endif; ?>
      </tbody></table></div>
  </section>

  <section class="tr-sec">
    <h2>Per commessa <small><?= $h0(count($cm)) ?> commesse · apri una riga per i moduli; la provenienza è evidenziata</small></h2>
    <div class="tr-wrap"><table class="tr-t" id="trCm">
      <thead><tr><th></th><th>Commessa</th><th>Denominazione</th><th>Cliente</th><th>Codice linea</th><th>Linea di servizio</th><th>Tipologia</th><th class="r">Moduli</th><th class="r">Ticket (codice)</th><th class="r">Rif. libero</th><th class="r">Da commessa</th><th class="r">Tecnici</th><th class="r">Ore</th><th>Periodo</th><?php if ($can_export): ?><th>Export</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($cm as $x): $code = (string)$x['commessa']; ?>
        <tr data-c="<?= h($code) ?>">
          <td><button type="button" class="tr-drill" aria-expanded="false" title="Moduli della commessa">▸</button></td>
          <td><?php if ($can_pd && (int)$x['project_id'] > 0): ?><a href="<?= url_safe('project_dashboard', ['id' => (int)$x['project_id']]) ?>" title="Scheda commessa"><?= h($code) ?></a><?php else: ?><?= h($code) ?><?php endif; ?></td>
          <td title="<?= h((string)$x['denominazione']) ?>"><?= h(mb_strimwidth((string)$x['denominazione'], 0, 42, '…')) ?></td>
          <td title="<?= h((string)$x['cliente']) ?>"><?= h(mb_strimwidth((string)$x['cliente'], 0, 32, '…')) ?></td>
          <td><?= h((string)$x['codice_linea']) ?></td><td title="<?= h((string)($x['linea_label'] ?? '')) ?>"><?= h(mb_strimwidth((string)($x['linea_label'] ?? ''), 0, 34, '…')) ?></td><td><?= h(ItServiceModel::tipologia($x['tipologia'])) ?></td>
          <td class="r"><?= $h0($x['moduli']) ?></td>
          <td class="r"><?= (int)$x['da_ticket'] ? '<span class="tr-pv tr-pv-ticket">' . $h0($x['da_ticket']) . '</span>' : '<span class="muted">0</span>' ?></td>
          <td class="r"><?= (int)$x['da_testo'] ? '<span class="tr-pv tr-pv-testo">' . $h0($x['da_testo']) . '</span>' : '<span class="muted">0</span>' ?></td>
          <td class="r"><?= $h0($x['da_commessa']) ?></td><td class="r"><?= $h0($x['tecnici']) ?></td><td class="r"><?= $h2($x['ore']) ?></td>
          <td style="font-size:11px"><?= h($dt($x['dal'])) ?> – <?= h($dt($x['al'])) ?></td>
          <?php if ($can_export): ?><td style="font-size:11px"><a href="<?= $qs(['rep' => 'xlsx', 'commessa' => $code]) ?>" title="Moduli della commessa in XLSX">XLSX</a> · <a href="<?= $qs(['rep' => 'csv', 'commessa' => $code]) ?>">CSV</a> · <a href="<?= $qs(['rep' => 'pdf', 'commessa' => $code]) ?>">PDF</a></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($cm): ?><tr class="tot"><td></td><td>Totale</td><td colspan="5"><?= $h0(count($cm)) ?> commesse</td><td class="r"><?= $h0($S($cm, 'moduli')) ?></td><td class="r"><?= $h0($S($cm, 'da_ticket')) ?></td><td class="r"><?= $h0($S($cm, 'da_testo')) ?></td><td class="r"><?= $h0($S($cm, 'da_commessa')) ?></td><td></td><td class="r"><?= $h2($S($cm, 'ore')) ?></td><td></td><?php if ($can_export): ?><td></td><?php endif; ?></tr><?php endif; ?>
      </tbody></table></div>
    <div class="tr-note">Provenienza dal campo ticket del modulo: <span class="tr-pv tr-pv-ticket">Ticket (codice)</span> riporta un codice ticket (es. WTS_000000070) · <span class="tr-pv tr-pv-testo">Riferimento libero</span> campo compilato con un testo · <span class="tr-pv tr-pv-commessa">Da commessa</span> nessun ticket, modulo generato dalla commessa.</div>
  </section>
  <script>
  (function () {
    var base = <?= json_encode(html_entity_decode($qs(['ajax' => 'moduli']))) ?>;
    document.querySelectorAll('#trCm .tr-drill').forEach(function (b) {
      b.addEventListener('click', function () {
        var tr = b.closest('tr'), nx = tr.nextElementSibling;
        if (nx && nx.classList.contains('tr-dd')) { nx.remove(); b.setAttribute('aria-expanded', 'false'); b.textContent = '▸'; return; }
        var dd = document.createElement('tr'); dd.className = 'tr-dd'; var td = document.createElement('td'); td.colSpan = tr.children.length; td.textContent = 'Caricamento…'; dd.appendChild(td); tr.after(dd);
        b.setAttribute('aria-expanded', 'true'); b.textContent = '▾';
        fetch(base + (base.indexOf('?') < 0 ? '?' : '&') + 'commessa=' + encodeURIComponent(tr.dataset.c), {credentials: 'same-origin'})
          .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
          .then(function (html) { td.innerHTML = html; })
          .catch(function (e) { td.textContent = 'Errore nel caricamento: ' + e.message; });
      });
    });
  })();
  </script>
<?php endif; ?>

<?php require_once('footer.php'); ?>
