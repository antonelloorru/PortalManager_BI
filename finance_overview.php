<?php
/**
 * finance_overview.php — Sezione Finance (v1.8.0, era v1.7.98)
 *
 * Quadro del personale per il controllo di gestione con vista personalizzabile.
 *
 * v1.8.0 — I dati economici hanno validità ANNUALE: la vista opera su un anno di
 * competenza selezionabile. I valori economici (RAL, FullCost, ValoreFTE, ...)
 * e i parametri di riferimento sono relativi all'anno scelto (hr_employee_economics
 * + hr_reference_values per-anno). Le colonne anagrafico-contrattuali restano dal
 * record del dipendente. Il confronto tra annualità è in finance_compare.php.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/XlsxWriter.php');
require_once(__DIR__ . '/app/CostModel.php');

if (!can('view', 'finance_overview.php')) { redirect('dashboard'); }
$u_id        = (int)$_SESSION['user_id'];
$can_profile = can('view', 'employee_profile.php');
$can_hr      = can('view', 'employee_compensation.php') || can('view', 'manage_employees_compensation.php');
$can_export  = can('export', 'finance_overview.php');

$cm          = new CostModel($pdo);
$cur_year    = $cm->currentYear();
$year        = $cm->resolveYear($_GET['year'] ?? 0);
$year_labels = $cm->years();

/* ── Registro colonne ──────────────────────────────────────────────────────── */
function fin_registry(PDO $pdo): array
{
    try {
        $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $cols = []; }
    $has = fn(string $c) => in_array($c, $cols, true);
    $col = fn(string $c, string $fb = "''") => $has($c) ? "e.`$c`" : $fb;

    $mail = $has('business_email') ? "e.`business_email`" : ($has('work_email') ? "e.`work_email`" : $col('email'));
    $qual = $has('qualification') && $has('job_title')
        ? "COALESCE(NULLIF(e.`qualification`,''), e.`job_title`)"
        : ($has('qualification') ? "e.`qualification`" : $col('job_title'));
    $dept = $has('department_id') && $has('department')
        ? "COALESCE(d.`name`, NULLIF(e.`department`,''))"
        : ($has('department_id') ? "d.`name`" : $col('department'));
    $azienda = $has('agency') ? "COALESCE(NULLIF(e.`agency`,''), c.`name`)" : "c.`name`";

    return [
        // anagrafiche / contrattuali (dal record dipendente)
        'cognome'      => ['Cognome',                     'sql', $col('last_name'),  false],
        'nome'         => ['Nome',                        'sql', $col('first_name'), false],
        'cf'           => ['Codice fiscale',              'sql', $col('fiscal_code'), false],
        'email_az'     => ['Email aziendale',             'sql', $mail,              false],
        'azienda'      => ['Azienda o Agenzia',           'sql', $azienda,           false],
        'sede'         => ['Sede',                        'sql', 'l.`location_name`', false],
        'dipartimento' => ['Dipartimento',                'sql', $dept,              false],
        'qualifica'    => ['Qualifica/Ruolo',             'sql', $qual,              false],
        'rapporto'     => ['Tipo di rapporto',            'sql', $col('contract_type'), false],
        'stato'        => ['Stato',                       'sql', $col('status'),     false],
        'assunzione'   => ['Data assunzione',             'sql', $col('hire_date','NULL'), false],
        'fine'         => ['Data fine',                   'sql', $col('end_date','NULL'),  false],
        'class_fin'    => ['Classificazione finanziaria', 'sql', 'ECON:classificazione_finanziaria', false],
        // economiche (riservate HR, per anno di competenza)
        'ral'         => ['RAL',                    'sql',  'ECON:ral',                          true],
        'full_cost'   => ['FullCost',               'calc', 'full_cost',                         true],
        'valore_tabp' => ['ValoreTABP',             'used', 'valore_tabp',                       true],
        'qt_trasf'    => ['Qt. Trasferte Annue',    'sql',  'ECON:qt_trasferte_annue',           true],
        'qt_bp'       => ['Qt. Buoni Pasto',        'sql',  'ECON:qt_buoni_pasto',               true],
        'tot_ta_bp'   => ['TotAAxTA+BP',            'calc', 'tot_aa_ta_bp',                      true],
        'km'          => ['Km concordati (annui)',  'sql',  'ECON:km_concordati',                true],
        'val_km'      => ['Val.KM',                 'used', 'val_km',                            true],
        'rimborso_km' => ['Rimborso KM',            'calc', 'rimborso_km',                       true],
        'incentivo'   => ['Incentivazione Extra',   'sql',  'ECON:incentivazione_extra',         true],
        'auto'        => ['Valore Medio anno Auto', 'sql',  'ECON:valore_medio_anno_auto',       true],
        'pre_ovh'     => ['TotalePreOverHead',      'calc', 'totale_pre_overhead',               true],
        'overhead'    => ['OverHead Aziendale',     'used', 'overhead_aziendale',                true],
        'tot_costo'   => ['TotCostoTab',            'calc', 'tot_costo_tab',                     true],
        'no_auto'     => ['CostoNoAuto',            'calc', 'costo_no_auto',                     true],
        'valore_fte'  => ['ValoreFTE',              'calc', 'valore_fte',                        true],
        'tot_fte_ca'  => ['TotaleFTE+CA',           'calc', 'totale_fte_ca',                     true],
    ];
}

