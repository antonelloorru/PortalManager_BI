<?php
require_once __DIR__ . '/EnumExtender.php';
/**
 * certV 5.4.0 — app/ImportValidator.php
 *
 * Layer di validazione pre-import:
 *   - Schema dichiarativo per ogni tipo di import
 *   - Validazione tipo, formato, vincoli DB
 *   - Risoluzione FK SMART (es. "brand:Cisco" → brand_id)
 *   - Applicazione regole personalizzate
 *
 * USO:
 *   $validator = new ImportValidator($pdo, 'dipendenti');
 *   $result = $validator->validateRow($rowAssoc);
 *   if ($result['valid']) { ... } else { print_r($result['errors']); }
 */

final class ImportValidator
{
    private PDO $pdo;
    private string $type;
    private array $schema;

    /** Cache risoluzione FK (riduce query) */
    private array $fkCache = [];

    public function __construct(PDO $pdo, string $type)
    {
        $this->pdo = $pdo;
        $this->type = $type;
        $this->schema = self::getSchema($type);
        if (empty($this->schema)) {
            throw new InvalidArgumentException("Schema sconosciuto per tipo: $type");
        }
    }

    /**
     * Schemi dichiarativi: chiave campo → regole
     *
     * Regole supportate:
     *   - required: true                → campo obbligatorio
     *   - type: 'string'|'int'|'email'|'date'|'bool'|'enum'|'decimal'|'phone'|'url'|'cf'|'piva'
     *   - max_length: N                 → lunghezza massima string
     *   - enum: [v1,v2,...]             → valore deve essere in elenco
     *   - fk: 'tableName:column'        → cerca valore in tabella, restituisce id
     *   - unique_in: 'table.column'     → check unicità (warning, non blocca)
     *   - default: any                  → valore default se vuoto
     *   - regex: '/.../'                → pattern matching
     *   - min: N / max: N               → valori numerici
     */
    public static function getSchema(string $type): array
    {
        return match ($type) {
            'dipendenti' => [
                'first_name'     => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Nome', 'hint' => 'Nome di battesimo', 'example' => 'Mario'],
                'last_name'      => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Cognome', 'hint' => 'Cognome', 'example' => 'Rossi'],
                'fiscal_code'    => ['type' => 'cf', 'unique_in' => 'employees.fiscal_code', 'label' => 'Codice Fiscale', 'hint' => '16 caratteri alfanumerici. Usato come chiave univoca per il match in update', 'example' => 'RSSMRA85M01H501Z'],
                'date_of_birth'  => ['type' => 'date', 'label' => 'Data di nascita', 'hint' => 'Formato YYYY-MM-DD o DD/MM/YYYY', 'example' => '1985-08-01'],
                'phone'          => ['type' => 'phone', 'max_length' => 50, 'label' => 'Telefono', 'hint' => 'Telefono personale', 'example' => '+39 333 1234567'],
                'personal_email' => ['type' => 'email', 'max_length' => 150, 'label' => 'Email personale', 'hint' => 'Email privata del dipendente', 'example' => 'mario.rossi@gmail.com'],
                'employee_code'  => ['type' => 'string', 'max_length' => 50, 'unique_in' => 'employees.employee_code', 'label' => 'Codice dipendente', 'hint' => 'Matricola interna. Univoco', 'example' => 'EMP001'],
                'job_title'      => ['type' => 'string', 'max_length' => 150, 'label' => 'Mansione', 'hint' => 'Posizione attuale', 'example' => 'Senior Developer'],
                'department'     => ['type' => 'string', 'max_length' => 100, 'label' => 'Reparto', 'hint' => 'Dipartimento di appartenenza', 'example' => 'IT'],
                'company_name'   => ['fk' => 'companies:name', 'fk_target' => 'company_id', 'label' => 'Azienda', 'hint' => 'Nome esatto azienda del gruppo (deve esistere in anagrafica)', 'example' => 'Antea srl'],
                'location_name'  => ['fk' => 'locations:name', 'fk_target' => 'location_id', 'label' => 'Sede', 'hint' => 'Nome esatto sede (deve esistere in anagrafica)', 'example' => 'Milano HQ'],
                'work_mode_name' => ['fk' => 'work_modes:name', 'fk_target' => 'work_mode_id', 'label' => 'Modalità lavoro', 'hint' => 'In sede / Remote / Ibrido', 'example' => 'Ibrido'],
                'contract_type'  => ['type' => 'enum', 'enum' => ['Indeterminato','Determinato','Apprendistato','Stage','Collaborazione','Partita IVA','Altro'], 'label' => 'Tipo contratto', 'hint' => 'Solo valori dell\'enumerazione'],
                'hire_date'      => ['type' => 'date', 'label' => 'Data assunzione', 'hint' => 'Inizio rapporto di lavoro', 'example' => '2020-01-15'],
                'end_date'       => ['type' => 'date', 'label' => 'Data fine', 'hint' => 'Solo per contratti a termine', 'example' => '2025-12-31'],
                'status'         => ['type' => 'enum', 'enum' => ['active','inactive','suspended'], 'default' => 'active', 'label' => 'Stato', 'hint' => 'active/inactive/suspended'],
            ],

            'accessi' => [
                'email'          => ['required' => true, 'type' => 'email', 'max_length' => 150, 'unique_in' => 'users.email', 'label' => 'Email login', 'hint' => 'Email di accesso al portale. Univoca', 'example' => 'mario.rossi@azienda.it'],
                'role_name'      => ['fk' => 'roles:name', 'fk_target' => 'role_id', 'required' => true, 'label' => 'Ruolo', 'hint' => 'Nome esatto del ruolo (Super Admin, HR Director, ecc.)', 'example' => 'Recruiter'],
                'employee_email' => ['type' => 'email', 'fk' => 'employees:personal_email', 'fk_target' => 'employee_id', 'label' => 'Email dipendente', 'hint' => 'Email anagrafica per collegare l\'utente. Opzionale', 'example' => 'mario.rossi@gmail.com'],
                'is_active'      => ['type' => 'bool', 'default' => '1', 'label' => 'Attivo', 'hint' => '1=attivo, 0=disattivato. Default: 1'],
            ],

            'brand' => [
                'name'           => ['required' => true, 'type' => 'string', 'max_length' => 100, 'unique_in' => 'brands.name', 'label' => 'Nome brand', 'hint' => 'Nome univoco del brand di certificazioni', 'example' => 'Cisco'],
                'description'    => ['type' => 'string', 'max_length' => 500, 'label' => 'Descrizione', 'hint' => 'Breve descrizione del brand', 'example' => 'Networking, security, collaboration'],
                'website'        => ['type' => 'url', 'max_length' => 200, 'label' => 'Sito web', 'hint' => 'URL completo con http:// o https://', 'example' => 'https://www.cisco.com'],
                'is_active'      => ['type' => 'bool', 'default' => '1', 'label' => 'Attivo', 'hint' => '1=attivo nel catalogo'],
            ],

            'tecnologie' => [
                'name'           => ['required' => true, 'type' => 'string', 'max_length' => 100, 'unique_in' => 'technologies.name', 'label' => 'Nome tecnologia', 'hint' => 'Entità trasversale cross-brand (es. Networking, Cloud, Security, Data&AI)', 'example' => 'Networking'],
                'description'    => ['type' => 'string', 'max_length' => 500, 'label' => 'Descrizione', 'hint' => 'Ambito coperto dalla tecnologia'],
                'category_name'  => ['fk' => 'tech_categories:name', 'fk_target' => 'category_id', 'label' => 'Categoria', 'hint' => 'Macro-categoria (Infrastructure, Security, Data & AI, DevOps, Software, Methodology)', 'example' => 'Infrastructure'],
                'slug'           => ['type' => 'string', 'max_length' => 120, 'label' => 'Slug URL', 'hint' => 'Identificatore URL-safe (auto-generato se vuoto)', 'example' => 'networking'],
                'icon'           => ['type' => 'string', 'max_length' => 50, 'label' => 'Icona', 'hint' => 'Classe FontAwesome (fa-network-wired)', 'example' => 'fa-network-wired'],
                'color'          => ['type' => 'string', 'max_length' => 7, 'label' => 'Colore', 'hint' => 'Codice HEX', 'example' => '#0ea5e9'],
                'is_active'      => ['type' => 'bool', 'default' => '1', 'label' => 'Attiva'],
                'brand_name'     => ['fk' => 'brands:name', 'fk_target' => 'brand_id', 'label' => 'Brand iniziale', 'hint' => 'Opzionale: alimenta il pivot N:M tech_brands (puoi associarne altri dopo)', 'example' => 'Cisco'],
            ],

            'catalogo' => [
                'code'        => ['required' => true, 'type' => 'string', 'max_length' => 50, 'unique_in' => 'certifications.code', 'label' => 'Codice certificazione', 'hint' => 'Codice ufficiale del vendor. Univoco', 'example' => 'CCNA-200-301'],
                'name'        => ['required' => true, 'type' => 'string', 'max_length' => 200, 'label' => 'Nome esteso', 'hint' => 'Nome completo della certificazione', 'example' => 'Cisco Certified Network Associate'],
                'brand_name'  => ['fk' => 'brands:name', 'fk_target' => 'brand_id', 'required' => true, 'label' => 'Brand', 'hint' => 'Nome esatto brand', 'example' => 'Cisco'],
                'tech_name'   => ['fk' => 'technologies:name', 'fk_target' => 'technology_id', 'label' => 'Tecnologia', 'hint' => 'Nome esatto tecnologia (opzionale)', 'example' => 'Networking'],
                'level'       => ['type' => 'extensible_enum', 'extensible_target' => 'certifications.level', 'label' => 'Livello', 'hint' => 'Livello della certificazione (Foundation/Associate/Professional/Expert/Specialty). Nuovi livelli vanno approvati separatamente.', 'example' => 'Associate'],
                'category'    => ['type' => 'extensible_enum', 'extensible_target' => 'certifications.category', 'default' => 'tecnica', 'label' => 'Tipologia', 'hint' => 'aziendale / commerciale / tecnica'],
                'validity_months' => ['type' => 'int', 'min' => 0, 'max' => 240, 'label' => 'Validità (mesi)', 'hint' => 'Mesi prima della scadenza (0=non scade)', 'example' => '36'],
                'cost_estimate'   => ['type' => 'decimal', 'label' => 'Costo (€)', 'hint' => 'Costo medio dell\'esame in euro', 'example' => '300'],
            ],

            'sedi' => [
                'name'         => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Nome sede', 'hint' => 'Es. Sede Milano, HQ Roma', 'example' => 'Milano HQ'],
                'company_name' => ['fk' => 'companies:name', 'fk_target' => 'company_id', 'required' => true, 'label' => 'Azienda', 'hint' => 'Nome esatto azienda', 'example' => 'Antea srl'],
                'address'      => ['type' => 'string', 'max_length' => 200, 'label' => 'Indirizzo', 'hint' => 'Via e numero civico', 'example' => 'Via Roma 10'],
                'city'         => ['type' => 'string', 'max_length' => 100, 'label' => 'Città', 'example' => 'Milano'],
                'province'     => ['type' => 'string', 'max_length' => 50, 'label' => 'Provincia', 'hint' => 'Sigla 2 lettere o nome esteso', 'example' => 'MI'],
                'country'      => ['type' => 'string', 'max_length' => 50, 'default' => 'Italia', 'label' => 'Stato', 'hint' => 'Default: Italia'],
                'is_active'    => ['type' => 'bool', 'default' => '1', 'label' => 'Attiva', 'hint' => '1=sede operativa'],
            ],

            'agenzie' => [
                'name'         => ['required' => true, 'type' => 'string', 'max_length' => 200, 'unique_in' => 'agencies.name', 'label' => 'Nome agenzia', 'hint' => 'Ragione sociale agenzia di recruiting', 'example' => 'Adecco SpA'],
                'vat_number'   => ['type' => 'piva', 'label' => 'P.IVA', 'hint' => '11 cifre', 'example' => '12345678901'],
                'email'        => ['type' => 'email', 'max_length' => 150, 'label' => 'Email contatto', 'example' => 'info@agenzia.it'],
                'phone'        => ['type' => 'phone', 'max_length' => 50, 'label' => 'Telefono', 'example' => '+39 02 1234567'],
                'website'      => ['type' => 'url', 'max_length' => 200, 'label' => 'Sito web', 'example' => 'https://www.agenzia.it'],
                'address'      => ['type' => 'string', 'max_length' => 255, 'label' => 'Indirizzo'],
                'city'         => ['type' => 'string', 'max_length' => 100, 'label' => 'Città'],
                'is_active'    => ['type' => 'bool', 'default' => '1', 'label' => 'Attiva'],
            ],

            'contatti_agenzie' => [
                'agency_name'  => ['fk' => 'agencies:name', 'fk_target' => 'agency_id', 'required' => true, 'label' => 'Nome agenzia', 'hint' => 'Nome esatto agenzia (deve esistere)', 'example' => 'Adecco SpA'],
                'first_name'   => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Nome referente', 'example' => 'Anna'],
                'last_name'    => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Cognome referente', 'example' => 'Bianchi'],
                'role'         => ['type' => 'string', 'max_length' => 100, 'label' => 'Ruolo', 'hint' => 'Es. Account Manager, Recruiter', 'example' => 'Account Manager'],
                'email'        => ['type' => 'email', 'max_length' => 150, 'label' => 'Email', 'example' => 'anna.bianchi@adecco.it'],
                'phone'        => ['type' => 'phone', 'max_length' => 30, 'label' => 'Telefono', 'example' => '+39 02 7654321'],
                'is_primary'   => ['type' => 'bool', 'default' => '0', 'label' => 'Referente principale', 'hint' => '1 = referente primario per questa agenzia'],
            ],

            'candidati' => [
                'first_name'     => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Nome', 'example' => 'Luca'],
                'last_name'      => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Cognome', 'example' => 'Verdi'],
                'email'          => ['required' => true, 'type' => 'email', 'max_length' => 150, 'unique_in' => 'candidates.email', 'label' => 'Email', 'hint' => 'Email candidato. Univoca = chiave per match', 'example' => 'luca.verdi@gmail.com'],
                'phone'          => ['type' => 'phone', 'max_length' => 50, 'label' => 'Telefono', 'example' => '+39 333 9876543'],
                'linkedin_url'   => ['type' => 'url', 'max_length' => 255, 'label' => 'LinkedIn', 'hint' => 'URL profilo LinkedIn completo', 'example' => 'https://linkedin.com/in/lucaverdi'],
                'ral_richiesta_k'=> ['type' => 'decimal', 'label' => 'RAL richiesta (k€)', 'hint' => 'In migliaia di euro. Es: 35 = 35.000€', 'example' => '35'],
                'preavviso_giorni'=> ['type' => 'int', 'min' => 0, 'max' => 365, 'label' => 'Preavviso (giorni)', 'hint' => 'Giorni di preavviso da rispettare', 'example' => '30'],
                'source'         => ['type' => 'enum', 'enum' => ['linkedin','indeed','referenza','agenzia','sito','altro'], 'default' => 'altro', 'label' => 'Fonte', 'hint' => 'Da dove arriva il candidato'],
                'agency_name'    => ['fk' => 'agencies:name', 'fk_target' => 'agency_id', 'label' => 'Agenzia', 'hint' => 'Solo se source=agenzia. Nome esatto agenzia', 'example' => 'Adecco SpA'],
            ],

            'clienti' => [
                'name'        => ['required' => true, 'type' => 'string', 'max_length' => 200, 'unique_in' => 'clients.name', 'label' => 'Ragione sociale', 'hint' => 'Nome univoco del cliente', 'example' => 'Cliente XYZ Spa'],
                'vat_number'  => ['type' => 'piva', 'label' => 'P.IVA', 'hint' => '11 cifre', 'example' => '01234567890'],
                'fiscal_code' => ['type' => 'cf', 'label' => 'Codice fiscale', 'hint' => '11 o 16 caratteri'],
                'sector'      => ['type' => 'string', 'max_length' => 100, 'label' => 'Settore', 'hint' => 'Es. IT, Finance, Manufacturing', 'example' => 'IT'],
                'city'        => ['type' => 'string', 'max_length' => 100, 'label' => 'Città', 'example' => 'Milano'],
                'phone'       => ['type' => 'phone', 'max_length' => 50, 'label' => 'Telefono'],
                'email'       => ['type' => 'email', 'max_length' => 150, 'label' => 'Email', 'example' => 'info@cliente.it'],
                'is_active'   => ['type' => 'bool', 'default' => '1', 'label' => 'Attivo'],
            ],

            'tech_brand_links' => [
                'tech_name'   => ['fk' => 'technologies:name', 'fk_target' => 'technology_id', 'required' => true, 'label' => 'Nome tecnologia', 'hint' => 'Tecnologia esistente', 'example' => 'Networking'],
                'brand_name'  => ['fk' => 'brands:name', 'fk_target' => 'brand_id', 'required' => true, 'label' => 'Brand', 'hint' => 'Brand da associare', 'example' => 'Cisco'],
                'is_primary'  => ['type' => 'bool', 'default' => '0', 'label' => 'Brand primario', 'hint' => '1 se è il vendor primario per questa tech'],
                'notes'       => ['type' => 'string', 'max_length' => 500, 'label' => 'Note'],
            ],

            'tech_cert_links' => [
                'tech_name'   => ['fk' => 'technologies:name', 'fk_target' => 'technology_id', 'required' => true, 'label' => 'Tecnologia', 'example' => 'Networking'],
                'cert_code'   => ['fk' => 'certifications:code', 'fk_target' => 'certification_id', 'required' => true, 'label' => 'Codice certificazione', 'example' => 'CCNA-200-301'],
                'relevance'   => ['type' => 'enum', 'enum' => ['primary','secondary','related'], 'default' => 'primary', 'label' => 'Rilevanza', 'hint' => 'primary=core, secondary=correlata, related=parziale'],
            ],

            'employee_skills' => [
                'employee_email' => ['fk' => 'employees:personal_email', 'fk_target' => 'employee_id', 'required' => true, 'label' => 'Email dipendente', 'hint' => 'Email anagrafica dipendente', 'example' => 'mario.rossi@gmail.com'],
                'skill_name'     => ['required' => true, 'type' => 'string', 'max_length' => 100, 'label' => 'Skill', 'hint' => 'Nome competenza (es. Python, AWS Lambda, Kubernetes)', 'example' => 'Python'],
                'level'          => ['type' => 'extensible_enum', 'extensible_target' => 'employee_skills.level', 'default' => 'intermediate', 'label' => 'Livello', 'hint' => 'beginner/intermediate/advanced/expert'],
                'years'          => ['type' => 'decimal', 'min' => 0, 'max' => 50, 'label' => 'Anni esperienza', 'example' => '5'],
                'last_used'      => ['type' => 'date', 'label' => 'Ultimo utilizzo', 'hint' => 'Data ultimo progetto in cui usata'],
                'self_assessed'  => ['type' => 'bool', 'default' => '1', 'label' => 'Auto-valutata', 'hint' => '1=auto-dichiarata, 0=validata da manager'],
                'notes'          => ['type' => 'string', 'max_length' => 500, 'label' => 'Note'],
            ],

            'templates' => [
                'tipo'      => ['required' => true, 'type' => 'enum', 'enum' => ['hard_skills','soft_skills','we_offer','offer_info','description','nice_to_have'], 'label' => 'Tipo template', 'hint' => 'Sezione del template posizione'],
                'nome'      => ['required' => true, 'type' => 'string', 'max_length' => 150, 'label' => 'Nome template', 'hint' => 'Nome univoco. Stessi (tipo+nome) creano nuova versione', 'example' => 'PHP Senior'],
                'contenuto' => ['required' => true, 'type' => 'string', 'label' => 'Contenuto', 'hint' => 'Testo del template (può contenere a capo)', 'example' => 'PHP 8.x, MySQL, Laravel/Symfony'],
                'note'      => ['type' => 'string', 'max_length' => 255, 'label' => 'Note versione', 'hint' => 'Annotazione su questa versione', 'example' => 'Aggiornato 2026'],
            ],

            'certificati' => [
                'employee_email' => ['fk' => 'employees:personal_email', 'fk_target' => 'employee_id', 'required' => true, 'label' => 'Email dipendente', 'hint' => 'Email personale del dipendente che ha conseguito la cert. Deve esistere in anagrafica', 'example' => 'mario.rossi@gmail.com'],
                'cert_code'      => ['fk' => 'certifications:code', 'fk_target' => 'certification_id', 'required' => true, 'label' => 'Codice certificazione', 'hint' => 'Codice esatto dal catalogo', 'example' => 'CCNA-200-301'],
                'issue_date'     => ['required' => true, 'type' => 'date', 'label' => 'Data rilascio', 'hint' => 'Quando è stata conseguita', 'example' => '2024-06-15'],
                'expiry_date'    => ['type' => 'date', 'label' => 'Data scadenza', 'hint' => 'Lasciare vuoto se non scade', 'example' => '2027-06-15'],
                'status'         => ['type' => 'enum', 'enum' => ['active','expiring','expired','revoked'], 'default' => 'active', 'label' => 'Stato', 'hint' => 'Stato corrente certificazione'],
                'score'          => ['type' => 'int', 'min' => 0, 'max' => 1000, 'label' => 'Punteggio', 'hint' => 'Voto/punteggio se applicabile', 'example' => '850'],
                'certificate_code' => ['type' => 'string', 'max_length' => 100, 'label' => 'Codice certificato', 'hint' => 'Codice univoco rilasciato dal vendor', 'example' => 'CSC0123456789'],
                'notes'          => ['type' => 'string', 'label' => 'Note', 'hint' => 'Annotazioni libere'],
            ],

            'piani_formativi' => [
                'employee_email' => ['fk' => 'employees:personal_email', 'fk_target' => 'employee_id', 'required' => true, 'label' => 'Email dipendente', 'hint' => 'Email personale dipendente', 'example' => 'mario.rossi@gmail.com'],
                'cert_code'      => ['fk' => 'certifications:code', 'fk_target' => 'certification_id', 'required' => true, 'label' => 'Codice certificazione', 'hint' => 'Codice esatto certificazione obiettivo', 'example' => 'CCNA-200-301'],
                'plan_type'      => ['type' => 'enum', 'enum' => ['formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno'], 'default' => 'formazione', 'label' => 'Tipo piano', 'hint' => 'Tipologia attività formativa'],
                'target_date'    => ['type' => 'date', 'label' => 'Data target', 'hint' => 'Quando si vuole completare', 'example' => '2024-12-31'],
                'planned_exam_date' => ['type' => 'date', 'label' => 'Data esame previsto', 'hint' => 'Data esame se schedulato', 'example' => '2024-11-15'],
                'status'         => ['type' => 'enum', 'enum' => ['planned','in_progress','completed','cancelled'], 'default' => 'planned', 'label' => 'Stato'],
                'priority'       => ['type' => 'enum', 'enum' => ['Bassa','Media','Alta'], 'default' => 'Media', 'label' => 'Priorità'],
                'budget'         => ['type' => 'decimal', 'label' => 'Budget (€)', 'hint' => 'Budget allocato', 'example' => '500'],
                'is_renewal'     => ['type' => 'bool', 'default' => '0', 'label' => 'Rinnovo', 'hint' => '1 se è rinnovo di certificazione esistente'],
                'notes'          => ['type' => 'string', 'label' => 'Note'],
            ],

            'esami' => [
                'employee_email' => ['fk' => 'employees:personal_email', 'fk_target' => 'employee_id', 'required' => true, 'label' => 'Email dipendente', 'hint' => 'Email personale dipendente', 'example' => 'mario.rossi@gmail.com'],
                'cert_code'      => ['fk' => 'certifications:code', 'fk_target' => 'certification_id', 'label' => 'Codice certificazione', 'hint' => 'Opzionale se generic exam', 'example' => 'CCNA-200-301'],
                'planned_date'   => ['required' => true, 'type' => 'date', 'label' => 'Data esame', 'hint' => 'Data programmata', 'example' => '2024-12-10'],
                'plan_type'      => ['type' => 'enum', 'enum' => ['formazione','esame_certificazione','rinnovo','workshop_tecnico','workshop_commerciale','convegno'], 'default' => 'esame_certificazione', 'label' => 'Tipo'],
                'status'         => ['type' => 'enum', 'enum' => ['planned','completed','cancelled'], 'default' => 'planned', 'label' => 'Stato'],
                'result'         => ['type' => 'enum', 'enum' => ['passed','failed'], 'label' => 'Risultato', 'hint' => 'Solo se status=completed'],
                'exam_center'    => ['type' => 'string', 'max_length' => 200, 'label' => 'Centro esami', 'hint' => 'Nome centro/Pearson VUE', 'example' => 'Pearson VUE Milano'],
                'exam_location'  => ['type' => 'string', 'max_length' => 300, 'label' => 'Sede esame', 'hint' => 'Indirizzo sede esame'],
                'booking_code'   => ['type' => 'string', 'max_length' => 100, 'label' => 'Codice prenotazione', 'hint' => 'Codice voucher/booking'],
                'needs_logistics'=> ['type' => 'bool', 'default' => '0', 'label' => 'Logistica', 'hint' => '1 se richiede trasferta/logistica'],
                'notes'          => ['type' => 'string', 'label' => 'Note'],
            ],

            default => [],
        };
    }

