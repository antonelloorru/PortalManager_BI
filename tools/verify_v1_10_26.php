<?php
/**
 * tools/verify_v1_10_26.php — verifica della v1.10.26: tendina di ricerca flottante (pm-multiselect) e un solo componente filtro
 * nelle pagine con il filtro globale (nessun riquadro contratto esterno, nessun form di filtro secondario).
 * Uso: php tools/verify_v1_10_26.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.26", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.26');
chk("VERSION = 1.10.26 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.26' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.26')"));
chk("pm_migration_sql contiene 1.10.26", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.26'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_26.md", is_file("$root/docs/{$d}_v1_10_26.md"));
$js = $file('assets/js/pm-multiselect.js'); $css = $file('assets/css/pm-multiselect.css');
chk("Tendina in <body> con position:fixed (pm-ms-floating)", str_contains($js, 'document.body.appendChild(dropdown)') && str_contains($js, "NS + '-floating'") && str_contains($css, '.pm-ms-dropdown.pm-ms-floating') && str_contains($css, 'position: fixed'));
chk("Riposizionamento su scroll/resize e chiusura fuori schermo", str_contains($js, "window.addEventListener('scroll', place, true)") && str_contains($js, "window.removeEventListener('scroll', place, true)") && str_contains($js, 'r.bottom < 0 || r.top > vh'));
chk("Altezza della lista sullo spazio disponibile (96..300 px)", str_contains($js, 'Math.max(96, Math.min(300, avail - head))'));
chk("Scorrimento lista confinato, z-index sopra sticky", str_contains($css, 'overscroll-behavior: contain') && str_contains($css, 'z-index: 10050') && !str_contains($css, 'contain: layout paint'));
chk("Tastiera e clic esterno con la tendina flottante", str_contains($js, "dropdown.addEventListener('keydown', onKey)") && str_contains($js, 'openInstance.owns(ev.target)'));
foreach (['it_service.php', 'tech_report.php', 'service_desk.php', 'service_soc.php', 'dir_report.php', 'dgb_activities.php'] as $pgf) {
    $src = $file($pgf);
    chk("$pgf: nessun riquadro filtro contratto esterno, un solo form GET", !str_contains($src, 'PmContractFilter::banner(') && substr_count($src, '<form method="get"') === 1);
}
chk("Relazione Tecnici: dettaglio moduli nel pannello, tipologie non link-filtro", str_contains($file('tech_report.php'), 'name="det" value="1"') && !str_contains($file('tech_report.php'), "\$qs(['tipologie' => \$x['tipologia']])"));
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
