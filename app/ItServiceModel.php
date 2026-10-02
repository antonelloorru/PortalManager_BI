<?php
/**
 * ItServiceModel — letture per la Relazione di Servizio IT.
 *
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
        if ($f['ricavo'] !== '') { $w[] = "s.`ha_ricavo` = ?"; $a[] = (int)$f['ricavo']; }
        if ($c = $this->ctrCond('s.`commessa`', $f, $a)) $w[] = $c;
        if ($c = self::statoCond('pst.`project_code` = s.`commessa`', $f)) $w[] = $c;   // v1.9.87

        if ($f['q'] !== '') {
            $w[] = "(s.`commessa` LIKE ? OR s.`cliente` LIKE ? OR s.`modulo` LIKE ?)";
            $lk = '%' . $f['q'] . '%'; $a[] = $lk; $a[] = $lk; $a[] = $lk;
        }
        if ($f['cliente'] !== '') { $w[] = "s.`cliente` LIKE ?"; $a[] = '%' . $f['cliente'] . '%'; }

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
        foreach (['linee','codici','settori','aziende','incaricati','modalita','fasce','durate','sedi','contratti','stati'] as $k)
            if (!empty($f[$k])) return true;
        return ($f['ricavo'] ?? '') !== '' || ($f['q'] ?? '') !== '' || ($f['cliente'] ?? '') !== '';
    }

    /** Filtri su dimensioni esistenti solo nella vista dei rapportini (non nelle tabelle DGB). */
    private static function haFiltriServizio(array $f): bool
    {
        foreach (['linee','codici','settori','aziende','modalita','fasce','durate','sedi'] as $k)
            if (!empty($f[$k])) return true;
        return ($f['ricavo'] ?? '') !== '' || ($f['q'] ?? '') !== '';
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
                    ROUND(SUM({$cQ['rep']}), 2)         AS ore_reperibilita,
                    SUM({$cQ['fuori']} > 0)              AS fuori_orario,
                    ROUND(SUM({$cQ['fuori']}), 2)       AS ore_fuori_orario,
                    ROUND(SUM(CASE WHEN s.`ha_ricavo`=1 THEN s.`ore` ELSE 0 END), 2) AS ore_ricavo
               FROM `{$this->v['v_cm_it_servizio']}` s {$this->irJoin()}
              WHERE $w GROUP BY $sel $ord LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
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
            ? " LEFT JOIN `cm_intervention_reports` ir ON ir.`id` = (SELECT MIN(x.`id`) FROM `cm_intervention_reports` x WHERE x.`report_code` = s.`modulo`) "
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
        return $this->giorniQuery($f,
            "SELECT $col AS voce,
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


        
    /* [PM_V1_9_36_APPLIED] Espressione data effettiva e filtri condivisi */
    private function rsiWhere(array $f, array &$b): string {
        $DT = self::DGB_DATA;
        $w = ['COALESCE(a.deleted,0) <> 1'];
        if (!empty($f['from']) && !empty($f['to'])) { $w[] = "$DT BETWEEN ? AND ?"; $b[]=$f['from']; $b[]=$f['to']; }
        if (!empty($f['incaricati']) && is_array($f['incaricati'])) {
            $ph = implode(',', array_fill(0, count($f['incaricati']), '?'));
            $w[] = "(TRIM(CONCAT_WS(' ', op.first_name, op.second_name)) IN ($ph)
                   OR TRIM(CONCAT_WS(' ', op.second_name, op.first_name)) IN ($ph))";
            foreach ($f['incaricati'] as $v) $b[]=$v;
            foreach ($f['incaricati'] as $v) $b[]=$v;
        }
        if (!empty($f['cliente'])) { $w[] = "cli.name LIKE ?"; $b[]='%'.$f['cliente'].'%'; }
        if ($c = $this->ctrCondDgb('a.id_contract', $f, $b)) $w[] = $c;   // v1.9.77
        // v1.9.87 — stato commessa: PM Project collegato al contratto DGB
        if ($c = self::statoCond('pst.`dgb_contract_id` = a.id_contract', $f)) $w[] = $c;
        // v1.9.87 — dimensioni che esistono solo sui rapportini (linea, settore, azienda,
        // modalità, fascia, durata, sede, natura, ricerca): attività il cui rapportino rientra
        // nel perimetro unico. Senza questi filtri il riepilogo DGB li ignorava.
        if (self::haFiltriServizio($f)) {
            $w[] = "a.id IN (SELECT irp.`dgb_activity_id` FROM `cm_intervention_reports` irp
                              WHERE irp.`dgb_activity_id` IS NOT NULL
                                AND irp.`id` IN (" . $this->perimetro($f, false, $b) . "))";
        }
        return 'WHERE ' . implode(' AND ', $w);
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

    /** Query del dettaglio per commessa, senza ORDER BY (riusata da dettaglio e sintesi). */
    private function dettaglioSql(array $f, array &$b, ?int $contractId): string
    {
        $DT = self::DGB_DATA;
        $where = $this->rsiWhere($f, $b);
        if ($contractId !== null) {
            $where .= (stripos($where, 'WHERE') === false ? ' WHERE ' : ' AND ') . 'a.id_contract = ?';
            $b[] = $contractId;
        }
        $ORE  = "COALESCE(ao.hours, a.human_resource_hours, 0)";
        $COST = "COALESCE(ao.cost, a.human_resource_cost, a.total_cost, 0)";
        $sql = "
          SELECT a.id_contract AS contract_id,
                 COALESCE(NULLIF(c.code,''), p.project_code, CONCAT('Contratto #', a.id_contract)) AS contract_code,
                 c.code_x_installation,
                 cli.name AS customer_name, c.description AS contract_description,
                 p.project_code AS pm_project_code,
                 DATE_FORMAT($DT, '%d/%m/%Y') AS report_date, $DT AS report_iso,
                 a.id AS activity_id,
                 a.ticket,
                 TRIM(CONCAT_WS(' ', op.second_name, op.first_name)) AS operator_name,
                 COALESCE(rbb.band_name, op.type, 'Default') AS fascia,
                 CASE WHEN COALESCE(ao.during_availability,0)=1 THEN 'Reperibilità'
                      WHEN COALESCE(ao.extra_hours,0) >= $ORE AND $ORE > 0 THEN 'Straordinario'
                      WHEN COALESCE(ao.extra_hours,0) > 0
                           THEN CONCAT('Ordinario + straordinario (', REPLACE(FORMAT(ao.extra_hours,1),'.',','), ' h)')
                      ELSE 'Ordinario' END AS regime,
                 ROUND($ORE,2) AS ore,
                 ROUND($COST,2) AS costo_contratto,
                 ROUND(CASE WHEN COALESCE(ao.during_availability,0)=1
                            THEN COALESCE(rb_rep.rate_hour, op.hourly_cost, 0)*$ORE
                            ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost, 0)*$ORE END, 2) AS tot_costo_tab
          FROM dgb_forms_activity a
          LEFT JOIN dgb_operator op ON op.id = a.id_operator
          LEFT JOIN dgb_forms_contract c ON c.id = a.id_contract
          LEFT JOIN dgb_forms_activity_operator ao ON ao.id_activity = a.id AND ao.id_operator = a.id_operator
          LEFT JOIN clients cli ON cli.id = COALESCE(c.id_customer_comp, a.id_customer_comp)
          LEFT JOIN (SELECT dgb_contract_id, MIN(project_code) AS project_code
                       FROM cm_projects GROUP BY dgb_contract_id) p
                 ON p.dgb_contract_id = a.id_contract
          LEFT JOIN cm_rate_bands rbb ON rbb.band_name = COALESCE(op.type,'Default')
          LEFT JOIN cm_rate_band_rates rb_ord ON rb_ord.band_id=rbb.id AND rb_ord.cost_type='Aziendale' AND rb_ord.regime='Ordinario'
          LEFT JOIN cm_rate_band_rates rb_rep ON rb_rep.band_id=rbb.id AND rb_rep.cost_type='Aziendale' AND rb_rep.regime='Reperibilità'
          $where
        ";
        return $sql;
    }

    /* [PM_V1_9_36_APPLIED] Riepilogo aggregato per Codice Contratto */
    public function riepilogoContratto(array $f): array
    {
        $DT = self::DGB_DATA;
        $b = []; $where = $this->rsiWhere($f, $b);
        $ORE  = "COALESCE(ao.hours, a.human_resource_hours, 0)";
        $COST = "COALESCE(ao.cost, a.human_resource_cost, a.total_cost, 0)";
        $sql = "
          SELECT a.id_contract AS contract_id,
                 COALESCE(
                   NULLIF(MAX(CONCAT_WS(' | ', NULLIF(c.code,''), NULLIF(c.code_x_installation,''),
                            NULLIF(cli.name,''), NULLIF(c.description,''))), ''),
                   MAX(p.project_code),
                   CONCAT('Contratto #', a.id_contract)
                 ) AS codice_contratto,
                 MAX(p.project_code) AS pm_project_code,
                 ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=0
                                THEN GREATEST(0, $ORE - COALESCE(ao.extra_hours,0)) ELSE 0 END),2) AS ore_ordinarie,
                 -- v1.9.87: straordinario solo fuori reperibilità e non oltre le ore della riga
                 -- (prima le ore extra in reperibilità erano contate sia qui sia in ore_reperibilita)
                 ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=0
                                THEN LEAST(COALESCE(ao.extra_hours,0), $ORE) ELSE 0 END),2) AS ore_straordinario,
                 ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=1 THEN $ORE ELSE 0 END),2) AS ore_reperibilita,
                 COUNT(DISTINCT CONCAT($DT,'#',a.id_operator)) AS giorni_uomo,
                 ROUND(SUM($COST),2) AS costo_contratto,
                 ROUND(SUM(CASE WHEN COALESCE(ao.during_availability,0)=1
                                THEN COALESCE(rb_rep.rate_hour, op.hourly_cost,0)*$ORE
                                ELSE COALESCE(rb_ord.rate_hour, op.hourly_cost,0)*$ORE END),2) AS tot_costo_tab
          FROM dgb_forms_activity a
          LEFT JOIN dgb_operator op ON op.id = a.id_operator
          LEFT JOIN dgb_forms_contract c ON c.id = a.id_contract
          LEFT JOIN dgb_forms_activity_operator ao ON ao.id_activity = a.id AND ao.id_operator = a.id_operator
          LEFT JOIN clients cli ON cli.id = COALESCE(c.id_customer_comp, a.id_customer_comp)
          LEFT JOIN (SELECT dgb_contract_id, MIN(project_code) AS project_code
                       FROM cm_projects GROUP BY dgb_contract_id) p
                 ON p.dgb_contract_id = a.id_contract
          LEFT JOIN cm_rate_bands rbb ON rbb.band_name = COALESCE(op.type,'Default')
          LEFT JOIN cm_rate_band_rates rb_ord ON rb_ord.band_id=rbb.id AND rb_ord.cost_type='Aziendale' AND rb_ord.regime='Ordinario'
          LEFT JOIN cm_rate_band_rates rb_rep ON rb_rep.band_id=rbb.id AND rb_rep.cost_type='Aziendale' AND rb_rep.regime='Reperibilità'
          $where
          GROUP BY a.id_contract
          ORDER BY SUM($ORE) DESC
        ";
        try { $st=$this->pdo->prepare($sql); $st->execute($b); return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    }
}
