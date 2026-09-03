<?php
/**
 * app/DgbModel.php — Analytics attività DogoBit (v1.8.8)
 *
 * Query parametriche (range date, incaricato, stato) per:
 *  - tabella dati (ID, SLA innesco, ore, costi);
 *  - KPI consuntivo vs pianificato;
 *  - distribuzione carico per incaricato (sede vs remoto) con baseline;
 *  - data quality (ticket orfani, piani vuoti).
 */
final class DgbModel
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Normalizza i filtri in ingresso. */
    /**
     * v1.8.53 — Frazione di un intervento che ricade nell'orario ordinario.
     *
     * Regola aziendale: lunedi-venerdi 09:00-18:00 con pausa 13:00-14:00, quindi
     * due fasce da quattro ore. Fuori da queste — fine settimana, 18:01-08:59 e
     * la pausa — l'intervento e' in reperibilita'. Chi opera in turni non e'
     * soggetto alla regola.
     *
     * L'espressione e' identica a quella della vista `v_dgb_ore_classificate`:
     * duplicarla e' il prezzo per poter riusare i filtri esistenti, che lavorano
     * sugli alias `a` e `ao`. Le due definizioni vanno tenute allineate, ed e'
     * per questo che il collaudo le confronta riga per riga sui dati reali.
     *
     * Richiede in join `dgb_operator_profile pr` sull'operatore.
     */
    private const FRAC_ORD = "CASE
        WHEN COALESCE(pr.schedule_type,'ordinario') = 'turni' THEN 1.0
        WHEN DAYOFWEEK(a.date_start) IN (1,7) THEN 0.0
        WHEN a.date_start IS NULL OR a.date_dead_line IS NULL THEN 1.0
        WHEN TIMESTAMPDIFF(SECOND, a.date_start, a.date_dead_line) <= 0 THEN
            CASE WHEN (TIME(a.date_start) >= '09:00:00' AND TIME(a.date_start) < '13:00:00')
                   OR (TIME(a.date_start) >= '14:00:00' AND TIME(a.date_start) < '18:00:00')
                 THEN 1.0 ELSE 0.0 END
        ELSE LEAST(1.0, GREATEST(0.0,
              ( GREATEST(0, LEAST(TIME_TO_SEC(TIME(a.date_dead_line)),46800) - GREATEST(TIME_TO_SEC(TIME(a.date_start)),32400))
              + GREATEST(0, LEAST(TIME_TO_SEC(TIME(a.date_dead_line)),64800) - GREATEST(TIME_TO_SEC(TIME(a.date_start)),50400))
              ) / NULLIF(TIMESTAMPDIFF(SECOND, a.date_start, a.date_dead_line),0)))
    END";

    /** Join necessario alle espressioni orarie. */
    private const JOIN_PROFILE = " LEFT JOIN dgb_operator_profile pr ON pr.dgb_operator_id = ao.id_operator ";

    public static function normFilters(array $in): array
    {
        $d = fn($k) => (isset($in[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in[$k])) ? $in[$k] : '';
        $rt = strtoupper(trim((string)($in['report_type'] ?? '')));
        $md = strtolower(trim((string)($in['mode'] ?? '')));
        $sh = (float)($in['stdh'] ?? 8);
        if ($sh < 1 || $sh > 24) $sh = 8;
        $sched = strtolower(trim((string)($in['schedule'] ?? '')));
        $oncall = (string)($in['oncall'] ?? '');
        return [
            'from'        => $d('from'),
            'to'          => $d('to'),
            // v1.8.48: ricerca per codice attivita o ticket
            'q'           => trim((string)($in['q'] ?? '')),
            'operator'    => (int)($in['operator'] ?? 0),
            'status'      => trim((string)($in['status'] ?? '')),
            'contract'    => (int)($in['contract'] ?? 0),
            'report_type' => in_array($rt, ['STD', 'R_ANTEA'], true) ? $rt : '',
            'mode'        => in_array($md, ['sede', 'remoto'], true) ? $md : '',
            'stdh'        => $sh,
            'schedule'    => in_array($sched, ['ordinario', 'turni'], true) ? $sched : '',
            'oncall'      => in_array($oncall, ['0', '1'], true) ? $oncall : '',
        ];
    }

    /* ── Giorni lavorativi (lun-ven) ─────────────────────────────────────── */
    public static function workingDaysBetween(string $from, string $to): int
    {
        try { $d = new DateTime($from); $e = new DateTime($to); } catch (Throwable $x) { return 0; }
        if ($e < $d) return 0;
        $n = 0; $guard = 0;
        while ($d <= $e && $guard++ < 200000) {
            if ((int)$d->format('N') < 6) $n++;
            $d->modify('+1 day');
        }
        return $n;
    }

    /** WHERE + args sulle attività (alias a). Filtro incaricato via EXISTS sul dettaglio. */
    private function whereActivities(array $f): array
    {
        $w = ['a.deleted = 0']; $args = [];
        if ($f['from'])   { $w[] = "a.date_start >= ?"; $args[] = $f['from'] . ' 00:00:00'; }
        if ($f['to'])     { $w[] = "a.date_start <= ?"; $args[] = $f['to'] . ' 23:59:59'; }
        if ($f['status']) { $w[] = "a.status = ?";      $args[] = $f['status']; }
        if ($f['contract']){ $w[] = "a.id_contract = ?"; $args[] = $f['contract']; }
        if ($f['operator']) {
            $w[] = "EXISTS (SELECT 1 FROM dgb_forms_activity_operator x WHERE x.id_activity = a.id AND x.id_operator = ?)";
            $args[] = $f['operator'];
        }
        if ($f['schedule']) {
            $w[] = "EXISTS (SELECT 1 FROM dgb_forms_activity_operator x JOIN dgb_operator_profile pf ON pf.dgb_operator_id=x.id_operator WHERE x.id_activity=a.id AND pf.schedule_type=?)";
            $args[] = $f['schedule'];
        }
        // v1.8.48: ricerca per codice o ticket. Serve al collegamento dal tab
        // Consuntivo della scheda commessa, dove il codice rapporto coincide con
        // il codice attivita: si arriva qui gia' filtrati sulla riga cercata.
        if (!empty($f['q'])) {
            $w[] = "(a.code LIKE ? OR a.ticket LIKE ?)";
            $args[] = '%' . $f['q'] . '%';
            $args[] = '%' . $f['q'] . '%';
        }
        if ($f['oncall'] !== '') {
            $w[] = "EXISTS (SELECT 1 FROM dgb_forms_activity_operator x JOIN dgb_operator_profile pf ON pf.dgb_operator_id=x.id_operator WHERE x.id_activity=a.id AND pf.on_call=?)";
            $args[] = (int)$f['oncall'];
        }
        // v1.8.50: modalita' e tipo report erano applicati ai KPI (whereDetail)
        // ma non alla tabella. Applicando "da remoto" i totali scendevano e
        // l'elenco restava invariato: due numeri diversi sulla stessa schermata,
        // che e' il modo piu' rapido per far perdere fiducia a un cruscotto.
        // Gli attributi stanno sull'allocazione, quindi servono EXISTS.
        if ($f['mode'] === 'remoto') {
            $w[] = "EXISTS (SELECT 1 FROM dgb_forms_activity_operator x WHERE x.id_activity = a.id AND x.from_remote = 1)";
        }
        if ($f['mode'] === 'sede') {
            $w[] = "EXISTS (SELECT 1 FROM dgb_forms_activity_operator x WHERE x.id_activity = a.id AND COALESCE(x.from_remote,0) = 0)";
        }
        if (!empty($f['report_type'])) {
            $w[] = "EXISTS (SELECT 1 FROM dgb_forms_activity_operator x WHERE x.id_activity = a.id AND x.exec_report_type = ?)";
            $args[] = $f['report_type'];
        }
        return [implode(' AND ', $w), $args];
    }

    /* ── Sorgenti filtri ─────────────────────────────────────────────────── */

    public function operators(): array
    {
        return $this->pdo->query(
            // v1.9.3 — COGNOME NOME, non il contrario.
            //
            // `dgb_operator` tiene il cognome in `second_name`: concatenare
            // first + second produceva "Enrico Mancini" dove tutto il resto del
            // portale mostra "Mancini Enrico".
            //
            // I moduli di intervento usavano gia' la forma giusta, i menu che
            // leggono da qui no: la stessa persona compariva in due modi a
            // seconda della schermata.
            "SELECT o.id, TRIM(CONCAT(COALESCE(o.second_name,''),' ',COALESCE(o.first_name,''))) AS name, o.username
               FROM dgb_operator o
              WHERE o.id IN (SELECT DISTINCT id_operator FROM dgb_forms_activity_operator WHERE id_operator IS NOT NULL)
              ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function statuses(): array
    {
        return $this->pdo->query(
            "SELECT status, COUNT(*) n FROM dgb_forms_activity WHERE deleted=0 AND status IS NOT NULL AND status<>'' GROUP BY status ORDER BY n DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── Tabella dati (ID, SLA, Ore, Costi) ──────────────────────────────── */

    public function table(array $f, int $limit = 100, int $offset = 0): array
    {
        [$where, $args] = $this->whereActivities($f);
        $sql = "SELECT a.id AS activity_id, a.code, a.ticket, a.status, a.date_start, a.id_contract,
                       pl.fisrt_activity_start_date_time AS planned_start,
                       TIMESTAMPDIFF(HOUR, pl.fisrt_activity_start_date_time, a.date_start) AS sla_hours,
                       a.planned_hours,
                       COALESCE(d.actual_hours,0) AS actual_hours,
                       ROUND(COALESCE(d.actual_hours,0)-COALESCE(a.planned_hours,0),2) AS delta_hours,
                       a.total_cost, a.total_revenue,
                       (a.id_activity_planning IS NULL) AS is_orphan
                  FROM dgb_forms_activity a
                  LEFT JOIN dgb_forms_activity_planning pl ON pl.id = a.id_activity_planning
                  LEFT JOIN (SELECT id_activity, SUM(hours) actual_hours FROM dgb_forms_activity_operator GROUP BY id_activity) d
                         ON d.id_activity = a.id
                 WHERE $where
                 ORDER BY a.date_start DESC, a.id DESC
                 LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(array $f): int
    {
        [$where, $args] = $this->whereActivities($f);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM dgb_forms_activity a WHERE $where");
        $st->execute($args);
        return (int)$st->fetchColumn();
    }

    /* ── KPI: consuntivo vs pianificato + SLA aggregato ──────────────────── */

    public function kpi(array $f): array
    {
        [$where, $args] = $this->whereActivities($f);
        $sql = "SELECT COUNT(*) AS activities,
                       ROUND(SUM(COALESCE(a.planned_hours,0)),2) AS planned_hours,
                       ROUND(SUM(COALESCE(d.actual_hours,0)),2) AS actual_hours,
                       ROUND(SUM(COALESCE(a.total_cost,0)),2) AS total_cost,
                       ROUND(SUM(COALESCE(a.total_revenue,0)),2) AS total_revenue,
                       ROUND(AVG(TIMESTAMPDIFF(HOUR, pl.fisrt_activity_start_date_time, a.date_start)),1) AS avg_sla_hours,
                       SUM(CASE WHEN pl.id IS NOT NULL AND a.date_start > pl.fisrt_activity_start_date_time THEN 1 ELSE 0 END) AS late,
                       SUM(CASE WHEN pl.id IS NOT NULL AND a.date_start <= pl.fisrt_activity_start_date_time THEN 1 ELSE 0 END) AS on_time
                  FROM dgb_forms_activity a
                  LEFT JOIN dgb_forms_activity_planning pl ON pl.id = a.id_activity_planning
                  LEFT JOIN (SELECT id_activity, SUM(hours) actual_hours FROM dgb_forms_activity_operator GROUP BY id_activity) d
                         ON d.id_activity = a.id
                 WHERE $where";
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $planned = (float)($r['planned_hours'] ?? 0);
        $actual  = (float)($r['actual_hours'] ?? 0);
        $r['delta_hours'] = round($actual - $planned, 2);
        $r['achievement_pct'] = $planned > 0 ? round($actual / $planned * 100, 1) : null;
        return $r;
    }

    /* ── Distribuzione carico per incaricato (sede vs remoto) + baseline ─── */

    public function loadDistribution(array $f, int $limit = 15): array
    {
        // filtro coerente: solo dettagli delle attività che rispettano i filtri
        [$where, $args] = $this->whereActivities($f);
        $sql = "SELECT ao.id_operator AS assignee_id,
                       TRIM(CONCAT(COALESCE(op.second_name,''),' ',COALESCE(op.first_name,''))) AS assignee_name,
                       op.username,
                       ROUND(SUM(ao.hours),2) AS total_hours,
                       ROUND(SUM(CASE WHEN ao.from_remote=1 THEN ao.hours ELSE 0 END),2) AS remote_hours,
                       ROUND(SUM(CASE WHEN COALESCE(ao.from_remote,0)=0 THEN ao.hours ELSE 0 END),2) AS onsite_hours,
                       ROUND(SUM(ao.cost),2) AS total_cost,
                       COUNT(DISTINCT ao.id_activity) AS activities
                  FROM dgb_forms_activity_operator ao
                  JOIN dgb_forms_activity a ON a.id = ao.id_activity
                  LEFT JOIN dgb_operator op ON op.id = ao.id_operator
                 WHERE $where AND ao.id_operator IS NOT NULL
                 GROUP BY ao.id_operator, assignee_name, op.username
                 ORDER BY total_hours DESC
                 LIMIT " . (int)$limit;
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // baseline = media ore totali sugli incaricati mostrati (linea di riferimento)
        $tot = array_sum(array_map(fn($r) => (float)$r['total_hours'], $rows));
        $baseline = $rows ? round($tot / count($rows), 2) : 0;
        foreach ($rows as &$r) {
            $r['delta_vs_baseline'] = round((float)$r['total_hours'] - $baseline, 2);
            $r['over'] = (float)$r['total_hours'] >= $baseline;
        }
        unset($r);
        return ['baseline' => $baseline, 'rows' => $rows];
    }

    /* ── Data quality: ticket orfani (senza piano) e piani vuoti ─────────── */

    public function anomalies(array $f, int $sample = 20): array
    {
        [$where, $args] = $this->whereActivities($f);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM dgb_forms_activity a WHERE $where AND a.id_activity_planning IS NULL");
        $st->execute($args); $orphanCount = (int)$st->fetchColumn();
        $st = $this->pdo->prepare("SELECT a.id, a.code, a.ticket, a.status, a.date_start FROM dgb_forms_activity a WHERE $where AND a.id_activity_planning IS NULL ORDER BY a.date_start DESC LIMIT " . (int)$sample);
        $st->execute($args); $orphanRows = $st->fetchAll(PDO::FETCH_ASSOC);

        // piani vuoti (non dipendono dai filtri attività): planning senza attività
        $emptyCount = (int)$this->pdo->query("SELECT COUNT(*) FROM dgb_forms_activity_planning pl WHERE pl.deleted=0 AND NOT EXISTS (SELECT 1 FROM dgb_forms_activity a WHERE a.id_activity_planning=pl.id)")->fetchColumn();
        $emptyRows = $this->pdo->query("SELECT pl.id, pl.name, pl.repetition_type, pl.fisrt_activity_start_date_time FROM dgb_forms_activity_planning pl WHERE pl.deleted=0 AND NOT EXISTS (SELECT 1 FROM dgb_forms_activity a WHERE a.id_activity_planning=pl.id) ORDER BY pl.id DESC LIMIT " . (int)$sample)->fetchAll(PDO::FETCH_ASSOC);

        return ['orphan_count' => $orphanCount, 'orphan_sample' => $orphanRows,
                'empty_plan_count' => $emptyCount, 'empty_plan_sample' => $emptyRows];
    }

    /* ── Orario ordinario/straordinario e distribuzione temporale ────────── */

    /** WHERE + args sul dettaglio incaricati (alias ao) join attività (a). Ritorna anche l'espressione data-lavoro. */
    private function whereDetail(array $f): array
    {
        $wd = "COALESCE(a.report_date, DATE(a.date_start))";
        $w = ['a.deleted = 0']; $args = [];
        if ($f['from'])    { $w[] = "$wd >= ?"; $args[] = $f['from']; }
        if ($f['to'])      { $w[] = "$wd <= ?"; $args[] = $f['to']; }
        if ($f['status'])  { $w[] = "a.status = ?"; $args[] = $f['status']; }
        if ($f['contract']){ $w[] = "a.id_contract = ?"; $args[] = $f['contract']; }
        if ($f['operator']){ $w[] = "ao.id_operator = ?"; $args[] = $f['operator']; }
        if ($f['report_type']) { $w[] = "ao.exec_report_type = ?"; $args[] = $f['report_type']; }
        if ($f['mode'] === 'remoto') { $w[] = "ao.from_remote = 1"; }
        elseif ($f['mode'] === 'sede') { $w[] = "COALESCE(ao.from_remote,0) = 0"; }
        if ($f['schedule']) { $w[] = "EXISTS (SELECT 1 FROM dgb_operator_profile pf WHERE pf.dgb_operator_id=ao.id_operator AND pf.schedule_type=?)"; $args[] = $f['schedule']; }
        if ($f['oncall'] !== '') { $w[] = "EXISTS (SELECT 1 FROM dgb_operator_profile pf WHERE pf.dgb_operator_id=ao.id_operator AND pf.on_call=?)"; $args[] = (int)$f['oncall']; }
        return [implode(' AND ', $w), $args, $wd];
    }

    /**
     * Riepilogo orario: ordinario, straordinario, trasferta, recupero, carico totale,
     * capacità standard (giorni lavorativi x ore/giorno x incaricati) e saturazione.
     */
    public function hoursBreakdown(array $f): array
    {
        [$w, $args, $wd] = $this->whereDetail($f);
        // v1.8.78 — `extra_hours` e' COMPRESO in `hours`, non aggiuntivo: nelle
        // 9 ore di un modulo, 4 possono essere extra e 5 ordinarie. Sommare i
        // due campi conterebbe le extra due volte.
        //
        // `ordinary` porta quindi le ore ORDINARIE per differenza, e il totale
        // consuntivato e' esposto a parte come `total_hours`.
        $sql = "SELECT ROUND(SUM(ao.hours - COALESCE(ao.extra_hours,0)),2) ordinary,
                       ROUND(SUM(ao.hours),2) total_hours,
                       ROUND(SUM(ao.extra_hours),2) overtime,
                       ROUND(SUM(ao.trip_hours),2) trip, ROUND(SUM(ao.to_recover_hours),2) recovery,
                       ROUND(SUM(ao.cost),2) cost, ROUND(SUM(ao.revenue),2) revenue,
                       COUNT(DISTINCT ao.id_operator) operators, COUNT(DISTINCT ao.id_activity) activities,
                       MIN($wd) dmin, MAX($wd) dmax,
                       ROUND(SUM(CASE WHEN ao.from_remote=1 THEN ao.hours ELSE 0 END),2) remote_hours,
                       ROUND(SUM(CASE WHEN COALESCE(ao.from_remote,0)=0 THEN ao.hours ELSE 0 END),2) onsite_hours
                  FROM dgb_forms_activity_operator ao
                  JOIN dgb_forms_activity a ON a.id = ao.id_activity
                 WHERE $w";
        $st = $this->pdo->prepare($sql); $st->execute($args);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $ord = (float)($r['ordinary'] ?? 0); $ext = (float)($r['overtime'] ?? 0);
        $ops = (int)($r['operators'] ?? 0);
        $r['workload'] = round($ord + $ext, 2);
        $r['overtime_pct'] = $ord > 0 ? round($ext / $ord * 100, 1) : null;
        $effFrom = $f['from'] ?: (string)($r['dmin'] ?? '');
        $effTo   = $f['to'] ?: (string)($r['dmax'] ?? '');
        $wdays = ($effFrom && $effTo) ? self::workingDaysBetween($effFrom, $effTo) : 0;
        $stdCap = round($wdays * $f['stdh'] * max($ops, 1), 2);
        $r['working_days'] = $wdays;
        $r['std_capacity'] = $stdCap;
        $r['saturation_pct'] = $stdCap > 0 ? round($r['workload'] / $stdCap * 100, 1) : null;
        $r['eff_from'] = $effFrom; $r['eff_to'] = $effTo;
        return $r;
    }

    /**
     * Distribuzione temporale delle ore lavorate (ordinario + straordinario) con baseline
     * di orario ordinario per bucket.
     *  - granularity 'day'  : giorni del mese $month (YYYY-MM)
     *  - granularity 'month': mesi nel periodo di riferimento [from..to]
     */
    /**
     * v1.8.67 — Distribuzione delle ore sulle 24 ore del giorno.
     *
     * Restituisce una matrice giorno x ora per il mese indicato: quante ore sono
     * state lavorate in ciascuna fascia oraria di ciascun giorno.
     *
     * LE ORE SONO RIPARTITE, NON ATTRIBUITE ALL'INIZIO.
     * Attribuire le ore all'ora di inizio — che e' la lettura immediata del dato
     * — produce un profilo falso: sui dati reali l'ora 9 raccoglierebbe 251.721
     * ore su 338.000, perche' quasi ogni intervento e' registrato con inizio
     * alle 09:00. Ripartendo l'intervento sulle fasce che attraversa, il profilo
     * diventa quello vero: 30-43 mila ore per fascia dalle 9 alle 17, crollo
     * dalle 18, coda notturna sotto le 100 ore.
     *
     * La quota di ciascuna fascia e' la sovrapposizione fra l'intervallo
     * dell'intervento e l'ora, divisa per la durata totale, moltiplicata per le
     * ore consuntivate. E' la stessa logica della classificazione
     * ordinario/reperibilita della v1.8.53, applicata a 24 finestre invece che a
     * due.
     *
     * Gli interventi a cavallo della mezzanotte (0,58%) sono esclusi: la loro
     * ripartizione richiederebbe di spezzarli su due giorni, e il guadagno non
     * giustifica la complessita'.
     *
     * @return array{month:string,days:int,cells:array<string,float>,max:float,
     *               by_hour:array<int,float>,total:float}
     */
    /**
     * v1.8.69 — La matrice distingue ora QUATTRO nature piu' le assenze.
     *
     * Alla fascia oraria (ordinario / reperibilita', v1.8.53) si incrocia il tipo
     * di commessa (interna / a cliente, v1.8.58), ottenendo:
     *
     *   int_ord   interna in fascia        int_rep   interna fuori fascia
     *   cli_ord   a cliente in fascia      cli_rep   a cliente fuori fascia
     *
     * Le assenze — ferie, permessi, recuperi, malattia — non hanno una fascia
     * oraria propria: sono ore NON lavorate e vivono su un piano diverso.
     * Vengono restituite per giorno, non per ora, e la matrice le mostra in una
     * banda separata sotto la griglia. Distribuirle sulle 24 ore avrebbe dato
     * l'impressione che qualcuno lavorasse durante le ferie.
     */
    /**
     * v1.8.80 — Quadro delle ore del periodo, con capacita' e composizione.
     *
     * CAPACITA' ORDINARIA
     * Giorni lavorativi del periodo (lunedi-venerdi) per le ore giornaliere
     * previste, moltiplicati per il numero di incaricati che hanno operato.
     * E' la stessa base usata per la linea di riferimento dei grafici: se i due
     * numeri divergessero, uno dei due sarebbe sbagliato e non si saprebbe
     * quale.
     *
     * COMPOSIZIONE DELLE ORE
     * Le componenti NON sono addendi di una somma: si sovrappongono. Un
     * intervento da remoto in reperibilita' conta in entrambe. Sommarle darebbe
     * un totale superiore alle ore lavorate, ed e' la ragione per cui vengono
     * esposte come quote del consuntivo e non come parti di una torta.
     *
     * ORE EXTRA E ORE FUORI ORARIO
     * Sono due misure diverse dello stesso fenomeno:
     *   extra        dichiarate sul modulo dal gestionale
     *   fuori orario calcolate dalla collocazione temporale (v1.8.53)
     * Sui dati divergono molto — 5.411 contro 45.181 ore — e la divergenza e'
     * essa stessa un'informazione: vengono percio' mostrate entrambe, affiancate.
     */
    public function periodSummary(array $f): array
    {
        [$w, $args, $wd] = $this->whereDetail($f);
        $stdh = (float)($f['stdh'] ?? 8);

        $sql = "SELECT
                    ROUND(SUM(ao.hours), 2)                                   AS ore_consuntivate,
                    ROUND(SUM(COALESCE(ao.extra_hours,0)), 2)                 AS ore_extra,
                    ROUND(SUM(COALESCE(ao.trip_hours,0)), 2)                  AS ore_viaggio,
                    ROUND(SUM(COALESCE(ao.to_recover_hours,0)), 2)            AS ore_da_recuperare,
                    ROUND(SUM(CASE WHEN ao.during_availability = 1 THEN ao.hours ELSE 0 END), 2) AS ore_reperibilita,
                    ROUND(SUM(CASE WHEN ao.from_remote = 1        THEN ao.hours ELSE 0 END), 2) AS ore_remoto,
                    ROUND(SUM(CASE WHEN ao.smart_working = 1      THEN ao.hours ELSE 0 END), 2) AS ore_smart,
                    ROUND(SUM(ao.hours * (" . self::FRAC_ORD . ")), 2)        AS ore_in_orario,
                    COUNT(DISTINCT ao.id_operator)                            AS incaricati,
                    COUNT(DISTINCT " . $wd . ")                               AS giorni_con_attivita,
                    MIN(" . $wd . ")                                          AS dal,
                    MAX(" . $wd . ")                                          AS al
                  FROM dgb_forms_activity_operator ao
                  JOIN dgb_forms_activity a ON a.id = ao.id_activity"
                . self::JOIN_PROFILE . "
                 WHERE $w";

        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();

        $cons = (float)($r['ore_consuntivate'] ?? 0);
        $inOra = (float)($r['ore_in_orario'] ?? 0);

        // il fuori orario per DIFFERENZA, cosi' le due quote sommano sempre al
        // consuntivo (stessa regola della v1.8.53 e della v1.8.78)
        $r['ore_fuori_orario'] = round($cons - $inOra, 2);

        // giorni lavorativi del periodo: lunedi-venerdi fra gli estremi
        $dal = $r['dal'] ?? null; $al = $r['al'] ?? null;
        $gg = 0;
        if ($dal && $al) {
            $d = new DateTime((string)$dal);
            $fine = new DateTime((string)$al);
            while ($d <= $fine) {
                if ((int)$d->format('N') <= 5) $gg++;
                $d->modify('+1 day');
            }
        }
        $r['giorni_lavorativi']  = $gg;
        $r['ore_giornaliere']    = $stdh;
        $r['incaricati']         = (int)($r['incaricati'] ?? 0);

        // ── CAPACITA': la stessa della linea di riferimento nei grafici ──────
        //
        // Il primo tentativo usava giorni x ore x incaricati del periodo, e dava
        // 14.720 h contro i 10.544 della baseline: due verita' sulla stessa
        // grandezza, che e' esattamente cio' che va evitato.
        //
        // La baseline (v1.8.52) somma, per ogni giorno, le ore standard degli
        // operatori ATTIVI QUEL GIORNO. E' la misura corretta: non tutti gli 80
        // incaricati del mese lavorano tutti i 23 giorni, e attribuire a
        // ciascuno l'intero calendario gonfia la capacita' del 40%.
        //
        // Si usa quindi la stessa formula, letta dalla stessa fonte.
        $stCap = $this->pdo->prepare(
            "SELECT ROUND(SUM(op_giorno) * ?, 2) AS capacita
               FROM (SELECT COUNT(DISTINCT ao.id_operator) AS op_giorno
                       FROM dgb_forms_activity_operator ao
                       JOIN dgb_forms_activity a ON a.id = ao.id_activity"
                     . self::JOIN_PROFILE . "
                      WHERE $w
                      GROUP BY " . $wd . ") g");
        $stCap->execute(array_merge([$stdh], $args));
        $r['capacita_ordinaria'] = (float)($stCap->fetchColumn() ?: 0);
        $stCap->closeCursor();

        // la capacita' TEORICA resta esposta come confronto: la differenza fra
        // le due dice quanto il personale e' distribuito nel tempo invece che
        // presente ogni giorno
        $r['capacita_teorica'] = round($gg * $stdh * $r['incaricati'], 2);
        $r['presenza_media_pct'] = $r['capacita_teorica'] > 0
            ? round(100 * $r['capacita_ordinaria'] / $r['capacita_teorica'], 1) : null;

        $r['utilizzo_pct'] = $r['capacita_ordinaria'] > 0
            ? round(100 * $cons / $r['capacita_ordinaria'], 1) : null;

        return $r;
    }

    public function hourlyHeatmap(array $f, string $month = ''): array
    {
        [$w, $args, $wd] = $this->whereDetail($f);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = substr(($f['to'] ?: ($f['from'] ?: date('Y-m'))), 0, 7);
        }
        $mFrom = $month . '-01';
        $mTo   = date('Y-m-t', strtotime($mFrom));

        $sql = "WITH RECURSIVE h(n) AS (SELECT 0 UNION ALL SELECT n+1 FROM h WHERE n < 23)
                SELECT DAY($wd) AS g, h.n AS ora,
                       CASE WHEN COALESCE(cm.has_revenue, 1) = 0 THEN 'int' ELSE 'cli' END AS natura,
                       ROUND(SUM(ao.hours *
                           GREATEST(0, LEAST(TIME_TO_SEC(TIME(a.date_dead_line)), (h.n+1)*3600)
                                     - GREATEST(TIME_TO_SEC(TIME(a.date_start)), h.n*3600))
                           / NULLIF(TIMESTAMPDIFF(SECOND, a.date_start, a.date_dead_line), 0)), 2) AS ore
                  FROM dgb_forms_activity_operator ao
                  JOIN dgb_forms_activity a ON a.id = ao.id_activity"
                . self::JOIN_PROFILE . "
             -- il raccordo con la commessa e' `id_contract`, non il codice:
             -- `a.code` e' il codice dell'ATTIVITA (MEFA_23_000003), mentre
             -- `cm_projects.dgb_contract_id` porta l'identificativo del
             -- contratto. Sul codice il join non trovava nulla e tutte le
             -- attivita risultavano a cliente, comprese le interne.
             LEFT JOIN cm_projects        cp ON cp.dgb_contract_id = a.id_contract
             LEFT JOIN cm_contract_models cm ON cm.service_line = cp.service_line
                 CROSS JOIN h
                 WHERE $w
                   AND $wd BETWEEN ? AND ?
                   AND a.date_start IS NOT NULL AND a.date_dead_line IS NOT NULL
                   AND DATE(a.date_start) = DATE(a.date_dead_line)
                   AND TIMESTAMPDIFF(SECOND, a.date_start, a.date_dead_line) > 0
                 GROUP BY g, h.n, natura
                HAVING ore > 0";

        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge($args, [$mFrom, $mTo]));

        $cells = []; $split = []; $byHour = array_fill(0, 24, 0.0);
        $byNature = ['int_ord' => 0.0, 'int_rep' => 0.0, 'cli_ord' => 0.0, 'cli_rep' => 0.0];
        $max = 0.0; $tot = 0.0;
        $fasce = [9, 10, 11, 12, 14, 15, 16, 17];

        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $g = (int)$r['g']; $o = (int)$r['ora']; $v = (float)$r['ore'];
            $k = $g . ':' . $o;

            // ordinario solo se la fascia e' giusta E il giorno e' feriale:
            // e' la regola della v1.8.53, e nel fine settimana anche le fasce
            // 09-13 e 14-18 sono reperibilita'
            $we  = (int)date('N', strtotime($month . '-' . sprintf('%02d', $g))) >= 6;
            $ord = in_array($o, $fasce, true) && !$we;
            $nat = ((string)$r['natura']) . '_' . ($ord ? 'ord' : 'rep');

            $cells[$k] = ($cells[$k] ?? 0.0) + $v;
            $split[$k][$nat] = ($split[$k][$nat] ?? 0.0) + $v;
            $byHour[$o] += $v;
            if (isset($byNature[$nat])) $byNature[$nat] += $v;
            $tot += $v;
            if ($cells[$k] > $max) $max = $cells[$k];
        }
        $st->closeCursor();

        // assenze del mese, per giorno e tipo: sono ore NON lavorate e non
        // appartengono a una fascia oraria
        $abs = []; $absByType = []; $absTot = 0.0;
        try {
            // v1.8.81 — le assenze seguono il FILTRO TECNICO della pagina.
            //
            // Prima era il totale aziendale: con 94 persone in ferie ad agosto,
            // una banda alta non dice nulla su chi manca, e affiancata a una
            // matrice filtrata su un tecnico confrontava grandezze diverse.
            //
            // Il filtro della pagina porta l'ID dell'operatore, non il nome:
            // `normFilters()` normalizza `operator` a intero. Si usa quindi
            // `operator_id`, che e' anche il legame corretto — un confronto sul
            // nome sarebbe esposto alle differenze di forma fra gestionale e
            // anagrafica, gia' viste con l'inversione nome/cognome (v1.8.77).
            $opId = (int)($f['operator'] ?? 0);
            if ($opId > 0) {
                $stA = $this->pdo->prepare(
                    "SELECT DAY(c.start_date) AS g, c.commitment_type AS tipo,
                            t.label AS tipo_label, t.color AS colore,
                            ROUND(SUM(c.hours), 2) AS ore
                       FROM cm_operator_commitments c
                       JOIN cm_commitment_types t
                         ON t.code = c.commitment_type AND t.is_absence = 1
                      WHERE DATE_FORMAT(c.start_date, '%Y-%m') = ?
                        AND c.operator_id = ?
                      GROUP BY DAY(c.start_date), c.commitment_type, t.label, t.color");
                $stA->execute([$month, $opId]);
            } else {
                $stA = $this->pdo->prepare(
                    "SELECT DAY(giorno) AS g, tipo, tipo_label, colore, ore
                       FROM v_cm_assenze_giorno WHERE anno_mese = ?");
                $stA->execute([$month]);
            }
            while (($r = $stA->fetch(PDO::FETCH_ASSOC)) !== false) {
                $g = (int)$r['g']; $v = (float)$r['ore'];
                $abs[$g][$r['tipo']] = ($abs[$g][$r['tipo']] ?? 0.0) + $v;
                $absByType[$r['tipo']] = ['label' => $r['tipo_label'], 'colore' => $r['colore'],
                    'ore' => ($absByType[$r['tipo']]['ore'] ?? 0.0) + $v];
                $absTot += $v;
            }
            $stA->closeCursor();
        } catch (Throwable $e) { $abs = []; $absByType = []; $absTot = 0.0; }

        $absMax = 0.0;
        foreach ($abs as $perTipo) { $s = array_sum($perTipo); if ($s > $absMax) $absMax = $s; }

        return ['month' => $month, 'days' => (int)date('t', strtotime($mFrom)),
                'cells' => $cells, 'split' => $split, 'max' => $max, 'by_hour' => $byHour,
                'by_nature' => $byNature, 'total' => round($tot, 2),
                'absences' => $abs, 'abs_by_type' => $absByType,
                'abs_max' => $absMax, 'abs_total' => round($absTot, 2)];
    }

    public function temporalDistribution(array $f, string $granularity = 'month', string $month = ''): array
    {
        $granularity = $granularity === 'day' ? 'day' : 'month';
        [$w, $args, $wd] = $this->whereDetail($f);

        // incaricati distinti nell'insieme filtrato (per la baseline capacità)
        $stc = $this->pdo->prepare("SELECT COUNT(DISTINCT ao.id_operator) FROM dgb_forms_activity_operator ao JOIN dgb_forms_activity a ON a.id=ao.id_activity WHERE $w");
        $stc->execute($args); $N = max((int)$stc->fetchColumn(), 1);
        $stdh = (float)$f['stdh'];

        if ($granularity === 'day') {
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = ($f['to'] ?: ($f['from'] ?: date('Y-m'))); $month = substr($month, 0, 7);
            }
            $mFrom = $month . '-01';
            $mTo   = date('Y-m-t', strtotime($mFrom));
            $w2 = $w . " AND $wd BETWEEN ? AND ?"; $a2 = array_merge($args, [$mFrom, $mTo]);
            // v1.8.52: si conta anche quanti operatori hanno lavorato in ciascun
            // giorno. Serve alla baseline: usare N, il totale del periodo,
            // sovrastimava la capacita' giornaliera del 70-90% perche' includeva
            // chi quel giorno non era in servizio. Il confronto risultava sempre
            // in difetto e il grafico suggeriva un sottoutilizzo inesistente.
            // v1.8.53: 'ordinary' e 'overtime' non sono piu' i campi dichiarati
            // dalla sorgente ma la ripartizione secondo la regola oraria. La
            // reperibilita' si ricava per differenza, cosi' la somma delle due
            // componenti resta esattamente pari alle ore consuntivate.
            $fo = self::FRAC_ORD;
            $sql = "SELECT DATE($wd) k,
                           ROUND(SUM(ao.hours * ($fo)),2) ordinary,
                           ROUND(SUM(ao.hours),2) - ROUND(SUM(ao.hours * ($fo)),2) overtime,
                           COUNT(DISTINCT ao.id_operator) actives
                      FROM dgb_forms_activity_operator ao JOIN dgb_forms_activity a ON a.id=ao.id_activity"
                      . self::JOIN_PROFILE . "
                     WHERE $w2 GROUP BY k";
            $st = $this->pdo->prepare($sql); $st->execute($a2);
            $map = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $map[$row['k']] = $row;

            // mediana degli attivi nei giorni feriali con attivita': e' il
            // riferimento per i giorni senza dati, dove il conteggio sarebbe 0 e
            // la baseline sparirebbe. La mediana e' preferita alla media perche'
            // i giorni di chiusura o di presidio minimo la distorcerebbero.
            $act = [];
            foreach ($map as $k => $row) {
                if ((int)date('N', strtotime($k)) < 6 && (int)$row['actives'] > 0) $act[] = (int)$row['actives'];
            }
            sort($act);
            $cnt = count($act);
            $medianAct = $cnt ? (int)round($cnt % 2 ? $act[intdiv($cnt, 2)]
                                                    : ($act[$cnt/2 - 1] + $act[$cnt/2]) / 2) : $N;

            $buckets = [];
            $days = (int)date('t', strtotime($mFrom));
            for ($dd = 1; $dd <= $days; $dd++) {
                $key = sprintf('%s-%02d', $month, $dd);
                $dow = (int)date('N', strtotime($key));
                $ord = (float)($map[$key]['ordinary'] ?? 0); $ext = (float)($map[$key]['overtime'] ?? 0);
                $actv = (int)($map[$key]['actives'] ?? 0);
                $isWeekend = $dow >= 6;

                // La baseline segue chi ha davvero lavorato. Nel fine settimana
                // compare solo se ci sono ore: un turno di reperibilita' deve
                // avere un riferimento, un sabato di chiusura no.
                if ($actv > 0)          $base = round($stdh * $actv, 2);
                elseif (!$isWeekend)    $base = round($stdh * $medianAct, 2);
                else                    $base = 0.0;

                $buckets[] = ['key' => $key, 'label' => (string)$dd,
                    'ordinary' => $ord, 'overtime' => $ext, 'workload' => round($ord + $ext, 2),
                    'baseline' => $base, 'weekend' => $isWeekend,
                    'actives' => $actv, 'estimated' => ($actv === 0 && !$isWeekend)];
            }
            $scope = ['month' => $month, 'from' => $mFrom, 'to' => $mTo, 'median_actives' => $medianAct];
        } else {
            $fo = self::FRAC_ORD;
            $sql = "SELECT DATE_FORMAT($wd,'%Y-%m') k,
                           ROUND(SUM(ao.hours * ($fo)),2) ordinary,
                           ROUND(SUM(ao.hours),2) - ROUND(SUM(ao.hours * ($fo)),2) overtime
                      FROM dgb_forms_activity_operator ao JOIN dgb_forms_activity a ON a.id=ao.id_activity"
                      . self::JOIN_PROFILE . "
                     WHERE $w GROUP BY k";
            $st = $this->pdo->prepare($sql); $st->execute($args);
            $map = []; $keys = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $map[$row['k']] = $row; $keys[] = $row['k']; }
            $effFrom = $f['from'] ?: (($keys ? min($keys) : date('Y-m')) . '-01');
            $effTo   = $f['to'] ?: (($keys ? max($keys) : date('Y-m')) . '-01');
            $cur = new DateTime(substr($effFrom, 0, 7) . '-01');
            $end = new DateTime(substr($effTo, 0, 7) . '-01');
            $buckets = [];
            $months_it = [1=>'gen',2=>'feb',3=>'mar',4=>'apr',5=>'mag',6=>'giu',7=>'lug',8=>'ago',9=>'set',10=>'ott',11=>'nov',12=>'dic'];
            $guard = 0;
            while ($cur <= $end && $guard++ < 600) {
                $key = $cur->format('Y-m');
                $mF = $key . '-01'; $mT = date('Y-m-t', strtotime($mF));
                $lo = ($f['from'] && $f['from'] > $mF) ? $f['from'] : $mF;
                $hi = ($f['to'] && $f['to'] < $mT) ? $f['to'] : $mT;
                $wdays = self::workingDaysBetween($lo, $hi);
                $ord = (float)($map[$key]['ordinary'] ?? 0); $ext = (float)($map[$key]['overtime'] ?? 0);
                $buckets[] = ['key' => $key, 'label' => $months_it[(int)$cur->format('n')] . ' ' . $cur->format('y'),
                    'ordinary' => $ord, 'overtime' => $ext, 'workload' => round($ord + $ext, 2),
                    'baseline' => round($wdays * $stdh * $N, 2), 'weekend' => false];
                $cur->modify('+1 month');
            }
            $scope = ['from' => $effFrom, 'to' => $effTo];
        }

        $tot = ['ordinary' => 0.0, 'overtime' => 0.0, 'workload' => 0.0, 'baseline' => 0.0];
        foreach ($buckets as $b) foreach ($tot as $k => $v) $tot[$k] = round($v + $b[$k], 2);
        return ['granularity' => $granularity, 'operators' => $N, 'stdh' => $stdh,
                'scope' => $scope, 'buckets' => $buckets, 'totals' => $tot];
    }

    /* ── Riconciliazione con le commesse native ──────────────────────────── */

    /**
     * Rollup DGB per uno o più contratti DogoBit (id_contract), aggregato dalle
     * tabelle base con predicato indicizzato. Ritorna mappa dgb_contract_id => metriche.
     */
    public function commessaRollup(array $dgbContractIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $dgbContractIds))));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $out = [];
        foreach ($this->pdo->query(
            "SELECT a.id_contract cid, COUNT(DISTINCT a.id) activities,
                    SUM(a.id_activity_planning IS NULL) orphan_activities,
                    ROUND(SUM(COALESCE(a.total_cost,0)),2) total_cost,
                    ROUND(SUM(COALESCE(a.total_revenue,0)),2) total_revenue,
                    MIN(COALESCE(a.report_date,DATE(a.date_start))) first_date,
                    MAX(COALESCE(a.report_date,DATE(a.date_start))) last_date
               FROM dgb_forms_activity a
              WHERE a.deleted=0 AND a.id_contract IN ($in) GROUP BY a.id_contract"
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['cid']] = $r + ['actual_hours' => 0, 'overtime_hours' => 0, 'trip_hours' => 0, 'operators' => 0];
        }
        foreach ($this->pdo->query(
            "SELECT a.id_contract cid, ROUND(SUM(ao.hours),2) actual_hours,
                    ROUND(SUM(ao.extra_hours),2) overtime_hours, ROUND(SUM(ao.trip_hours),2) trip_hours,
                    COUNT(DISTINCT ao.id_operator) operators
               FROM dgb_forms_activity_operator ao JOIN dgb_forms_activity a ON a.id=ao.id_activity
              WHERE a.deleted=0 AND a.id_contract IN ($in) GROUP BY a.id_contract"
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (int)$r['cid'];
            if (!isset($out[$cid])) $out[$cid] = ['activities' => 0, 'orphan_activities' => 0, 'total_cost' => 0, 'total_revenue' => 0, 'first_date' => null, 'last_date' => null];
            $out[$cid]['actual_hours'] = $r['actual_hours']; $out[$cid]['overtime_hours'] = $r['overtime_hours'];
            $out[$cid]['trip_hours'] = $r['trip_hours']; $out[$cid]['operators'] = $r['operators'];
        }
        return $out;
    }

    /** Rollup DGB per una singola commessa (dgb_contract_id) o null se non collegata. */
    public function rollupForContract(?int $dgbContractId): ?array
    {
        if (!$dgbContractId) return null;
        $m = $this->commessaRollup([$dgbContractId]);
        return $m[$dgbContractId] ?? null;
    }

    /** Attività DGB recenti di un contratto (per la scheda commessa). */
    public function activitiesForContract(int $dgbContractId, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            "SELECT a.id, a.code, a.ticket, a.status, a.date_start, a.report_date,
                    a.planned_hours, a.total_cost, a.total_revenue,
                    (SELECT ROUND(SUM(x.hours),2) FROM dgb_forms_activity_operator x WHERE x.id_activity=a.id) AS actual_hours
               FROM dgb_forms_activity a
              WHERE a.deleted=0 AND a.id_contract=?
              ORDER BY COALESCE(a.report_date,a.date_start) DESC, a.id DESC LIMIT " . (int)$limit
        );
        $st->execute([$dgbContractId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Contratti DogoBit con commessa collegata: id_contract => project_code (per filtri/etichette). */
    public function contractLabels(): array
    {
        return $this->pdo->query(
            "SELECT dgb_contract_id, project_code FROM cm_projects
              WHERE dgb_contract_id IS NOT NULL ORDER BY project_code"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Elenco incaricati con profilo orario/reperibilità, dipendente collegato e ore totali. */
    public function operatorProfiles(): array
    {
        return $this->pdo->query(
            "SELECT o.id, TRIM(CONCAT(COALESCE(o.second_name,''),' ',COALESCE(o.first_name,''))) AS name,
                    o.username, m.employee_id,
                    TRIM(CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,''))) AS employee_name,
                    COALESCE(pf.schedule_type,'ordinario') AS schedule_type,
                    COALESCE(pf.on_call,0) AS on_call, COALESCE(pf.auto_classified,0) AS auto_classified,
                    d.total_hours, d.activities
               FROM dgb_operator o
               JOIN (SELECT id_operator, ROUND(SUM(hours),1) total_hours, COUNT(DISTINCT id_activity) activities
                       FROM dgb_forms_activity_operator WHERE id_operator IS NOT NULL GROUP BY id_operator) d
                 ON d.id_operator = o.id
               LEFT JOIN dgb_operator_profile pf ON pf.dgb_operator_id = o.id
               LEFT JOIN dgb_operator_map m ON m.dgb_operator_id = o.id
               LEFT JOIN employees e ON e.id = m.employee_id
              ORDER BY d.total_hours DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ── Bundle JSON per API ─────────────────────────────────────────────── */

    public function chartsJson(array $f, string $granularity = 'month', string $month = ''): array
    {
        $kpi = $this->kpi($f);
        $load = $this->loadDistribution($f);
        $hb  = $this->hoursBreakdown($f);
        $dist = $this->temporalDistribution($f, $granularity, $month);
        return [
            'hours_breakdown' => $hb,
            'temporal_distribution' => $dist,
            // gauge consuntivo vs pianificato
            'gauge' => [
                'planned_hours' => (float)($kpi['planned_hours'] ?? 0),
                'actual_hours'  => (float)($kpi['actual_hours'] ?? 0),
                'achievement_pct' => $kpi['achievement_pct'],
                'zones' => [ ['to'=>80,'label'=>'sotto','color'=>'#dc2626'], ['to'=>110,'label'=>'in linea','color'=>'#16a34a'], ['to'=>200,'label'=>'sopra','color'=>'#d97706'] ],
            ],
            // distribuzione carico con baseline
            'load_distribution' => [
                'baseline' => $load['baseline'],
                'series' => array_map(fn($r) => [
                    'assignee_id' => (int)$r['assignee_id'],
                    'label' => $r['assignee_name'] ?: $r['username'],
                    'onsite_hours' => (float)$r['onsite_hours'],
                    'remote_hours' => (float)$r['remote_hours'],
                    'total_hours' => (float)$r['total_hours'],
                    'delta_vs_baseline' => (float)$r['delta_vs_baseline'],
                    'over' => (bool)$r['over'],
                ], $load['rows']),
            ],
        ];
    }
}
