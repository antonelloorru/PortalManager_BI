<?php
/**
 * PortalManager — app/PasswordReset.php (v1.9.81)
 *
 * Reimpostazione password self-service: richiesta via email → link monouso → nuova password.
 *
 * Sicurezza:
 *  - token 256 bit (random_bytes), inviato solo per email; a DB SOLO l'hash SHA-256
 *    (`password_resets.token`): una copia del database non consente di usare i link;
 *  - monouso, scadenza configurabile (`pwd_reset_ttl_min`, default 60), una nuova
 *    richiesta invalida le precedenti; invalidati tutti a reimpostazione avvenuta;
 *  - risposta identica per email esistenti e non (nessuna enumerazione degli utenti),
 *    con tempo di risposta uniformato;
 *  - rate limit per IP e per email sulla richiesta, per IP sulla verifica del token;
 *  - link costruito da `app_public_url` (impostazione) e non dall'header Host della
 *    richiesta: impedisce l'avvelenamento del link (host header injection);
 *  - policy password (`pwd_min_length`, default 12; 3 classi di caratteri su 4; niente
 *    parti dell'email; diversa dall'attuale; max 72 byte, limite di bcrypt);
 *  - a reimpostazione avvenuta `users.password_changed_at` chiude le altre sessioni
 *    (Session::syncRole) e l'utente riceve una notifica; la 2FA resta richiesta al login.
 */

declare(strict_types=1);

final class PasswordReset
{
    public const MAX_BYTES = 72;

    /* ── impostazioni ─────────────────────────────────────────────────── */

    public static function setting(PDO $pdo, string $key, string $default = ''): string
    {
        try {
            $st = $pdo->prepare("SELECT `setting_value` FROM `app_settings` WHERE `setting_key` = ?");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            $st->closeCursor();
            return ($v === false || $v === null || $v === '') ? $default : (string)$v;
        } catch (Throwable $e) { return $default; }
    }

    public static function enabled(PDO $pdo): bool { return self::setting($pdo, 'pwd_reset_enabled', '1') === '1'; }

    public static function ttlMinutes(PDO $pdo): int
    {
        return max(10, min(1440, (int)self::setting($pdo, 'pwd_reset_ttl_min', '60')));
    }

    public static function minLength(PDO $pdo): int
    {
        return max(8, min(64, (int)self::setting($pdo, 'pwd_min_length', '12')));
    }

    /**
     * URL assoluto del portale. `app_public_url` se impostato; altrimenti schema +
     * SERVER_NAME (nome configurato in Apache, non l'header Host del client) + base.
     */
    public static function baseUrl(PDO $pdo): string
    {
        $u = rtrim(self::setting($pdo, 'app_public_url', ''), '/');
        if ($u !== '' && preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~i', $u)) return $u;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $host  = preg_replace('~[^A-Za-z0-9.\-]~', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $port  = (int)($_SERVER['SERVER_PORT'] ?? 0);
        $hp    = $host . (($port && $port !== 80 && $port !== 443) ? ':' . $port : '');
        return ($https ? 'https' : 'http') . '://' . $hp . (class_exists('Router') ? Router::base() : '');
    }

    /* ── richiesta ────────────────────────────────────────────────────── */

    /**
     * Crea il token e invia l'email, se l'utente esiste ed è attivo.
     * Il chiamante mostra SEMPRE lo stesso messaggio, qualunque sia l'esito.
     * @return string esito interno per il log: sent | unknown | inactive | mail_failed | error
     */
    public static function request(PDO $pdo, string $email, string $ip, string $ua): string
    {
        $email = mb_strtolower(trim($email));
        try {
            // nome: dall'anagrafica (display_name e' valorizzato solo per gli account di servizio)
            $st = $pdo->prepare("SELECT u.id, u.email, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.first_name, e.last_name)), ''), u.display_name) AS display_name, u.status
                                   FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                                  WHERE LOWER(u.email) = ? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if (!$u) return 'unknown';
            if (($u['status'] ?? '') !== 'active') return 'inactive';

            $token = bin2hex(random_bytes(32));
            $ttl   = self::ttlMinutes($pdo);

            $pdo->beginTransaction();
            // una sola richiesta valida per utente: le precedenti non servono piu'
            $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW()
                            WHERE used = 0 AND (user_id = ? OR email = ?)")->execute([(int)$u['id'], $u['email']]);
            $pdo->prepare("INSERT INTO password_resets (email, user_id, token, expires_at, used, request_ip, user_agent)
                           VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), 0, ?, ?)")
                ->execute([$u['email'], (int)$u['id'], hash('sha256', $token), $ttl,
                           mb_substr($ip, 0, 45), mb_substr($ua, 0, 255)]);
            // pulizia: richieste chiuse o scadute da oltre 30 giorni
            $pdo->exec("DELETE FROM password_resets WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $pdo->commit();

            $link = self::baseUrl($pdo) . '/' . ltrim(Router::url('password_reset', ['t' => $token]), '/');
            $ok = self::sendMail($pdo, (string)$u['email'], (string)($u['display_name'] ?: $u['email']), $link, $ttl, $ip);
            return $ok ? 'sent' : 'mail_failed';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
            error_log('[PasswordReset] ' . $e->getMessage());
            return 'error';
        }
    }

    /* ── verifica e reimpostazione ────────────────────────────────────── */

    /** Richiesta valida per il token (non usata, non scaduta), altrimenti null. */
    public static function find(PDO $pdo, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        try {
            $st = $pdo->prepare(
                "SELECT pr.id, pr.user_id, u.email, u.password_hash, u.status,
                        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.first_name, e.last_name)), ''), u.display_name) AS display_name
                   FROM password_resets pr JOIN users u ON u.id = pr.user_id
                   LEFT JOIN employees e ON e.id = u.employee_id
                  WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW() LIMIT 1");
            $st->execute([hash('sha256', $token)]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return ($r && $r['status'] === 'active') ? $r : null;
        } catch (Throwable $e) { return null; }
    }

