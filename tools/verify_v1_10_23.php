<?php
/**
 * tools/verify_v1_10_23.php — verifica della v1.10.23 / plugin pm-ats 1.3.4: testata «Lavora con noi» adattiva alla larghezza della
 * finestra, immagine dalla Libreria media (wp.media, srcset), riferimenti di creazione; invarianti 1.3.2-1.3.3.
 * Uso: php tools/verify_v1_10_23.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.23", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.23');
chk("VERSION = 1.10.23 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.23' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.23')"));
chk("pm_migration_sql contiene 1.10.23", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.23'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_23.md", is_file("$root/docs/{$d}_v1_10_23.md"));
$pub = $file("$pd/includes/class-pm-ats-public.php");
$set = $file("$pd/includes/class-pm-ats-settings.php");
$adm = $file("$pd/includes/class-pm-ats-admin.php");
$wcss = $file("$pd/assets/pm-ats-wetechs.css");
$js  = $file("$pd/assets/pm-ats-admin.js");
$tpl = $file("$pd/templates/jobs-accordion.php");
$main = $file("$pd/pm-ats.php");
chk("Testata composta da heroHtml (scale|cover) e passata al template", str_contains($pub, 'public static function heroHtml(array $s)') && str_contains($pub, "'hero_html' =>") && str_contains($tpl, 'PM_ATS_Public::heroHtml($s)'));
chk("scale: img larghezza 100%, altezza automatica", str_contains($wcss, 'width:100%!important;max-width:100%!important;height:auto!important'));
chk("cover: altezza proporzionale alla larghezza (cqi), nessun 100vw", str_contains($wcss, 'height:clamp(150px,32.8cqi,560px)') && !str_contains($wcss, '100vw'));
chk("Titolo testata fluido, nessuna dimensione fissa nelle media query", str_contains($wcss, 'clamp(24px,5.2cqi,60px)') && !str_contains($wcss, '.pm-ats-wt-h1{font-size:40px}') && !str_contains($wcss, '.pm-ats-wt-h1{font-size:56px}'));
chk("srcset dalla Libreria media (wp_get_attachment_image, sizes 100vw)", str_contains($pub, "wp_get_attachment_image(\$id, 'full'") && str_contains($pub, "'sizes' => '100vw'"));
chk("ID allegato ignorato se non coerente con l'URL (sameMedia)", str_contains($pub, 'self::sameMedia($url, (string)wp_get_attachment_url($id))'));
chk("Impostazioni wt_hero_image_id e wt_hero_fit (scale predefinito) nel tab Aspetto", str_contains($set, "'wt_hero_image_id'   => 0") && str_contains($set, "'wt_hero_fit'        => 'scale'") && str_contains($set, "'wt_hero_image', 'wt_hero_image_id', 'wt_hero_fit'"));
chk("Sanitizzazione ID: immagine e leggibile dall'utente", str_contains($set, "wp_attachment_is_image(\$o['wt_hero_image_id'])") && str_contains($set, "current_user_can('read_post', \$o['wt_hero_image_id'])"));
chk("Admin: wp_enqueue_media + pm-ats-admin.js", str_contains($adm, 'wp_enqueue_media();') && str_contains($adm, "assets/pm-ats-admin.js"));
chk("Selettore Libreria media (wp.media, solo immagini, URL+ID, Rimuovi)", str_contains($js, 'wp.media(') && str_contains($js, "type: 'image'") && str_contains($js, 'id.val(a.id)') && str_contains($adm, 'pm-ats-media-pick') && str_contains($adm, 'pm-ats-media-del'));
chk("Riferimenti di creazione (impostazioni + piè di pagina)", str_contains($adm, "Ideatore del plugin per WordPress: Antonello Orrù © 2026 · componente PortalManager_BI") && str_contains($adm, "add_filter('admin_footer_text'") && str_contains($adm, 'self::credits();'));
chk("Schema impostazioni 7, template 1.3.4", str_contains($main, "define('PM_ATS_SETTINGS_VERSION', '7')") && str_contains($main, "define('PM_ATS_TEMPLATE_VERSION', '1.3.4')") && (bool)preg_match('/@version\s+1\.3\.4/', $tpl));
chk("Plugin 1.3.4: header = costante = Stable tag", (bool)preg_match('/Version:\s*1\.3\.4/', $main) && str_contains($main, "define('PM_ATS_VERSION', '1.3.4')") && (bool)preg_match('/^Stable tag:\s*1\.3\.4\s*$/mi', $file("$pd/readme.txt")));
chk("CHANGELOG.md e readme 1.3.4", str_contains($file("$pd/CHANGELOG.md"), '## 1.3.4') && str_contains($file("$pd/readme.txt"), '= 1.3.4 ='));
chk("PortalManager: PLUGIN_RECOMMENDED = 1.3.4", str_contains($file('app/WpAtsConfig.php'), "PLUGIN_RECOMMENDED = '1.3.4'"));
chk("Invariati: titolo pagina 1.3.3, barra laterale 1.3.2, struttura 1-5", str_contains($pub, "'pageTitle'") && str_contains($pub, "'pm-ats-no-sidebar'") && str_contains($file("$pd/includes/class-pm-ats-jobs.php"), "'offriamo'      => ['Cosa offriamo'"));
$z = new ZipArchive();
chk("Pacchetto pm-ats-1.3.4.zip", $z->open("$root/integrations/wordpress/pm-ats-1.3.4.zip") === true && preg_match('/Version:\s*1\.3\.4/', (string)$z->getFromName('pm-ats/pm-ats.php')) === 1);
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
