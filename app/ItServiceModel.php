<?php
/**
 * ItServiceModel — letture per la Relazione di Servizio IT.
 *
 * Ogni interrogazione passa da `v_cm_it_servizio`, che espone una riga per
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

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

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
                       FROM `v_cm_it_servizio`")->fetch(PDO::FETCH_ASSOC);
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

        if ($f['q'] !== '') {
            $w[] = "(s.`commessa` LIKE ? OR s.`cliente` LIKE ? OR s.`modulo` LIKE ?)";
            $lk = '%' . $f['q'] . '%'; $a[] = $lk; $a[] = $lk; $a[] = $lk;
        }
        if ($f['cliente'] !== '') { $w[] = "s.`cliente` LIKE ?"; $a[] = '%' . $f['cliente'] . '%'; }

        return [implode(' AND ', $w), $a];
    }

    /** Totali del periodo. */
    public function totali(array $f): array
    {
        [$w, $a] = $this->where($f);
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
                    SUM(s.`fascia_oraria` = 'fuori orario') AS fuori_orario,
                    ROUND(SUM(CASE WHEN s.`ha_ricavo`=1 THEN s.`ore` ELSE 0 END), 2) AS ore_ricavo,
                    COUNT(DISTINCT s.`linea_servizio`)  AS linee,
                    COUNT(DISTINCT s.`commessa`)        AS commesse,
                    COUNT(DISTINCT s.`cliente`)         AS clienti
               FROM `v_cm_it_servizio` s WHERE $w");
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
                    SUM(s.`modalita` = 'reperibilita')  AS reperibilita,
                    SUM(s.`fascia_oraria` = 'fuori orario') AS fuori_orario,
                    ROUND(SUM(CASE WHEN s.`ha_ricavo`=1 THEN s.`ore` ELSE 0 END), 2) AS ore_ricavo
               FROM `v_cm_it_servizio` s
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
               FROM `v_cm_it_servizio` s WHERE $w
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
        $st = $this->pdo->prepare(
            "SELECT s.`anno_mese` AS ym, COUNT(*) AS interventi,
                    ROUND(SUM(s.`ore`), 2) AS ore,
                    ROUND(SUM(s.`ore_viaggio`), 2) AS ore_viaggio,
                    ROUND(SUM(CASE WHEN s.`fascia_oraria`='fuori orario' THEN s.`ore` ELSE 0 END), 2) AS ore_fuori,
                    COUNT(DISTINCT CONCAT(s.`incaricato`,'|',s.`giorno`)) AS giornate_uomo
               FROM `v_cm_it_servizio` s WHERE $w
              GROUP BY s.`anno_mese` ORDER BY s.`anno_mese`");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Valori disponibili per i menu dei filtri. */
    public function valori(string $dim): array
    {
        if (!isset(self::DIM[$dim])) return [];
        try {
            return $this->pdo->query(
                $dim === 'incaricato'
                    ? "SELECT DISTINCT `incaricato` FROM `v_cm_it_servizio`
                        WHERE `incaricato` IS NOT NULL AND `incaricato` <> ''
                        ORDER BY `incaricato_ordina`, `incaricato`"
                    : "SELECT DISTINCT `$dim` FROM `v_cm_it_servizio`
                        WHERE `$dim` IS NOT NULL AND `$dim` <> '' ORDER BY `$dim`")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }

    /** Coppie sede-cliente prive di distanza, per la geocodifica. */
    public function distanzeMancanti(int $limite = 100): array
    {
        try {
            $st = $this->pdo->query(
                "SELECT * FROM `v_cm_it_distanze_mancanti`
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
               FROM `v_cm_it_servizio` s WHERE $w");
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
            if (!empty($f['incaricati']) && is_array($f['incaricati'])) {
                $ph = implode(',', array_fill(0, count($f['incaricati']), '?'));
                $w .= " AND `tecnico` IN ($ph)";
                foreach ($f['incaricati'] as $v) $a[] = $v;
            }
            if (!empty($f['codici']) && is_array($f['codici'])) {
                $ph = implode(',', array_fill(0, count($f['codici']), '?'));
                $w .= " AND `codice_linea` IN ($ph)";
                foreach ($f['codici'] as $v) $a[] = $v;
            }
            if (($f['cliente'] ?? '') !== '') { $w .= " AND `cliente` LIKE ?"; $a[] = '%' . $f['cliente'] . '%'; }
            $st = $this->pdo->prepare("$select FROM `v_cm_sd_costi_valorizzati` WHERE $w $coda");
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
     * Riconciliazione: gli stessi giorni con e senza il filtro sulle attive.
     *
     * Serve a rispondere a "perche' il totale e' cambiato": una commessa chiusa
     * dopo la stampa fa scendere i giorni senza che nulla sia cambiato nei
     * moduli.
     */
    public function giorniRiconcilia(array $f): array
    {
        try {
            $w = "1=1"; $a = [];
            if (!empty($f['from']) && !empty($f['to'])) {
                $w = "`giorno` BETWEEN ? AND ?"; $a[] = $f['from']; $a[] = $f['to'];
            }
            if (!empty($f['incaricati']) && is_array($f['incaricati'])) {
                $ph = implode(',', array_fill(0, count($f['incaricati']), '?'));
                $w .= " AND `operatore` IN ($ph)";
                foreach ($f['incaricati'] as $v) $a[] = $v;
            }
            $st = $this->pdo->prepare(
                "SELECT `operatore`, `ordina`,
                        COUNT(DISTINCT `giorno`) AS giorni_totali,
                        COUNT(DISTINCT CASE WHEN `commessa_attiva`=1 THEN `giorno` END) AS giorni_attive,
                        COUNT(DISTINCT CASE WHEN `commessa_attiva`=0 THEN `giorno` END) AS giorni_chiuse,
                        ROUND(SUM(`ore`), 2) AS ore_totali,
                        ROUND(SUM(CASE WHEN `commessa_attiva`=1 THEN `ore` ELSE 0 END), 2) AS ore_attive
                   FROM `v_cm_it_giorni_base` WHERE $w
                  GROUP BY `operatore`, `ordina`
                 HAVING `giorni_chiuse` > 0
                  ORDER BY `ordina`");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** Il corpo comune: filtro di periodo e incaricati, sulle sole attive. */
    private function giorniQuery(array $f, string $select, string $coda): array
    {
        try {
            $w = "`commessa_attiva` = 1"; $a = [];
            if (!empty($f['from']) && !empty($f['to'])) {
                $w .= " AND `giorno` BETWEEN ? AND ?"; $a[] = $f['from']; $a[] = $f['to'];
            }
            if (!empty($f['incaricati']) && is_array($f['incaricati'])) {
                $ph = implode(',', array_fill(0, count($f['incaricati']), '?'));
                $w .= " AND `operatore` IN ($ph)";
                foreach ($f['incaricati'] as $v) $a[] = $v;
            }
            if (!empty($f['codici']) && is_array($f['codici'])) {
                $ph = implode(',', array_fill(0, count($f['codici']), '?'));
                $w .= " AND `codice_linea` IN ($ph)";
                foreach ($f['codici'] as $v) $a[] = $v;
            }
            if (($f['cliente'] ?? '') !== '') { $w .= " AND `cliente` LIKE ?"; $a[] = '%' . $f['cliente'] . '%'; }
            $st = $this->pdo->prepare("$select FROM `v_cm_it_giorni_base` WHERE $w $coda");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

}
