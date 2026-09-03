<?php
/**
 * certV 4.1 — app/Router.php
 * Mappa slug opachi <-> pagine reali.
 *
 * v4.1: aggiunte pagine 2FA (2fa_settings, 2fa_verify).
 *
 * Ogni pagina PHP del portale ha uno slug deterministico generato con
 * HMAC-SHA256 troncato. Così:
 *   - l'URL non rivela il nome del file (es. ?r=k7m2x9 invece di brand.php)
 *   - lo slug è stabile (stesso nome → stesso slug, finché URL_SECRET non cambia)
 *   - non serve una tabella DB per memorizzare la mappa
 *
 * L'entry point r.php risolve lo slug, verifica i permessi e include la pagina.
 */

final class Router
{
    /**
     * Whitelist di pagine accessibili via router.
     * Solo questi file possono essere serviti via ?r=.
     * I file admin/sensibili (install.php, reset_admin.php, api_*) NON sono qui.
     */
    public const PAGES = [
        // Core
        'index', 'login', 'logout', 'unauthorized', 'user_profile', 'employee_profile', 'employee_cv', 'device_manager', 'device_export', 'device_print', 'device_import', 'notifications',

        // 2FA (v4.1)
        '2fa_verify', '2fa_settings',

        // Brand & Partnership
        'brand', 'brand_referents', 'brand_technologies', 'brand_distributors',
        'gap_analysis',

        // Competenze
        'catalogo_certificazioni', 'report_certificazioni', 'visualizza_storico',
        'training_plans', 'upload_certificato', 'programmazione', 'segreteria',

        // Recruiting
        'recruiting_posizioni', 'recruiting_candidati', 'candidato_profilo',
        'publish_posizione', 'recruiting_agenzie', 'recruiting_contratti',
        'documenti',

        // Admin
        'manager_users', 'manage_employees', 'device_handover', 'menu_customizer', 'cv_import', 'manage_roles', 'manage_permissions',
        'manage_companies', 'manage_work_modes', 'mass_upload',
        'config_notifiche', 'smtp_settings', 'settings', 'view_logs',
        'manage_clients', 'manage_users_2fa', 'position_history', 'user_profile', 'branding',
        'mass_upload_jobs', 'mass_upload_review', 'mass_upload_partials',
        'manage_technologies', 'tech_skill_matrix', 'entity_change_log',
        'manage_enum_proposals', 'system_backup', 'credly_sync',

        // Gestione Commesse (v1.8.x)
        'manage_projects', 'project_dashboard', 'project_gantt', 'workload_overview', 'service_desk', 'it_service', 'dir_report', 'pratix_orders', 'sync_commesse',
        'dgb_activities', 'manage_rate_bands', 'import_commesse', 'import_commesse_db',
        'tech_registry', 'tech_units',
        'professionals', 'import_professionals', 'import_intervention_reports',
        'import_control', 'timesheet', 'projects', 'project_clients', 'project_import',

        // HR economics / Finance (v1.8.x)
        'finance_overview', 'finance_compare', 'employee_compensation',
        'hr_economic_years', 'import_economics_xlsx',

        // Anagrafica / HR aggiuntive
        'import_employees_xlsx', 'merge_employees', 'export_employees',
        'manage_departments', 'hr_reference_values', 'organigramma',

        // Competenze & Formazione aggiuntive
        'cert_import_cisco', 'credly_manual_import', 'linkedin_sync',
    ];

    /**
     * Pagine ad alto privilegio che il router NON deve servire
     * (devono essere accedute solo con path-esatto + autenticazione manuale).
     */
    public const RESTRICTED = [
        'install', 'reset_admin', 'fix_password', 'db_upgrade',
        'schema_check_upgrade', 'health_check', 'system_update',
        // v1.8.16: pagine Sistema/manutenzione ad accesso solo per path esatto
        // (l'anonimizzazione via slug ne rompeva l'accesso e le sotto-sezioni)
        'system_console', 'system_errors', 'recycle_bin', 'verify_integrity', 'cleanup_orphans',
        'migrate_links', 'diag', 'file_manager',
    ];

    private static array $slugToPage = [];
    private static array $pageToSlug = [];
    private static bool $built = false;

    /**
     * Genera lo slug per una pagina. Deterministico via HMAC.
     */
    public static function slug(string $page): string
    {
        $page = self::normalize($page);
        if (isset(self::$pageToSlug[$page])) return self::$pageToSlug[$page];

        $secret = Env::get('URL_SECRET', 'fallback-insecure');
        $hash = substr(hash_hmac('sha256', $page, $secret), 0, 16);
        self::$pageToSlug[$page] = $hash;
        self::$slugToPage[$hash] = $page;
        return $hash;
    }

    /**
     * Costruisce URL opaco per una pagina + parametri query opzionali.
     */
    public static function url(string $page, array $params = []): string
    {
        $page = self::normalize($page);

        // Le pagine RESTRICTED (alto privilegio/manutenzione) non vengono mai
        // anonimizzate: accesso solo per path esatto, anche se elencate in PAGES.
        if (!in_array($page, self::PAGES, true) || in_array($page, self::RESTRICTED, true)) {
            $qs = $params ? '?' . http_build_query($params) : '';
            return $page . '.php' . $qs;
        }

        $slug = self::slug($page);
        $params = array_merge(['r' => $slug], $params);

        $useRewrite = Env::get('USE_PRETTY_URLS', '1') === '1';

        if ($useRewrite) {
            $extra = $params;
            unset($extra['r']);
            $qs = !empty($extra) ? '?' . http_build_query($extra) : '';
            return 'app/' . $slug . $qs;
        }
        return 'r.php?' . http_build_query($params);
    }

    /**
     * Risolve uno slug al nome-pagina reale. Restituisce null se non valido.
     */
    public static function resolve(string $slug): ?string
    {
        if (!Security::isSlug($slug, 32)) return null;

        self::buildIndex();
        return self::$slugToPage[$slug] ?? null;
    }

    private static function buildIndex(): void
    {
        if (self::$built) return;
        self::$built = true;
        foreach (self::PAGES as $p) {
            self::slug($p);
        }
    }

    private static function normalize(string $page): string
    {
        $page = basename($page);
        if (str_ends_with($page, '.php')) $page = substr($page, 0, -4);
        return $page;
    }

    public static function isRoutable(string $page): bool
    {
        $page = self::normalize($page);
        return in_array($page, self::PAGES, true) && !in_array($page, self::RESTRICTED, true);
    }

    public static function isRestricted(string $page): bool
    {
        return in_array(self::normalize($page), self::RESTRICTED, true);
    }
}
