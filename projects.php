<?php
/**
 * PortalManager — projects.php
 *
 * Lista progetti con motore di ricerca multi-criterio (AND).
 * Riusa il componente ListFilter (v1.7.19) per search/export universali.
 *
 * Filtri server-side specifici:
 *   - Brand (multi-select)
 *   - Tecnologia (multi-select, popola dinamico al brand selezionato)
 *   - Tipo di servizio (ENUM da app_settings)
 *   - Certificazione richiesta
 *   - Dimensione cliente (PMI/Enterprise/Datacenter)
 *   - Range numero dipendenti coinvolti
 *   - Range numero utenti impattati
 *   - Stato (active/completed/in_progress)
 *
 * Ricerca testuale: titolo, descrizione, cliente
 */
require_once('access_control.php');

$u_role  = (int)($_SESSION['role_id'] ?? 99);
$u_id    = (int)$_SESSION['user_id'];
$can_create = can('create');
$can_edit   = can('edit');
$can_delete = can('delete');
$msg = '';

// ── POST: cancellazione progetto ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_delete && isset($_POST['delete_project'])) {
    Csrf::verify();
    $id = (int)$_POST['project_id'];
    if ($id > 0) {
        try {
            $pdo->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
            write_log('Projects', 'success', "Progetto #$id eliminato", $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Progetto eliminato.</div>";
        } catch (Throwable $e) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        }
    }
    redirect_self();
}

if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

// ── Filtri da GET ──
$f_q          = trim((string)($_GET['q']          ?? ''));
$f_brand_ids  = array_filter(array_map('intval', explode(',', (string)($_GET['brand_ids'] ?? ''))));
$f_tech_ids   = array_filter(array_map('intval', explode(',', (string)($_GET['tech_ids']  ?? ''))));
$f_cert_ids   = array_filter(array_map('intval', explode(',', (string)($_GET['cert_ids']  ?? ''))));
$f_service    = trim((string)($_GET['service_type']  ?? ''));
$f_size       = trim((string)($_GET['size_category'] ?? ''));
$f_status     = trim((string)($_GET['status']        ?? ''));
$f_executor  = ($_GET['executor_id'] ?? '') !== '' ? (int)$_GET['executor_id'] : null;
$f_agent     = trim((string)($_GET['agent']     ?? ''));
$f_techarea  = trim((string)($_GET['tech_area'] ?? ''));
$f_emp_min    = ($_GET['emp_min'] ?? '') !== '' ? (int)$_GET['emp_min'] : null;
$f_emp_max    = ($_GET['emp_max'] ?? '') !== '' ? (int)$_GET['emp_max'] : null;
$f_usr_min    = ($_GET['usr_min'] ?? '') !== '' ? (int)$_GET['usr_min'] : null;
$f_usr_max    = ($_GET['usr_max'] ?? '') !== '' ? (int)$_GET['usr_max'] : null;
$f_year_from  = ($_GET['year_from'] ?? '') !== '' ? (int)$_GET['year_from'] : null;
$f_year_to    = ($_GET['year_to']   ?? '') !== '' ? (int)$_GET['year_to']   : null;

// ── Costruzione query ──
$where  = [];
$params = [];

