<?php
/**
 * PortalManager — cron_wp_ats.php (v1.10.05)
 * Sincronizzazione pianificata con il sito WordPress (plugin pm-ats): invio posizioni + prelievo candidature.
 *
 *   php cron_wp_ats.php [--push] [--pull] [--test] [--force] [--quiet]
 *     senza --push/--pull/--test: entrambe le operazioni
 *     --force: esegue anche con sincronizzazione disattivata
 *
 * Utilità di pianificazione di Windows (ogni 15 minuti):
 *   schtasks /Create /SC MINUTE /MO 15 /TN "PortalManager - Sito web" /TR "\"P:\xampp\php\php.exe\" \"P:\xampp\htdocs\<portale>\cron_wp_ats.php\" --quiet" /RU SYSTEM
 *
 * Codici di uscita: 0 ok · 1 errori in sincronizzazione · 2 configurazione assente/disattivata
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Questo script si esegue solo da riga di comando.\n"); }
@set_time_limit(0);

$opt = $argv ?? [];
$quiet = in_array('--quiet', $opt, true);
$say = static function (string $m) use ($quiet): void { if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $m . PHP_EOL); };

if (!defined('APP_BASE')) define('APP_BASE', __DIR__);
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/Version.php';
require_once __DIR__ . '/app/WpAtsSync.php';

$cfg = WpAtsClient::settings($pdo);
if ($cfg['wpats.enabled'] !== '1' && !in_array('--force', $opt, true)) { $say('Sincronizzazione con il sito disattivata.'); exit(2); }
$sync = new WpAtsSync($pdo, null, $quiet ? null : Closure::fromCallable($say));
if (!$sync->configured()) { $say('Configurazione incompleta: URL, client ID e PM_WPATS_SECRET in .env.php.'); exit(2); }

$doTest = in_array('--test', $opt, true);
$doPush = in_array('--push', $opt, true);
$doPull = in_array('--pull', $opt, true);
if (!$doTest && !$doPush && !$doPull) { $doPush = $doPull = true; }

$ok = true;
foreach ([[$doTest, 'test'], [$doPush, 'pushJobs'], [$doPull, 'pullApplications']] as [$on, $fn]) {
    if (!$on) continue;
    $r = $sync->$fn(null, 'pianificata');
    $say(($r['ok'] ? 'OK  ' : 'ERR ') . $r['message']);
    $ok = $ok && $r['ok'];
}
exit($ok ? 0 : 1);
