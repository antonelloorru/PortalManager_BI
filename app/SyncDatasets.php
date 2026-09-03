<?php
/**
 * PortalManager — app/SyncDatasets.php  (v1.8.46)
 *
 * Registro dei dataset che alimentano il menu Gestione Commesse.
 *
 * Ogni dataset dichiara in un unico punto:
 *   · la query da eseguire sul gestionale (le stesse degli exporter ufficiali),
 *   · le intestazioni prodotte da quella query, che sono anche quelle attese
 *     nel CSV equivalente,
 *   · la tabella di destinazione nel portale, la chiave di upsert e la
 *     mappatura colonna → campo.
 *
 * È la fonte unica: connessione diretta e caricamento CSV leggono da qui, quindi
 * non possono divergere. Un CSV esportato dal gestionale con quelle intestazioni
 * è interscambiabile con la lettura diretta.
 *
 * ATTENZIONE — correzione rispetto alla v1.8.45. La tabella sorgente delle
 * commesse non è `contract` ma `forms_contract`, e i valori economici non sono
 * leggibili senza join: cliente, tipologia e commerciale stanno in tabelle
 * distinte. Le query qui sotto sono quelle reali degli exporter, verificate sul
 * dump del gestionale.
 */
final class SyncDatasets
{
    /**
     * Espressione riusabile: nome e cognome dell'operatore.
     * Isolata perché ricorre in quattro dataset.
     */
    private const OP_FULLNAME = "TRIM(CONCAT(COALESCE(%1\$s.first_name,''),' ',COALESCE(%1\$s.second_name,'')))";