/** Colonne disponibili all'utente corrente (esclude le economiche se non autorizzato). */
function fin_available(PDO $pdo, bool $can_hr): array
{
    $out = [];
    foreach (fin_registry($pdo) as $k => $d) if (!$d[3] || $can_hr) $out[$k] = $d;
    return $out;
}

/** Preferenze utente: ordine completo, colonne visibili, modalità di export. */
function fin_prefs(PDO $pdo, int $userId, array $available): array
{
    $default = array_slice(array_keys($available), 0, 12);
    $order   = array_keys($available);
    $visible = $default;
    $mode    = 'tutti';
    try {
        $st = $pdo->prepare("SELECT columns_json, export_mode FROM finance_view_prefs WHERE user_id=?");
        $st->execute([$userId]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $mode = $row['export_mode'] ?: 'tutti';
            $j = json_decode((string)$row['columns_json'], true);
            if (is_array($j) && !empty($j['order'])) {
                $ord = array_values(array_intersect($j['order'], array_keys($available)));
                foreach (array_keys($available) as $k) if (!in_array($k, $ord, true)) $ord[] = $k;
                $order   = $ord;
                $visible = array_values(array_intersect((array)($j['visible'] ?? $default), array_keys($available)));
            }
        }
    } catch (Throwable $e) { /* tabella assente */ }
    if (!$visible) $visible = $default;
    return ['order' => $order, 'visible' => $visible, 'mode' => $mode];
}

function fin_where(array $f): array
{
    $w = ['1=1']; $a = [];
    if ($f['q'] !== '') {
        $w[] = "(e.last_name LIKE ? OR e.first_name LIKE ? OR e.business_email LIKE ? OR e.employee_code LIKE ?)";
        $like = '%' . $f['q'] . '%'; array_push($a, $like, $like, $like, $like);
    }
    if ($f['company'] > 0)   { $w[] = 'e.company_id = ?';      $a[] = $f['company']; }
    if ($f['agency'] !== '') { $w[] = 'e.agency = ?';          $a[] = $f['agency']; }
    if ($f['dept'] !== '')   { $w[] = 'COALESCE(d.name, e.department) = ?'; $a[] = $f['dept']; }
    if ($f['ct'] !== '')     { $w[] = 'e.contract_type = ?';   $a[] = $f['ct']; }
    if ($f['cf'] !== '')     { $w[] = 'COALESCE(ee.classificazione_finanziaria, e.classificazione_finanziaria) = ?'; $a[] = $f['cf']; }
    if ($f['loc'] > 0)       { $w[] = 'e.location_id = ?';     $a[] = $f['loc']; }
    if ($f['stato'] === 'attivi')  { $w[] = "(e.end_date IS NULL OR e.end_date >= CURDATE())"; }
    if ($f['stato'] === 'cessati') { $w[] = "(e.end_date IS NOT NULL AND e.end_date < CURDATE())"; }
    if ($f['from'] !== '')   { $w[] = 'e.hire_date >= ?';      $a[] = $f['from']; }
    if ($f['to'] !== '')     { $w[] = 'e.hire_date <= ?';      $a[] = $f['to']; }
    return [implode(' AND ', $w), $a];
}

