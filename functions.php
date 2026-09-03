<?php
/**
 * certV 2.0 — functions.php  (struttura piatta)
 * FIX: __DIR__ ora punta alla root del progetto
 * FIX: cert_status_from_date usa soglia configurabile
 * FIX: brand_compliance_pct usa query singola efficiente
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// FIX v2.4.1: guard against missing Config.php
if (!file_exists(__DIR__ . "/Config.php")) {
    if (file_exists(__DIR__ . "/install.php")) { header("Location: install.php"); exit(); }
    die("Config.php mancante.");
}
require_once __DIR__ . "/Config.php";

// ─── SICUREZZA ────────────────────────────────────────────────────────────────

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function check_access(): bool {
    return isset($_SESSION['user_id']);
}

function check_role(array $allowed_roles): void {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php"); exit();
    }
    if (!in_array((int)($_SESSION['role_id'] ?? 99), $allowed_roles, true)) {
        header("Location: unauthorized.php"); exit();
    }
}

function current_role(): int {
    return (int)($_SESSION['role_id'] ?? 99);
}

/**
 * v2.2: restituisce l'employee_id dell'utente loggato (NULL se account di servizio)
 */
function current_employee_id(): ?int {
    return isset($_SESSION['employee_id']) && $_SESSION['employee_id']
        ? (int)$_SESSION['employee_id']
        : null;
}

/**
 * v2.2: nome visualizzato dell'utente loggato
 * Usa il nome dell'employee collegato, oppure display_name per account di servizio
 */
function current_user_name(): string {
    return $_SESSION['user_name'] ?? 'Utente';
}

// ─── LOG ──────────────────────────────────────────────────────────────────────

function write_log(string $category, string $level, string $message, ?int $user_id = null, array $context = []): void {
    global $pdo;
    try {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $ctx = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;
        $pdo->prepare(
            "INSERT INTO app_logs (category, level, message, user_id, context, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$category, $level, $message, $user_id, $ctx, $ip]);
    } catch (Exception $e) {
        error_log("write_log error: " . $e->getMessage());
    }
}

// ─── NOTIFICHE ────────────────────────────────────────────────────────────────

function push_notification(
    string  $title,
    string  $message,
    string  $module,
    string  $type     = 'info',
    ?int    $user_id  = null,
    ?int    $role_id  = null,
    ?string $link     = null,
    int     $days_exp = 30
): void {
    global $pdo;
    try {
        $exp = date('Y-m-d', strtotime("+$days_exp days"));
        $pdo->prepare(
            "INSERT INTO notifications (user_id, role_id, type, module, title, message, link_url, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$user_id, $role_id, $type, $module, $title, $message, $link, $exp]);
    } catch (Exception $e) {
        error_log("push_notification error: " . $e->getMessage());
    }
}

function unread_notifications(): int {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return 0;
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE is_read = 0
               AND (user_id = ? OR role_id = ?)
               AND (expires_at IS NULL OR expires_at >= CURDATE())"
        );
        $s->execute([$_SESSION['user_id'], current_role()]);
        return (int)$s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

// ─── COMPLIANCE (FIX: query singola efficiente) ───────────────────────────────

/**
 * FIX BUG #11: invece di N query in loop, calcola compliance con una singola query
 * Ritorna array [brand_id => ['pct'=>float,'active'=>int,'req'=>int,'gap'=>int]]
 */
function brand_compliance_all(): array {
    global $pdo;
    try {
        $brands = $pdo->query(
            "SELECT id, req_technical FROM brands WHERE req_technical > 0"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        if (empty($brands)) return [];

        $ids       = implode(',', array_keys($brands));
        $active_rows = $pdo->query(
            "SELECT c.brand_id, COUNT(DISTINCT uc.employee_id) cnt
             FROM user_certifications uc
             JOIN certifications c ON uc.certification_id = c.id
             WHERE c.brand_id IN ($ids) AND uc.status = 'active'
             GROUP BY c.brand_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = [];
        foreach ($brands as $bid => $req) {
            $act = (int)($active_rows[$bid] ?? 0);
            $pct = min(100, round($act / max(1, $req) * 100, 1));
            $result[$bid] = [
                'pct'    => $pct,
                'active' => $act,
                'req'    => (int)$req,
                'gap'    => max(0, $req - $act),
            ];
        }
        return $result;
    } catch (Exception $e) { return []; }
}

function brand_compliance_pct(int $brand_id): float {
    $all = brand_compliance_all();
    return $all[$brand_id]['pct'] ?? 100.0;
}

// ─── DATE / STATO ─────────────────────────────────────────────────────────────

function format_date(?string $d, string $fmt = 'd/m/Y'): string {
    if (!$d) return '—';
    try { return (new DateTime($d))->format($fmt); }
    catch (Exception $e) { return $d; }
}

function days_diff(string $date): int {
    try {
        $diff = (new DateTime())->diff(new DateTime($date));
        return (int)($diff->invert ? -$diff->days : $diff->days);
    } catch (Exception $e) { return 0; }
}

/**
 * FIX BUG #10: usa soglia configurabile da settings invece di 90 hardcoded
 */
function cert_status_from_date(?string $expiry): string {
    if (!$expiry) return 'active';
    $d = days_diff($expiry);
    if ($d < 0) return 'expired';
    $threshold = (int)(load_settings()['notify_days_1'] ?? 90);
    if ($d <= $threshold) return 'expiring';
    return 'active';
}

function status_badge(string $status): string {
    return match($status) {
        'active'   => '<span class="badge badge-success">Attiva</span>',
        'expiring' => '<span class="badge badge-warning">In scadenza</span>',
        'expired'  => '<span class="badge badge-danger">Scaduta</span>',
        'revoked'  => '<span class="badge badge-neutral">Revocata</span>',
        default    => '<span class="badge badge-neutral">' . h($status) . '</span>',
    };
}

function priority_badge(string $p): string {
    return match($p) {
        'Urgente' => '<span class="badge" style="background:#fee2e2;color:#991b1b">Urgente</span>',
        'Alta'    => '<span class="badge badge-warning">Alta</span>',
        'Media'   => '<span class="badge badge-info">Media</span>',
        default   => '<span class="badge badge-neutral">Bassa</span>',
    };
}

// ─── SETTINGS ─────────────────────────────────────────────────────────────────

function load_settings(): array {
    global $pdo;
    static $_cache = null;
    if ($_cache !== null) return $_cache;
    try {
        $_cache = $pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $_cache;
    } catch (Exception $e) { return []; }
}

function load_settings_fresh(): array {
    global $pdo;
    try {
        return $pdo->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { return []; }
}

function save_setting(string $key, string $value): void {
    global $pdo;
    $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
    )->execute([$key, $value]);
}
