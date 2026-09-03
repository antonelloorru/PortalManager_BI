<?php
/**
 * PortalManager — candidate_hire.php
 *
 * Trasformazione candidato → dipendente (employee).
 *
 * Flow:
 *   1. GET ?candidate_id=N&position_id=M  → mostra form pre-compilato
 *   2. POST → transazione:
 *        a. INSERT employees con dati candidato + dati aggiuntivi HR
 *        b. UPDATE candidates: status='hired', converted_to_employee_id=N, hired_at=NOW()
 *        c. UPDATE candidate_applications: stage='hired', hired_at=NOW(), hired_by=user
 *        d. UPDATE job_positions: hires_count++
 *        e. Se hires_count >= positions_expected → status='closed', closed_at=CURDATE()
 *           + log in position_status_history
 *        f. INSERT entity_change_log
 *   3. Redirect a employee_profile.php?id=N con flash success
 */
require_once('access_control.php');

$u_role = (int)($_SESSION['role_id'] ?? 99);
$u_id   = (int)$_SESSION['user_id'];

if (!can('create')) { redirect('recruiting_candidati'); }

$candidate_id = (int)($_GET['candidate_id'] ?? 0);
$position_id  = (int)($_GET['position_id']  ?? 0);
$msg = '';

if ($candidate_id <= 0) { redirect('recruiting_candidati'); }

// ── Carica candidato ──
$stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = ? AND (deleted_at IS NULL)");
$stmt->execute([$candidate_id]);
$candidate = $stmt->fetch();
if (!$candidate) { redirect('recruiting_candidati'); }

if ($candidate['status'] === 'hired' && !empty($candidate['converted_to_employee_id'])) {
    $_SESSION['flash_msg'] = "<div class='alert alert-info'>Candidato già convertito in dipendente #" . (int)$candidate['converted_to_employee_id'] . "</div>";
    redirect('employee_profile', ['id' => (int)$candidate['converted_to_employee_id']]);
}

