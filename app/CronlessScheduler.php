<?php
/**
 * PortalManager — app/CronlessScheduler.php  (v1.9.70)
 *
 * Pianificazione SENZA cron / Utilità di pianificazione.
 *
 * Meccanismo ("pseudo-cron" applicativo):
 *  1. tick() è registrata a fine richiesta (shutdown) in app/bootstrap.php: la pagina
 *     è già stata generata, quindi l'utente non attende.
 *  2. Throttling: al massimo un controllo ogni THROTTLE secondi per istanza
 *     (timestamp su file, nessuna query nelle altre richieste).
 *  3. Se SyncRunner::isDue() → dispatch(): richiesta HTTP interna (loopback) NON
 *     bloccante verso cronless_worker.php, firmata HMAC con scadenza. Il worker
 *     risponde subito, continua in background (ignore_user_abort) ed esegue il sync.
 *  4. Se il loopback non è raggiungibile: fallback in-process dopo l'invio della
 *     risposta (fastcgi_finish_request se disponibile, altrimenti flush).
 *
 * Idempotenza: lock con scadenza + "già eseguita oggi" in SyncRunner → tick multipli,
 * richieste concorrenti o replay del token non producono esecuzioni doppie.
 */
declare(strict_types=1);

final class CronlessScheduler
{
    public const THROTTLE   = 60;    // secondi tra due controlli
    public const TOKEN_TTL  = 300;   // validità firma worker (s)
    public const WORKER     = 'cronless_worker.php';

    /** Chiamata a fine richiesta web. Non deve mai generare errori visibili. */
    public static function tick(): void
    {
        try {
            if (PHP_SAPI === 'cli') return;
            if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === self::WORKER) return;
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!$pdo instanceof PDO) return;
            if (!self::throttleOk()) return;

            require_once __DIR__ . '/SyncRunner.php';
            $cfg = SyncRunner::config($pdo);
            if (!$cfg || ($cfg['exec_mode'] ?? 'cronless') !== 'cronless') return;

            $pdo->prepare("UPDATE `cm_sync_schedule` SET `last_tick_at` = ? WHERE `id` = 1")->execute([date('Y-m-d H:i:s')]);
            // v1.10.07 — pipeline del Service SOC: stesso scheduler, worker dedicato (task «soc»), intervallo proprio
            try {
                require_once __DIR__ . '/SocSync.php';
                if (!SyncRunner::isDue($cfg) && SocSync::isDue($pdo)) { self::dispatch($pdo, false, true, 'soc'); return; }
            } catch (Throwable $e) { /* tabelle SOC assenti: nessuna azione */ }
            if (!SyncRunner::isDue($cfg)) return;

