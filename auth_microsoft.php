<?php
/**
 * PortalManager — auth_microsoft.php
 * Endpoint SSO Microsoft 365 (Entra ID) — OIDC Authorization Code + PKCE.
 *
 *   ?action=start  -> redirect a Microsoft (authorize)
 *   callback (?code&state) -> verifica id_token, match utente per email, login.
 *
 * Pagina PUBBLICA (nessuna sessione richiesta). Il redirect_uri registrato in
 * Azure deve puntare esattamente a questo file (MS_REDIRECT_URI in .env.php).
 */
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Microsoft365Sso.php';

// ── TEST CONFIGURAZIONE (solo Super Admin già autenticato) ──────────
// Esegue il flusso reale con Microsoft ma NON effettua il login: verifica
// token, firma, MFA (amr) e match utente, poi torna a sso_settings con l'esito.
$isAdmin = !empty($_SESSION['user_id']) && (int)($_SESSION['role_id'] ?? 0) === 1;
if ($isAdmin && ($_GET['action'] ?? '') === 'test' && !isset($_GET['code'])) {
    if (!Microsoft365Sso::isConfigured()) redirect('sso_settings', ['t' => 'noconf']);
    $_SESSION['ms_sso_test'] = time();
    header('Location: ' . Microsoft365Sso::authorizeUrl());
    exit;
}
if ($isAdmin && !empty($_SESSION['ms_sso_test']) && (isset($_GET['code']) || isset($_GET['error']))) {
    unset($_SESSION['ms_sso_test']);
    $res = Microsoft365Sso::handleCallback($_GET, true);
    $out = ['ts' => time(), 'ok' => $res['ok'], 'error' => $res['error'] ?? null];
    if ($res['ok']) {
        $c   = $res['claims'];
        $amr = is_array($c['amr'] ?? null) ? $c['amr'] : [];
        $out += [
            'email'   => $c['email'], 'name' => $c['name'], 'tid' => $c['tid'],
            'amr'     => $amr, 'acrs' => $c['acrs'] ?? null,
            'mfa_ok'  => in_array('mfa', $amr, true),
        ];
        // match utente (stessa logica del login)
        $q = $pdo->prepare("SELECT id, status FROM users WHERE LOWER(email)=? LIMIT 1");
        $q->execute([$c['email']]); $u = $q->fetch(); $q->closeCursor();
        $via = 'users.email';
        if (!$u) {
            try {
                $q = $pdo->prepare("SELECT u.id, u.status FROM users u JOIN employees e ON e.id=u.employee_id
                                    WHERE LOWER(e.business_email)=? LIMIT 1");
                $q->execute([$c['email']]); $u = $q->fetch(); $q->closeCursor();
                $via = 'employees.business_email';
            } catch (\Throwable $e) {}
        }
        $out['user'] = $u ? ['id' => (int)$u['id'], 'status' => $u['status'], 'via' => $via] : null;
    }
    $_SESSION['ms_sso_test_result'] = $out;
    if (function_exists('write_log')) write_log('Security', $res['ok'] ? 'info' : 'warning',
        'Test SSO/MFA Microsoft: ' . ($res['ok'] ? ('esito OK, mfa=' . (!empty($out['mfa_ok']) ? 'si' : 'no')) : $res['error']),
        (int)$_SESSION['user_id']);
    redirect('sso_settings', ['t' => 'done']);
}

// già autenticato
if (!empty($_SESSION['user_id'])) { redirect('index'); }

// SSO non configurato/abilitato
if (!Microsoft365Sso::enabled()) { redirect('login', ['sso' => 'disabled']); }

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// ── STEP 1: avvio ──────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'start' && !isset($_GET['code'])) {
    if (function_exists('write_log')) write_log('Auth', 'info', 'SSO Microsoft: avvio', null, ['ip' => $ip]);
    header('Location: ' . Microsoft365Sso::authorizeUrl());
    exit;
}

