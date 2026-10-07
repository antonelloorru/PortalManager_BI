<?php
/**
 * Impostazioni del plugin (opzione unica pm_ats_settings, schema PM_ATS_SETTINGS_VERSION) e segreto condiviso con PortalManager.
 * v1.1.0 — salvataggio per scheda / passo del wizard (merge), codice di connessione per PortalManager.
 * Il segreto si legge, in ordine, dalla costante PM_ATS_SECRET (wp-config.php, consigliato) o
 * dall'opzione pm_ats_secret_enc cifrata AES-256-GCM con una chiave derivata dalle salt di WordPress.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Settings
{
    public const OPTION = 'pm_ats_settings';
    public const SECRET_OPTION = 'pm_ats_secret_enc';

    public static function defaults(): array
    {
        return [
            'client_id'          => 'portalmanager',
            'allowed_ips'        => '',            // CIDR separati da virgola; vuoto = qualunque IP (sconsigliato)
            'ip_source'          => 'REMOTE_ADDR', // REMOTE_ADDR | HTTP_X_FORWARDED_FOR | HTTP_CF_CONNECTING_IP
            'jobs_slug'          => 'posizioni-aperte',
            'list_page_id'       => 0,             // pagina con [pm_ats_jobs] (es. "Lavora con noi"): l'archivio vi reindirizza
            'auto_form'          => 1,             // modulo di candidatura in fondo alla scheda della posizione
            'allow_spontaneous'  => 1,             // candidatura spontanea (senza posizione)
            'phone_required'     => 1,
            'show_salary'        => 0,             // campo RAL desiderata nel modulo
            'company_name'       => '',
            'company_logo'       => '',
            // aspetto (variabili CSS; il font di default è quello del tema)
            'color_primary'      => '#ee7e02',
            'color_primary_text' => '#ffffff',
            'color_text'         => '',
            'color_muted'        => '#6b7280',
            'color_card'         => '#ffffff',
            'color_border'       => '#e5e7eb',
            'radius'             => 10,
            'font_family'        => '',
            'layout'             => 'grid',        // grid | list
            'per_page'           => 12,
            'custom_css'         => '',
            'privacy_url'        => '',
            'privacy_version'    => '1',
            'cv_max_mb'          => 5,
            'cv_types'           => 'pdf,doc,docx',
            'rate_per_day'       => 5,             // candidature per IP al giorno
            'min_fill_seconds'   => 3,             // tempo minimo di compilazione (anti-bot)
            'notify_email'       => '',
            'confirm_candidate'  => 1,
            'purge_after_ack'    => 1,             // dopo l'import in PortalManager: elimina CV e dati non necessari
            'retention_synced'   => 30,            // giorni: righe importate eliminate
            'retention_pending'  => 180,           // giorni: righe mai prelevate eliminate (con il CV)
            'remove_on_uninstall'=> 0,
        ];
    }

    public static function all(): array
    {
        $v = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($v) ? $v : []);
    }

    /** @return mixed */
    public static function get(string $k)
    {
        $a = self::all();
        return $a[$k] ?? null;
    }

    /** v1.1.0 — campi per scheda della pagina Impostazioni (salvataggio parziale: le altre schede restano invariate). */
    public const TABS = [
        'connessione' => ['client_id', 'allowed_ips', 'ip_source'],
        'pagina'      => ['list_page_id', 'jobs_slug', 'auto_form', 'allow_spontaneous', 'phone_required', 'show_salary', 'privacy_url', 'privacy_version',
                          'cv_types', 'cv_max_mb', 'rate_per_day', 'min_fill_seconds', 'notify_email', 'confirm_candidate'],
        'aspetto'     => ['color_primary', 'color_primary_text', 'color_text', 'color_muted', 'color_card', 'color_border', 'radius', 'font_family', 'layout', 'per_page', 'custom_css'],
        'dati'        => ['company_name', 'company_logo', 'purge_after_ack', 'retention_synced', 'retention_pending', 'remove_on_uninstall'],
    ];
    public const CHECKBOXES = ['auto_form', 'allow_spontaneous', 'phone_required', 'show_salary', 'confirm_candidate', 'purge_after_ack', 'remove_on_uninstall'];

    /**
     * v1.1.0 — Aggiorna solo le chiavi fornite (wizard, schede): unisce alle impostazioni correnti e sanifica tutto.
     * Le caselle di controllo dei campi indicati in $keys valgono 0 se assenti.
     */
    public static function merge(array $in, array $keys = []): array
    {
        $base = self::all();
        foreach ($keys ?: array_keys($in) as $k) {
            if (!array_key_exists($k, self::defaults())) continue;
            if (in_array($k, self::CHECKBOXES, true)) $base[$k] = empty($in[$k]) ? 0 : 1;
            elseif (array_key_exists($k, $in)) $base[$k] = $in[$k];
        }
        return self::sanitize($base);
    }

    public static function save(array $in, array $keys = []): void
    {
        update_option(self::OPTION, self::merge($in, $keys));
    }

    /**
     * v1.1.0 — Codice di connessione per PortalManager: «PMATS1.» + base64url(JSON {v, url, client, secret, plugin, api}).
     * Contiene il segreto: mostrato una sola volta, come il segreto.
     */
    public static function connectionCode(string $secret): string
    {
        $j = wp_json_encode(['v' => 1, 'url' => rest_url(PM_ATS_Rest::NS), 'client' => (string)self::get('client_id'), 'secret' => $secret,
                             'plugin' => PM_ATS_VERSION, 'api' => PM_ATS_API_VERSION, 'site' => home_url('/')]);
        return 'PMATS1.' . rtrim(strtr(base64_encode((string)$j), '+/', '-_'), '=');
    }

    public static function sanitize(array $in): array
    {
        // v1.1.0 — salvataggio da una scheda della pagina Impostazioni: solo i campi di quella scheda
        if (isset($in['_tab']) && isset(self::TABS[$in['_tab']])) {
            $tab = (string)$in['_tab']; unset($in['_tab']);
            $base = self::all();
            foreach (self::TABS[$tab] as $k) {
                if (in_array($k, self::CHECKBOXES, true)) $base[$k] = empty($in[$k]) ? 0 : 1;
                elseif (array_key_exists($k, $in)) $base[$k] = $in[$k];
            }
            $in = $base;
        }
        $d = self::defaults(); $o = [];
        $o['client_id']         = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($in['client_id'] ?? $d['client_id'])) ?: $d['client_id'];
        $ips = [];
        foreach (preg_split('/[\s,;]+/', (string)($in['allowed_ips'] ?? '')) as $c) {
            $c = trim($c);
            if ($c !== '' && self::validCidr($c)) $ips[] = $c;
        }
        $o['allowed_ips']       = implode(',', array_unique($ips));
        $o['ip_source']         = in_array($in['ip_source'] ?? '', ['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP'], true) ? $in['ip_source'] : 'REMOTE_ADDR';
        $o['jobs_slug']         = sanitize_title((string)($in['jobs_slug'] ?? '')) ?: $d['jobs_slug'];
        $o['list_page_id']      = max(0, (int)($in['list_page_id'] ?? 0));
        $o['auto_form']         = empty($in['auto_form']) ? 0 : 1;
        $o['allow_spontaneous'] = empty($in['allow_spontaneous']) ? 0 : 1;
        $o['phone_required']    = empty($in['phone_required']) ? 0 : 1;
        $o['show_salary']       = empty($in['show_salary']) ? 0 : 1;
        $o['company_name']      = sanitize_text_field((string)($in['company_name'] ?? ''));
        $o['company_logo']      = esc_url_raw((string)($in['company_logo'] ?? ''));
        foreach (['color_primary', 'color_primary_text', 'color_text', 'color_muted', 'color_card', 'color_border'] as $k)
            $o[$k] = (string)(sanitize_hex_color((string)($in[$k] ?? '')) ?? '');
        if ($o['color_primary'] === '') $o['color_primary'] = $d['color_primary'];
        $o['radius']            = max(0, min(30, (int)($in['radius'] ?? 10)));
        $o['font_family']       = preg_replace('/[^A-Za-z0-9 ,\'"-]/', '', (string)($in['font_family'] ?? ''));
        $o['layout']            = in_array($in['layout'] ?? '', ['grid', 'list'], true) ? $in['layout'] : 'grid';
        $o['per_page']          = max(1, min(100, (int)($in['per_page'] ?? 12)));
        $o['custom_css']        = trim(wp_strip_all_tags((string)($in['custom_css'] ?? '')));
        $o['privacy_url']       = esc_url_raw((string)($in['privacy_url'] ?? ''));
        $o['privacy_version']   = substr(sanitize_text_field((string)($in['privacy_version'] ?? '1')), 0, 40) ?: '1';
        $o['cv_max_mb']         = max(1, min(20, (int)($in['cv_max_mb'] ?? 5)));
        $types = array_intersect(array_map('trim', explode(',', strtolower((string)($in['cv_types'] ?? '')))), array_keys(PM_ATS_Applications::MIME));
        $o['cv_types']          = $types ? implode(',', $types) : $d['cv_types'];
        $o['rate_per_day']      = max(1, min(100, (int)($in['rate_per_day'] ?? 5)));
        $o['min_fill_seconds']  = max(0, min(60, (int)($in['min_fill_seconds'] ?? 3)));
        $o['notify_email']      = sanitize_email((string)($in['notify_email'] ?? ''));
        $o['confirm_candidate'] = empty($in['confirm_candidate']) ? 0 : 1;
        $o['purge_after_ack']   = empty($in['purge_after_ack']) ? 0 : 1;
        $o['retention_synced']  = max(1, min(3650, (int)($in['retention_synced'] ?? 30)));
        $o['retention_pending'] = max(7, min(3650, (int)($in['retention_pending'] ?? 180)));
        $o['remove_on_uninstall'] = empty($in['remove_on_uninstall']) ? 0 : 1;
        if ($o['jobs_slug'] !== self::get('jobs_slug')) update_option('pm_ats_flush_rewrite', 1);
        return $o;
    }

    public static function validCidr(string $c): bool
    {
        $p = explode('/', $c, 2);
        if (!filter_var($p[0], FILTER_VALIDATE_IP)) return false;
        if (!isset($p[1])) return true;
        $max = str_contains($p[0], ':') ? 128 : 32;
        return ctype_digit($p[1]) && (int)$p[1] <= $max;
    }

    /* ── segreto condiviso ─────────────────────────────────────────── */

    public static function secretSource(): string
    {
        if (defined('PM_ATS_SECRET') && (string)PM_ATS_SECRET !== '') return 'wp-config';
        return get_option(self::SECRET_OPTION) ? 'database' : 'none';
    }

    public static function secret(): ?string
    {
        if (defined('PM_ATS_SECRET') && (string)PM_ATS_SECRET !== '') return (string)PM_ATS_SECRET;
        $enc = (string)get_option(self::SECRET_OPTION, '');
        if ($enc === '') return null;
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }

    /** Genera un nuovo segreto (64 hex), lo salva cifrato e lo restituisce UNA volta per mostrarlo all'amministratore. */
    public static function rotateSecret(): string
    {
        $s = bin2hex(random_bytes(32));
        $iv = random_bytes(12); $tag = '';
        $ct = openssl_encrypt($s, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        update_option(self::SECRET_OPTION, base64_encode($iv . $tag . $ct), false);
        return $s;
    }

    public static function deleteSecret(): void { delete_option(self::SECRET_OPTION); }

    private static function key(): string
    {
        return hash('sha256', wp_salt('auth') . '|pm-ats|' . wp_salt('secure_auth'), true);
    }
}
