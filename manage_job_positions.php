<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Gestione Posizioni Aperte (CRUD interno).
 * Permesso richiesto: manage_job_positions.php
 */
require __DIR__ . '/bootstrap.php';
require_login();
if (!can('manage_job_positions.php')) { http_response_code(403); exit('Accesso negato'); }
csrf_start();

$err = null; $ok = null;
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    try {
        if ($action === 'save') {
            $id     = (int)($_POST['id'] ?? 0);
            $title  = trim((string)($_POST['title'] ?? ''));
            $slug   = trim((string)($_POST['slug']  ?? '')) ?: pm_slugify($title);
            $status = in_array($_POST['status'] ?? '', ['draft','open','closed','archived'], true) ? $_POST['status'] : 'draft';
            $data = [
                'title'         => $title,
                'slug'          => $slug,
                'department'    => trim((string)($_POST['department'] ?? '')) ?: null,
                'location'      => trim((string)($_POST['location'] ?? '')) ?: null,
                'contract_type' => trim((string)($_POST['contract_type'] ?? '')) ?: null,
                'seniority'     => trim((string)($_POST['seniority'] ?? '')) ?: null,
                'description'   => trim((string)($_POST['description'] ?? '')) ?: null,
                'requirements'  => trim((string)($_POST['requirements'] ?? '')) ?: null,
                'benefits'      => trim((string)($_POST['benefits'] ?? '')) ?: null,
                'openings'      => max(1, (int)($_POST['openings'] ?? 1)),
                'status'        => $status,
                'published_at'  => $status === 'open' ? ($_POST['published_at'] ?: date('Y-m-d H:i:s')) : ($_POST['published_at'] ?: null),
                'expires_at'    => $_POST['expires_at'] ?: null,
                'owner_user_id' => (int)current_user_id(),
                'company_id'    => (int)($_POST['company_id'] ?? 0) ?: null,
            ];
            if ($title === '') throw new RuntimeException('Titolo obbligatorio');
            if ($id > 0) {
                $cols = implode(', ', array_map(fn($k)=>"$k=?", array_keys($data)));
                $stmt = $pdo->prepare("UPDATE job_positions SET $cols WHERE id = ?");
                $stmt->execute(array_merge(array_values($data), [$id]));
                write_log('job_positions', "aggiornata posizione #$id", ['id'=>$id]);
            } else {
                $cols = implode(',', array_keys($data));
                $ph   = implode(',', array_fill(0, count($data), '?'));
                $stmt = $pdo->prepare("INSERT INTO job_positions ($cols) VALUES ($ph)");
                $stmt->execute(array_values($data));
                $id = (int)$pdo->lastInsertId();
                write_log('job_positions', "creata posizione #$id", ['id'=>$id]);
            }
            $ok = "Posizione salvata (#$id).";
            header('Location: manage_job_positions.php?edit=' . $id . '&saved=1'); exit;
        }
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE job_positions SET status='archived' WHERE id = ?")->execute([$id]);
            write_log('job_positions', "archiviata posizione #$id", ['id'=>$id]);
            header('Location: manage_job_positions.php?archived=' . $id); exit;
        }
    } catch (\Throwable $e) { $err = $e->getMessage(); }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $s = $pdo->prepare("SELECT * FROM job_positions WHERE id = ?");
    $s->execute([$editId]);
    $edit = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
$positions = $pdo->query(
    "SELECT id, title, department, location, status, openings, published_at, expires_at,
            (SELECT COUNT(*) FROM job_applications a WHERE a.position_id = p.id) apps
     FROM job_positions p ORDER BY FIELD(status,'open','draft','closed','archived'), id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/header.php';
?>
<h1>Posizioni aperte</h1>
<?php if ($ok): ?><div class="alert ok"><?= h($ok) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert err"><?= h($err) ?></div><?php endif; ?>

<h2><?= $edit ? 'Modifica posizione #' . (int)$edit['id'] : 'Nuova posizione' ?></h2>
<form method="post" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="grid">
    <label>Titolo* <input name="title" required maxlength="200" value="<?= h($edit['title'] ?? '') ?>"></label>
    <label>Slug (auto) <input name="slug" maxlength="160" value="<?= h($edit['slug'] ?? '') ?>"></label>
    <label>Reparto <input name="department" value="<?= h($edit['department'] ?? '') ?>"></label>
    <label>Sede <input name="location" value="<?= h($edit['location'] ?? '') ?>"></label>
    <label>Tipo contratto <input name="contract_type" value="<?= h($edit['contract_type'] ?? '') ?>"></label>
    <label>Seniority <input name="seniority" value="<?= h($edit['seniority'] ?? '') ?>"></label>
    <label>Posti <input name="openings" type="number" min="1" value="<?= (int)($edit['openings'] ?? 1) ?>"></label>
    <label>Stato
      <select name="status">
        <?php foreach (['draft','open','closed','archived'] as $s): ?>
          <option value="<?= $s ?>" <?= ($edit['status'] ?? 'draft') === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Pubblicazione <input name="published_at" type="datetime-local" value="<?= h(pm_dt_local($edit['published_at'] ?? '')) ?>"></label>
    <label>Scadenza <input name="expires_at" type="datetime-local" value="<?= h(pm_dt_local($edit['expires_at'] ?? '')) ?>"></label>
    <label class="col-2">Descrizione <textarea name="description" rows="4"><?= h($edit['description'] ?? '') ?></textarea></label>
    <label class="col-2">Requisiti  <textarea name="requirements" rows="4"><?= h($edit['requirements'] ?? '') ?></textarea></label>
    <label class="col-2">Benefit    <textarea name="benefits"     rows="3"><?= h($edit['benefits'] ?? '') ?></textarea></label>
  </div>
  <div class="actions">
    <button type="submit">Salva</button>
    <?php if ($edit): ?><a class="btn" href="manage_job_positions.php">Nuova</a><?php endif; ?>
  </div>
</form>

<h2>Elenco posizioni</h2>
<table class="tbl">
  <thead><tr><th>#</th><th>Titolo</th><th>Reparto</th><th>Sede</th><th>Stato</th><th>Posti</th><th>Cand.</th><th>Pubbl.</th><th>Scad.</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($positions as $p): ?>
    <tr>
      <td><?= (int)$p['id'] ?></td>
      <td><a href="?edit=<?= (int)$p['id'] ?>"><?= h($p['title']) ?></a></td>
      <td><?= h($p['department']) ?></td>
      <td><?= h($p['location']) ?></td>
      <td><span class="badge b-<?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
      <td><?= (int)$p['openings'] ?></td>
      <td><a href="manage_applications.php?position_id=<?= (int)$p['id'] ?>"><?= (int)$p['apps'] ?></a></td>
      <td><?= h($p['published_at']) ?></td>
      <td><?= h($p['expires_at']) ?></td>
      <td>
        <form method="post" onsubmit="return confirm('Archiviare?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <button class="btn-mini danger">Archivia</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/partials/footer.php'; ?>
