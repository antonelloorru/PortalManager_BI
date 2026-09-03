<?php
/**
 * PortalManager — organigramma.php
 *
 * Organigramma grafico (SVG) delle unità organizzative, generato dalla gerarchia
 * departments.parent_id. Considera l'azienda di appartenenza dei dipendenti e si
 * genera in base a filtri (azienda, unità radice, includi sotto-categorie/dipendenti).
 * Esportabile in SVG (?export=svg) e modificabile in strumenti vettoriali; la
 * gerarchia è modificabile in "Dipartimenti / Unità Organizzative".
 */
require_once('access_control.php');
require_once('functions.php');

if (!can('view', 'manage_departments.php') && !can('view', 'organigramma.php')) {
    $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Accesso negato.</div>";
    redirect('manage_employees');
}

/* ── Filtri ─────────────────────────────────────────────────────────────── */
$company_id = ($_GET['company_id'] ?? '') !== '' ? (int)$_GET['company_id'] : 0;
$root_dep   = ($_GET['root'] ?? '') !== '' ? (int)$_GET['root'] : 0;
$inc_sub    = isset($_GET['sub'])  ? ($_GET['sub'] === '1')  : true;
$inc_emp    = isset($_GET['emp'])  ? ($_GET['emp'] === '1')  : false;

/* ── Dati ───────────────────────────────────────────────────────────────── */
$companies = $pdo->query("SELECT id,name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$company_name = '';
if ($company_id) { foreach ($companies as $c) if ((int)$c['id'] === $company_id) $company_name = $c['name']; }

$units = [];
foreach ($pdo->query("SELECT id,name,parent_id,value_type FROM departments WHERE is_active=1 ORDER BY name") as $u) {
    $units[(int)$u['id']] = ['id'=>(int)$u['id'], 'name'=>$u['name'], 'parent_id'=>$u['parent_id']!==null?(int)$u['parent_id']:null, 'value_type'=>$u['value_type']];
}
$childrenOf = [];
foreach ($units as $u) { $childrenOf[$u['parent_id'] ?? 0][] = $u['id']; }

// sotto-categorie per unità (con tipologia effettiva)
$subByDept = [];
if ($inc_sub) {
    foreach ($pdo->query("SELECT s.id,s.department_id,s.name,COALESCE(s.value_type,d.value_type) eff FROM department_subcategories s JOIN departments d ON d.id=s.department_id WHERE s.is_active=1 ORDER BY s.name") as $s) {
        $subByDept[(int)$s['department_id']][] = ['id'=>(int)$s['id'], 'name'=>$s['name'], 'eff'=>$s['eff']];
    }
}

// dipendenti (filtro azienda) -> conteggi per unità + eventuali nominativi
$where = "department_id IS NOT NULL"; $params = [];
if ($company_id) { $where .= " AND company_id=?"; $params[] = $company_id; }
$empStmt = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(last_name,''),' ',COALESCE(first_name,''))) nome, department_id, subcategory_id FROM employees WHERE $where ORDER BY last_name, first_name");
$empStmt->execute($params);
$countByDept = []; $empByDept = []; $empBySub = [];
foreach ($empStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $d = (int)$e['department_id']; $s = $e['subcategory_id'] !== null ? (int)$e['subcategory_id'] : 0;
    $countByDept[$d] = ($countByDept[$d] ?? 0) + 1;
    if ($inc_emp) { if ($s) $empBySub[$s][] = $e['nome'] ?: '(senza nome)'; else $empByDept[$d][] = $e['nome'] ?: '(senza nome)'; }
}

/* ── Costruzione albero ─────────────────────────────────────────────────── */
function og_unit_node(int $uid, array $units, array $childrenOf, array $countByDept, array $subByDept, array $empByDept, array $empBySub, bool $inc_sub, bool $inc_emp): array {
    $u = $units[$uid];
    $node = ['type'=>'unit', 'label'=>$u['name'], 'sub'=>$u['value_type'].'  ·  '.($countByDept[$uid] ?? 0).' dip.', 'children'=>[]];
    foreach (($childrenOf[$uid] ?? []) as $cid) {
        if (isset($units[$cid])) $node['children'][] = og_unit_node($cid, $units, $childrenOf, $countByDept, $subByDept, $empByDept, $empBySub, $inc_sub, $inc_emp);
    }
    if ($inc_sub) foreach (($subByDept[$uid] ?? []) as $sc) {
        $scNode = ['type'=>'subcat', 'label'=>$sc['name'], 'sub'=>$sc['eff'], 'children'=>[]];
        if ($inc_emp) foreach (($empBySub[$sc['id']] ?? []) as $nm) $scNode['children'][] = ['type'=>'emp','label'=>$nm,'sub'=>'','children'=>[]];
        $node['children'][] = $scNode;
    }
    if ($inc_emp) foreach (($empByDept[$uid] ?? []) as $nm) $node['children'][] = ['type'=>'emp','label'=>$nm,'sub'=>'','children'=>[]];
    return $node;
}

