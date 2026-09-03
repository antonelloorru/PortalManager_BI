<?php
/**
 * certV 2.2 — publish_posizione.php
 * Pubblicazione posizioni su LinkedIn, Indeed, InfoJobs e altri portali
 * Ruoli: Super Admin (1), HR Director (2), Recruiter (5)
 */
require_once('access_control.php');
require_once('header.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$msg    = '';

$pos_id = (int)($_GET['pos_id'] ?? 0);
if (!$pos_id) { redirect('recruiting_posizioni'); }

// ── Carica posizione ──────────────────────────────────────────────────────────
$pq = $pdo->prepare(
    "SELECT jp.*, b.name brand_name,
            etl.first_name tl_fn, etl.last_name tl_ln
     FROM job_positions jp
     LEFT JOIN brands b ON jp.brand_id = b.id
     LEFT JOIN users tl ON jp.team_leader_id = tl.id
     LEFT JOIN employees etl ON tl.employee_id = etl.id
     WHERE jp.id = ?"
);
$pq->execute([$pos_id]);
$pos = $pq->fetch();
if (!$pos) { redirect('recruiting_posizioni'); }

// ── Settings ──────────────────────────────────────────────────────────────────
$cfg = load_settings();
$li_client_id     = $cfg['linkedin_client_id']     ?? '';
$li_client_secret = $cfg['linkedin_client_secret']  ?? '';
$li_company_id    = $cfg['linkedin_company_id']     ?? '';
$li_token         = $cfg['linkedin_access_token']   ?? '';
$site_url         = rtrim($cfg['company_website']   ?? '', '/');
$apply_url        = $cfg['company_apply_url']       ?? '';
$app_name         = $cfg['app_name']                ?? 'certV';

// URL di candidatura per questa posizione
$job_apply_url = $apply_url ?: ($site_url . '/careers');

// ── Controllo tabella publications (esiste?) ──────────────────────────────────
$table_ok = false;
try {
    $pdo->query("SELECT 1 FROM position_publications LIMIT 1");
    $table_ok = true;
} catch (PDOException $e) {
    $msg = "<div class='alert alert-warning'><i class='fa-solid fa-triangle-exclamation'></i>
        La tabella <code>position_publications</code> non esiste ancora.
        Eseguire prima <strong>migration_position_publications.sql</strong> in phpMyAdmin.</div>";
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table_ok) {
    $action = $_POST['action'] ?? '';

    // ── Salva configurazione OAuth LinkedIn ───────────────────────────────────
    if ($action === 'save_linkedin_config' && $u_role === 1) {
        foreach (['linkedin_client_id','linkedin_client_secret','linkedin_company_id',
                  'company_website','company_apply_url'] as $k) {
            save_setting($k, trim($_POST[$k] ?? ''));
        }
        write_log('Publish','info','Configurazione LinkedIn aggiornata',$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Configurazione salvata.</div>";
        // Ricarica
        $cfg = load_settings_fresh();
        $li_client_id     = $cfg['linkedin_client_id']     ?? '';
        $li_client_secret = $cfg['linkedin_client_secret'] ?? '';
        $li_company_id    = $cfg['linkedin_company_id']    ?? '';
        $site_url         = rtrim($cfg['company_website']  ?? '', '/');
        $apply_url        = $cfg['company_apply_url']      ?? '';
        $job_apply_url    = $apply_url ?: ($site_url . '/careers');
    }

    // ── Pubblica su LinkedIn via API ───────────────────────────────────────────
    if ($action === 'publish_linkedin') {
        if (!$li_token || !$li_company_id) {
            $msg = "<div class='alert alert-warning'>Configura prima il <strong>Company ID</strong> e completa il flusso OAuth LinkedIn.</div>";
        } else {
            $description = $pos['description'] ?? '';
            $skills_text = $pos['required_skills'] ? "\n\n**Competenze richieste:**\n" . $pos['required_skills'] : '';
            $ral_text    = ($pos['ral_min'] || $pos['ral_max'])
                ? "\n\n**RAL:** " . ($pos['ral_min'] ? '€'.number_format($pos['ral_min'],0,',','.'):'') .
                  ($pos['ral_max'] ? ' – €'.number_format($pos['ral_max'],0,',','.') : '')
                : '';

            $body = [
                "author"          => "urn:li:organization:" . $li_company_id,
                "lifecycleState"  => "PUBLISHED",
                "specificContent" => [
                    "com.linkedin.ugc.ShareContent" => [
                        "shareCommentary" => [
                            "text" => "🚀 Stiamo cercando: **{$pos['title']}**\n\n"
                                     . ($description ? substr($description,0,700)."..." : '')
                                     . $skills_text . $ral_text
                                     . "\n\n📍 {$pos['location']} · {$pos['remote_policy']}"
                                     . "\n📩 Candidati: " . $job_apply_url
                                     . "\n\n#hiring #jobs #" . preg_replace('/\s+/','',$pos['title'])
                        ],
                        "shareMediaCategory" => "NONE"
                    ]
                ],
                "visibility" => ["com.linkedin.ugc.MemberNetworkVisibility" => "PUBLIC"]
            ];

            $ch = curl_init('https://api.linkedin.com/v2/ugcPosts');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $li_token,
                    'Content-Type: application/json',
                    'X-Restli-Protocol-Version: 2.0.0',
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            $resp     = curl_exec($ch);
            $http_code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            if ($http_code === 201) {
                $data    = json_decode($resp, true);
                $post_id = $data['id'] ?? null;
                $post_url= $post_id ? 'https://www.linkedin.com/feed/update/' . $post_id : null;
                $pdo->prepare(
                    "INSERT INTO position_publications
                     (position_id,channel,channel_url,status,published_at,published_by,api_post_id,expires_at)
                     VALUES (?,?,?,?,NOW(),?,?,?)"
                )->execute([
                    $pos_id, 'linkedin', $post_url, 'published',
                    $u_id, $post_id,
                    date('Y-m-d', strtotime('+30 days'))
                ]);
                write_log('Publish','success',"Posizione #$pos_id pubblicata su LinkedIn",$u_id);
                $msg = "<div class='alert alert-success'><i class='fa-brands fa-linkedin' style='color:#0077b5'></i>
                    Post pubblicato su LinkedIn!" .
                    ($post_url ? " <a href='$post_url' target='_blank' style='color:inherit;font-weight:700'>Visualizza post →</a>" : '') .
                    "</div>";
            } else {
                $err_detail = json_decode($resp, true)['message'] ?? $resp;
                write_log('Publish','error',"Errore LinkedIn publish: HTTP $http_code — $err_detail",$u_id);
                $msg = "<div class='alert alert-danger'>Errore LinkedIn (HTTP $http_code): " . h($err_detail) .
                       "<br><small>Verifica che il token sia valido e abbia lo scope <code>w_member_social</code> o <code>w_organization_social</code>.</small></div>";
            }
        }
    }

    // ── Registra pubblicazione manuale (Indeed, InfoJobs, Glassdoor…) ─────────
    if ($action === 'register_manual') {
        $channel     = $_POST['channel'] ?? 'custom';
        $channel_url = trim($_POST['channel_url'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $exp_date    = $_POST['expires_at'] ?: null;

        $pdo->prepare(
            "INSERT INTO position_publications
             (position_id,channel,channel_url,status,published_at,published_by,expires_at,notes)
             VALUES (?,?,?,'published',NOW(),?,?,?)"
        )->execute([$pos_id, $channel, $channel_url ?: null, $u_id, $exp_date, $notes ?: null]);
        write_log('Publish','success',"Posizione #$pos_id registrata su $channel",$u_id);
        $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Pubblicazione registrata.</div>";
    }

    // ── Rimuovi registrazione ─────────────────────────────────────────────────
    if ($action === 'remove_pub') {
        $pub_id = (int)($_POST['pub_id'] ?? 0);
        $pdo->prepare("UPDATE position_publications SET status='removed' WHERE id=? AND position_id=?")
            ->execute([$pub_id, $pos_id]);
        $msg = "<div class='alert alert-success'>Pubblicazione rimossa.</div>";
    }

    // ── Salva token LinkedIn (da OAuth callback) ──────────────────────────────
    if ($action === 'save_token' && $u_role === 1) {
        save_setting('linkedin_access_token', trim($_POST['linkedin_access_token'] ?? ''));
        $msg = "<div class='alert alert-success'>Access Token salvato.</div>";
        $li_token = trim($_POST['linkedin_access_token'] ?? '');
    }
}

// ── Carica pubblicazioni esistenti ────────────────────────────────────────────
$pubs = [];
if ($table_ok) {
    $pq2 = $pdo->prepare(
        "SELECT pp.*, e.first_name pub_fn, e.last_name pub_ln
         FROM position_publications pp
         LEFT JOIN users u ON pp.published_by = u.id
         LEFT JOIN employees e ON u.employee_id = e.id
         WHERE pp.position_id = ?
         ORDER BY pp.created_at DESC"
    );
    $pq2->execute([$pos_id]);
    $pubs = $pq2->fetchAll();
}

// ── Genera testo annuncio ─────────────────────────────────────────────────────
function build_post_text(array $pos, string $apply_url, string $format = 'linkedin'): string {
    $title   = $pos['title'];
    $dept    = $pos['department'] ? " · {$pos['department']}" : '';
    $loc     = $pos['location'] ?? 'Da definire';
    $remote  = $pos['remote_policy'] ?? '';
    $contract= $pos['contract_type'] ?? '';
    $desc    = $pos['description'] ? "\n\n" . substr($pos['description'], 0, 500) . (strlen($pos['description'])>500?'…':'') : '';
    $skills  = $pos['required_skills'] ? "\n\n🎯 Cerchiamo:\n" . $pos['required_skills'] : '';
    $ral     = '';
    if ($pos['ral_min'] || $pos['ral_max']) {
        $ral = "\n\n💶 RAL: " . ($pos['ral_min']?'€'.number_format($pos['ral_min'],0,',','.'):'') .
               ($pos['ral_max']?' – €'.number_format($pos['ral_max'],0,',','.'):'');
    }
    $tag     = '#' . preg_replace('/[^a-zA-Z0-9]/', '', $title);
    $brand   = $pos['brand_name'] ? " · {$pos['brand_name']}" : '';

    if ($format === 'linkedin') {
        return "🚀 Stiamo assumendo: {$title}{$dept}{$brand}{$desc}{$skills}{$ral}\n\n"
             . "📍 {$loc} · {$remote}\n"
             . "📋 {$contract}\n\n"
             . "👉 Candidati qui: {$apply_url}\n\n"
             . "#hiring #lavoro #jobs {$tag} #IT";
    }
    if ($format === 'indeed') {
        return "Ruolo: {$title}\n"
             . "Sede: {$loc} ({$remote})\n"
             . "Contratto: {$contract}\n"
             . ($desc ? "Descrizione: " . strip_tags($desc) . "\n" : '')
             . ($pos['required_skills'] ? "Requisiti: " . $pos['required_skills'] . "\n" : '')
             . ($ral ? "RAL: " . strip_tags($ral) . "\n" : '')
             . "Candidature: {$apply_url}";
    }
    // generic
    return "{$title}\n{$loc} · {$remote} · {$contract}{$desc}{$skills}{$ral}\n\nCandidati: {$apply_url}";
}

$text_linkedin = build_post_text($pos, $job_apply_url, 'linkedin');
$text_indeed   = build_post_text($pos, $job_apply_url, 'indeed');
$text_generic  = build_post_text($pos, $job_apply_url, 'generic');

// Portali con URL diretti (deep links a form di pubblicazione)
$portals = [
    'linkedin'   => [
        'label' => 'LinkedIn',
        'color' => '#0077b5',
        'icon'  => 'fa-brands fa-linkedin',
        'api'   => true,
        'post_url' => 'https://www.linkedin.com/jobs/post/',
        'share_url'=> 'https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode($job_apply_url),
        'desc'  => 'API ufficiale o condivisione manuale'
    ],
    'indeed'     => [
        'label' => 'Indeed',
        'color' => '#003A9B',
        'icon'  => 'fa-solid fa-magnifying-glass',
        'api'   => false,
        'post_url' => 'https://employers.indeed.com/p/post-job',
        'share_url'=> null,
        'desc'  => 'Copia testo → incolla nel pannello employer'
    ],
    'infojobs'   => [
        'label' => 'InfoJobs',
        'color' => '#164194',
        'icon'  => 'fa-solid fa-briefcase',
        'api'   => false,
        'post_url' => 'https://www.infojobs.it/pubblicazione-annunci/',
        'share_url'=> null,
        'desc'  => 'Portale annunci italiano'
    ],
    'glassdoor'  => [
        'label' => 'Glassdoor',
        'color' => '#0CAA41',
        'icon'  => 'fa-solid fa-star',
        'api'   => false,
        'post_url' => 'https://www.glassdoor.com/employers/job-posting/',
        'share_url'=> null,
        'desc'  => 'Con employer account gratuito'
    ],
    'monster'    => [
        'label' => 'Monster',
        'color' => '#6D0099',
        'icon'  => 'fa-solid fa-ghost',
        'api'   => false,
        'post_url' => 'https://hiring.monster.com/',
        'share_url'=> null,
        'desc'  => 'Monster Hiring platform'
    ],
    'jobrapido'  => [
        'label' => 'Jobrapido',
        'color' => '#E31837',
        'icon'  => 'fa-solid fa-bolt',
        'api'   => false,
        'post_url' => 'https://it.jobrapido.com/employers',
        'share_url'=> null,
        'desc'  => 'Aggregatore internazionale'
    ],
];

$pub_channels = array_column($pubs, 'channel');
$active_tab   = $_GET['tab'] ?? 'publish';
?>

<!-- HEADER -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <div style="font-size:12px;color:var(--muted);margin-bottom:4px">
      <a href="recruiting_posizioni.php" style="color:var(--p);text-decoration:none">
        <i class="fa-solid fa-arrow-left"></i> Posizioni aperte
      </a>
      <span style="margin:0 6px">›</span> Pubblica su portali esterni
    </div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:4px">
      <i class="fa-solid fa-share-nodes" style="color:var(--p);margin-right:10px"></i><?=h($pos['title'])?>
    </h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php
      $sb_map = ['draft'=>'badge-neutral','open'=>'badge-success','paused'=>'badge-warning','closed'=>'badge-neutral','cancelled'=>'badge-danger'];
      $sl_map = ['draft'=>'Bozza','open'=>'Aperta','paused'=>'In pausa','closed'=>'Chiusa','cancelled'=>'Annullata'];
      ?>
      <span class="badge <?=$sb_map[$pos['status']]??'badge-neutral'?>"><?=$sl_map[$pos['status']]??$pos['status']?></span>
      <?php if($pos['department']): ?><span style="font-size:12px;color:var(--muted)"><?=h($pos['department'])?></span><?php endif; ?>
      <?php if($pos['brand_name']): ?><span style="font-size:12px;color:var(--muted)">· <?=h($pos['brand_name'])?></span><?php endif; ?>
      <span style="font-size:12px;color:var(--muted)">· <?=count(array_filter($pubs, fn($p)=>$p['status']==='published'))?> pubblicazioni attive</span>
    </div>
  </div>
</div>

<?=$msg?>

<?php if($pos['status'] !== 'open'): ?>
<div class="alert alert-warning">
  <i class="fa-solid fa-triangle-exclamation"></i>
  La posizione è in stato <strong><?=$sl_map[$pos['status']]?></strong>.
  Per pubblicarla sui portali è consigliabile prima approvarla e portarla in stato <strong>Aperta</strong>.
</div>
<?php endif; ?>

<!-- TAB NAV -->
<div class="no-print" style="display:flex;gap:2px;margin-bottom:22px;background:#f1f5f9;border-radius:10px;padding:4px">
  <?php foreach([
    ['publish', 'fa-share-nodes',    'Pubblica'],
    ['texts',   'fa-align-left',     'Testi annuncio'],
    ['history', 'fa-clock-rotate-left','Storico'],
    ['config',  'fa-gear',           'Configurazione API'],
  ] as [$tab,$icon,$label]): ?>
  <a href="<?= qs_self_safe(['pos_id'=>''.($pos_id).'', 'tab'=>''.($tab).'']) ?>"
     style="flex:1;text-align:center;padding:8px 14px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:600;
            <?=$active_tab===$tab?'background:#fff;color:var(--p);box-shadow:0 1px 3px rgba(0,0,0,.08)':'color:var(--muted)'?>">
    <i class="fa-solid <?=$icon?>" style="margin-right:5px"></i><?=$label?>
  </a>
  <?php endforeach; ?>
</div>

<!-- ══ TAB: PUBBLICA ══════════════════════════════════════════════════════════ -->
<?php if($active_tab === 'publish'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
  <?php foreach($portals as $pkey => $portal):
    $pub_active = array_filter($pubs, fn($p) => $p['channel']===$pkey && $p['status']==='published');
    $last_pub   = $pub_active ? end($pub_active) : null;
  ?>
  <div class="card" style="<?=$last_pub?'border-color:'.h($portal['color']).';background:'.h($portal['color']).'08':'border-color:var(--border)'?>">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
      <div style="width:40px;height:40px;border-radius:9px;background:<?=h($portal['color'])?>18;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="<?=h($portal['icon'])?>" style="color:<?=h($portal['color'])?>;font-size:18px"></i>
      </div>
      <div style="flex:1">
        <div style="font-size:14px;font-weight:800"><?=h($portal['label'])?></div>
        <div style="font-size:11px;color:var(--muted)"><?=h($portal['desc'])?></div>
      </div>
      <?php if($last_pub): ?>
      <span class="badge badge-success" style="font-size:9px">Pubblicato</span>
      <?php endif; ?>
    </div>

    <?php if($last_pub): ?>
    <div style="background:#f0fdf4;border-radius:7px;padding:9px 12px;font-size:11px;margin-bottom:12px">
      <div style="color:#065f46;font-weight:700">
        <i class="fa-solid fa-circle-check"></i> Pubblicato il <?=date('d/m/Y H:i', strtotime($last_pub['published_at']))?>
      </div>
      <?php if($last_pub['channel_url']): ?>
      <a href="<?=h($last_pub['channel_url'])?>" target="_blank" style="color:#065f46;font-size:10px">
        <?=h(substr($last_pub['channel_url'],0,55))?>… <i class="fa-solid fa-arrow-up-right-from-square"></i>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Azioni -->
    <div style="display:flex;flex-direction:column;gap:6px">

      <?php if($pkey === 'linkedin' && $portal['api']): ?>
        <!-- LinkedIn: API diretta -->
        <?php if($li_token && $li_company_id): ?>
        <form method="POST">
        <?= csrf_field() ?>
          <input type="hidden" name="action" value="publish_linkedin">
          <button type="submit" class="btn" style="width:100%;justify-content:center;background:<?=$portal['color']?>;color:#fff;border-color:<?=$portal['color']?>;padding:10px"
                  onclick="return confirm('Pubblicare su LinkedIn tramite API? Il post verrà pubblicato sulla pagina aziendale.')">
            <i class="<?=$portal['icon']?>"></i> Pubblica via API
          </button>
        </form>
        <?php else: ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:8px 12px;font-size:11px;color:#92400e;margin-bottom:6px">
          <i class="fa-solid fa-key"></i> API non configurata — <a href="<?= qs_self_safe(['pos_id'=>''.($pos_id).'', 'tab'=>'config']) ?>" style="color:inherit;font-weight:700">configura →</a>
        </div>
        <?php endif; ?>
        <!-- Condivisione manuale alternativa -->
        <a href="<?=$portal['share_url']?>" target="_blank" class="btn" style="width:100%;justify-content:center;padding:8px;font-size:12px;background:#e7f3f8;color:<?=$portal['color']?>;border-color:<?=$portal['color']?>22">
          <i class="fa-solid fa-arrow-up-right-from-square"></i> Condividi link manuale
        </a>

      <?php else: ?>
        <!-- Altri portali: apertura diretta + registrazione -->
        <a href="<?=h($portal['post_url'])?>" target="_blank" class="btn"
           style="width:100%;justify-content:center;background:<?=h($portal['color'])?>;color:#fff;border-color:<?=h($portal['color'])?>;padding:10px">
          <i class="<?=h($portal['icon'])?>"></i> Vai su <?=h($portal['label'])?>
        </a>
      <?php endif; ?>

      <!-- Copia testo annuncio -->
      <button onclick="copyText(<?=htmlspecialchars(json_encode($text_linkedin),ENT_QUOTES)?>, this)"
              class="btn btn-sm" style="width:100%;justify-content:center;font-size:11px">
        <i class="fa-solid fa-copy"></i> Copia testo annuncio
      </button>

      <!-- Registra pubblicazione manuale -->
      <button onclick="openRegModal('<?=$pkey?>','<?=h($portal['label'])?>')"
              class="btn btn-sm" style="width:100%;justify-content:center;font-size:11px;background:#f0f9ff;color:#0369a1">
        <i class="fa-solid fa-plus"></i> Registra pubblicazione manuale
      </button>

    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ TAB: TESTI ══════════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'texts'): ?>
<div style="display:flex;flex-direction:column;gap:16px">

  <?php foreach([
    ['LinkedIn / Social', $text_linkedin, '#0077b5', 'fa-brands fa-linkedin'],
    ['Indeed / Email',    $text_indeed,   '#003A9B', 'fa-solid fa-envelope'],
    ['Generico / Altro',  $text_generic,  '#475569', 'fa-solid fa-file-lines'],
  ] as [$fmt_label, $fmt_text, $fmt_color, $fmt_icon]): ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title">
        <i class="<?=$fmt_icon?>" style="color:<?=$fmt_color?>"></i> <?=h($fmt_label)?>
      </span>
      <button onclick="copyText(<?=htmlspecialchars(json_encode($fmt_text),ENT_QUOTES)?>, this)" class="btn btn-sm btn-primary">
        <i class="fa-solid fa-copy"></i> Copia
      </button>
    </div>
    <textarea id="txt_<?=md5($fmt_label)?>" rows="12" readonly
              style="width:100%;padding:12px;border:1px solid var(--border);border-radius:8px;font-size:12px;font-family:monospace;resize:vertical;background:#f8fafc;color:#334155;line-height:1.6"><?=h($fmt_text)?></textarea>
    <div style="font-size:10px;color:var(--muted);margin-top:4px">
      <?=strlen($fmt_text)?> caratteri
      <?php if($fmt_label === 'LinkedIn / Social'): ?> · LinkedIn consiglia max 1.300 caratteri per post<?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- URL candidatura configurata -->
  <div class="card" style="background:#f0f9ff;border-color:#bae6fd">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-link" style="color:var(--p)"></i> URL candidatura usato negli annunci</span>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
      <code style="flex:1;padding:10px 14px;background:#fff;border:1px solid var(--border);border-radius:7px;font-size:13px;color:#0369a1">
        <?=h($job_apply_url ?: '— non configurato')?>
      </code>
      <?php if($job_apply_url): ?>
      <button onclick="copyText('<?=h($job_apply_url)?>', this)" class="btn btn-sm btn-primary">
        <i class="fa-solid fa-copy"></i>
      </button>
      <a href="<?= qs_self_safe(['pos_id'=>''.($pos_id).'', 'tab'=>'config']) ?>" class="btn btn-sm">Modifica</a>
      <?php else: ?>
      <a href="<?= qs_self_safe(['pos_id'=>''.($pos_id).'', 'tab'=>'config']) ?>" class="btn btn-sm btn-primary">Configura URL →</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ══ TAB: STORICO ════════════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'history'): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--p)"></i> Storico pubblicazioni (<?=count($pubs)?>)</span>
  </div>
  <?php if(empty($pubs)): ?>
  <div style="text-align:center;padding:40px;color:var(--muted)">
    <i class="fa-solid fa-share-nodes" style="font-size:36px;opacity:.3;display:block;margin-bottom:10px"></i>
    Nessuna pubblicazione registrata per questa posizione.
  </div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('publish_posizione', '#lf-table-publish_posizione', ['export_filename' => 'publish_posizione', 'title' => 'Pubblicazione posizioni']); ?>
<table id="lf-table-publish_posizione" class="data-table">
    <thead><tr>
      <th>Portale</th><th>Pubblicato il</th><th>Scadenza</th>
      <th>Pubblicato da</th><th>Stato</th><th>Link</th><th class="no-print">Azioni</th>
    </tr></thead>
    <tbody>
    <?php
    $ch_colors = ['linkedin'=>'#0077b5','indeed'=>'#003A9B','infojobs'=>'#164194',
                  'glassdoor'=>'#0CAA41','monster'=>'#6D0099','jobrapido'=>'#E31837','custom'=>'#475569'];
    $ch_icons  = ['linkedin'=>'fa-brands fa-linkedin','indeed'=>'fa-solid fa-magnifying-glass',
                  'infojobs'=>'fa-solid fa-briefcase','glassdoor'=>'fa-solid fa-star',
                  'monster'=>'fa-solid fa-ghost','jobrapido'=>'fa-solid fa-bolt','custom'=>'fa-solid fa-globe'];
    foreach($pubs as $pub):
      $pcolor = $ch_colors[$pub['channel']] ?? '#475569';
      $picon  = $ch_icons[$pub['channel']]  ?? 'fa-solid fa-globe';
    ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:8px">
          <i class="<?=$picon?>" style="color:<?=$pcolor?>;font-size:14px"></i>
          <span style="font-weight:600"><?=ucfirst(h($pub['channel']))?></span>
        </div>
      </td>
      <td style="font-size:12px"><?=$pub['published_at']?date('d/m/Y H:i',strtotime($pub['published_at'])):'—'?></td>
      <td style="font-size:12px">
        <?php if($pub['expires_at']): ?>
        <?php $dd=days_diff($pub['expires_at']); ?>
        <span style="color:<?=$dd<7?'var(--danger)':($dd<30?'var(--warning)':'var(--muted)')?>">
          <?=format_date($pub['expires_at'])?> <?=$dd>=0?"(fra {$dd}gg)":'(scaduto)'?>
        </span>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td style="font-size:12px"><?=h(($pub['pub_fn']??'').' '.($pub['pub_ln']??''))?></td>
      <td>
        <?php $st_badge=['published'=>'badge-success','expired'=>'badge-warning','removed'=>'badge-neutral','draft'=>'badge-neutral']; ?>
        <span class="badge <?=$st_badge[$pub['status']]??'badge-neutral'?>" style="font-size:9px">
          <?=ucfirst($pub['status'])?>
        </span>
      </td>
      <td>
        <?php if($pub['channel_url']): ?>
        <a href="<?=h($pub['channel_url'])?>" target="_blank" style="font-size:11px;color:var(--p)">
          <i class="fa-solid fa-arrow-up-right-from-square"></i> Apri
        </a>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="no-print">
        <?php if($pub['status']==='published'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere questa pubblicazione dal registro?')">
        <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_pub">
          <input type="hidden" name="pub_id" value="<?=$pub['id']?>">
          <button type="submit" class="btn btn-danger btn-sm" title="Segna come rimossa">
            <i class="fa-solid fa-ban"></i>
          </button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- ══ TAB: CONFIGURAZIONE ═══════════════════════════════════════════════════ -->
<?php elseif($active_tab === 'config'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">

  <!-- Config URL candidatura -->
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-link" style="color:var(--p)"></i> URL candidatura & sito</span></div>
    <?php if($u_role <= 2): ?>
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_linkedin_config">
      <input type="hidden" name="linkedin_client_id" value="<?=h($li_client_id)?>">
      <input type="hidden" name="linkedin_client_secret" value="<?=h($li_client_secret)?>">
      <input type="hidden" name="linkedin_company_id" value="<?=h($li_company_id)?>">
      <div class="form-group">
        <label>Sito web aziendale</label>
        <input type="url" name="company_website" value="<?=h($site_url)?>" placeholder="https://www.azienda.it">
      </div>
      <div class="form-group" style="margin:0">
        <label>URL pagina candidature / careers</label>
        <input type="url" name="company_apply_url" value="<?=h($apply_url)?>" placeholder="https://www.azienda.it/careers">
        <div style="font-size:10px;color:var(--muted);margin-top:4px">
          Questo URL verrà incluso in tutti i testi degli annunci generati
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:14px;width:100%;justify-content:center;padding:11px">
        <i class="fa-solid fa-floppy-disk"></i> Salva
      </button>
    </form>
    <?php else: ?>
    <div class="alert alert-info">Solo Admin e HR Director possono modificare queste impostazioni.</div>
    <?php endif; ?>
  </div>

  <!-- Config LinkedIn API -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-brands fa-linkedin" style="color:#0077b5"></i> LinkedIn API</span>
      <?php if($li_token): ?><span class="badge badge-success" style="font-size:9px">Token configurato</span><?php endif; ?>
    </div>
    <?php if($u_role === 1): ?>
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_linkedin_config">
      <input type="hidden" name="company_website" value="<?=h($site_url)?>">
      <input type="hidden" name="company_apply_url" value="<?=h($apply_url)?>">
      <div class="form-group">
        <label>Client ID (developer.linkedin.com)</label>
        <input type="text" name="linkedin_client_id" value="<?=h($li_client_id)?>" placeholder="86xxxx">
      </div>
      <div class="form-group">
        <label>Client Secret</label>
        <input type="password" name="linkedin_client_secret" value="<?=h($li_client_secret)?>" placeholder="●●●●●●●●">
      </div>
      <div class="form-group" style="margin:0">
        <label>Company ID (URL pagina LinkedIn azienda)</label>
        <input type="text" name="linkedin_company_id" value="<?=h($li_company_id)?>" placeholder="Es. 12345678">
        <div style="font-size:10px;color:var(--muted);margin-top:4px">
          Trovalo nell'URL della tua pagina LinkedIn aziendale: linkedin.com/company/<strong>12345678</strong>
        </div>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:14px;width:100%;justify-content:center;padding:11px">
        <i class="fa-solid fa-floppy-disk"></i> Salva configurazione API
      </button>
    </form>
    <hr style="margin:16px 0;border-color:var(--border)">
    <!-- Token OAuth -->
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_token">
      <div class="form-group" style="margin:0">
        <label>Access Token OAuth2</label>
        <textarea name="linkedin_access_token" rows="3" placeholder="Incolla qui il Bearer token OAuth2..."
                  style="font-family:monospace;font-size:11px"><?=h($li_token)?></textarea>
        <div style="font-size:10px;color:var(--muted);margin-top:4px">
          Il token scade ogni 60 giorni. Scope necessario: <code>w_organization_social</code>
        </div>
      </div>
      <button type="submit" class="btn btn-sm" style="margin-top:10px;width:100%;justify-content:center">
        Salva token
      </button>
    </form>
    <?php else: ?>
    <div class="alert alert-info">Solo il Super Admin può modificare le credenziali API.</div>
    <?php if($li_client_id): ?><p style="font-size:12px;color:var(--muted);margin-top:8px">Client ID configurato: <code><?=h(substr($li_client_id,0,6))?>…</code></p><?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Guida rapida -->
  <div class="card" style="grid-column:span 2;background:#f8fafc">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-book" style="color:var(--muted)"></i> Guida rapida all'integrazione</span></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;font-size:12px;color:var(--muted);line-height:1.7">
      <div>
        <div style="font-weight:700;color:#1e293b;margin-bottom:6px"><i class="fa-brands fa-linkedin" style="color:#0077b5;margin-right:5px"></i>LinkedIn (API automatica)</div>
        <ol style="padding-left:16px">
          <li>Crea un'app su <a href="https://developer.linkedin.com/apps" target="_blank" style="color:var(--p)">developer.linkedin.com</a></li>
          <li>Aggiungi i product: <em>Share on LinkedIn</em> + <em>Sign In with LinkedIn</em></li>
          <li>Configura Redirect URL: <code><?=h($site_url ?: 'https://tuositio.it')?>/oauth_callback.php</code></li>
          <li>Copia Client ID e Secret nella tab Configurazione</li>
          <li>Genera il token OAuth con scope <code>w_organization_social</code></li>
          <li>Incolla il Bearer token nella configurazione</li>
        </ol>
      </div>
      <div>
        <div style="font-weight:700;color:#1e293b;margin-bottom:6px"><i class="fa-solid fa-hand-pointer" style="margin-right:5px"></i>Portali manuali (Indeed, InfoJobs…)</div>
        <ol style="padding-left:16px">
          <li>Clicca "Vai su [portale]" → si apre il pannello employer</li>
          <li>Clicca "Copia testo annuncio" in certV</li>
          <li>Incolla il testo nel form del portale</li>
          <li>Completa le informazioni mancanti (RAL, sede, ecc.)</li>
          <li>Pubblica e poi torna su certV</li>
          <li>Clicca "Registra pubblicazione manuale" con l'URL del post</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══ MODAL: Registra pubblicazione manuale ════════════════════════════════ -->
<div id="mReg" class="modal-overlay" style="z-index:1000">
  <div class="modal-box" style="width:480px">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px;align-items:center">
      <h3 style="margin:0;font-size:15px"><i class="fa-solid fa-plus-circle" style="color:var(--p);margin-right:8px"></i>Registra pubblicazione manuale</h3>
      <button onclick="closeModal('mReg')" style="border:none;background:none;font-size:22px;cursor:pointer;color:var(--muted)">&times;</button>
    </div>
    <p id="mRegChannel" style="font-size:13px;color:var(--muted);margin-bottom:14px"></p>
    <form method="POST">
        <?= csrf_field() ?>
      <input type="hidden" name="action" value="register_manual">
      <input type="hidden" name="channel" id="mRegChannelVal">
      <div class="form-group">
        <label>URL del post / annuncio (opzionale)</label>
        <input type="url" name="channel_url" placeholder="https://www.linkedin.com/jobs/view/...">
      </div>
      <div class="form-group">
        <label>Scadenza prevista</label>
        <input type="date" name="expires_at" value="<?=date('Y-m-d', strtotime('+30 days'))?>">
      </div>
      <div class="form-group">
        <label>Note</label>
        <textarea name="notes" rows="2" placeholder="Es. costo sponsorizzazione, ID interno…"></textarea>
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:11px">
          <i class="fa-solid fa-floppy-disk"></i> Salva
        </button>
        <button type="button" onclick="closeModal('mReg')" class="btn" style="flex:1;justify-content:center;padding:11px">Annulla</button>
      </div>
    </form>
  </div>
</div>

<script>
function copyText(text, btn) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => {
      const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Copiato!';
      btn.style.background = 'var(--success)';
      btn.style.color = '#fff';
      setTimeout(() => { btn.innerHTML = orig; btn.style.background=''; btn.style.color=''; }, 2000);
    });
  } else {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); document.body.removeChild(ta);
    alert('Testo copiato!');
  }
}
function openRegModal(channel, label) {
  document.getElementById('mRegChannelVal').value = channel;
  document.getElementById('mRegChannel').textContent = 'Portale: ' + label;
  document.getElementById('mReg').style.display = 'flex';
}
function closeModal(id) { document.getElementById(id).style.display='none'; }
</script>

<?php require_once('footer.php'); ?>
