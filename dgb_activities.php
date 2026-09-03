<?php
/**
 * dgb_activities.php — Attività & Rendicontazione DGB (v1.8.8)
 * Gestione Commesse: analisi gerarchica pianificazione -> attività -> incaricati,
 * KPI (SLA innesco, consuntivo vs pianificato), distribuzione carico sede/remoto,
 * data quality e import batch dei modelli DogoBit con report differenziale.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/DgbModel.php');
require_once(__DIR__ . '/app/DgbImporter.php');
require_once(__DIR__ . '/app/DgbSync.php');
require_once(__DIR__ . '/app/UploadGuard.php');

if (!can('view', 'dgb_activities.php')) { redirect('manage_projects'); }
$can_edit = can('edit', 'dgb_activities.php');
$u_id  = (int)$_SESSION['user_id'];
$model = new DgbModel($pdo);
$imp   = new DgbImporter($pdo);
$f     = DgbModel::normFilters($_GET);

// parametri distribuzione temporale
$gran  = ($_GET['gran'] ?? 'month') === 'day' ? 'day' : 'month';
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : '';

/**
 * SVG della distribuzione temporale.
 *
 * v1.8.52 — Il grafico mensile era un blocco chiuso: mostrava dodici barre e non
 * permetteva di scendere dentro un mese. Per vedere i giorni occorreva sapere che
 * esisteva il pulsante "Giorni" e scegliere il mese da un menu a parte.
 *
 * Ora ogni barra mensile e' un collegamento che apre il dettaglio giornaliero di
 * quel mese: il gesto naturale — cliccare il mese che interessa — fa la cosa
 * attesa. In vista giornaliera le barre restano statiche perche' non c'e' un
 * livello sotto.
 *
 * @param array       $dist   risultato di DgbModel::temporalDistribution()
 * @param callable|null $drill funzione che, dato il mese 'YYYY-MM', restituisce
 *                             l'URL del dettaglio giornaliero. Null = nessun link
 *                             (usato negli export, dove i collegamenti non servono).
 */
function dgb_dist_svg(array $dist, ?callable $drill = null): string
{
    $b = $dist['buckets'];
    if (!$b) return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 60"><text x="300" y="34" text-anchor="middle" font-size="13" fill="#94a3b8">Nessun dato</text></svg>';
    $n = count($b);
    $maxV = 1;
    foreach ($b as $r) { $maxV = max($maxV, (float)$r['workload'], (float)$r['baseline']); }
    $padL = 46; $padR = 14; $padT = 14; $padB = 42; $plotH = 210;
    $bw = max(560, $n * ($dist['granularity'] === 'day' ? 20 : 44));
    $W = $padL + $padR + $bw; $H = $padT + $plotH + $padB;
    $barW = $bw / $n; $col = fn($i) => $padL + $i * $barW;
    $y = fn($v) => round($padT + $plotH - ($v / $maxV) * $plotH, 1);
    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '" font-family="Segoe UI,Arial,sans-serif">';
    $svg .= '<rect width="' . $W . '" height="' . $H . '" fill="#ffffff"/>';
    // griglia + assi Y
    for ($g = 0; $g <= 4; $g++) {
        $val = $maxV * $g / 4; $yy = $y($val);
        $svg .= '<line x1="' . $padL . '" y1="' . $yy . '" x2="' . ($W - $padR) . '" y2="' . $yy . '" stroke="#eef2f7"/>';
        $svg .= '<text x="' . ($padL - 5) . '" y="' . ($yy + 3) . '" text-anchor="end" font-size="9" fill="#94a3b8">' . round($val) . '</text>';
    }
    // barre. In vista mensile ciascuna e' avvolta da un link al dettaglio del mese.
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    $num = fn($v) => number_format((float)$v, 2, ',', '.');
    $isMonth = ($dist['granularity'] === 'month');

    foreach ($b as $i => $r) {
        $x = $col($i) + $barW * 0.15; $w = $barW * 0.7;
        $ord = (float)$r['ordinary']; $ext = (float)$r['overtime'];
        $base = (float)$r['baseline'];

        // fondo grigio per il fine settimana
        if (!empty($r['weekend'])) $svg .= '<rect x="' . round($col($i),1) . '" y="' . $padT . '" width="' . round($barW,1) . '" height="' . $plotH . '" fill="#f8fafc"/>';

        // descrizione leggibile al passaggio del mouse: senza, i valori esatti
        // si possono solo stimare a occhio sull'asse
        $tip = $esc($r['label']) . ' — ordinario ' . $num($ord) . ' h';
        if ($ext > 0)  $tip .= ', reperibilita ' . $num($ext) . ' h';
        $tip .= ', totale ' . $num($ord + $ext) . ' h';
        if ($base > 0) {
            $tip .= ' su ' . $num($base) . ' h di riferimento';
            if (isset($r['actives']) && (int)$r['actives'] > 0) $tip .= ' (' . (int)$r['actives'] . ' incaricati)';
            if (!empty($r['estimated'])) $tip .= ' — riferimento stimato';
            $tip .= ' — utilizzo ' . number_format(($ord + $ext) / $base * 100, 0, ',', '.') . '%';
        }
        if ($isMonth) $tip .= ' — clic per il dettaglio giornaliero';

        $open = ''; $close = '';
        if ($isMonth && $drill !== null) {
            $open  = '<a href="' . $esc($drill((string)$r['key'])) . '" style="cursor:pointer">';
            $close = '</a>';
        }
        $svg .= $open;
        // area cliccabile a tutta altezza: la sola barra sarebbe un bersaglio
        // troppo piccolo nei mesi con poche ore
        $svg .= '<rect x="' . round($col($i),1) . '" y="' . $padT . '" width="' . round($barW,1) . '" height="' . $plotH . '" fill="transparent">'
              . '<title>' . $tip . '</title></rect>';

        $yOrd = $y($ord); $hOrd = $padT + $plotH - $yOrd;
        $svg .= '<rect x="' . round($x,1) . '" y="' . $yOrd . '" width="' . round($w,1) . '" height="' . round(max(0,$hOrd),1) . '" fill="#2563eb"><title>' . $tip . '</title></rect>';
        if ($ext > 0) {
            $yTot = $y($ord + $ext); $hExt = $yOrd - $yTot;
            $svg .= '<rect x="' . round($x,1) . '" y="' . $yTot . '" width="' . round($w,1) . '" height="' . round(max(0,$hExt),1) . '" fill="#f59e0b"><title>' . $tip . '</title></rect>';
        }
        $svg .= $close;

        if ($isMonth || $n <= 31) {
            $lblFill = !empty($r['weekend']) ? '#cbd5e1' : '#64748b';
            $svg .= '<text x="' . round($col($i)+$barW/2,1) . '" y="' . ($padT+$plotH+12) . '" text-anchor="middle" font-size="8" fill="' . $lblFill . '">' . $esc($r['label']) . '</text>';
        }
    }
    // Linea di riferimento della capacita'.
    //
    // v1.8.52: la linea viene SPEZZATA dove il riferimento non esiste — i giorni
    // di chiusura. Prima scendeva a zero e risaliva, disegnando dei picchi verso
    // il basso che sembravano un crollo della capacita' invece dell'assenza di
    // un valore. Ogni tratto continuo e' un segmento separato.
    $seg = [];
    foreach ($b as $i => $r) {
        $base = (float)$r['baseline'];
        if ($base > 0) {
            $seg[] = round($col($i) + $barW/2, 1) . ',' . $y($base);
        } elseif ($seg) {
            if (count($seg) > 1) $svg .= '<polyline points="' . implode(' ', $seg) . '" fill="none" stroke="#dc2626" stroke-width="1.6" stroke-dasharray="5 3"/>';
            elseif (count($seg) === 1) { [$px, $py] = explode(',', $seg[0]); $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="1.8" fill="#dc2626"/>'; }
            $seg = [];
        }
    }
    if (count($seg) > 1) $svg .= '<polyline points="' . implode(' ', $seg) . '" fill="none" stroke="#dc2626" stroke-width="1.6" stroke-dasharray="5 3"/>';
    elseif (count($seg) === 1) { [$px, $py] = explode(',', $seg[0]); $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="1.8" fill="#dc2626"/>'; }
    // legenda
    $ly = $H - 14;
    $svg .= '<rect x="' . $padL . '" y="' . $ly . '" width="11" height="10" fill="#2563eb"/><text x="' . ($padL+15) . '" y="' . ($ly+9) . '" font-size="10" fill="#475569">ordinario</text>';
    $svg .= '<rect x="' . ($padL+90) . '" y="' . $ly . '" width="11" height="10" fill="#f59e0b"/><text x="' . ($padL+105) . '" y="' . ($ly+9) . '" font-size="10" fill="#475569">reperibilita</text>';
    $svg .= '<line x1="' . ($padL+205) . '" y1="' . ($ly+5) . '" x2="' . ($padL+225) . '" y2="' . ($ly+5) . '" stroke="#dc2626" stroke-width="1.6" stroke-dasharray="5 3"/><text x="' . ($padL+230) . '" y="' . ($ly+9) . '" font-size="10" fill="#475569">capacita ordinaria (8 h/gg)</text>';
    $svg .= '</svg>';
    return $svg;
}

// ── Export della DISTRIBUZIONE temporale (SVG / XLSX / CSV) ───────────────────
$exp = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exp, ['distsvg', 'distxlsx', 'distcsv'], true)) {
    $dist = $model->temporalDistribution($f, $gran, $month);
    $stamp = date('Ymd_Hi');
    if ($exp === 'distsvg') {
        $svg = dgb_dist_svg($dist);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"dgb_distribuzione_{$gran}_$stamp.svg\"");
        echo $svg; exit;
    }
    $head = ['Periodo', 'Ordinario (h)', 'Reperibilità (h)', 'Carico totale (h)', 'Riferimento (h)', 'Delta vs riferimento (h)', 'Incaricati attivi', 'Nota'];
    $data = [$head];
    // v1.8.52: l'export riporta anche gli incaricati attivi, che sono il
    // denominatore della baseline giornaliera: senza, il riferimento non e'
    // ricostruibile fuori dal portale.
    foreach ($dist['buckets'] as $r) $data[] = [
        (string)$r['label'], $r['ordinary'], $r['overtime'], $r['workload'], $r['baseline'],
        round((float)$r['workload'] - (float)$r['baseline'], 2),
        isset($r['actives']) ? (int)$r['actives'] : '',
        !empty($r['estimated']) ? 'stimato' : '',
    ];
    $t = $dist['totals'];
    $data[] = ['TOTALE', $t['ordinary'], $t['overtime'], $t['workload'], $t['baseline'], round($t['workload'] - $t['baseline'], 2), '', ''];
    if ($exp === 'distxlsx') {
        require_once(__DIR__ . '/XlsxWriter.php');
        $w = new XlsxWriter(); $w->addSheet('Distribuzione DGB', $data); $w->download("dgb_distribuzione_{$gran}_$stamp.xlsx"); exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"dgb_distribuzione_{$gran}_$stamp.csv\"");
    $fh = fopen('php://output', 'w'); fwrite($fh, "\xEF\xBB\xBF");
    foreach ($data as $line) fputcsv($fh, $line, ';', '"');
    fclose($fh); exit;
}

