<?php
/**
 * ItServiceModel — letture per la Relazione di Servizio IT.
 *
 * v1.9.90 — Riepilogo per Codice Contratto e Dettaglio per commessa sul perimetro unico (moduli di
 *           intervento filtrati dal pannello principale), non piu' su una selezione DGB propria.
 * v1.9.89 — ore per classe (ordinarie / fuori orario / reperibilità / non classificate) uguali in ogni
 *           tabella: dettaglio aggregato, giorni per persona, andamento. Rapportino agganciato per id
 *           (non per codice modulo). Dettaglio delle ore non valorizzate con il motivo.
 * v1.9.88 — perimetro = tutto l'eseguito nel periodo per data del modulo di intervento: nessuna
 *           distinzione commesse attive / chiuse alla data, nessuna linea esclusa dai giorni lavorati;
 *           ore non valorizzate (senza tariffa) incluse e ripartite per persona, linea, area e altre dimensioni.
 * v1.9.87 — filtro «Stato commessa» (aperta / chiusa / sospesa / non chiusa) e perimetro unico:
 *           costi, giorni e sezioni DGB applicano TUTTI i filtri della pagina tramite lo stesso
 *           insieme di rapportini (perimetro()), invece di un sottoinsieme ricostruito a mano.
 * v1.9.78 — filtro contratto delegato a PmContractFilter (condiviso con SD, direzionale, DGB).
 * v1.9.77 — filtro globale Codice Contratto / PM Project (`contratti`) su tutti i dataset.
 *
 * v1.9.42 — fix Riepilogo/Dettaglio per Codice Contratto: dgb_forms_contract e
 * dgb_operator portati a LEFT JOIN (dgb_forms_contract e' vuota finche' non
 * sincronizzata → l'INNER JOIN scartava tutte le righe). Identita' contratto
 * spostata su a.id_contract; etichetta da cm_projects.project_code con fallback.
 *
 * Ogni interrogazione passa da `{$this->v['v_cm_it_servizio']}`, che espone una riga per
 * intervento con tutte le sue dimensioni. Le aggregazioni sono costruite qui
 * perche' le combinazioni richieste — piu' linee, piu' settori, piu' modalita'
 * insieme — non si esprimono in una vista fissa.
 */

declare(strict_types=1);

final class ItServiceModel
{
    private PDO $pdo;

    /** Dimensioni ammesse per il raggruppamento: elenco chiuso, non input libero. */
    public const DIM = [
        'incaricato'        => 'Incaricato',
        'settore'           => 'Settore tecnologico',
        // v1.8.93 — azienda esecutrice, derivata dal prefisso del codice
        // commessa. Il dato era gia' risolto in `exec_company_id`: mancava solo
        // il join.
        'azienda'           => 'Azienda esecutrice',
        // v1.8.92 — il CODICE della linea, distinto dall'etichetta.
        //
        // "WTS-ACM" e "Chiavi in mano" sono la stessa cosa detta in due modi, ma
        // servono a compiti diversi: il codice e' quello che compare sui
        // documenti e nel gestionale, l'etichetta e' leggibile da chi non lo
        // conosce a memoria. Chi confronta con un tabulato del gestionale cerca
        // il codice; chi legge un report cerca l'etichetta.
        'linea_servizio'    => 'Codice linea',
        'linea_label'       => 'Linea di servizio',
        'modello_contratto' => 'Modello di contratto',
        'modalita'          => 'Modalità',
        'fascia_oraria'     => 'Fascia oraria',
        'durata'            => 'Durata',
        'sede_riferimento'  => 'Sede di riferimento',
        'cliente'           => 'Cliente',
        'anno_mese'         => 'Mese',
    ];

    /**
     * v1.9.87 — Stato commessa (cm_projects.operational_status). «Chiusa» e «Non chiusa» sono
     * complementari e coincidono con `commessa_attiva` di v_cm_it_giorni_base:
     * chiuse = Chiusa / Annullata / Persa.
     */
    public const STATI = ['aperta' => 'Aperta', 'chiusa' => 'Chiusa', 'sospesa' => 'Sospesa', 'non_chiusa' => 'Non chiusa'];
    private const STATI_CHIUSI = "('CHIUSA','ANNULLATA','PERSA')";