    /** Errori di policy (vuoto = password valida). */
    public static function validate(PDO $pdo, string $pwd, string $confirm, string $email, string $currentHash): array
    {
        $err = [];
        $min = self::minLength($pdo);
        if ($pwd !== $confirm)               $err[] = 'Le due password non coincidono.';
        if (mb_strlen($pwd) < $min)          $err[] = "La password deve contenere almeno $min caratteri.";
        if (strlen($pwd) > self::MAX_BYTES)  $err[] = 'La password non può superare ' . self::MAX_BYTES . ' byte.';
        $classi = (int)preg_match('/[a-z]/', $pwd) + (int)preg_match('/[A-Z]/', $pwd)
                + (int)preg_match('/\d/', $pwd) + (int)preg_match('/[^A-Za-z0-9]/', $pwd);
        if ($classi < 3)                     $err[] = 'Usa almeno tre tipi di caratteri fra minuscole, maiuscole, numeri e simboli.';
        $local = mb_strtolower((string)strstr($email, '@', true));
        if (mb_strlen($local) >= 4 && str_contains(mb_strtolower($pwd), $local)) $err[] = 'La password non può contenere il nome dell\'account.';
        if ($currentHash !== '' && password_verify($pwd, $currentHash)) $err[] = 'La nuova password deve essere diversa da quella attuale.';
        return $err;
    }

