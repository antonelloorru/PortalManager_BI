<?php
/**
 * cron_cost_consolidate.php — consolida TotCostoTab per dipendente ed esercizio.
 *
 * `CostModel` calcola il costo aziendale al volo quando si apre la scheda del
 * dipendente, ma non lo scrive da nessuna parte. Questo script lo invoca per
 * ogni dipendente e ne registra il risultato in `cm_employee_cost_year`.
 *
 * Riusa `CostModel` invece di riscriverne la formula: due implementazioni della
 * stessa regola divergono, e il numero nella scheda del dipendente e quello
 * nella redditivita' devono essere lo stesso sempre.
 *
 * QUANDO ESEGUIRLO
 *   dopo l'aggiornamento dei dati finanziari di un esercizio, e ogni volta che
 *   cambiano RAL, overhead o gli altri parametri di calcolo.
 *
 * UTILIZZO
 *   php cron_cost_consolidate.php [--year=2025] [--all-years] [--dry-run] [--quiet]
 *
 *   --year=N      consolida un singolo esercizio (predefinito: quello corrente)
 *   --all-years   consolida tutti gli esercizi presenti in cm_cost_year_params
 *   --dry-run     calcola e mostra senza scrivere
 *   --force       ricalcola anche gli esercizi marcati come chiusi
 *
 * CODICI DI USCITA
 *   0  eseguito
 *   1  eseguito con dipendenti non calcolabili
 *   2  errore di configurazione o connessione
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo script si esegue solo da riga di comando.\n");
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$opt      = $argv ?? [];
$dryRun   = in_array('--dry-run', $opt, true);
$quiet    = in_array('--quiet', $opt, true);
$force    = in_array('--force', $opt, true);
$allYears = in_array('--all-years', $opt, true);

$annoArg = null;
foreach ($opt as $a) {
    if (preg_match('/^--year=(\d{4})$/', (string)$a, $m)) $annoArg = (int)$m[1];
}

$say = function (string $s) use ($quiet): void {
    if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $s . PHP_EOL);
};
$fail = function (string $s, int $c) use ($quiet): void {
    if (!$quiet) fwrite(STDERR, date('[Y-m-d H:i:s] ') . 'ERRORE: ' . $s . PHP_EOL);
    exit($c);
};

require_once(__DIR__ . '/app/Version.php');
require_once(__DIR__ . '/app/Db.php');
require_once(__DIR__ . '/app/CostModel.php');

try { $pdo = Db::pdo(); }
catch (Throwable $e) { $fail('connessione al database non riuscita: ' . $e->getMessage(), 2); }

try { $cm = new CostModel($pdo); }
catch (Throwable $e) { $fail('CostModel non disponibile: ' . $e->getMessage(), 2); }

// ── esercizi da elaborare ───────────────────────────────────────────────────
try {
    if ($allYears) {
        $anni = $pdo->query("SELECT `year` FROM `cm_cost_year_params` ORDER BY `year`")
                    ->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $anni = [$annoArg ?? (int)date('Y')];
    }
} catch (Throwable $e) {
    $fail('tabella cm_cost_year_params assente: eseguire la migration v1.8.97.', 2);
}
if (!$anni) $fail('nessun esercizio configurato in cm_cost_year_params.', 2);

$dip = $pdo->query("SELECT `id`, `first_name`, `last_name`, `part_time_pct`
                      FROM `employees` ORDER BY `last_name`, `first_name`")
           ->fetchAll(PDO::FETCH_ASSOC);
$say(sprintf('Dipendenti: %d. Esercizi: %s.%s', count($dip), implode(', ', $anni),
    $dryRun ? ' MODALITA PROVA, nessuna scrittura.' : ''));

$ins = $pdo->prepare(
    "INSERT INTO `cm_employee_cost_year`
        (`employee_id`, `year`, `tot_costo_tab`, `totale_pre_overhead`, `valore_fte`,
         `totale_fte_ca`, `ral`, `part_time_pct`, `working_days`, `hours_per_day`,
         `costo_giorno`, `costo_ora`, `source`, `computed_at`)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CostModel', NOW())
     ON DUPLICATE KEY UPDATE
        `tot_costo_tab` = VALUES(`tot_costo_tab`),
        `totale_pre_overhead` = VALUES(`totale_pre_overhead`),
        `valore_fte` = VALUES(`valore_fte`),
        `totale_fte_ca` = VALUES(`totale_fte_ca`),
        `ral` = VALUES(`ral`), `part_time_pct` = VALUES(`part_time_pct`),
        `working_days` = VALUES(`working_days`), `hours_per_day` = VALUES(`hours_per_day`),
        `costo_giorno` = VALUES(`costo_giorno`), `costo_ora` = VALUES(`costo_ora`),
        `computed_at` = NOW()");

$totOk = 0; $totKo = 0; $totSalt = 0;

foreach ($anni as $anno) {
    $anno = (int)$anno;

    $par = $pdo->prepare("SELECT * FROM `cm_cost_year_params` WHERE `year` = ?");
    $par->execute([$anno]);
    $p = $par->fetch(PDO::FETCH_ASSOC);
    if (!$p) { $say("Esercizio $anno: non configurato, saltato."); continue; }

    // un esercizio chiuso non si ricalcola: i suoi margini sono gia' stati
    // usati in report consegnati, e riscriverli li renderebbe irriproducibili
    if ((int)$p['is_closed'] === 1 && !$force) {
        $say("Esercizio $anno: chiuso, saltato. Usare --force per ricalcolarlo.");
        continue;
    }

    $gg  = max(1, (int)$p['working_days']);
    $ore = max(0.01, (float)$p['hours_per_day']);
    $ok = 0; $ko = 0; $senza = 0;

    foreach ($dip as $d) {
        try {
            $e = $cm->economics((int)$d['id'], $anno);
            if (!$e) { $senza++; continue; }

            $res = $cm->compute($e, $anno);
            // i valori derivati stanno sotto 'calc': compute() restituisce
            // ['used' => …, 'calc' => …, 'errors' => …], non le chiavi al primo
            // livello. Leggerle dalla radice dava null su ogni dipendente.
            $c   = $res['calc'] ?? [];
            $tot = isset($c['tot_costo_tab']) ? (float)$c['tot_costo_tab'] : null;

            if ($tot === null || $tot <= 0) { $senza++; continue; }

            $cGiorno = round($tot / $gg, 4);
            $cOra    = round($cGiorno / $ore, 4);

            if (!$dryRun) {
                $ins->execute([
                    (int)$d['id'], $anno, round($tot, 2),
                    isset($c['totale_pre_overhead']) ? round((float)$c['totale_pre_overhead'], 2) : null,
                    isset($c['valore_fte'])          ? round((float)$c['valore_fte'], 2) : null,
                    isset($c['totale_fte_ca'])       ? round((float)$c['totale_fte_ca'], 2) : null,
                    isset($e['ral']) ? (float)$e['ral'] : null,
                    isset($d['part_time_pct']) ? (float)$d['part_time_pct'] : null,
                    $gg, $ore, $cGiorno, $cOra,
                ]);
            }
            $ok++;
        } catch (Throwable $ex) {
            $ko++;
            if (!$quiet && $ko <= 5) {
                $say(sprintf('  %s %s: %s', $d['last_name'] ?? '', $d['first_name'] ?? '',
                    mb_substr($ex->getMessage(), 0, 120)));
            }
        }
    }

    $say(sprintf('Esercizio %d (%d giorni x %s ore): %d calcolati, %d senza dati economici, %d errori.',
        $anno, $gg, rtrim(rtrim(number_format($ore, 2, ',', ''), '0'), ','), $ok, $senza, $ko));
    $totOk += $ok; $totKo += $ko; $totSalt += $senza;
}

$say(sprintf('Totale: %d costi %s, %d senza dati, %d errori.',
    $totOk, $dryRun ? 'calcolati' : 'consolidati', $totSalt, $totKo));

if (!$dryRun && $totOk > 0) {
    $say('La redditivita a costo aziendale e ora disponibile in v_cm_redditivita_costo_reale.');
}

exit($totKo === 0 ? 0 : 1);
