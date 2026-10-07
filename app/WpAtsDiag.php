<?php
/**
 * app/WpAtsDiag.php — v1.10.16
 * Diagnostica dell'handshake PortalManager → plugin WordPress pm-ats, passo per passo, con il codice d'errore effettivo:
 *   1 Configurazione   URL API, client ID, segreto (.env.php) e sua impronta, TLS/CA/proxy
 *   2 DNS              risoluzione del nome host
 *   3 Trasporto / TLS  GET non firmata alla radice REST: connessione, certificato, redirect, intermediari (WAF/CDN)
 *   4 Plugin           GET non firmata al namespace pm-ats/v1: REST attiva e plugin presente
 *   5 Autenticazione   GET firmata /sync/status: codice effettivo del plugin (401 firma/client/orologio, 403 IP, 429 blocco)
 *   6 Orologio         scarto fra l'ora locale e l'intestazione Date del sito (tolleranza della firma ±300 s)
 * Nessun segreto nel risultato: solo l'impronta (12 hex) da confrontare con quella del plugin (≥ 1.1.1).
 */
declare(strict_types=1);

require_once __DIR__ . '/WpAtsClient.php';

final class WpAtsDiag
{
    /** @return array{ok:bool, summary:string, steps:array<int,array{0:string,1:string,2:string,3:string,4:string}>} [stato, passo, esito/codice, dettaglio, rimedio] */
    public static function run(PDO $pdo): array
    {
        $s = WpAtsClient::settings($pdo);
        $steps = [];
        $add = function (string $st, string $step, string $code, string $det, string $fix = '') use (&$steps) { $steps[] = [$st, $step, $code, $det, $fix]; };
        $sec = WpAtsClient::secret();
        $base = WpAtsClient::normalizeBase($s['wpats.base_url']);
        [$ca, $caSrc] = WpAtsClient::caBundle(trim($s['wpats.ca_file']));
        $cfgOk = $base !== null && $sec !== '' && trim($s['wpats.client_id']) !== '';
        $add($cfgOk ? 'ok' : 'ko', '1 Configurazione', $cfgOk ? 'completa' : 'incompleta',
            'URL API ' . ($base ?? '—') . ' · client «' . $s['wpats.client_id'] . '» · segreto ' . ($sec !== '' ? strlen($sec) . ' caratteri, impronta ' . WpAtsClient::fingerprint($sec) : 'MANCANTE')
            . ' · TLS ' . ($s['wpats.verify_tls'] !== '0' ? 'verificato' : 'NON verificato') . ' · CA ' . ($ca !== '' ? $ca . ' (' . $caSrc . ')' : $caSrc) . ($s['wpats.proxy'] !== '' ? ' · proxy ' . $s['wpats.proxy'] : ''),
            $cfgOk ? '' : 'completare URL, client ID e segreto (Impostazioni o codice di connessione)');
        if (!$cfgOk) return self::out(false, 'Configurazione incompleta', $steps);
        $c = WpAtsClient::fromSettings($pdo, 15);

        $host = (string)parse_url($base, PHP_URL_HOST);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        $add($ips ? 'ok' : ($s['wpats.proxy'] !== '' ? 'warn' : 'ko'), '2 DNS', $ips ? 'risolto' : 'non risolto', $host . ($ips ? ' → ' . implode(', ', array_slice($ips, 0, 4)) : ''),
            $ips ? '' : ($s['wpats.proxy'] !== '' ? 'risoluzione affidata al proxy' : 'verificare il nome host e il DNS del server'));
        if (!$ips && $s['wpats.proxy'] === '') return self::out(false, 'Nome host non risolto: ' . $host, $steps);

        $root = $c->probe($c->restRoot());
        $a = WpAtsClient::analyze($root);
        $tls = str_starts_with(strtolower($base), 'https') ? ' · TLS ok' : ' · HTTP in chiaro';
        if ($root['error'] || ($root['status'] >= 300 && $root['status'] < 400)) {
            $add('ko', '3 Trasporto / TLS', $a['code'], $a['cause'] . ' (' . $c->restRoot() . ')', $a['fix']);
            if (in_array((int)($root['errno'] ?? 0), [7, 28], true)) foreach (self::network($base, $ips, $c) as $x) $add(...$x);   // v1.10.17
            return self::out(false, $a['code'] . ' — ' . $a['cause'] . ($a['fix'] !== '' ? ' → ' . $a['fix'] : ''), $steps);
        }
        $restOk = $root['status'] === 200 && is_array($root['json']);
        $add($restOk ? 'ok' : 'warn', '3 Trasporto / TLS', 'HTTP ' . $root['status'],
            ($restOk ? 'REST API raggiungibile' . $tls : $a['cause']) . ' · IP ' . ($root['info']['ip'] ?? '?') . ' · ' . $root['ms'] . ' ms', $restOk ? '' : $a['fix']);

        $ns = $c->probe('');
        $nsOk = $ns['status'] === 200 && is_array($ns['json']) && ($ns['json']['namespace'] ?? '') === 'pm-ats/v1';
        $an = WpAtsClient::analyze($ns);
        $add($nsOk ? 'ok' : 'ko', '4 Plugin pm-ats', $nsOk ? 'presente' : $an['code'],
            $nsOk ? 'namespace pm-ats/v1 con ' . count((array)($ns['json']['routes'] ?? [])) . ' rotte' : $an['cause'], $nsOk ? '' : $an['fix']);

        $r = $c->request('GET', '/sync/status');
        $ar = WpAtsClient::analyze($r);
        $drift = self::drift($r['headers']['date'] ?? ($ns['headers']['date'] ?? ''));
        $pv = (string)($r['headers']['x-pm-ats-version'] ?? '');
        if ($r['status'] === 200 && !empty($r['json']['ok'])) {
            $add('ok', '5 Autenticazione', 'HTTP 200', 'firma accettata · plugin ' . ($r['json']['plugin'] ?? '?') . ' · WordPress ' . ($r['json']['wordpress'] ?? '?') . ' · ' . $r['ms'] . ' ms');
        } else {
            $add('ko', '5 Autenticazione', $ar['code'], $ar['cause'] . ($ar['origin'] === 'plugin' ? ' [plugin' . ($pv !== '' ? ' ' . $pv : '') . ']' : ' [' . $ar['origin'] . ']')
                . self::dataTxt($ar['data']), $ar['fix']);
        }
        if ($drift !== null) $add(abs($drift) > 300 ? 'ko' : (abs($drift) > 60 ? 'warn' : 'ok'), '6 Orologio', ($drift >= 0 ? '+' : '') . $drift . ' s',
            'scarto fra PortalManager e il sito (intestazione Date); tolleranza della firma ±300 s', abs($drift) > 60 ? 'sincronizzare l\'orologio (w32tm /resync, NTP)' : '');
        $ok = $r['status'] === 200 && !empty($r['json']['ok']);
        return self::out($ok, $ok ? 'Handshake riuscito' : WpAtsClient::describe($r), $steps);
    }

