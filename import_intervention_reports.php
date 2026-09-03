<?php
/**
 * import_intervention_reports.php — Import rapporti di intervento da XLSX (v1.7.59)
 * UPSERT su report_code; risoluzione commessa/tecnico/cliente/fascia; costi import + calcolati.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/XlsxReader.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/ProjectModel.php');

if (!can('view', 'import_intervention_reports.php')) { redirect('manage_projects'); }
$u_id  = (int)$_SESSION['user_id'];
$model = new ProjectModel($pdo);
$rates = new RateResolver($pdo);

$norm = fn($s) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
$b    = fn($v) => in_array(strtolower(trim((string)$v)), ['true','1','si','sì','vero','x','yes'], true) ? 1 : 0;
$dec  = fn($v) => ($v === '' || $v === null) ? 0.0 : (float)str_replace([' ', ','], ['', '.'], (string)$v);
$dt   = function($v) {
    $t = trim((string)$v); if ($t === '') return null;
    $fmt = strlen($t) <= 10 ? 'd/m/Y' : 'd/m/Y H:i';
    $o = DateTime::createFromFormat($fmt, $t);
    return $o ? $o->format($fmt === 'd/m/Y' ? 'Y-m-d' : 'Y-m-d H:i:s') : null;
};
// v1.7.65: clamp difensivo alle lunghezze di colonna. Un singolo valore fuori
// misura non deve piu' abortire un import di decine di migliaia di righe:
// viene troncato e conteggiato, e il totale e' riportato nell'esito.
$clip = function($v, int $max) use (&$truncated) {
    if ($v === null || $v === '') return null;
    $v = (string)$v;
    if (mb_strlen($v) <= $max) return $v;
    $truncated++;
    return mb_substr($v, 0, $max);
};
$truncated = 0;
$dOnly = function($v){ $t=trim((string)$v); if($t==='')return null; $o=DateTime::createFromFormat('d/m/Y',substr($t,0,10)); return $o?$o->format('Y-m-d'):null; };

// header (esatti dal file) -> chiave logica
$MAP = [
  'n.'=>'report_code','data rapporto'=>'report_date','inizio intervento'=>'start_at','fine intervento'=>'end_at',
  'approvato'=>'approved','codice commessa'=>'project_code','commessa'=>'project_name_raw','tipo'=>'service_type',
  'cliente'=>'client_raw','sede'=>'site_raw','riferimento del cliente'=>'client_reference','fascia'=>'band_raw',
  'settore tecnologico'=>'tech_sector','richiesta intervento'=>'request_text','ticket'=>'ticket',
  'lavoro eseguito'=>'work_done','tecnico'=>'technician_raw','da remoto'=>'remote','in reperibilità'=>'on_call',
  'in reperibilita'=>'on_call','pianificato (ore)'=>'planned_hours','quantità (ore)'=>'quantity_hours',
  'quantita (ore)'=>'quantity_hours','diff. (ore)'=>'diff_hours','di cui extra (ore)'=>'extra_hours',
  'ricavo'=>'client_revenue_import','costo'=>'company_cost_import',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // v1.7.62: se il body eccede post_max_size PHP lo scarta e $_POST arriva vuoto:
    // va intercettato PRIMA di Csrf::verify(), che altrimenti risponde 403 mascherando la causa.
    if (UploadGuard::postDiscarded()) { $_SESSION['flash_msg'] = UploadGuard::discardedMessage(); redirect_self(); }
    Csrf::verify();
    if (!can('create', 'import_intervention_reports.php')) { $_SESSION['flash_msg']="<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    @set_time_limit(0);
    try {
        if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) throw new Exception($err);

        // v1.7.62: parsing in streaming (memoria costante). Batch creato prima:
        // il totale righe non è noto a priori e viene consolidato a fine import.
        $pdo->prepare("INSERT INTO cm_import_batches (filename,kind,rows_total,created_by) VALUES (?,?,?,?)")
            ->execute([$_FILES['file']['name'], 'interventi', 0, $u_id]);
        $batchId = (int)$pdo->lastInsertId();

        // v1.8.51 — GRANA ESPLICITA.
        // `source_uid` viene dichiarato da questo canale invece di essere lasciato
        // calcolare al trigger. Il trigger resta la rete di sicurezza per i canali
        // che non lo fanno, ma un importer che conosce la propria granularità deve
        // dirlo: rende leggibile su quale chiave sta deduplicando, e fa fallire
        // subito un file il cui tracciato non permette di distinguere i tecnici.
        //
        // La formula coincide con quella del trigger: codice rapporto senza
        // suffisso, poi il primo identificativo disponibile del tecnico.
        $granaUid = function (string $code, ?int $techId, string $techRaw): string {
            $code = trim(explode('/', $code)[0]);
            $who  = $techId ? (string)$techId : (trim($techRaw) !== '' ? trim($techRaw) : '0');
            return $code . '#' . $who;
        };

        $up = $pdo->prepare(
          "INSERT INTO cm_intervention_reports
             (source_uid,source_system,report_code,report_date,start_at,end_at,approved,project_id,project_code,project_name_raw,service_type,
              client_id,client_location_id,client_raw,site_raw,client_reference,band_id,band_raw,tech_sector,
              request_text,ticket,work_done,technician_id,technician_raw,remote,on_call,
              planned_hours,quantity_hours,diff_hours,extra_hours,
              client_revenue_import,client_revenue_calc,company_cost_import,company_cost_calc,commercial_cost_calc,
              import_batch_id,imported_by,imported_at)
           VALUES (?,'xlsx',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE
             source_system=VALUES(source_system),report_date=VALUES(report_date),start_at=VALUES(start_at),end_at=VALUES(end_at),approved=VALUES(approved),
             project_id=COALESCE(VALUES(project_id),project_id),project_code=VALUES(project_code),project_name_raw=VALUES(project_name_raw),
             service_type=VALUES(service_type),client_id=COALESCE(VALUES(client_id),client_id),
             client_location_id=COALESCE(VALUES(client_location_id),client_location_id),client_raw=VALUES(client_raw),site_raw=VALUES(site_raw),
             client_reference=VALUES(client_reference),band_id=COALESCE(VALUES(band_id),band_id),band_raw=VALUES(band_raw),
             tech_sector=VALUES(tech_sector),request_text=VALUES(request_text),ticket=VALUES(ticket),work_done=VALUES(work_done),
             technician_id=COALESCE(VALUES(technician_id),technician_id),technician_raw=VALUES(technician_raw),
             remote=VALUES(remote),on_call=VALUES(on_call),planned_hours=VALUES(planned_hours),quantity_hours=VALUES(quantity_hours),
             diff_hours=VALUES(diff_hours),extra_hours=VALUES(extra_hours),
             client_revenue_import=VALUES(client_revenue_import),client_revenue_calc=VALUES(client_revenue_calc),
             company_cost_import=VALUES(company_cost_import),company_cost_calc=VALUES(company_cost_calc),
             commercial_cost_calc=VALUES(commercial_cost_calc),import_batch_id=VALUES(import_batch_id),imported_by=VALUES(imported_by)"
        );
        $stProj = $pdo->prepare("SELECT id FROM cm_projects WHERE project_code=?");

        $hmap = null; $ok = 0; $unmatched = 0; $skipped = 0;
        $get = function(array $row, string $key, $default='') use (&$hmap) {
            return isset($hmap[$key]) ? ($row[$hmap[$key]] ?? $default) : $default;
        };

        $pdo->beginTransaction();
        // v1.7.65: hint per il riconoscimento della riga di intestazione (gli export
        // hanno una riga di titolo iniziale e possono avere righe vuote sopra).
        $headersFound = [];
        $total = XlsxReader::each($_FILES['file']['tmp_name'], function(array $row, int $i)
            use (&$hmap, &$ok, &$unmatched, &$skipped, &$truncated, $MAP, $norm, $b, $dec, $dt, $dOnly, $get, $clip,
                 $up, $stProj, $model, $rates, $batchId, $u_id, $pdo) {

            if ($hmap === null) {
                $hmap = [];
                foreach (array_keys($row) as $h) { $k = $MAP[$norm($h)] ?? null; if ($k) $hmap[$k] = $h; }
                if (empty($hmap['report_code'])) {
                    throw new Exception('Colonna "N." (codice rapporto) non trovata. Intestazioni rilevate: '
                        . (implode(' | ', array_keys($row)) ?: 'nessuna'));
                }
            }

            $code = trim($get($row,'report_code'));
            if ($code === '') { $skipped++; return; }

            $pcode = trim($get($row,'project_code'));
            $projId = $pcode !== '' ? $model->resolveProjectId($pcode) : null;   // v1.7.67: alias-aware

            $clientRaw = trim($get($row,'client_raw'));
            $clientId  = $clientRaw !== '' ? $model->upsertClient($clientRaw) : null;
            $siteRaw   = trim($get($row,'site_raw'));
            $clientLoc = ($clientId && $siteRaw !== '') ? $model->upsertClientLocation($clientId, $siteRaw) : null;

            $techRaw = trim($get($row,'technician_raw'));
            $techId  = $techRaw !== '' ? $model->resolveTechnician($techRaw) : null;

            $bandRaw = trim($get($row,'band_raw'));
            $bandId  = $bandRaw !== '' ? $rates->bandIdByName($bandRaw) : null;

            $onCall  = $b($get($row,'on_call'));
            $qty     = $dec($get($row,'quantity_hours'));
            $calc    = $rates->calcCosts($bandId, $qty, (bool)$onCall);

            if (!$projId || !$techId) $unmatched++;

            $up->execute([
                $granaUid($code, $techId ? (int)$techId : null, (string)$techRaw),
                $clip($code, 80), $dOnly($get($row,'report_date')), $dt($get($row,'start_at')), $dt($get($row,'end_at')),
                $b($get($row,'approved')), $projId, $clip($pcode, 40), $clip($get($row,'project_name_raw'), 255), $clip($get($row,'service_type'), 80),
                $clientId, $clientLoc, $clip($clientRaw, 255), $clip($siteRaw, 255), $clip($get($row,'client_reference'), 255),
                $bandId, $clip($bandRaw, 80), $clip($get($row,'tech_sector'), 150),
                $get($row,'request_text') ?: null, $clip($get($row,'ticket'), 500), $get($row,'work_done') ?: null,
                $techId, $clip($techRaw, 200), $b($get($row,'remote')), $onCall,
                $dec($get($row,'planned_hours')), $qty, $dec($get($row,'diff_hours')), $dec($get($row,'extra_hours')),
                $dec($get($row,'client_revenue_import')), $calc['client_revenue_calc'],
                $dec($get($row,'company_cost_import')), $calc['company_cost_calc'], $calc['commercial_cost_calc'],
                $batchId, $u_id,
            ]);
            $ok++;

            // commit a blocchi: limita l'undo log e mantiene il progresso su file molto grandi
            if ($ok % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
        }, 0, $headersFound, ['header_hints' => array_keys($MAP)]);
        if ($pdo->inTransaction()) $pdo->commit();

        $pdo->prepare("UPDATE cm_import_batches SET rows_total=?, rows_ok=?, rows_unmatched=? WHERE id=?")
            ->execute([$total, $ok, $unmatched, $batchId]);
        write_log('Interventions','success',"Import rapporti: $ok righe ($unmatched non risolte, $skipped saltate) batch #$batchId",$u_id);
        // v1.7.67: le righe non risolte sono lavorabili dalla sezione di controllo,
        // raggruppate per valore grezzo (poche decine di valori invece di migliaia di righe).
        $link = $unmatched
            ? " <a href='" . url_safe('import_control') . "' style='font-weight:600'>Vai a Controllo &amp; Riconciliazione &rarr;</a>"
            : "";
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Import completato: $ok righe, $unmatched senza match commessa/tecnico"
            . ($skipped ? ", $skipped saltate" : "")
            . ($truncated ? ", $truncated valori troncati alla lunghezza di colonna" : "")
            . ". Picco memoria: " . UploadGuard::fmt(memory_get_peak_usage(true)) . "." . $link . "</div>";
    } catch (\Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore import: ".h($e->getMessage())."</div>";
    }
    redirect_self();
}

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');
?>
<div class="page-header"><h1><i class="fa-solid fa-file-import"></i> Import Rapporti di Intervento (XLSX)</h1></div>
<?= $msg ?>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-group"><label>Lista rapporti di intervento (.xlsx)</label>
      <input type="file" name="file" accept=".xlsx" required>
      <small style="color:var(--muted)"><?= UploadGuard::limitsNote() ?></small></div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Importa</button>
  </form>
  <p style="color:var(--muted);margin-top:12px">Lettura in streaming: la dimensione del file non incide sulla memoria. UPSERT su <code>N.</code> (codice rapporto). Ore di riferimento = <code>Quantità (ore)</code>. Costo/Ricavo salvati sia da file sia calcolati da fascia×ore×regime. <code>approvato</code> aggiornato ad ogni reimport.</p>
</div>
<?php require_once('footer.php'); ?>