    /** Aggiorna la password e chiude tutte le richieste dell'utente. */
    public static function complete(PDO $pdo, array $req, string $pwd, string $ip): bool
    {
        try {
            $hash = class_exists('Security') ? Security::hashPassword($pwd) : password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->beginTransaction();
            // il token si consuma per primo: due invii concorrenti non possono usarlo entrambi
            $st = $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE id = ? AND used = 0 AND expires_at > NOW()");
            $st->execute([(int)$req['id']]);
            if ($st->rowCount() !== 1) { $pdo->rollBack(); return false; }
            // ora di PHP, non del DB: Session::syncRole la confronta con l'avvio della sessione (time())
            $pdo->prepare("UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ? AND status = 'active'")
                ->execute([$hash, date('Y-m-d H:i:s'), (int)$req['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE user_id = ? AND used = 0")
                ->execute([(int)$req['user_id']]);
            $pdo->commit();
            self::notifyChanged($pdo, (string)$req['email'], (string)($req['display_name'] ?: $req['email']), $ip);
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $i) {} }
            error_log('[PasswordReset] ' . $e->getMessage());
            return false;
        }
    }

    /* ── email ────────────────────────────────────────────────────────── */

    private static function appName(PDO $pdo): string { return self::setting($pdo, 'app_name', 'PortalManager'); }

    private static function mail(PDO $pdo, string $to, string $subject, string $text, string $html): bool
    {
        // SmtpMailer.php esiste in root e in app/ con le stesse dichiarazioni: se ne carica uno solo
        if (!function_exists('send_certv_email') && !class_exists('SmtpMailer', false)) {
            foreach ([APP_BASE . '/SmtpMailer.php', APP_BASE . '/app/SmtpMailer.php'] as $f) {
                if (is_file($f)) { require_once $f; break; }
            }
        }
        if (!function_exists('send_certv_email')) return false;
        return send_certv_email($to, $subject, $text, $html, [], 'password_reset');
    }

    private static function sendMail(PDO $pdo, string $to, string $name, string $link, int $ttl, string $ip): bool
    {
        $app = self::appName($pdo);
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $text = "Ciao $name,\n\nè stata richiesta la reimpostazione della password del tuo account $app.\n\n"
              . "Apri questo link per scegliere una nuova password (valido $ttl minuti, utilizzabile una sola volta):\n$link\n\n"
              . "Se non sei stato tu, ignora questa email: la password attuale resta valida.\n"
              . "Richiesta da IP $ip il " . date('d/m/Y H:i') . ".\n\n-- \n$app";
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f1f5f9;padding:32px;color:#1e293b">'
              . '<div style="max-width:520px;margin:0 auto;background:#fff;padding:32px;border-radius:12px">'
              . '<h2 style="margin:0 0 14px;color:#0ea5e9">Reimpostazione password</h2>'
              . '<p>Ciao <strong>' . $e($name) . '</strong>,</p>'
              . '<p>è stata richiesta la reimpostazione della password del tuo account <strong>' . $e($app) . '</strong>.</p>'
              . '<p style="text-align:center;margin:26px 0"><a href="' . $e($link) . '" style="background:#0ea5e9;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:700">Scegli una nuova password</a></p>'
              . '<p style="font-size:13px;color:#64748b">Il link è valido <strong>' . $ttl . ' minuti</strong> e si può usare una sola volta. '
              . 'Se il pulsante non funziona copia questo indirizzo nel browser:<br><span style="word-break:break-all">' . $e($link) . '</span></p>'
              . '<p style="font-size:13px;color:#64748b">Se non sei stato tu, ignora questa email: la password attuale resta valida.</p>'
              . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:22px 0">'
              . '<p style="font-size:11px;color:#94a3b8;text-align:center">Richiesta da IP ' . $e($ip) . ' il ' . date('d/m/Y H:i') . ' · ' . $e($app) . ' · non rispondere</p>'
              . '</div></body></html>';
        return self::mail($pdo, $to, "$app — Reimpostazione password", $text, $html);
    }

    private static function notifyChanged(PDO $pdo, string $to, string $name, string $ip): void
    {
        $app = self::appName($pdo);
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $quando = date('d/m/Y H:i');
        $text = "Ciao $name,\n\nla password del tuo account $app è stata modificata il $quando (IP $ip).\n"
              . "Le altre sessioni aperte sono state chiuse.\n\n"
              . "Se non sei stato tu, contatta subito l'amministratore.\n\n-- \n$app";
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f1f5f9;padding:32px;color:#1e293b">'
              . '<div style="max-width:520px;margin:0 auto;background:#fff;padding:32px;border-radius:12px">'
              . '<h2 style="margin:0 0 14px;color:#16a34a">Password modificata</h2>'
              . '<p>Ciao <strong>' . $e($name) . '</strong>,</p><p>la password del tuo account <strong>' . $e($app) . '</strong> è stata modificata il '
              . $e($quando) . ' (IP ' . $e($ip) . '). Le altre sessioni aperte sono state chiuse.</p>'
              . '<p style="color:#b91c1c"><strong>Se non sei stato tu, contatta subito l\'amministratore.</strong></p></div></body></html>';
        self::mail($pdo, $to, "$app — Password modificata", $text, $html);
    }
}