    /**
     * v1.10.17 — Analisi di rete quando la connessione non si stabilisce: porte 443/80 per ogni IP risolto, IP privati
     * (DNS interno), host alternativo con/senza «www», proxy del sistema (variabili d'ambiente, WinHTTP).
     * @return array<int,array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function network(string $base, array $ips, WpAtsClient $c): array
    {
        $out = [];
        $u = parse_url($base);
        $host = (string)($u['host'] ?? '');
        $port = (int)($u['port'] ?? (strtolower((string)($u['scheme'] ?? 'https')) === 'https' ? 443 : 80));
        $tcp = static function (string $ip, int $p): array {
            $t0 = microtime(true);
            $fp = @fsockopen(str_contains($ip, ':') ? "[$ip]" : $ip, $p, $en, $es, 5);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if ($fp) { fclose($fp); return [true, "aperta ($ms ms)"]; }
            return [false, ($en === 110 || $en === 10060 || $ms >= 4900 ? 'nessuna risposta' : 'rifiutata') . " ($ms ms)"];
        };
        $priv = static fn(string $ip) => !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        $test = $c->resolveIp() !== '' ? [$c->resolveIp()] : $ips;
        $open = false; $p80 = false; $det = [];
        foreach (array_slice($test, 0, 3) as $ip) {
            [$o, $t] = $tcp($ip, $port); [$o80, $t80] = $port !== 80 ? $tcp($ip, 80) : [false, ''];
            $open = $open || $o; $p80 = $p80 || $o80;
            $det[] = "$ip" . ($priv($ip) ? ' (privato)' : '') . ": $port $t" . ($t80 !== '' ? " · 80 $t80" : '');
        }
        $fix = $open ? 'la porta risponde a livello TCP: verificare proxy/ispezione TLS o aumentare il timeout'
             : ($p80 ? "la porta 80 risponde ma la $port no: HTTPS non pubblicato su questo indirizzo o filtrato dal firewall"
                     : 'nessuna porta risponde da questo server: firewall in uscita o proxy obbligatorio; se il sito è ospitato nella rete aziendale (NAT hairpin) impostare «IP forzato» con l\'IP interno del server web');
        $out[] = [$open ? 'warn' : 'ko', '3b Rete', 'TCP ' . ($open ? 'aperta' : 'chiusa'), implode(' · ', $det) . ($c->resolveIp() !== '' ? ' — IP forzato' : ''), $fix];

        // host alternativo con/senza www: spesso solo uno dei due è pubblicato
        $alt = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $aips = gethostbynamel($alt) ?: [];
            if ($aips) {
                [$ao, $at] = $tcp($aips[0], $port);
                $out[] = [$ao ? 'warn' : 'ko', '3c Host alternativo', $alt, $alt . ' → ' . implode(', ', array_slice($aips, 0, 3)) . " · $port $at",
                          $ao ? 'questo host risponde: verificare se il WordPress con il plugin è su ' . $alt . ' (URL API indicato nel plugin, Impostazioni › Connessione)' : ''];
            }
        }

        // proxy del sistema: PHP/cURL NON usa le impostazioni proxy di Windows/browser
        $px = [];
        foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY'] as $k) if (($v = getenv($k)) !== false && $v !== '') $px[] = "$k=$v";
        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $w = (string)@shell_exec('netsh winhttp show proxy 2>NUL');
            if (preg_match('/(?:Server proxy|Proxy Server\(s\))\s*:\s*(\S+)/i', $w, $m)) $px[] = 'WinHTTP ' . $m[1];
        }
        $out[] = [$px && $c->proxy() === '' ? 'warn' : 'ok', '3d Proxy', $c->proxy() !== '' ? 'impostato' : ($px ? 'di sistema' : 'nessuno'),
                  ($c->proxy() !== '' ? 'PortalManager usa ' . $c->proxy() : 'PortalManager non usa proxy') . ($px ? ' · sistema: ' . implode(', ', $px) : ''),
                  $px && $c->proxy() === '' ? 'il server esce tramite proxy: indicarlo in Impostazioni › Rete › Proxy in uscita (PHP non usa il proxy di Windows/browser)' : ''];
        return $out;
    }

    private static function drift(string $date): ?int
    {
        $t = $date !== '' ? strtotime($date) : false;
        return $t ? time() - $t : null;
    }

    private static function dataTxt(array $d): string
    {
        $o = [];
        if (isset($d['client_ip']))   $o[] = 'IP visto dal sito ' . $d['client_ip'];
        if (isset($d['server_time'])) $o[] = 'ora del sito ' . date('d/m/Y H:i:s', (int)$d['server_time']) . ' (scarto ' . (time() - (int)$d['server_time']) . ' s)';
        if (isset($d['route']))       $o[] = 'rotta vista dal sito ' . ($d['method'] ?? '') . ' ' . $d['route'];
        if (isset($d['failures']))    $o[] = 'tentativi falliti ' . $d['failures'] . '/' . ($d['failures_max'] ?? 20);
        return $o ? ' · ' . implode(' · ', $o) : '';
    }

    private static function out(bool $ok, string $summary, array $steps): array
    {
        return ['ok' => $ok, 'summary' => $summary, 'steps' => $steps, 'at' => date('Y-m-d H:i:s')];
    }

    /** Tabella HTML del risultato (Impostazioni, configurazione guidata). */
    public static function html(array $d): string
    {
        $h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $ico = ['ok' => ['✔', '#16a34a'], 'warn' => ['!', '#d97706'], 'ko' => ['✘', '#dc2626']];
        $o = '<div style="font-size:13px;font-weight:700;margin:0 0 6px;color:' . ($d['ok'] ? '#16a34a' : '#dc2626') . '">' . $h($d['summary']) . '</div>'
           . '<table class="data-table" data-pm-nofilter style="width:100%;font-size:12px"><thead><tr><th></th><th>Passo</th><th>Esito</th><th>Dettaglio</th><th>Rimedio</th></tr></thead><tbody>';
        foreach ($d['steps'] as [$st, $step, $code, $det, $fix])
            $o .= '<tr><td style="font-weight:800;color:' . $ico[$st][1] . '">' . $ico[$st][0] . '</td><td style="white-space:nowrap">' . $h($step) . '</td><td style="white-space:nowrap"><code>' . $h($code) . '</code></td><td>' . $h($det) . '</td><td>' . $h($fix) . '</td></tr>';
        return $o . '</tbody></table><div style="font-size:11px;color:var(--muted);margin-top:4px">Eseguita il ' . $h(date('d/m/Y H:i:s', strtotime($d['at']))) . '</div>';
    }
}
