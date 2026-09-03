<?php
/**
 * export_employees.php — Estrazione Anagrafica Dipendenti (v1.7.90)
 *
 * Esporta in XLSX o CSV i dati anagrafici e contrattuali dei dipendenti.
 * Accesso riservato: Amministratore (Super Admin), HR e Responsabile Finanziario.
 * Le colonne esportate sono fisse e non includono dati retributivi (RAL,
 * compensation): l'estrazione è anagrafico-contrattuale.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/XlsxWriter.php');

if (!can('view', 'export_employees.php')) { redirect('dashboard'); }
$u_id = (int)$_SESSION['user_id'];

/** Colonne dell'estrazione: intestazione => espressione SQL. */
function exp_columns(PDO $pdo): array
{
    // rileva le colonne realmente presenti in employees per adattarsi allo schema
    try {
        $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $cols = []; }
    $has = fn(string $c) => in_array($c, $cols, true);
    $col = fn(string $c, string $fallback = "''") => $has($c) ? "e.`$c`" : $fallback;

    // Qualifica/Ruolo: preferisce qualification, poi job_title
    $qual = $has('qualification') && $has('job_title')
        ? "COALESCE(NULLIF(e.`qualification`,''), e.`job_title`)"
        : ($has('qualification') ? "e.`qualification`" : $col('job_title'));
    // Dipartimento: da tabella departments se collegata, altrimenti campo testuale
    $dept = $has('department_id') && $has('department')
        ? "COALESCE(d.`name`, NULLIF(e.`department`,''))"
        : ($has('department_id') ? "d.`name`" : $col('department'));

    // Email aziendale: business_email, con fallback su work_email/email se lo schema differisce
    $mail = $has('business_email') ? "e.`business_email`"
          : ($has('work_email') ? "e.`work_email`" : ($has('email') ? "e.`email`" : "''"));

    return [
        'Cognome'                   => $col('last_name'),
        'Nome'                      => $col('first_name'),
        'Codice fiscale'            => $col('fiscal_code'),
        'Matricola'                 => $col('employee_code'),
        'Email aziendale'           => $mail,
        'Contratto'                 => $col('contract_type'),
        'Data assunzione'           => $col('hire_date', 'NULL'),
        'Data cessazione'           => $col('end_date', 'NULL'),
        'Azienda'                   => 'c.`name`',
        'Sede'                      => 'l.`location_name`',
        'Tipo rapporto'             => 'w.`name`',
        'Dipartimento'              => $dept,
        'Qualifica/Ruolo'           => $qual,
        'Classificazione finanziaria' => $col('classificazione_finanziaria'),
        'Stato'                     => $col('status'),
    ];
}

