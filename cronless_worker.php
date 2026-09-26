<?php
/**
 * PortalManager — cronless_worker.php  (v1.9.70)
 *
 * Worker della sincronizzazione pianificata senza cron. Viene invocato SOLO dal
 * portale stesso (CronlessScheduler::dispatch) con una richiesta interna firmata.
 *
 * Sicurezza:
 *  - solo POST con firma HMAC-SHA256 (segreto in .env.php) e scadenza di 5 minuti;
 *  - richieste non firmate → 403 senza alcuna elaborazione;
 *  - un replay entro la scadenza è innocuo: SyncRunner esegue solo se "è il momento"
 *    e sotto lock (mai due esecuzioni, mai due volte nello stesso giorno), salvo
 *    avvio manuale firmato con force=1 (generato solo da un utente autorizzato).
 *
 * Non include bootstrap/access_control: nessuna sessione, nessun redirect al login.
 */
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

if (!defined('APP_BASE')) define('APP_BASE', __DIR__);
require_once __DIR__ . '/Config.php';            // $pdo
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/SyncRunner.php';
require_once __DIR__ . '/app/CronlessScheduler.php';

$ts    = (string)($_POST['ts'] ?? '');
$force = (string)($_POST['force'] ?? '0');
$sig   = (string)($_POST['sig'] ?? '');
$task  = (string)($_POST['task'] ?? 'sync');     // v1.9.73: 'sync' | 'snapshot'

if (!CronlessScheduler::verify($ts, $force, $sig, $task)) {
    http_response_code(403);
    exit;
}

// Risposta immediata al chiamante, poi elaborazione in background
ignore_user_abort(true);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$out = 'accepted';
http_response_code(202);
header('Content-Type: text/plain');
header('Content-Length: ' . strlen($out));
header('Connection: close');
echo $out;
if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

if ($task === 'snapshot') {
    require_once __DIR__ . '/app/PmSnapshot.php';
    PmSnapshot::refresh($pdo, $force === '1');     // copie delle viste lente (v1.9.73)
} else {
    SyncRunner::run($pdo, 'cronless', $force === '1');
}
