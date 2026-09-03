<?php
/**
 * employee_compensation.php — Compensation & Benefit (v1.8.0, era v1.7.94)
 *
 * Scheda separata e riservata HR con i dati economici del dipendente e il
 * calcolo del costo pieno / valore FTE.
 *
 * v1.8.0 — I dati economici hanno validità ANNUALE: la scheda opera su un
 * anno di competenza selezionabile (hr_economic_years). Gli input sono salvati
 * in hr_employee_economics per (dipendente, anno). Per l'anno corrente i valori
 * vengono rispecchiati anche nelle colonne di employees (retrocompatibilità con
 * la scheda anagrafica). Gli esercizi bloccati sono in sola lettura.
 * I parametri globali (RIF.) sono mostrati in sola lettura per l'anno scelto:
 * si modificano in Amministrazione → Valori di riferimento HR.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/CostModel.php');

$can_compensation = can('view', 'manage_employees_compensation.php') || can('view', 'employee_compensation.php');
if (!$can_compensation) { redirect('manage_employees'); }
$u_id   = (int)$_SESSION['user_id'];
$emp_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($emp_id <= 0) { redirect('manage_employees'); }

$can_edit = can('edit', 'employee_compensation.php') || can('edit', 'manage_employees.php');

$cm    = new CostModel($pdo);
$year  = $cm->resolveYear($_GET['year'] ?? $_POST['year'] ?? 0);
$cur_y = $cm->currentYear();

// Stato dell'esercizio (blocco)
$year_locked = false;
try {
    $st = $pdo->prepare("SELECT is_locked FROM hr_economic_years WHERE year=?");
    $st->execute([$year]);
    $year_locked = (bool)$st->fetchColumn();
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_year') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('employee_compensation', ['id' => $emp_id, 'year' => $year]); }
    $ny = (int)($_POST['new_year'] ?? 0);
    if ($ny < 2000 || $ny > 2100) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Anno di competenza non valido.</div>";
        redirect('employee_compensation', ['id' => $emp_id, 'year' => $year]);
    }
    $st = $pdo->prepare("INSERT IGNORE INTO hr_economic_years (year, label, created_by) VALUES (?, ?, ?)");
    $st->execute([$ny, "Esercizio $ny", $u_id]);
    if ($st->rowCount() > 0) {
        write_log('HR', 'success', "Creato esercizio $ny (da Compensation & Benefit)", $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Esercizio <strong>$ny</strong> creato.</div>";
    } else {
        $_SESSION['flash_msg'] = "<div class='alert alert-info'>Esercizio <strong>$ny</strong> già esistente: selezionato.</div>";
    }
    redirect('employee_compensation', ['id' => $emp_id, 'year' => $ny]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit)    { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('employee_compensation', ['id' => $emp_id, 'year' => $year]); }
    if ($year_locked)  { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Esercizio $year bloccato: modifiche non consentite.</div>"; redirect('employee_compensation', ['id' => $emp_id, 'year' => $year]); }

    $numf = fn($k) => is_numeric(str_replace(',', '.', (string)($_POST[$k] ?? ''))) ? (float)str_replace(',', '.', (string)$_POST[$k]) : null;
    $cf   = in_array($_POST['classificazione_finanziaria'] ?? '', ['Diretto','Indiretto'], true) ? $_POST['classificazione_finanziaria'] : null;
    $fs   = !empty($_POST['fuori_sede']) ? 1 : 0;

    $vals = [
        'ral'                        => $numf('ral'),
        'premio_concordato'          => $numf('premio_concordato'),
        'km_concordati'              => $numf('km_concordati'),
        'km_effettivi'               => $numf('km_effettivi'),
        'fuori_sede'                 => $fs,
        'fuori_sede_amount'          => $numf('fuori_sede_amount'),
        'classificazione_finanziaria'=> $cf,
        'moltiplicatore_fc'          => $numf('moltiplicatore_fc'),
        'qt_trasferte_annue'         => $numf('qt_trasferte_annue'),
        'qt_buoni_pasto'             => $numf('qt_buoni_pasto'),
        'valore_tabp'                => $numf('valore_tabp'),
        'val_km'                     => $numf('val_km'),
        'incentivazione_extra'       => $numf('incentivazione_extra'),
        'valore_medio_anno_auto'     => $numf('valore_medio_anno_auto'),
        'overhead_aziendale'         => $numf('overhead_aziendale'),
        'moltiplicatore_fte'         => $numf('moltiplicatore_fte'),
    ];

    $cols = array_keys($vals);
    $set  = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
    $sql  = "INSERT INTO hr_employee_economics (employee_id, year, " . implode(',', array_map(fn($c)=>"`$c`",$cols)) . ", updated_by)
             VALUES (" . implode(',', array_fill(0, count($cols) + 3, '?')) . ")
             ON DUPLICATE KEY UPDATE $set, updated_by=VALUES(updated_by)";
    $args = array_merge([$emp_id, $year], array_values($vals), [$u_id]);
    $pdo->prepare($sql)->execute($args);

    // Anno corrente: rispecchia nelle colonne employees per la scheda anagrafica.
    if ($year === $cur_y) {
        $pdo->prepare("UPDATE employees SET
                ral=?, premio_concordato=?, km_concordati=?, km_effettivi=?,
                fuori_sede=?, fuori_sede_amount=?, classificazione_finanziaria=?,
                moltiplicatore_fc=?, qt_trasferte_annue=?, qt_buoni_pasto=?, valore_tabp=?,
                val_km=?, incentivazione_extra=?, valore_medio_anno_auto=?,
                overhead_aziendale=?, moltiplicatore_fte=?
              WHERE id=?")->execute([
            $vals['ral'], $vals['premio_concordato'], $vals['km_concordati'], $vals['km_effettivi'],
            $vals['fuori_sede'], $vals['fuori_sede_amount'], $vals['classificazione_finanziaria'],
            $vals['moltiplicatore_fc'], $vals['qt_trasferte_annue'], $vals['qt_buoni_pasto'], $vals['valore_tabp'],
            $vals['val_km'], $vals['incentivazione_extra'], $vals['valore_medio_anno_auto'],
            $vals['overhead_aziendale'], $vals['moltiplicatore_fte'],
            $emp_id,
        ]);
    }
    write_log('HR', 'success', "Compensation & Benefit $year aggiornati per dipendente #$emp_id", $u_id);
    $_SESSION['flash_msg'] = "<div class='alert alert-success'>Dati economici dell'esercizio <strong>$year</strong> salvati.</div>";
    redirect('employee_compensation', ['id' => $emp_id, 'year' => $year]);
}

$st = $pdo->prepare("SELECT * FROM employees WHERE id=?");
$st->execute([$emp_id]);
$emp = $st->fetch(PDO::FETCH_ASSOC);
if (!$emp) { redirect('manage_employees'); }

// Dati economici dell'anno: dalla tabella per-anno; se assente e anno corrente, prefill dalle colonne employees.
$eco = $cm->economics($emp_id, $year);
$has_year_row = $eco !== null;
if ($eco === null) {
    $eco = ($year === $cur_y) ? $emp : [];
}
$v = fn($k) => $eco[$k] ?? null;

$cst = $cm->compute($eco, $year);
$U   = $cst['used']; $C = $cst['calc'];
$years = $cm->years();

require_once('header.php');
if (!empty($_SESSION['flash_msg'])) { echo $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$eur   = fn($val) => number_format((float)$val, 2, ',', '.') . ' €';
$dis   = (!$can_edit || $year_locked) ? 'disabled' : '';
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
  <div>
    <h1><i class="fa-solid fa-euro-sign" style="color:#dc2626"></i> Compensation &amp; Benefit
      <span style="background:#fee2e2;color:#991b1b;padding:2px 10px;border-radius:10px;font-size:10px;letter-spacing:1px;font-weight:700;vertical-align:middle"><i class="fa-solid fa-lock"></i> RISERVATO HR</span>
    </h1>
    <p style="color:var(--muted);font-size:13px;margin:4px 0 0">
      <strong><?= h(trim(($emp['last_name'] ?? '') . ' ' . ($emp['first_name'] ?? ''))) ?></strong>
      <?php if (!empty($emp['employee_code'])): ?> · matricola <?= h($emp['employee_code']) ?><?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <form method="get" style="display:flex;gap:6px;align-items:flex-end">
      <?= route_slug_field() ?>
      <input type="hidden" name="id" value="<?= $emp_id ?>">
      <div class="form-group" style="margin:0"><label style="font-size:11px">Anno di competenza</label>
        <select name="year" onchange="this.form.submit()" style="font-weight:700">
          <?php foreach ($years as $y => $lbl): ?>
            <option value="<?= (int)$y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int)$y ?><?= $y === $cur_y ? ' (corrente)' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
    </form>
    <?php if ($can_edit): ?>
    <form method="post" style="display:flex;gap:6px;align-items:flex-end" onsubmit="return confirm('Creare/selezionare questo esercizio?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_year">
      <input type="hidden" name="id" value="<?= $emp_id ?>">
      <div class="form-group" style="margin:0"><label style="font-size:11px">Nuovo anno di competenza</label>
        <input type="number" name="new_year" min="2000" max="2100" step="1" value="<?= (int)date('Y') ?>" style="width:92px;font-weight:700"></div>
      <button class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> Crea esercizio</button>
    </form>
    <?php endif; ?>
    <?php if (can('view', 'finance_overview.php')): ?>
      <a class="btn btn-sm" href="<?= url_safe('finance_overview', ['year' => $year]) ?>"><i class="fa-solid fa-chart-line"></i> Torna a Finance</a>
    <?php endif; ?>
    <a class="btn btn-sm" href="<?= url_safe('employee_profile', ['id' => $emp_id]) ?>"><i class="fa-solid fa-arrow-left"></i> Torna alla scheda</a>
    <?php if (can('view', 'finance_compare.php')): ?>
      <a class="btn btn-sm" href="<?= url_safe('finance_compare') ?>"><i class="fa-solid fa-scale-balanced"></i> Confronto annualità</a>
    <?php endif; ?>
    <?php if (can('view', 'hr_reference_values.php')): ?>
      <a class="btn btn-sm" href="<?= url_safe('hr_reference_values') ?>"><i class="fa-solid fa-sliders"></i> Valori di riferimento</a>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:12px;padding:10px 14px;background:<?= $year_locked ? '#fffbeb' : '#f0fdf4' ?>;border:1px solid <?= $year_locked ? '#fcd34d' : '#86efac' ?>">
  <span style="font-size:12px">
    Esercizio <strong><?= $year ?></strong> — <?= h($years[$year] ?? ('Esercizio ' . $year)) ?>.
    <?php if ($year_locked): ?><span style="color:#92400e;font-weight:700"><i class="fa-solid fa-lock"></i> Bloccato (sola lettura)</span>
    <?php elseif (!$has_year_row && $year === $cur_y): ?><span style="color:#166534">Dati non ancora salvati per questo esercizio: mostrati i valori correnti della scheda.</span>
    <?php elseif (!$has_year_row): ?><span style="color:#92400e">Nessun dato economico registrato per questo esercizio.</span>
    <?php endif; ?>
  </span>
</div>

<div class="card" style="margin-bottom:14px;background:#f0f9ff;border:1px solid #93c5fd;padding:12px">
  <div style="font-size:12px;font-weight:700;color:#0c4a6e;margin-bottom:8px"><i class="fa-solid fa-globe"></i> Valori di riferimento aziendali <?= $year ?> (validi per tutti)</div>
  <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px">
    <?php foreach (CostModel::refDefinitions() as $k => [$lbl, $dec]):
      $map = ['hr_mult_fc'=>'moltiplicatore_fc','hr_valore_tabp'=>'valore_tabp','hr_val_km'=>'val_km','hr_overhead_aziendale'=>'overhead_aziendale','hr_mult_fte'=>'moltiplicatore_fte'];
      $f = $map[$k]; $isRef = $U[$f]['ref']; ?>
      <div style="padding:8px;border-radius:8px;background:#fff;border:1px solid #bfdbfe">
        <div style="font-size:10px;color:var(--muted);font-weight:700"><?= h($lbl) ?></div>
        <div style="font-size:14px;font-weight:800;color:#0369a1"><?= number_format($cm->refs($year)[$k], (int)$dec, ',', '.') ?></div>
        <div style="font-size:9px;color:<?= $isRef ? '#0369a1' : '#b45309' ?>"><?= $isRef ? 'in uso' : 'sovrascritto in scheda' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p style="font-size:11px;color:var(--muted);margin:8px 0 0">Sono in sola lettura: si modificano (con storico, per anno) in <em>Amministrazione → Valori di riferimento HR</em>. Nella scheda sottostante puoi sovrascriverli per questo dipendente lasciando il campo vuoto per usare il riferimento.</p>
</div>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $emp_id ?>"><input type="hidden" name="year" value="<?= $year ?>">
  <div class="card" style="border:2px solid #fca5a5;background:#fef9f9">
    <div class="card-header"><span class="card-title" style="color:#dc2626"><i class="fa-solid fa-file-invoice-dollar"></i> Dati economici — esercizio <?= $year ?></span></div>
    <div class="grid-2">
      <div class="form-group"><label>RAL annua (€)</label>
        <input type="number" name="ral" step="0.01" min="0" value="<?=h($v('ral')??'')?>" placeholder="24972.50" <?=$dis?>></div>
      <div class="form-group"><label>Moltiplicatore FC <small style="color:var(--muted)">(vuoto = <?=number_format($cm->refs($year)['hr_mult_fc'],5,',','.')?>)</small></label>
        <input type="number" name="moltiplicatore_fc" step="0.00001" min="0" value="<?=h($v('moltiplicatore_fc')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Premio concordato (€)</label>
        <input type="number" name="premio_concordato" step="0.01" min="0" value="<?=h($v('premio_concordato')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Classificazione finanziaria</label>
        <select name="classificazione_finanziaria" <?=$dis?>>
          <option value="">—</option>
          <option value="Diretto"   <?= ($v('classificazione_finanziaria')??'')==='Diretto'?'selected':'' ?>>Diretto</option>
          <option value="Indiretto" <?= ($v('classificazione_finanziaria')??'')==='Indiretto'?'selected':'' ?>>Indiretto</option>
        </select></div>
      <div class="form-group"><label>Qt. Trasferte Annue</label>
        <input type="number" name="qt_trasferte_annue" step="0.01" min="0" value="<?=h($v('qt_trasferte_annue')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Qt. Buoni Pasto</label>
        <input type="number" name="qt_buoni_pasto" step="0.01" min="0" value="<?=h($v('qt_buoni_pasto')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>ValoreTABP (€) <small style="color:var(--muted)">(vuoto = <?=number_format($cm->refs($year)['hr_valore_tabp'],2,',','.')?>)</small></label>
        <input type="number" name="valore_tabp" step="0.01" min="0" value="<?=h($v('valore_tabp')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Km concordati (annui)</label>
        <input type="number" name="km_concordati" step="0.01" min="0" value="<?=h($v('km_concordati')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Val.KM (€/km) <small style="color:var(--muted)">(vuoto = <?=number_format($cm->refs($year)['hr_val_km'],4,',','.')?>)</small></label>
        <input type="number" name="val_km" step="0.0001" min="0" value="<?=h($v('val_km')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Km effettivi (annui)</label>
        <input type="number" name="km_effettivi" step="0.01" min="0" value="<?=h($v('km_effettivi')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Incentivazione Extra (€)</label>
        <input type="number" name="incentivazione_extra" step="0.01" min="0" value="<?=h($v('incentivazione_extra')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Valore Medio anno Auto (€)</label>
        <input type="number" name="valore_medio_anno_auto" step="0.01" min="0" value="<?=h($v('valore_medio_anno_auto')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>OverHead Aziendale <small style="color:var(--muted)">(vuoto = <?=number_format($cm->refs($year)['hr_overhead_aziendale'],4,',','.')?>)</small></label>
        <input type="number" name="overhead_aziendale" step="0.0001" min="0" value="<?=h($v('overhead_aziendale')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Moltiplicatore FTE <small style="color:var(--muted)">(vuoto = <?=number_format($cm->refs($year)['hr_mult_fte'],4,',','.')?>)</small></label>
        <input type="number" name="moltiplicatore_fte" step="0.0001" min="0" value="<?=h($v('moltiplicatore_fte')??'')?>" <?=$dis?>></div>
      <div class="form-group"><label>Indennità fuori sede</label>
        <select name="fuori_sede" <?=$dis?>>
          <option value="0" <?= (int)($v('fuori_sede')??0)===0?'selected':'' ?>>No</option>
          <option value="1" <?= (int)($v('fuori_sede')??0)===1?'selected':'' ?>>Sì</option>
        </select></div>
      <div class="form-group"><label>Importo fuori sede (€ annui)</label>
        <input type="number" name="fuori_sede_amount" step="0.01" min="0" value="<?=h($v('fuori_sede_amount')??'')?>" <?=$dis?>></div>
    </div>
    <?php if ($can_edit && !$year_locked): ?>
      <div style="margin-top:12px"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva esercizio <?= $year ?></button></div>
    <?php endif; ?>
  </div>
</form>

<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-calculator"></i> Valori calcolati — esercizio <?= $year ?></span></div>
  <?php if (!empty($cst['errors'])): ?>
    <div class="alert alert-danger" style="font-size:12px">Alcune formule non sono valide e si è usata la definizione predefinita: <?=h(implode(' · ', array_keys($cst['errors'])))?></div>
  <?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
    <?php foreach ($cm->labelsCurrent() as $k => $lbl):
      $hi = in_array($k, ['tot_costo_tab','totale_fte_ca'], true); ?>
      <div style="padding:12px;border-radius:8px;background:<?=$hi?'#fee2e2':'#f8fafc'?>;border:1px solid <?=$hi?'#fca5a5':'#e2e8f0'?>">
        <div style="font-size:10px;color:var(--muted);font-weight:700"><?=h($lbl)?></div>
        <div style="font-size:16px;font-weight:800;color:<?=$hi?'#991b1b':'#0f172a'?>"><?=$eur($C[$k])?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:10px">
    <?php foreach ($cm->formulas() as $k => $expr): ?>
      <span style="margin-right:10px"><strong><?=h($cm->labelsCurrent()[$k] ?? $k)?></strong> = <code><?=h($expr)?></code></span>
    <?php endforeach; ?>
    <?php if (can('view','hr_reference_values.php')): ?>
      <br>Le formule sono modificabili in <a href="<?=url_safe('hr_reference_values')?>">Valori di riferimento HR</a>.
    <?php endif; ?>
  </p>
</div>
<?php require_once('footer.php'); ?>
