<?php
/**
 * certV 2.0 — index.php  Dashboard principale
 * FIX BUG #6: alias SQL 'c' duplicato in query g_brand → rinominato in 'cert'
 * FIX BUG #11: compliance globale usa brand_compliance_all() — una sola query
 */
require_once('access_control.php');
require_once('header.php');

$u_role = (int)($_SESSION['role_id'] ?? 99);

try {
    // Certificazioni KPI
    $cert_stats = $pdo->query(
        "SELECT status, COUNT(*) c FROM user_certifications GROUP BY status"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $tot   = array_sum($cert_stats);
    $act   = (int)($cert_stats['active']   ?? 0);
    $exp_s = (int)($cert_stats['expiring'] ?? 0);
    $exp_d = (int)($cert_stats['expired']  ?? 0);

    $piani = (int)$pdo->query(
        "SELECT COUNT(*) FROM training_plans WHERE status NOT IN('completed','cancelled')"
    )->fetchColumn();

    // Recruiting KPI (protetto)
    $pos_aperte = 0; $cand_pipe = 0; $contratti_sc = 0;
    try {
        $pos_aperte   = (int)$pdo->query("SELECT COUNT(*) FROM job_positions WHERE status='open'")->fetchColumn();
        $cand_pipe    = (int)$pdo->query("SELECT COUNT(*) FROM candidate_applications WHERE stage NOT IN('hired','rejected')")->fetchColumn();
        $contratti_sc = (int)$pdo->query("SELECT COUNT(*) FROM agency_contracts WHERE status='active' AND end_date <= DATE_ADD(CURDATE(),INTERVAL 60 DAY)")->fetchColumn();
    } catch (Exception $e) {}

    // Brand
    $brands_data = $pdo->query("SELECT id, name, partnership_level, req_technical FROM brands ORDER BY name")->fetchAll();

    // Scadenze 30gg
    $scad30 = $pdo->query(
        "SELECT uc.expiry_date, e.first_name, e.last_name, cert.name cert_name, b.name brand_name,
                DATEDIFF(uc.expiry_date,CURDATE()) dd
         FROM user_certifications uc
         JOIN employees e           ON uc.employee_id = e.id
         JOIN certifications cert   ON uc.certification_id = cert.id
         JOIN brands b              ON cert.brand_id = b.id
         WHERE uc.status='active' AND uc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
         ORDER BY uc.expiry_date LIMIT 8"
    )->fetchAll();

    // Pipeline stages
    $pipe_stages = [];
    try {
        $pipe_stages = $pdo->query(
            "SELECT stage, COUNT(*) c FROM candidate_applications WHERE stage NOT IN('hired','rejected') GROUP BY stage"
        )->fetchAll();
    } catch (Exception $e) {}

    // Top candidati match
    $top_cand = [];
    try {
        $top_cand = $pdo->query(
            "SELECT ca.match_score, cand.first_name, cand.last_name, jp.title pos_title, ca.candidate_id, ca.id app_id
             FROM candidate_applications ca
             JOIN candidates cand   ON ca.candidate_id = cand.id
             JOIN job_positions jp  ON ca.position_id  = jp.id
             WHERE ca.match_score >= 75 AND ca.stage NOT IN('hired','rejected')
             ORDER BY ca.match_score DESC LIMIT 4"
        )->fetchAll();
    } catch (Exception $e) {}

    // FIX BUG #6: alias 'c' su certifications rinominato in 'cert'
    $g_brand = $pdo->query(
        "SELECT b.name, COUNT(uc.id) cnt
         FROM user_certifications uc
         JOIN certifications cert ON uc.certification_id = cert.id
         JOIN brands b            ON cert.brand_id = b.id
         GROUP BY b.name ORDER BY cnt DESC LIMIT 6"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (Exception $e) { $db_error = $e->getMessage(); }

// FIX BUG #11: compliance globale — una sola query invece di N loop queries
$compliance_map = brand_compliance_all();
$g_tot = 0; $g_met = 0;
foreach ($compliance_map as $bid => $data) {
    $g_tot += $data['req'];
    $g_met += min($data['req'], $data['active']);
}
$global_pct = $g_tot > 0 ? round($g_met / $g_tot * 100) : 100;
$global_col = $global_pct >= 80 ? 'var(--success)' : ($global_pct >= 60 ? 'var(--warning)' : 'var(--danger)');
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<?php if (isset($db_error)): ?>
<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> Errore DB: <?=h($db_error)?></div>
<?php endif; ?>

<?php
// ── v1.8.76 — stato della sincronizzazione con il gestionale ────────────────
//
// L'avviso compare SOLO a chi puo' intervenire (ruolo <= 5): per un dipendente
// sarebbe rumore su una cosa che non puo' risolvere, e un avviso che non si puo'
// agire si impara a ignorare.
//
// Ed e' visibile solo quando c'e' qualcosa da dire: se la sincronizzazione e'
// regolare non compare nulla. Un riquadro verde permanente diventa invisibile
// dopo una settimana, e con lui l'informazione che dovrebbe dare.
$syncAvviso = null;
if ($u_role <= 5) {
    try {
        $st = $pdo->query("SELECT * FROM `v_cm_sync_schedule_stato`");
        $ss = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;

        // l'ultima esecuzione RIUSCITA, che e' il dato richiesto: "ok" o
        // "parziale". Un'esecuzione fallita non ha aggiornato i dati, e citarla
        // come ultima sincronia sarebbe fuorviante.
        $ultimaOk = $pdo->query(
            "SELECT `started_at`, `finished_at`, `status`, `rows_read`, `rows_new`,
                    `rows_updated`, `datasets_ok`, `datasets_err`, `seconds`
               FROM `cm_sync_schedule_log`
              WHERE `status` IN ('ok','parziale')
              ORDER BY `started_at` DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        $ore = $ultimaOk ? (int)round((time() - strtotime((string)$ultimaOk['started_at'])) / 3600) : null;

        // la soglia e' 36 ore: piu' di un giorno e mezzo senza una
        // sincronizzazione riuscita significa che almeno una e' saltata
        $inRitardo = ($ore === null) || ($ore > 36);

        if ($ss || $ultimaOk) {
            $syncAvviso = [
                'attiva'     => (int)($ss['attiva'] ?? 0),
                'diagnosi'   => (string)($ss['diagnosi'] ?? 'sconosciuta'),
                'ultima_ok'  => $ultimaOk,
                'ore'        => $ore,
                'in_ritardo' => $inRitardo,
                'ultimo_esito' => (string)($ss['ultimo_esito'] ?? ''),
            ];
        }
    } catch (Throwable $e) { $syncAvviso = null; }
}
?>

<?php if ($syncAvviso && ($syncAvviso['in_ritardo'] || $syncAvviso['ultimo_esito'] === 'errore'
                          || $syncAvviso['diagnosi'] === 'mai eseguita')): ?>
  <?php
    $u = $syncAvviso['ultima_ok'];
    $grave = $syncAvviso['in_ritardo'] || $syncAvviso['diagnosi'] === 'mai eseguita';
    $col = $grave ? '#dc2626' : '#f59e0b';
  ?>
  <div style="border-left:4px solid <?=$col?>;background:<?=$grave ? '#fef2f2' : '#fffbeb'?>;
              padding:12px 16px;border-radius:8px;margin-bottom:18px;
              display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <i class="fa-solid fa-<?=$grave ? 'triangle-exclamation' : 'clock-rotate-left'?>"
       style="font-size:20px;color:<?=$col?>"></i>
    <div style="flex:1;min-width:280px">
      <div style="font-weight:700;font-size:14px;color:<?=$col?>">
        <?php if ($syncAvviso['diagnosi'] === 'mai eseguita'): ?>
          Sincronizzazione con il gestionale mai eseguita
        <?php elseif ($syncAvviso['in_ritardo']): ?>
          Dati del gestionale non aggiornati
        <?php else: ?>
          L'ultima sincronizzazione è fallita
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:#475569;margin-top:3px">
        <?php if ($u): ?>
          Ultima sincronizzazione completa:
          <strong><?=date('d/m/Y \l\l\e H:i', strtotime((string)$u['started_at']))?></strong>
          <?php if ($syncAvviso['ore'] !== null): ?>
            — <?=$syncAvviso['ore'] < 48
                 ? $syncAvviso['ore'] . ' ore fa'
                 : (int)round($syncAvviso['ore'] / 24) . ' giorni fa' ?>
          <?php endif; ?>
          <span style="color:var(--muted)">
            (<?=number_format((int)$u['rows_read'], 0, ',', '.')?> righe lette,
            <?=(int)$u['datasets_ok']?> dataset<?= (int)$u['datasets_err'] ? ', ' . (int)$u['datasets_err'] . ' in errore' : '' ?>)
          </span>
        <?php else: ?>
          Nessuna sincronizzazione completa risulta registrata.
        <?php endif; ?>
      </div>
    </div>
    <a class="btn btn-sm" style="background:<?=$col?>;color:#fff;border:none"
       href="<?=url_safe('sync_commesse')?>">
      <i class="fa-solid fa-rotate"></i> Vai alla sincronizzazione</a>
  </div>
<?php endif; ?>

<div style="margin-bottom:24px">
  <h1 style="font-size:21px;font-weight:800">Buongiorno, <?=h(explode(' ', $_SESSION['user_name'] ?? '')[0])?> 👋</h1>
  <p style="color:var(--muted);font-size:13px;margin-top:3px"><?=date('l d F Y')?> — <?=h($app_name)?> 2.4</p>
</div>

<!-- KPI row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:14px;margin-bottom:26px">
  <div class="stat-card" style="border-color:var(--p)"><div class="sl">Certificazioni tot.</div><div class="sv" style="color:var(--p)"><?=$tot?></div></div>
  <div class="stat-card" style="border-color:var(--success)"><div class="sl">Attive</div><div class="sv" style="color:var(--success)"><?=$act?></div></div>
  <div class="stat-card" style="border-color:var(--warning)">
    <div class="sl">In scadenza</div><div class="sv" style="color:var(--warning)"><?=$exp_s?></div>
    <?php if($exp_s>0): ?><div style="font-size:10px;color:var(--warning);margin-top:4px">Azione richiesta</div><?php endif; ?>
  </div>
  <div class="stat-card" style="border-color:var(--danger)"><div class="sl">Scadute</div><div class="sv" style="color:var(--danger)"><?=$exp_d?></div></div>
  <div class="stat-card" style="border-color:#6366f1"><div class="sl">Piani formativi</div><div class="sv" style="color:#6366f1"><?=$piani?></div></div>
  <div class="stat-card" style="border-color:<?=$global_col?>">
    <div class="sl">Compliance globale</div>
    <div class="sv" style="color:<?=$global_col?>"><?=$global_pct?>%</div>
  </div>
  <?php if($u_role<=5): ?>
  <div class="stat-card" style="border-color:#8b5cf6"><div class="sl">Posizioni aperte</div><div class="sv" style="color:#8b5cf6"><?=$pos_aperte?></div></div>
  <div class="stat-card" style="border-color:#ec4899"><div class="sl">Candidati pipeline</div><div class="sv" style="color:#ec4899"><?=$cand_pipe?></div></div>
  <?php if ($syncAvviso): ?>
    <?php
      // v1.8.76 — l'avviso in alto compare solo quando c'e' un problema; questa
      // scheda invece e' SEMPRE presente e risponde alla domanda "a quando
      // risale l'ultimo aggiornamento", che si pone anche quando tutto va bene.
      $u   = $syncAvviso['ultima_ok'];
      $ore = $syncAvviso['ore'];
      $cS  = $syncAvviso['in_ritardo'] ? 'var(--danger)'
           : (($ore !== null && $ore > 26) ? 'var(--warning)' : 'var(--success)');
      $val = $u === null ? 'mai'
           : ($ore < 1 ? 'ora' : ($ore < 48 ? $ore . 'h fa' : (int)round($ore / 24) . 'g fa'));
    ?>
    <div class="stat-card" style="border-color:<?=$cS?>"
         title="<?= $u ? 'Ultima sincronizzazione completa: ' . date('d/m/Y H:i', strtotime((string)$u['started_at'])) : 'Nessuna sincronizzazione completa registrata' ?>">
      <div class="sl">Ultima sincronia</div>
      <div class="sv" style="color:<?=$cS?>;font-size:<?=strlen($val) > 5 ? '17px' : '22px'?>"><?=h($val)?></div>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if($exp_s>0||$exp_d>0): ?>
<div class="alert alert-warning" style="margin-bottom:22px">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <strong>Rischio partnership:</strong> <?=$exp_s?> certificazioni in scadenza e <?=$exp_d?> scadute.
  <a href="gap_analysis.php" style="color:inherit;font-weight:700;margin-left:8px">Vedi gap analysis →</a>
</div>
<?php endif; ?>

<!-- ROW 1 -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px">

  <!-- Compliance brand — usa dati pre-calcolati (FIX BUG #11) -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-shield-check" style="color:var(--p)"></i>Brand compliance</span>
      <a href="gap_analysis.php" class="btn btn-blue btn-sm">Dettaglio →</a>
    </div>
    <?php if(empty($brands_data)): ?>
    <div style="text-align:center;color:var(--muted);padding:30px">Nessun brand configurato.</div>
    <?php else: ?>
    <?php foreach(array_slice($brands_data,0,6) as $b):
      $cdata = $compliance_map[$b['id']] ?? ['pct'=>100,'active'=>0,'req'=>(int)$b['req_technical']];
      $pct   = $cdata['pct'];
      $col   = $pct>=80?'var(--success)':($pct>=60?'var(--warning)':'var(--danger)');
    ?>
    <div style="margin-bottom:14px">
      <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:13px">
        <span style="font-weight:600"><?=h($b['name'])?></span>
        <span style="font-weight:800;color:<?=$col?>"><?=$pct?>%</span>
      </div>
      <div style="height:6px;background:#f1f5f9;border-radius:3px">
        <div style="height:6px;background:<?=$col?>;border-radius:3px;width:<?=$pct?>%"></div>
      </div>
      <div style="font-size:10px;color:var(--muted);margin-top:2px"><?=$cdata['active']?>/<?=$cdata['req']?> cert · <?=h($b['partnership_level'])?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Charts -->
  <div style="display:flex;flex-direction:column;gap:18px">
    <div class="card" style="flex:1">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-pie" style="color:var(--p)"></i>Stato certificazioni</span></div>
      <canvas id="cStato" style="max-height:160px"></canvas>
    </div>
    <div class="card" style="flex:1">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-chart-bar" style="color:var(--p)"></i>Top brand</span></div>
      <canvas id="cBrand" style="max-height:130px"></canvas>
    </div>
  </div>
</div>

<!-- ROW 2 -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:22px">

  <!-- Scadenze 30gg -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-clock" style="color:var(--warning)"></i>Scadenze prossimi 30gg</span>
      <a href="visualizza_storico.php" class="btn btn-sm">Vedi tutto</a>
    </div>
    <?php if(empty($scad30)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted)">
      <i class="fa-solid fa-circle-check" style="font-size:28px;color:var(--success);display:block;margin-bottom:8px"></i>
      Nessuna scadenza imminente
    </div>
    <?php else: ?>
    <?php foreach($scad30 as $sc):
      $dc  = (int)$sc['dd'];
      $col = $dc<=7?'var(--danger)':($dc<=14?'var(--warning)':'#f59e0b');
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:9px;border-radius:8px;background:#f8fafc;border-left:3px solid <?=$col?>;margin-bottom:8px">
      <div style="min-width:32px;text-align:center">
        <div style="font-size:16px;font-weight:800;color:<?=$col?>"><?=$dc?></div>
        <div style="font-size:9px;color:var(--muted)">gg</div>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=h($sc['cert_name'])?></div>
        <div style="font-size:11px;color:var(--muted)"><?=h($sc['first_name'].' '.$sc['last_name'])?> · <?=h($sc['brand_name'])?></div>
      </div>
      <div style="font-size:11px;color:<?=$col?>;font-weight:700;white-space:nowrap"><?=format_date($sc['expiry_date'],'d/m')?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Pipeline recruiting -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-filter" style="color:#8b5cf6"></i>Pipeline recruiting</span>
      <?php if(check_ui_permission('recruiting_candidati.php')): ?>
      <a href="recruiting_candidati.php" class="btn btn-sm">Gestisci →</a>
      <?php endif; ?>
    </div>
    <?php
    $stage_labels = [
        'cv_received'    => ['CV ricevuti',      '#6366f1'],
        'screening'      => ['Screening',         '#8b5cf6'],
        'tech_test'      => ['Test tecnico',      '#0ea5e9'],
        'hr_interview'   => ['Coll. HR',          '#f59e0b'],
        'tech_interview' => ['Coll. tecnico',     '#10b981'],
        'offer_sent'     => ['Offerta',           '#059669'],
    ];
    if (empty($pipe_stages)): ?>
    <div style="text-align:center;color:var(--muted);padding:30px">
      Nessun candidato in pipeline.
      <?php if(check_ui_permission('recruiting_posizioni.php')): ?>
      <a href="recruiting_posizioni.php" style="color:var(--p)">Apri posizione →</a>
      <?php endif; ?>
    </div>
    <?php else:
      $mx = max(array_column($pipe_stages,'c'));
      foreach($pipe_stages as $ps):
        [$lb,$col] = $stage_labels[$ps['stage']] ?? [$ps['stage'],'#94a3b8'];
        $pp = $mx > 0 ? round($ps['c']/$mx*100) : 0;
    ?>
    <div style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
        <span style="color:#334155;font-weight:500"><?=$lb?></span>
        <span style="font-weight:700;color:<?=$col?>"><?=$ps['c']?></span>
      </div>
      <div style="height:7px;background:#f1f5f9;border-radius:4px">
        <div style="height:7px;background:<?=$col?>;border-radius:4px;width:<?=$pp?>%"></div>
      </div>
    </div>
    <?php endforeach; endif; ?>

    <?php if(!empty($top_cand)): ?>
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
      <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Top match</div>
      <?php foreach($top_cand as $tc): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid #f8fafc">
        <div>
          <div style="font-weight:600;font-size:12px"><?=h($tc['first_name'].' '.$tc['last_name'])?></div>
          <div style="font-size:11px;color:var(--muted)"><?=h($tc['pos_title'])?></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-weight:800;color:var(--success);font-size:13px"><?=$tc['match_score']?>%</span>
          <a href="recruiting_candidati.php?app_id=<?=$tc['app_id']?>" class="btn btn-blue btn-sm">Score</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const pcolor = '<?=$primary?>';
new Chart(document.getElementById('cStato'),{
  type:'doughnut',
  data:{labels:['Attive','In scadenza','Scadute'],datasets:[{data:[<?=$act?>,<?=$exp_s?>,<?=$exp_d?>],backgroundColor:['#10b981','#f59e0b','#ef4444'],borderWidth:0}]},
  options:{cutout:'68%',plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:11}}}}}
});
new Chart(document.getElementById('cBrand'),{
  type:'bar',
  data:{labels:<?=json_encode(array_keys($g_brand??[]))?>,datasets:[{data:<?=json_encode(array_values($g_brand??[]))?>,backgroundColor:pcolor,borderRadius:5}]},
  options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1,font:{size:10}}},x:{ticks:{font:{size:10}}}}}
});
</script>

<?php require_once('footer.php'); ?>
