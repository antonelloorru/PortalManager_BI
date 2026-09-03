<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.26 — GET posizioni aperte (schema reale).
 * View: v_public_open_positions
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/app/PublicApiAuth.php';
require_once __DIR__ . '/app/ApiResponse.php';

use App\PublicApiAuth;
use App\ApiResponse;

$reqId    = ApiResponse::newRequestId();
$auth     = new PublicApiAuth($pdo);
$clientId = null;

$originsCsv = (string)($pdo->query("SELECT GROUP_CONCAT(allowed_origins SEPARATOR ',') FROM public_api_clients WHERE is_active=1")->fetchColumn() ?: '');
ApiResponse::cors($originsCsv);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new RuntimeException('method_not_allowed', 405);

    $client = $auth->authenticate('GET', '/api_public_positions.php', '', ApiResponse::readHeaders());
    $clientId = (string)$client['client_id'];
    $auth->requireScope($client, 'positions:read');
    $auth->rateLimit('client:' . $clientId, 'positions', 60, 60);

    $q      = trim((string)($_GET['q'] ?? ''));
    $dept   = trim((string)($_GET['department'] ?? ''));
    $loc    = trim((string)($_GET['location'] ?? ''));
    $limit  = min(100, max(1, (int)($_GET['limit']  ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where = []; $bind = [];
    if ($q !== '')    { $where[] = '(title LIKE ? OR description LIKE ?)'; $bind[] = "%$q%"; $bind[] = "%$q%"; }
    if ($dept !== '') { $where[] = 'department = ?'; $bind[] = $dept; }
    if ($loc !== '')  { $where[] = 'location = ?';   $bind[] = $loc; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT id, title, department, location, contract_type, remote_policy,
                   description, required_skills, nice_to_have, hard_skills, soft_skills,
                   benefits, we_offer, presentation_text, offer_info,
                   positions_expected, hires_count, opened_at, target_date
            FROM v_public_open_positions
            $whereSql
            ORDER BY opened_at DESC, id DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = (int)$pdo->query("SELECT COUNT(*) FROM v_public_open_positions " . $whereSql)->fetchColumn();

    $auth->audit($clientId, 'positions', 'GET', 200, $reqId);
    ApiResponse::json(200, ['ok' => true, 'total' => $total, 'items' => $rows], $reqId);
} catch (RuntimeException $e) {
    $code = (int)$e->getCode(); if ($code < 400 || $code > 599) $code = 500;
    $auth->audit($clientId, 'positions', 'GET', $code, $reqId, $e->getMessage());
    ApiResponse::json($code, ['ok' => false, 'error' => $e->getMessage()], $reqId);
} catch (\Throwable $e) {
    $auth->audit($clientId, 'positions', 'GET', 500, $reqId, 'server_error');
    error_log('[api_public_positions] ' . $e->getMessage());
    ApiResponse::json(500, ['ok' => false, 'error' => 'server_error'], $reqId);
}
