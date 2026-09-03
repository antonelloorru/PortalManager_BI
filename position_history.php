<?php
/**
 * certV 5.00.00 — position_history.php
 * Mostra la timeline storica di una posizione: cambi stato + variazioni compenso + storia template usati.
 *
 * Parametri GET:
 *   - id: ID della posizione (obbligatorio)
 */
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/app/PositionHistory.php';

$position_id = (int)($_GET['id'] ?? 0);
if ($position_id <= 0) {
    redirect('recruiting_posizioni');
}

if (!can('view', 'position_history.php') && (int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    redirect('unauthorized');
}

// Carica posizione + brand
$stmt = $pdo->prepare(
    "SELECT p.*, b.name AS brand_name
       FROM job_positions p
       LEFT JOIN brands b ON b.id = p.brand_id
      WHERE p.id = ?"
);
$stmt->execute([$position_id]);
$position = $stmt->fetch();

if (!$position) {
    redirect('recruiting_posizioni');
}

// Storico
$status_timeline = PositionHistory::getStatusTimeline($pdo, $position_id);
$compensation_timeline = PositionHistory::getCompensationTimeline($pdo, $position_id);
$stats = PositionHistory::computeStatistics($pdo, $position_id);

$status_label = [
    'draft'     => 'Bozza',     'open'   => 'Aperta',
    'paused'    => 'In pausa',  'closed' => 'Chiusa',
    'cancelled' => 'Annullata',
];
$status_color = [
    'draft'     => '#64748b',   'open'   => '#10b981',
    'paused'    => '#f59e0b',   'closed' => '#0ea5e9',
    'cancelled' => '#ef4444',
];

require_once __DIR__ . '/header.php';
?>

<style>
.tl-container{max-width:1000px;margin:0 auto}
.tl-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:16px}
.tl-head h1{font-size:22px;font-weight:800;margin-bottom:4px}
.tl-head .meta{color:var(--muted);font-size:13px}