// ── STEP 2: callback ───────────────────────────────────────────────
if (isset($_GET['code']) || isset($_GET['error'])) {
    $res = Microsoft365Sso::handleCallback($_GET);
    if (!$res['ok']) {
        if (function_exists('write_log')) write_log('Auth', 'warning', 'SSO Microsoft fallito: ' . $res['error'], null, ['ip' => $ip]);
        redirect('login', ['sso' => 'error']);
    }

    $email = $res['claims']['email'];

    // rilevamento schema v2.2+ (employee_id) come in login.php
    $schema_v22 = false;
    try { $pdo->query("SELECT `employee_id` FROM `users` LIMIT 0")->closeCursor(); $schema_v22 = true; } catch (\Throwable $e) {}

    if ($schema_v22) {
        $s = $pdo->prepare("SELECT u.id, u.employee_id, u.email, u.display_name, u.role_id, u.status
                            FROM users u WHERE LOWER(u.email) = ? LIMIT 1");
    } else {
        $s = $pdo->prepare("SELECT id, NULL AS employee_id, email,
                                   CONCAT(last_name,' ',first_name) AS display_name, role_id, status
                            FROM users WHERE LOWER(email) = ? LIMIT 1");
    }
    $s->execute([$email]);
    $user = $s->fetch();
    $s->closeCursor();

    // Fallback: match sull'email aziendale del dipendente collegato (users.employee_id -> employees.business_email)
    if (!$user && $schema_v22) {
        try {
            $s2 = $pdo->prepare(
                "SELECT u.id, u.employee_id, u.email, u.display_name, u.role_id, u.status
                 FROM users u
                 JOIN employees e ON e.id = u.employee_id
                 WHERE LOWER(e.business_email) = ? LIMIT 1"
            );
            $s2->execute([$email]);
            $user = $s2->fetch();
            $s2->closeCursor();
        } catch (\Throwable $e) { /* schema senza business_email: ignora */ }
    }

    // utente non presente: nessun auto-provisioning di default (sicurezza)
    if (!$user) {
        if (Microsoft365Sso::isConfigured() && Env::get('MS_AUTO_PROVISION', '0') === '1') {
            // provisioning minimale disabilitato per default: qui si potrebbe creare
            // l'utente con ruolo base. Lasciato come estensione controllata.
        }
        if (function_exists('write_log')) write_log('Auth', 'warning', "SSO Microsoft: nessun utente per $email", null, ['ip' => $ip]);
        redirect('login', ['sso' => 'nouser']);
    }
    if (($user['status'] ?? '') !== 'active') {
        if (function_exists('write_log')) write_log('Auth', 'warning', "SSO Microsoft: utente non attivo $email", (int)$user['id'], ['ip' => $ip]);
        redirect('login', ['sso' => 'inactive']);
    }

    // nome (da employees se collegato)
    $emp = null;
    if ($schema_v22 && !empty($user['employee_id'])) {
        $es = $pdo->prepare("SELECT id, first_name, last_name FROM employees WHERE id=?");
        $es->execute([(int)$user['employee_id']]);
        $emp = $es->fetch();
        $es->closeCursor();
    }
    $userName = $emp
        ? trim($emp['last_name'] . ' ' . $emp['first_name'])
        : ($user['display_name'] ?: ($res['claims']['name'] ?: 'Utente'));

    // ── Login completo. La MFA è già stata assolta da Microsoft
    //    (Conditional Access + verifica claim amr): nessuna 2FA applicativa.
    Session::onLogin((int)$user['id'], (int)$user['role_id'], [
        'employee_id' => $emp ? (int)$emp['id'] : null,
        'user_name'   => $userName,
    ]);
    if (class_exists('Csrf')) Csrf::rotate();

    if (function_exists('write_log')) {
        write_log('Auth', 'success', 'Login SSO Microsoft 365', (int)$user['id'],
            ['ip' => $ip, 'amr' => $res['claims']['amr'] ?? []]);
    }
    redirect('index');
}

// nessun parametro noto -> avvia il flusso
header('Location: ' . Microsoft365Sso::authorizeUrl());
exit;
