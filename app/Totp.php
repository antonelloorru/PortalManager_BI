<?php
/**
 * certV 4.1 — app/Totp.php
 * Implementazione pura PHP di TOTP (RFC 6238) e HOTP (RFC 4226)
 * compatibile con Google Authenticator, Microsoft Authenticator,
 * Authy, FreeOTP, 1Password, Bitwarden, ecc.
 *
 * Zero dipendenze esterne (no Composer).
 */

final class Totp
{
    private const PERIOD     = 30;       // secondi per token (standard)
    private const DIGITS     = 6;        // cifre del codice (standard)
    private const ALGORITHM  = 'sha1';   // RFC 6238 default
    private const SECRET_LEN = 20;       // 160 bit (standard Google)
    private const WINDOW     = 1;        // accetta code corrente +/- 1 (= 90 sec di tolleranza)

    /**
     * Genera un nuovo secret base32 (20 byte = 32 char base32).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_LEN));
    }

    /**
     * Verifica un codice TOTP a 6 cifre rispetto al secret.
     * Accetta una finestra di +/- 1 step (ovvero 90 secondi totali)
     * per tollerare drift di clock fra server e dispositivo.
     */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $secretBin = self::base32Decode($secret);
        if ($secretBin === false || $secretBin === '') return false;

        $now = (int)floor(time() / self::PERIOD);

        // Confronto in tempo costante per evitare timing attacks
        for ($w = -self::WINDOW; $w <= self::WINDOW; $w++) {
            $expected = self::generateAtCounter($secretBin, $now + $w);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Costruisce l'URI otpauth:// per il QR code.
     * Es: otpauth://totp/certV:utente@dom.it?secret=XXX&issuer=certV
     */
    public static function provisioningUri(string $secret, string $accountName, string $issuer = 'certV'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return "otpauth://totp/$label?$params";
    }

    /**
     * Genera codice corrente per debug / preview UI.
     */
    public static function currentCode(string $secret): string
    {
        $bin = self::base32Decode($secret);
        if ($bin === false) return '';
        return self::generateAtCounter($bin, (int)floor(time() / self::PERIOD));
    }

    /**
     * Secondi rimanenti prima del prossimo refresh del codice.
     */
    public static function secondsRemaining(): int
    {
        return self::PERIOD - (time() % self::PERIOD);
    }

    // ─── Implementazione HOTP (RFC 4226) ───────────────────────────

    private static function generateAtCounter(string $secretBin, int $counter): string
    {
        // Counter come big-endian 8-byte
        $bin = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGORITHM, $bin, $secretBin, true);

        // Dynamic truncation (RFC 4226 §5.3)
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value =
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF);

        $modulo = 10 ** self::DIGITS;
        return str_pad((string)($value % $modulo), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ─── Base32 (RFC 4648) ──────────────────────────────────────────
    // Implementazione minimal per i casi d'uso TOTP.

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bin): string
    {
        if ($bin === '') return '';
        $bits = '';
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $b32)
    {
        $b32 = strtoupper(preg_replace('/[\s=]/', '', $b32));
        if ($b32 === '') return '';
        if (preg_match('/[^A-Z2-7]/', $b32)) return false;

        $bits = '';
        $alphabet = self::ALPHABET;
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) return false;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /**
     * Genera l'URL di un QR code via Google Charts API (non richiede librerie).
     * In produzione si potrebbe sostituire con una libreria locale (es. endroid/qrcode).
     */
    public static function qrCodeUrl(string $provisioningUri, int $size = 200): string
    {
        // QR Server è gratuito e CORS-friendly
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
             . '&data=' . urlencode($provisioningUri);
    }
}
