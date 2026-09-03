<?php
/**
 * import_economics_xlsx.php — Import massivo dati economici per anno (v1.8.0)
 *
 * Modello di importazione standard dei dati economici, per anno di competenza.
 *   • Download del template XLSX (identificativi + Anno + campi economici).
 *   • Upload XLSX/CSV: match dipendente per Codice dipendente → Codice fiscale →
 *     Email aziendale; UPSERT in hr_employee_economics per (dipendente, anno).
 *   • Idempotente (ri-eseguibile), riepilogo per riga (aggiornati/creati/saltati).
 *   • Per l'anno corrente rispecchia i valori nelle colonne di employees.
 *
 * Riservato ad Amministratore, HR e Responsabile Finanziario.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/XlsxReader.php');
require_once(__DIR__ . '/app/XlsxWriter.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/CostModel.php');

if (!can('view', 'import_economics_xlsx.php')) { redirect('finance_overview'); }
$u_id     = (int)$_SESSION['user_id'];
$can_imp  = can('create', 'import_economics_xlsx.php') || can('edit', 'import_economics_xlsx.php');

$cm       = new CostModel($pdo);
$cur_year = $cm->currentYear();
$year     = $cm->resolveYear($_GET['year'] ?? $_POST['year'] ?? 0);
$years    = $cm->years();

/* ── Definizione tracciato ───────────────────────────────────────────────── */
// campo interno => [etichetta template, tipo]  (tipo: num|cf|bool)
$FIELDS = [
    'ral'                         => ['RAL', 'num'],
    'premio_concordato'           => ['Premio concordato', 'num'],
    'classificazione_finanziaria' => ['Classificazione finanziaria', 'cf'],
    'moltiplicatore_fc'           => ['Moltiplicatore FC', 'num'],
    'qt_trasferte_annue'          => ['Qt. Trasferte Annue', 'num'],
    'qt_buoni_pasto'              => ['Qt. Buoni Pasto', 'num'],
    'valore_tabp'                 => ['ValoreTABP', 'num'],
    'km_concordati'               => ['Km concordati', 'num'],
    'val_km'                      => ['Val.KM', 'num'],
    'km_effettivi'                => ['Km effettivi', 'num'],
    'incentivazione_extra'        => ['Incentivazione Extra', 'num'],
    'valore_medio_anno_auto'      => ['Valore Medio anno Auto', 'num'],
    'overhead_aziendale'          => ['OverHead Aziendale', 'num'],
    'moltiplicatore_fte'          => ['Moltiplicatore FTE', 'num'],
    'fuori_sede'                  => ['Indennità fuori sede', 'bool'],
    'fuori_sede_amount'           => ['Importo fuori sede', 'num'],
];

