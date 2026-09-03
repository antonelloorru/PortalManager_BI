<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Careers BFF (Backend-for-Frontend)
 * Vive sull'host del portale esterno (DMZ), NON dentro il gestionale HR.
 * Firma le richieste con HMAC e le inoltra all'API interna via HTTPS/mTLS.
 * Il browser non vede mai il client_secret.
 *
 * Config: definire in un file config protetto (fuori webroot) le seguenti costanti:
 *   PM_API_BASE, PM_CLIENT_ID, PM_CLIENT_SECRET, PM_CA_BUNDLE (opzionale)
 */

require __DIR__ . '/../../config/careers_bff_config.php';  // fuori webroot

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$op = (string)($_GET['op'] ?? '');
try {
    switch ($op) {
        case 'positions':   handlePositions();   break;
        case 'check_email': handleCheckEmail();  break;
        case 'apply':       handleApply();       break;
        default:            http_response_code(404); echo json_encode(['ok'=>false,'error'=>'unknown_op']);
    }
} catch (\Throwable $e) {
    http_response_code(502);
    error_log('[careers-bff] ' . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'bff_error']);
}

function handlePositions(): void {
    $qs = http_build_query([
        'q'          => trim((string)($_GET['q'] ?? '')),
        'department' => trim((string)($_GET['department'] ?? '')),
        'location'   => trim((string)($_GET['location'] ?? '')),
        'limit'      => (int)($_GET['limit']  ?? 50),
        'offset'     => (int)($_GET['offset'] ?? 0),
    ]);
    $path = '/api_public_positions.php';
    [$status, $body] = pm_signed_request('GET', $path . '?' . $qs, '', []);
    passthrough($status, $body);
}

function handleCheckEmail(): void {
    $raw = file_get_contents('php://input') ?: '';
    $path = '/api_public_check_email.php';
    [$status, $body] = pm_signed_request('POST', $path, $raw, ['Content-Type: application/json']);
    passthrough($status, $body);
}

function handleApply(): void {
    // Ricostruisce multipart per l'API. cURL fa il lavoro pesante.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); return;
    }
    $path = '/api_public_apply.php';

    // Costruisci CURLFile per il CV
    $post = [];
    foreach ($_POST as $k => $v) $post[$k] = is_scalar($v) ? (string)$v : json_encode($v);
    if (!empty($_FILES['cv']['tmp_name']) && is_uploaded_file($_FILES['cv']['tmp_name'])) {
        $post['cv'] = new CURLFile(
            $_FILES['cv']['tmp_name'],
            (string)$_FILES['cv']['type'],
            (string)$_FILES['cv']['name']
        );
    }
    // Ottieni raw body per firma HMAC — usiamo il payload prodotto da cURL
    // Per multipart la firma deve corrispondere al body effettivamente inviato:
    // cURL calcola il body internamente. Usiamo trick: chiamiamo un handler intermedio.
    // Semplificazione robusta: firma sul solo sha256 del multipart che ricostruiamo.
    $boundary = '----PMBFF' . bin2hex(random_bytes(12));
    $raw = build_multipart($post, $boundary);
    [$status, $body] = pm_signed_request_raw('POST', $path, $raw, [
        'Content-Type: multipart/form-data; boundary=' . $boundary,
    ]);
    passthrough($status, $body);
}

/** Firma HMAC + esegue chiamata. Ritorna [status, body]. */
function pm_signed_request(string $method, string $pathWithQs, string $rawBody, array $extraHeaders): array {
    return pm_signed_request_raw($method, $pathWithQs, $rawBody, $extraHeaders);
}

function pm_signed_request_raw(string $method, string $pathWithQs, string $rawBody, array $extraHeaders): array {
    $ts    = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $path  = strtok($pathWithQs, '?'); // path senza query per firma
    $hashBody = hash('sha256', $rawBody);
    $canonical = strtoupper($method) . "\n" . $path . "\n" . $ts . "\n" . $nonce . "\n" . $hashBody;
    $sig = hash_hmac('sha256', $canonical, PM_CLIENT_SECRET);

    $headers = array_merge($extraHeaders, [
        'X-PM-Client: '    . PM_CLIENT_ID,
        'X-PM-Timestamp: ' . $ts,
        'X-PM-Nonce: '     . $nonce,
        'X-PM-Signature: ' . $sig,
        'Accept: application/json',
    ]);

    $ch = curl_init(PM_API_BASE . $pathWithQs);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_POSTFIELDS     => $rawBody,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO         => defined('PM_CA_BUNDLE') ? PM_CA_BUNDLE : null,
    ]);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('curl: ' . curl_error($ch));
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, (string)$body];
}

function build_multipart(array $fields, string $boundary): string {
    $eol = "\r\n"; $out = '';
    foreach ($fields as $name => $val) {
        if ($val instanceof CURLFile) {
            $file = $val->getFilename(); $mime = $val->getMimeType() ?: 'application/octet-stream';
            $fname = $val->getPostFilename() ?: basename($file);
            $data = file_get_contents($file) ?: '';
            $out .= "--$boundary$eol";
            $out .= "Content-Disposition: form-data; name=\"$name\"; filename=\"$fname\"$eol";
            $out .= "Content-Type: $mime$eol$eol";
            $out .= $data . $eol;
        } else {
            $out .= "--$boundary$eol";
            $out .= "Content-Disposition: form-data; name=\"$name\"$eol$eol";
            $out .= (string)$val . $eol;
        }
    }
    $out .= "--$boundary--$eol";
    return $out;
}

function passthrough(int $status, string $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $body;
}