    public static function all(): array
    {
        $opName = fn(string $a) => sprintf(self::OP_FULLNAME, $a);

        return [

            // ───────────────────────────────────────────────────────────────
            'commesse' => [
                'label'       => 'Commesse / Progetti',
                'description' => "Anagrafica e KPI economici delle commesse. Alimenta l'elenco "
                               . "Commesse / Progetti, il Gantt e il Carico risorse.",
                'target'      => 'cm_projects',
                'key'         => 'project_code',
                'source_table'=> 'forms_contract',
                'affects'     => ['Commesse / Progetti', 'Gantt commesse', 'Carico & Sovrapposizioni', 'Timesheet'],
                'sql' => "SELECT
    op.abbr AS `abbr`,
    {$opName('op')} AS `commerciale`,
    CONCAT('https://sp.wetechs.it/#/contract/editV2/', c.id) AS `link`,
    c.id AS `__source_id`,
    ct.code AS `tipo`,
    c.code AS `codice_commessa`,
    dv.code AS `divisione`,
    c.name AS `commessa`,
    cust.name AS `cliente`,
    c.description AS `descrizione`,
    c.internal_description AS `descrizione interna`,
    CASE c.status WHEN 'OPEN' THEN 'APERTA' WHEN 'CLOSED' THEN 'CHIUSA'
         WHEN 'SUSPENDED' THEN 'SOSPESA' WHEN 'DRAFT' THEN 'BOZZA' ELSE c.status END AS `stato`,
    COALESCE(c.is_under_compliance, 0) AS `compliance da verificare`,
    COALESCE(c.is_pre_authorized, 0) AS `compliance pre autorizzata`,
    DATE_FORMAT(c.start_date, '%d/%m/%Y') AS `data inizio`,
    DATE_FORMAT(c.end_date, '%d/%m/%Y') AS `data fine`,
    COALESCE(c.n_ano_open, 0) AS `anomalie aperte`,
    COALESCE(c.n_ano_open_block, 0) AS `anomalie bloccanti`,
    c.eco_status_till_now AS `stato economico a oggi`,
    c.eco_status AS `stato_economico`,
    c.economic_value_till_now AS `valore a oggi`,
    c.economic_value AS `valore`,
    COALESCE(c.tot_rep_cost, 0) AS `consuntivato`,
    c.margin_value_till_now AS `margine a oggi`,
    c.margin_value AS `margine`,
    c.residual_value_till_now AS `residuo a oggi`,
    c.residual_value AS `residuo`,
    COALESCE(c.residual_value_overdraft, 0) AS `fido su valore`,
    COALESCE(c.rep_cost_overdraft, 0) AS `fido su costi`,
    COALESCE(c.invoice_freq_months, 0) AS `Fatt. freq. (mesi)`,
    COALESCE(DATE_FORMAT(c.invoice_first_date, '%d/%m/%Y'), '') AS `Prima fatt.`
FROM forms_contract c
LEFT JOIN forms_division dv ON dv.id = c.id_division
LEFT JOIN forms_contract_type ct ON ct.id = c.id_contracttype
LEFT JOIN forms_company cust ON cust.id = c.id_customer_comp
LEFT JOIN dgb_operator op ON op.id = c.id_salesman
WHERE COALESCE(c.deleted, 0) = 0
ORDER BY c.code ASC",
                // colonna CSV => [campo destinazione, tipo]
                'map' => [
                    'codice_commessa'            => ['project_code', 'text'],
                    // v1.8.72 — la divisione appartiene alla COMMESSA, non alla
                    // persona: e' il legame che il gestionale dichiara, e copre
                    // tutte le 808 commesse. Il legame con l'operatore esiste
                    // (dgb_operator_can_see_forms_division) ma e' un permesso di
                    // visibilita': nessun operatore ne ha una sola, il minimo e'
                    // due e la media quattro.
                    'divisione'                  => ['division_code', 'text'],
                    'commessa'                   => ['name', 'text'],
                    'abbr'                       => ['abbr', 'text'],
                    'commerciale'                => ['commercial_ref', 'text'],
                    'link'                       => ['external_link', 'text'],
                    '__source_id'                => ['dgb_contract_id', 'int'],
                    'tipo'                       => ['service_line', 'text'],
                    'cliente'                    => ['client_raw', 'text'],
                    'descrizione'                => ['description', 'text'],
                    'descrizione interna'        => ['internal_description', 'text'],
                    'stato'                      => ['operational_status', 'status'],
                    'compliance da verificare'   => ['compliance_to_verify', 'bool'],
                    'compliance pre autorizzata' => ['compliance_preauth', 'bool'],
                    'data inizio'                => ['start_date', 'date'],
                    'data fine'                  => ['end_date', 'date'],
                    'anomalie aperte'            => ['anomalies_open', 'int'],
                    'anomalie bloccanti'         => ['anomalies_blocking', 'int'],
                    'stato economico a oggi'     => ['economic_status_todate', 'text'],
                    'stato_economico'            => ['economic_status', 'text'],
                    'valore a oggi'              => ['value_todate', 'dec'],
                    'valore'                     => ['value_total', 'dec'],
                    'consuntivato'               => ['actual_cost', 'dec'],
                    'margine a oggi'             => ['margin_todate', 'dec'],
                    'margine'                    => ['margin_total', 'dec'],
                    'residuo a oggi'             => ['residual_todate', 'dec'],
                    'residuo'                    => ['residual_total', 'dec'],
                    'fido su valore'             => ['credit_on_value', 'dec'],
                    'fido su costi'              => ['credit_on_costs', 'dec'],
                    'Fatt. freq. (mesi)'         => ['billing_freq_months', 'int'],
                    'Prima fatt.'                => ['first_billing_date', 'date'],
                ],
                'required' => ['codice_commessa', 'commessa'],
                // il cliente testuale va risolto in anagrafica clienti
                'client_from'  => 'cliente',
                'company_from' => 'codice_commessa',   // azienda esecutrice dal prefisso
                'absorb_dgb'   => true,                // assorbe i segnaposto DGB-<id>
            ],

            // ───────────────────────────────────────────────────────────────
            'rapporti' => [
                'label'       => 'Rapporti di intervento',
                'description' => "Una riga per allocazione tecnico su rapporto approvato. Alimenta "
                               . "Timesheet, Controllo & Riconciliazione e Carico risorse.",
                'target'      => 'cm_intervention_reports',
                // v1.8.50 — GRANA CANONICA.
                // La granularità dell'export è la PRESTAZIONE: un tecnico su una
                // attività. Un intervento svolto da due tecnici produce due righe
                // con lo stesso `a.code`, e la chiave che le distingue è la coppia
                // (codice attività, operatore), materializzata in `source_uid`.
                //
                // Fino alla v1.8.49 la chiave era `dgb_source_id` e il codice veniva
                // disambiguato con un suffisso `/<id_operatore>`. Entrambe le scelte
                // erano sbagliate: la prima non riconosceva le righe importate da
                // XLSX (che hanno dgb_source_id NULL) e le duplicava, la seconda
                // rendeva `report_code` diverso dal codice del gestionale.
                //
                // Ora `report_code` è il codice di riferimento, identico alla
                // sorgente, e la deduplica è garantita dal vincolo UNIQUE su
                // `source_uid` — dal database, non dalla disciplina del codice.
                'key'         => 'source_uid',
                'source_table'=> 'forms_activity',
                'affects'     => ['Import rapporti intervento', 'Timesheet', 'Controllo & Riconciliazione', 'Carico & Sovrapposizioni'],
                'sql' => "SELECT
    a.code AS `N.`,
    CONCAT(a.code, '#', ao.id_operator) AS `__grana`,
    ao.id AS `id allocazione`,
    DATE_FORMAT(a.report_date, '%d/%m/%Y') AS `Data rapporto`,
    DATE_FORMAT(a.date_start, '%d/%m/%Y %H:%i') AS `Inizio intervento`,
    DATE_FORMAT(a.date_dead_line, '%d/%m/%Y %H:%i') AS `Fine intervento`,
    CASE WHEN a.approved_at IS NOT NULL THEN 1 ELSE 0 END AS `Approvato`,
    c.code AS `Codice Commessa`,
    c.name AS `Commessa`,
    ct.code AS `Tipo`,
    cust.name AS `Cliente`,
    p.name AS `Sede`,
    a.contact AS `Riferimento del cliente`,
    cr.name AS `Fascia`,
    COALESCE(ts.name, '') AS `Settore tecnologico`,
    a.note AS `Richiesta intervento`,
    COALESCE(a.ticket, '') AS `Ticket`,
    -- v1.9.12 — lo stato entra nel portale. Prima non veniva conservato, e la
    -- perdita di righe per stato non lasciava traccia: non si poteva sapere ne'
    -- quante mancassero ne' perche'.
    UPPER(TRIM(COALESCE(a.status, ''))) AS `Stato modulo`,
    a.report_note AS `Lavoro eseguito`,
    {$opName('op')} AS `Tecnico`,
    CASE WHEN COALESCE(ao.from_remote, 0) = 1 THEN 1 ELSE 0 END AS `Da remoto`,
    CASE WHEN COALESCE(ao.during_availability, 0) = 1 THEN 1 ELSE 0 END AS `In reperibilità`,
    COALESCE(a.planned_hours, 0) AS `Pianificato (ore)`,
    COALESCE(ao.quantity, 0) AS `Quantità (ore)`,
    COALESCE(a.planned_hours, 0) - COALESCE(ao.quantity, 0) AS `Diff. (ore)`,
    COALESCE(ao.extra_hours, 0) AS `Di cui Extra (ore)`,
    COALESCE(ao.revenue, 0) AS `Ricavo`,
    COALESCE(ao.cost, 0) AS `Costo`
FROM forms_activity a
-- v1.8.79: anche qui la deduplica sulla coppia (attivita, operatore).
-- Senza, i duplicati della sorgente generano due rapporti per lo stesso
-- intervento e raddoppiano le ore, che e' il difetto della v1.8.78 riprodotto
-- da un secondo dataset che legge la stessa tabella.
INNER JOIN forms_activity_has_dgb_operator ao ON ao.id_activity = a.id
INNER JOIN (
    SELECT MAX(id) AS keep_id FROM forms_activity_has_dgb_operator
     GROUP BY id_activity, id_operator
) kd ON kd.keep_id = ao.id
-- il conteggio delle allocazioni per attivita' e' su righe DISTINTE: contando
-- i duplicati, la ripartizione dei valori dell'attivita' risulterebbe divisa
-- per un numero piu' grande del reale
INNER JOIN (SELECT id_activity, COUNT(DISTINCT id_operator) AS n
              FROM forms_activity_has_dgb_operator
             GROUP BY id_activity) nalloc ON nalloc.id_activity = a.id
LEFT JOIN dgb_operator op ON op.id = ao.id_operator
LEFT JOIN forms_place p ON p.id = a.id_place
LEFT JOIN forms_contract c ON c.id = p.id_contract
LEFT JOIN forms_contract_type ct ON ct.id = c.id_contracttype
LEFT JOIN forms_company cust ON cust.id = c.id_customer_comp
LEFT JOIN forms_cost_range cr ON cr.id = op.id_cost_range
LEFT JOIN forms_tech_sector ts ON ts.id = a.id_tech_sector
WHERE COALESCE(a.deleted, 0) = 0
  -- v1.9.12 — CONFRONTO CASE-INSENSITIVE.
  --
  -- Il gestionale usa gli stessi stati in due grafie: 'completed' 70.833 volte
  -- e 'CLOSED' 4.514, 'closed' 5.675. Il filtro precedente confrontava solo le
  -- maiuscole e scartava in silenzio tutte le righe minuscole.
  --
  -- I moduli di Bressi risultavano tutti in stato 'assigned', che non compariva
  -- nell'elenco in nessuna grafia: il portale ne perdeva 3 su 307.
  --
  -- L'elenco e' ora quello completo dichiarato dall'azienda, e UPPER() rende il
  -- confronto indifferente alla grafia.
  AND UPPER(TRIM(COALESCE(a.status, ''))) IN
      ('OPEN','ASSIGNED','COMPLETED','ACTIVE','PENDING','CLOSED',
       'SUSPENDED','NOT_CLOSED','REJECTED','PASSED','TO_BE_DONE')
ORDER BY a.report_date ASC, a.code ASC",
                'map' => [
                    'N.'                      => ['report_code', 'text'],
                    '__grana'                 => ['source_uid', 'text'],
                    'id allocazione'          => ['dgb_source_id', 'int'],
                    'Data rapporto'           => ['report_date', 'date'],
                    'Inizio intervento'       => ['start_at', 'datetime'],
                    'Fine intervento'         => ['end_at', 'datetime'],
                    'Approvato'               => ['approved', 'bool'],
                    'Codice Commessa'         => ['project_code', 'text'],
                    'Commessa'                => ['project_name_raw', 'text'],
                    'Tipo'                    => ['service_type', 'text'],
                    'Cliente'                 => ['client_raw', 'text'],
                    'Sede'                    => ['site_raw', 'text'],
                    'Riferimento del cliente' => ['client_reference', 'text'],
                    'Fascia'                  => ['band_raw', 'text'],
                    'Settore tecnologico'     => ['tech_sector', 'text'],
                    'Richiesta intervento'    => ['request_text', 'text'],
                    'Ticket'                  => ['ticket', 'text'],
                    // v1.9.12 — lo stato entra nel portale: senza, la perdita di
                    // righe per stato non lascia traccia
                    'Stato modulo'            => ['source_status', 'text'],
                    'Lavoro eseguito'         => ['work_done', 'text'],
                    'Tecnico'                 => ['technician_raw', 'text'],
                    'Da remoto'               => ['remote', 'bool'],
                    'In reperibilità'         => ['on_call', 'bool'],
                    'Pianificato (ore)'       => ['planned_hours', 'dec'],
                    'Quantità (ore)'          => ['quantity_hours', 'dec'],
                    'Diff. (ore)'             => ['diff_hours', 'dec'],
                    'Di cui Extra (ore)'      => ['extra_hours', 'dec'],
                    'Ricavo'                  => ['client_revenue_import', 'dec'],
                    'Costo'                   => ['company_cost_import', 'dec'],
                ],
                'required'    => ['N.', 'Codice Commessa'],
                'client_from' => 'Cliente',
                'link_project'=> 'project_code',   // risolve project_id da cm_projects
                // v1.8.77 — risolve anche il TECNICO dal nome verso l'anagrafica:
                // senza, i rapporti restano scollegati e il tecnico sparisce da
                // allineamento team e report ore pur comparendo nei moduli.
                'link_technician' => 'technician_raw',
            ],

            // ───────────────────────────────────────────────────────────────
            'professionisti' => [
                'label'       => 'Anagrafica professionisti',
                'description' => "Operatori del gestionale con la relativa fascia di costo. "
                               . "Alimenta l'Anagrafica Professionisti e le tariffe orarie.",
                'target'      => 'cm_professionals',
                'key'         => 'source_operator_id',
                'source_table'=> 'dgb_operator',
                'affects'     => ['Anagrafica Professionisti', 'Fasce costo orario', 'Timesheet'],
                // v1.8.63 — DISTINCT: `dgb_operator` contiene righe duplicate, 512
                // per 256 id distinti. Il difetto era gia' stato corretto in
                // v1.8.60 sul dataset dei full cost ma non qui, dove questo
                // dataset leggeva la stessa tabella: il vincolo UNIQUE sulla
                // chiave lo mascherava, facendo passare il doppio delle righe e
                // aggiornando due volte le stesse anagrafiche.
                'sql' => "SELECT DISTINCT
    op.id AS `id operatore`,
    op.abbr AS `sigla`,
    op.first_name AS `nome`,
    op.second_name AS `cognome`,
    op.email AS `email`,
    op.username AS `username`,
    cr.name AS `fascia`,
    COALESCE(op.deleted, 0) AS `eliminato`
FROM dgb_operator op
LEFT JOIN forms_cost_range cr ON cr.id = op.id_cost_range
ORDER BY op.second_name, op.first_name",
                'map' => [
                    'id operatore' => ['source_operator_id', 'int'],
                    'sigla'        => ['abbr', 'text'],
                    'nome'         => ['first_name', 'text'],
                    'cognome'      => ['last_name', 'text'],
                    'email'        => ['email', 'text'],
                    'username'     => ['username', 'text'],
                    // cm_professionals non ha un campo dedicato alla fascia di costo:
                    // il valore testuale viene conservato in `notes`, mentre il legame
                    // con le fasce del portale resta gestito da manage_rate_bands.
                    'fascia'       => ['notes', 'text'],
                    'eliminato'    => ['deleted_src', 'bool'],
                ],
                'required' => ['id operatore'],
            ],

            // ───────────────────────────────────────────────────────────────
            // v1.8.57 — dataset economici, individuati analizzando il dump
            // ───────────────────────────────────────────────────────────────
            'tariffe' => [
                'label'       => 'Tariffe di contratto',
                'description' => "Tariffe per commessa e tipo attività, distinte per unità di misura "
                               . "(ora, giorno, mezza giornata, ora extra) e per natura (ricavo o costo). "
                               . "È il denominatore economico che mancava per calcolare una marginalità.",
                'target'      => 'cm_contract_rates',
                'key'         => 'source_id',
                'source_table'=> 'forms_um_rate_for_contract',
                'affects'     => ['Commesse / Progetti', 'Analisi di marginalità'],
                'sql' => "SELECT
    r.id AS `id tariffa`,
    c.code AS `codice_commessa`,
    at.code AS `tipo attivita`,
    r.type AS `natura`,
    r.um AS `unita`,
    r.value AS `valore`
FROM forms_um_rate_for_contract r
LEFT JOIN forms_contract c       ON c.id = r.id_contract
LEFT JOIN forms_activitytype at  ON at.id = r.id_activitytype
WHERE c.code IS NOT NULL
ORDER BY c.code, r.type, r.um",
                'map' => [
                    'id tariffa'      => ['source_id', 'int'],
                    'codice_commessa' => ['project_code', 'text'],
                    'divisione'       => ['division_code', 'text'],
                    'tipo attivita'   => ['activity_type', 'text'],
                    'natura'          => ['rate_nature', 'text'],
                    'unita'           => ['rate_unit', 'text'],
                    'valore'          => ['rate_value', 'dec'],
                ],
                'required'     => ['id tariffa', 'codice_commessa'],
                'link_project' => 'project_code',
            ],

            'allocazioni' => [
                'label'       => 'Allocazioni pianificate',
                'description' => "Assegnazioni di operatore a commessa con il relativo periodo. "
                               . "Permette il confronto fra chi è stato allocato e chi ha "
                               . "effettivamente consuntivato.",
                'target'      => 'cm_project_allocations',
                'key'         => 'source_id',
                'source_table'=> 'dgb_operator_allocations_on_forms_contract',
                'affects'     => ['Carico & Sovrapposizioni', 'Anagrafica Tecnica'],
                // L'ANALISI DEL DUMP HA RILEVATO UN DIFETTO NELLA SORGENTE.
                // `dgb_operator_allocations_on_forms_contract` non ha PRIMARY KEY e
                // contiene righe duplicate ESATTE: 199.458 righe per 99.729 valori
                // distinti di `id`, ciascuno ripetuto due volte con tutti i campi
                // identici. Verificato sul file del dump, quindi e' la sorgente e
                // non il caricamento.
                //
                // Senza DISTINCT il portale importerebbe il doppio delle
                // allocazioni, e `id` non sarebbe utilizzabile come chiave di
                // deduplica. Con DISTINCT le righe identiche collassano e `id`
                // torna univoco.
                'sql' => "SELECT DISTINCT
    a.id AS `id allocazione`,
    a.id_operator AS `id operatore`,
    TRIM(CONCAT(COALESCE(op.first_name,''),' ',COALESCE(op.second_name,''))) AS `operatore`,
    c.code AS `codice_commessa`,
    at.code AS `tipo attivita`,
    a.type AS `tipo allocazione`,
    DATE_FORMAT(a.start_date, '%d/%m/%Y') AS `dal`,
    DATE_FORMAT(a.end_date, '%d/%m/%Y') AS `al`
FROM dgb_operator_allocations_on_forms_contract a
LEFT JOIN forms_contract c      ON c.id = a.id_contract
LEFT JOIN dgb_operator op       ON op.id = a.id_operator
LEFT JOIN forms_activitytype at ON at.id = a.id_activitytype
WHERE c.code IS NOT NULL
ORDER BY c.code, a.start_date",
                'map' => [
                    'id allocazione'   => ['source_id', 'int'],
                    'id operatore'     => ['operator_id', 'int'],
                    'operatore'        => ['operator_name', 'text'],
                    'codice_commessa'  => ['project_code', 'text'],
                    'tipo attivita'    => ['activity_type', 'text'],
                    'tipo allocazione' => ['alloc_type', 'text'],
                    'dal'              => ['start_date', 'date'],
                    'al'               => ['end_date', 'date'],
                ],
                'required'     => ['id allocazione', 'codice_commessa'],
                'link_project' => 'project_code',
            ],

            'costi_fascia' => [
                'label'       => 'Costi orari delle fasce',
                'description' => "Costo orario e giornaliero per fascia professionale. Le fasce erano "
                               . "già in anagrafica ma senza importi: il costo reale stava solo nel "
                               . "gestionale, ed è il dato che mancava per un margine per persona.",
                'target'      => 'cm_band_costs',
                'key'         => 'band_name',
                'source_table'=> 'forms_cost_range',
                'affects'     => ['Fasce costo orario', 'Analisi di marginalità'],
                'sql' => "SELECT
    cr.id AS `id fascia`,
    cr.name AS `fascia`,
    cr.hourly_cost AS `costo orario`,
    cr.daily_cost AS `costo giornaliero`,
    cr.full_cost_from AS `costo pieno da`,
    cr.full_cost_to AS `costo pieno a`,
    CASE WHEN COALESCE(cr.deleted,0) = 0 THEN 1 ELSE 0 END AS `attiva`
FROM forms_cost_range cr
WHERE cr.name IS NOT NULL AND cr.name <> ''
ORDER BY cr.name",
                'map' => [
                    'id fascia'         => ['source_id', 'int'],
                    'fascia'            => ['band_name', 'text'],
                    'costo orario'      => ['hourly_cost', 'dec'],
                    'costo giornaliero' => ['daily_cost', 'dec'],
                    'costo pieno da'    => ['full_cost_from', 'dec'],
                    'costo pieno a'     => ['full_cost_to', 'dec'],
                    'attiva'            => ['is_active', 'bool'],
                ],
                'required' => ['fascia'],
            ],

            'costi_operatore' => [
                'label'       => 'Full cost per operatore',
                'description' => "Costo annuo pieno di ciascun operatore. È la base di costo prevista per "
                               . "le linee interne, il presidio e WTS-AM. Il costo orario si ricava "
                               . "dividendo per le ore lavorabili annue.",
                'target'      => 'cm_operator_costs',
                'key'         => 'source_id',
                'source_table'=> 'dgb_operator',
                'affects'     => ['Analisi di marginalità', 'Anagrafica Tecnica'],
                // DISTINCT: `dgb_operator` contiene righe duplicate, 512 per 256
                // id distinti. E' lo stesso difetto di
                // dgb_operator_allocations_on_forms_contract rilevato in v1.8.57.
                'sql' => "SELECT DISTINCT
    op.id AS `id operatore`,
    TRIM(CONCAT(COALESCE(op.first_name,''),' ',COALESCE(op.second_name,''))) AS `operatore`,
    op.full_cost AS `full cost annuo`,
    CASE WHEN COALESCE(op.full_cost,0) > 0
         THEN ROUND(op.full_cost / 1760, 4) ELSE NULL END AS `full cost orario`,
    1 AS `attivo`
FROM dgb_operator op
WHERE op.id IS NOT NULL
ORDER BY op.id",
                'map' => [
                    'id operatore'      => ['source_id', 'int'],
                    'operatore'         => ['operator_name', 'text'],
                    'full cost annuo'   => ['full_cost_year', 'dec'],
                    'full cost orario'  => ['full_cost_hour', 'dec'],
                    'attivo'            => ['is_active', 'bool'],
                ],
                'required' => ['id operatore'],
            ],

            'operazioni' => [
                'label'       => 'Operazioni economiche di commessa',
                'description' => "Il mastrino della commessa: ordini cliente, riporti dal contratto "
                               . "precedente, storni, costi direzionali e acquisti di beni e servizi. "
                               . "È qui che si trova il costo direzionale.",
                'target'      => 'cm_project_operations',
                'key'         => 'source_id',
                'source_table'=> 'forms_contract_operation',
                'affects'     => ['Commesse / Progetti', 'Analisi di marginalità'],
                // L'importo viene NORMALIZZATO qui: ogni tipo di operazione usa un
                // campo diverso — REC usa revenue, FVC usa cost, REP usa
                // final_value — e risolverlo in import evita che ogni query debba
                // conoscere la semantica dei tre campi.
                'sql' => "SELECT
    o.id AS `id operazione`,
    c.code AS `codice_commessa`,
    t.code AS `tipo operazione`,
    o.op_code AS `codice operazione`,
    o.op_name AS `descrizione operazione`,
    DATE_FORMAT(o.op_date, '%d/%m/%Y') AS `data`,
    o.order_code AS `codice ordine`,
    o.cost AS `costo`,
    o.revenue AS `ricavo`,
    o.final_value AS `valore finale`,
    o.invoice_amount AS `importo fatturato`,
    COALESCE(o.is_invoiced,0) AS `fatturato`,
    CASE t.type
        WHEN 'REC' THEN COALESCE(o.revenue,0)
        WHEN 'FVC' THEN COALESCE(o.cost,0)
        WHEN 'REP' THEN COALESCE(o.final_value,0)
        ELSE 0 END AS `importo`,
    CASE t.type
        WHEN 'REC' THEN COALESCE(o.revenue,0)
        WHEN 'FVC' THEN -COALESCE(o.cost,0)
        WHEN 'REP' THEN COALESCE(o.final_value,0)
        ELSE 0 END AS `importo con segno`,
    o.id_operator AS `id operatore`
FROM forms_contract_operation o
JOIN forms_contract_operation_type t ON t.id = o.id_contract_operation_type
LEFT JOIN forms_contract c ON c.id = o.id_contract
WHERE COALESCE(o.deleted,0) = 0 AND c.code IS NOT NULL
ORDER BY c.code, o.op_date",
                'map' => [
                    'id operazione'          => ['source_id', 'int'],
                    'codice_commessa'        => ['project_code', 'text'],
                    'tipo operazione'        => ['op_type_code', 'text'],
                    'codice operazione'      => ['op_code', 'text'],
                    'descrizione operazione' => ['op_name', 'text'],
                    'data'                   => ['op_date', 'date'],
                    'codice ordine'          => ['order_code', 'text'],
                    'costo'                  => ['cost', 'dec'],
                    'ricavo'                 => ['revenue', 'dec'],
                    'valore finale'          => ['final_value', 'dec'],
                    'importo fatturato'      => ['invoice_amount', 'dec'],
                    'fatturato'              => ['is_invoiced', 'bool'],
                    'importo'                => ['amount', 'dec'],
                    'importo con segno'      => ['signed_amount', 'dec'],
                    'id operatore'           => ['operator_id', 'int'],
                ],
                'required'     => ['id operazione', 'codice_commessa'],
                'link_project' => 'project_code',
            ],

            // ───────────────────────────────────────────────────────────────
            // v1.8.63 — dimensione organizzativa e allerte del gestionale
            // ───────────────────────────────────────────────────────────────
            'divisioni' => [
                'label'       => 'Divisioni aziendali',
                'description' => "Le unità organizzative reali del gestionale — Sistemistica, Assistenza "
                               . "Tecnica, Laboratorio, WeSecure e le società del gruppo. È la dimensione "
                               . "su cui aggregare effort e margini per struttura.",
                'target'      => 'cm_divisions',
                'key'         => 'source_id',
                'source_table'=> 'forms_division',
                'affects'     => ['Anagrafica Tecnica', 'Unità Organizzative'],
                'sql' => "SELECT DISTINCT
    d.id AS `id divisione`,
    d.code AS `codice`,
    d.name AS `denominazione`
FROM forms_division d
WHERE d.code IS NOT NULL AND d.code <> ''
ORDER BY d.name",
                'map' => [
                    'id divisione'  => ['source_id', 'int'],
                    'codice'        => ['code', 'text'],
                    'denominazione' => ['name', 'text'],
                ],
                'required' => ['id divisione', 'codice'],
            ],

            'tipi_anomalia' => [
                'label'       => 'Tipi di anomalia contrattuale',
                'description' => "Le regole di allerta economica del gestionale: soglie su margine, "
                               . "residuo, tariffa e ritardo di fatturazione, con la relativa gravità.",
                'target'      => 'cm_anomaly_types',
                'key'         => 'source_id',
                'source_table'=> 'forms_contract_anomaly_type',
                'affects'     => ['Commesse / Progetti', 'Anomalie'],
                'sql' => "SELECT DISTINCT
    t.id AS `id tipo`,
    t.code AS `codice`,
    t.type AS `famiglia`,
    t.threshold AS `soglia`,
    t.severity AS `gravita`,
    t.description AS `descrizione`,
    t.check_order AS `ordine`
FROM forms_contract_anomaly_type t
WHERE t.code IS NOT NULL
ORDER BY t.type, t.check_order",
                'map' => [
                    'id tipo'     => ['source_id', 'int'],
                    'codice'      => ['code', 'text'],
                    'famiglia'    => ['family', 'text'],
                    'soglia'      => ['threshold', 'dec'],
                    'gravita'     => ['severity', 'text'],
                    'descrizione' => ['description', 'text'],
                    'ordine'      => ['check_order', 'int'],
                ],
                'required' => ['id tipo', 'codice'],
            ],

            'anomalie_commessa' => [
                'label'       => 'Anomalie economiche di commessa',
                'description' => "Le segnalazioni che il gestionale ha già rilevato sulle commesse: "
                               . "margine sotto soglia, residuo esaurito, tariffe fuori standard, "
                               . "fatturazione in ritardo. Sono controlli del gestionale, non ricalcolati.",
                'target'      => 'cm_contract_anomalies',
                'key'         => 'source_id',
                'source_table'=> 'forms_contract_anomaly',
                'affects'     => ['Commesse / Progetti', 'Anomalie'],
                'sql' => "SELECT DISTINCT
    a.id AS `id anomalia`,
    c.code AS `codice_commessa`,
    a.an_code AS `codice anomalia`,
    a.an_type AS `famiglia`,
    a.an_threshold AS `soglia`,
    a.an_severity AS `gravita`,
    a.description AS `descrizione`,
    a.status AS `stato`,
    DATE_FORMAT(a.resolved_at, '%d/%m/%Y') AS `risolta il`,
    DATE_FORMAT(a.created_at, '%d/%m/%Y') AS `rilevata il`
FROM forms_contract_anomaly a
LEFT JOIN forms_contract c ON c.id = a.id_contract
WHERE c.code IS NOT NULL
ORDER BY c.code, a.an_severity, a.an_code",
                'map' => [
                    'id anomalia'     => ['source_id', 'int'],
                    'codice_commessa' => ['project_code', 'text'],
                    'codice anomalia' => ['anomaly_code', 'text'],
                    'famiglia'        => ['family', 'text'],
                    'soglia'          => ['threshold', 'dec'],
                    'gravita'         => ['severity', 'text'],
                    'descrizione'     => ['description', 'text'],
                    'stato'           => ['status', 'text'],
                    'risolta il'      => ['resolved_at', 'date'],
                    'rilevata il'     => ['detected_at', 'date'],
                ],
                'required'     => ['id anomalia', 'codice_commessa'],
                'link_project' => 'project_code',
            ],

            'assenze' => [
                'label'       => 'Assenze e recuperi',
                'description' => "Ferie, permessi, recupero ore, malattia e altri impegni di calendario "
                               . "del personale. Completa il quadro dell'effort: le ore non lavorate "
                               . "spiegano i vuoti nella distribuzione del carico.",
                'target'      => 'cm_operator_commitments',
                'key'         => 'source_id',
                'source_table'=> 'forms_commitment',
                'affects'     => ['Attività & Rendicontazione DGB', 'Carico & Sovrapposizioni'],
                // `quantity` e' la durata in ore dell'impegno: 7,94 di media per
                // le ferie, cioe' la giornata intera. Non e' un conteggio di
                // giorni, e sommarla come tale sottostimerebbe di otto volte.
                'sql' => "SELECT
    c.id AS `id impegno`,
    c.type AS `tipo`,
    c.id_operator AS `id operatore`,
    TRIM(CONCAT(COALESCE(op.first_name,''),' ',COALESCE(op.second_name,''))) AS `operatore`,
    c.quantity AS `ore`,
    DATE_FORMAT(c.date_start, '%d/%m/%Y') AS `dal`,
    DATE_FORMAT(c.date_dead_line, '%d/%m/%Y') AS `al`,
    TIME(c.date_start) AS `ora inizio`,
    TIME(c.date_dead_line) AS `ora fine`,
    LEFT(COALESCE(c.description,''), 400) AS `descrizione`
FROM forms_commitment c
LEFT JOIN dgb_operator op ON op.id = c.id_operator
WHERE COALESCE(c.deleted,0) = 0 AND c.date_start IS NOT NULL
ORDER BY c.date_start",
                'map' => [
                    'id impegno'   => ['source_id', 'int'],
                    'tipo'         => ['commitment_type', 'text'],
                    'id operatore' => ['operator_id', 'int'],
                    'operatore'    => ['operator_name', 'text'],
                    'ore'          => ['hours', 'dec'],
                    'dal'          => ['start_date', 'date'],
                    'al'           => ['end_date', 'date'],
                    'ora inizio'   => ['start_time', 'text'],
                    'ora fine'     => ['end_time', 'text'],
                    'descrizione'  => ['description', 'text'],
                ],
                'required' => ['id impegno', 'tipo'],
            ],

            // ───────────────────────────────────────────────────────────────
            // v1.8.73 — Attività DGB: alimentano la pagina "Attività &
            // Rendicontazione DGB". Non erano fra i dataset: venivano scritte
            // solo da DgbSync, un import separato, e restavano indietro rispetto
            // a "Sincronizza tutto".
            // ───────────────────────────────────────────────────────────────
            'attivita_dgb' => [
                'label'       => 'Attività DGB',
                'description' => "Le attività del gestionale con orari, stato e valori economici. "
                               . "Alimentano la pagina Attività & Rendicontazione DGB: senza questo "
                               . "dataset restavano ferme all'ultimo import manuale.",
                'target'      => 'dgb_forms_activity',
                'key'         => 'id',
                'source_table'=> 'forms_activity',
                'affects'     => ['Attività & Rendicontazione DGB'],
                'sql' => "SELECT
    a.id AS `id attivita`,
    a.code AS `codice`,
    a.ticket AS `ticket`,
    a.date_start AS `inizio`,
    a.date_dead_line AS `fine`,
    a.status AS `stato`,
    a.planned_hours AS `ore pianificate`,
    a.diff_hours AS `scostamento ore`,
    a.human_resource_hours AS `ore risorse`,
    a.human_resource_cost AS `costo risorse`,
    a.human_resource_revenue AS `ricavo risorse`,
    a.total_cost AS `costo totale`,
    a.total_revenue AS `ricavo totale`,
    a.total_from_remote AS `da remoto`,
    a.total_smart_working AS `smart working`,
    a.report_date AS `data rapporto`,
    a.assigned_at AS `assegnata il`,
    a.in_progress_at AS `avviata il`,
    a.completed_at AS `completata il`,
    a.closed_at AS `chiusa il`,
    a.approved_at AS `approvata il`,
    a.aborted_at AS `annullata il`,
    COALESCE(a.deleted,0) AS `eliminata`,
    a.id_operator AS `id operatore`,
    a.id_activity_planning AS `id pianificazione`,
    a.id_contract AS `id contratto`,
    a.id_customer_comp AS `id cliente`,
    a.id_report_author AS `id autore`,
    a.id_place AS `id sede`,
    a.id_zone AS `id zona`,
    a.id_activitytype AS `id tipo attivita`,
    a.created_at AS `creata il`,
    a.updated_at AS `aggiornata il`
FROM forms_activity a
ORDER BY a.id",
                'map' => [
                    'id attivita'       => ['id', 'int'],
                    'codice'            => ['code', 'text'],
                    'ticket'            => ['ticket', 'text'],
                    'inizio'            => ['date_start', 'datetime'],
                    'fine'              => ['date_dead_line', 'datetime'],
                    'stato'             => ['status', 'text'],
                    'ore pianificate'   => ['planned_hours', 'dec'],
                    'scostamento ore'   => ['diff_hours', 'dec'],
                    'ore risorse'       => ['human_resource_hours', 'dec'],
                    'costo risorse'     => ['human_resource_cost', 'dec'],
                    'ricavo risorse'    => ['human_resource_revenue', 'dec'],
                    'costo totale'      => ['total_cost', 'dec'],
                    'ricavo totale'     => ['total_revenue', 'dec'],
                    'da remoto'         => ['total_from_remote', 'dec'],
                    'smart working'     => ['total_smart_working', 'dec'],
                    'data rapporto'     => ['report_date', 'datetime'],
                    'assegnata il'      => ['assigned_at', 'datetime'],
                    'avviata il'        => ['in_progress_at', 'datetime'],
                    'completata il'     => ['completed_at', 'datetime'],
                    'chiusa il'         => ['closed_at', 'datetime'],
                    'approvata il'      => ['approved_at', 'datetime'],
                    'annullata il'      => ['aborted_at', 'datetime'],
                    'eliminata'         => ['deleted', 'bool'],
                    'id operatore'      => ['id_operator', 'int'],
                    'id pianificazione' => ['id_activity_planning', 'int'],
                    'id contratto'      => ['id_contract', 'int'],
                    'id cliente'        => ['id_customer_comp', 'int'],
                    'id autore'         => ['id_report_author', 'int'],
                    'id sede'           => ['id_place', 'int'],
                    'id zona'           => ['id_zone', 'int'],
                    'id tipo attivita'  => ['id_activitytype', 'int'],
                    'creata il'         => ['created_at', 'datetime'],
                    'aggiornata il'     => ['updated_at', 'datetime'],
                ],
                'required' => ['id attivita'],
            ],

            'allocazioni_dgb' => [
                'label'       => 'Ore per operatore su attività DGB',
                'description' => "Le ore consuntivate da ciascun operatore su ciascuna attività, con "
                               . "costi, ricavi, trasferte e ore a recupero. È la base della "
                               . "distribuzione del carico e della classificazione oraria.",
                'target'      => 'dgb_forms_activity_operator',
                'key'         => 'id',
                'source_table'=> 'forms_activity_has_dgb_operator',
                'affects'     => ['Attività & Rendicontazione DGB', 'Carico & Sovrapposizioni'],
                // v1.8.79 — DEDUPLICA A MONTE, sulla coppia (attivita, operatore).
                //
                // La chiave del dataset e' `id`, ma il fatto e' identificato
                // dalla COPPIA: due righe sorgente con id diversi e stessa
                // coppia producevano due INSERT, e dopo l'introduzione del
                // vincolo di unicita' (v1.8.78) il secondo fallisce con
                // "Duplicate entry '71416-2627'".
                //
                // Il vincolo fa il suo mestiere: segnala invece di accettare in
                // silenzio. Ma l'errore va tolto alla fonte, non tollerato — un
                // dataset che fallisce a ogni sincronizzazione diventa rumore
                // che si impara a ignorare.
                //
                // Si tiene la riga con id PIU' ALTO, cioe' l'ultima scritta:
                // se i valori differissero, e' quella che riflette lo stato
                // corrente della sorgente. Stessa regola della deduplica
                // applicata al portale nella v1.8.78, per non avere due criteri
                // che possano divergere.
                'sql' => "SELECT
    ao.id AS `id allocazione`,
    ao.exec_report_type AS `tipo rapporto`,
    ao.hours AS `ore`,
    ao.cost AS `costo`,
    ao.used_hourly_cost AS `costo orario`,
    ao.revenue AS `ricavo`,
    ao.quantity AS `quantita`,
    ao.um AS `unita`,
    ao.to_recover_hours AS `ore a recupero`,
    ao.extra_hours AS `ore extra`,
    ao.trip_hours AS `ore trasferta`,
    ao.from_remote AS `da remoto`,
    ao.smart_working AS `smart working`,
    ao.during_availability AS `in reperibilita`,
    ao.id_activity AS `id attivita`,
    ao.id_operator AS `id operatore`,
    ao.created_at AS `creata il`,
    ao.updated_at AS `aggiornata il`
FROM forms_activity_has_dgb_operator ao
JOIN (
    SELECT MAX(id) AS keep_id
      FROM forms_activity_has_dgb_operator
     GROUP BY id_activity, id_operator
) k ON k.keep_id = ao.id
ORDER BY ao.id",
                'map' => [
                    'id allocazione'  => ['id', 'int'],
                    'tipo rapporto'   => ['exec_report_type', 'text'],
                    'ore'             => ['hours', 'dec'],
                    'costo'           => ['cost', 'dec'],
                    'costo orario'    => ['used_hourly_cost', 'dec'],
                    'ricavo'          => ['revenue', 'dec'],
                    'quantita'        => ['quantity', 'dec'],
                    'unita'           => ['um', 'text'],
                    'ore a recupero'  => ['to_recover_hours', 'dec'],
                    'ore extra'       => ['extra_hours', 'dec'],
                    'ore trasferta'   => ['trip_hours', 'dec'],
                    'da remoto'       => ['from_remote', 'dec'],
                    'smart working'   => ['smart_working', 'dec'],
                    'in reperibilita' => ['during_availability', 'dec'],
                    'id attivita'     => ['id_activity', 'int'],
                    'id operatore'    => ['id_operator', 'int'],
                    'creata il'       => ['created_at', 'datetime'],
                    'aggiornata il'   => ['updated_at', 'datetime'],
                ],
                'required' => ['id allocazione', 'id attivita'],
            ],

            // ───────────────────────────────────────────────────────────────
            // v1.8.74 — Anagrafica clienti dal gestionale.
            //
            // `clients` era popolata per DERIVAZIONE dai rapporti: il nome del
            // cliente veniva estratto dal testo e creata una riga se assente.
            // Funziona, ma produce un'anagrafica parziale — 305 righe contro le
            // 338 aziende classificate come cliente nel gestionale — e priva di
            // partita IVA, indirizzo e recapiti, che nel gestionale ci sono.
            // ───────────────────────────────────────────────────────────────
            'clienti' => [
                'label'       => 'Anagrafica clienti',
                'description' => "Le aziende classificate come cliente nel gestionale, con partita IVA, "
                               . "codice fiscale, indirizzo e recapiti. Sostituisce l'anagrafica ricavata "
                               . "per derivazione dal testo dei rapporti.",
                'target'      => 'clients',
                'key'         => 'name',
                'source_table'=> 'forms_company',
                'affects'     => ['Commesse / Progetti', 'Carico & Sovrapposizioni'],
                // La chiave e' la DENOMINAZIONE, perche' `clients` non ha una
                // colonna per l'identificativo del gestionale e la riconciliazione
                // con i dati gia' presenti — creati per derivazione dal testo dei
                // rapporti — puo' avvenire solo sul nome.
                //
                // Sette aziende sono pero' registrate DUE VOLTE nel gestionale con
                // lo stesso nome, tipicamente una con partita IVA e una senza.
                // Importarle entrambe violerebbe la chiave; scartarne una a caso
                // perderebbe il dato migliore. Si aggregano quindi per nome
                // tenendo il valore piu' informativo di ciascun campo: MAX() su
                // una colonna dove NULL e stringa vuota convivono con il valore
                // vero restituisce quest'ultimo.
                'sql' => "SELECT
    c.name AS `denominazione`,
    MAX(NULLIF(TRIM(COALESCE(c.vat_number,'')),'')) AS `partita iva`,
    MAX(NULLIF(TRIM(COALESCE(c.tax_id_code,'')),'')) AS `codice fiscale`,
    MAX(NULLIF(TRIM(COALESCE(c.address,'')),'')) AS `indirizzo`,
    MAX(NULLIF(TRIM(COALESCE(c.contact,'')),'')) AS `referente`,
    MAX(CASE WHEN COALESCE(c.deleted,0) = 0 AND COALESCE(c.active,1) = 1 THEN 1 ELSE 0 END) AS `attivo`,
    MAX(CASE WHEN COALESCE(c.is_executor_comp,0) = 1 THEN 1 ELSE 0 END) AS `azienda interna`
FROM forms_company c
WHERE c.name IS NOT NULL AND c.name <> ''
  AND (COALESCE(c.is_customer_comp,0) = 1 OR COALESCE(c.is_executor_comp,0) = 1)
GROUP BY c.name
ORDER BY c.name",
                'map' => [
                    'denominazione'   => ['name', 'text'],
                    'partita iva'     => ['vat_number', 'text'],
                    'codice fiscale'  => ['fiscal_code', 'text'],
                    'indirizzo'       => ['address', 'text'],
                    'referente'       => ['contact_person', 'text'],
                    'attivo'          => ['is_active', 'bool'],
                    'azienda interna' => ['is_internal_company', 'bool'],
                ],
                'required' => ['denominazione'],
            ],

            // ───────────────────────────────────────────────────────────────
            // v1.8.82 — Service Desk: i messaggi dei ticket.
            //
            // `tt_article` contiene i MESSAGGI, non i ticket: 13.479 righe per
            // 3.512 ticket. Il `code` e' `WTS_000000003_001` — il ticket e' la
            // parte iniziale, il suffisso e' il progressivo del messaggio.
            // Contare le righe come ticket sovrastimerebbe di 3,8 volte.
            //
            // `tt_ticket` non e' esportata nel dump: il ticket si ricostruisce
            // dai suoi messaggi, e lo stato corrente e' il `t_status_after`
            // dell'ultimo.
            // ───────────────────────────────────────────────────────────────
            'ticket_messaggi' => [
                'label'       => 'Service Desk — messaggi ticket',
                'description' => "I messaggi dei ticket con autore, coda, stato prima e dopo. "
                               . "Il ticket si ricostruisce dai suoi messaggi: la tabella dei ticket "
                               . "non è esportata dal gestionale.",
                'target'      => 'cm_sd_messages',
                'key'         => 'source_id',
                'source_table'=> 'tt_article',
                'affects'     => ['Service Desk'],
                'sql' => "SELECT
    a.id AS `id messaggio`,
    a.code AS `codice messaggio`,
    SUBSTRING_INDEX(a.code, '_', 2) AS `codice ticket`,
    a.id_tt_ticket AS `id ticket`,
    a.type AS `tipo`,
    LEFT(COALESCE(a.subject,''), 400) AS `oggetto`,
    a.t_status_before AS `stato precedente`,
    a.t_status_after AS `stato successivo`,
    a.received_at AS `ricevuto il`,
    a.duration_article AS `durata`,
    a.id_author AS `id autore`,
    TRIM(CONCAT(COALESCE(o.first_name,''),' ',COALESCE(o.second_name,''))) AS `autore`,
    a.id_tt_queue AS `id coda`,
    q.name AS `coda`,
    a.from_name AS `mittente`
FROM tt_article a
LEFT JOIN dgb_operator o ON o.id = a.id_author
LEFT JOIN tt_queue q ON q.id = a.id_tt_queue
ORDER BY a.id",
                'map' => [
                    'id messaggio'     => ['source_id', 'int'],
                    'codice messaggio' => ['message_code', 'text'],
                    'codice ticket'    => ['ticket_code', 'text'],
                    'id ticket'        => ['ticket_id', 'int'],
                    'tipo'             => ['msg_type', 'text'],
                    'oggetto'          => ['subject', 'text'],
                    'stato precedente' => ['status_before', 'text'],
                    'stato successivo' => ['status_after', 'text'],
                    'ricevuto il'      => ['received_at', 'datetime'],
                    'durata'           => ['duration_min', 'dec'],
                    'id autore'        => ['author_id', 'int'],
                    'autore'           => ['author_name', 'text'],
                    'id coda'          => ['queue_id', 'int'],
                    'coda'             => ['queue_name', 'text'],
                    'mittente'         => ['from_name', 'text'],
                ],
                'required' => ['id messaggio', 'id ticket'],
            ],
        ];
    }

