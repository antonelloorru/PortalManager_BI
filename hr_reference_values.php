<?php
/**
 * hr_reference_values.php — Valori di riferimento HR (v1.7.94)
 *
 * Gestione dei parametri globali usati nel calcolo del costo pieno e del valore
 * FTE (Moltiplicatore FC, ValoreTABP, Val.KM, OverHead Aziendale, Moltiplicatore
 * FTE). Valgono per tutti i dipendenti che non hanno un valore specifico.
 * Ogni modifica è storicizzata in hr_reference_history.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/CostModel.php');

if (!can('view', 'hr_reference_values.php')) { redirect('dashboard'); }
$u_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'save_formulas') {
    Csrf::verify();
    if (!can('edit', 'hr_reference_values.php')) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
        redirect_self();
    }
    $note    = trim((string)($_POST['note'] ?? '')) ?: null;
    $changed = 0;
    $cur = $pdo->query("SELECT ref_key, ref_value FROM hr_reference_values")->fetchAll(PDO::FETCH_KEY_PAIR);
    $upd  = $pdo->prepare("UPDATE hr_reference_values SET ref_value=?, updated_by=? WHERE ref_key=?");
    $hist = $pdo->prepare("INSERT INTO hr_reference_history (ref_key, old_value, new_value, note, changed_by) VALUES (?,?,?,?,?)");

    foreach ((array)($_POST['val'] ?? []) as $key => $raw) {
        if (!isset($cur[$key])) continue;
        $raw = str_replace(',', '.', trim((string)$raw));
        if ($raw === '' || !is_numeric($raw)) continue;
        $new = (float)$raw; $old = (float)$cur[$key];
        if (abs($new - $old) < 0.0000005) continue;   // nessuna variazione
        $upd->execute([$new, $u_id, $key]);
        $hist->execute([$key, $old, $new, $note, $u_id]);
        write_log('HR', 'success', "Valore di riferimento $key: $old -> $new", $u_id);
        $changed++;
    }
    $_SESSION['flash_msg'] = "<div class='alert alert-" . ($changed ? 'success' : 'warning') . "'>"
        . ($changed ? "Aggiornati <strong>$changed</strong> valori di riferimento; le variazioni sono state storicizzate."
                    : "Nessuna variazione da salvare.") . "</div>";
    redirect_self();
}

// v1.7.95: salvataggio delle formule di calcolo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_formulas') {
    Csrf::verify();
    if (!can('edit', 'hr_reference_values.php')) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
        redirect_self();
    }
    $note    = trim((string)($_POST['fnote'] ?? '')) ?: null;
    $changed = 0; $errs = [];
    $cur  = $pdo->query("SELECT formula_key, expression FROM hr_formulas")->fetchAll(PDO::FETCH_KEY_PAIR);
    $upd  = $pdo->prepare("UPDATE hr_formulas SET expression=?, updated_by=? WHERE formula_key=?");
    $hist = $pdo->prepare("INSERT INTO hr_formula_history (formula_key, old_expression, new_expression, note, changed_by) VALUES (?,?,?,?,?)");
    // nomi ammessi: variabili di input + i risultati già calcolati in ordine
    $names = CostModel::inputVars();
    foreach (array_keys($cur) as $k) $names[] = $k;

    foreach ((array)($_POST['formula'] ?? []) as $key => $expr) {
        if (!isset($cur[$key])) continue;
        $expr = trim((string)$expr);
        if ($expr === '' || $expr === trim((string)$cur[$key])) continue;
        if ($err = FormulaEval::validate($expr, $names)) { $errs[] = h($key) . ': ' . h($err); continue; }
        $upd->execute([$expr, $u_id, $key]);
        $hist->execute([$key, $cur[$key], $expr, $note, $u_id]);
        write_log('HR', 'success', "Formula $key aggiornata: {$cur[$key]} -> $expr", $u_id);
        $changed++;
    }
    $msg = $changed ? "Aggiornate <strong>$changed</strong> formule; le variazioni sono state storicizzate." : "Nessuna formula modificata.";
    if ($errs) $msg .= "<br>Non salvate per errore di sintassi: " . implode(' · ', $errs);
    $_SESSION['flash_msg'] = "<div class='alert alert-" . ($errs ? 'danger' : ($changed ? 'success' : 'warning')) . "'>$msg</div>";
    redirect_self();
}

$rows = $pdo->query("SELECT * FROM hr_reference_values ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$hist = $pdo->query(
    "SELECT h.*, CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,'')) AS autore
       FROM hr_reference_history h
       LEFT JOIN users u    ON u.id = h.changed_by
       LEFT JOIN employees e ON e.id = u.employee_id
      ORDER BY h.changed_at DESC, h.id DESC LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);
$can_edit = can('edit', 'hr_reference_values.php');
// v1.7.95: formule e relativo storico
$cmx = new CostModel($pdo);
$formulas = [];
try { $formulas = $pdo->query("SELECT * FROM hr_formulas ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$fhist = [];
try {
    $fhist = $pdo->query(
        "SELECT h.*, CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,'')) AS autore
           FROM hr_formula_history h
           LEFT JOIN users u     ON u.id = h.changed_by
           LEFT JOIN employees e ON e.id = u.employee_id
          ORDER BY h.changed_at DESC, h.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

require_once('header.php');
if (!empty($_SESSION['flash_msg'])) { echo $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$fmt = fn($v, $d) => number_format((float)$v, (int)$d, ',', '.');
?>
<div class="page-header">
  <h1><i class="fa-solid fa-sliders"></i> Valori di riferimento HR</h1>
  <p style="color:var(--muted);font-size:13px">Parametri globali usati nel calcolo del costo pieno e del valore FTE. Valgono per tutti i dipendenti che non hanno un valore specifico nella propria scheda. Ogni modifica viene storicizzata.</p>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-calculator"></i> Parametri correnti</span></div>
  <form method="post">
    <?= csrf_field() ?>
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Parametro</th><th style="width:160px">Valore</th><th>Descrizione</th><th style="width:170px">Ultimo aggiornamento</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:600"><?= h($r['label']) ?><br><small style="color:var(--muted)"><?= h($r['ref_key']) ?></small></td>
          <td>
            <?php if ($can_edit): ?>
              <input type="text" name="val[<?= h($r['ref_key']) ?>]" value="<?= h(rtrim(rtrim(number_format((float)$r['ref_value'], (int)$r['decimals'], '.', ''), '0'), '.') ?: '0') ?>" style="width:100%;text-align:right">
            <?php else: ?>
              <strong><?= $fmt($r['ref_value'], $r['decimals']) ?></strong>
            <?php endif; ?>
          </td>
          <td style="color:var(--muted)"><?= h($r['description'] ?? '—') ?></td>
          <td style="color:var(--muted);font-size:11px"><?= h($r['updated_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($can_edit): ?>
    <div style="display:flex;gap:10px;align-items:flex-end;margin-top:12px;flex-wrap:wrap">
      <div class="form-group" style="margin:0;flex:1;min-width:240px"><label>Motivo della modifica (opzionale, salvato nello storico)</label>
        <input type="text" name="note" placeholder="es. aggiornamento tabellare 2026"></div>
      <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva e storicizza</button>
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:8px">Usa il punto o la virgola come separatore decimale. Vengono registrate solo le voci effettivamente variate.</p>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-square-root-variable"></i> Formule di calcolo</span></div>
  <p style="color:var(--muted);font-size:12px;margin:4px 0 10px">
    Le formule sono modificabili e vengono applicate nella scheda Compensation. Sono ammessi numeri, gli operatori
    <code>+ - * /</code>, le parentesi e i nomi elencati sotto: nessun altro contenuto è eseguito.
    Ogni formula può usare i risultati di quelle che la precedono.
  </p>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:11px;margin-bottom:12px">
    <strong>Variabili di input:</strong>
    <?php foreach (CostModel::inputVars() as $v): ?><code style="margin-right:6px"><?=h($v)?></code><?php endforeach; ?>
    <br><strong>Risultati riutilizzabili:</strong>
    <?php foreach ($formulas as $f): ?><code style="margin-right:6px"><?=h($f['formula_key'])?></code><?php endforeach; ?>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_formulas">
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th style="width:170px">Valore calcolato</th><th>Formula</th><th style="width:150px">Aggiornata</th></tr></thead>
      <tbody>
      <?php if (!$formulas): ?>
        <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:14px">Formule non ancora inizializzate: applica la migrazione 1.7.95.</td></tr>
      <?php else: foreach ($formulas as $f): ?>
        <tr>
          <td style="font-weight:600"><?= h($f['label']) ?><br><small style="color:var(--muted)"><?= h($f['formula_key']) ?></small></td>
          <td>
            <?php if ($can_edit): ?>
              <input type="text" name="formula[<?= h($f['formula_key']) ?>]" value="<?= h($f['expression']) ?>" style="width:100%;font-family:monospace">
              <?php if (!empty($f['description'])): ?><small style="color:var(--muted)"><?= h($f['description']) ?></small><?php endif; ?>
            <?php else: ?>
              <code><?= h($f['expression']) ?></code>
            <?php endif; ?>
          </td>
          <td style="color:var(--muted);font-size:11px"><?= h($f['updated_at']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?php if ($can_edit && $formulas): ?>
    <div style="display:flex;gap:10px;align-items:flex-end;margin-top:12px;flex-wrap:wrap">
      <div class="form-group" style="margin:0;flex:1;min-width:240px"><label>Motivo della modifica (opzionale, salvato nello storico)</label>
        <input type="text" name="fnote" placeholder="es. ValoreFTE calcolato sul costo senza auto"></div>
      <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva formule e storicizza</button>
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:8px">Le formule con errori di sintassi non vengono salvate e viene segnalato il motivo. In caso di formula non valida a runtime, il calcolo ricade sulla definizione predefinita.</p>
    <?php endif; ?>
  </form>

  <?php if ($fhist): ?>
  <details style="margin-top:12px">
    <summary style="cursor:pointer;font-size:12px;color:var(--muted)">Storico modifiche alle formule (<?= count($fhist) ?>)</summary>
    <table class="data-table" style="width:100%;font-size:11px;margin-top:8px">
      <thead><tr><th>Data</th><th>Formula</th><th>Da</th><th>A</th><th>Motivo</th><th>Autore</th></tr></thead>
      <tbody>
      <?php foreach ($fhist as $x): ?>
        <tr>
          <td style="white-space:nowrap"><?= h($x['changed_at']) ?></td>
          <td><?= h($x['formula_key']) ?></td>
          <td style="color:#991b1b"><code><?= h($x['old_expression'] ?? '—') ?></code></td>
          <td style="color:#166534"><code><?= h($x['new_expression']) ?></code></td>
          <td style="color:var(--muted)"><?= h($x['note'] ?? '—') ?></td>
          <td style="color:var(--muted)"><?= h(trim((string)$x['autore']) ?: ('#' . (int)$x['changed_by'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Storico modifiche (ultime <?= count($hist) ?>)</span></div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Data</th><th>Parametro</th><th style="text-align:right">Da</th><th style="text-align:right">A</th><th>Motivo</th><th>Autore</th></tr></thead>
    <tbody>
    <?php if (!$hist): ?>
      <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:16px">Nessuna modifica registrata.</td></tr>
    <?php else: foreach ($hist as $x): ?>
      <tr>
        <td style="white-space:nowrap"><?= h($x['changed_at']) ?></td>
        <td><?= h($x['ref_key']) ?></td>
        <td style="text-align:right;color:#991b1b"><?= $x['old_value'] === null ? '—' : $fmt($x['old_value'], 5) ?></td>
        <td style="text-align:right;color:#166534;font-weight:700"><?= $fmt($x['new_value'], 5) ?></td>
        <td style="color:var(--muted)"><?= h($x['note'] ?? '—') ?></td>
        <td style="color:var(--muted)"><?= h(trim((string)$x['autore']) ?: ('#' . (int)$x['changed_by'])) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php require_once('footer.php'); ?>
