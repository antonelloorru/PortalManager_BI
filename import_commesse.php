<?php
/**
 * import_commesse.php — Import massivo commesse da XLSX
 *
 * v1.8.41: l'import è ora **auto-riconciliante** rispetto ai segnaposto generati
 * dalla sincronizzazione DGB. In precedenza, importando una commessa reale il cui
 * contratto DGB era già stato sincronizzato, si ottenevano due righe distinte per
 * lo stesso contratto: il segnaposto `DGB-<id>` (a cui restavano agganciati i
 * rapporti di intervento) e la commessa reale (con i dati ma senza rapporti).
 * Elenco, Gantt e carico risorse mostravano quindi codici e dati incoerenti.
 *
 * Ora, per ogni riga importata:
 *   1. `dgb_contract_id` viene derivato dal link al gestionale;
 *   2. se esiste un segnaposto con lo stesso `dgb_contract_id`, i suoi riferimenti
 *      (rapporti, team, timesheet, fasi, workflow, alias, aggiornamenti, tariffe)
 *      vengono spostati sulla commessa reale e il segnaposto viene rimosso.
 *
 * UPSERT su project_code; upsert cliente; exec_company_id da prefisso.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/XlsxReader.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/ProjectModel.php');

if (!can('view', 'import_commesse.php')) { redirect('manage_projects'); }
$u_id  = (int)$_SESSION['user_id'];
$model = new ProjectModel($pdo);
$prefix = new PrefixResolver($pdo);

$norm = fn($s) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$s)));
$dec  = fn($v) => ($v === '' || $v === null) ? null : (float)str_replace([' ', ','], ['', '.'], (string)$v);
$intv = fn($v) => ($v === '' || $v === null) ? null : (int)$v;
$date = function($v) {
    $t = trim((string)$v); if ($t === '') return null;
    $o = DateTime::createFromFormat('d/m/Y', substr($t, 0, 10));
    return $o ? $o->format('Y-m-d') : null;
};

// mappa header-normalizzato -> chiave logica
$MAP = [
  'abbr'=>'abbr','commerciale'=>'commercial_ref','link'=>'external_link','tipo'=>'service_line',
  'codice_commessa'=>'project_code','commessa'=>'name','cliente'=>'client_raw',
  'descrizione'=>'description','descrizione interna'=>'internal_description','stato'=>'operational_status',
  'compliance da verificare'=>'compliance_to_verify','compliance pre autorizzata'=>'compliance_preauth',
  'data inizio'=>'start_date','data fine'=>'end_date','anomalie aperte'=>'anomalies_open',
  'anomalie bloccanti'=>'anomalies_blocking','stato economico a oggi'=>'economic_status_todate',
  'stato_economico'=>'economic_status','valore a oggi'=>'value_todate','valore'=>'value_total',
  'consuntivato'=>'actual_cost','margine a oggi'=>'margin_todate','margine'=>'margin_total',
  'residuo a oggi'=>'residual_todate','residuo'=>'residual_total','fido su valore'=>'credit_on_value',
  'fido su costi'=>'credit_on_costs','fatt. freq. (mesi)'=>'billing_freq_months','prima fatt.'=>'first_billing_date',
];

$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // v1.7.62: POST scartata da PHP (oltre post_max_size) -> intercettata prima di Csrf::verify()
    if (UploadGuard::postDiscarded()) { $_SESSION['flash_msg'] = UploadGuard::discardedMessage(); redirect_self(); }
    Csrf::verify();
    if (!can('create', 'import_commesse.php')) { $_SESSION['flash_msg']="<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    @set_time_limit(0);
    try {
        if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) throw new Exception($err);

        $pdo->prepare("INSERT INTO cm_import_batches (filename,kind,rows_total,created_by) VALUES (?,?,?,?)")
            ->execute([$_FILES['file']['name'], 'commesse', 0, $u_id]);
        $batchId = (int)$pdo->lastInsertId();

        $stExists = $pdo->prepare("SELECT id FROM cm_projects WHERE project_code=?");
        $up = $pdo->prepare(
          "INSERT INTO cm_projects
             (project_code,name,abbr,service_line,commercial_ref,external_link,dgb_contract_id,client_id,client_raw,exec_company_id,
              operational_status,economic_status,economic_status_todate,description,internal_description,
              compliance_to_verify,compliance_preauth,anomalies_open,anomalies_blocking,start_date,end_date,
              first_billing_date,billing_freq_months,value_total,value_todate,actual_cost,margin_total,margin_todate,
              residual_total,residual_todate,credit_on_value,credit_on_costs,import_batch_id,created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE
             name=VALUES(name),abbr=VALUES(abbr),service_line=VALUES(service_line),commercial_ref=VALUES(commercial_ref),
             external_link=VALUES(external_link),dgb_contract_id=COALESCE(VALUES(dgb_contract_id),dgb_contract_id),
             client_id=COALESCE(VALUES(client_id),client_id),client_raw=VALUES(client_raw),
             exec_company_id=COALESCE(VALUES(exec_company_id),exec_company_id),operational_status=VALUES(operational_status),
             economic_status=VALUES(economic_status),economic_status_todate=VALUES(economic_status_todate),
             description=VALUES(description),internal_description=VALUES(internal_description),
             compliance_to_verify=VALUES(compliance_to_verify),compliance_preauth=VALUES(compliance_preauth),
             anomalies_open=VALUES(anomalies_open),anomalies_blocking=VALUES(anomalies_blocking),
             start_date=VALUES(start_date),end_date=VALUES(end_date),first_billing_date=VALUES(first_billing_date),
             billing_freq_months=VALUES(billing_freq_months),value_total=VALUES(value_total),value_todate=VALUES(value_todate),
             actual_cost=VALUES(actual_cost),margin_total=VALUES(margin_total),margin_todate=VALUES(margin_todate),
             residual_total=VALUES(residual_total),residual_todate=VALUES(residual_todate),
             credit_on_value=VALUES(credit_on_value),credit_on_costs=VALUES(credit_on_costs),import_batch_id=VALUES(import_batch_id)"
        );

        $hmap = null; $ins = 0; $upd = 0; $skip = 0; $absorbed = 0;
        $get = function(array $row, string $key, $default='') use (&$hmap) {
            return isset($hmap[$key]) ? ($row[$hmap[$key]] ?? $default) : $default;
        };

        // v1.8.41 — riconciliazione con i segnaposto della sincronizzazione DGB.
        // Il link al gestionale ha forma .../contract/editV2/<id_contract>: da lì si
        // ricava l'id del contratto, che è la chiave con cui DgbSync ha creato il
        // segnaposto e con cui i rapporti di intervento sono agganciati.
        $dgbId = function($link): ?int {
            $l = trim((string)$link);
            if ($l === '') return null;
            return preg_match('~/contract/editV2/(\d+)~', $l, $m) ? (int)$m[1] : null;
        };
        // Segnaposto da assorbire: stesso contratto DGB, codice fittizio, riga diversa.
        $stPlaceholder = $pdo->prepare(
            "SELECT id FROM cm_projects
              WHERE dgb_contract_id = ? AND project_code LIKE 'DGB-%' AND id <> ?"
        );
        // Tabelle che referenziano cm_projects.id: i riferimenti vanno spostati
        // sulla commessa reale prima di eliminare il segnaposto.
        $DEPS = ['cm_intervention_reports','cm_team','cm_timesheet_entries','cm_presales_effort',
                 'cm_workflow_steps','cm_project_band_rates','cm_project_updates',
                 'cm_project_update_files','cm_project_phases','cm_alias_project','cm_alias_band'];
        $stMove = [];
        foreach ($DEPS as $t) {
            try { $stMove[$t] = $pdo->prepare("UPDATE `$t` SET project_id=? WHERE project_id=?"); }
            catch (Throwable $e) { /* tabella assente su installazioni parziali */ }
        }
        $stDrop = $pdo->prepare("DELETE FROM cm_projects WHERE id=? AND project_code LIKE 'DGB-%'");

        $absorb = function(int $realId, ?int $cid) use ($stPlaceholder, $stMove, $stDrop, &$absorbed): void {
            if (!$cid || !$realId) return;
            $stPlaceholder->execute([$cid, $realId]);
            foreach ($stPlaceholder->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                $oldId = (int)$oldId;
                foreach ($stMove as $st) {
                    try { $st->execute([$realId, $oldId]); } catch (Throwable $e) { /* ignora */ }
                }
                $stDrop->execute([$oldId]);
                $absorbed++;
            }
        };

        $pdo->beginTransaction();
        $headersFound = [];
        $total = XlsxReader::each($_FILES['file']['tmp_name'], function(array $row, int $i)
            use (&$hmap, &$ins, &$upd, &$skip, $MAP, $norm, $dec, $intv, $date, $get,
                 $up, $stExists, $model, $prefix, $batchId, $u_id, $pdo) {

            if ($hmap === null) {
                $hmap = [];
                foreach (array_keys($row) as $h) { $k = $MAP[$norm($h)] ?? null; if ($k) $hmap[$k] = $h; }
                if (empty($hmap['project_code'])) {
                    throw new Exception('Colonna codice_commessa non trovata. Intestazioni rilevate: '
                        . (implode(' | ', array_keys($row)) ?: 'nessuna'));
                }
            }

            $code = trim($get($row,'project_code'));
            if ($code === '') { $skip++; return; }
            $stExists->execute([$code]); $exists = (bool)$stExists->fetchColumn();

            $clientRaw = trim($get($row,'client_raw'));
            $clientId  = $clientRaw !== '' ? $model->upsertClient($clientRaw) : null;
            $execCo    = $prefix->companyId($code);
            $link      = $get($row,'external_link') ?: null;
            $cid       = $dgbId($link);   // v1.8.41: id contratto DGB dal link

            $up->execute([
                $code, trim($get($row,'name')) ?: $code, $get($row,'abbr') ?: null,
                $get($row,'service_line') ?: null, $get($row,'commercial_ref') ?: null, $link, $cid,
                $clientId, $clientRaw ?: null, $execCo,
                $get($row,'operational_status') ?: null, $get($row,'economic_status') ?: null, $get($row,'economic_status_todate') ?: null,
                $get($row,'description') ?: null, $get($row,'internal_description') ?: null,
                (int)($dec($get($row,'compliance_to_verify')) ?? 0) ? 1 : 0,
                (int)($dec($get($row,'compliance_preauth')) ?? 0) ? 1 : 0,
                $intv($get($row,'anomalies_open')) ?? 0, $intv($get($row,'anomalies_blocking')) ?? 0,
                $date($get($row,'start_date')), $date($get($row,'end_date')), $date($get($row,'first_billing_date')),
                $intv($get($row,'billing_freq_months')),
                $dec($get($row,'value_total')), $dec($get($row,'value_todate')), $dec($get($row,'actual_cost')),
                $dec($get($row,'margin_total')), $dec($get($row,'margin_todate')),
                $dec($get($row,'residual_total')), $dec($get($row,'residual_todate')),
                $dec($get($row,'credit_on_value')), $dec($get($row,'credit_on_costs')),
                $batchId, $u_id,
            ]);
            $exists ? $upd++ : $ins++;

            // v1.8.41: se lo stesso contratto DGB aveva già un segnaposto, i suoi
            // riferimenti passano alla commessa reale appena scritta e il
            // segnaposto viene rimosso. Evita la coesistenza di due righe per lo
            // stesso contratto, che lasciava i rapporti agganciati al codice fittizio.
            if ($cid) {
                $stExists->execute([$code]);
                $realId = (int)$stExists->fetchColumn();
                $absorb($realId, $cid);
            }

            if (($ins + $upd) % 500 === 0) { $pdo->commit(); $pdo->beginTransaction(); }
        }, 0, $headersFound, ['header_hints' => array_keys($MAP)]);
        if ($pdo->inTransaction()) $pdo->commit();

        $pdo->prepare("UPDATE cm_import_batches SET rows_total=?, rows_ok=?, rows_unmatched=? WHERE id=?")
            ->execute([$total, $ins+$upd, $skip, $batchId]);
        write_log('Projects','success',"Import commesse: $ins nuove, $upd aggiornate, $skip saltate, $absorbed segnaposto DGB assorbiti (batch #$batchId)",$u_id);
        $report = ['ins'=>$ins,'upd'=>$upd,'skip'=>$skip,'absorbed'=>$absorbed,'batch'=>$batchId];
        $absMsg = $absorbed > 0
            ? " Riconciliati <strong>$absorbed</strong> segnaposto DGB: i rapporti di intervento sono stati spostati sulle commesse reali."
            : "";
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Import completato: $ins nuove, $upd aggiornate, $skip saltate.$absMsg "
            . "Picco memoria: " . UploadGuard::fmt(memory_get_peak_usage(true)) . ".</div>";
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
<div class="page-header"><h1><i class="fa-solid fa-file-import"></i> Import Commesse (XLSX)</h1></div>
<?= $msg ?>
<div class="card">
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="form-group"><label>File export commesse (.xlsx)</label>
      <input type="file" name="file" accept=".xlsx" required>
      <small style="color:var(--muted)"><?= UploadGuard::limitsNote() ?></small></div>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Importa</button>
  </form>
  <p style="color:var(--muted);margin-top:12px">Lettura in streaming: la dimensione del file non incide sulla memoria. UPSERT su <code>codice_commessa</code>. Cliente creato in anagrafica clienti; azienda esecutrice derivata dal prefisso. Ri-eseguibile senza duplicati.</p>
  <p style="color:var(--muted);margin-top:6px">L'import è <strong>auto-riconciliante</strong>: dalla colonna <code>link</code> viene ricavato l'id del contratto DogoBit e, se la sincronizzazione DGB aveva già creato un segnaposto <code>DGB-&lt;id&gt;</code> per lo stesso contratto, i rapporti di intervento e gli altri riferimenti vengono spostati sulla commessa reale e il segnaposto viene rimosso. Le commesse prive di corrispondenza nel file restano invariate.</p>
</div>
<?php require_once('footer.php'); ?>
