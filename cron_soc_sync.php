<?php
/**
 * PortalManager — cron_soc_sync.php (v1.10.06)
 * Service SOC: sincronizzazione pianificata degli eventi ticket dal DB del sistema di gestione SOC.
 *
 *   php cron_soc_sync.php [--days=N] [--force] [--quiet]
 *     --days=N  finestra in giorni (predefinita: quella della connessione; 0 = tutto)
 *     --force   esegue anche con la sincronizzazione pianificata disattivata
 *
 * Utilità di pianificazione di Windows (ogni 30 minuti):
 *   schtasks /Create /SC MINUTE /MO 30 /TN "PortalManager - Service SOC" /TR "\"P:\xampp\php\php.exe\" \"P:\xampp\htdocs\<portale>\cron_soc_sync.php\" --quiet" /RU SYSTEM
 *
 * Codici di uscita: 0 ok · 1 errore di sincronizzazione · 2 disattivata o non configurata
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Questo script si esegue solo da riga di comando.\n"); }
@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$opt = $argv ?? [];
$quiet = in_array('--quiet', $opt, true);
$days = null;
foreach ($opt as $o) if (preg_match('/^--days=(\d+)$/', $o, $m)) $days = (int)$m[1];
$say = static function (string $m) use ($quiet): void { if (!$quiet) fwrite(STDOUT, date('[Y-m-d H:i:s] ') . $m . PHP_EOL); };

if (!defined('APP_BASE')) define('APP_BASE', __DIR__);
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/app/Env.php';
require_once __DIR__ . '/app/SocIngest.php';

$on = (string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'soc.sync_enabled'")->fetchColumn();
if ($on !== '1' && !in_array('--force', $opt, true)) { $say('Sincronizzazione pianificata del Service SOC disattivata.'); exit(2); }
if (!(int)$pdo->query("SELECT COUNT(*) FROM cm_soc_source_db WHERE is_active = 1")->fetchColumn()) { $say('Connessione al DB SOC non configurata o disattiva.'); exit(2); }

$r = (new SocIngest($pdo))->importDb(null, 'pianificata', $days);
$say(($r['ok'] ? 'OK  ' : 'ERR ') . $r['message']);
exit($r['ok'] ? 0 : 1);