/** Righe per le colonne richieste; economiche per l'anno; i valori derivati arrivano da CostModel. */
function fin_rows(PDO $pdo, CostModel $cm, array $f, array $keys, array $registry, bool $can_hr, int $year, int $cur): array
{
    $isCur = ($year === $cur);
    // colonne economiche presenti nella tabella per-anno
    try {
        $eeCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hr_employee_economics'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $eeCols = []; }
    $econExpr = function (string $c) use ($eeCols, $isCur) {
        $inEe = in_array($c, $eeCols, true);
        if ($inEe && $isCur) return "COALESCE(ee.`$c`, e.`$c`)";
        if ($inEe)           return "ee.`$c`";
        return "e.`$c`"; // schema senza tabella per-anno: retrocompatibilità
    };

    $needCost = false;
    foreach ($keys as $k) if (in_array(($registry[$k][1] ?? ''), ['calc','used'], true)) $needCost = true;

    $sel = ['e.id AS emp_id'];
    foreach ($keys as $i => $k) {
        $d = $registry[$k] ?? null;
        if ($d && $d[1] === 'sql') {
            $expr = $d[2];
            if (strncmp($expr, 'ECON:', 5) === 0) $expr = $econExpr(substr($expr, 5));
            $sel[] = $expr . " AS `k$i`";
        }
    }
    if ($needCost && $can_hr) {
        foreach (['ral','moltiplicatore_fc','qt_trasferte_annue','qt_buoni_pasto','valore_tabp','km_concordati',
                  'val_km','incentivazione_extra','valore_medio_anno_auto','overhead_aziendale','moltiplicatore_fte'] as $c) {
            if (in_array($c, $eeCols, true) || $isCur) $sel[] = $econExpr($c) . " AS `src_$c`";
        }
    }
    [$where, $args] = fin_where($f);
    $sql = "SELECT " . implode(', ', $sel) . "
              FROM employees e
              LEFT JOIN companies c              ON c.id = e.company_id
              LEFT JOIN company_locations l      ON l.id = e.location_id
              LEFT JOIN departments d            ON d.id = e.department_id
              LEFT JOIN hr_employee_economics ee ON ee.employee_id = e.id AND ee.year = ?
             WHERE $where
             ORDER BY e.last_name, e.first_name";
    $st = $pdo->prepare($sql); $st->execute(array_merge([$year], $args));
    $raw = $st->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($raw as $r) {
        $vals = []; $cost = null;
        if ($needCost && $can_hr) {
            $emp = [];
            foreach ($r as $kk => $vv) if (strpos($kk, 'src_') === 0) $emp[substr($kk, 4)] = $vv;
            $cost = $cm->compute($emp, $year);
        }
        foreach ($keys as $i => $k) {
            $d = $registry[$k] ?? null;
            if (!$d) { $vals[$k] = null; continue; }
            if ($d[1] === 'sql')      $vals[$k] = $r["k$i"] ?? null;
            elseif ($d[1] === 'calc') $vals[$k] = $cost ? ($cost['calc'][$d[2]] ?? null) : null;
            elseif ($d[1] === 'used') $vals[$k] = $cost ? ($cost['used'][$d[2]]['v'] ?? null) : null;
        }
        $out[] = ['emp_id' => (int)$r['emp_id'], 'vals' => $vals];
    }
    return $out;
}

$registry  = fin_registry($pdo);
$available = fin_available($pdo, $can_hr);

/* ── Salvataggio / reset preferenze di vista ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_view') {
    Csrf::verify();
    $order = [];
    foreach ((array)($_POST['ord'] ?? []) as $k => $pos) if (isset($available[$k])) $order[$k] = (float)$pos;
    asort($order);
    $order = array_keys($order);
    foreach (array_keys($available) as $k) if (!in_array($k, $order, true)) $order[] = $k;
    $visible = array_values(array_intersect((array)($_POST['vis'] ?? []), array_keys($available)));
    $mode    = ($_POST['export_mode'] ?? 'tutti') === 'vista' ? 'vista' : 'tutti';
    try {
        $pdo->prepare("INSERT INTO finance_view_prefs (user_id, columns_json, export_mode) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE columns_json=VALUES(columns_json), export_mode=VALUES(export_mode)")
            ->execute([$u_id, json_encode(['order' => $order, 'visible' => $visible], JSON_UNESCAPED_UNICODE), $mode]);
        write_log('Finance', 'success', 'Vista Finance personalizzata: ' . count($visible) . ' colonne, export ' . $mode, $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Vista personalizzata salvata (" . count($visible) . " colonne).</div>";
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Impossibile salvare la vista: " . h($e->getMessage()) . "</div>";
    }
    redirect_self(['year' => $year]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_view') {
    Csrf::verify();
    try { $pdo->prepare("DELETE FROM finance_view_prefs WHERE user_id=?")->execute([$u_id]); } catch (Throwable $e) {}
    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Vista ripristinata ai valori predefiniti.</div>";
    redirect_self(['year' => $year]);
}

$prefs     = fin_prefs($pdo, $u_id, $available);
$view_keys = array_values(array_filter($prefs['order'], fn($k) => in_array($k, $prefs['visible'], true)));
if (!$view_keys) $view_keys = array_slice(array_keys($available), 0, 12);

$f = [
    'q'       => trim((string)($_GET['q'] ?? '')),
    'company' => (int)($_GET['company'] ?? 0),
    'agency'  => trim((string)($_GET['agency'] ?? '')),
    'dept'    => trim((string)($_GET['dept'] ?? '')),
    'ct'      => trim((string)($_GET['ct'] ?? '')),
    'cf'      => trim((string)($_GET['cf'] ?? '')),
    'loc'     => (int)($_GET['loc'] ?? 0),
    'stato'   => (string)($_GET['stato'] ?? 'tutti'),
    'from'    => trim((string)($_GET['from'] ?? '')),
    'to'      => trim((string)($_GET['to'] ?? '')),
];

/* ── Export ──────────────────────────────────────────────────────────────── */
$fmt = strtolower(trim((string)($_GET['export'] ?? '')));
if ($fmt === 'xlsx' || $fmt === 'csv') {
    if (!$can_export) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti per l'esportazione.</div>"; redirect_self(['year' => $year]); }
    $mode = in_array(($_GET['mode'] ?? ''), ['tutti','vista'], true) ? $_GET['mode'] : $prefs['mode'];
    $keys = ($mode === 'vista') ? $view_keys : array_keys($available);
    $rows = fin_rows($pdo, $cm, $f, $keys, $registry, $can_hr, $year, $cur_year);

    $out = [array_merge(['Anno di competenza'], array_map(fn($k) => $available[$k][0], $keys))];
    foreach ($rows as $r) $out[] = array_merge([(string)$year], array_map(fn($k) => ($r['vals'][$k] === null ? '' : (string)$r['vals'][$k]), $keys));

    $stamp = date('Ymd_Hi');
    write_log('Finance', 'success', "Export Finance ($fmt, $mode, esercizio $year): " . count($rows) . " dipendenti, " . count($keys) . " colonne", $u_id);
    if ($fmt === 'xlsx') { $w = new XlsxWriter(); $w->addSheet("Finance $year", $out); $w->download("finance_dipendenti_{$year}_$stamp.xlsx"); exit; }
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"finance_dipendenti_{$year}_$stamp.csv\"");
    $fh = fopen('php://output', 'w'); fwrite($fh, "\xEF\xBB\xBF");
    foreach ($out as $r) fputcsv($fh, $r, ';', '"');
    fclose($fh); exit;
}

