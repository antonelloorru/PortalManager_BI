<?php
/**
 * tools/verify_v1_10_29.php — verifica della v1.10.29: Descrizione tariffa (Relazione Tecnici colonna e filtro, Scheda Progetto
 * Consuntivo) e filtro Tipo multiplo in Commesse / Progetti. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_29.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.29", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.29');
chk("VERSION = 1.10.29 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.29' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.29')"));
chk("pm_migration_sql contiene 1.10.29", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.29'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_29.md", is_file("$root/docs/{$d}_v1_10_29.md"));
$tr = $file('app/TechReport.php'); $pg = $file('tech_report.php'); $im = $file('app/ItServiceModel.php');
$mp = $file('manage_projects.php'); $pm = $file('app/ProjectModel.php'); $pd = $file('project_dashboard.php');
require_once "$root/app/TechReport.php";
chk("Relazione Tecnici: colonna Descrizione tariffa al posto di Fascia di costo", TechReport::H_MAIN[count(TechReport::H_MAIN) - 1] === 'Descrizione tariffa' && !in_array('Fascia di costo', TechReport::H_MAIN, true) && str_contains($tr, "\$r['descrizione_tariffa']"));
chk("Relazione Tecnici: filtro Descrizione tariffa", str_contains($pg, 'name="tariffe[]"') && str_contains($im, "'tariffe'   => array_slice(") && str_contains($im, 'private function tariffaFiltro('));
chk("Formula unica con collazione allineata", str_contains($im, "public static function tariffaExpr(") && str_contains($im, 'COLLATE utf8mb4_unicode_ci'));
chk("Commesse / Progetti: Tipo multiplo", str_contains($mp, 'name="sl[]" multiple') && str_contains($pm, 'p.service_line IN ('));
chk("Scheda Progetto › Consuntivo: Descrizione tariffa", str_contains($pd, '>Descrizione tariffa</th>') && str_contains($pd, 'tariffePerModuli(') && !str_contains($pd, '<th>Tecnico</th><th>Fascia</th>'));
$errs = [];
try {
    $m = new ItServiceModel($pdo); $f = $m->normFilters(['contratti_set' => 1]);
    $vals = $m->valoriTariffe();
    $tl = $m->tecniciLinea($f);
    $f2 = $m->normFilters(['contratti_set' => 1, 'tariffe' => $vals[0] ?? 'Fascia C (Ora)']); $tl2 = $m->tecniciLinea($f2);
    $ids = $pdo->query("SELECT id FROM cm_intervention_reports ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    $tp = $m->tariffePerModuli($ids);
    require_once "$root/app/ProjectModel.php";
    $pmo = new ProjectModel($pdo); $sl = $pdo->query("SELECT service_line FROM cm_projects WHERE service_line <> '' GROUP BY service_line LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $lst = $pmo->listAll(['service_line' => $sl]); $okT = !array_filter($lst, fn($r) => !in_array($r['service_line'], $sl, true));
    $okL = count($vals) > 0 && (!$tl['righe'] || array_key_exists('descrizione_tariffa', $tl['righe'][0])) && (int)($tl2['totale']['attivita'] ?? 0) <= (int)($tl['totale']['attivita'] ?? 0) && $okT;
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture eseguibili (valori, colonna, filtro, consuntivo, Tipo multiplo)", $okL && !$errs, $errs ? implode(' | ', $errs) : count($vals) . ' tariffe');
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
