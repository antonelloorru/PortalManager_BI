<?php
/**
 * tools/verify_v1_10_04.php — verifica della v1.10.04: le ore della pagina «Attività & Rendicontazione DGB»
 * sono classificate con la stessa logica della «Relazione di Servizio IT» (ordinarie / fuori orario /
 * reperibilità), in tutte le sezioni e con qualunque combinazione di filtri.
 * Uso: php tools/verify_v1_10_04.php --db=demo_portalmanager [--host= --user= --pass= --socket=]
 * Sola lettura.
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
foreach (['PmOrario', 'PmContractFilter', 'PmSnapshot', 'DgbModel', 'ItServiceModel'] as $c) require_once "$root/app/$c.php";
echo "→ Connesso a $user@$host/$db\n\n";
$ok = 0; $ko = 0;
function chk(string $lbl, bool $cond, string $extra = ''): void {
    global $ok, $ko;
    echo ($cond ? "✔" : "✘") . " " . str_pad($lbl, 70) . " " . $extra . "\n";
    $cond ? $ok++ : $ko++;
}
$file = fn(string $rel) => (string)@file_get_contents("$root/$rel");
$one  = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$n2   = fn($v) => number_format((float)$v, 2, ',', '.');
$eq   = fn($a, $b, float $tol = 0.06) => abs((float)$a - (float)$b) <= $tol;

// versione, migrazione, file
chk("app_settings.app_version = 1.10.04", $one("SELECT setting_value FROM app_settings WHERE setting_key='app_version'") === '1.10.04');
chk("VERSION = 1.10.04 e PM_VERSION allineato", trim($file('VERSION')) === '1.10.04' && str_contains($file('app/Version.php'), "define('PM_VERSION', '1.10.04')"));
chk("pm_migration_sql contiene 1.10.04", (bool)$one("SELECT COUNT(*) FROM pm_migration_sql WHERE version='1.10.04'"));
foreach (['TECHNICAL_DESIGN', 'MANUALE_ADMIN', 'MANUALE_UTENTE', 'DEPLOYMENT', 'CHANGELOG', 'RELEASE_CHECKLIST'] as $d)
    chk("Documento docs/{$d}_v1_10_04.md", is_file("$root/docs/{$d}_v1_10_04.md"));

// codice: una sola regola
$dm = $file('app/DgbModel.php');
chk("DgbModel::classi() presente (rep / ord / fuori)", method_exists('DgbModel', 'classi') && count(array_intersect(['rep', 'ord', 'fuori'], array_keys(DgbModel::classi()))) === 3);
chk("Ore ordinarie DGB da PmOrario::ordinarieSql, senza eccezione «turni»", preg_match("/function ordSql\(\): string\n    \{(.*?)\n    \}/s", $dm, $mo) && !str_contains($mo[1], "'turni'") && str_contains($dm, "PmOrario::ordinarieSql('a.date_start', 'a.date_dead_line'"));
chk("Nessuna classe calcolata dalle extra dichiarate (hours − extra)", !str_contains($dm, 'ao.hours - COALESCE(ao.extra_hours,0)') && !str_contains($dm, 'ore_straordinario'));
chk("Relazione IT: stessa funzione PmOrario::ordinarieSql", str_contains($file('app/ItServiceModel.php'), "PmOrario::ordinarieSql('ir.`start_at`', 'ir.`end_at`'"));
chk("DgbSync scrive end_at (fine attività) sui moduli sincronizzati", str_contains($file('app/DgbSync.php'), "'start_at','end_at'"));

// 1) stessa regola riga per riga: Relazione IT (codice reale, via reflection) contro DGB sulle stesse righe
$it = new ItServiceModel($pdo);
$rm = new ReflectionMethod($it, 'oreClassi'); $rm->setAccessible(true); $ci = $rm->invoke($it);
$rj = new ReflectionMethod($it, 'irJoin');    $rj->setAccessible(true); $irJ = $rj->invoke($it);
$cd = DgbModel::classi();
$vs = PmSnapshot::names($pdo, ['v_cm_it_servizio'])['v_cm_it_servizio'];
$sqlCmp = "SELECT COUNT(*) n, ROUND(SUM(s.ore),2) ore,
                  ROUND(SUM({$ci['ord']}),2) it_ord, ROUND(SUM({$cd['ord']}),2) dgb_ord,
                  ROUND(SUM({$ci['fuori']}),2) it_fuo, ROUND(SUM({$cd['fuori']}),2) dgb_fuo,
                  ROUND(SUM({$ci['rep']}),2) it_rep, ROUND(SUM({$cd['rep']}),2) dgb_rep,
                  ROUND(SUM({$ci['nc']}),2) it_nc,
                  SUM(ABS({$ci['ord']} - {$cd['ord']}) > 0.01 OR ABS({$ci['rep']} - {$cd['rep']}) > 0.01) righe_diverse
             FROM `$vs` s $irJ
             JOIN dgb_forms_activity_operator ao ON ao.id = ir.dgb_source_id
             JOIN dgb_forms_activity a ON a.id = ao.id_activity
            WHERE ir.source_system = 'dgb' AND a.deleted = 0
              AND ABS(COALESCE(ir.quantity_hours,0) - COALESCE(ao.hours,0)) < 0.005
              AND ir.start_at <=> a.date_start AND ir.end_at <=> a.date_dead_line";
try {
    $c = $pdo->query($sqlCmp)->fetch(PDO::FETCH_ASSOC);
    chk("Righe confrontate (moduli DGB nella vista della Relazione IT)", (int)$c['n'] > 0, '(' . number_format((int)$c['n'], 0, ',', '.') . ' righe, ' . $n2($c['ore']) . ' h)');
    chk("Ordinarie: Relazione IT = DGB", $eq($c['it_ord'], $c['dgb_ord'], 0.5), '(' . $n2($c['it_ord']) . ' / ' . $n2($c['dgb_ord']) . ')');
    chk("Fuori orario: Relazione IT = DGB", $eq($c['it_fuo'], $c['dgb_fuo'], 0.5), '(' . $n2($c['it_fuo']) . ' / ' . $n2($c['dgb_fuo']) . ')');
    chk("Reperibilità: Relazione IT = DGB", $eq($c['it_rep'], $c['dgb_rep'], 0.5), '(' . $n2($c['it_rep']) . ' / ' . $n2($c['dgb_rep']) . ')');
    chk("Non classificate della Relazione IT = 0 sulle righe DGB", $eq($c['it_nc'], 0), '(' . $n2($c['it_nc']) . ')');
    chk("Righe con classificazione diversa = 0", (int)$c['righe_diverse'] === 0, '(' . (int)$c['righe_diverse'] . ')');
} catch (Throwable $e) { chk("Confronto riga per riga Relazione IT / DGB", false, $e->getMessage()); }

// 2) SQL contro PHP (PmOrario::ordinarie) su un campione
$rows = $pdo->query("SELECT a.date_start s, a.date_dead_line e, COALESCE(ao.hours,0) h, COALESCE(ao.during_availability,0) r,
                            {$cd['ord']} o, {$cd['fuori']} f, {$cd['rep']} rp
                       FROM dgb_forms_activity_operator ao JOIN dgb_forms_activity a ON a.id = ao.id_activity
                      WHERE a.deleted = 0 ORDER BY ao.id DESC LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC);
$bad = 0;
foreach ($rows as $r) {
    $o = $r['r'] ? 0.0 : PmOrario::ordinarie($r['s'], $r['e'], (float)$r['h']);
    $f = $r['r'] ? 0.0 : (float)$r['h'] - $o; $p = $r['r'] ? (float)$r['h'] : 0.0;
    if (abs($o - (float)$r['o']) > 0.01 || abs($f - (float)$r['f']) > 0.01 || abs($p - (float)$r['rp']) > 0.01) $bad++;
}
chk("Classi SQL = regola PHP PmOrario::ordinarie (campione)", $rows && $bad === 0, '(' . count($rows) . " righe, $bad diverse)");

// 3) coerenza fra sezioni della pagina e partizione, con i filtri
$m = new DgbModel($pdo);
$c1 = (int)$one("SELECT id_contract FROM dgb_forms_activity WHERE deleted=0 GROUP BY id_contract ORDER BY COUNT(*) DESC LIMIT 1");
$op = (int)$one("SELECT ao.id_operator FROM dgb_forms_activity_operator ao WHERE ao.during_availability=1 GROUP BY ao.id_operator ORDER BY COUNT(*) DESC LIMIT 1");
$ym = (string)$one("SELECT DATE_FORMAT(MAX(COALESCE(a.report_date, DATE(a.date_start))),'%Y-%m') FROM dgb_forms_activity a
                      JOIN dgb_forms_activity_operator ao ON ao.id_activity = a.id WHERE a.deleted=0 AND ao.hours > 0");
$casi = [
    'nessun filtro'                 => [],
    'periodo (ultimo mese)'         => ['from' => "$ym-01", 'to' => date('Y-m-t', strtotime("$ym-01"))],
    'incaricato in reperibilità'    => ['operator' => [$op]],
    'reperibilità = sì'             => ['rep' => '1'],
    'modalità remoto + smart'       => ['mode' => ['remoto', 'smart']],
    'fascia oraria fuori orario'    => ['fasce' => ['fuori orario']],
    'durata giornata'               => ['durate' => ['giornata']],
    'stato commessa non chiusa'     => ['stato_commessa' => ['non_chiusa']],
    'contratto DGB'                 => ['contract' => $c1],
];
foreach ($casi as $lbl => $in) {
    $f = DgbModel::normFilters($in + ['gb' => ['incaricato']]);
    try {
        $t = $m->aggregaTotale($f); $hb = $m->hoursBreakdown($f); $ps = $m->periodSummary($f);
        $td = $m->temporalDistribution($f, 'month')['totals'];
        $ore = (float)($t['ore'] ?? 0);
        $part = $eq((float)$t['ore_ordinarie'] + (float)$t['ore_fuori_orario'] + (float)$t['ore_reperibilita'], $ore);
        $sez  = $eq($hb['ordinary'], $t['ore_ordinarie']) && $eq($hb['overtime'], $t['ore_fuori_orario']) && $eq($hb['oncall'], $t['ore_reperibilita'])
             && $eq($ps['ore_in_orario'], $t['ore_ordinarie']) && $eq($ps['ore_fuori_orario'], $t['ore_fuori_orario']) && $eq($ps['ore_reperibilita'], $t['ore_reperibilita'])
             && $eq($td['ordinary'], $t['ore_ordinarie'], 0.5) && $eq($td['overtime'], $t['ore_fuori_orario'], 0.5) && $eq($td['oncall'], $t['ore_reperibilita'], 0.5);
        $grp = array_sum(array_map(fn($r) => (float)$r['ore_ordinarie'], $m->aggrega($f, 100000)));
        $extra = $lbl === 'reperibilità = sì' ? ($eq($t['ore_ordinarie'], 0) && $eq($t['ore_fuori_orario'], 0)) : true;
        chk("Filtro «{$lbl}»: partizione, sezioni e gruppi coerenti", $ore >= 0 && $part && $sez && $eq($grp, $t['ore_ordinarie'], 0.5) && $extra,
            '(' . $n2($ore) . ' h = ' . $n2($t['ore_ordinarie']) . ' + ' . $n2($t['ore_fuori_orario']) . ' + ' . $n2($t['ore_reperibilita']) . ')');
    } catch (Throwable $e) { chk("Filtro «{$lbl}»", false, $e->getMessage()); }
}

echo "\nEsito: $ok OK, $ko KO\n";
exit($ko ? 1 : 0);
