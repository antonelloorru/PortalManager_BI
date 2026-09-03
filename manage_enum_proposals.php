<?php
/**
 * certV 5.8.0 — manage_enum_proposals.php
 *
 * Gestione proposte di estensione ENUM raccolte durante gli import.
 * Casi d'uso tipici:
 *   - CSV catalogo certificazioni con livello "Senior" (non in enum)
 *     → la proposta appare qui; l'admin la approva (estende ENUM) o
 *       la mappa a un livello esistente.
 *   - Dopo decisione, le righe LDB pendenti vengono auto-completate
 *     con il valore canonico.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/EnumExtender.php';
require_once __DIR__ . '/app/ImportProcessor.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role > 2) { header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php')); exit(); }

$ext = new EnumExtender($pdo);

// ─── HANDLE POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($u_role > 1) throw new RuntimeException('Solo Super Admin può prendere decisioni sulle proposte.');
        $pid = (int)($_POST['proposal_id'] ?? 0);
        if ($pid <= 0) throw new RuntimeException('Proposta non valida');

        if ($action === 'approve') {
            $r = $ext->approveProposal($pid, $u_id);
            // Auto-applica la decisione alle righe LDB pendenti
            $applied = (new ImportProcessor($pdo, 'catalogo'))->applyEnumDecision($pid, $u_id);
            $msg = "<i class='fa-solid fa-check'></i> ENUM esteso";
            if (!empty($r['enum'])) $msg .= " (ora: " . implode(', ', $r['enum']) . ")";
            if ($applied['updated'] > 0) $msg .= ". Completate <strong>{$applied['updated']}</strong> righe LDB pendenti.";
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>$msg</div>";
        }
        elseif ($action === 'map') {
            $mappedTo = trim((string)($_POST['mapped_to'] ?? ''));
            if ($mappedTo === '') throw new RuntimeException('Valore di mappatura obbligatorio');
            $ext->mapProposal($pid, $mappedTo, $u_id);
            $applied = (new ImportProcessor($pdo, 'catalogo'))->applyEnumDecision($pid, $u_id);
            $msg = "<i class='fa-solid fa-check'></i> Proposta mappata a <strong>" . h($mappedTo) . "</strong>";
            if ($applied['updated'] > 0) $msg .= ". Completate <strong>{$applied['updated']}</strong> righe LDB.";
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>$msg</div>";
        }
        elseif ($action === 'reject') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            $ext->rejectProposal($pid, $u_id, $reason);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Proposta rifiutata.</div>";
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
    $qs = !empty($_GET['r']) ? '?r=' . urlencode($_GET['r']) : '';
    header('Location: manage_enum_proposals.php' . $qs);
    exit();
}

// ─── DATA ───────────────────────────────────────────────────────────────
$f_status = $_GET['f_status'] ?? 'pending';
$f_table  = $_GET['f_table'] ?? '';
$proposals = $ext->listProposals($f_status !== 'all' ? $f_status : null,
                                  $f_table !== '' ? $f_table : null);
$targets = EnumExtender::getWhitelistedTargets();

// Conta per badge
$cnt = $pdo->query("SELECT status, COUNT(*) AS n FROM enum_proposals GROUP BY status")->fetchAll();
$counts = ['pending'=>0,'approved'=>0,'mapped'=>0,'rejected'=>0];
foreach ($cnt as $c) $counts[$c['status']] = (int)$c['n'];

require_once('header.php');
?>

<style>
.prop-card { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.04); margin-bottom:12px; overflow:hidden; }
.prop-card.pending { border-left:4px solid #f59e0b; }
.prop-card.approved { border-left:4px solid #10b981; opacity:.8; }
.prop-card.mapped { border-left:4px solid #0ea5e9; opacity:.85; }
.prop-card.rejected { border-left:4px solid #94a3b8; opacity:.6; }
.prop-h { padding:12px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; background:#f8fafc; }
.prop-b { padding:14px 16px; }
.prop-target { font-family:monospace; font-size:11px; background:#1e293b; color:#fff; padding:3px 8px; border-radius:5px; }
.prop-value { background:#fef3c7; color:#92400e; padding:4px 12px; border-radius:6px; font-weight:800; font-size:14px; font-family:monospace; }
.prop-occ { background:#e0f2fe; color:#075985; padding:2px 8px; border-radius:6px; font-size:10px; font-weight:700; }
.status-pill { padding:2px 9px; border-radius:8px; font-size:10px; font-weight:800; text-transform:uppercase; color:#fff; }
.sp-pending { background:#f59e0b; }
.sp-approved { background:#10b981; }
.sp-mapped { background:#0ea5e9; }
.sp-rejected { background:#94a3b8; }
.action-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; padding-top:8px; border-top:1px dashed var(--border); margin-top:8px; }
.enum-current { background:#f0fdf4; color:#166534; padding:6px 10px; border-radius:6px; font-size:11px; font-family:monospace; }
</style>

<div style="max-width:1200px;margin:0 auto">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-list-check" style="color:#f59e0b"></i> Estensioni ENUM da approvare
      </h1>
      <div style="color:var(--muted);font-size:13px">
        Valori non censiti incontrati durante gli import (livelli, tipologie). Approva o mappa a valori esistenti.
      </div>
    </div>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?><?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?><?php endif; ?>

  <!-- BADGE COUNT -->
  <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
    <?php
      $badges = [
        'pending'  => ['In attesa', '#f59e0b'],
        'approved' => ['Approvate (enum esteso)', '#10b981'],
        'mapped'   => ['Mappate a esistente', '#0ea5e9'],
        'rejected' => ['Rifiutate', '#94a3b8'],
      ];
      foreach ($badges as $k => [$lbl, $col]):
    ?>
      <a href="?f_status=<?=$k?><?= !empty($_GET['r']) ? '&r='.urlencode($_GET['r']) : '' ?>"
         style="background:<?=$col?>;color:#fff;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;<?= $f_status===$k?'box-shadow:0 0 0 3px rgba(0,0,0,.1)':'opacity:.7' ?>">
        <?=$lbl?>: <?=$counts[$k]?>
      </a>
    <?php endforeach; ?>
    <a href="?f_status=all<?= !empty($_GET['r']) ? '&r='.urlencode($_GET['r']) : '' ?>"
       style="background:#475569;color:#fff;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;<?= $f_status==='all'?'box-shadow:0 0 0 3px rgba(0,0,0,.1)':'opacity:.7' ?>">
      Tutte: <?= array_sum($counts) ?>
    </a>
  </div>

  <!-- FILTRO TARGET -->
  <form method="GET" class="filter-bar" style="margin-bottom:14px">
    <?php if (!empty($_GET['r'])): ?><input type="hidden" name="r" value="<?= h($_GET['r']) ?>"><?php endif; ?>
    <input type="hidden" name="f_status" value="<?= h($f_status) ?>">
    <div class="fg">
      <label>Campo target</label>
      <select name="f_table" onchange="this.form.submit()">
        <option value="">Tutti</option>
        <?php foreach ($targets as $tk => $tinfo):
          [$tt, $tc] = explode('.', $tk, 2);
        ?>
          <option value="<?= h($tt) ?>" <?= $f_table===$tt?'selected':'' ?>><?= h($tinfo['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <!-- LISTA -->
  <?php if (empty($proposals)): ?>
    <div style="background:#fff;padding:50px 30px;border-radius:12px;text-align:center;color:var(--muted)">
      <i class="fa-solid fa-circle-check" style="font-size:42px;color:#10b981;margin-bottom:12px"></i>
      <h3 style="margin:0 0 6px">Nessuna proposta da gestire</h3>
      <p style="font-size:13px;margin:0">Tutte le proposte sono state risolte. Le nuove arriveranno qui dopo gli import.</p>
    </div>
  <?php else: ?>
    <?php foreach ($proposals as $p):
      $target = $p['target_table'] . '.' . $p['target_column'];
      $tinfo = $targets[$target] ?? ['label' => $target, 'description' => ''];
      try { $current_enum = $ext->getEnumValues($p['target_table'], $p['target_column']); }
      catch (Throwable $e) { $current_enum = []; }
    ?>
      <div class="prop-card <?= h($p['status']) ?>">
        <div class="prop-h">
          <span class="prop-target"><?= h($target) ?></span>
          <span class="prop-value"><?= h($p['proposed_value']) ?></span>
          <span class="prop-occ"><i class="fa-solid fa-arrow-trend-up"></i> <?= (int)$p['occurrences'] ?> occorrenze</span>
          <span class="status-pill sp-<?= h($p['status']) ?>"><?= h($p['status']) ?></span>
          <?php if ($p['status'] === 'mapped' && $p['mapped_to']): ?>
            <span style="font-size:11px;color:#0ea5e9;font-weight:700">→ <?= h($p['mapped_to']) ?></span>
          <?php endif; ?>
          <span style="margin-left:auto;font-size:11px;color:var(--muted)">
            Prima vista <?= date('d/m/Y H:i', strtotime($p['first_seen_at'])) ?>
            <?php if ($p['first_seen_at'] !== $p['last_seen_at']): ?>
              · ultima <?= date('d/m H:i', strtotime($p['last_seen_at'])) ?>
            <?php endif; ?>
          </span>
        </div>
        <div class="prop-b">
          <div style="font-size:13px;color:var(--muted);margin-bottom:6px"><?= h($tinfo['description']) ?></div>
          <?php if (!empty($current_enum)): ?>
            <div style="margin-bottom:8px">
              <span style="font-size:11px;color:var(--muted);font-weight:700;text-transform:uppercase">Valori attuali:</span>
              <span class="enum-current"><?= h(implode(', ', $current_enum)) ?></span>
            </div>
          <?php endif; ?>

          <?php if ($p['status'] === 'pending' && $u_role === 1): ?>
            <div class="action-row">
              <!-- Approva: estende l'enum -->
              <form method="POST" onsubmit="return confirm('Estendere l\\'ENUM aggiungendo \\'<?= h($p['proposed_value']) ?>\\'?\\n\\nVerrà eseguito ALTER TABLE su <?= h($target) ?>.\\nLe righe LDB pendenti con questo valore verranno completate automaticamente.')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:#10b981;color:#fff;border-color:#10b981">
                  <i class="fa-solid fa-check"></i> Approva (estendi ENUM)
                </button>
              </form>

              <!-- Mappa: converte a valore esistente -->
              <?php if (!empty($current_enum)): ?>
                <form method="POST" style="display:flex;gap:6px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="map">
                  <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
                  <select name="mapped_to" required style="font-size:12px;padding:4px 8px">
                    <option value="">Mappa a…</option>
                    <?php foreach ($current_enum as $v): ?>
                      <option value="<?= h($v) ?>"><?= h($v) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm" style="background:#0ea5e9;color:#fff;border-color:#0ea5e9">
                    <i class="fa-solid fa-arrow-right-arrow-left"></i> Mappa
                  </button>
                </form>
              <?php endif; ?>

              <!-- Rifiuta -->
              <form method="POST" style="display:flex;gap:6px;margin-left:auto" onsubmit="return confirm('Rifiutare la proposta?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="proposal_id" value="<?= (int)$p['id'] ?>">
                <input type="text" name="reason" placeholder="Motivo (opzionale)" maxlength="200" style="font-size:11px;padding:4px 8px;width:160px">
                <button type="submit" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5">
                  <i class="fa-solid fa-ban"></i> Rifiuta
                </button>
              </form>
            </div>
          <?php elseif ($p['status'] !== 'pending'): ?>
            <div style="font-size:11px;color:var(--muted);padding-top:6px;border-top:1px dashed var(--border)">
              Decisione presa <?= $p['decided_at'] ? 'il ' . date('d/m/Y H:i', strtotime($p['decided_at'])) : '' ?>
              <?= !empty($p['user_name']) ? 'da <strong>' . h(trim($p['user_name'])) . '</strong>' : '' ?>
              <?= !empty($p['notes']) ? ' — ' . h($p['notes']) : '' ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div style="background:#eff6ff;border-left:4px solid #0ea5e9;padding:12px 16px;border-radius:8px;margin-top:18px;font-size:12px;color:#1e40af">
    <strong><i class="fa-solid fa-circle-info"></i> Come funziona:</strong> Durante un import, se il CSV contiene un valore non presente nell'ENUM (es. <code>level = "Senior"</code>), la riga viene marcata <strong>parziale</strong> e il valore registrato qui come proposta. Tu decidi se:
    <ul style="margin:6px 0 0 18px;line-height:1.7">
      <li><strong>Approvare</strong>: estende l'ENUM (ALTER TABLE) per accogliere il nuovo valore.</li>
      <li><strong>Mappare</strong>: converte le occorrenze a un valore già esistente (es. "Senior" → "Professional").</li>
      <li><strong>Rifiutare</strong>: ignora la proposta. Le righe LDB restano incomplete e dovranno essere completate manualmente.</li>
    </ul>
    Dopo Approva o Mappa, le righe LDB pendenti con quella proposta vengono completate automaticamente.
  </div>
</div>

<?php require_once('footer.php'); ?>
