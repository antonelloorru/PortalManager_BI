<?php
// app/UpdaterCore.php — funzioni pure dell'updater ZIP, estratte da system_update.php (v1.7.70)
// Riusate dalla console unificata system_console.php.
function analyze_zip(string $zipPath, string $appRoot, string $currentVer): array
{
    $r = [
        'zip_size'       => filesize($zipPath),
        'new_version'    => null,
        'files_total'    => 0,
        'files_new'      => [],
        'files_modified' => [],
        'files_unchanged'=> 0,
        'sql_migrations' => [],
        'has_manifest'   => false,
        'manifest'       => [],
        'errors'         => [],
    ];

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $r['errors'][] = "Impossibile aprire il file ZIP.";
        return $r;
    }

    $r['files_total'] = $zip->numFiles;

    // Scan tutti i file
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (substr($entry, -1) === '/') continue; // skip directories

        $basename = basename($entry);

        // Rileva versione
        if ($basename === 'VERSION') {
            $r['new_version'] = trim($zip->getFromIndex($i));
        }

        // Rileva manifest
        if ($basename === 'update_manifest.json') {
            $r['has_manifest'] = true;
            $r['manifest'] = json_decode($zip->getFromIndex($i), true) ?: [];
        }

        // Rileva migrazioni SQL
        if (preg_match('/^migration.*\.sql$/i', $basename) || preg_match('/^upgrade.*\.sql$/i', $basename)) {
            $existing = $appRoot . '/' . $basename;
            if (!file_exists($existing)) {
                $r['sql_migrations'][] = $basename;
            } else {
                // Confronta hash per capire se è cambiato
                $new_hash = md5($zip->getFromIndex($i));
                $old_hash = md5_file($existing);
                if ($new_hash !== $old_hash) {
                    $r['sql_migrations'][] = $basename . ' (aggiornato)';
                }
            }
        }

        // Confronta file con esistenti
        $local = $appRoot . '/' . $basename;
        if (!file_exists($local)) {
            $r['files_new'][] = $basename;
        } else {
            $new_hash = md5($zip->getFromIndex($i));
            $old_hash = md5_file($local);
            if ($new_hash !== $old_hash) {
                $r['files_modified'][] = $basename;
            } else {
                $r['files_unchanged']++;
            }
        }
    }

    $zip->close();
    return $r;
}