function norm_h(string $h): string {
    $h = function_exists('mb_strtolower') ? mb_strtolower(trim($h), 'UTF-8') : strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/u', ' ', $h);
    return trim(preg_replace('/\s+/', ' ', $h));
}
// sinonimi header => campo interno / identificativo
$HEADER_MAP = [
    // identificativi
    'codice dipendente' => '_code', 'matricola' => '_code', 'employee code' => '_code', 'cod dipendente' => '_code', 'codice' => '_code',
    'codice fiscale' => '_cf', 'cf' => '_cf', 'fiscal code' => '_cf',
    'email aziendale' => '_email', 'email' => '_email', 'business email' => '_email', 'mail aziendale' => '_email',
    'cognome' => '_last', 'cognome dipendente' => '_last', 'last name' => '_last', 'surname' => '_last',
    'nome' => '_first', 'nome dipendente' => '_first', 'first name' => '_first',
    'anno' => '_year', 'anno di competenza' => '_year', 'esercizio' => '_year', 'year' => '_year',
    // economici
    'ral' => 'ral', 'ral annua' => 'ral',
    'premio concordato' => 'premio_concordato', 'premio' => 'premio_concordato',
    'classificazione finanziaria' => 'classificazione_finanziaria', 'classificazione' => 'classificazione_finanziaria', 'class fin' => 'classificazione_finanziaria',
    'moltiplicatore fc' => 'moltiplicatore_fc', 'molt fc' => 'moltiplicatore_fc',
    'qt trasferte annue' => 'qt_trasferte_annue', 'trasferte' => 'qt_trasferte_annue', 'qt trasferte' => 'qt_trasferte_annue',
    'qt buoni pasto' => 'qt_buoni_pasto', 'buoni pasto' => 'qt_buoni_pasto', 'buoni' => 'qt_buoni_pasto',
    'valoretabp' => 'valore_tabp', 'valore tabp' => 'valore_tabp',
    'km concordati' => 'km_concordati', 'km concordati annui' => 'km_concordati',
    'val km' => 'val_km', 'valore km' => 'val_km',
    'km effettivi' => 'km_effettivi', 'km effettivi annui' => 'km_effettivi',
    'incentivazione extra' => 'incentivazione_extra', 'incentivo' => 'incentivazione_extra', 'incentivazione' => 'incentivazione_extra',
    'valore medio anno auto' => 'valore_medio_anno_auto', 'valore auto' => 'valore_medio_anno_auto', 'auto' => 'valore_medio_anno_auto',
    'overhead aziendale' => 'overhead_aziendale', 'overhead' => 'overhead_aziendale',
    'moltiplicatore fte' => 'moltiplicatore_fte', 'molt fte' => 'moltiplicatore_fte',
    'indennita fuori sede' => 'fuori_sede', 'fuori sede' => 'fuori_sede',
    'importo fuori sede' => 'fuori_sede_amount', 'importo fuori sede annui' => 'fuori_sede_amount',
];

function pm_num($v): ?float {
    if ($v === null) return null;
    // Valore già numerico (cella XLSX numerica): usalo così com'è, senza manipolazioni.
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim((string)$v);
    if ($s === '') return null;
    // rimuovi tutto tranne cifre, separatori e segno (valuta, spazi, ecc.)
    $s = preg_replace('/[^0-9,.\-]/u', '', $s);
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') return null;
    $lastComma = strrpos($s, ',');
    $lastDot   = strrpos($s, '.');
    if ($lastComma !== false && $lastDot !== false) {
        // entrambi presenti: il separatore decimale è l'ultimo che compare
        if ($lastComma > $lastDot) {           // formato IT: 1.234,56
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {                                // formato EN: 1,234.56
            $s = str_replace(',', '', $s);
        }
    } elseif ($lastComma !== false) {           // solo virgola => decimale (IT): 1234,56
        $s = str_replace(',', '.', $s);
    }
    // solo punto o nessun separatore: già decimale valido (es. cella XLSX 1234.56) => invariato
    return is_numeric($s) ? (float)$s : null;
}
function pm_cf($v): ?string {
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$v), 'UTF-8') : strtolower(trim((string)$v));
    if (str_starts_with($s, 'dir')) return 'Diretto';
    if (str_starts_with($s, 'ind')) return 'Indiretto';
    return null;
}
function pm_bool($v): int {
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$v), 'UTF-8') : strtolower(trim((string)$v));
    return in_array($s, ['1', 'si', 'sì', 'true', 'x', 'y', 'yes', 'vero'], true) ? 1 : 0;
}

/* ── Download template ───────────────────────────────────────────────────── */
if (($_GET['download'] ?? '') === 'template') {
    $head = ['Codice dipendente', 'Codice fiscale', 'Cognome', 'Nome', 'Email aziendale', 'Anno'];
    foreach ($FIELDS as $k => [$lbl, $t]) $head[] = $lbl;
    // riga d'esempio (verrà ignorata all'import se priva di identificativo valido)
    $ex = ['', '', '', '', '', $year];
    foreach ($FIELDS as $k => $d) $ex[] = '';
    $rows = [$head, $ex];
    $instr = [
        ['Modello import dati economici — PortalManager'],
        [''],
        ['Compilare un dipendente per riga. Identificazione (una qualsiasi): Codice dipendente, Codice fiscale, Email aziendale, oppure Cognome + Nome (se non ambiguo).'],
        ['La colonna Anno indica l\'esercizio di competenza; se lasciata vuota vale l\'anno selezionato nella pagina di import.'],
        ['Classificazione finanziaria: Diretto oppure Indiretto. Indennità fuori sede: Sì/No.'],
        ['Separatore decimale: virgola o punto. Le celle vuote non modificano il valore esistente.'],
        ['L\'import è idempotente: rieseguirlo aggiorna i valori senza duplicare le righe.'],
    ];
    $w = new XlsxWriter();
    $w->addSheet('Dati economici', $rows);
    $w->addSheet('Istruzioni', $instr);
    $w->download('template_dati_economici_' . $year . '.xlsx');
    exit;
}

