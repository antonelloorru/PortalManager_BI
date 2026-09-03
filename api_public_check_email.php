<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.26 — POST verifica email (schema reale).
 * Cerca in candidates.email (case-insensitive via collation _ci) e
 * segnala presenza di candidatura attiva (candidate_applications.stage).
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/app/PublicApiAuth.php';
require_once __DIR__ . '/app/ApiResponse.php';
require_once __DIR__ . '/app/CareersSettings.php';

use App\PublicApiAuth;
use App\ApiResponse;
use App\CareersSettings;

$reqId    = ApiResponse::newRequestId();
$auth     = new PublicApiAuth($pdo);
$settings = new CareersSettings($pdo);
$clientId = null;

$originsCsv = (string)($pdo->query("SELECT GROUP_CONCAT(allowed_origins SEPARATOR ',') FROM public_api_clients WHERE is_active=1")->fetchColumn() ?: '');
ApiResponse::cors($originsCsv);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('method_not_allowed', 405);
    $raw = ApiResponse::rawBody();
    $client = $auth->authenticate('POST', '/api_public_check_email.php', $raw, ApiResponse::readHeaders());
    $clientId = (string)$client['client_id'];
    $auth->requireScope($client, 'candidates:check');

    $ipLimit = $settings->getInt('careers.rate_email_check_per_hour', 20);
    $auth->rateLimit('ip:' . PublicApiAuth::clientIp(), 'email_check', $ipLimit, 3600);
    $auth->rateLimit('client:' . $clientId, 'email_check', 100, 3600);

    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $email = strtolower(trim((string)($body['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        throw new RuntimeException('invalid_email', 400);
    }

    $exists = false; $asEmployee = false; $hasApplication = false;

    $q = $pdo->prepare("SELECT 1 FROM candidates WHERE LOWER(TRIM(email)) = ? AND (deleted_at IS NULL OR deleted_at IS NOT NULL) LIMIT 1");
    $q->execute([$email]); $exists = (bool)$q->fetchColumn();

    // Impiegato riconosciuto: business_email OR personal_email
    $q = $pdo->prepare("SELECT 1 FROM employees WHERE LOWER(TRIM(COALESCE(business_email,''))) = ? OR LOWER(TRIM(COALESCE(personal_email,''))) = ? LIMIT 1");
    $q->execute([$email, $email]); $asEmployee = (bool)$q->fetchColumn();

    if ($exists) {
        $q = $pdo->prepare(
            "SELECT 1 FROM candidate_applications a
             JOIN candidates c ON c.id = a.candidate_id
             WHERE LOWER(TRIM(c.email)) = ?
               AND a.stage IN ('cv_received','screening','tech_test','hr_interview','tech_interview','offer_sent')
             LIMIT 1"
        );
        $q->execute([$email]);
        $hasApplication = (bool)$q->fetchColumn();
    }

    $auth->audit($clientId, 'email_check', 'POST', 200, $reqId);
    ApiResponse::json(200, [
        'ok' => true,
        'known' => $exists || $asEmployee,
        'is_employee' => $asEmployee,
        'has_active_application' => $hasApplication,
    ], $reqId);
} catch (RuntimeException $e) {
    $code = (int)$e->getCode(); if ($code < 400 || $code > 599) $code = 500;
    $auth->audit($clientId, 'email_check', 'POST', $code, $reqId, $e->getMessage());
    ApiResponse::json($code, ['ok' => false, 'error' => $e->getMessage()], $reqId);
} catch (\JsonException) {
    $auth->audit($clientId, 'email_check', 'POST', 400, $reqId, 'bad_json');
    ApiResponse::json(400, ['ok' => false, 'error' => 'bad_json'], $reqId);
} catch (\Throwable $e) {
    $auth->audit($clientId, 'email_check', 'POST', 500, $reqId, 'server_error');
    error_log('[api_public_check_email] ' . $e->getMessage());
    ApiResponse::json(500, ['ok' => false, 'error' => 'server_error'], $reqId);
}
