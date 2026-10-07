<?php
/**
 * app/WpAtsConfig.php — v1.10.14
 * Configurazione della connessione al plugin WordPress pm-ats, condivisa da:
 *   wp_ats_setup.php    configurazione guidata (prerequisiti → connessione → test → opzioni → primo invio)
 *   wp_ats_settings.php pagina impostazioni (modifica manuale, test, versioni e compatibilità)
 * - codice di connessione del plugin «PMATS1.<base64url JSON {v,url,client,secret,plugin,api,site}>»;
 * - salvataggio validato delle impostazioni wpats.* (app_settings) e del segreto (SOLO .env.php: PM_WPATS_SECRET);
 * - compatibilità di versione: plugin ≥ PLUGIN_MIN, protocollo API = API_VERSION;
 * - stato della configurazione guidata (wpats.setup_done / setup_at / setup_step) e info del sito (wpats.remote_info).
 */
declare(strict_types=1);

require_once __DIR__ . '/WpAtsClient.php';
require_once __DIR__ . '/Env.php';

final class WpAtsConfig
{
    /** Plugin minimo per tutte le funzioni di questa versione di PortalManager (wizard, versioni). */
    public const PLUGIN_MIN = '1.1.0';
    /** Plugin minimo funzionante (sincronizzazione base). */
    public const PLUGIN_BASE = '1.0.0';
    /** v1.10.16 — plugin consigliato: la 1.1.0 conta come fallimenti anche le chiamate riuscite (blocco 429 dopo 20 chiamate). */
    public const PLUGIN_RECOMMENDED = '1.3.3';   // v1.10.22: titolo della pagina del tema opzionale/personalizzabile (v1.10.21: nessuna barra laterale)
    /** Protocollo REST supportato (pm-ats/v1). */
    public const API_VERSION = '1';

