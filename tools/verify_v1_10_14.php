<?php
/**
 * tools/verify_v1_10_14.php — verifica della v1.10.14: integrazione pm-ats (plugin WordPress ↔ PortalManager).
 *   PortalManager: configurazione guidata (wp_ats_setup), impostazioni (wp_ats_settings), WpAtsConfig, registrazioni menu/router/permessi.
 *   Plugin 1.1.0: wizard di onboarding, impostazioni a schede, versioning formale (header, costanti, asset, template), pacchetto ZIP.
 * Uso: php tools/verify_v1_10_14.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--online]
 *   --online esegue anche il test firmato verso il sito (se configurato).
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
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 74) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

echo "— Versioning PortalManager\n";
chk("app_settings.app_version = 1.10.14", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.14');
chk("VERSION = 1.10.14 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.14' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.14')"));
chk("pm_migration_sql contiene 1.10.07 … 1.10.14", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09','1.10.10','1.10.11','1.10.12','1.10.13','1.10.14')") === 8);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_14.md", is_file("$root/docs/{$d}_v1_10_14.md"));

echo "\n— Lato PortalManager\n";
foreach (['app/WpAtsConfig.php', 'wp_ats_setup.php', 'wp_ats_settings.php', 'wp_ats_sync.php'] as $f) chk("File $f", is_file("$root/$f"));
foreach (['wpats.setup_done', 'wpats.setup_at', 'wpats.setup_step', 'wpats.remote_info'] as $k)
    chk("Impostazione $k presente", (int)$one("SELECT COUNT(*) FROM app_settings WHERE setting_key='$k'") === 1);
chk("Segreto NON nel database (nessuna chiave wpats.*secret*)", (int)$one("SELECT COUNT(*) FROM app_settings WHERE setting_key LIKE 'wpats.%secret%'") === 0);
$r = $file('app/Router.php');
chk("Router: wp_ats_settings e wp_ats_setup in PAGES", str_contains($r, "'wp_ats_settings'") && str_contains($r, "'wp_ats_setup'"));
chk("MenuManager: voci Impostazioni e Configurazione guidata", str_contains($file('app/MenuManager.php'), "'page' => 'wp_ats_settings'") && str_contains($file('app/MenuManager.php'), "'page' => 'wp_ats_setup'"));
chk("PermissionCatalog e manage_permissions: nuove pagine", str_contains($file('app/PermissionCatalog.php'), "'wp_ats_settings.php'") && str_contains($file('manage_permissions.php'), "'wp_ats_setup.php'"));
foreach (['wp_ats_setup.php', 'wp_ats_settings.php'] as $f) chk("$f: riservata al Super Admin + CSRF", str_contains($file($f), "role_id'] ?? 99) !== 1") && str_contains($file($f), 'Csrf::verify()'));
$s = $file('wp_ats_sync.php');
chk("wp_ats_sync.php: configurazione spostata (nessun salvataggio segreto qui), banner guidata", !str_contains($s, 'Env::persist') && str_contains($s, "url_safe('wp_ats_setup')") && str_contains($s, "url_safe('wp_ats_settings')"));
// codice di connessione: round trip
$sec = bin2hex(random_bytes(32));
$code = 'PMATS1.' . rtrim(strtr(base64_encode(json_encode(['v' => 1, 'url' => 'https://www.esempio.it/wp-json/pm-ats/v1', 'client' => 'portalmanager', 'secret' => $sec, 'plugin' => '1.1.0', 'api' => '1', 'site' => 'https://www.esempio.it/'])), '+/', '-_'), '=');
$c = WpAtsConfig::parseCode($code);
chk("WpAtsConfig::parseCode: codice valido decodificato", $c !== null && $c['secret'] === $sec && $c['client'] === 'portalmanager' && str_starts_with($c['url'], 'https://www.esempio.it/'));
chk("WpAtsConfig::parseCode: codice alterato rifiutato", WpAtsConfig::parseCode('PMATS1.xxx') === null && WpAtsConfig::parseCode(substr($code, 1)) === null);
chk("Compatibilità: plugin 1.1.0 / API 1 → ok", WpAtsConfig::compat(['plugin' => '1.1.0', 'api' => '1', 'onboarding' => 'done'])['level'] === 'ok');
chk("Compatibilità: plugin 1.0.0 → warn (sincronizzazione base)", WpAtsConfig::compat(['plugin' => '1.0.0'])['level'] === 'warn');
chk("Compatibilità: API v2 → ko", WpAtsConfig::compat(['plugin' => '2.0.0', 'api' => '2'])['level'] === 'ko');
chk("Prerequisiti: nessun requisito bloccante", !in_array('ko', array_column(WpAtsConfig::prerequisites($pdo), 0), true));
$done = WpAtsConfig::setupDone($pdo); $url = WpAtsConfig::setting($pdo, 'wpats.base_url', '');
chk("Stato configurazione guidata", true, $done ? '(completata)' : ($url !== '' ? '(URL presente, guidata da completare)' : '(da eseguire)'));
if (isset($args['online']) && $url !== '') {
    $t = WpAtsConfig::test($pdo, null);
    chk("Test firmato verso il sito + compatibilità", $t['ok'], mb_substr($t['message'] . ' — ' . ($t['compat']['msg'] ?? ''), 0, 120));
}

echo "\n— Lato WordPress (plugin pm-ats)\n";
$pd = 'integrations/wordpress/pm-ats';
$main = $file("$pd/pm-ats.php");
preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $main, $hv); preg_match("/define\('PM_ATS_VERSION',\s*'([^']+)'/", $main, $cv);
chk("Header Version = costante PM_ATS_VERSION = 1.1.0", ($hv[1] ?? '') === '1.1.0' && ($cv[1] ?? '') === '1.1.0');
foreach (['PM_ATS_API_VERSION', 'PM_ATS_DB_VERSION', 'PM_ATS_SETTINGS_VERSION', 'PM_ATS_TEMPLATE_VERSION', 'PM_ATS_MIN_PM'] as $k) chk("Costante $k", str_contains($main, "'$k'"));
preg_match("/define\('PM_ATS_MIN_PM',\s*'([^']+)'/", $main, $mp);
chk("PM_ATS_MIN_PM ≤ versione PortalManager", isset($mp[1]) && version_compare(trim($file('VERSION')), $mp[1], '>='), '(' . ($mp[1] ?? '?') . ')');
chk("readme.txt: Stable tag 1.1.0", (bool)preg_match('/^Stable tag:\s*1\.1\.0\s*$/mi', $file("$pd/readme.txt")));
chk("CHANGELOG.md del plugin con 1.1.0", str_contains($file("$pd/CHANGELOG.md"), '1.1.0'));
foreach (['assets/admin.css', 'assets/pm-ats.css', 'assets/pm-ats.js'] as $a) chk("Asset $a: @version 1.1.0", (bool)preg_match('/@version\s+1\.1\.0/', substr($file("$pd/$a"), 0, 400)));
foreach (glob("$root/$pd/templates/*.php") ?: [] as $t) chk('Template ' . basename($t) . ': @version 1.1.0', (bool)preg_match('/@version\s+1\.1\.0/', (string)file_get_contents($t, false, null, 0, 1024)));
foreach (['includes/class-pm-ats-setup.php', 'includes/class-pm-ats-upgrade.php'] as $f) chk("File $f", is_file("$root/$pd/$f"));
chk("Wizard: nonce, capability, 5 passi", str_contains($file("$pd/includes/class-pm-ats-setup.php"), "'pm_ats_setup'") && str_contains($file("$pd/includes/class-pm-ats-setup.php"), 'manage_options') && str_contains($file("$pd/includes/class-pm-ats-setup.php"), 'step5'));
chk("REST: status espone versioni + header X-PM-ATS-Version", str_contains($file("$pd/includes/class-pm-ats-rest.php"), 'X-PM-ATS-Version') && str_contains($file("$pd/includes/class-pm-ats-rest.php"), "'settings_schema'"));
chk("Export impostazioni senza segreto", !str_contains(substr($file("$pd/includes/class-pm-ats-admin.php"), (int)strpos($file("$pd/includes/class-pm-ats-admin.php"), "if (\$op === 'export')"), 600), 'secret'));
$zp = "$root/integrations/wordpress/pm-ats-1.1.0.zip"; $z = new ZipArchive();
$zok = is_file($zp) && $z->open($zp) === true;
chk("Pacchetto pm-ats-1.1.0.zip (cartella pm-ats/, header 1.1.0)", $zok && preg_match('/Version:\s*1\.1\.0/', (string)$z->getFromName('pm-ats/pm-ats.php')) === 1 && $z->locateName('pm-ats/includes/class-pm-ats-setup.php') !== false);
if ($zok) $z->close();

echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
