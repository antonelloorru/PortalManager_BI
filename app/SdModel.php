<?php
/**
 * SdModel — letture per la sezione Service Desk.
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

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

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

    /** Clausola condivisa da pannello, elenchi ed export: un solo punto di verita'. */
    private function where(array $f): array
    {
        // v1.8.88 — il filtro tecnico si applica con un JOIN, non con IN o
        // EXISTS.
        //
        // Su `v_cm_sd_ticket`, che e' una vista costruita su altre viste,
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

        // il JOIN precede i parametri della WHERE nell'ordine di sostituzione
        $join = ''; $pre = [];
        if (!empty($f['tec'])) {
            $join = " JOIN `v_cm_sd_presa_carico` pc
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
                  FROM `v_cm_sd_ticket` t $j
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
            "SELECT COUNT(*) FROM `v_cm_sd_ticket` t $j
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
               FROM `v_cm_sd_ticket` t $j
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
    public function trend(array $f, int $mesi = 12): array
    {
        $jT = ''; $argsT = [];
        if (!empty($f['tec'])) {
            $jT = " JOIN `v_cm_sd_presa_carico` pc
                      ON pc.`ticket` = t.`ticket` AND pc.`tecnico` = ? ";
            $argsT[] = $f['tec'];
        }
        // v1.9.9 — il periodo impostato, non "gli ultimi N mesi da to".
        //
        // Il grafico mostrava dodici mesi a ritroso dalla data finale, ignorando
        // quella iniziale: chi sceglieva un trimestre vedeva un anno, e i numeri
        // del grafico non corrispondevano a quelli degli indicatori sopra.
        array_push($argsT, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59');

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
               FROM `v_cm_sd_ticket` t $jT
              WHERE t.`aperto_il` IS NOT NULL
                AND t.`aperto_il` BETWEEN ? AND ?
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
               FROM `v_cm_sd_ticket` t $j
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
               FROM `v_cm_sd_messaggi` m
          LEFT JOIN `v_cm_nomi` n ON n.`forma` = m.`author_name`
              WHERE m.`received_at` BETWEEN ? AND ?
                AND m.`author_name` IS NOT NULL AND m.`author_name` <> ''
                AND (? = '' OR m.`author_name` = ?)
              GROUP BY m.`author_name`, n.`ordina`
              ORDER BY n.`ordina` IS NULL, n.`ordina`, m.`author_name`");
        $st->execute([$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59',
                      $f['tec'] ?? '', $f['tec'] ?? '']);
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
               FROM `v_cm_sd_ticket` t $j
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
            "SELECT * FROM `v_cm_sd_scheda_tecnico` WHERE `tecnico` = ?");
        $st->execute([$tecnico]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        if (!$r) return [];

        // le misure del PERIODO selezionato, distinte da quelle complessive
        $st2 = $this->pdo->prepare(
            "SELECT COUNT(*) AS messaggi,
                    SUM(m.`msg_type` = 'SUPPORT_MSG')   AS risposte,
                    SUM(m.`msg_type` = 'INTERNAL_NOTE') AS note,
                    COUNT(DISTINCT m.`ticket_code`)     AS ticket,
                    COUNT(DISTINCT m.`queue_name`)      AS code,
                    COUNT(DISTINCT DATE(m.`received_at`)) AS giorni
               FROM `v_cm_sd_messaggi` m
              WHERE m.`author_name` = ? AND m.`received_at` BETWEEN ? AND ?");
        $st2->execute([$tecnico, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59']);
        $r['periodo'] = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
        $st2->closeCursor();

        $st3 = $this->pdo->prepare(
            "SELECT COUNT(*) AS presi,
                    SUM(t.`gestione` = 'risolto dal Service Desk')                  AS risolti,
                    SUM(t.`gestione` = 'escalation di 2 livello verso specialisti') AS scalati,
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.`aperto_il`, p.`prima_risposta`)) / 60, 1) AS ore_1a
               FROM `v_cm_sd_presa_carico` p
               JOIN `v_cm_sd_ticket` t ON t.`ticket` = p.`ticket`
              WHERE p.`tecnico` = ? AND p.`prima_risposta` BETWEEN ? AND ?");
        $st3->execute([$tecnico, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59']);
        $r['periodo_esito'] = $st3->fetch(PDO::FETCH_ASSOC) ?: [];
        $st3->closeCursor();

        return $r;
    }

    /** Andamento mensile del singolo, ultimi N mesi. */
    public function schedaMesi(string $tecnico, array $f = [], int $mesi = 12): array
    {
        // v1.9.9 — il periodo impostato. Il grafico prendeva gli ultimi 12 mesi
        // qualunque cosa fosse selezionato nei filtri.
        $w = "`tecnico` = ?"; $a = [$tecnico];
        if (!empty($f['from']) && !empty($f['to'])) {
            $w .= " AND `anno_mese` BETWEEN ? AND ?";
            $a[] = substr((string)$f['from'], 0, 7);
            $a[] = substr((string)$f['to'], 0, 7);
        }
        $st = $this->pdo->prepare(
            "SELECT `anno_mese`, `messaggi`, `risposte`, `note`, `ticket`
               FROM `v_cm_sd_tecnico_mese`
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
        $st = $this->pdo->prepare(
            "SELECT COALESCE(m.`queue_name`, '(nessuna)') AS coda,
                    COUNT(*) AS messaggi, COUNT(DISTINCT m.`ticket_code`) AS ticket
               FROM `v_cm_sd_messaggi` m
              WHERE $w GROUP BY coda ORDER BY ticket DESC");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** I ticket presi in carico dal singolo nel periodo. */
    public function schedaTicket(string $tecnico, array $f, int $limite = 100): array
    {
        $st = $this->pdo->prepare(
            "SELECT t.`ticket`, t.`oggetto`, t.`coda`, t.`stato`, t.`gestione`,
                    t.`messaggi`, t.`aperto_il`, t.`durata_ore`,
                    ROUND(TIMESTAMPDIFF(MINUTE, t.`aperto_il`, p.`prima_risposta`) / 60, 1) AS ore_1a
               FROM `v_cm_sd_presa_carico` p
               JOIN `v_cm_sd_ticket` t ON t.`ticket` = p.`ticket`
              WHERE p.`tecnico` = ? AND p.`prima_risposta` BETWEEN ? AND ?
              ORDER BY t.`aperto_il` DESC LIMIT " . (int)$limite);
        $st->execute([$tecnico, $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59']);
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
               JOIN `v_cm_sd_nome_moduli` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE b.`nome_ticket` = ? AND r.`report_date` BETWEEN ? AND ?
              GROUP BY contratto, codice, modello, ha_ricavo
              ORDER BY ore DESC");
        $st->execute([$tecnico, $f['from'], $f['to']]);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Riepilogo dei moduli nel periodo: totale, a ricavo, interne. */
    public function moduliRiepilogo(string $tecnico, array $f): array
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*)                                      AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    ROUND(SUM(CASE WHEN COALESCE(cm.`has_revenue`,1) = 1
                              THEN COALESCE(r.`quantity_hours`,0) ELSE 0 END), 2) AS ore_ricavo,
                    ROUND(SUM(COALESCE(r.`extra_hours`,0)), 2)    AS ore_extra,
                    COUNT(DISTINCT r.`project_code`)              AS commesse,
                    COUNT(DISTINCT COALESCE(cm.`model`,'x'))      AS modelli
               FROM `cm_intervention_reports` r
               JOIN `v_cm_sd_nome_moduli` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE b.`nome_ticket` = ? AND r.`report_date` BETWEEN ? AND ?");
        $st->execute([$tecnico, $f['from'], $f['to']]);
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
        $st = $this->pdo->prepare(
            "SELECT COALESCE(p.`service_line`, '(nessuna)')   AS codice,
                    COALESCE(cm.`label`, p.`service_line`)    AS etichetta,
                    COALESCE(cm.`model`, 'da_classificare')   AS modello,
                    COALESCE(cm.`has_revenue`, 1)             AS ha_ricavo,
                    COUNT(*)                                  AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    COUNT(DISTINCT r.`project_code`)          AS commesse
               FROM `cm_intervention_reports` r
               JOIN `v_cm_sd_nome_moduli` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE b.`nome_ticket` = ? AND r.`report_date` BETWEEN ? AND ?
              GROUP BY codice, etichetta, modello, ha_ricavo
              ORDER BY ore DESC");
        $st->execute([$tecnico, $f['from'], $f['to']]);
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
        $st = $this->pdo->prepare(
            "SELECT COALESCE(p.`service_line`, '(nessuna)')   AS codice,
                    COALESCE(cm.`label`, p.`service_line`)    AS etichetta,
                    COALESCE(cm.`has_revenue`, 1)             AS ha_ricavo,
                    COUNT(*)                                  AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    COUNT(DISTINCT b.`nome_ticket`)           AS tecnici,
                    COUNT(DISTINCT r.`project_code`)          AS commesse
               FROM `cm_intervention_reports` r
               JOIN `v_cm_sd_nome_moduli` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p         ON p.`id` = r.`project_id`
          LEFT JOIN `cm_contract_models` cm ON cm.`service_line` = p.`service_line`
              WHERE r.`report_date` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND b.`nome_ticket` = ?" : "") . "
              GROUP BY codice, etichetta, ha_ricavo
              ORDER BY ore DESC");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
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
        $st = $this->pdo->prepare(
            "SELECT COALESCE(az.`name`, '(non attribuita)')   AS azienda,
                    COUNT(*)                                  AS moduli,
                    ROUND(SUM(COALESCE(r.`quantity_hours`,0)), 2) AS ore,
                    COUNT(DISTINCT b.`nome_ticket`)           AS tecnici,
                    COUNT(DISTINCT r.`project_code`)          AS commesse,
                    COUNT(DISTINCT p.`service_line`)          AS linee
               FROM `cm_intervention_reports` r
               JOIN `v_cm_sd_nome_moduli` b ON b.`nome_moduli` = r.`technician_raw`
          LEFT JOIN `cm_projects` p  ON p.`id` = r.`project_id`
          LEFT JOIN `companies` az   ON az.`id` = p.`exec_company_id`
              WHERE r.`report_date` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND b.`nome_ticket` = ?" : "") . "
              GROUP BY azienda ORDER BY ore DESC");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
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
        $st = $this->pdo->prepare(
            "SELECT COALESCE(m.`queue_name`, '(nessuna)')   AS coda,
                    COUNT(DISTINCT m.`ticket_code`)         AS ticket,
                    COUNT(*)                                AS messaggi,
                    COALESCE(pc.`presi`, 0)                 AS presi_in_carico,
                    COALESCE(tot.`ticket_coda`, 0)          AS ticket_coda,
                    CASE WHEN COALESCE(tot.`ticket_coda`, 0) > 0
                         THEN ROUND(100 * COUNT(DISTINCT m.`ticket_code`)
                                  / tot.`ticket_coda`, 1) END AS quota_coda_pct
               FROM `v_cm_sd_messaggi` m
          LEFT JOIN (SELECT COALESCE(x.`queue_name`,'(nessuna)') AS coda,
                            COUNT(DISTINCT x.`ticket_code`) AS ticket_coda
                       FROM `cm_sd_messages` x WHERE $wt GROUP BY coda) tot
                 ON tot.`coda` = COALESCE(m.`queue_name`, '(nessuna)')
          LEFT JOIN (SELECT `tecnico`, COALESCE(`coda`,'(nessuna)') AS coda, COUNT(*) AS presi
                       FROM `v_cm_sd_presa_carico` GROUP BY `tecnico`, coda) pc
                 ON pc.`tecnico` = ? AND pc.`coda` = COALESCE(m.`queue_name`, '(nessuna)')
              WHERE $wm
              GROUP BY coda, pc.`presi`, tot.`ticket_coda`
              ORDER BY ticket DESC");
        $st->execute(array_merge($at, [$tecnico], $a));
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
                // v1.9.9 — `v_cm_sd_operativita` NON ha la colonna `ordina`:
                // l'ORDER BY su di essa faceva fallire la query, e il try/catch
                // restituiva un elenco vuoto. La tabella dei componenti era
                // vuota da prima di questa release, senza alcun segnale.
                // La chiave di ordinamento viene da `v_cm_nomi`.
                return $this->pdo->query(
                    "SELECT o.*, COALESCE(n.`ordina`, LOWER(o.`tecnico`)) AS ordina
                       FROM `v_cm_sd_operativita` o
                  LEFT JOIN `v_cm_nomi` n ON n.`forma` = o.`tecnico`
                      WHERE o.`livello` = 'L1'
                      ORDER BY ordina, o.`tecnico`")
                    ->fetchAll(PDO::FETCH_ASSOC);
            }
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
                   FROM `v_cm_sd_operativita` o
              LEFT JOIN `v_cm_nomi` n ON n.`forma` = o.`tecnico`
              LEFT JOIN (SELECT pc.`tecnico`, COUNT(*) AS presi,
                                SUM(t.`gestione`='risolto dal Service Desk') AS risolti,
                                SUM(t.`gestione`='escalation di 2 livello verso specialisti') AS scalati,
                                ROUND(AVG(TIMESTAMPDIFF(MINUTE,t.`aperto_il`,pc.`prima_risposta`))/60,1) AS ore_1a
                           FROM `v_cm_sd_presa_carico` pc
                           JOIN `v_cm_sd_ticket` t ON t.`ticket` = pc.`ticket`
                          WHERE pc.`prima_risposta` BETWEEN ? AND ?
                          GROUP BY pc.`tecnico`) p ON p.`tecnico` = o.`tecnico`
              LEFT JOIN (SELECT `author_name`, COUNT(*) AS messaggi,
                                COUNT(DISTINCT `queue_name`) AS code
                           FROM `v_cm_sd_messaggi`
                          WHERE `received_at` BETWEEN ? AND ?
                          GROUP BY `author_name`) ms ON ms.`author_name` = o.`tecnico`
              LEFT JOIN (SELECT `tecnico`, COUNT(*) AS moduli, SUM(`ore`) AS ore,
                                SUM(CASE WHEN `ha_ricavo`=1 THEN `ore` ELSE 0 END) AS ore_ric,
                                COUNT(DISTINCT `commessa`) AS commesse
                           FROM `v_cm_sd_moduli` WHERE `giorno` BETWEEN ? AND ?
                          GROUP BY `tecnico`) md ON md.`tecnico` = o.`tecnico`
                  WHERE o.`livello` = 'L1'
                  ORDER BY ordina, o.`tecnico`");
            $st->execute([$f['from'].' 00:00:00', $f['to'].' 23:59:59',
                          $f['from'].' 00:00:00', $f['to'].' 23:59:59',
                          $f['from'], $f['to']]);
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
                "SELECT * FROM `v_cm_sd_scheda_tecnico`
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

    /**
     * v1.9.5 — Il quadro di squadra.
     *
     * Ticket e moduli su colonne distinte, mai sommati: un ticket puo' generare
     * un modulo e la sovrapposizione non e' quantificabile.
     */
    public function teamQuadro(array $f): array
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*)                                              AS moduli,
                    ROUND(SUM(m.`ore`), 2)                                AS ore,
                    ROUND(SUM(m.`ore_extra`), 2)                          AS ore_extra,
                    ROUND(SUM(CASE WHEN m.`ha_ricavo` = 1 THEN m.`ore` ELSE 0 END), 2) AS ore_ricavo,
                    ROUND(SUM(CASE WHEN m.`fascia_oraria` = 'fuori orario'
                              THEN m.`ore` ELSE 0 END), 2)                AS ore_fuori,
                    ROUND(SUM(CASE WHEN m.`fascia_oraria` = 'in orario'
                              THEN m.`ore` ELSE 0 END), 2)                AS ore_in_orario,
                    COUNT(DISTINCT m.`tecnico`)                           AS tecnici,
                    COUNT(DISTINCT m.`commessa`)                          AS commesse,
                    COUNT(DISTINCT m.`codice_linea`)                      AS linee,
                    COUNT(DISTINCT CONCAT(m.`tecnico`, '|', m.`giorno`))  AS giornate_uomo
               FROM `v_cm_sd_moduli` m
              WHERE m.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND m.`tecnico` = ?" : ""));
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
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
               FROM `v_cm_sd_team` t
          LEFT JOIN `v_cm_nomi` nm ON nm.`forma` = t.`nome`
          LEFT JOIN (SELECT p.`tecnico`, COUNT(*) AS presi
                       FROM `v_cm_sd_presa_carico` p
                      WHERE p.`prima_risposta` BETWEEN ? AND ?
                      GROUP BY p.`tecnico`) pc ON pc.`tecnico` = t.`nome`
          LEFT JOIN (SELECT `tecnico`, COUNT(*) AS moduli, SUM(`ore`) AS ore,
                            SUM(`ore_extra`) AS ore_extra,
                            SUM(CASE WHEN `fascia_oraria`='in orario' THEN `ore` ELSE 0 END) AS ore_in,
                            SUM(CASE WHEN `fascia_oraria`='fuori orario' THEN `ore` ELSE 0 END) AS ore_fuori,
                            SUM(CASE WHEN `ha_ricavo`=1 THEN `ore` ELSE 0 END) AS ore_ricavo,
                            COUNT(DISTINCT `commessa`) AS commesse,
                            COUNT(DISTINCT `codice_linea`) AS linee,
                            COUNT(DISTINCT `giorno`) AS giornate
                       FROM `v_cm_sd_moduli`
                      WHERE `giorno` BETWEEN ? AND ?
                      GROUP BY `tecnico`) md ON md.`tecnico` = t.`nome`
              ORDER BY ordina, t.`nome`");
        $st->execute([$f['from'].' 00:00:00', $f['to'].' 23:59:59', $f['from'], $f['to']]);
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
        $st = $this->pdo->prepare(
            "SELECT `fascia_oraria`, COUNT(*) AS interventi, ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(`ore_extra`), 2) AS ore_extra,
                    COUNT(DISTINCT `tecnico`) AS tecnici,
                    COUNT(DISTINCT `giorno`) AS giornate,
                    ROUND(AVG(`ore`), 2) AS ore_medie
               FROM `v_cm_sd_moduli`
              WHERE `giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND `tecnico` = ?" : "") . "
              GROUP BY `fascia_oraria` ORDER BY ore DESC");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Interventi e ore per tipologia di contratto, con la fascia. */
    public function teamContratto(array $f): array
    {
        $st = $this->pdo->prepare(
            "SELECT `codice_linea`, `contratto`, `modello`, `ha_ricavo`,
                    COUNT(*) AS interventi, ROUND(SUM(`ore`), 2) AS ore,
                    ROUND(SUM(CASE WHEN `fascia_oraria`='in orario' THEN `ore` ELSE 0 END), 2) AS ore_in,
                    ROUND(SUM(CASE WHEN `fascia_oraria`='fuori orario' THEN `ore` ELSE 0 END), 2) AS ore_fuori,
                    ROUND(SUM(`ore_extra`), 2) AS ore_extra,
                    COUNT(DISTINCT `tecnico`) AS tecnici,
                    COUNT(DISTINCT `commessa`) AS commesse
               FROM `v_cm_sd_moduli`
              WHERE `giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND `tecnico` = ?" : "") . "
              GROUP BY `codice_linea`, `contratto`, `modello`, `ha_ricavo`
              ORDER BY ore DESC");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
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
                   FROM `v_cm_sd_team` t
              LEFT JOIN `v_cm_nomi` n ON n.`forma` = t.`nome`
                  UNION
                 SELECT m.`author_name`,
                        CONCAT(m.`author_name`, ' — ', COALESCE(m.`livello`, 'L2')),
                        0,
                        COALESCE(n2.`ordina`, LOWER(m.`author_name`))
                   FROM `v_cm_sd_messaggi` m
              LEFT JOIN `v_cm_nomi` n2 ON n2.`forma` = m.`author_name`
                  WHERE m.`author_name` IS NOT NULL AND m.`author_name` <> ''
                    AND m.`author_name` NOT IN (SELECT `nome` FROM `v_cm_sd_team`)
                  ORDER BY `in_team` DESC, `ordina`");
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /**
     * v1.9.7 — Assenze del team: ferie, permessi, recuperi, malattia, visite.
     *
     * `v_cm_assenze_serie` (v1.8.81) usa la forma "Nome Cognome", la stessa dei
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
               FROM `v_cm_assenze_serie` a
               JOIN `v_cm_sd_team` t ON t.`nome` = a.`operatore`
          LEFT JOIN `v_cm_nomi` n    ON n.`forma` = a.`operatore`
              WHERE a.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND a.`operatore` = ?" : "") . "
              GROUP BY a.`operatore`, n.`ordina`
              ORDER BY ordina, a.`operatore`");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
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
               FROM `v_cm_assenze_serie` a
               JOIN `v_cm_sd_team` t ON t.`nome` = a.`operatore`
              WHERE a.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND a.`operatore` = ?" : ""));
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        $r['giornate'] = round((float)($r['totale'] ?? 0) / 8, 1);
        return $r;
    }

    /** Andamento mensile delle assenze, per il grafico. */
    public function assenzeMesi(array $f): array
    {
        $st = $this->pdo->prepare(
            "SELECT a.`anno_mese` AS ym,
                    ROUND(SUM(a.`ferie`), 2)          AS ferie,
                    ROUND(SUM(a.`permessi`), 2)       AS permessi,
                    ROUND(SUM(a.`recuperi`), 2)       AS recuperi,
                    ROUND(SUM(a.`malattia`), 2)       AS malattia,
                    ROUND(SUM(a.`totale_assenze`), 2) AS totale
               FROM `v_cm_assenze_serie` a
               JOIN `v_cm_sd_team` t ON t.`nome` = a.`operatore`
              WHERE a.`giorno` BETWEEN ? AND ?"
              . ($f['tec'] !== '' ? " AND a.`operatore` = ?" : "") . "
              GROUP BY a.`anno_mese` ORDER BY a.`anno_mese`");
        $a = [$f['from'], $f['to']];
        if ($f['tec'] !== '') $a[] = $f['tec'];
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
    public function obj2Quadro(): array
    {
        try {
            $r = $this->pdo->query("SELECT * FROM `v_cm_sd_obj2_quadro`")->fetch(PDO::FETCH_ASSOC);
            return $r ?: [];
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2: il dettaglio per linea di servizio. */
    public function obj2Linee(): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM `v_cm_sd_obj2_linee` ORDER BY `valore` DESC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2: gli addetti mese per mese. */
    public function obj2Addetti(int $mesi = 24): array
    {
        try {
            $st = $this->pdo->query(
                "SELECT * FROM `v_cm_sd_addetti_mese`
                  ORDER BY `anno_mese` DESC LIMIT " . max(1, $mesi));
            $out = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
            $st->closeCursor();
            return $out;
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2.3: la ripartizione per classe di gestione. */
    public function obj23Ripartizione(): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM `v_cm_sd_obj23_ripartizione` ORDER BY `ticket` DESC")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2.3: la ripartizione per coda. */
    public function obj23Code(int $limite = 20): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM `v_cm_sd_obj23_code` ORDER BY `ticket` DESC LIMIT " . max(1, $limite))
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }

    /** OBJ_2: le commesse del perimetro, per l'export. */
    public function obj2Commesse(int $limite = 2000): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM `v_cm_sd_commesse` ORDER BY `valore` DESC LIMIT " . max(1, $limite))
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
            if (empty($f['from']) || empty($f['to'])) {
                $r = $this->pdo->query("SELECT * FROM `v_cm_sd_obj21_quadro`")->fetch(PDO::FETCH_ASSOC);
                return $r ?: [];
            }
            $st = $this->pdo->prepare(
                "SELECT (SELECT COUNT(*) FROM `v_cm_sd_tecnici_uo`)          AS tecnici_uo,
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
                   FROM `v_cm_sd_attivita`
                  WHERE `giorno` BETWEEN ? AND ?"
                  . ($f['tec'] !== '' ? " AND `tecnico` = ?" : ""));
            $a = [$f['from'], $f['to']];
            if (($f['tec'] ?? '') !== '') $a[] = $f['tec'];
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
                   FROM `v_cm_sd_attivita`
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
                   FROM `v_cm_sd_tecnici_uo` t
              LEFT JOIN (SELECT `tecnico`, COUNT(*) AS interventi, SUM(`ore`) AS ore,
                                SUM(CASE WHEN `natura`='fatturabile' THEN `ore` ELSE 0 END) AS ore_fatt,
                                SUM(CASE WHEN `natura`='interna' THEN `ore` ELSE 0 END) AS ore_int,
                                SUM(`valore_addebitato`) AS valore_addebitato,
                                SUM(`valore_listino`) AS valore_listino,
                                COUNT(DISTINCT `commessa`) AS commesse,
                                COUNT(DISTINCT `giorno`) AS giornate
                           FROM `v_cm_sd_attivita` WHERE $w
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
            $st = $this->pdo->prepare("$select FROM `v_cm_sd_costi_valorizzati` WHERE $w $coda");
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
                "SELECT `nome`, `sotto_unita` FROM `v_cm_sd_team` ORDER BY `nome`")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}
