<?php
/**
 * certV 4.2 — app/Csrf.php (v2 — lista legacy estesa)
 *
 * Protezione CSRF con token per sessione + controllo automatico su ogni POST.
 *
 * USO NEI FORM:
 *   <form method="POST" ...>
 *     <?= csrf_field() ?>           ← stampa <input type="hidden" name="_csrf" ...>
 *     ...
 *   </form>
 *
 * USO IN HANDLER POST (verifica automatica):
 *   - Quando il file viene incluso da bootstrap.php, Csrf::verify() viene
 *     chiamato automaticamente alla fine di questo file (a meno che
 *     CSRF_SKIP sia definito prima del require).
 *
 * USO NEGLI AJAX/FETCH:
 *   - header.php inietta il token in tutti i fetch automatici via header
 *     X-CSRF-Token. Per chiamate jQuery o XHR manuali, leggere il token
 *     dal meta tag <meta name="csrf-token" content="...">.
 *
 * SKIP CSRF SU PAGINA SPECIFICA:
 *   <?php define('CSRF_SKIP', true); require_once 'app/bootstrap.php'; ?>
 *
 * WHITELIST LEGACY:
 *   I tool admin storici che fanno POST senza token CSRF sono elencati in
 *   $legacyAdminTools all'interno di Csrf::verify(). Sono protetti
 *   dall'autenticazione admin + SameSite=Strict sul cookie di sessione.
 */

final class Csrf
{
    private const FIELD  = '_csrf';
    private const HEADER = 'HTTP_X_CSRF_TOKEN';
    private const TTL    = 7200; // 2 ore

    /**
     * Tool admin legacy che POSTano senza CSRF.
     * Elenco aggiornato v4.2:
     *   - file installer/reset (creano la sessione, non si aspettano CSRF)
     *   - tool sistema (db_upgrade, system_update, schema_check, health_check)
     *   - utility manutenzione (apply_csrf_patch, verify_integrity)
     *   - file admin con form non ancora migrati (smtp_settings, settings,
     *     config_notifiche): da rimuovere quando i form sono migrati
     */
    private const LEGACY_ADMIN_TOOLS = [
        // Installer / reset (devono funzionare senza sessione)
        'install.php',
        'reset_admin.php',
        'fix_password.php',

        // Tool sistema (Super Admin)
        'system_update.php',
        'db_upgrade.php',
        'schema_check_upgrade.php',
        'health_check.php',

        // Utility manutenzione (one-shot, vengono eliminati dopo l'uso)
        'apply_csrf_patch.php',
        'verify_integrity.php',
        'verify_integrity_v2.php',
        'migrate_links.php',
    ];

    /**
     * Restituisce il token corrente, generandolo se mancante o scaduto.
     */
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return '';

        $now = time();
        $t   = $_SESSION['_csrf'] ?? null;

        if (!$t || !is_array($t) || empty($t['value']) || ($now - ($t['created'] ?? 0) > self::TTL)) {
            $raw = random_bytes(32);
            $_SESSION['_csrf'] = [
                'value'   => bin2hex($raw),
                'created' => $now,
            ];
        }
        return $_SESSION['_csrf']['value'];
    }

    /**
     * HTML da stampare nei form: <input type="hidden" name="_csrf" value="...">
     */
    public static function field(): string
    {
        $t = htmlspecialchars(self::token(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<input type="hidden" name="' . self::FIELD . '" value="' . $t . '">';
    }

    /**
     * Verifica il token su una POST. Supporta body POST e header X-CSRF-Token.
     * In caso di fallimento, invia 403 e termina.
     *
     * Salta la verifica per:
     *   - Richieste non POST (GET, HEAD, ecc.)
     *   - File presenti in LEGACY_ADMIN_TOOLS
     */
    public static function verify(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

        // Page corrente (basename del file PHP attualmente in esecuzione)
        $page = basename($_SERVER['PHP_SELF'] ?? '');
        if (in_array($page, self::LEGACY_ADMIN_TOOLS, true)) {
            return;
        }

        $expected = $_SESSION['_csrf']['value'] ?? '';
        $provided = $_POST[self::FIELD] ?? $_SERVER[self::HEADER] ?? '';

        if (!$expected || !$provided || !hash_equals($expected, (string)$provided)) {
            http_response_code(403);

            // Log per audit (se la funzione esiste)
            if (function_exists('write_log')) {
                write_log(
                    'Security',
                    'warning',
                    'CSRF token invalido su ' . $page,
                    $_SESSION['user_id'] ?? null,
                    [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]
                );
            }

            die('<!DOCTYPE html><html lang="it"><meta charset="UTF-8"><title>403</title>
                <body style="font-family:system-ui,sans-serif;padding:40px;background:#fee2e2;color:#991b1b;text-align:center">
                <h1 style="margin:0 0 12px">403 — Richiesta rifiutata</h1>
                <p style="font-size:14px;color:#7f1d1d">Token di sicurezza non valido o scaduto.</p>
                <p style="font-size:13px;color:#991b1b">Ricarica la pagina e riprova.</p>
                <p style="margin-top:24px"><a href="javascript:history.back()" style="color:#991b1b">&larr; Indietro</a></p>
                </body></html>');
        }
    }

    /**
     * Forza la rotazione del token (da chiamare dopo azioni critiche come login).
     * Il prossimo accesso a token() ne genererà uno nuovo.
     */
    public static function rotate(): void
    {
        unset($_SESSION['_csrf']);
    }

    /**
     * Verifica se la pagina corrente è nella whitelist legacy.
     * Utile per debug e per i tool di diagnostica.
     */
    public static function isLegacyTool(?string $page = null): bool
    {
        $page ??= basename($_SERVER['PHP_SELF'] ?? '');
        return in_array($page, self::LEGACY_ADMIN_TOOLS, true);
    }

    /**
     * Restituisce la lista dei tool legacy (per audit / UI di debug).
     */
    public static function legacyTools(): array
    {
        return self::LEGACY_ADMIN_TOOLS;
    }
}

// ── Auto-verify ────────────────────────────────────────────────────
// Se la request è POST e abbiamo una sessione attiva, verifica il token.
// Disabilitabile per endpoint specifici dichiarando CSRF_SKIP prima del require.
if (!defined('CSRF_SKIP')) {
    Csrf::verify();
}