/** Righe dell'estrazione, con i filtri applicati. */
function exp_rows(PDO $pdo, array $f): array
{
    $cols = exp_columns($pdo);
    $select = [];
    $i = 0;
    foreach ($cols as $label => $expr) $select[] = "$expr AS `col" . ($i++) . "`";

    $w = ['1=1']; $a = [];
    if (!empty($f['company_id'])) { $w[] = 'e.company_id = ?'; $a[] = (int)$f['company_id']; }
    if (($f['stato'] ?? '') === 'attivi')   { $w[] = "(e.end_date IS NULL OR e.end_date >= CURDATE())"; }
    if (($f['stato'] ?? '') === 'cessati')  { $w[] = "(e.end_date IS NOT NULL AND e.end_date < CURDATE())"; }

    $sql = "SELECT " . implode(', ', $select) . "
              FROM employees e
              LEFT JOIN companies c          ON c.id = e.company_id
              LEFT JOIN company_locations l  ON l.id = e.location_id
              LEFT JOIN work_modes w         ON w.id = e.work_mode_id
              LEFT JOIN departments d        ON d.id = e.department_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY e.last_name, e.first_name";
    try {
        $st = $pdo->prepare($sql); $st->execute($a);
        $raw = $st->fetchAll(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        // fallback senza le lookup opzionali (schema ridotto)
        $sql2 = str_replace(
            ["LEFT JOIN departments d        ON d.id = e.department_id"],
            [""], $sql
        );
        $st = $pdo->prepare($sql2); $st->execute($a);
        $raw = $st->fetchAll(PDO::FETCH_NUM);
    }
    $out = [array_keys($cols)];
    foreach ($raw as $r) $out[] = array_map(fn($v) => $v === null ? '' : (string)$v, $r);
    return $out;
}

$f = [
    'company_id' => (int)($_GET['company'] ?? $_POST['company'] ?? 0),
    'stato'      => (string)($_GET['stato'] ?? $_POST['stato'] ?? 'attivi'),
];

// ── Download (prima di header.php) ────────────────────────────────────────────
$fmt = strtolower(trim((string)($_GET['export'] ?? '')));
if ($fmt === 'xlsx' || $fmt === 'csv') {
    if (!can('export', 'export_employees.php')) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti per l'estrazione.</div>"; redirect_self(); }
    $rows = exp_rows($pdo, $f);
    $stamp = date('Ymd_Hi');
    write_log('HR', 'success', "Estrazione anagrafica dipendenti ($fmt): " . (count($rows) - 1) . " righe", $u_id);

    if ($fmt === 'xlsx') {
        $w = new XlsxWriter();
        $w->addSheet('Anagrafica dipendenti', $rows);
        $w->download("anagrafica_dipendenti_$stamp.xlsx");
        exit;
    }
    // CSV: separatore ';' e BOM UTF-8 per apertura corretta in Excel italiano
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"anagrafica_dipendenti_$stamp.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($rows as $r) fputcsv($out, $r, ';', '"');
    fclose($out);
    exit;
}

$companies = [];
try { $companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
$preview = exp_rows($pdo, $f);
$tot = max(0, count($preview) - 1);

require_once('header.php');
$qs = fn(array $o = []) => url_safe('export_employees', array_filter(array_merge(['company' => $f['company_id'], 'stato' => $f['stato']], $o), fn($v) => $v !== '' && $v !== 0));
?>
<div class="page-header">
  <h1><i class="fa-solid fa-file-export"></i> Estrazione Anagrafica Dipendenti</h1>
  <p style="color:var(--muted);font-size:13px">Estrazione anagrafico-contrattuale in XLSX o CSV. Accesso riservato ad Amministratore, HR e Responsabile Finanziario.</p>
</div>

<div class="card" style="margin-bottom:14px">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?= route_slug_field() ?>
    <div class="form-group" style="margin:0"><label>Azienda</label>
      <select name="company"><option value="">tutte</option>
        <?php foreach ($companies as $id => $nm): ?><option value="<?= (int)$id ?>" <?= $f['company_id'] === (int)$id ? 'selected' : '' ?>><?= h($nm) ?></option><?php endforeach; ?></select></div>
    <div class="form-group" style="margin:0"><label>Stato</label>
      <select name="stato">
        <option value="tutti"  <?= $f['stato'] === 'tutti'   ? 'selected' : '' ?>>tutti</option>
        <option value="attivi" <?= $f['stato'] === 'attivi'  ? 'selected' : '' ?>>solo in forza</option>
        <option value="cessati"<?= $f['stato'] === 'cessati' ? 'selected' : '' ?>>solo cessati</option>
      </select></div>
    <button class="btn">Applica filtri</button>
    <span style="align-self:center;color:var(--muted);font-size:12px"><strong><?= $tot ?></strong> dipendenti nell'estrazione</span>
    <?php if (can('export', 'export_employees.php')): ?>
      <a class="btn btn-success" href="<?= $qs(['export' => 'xlsx']) ?>"><i class="fa-solid fa-file-excel"></i> Scarica XLSX</a>
      <a class="btn btn-primary" href="<?= $qs(['export' => 'csv']) ?>"><i class="fa-solid fa-file-csv"></i> Scarica CSV</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-table"></i> Anteprima (prime 25 righe)</span></div>
  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:11px;white-space:nowrap">
      <thead><tr><?php foreach ($preview[0] as $hh): ?><th><?= h($hh) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php if ($tot === 0): ?>
        <tr><td colspan="<?= count($preview[0]) ?>" style="text-align:center;color:var(--muted);padding:16px">Nessun dipendente con i filtri selezionati.</td></tr>
      <?php else: foreach (array_slice($preview, 1, 25) as $r): ?>
        <tr><?php foreach ($r as $v): ?><td><?= h($v) ?></td><?php endforeach; ?></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">Il CSV usa separatore <code>;</code> e codifica UTF-8 con BOM, per l'apertura diretta in Excel. L'estrazione non contiene dati retributivi.</p>
</div>
<?php require_once('footer.php'); ?>
