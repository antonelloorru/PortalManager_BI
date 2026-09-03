<?php
/**
 * certV 4.1 — app/RecoveryCodes.php
 * Generazione e verifica di codici di recupero one-time.
 *
 * Formato: 10 codici da 8 caratteri alfanumerici, raggruppati 4-4
 * (es. "X7K2-9PQM"). Ogni codice è valido una sola volta.
 *
 * Storage: hash bcrypt nel DB (tabella user_2fa_recovery_codes).
 * Mai memorizzati in chiaro dopo la generazione.
 */

final class RecoveryCodes
{
    private const COUNT     = 10;
    private const CHARSET   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I confusione
    private const GROUP_LEN = 4;
    private const GROUPS    = 2;  // 2 gruppi da 4 = 8 char totali

    /**
     * Genera 10 nuovi codici (in chiaro, da mostrare UNA SOLA VOLTA all'utente).
     */
    public static function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $codes[] = self::generateOne();
        }
        return $codes;
    }

    private static function generateOne(): string
    {
        $parts = [];
        $clen = strlen(self::CHARSET);
        for ($g = 0; $g < self::GROUPS; $g++) {
            $part = '';
            for ($i = 0; $i < self::GROUP_LEN; $i++) {
                $part .= self::CHARSET[random_int(0, $clen - 1)];
            }
            $parts[] = $part;
        }
        return implode('-', $parts);
    }

    /**
     * Hash di un codice per il salvataggio. Bcrypt (cost 10) è sufficiente:
     * il codice ha già ~40 bit di entropia, non c'è da rallentare brute-force.
     */
    public static function hash(string $code): string
    {
        return password_hash(self::normalize($code), PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * Verifica un codice contro un hash.
     */
    public static function verify(string $code, string $hash): bool
    {
        return password_verify(self::normalize($code), $hash);
    }

    /**
     * Normalizza l'input utente: maiuscole, no spazi/trattini.
     * Es: "x7k2 9PqM" → "X7K29PQM"
     */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[\s\-]/', '', $code));
    }

    /**
     * Salva i nuovi codici nel DB e cancella i vecchi.
     * Ritorna i codici in chiaro da mostrare all'utente UNA volta.
     */
    public static function regenerate(PDO $pdo, int $userId): array
    {
        $codes = self::generate();

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM user_2fa_recovery_codes WHERE user_id = ?")
                ->execute([$userId]);

            $stmt = $pdo->prepare(
                "INSERT INTO user_2fa_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, NOW())"
            );
            foreach ($codes as $c) {
                $stmt->execute([$userId, self::hash($c)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $codes;
    }

    /**
     * Tenta di consumare un codice. Se valido, lo marca come usato e ritorna true.
     */
    public static function consume(PDO $pdo, int $userId, string $code): bool
    {
        $stmt = $pdo->prepare(
            "SELECT id, code_hash FROM user_2fa_recovery_codes
             WHERE user_id = ? AND used_at IS NULL"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            if (self::verify($code, $row['code_hash'])) {
                $upd = $pdo->prepare(
                    "UPDATE user_2fa_recovery_codes SET used_at = NOW() WHERE id = ?"
                );
                $upd->execute([$row['id']]);
                return true;
            }
        }
        return false;
    }

    /**
     * Conta quanti codici sono ancora utilizzabili.
     */
    public static function remaining(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM user_2fa_recovery_codes
             WHERE user_id = ? AND used_at IS NULL"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
