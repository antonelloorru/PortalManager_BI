<?php
/**
 * DirModel — letture per il report direzionale e le schede commerciale.
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

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

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
        ];
        return $f;
    }

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
                    SUM(c.`aperta`=1 AND c.`giorni_senza_movimenti` > 90) AS ferme
               FROM `v_cm_dir_commessa` c WHERE $w");
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
               FROM `v_cm_dir_commessa` c WHERE $w
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
               FROM `v_cm_dir_commessa` c WHERE $w
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
        if ($f['agente'] !== '') { $w[] = "`agente` = ?"; $a[] = $f['agente']; }
        if (!empty($f['linee'])) {
            $w[] = "`commessa` IN (SELECT `commessa` FROM `v_cm_dir_commessa`
                                    WHERE `linea_servizio` IN ("
                 . implode(',', array_fill(0, count($f['linee']), '?')) . "))";
            foreach ($f['linee'] as $v) $a[] = $v;
        }
        $st = $this->pdo->prepare(
            "SELECT * FROM `v_cm_dir_attenzione` WHERE " . implode(' AND ', $w)
          . " ORDER BY `priorita`, `valore` DESC LIMIT " . (int)$limite);
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
            "SELECT * FROM `v_cm_dir_commessa` c WHERE $w
              ORDER BY c.`valore` DESC LIMIT " . (int)$limite);
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Andamento mensile del perimetro. */
    public function andamento(array $f, int $mesi = 12): array
    {
        $w = ["a.`anno_mese` >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL ? MONTH), '%Y-%m')"];
        $a = [$mesi];
        if ($f['agente'] !== '') { $w[] = "a.`agente` = ?"; $a[] = $f['agente']; }
        $st = $this->pdo->prepare(
            "SELECT a.`anno_mese` AS ym, SUM(a.`commesse_movimentate`) AS commesse,
                    SUM(a.`interventi`) AS interventi, ROUND(SUM(a.`ore`), 2) AS ore,
                    ROUND(SUM(a.`costo`), 2) AS costo
               FROM `v_cm_dir_andamento` a WHERE " . implode(' AND ', $w)
          . " GROUP BY a.`anno_mese` ORDER BY a.`anno_mese`");
        $st->execute($a);
        $out = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $out;
    }

    /** Elenco degli agenti, per il selettore. */
    public function elencoAgenti(): array
    {
        try {
            return $this->pdo->query(
                "SELECT DISTINCT `agente` FROM `v_cm_dir_commessa`
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
                "SELECT DISTINCT `$dim` FROM `v_cm_dir_commessa`
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
    public function perimetro(string $agente): array
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS tot,
                    SUM(`agente` = ?) AS suo,
                    ROUND(SUM(CASE WHEN `ha_ricavo`=1 THEN `valore` ELSE 0 END), 2) AS valore_tot,
                    ROUND(SUM(CASE WHEN `agente` = ? AND `ha_ricavo`=1
                              THEN `valore` ELSE 0 END), 2) AS valore_suo
               FROM `v_cm_dir_commessa`");
        $st->execute([$agente, $agente]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $st->closeCursor();
        $r['pct_commesse'] = ((int)($r['tot'] ?? 0)) > 0
            ? round(100 * (int)$r['suo'] / (int)$r['tot'], 1) : null;
        $r['pct_valore'] = ((float)($r['valore_tot'] ?? 0)) > 0
            ? round(100 * (float)$r['valore_suo'] / (float)$r['valore_tot'], 1) : null;
        return $r;
    }
}
