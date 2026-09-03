<?php
declare(strict_types=1);
/**
 * PortalManager v1.9.25 — Auto-patch employee_profile.php
 *
 * Corregge "Undefined variable $emp" dopo POST:
 *   FIX 1 — PRG: chiude il ramo POST con header('Location') + exit.
 *   FIX 2 — Guard: se $emp non è array, lo ricarica da employees prima del render.
 *
 * Idempotente (marker `PM_V1_9_25_APPLIED`). Backup automatico + validazione php -l.
 *
 * Uso:
 *   php tools/apply_v1_9_25_patch.php                (target ../employee_profile.php)
 *   php tools/apply_v1_9_25_patch.php <file>
 *   php tools/apply_v1_9_25_patch.php <file> --dry-run
 */

$dry = false;
$target = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    else $target = $a;
}
$target = $target ?: realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'employee_profile.php';

if (!is_file($target)) {
    fwrite(STDERR, "[ERRORE] File non trovato: $target\n");
    exit(1);
}

$src = file_get_contents($target);
if ($src === false) { fwrite(STDERR, "[ERRORE] Lettura fallita\n"); exit(1); }

if (strpos($src, 'PM_V1_9_25_APPLIED') !== false) {
    echo "[SKIP] Patch già applicata a $target\n";
    exit(0);
}

echo "→ Target: $target (" . strlen($src) . " byte)\n";

$new = $src;
$changes = [];

/* ============ FIX 1 — PRG dopo il ramo POST ============ */
$prgBlock = <<<'PHP'

    // [PM_V1_9_25_APPLIED] PRG: elimina "Undefined variable $emp" e doppio-submit.
    $__pm_eid = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($__pm_eid > 0 && !headers_sent()) {
        header('Location: employee_profile.php?id=' . $__pm_eid . '&saved=1');
        exit;
    }
PHP;

// Cerca uno tra: if($_SERVER['REQUEST_METHOD']==='POST'), if((...REQUEST_METHOD... ?? '')==='POST'),
// $_POST-driven, etc. Tollera parentesi extra e null-coalesce.
if (preg_match('/\bif\s*\(([^{}\n]{0,200}REQUEST_METHOD[^{}\n]{0,200}POST[^{}\n]{0,200})\)\s*\{/i',
               $new, $m, PREG_OFFSET_CAPTURE)) {
    $openBrace = $m[0][1] + strlen($m[0][0]) - 1; // posizione del '{'
    $close = pm_find_matching_brace($new, $openBrace);
    if ($close !== false && strpos(substr($new, $openBrace, $close - $openBrace), 'PM_V1_9_25_APPLIED') === false) {
        $new = substr($new, 0, $close) . $prgBlock . "\n" . substr($new, $close);
        $changes[] = "FIX 1 — PRG inserito a fine ramo POST (offset $close)";
    }
} else {
    $changes[] = "FIX 1 — ramo POST non individuato; skip (verrà applicato solo FIX 2).";
}

/* ============ FIX 2 — Guard $emp prima del primo uso ============ */
$guardBody = <<<'PHP'
// [PM_V1_9_25_APPLIED] Guard difensivo: garantisce $emp valorizzato.
if (!isset($emp) || !is_array($emp)) {
    $__pm_eid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $emp = [];
    if ($__pm_eid > 0 && isset($pdo) && $pdo instanceof PDO) {
        try {
            $__pm_stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
            $__pm_stmt->execute([$__pm_eid]);
            $emp = $__pm_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $__pm_e) { $emp = []; }
    }
}
PHP;

// Trova il PRIMO uso di $emp[
if (preg_match('/\$emp\s*\[/', $new, $m, PREG_OFFSET_CAPTURE)) {
    $pos = $m[0][1];
    $lineStart = strrpos(substr($new, 0, $pos), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;

    $inHtml = pm_is_html_context($new, $lineStart);
    $insertion = $inHtml
        ? "<?php\n" . $guardBody . "\n?>\n"
        : $guardBody . "\n";

    $new = substr($new, 0, $lineStart) . $insertion . substr($new, $lineStart);
    $changes[] = "FIX 2 — Guard \$emp inserito a offset $lineStart (contesto " . ($inHtml ? "HTML" : "PHP") . ")";
} else {
    $changes[] = "FIX 2 — nessun uso di \$emp[…]; guard non inserito.";
}

/* ============ OUTPUT ============ */
if ($new === $src) {
    echo "[NO-OP] Nessuna modifica.\n";
    exit(0);
}

echo "\nModifiche:\n";
foreach ($changes as $c) echo "  - $c\n";

if ($dry) {
    echo "\n[DRY-RUN] File NON scritto.\n";
    exit(0);
}

$bak = $target . '.bak_v1_9_25_' . date('Ymd_His');
if (!copy($target, $bak)) { fwrite(STDERR, "[ERRORE] Backup fallito\n"); exit(1); }
echo "→ Backup: $bak\n";

$tmp = $target . '.tmp_v1_9_25';
if (file_put_contents($tmp, $new) === false) { fwrite(STDERR, "[ERRORE] Scrittura fallita\n"); exit(1); }
if (!rename($tmp, $target)) { fwrite(STDERR, "[ERRORE] Rename fallito\n"); exit(1); }

$lint = shell_exec('php -l ' . escapeshellarg($target) . ' 2>&1');
if (strpos((string)$lint, 'No syntax errors') === false) {
    fwrite(STDERR, "[ROLLBACK] Sintassi non valida. Ripristino backup.\n$lint\n");
    copy($bak, $target);
    exit(2);
}

echo "[OK] Patch v1.9.25 applicata. php -l pulito.\n";
echo "     Rollback manuale: copy \"$bak\" \"$target\"\n";
exit(0);

/* ---------------- helpers ---------------- */

/** Ritorna true se la posizione $pos si trova in modalità HTML (fuori da <?php ... ?>). */
function pm_is_html_context(string $s, int $pos): bool
{
    $slice = substr($s, 0, $pos);
    $openTag  = strrpos($slice, '<?');
    $closeTag = strrpos($slice, '?>');
    // Se non c'e mai stato un tag di apertura, oppure l'ultimo di chiusura viene DOPO l'ultimo di apertura, siamo in HTML.
    if ($openTag === false) return true;
    if ($closeTag !== false && $closeTag > $openTag) return true;
    return false;
}

/** Trova la graffa } corrispondente alla { in posizione $openBracePos. */
function pm_find_matching_brace(string $s, int $openBracePos): int|false
{
    $len = strlen($s);
    if ($openBracePos < 0 || $openBracePos >= $len || $s[$openBracePos] !== '{') return false;
    $depth = 0; $inStr = false; $q = ''; $lc = false; $bc = false;
    for ($i = $openBracePos; $i < $len; $i++) {
        $c = $s[$i]; $nx = $s[$i + 1] ?? '';
        if ($lc) { if ($c === "\n") $lc = false; continue; }
        if ($bc) { if ($c === '*' && $nx === '/') { $bc = false; $i++; } continue; }
        if ($inStr) {
            if ($c === '\\') { $i++; continue; }
            if ($c === $q) $inStr = false;
            continue;
        }
        if ($c === '/' && $nx === '/') { $lc = true; $i++; continue; }
        if ($c === '#') { $lc = true; continue; }
        if ($c === '/' && $nx === '*') { $bc = true; $i++; continue; }
        if ($c === '"' || $c === "'") { $inStr = true; $q = $c; continue; }
        if ($c === '{') $depth++;
        elseif ($c === '}') { $depth--; if ($depth === 0) return $i; }
    }
    return false;
}
