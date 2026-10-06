<?php
/**
 * PortalManager — cron_soc_sync.php (v1.10.07)
 * Innesco da riga di comando della pipeline unica del Service SOC (app/SocSync.php): cartella di arrivo + DB SOC +
 * ricostruzione ticket + abbinamenti + Unità Organizzativa SOC. Stesso lock e stesso registro dello scheduler del portale.
 *
 *   php cron_soc_sync.php [--force] [--quiet]
 *     senza --force esegue solo se «è il momento» (sincronizzazione attiva e intervallo trascorso)
 *
 * Utilità di pianificazione di Windows (alternativa allo scheduler applicativo):
 *   schtasks /Create /SC MINUTE /MO 30 /TN "PortalManager - Service SOC" /TR "\"P:\xampp\php\php.exe\" \"P:\xampp\htdocs\<portale>\cron_soc_sync.php\" --quiet" /RU SYSTEM
 *
 * Codici di uscita: 0 ok o non dovuta · 1 errore
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Questo script si esegue solo da riga di comando.\n"); }
@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$opt = $argv ?? [];
$quiet = in_array('--quiet', $opt, true);
$say = static function (string $m) use ($quiet): void { if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $m . PHP_EOL); };

if (!defined('APP_BASE')) define('APP_BASE', __DIR__);
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/SocSync.php';

$r = SocSync::run($pdo, 'pianificata', in_array('--force', $opt, true), null, $say);
exit($r['ok'] ? 0 : 1);
