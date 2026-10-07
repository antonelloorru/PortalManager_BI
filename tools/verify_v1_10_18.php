<?php
/**
 * tools/verify_v1_10_18.php — verifica della v1.10.18: pubblicazione puntuale per posizione (job_positions.web_status) e
 * anteprima della scheda annuncio (plugin pm-ats 1.2.0: POST /sync/preview, ?pm_ats_preview=<token>; anteprima locale di riserva).
 * Uso: php tools/verify_v1_10_18.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--online]
 *   --online: anteprima dal sito della prima posizione aperta (nessuna modifica di stato sul sito).
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
require_once "$root/app/WpAtsSync.php";
require_once "$root/app/WpAtsPreview.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 78) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

chk("app_settings.app_version = 1.10.18", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.18');
chk("VERSION = 1.10.18 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.18' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.18')"));
chk("pm_migration_sql contiene 1.10.18", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.18'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_18.md", is_file("$root/docs/{$d}_v1_10_18.md"));

echo "\n— PortalManager\n";
$col = $pdo->query("SHOW COLUMNS FROM job_positions LIKE 'web_status'")->fetch(PDO::FETCH_ASSOC);
chk("job_positions.web_status ENUM(publish,draft,off) predefinito publish", $col && str_contains((string)$col['Type'], "'publish','draft','off'") && $col['Default'] === 'publish');
chk("job_positions.web_status_at / web_status_by", (bool)$pdo->query("SHOW COLUMNS FROM job_positions LIKE 'web_status_at'")->fetch() && (bool)$pdo->query("SHOW COLUMNS FROM job_positions LIKE 'web_status_by'")->fetch());
$sync = new WpAtsSync($pdo, null);
$items = $sync->openPositions();
$offIds = array_map('intval', $pdo->query("SELECT id FROM job_positions WHERE web_status = 'off'")->fetchAll(PDO::FETCH_COLUMN));
chk("Invio: escluse le posizioni «Non pubblicare», web_status nelle altre", !array_intersect(array_column($items, 'id'), $offIds) && !array_filter($items, fn($i) => !in_array($i['web_status'], ['publish', 'draft'], true)), '(' . count($items) . ' inviabili, ' . count($offIds) . ' off)');
$any = (int)$one("SELECT id FROM job_positions ORDER BY id LIMIT 1");
$it = $sync->positionItem($any);
chk("Anteprima: item di qualsiasi posizione (anche non aperta)", $it !== null && $it['id'] === $any && str_starts_with($it['code'], 'POS-'));
$html = WpAtsPreview::html($it, 'test');
chk("Anteprima locale: barra, titolo, sezioni, stile del plugin, modulo disattivato", str_contains($html, 'ANTEPRIMA LOCALE') && str_contains($html, '.pm-ats') && str_contains($html, 'invio è disattivato'));
chk("Formattazione testo come il plugin (elenchi)", WpAtsPreview::format("Intro\n- uno\n- due") === '<p>Intro</p><ul><li>uno</li><li>due</li></ul>');
$s = $file('wp_ats_sync.php');
chk("wp_ats_sync.php: stato per posizione (edit) + invio puntuale + anteprima (view)", str_contains($s, "\$act === 'web_status' && \$canRun") && str_contains($s, 'pushOne(') && str_contains($s, "isset(\$_GET['preview'])"));
chk("Anteprima: redirect solo verso il sito configurato", str_contains($s, "strcasecmp((string)parse_url(\$r['url'], PHP_URL_HOST), \$base) === 0"));
chk("publish_posizione.php: scheda Sito web con stato e anteprima", str_contains($file('publish_posizione.php'), "'wp_ats_sync', ['preview' => (int)\$pos_id]"));
chk("WpAtsSync: invio delta della sola posizione, registro pubblicazioni draft/published", str_contains($file('app/WpAtsSync.php'), "'mode' => 'delta'") && str_contains($file('app/WpAtsSync.php'), "=== 'draft' ? 'draft' : 'published'"));
chk("Compatibilità: plugin consigliato 1.2.0", WpAtsConfig::PLUGIN_RECOMMENDED === '1.2.0' && WpAtsConfig::compat(['plugin' => '1.1.1', 'api' => '1'])['level'] === 'warn');

echo "\n— Plugin pm-ats 1.2.0\n";
$pd = 'integrations/wordpress/pm-ats';
$main = $file("$pd/pm-ats.php");
chk("Header Version = PM_ATS_VERSION = Stable tag = 1.2.0", (bool)preg_match('/Version:\s*1\.2\.0/', $main) && str_contains($main, "define('PM_ATS_VERSION', '1.2.0')") && (bool)preg_match('/^Stable tag:\s*1\.2\.0\s*$/mi', $file("$pd/readme.txt")));
$j = $file("$pd/includes/class-pm-ats-jobs.php");
chk("sync: web_status draft → post in bozza, _pm_web_status, ritiro anche delle bozze", str_contains($j, "'post_status' => \$want") && str_contains($j, "'_pm_web_status', 'withdrawn'") && str_contains($j, "w.meta_value = 'draft'"));
chk("Anteprima: token casuale, transient 30 min, nessun contenuto creato", str_contains($j, 'bin2hex(random_bytes(24))') && str_contains($j, 'PREVIEW_TTL = 1800'));
chk("REST POST /sync/preview firmata", str_contains($file("$pd/includes/class-pm-ats-rest.php"), "'/sync/preview', ['methods' => 'POST', 'callback' => [self::class, 'preview'], 'permission_callback' => \$auth]"));
$pu = $file("$pd/includes/class-pm-ats-public.php");
chk("Pagina di anteprima: noindex, modulo disattivato, 410 se scaduta", str_contains($pu, 'X-Robots-Tag: noindex') && str_contains($pu, 'invio è disattivato') && str_contains($pu, '410'));
chk("Bozza: nessun reindirizzo «posizione chiusa»", str_contains($pu, "get_post_meta(\$p->ID, '_pm_web_status', true) !== 'draft'"));
$z = new ZipArchive();
chk("Pacchetto pm-ats-1.2.0.zip", $z->open("$root/integrations/wordpress/pm-ats-1.2.0.zip") === true && preg_match('/Version:\s*1\.2\.0/', (string)$z->getFromName('pm-ats/pm-ats.php')) === 1);

if (isset($args['online'])) {
    echo "\n— In linea\n";
    $s2 = new WpAtsSync($pdo);
    $first = $items[0]['id'] ?? $any;
    $p = $s2->preview((int)$first);
    chk("Anteprima dal sito POS-$first", $p['ok'], $p['ok'] ? $p['url'] : $p['message']);
}
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
