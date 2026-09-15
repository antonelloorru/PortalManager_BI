<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.31 — Auto-patch service_desk.php
 *
 * Applica in-place:
 *  1) Include CSS/JS pm-ui-boost e meta target subito dopo require_once('header.php').
 *  2) Aggiunge class="pm-ms" (+ placeholder) ai 4 <select> del blocco filtri:
 *     tec, queue, level, gest.
 *  3) data-no-reorder su level e gest (label composite non anagrafiche).
 *
 * Idempotente (marker PM_V1_9_31_APPLIED). Backup + validazione php -l + rollback.
 *
 * Uso:
 *   php tools\apply_v1_9_31_patch.php                          (default ..\service_desk.php)
 *   php tools\apply_v1_9_31_patch.php <path\service_desk.php>
 *   php tools\apply_v1_9_31_patch.php <path> --dry-run
 */

$dry = false; $target = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else $target = $a;
}
$target = $target ?: realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'service_desk.php';

if (!is_file($target)) { fwrite(STDERR, "[ERRORE] File non trovato: $target\n"); exit(1); }
$src = file_get_contents($target);
if ($src === false) { fwrite(STDERR, "[ERRORE] Lettura fallita\n"); exit(1); }

if (strpos($src, 'PM_V1_9_31_APPLIED') !== false) {
    echo "[SKIP] Patch v1.9.31 gia' applicata a $target\n"; exit(0);
}
echo "→ Target: $target (" . strlen($src) . " byte)\n";

$new = $src;
$changes = [];

// ── FIX 1: include CSS/JS/meta subito dopo require_once('header.php'); ─
$includeBlock = "\n// [PM_V1_9_31_APPLIED] UI boost: multi-select con search + label Cognome Nome\n"
              . "if (!isset(\$GLOBALS['__pm_ui_boost_v1931'])) {\n"
              . "    \$GLOBALS['__pm_ui_boost_v1931'] = true;\n"
              . "    echo '<link rel=\"stylesheet\" href=\"assets/css/pm-ui-boost.css\">' . \"\\n\";\n"
              . "    echo '<script src=\"assets/js/pm-ui-boost.js\" defer></script>' . \"\\n\";\n"
              . "    echo '<meta name=\"pm-ui-boost\" content=\\'form select[name=\"tec\"], form select[name=\"queue\"], form select[name=\"level\"], form select[name=\"gest\"]\\'>' . \"\\n\";\n"
              . "}\n";
if (preg_match('/(require_once\s*\(\s*[\'"]header\.php[\'"]\s*\)\s*;)/', $new, $m, PREG_OFFSET_CAPTURE)) {
    $pos = $m[0][1] + strlen($m[0][0]);
    $new = substr($new, 0, $pos) . $includeBlock . substr($new, $pos);
    $line = substr_count(substr($src, 0, $pos), "\n") + 1;
    $changes[] = "FIX 1: include CSS/JS/meta inserito dopo require_once('header.php') (riga $line)";
} else {
    $changes[] = "FIX 1: require_once('header.php') non trovato → skip (aggiungi manualmente).";
}

// ── FIX 2: class="pm-ms" + placeholder ai 4 select del blocco filtri ───
$sub = [
    ['tec',   'form-group"><label>Componente del team</label>',   '— tutta la squadra —', false],
    ['queue', 'form-group"><label>Coda</label>',                  '— tutte —',            false],
    ['level', 'form-group"><label>Livello coinvolto</label>',     '— tutti —',            true ],
    ['gest',  'form-group"><label>Classe di gestione</label>',    '— tutte —',            true ],
];
foreach ($sub as [$name, $anchor, $ph, $noReorder]) {
    $rx = '/(<div class="' . preg_quote($anchor, '/') . ')(\s*)(<select\s+name="' . preg_quote($name, '/') . '")(?!\s+class="pm-ms")/';
    $extra = ' class="pm-ms" data-placeholder="' . htmlspecialchars($ph, ENT_QUOTES) . '"'
           . ($noReorder ? ' data-no-reorder' : '');
    $replaced = 0;
    $new = preg_replace_callback($rx, function ($m) use ($extra, &$replaced) {
        $replaced++;
        return $m[1] . $m[2] . $m[3] . $extra;
    }, $new, 1);
    $changes[] = "FIX 2 [$name]: " . ($replaced ? "class/pm-ms aggiunta" : "select non trovata (già patched?)");
}

if ($new === $src) { echo "[NO-OP] Nessuna modifica.\n"; exit(0); }

echo "\nModifiche:\n"; foreach ($changes as $c) echo "  - $c\n";
if ($dry) { echo "\n[DRY-RUN] File NON scritto.\n"; exit(0); }

$bak = $target . '.bak_v1_9_31_' . date('Ymd_His');
if (!copy($target, $bak)) { fwrite(STDERR, "[ERRORE] Backup fallito\n"); exit(1); }
echo "→ Backup: $bak\n";

$tmp = $target . '.tmp_v1_9_31';
if (file_put_contents($tmp, $new) === false) { fwrite(STDERR, "[ERRORE] Scrittura fallita\n"); exit(1); }
if (!rename($tmp, $target)) { fwrite(STDERR, "[ERRORE] Rename fallito\n"); exit(1); }

$lint = shell_exec('php -l ' . escapeshellarg($target) . ' 2>&1');
if (strpos((string)$lint, 'No syntax errors') === false) {
    fwrite(STDERR, "[ROLLBACK] Sintassi non valida. Ripristino backup.\n$lint\n");
    copy($bak, $target);
    exit(2);
}

echo "[OK] Patch v1.9.31 applicata. php -l pulito.\n";
echo "     Backup: $bak\n";
echo "\nRicordati di:\n";
echo " - Copiare assets/js/pm-ui-boost.js e assets/css/pm-ui-boost.css in webroot\n";
echo " - Riavviare Apache o svuotare OPcache: net stop Apache2.4 ; net start Apache2.4\n";
exit(0);
