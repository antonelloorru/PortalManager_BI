<?php
/**
 * DirModel — letture per il report direzionale e le schede commerciale.
 *
 * v1.9.94 — intervallo date (Data Inizio / Data Fine): commesse la cui durata interseca il periodo,
 *           andamento sui mesi del periodo; indicatore Fido; competenza pro-rata mensile degli
 *           ordini cliente (ProRata) raggruppata per anno.
 * v1.9.78 — filtro globale Codice Contratto / PM Project (PmContractFilter) su quadro,
 * agenti, grafici, commesse, attenzione, andamento, perimetro ed export.
 *
 * Le due destinazioni condividono le stesse query: la scheda dell'agente e' il
 * report direzionale ristretto al suo perimetro. Duplicare le query per i due
 * casi le farebbe divergere alla prima modifica, e i due documenti mostrerebbero
 * numeri diversi per la stessa commessa.
 */

declare(strict_types=1);

final class DirModel
{
    private PDO $pdo;

    /** v1.9.94 — presenza di un fido (sforamento consentito) sulla commessa: su valore o su costi. */
    public const FIDO = "(COALESCE(pf.`credit_on_value`,0) <> 0 OR COALESCE(pf.`credit_on_costs`,0) <> 0)";

    /** v1.9.73 — nome da interrogare per ciascuna vista: copia aggiornata se lenta, altrimenti la vista. */
    private array $v = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        require_once __DIR__ . '/PmSnapshot.php';
        require_once __DIR__ . '/PmContractFilter.php';
        require_once __DIR__ . '/PmUoFilter.php';
        $this->v = PmSnapshot::names($pdo, ['v_cm_dir_andamento', 'v_cm_dir_attenzione', 'v_cm_dir_commessa']);
    }

    public function normFilters(array $q): array
    {
        $d = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
        $arr = static function ($v): array {
            if (is_string($v)) $v = $v === '' ? [] : explode(',', $v);
            return array_values(array_filter(array_map('trim', (array)$v), fn($x) => $x !== ''));
        };
        $f = [
            'agente'   => trim((string)($q['agente'] ?? '')),
            'stato'    => $arr($q['stato']    ?? []),
            'linee'    => $arr($q['linee']    ?? []),
            'aziende'  => $arr($q['aziende']  ?? []),
            // 'aperte' e' il valore predefinito: il rischio riguarda cio' su cui
            // si puo' ancora agire, e includere le chiuse triplica i conteggi
            // con casi su cui non si puo' intervenire
            'solo'     => in_array($q['solo'] ?? 'aperte', ['aperte','tutte','ricavo'], true)
                          ? (string)($q['solo'] ?? 'aperte') : 'aperte',
            'from'     => $d($q['from'] ?? ''),
            'to'       => $d($q['to'] ?? ''),
            // v1.9.8 — allineamento al pannello di Commesse/Progetti, che ha
            // ricerca libera e cliente. Senza, per trovare una commessa dal
            // codice bisognava uscire dalla sezione.
            'q'        => trim((string)($q['q'] ?? '')),
            'cliente'  => trim((string)($q['cliente'] ?? '')),
            // v1.9.78 — filtro globale Codice Contratto / PM Project
            'contratti' => PmContractFilter::fromRequest($q),
            // v1.10.30 — Unità Organizzativa: commesse con moduli o team delle unità scelte
            'uo'        => PmUoFilter::fromRequest($q),
        ];
        if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) [$f['from'], $f['to']] = [$f['to'], $f['from']];
        return $f;
    }

    /** v1.9.78 — filtro contratto risolto una volta per richiesta. */
    private ?PmContractFilter $cf = null;
    public function cf(array $f): PmContractFilter
    {
        $v = $f['contratti'] ?? [];
        if ($this->cf === null || $this->cf->values() !== PmContractFilter::norm($v)) $this->cf = new PmContractFilter($this->pdo, $v);
        return $this->cf;
    }

    /** Opzioni del filtro contratto: le commesse del portafoglio direzionale. */
    public function valoriContratti(): array
    {
        return PmContractFilter::options($this->pdo, "SELECT `commessa` AS code FROM `{$this->v['v_cm_dir_commessa']}`");
    }

    /** v1.10.15 — clausola del filtro principale (alias c = v_cm_dir_commessa) per i report per tipologia. */
    public function whereSql(array $f): array { return $this->where($f); }

    /** v1.10.15 — nome effettivo (snapshot o vista) di v_cm_dir_commessa. */
    public function viewCommessa(): string { return $this->v['v_cm_dir_commessa']; }

    /** Clausola condivisa da quadro, elenchi ed export. */
    private function where(array $f): array
    {
        $w = ['1=1']; $a = [];

        if ($f['agente'] !== '') { $w[] = "c.`agente` = ?"; $a[] = $f['agente']; }

        // ricerca libera su codice, denominazione e cliente: sono i tre modi in
        // cui una commessa viene nominata a voce
        if ($f['q'] !== '') {
            $w[] = "(c.`commessa` LIKE ? OR c.`denominazione` LIKE ? OR c.`cliente` LIKE ?)";
            $like = '%' . $f['q'] . '%';
            $a[] = $like; $a[] = $like; $a[] = $like;
        }
        if ($f['cliente'] !== '') { $w[] = "c.`cliente` LIKE ?"; $a[] = '%' . $f['cliente'] . '%'; }
        if ($f['solo'] === 'aperte') $w[] = "c.`aperta` = 1";
        if ($f['solo'] === 'ricavo') $w[] = "c.`ha_ricavo` = 1";

        foreach (['stato' => 'c.`stato`', 'linee' => 'c.`linea_servizio`',
                  'aziende' => 'c.`azienda`'] as $k => $col) {
            if (!empty($f[$k])) {
                $w[] = "$col IN (" . implode(',', array_fill(0, count($f[$k]), '?')) . ")";
                foreach ($f[$k] as $v) $a[] = $v;
            }
        }
        $cf = $this->cf($f);
        if ($cf->active()) $w[] = $cf->sql('code', 'c.`commessa`', $a);   // v1.9.78
        if (!empty($f['uo'])) $w[] = PmUoFilter::projectCodeSql($f['uo'], 'c.`commessa`');   // v1.10.30
        // v1.9.94 — intervallo date: commesse attive nel periodo (durata che interseca [Da, A])
        if ($f['from'] !== '') { $w[] = "(c.`end_date` IS NULL OR c.`end_date` >= ?)";   $a[] = $f['from']; }
        if ($f['to']   !== '') { $w[] = "(c.`start_date` IS NULL OR c.`start_date` <= ?)"; $a[] = $f['to']; }
        return [implode(' AND ', $w), $a];
    }

    /**
     * Il quadro complessivo.
     *
     * Valore, costo e margine si calcolano SOLO sulle commesse a ricavo: le
     * interne consumano ore senza produrne per costruzione, e includerle
     * abbassa il margine descrivendo una realta' che non esiste.
     *
     * Le loro ore restano contate a parte, perche' sono capacita' impiegata.
     */
    public function quadro(array $f): array
    {
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT COUNT(*)                                   AS commesse,
                    SUM(c.`aperta` = 1)                        AS aperte,
                    SUM(c.`ha_ricavo` = 1)                     AS a_ricavo,
                    COUNT(DISTINCT c.`cliente`)                AS clienti,
                    COUNT(DISTINCT c.`agente`)                 AS agenti,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=1 THEN c.`valore`  ELSE 0 END), 2) AS valore,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=1 THEN c.`costo`   ELSE 0 END), 2) AS costo,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=1 THEN c.`margine` ELSE 0 END), 2) AS margine,
                    ROUND(SUM(c.`ore`), 2)                     AS ore,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=0 THEN c.`ore` ELSE 0 END), 2) AS ore_interne,
                    SUM(c.`interventi`)                        AS interventi,
                    SUM(c.`aperta`=1 AND c.`sforamento_critico`=1) AS sforate,
                    SUM(c.`aperta`=1 AND c.`consumo_valore_pct` >= 75
                                     AND c.`consumo_valore_pct` < 100) AS prossime,
                    SUM(c.`aperta`=1 AND c.`divergenza_pct` >= 20) AS divergenti,
                    SUM(c.`aperta`=1 AND c.`giorni_a_scadenza` BETWEEN 0 AND 30) AS in_scadenza,
                    SUM(c.`aperta`=1 AND c.`giorni_senza_movimenti` > 90) AS ferme,
                    SUM(" . self::FIDO . ") AS con_fido
               FROM `{$this->v['v_cm_dir_commessa']}` c
               LEFT JOIN `cm_projects` pf ON pf.`id` = c.`commessa_id` WHERE $w");
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();

        $r['margine_pct'] = ((float)($r['valore'] ?? 0)) > 0
            ? round(100 * (float)$r['margine'] / (float)$r['valore'], 1) : null;
        $r['costo_orario'] = ((float)($r['ore'] ?? 0)) > 0
            ? round((float)$r['costo'] / (float)$r['ore'], 2) : null;
        return $r;
    }

    /** Il confronto fra agenti — solo nel report direzionale. */
    public function agenti(array $f): array
    {
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT c.`agente`,
                    COUNT(*)                                   AS commesse,
                    SUM(c.`aperta` = 1)                        AS aperte,
                    COUNT(DISTINCT c.`cliente`)                AS clienti,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=1 THEN c.`valore`  ELSE 0 END), 2) AS valore,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=1 THEN c.`margine` ELSE 0 END), 2) AS margine,
                    ROUND(SUM(c.`ore`), 2)                     AS ore,
                    SUM(c.`aperta`=1 AND c.`sforamento_critico`=1) AS sforate,
                    SUM(c.`aperta`=1 AND c.`divergenza_pct` >= 20) AS divergenti,
                    SUM(c.`aperta`=1 AND c.`giorni_a_scadenza` BETWEEN 0 AND 30) AS in_scadenza,
                    SUM(c.`aperta`=1 AND c.`giorni_senza_movimenti` > 90) AS ferme
               FROM `{$this->v['v_cm_dir_commessa']}` c WHERE $w
              GROUP BY c.`agente` ORDER BY valore DESC");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        foreach ($out as &$r) {
            $r['margine_pct'] = ((float)$r['valore']) > 0
                ? round(100 * (float)$r['margine'] / (float)$r['valore'], 1) : null;
        }
        return $out;
    }

    /** Ripartizione su una dimensione, per i grafici. */
    public function perDimensione(array $f, string $dim, int $limite = 12): array
    {
        $ok = ['modello_label','linea_servizio','stato','azienda','agente','divisione'];
        if (!in_array($dim, $ok, true)) return [];
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT COALESCE(c.`$dim`, '(non attribuito)') AS voce,
                    COUNT(*) AS commesse,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=1 THEN c.`valore` ELSE 0 END), 2) AS valore,
                    ROUND(SUM(CASE WHEN c.`ha_ricavo`=1 THEN c.`margine` ELSE 0 END), 2) AS margine,
                    ROUND(SUM(c.`ore`), 2) AS ore
               FROM `{$this->v['v_cm_dir_commessa']}` c WHERE $w
              GROUP BY voce ORDER BY valore DESC LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * Le commesse che richiedono attenzione.
     *
     * Ordinate per priorita' e poi per valore: fra due problemi dello stesso
     * tipo, conta prima quello che pesa di piu'.
     */
    public function attenzione(array $f, int $limite = 200): array
    {
        $w = ['1=1']; $a = [];
        if ($f['agente'] !== '') { $w[] = "x.`agente` = ?"; $a[] = $f['agente']; }
        if (!empty($f['linee'])) {
            $w[] = "x.`commessa` IN (SELECT `commessa` FROM `{$this->v['v_cm_dir_commessa']}`
                                    WHERE `linea_servizio` IN ("
                 . implode(',', array_fill(0, count($f['linee']), '?')) . "))";
            foreach ($f['linee'] as $v) $a[] = $v;
        }
        $cf = $this->cf($f);
        if ($cf->active()) $w[] = $cf->sql('code', 'x.`commessa`', $a);   // v1.9.78
        if (!empty($f['uo'])) $w[] = PmUoFilter::projectCodeSql($f['uo'], 'x.`commessa`');   // v1.10.30
        // v1.9.94 — intervallo date
        if ($f['from'] !== '') { $w[] = "(pf.`end_date` IS NULL OR pf.`end_date` >= ?)";   $a[] = $f['from']; }
        if ($f['to']   !== '') { $w[] = "(pf.`start_date` IS NULL OR pf.`start_date` <= ?)"; $a[] = $f['to']; }
        $st = $this->pdo->prepare(
            "SELECT x.*, pf.`id` AS project_id, pf.`external_link`,
                    pf.`credit_on_value` AS fido_valore, pf.`credit_on_costs` AS fido_costi, " . self::FIDO . " AS fido
               FROM `{$this->v['v_cm_dir_attenzione']}` x
               LEFT JOIN `cm_projects` pf ON pf.`project_code` = x.`commessa`
              WHERE " . implode(' AND ', $w)
          . " ORDER BY x.`priorita`, x.`valore` DESC LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Le commesse del perimetro, per l'elenco e l'export. */
    public function commesse(array $f, int $limite = 500): array
    {
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT c.*, pf.`external_link`, pf.`credit_on_value` AS fido_valore, pf.`credit_on_costs` AS fido_costi, " . self::FIDO . " AS fido
               FROM `{$this->v['v_cm_dir_commessa']}` c
               LEFT JOIN `cm_projects` pf ON pf.`id` = c.`commessa_id` WHERE $w
              ORDER BY c.`valore` DESC LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Andamento mensile del perimetro. */
    public function andamento(array $f, int $mesi = 12): array
    {
        // v1.9.78 — con il filtro contratto la vista aggregata (mese x agente) non
        // basta: stesso calcolo di v_cm_dir_andamento sulle tabelle, ristretto
        // alle commesse selezionate.
        $cf = $this->cf($f);
        // v1.9.94 — con l'intervallo date i mesi sono quelli del periodo, non gli ultimi $mesi
        $daYm = $f['from'] !== '' ? substr($f['from'], 0, 7) : null;
        $aYm  = $f['to']   !== '' ? substr($f['to'], 0, 7)   : null;
        if ($cf->active() || !empty($f['uo'])) {   // v1.10.30 — anche con il filtro Unità Organizzativa
            $a = []; $w = ["ir.`report_date` IS NOT NULL"];
            if ($daYm === null && $aYm === null) { $w[] = "DATE_FORMAT(ir.`report_date`, '%Y-%m') >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m')"; $a[] = $mesi; }
            if ($daYm !== null) { $w[] = "DATE_FORMAT(ir.`report_date`, '%Y-%m') >= ?"; $a[] = $daYm; }
            if ($aYm  !== null) { $w[] = "DATE_FORMAT(ir.`report_date`, '%Y-%m') <= ?"; $a[] = $aYm; }
            if ($f['agente'] !== '') { $w[] = "COALESCE(p.`commercial_ref`, '(non attribuita)') = ?"; $a[] = $f['agente']; }
            if ($cf->active()) $w[] = $cf->sql('pid', 'ir.`project_id`', $a);
            if (!empty($f['uo'])) $w[] = PmUoFilter::projectSql($f['uo'], 'p.`id`');
            $st = $this->pdo->prepare(
                "SELECT DATE_FORMAT(ir.`report_date`, '%Y-%m') AS ym,
                        COUNT(DISTINCT ir.`project_id`) AS commesse, COUNT(*) AS interventi,
                        ROUND(SUM(COALESCE(ir.`quantity_hours`, 0)), 2) AS ore,
                        ROUND(SUM(COALESCE(ir.`company_cost_import`, 0)), 2) AS costo
                   FROM `cm_intervention_reports` ir JOIN `cm_projects` p ON p.`id` = ir.`project_id`
                  WHERE " . implode(' AND ', $w) . "
                  GROUP BY ym ORDER BY ym");
            $st->execute($a);
            $out = $st->fetchAll(PDO::FETCH_ASSOC);
            $st->closeCursor();
            return $out;
        }
        $w = []; $a = [];
        if ($daYm === null && $aYm === null) { $w[] = "a.`anno_mese` >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m')"; $a[] = $mesi; }
        if ($daYm !== null) { $w[] = "a.`anno_mese` >= ?"; $a[] = $daYm; }
        if ($aYm  !== null) { $w[] = "a.`anno_mese` <= ?"; $a[] = $aYm; }
        if ($f['agente'] !== '') { $w[] = "a.`agente` = ?"; $a[] = $f['agente']; }
        $st = $this->pdo->prepare(
            "SELECT a.`anno_mese` AS ym, SUM(a.`commesse_movimentate`) AS commesse,
                    SUM(a.`interventi`) AS interventi, ROUND(SUM(a.`ore`), 2) AS ore,
                    ROUND(SUM(a.`costo`), 2) AS costo
               FROM `{$this->v['v_cm_dir_andamento']}` a WHERE " . implode(' AND ', $w)
          . " GROUP BY a.`anno_mese` ORDER BY a.`anno_mese`");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.9.94 — Valore degli ordini cliente per competenza (pro-rata temporis mensile, ProRata).
     *
     * Fonte: ordini della commessa (cm_project_operations, tipi COR «Ordine cliente» e COV «Riporto da
     * contratto precedente», importo = revenue, data = op_date). Commesse a ricavo senza ordini: il valore
     * contrattuale della commessa con data ordine = inizio commessa (indicato come «valore contratto»).
     * Durata: start_date → end_date della commessa; l'inizio si sposta alla data ordine se successiva.
     * Periodo: [Data Inizio, Data Fine] del filtro; senza date, l'intera durata.
     *
     * @return array{periodo:array, anni:array<int,array>, commesse:array<int,array>, totale:float}
     */
    public function competenza(array $f): array
    {
        require_once __DIR__ . '/ProRata.php';
        [$w, $a] = $this->where($f);
        $st = $this->pdo->prepare(
            "SELECT c.`commessa_id`, c.`commessa`, c.`denominazione`, c.`cliente`, c.`agente`, c.`stato`,
                    c.`start_date`, c.`end_date`, c.`valore`, pf.`external_link`,
                    pf.`credit_on_value` AS fido_valore, pf.`credit_on_costs` AS fido_costi, " . self::FIDO . " AS fido,
                    o.`op_type_code` AS tipo, o.`op_date` AS data_ordine, o.`order_code` AS ordine, o.`revenue` AS importo
               FROM `{$this->v['v_cm_dir_commessa']}` c
               JOIN `cm_projects` pf ON pf.`id` = c.`commessa_id`
               LEFT JOIN `cm_project_operations` o
                      ON o.`project_id` = c.`commessa_id` AND o.`op_type_code` IN ('COR','COV') AND COALESCE(o.`revenue`,0) <> 0
              WHERE $w AND c.`ha_ricavo` = 1 AND c.`start_date` IS NOT NULL AND c.`end_date` IS NOT NULL
              ORDER BY c.`commessa`, o.`op_date`");
        $st->execute($a);
        $da = $f['from'] !== '' ? $f['from'] : null;
        $al = $f['to']   !== '' ? $f['to']   : null;
        $anni = []; $comm = []; $tot = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $daOrd = $r['tipo'] !== null;
            $imp   = $daOrd ? (float)$r['importo'] : (float)$r['valore'];
            if ($imp == 0.0) continue;
            $p = ProRata::ripartisci($imp, (string)$r['start_date'], (string)$r['end_date'],
                                     $daOrd ? (string)$r['data_ordine'] : null, $da, $al);
            $id = (int)$r['commessa_id'];
            if (!isset($comm[$id])) {
                $comm[$id] = ['project_id' => $id, 'external_link' => $r['external_link'],
                              'commessa' => $r['commessa'], 'denominazione' => $r['denominazione'], 'cliente' => $r['cliente'],
                              'agente' => $r['agente'], 'stato' => $r['stato'], 'inizio' => $r['start_date'], 'fine' => $r['end_date'],
                              'fido' => (int)$r['fido'], 'fido_valore' => $r['fido_valore'], 'fido_costi' => $r['fido_costi'],
                              'ordini' => [], 'importo' => 0.0, 'valore_periodo' => 0.0, 'anni' => []];
            }
            $c = &$comm[$id];
            $c['ordini'][] = ['fonte' => $daOrd ? ($r['tipo'] === 'COV' ? 'Riporto' : 'Ordine cliente') : 'Valore contratto',
                              'codice' => $r['ordine'], 'data' => $daOrd ? $r['data_ordine'] : $r['start_date'],
                              'importo' => $imp, 'mesi_totali' => $p['mesi_totali'], 'quota_mensile' => $p['quota_mensile'],
                              'inizio' => $p['inizio'], 'fine' => $p['fine'], 'anni' => $p['anni']];
            $c['importo'] += $imp;
            $c['valore_periodo'] += $p['valore_periodo'];
            foreach ($p['anni'] as $y => $x) {
                $c['anni'][$y]['valore'] = ($c['anni'][$y]['valore'] ?? 0) + $x['valore'];
                $c['anni'][$y]['mesi']   = max($c['anni'][$y]['mesi'] ?? 0, $x['mesi']);
                $anni[$y]['valore']   = ($anni[$y]['valore'] ?? 0) + $x['valore'];
                $anni[$y]['commesse'][$id] = true;
            }
            $tot += $p['valore_periodo'];
            unset($c);
        }
        ksort($anni);
        foreach ($anni as $y => &$x) { $x['valore'] = round($x['valore'], 2); $x['commesse'] = count($x['commesse']); }
        unset($x);
        $comm = array_values(array_filter($comm, fn($c) => $c['valore_periodo'] != 0.0));
        usort($comm, fn($p, $q) => $q['valore_periodo'] <=> $p['valore_periodo']);
        return ['periodo' => ['da' => $da, 'a' => $al], 'anni' => $anni, 'commesse' => $comm, 'totale' => round($tot, 2)];
    }

    /** Elenco degli agenti, per il selettore. */
    public function elencoAgenti(): array
    {
        try {
            return $this->pdo->query(
                "SELECT DISTINCT `agente` FROM `{$this->v['v_cm_dir_commessa']}`
                  WHERE `agente` <> '' ORDER BY `agente`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }

    /** Valori per i filtri. */
    public function valori(string $dim): array
    {
        $ok = ['stato', 'linea_servizio', 'azienda'];
        if (!in_array($dim, $ok, true)) return [];
        try {
            return $this->pdo->query(
                "SELECT DISTINCT `$dim` FROM `{$this->v['v_cm_dir_commessa']}`
                  WHERE `$dim` IS NOT NULL AND `$dim` <> '' ORDER BY `$dim`")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }

    /**
     * Il perimetro dell'agente rispetto al totale.
     *
     * Serve alla scheda: chi legge deve sapere che cosa NON sta vedendo. Una
     * scheda che mostra 12 commesse senza dire che il portafoglio ne ha 1.062
     * lascia credere di aver visto tutto.
     */
    public function perimetro(string $agente, array $f = []): array
    {
        // v1.9.78 — con il filtro contratto il perimetro e' misurato dentro la selezione
        $a = [$agente, $agente];
        $wc = '';
        $cf = $this->cf($f);
        if ($cf->active()) $wc = ' WHERE ' . $cf->sql('code', '`commessa`', $a);
        if (!empty($f['uo'])) $wc .= ($wc === '' ? ' WHERE ' : ' AND ') . PmUoFilter::projectCodeSql($f['uo'], '`commessa`');   // v1.10.30
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS tot,
                    SUM(`agente` = ?) AS suo,
                    ROUND(SUM(CASE WHEN `ha_ricavo`=1 THEN `valore` ELSE 0 END), 2) AS valore_tot,
                    ROUND(SUM(CASE WHEN `agente` = ? AND `ha_ricavo`=1
                              THEN `valore` ELSE 0 END), 2) AS valore_suo
               FROM `{$this->v['v_cm_dir_commessa']}`$wc");
        $st->execute($a);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        $r['pct_commesse'] = ((int)($r['tot'] ?? 0)) > 0
            ? round(100 * (int)$r['suo'] / (int)$r['tot'], 1) : null;
        $r['pct_valore'] = ((float)($r['valore_tot'] ?? 0)) > 0
            ? round(100 * (float)$r['valore_suo'] / (float)$r['valore_tot'], 1) : null;
        return $r;
    }
}
