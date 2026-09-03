<?php
/**
 * certV 2.0 v2.2 — user_profile.php  Dossier professionale
 * v2.2: carica dati anagrafici da employees, dati accesso da users
 *       Supporta ?id=USER_ID e ?emp_id=EMPLOYEE_ID
 */
require_once('access_control.php');

$viewer_id   = (int)$_SESSION['user_id'];
$viewer_role = (int)($_SESSION['role_id'] ?? 99);
$viewer_emp  = (int)($_SESSION['employee_id'] ?? 0);

// Risolve il profilo da user_id o employee_id
if (isset($_GET['emp_id'])) {
    $emp_id = (int)$_GET['emp_id'];
} elseif (isset($_GET['id'])) {
    // Retro-compat: GET id = user_id → ricava employee_id
    $tmp = $pdo->prepare("SELECT employee_id FROM users WHERE id=?");
    $tmp->execute([(int)$_GET['id']]);
    $emp_id = (int)($tmp->fetchColumn() ?: 0);
    if (!$emp_id) $emp_id = $viewer_emp; // fallback al proprio
} else {
    $emp_id = $viewer_emp;
}

// Dipendente vede solo se stesso (PRIMA di header.php per evitare "headers already sent")
if ($viewer_role === 6 && $emp_id !== $viewer_emp) {
    redirect_self(['emp_id' => $viewer_emp]);
}

// v1.7.16: header.php DOPO le validazioni che possono fare redirect
require_once('header.php');

