<?php
/**
 * PortalManager 1.7.9 — app/MenuManager.php
 *
 * Gestisce la definizione strutturata del menu del portale:
 *  - Catalogo completo voci disponibili (struttura "default")
 *  - Caricamento config personalizzata (per utente o per ruolo)
 *  - Salvataggio config con merge intelligente
 *  - Filtro voci in base a permessi RBAC
 */

class MenuManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Restituisce la struttura COMPLETA di default del menu, organizzata in sezioni.
     * Questa è la "verità" alla quale ogni personalizzazione fa riferimento.
     * Ogni voce ha: page (file PHP), label, icon (FA class), section.
     */
    public static function defaultMenu(): array
    {
        return [
            [
                'key'     => 'home',
                'label'   => 'Home',
                'icon'    => 'fa-house',
                'items'   => [
                    ['page' => 'index',         'label' => 'Dashboard',         'icon' => 'fa-gauge-high',    'always_visible' => true],
                    ['page' => 'user_profile',  'label' => 'Il mio dossier',    'icon' => 'fa-id-badge',      'always_visible' => true],
                    ['page' => '2fa_settings',  'label' => 'Sicurezza account', 'icon' => 'fa-shield-halved'],
                ],
            ],
            [
                'key'     => 'brand',
                'label'   => 'Brand & Partnership',
                'icon'    => 'fa-tags',
                'items'   => [
                    ['page' => 'brand',                'label' => 'Directory brand',          'icon' => 'fa-tags'],
                    ['page' => 'brand_referents',      'label' => 'Referenti & requisiti',    'icon' => 'fa-user-shield'],
                    ['page' => 'gap_analysis',         'label' => 'Gap analysis',             'icon' => 'fa-chart-bar'],
                    ['page' => 'brand_technologies',   'label' => 'Tecnologie & Servizi',     'icon' => 'fa-microchip'],
                    ['page' => 'brand_distributors',   'label' => 'Distributori',             'icon' => 'fa-truck-field'],
                ],
            ],
            [
                'key'     => 'competenze',
                'label'   => 'Competenze & Formazione',
                'icon'    => 'fa-graduation-cap',
                'items'   => [
                    ['page' => 'manage_technologies',     'label' => 'Tecnologie',             'icon' => 'fa-microchip'],
                    ['page' => 'tech_skill_matrix',       'label' => 'Skill matrix',           'icon' => 'fa-table-cells'],
                    ['page' => 'catalogo_certificazioni', 'label' => 'Catalogo certificazioni','icon' => 'fa-certificate'],
                    ['page' => 'report_certificazioni',   'label' => 'Report certificazioni',  'icon' => 'fa-chart-pie'],
                    ['page' => 'visualizza_storico',      'label' => 'Storico competenze',     'icon' => 'fa-clock-rotate-left'],
                    ['page' => 'training_plans',          'label' => 'Master calendar',        'icon' => 'fa-calendar-days'],
                    ['page' => 'upload_certificato',      'label' => 'Carica certificato',     'icon' => 'fa-upload'],
                    ['page' => 'cert_import_cisco',       'label' => 'Import certificazioni Cisco','icon' => 'fa-file-import'],
                    ['page' => 'programmazione',          'label' => 'Pianifica esame',        'icon' => 'fa-calendar-plus'],
                    ['page' => 'segreteria',              'label' => 'Segreteria & Logistica', 'icon' => 'fa-concierge-bell'],
                ],
            ],
            [
                'key'     => 'recruiting',
                'label'   => 'Recruiting & Agenzie',
                'icon'    => 'fa-user-plus',
                'items'   => [
                    ['page' => 'recruiting_posizioni',  'label' => 'Posizioni aperte',     'icon' => 'fa-briefcase'],
                    ['page' => 'recruiting_candidati',  'label' => 'Pipeline candidati',   'icon' => 'fa-users-line'],
                    ['page' => 'publish_posizione',     'label' => 'Pubblica su portali',  'icon' => 'fa-bullhorn'],
                    ['page' => 'candidato_profilo',     'label' => 'Dossier candidati',    'icon' => 'fa-folder-tree'],
                    ['page' => 'documenti',             'label' => 'Archivio documenti',   'icon' => 'fa-folder-open'],
                    ['page' => 'manage_clients',        'label' => 'Anagrafica clienti',   'icon' => 'fa-handshake'],
                    ['page' => 'recruiting_agenzie',    'label' => 'Agenzie',              'icon' => 'fa-building-user'],
                    ['page' => 'recruiting_contratti',  'label' => 'Contratti agenzie',    'icon' => 'fa-file-contract'],
                    ['page' => 'cv_import',             'label' => 'Importa CV',           'icon' => 'fa-file-import'],
                    ['page' => 'linkedin_sync',         'label' => 'Sync LinkedIn',        'icon' => 'fa-linkedin'],
                    ['page' => 'mass_upload',           'label' => 'Import massivo (multi)','icon' => 'fa-cloud-arrow-up'],
                ],
            ],
            [
                'key'     => 'projects',
                'label'   => 'Progetti & Referenze',
                'icon'    => 'fa-diagram-project',
                'items'   => [
                    ['page' => 'projects',         'label' => 'Progetti realizzati',  'icon' => 'fa-folder-tree'],
                    ['page' => 'project_clients',  'label' => 'Anagrafica clienti',   'icon' => 'fa-handshake'],
                    ['page' => 'project_import',   'label' => 'Import massivo CSV',   'icon' => 'fa-file-import'],
                ],
            ],
            [
                'key'     => 'commesse',
                'label'   => 'Gestione Commesse',
                'icon'    => 'fa-file-invoice-dollar',
                // v1.8.50 - Il menu era un elenco piatto di quindici voci in cui
                // anagrafiche, acquisizione dati e analisi erano mescolate, e in cui
                // le cinque voci di import occupavano un terzo dello spazio pur
                // essendo le meno usate.
                //
                // L'ordine segue ora il flusso della rendicontazione analitica:
                // prima le ANAGRAFICHE, che sono le dimensioni su cui si aggrega;
                // poi l'ACQUISIZIONE, che porta i fatti; infine l'ANALISI, che legge
                // le misure.
                //
                // Il raggruppamento e' reso dall'ordine e dalle etichette, non da
                // voci separatore: la personalizzazione del menu (drag&drop) assume
                // che ogni voce abbia una chiave `page`, e una voce priva di pagina
                // verrebbe scartata dal normalizzatore dopo aver generato un warning.
                // Introdurre i separatori richiederebbe di modificare anche il
                // renderer in header.php, che non fa parte di questo pacchetto.
                'items'   => [
                    ['page' => 'manage_projects',   'label' => 'Commesse / Progetti',         'icon' => 'fa-briefcase'],
                    ['page' => 'tech_registry',     'label' => 'Anagrafica Tecnica',          'icon' => 'fa-user-gear'],
                    ['page' => 'tech_units',        'label' => 'Unità Organizzative',         'icon' => 'fa-sitemap'],
                    ['page' => 'professionals',     'label' => 'Professionisti esterni',      'icon' => 'fa-user-tie'],
                    ['page' => 'manage_rate_bands', 'label' => 'Fasce costo orario',          'icon' => 'fa-euro-sign'],
                    // v1.9.23 — ordinativi Pratix: un ordinativo, piu' commesse
                    ['page' => 'pratix_orders',     'label' => 'Ordinativi Pratix',           'icon' => 'fa-file-invoice'],

                    // -- acquisizione dati --
                    ['page' => 'sync_commesse',     'label' => 'Sincronizzazione gestionale', 'icon' => 'fa-rotate'],
                    ['page' => 'import_commesse_db','label' => 'Connessione al gestionale',   'icon' => 'fa-database'],
                    ['page' => 'import_commesse',   'label' => 'Import commesse da file',     'icon' => 'fa-file-import'],
                    ['page' => 'import_intervention_reports','label' => 'Import rapporti da file','icon' => 'fa-file-arrow-up'],
                    ['page' => 'import_professionals','label' => 'Import professionisti',     'icon' => 'fa-id-card-clip'],
                    ['page' => 'import_control',    'label' => 'Controllo & Riconciliazione', 'icon' => 'fa-clipboard-check'],

                    // -- analisi e rendicontazione --
                    ['page' => 'dgb_activities',    'label' => 'Attività & Rendicontazione DGB','icon' => 'fa-diagram-project'],
                    ['page' => 'timesheet',         'label' => 'Timesheet',                   'icon' => 'fa-table-list'],
                    ['page' => 'workload_overview', 'label' => 'Carico & Sovrapposizioni',    'icon' => 'fa-people-arrows'],
                    ['page' => 'service_desk',      'label' => 'Service Desk',              'icon' => 'fa-headset'],
                    ['page' => 'it_service',        'label' => 'Relazione di Servizio IT',  'icon' => 'fa-server'],
                    ['page' => 'dir_report',        'label' => 'Report direzionale',        'icon' => 'fa-chart-pie'],
                    ['page' => 'project_gantt',     'label' => 'Gantt commesse',              'icon' => 'fa-chart-gantt'],
                ],
            ],
            [
                'key'     => 'amministrazione',
                'label'   => 'Amministrazione',
                'icon'    => 'fa-briefcase',
                'items'   => [
                    ['page' => 'manage_employees',     'label' => 'Anagrafica dipendenti',  'icon' => 'fa-id-card'],
                    ['page' => 'import_employees_xlsx','label' => 'Import dipendenti XLSX', 'icon' => 'fa-file-arrow-up'],
                    ['page' => 'merge_employees',      'label' => 'Verifica & Merge anagrafiche', 'icon' => 'fa-people-arrows'],
                    ['page' => 'export_employees',     'label' => 'Estrazione anagrafica',  'icon' => 'fa-file-export'],
                    ['page' => 'finance_overview',     'label' => 'Finance',                'icon' => 'fa-chart-pie'],
                    ['page' => 'hr_reference_values',  'label' => 'Valori di riferimento HR','icon' => 'fa-sliders'],
                    ['page' => 'manage_departments',   'label' => 'Dipartimenti / Unità Org.', 'icon' => 'fa-sitemap'],
                    ['page' => 'organigramma',        'label' => 'Organigramma',            'icon' => 'fa-diagram-project'],
                    ['page' => 'manager_users',        'label' => 'Utenti',                 'icon' => 'fa-user-cog'],
                    ['page' => 'manage_roles',         'label' => 'Ruoli',                  'icon' => 'fa-users-gear'],
                    ['page' => 'manage_permissions',   'label' => 'Permessi ruoli',         'icon' => 'fa-key'],
                    ['page' => 'manage_companies',     'label' => 'Aziende & Sedi',         'icon' => 'fa-building'],
                    ['page' => 'manage_work_modes',    'label' => 'Modalità lavoro',        'icon' => 'fa-clock'],
                    ['page' => 'entity_change_log',    'label' => 'Audit log (modifiche)',  'icon' => 'fa-clock-rotate-left'],
                    ['page' => 'view_logs',            'label' => 'Log applicazione',       'icon' => 'fa-file-lines'],
                    ['page' => 'device_manager',       'label' => 'Gestione dispositivi',   'icon' => 'fa-laptop'],
                    ['page' => 'device_handover',      'label' => 'Modulo consegna/restituzione', 'icon' => 'fa-file-signature'],
                    ['page' => 'device_import',        'label' => 'Import dispositivi',     'icon' => 'fa-file-import'],
                    ['page' => 'device_export',        'label' => 'Export dispositivi',     'icon' => 'fa-file-export'],
                    ['page' => 'device_print',         'label' => 'Stampa elenco',          'icon' => 'fa-print'],
                    ['page' => 'credly_sync',          'label' => 'Sync Credly',            'icon' => 'fa-shield-halved'],
                    ['page' => 'credly_manual_import', 'label' => 'Credly offline',         'icon' => 'fa-file-arrow-up'],
                    ['page' => 'branding',             'label' => 'Branding & Tema',        'icon' => 'fa-palette'],
                    ['page' => 'settings',             'label' => 'Impostazioni generali',  'icon' => 'fa-gears'],
                    ['page' => 'smtp_settings',        'label' => 'Email SMTP',             'icon' => 'fa-envelope'],
                    ['page' => 'config_notifiche',     'label' => 'Config notifiche',       'icon' => 'fa-bell'],
                    ['page' => 'notifications',        'label' => 'Centro notifiche',       'icon' => 'fa-bell-concierge'],
                ],
            ],
            [
                'key'     => 'sistema',
                'label'   => 'Sistema',
                'icon'    => 'fa-cog',
                'items'   => [
                    ['page' => 'menu_customizer',      'label' => 'Personalizza menu',      'icon' => 'fa-bars-staggered', 'always_visible' => true],
                    ['page' => 'system_console',       'label' => 'Console di sistema',     'icon' => 'fa-sliders'],
                    // v1.9.21 — diagnostica errori PHP, riservata al super admin:
                    // il registro contiene percorsi e frammenti di query
                    ['page' => 'system_errors',        'label' => 'Diagnostica errori',    'icon' => 'fa-bug'],
                    ['page' => 'recycle_bin',          'label' => 'Cestino',                'icon' => 'fa-trash-arrow-up'],
                    ['page' => 'db_upgrade',           'label' => 'DB Upgrade (motore)',    'icon' => 'fa-database'],
                    ['page' => 'schema_check_upgrade', 'label' => 'Schema check',           'icon' => 'fa-magnifying-glass'],
                    ['page' => 'health_check',         'label' => 'Health check',           'icon' => 'fa-heart-pulse'],
                    ['page' => 'system_backup',        'label' => 'Backup completo',        'icon' => 'fa-box-archive'],
                    ['page' => 'verify_integrity',     'label' => 'Verifica integrità',     'icon' => 'fa-shield-check'],
                    ['page' => 'cleanup_orphans',      'label' => 'Pulizia orfani',         'icon' => 'fa-broom'],
                    ['page' => 'migrate_links',        'label' => 'Migrazione link',        'icon' => 'fa-link'],
                    ['page' => 'diag',                 'label' => 'Diagnostica',            'icon' => 'fa-stethoscope'],
                    ['page' => 'file_manager',         'label' => 'File manager',           'icon' => 'fa-folder'],
                    ['page' => 'manage_enum_proposals','label' => 'Proposte ENUM',          'icon' => 'fa-list-check'],
                ],
            ],
        ];
    }

    /**
     * Carica la configurazione effettiva del menu per l'utente.
     * Priorità: utente → ruolo → default.
     * Restituisce la struttura sezioni → items già filtrata per permessi.
     */
    public function loadMenuFor(int $user_id, int $role_id): array
    {
        $cfg = $this->loadPreference('user', $user_id);
        if ($cfg === null) $cfg = $this->loadPreference('role', $role_id);
        if ($cfg === null) $cfg = self::defaultMenu();

        // Merge intelligente: applica la config salvata sopra il default
        $cfg = $this->mergeWithDefault($cfg);

        // Filtra per permessi RBAC
        return $this->filterByPermissions($cfg, $role_id);
    }

    /**
     * Recupera la preferenza salvata (config JSON) o null.
     */
    public function loadPreference(string $scope_type, int $scope_id): ?array
    {
        try {
            $s = $this->pdo->prepare("SELECT menu_config FROM menu_preferences WHERE scope_type=? AND scope_id=?");
            $s->execute([$scope_type, $scope_id]);
            $json = $s->fetchColumn();
            if ($json === false || $json === '') return null;
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Salva la configurazione (insert/update).
     */
    public function savePreference(string $scope_type, int $scope_id, array $config): bool
    {
        if (!in_array($scope_type, ['user','role'], true)) {
            throw new InvalidArgumentException('scope_type invalido');
        }
        $json = json_encode($this->sanitizeConfig($config), JSON_UNESCAPED_UNICODE);
        try {
            $s = $this->pdo->prepare(
                "INSERT INTO menu_preferences (scope_type, scope_id, menu_config)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE menu_config=VALUES(menu_config)"
            );
            return $s->execute([$scope_type, $scope_id, $json]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Cancella una preferenza (torna ai default).
     */
    public function deletePreference(string $scope_type, int $scope_id): bool
    {
        try {
            return $this->pdo->prepare("DELETE FROM menu_preferences WHERE scope_type=? AND scope_id=?")
                ->execute([$scope_type, $scope_id]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Sanitizza una configurazione utente: limita ai campi consentiti,
     * preserva l'integrità di "always_visible".
     */
    private function sanitizeConfig(array $config): array
    {
        $default = self::defaultMenu();
        $default_lookup_sec = [];
        $default_lookup_item = [];
        foreach ($default as $sec) {
            $default_lookup_sec[$sec['key']] = $sec;
            foreach ($sec['items'] as $it) {
                $default_lookup_item[$it['page']] = $it;
            }
        }

        $clean = [];
        foreach ($config as $sec) {
            if (!isset($sec['key']) || !isset($default_lookup_sec[$sec['key']])) continue;
            $base_sec = $default_lookup_sec[$sec['key']];
            $clean_sec = [
                'key'     => $base_sec['key'],
                'label'   => $base_sec['label'],
                'icon'    => $base_sec['icon'],
                'visible' => isset($sec['visible']) ? (bool)$sec['visible'] : true,
                'items'   => [],
            ];
            $items_input = $sec['items'] ?? [];
            $seen = [];
            foreach ($items_input as $it) {
                $page = $it['page'] ?? null;
                if (!$page || !isset($default_lookup_item[$page])) continue;
                if (isset($seen[$page])) continue;
                $seen[$page] = true;
                $base_it = $default_lookup_item[$page];
                $clean_sec['items'][] = [
                    'page'    => $base_it['page'],
                    'label'   => $base_it['label'],
                    'icon'    => $base_it['icon'],
                    'visible' => !empty($base_it['always_visible']) || (isset($it['visible']) ? (bool)$it['visible'] : true),
                ];
            }
            // Aggiungo eventuali items mancanti dalla config (sezione potrebbe avere voci nuove dopo aggiornamento)
            foreach ($base_sec['items'] as $base_it) {
                if (!isset($seen[$base_it['page']])) {
                    $clean_sec['items'][] = [
                        'page'    => $base_it['page'],
                        'label'   => $base_it['label'],
                        'icon'    => $base_it['icon'],
                        'visible' => true,
                    ];
                }
            }
            $clean[] = $clean_sec;
        }
        // Aggiungo sezioni mancanti
        $seen_secs = array_column($clean, 'key');
        foreach ($default as $base_sec) {
            if (!in_array($base_sec['key'], $seen_secs, true)) {
                $items_norm = array_map(fn($it) => array_merge($it, ['visible' => true]), $base_sec['items']);
                $clean[] = array_merge($base_sec, ['visible' => true, 'items' => $items_norm]);
            }
        }
        return $clean;
    }

    /**
     * Merge config salvata + default: usa lo stesso sanitizer per riempire i buchi.
     */
    private function mergeWithDefault(array $config): array
    {
        // v1.7.48: vero merge — aggiunge voci/sezioni NUOVE introdotte nel default
        // dopo che l'utente/ruolo ha salvato una config personalizzata.
        // Senza questa logica, le nuove voci di menu introdotte dalle release
        // successive sarebbero invisibili per utenti con preferenze salvate.
        $config = $this->sanitizeConfig($config);
        $default = self::defaultMenu();

        // Indicizzo la config salvata per chiave sezione
        $saved_sections = [];
        foreach ($config as $idx => $sec) {
            $saved_sections[$sec['key']] = $idx;
        }

        // Per ogni sezione del DEFAULT
        foreach ($default as $def_sec) {
            $key = $def_sec['key'];
            if (!isset($saved_sections[$key])) {
                // Sezione NUOVA → aggiungo intera con visible=true
                $new_sec = $def_sec;
                $new_sec['visible'] = true;
                foreach ($new_sec['items'] as &$it) { $it['visible'] = true; }
                unset($it);
                $config[] = $new_sec;
                continue;
            }

            // Sezione esistente → confronto pagina per pagina
            $sec_idx = $saved_sections[$key];
            $saved_pages = [];
            foreach ($config[$sec_idx]['items'] as $it) {
                $saved_pages[$it['page']] = true;
            }
            foreach ($def_sec['items'] as $def_it) {
                if (!isset($saved_pages[$def_it['page']])) {
                    // Voce NUOVA → aggiungo a fine sezione con visible=true
                    $def_it['visible'] = true;
                    $config[$sec_idx]['items'][] = $def_it;
                }
            }
        }
        return $config;
    }

    /**
     * Filtra le voci in base ai permessi RBAC dell'utente. Le voci con
     * 'always_visible'=true (es. Dashboard, Personalizza menu) restano sempre.
     */
    private function filterByPermissions(array $config, int $role_id): array
    {
        $default_items = [];
        foreach (self::defaultMenu() as $sec) {
            foreach ($sec['items'] as $it) $default_items[$it['page']] = $it;
        }

        $out = [];
        foreach ($config as $sec) {
            if (empty($sec['visible'])) continue;
            $visible_items = [];
            foreach ($sec['items'] as $it) {
                if (empty($it['visible'])) continue;
                $base = $default_items[$it['page']] ?? [];
                $always = !empty($base['always_visible']);
                if (!$always && !$this->userCanSee($it['page'], $role_id)) continue;
                $visible_items[] = $it;
            }
            if (!empty($visible_items)) {
                $sec_copy = $sec;
                $sec_copy['items'] = $visible_items;
                $out[] = $sec_copy;
            }
        }
        return $out;
    }

    /**
     * Verifica se l'utente corrente può vedere la pagina.
     *
     * v1.7.30: ora considera CORRETTAMENTE la gerarchia:
     *   1. Super Admin (role_id=1) → sempre OK
     *   2. user_permissions (override specifico per utente) — priorità
     *   3. role_permissions (fallback per ruolo)
     *
     * Specifica priorità: se l'utente ha un override esplicito (anche solo 0
     * o solo 1) per la pagina, quello prevale sul valore del ruolo.
     */
    private function userCanSee(string $page, int $role_id): bool
    {
        if ($role_id === 1) return true; // super admin vede tutto

        $page_name = $page . '.php';
        $user_id = (int)($_SESSION['user_id'] ?? 0);

        // ── Override utente specifico (priorità massima) ──
        if ($user_id > 0) {
            try {
                $s = $this->pdo->prepare("SELECT can_view FROM user_permissions WHERE user_id=? AND page_name=? LIMIT 1");
                $s->execute([$user_id, $page_name]);
                $v = $s->fetchColumn();
                $s->closeCursor();
                if ($v !== false && $v !== null) {
                    // Override esplicito (0 o 1) — prevale sul ruolo
                    return (bool)(int)$v;
                }
            } catch (Throwable $e) {
                // Tabella user_permissions inesistente o errore — fallback ruolo
            }
        }

        // ── Permesso del ruolo (fallback) ──
        try {
            $s = $this->pdo->prepare("SELECT can_view FROM role_permissions WHERE role_id=? AND page_name=? LIMIT 1");
            $s->execute([$role_id, $page_name]);
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
