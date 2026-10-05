<?php
/**
 * tools/verify_v1_9_99.php — verifica della v1.9.99 (schema e seed Progetti PRJ).
 * Uso: php tools/verify_v1_9_99.php --db=demo_portalmanager [--host=127.0.0.1 --user=root --pass=]
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
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { echo "✘ Connessione: " . $e->getMessage() . "\n"; exit(1); }

echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 62) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$v = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$P = "(SELECT id FROM cm_prj WHERE prj_code='PRJ-2026-0001')";

// versione
chk("pm_migration_sql contiene 1.9.99", (bool)$v("SELECT COUNT(*) FROM pm_migration_sql WHERE version='1.9.99'"));
foreach (['app_version', 'schema_version', 'release_label'] as $k)
    chk("app_settings.$k = 1.9.99", $v("SELECT setting_value FROM app_settings WHERE setting_key='$k'") === '1.9.99');

// schema
$tab = (int)$v("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'cm\\_prj%'");
chk("Tabelle cm_prj*", $tab === 38, "($tab / 38)");
$fk = $pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_prj_sp'")->fetchColumn();
chk("cm_prj.sp_project_id -> cm_projects ON DELETE SET NULL", $fk === 'SET NULL', "($fk)");
$run = $pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_prun_prj'")->fetchColumn();
chk("cm_prj_calc_run -> cm_prj ON DELETE RESTRICT (run immutabili)", $run === 'RESTRICT', "($run)");
try { $pdo->exec("INSERT INTO cm_prj (prj_code,nome) VALUES ('PRJ-26-1','x')"); chk("CHECK formato prj_code", false); }
catch (Throwable $e) { chk("CHECK formato prj_code (PRJ-AAAA-NNNN)", true); }

// seed
chk("PRJ-2026-0001 presente, non collegato", (int)$v("SELECT COUNT(*) FROM cm_prj WHERE prj_code='PRJ-2026-0001' AND sp_project_id IS NULL") === 1);
chk("Sequenza 2026 >= 1", (int)$v("SELECT last_no FROM cm_prj_sequence WHERE year=2026") >= 1);
$t = (int)$v("SELECT SUM(quantita) FROM cm_prj_ticket_volume WHERE prj_id=$P AND year=2025 AND is_current=1");
chk("Ticket 2025 = 15.971", $t === 15971, "($t)");
$h = (float)$v("SELECT SUM(tv.quantita*a.ore) FROM cm_prj_ticket_volume tv JOIN cm_prj_aht a ON a.prj_id=tv.prj_id AND a.tipo=tv.tipo AND a.service_id=0 AND a.is_current=1
                 WHERE tv.prj_id=$P AND tv.year=2025 AND tv.is_current=1");
chk("Ore da ticket = 55.579,5 h", abs($h - 55579.5) < 0.01, "(" . number_format($h, 1, ',', '.') . ")");
chk("FTE da ticket (senza uplift) = 34,7 ±0,1", abs($h / 1600 - 34.7) <= 0.1, "(" . number_format($h / 1600, 2, ',', '.') . ")");
$q = (int)$v("SELECT COUNT(*) FROM (SELECT group_id, SUM(quota) s FROM cm_prj_ticket_mapping WHERE prj_id=$P AND is_current=1 GROUP BY group_id HAVING ABS(s-1)>0.0001) x");
chk("Mapping gruppo->servizio: quote = 100% per gruppo", $q === 0, "($q gruppi fuori)");
$ob = (float)$v("SELECT SUM(sp.fte) FROM cm_prj_service_profile sp JOIN cm_prj_profile p ON p.id=sp.profile_id WHERE sp.prj_id=$P AND sp.is_current=1 AND p.tipo='obbligatorio'");
$to = (float)$v("SELECT SUM(fte) FROM cm_prj_service_profile WHERE prj_id=$P AND is_current=1");
chk("FTE profili obbligatori / totali = 26,5 / 55,1", abs($ob - 26.5) < 1e-6 && abs($to - 55.1) < 1e-6, "($ob / $to)");
$d = (float)$v("SELECT SUM(prezzo/anni_ammortamento) FROM cm_prj_equipment WHERE prj_key=0 AND is_current=1");
chk("Dotazione per FTE = 1.184 €", abs($d - 1184) < 1, "(" . number_format($d, 2, ',', '.') . ")");
$par = $pdo->query("SELECT chiave, valore FROM cm_prj_param WHERE prj_key=0 AND is_current=1")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($pdo->query("SELECT nome, affitto_mq_mese FROM cm_prj_zone WHERE prj_key=0 AND is_current=1 ORDER BY indice_ral DESC")->fetchAll(PDO::FETCH_KEY_PAIR) as $z => $aff) {
    $s = $d + $par['mq_postazione'] * $par['occupazione_pct'] * $aff * 12 * (1 + $par['oneri_accessori_pct']) + $par['kwh_postazione'] * $par['prezzo_kwh'];
    $att = ['Milano' => 3532, 'Roma' => 2985, 'Firenze' => 2646, 'Napoli' => 2402][$z] ?? null;
    chk("Strutturale per FTE ufficio $z = $att €", $att !== null && abs($s - $att) < 1, "(" . number_format($s, 1, ',', '.') . ")");
}
$c = (float)$v("SELECT AVG(canone_eur) FROM cm_prj_tender_base WHERE prj_id=$P AND is_current=1");
chk("Canone medio = 2.351,9 k€", abs($c / 1000 - 2351.9) < 0.05, "(" . number_format($c / 1000, 1, ',', '.') . ")");
chk("Criteri: totale punti = 100 (tecnica 70 + economica 30)", (float)$v("SELECT SUM(punti_max) FROM cm_prj_criterion WHERE prj_id=$P AND is_current=1") == 100.0);
chk("KPI = 24", (int)$v("SELECT COUNT(*) FROM cm_prj_kpi WHERE prj_id=$P AND is_current=1") === 24);
chk("Associazioni KPI-servizio = 235", (int)$v("SELECT COUNT(*) FROM cm_prj_service_kpi WHERE prj_id=$P") === 235);
chk("Scenari = 8, scenario di riferimento impostato", (int)$v("SELECT COUNT(*) FROM cm_prj_scenario WHERE prj_id=$P") === 8
    && (int)$v("SELECT COUNT(*) FROM cm_prj WHERE prj_code='PRJ-2026-0001' AND scenario_riferimento_id IS NOT NULL") === 1);
$noEnt = 0;
foreach (['cm_prj_gara','cm_prj_tender_base','cm_prj_rate_card','cm_prj_service','cm_prj_asset_metric','cm_prj_ticket_mapping','cm_prj_ticket_volume',
          'cm_prj_aht','cm_prj_productivity','cm_prj_profile_req','cm_prj_service_profile','cm_prj_salary_band','cm_prj_param','cm_prj_zone',
          'cm_prj_nearshore','cm_prj_equipment','cm_prj_site_cost','cm_prj_overhead','cm_prj_kpi','cm_prj_criterion'] as $tb)
    $noEnt += (int)$v("SELECT COUNT(*) FROM `$tb` WHERE ent_id IS NULL");
chk("Tabelle versionate: ent_id valorizzato", $noEnt === 0, "($noEnt righe senza ent_id)");
chk("Fasce RAL con zona base", (int)$v("SELECT COUNT(*) FROM cm_prj_salary_band WHERE prj_id=$P AND zona_base_id IS NULL") === 0);

// permessi
chk("Catalogo permessi: 7 voci PRJ", (int)$v("SELECT COUNT(*) FROM permissions WHERE name IN ('manage_projects_prj.php','prj_dashboard.php','prj_dashboard_calc.php','prj_link.php','prj_parameters.php','prj_history.php','prj_costs_real.php')") === 7);
chk("role_permissions Super Admin sulle pagine PRJ", (int)$v("SELECT COUNT(*) FROM role_permissions WHERE role_id=1 AND (page_name LIKE 'prj\\_%' OR page_name='manage_projects_prj.php')") === 7);

// file
$root = realpath(__DIR__ . '/..');
chk("VERSION = 1.9.99", trim((string)@file_get_contents("$root/VERSION")) === '1.9.99');
chk("app/Version.php PM_VERSION = 1.9.99", (bool)preg_match("/define\\('PM_VERSION', '1\\.9\\.99'\\)/", (string)@file_get_contents("$root/app/Version.php")));
chk("Router::PAGES contiene prj_dashboard", str_contains((string)@file_get_contents("$root/app/Router.php"), "'prj_dashboard'"));
chk("merge_employees: whitelist cm_prj_profile_assignment", str_contains((string)@file_get_contents("$root/merge_employees.php"), "'cm_prj_profile_assignment'"));

echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
