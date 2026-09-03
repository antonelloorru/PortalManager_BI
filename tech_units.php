<?php
/**
 * PortalManager — tech_units.php  (v1.8.48)
 *
 * Unita organizzative tecniche e relative sotto-unita.
 *
 * La tassonomia e' gestita qui, separatamente dall'anagrafica tecnica: le due
 * cose cambiano con ritmi diversi — le persone si spostano spesso, la struttura
 * organizzativa raramente — e tenerle separate evita che una riorganizzazione
 * debba passare per la scheda di ogni tecnico.
 *
 * Le unita non si eliminano quando sono in uso: si disattivano. Cancellare
 * un'unita a cui sono agganciati profili e storico renderebbe illeggibile
 * l'archivio delle assegnazioni passate.
 */
require_once('access_control.php');
require_once('functions.php');

if (!can('view', 'tech_units.php')) { redirect('dashboard'); }
$can_edit = can('edit', 'tech_units.php') || can('create', 'tech_units.php');
$can_del  = can('delete', 'tech_units.php');
$u_id     = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    $action = (string)($_POST['action'] ?? '');

    try {
        // ── Unita ───────────────────────────────────────────────────────────
        if ($action === 'unit_save') {
            $id   = (int)($_POST['id'] ?? 0);
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', trim((string)($_POST['code'] ?? ''))));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($code === '' || $name === '') throw new Exception('Codice e denominazione sono obbligatori.');
            $args = [
                $code, $name,
                trim((string)($_POST['description'] ?? '')) ?: null,
                trim((string)($_POST['color'] ?? '')) ?: null,
                isset($_POST['is_oncall']) ? 1 : 0,
                (int)($_POST['sort_order'] ?? 0),
                isset($_POST['is_active']) ? 1 : 0,
            ];
            if ($id) {
                $args[] = $id;
                $pdo->prepare("UPDATE cm_tech_units SET code=?,name=?,description=?,color=?,is_oncall=?,sort_order=?,is_active=? WHERE id=?")->execute($args);
                write_log('TechUnits','success',"Unita tecnica aggiornata: $name",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Unità <strong>".h($name)."</strong> aggiornata.</div>";
            } else {
                $args[] = $u_id;
                $pdo->prepare("INSERT INTO cm_tech_units (code,name,description,color,is_oncall,sort_order,is_active,created_by) VALUES (?,?,?,?,?,?,?,?)")->execute($args);
                write_log('TechUnits','success',"Unita tecnica creata: $name",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Unità <strong>".h($name)."</strong> creata.</div>";
            }
        }

        if ($action === 'unit_delete' && $can_del) {
            $id = (int)($_POST['id'] ?? 0);
            // il conteggio decide fra eliminazione e disattivazione
            $used = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM cm_tech_profiles WHERE unit_id=$id)
                                           + (SELECT COUNT(*) FROM cm_tech_history WHERE unit_id=$id)")->fetchColumn();
            if ($used > 0) {
                $pdo->prepare("UPDATE cm_tech_units SET is_active=0 WHERE id=?")->execute([$id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-warning'>L'unità è usata da $used fra profili e voci di storico: "
                    . "è stata <strong>disattivata</strong> anziché eliminata, così l'archivio resta leggibile.</div>";
            } else {
                $pdo->prepare("DELETE FROM cm_tech_subunits WHERE unit_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM cm_tech_units WHERE id=?")->execute([$id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Unità eliminata.</div>";
            }
            write_log('TechUnits','info',"Unita tecnica #$id rimossa o disattivata",$u_id);
        }

        // ── Sotto-unita ─────────────────────────────────────────────────────
        if ($action === 'sub_save') {
            $id     = (int)($_POST['id'] ?? 0);
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $name   = trim((string)($_POST['name'] ?? ''));
            if (!$unitId || $name === '') throw new Exception('Unità e denominazione sono obbligatorie.');
            $args = [$unitId, $name, trim((string)($_POST['description'] ?? '')) ?: null,
                     (int)($_POST['sort_order'] ?? 0), isset($_POST['is_active']) ? 1 : 0];
            if ($id) {
                $args[] = $id;
                $pdo->prepare("UPDATE cm_tech_subunits SET unit_id=?,name=?,description=?,sort_order=?,is_active=? WHERE id=?")->execute($args);
            } else {
                $pdo->prepare("INSERT INTO cm_tech_subunits (unit_id,name,description,sort_order,is_active) VALUES (?,?,?,?,?)")->execute($args);
            }
            write_log('TechUnits','success',"Sotto-unita salvata: $name",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Sotto-unità <strong>".h($name)."</strong> salvata.</div>";
        }

        if ($action === 'sub_delete' && $can_del) {
            $id = (int)($_POST['id'] ?? 0);
            $used = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM cm_tech_profiles WHERE subunit_id=$id)
                                           + (SELECT COUNT(*) FROM cm_tech_history WHERE subunit_id=$id)")->fetchColumn();
            if ($used > 0) {
                $pdo->prepare("UPDATE cm_tech_subunits SET is_active=0 WHERE id=?")->execute([$id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Sotto-unità in uso: <strong>disattivata</strong> anziché eliminata.</div>";
            } else {
                $pdo->prepare("DELETE FROM cm_tech_subunits WHERE id=?")->execute([$id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Sotto-unità eliminata.</div>";
            }
        }
    } catch (Throwable $e) {
        $dup = str_contains($e->getMessage(), 'Duplicate entry');
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>"
            . ($dup ? 'Esiste già una voce con questo codice o denominazione.' : h($e->getMessage())) . "</div>";
    }
    redirect_self();
}

$units = $pdo->query("SELECT u.*,
        (SELECT COUNT(*) FROM cm_tech_subunits s WHERE s.unit_id=u.id) AS n_sub,
        (SELECT COUNT(*) FROM cm_tech_profiles p WHERE p.unit_id=u.id AND p.is_active=1) AS n_tech
    FROM cm_tech_units u ORDER BY u.sort_order, u.name")->fetchAll(PDO::FETCH_ASSOC);
$subs = $pdo->query("SELECT s.*, (SELECT COUNT(*) FROM cm_tech_profiles p WHERE p.subunit_id=s.id AND p.is_active=1) AS n_tech
    FROM cm_tech_subunits s ORDER BY s.unit_id, s.sort_order, s.name")->fetchAll(PDO::FETCH_ASSOC);
$subsByUnit = [];
foreach ($subs as $s) $subsByUnit[(int)$s['unit_id']][] = $s;

$edit_unit = null; $edit_sub = null;
if (($eu = (int)($_GET['edit_unit'] ?? 0))) {
    foreach ($units as $u) if ((int)$u['id'] === $eu) $edit_unit = $u;
}
if (($es = (int)($_GET['edit_sub'] ?? 0))) {
    foreach ($subs as $s) if ((int)$s['id'] === $es) $edit_sub = $s;
}

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');
?>
<style>
.tu-panel { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; overflow:hidden }
.tu-panel > summary { list-style:none; cursor:pointer; padding:11px 14px; font-weight:700; font-size:13px;
  display:flex; align-items:center; gap:9px; background:#f8fafc; user-select:none }
.tu-panel > summary::-webkit-details-marker { display:none }
.tu-panel > summary:hover { background:#f1f5f9 }
.tu-panel[open] > summary { border-bottom:1px solid #e2e8f0 }
.tu-panel > summary .chev { transition:transform .15s ease; color:var(--muted); font-size:11px }
.tu-panel[open] > summary .chev { transform:rotate(90deg) }
.tu-body { padding:14px }
.tu-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px }
.tu-grid .form-group { margin:0 }
.tu-grid label { font-size:11px; color:#475569; font-weight:600 }
.tu-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; vertical-align:middle }
.tu-sub { font-size:12px; padding:3px 0 3px 22px; border-left:2px solid #e2e8f0; margin-left:6px }
.tu-off { opacity:.5 }
@media (max-width:900px){ .tu-grid { grid-template-columns:repeat(2,1fr) } }
</style>

<div class="page-header">
  <h1><i class="fa-solid fa-sitemap"></i> Unità Organizzative Tecniche</h1>
  <p style="color:var(--muted);font-size:13px">
    Tassonomia usata per classificare tecnici e professionisti nell'Anagrafica Tecnica.
    Le voci in uso non si eliminano ma si disattivano, così lo storico resta leggibile.
  </p>
</div>
<?= $msg ?>

<?php if ($can_edit): ?>
<details class="tu-panel" <?= $edit_unit ? 'open' : '' ?>>
  <summary><i class="fa-solid fa-chevron-right chev"></i><i class="fa-solid fa-plus" style="color:#3b82f6"></i>
    <?= $edit_unit ? 'Modifica unità: ' . h($edit_unit['name']) : 'Nuova unità organizzativa' ?></summary>
  <div class="tu-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="unit_save">
      <input type="hidden" name="id" value="<?= (int)($edit_unit['id'] ?? 0) ?>">
      <div class="tu-grid">
        <div class="form-group"><label>Codice *</label>
          <input type="text" name="code" required maxlength="40" value="<?=h($edit_unit['code'] ?? '')?>" placeholder="SIS_INFRA">
          <small style="color:var(--muted);font-size:10px">Solo lettere, cifre e underscore</small></div>
        <div class="form-group"><label>Denominazione *</label>
          <input type="text" name="name" required maxlength="120" value="<?=h($edit_unit['name'] ?? '')?>" placeholder="Sistemista Infrastruttura"></div>
        <div class="form-group"><label>Colore identificativo</label>
          <input type="color" name="color" value="<?=h($edit_unit['color'] ?? '#3b82f6')?>"></div>
        <div class="form-group"><label>Ordine di visualizzazione</label>
          <input type="number" name="sort_order" value="<?=(int)($edit_unit['sort_order'] ?? 0)?>"></div>
        <div class="form-group" style="grid-column:span 2"><label>Descrizione</label>
          <input type="text" name="description" maxlength="255" value="<?=h($edit_unit['description'] ?? '')?>"></div>
        <div class="form-group" style="display:flex;align-items:flex-end;gap:16px;grid-column:span 2">
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;padding-bottom:8px">
            <input type="checkbox" name="is_oncall" value="1" <?= !empty($edit_unit['is_oncall']) ? 'checked' : '' ?>>
            Unità di reperibilità</label>
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;padding-bottom:8px">
            <input type="checkbox" name="is_active" value="1" <?= (!$edit_unit || !empty($edit_unit['is_active'])) ? 'checked' : '' ?>>
            Attiva</label>
        </div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px">
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $edit_unit ? 'Salva modifiche' : 'Crea unità' ?></button>
        <?php if ($edit_unit): ?><a class="btn btn-sm" href="<?=url_safe('tech_units')?>">Annulla</a><?php endif; ?>
      </div>
    </form>
  </div>
</details>

<details class="tu-panel" <?= $edit_sub ? 'open' : '' ?>>
  <summary><i class="fa-solid fa-chevron-right chev"></i><i class="fa-solid fa-diagram-next" style="color:#0891b2"></i>
    <?= $edit_sub ? 'Modifica sotto-unità: ' . h($edit_sub['name']) : 'Nuova sotto-unità' ?></summary>
  <div class="tu-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="sub_save">
      <input type="hidden" name="id" value="<?= (int)($edit_sub['id'] ?? 0) ?>">
      <div class="tu-grid">
        <div class="form-group"><label>Unità di appartenenza *</label>
          <select name="unit_id" required>
            <option value="">— seleziona —</option>
            <?php foreach ($units as $u): ?>
              <option value="<?=(int)$u['id']?>" <?= (int)($edit_sub['unit_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?=h($u['name'])?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label>Denominazione *</label>
          <input type="text" name="name" required maxlength="120" value="<?=h($edit_sub['name'] ?? '')?>" placeholder="Es. Virtualizzazione"></div>
        <div class="form-group"><label>Ordine</label>
          <input type="number" name="sort_order" value="<?=(int)($edit_sub['sort_order'] ?? 0)?>"></div>
        <div class="form-group" style="display:flex;align-items:flex-end">
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;padding-bottom:8px">
            <input type="checkbox" name="is_active" value="1" <?= (!$edit_sub || !empty($edit_sub['is_active'])) ? 'checked' : '' ?>>
            Attiva</label></div>
        <div class="form-group" style="grid-column:span 4"><label>Descrizione</label>
          <input type="text" name="description" maxlength="255" value="<?=h($edit_sub['description'] ?? '')?>"></div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px">
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $edit_sub ? 'Salva modifiche' : 'Crea sotto-unità' ?></button>
        <?php if ($edit_sub): ?><a class="btn btn-sm" href="<?=url_safe('tech_units')?>">Annulla</a><?php endif; ?>
      </div>
    </form>
  </div>
</details>
<?php endif; ?>

<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-list"></i> Struttura</span>
    <span style="color:var(--muted);font-size:12px;margin-left:auto">
      <strong><?=count($units)?></strong> unità · <strong><?=count($subs)?></strong> sotto-unità
    </span>
  </div>
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr>
      <th style="width:34px"></th><th>Codice</th><th>Unità organizzativa</th><th>Descrizione</th>
      <th style="text-align:center">Reperibilità</th>
      <th style="text-align:right">Sotto-unità</th><th style="text-align:right">Tecnici</th>
      <th style="text-align:center">Stato</th><th style="width:80px"></th>
    </tr></thead>
    <tbody>
    <?php if (!$units): ?>
      <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:20px">
        Nessuna unità definita. La migration ne installa nove predefinite: se l'elenco è vuoto, non è stata eseguita.
      </td></tr>
    <?php else: foreach ($units as $u): $uid = (int)$u['id']; ?>
      <tr class="<?= $u['is_active'] ? '' : 'tu-off' ?>">
        <td><?php if (!empty($subsByUnit[$uid])): ?>
          <button type="button" class="btn btn-sm" onclick="var e=document.getElementById('sub-<?=$uid?>');e.style.display=e.style.display==='none'?'table-row':'none'">
            <i class="fa-solid fa-chevron-down"></i></button><?php endif; ?></td>
        <td><code><?=h($u['code'])?></code></td>
        <td><span class="tu-dot" style="background:<?=h($u['color'] ?: '#94a3b8')?>"></span><strong><?=h($u['name'])?></strong></td>
        <td style="color:var(--muted)"><?=h($u['description'] ?? '—')?></td>
        <td style="text-align:center"><?= (int)$u['is_oncall'] ? '<i class="fa-solid fa-phone-volume" style="color:#f59e0b" title="Unità di reperibilità"></i>' : '—' ?></td>
        <td style="text-align:right"><?=(int)$u['n_sub']?></td>
        <td style="text-align:right"><strong><?=(int)$u['n_tech']?></strong></td>
        <td style="text-align:center"><?= (int)$u['is_active']
            ? '<span style="color:#16a34a;font-weight:600">attiva</span>'
            : '<span style="color:#94a3b8">disattivata</span>' ?></td>
        <td><?php if ($can_edit): ?>
          <a class="btn btn-sm btn-blue" href="<?=url_safe('tech_units',['edit_unit'=>$uid])?>" title="Modifica"><i class="fa-solid fa-pen"></i></a>
          <?php if ($can_del): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Rimuovere l\'unità? Se è in uso verrà disattivata.')">
            <?= csrf_field() ?><input type="hidden" name="action" value="unit_delete">
            <input type="hidden" name="id" value="<?=$uid?>">
            <button class="btn btn-sm" title="Rimuovi o disattiva"><i class="fa-solid fa-trash"></i></button></form>
          <?php endif; ?>
        <?php endif; ?></td>
      </tr>
      <?php if (!empty($subsByUnit[$uid])): ?>
        <tr id="sub-<?=$uid?>" style="display:none;background:#f8fafc">
          <td colspan="9">
            <?php foreach ($subsByUnit[$uid] as $s): ?>
              <div class="tu-sub <?= $s['is_active'] ? '' : 'tu-off' ?>" style="display:flex;align-items:center;gap:10px">
                <i class="fa-solid fa-turn-up fa-rotate-90" style="color:#cbd5e1;font-size:10px"></i>
                <strong><?=h($s['name'])?></strong>
                <span style="color:var(--muted)"><?=h($s['description'] ?? '')?></span>
                <span style="color:var(--muted);font-size:11px"><?=(int)$s['n_tech']?> tecnici</span>
                <?php if (!$s['is_active']): ?><span style="color:#94a3b8;font-size:11px">(disattivata)</span><?php endif; ?>
                <?php if ($can_edit): ?>
                  <span style="margin-left:auto;display:flex;gap:4px">
                    <a class="btn btn-sm" href="<?=url_safe('tech_units',['edit_sub'=>(int)$s['id']])?>"><i class="fa-solid fa-pen"></i></a>
                    <?php if ($can_del): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Rimuovere la sotto-unità?')">
                      <?= csrf_field() ?><input type="hidden" name="action" value="sub_delete">
                      <input type="hidden" name="id" value="<?=(int)$s['id']?>">
                      <button class="btn btn-sm"><i class="fa-solid fa-trash"></i></button></form>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endif; ?>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    La colonna <strong>Tecnici</strong> conta i profili attivi assegnati all'unità.
    Le unità marcate come reperibilità sono quelle che comportano interventi fuori orario.
  </p>
</div>
<?php require_once('footer.php'); ?>
