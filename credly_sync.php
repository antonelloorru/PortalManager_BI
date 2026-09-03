<?php
/**
 * PortalManager 1.1.0 — credly_sync.php
 *
 * Pagina di gestione importazione/aggiornamento certificazioni da Credly.
 *
 * Funzionalità:
 *   1. Lista dipendenti con eventuale username Credly collegato (employee_credly_link)
 *   2. Form per collegare/aggiornare lo username Credly di un dipendente
 *   3. Bottone "Sincronizza ora" → invoca CredlyImporter::syncEmployee()
 *   4. Bottone "Sync tutti" → batch su tutti i collegamenti attivi
 *   5. Visualizza esito: imported/updated/unchanged/unmatched/errors + dettaglio
 *
 * Sicurezza:
 *   - Solo Super Admin (1) e HR Director (2) possono accedere
 *   - Solo Super Admin può fare "Sync tutti"
 *   - Rate limit 30s per batch
 */
require_once('access_control.php');
require_once __DIR__ . '/app/CredlyImporter.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if (!in_array($u_role, [1, 2], true)) {
    header('Location: ' . (function_exists('url') ? url('unauthorized') : 'unauthorized.php'));
    exit;
}

$msg     = '';
$detail  = null;

// ────────────────────────────────────────────────────────────────
// POST handlers
// ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ─── Link/unlink username Credly a dipendente ───
        if ($action === 'link') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $user  = trim($_POST['credly_username'] ?? '');
            $username = CredlyImporter::parseUsername($user);

            if (!$empId || !$username) {
                throw new RuntimeException('Dipendente e username Credly richiesti.');
            }

            $pdo->prepare(
                "INSERT INTO employee_credly_link (employee_id, credly_username, created_by, created_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE credly_username = VALUES(credly_username), updated_at = NOW()"
            )->execute([$empId, $username, $u_id]);

            if (function_exists('write_log')) {
                write_log('CredlyImport', 'success',
                    "Linkato Credly username=$username a employee_id=$empId", $u_id);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-link'></i> Collegamento Credly salvato per il dipendente.</div>";
        }

        // ─── Rimuovi collegamento ───
        elseif ($action === 'unlink') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $pdo->prepare("DELETE FROM employee_credly_link WHERE employee_id = ?")->execute([$empId]);
            $msg = "<div class='alert alert-info'><i class='fa-solid fa-link-slash'></i> Collegamento rimosso.</div>";
        }

        // ─── Sincronizza un singolo dipendente ───
        elseif ($action === 'sync_one') {
            $empId = (int)($_POST['employee_id'] ?? 0);
            $s = $pdo->prepare("SELECT credly_username FROM employee_credly_link WHERE employee_id = ?");
            $s->execute([$empId]);
            $username = $s->fetchColumn();
            if (!$username) throw new RuntimeException('Dipendente non collegato a Credly.');

            $importer = new CredlyImporter($pdo, $u_id);
            $detail   = $importer->syncEmployee($empId, $username);

            if (function_exists('write_log')) {
                write_log('CredlyImport', 'success',
                    sprintf('Sync emp=%d: %d imp, %d upd, %d unmatch, %d err',
                        $empId, $detail['imported'], $detail['updated'],
                        $detail['unmatched'], $detail['errors']),
                    $u_id);
            }

            $msg = sprintf(
                "<div class='alert alert-success'><i class='fa-solid fa-rotate'></i> Sincronizzazione completata: <strong>%d</strong> nuove · <strong>%d</strong> nuove + auto-catalogo · <strong>%d</strong> aggiornate · <strong>%d</strong> invariate · <strong>%d</strong> da mappare · <strong>%d</strong> errori.</div>",
                $detail['imported'], ($detail['created_cert'] ?? 0), $detail['updated'], $detail['unchanged'],
                $detail['unmatched'], $detail['errors']
            );
        }

        // ─── Batch su tutti (solo Super Admin) ───
        elseif ($action === 'sync_all') {
            if ($u_role !== 1) throw new RuntimeException('Operazione riservata al Super Admin.');

            // Rate limit
            $lockFile = __DIR__ . '/uploads/.ratelimit/credly_sync_all.lock';
            @mkdir(dirname($lockFile), 0775, true);
            if (is_file($lockFile) && (time() - filemtime($lockFile)) < 30) {
                throw new RuntimeException('Attendere 30 secondi tra batch successivi.');
            }
            @touch($lockFile);

            $links = $pdo->query(
                "SELECT l.employee_id, l.credly_username, e.first_name, e.last_name
                   FROM employee_credly_link l
                   JOIN employees e ON e.id = l.employee_id
                  WHERE e.status = 'active'
                  ORDER BY e.last_name, e.first_name"
            )->fetchAll(PDO::FETCH_ASSOC);

            $importer = new CredlyImporter($pdo, $u_id);
            $totals = ['imported'=>0,'updated'=>0,'unchanged'=>0,'unmatched'=>0,'errors'=>0,'employees'=>0];
            $perEmp = [];

            foreach ($links as $lk) {
                try {
                    $r = $importer->syncEmployee((int)$lk['employee_id'], $lk['credly_username']);
                    foreach (['imported','updated','unchanged','unmatched','errors','created_cert'] as $k) {
                        $totals[$k] = ($totals[$k] ?? 0) + ($r[$k] ?? 0);
                    }
                    $totals['employees']++;
                    $perEmp[] = [
                        'name'   => $lk['last_name'] . ' ' . $lk['first_name'],
                        'result' => $r,
                    ];
                } catch (Throwable $e) {
                    $totals['errors']++;
                    $perEmp[] = [
                        'name'  => $lk['last_name'] . ' ' . $lk['first_name'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
            $detail = ['batch' => true, 'totals' => $totals, 'per_emp' => $perEmp];
            $msg = sprintf(
                "<div class='alert alert-success'><i class='fa-solid fa-users-rotate'></i> Batch completato su <strong>%d</strong> dipendenti: <strong>%d</strong> nuove · <strong>%d</strong> aggiornate · <strong>%d</strong> da mappare · <strong>%d</strong> errori.</div>",
                $totals['employees'], $totals['imported'], $totals['updated'],
                $totals['unmatched'], $totals['errors']
            );
        }

    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " .
               htmlspecialchars($e->getMessage()) . "</div>";
        if (function_exists('write_log')) {
            write_log('CredlyImport', 'error', $e->getMessage(), $u_id);
        }
    }
}

// ────────────────────────────────────────────────────────────────
// Dati per la UI
// ────────────────────────────────────────────────────────────────
$rows = $pdo->query(
    "SELECT e.id AS employee_id,
            e.first_name, e.last_name,
            COALESCE(u.email, e.personal_email, '') AS email,
            e.employee_code,
            l.credly_username, l.last_sync_at,
            l.last_sync_imported, l.last_sync_updated, l.last_sync_unmatched,
            (SELECT COUNT(*) FROM user_certifications uc WHERE uc.employee_id = e.id) AS cert_count
       FROM employees e
       LEFT JOIN users u ON u.employee_id = e.id AND u.status = 'active'
       LEFT JOIN employee_credly_link l ON l.employee_id = e.id
      WHERE e.status = 'active'
      GROUP BY e.id
      ORDER BY (l.credly_username IS NULL), e.last_name, e.first_name"
)->fetchAll(PDO::FETCH_ASSOC);

$totalLinked = 0;
foreach ($rows as $r) if (!empty($r['credly_username'])) $totalLinked++;

require_once('header.php');
?>

<style>
.cr-card { background:#fff; border-radius:12px; box-shadow:0 2px 6px rgba(0,0,0,.06); padding:18px; margin-bottom:14px }
.cr-stat { display:inline-block; background:#eff6ff; color:#1e40af; padding:3px 10px; border-radius:6px; font-size:11px; font-weight:700; margin-right:6px }
.cr-stat.ok { background:#dcfce7; color:#166534 }
.cr-stat.warn { background:#fef3c7; color:#92400e }
.cr-stat.err { background:#fee2e2; color:#991b1b }
.cr-tbl { width:100%; border-collapse:collapse; font-size:13px }
.cr-tbl th { background:#f1f5f9; padding:10px; text-align:left; font-size:11px; text-transform:uppercase; color:#475569; font-weight:700 }
.cr-tbl td { padding:10px; border-bottom:1px solid #e2e8f0; vertical-align:middle }
.cr-tbl tr:hover td { background:#f8fafc }
.cr-link-pill { display:inline-block; background:#ede9fe; color:#5b21b6; padding:2px 8px; border-radius:5px; font-size:11px; font-weight:600; font-family:monospace }
.cr-no-link { color:#94a3b8; font-size:11px; font-style:italic }
.cr-btn-sm { padding:5px 10px; font-size:11px; border-radius:6px; border:0; cursor:pointer; font-weight:600 }
.cr-btn-sync { background:#0ea5e9; color:#fff }
.cr-btn-sync:hover { background:#0284c7 }
.cr-btn-link { background:#7c3aed; color:#fff }
.cr-btn-link:hover { background:#6d28d9 }
.cr-btn-del { background:#ef4444; color:#fff }
.cr-btn-del:hover { background:#dc2626 }
.cr-result { background:#f8fafc; border-radius:8px; padding:12px; margin-top:10px; font-size:12px; max-height:300px; overflow-y:auto }
.cr-result-row { padding:4px 0; border-bottom:1px solid #e2e8f0 }
.cr-result-row:last-child { border-bottom:0 }
</style>

<div style="max-width:1200px;margin:0 auto">

  <div style="margin-bottom:18px">
    <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
      <i class="fa-solid fa-shield-halved" style="color:#7c3aed"></i> Integrazione Credly
    </h1>
    <div style="color:var(--muted);font-size:13px">
      Importa e mantieni aggiornate le certificazioni dei dipendenti dal loro profilo pubblico Credly.
    </div>
  </div>

  <?= $msg ?>

  <!-- ═══ Statistiche e batch ═══ -->
  <div class="cr-card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
    <div>
      <span class="cr-stat"><?= count($rows) ?> dipendenti attivi</span>
      <span class="cr-stat ok"><?= $totalLinked ?> collegati a Credly</span>
      <?php if (count($rows) - $totalLinked > 0): ?>
        <span class="cr-stat warn"><?= count($rows) - $totalLinked ?> da collegare</span>
      <?php endif; ?>
    </div>
    <?php if ($u_role === 1 && $totalLinked > 0): ?>
    <form method="POST" onsubmit="return confirm('Sincronizzare tutti i <?= $totalLinked ?> dipendenti collegati? Operazione potenzialmente lunga.');" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync_all">
      <button type="submit" class="btn btn-primary" style="padding:9px 18px">
        <i class="fa-solid fa-users-rotate"></i> Sincronizza tutti
      </button>
    </form>
    <?php endif; ?>
  </div>

  <!-- ═══ Dettaglio esito ultima operazione ═══ -->
  <?php if ($detail && !empty($detail['detail']) && !isset($detail['batch'])): ?>
  <div class="cr-card">
    <h3 style="font-size:14px;font-weight:800;margin-bottom:10px">Dettaglio sincronizzazione</h3>
    <div class="cr-result">
      <?php foreach ($detail['detail'] as $d):
        $color = ['imported'=>'#166534','created_cert'=>'#7c3aed','updated'=>'#1e40af','unchanged'=>'#64748b','unmatched'=>'#92400e','error'=>'#991b1b'][$d['result']] ?? '#475569';
      ?>
        <div class="cr-result-row">
          <span style="color:<?= $color ?>;font-weight:700;text-transform:uppercase;font-size:10px;display:inline-block;width:90px"><?= h($d['result']) ?></span>
          <?= h($d['badge']) ?>
          <?php if (!empty($d['note'])): ?>
            <span style="color:#991b1b;font-size:11px"> &mdash; <?= h($d['note']) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($detail && !empty($detail['batch'])): ?>
  <div class="cr-card">
    <h3 style="font-size:14px;font-weight:800;margin-bottom:10px">Esito batch per dipendente</h3>
    <div class="cr-result">
      <?php foreach ($detail['per_emp'] as $d): ?>
        <div class="cr-result-row">
          <strong><?= h($d['name']) ?></strong> &mdash;
          <?php if (!empty($d['error'])): ?>
            <span style="color:#991b1b">ERRORE: <?= h($d['error']) ?></span>
          <?php else:
            $r = $d['result']; ?>
            <span class="cr-stat ok"><?= $r['imported'] ?> nuove</span>
            <span class="cr-stat"><?= $r['updated'] ?> upd</span>
            <span class="cr-stat warn"><?= $r['unmatched'] ?> da mappare</span>
            <?php if ($r['errors']): ?><span class="cr-stat err"><?= $r['errors'] ?> err</span><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══ Tabella dipendenti ═══ -->
  <div class="cr-card" style="padding:0;overflow:hidden">
    <table class="cr-tbl">
      <thead>
        <tr>
          <th>Dipendente</th>
          <th>Username Credly</th>
          <th>Ultima sync</th>
          <th>Cert totali</th>
          <th style="text-align:right">Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <strong><?= h($r['last_name'] . ' ' . $r['first_name']) ?></strong>
            <div style="font-size:11px;color:#94a3b8"><?= h($r['email']) ?></div>
          </td>
          <td>
            <?php if (!empty($r['credly_username'])): ?>
              <a href="https://www.credly.com/users/<?= h($r['credly_username']) ?>/badges" target="_blank" class="cr-link-pill" title="Apri profilo Credly">
                <i class="fa-solid fa-external-link-alt" style="font-size:9px"></i>
                <?= h($r['credly_username']) ?>
              </a>
            <?php else: ?>
              <span class="cr-no-link">— non collegato —</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($r['last_sync_at'])): ?>
              <div style="font-size:11px"><?= date('d/m/Y H:i', strtotime($r['last_sync_at'])) ?></div>
              <div style="font-size:10px;color:#64748b">
                +<?= (int)$r['last_sync_imported'] ?> nuove
                · &Delta;<?= (int)$r['last_sync_updated'] ?>
                <?php if ((int)$r['last_sync_unmatched'] > 0): ?>
                  · <span style="color:#92400e">⚠ <?= (int)$r['last_sync_unmatched'] ?> da mappare</span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span class="cr-no-link">mai</span>
            <?php endif; ?>
          </td>
          <td><strong><?= (int)$r['cert_count'] ?></strong></td>
          <td style="text-align:right;white-space:nowrap">
            <?php if (!empty($r['credly_username'])): ?>
              <form method="POST" style="display:inline;margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sync_one">
                <input type="hidden" name="employee_id" value="<?= (int)$r['employee_id'] ?>">
                <button type="submit" class="cr-btn-sm cr-btn-sync" title="Sincronizza ora">
                  <i class="fa-solid fa-rotate"></i> Sync
                </button>
              </form>
              <form method="POST" style="display:inline;margin:0" onsubmit="return confirm('Scollegare l\'username Credly da questo dipendente? Le certificazioni già importate restano.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unlink">
                <input type="hidden" name="employee_id" value="<?= (int)$r['employee_id'] ?>">
                <button type="submit" class="cr-btn-sm cr-btn-del" title="Scollega">
                  <i class="fa-solid fa-link-slash"></i>
                </button>
              </form>
            <?php else: ?>
              <button type="button" class="cr-btn-sm cr-btn-link"
                      onclick="openLink(<?= (int)$r['employee_id'] ?>, '<?= h($r['last_name'] . ' ' . $r['first_name']) ?>')">
                <i class="fa-solid fa-link"></i> Collega
              </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ═══ Modal link username ═══ -->
  <div id="linkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;max-width:500px;width:90%;padding:24px">
      <h3 style="font-size:16px;font-weight:800;margin-bottom:10px">
        <i class="fa-solid fa-link" style="color:#7c3aed"></i> Collega profilo Credly
      </h3>
      <div style="font-size:12px;color:#64748b;margin-bottom:14px">
        Dipendente: <strong id="linkEmpName"></strong>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="link">
        <input type="hidden" name="employee_id" id="linkEmpId">

        <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:4px">URL profilo Credly o username</label>
        <input type="text" name="credly_username" required
               placeholder="es. https://www.credly.com/users/lorenzo-buschi/badges"
               style="width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px">
        <div style="font-size:11px;color:#64748b;margin-top:6px">
          Accetta URL completo (anche con <code>/badges</code>) o solo lo username/UUID Credly.
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
          <button type="button" onclick="closeLink()" class="btn">Annulla</button>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-link"></i> Salva collegamento</button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
function openLink(empId, name) {
    document.getElementById('linkEmpId').value = empId;
    document.getElementById('linkEmpName').textContent = name;
    const m = document.getElementById('linkModal');
    m.style.display = 'flex';
}
function closeLink() {
    document.getElementById('linkModal').style.display = 'none';
}
document.getElementById('linkModal').addEventListener('click', e => {
    if (e.target.id === 'linkModal') closeLink();
});
</script>

<?php require_once('footer.php'); ?>