$topIds = [];
if ($root_dep && isset($units[$root_dep])) $topIds = [$root_dep];
else $topIds = $childrenOf[0] ?? []; // unità di vertice (parent_id NULL)

$rootLabel = $company_name !== '' ? $company_name : 'Organizzazione';
$rootSub   = $company_id ? 'Azienda' : 'Tutte le aziende';
$tree = ['type'=>'root', 'label'=>$rootLabel, 'sub'=>$rootSub, 'children'=>[]];
foreach ($topIds as $tid) if (isset($units[$tid])) $tree['children'][] = og_unit_node($tid, $units, $childrenOf, $countByDept, $subByDept, $empByDept, $empBySub, $inc_sub, $inc_emp);

/* ── Layout tidy (leaf-based) ───────────────────────────────────────────── */
$CONF = ['boxW'=>190, 'boxH'=>52, 'hGap'=>18, 'vGap'=>46, 'pad'=>30];
function og_layout(array &$node, int $depth, array &$cur, array $c): void {
    $node['y'] = $c['pad'] + $depth * ($c['boxH'] + $c['vGap']);
    if (empty($node['children'])) {
        $node['x'] = $cur['x']; $cur['x'] += $c['boxW'] + $c['hGap'];
    } else {
        foreach ($node['children'] as &$ch) og_layout($ch, $depth + 1, $cur, $c);
        $node['x'] = ($node['children'][0]['x'] + $node['children'][count($node['children'])-1]['x']) / 2;
    }
    $cur['maxX'] = max($cur['maxX'], $node['x'] + $c['boxW']);
    $cur['maxY'] = max($cur['maxY'], $node['y'] + $c['boxH']);
}
$cur = ['x'=>$CONF['pad'], 'maxX'=>0, 'maxY'=>0];
og_layout($tree, 0, $cur, $CONF);
$svgW = (int)($cur['maxX'] + $CONF['pad']);
$svgH = (int)($cur['maxY'] + $CONF['pad']);

/* ── Emissione SVG ──────────────────────────────────────────────────────── */
function og_esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function og_clip($s,$n){ $s=(string)$s; return function_exists('mb_strimwidth')?mb_strimwidth($s,0,$n,'…'):(strlen($s)>$n?substr($s,0,$n-1).'…':$s); }
function og_style(string $t): array {
    switch ($t) {
        case 'root':   return ['fill'=>'#1e3a8a','stroke'=>'#1e3a8a','text'=>'#ffffff','sub'=>'#c7d2fe'];
        case 'unit':   return ['fill'=>'#eff6ff','stroke'=>'#3b82f6','text'=>'#1e3a8a','sub'=>'#3b82f6'];
        case 'subcat': return ['fill'=>'#f0fdf4','stroke'=>'#22c55e','text'=>'#166534','sub'=>'#16a34a'];
        default:       return ['fill'=>'#f8fafc','stroke'=>'#94a3b8','text'=>'#334155','sub'=>'#64748b'];
    }
}
function og_render(array $node, array $c, array &$out): void {
    $st = og_style($node['type']);
    $x = $node['x']; $y = $node['y']; $w = $c['boxW']; $h = $c['boxH'];
    // connettori verso i figli
    foreach ($node['children'] as $ch) {
        $px = $x + $w/2; $py = $y + $h;
        $cx = $ch['x'] + $w/2; $cy = $ch['y'];
        $my = ($py + $cy) / 2;
        $out[] = '<path d="M'.$px.','.$py.' V'.$my.' H'.$cx.' V'.$cy.'" fill="none" stroke="#cbd5e1" stroke-width="1.5"/>';
    }
    // box
    $out[] = '<rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="8" ry="8" fill="'.$st['fill'].'" stroke="'.$st['stroke'].'" stroke-width="1.5"/>';
    $out[] = '<text x="'.($x + $w/2).'" y="'.($y + ($node['sub']!==''?20:30)).'" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="12" font-weight="700" fill="'.$st['text'].'">'.og_esc(og_clip($node['label'],26)).'</text>';
    if ($node['sub'] !== '') $out[] = '<text x="'.($x + $w/2).'" y="'.($y + 37).'" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="10" fill="'.$st['sub'].'">'.og_esc(og_clip($node['sub'],32)).'</text>';
    foreach ($node['children'] as $ch) og_render($ch, $c, $out);
}
$parts = [];
og_render($tree, $CONF, $parts);
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$svgW.' '.$svgH.'" width="'.$svgW.'" height="'.$svgH.'" font-family="Segoe UI, Arial, sans-serif">'
     . '<rect x="0" y="0" width="'.$svgW.'" height="'.$svgH.'" fill="#ffffff"/>'
     . implode('', $parts) . '</svg>';

