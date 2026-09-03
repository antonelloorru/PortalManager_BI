<?php
/**
 * PortalManager — project_view.php
 *
 * Vista DI SOLA LETTURA di un progetto: scheda completa con tutti i campi,
 * organizzata in sezioni leggibili.
 *
 * Differisce da project_form.php (che è la vista editabile/CRUD):
 *   - Nessun input form, solo visualizzazione
 *   - Layout a "card" raggruppate per tipologia di informazione
 *   - Stampabile (CSS @print)
 *   - Bottone "Modifica" che porta a project_form.php
 */
require_once('access_control.php');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { redirect('projects'); }

// ── Carica progetto con join ──
$stmt = $pdo->prepare("
    SELECT p.*,
           c.name AS client_name, c.size_category AS client_size,
           c.employees_count AS client_employees, c.users_count AS client_users,
           c.industry AS client_industry, c.id AS client_id,
           ec.name AS executor_name,
           COALESCE(u.display_name, SUBSTRING_INDEX(u.email,'@',1), CONCAT('User#',u.id)) AS created_by_name
      FROM projects p
      JOIN project_clients c ON p.client_id = c.id
      LEFT JOIN companies ec ON p.executing_company_id = ec.id
      LEFT JOIN users u ON p.created_by = u.id
     WHERE p.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { redirect('projects'); }

// Permesso confidenziale
if ((int)$p['confidential'] === 1 && (int)$_SESSION['role_id'] > 3) {
    redirect('projects');
}

// ── Brand, Tecnologie, Certificazioni ──
$brands_stmt = $pdo->prepare("
    SELECT b.id, b.name FROM project_brands pb
    JOIN brands b ON pb.brand_id = b.id
    WHERE pb.project_id = ? ORDER BY b.name
");
$brands_stmt->execute([$id]);
$brands = $brands_stmt->fetchAll();

$techs_stmt = $pdo->prepare("
    SELECT bt.id, bt.name, bt.version, b.name AS brand_name
      FROM project_technologies pt
      JOIN brand_technologies bt ON pt.brand_technology_id = bt.id
      JOIN brands b ON bt.brand_id = b.id
     WHERE pt.project_id = ?
     ORDER BY b.name, bt.name
");
$techs_stmt->execute([$id]);
$techs = $techs_stmt->fetchAll();

$certs_stmt = $pdo->prepare("
    SELECT cert.id, cert.name, cert.code, pcert.required
      FROM project_certifications pcert
      JOIN certifications cert ON pcert.certification_id = cert.id
     WHERE pcert.project_id = ?
     ORDER BY cert.name
");
$certs_stmt->execute([$id]);
$certs = $certs_stmt->fetchAll();

$can_edit_proj = can('edit', 'projects.php');

require_once('header.php');

// Helper per chip già esistente in projects.php non disponibile qui: ne creo uno locale
if (!function_exists('pv_chip')) {
    function pv_chip(string $label, string $color = '#3b82f6'): string {
        return '<span style="display:inline-block;background:' . $color . '15;color:' . $color
             . ';padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;margin-right:5px;margin-bottom:4px;border:1px solid ' . $color . '40">'
             . h($label) . '</span>';
    }
}

function pv_field(string $label, $value, string $unit = ''): string
{
    if ($value === null || $value === '' || $value === 0) {
        $display = '<span style="color:#94a3b8;font-style:italic">—</span>';
    } else {
        $display = '<strong style="color:#0f172a">' . h((string)$value) . ($unit ? ' ' . h($unit) : '') . '</strong>';
    }
    return '<div style="margin-bottom:8px">'
         . '<div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px">' . h($label) . '</div>'
         . '<div style="font-size:13px">' . $display . '</div>'
         . '</div>';
}

$status_colors = [
    'active' => '#0ea5e9', 'completed' => '#16a34a', 'on_hold' => '#f59e0b',
    'cancelled' => '#dc2626', 'draft' => '#94a3b8',
];
$size_colors = [
    'PMI' => '#0ea5e9', 'Enterprise' => '#7c3aed', 'Core/Infrastruttura Datacenter' => '#dc2626',
];
?>

<style>
@media print {
    .no-print, .topbar, .sidebar, .menu, header, footer, .btn { display: none !important; }
    .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; page-break-inside: avoid; }
    body { padding: 0 !important; margin: 0 !important; background: white !important; }
}
.pv-card { background: #fff; border-radius: 8px; padding: 16px; margin-bottom: 14px; border: 1px solid #e2e8f0; }
.pv-card h3 { margin: 0 0 12px 0; font-size: 14px; color: #7c3aed; padding-bottom: 6px; border-bottom: 1px dashed #e2e8f0; }
</style>

<div style="margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px" class="no-print">
  <div>
    <h2 style="margin:0;color:#0f172a;font-size:22px">
      <i class="fa-solid fa-folder-open" style="color:#7c3aed"></i> Scheda Progetto
      <?php if ((int)$p['confidential'] === 1): ?>
      <i class="fa-solid fa-lock" style="color:#dc2626;font-size:14px;margin-left:6px" title="Confidenziale"></i>
      <?php endif; ?>
    </h2>
    <div style="font-size:12px;color:#64748b;margin-top:3px">
      <a href="<?= url_safe('projects') ?>" style="color:#64748b"><i class="fa-solid fa-arrow-left"></i> Torna alla lista</a>
      · ID #<?= (int)$p['id'] ?>
      · Creato il <?= h(date('d/m/Y H:i', strtotime($p['created_at']))) ?>
      <?php if ($p['created_by_name']): ?> da <strong><?= h($p['created_by_name']) ?></strong><?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($can_edit_proj): ?>
    <a href="<?= url_safe('project_form', ['id' => (int)$p['id']]) ?>" class="btn btn-primary" style="background:#3b82f6">
      <i class="fa-solid fa-pen"></i> Modifica
    </a>
    <?php endif; ?>
    <button onclick="window.print()" class="btn">
      <i class="fa-solid fa-print"></i> Stampa
    </button>
  </div>
</div>

<!-- ═════ INTESTAZIONE ═════ -->
<div class="pv-card" style="background:linear-gradient(135deg,#f8fafc 0%,#eff6ff 100%)">
  <div style="display:flex;justify-content:space-between;align-items:start;gap:14px;flex-wrap:wrap">
    <div style="flex:1;min-width:280px">
      <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">
        <?= h($p['service_type'] ?? 'PROGETTO') ?>
      </div>
      <h1 style="margin:0 0 8px 0;font-size:24px;color:#0f172a;line-height:1.2"><?= h($p['title']) ?></h1>
      <div style="font-size:13px;color:#475569">
        <i class="fa-solid fa-handshake" style="color:#7c3aed"></i>
        Cliente: <strong><?= h($p['client_name']) ?></strong>
        <?= pv_chip($p['client_size'], $size_colors[$p['client_size']] ?? '#64748b') ?>
      </div>
    </div>
    <div style="text-align:right">
      <?php
        $st = $p['status']; $col = $status_colors[$st] ?? '#64748b';
        if ((int)$p['is_in_progress'] === 1) { $st = 'IN CORSO'; $col = '#16a34a'; }
      ?>
      <div style="display:inline-block;padding:6px 14px;border-radius:20px;background:<?= $col ?>15;color:<?= $col ?>;font-weight:700;font-size:12px;border:2px solid <?= $col ?>40">
        <i class="fa-solid fa-circle" style="font-size:8px"></i> <?= h(strtoupper($st)) ?>
      </div>
      <?php if ($p['executor_name']): ?>
      <div style="margin-top:8px;font-size:11px;color:#64748b">Eseguito da</div>
      <div style="font-weight:700;color:#7c3aed">
        <i class="fa-solid fa-building"></i> <?= h($p['executor_name']) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═════ GRIGLIA 2 COLONNE ═════ -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">

  <!-- COL 1 -->
  <div>
    <?php if ($p['description']): ?>
    <div class="pv-card">
      <h3><i class="fa-solid fa-file-lines"></i> Descrizione attività</h3>
      <div style="font-size:13px;color:#475569;line-height:1.6;white-space:pre-wrap"><?= h($p['description']) ?></div>
    </div>
    <?php endif; ?>

    <div class="pv-card">
      <h3><i class="fa-solid fa-coins"></i> Dati commerciali</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
        <?= pv_field('Importo totale', $p['value_euro'] !== null ? '€ ' . number_format((float)$p['value_euro'], 2, ',', '.') : null) ?>
        <?= pv_field('Importo servizi', $p['amount_services'] !== null ? '€ ' . number_format((float)$p['amount_services'], 2, ',', '.') : null) ?>
        <?= pv_field('Importo HW/SW', $p['amount_hw_sw'] !== null ? '€ ' . number_format((float)$p['amount_hw_sw'], 2, ',', '.') : null) ?>
        <?= pv_field('Durata', $p['duration_text']) ?>
        <?= pv_field('Agente commerciale', $p['commercial_agent']) ?>
        <?= pv_field('Periodo (testuale)', $p['period_text']) ?>
      </div>
      <?php if ($p['tech_areas']): ?>
      <div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e2e8f0">
        <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px">AREE TECNOLOGICHE</div>
        <?php foreach (array_filter(array_map('trim', explode('|', $p['tech_areas']))) as $a):
          echo pv_chip($a, '#f59e0b');
        endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="pv-card">
      <h3><i class="fa-solid fa-calendar-days"></i> Periodo e tempi</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
        <?= pv_field('Data inizio', $p['date_start'] ? date('d/m/Y', strtotime($p['date_start'])) : null) ?>
        <?= pv_field('Data fine', $p['date_end'] ? date('d/m/Y', strtotime($p['date_end'])) : ((int)$p['is_in_progress'] === 1 ? 'in corso' : null)) ?>
        <?= pv_field('Stato', strtoupper($p['status'])) ?>
      </div>
    </div>

    <div class="pv-card">
      <h3><i class="fa-solid fa-users"></i> Dimensionamento</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <?= pv_field('Dipendenti coinvolti', $p['employees_involved']) ?>
        <?= pv_field('Utenti impattati', $p['users_impacted'] !== null ? number_format((int)$p['users_impacted'], 0, ',', '.') : null) ?>
      </div>
    </div>

    <?php if ($p['notes']): ?>
    <div class="pv-card" style="background:#fefce8;border:1px solid #fde68a">
      <h3 style="color:#a16207"><i class="fa-solid fa-note-sticky"></i> Note aggiuntive</h3>
      <div style="font-size:12px;color:#475569;line-height:1.5;white-space:pre-wrap"><?= h($p['notes']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- COL 2 -->
  <div>
    <div class="pv-card">
      <h3><i class="fa-solid fa-building" style="color:#0ea5e9"></i> Cliente</h3>
      <?= pv_field('Ragione sociale', $p['client_name']) ?>
      <?= pv_field('Dimensione', $p['client_size']) ?>
      <?= pv_field('Settore', $p['client_industry']) ?>
      <?= pv_field('Numero dipendenti', $p['client_employees'] !== null ? number_format((int)$p['client_employees'], 0, ',', '.') : null) ?>
      <?= pv_field('Numero utenti', $p['client_users'] !== null ? number_format((int)$p['client_users'], 0, ',', '.') : null) ?>
      <div style="margin-top:8px;font-size:11px">
        <a href="<?= url_safe('project_clients', ['edit' => (int)$p['client_id']]) ?>" style="color:#0ea5e9">
          <i class="fa-solid fa-arrow-up-right-from-square"></i> Apri scheda cliente
        </a>
      </div>
    </div>

    <div class="pv-card">
      <h3 style="color:#0ea5e9"><i class="fa-solid fa-tags"></i> Brand coinvolti (<?= count($brands) ?>)</h3>
      <?php if (empty($brands)): ?>
      <span style="color:#94a3b8;font-style:italic;font-size:12px">Nessun brand associato</span>
      <?php else: foreach ($brands as $b) echo pv_chip($b['name'], '#0ea5e9'); endif; ?>
    </div>

    <div class="pv-card">
      <h3 style="color:#16a34a"><i class="fa-solid fa-microchip"></i> Tecnologie (<?= count($techs) ?>)</h3>
      <?php if (empty($techs)): ?>
      <span style="color:#94a3b8;font-style:italic;font-size:12px">Nessuna tecnologia censita</span>
      <?php else: foreach ($techs as $t):
        $label = $t['brand_name'] . ' · ' . $t['name'] . ($t['version'] ? ' (' . $t['version'] . ')' : '');
        echo pv_chip($label, '#16a34a');
      endforeach; endif; ?>
    </div>

    <div class="pv-card">
      <h3 style="color:#dc2626"><i class="fa-solid fa-certificate"></i> Certificazioni (<?= count($certs) ?>)</h3>
      <?php if (empty($certs)): ?>
      <span style="color:#94a3b8;font-style:italic;font-size:12px">Nessuna certificazione richiesta</span>
      <?php else: foreach ($certs as $c):
        $label = $c['name'] . ($c['code'] ? ' (' . $c['code'] . ')' : '') . ((int)$c['required'] === 1 ? ' ⚑' : '');
        echo pv_chip($label, '#dc2626');
      endforeach; endif; ?>
    </div>
  </div>
</div>

<?php require_once('footer.php'); ?>