if ($f_q !== '') {
    $where[] = "(p.title LIKE ? OR p.description LIKE ? OR c.name LIKE ? OR p.tech_areas LIKE ? OR p.commercial_agent LIKE ? OR p.notes LIKE ?)";
    $like = "%$f_q%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($f_service !== '')  { $where[] = "p.service_type = ?";  $params[] = $f_service; }
if ($f_size !== '')     { $where[] = "c.size_category = ?"; $params[] = $f_size; }
if ($f_status !== '')   { $where[] = "p.status = ?";        $params[] = $f_status; }
if ($f_executor !== null) { $where[] = "p.executing_company_id = ?"; $params[] = $f_executor; }
if ($f_agent !== '')      { $where[] = "p.commercial_agent LIKE ?"; $params[] = '%' . $f_agent . '%'; }
if ($f_techarea !== '')   { $where[] = "p.tech_areas LIKE ?";       $params[] = '%' . $f_techarea . '%'; }
if ($f_emp_min !== null){ $where[] = "p.employees_involved >= ?"; $params[] = $f_emp_min; }
if ($f_emp_max !== null){ $where[] = "p.employees_involved <= ?"; $params[] = $f_emp_max; }
if ($f_usr_min !== null){ $where[] = "p.users_impacted >= ?";     $params[] = $f_usr_min; }
if ($f_usr_max !== null){ $where[] = "p.users_impacted <= ?";     $params[] = $f_usr_max; }
if ($f_year_from)       { $where[] = "YEAR(p.date_start) >= ?";   $params[] = $f_year_from; }
if ($f_year_to)         { $where[] = "YEAR(COALESCE(p.date_end, p.date_start)) <= ?"; $params[] = $f_year_to; }

// Confidenziali: solo ruoli 1-3
if ($u_role > 3) { $where[] = "p.confidential = 0"; }

// Filtri pivot (M:N → subquery EXISTS)
if (!empty($f_brand_ids)) {
    $ph = implode(',', array_fill(0, count($f_brand_ids), '?'));
    $where[] = "EXISTS (SELECT 1 FROM project_brands pb WHERE pb.project_id = p.id AND pb.brand_id IN ($ph))";
    $params = array_merge($params, $f_brand_ids);
}
if (!empty($f_tech_ids)) {
    $ph = implode(',', array_fill(0, count($f_tech_ids), '?'));
    $where[] = "EXISTS (SELECT 1 FROM project_technologies pt WHERE pt.project_id = p.id AND pt.brand_technology_id IN ($ph))";
    $params = array_merge($params, $f_tech_ids);
}
if (!empty($f_cert_ids)) {
    $ph = implode(',', array_fill(0, count($f_cert_ids), '?'));
    $where[] = "EXISTS (SELECT 1 FROM project_certifications pc WHERE pc.project_id = p.id AND pc.certification_id IN ($ph))";
    $params = array_merge($params, $f_cert_ids);
}

$where_sql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

// ── Query principale ──
$sql = "
    SELECT p.id, p.title, p.service_type, p.status, p.is_in_progress,
           p.employees_involved, p.users_impacted,
           p.date_start, p.date_end, p.confidential, p.executing_company_id,
           p.duration_text, p.commercial_agent, p.amount_services, p.amount_hw_sw,
           p.period_text, p.tech_areas, p.value_euro,
           c.id AS client_id, c.name AS client_name, c.size_category,
           c.employees_count AS client_employees, c.users_count AS client_users,
           ec.name AS executor_name,
           (SELECT GROUP_CONCAT(DISTINCT b.name ORDER BY b.name SEPARATOR ', ')
              FROM project_brands pb JOIN brands b ON pb.brand_id = b.id
             WHERE pb.project_id = p.id) AS brands_list,
           (SELECT GROUP_CONCAT(DISTINCT bt.name ORDER BY bt.name SEPARATOR ', ')
              FROM project_technologies pt JOIN brand_technologies bt ON pt.brand_technology_id = bt.id
             WHERE pt.project_id = p.id) AS techs_list,
           (SELECT COUNT(*)
              FROM project_certifications pcert
             WHERE pcert.project_id = p.id) AS certs_count
      FROM projects p
      JOIN project_clients c ON p.client_id = c.id
      LEFT JOIN companies ec ON p.executing_company_id = ec.id
    $where_sql
     ORDER BY p.date_start DESC, p.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// ── Liste per dropdown filtri ──
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

// Helper per chip
function proj_chip(string $label, string $color = '#3b82f6'): string {
    return '<span style="display:inline-block;background:' . $color . '15;color:' . $color
         . ';padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600;margin-right:3px;margin-bottom:2px;border:1px solid ' . $color . '40">'
         . h($label) . '</span>';
}

// Helper status badge
function proj_status_badge(string $status, int $in_progress): string {
    if ($in_progress) return proj_chip('🟢 IN CORSO', '#16a34a');
    return match($status) {
        'active'    => proj_chip('▶ Attivo', '#0ea5e9'),
        'completed' => proj_chip('✓ Completato', '#16a34a'),
        'on_hold'   => proj_chip('⏸ Sospeso', '#f59e0b'),
        'cancelled' => proj_chip('✕ Annullato', '#dc2626'),
        'draft'     => proj_chip('📝 Bozza', '#94a3b8'),
        default     => proj_chip(strtoupper($status), '#64748b'),
    };
}
?>

<div style="margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
  <div>
    <h2 style="margin:0;color:#0f172a;font-size:22px">
      <i class="fa-solid fa-diagram-project" style="color:#7c3aed"></i> Progetti & Referenze Clienti
    </h2>
    <div style="font-size:12px;color:#64748b;margin-top:3px">
      Archivio storico delle attività realizzate · Ricerca avanzata · Export per offerte/gare
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($can_create): ?>
    <a href="<?= url_safe('project_form') ?>" class="btn btn-primary">
      <i class="fa-solid fa-plus"></i> Nuovo progetto
    </a>
    <?php endif; ?>
    <a href="<?= url_safe('project_clients') ?>" class="btn">
      <i class="fa-solid fa-handshake"></i> Anagrafica clienti
    </a>
  </div>
</div>

<?= $msg ?>

<!-- ═════ PANNELLO FILTRI AVANZATI ═════ -->
<div class="card" style="padding:14px;margin-bottom:14px">
  <details <?= ($f_q || $f_brand_ids || $f_tech_ids || $f_cert_ids || $f_service || $f_size) ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-weight:700;color:#7c3aed;font-size:13px;user-select:none">
      <i class="fa-solid fa-sliders"></i> Pannello filtri avanzati
      <?php $active = array_sum([(int)!!$f_q, (int)!!$f_brand_ids, (int)!!$f_tech_ids, (int)!!$f_cert_ids,
                                  (int)!!$f_service, (int)!!$f_size, (int)!!$f_status,
                                  (int)($f_executor!==null), (int)!!$f_agent, (int)!!$f_techarea,
                                  (int)($f_emp_min!==null), (int)($f_emp_max!==null),
                                  (int)($f_usr_min!==null), (int)($f_usr_max!==null),
                                  (int)!!$f_year_from, (int)!!$f_year_to]); ?>
      <?php if ($active): ?>
      <span style="background:#dc2626;color:#fff;padding:1px 8px;border-radius:10px;font-size:10px;margin-left:5px"><?= $active ?> filtri attivi</span>
      <?php endif; ?>
    </summary>

    <form method="GET" action="" id="projectsFilterForm"
          style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px">
      <?php if (!empty($_GET['r'])): ?><input type="hidden" name="r" value="<?= h($_GET['r']) ?>"><?php endif; ?>

      <!-- Search testuale -->
      <div style="grid-column:1/-1">
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">RICERCA TESTUALE (titolo, descrizione, cliente)</label>
        <input type="text" name="q" value="<?= h($f_q) ?>" placeholder="Es: migrazione, datacenter, ...
"
               style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px">
      </div>

      <!-- Brand (multi) -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">BRAND</label>
        <select name="brand_ids[]" multiple id="filterBrand" size="4"
                style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;height:90px">
          <?php foreach ($brands as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= in_array((int)$b['id'], $f_brand_ids, true) ? 'selected' : '' ?>>
            <?= h($b['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:10px;color:#94a3b8;margin-top:2px">Ctrl/Cmd+click per multi-selezione</div>
      </div>

      <!-- Tecnologia (multi, filtrata dinamicamente dal brand) -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">TECNOLOGIA</label>
        <select name="tech_ids[]" multiple id="filterTech" size="4"
                style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;height:90px">
          <?php foreach ($techs as $t): ?>
          <option value="<?= (int)$t['id'] ?>" data-brand="<?= (int)($t['brand_id'] ?? 0) ?>"
                  <?= in_array((int)$t['id'], $f_tech_ids, true) ? 'selected' : '' ?>>
            <?= h($t['brand_name']) ?> · <?= h($t['name']) ?><?= !empty($t['version']) ? ' (' . h($t['version']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:10px;color:#94a3b8;margin-top:2px">Si filtra automaticamente per brand</div>
      </div>

      <!-- Certificazione -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">CERTIFICAZIONE</label>
        <select name="cert_ids[]" multiple size="4"
                style="width:100%;padding:6px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;height:90px">
          <?php foreach ($certs as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $f_cert_ids, true) ? 'selected' : '' ?>>
            <?= h($c['name']) ?><?= $c['code'] ? ' (' . h($c['code']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Tipo servizio -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">TIPO SERVIZIO</label>
        <select name="service_type" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
          <option value="">— Tutti —</option>
          <?php foreach ($service_types as $st): ?>
          <option value="<?= h($st) ?>" <?= $f_service === $st ? 'selected' : '' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Dimensione cliente -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">DIMENSIONE CLIENTE</label>
        <select name="size_category" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
          <option value="">— Tutte —</option>
          <option value="PMI" <?= $f_size==='PMI'?'selected':'' ?>>PMI</option>
          <option value="Enterprise" <?= $f_size==='Enterprise'?'selected':'' ?>>Enterprise</option>
          <option value="Core/Infrastruttura Datacenter" <?= $f_size==='Core/Infrastruttura Datacenter'?'selected':'' ?>>Core/Datacenter</option>
        </select>
      </div>

      <!-- Stato progetto -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">STATO</label>
        <select name="status" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
          <option value="">— Tutti —</option>
          <option value="active"    <?= $f_status==='active'?'selected':'' ?>>Attivo</option>
          <option value="completed" <?= $f_status==='completed'?'selected':'' ?>>Completato</option>
          <option value="on_hold"   <?= $f_status==='on_hold'?'selected':'' ?>>Sospeso</option>
          <option value="cancelled" <?= $f_status==='cancelled'?'selected':'' ?>>Annullato</option>
        </select>
      </div>

      <!-- Azienda esecutrice (v1.7.27) -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">AZIENDA ESECUTRICE</label>
        <select name="executor_id" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
          <option value="">— Tutte le aziende —</option>
          <?php foreach ($companies_list as $co): ?>
          <option value="<?= (int)$co['id'] ?>" <?= $f_executor === (int)$co['id'] ? 'selected' : '' ?>>
            <?= h($co['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- v1.7.33: Filtro Agente commerciale -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">AGENTE COMMERCIALE</label>
        <input type="text" name="agent" value="<?= h($f_agent) ?>" placeholder="es: Rossi, Marini, ..."
               style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
      </div>

      <!-- v1.7.33: Filtro Aree tecnologiche -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">AREA TECNOLOGICA (testo)</label>
        <input type="text" name="tech_area" value="<?= h($f_techarea) ?>" placeholder="es: VDI, CABLING, ..."
               style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
      </div>

      <!-- Range dipendenti coinvolti -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">DIPENDENTI COINVOLTI (range)</label>
        <div style="display:flex;gap:4px">
          <input type="number" name="emp_min" value="<?= h($f_emp_min ?? '') ?>" placeholder="min" min="0"
                 style="width:50%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
          <input type="number" name="emp_max" value="<?= h($f_emp_max ?? '') ?>" placeholder="max" min="0"
                 style="width:50%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
        </div>
      </div>

      <!-- Range utenti impattati -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">UTENTI IMPATTATI (range)</label>
        <div style="display:flex;gap:4px">
          <input type="number" name="usr_min" value="<?= h($f_usr_min ?? '') ?>" placeholder="min" min="0"
                 style="width:50%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
          <input type="number" name="usr_max" value="<?= h($f_usr_max ?? '') ?>" placeholder="max" min="0"
                 style="width:50%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
        </div>
      </div>

      <!-- Range anni -->
      <div>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px">PERIODO (anni)</label>
        <div style="display:flex;gap:4px">
          <input type="number" name="year_from" value="<?= h($f_year_from ?? '') ?>" placeholder="da" min="2000" max="2099"
                 style="width:50%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
          <input type="number" name="year_to" value="<?= h($f_year_to ?? '') ?>" placeholder="a" min="2000" max="2099"
                 style="width:50%;padding:7px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
        </div>
      </div>

      <!-- Actions -->
      <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center;padding-top:10px;border-top:1px solid #e2e8f0">
        <button type="submit" class="btn btn-primary" style="background:#7c3aed">
          <i class="fa-solid fa-magnifying-glass"></i> Applica filtri
        </button>
        <a href="<?= url_safe('projects') ?>" class="btn">
          <i class="fa-solid fa-rotate-left"></i> Reset
        </a>
        <span style="margin-left:auto;font-size:12px;color:#64748b;font-weight:600">
          <i class="fa-solid fa-list"></i> <?= count($projects) ?> risultat<?= count($projects) === 1 ? 'o' : 'i' ?>
        </span>
      </div>
    </form>
  </details>
</div>

<!-- ═════ LISTA PROGETTI ═════ -->
<?php require_once __DIR__ . '/app/ListFilter.php';
ListFilter::render('projects', '#tProjects', ['export_filename' => 'progetti_referenze', 'title' => 'Progetti & Referenze']); ?>

<div class="card" style="overflow-x:auto">
  <table id="tProjects" class="data-table" style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="background:#1e293b;color:#fff">
        <th style="padding:9px 10px;text-align:left">Titolo</th>
        <th style="padding:9px 10px;text-align:left">Cliente</th>
        <th style="padding:9px 10px;text-align:left">Eseguito da</th>
        <th style="padding:9px 10px;text-align:left">Dim.</th>
        <th style="padding:9px 10px;text-align:left">Tipo servizio</th>
        <th style="padding:9px 10px;text-align:right">Importo</th>
        <th style="padding:9px 10px;text-align:left">Agente</th>
        <th style="padding:9px 10px;text-align:left">Aree tec.</th>
        <th style="padding:9px 10px;text-align:left">Brand / Tecnologie</th>
        <th style="padding:9px 10px;text-align:center">Periodo</th>
        <th style="padding:9px 10px;text-align:right">Dip.</th>
        <th style="padding:9px 10px;text-align:right">Utenti</th>
        <th style="padding:9px 10px;text-align:center">Stato</th>
        <?php if ($can_edit || $can_delete): ?>
        <th style="padding:9px 10px;text-align:center">Azioni</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($projects)): ?>
      <tr><td colspan="14" style="padding:30px;text-align:center;color:#94a3b8;font-style:italic">
        Nessun progetto trovato. <?php if ($can_create): ?><a href="<?= url_safe('project_form') ?>">Crea il primo →</a><?php endif; ?>
      </td></tr>
      <?php else: ?>
      <?php foreach ($projects as $p): ?>
      <tr style="border-bottom:1px solid #e2e8f0">
        <td style="padding:8px 10px">
          <a href="<?= url_safe('project_view', ['id' => (int)$p['id']]) ?>" style="color:#0f172a;text-decoration:none;font-weight:700"><?= h($p['title']) ?></a>
          <?php if ($p['confidential']): ?>
          <i class="fa-solid fa-lock" title="Confidenziale" style="color:#dc2626;font-size:10px;margin-left:4px"></i>
          <?php endif; ?>
        </td>
        <td style="padding:8px 10px"><?= h($p['client_name']) ?></td>
        <td style="padding:8px 10px;font-size:12px">
          <?php if (!empty($p['executor_name'])): ?>
          <span style="background:#7c3aed15;color:#7c3aed;padding:2px 9px;border-radius:10px;font-size:11px;font-weight:600;border:1px solid #7c3aed40">
            <i class="fa-solid fa-building"></i> <?= h($p['executor_name']) ?>
          </span>
          <?php else: ?>
          <span style="color:#94a3b8;font-style:italic">—</span>
          <?php endif; ?>
        </td>
        <td style="padding:8px 10px">
          <?= proj_chip($p['size_category'], match($p['size_category']) {
              'PMI' => '#0ea5e9',
              'Enterprise' => '#7c3aed',
              default => '#dc2626',
          }) ?>
        </td>
        <td style="padding:8px 10px"><?= h($p['service_type'] ?? '—') ?></td>
        <td style="padding:8px 10px;text-align:right;font-size:11px;white-space:nowrap;font-variant-numeric:tabular-nums">
          <?php if ($p['value_euro'] !== null && $p['value_euro'] > 0): ?>
          <strong style="color:#0f172a">€ <?= number_format((float)$p['value_euro'], 0, ',', '.') ?></strong>
          <?php if (!empty($p['amount_services']) || !empty($p['amount_hw_sw'])): ?>
          <div style="font-size:9px;color:#64748b;margin-top:2px">
            <?php if (!empty($p['amount_services'])): ?>S: € <?= number_format((float)$p['amount_services'], 0, ',', '.') ?><?php endif; ?>
            <?php if (!empty($p['amount_hw_sw'])): ?> · HW: € <?= number_format((float)$p['amount_hw_sw'], 0, ',', '.') ?><?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if (!empty($p['duration_text'])): ?>
          <div style="font-size:9px;color:#7c3aed"><?= h($p['duration_text']) ?></div>
          <?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="padding:8px 10px;font-size:11px"><?= h($p['commercial_agent'] ?? '—') ?></td>
        <td style="padding:8px 10px;font-size:11px;max-width:140px">
          <?php
            if (!empty($p['tech_areas'])) {
                $areas = array_filter(array_map('trim', explode('|', $p['tech_areas'])));
                foreach (array_slice($areas, 0, 3) as $a) echo proj_chip($a, '#f59e0b');
                if (count($areas) > 3) echo proj_chip('+' . (count($areas) - 3), '#64748b');
            } else echo '—';
          ?>
        </td>
        <td style="padding:8px 10px;max-width:260px">
          <?php
            $brand_arr = $p['brands_list'] ? explode(', ', $p['brands_list']) : [];
            $tech_arr  = $p['techs_list']  ? explode(', ', $p['techs_list'])  : [];
            foreach (array_slice($brand_arr, 0, 4) as $b) echo proj_chip($b, '#0ea5e9');
            if (count($brand_arr) > 4) echo proj_chip('+' . (count($brand_arr) - 4), '#64748b');
            echo '<br>';
            foreach (array_slice($tech_arr, 0, 4) as $t) echo proj_chip($t, '#16a34a');
            if (count($tech_arr) > 4) echo proj_chip('+' . (count($tech_arr) - 4), '#64748b');
          ?>
        </td>
        <td style="padding:8px 10px;text-align:center;font-size:11px;white-space:nowrap">
          <?php
            $ds = $p['date_start'] ? date('m/Y', strtotime($p['date_start'])) : '?';
            $de = $p['is_in_progress'] ? 'in corso' : ($p['date_end'] ? date('m/Y', strtotime($p['date_end'])) : '?');
            echo h("$ds – $de");
          ?>
        </td>
        <td style="padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums"><?= $p['employees_involved'] !== null ? (int)$p['employees_involved'] : '—' ?></td>
        <td style="padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums"><?= $p['users_impacted'] !== null ? number_format((int)$p['users_impacted'], 0, ',', '.') : '—' ?></td>
        <td style="padding:8px 10px;text-align:center;white-space:nowrap"><?= proj_status_badge($p['status'], (int)$p['is_in_progress']) ?></td>
        <?php if ($can_edit || $can_delete): ?>
        <td style="padding:8px 10px;text-align:center;white-space:nowrap">
          <a href="<?= url_safe('project_view', ['id' => (int)$p['id']]) ?>" class="btn btn-sm" title="Vista dettaglio"
             style="background:#7c3aed;color:#fff"><i class="fa-solid fa-eye"></i></a>
          <?php if ($can_edit): ?>
          <a href="<?= url_safe('project_form', ['id' => (int)$p['id']]) ?>" class="btn btn-sm" title="Modifica"
             style="background:#3b82f6;color:#fff;margin-left:3px"><i class="fa-solid fa-pen"></i></a>
          <?php endif; ?>
          <?php if ($can_delete): ?>
          <form method="POST" style="display:inline-block;margin-left:3px"
                onsubmit="return confirm('Eliminare il progetto «<?= h(addslashes($p['title'])) ?>»?\n\nQuesta azione è irreversibile.');">
            <?= csrf_field() ?>
            <input type="hidden" name="delete_project" value="1">
            <input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="btn btn-sm" title="Elimina"
                    style="background:#dc2626;color:#fff;border:0">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
// ── Filtro dinamico: nascondi tecnologie non appartenenti ai brand selezionati ──
(function() {
    const $brand = document.getElementById('filterBrand');
    const $tech  = document.getElementById('filterTech');
    if (!$brand || !$tech) return;

    function syncTechs() {
        const selectedBrands = Array.from($brand.selectedOptions).map(o => parseInt(o.value));
        Array.from($tech.options).forEach(opt => {
            const techBrand = parseInt(opt.dataset.brand || 0);
            // Se nessun brand selezionato → mostra tutte le tecnologie
            // Se brand selezionati → mostra solo quelle del brand
            const show = selectedBrands.length === 0 || selectedBrands.includes(techBrand);
            opt.style.display = show ? '' : 'none';
            opt.disabled = !show;
            if (!show) opt.selected = false;
        });
    }
    $brand.addEventListener('change', syncTechs);
    syncTechs();
})();
</script>

<?php require_once('footer.php'); ?>
