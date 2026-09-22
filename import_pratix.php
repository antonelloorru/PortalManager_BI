<?php
/**
 * import_pratix.php â€” Import dati Pratix da file Excel (.xls / .xlsx)
 *
 * v1.9.58 â€” Pipeline ETL per ingestione dati Pratix:
 *   1. Upload file .xls (OLE2) o .xlsx
 *   2. Parsing con XlsBiffReader (xls) o XlsxReader (xlsx)
 *   3. Upsert idempotente su cm_pratix_import (chiave: order_code = Codice)
 *   4. Report esito: N insert, N update, N skip (Codice vuoto)
 *
 * Colonne mappate dal file Pratix:
 *   Codice                â†’ order_code            (chiave di join con pratix_orders)
 *   Cliente Effettivo     â†’ cliente_effettivo
 *   Cliente di Fatturazione â†’ cliente_fatturazione
 *   Progetto              â†’ progetto
 *   Descrizione           â†’ descrizione_pratix
 *   Stato                 â†’ stato_pratix
 *   Azienda               â†’ azienda
 *   Numero Documento      â†’ numero_documento
 *   Tipologia             â†’ tipologia
 *   Totale                â†’ totale
 *   Firma Commerciale     â†’ firma_commerciale
 *   Firma Tecnica         â†’ firma_tecnica
 *   Linea di Business     â†’ linea_di_business
 *   Anno Solare           â†’ anno_solare
 *   Stato Contratto       â†’ stato_contratto
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/UploadGuard.php');

// Auto-carica il reader corretto in base all'estensione
function pratix_load_reader(string $ext): void {
    if ($ext === 'xls') {
        require_once(__DIR__ . '/app/XlsBiffReader.php');
    } else {
        require_once(__DIR__ . '/app/XlsxReader.php');
    }
}

if (!can('view', 'import_pratix.php')) { redirect('pratix_orders'); }
$can_import = can('create', 'import_pratix.php');
$u_id = (int)$_SESSION['user_id'];

// Verifica che la tabella esista (migration non ancora eseguita?)
$tableExists = false;
try {
    $pdo->query("SELECT 1 FROM cm_pratix_import LIMIT 1");
    $tableExists = true;
} catch (Throwable $e) {
    $tableExists = false;
}

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (UploadGuard::postDiscarded()) {
        $_SESSION['flash_msg'] = UploadGuard::discardedMessage();
        redirect_self();
    }
    Csrf::verify();

    if (!$can_import) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
        redirect_self();
    }

    if (!$tableExists) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Tabella <code>cm_pratix_import</code> non trovata. Eseguire prima l'aggiornamento del database (db_upgrade.php â†’ v1.9.58).</div>";
        redirect_self();
    }

    @set_time_limit(300);

    try {
        if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) {
            throw new Exception($err);
        }

        $tmpPath  = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            throw new Exception("Formato non supportato: .$ext. Caricare un file .xls o .xlsx.");
        }

        pratix_load_reader($ext);

        // Batch tracking (compatibile con import_commesse.php)
        $batchId = null;
        try {
            $pdo->prepare("INSERT INTO cm_import_batches (filename, kind, rows_total, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$origName, 'pratix', 0, $u_id]);
            $batchId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) { /* cm_import_batches potrebbe non esistere */ }

        // Leggi le intestazioni attese
        $hints = [
            'codice', 'cliente effettivo', 'cliente di fatturazione', 'progetto',
            'descrizione', 'stato', 'azienda', 'numero documento', 'tipologia',
            'totale', 'firma commerciale', 'firma tecnica', 'linea di business'
        ];

        // Parsing
        $headers = [];
        $allRows = [];
        if ($ext === 'xls') {
            $result  = XlsBiffReader::read($tmpPath, 0, ['header_hints' => $hints]);
        } else {
            $result  = XlsxReader::read($tmpPath, 0, ['header_hints' => $hints]);
        }
        $headers = $result['headers'];
        $allRows = $result['rows'];

        if (empty($headers)) {
            throw new Exception("Nessuna intestazione trovata nel file. Verificare che il file contenga una riga header con le colonne Pratix.");
        }

        // Normalizza le intestazioni per lookup case-insensitive
        $normHeaders = [];
        foreach ($headers as $h) {
            $normHeaders[mb_strtolower(trim($h))] = $h;
        }

        // Helper: ottieni valore dalla riga per colonna (case-insensitive)
        $get = function (array $row, string $colName) use ($normHeaders): string {
            $key = mb_strtolower(trim($colName));
            $hdr = $normHeaders[$key] ?? null;
            if ($hdr === null) return '';
            return trim((string)($row[$hdr] ?? ''));
        };

        // Prepared statement per UPSERT
        $stmt = $pdo->prepare("
            INSERT INTO cm_pratix_import
                (order_code, cliente_effettivo, cliente_fatturazione, progetto,
                 descrizione_pratix, stato_pratix, azienda, numero_documento,
                 tipologia, totale, firma_commerciale, firma_tecnica,
                 linea_di_business, anno_solare, stato_contratto,
                 imported_by, import_batch_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                cliente_effettivo    = VALUES(cliente_effettivo),
                cliente_fatturazione = VALUES(cliente_fatturazione),
                progetto             = VALUES(progetto),
                descrizione_pratix   = VALUES(descrizione_pratix),
                stato_pratix         = VALUES(stato_pratix),
                azienda              = VALUES(azienda),
                numero_documento     = VALUES(numero_documento),
                tipologia            = VALUES(tipologia),
                totale               = VALUES(totale),
                firma_commerciale    = VALUES(firma_commerciale),
                firma_tecnica        = VALUES(firma_tecnica),
                linea_di_business    = VALUES(linea_di_business),
                anno_solare          = VALUES(anno_solare),
                stato_contratto      = VALUES(stato_contratto),
                imported_by          = VALUES(imported_by),
                import_batch_id      = VALUES(import_batch_id),
                imported_at          = CURRENT_TIMESTAMP
        ");

        $cntInsert  = 0;
        $cntUpdate  = 0;
        $cntSkip    = 0;
        $cntError   = 0;
        $errors     = [];

        $pdo->beginTransaction();
        try {
            foreach ($allRows as $row) {
                $orderCode = $get($row, 'Codice');
                if ($orderCode === '' || $orderCode === '0') { $cntSkip++; continue; }

                // Sanitizza totale (numero decimale)
                $totaleRaw = $get($row, 'Totale');
                $totale    = null;
                if ($totaleRaw !== '' && $totaleRaw !== '0') {
                    $totaleClean = str_replace([' ', ','], ['', '.'], $totaleRaw);
                    if (is_numeric($totaleClean)) $totale = (float)$totaleClean;
                }

                // Anno solare
                $annoRaw = $get($row, 'Anno Solare');
                $anno    = ($annoRaw !== '' && ctype_digit($annoRaw) && (int)$annoRaw > 2000) ? (int)$annoRaw : null;

                try {
                    $stmt->execute([
                        $orderCode,
                        $get($row, 'Cliente Effettivo')       ?: null,
                        $get($row, 'Cliente di Fatturazione') ?: null,
                        ($get($row, 'Progetto') ?: null),
                        $get($row, 'Descrizione')             ?: null,
                        $get($row, 'Stato')                   ?: null,
                        $get($row, 'Azienda')                 ?: null,
                        $get($row, 'Numero Documento')        ?: null,
                        $get($row, 'Tipologia')               ?: null,
                        $totale,
                        $get($row, 'Firma Commerciale')       ?: null,
                        $get($row, 'Firma Tecnica')           ?: null,
                        $get($row, 'Linea di Business')       ?: null,
                        $anno,
                        $get($row, 'Stato Contratto')         ?: null,
                        $u_id,
                        $batchId,
                    ]);
                    // rowCount: 1 = insert, 2 = update (ON DUPLICATE KEY), 0 = no change
                    $rc = $stmt->rowCount();
                    if ($rc === 1) $cntInsert++;
                    elseif ($rc === 2) $cntUpdate++;
                    else $cntUpdate++; // no change = giÃ  aggiornato con stessi dati
                } catch (Throwable $rowEx) {
                    $cntError++;
                    if (count($errors) < 10) $errors[] = "Codice '$orderCode': " . $rowEx->getMessage();
                }
            }
            $pdo->commit();
        } catch (Throwable $txEx) {
            $pdo->rollBack();
            throw $txEx;
        }

        // Aggiorna batch con totale righe
        if ($batchId) {
            try {
                $pdo->prepare("UPDATE cm_import_batches SET rows_total=? WHERE id=?")
                    ->execute([count($allRows), $batchId]);
            } catch (Throwable $e) {}
        }

        $report = [
            'success'    => true,
            'filename'   => $origName,
            'headers'    => $headers,
            'total'      => count($allRows),
            'insert'     => $cntInsert,
            'update'     => $cntUpdate,
            'skip'       => $cntSkip,
            'errors'     => $cntError,
            'error_list' => $errors,
        ];

        write_log('PratixImport', 'success',
            "Import Pratix '$origName': {$cntInsert} inseriti, {$cntUpdate} aggiornati, {$cntSkip} saltati",
            $u_id);

    } catch (Throwable $e) {
        $report = ['success' => false, 'error' => $e->getMessage()];
        write_log('PratixImport', 'error', $e->getMessage(), $u_id);
    }
}

