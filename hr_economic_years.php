<?php
/**
 * hr_economic_years.php — Gestione annualità economiche (v1.8.0)
 *
 * Catalogo degli esercizi (anni di competenza) dei dati economici. Consente di:
 *   • creare un nuovo esercizio (opzionalmente clonando i dati da un anno sorgente,
 *     utile per avviare la compilazione massiva del nuovo anno);
 *   • impostare l'esercizio corrente (default delle viste);
 *   • bloccare/sbloccare un esercizio (sola lettura);
 *   • eliminare un esercizio privo di dati.
 *
 * Riservato ad Amministratore, HR Director e Responsabile Finanziario.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/CostModel.php');

if (!can('view', 'hr_economic_years.php')) { redirect('dashboard'); }
$u_id     = (int)$_SESSION['user_id'];
$can_edit = can('edit', 'hr_economic_years.php');
$can_new  = can('create', 'hr_economic_years.php') || $can_edit;
$can_del  = can('delete', 'hr_economic_years.php');

/* ── POST handlers (PRG) ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_year') {
        if (!$can_new) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('hr_economic_years'); }
        $y = (int)($_POST['year'] ?? 0);
        if ($y < 2000 || $y > 2100) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Anno non valido.</div>"; redirect('hr_economic_years'); }
        $label = trim((string)($_POST['label'] ?? '')) ?: ('Esercizio ' . $y);
        $note  = trim((string)($_POST['note'] ?? '')) ?: null;
        $clone = (int)($_POST['clone_from'] ?? 0);

        $exists = $pdo->prepare("SELECT 1 FROM hr_economic_years WHERE year=?");
        $exists->execute([$y]);
        if ($exists->fetchColumn()) {
            $_SESSION['flash_msg'] = "<div class='alert alert-warning'>L'esercizio $y esiste già.</div>";
            redirect('hr_economic_years');
        }
        $pdo->prepare("INSERT INTO hr_economic_years (year, label, is_current, is_locked, note, created_by) VALUES (?,?,0,0,?,?)")
            ->execute([$y, $label, $note, $u_id]);

        $cloned = 0; $clonedRefs = 0;
        if ($clone > 0 && $clone !== $y) {
            // clona input economici per-dipendente (senza sovrascrivere eventuali già presenti)
            $cols = CostModel::INPUT_COLUMNS;
            $collist = implode(',', array_map(fn($c) => "`$c`", $cols));
            $st = $pdo->prepare("INSERT IGNORE INTO hr_employee_economics (employee_id, year, $collist, note, updated_by)
                                 SELECT employee_id, ?, $collist, note, ? FROM hr_employee_economics WHERE year=?");
            $st->execute([$y, $u_id, $clone]);
            $cloned = $st->rowCount();
            // clona valori di riferimento globali per il nuovo anno
            try {
                $stR = $pdo->prepare("INSERT IGNORE INTO hr_reference_values (ref_key, year, label, ref_value, decimals, description, updated_by)
                                      SELECT ref_key, ?, label, ref_value, decimals, description, ? FROM hr_reference_values WHERE year=?");
                $stR->execute([$y, $u_id, $clone]);
                $clonedRefs = $stR->rowCount();
            } catch (Throwable $e) {}
        }
        write_log('HR', 'success', "Creato esercizio economico $y" . ($clone ? " (clonato da $clone: $cloned dipendenti, $clonedRefs riferimenti)" : ''), $u_id);
        $msg = "Esercizio <strong>$y</strong> creato.";
        if ($clone > 0) $msg .= " Clonati <strong>$cloned</strong> record dipendente e <strong>$clonedRefs</strong> valori di riferimento dall'esercizio $clone.";
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>$msg</div>";
        redirect('hr_economic_years');
    }

    if ($action === 'set_current') {
        if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('hr_economic_years'); }
        $y = (int)($_POST['year'] ?? 0);
        $pdo->prepare("UPDATE hr_economic_years SET is_current = (year = ?)")->execute([$y]);
        write_log('HR', 'success', "Esercizio corrente impostato a $y", $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Esercizio corrente impostato a <strong>$y</strong>.</div>";
        redirect('hr_economic_years');
    }

    if ($action === 'toggle_lock') {
        if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('hr_economic_years'); }
        $y = (int)($_POST['year'] ?? 0);
        $pdo->prepare("UPDATE hr_economic_years SET is_locked = 1 - is_locked WHERE year=?")->execute([$y]);
        write_log('HR', 'success', "Cambiato stato blocco esercizio $y", $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Stato di blocco dell'esercizio <strong>$y</strong> aggiornato.</div>";
        redirect('hr_economic_years');
    }

    if ($action === 'delete_year') {
        if (!$can_del) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect('hr_economic_years'); }
        $y = (int)($_POST['year'] ?? 0);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM hr_employee_economics WHERE year=?");
        $cnt->execute([$y]);
        $isCur = $pdo->prepare("SELECT is_current FROM hr_economic_years WHERE year=?");
        $isCur->execute([$y]);
        if ((int)$cnt->fetchColumn() > 0) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Impossibile eliminare l'esercizio $y: contiene dati economici.</div>";
        } elseif ((int)$isCur->fetchColumn() === 1) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Impossibile eliminare l'esercizio corrente.</div>";
        } else {
            $pdo->prepare("DELETE FROM hr_economic_years WHERE year=?")->execute([$y]);
            try { $pdo->prepare("DELETE FROM hr_reference_values WHERE year=?")->execute([$y]); } catch (Throwable $e) {}
            write_log('HR', 'success', "Eliminato esercizio economico $y", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Esercizio <strong>$y</strong> eliminato.</div>";
        }
        redirect('hr_economic_years');
    }
}

/* ── Dati per la vista ───────────────────────────────────────────────────── */
$years = $pdo->query(
    "SELECT y.*, (SELECT COUNT(*) FROM hr_employee_economics ee WHERE ee.year=y.year) AS n_emp
       FROM hr_economic_years y ORDER BY y.year DESC"
)->fetchAll(PDO::FETCH_ASSOC);

