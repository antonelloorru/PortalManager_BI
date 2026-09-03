<?php
/**
 * manage_projects.php — Elenco commesse + creazione manuale
 *
 * v1.8.47 — Revisione dell'interfaccia.
 *
 *   · Il modulo "Nuova commessa" non occupa più la parte alta della pagina:
 *     è racchiuso in un pannello che si apre su richiesta. Chi consulta
 *     l'elenco vede subito i dati, chi deve inserire apre il pannello.
 *   · Il pannello dei filtri è anch'esso a scomparsa, con l'indicazione di
 *     quanti filtri sono attivi. Si apre da solo quando lo sono, così un
 *     elenco filtrato non sembra mai un elenco incompleto.
 *   · I filtri coprono ora tutte le 29 colonne dello standard "Lista commesse",
 *     raggruppati per area anziché elencati alla rinfusa.
 *   · Etichette riviste: la tabella conserva i nomi dello standard di export,
 *     i filtri usano nomi discorsivi che spiegano che cosa selezionano.
 *
 * Export XLSX/CSV a 29 colonne, invariato e verificato.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/ProjectModel.php');

if (!can('view', 'manage_projects.php')) { redirect('dashboard'); }
$can_create = can('create', 'manage_projects.php');
$u_id  = (int)$_SESSION['user_id'];
$model = new ProjectModel($pdo);
$prefix = new PrefixResolver($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (($_POST['action'] ?? '') === 'create' && $can_create) {
        $code = trim($_POST['project_code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($code !== '' && $name !== '') {
            $clientId = ($_POST['client_raw'] ?? '') !== '' ? $model->upsertClient(trim($_POST['client_raw'])) : null;
            $st = $pdo->prepare(
              "INSERT INTO cm_projects (project_code,name,abbr,commercial_ref,external_link,service_line,project_type,client_id,client_raw,exec_company_id,
                                     commercial_status,operational_status,description,internal_description,
                                     start_date,end_date,value_total,material_costs,created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE name=VALUES(name)"
            );
            $st->execute([
                $code, $name,
                trim($_POST['abbr'] ?? '') ?: null,
                trim($_POST['commercial_ref'] ?? '') ?: null,
                trim($_POST['external_link'] ?? '') ?: null,
                $_POST['service_line'] ?: null, $_POST['project_type'] ?: null,
                $clientId, ($_POST['client_raw'] ?? '') ?: null, $prefix->companyId($code),
                $_POST['commercial_status'] ?: null, $_POST['operational_status'] ?: 'APERTA',
                trim($_POST['description'] ?? '') ?: null,
                trim($_POST['internal_description'] ?? '') ?: null,
                ($_POST['start_date'] ?? '') ?: null,
                ($_POST['end_date'] ?? '') ?: null,
                ($_POST['value_total'] ?? '') !== '' ? (float)$_POST['value_total'] : null,
                ($_POST['material_costs'] ?? '') !== '' ? (float)$_POST['material_costs'] : 0,
                $u_id,
            ]);
            $id = (int)$pdo->lastInsertId();
            write_log('Projects','success',"Commessa creata $code (#$id)",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Commessa <strong>".h($code)."</strong> creata.</div>";
            if ($id > 0) redirect('project_dashboard', ['id' => $id]);
        } else {
            // riapro il pannello di inserimento: un errore senza il modulo davanti
            // lascerebbe l'utente a chiedersi dove ha sbagliato
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Codice commessa e denominazione sono obbligatori.</div>";
            $_SESSION['reopen_new'] = 1;
        }
    }
    redirect_self();
}

// ── Filtri: lettura dei parametri ───────────────────────────────────────────
$dateOk = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : '';
$SORTS = ['code','code_desc','name','client','value_desc','value_asc','margin_desc','margin_asc',
          'residual_desc','cost_desc','start_desc','start_asc','end_asc','anom_desc'];

$f = [
    // ricerca e anagrafica
    'q'              => trim($_GET['q'] ?? ''),
    'abbr'           => trim($_GET['abbr'] ?? ''),
    'commercial_ref' => trim($_GET['cref'] ?? ''),
    'client_raw'     => trim($_GET['cliente'] ?? ''),
    'descr'          => trim($_GET['descr'] ?? ''),
    'client_id'      => (int)($_GET['client'] ?? 0),
    'company_id'     => (int)($_GET['company'] ?? 0),
    'service_line'   => trim($_GET['sl'] ?? ''),
    'type'           => trim($_GET['type'] ?? ''),
    'has_link'       => $_GET['link'] ?? '',
    'has_dgb'        => $_GET['dgb'] ?? '',
    // stato e compliance
    'status'               => trim($_GET['status'] ?? ''),
    'commercial'           => trim($_GET['commercial'] ?? ''),
    'econ'                 => trim($_GET['econ'] ?? ''),
    'econ_today'           => trim($_GET['econ_today'] ?? ''),
    'compliance_to_verify' => $_GET['cverify'] ?? '',
    'compliance_preauth'   => $_GET['cpre'] ?? '',
    'anom_open'            => !empty($_GET['aopen']) ? 1 : 0,
    'anom_blocking'        => !empty($_GET['ablocking']) ? 1 : 0,
    // periodo
    'from'        => $dateOk('from'),
    'to'          => $dateOk('to'),
    'end_from'    => $dateOk('end_from'),
    'end_to'      => $dateOk('end_to'),
    'no_end'      => !empty($_GET['no_end']) ? 1 : 0,
    'ending_days' => (int)($_GET['ending'] ?? 0),
    // importi
    'value_min'    => $_GET['vmin'] ?? '',
    'value_max'    => $_GET['vmax'] ?? '',
    'margin_min'   => $_GET['mmin'] ?? '',
    'margin_max'   => $_GET['mmax'] ?? '',
    'residual_min' => $_GET['rmin'] ?? '',
    'residual_max' => $_GET['rmax'] ?? '',
    'cost_min'     => $_GET['kmin'] ?? '',
    'cost_max'     => $_GET['kmax'] ?? '',
    'margin_neg'   => !empty($_GET['mneg']) ? 1 : 0,
    'overdraft'    => !empty($_GET['fido']) ? 1 : 0,
    // fatturazione e provenienza
    'bill_freq' => (int)($_GET['bfreq'] ?? 0),
    'bill_from' => $dateOk('bfrom'),
    'bill_to'   => $dateOk('bto'),
    'batch'     => (int)($_GET['batch'] ?? 0),
    'sort'      => in_array($_GET['sort'] ?? '', $SORTS, true) ? $_GET['sort'] : 'code',
];

$rows = $model->listAll($f);

// v1.8.11: rollup DGB per le commesse in elenco (riconciliazione via dgb_contract_id)
require_once(__DIR__ . '/app/DgbModel.php');
$dgb = new DgbModel($pdo);
$dgb_map = []; $dgb_roll = [];
if ($rows) {
    $pids = array_map(fn($r) => (int)$r['id'], $rows);
    $mp = $pdo->query("SELECT id, dgb_contract_id FROM cm_projects WHERE id IN (" . implode(',', $pids) . ") AND dgb_contract_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
    $dgb_map = array_map('intval', $mp);
    $roll = $dgb->commessaRollup(array_values($dgb_map));
    foreach ($dgb_map as $pid => $cid) if (isset($roll[$cid])) $dgb_roll[$pid] = $roll[$cid];
}

// sorgenti per i menu a tendina
$clients       = $pdo->query("SELECT id,name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$companies     = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$op_statuses   = $pdo->query("SELECT DISTINCT operational_status FROM cm_projects WHERE operational_status IS NOT NULL AND operational_status<>'' ORDER BY operational_status")->fetchAll(PDO::FETCH_COLUMN);
$service_lines = $pdo->query("SELECT DISTINCT service_line FROM cm_projects WHERE service_line IS NOT NULL AND service_line<>'' ORDER BY service_line")->fetchAll(PDO::FETCH_COLUMN);
$econ_statuses = $pdo->query("SELECT DISTINCT economic_status FROM cm_projects WHERE economic_status IS NOT NULL AND economic_status<>'' ORDER BY economic_status")->fetchAll(PDO::FETCH_COLUMN);
$econ_today    = $pdo->query("SELECT DISTINCT economic_status_todate FROM cm_projects WHERE economic_status_todate IS NOT NULL AND economic_status_todate<>'' ORDER BY economic_status_todate")->fetchAll(PDO::FETCH_COLUMN);
$bill_freqs    = $pdo->query("SELECT DISTINCT billing_freq_months FROM cm_projects WHERE billing_freq_months IS NOT NULL AND billing_freq_months>0 ORDER BY billing_freq_months")->fetchAll(PDO::FETCH_COLUMN);
$comm_statuses = ['In Approvazione','Offerta Presentata','Acquisita','Persa'];
$proj_types    = ['Gara Consip/MePA/Carrier','Progetto Standard','Trattativa Diretta'];

// ── Header standard "Lista commesse" (29 colonne, ordine del file di export) ──
$STD_HEADERS = [
    'abbr','commerciale','link','tipo','codice_commessa','commessa','cliente',
    'descrizione','descrizione interna','stato','compliance da verificare','compliance pre autorizzata',
    'data inizio','data fine','anomalie aperte','anomalie bloccanti',
    'stato economico a oggi','stato_economico','valore a oggi','valore','consuntivato',
    'margine a oggi','margine','residuo a oggi','residuo','fido su valore','fido su costi',
    'Fatt. freq. (mesi)','Prima fatt.',
];
$rowToStd = function(array $r): array {
    $num = fn($v) => ($v === null || $v === '') ? '' : (float)$v;
    $int = fn($v) => (int)$v;
    $dt  = fn($v) => $v ? date('d/m/Y', strtotime($v)) : '';
    $cli = trim((string)($r['client_name'] ?? '') ?: (string)($r['client_raw'] ?? ''));
    return [
        (string)($r['abbr'] ?? ''),
        (string)($r['commercial_ref'] ?? ''),
        (string)($r['external_link'] ?? ''),
        (string)($r['service_line'] ?? ''),
        (string)($r['project_code'] ?? ''),
        (string)($r['name'] ?? ''),
        $cli,
        (string)($r['description'] ?? ''),
        (string)($r['internal_description'] ?? ''),
        (string)($r['operational_status'] ?? ''),
        $int($r['compliance_to_verify'] ?? 0),
        $int($r['compliance_preauth'] ?? 0),
        $dt($r['start_date'] ?? null),
        $dt($r['end_date'] ?? null),
        $int($r['anomalies_open'] ?? 0),
        $int($r['anomalies_blocking'] ?? 0),
        (string)($r['economic_status_todate'] ?? ''),
        (string)($r['economic_status'] ?? ''),
        $num($r['value_todate'] ?? null),
        $num($r['value_total'] ?? null),
        $num($r['actual_cost'] ?? null),
        $num($r['margin_todate'] ?? null),
        $num($r['margin_total'] ?? null),
        $num($r['residual_todate'] ?? null),
        $num($r['residual_total'] ?? null),
        $num($r['credit_on_value'] ?? null),
        $num($r['credit_on_costs'] ?? null),
        ($r['billing_freq_months'] ?? '') !== '' ? (int)$r['billing_freq_months'] : 0,
        $dt($r['first_billing_date'] ?? null),
    ];
};

// ── Esportazione XLSX / CSV (rispetta i filtri applicati) ────────────────────
$fmt = strtolower(trim((string)($_GET['export'] ?? '')));
if ($fmt === 'xlsx' || $fmt === 'csv') {
    // Nessun output spurio prima del binario, altrimenti il file arriva corrotto.
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');

    $data = [$STD_HEADERS];
    foreach ($rows as $r) $data[] = $rowToStd($r);
    $stamp = date('Ymd_Hi');
    write_log('Projects', 'info', "Export lista_commesse ($fmt): " . count($rows) . " righe", $u_id);

    if ($fmt === 'xlsx') {
        require_once(__DIR__ . '/XlsxWriter.php');
        $w = new XlsxWriter();
        $w->addSheet('Lista commesse', $data);
        $w->download("lista_commesse_$stamp.xlsx");
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"lista_commesse_$stamp.csv\"");
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");                     // BOM: accenti corretti in Excel
    foreach ($data as $line) fputcsv($fh, $line, ';', '"');
    fclose($fh);
    exit;
}

$msg = ''; $reopen_new = false;
if (!empty($_SESSION['flash_msg']))  { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (!empty($_SESSION['reopen_new'])) { $reopen_new = true; unset($_SESSION['reopen_new']); }

require_once('header.php');

$eur = fn($v) => $v === null || $v === '' ? '—' : number_format((float)$v, 2, ',', '.') . ' €';
$badge = fn($op) => match(strtoupper((string)$op)) {
    'APERTA' => '#16a34a', 'SOSPESA' => '#f59e0b', 'CHIUSA' => '#64748b', 'BOZZA' => '#94a3b8', default => '#334155'
};
$econBadge = fn($v) => match(strtoupper((string)$v)) {
    'OK' => '#16a34a', 'WARN', 'CRITICO' => '#f59e0b', 'KO', 'SFORATO' => '#dc2626', default => '#64748b'
};

// ── Conteggio dei filtri attivi ─────────────────────────────────────────────
// Serve a due cose: mostrare quanti sono nel titolo del pannello e decidere se
// aprirlo. Un elenco filtrato con il pannello chiuso sembrerebbe incompleto.
$active_filters = 0;
foreach ($f as $k => $v) {
    if ($k === 'sort') continue;
    if ($v === '' || $v === 0 || $v === null) continue;
    $active_filters++;
}
$active = $active_filters > 0;

// costruzione degli URL preservando i filtri correnti
$qs = function(array $over = []) use ($f) {
    $map = [
        'q'=>$f['q'], 'abbr'=>$f['abbr'], 'cref'=>$f['commercial_ref'], 'cliente'=>$f['client_raw'],
        'descr'=>$f['descr'], 'client'=>$f['client_id'], 'company'=>$f['company_id'],
        'sl'=>$f['service_line'], 'type'=>$f['type'], 'link'=>$f['has_link'], 'dgb'=>$f['has_dgb'],
        'status'=>$f['status'], 'commercial'=>$f['commercial'], 'econ'=>$f['econ'], 'econ_today'=>$f['econ_today'],
        'cverify'=>$f['compliance_to_verify'], 'cpre'=>$f['compliance_preauth'],
        'aopen'=>$f['anom_open']?1:'', 'ablocking'=>$f['anom_blocking']?1:'',
        'from'=>$f['from'], 'to'=>$f['to'], 'end_from'=>$f['end_from'], 'end_to'=>$f['end_to'],
        'no_end'=>$f['no_end']?1:'', 'ending'=>$f['ending_days'],
        'vmin'=>$f['value_min'], 'vmax'=>$f['value_max'], 'mmin'=>$f['margin_min'], 'mmax'=>$f['margin_max'],
        'rmin'=>$f['residual_min'], 'rmax'=>$f['residual_max'], 'kmin'=>$f['cost_min'], 'kmax'=>$f['cost_max'],
        'mneg'=>$f['margin_neg']?1:'', 'fido'=>$f['overdraft']?1:'',
        'bfreq'=>$f['bill_freq'], 'bfrom'=>$f['bill_from'], 'bto'=>$f['bill_to'], 'batch'=>$f['batch'],
        'sort'=>$f['sort'] !== 'code' ? $f['sort'] : '',
    ];
    $map = array_filter($map, fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return url_safe('manage_projects', array_merge($map, $over));
};

$total_projects = (int)$pdo->query("SELECT COUNT(*) FROM cm_projects")->fetchColumn();
?>
<style>
/* v1.8.47: pannelli a scomparsa basati su <details>, senza dipendenze JavaScript */
.pm-panel { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; overflow:hidden }
.pm-panel > summary {
  list-style:none; cursor:pointer; padding:11px 14px; font-weight:700; font-size:13px;
  display:flex; align-items:center; gap:9px; background:#f8fafc;
  border-bottom:1px solid transparent; user-select:none;
}
.pm-panel > summary::-webkit-details-marker { display:none }
.pm-panel > summary:hover { background:#f1f5f9 }
.pm-panel[open] > summary { border-bottom-color:#e2e8f0 }
.pm-panel > summary .pm-chev { transition:transform .15s ease; color:var(--muted); font-size:11px }
.pm-panel[open] > summary .pm-chev { transform:rotate(90deg) }
.pm-panel-body { padding:14px }
.pm-badge { background:#3b82f6; color:#fff; border-radius:10px; padding:1px 8px; font-size:11px; font-weight:700 }
.pm-hint { font-weight:400; color:var(--muted); font-size:11px; margin-left:auto }
.pm-group { margin-bottom:14px }
.pm-group > h4 {
  margin:0 0 8px; font-size:11px; text-transform:uppercase; letter-spacing:.5px;
  color:#64748b; font-weight:800; border-bottom:1px solid #f1f5f9; padding-bottom:4px;
}
.pm-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px }
.pm-grid .form-group { margin:0 }
.pm-grid label { font-size:11px; color:#475569; font-weight:600 }
.pm-checks { display:flex; gap:16px; flex-wrap:wrap; font-size:12px; align-items:center; padding-top:8px }
.pm-checks label { display:flex; gap:6px; align-items:center; cursor:pointer; font-weight:500 }
.pm-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:14px }
.pm-count { color:var(--muted); font-size:12px; margin-left:auto }
@media (max-width:900px) { .pm-grid { grid-template-columns:repeat(2,1fr) } }
@media print { .pm-panel, .pm-toolbar { display:none } }
</style>

<div class="page-header">
  <h1><i class="fa-solid fa-briefcase"></i> Commesse / Progetti</h1>
</div>
<?= $msg ?>

<div class="pm-toolbar">
  <?php if ($can_create): ?>
    <button type="button" class="btn btn-primary btn-sm" onclick="
        var d=document.getElementById('panelNew'); d.open=!d.open;
        if(d.open) d.scrollIntoView({behavior:'smooth',block:'nearest'});">
      <i class="fa-solid fa-plus"></i> Nuova commessa
    </button>
  <?php endif; ?>
  <a class="btn btn-success btn-sm" href="<?=$qs(['export'=>'xlsx'])?>"
     title="Scarica l'elenco filtrato in Excel, 29 colonne">
    <i class="fa-solid fa-file-excel"></i> Esporta XLSX</a>
  <a class="btn btn-sm" href="<?=$qs(['export'=>'csv'])?>"
     title="Scarica l'elenco filtrato in CSV, separatore punto e virgola">
    <i class="fa-solid fa-file-csv"></i> Esporta CSV</a>
  <span class="pm-count">
    <strong><?=count($rows)?></strong> di <strong><?=$total_projects?></strong> commesse<?=$active?' (filtrate)':''?>
  </span>
</div>

<?php if ($can_create): ?>
<details class="pm-panel" id="panelNew" <?= $reopen_new ? 'open' : '' ?>>
  <summary>
    <i class="fa-solid fa-chevron-right pm-chev"></i>
    <i class="fa-solid fa-plus" style="color:#3b82f6"></i>
    Inserisci una nuova commessa
    <span class="pm-hint">i valori economici si compilano poi dalla scheda o dall'import</span>
  </summary>
  <div class="pm-panel-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">

      <div class="pm-group">
        <h4>Identificazione</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Codice commessa *</label>
            <input type="text" name="project_code" required placeholder="Es. WTS_3016">
            <small style="color:var(--muted);font-size:10px">Il prefisso determina l'azienda esecutrice</small></div>
          <div class="form-group"><label>Denominazione *</label>
            <input type="text" name="name" required placeholder="Nome della commessa"></div>
          <div class="form-group"><label>Sigla commerciale</label>
            <input type="text" name="abbr" maxlength="20" placeholder="Es. ALGA"></div>
          <div class="form-group"><label>Commerciale di riferimento</label>
            <input type="text" name="commercial_ref" maxlength="120" placeholder="Cognome Nome"></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Classificazione e cliente</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Linea di servizio</label>
            <input type="text" name="service_line" list="sl_dl" placeholder="Es. WTS-PRES">
            <datalist id="sl_dl"><?php foreach($service_lines as $s):?><option value="<?=h($s)?>"><?php endforeach;?></datalist></div>
          <div class="form-group"><label>Tipologia contrattuale</label>
            <select name="project_type"><option value="">— non specificata —</option>
              <?php foreach($proj_types as $s):?><option><?=h($s)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Cliente</label>
            <input type="text" name="client_raw" list="clients_dl" placeholder="Ragione sociale">
            <datalist id="clients_dl"><?php foreach($clients as $c):?><option value="<?=h($c['name'])?>"><?php endforeach;?></datalist></div>
          <div class="form-group"><label>Collegamento al gestionale</label>
            <input type="url" name="external_link" placeholder="https://…"></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Stato e periodo</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Stato operativo</label>
            <select name="operational_status">
              <option>APERTA</option><option>SOSPESA</option><option>CHIUSA</option><option>BOZZA</option></select></div>
          <div class="form-group"><label>Stato commerciale</label>
            <select name="commercial_status"><option value="">— non specificato —</option>
              <?php foreach($comm_statuses as $s):?><option><?=h($s)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Data inizio</label><input type="date" name="start_date"></div>
          <div class="form-group"><label>Data fine</label><input type="date" name="end_date"></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Valori e descrizioni</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Valore di contratto (€)</label>
            <input type="number" step="0.01" name="value_total" placeholder="0.00"></div>
          <div class="form-group"><label>Costi materiali (€)</label>
            <input type="number" step="0.01" name="material_costs" placeholder="0.00"></div>
          <div class="form-group" style="grid-column:span 2"><label>Descrizione (visibile)</label>
            <input type="text" name="description" placeholder="Oggetto della commessa"></div>
          <div class="form-group" style="grid-column:span 4"><label>Descrizione interna (riservata)</label>
            <input type="text" name="internal_description" placeholder="Note non esposte al cliente"></div>
        </div>
      </div>

      <div style="display:flex;gap:8px;align-items:center;border-top:1px solid #f1f5f9;padding-top:12px">
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Crea e apri la scheda</button>
        <button type="button" class="btn btn-sm"
                onclick="document.getElementById('panelNew').open=false">Annulla</button>
        <span style="color:var(--muted);font-size:11px;margin-left:auto">* campi obbligatori</span>
      </div>
    </form>
  </div>
</details>
<?php endif; ?>

<details class="pm-panel" id="panelFilters" <?= $active ? 'open' : '' ?>>
  <summary>
    <i class="fa-solid fa-chevron-right pm-chev"></i>
    <i class="fa-solid fa-filter" style="color:#3b82f6"></i>
    Filtri di ricerca
    <?php if ($active): ?>
      <span class="pm-badge"><?=$active_filters?> attiv<?=$active_filters==1?'o':'i'?></span>
    <?php endif; ?>
    <span class="pm-hint">ogni colonna dell'elenco è filtrabile</span>
  </summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?>

      <div class="pm-group">
        <h4>Ricerca libera e anagrafica</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Cerca ovunque</label>
            <input type="text" name="q" value="<?=h($f['q'])?>" placeholder="codice, nome, sigla, descrizioni"></div>
          <div class="form-group"><label>Sigla commerciale</label>
            <input type="text" name="abbr" value="<?=h($f['abbr'])?>" placeholder="Es. ALGA"></div>
          <div class="form-group"><label>Commerciale di riferimento</label>
            <input type="text" name="cref" value="<?=h($f['commercial_ref'])?>" placeholder="cognome o nome"></div>
          <div class="form-group"><label>Cliente (ricerca testuale)</label>
            <input type="text" name="cliente" value="<?=h($f['client_raw'])?>" placeholder="parte della ragione sociale"></div>
          <div class="form-group"><label>Cliente in anagrafica</label>
            <select name="client"><option value="">— tutti —</option>
              <?php foreach($clients as $c):?><option value="<?=(int)$c['id']?>" <?=$f['client_id']===(int)$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Azienda esecutrice</label>
            <select name="company"><option value="">— tutte —</option>
              <?php foreach($companies as $id=>$n):?><option value="<?=(int)$id?>" <?=$f['company_id']===(int)$id?'selected':''?>><?=h($n)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Testo nelle descrizioni</label>
            <input type="text" name="descr" value="<?=h($f['descr'])?>" placeholder="visibile o interna"></div>
          <div class="form-group"><label>Linea di servizio</label>
            <select name="sl"><option value="">— tutte —</option>
              <?php foreach($service_lines as $s):?><option value="<?=h($s)?>" <?=$f['service_line']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Stato, compliance e anomalie</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Stato operativo</label>
            <select name="status"><option value="">— tutti —</option>
              <?php foreach($op_statuses as $s):?><option value="<?=h($s)?>" <?=$f['status']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Stato commerciale</label>
            <select name="commercial"><option value="">— tutti —</option>
              <?php foreach($comm_statuses as $s):?><option value="<?=h($s)?>" <?=$f['commercial']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Tipologia contrattuale</label>
            <select name="type"><option value="">— tutte —</option>
              <?php foreach($proj_types as $s):?><option value="<?=h($s)?>" <?=$f['type']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Stato economico (intero contratto)</label>
            <select name="econ"><option value="">— tutti —</option>
              <?php foreach($econ_statuses as $s):?><option value="<?=h($s)?>" <?=$f['econ']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Stato economico (a oggi)</label>
            <select name="econ_today"><option value="">— tutti —</option>
              <?php foreach($econ_today as $s):?><option value="<?=h($s)?>" <?=$f['econ_today']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></div>
          <div class="form-group"><label>Compliance da verificare</label>
            <select name="cverify"><option value="">— indifferente —</option>
              <option value="1" <?=$f['compliance_to_verify']==='1'?'selected':''?>>Sì, da verificare</option>
              <option value="0" <?=$f['compliance_to_verify']==='0'?'selected':''?>>No</option></select></div>
          <div class="form-group"><label>Compliance pre autorizzata</label>
            <select name="cpre"><option value="">— indifferente —</option>
              <option value="1" <?=$f['compliance_preauth']==='1'?'selected':''?>>Sì, autorizzata</option>
              <option value="0" <?=$f['compliance_preauth']==='0'?'selected':''?>>No</option></select></div>
          <div class="form-group"><label>Collegamento al gestionale</label>
            <select name="link"><option value="">— indifferente —</option>
              <option value="1" <?=$f['has_link']==='1'?'selected':''?>>Presente</option>
              <option value="0" <?=$f['has_link']==='0'?'selected':''?>>Assente</option></select></div>
        </div>
        <div class="pm-checks">
          <label><input type="checkbox" name="aopen" value="1" <?=$f['anom_open']?'checked':''?>> Solo con anomalie aperte</label>
          <label><input type="checkbox" name="ablocking" value="1" <?=$f['anom_blocking']?'checked':''?>> Solo con anomalie bloccanti</label>
          <label><input type="checkbox" name="mneg" value="1" <?=$f['margin_neg']?'checked':''?>> Solo in perdita (margine negativo)</label>
          <label><input type="checkbox" name="fido" value="1" <?=$f['overdraft']?'checked':''?>> Solo con fido superato</label>
        </div>
      </div>

      <div class="pm-group">
        <h4>Periodo</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Inizio: dal</label><input type="date" name="from" value="<?=h($f['from'])?>"></div>
          <div class="form-group"><label>Inizio: al</label><input type="date" name="to" value="<?=h($f['to'])?>"></div>
          <div class="form-group"><label>Fine: dal</label><input type="date" name="end_from" value="<?=h($f['end_from'])?>"></div>
          <div class="form-group"><label>Fine: al</label><input type="date" name="end_to" value="<?=h($f['end_to'])?>"></div>
          <div class="form-group"><label>In scadenza entro (giorni)</label>
            <input type="number" name="ending" min="0" max="3650" value="<?=$f['ending_days'] ?: ''?>" placeholder="Es. 60"></div>
          <div class="form-group" style="display:flex;align-items:flex-end">
            <label style="display:flex;gap:6px;align-items:center;font-weight:500;padding-bottom:8px;font-size:12px">
              <input type="checkbox" name="no_end" value="1" <?=$f['no_end']?'checked':''?>> Senza data di fine</label></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Importi (€)</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Valore: da</label><input type="number" step="0.01" name="vmin" value="<?=h($f['value_min'])?>"></div>
          <div class="form-group"><label>Valore: a</label><input type="number" step="0.01" name="vmax" value="<?=h($f['value_max'])?>"></div>
          <div class="form-group"><label>Margine: da</label><input type="number" step="0.01" name="mmin" value="<?=h($f['margin_min'])?>"></div>
          <div class="form-group"><label>Margine: a</label><input type="number" step="0.01" name="mmax" value="<?=h($f['margin_max'])?>"></div>
          <div class="form-group"><label>Residuo: da</label><input type="number" step="0.01" name="rmin" value="<?=h($f['residual_min'])?>"></div>
          <div class="form-group"><label>Residuo: a</label><input type="number" step="0.01" name="rmax" value="<?=h($f['residual_max'])?>"></div>
          <div class="form-group"><label>Consuntivato: da</label><input type="number" step="0.01" name="kmin" value="<?=h($f['cost_min'])?>"></div>
          <div class="form-group"><label>Consuntivato: a</label><input type="number" step="0.01" name="kmax" value="<?=h($f['cost_max'])?>"></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Fatturazione, provenienza e ordinamento</h4>
        <div class="pm-grid">
          <div class="form-group"><label>Frequenza fatturazione</label>
            <select name="bfreq"><option value="">— tutte —</option>
              <?php foreach($bill_freqs as $b):?><option value="<?=(int)$b?>" <?=$f['bill_freq']===(int)$b?'selected':''?>>ogni <?=(int)$b?> mesi</option><?php endforeach;?></select></div>
          <div class="form-group"><label>Prima fattura: dal</label><input type="date" name="bfrom" value="<?=h($f['bill_from'])?>"></div>
          <div class="form-group"><label>Prima fattura: al</label><input type="date" name="bto" value="<?=h($f['bill_to'])?>"></div>
          <div class="form-group"><label>Riconciliata col gestionale</label>
            <select name="dgb"><option value="">— indifferente —</option>
              <option value="1" <?=$f['has_dgb']==='1'?'selected':''?>>Sì</option>
              <option value="0" <?=$f['has_dgb']==='0'?'selected':''?>>No</option></select></div>
          <div class="form-group"><label>Batch di import (numero)</label>
            <input type="number" name="batch" min="0" value="<?=$f['batch'] ?: ''?>" placeholder="Es. 12"></div>
          <div class="form-group" style="grid-column:span 3"><label>Ordina l'elenco per</label>
            <select name="sort">
              <option value="code"          <?=$f['sort']==='code'?'selected':''?>>Codice commessa (A→Z)</option>
              <option value="code_desc"     <?=$f['sort']==='code_desc'?'selected':''?>>Codice commessa (Z→A)</option>
              <option value="name"          <?=$f['sort']==='name'?'selected':''?>>Denominazione (A→Z)</option>
              <option value="client"        <?=$f['sort']==='client'?'selected':''?>>Cliente (A→Z)</option>
              <option value="value_desc"    <?=$f['sort']==='value_desc'?'selected':''?>>Valore (dal più alto)</option>
              <option value="value_asc"     <?=$f['sort']==='value_asc'?'selected':''?>>Valore (dal più basso)</option>
              <option value="margin_desc"   <?=$f['sort']==='margin_desc'?'selected':''?>>Margine (dal più alto)</option>
              <option value="margin_asc"    <?=$f['sort']==='margin_asc'?'selected':''?>>Margine (dal più basso)</option>
              <option value="residual_desc" <?=$f['sort']==='residual_desc'?'selected':''?>>Residuo (dal più alto)</option>
              <option value="cost_desc"     <?=$f['sort']==='cost_desc'?'selected':''?>>Consuntivato (dal più alto)</option>
              <option value="start_desc"    <?=$f['sort']==='start_desc'?'selected':''?>>Data inizio (più recenti)</option>
              <option value="start_asc"     <?=$f['sort']==='start_asc'?'selected':''?>>Data inizio (più remote)</option>
              <option value="end_asc"       <?=$f['sort']==='end_asc'?'selected':''?>>Data fine (prime in scadenza)</option>
              <option value="anom_desc"     <?=$f['sort']==='anom_desc'?'selected':''?>>Anomalie (dalle più critiche)</option>
            </select></div>
        </div>
      </div>

      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid #f1f5f9;padding-top:12px">
        <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Applica i filtri</button>
        <?php if($active): ?>
          <a class="btn btn-sm" href="<?=url_safe('manage_projects')?>"><i class="fa-solid fa-eraser"></i> Azzera tutti</a>
        <?php endif; ?>
        <span class="pm-count">
          <strong><?=count($rows)?></strong> di <strong><?=$total_projects?></strong> commesse<?=$active?' (filtrate)':''?>
        </span>
        <a class="btn btn-success btn-sm" href="<?=$qs(['export'=>'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a>
        <a class="btn btn-sm" href="<?=$qs(['export'=>'csv'])?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
      </div>
    </form>
  </div>
</details>

<div class="card" style="overflow-x:auto">
  <table class="data-table" style="width:100%;font-size:12px;white-space:nowrap;min-width:2400px">
    <thead><tr>
      <th title="Sigla del commerciale">abbr</th>
      <th title="Commerciale di riferimento">commerciale</th>
      <th title="Collegamento al gestionale">link</th>
      <th title="Linea di servizio">tipo</th>
      <th>codice_commessa</th>
      <th title="Denominazione della commessa">commessa</th>
      <th>cliente</th>
      <th>descrizione</th>
      <th title="Descrizione riservata, non esposta al cliente">descrizione interna</th>
      <th title="Stato operativo">stato</th>
      <th title="Compliance da verificare">compl. da verif.</th>
      <th title="Compliance pre autorizzata">compl. pre aut.</th>
      <th>data inizio</th>
      <th>data fine</th>
      <th style="text-align:right" title="Anomalie aperte">anom. aperte</th>
      <th style="text-align:right" title="Anomalie bloccanti">anom. bloccanti</th>
      <th title="Stato economico alla data odierna">stato econ. a oggi</th>
      <th title="Stato economico sull'intero contratto">stato_economico</th>
      <th style="text-align:right" title="Quota di valore maturata a oggi">valore a oggi</th>
      <th style="text-align:right" title="Valore contrattuale complessivo">valore</th>
      <th style="text-align:right" title="Costi effettivi sostenuti">consuntivato</th>
      <th style="text-align:right" title="Margine maturato a oggi">margine a oggi</th>
      <th style="text-align:right" title="Margine sull'intero contratto">margine</th>
      <th style="text-align:right">residuo a oggi</th>
      <th style="text-align:right">residuo</th>
      <th style="text-align:right" title="Sforamento sul valore">fido su valore</th>
      <th style="text-align:right" title="Sforamento sui costi">fido su costi</th>
      <th style="text-align:right" title="Frequenza di fatturazione in mesi">Fatt. freq. (mesi)</th>
      <th title="Data della prima fattura">Prima fatt.</th>
      <th title="Attività e ore consuntivate sul gestionale">DGB att/ore</th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php if(!$rows): ?>
      <tr><td colspan="31" style="text-align:center;color:var(--muted);padding:24px">
        <?php if ($active): ?>
          Nessuna commessa corrisponde ai filtri impostati.
          <a href="<?=url_safe('manage_projects')?>">Azzera i filtri</a> per vedere l'elenco completo.
        <?php else: ?>
          Nessuna commessa in archivio.
        <?php endif; ?>
      </td></tr>
    <?php else: foreach ($rows as $r):
        $cli = trim((string)($r['client_name'] ?? '') ?: (string)($r['client_raw'] ?? ''));
    ?>
      <tr>
        <td style="font-weight:600"><?=h($r['abbr'] ?? '')?></td>
        <td><?=h($r['commercial_ref'] ?? '')?></td>
        <td><?php if(!empty($r['external_link'])): ?><a href="<?=h($r['external_link'])?>" target="_blank" rel="noopener" title="Apri sul gestionale"><i class="fa-solid fa-arrow-up-right-from-square"></i></a><?php else: ?>—<?php endif; ?></td>
        <td><?=h($r['service_line'] ?? '—')?></td>
        <td style="font-weight:600"><?=h($r['project_code'])?></td>
        <td title="<?=h((string)$r['name'])?>"><?=h(mb_strimwidth((string)$r['name'],0,42,'…'))?></td>
        <td><?=h($cli ?: '—')?></td>
        <td title="<?=h((string)($r['description'] ?? ''))?>"><?=h(mb_strimwidth((string)($r['description'] ?? ''),0,36,'…'))?></td>
        <td title="<?=h((string)($r['internal_description'] ?? ''))?>"><?=h(mb_strimwidth((string)($r['internal_description'] ?? ''),0,36,'…'))?></td>
        <td><span style="color:<?=$badge($r['operational_status'] ?? '')?>;font-weight:600"><?=h($r['operational_status'] ?? '—')?></span></td>
        <td style="text-align:center"><?= (int)($r['compliance_to_verify'] ?? 0) ? '<i class="fa-solid fa-circle-check" style="color:#dc2626" title="Da verificare"></i>' : '—' ?></td>
        <td style="text-align:center"><?= (int)($r['compliance_preauth'] ?? 0) ? '<i class="fa-solid fa-circle-check" style="color:#16a34a" title="Pre autorizzata"></i>' : '—' ?></td>
        <td><?= $r['start_date'] ? date('d/m/Y', strtotime($r['start_date'])) : '—' ?></td>
        <td><?= $r['end_date']   ? date('d/m/Y', strtotime($r['end_date']))   : '—' ?></td>
        <td style="text-align:right"><?= (int)($r['anomalies_open'] ?? 0) ?></td>
        <td style="text-align:right"><?php $ab=(int)($r['anomalies_blocking'] ?? 0); ?><span style="color:<?=$ab>0?'#dc2626':'inherit'?>;font-weight:<?=$ab>0?'600':'400'?>"><?=$ab?></span></td>
        <td><?php $et=(string)($r['economic_status_todate'] ?? ''); ?><span style="color:<?=$econBadge($et)?>;font-weight:600"><?=h($et?:'—')?></span></td>
        <td><?php $ee=(string)($r['economic_status'] ?? ''); ?><span style="color:<?=$econBadge($ee)?>;font-weight:600"><?=h($ee?:'—')?></span></td>
        <td style="text-align:right"><?=$eur($r['value_todate'] ?? null)?></td>
        <td style="text-align:right"><?=$eur($r['value_total'] ?? null)?></td>
        <td style="text-align:right"><?=$eur($r['actual_cost'] ?? null)?></td>
        <td style="text-align:right"><?=$eur($r['margin_todate'] ?? null)?></td>
        <td style="text-align:right"><?php $mt=$r['margin_total'] ?? null; ?><span style="color:<?=($mt!==null&&(float)$mt<0)?'#dc2626':'inherit'?>"><?=$eur($mt)?></span></td>
        <td style="text-align:right"><?=$eur($r['residual_todate'] ?? null)?></td>
        <td style="text-align:right"><?=$eur($r['residual_total'] ?? null)?></td>
        <td style="text-align:right"><?=$eur($r['credit_on_value'] ?? null)?></td>
        <td style="text-align:right"><?=$eur($r['credit_on_costs'] ?? null)?></td>
        <td style="text-align:right"><?= ($r['billing_freq_months'] ?? '') !== '' ? (int)$r['billing_freq_months'] : '—' ?></td>
        <td><?= $r['first_billing_date'] ? date('d/m/Y', strtotime($r['first_billing_date'])) : '—' ?></td>
        <td style="text-align:right"><?php if(isset($dgb_roll[(int)$r['id']])): $dr=$dgb_roll[(int)$r['id']]; ?><span style="color:#0891b2;font-weight:600" title="Attività DGB / ore consuntivate"><?=number_format((int)$dr['activities'],0,',','.')?> / <?=number_format((float)$dr['actual_hours'],0,',','.')?>h</span><?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?></td>
        <td><a class="btn btn-sm btn-blue" href="<?=url_safe('project_dashboard', ['id'=>(int)$r['id']])?>" title="Apri la scheda della commessa"><i class="fa-solid fa-chart-line"></i></a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    Colonne dello standard "Lista commesse" (29). L'esportazione riporta gli stessi header e rispetta i filtri
    applicati; il CSV usa il punto e virgola come separatore ed è in UTF-8 con BOM, quindi si apre correttamente
    in Excel italiano.
  </p>
</div>
<?php require_once('footer.php'); ?>
