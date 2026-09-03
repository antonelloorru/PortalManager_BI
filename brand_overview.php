<?php
/**
 * PortalManager — brand_overview.php
 *
 * Vista 360° EDITABILE di un singolo brand: tutte le informazioni e operazioni
 * CRUD in un'unica schermata.
 *
 * Sezioni con editing inline:
 *   1. Header brand (nome, descrizione, partnership level, priority, colore)
 *   2. Contatti Brand (PAM, SL esterno)
 *   3. Team Interno (BM, SL interno)
 *   4. Requisiti Partnership (req_company/commercial/technical)
 *   5. Referenti Dipendenti (add/remove con storico)
 *   6. Distributori Associati (link/unlink + edit ranking/tipo/referenti)
 *   7. Tecnologie & Servizi (add/remove)
 *   8. Link & Risorse (partner_portal, learning, tech_doc)
 *
 * Handler POST con whitelist di campi per ogni sezione.
 * Pattern PRG: tutti i submit → redirect alla stessa pagina con flash message.
 */
require_once('access_control.php');
require_once('functions.php');

$brand_id = (int)($_GET['id'] ?? 0);
if ($brand_id <= 0) { redirect('brand'); }

$u_id = (int)$_SESSION['user_id'];
$can_view = can('view',   'brand_overview.php') || can('view', 'brand.php');
$can_edit = can('edit',   'brand.php');
if (!$can_view) { redirect('brand'); }

// Carica brand
$bq = $pdo->prepare("SELECT * FROM brands WHERE id = ?");
$bq->execute([$brand_id]);
$b = $bq->fetch();
if (!$b) { redirect('brand'); }

