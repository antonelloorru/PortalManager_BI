<?php
/**
 * tools/verify_v1_10_19.php — verifica della v1.10.19 / plugin pm-ats 1.3.0:
 *   A) struttura vincolante della Job Description (Chi siamo, Informazioni sull'offerta, Competenze, Costituisce titolo
 *      preferenziale, Cosa offriamo) identica in plugin e PortalManager; nota interna «description» mai inviata;
 *   B) layout di riferimento «Lavora con noi» (template jobs-accordion.php, pm-ats-wetechs.css, modulo con scelta posizione).
 * Uso: php tools/verify_v1_10_19.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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
require_once "$root/app/WpAtsSync.php";
require_once "$root/app/WpAtsPreview.php";
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

chk("app_settings.app_version = 1.10.19", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.19');
chk("VERSION = 1.10.19 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.19' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.19')"));
chk("pm_migration_sql contiene 1.10.19", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.19'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_19.md", is_file("$root/docs/{$d}_v1_10_19.md"));

echo "\n— A. Struttura vincolante della Job Description\n";
$expect = ['Chi siamo', "Informazioni sull'offerta", 'Competenze', 'Costituisce titolo preferenziale', 'Cosa offriamo'];
chk("PortalManager: ordine 1-5", array_column(WpAtsPreview::STRUCTURE, 0) === $expect);
$j = $file("$pd/includes/class-pm-ats-jobs.php");
preg_match_all("/=> \\['([^']+(?:\\\\'[^']+)?)', \\[/", substr($j, (int)strpos($j, 'public const STRUCTURE'), 900), $m);
chk("Plugin: ordine 1-5 identico", array_map(fn($x) => str_replace("\\'", "'", $x), $m[1]) === $expect, '(' . implode(' · ', array_map(fn($x) => str_replace("\\'", "'", $x), $m[1])) . ')');
$full = ['title' => 'T', 'presentation_text' => 'p', 'offer_info' => 'o', 'required_skills' => 'r', 'hard_skills' => 'h', 'soft_skills' => 's', 'nice_to_have' => 'n', 'we_offer' => 'w', 'benefits' => 'b', 'description' => 'NOTA INTERNA', 'gender_disclaimer' => 'g'];
chk("Sezioni nell'ordine, sottotitoli di Competenze e Cosa offriamo", array_column(WpAtsPreview::sections($full), 'n') === [1, 2, 3, 4, 5] && count(WpAtsPreview::sections($full)[2]['parts']) === 3 && count(WpAtsPreview::sections($full)[4]['parts']) === 2);
chk("Sezioni vuote omesse, numerazione stabile", array_column(WpAtsPreview::sections(['presentation_text' => 'p', 'nice_to_have' => 'n']), 'n') === [1, 4]);
$h = WpAtsPreview::html($full, 'x');
chk("Anteprima locale: ordine 1-5 e nota pari opportunità in chiusura", preg_match_all('/data-pm-ats-section="(\d)"/', $h, $mm) === 5 && $mm[1] === ['1', '2', '3', '4', '5'] && str_contains($h, 'pm-ats-section-closing'));
chk("Nota interna «description» mai mostrata (anteprima locale)", !str_contains($h, 'NOTA INTERNA'));
$it = WpAtsSync::item(['id' => 1, 'title' => 'x', 'positions_expected' => 1, 'description' => 'NOTA INTERNA', 'web_status' => 'publish']);
chk("Nota interna «description» mai inviata al sito", $it['description'] === '');
chk("Plugin: description non conservata (normalize)", str_contains($j, "\$d['description'] = '';"));
chk("Plugin: scheda, fisarmonica, anteprima, JSON-LD dalla stessa sorgente", str_contains($file("$pd/templates/job-single.php"), 'PM_ATS_Jobs::sectionsHtml(') && str_contains($file("$pd/templates/jobs-accordion.php"), 'PM_ATS_Jobs::sectionsHtml(') && str_contains($j, 'foreach (self::sections($d) as $s)'));

echo "\n— B. Layout di riferimento «Lavora con noi»\n";
$css = $file("$pd/assets/pm-ats-wetechs.css");
foreach (['#234d85' => 'titoli', '#ec7f31' => 'accento', '#00457a' => 'gradiente testata', '#f4f4f4' => 'voce fisarmonica', '#d9d9d9' => 'bordo voce', '#d06a27' => 'bordo/hover modulo', 'font-size:48px' => 'titolo 48px', 'font-size:60px' => 'testata 60px', 'border-radius:10px' => 'raggio 10px'] as $k => $l)
    chk("CSS di riferimento: $l ($k)", str_contains($css, $k));
chk("Responsive 980 / 767 px", str_contains($css, '@media (max-width:980px)') && str_contains($css, '@media (max-width:767px)'));
chk("Nessun font esterno caricato", !preg_match('~fonts\.googleapis|@import|@font-face~i', $css));
$t = $file("$pd/templates/jobs-accordion.php");
chk("Template fisarmonica: testata, titolo con evidenza, elenco, modulo a lato, aria-expanded", str_contains($t, 'pm-ats-wt-hero') && str_contains($t, 'pm-ats-wt-accent') && str_contains($t, 'aria-expanded') && str_contains($t, 'pm-ats-wt-col-form'));
chk("Modulo: «Posizione per cui ti candidi» con preselezione", str_contains($file("$pd/templates/apply-form.php"), 'data-pm-ats-job') && str_contains($file("$pd/assets/pm-ats.js"), 'data-pm-ats-apply'));
chk("Impostazioni: layout accordion, colori, testata, titoli", str_contains($file("$pd/includes/class-pm-ats-settings.php"), "'grid', 'list', 'accordion'") && str_contains($file("$pd/includes/class-pm-ats-settings.php"), "'wt_hero_image'"));
$main = $file("$pd/pm-ats.php");
chk("Plugin 1.3.0: header = costante = Stable tag; template 1.3.0; impostazioni schema 3", (bool)preg_match('/Version:\s*1\.3\.0/', $main) && str_contains($main, "define('PM_ATS_VERSION', '1.3.0')") && str_contains($main, "define('PM_ATS_TEMPLATE_VERSION', '1.3.0')") && str_contains($main, "define('PM_ATS_SETTINGS_VERSION', '3')") && (bool)preg_match('/^Stable tag:\s*1\.3\.0\s*$/mi', $file("$pd/readme.txt")));
foreach (['job-single.php', 'apply-form.php', 'jobs-accordion.php'] as $tp) chk("Template $tp @version 1.3.0", (bool)preg_match('/@version\s+1\.3\.0/', $file("$pd/templates/$tp")));
$z = new ZipArchive();
chk("Pacchetto pm-ats-1.3.0.zip", $z->open("$root/integrations/wordpress/pm-ats-1.3.0.zip") === true && $z->locateName('pm-ats/templates/jobs-accordion.php') !== false && $z->locateName('pm-ats/assets/pm-ats-wetechs.css') !== false);
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
