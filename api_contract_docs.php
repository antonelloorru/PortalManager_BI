<?php
/**
 * certV 2.4 — api_contract_docs.php
 * Endpoint AJAX per lo storico versioni documenti contrattuali.
 * Restituisce JSON con tutte le versioni di un contratto.
 */
require_once __DIR__ . '/app/bootstrap.php';

if (empty($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 99) > 2) {
    http_response_code(403);
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$contract_id = (int)($_GET['contract_id'] ?? 0);
if (!$contract_id) {
    echo json_encode([]);
    exit;
}

try {
    $s = $pdo->prepare(
        "SELECT cd.*, u.display_name uploader_name
         FROM contract_documents cd
         LEFT JOIN users u ON cd.uploaded_by = u.id
         WHERE cd.contract_id = ?
         ORDER BY cd.version DESC"
    );
    $s->execute([$contract_id]);
    $docs = $s->fetchAll(PDO::FETCH_ASSOC);

    // Formatta date per il frontend
    foreach ($docs as &$d) {
        $d['created_at'] = $d['created_at'] ? date('d/m/Y H:i', strtotime($d['created_at'])) : null;
        $d['signed_date'] = $d['signed_date'] ? date('d/m/Y', strtotime($d['signed_date'])) : null;
        $d['archived_at'] = $d['archived_at'] ? date('d/m/Y H:i', strtotime($d['archived_at'])) : null;
    }

    echo json_encode($docs);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
