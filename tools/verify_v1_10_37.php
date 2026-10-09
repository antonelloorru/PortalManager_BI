<?php
/**
 * tools/verify_v1_10_37.php — verifica della v1.10.37: plugin pm-ats 1.3.5 (testata a tutta larghezza della finestra). Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_37.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 80) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$pd = 'integrations/wordpress/pm-ats';

chk("app_settings.app_version = 1.10.37", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.37');
chk("VERSION = 1.10.37 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.37' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.37')"));
chk("pm_migration_sql contiene 1.10.37", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.37'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_37.md", is_file("$root/docs/{$d}_v1_10_37.md"));
$pd = 'integrations/wordpress/pm-ats';
$main = $file("$pd/pm-ats.php"); $css = $file("$pd/assets/pm-ats-wetechs.css"); $js = $file("$pd/assets/pm-ats.js");
chk("Plugin 1.3.5: header, costante, Stable tag", str_contains($main, 'Version:           1.3.5') && str_contains($main, "define('PM_ATS_VERSION', '1.3.5')") && str_contains($file("$pd/readme.txt"), 'Stable tag: 1.3.5'));
chk("Schema impostazioni 8 con wt_hero_width", str_contains($main, "define('PM_ATS_SETTINGS_VERSION', '8')") && str_contains($file("$pd/includes/class-pm-ats-settings.php"), "'wt_hero_width'      => 'window'"));
chk("Testata: classe full-width e selettore Larghezza", substr_count($file("$pd/includes/class-pm-ats-public.php"), "\$full . '") === 3 && str_contains($file("$pd/includes/class-pm-ats-admin.php"), "wt_hero_width"));
chk("CSS/JS: larghezza finestra e scostamento", str_contains($css, 'var(--pm-ats-hero-w,100vw)') && str_contains($css, 'calc(50% - 50vw)') && str_contains($js, 'document.documentElement.clientWidth') && str_contains($js, '--pm-ats-hero-ml'));
chk("Asset @version 1.3.5", str_contains($css, '@version 1.3.5') && str_contains($js, '@version 1.3.5'));
chk("CHANGELOG plugin 1.3.5", str_contains($file("$pd/CHANGELOG.md"), '## 1.3.5'));
$z = new ZipArchive(); $okZ = is_file("$root/integrations/wordpress/pm-ats-1.3.5.zip") && $z->open("$root/integrations/wordpress/pm-ats-1.3.5.zip") === true && $z->locateName('pm-ats/pm-ats.php') !== false;
if ($okZ) { $okZ = str_contains((string)$z->getFromName('pm-ats/pm-ats.php'), "'1.3.5'"); $z->close(); }
chk("Pacchetto pm-ats-1.3.5.zip coerente", $okZ);
require_once "$root/app/WpAtsConfig.php";
chk("PortalManager: PLUGIN_RECOMMENDED = 1.3.5", WpAtsConfig::PLUGIN_RECOMMENDED === '1.3.5');
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
