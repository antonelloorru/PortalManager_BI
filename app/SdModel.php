<?php
/**
 * SdModel — letture per la sezione Service Desk.
 *
 * v1.9.78 — filtro globale Codice Contratto / PM Project (PmContractFilter) su tutte le
 * letture che dipendono dal periodo e su OBJ_2 / OBJ_2.3: ticket via ticket collegati,
 * moduli/costi/attivita' via codice commessa, assenze via persone coinvolte.
 *
 * Tutte le interrogazioni passano dalle viste della v1.8.82/83: la logica di
 * classificazione L1/L2 e delle sei classi di gestione sta in SQL, non qui.
 * Duplicarla in PHP creerebbe due definizioni da tenere allineate, e la prima
 * volta che divergessero nessuno saprebbe quale delle due e' quella giusta.
 */

declare(strict_types=1);

final class SdModel
{
    private PDO $pdo;

    /** v1.9.73 — nome da interrogare per ciascuna vista: copia aggiornata se lenta, altrimenti la vista. */
    private array $v = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        require_once __DIR__ . '/PmSnapshot.php';
        require_once __DIR__ . '/PmContractFilter.php';
        $this->v = PmSnapshot::names($pdo, ['v_cm_assenze_serie', 'v_cm_nomi', 'v_cm_sd_addetti_mese', 'v_cm_sd_attivita', 'v_cm_sd_commesse', 'v_cm_sd_costi_valorizzati', 'v_cm_sd_messaggi', 'v_cm_sd_moduli', 'v_cm_sd_nome_moduli', 'v_cm_sd_obj21_quadro', 'v_cm_sd_obj23_code', 'v_cm_sd_obj23_ripartizione', 'v_cm_sd_obj2_linee', 'v_cm_sd_obj2_quadro', 'v_cm_sd_operativita', 'v_cm_sd_presa_carico', 'v_cm_sd_scheda_tecnico', 'v_cm_sd_team', 'v_cm_sd_tecnici_uo', 'v_cm_sd_tecnico_mese', 'v_cm_sd_ticket']);
    }

    /**
     * Normalizza i filtri della pagina.
     *
     * Il periodo predefinito e' l'ultimo mese con ticket, non il mese corrente:
     * aprire la sezione il primo del mese su un pannello vuoto farebbe pensare a
     * un guasto.
     */
    public function normFilters(array $q): array
    {
        $f = [
            'from'  => '',
            'to'    => '',
            'queue' => trim((string)($q['queue'] ?? '')),
            'level' => in_array($q['level'] ?? '', ['L1', 'L2'], true) ? $q['level'] : '',
            'gest'  => trim((string)($q['gest'] ?? '')),
            // v1.8.88 — il tecnico e' un FILTRO, non solo un parametro di
            // visualizzazione: con la scheda aperta ogni riquadro della pagina
            // deve riferirsi a lui. Prima gli indicatori in testa, la
            // ripartizione e l'andamento restavano generali, e affiancati a una
            // scheda personale sembravano suoi.
            'tec'   => trim((string)($q['tec'] ?? '')),
            // v1.9.78 — filtro globale Codice Contratto / PM Project. I ticket non
            // portano la commessa: il legame passa dal ticket dell'attivita' DGB
            // (contratto) e dal ticket del rapportino (commessa). Vedi PmContractFilter.
            'contratti' => PmContractFilter::fromRequest($q),
        ];

        $d = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? $v : '';
        $f['from'] = $d($q['from'] ?? '');
        $f['to']   = $d($q['to'] ?? '');

        if ($f['from'] === '' || $f['to'] === '') {
            try {
                $r = $this->pdo->query(
                    "SELECT DATE_FORMAT(MAX(`received_at`), '%Y-%m-01') AS a,
                            LAST_DAY(MAX(`received_at`))                AS b
                       FROM `cm_sd_messages`")->fetch(PDO::FETCH_ASSOC);
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

    /* ── v1.9.78 — filtro contratto ─────────────────────────────────────── */
    private ?PmContractFilter $cf = null;

    /** Filtro contratto risolto una volta per richiesta (codici, id DGB, ticket). */
    public function cf(array $f): PmContractFilter
    {
        $v = $f['contratti'] ?? [];
        if ($this->cf === null || $this->cf->values() !== PmContractFilter::norm($v)) $this->cf = new PmContractFilter($this->pdo, $v);
        return $this->cf;
    }

    /** " AND <col> IN (...)" se il filtro e' attivo, "" altrimenti. $kind: code | pid | id | ticket. */
    private function ctr(array $f, string $kind, string $col, array &$a): string
    {
        return $this->cf($f)->andSql($kind, $col, $a);
    }

    /** Opzioni del filtro contratto (elenco globale: i ticket possono riguardare qualunque commessa). */
    public function valoriContratti(): array
    {
        return PmContractFilter::options($this->pdo);
    }

    /**
     * Persone coinvolte nei contratti selezionati nel periodo, nella forma dei ticket:
     * chi ha preso in carico un ticket collegato + chi ha un modulo sulle commesse.
     * Serve alle assenze, che non hanno una commessa: con il filtro si mostrano
     * quelle delle persone che hanno lavorato sul contratto.
     */
    private ?array $persone = null;
    private function personeContratto(array $f): array
    {
        if ($this->persone !== null) return $this->persone;
        $cf = $this->cf($f); $out = [];
        try {
            $a = [];
            $w = $cf->sql('ticket', 'pc.`ticket`', $a);
            $st = $this->pdo->prepare("SELECT DISTINCT pc.`tecnico` FROM `{$this->v['v_cm_sd_presa_carico']}` pc WHERE $w");
            $st->execute($a); $out = $st->fetchAll(PDO::FETCH_COLUMN); $st->closeCursor();
            $a = [$f['from'], $f['to']];
            $w = $cf->sql('code', 'r.`project_code`', $a);
            $st = $this->pdo->prepare(
                "SELECT DISTINCT b.`nome_ticket` FROM `cm_intervention_reports` r
                   JOIN `{$this->v['v_cm_sd_nome_moduli']}` b ON b.`nome_moduli` = r.`technician_raw`
                  WHERE r.`report_date` BETWEEN ? AND ? AND $w");
            $st->execute($a); $out = array_merge($out, $st->fetchAll(PDO::FETCH_COLUMN)); $st->closeCursor();
        } catch (Throwable $e) {}
        return $this->persone = array_values(array_unique(array_filter(array_map('strval', $out), fn($x) => $x !== '')));
    }

    /** Condizione sulle persone per le assenze (vuota se il filtro non e' attivo). */
    private function ctrPersone(array $f, string $col, array &$a): string
    {
        if (!$this->cf($f)->active()) return '';
        $p = $this->personeContratto($f);
        if (!$p) return ' AND 0=1';
        foreach ($p as $x) $a[] = $x;
        return " AND $col IN (" . implode(',', array_fill(0, count($p), '?')) . ")";
    }

    /** Clausola condivisa da pannello, elenchi ed export: un solo punto di verita'. */
    private function where(array $f): array
    {
        // v1.8.88 — il filtro tecnico si applica con un JOIN, non con IN o
        // EXISTS.
        //
        // Su `{$this->v['v_cm_sd_ticket']}`, che e' una vista costruita su altre viste,
        // MariaDB risolve male la sottoquery: `IN` ed `EXISTS` restituivano
        // 2 ticket dove il join ne trova 520. Verificato in SQL puro, non e' un
        // errore della clausola ma dell'ottimizzatore su viste annidate.
        //
        // Il join e' anche piu' leggibile: dice "i ticket presi in carico da
        // questa persona" invece di "i ticket la cui chiave compare in".
        $w = ["t.`aperto_il` BETWEEN ? AND ?"];
        $a = [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'];

        // v1.8.88 — con un tecnico selezionato, i ticket sono quelli che ha
        // PRESO IN CARICO: e' la stessa unita' di misura usata dalla scheda, e
        // usarne una diversa qui darebbe due numeri diversi per la stessa
        // persona nella stessa pagina.
        //
        // NOTA: con `EXISTS` correlato su `t.ticket` MariaDB risolveva `t` con
        // l'alias interno della vista invece che con quello esterno, e il filtro
        // restituiva 2 ticket su 520. Un `IN` su sottoquery NON correlata evita
        // l'ambiguita': la sottoquery si risolve da sola e il confronto avviene
        // sul risultato.
        if ($f['queue'] !== '') { $w[] = "t.`coda` = ?";     $a[] = $f['queue']; }
        if ($f['gest']  !== '') { $w[] = "t.`gestione` = ?";  $a[] = $f['gest']; }
        // il livello filtra sui ticket TOCCATI da quel livello, non su una
        // proprieta' del ticket: un ticket puo' essere stato lavorato da entrambi
        if ($f['level'] === 'L1') $w[] = "t.`msg_l1` > 0";
        if ($f['level'] === 'L2') $w[] = "t.`msg_l2` > 0";
        // v1.9.78 — filtro contratto sui ticket collegati
        if ($this->cf($f)->active()) $w[] = $this->cf($f)->sql('ticket', 't.`ticket`', $a);

        // il JOIN precede i parametri della WHERE nell'ordine di sostituzione
        $join = ''; $pre = [];
        if (!empty($f['tec'])) {
            $join = " JOIN `{$this->v['v_cm_sd_presa_carico']}` pc
                        ON pc.`ticket` = t.`ticket` AND pc.`tecnico` = ? ";
            $pre[] = $f['tec'];
        }
        return [implode(' AND ', $w), array_merge($pre, $a), $join];
    }

    /** I quattro indicatori in testa alla pagina. */
    public function headline(array $f): array
    {
        [$w, $a, $j] = $this->where($f);

        $sql = "SELECT
                    COUNT(*)                                                    AS ticket,
                    SUM(t.`gestione` = 'risolto dal Service Desk')              AS risolti_l1,
                    SUM(t.`gestione` = 'escalation di 2 livello verso specialisti') AS escalation,
                    SUM(t.`gestione` = 'presa in carico diretta da specialisti')    AS diretti,
                    SUM(t.`gestione` = 'lavorato senza risposta scritta')        AS lavorati_nr,
                    SUM(t.`gestione` = 'cliente senza risposta scritta')         AS cliente_nr,
                    SUM(t.`gestione` = 'mai preso in carico')                    AS mai_presi,
                    SUM(t.`stato` = 'CLOSED')                                   AS chiusi,
                    ROUND(AVG(t.`messaggi`), 1)                                 AS messaggi_medi,
                    ROUND(AVG(NULLIF(t.`durata_ore`, 0)), 1)                    AS durata_media
                  FROM `{$this->v['v_cm_sd_ticket']}` t $j
                 WHERE $w";
        $st = $this->pdo->prepare($sql);
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();

        // Il tasso di escalation si calcola SOLO sui ticket presi in carico dal
        // Service Desk. Includere le prese in carico dirette lo porterebbe dal 3%
        // al 54%: quei ticket non sono mai passati dal primo livello, e non
        // possono essere stati scalati.
        $presi = (int)$r['risolti_l1'] + (int)$r['escalation'];
        $r['presi_in_carico']   = $presi;
        $r['tasso_escalation']  = $presi > 0 ? round(100 * (int)$r['escalation'] / $presi, 1) : null;

        // i ticket che richiedono un intervento: mai presi in carico, piu' quelli
        // con cliente senza risposta ancora aperti
        $st2 = $this->pdo->prepare(
            "SELECT COUNT(*) FROM `{$this->v['v_cm_sd_ticket']}` t $j
              WHERE $w AND (t.`gestione` = 'mai preso in carico'
                        OR (t.`gestione` = 'cliente senza risposta scritta' AND t.`stato` <> 'CLOSED'))");
        $st2->execute($a);
        $r['scoperti'] = (int)$st2->fetchColumn();
        $st2->closeCursor();

        return $r;
    }

    /** Ripartizione nelle sei classi, ordinata per numerosita'. */
    public function breakdown(array $f): array
    {
        [$w, $a, $j] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT t.`gestione`, COUNT(*) AS ticket,
                    SUM(t.`stato` = 'CLOSED') AS chiusi,
                    ROUND(AVG(NULLIF(t.`durata_ore`, 0)), 1) AS durata_media
               FROM `{$this->v['v_cm_sd_ticket']}` t $j
              WHERE $w
              GROUP BY t.`gestione`
              ORDER BY ticket DESC");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Andamento mensile, per il grafico. */
    /**
     * v1.9.13 — I giorni coperti dal periodo, estremi compresi.
     *
     * Il 1 e il 31 gennaio distano 30 giorni ma il periodo ne comprende 31: e'
     * la differenza fra "quanto tempo passa" e "quanti giorni guardo", e qui
     * serve la seconda.
     *
     * Restituisce 0 se il periodo non e' definito: il chiamante ricade sulla
     * grana mensile, che e' la scelta prudente — un grafico con troppe barre e'
     * peggio di uno con poche.
     */
    private function giorniPeriodo(array $f): int
    {
        if (empty($f['from']) || empty($f['to'])) return 0;
        try {
            $a = new DateTimeImmutable((string)$f['from']);
            $b = new DateTimeImmutable((string)$f['to']);
            if ($b < $a) return 0;
            return (int)$a->diff($b)->days + 1;
        } catch (Throwable $e) { return 0; }
    }

    /** Un'impostazione numerica, con valore di ripiego se assente o illeggibile. */
    private function impostazione(string $chiave, int $default): int
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT `setting_value` FROM `app_settings` WHERE `setting_key` = ?");
            $st->execute([$chiave]);
            $v = $st->fetchColumn();
            $st->closeCursor();
            if ($v === false || !is_numeric($v)) return $default;
            $n = (int)$v;
            return $n > 0 ? $n : $default;
        } catch (Throwable $e) { return $default; }
    }

    /**
     * v1.9.13 — GRANULARITA' ADATTIVA dell'asse temporale.
     *
     * Fino a 3 mesi il raggruppamento e' GIORNALIERO, oltre e' MENSILE.
     *
     * Su un trimestre il grafico mensile aveva tre barre: non e' un andamento,
     * e' un confronto fra tre numeri. Su tre anni il grafico giornaliero ne
     * avrebbe piu' di mille, illeggibili a qualunque larghezza.
     *
     * La soglia e' in `sd_trend_giorni_soglia`: 92 giorni, cioe' un trimestre
     * comprensivo dei mesi da 31. Con 90 un trimestre solare come
     * gennaio-marzo (90 giorni) sarebbe giornaliero e maggio-luglio (92) no —
     * due periodi che l'utente chiama entrambi "tre mesi" si comporterebbero in
     * modo diverso.
     *
     * La chiave restituita resta `ym` in entrambi i casi: le pagine e i report
     * la usano per l'etichetta dell'asse, e cambiarle il nome avrebbe richiesto
     * di toccare ogni punto che la legge. Cambia il FORMATO — 'AAAA-MM' oppure
     * 'AAAA-MM-GG' — e `grana` lo dichiara, cosi' chi disegna sa cosa sta
     * ricevendo.
     */
    /**
     * v1.9.73 — Andamento GIORNALIERO dei ticket, sempre disponibile.
     * Il grafico adattivo diventa mensile oltre `sd_trend_giorni_soglia` (92 giorni):
     * con il periodo predefinito la vista giornaliera non compariva mai. Qui si usa
     * lo stesso conteggio di trend() sugli ultimi $maxGiorni giorni del periodo.
     * @return array{from:string,to:string,rows:array}
     */
    public function trendGiornaliero(array $f, int $maxGiorni = 92): array
    {
        require_once __DIR__ . '/PmCharts.php';
        [$da, $a] = PmCharts::window((string)$f['from'], (string)$f['to'], $maxGiorni);
        $jT = ''; $args = [];
        if (!empty($f['tec'])) {
            $jT = " JOIN `{$this->v['v_cm_sd_presa_carico']}` pc ON pc.`ticket` = t.`ticket` AND pc.`tecnico` = ? ";
            $args[] = $f['tec'];
        }
        array_push($args, $da . ' 00:00:00', $a . ' 23:59:59');
        $wc = $this->ctr($f, 'ticket', 't.`ticket`', $args);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT DATE(t.`aperto_il`) AS giorno,
                    COUNT(*)                                                         AS ticket,
                    SUM(t.`gestione` = 'risolto dal Service Desk')                   AS risolti_l1,
                    SUM(t.`gestione` = 'escalation di 2 livello verso specialisti')  AS escalation,
                    SUM(t.`gestione` = 'presa in carico diretta da specialisti')     AS diretti,
                    SUM(t.`gestione` = 'mai preso in carico')                        AS mai_presi
               FROM `{$this->v['v_cm_sd_ticket']}` t $jT
              WHERE t.`aperto_il` IS NOT NULL AND t.`aperto_il` BETWEEN ? AND ? $wc
              GROUP BY DATE(t.`aperto_il`) ORDER BY giorno");
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        foreach ($rows as &$r) {   // ciò che non rientra nelle quattro classi resta visibile come "altro"
            $r['altro'] = max(0, (int)$r['ticket'] - (int)$r['risolti_l1'] - (int)$r['escalation'] - (int)$r['diretti'] - (int)$r['mai_presi']);
        }
        unset($r);
        return ['from' => $da, 'to' => $a, 'rows' => $rows];
    }

    public function trend(array $f, int $mesi = 12): array
    {
        $jT = ''; $argsT = [];
        if (!empty($f['tec'])) {
            $jT = " JOIN `{$this->v['v_cm_sd_presa_carico']}` pc
                      ON pc.`ticket` = t.`ticket` AND pc.`tecnico` = ? ";
            $argsT[] = $f['tec'];
        }
        // v1.9.9 — il periodo impostato, non "gli ultimi N mesi da to".
        //
        // Il grafico mostrava dodici mesi a ritroso dalla data finale, ignorando
        // quella iniziale: chi sceglieva un trimestre vedeva un anno, e i numeri
        // del grafico non corrispondevano a quelli degli indicatori sopra.
        array_push($argsT, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59');
        $wc = $this->ctr($f, 'ticket', 't.`ticket`', $argsT);   // v1.9.78

        // giorni coperti dal periodo, estremi compresi
        $giorni = $this->giorniPeriodo($f);
        $soglia = $this->impostazione('sd_trend_giorni_soglia', 92);
        $perGiorno = ($giorni > 0 && $giorni <= $soglia);
        $fmt = $perGiorno ? '%Y-%m-%d' : '%Y-%m';

        $st = $this->pdo->prepare(
            "SELECT DATE_FORMAT(t.`aperto_il`, '$fmt') AS ym,
                    COUNT(*)                                                    AS ticket,
                    SUM(t.`gestione` = 'risolto dal Service Desk')              AS risolti_l1,
                    SUM(t.`gestione` = 'escalation di 2 livello verso specialisti') AS escalation,
                    SUM(t.`gestione` = 'presa in carico diretta da specialisti')    AS diretti,
                    SUM(t.`gestione` = 'mai preso in carico')                    AS mai_presi
               FROM `{$this->v['v_cm_sd_ticket']}` t $jT
              WHERE t.`aperto_il` IS NOT NULL
                AND t.`aperto_il` BETWEEN ? AND ? $wc
              GROUP BY ym ORDER BY ym");
        $st->execute($argsT);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();

        foreach ($out as &$r) {
            $presi = (int)$r['risolti_l1'] + (int)$r['escalation'];
            $r['tasso'] = $presi > 0 ? round(100 * (int)$r['escalation'] / $presi, 1) : null;
            // la grana viaggia con i dati: chi disegna deve sapere se 'ym' e' un
            // mese o un giorno, e dedurlo dalla lunghezza della stringa sarebbe
            // un accordo implicito che si rompe al primo formato nuovo
            $r['grana'] = $perGiorno ? 'giorno' : 'mese';
        }
        unset($r);
        return $out;
    }

    /** I ticket che richiedono un intervento. */
    public function scoperti(array $f, int $limite = 50): array
    {
        [$w, $a, $j] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT t.`ticket`, t.`oggetto`, t.`coda`, t.`stato`, t.`messaggi`,
                    t.`aperto_il`, t.`gestione`, t.`presidio`,
                    TIMESTAMPDIFF(DAY, t.`aperto_il`, NOW()) AS giorni
               FROM `{$this->v['v_cm_sd_ticket']}` t $j
              WHERE $w AND (t.`gestione` = 'mai preso in carico'
                        OR (t.`gestione` = 'cliente senza risposta scritta' AND t.`stato` <> 'CLOSED'))
              ORDER BY giorni DESC LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Operativita' per tecnico nel periodo. */
    public function operatori(array $f): array
    {
        $a = [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59', $f['tec'] ?? '', $f['tec'] ?? ''];
        $wc = $this->ctr($f, 'ticket', 'm.`ticket_code`', $a);   // v1.9.78
        $st = $this->pdo->prepare(
            // v1.8.91 — ordinato per COGNOME e nome: le due fonti scrivono il
            // nome in ordini opposti, e un ORDER BY sulla colonna ordinerebbe
            // alcune persone per cognome e altre per nome.
            "SELECT m.`author_name` AS tecnico,
                    COALESCE(n.`ordina`, LOWER(m.`author_name`)) AS ordina,
                    MAX(m.`livello`) AS livello,
                    MAX(m.`sotto_unita`) AS sotto_unita,
                    COUNT(*) AS messaggi,
                    SUM(m.`msg_type` = 'SUPPORT_MSG')   AS risposte,
                    SUM(m.`msg_type` = 'INTERNAL_NOTE') AS note,
                    COUNT(DISTINCT m.`ticket_code`)     AS ticket,
                    COUNT(DISTINCT m.`queue_name`)      AS code
               FROM `{$this->v['v_cm_sd_messaggi']}` m
          LEFT JOIN `{$this->v['v_cm_nomi']}` n ON n.`forma` = m.`author_name`
              WHERE m.`received_at` BETWEEN ? AND ?
                AND m.`author_name` IS NOT NULL AND m.`author_name` <> ''
                AND (? = '' OR m.`author_name` = ?) $wc
              GROUP BY m.`author_name`, n.`ordina`
              ORDER BY n.`ordina` IS NULL, n.`ordina`, m.`author_name`");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Volumi per coda. */
    public function code(array $f): array
    {
        [$w, $a, $j] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT COALESCE(t.`coda`, '(nessuna)') AS coda, COUNT(*) AS ticket,
                    SUM(t.`msg_l1` > 0) AS con_l1,
                    SUM(t.`gestione` = 'mai preso in carico') AS scoperti
               FROM `{$this->v['v_cm_sd_ticket']}` t $j
              WHERE $w GROUP BY coda ORDER BY ticket DESC");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.8.86 — Scheda del singolo componente.
     *
     * Le misure di ESITO — presi in carico, risolti, scalati, tempo di prima
     * risposta — sono calcolate sui ticket di cui il tecnico ha scritto la PRIMA
     * risposta di supporto. Contare i messaggi misurerebbe quanto scrive, non
     * quanto risolve: chi interviene a meta' conversazione accumula messaggi su
     * ticket che non ha preso in carico.
     */
    public function scheda(string $tecnico, array $f): array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM `{$this->v['v_cm_sd_scheda_tecnico']}` WHERE `tecnico` = ?");
        $st->execute([$tecnico]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        if (!$r) return [];

        // le misure del PERIODO selezionato, distinte da quelle complessive
        $a2 = [$tecnico, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'];
        $a3 = $a2;
        $st2 = $this->pdo->prepare(
            "SELECT COUNT(*) AS messaggi,
                    SUM(m.`msg_type` = 'SUPPORT_MSG')   AS risposte,
                    SUM(m.`msg_type` = 'INTERNAL_NOTE') AS note,
                    COUNT(DISTINCT m.`ticket_code`)     AS ticket,
                    COUNT(DISTINCT m.`queue_name`)      AS code,
                    COUNT(DISTINCT DATE(m.`received_at`)) AS giorni
               FROM `{$this->v['v_cm_sd_messaggi']}` m
              WHERE m.`author_name` = ? AND m.`received_at` BETWEEN ? AND ?" . $this->ctr($f, 'ticket', 'm.`ticket_code`', $a2));
        $st2->execute($a2);
        $r['periodo'] = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
        $st2->closeCursor();

        $st3 = $this->pdo->prepare(
            "SELECT COUNT(*) AS presi,
                    SUM(t.`gestione` = 'risolto dal Service Desk')                  AS risolti,
                    SUM(t.`gestione` = 'escalation di 2 livello verso specialisti') AS scalati,
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.`aperto_il`, p.`prima_risposta`)) / 60, 1) AS ore_1a
               FROM `{$this->v['v_cm_sd_presa_carico']}` p
               JOIN `{$this->v['v_cm_sd_ticket']}` t ON t.`ticket` = p.`ticket`
              WHERE p.`tecnico` = ? AND p.`prima_risposta` BETWEEN ? AND ?" . $this->ctr($f, 'ticket', 'p.`ticket`', $a3));
        $st3->execute($a3);
        $r['periodo_esito'] = $st3->fetch(PDO::FETCH_ASSOC) ?: [];
        $st3->closeCursor();

        return $r;
    }

    /** Andamento mensile del singolo, ultimi N mesi. */
    public function schedaMesi(string $tecnico, array $f = [], int $mesi = 12): array
    {
        // v1.9.9 — il periodo impostato. Il grafico prendeva gli ultimi 12 mesi
        // qualunque cosa fosse selezionato nei filtri.
        // v1.9.78 — con il filtro contratto la vista aggregata non basta: stesso
        // conteggio di v_cm_sd_tecnico_mese sui soli messaggi dei ticket collegati
        if ($this->cf($f)->active()) {
            $a = [$tecnico];
            $w = "m.`author_name` = ? AND m.`received_at` IS NOT NULL";
            if (!empty($f['from']) && !empty($f['to'])) {
                $w .= " AND m.`received_at` BETWEEN ? AND ?";
                $a[] = $f['from'] . ' 00:00:00'; $a[] = $f['to'] . ' 23:59:59';
            }
            $w .= $this->ctr($f, 'ticket', 'm.`ticket_code`', $a);
            $st = $this->pdo->prepare(
                "SELECT DATE_FORMAT(m.`received_at`, '%Y-%m') AS anno_mese, COUNT(*) AS messaggi,
                        SUM(m.`msg_type` = 'SUPPORT_MSG') AS risposte, SUM(m.`msg_type` = 'INTERNAL_NOTE') AS note,
                        COUNT(DISTINCT m.`ticket_code`) AS ticket
                   FROM `{$this->v['v_cm_sd_messaggi']}` m WHERE $w
                  GROUP BY anno_mese ORDER BY anno_mese DESC LIMIT " . (int)$mesi);
            $st->execute($a);
            $out = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
            $st->closeCursor();
            return $out;
        }
        $w = "`tecnico` = ?"; $a = [$tecnico];
        if (!empty($f['from']) && !empty($f['to'])) {
            $w .= " AND `anno_mese` BETWEEN ? AND ?";
            $a[] = substr((string)$f['from'], 0, 7);
            $a[] = substr((string)$f['to'], 0, 7);
        }
        $st = $this->pdo->prepare(
            "SELECT `anno_mese`, `messaggi`, `risposte`, `note`, `ticket`
               FROM `{$this->v['v_cm_sd_tecnico_mese']}`
              WHERE $w
              ORDER BY `anno_mese` DESC LIMIT " . (int)$mesi);
        $st->execute($a);
        $out = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
        $st->closeCursor();
        return $out;
    }

    /** Code presidiate dal singolo. */
    public function schedaCode(string $tecnico, array $f = []): array
    {
        // v1.9.9 — ricalcolato dai messaggi nel periodo invece di leggere la
        // vista aggregata, che e' sull'intero archivio.
        $w = "m.`author_name` = ?"; $a = [$tecnico];
        if (!empty($f['from']) && !empty($f['to'])) {
            $w .= " AND m.`received_at` BETWEEN ? AND ?";
            $a[] = $f['from'] . ' 00:00:00'; $a[] = $f['to'] . ' 23:59:59';
        }
        $w .= $this->ctr($f, 'ticket', 'm.`ticket_code`', $a);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT COALESCE(m.`queue_name`, '(nessuna)') AS coda,
                    COUNT(*) AS messaggi, COUNT(DISTINCT m.`ticket_code`) AS ticket
               FROM `{$this->v['v_cm_sd_messaggi']}` m
              WHERE $w GROUP BY coda ORDER BY ticket DESC");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** I ticket presi in carico dal singolo nel periodo. */
    public function schedaTicket(string $tecnico, array $f, int $limite = 100): array
    {
        $a = [$tecnico, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'];
        $st = $this->pdo->prepare(
            "SELECT t.`ticket`, t.`oggetto`, t.`coda`, t.`stato`, t.`gestione`,
                    t.`messaggi`, t.`aperto_il`, t.`durata_ore`,
                    ROUND(TIMESTAMPDIFF(MINUTE, t.`aperto_il`, p.`prima_risposta`) / 60, 1) AS ore_1a
               FROM `{$this->v['v_cm_sd_presa_carico']}` p
               JOIN `{$this->v['v_cm_sd_ticket']}` t ON t.`ticket` = p.`ticket`
              WHERE p.`tecnico` = ? AND p.`prima_risposta` BETWEEN ? AND ?"
              . $this->ctr($f, 'ticket', 'p.`ticket`', $a) . "
              ORDER BY t.`aperto_il` DESC LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.8.87 — Operatività sui moduli di intervento, per modello contrattuale.
     *
     * Ticket e moduli NON si sommano: un ticket puo' generare un modulo, e
     * contarli insieme conterebbe lo stesso lavoro due volte. Restano due
     * grandezze affiancate.
     */
    public function moduliContratto(string $tecnico, array $f): array
    {
        $a = [$tecnico, $f['from'], $f['to']];
        $wc = $this->ctr($f, 'code', 'r.`project_code`', $a);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT COALESCE(cm.`label`, p.`service_line`, '(nessuna linea)') AS contratto,
                    COALESCE(p.`service_line`, '(nessuna)')       AS codice,
                    COALESCE(cm.`model`, 'da_classificare')       AS modello,
                    COALESCE(cm.`has_revenue`, 1)                 AS ha_ricavo,
                    COUNT(*)                                      AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    ROUND(SUM(COALESCE(r.`extra_hours`,0)), 2)    AS ore_extra,
                    COUNT(DISTINCT r.`project_code`)              AS commesse
               FROM `cm_intervention_reports` r
               JOIN `{$this->v['v_cm_sd_nome_moduli']}` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE b.`nome_ticket` = ? AND r.`report_date` BETWEEN ? AND ? $wc
              GROUP BY contratto, codice, modello, ha_ricavo
              ORDER BY ore DESC");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Riepilogo dei moduli nel periodo: totale, a ricavo, interne. */
    public function moduliRiepilogo(string $tecnico, array $f): array
    {
        $a = [$tecnico, $f['from'], $f['to']];
        $wc = $this->ctr($f, 'code', 'r.`project_code`', $a);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT COUNT(*)                                      AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    ROUND(SUM(CASE WHEN COALESCE(cm.`has_revenue`,1) = 1
                              THEN COALESCE(r.`quantity_hours`,0) ELSE 0 END), 2) AS ore_ricavo,
                    ROUND(SUM(COALESCE(r.`extra_hours`,0)), 2)    AS ore_extra,
                    COUNT(DISTINCT r.`project_code`)              AS commesse,
                    COUNT(DISTINCT COALESCE(cm.`model`,'x'))      AS modelli
               FROM `cm_intervention_reports` r
               JOIN `{$this->v['v_cm_sd_nome_moduli']}` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE b.`nome_ticket` = ? AND r.`report_date` BETWEEN ? AND ? $wc");
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        $r['ore_interne'] = round((float)($r['ore'] ?? 0) - (float)($r['ore_ricavo'] ?? 0), 2);
        $r['pct_ricavo']  = ((float)($r['ore'] ?? 0)) > 0
            ? round(100 * (float)$r['ore_ricavo'] / (float)$r['ore'], 1) : null;
        return $r;
    }

    /**
     * v1.8.92 — Moduli del componente per CODICE di linea di servizio.
     *
     * `moduliContratto()` raggruppa per etichetta del modello contrattuale —
     * "Chiavi in mano", "Presidio presso cliente" — che e' leggibile ma non
     * corrisponde uno a uno con la linea: piu' linee possono condividere lo
     * stesso modello.
     *
     * Il CODICE e' quello che compare sui documenti e nel gestionale. Chi
     * riscontra un tabulato cerca "WTS-ACM", non "Chiavi in mano".
     */
    public function moduliCodice(string $tecnico, array $f): array
    {
        $a = [$tecnico, $f['from'], $f['to']];
        $wc = $this->ctr($f, 'code', 'r.`project_code`', $a);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT COALESCE(p.`service_line`, '(nessuna)')   AS codice,
                    COALESCE(cm.`label`, p.`service_line`)    AS etichetta,
                    COALESCE(cm.`model`, 'da_classificare')   AS modello,
                    COALESCE(cm.`has_revenue`, 1)             AS ha_ricavo,
                    COUNT(*)                                  AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    COUNT(DISTINCT r.`project_code`)          AS commesse
               FROM `cm_intervention_reports` r
               JOIN `{$this->v['v_cm_sd_nome_moduli']}` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE b.`nome_ticket` = ? AND r.`report_date` BETWEEN ? AND ? $wc
              GROUP BY codice, etichetta, modello, ha_ricavo
              ORDER BY ore DESC");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * Moduli di TUTTO il Service Desk per codice di linea, per il pannello
     * generale: prima il dato esisteva solo dentro la scheda del singolo.
     */
    public function codiciLinea(array $f): array
    {
        $ac = [];
        $wc = $this->ctr($f, 'code', 'r.`project_code`', $ac);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT COALESCE(p.`service_line`, '(nessuna)')   AS codice,
                    COALESCE(cm.`label`, p.`service_line`)    AS etichetta,
                    COALESCE(cm.`has_revenue`, 1)             AS ha_ricavo,
                    COUNT(*)                                  AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    COUNT(DISTINCT b.`nome_ticket`)           AS tecnici,
                    COUNT(DISTINCT r.`project_code`)          AS commesse
               FROM `cm_intervention_reports` r
               JOIN `{$this->v['v_cm_sd_nome_moduli']}` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE r.`report_date` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND b.`nome_ticket` = ?" : "") . " $wc
              GROUP BY codice, etichetta, ha_ricavo
              ORDER BY ore DESC");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        foreach ($ac as $x) $a[] = $x;
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.8.93 — Moduli del Service Desk per AZIENDA ESECUTRICE.
     *
     * L'azienda e' derivata dal prefisso del codice commessa e risolta in
     * `cm_projects.exec_company_id`, popolato su tutte le commesse. Si usa il
     * NOME e non il prefisso: chi legge riconosce "Nis Group srl", non "NIS".
     */
    public function aziendeEsecutrici(array $f): array
    {
        $ac = [];
        $wc = $this->ctr($f, 'code', 'r.`project_code`', $ac);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT COALESCE(az.`name`, '(non attribuita)')   AS azienda,
                    COUNT(*)                                  AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    COUNT(DISTINCT b.`nome_ticket`)           AS tecnici,
                    COUNT(DISTINCT r.`project_code`)          AS commesse,
                    COUNT(DISTINCT p.`service_line`)          AS linee
               FROM `cm_intervention_reports` r
               JOIN `{$this->v['v_cm_sd_nome_moduli']}` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p  ON p.`id` = r.`project_id`
          LEFT JOIN `companies` az   ON az.`id` = p.`exec_company_id`
              WHERE r.`report_date` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND b.`nome_ticket` = ?" : "") . " $wc
              GROUP BY azienda ORDER BY ore DESC");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        foreach ($ac as $x) $a[] = $x;
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Code seguite dal singolo, con la quota sul totale della coda. */
    public function codeDettaglio(string $tecnico, array $f = []): array
    {
        // v1.9.9 — periodo. La quota si rapporta al totale della coda NELLO
        // STESSO periodo: rapportarla al totale storico darebbe percentuali
        // minuscole su un trimestre, e sembrerebbero un difetto di calcolo.
        $wm = "m.`author_name` = ?"; $wt = "1=1"; $a = [$tecnico]; $at = [];
        if (!empty($f['from']) && !empty($f['to'])) {
            $wm .= " AND m.`received_at` BETWEEN ? AND ?";
            $a[] = $f['from'] . ' 00:00:00'; $a[] = $f['to'] . ' 23:59:59';
            $wt = "x.`received_at` BETWEEN ? AND ?";
            $at[] = $f['from'] . ' 00:00:00'; $at[] = $f['to'] . ' 23:59:59';
        }
        // v1.9.78 — filtro contratto: messaggi, totale di coda e prese in carico
        // sugli stessi ticket, cosi' la quota resta un rapporto omogeneo
        $wt .= $this->ctr($f, 'ticket', 'x.`ticket_code`', $at);
        $ap = []; $wp = $this->cf($f)->active() ? ' WHERE ' . $this->cf($f)->sql('ticket', '`ticket`', $ap) : '';
        $wm .= $this->ctr($f, 'ticket', 'm.`ticket_code`', $a);
        $st = $this->pdo->prepare(
            "SELECT COALESCE(m.`queue_name`, '(nessuna)')   AS coda,
                    COUNT(DISTINCT m.`ticket_code`)         AS ticket,
                    COUNT(*)                                AS messaggi,
                    COALESCE(pc.`presi`, 0)                 AS presi_in_carico,
                    COALESCE(tot.`ticket_coda`, 0)          AS ticket_coda,
                    CASE WHEN COALESCE(tot.`ticket_coda`, 0) > 0
                         THEN ROUND(100 * COUNT(DISTINCT m.`ticket_code`)
                                  / tot.`ticket_coda`, 1) END AS quota_coda_pct
               FROM `{$this->v['v_cm_sd_messaggi']}` m
          LEFT JOIN (SELECT COALESCE(x.`queue_name`,'(nessuna)') AS coda,
                            COUNT(DISTINCT x.`ticket_code`) AS ticket_coda
                       FROM `cm_sd_messages` x WHERE $wt GROUP BY coda) tot
                 ON tot.`coda` = COALESCE(m.`queue_name`, '(nessuna)')
          LEFT JOIN (SELECT `tecnico`, COALESCE(`coda`,'(nessuna)') AS coda, COUNT(*) AS presi
                       FROM `{$this->v['v_cm_sd_presa_carico']}`$wp GROUP BY `tecnico`, coda) pc
                 ON pc.`tecnico` = ? AND pc.`coda` = COALESCE(m.`queue_name`, '(nessuna)')
              WHERE $wm
              GROUP BY coda, pc.`presi`, tot.`ticket_coda`
              ORDER BY ticket DESC");
        $st->execute(array_merge($at, $ap, [$tecnico], $a));
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Operatività completa di tutti i componenti: ticket e moduli affiancati. */
    public function operativita(array $f = []): array
    {
        // v1.9.9 — periodo. La vista aggregata e' sull'intero archivio: qui i
        // valori vengono ricalcolati sul periodo, cosi' la tabella dei
        // componenti concorda con gli indicatori in testa alla pagina.
        try {
            if (empty($f['from']) || empty($f['to'])) {
                // v1.9.9 — `{$this->v['v_cm_sd_operativita']}` NON ha la colonna `ordina`:
                // l'ORDER BY su di essa faceva fallire la query, e il try/catch
                // restituiva un elenco vuoto. La tabella dei componenti era
                // vuota da prima di questa release, senza alcun segnale.
                // La chiave di ordinamento viene da `{$this->v['v_cm_nomi']}`.
                return $this->pdo->query(
                    "SELECT o.*, COALESCE(n.`ordina`, LOWER(o.`tecnico`)) AS ordina
                       FROM `{$this->v['v_cm_sd_operativita']}` o
                  LEFT JOIN `{$this->v['v_cm_nomi']}` n ON n.`forma` = o.`tecnico`
                      WHERE o.`livello` = 'L1'
                      ORDER BY ordina, o.`tecnico`")
                    ->fetchAll(PDO::FETCH_ASSOC);
            }
            // v1.9.78 — filtro contratto in ciascuna delle tre sottoquery
            $a1 = [$f['from'].' 00:00:00', $f['to'].' 23:59:59'];
            $a2 = $a1; $a3 = [$f['from'], $f['to']];
            $w1 = $this->ctr($f, 'ticket', 'pc.`ticket`', $a1);
            $w2 = $this->ctr($f, 'ticket', '`ticket_code`', $a2);
            $w3 = $this->ctr($f, 'code', '`commessa`', $a3);
            $st = $this->pdo->prepare(
                "SELECT o.`tecnico`, o.`livello`, o.`sotto_unita`,
                        COALESCE(p.`presi`, 0)                    AS presi_in_carico,
                        COALESCE(p.`risolti`, 0)                  AS risolti,
                        COALESCE(p.`scalati`, 0)                  AS scalati,
                        CASE WHEN COALESCE(p.`presi`,0) > 0
                             THEN ROUND(100*p.`scalati`/p.`presi`,1) END AS tasso_escalation_pct,
                        p.`ore_1a`                                AS ore_prima_risposta,
                        COALESCE(ms.`messaggi`, 0)                AS messaggi,
                        COALESCE(ms.`code`, 0)                    AS code_ticket,
                        COALESCE(md.`moduli`, 0)                  AS moduli_intervento,
                        ROUND(COALESCE(md.`ore`, 0), 2)           AS ore_moduli,
                        ROUND(COALESCE(md.`ore_ric`, 0), 2)       AS ore_a_ricavo,
                        ROUND(COALESCE(md.`ore`,0)-COALESCE(md.`ore_ric`,0), 2) AS ore_interne,
                        CASE WHEN COALESCE(md.`ore`,0) > 0
                             THEN ROUND(100*md.`ore_ric`/md.`ore`,1) END AS pct_a_ricavo,
                        COALESCE(md.`commesse`, 0)                AS commesse,
                        COALESCE(n.`ordina`, LOWER(o.`tecnico`))  AS ordina
                   FROM `{$this->v['v_cm_sd_operativita']}` o
              LEFT JOIN `{$this->v['v_cm_nomi']}` n ON n.`forma` = o.`tecnico`
              LEFT JOIN (SELECT pc.`tecnico`, COUNT(*) AS presi,
                                SUM(t.`gestione`='risolto dal Service Desk') AS risolti,
                                SUM(t.`gestione`='escalation di 2 livello verso specialisti') AS scalati,
                                ROUND(AVG(TIMESTAMPDIFF(MINUTE,t.`aperto_il`,pc.`prima_risposta`))/60,1) AS ore_1a
                           FROM `{$this->v['v_cm_sd_presa_carico']}` pc
                           JOIN `{$this->v['v_cm_sd_ticket']}` t ON t.`ticket` = pc.`ticket`
                          WHERE pc.`prima_risposta` BETWEEN ? AND ? $w1
                          GROUP BY pc.`tecnico`) p ON p.`tecnico` = o.`tecnico`
              LEFT JOIN (SELECT `author_name`, COUNT(*) AS messaggi,
                                COUNT(DISTINCT `queue_name`) AS code
                           FROM `{$this->v['v_cm_sd_messaggi']}`
                          WHERE `received_at` BETWEEN ? AND ? $w2
                          GROUP BY `author_name`) ms ON ms.`author_name` = o.`tecnico`
              LEFT JOIN (SELECT `tecnico`, COUNT(*) AS moduli, SUM(`ore`) AS ore,
                                SUM(CASE WHEN `ha_ricavo`=1 THEN `ore` ELSE 0 END) AS ore_ric,
                                COUNT(DISTINCT `commessa`) AS commesse
                           FROM `{$this->v['v_cm_sd_moduli']}` WHERE `giorno` BETWEEN ? AND ? $w3
                          GROUP BY `tecnico`) md ON md.`tecnico` = o.`tecnico`
                  WHERE o.`livello` = 'L1'
                  ORDER BY ordina, o.`tecnico`");
            $st->execute(array_merge($a1, $a2, $a3));
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** Confronto fra i componenti del primo livello. */
    public function confronto(): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM `{$this->v['v_cm_sd_scheda_tecnico']}`
                  WHERE `livello` = 'L1'
                  ORDER BY `ordina` IS NULL, `ordina`, `tecnico`")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** Elenco delle code, per il filtro. */
    public function elencoCode(): array
    {
        try {
            return $this->pdo->query(
                "SELECT DISTINCT `queue_name` FROM `cm_sd_messages`
                  WHERE `queue_name` IS NOT NULL AND `queue_name` <> ''
                  ORDER BY `queue_name`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }


    // ── v1.9.75 — ripartizione oraria dei moduli ────────────────────────────
    private ?string $sdKey = null;

    /**
     * Colonna di v_cm_sd_moduli con il codice del modulo (rapportino), se esiste.
     * Serve ad agganciare il rapportino e ripartire le ore con la regola unica
     * (app/PmOrario.php). Stringa vuota = non disponibile: resta la fascia del modulo.
     */
    public function sdKey(): string
    {
        if ($this->sdKey !== null) return $this->sdKey;
        $this->sdKey = '';
        try {
            $st = $this->pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                                          AND COLUMN_NAME IN ('modulo','report_code','codice_modulo')
                                        ORDER BY FIELD(COLUMN_NAME,'modulo','report_code','codice_modulo') LIMIT 1");
            $st->execute([$this->v['v_cm_sd_moduli']]);
            $this->sdKey = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        return $this->sdKey;
    }

    /** Quantità di ore ordinarie / fuori orario per la riga del modulo (alias $a) + JOIN necessario. */
    private function sdSplit(string $a = 'm'): array
    {
        $ore = "COALESCE($a.`ore`,0)";
        $fas = "LOWER(TRIM(COALESCE($a.`fascia_oraria`,'')))";
        $key = $this->sdKey();
        if ($key === '') {
            return ['ord' => "(CASE WHEN $fas = 'in orario' THEN $ore ELSE 0 END)",
                    'fuori' => "(CASE WHEN $fas = 'fuori orario' THEN $ore ELSE 0 END)", 'join' => ''];
        }
        require_once __DIR__ . '/PmOrario.php';
        $spl = PmOrario::ordinarieSql('ir.`start_at`', 'ir.`end_at`', $ore, $this->pdo);
        return [
            'ord'   => "(CASE WHEN ir.`id` IS NOT NULL THEN $spl WHEN $fas = 'in orario' THEN $ore ELSE 0 END)",
            'fuori' => "(CASE WHEN ir.`id` IS NOT NULL THEN $ore - $spl WHEN $fas = 'fuori orario' THEN $ore ELSE 0 END)",
            'join'  => " LEFT JOIN `cm_intervention_reports` ir ON ir.`id` = (SELECT MIN(x.`id`) FROM `cm_intervention_reports` x WHERE x.`report_code` = $a.`$key`) ",
        ];
    }

    /**
     * v1.9.5 — Il quadro di squadra.
     *
     * Ticket e moduli su colonne distinte, mai sommati: un ticket puo' generare
     * un modulo e la sovrapposizione non e' quantificabile.
     */
    public function teamQuadro(array $f): array
    {
        $sp = $this->sdSplit('m');   // v1.9.75
        $ac = []; $wc = $this->ctr($f, 'code', 'm.`commessa`', $ac);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT COUNT(*)                                              AS moduli,
                    ROUND(SUM(m.`ore`), 2)                                AS ore,
                    ROUND(SUM(m.`ore_extra`), 2)                          AS ore_extra,
                    ROUND(SUM(CASE WHEN m.`ha_ricavo` = 1 THEN m.`ore` ELSE 0 END), 2) AS ore_ricavo,
                    ROUND(SUM({$sp['fuori']}), 2)                        AS ore_fuori,
                    ROUND(SUM({$sp['ord']}), 2)                          AS ore_in_orario,
                    COUNT(DISTINCT m.`tecnico`)                           AS tecnici,
                    COUNT(DISTINCT m.`commessa`)                          AS commesse,
                    COUNT(DISTINCT m.`codice_linea`)                      AS linee,
                    COUNT(DISTINCT CONCAT(m.`tecnico`, '|', m.`giorno`))  AS giornate_uomo
               FROM `{$this->v['v_cm_sd_moduli']}` m {$sp['join']}
              WHERE m.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND m.`tecnico` = ?" : "") . $wc);
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        foreach ($ac as $x) $a[] = $x;
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        $r['ore_per_giornata'] = ((int)($r['giornate_uomo'] ?? 0)) > 0
            ? round((float)$r['ore'] / (int)$r['giornate_uomo'], 1) : null;
        $r['pct_fuori'] = ((float)($r['ore'] ?? 0)) > 0
            ? round(100 * (float)$r['ore_fuori'] / (float)$r['ore'], 1) : null;
        return $r;
    }

    /** Il dettaglio per componente della squadra. */
    public function teamDettaglio(array $f): array
    {
        $sp = $this->sdSplit('m');   // v1.9.75
        $a1 = [$f['from'].' 00:00:00', $f['to'].' 23:59:59']; $a2 = [$f['from'], $f['to']];   // v1.9.78
        $w1 = $this->ctr($f, 'ticket', 'p.`ticket`', $a1);
        $w2 = $this->ctr($f, 'code', 'm.`commessa`', $a2);
        $st = $this->pdo->prepare(
            "SELECT t.`nome` AS tecnico, t.`sotto_unita`,
                    COALESCE(nm.`ordina`, LOWER(t.`nome`))                AS ordina,
                    COALESCE(pc.`presi`, 0)                               AS ticket_presi,
                    COALESCE(md.`moduli`, 0)                              AS moduli,
                    ROUND(COALESCE(md.`ore`, 0), 2)                       AS ore,
                    ROUND(COALESCE(md.`ore_extra`, 0), 2)                 AS ore_extra,
                    ROUND(COALESCE(md.`ore_in`, 0), 2)                    AS ore_in_orario,
                    ROUND(COALESCE(md.`ore_fuori`, 0), 2)                 AS ore_fuori_orario,
                    ROUND(COALESCE(md.`ore_ricavo`, 0), 2)                AS ore_a_ricavo,
                    COALESCE(md.`commesse`, 0)                            AS commesse,
                    COALESCE(md.`linee`, 0)                               AS linee,
                    COALESCE(md.`giornate`, 0)                            AS giornate
               FROM `{$this->v['v_cm_sd_team']}` t
          LEFT JOIN `{$this->v['v_cm_nomi']}` nm ON nm.`forma` = t.`nome`
          LEFT JOIN (SELECT p.`tecnico`, COUNT(*) AS presi
                       FROM `{$this->v['v_cm_sd_presa_carico']}` p
                      WHERE p.`prima_risposta` BETWEEN ? AND ? $w1
                      GROUP BY p.`tecnico`) pc ON pc.`tecnico` = t.`nome`
          LEFT JOIN (SELECT `tecnico`, COUNT(*) AS moduli, SUM(`ore`) AS ore,
                            SUM(`ore_extra`) AS ore_extra,
                            SUM({$sp['ord']}) AS ore_in,
                            SUM({$sp['fuori']}) AS ore_fuori,
                            SUM(CASE WHEN `ha_ricavo`=1 THEN `ore` ELSE 0 END) AS ore_ricavo,
                            COUNT(DISTINCT `commessa`) AS commesse,
                            COUNT(DISTINCT `codice_linea`) AS linee,
                            COUNT(DISTINCT `giorno`) AS giornate
                       FROM `{$this->v['v_cm_sd_moduli']}` m {$sp['join']}
                      WHERE m.`giorno` BETWEEN ? AND ? $w2
                      GROUP BY m.`tecnico`) md ON md.`tecnico` = t.`nome`
              ORDER BY ordina, t.`nome`");
        $st->execute(array_merge($a1, $a2));
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        foreach ($out as &$r) {
            $r['ore_per_giornata'] = ((int)$r['giornate']) > 0
                ? round((float)$r['ore'] / (int)$r['giornate'], 1) : null;
            $r['pct_fuori'] = ((float)$r['ore']) > 0
                ? round(100 * (float)$r['ore_fuori_orario'] / (float)$r['ore'], 1) : null;
        }
        return $out;
    }

    /** Interventi e ore per fascia oraria. */
    public function teamFascia(array $f): array
    {
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        $tec = $f['tec'] !== '' ? " AND m.`tecnico` = ?" : "";
        $tec .= $this->ctr($f, 'code', 'm.`commessa`', $a);   // v1.9.78
        if ($this->sdKey() === '') {           // senza codice modulo: fascia del modulo intero (come prima)
            $st = $this->pdo->prepare(
                "SELECT m.`fascia_oraria`, COUNT(*) AS interventi, ROUND(SUM(m.`ore`), 2) AS ore,
                        ROUND(SUM(m.`ore_extra`), 2) AS ore_extra,
                        COUNT(DISTINCT m.`tecnico`) AS tecnici, COUNT(DISTINCT m.`giorno`) AS giornate,
                        ROUND(AVG(m.`ore`), 2) AS ore_medie
                   FROM `{$this->v['v_cm_sd_moduli']}` m
                  WHERE m.`giorno` BETWEEN ? AND ? $tec
                  GROUP BY m.`fascia_oraria` ORDER BY ore DESC");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
            return $out;
        }
        // v1.9.75 — ore ripartite: un modulo 12:00-00:00 conta 5 h in orario e il resto fuori orario
        $sp = $this->sdSplit('m');
        $st = $this->pdo->prepare(
            "SELECT SUM(x.o > 0) AS mi, ROUND(SUM(x.o), 2) AS oi, COUNT(DISTINCT CASE WHEN x.o > 0 THEN x.tecnico END) AS ti,
                    COUNT(DISTINCT CASE WHEN x.o > 0 THEN x.giorno END) AS gi,
                    SUM(x.u > 0) AS mf, ROUND(SUM(x.u), 2) AS of, COUNT(DISTINCT CASE WHEN x.u > 0 THEN x.tecnico END) AS tf,
                    COUNT(DISTINCT CASE WHEN x.u > 0 THEN x.giorno END) AS gf, ROUND(SUM(x.e), 2) AS ex
               FROM (SELECT m.`tecnico` AS tecnico, m.`giorno` AS giorno, {$sp['ord']} AS o, {$sp['fuori']} AS u,
                            COALESCE(m.`ore_extra`,0) AS e
                       FROM `{$this->v['v_cm_sd_moduli']}` m {$sp['join']}
                      WHERE m.`giorno` BETWEEN ? AND ? $tec) x");
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: []; $st->closeCursor();
        $out = [
            ['fascia_oraria' => 'in orario',    'interventi' => (int)($r['mi'] ?? 0), 'ore' => (float)($r['oi'] ?? 0), 'ore_extra' => 0.0,
             'tecnici' => (int)($r['ti'] ?? 0), 'giornate' => (int)($r['gi'] ?? 0)],
            ['fascia_oraria' => 'fuori orario', 'interventi' => (int)($r['mf'] ?? 0), 'ore' => (float)($r['of'] ?? 0), 'ore_extra' => (float)($r['ex'] ?? 0),
             'tecnici' => (int)($r['tf'] ?? 0), 'giornate' => (int)($r['gf'] ?? 0)],
        ];
        foreach ($out as &$o) $o['ore_medie'] = $o['interventi'] > 0 ? round($o['ore'] / $o['interventi'], 2) : 0;
        unset($o);
        usort($out, fn($p, $q) => $q['ore'] <=> $p['ore']);
        return array_values(array_filter($out, fn($o) => $o['interventi'] > 0 || $o['ore'] > 0));
    }

    /** Interventi e ore per tipologia di contratto, con la fascia. */
    public function teamContratto(array $f): array
    {
        $sp = $this->sdSplit('m');   // v1.9.75
        $ac = []; $wc = $this->ctr($f, 'code', 'm.`commessa`', $ac);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT m.`codice_linea`, m.`contratto`, m.`modello`, m.`ha_ricavo`,
                    COUNT(*) AS interventi, ROUND(SUM(m.`ore`), 2) AS ore,
                    ROUND(SUM({$sp['ord']}), 2) AS ore_in,
                    ROUND(SUM({$sp['fuori']}), 2) AS ore_fuori,
                    ROUND(SUM(m.`ore_extra`), 2) AS ore_extra,
                    COUNT(DISTINCT m.`tecnico`) AS tecnici,
                    COUNT(DISTINCT m.`commessa`) AS commesse
               FROM `{$this->v['v_cm_sd_moduli']}` m {$sp['join']}
              WHERE m.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND m.`tecnico` = ?" : "") . $wc . "
              GROUP BY m.`codice_linea`, m.`contratto`, m.`modello`, m.`ha_ricavo`
              ORDER BY ore DESC");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        foreach ($ac as $x) $a[] = $x;
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.9.6 — L'elenco dei componenti per il menu di filtro.
     *
     * Ordinato per COGNOME e con la sotto-unita' nell'etichetta: in un menu a
     * tendina il nome da solo costringe a ricordare chi sta in quale livello.
     *
     * Comprende anche chi ha lavorato su ticket senza essere nel team: un
     * elenco che mostrasse solo i quattro dell'unita' non permetterebbe di
     * filtrare su uno specialista che ha preso in carico dei ticket.
     */
    public function elencoTeam(): array
    {
        try {
            $st = $this->pdo->query(
                "SELECT t.`nome`,
                        CONCAT(t.`nome`,
                               CASE WHEN t.`sotto_unita` IS NOT NULL AND t.`sotto_unita` <> ''
                                    THEN CONCAT(' — ', t.`sotto_unita`) ELSE '' END) AS etichetta,
                        1 AS in_team,
                        COALESCE(n.`ordina`, LOWER(t.`nome`)) AS ordina
                   FROM `{$this->v['v_cm_sd_team']}` t
              LEFT JOIN `{$this->v['v_cm_nomi']}` n ON n.`forma` = t.`nome`
                  UNION
                 SELECT m.`author_name`,
                        CONCAT(m.`author_name`, ' — ', COALESCE(m.`livello`, 'L2')),
                        0,
                        COALESCE(n2.`ordina`, LOWER(m.`author_name`))
                   FROM `{$this->v['v_cm_sd_messaggi']}` m
              LEFT JOIN `{$this->v['v_cm_nomi']}` n2 ON n2.`forma` = m.`author_name`
                  WHERE m.`author_name` IS NOT NULL AND m.`author_name` <> ''
                    AND m.`author_name` NOT IN (SELECT `nome` FROM `{$this->v['v_cm_sd_team']}`)
                  ORDER BY `in_team` DESC, `ordina`");
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * v1.9.7 — Assenze del team: ferie, permessi, recuperi, malattia, visite.
     *
     * `{$this->v['v_cm_assenze_serie']}` (v1.8.81) usa la forma "Nome Cognome", la stessa dei
     * ticket: il legame e' diretto e non serve il ponte dei nomi.
     *
     * `altre` e' la parte di totale che le quattro voci non spiegano: nei dati
     * esiste almeno un caso con tutte le voci a zero e il totale valorizzato,
     * cioe' un tipo di assenza che la v1.8.81 non aveva classificato.
     *
     * Le VISITE sono contate a parte e NON entrano nel totale: la v1.8.81 aveva
     * accertato che le loro ore sono gia' comprese nelle altre voci — sono
     * riconosciute dalla descrizione, non da un tipo dedicato. Sommarle
     * conterebbe due volte le stesse ore.
     */
    public function assenzeTeam(array $f): array
    {
        $ap = []; $wp = $this->ctrPersone($f, 'a.`operatore`', $ap);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT a.`operatore`                             AS tecnico,
                    COALESCE(n.`ordina`, LOWER(a.`operatore`)) AS ordina,
                    ROUND(SUM(a.`ferie`), 2)                  AS ferie,
                    ROUND(SUM(a.`permessi`), 2)               AS permessi,
                    ROUND(SUM(a.`recuperi`), 2)               AS recuperi,
                    ROUND(SUM(a.`malattia`), 2)               AS malattia,
                    ROUND(SUM(a.`visite`), 2)                 AS visite,
                    ROUND(SUM(a.`totale_assenze`), 2)         AS totale,
                    ROUND(SUM(a.`totale_assenze`) - SUM(a.`ferie` + a.`permessi`
                          + a.`recuperi` + a.`malattia`), 2)   AS altre,
                    COUNT(DISTINCT a.`giorno`)                AS giorni
               FROM `{$this->v['v_cm_assenze_serie']}` a
               JOIN `{$this->v['v_cm_sd_team']}` t ON t.`nome` = a.`operatore`
          LEFT JOIN `{$this->v['v_cm_nomi']}` n    ON n.`forma` = a.`operatore`
              WHERE a.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND a.`operatore` = ?" : "") . $wp . "
              GROUP BY a.`operatore`, n.`ordina`
              ORDER BY ordina, a.`operatore`");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        foreach ($ap as $x) $a[] = $x;
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();

        // le giornate equivalenti: 8 ore, la stessa convenzione della v1.8.96
        foreach ($out as &$r) {
            $r['giornate'] = round((float)$r['totale'] / 8, 1);
        }
        return $out;
    }

    /** Il totale delle assenze del team, per gli indicatori. */
    public function assenzeQuadro(array $f): array
    {
        $ap = []; $wp = $this->ctrPersone($f, 'a.`operatore`', $ap);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT ROUND(SUM(a.`ferie`), 2)          AS ferie,
                    ROUND(SUM(a.`permessi`), 2)       AS permessi,
                    ROUND(SUM(a.`recuperi`), 2)       AS recuperi,
                    ROUND(SUM(a.`malattia`), 2)       AS malattia,
                    ROUND(SUM(a.`visite`), 2)         AS visite,
                    ROUND(SUM(a.`totale_assenze`), 2) AS totale,
                    ROUND(SUM(a.`totale_assenze`) - SUM(a.`ferie` + a.`permessi`
                          + a.`recuperi` + a.`malattia`), 2) AS altre,
                    COUNT(DISTINCT a.`operatore`)     AS persone,
                    COUNT(DISTINCT a.`giorno`)        AS giorni
               FROM `{$this->v['v_cm_assenze_serie']}` a
               JOIN `{$this->v['v_cm_sd_team']}` t ON t.`nome` = a.`operatore`
              WHERE a.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND a.`operatore` = ?" : "") . $wp);
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        foreach ($ap as $x) $a[] = $x;
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        $r['giornate'] = round((float)($r['totale'] ?? 0) / 8, 1);
        return $r;
    }

    /** Andamento mensile delle assenze, per il grafico. */
    public function assenzeMesi(array $f): array
    {
        $ap = []; $wp = $this->ctrPersone($f, 'a.`operatore`', $ap);   // v1.9.78
        $st = $this->pdo->prepare(
            "SELECT a.`anno_mese` AS ym,
                    ROUND(SUM(a.`ferie`), 2)          AS ferie,
                    ROUND(SUM(a.`permessi`), 2)       AS permessi,
                    ROUND(SUM(a.`recuperi`), 2)       AS recuperi,
                    ROUND(SUM(a.`malattia`), 2)       AS malattia,
                    ROUND(SUM(a.`totale_assenze`), 2) AS totale
               FROM `{$this->v['v_cm_assenze_serie']}` a
               JOIN `{$this->v['v_cm_sd_team']}` t ON t.`nome` = a.`operatore`
              WHERE a.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND a.`operatore` = ?" : "") . $wp . "
              GROUP BY a.`anno_mese` ORDER BY a.`anno_mese`");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        foreach ($ap as $x) $a[] = $x;
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.9.10 — OBJ_2: il quadro economico e operativo del perimetro.
     *
     * Il perimetro e' un parametro (`sd_linee_perimetro`): quali contratti siano
     * "Service Desk" e' una domanda aziendale, non tecnica.
     */
    public function obj2Quadro(array $f = []): array
    {
        if ($this->cf($f)->active()) return $this->obj2QuadroFiltrato($f);   // v1.9.78
        try {
            $r = $this->pdo->query("SELECT * FROM `{$this->v['v_cm_sd_obj2_quadro']}`")->fetch(PDO::FETCH_ASSOC);
            return $r ?: [];
        } catch (Throwable $e) { return []; }
    }

    /**
     * v1.9.78 — OBJ_2 ristretto ai contratti selezionati: stesse grandezze di
     * v_cm_sd_obj2_quadro, calcolate su commesse, moduli e ticket collegati.
     */
    private function obj2QuadroFiltrato(array $f): array
    {
        $cf = $this->cf($f); $r = [];
        try {
            $a = []; $w = $cf->sql('code', '`commessa`', $a);
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) AS commesse, SUM(`aperta` = 1) AS commesse_aperte,
                        COUNT(DISTINCT `cliente`) AS clienti, ROUND(SUM(`valore`), 2) AS valore_totale,
                        ROUND(SUM(CASE WHEN `aperta` = 1 THEN `valore` ELSE 0 END), 2) AS valore_aperte,
                        ROUND(SUM(`maturato`), 2) AS maturato, ROUND(SUM(`costi`), 2) AS costi,
                        ROUND(SUM(`margine`), 2) AS margine,
                        CASE WHEN SUM(`valore`) > 0 THEN ROUND(100 * SUM(`margine`) / SUM(`valore`), 1) END AS margine_pct
                   FROM `{$this->v['v_cm_sd_commesse']}` WHERE $w");
            $st->execute($a); $r = $st->fetch(PDO::FETCH_ASSOC) ?: []; $st->closeCursor();

            $a = []; $w = $cf->sql('pid', 'r.`project_id`', $a);
            $st = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT r.`technician_raw`) FROM `cm_intervention_reports` r
                   JOIN `{$this->v['v_cm_sd_commesse']}` c ON c.`commessa_id` = r.`project_id`
                  WHERE r.`technician_raw` <> '' AND $w");
            $st->execute($a); $r['addetti_distinti'] = (int)$st->fetchColumn(); $st->closeCursor();

            $mesi = $this->obj2Addetti(100000, $f);
            $n = count($mesi); $ore = array_sum(array_map(fn($m) => (float)$m['ore'], $mesi));
            $r['addetti_medi_mese'] = $n ? round(array_sum(array_map(fn($m) => (int)$m['addetti'], $mesi)) / $n, 1) : null;
            $r['addetti_picco']     = $n ? max(array_map(fn($m) => (int)$m['addetti'], $mesi)) : null;
            $r['mesi_con_attivita'] = $n;
            $r['ore_totali']        = round($ore, 2);
            $fte = (float)$this->impostazione('sd_ore_mese_fte', 0);
            $r['fte_equivalenti']   = ($n && $fte > 0) ? round($ore / ($n * $fte), 1) : null;

            $a = []; $w = $cf->sql('ticket', 't.`ticket`', $a);
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) AS ticket, SUM(t.`gestione` <> 'mai preso in carico') AS ticket_presi,
                        SUM(t.`gestione` = 'risolto dal Service Desk') AS ticket_risolti,
                        SUM(t.`gestione` = 'escalation di 2 livello verso specialisti') AS ticket_scalati,
                        SUM(t.`gestione` = 'presa in carico diretta da specialisti') AS ticket_diretti,
                        SUM(t.`gestione` = 'mai preso in carico') AS ticket_mai_presi,
                        CASE WHEN SUM(t.`gestione` <> 'mai preso in carico') > 0
                             THEN ROUND(100 * SUM(t.`gestione` = 'escalation di 2 livello verso specialisti')
                                      / SUM(t.`gestione` <> 'mai preso in carico'), 1) END AS escalation_pct
                   FROM `{$this->v['v_cm_sd_ticket']}` t WHERE $w");
            $st->execute($a); $r += ($st->fetch(PDO::FETCH_ASSOC) ?: []); $st->closeCursor();
        } catch (Throwable $e) {}
        return $r;
    }

    /** OBJ_2: il dettaglio per linea di servizio. */
    public function obj2Linee(array $f = []): array
    {
        if ($this->cf($f)->active()) {   // v1.9.78 — stesso calcolo di v_cm_sd_obj2_linee sulla selezione
            try {
                $a = []; $w = $this->cf($f)->sql('code', 'c.`commessa`', $a);
                $st = $this->pdo->prepare(
                    "SELECT c.`codice_linea`, c.`contratto`, c.`modello`, c.`ha_ricavo`,
                            COUNT(*) AS commesse, SUM(c.`aperta` = 1) AS aperte,
                            ROUND(SUM(c.`valore`), 2) AS valore, ROUND(SUM(c.`maturato`), 2) AS maturato,
                            ROUND(SUM(c.`costi`), 2) AS costi, ROUND(SUM(c.`margine`), 2) AS margine,
                            ROUND(SUM(c.`margine_maturato`), 2) AS margine_maturato,
                            CASE WHEN SUM(c.`valore`) > 0 THEN ROUND(100 * SUM(c.`margine`) / SUM(c.`valore`), 1) END AS margine_pct,
                            COUNT(DISTINCT c.`cliente`) AS clienti
                       FROM `{$this->v['v_cm_sd_commesse']}` c WHERE $w
                      GROUP BY c.`codice_linea`, c.`contratto`, c.`modello`, c.`ha_ricavo`
                      ORDER BY valore DESC");
                $st->execute($a);
                $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
                $tot = array_sum(array_map(fn($r) => (float)$r['valore'], $out));
                foreach ($out as &$r) $r['quota_valore_pct'] = $tot > 0 ? round(100 * (float)$r['valore'] / $tot, 1) : null;
                unset($r);
                return $out;
            } catch (Throwable $e) { return []; }
        }
        try {
            return $this->pdo->query(
                "SELECT * FROM `{$this->v['v_cm_sd_obj2_linee']}` ORDER BY `valore` DESC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2: gli addetti mese per mese. */
    public function obj2Addetti(int $mesi = 24, array $f = []): array
    {
        if ($this->cf($f)->active()) {   // v1.9.78 — stesso calcolo di v_cm_sd_addetti_mese sulla selezione
            try {
                $a = []; $w = $this->cf($f)->sql('pid', 'r.`project_id`', $a);
                $st = $this->pdo->prepare(
                    "SELECT DATE_FORMAT(r.`report_date`, '%Y-%m') AS anno_mese,
                            COUNT(DISTINCT r.`technician_raw`) AS addetti, COUNT(*) AS moduli,
                            ROUND(SUM(COALESCE(r.`quantity_hours`, 0)), 2) AS ore,
                            COUNT(DISTINCT r.`project_code`) AS commesse
                       FROM `cm_intervention_reports` r
                       JOIN `{$this->v['v_cm_sd_commesse']}` c ON c.`commessa_id` = r.`project_id`
                      WHERE r.`report_date` IS NOT NULL AND r.`technician_raw` <> '' AND $w
                      GROUP BY anno_mese ORDER BY anno_mese DESC LIMIT " . max(1, $mesi));
                $st->execute($a);
                $out = array_reverse($st->fetchAll(PDO::FETCH_ASSOC)); $st->closeCursor();
                return $out;
            } catch (Throwable $e) { return []; }
        }
        try {
            $st = $this->pdo->query(
                "SELECT * FROM `{$this->v['v_cm_sd_addetti_mese']}`
                  ORDER BY `anno_mese` DESC LIMIT " . max(1, $mesi));
            $out = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2.3: la ripartizione per classe di gestione. */
    public function obj23Ripartizione(array $f = []): array
    {
        if ($this->cf($f)->active()) {   // v1.9.78 — stesso calcolo di v_cm_sd_obj23_ripartizione sui ticket collegati
            try {
                $a = []; $w = $this->cf($f)->sql('ticket', 't.`ticket`', $a);
                $st = $this->pdo->prepare(
                    "SELECT t.`gestione`, COUNT(*) AS ticket, COUNT(DISTINCT t.`coda`) AS code,
                            ROUND(AVG(t.`messaggi`), 1) AS messaggi_medi,
                            ROUND(AVG(CASE WHEN t.`gestione` <> 'mai preso in carico' THEN t.`durata_ore` END), 1) AS durata_media_ore,
                            MIN(t.`aperto_il`) AS dal, MAX(t.`aperto_il`) AS al
                       FROM `{$this->v['v_cm_sd_ticket']}` t WHERE $w
                      GROUP BY t.`gestione` ORDER BY ticket DESC");
                $st->execute($a);
                $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
                $tot = array_sum(array_map(fn($r) => (int)$r['ticket'], $out));
                foreach ($out as &$r) $r['quota_pct'] = $tot > 0 ? round(100 * (int)$r['ticket'] / $tot, 1) : null;
                unset($r);
                return $out;
            } catch (Throwable $e) { return []; }
        }
        try {
            return $this->pdo->query(
                "SELECT * FROM `{$this->v['v_cm_sd_obj23_ripartizione']}` ORDER BY `ticket` DESC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2.3: la ripartizione per coda. */
    public function obj23Code(int $limite = 20, array $f = []): array
    {
        if ($this->cf($f)->active()) {   // v1.9.78 — stesso calcolo di v_cm_sd_obj23_code sui ticket collegati
            try {
                $a = []; $w = $this->cf($f)->sql('ticket', 't.`ticket`', $a);
                $st = $this->pdo->prepare(
                    "SELECT COALESCE(t.`coda`, '(nessuna)') AS coda, COUNT(*) AS ticket,
                            SUM(t.`gestione` = 'risolto dal Service Desk') AS risolti,
                            SUM(t.`gestione` = 'escalation di 2 livello verso specialisti') AS scalati,
                            SUM(t.`gestione` = 'presa in carico diretta da specialisti') AS diretti,
                            SUM(t.`gestione` = 'mai preso in carico') AS mai_presi,
                            CASE WHEN SUM(t.`gestione` <> 'mai preso in carico') > 0
                                 THEN ROUND(100 * SUM(t.`gestione` = 'escalation di 2 livello verso specialisti')
                                          / SUM(t.`gestione` <> 'mai preso in carico'), 1) END AS escalation_pct,
                            ROUND(AVG(CASE WHEN t.`gestione` <> 'mai preso in carico' THEN t.`durata_ore` END), 1) AS durata_media_ore
                       FROM `{$this->v['v_cm_sd_ticket']}` t WHERE $w
                      GROUP BY coda ORDER BY ticket DESC LIMIT " . max(1, $limite));
                $st->execute($a);
                $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
                $tot = array_sum(array_map(fn($r) => (int)$r['ticket'], $out));
                foreach ($out as &$r) $r['quota_pct'] = $tot > 0 ? round(100 * (int)$r['ticket'] / $tot, 1) : null;
                unset($r);
                return $out;
            } catch (Throwable $e) { return []; }
        }
        try {
            return $this->pdo->query(
                "SELECT * FROM `{$this->v['v_cm_sd_obj23_code']}` ORDER BY `ticket` DESC LIMIT " . max(1, $limite))
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2: le commesse del perimetro, per l'export. */
    public function obj2Commesse(int $limite = 2000, array $f = []): array
    {
        if ($this->cf($f)->active()) {   // v1.9.78
            try {
                $a = []; $w = $this->cf($f)->sql('code', '`commessa`', $a);
                $st = $this->pdo->prepare("SELECT * FROM `{$this->v['v_cm_sd_commesse']}` WHERE $w ORDER BY `valore` DESC LIMIT " . max(1, $limite));
                $st->execute($a);
                $out = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
                return $out;
            } catch (Throwable $e) { return []; }
        }
        try {
            return $this->pdo->query(
                "SELECT * FROM `{$this->v['v_cm_sd_commesse']}` ORDER BY `valore` DESC LIMIT " . max(1, $limite))
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /**
     * v1.9.11 — OBJ_2.1/2.2: l'attivita' del Service Desk dai MODULI.
     *
     * I moduli portano `project_id`, i ticket no: e' il raccordo che mancava
     * alla v1.9.10. La natura fatturabile o interna viene dalla commessa
     * (`has_revenue`), non dal ticket.
     */
    public function obj21Quadro(array $f = []): array
    {
        try {
            if ((empty($f['from']) || empty($f['to'])) && !$this->cf($f)->active()) {
                $r = $this->pdo->query("SELECT * FROM `{$this->v['v_cm_sd_obj21_quadro']}`")->fetch(PDO::FETCH_ASSOC);
                return $r ?: [];
            }
            $ac = []; $wc = $this->ctr($f, 'code', '`commessa`', $ac);   // v1.9.78
            $st = $this->pdo->prepare(
                "SELECT (SELECT COUNT(*) FROM `{$this->v['v_cm_sd_tecnici_uo']}`)          AS tecnici_uo,
                        COUNT(*)                                             AS interventi,
                        ROUND(SUM(`ore`), 2)                                 AS ore,
                        SUM(`natura` = 'fatturabile')                        AS interventi_fatt,
                        ROUND(SUM(CASE WHEN `natura`='fatturabile' THEN `ore` ELSE 0 END), 2) AS ore_fatt,
                        ROUND(SUM(CASE WHEN `natura`='fatturabile'
                                  THEN `valore_addebitato` ELSE 0 END), 2)   AS valore_addebitato,
                        ROUND(SUM(CASE WHEN `natura`='fatturabile'
                                  THEN `valore_listino` ELSE 0 END), 2)      AS valore_listino_fatt,
                        SUM(`natura` = 'interna')                            AS interventi_int,
                        ROUND(SUM(CASE WHEN `natura`='interna' THEN `ore` ELSE 0 END), 2) AS ore_int,
                        ROUND(SUM(CASE WHEN `natura`='interna'
                                  THEN `valore_listino` ELSE 0 END), 2)      AS valore_listino_int,
                        CASE WHEN SUM(`ore`) > 0
                             THEN ROUND(100 * SUM(CASE WHEN `natura`='interna' THEN `ore` ELSE 0 END)
                                      / SUM(`ore`), 1) END                   AS quota_interna_pct,
                        SUM(`tariffa_ora` IS NOT NULL)                       AS righe_con_tariffa,
                        (SELECT COUNT(*) FROM `cm_sd_listino` WHERE `tariffa_ora` IS NOT NULL) AS linee_a_listino,
                        (SELECT COUNT(*) FROM `cm_sd_listino`)               AS linee_listino
                   FROM `{$this->v['v_cm_sd_attivita']}`
                  WHERE `giorno` BETWEEN ? AND ?"
                  . ($f['tec'] !== '' ? " AND `tecnico` = ?" : "") . $wc);
            $a = [$f['from'], $f['to']];
            if (($f['tec'] ?? '') !== '') $a[] = $f['tec'];
            foreach ($ac as $x) $a[] = $x;
            $st->execute($a);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $st->closeCursor();
            return $r;
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2.1 — l'attivita' fatturabile per contratto. */
    public function obj21Fatturabili(array $f = []): array
    {
        return $this->obj2xDettaglio($f, 'fatturabile');
    }

    /** OBJ_2.2 — l'attivita' interna per contratto. */
    public function obj22Interne(array $f = []): array
    {
        return $this->obj2xDettaglio($f, 'interna');
    }

    /**
     * Il dettaglio per contratto, filtrato per natura.
     *
     * Una funzione sola per le due nature: le colonne sono le stesse e
     * duplicarla avrebbe significato correggere due volte ogni modifica.
     */
    private function obj2xDettaglio(array $f, string $natura): array
    {
        try {
            $w = "`natura` = ?"; $a = [$natura];
            if (!empty($f['from']) && !empty($f['to'])) {
                $w .= " AND `giorno` BETWEEN ? AND ?";
                $a[] = $f['from']; $a[] = $f['to'];
            }
            if (($f['tec'] ?? '') !== '') { $w .= " AND `tecnico` = ?"; $a[] = $f['tec']; }
            $w .= $this->ctr($f, 'code', '`commessa`', $a);   // v1.9.78

            $st = $this->pdo->prepare(
                "SELECT `codice_linea`, `contratto`, `modello`,
                        COUNT(*)                                    AS interventi,
                        COUNT(DISTINCT `tecnico`)                   AS tecnici,
                        COUNT(DISTINCT `commessa`)                  AS commesse,
                        COUNT(DISTINCT `cliente`)                   AS clienti,
                        COUNT(DISTINCT `giorno`)                    AS giornate,
                        ROUND(SUM(`ore`), 2)                        AS ore,
                        ROUND(SUM(`ore_extra`), 2)                  AS ore_extra,
                        ROUND(SUM(`valore_addebitato`), 2)          AS valore_addebitato,
                        SUM(`valore_addebitato` IS NOT NULL)        AS righe_addebitate,
                        ROUND(SUM(`valore_listino`), 2)             AS valore_listino,
                        MAX(`tariffa_ora`)                          AS tariffa_ora
                   FROM `{$this->v['v_cm_sd_attivita']}`
                  WHERE $w
                  GROUP BY `codice_linea`, `contratto`, `modello`
                  ORDER BY ore DESC");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();

            $tot = 0.0;
            foreach ($out as $r) $tot += (float)$r['ore'];
            foreach ($out as &$r)
                $r['quota_ore_pct'] = $tot > 0 ? round(100 * (float)$r['ore'] / $tot, 1) : null;
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2.3 — la ripartizione fatturabile/interna per tecnico dell'unita'. */
    public function obj23Tecnici(array $f = []): array
    {
        try {
            $w = "1=1"; $a = [];
            if (!empty($f['from']) && !empty($f['to'])) {
                $w = "`giorno` BETWEEN ? AND ?"; $a[] = $f['from']; $a[] = $f['to'];
            }
            $w .= $this->ctr($f, 'code', '`commessa`', $a);   // v1.9.78
            $st = $this->pdo->prepare(
                "SELECT t.`nome` AS tecnico, t.`unita`, t.`ordina`,
                        COALESCE(a.`interventi`, 0)              AS interventi,
                        ROUND(COALESCE(a.`ore`, 0), 2)           AS ore,
                        ROUND(COALESCE(a.`ore_fatt`, 0), 2)      AS ore_fatturabili,
                        ROUND(COALESCE(a.`ore_int`, 0), 2)       AS ore_interne,
                        CASE WHEN COALESCE(a.`ore`, 0) > 0
                             THEN ROUND(100 * a.`ore_fatt` / a.`ore`, 1) END AS quota_fatturabile_pct,
                        ROUND(COALESCE(a.`valore_addebitato`, 0), 2) AS valore_addebitato,
                        ROUND(COALESCE(a.`valore_listino`, 0), 2)    AS valore_listino,
                        COALESCE(a.`commesse`, 0)                AS commesse,
                        COALESCE(a.`giornate`, 0)                AS giornate
                   FROM `{$this->v['v_cm_sd_tecnici_uo']}` t
              LEFT JOIN (SELECT `tecnico`, COUNT(*) AS interventi, SUM(`ore`) AS ore,
                                SUM(CASE WHEN `natura`='fatturabile' THEN `ore` ELSE 0 END) AS ore_fatt,
                                SUM(CASE WHEN `natura`='interna' THEN `ore` ELSE 0 END) AS ore_int,
                                SUM(`valore_addebitato`) AS valore_addebitato,
                                SUM(`valore_listino`) AS valore_listino,
                                COUNT(DISTINCT `commessa`) AS commesse,
                                COUNT(DISTINCT `giorno`) AS giornate
                           FROM `{$this->v['v_cm_sd_attivita']}` WHERE $w
                          GROUP BY `tecnico`) a ON a.`tecnico` = t.`nome`
                  ORDER BY t.`ordina`, t.`nome`");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** Il listino, per il pannello e l'export. */
    public function listino(): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM `cm_sd_listino` ORDER BY `service_line`")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /**
     * v1.9.15 — Riepilogo costi per fascia e contratto.
     *
     * Le tariffe sono per scaglione di durata del SINGOLO intervento: fino a
     * 4 ore la tariffa oraria, oltre la mezza giornata, da 8 la giornata.
     * Il valore e' ore x tariffa, non un pacchetto forfetario — tre mezze
     * giornate valgono 14 h x 87,50 e non 3 x 350,00.
     */
    public function costiRiepilogo(array $f = []): array
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
              ORDER BY `codice_linea`, `fascia`, FIELD(`scaglione`,'ora','mezza_giornata','giornata')");
    }

    /** Il riepilogo per singola commessa: secondo foglio del template. */
    public function costiPerCommessa(array $f = []): array
    {
        return $this->costiQuery($f,
            "SELECT `commessa`, `codice_linea`, `contratto`, `cliente`, `fascia`, `scaglione`,
                    COALESCE(`descrizione_tariffa`,
                             CONCAT('Fascia ', `fascia`, ' (', `scaglione`, ')')) AS descrizione_tariffa,
                    `reperibilita`,
                    COUNT(*) AS interventi, ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(`valore`), 2) AS valore, MAX(`tariffa_ora`) AS tariffa_ora",
            "GROUP BY `commessa`, `codice_linea`, `contratto`, `cliente`, `fascia`, `scaglione`,
                      descrizione_tariffa, `reperibilita`
              ORDER BY `codice_linea`, `commessa`, `fascia`,
                       FIELD(`scaglione`,'ora','mezza_giornata','giornata')");
    }

    /** Il quadro complessivo dei costi. */
    public function costiQuadro(array $f = []): array
    {
        $r = $this->costiQuery($f,
            "SELECT COUNT(*) AS interventi, ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(`valore`), 2) AS valore,
                    SUM(`fascia` = 'C') AS interventi_ordinario,
                    ROUND(SUM(CASE WHEN `fascia`='C' THEN `ore` ELSE 0 END), 2) AS ore_ordinario,
                    ROUND(SUM(CASE WHEN `fascia`='C' THEN `valore` ELSE 0 END), 2) AS valore_ordinario,
                    SUM(`fascia` = 'D') AS interventi_extra,
                    ROUND(SUM(CASE WHEN `fascia`='D' THEN `ore` ELSE 0 END), 2) AS ore_extra,
                    ROUND(SUM(CASE WHEN `fascia`='D' THEN `valore` ELSE 0 END), 2) AS valore_extra,
                    SUM(`reperibilita` = 'SI') AS interventi_reperibilita,
                    COUNT(DISTINCT `commessa`) AS commesse,
                    COUNT(DISTINCT `codice_linea`) AS linee,
                    COUNT(DISTINCT `tecnico`) AS tecnici,
                    SUM(`tariffa_ora` IS NULL) AS righe_senza_tariffa",
            "");
        return $r[0] ?? [];
    }

    /**
     * Il corpo comune delle tre interrogazioni sui costi.
     *
     * Il filtro e' identico e la parte variabile e' solo SELECT e GROUP BY:
     * ripeterlo tre volte avrebbe significato correggere tre volte ogni
     * modifica al periodo o al tecnico.
     */
    private function costiQuery(array $f, string $select, string $coda): array
    {
        try {
            $w = "1=1"; $a = [];
            if (!empty($f['from']) && !empty($f['to'])) {
                $w = "`giorno` BETWEEN ? AND ?"; $a[] = $f['from']; $a[] = $f['to'];
            }
            if (($f['tec'] ?? '') !== '') { $w .= " AND `tecnico` = ?"; $a[] = $f['tec']; }
            $w .= $this->ctr($f, 'code', '`commessa`', $a);   // v1.9.78
            $st = $this->pdo->prepare("$select FROM `{$this->v['v_cm_sd_costi_valorizzati']}` WHERE $w $coda");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** Il listino per fascia e scaglione, per il pannello e l'export. */
    public function costiTariffe(): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM `cm_sd_tariffe`
                  ORDER BY `service_line`, `fascia`,
                           FIELD(`scaglione`,'ora','mezza_giornata','giornata')")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** Il team di primo livello, per dichiarare su cosa si basa la classificazione. */
    public function team(): array
    {
        try {
            return $this->pdo->query(
                "SELECT `nome`, `sotto_unita` FROM `{$this->v['v_cm_sd_team']}` ORDER BY `nome`")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
