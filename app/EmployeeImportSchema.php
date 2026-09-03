<?php
/**
 * PortalManager — app/EmployeeImportSchema.php  (v1.8.43)
 *
 * Definizione UNICA del tracciato di interscambio dell'anagrafica dipendenti.
 *
 * Il template scaricabile e il parser di import leggono entrambi da qui: è questa
 * unicità della fonte a garantire che restino allineati. Aggiungere una colonna
 * significa toccare solo questo file, e sia il template sia l'import la
 * riconoscono immediatamente.
 *
 * Ogni voce descrive:
 *   label     intestazione mostrata nel template (e attesa nel file importato)
 *   field     campo logico usato dall'importer
 *   type      text | date | int | bool | decimal | enum | lookup
 *   syn       intestazioni alternative accettate in import (retro-compatibilità)
 *   example   valore di esempio nella riga dimostrativa del template
 *   note      spiegazione nel foglio "Istruzioni"
 *   sensitive true = colonna retributiva, inclusa solo con permesso Compensation
 */
final class EmployeeImportSchema
{
    /** Tracciato canonico, nell'ordine in cui compare nel template. */
    public const COLUMNS = [
        // ── Identificazione ──────────────────────────────────────────────
        ['label'=>'Matricola',            'field'=>'employee_code','type'=>'text','syn'=>['dipendente','codice','matricola_dipendente'],
         'example'=>'EMP001','note'=>'Codice interno. Insieme al codice fiscale è la chiave di aggiornamento.'],
        ['label'=>'Cognome',              'field'=>'last_name','type'=>'text','syn'=>['cognome_dipendente'],
         'example'=>'Rossi','note'=>'Obbligatorio.'],
        ['label'=>'Nome',                 'field'=>'first_name','type'=>'text','syn'=>['nome_dipendente'],
         'example'=>'Mario','note'=>'Obbligatorio.'],
        ['label'=>'Codice fiscale',       'field'=>'fiscal_code','type'=>'text','syn'=>['cf','codice_fiscale'],
         'example'=>'RSSMRA80A01H501U','note'=>'Chiave primaria di riconciliazione: se presente prevale sulla matricola.'],
        ['label'=>'Data di nascita',      'field'=>'date_of_birth','type'=>'date','syn'=>['data_nascita','nascita'],
         'example'=>'01/01/1980','note'=>'Formato GG/MM/AAAA oppure AAAA-MM-GG.'],
        ['label'=>'Genere',               'field'=>'gender','type'=>'enum','syn'=>['sesso'],
         'example'=>'M','note'=>'M, F oppure altro. Accetta anche Maschio/Femmina.'],

        // ── Recapiti ─────────────────────────────────────────────────────
        ['label'=>'Email aziendale',      'field'=>'business_email','type'=>'text','syn'=>['email','email_azienda'],
         'example'=>'mario.rossi@azienda.it','note'=>''],
        ['label'=>'Email personale',      'field'=>'personal_email','type'=>'text','syn'=>['email_personale','email_privata'],
         'example'=>'','note'=>''],
        ['label'=>'Telefono',             'field'=>'phone','type'=>'text','syn'=>['telefono_aziendale','cellulare'],
         'example'=>'','note'=>''],
        ['label'=>'Telefono personale',   'field'=>'phone_personal','type'=>'text','syn'=>['telefono_privato','cellulare_personale'],
         'example'=>'','note'=>''],

        // ── Collocazione organizzativa ───────────────────────────────────
        ['label'=>'Azienda',              'field'=>'company','type'=>'lookup','syn'=>['societa','società','ragione_sociale'],
         'example'=>'WETECH\'S SPA SB','note'=>'Cercata per nome. Con l\'opzione attiva, le aziende mancanti vengono create.'],
        ['label'=>'Sede',                 'field'=>'location','type'=>'lookup','syn'=>['sede_di_lavoro','filiale'],
         'example'=>'Sede Milano','note'=>'Cercata per nome sede o città sotto l\'azienda indicata.'],
        ['label'=>'Modalità lavoro',      'field'=>'work_mode','type'=>'lookup','syn'=>['modalita','modalita_lavoro','modalità'],
         'example'=>'Ibrido','note'=>'Deve corrispondere a una modalità già configurata. Valore non trovato: campo lasciato invariato.'],
        ['label'=>'Dipartimento',         'field'=>'department_name','type'=>'lookup','syn'=>['unita_organizzativa','reparto','dipartimento_unita'],
         'example'=>'Acquisti','note'=>'Cercato per nome fra le unità organizzative esistenti.'],
        ['label'=>'Sotto-categoria',      'field'=>'subcategory','type'=>'lookup','syn'=>['sottocategoria','sotto_categoria'],
         'example'=>'','note'=>'Cercata fra le sotto-categorie del dipartimento indicato.'],
        ['label'=>'Agenzia',              'field'=>'agency','type'=>'text','syn'=>['agenzia_somministrazione','agenzia_interinale'],
         'example'=>'','note'=>'Per i rapporti in somministrazione.'],
        ['label'=>'Qualifica',            'field'=>'job_title','type'=>'text','syn'=>['mansione','ruolo_aziendale','job_title'],
         'example'=>'Sistemista Senior','note'=>'Mansione descrittiva, distinta dalla qualifica CCNL.'],

        // ── Inquadramento contrattuale ───────────────────────────────────
        ['label'=>'CCNL',                 'field'=>'ccnl','type'=>'text','syn'=>['contratto_collettivo'],
         'example'=>'Metalmeccanico','note'=>''],
        ['label'=>'Qualifica CCNL',       'field'=>'qualification','type'=>'text','syn'=>['qualifica_ccnl','inquadramento'],
         'example'=>'Impiegato','note'=>''],
        ['label'=>'Livello CCNL',         'field'=>'contract_level','type'=>'text','syn'=>['livello','livello_ccnl'],
         'example'=>'5','note'=>''],
        ['label'=>'Tipo contratto',       'field'=>'contract_type','type'=>'enum','syn'=>['tipo_rapporto','rapporto'],
         'example'=>'Indeterminato','note'=>'Indeterminato, Determinato, Apprendistato, Interinale, Somministrazione, Consulenza, Stage, Partita IVA.'],
        ['label'=>'Part-time',            'field'=>'part_time','type'=>'bool','syn'=>['tipo_part_time','parttime'],
         'example'=>'No','note'=>'Si oppure No.'],
        ['label'=>'% Part-time',          'field'=>'part_time_pct','type'=>'decimal','syn'=>['pt','pct','%pt','perc_part_time','percentuale_part_time'],
         'example'=>'','note'=>'Percentuale numerica, es. 50. Compilare solo se part-time è Si.'],
        ['label'=>'Scad. apprendistato',  'field'=>'apprenticeship_end_date','type'=>'date','syn'=>['scadenza_apprendistato','data_scadenza_apprendistato'],
         'example'=>'','note'=>'Solo per contratti di apprendistato.'],
        ['label'=>'Data assunzione',      'field'=>'hire_date','type'=>'date','syn'=>['assunzione','data_assunzione'],
         'example'=>'01/03/2020','note'=>''],
        ['label'=>'Data cessazione',      'field'=>'end_date','type'=>'date','syn'=>['cessazione','data_cessazione','fine_rapporto'],
         'example'=>'','note'=>'Se valorizzata, il dipendente viene marcato come cessato.'],
        ['label'=>'Classificazione',      'field'=>'classificazione_finanziaria','type'=>'enum','syn'=>['classificazione_finanziaria','diretto_indiretto'],
         'example'=>'Diretto','note'=>'Diretto oppure Indiretto.'],

        // ── Badge ────────────────────────────────────────────────────────
        ['label'=>'Badge numero',         'field'=>'badge_number','type'=>'text','syn'=>['badge','numero_badge','badge_numero'],
         'example'=>'','note'=>''],
        ['label'=>'Badge data rilascio',  'field'=>'badge_issue_date','type'=>'date','syn'=>['data_rilascio_badge','rilascio_badge','badge_data_rilascio'],
         'example'=>'','note'=>''],

        // ── Stato ────────────────────────────────────────────────────────
        ['label'=>'Stato',                'field'=>'status','type'=>'enum','syn'=>['stato_dipendente','attivo'],
         'example'=>'active','note'=>'active, inactive o terminated. Se vuoto viene dedotto dalla data di cessazione.'],

        // ── Retribuzione (solo con permesso Compensation) ────────────────
        ['label'=>'RAL',                  'field'=>'ral','type'=>'decimal','syn'=>['retribuzione_annua_lorda','stipendio'],
         'example'=>'','note'=>'Retribuzione annua lorda. Colonna visibile solo con il permesso Compensation.',
         'sensitive'=>true],
        ['label'=>'Premio concordato',    'field'=>'premio_concordato','type'=>'decimal','syn'=>['premio','bonus_concordato'],
         'example'=>'','note'=>'Colonna visibile solo con il permesso Compensation.',
         'sensitive'=>true],
    ];

