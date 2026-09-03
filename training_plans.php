<?php
/**
 * certV 2.4 — training_plans.php   Master Calendar Formazione
 * Visibilità basata su ruoli (manage_permissions.php):
 *   1 Super Admin / 2 HR Director → tutti i dipendenti
 *   3 Brand Manager → dipendenti con cert/piani sui brand gestiti
 *   4 Team Leader → dipendenti assegnati alle sue posizioni + stesso dipartimento
 *   5 Recruiter → solo statistiche aggregate (se ha accesso alla pagina)
 *   6 Dipendente → solo i propri impegni
 *
 * Fonti dati (UNION):
 *   training_plans (piani formativi)
 *   planned_exams (esami pianificati)
 *   user_certifications (scadenze in arrivo)
 */
require_once('access_control.php');
require_once('functions.php');
require_once('header.php');

$u_id     = (int)$_SESSION['user_id'];
$u_emp_id = (int)($_SESSION['employee_id'] ?? 0);
$u_role   = (int)($_SESSION['role_id'] ?? 99);

// ── Mappa tipi percorso ─────────────────────────────────────────────────────
$PLAN_TYPES = [
    'formazione'            => ['Formazione',            'fa-graduation-cap','#0369a1','#e0f2fe'],
    'esame_certificazione'  => ['Esame certificazione',  'fa-file-circle-check','#7c3aed','#ede9fe'],
    'rinnovo'               => ['Rinnovo',               'fa-rotate',       '#059669','#ecfdf5'],
    'workshop_tecnico'      => ['Workshop tecnico',      'fa-screwdriver-wrench','#d97706','#fffbeb'],
    'workshop_commerciale'  => ['Workshop commerciale',  'fa-handshake',    '#dc2626','#fee2e2'],
    'convegno'              => ['Convegno',              'fa-users-rectangle','#6366f1','#eef2ff'],
];

// ═══════════════════════════════════════════════════════════════════════════
//  LOGICA VISIBILITÀ PER RUOLO
// ═══════════════════════════════════════════════════════════════════════════

$visible_emp_ids = null; // null = tutti, array = lista specifica

if ($u_role >= 6) {
    // Dipendente: solo se stesso
    $visible_emp_ids = [$u_emp_id];
} elseif ($u_role === 5) {
    // Recruiter: solo vista aggregata — nessun dipendente specifico (vede KPI)
    $visible_emp_ids = [0]; // forzatamente vuoto
} elseif ($u_role === 4) {
    // Team Leader: dipendenti che hanno candidature "hired" sulle sue posizioni
    // + dipendenti nello stesso dipartimento
    $team_ids = [];
    try {
        // Dipendenti assunti tramite posizioni del TL
        $tq = $pdo->prepare(
            "SELECT DISTINCT e.id FROM employees e
             JOIN candidates c2 ON c2.email = e.personal_email OR (LOWER(c2.first_name)=LOWER(e.first_name) AND LOWER(c2.last_name)=LOWER(e.last_name))
             JOIN candidate_applications ca ON ca.candidate_id = c2.id AND ca.stage='hired'
             JOIN job_positions jp ON ca.position_id = jp.id AND jp.team_leader_id = ?
             WHERE e.status='active'"
        );
        $tq->execute([$u_id]);
        foreach ($tq->fetchAll(PDO::FETCH_COLUMN) as $eid) $team_ids[(int)$eid] = true;
    } catch (\Exception $e) {}

    // + dipendenti nello stesso dipartimento del TL
    try {
        $dept_q = $pdo->prepare("SELECT department FROM employees WHERE id=?");
        $dept_q->execute([$u_emp_id]); $my_dept = $dept_q->fetchColumn(); $dept_q->closeCursor();
        if ($my_dept) {
            $dq = $pdo->prepare("SELECT id FROM employees WHERE department=? AND status='active'");
            $dq->execute([$my_dept]);
            foreach ($dq->fetchAll(PDO::FETCH_COLUMN) as $eid) $team_ids[(int)$eid] = true;
        }
    } catch (\Exception $e) {}

    // Sempre include se stesso
    $team_ids[$u_emp_id] = true;
    $visible_emp_ids = array_keys($team_ids);
} elseif ($u_role === 3) {
    // Brand Manager: dipendenti che hanno piani/esami/certificazioni su brand gestiti dal BM
    $bm_ids = [];
    try {
        // Brand gestiti dal BM (via brand_referents)
        $br_q = $pdo->prepare("SELECT DISTINCT brand_id FROM brand_referents WHERE user_id=? AND is_active=1");
        $br_q->execute([$u_id]);
        $my_brands = $br_q->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($my_brands)) {
            $ph = implode(',', array_fill(0, count($my_brands), '?'));
            // Dipendenti con cert/piani su questi brand
            $eq = $pdo->prepare("SELECT DISTINCT emp_id FROM (
                SELECT tp.employee_id emp_id FROM training_plans tp
                JOIN certifications c ON tp.certification_id=c.id WHERE c.brand_id IN($ph)
                UNION
                SELECT uc.employee_id FROM user_certifications uc
                JOIN certifications c ON uc.certification_id=c.id WHERE c.brand_id IN($ph)
                UNION
                SELECT pe.employee_id FROM planned_exams pe
                JOIN certifications c ON pe.certification_id=c.id WHERE c.brand_id IN($ph)
            ) sub");
            $eq->execute([...$my_brands, ...$my_brands, ...$my_brands]);
            foreach ($eq->fetchAll(PDO::FETCH_COLUMN) as $eid) $bm_ids[(int)$eid] = true;
        }
    } catch (\Exception $e) {}
    // Fallback: se il BM non ha brand associati, vede tutti (per non bloccare)
    $visible_emp_ids = empty($bm_ids) ? null : array_keys($bm_ids);
}
// Ruoli 1-2: $visible_emp_ids resta null → vede tutti

