<?php
/**
 * tools/verify_v1_10_01.php — verifica della v1.10.01 (interfaccia Progetti PRJ, collegamento, parametri).
 * Uso: php tools/verify_v1_10_01.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
 * Le prove di scrittura girano in una transazione annullata: nessun dato resta nel database.
 */
declare(strict_types=1);
$args = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
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
require_once $root . '/app/PrjRepo.php';
require_once $root . '/app/PrjLink.php';
require_once $root . '/app/PrjUi.php';
if (!function_exists('h')) { function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 64) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");

// versione
chk("pm_migration_sql contiene 1.10.01", (bool)$pdo->query("SELECT COUNT(*) FROM pm_migration_sql WHERE version='1.10.01'")->fetchColumn());
chk("app_settings.app_version = 1.10.01", $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version'")->fetchColumn() === '1.10.01');
chk("VERSION = 1.10.01", trim($file('VERSION')) === '1.10.01');
chk("PM_VERSION = 1.10.01", (bool)preg_match("/define\\('PM_VERSION', '1\\.10\\.01'\\)/", $file('app/Version.php')));

// file e integrazioni
foreach (['prj_dashboard.php', 'prj_parameters.php', 'api_prj.php', 'app/prj_list.php', 'app/PrjLink.php', 'app/PrjUi.php', 'app/PrjRepo.php', 'app/PrjCalc.php'] as $f)
    chk("File $f presente", is_file("$root/$f"));
chk("manage_projects.php: vista ?view=prj", str_contains($file('manage_projects.php'), "app/prj_list.php"));
chk("MenuManager: voce Parametri dimensionamento", str_contains($file('app/MenuManager.php'), "'page' => 'prj_parameters'"));
chk("Router::PAGES: prj_dashboard e prj_parameters", str_contains($file('app/Router.php'), "'prj_dashboard', 'prj_parameters'"));
chk("access_control: api_prj.php ad accesso diretto", str_contains($file('access_control.php'), "'api_prj.php'"));
chk("PermissionCatalog: 7 voci PRJ", substr_count($file('app/PermissionCatalog.php'), "prj") >= 7 && str_contains($file('app/PermissionCatalog.php'), "'prj_link.php'"));
chk("CommesseSync: controllo PRJ dopo la sincronizzazione", str_contains($file('app/CommesseSync.php'), 'afterSync'));
chk("Permessi ruolo su prj_parameters.php", (int)$pdo->query("SELECT COUNT(*) FROM role_permissions WHERE page_name='prj_parameters.php'")->fetchColumn() >= 1);

// funzioni (transazione annullata)
$repo = new PrjRepo($pdo); $lnk = new PrjLink($pdo);
$src = (int)$pdo->query("SELECT id FROM cm_prj WHERE prj_code='PRJ-2026-0001'")->fetchColumn();
$pdo->beginTransaction();
try {
    $n = $repo->createPrj(['nome' => 'Verifica v1.10.01', 'client_id' => null, 'client_raw' => '', 'exec_company_id' => null, 'project_type' => null, 'stato' => 'Bozza',
                           'responsabile_user_id' => null, 'codice_gara' => '', 'cig' => '', 'stazione_appaltante' => '', 'data_offerta' => null, 'start_date' => null, 'end_date' => null, 'note' => ''], null);
    $c = $pdo->query("SELECT prj_code FROM cm_prj WHERE id=$n")->fetchColumn();
    chk("Nuovo PRJ: codice generato, gara e produttività iniziali", (bool)preg_match('/^PRJ-\d{4}-\d{4}$/', (string)$c)
        && (int)$pdo->query("SELECT COUNT(*) FROM cm_prj_productivity WHERE prj_id=$n AND is_current=1")->fetchColumn() === 1, "($c)");
    if ($src) {
        $sc = (int)$pdo->query("SELECT scenario_riferimento_id FROM cm_prj WHERE id=$src")->fetchColumn();
        $cl = $repo->clonePrj($src, null, 'Clone verifica');
        $sc2 = (int)$pdo->query("SELECT scenario_riferimento_id FROM cm_prj WHERE id=$cl")->fetchColumn();
        $a = $repo->calc($src, $sc, date('Y-m-d'))['totali']; $b = $repo->calc($cl, $sc2, date('Y-m-d'))['totali'];
        chk("Clonazione: stesso risultato dello scenario di riferimento", abs($a['costo_aziendale_totale'] - $b['costo_aziendale_totale']) < 0.01 && abs($a['ticket_anno'] - $b['ticket_anno']) < 0.01,
            '(' . PrjUi::k($a['costo_aziendale_totale']) . ' = ' . PrjUi::k($b['costo_aziendale_totale']) . ')');
        chk("Clonazione: stato Bozza, non collegato", (int)$pdo->query("SELECT COUNT(*) FROM cm_prj WHERE id=$cl AND stato='Bozza' AND sp_project_id IS NULL")->fetchColumn() === 1);
        // griglia versionata: nuova versione e rettifica
        $tb = $pdo->query("SELECT id, valid_from FROM cm_prj_tender_base WHERE prj_id=$cl AND is_current=1 ORDER BY year LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $r1 = PrjUi::saveGrid($pdo, $repo, $cl, ['valid_from' => $tb['valid_from'], 'v' => ['cm_prj_tender_base' => [$tb['id'] => ['canone_eur' => '2.000.000,50']]]], null);
        $v1 = $pdo->query("SELECT COUNT(*) c, MAX(canone_eur) m FROM cm_prj_tender_base WHERE prj_id=$cl AND ent_id=(SELECT ent_id FROM cm_prj_tender_base WHERE id={$tb['id']})")->fetch(PDO::FETCH_ASSOC);
        chk("Griglia: rettifica con stessa decorrenza (nessuna nuova versione)", $r1['saved'] === 1 && (int)$v1['c'] === 1 && abs((float)$v1['m'] - 2000000.5) < 0.001, '(' . $v1['m'] . ')');
        $r2 = PrjUi::saveGrid($pdo, $repo, $cl, ['valid_from' => '2099-01-01', 'v' => ['cm_prj_tender_base' => [$tb['id'] => ['canone_eur' => '1000']]]], null);
        $v2 = (int)$pdo->query("SELECT COUNT(*) FROM cm_prj_tender_base WHERE prj_id=$cl AND ent_id=(SELECT ent_id FROM cm_prj_tender_base WHERE id={$tb['id']})")->fetchColumn();
        chk("Griglia: nuova versione con decorrenza successiva", $r2['saved'] === 1 && $v2 === 2);
        $r3 = PrjUi::saveGrid($pdo, $repo, $n, ['valid_from' => '2099-01-01', 'v' => ['cm_prj_tender_base' => [$tb['id'] => ['canone_eur' => '1']]]], null);
        chk("Griglia: riga di altro progetto rifiutata", $r3['saved'] === 0 && count($r3['errors']) === 1);
        $r4 = PrjUi::saveGrid($pdo, $repo, $cl, ['valid_from' => '2099-01-02', 'v' => ['cm_prj_service' => [1 => ['codice' => 'X']], 'cm_projects' => [1 => ['name' => 'x']]]], null);
        chk("Griglia: campi e tabelle fuori whitelist ignorati", $r4['saved'] === 0);
        // collegamento
        $sp = (int)$pdo->query("SELECT id FROM cm_projects WHERE project_code NOT LIKE 'DGB-%' ORDER BY id LIMIT 1")->fetchColumn();
        $code = (string)$pdo->query("SELECT project_code FROM cm_projects WHERE id=$sp")->fetchColumn();
        chk("Ricerca commesse SP per codice", (bool)array_filter($lnk->search($code), fn($r) => (int)$r['id'] === $sp), "($code)");
        $dgb = (int)$pdo->query("SELECT id FROM cm_projects WHERE project_code LIKE 'DGB-%' LIMIT 1")->fetchColumn();
        if ($dgb) { try { $lnk->link($cl, $dgb, 'test', null); chk("Segnaposto DGB- non collegabile", false); } catch (Throwable $e) { chk("Segnaposto DGB- non collegabile", true); } }
        $lnk->link($cl, $sp, 'verifica', null);
        $lnk->unlink($cl, 'verifica', null);
        $h = array_column($lnk->historyOf($cl), 'azione');
        chk("Collega e scollega: storico", $h === ['scollegato', 'collegato'], '(' . implode(', ', $h) . ')');
        $before = $pdo->query("SELECT COUNT(*) FROM cm_projects")->fetchColumn();
        $pdo->exec("UPDATE cm_projects SET commercial_ref = CONCAT(COALESCE(commercial_ref,''), ' " . $pdo->query("SELECT prj_code FROM cm_prj WHERE id=$cl")->fetchColumn() . "') WHERE id=$sp");
        $res = $lnk->afterSync(null);
        chk("Dopo sincronizzazione: collegamento da commercial_ref", $res['collegati'] >= 1 && (int)$pdo->query("SELECT sp_project_id FROM cm_prj WHERE id=$cl")->fetchColumn() === $sp);
        chk("cm_projects mai scritta dal modulo (conteggio invariato)", $pdo->query("SELECT COUNT(*) FROM cm_projects")->fetchColumn() === $before);
    }
} catch (Throwable $e) { chk("Prove funzionali", false, $e->getMessage()); }
$pdo->rollBack();

echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
