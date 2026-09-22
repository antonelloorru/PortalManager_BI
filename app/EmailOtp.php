<?php
/**
 * certV 4.1 — app/EmailOtp.php
 * Codici OTP a 6 cifre inviati via email.
 *
 * Usa SmtpMailer.php (già presente nel portale).
 * Lo storage è in memoria di sessione, mai persistito sul DB
 * (così quando l'utente chiude il browser il codice diventa invalido).
 *
 * Caratteristiche:
 *   - Validità 10 minuti
 *   - Max 5 tentativi di verifica per codice
 *   - Re-send rate-limited: 1 nuovo codice ogni 60 secondi
 *   - Codice consumato dopo verifica corretta
 */

final class EmailOtp
{
    private const TTL          = 600;  // 10 minuti
    private const MAX_ATTEMPTS = 5;
    private const RESEND_AFTER = 60;   // secondi

    /**
     * Genera un codice e lo memorizza in sessione.
     * Ritorna il codice in chiaro (per inviarlo via email).
     */
    public static function issue(int $userId): string
    {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION['_email_otp'] = [
            'user_id'      => $userId,
            'code_hash'    => password_hash($code, PASSWORD_BCRYPT),
            'created_at'   => time(),
            'attempts'     => 0,
            'last_send_at' => time(),
        ];

        return $code;
    }

    /**
     * Verifica il codice inserito. Logica:
     *   - Se non c'è codice in sessione → false
     *   - Se utente sbagliato → false
     *   - Se scaduto → consuma e false
     *   - Se troppi tentativi → consuma e false
     *   - Se match → consuma e true
     *   - Se mismatch → incrementa attempts, false
     */
    public static function verify(int $userId, string $code): bool
    {
        $otp = $_SESSION['_email_otp'] ?? null;
        if (!$otp || $otp['user_id'] !== $userId) return false;

        // Scaduto?
        if (time() - $otp['created_at'] > self::TTL) {
            self::clear();
            return false;
        }

        // Troppi tentativi?
        if ($otp['attempts'] >= self::MAX_ATTEMPTS) {
            self::clear();
            return false;
        }

        $code = preg_replace('/\s+/', '', $code);
        if (password_verify($code, $otp['code_hash'])) {
            self::clear();
            return true;
        }

        $_SESSION['_email_otp']['attempts']++;
        return false;
    }

    /**
     * Cancella il codice corrente (dopo uso o per reset).
     */
    public static function clear(): void
    {
        unset($_SESSION['_email_otp']);
    }

    /**
     * Quanti secondi mancano prima di poter chiedere un re-send?
     */
    public static function resendCooldown(): int
    {
        $otp = $_SESSION['_email_otp'] ?? null;
        if (!$otp) return 0;
        $elapsed = time() - $otp['last_send_at'];
        return max(0, self::RESEND_AFTER - $elapsed);
    }

    /**
     * Re-issue del codice se cooldown rispettato. Ritorna il nuovo codice o null.
     */
    public static function reissue(int $userId): ?string
    {
        if (self::resendCooldown() > 0) return null;
        return self::issue($userId);
    }

    /**
     * Tentativi rimanenti per il codice corrente.
     */
    public static function attemptsRemaining(): int
    {
        $otp = $_SESSION['_email_otp'] ?? null;
        if (!$otp) return 0;
        return max(0, self::MAX_ATTEMPTS - $otp['attempts']);
    }

    /**
     * Invio dell'email tramite SmtpMailer (se presente) o mail() come fallback.
     * Tutto il rendering del messaggio è in questo metodo per centralizzarlo.
     */
    public static function send(string $toEmail, string $toName, string $code, string $appName = 'certV'): bool
    {
        $subject = "$appName - Codice di verifica: $code";

        $textBody = <<<TXT
Ciao $toName,

Il tuo codice di verifica per accedere a $appName è:

   $code

Il codice è valido per 10 minuti. Se non hai richiesto tu l'accesso,
ignora questa email e contatta l'amministratore.

--
$appName
TXT;

        $htmlBody = self::renderHtmlEmail($toName, $code, $appName);

        // Prova con SmtpMailer (file gia' presente nel portale)
        $mailerFile = APP_BASE . '/SmtpMailer.php';
        if (file_exists($mailerFile)) {
            require_once $mailerFile;
            try {
                if (class_exists('SmtpMailer')) {
                    $mailer = new SmtpMailer();
                    return $mailer->send($toEmail, $toName, $subject, $htmlBody, $textBody);
                }
            } catch (Throwable $e) {
                error_log('[EmailOtp] SmtpMailer error: ' . $e->getMessage());
            }
        }

        // Fallback: mail() PHP nativo
        $headers = "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "From: noreply@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n";
        return @mail($toEmail, $subject, $textBody, $headers);
    }

    private static function renderHtmlEmail(string $name, string $code, string $appName): string
    {
        $name = htmlspecialchars($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $code = htmlspecialchars($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $app  = htmlspecialchars($appName, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background: #f1f5f9; padding: 40px; color: #1e293b;">
  <div style="max-width: 480px; margin: 0 auto; background: #fff; padding: 40px; border-radius: 12px;">
    <h2 style="margin: 0 0 16px; color: #0ea5e9;">Codice di verifica</h2>
    <p>Ciao <strong>$name</strong>,</p>
    <p>Il tuo codice per accedere a <strong>$app</strong> è:</p>
    <div style="background: #f8fafc; border: 2px dashed #cbd5e1; padding: 24px; text-align: center; border-radius: 8px; margin: 24px 0;">
      <div style="font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #0ea5e9; font-family: 'Courier New', monospace;">$code</div>
    </div>
    <p style="color: #64748b; font-size: 13px;">
      Il codice è valido per <strong>10 minuti</strong>.<br>
      Se non hai richiesto tu l'accesso, ignora questa email.
    </p>
    <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;">
    <p style="color: #94a3b8; font-size: 11px; text-align: center;">$app - notifica automatica, non rispondere</p>
  </div>
</body>
</html>
HTML;
    }
}