            self::dispatch($pdo, false, true);
        } catch (Throwable $e) {
            // mai propagare: lo scheduler non deve rompere le pagine
        }
    }

    /** Throttling su file: true se è passato THROTTLE dall'ultimo controllo. */
    private static function throttleOk(): bool
    {
        $f = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
           . 'pm_cronless_' . substr(sha1(defined('APP_ROOT') ? APP_ROOT : __DIR__), 0, 12) . '.chk';
        $last = @filemtime($f);
        if ($last !== false && (time() - $last) < self::THROTTLE) return false;
        @touch($f);
        return true;
    }

    // ── firma HMAC del worker ────────────────────────────────────────
    private static function secret(): string
    {
        $s = class_exists('Env') ? (string)(Env::get('URL_SECRET', '') ?: Env::get('APP_SECRET', '')) : '';
        if ($s === '') $s = hash('sha256', (defined('DB_PASS') ? DB_PASS : '') . __DIR__);
        return $s;
    }

    /** $task: 'sync' (sincronizzazione), 'snapshot' (copie delle viste, v1.9.73), 'soc' (pipeline Service SOC, v1.10.07). */
    public static function sign(int $ts, bool $force, string $task = 'sync'): string
    {
        $payload = "cronless|$ts|" . ($force ? '1' : '0') . ($task === 'sync' ? '' : "|$task");
        return hash_hmac('sha256', $payload, self::secret());
    }

    public static function verify(string $ts, string $force, string $sig, string $task = 'sync'): bool
    {
        if (!ctype_digit($ts) || abs(time() - (int)$ts) > self::TOKEN_TTL) return false;
        if (!in_array($task, ['sync', 'snapshot', 'soc'], true)) return false;
        return hash_equals(self::sign((int)$ts, $force === '1', $task), $sig);
    }

    // ── avvio del worker ─────────────────────────────────────────────
    /**
     * Avvia il worker con una richiesta interna non bloccante.
     * $allowInline: se il loopback fallisce esegue nel processo corrente (solo a fine
     * richiesta, quando la pagina è già stata inviata: usato dal tick).
     * @return array{ok:bool, via:string, note:string}
     */
    public static function dispatch(PDO $pdo, bool $force, bool $allowInline = false, string $task = 'sync'): array
    {
        $ts   = time();
        $body = http_build_query(['ts' => $ts, 'force' => $force ? '1' : '0', 'task' => $task, 'sig' => self::sign($ts, $force, $task)]);
        $res  = self::loopback($body);

        if (!$res['ok'] && !$allowInline) {   // da una pagina: nessuna esecuzione bloccante
            self::note($pdo, $res);
            return $res;
        }
        if (!$res['ok']) {
            // fallback: esecuzione in-process dopo aver chiuso la risposta al browser
            $res = ['ok' => true, 'via' => 'in-process', 'note' => 'loopback non disponibile (' . $res['note'] . '): esecuzione nel processo corrente'];
            if ($task === 'sync') self::note($pdo, $res);
            self::runInProcess($pdo, $force, $task);
            return $res;
        }
        if ($task === 'sync') self::note($pdo, $res);   // la telemetria in pagina riguarda la sincronizzazione
        return $res;
    }

    private static function note(PDO $pdo, array $res): void
    {
        try {
            $pdo->prepare("UPDATE `cm_sync_schedule` SET `last_dispatch_at` = ?, `last_dispatch_note` = ? WHERE `id` = 1")
                ->execute([date('Y-m-d H:i:s'), mb_substr($res['via'] . ': ' . $res['note'], 0, 255)]);
        } catch (Throwable $e) {}
    }

    /** URL e endpoint di rete del worker ricavati dalla richiesta corrente. */
    public static function workerTarget(): array
    {
        $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $port   = (int)($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
        $host   = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $dir    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        // gli script in sottocartella (es. /app/…) non esistono: le pagine stanno in root
        $path   = $dir . '/' . self::WORKER;
        return ['https' => $https, 'port' => $port, 'host' => $host, 'path' => $path];
    }

    /** POST non bloccante: scrive la richiesta e chiude senza attendere la risposta. */
    private static function loopback(string $body): array
    {
        $t = self::workerTarget();
        $ctx = stream_context_create(['ssl' => [
            // connessione verso se stessi (127.0.0.1): certificato self-signed/nome diverso ammessi
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]);
        $remote = ($t['https'] ? 'ssl://' : 'tcp://') . '127.0.0.1:' . $t['port'];
        $fp = @stream_socket_client($remote, $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) return ['ok' => false, 'via' => 'loopback', 'note' => "connessione a $remote fallita: $errstr ($errno)"];

        $req = "POST {$t['path']} HTTP/1.1\r\n"
             . "Host: {$t['host']}\r\n"
             . "Content-Type: application/x-www-form-urlencoded\r\n"
             . "Content-Length: " . strlen($body) . "\r\n"
             . "User-Agent: PortalManager-Cronless\r\n"
             . "Connection: close\r\n\r\n" . $body;
        $w = @fwrite($fp, $req);
        // attesa minima per consegnare la richiesta (non si legge la risposta)
        stream_set_timeout($fp, 1);
        @fread($fp, 1);
        @fclose($fp);
        return $w ? ['ok' => true, 'via' => 'loopback', 'note' => "worker avviato su {$t['path']}"]
                  : ['ok' => false, 'via' => 'loopback', 'note' => 'scrittura richiesta fallita'];
    }

    /** Fallback: chiude la risposta al browser e prosegue nello stesso processo. */
    private static function runInProcess(PDO $pdo, bool $force, string $task = 'sync'): void
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @flush();
        }
        if ($task === 'snapshot') {
            require_once __DIR__ . '/PmSnapshot.php';
            PmSnapshot::refresh($pdo, $force);
            return;
        }
        if ($task === 'soc') {                               // v1.10.07 — pipeline Service SOC
            require_once __DIR__ . '/SocSync.php';
            SocSync::run($pdo, 'pianificata', $force);
            return;
        }
        require_once __DIR__ . '/SyncRunner.php';
        SyncRunner::run($pdo, 'cronless', $force);
    }
}
