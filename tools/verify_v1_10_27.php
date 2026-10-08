<?php
/**
 * tools/verify_v1_10_27.php — verifica della v1.10.27: File Manager (URL assoluti rispetto a <base href>, CSRF, PRG,
 * invio file a blocchi, visualizzazione sicura, cartelle protette).
 * Uso: php tools/verify_v1_10_27.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.27", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.27');
chk("VERSION = 1.10.27 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.27' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.27')"));
chk("pm_migration_sql contiene 1.10.27", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.27'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_27.md", is_file("$root/docs/{$d}_v1_10_27.md"));
$fm = $file('file_manager.php');
chk("Nessun link relativo «?…» (base href)", !preg_match('/href="\?/', $fm) && substr_count($fm, 'fm_url(') >= 10);
chk("CSRF verificato su ogni POST", str_contains($fm, "Csrf::verify();"));
chk("PRG: esito in sessione e redirect alla cartella", str_contains($fm, "function fm_done(") && str_contains($fm, "\$_SESSION['fm_flash']") && str_contains($fm, 'name="p" value="<?= $h($current_rel_dir) ?>"'));
chk("Download/ZIP/visualizza: buffer svuotati, invio a blocchi, nome RFC 5987", str_contains($fm, 'function fm_send_file(') && str_contains($fm, 'fread($fh, 1048576)') && str_contains($fm, "filename*=UTF-8") && !str_contains($fm, 'readfile($target)'));
chk("Visualizza: testo come text/plain con CSP sandbox", str_contains($fm, "Content-Security-Policy: sandbox") && !str_contains($fm, "'svg' => 'image/svg+xml'"));
chk("Rinomina/elimina con data-attribute (nessun addslashes in onclick)", !str_contains($fm, 'addslashes(') && str_contains($fm, 'onclick="renameItem(this.dataset.rel, this.dataset.name)"'));
chk("Cartelle di sistema protette, esito reale dell'eliminazione", str_contains($fm, "['app', 'assets', 'sql', 'uploads', 'vendor']") && str_contains($fm, 'function fm_delete('));
chk("Accesso solo Super Admin", str_contains($fm, 'if ($u_role !== 1)'));
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
