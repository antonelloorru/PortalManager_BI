<?php
/**
 * certV 5.7.0 — tech_skill_matrix.php
 *
 * Matrice di copertura per ogni tecnologia:
 * - Quanti dipendenti coprono la tech (via cert posseduta o skill)
 * - Skill gap analysis: tech con catalogo ma 0 risorse
 * - Drilldown: dettaglio coperture per singola tecnologia
 */
require_once('access_control.php');
require_once __DIR__ . '/app/TechnologyMapper.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role > 2) { header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php')); exit(); }

$mapper = new TechnologyMapper($pdo);
$f_category = (int)($_GET['f_category'] ?? 0);
$f_tech     = (int)($_GET['f_tech'] ?? 0);

$overview = $mapper->getOverview($f_category > 0 ? $f_category : null, true);
$gaps     = $mapper->getSkillGaps();
$categories = $pdo->query("SELECT * FROM tech_categories WHERE is_active = 1 ORDER BY display_order, name")->fetchAll();

// Drilldown su singola tech
$drill_tech = null;
$drill_coverage = [];
if ($f_tech > 0) {
    $tt = $pdo->prepare("SELECT t.*, tc.name AS category_name FROM technologies t LEFT JOIN tech_categories tc ON tc.id = t.category_id WHERE t.id = ?");
    $tt->execute([$f_tech]);
    $drill_tech = $tt->fetch(PDO::FETCH_ASSOC);
    if ($drill_tech) {
        $drill_coverage = $mapper->getCoverageMatrix($f_tech);
    }
}

require_once('header.php');
?>