require_once('header.php');
if (!empty($_SESSION['flash_msg'])) { echo $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$nextYear = ($years ? (int)$years[0]['year'] : (int)date('Y')) + 1;
?>
<div class="page-header">
  <h1><i class="fa-solid fa-calendar-days" style="color:#0891b2"></i> Annualità economiche</h1>
  <p style="color:var(--muted);font-size:13px">Catalogo degli esercizi (anni di competenza) dei dati economici. L'esercizio corrente è il default delle viste Finance e Compensation. Gli esercizi bloccati sono in sola lettura.</p>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-list"></i> Esercizi catalogati</span></div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr>
      <th style="width:80px">Anno</th><th>Etichetta</th><th style="text-align:right;width:110px">Dipendenti</th>
      <th style="width:90px">Corrente</th><th style="width:90px">Stato</th><th>Note</th><th style="width:230px"></th>
    </tr></thead>
    <tbody>
    <?php if (!$years): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:16px">Nessun esercizio catalogato.</td></tr>
    <?php else: foreach ($years as $y): $yy = (int)$y['year']; ?>
      <tr>
        <td style="font-weight:800;font-size:14px"><?= $yy ?></td>
        <td><?= h($y['label']) ?></td>
        <td style="text-align:right"><?= (int)$y['n_emp'] ?></td>
        <td>
          <?php if ((int)$y['is_current'] === 1): ?>
            <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700">CORRENTE</span>
          <?php elseif ($can_edit): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="set_current"><input type="hidden" name="year" value="<?= $yy ?>">
              <button class="btn btn-sm" title="Imposta come corrente"><i class="fa-solid fa-check"></i> imposta</button></form>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td>
          <?php if ((int)$y['is_locked'] === 1): ?>
            <span style="color:#92400e;font-weight:700"><i class="fa-solid fa-lock"></i> bloccato</span>
          <?php else: ?>
            <span style="color:#166534"><i class="fa-solid fa-lock-open"></i> aperto</span>
          <?php endif; ?>
        </td>
        <td style="color:var(--muted)"><?= h($y['note'] ?? '—') ?></td>
        <td style="white-space:nowrap">
          <?php if ($can_edit): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_lock"><input type="hidden" name="year" value="<?= $yy ?>">
              <button class="btn btn-sm" title="<?= (int)$y['is_locked'] ? 'Sblocca' : 'Blocca' ?>"><i class="fa-solid fa-<?= (int)$y['is_locked'] ? 'lock-open' : 'lock' ?>"></i></button></form>
          <?php endif; ?>
          <?php if ($can_del && (int)$y['is_current'] !== 1 && (int)$y['n_emp'] === 0): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Eliminare l\'esercizio <?= $yy ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_year"><input type="hidden" name="year" value="<?= $yy ?>">
              <button class="btn btn-sm" style="background:#fee2e2;color:#991b1b" title="Elimina"><i class="fa-solid fa-trash"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($can_new): ?>
<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-plus"></i> Nuovo esercizio</span></div>
  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="create_year">
    <div class="form-group" style="margin:0"><label>Anno</label>
      <input type="number" name="year" min="2000" max="2100" value="<?= $nextYear ?>" required style="width:110px"></div>
    <div class="form-group" style="margin:0;flex:1;min-width:200px"><label>Etichetta</label>
      <input type="text" name="label" placeholder="Esercizio <?= $nextYear ?>"></div>
    <div class="form-group" style="margin:0"><label>Clona dati da</label>
      <select name="clone_from">
        <option value="0">— nessuna clonazione —</option>
        <?php foreach ($years as $y): ?><option value="<?= (int)$y['year'] ?>"><?= (int)$y['year'] ?> (<?= (int)$y['n_emp'] ?> dip.)</option><?php endforeach; ?>
      </select></div>
    <div class="form-group" style="margin:0;flex:1;min-width:200px"><label>Note</label>
      <input type="text" name="note" placeholder="es. aggiornamento tabellare, budget…"></div>
    <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Crea esercizio</button>
  </form>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">La clonazione copia gli input economici di ogni dipendente e i valori di riferimento globali dall'esercizio scelto, così da partire da una base già compilata da rettificare per il nuovo anno. Non sovrascrive dati eventualmente già presenti nel nuovo esercizio.</p>
</div>
<?php endif; ?>
<?php require_once('footer.php'); ?>
