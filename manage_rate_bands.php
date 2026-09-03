<?php
/**
 * manage_rate_bands.php — Fasce costo orario (Aziendale/Cliente/Commerciale × Ordinario/Reperibilità)
 * Storicizzazione variazioni in hourly_rate_band_history. v1.7.59
 */
require_once('access_control.php');
require_once('functions.php');

if (!can('view', 'manage_rate_bands.php')) { redirect('manage_projects'); }
$can_edit = can('edit', 'manage_rate_bands.php') || can('create', 'manage_rate_bands.php');
$u_id = (int)$_SESSION['user_id'];

$TYPES   = ['Aziendale','Cliente','Commerciale'];
$REGIMES = ['Ordinario','Reperibilità'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg']="<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_band') {
        $name = trim($_POST['band_name'] ?? '');
        if ($name !== '') {
            $st = $pdo->prepare("INSERT IGNORE INTO cm_rate_bands (band_name) VALUES (?)");
            $st->execute([$name]);
            if ($st->rowCount() > 0) {
                $bid = (int)$pdo->lastInsertId();
                foreach ($TYPES as $t) foreach ($REGIMES as $r)
                    $pdo->prepare("INSERT IGNORE INTO cm_rate_band_rates (band_id,cost_type,regime,rate_hour) VALUES (?,?,?,0)")
                        ->execute([$bid,$t,$r]);
                write_log('RateBands','success',"Creata fascia $name",$u_id);
            }
        }
        redirect_self();
    }

    if ($action === 'save_rates') {
        $rates = $_POST['rate'] ?? []; // rate[band_id][type][regime] = value
        $changed = 0;
        foreach ($rates as $bid => $byType) {
            $bid = (int)$bid;
            foreach ($byType as $type => $byReg) {
                if (!in_array($type, $TYPES, true)) continue;
                foreach ($byReg as $reg => $val) {
                    if (!in_array($reg, $REGIMES, true)) continue;
                    $new = (float)str_replace(',', '.', (string)$val);
                    $st = $pdo->prepare("SELECT rate_hour FROM cm_rate_band_rates WHERE band_id=? AND cost_type=? AND regime=?");
                    $st->execute([$bid,$type,$reg]);
                    $old = $st->fetchColumn();
                    if ($old === false) {
                        $pdo->prepare("INSERT INTO cm_rate_band_rates (band_id,cost_type,regime,rate_hour) VALUES (?,?,?,?)")
                            ->execute([$bid,$type,$reg,$new]);
                        $old = null;
                    } elseif ((float)$old !== $new) {
                        $pdo->prepare("UPDATE cm_rate_band_rates SET rate_hour=? WHERE band_id=? AND cost_type=? AND regime=?")
                            ->execute([$new,$bid,$type,$reg]);
                    } else continue;
                    $bn = $pdo->prepare("SELECT band_name FROM cm_rate_bands WHERE id=?"); $bn->execute([$bid]);
                    $pdo->prepare("INSERT INTO cm_rate_band_history (band_id,band_name,cost_type,regime,action,old_rate,new_rate,changed_by)
                                   VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$bid,$bn->fetchColumn(),$type,$reg,'UPDATE',$old,$new,$u_id]);
                    $changed++;
                }
            }
        }
        write_log('RateBands','success',"Aggiornate $changed tariffe fasce",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Salvate $changed tariffe.</div>";
        redirect_self();
    }
    redirect_self();
}

$bands = $pdo->query("SELECT * FROM cm_rate_bands ORDER BY band_name")->fetchAll(PDO::FETCH_ASSOC);
$rateRows = $pdo->query("SELECT band_id,cost_type,regime,rate_hour FROM cm_rate_band_rates")->fetchAll(PDO::FETCH_ASSOC);
$R = [];
foreach ($rateRows as $r) $R[$r['band_id']][$r['cost_type']][$r['regime']] = $r['rate_hour'];
$hist = $pdo->query("SELECT * FROM cm_rate_band_history ORDER BY changed_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$msg='';
if (!empty($_SESSION['flash_msg'])) { $msg=$_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');
?>
<div class="page-header"><h1><i class="fa-solid fa-euro-sign"></i> Fasce Costo Orario</h1></div>
<?= $msg ?>

<?php if ($can_edit): ?>
<div class="card" style="margin-bottom:16px">
  <form method="post" style="display:flex;gap:10px;align-items:flex-end">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_band">
    <div class="form-group" style="margin:0"><label>Nuova fascia</label>
      <input type="text" name="band_name" placeholder="Es. Fascia G" required></div>
    <button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Aggiungi</button>
  </form>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_rates">
    <table class="data-table" style="width:100%">
      <thead>
        <tr><th rowspan="2">Fascia</th>
          <?php foreach ($TYPES as $t): ?><th colspan="2" style="text-align:center"><?=h($t)?></th><?php endforeach; ?>
        </tr>
        <tr><?php foreach ($TYPES as $t): foreach ($REGIMES as $r): ?><th style="text-align:center;font-weight:normal"><?=h($r)?></th><?php endforeach; endforeach; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($bands as $band): $bid=(int)$band['id']; ?>
        <tr>
          <td><strong><?=h($band['band_name'])?></strong></td>
          <?php foreach ($TYPES as $t): foreach ($REGIMES as $r): $v=$R[$bid][$t][$r] ?? '0.00'; ?>
            <td><input type="number" step="0.01" min="0" style="width:90px"
                 name="rate[<?=$bid?>][<?=h($t)?>][<?=h($r)?>]" value="<?=h($v)?>" <?=$can_edit?'':'readonly'?>></td>
          <?php endforeach; endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($can_edit): ?><button class="btn btn-success" style="margin-top:12px"><i class="fa-solid fa-floppy-disk"></i> Salva tariffe</button><?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Storico tariffe (ultime 100)</span></div>
  <table class="data-table" style="width:100%">
    <thead><tr><th>Data</th><th>Fascia</th><th>Tipologia</th><th>Regime</th><th>Vecchia</th><th>Nuova</th></tr></thead>
    <tbody>
    <?php if(!$hist): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">Nessuna variazione.</td></tr>
    <?php else: foreach ($hist as $hh): ?>
      <tr><td><?=h($hh['changed_at'])?></td><td><?=h($hh['band_name'])?></td><td><?=h($hh['cost_type'])?></td>
          <td><?=h($hh['regime'])?></td><td><?=h($hh['old_rate']??'—')?></td><td><?=h($hh['new_rate']??'—')?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php require_once('footer.php'); ?>