    /**
     * v1.8.57 — Ordine di sincronizzazione.
     *
     * I dataset non sono indipendenti: rapporti, tariffe e allocazioni si
     * agganciano alle commesse per codice, quindi le commesse vanno lette per
     * prime. Sincronizzare in ordine sbagliato non perde dati — l'aggancio
     * avviene comunque alla passata successiva — ma lascia righe scollegate
     * fino ad allora, e chi guarda subito dopo vede un'analisi incompleta.
     *
     * @return string[]
     */
    public static function syncOrder(): array
    {
        $ordine = ['divisioni', 'clienti', 'commesse', 'costi_fascia', 'costi_operatore', 'professionisti',
                   'tariffe', 'allocazioni', 'operazioni', 'tipi_anomalia', 'anomalie_commessa', 'assenze', 'ticket_messaggi', 'attivita_dgb', 'allocazioni_dgb', 'rapporti'];
        // eventuali dataset aggiunti in futuro e non elencati vanno in coda,
        // così l'ordine resta valido senza doverlo aggiornare per forza
        foreach (self::keys() as $k) {
            if (!in_array($k, $ordine, true)) $ordine[] = $k;
        }
        return array_values(array_filter($ordine, fn($k) => in_array($k, self::keys(), true)));
    }

    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) throw new InvalidArgumentException("Dataset sconosciuto: $key");
        return $all[$key];
    }

    public static function keys(): array { return array_keys(self::all()); }

    /**
     * Intestazioni attese nel CSV, cioè quelle prodotte dalla query.
     *
     * Le colonne tecniche (prefisso `__`) sono normalmente omesse dai file di
     * export leggibili, ma quella che funge da chiave di upsert va inclusa:
     * senza di essa un CSV non potrebbe aggiornare le righe esistenti.
     */
    public static function headers(string $key): array
    {
        $d = self::get($key);
        $keyCol = null;
        foreach ($d['map'] as $col => $spec) {
            if ($spec[0] === $d['key']) { $keyCol = $col; break; }
        }
        $cols = array_keys($d['map']);
        return array_values(array_filter(
            $cols,
            fn($c) => !str_starts_with($c, '__') || $c === $keyCol
        ));
    }

    /** Normalizzazione delle intestazioni, tollerante su caso e punteggiatura. */
    public static function normalize(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = str_replace(['à','è','é','ì','ò','ù'], ['a','e','e','i','o','u'], $h);
        $h = preg_replace('/[^a-z0-9]+/u', '_', $h);
        return trim((string)$h, '_');
    }

    /** Mappa intestazione normalizzata => [campo, tipo]. */
    public static function headerMap(string $key): array
    {
        $out = [];
        foreach (self::get($key)['map'] as $col => $spec) {
            $out[self::normalize($col)] = $spec;
        }
        return $out;
    }

    /**
     * v1.8.49 — Tabelle sorgente realmente coinvolte da un dataset.
     *
     * Sono estratte dalla query, non dichiarate a mano: una dichiarazione
     * separata divergerebbe dalla query alla prima modifica, ed e' proprio
     * quella divergenza che l'analisi della sorgente deve poter individuare.
     *
     * @return string[]
     */
    public static function tablesOf(string $key): array
    {
        $sql = self::get($key)['sql'];
        preg_match_all('/\b(?:FROM|JOIN)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $sql, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /** Tutte le tabelle sorgente usate dall'insieme dei dataset. @return string[] */
    public static function allTables(): array
    {
        $out = [];
        foreach (self::keys() as $k) $out = array_merge($out, self::tablesOf($k));
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /**
     * Confronta l'inventario della sorgente con le tabelle usate dai dataset.
     *
     * Tre esiti interessano:
     *   used      tabelle usate e presenti: la sincronizzazione le leggera'
     *   missing   usate ma ASSENTI dalla sorgente: la sincronizzazione fallira'
     *   unused    presenti ma non usate: potenziale materiale per nuovi dataset
     *
     * `missing` e' il motivo per cui questa analisi esiste: senza, una tabella
     * rinominata dal fornitore si scopre solo quando l'import va in errore.
     *
     * @param array<int,array{name:string,type:string,columns:int,rows:int}> $inventory
     */
    public static function coverage(array $inventory): array
    {
        $present = [];
        foreach ($inventory as $t) $present[strtolower($t['name'])] = $t;

        $used = self::allTables();
        $ok = []; $missing = [];
        foreach ($used as $t) {
            if (isset($present[strtolower($t)])) $ok[] = $t; else $missing[] = $t;
        }
        $usedLower = array_map('strtolower', $used);
        $unused = [];
        foreach ($inventory as $t) {
            if (!in_array(strtolower($t['name']), $usedLower, true)) $unused[] = $t;
        }
        return ['used' => $ok, 'missing' => $missing, 'unused' => $unused,
                'total' => count($inventory)];
    }
}
