<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.24 — Diagnostica migration (self-contained).
 * Non dipende da bootstrap.php.
 *
 * Uso (da CLI):
 *   php diagnose_1_9_24.php [file.sql] [--db=nome] [--user=root] [--pass=] [--host=127.0.0.1] [--port=3306]
 *
 * Esempi:
 *   php diagnose_1_9_24.php
 *   php diagnose_1_9_24.php migration_v1_9_24_hotfix.sql --db=portalmanager
 *   php diagnose_1_9_24.php migration_v1_9_24_hotfix.sql --db=demo_portalmanager --user=root --pass=segreta
 *
 * Autodetect: se in ../config/ esiste un file db.php/database.php/config.php
 * con costanti/variabili DB_HOST DB_NAME DB_USER DB_PASS, li usa.
 */

$args = parseArgs($argv);
$file = $args['_positional'][0] ?? (__DIR__ . '/migration_v1_9_24_hotfix.sql');
if (!is_file($file)) { fwrite(STDERR, "File non trovato: $file\n"); exit(1); }

[$host, $port, $db, $user, $pass] = resolveDbCredentials($args);
echo "→ Connessione: mysql://$user@$host:$port/$db\n";
try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Connessione fallita: " . $e->getMessage() . "\n");
    exit(1);
}

$sql = file_get_contents($file);

// Tokenizer: rimuove commenti (--, /* */) e splitta su ';' fuori dalle stringhe/backtick.
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);

$stmts = []; $buf = ''; $inStr = false; $q = '';
$len = strlen($sql);
for ($i = 0; $i < $len; $i++) {
    $c = $sql[$i];
    if ($inStr) {
        $buf .= $c;
        if ($c === $q && ($i === 0 || $sql[$i - 1] !== '\\')) $inStr = false;
        continue;
    }
    if ($c === "'" || $c === '"' || $c === '`') { $inStr = true; $q = $c; $buf .= $c; continue; }
    if ($c === ';') { $t = trim($buf); if ($t !== '') $stmts[] = $t; $buf = ''; continue; }
    $buf .= $c;
}
if (trim($buf) !== '') $stmts[] = trim($buf);

echo "Trovati " . count($stmts) . " statement.\n\n";

$fails = 0;
foreach ($stmts as $idx => $s) {
    $preview = preg_replace('/\s+/', ' ', substr($s, 0, 90));
    try {
        $pdo->exec($s);
        printf("[OK]   #%02d %s\n", $idx + 1, $preview);
    } catch (PDOException $e) {
        $fails++;
        printf("[FAIL] #%02d %s\n", $idx + 1, $preview);
        printf("       ERRORE: %s\n", $e->getMessage());
        echo "--- STATEMENT COMPLETO ---\n$s\n--- FINE ---\n\n";
        exit(2);
    }
}
echo "\nMigration completata senza errori (" . count($stmts) . " statement).\n";

/* -------------------- helpers -------------------- */

function parseArgs(array $argv): array {
    $out = ['_positional' => []];
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $out[$m[1]] = $m[2];
        elseif (str_starts_with($a, '--'))            $out[substr($a, 2)] = true;
        else                                          $out['_positional'][] = $a;
    }
    return $out;
}

function resolveDbCredentials(array $args): array {
    // Priorità: CLI arg > ENV > autodetect config > default XAMPP
    $host = $args['host'] ?? getenv('PM_DB_HOST') ?: '127.0.0.1';
    $port = (int)($args['port'] ?? getenv('PM_DB_PORT') ?: 3306);
    $db   = $args['db']   ?? getenv('PM_DB_NAME') ?: null;
    $user = $args['user'] ?? getenv('PM_DB_USER') ?: null;
    $pass = $args['pass'] ?? getenv('PM_DB_PASS');
    if ($pass === false) $pass = null;

    // Autodetect: cerca in ../config/ file noti
    $projectRoot = realpath(__DIR__ . '/..');
    $candidates = [
        $projectRoot . '/config/db.php',
        $projectRoot . '/config/database.php',
        $projectRoot . '/config/config.php',
        $projectRoot . '/app/Config.php',
        $projectRoot . '/config.php',
    ];
    foreach ($candidates as $cfg) {
        if (!is_file($cfg)) continue;
        $src = @file_get_contents($cfg);
        if ($src === false) continue;
        // Cattura pattern comuni: define('DB_NAME', '...'), $db_name = '...', 'name' => '...'
        $map = [
            'host' => ['DB_HOST','db_host','host','DB_SERVER'],
            'name' => ['DB_NAME','db_name','name','DB_DATABASE','database'],
            'user' => ['DB_USER','db_user','user','DB_USERNAME','username'],
            'pass' => ['DB_PASS','db_pass','pass','DB_PASSWORD','password'],
            'port' => ['DB_PORT','db_port','port'],
        ];
        foreach ($map as $k => $names) {
            foreach ($names as $n) {
                if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($n,'/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) { $found[$k] = $m[1]; break; }
                if (preg_match('/\$' . preg_quote($n,'/') . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $src, $m))                     { $found[$k] = $m[1]; break; }
                if (preg_match('/[\'"]' . preg_quote($n,'/') . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $src, $m))            { $found[$k] = $m[1]; break; }
            }
        }
        if (!empty($found)) {
            if ($db   === null && !empty($found['name'])) $db   = $found['name'];
            if ($user === null && !empty($found['user'])) $user = $found['user'];
            if ($pass === null && isset($found['pass']))  $pass = $found['pass'];
            if (empty($args['host']) && !empty($found['host'])) $host = $found['host'];
            if (empty($args['port']) && !empty($found['port'])) $port = (int)$found['port'];
            echo "→ Config trovata: $cfg\n";
            break;
        }
    }

    // Default XAMPP se ancora vuoto
    $user = $user ?: 'root';
    $pass = $pass ?? '';
    $db   = $db   ?: basename(realpath(__DIR__ . '/..') ?: 'portalmanager');

    return [$host, $port, $db, $user, $pass];
}