function apply_update(string $zipPath, string $appRoot, string $backupDir, string $tempDir, string $currentVer, PDO $pdo, int $userId): array
{
    $r = [
        'backup_file'     => null,
        'backup_db'       => null,
        'files_updated'   => 0,
        'files_added'     => 0,
        'files_skipped'   => 0,
        'sql_executed'    => [],
        'sql_errors'      => [],
        'old_version'     => $currentVer,
        'new_version'     => null,
        'errors'          => [],
        'duration'        => 0,
    ];
    $t_start = microtime(true);

    // ── Step 1: Backup file correnti ─────────────────────────────────────────
    $backup_name = "backup_v{$currentVer}_" . date('Ymd_His') . '.zip';
    $backup_path = $backupDir . $backup_name;
    $bz = new ZipArchive();
    if ($bz->open($backup_path, ZipArchive::CREATE) === true) {
        $skip = ['uploads','docs','.git','node_modules','vendor'];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $rel = str_replace($appRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $rel = str_replace('\\', '/', $rel);
            // Skip directories
            $skipThis = false;
            foreach ($skip as $s) { if (strpos($rel, $s . '/') === 0 || $rel === $s) { $skipThis = true; break; } }
            if ($skipThis) continue;
            if ($file->isFile() && $file->getSize() < 5*1024*1024) {
                $bz->addFile($file->getPathname(), $rel);
            }
        }
        $bz->close();
        $r['backup_file'] = $backup_name;
    } else {
        $r['errors'][] = "Impossibile creare backup file.";
    }

    // ── Step 2: Backup DB ────────────────────────────────────────────────────
    try {
        $db_name = '';
        try { $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn(); } catch (\Exception $e) {}
        if ($db_name) {
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $db_file = "db_backup_v{$currentVer}_" . date('Ymd_His') . '.sql';
            $bh = fopen($backupDir . $db_file, 'w');
            if ($bh === false) {
                $r['errors'][] = "Impossibile creare file backup DB.";
            } else {
                // v1.8.10: backup in streaming (query non bufferizzata + scrittura a blocchi)
                // per non esaurire la memoria su tabelle molto grandi (es. import DGB con
                // decine di migliaia di righe). In precedenza si caricava l'intera tabella
                // in memoria con fetchAll, causando "Allowed memory size exhausted".
                $hadBuffered = true;
                try { $hadBuffered = (bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY); } catch (\Throwable $e) {}
                try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); } catch (\Throwable $e) {}
                foreach ($tables as $t) {
                    try { $st = $pdo->query("SELECT * FROM `$t`"); }
                    catch (\Throwable $e) { $r['errors'][] = "Backup tabella $t saltata: " . $e->getMessage(); continue; }
                    $cols = null; $buf = ''; $cnt = 0; $started = false;
                    while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                        if ($cols === null) { $cols = array_keys($row); fwrite($bh, "-- Table: $t\n"); $started = true; }
                        $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . addslashes((string)$v) . "'", array_values($row));
                        $buf .= "INSERT IGNORE INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
                        if ((++$cnt % 500) === 0) { fwrite($bh, $buf); $buf = ''; }
                    }
                    if ($buf !== '') fwrite($bh, $buf);
                    if ($started) fwrite($bh, "\n");
                    if (method_exists($st, 'closeCursor')) $st->closeCursor();
                    $st = null;
                }
                try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $hadBuffered); } catch (\Throwable $e) {}
                fclose($bh);
                $r['backup_db'] = $db_file;
            }
        }
    } catch (\Exception $e) {
        $r['errors'][] = "Backup DB fallito: " . $e->getMessage();
    }

    // ── Step 3: Estrai ZIP ───────────────────────────────────────────────────
    if (is_dir($tempDir)) { array_map('unlink', glob($tempDir . '*')); } else { @mkdir($tempDir, 0755, true); }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $r['errors'][] = "Impossibile aprire ZIP.";
        $r['duration'] = round(microtime(true) - $t_start, 2);
        return $r;
    }

    // Protezione: file che NON devono essere sovrascritti.
    // Match esatto sul percorso relativo dentro lo zip (case-insensitive su Windows).
    $protected = ['Config.php', '.htaccess', '.env.php', 'installer_disabled.flag'];
    $protected_dirs = ['uploads/']; // tutto il contenuto di queste cartelle è protetto

    $new_sql_files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);

        // Salta entry vuote o di tipo "directory" (terminano con /)
        if ($entry === '' || substr($entry, -1) === '/') continue;

        // Normalizza separatori: alcuni zip Windows usano backslash invece di slash.
        // Senza questa normalizzazione i file ZIP creati su Windows non si estraggono
        // mai nelle sottocartelle corrette.
        $entry = str_replace('\\', '/', $entry);

        // ── ZIP-SLIP PROTECTION ──────────────────────────────────────────
        // Rifiuta path-traversal: ../, percorsi assoluti, doppie barre.
        if (strpos($entry, '..') !== false
            || strpos($entry, '//') !== false
            || $entry[0] === '/'
            || preg_match('#^[A-Za-z]:#', $entry)) {
            $r['errors'][] = "Voce ZIP rifiutata (path sospetto): $entry";
            continue;
        }

        // Path relativo nel target = path nello zip (preserva tutta la gerarchia).
        // Es: app/Csrf.php → app/Csrf.php (NON appiattito a Csrf.php)
        //     sql/migration.sql → sql/migration.sql
        //     docs/sub/guide.md → docs/sub/guide.md
        $target_rel = $entry;
        $basename = basename($entry);

        // ── PROTEZIONE FILE/DIR ──────────────────────────────────────────
        $isProtected = false;
        // Match esatto su file protetti (in qualunque posizione)
        if (in_array($basename, $protected, true) && file_exists($appRoot . '/' . $target_rel)) {
            $isProtected = true;
        }
        // Match prefisso su cartelle protette
        foreach ($protected_dirs as $p) {
            if (strpos($target_rel, $p) === 0) { $isProtected = true; break; }
        }
        if ($isProtected) {
            $r['files_skipped']++;
            continue;
        }

        $target = $appRoot . '/' . $target_rel;
        $content = $zip->getFromIndex($i);

        // ── SAFEGUARD path canonico ──────────────────────────────────────
        // Verifica che il path risolto NON esca dal root del portale
        // (defense in depth contro zip-slip residuo dopo rewrite di realpath).
        $target_dir = dirname($target);
        if (!is_dir($target_dir)) {
            if (!@mkdir($target_dir, 0755, true)) {
                $r['errors'][] = "Impossibile creare cartella: $target_dir";
                continue;
            }
        }
        $real_target_dir = realpath($target_dir) ?: $target_dir;
        $real_app_root   = realpath($appRoot) ?: $appRoot;
        if (strpos(str_replace('\\', '/', $real_target_dir), str_replace('\\', '/', $real_app_root)) !== 0) {
            $r['errors'][] = "Voce ZIP rifiutata (path fuori da appRoot): $entry";
            continue;
        }

        // Verifica se diverso
        $is_new = !file_exists($target);
        $is_changed = !$is_new && md5($content) !== md5_file($target);

        if ($is_new || $is_changed) {
            $dir = dirname($target);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            file_put_contents($target, $content);
            if ($is_new) $r['files_added']++;
            else $r['files_updated']++;

            // Traccia SQL nuovi
            if (preg_match('/\.sql$/i', $basename)) {
                $new_sql_files[] = $basename;
            }
        } else {
            $r['files_skipped']++;
        }

        // Rileva versione
        if ($basename === 'VERSION') {
            $r['new_version'] = trim($content);
        }
    }
    $zip->close();

    // ── Step 4: Esecuzione migrazioni SQL ────────────────────────────────────
    foreach ($new_sql_files as $sql_file) {
        $sql_path = $appRoot . '/' . $sql_file;
        if (!file_exists($sql_path)) continue;

        $sql_content = file_get_contents($sql_path);
        $statements  = array_filter(array_map('trim', explode(';', $sql_content)));

        $ok = 0; $err = 0;
        foreach ($statements as $stmt) {
            // v1.7.63: prima veniva saltato l'INTERO chunk se iniziava con '--'.
            // Con explode(';') il blocco di commenti che precede un'istruzione fa
            // parte dello stesso chunk: le migration commentate venivano quindi
            // scartate in silenzio (0 istruzioni eseguite, versione non aggiornata).
            // Ora i commenti vengono rimossi e si salta solo se non resta SQL.
            $stmt = preg_replace('/^\s*--[^\n]*(\n|$)/m', '', $stmt);
            $stmt = preg_replace('!/\*.*?\*/!s', '', $stmt);
            $stmt = trim((string)$stmt);
            if (!$stmt || preg_match('/^\s*(SELECT|SHOW)/i', $stmt)) continue;
            try {
                $pdo->exec($stmt);
                $ok++;
            } catch (\PDOException $e) {
                // Ignora errori non bloccanti (colonna già esiste, tabella già esiste)
                $msg_err = $e->getMessage();
                if (strpos($msg_err, 'Duplicate') !== false || strpos($msg_err, 'already exists') !== false) {
                    $ok++; // conta come successo
                } else {
                    $err++;
                    $r['sql_errors'][] = "$sql_file: " . mb_substr($msg_err, 0, 120);
                }
            }
        }
        $r['sql_executed'][] = ['file' => $sql_file, 'ok' => $ok, 'errors' => $err];
    }

    // ── Step 5: Esegui db_upgrade.php logic se presente ──────────────────────
    // Ricarica il file aggiornato ed esegui le migrazioni mancanti
    if (file_exists($appRoot . '/db_upgrade.php')) {
        // Tenta di eseguire le migrazioni tramite il nuovo db_upgrade
        // Non includiamo il file (ha UI), ma eseguiamo i SQL blocks direttamente
        try {
            $new_ver = $r['new_version'] ?: $currentVer;
            // v1.7.61: allinea TUTTE le chiavi di versione, non solo app_version.
            // Prima venivano aggiornate solo app_version, lasciando schema_version
            // e release_label ferme alla release precedente.
            $st_ver = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $st_ver->execute(['app_version',    $new_ver, 'Versione applicazione']);
            $st_ver->execute(['schema_version', $new_ver, 'Versione schema database']);
            $st_ver->execute(['release_label',  $new_ver, 'Etichetta release mostrata in footer']);
        } catch (\Exception $e) {}
    }

    // ── Step 6: Pulizia ──────────────────────────────────────────────────────
    @unlink($zipPath);
    if (is_dir($tempDir)) { array_map('unlink', glob($tempDir . '*')); @rmdir($tempDir); }

    // Log
    write_log('System', 'success', "Update da v{$currentVer} a v" . ($r['new_version'] ?: '?') . ": {$r['files_updated']} aggiornati, {$r['files_added']} nuovi, " . count($r['sql_executed']) . " SQL", $userId);

    $r['duration'] = round(microtime(true) - $t_start, 2);
    return $r;
}