// ── Export XLSX/CSV della tabella (prima di header) ──────────────────────────
$fmt = strtolower(trim((string)($_GET['export'] ?? '')));
if ($fmt === 'xlsx' || $fmt === 'csv') {
    $rows = $model->table($f, 5000, 0);
    $head = ['ID','Codice','Ticket','Stato','Avvio effettivo','Avvio previsto','SLA (h)','Ore pianificate','Ore consuntivate','Delta ore','Costo','Ricavo','Orfano'];
    $data = [$head];
    foreach ($rows as $r) $data[] = [
        (int)$r['activity_id'], (string)$r['code'], (string)$r['ticket'], (string)$r['status'],
        (string)$r['date_start'], (string)($r['planned_start'] ?? ''), $r['sla_hours'] ?? '',
        $r['planned_hours'] ?? '', $r['actual_hours'] ?? '', $r['delta_hours'] ?? '',
        $r['total_cost'] ?? '', $r['total_revenue'] ?? '', $r['is_orphan'] ? 'SI' : '',
    ];
    $stamp = date('Ymd_Hi');
    write_log('DGB', 'info', 'Export attività (' . $fmt . '): ' . count($rows) . ' righe', $u_id);
    if ($fmt === 'xlsx') {
        require_once(__DIR__ . '/XlsxWriter.php');
        $w = new XlsxWriter(); $w->addSheet('Attività DGB', $data); $w->download("dgb_attivita_$stamp.xlsx"); exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"dgb_attivita_$stamp.csv\"");
    $fh = fopen('php://output', 'w'); fwrite($fh, "\xEF\xBB\xBF");
    foreach ($data as $line) fputcsv($fh, $line, ';', '"');
    fclose($fh); exit;
}

// ── Import batch dei modelli (Task 1 + Task 5 diff) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('dgb_activities', ['tab' => 'import']); }
    $batch = DgbImporter::uuid();
    $dir = __DIR__ . '/uploads/dgb_tmp/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $done = 0; $errs = [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
        $c = count($_FILES['files']['name']);
        for ($i = 0; $i < $c; $i++) {
            if ((int)$_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = (string)$_FILES['files']['name'][$i];
            $tmp  = (string)$_FILES['files']['tmp_name'][$i];
            $modelName = DgbImporter::detectModel($orig);
            if (!$modelName) { $errs[] = "Modello non riconosciuto: " . h($orig); continue; }
            $dest = $dir . 'imp_' . bin2hex(random_bytes(4)) . '.csv';
            if (!@move_uploaded_file($tmp, $dest)) { $errs[] = "Upload fallito: " . h($orig); continue; }
            try { $imp->importFile($dest, $modelName, $batch, $u_id); $done++; }
            catch (Throwable $e) { $errs[] = h($orig) . ': ' . h($e->getMessage()); }
            @unlink($dest);
        }
    }
    write_log('DGB', 'success', "Import batch $batch: $done file", $u_id);
    $_SESSION['flash_msg'] = "<div class='alert alert-" . ($done ? 'success' : 'danger') . "'>Import completato: $done file"
        . ($errs ? ' — errori: ' . implode('; ', $errs) : '') . ".</div>";
    redirect('dgb_activities', ['tab' => 'import', 'batch' => $batch]);
}

// ── Sync DGB -> Commesse & Moduli intervento (v1.8.13) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('dgb_activities', ['tab' => 'import']); }
    $sync = new DgbSync($pdo);
    $created = $sync->ensureProjects($u_id);
    $res = $sync->syncReports($u_id);
    write_log('DGB', 'success', "Sync commesse: {$created} commesse create, {$res['written']} moduli intervento", $u_id);
    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Sincronizzazione completata: <strong>$created</strong> commesse create, <strong>{$res['written']}</strong> moduli di intervento allineati"
        . ($res['no_project'] ? " ({$res['no_project']} senza commessa)" : "") . ". Le sotto-voci di Gestione Commesse ora includono i dati DGB.</div>";
    redirect('dgb_activities', ['tab' => 'import']);
}

// ── Auto-classifica profili orario/reperibilità ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'classify') {
    Csrf::verify();
    if (!$can_edit) { redirect('dgb_activities', ['tab' => 'incaricati']); }
    $c = (new DgbSync($pdo))->autoClassify();
    write_log('DGB', 'success', "Auto-classifica: {$c['turni']} turni, {$c['on_call']} reperibilità", $u_id);
    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Classificati <strong>{$c['classified']}</strong> incaricati: {$c['turni']} a turni, {$c['ordinario']} ordinario, {$c['on_call']} in reperibilità.</div>";
    redirect('dgb_activities', ['tab' => 'incaricati']);
}

// ── Salvataggio manuale profili ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profiles') {
    Csrf::verify();
    if (!$can_edit) { redirect('dgb_activities', ['tab' => 'incaricati']); }
    $sched = $_POST['schedule'] ?? []; $onc = $_POST['oncall'] ?? [];
    $up = $pdo->prepare("INSERT INTO dgb_operator_profile (dgb_operator_id, schedule_type, on_call, auto_classified, updated_at)
                         VALUES (?,?,?,0,NOW()) ON DUPLICATE KEY UPDATE schedule_type=VALUES(schedule_type), on_call=VALUES(on_call), auto_classified=0, updated_at=NOW()");
    $n = 0;
    foreach ((array)$sched as $opId => $sv) {
        $opId = (int)$opId;
        $sv = in_array($sv, ['ordinario', 'turni'], true) ? $sv : 'ordinario';
        $ov = isset($onc[$opId]) ? 1 : 0;
        $up->execute([$opId, $sv, $ov]); $n++;
    }
    write_log('DGB', 'info', "Profili aggiornati manualmente: $n", $u_id);
    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Profili aggiornati: $n incaricati.</div>";
    redirect('dgb_activities', ['tab' => 'incaricati']);
}

$tab = in_array($_GET['tab'] ?? '', ['analisi', 'import', 'incaricati', 'anomalie'], true) ? $_GET['tab'] : 'analisi';

// v1.8.54: anomalie di imputazione oraria. Le viste calcolano in lettura, quindi
// il risultato e' sempre coerente con i dati del momento.
//
// v1.8.56: filtri per tecnico, tipo e periodo. La costruzione della clausola e'
// una sola, condivisa fra la tabella a video e l'export: se fossero due, un
// filtro aggiunto all'una e non all'altra produrrebbe un file che non
// corrisponde a cio' che si sta guardando — ed e' esattamente il difetto che
// l'export deve non avere.
$anomRiep = []; $anomRighe = []; $anomTecnici = []; $anomTot = 0;
// v1.8.59: anomalie di IMPUTAZIONE della commessa, distinte da quelle orarie.
// Un modulo di intervento su un contratto che remunera la sola disponibilita'
// (WTS-REP) va spostato sul contratto operativo collegato.
$impRighe = []; $impRiep = [];
$anomF = [
    'tipo'    => trim($_GET['atipo'] ?? ''),
    'tecnico' => trim($_GET['atec'] ?? ''),
    'sev'     => trim($_GET['asev'] ?? ''),
    'dal'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['adal'] ?? '') ? $_GET['adal'] : '',
    'al'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['aal'] ?? '')  ? $_GET['aal']  : '',
];
$anomAttivi = count(array_filter($anomF, fn($v) => $v !== ''));

/** Clausola WHERE delle anomalie: unica sorgente per video ed export. */
$anomWhere = function (array $f): array {
    $w = ['1=1']; $a = [];
    if ($f['tipo']    !== '') { $w[] = 'tipo = ?';          $a[] = $f['tipo']; }
    if ($f['sev']     !== '') { $w[] = 'severita = ?';      $a[] = $f['sev']; }
    if ($f['tecnico'] !== '') { $w[] = 'tecnico LIKE ?';    $a[] = '%' . $f['tecnico'] . '%'; }
    if ($f['dal']     !== '') { $w[] = 'giorno >= ?';       $a[] = $f['dal']; }
    if ($f['al']      !== '') { $w[] = 'giorno <= ?';       $a[] = $f['al']; }
    return [implode(' AND ', $w), $a];
};
$anomOrder = " ORDER BY FIELD(severita,'alta','media'), giorno DESC, ore DESC";
$anomCols  = ['Severita', 'Tipo', 'Tecnico', 'Giorno', 'Ore', 'Righe', 'Commesse coinvolte', 'Rilievo', 'Dettaglio'];
/** Riga nel formato dell'export: stesse colonne e stessi valori del video. */
$anomRow = fn(array $r) => [
    $r['severita'], $r['tipo'], $r['tecnico'] ?: '',
    $r['giorno'] ? date('d/m/Y', strtotime($r['giorno'])) : '',
    (float)$r['ore'], (int)$r['righe'], (int)$r['commesse_distinte'],
    $r['descrizione'] ?? '', $r['dettaglio'] ?? '',
];

// ── Export delle anomalie ───────────────────────────────────────────────────
// Esporta TUTTE le righe che soddisfano i filtri, senza il limite applicato a
// video: chi esporta vuole il dato completo, non la prima pagina.
$anomExp = strtolower(trim((string)($_GET['aexport'] ?? '')));
if ($tab === 'anomalie' && ($anomExp === 'xlsx' || $anomExp === 'csv')) {
    try {
        [$wA, $aA] = $anomWhere($anomF);
        $stX = $pdo->prepare("SELECT * FROM v_dgb_anomalie_orario WHERE $wA" . $anomOrder);
        $stX->execute($aA);
        $data = [$anomCols];
        while (($r = $stX->fetch(PDO::FETCH_ASSOC)) !== false) $data[] = $anomRow($r);
        $stX->closeCursor();

        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');
        $stamp = date('Ymd_Hi');
        write_log('DGB', 'info', 'Export anomalie orarie (' . $anomExp . '): ' . (count($data) - 1) . ' righe', $u_id);

        if ($anomExp === 'xlsx') {
            require_once(__DIR__ . '/XlsxWriter.php');
            $w = new XlsxWriter();
            $w->addSheet('Anomalie orarie', $data);
            $w->download("anomalie_orarie_$stamp.xlsx");
            exit;
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"anomalie_orarie_$stamp.csv\"");
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($data as $line) fputcsv($fh, $line, ';', '"');
        fclose($fh);
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Export non riuscito: " . h($e->getMessage()) . "</div>";
        redirect_self();
    }
}

