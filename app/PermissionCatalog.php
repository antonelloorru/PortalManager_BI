<?php
/**
 * PortalManager — app/PermissionCatalog.php (v1.9.83)
 *
 * Catalogo delle pagine soggette a permesso, UNICO punto in cui descriverle.
 * Prima era l'array $page_map dentro manage_permissions.php: ogni nuova pagina richiedeva
 * di modificare quella pagina e di scrivere una migration con i permessi. Ora:
 *   - le etichette curate stanno qui (sections());
 *   - le pagine non descritte qui vengono comunque scoperte (discover()) dal menu
 *     (MenuManager::defaultMenu), dal router (Router::PAGES) e dai permessi a DB;
 *   - RbacSync allinea il catalogo a DB (tabella `permissions`) e i permessi di ruolo.
 * Aggiungere una pagina = aggiungerla al menu o al router. Descriverla qui e' facoltativo.
 */

declare(strict_types=1);

final class PermissionCatalog
{
    /** Pagine pubbliche o sempre consentite: fuori dalla matrice. */
    public const SKIP = ['index.php', 'login.php', 'logout.php', 'unauthorized.php', 'auth_microsoft.php',
                         'password_reset.php', '2fa_verify.php', '2fa_settings.php', 'r.php'];

    /** Sezione => [pagina.php => [etichetta, descrizione]] — etichette curate. */
    public static function sections(): array
    {
        return [
        'Brand & Partnership' => [
            'brand.php'                  => ['Directory Brand', 'CRUD brand, contatti, priorità'],
            'brand_referents.php'        => ['Referenti & Requisiti', 'Assegna referenti, storico requisiti'],
            'brand_technologies.php'     => ['Catalogo tecnologie', 'Tech/servizi/prodotti per brand'],
            'brand_distributors.php'     => ['Distributori', 'Ranking e partnership'],
            'brand_overview.php'         => ['↳ Vista 360° brand', 'Sub-route: vista unificata di un singolo brand (referenti, distributori, tecnologie)'],
            'gap_analysis.php'           => ['Gap Analysis', 'Scostamento cert richieste vs detenute'],
        ],
        'Competenze & Formazione' => [
            'catalogo_certificazioni.php'=> ['Catalogo certificazioni', 'CRUD anagrafica cert con storicizzazione'],
            'report_certificazioni.php'  => ['Report certificazioni', 'Tabella cert conseguite + elimina'],
            'visualizza_storico.php'     => ['Storico competenze', 'Filtri per brand/dipendente/periodo'],
            'upload_certificato.php'     => ['Carica certificato', 'Upload PDF certificazione'],
            'cert_import_cisco.php'      => ['Import certificazioni Cisco', 'Importer XLSX report Cisco: aggiorna cert acquisite dei dipendenti'],
            'cert_import_omnissa.php'    => ['Import certificazioni Omnissa', 'Importer XLSX report Omnissa: upsert cert dei dipendenti'],
            'training_plans.php'         => ['Master Calendar', 'Calendario impegni per ruolo'],
            'programmazione.php'         => ['Pianifica attività', 'Esami, workshop, convegni'],
            'segreteria.php'             => ['Segreteria & Logistica', 'Richieste logistiche'],
            'manage_enum_proposals.php'  => ['Proposte ENUM', 'Approva/rifiuta nuovi valori ENUM'],
            'manage_technologies.php'    => ['Tecnologie skill', 'Catalogo skill trasversale (skill matrix)'],
            'tech_skill_matrix.php'      => ['Skill matrix tecnologica', 'Matrice competenze dipendenti'],
        ],
        'Recruiting & Agenzie' => [
            'recruiting_posizioni.php'   => ['Posizioni aperte', 'Job positions + multiposting'],
            'recruiting_candidati.php'   => ['Pipeline candidati', 'ATS con soft delete'],
            'candidato_profilo.php'      => ['↳ Dossier candidato', 'Sub-route: accesso via lista Candidati'],
            'recruiting_agenzie.php'     => ['Agenzie selezione', 'Anagrafica + contatti'],
            'wp_ats_sync.php'            => ['Sito web (WordPress)', 'Pubblicazione posizioni e prelievo candidature dal sito'],
            'wp_ats_settings.php'        => ['Sito web — Impostazioni', 'Connessione al plugin WordPress (solo Super Admin)'],
            'wp_ats_setup.php'           => ['Sito web — Configurazione guidata', 'Wizard connessione plugin WordPress (solo Super Admin)'],
            'recruiting_contratti.php'   => ['Contratti agenzie', 'Upload firmato + versioning'],
            'documenti.php'              => ['Archivio documenti', 'Gestione documentale con ACL'],
            'publish_posizione.php'      => ['Pubblicazione posizioni', 'Multi-channel posting'],
            'cv_import.php'              => ['Importa CV', 'Upload e parsing CV PDF/DOCX'],
            'import_candidates_linkedin.php' => ['Importa candidati LinkedIn', 'Report candidati LinkedIn -> anagrafiche + candidature'],
            'candidate_hire.php'         => ['↳ Assumi candidato', 'Sub-route: accesso via dossier candidato (azione finale pipeline)'],
            'position_history.php'       => ['↳ Storico posizioni', 'Sub-route: accesso via Profilo dipendente'],
            'export_positions_pdf.php'   => ['↳ Export posizioni PDF', 'Sub-route: azione export dalla lista Posizioni'],
            'export_positions_xlsx.php'  => ['↳ Export posizioni XLSX', 'Sub-route: azione export dalla lista Posizioni'],
        ],
        'Progetti & Referenze' => [
            'projects.php'               => ['Progetti realizzati', 'Lista progetti + filtri multi-criterio'],
            'project_form.php'           => ['Form progetto', 'CRUD progetto + tag M:N'],
            'project_clients.php'        => ['Anagrafica clienti', 'CRUD clienti progetti'],
            'project_import.php'         => ['Import massivo CSV', 'Bulk import progetti con auto-create'],
        ],
        'Gestione Commesse' => [
            'manage_projects.php'        => ['Commesse / Progetti', 'Elenco e creazione commesse, azienda esecutrice da prefisso'],
            'project_dashboard.php'      => ['↳ Scheda commessa', 'Sub-route: dashboard a tab (anagrafica, presales, team, redditività, consuntivo)'],
            'manage_rate_bands.php'      => ['Fasce costo orario', 'Tariffe per fascia × tipologia (Aziendale/Cliente/Commerciale) × regime, storicizzate'],
            'import_commesse.php'        => ['Import commesse XLSX', 'Import massivo commesse (UPSERT su codice commessa)'],
            'pratix_import.php'          => ['Import Pratix', 'Ingestione report Pratix (.xls/.xlsx) -> cm_pratix_ext'],
            'import_commesse_db.php'      => ['Import Commesse DB', 'Import commesse dall export nativo del gestionale (CSV separatore pipe)'],
            'professionals.php'          => ['Anagrafica Professionisti', 'Operatori importati non presenti tra i dipendenti; merge verso anagrafica dipendenti'],
            'import_professionals.php'   => ['Import Professionisti', 'Import operatori dal gestionale (CSV separatore pipe); credenziali escluse'],
            'import_intervention_reports.php' => ['Import rapporti di intervento', 'Import consuntivo interventi (UPSERT su codice rapporto)'],
            'import_control.php'         => ['Controllo & Riconciliazione', 'Anomalie import, alias persistenti, export/import XLSX, riapplicazione massiva'],
            'timesheet.php'              => ['Timesheet', 'Ore per risorsa/giorno da rapporti e voci manuali, saturazione, export XLSX'],
            'project_gantt.php'          => ['Gantt commesse', 'Diagramma di Gantt di portfolio: pianificato vs effettivo dai rapporti'],
            'workload_overview.php'      => ['Carico & Sovrapposizioni', 'Impegno persone per commessa, contemporaneità, sovraccarichi, contesa risorse'],
            'dgb_activities.php'         => ['Attività & Rendicontazione DGB', 'Gerarchia pianificazione/attività/incaricati DogoBit, KPI SLA e consuntivo, distribuzione carico, data quality, import batch con diff'],
            'dgb_api.php'                => ['↳ API attività DGB', 'Sub-route: endpoint JSON parametrizzato (tabella, KPI, grafici, anomalie)'],
            // v1.10.01 — Progetti PRJ e Analisi Gara & Dimensionamento
            'manage_projects_prj.php'    => ['Progetti PRJ (elenco)', 'Permesso virtuale: vista ?view=prj di Commesse / Progetti (consultazione, creazione, export)'],
            'prj_dashboard.php'          => ['↳ Scheda progetto PRJ', 'Sub-route: anagrafica, gara, servizi, asset e volumi, profili, costi, scenari'],
            'prj_dashboard_calc.php'     => ['↳ Calcolo scenari PRJ', 'Permesso virtuale: esecuzione dei calc run'],
            'prj_link.php'               => ['↳ Collegamento PRJ - commessa SP', 'Permesso virtuale: collega, scollega, sostituisce la commessa SP (separato da edit)'],
            'prj_costs_real.php'         => ['↳ Costi reali dipendenti (PRJ)', 'Permesso virtuale: costi reali nei confronti stimato/consuntivo (stesso perimetro della Compensation)'],
            'prj_parameters.php'         => ['Parametri dimensionamento', 'Parametri globali versionati: oneri, H24, produttività, zone, nearshore, dotazioni, sede, overhead'],
            'prj_history.php'            => ['Scenari & confronti progetti', 'Calc run multi-progetto per periodo, scenario, zona, stato, commessa SP'],
            // v1.9.82 — pagine presenti nel menu ma assenti dalla matrice: i permessi esistevano a DB
            // (voce visibile) ma non si potevano vedere ne' revocare da qui
            'pratix_orders.php'          => ['Ordinativi Pratix', 'Ordinativi, fatturazione e cliente effettivo da Pratix'],
            'sync_commesse.php'          => ['Sincronizzazione gestionale', 'Import/sync dal DB del gestionale, pianificazione giornaliera'],
            'tech_registry.php'          => ['Anagrafica tecnici', 'Registro tecnici IT e profili'],
            'tech_units.php'             => ['Unità organizzative tecniche', 'Unità e sotto-unità tecniche, assegnazioni'],
            'service_desk.php'           => ['Service Desk', 'Ticket, prese in carico, squadra, costi e OBJ_2 del Service Desk'],
            'service_soc.php'           => ['Service SOC', 'Ticket del sistema di gestione SOC aggregati con moduli, commesse e dipendenti; import XLSX e sync DB SOC'],
            'it_service.php'             => ['Relazione di Servizio IT', 'Operatività per incaricato, linea, settore, modalità; stampa ed export'],
            'tech_report.php'            => ['Relazione Tecnici', 'Tecnico × codice linea, metriche di dettaglio, moduli valorizzati / non valorizzati, rapporti di intervento per tipologia e provenienza; stampa ed export'],
            'tech_report_economics.php'  => ['↳ Relazione Tecnici: valori', 'Permesso virtuale: produzione teorica e valore addebitato dei moduli, valore dei contratti WTS-SD (scheda ServiceDesk) nella Relazione Tecnici e nei suoi export'],
            'dir_report.php'             => ['Report direzionale', 'Portafoglio commesse, margini, rischio, schede commerciali per agente'],
            'cm_search.php'              => ['Ricerca', 'Vista tabellare filtrabile su tutti gli archivi del modulo; export CSV/XLSX/DOCX/PDF (ogni archivio richiede la vista della pagina sorgente)'],
            'cm_search_economics.php'    => ['↳ Ricerca: importi e costi', 'Permesso virtuale: colonne economiche (valori, costi, ricavi, margini, tariffe) nella Ricerca e nei suoi export'],
            'relazione_servizio_it.php'  => ['↳ Relazione servizio IT (legacy)', 'Pagina storica della relazione IT'],
            'report_servizi_it.php'      => ['↳ Report servizi IT (legacy)', 'Pagina storica dei report servizi IT'],
            'project_view.php'           => ['↳ Vista progetto', 'Sub-route: dettaglio progetto realizzato'],
        ],
        'Dispositivi & Asset' => [
            'device_manager.php'         => ['Gestione dispositivi', 'CRUD asset assegnati'],
            'device_handover.php'        => ['Consegna/restituzione', 'Modulo handover firmato'],
            'device_import.php'          => ['Import dispositivi', 'CSV upload massivo'],
            'device_export.php'          => ['Export dispositivi', 'Esporta CSV/XLSX'],
            'device_print.php'           => ['Stampa modulo', 'PDF asset list'],
        ],
        'Anagrafica & HR' => [
            'manage_employees.php'       => ['Anagrafica dipendenti', 'CRUD HR + documenti'],
            'manage_employees_compensation.php' => ['↳ Compensation & Benefit (riservato)', 'Permesso virtuale: visibilità RAL/premio/km/fuori sede nella scheda dipendente'],
            'import_employees_xlsx.php'  => ['Import dipendenti XLSX', 'Importer massivo anagrafica dipendenti da XLSX/CSV'],
            'merge_employees.php'        => ['Verifica & Merge anagrafiche', 'Identifica duplicati e unifica record (riservato HR/Super Admin)'],
            'export_employees.php'       => ['Estrazione anagrafica dipendenti', 'Export XLSX/CSV anagrafico-contrattuale (Amministratore, HR, Responsabile Finanziario)'],
            'finance_overview.php'       => ['Finance', 'Quadro dipendenti per il controllo di gestione, con filtri ed export (Finance, HR, Amministratore)'],
            'finance_compare.php'        => ['Confronto annualità', 'Confronto metriche economiche tra due esercizi, per dipendente e aggregato (Finance, HR, Amministratore)'],
            'import_economics_xlsx.php'  => ['Import dati economici', 'Import massivo dati economici per anno di competenza da template XLSX/CSV'],
            'hr_economic_years.php'      => ['Annualità economiche', 'Catalogo esercizi: corrente, blocco, clonazione dati tra anni'],
            'hr_reference_values.php'    => ['Valori di riferimento HR', 'Parametri globali costo pieno/FTE, con storico delle modifiche'],
            'employee_compensation.php'  => ['Scheda Compensation & Benefit', 'Dati economici del dipendente, costo pieno e valore FTE (riservato HR)'],
            'manage_departments.php'     => ['Dipartimenti / Unità Organizzative', 'Lookup dipartimenti (Servizio a Valore / Non a Valore) con storicizzazione'],
            'employee_profile.php'       => ['↳ Profilo dipendente', 'Sub-route: accesso via Anagrafica dipendenti'],
            'employee_cv.php'            => ['↳ Generazione CV', 'Sub-route: accesso via Profilo dipendente'],
            'user_profile.php'           => ['Profilo utente', 'Self-edit dati personali'],
            'organigramma.php'           => ['Organigramma', 'Struttura organizzativa e riporti'],   // v1.9.82
        ],
        'Sync esterni' => [
            'linkedin_sync.php'          => ['Sync LinkedIn', 'Importa skill da profili LinkedIn'],
            'credly_sync.php'            => ['Sync Credly', 'Importa badge automatico'],
            'credly_manual_import.php'   => ['Credly offline', 'Carica JSON badge esportato'],
        ],
        'Amministrazione' => [
            'manager_users.php'          => ['Gestione utenti', 'Account, password, ruoli'],
            'manage_users_2fa.php'       => ['2FA utenti', 'Reset/forza 2FA per utenti'],
            'manage_companies.php'       => ['Aziende & Sedi', 'Struttura societaria'],
            'manage_clients.php'         => ['Anagrafica clienti', 'CRUD clienti aziendali'],
            'manage_work_modes.php'      => ['Modalità lavoro', 'Smart working, ibrido...'],
            'manage_permissions.php'     => ['Permessi RBAC', 'Matrice ruoli + override utente'],
            'manage_roles.php'           => ['Gestione ruoli', 'Definizione ruoli'],
            'mass_upload.php'            => ['Import massivo Smart', 'CSV 12 tipi con auto-create'],
            'mass_upload_jobs.php'       => ['↳ Job import storici', 'Sub-route: storico accesso via Import massivo'],
            'mass_upload_review.php'     => ['↳ Review staging', 'Sub-route: review accesso via Import massivo'],
            'branding.php'               => ['Branding', 'Logo, favicon, colore, nome'],
            'menu_customizer.php'        => ['Personalizza menu', 'Personalizzazione ordine, visibilità e sezioni della navigation bar'],
            'entity_change_log.php'      => ['Audit modifiche', 'Log delle modifiche su entità'],
            'notifications.php'          => ['Notifiche', 'Centro notifiche utente'],
            'file_manager.php'           => ['File manager', 'Browser file server'],
        ],
        'Sistema' => [
            'config_notifiche.php'       => ['Config notifiche', 'Soglie alert'],
            'smtp_settings.php'          => ['Configurazione SMTP', 'Server email + test'],
            'settings.php'               => ['Impostazioni', 'Nome app, colore, email sistema'],
            'system_console.php'       => ['Console di sistema', 'Aggiornamenti ZIP, migrazioni, SQL Runner e log in una sola pagina'],
            'sso_settings.php'         => ['SSO Microsoft 365 / MFA', 'Configurazione Single Sign-On Microsoft 365 e MFA'],
            'perf_center.php'          => ['Prestazioni', 'Copie delle viste lente e profiler delle query'],
            'recycle_bin.php'          => ['Cestino', 'Ripristino dei record cancellati per errore in tutto il portale (soft-delete + restore)'],
            'db_upgrade.php'             => ['Aggiornamento DB', 'Migrazioni versione'],
            'system_update.php'          => ['Aggiorna sistema', 'Upload ZIP, backup, update file e DB'],
            'system_backup.php'          => ['Backup sistema', 'Genera ZIP completo DB+file'],
            'view_logs.php'              => ['Log applicazione', 'Audit log'],
            'health_check.php'           => ['Health check', 'Diagnostica sistema'],
            'sql_runner.php'             => ['SQL Runner', 'Esegue script SQL/migration'],
            'schema_check_upgrade.php'   => ['Schema check', 'Verifica integrità schema DB'],
            'verify_integrity.php'       => ['Verifica integrità', 'Check file system + DB'],
            'cleanup_orphans.php'        => ['Pulizia orfani', 'Rimuovi record DB orfani'],
            'migrate_links.php'          => ['Migrazione link', 'Aggiorna link opachi'],
            'diag.php'                   => ['Diagnostica', 'Pagina di debug rapido'],
            'system_errors.php'          => ['Errori di sistema', 'Registro errori applicativi'],   // v1.9.82
            'rbac_sync.php'              => ['Sincronizzazione permessi', 'Catalogo pagine, regole di seeding, allineamento ruoli/utenti/permessi'],   // v1.9.83
        ],
    ];
    }