    public static function setting(PDO $pdo, string $k, string $d = ''): string
    {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?"); $st->execute([$k]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? $d : (string)$v;
        } catch (Throwable $e) { return $d; }
    }

    public static function set(PDO $pdo, array $kv): void
    {
        $st = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($kv as $k => $v) $st->execute([$k, (string)$v]);
    }

    /**
     * Decodifica il codice di connessione del plugin. null se non valido.
     * @return array{url:string,client:string,secret:string,plugin:string,api:string,site:string}|null
     */
    public static function parseCode(string $code): ?array
    {
        $code = trim(preg_replace('/\s+/', '', $code));
        if (!str_starts_with($code, 'PMATS1.')) return null;
        $b = strtr(substr($code, 7), '-_', '+/');
        $j = json_decode((string)base64_decode($b . str_repeat('=', (4 - strlen($b) % 4) % 4), true), true);
        if (!is_array($j) || (int)($j['v'] ?? 0) !== 1) return null;
        $url = WpAtsClient::normalizeBase((string)($j['url'] ?? ''));
        $sec = (string)($j['secret'] ?? '');
        $cid = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($j['client'] ?? ''));
        if ($url === null || !preg_match('/^[A-Za-z0-9]{32,128}$/', $sec) || $cid === '') return null;
        return ['url' => $url, 'client' => $cid, 'secret' => $sec, 'plugin' => (string)($j['plugin'] ?? ''), 'api' => (string)($j['api'] ?? ''), 'site' => (string)($j['site'] ?? '')];
    }

    /**
     * Valida e salva. $in: base_url, client_id, verify_tls, ca_file, proxy, timeout, push_on_change, pull_batch, enabled (chiavi assenti = invariate).
     * $secret: nuovo segreto ('' = invariato); $clearSecret: rimuove il segreto. @return string[] errori (vuoto = salvato)
     */
    public static function save(PDO $pdo, array $in, string $secret = '', bool $clearSecret = false): array
    {
        $cur = WpAtsClient::settings($pdo);
        $err = []; $kv = [];
        if (array_key_exists('base_url', $in)) {
            $url = trim((string)$in['base_url']);
            $norm = $url === '' ? '' : (WpAtsClient::normalizeBase($url) ?? '');
            if ($url !== '' && $norm === '') $err[] = 'URL non valido';
            // HTTPS obbligatorio (segreto e CV viaggiano su questa connessione); HTTP solo verso questo stesso computer (prove)
            if ($norm !== '' && stripos($norm, 'https://') !== 0 && !preg_match('~^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?/~i', $norm)) $err[] = 'L\'URL deve essere HTTPS';
            $kv['wpats.base_url'] = $norm;
        }
        if (array_key_exists('client_id', $in)) $kv['wpats.client_id'] = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$in['client_id']) ?: 'portalmanager';
        if (array_key_exists('ca_file', $in)) {
            $ca = trim((string)$in['ca_file']);
            if ($ca !== '' && !is_file($ca)) $err[] = 'File CA non trovato: ' . $ca;
            $kv['wpats.ca_file'] = $ca;
        }
        if (array_key_exists('resolve_ip', $in)) {   // v1.10.17
            $ri = trim((string)$in['resolve_ip']);
            if ($ri !== '' && !filter_var($ri, FILTER_VALIDATE_IP)) $err[] = 'IP forzato non valido';
            $kv['wpats.resolve_ip'] = $ri;
        }
        if (array_key_exists('proxy', $in)) {
            $proxy = trim((string)$in['proxy']);
            if ($proxy !== '' && !preg_match('#^(https?://)?[A-Za-z0-9.\-\[\]:]+(:\d{2,5})?$#', $proxy)) $err[] = 'Proxy non valido';
            $kv['wpats.proxy'] = $proxy;
        }
        foreach (['verify_tls', 'push_on_change', 'enabled'] as $k) if (array_key_exists($k, $in)) $kv['wpats.' . $k] = empty($in[$k]) ? '0' : '1';
        if (array_key_exists('timeout', $in)) $kv['wpats.timeout'] = (string)max(5, min(120, (int)$in['timeout']));
        if (array_key_exists('pull_batch', $in)) $kv['wpats.pull_batch'] = (string)max(1, min(100, (int)$in['pull_batch']));
        if ($secret !== '' && !preg_match('/^[A-Za-z0-9]{32,128}$/', $secret)) $err[] = 'Segreto non valido (32-128 caratteri alfanumerici)';
        $hasSecret = $secret !== '' || (!$clearSecret && WpAtsClient::secret() !== '');
        $enabled = $kv['wpats.enabled'] ?? $cur['wpats.enabled'];
        if ($enabled === '1' && ((($kv['wpats.base_url'] ?? $cur['wpats.base_url']) === '') || !$hasSecret)) $err[] = 'Per attivare servono URL e segreto';
        if ($err) return $err;
        if ($secret !== '' && !Env::persist(['PM_WPATS_SECRET' => $secret])) return ['.env.php non scrivibile: segreto non salvato'];
        if ($clearSecret && $secret === '' && !Env::persist(['PM_WPATS_SECRET' => null])) return ['.env.php non scrivibile: segreto non rimosso'];
        self::set($pdo, $kv);
        return [];
    }

    /**
     * Compatibilità con la risposta di /sync/status.
     * @return array{level:string,msg:string}  level = ok | warn | ko
     */
    public static function compat(array $status): array
    {
        $pv = (string)($status['plugin'] ?? '');
        $api = (string)($status['api'] ?? '1');                  // il plugin 1.0.0 non dichiara l'API: è la v1
        if ($api !== self::API_VERSION) return ['level' => 'ko', 'msg' => "Protocollo API del plugin v$api non supportato (PortalManager usa v" . self::API_VERSION . ')'];
        if ($pv === '' || version_compare($pv, self::PLUGIN_BASE, '<')) return ['level' => 'ko', 'msg' => 'Versione del plugin non riconosciuta'];
        if (version_compare($pv, self::PLUGIN_MIN, '<'))
            return ['level' => 'warn', 'msg' => "Plugin $pv: sincronizzazione supportata; aggiornare a ≥ " . self::PLUGIN_MIN . ' per configurazione guidata e controllo versioni'];
        if (version_compare($pv, self::PLUGIN_RECOMMENDED, '<'))
            return ['level' => 'warn', 'msg' => "Plugin $pv: aggiornare a " . self::PLUGIN_RECOMMENDED . (version_compare($pv, '1.1.1', '<') ? ' (corregge il blocco 429 «too_many_failures» dopo 20 chiamate; ' : ' (') . 'ordine vincolante della Job Description, nessuna nota interna pubblicata, layout Lavora con noi)'];
        $msg = "Plugin $pv compatibile (API v$api)";
        if (($status['onboarding'] ?? 'done') !== 'done') return ['level' => 'warn', 'msg' => $msg . ' — configurazione guidata del sito non completata'];
        return ['level' => 'ok', 'msg' => $msg];
    }

    /**
     * v1.10.16 — Diagnostica passo per passo dell'handshake (WpAtsDiag), salvata in wp_ats_diag (ultime 100, nessun segreto:
     * solo l'impronta) e registrata nel log della sincronizzazione.
     */
    public static function diag(PDO $pdo, ?int $uid): array
    {
        require_once __DIR__ . '/WpAtsDiag.php';
        $d = WpAtsDiag::run($pdo);
        try {   // storico in wp_ats_diag (v1.10.16): nessun segreto, solo l'impronta
            $pdo->prepare("INSERT INTO wp_ats_diag (ok, summary, steps_json, user_id) VALUES (?, ?, ?, ?)")
                ->execute([$d['ok'] ? 1 : 0, mb_substr($d['summary'], 0, 1000), json_encode($d['steps'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $uid]);
            $pdo->exec("DELETE FROM wp_ats_diag WHERE id < (SELECT m FROM (SELECT MAX(id) - 100 AS m FROM wp_ats_diag) x)");
        } catch (Throwable $e) {}
        try {
            $pdo->prepare("INSERT INTO wp_ats_sync_log (operation, trigger_type, status, message, user_id, started_at, finished_at) VALUES ('test', 'manuale', ?, ?, ?, NOW(), NOW())")
                ->execute([$d['ok'] ? 'ok' : 'error', mb_substr('Diagnostica: ' . $d['summary'], 0, 1000), $uid]);
        } catch (Throwable $e) {}
        return $d;
    }

    public static function lastDiag(PDO $pdo): array
    {
        try {
            $r = $pdo->query("SELECT ok, summary, steps_json, created_at FROM wp_ats_diag ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
        if (!$r) return [];
        $st = json_decode((string)$r['steps_json'], true);
        return ['ok' => (bool)$r['ok'], 'summary' => (string)$r['summary'], 'steps' => is_array($st) ? $st : [], 'at' => (string)$r['created_at']];
    }

    /** Esegue il test, salva le informazioni del sito (wpats.remote_info) e la compatibilità; se fallisce esegue la diagnostica. */
    public static function test(PDO $pdo, ?int $uid): array
    {
        require_once __DIR__ . '/WpAtsSync.php';
        $r = (new WpAtsSync($pdo))->test($uid);
        if (!$r['ok'] && WpAtsClient::fromSettings($pdo)) $r['diag'] = self::diag($pdo, $uid);   // v1.10.16
        if ($r['ok'] && is_array($r['data'] ?? null)) {
            $d = $r['data']; $c = self::compat($d);
            self::set($pdo, ['wpats.remote_info' => json_encode(['plugin' => $d['plugin'] ?? '', 'api' => $d['api'] ?? '1', 'wordpress' => $d['wordpress'] ?? '', 'php' => $d['php'] ?? '',
                'site' => $d['site'] ?? '', 'onboarding' => $d['onboarding'] ?? '', 'list_url' => $d['list_url'] ?? '', 'checked_at' => date('Y-m-d H:i:s'),
                'compat' => $c['level'], 'compat_msg' => $c['msg']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            $r['compat'] = $c;
            if ($c['level'] === 'ko') $r['ok'] = false;
        }
        return $r;
    }

    public static function remoteInfo(PDO $pdo): array
    {
        $j = json_decode(self::setting($pdo, 'wpats.remote_info', ''), true);
        return is_array($j) ? $j : [];
    }

    /** @return array<int,array{0:string,1:string,2:string}> [ok|warn|ko, requisito, dettaglio] */
    public static function prerequisites(PDO $pdo): array
    {
        $base = defined('APP_BASE') ? APP_BASE : dirname(__DIR__);
        $env = $base . '/.env.php';
        $c = [];
        $c[] = [function_exists('curl_init') ? 'ok' : 'ko', 'Estensione PHP curl', function_exists('curl_version') ? 'curl ' . (curl_version()['version'] ?? '') . ' · ' . (curl_version()['ssl_version'] ?? '') : 'abilitare extension=curl in php.ini'];
        $c[] = [function_exists('hash_hmac') && function_exists('random_bytes') ? 'ok' : 'ko', 'HMAC-SHA256 e generatore casuale', 'firma delle chiamate'];
        $c[] = [(is_file($env) ? is_writable($env) : is_writable($base)) ? 'ok' : 'ko', '.env.php scrivibile', 'il segreto PM_WPATS_SECRET è salvato solo lì'];
        $tbl = false;
        try { $tbl = (bool)$pdo->query("SHOW TABLES LIKE 'wp_ats_sync_log'")->fetchColumn(); } catch (Throwable $e) {}
        $c[] = [$tbl ? 'ok' : 'ko', 'Tabelle della sincronizzazione', $tbl ? 'wp_ats_sync_log, wp_ats_imports' : 'eseguire le migrazioni (v1.10.05)'];
        $c[] = [ini_get('allow_url_fopen') || function_exists('curl_init') ? 'ok' : 'warn', 'Connessione in uscita HTTPS', 'il server PortalManager deve raggiungere il sito (porta 443)'];
        $c[] = ['ok', 'Versioni', 'PortalManager ' . (defined('PM_VERSION') ? PM_VERSION : '?') . ' · plugin richiesto ≥ ' . self::PLUGIN_MIN . ' · API v' . self::API_VERSION];
        return $c;
    }

    public static function setupDone(PDO $pdo): bool { return self::setting($pdo, 'wpats.setup_done', '0') === '1'; }
}
