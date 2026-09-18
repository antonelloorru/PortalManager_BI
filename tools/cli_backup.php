<?php
/**
 * tools/cli_backup.php — Backup CLI sicuro e ultra-rapido (Zero-Timeout)
 * Eseguibile via riga di comando per creare backup completi senza i limiti di timeout HTTP di Apache/PHP.
 *
 * Utilizzo:
 *   php tools/cli_backup.php [--skip-files] [--skip-db]
 */

if (PHP_SAPI !== 'cli') {
    die("Questo script puo' essere eseguito solo da riga di comando (CLI).\n");
}

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$appRoot = dirname(__DIR__);
$backupDir = $appRoot . '/uploads/backups/';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

$skipFiles = in_array('--skip-files', $argv, true);
$skipDb    = in_array('--skip-db', $argv, true);
if ($skipDb) {
    define('CLI_OPTIONAL_DB', true);
}

require_once $appRoot . '/Config.php';

$currentVer = '1.9.53';
if (file_exists($appRoot . '/VERSION')) {
    $currentVer = trim((string)file_get_contents($appRoot . '/VERSION'));
}

$timestamp = date('Ymd_His');
echo "\n=======================================================\n";
echo "  PortalManager — CLI Fast Backup Tool (Zero-Timeout)  \n";
echo "=======================================================\n";
echo "Root applicazione: $appRoot\n";
echo "Cartella backup:   $backupDir\n";
echo "Versione corrente: $currentVer\n";
echo "Timestamp:         $timestamp\n\n";

$skipFiles = in_array('--skip-files', $argv, true);
$skipDb    = in_array('--skip-db', $argv, true);

// ── 1. Backup Filesystem ──────────────────────────────────────────────
if (!$skipFiles) {
    $zipName = "backup_files_v{$currentVer}_{$timestamp}.zip";
    $zipPath = $backupDir . $zipName;
    echo "1. Creazione backup filesystem in corso...\n";
    $t0 = microtime(true);

    if (class_exists('ZipArchive')) {
        $bz = new ZipArchive();
        if ($bz->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $skipDirs = ['uploads', 'docs', '.git', 'node_modules', 'vendor', 'Dump', 'tools'];
            $count = 0;
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $rel = str_replace($appRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $rel = str_replace('\\', '/', $rel);

                $skipThis = false;
                foreach ($skipDirs as $sd) {
                    if (strpos($rel, $sd . '/') === 0 || $rel === $sd) {
                        $skipThis = true;
                        break;
                    }
                }
                if ($skipThis) continue;

                if (preg_match('/\.(zip|tar|gz|7z|sql)$/i', $rel)) continue;

                if ($file->isFile() && $file->getSize() < 10 * 1024 * 1024) {
                    $bz->addFile($file->getPathname(), $rel);
                    $count++;
                }
            }
            $bz->close();
            $sec = round(microtime(true) - $t0, 2);
            $mb = round(filesize($zipPath) / (1024 * 1024), 2);
            echo "   [OK] File archiviati (ZipArchive): $count file in {$sec}s ({$mb} MB) -> $zipName\n";
        } else {
            echo "   [ERRORE] Impossibile creare il file ZIP: $zipPath\n";
        }
    } else {
        // Fallback su tar.exe nativo di Windows (Zero estensioni PHP richieste)
        $origCwd = getcwd();
        chdir($appRoot);
        $cmd = 'tar.exe -a -cf ' . escapeshellarg($zipPath) . ' --exclude="uploads" --exclude="docs" --exclude=".git" --exclude="Dump" --exclude="*.zip" --exclude="*.sql" .';
        @exec($cmd . ' 2>&1', $out, $rc);
        chdir($origCwd);
        if ($rc === 0 && file_exists($zipPath)) {
            $sec = round(microtime(true) - $t0, 2);
            $mb = round(filesize($zipPath) / (1024 * 1024), 2);
            echo "   [OK] File archiviati (tar nativo): backup completato in {$sec}s ({$mb} MB) -> $zipName\n";
        } else {
            echo "   [ERRORE] Backup tar fallito: " . implode("\n", $out) . "\n";
        }
    }
} else {
    echo "1. Backup filesystem saltato (--skip-files).\n";
}

