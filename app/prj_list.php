<?php
/**
 * app/prj_list.php — vista «Progetti PRJ» di Commesse / Progetti (manage_projects.php?view=prj)  (v1.10.01)
 *
 * Inclusa da manage_projects.php dopo il controllo di accesso. Permessi sul permesso virtuale
 * manage_projects_prj.php: view (elenco), create (Nuovo, Clona), export (XLSX).
 * Indicatori (FTE, costo aziendale totale, % canone) calcolati sullo scenario di riferimento alla data odierna.
 */
require_once __DIR__ . '/PrjRepo.php';
require_once __DIR__ . '/PrjUi.php';

if (!can('view', 'manage_projects_prj.php')) {
    $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Non hai accesso ai Progetti PRJ.</div>";
    redirect('manage_projects');
}
$can_create = can('create', 'manage_projects_prj.php');
$can_export = can('export', 'manage_projects_prj.php');
$u_id = (int)$_SESSION['user_id'];
$repo = new PrjRepo($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $a = (string)($_POST['action'] ?? '');
    try {
        if ($a === 'prj_create' && $can_create) {
            $nome = mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 200);
            if ($nome === '') { $_SESSION['flash_msg'] = "<div class='alert alert-warning'>Il nome del progetto è obbligatorio.</div>"; $_SESSION['prj_reopen'] = 1; redirect('manage_projects', ['view' => 'prj']); }
            $d = static fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
            $i = static fn($k) => ctype_digit((string)($_POST[$k] ?? '')) && (int)$_POST[$k] > 0 ? (int)$_POST[$k] : null;
            $newId = $repo->createPrj([
                'nome' => $nome, 'client_id' => $i('client_id'), 'client_raw' => mb_substr(trim((string)($_POST['client_raw'] ?? '')), 0, 180),
                'exec_company_id' => $i('exec_company_id'),
                'project_type' => in_array($_POST['project_type'] ?? '', PrjUi::TIPI, true) ? $_POST['project_type'] : null,
                'stato' => in_array($_POST['stato'] ?? '', PrjUi::STATI, true) ? $_POST['stato'] : 'Bozza',
                'responsabile_user_id' => $u_id, 'codice_gara' => mb_substr(trim((string)($_POST['codice_gara'] ?? '')), 0, 60),
                'cig' => mb_substr(trim((string)($_POST['cig'] ?? '')), 0, 20), 'stazione_appaltante' => mb_substr(trim((string)($_POST['stazione_appaltante'] ?? '')), 0, 200),
                'data_offerta' => $d('data_offerta'), 'start_date' => $d('start_date'), 'end_date' => $d('end_date'), 'note' => trim((string)($_POST['note'] ?? '')),
            ], $u_id);
            $code = $pdo->query("SELECT prj_code FROM cm_prj WHERE id = $newId")->fetchColumn();
            write_log('Commesse', 'success', "Progetto PRJ creato $code (#$newId)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Progetto <strong>" . h((string)$code) . "</strong> creato.</div>";
            redirect('prj_dashboard', ['id' => $newId]);
        }
        if ($a === 'prj_clone' && $can_create) {
            $src = (int)($_POST['prj_id'] ?? 0);
            $newId = $repo->clonePrj($src, $u_id, mb_substr(trim((string)($_POST['nome'] ?? '')), 0, 200));
            $code = $pdo->query("SELECT prj_code FROM cm_prj WHERE id = $newId")->fetchColumn();
            write_log('Commesse', 'success', "Progetto PRJ clonato: #$src -> $code (#$newId)", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Progetto clonato in <strong>" . h((string)$code) . "</strong> (stato Bozza, non collegato).</div>";
            redirect('prj_dashboard', ['id' => $newId]);
        }
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
    } catch (Throwable $e) {
        write_log('Commesse', 'error', 'Progetti PRJ: ' . $e->getMessage(), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Operazione non riuscita: " . h($e->getMessage()) . "</div>";
    }
    redirect('manage_projects', ['view' => 'prj']);
}

// ── Filtri ──
$dOk = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET[$k] ?? '') ? $_GET[$k] : '';
$arr = function ($v): array { if (is_string($v)) $v = $v === '' ? [] : explode(',', $v); return array_values(array_filter(array_map('strval', (array)$v), fn($x) => $x !== '')); };
$f = [
    'q'       => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'stati'   => array_values(array_intersect($arr($_GET['stato'] ?? []), PrjUi::STATI)),
    'company' => (int)($_GET['company'] ?? 0),
    'client'  => (int)($_GET['client'] ?? 0),
    'link'    => in_array($_GET['link'] ?? '', ['0', '1'], true) ? $_GET['link'] : '',
    'from'    => $dOk('from'), 'to' => $dOk('to'),
];
$w = ['1=1']; $args = [];
if ($f['q'] !== '') { $w[] = "(p.prj_code LIKE ? OR p.nome LIKE ? OR p.client_raw LIKE ? OR c.name LIKE ? OR p.cig LIKE ? OR p.codice_gara LIKE ? OR sp.project_code LIKE ?)"; array_push($args, ...array_fill(0, 7, '%' . $f['q'] . '%')); }
if ($f['stati']) { $w[] = "p.stato IN (" . implode(',', array_fill(0, count($f['stati']), '?')) . ")"; array_push($args, ...$f['stati']); }
if ($f['company']) { $w[] = "p.exec_company_id = ?"; $args[] = $f['company']; }
if ($f['client']) { $w[] = "p.client_id = ?"; $args[] = $f['client']; }
if ($f['link'] !== '') $w[] = $f['link'] === '1' ? "p.sp_project_id IS NOT NULL" : "p.sp_project_id IS NULL";
if ($f['from'] !== '') { $w[] = "COALESCE(p.end_date, p.start_date, DATE(p.created_at)) >= ?"; $args[] = $f['from']; }
if ($f['to'] !== '')   { $w[] = "COALESCE(p.start_date, DATE(p.created_at)) <= ?"; $args[] = $f['to']; }
$st = $pdo->prepare("SELECT p.*, COALESCE(c.name, p.client_raw) AS cliente, co.name AS societa, sp.project_code AS sp_code, sp.name AS sp_name,
                            s.nome AS scen_nome, (SELECT MAX(r.created_at) FROM cm_prj_calc_run r WHERE r.prj_id = p.id) AS ultimo_calcolo
                       FROM cm_prj p LEFT JOIN clients c ON c.id = p.client_id LEFT JOIN companies co ON co.id = p.exec_company_id
                       LEFT JOIN cm_projects sp ON sp.id = p.sp_project_id LEFT JOIN cm_prj_scenario s ON s.id = p.scenario_riferimento_id
                      WHERE " . implode(' AND ', $w) . " ORDER BY p.prj_code DESC");
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$today = date('Y-m-d');
foreach ($rows as &$r) {
    $r['_k'] = null; $r['_err'] = null;
    if ($r['scenario_riferimento_id']) {
        try { $r['_k'] = $repo->calc((int)$r['id'], (int)$r['scenario_riferimento_id'], $today)['totali']; }
        catch (Throwable $e) { $r['_err'] = $e->getMessage(); }
    }
}
unset($r);
$total = (int)$pdo->query("SELECT COUNT(*) FROM cm_prj")->fetchColumn();
$active = ($f['q'] !== '') + (bool)$f['stati'] + (bool)$f['company'] + (bool)$f['client'] + ($f['link'] !== '') + ($f['from'] !== '') + ($f['to'] !== '');
$qp = array_filter(['view' => 'prj', 'q' => $f['q'], 'stato' => implode(',', $f['stati']), 'company' => $f['company'] ?: '', 'client' => $f['client'] ?: '',
                    'link' => $f['link'], 'from' => $f['from'], 'to' => $f['to']], fn($v) => $v !== '' && $v !== null);

// ── Export XLSX ──
if (($_GET['export'] ?? '') === 'xlsx') {
    if (!$can_export) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Export non consentito.</div>"; redirect('manage_projects', ['view' => 'prj']); }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    $data = [['Codice PRJ', 'Nome', 'Cliente', 'Società esecutrice', 'Stato', 'Codice commessa SP', 'Commessa SP', 'Scenario di riferimento', 'FTE',
              'Costo aziendale totale (€)', 'Canone medio (€)', '% canone', 'Margine (€)', 'Inizio', 'Fine', 'Ultimo calcolo']];
    foreach ($rows as $r) {
        $k = $r['_k'];
        $data[] = [$r['prj_code'], $r['nome'], (string)$r['cliente'], (string)$r['societa'], $r['stato'], (string)$r['sp_code'], (string)$r['sp_name'], (string)$r['scen_nome'],
                   $k ? round($k['fte_totali'], 2) : '', $k ? round($k['costo_aziendale_totale'], 2) : '', $k ? round($k['canone_medio'], 2) : '',
                   $k && $k['pct_canone'] !== null ? round($k['pct_canone'] * 100, 1) : '', $k ? round($k['margine'], 2) : '',
                   (string)$r['start_date'], (string)$r['end_date'], (string)$r['ultimo_calcolo']];
    }
    $flt = [['Parametro', 'Valore']];
    foreach (['q' => 'Cerca', 'stato' => 'Stato', 'company' => 'Società (id)', 'client' => 'Cliente (id)', 'link' => 'Collegato a commessa SP', 'from' => 'Dal', 'to' => 'Al'] as $k => $l)
        if (isset($qp[$k])) $flt[] = [$l, $k === 'link' ? ($qp[$k] === '1' ? 'sì' : 'no') : (string)$qp[$k]];
    $flt[] = ['Indicatori calcolati al', date('d/m/Y')]; $flt[] = ['Generato il', date('d/m/Y H:i')];
    require_once dirname(__DIR__) . '/XlsxWriter.php';
    write_log('Commesse', 'info', 'Export Progetti PRJ: ' . count($rows) . ' righe', $u_id);
    $xw = new XlsxWriter(); $xw->addSheet('Progetti PRJ', $data); $xw->addSheet('Filtri', $flt); $xw->download('progetti_prj_' . date('Ymd_Hi') . '.xlsx');
    exit;
}

