<?php
/**
 * PortalManager — sync_commesse.php  (v1.8.46)
 *
 * Sincronizzazione dei dati di Gestione Commesse dal gestionale.
 *
 * Una sola pagina per tre dataset — commesse, rapporti di intervento,
 * professionisti — e due origini intercambiabili:
 *
 *   · connessione diretta al database del gestionale, con le stesse query degli
 *     exporter ufficiali;
 *   · file CSV con le medesime intestazioni, cioè quello che quegli exporter
 *     producono.
 *
 * Entrambe convergono su DatasetSync::writeRows(), quindi il risultato è
 * identico: il CSV è un'alternativa reale, non un percorso parallelo.
 *
 * Le tabelle e le colonne sorgente sono dichiarate in app/SyncDatasets.php, che
 * è la fonte unica da cui derivano query, intestazioni CSV e mappatura.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/ProjectModel.php');
require_once(__DIR__ . '/app/PrefixResolver.php');
require_once(__DIR__ . '/app/SourceDb.php');
require_once(__DIR__ . '/app/SyncDatasets.php');
require_once(__DIR__ . '/app/DatasetSync.php');

if (!can('view', 'sync_commesse.php')) { redirect('manage_projects'); }
$u_id     = (int)$_SESSION['user_id'];
$can_run  = can('create', 'sync_commesse.php');
$model    = new ProjectModel($pdo);
$prefix   = new PrefixResolver($pdo);
$sync     = new DatasetSync($pdo, $model, $prefix);
$datasets = SyncDatasets::all();

$srcCfg = null;
try {
    $srcCfg = $pdo->query("SELECT * FROM cm_source_db WHERE is_active=1 ORDER BY id DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { /* migration non ancora eseguita */ }
$drivers = SourceDb::availableDrivers();

/** Configurazione di connessione a partire dalla riga salvata. */
$buildCfg = function (array $c): array {
    return [
        'driver'   => $c['driver'], 'host' => $c['host'], 'port' => (int)$c['port'],
        'dbname'   => $c['dbname'], 'username' => $c['username'],
        'password' => SourceDb::decrypt((string)$c['password_enc']),
        'timeout'  => (int)$c['timeout'],
    ];
};

