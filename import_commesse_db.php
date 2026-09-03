<?php
/**
 * import_commesse_db.php — Import Commesse dal DB gestionale
 *
 * v1.8.45: oltre al caricamento del CSV esportato a mano, la pagina consente ora
 * la LETTURA DIRETTA dal database gestionale. Si configurano una volta indirizzo,
 * porta, nome database, utente e password, e la sincronizzazione si lancia
 * quando serve, senza passare da alcun file.
 *
 * La connessione e' in sola lettura (app/SourceDb.php) e la password e' salvata
 * cifrata con AES-256-GCM. La mappatura delle colonne e' la stessa dell'import
 * v1.8.63 — la pagina conserva la sola configurazione della connessione
 * e la verifica: l'import da tabella singola e' stato rimosso.
 *
 * Complementare a "Import commesse XLSX": importa l'export nativo del gestionale
 * sorgente (tabella "contract"), un CSV con separatore '|', quoting '"' e CRLF,
 * mappando le sue colonne su cm_projects. UPSERT su project_code (= code),
 * ri-eseguibile senza duplicati. Cliente creato in anagrafica, azienda esecutrice
 * dal prefisso del codice. I record con deleted=1 sono saltati per default.
 *
 * Dati riservati: il file caricato viene elaborato in streaming e non conservato.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/ProjectModel.php');
require_once(__DIR__ . '/app/PrefixResolver.php');
require_once(__DIR__ . '/app/RecycleBin.php');
require_once(__DIR__ . '/app/SourceDb.php');
require_once(__DIR__ . '/app/SyncDatasets.php');

if (!can('view', 'import_commesse_db.php')) { redirect('manage_projects'); }
$u_id   = (int)$_SESSION['user_id'];
$model  = new ProjectModel($pdo);
$prefix = new PrefixResolver($pdo);

$dec = fn($v) => ($v === '' || $v === null) ? null : (float)str_replace([' ', ','], ['', '.'], (string)$v);
$intv = fn($v) => ($v === '' || $v === null) ? null : (int)$v;
// il gestionale usa 'YYYY-MM-DD HH:MM:SS.mmm'
$date = function ($v) {
    $t = trim((string)$v);
    if ($t === '' || str_starts_with($t, '0000')) return null;
    $d = substr($t, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
};
// stato gestionale -> operational_status
$statusMap = ['OPEN' => 'Aperta', 'CLOSED' => 'Chiusa', 'SUSPENDED' => 'Sospesa'];

// ── v1.8.45: configurazione della sorgente esterna ──────────────────────────
$srcCfg = null;
try {
    $srcCfg = $pdo->query("SELECT * FROM cm_source_db WHERE is_active=1 ORDER BY id DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { /* migration non ancora eseguita */ }
