<?php
/**
 * certV 4.0 — access_control.php (hardened)
 * - Carica il bootstrap di sicurezza (session, CSRF, headers)
 * - Controllo accesso granulare: ruolo (base) + utente (override)
 * - Azioni: view, create, edit, delete, export
 *
 * Per retrocompatibilità, questo file può ancora essere incluso direttamente
 * dai file esistenti. Internamente delega al bootstrap.
 */

if (!defined('APP_BASE')) define('APP_BASE', __DIR__);

// Bootstrap: se non è già caricato, lo carica (ordina session/headers/csrf)
if (!class_exists('Session')) {
    require_once __DIR__ . '/app/bootstrap.php';
}

$current_page   = basename($_SERVER['PHP_SELF']);
// Rimuovi l'estensione perché Router usa 'brand' non 'brand.php'
$current_key    = str_ends_with($current_page, '.php')
                ? substr($current_page, 0, -4)
                : $current_page;

$public_pages   = ['login.php', 'unauthorized.php', 'install.php', 'r.php'];
$always_allowed = [
    'index.php', 'user_profile.php', 'notifications.php', 'logout.php',
    'db_upgrade.php', 'schema_check_upgrade.php', 'health_check.php', 'system_update.php',
    'api_filters.php', 'api_cert_search.php', 'api_cert_history.php', 'api_contract_docs.php', 'api_cert_codes.php',
    'doc_download.php', 'download.php',
    // NOTA v4.0: reset_admin.php e fix_password.php RIMOSSI da always_allowed.
    // In produzione devono essere bloccati via installer_disabled.flag.
];

if (!in_array($current_page, $public_pages)) {
    if (!isset($_SESSION['user_id'])) {
        // Usa URL opaco se il router è disponibile
        if (class_exists('Router')) {
            header('Location: ' . Router::url('login'));
        } else {
            header('Location: login.php');
        }
        exit();
    }
    $u_id   = (int)$_SESSION['user_id'];
    $u_role = (int)($_SESSION['role_id'] ?? 99);
    if ($u_role !== 1 && !in_array($current_page, $always_allowed)) {
        if (!can('view', $current_page)) {
            if (class_exists('Router')) {
                header('Location: ' . Router::url('unauthorized'));
            } else {
                header('Location: unauthorized.php');
            }
            exit();
        }
    }
}

/**
 * Verifica permessi per pagina+azione.
 *
 * v1.7.30 — GERARCHIA PERMESSI (priorità decrescente):
 *   1. Super Admin (role_id=1)              → sempre TRUE
 *   2. user_permissions (override utente)    → se valore NOT NULL, PREVALE
 *   3. role_permissions (fallback ruolo)     → usato se override = NULL
 *   4. Default                                → FALSE (deny by default)
 *
 * L'override del singolo utente PREVALE sempre sul ruolo, sia in allow (1)
 * che in deny (0). Solo se il valore utente è NULL (non specificato), si
 * applica il valore del ruolo.
 */
function can(string $action = 'view', string $page = ''): bool
{
    global $pdo;
    $uid  = (int)($_SESSION['user_id'] ?? 0);
    $role = (int)($_SESSION['role_id'] ?? 99);
    if (!$page) $page = basename($_SERVER['PHP_SELF']);
    if ($role === 1) return true;

    $col = "can_$action";
    $ok  = ['can_view', 'can_create', 'can_edit', 'can_delete', 'can_export'];
    if (!in_array($col, $ok)) $col = 'can_view';

    try {
        $s = $pdo->prepare("SELECT `$col` FROM user_permissions WHERE user_id=? AND page_name=?");
        $s->execute([$uid, $page]);
        $v = $s->fetchColumn();
        $s->closeCursor();
        if ($v !== false && $v !== null) return (bool)(int)$v;
    } catch (\PDOException $e) {}

    try {
        $s = $pdo->prepare("SELECT `$col` FROM role_permissions WHERE role_id=? AND page_name=?");
        $s->execute([$role, $page]);
        $v = $s->fetchColumn();
        $s->closeCursor();
        if ($v !== false) return (bool)(int)$v;
    } catch (\PDOException $e) {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id=? AND page_name=?");
            $s->execute([$role, $page]);
            return $action === 'view' ? (bool)$s->fetchColumn() : false;
        } catch (\PDOException $e2) { return false; }
    }
    return false;
}

