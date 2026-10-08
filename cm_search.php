<?php
/**
 * cm_search.php — Gestione Commesse › Ricerca (v1.10.24)
 *
 * Vista tabellare filtrabile su tutti gli archivi del modulo Commesse (13 ambiti, app/CmSearch.php) con le
 * correlazioni del modulo (commessa, cliente, società esecutrice, risorsa) ed export CSV / XLSX / DOCX / PDF
 * dei risultati filtrati.
 *
 *   - «Tutto il database»: conteggio e anteprima delle corrispondenze in ogni ambito visibile all'utente;
 *   - ambito: filtri comuni + filtro per ogni colonna (sintassi in CmSearch), scelta delle colonne,
 *     ordinamento su ogni colonna, totali delle colonne numeriche, paginazione;
 *   - export: stesse colonne, filtri e ordinamento della vista (can export su cm_search.php).
 *
 * Permessi: cm_search.php (view, export) · cm_search_economics.php (view: importi, costi, ricavi, margini) ·
 * ogni ambito richiede la vista della pagina sorgente (CmSearch::registry, gate).
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/CmSearch.php');
require_once(__DIR__ . '/app/PmReport.php');

if (!can('view', 'cm_search.php')) { redirect('dashboard'); }
$u_id       = (int)$_SESSION['user_id'];
$can_export = can('export', 'cm_search.php');
$S          = new CmSearch($pdo, can('view', 'cm_search_economics.php'));
$allowed    = $S->allowed('can');

$ds = (string)($_GET['ds'] ?? 'tutto');
if (!isset($allowed[$ds])) $ds = 'tutto';
$d  = $ds === 'tutto' ? null : $allowed[$ds];
$p  = CmSearch::params($_GET, $d);

$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$url = fn(array $over = [], ?string $dsx = null) => url_safe('cm_search', CmSearch::query($dsx ?? $ds, $p, $dsx === null || $dsx === $ds ? $d : null, $over));

// ── Export (prima di header.php) ────────────────────────────────────────────
$fmt = strtolower((string)($_GET['export'] ?? ''));
if ($fmt !== '') {
    if (!$can_export || !$d || !isset(PmReport::FORMATS[$fmt])) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Export non consentito o formato non valido.</div>";
        redirect('cm_search', CmSearch::query($ds, $p, $d));
    }
    @set_time_limit(300);
    @ini_set('memory_limit', '1024M');
    $out = $S->report($ds, $d, $p, $fmt, $companies);
    write_log('CmSearch', 'info', "Export ricerca {$ds} ({$fmt}): {$out['rows']} righe di {$out['n']}", $u_id,
        ['ambito' => $ds, 'formato' => $fmt, 'righe' => $out['rows'], 'trovate' => $out['n'], 'colonne' => implode(',', $p['c'])]);
    $out['report']->send($fmt, $out['file']);
    exit;
}

// ── Dati della vista ────────────────────────────────────────────────────────
$t0 = microtime(true);
$bad = []; $res = null; $overview = [];
$anyFilter = $p['q'] !== '' || $p['commessa'] !== '' || $p['cliente'] !== '' || $p['persona'] !== '' || $p['societa'] || $p['da'] !== '' || $p['a'] !== '';
if ($d) {
    $args = [];
    $where = $S->where($d, $p, $args, $bad);
    $sum   = $S->summary($d, $where, $args, $p['c']);
    $pages = max(1, (int)ceil($sum['n'] / $p['per']));
    if ($p['pg'] > $pages) $p['pg'] = $pages;
    $rows  = $S->rows($d, $where, $args, $p['c'], $p['s'], $p['d'], $p['per'], ($p['pg'] - 1) * $p['per']);
    $res   = ['n' => $sum['n'], 'tot' => $sum['tot'], 'rows' => $rows, 'pages' => $pages];
} else {
    foreach ($allowed as $k => $dx) {
        $args = []; $b = [];
        $w = $S->where($dx, $p, $args, $b, true);
        if ($w === null) { $overview[$k] = null; continue; }
        $sm = $S->summary($dx, $w, $args);
        $prev = [];
        if ($anyFilter && $sm['n'] > 0) {
            $pc = array_slice(CmSearch::defaultCols($dx), 0, 4);
            $prev = ['cols' => $pc, 'rows' => $S->rows($dx, $w, $args, $pc, $dx['sort'][0], $dx['sort'][1], 3)];
        }
        $overview[$k] = ['n' => $sm['n'], 'prev' => $prev];
    }
}
$elapsed = microtime(true) - $t0;

$links = [];
foreach (['project_dashboard' => 'project_dashboard.php', 'prj_dashboard' => 'prj_dashboard.php'] as $pg => $perm) $links[$pg] = can('view', $perm);

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

$GLOBALS['PM_NO_AUTOFILTER'] = true;
require_once('header.php');

$relLabels = CmSearch::REL + ['societa' => 'Società', 'periodo' => 'Periodo'];
?>
<style>
.cms-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 14px}
.cms-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:1px solid #e2e8f0;border-radius:999px;background:#fff;color:#334155;font-size:12px;font-weight:600;text-decoration:none}
.cms-tab:hover{background:#f1f5f9}
.cms-tab.on{background:#1e293b;border-color:#1e293b;color:#fff}
.cms-tab .n{font-weight:700;opacity:.75}
.cms-panel{border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:12px 14px;margin-bottom:12px}
.cms-grid{display:grid;grid-template-columns:2fr repeat(3,1fr) 1fr 1fr 1fr;gap:10px;align-items:end}
.cms-grid .form-group{margin:0}
.cms-grid label{font-size:11px;color:#475569;font-weight:600}
.cms-grid input,.cms-grid select{width:100%}
.cms-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}
.cms-actions .sp{margin-left:auto;color:var(--muted);font-size:12px}
.cms-cols{margin-top:8px;font-size:12px}
.cms-cols summary{cursor:pointer;color:#334155;font-weight:600}
.cms-cols .list{display:flex;flex-wrap:wrap;gap:4px 14px;padding:8px 0 2px}
.cms-cols label{display:flex;gap:5px;align-items:center;font-weight:400}
.cms-help{font-size:11.5px;color:#475569;line-height:1.55;margin:6px 0 0}
.cms-help code{background:#f1f5f9;border-radius:4px;padding:0 4px}
.cms-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 10px}
.cms-bar .cnt{font-size:13px;color:#334155}
.cms-bar .cnt strong{font-size:15px}
.cms-bar .exp{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.cms-wrap{overflow:auto;max-height:70vh;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
.cms-t{border-collapse:separate;border-spacing:0;width:100%;font-size:12px}
.cms-t th,.cms-t td{padding:6px 9px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:top;white-space:nowrap}
.cms-t td.long{white-space:normal;min-width:260px;max-width:420px}
.cms-t td.num,.cms-t th.num{text-align:right;font-variant-numeric:tabular-nums}
.cms-t thead th{position:sticky;top:0;background:#1e293b;color:#fff;font-weight:600;z-index:2}
.cms-t thead tr.flt th{top:31px;background:#f8fafc;padding:4px 5px;z-index:2;border-bottom:1px solid #e2e8f0}
.cms-t thead th a{color:inherit;text-decoration:none;display:inline-flex;gap:5px;align-items:center}
.cms-t thead th a .ar{opacity:.45;font-size:10px}
.cms-t thead th a.on .ar{opacity:1;color:#fbbf24}
.cms-t .flt input{width:100%;min-width:70px;font-size:11px;padding:3px 6px;border:1px solid #cbd5e1;border-radius:5px}
.cms-t .flt input.set{border-color:#2563eb;background:#eff6ff}
.cms-t .flt input.bad{border-color:#dc2626;background:#fef2f2}
.cms-t tbody tr:nth-child(even) td{background:#fcfdfe}
.cms-t tbody tr:hover td{background:#f1f5f9}
.cms-t tfoot td{position:sticky;bottom:0;background:#e2e8f0;font-weight:700;border-top:1px solid #cbd5e1}
.cms-t a.lk{color:#1d4ed8;text-decoration:none;font-weight:600}
.cms-t a.lk:hover{text-decoration:underline}
.cms-empty{padding:28px;text-align:center;color:var(--muted)}
.cms-pag{display:flex;flex-wrap:wrap;gap:4px;align-items:center;margin:10px 0}
.cms-pag a,.cms-pag span{padding:3px 9px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;text-decoration:none;color:#334155;background:#fff}
.cms-pag span.on{background:#1e293b;color:#fff;border-color:#1e293b}
.cms-pag span.gap{border:0;background:none}
.cms-ov{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(300px,100%),1fr));gap:12px}
.cms-card{border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:12px 14px;display:flex;flex-direction:column;gap:8px;min-width:0;overflow:hidden}
.cms-card h3{margin:0;font-size:14px;display:flex;gap:8px;align-items:center}
.cms-card h3 i{color:#2563eb}
.cms-card .big{font-size:22px;font-weight:800;color:#0f172a}
.cms-card .big.zero{color:#94a3b8}
.cms-card .na{font-size:12px;color:#94a3b8}
.cms-card table{width:100%;font-size:11px;border-collapse:collapse;table-layout:fixed}
.cms-card td{padding:3px 4px;border-top:1px solid #f1f5f9;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cms-card .go{margin-top:auto;align-self:flex-start}
.cms-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:8px 12px;font-size:12px;margin-bottom:10px}
@media (max-width:1100px){.cms-grid{grid-template-columns:repeat(2,1fr)}.cms-grid .q{grid-column:1/-1}}
@media print{.cms-panel,.cms-tabs,.cms-bar .exp,.cms-pag,.cms-t .flt{display:none}.cms-wrap{max-height:none;overflow:visible}}
</style>

<div class="page-header">
  <h1><i class="fa-solid fa-magnifying-glass"></i> Ricerca</h1>
  <p style="margin:2px 0 0;color:var(--muted);font-size:13px">Tutti gli archivi di Gestione Commesse in una vista filtrabile, con le stesse correlazioni del modulo (commessa, cliente, società, risorsa).</p>
</div>
<?= $msg ?>

<nav class="cms-tabs" aria-label="Ambiti di ricerca">
  <a class="cms-tab <?= $ds === 'tutto' ? 'on' : '' ?>" href="<?= $url(['f' => null, 'c' => null, 's' => null, 'd' => null, 'pg' => null], 'tutto') ?>"><i class="fa-solid fa-database"></i> Tutto il database</a>
  <?php foreach ($allowed as $k => $dx): ?>
    <a class="cms-tab <?= $ds === $k ? 'on' : '' ?>" href="<?= $url(['f' => null, 'c' => null, 's' => null, 'd' => null, 'pg' => null], $k) ?>">
      <i class="fa-solid <?= h($dx['icon']) ?>"></i> <?= h($dx['label']) ?>
      <?php if ($ds === 'tutto' && isset($overview[$k])): ?><span class="n"><?= number_format($overview[$k]['n'], 0, ',', '.') ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<form method="get" id="cmsForm" class="cms-panel" autocomplete="off">
  <?= route_slug_field() ?>
  <input type="hidden" name="ds" value="<?= h($ds) ?>">
  <?php if ($d && ($p['s'] !== $d['sort'][0] || $p['d'] !== $d['sort'][1])): ?>
    <input type="hidden" name="s" value="<?= h($p['s']) ?>"><input type="hidden" name="d" value="<?= h($p['d']) ?>">
  <?php endif; ?>
  <div class="cms-grid">
    <div class="form-group q"><label for="cms-q">Cerca ovunque</label>
      <input type="search" id="cms-q" name="q" value="<?= h($p['q']) ?>" placeholder="codice, nome, cliente, ticket, descrizioni…" autofocus></div>
    <div class="form-group"><label for="cms-commessa">Commessa</label>
      <input type="text" id="cms-commessa" name="commessa" value="<?= h($p['commessa']) ?>" placeholder="codice o nome"></div>
    <div class="form-group"><label for="cms-cliente">Cliente</label>
      <input type="text" id="cms-cliente" name="cliente" value="<?= h($p['cliente']) ?>"></div>
    <div class="form-group"><label for="cms-persona">Risorsa / persona</label>
      <input type="text" id="cms-persona" name="persona" value="<?= h($p['persona']) ?>" placeholder="cognome, nome, email"></div>
    <div class="form-group"><label for="cms-societa">Società esecutrice</label>
      <select id="cms-societa" name="societa"><option value="">tutte</option>
        <?php foreach ($companies as $id => $nm): ?><option value="<?= (int)$id ?>" <?= $p['societa'] === (int)$id ? 'selected' : '' ?>><?= h($nm) ?></option><?php endforeach; ?>
      </select></div>
    <div class="form-group"><label for="cms-da">Dal</label><input type="date" id="cms-da" name="da" value="<?= h($p['da']) ?>"></div>
    <div class="form-group"><label for="cms-a">Al</label><input type="date" id="cms-a" name="a" value="<?= h($p['a']) ?>"></div>
  </div>

  <?php if ($d): ?>
  <details class="cms-cols" <?= $p['c'] !== CmSearch::defaultCols($d) ? 'open' : '' ?>>
    <summary><i class="fa-solid fa-table-columns"></i> Colonne (<?= count($p['c']) ?> di <?= count(CmSearch::visibleCols($d)) ?>)</summary>
    <div class="list">
      <?php foreach (CmSearch::visibleCols($d) as $k => $c): ?>
        <label><input type="checkbox" name="c[]" value="<?= h($k) ?>" <?= in_array($k, $p['c'], true) ? 'checked' : '' ?>> <?= h($c['l']) ?></label>
      <?php endforeach; ?>
    </div>
    <a href="<?= $url(['c' => null, 'pg' => null]) ?>" style="font-size:11.5px">Ripristina le colonne predefinite</a>
  </details>
  <?php endif; ?>

  <div class="cms-actions">
    <button class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Cerca</button>
    <a class="btn btn-sm" href="<?= url_safe('cm_search', ['ds' => $ds]) ?>"><i class="fa-solid fa-eraser"></i> Azzera filtri</a>
    <?php if ($d): ?>
      <label style="font-size:12px;display:flex;gap:6px;align-items:center">Righe per pagina
        <select name="per" onchange="this.form.submit()"><?php foreach (CmSearch::PER as $n): ?><option <?= $p['per'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
    <?php endif; ?>
    <span class="sp"><?= $ds === 'tutto' ? count($allowed) . ' ambiti consultabili' : h($d['label']) ?> · <?= number_format($elapsed, 2, ',', '.') ?> s</span>
  </div>
  <details class="cms-help">
    <summary style="cursor:pointer">Sintassi dei filtri di colonna</summary>
    Testo: <code>abc</code> contiene · <code>a|b</code> uno dei due · <code>=abc</code> uguale · <code>^abc</code> inizia con · <code>!abc</code> non contiene.
    Numeri: <code>10</code> · <code>&gt;10</code> · <code>&lt;=5,5</code> · <code>10..20</code>.
    Date: <code>2026</code> · <code>2026-03</code> · <code>03/2026</code> · <code>15/03/2026</code> · <code>&gt;=2026-01</code> · <code>2026-01..2026-03</code>.
    Sì/no: <code>sì</code> · <code>no</code>. Per tutte: <code>=</code> vuoto · <code>!=</code> valorizzato.
    I filtri si sommano; «Cerca ovunque» cerca il testo in tutte le colonne testuali dell'ambito.
  </details>
</form>

<?php if ($bad):
    $lbl = array_map(fn($k) => $relLabels[$k] ?? ($d['cols'][$k]['l'] ?? $k), array_unique($bad)); ?>
  <div class="cms-warn"><i class="fa-solid fa-triangle-exclamation"></i> Filtri non applicati (non validi o non disponibili per questo ambito): <strong><?= h(implode(', ', $lbl)) ?></strong>.</div>
<?php endif; ?>

<?php if (!$allowed): ?>
  <div class="cms-panel cms-empty">Nessun archivio consultabile con i permessi attuali.</div>

<?php elseif ($ds === 'tutto'): ?>
  <div class="cms-bar"><span class="cnt"><?= $anyFilter ? 'Corrispondenze per ambito' : 'Contenuto degli archivi' ?></span>
    <span class="exp" style="font-size:12px;color:var(--muted)">Apri un ambito per filtrare per colonna ed esportare.</span></div>
  <div class="cms-ov">
  <?php foreach ($allowed as $k => $dx): $o = $overview[$k] ?? null; ?>
    <div class="cms-card">
      <h3><i class="fa-solid <?= h($dx['icon']) ?>"></i> <?= h($dx['label']) ?></h3>
      <?php if ($o === null): ?>
        <div class="na">Filtri non applicabili a questo archivio.</div>
      <?php else: ?>
        <div class="big <?= $o['n'] ? '' : 'zero' ?>"><?= number_format($o['n'], 0, ',', '.') ?> <span style="font-size:12px;font-weight:600;color:var(--muted)">righe</span></div>
        <?php if ($o['prev']): ?>
          <table><?php foreach ($o['prev']['rows'] as $r): ?><tr><?php foreach ($o['prev']['cols'] as $ck): $cv = CmSearch::display($dx['cols'][$ck], $r[$ck] ?? null); ?><td title="<?= h($cv) ?>"><?= h($cv) ?></td><?php endforeach; ?></tr><?php endforeach; ?></table>
        <?php endif; ?>
        <a class="btn btn-sm go" href="<?= $url(['f' => null, 'c' => null, 's' => null, 'd' => null, 'pg' => null], $k) ?>">Apri <i class="fa-solid fa-arrow-right"></i></a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>

<?php else:
    $cols = $p['c'];
    $firstRow = ($p['pg'] - 1) * $p['per'] + 1;
    $lastRow  = min($res['n'], $p['pg'] * $p['per']);
    $hasTot   = (bool)$res['tot'];
?>
  <div class="cms-bar">
    <span class="cnt"><strong><?= number_format($res['n'], 0, ',', '.') ?></strong> righe<?= $res['n'] ? ' · ' . number_format($firstRow, 0, ',', '.') . '–' . number_format($lastRow, 0, ',', '.') : '' ?></span>
    <?php if ($can_export): ?>
      <span class="exp"><span style="font-size:12px;font-weight:700;color:#334155"><i class="fa-solid fa-file-export"></i> Esporta i risultati:</span>
        <?php foreach (['csv' => ['CSV', 'fa-file-csv'], 'xlsx' => ['XLSX', 'fa-file-excel'], 'docx' => ['DOCX', 'fa-file-word'], 'pdf' => ['PDF', 'fa-file-pdf']] as $fx => [$fl, $fi]): ?>
          <a class="btn btn-sm" href="<?= $url(['export' => $fx, 'pg' => null]) ?>" title="<?= $fl ?>: <?= in_array($fx, ['docx', 'pdf'], true) ? 'fino a ' . number_format(CmSearch::MAX_DOC, 0, ',', '.') : 'fino a ' . number_format(CmSearch::MAX_FILE, 0, ',', '.') ?> righe, colonne e ordinamento della vista"><i class="fa-solid <?= $fi ?>"></i> <?= $fl ?></a>
        <?php endforeach; ?>
      </span>
    <?php endif; ?>
  </div>

  <div class="cms-wrap">
    <table class="cms-t">
      <thead>
        <tr>
          <?php foreach ($cols as $k): $c = $d['cols'][$k]; $on = $p['s'] === $k; $nd = $on && $p['d'] === 'asc' ? 'desc' : ($on ? 'asc' : (CmSearch::isNumeric($c) || in_array($c['t'], ['date', 'datetime'], true) ? 'desc' : 'asc')); ?>
            <th class="<?= CmSearch::isNumeric($c) ? 'num' : '' ?>" scope="col" aria-sort="<?= $on ? ($p['d'] === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
              <a class="<?= $on ? 'on' : '' ?>" href="<?= $url(['s' => $k, 'd' => $nd, 'pg' => null]) ?>" title="Ordina per <?= h($c['l']) ?>"><?= h($c['l']) ?>
                <span class="ar"><i class="fa-solid <?= $on ? ($p['d'] === 'asc' ? 'fa-arrow-up' : 'fa-arrow-down') : 'fa-sort' ?>"></i></span></a>
            </th>
          <?php endforeach; ?>
        </tr>
        <tr class="flt">
          <?php foreach ($cols as $k): $v = $p['f'][$k] ?? ''; $isBad = in_array($k, $bad, true);
                $ph = match ($d['cols'][$k]['t']) { 'date', 'datetime' => 'es. 2026-03', 'bool' => 'sì / no', 'int', 'num', 'hours', 'eur' => 'es. >10', default => 'filtra…' }; ?>
            <th><input form="cmsForm" type="text" name="f[<?= h($k) ?>]" value="<?= h($v) ?>" placeholder="<?= $ph ?>" class="<?= $isBad ? 'bad' : ($v !== '' ? 'set' : '') ?>" aria-label="Filtro <?= h($d['cols'][$k]['l']) ?>"></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$res['rows']): ?>
        <tr><td colspan="<?= count($cols) ?>" class="cms-empty">Nessun risultato con i filtri impostati.</td></tr>
      <?php else: foreach ($res['rows'] as $r): ?>
        <tr>
          <?php foreach ($cols as $k): $c = $d['cols'][$k]; $txt = CmSearch::display($c, $r[$k] ?? null);
                $lk = $d['links'][$k] ?? null; $lid = $lk ? (int)($r[$lk[1]] ?? 0) : 0;
                $cls = CmSearch::isNumeric($c) ? 'num' : ($c['t'] === 'long' ? 'long' : ''); ?>
            <td class="<?= $cls ?>"<?= $c['t'] === 'long' && $r[$k] !== null && mb_strlen((string)$r[$k]) > 140 ? ' title="' . h(mb_substr((string)$r[$k], 0, 1500)) . '"' : '' ?>>
              <?php if ($lk && $lid > 0 && !empty($links[$lk[0]]) && $txt !== ''): ?><a class="lk" href="<?= url_safe($lk[0], ['id' => $lid]) ?>"><?= h($txt) ?></a><?php else: ?><?= h($txt) ?><?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <?php if ($hasTot && $res['rows']): ?>
      <tfoot><tr>
        <?php foreach ($cols as $i => $k): $c = $d['cols'][$k]; ?>
          <td class="<?= CmSearch::isNumeric($c) ? 'num' : '' ?>"><?= array_key_exists($k, $res['tot']) ? h(CmSearch::display($c, $res['tot'][$k])) : ($i === 0 ? 'Totale (' . number_format($res['n'], 0, ',', '.') . ' righe)' : '') ?></td>
        <?php endforeach; ?>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?php if ($res['pages'] > 1): $pg = $p['pg']; $last = $res['pages']; ?>
    <nav class="cms-pag" aria-label="Pagine">
      <?php if ($pg > 1): ?><a href="<?= $url(['pg' => $pg - 1]) ?>">‹ Precedente</a><?php endif; ?>
      <?php $prevShown = 0; for ($i = 1; $i <= $last; $i++):
          if ($i !== 1 && $i !== $last && abs($i - $pg) > 2) { if ($prevShown !== -1) echo '<span class="gap">…</span>'; $prevShown = -1; continue; }
          $prevShown = $i; ?>
        <?php if ($i === $pg): ?><span class="on" aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= $url(['pg' => $i > 1 ? $i : null]) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($pg < $last): ?><a href="<?= $url(['pg' => $pg + 1]) ?>">Successiva ›</a><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php require_once('footer.php'); ?>
