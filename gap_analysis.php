<?php
/**
 * PortalManager — gap_analysis.php
 * Dashboard Gap Analysis & Compliance con grafici Chart.js + conteggio
 * completo certificazioni possedute per brand.
 */

require_once('access_control.php');
require_once('header.php');

$warn_pct = (float)($settings['compliance_warning_pct']  ?? 80);
$crit_pct = (float)($settings['compliance_critical_pct'] ?? 60);

// ─────────────────────────────────────────────────────────────────────
// Brand
// ─────────────────────────────────────────────────────────────────────
$brands = $pdo->query(
    "SELECT b.*,
       (SELECT COUNT(*) FROM certifications c WHERE c.brand_id=b.id AND c.is_active=1) cats
     FROM brands b ORDER BY b.name"
)->fetchAll();

// ─────────────────────────────────────────────────────────────────────
// Conteggi DETTAGLIATI aggregati per brand (una sola query)
// ─────────────────────────────────────────────────────────────────────
$stats_rows = $pdo->query(
    "SELECT b.id AS brand_id,
            SUM(CASE WHEN uc.status='active'   THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN uc.status='expiring' THEN 1 ELSE 0 END) AS expiring_count,
            SUM(CASE WHEN uc.status='expired'  THEN 1 ELSE 0 END) AS expired_count,
            SUM(CASE WHEN uc.status='revoked'  THEN 1 ELSE 0 END) AS revoked_count,
            COUNT(uc.id) AS total_count,
            COUNT(DISTINCT uc.employee_id) AS holders_count,
            COUNT(DISTINCT uc.certification_id) AS distinct_certs
       FROM brands b
       LEFT JOIN certifications c     ON c.brand_id    = b.id
       LEFT JOIN user_certifications uc ON uc.certification_id = c.id
      GROUP BY b.id"
)->fetchAll(PDO::FETCH_ASSOC);

$stats_by_brand = [];
foreach ($stats_rows as $row) $stats_by_brand[(int)$row['brand_id']] = $row;

function brand_stats(int $brand_id): array {
    global $stats_by_brand;
    return $stats_by_brand[$brand_id] ?? [
        'active_count' => 0, 'expiring_count' => 0, 'expired_count' => 0, 'revoked_count' => 0,
        'total_count'  => 0, 'holders_count'  => 0, 'distinct_certs' => 0,
    ];
}

function get_compliance(int $brand_id, int $req): array {
    $s = brand_stats($brand_id);
    $ac = (int)$s['active_count'];
    $pct = $req > 0 ? min(100, round($ac / $req * 100, 1)) : 100;
    return [
        'pct'      => $pct,
        'active'   => $ac,
        'expiring' => (int)$s['expiring_count'],
        'expired'  => (int)$s['expired_count'],
        'revoked'  => (int)$s['revoked_count'],
        'total'    => (int)$s['total_count'],
        'holders'  => (int)$s['holders_count'],
        'distinct' => (int)$s['distinct_certs'],
        'gap'      => max(0, $req - $ac),
    ];
}

// Scadenze 90 giorni
$scad90 = $pdo->query(
    "SELECT b.name brand_name, cert.name cert_name, uc.expiry_date,
            e.first_name, e.last_name, DATEDIFF(uc.expiry_date,CURDATE()) dd
     FROM user_certifications uc
     JOIN certifications cert ON uc.certification_id=cert.id
     JOIN brands b ON cert.brand_id=b.id
     JOIN employees e ON uc.employee_id=e.id
     WHERE uc.status='active'
       AND uc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 90 DAY)
     ORDER BY uc.expiry_date"
)->fetchAll();

// Brand critici + KPI globale
$critical_brands = array_filter($brands, function ($b) use ($warn_pct) {
    $c = get_compliance($b['id'], (int)$b['req_technical']);
    return $c['pct'] < $warn_pct && (int)$b['req_technical'] > 0;
});

$g_req = 0; $g_act = 0; $g_total = 0;
foreach ($brands as $b) {
    $c = get_compliance($b['id'], (int)$b['req_technical']);
    if ((int)$b['req_technical'] > 0) {
        $g_req += (int)$b['req_technical'];
        $g_act += min((int)$b['req_technical'], $c['active']);
    }
    $g_total += $c['total'];
}
$g_holders = (int)$pdo->query("SELECT COUNT(DISTINCT employee_id) FROM user_certifications WHERE status IN('active','expiring')")->fetchColumn();
$global_pct = $g_req > 0 ? round($g_act / $g_req * 100) : 100;
$global_col = $global_pct >= $warn_pct ? 'var(--success)' : ($global_pct >= $crit_pct ? 'var(--warning)' : 'var(--danger)');

