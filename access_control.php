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

// v1.9.51 — RBAC fix: riallinea il ruolo di sessione all'assegnazione corrente a DB.
// Senza questo, un cambio ruolo lato admin non ha effetto finché l'utente non rifà
// login e la validazione runtime usa il ruolo (stale) salvato al login.
if (isset($pdo) && $pdo instanceof PDO && class_exists('Session')) {
    Session::syncRole($pdo);
}

// v1.9.83 — auto-sync RBAC: se il codice (menu, router, catalogo, versione) e' cambiato, allinea a fine
// richiesta catalogo pagine e matrice permessi. Nel caso normale costa la lettura di un file.
if (isset($pdo) && $pdo instanceof PDO && is_file(__DIR__ . '/app/RbacSync.php')) {
    require_once __DIR__ . '/app/RbacSync.php';
    RbacSync::autoSync($pdo);
}

// v1.9.56 — Auto-migrazione idempotente tabelle menu_preferences e permissions
if (isset($pdo) && $pdo instanceof PDO) {
    static $menuMigrated = false;
    if (!$menuMigrated) {
        $menuMigrated = true;
        try {
            $pdo->query("SELECT id FROM menu_preferences LIMIT 0")->closeCursor();
            $pdo->query("SELECT id FROM permissions LIMIT 0")->closeCursor();
        } catch (\Throwable $e) {
            $candidates = [
                __DIR__ . '/20260918_113000_fix_menu_permissions.sql',
                __DIR__ . '/sql/20260918_113000_fix_menu_permissions.sql',
                __DIR__ . '/migration_menu_permissions.sql',
            ];
            $mf = null;
            foreach ($candidates as $c) {
                if (file_exists($c)) { $mf = $c; break; }
            }
            if ($mf) {
                $sqlContent = file_get_contents($mf);
                $lines = array_map(function($l) {
                    $t = trim($l);
                    return str_starts_with($t, '--') ? '' : $l;
                }, explode("\n", $sqlContent));
                $cleanSql = implode("\n", $lines);
                foreach (explode(";", $cleanSql) as $s) {
                    $s = trim($s);
                    if (!$s) continue;
                    try { $pdo->exec($s); } catch (\Throwable $x) {}
                }
            }
        }
    }
}

$current_page   = basename($_SERVER['PHP_SELF']);
// Rimuovi l'estensione perché Router usa 'brand' non 'brand.php'
$current_key    = str_ends_with($current_page, '.php')
                ? substr($current_page, 0, -4)
                : $current_page;

$public_pages   = ['login.php', 'unauthorized.php', 'install.php', 'r.php', 'auth_microsoft.php', 'password_reset.php'];   // v1.9.81
$always_allowed = [
    'index.php', 'user_profile.php', 'notifications.php', 'logout.php',
    'api_filters.php', 'api_cert_search.php', 'api_cert_history.php', 'api_contract_docs.php', 'api_cert_codes.php', 'api_prj.php',   // v1.10.01 — verifica can() per azione
    'doc_download.php', 'download.php',
    // NOTA v1.9.54: db_upgrade.php, schema_check_upgrade.php, health_check.php e system_update.php
    // rimossi da always_allowed per riservarli esclusivamente al Super Admin (role_id = 1).
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
        // v1.10.01 — la scheda «Progetti PRJ» di Commesse / Progetti ha un permesso proprio (manage_projects_prj.php)
        $prjView = $current_page === 'manage_projects.php' && ($_GET['view'] ?? '') === 'prj' && can('view', 'manage_projects_prj.php');
        if (!$prjView && !can('view', $current_page)) {
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

/**
 * v1.9.56 — RBAC Policy Check standard.
 * Supporta sia nomi file fisici (es. 'menu_customizer.php') sia alias/slug
 * di capability (es. 'menu.customize', 'can_customize_menu', 'menu_customizer').
 */
function hasPermissionTo(string $permission, string $action = 'view'): bool
{
    static $aliasMap = [
        'menu.customize'      => 'menu_customizer.php',
        'can_customize_menu'  => 'menu_customizer.php',
        'menu_customizer'     => 'menu_customizer.php',
        'user.profile'        => 'user_profile.php',
        'roles.manage'        => 'manage_roles.php',
        'permissions.manage'  => 'manage_permissions.php',
    ];

    $targetPage = $aliasMap[$permission] ?? $permission;
    if (!str_ends_with($targetPage, '.php')) {
        $targetPage .= '.php';
    }

    return can($action, $targetPage);
}

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