    /** v1.9.73 — nome da interrogare per ciascuna vista: copia aggiornata se lenta, altrimenti la vista. */
    private array $v = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        require_once __DIR__ . '/PmSnapshot.php';
        require_once __DIR__ . '/PmContractFilter.php';
        require_once __DIR__ . '/PmUoFilter.php';   // v1.10.30
        $this->v = PmSnapshot::names($pdo, ['v_cm_it_distanze_mancanti', 'v_cm_it_giorni_base', 'v_cm_it_servizio', 'v_cm_sd_costi_valorizzati']);
    }

    public function normFilters(array $q): array
    {
        $d = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
        $arr = static function ($v): array {
            if (is_string($v)) $v = $v === '' ? [] : explode(',', $v);
            return array_values(array_filter(array_map('trim', (array)$v), fn($x) => $x !== ''));
        };

        $f = [
            'from'      => $d($q['from'] ?? ''),
            'to'        => $d($q['to'] ?? ''),
            // liste: la richiesta era di poter aggregare piu' elementi insieme,
            // quindi ogni dimensione accetta piu' valori
            'linee'     => $arr($q['linee']     ?? []),
            'codici'    => $arr($q['codici']    ?? []),
            'settori'   => $arr($q['settori']   ?? []),
            'aziende'   => $arr($q['aziende']   ?? []),
            'incaricati'=> $arr($q['incaricati']?? []),
            'modalita'  => $arr($q['modalita']  ?? []),
            'fasce'     => $arr($q['fasce']     ?? []),
            'durate'    => $arr($q['durate']    ?? []),
            'sedi'      => $arr($q['sedi']      ?? []),
            // v1.9.77 — filtro globale Codice Contratto / PM Project: codice
            // commessa (cm_projects.project_code) oppure 'dgb:<id_contract>'
            // v1.9.78 — filtro condiviso fra le pagine (PmContractFilter, persistente in sessione)
            'contratti' => PmContractFilter::fromRequest($q),
            // v1.9.87 — stato della commessa (multi-selezione, elenco chiuso)
            'stati'     => array_values(array_intersect(array_keys(self::STATI), $arr($q['stato_commessa'] ?? []))),
            'ricavo'    => in_array($q['ricavo'] ?? '', ['1', '0'], true) ? (string)$q['ricavo'] : '',
            // v1.9.8 — ricerca libera e cliente, come nel pannello di
            // Commesse/Progetti: senza, per isolare una commessa bisognava
            // conoscerne la linea di servizio
            'q'         => trim((string)($q['q'] ?? '')),
            'cliente'   => trim((string)($q['cliente'] ?? '')),
            // v1.10.25 — Relazione Tecnici: tipologia di contratto (modello della linea) e provenienza ticket del modulo
            'tipologie' => array_slice($arr($q['tipologie'] ?? []), 0, 50),
            'prov'      => array_values(array_intersect(array_keys(self::PROV), $arr($q['prov'] ?? []))),
            // v1.10.29 — descrizione tariffa del modulo («Fascia C (Ora)»: fascia oraria + unità di misura)
            'tariffe'   => array_slice($arr($q['tariffe'] ?? []), 0, 50),
            // v1.10.30 — Unità Organizzativa (Anagrafica tecnica) dell'incaricato
            'uo'        => PmUoFilter::fromRequest($q),
        ];

        // dimensioni di raggruppamento, validate contro l'elenco chiuso
        $gb = $arr($q['gb'] ?? []);
        $gb = array_values(array_intersect($gb, array_keys(self::DIM)));
        $f['gb'] = $gb ?: ['incaricato', 'linea_label'];

        if ($f['from'] === '' || $f['to'] === '') {
            try {
                $r = $this->pdo->query(
                    "SELECT DATE_FORMAT(MAX(`giorno`), '%Y-%m-01') a, LAST_DAY(MAX(`giorno`)) b
                       FROM `{$this->v['v_cm_it_servizio']}`")->fetch(PDO::FETCH_ASSOC);
                $f['from'] = $f['from'] ?: (string)($r['a'] ?? date('Y-m-01'));
                $f['to']   = $f['to']   ?: (string)($r['b'] ?? date('Y-m-t'));
            } catch (Throwable $e) {
                $f['from'] = $f['from'] ?: date('Y-m-01');
                $f['to']   = $f['to']   ?: date('Y-m-t');
            }
        }
        if ($f['from'] > $f['to']) { [$f['from'], $f['to']] = [$f['to'], $f['from']]; }
        return $f;
    }

    /** Clausola condivisa da tutte le letture, export compreso. */
    private function where(array $f): array
    {
        $w = ["s.`giorno` BETWEEN ? AND ?"];
        $a = [$f['from'], $f['to']];

        // ogni lista diventa un IN: piu' valori sulla stessa dimensione si
        // sommano (OR), dimensioni diverse si restringono (AND). E' il
        // comportamento che ci si aspetta da un pannello di filtri.
        foreach ([
            'linee'      => 's.`linea_label`',
            'codici'     => 's.`linea_servizio`',
            'settori'    => 's.`settore`',
            'aziende'    => 's.`azienda`',
            'incaricati' => 's.`incaricato`',
            'modalita'   => 's.`modalita`',
            'fasce'      => 's.`fascia_oraria`',
            'durate'     => 's.`durata`',
            'sedi'       => 's.`sede_riferimento`',
        ] as $k => $col) {
            if (!empty($f[$k])) {
                $w[] = "$col IN (" . implode(',', array_fill(0, count($f[$k]), '?')) . ")";
                foreach ($f[$k] as $v) $a[] = $v;
            }
        }
        // v1.10.11 — perimetri usati dal Consuntivo del Service SOC (non esposti nel pannello della Relazione IT):
        // dipendenti = componenti dell'Unità Organizzativa (id); tickets = moduli che riportano i ticket filtrati.
        if (!empty($f['dipendenti'])) {
            $ids = array_values(array_unique(array_map('intval', $f['dipendenti'])));
            $w[] = "s.`employee_id` IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
            foreach ($ids as $v) $a[] = $v;
        }
        if (isset($f['tickets']) && is_array($f['tickets'])) {
            if (!$f['tickets']) $w[] = '1 = 0';
            else {
                $w[] = "s.`report_id` IN (SELECT irt.`id` FROM `cm_intervention_reports` irt WHERE irt.`ticket` IN (" . implode(',', array_fill(0, count($f['tickets']), '?')) . "))";
                foreach ($f['tickets'] as $v) $a[] = (string)$v;
            }
        }
        if (($f['ricavo'] ?? '') !== '') { $w[] = "s.`ha_ricavo` = ?"; $a[] = (int)$f['ricavo']; }
        if ($c = $this->ctrCond('s.`commessa`', $f, $a)) $w[] = $c;
        if ($c = self::statoCond('pst.`project_code` = s.`commessa`', $f)) $w[] = $c;   // v1.9.87

        if ($f['q'] !== '') {
            $w[] = "(s.`commessa` LIKE ? OR s.`cliente` LIKE ? OR s.`modulo` LIKE ?)";
            $lk = '%' . $f['q'] . '%'; $a[] = $lk; $a[] = $lk; $a[] = $lk;
        }
        if ($f['cliente'] !== '') { $w[] = "s.`cliente` LIKE ?"; $a[] = '%' . $f['cliente'] . '%'; }
        // v1.10.25 — tipologia di contratto e provenienza ticket (Relazione Tecnici)
        if (!empty($f['tipologie'])) {
            $w[] = "COALESCE(NULLIF(s.`modello_contratto`,''),'da_classificare') IN (" . implode(',', array_fill(0, count($f['tipologie']), '?')) . ")";
            foreach ($f['tipologie'] as $v) $a[] = $v;
        }
        if (!empty($f['uo'])) $w[] = PmUoFilter::empSql($f['uo'], 's.`employee_id`');   // v1.10.30
        if (!empty($f['tariffe'])) {
            $w[] = $this->tariffaFiltro($f['tariffe'], $a);
        }
        if (!empty($f['prov']) && count($f['prov']) < count(self::PROV)) {
            $w[] = "(SELECT " . self::provSql('irp') . " FROM `cm_intervention_reports` irp WHERE irp.`id` = s.`report_id`) IN ('" . implode("','", $f['prov']) . "')";
        }

        return [implode(' AND ', $w), $a];
    }

    /* ── v1.9.77 — filtro globale Codice Contratto / PM Project ─────────────
     *
     * Un solo filtro, due chiavi fisiche: le viste dei rapportini espongono il
     * codice commessa (`commessa` = cm_projects.project_code), le sezioni DGB
     * l'id del contratto (`a.id_contract`). Il ponte e' cm_projects.dgb_contract_id,
     * in entrambe le direzioni: scegliere un PM Project filtra anche le attivita'
     * DGB del suo contratto, scegliere un contratto DGB filtra anche i rapportini
     * delle commesse collegate. Stessa condizione per ogni dataset della pagina.
     */
    public static function normContratti(array $v): array { return PmContractFilter::norm($v); }

    /** v1.9.78 — risoluzione unica per richiesta (codici, id DGB) in PmContractFilter. */
    private ?PmContractFilter $cf = null;
    private function cf(array $f): PmContractFilter
    {
        $v = $f['contratti'] ?? [];
        if ($this->cf === null || $this->cf->values() !== PmContractFilter::norm($v)) $this->cf = new PmContractFilter($this->pdo, $v);
        return $this->cf;
    }

    /** Condizione su una colonna "codice commessa". NULL se il filtro e' vuoto. */
    private function ctrCond(string $col, array $f, array &$a): ?string
    {
        $cf = $this->cf($f);
        return $cf->active() ? $cf->sql('code', $col, $a) : null;
    }

    /** Condizione su una colonna "id contratto DGB". NULL se il filtro e' vuoto. */
    private function ctrCondDgb(string $col, array $f, array &$a): ?string
    {
        $cf = $this->cf($f);
        return $cf->active() ? $cf->sql('id', $col, $a) : null;
    }

    /* ── v1.9.87 — Stato commessa e perimetro unico ─────────────────────────── */

    /**
     * Condizione sullo stato della commessa; $join lega `cm_projects pst` alla riga
     * (codice commessa o id contratto DGB). NULL se il filtro e' vuoto.
     * Valori da elenco chiuso: nessun parametro.
     */
    private static function statoCond(string $join, array $f): ?string
    {
        if (empty($f['stati'])) return null;
        $st = "UPPER(TRIM(COALESCE(pst.`operational_status`,'')))";
        $or = [];
        foreach ($f['stati'] as $k) {
            if ($k === 'aperta')     $or[] = "$st = 'APERTA'";
            if ($k === 'sospesa')    $or[] = "$st = 'SOSPESA'";
            if ($k === 'chiusa')     $or[] = "$st IN " . self::STATI_CHIUSI;
            if ($k === 'non_chiusa') $or[] = "$st NOT IN " . self::STATI_CHIUSI;
        }
        return $or ? "EXISTS (SELECT 1 FROM `cm_projects` pst WHERE $join AND (" . implode(' OR ', $or) . "))" : null;
    }

    /** Filtri attivi oltre al periodo (qualunque dimensione). */
    private static function haFiltri(array $f): bool
    {
        foreach (['linee','codici','settori','aziende','incaricati','modalita','fasce','durate','sedi','contratti','stati','dipendenti','tipologie','prov','tariffe','uo'] as $k)
            if (!empty($f[$k])) return true;
        if (isset($f['tickets'])) return true;                                       // v1.10.11
        return ($f['ricavo'] ?? '') !== '' || ($f['q'] ?? '') !== '' || ($f['cliente'] ?? '') !== '';
    }

    private array $perim = [];

    /**
     * v1.9.88 — data di riferimento delle attività DGB: data del modulo di intervento, in mancanza
     * l'inizio dell'attività. Mai la data di chiusura o completamento (perimetro = eseguito nel periodo).
     */
    private const DGB_DATA = "COALESCE(a.report_date, DATE(a.date_start))";

    /**
     * Insieme dei rapportini (report_id) che soddisfano TUTTI i filtri della pagina, con la
     * stessa clausola di KPI, grafici e tabelle (where()). Le sezioni costruite su altre viste
     * (costi, giorni, DGB) lo usano come perimetro: un'unica definizione, nessuna divergenza.
     *
     * Calcolato una volta per richiesta in una tabella temporanea; se non e' possibile crearla
     * si ripiega su una sottoquery con i suoi parametri (aggiunti ad $a).
     * $conPeriodo=false: senza il vincolo di data (per le sezioni DGB, che datano l'attivita').
     *
     * @return string espressione da usare come `col IN (<espressione>)`
     */
    private function perimetro(array $f, bool $conPeriodo, array &$a): string
    {
        $fx = $f;
        if (!$conPeriodo) { $fx['from'] = '0001-01-01'; $fx['to'] = '9999-12-31'; }
        [$w, $wa] = $this->where($fx);
        $key = md5($w . '|' . json_encode($wa));
        if (!isset($this->perim[$key])) {
            $t = 'tmp_its_perim_' . substr($key, 0, 12);
            try {
                $this->pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS `$t` (`report_id` INT NOT NULL PRIMARY KEY) ENGINE=MEMORY");
                $this->pdo->exec("TRUNCATE TABLE `$t`");
                $st = $this->pdo->prepare("INSERT IGNORE INTO `$t` (`report_id`)
                                           SELECT DISTINCT s.`report_id` FROM `{$this->v['v_cm_it_servizio']}` s WHERE $w");
                $st->execute($wa);
                $this->perim[$key] = ['sql' => "SELECT `report_id` FROM `$t`", 'args' => []];
            } catch (Throwable $e) {
                $this->perim[$key] = ['sql' => "SELECT s.`report_id` FROM `{$this->v['v_cm_it_servizio']}` s WHERE $w", 'args' => $wa];
            }
        }
        foreach ($this->perim[$key]['args'] as $v) $a[] = $v;
        return $this->perim[$key]['sql'];
    }

    /** Opzioni del filtro: commesse presenti nella Relazione IT + contratti DGB senza PM Project. */
    public function valoriContratti(): array
    {
        return PmContractFilter::options($this->pdo,
            "SELECT DISTINCT `commessa` AS code FROM `{$this->v['v_cm_it_servizio']}`");
    }

    /** Descrizione leggibile dei filtri attivi (stampa, DOCX, XLSX). */
    public function descrizioneFiltri(array $f, array $ctrLabels = []): array
    {
        $out = [];
        if (!empty($f['contratti'])) $out[] = PmContractFilter::describe($f['contratti'], $ctrLabels);
        foreach ([['linee','Linee'],['codici','Codici linea'],['settori','Settori'],['aziende','Aziende'],
                  ['incaricati','Incaricati'],['sedi','Sedi'],['modalita','Modalità'],['fasce','Fasce'],
                  ['durate','Durate']] as [$k, $l]) {
            if (!empty($f[$k])) $out[] = $l . ': ' . implode(', ', array_map([self::class, 'etichetta'], $f[$k]));
        }
        if (!empty($f['stati'])) $out[] = 'Stato commessa: ' . implode(', ', array_map(fn($k) => self::STATI[$k] ?? $k, $f['stati']));
        if (($f['ricavo'] ?? '') !== '') $out[] = 'Natura: ' . ($f['ricavo'] === '1' ? 'a ricavo' : 'interne');
        if (($f['q'] ?? '') !== '')       $out[] = 'Ricerca: ' . $f['q'];
        if (($f['cliente'] ?? '') !== '') $out[] = 'Cliente: ' . $f['cliente'];
        if (!empty($f['tipologie'])) $out[] = 'Tipologia contratto: ' . implode(', ', array_map([self::class, 'tipologia'], $f['tipologie']));
        if (!empty($f['tariffe'])) $out[] = 'Descrizione tariffa: ' . implode(', ', $f['tariffe']);
        if (!empty($f['uo'])) $out[] = PmUoFilter::describe($f['uo'], PmUoFilter::options($this->pdo));
        if (!empty($f['prov'])) $out[] = 'Provenienza: ' . implode(', ', array_map(fn($k) => self::PROV[$k] ?? $k, $f['prov']));
        return $out;
    }

    /** Totali del periodo. */
    public function totali(array $f): array
    {
        [$w, $a] = $this->where($f);
        $cQ = $this->oreClassi();   // v1.9.75 — ripartizione per ore
        $st = $this->pdo->prepare(
            "SELECT COUNT(*)                            AS interventi,
                    COUNT(DISTINCT s.`giorno`)          AS giorni_distinti,
                    COUNT(DISTINCT CONCAT(s.`incaricato`,'|',s.`giorno`)) AS giornate_uomo,
                    COUNT(DISTINCT s.`incaricato`)      AS incaricati,
                    ROUND(SUM(s.`ore`), 2)              AS ore,
                    ROUND(SUM(s.`ore_extra`), 2)        AS ore_extra,
                    ROUND(SUM(s.`ore_viaggio`), 2)      AS ore_viaggio,
                    ROUND(SUM(COALESCE(s.`km_percorsi`,0)), 2) AS km,
                    SUM(s.`km_percorsi` IS NULL AND s.`modalita`='presso cliente') AS trasferte_senza_km,
                    SUM(s.`durata` = 'giornata')        AS giornate,
                    SUM(s.`durata` = 'mezza giornata')  AS mezze_giornate,
                    SUM({$cQ['fuori']} > 0)              AS fuori_orario,
                    ROUND(SUM({$cQ['fuori']}), 2)       AS ore_fuori_orario,
                    ROUND(SUM({$cQ['ord']}), 2)         AS ore_ordinarie,
                    ROUND(SUM({$cQ['rep']}), 2)         AS ore_reperibilita,
                    SUM({$cQ['repC']})                  AS reperibilita,
                    ROUND(SUM({$cQ['nc']}), 2)          AS ore_non_classificate,
                    ROUND(SUM(CASE WHEN s.`ha_ricavo`=1 THEN s.`ore` ELSE 0 END), 2) AS ore_ricavo,
                    COUNT(DISTINCT s.`linea_servizio`)  AS linee,
                    COUNT(DISTINCT s.`commessa`)        AS commesse,
                    COUNT(DISTINCT s.`cliente`)         AS clienti
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->irJoin()} WHERE $w");
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        // giornate-uomo: la coppia incaricato+giorno. Sommare i giorni distinti
        // di ciascuno darebbe un numero piu' alto del calendario.
        $r['ore_medie_giornata'] = ((int)($r['giornate_uomo'] ?? 0)) > 0
            ? round((float)$r['ore'] / (int)$r['giornate_uomo'], 2) : null;
        return $r;
    }

    /** Aggregazione secondo le dimensioni scelte. */
    public function aggrega(array $f, int $limite = 500): array
    {
        [$w, $a] = $this->where($f);
        $cQ = $this->oreClassi();   // v1.9.75 — ripartizione per ore
        $cols = [];
        foreach ($f['gb'] as $g) $cols[] = "s.`$g`";
        $sel = implode(', ', $cols);

        // v1.8.91 — quando si raggruppa per persona, l'ordine e' alfabetico per
        // COGNOME: un elenco di persone ordinato per volume costringe a cercare
        // il nome scorrendo tutta la tabella.
        // Sulle altre dimensioni resta l'ordine per ore, che e' quello utile.
        $ord = in_array('incaricato', $f['gb'], true)
            ? "ORDER BY MIN(s.`incaricato_ordina`), s.`incaricato`"
            : "ORDER BY ore DESC";

        $st = $this->pdo->prepare(
            "SELECT $sel,
                    COUNT(*)                            AS interventi,
                    COUNT(DISTINCT CONCAT(s.`incaricato`,'|',s.`giorno`)) AS giornate_uomo,
                    ROUND(SUM(s.`ore`), 2)              AS ore,
                    ROUND(SUM(s.`ore_extra`), 2)        AS ore_extra,
                    ROUND(SUM(s.`ore_viaggio`), 2)      AS ore_viaggio,
                    ROUND(SUM(COALESCE(s.`km_percorsi`,0)), 2) AS km,
                    SUM(s.`durata` = 'giornata')        AS giornate,
                    SUM(s.`durata` = 'mezza giornata')  AS mezze_giornate,
                    SUM(s.`modalita` = 'presso cliente') AS presso_cliente,
                    SUM(s.`modalita` = 'da remoto')     AS da_remoto,
                    SUM(s.`modalita` = 'smart working') AS smart_working,
                    SUM({$cQ['repC']})                  AS reperibilita,
                    ROUND(SUM({$cQ['ord']}), 2)         AS ore_ordinarie,
                    ROUND(SUM({$cQ['rep']}), 2)         AS ore_reperibilita,
                    SUM({$cQ['fuori']} > 0)              AS fuori_orario,
                    ROUND(SUM({$cQ['fuori']}), 2)       AS ore_fuori_orario,
                    ROUND(SUM({$cQ['nc']}), 2)          AS ore_non_classificate,
                    ROUND(SUM(CASE WHEN s.`ha_ricavo`=1 THEN s.`ore` ELSE 0 END), 2) AS ore_ricavo
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->irJoin()}
              WHERE $w GROUP BY $sel $ord LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** v1.10.11 — Incaricato (nome sul modulo) → dipendente, sullo stesso perimetro di KPI e tabelle. */
    public function incaricatiDipendenti(array $f): array
    {
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare("SELECT s.`incaricato`, MAX(s.`employee_id`) AS employee_id
                                     FROM `{$this->v['v_cm_it_servizio']}` s WHERE $w GROUP BY s.`incaricato`");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $st->closeCursor();
        return array_map('intval', $out);
    }

    /** Ripartizione su una singola dimensione, per i grafici. */
    public function perDimensione(array $f, string $dim, int $limite = 12): array
    {
        if (!isset(self::DIM[$dim])) return [];
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT s.`$dim` AS voce, COUNT(*) AS interventi,
                    ROUND(SUM(s.`ore`), 2) AS ore,
                    COUNT(DISTINCT CONCAT(s.`incaricato`,'|',s.`giorno`)) AS giornate_uomo
               FROM `{$this->v['v_cm_it_servizio']}` s WHERE $w
              GROUP BY s.`$dim` ORDER BY ore DESC LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Andamento mensile. */
    public function andamento(array $f): array
    {
        [$w, $a] = $this->where($f);
        $c = $this->oreClassi();   // v1.9.74 — stesse regole del grafico giornaliero
        $st = $this->pdo->prepare(
            "SELECT s.`anno_mese` AS ym, COUNT(*) AS interventi,
                    ROUND(SUM(s.`ore`), 2) AS ore,
                    ROUND(SUM(s.`ore_viaggio`), 2) AS ore_viaggio,
                    ROUND(SUM({$c['fuori']}), 2) AS ore_fuori,
                    ROUND(SUM({$c['rep']}), 2)   AS ore_reperibilita,
                    ROUND(SUM({$c['ord']}), 2)   AS ore_ordinarie,
                    COUNT(DISTINCT CONCAT(s.`incaricato`,'|',s.`giorno`)) AS giornate_uomo
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->irJoin()} WHERE $w
              GROUP BY s.`anno_mese` ORDER BY s.`anno_mese`");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.9.75 — Ripartizione delle ore di ogni riga fra ordinarie, fuori orario,
     * reperibilità e non classificate (quantità, non condizioni).
     *
     * Se la vista espone `modulo` (codice del rapportino), il rapportino viene agganciato
     * e le ore sono ripartite con la regola unica di app/PmOrario.php: sovrapposizione
     * reale con le fasce ordinarie (un turno 12:00–00:00 è 5 h ordinarie + 6 h fuori
     * orario, non tutto ordinario perché iniziato alle 12). Senza aggancio vale la fascia
     * del modulo intero (v1.9.74). Somma delle quattro quantità = ore della riga.
     */
    private function oreClassi(): array
    {
        require_once __DIR__ . '/PmOrario.php';
        $mod = "LOWER(TRIM(COALESCE(s.`modalita`,'')))";
        $fas = "LOWER(TRIM(COALESCE(s.`fascia_oraria`,'')))";
        $rep = "($mod LIKE 'reperibilit%')";
        $ore = "COALESCE(s.`ore`,0)";
        if ($this->haModulo()) {
            // v1.9.76 — la reperibilità è un flag del rapportino (on_call), indipendente dalla
            // modalità: un intervento può essere da remoto E in reperibilità (79 su 91 nei dati).
            // Con la sola `modalita` della vista questi interventi sparivano dai grafici.
            $rep = "($mod LIKE 'reperibilit%' OR COALESCE(ir.`on_call`,0) = 1)";
            $spl = PmOrario::ordinarieSql('ir.`start_at`', 'ir.`end_at`', $ore, $this->pdo);
            $ord = "(CASE WHEN $rep THEN 0 WHEN ir.`id` IS NOT NULL THEN $spl WHEN $fas = 'in orario' THEN $ore ELSE 0 END)";
            $fuo = "(CASE WHEN $rep THEN 0 WHEN ir.`id` IS NOT NULL THEN $ore - $spl WHEN $fas = 'fuori orario' THEN $ore ELSE 0 END)";
            $ncC = "(NOT $rep AND ir.`id` IS NULL AND $fas NOT IN ('in orario','fuori orario'))";
        } else {
            $ord = "(CASE WHEN NOT $rep AND $fas = 'in orario' THEN $ore ELSE 0 END)";
            $fuo = "(CASE WHEN NOT $rep AND $fas = 'fuori orario' THEN $ore ELSE 0 END)";
            $ncC = "(NOT $rep AND $fas NOT IN ('in orario','fuori orario'))";
        }
        return [
            'rep'    => "(CASE WHEN $rep THEN $ore ELSE 0 END)",
            'ord'    => $ord,
            'fuori'  => $fuo,
            'nc'     => "(CASE WHEN $ncC THEN $ore ELSE 0 END)",
            'nc_cond'=> $ncC,
            'repC'   => $rep,
        ];
    }

    /** La vista espone il codice del modulo? (per agganciare il rapportino) */
    private ?bool $haModulo = null;
    private function haModulo(): bool
    {
        if ($this->haModulo !== null) return $this->haModulo;
        try {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'modulo'");
            $st->execute([$this->v['v_cm_it_servizio']]);
            $this->haModulo = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { $this->haModulo = false; }
        return $this->haModulo;
    }

    /** JOIN al rapportino del modulo (uno solo: l'id minimo per codice). */
    private function irJoin(): string
    {
        return $this->haModulo()
            // v1.9.89 — il rapportino della riga (report_id), non il primo con lo stesso codice modulo:
            // con piu' tecnici sullo stesso modulo si leggevano orari e reperibilita' di un altro tecnico
            ? " LEFT JOIN `cm_intervention_reports` ir ON ir.`id` = s.`report_id` "
            : '';
    }

    /**
     * v1.9.73 — Andamento GIORNALIERO delle ore (ultimi $maxGiorni giorni del periodo),
     * con le stesse classi del grafico mensile: ordinarie, fuori orario, reperibilità.
     * @return array{from:string,to:string,rows:array}
     */
    public function andamentoGiornaliero(array $f, int $maxGiorni = 92): array
    {
        require_once __DIR__ . '/PmCharts.php';
        [$da, $a] = PmCharts::window((string)$f['from'], (string)$f['to'], $maxGiorni);
        $fw = $f; $fw['from'] = $da; $fw['to'] = $a;
        [$w, $args] = $this->where($fw);
        $c = $this->oreClassi();
        $st = $this->pdo->prepare(
            "SELECT s.`giorno` AS giorno, COUNT(*) AS interventi,
                    ROUND(SUM(s.`ore`), 2) AS ore,
                    ROUND(SUM({$c['ord']}), 2)   AS ore_ordinarie,
                    ROUND(SUM({$c['fuori']}), 2) AS ore_fuori,
                    ROUND(SUM({$c['rep']}), 2)   AS ore_reperibilita,
                    ROUND(SUM({$c['nc']}), 2)    AS ore_non_classificate,
                    COUNT(DISTINCT s.`incaricato`) AS persone
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->irJoin()} WHERE $w
              GROUP BY s.`giorno` ORDER BY s.`giorno`");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();

        // valori di fascia/modalità non riconosciuti: mostrati in pagina per individuare il dato anomalo
        $anom = [];
        if (array_sum(array_column($rows, 'ore_non_classificate')) > 0) {
            $sa = $this->pdo->prepare(
                "SELECT COALESCE(s.`fascia_oraria`, '(vuota)') AS fascia, COALESCE(s.`modalita`, '(vuota)') AS modalita,
                        COUNT(*) AS interventi, ROUND(SUM(s.`ore`), 2) AS ore
                   FROM `{$this->v['v_cm_it_servizio']}` s {$this->irJoin()} WHERE $w AND {$c['nc_cond']}
                  GROUP BY 1, 2 ORDER BY ore DESC LIMIT 5");
            $sa->execute($args);
            $anom = $sa->fetchAll(PDO::FETCH_ASSOC);
            $sa->closeCursor();
        }
        return ['from' => $da, 'to' => $a, 'rows' => $rows, 'non_classificate' => $anom];
    }

    // ── v1.9.76 — formattazione per tabelle, grafici ed export ──────────────
    /** Etichetta leggibile dei valori tecnici della vista (le altre voci restano invariate). */
    public static function etichetta($v): string
    {
        $v = (string)$v;
        $map = ['reperibilita' => 'Reperibilità', 'reperibilità' => 'Reperibilità', 'da remoto' => 'Da remoto',
                'presso cliente' => 'Presso cliente', 'smart working' => 'Smart working', 'in sede' => 'In sede',
                'in orario' => 'In orario', 'fuori orario' => 'Fuori orario', 'giornata' => 'Giornata',
                'mezza giornata' => 'Mezza giornata', 'non rilevata' => 'Non rilevata'];
        return $map[mb_strtolower(trim($v))] ?? $v;
    }

    /** Flag di reperibilità in qualunque forma (1/0, S/N, sì/no, testo) → true/false/null (sconosciuto). */
    public static function reperibilitaFlag($v): ?bool
    {
        if ($v === null) return null;
        $t = mb_strtolower(trim((string)$v));
        if ($t === '') return null;
        if (in_array($t, ['1', 's', 'si', 'sì', 'y', 'yes', 'true', 'x', 'reperibilita', 'reperibilità', 'rep'], true)) return true;
        if (in_array($t, ['0', 'n', 'no', 'false', '-', '—', 'ordinario'], true)) return false;
        return null;
    }

    /** Testo per export: «Sì» / «No» / valore originale se non riconosciuto. */
    public static function reperibilitaTesto($v): string
    {
        $f = self::reperibilitaFlag($v);
        return $f === true ? 'Sì' : ($f === false ? 'No' : self::etichetta((string)$v));
    }

    /** HTML per le tabelle: badge viola «Sì», trattino per «No». */
    public static function reperibilitaHtml($v): string
    {
        $f = self::reperibilitaFlag($v);
        if ($f === true)  return '<span style="background:#ede9fe;color:#6d28d9;border-radius:6px;padding:1px 7px;font-weight:600;font-size:11px">Sì</span>';
        if ($f === false) return '<span style="color:#94a3b8">—</span>';
        return htmlspecialchars(self::etichetta((string)$v), ENT_QUOTES, 'UTF-8');
    }

    /** Valori disponibili per i menu dei filtri. */
    public function valori(string $dim): array
    {
        if (!isset(self::DIM[$dim])) return [];
        try {
            return $this->pdo->query(
                $dim === 'incaricato'
                    ? "SELECT DISTINCT `incaricato` FROM `{$this->v['v_cm_it_servizio']}`
                        WHERE `incaricato` IS NOT NULL AND `incaricato` <> ''
                        ORDER BY `incaricato_ordina`, `incaricato`"
                    : "SELECT DISTINCT `$dim` FROM `{$this->v['v_cm_it_servizio']}`
                        WHERE `$dim` IS NOT NULL AND `$dim` <> '' ORDER BY `$dim`")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }

    /** Coppie sede-cliente prive di distanza, per la geocodifica. */
    public function distanzeMancanti(int $limite = 100): array
    {
        try {
            $st = $this->pdo->query(
                "SELECT * FROM `{$this->v['v_cm_it_distanze_mancanti']}`
                  ORDER BY `interventi` DESC LIMIT " . (int)$limite);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** Stato della copertura chilometrica. */
    public function statoKm(array $f): array
    {
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT SUM(s.`modalita` = 'presso cliente')                     AS trasferte,
                    SUM(s.`modalita` = 'presso cliente' AND s.`km_percorsi` IS NOT NULL) AS con_km,
                    ROUND(SUM(COALESCE(s.`km_percorsi`, 0)), 2)              AS km,
                    ROUND(SUM(CASE WHEN s.`modalita`='presso cliente'
                              THEN s.`ore_viaggio` ELSE 0 END), 2)           AS ore_viaggio
               FROM `{$this->v['v_cm_it_servizio']}` s WHERE $w");
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        $r['copertura_pct'] = ((int)($r['trasferte'] ?? 0)) > 0
            ? round(100 * (int)$r['con_km'] / (int)$r['trasferte'], 1) : null;
        return $r;
    }
    /**
     * v1.9.15 — Riepilogo costi per fascia e contratto.
     *
     * Le viste sono le stesse del Service Desk: una seconda definizione degli
     * scaglioni divergerebbe dalla prima, e le due sezioni darebbero valori
     * diversi sullo stesso intervento.
     *
     * Cambia il perimetro dei filtri, non il calcolo.
     */
    public function costiRiepilogo(array $f): array
    {
        return $this->costiQuery($f,
            "SELECT `codice_linea`, `contratto`, `fascia`, `scaglione`,
                    COALESCE(`descrizione_tariffa`,
                             CONCAT('Fascia ', `fascia`, ' (', `scaglione`, ')')) AS descrizione_tariffa,
                    `reperibilita`,
                    COUNT(*) AS interventi, ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(`valore`), 2) AS valore, MAX(`tariffa_ora`) AS tariffa_ora,
                    COUNT(DISTINCT `commessa`) AS commesse,
                    SUM(`tariffa_ora` IS NULL) AS righe_senza_tariffa",
            "GROUP BY `codice_linea`, `contratto`, `fascia`, `scaglione`,
                      descrizione_tariffa, `reperibilita`
              ORDER BY `codice_linea`, `fascia`,
                       FIELD(`scaglione`,'ora','mezza_giornata','giornata')");
    }

    /** Il quadro complessivo dei costi. */
    public function costiQuadro(array $f): array
    {
        $r = $this->costiQuery($f,
            "SELECT COUNT(*) AS interventi, ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(`valore`), 2) AS valore,
                    ROUND(SUM(CASE WHEN `fascia`='C' THEN `valore` ELSE 0 END), 2) AS valore_ordinario,
                    ROUND(SUM(CASE WHEN `fascia`='C' THEN `ore` ELSE 0 END), 2) AS ore_ordinario,
                    ROUND(SUM(CASE WHEN `fascia`='D' THEN `valore` ELSE 0 END), 2) AS valore_extra,
                    ROUND(SUM(CASE WHEN `fascia`='D' THEN `ore` ELSE 0 END), 2) AS ore_extra,
                    SUM(`reperibilita` = 'SI') AS interventi_reperibilita,
                    COUNT(DISTINCT `commessa`) AS commesse,
                    COUNT(DISTINCT `tecnico`) AS tecnici,
                    SUM(`tariffa_ora` IS NULL) AS righe_senza_tariffa",
            "");
        return $r[0] ?? [];
    }

    /**
     * Il corpo comune, con i filtri della Relazione IT.
     *
     * `incaricati` qui e' a selezione multipla, non un valore singolo come nel
     * Service Desk: il perimetro dei filtri e' quello della sezione.
     */
    private function costiQuery(array $f, string $select, string $coda): array
    {
        try {
            $w = "1=1"; $a = [];
            if (!empty($f['from']) && !empty($f['to'])) {
                $w = "`giorno` BETWEEN ? AND ?"; $a[] = $f['from']; $a[] = $f['to'];
            }
            // v1.9.87 — tutti i filtri della pagina, tramite il perimetro unico dei rapportini
            // (prima: solo incaricati, codici linea, cliente e contratto → costi disallineati
            // da KPI e grafici con linee, settori, modalità, fasce, durate, sedi, natura, ricerca)
            if (self::haFiltri($f)) $w .= " AND `report_id` IN (" . $this->perimetro($f, true, $a) . ")";
            $st = $this->pdo->prepare("$select FROM `{$this->v['v_cm_sd_costi_valorizzati']}` WHERE $w $coda");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * v1.9.19 — Giorni lavorati per operatore.
     *
     * Il filtro sul periodo e sugli incaricati e' quello della sezione. Il
     * perimetro delle linee e lo stato delle commesse sono gia' nella vista:
     * ripeterli qui darebbe due definizioni dello stesso perimetro.
     */
    public function giorniOperatore(array $f): array
    {
        return $this->giorniQuery($f,
            "SELECT `operatore`, `ordina`,
                    COUNT(DISTINCT `giorno`)                    AS giorni_lavorati,
                    COUNT(*)                                    AS interventi,
                    ROUND(SUM(`ore`), 2)                        AS ore,
                    ROUND(SUM(`ore`) / 8, 1)                    AS giornate_equiv,
                    CASE WHEN COUNT(DISTINCT `giorno`) > 0
                         THEN ROUND(SUM(`ore`) / COUNT(DISTINCT `giorno`), 1) END AS ore_per_giorno,
                    COUNT(DISTINCT CASE WHEN `fascia`='A' THEN `giorno` END) AS giorni_A,
                    COUNT(DISTINCT CASE WHEN `fascia`='B' THEN `giorno` END) AS giorni_B,
                    COUNT(DISTINCT CASE WHEN `fascia`='C' THEN `giorno` END) AS giorni_C,
                    COUNT(DISTINCT CASE WHEN `fascia`='D' THEN `giorno` END) AS giorni_D,
                    COUNT(DISTINCT CASE WHEN `fascia`='E' THEN `giorno` END) AS giorni_E,
                    COUNT(DISTINCT CASE WHEN `fascia`='X' THEN `giorno` END) AS giorni_X,
                    ROUND(SUM(CASE WHEN `fascia`='C' THEN `ore` ELSE 0 END), 2) AS ore_C,
                    ROUND(SUM(CASE WHEN `fascia`='D' THEN `ore` ELSE 0 END), 2) AS ore_D,
                    COUNT(DISTINCT `area_tecnologica`)          AS aree,
                    COUNT(DISTINCT `commessa`)                  AS commesse,
                    COUNT(DISTINCT `cliente`)                   AS clienti,
                    COUNT(DISTINCT `codice_linea`)              AS linee,
                    ROUND(SUM(CASE WHEN `valorizzata`=1 THEN `ore` ELSE 0 END), 2) AS ore_valorizzate,
                    ROUND(SUM(CASE WHEN `valorizzata`=0 THEN `ore` ELSE 0 END), 2) AS ore_non_valorizzate,
                    COUNT(DISTINCT CASE WHEN `valorizzata`=0 THEN `giorno` END)   AS giorni_non_valorizzati,
                    ROUND(SUM(`produzione_teorica`), 2)         AS produzione_teorica,
                    ROUND(SUM(`valore_addebitato`), 2)          AS valore_addebitato,
                    SUM(`produzione_teorica` IS NULL)           AS righe_senza_tariffa,
                    CASE WHEN COUNT(DISTINCT `giorno`) > 0
                         THEN ROUND(SUM(`produzione_teorica`) / COUNT(DISTINCT `giorno`), 2) END
                                                                AS produzione_per_giorno,
                    SUM(`fascia_origine` = 'attivita')          AS fascia_letta,
                    MIN(`giorno`) AS dal, MAX(`giorno`) AS al",
            "GROUP BY `operatore`, `ordina` ORDER BY `ordina`, `operatore`");
    }

    /** Il dettaglio per area tecnologica. */
    public function giorniArea(array $f): array
    {
        $righe = $this->giorniQuery($f,
            "SELECT `operatore`, `ordina`, `area_tecnologica`,
                    COUNT(DISTINCT `giorno`) AS giorni, COUNT(*) AS interventi,
                    ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(`produzione_teorica`), 2) AS produzione_teorica,
                    COUNT(DISTINCT `commessa`) AS commesse",
            "GROUP BY `operatore`, `ordina`, `area_tecnologica` ORDER BY `ordina`, ore DESC");

        // la quota si calcola in PHP sul totale dell'operatore: in SQL avrebbe
        // richiesto di ripetere tutte le condizioni del filtro in una sottoquery,
        // e ripeterle e' il modo in cui divergono
        $tot = [];
        foreach ($righe as $r) $tot[$r['operatore']] = ($tot[$r['operatore']] ?? 0) + (float)$r['ore'];
        foreach ($righe as &$r) {
            $t = $tot[$r['operatore']] ?? 0;
            $r['quota_ore_pct'] = $t > 0 ? round(100 * (float)$r['ore'] / $t, 1) : null;
        }
        return $righe;
    }

    /** Il quadro complessivo. */
    public function giorniQuadro(array $f): array
    {
        $r = $this->giorniQuery($f,
            "SELECT COUNT(DISTINCT `operatore`) AS operatori,
                    COUNT(DISTINCT `giorno`) AS giorni_calendario,
                    COUNT(DISTINCT CONCAT(`operatore`, '|', `giorno`)) AS giorni_uomo,
                    COUNT(*) AS interventi,
                    ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(`ore`) / 8, 1) AS giornate_equiv,
                    COUNT(DISTINCT `area_tecnologica`) AS aree,
                    COUNT(DISTINCT `commessa`) AS commesse,
                    COUNT(DISTINCT `codice_linea`) AS linee,
                    ROUND(SUM(CASE WHEN `valorizzata`=1 THEN `ore` ELSE 0 END), 2) AS ore_valorizzate,
                    ROUND(SUM(CASE WHEN `valorizzata`=0 THEN `ore` ELSE 0 END), 2) AS ore_non_valorizzate,
                    SUM(`valorizzata`=0) AS interventi_non_valorizzati,
                    COUNT(DISTINCT CASE WHEN `valorizzata`=0 THEN CONCAT(`operatore`,'|',`giorno`) END)
                                                        AS giorni_uomo_non_valorizzati,
                    ROUND(SUM(`produzione_teorica`), 2) AS produzione_teorica,
                    ROUND(SUM(`valore_addebitato`), 2) AS valore_addebitato,
                    SUM(`produzione_teorica` IS NULL) AS righe_senza_tariffa,
                    ROUND(100 * SUM(`fascia_origine`='attivita') / NULLIF(COUNT(*),0), 1)
                                                        AS fascia_letta_pct,
                    COUNT(DISTINCT CASE WHEN `fascia`='C' THEN CONCAT(`operatore`,'|',`giorno`) END)
                                                        AS giorni_uomo_C,
                    COUNT(DISTINCT CASE WHEN `fascia`='D' THEN CONCAT(`operatore`,'|',`giorno`) END)
                                                        AS giorni_uomo_D",
            "");
        return $r[0] ?? [];
    }

    /**
     * v1.9.89 — Ore per classe e giorni per persona, con la STESSA regola di dettaglio, andamento e
     * KPI (oreClassi): ordinarie + fuori orario + reperibilità + non classificate = ore.
     * Chiave = nome dell'incaricato (= `operatore` della sezione giorni: stesso campo del rapportino).
     * @return array<string,array>
     */
    public function classiPerPersona(array $f): array
    {
        [$w, $a] = $this->where($f);
        $c = $this->oreClassi();
        $st = $this->pdo->prepare(
            "SELECT s.`incaricato` AS persona,
                    ROUND(SUM({$c['ord']}), 2)   AS ore_ordinarie,
                    ROUND(SUM({$c['fuori']}), 2) AS ore_fuori_orario,
                    ROUND(SUM({$c['rep']}), 2)   AS ore_reperibilita,
                    ROUND(SUM({$c['nc']}), 2)    AS ore_non_classificate,
                    SUM({$c['repC']})            AS interventi_reperibilita,
                    COUNT(DISTINCT CASE WHEN {$c['repC']} THEN s.`giorno` END)        AS giorni_reperibilita,
                    COUNT(DISTINCT CASE WHEN {$c['fuori']} > 0 THEN s.`giorno` END)  AS giorni_fuori_orario,
                    COUNT(DISTINCT CASE WHEN {$c['ord']} > 0 THEN s.`giorno` END)    AS giorni_ordinari
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->irJoin()} WHERE $w
              GROUP BY s.`incaricato`");
        $st->execute($a);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['persona']] = $r;
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.9.89 — Dettaglio delle ore non valorizzate (moduli senza tariffa di listino), per codice linea,
     * commessa e persona, con il motivo: commessa senza listino oppure combinazione fascia/unità non prevista.
     */
    public function nonValorizzate(array $f, int $limite = 2000): array
    {
        return $this->giorniQuery($f,
            "SELECT `codice_linea`, `contratto`, `commessa`, `cliente`, `operatore`, `ordina`,
                    CASE WHEN EXISTS (SELECT 1 FROM `cm_contract_rates` crx
                                       WHERE crx.`project_code` = `commessa` AND crx.`rate_nature` = 'R'
                                         AND crx.`rate_value` > 0)
                         THEN 'Tariffa mancante per fascia/unità' ELSE 'Commessa senza listino' END AS motivo,
                    GROUP_CONCAT(DISTINCT CONCAT('Fascia ', `fascia`, ' · ',
                                 CASE `um` WHEN 'D' THEN 'giornata' WHEN 'HD' THEN 'mezza giornata' ELSE 'ora' END)
                                 ORDER BY `fascia`, `um` SEPARATOR ', ') AS combinazioni,
                    COUNT(*) AS interventi,
                    COUNT(DISTINCT `giorno`) AS giorni,
                    ROUND(SUM(`ore`), 2) AS ore,
                    MIN(`giorno`) AS dal, MAX(`giorno`) AS al",
            "AND `valorizzata` = 0
             GROUP BY `codice_linea`, `contratto`, `commessa`, `cliente`, `operatore`, `ordina`
             ORDER BY `codice_linea`, `commessa`, `ordina` LIMIT " . (int)$limite);
    }

    /** Dimensioni della ripartizione dei giorni lavorati (elenco chiuso). */
    public const GIORNI_DIM = [
        'codice_linea'     => 'Codice linea',
        'area_tecnologica' => 'Area tecnologica',
        'contratto'        => 'Linea di servizio',
        'cliente'          => 'Cliente',
        'commessa'         => 'Commessa',
        'anno_mese'        => 'Mese',
        'fascia'           => 'Fascia',
        'stato_commessa'   => 'Stato commessa',
    ];

    /**
     * v1.9.88 — Giorni lavorati ripartiti su una dimensione, ore valorizzate e non valorizzate
     * distinte. Stesso perimetro e stessi filtri del resto della sezione.
     */
    public function giorniPer(array $f, string $dim, int $limite = 1000): array
    {
        if (!isset(self::GIORNI_DIM[$dim])) return [];
        $col = $dim === 'stato_commessa' ? "COALESCE(NULLIF(`stato_commessa`,''),'(n.d.)')" : "`$dim`";
        $ord = $dim === 'anno_mese' ? 'voce' : 'ore DESC';
        // v1.9.92 — per commessa: cliente e descrizione come in «Commesse / Progetti» (export XLSX)
        $extra = $dim === 'commessa'
            ? "MAX(`cliente`) AS cliente,
                    (SELECT pd.`description` FROM `cm_projects` pd WHERE pd.`project_code` = `commessa` LIMIT 1) AS descrizione,"
            : '';
        return $this->giorniQuery($f,
            "SELECT $col AS voce, $extra
                    COUNT(DISTINCT `operatore`) AS persone,
                    COUNT(DISTINCT CONCAT(`operatore`,'|',`giorno`)) AS giorni_uomo,
                    COUNT(DISTINCT CASE WHEN `valorizzata`=0 THEN CONCAT(`operatore`,'|',`giorno`) END) AS giorni_uomo_non_val,
                    COUNT(*) AS interventi,
                    ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(CASE WHEN `valorizzata`=1 THEN `ore` ELSE 0 END), 2) AS ore_valorizzate,
                    ROUND(SUM(CASE WHEN `valorizzata`=0 THEN `ore` ELSE 0 END), 2) AS ore_non_valorizzate,
                    ROUND(SUM(`ore`) / 8, 1) AS giornate_equiv,
                    COUNT(DISTINCT `commessa`) AS commesse,
                    ROUND(SUM(`produzione_teorica`), 2) AS produzione_teorica",
            "GROUP BY voce ORDER BY $ord LIMIT " . (int)$limite);
    }

    /**
     * Il corpo comune: periodo (data del modulo) + perimetro unico dei filtri.
     * v1.9.88 — tutto l'eseguito: nessun vincolo sullo stato della commessa (salvo il filtro
     * «Stato commessa» scelto dall'utente, gia' nel perimetro) e nessuna linea esclusa.
     */
    private function giorniQuery(array $f, string $select, string $coda): array
    {
        try {
            $w = "1=1"; $a = [];
            if (!empty($f['from']) && !empty($f['to'])) {
                $w .= " AND `giorno` BETWEEN ? AND ?"; $a[] = $f['from']; $a[] = $f['to'];
            }
            if (self::haFiltri($f)) $w .= " AND `report_id` IN (" . $this->perimetro($f, true, $a) . ")";
            $st = $this->pdo->prepare("$select FROM `{$this->v['v_cm_it_giorni_base']}` WHERE $w $coda");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }


        
    /**
     * v1.9.90 — Sorgente unica delle sezioni «Riepilogo per Codice Contratto» e «Dettaglio per commessa».
     *
     * Prima (rsiWhere) le due sezioni leggevano le attività DGB con una propria selezione — data
     * dell'attività, incaricato per nome dell'operatore DGB, cliente dall'anagrafica DGB — e quindi non
     * recepivano gli stessi parametri del pannello: a settembre 2026 9.505 h su 197 contratti contro
     * 7.891,5 h su 189 commesse dei KPI; il filtro «Cliente» le azzerava; l'incaricato contava anche
     * attività di altri tecnici.
     *
     * Ora la riga è il MODULO DI INTERVENTO del perimetro unico (perimetro(): stessi filtri di KPI,
     * grafici, tabelle, costi e giorni), agganciato alla sua allocazione DGB (dgb_source_id =
     * dgb_forms_activity_operator.id) e all'attività/contratto. Ore e data sono quelle del modulo.
     * Le attività DGB senza modulo di intervento non entrano (contate a parte da attivitaSenzaModulo()).
     */
    private function rsiFrom(array $f, array &$b): string
    {
        $perim = $this->perimetro($f, true, $b);
        return "
          FROM `cm_intervention_reports` ir
          JOIN dgb_forms_activity a ON a.id = ir.`dgb_activity_id`
          LEFT JOIN dgb_forms_activity_operator ao ON ao.id = ir.`dgb_source_id`
          LEFT JOIN dgb_operator op ON op.id = COALESCE(ao.id_operator, a.id_operator)
          LEFT JOIN dgb_forms_contract c ON c.id = a.id_contract
          LEFT JOIN clients cli ON cli.id = COALESCE(c.id_customer_comp, a.id_customer_comp)
          LEFT JOIN (SELECT dgb_contract_id, MIN(project_code) AS project_code
                       FROM cm_projects GROUP BY dgb_contract_id) p
                 ON p.dgb_contract_id = a.id_contract
          LEFT JOIN cm_rate_bands rbb ON rbb.band_name = COALESCE(op.type,'Default')
          LEFT JOIN cm_rate_band_rates rb_ord ON rb_ord.band_id=rbb.id AND rb_ord.cost_type='Aziendale' AND rb_ord.regime='Ordinario'
          LEFT JOIN cm_rate_band_rates rb_rep ON rb_rep.band_id=rbb.id AND rb_rep.cost_type='Aziendale' AND rb_rep.regime='Reperibilità'
          WHERE ir.`id` IN ($perim) AND COALESCE(a.deleted,0) <> 1";
    }

    /** Espressioni comuni (riga = modulo di intervento). */
    private const RSI_ORE   = "ROUND(COALESCE(ir.`quantity_hours`, 0), 2)";
    private const RSI_REP   = "(COALESCE(ao.during_availability,0) = 1 OR COALESCE(ir.`on_call`,0) = 1)";
    private const RSI_EXTRA = "LEAST(COALESCE(ao.extra_hours, ir.`extra_hours`, 0), COALESCE(ir.`quantity_hours`, 0))";
    private const RSI_COST  = "COALESCE(ao.cost, a.human_resource_cost, a.total_cost, 0)";

    /* ── v1.9.91 — Attività DGB senza modulo di intervento ─────────────────────
     *
     * Restano fuori dal perimetro (non hanno un rapportino). Nella pagina hanno una sezione propria.
     * Filtri applicabili senza rapportino: periodo (data dell'attività), contratto/PM project,
     * stato commessa, incaricato (operatore DGB), cliente, linea e codice linea (commessa collegata),
     * ricerca libera. Le dimensioni esistenti solo sui rapportini (settore, modalità, fascia, durata,
     * sede, natura, azienda) non si applicano e sono dichiarate in pagina.
     */
    private const SM_MOTIVO = "CASE
            WHEN a.status IN ('assigned','new','planned','scheduled') THEN 'Assegnata, non ancora rendicontata'
            WHEN a.status = 'in_progress' THEN 'In corso, non ancora rendicontata'
            WHEN a.status LIKE 'frozen%' THEN 'Congelata / sospesa'
            WHEN a.status IN ('completed','closed','approved') THEN 'Eseguita ma senza modulo (da sincronizzare)'
            WHEN a.status = 'aborted' THEN 'Annullata'
            ELSE CONCAT('Altro stato: ', COALESCE(a.status,'(vuoto)')) END";

    private function smFrom(array $f, array &$b): string
    {
        $w = ['COALESCE(a.deleted,0) <> 1',
              'NOT EXISTS (SELECT 1 FROM `cm_intervention_reports` x WHERE x.`dgb_activity_id` = a.id)',
              self::DGB_DATA . ' BETWEEN ? AND ?'];
        $b[] = $f['from']; $b[] = $f['to'];
        if ($c = $this->ctrCondDgb('a.id_contract', $f, $b)) $w[] = $c;
        if ($c = self::statoCond('pst.`dgb_contract_id` = a.id_contract', $f)) $w[] = $c;
        if (!empty($f['incaricati'])) {
            $ph = implode(',', array_fill(0, count($f['incaricati']), '?'));
            $w[] = "(TRIM(CONCAT_WS(' ', op.first_name, op.second_name)) IN ($ph) OR TRIM(CONCAT_WS(' ', op.second_name, op.first_name)) IN ($ph))";
            foreach ($f['incaricati'] as $v) $b[] = $v;
            foreach ($f['incaricati'] as $v) $b[] = $v;
        }
        if (($f['cliente'] ?? '') !== '') { $w[] = "COALESCE(cli.name, p.client_raw) LIKE ?"; $b[] = '%' . $f['cliente'] . '%'; }
        if (!empty($f['codici'])) {
            $w[] = "p.service_line IN (" . implode(',', array_fill(0, count($f['codici']), '?')) . ")";
            foreach ($f['codici'] as $v) $b[] = $v;
        }
        if (!empty($f['linee'])) {
            $w[] = "COALESCE(cm.label, p.service_line) IN (" . implode(',', array_fill(0, count($f['linee']), '?')) . ")";
            foreach ($f['linee'] as $v) $b[] = $v;
        }
        if (($f['q'] ?? '') !== '') {
            $w[] = "(a.code LIKE ? OR c.code LIKE ? OR p.project_code LIKE ? OR cli.name LIKE ? OR a.ticket LIKE ?)";
            $lk = '%' . $f['q'] . '%'; array_push($b, $lk, $lk, $lk, $lk, $lk);
        }
        return "
          FROM dgb_forms_activity a
          LEFT JOIN dgb_forms_activity_operator ao ON ao.id_activity = a.id AND ao.id_operator = a.id_operator
          LEFT JOIN dgb_operator op ON op.id = a.id_operator
          LEFT JOIN dgb_forms_contract c ON c.id = a.id_contract
          LEFT JOIN (SELECT dgb_contract_id, MIN(id) AS id FROM cm_projects GROUP BY dgb_contract_id) px ON px.dgb_contract_id = a.id_contract
          LEFT JOIN cm_projects p ON p.id = px.id
          LEFT JOIN cm_contract_models cm ON cm.service_line = p.service_line
          LEFT JOIN clients cli ON cli.id = COALESCE(c.id_customer_comp, a.id_customer_comp)
          WHERE " . implode(' AND ', $w);
    }

    /** Filtri della pagina che non si applicano alle attività senza modulo. */
    public static function filtriNonApplicabiliSenzaModulo(array $f): array
    {
        $out = [];
        foreach (['settori' => 'settore', 'aziende' => 'azienda', 'modalita' => 'modalità', 'fasce' => 'fascia',
                  'durate' => 'durata', 'sedi' => 'sede'] as $k => $l) if (!empty($f[$k])) $out[] = $l;
        if (($f['ricavo'] ?? '') !== '') $out[] = 'natura';
        return $out;
    }

    /** v1.9.91 — Totali per motivo. */
    public function attivitaSenzaModulo(array $f): array
    {
        $b = [];
        try {
            $st = $this->pdo->prepare(
                "SELECT " . self::SM_MOTIVO . " AS motivo, COUNT(*) AS attivita,
                        ROUND(SUM(COALESCE(ao.hours, a.human_resource_hours, a.planned_hours, 0)), 2) AS ore,
                        COUNT(DISTINCT a.id_contract) AS contratti, COUNT(DISTINCT a.id_operator) AS operatori
                 " . $this->smFrom($f, $b) . " GROUP BY motivo ORDER BY attivita DESC");
            $st->execute($b);
            $per = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $per = []; }
        return ['attivita' => array_sum(array_column($per, 'attivita')),
                'ore'      => round(array_sum(array_map(fn($r) => (float)$r['ore'], $per)), 2),
                'motivi'   => $per];
    }

    /** v1.9.91 — Dettaglio per contratto × operatore × motivo (con $perAttivita: una riga per attività, per l'export). */
    public function attivitaSenzaModuloDettaglio(array $f, bool $perAttivita = false, int $limite = 1000): array
    {
        $b = [];
        $contr = "COALESCE(NULLIF(c.code,''), p.project_code, CONCAT('Contratto #', a.id_contract))";
        $oper  = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', op.second_name, op.first_name)),''), CONCAT('Operatore #', a.id_operator))";
        $ore   = "COALESCE(ao.hours, a.human_resource_hours, a.planned_hours, 0)";
        $sql = $perAttivita
            ? "SELECT a.id AS attivita_id, a.code AS codice, a.ticket, a.status AS stato, " . self::SM_MOTIVO . " AS motivo,
                      " . self::DGB_DATA . " AS data, $contr AS contratto, p.project_code AS pm_project, p.service_line AS codice_linea,
                      COALESCE(cli.name, p.client_raw) AS cliente, $oper AS operatore,
                      ROUND($ore, 2) AS ore, (ao.id IS NOT NULL) AS allocata, a.date_dead_line AS scadenza
               " . $this->smFrom($f, $b) . " ORDER BY data, contratto LIMIT " . (int)$limite
            : "SELECT a.id_contract AS contract_id, MAX($contr) AS contratto, MAX(p.project_code) AS pm_project,
                      MAX(p.service_line) AS codice_linea, MAX(COALESCE(cli.name, p.client_raw)) AS cliente,
                      $oper AS operatore, " . self::SM_MOTIVO . " AS motivo,
                      COUNT(*) AS attivita, ROUND(SUM($ore), 2) AS ore,
                      MIN(" . self::DGB_DATA . ") AS dal, MAX(" . self::DGB_DATA . ") AS al
               " . $this->smFrom($f, $b) . "
               GROUP BY a.id_contract, operatore, motivo
               ORDER BY motivo, contratto, operatore LIMIT " . (int)$limite;
        try { $st = $this->pdo->prepare($sql); $st->execute($b); return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }

    /* [PM_V1_9_36_APPLIED] Dettaglio per Commessa (sorgente dgb_forms_activity diretta) */
    /**
     * v1.9.73 — Dettaglio per commessa. Con $contractId restituisce le sole righe di quel
     * contratto: la pagina le carica quando l'utente apre il contratto, invece di inviare
     * tutte le righe del periodo (su 8,5 mesi: 21.357 righe, 5,3 MB di HTML).
     * Senza $contractId: tutte le righe, come prima (stampa, DOCX, XLSX).
     */
    public function dettaglioCommessa(array $f, ?int $contractId = null): array
    {
        $b = [];
        $sql = $this->dettaglioSql($f, $b, $contractId)
             . " ORDER BY contract_code, contract_id, report_iso, activity_id";
        try { $st = $this->pdo->prepare($sql); $st->execute($b); return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }

    /**
     * v1.9.73 — Una riga per contratto: intestazione, numero di righe e totali.
     * È ciò che la pagina mostra all'apertura; il dettaglio arriva su richiesta.
     */
    public function dettaglioCommessaSintesi(array $f): array
    {
        $b = [];
        $sql = "SELECT x.contract_id, MAX(x.contract_code) AS contract_code, MAX(x.code_x_installation) AS code_x_installation,
                       MAX(x.customer_name) AS customer_name, MAX(x.contract_description) AS contract_description,
                       MAX(x.pm_project_code) AS pm_project_code, COUNT(*) AS righe,
                       ROUND(SUM(x.ore), 2) AS ore, ROUND(SUM(x.costo_contratto), 2) AS costo_contratto,
                       ROUND(SUM(x.tot_costo_tab), 2) AS tot_costo_tab
                  FROM (" . $this->dettaglioSql($f, $b, null) . ") x
                 GROUP BY x.contract_id
                 ORDER BY contract_code, x.contract_id";
        try { $st = $this->pdo->prepare($sql); $st->execute($b); return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }

    /** Query del dettaglio per commessa, senza ORDER BY (riusata da dettaglio e sintesi). v1.9.90: perimetro unico. */
    private function dettaglioSql(array $f, array &$b, ?int $contractId): string
    {
        $from = $this->rsiFrom($f, $b);
        if ($contractId !== null) { $from .= ' AND a.id_contract = ?'; $b[] = $contractId; }
        $ORE = self::RSI_ORE; $REP = self::RSI_REP; $EXT = self::RSI_EXTRA; $COST = self::RSI_COST;
        return "
          SELECT a.id_contract AS contract_id,
                 COALESCE(NULLIF(c.code,''), p.project_code, CONCAT('Contratto #', a.id_contract)) AS contract_code,
                 c.code_x_installation,
                 cli.name AS customer_name, c.description AS contract_description,
                 p.project_code AS pm_project_code,
                 DATE_FORMAT(ir.`report_date`, '%d/%m/%Y') AS report_date, ir.`report_date` AS report_iso,
                 a.id AS activity_id,
                 a.ticket,
                 COALESCE(NULLIF(TRIM(CONCAT_WS(' ', op.second_name, op.first_name)), ''), ir.`technician_raw`) AS operator_name,
                 COALESCE(rbb.band_name, op.type, 'Default') AS fascia,
                 CASE WHEN $REP THEN 'Reperibilità'
                      WHEN $EXT >= $ORE AND $ORE > 0 THEN 'Straordinario'
                      WHEN $EXT > 0 THEN CONCAT('Ordinario + straordinario (', REPLACE(FORMAT($EXT,1),'.',','), ' h)')
                      ELSE 'Ordinario' END AS regime,
                 $ORE AS ore,
                 ROUND($COST,2) AS costo_contratto,
                 ROUND(CASE WHEN $REP THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0)*$ORE
                            ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0)*$ORE END, 2) AS tot_costo_tab
          $from";
    }

    /* [PM_V1_9_36_APPLIED] Riepilogo aggregato per Codice Contratto — v1.9.90: perimetro unico */
    public function riepilogoContratto(array $f): array
    {
        $b = []; $from = $this->rsiFrom($f, $b);
        $ORE = self::RSI_ORE; $REP = self::RSI_REP; $EXT = self::RSI_EXTRA; $COST = self::RSI_COST;
        $sql = "
          SELECT a.id_contract AS contract_id,
                 COALESCE(
                   NULLIF(MAX(CONCAT_WS(' | ', NULLIF(c.code,''), NULLIF(c.code_x_installation,''),
                            NULLIF(cli.name,''), NULLIF(c.description,''))), ''),
                   MAX(p.project_code),
                   CONCAT('Contratto #', a.id_contract)
                 ) AS codice_contratto,
                 MAX(p.project_code) AS pm_project_code,
                 ROUND(SUM(CASE WHEN $REP THEN 0 ELSE $ORE - $EXT END),2) AS ore_ordinarie,
                 ROUND(SUM(CASE WHEN $REP THEN 0 ELSE $EXT END),2) AS ore_straordinario,
                 ROUND(SUM(CASE WHEN $REP THEN $ORE ELSE 0 END),2) AS ore_reperibilita,
                 ROUND(SUM($ORE),2) AS ore,
                 COUNT(*) AS moduli,
                 COUNT(DISTINCT CONCAT(ir.`report_date`,'#',ir.`technician_raw`)) AS giorni_uomo,
                 ROUND(SUM($COST),2) AS costo_contratto,
                 ROUND(SUM(CASE WHEN $REP THEN COALESCE(rb_rep.rate_hour, op.hourly_cost,0)*$ORE
                                ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost,0)*$ORE END),2) AS tot_costo_tab
          $from
          GROUP BY a.id_contract
          ORDER BY SUM($ORE) DESC
        ";
        try { $st=$this->pdo->prepare($sql); $st->execute($b); return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }

    /* ══ v1.10.25 — Relazione Tecnici ═══════════════════════════════════════════════════════════
     * Stesso perimetro e stessi filtri della Relazione di Servizio IT (where(), perimetro()):
     * riga = modulo di intervento della vista v_cm_it_servizio, agganciato al rapportino per id.
     */

    /** Tipologie di contratto = modello della linea di servizio (cm_contract_models.model). */
    public const TIPOLOGIE = ['presidio' => 'Presidio', 'a_scalare' => 'A scalare (monte ore)', 'chiavi_mano' => 'Chiavi in mano',
        'a_chiamata' => 'Su chiamata', 'canone' => 'A canone', 'assistenza' => 'Assistenza e manutenzione', 'interno' => 'Interno (non a ricavo)',
        'da_classificare' => 'Da classificare'];

    /**
     * Provenienza del modulo dal campo ticket del rapportino:
     *   ticket   = riporta un codice ticket (es. WTS_000000070, WES_000000347);
     *   testo    = campo ticket compilato con un riferimento libero (es. «Presidio», «Monitoraggio giornaliero»);
     *   commessa = nessun ticket: modulo generato dalla commessa (pianificazione / attività di commessa).
     */
    public const PROV = ['ticket' => 'Ticket (codice)', 'testo' => 'Riferimento libero', 'commessa' => 'Da commessa'];
    public const TICKET_RE = '[A-Za-z]{2,4}_[0-9]{6,}';

    public static function provSql(string $al = 'ir'): string
    {
        return "(CASE WHEN NULLIF(TRIM($al.`ticket`),'') IS NULL THEN 'commessa' WHEN $al.`ticket` REGEXP '" . self::TICKET_RE . "' THEN 'ticket' ELSE 'testo' END)";
    }

    public static function tipologia($v): string
    {
        $v = (string)$v;
        return self::TIPOLOGIE[$v] ?? ($v === '' ? self::TIPOLOGIE['da_classificare'] : $v);
    }

    /** Valori presenti del filtro tipologia (chiave => etichetta). */
    public function valoriTipologie(): array
    {
        $out = [];
        try {
            foreach ($this->pdo->query("SELECT DISTINCT COALESCE(NULLIF(`modello_contratto`,''),'da_classificare') FROM `{$this->v['v_cm_it_servizio']}`")->fetchAll(PDO::FETCH_COLUMN) as $k)
                $out[(string)$k] = self::tipologia($k);
        } catch (Throwable $e) {}
        uksort($out, fn($a, $b) => (array_search($a, array_keys(self::TIPOLOGIE)) === false ? 99 : array_search($a, array_keys(self::TIPOLOGIE)))
                                 <=> (array_search($b, array_keys(self::TIPOLOGIE)) === false ? 99 : array_search($b, array_keys(self::TIPOLOGIE))));
        return $out;
    }

    /**
     * v1.10.32 — Festivi nazionali italiani degli anni [y1, y2] (1/1, 6/1, Pasquetta, 25/4, 1/5, 2/6, 15/8, 1/11, 8/12,
     * 25/12, 26/12) come chiavi 'Y-m-d'. Condiviso da giorniLavorabili() e prossimoLavorativo().
     */
    public static function festivi(int $y1, int $y2): array
    {
        static $cache = [];
        $fest = [];
        for ($y = $y1; $y <= $y2; $y++) {
            if (!isset($cache[$y])) {
                $c0 = [];
                foreach (['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'] as $md) $c0["$y-$md"] = 1;
                // Pasqua (algoritmo di Meeus/Jones/Butcher) + 1 giorno
                $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $dd = intdiv($b, 4); $ee = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
                $h = (19 * $a + $b - $dd - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $ee + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
                $mo = intdiv($h + $l - 7 * $m + 114, 31); $da = (($h + $l - 7 * $m + 114) % 31) + 1;
                $c0[date('Y-m-d', mktime(0, 0, 0, $mo, $da + 1, $y))] = 1;
                $cache[$y] = $c0;
            }
            $fest += $cache[$y];
        }
        return $fest;
    }

    /** v1.10.32 — Primo giorno lavorativo (lun–ven non festivo) successivo alla data indicata (Y-m-d). */
    public static function prossimoLavorativo(string $giorno): string
    {
        $d = new DateTime($giorno);
        for ($i = 0; $i < 15; $i++) {
            $d->modify('+1 day');
            $fest = self::festivi((int)$d->format('Y'), (int)$d->format('Y'));
            if ((int)$d->format('N') < 6 && !isset($fest[$d->format('Y-m-d')])) break;
        }
        return $d->format('Y-m-d');
    }

    /**
     * v1.10.32 — Controllo Reperibilità (Relazione Tecnici).
     *
     * 1. Interventi in reperibilità (v1.10.34): moduli del perimetro filtrato (where(): tutti i filtri globali di pagina) in
     *    modalità «reperibilita» — la stessa del filtro Modalità = Reperibilità (v_cm_it_servizio.modalita, origine DGB
     *    during_availability = on_call del modulo) — con inizio nella fascia 18:01–08:59. Il turno notturno appartiene al
     *    giorno in cui inizia: inizio 18:01–23:59 → turno del giorno stesso; inizio 00:00–08:59 → turno del giorno precedente.
     * 2. Giorno successivo: primo giorno lavorativo (lun–ven non festivo) dopo il giorno del turno.
     * 3. Correlazione: primo modulo NON in reperibilità (on_call = 0) dello STESSO tecnico (dipendente o professionista) in
     *    quel giorno, con inizio nella fascia 09:00–18:00 e non prima della fine dell'intervento in reperibilità, cercato fra
     *    tutti i suoi moduli (il controllo riguarda la persona, non il perimetro della commessa).
     * Una riga per intervento in reperibilità con attività ordinaria nel giorno successivo.
     */
    public const REP_NOTTE = ['18:01:00', '09:00:00'];   // TIME >= [0] OR TIME < [1]
    public const REP_GIORNO = ['09:00:00', '18:00:59'];  // TIME BETWEEN [0] AND [1]

    /** v1.10.34 — modulo in reperibilità: modalità «reperibilita», identica al filtro Modalità della pagina. */
    public const REP_MODALITA = 'reperibilita';

    public function controlloReperibilita(array $f, int $limite = 20000): array
    {
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare("SELECT s.`report_id`, s.`modulo`, s.`incaricato` AS tecnico, s.`commessa`, s.`cliente`, s.`linea_servizio` AS tipo,
                    s.`linea_label`, ir.`start_at`, ir.`end_at`, ir.`technician_id`, ir.`technician_professional_id`, ir.`project_id`
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->trJoin()}
              WHERE $w AND ir.`start_at` IS NOT NULL
                AND (TIME(ir.`start_at`) >= '" . self::REP_NOTTE[0] . "' OR TIME(ir.`start_at`) < '" . self::REP_NOTTE[1] . "')
                AND s.`modalita` = '" . self::REP_MODALITA . "'
              ORDER BY s.`incaricato`, ir.`start_at` LIMIT " . (int)$limite);
        $st->execute($a); $notte = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();

        $key = static fn(array $r): string => !empty($r['technician_id']) ? 'e' . (int)$r['technician_id'] : (!empty($r['technician_professional_id']) ? 'p' . (int)$r['technician_professional_id'] : '');
        $emp = []; $prof = []; $giorni = [];
        foreach ($notte as $i => $r) {
            $t = strtotime((string)$r['start_at']);
            $turno = date('H:i:s', $t) >= self::REP_NOTTE[0] ? date('Y-m-d', $t) : date('Y-m-d', $t - 86400);
            $notte[$i]['turno'] = $turno;
            $notte[$i]['giorno_succ'] = self::prossimoLavorativo($turno);
            $giorni[$notte[$i]['giorno_succ']] = 1;
            if (!empty($r['technician_id'])) $emp[(int)$r['technician_id']] = 1;
            elseif (!empty($r['technician_professional_id'])) $prof[(int)$r['technician_professional_id']] = 1;
        }
        $primo = [];
        if ($giorni && ($emp || $prof)) {
            $cond = [];
            if ($emp)  $cond[] = 'ir.`technician_id` IN (' . implode(',', array_map('intval', array_keys($emp))) . ')';
            if ($prof) $cond[] = '(ir.`technician_id` IS NULL AND ir.`technician_professional_id` IN (' . implode(',', array_map('intval', array_keys($prof))) . '))';
            $gg = array_keys($giorni); sort($gg);
            $ph = implode(',', array_fill(0, count($gg), '?'));
            $sd = $this->pdo->prepare("SELECT ir.`id`, ir.`report_code` AS modulo, ir.`start_at`, ir.`end_at`, ir.`technician_id`, ir.`technician_professional_id`,
                        COALESCE(p.`project_code`, ir.`project_code`) AS commessa, ir.`project_id`,
                        COALESCE(pc.`name`, ir.`client_raw`) AS cliente, COALESCE(p.`service_line`, '(nessuna)') AS tipo,
                        COALESCE(cmm.`label`, p.`service_line`) AS tipo_label   -- v1.10.34: stesse regole di v_cm_it_servizio (cliente, linea)
                   FROM `cm_intervention_reports` ir LEFT JOIN `cm_projects` p ON p.`id` = ir.`project_id`
                   LEFT JOIN `clients` pc ON pc.`id` = p.`client_id`
                   LEFT JOIN `cm_contract_models` cmm ON cmm.`service_line` = p.`service_line`
                  WHERE ir.`start_at` >= ? AND ir.`start_at` < ? AND DATE(ir.`start_at`) IN ($ph)
                    AND TIME(ir.`start_at`) BETWEEN '" . self::REP_GIORNO[0] . "' AND '" . self::REP_GIORNO[1] . "'
                    AND COALESCE(ir.`on_call`, 0) = 0   -- v1.10.34: attività ordinaria, non in reperibilità
                    AND (" . implode(' OR ', $cond) . ")
                  ORDER BY ir.`start_at`, ir.`id`");
            $sd->execute(array_merge([$gg[0] . ' 00:00:00', date('Y-m-d', strtotime(end($gg) . ' +1 day')) . ' 00:00:00'], $gg));
            foreach ($sd->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $primo[$key($r) . '|' . substr((string)$r['start_at'], 0, 10)][] = $r;   // in ordine di inizio
            }
            $sd->closeCursor();
        }
        $righe = []; $tec = [];
        foreach ($notte as $r) {
            $k = $key($r);
            if ($k === '' || !isset($primo[$k . '|' . $r['giorno_succ']])) continue;
            // primo modulo ordinario che inizia dopo la fine dell'intervento in reperibilità (es. turno 00:00–08:59 che
            // termina alle 10:00: il modulo delle 09:30 è la prosecuzione, non l'attività del giorno successivo)
            $fine = (string)($r['end_at'] ?: $r['start_at']); $g = null;
            foreach ($primo[$k . '|' . $r['giorno_succ']] as $c) if ((string)$c['start_at'] >= $fine && (int)$c['id'] !== (int)$r['report_id']) { $g = $c; break; }
            if ($g === null) continue;
            $righe[] = ['tecnico' => (string)$r['tecnico'], 'rep_inizio' => (string)$r['start_at'], 'rep_fine' => $r['end_at'], 'rep_modulo' => (string)$r['modulo'],
                        'turno' => $r['turno'], 'succ_inizio' => (string)$g['start_at'], 'succ_fine' => $g['end_at'], 'succ_modulo' => (string)$g['modulo'],
                        'succ_commessa' => (string)$g['commessa'], 'succ_project_id' => (int)$g['project_id'],
                        'succ_cliente' => (string)($g['cliente'] ?? ''), 'succ_tipo' => (string)($g['tipo'] ?? ''), 'succ_tipo_label' => (string)($g['tipo_label'] ?? ''),
                        'cliente' => (string)$r['cliente'], 'commessa' => (string)$r['commessa'], 'project_id' => (int)$r['project_id'],
                        'tipo' => (string)$r['tipo'], 'tipo_label' => (string)($r['linea_label'] ?? '')];
            $tec[$k] = 1;
        }
        return ['righe' => $righe, 'notturni' => count($notte), 'tecnici' => count($tec),
                'tecnici_notte' => count(array_unique(array_filter(array_map($key, $notte)))), 'troncato' => count($notte) >= $limite];
    }

    /**
     * Giorni lavorabili fra due date: lunedì–venerdì esclusi i festivi nazionali italiani
     * (1/1, 6/1, Pasquetta, 25/4, 1/5, 2/6, 15/8, 1/11, 8/12, 25/12, 26/12).
     */
    public static function giorniLavorabili(string $from, string $to): int
    {
        try { $d = new DateTime($from); $e = new DateTime($to); } catch (Throwable $x) { return 0; }
        if ($e < $d) return 0;
        $fest = self::festivi((int)$d->format('Y'), (int)$e->format('Y'));
        $n = 0; $guard = 0;
        while ($d <= $e && $guard++ < 40000) {
            if ((int)$d->format('N') < 6 && !isset($fest[$d->format('Y-m-d')])) $n++;
            $d->modify('+1 day');
        }
        return $n;
    }

    /**
     * v1.10.29 — Descrizione tariffa del modulo, stessa formula di v_cm_sd_costi_valorizzati.descrizione_tariffa
     * (Relazione IT, Service Desk): «Fascia <fascia> (<etichetta unità>)», es. «Fascia C (Ora)», «Fascia D (Giornata)».
     * Fascia e unità dal perimetro dei giorni (v_cm_it_giorni_base, per report_id). NULL se il modulo non vi compare.
     */
    /**
     * Condizione del filtro «Descrizione tariffa»: semi-join non correlato sul perimetro dei giorni
     * (una sola lettura della vista / copia, anche senza indice su report_id).
     */
    private function tariffaFiltro(array $vals, array &$a): string
    {
        foreach ($vals as $v) $a[] = $v;
        return "s.`report_id` IN (SELECT gbf.`report_id` FROM `{$this->v['v_cm_it_giorni_base']}` gbf
                                    LEFT JOIN `cm_um_tempi` tmf ON " . self::u('tmf.`um`') . " = " . self::u('gbf.`um`') . "
                                   WHERE " . self::tariffaExpr('gbf', 'tmf') . " IN (" . implode(',', array_fill(0, count($vals), '?')) . "))";
    }

    /**
     * JOIN della descrizione tariffa per modulo (alias tt.descr) limitato al periodo del filtro: tabella derivata
     * aggregata per report_id, una lettura della vista / copia filtrata per giorno (date già validate da normFilters).
     */
    private function tariffaJoin(array $f): string
    {
        $d = static fn($x) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$x) ? (string)$x : '1900-01-01';
        $from = $d($f['from'] ?? ''); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? '')) ? $f['to'] : '9999-12-31';
        return " LEFT JOIN (SELECT gbt.`report_id`, MIN(" . self::tariffaExpr('gbt', 'tmt') . ") AS descr
                              FROM `{$this->v['v_cm_it_giorni_base']}` gbt LEFT JOIN `cm_um_tempi` tmt ON " . self::u('tmt.`um`') . " = " . self::u('gbt.`um`') . "
                             WHERE gbt.`giorno` BETWEEN '$from' AND '$to' GROUP BY gbt.`report_id`) tt ON tt.`report_id` = s.`report_id` ";
    }

    /** Descrizione tariffa di un insieme di moduli (Scheda progetto › Consuntivo): [report_id => descrizione]. */
    public function tariffePerModuli(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        try {
            $st = $this->pdo->prepare("SELECT gb.`report_id`, MIN(" . self::tariffaExpr('gb', 'tm') . ")
                                         FROM `{$this->v['v_cm_it_giorni_base']}` gb LEFT JOIN `cm_um_tempi` tm ON " . self::u('tm.`um`') . " = " . self::u('gb.`um`') . "
                                        WHERE gb.`report_id` IN (" . implode(',', $ids) . ") GROUP BY gb.`report_id`");
            $st->execute();
            return array_map('strval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable $e) { return []; }
    }

    /** Collazione unica (copie aggiornate e viste possono avere collazioni diverse da cm_um_tempi). */
    private static function u(string $x): string { return "CONVERT($x USING utf8mb4) COLLATE utf8mb4_unicode_ci"; }

    /** «Fascia <fascia> (<etichetta unità>)» — stessa formula di v_cm_sd_costi_valorizzati.descrizione_tariffa. */
    public static function tariffaExpr(string $gb, string $tm): string
    {
        return "CONCAT('Fascia ', " . self::u("$gb.`fascia`") . ", ' (', COALESCE(" . self::u("$tm.`etichetta`") . ", " . self::u("$gb.`um`") . "), ')')";
    }

    /** Valori del filtro «Descrizione tariffa», ordinati per fascia e unità (ora, mezza giornata, giornata). */
    public function valoriTariffe(): array
    {
        try {
            return $this->pdo->query("SELECT " . self::tariffaExpr('gb', 'tm') . " AS d
                                        FROM `{$this->v['v_cm_it_giorni_base']}` gb LEFT JOIN `cm_um_tempi` tm ON " . self::u('tm.`um`') . " = " . self::u('gb.`um`') . "
                                       WHERE gb.`fascia` IS NOT NULL AND gb.`fascia` <> ''
                                       GROUP BY gb.`fascia`, gb.`um`, tm.`etichetta`, tm.`ordine`
                                       ORDER BY gb.`fascia`, COALESCE(tm.`ordine`, 99)")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }

    /** Join del rapportino (ticket) per le letture della Relazione Tecnici. */
    private function trJoin(): string
    {
        return " LEFT JOIN `cm_intervention_reports` ir ON ir.`id` = s.`report_id` LEFT JOIN `cm_rate_bands` rbx ON rbx.`id` = ir.`band_id` ";
    }

    /** Colonne aggregate comuni (attività, ticket, giorni, ore, classi, modalità, descrizione tariffa). */
    private function trSelect(): string
    {
        $c = $this->oreClassi();
        $tar = "tt.`descr`";             // v1.10.29 — al posto della fascia di costo (Junior/Senior/…): tariffaJoin()
        return "COUNT(DISTINCT s.`report_id`)                           AS attivita,
                COUNT(DISTINCT NULLIF(TRIM(ir.`ticket`),''))             AS ticket,
                COUNT(DISTINCT CONCAT(s.`incaricato`,'|',s.`giorno`))    AS giornate_uomo,
                ROUND(SUM(s.`ore`), 2)                                    AS ore,
                ROUND(SUM({$c['ord']}), 2)                                AS ore_ordinarie,
                ROUND(SUM({$c['fuori']}), 2)                              AS ore_fuori_orario,
                ROUND(SUM({$c['rep']}), 2)                                AS ore_reperibilita,
                ROUND(SUM({$c['nc']}), 2)                                 AS ore_non_classificate,
                ROUND(SUM(COALESCE(s.`ore_extra`,0)), 2)                  AS ore_extra,
                SUM(s.`modalita` = 'presso cliente')                      AS presso_cliente,
                SUM(s.`modalita` = 'da remoto')                           AS da_remoto,
                SUM(s.`modalita` = 'smart working')                       AS smart_working,
                GROUP_CONCAT(DISTINCT $tar ORDER BY $tar SEPARATOR ', ')  AS descrizione_tariffa";
    }

    /**
     * Righe tecnico × codice linea, con i totali per tecnico (giornate-uomo non sommabili fra linee:
     * lo stesso giorno può avere moduli su più linee) e il totale generale.
     * @return array{righe:array, tecnici:array<string,array>, totale:array, gg_lavorabili:int}
     */
    public function tecniciLinea(array $f, int $limite = 5000): array
    {
        [$w, $a] = $this->where($f);
        $sel = $this->trSelect();
        $from = "FROM `{$this->v['v_cm_it_servizio']}` s {$this->trJoin()} {$this->tariffaJoin($f)} WHERE $w";
        $st = $this->pdo->prepare("SELECT s.`incaricato` AS tecnico, MIN(s.`incaricato_ordina`) AS ordina,
                                          COALESCE(NULLIF(s.`linea_servizio`,''),'(n.d.)') AS codice_linea, MAX(s.`linea_label`) AS linea_label, $sel
                                     $from GROUP BY s.`incaricato`, codice_linea
                                    ORDER BY ordina, s.`incaricato`, ore DESC LIMIT " . (int)$limite);
        $st->execute($a); $righe = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
        $st = $this->pdo->prepare("SELECT s.`incaricato` AS tecnico, MAX(s.`employee_id`) AS employee_id, COUNT(DISTINCT s.`linea_servizio`) AS linee, $sel
                                     $from GROUP BY s.`incaricato`");
        $st->execute($a); $tec = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $tec[(string)$r['tecnico']] = $r; $st->closeCursor();
        $st = $this->pdo->prepare("SELECT COUNT(DISTINCT s.`incaricato`) AS tecnici, COUNT(DISTINCT s.`linea_servizio`) AS linee, $sel $from");
        $st->execute($a); $tot = $st->fetch(PDO::FETCH_ASSOC) ?: []; $st->closeCursor();
        return ['righe' => $righe, 'tecnici' => $tec, 'totale' => $tot, 'gg_lavorabili' => self::giorniLavorabili($f['from'], $f['to'])];
    }

    /**
     * Moduli di intervento valorizzati (con tariffa di listino) e non valorizzati, sullo stesso perimetro
     * (v_cm_it_giorni_base, come «Giorni per operatore»). $perTecnico: una riga per tecnico.
     */
    public function valorizzazione(array $f, bool $perTecnico = false): array
    {
        $vz = $this->valExpr();
        $sel = "COUNT(*) AS moduli, COUNT(DISTINCT `operatore`) AS tecnici, COUNT(DISTINCT `commessa`) AS commesse,
                COUNT(DISTINCT CONCAT(`operatore`,'|',`giorno`)) AS giornate_uomo, ROUND(SUM(`ore`), 2) AS ore,
                ROUND(SUM(`produzione_teorica`), 2) AS produzione_teorica, ROUND(SUM(`valore_addebitato`), 2) AS valore_addebitato";
        if ($perTecnico)
            return $this->giorniQuery($f, "SELECT `operatore` AS tecnico, MIN(`ordina`) AS ordina,
                    SUM($vz) AS moduli_val, ROUND(SUM(CASE WHEN $vz THEN `ore` ELSE 0 END), 2) AS ore_val,
                    SUM(NOT $vz) AS moduli_nv, ROUND(SUM(CASE WHEN $vz THEN 0 ELSE `ore` END), 2) AS ore_nv,
                    ROUND(SUM(`produzione_teorica`), 2) AS produzione_teorica",
                "GROUP BY `operatore` ORDER BY ordina, `operatore`");
        $out = [];
        foreach ($this->giorniQuery($f, "SELECT ($vz) AS v, $sel", "GROUP BY v") as $r) $out[(int)$r['v'] === 1 ? 'val' : 'nv'] = $r;
        $out['tot'] = $this->giorniQuery($f, "SELECT $sel", "")[0] ?? [];
        return $out;
    }

    /** Condizione «valorizzato»: colonna `valorizzata` della vista, altrimenti tariffa presente (viste precedenti). */
    private ?string $valExpr = null;
    private function valExpr(): string
    {
        if ($this->valExpr !== null) return $this->valExpr;
        try {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'valorizzata'");
            $st->execute([$this->v['v_cm_it_giorni_base']]);
            $has = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { $has = false; }
        return $this->valExpr = $has ? "(COALESCE(`valorizzata`,0) = 1)" : "(`produzione_teorica` IS NOT NULL)";
    }

    /** Rapporti di intervento per tipologia di contratto, con la provenienza. */
    public function rapportiTipologia(array $f): array
    {
        [$w, $a] = $this->where($f);
        $pv = self::provSql('ir');
        $st = $this->pdo->prepare("SELECT COALESCE(NULLIF(s.`modello_contratto`,''),'da_classificare') AS tipologia,
                    COUNT(DISTINCT s.`commessa`) AS commesse, COUNT(DISTINCT s.`report_id`) AS moduli,
                    COUNT(DISTINCT CASE WHEN $pv = 'ticket' THEN s.`report_id` END)   AS da_ticket,
                    COUNT(DISTINCT CASE WHEN $pv = 'testo' THEN s.`report_id` END)    AS da_testo,
                    COUNT(DISTINCT CASE WHEN $pv = 'commessa' THEN s.`report_id` END) AS da_commessa,
                    COUNT(DISTINCT NULLIF(TRIM(ir.`ticket`),'')) AS ticket, COUNT(DISTINCT s.`incaricato`) AS tecnici, ROUND(SUM(s.`ore`), 2) AS ore
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->trJoin()} WHERE $w GROUP BY tipologia ORDER BY moduli DESC");
        $st->execute($a); $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
        return $out;
    }

    /** Rapporti di intervento per commessa (drill-down: id della commessa per il link alla scheda). */
    public function rapportiCommessa(array $f, int $limite = 3000): array
    {
        [$w, $a] = $this->where($f);
        $pv = self::provSql('ir');
        $st = $this->pdo->prepare("SELECT s.`commessa`, MAX(s.`cliente`) AS cliente, MAX(s.`linea_servizio`) AS codice_linea, MAX(s.`linea_label`) AS linea_label,
                    COALESCE(NULLIF(MAX(s.`modello_contratto`),''),'da_classificare') AS tipologia,
                    (SELECT MIN(pp.`id`) FROM `cm_projects` pp WHERE pp.`project_code` = s.`commessa`) AS project_id,
                    (SELECT MIN(COALESCE(NULLIF(pp.`description`,''), pp.`name`)) FROM `cm_projects` pp WHERE pp.`project_code` = s.`commessa`) AS denominazione,
                    COUNT(DISTINCT s.`report_id`) AS moduli,
                    COUNT(DISTINCT CASE WHEN $pv = 'ticket' THEN s.`report_id` END)   AS da_ticket,
                    COUNT(DISTINCT CASE WHEN $pv = 'testo' THEN s.`report_id` END)    AS da_testo,
                    COUNT(DISTINCT CASE WHEN $pv = 'commessa' THEN s.`report_id` END) AS da_commessa,
                    COUNT(DISTINCT NULLIF(TRIM(ir.`ticket`),'')) AS ticket, COUNT(DISTINCT s.`incaricato`) AS tecnici,
                    ROUND(SUM(s.`ore`), 2) AS ore, MIN(s.`giorno`) AS dal, MAX(s.`giorno`) AS al
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->trJoin()} WHERE $w
              GROUP BY s.`commessa` ORDER BY moduli DESC, s.`commessa` LIMIT " . (int)$limite);
        $st->execute($a); $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
        return $out;
    }

    /** Moduli di intervento (dettaglio), facoltativamente di una sola commessa. */
    public function rapportiModuli(array $f, ?string $commessa = null, int $limite = 2000): array
    {
        [$w, $a] = $this->where($f);
        if ($commessa !== null) { $w .= " AND s.`commessa` = ?"; $a[] = $commessa; }
        $st = $this->pdo->prepare("SELECT s.`report_id`, s.`modulo`, s.`giorno`, s.`commessa`, s.`cliente`, s.`incaricato` AS tecnico,
                    s.`linea_servizio` AS codice_linea, s.`linea_label`, COALESCE(NULLIF(s.`modello_contratto`,''),'da_classificare') AS tipologia,
                    s.`modalita`, ROUND(s.`ore`, 2) AS ore, NULLIF(TRIM(ir.`ticket`),'') AS ticket, " . self::provSql('ir') . " AS provenienza,
                    ir.`dgb_activity_code` AS attivita_dgb
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->trJoin()} WHERE $w
              ORDER BY s.`commessa`, s.`giorno` DESC, s.`report_id` DESC LIMIT " . (int)$limite);
        $st->execute($a); $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
        return $out;
    }

    /** Numero di moduli del perimetro (per avvisare quando il dettaglio è troncato). */
    public function contaModuli(array $f, ?string $commessa = null): int
    {
        [$w, $a] = $this->where($f);
        if ($commessa !== null) { $w .= " AND s.`commessa` = ?"; $a[] = $commessa; }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->v['v_cm_it_servizio']}` s WHERE $w");
        $st->execute($a); $n = (int)$st->fetchColumn(); $st->closeCursor();
        return $n;
    }

    /* ── v1.10.35 — Relazione Tecnici › ServiceDesk: metriche aggregate dei contratti WTS-SD ──────────────────────
     *
     * Tre livelli separati:
     *   A. perimetro contratti  — cm_projects con service_line = 'WTS-SD' attivi nel periodo (durata ∩ [Da, A]) o con moduli
     *                             nel periodo; filtri globali di contratto (Codice contratto / PM Project, stato, cliente, ricerca);
     *   B. perimetro moduli     — v_cm_it_servizio con where($f) (TUTTI i filtri globali) AND linea_servizio = 'WTS-SD';
     *   C. filtro di esclusione — Unità Organizzative escluse (default «Service Desk»): un ticket è «gestito da altri team»
     *                             se almeno un suo modulo è di una risorsa NON appartenente alle unità escluse.
     * Ticket = codice ticket del modulo (provenienza «ticket», REGEXP TICKET_RE), contato una volta (COUNT DISTINCT).
     */
    public const SD_LINEA = 'WTS-SD';
    public const SD_UO    = 'Service Desk';

    /** Id dell'Unità Organizzativa «Service Desk» (esclusione predefinita); null se non definita. */
    public function sdUnitId(): ?int
    {
        try {
            $st = $this->pdo->prepare("SELECT id FROM cm_tech_units WHERE LOWER(TRIM(name)) = LOWER(?) ORDER BY id LIMIT 1");
            $st->execute([self::SD_UO]); $id = $st->fetchColumn(); $st->closeCursor();
            return $id !== false ? (int)$id : null;
        } catch (Throwable $e) { return null; }
    }

    /** Condizione «modulo di una risorsa delle unità escluse» (alias s = v_cm_it_servizio, ir = cm_intervention_reports). */
    private static function esclSql(array $escl): string
    {
        if (!$escl) return '0 = 1';
        return "(COALESCE(s.`employee_id`, 0) IN (" . PmUoFilter::empSub($escl) . ") OR COALESCE(ir.`technician_professional_id`, 0) IN (" . PmUoFilter::profSub($escl) . "))";
    }

    /** Espressioni SQL delle metriche (mostrate nella scheda come definizione: traduzione 1:1 in COUNT / SUM / AVG). */
    public static function sdDefinizioni(): array
    {
        $tk = "TRIM(ir.ticket)  /* solo provenienza 'ticket': ir.ticket REGEXP '" . self::TICKET_RE . "' */";
        return [
            ['Contratti WTS-SD', "COUNT(DISTINCT p.id)", "cm_projects p WHERE p.service_line = '" . self::SD_LINEA . "' AND p.start_date <= :a AND p.end_date >= :da (o con moduli nel periodo)"],
            ['Valore totale contratti', "SUM(p.value_total)", 'stesso perimetro'],
            ['Valore di competenza nel periodo', "SUM(p.value_total / (PERIOD_DIFF(YM(p.end_date), YM(p.start_date)) + 1) * GREATEST(0, PERIOD_DIFF(YM(LEAST(p.end_date, :a)), YM(GREATEST(p.start_date, :da))) + 1))", 'pro-rata mensile, come Report direzionale (ProRata)'],
            ['Media risorse per contratto', "AVG(n) FROM (SELECT s.commessa, COUNT(DISTINCT s.incaricato) n FROM v_cm_it_servizio s WHERE <filtri> AND s.linea_servizio = '" . self::SD_LINEA . "' GROUP BY s.commessa)", 'contratti con moduli nel periodo'],
            ['Ticket gestiti (totale)', "COUNT(DISTINCT $tk)", "moduli WTS-SD del perimetro"],
            ['Ticket gestiti da altri team', "COUNT(DISTINCT CASE WHEN NOT <risorsa nelle UO escluse> THEN $tk END)", 'almeno un modulo di una risorsa fuori dalle UO escluse'],
            ['Quota ticket altri team', "100 * [ticket altri team] / [ticket totale]", '%'],
        ];
    }

    public function serviceDesk(array $f, array $escl): array
    {
        $tkOk = "(" . self::provSql('ir') . " = 'ticket')";
        $ex = self::esclSql($escl);
        // B. moduli WTS-SD del perimetro: aggregati per commessa e totali (DISTINCT sull'intero perimetro, non somma di righe)
        [$w, $a] = $this->where($f);
        $w .= " AND s.`linea_servizio` = ?"; $a[] = self::SD_LINEA;
        $cols = "COUNT(*) AS moduli, COUNT(DISTINCT s.`incaricato`) AS risorse, ROUND(SUM(s.`ore`), 2) AS ore,
                 COUNT(DISTINCT CASE WHEN $tkOk THEN TRIM(ir.`ticket`) END) AS ticket,
                 COUNT(DISTINCT CASE WHEN $tkOk AND NOT $ex THEN TRIM(ir.`ticket`) END) AS ticket_altri,
                 COUNT(DISTINCT CASE WHEN $tkOk AND $ex THEN TRIM(ir.`ticket`) END) AS ticket_escl,
                 COUNT(DISTINCT CASE WHEN NOT $ex THEN s.`incaricato` END) AS risorse_altri,
                 SUM(" . self::provSql('ir') . " = 'testo') AS rif_liberi";
        $from = "FROM `{$this->v['v_cm_it_servizio']}` s {$this->trJoin()} WHERE $w";
        $st = $this->pdo->prepare("SELECT s.`commessa`, $cols $from GROUP BY s.`commessa`");
        $st->execute($a); $mod = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $mod[(string)$r['commessa']] = $r;
        $st->closeCursor();
        $st = $this->pdo->prepare("SELECT $cols $from");
        $st->execute($a); $tot = $st->fetch(PDO::FETCH_ASSOC) ?: []; $st->closeCursor();

        // C. ripartizione per Unità Organizzativa della risorsa (ticket distinti per unità; un ticket può comparire in più unità)
        $fromUo = "FROM `{$this->v['v_cm_it_servizio']}` s {$this->trJoin()}
                   LEFT JOIN `cm_tech_profiles` tpx ON tpx.`is_active` = 1 AND ((s.`employee_id` IS NOT NULL AND tpx.`employee_id` = s.`employee_id`)
                                                       OR (s.`employee_id` IS NULL AND tpx.`professional_id` = ir.`technician_professional_id`))
                   LEFT JOIN `cm_tech_units` u ON u.`id` = tpx.`unit_id` WHERE $w";
        $st = $this->pdo->prepare("SELECT COALESCE(u.`id`, 0) AS uo_id, COALESCE(u.`name`, '(nessuna unità)') AS uo,
                    COUNT(*) AS moduli, COUNT(DISTINCT s.`incaricato`) AS risorse, ROUND(SUM(s.`ore`), 2) AS ore,
                    COUNT(DISTINCT CASE WHEN $tkOk THEN TRIM(ir.`ticket`) END) AS ticket
               $fromUo GROUP BY uo_id, uo ORDER BY ticket DESC, moduli DESC");
        $st->execute($a); $perUo = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
        foreach ($perUo as &$u) $u['escluso'] = in_array((int)$u['uo_id'], $escl, true);
        unset($u);

        // A. perimetro contratti WTS-SD: attivi nel periodo o con moduli nel periodo, filtri globali di contratto
        $wc = ["p.`service_line` = ?"]; $ac = [self::SD_LINEA];
        $per = "(COALESCE(p.`start_date`, '1000-01-01') <= ? AND COALESCE(p.`end_date`, '9999-12-31') >= ?)"; $ac[] = $f['to']; $ac[] = $f['from'];
        if ($mod) { $per = "($per OR p.`project_code` IN (" . implode(',', array_fill(0, count($mod), '?')) . "))"; foreach (array_keys($mod) as $c) $ac[] = (string)$c; }
        $wc[] = $per;
        if ($c = $this->ctrCond('p.`project_code`', $f, $ac)) $wc[] = $c;
        if ($c = self::statoCond('pst.`id` = p.`id`', $f)) $wc[] = $c;
        if (($f['cliente'] ?? '') !== '') { $wc[] = "COALESCE(cl.`name`, p.`client_raw`) LIKE ?"; $ac[] = '%' . $f['cliente'] . '%'; }
        if (($f['q'] ?? '') !== '') { $wc[] = "(p.`project_code` LIKE ? OR p.`name` LIKE ? OR COALESCE(cl.`name`, p.`client_raw`) LIKE ?)"; $lk = '%' . $f['q'] . '%'; array_push($ac, $lk, $lk, $lk); }
        $ym = static fn(string $e) => "DATE_FORMAT($e, '%Y%m')";
        $comp = "CASE WHEN p.`start_date` IS NULL OR p.`end_date` IS NULL OR p.`end_date` < p.`start_date` THEN NULL
                      ELSE COALESCE(p.`value_total`, 0) / (PERIOD_DIFF(" . $ym('p.`end_date`') . ", " . $ym('p.`start_date`') . ") + 1)
                           * GREATEST(0, PERIOD_DIFF(" . $ym('LEAST(p.`end_date`, ?)') . ", " . $ym('GREATEST(p.`start_date`, ?)') . ") + 1) END";
        $st = $this->pdo->prepare("SELECT p.`id`, p.`project_code` AS commessa, p.`name` AS denominazione, COALESCE(cl.`name`, p.`client_raw`) AS cliente,
                    p.`start_date`, p.`end_date`, p.`operational_status` AS stato, COALESCE(p.`value_total`, 0) AS valore, ROUND($comp, 2) AS valore_periodo
               FROM `cm_projects` p LEFT JOIN `clients` cl ON cl.`id` = p.`client_id`
              WHERE " . implode(' AND ', $wc) . " ORDER BY p.`project_code`");
        $st->execute(array_merge([$f['to'], $f['from']], $ac));
        $ctr = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
        $vuoto = ['moduli' => 0, 'risorse' => 0, 'ore' => 0, 'ticket' => 0, 'ticket_altri' => 0, 'ticket_escl' => 0, 'risorse_altri' => 0, 'rif_liberi' => 0];
        $nAtt = 0; $sumRis = 0;
        foreach ($ctr as &$c) {
            $c += $mod[(string)$c['commessa']] ?? $vuoto;
            $c['attivo'] = (string)($c['start_date'] ?? '') <= $f['to'] && (string)($c['end_date'] ?? '9999-12-31') >= $f['from'];
            if ((int)$c['moduli'] > 0) { $nAtt++; $sumRis += (int)$c['risorse']; }
        }
        unset($c);
        $tk = (int)($tot['ticket'] ?? 0); $tkA = (int)($tot['ticket_altri'] ?? 0);
        $kpi = [
            'contratti'        => count($ctr),
            'contratti_attivi' => count(array_filter($ctr, fn($c) => $c['attivo'])),
            'contratti_moduli' => $nAtt,
            'valore'           => round(array_sum(array_map(fn($c) => (float)$c['valore'], $ctr)), 2),
            'valore_periodo'   => round(array_sum(array_map(fn($c) => (float)$c['valore_periodo'], $ctr)), 2),
            'media_risorse'    => $nAtt > 0 ? round($sumRis / $nAtt, 2) : null,
            'risorse'          => (int)($tot['risorse'] ?? 0),
            'moduli'           => (int)($tot['moduli'] ?? 0),
            'ore'              => (float)($tot['ore'] ?? 0),
            'ticket'           => $tk,
            'ticket_altri'     => $tkA,
            'ticket_escl'      => (int)($tot['ticket_escl'] ?? 0),
            'ticket_solo_escl' => $tk - $tkA,
            'pct_altri'        => $tk > 0 ? round($tkA / $tk * 100, 1) : null,
            'rif_liberi'       => (int)($tot['rif_liberi'] ?? 0),
        ];
        $on = PmUoFilter::options($this->pdo);
        return ['kpi' => $kpi, 'contratti' => $ctr, 'uo' => $perUo, 'escl' => $escl,
                'escl_nomi' => implode(', ', array_map(fn($i) => $on[$i] ?? ('#' . $i), $escl))];
    }
}
