<?php
/**
 * PortalManager — app/CmSearch.php  (v1.10.24)
 *
 * Ricerca trasversale di Gestione Commesse: una vista tabellare filtrabile su tutti gli archivi del modulo
 * (commesse, moduli di intervento, pianificazione, impegni, operazioni, team, professionisti, anagrafica
 * tecnica, ordinativi Pratix, attività DGB, progetti PRJ, timesheet, ticket SOC) con le stesse correlazioni
 * usate dalle pagine del modulo:
 *   - commessa:     cm_projects.id (project_id) · DGB via cm_projects.dgb_contract_id = id_contract
 *                   · Pratix via cm_project_operations.order_code · PRJ via cm_prj.sp_project_id
 *   - cliente:      COALESCE(clients.name, *_raw) · società esecutrice: cm_projects.exec_company_id
 *   - risorsa:      employees (technician_id / employee_id) → cm_professionals → testo grezzo
 *
 * Sicurezza:
 *   - ogni ambito è visibile solo a chi vede la pagina sorgente (gate = permesso «view» di almeno una pagina);
 *   - le colonne economiche (flag e) esistono solo con il permesso virtuale cm_search_economics.php:
 *     senza, vengono rimosse dalla definizione (non selezionabili, filtrabili, ordinabili né esportabili);
 *   - espressioni SQL solo dal registro; chiavi di colonna e ordinamento in whitelist; valori sempre come parametri.
 *
 * Sintassi dei filtri per colonna (CmSearch::cond):
 *   testo  abc · a|b (uno dei due) · =abc (uguale) · !abc (non contiene) · ^abc (inizia con) · = (vuoto) · != (non vuoto)
 *   numeri 10 · >10 · >=10 · <10 · <=10 · 10..20 · = · !=          (decimali con virgola o punto)
 *   date   2026 · 2026-03 · 2026-03-15 · 15/03/2026 · 03/2026 · >=2026-01 · <2026-07 · 2026-01..2026-03 · = · !=
 *   sì/no  sì · no
 */
declare(strict_types=1);

final class CmSearch
{
    public const PER   = [25, 50, 100, 250];
    public const MAX_FILE = 50000;   // righe massime in XLSX / CSV
    public const MAX_DOC  = 3000;    // righe massime in DOCX / PDF
    public const DOC_TEXT = 400;     // caratteri massimi di un testo lungo in DOCX / PDF

    /** filtri «di correlazione» comuni a tutti gli ambiti che li supportano */
    public const REL = ['commessa' => 'Commessa', 'cliente' => 'Cliente', 'persona' => 'Risorsa / persona'];

    private const PERSON_E  = "NULLIF(TRIM(CONCAT(COALESCE(e.last_name,''),' ',COALESCE(e.first_name,''))),'')";
    private const PERSON_PF = "NULLIF(TRIM(CONCAT(COALESCE(pf.last_name,''),' ',COALESCE(pf.first_name,''))),'')";
    private const CLIENT_P  = "COALESCE(cl.name, p.client_raw)";
    private const P_JOIN    = " LEFT JOIN clients cl ON cl.id = p.client_id LEFT JOIN companies co ON co.id = p.exec_company_id";

    private array $defs;

    public function __construct(private PDO $pdo, private bool $eco = false)
    {
        $this->defs = self::registry();
        if (!$eco) foreach ($this->defs as $k => $d) $this->defs[$k]['cols'] = array_filter($d['cols'], fn($c) => !str_contains($c['f'], 'e'));
    }

    // ── registro degli ambiti ────────────────────────────────────────
    /**
     * Colonna: [l = etichetta, x = espressione SQL, t = tipo, f = flag]
     *   tipo: text | long | int | num | hours | eur | date | datetime | bool
     *   flag: d = visibile per impostazione predefinita · e = economica · s = totale in fondo · h = di servizio (mai mostrata)
     */
    private static function c(string $l, string $x, string $t = 'text', string $f = ''): array { return ['l' => $l, 'x' => $x, 't' => $t, 'f' => $f]; }