    /**
     * Catalogo completo: pagine curate + voci di menu + pagine del router + pagine con permessi a DB.
     * @return array<string, array{label:string, description:string, section:string, in_menu:int, in_router:int,
     *               in_curated:int, icon:?string, sort:int, hard_gate:?int}>
     */
    public static function discover(?PDO $pdo = null): array
    {
        $out = [];
        $sort = 0;
        foreach (self::sections() as $sec => $pgs) {
            foreach ($pgs as $f => [$label, $desc]) {
                $out[$f] = ['label' => $label, 'description' => $desc, 'section' => $sec, 'in_menu' => 0,
                            'in_router' => 0, 'in_curated' => 1, 'icon' => null, 'sort' => $sort += 10, 'hard_gate' => null];
            }
        }
        if (!class_exists('MenuManager') && is_file(__DIR__ . '/MenuManager.php')) require_once __DIR__ . '/MenuManager.php';
        if (class_exists('MenuManager')) {
            foreach (MenuManager::defaultMenu() as $ms) {
                foreach ($ms['items'] as $mi) {
                    $f = $mi['page'] . '.php';
                    if (in_array($f, self::SKIP, true)) continue;
                    if (!isset($out[$f])) {
                        $out[$f] = ['label' => $mi['label'], 'description' => 'Voce di menu', 'section' => $ms['label'],
                                    'in_menu' => 0, 'in_router' => 0, 'in_curated' => 0, 'icon' => null,
                                    'sort' => $sort += 10, 'hard_gate' => null];
                    }
                    $out[$f]['in_menu'] = 1;
                    $out[$f]['icon'] = $mi['icon'] ?? null;
                }
            }
            foreach ($out as $f => &$r) $r['hard_gate'] = MenuManager::HARD_GATES[substr($f, 0, -4)] ?? null;
            unset($r);
        }
        if (!class_exists('Router') && is_file(__DIR__ . '/Router.php')) require_once __DIR__ . '/Router.php';
        if (class_exists('Router')) {
            // servite dal router (PAGES) o per percorso diretto riservato (RESTRICTED)
            foreach (array_merge(Router::PAGES, Router::RESTRICTED) as $p) {
                $f = $p . '.php';
                if (in_array($f, self::SKIP, true)) continue;
                if (!isset($out[$f])) {
                    $out[$f] = ['label' => self::humanize($f), 'description' => 'Pagina servita dal router', 'section' => 'Altre pagine',
                                'in_menu' => 0, 'in_router' => 0, 'in_curated' => 0, 'icon' => null, 'sort' => $sort += 10, 'hard_gate' => null];
                }
                $out[$f]['in_router'] = 1;
            }
        }
        if ($pdo) {   // pagine con permessi registrati ma non descritte altrove
            try {
                $q = $pdo->query("SELECT DISTINCT page_name FROM role_permissions
                                  UNION SELECT DISTINCT page_name FROM user_permissions");
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) {
                    $f = self::normalize((string)$f);
                    if ($f === '' || isset($out[$f]) || in_array($f, self::SKIP, true)) continue;
                    $out[$f] = ['label' => self::humanize($f), 'description' => 'Permesso registrato a DB', 'section' => 'Altre pagine',
                                'in_menu' => 0, 'in_router' => 0, 'in_curated' => 0, 'icon' => null, 'sort' => $sort += 10, 'hard_gate' => null];
                }
            } catch (Throwable $e) {}
        }
        return $out;
    }

    /** 'brand' → 'brand.php'; spazi e maiuscole normalizzati. */
    public static function normalize(string $page): string
    {
        $p = strtolower(trim($page));
        if ($p === '') return '';
        return str_ends_with($p, '.php') ? $p : $p . '.php';
    }

    public static function humanize(string $f): string
    {
        return ucfirst(str_replace('_', ' ', preg_replace('/\.php$/', '', $f)));
    }
}