<style>
.matrix-table { width:100%; border-collapse:collapse; background:#fff; }
.matrix-table th { background:#f8fafc; padding:10px; text-align:left; font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; border-bottom:2px solid var(--border); }
.matrix-table td { padding:10px; border-bottom:1px solid var(--border); }
.coverage-bar { display:inline-block; height:8px; border-radius:4px; background:#e5e7eb; width:120px; vertical-align:middle; overflow:hidden; }
.coverage-bar > span { display:block; height:100%; background:linear-gradient(90deg,#10b981,#0ea5e9); }
.gap-card { background:#fef2f2; border-left:4px solid #dc2626; padding:10px 14px; margin-bottom:8px; border-radius:6px; display:flex; justify-content:space-between; align-items:center; }
.tech-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:8px; font-size:11px; font-weight:700; color:#fff; }
.cov-row { display:grid; grid-template-columns:1fr auto auto; gap:10px; padding:8px 12px; border-bottom:1px solid var(--border); align-items:center; }
.cov-row:hover { background:#f8fafc; }
</style>

<div style="max-width:1300px;margin:0 auto">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-table-cells" style="color:#8b5cf6"></i> Skill Matrix per tecnologia
      </h1>
      <div style="color:var(--muted);font-size:13px">Copertura risorse e gap analysis sulle tecnologie aziendali</div>
    </div>
    <div style="display:flex;gap:6px">
      <a href="<?= url_safe('manage_technologies') ?>" class="btn btn-sm"><i class="fa-solid fa-microchip"></i> Gestisci tecnologie</a>
    </div>
  </div>

  <!-- DRILLDOWN -->
  <?php if ($drill_tech): ?>
    <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:18px;border-top:4px solid <?= h($drill_tech['color'] ?: '#0ea5e9') ?>">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
        <div>
          <h2 style="font-size:18px;font-weight:800">
            <i class="fa-solid <?= h($drill_tech['icon'] ?: 'fa-microchip') ?>" style="color:<?= h($drill_tech['color'] ?: '#0ea5e9') ?>"></i>
            <?= h($drill_tech['name']) ?>
          </h2>
          <div style="color:var(--muted);font-size:12px"><?= h($drill_tech['category_name'] ?? 'Senza categoria') ?> · <?= count($drill_coverage) ?> risorse</div>
        </div>
        <a href="<?= url_safe('tech_skill_matrix') ?>" class="btn btn-sm">← Torna alla matrice</a>
      </div>

      <?php if (empty($drill_coverage)): ?>
        <div style="text-align:center;color:var(--muted);padding:20px">
          <i class="fa-solid fa-triangle-exclamation" style="font-size:32px;color:#f59e0b;margin-bottom:8px"></i>
          <p>Nessuna risorsa copre questa tecnologia. Considerare formazione o assunzioni.</p>
        </div>
      <?php else: ?>
        <div style="background:#f8fafc;border-radius:8px;overflow:hidden">
          <?php foreach ($drill_coverage as $cov): ?>
            <div class="cov-row">
              <div>
                <strong><?= h($cov['employee_name']) ?></strong>
                <?php if (!empty($cov['job_title'])): ?>
                  <span style="color:var(--muted);font-size:11px">— <?= h($cov['job_title']) ?></span>
                <?php endif; ?>
              </div>
              <div style="text-align:center">
                <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700">
                  <i class="fa-solid fa-award"></i> <?= (int)$cov['cert_count'] ?> cert
                </span>
              </div>
              <div style="text-align:center">
                <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700">
                  <i class="fa-solid fa-wand-magic-sparkles"></i> <?= (int)$cov['skill_count'] ?> skill
                </span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- FILTRI -->
  <form method="GET" class="filter-bar" style="margin-bottom:14px">
    <?php if (!empty($_GET['r'])): ?><input type="hidden" name="r" value="<?= h($_GET['r']) ?>"><?php endif; ?>
    <div class="fg">
      <label>Categoria</label>
      <select name="f_category" onchange="this.form.submit()">
        <option value="0">Tutte</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $f_category===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <!-- GAP ANALYSIS -->
  <?php if (!empty($gaps)): ?>
    <div style="background:#fff;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:18px">
      <h3 style="font-size:14px;font-weight:800;color:#dc2626;margin-bottom:10px">
        <i class="fa-solid fa-triangle-exclamation"></i> Skill gap: tecnologie senza risorse coperte (<?= count($gaps) ?>)
      </h3>
      <?php foreach ($gaps as $g): ?>
        <div class="gap-card">
          <div>
            <strong><?= h($g['name']) ?></strong>
            <span style="color:var(--muted);font-size:11px"> — <?= (int)$g['available_certs'] ?> cert in catalogo, 0 dipendenti</span>
          </div>
          <a href="?f_tech=<?= $g['id'] ?><?= !empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '' ?>" class="btn btn-sm">Dettaglio</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- MATRICE PRINCIPALE -->
  <div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06)">
    <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('tech_skill_matrix', '#lf-table-tech_skill_matrix', ['export_filename' => 'tech_skill_matrix', 'title' => 'Skill matrix tecnologica']); ?>
<table id="lf-table-tech_skill_matrix" class="matrix-table">
      <thead>
        <tr>
          <th>Tecnologia</th>
          <th>Categoria</th>
          <th style="text-align:right">Brand</th>
          <th style="text-align:right">Cert in catalogo</th>
          <th style="text-align:right">Cert possedute</th>
          <th style="text-align:right">Risorse</th>
          <th>Copertura</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php
          $max_resources = 0;
          foreach ($overview as $t) $max_resources = max($max_resources, (int)$t['skilled_employees']);
          if ($max_resources === 0) $max_resources = 1;
        ?>
        <?php foreach ($overview as $t): ?>
          <tr>
            <td>
              <span class="tech-pill" style="background:<?= h($t['color'] ?: '#0ea5e9') ?>">
                <i class="fa-solid <?= h($t['icon'] ?: 'fa-microchip') ?>"></i>
                <?= h($t['name']) ?>
              </span>
            </td>
            <td style="font-size:12px;color:var(--muted)"><?= h($t['category_name'] ?? '—') ?></td>
            <td style="text-align:right;font-weight:700"><?= (int)$t['brand_count'] ?></td>
            <td style="text-align:right;font-weight:700"><?= (int)$t['cert_count'] ?></td>
            <td style="text-align:right;font-weight:700;color:#10b981"><?= (int)$t['held_count'] ?></td>
            <td style="text-align:right;font-weight:700;color:#0ea5e9"><?= (int)$t['skilled_employees'] ?></td>
            <td>
              <span class="coverage-bar"><span style="width:<?= $max_resources > 0 ? ((int)$t['skilled_employees'] * 100 / $max_resources) : 0 ?>%"></span></span>
            </td>
            <td>
              <a href="?f_tech=<?= $t['id'] ?><?= !empty($_GET['r']) ? '&r=' . urlencode($_GET['r']) : '' ?>" class="btn btn-sm" title="Drilldown">
                <i class="fa-solid fa-magnifying-glass"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($overview)): ?>
          <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--muted)">Nessuna tecnologia attiva.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once('footer.php'); ?>