.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.stat{background:#fff;padding:14px 16px;border-radius:10px;border-left:4px solid var(--p)}
.stat .lbl{font-size:10px;text-transform:uppercase;font-weight:700;color:var(--muted);letter-spacing:.5px}
.stat .val{font-size:22px;font-weight:800;margin-top:4px;color:#1e293b}
.stat .sub{font-size:11px;color:var(--muted);margin-top:2px}

.timeline{position:relative;padding-left:32px;margin-bottom:32px}
.timeline::before{content:'';position:absolute;left:11px;top:0;bottom:0;width:2px;background:var(--border)}
.tl-item{position:relative;margin-bottom:18px;padding:14px 18px;background:#fff;border-radius:10px;border:1px solid var(--border)}
.tl-item::before{content:'';position:absolute;left:-26px;top:18px;width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid var(--p);box-shadow:0 0 0 3px #f1f5f9}
.tl-item.status-draft::before{border-color:#64748b}
.tl-item.status-open::before{border-color:#10b981}
.tl-item.status-paused::before{border-color:#f59e0b}
.tl-item.status-closed::before{border-color:#0ea5e9}
.tl-item.status-cancelled::before{border-color:#ef4444}
.tl-item.compensation::before{border-color:#8b5cf6}

.tl-row1{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:8px}
.tl-row1 .change-label{font-size:13px;font-weight:700;color:#1e293b}
.tl-row1 .when{font-size:11px;color:var(--muted)}
.tl-row2{font-size:12px;color:var(--muted);margin-bottom:8px}
.tl-row3{font-size:12px;color:#475569;line-height:1.5}

.transition{display:inline-flex;align-items:center;gap:6px;font-size:12px}
.transition .pill{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;color:#fff}
.transition .arrow{color:var(--muted);font-size:14px}

.comp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:12px}
.comp-grid .lbl{color:var(--muted);font-size:10px;text-transform:uppercase;font-weight:700}
.comp-grid .val{color:#1e293b;font-weight:600;margin-top:2px}

.section-header{display:flex;align-items:center;gap:10px;margin:24px 0 14px;padding-bottom:8px;border-bottom:2px solid var(--border)}
.section-header h2{font-size:15px;font-weight:700;color:#1e293b}
.section-header .count{background:var(--p);color:#fff;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700}

.empty{padding:40px;text-align:center;color:var(--muted);font-size:13px}

@media (max-width:768px){
  .stats-grid{grid-template-columns:1fr 1fr}
  .comp-grid{grid-template-columns:1fr}
}
</style>

<div class="tl-container">
  <div class="tl-head">
    <div>
      <h1>📅 Storico posizione</h1>
      <div class="meta">
        <strong><?= h($position['title']) ?></strong>
        <?php if ($position['brand_name']): ?>· <?= h($position['brand_name']) ?><?php endif; ?>
        · ID #<?= (int)$position['id'] ?>
      </div>
    </div>
    <div>
      <a href="<?= url_safe('recruiting_posizioni') ?>" class="btn">← Posizioni</a>
    </div>
  </div>

  <!-- Statistiche -->
  <?php if (!empty($stats)): ?>
  <div class="stats-grid">
    <div class="stat">
      <div class="lbl">Totale cambi</div>
      <div class="val"><?= (int)($stats['total_changes'] ?? 0) ?></div>
      <div class="sub">stati registrati</div>
    </div>
    <div class="stat" style="border-left-color:#10b981">
      <div class="lbl">Prima apertura</div>
      <div class="val" style="font-size:14px"><?= $stats['first_opened'] ? date('d/m/Y', strtotime($stats['first_opened'])) : '—' ?></div>
      <div class="sub"><?= $stats['first_opened'] ? date('H:i', strtotime($stats['first_opened'])) : '' ?></div>
    </div>
    <div class="stat" style="border-left-color:#0ea5e9">
      <div class="lbl">Chiusura</div>
      <div class="val" style="font-size:14px"><?= $stats['last_closed'] ? date('d/m/Y', strtotime($stats['last_closed'])) : 'Aperta' ?></div>
      <div class="sub"><?= $stats['last_closed'] ? date('H:i', strtotime($stats['last_closed'])) : '' ?></div>
    </div>
    <div class="stat" style="border-left-color:#f59e0b">
      <div class="lbl">Riaperture</div>
      <div class="val"><?= (int)($stats['reopens_count'] ?? 0) ?></div>
      <div class="sub">paused → open</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Timeline stati -->
  <div class="section-header">
    <h2>Cambi di stato</h2>
    <span class="count"><?= count($status_timeline) ?></span>
  </div>

  <?php if (empty($status_timeline)): ?>
    <div class="empty">Nessuno storico stato disponibile.</div>
  <?php else: ?>
    <div class="timeline">
      <?php foreach (array_reverse($status_timeline) as $h): ?>
        <div class="tl-item status-<?= h($h['new_status']) ?>">
          <div class="tl-row1">
            <div class="transition">
              <?php if ($h['old_status']): ?>
                <span class="pill" style="background:<?= h($status_color[$h['old_status']]) ?>"><?= h($status_label[$h['old_status']]) ?></span>
                <span class="arrow">→</span>
              <?php endif; ?>
              <span class="pill" style="background:<?= h($status_color[$h['new_status']]) ?>"><?= h($status_label[$h['new_status']]) ?></span>
            </div>
            <div class="when"><?= date('d/m/Y H:i', strtotime($h['changed_at'])) ?></div>
          </div>

          <div class="tl-row2">
            <?php if ($h['changed_by_name']): ?>
              da <strong><?= h(trim($h['changed_by_name'])) ?></strong>
            <?php else: ?>
              da <em>(utente sconosciuto)</em>
            <?php endif; ?>
            <?php if ($h['opened_at_snapshot']): ?>
              · apertura: <?= date('d/m/Y', strtotime($h['opened_at_snapshot'])) ?>
            <?php endif; ?>
            <?php if ($h['closed_at_snapshot']): ?>
              · chiusura: <?= date('d/m/Y', strtotime($h['closed_at_snapshot'])) ?>
            <?php endif; ?>
          </div>

          <?php if (!empty($h['notes'])): ?>
            <div class="tl-row3">📝 <?= h($h['notes']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Timeline compenso -->
  <div class="section-header">
    <h2>Variazioni compenso</h2>
    <span class="count"><?= count($compensation_timeline) ?></span>
  </div>

  <?php if (empty($compensation_timeline)): ?>
    <div class="empty">Nessuna variazione di compenso registrata.</div>
  <?php else: ?>
    <div class="timeline">
      <?php foreach (array_reverse($compensation_timeline) as $h): ?>
        <div class="tl-item compensation">
          <div class="tl-row1">
            <div class="change-label">💰 Modifica compenso</div>
            <div class="when"><?= date('d/m/Y H:i', strtotime($h['changed_at'])) ?></div>
          </div>

          <div class="tl-row2">
            <?php if ($h['changed_by_name']): ?>
              da <strong><?= h(trim($h['changed_by_name'])) ?></strong>
            <?php else: ?>
              da <em>(utente sconosciuto)</em>
            <?php endif; ?>
          </div>

          <div class="comp-grid" style="margin-top:8px">
            <div>
              <div class="lbl">RAL min</div>
              <div class="val"><?= $h['ral_min'] !== null ? '€ ' . number_format((float)$h['ral_min'], 0, ',', '.') : '—' ?></div>
            </div>
            <div>
              <div class="lbl">RAL max</div>
              <div class="val"><?= $h['ral_max'] !== null ? '€ ' . number_format((float)$h['ral_max'], 0, ',', '.') : '—' ?></div>
            </div>
            <div>
              <div class="lbl">Benefits</div>
              <div class="val" style="font-weight:400;font-size:11px">
                <?= $h['benefits'] ? nl2br(h(mb_strimwidth($h['benefits'], 0, 80, '…'))) : '—' ?>
              </div>
            </div>
          </div>

          <?php if (!empty($h['notes'])): ?>
            <div class="tl-row3" style="margin-top:8px">📝 <?= h($h['notes']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/footer.php'; ?>
