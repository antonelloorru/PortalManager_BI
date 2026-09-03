<?php
/**
 * certV 5.5.0 — mass_upload_partials.php
 *
 * Late Data Binding (LDB) UI:
 * Lista dei record importati con campi mancanti, con form di completamento inline.
 * Supporta filtri per tipo di import e per utente proprietario del job.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/ImportValidator.php';
require_once __DIR__ . '/app/ImportProcessor.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

if ($u_role > 2) {
    header('Location: unauthorized.php'); exit();
}

// ─── HANDLE POST: completa singolo campo ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'complete_field') {
            $stagingId = (int)$_POST['staging_id'];
            $fieldName = trim((string)$_POST['field_name']);
            $newValue  = $_POST['new_value'] ?? '';

            // Recupero tipo import per istanziare il processor
            $stmt = $pdo->prepare(
                "SELECT j.import_type FROM import_staging_rows isr
                   JOIN import_jobs j ON j.id = isr.job_id
                  WHERE isr.id = ?"
            );
            $stmt->execute([$stagingId]);
            $type = $stmt->fetchColumn();
            if (!$type) throw new RuntimeException("Riga non trovata.");

            $proc = new ImportProcessor($pdo, $type);
            $r = $proc->completePartialField($stagingId, $fieldName, $newValue, $u_id);

            $msg = "<i class='fa-solid fa-check'></i> Campo <strong>" . h($fieldName) . "</strong> completato.";
            if ($r['completed']) {
                $msg .= " Record completamente integrato.";
            } else {
                $msg .= " Restano <strong>{$r['remaining']}</strong> campi da completare.";
            }
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>$msg</div>";
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
    header('Location: ' . $_SERVER['PHP_SELF'] .
           (!empty($_GET['r']) ? '?r=' . urlencode($_GET['r']) : '') .
           (!empty($_GET['f_type']) ? (empty($_GET['r']) ? '?' : '&') . 'f_type=' . urlencode($_GET['f_type']) : ''));
    exit();
}

// ─── FILTRI ───────────────────────────────────────────────────────────
$f_type = trim((string)($_GET['f_type'] ?? ''));
$f_only_mine = !empty($_GET['f_only_mine']);

$partials = ImportProcessor::listPartialRecords(
    $pdo,
    $f_type !== '' ? $f_type : null,
    $f_only_mine ? $u_id : null,
    300
);

// Tipi distinti per dropdown filtro
$types_distinct = $pdo->query(
    "SELECT DISTINCT j.import_type
       FROM import_staging_rows isr
       JOIN import_jobs j ON j.id = isr.job_id
      WHERE isr.is_partial = 1 AND isr.missing_fields IS NOT NULL
      ORDER BY j.import_type"
)->fetchAll(PDO::FETCH_COLUMN);

// Map tipo → label leggibile
$type_labels = [
    'dipendenti'        => 'Dipendenti',
    'accessi'           => 'Accessi',
    'brand'             => 'Brand',
    'tecnologie'        => 'Tecnologie',
    'catalogo'          => 'Catalogo certificazioni',
    'sedi'              => 'Sedi',
    'agenzie'           => 'Agenzie',
    'contatti_agenzie'  => 'Contatti agenzie',
    'candidati'         => 'Candidati',
    'clienti'           => 'Clienti',
    'certificati'       => 'Certificazioni dipendenti',
    'piani_formativi'   => 'Piani formativi',
    'esami'             => 'Esami pianificati',
    'templates'         => 'Template posizioni',
];

require_once('header.php');
?>

<style>
.ldb-card { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.04); margin-bottom:14px; overflow:hidden; }
.ldb-card-h { background: linear-gradient(135deg,#fef3c7,#fde68a); padding:10px 14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; border-left:4px solid #f59e0b; }
.ldb-card-h .meta { font-size:11px; color:#92400e; }
.ldb-card-h .meta strong { font-weight:800; }
.ldb-card-b { padding:14px; }
.ldb-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:10px; }
.ldb-field { background:#fff; border:1px solid var(--border); border-radius:8px; padding:10px; }
.ldb-field-h { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.ldb-field-name { font-weight:700; font-size:12px; color:#1e293b; }
.ldb-field-type { font-size:10px; color:var(--muted); font-family:monospace; }
.ldb-field-hint { font-size:10px; color:var(--muted); margin-bottom:8px; line-height:1.4; }
.ldb-field input, .ldb-field select { width:100%; padding:6px 8px; border:1px solid var(--border); border-radius:5px; font-size:12px; font-family:inherit; }
.ldb-field-row { display:flex; gap:6px; }
.ldb-field-row input, .ldb-field-row select { flex:1; }
.ldb-payload { background:#f8fafc; padding:8px 12px; border-radius:6px; margin-bottom:10px; font-size:11px; color:#475569; }
.ldb-payload strong { color:#1e293b; }
</style>

<div style="max-width:1400px;margin:0 auto">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-puzzle-piece" style="color:#f59e0b"></i> Completamento dati post-import (LDB)
      </h1>
      <div style="color:var(--muted);font-size:13px">
        Record importati con campi mancanti. Completali qui per finalizzare l'integrazione.
      </div>
    </div>
    <a href="<?= url_safe('mass_upload_jobs') ?>" class="btn btn-sm">← Storico job</a>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?>
    <?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?>
  <?php endif; ?>

  <!-- BANNER INFORMATIVO -->
  <div style="background:#eff6ff;border-left:4px solid #0ea5e9;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;color:#1e40af">
    <i class="fa-solid fa-circle-info"></i> <strong>Late Data Binding (LDB)</strong>:
    durante l'import in modalità "consenti record parziali" i record sono stati salvati
    anche se mancavano alcuni campi obbligatori. Qui puoi completarli manualmente uno per uno.
    Il record si considera completato quando tutti i campi mancanti sono stati compilati.
  </div>

  <!-- FILTRI -->
  <form method="GET" class="filter-bar" style="margin-bottom:14px">
    <?php if (!empty($_GET['r'])): ?><input type="hidden" name="r" value="<?= h($_GET['r']) ?>"><?php endif; ?>
    <div class="fg">
      <label>Tipo import</label>
      <select name="f_type" onchange="this.form.submit()">
        <option value="">Tutti</option>
        <?php foreach ($types_distinct as $t): ?>
          <option value="<?= h($t) ?>" <?= $f_type===$t?'selected':'' ?>><?= h($type_labels[$t] ?? $t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg" style="display:flex;align-items:flex-end">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0">
        <input type="checkbox" name="f_only_mine" value="1" <?= $f_only_mine?'checked':'' ?> onchange="this.form.submit()">
        <span>Solo miei import</span>
      </label>
    </div>
    <div class="fg" style="margin-left:auto">
      <label style="visibility:hidden">.</label>
      <span style="font-weight:700;color:var(--muted);font-size:12px">
        <?= count($partials) ?> record da completare
      </span>
    </div>
  </form>

  <!-- LISTA RECORD LDB -->
  <?php if (empty($partials)): ?>
    <div style="background:#fff;padding:60px 30px;border-radius:12px;text-align:center;color:var(--muted)">
      <i class="fa-solid fa-circle-check" style="font-size:48px;color:#10b981;margin-bottom:14px"></i>
      <h3 style="margin:0 0 8px;font-size:16px">Nessun record da completare</h3>
      <p style="margin:0;font-size:13px">Tutti gli import sono completi. Ottimo lavoro!</p>
    </div>
  <?php else: ?>
    <?php foreach ($partials as $row):
      $type = $row['import_type'];
      $type_label = $type_labels[$type] ?? $type;
      $payload = json_decode((string)$row['payload'], true) ?: [];
      $missing = json_decode((string)$row['missing_fields'], true) ?: [];

      // Carica schema per questo tipo
      try {
          $schema = ImportValidator::getSchema($type);
      } catch (Throwable $e) {
          $schema = [];
      }

      // Identifica chiave per il record (label leggibile)
      $key_field = match ($type) {
          'dipendenti', 'accessi'  => 'first_name',
          'candidati'              => 'email',
          'brand', 'tecnologie', 'agenzie', 'clienti', 'sedi'  => 'name',
          'catalogo'               => 'code',
          default                  => array_key_first($payload) ?: 'id',
      };
      $row_label = '';
      if ($type === 'dipendenti' || $type === 'accessi') {
          $row_label = trim(($payload['first_name'] ?? '') . ' ' . ($payload['last_name'] ?? ''));
      } elseif (!empty($payload[$key_field])) {
          $row_label = (string)$payload[$key_field];
      } else {
          $row_label = "Record #{$row['result_id']}";
      }
    ?>
      <div class="ldb-card">
        <div class="ldb-card-h">
          <div>
            <div style="font-weight:800;font-size:14px;color:#451a03">
              <i class="fa-solid fa-id-card"></i>
              <?= h($row_label) ?>
              <span style="background:#f59e0b;color:#fff;padding:2px 8px;border-radius:8px;font-size:9px;font-weight:800;margin-left:6px">
                <?= count($missing) ?> CAMPI MANCANTI
              </span>
            </div>
            <div class="meta">
              <i class="fa-solid fa-tag"></i> <?= h($type_label) ?>
              · <strong>Job #<?= (int)$row['job_id'] ?></strong>: <?= h($row['original_name']) ?>
              · Riga <?= (int)$row['row_number'] ?>
              · ID <?= (int)$row['result_id'] ?>
              · Importato <?= date('d/m H:i', strtotime($row['imported_at'])) ?>
            </div>
          </div>
        </div>
        <div class="ldb-card-b">

          <!-- PAYLOAD ATTUALE (compatto) -->
          <div class="ldb-payload">
            <strong>Dati attualmente salvati:</strong>
            <?php
              $shown = 0;
              foreach ($payload as $k => $v) {
                  if ($v === null || $v === '' || in_array($k, $missing, true)) continue;
                  if ($shown >= 6) break;
                  echo ' · <span><strong>' . h($k) . ':</strong> ' . h(mb_strimwidth((string)$v, 0, 30, '…')) . '</span>';
                  $shown++;
              }
              if ($shown === 0) echo ' <em>(solo campi mancanti)</em>';
            ?>
          </div>

          <!-- FORM COMPLETAMENTO PER OGNI CAMPO MANCANTE -->
          <div class="ldb-fields">
            <?php foreach ($missing as $field):
              $rules = $schema[$field] ?? [];
              $label = $rules['label'] ?? $field;
              $hint  = $rules['hint']  ?? '';
              $type_d = $rules['type'] ?? 'string';
              $enum  = $rules['enum'] ?? null;
            ?>
              <div class="ldb-field">
                <div class="ldb-field-h">
                  <div>
                    <div class="ldb-field-name"><?= h($label) ?></div>
                    <div class="ldb-field-type"><?= h($field) ?> · <?= h($type_d) ?></div>
                  </div>
                </div>
                <?php if ($hint): ?>
                  <div class="ldb-field-hint"><?= h($hint) ?></div>
                <?php endif; ?>
                <form method="POST" class="ldb-field-row">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="complete_field">
                  <input type="hidden" name="staging_id" value="<?= (int)$row['staging_id'] ?>">
                  <input type="hidden" name="field_name" value="<?= h($field) ?>">
                  <?php if ($enum): ?>
                    <select name="new_value" required>
                      <option value="">— seleziona —</option>
                      <?php foreach ($enum as $opt): ?>
                        <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($type_d === 'date'): ?>
                    <input type="date" name="new_value" required>
                  <?php elseif ($type_d === 'int' || $type_d === 'decimal'): ?>
                    <input type="number" step="<?= $type_d==='int'?'1':'0.01' ?>" name="new_value" required>
                  <?php elseif ($type_d === 'email'): ?>
                    <input type="email" name="new_value" required>
                  <?php elseif ($type_d === 'bool'): ?>
                    <select name="new_value" required>
                      <option value="1">Sì</option>
                      <option value="0">No</option>
                    </select>
                  <?php else: ?>
                    <input type="text" name="new_value" required maxlength="<?= (int)($rules['max_length'] ?? 255) ?>">
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary btn-sm" title="Salva e propaga al record">
                    <i class="fa-solid fa-check"></i>
                  </button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

<?php require_once('footer.php'); ?>