    public static function registry(): array
    {
        $c = [self::class, 'c'];
        $tech = "COALESCE(" . self::PERSON_E . ", " . self::PERSON_PF . ", NULLIF(r.technician_raw,''))";
        $pcode = fn(string $raw) => $c('Cod. commessa', "COALESCE(p.project_code, $raw)", 'text', 'd');
        return [
            'commesse' => [
                'label' => 'Commesse', 'icon' => 'fa-briefcase', 'gate' => ['manage_projects.php'],
                'from'  => "cm_projects p" . self::P_JOIN
                         . " LEFT JOIN (SELECT project_id, COUNT(*) AS n, SUM(quantity_hours) AS h, MAX(report_date) AS last_d
                                        FROM cm_intervention_reports WHERE project_id IS NOT NULL GROUP BY project_id) ir ON ir.project_id = p.id",
                'where' => '', 'key' => 'p.id', 'sort' => ['codice', 'asc'], 'date' => 'p.start_date',
                'rel'   => ['commessa' => ['p.project_code', 'p.name', 'p.abbr'], 'cliente' => ['cl.name', 'p.client_raw'], 'societa' => 'p.exec_company_id'],
                'links' => ['codice' => ['project_dashboard', '_pid'], 'commessa' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'codice'      => $c('Codice', 'p.project_code', 'text', 'd'),
                    'commessa'    => $c('Commessa', 'p.name', 'text', 'd'),
                    'sigla'       => $c('Sigla', 'p.abbr'),
                    'cliente'     => $c('Cliente', self::CLIENT_P, 'text', 'd'),
                    'societa'     => $c('Società esecutrice', 'co.name', 'text', 'd'),
                    'commerciale' => $c('Commerciale', 'p.commercial_ref'),
                    'linea'       => $c('Tipo / linea', 'p.service_line', 'text', 'd'),
                    'tipologia'   => $c('Tipologia', 'p.project_type'),
                    'stato'       => $c('Stato', 'p.operational_status', 'text', 'd'),
                    'stato_comm'  => $c('Stato commerciale', 'p.commercial_status'),
                    'stato_eco'   => $c('Stato economico', 'p.economic_status'),
                    'inizio'      => $c('Inizio', 'p.start_date', 'date', 'd'),
                    'fine'        => $c('Fine', 'p.end_date', 'date', 'd'),
                    'valore'      => $c('Valore', 'p.value_total', 'eur', 'des'),
                    'valore_oggi' => $c('Valore a oggi', 'p.value_todate', 'eur', 'es'),
                    'consuntivo'  => $c('Consuntivato', 'p.actual_cost', 'eur', 'es'),
                    'margine'     => $c('Margine', 'p.margin_total', 'eur', 'des'),
                    'residuo'     => $c('Residuo', 'p.residual_total', 'eur', 'es'),
                    'anomalie'    => $c('Anomalie aperte', 'p.anomalies_open', 'int', 's'),
                    'moduli'      => $c('Moduli', 'COALESCE(ir.n, 0)', 'int', 'ds'),
                    'ore_moduli'  => $c('Ore moduli', 'COALESCE(ir.h, 0)', 'hours', 'ds'),
                    'ultimo'      => $c('Ultimo modulo', 'ir.last_d', 'date'),
                    'dgb'         => $c('Contratto DGB', 'p.dgb_contract_id', 'int'),
                    'prj'         => $c('Progetti PRJ', "(SELECT GROUP_CONCAT(x.prj_code ORDER BY x.prj_code SEPARATOR ', ') FROM cm_prj x WHERE x.sp_project_id = p.id)"),
                    'descrizione' => $c('Descrizione', 'p.description', 'long'),
                    'descr_int'   => $c('Descrizione interna', 'p.internal_description', 'long'),
                    '_pid'        => $c('', 'p.id', 'int', 'h'),
                ],
            ],
            'moduli' => [
                'label' => 'Moduli di intervento', 'icon' => 'fa-file-signature', 'gate' => ['project_dashboard.php', 'import_intervention_reports.php'],
                'from'  => "cm_intervention_reports r LEFT JOIN cm_projects p ON p.id = r.project_id
                            LEFT JOIN clients cl ON cl.id = COALESCE(r.client_id, p.client_id) LEFT JOIN companies co ON co.id = p.exec_company_id
                            LEFT JOIN employees e ON e.id = r.technician_id LEFT JOIN cm_professionals pf ON pf.id = r.technician_professional_id
                            LEFT JOIN cm_rate_bands b ON b.id = r.band_id",
                'where' => '', 'key' => 'r.id', 'sort' => ['giorno', 'desc'], 'date' => 'r.report_date',
                'rel'   => ['commessa' => ['p.project_code', 'r.project_code', 'p.name', 'r.project_name_raw'], 'cliente' => ['cl.name', 'r.client_raw'],
                            'societa' => 'p.exec_company_id', 'persona' => [$tech]],
                'links' => ['commessa_cod' => ['project_dashboard', '_pid'], 'commessa' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'codice'       => $c('Modulo', 'r.report_code', 'text', 'd'),
                    'giorno'       => $c('Data', 'r.report_date', 'date', 'd'),
                    'inizio'       => $c('Inizio', 'r.start_at', 'datetime'),
                    'fine'         => $c('Fine', 'r.end_at', 'datetime'),
                    'commessa_cod' => $pcode('r.project_code'),
                    'commessa'     => $c('Commessa', 'COALESCE(p.name, r.project_name_raw)', 'text', 'd'),
                    'cliente'      => $c('Cliente', 'COALESCE(cl.name, r.client_raw)', 'text', 'd'),
                    'sede'         => $c('Sede', 'r.site_raw'),
                    'societa'      => $c('Società esecutrice', 'co.name'),
                    'tecnico'      => $c('Tecnico', $tech, 'text', 'd'),
                    'risorsa'      => $c('Tipo risorsa', "CASE WHEN e.id IS NOT NULL THEN 'Dipendente' WHEN pf.id IS NOT NULL THEN 'Professionista' ELSE 'Non collegato' END"),
                    'servizio'     => $c('Servizio', 'r.service_type', 'text', 'd'),
                    'settore'      => $c('Settore', 'r.tech_sector'),
                    'fascia'       => $c('Fascia', 'COALESCE(b.band_name, r.band_raw)'),
                    'ticket'       => $c('Ticket', 'r.ticket'),
                    'remoto'       => $c('Remoto', 'r.remote', 'bool'),
                    'reperibilita' => $c('Reperibilità', 'r.on_call', 'bool'),
                    'in_orario'    => $c('In orario', 'r.in_working_hours', 'bool'),
                    'approvato'    => $c('Approvato', 'r.approved', 'bool'),
                    'ore_pian'     => $c('Ore pianificate', 'r.planned_hours', 'hours', 's'),
                    'ore'          => $c('Ore', 'r.quantity_hours', 'hours', 'ds'),
                    'straordinario'=> $c('Ore extra', 'r.extra_hours', 'hours', 's'),
                    'ricavo'       => $c('Ricavo', 'COALESCE(r.client_revenue_calc, r.client_revenue_import)', 'eur', 'es'),
                    'costo'        => $c('Costo', 'COALESCE(r.company_cost_calc, r.company_cost_import)', 'eur', 'des'),
                    'fonte'        => $c('Fonte', 'r.source_system'),
                    'stato_fonte'  => $c('Stato fonte', 'r.source_status'),
                    'attivita_dgb' => $c('Attività DGB', 'r.dgb_activity_code'),
                    'richiesta'    => $c('Richiesta', 'r.request_text', 'long'),
                    'intervento'   => $c('Intervento eseguito', 'r.work_done', 'long'),
                    '_pid'         => $c('', 'p.id', 'int', 'h'),
                ],
            ],
            'pianificazione' => [
                'label' => 'Pianificazione', 'icon' => 'fa-calendar-days', 'gate' => ['workload_overview.php', 'project_gantt.php'],
                'from'  => "cm_project_allocations a LEFT JOIN cm_projects p ON p.id = a.project_id" . self::P_JOIN,
                'where' => '', 'key' => 'a.id', 'sort' => ['inizio', 'desc'], 'period' => ['a.start_date', 'a.end_date'],
                'rel'   => ['commessa' => ['p.project_code', 'a.project_code', 'p.name'], 'cliente' => ['cl.name', 'p.client_raw'], 'societa' => 'p.exec_company_id', 'persona' => ['a.operator_name']],
                'links' => ['commessa_cod' => ['project_dashboard', '_pid'], 'commessa' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'operatore'    => $c('Risorsa', 'a.operator_name', 'text', 'd'),
                    'commessa_cod' => $pcode('a.project_code'),
                    'commessa'     => $c('Commessa', 'p.name', 'text', 'd'),
                    'cliente'      => $c('Cliente', self::CLIENT_P, 'text', 'd'),
                    'societa'      => $c('Società esecutrice', 'co.name'),
                    'attivita'     => $c('Attività', 'a.activity_type', 'text', 'd'),
                    'tipo'         => $c('Tipo allocazione', 'a.alloc_type', 'text', 'd'),
                    'inizio'       => $c('Dal', 'a.start_date', 'date', 'd'),
                    'fine'         => $c('Al', 'a.end_date', 'date', 'd'),
                    'giorni'       => $c('Giorni', 'DATEDIFF(a.end_date, a.start_date) + 1', 'int', 'ds'),
                    '_pid'         => $c('', 'p.id', 'int', 'h'),
                ],
            ],
            'impegni' => [
                'label' => 'Impegni risorse', 'icon' => 'fa-user-clock', 'gate' => ['workload_overview.php'],
                'from'  => "cm_operator_commitments m",
                'where' => '', 'key' => 'm.id', 'sort' => ['inizio', 'desc'], 'period' => ['m.start_date', 'm.end_date'],
                'rel'   => ['persona' => ['m.operator_name']],
                'links' => [],
                'cols'  => [
                    'operatore'   => $c('Risorsa', 'm.operator_name', 'text', 'd'),
                    'tipo'        => $c('Tipo impegno', 'm.commitment_type', 'text', 'd'),
                    'ore'         => $c('Ore', 'm.hours', 'hours', 'ds'),
                    'inizio'      => $c('Dal', 'm.start_date', 'date', 'd'),
                    'fine'        => $c('Al', 'm.end_date', 'date', 'd'),
                    'dalle'       => $c('Dalle', 'm.start_time'),
                    'alle'        => $c('Alle', 'm.end_time'),
                    'descrizione' => $c('Descrizione', 'm.description', 'text', 'd'),
                ],
            ],
            'operazioni' => [
                'label' => 'Operazioni di commessa', 'icon' => 'fa-receipt', 'gate' => ['project_dashboard.php', 'pratix_orders.php'],
                'from'  => "cm_project_operations o LEFT JOIN cm_projects p ON p.id = o.project_id" . self::P_JOIN,
                'where' => '', 'key' => 'o.id', 'sort' => ['data', 'desc'], 'date' => 'o.op_date',
                'rel'   => ['commessa' => ['p.project_code', 'o.project_code', 'p.name'], 'cliente' => ['cl.name', 'p.client_raw'], 'societa' => 'p.exec_company_id'],
                'links' => ['commessa_cod' => ['project_dashboard', '_pid'], 'commessa' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'data'         => $c('Data', 'o.op_date', 'date', 'd'),
                    'codice'       => $c('Operazione', 'o.op_code', 'text', 'd'),
                    'tipo'         => $c('Tipo', 'o.op_type_code', 'text', 'd'),
                    'tipo_nome'    => $c('Descrizione tipo', '(SELECT MIN(t.name) FROM cm_operation_types t WHERE t.code = o.op_type_code)'),
                    'nome'         => $c('Nome', 'o.op_name', 'text', 'd'),
                    'commessa_cod' => $pcode('o.project_code'),
                    'commessa'     => $c('Commessa', 'p.name'),
                    'cliente'      => $c('Cliente', self::CLIENT_P, 'text', 'd'),
                    'societa'      => $c('Società esecutrice', 'co.name'),
                    'ordinativo'   => $c('Ordinativo', 'o.order_code', 'text', 'd'),
                    'descrizione'  => $c('Descrizione', 'o.description', 'long'),
                    'costo'        => $c('Costo', 'o.cost', 'eur', 'es'),
                    'ricavo'       => $c('Ricavo', 'o.revenue', 'eur', 'es'),
                    'valore'       => $c('Valore finale', 'o.final_value', 'eur', 'des'),
                    'importo'      => $c('Importo', 'o.amount', 'eur', 'es'),
                    'fatturato'    => $c('Fatturato', 'o.invoice_amount', 'eur', 'des'),
                    'fatturata'    => $c('Fatturata', 'o.is_invoiced', 'bool', 'd'),
                    '_pid'         => $c('', 'p.id', 'int', 'h'),
                ],
            ],
            'team' => [
                'label' => 'Team di commessa', 'icon' => 'fa-people-group', 'gate' => ['project_dashboard.php'],
                'from'  => "cm_team t LEFT JOIN cm_projects p ON p.id = t.project_id" . self::P_JOIN
                         . " LEFT JOIN employees e ON e.id = t.employee_id LEFT JOIN cm_professionals pf ON pf.id = t.professional_id",
                'where' => '', 'key' => 't.id', 'sort' => ['commessa_cod', 'asc'],
                'rel'   => ['commessa' => ['p.project_code', 'p.name'], 'cliente' => ['cl.name', 'p.client_raw'], 'societa' => 'p.exec_company_id',
                            'persona' => ["COALESCE(" . self::PERSON_E . ", " . self::PERSON_PF . ")"]],
                'links' => ['commessa_cod' => ['project_dashboard', '_pid'], 'commessa' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'commessa_cod' => $c('Cod. commessa', 'p.project_code', 'text', 'd'),
                    'commessa'     => $c('Commessa', 'p.name', 'text', 'd'),
                    'cliente'      => $c('Cliente', self::CLIENT_P, 'text', 'd'),
                    'societa'      => $c('Società esecutrice', 'co.name'),
                    'membro'       => $c('Membro', "COALESCE(" . self::PERSON_E . ", " . self::PERSON_PF . ")", 'text', 'd'),
                    'tipo'         => $c('Tipo', 't.member_type', 'text', 'd'),
                    'ruolo'        => $c('Ruolo', 't.role_in_project', 'text', 'd'),
                    'ore'          => $c('Ore allocate', 't.allocated_hours', 'hours', 'ds'),
                    'rapporto'     => $c('Rapporto', 't.employment_type'),
                    'origine'      => $c('Origine', 't.source'),
                    '_pid'         => $c('', 'p.id', 'int', 'h'),
                ],
            ],
            'professionisti' => [
                'label' => 'Professionisti', 'icon' => 'fa-user-tie', 'gate' => ['professionals.php'],
                'from'  => "cm_professionals pf LEFT JOIN companies co ON co.id = pf.exec_company_id LEFT JOIN employees e ON e.id = pf.employee_id
                            LEFT JOIN (SELECT technician_professional_id AS pid, COUNT(*) AS n, SUM(quantity_hours) AS h, MAX(report_date) AS last_d
                                       FROM cm_intervention_reports WHERE technician_professional_id IS NOT NULL GROUP BY technician_professional_id) ir ON ir.pid = pf.id",
                'where' => '', 'key' => 'pf.id', 'sort' => ['nominativo', 'asc'],
                'rel'   => ['societa' => 'pf.exec_company_id', 'persona' => [self::PERSON_PF, 'pf.email', 'pf.abbr']],
                'links' => [],
                'cols'  => [
                    'nominativo'  => $c('Nominativo', self::PERSON_PF, 'text', 'd'),
                    'sigla'       => $c('Sigla', 'pf.abbr'),
                    'username'    => $c('Utente gestionale', 'pf.username'),
                    'email'       => $c('Email', 'pf.email', 'text', 'd'),
                    'telefono'    => $c('Telefono', 'pf.phone'),
                    'societa'     => $c('Società', 'COALESCE(co.name, pf.company_abbr)', 'text', 'd'),
                    'tipo'        => $c('Tipo operatore', 'pf.operator_type', 'text', 'd'),
                    'costo_ora'   => $c('Costo orario', 'pf.hourly_cost', 'eur', 'de'),
                    'costo_pieno' => $c('Costo pieno', 'pf.full_cost', 'eur', 'e'),
                    'attivo'      => $c('Attivo', 'pf.active', 'bool', 'd'),
                    'stato'       => $c('Stato', 'pf.status', 'text', 'd'),
                    'dipendente'  => $c('Dipendente collegato', self::PERSON_E, 'text', 'd'),
                    'match'       => $c('Corrispondenza', 'pf.match_type'),
                    'moduli'      => $c('Moduli', 'COALESCE(ir.n, 0)', 'int', 'ds'),
                    'ore'         => $c('Ore moduli', 'COALESCE(ir.h, 0)', 'hours', 'ds'),
                    'ultimo'      => $c('Ultimo modulo', 'ir.last_d', 'date'),
                    'competenze'  => $c('Competenze', 'pf.skills', 'long'),
                ],
            ],
            'tecnici' => [
                'label' => 'Anagrafica tecnica', 'icon' => 'fa-user-gear', 'gate' => ['tech_registry.php'],
                'from'  => "cm_tech_profiles tp LEFT JOIN employees e ON e.id = tp.employee_id LEFT JOIN cm_professionals pf ON pf.id = tp.professional_id
                            LEFT JOIN cm_tech_units u ON u.id = tp.unit_id LEFT JOIN cm_tech_subunits su ON su.id = tp.subunit_id
                            LEFT JOIN companies co ON co.id = e.company_id",
                'where' => '', 'key' => 'tp.id', 'sort' => ['risorsa', 'asc'], 'date' => 'tp.valid_from',
                'rel'   => ['societa' => 'e.company_id', 'persona' => ["COALESCE(" . self::PERSON_E . ", " . self::PERSON_PF . ")"]],
                'links' => [],
                'cols'  => [
                    'risorsa'     => $c('Risorsa', "COALESCE(" . self::PERSON_E . ", " . self::PERSON_PF . ")", 'text', 'd'),
                    'tipo'        => $c('Tipo', "CASE WHEN tp.employee_id IS NOT NULL THEN 'Dipendente' ELSE 'Professionista' END", 'text', 'd'),
                    'societa'     => $c('Società', 'co.name'),
                    'unita'       => $c('Unità', 'u.name', 'text', 'd'),
                    'sottounita'  => $c('Sotto-unità', 'su.name', 'text', 'd'),
                    'seniority'   => $c('Seniority', 'tp.seniority', 'text', 'd'),
                    'reperibile'  => $c('Reperibile', 'tp.on_call', 'bool', 'd'),
                    'h24'         => $c('H24', 'tp.on_call_h24', 'bool'),
                    'turni'       => $c('Turni', 'tp.shift_pattern', 'text', 'd'),
                    'skill'       => $c('Competenza principale', 'tp.main_skill', 'text', 'd'),
                    'certificazioni' => $c('Certificazioni', 'tp.certifications', 'long'),
                    'valido_dal'  => $c('Valido dal', 'tp.valid_from', 'date'),
                    'attivo'      => $c('Attivo', 'tp.is_active', 'bool', 'd'),
                    'note'        => $c('Note', 'tp.notes', 'long'),
                ],
            ],
            'pratix' => [
                'label' => 'Ordinativi Pratix', 'icon' => 'fa-file-invoice', 'gate' => ['pratix_orders.php'],
                'from'  => "cm_pratix_ext x",
                'where' => '', 'key' => 'x.id', 'sort' => ['ordinativo', 'desc'], 'date' => 'x.imported_at',
                'rel'   => ['commessa' => ["(SELECT GROUP_CONCAT(DISTINCT CONCAT(pp.project_code, ' ', COALESCE(pp.name, '')) SEPARATOR ' | ') FROM cm_project_operations oo JOIN cm_projects pp ON pp.id = oo.project_id WHERE oo.order_code = x.order_code)"],
                            'cliente' => ['x.cliente_effettivo', 'x.cliente_fatturazione']],
                'links' => [],
                'cols'  => [
                    'ordinativo'   => $c('Ordinativo', 'x.order_code', 'text', 'd'),
                    'documento'    => $c('N. documento', 'x.numero_documento'),
                    'tipologia'    => $c('Tipologia', 'x.tipologia', 'text', 'd'),
                    'stato'        => $c('Stato', 'x.stato', 'text', 'd'),
                    'azienda'      => $c('Azienda', 'x.azienda', 'text', 'd'),
                    'cliente_eff'  => $c('Cliente effettivo', 'x.cliente_effettivo', 'text', 'd'),
                    'cliente_fatt' => $c('Cliente fatturazione', 'x.cliente_fatturazione'),
                    'progetto'     => $c('Progetto', 'x.progetto', 'text', 'd'),
                    'descrizione'  => $c('Descrizione', 'x.descrizione'),
                    'linea'        => $c('Linea di business', 'x.linea_business'),
                    'totale'       => $c('Totale', 'x.totale', 'eur', 'des'),
                    'firma_tec'    => $c('Firma tecnica', 'x.firma_tecnica'),
                    'firma_comm'   => $c('Firma commerciale', 'x.firma_commerciale'),
                    'commesse'     => $c('Commesse collegate', "(SELECT GROUP_CONCAT(DISTINCT pp.project_code ORDER BY pp.project_code SEPARATOR ', ') FROM cm_project_operations oo JOIN cm_projects pp ON pp.id = oo.project_id WHERE oo.order_code = x.order_code)", 'text', 'd'),
                    'importato'    => $c('Importato il', 'x.imported_at', 'datetime'),
                ],
            ],
            'dgb' => [
                'label' => 'Attività DGB', 'icon' => 'fa-diagram-project', 'gate' => ['dgb_activities.php'],
                'from'  => "dgb_forms_activity da
                            LEFT JOIN (SELECT dgb_contract_id, MIN(id) AS pid FROM cm_projects WHERE dgb_contract_id IS NOT NULL GROUP BY dgb_contract_id) pm ON pm.dgb_contract_id = da.id_contract
                            LEFT JOIN cm_projects p ON p.id = pm.pid" . self::P_JOIN . " LEFT JOIN dgb_operator o ON o.id = da.id_operator",
                'where' => 'da.deleted = 0', 'key' => 'da.id', 'sort' => ['inizio', 'desc'], 'date' => 'da.date_start',
                'rel'   => ['commessa' => ['p.project_code', 'p.name'], 'cliente' => ['cl.name', 'p.client_raw'], 'societa' => 'p.exec_company_id',
                            'persona' => ["NULLIF(TRIM(CONCAT(COALESCE(o.second_name,''),' ',COALESCE(o.first_name,''))),'')"]],
                'links' => ['commessa_cod' => ['project_dashboard', '_pid'], 'commessa' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'codice'       => $c('Attività', 'da.code', 'text', 'd'),
                    'ticket'       => $c('Ticket', 'da.ticket', 'text', 'd'),
                    'stato'        => $c('Stato', 'da.status', 'text', 'd'),
                    'inizio'       => $c('Inizio', 'da.date_start', 'datetime', 'd'),
                    'scadenza'     => $c('Scadenza', 'da.date_dead_line', 'datetime'),
                    'rapporto'     => $c('Data rapporto', 'da.report_date', 'date'),
                    'completata'   => $c('Completata', 'da.completed_at', 'datetime'),
                    'chiusa'       => $c('Chiusa', 'da.closed_at', 'datetime'),
                    'operatore'    => $c('Incaricato', "NULLIF(TRIM(CONCAT(COALESCE(o.second_name,''),' ',COALESCE(o.first_name,''))),'')", 'text', 'd'),
                    'commessa_cod' => $c('Cod. commessa', 'p.project_code', 'text', 'd'),
                    'commessa'     => $c('Commessa', 'p.name'),
                    'cliente'      => $c('Cliente', self::CLIENT_P, 'text', 'd'),
                    'societa'      => $c('Società esecutrice', 'co.name'),
                    'contratto'    => $c('Contratto DGB', 'da.id_contract', 'int'),
                    'ore_pian'     => $c('Ore pianificate', 'da.planned_hours', 'hours', 's'),
                    'ore'          => $c('Ore risorse', 'da.human_resource_hours', 'hours', 'ds'),
                    'costo'        => $c('Costo', 'da.total_cost', 'eur', 'des'),
                    'ricavo'       => $c('Ricavo', 'da.total_revenue', 'eur', 'es'),
                    '_pid'         => $c('', 'p.id', 'int', 'h'),
                ],
            ],
            'prj' => [
                'label' => 'Progetti PRJ', 'icon' => 'fa-scale-balanced', 'gate' => ['manage_projects_prj.php'],
                'from'  => "cm_prj x LEFT JOIN clients cl ON cl.id = x.client_id LEFT JOIN companies co ON co.id = x.exec_company_id LEFT JOIN cm_projects p ON p.id = x.sp_project_id",
                'where' => '', 'key' => 'x.id', 'sort' => ['codice', 'asc'], 'date' => 'x.start_date',
                'rel'   => ['commessa' => ['x.prj_code', 'x.nome', 'p.project_code', 'p.name'], 'cliente' => ['cl.name', 'x.client_raw'], 'societa' => 'x.exec_company_id'],
                'links' => ['codice' => ['prj_dashboard', '_xid'], 'nome' => ['prj_dashboard', '_xid'], 'commessa_sp' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'codice'      => $c('Codice PRJ', 'x.prj_code', 'text', 'd'),
                    'nome'        => $c('Progetto', 'x.nome', 'text', 'd'),
                    'cliente'     => $c('Cliente', 'COALESCE(cl.name, x.client_raw)', 'text', 'd'),
                    'societa'     => $c('Società esecutrice', 'co.name'),
                    'tipo'        => $c('Tipo', 'x.project_type', 'text', 'd'),
                    'stato'       => $c('Stato', 'x.stato', 'text', 'd'),
                    'gara'        => $c('Codice gara', 'x.codice_gara'),
                    'cig'         => $c('CIG', 'x.cig'),
                    'stazione'    => $c('Stazione appaltante', 'x.stazione_appaltante', 'text', 'd'),
                    'offerta'     => $c('Data offerta', 'x.data_offerta', 'date'),
                    'aggiudicazione' => $c('Aggiudicazione', 'x.data_aggiudicazione', 'date'),
                    'inizio'      => $c('Inizio', 'x.start_date', 'date', 'd'),
                    'fine'        => $c('Fine', 'x.end_date', 'date', 'd'),
                    'commessa_sp' => $c('Commessa SP', 'p.project_code', 'text', 'd'),
                    'note'        => $c('Note', 'x.note', 'long'),
                    '_pid'        => $c('', 'p.id', 'int', 'h'),
                    '_xid'        => $c('', 'x.id', 'int', 'h'),
                ],
            ],
            'timesheet' => [
                'label' => 'Timesheet', 'icon' => 'fa-table-list', 'gate' => ['timesheet.php'],
                'from'  => "cm_timesheet_entries t LEFT JOIN employees e ON e.id = t.employee_id LEFT JOIN cm_projects p ON p.id = t.project_id" . self::P_JOIN,
                'where' => '', 'key' => 't.id', 'sort' => ['data', 'desc'], 'date' => 't.work_date',
                'rel'   => ['commessa' => ['p.project_code', 'p.name'], 'cliente' => ['cl.name', 'p.client_raw'], 'societa' => 'p.exec_company_id', 'persona' => [self::PERSON_E]],
                'links' => ['commessa_cod' => ['project_dashboard', '_pid']],
                'cols'  => [
                    'dipendente'   => $c('Dipendente', self::PERSON_E, 'text', 'd'),
                    'data'         => $c('Data', 't.work_date', 'date', 'd'),
                    'attivita'     => $c('Attività', 't.activity_type', 'text', 'd'),
                    'commessa_cod' => $c('Cod. commessa', 'p.project_code', 'text', 'd'),
                    'commessa'     => $c('Commessa', 'p.name', 'text', 'd'),
                    'cliente'      => $c('Cliente', self::CLIENT_P),
                    'ore'          => $c('Ore', 't.hours', 'hours', 'ds'),
                    'note'         => $c('Note', 't.notes', 'text', 'd'),
                    '_pid'         => $c('', 'p.id', 'int', 'h'),
                ],
            ],
            'soc' => [
                'label' => 'Ticket SOC', 'icon' => 'fa-shield-halved', 'gate' => ['service_soc.php'],
                'from'  => "cm_soc_tickets s LEFT JOIN clients cl ON cl.id = s.client_id",
                'where' => '', 'key' => 's.ticket_code', 'sort' => ['aperto', 'desc'], 'date' => 's.opened_at',
                'rel'   => ['cliente' => ['cl.name', 's.client_name'], 'persona' => ['s.owner_name', 's.assignee_name']],
                'links' => [],
                'cols'  => [
                    'ticket'     => $c('Ticket', 's.ticket_code', 'text', 'd'),
                    'titolo'     => $c('Titolo', 's.title', 'text', 'd'),
                    'aperto'     => $c('Aperto', 's.opened_at', 'datetime', 'd'),
                    'chiuso'     => $c('Chiuso', 's.closed_at', 'datetime'),
                    'stato'      => $c('Stato', 's.status_now', 'text', 'd'),
                    'categoria'  => $c('Categoria', 's.category', 'text', 'd'),
                    'tipo'       => $c('Tipo', 's.ticket_type'),
                    'coda'       => $c('Coda', 's.queue_name'),
                    'cliente'    => $c('Cliente', 'COALESCE(cl.name, s.client_name)', 'text', 'd'),
                    'contratto'  => $c('Contratto SOC', 's.soc_contract'),
                    'owner'      => $c('Owner', 's.owner_name'),
                    'assegnato'  => $c('Assegnatario', 's.assignee_name', 'text', 'd'),
                    'eventi'     => $c('Eventi', 's.n_events', 'int', 's'),
                    'prima_risp' => $c('Prima risposta (min)', 's.first_response_min', 'int'),
                    'risoluzione'=> $c('Risoluzione (min)', 's.resolution_min', 'int'),
                ],
            ],
        ];
    }

    // ── accesso ──────────────────────────────────────────────────────
    /** Ambiti visibili: $can($action, $page) è la funzione can() del portale. */
    public function allowed(callable $can): array
    {
        $out = [];
        foreach ($this->defs as $k => $d) foreach ($d['gate'] as $pg) if ($can('view', $pg)) { $out[$k] = $d; break; }
        return $out;
    }

    public function def(string $ds): ?array { return $this->defs[$ds] ?? null; }

    /** Colonne mostrabili (senza quelle di servizio). */
    public static function visibleCols(array $d): array { return array_filter($d['cols'], fn($c) => !str_contains($c['f'], 'h')); }

    public static function defaultCols(array $d): array
    {
        return array_keys(array_filter($d['cols'], fn($c) => str_contains($c['f'], 'd') && !str_contains($c['f'], 'h')));
    }

    // ── parametri ────────────────────────────────────────────────────
    /** Normalizza i parametri GET della ricerca per l'ambito $d (whitelist). */
    public static function params(array $g, ?array $d): array
    {
        $s = static fn($v, int $max = 200) => mb_substr(trim(is_string($v) ? $v : ''), 0, $max);
        $p = [
            'q' => $s($g['q'] ?? ''), 'commessa' => $s($g['commessa'] ?? ''), 'cliente' => $s($g['cliente'] ?? ''), 'persona' => $s($g['persona'] ?? ''),
            'societa' => max(0, (int)($g['societa'] ?? 0)),
            'da' => self::isoDate($s($g['da'] ?? '', 10)), 'a' => self::isoDate($s($g['a'] ?? '', 10)),
            'per' => in_array((int)($g['per'] ?? 0), self::PER, true) ? (int)$g['per'] : 50,
            'pg' => max(1, (int)($g['pg'] ?? 1)), 'f' => [], 'c' => [], 's' => '', 'd' => 'asc',
        ];
        if (!$d) return $p;
        $vis = self::visibleCols($d);
        foreach ((array)($g['f'] ?? []) as $k => $v) if (is_string($k) && isset($vis[$k]) && is_string($v) && trim($v) !== '') $p['f'][$k] = $s($v, 120);
        $cols = $g['c'] ?? '';
        $cols = is_array($cols) ? $cols : explode(',', (string)$cols);
        foreach ($cols as $k) if (is_string($k) && isset($vis[$k]) && !in_array($k, $p['c'], true)) $p['c'][] = $k;
        if (!$p['c']) $p['c'] = self::defaultCols($d);
        $p['s'] = is_string($g['s'] ?? null) && isset($vis[$g['s']]) ? $g['s'] : $d['sort'][0];
        $p['d'] = in_array($g['d'] ?? '', ['asc', 'desc'], true) ? $g['d'] : ($p['s'] === $d['sort'][0] ? $d['sort'][1] : 'asc');
        if (!isset($vis[$p['s']])) { $p['s'] = (string)array_key_first($vis); $p['d'] = 'asc'; }
        return $p;
    }

    /** Parametri per l'URL (solo quelli valorizzati / diversi dal predefinito). */
    public static function query(string $ds, array $p, ?array $d, array $over = []): array
    {
        $q = ['ds' => $ds];
        foreach (['q', 'commessa', 'cliente', 'persona', 'da', 'a'] as $k) if ($p[$k] !== '') $q[$k] = $p[$k];
        if ($p['societa']) $q['societa'] = $p['societa'];
        if ($d) {
            if ($p['f']) $q['f'] = $p['f'];
            if ($p['c'] !== self::defaultCols($d)) $q['c'] = implode(',', $p['c']);
            if ($p['s'] !== $d['sort'][0] || $p['d'] !== $d['sort'][1]) { $q['s'] = $p['s']; $q['d'] = $p['d']; }
            if ($p['per'] !== 50) $q['per'] = $p['per'];
            if ($p['pg'] > 1) $q['pg'] = $p['pg'];
        }
        foreach ($over as $k => $v) { if ($v === null) unset($q[$k]); else $q[$k] = $v; }
        return $q;
    }

    // ── condizioni ───────────────────────────────────────────────────
    private static function like(string $v): string { return '%' . addcslashes($v, '%_\\') . '%'; }

    public static function isoDate(string $v): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) return $v;
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return '';
    }

    /** Numero da testo italiano o inglese («1.234,5», «1234.5», «-3»). */
    public static function num(string $v): ?float
    {
        $v = str_replace([' ', '€', "\u{00A0}"], '', trim($v));
        if ($v === '') return null;
        if (str_contains($v, ',')) $v = str_replace(',', '.', str_replace('.', '', $v));
        elseif (substr_count($v, '.') > 1) $v = str_replace('.', '', $v);
        return is_numeric($v) ? (float)$v : null;
    }

    /** Periodo [inizio, fine] (Y-m-d) da «2026», «2026-03», «03/2026», «2026-03-15», «15/03/2026». */
    public static function period(string $v): ?array
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})$/', $v, $m)) return ["$m[1]-01-01", "$m[1]-12-31"];
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $v, $m) || (preg_match('#^(\d{1,2})/(\d{4})$#', $v, $n) && ($m = [0, $n[2], $n[1]]))) {
            $y = (int)$m[1]; $mo = (int)$m[2];
            if ($mo < 1 || $mo > 12) return null;
            $s = sprintf('%04d-%02d-01', $y, $mo);
            return [$s, date('Y-m-t', strtotime($s))];
        }
        $d = self::isoDate($v);
        return $d !== '' ? [$d, $d] : null;
    }

    /**
     * Condizione SQL per il filtro $v sulla colonna $c. Ritorna [sql, args] o null se il valore non è valido.
     */
    public static function cond(array $c, string $v): ?array
    {
        $x = $c['x']; $t = $c['t']; $v = trim($v);
        if ($v === '') return null;
        if ($v === '=') return [$t === 'text' || $t === 'long' ? "($x IS NULL OR $x = '')" : "$x IS NULL", []];
        if ($v === '!=') return [$t === 'text' || $t === 'long' ? "($x IS NOT NULL AND $x <> '')" : "$x IS NOT NULL", []];
        if ($t === 'bool') {
            $b = mb_strtolower($v);
            if (in_array($b, ['1', 'si', 'sì', 's', 'yes', 'y', 'vero', 'true'], true)) return ["COALESCE($x, 0) <> 0", []];
            if (in_array($b, ['0', 'no', 'n', 'falso', 'false'], true)) return ["COALESCE($x, 0) = 0", []];
            return null;
        }
        if ($t === 'date' || $t === 'datetime') {
            $col = $t === 'datetime' ? "DATE($x)" : $x;
            if (str_contains($v, '..')) {
                [$a, $b] = array_map('trim', explode('..', $v, 2));
                $pa = $a !== '' ? self::period($a) : null; $pb = $b !== '' ? self::period($b) : null;
                if (($a !== '' && !$pa) || ($b !== '' && !$pb) || (!$pa && !$pb)) return null;
                $w = []; $args = [];
                if ($pa) { $w[] = "$col >= ?"; $args[] = $pa[0]; }
                if ($pb) { $w[] = "$col <= ?"; $args[] = $pb[1]; }
                return ['(' . implode(' AND ', $w) . ')', $args];
            }
            if (preg_match('/^(>=|<=|>|<)\s*(.+)$/', $v, $m)) {
                $pp = self::period($m[2]); if (!$pp) return null;
                return match ($m[1]) { '>=' => ["$col >= ?", [$pp[0]]], '<=' => ["$col <= ?", [$pp[1]]], '>' => ["$col > ?", [$pp[1]]], default => ["$col < ?", [$pp[0]]] };
            }
            $pp = self::period($v); if (!$pp) return null;
            return ["$col BETWEEN ? AND ?", $pp];
        }
        if (in_array($t, ['int', 'num', 'hours', 'eur'], true)) {
            if (str_contains($v, '..')) {
                [$a, $b] = array_map('trim', explode('..', $v, 2));
                $na = $a !== '' ? self::num($a) : null; $nb = $b !== '' ? self::num($b) : null;
                if (($a !== '' && $na === null) || ($b !== '' && $nb === null) || ($na === null && $nb === null)) return null;
                $w = []; $args = [];
                if ($na !== null) { $w[] = "$x >= ?"; $args[] = $na; }
                if ($nb !== null) { $w[] = "$x <= ?"; $args[] = $nb; }
                return ['(' . implode(' AND ', $w) . ')', $args];
            }
            if (preg_match('/^(>=|<=|<>|!=|>|<|=)?\s*(.+)$/', $v, $m)) {
                $n = self::num($m[2]); if ($n === null) return null;
                $op = $m[1] === '' ? '=' : ($m[1] === '!=' ? '<>' : $m[1]);
                return ["$x $op ?", [$n]];
            }
            return null;
        }
        // testo
        if ($v[0] === '=' ) return ["$x = ?", [substr($v, 1)]];
        if ($v[0] === '!' ) { $r = trim(substr($v, 1)); return $r === '' ? null : ["($x IS NULL OR $x NOT LIKE ?)", [self::like($r)]]; }
        if ($v[0] === '^' ) { $r = trim(substr($v, 1)); return $r === '' ? null : ["$x LIKE ?", [addcslashes($r, '%_\\') . '%']]; }
        $parts = array_values(array_filter(array_map('trim', explode('|', $v)), fn($s) => $s !== ''));
        if (!$parts) return null;
        return ['(' . implode(' OR ', array_fill(0, count($parts), "$x LIKE ?")) . ')', array_map([self::class, 'like'], $parts)];
    }

    /**
     * WHERE completa: condizione di base dell'ambito + ricerca libera + filtri di correlazione + periodo + filtri per colonna.
     * $bad riceve le chiavi dei filtri ignorati perché non validi. Ritorna null se un filtro di correlazione richiesto
     * non è supportato dall'ambito (nella ricerca su tutto il database l'ambito viene saltato).
     */
    public function where(array $d, array $p, array &$args, array &$bad = [], bool $strictRel = false): ?string
    {
        $w = []; $args = []; $bad = [];
        if ($d['where'] !== '') $w[] = $d['where'];
        if ($p['q'] !== '') {
            $or = [];
            foreach ($d['cols'] as $k => $c) if (($c['t'] === 'text' || $c['t'] === 'long') && !str_contains($c['f'], 'h')) { $or[] = "{$c['x']} LIKE ?"; $args[] = self::like($p['q']); }
            if ($or) $w[] = '(' . implode(' OR ', $or) . ')';
        }
        foreach (array_keys(self::REL) as $r) {
            if ($p[$r] === '') continue;
            if (empty($d['rel'][$r])) { if ($strictRel) return null; $bad[] = $r; continue; }
            $or = [];
            foreach ($d['rel'][$r] as $x) { $or[] = "$x LIKE ?"; $args[] = self::like($p[$r]); }
            $w[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($p['societa']) {
            if (empty($d['rel']['societa'])) { if ($strictRel) return null; $bad[] = 'societa'; }
            else { $w[] = $d['rel']['societa'] . ' = ?'; $args[] = $p['societa']; }
        }
        if ($p['da'] !== '' || $p['a'] !== '') {
            if (!empty($d['period'])) {   // intervallo: sovrapposizione con il periodo richiesto
                [$s, $e] = $d['period'];
                if ($p['a'] !== '')  { $w[] = "$s <= ?"; $args[] = $p['a']; }
                if ($p['da'] !== '') { $w[] = "COALESCE($e, $s) >= ?"; $args[] = $p['da']; }
            } elseif (!empty($d['date'])) {
                if ($p['da'] !== '') { $w[] = "{$d['date']} >= ?"; $args[] = $p['da']; }
                if ($p['a'] !== '')  { $w[] = "{$d['date']} < DATE_ADD(?, INTERVAL 1 DAY)"; $args[] = $p['a']; }
            } else { if ($strictRel) return null; $bad[] = 'periodo'; }
        }
        foreach ($p['f'] as $k => $v) {
            $r = isset($d['cols'][$k]) ? self::cond($d['cols'][$k], $v) : null;
            if (!$r) { $bad[] = $k; continue; }
            $w[] = $r[0]; array_push($args, ...$r[1]);
        }
        return $w ? implode(' AND ', $w) : '1=1';
    }

    // ── interrogazioni ───────────────────────────────────────────────
    /** Conteggio e totali delle colonne sommabili richieste. */
    public function summary(array $d, string $where, array $args, array $cols = []): array
    {
        $sel = ['COUNT(*) AS n']; $sum = [];
        foreach ($cols as $k) if (isset($d['cols'][$k]) && str_contains($d['cols'][$k]['f'], 's')) { $sel[] = "SUM({$d['cols'][$k]['x']}) AS `$k`"; $sum[] = $k; }
        $st = $this->pdo->prepare('SELECT ' . implode(', ', $sel) . " FROM {$d['from']} WHERE $where");
        $st->execute($args);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0];
        $tot = []; foreach ($sum as $k) $tot[$k] = $r[$k] === null ? null : (float)$r[$k];
        return ['n' => (int)$r['n'], 'tot' => $tot];
    }

    /** Righe con le colonne $cols (+ colonne di servizio), ordinate e paginate. */
    public function rows(array $d, string $where, array $args, array $cols, string $sort, string $dir, int $limit, int $offset = 0): array
    {
        $sel = [];
        foreach ($d['cols'] as $k => $c) if (in_array($k, $cols, true) || str_contains($c['f'], 'h')) $sel[] = "{$c['x']} AS `$k`";
        if (!isset($d['cols'][$sort])) $sort = (string)array_key_first($d['cols']);
        $dir = $dir === 'desc' ? 'DESC' : 'ASC';
        $sql = 'SELECT ' . implode(', ', $sel) . " FROM {$d['from']} WHERE $where"
             . " ORDER BY ({$d['cols'][$sort]['x']}) IS NULL, ({$d['cols'][$sort]['x']}) $dir, {$d['key']} $dir LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── valori ───────────────────────────────────────────────────────
    /** Valore tipizzato per l'export (int/float/string/null). */
    public static function value(array $c, $v, int $textMax = 0)
    {
        if ($v === null) return null;
        switch ($c['t']) {
            case 'int':   return (int)$v;
            case 'num': case 'hours': case 'eur': return round((float)$v, 2);
            case 'bool':  return (int)$v ? 'sì' : 'no';
            case 'date':  return $v ? date('d/m/Y', strtotime((string)$v)) : '';
            case 'datetime': return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
            default:
                $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
                return $textMax > 0 && mb_strlen($s) > $textMax ? mb_substr($s, 0, $textMax - 1) . '…' : $s;
        }
    }

    /** Valore per la tabella HTML (già testo, non ancora codificato). */
    public static function display(array $c, $v): string
    {
        if ($v === null || $v === '') return '';
        return match ($c['t']) {
            'int'   => number_format((float)$v, 0, ',', '.'),
            'num', 'hours' => number_format((float)$v, 2, ',', '.'),
            'eur'   => number_format((float)$v, 2, ',', '.') . ' €',
            'bool'  => (int)$v ? 'sì' : 'no',
            'date'  => date('d/m/Y', strtotime((string)$v)),
            'datetime' => date('d/m/Y H:i', strtotime((string)$v)),
            'long'  => self::value($c, $v, 140),
            default => (string)$v,
        };
    }

    public static function isNumeric(array $c): bool { return in_array($c['t'], ['int', 'num', 'hours', 'eur'], true); }

    /** Descrizione leggibile dei filtri attivi (export e intestazione). */
    public static function describe(array $d, array $p, array $companies = []): string
    {
        $o = [];
        if ($p['q'] !== '') $o[] = 'testo «' . $p['q'] . '»';
        foreach (self::REL as $k => $l) if ($p[$k] !== '') $o[] = mb_strtolower($l) . ' «' . $p[$k] . '»';
        if ($p['societa']) $o[] = 'società «' . ($companies[$p['societa']] ?? '#' . $p['societa']) . '»';
        if ($p['da'] !== '' || $p['a'] !== '') $o[] = 'periodo ' . ($p['da'] !== '' ? date('d/m/Y', strtotime($p['da'])) : '…') . ' – ' . ($p['a'] !== '' ? date('d/m/Y', strtotime($p['a'])) : '…');
        foreach ($p['f'] as $k => $v) if (isset($d['cols'][$k])) $o[] = $d['cols'][$k]['l'] . ' «' . $v . '»';
        return $o ? 'Filtri: ' . implode(' · ', $o) : 'Nessun filtro';
    }

    /**
     * Report dell'ambito per l'export: tutte le righe filtrate (fino a MAX_FILE / MAX_DOC), colonne scelte, totali.
     */
    public function report(string $ds, array $d, array $p, string $fmt, array $companies = []): array
    {
        $args = []; $bad = [];
        $where = $this->where($d, $p, $args, $bad);
        $sum = $this->summary($d, $where, $args, $p['c']);
        $cap = in_array($fmt, ['docx', 'pdf'], true) ? self::MAX_DOC : self::MAX_FILE;
        $rows = $this->rows($d, $where, $args, $p['c'], $p['s'], $p['d'], $cap, 0);
        $textMax = in_array($fmt, ['docx', 'pdf'], true) ? self::DOC_TEXT : 0;
        $hdr = []; $dec = [];
        foreach ($p['c'] as $k) { $hdr[] = $d['cols'][$k]['l']; $dec[] = $d['cols'][$k]['t'] === 'int' ? 0 : 2; }
        $data = [];
        foreach ($rows as $r) { $line = []; foreach ($p['c'] as $k) $line[] = self::value($d['cols'][$k], $r[$k] ?? null, $textMax); $data[] = $line; }
        $total = false;
        if ($sum['tot'] && $data) {
            $line = [];
            foreach ($p['c'] as $i => $k) $line[] = array_key_exists($k, $sum['tot']) ? ($d['cols'][$k]['t'] === 'int' ? (int)$sum['tot'][$k] : round((float)$sum['tot'][$k], 2)) : ($i === 0 ? 'Totale' : '');
            if (!array_key_exists($p['c'][0], $sum['tot'])) $line[0] = 'Totale (' . number_format($sum['n'], 0, ',', '.') . ' righe)';
            $data[] = $line; $total = true;
        }
        require_once __DIR__ . '/PmReport.php';
        $rep = new PmReport('Ricerca — ' . $d['label'], 'Gestione Commesse · generato il ' . date('d/m/Y H:i'), 'L');
        $rep->meta(self::describe($d, $p, $companies));
        $rep->meta('Righe trovate: ' . number_format($sum['n'], 0, ',', '.') . ' · ordinamento: ' . $d['cols'][$p['s']]['l'] . ($p['d'] === 'desc' ? ' (decrescente)' : ' (crescente)'));
        if ($sum['n'] > $cap) $rep->note('Il file contiene le prime ' . number_format($cap, 0, ',', '.') . ' righe su ' . number_format($sum['n'], 0, ',', '.')
            . ($cap === self::MAX_DOC ? ': per l\'elenco completo usare XLSX o CSV, oppure restringere i filtri.' : ': restringere i filtri per l\'elenco completo.'));
        if ($textMax) foreach ($p['c'] as $k) if ($d['cols'][$k]['t'] === 'long') { $rep->note('I testi lunghi sono abbreviati a ' . $textMax . ' caratteri (completi in XLSX e CSV).'); break; }
        $rep->table($d['label'], $hdr, $data, ['dec' => $dec, 'total' => $total, 'notitle' => true,
            'right' => array_keys(array_filter(array_map(fn($k) => self::isNumeric($d['cols'][$k]), $p['c'])))]);
        return ['report' => $rep, 'n' => $sum['n'], 'rows' => count($rows), 'file' => 'ricerca_' . $ds . '_' . date('Ymd_Hi')];
    }
}