// â”€â”€ Statistiche import precedenti â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$lastImport = null;
$totalRecords = 0;
if ($tableExists) {
    try {
        $lastImport = $pdo->query(
            "SELECT imported_at, imported_by, import_batch_id,
                    (SELECT username FROM users WHERE id = imported_by LIMIT 1) AS importer
             FROM cm_pratix_import ORDER BY imported_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $totalRecords = (int)$pdo->query("SELECT COUNT(*) FROM cm_pratix_import")->fetchColumn();
    } catch (Throwable $e) {}
}
?>
<?php require_once('header.php'); ?>

<div class="container-fluid py-4">
  <div class="d-flex align-items-center gap-3 mb-4">
    <h1 class="h3 mb-0"><i class="fa-solid fa-file-excel text-success me-2"></i>Import Pratix</h1>
    <a href="<?=url_safe('pratix_orders')?>" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="fa-solid fa-arrow-left me-1"></i>Torna a Ordinativi Pratix
    </a>
  </div>

<?php if (!$tableExists): ?>
  <div class="alert alert-warning">
    <i class="fa-solid fa-triangle-exclamation me-2"></i>
    Tabella <code>cm_pratix_import</code> non trovata. Eseguire prima <a href="<?=url_safe('db_upgrade')?>">db_upgrade.php â†’ v1.9.58</a>.
  </div>
<?php endif; ?>

<?php if ($report): ?>
  <?php if ($report['success']): ?>
    <div class="alert alert-success">
      <h5><i class="fa-solid fa-check-circle me-2"></i>Import completato: <strong><?=h($report['filename'])?></strong></h5>
      <div class="row mt-2 g-2">
        <div class="col-auto"><span class="badge bg-primary fs-6"><?=$report['total']?> righe lette</span></div>
        <div class="col-auto"><span class="badge bg-success fs-6"><?=$report['insert']?> inserite</span></div>
        <div class="col-auto"><span class="badge bg-info fs-6"><?=$report['update']?> aggiornate</span></div>
        <div class="col-auto"><span class="badge bg-secondary fs-6"><?=$report['skip']?> saltate</span></div>
        <?php if ($report['errors'] > 0): ?>
          <div class="col-auto"><span class="badge bg-danger fs-6"><?=$report['errors']?> errori</span></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($report['error_list'])): ?>
        <hr><strong>Dettaglio errori:</strong>
        <ul class="small mb-0">
          <?php foreach ($report['error_list'] as $er): ?>
            <li><?=h($er)?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($report['headers'])): ?>
        <hr><small class="text-muted">Colonne rilevate (<?=count($report['headers'])?>): <?=h(implode(', ', array_slice($report['headers'], 0, 20)))?><?= count($report['headers']) > 20 ? '...' : '' ?></small>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-danger">
      <i class="fa-solid fa-circle-xmark me-2"></i>
      <strong>Errore durante l'import:</strong> <?=h($report['error'])?>
    </div>
  <?php endif; ?>