// ── Download del tracciato CSV ──────────────────────────────────────────────
// Le intestazioni provengono dal registro, le stesse che il parser riconosce.
$tpl = trim((string)($_GET['template'] ?? ''));
if ($tpl !== '' && isset($datasets[$tpl])) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    $headers = SyncDatasets::headers($tpl);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tracciato_' . $tpl . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';', '"');
    fclose($out);
    write_log('Projects', 'info', "Download tracciato CSV dataset $tpl (" . count($headers) . " colonne)", $u_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (UploadGuard::postDiscarded()) { $_SESSION['flash_msg'] = UploadGuard::discardedMessage(); redirect_self(); }
    Csrf::verify();
    if (!$can_run) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    @set_time_limit(0);

    $action = (string)($_POST['action'] ?? '');
    $ds     = (string)($_POST['dataset'] ?? '');
    if ($ds !== '' && !isset($datasets[$ds])) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Dataset non valido.</div>"; redirect_self(); }

    try {
        // ── v1.8.49: analisi dell'INTERO database sorgente ───────────────────
        // Non basta sapere che le tabelle dei dataset esistono: prima di
        // sincronizzare occorre vedere che cosa la sorgente contiene davvero.
        // Una tabella rinominata dal fornitore, o una vista di export aggiunta,
        // passerebbero altrimenti inosservate fino al primo errore di import.
        /**
 * v1.9.4 — registra una sincronizzazione MANUALE nello stato pianificato.
 *
 * Prima solo `cron_sync.php` aggiornava `cm_sync_schedule`. Una sincronizzazione
 * lanciata a mano aggiornava i dati ma lasciava lo stato fermo all'ultima
 * esecuzione automatica: il riquadro in home continuava a segnalare "in ritardo"
 * su dati appena aggiornati, e chi lo leggeva concludeva che il sistema fosse
 * fermo.
 *
 * `trigger_type = 'manuale'` distingue le due origini: un'esecuzione manuale
 * quotidiana e una pianificata che funziona sono situazioni diverse, e appiattirle
 * nasconderebbe una pianificazione guasta.
 *
 * NON tocca `lock_owner`: il lock appartiene al cron, e prenderlo qui bloccherebbe
 * l'esecuzione automatica successiva senza motivo.
 *
 * Fallisce in silenzio se le tabelle della v1.8.75 non esistono: la
 * sincronizzazione e' gia' avvenuta, e un errore qui la farebbe sembrare fallita.
 */
$registraEsecuzione = function (PDO $pdo, string $stato, string $nota,
                                float $secondi, array $tot = [], int $err = 0): void {
    try {
        $pdo->prepare(
            "INSERT INTO `cm_sync_schedule_log`
                (`started_at`, `finished_at`, `trigger_type`, `status`,
                 `datasets_ok`, `datasets_err`, `rows_read`, `rows_new`,
                 `rows_updated`, `rows_removed`, `seconds`, `note`)
             VALUES (DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), 'manuale', ?,
                     ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([(int)round($secondi), $stato,
                       (int)($tot['datasets_ok'] ?? 0), $err,
                       (int)($tot['total'] ?? 0), (int)($tot['ins'] ?? 0),
                       (int)($tot['upd'] ?? 0), (int)($tot['removed'] ?? 0),
                       round($secondi, 1), mb_substr($nota, 0, 500)]);

        $pdo->prepare(
            "UPDATE `cm_sync_schedule`
                SET `last_run_at` = NOW(), `last_status` = ?, `last_note` = ?, `last_seconds` = ?
              WHERE `id` = 1")
            ->execute([$stato, mb_substr($nota, 0, 500), round($secondi, 1)]);
    } catch (Throwable $e) {
        // le tabelle della v1.8.75 potrebbero non esserci: la sincronizzazione
        // e' comunque avvenuta e non va segnalata come fallita
    }
};

if ($action === 'analyze') {
            if (!$srcCfg) throw new Exception('Configurare prima la connessione in Import Commesse DB.');
            $src = SourceDb::connect($buildCfg($srcCfg));
            $inv = $src->inventory((string)($srcCfg['source_schema'] ?? ''));
            $cov = SyncDatasets::coverage($inv);
            write_log('Projects', 'info', sprintf(
                'Analisi sorgente: %d oggetti, %d usati dai dataset, %d mancanti',
                count($inv), count($cov['used']), count($cov['missing'])), $u_id);
            $_SESSION['flash_inv'] = ['inventory' => $inv, 'coverage' => $cov,
                                      'server' => $src->serverVersion(), 'schema' => $src->currentSchema()];
            redirect_self();
        }

        // ── da connessione diretta ──────────────────────────────────────────
        // ── v1.8.57: sincronizzazione completa in un'unica azione ───────────
        //
        // Sincronizzare un dataset alla volta obbliga a ricordare l'ordine
        // giusto e a non dimenticarne nessuno. L'ordine conta: rapporti,
        // tariffe e allocazioni si agganciano alle commesse per codice, quindi
        // le commesse vanno lette per prime. Sbagliarlo non perde dati — gli
        // agganci avvengono alla passata successiva — ma lascia righe scollegate
        // fino ad allora, e chi guarda subito dopo vede un'analisi incompleta.
        //
        // La connessione alla sorgente e' aperta UNA volta per tutti i dataset.
        // v1.8.67 — anteprima su TUTTE le righe, in streaming.
        // Non usa readSource(), che accumulerebbe oltre 100 MB sui volumi reali:
        // legge riga per riga con memoria costante.
        // v1.8.71 — riconciliazione: rimuove dal portale cio' che il gestionale
        // non conosce piu'. La sincronizzazione aggiunge e aggiorna ma non
        // rimuove, ed e' cosi' che il consuntivo si era riempito di 67.786
        // rapporti fantasma (v1.8.70).
        // v1.8.75 — configurazione della sincronizzazione pianificata
        if ($action === 'save_schedule') {
            $ore = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['run_at'] ?? ''))
                 ? $_POST['run_at'] . ':00' : '02:00:00';
            $gg = array_values(array_filter(array_map('intval', (array)($_POST['days'] ?? [])),
                                            fn($d) => $d >= 1 && $d <= 7));
            if (!$gg) $gg = [1,2,3,4,5,6,7];
            $fin = max(15, min(720, (int)($_POST['window_minutes'] ?? 120)));

            $pdo->prepare(
                "UPDATE `cm_sync_schedule`
                    SET `is_enabled` = ?, `run_at` = ?, `days_mask` = ?, `window_minutes` = ?, `reconcile` = ?
                  WHERE `id` = 1")
                ->execute([empty($_POST['is_enabled']) ? 0 : 1, $ore, implode(',', $gg), $fin,
                           empty($_POST['reconcile']) ? 0 : 1]);

            write_log('Projects', 'info', sprintf('Pianificazione sincronizzazione %s alle %s (%s)',
                empty($_POST['is_enabled']) ? 'disattivata' : 'attivata', substr($ore, 0, 5),
                implode(',', $gg)), $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Pianificazione salvata.</div>";
            redirect_self();
        }

        if ($action === 'reconcile_check' || $action === 'reconcile_apply') {
            if (!$srcCfg) throw new Exception('Configurare prima la connessione.');
            $dry = ($action === 'reconcile_check');
            $src = SourceDb::connect($buildCfg($srcCfg));

            $esiti = []; $t0 = microtime(true);
            foreach (SyncDatasets::syncOrder() as $k) {
                try {
                    $r = $sync->reconcile($src, $k, $u_id, $dry);
                    $r['dataset'] = $k; $r['label'] = $datasets[$k]['label'] ?? $k;
                    $r['esito'] = 'ok';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
                    $r = ['dataset' => $k, 'label' => $datasets[$k]['label'] ?? $k,
                          'esito' => 'errore', 'errore' => $e->getMessage(),
                          'total_src' => 0, 'total_dst' => 0, 'orphans' => 0,
                          'removed' => 0, 'protected' => 0, 'samples' => [], 'secondi' => 0];
                }
                $esiti[] = $r;
            }
            $tot = ['total_src'=>0,'total_dst'=>0,'orphans'=>0,'removed'=>0,'protected'=>0]; $err = 0;
            foreach ($esiti as $r) {
                foreach (array_keys($tot) as $c) $tot[$c] += (int)($r[$c] ?? 0);
                if ($r['esito'] === 'errore') $err++;
            }
            write_log('Projects', $dry ? 'info' : 'warning', sprintf(
                'Riconciliazione %s: %d orfane su %d righe, %d rimosse',
                $dry ? '(verifica)' : '(applicata)', $tot['orphans'], $tot['total_dst'], $tot['removed']), $u_id);

            $_SESSION['flash_rec'] = ['esiti' => $esiti, 'tot' => $tot, 'err' => $err,
                                      'dry' => $dry, 'secondi' => round(microtime(true) - $t0, 1)];
            redirect_self();
        }

        if ($action === 'preview_full') {
            if (!$srcCfg) throw new Exception('Configurare prima la connessione.');
            $src = SourceDb::connect($buildCfg($srcCfg));
            $esiti = []; $t0 = microtime(true);
            foreach (SyncDatasets::syncOrder() as $k) {
                try {
                    $r = $sync->previewAll($src, $k);
                    $r['dataset'] = $k; $r['label'] = $datasets[$k]['label'] ?? $k;
                    $r['esito'] = 'ok';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
                    $r = ['dataset' => $k, 'label' => $datasets[$k]['label'] ?? $k,
                          'esito' => 'errore', 'errore' => $e->getMessage(),
                          'total' => 0, 'ins' => 0, 'upd' => 0, 'skip' => 0,
                          'linked' => 0, 'absorbed' => 0, 'secondi' => 0];
                }
                $esiti[] = $r;
            }
            $tot = ['total'=>0,'ins'=>0,'upd'=>0,'skip'=>0,'linked'=>0,'absorbed'=>0]; $err = 0;
            foreach ($esiti as $r) {
                foreach (array_keys($tot) as $c) $tot[$c] += (int)($r[$c] ?? 0);
                if ($r['esito'] === 'errore') $err++;
            }
            write_log('Projects', 'info', sprintf('Anteprima completa su tutte le righe: %d lette in %.1fs',
                $tot['total'], microtime(true) - $t0), $u_id);
            $_SESSION['flash_all'] = ['esiti' => $esiti, 'tot' => $tot, 'err' => $err,
                                      'dry' => true, 'integrale' => true,
                                      'secondi' => round(microtime(true) - $t0, 1)];
            redirect_self();
        }

        if ($action === 'sync_all' || $action === 'preview_all') {
            if (!$srcCfg) throw new Exception('Configurare prima la connessione in Import Commesse DB.');
            // v1.8.65 — l'azione determina se si scrive o no: va letta in modo
            // esplicito e non per esclusione. `$dry = ($action !== 'sync_all')`
            // sembrerebbe equivalente, ma tratterebbe come anteprima anche un
            // valore imprevisto — cioe' fallirebbe in modo silenzioso proprio
            // nel caso in cui qualcosa e' andato storto nella trasmissione.
            $dry = ($action === 'preview_all');
            write_log('Projects', 'info', 'Sync completa richiesta in modalita '
                . ($dry ? 'anteprima' : 'scrittura'), $u_id);
            $src = SourceDb::connect($buildCfg($srcCfg));
            $origine = sprintf('%s@%s/%s', $srcCfg['driver'], $srcCfg['host'], $srcCfg['dbname']);

            $esiti = []; $t0 = microtime(true);
            foreach (SyncDatasets::syncOrder() as $k) {
                $tk = microtime(true);
                try {
                    $rows  = $sync->readSource($src, $k, $dry ? 200 : 0);
                    $batch = $dry ? 0 : $sync->openBatch($k, $origine, $u_id);
                    $r     = $sync->writeRows($k, $rows, $u_id, $dry, $batch);
                    if (!$dry) $sync->closeBatch($batch, $r);
                    $r['dataset'] = $k;
                    $r['label']   = $datasets[$k]['label'] ?? $k;
                    $r['secondi'] = round(microtime(true) - $tk, 1);
                    $r['esito']   = 'ok';
                    $esiti[] = $r;
                } catch (Throwable $e) {
                    // v1.8.64 — rete di sicurezza: se il dataset ha lasciato una
                    // transazione aperta, va chiusa qui prima di proseguire.
                    // Il rollback e' ora anche dentro DatasetSync, ma l'errore
                    // puo' arrivare da openBatch o readSource, fuori da quel
                    // try. Una transazione appesa farebbe fallire tutti i
                    // dataset successivi con "There is already an active
                    // transaction", che e' esattamente il difetto osservato.
                    if ($pdo->inTransaction()) {
                        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
                    }
                    // un dataset che fallisce non ferma gli altri: interrompere
                    // lascerebbe l'insieme a meta', con una parte aggiornata e
                    // una no, che e' lo stato peggiore da diagnosticare
                    $esiti[] = ['dataset' => $k, 'label' => $datasets[$k]['label'] ?? $k,
                                'esito' => 'errore', 'errore' => $e->getMessage(),
                                'total' => 0, 'ins' => 0, 'upd' => 0, 'skip' => 0,
                                'linked' => 0, 'absorbed' => 0,
                                'secondi' => round(microtime(true) - $tk, 1)];
                }
            }

            $tot = ['total' => 0, 'ins' => 0, 'upd' => 0, 'skip' => 0, 'linked' => 0, 'absorbed' => 0];
            $err = 0;
            foreach ($esiti as $r) {
                foreach (array_keys($tot) as $c) $tot[$c] += (int)($r[$c] ?? 0);
                if ($r['esito'] === 'errore') $err++;
            }

            if (!$dry) {
                $pdo->prepare("UPDATE cm_source_db SET last_sync_at=NOW(), last_sync_note=? WHERE id=?")
                    ->execute([sprintf('Completa: %d dataset, %d lette, %d nuove, %d aggiornate%s',
                        count($esiti), $tot['total'], $tot['ins'], $tot['upd'],
                        $err ? ", $err in errore" : ''), (int)$srcCfg['id']]);
                write_log('Projects', $err ? 'warning' : 'success', sprintf(
                    'Sync completa: %d dataset in %.1fs, %d lette, %d nuove, %d aggiornate, %d in errore',
                    count($esiti), microtime(true) - $t0, $tot['total'], $tot['ins'], $tot['upd'], $err), $u_id);

                // v1.9.4 — lo stato pianificato riflette anche le esecuzioni manuali
                $registraEsecuzione($pdo,
                    $err === 0 ? 'ok' : (count($esiti) > $err ? 'parziale' : 'errore'),
                    sprintf('Manuale: %d dataset, %d lette, %d nuove, %d aggiornate%s',
                        count($esiti), $tot['total'], $tot['ins'], $tot['upd'],
                        $err ? ", $err in errore" : ''),
                    microtime(true) - $t0,
                    $tot + ['datasets_ok' => count($esiti) - $err], $err);
            }

            $_SESSION['flash_all'] = ['esiti' => $esiti, 'tot' => $tot, 'err' => $err,
                                      'dry' => $dry, 'secondi' => round(microtime(true) - $t0, 1)];
            redirect_self();
        }

        if ($action === 'sync_db' || $action === 'preview_db') {
            // v1.9.4 — misura la durata per lo stato pianificato
            $t0 = microtime(true);
            if (!$srcCfg) throw new Exception('Configurare prima la connessione in Import Commesse DB.');
            $dry = ($action === 'preview_db');
            $src = SourceDb::connect($buildCfg($srcCfg));
            $rows = $sync->readSource($src, $ds, $dry ? 200 : 0);

            $batch = $dry ? 0 : $sync->openBatch($ds, sprintf('%s@%s/%s',
                $srcCfg['driver'], $srcCfg['host'], $srcCfg['dbname']), $u_id);
            $rep = $sync->writeRows($ds, $rows, $u_id, $dry, $batch);
            if (!$dry) {
                $sync->closeBatch($batch, $rep);
                $pdo->prepare("UPDATE cm_source_db SET last_sync_at=NOW(), last_sync_note=? WHERE id=?")
                    ->execute([sprintf('%s: %d lette, %d nuove, %d aggiornate',
                        $datasets[$ds]['label'], $rep['total'], $rep['ins'], $rep['upd']), (int)$srcCfg['id']]);
                write_log('Projects', 'success', sprintf(
                    'Sync %s da DB: %d lette, %d nuove, %d aggiornate, %d senza chiave, %d agganciate, %d segnaposto assorbiti',
                    $ds, $rep['total'], $rep['ins'], $rep['upd'], $rep['skip'], $rep['linked'], $rep['absorbed']), $u_id);

                // v1.9.4 — anche il singolo dataset aggiorna lo stato: e' comunque
                // una sincronizzazione avvenuta, e lo stato deve dirlo. La nota
                // riporta QUALE dataset, cosi' chi legge distingue un aggiornamento
                // parziale da uno completo.
                // 'dataset' e non 'parziale': il cron salta la giornata quando
                // trova last_status IN ('ok','parziale'), e aver aggiornato UNA
                // tabella non e' aver sincronizzato. Con 'parziale' un import
                // singolo avrebbe silenziosamente sospeso la sincronizzazione
                // automatica di quel giorno.
                $registraEsecuzione($pdo, 'dataset',
                    sprintf('Manuale, solo %s: %d lette, %d nuove, %d aggiornate',
                        $datasets[$ds]['label'] ?? $ds, $rep['total'], $rep['ins'], $rep['upd']),
                    microtime(true) - $t0,
                    ['datasets_ok' => 1, 'total' => $rep['total'],
                     'ins' => $rep['ins'], 'upd' => $rep['upd']], 0);
            }
            $rep['dataset'] = $ds; $rep['origin'] = 'Connessione diretta'; $rep['dry'] = $dry;
            $_SESSION['flash_rep'] = $rep;
            redirect_self();
        }

        // ── da file CSV ─────────────────────────────────────────────────────
        if ($action === 'sync_csv' || $action === 'preview_csv') {
            // v1.9.4 — misura la durata per lo stato pianificato
            $t0 = microtime(true);
            if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) throw new Exception($err);
            $dry = ($action === 'preview_csv');
            [$rows, $known, $unknown] = DatasetSync::readCsv($_FILES['file']['tmp_name'], $ds);

            $batch = $dry ? 0 : $sync->openBatch($ds, (string)$_FILES['file']['name'], $u_id);
            $rep = $sync->writeRows($ds, $rows, $u_id, $dry, $batch);
            if (!$dry) {
                $sync->closeBatch($batch, $rep);
                write_log('Projects', 'success', sprintf(
                    'Sync %s da CSV "%s": %d lette, %d nuove, %d aggiornate',
                    $ds, $_FILES['file']['name'], $rep['total'], $rep['ins'], $rep['upd']), $u_id);

                // v1.9.4 — l'import da CSV non e' una sincronizzazione dal
                // gestionale: aggiorna lo stato ma resta distinguibile nella nota,
                // perche' i dati arrivano da un file e non dalla sorgente.
                $registraEsecuzione($pdo, 'dataset',
                    sprintf('Manuale da CSV, solo %s: %d lette, %d nuove, %d aggiornate',
                        $datasets[$ds]['label'] ?? $ds, $rep['total'], $rep['ins'], $rep['upd']),
                    microtime(true) - $t0,
                    ['datasets_ok' => 1, 'total' => $rep['total'],
                     'ins' => $rep['ins'], 'upd' => $rep['upd']], 0);
            }
            $rep['dataset'] = $ds; $rep['origin'] = 'File CSV'; $rep['dry'] = $dry;
            $rep['known'] = $known; $rep['unknown'] = $unknown;
            $_SESSION['flash_rep'] = $rep;
            redirect_self();
        }

        throw new Exception('Azione non riconosciuta.');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        write_log('Projects', 'error', "Sync $ds: " . $e->getMessage(), $u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> "
            . h($e->getMessage()) . "</div>";
        redirect_self();
    }
}

$msg = ''; $rep = null; $inv = null;
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (!empty($_SESSION['flash_rep'])) { $rep = $_SESSION['flash_rep']; unset($_SESSION['flash_rep']); }
if (!empty($_SESSION['flash_inv'])) { $inv = $_SESSION['flash_inv']; unset($_SESSION['flash_inv']); }
$repAll = null;
if (!empty($_SESSION['flash_all'])) { $repAll = $_SESSION['flash_all']; unset($_SESSION['flash_all']); }
$repRec = null;
if (!empty($_SESSION['flash_rec'])) { $repRec = $_SESSION['flash_rec']; unset($_SESSION['flash_rec']); }

// v1.8.75 — stato della pianificazione
$sched = null; $schedLog = [];
try {
    $sched = $pdo->query("SELECT * FROM `cm_sync_schedule` WHERE `id` = 1")->fetch(PDO::FETCH_ASSOC);
    $schedStato = $pdo->query("SELECT * FROM `v_cm_sync_schedule_stato`")->fetch(PDO::FETCH_ASSOC);
    $schedLog = $pdo->query("SELECT * FROM `cm_sync_schedule_log`
                              ORDER BY `started_at` DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $sched = null; $schedStato = null; }

require_once('header.php');
?>
<div class="page-header">
  <h1><i class="fa-solid fa-rotate"></i> Sincronizzazione gestionale</h1>
  <p style="color:var(--muted);font-size:13px">
    Aggiorna i dati di Gestione Commesse leggendo dal database del gestionale oppure da un file CSV
    con lo stesso tracciato. Scrittura per chiave naturale: ripetibile senza duplicati.
  </p>
</div>
<?= $msg ?>

<?php if ($rep): $d = $datasets[$rep['dataset']]; ?>
  <div class="card" style="margin-bottom:16px;border:2px solid <?= $rep['dry'] ? '#3b82f6' : '#16a34a' ?>">
    <div class="card-header"><span class="card-title">
      <i class="fa-solid fa-<?= $rep['dry'] ? 'eye' : 'circle-check' ?>"></i>
      <?= $rep['dry'] ? 'Anteprima' : 'Sincronizzazione completata' ?> —
      <?= h($d['label']) ?> · <?= h($rep['origin']) ?>
    </span></div>
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;padding:10px 0">
      <?php foreach ([['Righe lette', $rep['total']], [$rep['dry'] ? 'Da inserire' : 'Nuove', $rep['ins']],
                      [$rep['dry'] ? 'Da aggiornare' : 'Aggiornate', $rep['upd']],
                      ['Senza chiave', $rep['skip']], ['Agganciate a commessa', $rep['linked']],
                      ['Segnaposto assorbiti', $rep['absorbed']]] as [$k, $v]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px">
          <div style="font-size:20px;font-weight:800"><?= (int)$v ?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700"><?= $k ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($rep['unknown'])): ?>
      <div style="font-size:11px;color:#b45309;padding:6px 0">
        Colonne del file non riconosciute e ignorate: <code><?= h(implode(', ', $rep['unknown'])) ?></code>
      </div>
    <?php endif; ?>
    <?php if (!empty($rep['preview'])): ?>
      <div style="overflow-x:auto;margin-top:8px">
        <table class="data-table" style="width:100%;font-size:11px;white-space:nowrap">
          <thead><tr><th>Azione</th>
            <?php foreach (array_slice(array_keys($rep['preview'][0]), 1, 8) as $c): ?>
              <th><?= h($c) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($rep['preview'] as $r): $cells = array_slice($r, 1, 8, true); ?>
            <tr>
              <td><span style="color:<?= $r['azione'] === 'inserisce' ? '#16a34a' : '#0891b2' ?>;font-weight:700"><?= h($r['azione']) ?></span></td>
              <?php foreach ($cells as $v): ?>
                <td><?= h(mb_strimwidth((string)($v ?? ''), 0, 32, '…')) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="color:var(--muted);font-size:11px;margin-top:8px">Nessun dato è stato scritto: questa è una simulazione.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$srcCfg): ?>
  <div class="alert alert-warning" style="font-size:12px">
    <i class="fa-solid fa-circle-info"></i> Nessuna connessione al gestionale configurata: la sincronizzazione
    diretta non è disponibile. I parametri si impostano i parametri si impostano in <a href="<?= url_safe('import_commesse_db') ?>">Connessione al gestionale</a>.
    Il caricamento da CSV funziona comunque.
  </div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-magnifying-glass-chart"></i> Analisi del database sorgente</span>
      <?php if ($can_run): ?>
        <form method="post" style="margin-left:auto"><?= csrf_field() ?>
          <input type="hidden" name="action" value="analyze">
          <button class="btn btn-sm"><i class="fa-solid fa-list-check"></i> Analizza tutte le tabelle</button></form>
      <?php endif; ?>
    </div>
    <p style="font-size:12px;color:var(--muted);margin:4px 0 0">
      Esamina l'intero schema del gestionale, non solo le tabelle usate dai dataset: serve ad accorgersi
      di tabelle rinominate, rimosse o aggiunte prima che siano gli import a fallire.
      La lettura avviene sui cataloghi di sistema e non tocca i dati.
    </p>
  </div>
<?php endif; ?>

<?php if ($sched && $srcCfg): ?>
<?php
  $gAttivi = array_map('intval', array_filter(explode(',', (string)$sched['days_mask'])));
  $gNomi   = [1=>'lun', 2=>'mar', 3=>'mer', 4=>'gio', 5=>'ven', 6=>'sab', 7=>'dom'];
  $diag    = $schedStato['diagnosi'] ?? 'sconosciuta';
  $colDiag = match ($diag) {
      'regolare'    => '#16a34a',
      'disattivata' => '#94a3b8',
      'IN RITARDO', 'ultima esecuzione fallita' => '#dc2626',
      default       => '#f59e0b',
  };
?>
<div class="card" style="margin-bottom:16px;border-left:4px solid <?=$colDiag?>">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-clock"></i> Sincronizzazione giornaliera pianificata</span>
    <span style="background:<?=$colDiag?>;color:#fff;border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;margin-left:8px">
      <?=h($diag)?></span>
    <?php if (!empty($schedStato['in_esecuzione'])): ?>
      <span style="background:#2563eb;color:#fff;border-radius:10px;padding:2px 10px;font-size:11px;margin-left:6px">
        in esecuzione</span>
    <?php endif; ?>
  </div>

  <?php if (!empty($sched['last_run_at'])): ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px">
      <?php foreach ([
        ['Ultima esecuzione', date('d/m/Y H:i', strtotime((string)$sched['last_run_at'])), '#334155'],
        ['Esito', $sched['last_status'] ?? '—',
          ($sched['last_status'] ?? '') === 'ok' ? '#16a34a' : '#f59e0b'],
        ['Durata', ($sched['last_seconds'] !== null ? $sched['last_seconds'] . 's' : '—'), '#334155'],
        ['Errori (30 gg)', (int)($schedStato['errori_30gg'] ?? 0),
          (int)($schedStato['errori_30gg'] ?? 0) > 0 ? '#dc2626' : '#94a3b8'],
      ] as [$k, $v, $c]): ?>
        <div style="text-align:center;padding:10px;background:#f8fafc;border-radius:8px">
          <div style="font-size:15px;font-weight:800;color:<?=$c?>"><?=h((string)$v)?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700"><?=$k?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($sched['last_note'])): ?>
      <p style="font-size:11px;color:var(--muted);margin:0 0 10px"><?=h((string)$sched['last_note'])?></p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($can_run): ?>
  <form method="post" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;align-items:end">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_schedule">
    <div class="form-group" style="margin:0">
      <label style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="is_enabled" value="1" <?=$sched['is_enabled'] ? 'checked' : ''?>>
        <strong>Pianificazione attiva</strong>
      </label>
    </div>
    <div class="form-group" style="margin:0"><label>Ora di esecuzione</label>
      <input type="time" name="run_at" value="<?=h(substr((string)$sched['run_at'], 0, 5))?>"></div>
    <div class="form-group" style="margin:0"><label>Finestra di recupero (minuti)</label>
      <input type="number" name="window_minutes" min="15" max="720"
             value="<?=(int)$sched['window_minutes']?>"></div>
    <div class="form-group" style="margin:0">
      <label style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="reconcile" value="1" <?=$sched['reconcile'] ? 'checked' : ''?>>
        Riconcilia (rimuove le orfane)
      </label>
    </div>
    <div style="grid-column:1/-1;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <span style="font-size:12px;font-weight:600">Giorni:</span>
      <?php foreach ($gNomi as $n => $et): ?>
        <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px">
          <input type="checkbox" name="days[]" value="<?=$n?>" <?=in_array($n, $gAttivi, true) ? 'checked' : ''?>>
          <?=$et?>
        </label>
      <?php endforeach; ?>
      <button class="btn btn-primary btn-sm" style="margin-left:auto">
        <i class="fa-solid fa-floppy-disk"></i> Salva pianificazione</button>
    </div>
  </form>
  <?php endif; ?>

  <div class="alert alert-warning" style="font-size:11px;margin-top:12px">
    <strong>Serve un passo su Windows.</strong> Il portale decide <em>se</em> è il momento di
    sincronizzare, ma non può avviarsi da solo: qualcuno deve invocarlo. Creare un'attività
    nell'<strong>Utilità di pianificazione</strong> che esegua
    <code>P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_sync.php</code>
    <strong>ogni ora</strong>. Fuori dalla finestra prevista lo script termina subito, quindi
    l'attività oraria non comporta alcun carico — e l'orario si cambia da qui, senza toccare Windows.
  </div>

  <?php if ($schedLog): ?>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;font-size:12px;font-weight:600">Ultime esecuzioni</summary>
      <div style="overflow-x:auto;margin-top:8px">
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr>
            <th>Avvio</th><th>Tipo</th><th>Esito</th><th style="text-align:right">Dataset ok</th>
            <th style="text-align:right">Lette</th><th style="text-align:right">Nuove</th>
            <th style="text-align:right">Aggiornate</th><th style="text-align:right">Durata</th>
          </tr></thead>
          <tbody>
          <?php foreach ($schedLog as $l): ?>
            <tr<?= ($l['status'] ?? '') === 'errore' ? ' style="background:#fef2f2"' : '' ?>>
              <td><?=date('d/m H:i', strtotime((string)$l['started_at']))?></td>
              <td style="color:var(--muted)"><?=h((string)$l['trigger_type'])?></td>
              <td><span style="font-weight:700;color:<?=($l['status'] ?? '')==='ok'?'#16a34a':(($l['status'] ?? '')==='errore'?'#dc2626':'#f59e0b')?>">
                <?=h((string)($l['status'] ?? '—'))?></span></td>
              <td style="text-align:right"><?=(int)$l['datasets_ok']?><?= (int)$l['datasets_err'] ? ' / '.(int)$l['datasets_err'].' err' : '' ?></td>
              <td style="text-align:right"><?=number_format((int)$l['rows_read'],0,',','.')?></td>
              <td style="text-align:right;color:#16a34a"><?=number_format((int)$l['rows_new'],0,',','.')?></td>
              <td style="text-align:right;color:#2563eb"><?=number_format((int)$l['rows_updated'],0,',','.')?></td>
              <td style="text-align:right"><?=$l['seconds'] !== null ? $l['seconds'].'s' : '—'?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($srcCfg): ?>
<div class="card" style="margin-bottom:16px;border:2px solid #2563eb">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-rotate"></i> Sincronizzazione completa</span>
  </div>
  <p style="font-size:12px;color:var(--muted);margin:4px 0 10px">
    Aggiorna <strong>tutti i <?=count($datasets)?> dataset</strong> in un'unica operazione, nell'ordine corretto:
    <?php foreach (SyncDatasets::syncOrder() as $i => $k): ?><?= $i ? ' → ' : '' ?><strong><?=h($datasets[$k]['label'] ?? $k)?></strong><?php endforeach; ?>.
    L'ordine conta perché rapporti, tariffe e allocazioni si agganciano alle commesse per codice: le commesse
    vanno lette per prime, altrimenti restano righe scollegate fino alla passata successiva.
    La connessione alla sorgente è aperta una volta sola.
  </p>
  <?php if ($can_run): ?>
    <?php
      // v1.8.65 — DUE FORM SEPARATI, ciascuno con l'azione in un campo nascosto.
      //
      // Prima erano due <button name="action"> nello stesso form. Il valore di
      // un pulsante viene trasmesso solo se il browser lo riconosce come
      // "submitter" del form: se l'invio avviene in altro modo — Invio da
      // tastiera, un submit programmatico, un click che parte dall'icona dentro
      // il pulsante — il valore non parte, e il server riceveva l'azione del
      // PRIMO pulsante. Premendo "Sincronizza tutto" si otteneva l'anteprima.
      //
      // Un campo nascosto viene inviato sempre, comunque il form parta.
    ?>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview_all">
        <button class="btn btn-sm"><i class="fa-solid fa-eye"></i> Anteprima completa</button>
      </form>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Esaminare TUTTE le righe? Richiede alcuni minuti e non scrive nulla.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="preview_full">
        <button class="btn btn-sm"><i class="fa-solid fa-list-check"></i> Anteprima integrale</button>
      </form>
      <form method="post" style="display:inline"
            onsubmit="return confirm('Sincronizzare tutti i dataset dal gestionale?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="sync_all">
        <button class="btn btn-primary"><i class="fa-solid fa-rotate"></i> Sincronizza tutto</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="reconcile_check">
        <button class="btn btn-sm"><i class="fa-solid fa-scale-balanced"></i> Verifica allineamento</button>
      </form>
      <span style="color:var(--muted);font-size:11px;margin-left:auto">
        Un dataset che fallisce non ferma gli altri: l'esito è riportato per ciascuno.
      </span>
    </div>
    <p style="font-size:11px;color:var(--muted);margin:8px 0 0">
      <strong>Anteprima completa</strong>: rapida, esamina 200 righe per dataset — serve a verificare che
      tutti rispondano.<br>
      <strong>Anteprima integrale</strong>: esamina <strong>tutte</strong> le righe e riporta i conteggi reali
      di nuove e aggiornate. Richiede alcuni minuti e non scrive nulla. Legge in streaming, una riga alla
      volta, quindi la memoria impiegata non cresce con il volume.<br>
      <strong>Sincronizza tutto</strong>: l'unico che scrive.
    </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($repRec): ?>
<div class="card" style="margin-bottom:16px;border:2px solid <?=$repRec['dry'] ? '#f59e0b' : '#dc2626'?>">
  <div class="card-header"><span class="card-title">
    <i class="fa-solid fa-scale-balanced"></i>
    <?php if ($repRec['dry']): ?>
      <span style="background:#f59e0b;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px">VERIFICA</span>
      Allineamento con il gestionale — <?=$repRec['secondi']?>s
    <?php else: ?>
      <span style="background:#dc2626;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px">RIALLINEATO</span>
      Righe rimosse — <?=$repRec['secondi']?>s
    <?php endif; ?>
  </span></div>

  <?php if ($repRec['dry']): ?>
    <div class="alert alert-warning" style="font-size:12px">
      <strong>Nessuna riga è stata rimossa.</strong> Sono elencate le righe presenti nel portale che il
      gestionale <strong>non conosce più</strong>: cancellate alla fonte, riclassificate, oppure prodotte da
      un import ormai superato.
      <?php if ($repRec['tot']['orphans'] > 0): ?>
        Per rimuoverle usare <strong>Riallinea al gestionale</strong> qui sotto.
      <?php else: ?>
        <strong>Il portale è allineato: nessuna riga orfana.</strong>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr>
        <th>Dataset</th><th style="text-align:right">Nel gestionale</th><th style="text-align:right">Nel portale</th>
        <th style="text-align:right">Orfane</th><th style="text-align:right">Rimosse</th>
        <th style="text-align:right">Protette</th><th>Esempi di chiave orfana</th>
      </tr></thead>
      <tbody>
      <?php foreach ($repRec['esiti'] as $r): ?>
        <tr<?= (int)($r['orphans'] ?? 0) > 0 ? ' style="background:#fffbeb"' : '' ?>>
          <td style="font-weight:600"><?=h($r['label'])?></td>
          <td style="text-align:right"><?=number_format((int)$r['total_src'],0,',','.')?></td>
          <td style="text-align:right"><?=number_format((int)$r['total_dst'],0,',','.')?></td>
          <td style="text-align:right;font-weight:700;color:<?=(int)$r['orphans']>0?'#b45309':'#16a34a'?>">
            <?=number_format((int)$r['orphans'],0,',','.')?></td>
          <td style="text-align:right;color:#dc2626"><?=number_format((int)$r['removed'],0,',','.')?></td>
          <td style="text-align:right;color:var(--muted)"><?=number_format((int)$r['protected'],0,',','.')?></td>
          <td style="font-size:10px;color:var(--muted)">
            <?php if ($r['esito'] === 'errore'): ?>
              <span style="color:#dc2626"><?=h(mb_strimwidth((string)($r['errore'] ?? ''),0,80,'…'))?></span>
            <?php elseif (!empty($r['samples'])): ?>
              <?=h(implode(', ', array_slice($r['samples'], 0, 5)))?><?= count($r['samples']) > 5 ? ' …' : '' ?>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;border-top:2px solid #e2e8f0">
        <td>TOTALE</td>
        <td style="text-align:right"><?=number_format($repRec['tot']['total_src'],0,',','.')?></td>
        <td style="text-align:right"><?=number_format($repRec['tot']['total_dst'],0,',','.')?></td>
        <td style="text-align:right;color:#b45309"><?=number_format($repRec['tot']['orphans'],0,',','.')?></td>
        <td style="text-align:right;color:#dc2626"><?=number_format($repRec['tot']['removed'],0,',','.')?></td>
        <td style="text-align:right"><?=number_format($repRec['tot']['protected'],0,',','.')?></td>
        <td></td>
      </tr></tfoot>
    </table>
  </div>

  <p style="font-size:11px;color:var(--muted);margin-top:8px">
    Le righe <strong>protette</strong> sono quelle inserite a mano o caricate da XLSX: non provengono dal
    gestionale, quindi la sua assenza non le rende orfane e non vengono mai rimosse.
  </p>

  <?php if ($repRec['dry'] && $repRec['tot']['orphans'] > 0 && $can_run): ?>
    <form method="post" style="margin-top:10px"
          onsubmit="return confirm('Rimuovere <?=$repRec['tot']['orphans']?> righe non più presenti nel gestionale? L\'operazione non è reversibile: fare prima un backup.')">
      <?= csrf_field() ?><input type="hidden" name="action" value="reconcile_apply">
      <button class="btn btn-danger">
        <i class="fa-solid fa-broom"></i> Riallinea al gestionale — rimuovi <?=number_format($repRec['tot']['orphans'],0,',','.')?> righe</button>
      <span style="font-size:11px;color:#b45309;margin-left:8px">
        Operazione non reversibile: eseguire un backup del database prima di procedere.
      </span>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($repAll): ?>
<div class="card" style="margin-bottom:16px;border:2px solid <?=$repAll['err'] ? '#dc2626' : '#16a34a'?>">
  <div class="card-header"><span class="card-title">
    <i class="fa-solid fa-<?=$repAll['err'] ? 'triangle-exclamation' : 'circle-check'?>"></i>
    <?php // v1.8.65: la modalita' e' dichiarata nel titolo con un colore
          // distinto, non solo in un avviso sotto. Chi preme "Sincronizza tutto"
          // e ottiene un'anteprima deve accorgersene dal titolo. ?>
    <?php if ($repAll['dry']): ?>
      <span style="background:#f59e0b;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px">
        <?= !empty($repAll['integrale']) ? 'ANTEPRIMA INTEGRALE' : 'ANTEPRIMA' ?></span>
      Nessun dato scritto — <?=$repAll['secondi']?>s
    <?php else: ?>
      <span style="background:#16a34a;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px">SCRITTURA</span>
      Sincronizzazione eseguita — <?=$repAll['secondi']?>s
    <?php endif; ?>
  </span></div>
  <?php if ($repAll['dry']): ?>
    <div class="alert alert-warning" style="font-size:12px">
      <?php if (!empty($repAll['integrale'])): ?>
        <strong>Anteprima integrale</strong>: nessun dato è stato scritto, ma sono state esaminate
        <strong>tutte</strong> le righe. I conteggi di nuove e aggiornate sono quelli reali.
        Per applicarli usare <strong>Sincronizza tutto</strong>.
      <?php else: ?>
        <strong>Questa era un'anteprima</strong>: nessun dato è stato scritto, e la lettura è limitata
        a 200 righe per dataset. I numeri indicano che cosa verrebbe fatto, non l'intero volume —
        per averli sull'intero volume usare <strong>Anteprima integrale</strong>.
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div style="overflow-x:auto">
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr>
        <th>Dataset</th><th style="text-align:right">Lette</th><th style="text-align:right">Nuove</th>
        <th style="text-align:right">Aggiornate</th><th style="text-align:right">Senza chiave</th>
        <th style="text-align:right">Agganciate</th><th style="text-align:right">Secondi</th><th>Esito</th>
      </tr></thead>
      <tbody>
      <?php foreach ($repAll['esiti'] as $r): ?>
        <tr<?= $r['esito'] === 'errore' ? ' style="background:#fef2f2"' : '' ?>>
          <td style="font-weight:600"><?=h($r['label'])?></td>
          <td style="text-align:right"><?=number_format((int)$r['total'],0,',','.')?></td>
          <td style="text-align:right;color:#16a34a"><?=number_format((int)$r['ins'],0,',','.')?></td>
          <td style="text-align:right;color:#2563eb"><?=number_format((int)$r['upd'],0,',','.')?></td>
          <td style="text-align:right"><?=number_format((int)$r['skip'],0,',','.')?></td>
          <td style="text-align:right"><?=number_format((int)$r['linked'],0,',','.')?></td>
          <td style="text-align:right"><?=$r['secondi']?></td>
          <td><?php if ($r['esito'] === 'ok'): ?>
              <span style="color:#16a34a;font-weight:600">ok</span>
            <?php else: ?>
              <span style="color:#dc2626;font-weight:600" title="<?=h($r['errore'] ?? '')?>">errore</span>
              <div style="font-size:10px;color:#dc2626"><?=h(mb_strimwidth((string)($r['errore'] ?? ''),0,90,'…'))?></div>
            <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="font-weight:700;border-top:2px solid #e2e8f0">
        <td>TOTALE</td>
        <td style="text-align:right"><?=number_format($repAll['tot']['total'],0,',','.')?></td>
        <td style="text-align:right"><?=number_format($repAll['tot']['ins'],0,',','.')?></td>
        <td style="text-align:right"><?=number_format($repAll['tot']['upd'],0,',','.')?></td>
        <td style="text-align:right"><?=number_format($repAll['tot']['skip'],0,',','.')?></td>
        <td style="text-align:right"><?=number_format($repAll['tot']['linked'],0,',','.')?></td>
        <td style="text-align:right"><?=$repAll['secondi']?></td>
        <td><?= $repAll['err'] ? '<span style="color:#dc2626">' . $repAll['err'] . ' in errore</span>' : '—' ?></td>
      </tr></tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($inv):
    $cov = $inv['coverage'];
    $tab = count(array_filter($inv['inventory'], fn($t) => $t['type'] === 'tabella'));
    $vis = count(array_filter($inv['inventory'], fn($t) => $t['type'] === 'vista'));
?>
  <div class="card" style="margin-bottom:16px;border:2px solid <?= $cov['missing'] ? '#dc2626' : '#16a34a' ?>">
    <div class="card-header"><span class="card-title">
      <i class="fa-solid fa-<?= $cov['missing'] ? 'triangle-exclamation' : 'circle-check' ?>"></i>
      Schema <code><?=h($inv['schema'])?></code> · server <?=h($inv['server'])?>
    </span></div>

    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;padding:10px 0">
      <?php foreach ([
        ['Oggetti totali', $cov['total'], '#334155'],
        ['Tabelle', $tab, '#334155'],
        ['Viste', $vis, '#0891b2'],
        ['Usate dai dataset', count($cov['used']), '#16a34a'],
        ['Mancanti', count($cov['missing']), $cov['missing'] ? '#dc2626' : '#94a3b8'],
      ] as [$k, $v, $c]): ?>
        <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px">
          <div style="font-size:20px;font-weight:800;color:<?=$c?>"><?=(int)$v?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700"><?=$k?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($cov['missing']): ?>
      <div class="alert alert-danger" style="font-size:12px">
        <strong>Attenzione:</strong> queste tabelle sono richieste dai dataset ma non esistono nella sorgente:
        <code><?=h(implode(', ', $cov['missing']))?></code>.
        La sincronizzazione dei dataset che le usano fallirebbe: verificare se sono state rinominate o spostate
        in un altro schema.
      </div>
    <?php else: ?>
      <div style="font-size:12px;color:#15803d;padding:4px 0">
        Tutte le <?=count($cov['used'])?> tabelle richieste dai dataset sono presenti: la sincronizzazione può procedere.
      </div>
    <?php endif; ?>

    <details style="margin-top:12px">
      <summary style="cursor:pointer;font-size:12px;font-weight:600">
        Elenco completo degli oggetti (<?=$cov['total']?>)</summary>
      <div style="overflow-x:auto;margin-top:8px;max-height:420px;overflow-y:auto">
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Oggetto</th><th>Tipo</th><th style="text-align:right">Colonne</th>
            <th style="text-align:right">Righe (stima)</th><th>Uso</th></tr></thead>
          <tbody>
          <?php
            $usedLower = array_map('strtolower', $cov['used']);
            foreach ($inv['inventory'] as $t):
              $isUsed = in_array(strtolower($t['name']), $usedLower, true);
          ?>
            <tr<?= $isUsed ? ' style="background:#f0fdf4"' : '' ?>>
              <td><code><?=h($t['name'])?></code></td>
              <td><?= $t['type'] === 'vista'
                    ? '<span style="color:#0891b2;font-weight:600">vista</span>' : 'tabella' ?></td>
              <td style="text-align:right"><?=(int)$t['columns']?></td>
              <td style="text-align:right"><?= $t['rows'] ? number_format((int)$t['rows'], 0, ',', '.') : '—' ?></td>
              <td><?= $isUsed
                    ? '<span style="color:#16a34a;font-weight:600">usata dai dataset</span>'
                    : '<span style="color:var(--muted)">non utilizzata</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="color:var(--muted);font-size:10px;margin-top:6px">
        Il numero di righe è la stima del motore, non un conteggio esatto: serve a dare l'ordine di grandezza
        senza scansionare le tabelle. Le <strong>viste</strong> del gestionale sono spesso estrazioni già pronte
        e sono le candidate naturali per nuovi dataset di sincronizzazione.
      </p>
    </details>
  </div>
<?php endif; ?>


<?php foreach ($datasets as $key => $d): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-table"></i> <?= h($d['label']) ?></span>
      <span style="color:var(--muted);font-size:11px;margin-left:auto">
        <?= h($d['source_table']) ?> &rarr; <?= h($d['target']) ?>
      </span>
    </div>
    <p style="font-size:12px;color:var(--muted);margin:4px 0 10px"><?= h($d['description']) ?></p>
    <div style="font-size:11px;color:#475569;margin-bottom:10px">
      <strong>Aggiorna le viste:</strong> <?= h(implode(' · ', $d['affects'])) ?><br>
      <strong>Chiave di aggiornamento:</strong> <code><?= h($d['key']) ?></code> —
      <strong>colonne del tracciato:</strong> <?= count(SyncDatasets::headers($key)) ?>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding-bottom:10px;border-bottom:1px solid #e2e8f0">
      <?php if ($srcCfg && $can_run): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="dataset" value="<?= h($key) ?>">
          <input type="hidden" name="action" value="preview_db">
          <button class="btn btn-sm"><i class="fa-solid fa-eye"></i> Anteprima dal gestionale</button></form>
        <form method="post" style="display:inline"
              onsubmit="return confirm('Sincronizzare <?= h($d['label']) ?> dal gestionale?')">
          <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= h($key) ?>">
          <input type="hidden" name="action" value="sync_db">
          <button class="btn btn-sm btn-success"><i class="fa-solid fa-rotate"></i> Sincronizza dal gestionale</button></form>
      <?php endif; ?>
      <a class="btn btn-sm" href="<?= url_safe('sync_commesse', ['template' => $key]) ?>">
        <i class="fa-solid fa-file-csv"></i> Scarica tracciato CSV</a>
    </div>

    <?php if ($can_run): ?>
      <form method="post" enctype="multipart/form-data"
            style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= h($key) ?>">
        <div class="form-group" style="margin:0;flex:1;min-width:260px">
          <label>Oppure carica un CSV con questo tracciato</label>
          <input type="file" name="file" accept=".csv,.txt" required></div>
        <?php // stesso motivo: l'azione sta in un campo nascosto, impostato dal
              // pulsante premuto. Qui i form non si possono separare perche'
              // condividono il campo file, quindi il valore viene scritto prima
              // dell'invio invece di dipendere dal submitter. ?>
        <input type="hidden" name="action" value="preview_csv" id="csvAction_<?= h($key) ?>">
        <button type="submit" class="btn btn-sm"
                onclick="document.getElementById('csvAction_<?= h($key) ?>').value='preview_csv'">
          <i class="fa-solid fa-eye"></i> Anteprima</button>
        <button type="submit" class="btn btn-sm btn-primary"
                onclick="document.getElementById('csvAction_<?= h($key) ?>').value='sync_csv'">
          <i class="fa-solid fa-upload"></i> Importa</button>
      </form>
    <?php endif; ?>

    <details style="margin-top:10px">
      <summary style="cursor:pointer;font-size:11px;color:var(--muted)">Colonne attese nel CSV</summary>
      <div style="font-family:Consolas,monospace;font-size:10px;color:#1e293b;margin-top:6px;
                  display:grid;grid-template-columns:repeat(4,1fr);gap:3px 10px">
        <?php foreach (SyncDatasets::headers($key) as $hcol): ?>
          <div<?= in_array($hcol, $d['required'] ?? [], true) ? ' title="Obbligatoria"' : '' ?>>
            <?= in_array($hcol, $d['required'] ?? [], true) ? '<strong>' . h($hcol) . '</strong> *' : h($hcol) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  </div>
<?php endforeach; ?>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-info"></i> Come funziona</span></div>
  <div style="font-size:12px;color:#334155;line-height:1.6">
    <p style="margin:0 0 8px">
      Le due origini producono lo stesso risultato: le query di lettura e le intestazioni del CSV derivano
      dalla medesima definizione, quindi un file esportato dal gestionale è interscambiabile con la lettura diretta.
    </p>
    <p style="margin:0 0 8px">
      La scrittura avviene per <strong>chiave naturale</strong> e non duplica: le righe già presenti vengono
      aggiornate, le nuove create. Le celle vuote non sovrascrivono valori già registrati, così un file parziale
      può servire a correggere singoli campi.
    </p>
    <p style="margin:0">
      Conviene sempre lanciare prima l'<strong>Anteprima</strong>, che legge senza scrivere e mostra quante righe
      verrebbero inserite e quante aggiornate.
    </p>
  </div>
</div>
<?php require_once('footer.php'); ?>