// Dataset per chart
$chart_labels = []; $chart_active = []; $chart_expiring = []; $chart_expired = []; $chart_req = [];
foreach ($brands as $b) {
    $c = get_compliance($b['id'], (int)$b['req_technical']);
    if ($c['total'] === 0 && (int)$b['req_technical'] === 0) continue;
    $chart_labels[]   = $b['name'];
    $chart_active[]   = $c['active'];
    $chart_expiring[] = $c['expiring'];
    $chart_expired[]  = $c['expired'];
    $chart_req[]      = (int)$b['req_technical'];
}

// Distribuzione globale per stato
$global_status = $pdo->query("SELECT status, COUNT(*) AS c FROM user_certifications GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$g_active   = (int)($global_status['active']   ?? 0);
$g_expiring = (int)($global_status['expiring'] ?? 0);
$g_expired  = (int)($global_status['expired']  ?? 0);
$g_revoked  = (int)($global_status['revoked']  ?? 0);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:16px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-chart-bar" style="color:var(--p);margin-right:10px"></i>Gap Analysis &amp; Compliance
    </h1>
    <p style="color:var(--muted);font-size:13px;margin:0">Confronto requisiti vendor vs certificazioni attive · vista per brand con conteggi e grafici</p>
  </div>
  <div style="display:flex;gap:24px;align-items:center">
    <div style="text-align:center">
      <div style="font-size:30px;font-weight:800;color:<?=$global_col?>;line-height:1"><?=$global_pct?>%</div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;font-weight:700">Compliance</div>
    </div>
    <div style="text-align:center">
      <div style="font-size:30px;font-weight:800;color:var(--p);line-height:1"><?=$g_total?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;font-weight:700">Cert. totali</div>
    </div>
    <div style="text-align:center">
      <div style="font-size:30px;font-weight:800;color:#0ea5e9;line-height:1"><?=$g_holders?></div>
      <div style="font-size:10px;color:var(--muted);text-transform:uppercase;font-weight:700">Holders</div>
    </div>
  </div>
</div>

<?php if (!empty($critical_brands)): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <strong><?=count($critical_brands)?> brand</strong> sotto la soglia critica (<?=$warn_pct?>%):
  <?=implode(', ', array_map(fn($b)=>'<strong>'.h($b['name']).'</strong>', $critical_brands))?>
</div>
<?php endif; ?>

<!-- GRAFICI -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-chart-pie" style="color:#0ea5e9"></i> Distribuzione per stato</span>
    </div>
    <div style="display:flex;gap:14px;align-items:center">
      <div style="flex:0 0 200px"><canvas id="chartStatus" width="200" height="200"></canvas></div>
      <div style="flex:1;font-size:12px;line-height:1.9">
        <div><span style="display:inline-block;width:12px;height:12px;background:#16a34a;border-radius:3px;margin-right:8px"></span>Attive: <strong><?=$g_active?></strong></div>
        <div><span style="display:inline-block;width:12px;height:12px;background:#f59e0b;border-radius:3px;margin-right:8px"></span>In scadenza: <strong><?=$g_expiring?></strong></div>
        <div><span style="display:inline-block;width:12px;height:12px;background:#dc2626;border-radius:3px;margin-right:8px"></span>Scadute: <strong><?=$g_expired?></strong></div>
        <div><span style="display:inline-block;width:12px;height:12px;background:#94a3b8;border-radius:3px;margin-right:8px"></span>Revocate: <strong><?=$g_revoked?></strong></div>
        <div style="border-top:1px solid var(--border);margin-top:8px;padding-top:8px"><strong>Totale: <?=$g_total?></strong></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-gauge-high" style="color:<?=$global_col?>"></i> Compliance globale vs requisiti</span>
    </div>
    <div style="display:flex;gap:14px;align-items:center">
      <div style="flex:0 0 200px;position:relative">
        <canvas id="chartGlobal" width="200" height="200"></canvas>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none">
          <div style="font-size:28px;font-weight:800;color:<?=$global_col?>;line-height:1"><?=$global_pct?>%</div>
        </div>
      </div>
      <div style="flex:1;font-size:12px;line-height:1.9">
        <div>Coperto: <strong style="color:var(--success)"><?=$g_act?></strong></div>
        <div>Gap residuo: <strong style="color:var(--danger)"><?=max(0,$g_req-$g_act)?></strong></div>
        <div>Requisito totale: <strong><?=$g_req?></strong></div>
        <div style="border-top:1px solid var(--border);margin-top:8px;padding-top:8px;font-size:11px;color:var(--muted)">
          Soglia Safe: ≥<?=$warn_pct?>% · Attenzione: ≥<?=$crit_pct?>%
        </div>
      </div>
    </div>
  </div>
</div>

<!-- BAR CHART PER BRAND -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-chart-column" style="color:#7c3aed"></i> Certificazioni possedute per brand</span>
    <span style="font-size:11px;color:var(--muted)"><?=count($chart_labels)?> brand con dati</span>
  </div>
  <?php if (empty($chart_labels)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px">Nessun brand con certificazioni o requisiti definiti.</div>
  <?php else: ?>
  <div style="position:relative;height:<?=max(220, count($chart_labels)*32)?>px">
    <canvas id="chartBrands"></canvas>
  </div>
  <?php endif; ?>
</div>

<!-- TABELLA MATRICE -->
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <span class="card-title">Matrice compliance per brand</span>
    <div style="display:flex;gap:8px;font-size:11px;align-items:center">
      <span style="background:#d1fae522;border-left:3px solid var(--success);padding:3px 8px;border-radius:4px;color:#065f46">≥<?=$warn_pct?>% Safe</span>
      <span style="background:#fef3c722;border-left:3px solid var(--warning);padding:3px 8px;border-radius:4px;color:#92400e">≥<?=$crit_pct?>% Attenzione</span>
      <span style="background:#fee2e222;border-left:3px solid var(--danger);padding:3px 8px;border-radius:4px;color:#991b1b">&lt;<?=$crit_pct?>% Rischio</span>
    </div>
  </div>
  <div style="overflow-x:auto">
  <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('gap_analysis', '#lf-table-gap_analysis', ['export_filename' => 'gap_analysis', 'title' => 'Gap analysis']); ?>
<table id="lf-table-gap_analysis" class="data-table">
    <thead>
      <tr>
        <th>Brand</th>
        <th>Partnership</th>
        <th style="text-align:center" title="Titoli nel catalogo brand">Catalogo</th>
        <th style="text-align:center" title="Totale certificazioni possedute (tutti gli stati)">Totale</th>
        <th style="text-align:center" title="Dipendenti con almeno una cert del brand">Holders</th>
        <th style="text-align:center">Attive</th>
        <th style="text-align:center">In scad.</th>
        <th style="text-align:center">Scadute</th>
        <th style="text-align:center">Req.</th>
        <th style="text-align:center">Gap</th>
        <th style="text-align:center">Compliance</th>
        <th style="text-align:center">Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($brands as $b):
      $req = (int)$b['req_technical'];
      $c   = get_compliance($b['id'], $req);
      $col = $c['pct'] >= $warn_pct ? 'var(--success)' : ($c['pct'] >= $crit_pct ? 'var(--warning)' : 'var(--danger)');
    ?>
    <tr>
      <td>
        <div style="font-weight:700"><?=h($b['name'])?></div>
        <div style="font-size:10px;color:var(--muted)"><?=$c['distinct']?> titoli distinti utilizzati</div>
      </td>
      <td><span class="badge badge-info"><?=h($b['partnership_level'] ?? '—')?></span></td>
      <td style="text-align:center;font-size:11px;color:var(--muted)"><?=$b['cats']?></td>
      <td style="text-align:center"><strong style="font-size:14px"><?=$c['total']?></strong></td>
      <td style="text-align:center"><strong style="font-size:14px;color:#0ea5e9"><?=$c['holders']?></strong></td>
      <td style="text-align:center">
        <span style="font-weight:800;color:<?=$c['active']>=$req&&$req>0?'var(--success)':($c['active']>0?'var(--text)':'var(--danger)')?>"><?=$c['active']?></span>
      </td>
      <td style="text-align:center">
        <?php if($c['expiring']>0): ?><span style="color:var(--warning);font-weight:700"><?=$c['expiring']?></span>
        <?php else: ?><span style="color:var(--muted)">0</span><?php endif; ?>
      </td>
      <td style="text-align:center">
        <?php if($c['expired']>0): ?><span style="color:var(--danger);font-weight:700"><?=$c['expired']?></span>
        <?php else: ?><span style="color:var(--muted)">0</span><?php endif; ?>
      </td>
      <td style="text-align:center;font-weight:700"><?=$req?:'—'?></td>
      <td style="text-align:center">
        <?php if($c['gap']>0): ?><span style="font-weight:800;color:var(--danger)">-<?=$c['gap']?></span>
        <?php elseif($req>0): ?><span style="color:var(--success)">&#10003;</span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td style="text-align:center">
        <div style="display:flex;align-items:center;gap:8px;justify-content:center">
          <div style="width:60px;height:6px;background:#f1f5f9;border-radius:3px">
            <div style="height:6px;background:<?=$col?>;border-radius:3px;width:<?=$c['pct']?>%"></div>
          </div>
          <span style="font-weight:800;color:<?=$col?>;font-size:13px"><?=$c['pct']?>%</span>
        </div>
      </td>
      <td style="text-align:center">
        <?php if($req<=0): ?><span class="badge badge-neutral">N/A</span>
        <?php elseif($c['pct']>=$warn_pct): ?><span class="badge badge-success">Safe</span>
        <?php elseif($c['pct']>=$crit_pct): ?><span class="badge badge-warning">Attenzione</span>
        <?php else: ?><span class="badge badge-danger">Rischio</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if (!empty($scad90)): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-hourglass-half" style="color:var(--warning)"></i>Scadenze critiche prossimi 90 giorni</span>
    <span style="font-size:12px;color:var(--muted)"><?=count($scad90)?> certificazioni</span>
  </div>
  <table class="data-table">
    <thead><tr><th>Brand</th><th>Certificazione</th><th>Dipendente</th><th>Scadenza</th><th>Giorni</th></tr></thead>
    <tbody>
    <?php foreach ($scad90 as $s):
      $dd = (int)$s['dd'];
      $dcol = $dd < 30 ? 'var(--danger)' : ($dd < 60 ? 'var(--warning)' : 'var(--text)');
    ?>
      <tr>
        <td><span class="badge badge-neutral"><?=h($s['brand_name'])?></span></td>
        <td><?=h($s['cert_name'])?></td>
        <td><?=h($s['first_name'].' '.$s['last_name'])?></td>
        <td><?=date('d/m/Y', strtotime($s['expiry_date']))?></td>
        <td style="font-weight:700;color:<?=$dcol?>"><?=$dd?> gg</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  if (typeof Chart === 'undefined') { console.warn('Chart.js non disponibile'); return; }

  const ctxStatus = document.getElementById('chartStatus');
  if (ctxStatus) {
    new Chart(ctxStatus, {
      type: 'doughnut',
      data: {
        labels: ['Attive','In scadenza','Scadute','Revocate'],
        datasets: [{
          data: [<?=$g_active?>, <?=$g_expiring?>, <?=$g_expired?>, <?=$g_revoked?>],
          backgroundColor: ['#16a34a','#f59e0b','#dc2626','#94a3b8'],
          borderWidth: 2, borderColor: '#fff'
        }]
      },
      options: { responsive:true, maintainAspectRatio:true, cutout:'60%', plugins:{legend:{display:false}} }
    });
  }

  const ctxGlobal = document.getElementById('chartGlobal');
  if (ctxGlobal) {
    const pct = <?=$global_pct?>;
    const col = <?=json_encode($global_pct >= $warn_pct ? '#16a34a' : ($global_pct >= $crit_pct ? '#f59e0b' : '#dc2626'))?>;
    new Chart(ctxGlobal, {
      type: 'doughnut',
      data: {
        labels: ['Coperto','Gap'],
        datasets: [{ data: [pct, Math.max(0, 100 - pct)], backgroundColor: [col, '#f1f5f9'], borderWidth: 0 }]
      },
      options: { responsive:true, maintainAspectRatio:true, cutout:'75%', plugins:{legend:{display:false}, tooltip:{enabled:false}} }
    });
  }

  const ctxBrands = document.getElementById('chartBrands');
  if (ctxBrands) {
    new Chart(ctxBrands, {
      type: 'bar',
      data: {
        labels: <?=json_encode($chart_labels, JSON_UNESCAPED_UNICODE)?>,
        datasets: [
          { label: 'Attive',      data: <?=json_encode($chart_active)?>,   backgroundColor: '#16a34a' },
          { label: 'In scadenza', data: <?=json_encode($chart_expiring)?>, backgroundColor: '#f59e0b' },
          { label: 'Scadute',     data: <?=json_encode($chart_expired)?>,  backgroundColor: '#dc2626' },
          { label: 'Requisito',   data: <?=json_encode($chart_req)?>,
            backgroundColor: 'rgba(124, 58, 237, 0.25)', borderColor: '#7c3aed', borderWidth: 2, borderDash: [5,5] },
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { position: 'top', labels: { font: { size: 11 } } }, tooltip: { mode: 'index', intersect: false } }
      }
    });
  }
})();
</script>