// ── Carica posizioni candidate dell'utente ──
$apps_stmt = $pdo->prepare("
    SELECT ca.id AS app_id, ca.stage, ca.match_score,
           jp.id AS position_id, jp.title, jp.department, jp.status AS pos_status,
           jp.positions_expected, jp.hires_count, jp.contract_type, jp.location,
           jp.remote_policy
      FROM candidate_applications ca
      JOIN job_positions jp ON ca.position_id = jp.id
     WHERE ca.candidate_id = ?
     ORDER BY ca.stage_updated_at DESC
");
$apps_stmt->execute([$candidate_id]);
$applications = $apps_stmt->fetchAll();

// Se position_id non specificato, prova ultima candidatura attiva
if ($position_id <= 0 && !empty($applications)) {
    foreach ($applications as $a) {
        if (in_array($a['stage'], ['offer_sent','tech_interview','hr_interview'], true)) {
            $position_id = (int)$a['position_id'];
            break;
        }
    }
    if ($position_id <= 0) $position_id = (int)$applications[0]['position_id'];
}

// Carica dettagli posizione selezionata
$position = null;
if ($position_id > 0) {
    $pstmt = $pdo->prepare("SELECT * FROM job_positions WHERE id = ?");
    $pstmt->execute([$position_id]);
    $position = $pstmt->fetch() ?: null;
}

// ── POST: esegui conversione ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_hire'])) {
    Csrf::verify();

    $emp = [
        'first_name'         => trim((string)($_POST['first_name'] ?? $candidate['first_name'])),
        'last_name'          => trim((string)($_POST['last_name']  ?? $candidate['last_name'])),
        'fiscal_code'        => trim((string)($_POST['fiscal_code'] ?? '')) ?: null,
        'date_of_birth'      => $_POST['date_of_birth'] ?: null,
        'phone'              => trim((string)($_POST['phone'] ?? '')) ?: null,
        'phone_personal'     => trim((string)($_POST['phone_personal'] ?? $candidate['phone'] ?? '')) ?: null,
        'personal_email'     => trim((string)($_POST['personal_email'] ?? $candidate['email'] ?? '')) ?: null,
        'business_email'     => trim((string)($_POST['business_email'] ?? '')) ?: null,
        'employee_code'      => trim((string)($_POST['employee_code'] ?? '')) ?: null,
        'job_title'          => trim((string)($_POST['job_title'] ?? ($position['title'] ?? ''))) ?: null,
        'department'         => trim((string)($_POST['department'] ?? ($position['department'] ?? ''))) ?: null,
        'contract_type'      => $_POST['contract_type'] ?? ($position['contract_type'] ?? 'Indeterminato'),
        'hire_date'          => $_POST['hire_date'] ?: date('Y-m-d'),
        'company_id'         => (int)($_POST['company_id'] ?? 1) ?: 1,
        'location_id'        => ($_POST['location_id'] ?? '') !== '' ? (int)$_POST['location_id'] : null,
        'work_mode_id'       => ($_POST['work_mode_id'] ?? '') !== '' ? (int)$_POST['work_mode_id'] : null,
        'education_level'    => $candidate['education_level'],
        'education_field'    => $candidate['education_field'],
        'education_institute'=> $candidate['education_institute'],
        'education_year'     => $candidate['education_year'],
        'technical_skills'   => $candidate['skills_tags'],
        'cv_path'            => $candidate['cv_path'],
        'linkedin_url'       => $candidate['linkedin_url'],
        'status'             => 'active',
    ];

    // Validazione
    $errors = [];
    if ($emp['first_name'] === '')   $errors[] = 'Nome obbligatorio';
    if ($emp['last_name']  === '')   $errors[] = 'Cognome obbligatorio';
    if ($emp['hire_date']  === null) $errors[] = 'Data assunzione obbligatoria';
    if ($emp['company_id'] <= 0)     $errors[] = 'Azienda obbligatoria';
    if ($position_id <= 0 || !$position) $errors[] = 'Posizione non valida';

    if (!empty($errors)) {
        $msg = "<div class='alert alert-danger'><strong>Errori:</strong><ul style='margin:5px 0 0 20px'><li>"
             . implode('</li><li>', array_map('h', $errors)) . "</li></ul></div>";
    } else {
        try {
            $pdo->beginTransaction();

            // ─── 1. INSERT employees ───
            $cols = array_keys($emp);
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $sql = "INSERT INTO employees (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)";
            $pdo->prepare($sql)->execute(array_values($emp));
            $new_emp_id = (int)$pdo->lastInsertId();

            // ─── 2. UPDATE candidates ───
            $pdo->prepare("
                UPDATE candidates
                   SET status = 'hired',
                       converted_to_employee_id = ?,
                       hired_at = NOW()
                 WHERE id = ?
            ")->execute([$new_emp_id, $candidate_id]);

            // ─── 3. UPDATE candidate_applications ───
            // - stage = hired per la posizione selezionata
            // - rejected (motivo: position_filled) per le altre candidature attive dello stesso candidato
            $pdo->prepare("
                UPDATE candidate_applications
                   SET stage = 'hired', hired_at = NOW(), hired_by = ?
                 WHERE candidate_id = ? AND position_id = ?
            ")->execute([$u_id, $candidate_id, $position_id]);

            $pdo->prepare("
                UPDATE candidate_applications
                   SET stage = 'rejected', rejection_reason = 'Assunto su altra posizione'
                 WHERE candidate_id = ?
                   AND position_id != ?
                   AND stage NOT IN ('rejected','hired')
            ")->execute([$candidate_id, $position_id]);

            // ─── 4. UPDATE job_positions: incremento contatore ───
            $pdo->prepare("UPDATE job_positions SET hires_count = hires_count + 1 WHERE id = ?")
                ->execute([$position_id]);

            // Ricarico posizione per check auto-close
            $check = $pdo->prepare("SELECT hires_count, positions_expected, status FROM job_positions WHERE id = ?");
            $check->execute([$position_id]);
            $pos_state = $check->fetch();
            $auto_closed = false;

            if ($pos_state && (int)$pos_state['hires_count'] >= (int)$pos_state['positions_expected']
                && $pos_state['status'] !== 'closed') {
                // ─── 5. Auto-chiusura posizione ───
                $pdo->prepare("
                    UPDATE job_positions
                       SET status = 'closed', closed_at = CURDATE()
                     WHERE id = ?
                ")->execute([$position_id]);

                // Log in position_status_history
                $pdo->prepare("
                    INSERT INTO position_status_history
                      (position_id, old_status, new_status, closed_at_snapshot, changed_by, notes)
                    VALUES (?, ?, 'closed', CURDATE(), ?, ?)
                ")->execute([
                    $position_id,
                    $pos_state['status'],
                    $u_id,
                    "Auto-chiusura: raggiunto target assunzioni ({$pos_state['hires_count']}/{$pos_state['positions_expected']})"
                ]);
                $auto_closed = true;
            }

            $pdo->commit();

            write_log('Recruiting', 'success',
                "Candidato #$candidate_id ({$candidate['first_name']} {$candidate['last_name']}) " .
                "assunto come dipendente #$new_emp_id (posizione #$position_id)" .
                ($auto_closed ? ' - posizione AUTO-CHIUSA' : ''),
                $u_id);

            $flash = "<div class='alert alert-success'>
                <i class='fa-solid fa-circle-check'></i> <strong>Assunzione completata!</strong><br>
                Dipendente <strong>#{$new_emp_id}</strong> creato con successo.
                <br>Candidato marcato come 'Assunto'.";
            if ($auto_closed) {
                $flash .= "<br><i class='fa-solid fa-lock'></i> <strong>Posizione AUTO-CHIUSA</strong> (raggiunto target: {$pos_state['hires_count']}/{$pos_state['positions_expected']} assunzioni).";
            }
            $flash .= "</div>";
            $_SESSION['flash_msg'] = $flash;

            redirect('employee_profile', ['id' => $new_emp_id]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "<div class='alert alert-danger'>Errore conversione: " . h($e->getMessage()) . "</div>";
        }
    }
}

// ── Carica liste per dropdown ──
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$locations = [];
try {
    $locations = $pdo->query("SELECT id, name, company_id FROM company_locations ORDER BY name")->fetchAll();
} catch (\Throwable $e) {}
$work_modes = [];
try {
    $work_modes = $pdo->query("SELECT id, name FROM work_modes ORDER BY name")->fetchAll();
} catch (\Throwable $e) {}

require_once('header.php');
?>

<div style="margin-bottom:18px">
  <h2 style="margin:0;color:#0f172a;font-size:22px">
    <i class="fa-solid fa-user-check" style="color:#16a34a"></i> Trasforma candidato in dipendente
  </h2>
  <div style="font-size:12px;color:#64748b;margin-top:3px">
    <a href="<?= url_safe('candidato_profilo', ['id' => $candidate_id]) ?>" style="color:#64748b">
      <i class="fa-solid fa-arrow-left"></i> Torna al profilo candidato
    </a>
  </div>
</div>

<?= $msg ?>

<div class="card" style="padding:14px;margin-bottom:14px;background:#f0fdf4;border:1px solid #86efac">
  <h3 style="margin:0 0 6px 0;font-size:14px;color:#166534">
    <i class="fa-solid fa-user-tag"></i> Candidato selezionato
  </h3>
  <div style="font-size:13px;color:#15803d">
    <strong><?= h($candidate['first_name'] . ' ' . $candidate['last_name']) ?></strong>
    <?php if ($candidate['email']): ?> · <?= h($candidate['email']) ?><?php endif; ?>
    <?php if ($candidate['phone']): ?> · <?= h($candidate['phone']) ?><?php endif; ?>
  </div>
</div>

<form method="POST" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="execute_hire" value="1">

  <!-- ═════ POSIZIONE LAVORATIVA ═════ -->
  <div class="card" style="padding:16px;margin-bottom:14px">
    <h3 style="margin:0 0 12px 0;font-size:14px;color:#7c3aed">
      <i class="fa-solid fa-briefcase"></i> 1. Posizione lavorativa
    </h3>
    <?php if (empty($applications)): ?>
    <div class="alert alert-warning">
      <i class="fa-solid fa-triangle-exclamation"></i> Il candidato non è associato a nessuna posizione. Aggiungi prima una candidatura dal profilo.
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Posizione *</label>
        <select id="positionSelect"
                onchange="window.location='<?= url_safe('candidate_hire', ['candidate_id' => $candidate_id]) ?>&position_id='+this.value"
                style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
          <?php foreach ($applications as $a):
            $disabled = $a['pos_status'] === 'closed' && (int)$a['hires_count'] >= (int)$a['positions_expected'];
          ?>
          <option value="<?= (int)$a['position_id'] ?>"
                  <?= $position_id === (int)$a['position_id'] ? 'selected' : '' ?>
                  <?= $disabled ? 'disabled' : '' ?>>
            <?= h($a['title']) ?>
            (<?= h($a['department'] ?? '?') ?>)
            — stage: <?= h($a['stage']) ?>
            <?= $disabled ? '[CHIUSA]' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($position): ?>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Stato posizione</label>
        <div style="padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:13px">
          <strong><?= h($position['status']) ?></strong>
        </div>
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Assunzioni</label>
        <div style="padding:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;text-align:center">
          <strong style="font-size:16px;color:<?= (int)$position['hires_count'] >= (int)$position['positions_expected'] ? '#16a34a' : '#7c3aed' ?>">
            <?= (int)$position['hires_count'] ?> / <?= (int)$position['positions_expected'] ?>
          </strong>
          <?php if ((int)$position['hires_count']+1 >= (int)$position['positions_expected']): ?>
          <div style="font-size:10px;color:#16a34a;font-weight:700;margin-top:2px">
            <i class="fa-solid fa-lock"></i> Auto-close prossimo
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($position && (empty($applications) === false)): ?>
  <!-- ═════ DATI DIPENDENTE ═════ -->
  <div class="card" style="padding:16px;margin-bottom:14px">
    <h3 style="margin:0 0 12px 0;font-size:14px;color:#7c3aed">
      <i class="fa-solid fa-id-card"></i> 2. Dati anagrafici e contratto
    </h3>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Nome *</label>
        <input type="text" name="first_name" value="<?= h($candidate['first_name']) ?>" required
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Cognome *</label>
        <input type="text" name="last_name" value="<?= h($candidate['last_name']) ?>" required
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Codice fiscale</label>
        <input type="text" name="fiscal_code" value="" maxlength="16"
               placeholder="es. RSSMRA85T10F205Z"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;text-transform:uppercase">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:12px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Data di nascita</label>
        <input type="date" name="date_of_birth" value=""
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Tel. personale</label>
        <input type="text" name="phone_personal" value="<?= h($candidate['phone'] ?? '') ?>"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Email personale</label>
        <input type="email" name="personal_email" value="<?= h($candidate['email'] ?? '') ?>"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Email aziendale</label>
        <input type="email" name="business_email" value=""
               placeholder="nome.cognome@azienda.it"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:10px;margin-bottom:12px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Matricola</label>
        <input type="text" name="employee_code" value=""
               placeholder="es. EMP-2025-001"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Ruolo (Job title)</label>
        <input type="text" name="job_title" value="<?= h($position['title'] ?? '') ?>"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Dipartimento</label>
        <input type="text" name="department" value="<?= h($position['department'] ?? '') ?>"
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:8px">
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Data assunzione *</label>
        <input type="date" name="hire_date" value="<?= date('Y-m-d') ?>" required
               style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Tipo contratto</label>
        <select name="contract_type"
                style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
          <?php foreach (['Indeterminato','Determinato','Somministrazione','Consulenza','Stage','Partita IVA'] as $ct): ?>
          <option value="<?= h($ct) ?>" <?= ($position['contract_type'] ?? '') === $ct ? 'selected' : '' ?>>
            <?= h($ct) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Azienda *</label>
        <select name="company_id" required
                style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
          <?php foreach ($companies as $co): ?>
          <option value="<?= (int)$co['id'] ?>" <?= (int)$co['id'] === 1 ? 'selected' : '' ?>>
            <?= h($co['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Sede / Mod. lavoro</label>
        <div style="display:flex;gap:4px">
          <?php if (!empty($locations)): ?>
          <select name="location_id" style="flex:1;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
            <option value="">— Sede —</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?= (int)$loc['id'] ?>"><?= h($loc['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <?php if (!empty($work_modes)): ?>
          <select name="work_mode_id" style="flex:1;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
            <option value="">— Mod. —</option>
            <?php foreach ($work_modes as $wm): ?>
            <option value="<?= (int)$wm['id'] ?>"><?= h($wm['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ═════ AZIONI ═════ -->
  <div class="card" style="padding:14px;background:#fefce8;border:1px solid #fde68a">
    <div style="font-size:12px;color:#a16207;margin-bottom:10px">
      <i class="fa-solid fa-circle-info"></i> <strong>L'operazione eseguirà in transazione</strong>:
      <ol style="margin:4px 0 0 18px;padding:0">
        <li>Creazione record dipendente nell'anagrafica HR</li>
        <li>Marcatura del candidato come <strong>Assunto</strong></li>
        <li>Stage di candidatura → <strong>hired</strong></li>
        <li>Altre candidature dello stesso candidato → rejected (motivo: assunto altrove)</li>
        <li>Incremento contatore assunzioni della posizione (<?= (int)$position['hires_count']+1 ?>/<?= (int)$position['positions_expected'] ?>)</li>
        <?php if ((int)$position['hires_count']+1 >= (int)$position['positions_expected']): ?>
        <li style="color:#16a34a;font-weight:700">Auto-chiusura della posizione (target raggiunto)</li>
        <?php endif; ?>
      </ol>
    </div>
    <div style="display:flex;gap:10px">
      <button type="submit" class="btn btn-primary" style="background:#16a34a"
              onclick="return confirm('Confermi la trasformazione di <?= h(addslashes($candidate['first_name'].' '.$candidate['last_name'])) ?> in dipendente?\n\nL\'operazione è irreversibile via UI.')">
        <i class="fa-solid fa-user-check"></i> Esegui assunzione
      </button>
      <a href="<?= url_safe('candidato_profilo', ['id' => $candidate_id]) ?>" class="btn">
        <i class="fa-solid fa-xmark"></i> Annulla
      </a>
    </div>
  </div>
  <?php endif; ?>
</form>

<?php require_once('footer.php'); ?>