if ($tab === 'anomalie') {
    try {
        $anomRiep = $pdo->query("SELECT * FROM v_dgb_anomalie_riepilogo ORDER BY FIELD(severita,'alta','media'), tipo")
                        ->fetchAll(PDO::FETCH_ASSOC);
        $anomTecnici = $pdo->query("SELECT DISTINCT tecnico FROM v_dgb_anomalie_orario
                                     WHERE tecnico IS NOT NULL AND tecnico <> '' ORDER BY tecnico")
                           ->fetchAll(PDO::FETCH_COLUMN);
        [$wA, $aA] = $anomWhere($anomF);
        // il conteggio totale e' separato dall'elenco: serve a dire quante righe
        // l'export produrrebbe, che con il limite a video non coinciderebbe
        $stC = $pdo->prepare("SELECT COUNT(*) FROM v_dgb_anomalie_orario WHERE $wA");
        $stC->execute($aA);
        $anomTot = (int)$stC->fetchColumn();

        $stA = $pdo->prepare("SELECT * FROM v_dgb_anomalie_orario WHERE $wA" . $anomOrder . " LIMIT 500");
        $stA->execute($aA);
        $anomRighe = $stA->fetchAll(PDO::FETCH_ASSOC);

        try {
            $impRiep  = $pdo->query("SELECT * FROM v_cm_anomalia_imputazione_riepilogo")->fetchAll(PDO::FETCH_ASSOC);
            $impRighe = $pdo->query("SELECT * FROM v_cm_anomalia_imputazione
                                      ORDER BY data_rapporto DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $impRighe = []; $impRiep = []; }
    } catch (Throwable $e) {
        $anomRiep = []; $anomRighe = [];
        $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Viste delle anomalie non disponibili: eseguire la migration v1.8.54.</div>";
    }
}

// dati analisi
$kpi   = $model->kpi($f);
$hb    = $model->hoursBreakdown($f);
// v1.8.80 — quadro del periodo: capacita' e composizione delle ore
$ps    = $model->periodSummary($f);
$dist  = $model->temporalDistribution($f, $gran, $month);

// v1.8.67 — matrice giorno x ora, solo in vista giornaliera: sulle dodici barre
// mensili una distribuzione oraria non avrebbe un asse su cui svilupparsi.
// v1.8.69 — export XLSX della matrice: i dati che la generano, non l'immagine.
// Tre fogli — celle, profilo orario, assenze — perche' un foglio unico
// costringerebbe a separarli a mano per farci qualunque analisi.
if ($gran === 'day' && strtolower((string)($_GET['hexport'] ?? '')) === 'xlsx') {
    try {
        $hh = $model->hourlyHeatmap($f, $month);
        $et = ['cli_ord' => 'cliente ordinario', 'cli_rep' => 'cliente reperibilità',
               'int_ord' => 'interno ordinario', 'int_rep' => 'interno reperibilità'];

        $celle = [['Giorno', 'Ora', 'Natura', 'Ore']];
        foreach ($hh['split'] as $k => $perNat) {
            [$gg, $oo] = array_map('intval', explode(':', $k));
            foreach ($perNat as $nk => $nv)
                $celle[] = [$gg, sprintf('%02d:00', $oo), $et[$nk] ?? $nk, round($nv, 2)];
        }
        $profilo = [['Ora', 'Ore totali']];
        foreach ($hh['by_hour'] as $oo => $vv) if ($vv > 0) $profilo[] = [sprintf('%02d:00', $oo), round($vv, 2)];
        $assenze = [['Giorno', 'Tipo', 'Ore']];
        foreach ($hh['absences'] as $gg => $perT)
            foreach ($perT as $tk => $vv)
                $assenze[] = [$gg, $hh['abs_by_type'][$tk]['label'] ?? $tk, round($vv, 2)];

        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');
        require_once(__DIR__ . '/XlsxWriter.php');
        $w = new XlsxWriter();
        $w->addSheet('Celle giorno-ora', $celle);
        $w->addSheet('Profilo orario', $profilo);
        if (count($assenze) > 1) $w->addSheet('Assenze', $assenze);
        write_log('DGB', 'info', 'Export XLSX matrice oraria ' . $hh['month'] . ': '
            . (count($celle) - 1) . ' celle', $u_id);
        $w->download('distribuzione_oraria_' . str_replace('-', '', $hh['month']) . '.xlsx');
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Export non riuscito: " . h($e->getMessage()) . "</div>";
        redirect_self();
    }
}

$heat = null;
if ($gran === 'day') {
    try { $heat = $model->hourlyHeatmap($f, $dist['scope']['month'] ?? $month); }
    catch (Throwable $e) { $heat = null; }
}
$rows  = $model->table($f, 100, 0);
$total = $model->count($f);
$load  = $model->loadDistribution($f, 15);
$anom  = $model->anomalies($f, 15);
$operators = $model->operators();
$statuses  = $model->statuses();
// v1.8.11: contratti DGB collegati a una commessa (per filtro ed etichette)
$clabels = $model->contractLabels(); // dgb_contract_id => project_code
$cinst = $pdo->query("SELECT dgb_contract_id, name FROM cm_projects WHERE dgb_contract_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR); // dgb_contract_id => code_x_installation (name)
$dgb_contracts = $pdo->query("SELECT DISTINCT a.id_contract cid, p.project_code FROM dgb_forms_activity a JOIN cm_projects p ON p.dgb_contract_id=a.id_contract WHERE a.deleted=0 AND a.id_contract IS NOT NULL ORDER BY p.project_code")->fetchAll(PDO::FETCH_KEY_PAIR);
$cproj_id = $pdo->query("SELECT dgb_contract_id, id FROM cm_projects WHERE dgb_contract_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);

// dati import
$batchUuid = preg_match('/^[0-9a-f-]{36}$/i', $_GET['batch'] ?? '') ? $_GET['batch'] : '';
$batchRows = $batchUuid ? $imp->batchDetail($batchUuid) : [];
$recent    = $imp->recentBatches(8);

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');

$eur = fn($v) => $v !== null ? number_format((float)$v, 2, ',', '.') : '—';
$qs = function (array $over = []) use ($f, $tab, $gran, $month) {
    $p = array_filter(['from'=>$f['from'],'to'=>$f['to'],'operator'=>$f['operator'],'status'=>$f['status'],'contract'=>$f['contract'],
                       'report_type'=>$f['report_type'],'mode'=>$f['mode'],'stdh'=>$f['stdh']!=8.0?$f['stdh']:'',
                       'schedule'=>$f['schedule'],'oncall'=>$f['oncall'],
                       'gran'=>$gran!=='month'?$gran:'','month'=>$month,'tab'=>$tab!=='analisi'?$tab:''], fn($v)=>$v!=='' && $v!==0);
    return url_safe('dgb_activities', array_merge($p, $over));
};
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div>
    <h1><i class="fa-solid fa-diagram-project"></i> Attività &amp; Rendicontazione DGB</h1>
    <p style="color:var(--muted);font-size:13px">Gerarchia pianificazione → attività → incaricati dai modelli DogoBit: SLA d'innesco, consuntivo vs pianificato, distribuzione carico sede/remoto e data quality.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn btn-success btn-sm" href="<?=$qs(['export'=>'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a>
    <a class="btn btn-primary btn-sm" href="<?=$qs(['export'=>'csv'])?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
  </div>
</div>
<?= $msg ?>

<div class="tab-bar" style="display:flex;gap:6px;border-bottom:1px solid #e2e8f0;margin-bottom:14px">
  <a class="btn btn-sm <?=$tab==='analisi'?'btn-primary':''?>" href="<?=url_safe('dgb_activities',array_filter(['from'=>$f['from'],'to'=>$f['to'],'operator'=>$f['operator'],'status'=>$f['status']]))?>">Analisi &amp; KPI</a>
  <a class="btn btn-sm <?=$tab==='import'?'btn-primary':''?>" href="<?=url_safe('dgb_activities',['tab'=>'import'])?>">Import &amp; Diff</a>
  <a class="btn btn-sm <?=$tab==='incaricati'?'btn-primary':''?>" href="<?=url_safe('dgb_activities',['tab'=>'incaricati'])?>">Incaricati (orario/reperibilità)</a>
  <?php
    // il contatore delle sole anomalie gravi va nella scheda: se restasse dentro,
    // nessuno saprebbe di doverla aprire
    $nAlta = 0;
    try { $nAlta = (int)$pdo->query("SELECT COUNT(*) FROM v_dgb_anomalie_orario WHERE severita='alta'")->fetchColumn(); }
    catch (Throwable $e) { $nAlta = 0; }
  ?>
  <a class="btn btn-sm <?=$tab==='anomalie'?'btn-primary':''?>" href="<?=url_safe('dgb_activities',['tab'=>'anomalie'])?>">
    Anomalie orarie
    <?php if ($nAlta > 0): ?>
      <span style="background:#dc2626;color:#fff;border-radius:9px;padding:0 6px;font-size:10px;font-weight:700;margin-left:4px"><?=$nAlta?></span>
    <?php endif; ?>
  </a>
</div>

<?php if ($tab === 'anomalie'): ?>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-triangle-exclamation"></i> Anomalie di imputazione oraria</span></div>
  <p style="font-size:12px;color:var(--muted);margin:4px 0 10px">
    Controlli sulla coerenza delle ore imputate. Sono segnalazioni da verificare, non errori accertati:
    il portale non conosce il contesto di ogni intervento.
  </p>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px">
    <?php foreach ($anomRiep as $r):
      $alta = $r['severita'] === 'alta';
      $lbl = $r['tipo'] === 'ore_duplicate' ? 'Ore identiche su più commesse' : 'Ore giornaliere fuori scala';
    ?>
      <a href="<?=url_safe('dgb_activities', array_merge(array_filter(['tab'=>'anomalie','atec'=>$anomF['tecnico'],'adal'=>$anomF['dal'],'aal'=>$anomF['al']], fn($v)=>$v!==''), ['atipo'=>$r['tipo']]))?>"
         style="border:1px solid #e2e8f0;border-left:4px solid <?=$alta?'#dc2626':'#f59e0b'?>;border-radius:8px;padding:11px;text-decoration:none;color:inherit;display:block">
        <div style="font-size:21px;font-weight:800;color:<?=$alta?'#dc2626':'#f59e0b'?>"><?=(int)$r['segnalazioni']?></div>
        <div style="font-size:11px;font-weight:700;color:#475569"><?=h($lbl)?></div>
        <div style="font-size:10px;color:var(--muted);margin-top:3px">
          severità <?=h($r['severita'])?> · <?=(int)$r['tecnici_coinvolti']?> tecnici ·
          <?=number_format((float)$r['ore_coinvolte'],0,',','.')?> h
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$anomRiep): ?>
      <div style="padding:14px;color:#15803d;font-size:13px"><i class="fa-solid fa-circle-check"></i> Nessuna anomalia rilevata.</div>
    <?php endif; ?>
  </div>

</div>

<?php if ($impRighe): ?>
<div class="card" style="margin-bottom:14px;border-left:4px solid #dc2626">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-file-circle-xmark"></i> Interventi imputati al contratto sbagliato</span>
    <span style="background:#dc2626;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;margin-left:8px"><?=count($impRighe)?></span>
  </div>
  <p style="font-size:12px;color:var(--muted);margin:4px 0 10px">
    I contratti di sola <strong>disponibilità</strong> — come WTS-REP, la reperibilità a canone — non devono
    ricevere moduli di intervento: il canone remunera l'essere reperibili, non l'intervento. Quando la chiamata
    arriva, il modulo appartiene al contratto operativo collegato (WTS-CC o WTS-CSS).
  </p>
  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr>
        <th>Rapporto</th><th>Data</th><th>Tecnico</th><th style="text-align:right">Ore</th>
        <th>Commessa errata</th><th>Cliente</th><th>Da spostare su</th><th>Alternative</th>
      </tr></thead>
      <tbody>
      <?php foreach ($impRighe as $r): ?>
        <tr>
          <td><code><?=h($r['codice_rapporto'])?></code></td>
          <td><?= $r['data_rapporto'] ? date('d/m/Y', strtotime($r['data_rapporto'])) : '—' ?></td>
          <td><?=h($r['tecnico'] ?: '—')?></td>
          <td style="text-align:right"><?=number_format((float)$r['ore'],2,',','.')?></td>
          <td><code style="color:#dc2626"><?=h($r['commessa_errata'])?></code>
              <span style="font-size:10px;color:var(--muted)"><?=h($r['linea_errata'])?></span></td>
          <td><?=h(mb_strimwidth((string)$r['cliente'],0,26,'…'))?></td>
          <td><?php if ($r['commessa_suggerita']): ?>
                <code style="color:#16a34a"><?=h($r['commessa_suggerita'])?></code>
              <?php else: ?>
                <span style="color:var(--muted);font-size:11px">nessuna candidata</span>
              <?php endif; ?></td>
          <td style="font-size:11px"><?php
              $nc = (int)$r['commesse_candidate'];
              if ($nc === 1)      echo '<span style="color:#16a34a">unica</span>';
              elseif ($nc > 1)    echo '<span style="color:#f59e0b">' . $nc . ' possibili</span>';
              else                echo '<span style="color:var(--muted)">—</span>';
            ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    La commessa suggerita è scelta fra quelle dello <strong>stesso cliente</strong> con linea ammessa e attive
    alla data dell'intervento, preferendo le aperte. Dove le alternative sono più d'una il suggerimento è
    indicativo: la scelta va fatta da chi conosce l'intervento. La correzione si esegue sul gestionale.
  </p>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:14px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-filter"></i> Filtri</span>
    <?php if ($anomAttivi): ?>
      <span style="background:#3b82f6;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;margin-left:8px"><?=$anomAttivi?> attiv<?=$anomAttivi==1?'o':'i'?></span>
    <?php endif; ?>
  </div>
  <form method="get" style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;align-items:end">
    <?= route_slug_field() ?>
    <input type="hidden" name="tab" value="anomalie">
    <div class="form-group" style="margin:0"><label>Tecnico</label>
      <input type="text" name="atec" list="anom_tec_dl" value="<?=h($anomF['tecnico'])?>" placeholder="nome o parte">
      <datalist id="anom_tec_dl">
        <?php foreach ($anomTecnici as $t): ?><option value="<?=h($t)?>"><?php endforeach; ?>
      </datalist></div>
    <div class="form-group" style="margin:0"><label>Tipo di anomalia</label>
      <select name="atipo">
        <option value="">— tutte —</option>
        <option value="ore_duplicate"   <?=$anomF['tipo']==='ore_duplicate'?'selected':''?>>Ore identiche su più commesse</option>
        <option value="ore_giornaliere" <?=$anomF['tipo']==='ore_giornaliere'?'selected':''?>>Ore giornaliere fuori scala</option>
      </select></div>
    <div class="form-group" style="margin:0"><label>Severità</label>
      <select name="asev">
        <option value="">— tutte —</option>
        <option value="alta"  <?=$anomF['sev']==='alta'?'selected':''?>>Alta</option>
        <option value="media" <?=$anomF['sev']==='media'?'selected':''?>>Media</option>
      </select></div>
    <div class="form-group" style="margin:0"><label>Dal giorno</label>
      <input type="date" name="adal" value="<?=h($anomF['dal'])?>"></div>
    <div class="form-group" style="margin:0"><label>Al giorno</label>
      <input type="date" name="aal" value="<?=h($anomF['al'])?>"></div>
    <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Applica</button>
      <?php if ($anomAttivi): ?>
        <a class="btn btn-sm" href="<?=url_safe('dgb_activities',['tab'=>'anomalie'])?>"><i class="fa-solid fa-eraser"></i> Azzera</a>
      <?php endif; ?>
      <span style="color:var(--muted);font-size:12px;margin-left:auto">
        <strong><?=number_format($anomTot,0,',','.')?></strong> segnalazioni corrispondono ai filtri
      </span>
      <?php
        // gli export riportano i filtri correnti: il file deve contenere
        // esattamente ciò che si sta guardando
        $aqs = fn(array $over = []) => url_safe('dgb_activities', array_merge(
            array_filter([
                'tab' => 'anomalie', 'atipo' => $anomF['tipo'], 'atec' => $anomF['tecnico'],
                'asev' => $anomF['sev'], 'adal' => $anomF['dal'], 'aal' => $anomF['al'],
            ], fn($v) => $v !== ''), $over));
      ?>
      <a class="btn btn-success btn-sm" href="<?=$aqs(['aexport'=>'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> Esporta XLSX</a>
      <a class="btn btn-sm" href="<?=$aqs(['aexport'=>'csv'])?>"><i class="fa-solid fa-file-csv"></i> Esporta CSV</a>
    </div>
  </form>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    L'export riporta le stesse colonne della tabella e <strong>tutte</strong> le righe che soddisfano i filtri,
    non le sole 500 mostrate a video.
  </p>
</div>

<?php if ($anomRighe): ?>
<div class="card" style="overflow-x:auto">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-list"></i> Segnalazioni</span>
    <span style="color:var(--muted);font-size:11px;margin-left:auto">
      <?=number_format(count($anomRighe),0,',','.')?> di <?=number_format($anomTot,0,',','.')?> righe<?= $anomTot > count($anomRighe) ? ' (prime 500 a video)' : '' ?>
    </span>
  </div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr>
      <th style="width:70px">Severità</th><th>Tipo</th><th>Tecnico</th><th>Giorno</th>
      <th style="text-align:right">Ore</th><th style="text-align:right">Righe</th>
      <th style="text-align:right">Commesse</th><th>Rilievo</th><th>Dettaglio</th>
    </tr></thead>
    <tbody>
    <?php foreach ($anomRighe as $r): ?>
      <tr>
        <td><span style="color:<?=$r['severita']==='alta'?'#dc2626':'#f59e0b'?>;font-weight:700;font-size:11px"><?=h($r['severita'])?></span></td>
        <td style="font-size:11px;color:var(--muted)"><?=h($r['tipo'])?></td>
        <td><?=h($r['tecnico'] ?: '—')?></td>
        <td><?= $r['giorno'] ? date('d/m/Y', strtotime($r['giorno'])) : '—' ?></td>
        <td style="text-align:right;font-weight:600"><?=number_format((float)$r['ore'],2,',','.')?></td>
        <td style="text-align:right"><?=(int)$r['righe']?></td>
        <td style="text-align:right"><?=(int)$r['commesse_distinte']?></td>
        <td style="font-size:11px"><?=h($r['descrizione'])?></td>
        <td style="font-size:11px;color:var(--muted)"><?=h($r['dettaglio'] ?? '—')?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    <strong>Ore identiche su più commesse</strong>: lo stesso tecnico ha imputato la stessa quantità di ore a
    commesse diverse nello stesso giorno e con lo stesso orario di inizio. È il segno tipico della compilazione
    per copia, ma può essere legittimo quando un intervento serve davvero più commesse in parallelo.<br>
    <strong>Ore giornaliere fuori scala</strong>: oltre 24 ore in un giorno è un errore certo; fra 12 e 24 è da
    verificare, perché una giornata lunga con reperibilità notturna è possibile.
  </p>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if ($tab === 'analisi'): ?>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-filter"></i> Filtri</span></div>
  <form method="get" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;align-items:end">
      <?= route_slug_field() ?>
    <input type="hidden" name="gran" value="<?=h($gran)?>"><input type="hidden" name="month" value="<?=h($month)?>">
    <div class="form-group" style="margin:0"><label>Codice attività o ticket</label>
      <input type="text" name="q" value="<?=h($f['q'] ?? '')?>" placeholder="Es. MAMT_23_000790"></div>
    <div class="form-group" style="margin:0"><label>Dal (data lavoro)</label><input type="date" name="from" value="<?=h($f['from'])?>"></div>
    <div class="form-group" style="margin:0"><label>Al (data lavoro)</label><input type="date" name="to" value="<?=h($f['to'])?>"></div>
    <div class="form-group" style="margin:0"><label>Incaricato</label>
      <select name="operator"><option value="">tutti</option>
        <?php foreach($operators as $o):?><option value="<?=(int)$o['id']?>" <?=$f['operator']===(int)$o['id']?'selected':''?>><?=h(trim($o['name']) ?: $o['username'])?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Stato</label>
      <select name="status"><option value="">tutti</option>
        <?php foreach($statuses as $s):?><option value="<?=h($s['status'])?>" <?=$f['status']===$s['status']?'selected':''?>><?=h($s['status'])?> (<?=$s['n']?>)</option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Tipo report</label>
      <select name="report_type"><option value="">tutti</option>
        <?php foreach(['STD','R_ANTEA'] as $rt):?><option value="<?=$rt?>" <?=$f['report_type']===$rt?'selected':''?>><?=$rt?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Modalità</label>
      <select name="mode"><option value="">tutte</option>
        <option value="sede" <?=$f['mode']==='sede'?'selected':''?>>sede</option>
        <option value="remoto" <?=$f['mode']==='remoto'?'selected':''?>>remoto</option></select></div>
    <div class="form-group" style="margin:0"><label>Commessa</label>
      <select name="contract"><option value="">tutte</option>
        <?php foreach($dgb_contracts as $cid=>$pc):?><option value="<?=(int)$cid?>" <?=$f['contract']===(int)$cid?'selected':''?>><?=h($pc)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Orario</label>
      <select name="schedule"><option value="">tutti</option>
        <option value="ordinario" <?=$f['schedule']==='ordinario'?'selected':''?>>ordinario</option>
        <option value="turni" <?=$f['schedule']==='turni'?'selected':''?>>turni</option></select></div>
    <div class="form-group" style="margin:0"><label>Reperibilità</label>
      <select name="oncall"><option value="">tutti</option>
        <option value="1" <?=$f['oncall']==='1'?'selected':''?>>solo reperibili</option>
        <option value="0" <?=$f['oncall']==='0'?'selected':''?>>non reperibili</option></select></div>
    <div class="form-group" style="margin:0"><label>Ore ordinarie/giorno</label><input type="number" name="stdh" step="0.5" min="1" max="24" value="<?=h((string)$f['stdh'])?>"></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Applica</button>
      <a class="btn" href="<?=url_safe('dgb_activities')?>">Azzera</a></div>
  </form>
</div>

<!-- KPI cards -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px">
  <?php
  $cards = [
    ['Attività', number_format((int)$kpi['activities'],0,',','.'), '#0f172a'],
    ['Ore pianificate', $eur($kpi['planned_hours']), '#6366f1'],
    ['Ore consuntivate', $eur($kpi['actual_hours']), '#2563eb'],
    ['Delta ore', ($kpi['delta_hours']>=0?'+':'').$eur($kpi['delta_hours']), $kpi['delta_hours']>=0?'#16a34a':'#dc2626'],
    ['Achievement', $kpi['achievement_pct']!==null?$kpi['achievement_pct'].'%':'—', '#0891b2'],
    ['In ritardo', number_format((int)$kpi['late'],0,',','.'), '#d97706'],
  ];
  foreach($cards as [$lbl,$val,$col]): ?>
    <div class="card" style="padding:12px"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em"><?=h($lbl)?></div>
      <div style="font-size:22px;font-weight:800;color:<?=$col?>;margin-top:4px"><?=$val?></div></div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
  <!-- Gauge consuntivo vs pianificato -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-gauge-high"></i> Consuntivo vs Pianificato</span></div>
    <?php
      $pct = $kpi['achievement_pct'] !== null ? (float)$kpi['achievement_pct'] : 0;
      $ang = M_PI * (1 - min($pct,200)/200); // 0%->180°(sx), 200%->0°(dx)
      $cx=150;$cy=140;$rr=110;
      $polar=function($a,$r) use($cx,$cy){ return [round($cx+$r*cos($a),1), round($cy-$r*sin($a),1)]; };
      $arc=function($a0,$a1,$r) use($polar){ [$x0,$y0]=$polar($a0,$r); [$x1,$y1]=$polar($a1,$r); $large=abs($a1-$a0)>M_PI?1:0; $sweep=$a1<$a0?1:0; return "M $x0 $y0 A $r $r 0 $large $sweep $x1 $y1"; };
      // zone: 0-80 rosso, 80-110 verde, 110-200 ambra (mappate su 180°..0°)
      $zA=fn($p)=>M_PI*(1-min($p,200)/200);
      [$nx,$ny]=$polar($ang,$rr-14);
    ?>
    <svg viewBox="0 0 300 170" style="width:100%;max-width:340px;display:block;margin:0 auto">
      <path d="<?=$arc($zA(0),$zA(80),$rr)?>" fill="none" stroke="#fca5a5" stroke-width="16"/>
      <path d="<?=$arc($zA(80),$zA(110),$rr)?>" fill="none" stroke="#86efac" stroke-width="16"/>
      <path d="<?=$arc($zA(110),$zA(200),$rr)?>" fill="none" stroke="#fcd34d" stroke-width="16"/>
      <line x1="<?=$cx?>" y1="<?=$cy?>" x2="<?=$nx?>" y2="<?=$ny?>" stroke="#0f172a" stroke-width="3"/>
      <circle cx="<?=$cx?>" cy="<?=$cy?>" r="5" fill="#0f172a"/>
      <text x="<?=$cx?>" y="<?=$cy-30?>" text-anchor="middle" font-size="30" font-weight="800" fill="#0f172a"><?=$pct?>%</text>
      <text x="40" y="160" font-size="10" fill="#94a3b8">0%</text>
      <text x="150" y="20" text-anchor="middle" font-size="10" fill="#94a3b8">100%</text>
      <text x="262" y="160" text-anchor="end" font-size="10" fill="#94a3b8">200%</text>
    </svg>
    <p style="text-align:center;font-size:12px;color:var(--muted)">Consuntivate <strong><?=$eur($kpi['actual_hours'])?> h</strong> su pianificate <strong><?=$eur($kpi['planned_hours'])?> h</strong>. Verde = in linea (80–110%).</p>
  </div>

  <!-- Distribuzione carico con baseline -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-bar"></i> Distribuzione carico per incaricato</span></div>
    <?php if(!$load['rows']): ?><p style="color:var(--muted);text-align:center;padding:14px">Nessun dato.</p>
    <?php else:
      $rowsL=$load['rows']; $base=$load['baseline'];
      $maxH=max(array_map(fn($r)=>(float)$r['total_hours'],$rowsL)); $maxH=max($maxH,$base,1);
      $bw=520;$lblW=140;$plot=$bw-$lblW-40;$rh=22;$H=count($rowsL)*$rh+34;
      $x=fn($v)=>round($lblW+($v/$maxH)*$plot,1);
    ?>
    <svg viewBox="0 0 <?=$bw?> <?=$H?>" style="width:100%;height:auto">
      <?php $baseX=$x($base); ?>
      <line x1="<?=$baseX?>" y1="6" x2="<?=$baseX?>" y2="<?=count($rowsL)*$rh+8?>" stroke="#dc2626" stroke-width="1.5" stroke-dasharray="5 3"/>
      <text x="<?=$baseX?>" y="<?=count($rowsL)*$rh+20?>" text-anchor="middle" font-size="9" fill="#dc2626">baseline <?=round($base)?>h</text>
      <?php foreach($rowsL as $i=>$r): $y=8+$i*$rh; $onX=$x((float)$r['onsite_hours'])-$lblW; $rem=(float)$r['remote_hours']; $ons=(float)$r['onsite_hours']; ?>
        <text x="<?=$lblW-6?>" y="<?=$y+12?>" text-anchor="end" font-size="10" fill="#334155"><?=h(mb_strimwidth(trim($r['assignee_name']) ?: (string)$r['username'],0,20,'…'))?></text>
        <rect x="<?=$lblW?>" y="<?=$y?>" width="<?=max(0,$x($ons)-$lblW)?>" height="14" fill="#2563eb"><title>Sede: <?=$ons?> h</title></rect>
        <rect x="<?=$x($ons)?>" y="<?=$y?>" width="<?=max(0,$x($ons+$rem)-$x($ons))?>" height="14" fill="#93c5fd"><title>Remoto: <?=$rem?> h</title></rect>
        <text x="<?=$x($ons+$rem)+4?>" y="<?=$y+12?>" font-size="9" fill="<?=$r['over']?'#16a34a':'#94a3b8'?>"><?=round((float)$r['total_hours'])?></text>
      <?php endforeach; ?>
    </svg>
    <div style="display:flex;gap:14px;font-size:11px;color:var(--muted);margin-top:6px">
      <span><span style="display:inline-block;width:12px;height:10px;background:#2563eb;border-radius:2px;vertical-align:middle"></span> sede</span>
      <span><span style="display:inline-block;width:12px;height:10px;background:#93c5fd;border-radius:2px;vertical-align:middle"></span> remoto</span>
      <span><span style="display:inline-block;width:12px;height:2px;background:#dc2626;vertical-align:middle"></span> baseline (media)</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- v1.8.80 — Capacità del periodo e composizione delle ore -->
<div class="card" style="margin-bottom:14px;border-left:4px solid #2563eb">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-calendar-check"></i> Quadro del periodo</span>
    <span style="font-size:12px;color:var(--muted);margin-left:8px">
      <?= $ps['dal'] ? date('d/m/Y', strtotime((string)$ps['dal'])) : '—' ?>
      → <?= $ps['al'] ? date('d/m/Y', strtotime((string)$ps['al'])) : '—' ?>
    </span>
  </div>

  <?php
    $hh = fn($v) => number_format((float)$v, 1, ',', '.');
    $qq = fn($v) => (float)$ps['ore_consuntivate'] > 0
        ? number_format(100 * (float)$v / (float)$ps['ore_consuntivate'], 1, ',', '.') . '%' : '—';
    $sat = $ps['utilizzo_pct'];
  ?>

  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:12px">
    <?php foreach ([
      ['Giorni lavorativi', (int)$ps['giorni_lavorativi'], '#334155',
       'Lunedì–venerdì compresi nel periodo'],
      ['Ore ordinarie al giorno', $hh($ps['ore_giornaliere']), '#334155', 'Parametro dei filtri'],
      ['Incaricati', (int)$ps['incaricati'], '#334155', 'Persone che hanno operato nel periodo'],
      ['Capacità ordinaria', $hh($ps['capacita_ordinaria']) . ' h', '#2563eb',
       'Somma delle ore standard degli incaricati attivi in ciascun giorno. È la stessa base '
       . 'della linea di riferimento nei grafici: i due numeri coincidono. '
       . 'Capacità teorica a organico pieno: ' . $hh($ps['capacita_teorica']) . ' h, '
       . 'presenza media ' . number_format((float)$ps['presenza_media_pct'], 1, ',', '.') . '%'],
      ['Ore consuntivate', $hh($ps['ore_consuntivate']) . ' h',
       ($sat !== null && $sat > 100) ? '#dc2626' : '#16a34a',
       $sat !== null ? 'Utilizzo ' . number_format($sat, 1, ',', '.') . '% della capacità' : ''],
    ] as [$lbl, $val, $col, $tip]): ?>
      <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px"
           title="<?=h($tip)?>">
        <div style="font-size:20px;font-weight:800;color:<?=$col?>"><?=$val?></div>
        <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase"><?=h($lbl)?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div style="font-size:12px;font-weight:700;margin:14px 0 8px">Dettaglio delle ore</div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
    <?php foreach ([
      ['In orario ordinario', $ps['ore_in_orario'], '#2563eb',
       'Ore che cadono nelle fasce 09–13 e 14–18 dei giorni feriali'],
      ['Fuori orario ordinario', $ps['ore_fuori_orario'], '#f59e0b',
       'Tutte le altre, fine settimana compreso. Calcolate dalla collocazione temporale'],
      ['Extra dichiarate', $ps['ore_extra'], '#dc2626',
       'Straordinario dichiarato sul modulo dal gestionale'],
      ['In reperibilità', $ps['ore_reperibilita'], '#7c3aed',
       'Interventi svolti durante un turno di reperibilità'],
      ['Da remoto', $ps['ore_remoto'], '#0d9488', 'Intervento non in sede cliente'],
      ['Smart working', $ps['ore_smart'], '#0891b2', 'Lavoro agile'],
      ['Ore di viaggio', $ps['ore_viaggio'], '#64748b', 'Trasferta, esclusa dalle ore di intervento'],
      ['Da recuperare', $ps['ore_da_recuperare'], '#b45309',
       'Ore che il tecnico dovrà recuperare'],
    ] as [$lbl, $val, $col, $tip]): ?>
      <div style="padding:10px;border-left:3px solid <?=$col?>;background:#fafafa;border-radius:4px"
           title="<?=h($tip)?>">
        <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase"><?=h($lbl)?></div>
        <div style="font-size:17px;font-weight:800;color:<?=$col?>"><?=$hh($val)?> h</div>
        <div style="font-size:10px;color:var(--muted)"><?=$qq($val)?> del consuntivo</div>
      </div>
    <?php endforeach; ?>
  </div>

  <p style="font-size:11px;color:var(--muted);margin-top:10px">
    <strong>Le voci del dettaglio si sovrappongono</strong> e non vanno sommate: un intervento da remoto
    durante un turno di reperibilità conta in entrambe. Solo <em>in orario</em> e <em>fuori orario</em>
    formano una partizione, e infatti sommano esattamente alle ore consuntivate.
  </p>

  <?php
    // extra dichiarate contro fuori orario calcolate: due misure dello stesso
    // fenomeno che sui dati divergono molto. La divergenza e' essa stessa
    // un'informazione, e va mostrata invece di scegliere quale delle due esporre.
    $exD = (float)$ps['ore_extra']; $fuo = (float)$ps['ore_fuori_orario'];
    if ($fuo > 0 && $exD < $fuo * 0.5):
  ?>
    <div class="alert alert-warning" style="font-size:11px;margin-top:8px">
      <strong>Extra dichiarate e ore fuori orario non coincidono</strong>:
      <?=$hh($exD)?> h contro <?=$hh($fuo)?> h.
      Le prime sono dichiarate sul modulo dal gestionale, le seconde calcolate dagli orari di
      inizio e fine. Se le extra devono corrispondere al lavoro fuori orario, la differenza indica
      che molte non vengono dichiarate come tali alla fonte.
    </div>
  <?php endif; ?>
</div>

<!-- Orario ordinario/straordinario & carico -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:14px">
  <?php
  $hcards = [
    ['Ore ordinarie', $eur($hb['ordinary']), '#2563eb'],
    ['Straordinario', $eur($hb['overtime']).($hb['overtime_pct']!==null?' ('.$hb['overtime_pct'].'%)':''), '#f59e0b'],
    ['Trasferta', $eur($hb['trip']), '#7c3aed'],
    ['Carico totale', $eur($hb['workload']), '#0f172a'],
    ['Capacità standard', $eur($hb['std_capacity']), '#64748b'],
    ['Saturazione', $hb['saturation_pct']!==null?$hb['saturation_pct'].'%':'—', ($hb['saturation_pct']!==null && $hb['saturation_pct']>100)?'#dc2626':'#16a34a'],
  ];
  foreach($hcards as [$lbl,$val,$col]): ?>
    <div class="card" style="padding:12px"><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em"><?=h($lbl)?></div>
      <div style="font-size:20px;font-weight:800;color:<?=$col?>;margin-top:4px"><?=$val?></div></div>
  <?php endforeach; ?>
</div>

<!-- Distribuzione temporale ordinario vs straordinario -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <?php
      // v1.8.68 — il periodo rappresentato e' dichiarato nel titolo. Il grafico
      // si aggiornava gia' passando a "Giorni (mese)", ma il titolo restava
      // identico e non c'era modo di accorgersene se non contando le barre.
      $mesiIt = [1=>'gennaio','febbraio','marzo','aprile','maggio','giugno','luglio',
                 'agosto','settembre','ottobre','novembre','dicembre'];
      if ($gran === 'day') {
          $mm = $dist['scope']['month'] ?? $month;
          $periodo = 'giorni di ' . ($mesiIt[(int)substr($mm,5,2)] ?? '') . ' ' . substr($mm,0,4);
      } else {
          $periodo = 'mesi del periodo';
      }
      $totOre = 0.0;
      foreach ($dist['buckets'] as $b) $totOre += (float)$b['ordinary'] + (float)$b['overtime'];
    ?>
    <span class="card-title"><i class="fa-solid fa-chart-column"></i>
      Distribuzione carico — ordinario vs reperibilità
      <span style="font-weight:400;color:var(--muted);font-size:12px">
        · <?=h($periodo)?> · <?=count($dist['buckets'])?> barre · <?=number_format($totOre,1,',','.')?> h
      </span>
    </span>
    <div style="display:flex;gap:6px;align-items:center">
      <a class="btn btn-sm <?=$gran==='month'?'btn-primary':''?>" href="<?=$qs(['gran'=>'month','month'=>''])?>">Mesi (periodo)</a>
      <a class="btn btn-sm <?=$gran==='day'?'btn-primary':''?>" href="<?=$qs(['gran'=>'day','month'=>$month ?: substr($dist['scope']['month'] ?? ($f['to'] ?: date('Y-m')),0,7)])?>">Giorni (mese)</a>
      <?php if($gran==='day'):
        // v1.8.52: navigazione fra mesi adiacenti. Senza, per confrontare due
        // mesi occorre tornare alla vista mensile e riscendere.
        $curM = $dist['scope']['month'] ?? $month ?: date('Y-m');
        $prevM = date('Y-m', strtotime($curM . '-01 -1 month'));
        $nextM = date('Y-m', strtotime($curM . '-01 +1 month'));
      ?>
      <a class="btn btn-sm" title="Mese precedente" href="<?=$qs(['gran'=>'day','month'=>$prevM])?>"><i class="fa-solid fa-chevron-left"></i></a>
      <form method="get" style="display:inline-flex;gap:4px;align-items:center;margin:0">
      <?= route_slug_field() ?>
        <?php foreach(['from','to','operator','status','report_type','mode','stdh'] as $k): if($f[$k]!=='' && $f[$k]!==0): ?><input type="hidden" name="<?=$k?>" value="<?=h((string)$f[$k])?>"><?php endif; endforeach; ?>
        <input type="hidden" name="gran" value="day">
        <input type="month" name="month" value="<?=h($curM)?>" onchange="this.form.submit()">
      </form>
      <a class="btn btn-sm" title="Mese successivo" href="<?=$qs(['gran'=>'day','month'=>$nextM])?>"><i class="fa-solid fa-chevron-right"></i></a>
      <?php endif; ?>
    </div>
  </div>
  <div style="overflow-x:auto"><?php
    // v1.8.52: in vista mensile ogni barra apre il dettaglio giornaliero del
    // proprio mese, preservando i filtri attivi.
    echo dgb_dist_svg($dist, fn(string $k) => $qs(['gran' => 'day', 'month' => substr($k, 0, 7)]));
  ?></div>
  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-top:8px">
    <?php if ($heat && $heat['total'] > 0): ?>
  <?php // v1.8.81 — larghezza e spaziature allineate agli altri grafici della
        // pagina: il blocco usava margini propri e risultava piu stretto,
        // spezzando l'allineamento verticale della colonna. ?>
  <div style="margin-top:16px;border-top:1px solid #e2e8f0;padding-top:14px;
              margin-left:-4px;margin-right:-4px;padding-left:4px;padding-right:4px">
    <?php
      // v1.8.72 — LE DEFINIZIONI STANNO QUI, PRIMA DI OGNI LORO USO.
      //
      // Nella v1.8.69 `$NAT` era dichiarato nel blocco che precede la tabella,
      // ma la LEGENDA che lo usa viene prima: PHP la eseguiva con la variabile
      // ancora inesistente, producendo "Undefined variable $NAT" e un foreach su
      // null. Il difetto non compariva in sviluppo perche' con display_errors
      // spento l'avviso e' silenzioso e la legenda semplicemente non appare.
      //
      // Le quattro nature: la tinta porta la natura, la saturazione il volume.
      // Blu e arancione restano i colori di ordinario e reperibilita' del
      // grafico a colonne; le attivita' interne usano verde e rosso.
      $NAT = [
          'cli_ord' => ['#2563eb', 'cliente · ordinario'],
          'cli_rep' => ['#f59e0b', 'cliente · reperibilità'],
          'int_ord' => ['#0d9488', 'interno · ordinario'],
          'int_rep' => ['#dc2626', 'interno · reperibilità'],
      ];
      $oreOrd = [9,10,11,12,14,15,16,17];   // fasce ordinarie, v1.8.53

      /** Miscela il colore della natura dominante con il bianco secondo il volume. */
      $cella = function (array $perNat, float $max) use ($NAT): array {
          $tot = array_sum($perNat);
          if ($tot <= 0) return ['#f8fafc', '', 0.0];
          arsort($perNat);
          $dom = array_key_first($perNat);
          $hex = $NAT[$dom][0] ?? '#2563eb';
          // radice e non lineare: la cella massima vale quasi tre volte la media
          $t = $max > 0 ? sqrt($tot / $max) : 0;
          $r = hexdec(substr($hex,1,2)); $g = hexdec(substr($hex,3,2)); $b = hexdec(substr($hex,5,2));
          $mix = fn(int $c) => (int)round(248 - (248 - $c) * $t);
          return [sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b)), $dom, $tot];
      };
    ?>
    <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:8px;flex-wrap:wrap">
      <strong style="font-size:13px">Distribuzione sulle 24 ore</strong>
      <?php foreach ($NAT as $nk => [$col, $etichetta]):
        $vn = $heat['by_nature'][$nk] ?? 0.0; if ($vn <= 0) continue; ?>
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px">
          <span style="width:12px;height:12px;background:<?=$col?>;display:inline-block;border-radius:2px"></span>
          <?=h($etichetta)?> <strong><?=number_format($vn,1,',','.')?></strong> h
          (<?=number_format($vn / max($heat['total'],0.01) * 100, 1, ',', '.')?>%)
        </span>
      <?php endforeach; ?>
      <span style="font-size:11px;color:var(--muted);margin-left:auto">
        <?=number_format($heat['total'],1,',','.')?> ore ripartite sulle fasce attraversate
      </span>
      <?php
        // gli export riportano i filtri correnti: il file deve contenere
        // esattamente cio' che si sta guardando
        $hqs = fn(array $over = []) => url_safe('dgb_activities', array_merge(
            array_filter($f, fn($v) => $v !== '' && $v !== null && !is_array($v)),
            ['gran' => 'day', 'month' => $heat['month']], $over));
      ?>
      <a class="btn btn-success btn-sm" style="font-size:11px;padding:3px 8px"
         href="<?=$hqs(['hexport' => 'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a>
    </div>
    <?php
      // Le definizioni di $NAT, $oreOrd e $cella sono in testa al riquadro,
      // sopra la legenda che le usa (v1.8.72).
    ?>
    <div style="overflow-x:auto;width:100%">
      <table style="border-collapse:collapse;font-size:9px;width:100%;table-layout:fixed">
        <thead>
          <tr>
            <th style="text-align:right;padding-right:6px;font-weight:600;color:#64748b">ora</th>
            <?php for ($g = 1; $g <= $heat['days']; $g++):
              $dow = (int)date('N', strtotime($heat['month'] . '-' . sprintf('%02d', $g))); ?>
              <th style="width:15px;color:<?=$dow>=6?'#cbd5e1':'#64748b'?>;font-weight:600"><?=$g?></th>
            <?php endfor; ?>
            <th style="padding-left:8px;color:#64748b;font-weight:600">tot</th>
          </tr>
        </thead>
        <tbody>
        <?php for ($o = 23; $o >= 0; $o--):
          if ($heat['by_hour'][$o] <= 0) continue;
          $ord = in_array($o, $oreOrd, true); ?>
          <tr>
            <td style="text-align:right;padding-right:6px;color:<?=$ord?'#334155':'#94a3b8'?>;font-weight:<?=$ord?'700':'400'?>">
              <?=sprintf('%02d', $o)?></td>
            <?php for ($g = 1; $g <= $heat['days']; $g++):
              $perNat = $heat['split'][$g . ':' . $o] ?? [];
              [$bg, $dom, $vTot] = $cella($perNat, $heat['max']);
              $tt = '';
              if ($vTot > 0) {
                  $parti = [];
                  foreach ($perNat as $nk => $nv)
                      $parti[] = ($NAT[$nk][1] ?? $nk) . ' ' . number_format($nv, 2, ',', '.') . ' h';
                  $tt = $g . '/' . (int)substr($heat['month'],5,2) . ' ore ' . sprintf('%02d',$o) . ':00 — '
                      . number_format($vTot,2,',','.') . ' h · ' . implode(' · ', $parti);
              } ?>
              <td style="width:15px;height:13px;background:<?=$bg?>;border:1px solid #fff"
                  <?php if ($tt): ?>title="<?=h($tt)?>"<?php endif; ?>></td>
            <?php endfor; ?>
            <td style="padding-left:8px;text-align:right;color:<?=$ord?'#334155':'#94a3b8'?>">
              <?=number_format($heat['by_hour'][$o],0,',','.')?></td>
          </tr>
        <?php endfor; ?>
        </tbody>
        <?php if (!empty($heat['absences'])): ?>
        <tfoot>
          <?php
            // Le assenze stanno in una banda SEPARATA, non distribuite sulle 24
            // ore: sono ore non lavorate, e spalmarle su una fascia oraria darebbe
            // l'impressione che qualcuno lavorasse durante le ferie.
            foreach ($heat['abs_by_type'] as $tk => $td):
              $rigaVuota = true;
              foreach ($heat['absences'] as $gg => $perT) if (!empty($perT[$tk])) { $rigaVuota = false; break; }
              if ($rigaVuota) continue; ?>
            <tr>
              <td style="text-align:right;padding-right:6px;color:<?=h($td['colore'])?>;font-weight:700;white-space:nowrap">
                <?=h($td['label'])?></td>
              <?php for ($g = 1; $g <= $heat['days']; $g++):
                $v = $heat['absences'][$g][$tk] ?? 0.0;
                $t = $heat['abs_max'] > 0 && $v > 0 ? sqrt($v / $heat['abs_max']) : 0;
                $hex = $td['colore'] ?: '#8b5cf6';
                $mix = fn(int $c) => (int)round(248 - (248 - $c) * $t);
                $bg = $v > 0 ? sprintf('#%02x%02x%02x',
                        $mix(hexdec(substr($hex,1,2))), $mix(hexdec(substr($hex,3,2))), $mix(hexdec(substr($hex,5,2))))
                      : '#f8fafc'; ?>
                <td style="width:15px;height:13px;background:<?=$bg?>;border:1px solid #fff"
                    <?php if ($v > 0): ?>title="<?=$g?> — <?=h($td['label'])?> <?=number_format($v,1,',','.')?> h"<?php endif; ?>></td>
              <?php endfor; ?>
              <td style="padding-left:8px;text-align:right;color:<?=h($td['colore'])?>">
                <?=number_format($td['ore'],0,',','.')?></td>
            </tr>
          <?php endforeach; ?>
        </tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php if (!empty($heat['abs_by_type'])): ?>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;font-size:11px">
        <strong>Assenze</strong>
        <?php foreach ($heat['abs_by_type'] as $td): ?>
          <span style="display:inline-flex;align-items:center;gap:5px">
            <span style="width:12px;height:12px;background:<?=h($td['colore'])?>;display:inline-block;border-radius:2px"></span>
            <?=h($td['label'])?> <strong><?=number_format($td['ore'],1,',','.')?></strong> h
          </span>
        <?php endforeach; ?>
        <span style="color:var(--muted);margin-left:auto">
          totale <?=number_format($heat['abs_total'],1,',','.')?> h non lavorate
        </span>
      </div>
    <?php endif; ?>
    <p style="font-size:11px;color:var(--muted);margin:8px 0 0">
      Ogni cella è un'ora di un giorno, colorata secondo la <strong>natura prevalente</strong>:
      <strong style="color:#2563eb">blu</strong> cliente ordinario,
      <strong style="color:#b45309">arancione</strong> cliente in reperibilità,
      <strong style="color:#0d9488">verde</strong> interno ordinario,
      <strong style="color:#dc2626">rosso</strong> interno in reperibilità.
      Più la cella è intensa, più ore vi sono state lavorate; il suggerimento riporta la ripartizione
      completa quando una cella contiene più nature.
      Le <strong>assenze</strong> — ferie, permessi, recuperi, malattia — stanno nella banda sotto la
      griglia: sono ore <em>non</em> lavorate e non appartengono a una fascia oraria.
      Le fasce ordinarie sono 09–13 e 14–18 dal lunedì al venerdì: <strong>nel fine settimana anche
      quelle ore sono reperibilità</strong>, ed è per questo che le colonne del sabato e della domenica
      risultano arancioni per intera.
      Le ore di un intervento sono <strong>ripartite sulle fasce che attraversa</strong>, non attribuite
      all'orario di inizio: attribuirle all'inizio concentrerebbe quasi tutto sulle 09:00, che è
      l'orario con cui la maggior parte degli interventi viene registrata.
    </p>
  </div>
  <?php endif; ?>

  <p style="font-size:11px;color:var(--muted);margin:0;max-width:70%">
      <strong>Ordinario</strong> = lun-ven 09:00–13:00 e 14:00–18:00 (8 h/giorno).
      Fuori da queste fasce — fine settimana, 18:01–08:59 e pausa pranzo — l'intervento è in
      <strong>reperibilità</strong>. Chi opera in turni non è soggetto alla regola.
      Le ore consuntivate non vengono ricalcolate: viene ripartita la loro classificazione,
      in proporzione a quanto dell'intervento cade nella fascia ordinaria.
      <?php if ($gran === 'month'): ?>
        Riferimento = giorni lavorativi × <?=h((string)$f['stdh'])?> h × <?=$dist['operators']?> incaricat<?=$dist['operators']==1?'o':'i'?>.
        <strong>Fai clic su un mese per aprirne il dettaglio giornaliero.</strong>
      <?php else: ?>
        Riferimento = <?=h((string)$f['stdh'])?> h × incaricati <strong>attivi in quel giorno</strong>;
        dove non ci sono dati si usa la mediana dei feriali
        (<?= (int)($dist['scope']['median_actives'] ?? 0) ?> incaricati).
        Nei giorni di chiusura il riferimento non esiste e la linea si interrompe.
      <?php endif; ?>
      Passa il mouse su una barra per i valori esatti.
    </p>
    <div style="display:flex;gap:6px">
      <a class="btn btn-success btn-sm" href="<?=$qs(['export'=>'distxlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a>
      <a class="btn btn-primary btn-sm" href="<?=$qs(['export'=>'distcsv'])?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
      <a class="btn btn-sm" href="<?=$qs(['export'=>'distsvg'])?>"><i class="fa-solid fa-image"></i> Grafico (SVG)</a>
    </div>
  </div>
</div>

<!-- Data quality -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-triangle-exclamation"></i> Data Quality</span></div>
  <div style="display:flex;gap:24px;flex-wrap:wrap">
    <div><span style="font-size:22px;font-weight:800;color:<?=$anom['orphan_count']?'#d97706':'#16a34a'?>"><?=number_format((int)$anom['orphan_count'],0,',','.')?></span>
      <div style="font-size:11px;color:var(--muted)">ticket orfani (senza piano)</div></div>
    <div><span style="font-size:22px;font-weight:800;color:<?=$anom['empty_plan_count']?'#d97706':'#16a34a'?>"><?=number_format((int)$anom['empty_plan_count'],0,',','.')?></span>
      <div style="font-size:11px;color:var(--muted)">piani vuoti (senza ticket)</div></div>
  </div>
  <?php if($anom['empty_plan_sample']): ?>
    <div style="margin-top:10px;font-size:11px;color:var(--muted)">Piani vuoti (campione): <?=implode(', ', array_map(fn($p)=>'#'.$p['id'].' '.h(mb_strimwidth((string)$p['name'],0,24,'…')), $anom['empty_plan_sample']))?></div>
  <?php endif; ?>
</div>

<!-- Tabella dati -->
<div class="card" style="overflow-x:auto">
  <div class="card-header" style="display:flex;justify-content:space-between"><span class="card-title"><i class="fa-solid fa-table"></i> Attività</span>
    <span style="font-size:12px;color:var(--muted)"><strong><?=number_format($total,0,',','.')?></strong> attività · prime 100</span></div>
  <table class="data-table" style="width:100%;font-size:12px;white-space:nowrap">
    <thead><tr><th>ID</th><th>Codice</th><th>Commessa</th><th>Ticket</th><th>Stato</th><th>Avvio</th><th>SLA (h)</th><th style="text-align:right">Pian.</th><th style="text-align:right">Cons.</th><th style="text-align:right">Δ</th><th style="text-align:right">Costo</th><th style="text-align:right">Ricavo</th></tr></thead>
    <tbody>
    <?php if(!$rows): ?><tr><td colspan="12" style="text-align:center;color:var(--muted);padding:18px">Nessuna attività per i filtri impostati.</td></tr>
    <?php else: foreach($rows as $r): ?>
      <tr>
        <td><?=(int)$r['activity_id']?><?=$r['is_orphan']?' <span title="Senza piano" style="color:#d97706"><i class="fa-solid fa-link-slash" style="font-size:10px"></i></span>':''?></td>
        <td style="font-weight:600"><?=h($r['code'])?></td>
        <td><?php $cid=(int)($r['id_contract']??0); if($cid && isset($clabels[$cid])): ?><a href="<?=url_safe('project_dashboard',['id'=>(int)($cproj_id[$cid]??0),'tab'=>'dgb'])?>" title="Apri scheda commessa"><?=h($clabels[$cid])?></a><?php if(!empty($cinst[$cid]) && $cinst[$cid]!==$clabels[$cid]): ?><br><span style="color:var(--muted);font-size:11px" title="code_x_installation"><?=h(mb_strimwidth((string)$cinst[$cid],0,32,'…'))?></span><?php endif; ?><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
        <td><?=h(mb_strimwidth((string)$r['ticket'],0,26,'…'))?></td>
        <td><?=h($r['status'])?></td>
        <td><?=h($r['date_start'])?></td>
        <td style="text-align:right;<?=($r['sla_hours']!==null && $r['sla_hours']>0)?'color:#d97706':''?>"><?=$r['sla_hours']!==null?(int)$r['sla_hours']:'—'?></td>
        <td style="text-align:right"><?=$eur($r['planned_hours'])?></td>
        <td style="text-align:right"><?=$eur($r['actual_hours'])?></td>
        <td style="text-align:right;<?=($r['delta_hours']<0)?'color:#dc2626':'color:#16a34a'?>"><?=$eur($r['delta_hours'])?></td>
        <td style="text-align:right"><?=$eur($r['total_cost'])?></td>
        <td style="text-align:right"><?=$eur($r['total_revenue'])?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p style="font-size:11px;color:var(--muted);margin-top:8px">SLA d'innesco = scostamento (ore) tra avvio previsto dal piano e avvio effettivo (positivo = in ritardo). Δ = ore consuntivate − pianificate. Export ed endpoint JSON (<code>dgb_api.php</code>) rispettano i filtri.</p>
</div>

<?php elseif ($tab === 'import'): /* ── TAB IMPORT & DIFF ─────────────────────────────── */ ?>

<?php if($can_edit): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-import"></i> Importa modelli DogoBit</span></div>
  <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <p style="font-size:12px;color:var(--muted);margin:0">Seleziona uno o più CSV (separatore <code>|</code>). Il modello è riconosciuto dal nome file: <em>dgb_operator</em>, <em>dgb_operator_allocations_on_forms_contract</em>, <em>forms_contract</em>, <em>forms_activity_planning</em>, <em>forms_activity</em>, <em>forms_activity_has_dgb_operator</em>. Dopo l'import dei contratti, la sincronizzazione imposta <strong>codice commessa = Code</strong> e <strong>nome = code_x_installation</strong>. <?=h(UploadGuard::limitsNote())?></p>
    <input type="file" name="files[]" accept=".csv" multiple required>
    <div><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Importa e calcola differenze</button></div>
  </form>
</div>
<?php endif; ?>

<?php if($can_edit): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-arrows-turn-to-dots"></i> Sincronizza su Commesse &amp; Moduli intervento</span></div>
  <p style="font-size:12px;color:var(--muted);margin:0 0 10px">Materializza i dati DGB nelle sotto-voci native di Gestione Commesse: crea le commesse mancanti (da contratto DogoBit) e genera i <strong>moduli di intervento</strong> (<code>cm_intervention_reports</code>) da ogni riga incaricato — con tecnico collegato, ore, remoto e reperibilità. Operazione idempotente (ri-eseguibile senza duplicati).</p>
  <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='Sincronizzazione in corso…'">
    <?= csrf_field() ?><input type="hidden" name="action" value="sync">
    <button class="btn btn-primary"><i class="fa-solid fa-arrows-rotate"></i> Sincronizza ora</button>
  </form>
</div>
<?php endif; ?>
<?php if($batchRows): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-code-compare"></i> Report differenziale — batch <?=h(substr($batchUuid,0,8))?></span></div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Tabella</th><th>File</th><th style="text-align:right">Letti</th><th style="text-align:right">Nuovi</th><th style="text-align:right">Modificati</th><th style="text-align:right">Invariati</th><th style="text-align:right">Scartati</th></tr></thead>
    <tbody>
    <?php foreach($batchRows as $b): ?>
      <tr>
        <td style="font-weight:600"><?=h($b['table_name'])?></td>
        <td style="color:var(--muted)"><?=h(mb_strimwidth((string)$b['source_file'],0,36,'…'))?></td>
        <td style="text-align:right"><?=number_format((int)$b['rows_read'],0,',','.')?></td>
        <td style="text-align:right;color:#16a34a;font-weight:600"><?=number_format((int)$b['rows_inserted'],0,',','.')?></td>
        <td style="text-align:right;color:#d97706;font-weight:600"><?=number_format((int)$b['rows_updated'],0,',','.')?></td>
        <td style="text-align:right;color:var(--muted)"><?=number_format((int)$b['rows_unchanged'],0,',','.')?></td>
        <td style="text-align:right"><?=number_format((int)$b['rows_skipped'],0,',','.')?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Storico importazioni</span></div>
  <?php if(!$recent): ?><p style="color:var(--muted);text-align:center;padding:14px">Nessuna importazione registrata.</p>
  <?php else: ?>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Data</th><th>Batch</th><th style="text-align:right">Letti</th><th style="text-align:right">Nuovi</th><th style="text-align:right">Modificati</th><th style="text-align:right">Invariati</th><th></th></tr></thead>
    <tbody>
    <?php foreach($recent as $b): ?>
      <tr>
        <td><?=h(date('d/m/Y H:i', strtotime($b['created_at'])))?></td>
        <td style="font-family:monospace;font-size:11px"><?=h(substr($b['batch_uuid'],0,8))?></td>
        <td style="text-align:right"><?=number_format((int)$b['read_tot'],0,',','.')?></td>
        <td style="text-align:right;color:#16a34a"><?=number_format((int)$b['ins_tot'],0,',','.')?></td>
        <td style="text-align:right;color:#d97706"><?=number_format((int)$b['upd_tot'],0,',','.')?></td>
        <td style="text-align:right;color:var(--muted)"><?=number_format((int)$b['unch_tot'],0,',','.')?></td>
        <td><a class="btn btn-sm" href="<?=url_safe('dgb_activities',['tab'=>'import','batch'=>$b['batch_uuid']])?>">dettaglio</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php else: /* ── TAB INCARICATI (orario/reperibilità) ──────────────────── */
$profiles = $model->operatorProfiles();
$n_turni = count(array_filter($profiles, fn($p)=>$p['schedule_type']==='turni'));
$n_oncall = count(array_filter($profiles, fn($p)=>(int)$p['on_call']===1));
$n_mapped = count(array_filter($profiles, fn($p)=>$p['employee_id']));
?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header" style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <span class="card-title"><i class="fa-solid fa-user-clock"></i> Incaricati — orario e reperibilità</span>
    <?php if($can_edit): ?>
    <form method="post" onsubmit="this.querySelector('button').disabled=true">
      <?= csrf_field() ?><input type="hidden" name="action" value="classify">
      <button class="btn btn-sm"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-classifica dai dati</button>
    </form>
    <?php endif; ?>
  </div>
  <p style="font-size:12px;color:var(--muted);margin:0 0 6px"><strong><?=count($profiles)?></strong> incaricati · <strong><?=$n_turni?></strong> a turni · <strong><?=$n_oncall?></strong> in reperibilità · <strong><?=$n_mapped?></strong> collegati a un dipendente. L'orario ordinario è la fascia 09–18 lun–ven; "turni" indica chi lavora prevalentemente fuori fascia/weekend. La reperibilità deriva dalle attività svolte in disponibilità (<code>during_availability</code>) ma è modificabile.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_profiles">
    <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Incaricato</th><th>Dipendente collegato</th><th style="text-align:right">Ore</th><th style="text-align:right">Attività</th><th>Orario</th><th style="text-align:center">Reperibilità</th><th style="text-align:center">Auto</th></tr></thead>
      <tbody>
      <?php foreach($profiles as $p): ?>
        <tr>
          <td style="font-weight:600"><?=h($p['name'] ?: $p['username'])?></td>
          <td><?php if($p['employee_id']): ?><?=h($p['employee_name'])?><?php else: ?><span style="color:#d97706" title="Nessun dipendente collegato">— non collegato</span><?php endif; ?></td>
          <td style="text-align:right"><?=number_format((float)$p['total_hours'],1,',','.')?></td>
          <td style="text-align:right"><?=number_format((int)$p['activities'],0,',','.')?></td>
          <td>
            <?php if($can_edit): ?>
            <select name="schedule[<?=(int)$p['id']?>]" style="font-size:12px;padding:2px">
              <option value="ordinario" <?=$p['schedule_type']==='ordinario'?'selected':''?>>ordinario</option>
              <option value="turni" <?=$p['schedule_type']==='turni'?'selected':''?>>turni</option>
            </select>
            <?php else: ?><?=h($p['schedule_type'])?><?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if($can_edit): ?><input type="checkbox" name="oncall[<?=(int)$p['id']?>]" value="1" <?=(int)$p['on_call']?'checked':''?>>
            <?php else: ?><?=(int)$p['on_call']?'sì':'no'?><?php endif; ?>
          </td>
          <td style="text-align:center"><?=(int)$p['auto_classified']?'<span title="Classificato automaticamente" style="color:#0891b2"><i class="fa-solid fa-robot" style="font-size:11px"></i></span>':'<span title="Manuale" style="color:#64748b"><i class="fa-solid fa-hand" style="font-size:11px"></i></span>'?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php if($can_edit): ?><div style="margin-top:10px"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva profili</button></div><?php endif; ?>
  </form>
</div>

<?php endif; ?>
<?php require_once('footer.php'); ?>
