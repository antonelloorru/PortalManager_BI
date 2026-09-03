<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.26 — POST candidatura + CV (schema reale).
 * Scrittura:
 *   - candidates (upsert per email; source='Portale'; gdpr_consent/gdpr_date)
 *   - candidate_documents (doc_type='cv')
 *   - candidate_applications (stage='cv_received'; UNIQUE candidate_id+position_id)
 * File: uploads/cv_imports/cand_<id>_cv_<ts>.<ext>   (allineato al pattern esistente)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/app/PublicApiAuth.php';
require_once __DIR__ . '/app/ApiResponse.php';
require_once __DIR__ . '/app/CareersSettings.php';
require_once __DIR__ . '/app/CvUploadValidator.php';

use App\PublicApiAuth;
use App\ApiResponse;
use App\CareersSettings;
use App\CvUploadValidator;

$reqId    = ApiResponse::newRequestId();
$auth     = new PublicApiAuth($pdo);
$settings = new CareersSettings($pdo);
$clientId = null;

$originsCsv = (string)($pdo->query("SELECT GROUP_CONCAT(allowed_origins SEPARATOR ',') FROM public_api_clients WHERE is_active=1")->fetchColumn() ?: '');
ApiResponse::cors($originsCsv);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('method_not_allowed', 405);
    $raw = ApiResponse::rawBody();
    $client = $auth->authenticate('POST', '/api_public_apply.php', $raw, ApiResponse::readHeaders());
    $clientId = (string)$client['client_id'];
    $auth->requireScope($client, 'applications:write');

    $auth->rateLimit('ip:' . PublicApiAuth::clientIp(), 'apply', $settings->getInt('careers.rate_apply_per_day', 5), 86400);
    $auth->rateLimit('client:' . $clientId, 'apply', 200, 86400);

    $positionId = (int)($_POST['position_id'] ?? 0);
    $email      = strtolower(trim((string)($_POST['email'] ?? '')));
    $first      = trim((string)($_POST['first_name'] ?? ''));
    $last       = trim((string)($_POST['last_name']  ?? ''));
    $phone      = trim((string)($_POST['phone'] ?? ''));
    $linkedin   = trim((string)($_POST['linkedin_url'] ?? ''));
    $notice     = trim((string)($_POST['availability'] ?? ''));      // → notice_period
    $ralExp     = trim((string)($_POST['salary_expectation'] ?? '')); // → ral_requested
    $cover      = trim((string)($_POST['cover_letter'] ?? ''));
    $consP      = (int)($_POST['consent_privacy']   ?? 0) === 1;
    $consM      = (int)($_POST['consent_marketing'] ?? 0) === 1;

    if ($positionId <= 0)                           throw new RuntimeException('missing_position', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('invalid_email', 400);
    if ($first === '' || mb_strlen($first) > 100)   throw new RuntimeException('invalid_first_name', 400);
    if ($last  === '' || mb_strlen($last)  > 100)   throw new RuntimeException('invalid_last_name',  400);
    if ($linkedin !== '' && !filter_var($linkedin, FILTER_VALIDATE_URL)) throw new RuntimeException('invalid_linkedin', 400);
    if (!$consP)                                    throw new RuntimeException('privacy_consent_required', 400);
    if (mb_strlen($cover) > 20000)                  throw new RuntimeException('cover_too_long', 400);

    $stmt = $pdo->prepare("SELECT id, title FROM v_public_open_positions WHERE id = ? LIMIT 1");
    $stmt->execute([$positionId]);
    $pos = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pos) throw new RuntimeException('position_not_open', 409);

    if (!isset($_FILES['cv']) || !is_array($_FILES['cv'])) throw new RuntimeException('cv_missing', 400);
    $maxBytes = $settings->getInt('careers.cv_max_bytes', 5242880);
    $mimeList = $settings->getCsv('careers.cv_allowed_mime', [
        'application/pdf','application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);
    $storeRel = $settings->get('careers.storage_path', 'uploads/cv_imports');
    $storeAbs = realpath(__DIR__) . '/' . ltrim((string)$storeRel, '/');
    $sourceTag = (string)$settings->get('careers.public_source_tag', 'Portale');

    $pdo->beginTransaction();

    // 1) Upsert candidato per email (case-insensitive)
    $stmt = $pdo->prepare("SELECT id FROM candidates WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    $stmt->execute([$email]);
    $existing = $stmt->fetchColumn();

    $ralNum = null;
    if ($ralExp !== '') {
        $norm = str_replace([' ','€','.',','], ['','','','.'], $ralExp);
        if (is_numeric($norm)) $ralNum = (float)$norm;
    }

    if ($existing) {
        $candidateId = (int)$existing;
        $u = $pdo->prepare(
            "UPDATE candidates SET
                first_name = ?, last_name = ?,
                phone         = COALESCE(NULLIF(?, ''), phone),
                linkedin_url  = COALESCE(NULLIF(?, ''), linkedin_url),
                notice_period = COALESCE(NULLIF(?, ''), notice_period),
                ral_requested = COALESCE(?, ral_requested),
                gdpr_consent  = 1, gdpr_date = CURDATE(),
                consent_marketing = ?,
                submitted_ip  = ?, submitted_ua = ?, submitted_ref = ?
             WHERE id = ?"
        );
        $u->execute([$first, $last, $phone, $linkedin, $notice, $ralNum, (int)$consM,
                     PublicApiAuth::packIp(PublicApiAuth::clientIp()),
                     substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $reqId, $candidateId]);
    } else {
        $i = $pdo->prepare(
            "INSERT INTO candidates
                (first_name,last_name,email,phone,linkedin_url,notice_period,ral_requested,
                 source,gdpr_consent,gdpr_date,consent_marketing,status,
                 submitted_ip,submitted_ua,submitted_ref,created_at)
             VALUES (?,?,?,?,?,?,?,?,1,CURDATE(),?, 'new', ?,?,?, NOW())"
        );
        $i->execute([$first, $last, $email, $phone, $linkedin ?: null, $notice ?: null, $ralNum,
                     $sourceTag, (int)$consM,
                     PublicApiAuth::packIp(PublicApiAuth::clientIp()),
                     substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $reqId]);
        $candidateId = (int)$pdo->lastInsertId();
    }

    // 2) Dedup application (candidate_id, position_id) — stage attivo
    $stmt = $pdo->prepare("SELECT id, stage FROM candidate_applications WHERE candidate_id=? AND position_id=? LIMIT 1");
    $stmt->execute([$candidateId, $positionId]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($prev && in_array($prev['stage'], ['cv_received','screening','tech_test','hr_interview','tech_interview','offer_sent'], true)) {
        $pdo->rollBack();
        throw new RuntimeException('application_already_active', 409);
    }

    // 3) Salva CV in candidate_documents (doc_type='cv')
    if (!is_dir($storeAbs) && !mkdir($storeAbs, 0750, true) && !is_dir($storeAbs)) {
        throw new RuntimeException('storage_unwritable', 500);
    }
    // Validazione via CvUploadValidator (nome file coerente con pattern esistente)
    $file = $_FILES['cv'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('upload_error_' . (int)$file['error'], 400);
    if (!is_uploaded_file($file['tmp_name']))                     throw new RuntimeException('not_uploaded_file', 400);
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes)                          throw new RuntimeException('cv_too_large', 413);
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $mimeList, true))                        throw new RuntimeException('cv_bad_mime:' . $mime, 415);
    $extMap = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $ext = $extMap[$mime] ?? null;
    if ($ext === null) throw new RuntimeException('cv_bad_ext', 415);

    $stored = sprintf('cand_%d_cv_%d.%s', $candidateId, time(), $ext);
    $dest = $storeAbs . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('move_failed', 500);
    @chmod($dest, 0640);

    $origName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string)$file['name'])) ?: 'cv';

    $ins = $pdo->prepare(
        "INSERT INTO candidate_documents
            (candidate_id, doc_type, file_path, original_filename, file_size, mime_type, description, uploaded_by, uploaded_at)
         VALUES (?, 'cv', ?, ?, ?, ?, ?, NULL, NOW())"
    );
    // file_path relativo a uploads/, come da pattern esistente
    $rel = ltrim(str_replace(realpath(__DIR__) . '/uploads/', '', $dest), '/');
    if (str_starts_with($rel, '/') || $rel === '') $rel = 'cv_imports/' . $stored;
    $ins->execute([$candidateId, $rel, $origName, $size, $mime, 'Ricevuto da portale esterno — ref ' . $reqId]);

    // 4) Application
    $ins = $pdo->prepare(
        "INSERT INTO candidate_applications
            (candidate_id, position_id, stage, source_channel, submitted_ip, submitted_ua, api_request_id, created_at)
         VALUES (?, ?, 'cv_received', 'careers_portal', ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE stage = 'cv_received', source_channel = 'careers_portal',
              submitted_ip = VALUES(submitted_ip), submitted_ua = VALUES(submitted_ua), api_request_id = VALUES(api_request_id)"
    );
    $ins->execute([$candidateId, $positionId,
                   PublicApiAuth::packIp(PublicApiAuth::clientIp()),
                   substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $reqId]);
    $appId = (int)$pdo->lastInsertId();

    // Cover letter opzionale come documento tipo 'lettera'
    if ($cover !== '') {
        $letterName = sprintf('cand_%d_lettera_%d.txt', $candidateId, time());
        $letterAbs = $storeAbs . '/' . $letterName;
        if (@file_put_contents($letterAbs, $cover) !== false) {
            @chmod($letterAbs, 0640);
            $lRel = ltrim(str_replace(realpath(__DIR__) . '/uploads/', '', $letterAbs), '/') ?: 'cv_imports/' . $letterName;
            $pdo->prepare(
                "INSERT INTO candidate_documents
                    (candidate_id, doc_type, file_path, original_filename, file_size, mime_type, description, uploaded_at)
                 VALUES (?, 'lettera', ?, 'lettera_di_presentazione.txt', ?, 'text/plain', ?, NOW())"
            )->execute([$candidateId, $lRel, strlen($cover), 'Cover letter da portale — ref ' . $reqId]);
        }
    }

    $pdo->commit();

    // Notifica HR best-effort
    $notify = (string)$settings->get('careers.notify_email', '');
    if ($notify !== '') {
        @mail($notify, '[PortalManager] Nuova candidatura #' . ($appId ?: '?'),
              "Candidatura ricevuta.\nPosizione: {$pos['title']} (#{$positionId})\nCandidato: $first $last <$email>\nRef: $reqId\n");
    }

    $auth->audit($clientId, 'apply', 'POST', 201, $reqId);
    ApiResponse::json(201, [
        'ok' => true,
        'application_id' => $appId,
        'candidate_id'   => $candidateId,
        'message'        => 'Candidatura ricevuta. Sarai contattato dal team HR.',
        'reference'      => $reqId,
    ], $reqId);
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $code = (int)$e->getCode(); if ($code < 400 || $code > 599) $code = 500;
    $auth->audit($clientId, 'apply', 'POST', $code, $reqId, $e->getMessage());
    ApiResponse::json($code, ['ok' => false, 'error' => $e->getMessage()], $reqId);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $auth->audit($clientId, 'apply', 'POST', 500, $reqId, 'server_error');
    error_log('[api_public_apply] ' . $e->getMessage());
    ApiResponse::json(500, ['ok' => false, 'error' => 'server_error'], $reqId);
}
