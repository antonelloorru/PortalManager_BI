<?php
/**
 * tools/verify_v1_10_22.php — verifica della v1.10.22 / plugin pm-ats 1.3.3: titolo della pagina del tema (es. Divi
 * h1.entry-title.main_title) visibile, nascosto o personalizzato nelle pagine «Lavora con noi»; invarianti 1.3.2 (nessuna barra laterale).
 * Uso: php tools/verify_v1_10_22.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.22", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.22');
chk("VERSION = 1.10.22 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.22' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.22')"));
chk("pm_migration_sql contiene 1.10.22", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.22'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_22.md", is_file("$root/docs/{$d}_v1_10_22.md"));
$pub = $file("$pd/includes/class-pm-ats-public.php");
$set = $file("$pd/includes/class-pm-ats-settings.php");
$adm = $file("$pd/includes/class-pm-ats-admin.php");
$css = $file("$pd/assets/pm-ats.css");
$main = $file("$pd/pm-ats.php");
chk("Filtro the_title → pageTitle", str_contains($pub, "add_filter('the_title', [self::class, 'pageTitle'], 20, 2)"));
chk("Solo titolo della pagina richiesta nel ciclo principale (menu e <title> invariati)", str_contains($pub, 'in_the_loop()') && str_contains($pub, 'is_main_query()') && str_contains($pub, '(int)get_queried_object_id()'));
chk("Ambito: pagine elenco/shortcode, esclusa la scheda posizione (isListPage)", str_contains($pub, 'public static function isListPage') && str_contains($pub, 'self::matchPage($postId, false)'));
chk("Testo personalizzato codificato (esc_html)", str_contains($pub, 'esc_html($t)'));
chk("Classe pm-ats-hide-title + regola CSS Divi main_title", str_contains($pub, "'pm-ats-hide-title'") && str_contains($css, 'body.pm-ats-hide-title .entry-title.main_title'));
chk("Impostazioni page_title_mode (show predefinito) e page_title_text", str_contains($set, "'page_title_mode'    => 'show'") && str_contains($set, "'page_title_text'    => ''"));
chk("Sanitizzazione: whitelist show|hide|custom, testo max 150", str_contains($set, "['show', 'hide', 'custom']") && str_contains($set, "mb_substr(sanitize_text_field((string)(\$in['page_title_text']"));
chk("Chiavi nel tab Aspetto", str_contains($set, "'page_title_mode', 'page_title_text'"));
chk("Campo «Titolo della pagina» in Impostazioni › Aspetto", str_contains($adm, "\$f('page_title_mode')") && str_contains($adm, "\$txt('page_title_text'"));
chk("Schema impostazioni 6", str_contains($main, "define('PM_ATS_SETTINGS_VERSION', '6')"));
chk("Plugin 1.3.3: header = costante = Stable tag", (bool)preg_match('/Version:\s*1\.3\.3/', $main) && str_contains($main, "define('PM_ATS_VERSION', '1.3.3')") && (bool)preg_match('/^Stable tag:\s*1\.3\.3\s*$/mi', $file("$pd/readme.txt")));
chk("CHANGELOG.md e readme 1.3.3", str_contains($file("$pd/CHANGELOG.md"), '## 1.3.3') && str_contains($file("$pd/readme.txt"), '= 1.3.3 ='));
chk("PortalManager: PLUGIN_RECOMMENDED = 1.3.3", str_contains($file('app/WpAtsConfig.php'), "PLUGIN_RECOMMENDED = '1.3.3'"));
chk("Invariato 1.3.2: pagine senza barra laterale", str_contains($pub, "'et_no_sidebar'") && str_contains($pub, "'pm-ats-no-sidebar'") && str_contains($css, 'body.pm-ats-no-sidebar #sidebar'));
chk("Struttura vincolante invariata (sezioni 1-5)", str_contains($file("$pd/includes/class-pm-ats-jobs.php"), "'chi-siamo'     => ['Chi siamo'") && str_contains($file("$pd/includes/class-pm-ats-jobs.php"), "'offriamo'      => ['Cosa offriamo'"));
$z = new ZipArchive();
chk("Pacchetto pm-ats-1.3.3.zip", $z->open("$root/integrations/wordpress/pm-ats-1.3.3.zip") === true && preg_match('/Version:\s*1\.3\.3/', (string)$z->getFromName('pm-ats/pm-ats.php')) === 1);
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