<?php endif; ?>

  <div class="row g-4">
    <!-- Upload form -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-header"><i class="fa-solid fa-upload me-2"></i>Carica file Pratix</div>
        <div class="card-body">
          <?php if (!$can_import): ?>
            <div class="alert alert-warning mb-0">Non hai i privilegi per eseguire l'import.</div>
          <?php else: ?>
          <form method="post" enctype="multipart/form-data">
            <?=Csrf::field()?>
            <p class="text-muted small mb-3">
              Carica il file <strong>PRATIX</strong> esportato dal gestionale.<br>
              Formati supportati: <code>.xls</code> (Excel 97-2003) e <code>.xlsx</code> (Excel moderno).<br>
              La chiave di join Ã¨ la colonna <strong>Codice</strong> del file â†’ campo <code>order_code</code> del portale.
            </p>
            <div class="mb-3">
              <label class="form-label fw-semibold">File Excel Pratix</label>
              <input type="file" name="file" class="form-control" accept=".xls,.xlsx" required>
              <div class="form-text">Dimensione massima: <?=ini_get('upload_max_filesize')?></div>
            </div>
            <div class="alert alert-info small mb-3">
              <strong>Colonne importate:</strong> Codice, Cliente Effettivo, Cliente di Fatturazione,
              Progetto, Descrizione, Stato, Azienda, Numero Documento, Tipologia, Totale,
              Firma Commerciale, Firma Tecnica, Linea di Business, Anno Solare, Stato Contratto
            </div>
            <button type="submit" class="btn btn-success">
              <i class="fa-solid fa-file-import me-2"></i>Importa dati Pratix
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Status panel -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-header"><i class="fa-solid fa-database me-2"></i>Stato dati importati</div>
        <div class="card-body">
          <?php if (!$tableExists): ?>
            <p class="text-muted mb-0">Tabella non ancora creata.</p>
          <?php else: ?>
            <dl class="row mb-0">
              <dt class="col-sm-6">Record totali</dt>
              <dd class="col-sm-6"><strong class="text-primary"><?=number_format($totalRecords, 0, ',', '.')?></strong></dd>
              <?php if ($lastImport): ?>
                <dt class="col-sm-6">Ultimo import</dt>
                <dd class="col-sm-6"><?=date('d/m/Y H:i', strtotime($lastImport['imported_at']))?></dd>
                <dt class="col-sm-6">Importato da</dt>
                <dd class="col-sm-6"><?=h($lastImport['importer'] ?? 'N/D')?></dd>
              <?php else: ?>
                <dt class="col-sm-6">Nessun import</dt>
                <dd class="col-sm-6 text-muted">â€”</dd>
              <?php endif; ?>
            </dl>
            <hr>
            <a href="<?=url_safe('pratix_orders')?>" class="btn btn-sm btn-outline-primary">
              <i class="fa-solid fa-table me-1"></i>Visualizza Ordinativi Pratix
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-header"><i class="fa-solid fa-circle-info me-2"></i>Istruzioni</div>
        <div class="card-body small text-muted">
          <ol class="mb-0 ps-3">
            <li>Esporta il file dal gestionale Pratix (menu Ordinativi â†’ Export Excel).</li>
            <li>Salva il file in formato <code>.xls</code> o <code>.xlsx</code>.</li>
            <li>Carica il file tramite il form a sinistra.</li>
            <li>Il sistema esegue un <strong>upsert</strong>: i record esistenti vengono aggiornati, i nuovi inseriti, quelli senza Codice saltati.</li>
            <li>I dati importati sono visibili nella pagina <a href="<?=url_safe('pratix_orders')?>">Ordinativi Pratix</a> nelle colonne <em>(da Pratix)</em>.</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once('footer.php'); ?>