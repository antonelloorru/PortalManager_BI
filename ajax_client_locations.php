<?php
/**
 * ajax_client_locations.php — Cascade select Cliente -> Sede cliente (v1.7.59)
 */
require_once('access_control.php');
if (!can('view', 'manage_projects.php')) { http_response_code(403); exit('[]'); }
header('Content-Type: application/json');
$cid = (int)($_GET['client_id'] ?? 0);
$st = $pdo->prepare("SELECT id, location_name FROM client_locations WHERE client_id=? ORDER BY location_name");
$st->execute([$cid]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
