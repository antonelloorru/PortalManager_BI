<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Gestione Candidature (kanban/tabella + dettaglio).
 * Permesso richiesto: manage_applications.php
 */
require __DIR__ . '/bootstrap.php';
require_login();
if (!can('manage_applications.php')) { http_response_code(403); exit('Accesso negato'); }
csrf_start();

$positionId = (int)($_GET['position_id'] ?? 0);
$status     = (string)($_GET['status'] ?? '');
$appId      = (int)($_GET['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);
    if ($act === 'update_status' && $id > 0) {
        $ns = in_array($_POST['status'] ?? '', ['new','screening','interview','offer','hired','rejected','withdrawn'], true) ? $_POST['status'] : 'new';
        $pdo->prepare("UPDATE job_applications SET status=?, notes=CONCAT(COALESCE(notes,''), ?, '\n'), assigned_to=? WHERE id=?")
            ->execute([$ns, '[' . date('Y-m-d H:i') . '] ' . current_user_display() . ' → ' . $ns, current_user_id(), $id]);
        write_log('applications', "candidatura #$id → $ns", ['id'=>$id,'status'=>$ns]);
        if ($ns === 'hired') {
            $stmt = $pdo->prepare(
                "SELECT c.* FROM candidates c JOIN job_applications a ON a.candidate_id = c.id WHERE a.id = ?"
            );
            $stmt->execute([$id]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($c && empty($c['linked_employee_id'])) {
                $ins = $pdo->prepare(
                    "INSERT INTO employees (first_name,last_name,email,phone,fiscal_code,birth_date,created_at)
                     VALUES (?,?,?,?,?,?,NOW())"
                );
                $ins->execute([$c['first_name'],$c['last_name'],$c['email'],$c['phone'],$c['fiscal_code'],$c['birth_date']]);
                $eid = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE candidates SET linked_employee_id=? WHERE id=?")->execute([$eid, (int)$c['id']]);
            }
        }
        header('Location: manage_applications.php?id=' . $id); exit;
    }
    if ($act === 'rate' && $id > 0) {
        $r = max(1, min(5, (int)$_POST['rating']));
        $pdo->prepare("UPDATE job_applications SET rating=? WHERE id=?")->execute([$r, $id]);
        header('Location: manage_applications.php?id=' . $id); exit;
    }
}

if ($appId > 0) {
    $stmt = $pdo->prepare(
        "SELECT a.*, c.first_name, c.last_name, c.email, c.phone, c.birth_date, c.city, c.country, c.linkedin_url,
                p.title AS position_title, cv.original_name cv_name, cv.stored_name cv_stored, cv.mime_type cv_mime
         FROM job_applications a
         JOIN candidates    c ON c.id = a.candidate_id
         JOIN job_positions p ON p.id = a.position_id
         LEFT JOIN candidate_cv_files cv ON cv.id = a.cv_file_id
         WHERE a.id = ?"
    );
    $stmt->execute([$appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
}

$where = []; $bind = [];
if ($positionId > 0) { $where[] = 'a.position_id = ?'; $bind[] = $positionId; }
if ($status !== '')  { $where[] = 'a.status = ?';       $bind[] = $status; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stmt = $pdo->prepare(
    "SELECT a.id, a.status, a.submitted_at, a.rating,
            c.first_name, c.last_name, c.email, p.title AS position_title, p.id AS position_id
     FROM job_applications a
     JOIN candidates    c ON c.id = a.candidate_id
     JOIN job_positions p ON p.id = a.position_id
     $whereSql
     ORDER BY a.submitted_at DESC
     LIMIT 500"
);
$stmt->execute($bind);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/header.php';
?>
<h1>Candidature</h1>
<form method="get" class="filters">
  <label>Posizione
    <select name="position_id" onchange="this.form.submit()">
      <option value="0">— tutte —</option>
      <?php foreach ($pdo->query("SELECT id, title FROM job_positions ORDER BY title")->fetchAll(PDO::FETCH_ASSOC) as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $p['id']==$positionId?'selected':'' ?>><?= h($p['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Stato
    <select name="status" onchange="this.form.submit()">
      <option value="">— tutti —</option>
      <?php foreach (['new','screening','interview','offer','hired','rejected','withdrawn'] as $s): ?>
        <option value="<?= $s ?>" <?= $s===$status?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<?php if (!empty($app)): ?>
  <section class="card">
    <h2>Candidatura #<?= (int)$app['id'] ?> — <?= h($app['position_title']) ?></h2>
    <p>
      <b><?= h($app['first_name'] . ' ' . $app['last_name']) ?></b><br>
      <?= h($app['email']) ?> · <?= h($app['phone']) ?><br>
      <?= h($app['city']) ?> <?= h($app['country']) ?><br>
      <?php if ($app['linkedin_url']): ?><a href="<?= h($app['linkedin_url']) ?>" target="_blank" rel="noopener">LinkedIn</a><br><?php endif; ?>
      Invio: <?= h($app['submitted_at']) ?>
    </p>
    <?php if ($app['cv_stored']): ?>
      <p><a href="download_cv.php?app=<?= (int)$app['id'] ?>">Scarica CV — <?= h($app['cv_name']) ?></a></p>
    <?php endif; ?>
    <?php if ($app['cover_letter']): ?>
      <details><summary>Lettera di presentazione</summary><pre><?= h($app['cover_letter']) ?></pre></details>
    <?php endif; ?>

    <form method="post" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status">
      <input type="hidden" name="id" value="<?= (int)$app['id'] ?>">
      <label>Stato
        <select name="status">
          <?php foreach (['new','screening','interview','offer','hired','rejected','withdrawn'] as $s): ?>
            <option value="<?= $s ?>" <?= $s===$app['status']?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button>Aggiorna</button>
    </form>

    <form method="post" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="rate">
      <input type="hidden" name="id" value="<?= (int)$app['id'] ?>">
      <label>Voto (1-5) <input name="rating" type="number" min="1" max="5" value="<?= (int)($app['rating'] ?? 0) ?>"></label>
      <button>Salva voto</button>
    </form>

    <?php if ($app['notes']): ?><h3>Storico</h3><pre><?= h($app['notes']) ?></pre><?php endif; ?>
  </section>
<?php endif; ?>

<table class="tbl">
  <thead><tr><th>#</th><th>Nome</th><th>Email</th><th>Posizione</th><th>Stato</th><th>Voto</th><th>Inviata</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list as $a): ?>
    <tr>
      <td><?= (int)$a['id'] ?></td>
      <td><?= h($a['first_name'] . ' ' . $a['last_name']) ?></td>
      <td><?= h($a['email']) ?></td>
      <td><a href="?position_id=<?= (int)$a['position_id'] ?>"><?= h($a['position_title']) ?></a></td>
      <td><span class="badge b-<?= h($a['status']) ?>"><?= h($a['status']) ?></span></td>
      <td><?= h($a['rating']) ?></td>
      <td><?= h($a['submitted_at']) ?></td>
      <td><a href="?id=<?= (int)$a['id'] ?>">Apri</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__ . '/partials/footer.php'; ?>
