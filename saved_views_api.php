<?php
/**
 * ════════════════════════════════════════════════════════════════════════
 * PortalManager — app/saved_views_api.php
 *
 * API endpoint per:
 *   - CRUD viste salvate (azioni: list, save, delete)
 *   - Generazione export server-side (XLSX, DOCX) — il browser invia
 *     i dati visibili e questo endpoint genera il file
 *
 * Sicurezza: richiede sessione utente attiva. Le viste sono per-user
 * (l'utente vede solo le proprie + le condivise).
 * ════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// v1.8.39: cattura QUALSIASI output prodotto dagli include o da eventuali warning,
// così da non contaminare/troncare gli export binari (XLSX/DOCX).
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_start();

// ── Bootstrap minimo (sicuro per richieste AJAX) ──
// v1.7.36: file spostato da app/ a root per evitare blocco .htaccess su app/*.php
// Path bootstrap aggiornato: era './bootstrap.php' (quando in app/), ora 'app/bootstrap.php'
require_once __DIR__ . '/app/bootstrap.php';

// v1.8.39: la sessione è già avviata dal bootstrap. Chiamare di nuovo session_start()
// generava il Notice "Ignoring session_start() because a session is already active"
// che veniva stampato in testa al file esportato, rendendolo non apribile.
if (session_status() !== PHP_SESSION_ACTIVE) { Session::start(); }

// Verifica autenticazione
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Sessione scaduta. Effettua nuovamente il login.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? $_POST['action'] ?? '';

// Router azioni
try {
    switch ($action) {
        case 'list':   handle_list($user_id);   break;
        case 'save':   handle_save($user_id);   break;
        case 'delete': handle_delete($user_id); break;
        case 'export': handle_export();         break;
        default:
            http_response_code(400);
            json_error("Azione non riconosciuta: '$action'");
    }
} catch (Throwable $e) {
    http_response_code(500);
    json_error('Errore server: ' . $e->getMessage());
}


// ════════════════════════════════════════════════════════════════════════
// HANDLER: LIST viste per pagina
// ════════════════════════════════════════════════════════════════════════
function handle_list(int $user_id): void
{
    global $pdo;

    $page_name = trim((string)($_GET['page_name'] ?? ''));
    if ($page_name === '') {
        json_error('page_name mancante');
    }

    $stmt = $pdo->prepare(
        "SELECT id, user_id, page_name, name, filters_json, is_shared, created_at, updated_at
           FROM saved_views
          WHERE page_name = ?
            AND (user_id = ? OR is_shared = 1)
          ORDER BY is_shared ASC, updated_at DESC"
    );
    $stmt->execute([$page_name, $user_id]);
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decodifico filters_json
    foreach ($views as &$v) {
        $v['filters'] = json_decode($v['filters_json'] ?? '{}', true) ?: new stdClass();
        unset($v['filters_json']);
        $v['is_owner'] = ((int)$v['user_id'] === $user_id);
    }

    json_response(['success' => true, 'views' => $views]);
}


// ════════════════════════════════════════════════════════════════════════
// HANDLER: SAVE vista
// ════════════════════════════════════════════════════════════════════════
function handle_save(int $user_id): void
{
    global $pdo;

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_error('Payload JSON non valido');
    }

    $page_name = trim((string)($body['page_name'] ?? ''));
    $name      = trim((string)($body['name']      ?? ''));
    $filters   = $body['filters']   ?? new stdClass();
    $is_shared = !empty($body['is_shared']) ? 1 : 0;

    if ($page_name === '' || $name === '') {
        json_error('Campi obbligatori mancanti (page_name, name)');
    }
    if (strlen($name) > 120) {
        json_error('Nome troppo lungo (max 120 caratteri)');
    }

    $filters_json = json_encode($filters, JSON_UNESCAPED_UNICODE);
    if ($filters_json === false || strlen($filters_json) > 64000) {
        json_error('Filtri troppo grandi o JSON non valido');
    }

    // INSERT o UPDATE se esiste già stesso nome per stesso utente+pagina
    $stmt = $pdo->prepare(
        "SELECT id FROM saved_views
          WHERE user_id = ? AND page_name = ? AND name = ?
          LIMIT 1"
    );
    $stmt->execute([$user_id, $page_name, $name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare(
            "UPDATE saved_views
                SET filters_json = ?, is_shared = ?, updated_at = NOW()
              WHERE id = ?"
        );
        $stmt->execute([$filters_json, $is_shared, (int)$existing['id']]);
        $view_id = (int)$existing['id'];
        $msg = 'Vista aggiornata';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO saved_views (user_id, page_name, name, filters_json, is_shared, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([$user_id, $page_name, $name, $filters_json, $is_shared]);
        $view_id = (int)$pdo->lastInsertId();
        $msg = 'Vista salvata';
    }

    json_response(['success' => true, 'view_id' => $view_id, 'message' => $msg]);
}


// ════════════════════════════════════════════════════════════════════════
// HANDLER: DELETE vista
// ════════════════════════════════════════════════════════════════════════
function handle_delete(int $user_id): void
{
    global $pdo;

    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) json_error('id vista mancante');

    // Solo il proprietario può eliminare
    $stmt = $pdo->prepare(
        "DELETE FROM saved_views WHERE id = ? AND user_id = ?"
    );
    $stmt->execute([$id, $user_id]);

    if ($stmt->rowCount() === 0) {
        json_error('Vista non trovata o non autorizzato');
    }

    json_response(['success' => true, 'message' => 'Vista eliminata']);
}


// ════════════════════════════════════════════════════════════════════════
// HANDLER: EXPORT (XLSX o DOCX)
// Riceve da POST: format, filename, title, payload (JSON con headers e rows)
// ════════════════════════════════════════════════════════════════════════
function handle_export(): void
{
    $format    = (string)($_POST['format']   ?? 'csv');
    $filename  = (string)($_POST['filename'] ?? 'export');
    $title     = (string)($_POST['title']    ?? 'Export');
    $payload   = (string)($_POST['payload']  ?? '');

    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);

    $data = json_decode($payload, true);
    if (!is_array($data) || !isset($data['headers']) || !isset($data['rows'])) {
        http_response_code(400);
        echo 'Payload non valido.';
        return;
    }

    $headers = array_map('strval', $data['headers']);
    $rows = array_map(function($r) {
        return array_map('strval', is_array($r) ? $r : []);
    }, $data['rows']);

    switch ($format) {
        case 'xlsx': export_xlsx($filename, $title, $headers, $rows); break;
        case 'docx': export_docx($filename, $title, $headers, $rows); break;
        case 'odt':  export_odt($filename, $title, $headers, $rows);  break;   // v1.9.71
        default:
            http_response_code(400);
            echo 'Formato non supportato: ' . htmlspecialchars($format);
    }
}


// ════════════════════════════════════════════════════════════════════════
// XLSX MINIMAL (no librerie esterne)
// XLSX è un ZIP contenente OOXML: [Content_Types].xml, _rels/, xl/workbook.xml,
// xl/worksheets/sheet1.xml, xl/sharedStrings.xml
// ════════════════════════════════════════════════════════════════════════
function export_xlsx(string $filename, string $title, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZipArchive non disponibile (estensione PHP zip richiesta).';
        return;
    }

    // v1.8.39: sanitizza i valori — rimuove caratteri di controllo e sequenze
    // UTF-8 non valide che renderebbero l'XML (quindi l'XLSX) non apribile.
    $san = static function ($v): string {
        $v = (string)$v;
        if (function_exists('mb_convert_encoding')) { $v = @mb_convert_encoding($v, 'UTF-8', 'UTF-8'); }
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    };
    $headers = array_map($san, $headers);
    $rows    = array_map(static fn($r) => array_map($san, (array)$r), $rows);

    // ── 1) Raccogli stringhe uniche per sharedStrings ──
    $strings = [];
    $string_index = [];
    foreach (array_merge([$headers], $rows) as $row) {
        foreach ($row as $cell) {
            if (!isset($string_index[$cell])) {
                $string_index[$cell] = count($strings);
                $strings[] = $cell;
            }
        }
    }

    // ── 2) Build worksheet XML ──
    $sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
               . '<sheetData>';

    $build_row = function (array $row, int $rowNum) use ($string_index, &$sheet_xml) {
        $sheet_xml .= '<row r="' . $rowNum . '">';
        foreach ($row as $i => $cell) {
            $col = chr(65 + ($i % 26));   // A-Z (semplificato: max 26 colonne)
            if ($i >= 26) {
                // Per oltre la Z: AA, AB, ecc. (max 702 col supportate)
                $col = chr(65 + intdiv($i, 26) - 1) . chr(65 + ($i % 26));
            }
            $ref = $col . $rowNum;
            // Tutte le celle come stringhe (sharedStrings index)
            $idx = $string_index[$cell];
            $style = $rowNum === 1 ? ' s="1"' : '';  // Riga header con stile bold
            $sheet_xml .= '<c r="' . $ref . '" t="s"' . $style . '><v>' . $idx . '</v></c>';
        }
        $sheet_xml .= '</row>';
    };

    $build_row($headers, 1);
    foreach ($rows as $i => $r) $build_row($r, $i + 2);

    $sheet_xml .= '</sheetData></worksheet>';

    // ── 3) Build sharedStrings XML ──
    $ss_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) {
        $ss_xml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }
    $ss_xml .= '</sst>';

    // ── 4) Build workbook.xml ──
    $wb_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars(substr($title, 0, 31), ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

    // ── 5) Build styles.xml (header bold) ──
    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2">'
                .   '<font><sz val="11"/><name val="Calibri"/></font>'
                .   '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
                . '</fonts>'
                . '<fills count="3">'
                .   '<fill><patternFill patternType="none"/></fill>'
                .   '<fill><patternFill patternType="gray125"/></fill>'
                .   '<fill><patternFill patternType="solid"><fgColor rgb="FF003399"/></patternFill></fill>'
                . '</fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2">'
                .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
                . '</cellXfs>'
                . '</styleSheet>';

    // ── 6) Build [Content_Types].xml + _rels ──
    $ct_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

    $root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
               . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
               . '</Relationships>';

    $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
             . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
             . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
             . '</Relationships>';

    // ── 7) Costruisci ZIP in memoria ──
    $tmpfile = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmpfile, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Errore creazione XLSX';
        return;
    }
    $zip->addFromString('[Content_Types].xml', $ct_xml);
    $zip->addFromString('_rels/.rels', $root_rels);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
    $zip->addFromString('xl/workbook.xml', $wb_xml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
    $zip->addFromString('xl/sharedStrings.xml', $ss_xml);
    $zip->addFromString('xl/styles.xml', $styles_xml);
    $zip->close();

    // ── 8) Output ──
    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . filesize($tmpfile));
    readfile($tmpfile);
    unlink($tmpfile);
    exit;
}


// ════════════════════════════════════════════════════════════════════════
// DOCX MINIMAL (no librerie esterne) — tabella riepilogativa
// ════════════════════════════════════════════════════════════════════════
function export_docx(string $filename, string $title, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'ZipArchive non disponibile';
        return;
    }

    // v1.8.39: sanitizza i valori (vedi export_xlsx)
    $san = static function ($v): string {
        $v = (string)$v;
        if (function_exists('mb_convert_encoding')) { $v = @mb_convert_encoding($v, 'UTF-8', 'UTF-8'); }
        return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    };
    $headers = array_map($san, $headers);
    $rows    = array_map(static fn($r) => array_map($san, (array)$r), $rows);

    // Costruisco la tabella OOXML
    $col_count = count($headers);
    $col_width = (int)(9000 / max($col_count, 1));   // distribuzione larghezza

    // Helper
    $cell_xml = function (string $text, bool $bold = false, ?string $fill = null) {
        $bold_xml = $bold ? '<w:b/>' : '';
        $color_xml = $bold ? '<w:color w:val="FFFFFF"/>' : '';
        $shd = $fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '';
        return '<w:tc><w:tcPr><w:tcBorders>'
             . '<w:top w:val="single" w:sz="4" w:color="CCCCCC"/>'
             . '<w:left w:val="single" w:sz="4" w:color="CCCCCC"/>'
             . '<w:bottom w:val="single" w:sz="4" w:color="CCCCCC"/>'
             . '<w:right w:val="single" w:sz="4" w:color="CCCCCC"/>'
             . '</w:tcBorders>'
             . $shd
             . '</w:tcPr>'
             . '<w:p><w:pPr><w:spacing w:before="40" w:after="40"/></w:pPr>'
             . '<w:r><w:rPr><w:sz w:val="18"/>' . $bold_xml . $color_xml . '</w:rPr>'
             . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t>'
             . '</w:r></w:p></w:tc>';
    };

    $table_xml = '<w:tbl>'
               . '<w:tblPr><w:tblW w:w="9000" w:type="dxa"/></w:tblPr>'
               . '<w:tblGrid>';
    for ($i = 0; $i < $col_count; $i++) $table_xml .= '<w:gridCol w:w="' . $col_width . '"/>';
    $table_xml .= '</w:tblGrid>';

    // Header row (sfondo blu UE, testo bianco bold)
    $table_xml .= '<w:tr>';
    foreach ($headers as $h) $table_xml .= $cell_xml($h, true, '003399');
    $table_xml .= '</w:tr>';

    // Data rows (alternati)
    foreach ($rows as $i => $r) {
        $fill = ($i % 2 === 1) ? 'F8FAFC' : null;
        $table_xml .= '<w:tr>';
        // Padding cells se row più corto di headers
        for ($c = 0; $c < $col_count; $c++) {
            $table_xml .= $cell_xml($r[$c] ?? '', false, $fill);
        }
        $table_xml .= '</w:tr>';
    }
    $table_xml .= '</w:tbl>';

    $title_display = ucfirst(str_replace('_', ' ', $title));
    $now = date('d/m/Y H:i');

    // Document body
    $doc_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
             . '<w:body>'
             // Titolo
             . '<w:p><w:pPr><w:spacing w:before="0" w:after="120"/></w:pPr>'
             . '<w:r><w:rPr><w:b/><w:sz w:val="32"/><w:color w:val="003399"/></w:rPr>'
             . '<w:t>' . htmlspecialchars($title_display, ENT_XML1) . '</w:t></w:r></w:p>'
             // Sotto-titolo
             . '<w:p><w:pPr><w:spacing w:before="0" w:after="240"/></w:pPr>'
             . '<w:r><w:rPr><w:sz w:val="18"/><w:color w:val="64748B"/></w:rPr>'
             . '<w:t>Esportato il ' . $now . ' · ' . count($rows) . ' righe · PortalManager</w:t></w:r></w:p>'
             // Tabella
             . $table_xml
             // Footer
             . '<w:p><w:pPr><w:spacing w:before="240"/></w:pPr></w:p>'
             . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr>'
             . '</w:body></w:document>';

    // Content Types + rels
    $ct_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

    $root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
               . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
               . '</Relationships>';

    // Build ZIP
    $tmpfile = tempnam(sys_get_temp_dir(), 'docx_');
    $zip = new ZipArchive();
    if ($zip->open($tmpfile, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Errore creazione DOCX';
        return;
    }
    $zip->addFromString('[Content_Types].xml', $ct_xml);
    $zip->addFromString('_rels/.rels', $root_rels);
    $zip->addFromString('word/document.xml', $doc_xml);
    $zip->close();

    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '.docx"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . filesize($tmpfile));
    readfile($tmpfile);
    unlink($tmpfile);
    exit;
}


// ════════════════════════════════════════════════════════════════════════
// v1.9.71 — ODT (OpenDocument Text, LibreOffice/OpenOffice) senza librerie.
// Pacchetto ODF: "mimetype" come PRIMA voce e NON compressa (requisito della
// specifica), META-INF/manifest.xml, content.xml, styles.xml, meta.xml.
// Oltre 6 colonne la pagina è orizzontale.
// ════════════════════════════════════════════════════════════════════════
function export_odt(string $filename, string $title, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) { http_response_code(500); echo 'ZipArchive non disponibile'; return; }

    $san = static function ($v): string {
        $v = (string)$v;
        if (function_exists('mb_convert_encoding')) { $v = @mb_convert_encoding($v, 'UTF-8', 'UTF-8'); }
        $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $cols      = max(1, count($headers));
    $landscape = $cols > 6;
    $pageW     = $landscape ? '29.7cm' : '21cm';
    $pageH     = $landscape ? '21cm'   : '29.7cm';
    $bodyW     = $landscape ? 27.7 : 19.0;
    $colW      = number_format($bodyW / $cols, 3, '.', '') . 'cm';
    $titleTxt  = $san(ucfirst(str_replace('_', ' ', $title)));
    $sub       = $san('Esportato il ' . date('d/m/Y H:i') . ' · ' . count($rows) . ' righe · PortalManager');

    $ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        . 'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
        . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
        . 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        . 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
        . 'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" office:version="1.2"';

    $cell = static function (string $txt, string $cs, string $ps): string {
        return '<table:table-cell table:style-name="' . $cs . '" office:value-type="string">'
             . '<text:p text:style-name="' . $ps . '">' . $txt . '</text:p></table:table-cell>';
    };
    $tbl = '<table:table table:name="Dati" table:style-name="Tab">'
         . '<table:table-column table:style-name="Col" table:number-columns-repeated="' . $cols . '"/>'
         . '<table:table-header-rows><table:table-row>';
    for ($c = 0; $c < $cols; $c++) $tbl .= $cell($san($headers[$c] ?? ''), 'CellH', 'PH');
    $tbl .= '</table:table-row></table:table-header-rows>';
    foreach ($rows as $i => $r) {
        $cs = ($i % 2 === 1) ? 'CellZ' : 'CellD';
        $tbl .= '<table:table-row>';
        for ($c = 0; $c < $cols; $c++) $tbl .= $cell($san($r[$c] ?? ''), $cs, 'PD');
        $tbl .= '</table:table-row>';
    }
    $tbl .= '</table:table>';

    $b = '0.5pt solid #cccccc';
    $content = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content ' . $ns . '><office:automatic-styles>'
        . '<style:style style:name="Tab" style:family="table"><style:table-properties style:width="' . $bodyW . 'cm" table:align="left"/></style:style>'
        . '<style:style style:name="Col" style:family="table-column"><style:table-column-properties style:column-width="' . $colW . '"/></style:style>'
        . '<style:style style:name="CellH" style:family="table-cell"><style:table-cell-properties fo:background-color="#003399" fo:padding="0.06cm" fo:border="' . $b . '"/></style:style>'
        . '<style:style style:name="CellD" style:family="table-cell"><style:table-cell-properties fo:padding="0.06cm" fo:border="' . $b . '"/></style:style>'
        . '<style:style style:name="CellZ" style:family="table-cell"><style:table-cell-properties fo:background-color="#f8fafc" fo:padding="0.06cm" fo:border="' . $b . '"/></style:style>'
        . '<style:style style:name="PH" style:family="paragraph"><style:text-properties fo:font-size="9pt" fo:font-weight="bold" fo:color="#ffffff"/></style:style>'
        . '<style:style style:name="PD" style:family="paragraph"><style:text-properties fo:font-size="9pt"/></style:style>'
        . '<style:style style:name="PT" style:family="paragraph"><style:paragraph-properties fo:margin-bottom="0.15cm"/><style:text-properties fo:font-size="16pt" fo:font-weight="bold" fo:color="#003399"/></style:style>'
        . '<style:style style:name="PS" style:family="paragraph"><style:paragraph-properties fo:margin-bottom="0.35cm"/><style:text-properties fo:font-size="9pt" fo:color="#64748b"/></style:style>'
        . '</office:automatic-styles><office:body><office:text>'
        . '<text:p text:style-name="PT">' . $titleTxt . '</text:p>'
        . '<text:p text:style-name="PS">' . $sub . '</text:p>'
        . $tbl . '</office:text></office:body></office:document-content>';

    $styles = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-styles ' . $ns . '>'
        . '<office:styles><style:default-style style:family="paragraph"><style:text-properties fo:font-size="10pt"/></style:default-style></office:styles>'
        . '<office:automatic-styles><style:page-layout style:name="PL"><style:page-layout-properties fo:page-width="' . $pageW . '" fo:page-height="' . $pageH . '" style:print-orientation="' . ($landscape ? 'landscape' : 'portrait') . '" fo:margin-top="1cm" fo:margin-bottom="1cm" fo:margin-left="1cm" fo:margin-right="1cm"/></style:page-layout></office:automatic-styles>'
        . '<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="PL"/></office:master-styles>'
        . '</office:document-styles>';

    $meta = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-meta ' . $ns . '><office:meta><meta:generator>PortalManager</meta:generator>'
        . '<dc:title>' . $titleTxt . '</dc:title><meta:creation-date>' . date('Y-m-d\TH:i:s') . '</meta:creation-date>'
        . '</office:meta></office:document-meta>';

    $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
        . '<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
        . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
        . '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
        . '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
        . '</manifest:manifest>';

    $tmp = tempnam(sys_get_temp_dir(), 'odt_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); echo 'Errore creazione ODT'; return; }
    $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('META-INF/manifest.xml', $manifest);
    $zip->addFromString('content.xml', $content);
    $zip->addFromString('styles.xml', $styles);
    $zip->addFromString('meta.xml', $meta);
    $zip->close();

    if (PHP_SAPI === 'cli' && getenv('PM_ODT_TEST_OUT')) { rename($tmp, (string)getenv('PM_ODT_TEST_OUT')); return; }  // test automatici
    while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: application/vnd.oasis.opendocument.text');
    header('Content-Disposition: attachment; filename="' . $filename . '.odt"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}



// ════════════════════════════════════════════════════════════════════════
// Helpers JSON
// ════════════════════════════════════════════════════════════════════════
function json_response(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg): void
{
    http_response_code(400);
    json_response(['success' => false, 'error' => $msg]);
}