/* ── Export SVG ─────────────────────────────────────────────────────────── */
if (($_GET['export'] ?? '') === 'svg') {
    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    if (!headers_sent()) {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="organigramma_' . date('Ymd_His') . '.svg"');
        header('Content-Length: ' . strlen($svg));
        header('Cache-Control: no-store, must-revalidate');
    }
    echo $svg;
    exit;
}

require_once('header.php');
$qbase = function (array $o = []) use ($company_id, $root_dep, $inc_sub, $inc_emp) {
    $q = array_merge(['company_id'=>$company_id ?: '', 'root'=>$root_dep ?: '', 'sub'=>$inc_sub?'1':'0', 'emp'=>$inc_emp?'1':'0'], $o);
    return url_safe('organigramma', array_filter($q, fn($v)=>$v!=='' && $v!==null));
};
?>
<div class="container" style="max-width:100%">
  <h1 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap"><i class="fa-solid fa-diagram-project"></i> Organigramma
    <a class="btn btn-sm" style="margin-left:auto" href="<?=url_safe('manage_departments')?>"><i class="fa-solid fa-sitemap"></i> Gestisci gerarchia</a>
    <a class="btn btn-sm btn-success" href="<?=$qbase(['export'=>'svg'])?>"><i class="fa-solid fa-download"></i> Esporta SVG</a>
  </h1>

  <div class="card" style="margin-bottom:14px">
    <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <?= route_slug_field() ?>
      <div class="form-group" style="margin:0;min-width:200px"><label>Azienda</label>
        <select name="company_id">
          <option value="">— Tutte le aziende —</option>
          <?php foreach ($companies as $c): ?><option value="<?=(int)$c['id']?>" <?=$company_id===(int)$c['id']?'selected':''?>><?=h($c['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;min-width:200px"><label>Unità radice</label>
        <select name="root">
          <option value="">— Tutte (vertice) —</option>
          <?php foreach ($units as $u): ?><option value="<?=(int)$u['id']?>" <?=$root_dep===(int)$u['id']?'selected':''?>><?=h($u['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0"><label>Sotto-categorie</label>
        <select name="sub"><option value="1" <?=$inc_sub?'selected':''?>>Includi</option><option value="0" <?=!$inc_sub?'selected':''?>>Escludi</option></select>
      </div>
      <div class="form-group" style="margin:0"><label>Dipendenti</label>
        <select name="emp"><option value="0" <?=!$inc_emp?'selected':''?>>Escludi</option><option value="1" <?=$inc_emp?'selected':''?>>Includi</option></select>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fa-solid fa-arrows-rotate"></i> Genera</button>
    </form>
    <div style="margin-top:8px;font-size:11px;color:var(--muted)">
      <span style="display:inline-block;width:11px;height:11px;background:#1e3a8a;border-radius:2px;vertical-align:middle"></span> Azienda/Organizzazione
      &nbsp; <span style="display:inline-block;width:11px;height:11px;background:#eff6ff;border:1px solid #3b82f6;border-radius:2px;vertical-align:middle"></span> Unità
      &nbsp; <span style="display:inline-block;width:11px;height:11px;background:#f0fdf4;border:1px solid #22c55e;border-radius:2px;vertical-align:middle"></span> Sotto-categoria
      &nbsp; <span style="display:inline-block;width:11px;height:11px;background:#f8fafc;border:1px solid #94a3b8;border-radius:2px;vertical-align:middle"></span> Dipendente
      &nbsp;·&nbsp; L'SVG esportato è modificabile in strumenti vettoriali (Inkscape, Illustrator). La gerarchia si modifica in "Gestisci gerarchia".
    </div>
  </div>

  <div class="card" style="overflow:auto;max-height:75vh">
    <?php if (empty($tree['children'])): ?>
      <div class="alert" style="margin:0">Nessuna unità organizzativa da mostrare con i filtri selezionati. Definisci l'unità superiore delle unità in "Gestisci gerarchia".</div>
    <?php else: ?>
      <div style="min-width:<?=$svgW?>px"><?= $svg ?></div>
    <?php endif; ?>
  </div>
</div>
<?php require_once('footer.php'); ?>
