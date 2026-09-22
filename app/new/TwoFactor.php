<?php
/**
 * certV 4.2 — app/TwoFactor.php (admin-controlled v2)
 * Facade unica per la gestione 2FA con modello "admin authorize, user setup".
 *
 * NUOVO MODELLO (v4.2):
 *   - Solo il Super Admin può ATTIVARE/REVOCARE TOTP/Email per un utente
 *   - L'utente abilitato fa il setup TOTP da solo (scansione QR)
 *   - L'utente può rigenerare i propri recovery codes
 *
 * Schema sessione durante 2FA pending (invariato):
 *   $_SESSION['_2fa_pending'] = [
 *      'user_id'    => int,
 *      'role_id'    => int,
 *      'employee_id'=> int|null,
 *      'user_name'  => string,
 *      'email'      => string,
 *      'expires_at' => int,
 *      'method'     => 'totp'|'email',
 *   ]
 */

final class TwoFactor
{
    private const PENDING_TTL = 300; // 5 minuti

    // ─── Stato 2FA dell'utente ─────────────────────────────────────

    /**
     * Stato 2FA per utente, distinguendo "autorizzato dall'admin"
     * da "configurato dall'utente" (per TOTP servono entrambi).
     */
    public static function getUserState(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT totp_secret, totp_authorized, totp_enabled,
                    email_otp_authorized, email_otp_enabled,
                    verified_at, authorized_by, authorized_at
             FROM user_2fa WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::emptyState();
        }

        // TOTP "attivo per il login" = autorizzato AND configurato AND con secret
        $totp_authorized = (bool)$row['totp_authorized'];
        $totp_configured = !empty($row['totp_secret']);
        $totp_active     = $totp_authorized && $totp_configured && (bool)$row['totp_enabled'];

        // Email OTP "attivo" = autorizzato (non serve un secret)
        $email_authorized = (bool)$row['email_otp_authorized'];
        $email_active     = $email_authorized && (bool)$row['email_otp_enabled'];

