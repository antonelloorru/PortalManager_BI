<?php
/**
 * import_professionals.php — Import operatori -> Anagrafica Professionisti (v1.7.81)
 *
 * Importa l'export "operator" del gestionale (CSV separatore '|') nella tabella
 * cm_professionals. Vengono importati SOLO i dati anagrafici/costo: password,
 * temp_password, password_history, rfid e simili NON vengono mai letti ne' salvati.
 * UPSERT su source_operator_id (id del gestionale), ri-eseguibile senza duplicati.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/ProfessionalStore.php');
require_once(__DIR__ . '/app/PrefixResolver.php');

if (!can('view', 'import_professionals.php')) { redirect('professionals'); }
$u_id   = (int)$_SESSION['user_id'];
$store  = new ProfessionalStore($pdo);
$prefix = new PrefixResolver($pdo);

$dec = fn($v) => ($v === '' || $v === null) ? null : (float)str_replace([' ', ','], ['', '.'], (string)$v);

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (UploadGuard::postDiscarded()) { $_SESSION['flash_msg'] = UploadGuard::discardedMessage(); redirect_self(); }
    Csrf::verify();
    if (!can('create', 'import_professionals.php')) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    @set_time_limit(0);
    $skip_deleted = ($_POST['include_deleted'] ?? '') !== '1';
    $auto_link    = ($_POST['auto_link'] ?? '') === '1';

    try {
        if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) throw new Exception($err);

        $pdo->prepare("INSERT INTO cm_import_batches (filename,kind,rows_total,created_by) VALUES (?,?,?,?)")
            ->execute([$_FILES['file']['name'], 'commesse', 0, $u_id]);
        $batchId = (int)$pdo->lastInsertId();

        $fh = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$fh) throw new Exception('Impossibile aprire il file.');
        $bom = fread($fh, 3); if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $header = fgetcsv($fh, 0, '|', '"');
        if (!$header) throw new Exception('File vuoto o non leggibile.');
        $idx = [];
        foreach ($header as $i => $h) $idx[strtolower(trim($h))] = $i;
        foreach (['id', 'first_name', 'second_name'] as $req) {
            if (!isset($idx[$req])) throw new Exception("Colonna obbligatoria '$req' non trovata.");
        }
        $col = function (array $row, string $k) use ($idx) {
            $i = $idx[$k] ?? null; return ($i !== null && isset($row[$i])) ? trim((string)$row[$i]) : '';
        };

        $ins = 0; $upd = 0; $skip = 0; $skipdel = 0; $linked = 0; $matched = 0; $total = 0;
        while (($row = fgetcsv($fh, 0, '|', '"')) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
            $total++;
            $sid = $col($row, 'id');
            if ($sid === '') { $skip++; continue; }
            $deleted = $col($row, 'deleted') === '1';
            if ($skip_deleted && $deleted) { $skipdel++; continue; }

            // NB: campi credenziali (password, temp_password, password_history, rfid) volutamente ignorati
            $company = $col($row, 'company_abbr');
            $d = [
                'source_operator_id' => (int)$sid,
                'username'    => $col($row, 'username') ?: null,
                'email'       => $col($row, 'email') ?: null,
                'first_name'  => $col($row, 'first_name') ?: null,
                'last_name'   => $col($row, 'second_name') ?: null,
                'abbr'        => $col($row, 'abbr') ?: null,
                'company_abbr'=> $company ?: null,
                'exec_company_id' => $company !== '' ? $prefix->companyId($company . '_0') : null,
                'phone'       => $col($row, 'phone') ?: null,
                'badge'       => $col($row, 'badge') ?: null,
                'hourly_cost' => $dec($col($row, 'hourly_cost')),
                'full_cost'   => $dec($col($row, 'full_cost')),
                'skills'      => $col($row, 'skills') ?: null,
                'notes'       => $col($row, 'note') ?: null,
                'operator_type' => $col($row, 'type') ?: null,
                'active'      => $col($row, 'active') === '0' ? 0 : 1,
                'deleted_src' => $deleted ? 1 : 0,
            ];
            $res = $store->upsert($d, $batchId, $u_id);
            $res === 'inserted' ? $ins++ : $upd++;

            // v1.7.82: rileva se l'operatore corrisponde a un dipendente (email/nome), per distinguerlo
            $pid = (int)$pdo->query("SELECT id FROM cm_professionals WHERE source_operator_id=" . (int)$sid)->fetchColumn();
            $sug = $store->suggestEmployee(['email' => $d['email'], 'first_name' => $d['first_name'], 'last_name' => $d['last_name']]);
            if ($pid) {
                // non sovrascrive un collegamento già confermato
                $cur = $pdo->query("SELECT employee_id FROM cm_professionals WHERE id=$pid")->fetchColumn();
                if (!$cur) $store->setMatch($pid, $sug);
            }
            if ($sug) $matched++;

            if ($auto_link && $pid && $sug && $sug['match'] === 'email') {
                $cur = $pdo->query("SELECT employee_id FROM cm_professionals WHERE id=$pid")->fetchColumn();
                if (!$cur) { $store->linkToEmployee($pid, (int)$sug['id'], $u_id); $linked++; }
            }
        }
        fclose($fh);

        $pdo->prepare("UPDATE cm_import_batches SET rows_total=?, rows_ok=?, rows_unmatched=? WHERE id=?")
            ->execute([$total, $ins + $upd, $skip + $skipdel, $batchId]);
        write_log('Professionals', 'success', "Import professionisti: $ins nuovi, $upd aggiornati, $linked auto-collegati (batch #$batchId)", $u_id);
        $_SESSION['flash_prof_import'] = compact('total', 'ins', 'upd', 'skip', 'skipdel', 'linked', 'matched', 'batchId');
        redirect_self();
    } catch (Throwable $e) {
        write_log('Professionals', 'error', "Import professionisti fallito: " . $e->getMessage(), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . h($e->getMessage()) . "</div>";
        redirect_self();
    }
}

if (!empty($_SESSION['flash_prof_import'])) { $report = $_SESSION['flash_prof_import']; unset($_SESSION['flash_prof_import']); }

require_once('header.php');
?>
<div class="page-header">
  <h1><i class="fa-solid fa-user-tie"></i> Import Professionisti</h1>
  <p style="color:var(--muted);font-size:13px">Importa gli operatori del gestionale (CSV separatore <code>|</code>) nell'anagrafica professionisti, separata dai dipendenti. Le credenziali (password, RFID) non vengono mai importate.</p>
</div>

<?php if ($report): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid #16a34a">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-check"></i> Import completato (batch #<?=$report['batchId']?>)</span></div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;padding:10px 0">
      <?php foreach ([['Righe',$report['total']],['Nuovi',$report['ins']],['Aggiornati',$report['upd']],['Riconosciuti dipendenti',$report['matched']??0],['Auto-collegati',$report['linked']],['Eliminati saltati',$report['skipdel']]] as [$k,$v]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px"><div style="font-size:20px;font-weight:800"><?=$v?></div><div style="font-size:10px;color:var(--muted);font-weight:700"><?=$k?></div></div>
      <?php endforeach; ?>
    </div>
    <p style="font-size:12px;color:var(--muted)"><i class="fa-solid fa-circle-info"></i> I professionisti riconosciuti come dipendenti (per email o nome) sono contrassegnati nell'anagrafica e filtrabili; puoi comunque collegarli definitivamente con <em>Unisci</em>.</p>
    <a class="btn btn-primary" href="<?=url_safe('professionals')?>"><i class="fa-solid fa-users"></i> Vai all'anagrafica professionisti</a>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-csv"></i> Carica export operatori</span></div>
  <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:12px">
    <?= csrf_field() ?>
    <div class="form-group" style="margin:0;max-width:520px"><label>File CSV del gestionale (separatore <code>|</code>)</label><input type="file" name="file" accept=".csv,.txt" required></div>
    <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="auto_link" value="1" checked> collega automaticamente ai dipendenti con <strong>email identica</strong></label>
    <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="include_deleted" value="1"> importa anche gli operatori marcati come eliminati</label>
    <div><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Importa</button></div>
  </form>
  <div style="background:#fffbeb;border:1px solid #fde68a;padding:12px;border-radius:8px;margin-top:14px;font-size:12px;color:#92400e">
    <i class="fa-solid fa-shield-halved"></i> <strong>Riservatezza:</strong> vengono importati solo nome, cognome, email, azienda, recapiti, costo orario/pieno, skill e note. I campi <code>password</code>, <code>temp_password</code>, <code>password_history</code> e <code>rfid</code> sono esclusi a monte e non transitano nel database.
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">UPSERT su ID operatore del gestionale: ri-eseguibile senza duplicati. Azienda derivata dalla sigla (<code>company_abbr</code>).</p>
</div>
<?php require_once('footer.php'); ?>