    /**
     * Valida una riga (associative array).
     *
     * @return array{valid: bool, errors: array<string,string>, normalized: array}
     *   - valid: true se nessun errore
     *   - errors: campo → messaggio (UI può evidenziare in rosso)
     *   - normalized: dati normalizzati (FK risolte, default applicati, ecc.)
     */
    public function validateRow(array $row): array
    {
        $errors = [];
        $normalized = [];

        foreach ($this->schema as $field => $rules) {
            $value = $row[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            // Default
            if (($value === null || $value === '') && isset($rules['default'])) {
                $value = $rules['default'];
            }

            // Required
            if (($rules['required'] ?? false) && ($value === null || $value === '')) {
                $errors[$field] = 'Campo obbligatorio mancante';
                continue;
            }

            // Skip se vuoto e non required
            if ($value === null || $value === '') {
                $normalized[$field] = null;
                continue;
            }

            // Type validation
            $type = $rules['type'] ?? 'string';
            $typeError = $this->validateType($value, $type, $rules);
            if ($typeError) {
                $errors[$field] = $typeError;
                continue;
            }

            // Max length
            if (isset($rules['max_length']) && is_string($value) && mb_strlen($value) > $rules['max_length']) {
                $errors[$field] = "Lunghezza massima {$rules['max_length']} caratteri";
                continue;
            }

            // Enum
            if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                $errors[$field] = 'Valore non ammesso. Valori validi: ' . implode(', ', $rules['enum']);
                continue;
            }

            // v5.8: Extensible enum (es. catalogo.level) — validazione strict:
            // valori non in lista sono errori bloccanti se NON c'è già una proposta mapped.
            if (($rules['type'] ?? '') === 'extensible_enum' && isset($rules['extensible_target'])) {
                $r = $this->resolveExtensibleEnum($rules['extensible_target'], (string)$value, false);
                if (isset($r['_invalid'])) {
                    $errors[$field] = $r['_reason'] ?? 'Valore non ammesso';
                    continue;
                }
                if (isset($r['_value'])) {
                    $value = $r['_value'];
                    $normalized[$field] = $value;
                    continue;
                }
            }

            // Range numeric
            if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
                $errors[$field] = "Valore minimo: {$rules['min']}";
                continue;
            }
            if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
                $errors[$field] = "Valore massimo: {$rules['max']}";
                continue;
            }

