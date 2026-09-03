<?php
/**
 * certV 2.4 — api_cert_history.php
 * Endpoint AJAX per storico modifiche certificazioni.
 */
require_once __DIR__ . '/app/bootstrap.php';

if (empty($_SESSION['user_id'])) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json; charset=utf-8');

$cert_id = (int)($_GET['cert_id'] ?? 0);
if (!$cert_id) { echo '[]'; exit; }

try {
    $s = $pdo->prepare(
        "SELECT cv.*, u.display_name author
         FROM certification_versions cv
         LEFT JOIN users u ON cv.changed_by = u.id
         WHERE cv.certification_id = ?
         ORDER BY cv.version DESC, cv.changed_at DESC"
    );
    $s->execute([$cert_id]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['changed_at'] = $r['changed_at'] ? date('d/m/Y H:i', strtotime($r['changed_at'])) : null;
    }
    echo json_encode($rows);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