        return [
            'totp_authorized'   => $totp_authorized,
            'totp_configured'   => $totp_configured,
            'totp_active'       => $totp_active,
            'totp_secret'       => $row['totp_secret'],
            'email_authorized'  => $email_authorized,
            'email_active'      => $email_active,
            // Compat: alias dei nomi vecchi
            'totp_enabled'      => $totp_active,
            'email_otp_enabled' => $email_active,
            'enabled'           => $totp_active || $email_active,
            'verified'          => !empty($row['verified_at']),
            'authorized_by'     => $row['authorized_by'],
            'authorized_at'     => $row['authorized_at'],
            // Visibilità menu: c'è almeno una autorizzazione admin
            'has_authorization' => $totp_authorized || $email_authorized,
        ];
    }

    private static function emptyState(): array
    {
        return [
            'totp_authorized'   => false,
            'totp_configured'   => false,
            'totp_active'       => false,
            'totp_secret'       => null,
            'email_authorized'  => false,
            'email_active'      => false,
            'totp_enabled'      => false,
            'email_otp_enabled' => false,
            'enabled'           => false,
            'verified'          => false,
            'authorized_by'     => null,
            'authorized_at'     => null,
            'has_authorization' => false,
        ];
    }

    /**
     * 2FA è attiva per il login dell'utente?
     * (almeno un metodo è autorizzato E completato)
     */
    public static function isEnabled(PDO $pdo, int $userId): bool
    {
        return self::getUserState($pdo, $userId)['enabled'];
    }

    /**
     * L'utente ha visibilità sul menu "Sicurezza account"?
     * Ovvero: l'admin ha autorizzato qualcosa per lui.
     */
    public static function hasAuthorization(PDO $pdo, int $userId): bool
    {
        return self::getUserState($pdo, $userId)['has_authorization'];
    }

    // ─── ADMIN: gestione autorizzazioni ────────────────────────────

    /**
     * Admin autorizza TOTP per un utente. L'utente potrà poi configurare
     * il proprio QR code dal pannello Sicurezza account.
     */
    public static function adminAuthorizeTotp(PDO $pdo, int $userId, int $adminId): void
    {
        $pdo->prepare(
            "INSERT INTO user_2fa (user_id, totp_authorized, authorized_by, authorized_at, created_at)
             VALUES (?, 1, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                totp_authorized = 1,
                authorized_by   = VALUES(authorized_by),
                authorized_at   = NOW()"
        )->execute([$userId, $adminId]);

        if (function_exists('write_log')) {
            write_log('2FA-Admin', 'info', "TOTP autorizzato per utente $userId", $adminId);
        }
    }

    /**
     * Admin revoca TOTP per un utente. Cancella anche secret e config.
     */
    public static function adminRevokeTotp(PDO $pdo, int $userId, int $adminId): void
    {
        $pdo->prepare(
            "UPDATE user_2fa
                SET totp_authorized = 0,
                    totp_enabled    = 0,
                    totp_secret     = NULL
              WHERE user_id = ?"
        )->execute([$userId]);

        if (function_exists('write_log')) {
            write_log('2FA-Admin', 'warning', "TOTP revocato per utente $userId", $adminId);
        }
    }

    /**
     * Admin autorizza Email OTP per un utente.
     */
    public static function adminAuthorizeEmail(PDO $pdo, int $userId, int $adminId): void
    {
        $pdo->prepare(
            "INSERT INTO user_2fa (user_id, email_otp_authorized, email_otp_enabled, authorized_by, authorized_at, created_at)
             VALUES (?, 1, 1, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                email_otp_authorized = 1,
                email_otp_enabled    = 1,
                authorized_by        = VALUES(authorized_by),
                authorized_at        = NOW()"
        )->execute([$userId, $adminId]);

        if (function_exists('write_log')) {
            write_log('2FA-Admin', 'info', "Email OTP autorizzato per utente $userId", $adminId);
        }
    }

    public static function adminRevokeEmail(PDO $pdo, int $userId, int $adminId): void
    {
        $pdo->prepare(
            "UPDATE user_2fa
                SET email_otp_authorized = 0,
                    email_otp_enabled    = 0
              WHERE user_id = ?"
        )->execute([$userId]);

        if (function_exists('write_log')) {
            write_log('2FA-Admin', 'warning', "Email OTP revocato per utente $userId", $adminId);
        }
    }

    /**
     * Admin reset 2FA completo: cancella TUTTO (autorizzazioni, secret, recovery codes).
     * Da usare solo se l'utente ha perso accesso totale.
     */
    public static function adminFullReset(PDO $pdo, int $userId, int $adminId): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM user_2fa WHERE user_id = ?")
                ->execute([$userId]);
            $pdo->prepare("DELETE FROM user_2fa_recovery_codes WHERE user_id = ?")
                ->execute([$userId]);
            $pdo->commit();

            if (function_exists('write_log')) {
                write_log('2FA-Admin', 'critical', "RESET COMPLETO 2FA per utente $userId", $adminId);
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─── USER: setup pratico TOTP (richiede autorizzazione admin) ──

    /**
     * Inizia il setup TOTP. Possibile SOLO se l'admin ha autorizzato.
     */
    public static function startTotpSetup(PDO $pdo, int $userId, string $userEmail): array
    {
        $state = self::getUserState($pdo, $userId);
        if (!$state['totp_authorized']) {
            throw new RuntimeException('TOTP non autorizzato per questo utente. Contatta l\'amministratore.');
        }

        $secret = Totp::generateSecret();
        $appName = self::appName($pdo);
        $uri = Totp::provisioningUri($secret, $userEmail, $appName);

        $_SESSION['_2fa_setup'] = [
            'user_id' => $userId,
            'secret'  => $secret,
            'started' => time(),
        ];

        return [
            'secret'    => $secret,
            'uri'       => $uri,
            'qr_url'    => Totp::qrCodeUrl($uri, 220),
            'app_name'  => $appName,
        ];
    }

    /**
     * L'utente conferma il setup TOTP inserendo un codice generato dall'app.
     * Salva il secret e marca totp_enabled=1.
     */
    public static function confirmTotpSetup(PDO $pdo, int $userId, string $code): bool
    {
        $state = self::getUserState($pdo, $userId);
        if (!$state['totp_authorized']) return false;

        $setup = $_SESSION['_2fa_setup'] ?? null;
        if (!$setup || $setup['user_id'] !== $userId) return false;
        if (time() - $setup['started'] > 600) {
            unset($_SESSION['_2fa_setup']);
            return false;
        }

        if (!Totp::verify($setup['secret'], $code)) {
            return false;
        }

        $pdo->prepare(
            "UPDATE user_2fa
                SET totp_secret  = ?,
                    totp_enabled = 1,
                    verified_at  = COALESCE(verified_at, NOW())
              WHERE user_id = ?"
        )->execute([$setup['secret'], $userId]);

        unset($_SESSION['_2fa_setup']);
        return true;
    }

    // ─── Flusso login two-step (invariato) ─────────────────────────

    public static function startPendingLogin(int $userId, int $roleId, ?int $employeeId, string $userName, string $email): void
    {
        $_SESSION['_2fa_pending'] = [
            'user_id'     => $userId,
            'role_id'     => $roleId,
            'employee_id' => $employeeId,
            'user_name'   => $userName,
            'email'       => $email,
            'expires_at'  => time() + self::PENDING_TTL,
            'method'      => null,
        ];
    }

    public static function getPending(): ?array
    {
        $p = $_SESSION['_2fa_pending'] ?? null;
        if (!$p) return null;
        if (time() > $p['expires_at']) {
            unset($_SESSION['_2fa_pending']);
            EmailOtp::clear();
            return null;
        }
        return $p;
    }

    public static function clearPending(): void
    {
        unset($_SESSION['_2fa_pending']);
        EmailOtp::clear();
    }

    public static function completePendingLogin(PDO $pdo, string $code): ?array
    {
        $pending = self::getPending();
        if (!$pending) return null;

        $uid = (int)$pending['user_id'];
        $state = self::getUserState($pdo, $uid);

        $verified = false;
        $method   = null;

        // 1. TOTP (solo se attivo: autorizzato + configurato)
        if (!$verified && $state['totp_active'] && preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code))) {
            if (Totp::verify($state['totp_secret'], $code)) {
                $verified = true;
                $method = 'totp';
            }
        }

        // 2. Email OTP
        if (!$verified && preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code))) {
            if (EmailOtp::verify($uid, $code)) {
                $verified = true;
                $method = 'email';
            }
        }

        // 3. Recovery code
        if (!$verified && preg_match('/^[A-Z0-9\- ]{8,12}$/i', $code)) {
            if (RecoveryCodes::consume($pdo, $uid, $code)) {
                $verified = true;
                $method = 'recovery';
            }
        }

        if (!$verified) return null;

        if (function_exists('write_log')) {
            write_log('Auth', 'success', "2FA verificata via $method", $uid,
                      ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        }

        $userData = $pending;
        self::clearPending();
        return $userData;
    }

    // ─── Helper ────────────────────────────────────────────────────

    private static function appName(PDO $pdo): string
    {
        try {
            $s = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'app_name'");
            $s->execute();
            return $s->fetchColumn() ?: 'certV';
        } catch (Throwable $e) {
            return 'certV';
        }
    }
}