function check_ui_permission(string $page): bool { return can('view', $page); }

function perms(string $page = ''): array
{
    $r = [];
    foreach (['view', 'create', 'edit', 'delete', 'export'] as $a) {
        $r[$a] = can($a, $page);
    }
    return $r;
}

/**
 * v1.7.30 — Helper diagnostico: restituisce per ogni azione il valore
 * effettivo + la sorgente (user|role|default) — utile per debug UI permessi.
 */
function effective_perms(string $page = '', ?int $uid = null, ?int $role = null): array
{
    global $pdo;
    if ($uid === null)  $uid  = (int)($_SESSION['user_id'] ?? 0);
    if ($role === null) $role = (int)($_SESSION['role_id'] ?? 99);
    if (!$page) $page = basename($_SERVER['PHP_SELF']);

    $r = [];
    foreach (['view', 'create', 'edit', 'delete', 'export'] as $a) {
        $col = "can_$a";
        $value = false; $source = 'default';

        if ($role === 1) { $r[$a] = ['value' => true, 'source' => 'superadmin']; continue; }

        // Override utente
        try {
            $s = $pdo->prepare("SELECT `$col` FROM user_permissions WHERE user_id=? AND page_name=?");
            $s->execute([$uid, $page]);
            $v = $s->fetchColumn();
            $s->closeCursor();
            if ($v !== false && $v !== null) {
                $value = (bool)(int)$v;
                $source = 'user';
                $r[$a] = ['value' => $value, 'source' => $source];
                continue;
            }
        } catch (\PDOException $e) {}

        // Fallback ruolo
        try {
            $s = $pdo->prepare("SELECT `$col` FROM role_permissions WHERE role_id=? AND page_name=?");
            $s->execute([$role, $page]);
            $v = $s->fetchColumn();
            $s->closeCursor();
            if ($v !== false) {
                $value = (bool)(int)$v;
                $source = 'role';
            }
        } catch (\PDOException $e) {}

        $r[$a] = ['value' => $value, 'source' => $source];
    }
    return $r;
}

function load_effective_permissions(int $userId): array
{
    global $pdo;
    $result = [];
    try {
        $s = $pdo->prepare("SELECT role_id FROM users WHERE id=?");
        $s->execute([$userId]);
        $roleId = (int)$s->fetchColumn();
        $s->closeCursor();
    } catch (\Exception $e) { return $result; }

    try {
        $rp = $pdo->prepare("SELECT page_name,can_view,can_create,can_edit,can_delete,can_export FROM role_permissions WHERE role_id=?");
        $rp->execute([$roleId]);
        foreach ($rp->fetchAll() as $r) {
            $result[$r['page_name']] = [
                'view'   => (int)($r['can_view']   ?? 1),
                'create' => (int)($r['can_create'] ?? 1),
                'edit'   => (int)($r['can_edit']   ?? 1),
                'delete' => (int)($r['can_delete'] ?? 0),
                'export' => (int)($r['can_export'] ?? 1),
                'source' => 'role'
            ];
        }
    } catch (\Exception $e) {
        try {
            $rp = $pdo->prepare("SELECT page_name FROM role_permissions WHERE role_id=?");
            $rp->execute([$roleId]);
            foreach ($rp->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $result[$p] = ['view'=>1,'create'=>1,'edit'=>1,'delete'=>0,'export'=>1,'source'=>'role'];
            }
        } catch (\Exception $e2) {}
    }

    try {
        $up = $pdo->prepare("SELECT page_name,can_view,can_create,can_edit,can_delete,can_export FROM user_permissions WHERE user_id=?");
        $up->execute([$userId]);
        foreach ($up->fetchAll() as $u) {
            $p = $u['page_name'];
            if (!isset($result[$p])) {
                $result[$p] = ['view'=>0,'create'=>0,'edit'=>0,'delete'=>0,'export'=>0,'source'=>'user'];
            }
            foreach (['view','create','edit','delete','export'] as $a) {
                if ($u["can_$a"] !== null) {
                    $result[$p][$a] = (int)$u["can_$a"];
                    $result[$p]['source'] = 'user';
                }
            }
        }
    } catch (\Exception $e) {}
    return $result;
}
