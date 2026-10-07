<?php
/**
 * tools/verify_v1_10_16.php — verifica della v1.10.16: diagnostica dell'handshake PortalManager → pm-ats e fix del plugin 1.1.1
 * (verifica HMAC eseguita una sola volta per richiesta: niente falsi «replay» né blocco 429 dopo 20 chiamate).
 * Uso: php tools/verify_v1_10_16.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--online=N]
 *   --online=N esegue la diagnostica e N test consecutivi verso il sito configurato (N > 20 dimostra l'assenza del blocco 429).
 */
declare(strict_types=1);
$args = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? '1';
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
require_once "$root/app/WpAtsConfig.php";
require_once "$root/app/WpAtsDiag.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 78) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

chk("app_settings.app_version = 1.10.16", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.16');
chk("VERSION = 1.10.16 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.16' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.16')"));
chk("pm_migration_sql contiene 1.10.07 … 1.10.16", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09','1.10.10','1.10.11','1.10.12','1.10.13','1.10.14','1.10.15','1.10.16')") === 10);
chk("Tabella wp_ats_diag", (bool)$one("SHOW TABLES LIKE 'wp_ats_diag'"));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_16.md", is_file("$root/docs/{$d}_v1_10_16.md"));

echo "\n— PortalManager\n";
chk("WpAtsDiag: 6 passi (configurazione, DNS, TLS, plugin, autenticazione, orologio)", str_contains($file('app/WpAtsDiag.php'), "'6 Orologio'") && str_contains($file('app/WpAtsDiag.php'), "'4 Plugin pm-ats'"));
chk("WpAtsClient: errno cURL, redirect, CA automatica / archivio nativo Windows", str_contains($file('app/WpAtsClient.php'), 'CURLINFO_REDIRECT_URL') && str_contains($file('app/WpAtsClient.php'), 'CURLSSLOPT_NATIVE_CA') && str_contains($file('app/WpAtsClient.php'), 'function caBundle'));
chk("Impronta del segreto identica al plugin", WpAtsClient::fingerprint('abc') === substr(hash('sha256', 'pm-ats-fp|abc'), 0, 12) && str_contains($file('integrations/wordpress/pm-ats/includes/class-pm-ats-settings.php'), "'pm-ats-fp|'"));
$cases = [
    'cURL 60'                 => ['status' => 0, 'error' => 'SSL certificate problem: unable to get local issuer certificate', 'errno' => 60, 'headers' => [], 'json' => null, 'body' => ''],
    'HTTP 301'                => ['status' => 301, 'error' => null, 'headers' => ['location' => 'https://www.sito.it/wp-json/pm-ats/v1/sync/status'], 'json' => null, 'body' => '', 'info' => ['redirect' => 'https://www.sito.it/wp-json/pm-ats/v1/sync/status']],
    'HTTP 401 bad_signature'  => ['status' => 401, 'error' => null, 'headers' => ['x-pm-ats-version' => '1.1.1; api=1', 'x-pm-ats-error' => 'bad_signature'], 'json' => ['code' => 'pm_ats_bad_signature', 'message' => 'x', 'data' => ['status' => 401, 'reason' => 'bad_signature', 'route' => '/pm-ats/v1/sync/status']], 'body' => ''],
    'HTTP 401 clock_skew'     => ['status' => 401, 'error' => null, 'headers' => [], 'json' => ['code' => 'pm_ats_clock_skew', 'message' => 'clock_skew', 'data' => ['status' => 401]], 'body' => ''],
    'HTTP 403 ip_not_allowed' => ['status' => 403, 'error' => null, 'headers' => ['x-pm-ats-error' => 'ip_not_allowed'], 'json' => ['code' => 'pm_ats_ip_not_allowed', 'message' => 'x', 'data' => ['status' => 403, 'reason' => 'ip_not_allowed', 'client_ip' => '203.0.113.7']], 'body' => ''],
    'HTTP 403'                => ['status' => 403, 'error' => null, 'headers' => ['server' => 'cloudflare', 'content-type' => 'text/html'], 'json' => null, 'body' => '<title>Attention Required! | Cloudflare</title>'],
    'HTTP 401 rest_not_logged_in' => ['status' => 401, 'error' => null, 'headers' => [], 'json' => ['code' => 'rest_not_logged_in', 'message' => 'x'], 'body' => ''],
    'HTTP 429 too_many_failures'  => ['status' => 429, 'error' => null, 'headers' => [], 'json' => ['code' => 'pm_ats_too_many_failures', 'message' => 'x', 'data' => ['status' => 429]], 'body' => ''],
];
foreach ($cases as $exp => $r) { $d = WpAtsClient::describe($r); chk("Codice effettivo: $exp", str_starts_with($d, $exp) && str_contains($d, '→'), mb_substr($d, 0, 70)); }
chk("403 di firewall/CDN distinto dal 403 del plugin", WpAtsClient::analyze($cases['HTTP 403'])['origin'] === 'intermediario' && WpAtsClient::analyze($cases['HTTP 403 ip_not_allowed'])['origin'] === 'plugin');
chk("Compatibilità: plugin 1.1.0 → avviso aggiornamento a 1.1.1", WpAtsConfig::compat(['plugin' => '1.1.0', 'api' => '1'])['level'] === 'warn' && WpAtsConfig::compat(['plugin' => '1.1.1', 'api' => '1', 'onboarding' => 'done'])['level'] === 'ok');

echo "\n— Plugin pm-ats 1.1.1\n";
$pd = 'integrations/wordpress/pm-ats';
$main = $file("$pd/pm-ats.php"); $auth = $file("$pd/includes/class-pm-ats-auth.php");
chk("Header Version = PM_ATS_VERSION = Stable tag = 1.1.1", (bool)preg_match('/Version:\s*1\.1\.1/', $main) && str_contains($main, "define('PM_ATS_VERSION', '1.1.1')") && (bool)preg_match('/^Stable tag:\s*1\.1\.1\s*$/mi', $file("$pd/readme.txt")));
chk("Fix: una sola verifica HMAC per richiesta (cache per oggetto richiesta)", str_contains($auth, 'spl_object_hash($req)') && str_contains($auth, 'private static function check('));
chk("Errori con data.reason e dati di diagnosi (client_ip, server_time, route)", str_contains($auth, "'client_ip' => \$ip") && str_contains($auth, "'server_time' => time()") && str_contains($auth, "'route' => \$req->get_route()"));
chk("X-PM-ATS-Version / X-PM-ATS-Error su tutte le risposte del namespace", str_contains($file("$pd/includes/class-pm-ats-rest.php"), "'X-PM-ATS-Error'") && str_contains($file("$pd/includes/class-pm-ats-rest.php"), 'rest_post_dispatch'));
chk("CHANGELOG del plugin con 1.1.1", str_contains($file("$pd/CHANGELOG.md"), '## 1.1.1'));
$z = new ZipArchive();
chk("Pacchetto pm-ats-1.1.1.zip", $z->open("$root/integrations/wordpress/pm-ats-1.1.1.zip") === true && preg_match('/Version:\s*1\.1\.1/', (string)$z->getFromName('pm-ats/pm-ats.php')) === 1 && str_contains((string)$z->getFromName('pm-ats/includes/class-pm-ats-auth.php'), 'spl_object_hash'));

if (isset($args['online'])) {
    echo "\n— In linea\n";
    $d = WpAtsDiag::run($pdo);
    chk("Diagnostica dell'handshake", $d['ok'], $d['summary']);
    $n = max(1, (int)$args['online']); $okN = 0; $last = '';
    $c = WpAtsClient::fromSettings($pdo);
    for ($i = 0; $i < $n && $c; $i++) { $r = $c->request('GET', '/sync/status'); if ($r['status'] === 200) $okN++; else $last = WpAtsClient::describe($r); }
    chk("$n test consecutivi riusciti (nessun blocco 429)", $okN === $n, "($okN/$n) $last");
}
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
