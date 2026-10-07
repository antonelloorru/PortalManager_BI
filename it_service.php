<?php
/**
 * it_service.php — Relazione di Servizio IT (v1.8.90)
 *
 * Filtri combinabili su tutte le dimensioni, raggruppamento libero, grafici,
 * export XLSX/DOCX, selezione dettagli per stampa/export, report a colori. (v1.9.46)
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
$itModel = $it;   // v1.10.13 — il template riusa $it come variabile di ciclo
$f  = $it->normFilters($_GET);

// v1.9.46 — dettagli selezionabili prima di stampa/export
$INC_ALL = ['quadro','andamento','dettaglio','giorni','costi','contratti','commesse','senzamodulo'];
$INC_LBL = ['quadro'=>'Quadro / KPI','andamento'=>'Andamento mensile','dettaglio'=>'Dettaglio interventi',
            'giorni'=>'Giorni per operatore','costi'=>'Riepilogo costi',
            'contratti'=>'Riepilogo per contratto','commesse'=>'Dettaglio per commessa',
            'senzamodulo'=>'Attività DGB senza modulo'];
$inc = isset($_GET['inc']) ? array_values(array_intersect($INC_ALL, (array)$_GET['inc'])) : $INC_ALL;
if (!$inc) $inc = $INC_ALL;
$incOn = fn(string $k): bool => in_array($k, $inc, true);

// v1.9.73 — righe di un singolo contratto, richieste quando l'utente lo apre nel
// "Dettaglio per commessa". Stessi filtri e stessi permessi della pagina.
if (($_GET['ajax'] ?? '') === 'dett_commessa') {
    $cid = (int)($_GET['cid'] ?? 0);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($cid <= 0) { http_response_code(400); exit; }
    $nf = fn($v) => number_format((float)$v, 2, ',', '.');
    foreach ($it->dettaglioCommessa($f, $cid) as $r) {
        echo '<tr><td>' . h((string)$r['report_date']) . '</td><td>' . h((string)$r['operator_name']) . '</td>'
           . '<td><code>' . (h((string)$r['ticket']) ?: '—') . '</code></td><td>' . h((string)$r['fascia']) . '</td>'
           . '<td>' . h((string)$r['regime']) . '</td><td>' . $nf($r['ore']) . '</td>'
           . '<td>' . $nf($r['costo_contratto']) . '</td><td>' . $nf($r['tot_costo_tab']) . "</td></tr>\n";
    }
    exit;
}

$pronto = true; $errore = '';
try {
    $tot   = $it->totali($f);
    $righe = $it->aggrega($f, 500);
    $trend = $it->andamento($f);
    $trendG = $it->andamentoGiornaliero($f);   // v1.9.73 — andamento giornaliero
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
    $vCtr  = $it->valoriContratti();          // v1.9.77 — Codice Contratto / PM Project

    // v1.9.15 — costi per fascia e contratto, stesse viste del Service Desk
    $cQ2   = $it->costiQuadro($f);
    $cRie2 = $it->costiRiepilogo($f);

    // v1.9.19 — giorni lavorati per persona
    $gQ    = $it->giorniQuadro($f);
    $gOp   = $it->giorniOperatore($f);
    $gAr   = $it->giorniArea($f);
    // v1.9.88 — ripartizione dei giorni (ore valorizzate e non) per le dimensioni richieste
    $gCls  = $it->classiPerPersona($f);      // v1.9.89 — ore per classe per persona (stessa regola dei KPI)
    $gNv   = $it->nonValorizzate($f);        // v1.9.89 — dettaglio ore non valorizzate
    $gDim  = [];
    foreach (array_keys(ItServiceModel::GIORNI_DIM) as $gd) $gDim[$gd] = $it->giorniPer($f, $gd);
    // [PM_V1_9_34_APPLIED]
    // v1.9.73 — a schermo solo la sintesi per contratto; il dettaglio completo serve
    // all'export DOCX (la stampa lo calcola da sé in app/it_service_print.php)
    $dettFull     = (($_GET['export'] ?? '') === 'docx');
    $dettCommessa = $dettFull ? $it->dettaglioCommessa($f) : [];
    $dettSintesi  = ($dettFull || ($_GET['print'] ?? '') === '1') ? [] : $it->dettaglioCommessaSintesi($f);
    // [PM_V1_9_35_APPLIED]
    $riepContratto = $it->riepilogoContratto($f);
    // v1.9.90 — filtri applicati e attività DGB fuori perimetro, per le sezioni per contratto
    $filtriTxt = $it->descrizioneFiltri($f, $vCtr);
    $nSm       = $it->attivitaSenzaModulo($f);
    // v1.9.91 — dettaglio delle attività DGB senza modulo (sezione dedicata)
    $smDett    = $it->attivitaSenzaModuloDettaglio($f);
    $smNonAppl = ItServiceModel::filtriNonApplicabiliSenzaModulo($f);
} catch (Throwable $e) {
    $pronto = false; $errore = $e->getMessage();
    $trendG = ['from' => '', 'to' => '', 'rows' => []];
    $dettCommessa = $dettSintesi = [];
    $tot = $km = []; $righe = $trend = $gMod = $gLin = $gSet = $gDur = $gFas = [];
    // v1.9.19 — nel ramo di errore le variabili vanno AZZERATE, non ricalcolate.
    //
    // Erano finite qui per errore: se il caricamento principale fallisce,
    // rifare le stesse query nel catch le fa fallire di nuovo, e nel percorso
    // normale le variabili restano indefinite. Il template le usa comunque, e
    // PHP produce un avviso su ogni riferimento.
    $cQ2 = $gQ = []; $cRie2 = $gOp = $gAr = $gDim = $gCls = $gNv = [];
    $dettCommessa = []; $riepContratto = []; $filtriTxt = []; $nSm = ['attivita' => 0, 'ore' => 0, 'motivi' => []]; $smDett = []; $smNonAppl = [];
    $vLin = $vSet = $vInc = $vSed = $vCod = $vAz = []; $gCod = $gAz = []; $vCtr = [];
}

$COL = ['#2563eb','#16a34a','#f59e0b','#dc2626','#7c3aed','#0891b2','#db2777','#65a30d',
        '#ea580c','#0d9488','#9333ea','#4f46e5'];
$colMod = ['in sede'=>'#2563eb','da remoto'=>'#0d9488','presso cliente'=>'#f59e0b',
           'smart working'=>'#0891b2','reperibilita'=>'#7c3aed','reperibilità'=>'#7c3aed'];

// ── export XLSX, con foglio pivot ───────────────────────────────────────────
if ($pronto && ($_GET['export'] ?? '') === 'xlsx') {
    require_once(__DIR__ . '/app/XlsxWriter.php');
    $w = new XlsxWriter();

    // foglio 1: l'aggregazione come mostrata a video
    $int = [array_merge(array_map(fn($g) => ItServiceModel::DIM[$g], $f['gb']),
        ['Interventi','Giornate-uomo','Ore totali','Ore ordinarie','Ore fuori orario','Ore reperibilità',
         'Ore non classificate','Ore extra','Ore viaggio','Km',
         'N. giornate','N. mezze giornate','N. presso cliente','N. da remoto','N. smart working',
         'N. interventi reperibilità','N. interventi fuori orario','Ore a ricavo'])];
    foreach ($righe as $r) {
        $riga = [];
        foreach ($f['gb'] as $g) $riga[] = $r[$g];
        $int[] = array_merge($riga, [(int)$r['interventi'], (int)$r['giornate_uomo'],
            $r['ore'], $r['ore_ordinarie'], $r['ore_fuori_orario'], $r['ore_reperibilita'], $r['ore_non_classificate'],
            $r['ore_extra'], $r['ore_viaggio'], $r['km'],
            (int)$r['giornate'], (int)$r['mezze_giornate'], (int)$r['presso_cliente'],
            (int)$r['da_remoto'], (int)$r['smart_working'], (int)$r['reperibilita'],
            (int)$r['fuori_orario'], $r['ore_ricavo']]);
    }
    // v1.9.19 — i giorni lavorati nell'export
    $clsX = $it->classiPerPersona($f);
    $rgo = [['Operatore','Giorni lavorati','Interventi','Ore','Ore ordinarie','Ore fuori orario','Ore reperibilità',
             'Giorni con reperibilità','Ore valorizzate','Ore non valorizzate',
             'Giorni con ore non valorizzate','Giornate equiv.','Ore/giorno',
             'Giorni fascia tariffaria A','Giorni fascia tariffaria B','Giorni fascia tariffaria C','Giorni fascia tariffaria D','Giorni fascia tariffaria E','Giorni fascia tariffaria X',
             'Ore fascia tariffaria C','Ore fascia tariffaria D','Aree','Linee','Commesse','Clienti',
             'Produzione teorica','Produzione/giorno','Addebitato','Righe senza tariffa',
             'Dal','Al']];
    foreach ($it->giorniOperatore($f) as $x) $rgo[] = [$x['operatore'],
        (int)$x['giorni_lavorati'], (int)$x['interventi'], $x['ore'],
        (float)($clsX[$x['operatore']]['ore_ordinarie'] ?? 0), (float)($clsX[$x['operatore']]['ore_fuori_orario'] ?? 0),
        (float)($clsX[$x['operatore']]['ore_reperibilita'] ?? 0), (int)($clsX[$x['operatore']]['giorni_reperibilita'] ?? 0),
        $x['ore_valorizzate'],
        $x['ore_non_valorizzate'], (int)$x['giorni_non_valorizzati'], $x['giornate_equiv'],
        $x['ore_per_giorno'], (int)$x['giorni_A'], (int)$x['giorni_B'], (int)$x['giorni_C'],
        (int)$x['giorni_D'], (int)$x['giorni_E'], (int)$x['giorni_X'], $x['ore_C'], $x['ore_D'],
        (int)$x['aree'], (int)$x['linee'], (int)$x['commesse'], (int)$x['clienti'], $x['produzione_teorica'],
        $x['produzione_per_giorno'], $x['valore_addebitato'], (int)$x['righe_senza_tariffa'],
        $x['dal'], $x['al']];
    $w->addSheet('Giorni per operatore', $rgo);

    $rga = [['Operatore','Area tecnologica','Giorni','Interventi','Ore','Quota ore %',
             'Commesse','Produzione teorica']];
    foreach ($it->giorniArea($f) as $x) $rga[] = [$x['operatore'], $x['area_tecnologica'],
        (int)$x['giorni'], (int)$x['interventi'], $x['ore'], $x['quota_ore_pct'],
        (int)$x['commesse'], $x['produzione_teorica']];
    $w->addSheet('Giorni per area', $rga);

    // v1.9.89 — dettaglio ore non valorizzate
    $rnv = [['Codice linea','Linea di servizio','Commessa','Cliente','Persona','Interventi','Giorni','Ore',
             'Motivo','Fascia · unità senza tariffa','Dal','Al']];
    foreach ($it->nonValorizzate($f, 100000) as $x) $rnv[] = [$x['codice_linea'], $x['contratto'], $x['commessa'],
        $x['cliente'], $x['operatore'], (int)$x['interventi'], (int)$x['giorni'], $x['ore'], $x['motivo'],
        $x['combinazioni'], $x['dal'], $x['al']];
    $w->addSheet('Ore non valorizzate', $rnv);

    // v1.9.88 — giorni per codice linea, area tecnologica e dimensioni correlate
    foreach (ItServiceModel::GIORNI_DIM as $gd => $gl) {
        // v1.9.92 — foglio «Giorni per commessa»: Cliente e Descrizione (da Commesse / Progetti) prima di Commessa
        $perComm = ($gd === 'commessa');
        $rr = [array_merge($perComm ? ['Cliente', 'Descrizione'] : [], [$gl, 'Persone', 'Giorni-uomo', 'Giorni-uomo con ore non valorizzate', 'Interventi', 'Ore',
                'Ore valorizzate', 'Ore non valorizzate', 'Giornate equiv.', 'Commesse', 'Produzione teorica'])];
        foreach ($it->giorniPer($f, $gd, 5000) as $x) $rr[] = array_merge(
            $perComm ? [(string)($x['cliente'] ?? ''), (string)($x['descrizione'] ?? '')] : [],
            [ItServiceModel::etichetta((string)$x['voce']),
            (int)$x['persone'], (int)$x['giorni_uomo'], (int)$x['giorni_uomo_non_val'], (int)$x['interventi'],
            $x['ore'], $x['ore_valorizzate'], $x['ore_non_valorizzate'], $x['giornate_equiv'],
            (int)$x['commesse'], $x['produzione_teorica']]);
        $w->addSheet(mb_substr('Giorni per ' . mb_strtolower($gl), 0, 31), $rr);
    }

    $w->addSheet('Dettaglio', $int);

    // v1.9.91 — attività DGB senza modulo di intervento (una riga per attività)
    $rsm = [['Data','Attività','Ticket','Stato DGB','Motivo','Contratto','PM Project','Codice linea','Cliente',
             'Operatore','Ore (pianificate/allocate)','Allocazione','Scadenza']];
    foreach ($it->attivitaSenzaModuloDettaglio($f, true, 100000) as $x) $rsm[] = [$x['data'], $x['codice'], $x['ticket'],
        $x['stato'], $x['motivo'], $x['contratto'], $x['pm_project'], $x['codice_linea'], $x['cliente'], $x['operatore'],
        $x['ore'], (int)$x['allocata'] ? 'sì' : 'no', $x['scadenza']];
    $w->addSheet('DGB senza modulo', $rsm);

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
        foreach ($dati as $d) $rr[] = [ItServiceModel::etichetta($d['voce']), (int)$d['interventi'], $d['ore'], (int)$d['giornate_uomo']];
        $w->addSheet(mb_substr($et, 0, 28), $rr);
    }

    write_log('Projects', 'info', "Export Relazione IT {$f['from']}..{$f['to']}", $u_id);
    // v1.9.77 — perimetro dell'estrazione: periodo e filtri applicati
    $rf = [['Parametro', 'Valore'], ['Periodo', date('d/m/Y', strtotime($f['from'])) . ' – ' . date('d/m/Y', strtotime($f['to']))]];
    foreach ($it->descrizioneFiltri($f, $vCtr) as $x) { [$k, $v] = explode(': ', $x, 2) + [1 => '']; $rf[] = [$k, $v]; }
    $rf[] = ['Generato il', date('d/m/Y H:i')];
    $w->addSheet('Filtri', $rf);
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
        $c = $colori[$d['voce']] ?? $colori[mb_strtolower(trim((string)$d['voce']))] ?? $colori[$i % count($colori)] ?? '#2563eb';
        $lab = ItServiceModel::etichetta($d['voce']);   // v1.9.76 — «reperibilita» → «Reperibilità»
        $o .= '<text x="' . ($lw - 6) . '" y="' . ($y + 11) . '" text-anchor="end" font-size="10" fill="#334155">'
            . htmlspecialchars(mb_strimwidth($lab, 0, 24, '…')) . '</text>';
        $o .= '<rect x="' . $lw . '" y="' . ($y + 2) . '" width="' . round(max(1, $l), 1)
            . '" height="12" fill="' . $c . '" rx="2"><title>' . htmlspecialchars($lab)
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

// v1.10.13 — Relazione come report DOCX / XLSX / CSV / PDF dal solo filtro principale (app/it_service_report.php):
// generale, personale (un solo incaricato nel filtro) o uno per incaricato (ZIP). «export=docx» resta come alias.
$repFmt = (string)($_GET['rep'] ?? (($_GET['export'] ?? '') === 'docx' ? 'docx' : ''));
if ($pronto && $repFmt !== '') {
    require_once(__DIR__ . '/app/it_service_report.php');
    if (!isset(PmReport::FORMATS[$repFmt]) || !can('export', 'it_service.php')) { http_response_code(403); exit('Export non consentito.'); }
    @set_time_limit(0);
    $tgt  = (isset($_GET['target']) && is_numeric($_GET['target'])) ? (float)$_GET['target'] : null;
    $base = "relazione_servizio_it_{$f['from']}_{$f['to']}";
    if (!empty($_GET['rep_zip']) && count($f['incaricati']) !== 1) {
        $items = it_service_report_tecnici($it, $f);
        write_log('Projects', 'info', 'Relazione IT per incaricato ' . strtoupper($repFmt) . ' (ZIP, ' . count($items) . ") {$f['from']}..{$f['to']}", $u_id);
        PmReport::bundle($repFmt, $items, fn($k) => it_service_report($it, ['incaricati' => [(string)$k]] + $f, $vCtr, $inc, $repFmt, $tgt), $base . '_incaricati');
    }
    $pers = count($f['incaricati']) === 1 ? $f['incaricati'][0] : '';
    write_log('Projects', 'info', 'Relazione IT ' . strtoupper($repFmt) . ($pers !== '' ? " personale ($pers)" : ' generale') . " {$f['from']}..{$f['to']}", $u_id);
    it_service_report($it, $f, $vCtr, $inc, $repFmt, $tgt)->send($repFmt, $base . ($pers !== '' ? '_' . $pers : '_generale'));
}

// v1.9.90 — un solo blocco filtri: il pannello principale (server-side, $f).
// Il filtro automatico di footer.php (ListFilter::renderAuto) agganciava una seconda barra
// client-side (ricerca, filtri per colonna, viste, export) alla tabella con più righe — il
// «Riepilogo per Codice Contratto» o il «Dettaglio» — che filtrava solo le righe a video, non
// aggiornava totali, sezioni collegate, stampa ed export e non era sincronizzata col pannello.
$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');
// v1.9.90 — rimosso pm-ui-boost (patch v1.9.34): secondo motore di multi-select sulle stesse
// select già gestite da pm-multiselect (header.php), con stato non condiviso.


$qs = function (array $over = []) use ($f, $inc, $INC_ALL) {
    $p = ['from' => $f['from'], 'to' => $f['to'], 'ricavo' => $f['ricavo'],
          'q' => $f['q'], 'cliente' => $f['cliente']];
    foreach (['contratti','linee','codici','settori','aziende','incaricati','modalita','fasce','durate','sedi','gb'] as $k)
        if (!empty($f[$k])) $p[$k] = implode(',', $f[$k]);
    if (!empty($f['stati'])) $p['stato_commessa'] = implode(',', $f['stati']);   // v1.9.87
    if (count($inc) < count($INC_ALL)) $p['inc'] = $inc; // subset -> inc[] nei link stampa/export
    return url_safe('it_service', array_merge(array_filter($p, fn($v) => $v !== '' && $v !== []), $over));
};
?>

<div style="margin-bottom:14px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-server"></i> Relazione di Servizio IT</h1>
  <?php if (class_exists('PmSnapshot')) echo PmSnapshot::badge(); ?>
  <p style="color:var(--muted);font-size:12px;margin-top:2px">
    Operatività per incaricato, linea di servizio, settore tecnologico, modalità e fascia oraria.
  </p>
</div>

<?= PmContractFilter::banner($f['contratti'], $vCtr, $qs(['contratti' => null, 'contratti_set' => 1])) ?>
<?php
  // v1.9.79 — rapportini senza attivita DGB: modalita e fascia oraria non ricavabili
  if ($pronto) {
      require_once(__DIR__ . '/app/PmReportLink.php');
      $nScoll = PmReportLink::unlinked($pdo, $f['from'], $f['to']);
      if ($nScoll > 0): ?>
  <div class="alert alert-warning" style="font-size:12px"><i class="fa-solid fa-link-slash"></i>
    <strong><?=$hh($nScoll)?> moduli di intervento</strong> del periodo non sono collegati a un'attività DGB:
    per questi modalità (smart working, reperibilità, da remoto) e fascia oraria non sono rilevabili.
    Il collegamento si ripristina alla prossima sincronizzazione o dopo l'import DGB.</div>
<?php endif; } ?>
<?php if (!$pronto): ?>
  <div class="alert alert-warning"><strong>Dati non disponibili.</strong>
    Eseguire la migration v1.8.89.
    <div style="font-size:11px;color:var(--muted);margin-top:4px"><?=h($errore)?></div></div>
  <?php require_once('footer.php'); exit; ?>
<?php endif; ?>

<?php // v1.9.8 — pannello uniformato al template di Commesse/Progetti ?>
<?php
  $attivi = ($f['q'] !== '') + ($f['cliente'] !== '') + ($f['ricavo'] !== '');
  foreach (['contratti','stati','linee','codici','settori','aziende','incaricati','modalita','fasce','durate','sedi'] as $k)
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
        <h4>Contratto e stato commessa <span class="pm-multi">(filtro globale: KPI, grafici, tabelle, costi, giorni, DGB, stampa ed export)</span></h4>
        <div class="pm-grid-auto">
          <?= PmContractFilter::field($vCtr, $f['contratti'], 'vale anche per Service Desk, Report direzionale, DGB') ?>
          <?php // v1.9.87 — stato della commessa (anagrafica PM Project), applicato a tutte le sezioni ?>
          <div class="form-group"><label>Stato commessa <span class="pm-multi">(multipla)</span></label>
            <select name="stato_commessa[]" multiple size="4" class="pm-ms" data-placeholder="Tutti">
              <?php foreach (ItServiceModel::STATI as $sk => $sl): ?>
                <option value="<?=$sk?>" <?=in_array($sk, $f['stati'], true) ? 'selected' : ''?>><?=h($sl)?></option>
              <?php endforeach; ?></select></div>
        </div>
      </div>

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
              <select name="<?=$k?>[]" multiple size="3" class="pm-ms">
                <?php foreach ($vals as $v): ?>
                  <option value="<?=h($v)?>" <?=in_array($v,$f[$k],true)?'selected':''?>><?=h(ItServiceModel::etichetta($v))?></option>
                <?php endforeach; ?></select></div>
          <?php endforeach; ?>
          <div class="form-group"><label>Natura</label>
            <select name="ricavo" class="pm-ms"><option value="">— tutte —</option>
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
              <select name="<?=$k?>[]" multiple size="3" class="pm-ms">
                <?php foreach ($vals as $v): ?>
                  <option value="<?=h($v)?>" <?=in_array($v,$f[$k],true)?'selected':''?>><?=h(ItServiceModel::etichetta($v))?></option>
                <?php endforeach; ?></select></div>
          <?php endforeach; ?>
          <div class="form-group"><label>Raggruppa per <span class="pm-multi">(multipla)</span></label>
            <select name="gb[]" multiple size="3" class="pm-ms">
              <?php foreach (ItServiceModel::DIM as $k => $lbl): ?>
                <option value="<?=$k?>" <?=in_array($k,$f['gb'],true)?'selected':''?>><?=h($lbl)?></option>
              <?php endforeach; ?></select></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Dettagli da includere <span class="pm-multi">(stampa / export)</span></h4>
        <div class="pm-grid-auto">
          <?php foreach ($INC_ALL as $ik): ?>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px">
              <input type="checkbox" name="inc[]" value="<?=$ik?>" <?=$incOn($ik)?'checked':''?>>
              <?=h($INC_LBL[$ik])?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('it_service', ['contratti_set' => 1])?>">Azzera</a>
        <a class="btn btn-sm" href="<?=$qs(['export'=>'xlsx'])?>">
          <i class="fa-solid fa-file-excel"></i> Dati XLSX + pivot</a>
        <?php // v1.9.17 — l'etichetta dice quale report esce. Con un incaricato
              // solo selezionato il report è personale: un pulsante che dice
              // "generale" e produce una scheda personale fa dubitare dei dati. ?>
        <a class="btn btn-sm" href="<?=$qs(['print'=>'1'])?>" target="_blank">
          <i class="fa-solid fa-print"></i>
          Stampa <?= count($f['incaricati'] ?? []) === 1 ? 'report personale' : 'report generale' ?></a>
      </div>
    </form>
  </div>
</details>
<?php if ($pronto) { require_once(__DIR__ . '/app/it_service_report.php');
    echo PmReport::toolbar($qs, it_service_report_tecnici($itModel, $f), count($f['incaricati']) === 1, 'incaricati', 'incaricato', can('export', 'it_service.php'), ['Stampa' => ['print' => '1']]); } ?>

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
    // v1.9.46 — target "ore ordinarie lavorative": override ?target= oppure media mensile
    $avgOrd = 0.0; if ($trend) { $sO=0.0; foreach ($trend as $t) $sO += (float)($t['ore_ordinarie'] ?? 0); $avgOrd = $sO / count($trend); }
    $targetOrd = (isset($_GET['target']) && is_numeric($_GET['target'])) ? (float)$_GET['target'] : round($avgOrd);
    if ($targetOrd > 0) $mx = max($mx, $targetOrd);
    $W=900; $H=200; $pL=48; $pR=12; $pT=10; $pB=26;
    $pw=$W-$pL-$pR; $ph=$H-$pT-$pB; $nb=max(1,count($trend)); $bw=$pw/$nb;
  ?>
  <svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:100%;min-width:600px;height:auto;font-family:inherit">
    <?php for($g=0;$g<=4;$g++): $y=$pT+$ph-$g*$ph/4; ?>
      <line x1="<?=$pL?>" y1="<?=round($y,1)?>" x2="<?=$W-$pR?>" y2="<?=round($y,1)?>" stroke="#e2e8f0"/>
      <text x="<?=$pL-5?>" y="<?=round($y+3,1)?>" text-anchor="end" font-size="9" fill="#94a3b8">
        <?=$hh(round($mx*$g/4))?></text>
    <?php endfor; ?>
    <?php if($targetOrd>0): $yt=$pT+$ph-$targetOrd/$mx*$ph; ?>
      <line x1="<?=$pL?>" y1="<?=round($yt,1)?>" x2="<?=$W-$pR?>" y2="<?=round($yt,1)?>"
            stroke="#16a34a" stroke-width="1.5" stroke-dasharray="6 3"/>
      <text x="<?=$W-$pR?>" y="<?=round($yt-3,1)?>" text-anchor="end" font-size="9" fill="#16a34a">
        target ore ord. <?=$hh(round($targetOrd))?></text>
    <?php endif; ?>
    <?php foreach($trend as $i=>$t):
      $ho=(float)$t['ore']/$mx*$ph; $hv=(float)$t['ore_fuori']/$mx*$ph;
      $x=$pL+$i*$bw+$bw*0.15; $bx=max(2,$bw*0.7); ?>
      <rect x="<?=round($x,1)?>" y="<?=round($pT+$ph-$ho,1)?>" width="<?=round($bx,1)?>"
            height="<?=round($ho,1)?>" fill="#2563eb" rx="1">
        <title><?=h($t['ym'])?>: <?=$hh1($t['ore'])?> h · <?=$hh($t['giornate_uomo'])?> giornate-uomo</title></rect>
      <rect x="<?=round($x,1)?>" y="<?=round($pT+$ph-$hv,1)?>" width="<?=round($bx,1)?>"
            height="<?=round($hv,1)?>" fill="#f59e0b" rx="1">
        <title>fuori orario: <?=$hh1($t['ore_fuori'])?> h</title></rect>
      <?php $hrep=(float)($t['ore_reperibilita'] ?? 0)/$mx*$ph; if($hrep>0): ?>
        <rect x="<?=round($x+$bx*0.60,1)?>" y="<?=round($pT+$ph-$hrep,1)?>" width="<?=round($bx*0.40,1)?>"
              height="<?=round($hrep,1)?>" fill="#7c3aed" rx="1">
          <title>reperibilità: <?=$hh1($t['ore_reperibilita'])?> h</title></rect>
      <?php endif; ?>
      <?php if($i % max(1,intdiv($nb,10))===0): ?>
        <text x="<?=round($x+$bx/2,1)?>" y="<?=$H-8?>" text-anchor="middle" font-size="9" fill="#64748b">
          <?=h(substr((string)$t['ym'],2))?></text>
      <?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <div style="font-size:11px;color:var(--muted)">
    <span style="display:inline-block;width:12px;height:8px;background:#2563eb"></span> ore totali
    <span style="display:inline-block;width:12px;height:8px;background:#f59e0b;margin-left:12px"></span> fuori orario
    <span style="display:inline-block;width:12px;height:8px;background:#7c3aed;margin-left:12px"></span> reperibilità
    <span style="display:inline-block;width:12px;height:0;border-top:2px dashed #16a34a;margin-left:12px;vertical-align:middle"></span> target ore ordinarie
  </div>
</div>
<?php endif; ?>

<?php // ── v1.9.73: andamento giornaliero delle ore ───────────────────────────
if (!empty($trendG['rows'])):
    require_once __DIR__ . '/app/PmCharts.php';
    $pmGD = PmCharts::fillDays($trendG['rows'], $trendG['from'], $trendG['to'], 'giorno', ['ore_ordinarie', 'ore_fuori', 'ore_reperibilita', 'ore_non_classificate']);
    $pmFer = array_filter($pmGD, fn($d) => (int)date('N', strtotime($d['d'])) < 6);
    $pmMedia = $pmFer ? array_sum(array_column($pmFer, 'ore_ordinarie')) / count($pmFer) : 0;
?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-calendar-day"></i> Andamento giornaliero — ore</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">
      <?= count($pmGD) < 92 ? 'intero periodo' : 'ultimi 92 giorni del periodo' ?> · passa sulle barre per il dettaglio</span></div>
  <div style="overflow-x:auto">
  <?= PmCharts::dailyStacked($pmGD, [
        ['key' => 'ore_ordinarie',        'label' => 'Ore ordinarie',        'color' => '#2563eb'],
        ['key' => 'ore_fuori',            'label' => 'Fuori orario',         'color' => '#f59e0b'],
        ['key' => 'ore_reperibilita',     'label' => 'Reperibilità',         'color' => '#7c3aed'],
        ['key' => 'ore_non_classificate', 'label' => 'Fascia non rilevata',  'color' => '#94a3b8'],
      ], ['unit' => 'h', 'target' => round($pmMedia, 1), 'targetLabel' => 'media ore ordinarie (giorni feriali)']) ?>
  </div>
  <?php if (!empty($trendG['non_classificate'])): ?>
    <div class="alert alert-warning" style="font-size:11px;margin-top:8px">
      <b>Ore senza fascia oraria riconosciuta</b> (in grigio): non vengono conteggiate né come ordinarie né come fuori orario.
      Valori trovati:
      <?php foreach ($trendG['non_classificate'] as $pmNc): ?>
        fascia «<?= h($pmNc['fascia']) ?>» / modalità «<?= h($pmNc['modalita']) ?>»: <?= number_format((float)$pmNc['ore'], 1, ',', '.') ?> h (<?= (int)$pmNc['interventi'] ?> interventi);
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
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
        <th style="text-align:right">Giornate-uomo</th><th style="text-align:right">Ore totali</th>
        <th style="text-align:right;border-bottom:2px solid #16a34a">Ore ordinarie</th>
        <th style="text-align:right;border-bottom:2px solid #f59e0b">Ore fuori orario</th>
        <th style="text-align:right;border-bottom:2px solid #7c3aed">Ore reperibilità</th>
        <th style="text-align:right;border-bottom:2px solid #7c3aed" title="numero di interventi in reperibilità">N. interv. reperib.</th>
        <th style="text-align:right">Ore extra</th><th style="text-align:right">Ore viaggio</th>
        <th style="text-align:right">Km</th>
        <th style="text-align:right" title="numero di interventi">N. presso cliente</th>
        <th style="text-align:right" title="numero di interventi">N. remoto</th>
        <th style="text-align:right" title="numero di interventi">N. smart</th>
      </tr></thead>
      <tbody>
      <?php $tD = []; foreach ($righe as $r): foreach (['interventi','giornate_uomo','ore','ore_ordinarie','ore_fuori_orario','ore_reperibilita','reperibilita','ore_extra','ore_viaggio','km','presso_cliente','da_remoto','smart_working'] as $k) $tD[$k] = ($tD[$k] ?? 0) + (float)$r[$k]; ?>
        <tr>
          <?php foreach ($f['gb'] as $g): ?><td><?=h((string)$r[$g])?></td><?php endforeach; ?>
          <td style="text-align:right"><?=$hh($r['interventi'])?></td>
          <td style="text-align:right"><?=$hh($r['giornate_uomo'])?></td>
          <td style="text-align:right;font-weight:700"><?=$hh1($r['ore'])?></td>
          <td style="text-align:right;color:#16a34a"><?=$hh1($r['ore_ordinarie'])?></td>
          <td style="text-align:right;color:#b45309"><?=(float)$r['ore_fuori_orario'] > 0 ? $hh1($r['ore_fuori_orario']) : '—'?></td>
          <td style="text-align:right;color:#7c3aed;font-weight:600"><?=(float)$r['ore_reperibilita'] > 0 ? $hh1($r['ore_reperibilita']) : '—'?></td>
          <td style="text-align:right;color:#7c3aed"><?=(int)$r['reperibilita'] > 0 ? $hh($r['reperibilita']) : '—'?></td>
          <td style="text-align:right;color:var(--muted)"><?=$hh1($r['ore_extra'])?></td>
          <td style="text-align:right;color:#f59e0b"><?=$hh1($r['ore_viaggio'])?></td>
          <td style="text-align:right"><?=(float)$r['km'] > 0 ? $hh1($r['km']) : '—'?></td>
          <td style="text-align:right"><?=$hh($r['presso_cliente'])?></td>
          <td style="text-align:right"><?=$hh($r['da_remoto'])?></td>
          <td style="text-align:right"><?=$hh($r['smart_working'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($righe): // v1.9.89 — totali: coincidono con i KPI e con «Ore per modalità» ?>
      <tfoot><tr style="font-weight:700;background:#f8fafc">
        <td colspan="<?=count($f['gb'])?>">Totale<?= count($righe) >= 500 ? ' (prime 500 righe)' : '' ?></td>
        <td style="text-align:right"><?=$hh($tD['interventi'])?></td>
        <td style="text-align:right">—</td>
        <td style="text-align:right"><?=$hh1($tD['ore'])?></td>
        <td style="text-align:right;color:#16a34a"><?=$hh1($tD['ore_ordinarie'])?></td>
        <td style="text-align:right;color:#b45309"><?=$hh1($tD['ore_fuori_orario'])?></td>
        <td style="text-align:right;color:#7c3aed"><?=$hh1($tD['ore_reperibilita'])?></td>
        <td style="text-align:right;color:#7c3aed"><?=$hh($tD['reperibilita'])?></td>
        <td style="text-align:right"><?=$hh1($tD['ore_extra'])?></td>
        <td style="text-align:right"><?=$hh1($tD['ore_viaggio'])?></td>
        <td style="text-align:right"><?=$tD['km'] > 0 ? $hh1($tD['km']) : '—'?></td>
        <td style="text-align:right"><?=$hh($tD['presso_cliente'])?></td>
        <td style="text-align:right"><?=$hh($tD['da_remoto'])?></td>
        <td style="text-align:right"><?=$hh($tD['smart_working'])?></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
  <p style="font-size:11px;color:var(--muted);margin-top:8px">
    <strong>Giornate-uomo</strong>: coppia incaricato + giorno. Chi svolge cinque interventi in un
    giorno ha lavorato una giornata. <strong>Ore ordinarie + fuori orario + reperibilità = ore totali</strong>
    (+ eventuali non classificate; stessa regola di KPI, andamento e giorni per persona); le colonne «N.» contano interventi, non ore.
    <strong>Le ore extra sono comprese nelle ore</strong>, non aggiuntive. <strong>Km</strong> compare solo dove la distanza sede-cliente è stata rilevata:
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
        tutto l'eseguito nel periodo per data del modulo · tutte le linee, ore valorizzate e non
        <?= empty($f['stati']) ? '' : '· stato commessa: ' . h(implode(', ', array_map(fn($k) => ItServiceModel::STATI[$k], $f['stati']))) ?></span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:10px">
      <?php foreach ([
        ['Operatori', $hh($gQ['operatori'] ?? 0), '#0f766e', $hh($gQ['commesse'] ?? 0) . ' commesse'],
        ['Giorni-uomo', $hh($gQ['giorni_uomo'] ?? 0), '#2563eb',
         $hh($gQ['giorni_calendario'] ?? 0) . ' gg di calendario'],
        ['Ore', $hh1($gQ['ore'] ?? 0), '#334155',
         $hh1($gQ['giornate_equiv'] ?? 0) . ' giornate eq.'],
        ['Ore valorizzate', $hh1($gQ['ore_valorizzate'] ?? 0), '#7c3aed', 'con tariffa di listino'],
        ['Ore non valorizzate', $hh1($gQ['ore_non_valorizzate'] ?? 0), '#64748b',
         $hh($gQ['giorni_uomo_non_valorizzati'] ?? 0) . ' giorni-uomo · dettaglio sotto'],
        ['Ore fuori orario', $hh1($tot['ore_fuori_orario'] ?? 0), '#b45309',
         $hh1($tot['ore_ordinarie'] ?? 0) . ' h ordinarie'],
        ['Ore reperibilità', $hh1($tot['ore_reperibilita'] ?? 0), '#7c3aed',
         $hh($tot['reperibilita'] ?? 0) . ' interventi'],
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
        <strong>Solo il <?=$hh1($fLetta)?>% dei moduli ha la fascia tariffaria letta dall'attività</strong>: per
        gli altri è dedotta dall'orario di inizio. Riguarda la tariffa applicata (produzione teorica),
        non le ore per classe della tabella.
      </div>
    <?php endif; ?>
    <?php if ($senzaTar > 0): ?>
      <div style="background:#f8fafc;border-left:3px solid #64748b;padding:8px 11px;
                  border-radius:0 6px 6px 0;font-size:11px;margin-bottom:8px">
        <strong><?=$hh($senzaTar)?> interventi non valorizzati</strong> (senza tariffa di listino: linee a canone,
        presidio, attività interne, combinazioni fascia × durata non previste dal contratto): contano nei giorni
        lavorati e nelle ore, non nella produzione teorica.
      </div>
    <?php endif; ?>

    <table class="data-table" style="width:100%;font-size:11px">
      <thead>
        <tr style="font-size:9px;color:var(--muted)">
          <th></th>
          <th colspan="3" style="text-align:center;border-bottom:2px solid #2563eb">GIORNI LAVORATI (TOTALE)</th>
          <th colspan="4" style="text-align:center;border-bottom:2px solid #334155">ORE PER CLASSE (ORDINARIE + FUORI ORARIO + REPERIBILITÀ = TOTALE)</th>
          <th colspan="2" style="text-align:center;border-bottom:2px solid #64748b">ORE PER VALORE</th>
          <th colspan="2" style="text-align:center;border-bottom:2px solid #7c3aed">PRODUZIONE</th>
          <th></th>
        </tr>
        <tr><th>Operatore</th>
          <th style="text-align:right" title="giorni distinti con almeno un intervento">Giorni</th>
          <th style="text-align:right" title="di cui giorni con almeno un intervento in reperibilità">di cui in reperib.</th>
          <th style="text-align:right">Giornate eq.</th>
          <th style="text-align:right">Ore totali</th>
          <th style="text-align:right;color:#16a34a">Ordinarie</th>
          <th style="text-align:right;color:#b45309">Fuori orario</th>
          <th style="text-align:right;color:#7c3aed">Reperibilità</th>
          <th style="text-align:right;color:#7c3aed" title="con tariffa di listino">Valorizzate</th>
          <th style="text-align:right;color:#64748b" title="senza tariffa di listino: vedi dettaglio sotto">Non valorizzate</th>
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
          <?php $cl = $gCls[$x['operatore']] ?? []; ?>
          <td style="text-align:right;font-weight:700;font-size:13px"><?=$hh($x['giorni_lavorati'])?></td>
          <td style="text-align:right;color:#7c3aed"><?=(int)($cl['giorni_reperibilita'] ?? 0) > 0 ? $hh($cl['giorni_reperibilita']) : '—'?></td>
          <td style="text-align:right;color:var(--muted)"><?=$hh1($x['giornate_equiv'])?></td>
          <td style="text-align:right;font-weight:700"><?=$hh1($x['ore'])?></td>
          <td style="text-align:right;color:#16a34a"><?=$hh1($cl['ore_ordinarie'] ?? 0)?></td>
          <td style="text-align:right;color:#b45309"><?=(float)($cl['ore_fuori_orario'] ?? 0) > 0 ? $hh1($cl['ore_fuori_orario']) : '—'?></td>
          <td style="text-align:right;color:#7c3aed;font-weight:600"><?=(float)($cl['ore_reperibilita'] ?? 0) > 0 ? $hh1($cl['ore_reperibilita']) : '—'?></td>
          <td style="text-align:right;color:#7c3aed"><?=$hh1($x['ore_valorizzate'])?></td>
          <td style="text-align:right;color:#64748b"><?=((float)$x['ore_non_valorizzate']) > 0 ? $hh1($x['ore_non_valorizzate']) : '—'?></td>
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
      <?php // v1.9.89 — totali della tabella: coincidono con i KPI della pagina ?>
      <tfoot><tr style="font-weight:700;background:#f8fafc">
        <td>Totale (<?=$hh(count($gOp))?> persone)</td>
        <td style="text-align:right"><?=$hh($gQ['giorni_uomo'] ?? 0)?> <span style="font-weight:400;font-size:9px">gg-uomo</span></td>
        <td style="text-align:right;color:#7c3aed"><?=$hh(array_sum(array_map(fn($c) => (int)$c['giorni_reperibilita'], $gCls)))?></td>
        <td style="text-align:right"><?=$hh1($gQ['giornate_equiv'] ?? 0)?></td>
        <td style="text-align:right"><?=$hh1($gQ['ore'] ?? 0)?></td>
        <td style="text-align:right;color:#16a34a"><?=$hh1($tot['ore_ordinarie'] ?? 0)?></td>
        <td style="text-align:right;color:#b45309"><?=$hh1($tot['ore_fuori_orario'] ?? 0)?></td>
        <td style="text-align:right;color:#7c3aed"><?=$hh1($tot['ore_reperibilita'] ?? 0)?></td>
        <td style="text-align:right;color:#7c3aed"><?=$hh1($gQ['ore_valorizzate'] ?? 0)?></td>
        <td style="text-align:right;color:#64748b"><?=$hh1($gQ['ore_non_valorizzate'] ?? 0)?></td>
        <td style="text-align:right"><?=$hh1($gQ['produzione_teorica'] ?? 0)?></td>
        <td></td><td></td>
      </tr></tfoot>
    </table>

    <?php // v1.9.88 — ripartizione per codice linea, area tecnologica e dimensioni correlate ?>
    <div style="margin-top:12px">
      <?php foreach (ItServiceModel::GIORNI_DIM as $gd => $gl): $righeD = $gDim[$gd] ?? []; if (!$righeD) continue;
            $totD = array_sum(array_map(fn($x) => (float)$x['ore'], $righeD)); ?>
        <details class="pm-gdim" <?= in_array($gd, ['codice_linea', 'area_tecnologica'], true) ? 'open' : '' ?>
                 style="margin-bottom:8px;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;background:#fff">
          <summary style="cursor:pointer;font-size:12px;font-weight:700">
            Giorni per <?=h(mb_strtolower($gl))?>
            <span style="font-weight:400;color:var(--muted);font-size:11px">· <?=$hh(count($righeD))?> voci</span></summary>
          <table class="data-table" style="width:100%;font-size:11px;margin:6px 0 0">
            <thead><tr><th><?=h($gl)?></th>
              <th style="text-align:right">Persone</th><th style="text-align:right">Giorni-uomo</th>
              <th style="text-align:right">Interventi</th><th style="text-align:right">Ore</th>
              <th style="text-align:right">Valorizzate</th><th style="text-align:right">Non valorizzate</th>
              <th style="text-align:right">Quota ore</th><th style="text-align:right">Produzione teorica</th></tr></thead>
            <tbody>
            <?php foreach ($righeD as $x): ?>
              <tr><td style="font-weight:600"><?=h(ItServiceModel::etichetta((string)$x['voce']))?></td>
                <td style="text-align:right"><?=$hh($x['persone'])?></td>
                <td style="text-align:right;font-weight:700"><?=$hh($x['giorni_uomo'])?></td>
                <td style="text-align:right"><?=$hh($x['interventi'])?></td>
                <td style="text-align:right;font-weight:600"><?=$hh1($x['ore'])?></td>
                <td style="text-align:right;color:#7c3aed"><?=$hh1($x['ore_valorizzate'])?></td>
                <td style="text-align:right;color:#64748b"><?=((float)$x['ore_non_valorizzate']) > 0 ? $hh1($x['ore_non_valorizzate']) : '—'?></td>
                <td style="text-align:right;color:var(--muted)"><?=$totD > 0 ? $hh1(100 * (float)$x['ore'] / $totD) . '%' : '—'?></td>
                <td style="text-align:right"><?=$x['produzione_teorica'] !== null ? $hh1($x['produzione_teorica']) : '—'?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endforeach; ?>
    </div>

    <?php // v1.9.89 — dettaglio delle ore non valorizzate (senza tariffa di listino) ?>
    <?php if ($gNv):
          $nvMot = []; $nvLin = [];
          foreach ($gNv as $x) {
              $nvMot[$x['motivo']] = ($nvMot[$x['motivo']] ?? 0) + (float)$x['ore'];
              $nvLin[$x['codice_linea']]['ore'] = ($nvLin[$x['codice_linea']]['ore'] ?? 0) + (float)$x['ore'];
              $nvLin[$x['codice_linea']]['label'] = $x['contratto'];
          }
          arsort($nvMot); uasort($nvLin, fn($p, $q) => $q['ore'] <=> $p['ore']); ?>
      <details id="non-valorizzate" open style="margin-top:12px;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;background:#f8fafc">
        <summary style="cursor:pointer;font-size:12px;font-weight:700">
          <i class="fa-solid fa-circle-minus" style="color:#64748b"></i>
          Dettaglio ore non valorizzate — <?=$hh1($gQ['ore_non_valorizzate'] ?? 0)?> h
          <span style="font-weight:400;color:var(--muted);font-size:11px">· <?=$hh(count($gNv))?> righe persona × commessa
            · senza tariffa di listino: contano nei giorni e nelle ore, non nella produzione teorica</span></summary>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0">
          <?php foreach ($nvMot as $m => $o): ?>
            <span style="background:#fff;border:1px solid #cbd5e1;border-radius:12px;padding:2px 9px;font-size:11px">
              <strong><?=h($m)?></strong> <?=$hh1($o)?> h</span>
          <?php endforeach; ?>
          <?php foreach (array_slice($nvLin, 0, 8, true) as $cl => $o): ?>
            <span style="background:#eef2ff;border-radius:12px;padding:2px 9px;font-size:11px" title="<?=h($o['label'])?>">
              <?=h($cl)?> <?=$hh1($o['ore'])?> h</span>
          <?php endforeach; ?>
        </div>
        <div style="max-height:420px;overflow:auto">
        <table class="data-table" style="width:100%;font-size:11px;margin:0">
          <thead><tr><th>Codice linea</th><th>Commessa</th><th>Cliente</th><th>Persona</th>
            <th style="text-align:right">Interventi</th><th style="text-align:right">Giorni</th>
            <th style="text-align:right">Ore</th><th>Motivo</th><th>Fascia · unità senza tariffa</th></tr></thead>
          <tbody>
          <?php foreach ($gNv as $x): ?>
            <tr><td title="<?=h((string)$x['contratto'])?>"><?=h((string)$x['codice_linea'])?></td>
              <td><code><?=h((string)$x['commessa'])?></code></td>
              <td><?=h(mb_strimwidth((string)$x['cliente'], 0, 32, '…'))?></td>
              <td><?=h((string)$x['operatore'])?></td>
              <td style="text-align:right"><?=$hh($x['interventi'])?></td>
              <td style="text-align:right"><?=$hh($x['giorni'])?></td>
              <td style="text-align:right;font-weight:700"><?=$hh1($x['ore'])?></td>
              <td style="color:<?=$x['motivo'] === 'Commessa senza listino' ? '#64748b' : '#b45309'?>"><?=h((string)$x['motivo'])?></td>
              <td style="color:var(--muted)"><?=$x['motivo'] === 'Commessa senza listino' ? '—' : h((string)$x['combinazioni'])?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </details>
    <?php endif; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:8px;padding-top:8px;
              border-top:1px solid #f1f5f9">
      <strong>«Giorni lavorati» sono giorni distinti</strong>: due interventi nello stesso giorno
      contano una volta sola. Le <strong>giornate equivalenti</strong> sono le ore diviso 8 —
      chi lavora due ore al giorno per venti giorni ha 20 giorni lavorati e 5 giornate.
      <strong>Ore per classe</strong>: ordinarie (dentro l'orario di lavoro), fuori orario e reperibilità
      (intervento in reperibilità, qualunque orario) — stessa regola di KPI, andamento e dettaglio; la somma
      è il totale. «di cui in reperib.» conta i giorni con almeno un intervento in reperibilità.
      <strong>Valorizzate</strong> = con tariffa di listino. La <strong>produzione teorica</strong> è ore × listino:
      ciò che il lavoro varrebbe, non ciò che è stato fatturato.
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
              <td style="text-align:center"><?=ItServiceModel::reperibilitaHtml($x['reperibilita'])?></td>
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



<?php // [PM_V1_9_35_APPLIED] Sezione 2: Riepilogo per Codice Contratto ?>
<style>
  .r35-h2 { margin:22px 0 8px; font-size:16px; }
  .r35-badge { background:#dcfce7; color:#166534; padding:2px 8px; border-radius:999px; font-size:11px; }
  .r35-tbl { width:100%; border-collapse:collapse; margin:4px 0 20px; }
  .r35-tbl th,.r35-tbl td { padding:6px 8px; border-bottom:1px solid #e4e7ee; font-size:12.5px; text-align:right; }
  .r35-tbl th:first-child,.r35-tbl td:first-child,.r35-tbl th:nth-child(2),.r35-tbl td:nth-child(2){text-align:left;}
  .r35-tbl thead th { background:#f0f2f7; }
  .r35-tbl tfoot td { font-weight:600; background:#f0f2f7; }
  .r35-empty { color:#92400e; background:#fffbeb; border:1px solid #fde68a; padding:10px 12px; border-radius:6px; font-size:13px; }
</style>
<?php
  // v1.9.90 — le due sezioni DGB leggono lo stesso payload del pannello principale ($f): lo dichiarano
  $filtriBox = '<div style="font-size:11px;color:var(--muted);margin:0 0 8px">'
             . '<i class="fa-solid fa-filter"></i> Periodo ' . h(date('d/m/Y', strtotime($f['from']))) . ' – ' . h(date('d/m/Y', strtotime($f['to'])))
             . ($filtriTxt ? ' · ' . h(implode(' · ', $filtriTxt)) : ' · nessun altro filtro')
             . ' <span style="color:#94a3b8">(filtri del pannello in alto)</span></div>';
?>
<h2 class="r35-h2">Riepilogo per Codice Contratto</h2>
<?= $filtriBox ?>
<?php if (empty($riepContratto)): ?>
  <div class="r35-empty">Nessun dato per il periodo
    <b><?= h($f['from'] ?? '—') ?></b> – <b><?= h($f['to'] ?? '—') ?></b>.
    Allarga il filtro periodo o verifica la sincronizzazione DGB.</div>
<?php else: ?>
  <table class="r35-tbl" data-pm-nofilter>
    <thead><tr>
      <th>Codice contratto</th><th>PM Project</th>
      <th>Ore totali</th><th>Ore ord.</th><th>Ore str.</th><th>Ore rep.</th>
      <th>Giorni-uomo</th><th>Costo contratto (€)</th><th>TotCostoTab (€)</th>
    </tr></thead>
    <tbody>
    <?php $sO=$sS=$sR=$sG=$sC=$sT=0; foreach ($riepContratto as $r): ?>
      <tr>
        <td><?= h($r['codice_contratto']) ?></td>
        <td><?= h((string)($r['pm_project_code'] ?? '')) ?></td>
        <td style="font-weight:600"><?= number_format((float)$r['ore'],2,',','.') ?></td>
        <td><?= number_format((float)$r['ore_ordinarie'],2,',','.') ?></td>
        <td><?= number_format((float)$r['ore_straordinario'],2,',','.') ?></td>
        <td><?= number_format((float)$r['ore_reperibilita'],2,',','.') ?></td>
        <td><?= number_format((float)$r['giorni_uomo'],0,',','.') ?></td>
        <td><?= number_format((float)$r['costo_contratto'],2,',','.') ?></td>
        <td><?= number_format((float)$r['tot_costo_tab'],2,',','.') ?></td>
      </tr>
    <?php $sTot=($sTot??0)+(float)$r['ore']; $sO+=(float)$r['ore_ordinarie'];$sS+=(float)$r['ore_straordinario'];$sR+=(float)$r['ore_reperibilita'];$sG+=(int)$r['giorni_uomo'];$sC+=(float)$r['costo_contratto'];$sT+=(float)$r['tot_costo_tab']; endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="2">Totali</td>
      <td><?= number_format($sTot ?? 0,2,',','.') ?></td>
      <td><?= number_format($sO,2,',','.') ?></td><td><?= number_format($sS,2,',','.') ?></td>
      <td><?= number_format($sR,2,',','.') ?></td><td><?= number_format($sG,0,',','.') ?></td>
      <td><?= number_format($sC,2,',','.') ?></td><td><?= number_format($sT,2,',','.') ?></td>
    </tr></tfoot>
  </table>
  <?php // v1.9.90 — quadratura con i KPI e attività DGB fuori perimetro
    $dOre = round((float)($tot['ore'] ?? 0) - (float)($sTot ?? 0), 2); ?>
  <p style="font-size:11px;color:var(--muted);margin:-12px 0 16px">
    Righe = moduli di intervento del pannello (stessi filtri di KPI, grafici, costi e giorni) collegati al contratto DGB:
    ore totali <?= number_format((float)($sTot ?? 0),2,',','.') ?> su <?= number_format((float)($tot['ore'] ?? 0),2,',','.') ?> dei KPI<?= $dOre > 0 ? ' (differenza: moduli con attività DGB annullata o assente)' : '' ?>.
    <?php if ((int)$nSm['attivita'] > 0): ?>
      Fuori da queste sezioni: <?= number_format((float)$nSm['attivita'],0,',','.') ?> attività DGB senza modulo di intervento
      (<?= number_format((float)$nSm['ore'],1,',','.') ?> h) — <a href="#dgb-senza-modulo">dettaglio nella sezione dedicata</a>.
    <?php endif; ?></p>
<?php endif; ?>

<?php // [PM_V1_9_34_APPLIED] Sezione Dettaglio per Commessa — v1.9.73: righe caricate su richiesta ?>
<?php if (!empty($dettSintesi)):
    $pmNr = array_sum(array_column($dettSintesi, 'righe'));
    $pmNf = fn($v) => number_format((float)$v, 2, ',', '.');
?>
<style>
  .rsi34-h2 { margin:22px 0 8px; font-size:16px; }
  .rsi34-badge { background:#e0e7ff; color:#3730a3; padding:2px 8px; border-radius:999px; font-size:11px; }
  .rsi34-h3 { margin:6px 0 0; font-size:13px; background:#1e293b; color:#fff; padding:8px 12px; border-radius:5px;
              font-family:ui-monospace,SFMono-Regular,Menlo,monospace; cursor:pointer; list-style:none; }
  .rsi34-h3::-webkit-details-marker { display:none; }
  .rsi34-h3::before { content:'▸'; display:inline-block; width:14px; transition:transform .15s; }
  details[open] > .rsi34-h3::before { transform:rotate(90deg); }
  .rsi34-tbl { width:100%; border-collapse:collapse; margin:4px 0 14px; }
  .rsi34-tbl th, .rsi34-tbl td { padding:5px 8px; border-bottom:1px solid #e4e7ee; font-size:12.5px; text-align:right; }
  .rsi34-tbl th:nth-child(-n+3), .rsi34-tbl td:nth-child(-n+3) { text-align:left; }
  .rsi34-tbl thead th { background:#f0f2f7; }
  .rsi34-tbl tfoot td { font-weight:600; background:#eef4ff; }
</style>
<h2 class="rsi34-h2">Dettaglio per Commessa
  <span class="rsi34-badge"><?= count($dettSintesi) ?> contratti · <?= number_format($pmNr, 0, ',', '.') ?> righe</span></h2>
<?= $filtriBox ?? '' ?>
<p style="color:var(--muted);font-size:12px;margin:0 0 8px">
  Apri un contratto per vederne le righe: vengono caricate solo quando servono.
  Stampa ed export Word includono il dettaglio completo.</p>
<?php foreach ($dettSintesi as $c):
    $intest = implode(' | ', array_filter([$c['contract_code'], $c['code_x_installation'], $c['customer_name'], $c['contract_description']],
                                           fn($v) => $v !== null && $v !== '')); ?>
  <details class="rsi-dett" data-cid="<?= (int)$c['contract_id'] ?>">
    <summary class="rsi34-h3"><?= h($intest) ?><?php if ($c['pm_project_code']): ?> · PM: <?= h($c['pm_project_code']) ?><?php endif; ?>
      <span style="float:right;font-weight:normal"><?= (int)$c['righe'] ?> righe · <?= $pmNf($c['ore']) ?>h · € <?= $pmNf($c['tot_costo_tab']) ?></span></summary>
    <table class="rsi34-tbl" data-pm-nofilter>
      <thead><tr><th>Data</th><th>Operatore</th><th>Ticket</th><th>Fascia</th><th>Regime</th><th>Ore</th><th>Costo contratto (€)</th><th>TotCostoTab (€)</th></tr></thead>
      <tbody><tr><td colspan="8" style="text-align:left;color:#94a3b8">Caricamento…</td></tr></tbody>
      <tfoot><tr><td colspan="5">Totali commessa</td>
        <td><?= $pmNf($c['ore']) ?></td><td><?= $pmNf($c['costo_contratto']) ?></td><td><?= $pmNf($c['tot_costo_tab']) ?></td></tr></tfoot>
    </table>
  </details>
<?php endforeach; ?>
<script>
(function () {
  document.querySelectorAll('details.rsi-dett').forEach(function (d) {
    d.addEventListener('toggle', function () {
      if (!d.open || d.dataset.stato === 'ok' || d.dataset.stato === 'caricamento') return;
      var body = d.querySelector('tbody');
      d.dataset.stato = 'caricamento';
      var u = new URL(window.location.href);
      u.searchParams.set('ajax', 'dett_commessa');
      u.searchParams.set('cid', d.dataset.cid);
      fetch(u.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function (html) {
          body.innerHTML = html || '<tr><td colspan="8" style="text-align:left;color:#94a3b8">Nessuna riga.</td></tr>';
          d.dataset.stato = 'ok';
        })
        .catch(function (e) {
          body.innerHTML = '<tr><td colspan="8" style="text-align:left;color:#dc2626">Caricamento non riuscito (' + e.message + '): chiudi e riapri per riprovare.</td></tr>';
          d.dataset.stato = '';
        });
    });
  });
})();
</script>
<?php endif; ?>

<?php // ── v1.9.91 — Attività DGB senza modulo di intervento ───────────────────── ?>
<?php if ((int)$nSm['attivita'] > 0): ?>
<div class="card" id="dgb-senza-modulo" style="margin:18px 0 14px;border-left:4px solid #64748b">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-link-slash"></i> Attività DGB senza modulo di intervento</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">
      <?=$hh($nSm['attivita'])?> attività · <?=$hh1($nSm['ore'])?> h · fuori dai totali della relazione</span>
  </div>
  <div style="font-size:11px;color:var(--muted);margin:0 0 8px">
    <i class="fa-solid fa-filter"></i> Periodo <?=h(date('d/m/Y', strtotime($f['from'])))?> – <?=h(date('d/m/Y', strtotime($f['to'])))?> (data dell'attività)
    · filtri applicati: contratto, stato commessa, incaricato, cliente, linea, codice linea, ricerca
    <?php if ($smNonAppl): ?><br><span style="color:#b45309">Non applicabili (dati presenti solo sui moduli): <?=h(implode(', ', $smNonAppl))?>.</span><?php endif; ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:10px">
    <?php foreach ($nSm['motivi'] as $m): ?>
      <div style="padding:10px;background:#f8fafc;border-radius:8px">
        <div style="font-size:16px;font-weight:800;color:#334155"><?=$hh($m['attivita'])?> <span style="font-size:11px;font-weight:600;color:var(--muted)">attività · <?=$hh1($m['ore'])?> h</span></div>
        <div style="font-size:11px;font-weight:700"><?=h($m['motivo'])?></div>
        <div style="font-size:10px;color:var(--muted)"><?=$hh($m['contratti'])?> contratti · <?=$hh($m['operatori'])?> operatori</div>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="max-height:460px;overflow:auto">
  <table class="data-table" data-pm-nofilter style="width:100%;font-size:11px">
    <thead><tr><th>Contratto</th><th>PM Project</th><th>Codice linea</th><th>Cliente</th><th>Operatore</th><th>Motivo</th>
      <th style="text-align:right">Attività</th><th style="text-align:right" title="ore allocate o, in mancanza, pianificate">Ore</th><th>Dal</th><th>Al</th></tr></thead>
    <tbody>
    <?php foreach ($smDett as $x): ?>
      <tr><td><code><?=h((string)$x['contratto'])?></code></td>
        <td><?=h((string)$x['pm_project'])?></td>
        <td><?=h((string)$x['codice_linea'])?></td>
        <td><?=h(mb_strimwidth((string)$x['cliente'], 0, 32, '…'))?></td>
        <td><?=h((string)$x['operatore'])?></td>
        <td style="color:<?=str_starts_with((string)$x['motivo'], 'Eseguita') ? '#dc2626' : '#475569'?>"><?=h((string)$x['motivo'])?></td>
        <td style="text-align:right"><?=$hh($x['attivita'])?></td>
        <td style="text-align:right;font-weight:700"><?=$hh1($x['ore'])?></td>
        <td><?=h(date('d/m/Y', strtotime((string)$x['dal'])))?></td>
        <td><?=h(date('d/m/Y', strtotime((string)$x['al'])))?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="font-size:11px;color:var(--muted);margin-top:8px">
    Attività presenti nel DGB per il periodo ma senza modulo di intervento collegato, quindi escluse da KPI, grafici, costi,
    giorni e riepiloghi. <strong>Assegnata / in corso</strong>: pianificata, non ancora rendicontata (ore pianificate).
    <strong>Congelata</strong>: sospesa nel DGB. <strong>Eseguita ma senza modulo</strong>: chiusa nel DGB ma non sincronizzata —
    va recuperata con la sincronizzazione. L'export XLSX contiene una riga per attività (foglio «DGB senza modulo»).
  </p>
</div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
