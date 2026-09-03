<?php
/**
 * PortalManager — project_form.php
 *
 * Form CRUD per creare/modificare un progetto.
 * Selezione multi-tag di Brand, Tecnologie e Certificazioni con
 * possibilità di aggiungere nuovi valori al volo (modal inline).
 */
require_once('access_control.php');

$u_role = (int)($_SESSION['role_id'] ?? 99);
$u_id   = (int)$_SESSION['user_id'];
$can_create = can('create');
$can_edit   = can('edit');
$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

if ($is_edit && !$can_edit) { redirect('projects'); }
if (!$is_edit && !$can_create) { redirect('projects'); }

$msg = '';

// ── POST: SAVE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project'])) {
    Csrf::verify();

    $data = [
        'title'              => trim((string)($_POST['title'] ?? '')),
        'description'        => trim((string)($_POST['description'] ?? '')),
        'client_id'          => (int)($_POST['client_id'] ?? 0),
        'executing_company_id' => ($_POST['executing_company_id'] ?? '') !== '' ? (int)$_POST['executing_company_id'] : null,
        'service_type'       => trim((string)($_POST['service_type'] ?? '')) ?: null,
        'employees_involved' => ($_POST['employees_involved'] ?? '') !== '' ? (int)$_POST['employees_involved'] : null,
        'users_impacted'     => ($_POST['users_impacted'] ?? '')     !== '' ? (int)$_POST['users_impacted']     : null,
        'date_start'         => $_POST['date_start'] ?: null,
        'date_end'           => $_POST['date_end']   ?: null,
        'is_in_progress'     => !empty($_POST['is_in_progress']) ? 1 : 0,
        'status'             => $_POST['status'] ?? 'active',
        'value_euro'         => ($_POST['value_euro']      ?? '') !== '' ? (float)str_replace(',', '.', $_POST['value_euro']) : null,
        'amount_services'    => ($_POST['amount_services'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['amount_services']) : null,
        'amount_hw_sw'       => ($_POST['amount_hw_sw']    ?? '') !== '' ? (float)str_replace(',', '.', $_POST['amount_hw_sw']) : null,
        'duration_text'      => trim((string)($_POST['duration_text']    ?? '')) ?: null,
        'commercial_agent'   => trim((string)($_POST['commercial_agent'] ?? '')) ?: null,
        'period_text'        => trim((string)($_POST['period_text']      ?? '')) ?: null,
        'tech_areas'         => trim((string)($_POST['tech_areas']       ?? '')) ?: null,
        'notes'              => trim((string)($_POST['notes']            ?? '')) ?: null,
        'confidential'       => !empty($_POST['confidential']) ? 1 : 0,
    ];

    // Validazione
    $errors = [];
    if ($data['title'] === '')      $errors[] = 'Titolo obbligatorio';
    if ($data['client_id'] <= 0)    $errors[] = 'Cliente obbligatorio';
    if ($data['date_start'] && $data['date_end'] && $data['date_end'] < $data['date_start']) {
        $errors[] = 'Data fine antecedente a data inizio';
    }
    if ($data['is_in_progress']) $data['date_end'] = null;

    if (!empty($errors)) {
        $msg = "<div class='alert alert-danger'><strong>Errori:</strong><ul style='margin:5px 0 0 20px'><li>" . implode('</li><li>', array_map('h', $errors)) . "</li></ul></div>";
    } else {
        try {
            $pdo->beginTransaction();

            if ($is_edit) {
                $sql = "UPDATE projects SET
                          title=?, description=?, client_id=?, executing_company_id=?, service_type=?,
                          employees_involved=?, users_impacted=?,
                          date_start=?, date_end=?, is_in_progress=?, status=?,
                          value_euro=?, amount_services=?, amount_hw_sw=?,
                          duration_text=?, commercial_agent=?, period_text=?, tech_areas=?, notes=?,
                          confidential=?
                        WHERE id=?";
                $params = [...array_values($data), $id];
                $pdo->prepare($sql)->execute($params);
                $project_id = $id;
                $action = 'aggiornato';
            } else {
                $sql = "INSERT INTO projects
                          (title, description, client_id, executing_company_id, service_type,
                           employees_involved, users_impacted,
                           date_start, date_end, is_in_progress, status,
                           value_euro, amount_services, amount_hw_sw,
                           duration_text, commercial_agent, period_text, tech_areas, notes,
                           confidential, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                $params = [...array_values($data), $u_id];
                $pdo->prepare($sql)->execute($params);
                $project_id = (int)$pdo->lastInsertId();
                $action = 'creato';
            }

            // Pivot: ricreo le relazioni M:N
            $brand_ids = array_filter(array_map('intval', $_POST['brand_ids'] ?? []));
            $tech_ids  = array_filter(array_map('intval', $_POST['tech_ids']  ?? []));
            $cert_ids  = array_filter(array_map('intval', $_POST['cert_ids']  ?? []));

            $pdo->prepare("DELETE FROM project_brands WHERE project_id = ?")->execute([$project_id]);
            $pdo->prepare("DELETE FROM project_technologies WHERE project_id = ?")->execute([$project_id]);
            $pdo->prepare("DELETE FROM project_certifications WHERE project_id = ?")->execute([$project_id]);

            $ins_b = $pdo->prepare("INSERT IGNORE INTO project_brands (project_id, brand_id) VALUES (?, ?)");
            foreach ($brand_ids as $bid) $ins_b->execute([$project_id, $bid]);

            $ins_t = $pdo->prepare("INSERT IGNORE INTO project_technologies (project_id, brand_technology_id) VALUES (?, ?)");
            foreach ($tech_ids as $tid) $ins_t->execute([$project_id, $tid]);

            $ins_c = $pdo->prepare("INSERT IGNORE INTO project_certifications (project_id, certification_id, required) VALUES (?, ?, ?)");
            foreach ($cert_ids as $cid) $ins_c->execute([$project_id, $cid, !empty($_POST["cert_req_$cid"]) ? 1 : 0]);

            $pdo->commit();

            write_log('Projects','success',"Progetto #$project_id $action",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Progetto $action.</div>";
            redirect('projects');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "<div class='alert alert-danger'>Errore salvataggio: " . h($e->getMessage()) . "</div>";
        }
    }
}

// ── Carica progetto se modifica ──
$project = [
    'title' => '', 'description' => '', 'client_id' => 0, 'executing_company_id' => '', 'service_type' => '',
    'employees_involved' => '', 'users_impacted' => '',
    'date_start' => '', 'date_end' => '',
    'is_in_progress' => 0, 'status' => 'active',
    'value_euro' => '', 'amount_services' => '', 'amount_hw_sw' => '',
    'duration_text' => '', 'commercial_agent' => '', 'period_text' => '',
    'tech_areas' => '', 'notes' => '',
    'confidential' => 0,
];
$selected_brands = [];
$selected_techs  = [];
$selected_certs  = [];
$cert_required = [];

if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { redirect('projects'); }
    $project = array_merge($project, $row);

    $selected_brands = $pdo->prepare("SELECT brand_id FROM project_brands WHERE project_id = ?");
    $selected_brands->execute([$id]);
    $selected_brands = array_column($selected_brands->fetchAll(), 'brand_id');

    $selected_techs = $pdo->prepare("SELECT brand_technology_id FROM project_technologies WHERE project_id = ?");
    $selected_techs->execute([$id]);
    $selected_techs = array_column($selected_techs->fetchAll(), 'brand_technology_id');

    $cstmt = $pdo->prepare("SELECT certification_id, required FROM project_certifications WHERE project_id = ?");
    $cstmt->execute([$id]);
    foreach ($cstmt->fetchAll() as $r) {
        $selected_certs[] = (int)$r['certification_id'];
        if ($r['required']) $cert_required[(int)$r['certification_id']] = 1;
    }
}

// ── Liste per dropdown ──
$clients = $pdo->query("SELECT id, name, size_category FROM project_clients ORDER BY name")->fetchAll();
$companies_list = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$brands  = $pdo->query("SELECT id, name FROM brands ORDER BY name")->fetchAll();
$techs   = $pdo->query("
    SELECT bt.id, bt.name, bt.brand_id, bt.version, b.name AS brand_name
      FROM brand_technologies bt
      JOIN brands b ON bt.brand_id = b.id
     WHERE bt.status = 'active'
     ORDER BY b.name, bt.name
")->fetchAll();
$certs   = $pdo->query("SELECT id, name, code FROM certifications ORDER BY name")->fetchAll();
$service_types = explode(';', (string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='project_service_types'")->fetchColumn());
$service_types = array_filter(array_map('trim', $service_types));

require_once('header.php');
?>

<div style="margin-bottom:18px">
  <h2 style="margin:0;color:#0f172a;font-size:22px">
    <i class="fa-solid fa-diagram-project" style="color:#7c3aed"></i>
    <?= $is_edit ? 'Modifica' : 'Nuovo' ?> progetto
  </h2>
  <div style="font-size:12px;color:#64748b;margin-top:3px">
    <a href="<?= url_safe('projects') ?>" style="color:#64748b"><i class="fa-solid fa-arrow-left"></i> Torna alla lista</a>
  </div>
</div>

<?= $msg ?>

<form method="POST" action="<?= h(qs_self_safe()) ?>" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="save_project" value="1">

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px">
    <!-- ═════ COLONNA 1: DATI PRINCIPALI ═════ -->
    <div class="card" style="padding:18px">
      <h3 style="margin:0 0 12px 0;font-size:14px;color:#7c3aed">
        <i class="fa-solid fa-info-circle"></i> Dati principali
      </h3>

      <div style="margin-bottom:12px">
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">
          Titolo progetto *
        </label>
        <input type="text" name="title" value="<?= h($project['title']) ?>" required maxlength="255"
               placeholder="Es: Consolidamento DC Cliente XYZ con migrazione VMware → Nutanix"
               style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>

      <div style="margin-bottom:12px">
        <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">
          Descrizione attività svolte
        </label>
        <textarea name="description" rows="6"
                  placeholder="Descrivi nel dettaglio le attività realizzate, le sfide affrontate, i risultati ottenuti..."
                  style="width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;font-family:inherit"><?= h($project['description']) ?></textarea>
      </div>

      <div style="display:grid;grid-template-columns:2fr 1.2fr 1fr;gap:10px;margin-bottom:12px">
        <div>
          <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">
            Cliente *
          </label>
          <select name="client_id" required
                  style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
            <option value="">— Seleziona cliente —</option>
            <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$project['client_id'] === (int)$c['id'] ? 'selected' : '' ?>>
              <?= h($c['name']) ?> <span style="color:#64748b">(<?= h($c['size_category']) ?>)</span>
            </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:10px;color:#64748b;margin-top:3px">
            <a href="<?= url_safe('project_clients') ?>" target="_blank">
              <i class="fa-solid fa-plus"></i> Crea nuovo cliente
            </a>
          </div>
        </div>
        <div>
          <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">
            <i class="fa-solid fa-building" style="color:#7c3aed"></i> Azienda esecutrice
          </label>
          <select name="executing_company_id"
                  style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
            <option value="">— Non specificata —</option>
            <?php foreach ($companies_list as $co): ?>
            <option value="<?= (int)$co['id'] ?>" <?= (int)($project['executing_company_id'] ?? 0) === (int)$co['id'] ? 'selected' : '' ?>>
              <?= h($co['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:10px;color:#64748b;margin-top:3px">
            Quale azienda del gruppo ha eseguito il progetto
          </div>
        </div>
        <div>
          <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">
            Tipo servizio
          </label>
          <input type="text" name="service_type" value="<?= h($project['service_type']) ?>"
                 list="service_types_list" placeholder="Es: Consolidamento"
                 style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
          <datalist id="service_types_list">
            <?php foreach ($service_types as $st): ?>
            <option value="<?= h($st) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:12px">
        <div>
          <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Data inizio</label>
          <input type="date" name="date_start" value="<?= h($project['date_start']) ?>"
                 style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
        </div>
        <div>
          <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Data fine</label>
          <input type="date" name="date_end" value="<?= h($project['date_end']) ?>"
                 style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px" id="dateEndInput">
        </div>
        <div>
          <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Dipendenti coinvolti</label>
          <input type="number" name="employees_involved" value="<?= h($project['employees_involved'] ?? '') ?>" min="0"
                 style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
        </div>
        <div>
          <label style="font-weight:700;font-size:12px;color:#475569;display:block;margin-bottom:4px">Utenti impattati</label>
          <input type="number" name="users_impacted" value="<?= h($project['users_impacted'] ?? '') ?>" min="0"
                 style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
        </div>
      </div>

      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:8px;background:#f8fafc;border-radius:6px;font-size:13px">
        <label style="cursor:pointer">
          <input type="checkbox" name="is_in_progress" value="1" <?= $project['is_in_progress'] ? 'checked' : '' ?>
                 onchange="document.getElementById('dateEndInput').disabled = this.checked; if(this.checked) document.getElementById('dateEndInput').value='';">
          <strong>Progetto in corso</strong>
        </label>
        <label style="cursor:pointer">
          <input type="checkbox" name="confidential" value="1" <?= $project['confidential'] ? 'checked' : '' ?>>
          <i class="fa-solid fa-lock" style="color:#dc2626"></i> <strong>Confidenziale</strong>
          <span style="font-size:11px;color:#64748b">(solo ruoli 1-3)</span>
        </label>
        <label style="margin-left:auto">
          <span style="font-weight:700;font-size:12px;color:#475569;margin-right:6px">Stato:</span>
          <select name="status" style="padding:5px 9px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
            <option value="active"    <?= $project['status']==='active'?'selected':''     ?>>Attivo</option>
            <option value="completed" <?= $project['status']==='completed'?'selected':''  ?>>Completato</option>
            <option value="on_hold"   <?= $project['status']==='on_hold'?'selected':''    ?>>Sospeso</option>
            <option value="cancelled" <?= $project['status']==='cancelled'?'selected':''  ?>>Annullato</option>
            <option value="draft"     <?= $project['status']==='draft'?'selected':''      ?>>Bozza</option>
          </select>
        </label>
        <label>
          <span style="font-weight:700;font-size:12px;color:#475569;margin-right:6px">Valore tot. (€):</span>
          <input type="number" name="value_euro" value="<?= h($project['value_euro'] ?? '') ?>" step="0.01" min="0"
                 placeholder="opzionale" id="valTotInput"
                 style="width:120px;padding:5px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
        </label>
      </div>

      <!-- ═════ v1.7.33: DATI COMMERCIALI ═════ -->
      <div style="margin-top:14px;padding-top:14px;border-top:1px dashed #cbd5e1">
        <h3 style="margin:0 0 10px 0;font-size:13px;color:#7c3aed">
          <i class="fa-solid fa-coins"></i> Dati commerciali
        </h3>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px">
          <div>
            <label style="font-weight:700;font-size:11px;color:#475569;display:block;margin-bottom:3px">Importo Servizi (€)</label>
            <input type="number" name="amount_services" value="<?= h($project['amount_services'] ?? '') ?>" step="0.01" min="0" id="amtServ"
                   style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
          </div>
          <div>
            <label style="font-weight:700;font-size:11px;color:#475569;display:block;margin-bottom:3px">Importo HW/SW (€)</label>
            <input type="number" name="amount_hw_sw" value="<?= h($project['amount_hw_sw'] ?? '') ?>" step="0.01" min="0" id="amtHwSw"
                   style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
          </div>
          <div>
            <label style="font-weight:700;font-size:11px;color:#475569;display:block;margin-bottom:3px">Durata</label>
            <input type="text" name="duration_text" value="<?= h($project['duration_text'] ?? '') ?>" maxlength="100"
                   placeholder="es: 6 MESI, 10 anni"
                   style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
          </div>
          <div>
            <label style="font-weight:700;font-size:11px;color:#475569;display:block;margin-bottom:3px">Agente commerciale</label>
            <input type="text" name="commercial_agent" value="<?= h($project['commercial_agent'] ?? '') ?>" maxlength="150"
                   placeholder="es: Mario Rossi"
                   style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:10px">
          <div>
            <label style="font-weight:700;font-size:11px;color:#475569;display:block;margin-bottom:3px">Periodo (testo libero)</label>
            <input type="text" name="period_text" value="<?= h($project['period_text'] ?? '') ?>" maxlength="100"
                   placeholder="es: 2023-2024, novembre 2023-ottobre 2026"
                   style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
          </div>
          <div>
            <label style="font-weight:700;font-size:11px;color:#475569;display:block;margin-bottom:3px">Aree tecnologiche (testo libero, separate da |)</label>
            <input type="text" name="tech_areas" value="<?= h($project['tech_areas'] ?? '') ?>" maxlength="500"
                   placeholder="es: TRASLOCO DC|VDI|CABLING|COLLABORATION"
                   style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px">
          </div>
        </div>

        <div>
          <label style="font-weight:700;font-size:11px;color:#475569;display:block;margin-bottom:3px">Note aggiuntive</label>
          <textarea name="notes" rows="2"
                    placeholder="Note operative, dettagli particolari, riferimenti aggiuntivi..."
                    style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px;font-family:inherit"><?= h($project['notes'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <!-- ═════ COLONNA 2: SELEZIONI MULTI-TAG ═════ -->
    <div style="display:flex;flex-direction:column;gap:12px">

      <!-- Brand -->
      <div class="card" style="padding:14px">
        <h3 style="margin:0 0 8px 0;font-size:13px;color:#0ea5e9">
          <i class="fa-solid fa-tags"></i> Brand coinvolti
        </h3>
        <select name="brand_ids[]" multiple size="6"
                style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;height:130px">
          <?php foreach ($brands as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $selected_brands, true) ? 'selected' : '' ?>>
            <?= h($b['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:10px;color:#64748b;margin-top:3px">Ctrl/Cmd+click per più selezioni</div>
      </div>

      <!-- Tecnologie -->
      <div class="card" style="padding:14px">
        <h3 style="margin:0 0 8px 0;font-size:13px;color:#16a34a">
          <i class="fa-solid fa-microchip"></i> Tecnologie utilizzate
        </h3>
        <select name="tech_ids[]" multiple size="6"
                style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;height:130px">
          <?php foreach ($techs as $t): ?>
          <option value="<?= (int)$t['id'] ?>" data-brand="<?= (int)($t['brand_id'] ?? 0) ?>"
                  <?= in_array((int)$t['id'], $selected_techs, true) ? 'selected' : '' ?>>
            <?= h($t['brand_name']) ?> · <?= h($t['name']) ?><?= !empty($t['version']) ? ' (' . h($t['version']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Certificazioni -->
      <div class="card" style="padding:14px">
        <h3 style="margin:0 0 8px 0;font-size:13px;color:#dc2626">
          <i class="fa-solid fa-certificate"></i> Certificazioni
        </h3>
        <select name="cert_ids[]" multiple size="6"
                style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;height:130px">
          <?php foreach ($certs as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $selected_certs, true) ? 'selected' : '' ?>>
            <?= h($c['name']) ?><?= $c['code'] ? ' (' . h($c['code']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:10px;color:#64748b;margin-top:3px">
          Sia richieste dal cliente che semplicemente impiegate
        </div>
      </div>
    </div>
  </div>

  <!-- ═════ BOTTONI ═════ -->
  <div class="card" style="padding:14px;display:flex;gap:10px;align-items:center">
    <button type="submit" class="btn btn-primary" style="background:#7c3aed">
      <i class="fa-solid fa-floppy-disk"></i> <?= $is_edit ? 'Salva modifiche' : 'Crea progetto' ?>
    </button>
    <a href="<?= url_safe('projects') ?>" class="btn">
      <i class="fa-solid fa-xmark"></i> Annulla
    </a>
    <?php if ($is_edit): ?>
    <span style="margin-left:auto;font-size:11px;color:#94a3b8">
      Progetto #<?= (int)$id ?> ·
      <?php if (!empty($project['created_at'])): ?>Creato il <?= date('d/m/Y H:i', strtotime($project['created_at'])) ?><?php endif; ?>
    </span>
    <?php endif; ?>
  </div>
</form>

<?php require_once('footer.php'); ?>
