<?php
/**
 * PortalManager — cron_sync.php  (v1.9.70)
 *
 * OPZIONALE. Dalla v1.9.70 la sincronizzazione giornaliera è gestita dal portale
 * senza cron (modalità "cronless", vedi app/CronlessScheduler.php). Questo script
 * resta per chi preferisce un innesco esterno (modalità "os_task") o per esecuzioni
 * manuali da riga di comando. Usa la stessa logica (app/SyncRunner.php) e lo stesso
 * lock: le due modalità non producono mai esecuzioni doppie.
 *
 *   php cron_sync.php [--force] [--dry-run] [--quiet]
 *
 * Codici di uscita: 0 ok / non era il momento · 1 errori su dataset · 2 configurazione/connessione
 *
 * v1.9.70: corretto l'include di app/Db.php (file inesistente → errore fatale a ogni
 * esecuzione): la connessione è quella di Config.php.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Questo script si esegue solo da riga di comando.\n");
}
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$opt    = $argv ?? [];
$force  = in_array('--force', $opt, true);
$dryRun = in_array('--dry-run', $opt, true);
$quiet  = in_array('--quiet', $opt, true);
$say = function (string $m) use ($quiet): void { if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $m . PHP_EOL); };

if (!defined('APP_BASE')) define('APP_BASE', __DIR__);
require_once __DIR__ . '/Config.php';          // $pdo (in CLI un errore DB termina lo script)
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/SyncRunner.php';

$res = SyncRunner::run($pdo, $force ? 'manuale' : 'pianificata', $force, $dryRun, $say);
exit($res['code']);