// ── 2. Backup Database ────────────────────────────────────────────────
if (!$skipDb && isset($pdo)) {
    $sqlName = "backup_db_v{$currentVer}_{$timestamp}.sql";
    $sqlPath = $backupDir . $sqlName;
    echo "\n2. Creazione backup database in corso...\n";
    $t0 = microtime(true);

    $fh = fopen($sqlPath, 'w');
    if ($fh) {
        fwrite($fh, "-- ======================================================\n");
        fwrite($fh, "-- PortalManager v{$currentVer} Database Backup\n");
        fwrite($fh, "-- Generato: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "-- ======================================================\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($fh, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($fh, "SET NAMES utf8mb4;\n\n");

        $rawTables = $pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM);
        $baseTables = [];
        $views = [];

        foreach ($rawTables as $row) {
            $name = $row[0];
            $type = $row[1] ?? 'BASE TABLE';
            if ($type === 'VIEW') {
                $views[] = $name;
            } else {
                $baseTables[] = $name;
            }
        }

        echo "   Tabelle dati da esportare: " . count($baseTables) . "\n";
        echo "   Viste SQL da preservare DDL: " . count($views) . " (dati non esportati per evitare timeout)\n";

        $totalRows = 0;
        foreach ($baseTables as $tbl) {
            $tEsc = '`' . str_replace('`', '``', $tbl) . '`';
            fwrite($fh, "DROP TABLE IF EXISTS $tEsc;\n");
            $cr = $pdo->query("SHOW CREATE TABLE $tEsc")->fetch(PDO::FETCH_ASSOC);
            fwrite($fh, ($cr['Create Table'] ?? '') . ";\n\n");

            $st = $pdo->query("SELECT * FROM $tEsc");
            $cols = null;
            $batch = [];
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = array_keys($r);
                }
                $vals = [];
                foreach ($r as $val) {
                    if ($val === null) {
                        $vals[] = 'NULL';
                    } elseif (is_int($val) || is_float($val)) {
                        $vals[] = (string)$val;
                    } else {
                        $vals[] = $pdo->quote((string)$val);
                    }
                }
                $batch[] = '(' . implode(',', $vals) . ')';
                $totalRows++;
                if (count($batch) >= 100) {
                    fwrite($fh, "INSERT INTO $tEsc (`" . implode('`,`', $cols) . "`) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                fwrite($fh, "INSERT INTO $tEsc (`" . implode('`,`', $cols) . "`) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($fh, "\n");
        }

        if (!empty($views)) {
            fwrite($fh, "-- ── Viste SQL (DDL) ───────────────────────────────────\n\n");
            foreach ($views as $vw) {
                $vEsc = '`' . str_replace('`', '``', $vw) . '`';
                try {
                    $cr = $pdo->query("SHOW CREATE VIEW $vEsc")->fetch(PDO::FETCH_ASSOC);
                    if ($cr && isset($cr['Create View'])) {
                        fwrite($fh, "DROP VIEW IF EXISTS $vEsc;\n");
                        fwrite($fh, $cr['Create View'] . ";\n\n");
                    }
                } catch (\Throwable $e) {
                    fwrite($fh, "-- Impossibile esportare DDL vista $vEsc: " . $e->getMessage() . "\n");
                }
            }
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
        $sec = round(microtime(true) - $t0, 2);
        $mb = round(filesize($sqlPath) / (1024 * 1024), 2);
        echo "   [OK] Database esportato: {$totalRows} record in {$sec}s ({$mb} MB) -> $sqlName\n";
    } else {
        echo "   [ERRORE] Impossibile creare il file SQL: $sqlPath\n";
    }
} else {
    echo "\n2. Backup database saltato (--skip-db).\n";
}

echo "\n=======================================================\n";
echo "  Backup completato con successo!\n";
echo "=======================================================\n\n";