$clients   = $pdo->query("SELECT DISTINCT c.id, c.name FROM clients c WHERE c.is_active = 1 ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$reopen = !empty($_SESSION['prj_reopen']); unset($_SESSION['prj_reopen']);
$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once dirname(__DIR__) . '/header.php';
?>
<div class="page-header"><h1><i class="fa-solid fa-briefcase"></i> Commesse / Progetti</h1></div>
<div class="tabs" style="display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--border)">
  <?php if (can('view', 'manage_projects.php')): ?><a class="tab-btn" href="<?=url_safe('manage_projects')?>" style="text-decoration:none">Commesse SP</a><?php endif; ?>
  <a class="tab-btn active" href="<?=url_safe('manage_projects', ['view' => 'prj'])?>" style="text-decoration:none">Progetti PRJ</a>
</div>
<?= $msg ?>

<div class="pm-toolbar">
  <?php if ($can_create): ?><button type="button" class="btn btn-primary btn-sm" onclick="var d=document.getElementById('panelNewPrj'); d.open=!d.open;"><i class="fa-solid fa-plus"></i> Nuovo progetto</button><?php endif; ?>
  <?php if ($can_export): ?><a class="btn btn-success btn-sm" href="<?=url_safe('manage_projects', $qp + ['export' => 'xlsx'])?>"><i class="fa-solid fa-file-excel"></i> Esporta XLSX</a><?php endif; ?>
  <a class="btn btn-sm" href="<?=url_safe('prj_parameters')?>"><i class="fa-solid fa-sliders"></i> Parametri dimensionamento</a>
  <span class="pm-count"><strong><?=count($rows)?></strong> di <strong><?=$total?></strong> progetti<?=$active ? ' (filtrati)' : ''?></span>
</div>

<?php if ($can_create): ?>
<details class="pm-panel" id="panelNewPrj" <?=$reopen ? 'open' : ''?>>
  <summary><i class="fa-solid fa-chevron-right pm-chev"></i><i class="fa-solid fa-plus" style="color:#3b82f6"></i> Nuovo progetto PRJ
    <span class="pm-hint">il codice PRJ-AAAA-NNNN è generato dal portale; il collegamento alla commessa SP si fa dopo, dalla scheda</span></summary>
  <div class="pm-panel-body">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="prj_create">
      <div class="pm-grid">
        <div class="form-group" style="grid-column:span 2"><label>Nome *</label><input type="text" name="nome" maxlength="200" required placeholder="Es. Gara Managed Service 2027-2030"></div>
        <div class="form-group"><label>Cliente (anagrafica)</label><select name="client_id"><option value="">—</option><?php foreach ($clients as $c): ?><option value="<?=(int)$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Cliente (testo)</label><input type="text" name="client_raw" maxlength="180"></div>
        <div class="form-group"><label>Società esecutrice</label><select name="exec_company_id"><option value="">—</option><?php foreach ($companies as $cid => $cn): ?><option value="<?=(int)$cid?>"><?=h($cn)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Tipo</label><select name="project_type"><option value="">—</option><?php foreach (PrjUi::TIPI as $t): ?><option><?=h($t)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Stato</label><select name="stato"><?php foreach (PrjUi::STATI as $t): ?><option><?=h($t)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Stazione appaltante</label><input type="text" name="stazione_appaltante" maxlength="200"></div>
        <div class="form-group"><label>Codice gara</label><input type="text" name="codice_gara" maxlength="60"></div>
        <div class="form-group"><label>CIG</label><input type="text" name="cig" maxlength="20"></div>
        <div class="form-group"><label>Data offerta</label><input type="date" name="data_offerta"></div>
        <div class="form-group"><label>Inizio</label><input type="date" name="start_date"></div>
        <div class="form-group"><label>Fine</label><input type="date" name="end_date"></div>
        <div class="form-group" style="grid-column:span 3"><label>Note</label><input type="text" name="note"></div>
      </div>
      <button class="btn btn-primary btn-sm" style="margin-top:8px"><i class="fa-solid fa-check"></i> Crea e apri la scheda</button>
    </form>
  </div>
</details>
<?php endif; ?>

<details class="pm-panel" <?=$active ? 'open' : ''?>>
  <summary><i class="fa-solid fa-chevron-right pm-chev"></i><i class="fa-solid fa-filter" style="color:#3b82f6"></i> Filtri
    <?php if ($active): ?><span class="pm-badge"><?=$active?></span><?php endif; ?></summary>
  <div class="pm-panel-body">
    <form method="get"><?= route_slug_field('manage_projects') ?><input type="hidden" name="view" value="prj">
      <div class="pm-grid">
        <div class="form-group"><label>Cerca ovunque</label><input type="text" name="q" value="<?=h($f['q'])?>" placeholder="codice PRJ, nome, cliente, CIG, codice commessa"></div>
        <div class="form-group"><label>Stato <span class="pm-multi">(multipla)</span></label><select name="stato[]" multiple size="3" class="pm-ms" data-placeholder="Tutti">
          <?php foreach (PrjUi::STATI as $t): ?><option <?=in_array($t, $f['stati'], true) ? 'selected' : ''?>><?=h($t)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Società esecutrice</label><select name="company"><option value="">— tutte —</option><?php foreach ($companies as $cid => $cn): ?><option value="<?=(int)$cid?>" <?=$f['company'] === (int)$cid ? 'selected' : ''?>><?=h($cn)?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Cliente</label><select name="client"><option value="">— tutti —</option><?php foreach ($clients as $c): ?><option value="<?=(int)$c['id']?>" <?=$f['client'] === (int)$c['id'] ? 'selected' : ''?>><?=h($c['name'])?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Collegato a commessa SP</label><select name="link"><option value="">— indifferente —</option><option value="1" <?=$f['link'] === '1' ? 'selected' : ''?>>Sì</option><option value="0" <?=$f['link'] === '0' ? 'selected' : ''?>>No</option></select></div>
        <div class="form-group"><label>Periodo: dal</label><input type="date" name="from" value="<?=h($f['from'])?>"></div>
        <div class="form-group"><label>Periodo: al</label><input type="date" name="to" value="<?=h($f['to'])?>"></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Applica</button>
        <?php if ($active): ?><a class="btn btn-sm" href="<?=url_safe('manage_projects', ['view' => 'prj'])?>">Azzera</a><?php endif; ?></div>
    </form>
  </div>
</details>

<div class="card" style="overflow-x:auto">
  <table class="data-table" style="width:100%;font-size:12px;white-space:nowrap">
    <thead><tr><th>Codice PRJ</th><th>Nome</th><th>Cliente</th><th>Società esecutrice</th><th>Stato</th><th>Commessa SP</th><th>Scenario di riferimento</th>
      <th style="text-align:right">FTE</th><th style="text-align:right">Costo aziendale totale</th><th style="text-align:right">% canone</th><th>Ultimo calcolo</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="12" style="text-align:center;color:var(--muted);padding:20px"><?=$active ? 'Nessun progetto corrisponde ai filtri.' : 'Nessun progetto PRJ.'?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $k = $r['_k']; ?>
      <tr>
        <td><a href="<?=url_safe('prj_dashboard', ['id' => (int)$r['id']])?>" style="font-weight:700"><?=h($r['prj_code'])?></a></td>
        <td title="<?=h($r['nome'])?>"><?=h(mb_strimwidth($r['nome'], 0, 46, '…'))?></td>
        <td><?=h((string)($r['cliente'] ?? '—'))?></td><td><?=h((string)($r['societa'] ?? '—'))?></td>
        <td><span class="prj-badge" style="display:inline-block;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700;color:#fff;background:<?=PrjUi::statoColor($r['stato'])?>"><?=h($r['stato'])?></span></td>
        <td><?php if ($r['sp_code']): ?><a href="<?=url_safe('project_dashboard', ['id' => (int)$r['sp_project_id']])?>" title="<?=h((string)$r['sp_name'])?>"><i class="fa-solid fa-link"></i> <?=h($r['sp_code'])?></a><?php else: ?><span style="color:var(--muted)">non collegato</span><?php endif; ?></td>
        <td><?=h((string)($r['scen_nome'] ?? '—'))?></td>
        <td style="text-align:right"><?= $k ? PrjUi::n($k['fte_totali'], 1) : '—' ?></td>
        <td style="text-align:right"><?= $k ? PrjUi::k($k['costo_aziendale_totale']) : ($r['_err'] ? '<span title="' . h($r['_err']) . '" style="color:#d97706">n.d.</span>' : '—') ?></td>
        <td style="text-align:right;font-weight:700;color:<?= $k && $k['pct_canone'] !== null ? ($k['pct_canone'] > 1 ? '#dc2626' : '#16a34a') : 'inherit' ?>"><?= $k ? PrjUi::pct($k['pct_canone']) : '—' ?></td>
        <td><?= $r['ultimo_calcolo'] ? h(date('d/m/Y H:i', strtotime($r['ultimo_calcolo']))) : '—' ?></td>
        <td style="display:flex;gap:4px">
          <a class="btn btn-sm btn-blue" href="<?=url_safe('prj_dashboard', ['id' => (int)$r['id']])?>"><i class="fa-solid fa-compass-drafting"></i> Scheda</a>
          <?php if (can('edit', 'prj_link.php')): ?><a class="btn btn-sm" href="<?=url_safe('prj_dashboard', ['id' => (int)$r['id'], 'tab' => 'link'])?>" title="Collega / scollega commessa SP"><i class="fa-solid fa-link"></i></a><?php endif; ?>
          <?php if ($can_create): ?><form method="post" style="margin:0" onsubmit="var n=prompt('Nome del nuovo progetto','Copia di <?=h(addslashes($r['nome']))?>'); if(n===null) return false; this.nome.value=n; return true;">
            <?= csrf_field() ?><input type="hidden" name="action" value="prj_clone"><input type="hidden" name="prj_id" value="<?=(int)$r['id']?>"><input type="hidden" name="nome" value="">
            <button class="btn btn-sm" title="Clona il progetto"><i class="fa-solid fa-clone"></i></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">Indicatori calcolati alla data odierna sullo scenario di riferimento di ciascun progetto. I Progetti PRJ sono distinti dalle commesse SP sincronizzate dal gestionale e non vengono mai scritti tra le commesse.</p>
</div>
<?php require_once dirname(__DIR__) . '/footer.php'; ?>
