<?php
/**
 * cron_alerts.php — rilevazione e invio degli alert sulle commesse.
 *
 * Invocato dall'Utilita' di pianificazione di Windows:
 *
 *   P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_alerts.php
 *
 * Una volta al giorno e' sufficiente: le soglie si superano nell'arco di giorni,
 * non di ore, e la firma impedisce comunque il reinvio a parita' di condizione.
 *
 * OPZIONI
 *   --solo-rileva   rileva e registra, non invia
 *   --solo-invia    invia gli eventi gia' rilevati e non ancora spediti
 *   --dry-run       simula l'invio: registra come "simulata" senza spedire
 *   --quiet         nessun output a schermo
 *
 * CODICI DI USCITA
 *   0  eseguito, oppure niente da fare
 *   1  eseguito con errori di invio
 *   2  errore di configurazione o di connessione
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo script si esegue solo da riga di comando.\n");
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

$opt        = $argv ?? [];
$soloRileva = in_array('--solo-rileva', $opt, true);
$soloInvia  = in_array('--solo-invia', $opt, true);
$dryRun     = in_array('--dry-run', $opt, true);
$quiet      = in_array('--quiet', $opt, true);

$say = function (string $m) use ($quiet): void {
    if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $m . PHP_EOL);
};
$fail = function (string $m, int $c) use ($quiet): void {
    if (!$quiet) fwrite(STDERR, date('[Y-m-d H:i:s] ') . 'ERRORE: ' . $m . PHP_EOL);
    exit($c);
};

require_once(__DIR__ . '/app/Version.php');
require_once(__DIR__ . '/app/Db.php');
require_once(__DIR__ . '/app/AlertEngine.php');

try { $pdo = Db::pdo(); }
catch (Throwable $e) { $fail('connessione al database non riuscita: ' . $e->getMessage(), 2); }

try {
    $ae = new AlertEngine($pdo);
    $st = $ae->stato();
} catch (Throwable $e) {
    $fail('tabelle di alerting assenti: eseguire la migration v1.8.95.', 2);
}

$say(sprintf('Alerting: %s, %s. Regole attive: %d.',
    $st['attivo'] ? 'attivo' : 'DISATTIVATO',
    ($dryRun || $st['dry_run']) ? 'modalita prova' : 'invio reale',
    (int)($st['regole_attive'] ?? 0)));

$errori = 0;

// ── rilevazione ─────────────────────────────────────────────────────────────
if (!$soloInvia) {
    try {
        $r = $ae->rileva(false);
        $say(sprintf('Rilevazione: %d condizioni correnti — %d nuove, %d gia note, %d chiuse.',
            $r['correnti'] ?? 0, $r['nuovi'], $r['gia_noti'], $r['chiusi']));
        if (($r['gia_noti'] ?? 0) > 0) {
            $say(sprintf('  %d condizioni erano gia state segnalate e non generano un nuovo invio.',
                $r['gia_noti']));
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
        $fail('rilevazione fallita: ' . $e->getMessage(), 2);
    }
}

// ── invio ───────────────────────────────────────────────────────────────────
if (!$soloRileva) {
    try {
        $i = $ae->invia($dryRun ? true : null);
        $say(sprintf('Invio: %s — %d messaggi a %d destinatari, %d errori (%d eventi).',
            $i['stato'], $i['inviate'], $i['destinatari'] ?? 0, $i['errori'], $i['eventi']));
        $errori = (int)$i['errori'];

        if ($i['stato'] === 'nessun destinatario configurato') {
            $say('  Configurare gli indirizzi in cm_alert_recipients e alert_director_email.');
        }
        if ($i['stato'] === 'disattivato') {
            $say('  L invio e disattivato: impostare alert_enabled a 1 per spedire davvero.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i2) {} }
        $fail('invio fallito: ' . $e->getMessage(), 2);
    }
}

exit($errori === 0 ? 0 : 1);
