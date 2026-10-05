<?php
/**
 * prj_parameters.php — Parametri dimensionamento (v1.10.01)
 *
 * Parametri GLOBALI dei Progetti PRJ (prj_key = 0), versionati: oneri, indennità H24, produttività di default,
 * postazione ed energia, zone, nearshore, dotazioni, costi di sede, overhead, fonti.
 * Ogni modifica apre una nuova versione con decorrenza (stessa decorrenza della versione vigente = rettifica);
 * i calcoli leggono il valore in vigore alla data del calcolo. Permessi: prj_parameters.php view / edit.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/PrjRepo.php');
require_once(__DIR__ . '/app/PrjUi.php');

if (!can('view', 'prj_parameters.php')) { redirect('dashboard'); }
$can_edit = can('edit', 'prj_parameters.php');
$u_id = (int)$_SESSION['user_id'];
$repo = new PrjRepo($pdo);

$ADD = [  // tabella => [campi chiave testuali, campi numerici]
    'cm_prj_param'     => ['label' => 'Parametro', 'key' => ['chiave' => 'Chiave', 'unita' => 'Unità', 'descrizione' => 'Descrizione'], 'num' => ['valore' => 'Valore']],
    'cm_prj_zone'      => ['label' => 'Zona', 'key' => ['nome' => 'Zona'], 'num' => ['indice_ral' => 'Indice RAL', 'affitto_mq_mese' => 'Affitto €/m²/mese']],
    'cm_prj_nearshore' => ['label' => 'Paese nearshore', 'key' => ['paese' => 'Paese'], 'num' => ['indice_ral' => 'Indice RAL', 'oneri_pct' => 'Oneri datore (0-1)']],
    'cm_prj_equipment' => ['label' => 'Dotazione', 'key' => ['voce' => 'Voce'], 'num' => ['prezzo' => 'Prezzo €', 'anni_ammortamento' => 'Anni ammortamento']],
    'cm_prj_site_cost' => ['label' => 'Costo di sede', 'key' => ['voce' => 'Voce'], 'num' => ['eur_mese' => '€/mese']],
    'cm_prj_overhead'  => ['label' => 'Overhead', 'key' => ['voce' => 'Voce'], 'num' => ['importo' => 'Importo €'], 'enum' => ['tipo' => ['fisso', 'per_fte']]],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    $a = (string)($_POST['action'] ?? '');
    try {
        if ($a === 'vsave') {
            $r = PrjUi::saveGrid($pdo, $repo, 0, $_POST, $u_id);
            if ($r['saved']) write_log('Commesse', 'info', "Parametri dimensionamento: {$r['saved']} righe versionate", $u_id);
            $_SESSION['flash_msg'] = PrjUi::flash($r);
        } elseif ($a === 'add' && isset($ADD[$_POST['table'] ?? ''])) {
            $t = $_POST['table']; $spec = $ADD[$t]; $d = ['prj_id' => null];
            foreach ($spec['key'] as $k => $l) $d[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $k === 'descrizione' ? 255 : 120) ?: null;
            foreach ($spec['num'] as $k => $l) { $v = PrjUi::num($_POST[$k] ?? ''); if ($v === null || $v < 0) throw new InvalidArgumentException("$l: valore non valido."); $d[$k] = $v; }
            foreach ($spec['enum'] ?? [] as $k => $ok) $d[$k] = in_array($_POST[$k] ?? '', $ok, true) ? $_POST[$k] : $ok[0];
            $first = array_key_first($spec['key']);
            if (!$d[$first]) throw new InvalidArgumentException($spec['key'][$first] . ' obbligatorio.');
            if ($t === 'cm_prj_param' && !preg_match('/^[a-z][a-z0-9_]{1,59}$/', (string)$d['chiave'])) throw new InvalidArgumentException('Chiave: solo minuscole, cifre e _.');
            $ex = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE prj_key = 0 AND `$first` = ? AND is_current = 1"); $ex->execute([$d[$first]]);
            if ($ex->fetchColumn()) throw new InvalidArgumentException("«{$d[$first]}» esiste già: modificarlo nella tabella.");
            $vf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['valid_from'] ?? '')) ? $_POST['valid_from'] : date('Y-m-d');
            $repo->insertVersioned($t, $d, $vf, $u_id, mb_substr(trim((string)($_POST['change_note'] ?? '')), 0, 200) ?: 'nuovo');
            write_log('Commesse', 'info', "Parametri dimensionamento: aggiunto {$spec['label']} «{$d[$first]}»", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>" . h($spec['label']) . " «" . h((string)$d[$first]) . "» aggiunto.</div>";
        } elseif ($a === 'close' && isset($ADD[$_POST['table'] ?? ''])) {
            $t = $_POST['table'];
            $st = $pdo->prepare("SELECT id FROM `$t` WHERE id = ? AND prj_key = 0 AND is_current = 1"); $st->execute([(int)($_POST['id'] ?? 0)]);
            if ($rid = (int)$st->fetchColumn()) {
                $repo->closeVersion($t, $rid, date('Y-m-d', strtotime('-1 day')), $u_id, 'dismesso');
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Voce dismessa da oggi (resta nello storico e nei calcoli con data precedente).</div>";
            }
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
    redirect_self();
}

$cur = fn(string $t, string $order) => $pdo->query("SELECT * FROM `$t` WHERE prj_key = 0 AND is_current = 1 ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
$data = [
    'cm_prj_param'     => $cur('cm_prj_param', 'chiave'),
    'cm_prj_zone'      => $cur('cm_prj_zone', 'indice_ral DESC'),
    'cm_prj_nearshore' => $cur('cm_prj_nearshore', 'paese'),
    'cm_prj_equipment' => $cur('cm_prj_equipment', 'id'),
    'cm_prj_site_cost' => $cur('cm_prj_site_cost', 'id'),
    'cm_prj_overhead'  => $cur('cm_prj_overhead', 'id'),
];
$src = $pdo->query("SELECT id, codice, titolo, url FROM cm_prj_source WHERE prj_key = 0 ORDER BY codice")->fetchAll(PDO::FETCH_ASSOC);
$srcBy = []; foreach ($src as $s) $srcBy[(int)$s['id']] = $s['codice'];
$hist = [];
$hT = $_GET['h'] ?? ''; $hE = (int)($_GET['e'] ?? 0);
if (isset($ADD[$hT]) && $hE) $hist = $repo->versions($hT, $hE);
$par = []; foreach ($data['cm_prj_param'] as $p) $par[$p['chiave']] = (float)$p['valore'];
$str = [];
foreach ($data['cm_prj_zone'] as $z) $str[$z['nome']] = PrjCalc::structuralPerFte($data['cm_prj_equipment'], $par, (float)$z['affitto_mq_mese']);

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');

$table = function (string $t, array $cols, string $title, string $icon) use ($data, $can_edit, $srcBy, $ADD) {
    ob_start(); ?>
    <div class="card" style="margin-bottom:14px;overflow-x:auto">
      <div class="card-header"><span class="card-title"><i class="fa-solid <?=$icon?>"></i> <?=h($title)?></span></div>
      <table class="data-table" style="width:100%;font-size:12px"><thead><tr>
        <?php foreach ($cols as $c => $l): ?><th <?=isset(PrjUi::EDITABLE[$t][$c]) && PrjUi::EDITABLE[$t][$c] !== 'text' ? 'style="text-align:right"' : ''?>><?=h($l)?></th><?php endforeach; ?>
        <th>Fonte</th><th>Versione</th><th></th></tr></thead><tbody>
      <?php foreach ($data[$t] as $r): ?><tr>
        <?php foreach ($cols as $c => $l): ?><td <?=isset(PrjUi::EDITABLE[$t][$c]) && PrjUi::EDITABLE[$t][$c] !== 'text' ? 'style="text-align:right"' : ''?>>
          <?= isset(PrjUi::EDITABLE[$t][$c]) ? PrjUi::input($t, (int)$r['id'], $c, $r[$c], $can_edit) : h((string)$r[$c]) ?></td><?php endforeach; ?>
        <td style="color:var(--muted);font-size:11px"><?=h($srcBy[(int)$r['source_id']] ?? '')?></td>
        <td style="color:var(--muted);font-size:11px"><a href="<?=url_safe('prj_parameters', ['h' => $t, 'e' => (int)$r['ent_id']])?>#storico">v<?=(int)$r['version_no']?></a> dal <?=h($r['valid_from'])?></td>
        <td><?php if ($can_edit): ?><button type="submit" form="close_<?=$t?>_<?=(int)$r['id']?>" class="btn btn-sm" title="Dismetti da oggi" style="padding:1px 6px">×</button><?php endif; ?></td>
      </tr><?php endforeach; ?></tbody></table>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="page-header"><h1><i class="fa-solid fa-sliders"></i> Parametri dimensionamento
  <?php if (can('view', 'manage_projects_prj.php')): ?><a class="btn btn-sm" style="float:right" href="<?=url_safe('manage_projects', ['view' => 'prj'])?>"><i class="fa-solid fa-compass-drafting"></i> Progetti PRJ</a><?php endif; ?></h1>
  <p style="color:var(--muted);font-size:13px">Parametri globali dei Progetti PRJ, versionati per data di decorrenza. Un progetto può avere valori propri che prevalgono su quelli globali.
  I calcoli usano il valore in vigore alla data del calcolo.</p></div>
<?= $msg ?>

<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="vsave">
<?= $table('cm_prj_param', ['chiave' => 'Chiave', 'descrizione' => 'Descrizione', 'valore' => 'Valore', 'unita' => 'Unità'], 'Parametri economici e di produttività', 'fa-percent') ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
  <div><?= $table('cm_prj_zone', ['nome' => 'Zona', 'indice_ral' => 'Indice RAL', 'affitto_mq_mese' => 'Affitto €/m²/mese'], 'Zone', 'fa-map-location-dot') ?></div>
  <div><?= $table('cm_prj_nearshore', ['paese' => 'Paese', 'indice_ral' => 'Indice RAL', 'oneri_pct' => 'Oneri datore (0-1)'], 'Nearshore (solo profili di supporto)', 'fa-earth-europe') ?></div>
  <div><?= $table('cm_prj_equipment', ['voce' => 'Voce', 'prezzo' => 'Prezzo €', 'anni_ammortamento' => 'Anni'], 'Dotazione per FTE', 'fa-laptop') ?></div>
  <div><?= $table('cm_prj_site_cost', ['voce' => 'Voce', 'eur_mese' => '€/mese'], 'Costi di sede', 'fa-building') ?>
       <?= $table('cm_prj_overhead', ['voce' => 'Voce', 'tipo' => 'Tipo', 'importo' => 'Importo €'], 'Overhead di progetto', 'fa-briefcase') ?></div>
</div>
<?php if ($can_edit) echo PrjUi::versionFields(); ?>
</form>
<?php if ($can_edit) foreach ($data as $t => $rows) foreach ($rows as $r): ?>
  <form method="post" id="close_<?=$t?>_<?=(int)$r['id']?>" style="display:none" onsubmit="return confirm('Dismettere la voce da oggi?')"><?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="table" value="<?=$t?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"></form>
<?php endforeach; ?>

<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-building-circle-check"></i> Costo strutturale per FTE (valori correnti)</span></div>
  <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Zona</th><th style="text-align:right">Dotazione</th><th style="text-align:right">Affitto</th><th style="text-align:right">Energia</th><th style="text-align:right">Ufficio</th><th style="text-align:right">Remoto</th></tr></thead><tbody>
  <?php foreach ($str as $z => $s): ?><tr><td><?=h($z)?></td><?php foreach (['dotazione', 'affitto', 'energia', 'ufficio', 'remoto'] as $k): ?><td style="text-align:right;<?=$k === 'ufficio' ? 'font-weight:700' : ''?>"><?=PrjUi::eur($s[$k])?></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table>
</div>

<?php if ($can_edit): ?>
<details class="pm-panel" style="margin-top:14px"><summary><i class="fa-solid fa-chevron-right pm-chev"></i> <i class="fa-solid fa-plus" style="color:#3b82f6"></i> Aggiungi una voce</summary><div class="pm-panel-body">
  <?php foreach ($ADD as $t => $spec): ?>
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px;border-bottom:1px solid #f1f5f9;padding-bottom:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="table" value="<?=$t?>">
    <strong style="font-size:12px;min-width:120px"><?=h($spec['label'])?></strong>
    <?php foreach ($spec['key'] as $k => $l): ?><div class="form-group" style="margin:0"><label style="font-size:11px"><?=h($l)?></label><input type="text" name="<?=$k?>" <?=$k === array_key_first($spec['key']) ? 'required' : ''?>></div><?php endforeach; ?>
    <?php foreach ($spec['num'] as $k => $l): ?><div class="form-group" style="margin:0"><label style="font-size:11px"><?=h($l)?></label><input type="text" name="<?=$k?>" required style="width:90px"></div><?php endforeach; ?>
    <?php foreach ($spec['enum'] ?? [] as $k => $ok): ?><div class="form-group" style="margin:0"><label style="font-size:11px"><?=h($k)?></label><select name="<?=$k?>"><?php foreach ($ok as $o): ?><option><?=h($o)?></option><?php endforeach; ?></select></div><?php endforeach; ?>
    <div class="form-group" style="margin:0"><label style="font-size:11px">Decorrenza</label><input type="date" name="valid_from" value="<?=date('Y-m-d')?>"></div>
    <button class="btn btn-sm">Aggiungi</button>
  </form>
  <?php endforeach; ?>
</div></details>
<?php endif; ?>

<div class="card" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-book"></i> Fonti globali</span></div>
  <table class="data-table" style="width:100%;font-size:12px"><tbody>
  <?php foreach ($src as $s): ?><tr><td style="width:160px"><strong><?=h($s['codice'])?></strong></td><td><?=h($s['titolo'])?></td><td><?php if ($s['url']): ?><a href="<?=h($s['url'])?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>

<?php if ($hist): ?>
<div class="card" id="storico" style="margin-top:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Storico versioni — <?=h($ADD[$hT]['label'])?></span></div>
  <table class="data-table" style="width:100%;font-size:12px"><thead><tr><th>Versione</th><th>Dal</th><th>Al</th><th>Valori</th><th>Nota</th><th>Registrata il</th></tr></thead><tbody>
  <?php foreach ($hist as $v): ?><tr style="<?=$v['is_current'] ? 'font-weight:700' : ''?>"><td>v<?=(int)$v['version_no']?></td><td><?=h($v['valid_from'])?></td><td><?=h((string)($v['valid_to'] ?? '—'))?></td>
    <td><?=h(implode(' · ', array_map(fn($k) => $k . ' ' . $v[$k], array_keys(array_merge($ADD[$hT]['key'], $ADD[$hT]['num'], $ADD[$hT]['enum'] ?? [])))))?></td>
    <td><?=h((string)$v['change_note'])?></td><td><?=h($v['created_at'])?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php require_once('footer.php'); ?>