// ═══════════════════════════════════════════════════════════════════════════
//  PARAMETRI CALENDARIO
// ═══════════════════════════════════════════════════════════════════════════

$view  = in_array($_GET['v']??'month', ['week','month','quarter','list']) ? ($_GET['v']??'month') : 'month';
$month = (int)($_GET['m'] ?? date('n'));
$year  = (int)($_GET['y'] ?? date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12){ $month = 1;  $year++; }

$first_of_month = new DateTime("$year-$month-01");
$cur_display    = clone $first_of_month;
$start          = (clone $first_of_month)->modify('monday this week');
$end = match($view) {
    'week'    => (clone $start)->modify('+6 days'),
    'quarter' => (clone $first_of_month)->modify('+3 months -1 day'),
    default   => (clone $first_of_month)->modify('last day of this month'),
};
$date_from = $start->format('Y-m-d');
$date_to   = $end->format('Y-m-d');

// Filtri utente
$f_emps   = $_GET['f_us'] ?? [];
$f_brands = $_GET['f_br'] ?? [];
$f_tipo   = $_GET['f_tipo'] ?? '';
$f_plan_type = $_GET['f_plan_type'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════
//  QUERY EVENTI (UNION 3 fonti)
// ═══════════════════════════════════════════════════════════════════════════

function build_emp_filter(string $col, ?array $visible, array $selected): array {
    $sql = ''; $params = [];
    if ($visible !== null && !empty($visible)) {
        $ph = implode(',', array_fill(0, count($visible), '?'));
        $sql .= " AND $col IN($ph)";
        $params = $visible;
    }
    if (!empty($selected)) {
        $ph = implode(',', array_fill(0, count($selected), '?'));
        $sql .= " AND $col IN($ph)";
        $params = array_merge($params, $selected);
    }
    return [$sql, $params];
}

// ── Training Plans ──
$sql_tp = "SELECT tp.target_date ev_date, e.first_name, e.last_name, e.department,
                  certA.name cert_name, COALESCE(bA.name,'—') brand_name,
                  'PIANO' tipo, tp.priority info, tp.status tp_status, tp.budget, tp.is_renewal,
                  tp.plan_type
           FROM training_plans tp
           JOIN employees e          ON tp.employee_id = e.id
           LEFT JOIN certifications certA ON tp.certification_id = certA.id
           LEFT JOIN brands bA       ON certA.brand_id = bA.id
           WHERE tp.target_date BETWEEN ? AND ? AND tp.status NOT IN('completed','cancelled')";
$p_tp = [$date_from, $date_to];
[$ef_sql, $ef_params] = build_emp_filter('tp.employee_id', $visible_emp_ids, $f_emps);
$sql_tp .= $ef_sql; $p_tp = array_merge($p_tp, $ef_params);
if (!empty($f_brands)) { $ph=implode(',',array_fill(0,count($f_brands),'?')); $sql_tp.=" AND certA.brand_id IN($ph)"; $p_tp=array_merge($p_tp,$f_brands); }
if ($f_plan_type) { $sql_tp .= " AND tp.plan_type=?"; $p_tp[] = $f_plan_type; }

// ── Planned Exams ──
$sql_pe = "SELECT pe.planned_date ev_date, e.first_name, e.last_name, e.department,
                  certB.name cert_name, COALESCE(bB.name,'—') brand_name,
                  'ESAME' tipo, pe.status info, pe.status tp_status, NULL budget, 0 is_renewal,
                  pe.plan_type
           FROM planned_exams pe
           JOIN employees e          ON pe.employee_id = e.id
           LEFT JOIN certifications certB ON pe.certification_id = certB.id
           LEFT JOIN brands bB       ON certB.brand_id = bB.id
           WHERE pe.planned_date BETWEEN ? AND ? AND pe.status='planned'";
$p_pe = [$date_from, $date_to];
[$ef_sql, $ef_params] = build_emp_filter('pe.employee_id', $visible_emp_ids, $f_emps);
$sql_pe .= $ef_sql; $p_pe = array_merge($p_pe, $ef_params);
if (!empty($f_brands)) { $ph=implode(',',array_fill(0,count($f_brands),'?')); $sql_pe.=" AND certB.brand_id IN($ph)"; $p_pe=array_merge($p_pe,$f_brands); }
if ($f_plan_type) { $sql_pe .= " AND pe.plan_type=?"; $p_pe[] = $f_plan_type; }

// ── Scadenze certificazioni ──
$sql_uc = "SELECT uc.expiry_date ev_date, e.first_name, e.last_name, e.department,
                  certC.name cert_name, COALESCE(bC.name,'—') brand_name,
                  'SCADENZA' tipo, uc.status info, 'active' tp_status, NULL budget, 0 is_renewal,
                  'rinnovo' plan_type
           FROM user_certifications uc
           JOIN employees e          ON uc.employee_id = e.id
           JOIN certifications certC ON uc.certification_id = certC.id
           LEFT JOIN brands bC       ON certC.brand_id = bC.id
           WHERE uc.expiry_date BETWEEN ? AND ? AND uc.status IN('active','expiring')";
$p_uc = [$date_from, $date_to];
[$ef_sql, $ef_params] = build_emp_filter('uc.employee_id', $visible_emp_ids, $f_emps);
$sql_uc .= $ef_sql; $p_uc = array_merge($p_uc, $ef_params);
if (!empty($f_brands)) { $ph=implode(',',array_fill(0,count($f_brands),'?')); $sql_uc.=" AND certC.brand_id IN($ph)"; $p_uc=array_merge($p_uc,$f_brands); }
// Scadenze sono sempre tipo "rinnovo" — filtra solo se richiesto
if ($f_plan_type && $f_plan_type !== 'rinnovo') { $sql_uc .= " AND 0"; }

// Applica filtro tipo
$queries = [];
if (!$f_tipo || $f_tipo === 'piano')    $queries[] = ['sql' => $sql_tp, 'params' => $p_tp];
if (!$f_tipo || $f_tipo === 'esame')    $queries[] = ['sql' => $sql_pe, 'params' => $p_pe];
if (!$f_tipo || $f_tipo === 'scadenza') $queries[] = ['sql' => $sql_uc, 'params' => $p_uc];

$all_events = [];
if ($visible_emp_ids === null || !empty($visible_emp_ids)) {
    $union_sql = implode(" UNION ALL ", array_map(fn($q) => "({$q['sql']})", $queries)) . " ORDER BY ev_date ASC";
    $union_params = array_merge(...array_map(fn($q) => $q['params'], $queries));
    $s = $pdo->prepare($union_sql);
    $s->execute($union_params);
    $all_events = $s->fetchAll();
}

$events_by_date = [];
foreach ($all_events as $e) $events_by_date[$e['ev_date']][] = $e;

// Liste per filtri
$all_emps   = ($visible_emp_ids === null)
    ? $pdo->query("SELECT id,first_name,last_name,department FROM employees WHERE status='active' ORDER BY last_name")->fetchAll()
    : ((!empty($visible_emp_ids)) ? $pdo->prepare("SELECT id,first_name,last_name,department FROM employees WHERE id IN(" . implode(',',array_fill(0,count($visible_emp_ids),'?')) . ") AND status='active' ORDER BY last_name") : null);
if (is_object($all_emps)) { $all_emps->execute($visible_emp_ids); $all_emps = $all_emps->fetchAll(); }
if (!$all_emps) $all_emps = [];
$all_brands = $pdo->query("SELECT id,name FROM brands ORDER BY name")->fetchAll();

// KPI
$kpi_piani = count(array_filter($all_events, fn($e) => $e['tipo'] === 'PIANO'));
$kpi_esami = count(array_filter($all_events, fn($e) => $e['tipo'] === 'ESAME'));
$kpi_scad  = count(array_filter($all_events, fn($e) => $e['tipo'] === 'SCADENZA'));
$kpi_people = count(array_unique(array_map(fn($e) => $e['first_name'].$e['last_name'], $all_events)));

function nav_url(string $v, int $m, int $y, int $delta): string {
    $nm = $m + $delta; $ny = $y;
    if ($nm < 1)  { $nm = 12; $ny--; }
    if ($nm > 12) { $nm = 1;  $ny++; }
    return "training_plans.php?v=$v&m=$nm&y=$ny";
}

$tipo_colors = [
    'PIANO'    => ['#0369a1','#e0f2fe','🎯'],
    'ESAME'    => ['#7c3aed','#ede9fe','📝'],
    'SCADENZA' => ['#dc2626','#fee2e2','⚠️'],
];

// Funzione: colore per plan_type (più specifico di tipo)
function plan_type_style(string $plan_type, array $PLAN_TYPES): array {
    $pt = $PLAN_TYPES[$plan_type] ?? null;
    return $pt ? [$pt[2], $pt[3], '<i class="fa-solid '.$pt[1].'" style="font-size:9px"></i>'] : ['#94a3b8','#f8fafc','📌'];
}
function plan_type_label(string $plan_type, array $PLAN_TYPES): string {
    return $PLAN_TYPES[$plan_type][0] ?? $plan_type;
}
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-calendar-days" style="color:var(--p);margin-right:10px"></i>Master Calendar Formazione
    </h1>
    <p style="color:var(--muted);font-size:13px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <?php foreach($PLAN_TYPES as $k=>[$lbl,$ico,$col,$bg]): ?>
      <span style="color:<?=$col?>;font-weight:700;font-size:11px"><i class="fa-solid <?=$ico?>" style="font-size:10px"></i> <?=$lbl?></span>
      <?php endforeach; ?>
      <span style="color:#dc2626;font-weight:700;font-size:11px">⚠️ Scadenze</span>
      <?php
        $vis_label = match(true) {
            $u_role <= 2 => '— Visibilità: tutti i dipendenti',
            $u_role === 3 => '— Visibilità: dipendenti sui tuoi brand',
            $u_role === 4 => '— Visibilità: il tuo team (' . count($visible_emp_ids ?? []) . ' persone)',
            $u_role === 5 => '— Visibilità: solo statistiche aggregate',
            default => '— Visibilità: solo i tuoi impegni',
        };
      ?>
      <span style="color:var(--muted);font-style:italic;margin-left:8px"><?=$vis_label?></span>
    </p>
  </div>
  <div style="display:flex;gap:8px">
    <button onclick="exportCSV()" class="btn btn-sm no-print"><i class="fa-solid fa-file-csv"></i> Esporta</button>
    <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i></button>
  </div>
</div>

<!-- KPI -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px">
  <div class="stat-card" style="border-color:#0369a1"><div class="sl">Piani formativi</div><div class="sv" style="color:#0369a1"><?=$kpi_piani?></div></div>
  <div class="stat-card" style="border-color:#7c3aed"><div class="sl">Esami pianificati</div><div class="sv" style="color:#7c3aed"><?=$kpi_esami?></div></div>
  <div class="stat-card" style="border-color:#dc2626"><div class="sl">Scadenze in arrivo</div><div class="sv" style="color:#dc2626"><?=$kpi_scad?></div></div>
  <div class="stat-card" style="border-color:var(--p)"><div class="sl">Persone coinvolte</div><div class="sv" style="color:var(--p)"><?=$kpi_people?></div></div>
</div>

<!-- Filtri -->
<?php if($u_role <= 4 && count($all_emps) > 1): ?>
<form method="GET" class="filter-bar" style="align-items:flex-start">
<?php if (!empty($_GET["r"])): ?><input type="hidden" name="r" value="<?= htmlspecialchars($_GET["r"], ENT_QUOTES, "UTF-8") ?>"><?php endif; ?>
  <input type="hidden" name="v" value="<?=$view?>">
  <input type="hidden" name="m" value="<?=$month?>">
  <input type="hidden" name="y" value="<?=$year?>">
  <div class="fg">
    <label>Collaboratori</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;max-height:100px;overflow-y:auto;padding:8px;min-width:170px">
      <?php foreach($all_emps as $emp): ?>
      <label style="display:flex;gap:6px;font-size:12px;margin-bottom:3px;cursor:pointer;align-items:center">
        <input type="checkbox" name="f_us[]" value="<?=$emp['id']?>" <?=in_array($emp['id'],$f_emps)?'checked':''?>>
        <?=h($emp['last_name'].' '.$emp['first_name'])?><?=$emp['department']?' <span style="color:var(--muted);font-size:10px">('.h($emp['department']).')</span>':''?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg">
    <label>Brand</label>
    <div style="background:#fff;border:1px solid var(--border);border-radius:8px;max-height:100px;overflow-y:auto;padding:8px;min-width:150px">
      <?php foreach($all_brands as $b): ?>
      <label style="display:flex;gap:6px;font-size:12px;margin-bottom:3px;cursor:pointer;align-items:center">
        <input type="checkbox" name="f_br[]" value="<?=$b['id']?>" <?=in_array($b['id'],$f_brands)?'checked':''?>>
        <?=h($b['name'])?>
      </label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="fg">
    <label>Tipo percorso</label>
    <select name="f_tipo">
      <option value="">Tutti</option>
      <option value="piano" <?=$f_tipo==='piano'?'selected':''?>>📋 Piani formativi</option>
      <option value="esame" <?=$f_tipo==='esame'?'selected':''?>>📝 Esami pianificati</option>
      <option value="scadenza" <?=$f_tipo==='scadenza'?'selected':''?>>⚠️ Scadenze</option>
    </select>
  </div>
  <div class="fg">
    <label>Tipo attività</label>
    <select name="f_plan_type">
      <option value="">Tutti</option>
      <?php foreach($PLAN_TYPES as $k=>[$lbl,$ico,$col]): ?>
      <option value="<?=$k?>" <?=($_GET['f_plan_type']??'')===$k?'selected':''?>><?=h($lbl)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;flex-direction:column;gap:6px;padding-top:22px">
    <button type="submit" class="btn btn-primary">Applica</button>
    <a href="training_plans.php" class="btn btn-sm" style="text-align:center">Reset</a>
  </div>

  <div class="fg" style="margin-left:auto">
    <label style="visibility:hidden">.</label>
    <button type="button" onclick="window.print()" class="btn btn-sm" title="Stampa pagina"
            style="background:#fef3c7;color:#92400e;border-color:#fde68a">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</form>
<?php endif; ?>

<!-- Navigazione -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px" class="no-print">
  <div style="display:flex;background:#f1f5f9;padding:3px;border-radius:8px;border:1px solid var(--border)">
    <?php foreach(['week'=>'Settimana','month'=>'Mese','quarter'=>'Trimestre','list'=>'Lista'] as $k=>$l): ?>
    <a href="training_plans.php?v=<?=$k?>&m=<?=$month?>&y=<?=$year?>"
       style="padding:6px 14px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;<?=$view===$k?'background:#fff;color:var(--p);box-shadow:0 1px 3px rgba(0,0,0,.1)':'color:var(--muted)'?>">
      <?=$l?>
    </a>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;align-items:center;gap:14px">
    <a href="<?=nav_url($view,$month,$year,-1)?>" class="btn btn-sm"><i class="fa-solid fa-chevron-left"></i></a>
    <strong style="font-size:15px;min-width:160px;text-align:center"><?=$cur_display->format('F Y')?></strong>
    <a href="<?=nav_url($view,$month,$year,1)?>" class="btn btn-sm"><i class="fa-solid fa-chevron-right"></i></a>
  </div>
  <div style="font-size:12px;color:var(--muted)"><?=count($all_events)?> eventi</div>
</div>

<?php if($view === 'list'): ?>
<!-- ═══ VISTA LISTA ═══ -->
<div class="card" style="overflow-x:auto">
<table class="data-table" id="tCal">
<thead><tr><th>Data</th><th>Tipo percorso</th><th>Fonte</th><th>Collaboratore</th><th>Certificazione</th><th>Brand</th><th>Priorità</th><th>Dettagli</th></tr></thead>
<tbody>
<?php if(empty($all_events)): ?>
<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Nessun evento nel periodo.</td></tr>
<?php endif; ?>
<?php foreach($all_events as $ev):
  [$tc,$tbg,$ti] = plan_type_style($ev['plan_type'] ?? 'esame_certificazione', $PLAN_TYPES);
  $pt_label = plan_type_label($ev['plan_type'] ?? 'esame_certificazione', $PLAN_TYPES);
?>
<tr>
  <td style="font-weight:700;font-size:12px"><?=date('d/m/Y', strtotime($ev['ev_date']))?></td>
  <td><span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;background:<?=$tbg?>;color:<?=$tc?>;font-size:10px;font-weight:800"><?=$ti?> <?=h($pt_label)?></span></td>
  <td><span style="font-size:10px;color:var(--muted);font-weight:600"><?=$ev['tipo']?></span></td>
  <td style="font-weight:600;font-size:12px"><?=h($ev['last_name'].' '.$ev['first_name'])?><?=$ev['department']?' <span style="color:var(--muted);font-size:10px">('.h($ev['department']).')</span>':''?></td>
  <td style="font-size:12px"><?=h($ev['cert_name'] ?? '—')?></td>
  <td style="font-size:12px"><?=h($ev['brand_name'])?></td>
  <td style="font-size:11px"><?=h($ev['info'] ?? '—')?></td>
  <td style="font-size:11px;color:var(--muted)"><?=$ev['is_renewal']?'<span class="badge badge-info" style="font-size:9px">Rinnovo</span>':''?> <?=$ev['budget']?'€'.number_format($ev['budget'],0):''?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<script>$('#tCal').DataTable({language:{search:"Cerca:"},pageLength:50,order:[[0,'asc']]});</script>

<?php elseif(in_array($view,['month','week'])): ?>
<!-- ═══ VISTA CALENDARIO ═══ -->
<div style="background:#fff;border-radius:12px;border:1px solid #bae6fd;overflow:hidden">
  <div style="display:grid;grid-template-columns:repeat(7,1fr);background:#f0f9ff">
    <?php foreach(['Lun','Mar','Mer','Gio','Ven','Sab','Dom'] as $d): ?>
    <div style="padding:9px;text-align:center;font-size:10px;font-weight:800;color:#64748b"><?=$d?></div>
    <?php endforeach; ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:#bae6fd">
  <?php
  if ($view === 'month') {
      $grid_start = (clone $first_of_month)->modify('monday this week');
      $last_of_month = (clone $first_of_month)->modify('last day of this month');
      $grid_end  = clone $last_of_month;
      if ($grid_end->format('N') != 7) $grid_end->modify('next sunday');
  } else {
      $grid_start = clone $start;
      $grid_end   = clone $end;
  }
  $iter = clone $grid_start;
  while ($iter <= $grid_end):
      $ds = $iter->format('Y-m-d');
      $is_cur_month = ($iter->format('m') == $month);
      $is_today     = ($ds === date('Y-m-d'));
      $bg = $is_today ? 'background:#eff6ff;border:2px solid #3b82f6;' : ($is_cur_month ? 'background:#fff;' : 'background:#f8fafc;');
  ?>
  <div style="min-height:90px;padding:7px;<?=$bg?>border:1px solid #e0f2fe;">
    <div style="font-size:11px;font-weight:<?=$is_today?800:600?>;color:<?=$is_today?'#2563eb':($is_cur_month?'#1e293b':'#cbd5e1')?>;margin-bottom:4px"><?=$iter->format('j')?></div>
    <?php if(isset($events_by_date[$ds])): foreach($events_by_date[$ds] as $ev):
      [$ec,$ebg] = plan_type_style($ev['plan_type'] ?? 'esame_certificazione', $PLAN_TYPES);
    ?>
    <div style="font-size:9px;font-weight:700;padding:2px 5px;border-radius:3px;margin-bottom:2px;background:<?=$ebg?>;color:<?=$ec?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border-left:2px solid <?=$ec?>"
         title="<?=h(plan_type_label($ev['plan_type']??'esame_certificazione',$PLAN_TYPES).': '.$ev['cert_name'].' — '.$ev['first_name'].' '.$ev['last_name'])?>">
      <?=$PLAN_TYPES[$ev['plan_type']??''][1] ?? 'fa-circle' ?  '<i class="fa-solid '.($PLAN_TYPES[$ev['plan_type']??''][1]??'fa-circle').'" style="font-size:7px"></i>' : '📌'?> <?=h(mb_substr($ev['last_name'],0,8))?>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <?php $iter->modify('+1 day'); endwhile; ?>
  </div>
</div>

<?php else: ?>
<!-- ═══ VISTA TRIMESTRALE ═══ -->
<div class="card">
  <?php if(empty($all_events)): ?>
  <div style="text-align:center;padding:50px;color:var(--muted)">Nessun evento nel trimestre.</div>
  <?php else: ?>
  <?php $prev=''; foreach($all_events as $ev):
    [$col,$bg_c,$ico] = plan_type_style($ev['plan_type'] ?? 'esame_certificazione', $PLAN_TYPES);
    $pt_label = plan_type_label($ev['plan_type'] ?? 'esame_certificazione', $PLAN_TYPES);
    if ($ev['ev_date'] !== $prev): $prev = $ev['ev_date']; ?>
    <div style="padding:8px 0;font-size:11px;font-weight:800;color:var(--muted);border-bottom:1px solid var(--border);text-transform:uppercase;margin-top:8px">
      <?=date('l d M Y', strtotime($ev['ev_date']))?>
    </div>
    <?php endif; ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px;border-radius:8px;background:<?=$bg_c?>;margin:4px 0;border-left:4px solid <?=$col?>">
      <span style="font-size:11px;font-weight:800;color:<?=$col?>;min-width:70px"><?=$ico?> <?=h($pt_label)?></span>
      <div style="flex:1">
        <strong style="font-size:13px"><?=h($ev['cert_name'] ?? $ev['info'] ?? '—')?></strong><br>
        <span style="font-size:11px;color:var(--muted)"><?=h($ev['first_name'].' '.$ev['last_name'])?> · <?=h($ev['brand_name'])?></span>
      </div>
      <?php if($ev['info']): ?><span style="font-size:10px;padding:2px 8px;border-radius:10px;background:<?=$col?>22;color:<?=$col?>;font-weight:700"><?=h($ev['info'])?></span><?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php endif; ?>

<script>
function exportCSV(){
  let rows = "Data,Tipo,Collaboratore,Dipartimento,Certificazione,Brand,Priorita\n";
  <?php foreach($all_events as $ev): ?>
  rows += "<?=addslashes($ev['ev_date'])?>,<?=addslashes($ev['tipo'])?>,<?=addslashes($ev['first_name'].' '.$ev['last_name'])?>,<?=addslashes($ev['department']??'')?>,<?=addslashes($ev['cert_name'])?>,<?=addslashes($ev['brand_name'])?>,<?=addslashes($ev['info']??'')?>\n";
  <?php endforeach; ?>
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(rows);
  a.download = 'calendar_<?=$year?>_<?=$month?>.csv';
  a.click();
}
</script>
<?php require_once('footer.php'); ?>