$drivers   = SourceDb::availableDrivers();
$conn_msg  = null;
$sync_rep  = null;
$preview   = null;

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (UploadGuard::postDiscarded()) { $_SESSION['flash_msg'] = UploadGuard::discardedMessage(); redirect_self(); }
    Csrf::verify();

    // ── v1.8.45: azioni sulla connessione diretta ───────────────────────────
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action, ['save_conn', 'test_conn', 'sync', 'preview'], true)) {
        if (!can('create', 'import_commesse_db.php')) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
            redirect_self();
        }
        @set_time_limit(0);
        try {
            if ($action === 'save_conn') {
                $driver = (string)($_POST['driver'] ?? 'mysql');
                if (!isset(SourceDb::DRIVERS[$driver])) throw new Exception('Driver non valido.');
                // password vuota in modifica = mantieni quella gia' registrata
                $pwPost = (string)($_POST['password'] ?? '');
                $pwEnc  = $pwPost !== '' ? SourceDb::encrypt($pwPost)
                                         : (string)($srcCfg['password_enc'] ?? '');
                $vals = [
                    trim((string)($_POST['label'] ?? 'Gestionale')) ?: 'Gestionale',
                    $driver,
                    trim((string)($_POST['host'] ?? '')),
                    (int)($_POST['port'] ?? SourceDb::DRIVERS[$driver]['port']),
                    trim((string)($_POST['dbname'] ?? '')),
                    trim((string)($_POST['username'] ?? '')),
                    $pwEnc,
                    trim((string)($_POST['source_schema'] ?? '')) ?: null,
                    trim((string)($_POST['source_table'] ?? 'contract')) ?: 'contract',
                    max(3, min(60, (int)($_POST['timeout'] ?? 10))),
                    isset($_POST['include_deleted_db']) ? 1 : 0,
                ];
                if ($srcCfg) {
                    $vals[] = (int)$srcCfg['id'];
                    $pdo->prepare("UPDATE cm_source_db SET label=?,driver=?,host=?,port=?,dbname=?,username=?,
                                   password_enc=?,source_schema=?,source_table=?,timeout=?,include_deleted=?
                                   WHERE id=?")->execute($vals);
                } else {
                    $vals[] = $u_id;
                    $pdo->prepare("INSERT INTO cm_source_db
                        (label,driver,host,port,dbname,username,password_enc,source_schema,source_table,
                         timeout,include_deleted,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute($vals);
                }
                write_log('Projects', 'info', 'Configurazione sorgente commesse salvata', $u_id);
                $_SESSION['flash_msg'] = "<div class='alert alert-success'>Parametri di connessione salvati.</div>";
                redirect_self();
            }

            if (!$srcCfg) throw new Exception('Configurare e salvare prima i parametri di connessione.');
            $cfg = [
                'driver'   => $srcCfg['driver'], 'host' => $srcCfg['host'], 'port' => (int)$srcCfg['port'],
                'dbname'   => $srcCfg['dbname'], 'username' => $srcCfg['username'],
                'password' => SourceDb::decrypt((string)$srcCfg['password_enc']),
                'timeout'  => (int)$srcCfg['timeout'],
                'source_table' => $srcCfg['source_table'], 'source_schema' => (string)($srcCfg['source_schema'] ?? ''),
            ];
            $src = SourceDb::connect($cfg);

            if ($action === 'test_conn') {
                // v1.8.62 — La verifica controllava una SOLA tabella, quella
                // indicata in `source_table`, cercandovi le colonne `code` e
                // `name`. Era il meccanismo della v1.8.45, quando la
                // sincronizzazione leggeva davvero una tabella sola.
                //
                // Dalla v1.8.46 i dataset sono otto e leggono quattordici
                // tabelle con join fra loro: una verifica su `forms_contract`
                // non dice nulla sulla effettiva utilizzabilita' della sorgente,
                // e segnalava colonne mancanti anche quando tutto funzionava.
                //
                // Ora si verifica cio' che la sincronizzazione usa davvero:
                // l'inventario completo dello schema, incrociato con le tabelle
                // richieste dai dataset, e per ciascun dataset la sua query
                // eseguita con LIMIT 0 — che e' l'unica prova che quella query
                // funzionera'.
                $inv = $src->inventory($cfg['source_schema']);
                $cov = SyncDatasets::coverage($inv);

                $perDataset = [];
                foreach (SyncDatasets::syncOrder() as $k) {
                    $d = SyncDatasets::get($k);
                    $tab = SyncDatasets::tablesOf($k);
                    $assenti = array_values(array_intersect($tab, $cov['missing']));
                    $riga = ['key' => $k, 'label' => $d['label'] ?? $k,
                             'tabelle' => $tab, 'assenti' => $assenti];
                    if ($assenti) {
                        $riga['esito'] = 'tabelle mancanti';
                    } else {
                        // la prova che conta: la query gira davvero sulla sorgente
                        try {
                            $st = $src->query(rtrim($d['sql'], "; \n") . ' LIMIT 0');
                            $prodotte = [];
                            for ($i = 0; $i < $st->columnCount(); $i++) {
                                $m = $st->getColumnMeta($i);
                                if (is_array($m) && isset($m['name'])) $prodotte[] = (string)$m['name'];
                            }
                            $st->closeCursor();
                            $mancanti = array_values(array_diff(array_keys($d['map']), $prodotte));
                            $riga['colonne']  = count($prodotte);
                            $riga['mancanti'] = $mancanti;
                            $riga['esito']    = $mancanti ? 'colonne non prodotte' : 'ok';
                        } catch (Throwable $e) {
                            $riga['esito']  = 'query non eseguibile';
                            $riga['errore'] = $e->getMessage();
                        }
                    }
                    $perDataset[] = $riga;
                }
                $okCount = count(array_filter($perDataset, fn($r) => $r['esito'] === 'ok'));

                $_SESSION['flash_conn'] = [
                    'ok'          => $okCount === count($perDataset),
                    'server'      => $src->serverVersion(),
                    'schema'      => $src->currentSchema(),
                    'oggetti'     => count($inv),
                    'usate'       => count($cov['used']),
                    'assenti'     => $cov['missing'],
                    'per_dataset' => $perDataset,
                    'ok_count'    => $okCount,
                    'tot_dataset' => count($perDataset),
                ];
                write_log('Projects', 'info', sprintf('Test connessione: %d/%d dataset utilizzabili',
                    $okCount, count($perDataset)), $u_id);
                redirect_self();
            }

            // Nessun'altra azione: la sincronizzazione dei dati avviene in
            // Sincronizzazione gestionale, con gli undici dataset.
            throw new Exception('Azione non più disponibile: usare Sincronizzazione gestionale.');

            redirect_self();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            write_log('Projects', 'error', 'Sorgente commesse: ' . $e->getMessage(), $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> "
                . h($e->getMessage()) . "</div>";
            redirect_self();
        }
    }

    if (!can('create', 'import_commesse_db.php')) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    @set_time_limit(0);
    $include_deleted = ($_POST['include_deleted'] ?? '') === '1';

    try {
        if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) throw new Exception($err);

        $pdo->prepare("INSERT INTO cm_import_batches (filename,kind,rows_total,created_by) VALUES (?,?,?,?)")
            ->execute([$_FILES['file']['name'], 'commesse', 0, $u_id]);
        $batchId = (int)$pdo->lastInsertId();

        $stExists = $pdo->prepare("SELECT id FROM cm_projects WHERE project_code=?");
        $up = $pdo->prepare(
          "INSERT INTO cm_projects
             (project_code,name,client_id,client_raw,exec_company_id,operational_status,description,internal_description,
              start_date,end_date,value_total,value_todate,actual_cost,margin_total,margin_todate,
              residual_total,residual_todate,anomalies_open,anomalies_blocking,import_batch_id,created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE
             name=VALUES(name),client_id=COALESCE(VALUES(client_id),client_id),client_raw=VALUES(client_raw),
             exec_company_id=COALESCE(VALUES(exec_company_id),exec_company_id),operational_status=VALUES(operational_status),
             description=VALUES(description),internal_description=VALUES(internal_description),
             start_date=VALUES(start_date),end_date=VALUES(end_date),
             value_total=VALUES(value_total),value_todate=VALUES(value_todate),actual_cost=VALUES(actual_cost),
             margin_total=VALUES(margin_total),margin_todate=VALUES(margin_todate),
             residual_total=VALUES(residual_total),residual_todate=VALUES(residual_todate),
             anomalies_open=VALUES(anomalies_open),anomalies_blocking=VALUES(anomalies_blocking),
             import_batch_id=VALUES(import_batch_id)"
        );

        // ── parser CSV nativo (delimiter '|', quoting '"', CRLF) ──
        $fh = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$fh) throw new Exception('Impossibile aprire il file.');
        // salta eventuale BOM
        $bom = fread($fh, 3); if ($bom !== "\xEF\xBB\xBF") rewind($fh);

        $header = fgetcsv($fh, 0, '|', '"');
        if (!$header) throw new Exception('File vuoto o non leggibile.');
        $idx = [];
        foreach ($header as $i => $h) $idx[strtolower(trim($h))] = $i;
        foreach (['code', 'name'] as $req) {
            if (!isset($idx[$req])) throw new Exception("Colonna obbligatoria '$req' non trovata. Intestazioni: " . implode(' | ', array_slice($header, 0, 10)) . ' …');
        }
        $col = function (array $row, string $k) use ($idx) {
            $i = $idx[$k] ?? null;
            return ($i !== null && isset($row[$i])) ? $row[$i] : '';
        };

        $ins = 0; $upd = 0; $skip = 0; $skip_deleted = 0; $total = 0;
        $pdo->beginTransaction();
        while (($row = fgetcsv($fh, 0, '|', '"')) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) continue;
            $total++;

            $code = trim($col($row, 'code'));
            if ($code === '') { $skip++; continue; }
            if (!$include_deleted && trim($col($row, 'deleted')) === '1') { $skip_deleted++; continue; }

            $stExists->execute([$code]); $exists = (bool)$stExists->fetchColumn();

            $clientRaw = trim($col($row, 'customer_comp_name'));
            $clientId  = $clientRaw !== '' ? $model->upsertClient($clientRaw) : null;
            $execCo    = $prefix->companyId($code);
            $status    = $statusMap[strtoupper(trim($col($row, 'status')))] ?? (trim($col($row, 'status')) ?: null);

            $up->execute([
                $code,
                trim($col($row, 'name')) ?: $code,
                $clientId, $clientRaw ?: null, $execCo, $status,
                ($col($row, 'description') !== '' ? $col($row, 'description') : null),
                ($col($row, 'internal_description') !== '' ? $col($row, 'internal_description') : null),
                $date($col($row, 'start_date')), $date($col($row, 'end_date')),
                $dec($col($row, 'economic_value')), $dec($col($row, 'economic_value_till_now')),
                $dec($col($row, 'time_material_costs')),
                $dec($col($row, 'margin_value')), $dec($col($row, 'margin_value_till_now')),
                $dec($col($row, 'residual_value')), $dec($col($row, 'residual_value_till_now')),
                $intv($col($row, 'n_ano_open')) ?? 0, $intv($col($row, 'n_ano_open_block')) ?? 0,
                $batchId, $u_id,
            ]);
            $exists ? $upd++ : $ins++;

            if (($ins + $upd) % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
        }
        if ($pdo->inTransaction()) $pdo->commit();
        fclose($fh);

        $pdo->prepare("UPDATE cm_import_batches SET rows_total=?, rows_ok=?, rows_unmatched=? WHERE id=?")
            ->execute([$total, $ins + $upd, $skip + $skip_deleted, $batchId]);
        write_log('Projects', 'success', "Import commesse DB: $ins nuove, $upd aggiornate, $skip_deleted eliminate saltate (batch #$batchId)", $u_id);
        $report = ['total' => $total, 'ins' => $ins, 'upd' => $upd, 'skip' => $skip, 'skip_deleted' => $skip_deleted, 'batch' => $batchId];
        $_SESSION['flash_import_db'] = $report;
        redirect_self();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        write_log('Projects', 'error', "Import commesse DB fallito: " . $e->getMessage(), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . h($e->getMessage()) . "</div>";
        redirect_self();
    }
}

if (!empty($_SESSION['flash_import_db'])) { $report = $_SESSION['flash_import_db']; unset($_SESSION['flash_import_db']); }
if (!empty($_SESSION['flash_conn']))    { $conn_msg = $_SESSION['flash_conn'];    unset($_SESSION['flash_conn']); }
if (!empty($_SESSION['flash_sync']))    { $sync_rep = $_SESSION['flash_sync'];    unset($_SESSION['flash_sync']); }
if (!empty($_SESSION['flash_preview'])) { $preview  = $_SESSION['flash_preview']; unset($_SESSION['flash_preview']); }
$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

require_once('header.php');
?>
<div class="page-header">
  <h1><i class="fa-solid fa-database"></i> Import Commesse DB</h1>
  <p style="color:var(--muted);font-size:13px">Allinea le commesse alla tabella <code>contract</code> del gestionale, leggendola direttamente dal database oppure da un export CSV. UPSERT su codice commessa: ri-eseguibile senza duplicati.</p>
</div>
<?= $msg ?>

<?php if ($report): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid #16a34a">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-check"></i> Import completato (batch #<?=$report['batch']?>)</span></div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;padding:10px 0">
      <?php foreach ([['Righe lette',$report['total']],['Nuove',$report['ins']],['Aggiornate',$report['upd']],['Eliminate saltate',$report['skip_deleted']],['Senza codice',$report['skip']]] as [$k,$v]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px"><div style="font-size:20px;font-weight:800"><?=$v?></div><div style="font-size:10px;color:var(--muted);font-weight:700"><?=$k?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($conn_msg): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid <?= $conn_msg['ok'] ? '#16a34a' : '#f59e0b' ?>">
    <div class="card-header"><span class="card-title">
      <i class="fa-solid fa-<?= $conn_msg['ok'] ? 'circle-check' : 'triangle-exclamation' ?>"></i>
      Connessione riuscita — server <?= h($conn_msg['server']) ?>
      <?php if (!empty($conn_msg['schema'])): ?>, schema <code><?= h($conn_msg['schema']) ?></code><?php endif; ?>
    </span></div>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:8px 0">
      <?php foreach ([
        ['Oggetti nello schema', (int)($conn_msg['oggetti'] ?? 0), '#334155'],
        ['Tabelle usate dai dataset', (int)($conn_msg['usate'] ?? 0), '#334155'],
        ['Dataset utilizzabili', ($conn_msg['ok_count'] ?? 0) . ' / ' . ($conn_msg['tot_dataset'] ?? 0),
          ($conn_msg['ok'] ?? false) ? '#16a34a' : '#f59e0b'],
        ['Tabelle assenti', count($conn_msg['assenti'] ?? []),
          empty($conn_msg['assenti']) ? '#94a3b8' : '#dc2626'],
      ] as [$k, $v, $c]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px">
          <div style="font-size:20px;font-weight:800;color:<?=$c?>"><?=$v?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700"><?=$k?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($conn_msg['assenti'])): ?>
      <div class="alert alert-danger" style="font-size:12px">
        Tabelle richieste dai dataset ma <strong>non presenti</strong> nella sorgente:
        <code><?= h(implode(', ', $conn_msg['assenti'])) ?></code>.
        Verificare lo schema indicato: potrebbero essere state rinominate.
      </div>
    <?php endif; ?>

    <div style="overflow-x:auto">
      <table class="data-table" style="width:100%;font-size:12px">
        <thead><tr>
          <th>Dataset</th><th style="text-align:right">Tabelle</th>
          <th style="text-align:right">Colonne</th><th>Esito</th><th>Dettaglio</th>
        </tr></thead>
        <tbody>
        <?php foreach (($conn_msg['per_dataset'] ?? []) as $d):
          $ok = $d['esito'] === 'ok';
          $col = $ok ? '#16a34a' : ($d['esito'] === 'tabelle mancanti' ? '#dc2626' : '#f59e0b');
        ?>
          <tr>
            <td style="font-weight:600"><?= h($d['label']) ?></td>
            <td style="text-align:right"><?= count($d['tabelle']) ?></td>
            <td style="text-align:right"><?= isset($d['colonne']) ? (int)$d['colonne'] : '—' ?></td>
            <td><span style="color:<?=$col?>;font-weight:700"><?= h($d['esito']) ?></span></td>
            <td style="font-size:11px;color:var(--muted)">
              <?php if (!empty($d['assenti'])): ?>
                assenti: <code><?= h(implode(', ', $d['assenti'])) ?></code>
              <?php elseif (!empty($d['mancanti'])): ?>
                colonne non prodotte: <code><?= h(implode(', ', $d['mancanti'])) ?></code>
              <?php elseif (!empty($d['errore'])): ?>
                <?= h(mb_strimwidth((string)$d['errore'], 0, 110, '…')) ?>
              <?php else: ?>
                query eseguita correttamente
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="margin-top:10px;font-size:12px;color:<?= $conn_msg['ok'] ? '#15803d' : '#b45309' ?>">
      <?php if ($conn_msg['ok']): ?>
        Tutti i dataset sono utilizzabili: si può procedere con la
        <a href="<?= url_safe('sync_commesse') ?>">sincronizzazione completa</a>.
      <?php else: ?>
        <strong>Alcuni dataset non sono utilizzabili.</strong> Gli altri funzionano comunque:
        la sincronizzazione completa prosegue sui dataset validi e riporta l'errore per quelli in difetto.
      <?php endif; ?>
    </div>

    <p style="color:var(--muted);font-size:11px;margin-top:8px">
      La verifica esegue la query di ciascun dataset con <code>LIMIT 0</code>: è l'unica prova che
      quella query funzionerà davvero sulla sorgente. Fino alla v1.8.61 veniva controllata una sola
      tabella, quella indicata qui sotto, cercandovi le colonne <code>code</code> e <code>name</code> —
      un residuo di quando la sincronizzazione leggeva una tabella sola, che segnalava colonne mancanti
      anche a sorgente perfettamente funzionante.
    </p>
  </div>
<?php endif; ?>p endif; ?>

<?php if ($sync_rep): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid #16a34a">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-check"></i>
      Sincronizzazione completata (batch #<?= (int)$sync_rep['batch'] ?>)</span></div>
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;padding:10px 0">
      <?php foreach ([['Righe lette',$sync_rep['total']],['Nuove',$sync_rep['ins']],['Aggiornate',$sync_rep['upd']],
                      ['Eliminate saltate',$sync_rep['skip_deleted']],['Senza codice',$sync_rep['skip']],
                      ['Segnaposto assorbiti',$sync_rep['absorbed']]] as [$k,$v]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px">
          <div style="font-size:20px;font-weight:800"><?= (int)$v ?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700"><?= $k ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($preview): ?>
  <div class="card" style="margin-bottom:16px;border:2px solid #3b82f6">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-eye"></i>
      Anteprima — prime <?= count($preview['preview']) ?> righe di <?= (int)$preview['total'] ?> lette
      (<?= (int)$preview['ins'] ?> da inserire, <?= (int)$preview['upd'] ?> da aggiornare)</span></div>
    <div style="overflow-x:auto">
      <table class="data-table" style="width:100%;font-size:11px;white-space:nowrap">
        <thead><tr><th>Azione</th><th>Codice</th><th>Nome</th><th>Cliente</th><th>Stato</th>
          <th>Inizio</th><th>Fine</th><th style="text-align:right">Valore</th></tr></thead>
        <tbody>
        <?php foreach ($preview['preview'] as $r): ?>
          <tr>
            <td><span style="color:<?= $r['azione']==='inserisce' ? '#16a34a' : '#0891b2' ?>;font-weight:700"><?= h($r['azione']) ?></span></td>
            <td style="font-weight:600"><?= h($r['code']) ?></td>
            <td><?= h(mb_strimwidth((string)$r['name'], 0, 40, '…')) ?></td>
            <td><?= h(mb_strimwidth((string)$r['client'], 0, 30, '…')) ?></td>
            <td><?= h((string)$r['status']) ?></td>
            <td><?= $r['start'] ? date('d/m/Y', strtotime($r['start'])) : '—' ?></td>
            <td><?= $r['end'] ? date('d/m/Y', strtotime($r['end'])) : '—' ?></td>
            <td style="text-align:right"><?= $r['value'] !== null ? number_format((float)$r['value'], 2, ',', '.') . ' €' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="color:var(--muted);font-size:11px;margin-top:8px">Nessun dato è stato scritto: questa è una simulazione.</p>
  </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-plug"></i> Connessione diretta al gestionale</span></div>

  <?php if (!$drivers): ?>
    <div class="alert alert-danger" style="font-size:12px">
      Nessun driver PDO per database esterni risulta caricato su questo server. Abilitare in
      <code>php.ini</code> almeno una fra <code>pdo_mysql</code>, <code>pdo_sqlsrv</code>,
      <code>pdo_dblib</code>, <code>pdo_pgsql</code> e riavviare Apache.
    </div>
  <?php else: ?>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_conn">
    <div class="form-group" style="margin:0"><label>Etichetta</label>
      <input type="text" name="label" value="<?= h($srcCfg['label'] ?? 'Gestionale') ?>"></div>
    <div class="form-group" style="margin:0"><label>Tipo database *</label>
      <select name="driver" id="drvSel">
        <?php foreach ($drivers as $k => $d): ?>
          <option value="<?= h($k) ?>" data-port="<?= (int)$d['port'] ?>"
            <?= ($srcCfg['driver'] ?? 'mysql') === $k ? 'selected' : '' ?>><?= h($d['label']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="form-group" style="margin:0"><label>Indirizzo (host o IP) *</label>
      <input type="text" name="host" id="hostIn" required value="<?= h($srcCfg['host'] ?? '') ?>" placeholder="192.168.1.10"></div>
    <div class="form-group" style="margin:0"><label>Porta *</label>
      <input type="number" name="port" id="portIn" required value="<?= (int)($srcCfg['port'] ?? 3306) ?>"></div>
    <div class="form-group" style="margin:0"><label>Nome database *</label>
      <input type="text" name="dbname" required value="<?= h($srcCfg['dbname'] ?? '') ?>"></div>
    <div class="form-group" style="margin:0"><label>Utente *</label>
      <input type="text" name="username" required autocomplete="off" value="<?= h($srcCfg['username'] ?? '') ?>"></div>
    <div class="form-group" style="margin:0"><label>Password<?= $srcCfg && $srcCfg['password_enc'] ? ' (già impostata)' : ' *' ?></label>
      <input type="password" name="password" autocomplete="new-password"
             placeholder="<?= $srcCfg && $srcCfg['password_enc'] ? 'lascia vuoto per non modificarla' : '' ?>"
             <?= $srcCfg && $srcCfg['password_enc'] ? '' : 'required' ?>></div>
    <div class="form-group" style="margin:0"><label>Timeout (secondi)</label>
      <input type="number" name="timeout" min="3" max="60" value="<?= (int)($srcCfg['timeout'] ?? 10) ?>"></div>
    <div class="form-group" style="margin:0"><label>Schema (opzionale)</label>
      <input type="text" name="source_schema" value="<?= h($srcCfg['source_schema'] ?? '') ?>" placeholder="dbo"></div>
    <div class="form-group" style="margin:0"><label>Tabella sorgente *</label>
      <input type="text" name="source_table" required value="<?= h($srcCfg['source_table'] ?? 'contract') ?>"></div>
    <div class="form-group" style="margin:0;grid-column:span 2;display:flex;align-items:flex-end">
      <label style="display:flex;gap:6px;align-items:center;font-size:12px;padding-bottom:8px">
        <input type="checkbox" name="include_deleted_db" value="1" <?= (int)($srcCfg['include_deleted'] ?? 0) === 1 ? 'checked' : '' ?>>
        sincronizza anche le commesse marcate come eliminate
      </label></div>
    <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva parametri</button>
      <?php if ($srcCfg): ?>
        <span style="color:var(--muted);font-size:11px;margin-left:auto">
          <?php if ($srcCfg['last_sync_at']): ?>
            Ultima sincronizzazione: <strong><?= date('d/m/Y H:i', strtotime($srcCfg['last_sync_at'])) ?></strong>
            — <?= h((string)$srcCfg['last_sync_note']) ?>
          <?php else: ?>Mai sincronizzato.<?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($srcCfg): ?>
    <div style="display:flex;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;flex-wrap:wrap">
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="action" value="test_conn">
        <button class="btn"><i class="fa-solid fa-plug-circle-check"></i> Prova connessione</button></form>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">
        <button class="btn"><i class="fa-solid fa-eye"></i> Anteprima (nessuna scrittura)</button></form>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Avviare la sincronizzazione? Le commesse presenti verranno aggiornate con i dati del gestionale.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="sync">
        <button class="btn btn-success"><i class="fa-solid fa-rotate"></i> Sincronizza ora</button></form>
    </div>
  <?php endif; ?>

  <div style="background:#f0fdf4;border:1px solid #86efac;padding:12px;border-radius:8px;margin-top:14px;font-size:11px;color:#166534">
    <strong>Sicurezza.</strong> La password è salvata cifrata con AES-256-GCM e non viene mai restituita al browser.
    La connessione apre una sessione di <strong>sola lettura</strong> e sono ammesse esclusivamente istruzioni
    <code>SELECT</code>. Si raccomanda comunque di usare sul gestionale un'utenza dedicata con privilegi di sola
    lettura sulla tabella <code><?= h($srcCfg['source_table'] ?? 'contract') ?></code>.
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    Procedura consigliata: salvare i parametri, usare <em>Prova connessione</em> per verificare che le colonne
    attese siano presenti, poi <em>Anteprima</em> per vedere che cosa verrebbe scritto e infine
    <em>Sincronizza ora</em>.
  </p>
  <script>
  (function () {
    var sel = document.getElementById('drvSel'), port = document.getElementById('portIn');
    if (!sel || !port) return;
    sel.addEventListener('change', function () {
      var p = this.options[this.selectedIndex].getAttribute('data-port');
      if (p) port.value = p;   // porta predefinita del driver scelto
    });
  })();
  </script>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-csv"></i> Carica export CSV</span></div>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?>
    <div class="form-group" style="margin:0;flex:1;min-width:280px"><label>File CSV del gestionale (separatore <code>|</code>)</label><input type="file" name="file" accept=".csv,.txt" required></div>
    <label style="display:flex;gap:6px;align-items:center;padding-bottom:8px"><input type="checkbox" name="include_deleted" value="1"> importa anche le commesse marcate come eliminate</label>
    <button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Importa</button>
  </form>
  <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:12px;border-radius:8px;margin-top:14px;font-size:12px;color:#1e40af">
    <strong>Mappatura principale:</strong> <code>code</code>→codice commessa, <code>name</code>→nome, <code>customer_comp_name</code>→cliente,
    <code>status</code>→stato (OPEN/CLOSED/SUSPENDED → Aperta/Chiusa/Sospesa), <code>economic_value</code>/<code>_till_now</code>→valore/valore a oggi,
    <code>margin_value</code>, <code>residual_value</code>, <code>time_material_costs</code>→consuntivato, date, anomalie. Azienda esecutrice dal prefisso del codice.
  </div>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">Lettura in streaming: la dimensione del file non incide sulla memoria. I dati caricati non vengono conservati oltre l'elaborazione. Le righe con <code>deleted=1</code> sono saltate salvo diversa indicazione.</p>
</div>
<?php require_once('footer.php'); ?>
