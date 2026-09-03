<?php
/**
 * PortalManager 1.0.2 — api_cert_codes.php
 *
 * Endpoint AJAX di supporto per upload_certificato.php.
 *
 * v1.0.2 FIX:
 *   - Usa app/bootstrap.php invece di session_start() puro + Config.php
 *     perché Session::start() (in app/Session.php) configura il cookie con
 *     nome custom 'certV_sid'. Senza bootstrap la sessione veniva avviata
 *     vuota (PHPSESSID inesistente) → 403 → JS mostrava "errore caricamento".
 *
 * Risposta JSON:
 * {
 *   "ok": true,
 *   "cert_id": 42,
 *   "catalog_code": "CCNA-200-301",
 *   "placeholder": "Es: CCNA-200-301-XXXXXX",
 *   "validity_months": 36,
 *   "existing_codes": [
 *     {"code":"CSCO13234567","count":1},
 *     {"code":"CSCO13234890","count":1}
 *   ],
 *   "total_issued": 12
 * }
 */
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

// Logging diagnostico in caso di fallimento
$debug = !empty($_GET['debug']) && (int)($_SESSION['role_id'] ?? 0) === 1;

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([
        'ok'    => false,
        'error' => 'not_authenticated',
        'hint'  => 'Effettuare nuovamente il login.',
    ]);
    exit;
}

$cert_id = (int)($_GET['cert_id'] ?? 0);
if ($cert_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_or_invalid_cert_id']);
    exit;
}

try {
    // 1. Codice catalogo della certificazione
    $s = $pdo->prepare(
        "SELECT id, name, code, validity_months
           FROM certifications
          WHERE id = ? AND is_active = 1
          LIMIT 1"
    );
    $s->execute([$cert_id]);
    $cert = $s->fetch(PDO::FETCH_ASSOC);

    if (!$cert) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'certification_not_found_or_inactive']);
        exit;
    }

    // 2. Codici già emessi per questa cert (con conteggio occorrenze, max 50)
    $s = $pdo->prepare(
        "SELECT certificate_code AS code, COUNT(*) AS count
           FROM user_certifications
          WHERE certification_id = ?
            AND certificate_code IS NOT NULL
            AND certificate_code <> ''
          GROUP BY certificate_code
          ORDER BY MAX(created_at) DESC
          LIMIT 50"
    );
    $s->execute([$cert_id]);
    $existing = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($existing as &$row) {
        $row['count'] = (int)$row['count'];
    }
    unset($row);

    // 3. Conteggio totale emissioni (anche senza codice)
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM user_certifications WHERE certification_id = ?"
    );
    $s->execute([$cert_id]);
    $totalIssued = (int)$s->fetchColumn();

    // 4. Placeholder suggerito
    $placeholder = $cert['code']
        ? 'Es: ' . $cert['code'] . '-XXXXXX'
        : 'Es: MC-12345-A';

    $response = [
        'ok'              => true,
        'cert_id'         => (int)$cert['id'],
        'cert_name'       => $cert['name'],
        'catalog_code'    => $cert['code'],
        'validity_months' => $cert['validity_months'] !== null ? (int)$cert['validity_months'] : null,
        'placeholder'     => $placeholder,
        'existing_codes'  => $existing,
        'total_issued'    => $totalIssued,
    ];

    if ($debug) {
        $response['_debug'] = [
            'user_id'      => $_SESSION['user_id'],
            'role_id'      => $_SESSION['role_id'] ?? null,
            'session_name' => session_name(),
            'session_id'   => substr(session_id(), 0, 8) . '...',
            'php_version'  => PHP_VERSION,
        ];
    }

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'db_error',
        'hint'  => $debug ? $e->getMessage() : 'Errore database. Contattare amministratore.',
    ]);
    if (function_exists('write_log')) {
        write_log('API', 'error', 'api_cert_codes: ' . $e->getMessage(), $_SESSION['user_id'] ?? 0);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'internal_error',
        'hint'  => $debug ? $e->getMessage() : 'Errore interno.',
    ]);
    if (function_exists('write_log')) {
        write_log('API', 'error', 'api_cert_codes (throwable): ' . $e->getMessage(), $_SESSION['user_id'] ?? 0);
    }
}