    /**
     * Normalizza un'intestazione per il confronto (minuscole, separatori unificati).
     *
     * Il simbolo % è tradotto in "perc" anziché essere scartato: senza questo
     * accorgimento "% Part-time" e "Part-time" collasserebbero sulla stessa
     * chiave e la seconda definizione sovrascriverebbe la prima, facendo finire
     * la percentuale nel campo booleano.
     */
    public static function normalize(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = str_replace(['à','è','é','ì','ò','ù'], ['a','e','e','i','o','u'], $h);
        $h = str_replace('%', ' perc ', $h);
        $h = preg_replace('/[^a-z0-9]+/u', '_', $h);
        return trim((string)$h, '_');
    }

    /** Verifica che nessuna intestazione o sinonimo collida su un campo diverso. */
    public static function collisions(): array
    {
        $seen = []; $bad = [];
        foreach (self::COLUMNS as $c) {
            foreach (array_merge([$c['label']], $c['syn'] ?? []) as $h) {
                $k = self::normalize($h);
                if (isset($seen[$k]) && $seen[$k] !== $c['field']) {
                    $bad[] = "$h -> {$seen[$k]} / {$c['field']}";
                }
                $seen[$k] = $c['field'];
            }
        }
        return $bad;
    }

    /**
     * Colonne del tracciato, filtrate per permesso retributivo.
     * @return array<int,array<string,mixed>>
     */
    public static function columns(bool $withCompensation = true): array
    {
        if ($withCompensation) return self::COLUMNS;
        return array_values(array_filter(self::COLUMNS, fn($c) => empty($c['sensitive'])));
    }

