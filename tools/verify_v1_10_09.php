<?php
/**
 * tools/verify_v1_10_09.php — verifica della v1.10.09: ticket del Service SOC associati agli incaricati del team SOC
 * anche con la sola sorgente DB (incaricato dedotto dai messaggi, operatori abbinati come autori, colonna «Seguiti»).
 * Uso: php tools/verify_v1_10_09.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--rebuild=1]
 * --rebuild=1 ricostruisce subito i ticket e gli abbinamenti (operazione idempotente della pipeline).
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
if (!defined('APP_BASE')) define('APP_BASE', $root);
foreach (['PmContractFilter', 'SocIngest', 'SocModel', 'SocSync'] as $c) require_once "$root/app/$c.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 72) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

chk("app_settings.app_version = 1.10.09", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.09');
chk("VERSION = 1.10.09 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.09' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.09')"));
chk("pm_migration_sql contiene 1.10.07, 1.10.08 e 1.10.09", (int)$one("SELECT COUNT(DISTINCT version) FROM pm_migration_sql WHERE version IN ('1.10.07','1.10.08','1.10.09')") === 3);
chk("Colonna cm_soc_tickets.assignee_source", (bool)$one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cm_soc_tickets' AND COLUMN_NAME='assignee_source'"));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_09.md", is_file("$root/docs/{$d}_v1_10_09.md"));
$ing = $file('app/SocIngest.php'); $mod = $file('app/SocModel.php');
chk("Ricostruzione: incaricato dedotto dal primo operatore", str_contains($ing, "t.assignee_source = 'dedotto'") && str_contains($ing, "event_kind IN ('supporto', 'nota')"));
chk("Abbinamento persone anche sugli autori di risposte e note", str_contains($ing, "UNION SELECT author_name FROM cm_soc_events WHERE event_kind IN ('supporto', 'nota')"));
chk("Team: colonne Incaricato (dedotti) e Seguiti", str_contains($mod, "dedotti") && str_contains($mod, "seguiti") && str_contains($file('service_soc.php'), 'Seguiti'));

if (($args['rebuild'] ?? '') === '1') {
    $i = new SocIngest($pdo); $n = $i->rebuild(); $i->autoMap(); SocSync::assignUnit($pdo);
    echo "  ricostruiti $n ticket\n";
}
$tot = (int)$one("SELECT COUNT(*) FROM cm_soc_tickets");
if ($tot) {
    $noInc = (int)$one("SELECT COUNT(*) FROM cm_soc_tickets WHERE COALESCE(assignee_name, '') = ''");
    $noOp  = (int)$one("SELECT COUNT(*) FROM cm_soc_tickets t WHERE COALESCE(t.assignee_name, '') = '' AND NOT EXISTS (SELECT 1 FROM cm_soc_events e WHERE e.ticket_code = t.ticket_code AND e.event_kind IN ('supporto','nota'))");
    chk("Ticket senza incaricato solo se nessun operatore ha risposto", $noInc === $noOp, "($noInc senza incaricato su $tot)");
    $src = $pdo->query("SELECT COALESCE(assignee_source, '-') s, COUNT(*) n FROM cm_soc_tickets GROUP BY s")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "  origine incaricato: " . json_encode($src) . "\n";
    $authors = (int)$one("SELECT COUNT(DISTINCT author_name) FROM cm_soc_events WHERE event_kind IN ('supporto','nota') AND COALESCE(author_name,'') <> ''");
    $inPeople = (int)$one("SELECT COUNT(DISTINCT e.author_name) FROM cm_soc_events e JOIN cm_soc_people p ON p.name = e.author_name WHERE e.event_kind IN ('supporto','nota')");
    chk("Operatori (autori) presenti negli abbinamenti", $authors === $inPeople, "($inPeople su $authors)");
    $uo = SocSync::unitId($pdo);
    $m = new SocModel($pdo); $f = $m->normFilters(['from' => '2000-01-01', 'to' => date('Y-m-d')]);
    $team = $m->team($f);
    $socWith = array_filter($team, fn($r) => !empty($r['in_uo_soc']) && empty($r['senza_ticket']) && ((int)$r['ticket'] + (int)$r['seguiti']) > 0);
    chk("Componenti dell'unità SOC con ticket associati", count($socWith) > 0, '(' . implode(', ', array_map(fn($r) => $r['dipendente'] . ' ' . $r['ticket'] . '/' . $r['seguiti'], $socWith)) . ')');
    $mapped = (int)$one("SELECT COUNT(*) FROM cm_soc_tickets t JOIN cm_tech_profiles tp ON tp.employee_id = t.assignee_employee_id WHERE tp.unit_id = $uo");
    chk("Ticket con incaricato nell'unità SOC", $mapped > 0, "($mapped su $tot)");
} else chk("Archivio ticket SOC presente", false, 'eseguire la pipeline (Sincronizzazione gestionale › SOC › Esegui ora)');
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