// ── SELF-EDIT: gestione POST per aggiornamento dati personali ────────
// L'utente può modificare i propri dati "safe" (telefono personale, email
// personale, social URL, bio, skills). Campi HR (azienda, contratto, qualifica)
// restano riservati ad Admin/HR via manage_employees.php.
$self_edit_msg = '';
$can_self_edit = ($emp_id === $viewer_emp && $emp_id > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'self_edit'
    && $can_self_edit) {

    // CSRF verify auto-fatto da bootstrap.php (Csrf::auto)
    try {
        // Normalizza URL Credly/LinkedIn
        $credly_in   = trim($_POST['credly_url']   ?? '');
        $linkedin_in = trim($_POST['linkedin_url'] ?? '');
        if ($credly_in && !preg_match('~^https?://~i', $credly_in)) {
            $credly_in = 'https://www.credly.com/users/' . ltrim($credly_in, '/');
        }
        if ($linkedin_in && !preg_match('~^https?://~i', $linkedin_in)) {
            $linkedin_in = 'https://www.linkedin.com/in/' . ltrim($linkedin_in, '/');
        }

        $pdo->prepare(
            "UPDATE employees SET
               phone           = ?,
               phone_personal  = ?,
               personal_email  = ?,
               credly_url      = ?,
               linkedin_url    = ?,
               bio             = ?,
               technical_skills= ?,
               soft_skills     = ?
             WHERE id = ?"
        )->execute([
            trim($_POST['phone']           ?? '') ?: null,
            trim($_POST['phone_personal']  ?? '') ?: null,
            trim($_POST['personal_email']  ?? '') ?: null,
            $credly_in   ?: null,
            $linkedin_in ?: null,
            trim($_POST['bio']             ?? '') ?: null,
            trim($_POST['technical_skills']?? '') ?: null,
            trim($_POST['soft_skills']     ?? '') ?: null,
            $emp_id,
        ]);

        // Sync employee_credly_link
        if ($credly_in && preg_match('~credly\.com/users/([^/?#\s]+)~i', $credly_in, $cm)) {
            try {
                $pdo->prepare(
                    "INSERT INTO employee_credly_link (employee_id, credly_username, created_by, created_at)
                     VALUES (?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE credly_username=VALUES(credly_username), updated_at=NOW()"
                )->execute([$emp_id, $cm[1], $viewer_id]);
            } catch (Throwable $e) {}
        } elseif (!$credly_in) {
            try { $pdo->prepare("DELETE FROM employee_credly_link WHERE employee_id=?")->execute([$emp_id]); } catch (Throwable $e) {}
        }

        // Sync employee_linkedin_link
        if ($linkedin_in && preg_match('~linkedin\.com/in/([^/?#\s]+)~i', $linkedin_in, $lm)) {
            try {
                $pdo->prepare(
                    "INSERT INTO employee_linkedin_link (employee_id, linkedin_vanity, created_by, created_at)
                     VALUES (?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE linkedin_vanity=VALUES(linkedin_vanity), updated_at=NOW()"
                )->execute([$emp_id, $lm[1], $viewer_id]);
            } catch (Throwable $e) {}
        } elseif (!$linkedin_in) {
            try { $pdo->prepare("DELETE FROM employee_linkedin_link WHERE employee_id=?")->execute([$emp_id]); } catch (Throwable $e) {}
        }

        if (function_exists('write_log')) {
            write_log('UserProfile', 'success', "Self-edit profilo emp=$emp_id", $viewer_id);
        }

        $self_edit_msg = '<div class="alert alert-success" style="margin-bottom:14px"><i class="fa-solid fa-check"></i> Dati personali aggiornati.</div>';

    } catch (Throwable $e) {
        $self_edit_msg = '<div class="alert alert-danger" style="margin-bottom:14px"><i class="fa-solid fa-triangle-exclamation"></i> Errore aggiornamento: ' . h($e->getMessage()) . '</div>';
        if (function_exists('write_log')) {
            write_log('UserProfile', 'error', "Self-edit emp=$emp_id: " . $e->getMessage(), $viewer_id);
        }
    }
}

// Recupero dati employee + user collegato
$us = $pdo->prepare(
    "SELECT e.*,
            co.name company_name,
            loc.location_name,
            wm.name mode_name, wm.color_hex mode_color,
            u.id user_id, u.email, r.name role_name, r.id role_id
     FROM employees e
     LEFT JOIN companies co         ON e.company_id   = co.id
     LEFT JOIN company_locations loc ON e.location_id = loc.id
     LEFT JOIN work_modes wm         ON e.work_mode_id = wm.id
     LEFT JOIN users u               ON u.employee_id  = e.id AND u.status='active'
     LEFT JOIN roles r               ON u.role_id      = r.id
     WHERE e.id=?"
);
$us->execute([$emp_id]);
$user = $us->fetch();
if (!$user) {
    echo "<div class='alert alert-danger'>Dipendente non trovato.</div>";
    require_once('footer.php'); exit();
}

// Certificazioni — query estesa con metadata Credly
$cs = $pdo->prepare(
    "SELECT uc.*, cert.name cert_name, cert.code cert_code,
            cert.credly_template_id, cert.exam_url cert_exam_url,
            b.name brand_name, b.logo_path brand_logo
     FROM user_certifications uc
     JOIN certifications cert ON uc.certification_id = cert.id
     JOIN brands b            ON cert.brand_id = b.id
     WHERE uc.employee_id = ?
     ORDER BY (uc.expiry_date IS NULL), uc.expiry_date DESC, uc.issue_date DESC"
);
$cs->execute([$emp_id]);
$certs = $cs->fetchAll();

// Arricchisce ogni cert con metadati di origine (Credly / LinkedIn) dalle note
foreach ($certs as &$_c) {
    $_c['is_credly']        = false;
    $_c['is_linkedin']      = false;
    $_c['source_label']     = 'manual';
    $_c['credly_badge_id']  = null;
    $_c['credly_badge_url'] = null;
    $_c['linkedin_url']     = null;

    $notes = $_c['notes'] ?? '';

    // Credly
    if ($notes && strpos($notes, 'credly_badge_id:') !== false) {
        $_c['is_credly']    = true;
        $_c['source_label'] = 'credly';
        if (preg_match('~credly_badge_id:([a-f0-9\-]+)~i', $notes, $m)) {
            $_c['credly_badge_id']  = $m[1];
            $_c['credly_badge_url'] = 'https://www.credly.com/badges/' . $m[1];
        }
        if (preg_match('~badge_url:(https?://\S+)~i', $notes, $m)) {
            $_c['credly_badge_url'] = trim($m[1]);
        }
    }
    // LinkedIn
    elseif ($notes && (strpos($notes, 'linkedin_cert:') !== false
                       || strpos($notes, 'Importato da LinkedIn') !== false)) {
        $_c['is_linkedin']  = true;
        $_c['source_label'] = 'linkedin';
        if (preg_match('~linkedin_cred_url:(https?://\S+)~i', $notes, $m)) {
            $_c['linkedin_url'] = trim($m[1]);
        }
    }
}
unset($_c);

// Vanity LinkedIn del dipendente (per link al profilo)
$linkedin_vanity = null;
try {
    $lv = $pdo->prepare("SELECT linkedin_vanity FROM employee_linkedin_link WHERE employee_id = ?");
    $lv->execute([$emp_id]);
    $linkedin_vanity = $lv->fetchColumn() ?: null;
} catch (Throwable $e) { /* tabella assente su versioni vecchie */ }



// Piani formativi attivi
$ps = $pdo->prepare(
    "SELECT tp.*, cert.name cert_name, b.name brand_name
     FROM training_plans tp
     JOIN certifications cert ON tp.certification_id = cert.id
     JOIN brands b            ON cert.brand_id = b.id
     WHERE tp.employee_id = ? AND tp.status NOT IN('completed','cancelled')
     ORDER BY tp.target_date"
);
$ps->execute([$emp_id]);
$plans = $ps->fetchAll();

// Esami pianificati
$exs = $pdo->prepare(
    "SELECT pe.*, cert.name cert_name
     FROM planned_exams pe
     JOIN certifications cert ON pe.certification_id = cert.id
     WHERE pe.employee_id = ? AND pe.status='planned'
     ORDER BY pe.planned_date"
);
$exs->execute([$emp_id]);
$exams = $exs->fetchAll();

$tot_cert    = count($certs);
$act_cert    = count(array_filter($certs, fn($c)=>$c['status']==='active'));
$exp_cert    = count(array_filter($certs, fn($c)=>in_array($c['status'],['expiring','expired'])));
$tot_credly   = count(array_filter($certs, fn($c)=>!empty($c['is_credly'])));
$tot_linkedin = count(array_filter($certs, fn($c)=>!empty($c['is_linkedin'])));
$tot_manual   = $tot_cert - $tot_credly - $tot_linkedin;
$act_plans    = count($plans);

$skills_tech = array_filter(array_map('trim', explode(',', $user['technical_skills']??'')));
$skills_soft = array_filter(array_map('trim', explode(',', $user['soft_skills']??'')));

// Ruoli abilitati a eliminare certificazioni di un dipendente
$can_delete_cert = in_array((int)($_SESSION['role_id'] ?? 99), [1, 2, 4], true);

$display_name = h($user['first_name'].' '.$user['last_name']);
$primary_hex  = ltrim($settings['primary_color']??'0ea5e9','#');
?>

<?= $self_edit_msg ?>

<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:16px;flex-wrap:wrap" class="no-print">
  <?php if($can_self_edit): ?>
  <button type="button" onclick="openSelfEdit()" class="btn btn-sm btn-primary">
    <i class="fa-solid fa-user-pen"></i> Modifica i miei dati
  </button>
  <?php endif; ?>
  <?php if($viewer_role <= 2 && $user['user_id']): ?>
  <a href="manager_users.php" class="btn btn-sm"><i class="fa-solid fa-key"></i> Gestione accessi</a>
  <?php endif; ?>
  <?php if($viewer_role <= 2): ?>
  <a href="manage_employees.php" class="btn btn-sm"><i class="fa-solid fa-id-card"></i> Anagrafica HR</a>
  <?php endif; ?>
  <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i></button>
</div>

<?php if($can_self_edit): ?>
<!-- ─── Modal self-edit dei miei dati ─── -->
<div id="selfEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;overflow-y:auto;padding:30px 14px">
  <div style="background:#fff;border-radius:12px;max-width:680px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="margin:0;font-size:17px;font-weight:800">
        <i class="fa-solid fa-user-pen" style="color:var(--p)"></i> Modifica i miei dati
      </h3>
      <button type="button" onclick="closeSelfEdit()" style="border:0;background:none;font-size:22px;cursor:pointer;color:#94a3b8">&times;</button>
    </div>
    <div style="font-size:12px;color:#64748b;margin-bottom:14px">
      Puoi aggiornare i tuoi contatti, profili pubblici e descrizione. I campi HR (azienda, contratto, qualifica)
      sono modificabili solo dall'amministrazione.
    </div>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="self_edit">

      <!-- Contatti -->
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Contatti</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <div class="form-group" style="margin:0">
          <label><i class="fa-solid fa-phone-flip" style="color:#0ea5e9"></i> Telefono aziendale</label>
          <input type="tel" name="phone" value="<?= h($user['phone'] ?? '') ?>" placeholder="+39 055 ...">
        </div>
        <div class="form-group" style="margin:0">
          <label><i class="fa-solid fa-mobile-screen" style="color:#10b981"></i> Telefono personale</label>
          <input type="tel" name="phone_personal" value="<?= h($user['phone_personal'] ?? '') ?>" placeholder="+39 333 ...">
        </div>
        <div class="form-group" style="margin:0;grid-column:span 2">
          <label><i class="fa-solid fa-envelope-open" style="color:#10b981"></i> Email personale</label>
          <input type="email" name="personal_email" value="<?= h($user['personal_email'] ?? '') ?>" placeholder="nome@gmail.com">
        </div>
      </div>

      <!-- Profili pubblici -->
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Profili pubblici</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <div class="form-group" style="margin:0">
          <label><i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> URL Credly</label>
          <input type="text" name="credly_url" value="<?= h($user['credly_url'] ?? '') ?>" placeholder="https://www.credly.com/users/...">
        </div>
        <div class="form-group" style="margin:0">
          <label><i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> URL LinkedIn</label>
          <input type="text" name="linkedin_url" value="<?= h($user['linkedin_url'] ?? '') ?>" placeholder="https://www.linkedin.com/in/...">
        </div>
      </div>

      <!-- Bio -->
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Bio professionale</div>
      <div class="form-group" style="margin:0 0 14px">
        <textarea name="bio" rows="3" placeholder="Breve descrizione professionale, esperienza, focus area..."
                  style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical"><?= h($user['bio'] ?? '') ?></textarea>
      </div>

      <!-- Skills -->
      <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Competenze</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        <div class="form-group" style="margin:0">
          <label>Skill tecniche (separate da virgola)</label>
          <textarea name="technical_skills" rows="2" placeholder="Python, AWS, Docker, ..."
                    style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;font-family:inherit"><?= h($user['technical_skills'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="margin:0">
          <label>Soft skill (separate da virgola)</label>
          <textarea name="soft_skills" rows="2" placeholder="Leadership, Comunicazione, ..."
                    style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;font-family:inherit"><?= h($user['soft_skills'] ?? '') ?></textarea>
        </div>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
        <button type="button" onclick="closeSelfEdit()" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">
          <i class="fa-solid fa-floppy-disk"></i> Salva modifiche
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openSelfEdit() {
  const m = document.getElementById('selfEditModal');
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeSelfEdit() {
  document.getElementById('selfEditModal').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('selfEditModal')?.addEventListener('click', e => {
  if (e.target.id === 'selfEditModal') closeSelfEdit();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && document.getElementById('selfEditModal').style.display === 'flex') {
    closeSelfEdit();
  }
});
</script>
<?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:24px">

<!-- ── COLONNA SX — identità ── -->
<div>
  <div class="card" style="text-align:center;padding:28px">
    <img src="https://ui-avatars.com/api/?name=<?=urlencode($user['first_name'].' '.$user['last_name'])?>&background=<?=$primary_hex?>&color=fff&size=96&bold=true"
         style="width:80px;height:80px;border-radius:50%;margin-bottom:14px;border:3px solid var(--border)">
    <h2 style="margin:0 0 6px;font-size:20px"><?=$display_name?></h2>
    <?php if($user['job_title']): ?>
    <div style="color:var(--muted);font-size:13px;margin-bottom:10px"><?=h($user['job_title'])?></div>
    <?php endif; ?>

    <!-- Badge ruolo (solo se ha account) -->
    <?php if($user['role_name']): ?>
    <span class="badge badge-info" style="margin-bottom:12px"><?=h($user['role_name'])?></span>
    <?php else: ?>
    <span class="badge badge-neutral" style="margin-bottom:12px;font-size:9px">Nessun account portale</span>
    <?php endif; ?>

    <!-- Info base -->
    <div style="text-align:left;margin-top:16px;border-top:1px solid var(--border);padding-top:16px">
      <?php $infos = [
        ['fa-building',       $user['company_name']  ?? null, '',       'Azienda'],
        ['fa-location-dot',   $user['location_name'] ?? null, '',       'Sede'],
        ['fa-briefcase',      $user['contract_type'] ?? null, '',       'Contratto'],
        ['fa-envelope',       $user['business_email']?? null, 'mailto:','Email aziendale'],
        ['fa-envelope-open',  $user['personal_email']?? null, 'mailto:','Email personale'],
        ['fa-phone-flip',     $user['phone']         ?? null, 'tel:',   'Telefono aziendale'],
        ['fa-mobile-screen',  $user['phone_personal']?? null, 'tel:',   'Telefono personale'],
      ];
      foreach($infos as $row): list($icon, $val, $prefix, $label) = array_pad($row, 4, '');
        if(!$val) continue; ?>
      <div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12px;color:#475569" title="<?=h($label)?>">
        <i class="fa-solid <?=$icon?>" style="width:14px;color:var(--muted)"></i>
        <?php if($prefix): ?>
        <a href="<?=$prefix.h($val)?>" style="color:var(--p);text-decoration:none"><?=h($val)?></a>
        <?php else: ?>
        <span><?=h($val)?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <!-- Profili pubblici social -->
      <?php if (!empty($user['credly_url']) || !empty($user['linkedin_url'])): ?>
      <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap">
        <?php if (!empty($user['credly_url'])): ?>
          <a href="<?=h($user['credly_url'])?>" target="_blank" rel="noopener"
             title="Profilo Credly pubblico"
             style="display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;padding:5px 10px;border-radius:6px;text-decoration:none;font-size:11px;font-weight:700">
            <i class="fa-solid fa-shield-halved"></i> Credly
          </a>
        <?php endif; ?>
        <?php if (!empty($user['linkedin_url'])): ?>
          <a href="<?=h($user['linkedin_url'])?>" target="_blank" rel="noopener"
             title="Profilo LinkedIn pubblico"
             style="display:inline-flex;align-items:center;gap:5px;background:#0a66c2;color:#fff;padding:5px 10px;border-radius:6px;text-decoration:none;font-size:11px;font-weight:700">
            <i class="fa-brands fa-linkedin"></i> LinkedIn
          </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if($user['mode_name']): ?>
      <div style="margin-top:8px">
        <span style="background:<?=h($user['mode_color']??'#eee')?>;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700"><?=h($user['mode_name'])?></span>
      </div>
      <?php endif; ?>
      <?php if($user['hire_date']): ?>
      <div style="font-size:11px;color:var(--muted);margin-top:8px">
        <i class="fa-solid fa-calendar-check" style="width:14px;color:var(--muted)"></i>
        Assunto: <?=format_date($user['hire_date'])?>
      </div>
      <?php endif; ?>
    </div>

    <!-- KPI rapidi -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px">
      <div style="background:#f8fafc;padding:10px;border-radius:8px;text-align:center;position:relative">
        <div style="font-size:20px;font-weight:800;color:var(--success)"><?=$act_cert?></div>
        <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase">Cert. attive</div>
        <?php if ($tot_credly > 0 || $tot_linkedin > 0): ?>
          <div style="position:absolute;top:4px;right:4px;display:flex;gap:3px">
            <?php if ($tot_credly > 0): ?>
            <span title="<?=$tot_credly?> da Credly" style="background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;font-size:9px;font-weight:700;padding:1px 6px;border-radius:10px;display:inline-flex;align-items:center;gap:3px">
              <i class="fa-solid fa-shield-halved" style="font-size:8px"></i><?=$tot_credly?>
            </span>
            <?php endif; ?>
            <?php if ($tot_linkedin > 0): ?>
            <span title="<?=$tot_linkedin?> da LinkedIn" style="background:#0a66c2;color:#fff;font-size:9px;font-weight:700;padding:1px 6px;border-radius:10px;display:inline-flex;align-items:center;gap:3px">
              <i class="fa-brands fa-linkedin" style="font-size:8px"></i><?=$tot_linkedin?>
            </span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div style="background:#f8fafc;padding:10px;border-radius:8px;text-align:center">
        <div style="font-size:20px;font-weight:800;color:var(--p)"><?=$act_plans?></div>
        <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase">Piani</div>
      </div>
      <?php if($exp_cert > 0): ?>
      <div style="background:#fff7ed;padding:10px;border-radius:8px;text-align:center;grid-column:span 2">
        <div style="font-size:20px;font-weight:800;color:var(--warning)"><?=$exp_cert?></div>
        <div style="font-size:9px;color:var(--warning);font-weight:700;text-transform:uppercase">In scad. / scadute</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bio -->
  <?php if($user['bio']): ?>
  <div class="card" style="margin-top:18px">
    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Bio</div>
    <p style="font-size:13px;color:#475569;line-height:1.6"><?=h($user['bio'])?></p>
  </div>
  <?php endif; ?>

  <!-- Skills -->
  <?php if(!empty($skills_tech) || !empty($skills_soft)): ?>
  <div class="card" style="margin-top:18px">
    <?php if(!empty($skills_tech)): ?>
    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Skill tecniche</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
      <?php foreach($skills_tech as $sk): ?>
      <span style="background:#e0f2fe;color:#0369a1;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700"><?=h($sk)?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if(!empty($skills_soft)): ?>
    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:8px">Soft skills</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
      <?php foreach($skills_soft as $sk): ?>
      <span style="background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700"><?=h($sk)?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── COLONNA DX — competenze e piani ── -->
<div>

  <!-- Piano formativo attivo -->
  <?php if(!empty($plans) || !empty($exams)): ?>
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-road" style="color:var(--p)"></i> Piano formativo attivo</span>
      <?php if(check_ui_permission('training_plans.php')): ?>
      <a href="training_plans.php" class="btn btn-sm btn-blue">Calendario →</a>
      <?php endif; ?>
    </div>
    <?php foreach($plans as $p): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px;background:#f8fafc;border-radius:8px;margin-bottom:8px;border-left:4px solid var(--p)">
      <div style="flex:1">
        <div style="font-weight:600;font-size:13px"><?=h($p['cert_name'])?></div>
        <div style="font-size:11px;color:var(--muted)"><?=h($p['brand_name'])?></div>
      </div>
      <div style="text-align:right">
        <?=priority_badge($p['priority'])?>
        <?php if($p['target_date']): ?>
        <div style="font-size:10px;color:var(--muted);margin-top:3px">Entro <?=format_date($p['target_date'])?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php foreach($exams as $ex):
      $dl = days_diff($ex['planned_date']);
      $col = $dl < 0 ? 'var(--danger)' : ($dl <= 7 ? 'var(--warning)' : 'var(--p)');
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px;background:#f8fafc;border-radius:8px;margin-bottom:8px;border-left:4px solid <?=$col?>">
      <div style="flex:1">
        <div style="font-weight:600;font-size:13px"><?=h($ex['cert_name'])?></div>
        <div style="font-size:11px;color:var(--muted)">Esame pianificato</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:16px;font-weight:800;color:<?=$col?>"><?=abs($dl)?></div>
        <div style="font-size:9px;color:var(--muted)"><?=$dl<0?'gg di ritardo':'gg al test'?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Certificazioni -->
  <div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:8px">
      <span class="card-title">
        <i class="fa-solid fa-graduation-cap" style="color:var(--p)"></i>
        Certificazioni (<?=$tot_cert?>)
        <?php if ($tot_credly > 0): ?>
          <span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;margin-left:6px;vertical-align:middle">
            <i class="fa-solid fa-shield-halved" style="font-size:9px"></i><?= $tot_credly ?> Credly
          </span>
        <?php endif; ?>
        <?php if ($tot_linkedin > 0): ?>
          <span style="display:inline-flex;align-items:center;gap:4px;background:#0a66c2;color:#fff;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;margin-left:6px;vertical-align:middle">
            <i class="fa-brands fa-linkedin" style="font-size:9px"></i><?= $tot_linkedin ?> LinkedIn
          </span>
        <?php endif; ?>
      </span>
      <div style="display:flex;gap:6px;align-items:center">
        <?php if ($tot_credly > 0 || $tot_linkedin > 0): ?>
          <!-- Filtro fonte -->
          <div id="certFilter" style="display:inline-flex;background:#f1f5f9;border-radius:8px;padding:2px;font-size:11px">
            <button type="button" data-f="all"    class="cf-btn cf-active" onclick="filterCerts(this,'all')">Tutte <span class="cf-count"><?= $tot_cert ?></span></button>
            <button type="button" data-f="manual" class="cf-btn" onclick="filterCerts(this,'manual')">Manuali <span class="cf-count"><?= $tot_manual ?></span></button>
            <?php if ($tot_credly > 0): ?>
            <button type="button" data-f="credly" class="cf-btn" onclick="filterCerts(this,'credly')">Credly <span class="cf-count"><?= $tot_credly ?></span></button>
            <?php endif; ?>
            <?php if ($tot_linkedin > 0): ?>
            <button type="button" data-f="linkedin" class="cf-btn" onclick="filterCerts(this,'linkedin')">LinkedIn <span class="cf-count"><?= $tot_linkedin ?></span></button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if(check_ui_permission('upload_certificato.php')): ?>
        <a href="upload_certificato.php" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus"></i> Aggiungi</a>
        <?php endif; ?>
        <?php if(check_ui_permission('credly_sync.php') && $tot_credly > 0): ?>
        <a href="<?= function_exists('url_safe') ? url_safe('credly_sync') : 'credly_sync.php' ?>" class="btn btn-sm" style="background:#ede9fe;color:#6d28d9" title="Sincronizza con Credly">
          <i class="fa-solid fa-rotate"></i> Credly
        </a>
        <?php endif; ?>
        <?php if(check_ui_permission('linkedin_sync.php') && ($tot_linkedin > 0 || $linkedin_vanity)): ?>
        <a href="<?= function_exists('url_safe') ? url_safe('linkedin_sync') : 'linkedin_sync.php' ?>" class="btn btn-sm" style="background:#dbeafe;color:#0a66c2" title="Importa da LinkedIn">
          <i class="fa-brands fa-linkedin"></i> LinkedIn
        </a>
        <?php endif; ?>
      </div>
    </div>

    <style>
      .cf-btn { background:transparent; border:0; padding:5px 12px; border-radius:6px; cursor:pointer; font-weight:600; color:#64748b; font-size:11px; transition:all .15s }
      .cf-btn:hover { color:#0f172a }
      .cf-btn.cf-active { background:#fff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,.08) }
      .cf-count { display:inline-block; margin-left:4px; background:#e2e8f0; color:#475569; padding:0 6px; border-radius:10px; font-size:10px }
      .cf-btn.cf-active .cf-count { background:#0ea5e9; color:#fff }
      .cert-row.cert-credly { background:linear-gradient(90deg,rgba(124,58,237,.04),transparent 30%) }
      .cert-row.cert-credly:hover { background:linear-gradient(90deg,rgba(124,58,237,.08),rgba(124,58,237,.02)) }
      .cert-row.cert-credly td:first-child { border-left:3px solid #a855f7 }
      .cert-row.cert-linkedin { background:linear-gradient(90deg,rgba(10,102,194,.05),transparent 30%) }
      .cert-row.cert-linkedin:hover { background:linear-gradient(90deg,rgba(10,102,194,.1),rgba(10,102,194,.02)) }
      .cert-row.cert-linkedin td:first-child { border-left:3px solid #0a66c2 }
      .credly-pill { display:inline-flex; align-items:center; gap:4px; background:linear-gradient(135deg,#7c3aed,#a855f7); color:#fff; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:700; margin-left:5px; vertical-align:middle; text-decoration:none }
      .credly-pill:hover { transform:translateY(-1px); box-shadow:0 2px 6px rgba(124,58,237,.4); color:#fff }
      .linkedin-pill { display:inline-flex; align-items:center; gap:4px; background:#0a66c2; color:#fff; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:700; margin-left:5px; vertical-align:middle; text-decoration:none }
      .linkedin-pill:hover { transform:translateY(-1px); box-shadow:0 2px 6px rgba(10,102,194,.4); color:#fff }
      .cert-source-icon { display:inline-flex; width:22px; height:22px; align-items:center; justify-content:center; border-radius:6px; font-size:11px }
      .cert-source-credly { background:#ede9fe; color:#6d28d9 }
      .cert-source-linkedin { background:#dbeafe; color:#0a66c2 }
      .cert-source-manual { background:#e0f2fe; color:#0369a1 }
      .cert-del-btn { background:transparent; border:1px solid #fecaca; color:#dc2626; width:28px; height:28px; border-radius:6px; cursor:pointer; font-size:11px; transition:all .15s; padding:0 }
      .cert-del-btn:hover { background:#dc2626; color:#fff; border-color:#dc2626; transform:scale(1.1) }
      .cert-del-btn:disabled { opacity:.4; cursor:wait }
      .cert-del-flash { padding:10px 14px; border-radius:8px; margin-bottom:12px; font-size:13px; display:none }
      .cert-del-flash.show { display:block; animation: cdfFade .3s }
      .cert-del-flash.ok { background:#dcfce7; color:#166534; border-left:4px solid #16a34a }
      .cert-del-flash.err { background:#fee2e2; color:#991b1b; border-left:4px solid #dc2626 }
      @keyframes cdfFade { from { opacity:0; transform:translateY(-4px) } to { opacity:1; transform:translateY(0) } }
    </style>

    <?php if(empty($certs)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted)">
      <i class="fa-solid fa-graduation-cap" style="font-size:32px;opacity:.3;display:block;margin-bottom:10px"></i>
      Nessuna certificazione registrata.
      <div style="margin-top:14px;display:flex;gap:8px;justify-content:center">
        <?php if(check_ui_permission('credly_sync.php')): ?>
        <a href="<?= function_exists('url_safe') ? url_safe('credly_sync') : 'credly_sync.php' ?>" class="btn btn-sm" style="background:#7c3aed;color:#fff">
          <i class="fa-solid fa-shield-halved"></i> Importa da Credly
        </a>
        <?php endif; ?>
        <?php if(check_ui_permission('linkedin_sync.php')): ?>
        <a href="<?= function_exists('url_safe') ? url_safe('linkedin_sync') : 'linkedin_sync.php' ?>" class="btn btn-sm" style="background:#0a66c2;color:#fff">
          <i class="fa-brands fa-linkedin"></i> Importa da LinkedIn
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="data-table" id="certTable">
      <thead>
        <tr>
          <th style="width:30px"></th>
          <th>Certificazione</th>
          <th>Brand</th>
          <th>Conseguita</th>
          <th>Scadenza</th>
          <th style="text-align:center">Verifica</th>
          <th style="text-align:center">Stato</th>
          <?php if ($can_delete_cert): ?>
          <th style="text-align:center;width:36px"></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach($certs as $c):
        $src = $c['source_label'];
      ?>
      <tr class="cert-row cert-<?= $src ?>" data-source="<?= $src ?>">
        <td style="text-align:center">
          <?php if (!empty($c['is_credly'])): ?>
            <span class="cert-source-icon cert-source-credly" title="Importato da Credly">
              <i class="fa-solid fa-shield-halved"></i>
            </span>
          <?php elseif (!empty($c['is_linkedin'])): ?>
            <span class="cert-source-icon cert-source-linkedin" title="Importato da LinkedIn">
              <i class="fa-brands fa-linkedin"></i>
            </span>
          <?php else: ?>
            <span class="cert-source-icon cert-source-manual" title="Inserito manualmente">
              <i class="fa-solid fa-user-pen"></i>
            </span>
          <?php endif; ?>
        </td>
        <td>
          <strong style="font-size:13px"><?=h($c['cert_name'])?></strong>
          <?php if (!empty($c['is_credly']) && !empty($c['credly_badge_url'])): ?>
            <a href="<?= h($c['credly_badge_url']) ?>" target="_blank" rel="noopener" class="credly-pill" title="Verifica su Credly">
              <i class="fa-solid fa-shield-halved" style="font-size:8px"></i> Credly
              <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:7px"></i>
            </a>
          <?php elseif (!empty($c['is_linkedin'])): ?>
            <a href="<?= !empty($c['linkedin_url']) ? h($c['linkedin_url']) : ($linkedin_vanity ? 'https://www.linkedin.com/in/' . h($linkedin_vanity) . '/details/certifications/' : 'https://www.linkedin.com/in/' . h($linkedin_vanity ?? '')) ?>" target="_blank" rel="noopener" class="linkedin-pill" title="Vedi su LinkedIn">
              <i class="fa-brands fa-linkedin" style="font-size:8px"></i> LinkedIn
              <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:7px"></i>
            </a>
          <?php endif; ?>
          <?php if($c['cert_code']): ?>
            <br><code style="font-size:10px;color:var(--muted)"><?=h($c['cert_code'])?></code>
          <?php endif; ?>
        </td>
        <td><span class="badge badge-neutral" style="font-size:9px"><?=h($c['brand_name'])?></span></td>
        <td style="font-size:12px"><?=format_date($c['issue_date'])?></td>
        <td style="font-size:12px"><?=$c['expiry_date']?format_date($c['expiry_date']):'<span style="color:var(--muted)">Perpetua</span>'?></td>
        <td style="text-align:center">
          <?php if (!empty($c['is_credly']) && !empty($c['credly_badge_url'])): ?>
            <a href="<?= h($c['credly_badge_url']) ?>" target="_blank" rel="noopener"
               style="color:#7c3aed;font-size:16px" title="Apri badge su Credly">
              <i class="fa-solid fa-shield-halved"></i>
            </a>
          <?php elseif (!empty($c['is_linkedin'])): ?>
            <?php if (!empty($c['linkedin_url'])): ?>
              <a href="<?= h($c['linkedin_url']) ?>" target="_blank" rel="noopener"
                 style="color:#0a66c2;font-size:16px" title="Verifica credenziale">
                <i class="fa-brands fa-linkedin"></i>
              </a>
            <?php else: ?>
              <i class="fa-brands fa-linkedin" style="color:#0a66c2;font-size:16px" title="Da LinkedIn"></i>
            <?php endif; ?>
          <?php elseif ($c['document_path']): ?>
            <a href="download.php?file=<?=urlencode($c['document_path'])?>" target="_blank"
               style="color:#e11d48;font-size:17px" title="Scarica PDF">
              <i class="fa-solid fa-file-pdf"></i>
            </a>
          <?php else: ?>
            <i class="fa-solid fa-file-circle-xmark" style="color:#cbd5e1" title="Nessun documento"></i>
          <?php endif; ?>
        </td>
        <td style="text-align:center"><?=status_badge($c['status'])?></td>
        <?php if ($can_delete_cert): ?>
        <td style="text-align:center">
          <button type="button"
                  class="cert-del-btn"
                  data-uc-id="<?= (int)$c['id'] ?>"
                  data-cert-name="<?= h(addslashes($c['cert_name'])) ?>"
                  data-source="<?= h($c['source_label']) ?>"
                  title="Elimina questa certificazione (errore caricamento)">
            <i class="fa-solid fa-trash"></i>
          </button>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($tot_credly > 0 || $tot_linkedin > 0): ?>
      <div style="margin-top:12px;padding:10px 14px;background:linear-gradient(90deg,#f8fafc,transparent);border-left:3px solid #94a3b8;font-size:11px;color:#6b7280;border-radius:0 8px 8px 0">
        <i class="fa-solid fa-circle-info" style="color:#64748b"></i>
        <?php if ($tot_credly > 0): ?>
          Scudo <i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> = importato da Credly.
        <?php endif; ?>
        <?php if ($tot_linkedin > 0): ?>
          Icona <i class="fa-brands fa-linkedin" style="color:#0a66c2"></i> = importato da LinkedIn.
        <?php endif; ?>
        Entrambe verificabili tramite link pubblico.
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Flash di feedback per eliminazioni -->
  <div id="certDelFlash" class="cert-del-flash"></div>

  <!-- Modal conferma eliminazione cert -->
  <?php if ($can_delete_cert): ?>
  <div id="certDelModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;max-width:480px;width:90%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
      <h3 style="font-size:16px;font-weight:800;margin-bottom:8px;color:#991b1b">
        <i class="fa-solid fa-triangle-exclamation"></i> Elimina certificazione
      </h3>
      <div style="font-size:13px;color:#475569;line-height:1.6;margin-bottom:14px">
        Stai per eliminare <strong id="cdmCertName">...</strong> da questo dipendente.
        <br>L'operazione è <strong>irreversibile</strong> ma viene tracciata nello storico modifiche.
      </div>
      <div id="cdmSourceNote" style="display:none;background:#fef3c7;border-left:3px solid #f59e0b;padding:8px 12px;font-size:11px;color:#92400e;border-radius:0 6px 6px 0;margin-bottom:12px"></div>
      <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:4px">Motivazione (obbligatoria)</label>
      <textarea id="cdmReason" rows="2" minlength="5" maxlength="500"
                placeholder="es. Caricato per errore, dipendente sbagliato, doppione..."
                style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit;resize:vertical"></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn" onclick="cdmClose()">Annulla</button>
        <button type="button" class="btn" id="cdmConfirmBtn" style="background:#dc2626;color:#fff" onclick="cdmConfirm()">
          <i class="fa-solid fa-trash"></i> Elimina definitivamente
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
    function filterCerts(btn, mode) {
      const rows = document.querySelectorAll('#certTable tbody tr.cert-row');
      rows.forEach(r => {
        const s = r.dataset.source;
        r.style.display = (mode === 'all' || mode === s) ? '' : 'none';
      });
      document.querySelectorAll('#certFilter .cf-btn').forEach(b => b.classList.remove('cf-active'));
      btn.classList.add('cf-active');
    }

    <?php if ($can_delete_cert): ?>
    /* ─── Eliminazione cert dipendente ──────────────────────────── */
    let _cdmCurrent = null;

    document.querySelectorAll('.cert-del-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        _cdmCurrent = {
          uc_id: btn.dataset.ucId,
          name: btn.dataset.certName,
          source: btn.dataset.source,
          row: btn.closest('tr')
        };
        document.getElementById('cdmCertName').textContent = _cdmCurrent.name;
        const note = document.getElementById('cdmSourceNote');
        if (_cdmCurrent.source === 'credly') {
          note.style.display = 'block';
          note.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Questa cert è stata importata da <strong>Credly</strong>. Verrà rimossa solo dal portale; al prossimo sync ricomparirà se ancora presente sul profilo Credly del dipendente.';
        } else if (_cdmCurrent.source === 'linkedin') {
          note.style.display = 'block';
          note.innerHTML = '<i class="fa-brands fa-linkedin"></i> Questa cert è stata importata da <strong>LinkedIn</strong>. Verrà rimossa solo dal portale; al prossimo import ricomparirà se ancora presente nel profilo LinkedIn del dipendente.';
        } else {
          note.style.display = 'none';
        }
        document.getElementById('cdmReason').value = '';
        document.getElementById('certDelModal').style.display = 'flex';
        setTimeout(() => document.getElementById('cdmReason').focus(), 100);
      });
    });

    function cdmClose() {
      document.getElementById('certDelModal').style.display = 'none';
      _cdmCurrent = null;
    }

    async function cdmConfirm() {
      if (!_cdmCurrent) return;
      const reason = document.getElementById('cdmReason').value.trim();
      if (reason.length < 5) {
        document.getElementById('cdmReason').focus();
        document.getElementById('cdmReason').style.borderColor = '#dc2626';
        return;
      }
      const confirmBtn = document.getElementById('cdmConfirmBtn');
      confirmBtn.disabled = true;
      confirmBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Eliminazione...';

      try {
        const fd = new FormData();
        fd.append('uc_id', _cdmCurrent.uc_id);
        fd.append('reason', reason);
        fd.append('csrf', '<?= function_exists('csrf_token') ? csrf_token() : (Csrf::token() ?? '') ?>');

        const r = await fetch('api_user_cert_delete.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        const txt = await r.text();
        let data; try { data = JSON.parse(txt); } catch { data = { ok: false, error: 'parse_error', raw: txt }; }

        if (data.ok) {
          // Rimuovi riga con animazione
          const row = _cdmCurrent.row;
          row.style.transition = 'opacity .25s, transform .25s';
          row.style.opacity = '0';
          row.style.transform = 'translateX(20px)';
          setTimeout(() => row.remove(), 250);

          flash('ok', '<i class="fa-solid fa-circle-check"></i> ' + (data.message || 'Certificazione eliminata.'));
          cdmClose();
          // Aggiorna contatori (semplice: ricarica dopo 1.5s)
          setTimeout(() => window.location.reload(), 1500);
        } else {
          flash('err', '<i class="fa-solid fa-triangle-exclamation"></i> ' +
                (data.hint || data.error || 'Errore eliminazione.'));
          confirmBtn.disabled = false;
          confirmBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Elimina definitivamente';
        }
      } catch (e) {
        flash('err', '<i class="fa-solid fa-triangle-exclamation"></i> Errore di rete: ' + e.message);
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Elimina definitivamente';
      }
    }

    function flash(type, html) {
      const el = document.getElementById('certDelFlash');
      el.className = 'cert-del-flash show ' + type;
      el.innerHTML = html;
      setTimeout(() => el.classList.remove('show'), 4500);
    }

    // Close modal on backdrop click
    document.getElementById('certDelModal')?.addEventListener('click', e => {
      if (e.target.id === 'certDelModal') cdmClose();
    });
    // ESC chiude
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') cdmClose();
    });
    <?php endif; ?>
  </script>
</div>
</div>

<?php require_once('footer.php'); ?>
