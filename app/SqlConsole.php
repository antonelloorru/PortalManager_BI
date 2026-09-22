<?php
// app/SqlConsole.php — tokenizer e classificatore SQL, estratti da sql_runner.php (v1.7.70)
// Comment/quote-aware: usati sia da sql_runner.php sia dalla console unificata.
function sql_split_statements(string $sql): array {
    // Rimuovo BOM
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") $sql = substr($sql, 3);

    $statements = [];
    $current = '';
    $in_string = false;
    $string_char = '';
    $in_comment_line = false;
    $in_comment_block = false;
    $delimiter = ';';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // Commento riga --
        if (!$in_string && !$in_comment_block && !$in_comment_line && $ch === '-' && $next === '-') {
            $in_comment_line = true;
            $current .= $ch;
            $i++;
            continue;
        }
        // Commento blocco /* */
        if (!$in_string && !$in_comment_line && !$in_comment_block && $ch === '/' && $next === '*') {
            $in_comment_block = true;
            $current .= $ch;
            $i++;
            continue;
        }
        if ($in_comment_line && $ch === "\n") {
            $in_comment_line = false;
        }
        if ($in_comment_block && $ch === '*' && $next === '/') {
            $in_comment_block = false;
            $current .= $ch . $next;
            $i += 2;
            continue;
        }
        if ($in_comment_line || $in_comment_block) {
            $current .= $ch;
            $i++;
            continue;
        }
        // Stringhe ' " `
        if (!$in_string && ($ch === "'" || $ch === '"' || $ch === '`')) {
            $in_string = true;
            $string_char = $ch;
            $current .= $ch;
            $i++;
            continue;
        }
        if ($in_string) {
            // Escape \X o doppio carattere quote
            if ($ch === '\\' && $next !== '') {
                $current .= $ch . $next;
                $i += 2;
                continue;
            }
            if ($ch === $string_char) {
                $in_string = false;
            }
            $current .= $ch;
            $i++;
            continue;
        }

        // DELIMITER personalizzato (es. per stored procedures)
        if ($delimiter === ';' && stripos(ltrim($current), 'DELIMITER ') === 0 && $ch === "\n") {
            $line = trim(substr($current, stripos($current, 'DELIMITER ') + 10));
            if ($line !== '') {
                $delimiter = $line;
                $current = '';
                $i++;
                continue;
            }
        }

        // Termine statement
        $delim_len = strlen($delimiter);
        if (substr($sql, $i, $delim_len) === $delimiter) {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = '';
            $i += $delim_len;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    // Ultimo statement (senza ;)
    $stmt = trim($current);
    if ($stmt !== '') $statements[] = $stmt;

    // Filtra statement vuoti o solo commenti
    return array_values(array_filter($statements, function ($s) {
        // Rimuovi commenti riga -- ... \n (NON multiline: il \n termina il commento)
        $clean = preg_replace('/--[^\n]*/', '', $s);
        // Rimuovi commenti blocco /* ... */ (s flag: il . matcha anche newline qui)
        $clean = preg_replace('!/\*.*?\*/!s', '', $clean);
        return trim($clean) !== '';
    }));
}

function sql_classify(string $stmt): array {
    $first = strtoupper(substr(ltrim($stmt), 0, 30));
    $cls = 'OTHER';
    $danger = false;
    if (strpos($first, 'DROP TABLE') === 0)       { $cls = 'DROP TABLE';      $danger = true; }
    elseif (strpos($first, 'DROP DATABASE') === 0){ $cls = 'DROP DATABASE';   $danger = true; }
    elseif (strpos($first, 'TRUNCATE') === 0)      { $cls = 'TRUNCATE';        $danger = true; }
    elseif (strpos($first, 'DELETE FROM') === 0)   { $cls = 'DELETE';          $danger = true; }
    elseif (strpos($first, 'CREATE TABLE') === 0)  { $cls = 'CREATE TABLE'; }
    elseif (strpos($first, 'ALTER TABLE') === 0)   { $cls = 'ALTER TABLE'; }
    elseif (strpos($first, 'INSERT') === 0)        { $cls = 'INSERT'; }
    elseif (strpos($first, 'UPDATE') === 0)        { $cls = 'UPDATE'; }
    elseif (strpos($first, 'SELECT') === 0)        { $cls = 'SELECT'; }
    elseif (strpos($first, 'SET ') === 0)          { $cls = 'SET'; }
    elseif (strpos($first, 'PREPARE') === 0)       { $cls = 'PREPARE'; }
    elseif (strpos($first, 'EXECUTE') === 0)       { $cls = 'EXECUTE'; }
    elseif (strpos($first, 'DEALLOCATE') === 0)    { $cls = 'DEALLOCATE'; }
    return ['class' => $cls, 'danger' => $danger];
}
