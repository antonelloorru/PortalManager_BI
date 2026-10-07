<?php
/**
 * tools/verify_v1_10_20.php — verifica della v1.10.20 / plugin pm-ats 1.3.1: pagina «Lavora con noi» con elenco delle posizioni
 * (titolo cliccabile → scheda) a sinistra e modulo di candidatura a destra; layout predefinito; shortcode dedicato.
 * Uso: php tools/verify_v1_10_20.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.20", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.20');
chk("VERSION = 1.10.20 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.20' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.20')"));
chk("pm_migration_sql contiene 1.10.20", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.20'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_20.md", is_file("$root/docs/{$d}_v1_10_20.md"));
$t = $file("$pd/templates/jobs-accordion.php");
chk("Elenco posizioni: titolo cliccabile verso la scheda (get_permalink)", str_contains($t, 'class="pm-ats-wt-link" href="<?php echo esc_url(get_permalink($p)); ?>"') && str_contains($t, "\$mode === 'link'"));
chk("Modulo nella colonna destra dopo l'elenco", strpos($t, 'pm-ats-wt-col-jobs') < strpos($t, 'pm-ats-wt-col-form') && str_contains($t, '<?php echo $form;'));
$css = $file("$pd/assets/pm-ats-wetechs.css");
chk("Due colonne affiancate (47,25% + 5,5%) con priorità sugli stili del tema", str_contains($css, 'flex-direction:row!important') && str_contains($css, 'max-width:47.25%'));
chk("Colonne secondo il contenitore (container query), nessun 100vw", str_contains($css, '@container pmatswt (max-width:760px)') && !str_contains($css, '100vw'));
$s = $file("$pd/includes/class-pm-ats-settings.php");
chk("Layout predefinito «Lavora con noi», elenco a link predefinito", str_contains($s, "'layout'             => 'accordion'") && str_contains($s, "'wt_list_mode'       => 'link'"));
chk("Aggiornamento: griglia/lista → «Lavora con noi» una sola volta (schema 4)", str_contains($file("$pd/includes/class-pm-ats-upgrade.php"), '$prevSchema < 4') && str_contains($file("$pd/pm-ats.php"), "define('PM_ATS_SETTINGS_VERSION', '4')"));
chk("Shortcode [pm_ats_lavora_con_noi]", str_contains($file("$pd/includes/class-pm-ats-public.php"), "add_shortcode('pm_ats_lavora_con_noi'"));
chk("Stili nell'<head> per ogni shortcode pm-ats", str_contains($file("$pd/includes/class-pm-ats-public.php"), 'pm_ats_(jobs|apply|lavora_con_noi)'));
$main = $file("$pd/pm-ats.php");
chk("Plugin 1.3.1: header = costante = Stable tag", (bool)preg_match('/Version:\s*1\.3\.1/', $main) && str_contains($main, "define('PM_ATS_VERSION', '1.3.1')") && (bool)preg_match('/^Stable tag:\s*1\.3\.1\s*$/mi', $file("$pd/readme.txt")));
chk("Template jobs-accordion.php @version 1.3.1", (bool)preg_match('/@version\s+1\.3\.1/', $t));
chk("Struttura vincolante invariata (sezioni 1-5)", str_contains($file("$pd/includes/class-pm-ats-jobs.php"), "'chi-siamo'     => ['Chi siamo'") && str_contains($file("$pd/includes/class-pm-ats-jobs.php"), "'offriamo'      => ['Cosa offriamo'"));
$z = new ZipArchive();
chk("Pacchetto pm-ats-1.3.1.zip", $z->open("$root/integrations/wordpress/pm-ats-1.3.1.zip") === true && preg_match('/Version:\s*1\.3\.1/', (string)$z->getFromName('pm-ats/pm-ats.php')) === 1);
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
