<?php
/**
 * tools/verify_v1_10_05.php — verifica della v1.10.05: sincronizzazione con il sito WordPress (plugin pm-ats).
 * Uso: php tools/verify_v1_10_05.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--live]
 *   --live: esegue anche il test di connessione al sito con la configurazione salvata (sola lettura).
 */
declare(strict_types=1);
$args = []; $live = false;
foreach (array_slice($argv, 1) as $a) { if ($a === '--live') $live = true; elseif (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2]; }
$host = $args['host'] ?? getenv('PM_HC_HOST') ?: '127.0.0.1';
$db   = $args['db']   ?? getenv('PM_HC_DB')   ?: 'portalmanager';
$user = $args['user'] ?? getenv('PM_HC_USER') ?: 'root';
$pass = $args['pass'] ?? getenv('PM_HC_PASS') ?: '';
$sock = $args['socket'] ?? '';
try {
    $dsn = $sock !== '' ? "mysql:unix_socket=$sock;dbname=$db;charset=utf8mb4" : "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "✘ Connessione: " . $e->getMessage() . "\n"; exit(1); }
$root = realpath(__DIR__ . '/..');
if (!defined('APP_BASE')) define('APP_BASE', $root);
require_once "$root/app/WpAtsSync.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 70) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

// versione e schema
chk("app_settings.app_version = 1.10.05", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.05');
chk("VERSION = 1.10.05 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.05' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.05')"));
chk("pm_migration_sql contiene 1.10.05", (bool)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version='1.10.05'"));
foreach (['wp_ats_sync_log', 'wp_ats_imports'] as $t)
    chk("Tabella $t", (bool)$one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'"));
chk("position_publications.channel include 'wordpress'", str_contains((string)$one("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='position_publications' AND COLUMN_NAME='channel'"), "'wordpress'"));
chk("Impostazioni wpats.* (9)", (int)$one("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'wpats.%'") >= 9);
chk("Nessun segreto nel database (wpats.*)", !(int)$one("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'wpats.%' AND setting_value REGEXP '^[a-f0-9]{48,}$'"));
chk("Permessi wp_ats_sync.php (ruoli con Pubblica su portali)", (int)$one("SELECT COUNT(*) FROM role_permissions WHERE page_name='wp_ats_sync.php'") >= 1);
chk("Vista v_public_open_positions", (bool)$one("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v_public_open_positions'"));

// file e collegamenti
foreach (['wp_ats_sync.php', 'cron_wp_ats.php', 'app/WpAtsClient.php', 'app/WpAtsSync.php', 'sql/migration_v1_10_05.sql'] as $f) chk("File $f", is_file("$root/$f"));
chk("Router: pagina wp_ats_sync", str_contains($file('app/Router.php'), "'wp_ats_sync'"));
chk("Menu Recruiting: Sito web (WordPress)", str_contains($file('app/MenuManager.php'), "'page' => 'wp_ats_sync'"));
chk("Catalogo permessi e manage_permissions", str_contains($file('app/PermissionCatalog.php'), "'wp_ats_sync.php'") && str_contains($file('manage_permissions.php'), "'wp_ats_sync.php'"));
chk("recruiting_posizioni: invio immediato su modifica", str_contains($file('recruiting_posizioni.php'), 'WpAtsSync::pushOnChange'));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_05.md", is_file("$root/docs/{$d}_v1_10_05.md"));

// plugin WordPress
$pl = "$root/integrations/wordpress/pm-ats";
$plFiles = ['pm-ats.php', 'uninstall.php', 'readme.txt', 'includes/class-pm-ats-settings.php', 'includes/class-pm-ats-log.php', 'includes/class-pm-ats-auth.php',
            'includes/class-pm-ats-jobs.php', 'includes/class-pm-ats-applications.php', 'includes/class-pm-ats-rest.php', 'includes/class-pm-ats-public.php',
            'includes/class-pm-ats-privacy.php', 'includes/class-pm-ats-admin.php', 'templates/jobs-list.php', 'templates/job-card.php',
            'templates/job-single.php', 'templates/apply-form.php', 'assets/pm-ats.css', 'assets/pm-ats.js', 'assets/admin.css'];
$miss = array_values(array_filter($plFiles, fn($f) => !is_file("$pl/$f")));
chk("Plugin pm-ats: " . count($plFiles) . " file", !$miss, $miss ? '(mancano: ' . implode(', ', $miss) . ')' : '');
$lint = [];
foreach ($plFiles as $f) if (str_ends_with($f, '.php')) { exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg("$pl/$f") . ' 2>&1', $o, $rc); if ($rc) $lint[] = $f; }
chk("Plugin pm-ats: sintassi PHP", !$lint, $lint ? implode(', ', $lint) : '');
preg_match("/define\('PM_ATS_VERSION', '([^']+)'\)/", (string)@file_get_contents("$pl/pm-ats.php"), $mv);
preg_match('/Stable tag:\s*(\S+)/', (string)@file_get_contents("$pl/readme.txt"), $mr);
preg_match('/\*\s*Version:\s*(\S+)/', (string)@file_get_contents("$pl/pm-ats.php"), $mh);
chk("Plugin: versione coerente (intestazione, costante, readme)", ($mv[1] ?? '') !== '' && ($mv[1] ?? '') === ($mr[1] ?? '') && ($mv[1] ?? '') === ($mh[1] ?? ''), '(' . ($mv[1] ?? '?') . ')');

// funzioni
$cases = ['https://www.sito.it' => 'https://www.sito.it/wp-json/pm-ats/v1', 'https://www.sito.it/wp-json/' => 'https://www.sito.it/wp-json/pm-ats/v1',
          'https://www.sito.it/wp-json/pm-ats/v1' => 'https://www.sito.it/wp-json/pm-ats/v1', 'https://www.sito.it/?rest_route=/' => 'https://www.sito.it/?rest_route=/pm-ats/v1', 'ftp://x' => null];
$bad = 0; foreach ($cases as $in => $exp) if (WpAtsClient::normalizeBase($in) !== $exp) $bad++;
chk("Normalizzazione URL del plugin (5 casi)", $bad === 0);
// firma: stessa formula del plugin (PM_ATS_Auth::verify)
$sec = bin2hex(random_bytes(32)); $ts = '1700000000'; $n = str_repeat('a', 32); $route = '/pm-ats/v1/sync/jobs'; $body = '{"mode":"full","items":[]}';
$pm = hash_hmac('sha256', "POST\n$route\n$ts\n$n\n" . hash('sha256', $body), $sec);
$authSrc = (string)@file_get_contents("$pl/includes/class-pm-ats-auth.php");
chk("Firma HMAC: stesso schema canonico di PortalManager e del plugin", str_contains($authSrc, '"\n" . $req->get_route() . "\n" . $ts . "\n" . $nonce . "\n"') && strlen($pm) === 64
    && str_contains($file('app/WpAtsClient.php'), '$method . "\n" . $route . "\n" . $ts . "\n" . $nonce . "\n" . hash(\'sha256\', $body)'));
$sync = new WpAtsSync($pdo, null);
$pos = $sync->openPositions();
chk("Posizioni da pubblicare = vista v_public_open_positions", count($pos) === (int)$one("SELECT COUNT(*) FROM v_public_open_positions"), '(' . count($pos) . ')');

if ($live) {
    if (!$sync->configured()) chk("Test di connessione al sito", false, 'configurazione incompleta (URL, client ID, PM_WPATS_SECRET in .env.php)');
    else { $r = $sync->test(null, 'manuale'); chk("Test di connessione al sito", $r['ok'], '(' . $r['message'] . ')'); }
}
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