/* ── Import in due fasi: validazione (anteprima) → conferma ──────────────── */
$summary = null;
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['validate','commit'], true)) {
    Csrf::verify();
    if (!$can_imp) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('import_economics_xlsx', ['year' => $year]); }
}

/* FASE 1 — validazione / anteprima (nessuna scrittura sul database) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate') {
    if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($err) . "</div>";
        redirect('import_economics_xlsx', ['year' => $year]);
    }
    $tmp  = $_FILES['file']['tmp_name'];
    $name = (string)($_FILES['file']['name'] ?? '');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    // lookup dipendenti (con nominativi e CF per l'anteprima)
    $emps = $pdo->query("SELECT id, employee_code, UPPER(fiscal_code) fc, LOWER(business_email) em, first_name, last_name, fiscal_code fcv FROM employees")->fetchAll(PDO::FETCH_ASSOC);
    $byCode = $byCf = $byEmail = $empName = $empCf = $byName = $dupName = [];
    foreach ($emps as $e) {
        $eid = (int)$e['id'];
        if ($e['employee_code'] !== null && $e['employee_code'] !== '') $byCode[(string)$e['employee_code']] = $eid;
        if ($e['fc']) $byCf[$e['fc']] = $eid;
        if ($e['em']) $byEmail[$e['em']] = $eid;
        $empName[$eid] = trim(($e['last_name'] ?? '') . ' ' . ($e['first_name'] ?? ''));
        $empCf[$eid]   = (string)($e['fcv'] ?? '');
        $nk = norm_h(($e['last_name'] ?? '') . ' ' . ($e['first_name'] ?? ''));
        if ($nk !== '') { if (isset($byName[$nk])) $dupName[$nk] = true; else $byName[$nk] = $eid; }
    }

    // lettura righe (assoc header=>valore)
    $dataRows = [];
    try {
        if ($ext === 'csv') {
            $fh = fopen($tmp, 'r');
            $first = fgets($fh); rewind($fh);
            $delim = (substr_count($first, ';') >= substr_count($first, ',')) ? ';' : ',';
            $hdr = null;
            while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
                if ($hdr === null) { $hdr = $cells; continue; }
                $row = [];
                foreach ($hdr as $i => $hname) $row[(string)$hname] = $cells[$i] ?? '';
                $dataRows[] = $row;
            }
            fclose($fh);
        } else {
            $hints = ['codice dipendente', 'codice fiscale', 'email aziendale', 'anno', 'ral'];
            $headersOut = null;
            XlsxReader::each($tmp, function ($row) use (&$dataRows) { $dataRows[] = $row; }, 0, $headersOut, ['header_hints' => $hints]);
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore lettura file: " . h($e->getMessage()) . "</div>";
        redirect('import_economics_xlsx', ['year' => $year]);
    }

    // mappa header presenti => campo interno
    $present = []; $ids = [];
    if ($dataRows) {
        foreach (array_keys($dataRows[0]) as $hname) {
            $key = $HEADER_MAP[norm_h((string)$hname)] ?? null;
            if ($key === null) continue;
            if (in_array($key, ['_code', '_cf', '_email', '_year', '_last', '_first'], true)) $ids[$key] = $hname;
            else $present[$key] = $hname;
        }
    }
    if (!$present) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Nessuna colonna economica riconosciuta nel file. Usa il template.</div>";
        redirect('import_economics_xlsx', ['year' => $year]);
    }

    // anni bloccati / censiti
    $lockedYears = array_map('intval', $pdo->query("SELECT year FROM hr_economic_years WHERE is_locked=1")->fetchAll(PDO::FETCH_COLUMN));
    $knownYears  = array_map('intval', array_keys($years));

    $cols = array_keys($present);
    $pv = [
        'created_at'   => time(),
        'default_year' => $year,
        'file'         => $name,
        'cols'         => $cols,
        'labels'       => array_map(fn($c) => $FIELDS[$c][0], $cols),
        'rows'         => [],
        'stats'        => ['ok'=>0,'err'=>0,'created'=>0,'updated'=>0,'warn'=>0],
    ];
    $ln = 1;
    foreach ($dataRows as $row) {
        $ln++;
        $code  = isset($ids['_code'])  ? trim((string)$row[$ids['_code']])  : '';
        $cf    = isset($ids['_cf'])    ? strtoupper(trim((string)$row[$ids['_cf']])) : '';
        $email = isset($ids['_email']) ? strtolower(trim((string)$row[$ids['_email']])) : '';
        $fLast = isset($ids['_last'])  ? trim((string)$row[$ids['_last']])  : '';
        $fFirst= isset($ids['_first']) ? trim((string)$row[$ids['_first']]) : '';
        if ($code === '' && $cf === '' && $email === '' && $fLast === '' && $fFirst === '') continue; // riga vuota
        $rowYear = $year;
        if (isset($ids['_year'])) {
            $yv = (int)preg_replace('/\D/', '', (string)$row[$ids['_year']]);
            if ($yv > 0) $rowYear = $yv;
        }
        $eid = ($code !== '' && isset($byCode[$code])) ? $byCode[$code]
             : (($cf !== '' && isset($byCf[$cf])) ? $byCf[$cf]
             : (($email !== '' && isset($byEmail[$email])) ? $byEmail[$email] : 0));
        $nameAmb = false;
        if ($eid === 0 && $fLast !== '' && $fFirst !== '') {
            $nk = norm_h($fLast . ' ' . $fFirst);
            if (isset($dupName[$nk])) $nameAmb = true;
            elseif (isset($byName[$nk])) $eid = $byName[$nk];
        }
        $fileName = trim($fLast . ' ' . $fFirst);
        $idLabel = $code ?: ($cf ?: ($email ?: $fileName));

        $entry = ['ln'=>$ln, 'eid'=>$eid, 'emp'=>($eid ? ($empName[$eid] ?: '—') : ($fileName ?: '—')),
                  'cf'=>($eid ? $empCf[$eid] : $cf), 'id'=>$idLabel, 'year'=>$rowYear,
                  'vals'=>[], 'action'=>'', 'ok'=>false, 'nota'=>''];

        if ($eid === 0) { $entry['nota'] = $nameAmb ? 'omonimia: specificare Codice dipendente o Codice fiscale' : 'dipendente non trovato'; $pv['stats']['err']++; $pv['rows'][]=$entry; continue; }
        $ry = $cm->resolveYear($rowYear); $entry['year'] = $ry;
        if (in_array($ry, $lockedYears, true)) { $entry['nota']="esercizio $ry bloccato"; $pv['stats']['err']++; $pv['rows'][]=$entry; continue; }
        if ($rowYear > 0 && !in_array($rowYear, $knownYears, true) && $ry !== $rowYear) {
            $entry['nota'] = "anno $rowYear non censito, usato $ry";
        }
        // valori
        $vals = []; $warn = false;
        foreach ($present as $field => $hname) {
            $raw  = (string)($row[$hname] ?? '');
            $type = $FIELDS[$field][1];
            if     ($type === 'num') { $v = pm_num($raw); if ($raw !== '' && $v === null) $warn = true; }
            elseif ($type === 'cf')  { $v = pm_cf($raw); }
            else                     { $v = pm_bool($raw); }
            $vals[$field] = $v;
        }
        $entry['vals'] = $vals;
        $exq = $pdo->prepare("SELECT 1 FROM hr_employee_economics WHERE employee_id=? AND year=?");
        $exq->execute([$eid, $ry]);
        $entry['action'] = $exq->fetchColumn() ? 'aggiornato' : 'creato';
        $entry['ok'] = true;
        $pv['stats']['ok']++;
        $pv['stats'][$entry['action']==='aggiornato' ? 'updated' : 'created']++;
        if ($warn) { $pv['stats']['warn']++; $entry['nota'] = trim(($entry['nota'] ? $entry['nota'].' · ' : '') . 'alcuni valori non numerici ignorati'); }
        $pv['rows'][] = $entry;
    }

    $_SESSION['econ_preview'] = $pv;
    redirect('import_economics_xlsx', ['year' => $year, 'preview' => 1]);
}

/* FASE 2 — conferma / commit (solo righe valide dell'anteprima) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit') {
    $pv = $_SESSION['econ_preview'] ?? null;
    if (!$pv || empty($pv['cols'])) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Nessuna anteprima da confermare. Ricarica il file.</div>";
        redirect('import_economics_xlsx', ['year' => $year]);
    }
    $cols    = $pv['cols'];
    $collist = implode(',', array_map(fn($c) => "`$c`", $cols));
    $phods   = implode(',', array_fill(0, count($cols), '?'));
    $setUp   = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
    $up = $pdo->prepare("INSERT INTO hr_employee_economics (employee_id, year, $collist, updated_by)
                         VALUES (?, ?, $phods, ?)
                         ON DUPLICATE KEY UPDATE $setUp, updated_by=VALUES(updated_by)");
    $mir = $pdo->prepare("UPDATE employees SET " . implode(',', array_map(fn($c) => "`$c`=?", $cols)) . " WHERE id=?");

    $res = ['created'=>0,'updated'=>0,'skipped'=>0,'mirror'=>0,'rows'=>[]];
    $pdo->beginTransaction();
    foreach ($pv['rows'] as $r) {
        if (empty($r['ok'])) {
            $res['skipped']++;
            $res['rows'][] = ['ln'=>$r['ln'],'id'=>$r['id'],'esito'=>'saltato','nota'=>$r['nota']];
            continue;
        }
        $ordered = array_map(fn($c) => $r['vals'][$c] ?? null, $cols);
        $up->execute(array_merge([$r['eid'], $r['year']], $ordered, [$u_id]));
        if ($r['action'] === 'aggiornato') $res['updated']++; else $res['created']++;
        if ((int)$r['year'] === (int)$cur_year) { $mir->execute(array_merge($ordered, [$r['eid']])); $res['mirror']++; }
        $res['rows'][] = ['ln'=>$r['ln'],'id'=>$r['id'],'esito'=>$r['action'],'nota'=>"esercizio {$r['year']}" . ($r['nota'] ? " · {$r['nota']}" : '')];
    }
    $pdo->commit();
    unset($_SESSION['econ_preview']);
    write_log('Finance', 'success', "Import dati economici confermato: {$res['created']} creati, {$res['updated']} aggiornati, {$res['skipped']} saltati", $u_id);
    $_SESSION['econ_summary'] = $res;
    redirect('import_economics_xlsx', ['year' => $year, 'done' => 1]);
}

// recupero anteprima/esito per il rendering (GET dopo PRG)
if (($_GET['cancel'] ?? '') === '1') { unset($_SESSION['econ_preview']); redirect('import_economics_xlsx', ['year' => $year]); }
if (($_GET['preview'] ?? '') === '1') $preview = $_SESSION['econ_preview'] ?? null;
if (($_GET['done'] ?? '') === '1')    { $summary = $_SESSION['econ_summary'] ?? null; unset($_SESSION['econ_summary']); }

require_once('header.php');
if (!empty($_SESSION['flash_msg'])) { echo $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div>
    <h1><i class="fa-solid fa-file-import" style="color:#0891b2"></i> Import dati economici</h1>
    <p style="color:var(--muted);font-size:13px">Compilazione massiva dei dati economici per anno di competenza, da modello XLSX/CSV. Match del dipendente per Codice, Codice fiscale o Email aziendale.</p>
  </div>
  <a class="btn btn-sm" href="<?=url_safe('finance_overview',['year'=>$year])?>"><i class="fa-solid fa-arrow-left"></i> Torna a Finance</a>
</div>

<?php if ($summary): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clipboard-check"></i> Esito import</span></div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px">
    <div style="padding:10px;border-radius:8px;background:#dcfce7;border:1px solid #86efac"><div style="font-size:10px;color:#166534;font-weight:700">Creati</div><div style="font-size:18px;font-weight:800;color:#166534"><?=$summary['created']?></div></div>
    <div style="padding:10px;border-radius:8px;background:#dbeafe;border:1px solid #93c5fd"><div style="font-size:10px;color:#1e40af;font-weight:700">Aggiornati</div><div style="font-size:18px;font-weight:800;color:#1e40af"><?=$summary['updated']?></div></div>
    <div style="padding:10px;border-radius:8px;background:#fee2e2;border:1px solid #fca5a5"><div style="font-size:10px;color:#991b1b;font-weight:700">Saltati</div><div style="font-size:18px;font-weight:800;color:#991b1b"><?=$summary['skipped']?></div></div>
    <div style="padding:10px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0"><div style="font-size:10px;color:var(--muted);font-weight:700">Rispecchiati su anagrafica</div><div style="font-size:18px;font-weight:800"><?=$summary['mirror']?></div></div>
  </div>
  <?php if ($summary['rows']): ?>
  <details>
    <summary style="cursor:pointer;font-size:12px;color:var(--muted)">Dettaglio righe (<?=count($summary['rows'])?>)</summary>
    <table class="data-table" style="width:100%;font-size:11px;margin-top:8px">
      <thead><tr><th style="width:60px">Riga</th><th>Identificativo</th><th style="width:110px">Esito</th><th>Nota</th></tr></thead>
      <tbody>
      <?php foreach ($summary['rows'] as $r):
        $c = $r['esito']==='saltato' ? '#991b1b' : ($r['esito']==='creato' ? '#166534' : '#1e40af'); ?>
        <tr><td><?=$r['ln']?></td><td><?=h($r['id'])?></td><td style="color:<?=$c?>;font-weight:700"><?=h($r['esito'])?></td><td style="color:var(--muted)"><?=h($r['nota'])?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($preview): $st = $preview['stats']; ?>
<div class="card" style="margin-bottom:14px;border:1px solid #93c5fd">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-list-check" style="color:#1e40af"></i> Anteprima e controllo dati — <?= h($preview['file'] ?? '') ?></span></div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px">
    <div style="padding:10px;border-radius:8px;background:#dcfce7;border:1px solid #86efac"><div style="font-size:10px;color:#166534;font-weight:700">Righe valide</div><div style="font-size:18px;font-weight:800;color:#166534"><?=$st['ok']?></div></div>
    <div style="padding:10px;border-radius:8px;background:#fee2e2;border:1px solid #fca5a5"><div style="font-size:10px;color:#991b1b;font-weight:700">Con errori (escluse)</div><div style="font-size:18px;font-weight:800;color:#991b1b"><?=$st['err']?></div></div>
    <div style="padding:10px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0"><div style="font-size:10px;color:var(--muted);font-weight:700">Di cui creazioni / aggiornamenti</div><div style="font-size:18px;font-weight:800"><?=$st['created']?> / <?=$st['updated']?></div></div>
    <div style="padding:10px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d"><div style="font-size:10px;color:#92400e;font-weight:700">Avvisi</div><div style="font-size:18px;font-weight:800;color:#92400e"><?=$st['warn']?></div></div>
  </div>
  <div style="max-height:340px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px">
    <table class="data-table" style="width:100%;font-size:11px">
      <thead style="position:sticky;top:0;background:#f8fafc"><tr>
        <th style="width:50px">Riga</th><th>Dipendente</th><th style="width:150px">Codice fiscale</th>
        <th style="width:70px">Anno</th><th style="width:110px">Esito</th><th>Nota</th>
      </tr></thead>
      <tbody>
      <?php foreach ($preview['rows'] as $r):
        $ok = !empty($r['ok']);
        $c  = !$ok ? '#991b1b' : ($r['action']==='aggiornato' ? '#1e40af' : '#166534');
        $es = !$ok ? 'ERRORE' : $r['action']; ?>
        <tr style="<?= $ok ? '' : 'background:#fef2f2' ?>">
          <td><?=$r['ln']?></td>
          <td><?=h($r['emp'])?><?php if ($r['id'] && $r['id']!==$r['emp']): ?> <span style="color:var(--muted)">(<?=h($r['id'])?>)</span><?php endif; ?></td>
          <td style="font-family:monospace"><?=h($r['cf'])?></td>
          <td><?=(int)$r['year']?></td>
          <td style="color:<?=$c?>;font-weight:700"><?=h($es)?></td>
          <td style="color:var(--muted)"><?=h($r['nota'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="display:flex;gap:10px;align-items:center;margin-top:12px">
    <?php if ($can_imp && $st['ok'] > 0): ?>
    <form method="post" onsubmit="return confirm('Confermare l\'importazione di <?=$st['ok']?> righe valide?')" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="commit"><input type="hidden" name="year" value="<?=$year?>">
      <button class="btn btn-success"><i class="fa-solid fa-check"></i> Conferma importazione (<?=$st['ok']?> righe)</button>
    </form>
    <?php elseif ($st['ok'] === 0): ?>
      <span style="color:#991b1b;font-weight:700;font-size:12px"><i class="fa-solid fa-triangle-exclamation"></i> Nessuna riga valida da importare: correggere il file e ricaricarlo.</span>
    <?php endif; ?>
    <a class="btn btn-sm" href="<?=url_safe('import_economics_xlsx',['year'=>$year,'cancel'=>1])?>">Annulla</a>
  </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-download"></i> 1. Scarica il modello</span></div>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <?= route_slug_field() ?>
    <input type="hidden" name="download" value="template">
    <div class="form-group" style="margin:0"><label>Anno di competenza (precompilato nel modello)</label>
      <select name="year" style="font-weight:700">
        <?php foreach ($years as $y => $lbl): ?><option value="<?=(int)$y?>" <?=$y===$year?'selected':''?>><?=(int)$y?><?=$y===$cur_year?' (corrente)':''?></option><?php endforeach; ?>
      </select></div>
    <button class="btn btn-primary"><i class="fa-solid fa-file-excel"></i> Scarica template XLSX</button>
  </form>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">Il modello contiene le colonne identificative (Codice dipendente / Codice fiscale / Email aziendale), la colonna Anno e i campi economici. Il foglio «Istruzioni» descrive il tracciato.</p>
</div>

<?php if ($can_imp): ?>
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-upload"></i> 2. Carica il file compilato</span></div>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="validate">
    <div class="form-group" style="margin:0"><label>Esercizio predefinito <small style="color:var(--muted)">(usato se la colonna Anno è vuota)</small></label>
      <select name="year" style="font-weight:700">
        <?php foreach ($years as $y => $lbl): ?><option value="<?=(int)$y?>" <?=$y===$year?'selected':''?>><?=(int)$y?><?=$y===$cur_year?' (corrente)':''?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group" style="margin:0;flex:1;min-width:240px"><label>File XLSX o CSV</label>
      <input type="file" name="file" accept=".xlsx,.csv" required></div>
    <button class="btn btn-primary"><i class="fa-solid fa-list-check"></i> Verifica e anteprima</button>
  </form>
  <p style="color:var(--muted);font-size:11px;margin-top:8px"><?= h(UploadGuard::limitsNote()) ?> Il file viene prima <strong>verificato</strong>: i dati vengono importati solo dopo la conferma dell'anteprima. L'import è idempotente (rieseguirlo aggiorna i valori esistenti per lo stesso dipendente e anno).</p>
</div>
<?php endif; ?>
<?php require_once('footer.php'); ?>
