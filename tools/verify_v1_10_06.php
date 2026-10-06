<?php
/**
 * tools/verify_v1_10_06.php — verifica della v1.10.06: Gestione Commesse › Service SOC.
 * Uso: php tools/verify_v1_10_06.php --db=demo_portalmanager [--host= --user= --pass= --socket=] [--file=<export.xlsx>]
 *   --file: importa l'export «lista eventi ticket» (idempotente: reimportarlo non crea doppioni) e controlla l'esito.
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
foreach (['SocIngest', 'SocModel'] as $c) require_once "$root/app/$c.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 70) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();

chk("app_settings.app_version = 1.10.06", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.06');
chk("VERSION = 1.10.06 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.06' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.06')"));
chk("pm_migration_sql contiene 1.10.06", (bool)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version='1.10.06'"));
foreach (['cm_soc_events', 'cm_soc_tickets', 'cm_soc_batches', 'cm_soc_source_db', 'cm_soc_people', 'cm_soc_clients'] as $t)
    chk("Tabella $t", (bool)$one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'"));
chk("Colonne reply_min / opened_by / avg_reply_min", (int)$one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='cm_soc_events' AND COLUMN_NAME='reply_min') OR (TABLE_NAME='cm_soc_tickets' AND COLUMN_NAME IN ('opened_by','avg_reply_min')))") === 3);
chk("Impostazioni soc.* (4)", (int)$one("SELECT COUNT(*) FROM app_settings WHERE setting_key IN ('soc.sla_risposta_ore','soc.presidio_ore','soc.sync_enabled','soc.closed_states')") === 4);
chk("Permessi service_soc.php (ruoli di Service Desk)", (int)$one("SELECT COUNT(*) FROM role_permissions WHERE page_name='service_soc.php'") >= 1);
chk("Nessuna password in chiaro nel DB SOC configurato", !(int)$one("SELECT COUNT(*) FROM cm_soc_source_db WHERE password_enc IS NOT NULL AND password_enc <> '' AND password_enc NOT REGEXP '^[A-Za-z0-9+/]+=*$'"));
foreach (['service_soc.php', 'cron_soc_sync.php', 'app/SocIngest.php', 'app/SocModel.php', 'app/soc_ticket_table.php', 'sql/migration_v1_10_06.sql'] as $f) chk("File $f", is_file("$root/$f"));
chk("Router: pagina service_soc", str_contains($file('app/Router.php'), "'service_soc'"));
chk("Menu Gestione Commesse: Service SOC", str_contains($file('app/MenuManager.php'), "'page' => 'service_soc'"));
chk("Catalogo permessi e manage_permissions", str_contains($file('app/PermissionCatalog.php'), "'service_soc.php'") && str_contains($file('manage_permissions.php'), "'service_soc.php'"));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d) chk("Documento docs/{$d}_v1_10_06.md", is_file("$root/docs/{$d}_v1_10_06.md"));

// regole di normalizzazione
chk("Intestazioni dell'export riconosciute (17 colonne)", count(SocIngest::mapHeaders(['Data evento', 'Casella di posta', 'Responsabile', 'Incaricato', 'Tipo', 'Categoria', 'Codice', 'Evento', 'Coda', 'Stato prima', 'Stato dopo', 'Risoluzione', 'Titolo', 'Autore', 'Cliente', 'Commessa', 'Durata'])) === 17);
chk("Durata «292g 19h 49m 5s» = 421.669 minuti", SocIngest::parseDuration('292g 19h 49m 5s') === 421669);
chk("Data «04/12/2025 15:17:14»", SocIngest::parseDateTime('04/12/2025 15:17:14') === '2025-12-04 15:17:14');
chk("Tipi evento: file e DB sulla stessa classe", SocIngest::kind('Messaggio del supporto') === SocIngest::kind('SUPPORT_MSG') && SocIngest::kind('Risposta del cliente') === SocIngest::kind('CUSTOMER_MSG')
    && SocIngest::kind('Annotazione interna') === SocIngest::kind('INTERNAL_NOTE') && SocIngest::kind('APERTO') === 'apertura');
$ing = new SocIngest($pdo);
$e1 = $ing->normalize(['event_at' => '04/12/2025 15:17:14', 'ticket_code' => 'WES_000000071', 'event_label' => 'Messaggio del supporto', 'status_before' => 'APERTO', 'status_after' => 'ATTESA CLIENTE']);
$e2 = $ing->normalize(['event_at' => '2025-12-04 15:17:14', 'ticket_code' => 'WES_000000071_001', 'event_label' => 'SUPPORT_MSG', 'status_before' => 'APERTO', 'status_after' => 'ATTESA CLIENTE']);
chk("Stessa chiave per lo stesso evento da file e da DB", $e1 && $e2 && SocIngest::baseKey($e1) === SocIngest::baseKey($e2));
[$q, $p] = SocIngest::extractQuery(['window_days' => 30, 'ticket_prefix' => 'WES_']);
chk("Query predefinita DB SOC: SELECT con data minima e prefisso", str_starts_with($q, 'SELECT') && count($p) === 2 && $p[1] === 'WES_%');

// dati e cruscotto
$nt = (int)$one("SELECT COUNT(*) FROM cm_soc_tickets");
if ($nt > 0) {
    chk("Ticket ricostruiti = codici distinti degli eventi", $nt === (int)$one("SELECT COUNT(DISTINCT ticket_code) FROM cm_soc_events"), "($nt)");
    chk("Contatori eventi coerenti (supporto + cliente + note ≤ eventi)", !(int)$one("SELECT COUNT(*) FROM cm_soc_tickets WHERE n_support + n_customer + n_notes > n_events"));
    chk("Chiusi solo con stato di chiusura", !(int)$one("SELECT COUNT(*) FROM cm_soc_tickets WHERE is_closed = 1 AND closed_at IS NULL"));
    $m = new SocModel($pdo); $f = $m->normFilters([]);
    $h = $m->headline($f);
    chk("Cruscotto: indicatori del periodo", $h['attivi'] >= $h['aperti'] && isset($h['backlog'], $h['presidio']), "({$h['attivi']} ticket, backlog {$h['backlog']})");
    $sum = array_sum(array_map(fn($r) => (int)$r['attivi'], $m->breakdown($f, 'categoria')));
    chk("Ripartizione per categoria = ticket del periodo", $sum === $h['attivi']);
    $ore = (float)$one("SELECT COALESCE(SUM(ir.quantity_hours),0) FROM cm_intervention_reports ir JOIN cm_soc_tickets t ON t.ticket_code = ir.ticket
                         WHERE ir.report_date BETWEEN '{$f['from']}' AND '{$f['to']}' AND EXISTS (SELECT 1 FROM cm_soc_events e WHERE e.ticket_code = t.ticket_code AND e.event_at BETWEEN '{$f['from']} 00:00:00' AND '{$f['to']} 23:59:59')");
    chk("Ore moduli di intervento del portale sui ticket del periodo", abs($ore - (float)$h['moduli']['ore']) < 0.01, '(' . number_format($ore, 1, ',', '.') . ' h)');
}
if (!empty($args['file']) && is_file($args['file'])) {
    $r = (new SocIngest($pdo))->importFile($args['file'], basename($args['file']), null);
    chk("Import del file", $r['ok'], '(' . $r['message'] . ')');
    $r2 = (new SocIngest($pdo))->importFile($args['file'], basename($args['file']), null);
    chk("Reimport dello stesso file senza nuove righe", $r2['ok'] && (int)$r2['rows_inserted'] === 0);
}
echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
