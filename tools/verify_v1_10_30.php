<?php
/**
 * tools/verify_v1_10_30.php — verifica della v1.10.30: filtro multi-select Unità Organizzativa su 8 pagine. Esegue le letture sul database indicato.
 * Uso: php tools/verify_v1_10_30.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
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

chk("app_settings.app_version = 1.10.30", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.30');
chk("VERSION = 1.10.30 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.30' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.30')"));
chk("pm_migration_sql contiene 1.10.30", (int)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version = '1.10.30'") === 1);
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_30.md", is_file("$root/docs/{$d}_v1_10_30.md"));
$pages = ['manage_projects.php', 'dir_report.php', 'service_desk.php', 'service_soc.php', 'it_service.php', 'dgb_activities.php', 'workload_overview.php', 'tech_report.php'];
$uf = $file('app/PmUoFilter.php');
chk("app/PmUoFilter.php presente (projectSql, empSql, dgbOperatorSql, field)", str_contains($uf, 'final class PmUoFilter') && str_contains($uf, 'function projectSql(') && str_contains($uf, 'function dgbOperatorSql(') && str_contains($uf, 'function field('));
foreach ($pages as $p) chk("Campo Unità Organizzativa in $p", str_contains($file($p), 'PmUoFilter::field('));
foreach (['ItServiceModel', 'SocModel', 'SdModel', 'DirModel', 'DgbModel', 'ProjectModel'] as $c) chk("Filtro UO applicato in app/$c.php", str_contains($file("app/$c.php"), 'PmUoFilter::'));
$errs = []; $info = [];
try {
    require_once "$root/app/PmUoFilter.php";
    $opts = PmUoFilter::options($pdo);
    $uid = (int)$one("SELECT unit_id FROM cm_tech_profiles WHERE is_active = 1 AND employee_id IS NOT NULL AND unit_id IS NOT NULL GROUP BY unit_id ORDER BY COUNT(*) DESC LIMIT 1");
    $sel = PmUoFilter::norm([$uid, 'x', -3, $uid]);
    $chkN = $sel === ($uid ? [$uid] : []);
    require_once "$root/app/ProjectModel.php";
    $pmo = new ProjectModel($pdo); $all = count($pmo->listAll([])); $flt = count($pmo->listAll(['uo' => $sel]));
    require_once "$root/app/ItServiceModel.php";
    $m = new ItServiceModel($pdo);
    $t0 = $m->tecniciLinea($m->normFilters(['contratti_set' => 1]));
    $t1 = $m->tecniciLinea($m->normFilters(['contratti_set' => 1, 'uo' => (string)$uid]));
    $okIt = (int)($t1['totale']['attivita'] ?? 0) <= (int)($t0['totale']['attivita'] ?? 0);
    require_once "$root/app/DirModel.php";
    $dm = new DirModel($pdo); $q0 = $dm->quadro($dm->normFilters(['solo' => 'tutte'])); $q1 = $dm->quadro($dm->normFilters(['solo' => 'tutte', 'uo' => [$uid]]));
    $okDir = (int)($q1['commesse'] ?? 0) <= (int)($q0['commesse'] ?? 0);
    require_once "$root/app/DgbModel.php";
    $dg = new DgbModel($pdo); $fd = DgbModel::normFilters(['uo' => (string)$uid]);
    $okDgb = $fd['uo'] === [$uid] && (DgbModel::query($fd)['uo'] ?? '') === (string)$uid && DgbModel::activeCount($fd) >= 1;
    $dg->kpi($fd);
    $empN = count(PmUoFilter::employeeIds($pdo, $sel));
    $info = [count($opts) . ' unità', "UO#$uid $empN dip.", "commesse $flt/$all", 'dir ' . (int)($q1['commesse'] ?? 0) . '/' . (int)($q0['commesse'] ?? 0)];
    $okL = $opts && $chkN && $flt <= $all && $okIt && $okDir && $okDgb && $empN > 0;
} catch (Throwable $e) { $errs[] = $e->getMessage(); $okL = false; }
chk("Letture eseguibili (opzioni, commesse, IT, direzionale, DGB)", $okL && !$errs, $errs ? implode(' | ', $errs) : implode(' · ', $info));
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