    /** Sole intestazioni, nell'ordine del template. @return string[] */
    public static function labels(bool $withCompensation = true): array
    {
        return array_column(self::columns($withCompensation), 'label');
    }

    /** Riga di esempio allineata alle intestazioni. @return string[] */
    public static function exampleRow(bool $withCompensation = true): array
    {
        return array_map(fn($c) => (string)($c['example'] ?? ''), self::columns($withCompensation));
    }

    /**
     * Mappa intestazione-normalizzata → campo logico, comprensiva dei sinonimi.
     * È la mappa usata dal parser di import.
     * @return array<string,string>
     */
    public static function headerMap(): array
    {
        $map = [];
        foreach (self::COLUMNS as $c) {
            $map[self::normalize($c['label'])] = $c['field'];
            foreach (($c['syn'] ?? []) as $s) $map[self::normalize($s)] = $c['field'];
        }
        return $map;
    }

    /** Campo logico → descrittore di colonna. @return array<string,array<string,mixed>> */
    public static function byField(): array
    {
        $out = [];
        foreach (self::COLUMNS as $c) $out[$c['field']] = $c;
        return $out;
    }

    /** Campi di un dato tipo. @return string[] */
    public static function fieldsOfType(string $type): array
    {
        return array_values(array_map(
            fn($c) => $c['field'],
            array_filter(self::COLUMNS, fn($c) => $c['type'] === $type)
        ));
    }
}