            // FK resolution
            if (isset($rules['fk'])) {
                [$fkTable, $fkColumn] = explode(':', $rules['fk']);
                $fkValue = $this->resolveFk($fkTable, $fkColumn, $value);
                if ($fkValue === null) {
                    $errors[$field] = "Riferimento '$value' non trovato in $fkTable.$fkColumn";
                    continue;
                }
                if (isset($rules['fk_target'])) {
                    $normalized[$rules['fk_target']] = $fkValue;
                }
            }

            // Unique check (warning, non blocca - può essere update)
            // Lo gestisce il commit handler

            $normalized[$field] = $this->castType($value, $type);
        }

        return [
            'valid'      => empty($errors),
            'errors'     => $errors,
            'normalized' => $normalized,
        ];
    }

    private function validateType($value, string $type, array $rules): ?string
    {
        switch ($type) {
            case 'int':
                if (!is_numeric($value) || (int)$value != $value) return 'Deve essere un numero intero';
                break;
            case 'decimal':
                if (!is_numeric($value)) return 'Deve essere un numero';
                break;
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) return 'Email non valida';
                break;
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) return 'URL non valido (deve iniziare con http:// o https://)';
                break;
            case 'date':
                $d = DateTime::createFromFormat('Y-m-d', $value)
                  ?: DateTime::createFromFormat('d/m/Y', $value);
                if (!$d) return 'Data non valida (usa formato YYYY-MM-DD o DD/MM/YYYY)';
                break;
            case 'bool':
                if (!in_array(strtolower((string)$value), ['0','1','true','false','si','sì','no','yes','y','n'], true)) {
                    return 'Valore booleano (0/1/si/no/true/false)';
                }
                break;
            case 'phone':
                if (!preg_match('/^[+0-9\s().\-\/]{4,30}$/', $value)) return 'Numero di telefono non valido';
                break;
            case 'cf':
                $v = strtoupper((string)$value);
                if (!preg_match('/^[A-Z0-9]{16}$/', $v) && !preg_match('/^[0-9]{11}$/', $v)) {
                    return 'Codice fiscale non valido (16 caratteri alfanumerici o 11 cifre)';
                }
                break;
            case 'piva':
                if (!preg_match('/^[0-9]{11}$/', (string)$value)) return 'Partita IVA non valida (11 cifre)';
                break;
            case 'enum':
                // Gestito dopo
                break;
            case 'string':
            default:
                // Tutto stringa OK
                break;
        }
        return null;
    }

    private function castType($value, string $type)
    {
        return match ($type) {
            'int'     => (int)$value,
            'decimal' => (float)$value,
            'bool'    => in_array(strtolower((string)$value), ['1','true','si','sì','yes','y'], true) ? 1 : 0,
            'date'    => $this->normalizeDate((string)$value),
            default   => is_string($value) ? trim($value) : $value,
        };
    }

    private function normalizeDate(string $value): ?string
    {
        $d = DateTime::createFromFormat('Y-m-d', $value)
          ?: DateTime::createFromFormat('d/m/Y', $value);
        return $d ? $d->format('Y-m-d') : null;
    }

    /** Risolve un FK con cache. */
    private function resolveFk(string $table, string $column, $value): ?int
    {
        $cacheKey = "$table:$column:$value";
        if (isset($this->fkCache[$cacheKey])) {
            return $this->fkCache[$cacheKey];
        }
        // Whitelist tabelle e colonne (sicurezza anti-injection)
        $allowed = [
            'companies'      => ['name'],
            'locations'      => ['name'],
            'work_modes'     => ['name'],
            'roles'          => ['name'],
            'employees'      => ['personal_email','fiscal_code'],
            'brands'         => ['name'],
            'technologies'   => ['name'],
            'tech_categories'=> ['name'],
            'certifications' => ['code'],
            'agencies'       => ['name'],
            'clients'        => ['name'],
        ];
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id FROM `$table` WHERE `$column` = ? LIMIT 1");
        $stmt->execute([$value]);
        $id = $stmt->fetchColumn();
        $result = $id !== false ? (int)$id : null;
        $this->fkCache[$cacheKey] = $result;
        return $result;
    }



    /**
     * v5.5 — Validazione Late Data Binding (LDB).
     *
     * Differenze da validateRow():
     *   - I campi obbligatori MANCANTI non sono errori bloccanti, ma vengono
     *     elencati in `missing_fields` come campi da completare manualmente.
     *   - I campi presenti vengono comunque validati per tipo/formato/FK.
     *     Se un campo presente ha un valore non valido, resta errore (rifiutato).
     *
     * Restituisce:
     *   - valid          → bool (true se non ci sono errori di formato/tipo)
     *   - errors         → array<string,string> errori sui campi presenti
     *   - missing_fields → array<int,string> nomi dei campi obbligatori vuoti
     *   - normalized     → dati normalizzati (FK risolte ove possibile)
     *
     * Use case: un import con 1000 righe in cui 200 hanno il campo
     * `date_of_birth` vuoto. In modalità LDB queste 200 righe vengono
     * importate (status='partial') e l'utente le completa via UI.
     */
    public function validateRowPartial(array $row): array
    {
        $errors = [];
        $missingFields = [];
        $normalized = [];

        foreach ($this->schema as $field => $rules) {
            $value = $row[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            // Default
            if (($value === null || $value === '') && isset($rules['default'])) {
                $value = $rules['default'];
            }

            // Required → tracciato in missing_fields, NON è errore bloccante
            if (($rules['required'] ?? false) && ($value === null || $value === '')) {
                $missingFields[] = $field;
                $normalized[$field] = null;
                // Se è una FK, non posso risolvere: salto target
                continue;
            }

            // Skip vuoti non required
            if ($value === null || $value === '') {
                $normalized[$field] = null;
                continue;
            }

            // Validazione tipo (sui valori effettivamente presenti)
            $type = $rules['type'] ?? 'string';
            $typeError = $this->validateType($value, $type, $rules);
            if ($typeError) {
                $errors[$field] = $typeError;
                continue;
            }

            // Max length
            if (isset($rules['max_length']) && is_string($value) && mb_strlen($value) > $rules['max_length']) {
                $errors[$field] = "Lunghezza massima {$rules['max_length']} caratteri";
                continue;
            }

            // Enum
            if (isset($rules['enum']) && !in_array($value, $rules['enum'], true)) {
                $errors[$field] = 'Valore non ammesso. Valori validi: ' . implode(', ', $rules['enum']);
                continue;
            }

            // v5.8: Extensible enum — valori sconosciuti diventano "missing" + proposta.
            if (($rules['type'] ?? '') === 'extensible_enum' && isset($rules['extensible_target'])) {
                $r = $this->resolveExtensibleEnum($rules['extensible_target'], (string)$value, true);
                if (isset($r['_invalid'])) {
                    $errors[$field] = $r['_reason'] ?? 'Valore non ammesso';
                    continue;
                }
                if (isset($r['_value'])) {
                    $value = $r['_value'];
                    $normalized[$field] = $value;
                    continue;
                }
                if (isset($r['_proposal'])) {
                    // Valore nuovo: registrato come proposta. Riga va in partial.
                    $missingFields[] = $field;
                    $normalized[$field] = null;
                    $normalized['__enum_proposals__'][$field] = [
                        'target'         => $r['_target'],
                        'proposed_value' => $r['_proposal'],
                    ];
                    continue;
                }
            }

            // Range
            if (isset($rules['min']) && is_numeric($value) && $value < $rules['min']) {
                $errors[$field] = "Valore minimo: {$rules['min']}";
                continue;
            }
            if (isset($rules['max']) && is_numeric($value) && $value > $rules['max']) {
                $errors[$field] = "Valore massimo: {$rules['max']}";
                continue;
            }

            // FK resolution (se presente, deve esistere)
            if (isset($rules['fk'])) {
                [$fkTable, $fkColumn] = explode(':', $rules['fk']);
                $fkValue = $this->resolveFk($fkTable, $fkColumn, $value);
                if ($fkValue === null) {
                    $errors[$field] = "Riferimento '$value' non trovato in $fkTable.$fkColumn";
                    continue;
                }
                if (isset($rules['fk_target'])) {
                    $normalized[$rules['fk_target']] = $fkValue;
                }
            }

            $normalized[$field] = $this->castType($value, $type);
        }

        return [
            'valid'          => empty($errors),
            'errors'         => $errors,
            'missing_fields' => $missingFields,
            'normalized'     => $normalized,
        ];
    }


    /**
     * v5.8 — Risolve un valore per un campo "extensible_enum".
     *
     * Comportamento:
     *  - exact match (case-sensitive) → ritorna il valore canonico
     *  - fuzzy match (case-insensitive)  → ritorna il valore canonico
     *  - nessun match e $allowProposal=false → ritorna ['_invalid' => true]
     *  - nessun match e $allowProposal=true  → registra proposta ed
     *      restituisce ['_proposal' => $valoreOriginale]
     *      (la riga andrà in stato 'partial' con missing_field = $field)
     */
    private function resolveExtensibleEnum(string $target, string $value, bool $allowProposal = true): array
    {
        if (!isset($this->enumExtender)) $this->enumExtender = new EnumExtender($this->pdo);
        [$tbl, $col] = explode('.', $target, 2);

        // Se non whitelistato: degrada a errore standard (sicurezza)
        if (!EnumExtender::isWhitelisted($tbl, $col)) {
            return ['_invalid' => true, '_reason' => "Target enum non whitelistato: $target"];
        }

        $r = $this->enumExtender->resolve($tbl, $col, $value);
        if ($r['exact'] !== null) return ['_value' => $r['exact']];
        if ($r['fuzzy'] !== null) return ['_value' => $r['fuzzy']];   // case-insensitive recovery

        // Valore mai visto. Se c'è già una proposta MAPPED, applica la mappatura.
        $mapped = $this->pdo->prepare(
            "SELECT mapped_to FROM enum_proposals
              WHERE target_table = ? AND target_column = ? AND proposed_value = ? AND status = 'mapped'"
        );
        $mapped->execute([$tbl, $col, $value]);
        $m = $mapped->fetchColumn();
        if ($m !== false && $m !== null && $m !== '') {
            return ['_value' => (string)$m];
        }

        if (!$allowProposal) {
            return ['_invalid' => true, '_reason' => "Valore '$value' non in lista. Valori validi: " . implode(', ', $this->enumExtender->getEnumValues($tbl, $col))];
        }

        // Nuovo valore: registra proposta. La riga andrà in 'partial'.
        $this->enumExtender->recordProposal($tbl, $col, $value, $this->jobIdForProposals ?? null);
        return ['_proposal' => $value, '_target' => $target];
    }

    /** Setta il job_id corrente per associare le proposte all'import in corso. */
    public function setJobIdForProposals(?int $jobId): void { $this->jobIdForProposals = $jobId; }

    private ?int $jobIdForProposals = null;
    private ?EnumExtender $enumExtender = null;

    /** Lista campi obbligatori per UI. */
    public function getRequiredFields(): array
    {
        $req = [];
        foreach ($this->schema as $f => $r) {
            if ($r['required'] ?? false) $req[] = $f;
        }
        return $req;
    }

    /** Ritorna lo schema completo (per UI editor). */
    public function getFullSchema(): array
    {
        return $this->schema;
    }
}
