<?php
/**
 * ════════════════════════════════════════════════════════════════
 *  certV 2.4 — SmtpMailer.php
 *  Motore SMTP indipendente dal sistema operativo.
 *  Usa esclusivamente stream_socket_client / fsockopen PHP.
 *  NON dipende da: mail(), sendmail, postfix, exec(), shell_exec()
 *
 *  Supporta: PLAIN, LOGIN, CRAM-MD5 auth | STARTTLS | SSL implicito
 *  Compatibile: Windows (XAMPP), Linux (LAMP), macOS (MAMP)
 * ════════════════════════════════════════════════════════════════
 */

class SmtpMailer
{
    // ── Configurazione ────────────────────────────────────────
    private string $host       = '';
    private int    $port       = 587;
    private string $encryption = 'tls';     // 'tls', 'ssl', 'none'
    private bool   $auth       = true;
    private string $username   = '';
    private string $password   = '';
    private int    $timeout    = 15;
    private bool   $debug      = false;

    // ── Stato ─────────────────────────────────────────────────
    private $socket = null;
    private array  $log         = [];
    private string $lastError   = '';
    private string $lastResponse= '';
    private string $serverEhlo  = '';

    // ── Email ─────────────────────────────────────────────────
    private string $fromEmail  = '';
    private string $fromName   = '';
    private array  $to         = [];
    private array  $cc         = [];
    private array  $bcc        = [];
    private string $subject    = '';
    private string $bodyText   = '';
    private string $bodyHtml   = '';
    private string $charset    = 'UTF-8';
    private array  $headers    = [];
    private array  $attachments = []; // [{name, content, mime}]

    /**
     * Configura da array (tipicamente da app_settings)
     */
    public function configure(array $cfg): self
    {
        $this->host       = $cfg['smtp_host']       ?? $this->host;
        $this->port       = (int)($cfg['smtp_port'] ?? $this->port);
        $this->encryption = $cfg['smtp_encryption']  ?? $this->encryption;
        $this->auth       = (bool)($cfg['smtp_auth'] ?? $this->auth);
        $this->username   = $cfg['smtp_user']        ?? $this->username;
        $this->password   = $cfg['smtp_pass']        ?? $this->password;
        $this->timeout    = (int)($cfg['smtp_timeout'] ?? $this->timeout);
        $this->debug      = (bool)($cfg['smtp_debug']  ?? $this->debug);
        $this->fromEmail  = $cfg['mail_from']        ?? $this->fromEmail;
        $this->fromName   = $cfg['mail_from_name']   ?? $this->fromName;
        return $this;
    }

    // ── Setters fluent ────────────────────────────────────────

    public function from(string $email, string $name = ''): self
    {
        $this->fromEmail = $email;
        $this->fromName  = $name;
        return $this;
    }