$rows = fin_rows($pdo, $cm, $f, $view_keys, $registry, $can_hr, $year, $cur_year);
$tot  = count($rows);

$companies = $departments = $agencies = $locations = [];
try { $companies   = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
try { $locations   = $pdo->query("SELECT id, location_name FROM company_locations ORDER BY location_name")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
try { $agencies    = $pdo->query("SELECT DISTINCT agency FROM employees WHERE agency IS NOT NULL AND agency<>'' ORDER BY agency")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
try { $departments = $pdo->query("SELECT DISTINCT COALESCE(d.name, e.department) x FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE COALESCE(d.name, e.department) IS NOT NULL AND COALESCE(d.name, e.department)<>'' ORDER BY x")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$contract_types = ['Indeterminato','Determinato','Apprendistato','Interinale','Somministrazione','Consulenza','Stage','Partita IVA'];

require_once('header.php');
if (!empty($_SESSION['flash_msg'])) { echo $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$qs = function (array $o = []) use ($f, $year) {
    return url_safe('finance_overview', array_filter(array_merge($f, ['year' => $year], $o), fn($v) => $v !== '' && $v !== 0 && $v !== 'tutti'));
};
$isNum = fn($k) => in_array(($registry[$k][1] ?? ''), ['calc','used'], true) || in_array($k, ['ral','qt_trasf','qt_bp','km','incentivo','auto'], true);
$fmtv = function ($k, $v) use ($registry) {
    if ($v === null || $v === '') return '—';
    $kind = $registry[$k][1] ?? 'sql';
    if ($kind === 'calc') return number_format((float)$v, 2, ',', '.');
    if ($kind === 'used') return rtrim(rtrim(number_format((float)$v, 5, ',', '.'), '0'), ',');
    if (in_array($k, ['ral','incentivo','auto'], true)) return number_format((float)$v, 2, ',', '.');
    return (string)$v;
};
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div>
    <h1><i class="fa-solid fa-chart-pie" style="color:#0891b2"></i> Finance <span style="font-size:14px;color:var(--muted)">· esercizio <?= $year ?></span></h1>
    <p style="color:var(--muted);font-size:13px">Quadro del personale per il controllo di gestione, con vista personalizzabile ed esportazione, per anno di competenza. Accesso riservato a Responsabile Finanziario, HR e Amministratore.</p>
  </div>
  <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <form method="get" style="display:flex;gap:6px;align-items:flex-end">
      <?= route_slug_field() ?>
      <?php foreach ($f as $fk => $fv): if ($fv === '' || $fv === 0 || $fv === 'tutti') continue; ?><input type="hidden" name="<?=h($fk)?>" value="<?=h($fv)?>"><?php endforeach; ?>
      <div class="form-group" style="margin:0"><label style="font-size:11px">Anno di competenza</label>
        <select name="year" onchange="this.form.submit()" style="font-weight:700">
          <?php foreach ($year_labels as $y => $lbl): ?>
            <option value="<?=(int)$y?>" <?= $y === $year ? 'selected':'' ?>><?=(int)$y?><?= $y === $cur_year ? ' (corrente)' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
    </form>
    <?php if (can('view','finance_compare.php')): ?><a class="btn btn-sm" href="<?=url_safe('finance_compare')?>"><i class="fa-solid fa-scale-balanced"></i> Confronto annualità</a><?php endif; ?>
    <?php if (can('view','import_economics_xlsx.php')): ?><a class="btn btn-sm" href="<?=url_safe('import_economics_xlsx',['year'=>$year])?>"><i class="fa-solid fa-file-import"></i> Import dati economici</a><?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <?= route_slug_field() ?>
    <input type="hidden" name="year" value="<?=$year?>">
    <div class="form-group" style="margin:0"><label>Cerca</label>
      <input type="text" name="q" value="<?=h($f['q'])?>" placeholder="cognome, nome, email…" style="width:170px"></div>
    <div class="form-group" style="margin:0"><label>Azienda</label>
      <select name="company"><option value="">tutte</option>
        <?php foreach($companies as $id=>$nm):?><option value="<?=(int)$id?>" <?=$f['company']===(int)$id?'selected':''?>><?=h($nm)?></option><?php endforeach;?></select></div>
    <?php if ($agencies): ?>
    <div class="form-group" style="margin:0"><label>Agenzia</label>
      <select name="agency"><option value="">tutte</option>
        <?php foreach($agencies as $a):?><option value="<?=h($a)?>" <?=$f['agency']===$a?'selected':''?>><?=h($a)?></option><?php endforeach;?></select></div>
    <?php endif; ?>
    <div class="form-group" style="margin:0"><label>Sede</label>
      <select name="loc"><option value="">tutte</option>
        <?php foreach($locations as $id=>$nm):?><option value="<?=(int)$id?>" <?=$f['loc']===(int)$id?'selected':''?>><?=h($nm)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Dipartimento</label>
      <select name="dept"><option value="">tutti</option>
        <?php foreach($departments as $d):?><option value="<?=h($d)?>" <?=$f['dept']===$d?'selected':''?>><?=h($d)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Tipo rapporto</label>
      <select name="ct"><option value="">tutti</option>
        <?php foreach($contract_types as $c):?><option value="<?=h($c)?>" <?=$f['ct']===$c?'selected':''?>><?=h($c)?></option><?php endforeach;?></select></div>
    <div class="form-group" style="margin:0"><label>Class. finanziaria</label>
      <select name="cf"><option value="">tutte</option>
        <option value="Diretto"   <?=$f['cf']==='Diretto'?'selected':''?>>Diretto</option>
        <option value="Indiretto" <?=$f['cf']==='Indiretto'?'selected':''?>>Indiretto</option></select></div>
    <div class="form-group" style="margin:0"><label>Stato</label>
      <select name="stato">
        <option value="tutti"   <?=$f['stato']==='tutti'?'selected':''?>>tutti</option>
        <option value="attivi"  <?=$f['stato']==='attivi'?'selected':''?>>in forza</option>
        <option value="cessati" <?=$f['stato']==='cessati'?'selected':''?>>cessati</option></select></div>
    <div class="form-group" style="margin:0"><label>Assunti dal</label><input type="date" name="from" value="<?=h($f['from'])?>"></div>
    <div class="form-group" style="margin:0"><label>al</label><input type="date" name="to" value="<?=h($f['to'])?>"></div>
    <button class="btn">Filtra</button>
    <a class="btn btn-sm" href="<?=url_safe('finance_overview',['year'=>$year])?>">Azzera</a>
    <span style="align-self:center;color:var(--muted);font-size:12px"><strong><?=$tot?></strong> dipendenti · <strong><?=count($view_keys)?></strong> colonne</span>
    <?php if ($can_export): ?>
      <a class="btn btn-success" href="<?=$qs(['export'=>'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> XLSX</a>
      <a class="btn btn-primary" href="<?=$qs(['export'=>'csv'])?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
      <span style="align-self:center;font-size:11px;color:var(--muted)">(<?= $prefs['mode']==='vista' ? 'vista personalizzata' : 'tutti i campi' ?>)</span>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="margin-bottom:14px">
  <details>
    <summary style="cursor:pointer;font-weight:700;color:#0891b2"><i class="fa-solid fa-table-columns"></i> Personalizza colonne e modalità di esportazione</summary>
    <form method="post" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_view"><input type="hidden" name="year" value="<?=$year?>">
      <p style="font-size:12px;color:var(--muted);margin:0 0 10px">
        Spunta le colonne da mostrare e assegna un numero d'ordine (crescente da sinistra a destra).
        <?php if (!$can_hr): ?><br><em>Le colonne economiche sono visibili solo con il permesso riservato HR.</em><?php endif; ?>
      </p>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(235px,1fr));gap:6px">
        <?php $pos = 0; foreach ($prefs['order'] as $k):
          if (!isset($available[$k])) continue;
          $d = $available[$k]; $pos += 10;
          $checked = in_array($k, $prefs['visible'], true); ?>
          <div style="display:flex;gap:6px;align-items:center;padding:5px 8px;border:1px solid <?=$d[3]?'#fca5a5':'#e2e8f0'?>;border-radius:6px;background:<?=$d[3]?'#fef9f9':'#fff'?>">
            <input type="checkbox" name="vis[]" value="<?=h($k)?>" <?=$checked?'checked':''?>>
            <input type="number" name="ord[<?=h($k)?>]" value="<?=$pos?>" step="1" style="width:62px;font-size:11px" title="ordine di visualizzazione">
            <span style="font-size:11px;font-weight:600"><?=h($d[0])?><?php if($d[3]):?> <i class="fa-solid fa-lock" style="color:#dc2626;font-size:9px" title="riservato HR"></i><?php endif;?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:14px;align-items:center;margin-top:12px;flex-wrap:wrap">
        <div style="font-size:12px">
          <strong>Esportazione:</strong>
          <label style="margin-left:8px"><input type="radio" name="export_mode" value="tutti" <?=$prefs['mode']!=='vista'?'checked':''?>> tutti i campi</label>
          <label style="margin-left:10px"><input type="radio" name="export_mode" value="vista" <?=$prefs['mode']==='vista'?'checked':''?>> come la vista personalizzata</label>
        </div>
        <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva vista</button>
      </div>
    </form>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('Ripristinare le colonne predefinite?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset_view"><input type="hidden" name="year" value="<?=$year?>">
      <button class="btn btn-sm"><i class="fa-solid fa-rotate-left"></i> Ripristina predefinite</button>
    </form>
    <?php if ($can_export): ?>
      <p style="font-size:11px;color:var(--muted);margin-top:8px">Esportazione immediata ignorando la preferenza salvata:
        <a href="<?=$qs(['export'=>'xlsx','mode'=>'tutti'])?>">XLSX tutti i campi</a> ·
        <a href="<?=$qs(['export'=>'xlsx','mode'=>'vista'])?>">XLSX come la vista</a> ·
        <a href="<?=$qs(['export'=>'csv','mode'=>'tutti'])?>">CSV tutti i campi</a> ·
        <a href="<?=$qs(['export'=>'csv','mode'=>'vista'])?>">CSV come la vista</a>
      </p>
    <?php endif; ?>
  </details>
</div>

<div class="card">
  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:11px;white-space:nowrap">
      <thead><tr>
        <?php foreach ($view_keys as $k): ?>
          <th style="<?= $available[$k][3] ? 'background:#fee2e2;color:#991b1b' : '' ?>"><?=h($available[$k][0])?></th>
        <?php endforeach; ?>
        <?php if ($can_profile || $can_hr): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$tot): ?>
        <tr><td colspan="<?=count($view_keys)+1?>" style="text-align:center;color:var(--muted);padding:16px">Nessun dipendente con i filtri selezionati.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($view_keys as $k): ?>
            <td style="<?= $isNum($k) ? 'text-align:right' : '' ?>"><?= h($fmtv($k, $r['vals'][$k] ?? null)) ?></td>
          <?php endforeach; ?>
          <?php if ($can_profile || $can_hr): ?>
          <td style="white-space:nowrap">
            <?php if ($can_profile): ?><a class="btn btn-sm" href="<?=url_safe('employee_profile', ['id'=>$r['emp_id']])?>" title="Scheda anagrafica"><i class="fa-solid fa-id-card"></i></a><?php endif; ?>
            <?php if ($can_hr): ?><a class="btn btn-sm" style="background:#fee2e2;color:#991b1b" href="<?=url_safe('employee_compensation', ['id'=>$r['emp_id'],'year'=>$year])?>" title="Compensation &amp; Benefit"><i class="fa-solid fa-euro-sign"></i></a><?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">Dati economici relativi all'esercizio <strong><?=$year?></strong>. Le colonne evidenziate in rosso contengono dati economici riservati HR. L'esportazione rispetta i filtri applicati; il CSV usa separatore <code>;</code> e UTF-8 con BOM.</p>
</div>
<?php require_once('footer.php'); ?>