// ════════════════════════════════════════════════════════════════════════
// POST HANDLERS (PRG pattern)
// ════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    Csrf::verify();
    $action = $_POST['action'] ?? '';

    try {
        // ─── Whitelist di campi modificabili in `brands` ───
        $brand_field_groups = [
            'header'      => ['name','description','partnership_level','priority','priority_color'],
            'contacts'    => ['pam_name','pam_email','pam_phone','pam_phone2','brand_sl_name','brand_sl_email','brand_sl_phone'],
            'team'        => ['internal_bm_name','internal_bm_email','internal_bm_phone','internal_sl_name','internal_sl_email','internal_sl_phone'],
            'requirements'=> ['req_company','req_commercial','req_technical'],
            'links'       => ['partner_portal_link','learning_link','tech_doc_link'],
        ];

        if (in_array($action, ['update_header','update_contacts','update_team','update_requirements','update_links'], true)) {
            $group = str_replace('update_', '', $action);
            $fields = $brand_field_groups[$group] ?? [];
            if (empty($fields)) throw new RuntimeException('Gruppo sconosciuto');
            $set = []; $params = [];
            foreach ($fields as $f) {
                if (isset($_POST[$f])) {
                    $val = trim((string)$_POST[$f]);
                    if (in_array($f, ['req_company','req_commercial','req_technical','priority'], true)) {
                        $val = (int)$val;
                        if ($f === 'priority') $val = max(1, min(5, $val));
                    }
                    if (in_array($f, ['name'], true) && $val === '') {
                        throw new RuntimeException('Il nome è obbligatorio');
                    }
                    $set[] = "`$f` = ?";
                    $params[] = $val === '' && $f !== 'name' ? null : $val;
                }
            }
            if (!empty($set)) {
                $params[] = $brand_id;
                $pdo->prepare("UPDATE brands SET " . implode(',', $set) . " WHERE id = ?")->execute($params);
                write_log('Brand','success', "Brand #$brand_id aggiornato sezione $group",$u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Sezione aggiornata.</div>";
            }
        }

        // ─── REFERENTI: add ───
        elseif ($action === 'add_referent') {
            $emp_id    = (int)($_POST['employee_id'] ?? 0);
            $role_type = $_POST['role_type'] ?? 'brand_manager';
            $start     = $_POST['start_date'] ?? date('Y-m-d');
            $notes     = trim((string)($_POST['notes'] ?? '')) ?: null;
            $allowed_roles = ['brand_manager','account_commerciale','referente_formazione','tecnico'];
            if (!in_array($role_type, $allowed_roles, true)) $role_type = 'brand_manager';
            if ($emp_id <= 0) throw new RuntimeException('Dipendente non selezionato');
            // Idempotenza: chiudi eventuale referente esistente attivo con stesso ruolo+emp
            $pdo->prepare("UPDATE brand_referents SET end_date = CURDATE() WHERE brand_id=? AND employee_id=? AND role_type=? AND end_date IS NULL")
                ->execute([$brand_id, $emp_id, $role_type]);
            $pdo->prepare("INSERT INTO brand_referents (brand_id, employee_id, role_type, start_date, notes) VALUES (?,?,?,?,?)")
                ->execute([$brand_id, $emp_id, $role_type, $start, $notes]);
            write_log('Brand','success', "Referente aggiunto: emp #$emp_id su brand #$brand_id ($role_type)",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Referente aggiunto.</div>";
        }

        elseif ($action === 'remove_referent') {
            $ref_id = (int)($_POST['referent_id'] ?? 0);
            if ($ref_id > 0) {
                $pdo->prepare("UPDATE brand_referents SET end_date = CURDATE() WHERE id = ? AND brand_id = ?")
                    ->execute([$ref_id, $brand_id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-info'><i class='fa-solid fa-circle-info'></i> Referente chiuso (end_date = oggi).</div>";
            }
        }

        // ─── DISTRIBUTORI: link / update / unlink ───
        elseif ($action === 'link_distributor') {
            $dist_id  = (int)($_POST['distributor_id'] ?? 0);
            $ranking  = $_POST['ranking'] ?? 'primary';
            if (!in_array($ranking, ['primary','secondary'], true)) $ranking = 'primary';
            $prio_ord = max(1, min(99, (int)($_POST['priority_order'] ?? 1)));
            $is_vol   = !empty($_POST['is_volume'])  ? 1 : 0;
            $is_val   = !empty($_POST['is_value'])   ? 1 : 0;
            $is_acad  = !empty($_POST['is_academy']) ? 1 : 0;
            $disc     = ($_POST['discount_pct'] ?? '') !== '' ? (float)str_replace(',', '.', $_POST['discount_pct']) : null;
            $notes    = trim((string)($_POST['link_notes'] ?? '')) ?: null;
            if ($dist_id <= 0) throw new RuntimeException('Distributore non selezionato');
            // UPSERT
            $pdo->prepare("
                INSERT INTO brand_distributors
                  (brand_id, distributor_id, ranking, priority_order, is_volume, is_value, is_academy,
                   commercial_ref, commercial_email, commercial_phone,
                   academy_ref, academy_email, academy_phone, contract_ref, discount_pct, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  ranking = VALUES(ranking),
                  priority_order = VALUES(priority_order),
                  is_volume = VALUES(is_volume),
                  is_value  = VALUES(is_value),
                  is_academy = VALUES(is_academy),
                  commercial_ref = VALUES(commercial_ref),
                  commercial_email = VALUES(commercial_email),
                  commercial_phone = VALUES(commercial_phone),
                  academy_ref = VALUES(academy_ref),
                  academy_email = VALUES(academy_email),
                  academy_phone = VALUES(academy_phone),
                  contract_ref = VALUES(contract_ref),
                  discount_pct = VALUES(discount_pct),
                  notes = VALUES(notes)
            ")->execute([
                $brand_id, $dist_id, $ranking, $prio_ord, $is_vol, $is_val, $is_acad,
                trim((string)($_POST['commercial_ref']   ?? '')) ?: null,
                trim((string)($_POST['commercial_email'] ?? '')) ?: null,
                trim((string)($_POST['commercial_phone'] ?? '')) ?: null,
                trim((string)($_POST['academy_ref']      ?? '')) ?: null,
                trim((string)($_POST['academy_email']    ?? '')) ?: null,
                trim((string)($_POST['academy_phone']    ?? '')) ?: null,
                trim((string)($_POST['contract_ref']     ?? '')) ?: null,
                $disc, $notes,
            ]);
            write_log('Brand','success', "Distributore #$dist_id collegato/aggiornato su brand #$brand_id",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Distributore collegato/aggiornato.</div>";
        }

        elseif ($action === 'unlink_distributor') {
            $bd_id = (int)($_POST['brand_distributor_id'] ?? 0);
            if ($bd_id > 0) {
                $pdo->prepare("DELETE FROM brand_distributors WHERE id = ? AND brand_id = ?")
                    ->execute([$bd_id, $brand_id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Distributore disassociato.</div>";
            }
        }

        // ─── TECNOLOGIE: add / remove ───
        elseif ($action === 'add_technology') {
            $name    = trim((string)($_POST['tech_name'] ?? ''));
            $version = trim((string)($_POST['tech_version'] ?? '')) ?: null;
            $category= trim((string)($_POST['tech_category'] ?? '')) ?: null;
            if ($name === '') throw new RuntimeException('Nome tecnologia obbligatorio');
            $pdo->prepare("INSERT INTO brand_technologies (brand_id, name, version, category) VALUES (?,?,?,?)")
                ->execute([$brand_id, $name, $version, $category]);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Tecnologia aggiunta.</div>";
        }

        elseif ($action === 'remove_technology') {
            $tech_id = (int)($_POST['technology_id'] ?? 0);
            if ($tech_id > 0) {
                $pdo->prepare("DELETE FROM brand_technologies WHERE id = ? AND brand_id = ?")
                    ->execute([$tech_id, $brand_id]);
                $_SESSION['flash_msg'] = "<div class='alert alert-info'><i class='fa-solid fa-trash'></i> Tecnologia rimossa.</div>";
            }
        }

        else { throw new RuntimeException('Azione non valida: ' . h($action)); }

    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . h($e->getMessage()) . "</div>";
    }

    header("Location: " . url_safe('brand_overview', ['id' => $brand_id])); exit;
}

// ════════════════════════════════════════════════════════════════════════
// CARICA DATI PER LA VISTA
// ════════════════════════════════════════════════════════════════════════
$refs = []; $dists = []; $techs = [];
$all_distributors = []; $all_employees = [];

try {
    $rq = $pdo->prepare("
        SELECT br.*, e.first_name, e.last_name, e.business_email AS email, e.phone, e.job_title
          FROM brand_referents br
          JOIN employees e ON br.employee_id = e.id
         WHERE br.brand_id = ? AND (br.end_date IS NULL OR br.end_date >= CURDATE())
         ORDER BY br.role_type, br.start_date DESC
    ");
    $rq->execute([$brand_id]);
    $refs = $rq->fetchAll();

    $dq = $pdo->prepare("
        SELECT bd.id AS bd_id, bd.*, d.name AS dist_name, d.type AS dist_type, d.city, d.website,
               d.status AS dist_status,
               d.commercial_name AS d_comm_name, d.commercial_email AS d_comm_email,
               d.academy_name AS d_acad_name, d.academy_email AS d_acad_email
          FROM brand_distributors bd
          JOIN distributors d ON bd.distributor_id = d.id
         WHERE bd.brand_id = ?
         ORDER BY bd.ranking ASC, bd.priority_order ASC, d.name
    ");
    $dq->execute([$brand_id]);
    $dists = $dq->fetchAll();

    $tq = $pdo->prepare("SELECT id, name, version, category FROM brand_technologies WHERE brand_id = ? ORDER BY category, name");
    $tq->execute([$brand_id]);
    $techs = $tq->fetchAll();

    // Liste per select
    $all_distributors = $pdo->query("SELECT id, name, type, status FROM distributors WHERE status='active' ORDER BY name")->fetchAll();
    $all_employees = $pdo->query("SELECT id, first_name, last_name, business_email, job_title FROM employees WHERE status='active' ORDER BY last_name, first_name")->fetchAll();
} catch (Throwable $e) {}

$proj_count = 0; $emp_count = 0;
try {
    $proj_count = (int)$pdo->query("SELECT COUNT(*) FROM project_brands WHERE brand_id = $brand_id")->fetchColumn();
    $emp_count  = (int)$pdo->query("SELECT COUNT(DISTINCT employee_id) FROM employee_brands WHERE brand_id = $brand_id")->fetchColumn();
} catch (Throwable $e) {}

// v1.7.42: certificazioni del brand (catalogo + possedute aggregate)
$cert_summary = [
    'catalog_total' => 0,
    'held_total' => 0,
    'employees_certified' => 0,
    'by_cat' => ['aziendale'=>0,'commerciale'=>0,'tecnica'=>0],
    'top_certs' => [],
];
try {
    $cert_summary['catalog_total'] = (int)$pdo->query("SELECT COUNT(*) FROM certifications WHERE brand_id = $brand_id AND is_active = 1")->fetchColumn();

    $qs = $pdo->prepare("
        SELECT c.category, COUNT(uc.id) AS held, COUNT(DISTINCT uc.employee_id) AS emp_n
          FROM user_certifications uc
          JOIN certifications c ON uc.certification_id = c.id
         WHERE c.brand_id = ? AND uc.status = 'active'
         GROUP BY c.category
    ");
    $qs->execute([$brand_id]);
    foreach ($qs->fetchAll() as $r) {
        $cert_summary['by_cat'][$r['category'] ?: 'tecnica'] = (int)$r['held'];
        $cert_summary['held_total'] += (int)$r['held'];
    }

    $qe = $pdo->prepare("SELECT COUNT(DISTINCT uc.employee_id)
                          FROM user_certifications uc
                          JOIN certifications c ON uc.certification_id = c.id
                         WHERE c.brand_id = ? AND uc.status = 'active'");
    $qe->execute([$brand_id]);
    $cert_summary['employees_certified'] = (int)$qe->fetchColumn();

    // Top 10 certificazioni più possedute (con n. holder)
    $qt = $pdo->prepare("
        SELECT c.id, c.name, c.code, c.category, c.level, COUNT(uc.id) AS held
          FROM certifications c
          LEFT JOIN user_certifications uc
                 ON uc.certification_id = c.id AND uc.status = 'active'
         WHERE c.brand_id = ? AND c.is_active = 1
         GROUP BY c.id, c.name, c.code, c.category, c.level
         ORDER BY held DESC, c.name
         LIMIT 12
    ");
    $qt->execute([$brand_id]);
    $cert_summary['top_certs'] = $qt->fetchAll();
} catch (Throwable $e) {}

require_once('header.php');

if (!function_exists('bv_chip')) {
    function bv_chip(string $label, string $color = '#3b82f6', string $icon = ''): string {
        $i = $icon ? '<i class="fa-solid ' . h($icon) . '" style="margin-right:4px"></i>' : '';
        return '<span style="display:inline-block;background:' . $color . '15;color:' . $color
             . ';padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;margin-right:4px;margin-bottom:3px;border:1px solid ' . $color . '40">'
             . $i . h($label) . '</span>';
    }
}

$priority = (int)($b['priority'] ?? 3);
$priority_color = $b['priority_color'] ?? '#3b82f6';
$priority_labels = [1=>'Critica',2=>'Alta',3=>'Media',4=>'Bassa',5=>'Minima'];
$role_labels = [
    'brand_manager'       => ['Brand Manager',        '#7c3aed'],
    'account_commerciale' => ['Account Commerciale',  '#0ea5e9'],
    'referente_formazione'=> ['Referente Formazione', '#16a34a'],
    'tecnico'             => ['Tecnico',              '#f59e0b'],
];
?>

<style>
.bv-card { background: #fff; border-radius: 8px; padding: 16px; margin-bottom: 14px; border: 1px solid #e2e8f0; }
.bv-card > h3 { margin: 0 0 12px 0; font-size: 14px; padding-bottom: 6px; border-bottom: 1px dashed #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; }
.bv-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
.bv-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 900px) { .bv-grid-3, .bv-grid-2 { grid-template-columns: 1fr; } }
.bv-edit-btn { background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600 }
.bv-edit-btn:hover { background:#e2e8f0 }
.bv-modal { display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9999; align-items:center; justify-content:center; padding:18px }
.bv-modal.show { display:flex }
.bv-modal-box { background:#fff; border-radius:10px; width:600px; max-width:100%; max-height:90vh; overflow-y:auto; padding:22px; box-shadow:0 12px 48px rgba(0,0,0,.3) }
.bv-modal-box h3 { margin:0 0 14px 0; color:#0f172a; font-size:16px }
.bv-field { margin-bottom:10px }
.bv-field label { display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:3px; text-transform:uppercase; letter-spacing:0.3px }
.bv-field input[type=text], .bv-field input[type=email], .bv-field input[type=number], .bv-field input[type=date], .bv-field select, .bv-field textarea {
  width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit;
}
.bv-row { display:flex; gap:8px }
.bv-row > * { flex:1 }
.bv-row-end { display:flex; justify-content:flex-end; gap:8px; margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0 }
</style>

<?php if (!empty($_SESSION['flash_msg'])): ?>
<?= $_SESSION['flash_msg'] ?>
<?php unset($_SESSION['flash_msg']); endif; ?>

<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <div>
    <h2 style="margin:0;font-size:21px;color:#0f172a">
      <i class="fa-solid fa-tags" style="color:#7c3aed"></i> Vista 360° Brand
    </h2>
    <div style="font-size:12px;color:#64748b;margin-top:3px">
      <a href="<?= url_safe('brand') ?>" style="color:#64748b"><i class="fa-solid fa-arrow-left"></i> Catalogo brand</a>
      · ID #<?= (int)$b['id'] ?>
    </div>
  </div>
  <button onclick="window.print()" class="btn"><i class="fa-solid fa-print"></i> Stampa</button>
</div>

<!-- ═════ HEADER BRAND ═════ -->
<div class="bv-card" style="background:linear-gradient(135deg,#f8fafc 0%,#eff6ff 100%)">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <?php if (!empty($b['logo_path']) && file_exists($b['logo_path'])): ?>
    <div style="width:74px;height:74px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
      <img src="<?= h($b['logo_path']) ?>" alt="<?= h($b['name']) ?>" style="max-width:68px;max-height:68px;object-fit:contain">
    </div>
    <?php else: ?>
    <div style="width:74px;height:74px;background:#e2e8f0;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:28px;color:#64748b;font-weight:800;flex-shrink:0">
      <?= h(strtoupper(substr($b['name'],0,2))) ?>
    </div>
    <?php endif; ?>
    <div style="flex:1;min-width:230px">
      <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px">VENDOR</div>
      <h1 style="margin:0 0 4px 0;font-size:24px;color:#0f172a;font-weight:800"><?= h($b['name']) ?></h1>
      <?php if ($b['description']): ?>
      <div style="font-size:12px;color:#475569;line-height:1.4"><?= h($b['description']) ?></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end">
      <span style="background:#7c3aed15;color:#7c3aed;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid #7c3aed40">
        <i class="fa-solid fa-medal"></i> <?= h($b['partnership_level'] ?? 'Registered') ?>
      </span>
      <span style="background:<?= h($priority_color) ?>15;color:<?= h($priority_color) ?>;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;border:1.5px solid <?= h($priority_color) ?>40">
        <i class="fa-solid fa-flag"></i> P<?= $priority ?> <?= h($priority_labels[$priority] ?? '') ?>
      </span>
      <div style="font-size:10px;color:#64748b;margin-top:2px">
        <?= $proj_count ?> progetti · <?= $emp_count ?> certificati
      </div>
      <?php if ($can_edit): ?>
      <button onclick="bvShow('mHeader')" class="bv-edit-btn"><i class="fa-solid fa-pen"></i> Modifica</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═════ CONTATTI / TEAM / REQUISITI ═════ -->
<div class="bv-grid-3">
  <div class="bv-card">
    <h3 style="color:#0ea5e9">
      <span><i class="fa-solid fa-address-book"></i> Contatti Brand</span>
      <?php if ($can_edit): ?><button onclick="bvShow('mContacts')" class="bv-edit-btn"><i class="fa-solid fa-pen"></i></button><?php endif; ?>
    </h3>
    <div style="margin-bottom:12px">
      <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:3px"><i class="fa-solid fa-user-tie"></i> PAM</div>
      <div style="font-weight:700;color:#0f172a;font-size:13px"><?= h($b['pam_name'] ?: '—') ?></div>
      <?php if ($b['pam_email']): ?><div style="font-size:11px"><a href="mailto:<?= h($b['pam_email']) ?>" style="color:#3b82f6"><i class="fa-solid fa-envelope"></i> <?= h($b['pam_email']) ?></a></div><?php endif; ?>
      <?php if ($b['pam_phone']): ?><div style="font-size:11px;color:#64748b"><i class="fa-solid fa-phone"></i> <?= h($b['pam_phone']) ?><?php if ($b['pam_phone2']): ?> · <?= h($b['pam_phone2']) ?><?php endif; ?></div><?php endif; ?>
    </div>
    <div>
      <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:3px"><i class="fa-solid fa-handshake"></i> SL Brand</div>
      <div style="font-weight:700;color:#0f172a;font-size:13px"><?= h($b['brand_sl_name'] ?: '—') ?></div>
      <?php if ($b['brand_sl_email']): ?><div style="font-size:11px"><a href="mailto:<?= h($b['brand_sl_email']) ?>" style="color:#3b82f6"><i class="fa-solid fa-envelope"></i> <?= h($b['brand_sl_email']) ?></a></div><?php endif; ?>
      <?php if ($b['brand_sl_phone']): ?><div style="font-size:11px;color:#64748b"><i class="fa-solid fa-phone"></i> <?= h($b['brand_sl_phone']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="bv-card">
    <h3 style="color:#16a34a">
      <span><i class="fa-solid fa-people-group"></i> Team Interno</span>
      <?php if ($can_edit): ?><button onclick="bvShow('mTeam')" class="bv-edit-btn"><i class="fa-solid fa-pen"></i></button><?php endif; ?>
    </h3>
    <div style="margin-bottom:12px">
      <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:3px"><i class="fa-solid fa-user-shield"></i> Brand Manager</div>
      <div style="font-weight:700;color:#0f172a;font-size:13px"><?= h($b['internal_bm_name'] ?: '—') ?></div>
      <?php if ($b['internal_bm_email']): ?><div style="font-size:11px"><a href="mailto:<?= h($b['internal_bm_email']) ?>" style="color:#3b82f6"><i class="fa-solid fa-envelope"></i> <?= h($b['internal_bm_email']) ?></a></div><?php endif; ?>
      <?php if ($b['internal_bm_phone']): ?><div style="font-size:11px;color:#64748b"><i class="fa-solid fa-phone"></i> <?= h($b['internal_bm_phone']) ?></div><?php endif; ?>
    </div>
    <div>
      <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:3px"><i class="fa-solid fa-chart-line"></i> Sales Leader</div>
      <div style="font-weight:700;color:#0f172a;font-size:13px"><?= h($b['internal_sl_name'] ?: '—') ?></div>
      <?php if ($b['internal_sl_email']): ?><div style="font-size:11px"><a href="mailto:<?= h($b['internal_sl_email']) ?>" style="color:#3b82f6"><i class="fa-solid fa-envelope"></i> <?= h($b['internal_sl_email']) ?></a></div><?php endif; ?>
      <?php if ($b['internal_sl_phone']): ?><div style="font-size:11px;color:#64748b"><i class="fa-solid fa-phone"></i> <?= h($b['internal_sl_phone']) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="bv-card">
    <h3 style="color:#dc2626">
      <span><i class="fa-solid fa-clipboard-check"></i> Requisiti Partnership</span>
      <?php if ($can_edit): ?><button onclick="bvShow('mReq')" class="bv-edit-btn"><i class="fa-solid fa-pen"></i></button><?php endif; ?>
    </h3>
    <table style="width:100%;font-size:12px">
      <tr><td style="padding:6px 0;color:#475569">Azienda</td><td style="text-align:right;font-weight:700;font-size:14px;color:#dc2626"><?= (int)$b['req_company'] ?></td></tr>
      <tr><td style="padding:6px 0;color:#475569;border-top:1px dashed #e2e8f0">Commerciali</td><td style="text-align:right;font-weight:700;font-size:14px;color:#dc2626;border-top:1px dashed #e2e8f0"><?= (int)$b['req_commercial'] ?></td></tr>
      <tr><td style="padding:6px 0;color:#475569;border-top:1px dashed #e2e8f0">Tecnici</td><td style="text-align:right;font-weight:700;font-size:14px;color:#dc2626;border-top:1px dashed #e2e8f0"><?= (int)$b['req_technical'] ?></td></tr>
    </table>
    <?php $tot_req = (int)$b['req_company'] + (int)$b['req_commercial'] + (int)$b['req_technical']; ?>
    <div style="margin-top:8px;padding:6px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:11px;color:#991b1b;text-align:center">
      Totale richiesti: <strong style="font-size:14px"><?= $tot_req ?></strong>
    </div>
  </div>
</div>

<!-- ═════ v1.7.42: CERTIFICAZIONI ═════ -->
<div class="bv-card">
  <h3 style="color:#dc2626">
    <span><i class="fa-solid fa-certificate"></i> Certificazioni del brand</span>
    <span style="font-size:11px;color:#64748b;font-weight:500">
      <strong style="color:#0f172a;font-size:14px"><?= $cert_summary['held_total'] ?></strong> attive
      · <?= $cert_summary['employees_certified'] ?> dipendenti certificati
      · catalogo: <?= $cert_summary['catalog_total'] ?>
    </span>
  </h3>
  <?php if ($cert_summary['held_total'] === 0 && $cert_summary['catalog_total'] === 0): ?>
  <div style="padding:18px;text-align:center;color:#94a3b8;font-style:italic;background:#f8fafc;border-radius:6px">
    Nessuna certificazione censita per questo brand. Verifica il catalogo certificazioni.
  </div>
  <?php else: ?>
    <!-- Split per categoria -->
    <?php if ($cert_summary['held_total'] > 0): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <?php
        $cat_colors = ['aziendale'=>'#7c3aed','commerciale'=>'#0ea5e9','tecnica'=>'#16a34a'];
        $cat_icons  = ['aziendale'=>'fa-building','commerciale'=>'fa-handshake','tecnica'=>'fa-wrench'];
        $cat_labels = ['aziendale'=>'Aziendali','commerciale'=>'Commerciali','tecnica'=>'Tecniche'];
        foreach (['aziendale','commerciale','tecnica'] as $cc):
          $n = (int)($cert_summary['by_cat'][$cc] ?? 0); if ($n === 0) continue;
      ?>
      <span style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:8px;background:<?= $cat_colors[$cc] ?>15;color:<?= $cat_colors[$cc] ?>;font-size:12px;font-weight:700;border:1px solid <?= $cat_colors[$cc] ?>40">
        <i class="fa-solid <?= $cat_icons[$cc] ?>"></i> <?= $n ?> <?= $cat_labels[$cc] ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Top certificazioni (catalogo con n. holder) -->
    <?php if (!empty($cert_summary['top_certs'])): ?>
    <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">
      Certificazioni nel catalogo (<?= count($cert_summary['top_certs']) ?>)
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:6px">
      <?php foreach ($cert_summary['top_certs'] as $c):
        $cc = $cat_colors[$c['category']] ?? '#64748b';
        $held = (int)$c['held'];
      ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:#fafbfc;border:1px solid #e2e8f0;border-radius:6px;font-size:11px">
        <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <strong style="color:#0f172a"><?= h($c['name']) ?></strong>
          <?php if ($c['code']): ?><span style="color:#64748b"> · <?= h($c['code']) ?></span><?php endif; ?>
          <?php if ($c['level']): ?> <span style="font-size:9px;background:<?= $cc ?>15;color:<?= $cc ?>;padding:1px 6px;border-radius:8px"><?= h($c['level']) ?></span><?php endif; ?>
        </div>
        <span style="background:<?= $held > 0 ? '#16a34a' : '#e2e8f0' ?>;color:<?= $held > 0 ? '#fff' : '#64748b' ?>;padding:2px 8px;border-radius:10px;font-weight:700;font-size:10px;min-width:24px;text-align:center"><?= $held ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ═════ REFERENTI ═════ -->
<div class="bv-card">
  <h3 style="color:#7c3aed">
    <span><i class="fa-solid fa-user-shield"></i> Referenti Dipendenti Attuali (<?= count($refs) ?>)</span>
    <?php if ($can_edit): ?><button onclick="bvShow('mRef')" class="bv-edit-btn"><i class="fa-solid fa-plus"></i> Assegna referente</button><?php endif; ?>
  </h3>
  <?php if (empty($refs)): ?>
  <div style="padding:18px;text-align:center;color:#94a3b8;font-style:italic;background:#f8fafc;border-radius:6px">Nessun referente assegnato.</div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
    <?php foreach ($refs as $r):
      [$role_label, $role_color] = $role_labels[$r['role_type']] ?? [$r['role_type'], '#64748b']; ?>
    <div style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;background:#fafbfc;position:relative">
      <div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:6px">
        <div>
          <div style="font-weight:700;font-size:13px;color:#0f172a"><?= h(trim($r['first_name'] . ' ' . $r['last_name'])) ?></div>
          <?php if ($r['job_title']): ?><div style="font-size:11px;color:#64748b"><?= h($r['job_title']) ?></div><?php endif; ?>
        </div>
        <?= bv_chip($role_label, $role_color) ?>
      </div>
      <?php if ($r['email']): ?><div style="font-size:11px;color:#3b82f6;margin-bottom:4px"><i class="fa-solid fa-envelope"></i> <a href="mailto:<?= h($r['email']) ?>" style="color:#3b82f6"><?= h($r['email']) ?></a></div><?php endif; ?>
      <div style="font-size:10px;color:#64748b;margin-top:6px;border-top:1px dashed #e2e8f0;padding-top:6px;display:flex;justify-content:space-between;align-items:center">
        <span>dal <strong><?= date('d/m/Y', strtotime($r['start_date'])) ?></strong></span>
        <?php if ($can_edit): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Chiudere questo referente?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_referent">
          <input type="hidden" name="referent_id" value="<?= (int)$r['id'] ?>">
          <button type="submit" style="background:#fee2e2;color:#991b1b;border:0;padding:2px 8px;border-radius:4px;font-size:10px;cursor:pointer" title="Rimuovi"><i class="fa-solid fa-xmark"></i></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ═════ DISTRIBUTORI ═════ -->
<div class="bv-card">
  <h3 style="color:#0ea5e9">
    <span><i class="fa-solid fa-truck-field"></i> Distributori Associati (<?= count($dists) ?>)</span>
    <?php if ($can_edit): ?><button onclick="bvShow('mDist')" class="bv-edit-btn"><i class="fa-solid fa-plus"></i> Associa distributore</button><?php endif; ?>
  </h3>
  <?php if (empty($dists)): ?>
  <div style="padding:18px;text-align:center;color:#94a3b8;font-style:italic;background:#f8fafc;border-radius:6px">Nessun distributore associato.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
        <th style="padding:8px;text-align:left;font-size:10px;text-transform:uppercase;color:#64748b">Distributore</th>
        <th style="padding:8px;text-align:left;font-size:10px;text-transform:uppercase;color:#64748b">Ranking</th>
        <th style="padding:8px;text-align:left;font-size:10px;text-transform:uppercase;color:#64748b">Tipo</th>
        <th style="padding:8px;text-align:left;font-size:10px;text-transform:uppercase;color:#64748b">Ref. commerciale</th>
        <th style="padding:8px;text-align:left;font-size:10px;text-transform:uppercase;color:#64748b">Ref. academy</th>
        <th style="padding:8px;text-align:right;font-size:10px;text-transform:uppercase;color:#64748b">Sconto</th>
        <?php if ($can_edit): ?><th style="padding:8px;width:80px"></th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($dists as $d):
          $rank_color = $d['ranking'] === 'primary' ? '#16a34a' : '#64748b';
        ?>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:10px 8px">
            <div style="font-weight:700;color:#0f172a"><?= h($d['dist_name']) ?></div>
            <div style="font-size:10px;color:#64748b"><?= h($d['dist_type']) ?><?php if ($d['city']): ?> · <?= h($d['city']) ?><?php endif; ?></div>
          </td>
          <td style="padding:10px 8px">
            <?= bv_chip(strtoupper($d['ranking']), $rank_color) ?>
            <div style="font-size:9px;color:#94a3b8">ord. <?= (int)$d['priority_order'] ?></div>
          </td>
          <td style="padding:10px 8px">
            <?php if ((int)$d['is_volume']):   echo bv_chip('Vol', '#0ea5e9');   endif; ?>
            <?php if ((int)$d['is_value']):    echo bv_chip('Val', '#7c3aed');   endif; ?>
            <?php if ((int)$d['is_academy']):  echo bv_chip('Acad','#16a34a');   endif; ?>
          </td>
          <td style="padding:10px 8px;font-size:11px">
            <?php $cn = $d['commercial_ref'] ?: $d['d_comm_name']; $ce = $d['commercial_email'] ?: $d['d_comm_email']; ?>
            <?php if ($cn): ?><div style="font-weight:600"><?= h($cn) ?></div><?php endif; ?>
            <?php if ($ce): ?><div style="color:#3b82f6;font-size:10px"><?= h($ce) ?></div><?php endif; ?>
            <?php if (!$cn): ?>—<?php endif; ?>
          </td>
          <td style="padding:10px 8px;font-size:11px">
            <?php $an = $d['academy_ref'] ?: $d['d_acad_name']; $ae = $d['academy_email'] ?: $d['d_acad_email']; ?>
            <?php if ($an): ?><div style="font-weight:600"><?= h($an) ?></div><?php endif; ?>
            <?php if ($ae): ?><div style="color:#3b82f6;font-size:10px"><?= h($ae) ?></div><?php endif; ?>
            <?php if (!$an): ?>—<?php endif; ?>
          </td>
          <td style="padding:10px 8px;text-align:right;font-weight:700;color:#16a34a">
            <?= $d['discount_pct'] !== null ? number_format((float)$d['discount_pct'], 1, ',', '.') . '%' : '—' ?>
          </td>
          <?php if ($can_edit): ?>
          <td style="padding:10px 8px;text-align:right">
            <button class="bv-edit-btn" onclick='bvEditDist(<?= htmlspecialchars(json_encode($d), ENT_QUOTES, "UTF-8") ?>)' title="Modifica"><i class="fa-solid fa-pen"></i></button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Disassociare il distributore <?= h(addslashes($d['dist_name'])) ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="unlink_distributor">
              <input type="hidden" name="brand_distributor_id" value="<?= (int)$d['bd_id'] ?>">
              <button type="submit" class="bv-edit-btn" style="background:#fee2e2;color:#991b1b;border-color:#fecaca" title="Disassocia"><i class="fa-solid fa-xmark"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ═════ TECNOLOGIE ═════ -->
<div class="bv-card">
  <h3 style="color:#16a34a">
    <span><i class="fa-solid fa-microchip"></i> Tecnologie & Servizi (<?= count($techs) ?>)</span>
    <?php if ($can_edit): ?><button onclick="bvShow('mTech')" class="bv-edit-btn"><i class="fa-solid fa-plus"></i> Aggiungi tecnologia</button><?php endif; ?>
  </h3>
  <?php if (empty($techs)): ?>
  <div style="padding:18px;text-align:center;color:#94a3b8;font-style:italic;background:#f8fafc;border-radius:6px">Nessuna tecnologia censita.</div>
  <?php else: ?>
  <?php $by_cat = []; foreach ($techs as $t) $by_cat[$t['category'] ?: 'Altro'][] = $t; ?>
  <?php foreach ($by_cat as $cat => $items): ?>
  <div style="margin-bottom:10px">
    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px"><?= h($cat) ?> (<?= count($items) ?>)</div>
    <div style="display:flex;flex-wrap:wrap;gap:4px">
      <?php foreach ($items as $t):
        $label = $t['name'] . ($t['version'] ? ' (' . $t['version'] . ')' : ''); ?>
      <span style="display:inline-flex;align-items:center;background:#16a34a15;color:#16a34a;padding:3px 4px 3px 10px;border-radius:12px;font-size:11px;font-weight:600;border:1px solid #16a34a40">
        <?= h($label) ?>
        <?php if ($can_edit): ?>
        <form method="POST" style="display:inline;margin-left:4px" onsubmit="return confirm('Rimuovere <?= h(addslashes($label)) ?>?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_technology">
          <input type="hidden" name="technology_id" value="<?= (int)$t['id'] ?>">
          <button type="submit" style="background:transparent;border:0;color:#dc2626;cursor:pointer;padding:0 4px;font-size:10px" title="Rimuovi"><i class="fa-solid fa-xmark"></i></button>
        </form>
        <?php endif; ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ═════ LINK ═════ -->
<div class="bv-card">
  <h3 style="color:#f59e0b">
    <span><i class="fa-solid fa-link"></i> Link & Risorse Esterne</span>
    <?php if ($can_edit): ?><button onclick="bvShow('mLinks')" class="bv-edit-btn"><i class="fa-solid fa-pen"></i></button><?php endif; ?>
  </h3>
  <?php if (!$b['partner_portal_link'] && !$b['learning_link'] && !$b['tech_doc_link']): ?>
  <div style="padding:14px;text-align:center;color:#94a3b8;font-style:italic;background:#f8fafc;border-radius:6px">Nessun link configurato.</div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px">
    <?php foreach ([['partner_portal_link','Partner portal','fa-handshake','#7c3aed'],['learning_link','Learning portal','fa-graduation-cap','#16a34a'],['tech_doc_link','Documentazione tecnica','fa-book-open','#0ea5e9']] as [$f,$lbl,$ic,$cl]): if (!$b[$f]) continue; ?>
    <a href="<?= h($b[$f]) ?>" target="_blank" style="padding:10px;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#0f172a;display:flex;align-items:center;gap:10px;background:#fafbfc">
      <i class="fa-solid <?= $ic ?>" style="font-size:18px;color:<?= $cl ?>"></i>
      <div style="flex:1;min-width:0">
        <div style="font-size:10px;color:#64748b"><?= h($lbl) ?></div>
        <div style="font-weight:600;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($b[$f]) ?></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($can_edit): ?>
<!-- ════════════════════════════════════════════════════════════════════════ -->
<!-- MODALI                                                                    -->
<!-- ════════════════════════════════════════════════════════════════════════ -->

<!-- Header -->
<div id="mHeader" class="bv-modal">
  <div class="bv-modal-box">
    <h3><i class="fa-solid fa-pen"></i> Modifica scheda principale</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_header">
      <div class="bv-field"><label>Nome *</label><input type="text" name="name" value="<?= h($b['name']) ?>" required></div>
      <div class="bv-field"><label>Descrizione</label><textarea name="description" rows="3"><?= h($b['description'] ?? '') ?></textarea></div>
      <div class="bv-row">
        <div class="bv-field"><label>Partnership level</label><input type="text" name="partnership_level" value="<?= h($b['partnership_level'] ?? '') ?>" placeholder="Gold, Silver, Platinum..."></div>
        <div class="bv-field"><label>Priorità (1-5)</label>
          <select name="priority">
            <?php foreach ($priority_labels as $pn=>$pl): ?>
            <option value="<?= $pn ?>" <?= $priority === $pn ? 'selected' : '' ?>><?= $pn ?> · <?= $pl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bv-field"><label>Colore priorità (HEX)</label><input type="text" name="priority_color" value="<?= h($priority_color) ?>" placeholder="#3b82f6" maxlength="7"></div>
      </div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mHeader')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Contatti -->
<div id="mContacts" class="bv-modal">
  <div class="bv-modal-box">
    <h3><i class="fa-solid fa-address-book"></i> Contatti Brand</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_contacts">
      <div style="font-size:11px;font-weight:700;color:#0ea5e9;text-transform:uppercase;margin-bottom:8px;padding-top:6px"><i class="fa-solid fa-user-tie"></i> PAM (Partner Account Manager)</div>
      <div class="bv-field"><label>Nome</label><input type="text" name="pam_name" value="<?= h($b['pam_name'] ?? '') ?>"></div>
      <div class="bv-row">
        <div class="bv-field"><label>Email</label><input type="email" name="pam_email" value="<?= h($b['pam_email'] ?? '') ?>"></div>
        <div class="bv-field"><label>Telefono</label><input type="text" name="pam_phone" value="<?= h($b['pam_phone'] ?? '') ?>"></div>
        <div class="bv-field"><label>Tel. 2</label><input type="text" name="pam_phone2" value="<?= h($b['pam_phone2'] ?? '') ?>"></div>
      </div>
      <div style="font-size:11px;font-weight:700;color:#0ea5e9;text-transform:uppercase;margin:14px 0 8px 0;padding-top:10px;border-top:1px dashed #e2e8f0"><i class="fa-solid fa-handshake"></i> Sales Leader Brand (esterno)</div>
      <div class="bv-field"><label>Nome</label><input type="text" name="brand_sl_name" value="<?= h($b['brand_sl_name'] ?? '') ?>"></div>
      <div class="bv-row">
        <div class="bv-field"><label>Email</label><input type="email" name="brand_sl_email" value="<?= h($b['brand_sl_email'] ?? '') ?>"></div>
        <div class="bv-field"><label>Telefono</label><input type="text" name="brand_sl_phone" value="<?= h($b['brand_sl_phone'] ?? '') ?>"></div>
      </div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mContacts')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Team -->
<div id="mTeam" class="bv-modal">
  <div class="bv-modal-box">
    <h3><i class="fa-solid fa-people-group"></i> Team Interno</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_team">
      <div style="font-size:11px;font-weight:700;color:#16a34a;text-transform:uppercase;margin-bottom:8px"><i class="fa-solid fa-user-shield"></i> Brand Manager (interno)</div>
      <div class="bv-field"><label>Nome</label><input type="text" name="internal_bm_name" value="<?= h($b['internal_bm_name'] ?? '') ?>"></div>
      <div class="bv-row">
        <div class="bv-field"><label>Email</label><input type="email" name="internal_bm_email" value="<?= h($b['internal_bm_email'] ?? '') ?>"></div>
        <div class="bv-field"><label>Telefono</label><input type="text" name="internal_bm_phone" value="<?= h($b['internal_bm_phone'] ?? '') ?>"></div>
      </div>
      <div style="font-size:11px;font-weight:700;color:#16a34a;text-transform:uppercase;margin:14px 0 8px 0;padding-top:10px;border-top:1px dashed #e2e8f0"><i class="fa-solid fa-chart-line"></i> Sales Leader (interno)</div>
      <div class="bv-field"><label>Nome</label><input type="text" name="internal_sl_name" value="<?= h($b['internal_sl_name'] ?? '') ?>"></div>
      <div class="bv-row">
        <div class="bv-field"><label>Email</label><input type="email" name="internal_sl_email" value="<?= h($b['internal_sl_email'] ?? '') ?>"></div>
        <div class="bv-field"><label>Telefono</label><input type="text" name="internal_sl_phone" value="<?= h($b['internal_sl_phone'] ?? '') ?>"></div>
      </div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mTeam')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Requisiti -->
<div id="mReq" class="bv-modal">
  <div class="bv-modal-box">
    <h3><i class="fa-solid fa-clipboard-check"></i> Requisiti Partnership</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_requirements">
      <div class="bv-row">
        <div class="bv-field"><label>Azienda</label><input type="number" name="req_company" min="0" max="999" value="<?= (int)$b['req_company'] ?>"></div>
        <div class="bv-field"><label>Commerciali</label><input type="number" name="req_commercial" min="0" max="999" value="<?= (int)$b['req_commercial'] ?>"></div>
        <div class="bv-field"><label>Tecnici</label><input type="number" name="req_technical" min="0" max="999" value="<?= (int)$b['req_technical'] ?>"></div>
      </div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mReq')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Link esterni -->
<div id="mLinks" class="bv-modal">
  <div class="bv-modal-box">
    <h3><i class="fa-solid fa-link"></i> Link & Risorse</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_links">
      <div class="bv-field"><label><i class="fa-solid fa-handshake"></i> Partner portal</label><input type="text" name="partner_portal_link" value="<?= h($b['partner_portal_link'] ?? '') ?>" placeholder="https://..."></div>
      <div class="bv-field"><label><i class="fa-solid fa-graduation-cap"></i> Learning portal</label><input type="text" name="learning_link" value="<?= h($b['learning_link'] ?? '') ?>" placeholder="https://..."></div>
      <div class="bv-field"><label><i class="fa-solid fa-book-open"></i> Documentazione tecnica</label><input type="text" name="tech_doc_link" value="<?= h($b['tech_doc_link'] ?? '') ?>" placeholder="https://..."></div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mLinks')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Nuovo referente -->
<div id="mRef" class="bv-modal">
  <div class="bv-modal-box">
    <h3><i class="fa-solid fa-user-shield"></i> Assegna referente dipendente</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_referent">
      <div class="bv-field">
        <label>Dipendente *</label>
        <select name="employee_id" required>
          <option value="">— Seleziona dipendente —</option>
          <?php foreach ($all_employees as $e): ?>
          <option value="<?= (int)$e['id'] ?>"><?= h($e['last_name'] . ' ' . $e['first_name']) ?><?php if ($e['job_title']): ?> — <?= h($e['job_title']) ?><?php endif; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bv-row">
        <div class="bv-field">
          <label>Ruolo *</label>
          <select name="role_type" required>
            <option value="brand_manager">Brand Manager</option>
            <option value="account_commerciale">Account Commerciale</option>
            <option value="referente_formazione">Referente Formazione</option>
            <option value="tecnico">Tecnico</option>
          </select>
        </div>
        <div class="bv-field"><label>Data inizio *</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
      </div>
      <div class="bv-field"><label>Note</label><input type="text" name="notes" maxlength="255" placeholder="Note libere"></div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mRef')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Assegna</button>
      </div>
    </form>
  </div>
</div>

<!-- Distributore (add + edit) -->
<div id="mDist" class="bv-modal">
  <div class="bv-modal-box" style="width:700px">
    <h3 id="mDistTitle"><i class="fa-solid fa-truck-field"></i> Associa distributore</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="link_distributor">
      <div class="bv-row">
        <div class="bv-field">
          <label>Distributore *</label>
          <select name="distributor_id" id="md_dist" required>
            <option value="">— Seleziona —</option>
            <?php foreach ($all_distributors as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= h($d['name']) ?> (<?= h($d['type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bv-field"><label>Ranking</label>
          <select name="ranking" id="md_rank">
            <option value="primary">Primary</option>
            <option value="secondary">Secondary</option>
          </select>
        </div>
        <div class="bv-field"><label>Ordine</label><input type="number" name="priority_order" id="md_ord" min="1" max="99" value="1"></div>
      </div>
      <div class="bv-row" style="margin:6px 0 10px 0">
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer"><input type="checkbox" name="is_volume" id="md_vol" value="1"> Volume</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer"><input type="checkbox" name="is_value" id="md_val" value="1"> Valore</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer"><input type="checkbox" name="is_academy" id="md_acad" value="1"> Academy</label>
      </div>
      <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:10px 0 6px 0;padding-top:8px;border-top:1px dashed #e2e8f0">Ref. commerciale (specifico)</div>
      <div class="bv-row">
        <div class="bv-field"><label>Nome</label><input type="text" name="commercial_ref" id="md_cref"></div>
        <div class="bv-field"><label>Email</label><input type="email" name="commercial_email" id="md_cemail"></div>
        <div class="bv-field"><label>Telefono</label><input type="text" name="commercial_phone" id="md_cphone"></div>
      </div>
      <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin:10px 0 6px 0">Ref. academy (specifico)</div>
      <div class="bv-row">
        <div class="bv-field"><label>Nome</label><input type="text" name="academy_ref" id="md_aref"></div>
        <div class="bv-field"><label>Email</label><input type="email" name="academy_email" id="md_aemail"></div>
        <div class="bv-field"><label>Telefono</label><input type="text" name="academy_phone" id="md_aphone"></div>
      </div>
      <div class="bv-row">
        <div class="bv-field"><label>Riferimento contratto</label><input type="text" name="contract_ref" id="md_contract" maxlength="100"></div>
        <div class="bv-field"><label>Sconto (%)</label><input type="text" name="discount_pct" id="md_disc" placeholder="es. 15.5"></div>
      </div>
      <div class="bv-field"><label>Note</label><textarea name="link_notes" id="md_notes" rows="2"></textarea></div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mDist')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<!-- Nuova tecnologia -->
<div id="mTech" class="bv-modal">
  <div class="bv-modal-box">
    <h3><i class="fa-solid fa-microchip"></i> Aggiungi tecnologia</h3>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_technology">
      <div class="bv-field"><label>Nome *</label><input type="text" name="tech_name" required maxlength="200" placeholder="es. vSphere, Horizon, FortiGate"></div>
      <div class="bv-row">
        <div class="bv-field"><label>Versione</label><input type="text" name="tech_version" maxlength="50" placeholder="es. 8.0"></div>
        <div class="bv-field"><label>Categoria</label><input type="text" name="tech_category" maxlength="100" placeholder="es. Virtualizzazione, Networking"></div>
      </div>
      <div class="bv-row-end">
        <button type="button" onclick="bvHide('mTech')" class="btn">Annulla</button>
        <button type="submit" class="btn btn-primary">Aggiungi</button>
      </div>
    </form>
  </div>
</div>

<script>
function bvShow(id){ document.getElementById(id).classList.add('show'); }
function bvHide(id){ document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.bv-modal').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('show'); });
});

function bvEditDist(d){
  document.getElementById('mDistTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Modifica associazione';
  document.getElementById('md_dist').value     = d.distributor_id;
  document.getElementById('md_dist').disabled  = true;
  document.getElementById('md_rank').value     = d.ranking;
  document.getElementById('md_ord').value      = d.priority_order;
  document.getElementById('md_vol').checked    = d.is_volume == 1;
  document.getElementById('md_val').checked    = d.is_value  == 1;
  document.getElementById('md_acad').checked   = d.is_academy == 1;
  document.getElementById('md_cref').value     = d.commercial_ref   || '';
  document.getElementById('md_cemail').value   = d.commercial_email || '';
  document.getElementById('md_cphone').value   = d.commercial_phone || '';
  document.getElementById('md_aref').value     = d.academy_ref      || '';
  document.getElementById('md_aemail').value   = d.academy_email    || '';
  document.getElementById('md_aphone').value   = d.academy_phone    || '';
  document.getElementById('md_contract').value = d.contract_ref     || '';
  document.getElementById('md_disc').value     = d.discount_pct     || '';
  document.getElementById('md_notes').value    = d.notes            || '';
  bvShow('mDist');
}
</script>
<?php endif; ?>

<?php require_once('footer.php'); ?>