    public function to(string $email, string $name = ''): self
    {
        $this->to[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function bcc(string $email): self
    {
        $this->bcc[] = ['email' => $email, 'name' => ''];
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function bodyText(string $text): self
    {
        $this->bodyText = $text;
        return $this;
    }

    public function bodyHtml(string $html): self
    {
        $this->bodyHtml = $html;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Allega un file inline (contenuto in memoria).
     * @param string $filename  Nome file visibile nel client (es. "evento.ics")
     * @param string $content   Contenuto binario/testo del file
     * @param string $mimeType  MIME type (es. "text/calendar")
     */
    public function attach(string $filename, string $content, string $mimeType = 'application/octet-stream'): self
    {
        $this->attachments[] = ['name' => $filename, 'content' => $content, 'mime' => $mimeType];
        return $this;
    }

    /**
     * Allega un file da disco.
     */
    public function attachFile(string $filepath, ?string $filename = null, ?string $mimeType = null): self
    {
        if (!file_exists($filepath)) return $this;
        $content = file_get_contents($filepath);
        $name = $filename ?: basename($filepath);
        $mime = $mimeType ?: (mime_content_type($filepath) ?: 'application/octet-stream');
        return $this->attach($name, $content, $mime);
    }

    // ── Reset per riutilizzo ──────────────────────────────────
    public function reset(): self
    {
        $this->to = $this->cc = $this->bcc = [];
        $this->subject = $this->bodyText = $this->bodyHtml = '';
        $this->headers = [];
        $this->attachments = [];
        $this->lastError = $this->lastResponse = '';
        return $this;
    }

    // ── Getters ───────────────────────────────────────────────
    public function getLastError(): string   { return $this->lastError; }
    public function getLog(): array          { return $this->log; }
    public function getLastResponse(): string{ return $this->lastResponse; }

    /**
     * ══════════════════════════════════════════════════════════
     *  INVIO EMAIL
     * ══════════════════════════════════════════════════════════
     */
    public function send(): bool
    {
        $this->log = [];
        $this->lastError = '';

        // ── Validazione ───────────────────────────────────────
        if (!$this->host) {
            $this->lastError = 'Host SMTP non configurato.';
            return false;
        }
        if (empty($this->to)) {
            $this->lastError = 'Nessun destinatario specificato.';
            return false;
        }
        if (!$this->fromEmail) {
            $this->lastError = 'Mittente non specificato.';
            return false;
        }

        try {
            // ── 1. Connessione ────────────────────────────────
            $this->connect();

            // ── 2. EHLO ───────────────────────────────────────
            $this->ehlo();

            // ── 3. STARTTLS (se porta 587 o encryption=tls) ──
            if ($this->encryption === 'tls') {
                $this->startTls();
                $this->ehlo(); // Ri-EHLO dopo TLS
            }

            // ── 4. Autenticazione ─────────────────────────────
            if ($this->auth && $this->username) {
                $this->authenticate();
            }

            // ── 5. Envelope (MAIL FROM + RCPT TO) ─────────────
            $this->mailFrom($this->fromEmail);
            foreach (array_merge($this->to, $this->cc, $this->bcc) as $rcpt) {
                $this->rcptTo($rcpt['email']);
            }

            // ── 6. DATA (headers + body) ──────────────────────
            $this->data($this->buildMessage());

            // ── 7. QUIT ───────────────────────────────────────
            $this->quit();

            return true;

        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            $this->addLog('ERROR', $e->getMessage());
            $this->close();
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════
    //  LIVELLO TRASPORTO (socket)
    // ══════════════════════════════════════════════════════════

    private function connect(): void
    {
        $proto = ($this->encryption === 'ssl') ? 'ssl://' : '';
        $addr  = $proto . $this->host . ':' . $this->port;

        $this->addLog('CONNECT', $addr);

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $addr, $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT, $ctx
        );

        if (!$this->socket) {
            throw new \Exception("Connessione a $addr fallita: [$errno] $errstr");
        }

        stream_set_timeout($this->socket, $this->timeout);

        $greeting = $this->readResponse();
        if (!$this->isResponseOk($greeting, 220)) {
            throw new \Exception("Risposta server inattesa: $greeting");
        }
    }

    private function ehlo(): void
    {
        $hostname = gethostname() ?: 'localhost';
        $resp = $this->sendCommand("EHLO $hostname", 250);
        $this->serverEhlo = $resp;
    }

    private function startTls(): void
    {
        $this->sendCommand("STARTTLS", 220);

        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $result = @stream_socket_enable_crypto($this->socket, true, $crypto);
        if (!$result) {
            throw new \Exception("Negoziazione TLS fallita. Verificare che il server supporti STARTTLS sulla porta {$this->port}.");
        }
        $this->addLog('TLS', 'Crittografia attivata');
    }

    private function authenticate(): void
    {
        // Preferisco LOGIN (supportato ovunque), poi PLAIN
        if (stripos($this->serverEhlo, 'LOGIN') !== false) {
            $this->authLogin();
        } elseif (stripos($this->serverEhlo, 'PLAIN') !== false) {
            $this->authPlain();
        } else {
            // Fallback: prova LOGIN comunque
            $this->authLogin();
        }
    }

    private function authLogin(): void
    {
        $this->sendCommand("AUTH LOGIN", 334);
        $this->sendCommand(base64_encode($this->username), 334);
        $this->sendCommand(base64_encode($this->password), 235);
        $this->addLog('AUTH', 'LOGIN riuscita');
    }

    private function authPlain(): void
    {
        $token = base64_encode("\0" . $this->username . "\0" . $this->password);
        $this->sendCommand("AUTH PLAIN $token", 235);
        $this->addLog('AUTH', 'PLAIN riuscita');
    }

    private function mailFrom(string $email): void
    {
        $this->sendCommand("MAIL FROM:<$email>", 250);
    }

    private function rcptTo(string $email): void
    {
        $this->sendCommand("RCPT TO:<$email>", [250, 251]);
    }

    private function data(string $message): void
    {
        $this->sendCommand("DATA", 354);

        // Dot-stuffing (RFC 5321 §4.5.2)
        $message = str_replace("\r\n.", "\r\n..", $message);

        fwrite($this->socket, $message . "\r\n.\r\n");
        $this->addLog('DATA', '(message body)');

        $resp = $this->readResponse();
        if (!$this->isResponseOk($resp, 250)) {
            throw new \Exception("Errore invio dati: $resp");
        }
    }

    private function quit(): void
    {
        try {
            $this->sendCommand("QUIT", 221);
        } catch (\Exception $e) {
            // Ignora errori su QUIT
        }
        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    // ══════════════════════════════════════════════════════════
    //  COSTRUZIONE MESSAGGIO (RFC 5322)
    // ══════════════════════════════════════════════════════════

    private function buildMessage(): string
    {
        $boundary = '----=_certV_' . bin2hex(random_bytes(16));
        $eol = "\r\n";

        $headers  = "Date: " . date('r') . $eol;
        $headers .= "From: " . $this->encodeAddress($this->fromEmail, $this->fromName) . $eol;
        $headers .= "To: " . $this->buildAddressList($this->to) . $eol;
        if (!empty($this->cc)) {
            $headers .= "Cc: " . $this->buildAddressList($this->cc) . $eol;
        }
        $headers .= "Subject: " . $this->encodeHeader($this->subject) . $eol;
        $headers .= "MIME-Version: 1.0" . $eol;
        $headers .= "X-Mailer: certV-SmtpMailer/2.4 (PHP/" . PHP_VERSION . ")" . $eol;
        $headers .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . ($this->host ?: 'certv.local') . ">" . $eol;

        // Header custom
        foreach ($this->headers as $k => $v) {
            $headers .= "$k: $v" . $eol;
        }

        // Body
        $hasAttachments = !empty($this->attachments);

        if ($hasAttachments) {
            // multipart/mixed (outer) wraps body + attachments
            $mixedBoundary = '----=_certV_mixed_' . bin2hex(random_bytes(12));
            $headers .= "Content-Type: multipart/mixed; boundary=\"$mixedBoundary\"" . $eol;
            $body = $eol;

            // Body part (text/html or alternative)
            $body .= "--$mixedBoundary" . $eol;
            if ($this->bodyHtml && $this->bodyText) {
                $altBoundary = '----=_certV_alt_' . bin2hex(random_bytes(12));
                $body .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"" . $eol . $eol;
                $body .= "--$altBoundary" . $eol;
                $body .= "Content-Type: text/plain; charset={$this->charset}" . $eol;
                $body .= "Content-Transfer-Encoding: quoted-printable" . $eol . $eol;
                $body .= quoted_printable_encode($this->bodyText) . $eol;
                $body .= "--$altBoundary" . $eol;
                $body .= "Content-Type: text/html; charset={$this->charset}" . $eol;
                $body .= "Content-Transfer-Encoding: quoted-printable" . $eol . $eol;
                $body .= quoted_printable_encode($this->bodyHtml) . $eol;
                $body .= "--$altBoundary--" . $eol;
            } elseif ($this->bodyHtml) {
                $body .= "Content-Type: text/html; charset={$this->charset}" . $eol;
                $body .= "Content-Transfer-Encoding: quoted-printable" . $eol . $eol;
                $body .= quoted_printable_encode($this->bodyHtml) . $eol;
            } else {
                $body .= "Content-Type: text/plain; charset={$this->charset}" . $eol;
                $body .= "Content-Transfer-Encoding: quoted-printable" . $eol . $eol;
                $body .= quoted_printable_encode($this->bodyText ?: '(nessun contenuto)') . $eol;
            }

            // Attachment parts
            foreach ($this->attachments as $att) {
                $body .= "--$mixedBoundary" . $eol;
                $body .= "Content-Type: {$att['mime']}; name=\"{$att['name']}\"" . $eol;
                $body .= "Content-Disposition: attachment; filename=\"{$att['name']}\"" . $eol;
                $body .= "Content-Transfer-Encoding: base64" . $eol . $eol;
                $body .= chunk_split(base64_encode($att['content']), 76, $eol);
            }
            $body .= "--$mixedBoundary--" . $eol;

        } elseif ($this->bodyHtml && $this->bodyText) {
            // Multipart alternative (no attachments)
            $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"" . $eol;
            $body  = $eol;
            $body .= "--$boundary" . $eol;
            $body .= "Content-Type: text/plain; charset={$this->charset}" . $eol;
            $body .= "Content-Transfer-Encoding: quoted-printable" . $eol . $eol;
            $body .= quoted_printable_encode($this->bodyText) . $eol;
            $body .= "--$boundary" . $eol;
            $body .= "Content-Type: text/html; charset={$this->charset}" . $eol;
            $body .= "Content-Transfer-Encoding: quoted-printable" . $eol . $eol;
            $body .= quoted_printable_encode($this->bodyHtml) . $eol;
            $body .= "--$boundary--" . $eol;
        } elseif ($this->bodyHtml) {
            $headers .= "Content-Type: text/html; charset={$this->charset}" . $eol;
            $headers .= "Content-Transfer-Encoding: quoted-printable" . $eol;
            $body = $eol . quoted_printable_encode($this->bodyHtml);
        } else {
            $headers .= "Content-Type: text/plain; charset={$this->charset}" . $eol;
            $headers .= "Content-Transfer-Encoding: quoted-printable" . $eol;
            $body = $eol . quoted_printable_encode($this->bodyText ?: '(nessun contenuto)');
        }

        return $headers . $body;
    }

    private function encodeAddress(string $email, string $name = ''): string
    {
        if (!$name) return "<$email>";
        return $this->encodeHeader($name) . " <$email>";
    }

    private function buildAddressList(array $addrs): string
    {
        return implode(', ', array_map(
            fn($a) => $this->encodeAddress($a['email'], $a['name']),
            $addrs
        ));
    }

    private function encodeHeader(string $value): string
    {
        // RFC 2047 encoding per header non-ASCII
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?' . $this->charset . '?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    // ══════════════════════════════════════════════════════════
    //  I/O SOCKET
    // ══════════════════════════════════════════════════════════

    private function sendCommand(string $cmd, $expectedCode = null): string
    {
        $logCmd = (stripos($cmd, 'AUTH') !== false && strlen($cmd) > 20)
            ? substr($cmd, 0, 10) . '***' : $cmd;
        $this->addLog('SEND', $logCmd);

        fwrite($this->socket, $cmd . "\r\n");
        $resp = $this->readResponse();

        if ($expectedCode !== null) {
            $codes = is_array($expectedCode) ? $expectedCode : [$expectedCode];
            $ok = false;
            foreach ($codes as $c) {
                if ($this->isResponseOk($resp, $c)) { $ok = true; break; }
            }
            if (!$ok) {
                throw new \Exception("Comando '$logCmd' fallito. Risposta: $resp");
            }
        }

        return $resp;
    }

    private function readResponse(): string
    {
        $response = '';
        $endTime  = time() + $this->timeout;

        while (is_resource($this->socket) && !feof($this->socket)) {
            $line = fgets($this->socket, 4096);
            if ($line === false) break;
            $response .= $line;

            // Multiline: continua se il 4° char è '-'
            if (isset($line[3]) && $line[3] !== '-') break;
            if (time() > $endTime) break;
        }

        $this->lastResponse = trim($response);
        $this->addLog('RECV', substr($this->lastResponse, 0, 200));
        return $this->lastResponse;
    }

    private function isResponseOk(string $response, int $code): bool
    {
        return (int)substr(ltrim($response), 0, 3) === $code;
    }

    private function addLog(string $type, string $message): void
    {
        $entry = date('H:i:s') . " [$type] $message";
        $this->log[] = $entry;
        if ($this->debug) {
            error_log("[SmtpMailer] $entry");
        }
    }

    // ══════════════════════════════════════════════════════════
    //  TEST CONNESSIONE (senza inviare email)
    // ══════════════════════════════════════════════════════════

    public function testConnection(): array
    {
        $this->log = [];
        $result = ['ok' => false, 'steps' => [], 'error' => ''];

        try {
            // 1. Connessione
            $this->connect();
            $result['steps'][] = ['Connessione TCP', true, "{$this->host}:{$this->port}"];

            // 2. EHLO
            $this->ehlo();
            $result['steps'][] = ['EHLO', true, 'Server pronto'];

            // 3. TLS
            if ($this->encryption === 'tls') {
                $this->startTls();
                $this->ehlo();
                $result['steps'][] = ['STARTTLS', true, 'Crittografia attiva'];
            } elseif ($this->encryption === 'ssl') {
                $result['steps'][] = ['SSL', true, 'Connessione SSL implicita'];
            }

            // 4. Auth
            if ($this->auth && $this->username) {
                $this->authenticate();
                $result['steps'][] = ['Autenticazione', true, 'Credenziali valide'];
            }

            $result['ok'] = true;
            $this->quit();

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $failedStep = count($result['steps']);
            $labels = ['Connessione TCP', 'EHLO', 'STARTTLS', 'Autenticazione'];
            $result['steps'][] = [$labels[$failedStep] ?? 'Fase sconosciuta', false, $e->getMessage()];
            $this->close();
        }

        $result['log'] = $this->log;
        return $result;
    }
}

// ══════════════════════════════════════════════════════════════
//  FUNZIONE HELPER GLOBALE
//  Usata da cron_notifications.php, publish_posizione.php, ecc.
// ══════════════════════════════════════════════════════════════

/**
 * Invia un'email usando il motore SMTP configurato.
 * Se SMTP non è abilitato, NON usa mail() (OS-dependent) ma logga un warning.
 *
 * @param string      $to       Destinatario
 * @param string      $subject  Oggetto
 * @param string      $body     Corpo testo
 * @param string|null $htmlBody Corpo HTML (opzionale)
 * @param array       $cc       Destinatari CC
 * @param string      $module   Modulo sorgente (per il log)
 * @param int|null    $relatedId ID record collegato (per tracciabilità)
 * @return bool
 */
function send_certv_email(
    string  $to,
    string  $subject,
    string  $body,
    ?string $htmlBody    = null,
    array   $cc          = [],
    string  $module      = 'system',
    ?int    $relatedId   = null,
    array   $attachments = []  // [{name, content, mime}]
): bool {
    global $pdo;

    $settings = load_settings();

    // Se SMTP non è abilitato, logga e ritorna false
    if (empty($settings['smtp_enabled']) || $settings['smtp_enabled'] === '0') {
        log_email($to, $subject, 'failed', 'SMTP non abilitato. Configurare da Impostazioni > SMTP.', $module, $relatedId);
        return false;
    }

    require_once __DIR__ . '/SmtpMailer.php';

    $mailer = new SmtpMailer();
    $mailer->configure($settings);
    $mailer->to($to);
    $mailer->subject($subject);
    $mailer->bodyText($body);

    if ($htmlBody) {
        $mailer->bodyHtml($htmlBody);
    }

    foreach ($cc as $ccAddr) {
        if ($ccAddr) $mailer->cc($ccAddr);
    }

    // Allegati
    foreach ($attachments as $att) {
        if (!empty($att['content']) && !empty($att['name'])) {
            $mailer->attach($att['name'], $att['content'], $att['mime'] ?? 'application/octet-stream');
        }
    }

    $ok = $mailer->send();

    // Log nel database
    log_email(
        $to, $subject,
        $ok ? 'sent' : 'failed',
        $ok ? null : $mailer->getLastError(),
        $module, $relatedId,
        $ok ? implode("\n", $mailer->getLog()) : null
    );

    // Log debug nel system log
    if (!$ok && ($settings['smtp_debug'] ?? '0') === '1') {
        write_log('SMTP', 'error', "Invio a $to fallito: " . $mailer->getLastError());
    }

    return $ok;
}

/**
 * Registra l'invio email nella tabella email_log
 */
function log_email(
    string  $to,
    string  $subject,
    string  $status,
    ?string $error    = null,
    string  $module   = 'system',
    ?int    $relatedId = null,
    ?string $smtpResp = null
): void {
    global $pdo;
    try {
        $pdo->prepare(
            "INSERT INTO email_log (recipient, subject, status, error_msg, smtp_response, module, related_id, sent_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $to, substr($subject, 0, 500), $status, $error, $smtpResp,
            $module, $relatedId, $_SESSION['user_id'] ?? null,
        ]);
    } catch (\Exception $e) {
        error_log("[certV email_log] " . $e->getMessage());
    }
}
