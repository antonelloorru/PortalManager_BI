<?php
/**
 * tools/verify_v1_10_31.php — verifica della v1.10.31: Link SP in Ordinativi Pratix e Scheda Commessa. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_31.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.31", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.31');
chk("VERSION = 1.10.31 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.31' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.31')"));
chk("pm_migration_sql contiene 1.10.31", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.31'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_31.md", is_file("$root/docs/{$d}_v1_10_31.md"));
$px = $file('pratix_orders.php'); $pdb = $file('project_dashboard.php');
chk("Ordinativi Pratix: query righe con external_link AS link_sp", str_contains($px, 'pj.`external_link` AS link_sp') && str_contains($px, 'LEFT JOIN `cm_projects` pj ON pj.`id` = r.`commessa_id`'));
chk("Ordinativi Pratix: Link SP a fianco del link Commessa", strpos($px, 'title="Apri la commessa"') < strpos($px, "\$r['link_sp'] ?? ''") && str_contains($px, 'title="Apri sul gestionale (SharePoint)"'));
chk("Ordinativi Pratix: colonna Link SP nell'export", str_contains($px, "\$rigHead = ['Ordinativo','Commessa','Link SP',") && str_contains($px, "\$x['commessa'], (string)(\$x['link_sp'] ?? '')"));
chk("Scheda Commessa: Link SP a fianco del Codice Commessa", (bool)preg_match("~h\(\\\$p\['project_code'\]\)\?>\s*\n\s*<\?php // v1\.10\.31.*?\\\$p\['external_link'\].*?Link SP~s", $pdb));
chk("Solo URL http(s) cliccabili", substr_count($px . $pdb, "preg_match('~^https?://~i'") >= 2 && str_contains($px, 'noopener noreferrer') && str_contains($pdb, 'noopener noreferrer'));
$errs = [];
try {
    $n = (int)$one("SELECT COUNT(*) FROM v_cm_pratix_righe");
    $l = (int)$one("SELECT COUNT(*) FROM v_cm_pratix_righe r LEFT JOIN cm_projects pj ON pj.id = r.commessa_id");
    $s = (int)$one("SELECT COUNT(*) FROM v_cm_pratix_righe r JOIN cm_projects pj ON pj.id = r.commessa_id WHERE pj.external_link LIKE 'http%'");
    $okL = $n === $l;
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture eseguibili (join senza duplicazione righe)", $okL && !$errs, $errs ? implode(' | ', $errs) : "$n righe · $s con Link SP");
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
