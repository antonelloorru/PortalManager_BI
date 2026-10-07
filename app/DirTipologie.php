<?php
/**
 * PortalManager — app/DirTipologie.php  (v1.10.15)
 *
 * Report Direzionale › analisi per tipologia di commessa. Un'unica sorgente per vista, stampa e export
 * (PmReport → HTML / DOCX / XLSX / CSV / PDF), dal SOLO filtro principale della pagina (DirModel::whereSql:
 * agente commerciale, cliente, ricerca, perimetro, stato, linee, aziende, contratto, periodo Da–A).
 *
 *   acm     WTS-ACM  valore venduto vs valore a listino consumato (ore × tariffa di fascia del listino di commessa),
 *                    giornate a listino, Consumo % e Performance %, esito In-Line / Over / Under (tolleranza ±toll %)
 *   css     WTS-CSS  consumo per anno: ore, giorni uomo, giornate equivalenti, valore addebitato, valore a listino
 *   cc      WTS-CC   come CSS + ordini cliente registrati a sistema (fatturato effettivo) e flag di presenza
 *   meg     WTS-MEG  valore di vendita, costo del lavoro, costo totale sostenuto (gestionale), margine
 *   nv      NV_*     ore per sottotipologia e incidenza % sulle ore totali dei servizi erogati nel perimetro
 *   moduli  tutte    per tecnico e fascia: moduli di intervento, ore, giorni lavorati, giornate equivalenti
 *
 * Righe di intervento: v_cm_it_giorni_base (stessa base, fasce e listino della Relazione di Servizio IT).
 * Periodo dei consumi: Da–A del filtro; senza date l'anno solare corrente. ACM: consumo cumulato fino alla data A
 * (la performance si misura sull'intero contratto).
 */
declare(strict_types=1);

require_once __DIR__ . '/PmReport.php';
require_once __DIR__ . '/PmSnapshot.php';

final class DirTipologie
{
    public const TABS = ['acm' => 'ACM', 'css' => 'WTS-CSS', 'cc' => 'WTS-CC', 'meg' => 'WTS-MEG', 'nv' => 'NV_', 'moduli' => 'Moduli di intervento'];
    public const ESITI = ['inline' => 'In-Line', 'over' => 'Over-performance', 'under' => 'Under-performance', 'nd' => 'Non valutabile'];
    public const ESITO_COL = ['inline' => '2563EB', 'over' => '16A34A', 'under' => 'DC2626', 'nd' => '94A3B8'];
    public const TOLL_DEFAULT = 5.0;

    private string $vg;

    public function __construct(private PDO $pdo, private DirModel $dm)
    {
        $this->vg = PmSnapshot::names($pdo, ['v_cm_it_giorni_base'])['v_cm_it_giorni_base'];
    }

    /** Parametri propri della scheda ACM (nel blocco filtri principale). */
    public static function normExtra(array $q, PDO $pdo): array
    {
        $e = $q['esito'] ?? [];
        if (is_string($e)) $e = $e === '' ? [] : explode(',', $e);
        $e = array_values(array_intersect(array_keys(self::ESITI), array_map('strval', (array)$e)));
        $def = self::TOLL_DEFAULT;
        try {
            $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'dir.acm_tolleranza_pct'"); $st->execute();
            $v = $st->fetchColumn(); if ($v !== false && is_numeric($v)) $def = (float)$v;
        } catch (Throwable $x) {}
        $t = isset($q['toll']) && $q['toll'] !== '' && is_numeric(str_replace(',', '.', (string)$q['toll'])) ? (float)str_replace(',', '.', (string)$q['toll']) : $def;
        return ['esito' => $e, 'toll' => max(0.0, min(50.0, round($t, 1)))];
    }

    /** Periodo dei consumi: Da–A del filtro, altrimenti l'anno solare corrente. */
    public static function periodo(array $f): array
    {
        $da = $f['from'] !== '' ? $f['from'] : date('Y') . '-01-01';
        $a  = $f['to']   !== '' ? $f['to']   : date('Y') . '-12-31';
        return ['da' => $da, 'a' => $a, 'label' => date('d/m/Y', strtotime($da)) . ' – ' . date('d/m/Y', strtotime($a)),
                'default' => $f['from'] === '' && $f['to'] === ''];
    }

    private static function slCond(string $tab): string
    {
        return match ($tab) {
            'acm' => "p.`service_line` = 'WTS-ACM'", 'css' => "p.`service_line` = 'WTS-CSS'", 'cc' => "p.`service_line` = 'WTS-CC'",
            'meg' => "p.`service_line` = 'WTS-MEG'", 'nv' => "p.`service_line` LIKE 'NV\\_%'", default => '1=1',
        };
    }

    private function q(string $sql, array $a): array
    {
        $st = $this->pdo->prepare($sql); $st->execute($a);
        $r = $st->fetchAll(PDO::FETCH_ASSOC); $st->closeCursor();
        return $r;
    }

    /** Commesse della tipologia nel perimetro del filtro principale. */
    private function commesse(array $f, string $tab): array
    {
        [$w, $a] = $this->dm->whereSql($f);
        $rows = $this->q("SELECT c.`commessa_id`, c.`commessa`, c.`denominazione`, c.`cliente`, c.`agente`, c.`stato`,
                                 c.`start_date`, c.`end_date`, c.`valore`, c.`costo`, p.`service_line`,
                                 COALESCE(NULLIF(TRIM(p.`description`), ''), c.`denominazione`) AS descrizione,
                                 p.`value_total`, p.`margin_total`
                            FROM `{$this->dm->viewCommessa()}` c JOIN `cm_projects` p ON p.`id` = c.`commessa_id`
                           WHERE $w AND " . self::slCond($tab) . " ORDER BY c.`agente`, c.`cliente`, c.`commessa`", $a);
        $out = [];
        foreach ($rows as $r) $out[(string)$r['commessa']] = $r;
        return $out;
    }

    /** Sottoquery delle commesse della tipologia (stessi parametri del filtro principale). */
    private function sub(array $f, string $tab, array &$a): string
    {
        [$w, $wa] = $this->dm->whereSql($f);
        foreach ($wa as $v) $a[] = $v;
        return "SELECT c.`commessa` FROM `{$this->dm->viewCommessa()}` c JOIN `cm_projects` p ON p.`id` = c.`commessa_id` WHERE $w AND " . self::slCond($tab);
    }

    // ── ACM ─────────────────────────────────────────────────────────
    public function acm(array $f, array $x): array
    {
        $cm = $this->commesse($f, 'acm');
        $a = [];
        $sub = $this->sub($f, 'acm', $a);
        $cond = '';
        if ($f['to'] !== '') { $cond = ' AND g.`giorno` <= ?'; $a[] = $f['to']; }
        $cons = [];
        foreach ($this->q("SELECT g.`commessa`, COUNT(*) AS moduli, ROUND(SUM(g.`ore`), 2) AS ore,
                                  ROUND(SUM(CASE WHEN g.`produzione_teorica` IS NOT NULL THEN g.`ore` ELSE 0 END), 2) AS ore_val,
                                  ROUND(SUM(CASE WHEN g.`produzione_teorica` IS NULL THEN g.`ore` ELSE 0 END), 2) AS ore_non_val,
                                  ROUND(SUM(COALESCE(g.`produzione_teorica`, 0)), 2) AS listino,
                                  MIN(g.`giorno`) AS dal, MAX(g.`giorno`) AS al
                             FROM `{$this->vg}` g WHERE g.`commessa` IN ($sub)$cond GROUP BY g.`commessa`", $a) as $r) $cons[(string)$r['commessa']] = $r;
        $toll = (float)$x['toll'];
        $out = [];
        foreach ($cm as $k => $c) {
            $g = $cons[$k] ?? ['moduli' => 0, 'ore' => 0, 'ore_val' => 0, 'ore_non_val' => 0, 'listino' => 0, 'dal' => null, 'al' => null];
            $vend = (float)$c['valore']; $list = (float)$g['listino'];
            $gg = round((float)$g['ore_val'] / 8, 2);
            $tgg = $gg > 0 ? $list / $gg : null;                               // tariffa giornaliera media a listino
            $ggVend = $tgg ? round($vend / $tgg, 2) : null;                    // giornate acquistate al listino medio
            $consPct = $vend > 0 ? round($list / $vend * 100, 1) : null;
            $perf = $consPct === null ? null : round(100 - $consPct, 1);
            $es = $consPct === null || $list <= 0 ? 'nd' : (abs($consPct - 100) <= $toll ? 'inline' : ($consPct < 100 ? 'over' : 'under'));
            if ($x['esito'] && !in_array($es, $x['esito'], true)) continue;
            $out[] = $c + ['moduli' => (int)$g['moduli'], 'ore' => (float)$g['ore'], 'ore_non_val' => (float)$g['ore_non_val'],
                           'listino' => $list, 'giornate' => $gg, 'tariffa_gg' => $tgg !== null ? round($tgg, 2) : null, 'giornate_vendute' => $ggVend,
                           'residuo' => round($vend - $list, 2), 'consumo_pct' => $consPct, 'performance_pct' => $perf, 'esito' => $es];
        }
        return $out;
    }

    // ── CSS / CC: consumo per anno ──────────────────────────────────
    public function consumo(array $f, string $tab): array
    {
        $cm = $this->commesse($f, $tab);
        $p = self::periodo($f);
        $a = [];
        $sub = $this->sub($f, $tab, $a);
        $a[] = $p['da']; $a[] = $p['a'];
        $rows = $this->q("SELECT g.`commessa`, YEAR(g.`giorno`) AS anno, COUNT(*) AS moduli, ROUND(SUM(g.`ore`), 2) AS ore,
                                 COUNT(DISTINCT CONCAT(g.`operatore`, '|', g.`giorno`)) AS giorni_uomo,
                                 ROUND(SUM(g.`valore_addebitato`), 2) AS addebitato, ROUND(SUM(COALESCE(g.`produzione_teorica`, 0)), 2) AS listino
                            FROM `{$this->vg}` g WHERE g.`commessa` IN ($sub) AND g.`giorno` BETWEEN ? AND ?
                           GROUP BY g.`commessa`, YEAR(g.`giorno`)", $a);
        $by = [];
        foreach ($rows as $r) $by[(string)$r['commessa']][(int)$r['anno']] = $r;
        $fatt = [];
        if ($tab === 'cc') {
            $a2 = [];
            $sub2 = $this->sub($f, $tab, $a2);
            $a2[] = $p['da']; $a2[] = $p['a'];
            foreach ($this->q("SELECT o.`project_code` AS commessa, YEAR(o.`op_date`) AS anno, COUNT(*) AS ordini,
                                      ROUND(SUM(COALESCE(o.`revenue`, 0)), 2) AS ordinato, ROUND(SUM(CASE WHEN o.`is_invoiced` = 1 THEN COALESCE(o.`invoice_amount`, o.`revenue`, 0) ELSE 0 END), 2) AS fatturato_flag
                                 FROM `cm_project_operations` o
                                WHERE o.`op_type_code` = 'COR' AND o.`project_code` IN ($sub2) AND o.`op_date` BETWEEN ? AND ?
                                GROUP BY o.`project_code`, YEAR(o.`op_date`)", $a2) as $r) $fatt[(string)$r['commessa']][(int)$r['anno']] = $r;
            $a3 = [];
            $sub3 = $this->sub($f, $tab, $a3);
            $tutti = [];
            foreach ($this->q("SELECT o.`project_code` AS commessa, COUNT(*) AS n FROM `cm_project_operations` o WHERE o.`op_type_code` = 'COR' AND o.`project_code` IN ($sub3) GROUP BY o.`project_code`", $a3) as $r)
                $tutti[(string)$r['commessa']] = (int)$r['n'];
        }
        $out = [];
        foreach ($cm as $k => $c) {
            $anni = array_unique(array_merge(array_keys($by[$k] ?? []), array_keys($fatt[$k] ?? [])));
            sort($anni);
            if (!$anni) $anni = [null];
            foreach ($anni as $y) {
                $g = $y !== null ? ($by[$k][$y] ?? null) : null;
                $row = $c + ['anno' => $y, 'moduli' => (int)($g['moduli'] ?? 0), 'ore' => (float)($g['ore'] ?? 0), 'giorni_uomo' => (int)($g['giorni_uomo'] ?? 0),
                             'giornate' => round((float)($g['ore'] ?? 0) / 8, 2), 'addebitato' => (float)($g['addebitato'] ?? 0), 'listino' => (float)($g['listino'] ?? 0)];
                if ($tab === 'cc') {
                    $fy = $y !== null ? ($fatt[$k][$y] ?? null) : null;
                    $row['ordini'] = (int)($fy['ordini'] ?? 0);
                    $row['fatturato'] = (float)($fy['ordinato'] ?? 0);
                    $row['ordini_tot'] = $tutti[$k] ?? 0;
                    $row['fatture_registrate'] = ($tutti[$k] ?? 0) > 0;
                }
                $out[] = $row;
            }
        }
        return $out;
    }

    // ── MEG ─────────────────────────────────────────────────────────
    public function meg(array $f): array
    {
        $out = [];
        foreach ($this->commesse($f, 'meg') as $c) {
            $vend = (float)$c['valore'];
            $tot = $c['margin_total'] !== null ? round((float)$c['value_total'] - (float)$c['margin_total'], 2) : (float)$c['costo'];
            $tot = max($tot, (float)$c['costo']);
            $out[] = $c + ['costo_lavoro' => (float)$c['costo'], 'costo_totale' => $tot, 'margine' => round($vend - $tot, 2),
                           'margine_pct' => $vend > 0 ? round(($vend - $tot) / $vend * 100, 1) : null];
        }
        return $out;
    }

    // ── NV_ ─────────────────────────────────────────────────────────
    public function nv(array $f): array
    {
        $p = self::periodo($f);
        $a = [];
        $subAll = $this->sub($f, 'moduli', $a);
        $a[] = $p['da']; $a[] = $p['a'];
        $tot = (float)($this->q("SELECT ROUND(SUM(g.`ore`), 2) AS ore FROM `{$this->vg}` g WHERE g.`commessa` IN ($subAll) AND g.`giorno` BETWEEN ? AND ?", $a)[0]['ore'] ?? 0);
        $a = [];
        $sub = $this->sub($f, 'nv', $a);
        $a[] = $p['da']; $a[] = $p['a'];
        $rows = $this->q("SELECT g.`codice_linea` AS tipologia, MAX(g.`contratto`) AS descrizione, COUNT(DISTINCT g.`commessa`) AS commesse,
                                 COUNT(*) AS moduli, COUNT(DISTINCT g.`operatore`) AS persone, ROUND(SUM(g.`ore`), 2) AS ore,
                                 COUNT(DISTINCT CONCAT(g.`operatore`, '|', g.`giorno`)) AS giorni_uomo
                            FROM `{$this->vg}` g WHERE g.`commessa` IN ($sub) AND g.`giorno` BETWEEN ? AND ?
                           GROUP BY g.`codice_linea` ORDER BY ore DESC", $a);
        $nvTot = array_sum(array_map(fn($r) => (float)$r['ore'], $rows));
        foreach ($rows as &$r) {
            $r['ore'] = (float)$r['ore'];
            $r['giornate'] = round($r['ore'] / 8, 2);
            $r['incidenza_pct'] = $tot > 0 ? round($r['ore'] / $tot * 100, 2) : null;
            $r['quota_nv_pct'] = $nvTot > 0 ? round($r['ore'] / $nvTot * 100, 1) : null;
        }
        unset($r);
        return ['righe' => $rows, 'ore_totali' => $tot, 'ore_nv' => $nvTot];
    }

    // ── Moduli di intervento per tecnico e fascia ───────────────────
    public function moduli(array $f): array
    {
        $p = self::periodo($f);
        $a = [];
        $sub = $this->sub($f, 'moduli', $a);
        $a[] = $p['da']; $a[] = $p['a'];
        $rows = $this->q("SELECT g.`operatore` AS tecnico, g.`fascia`, COUNT(*) AS moduli, ROUND(SUM(g.`ore`), 2) AS ore,
                                 COUNT(DISTINCT g.`giorno`) AS giorni, COUNT(DISTINCT g.`commessa`) AS commesse
                            FROM `{$this->vg}` g WHERE g.`commessa` IN ($sub) AND g.`giorno` BETWEEN ? AND ?
                           GROUP BY g.`operatore`, g.`fascia` ORDER BY g.`operatore`, g.`fascia`", $a);
        $a = [];
        $sub = $this->sub($f, 'moduli', $a);
        $a[] = $p['da']; $a[] = $p['a'];
        $tec = $this->q("SELECT g.`operatore` AS tecnico, COUNT(*) AS moduli, ROUND(SUM(g.`ore`), 2) AS ore,
                                COUNT(DISTINCT g.`giorno`) AS giorni, COUNT(DISTINCT g.`commessa`) AS commesse
                           FROM `{$this->vg}` g WHERE g.`commessa` IN ($sub) AND g.`giorno` BETWEEN ? AND ?
                          GROUP BY g.`operatore` ORDER BY ore DESC", $a);
        $fasce = array_values(array_unique(array_map(fn($r) => (string)$r['fascia'], $rows)));
        sort($fasce);
        return ['righe' => $rows, 'tecnici' => $tec, 'fasce' => $fasce];
    }

    // ── report (vista, stampa, DOCX, XLSX, CSV, PDF) ────────────────
    public function build(string $tab, array $f, array $x, string $filtriTxt): PmReport
    {
        $p = self::periodo($f);
        $r = new PmReport('Report direzionale — ' . self::TABS[$tab], '', 'L');
        $r->meta('Generato il ' . date('d/m/Y H:i') . ' · ' . $filtriTxt);
        $eur = fn($v) => number_format((float)$v, 0, ',', '.') . ' €';
        $num = fn($v, $d = 0) => number_format((float)$v, $d, ',', '.');
        $dt = fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : null;
        $base = ['Commerciale', 'Cliente', 'Codice commessa', 'Descrizione', 'Dal', 'Al'];
        $bv = fn(array $c) => [(string)$c['agente'], (string)$c['cliente'], (string)$c['commessa'], (string)$c['descrizione'], $dt($c['start_date']), $dt($c['end_date'])];

        if ($tab === 'acm') {
            $rows = $this->acm($f, $x);
            $cnt = array_fill_keys(array_keys(self::ESITI), 0);
            $sv = $sl = 0.0;
            foreach ($rows as $c) { $cnt[$c['esito']]++; if ($c['esito'] !== 'nd') { $sv += $c['valore']; $sl += $c['listino']; } }
            $cp = $sv > 0 ? $sl / $sv * 100 : null;
            $r->note('Valore a listino = Σ ore × tariffa oraria di fascia del listino della commessa (stesso calcolo della Relazione di Servizio IT), cumulato'
                . ($f['to'] !== '' ? ' fino al ' . $dt($f['to']) : ' fino a oggi') . '. Giornate a listino = ore valorizzate / 8. Consumo % = listino / venduto.'
                . ' Performance % = 100 − Consumo %. Esito: In-Line se il consumo è 100% ± ' . $num($x['toll'], 1) . '%, Over-performance se inferiore, Under-performance se superiore; Non valutabile senza valore venduto o senza consumo a listino.');
            $r->kpi([
                ['label' => 'Commesse ACM', 'value' => $num(count($rows)), 'sub' => $cnt['nd'] . ' non valutabili'],
                ['label' => 'Venduto (valutabili)', 'value' => $eur($sv), 'color' => '2563EB'],
                ['label' => 'Consumato a listino', 'value' => $eur($sl), 'color' => '7C3AED'],
                ['label' => 'Performance complessiva', 'value' => $cp === null ? '—' : $num(100 - $cp, 1) . ' %', 'sub' => $cp === null ? '' : 'consumo ' . $num($cp, 1) . ' %', 'color' => $cp !== null && $cp > 100 + $x['toll'] ? 'DC2626' : '16A34A'],
                ['label' => 'In-Line', 'value' => (string)$cnt['inline'], 'color' => self::ESITO_COL['inline']],
                ['label' => 'Over-performance', 'value' => (string)$cnt['over'], 'color' => self::ESITO_COL['over']],
                ['label' => 'Under-performance', 'value' => (string)$cnt['under'], 'color' => self::ESITO_COL['under']],
            ]);
            $r->bars('Commesse per esito', array_map(fn($k) => [self::ESITI[$k], (float)$cnt[$k]], array_keys(self::ESITI)), '', '2563EB');
            $ag = [];
            foreach ($rows as $c) if ($c['esito'] !== 'nd') { $k = $c['agente'] ?: '(n.d.)'; $ag[$k]['v'] = ($ag[$k]['v'] ?? 0) + $c['valore']; $ag[$k]['l'] = ($ag[$k]['l'] ?? 0) + $c['listino']; }
            $agR = [];
            foreach ($ag as $k => $v) if ($v['v'] > 0) $agR[] = [$k, round(100 - $v['l'] / $v['v'] * 100, 1)];
            usort($agR, fn($a, $b) => $b[1] <=> $a[1]);
            $r->bars('Performance % per commerciale', array_slice($agR, 0, 20), '%', '16A34A');
            $r->table('Commesse ACM', array_merge($base, ['Venduto €', 'Consumato a listino €', 'Residuo €', 'Giornate a listino', 'Tariffa media €/gg', 'Giornate vendute (listino)', 'Ore', 'Ore non valorizzate', 'Consumo %', 'Performance %', 'Esito']),
                array_map(fn($c) => array_merge($bv($c), [round((float)$c['valore'], 2), $c['listino'], $c['residuo'], $c['giornate'], $c['tariffa_gg'], $c['giornate_vendute'], $c['ore'], $c['ore_non_val'], $c['consumo_pct'], $c['performance_pct'], self::ESITI[$c['esito']]]), $rows),
                ['dec' => [6 => 2, 7 => 2, 8 => 2, 9 => 1, 10 => 2, 11 => 1, 12 => 1, 13 => 1, 14 => 1, 15 => 1]]);
            return $r;
        }

        if ($tab === 'css' || $tab === 'cc') {
            $rows = $this->consumo($f, $tab);
            $r->note('Consumo nel periodo ' . $p['label'] . ($p['default'] ? ' (anno corrente: impostare Da–A per altri periodi)' : '') . ', per anno.'
                . ' Giorni uomo = coppie tecnico/giorno distinte; giornate equivalenti = ore / 8. Valore addebitato e valore a listino come nella Relazione di Servizio IT.'
                . ($tab === 'cc' ? ' Fatturato = ordini cliente (operazioni «Ordine cliente») registrati a sistema nel periodo; «Fatture registrate» = esistono ordini cliente per la commessa.' : ''));
            $tOre = array_sum(array_column($rows, 'ore')); $tAdd = array_sum(array_column($rows, 'addebitato')); $tGu = array_sum(array_column($rows, 'giorni_uomo'));
            $cards = [
                ['label' => 'Commesse', 'value' => $num(count(array_unique(array_column($rows, 'commessa'))))],
                ['label' => 'Ore', 'value' => $num($tOre, 1) . ' h', 'color' => '2563EB'],
                ['label' => 'Giorni uomo', 'value' => $num($tGu), 'sub' => $num($tOre / 8, 1) . ' giornate equivalenti', 'color' => '7C3AED'],
                ['label' => 'Valore addebitato', 'value' => $eur($tAdd), 'color' => '16A34A'],
            ];
            if ($tab === 'cc') {
                $tF = array_sum(array_column($rows, 'fatturato'));
                $conF = count(array_unique(array_column(array_filter($rows, fn($c) => $c['fatture_registrate']), 'commessa')));
                $cards[] = ['label' => 'Fatturato (ordini cliente)', 'value' => $eur($tF), 'color' => '0891B2'];
                $cards[] = ['label' => 'Commesse con fatture registrate', 'value' => $num($conF), 'sub' => 'su ' . $num(count(array_unique(array_column($rows, 'commessa')))), 'color' => 'EA580C'];
            }
            $r->kpi($cards);
            $perAnno = [];
            foreach ($rows as $c) if ($c['anno']) { $perAnno[$c['anno']] = ($perAnno[$c['anno']] ?? 0) + $c['ore']; }
            ksort($perAnno);
            $r->bars('Ore per anno', array_map(fn($y, $v) => [(string)$y, round($v, 1)], array_keys($perAnno), $perAnno), 'h', '2563EB');
            $top = [];
            foreach ($rows as $c) $top[$c['commessa']] = ($top[$c['commessa']] ?? 0) + $c['ore'];
            arsort($top);
            $r->bars('Prime 15 commesse per ore', array_map(fn($k, $v) => [$k . ' · ' . mb_strimwidth((string)($rows[array_search($k, array_column($rows, 'commessa'))]['cliente'] ?? ''), 0, 30, '…'), round($v, 1)], array_keys(array_slice($top, 0, 15, true)), array_slice($top, 0, 15, true)), 'h', '7C3AED');
            $h = array_merge($base, ['Anno', 'Moduli', 'Ore', 'Giorni uomo', 'Giornate equiv.', 'Valore addebitato €', 'Valore a listino €']);
            if ($tab === 'cc') $h = array_merge($h, ['Ordini cliente nell\'anno', 'Fatturato €', 'Fatture registrate']);
            $r->table($tab === 'cc' ? 'Commesse WTS-CC' : 'Commesse WTS-CSS', $h, array_map(function ($c) use ($bv, $tab) {
                $row = array_merge($bv($c), [$c['anno'], $c['moduli'], $c['ore'], $c['giorni_uomo'], $c['giornate'], $c['addebitato'], $c['listino']]);
                if ($tab === 'cc') $row = array_merge($row, [$c['ordini'], $c['fatturato'], $c['fatture_registrate'] ? 'Sì (' . $c['ordini_tot'] . ')' : 'No']);
                return $row;
            }, $rows), ['dec' => [8 => 2, 10 => 2, 11 => 2, 12 => 2, 14 => 2]]);
            return $r;
        }

        if ($tab === 'meg') {
            $rows = $this->meg($f);
            $tv = array_sum(array_column($rows, 'valore')); $tc = array_sum(array_column($rows, 'costo_totale')); $tl = array_sum(array_column($rows, 'costo_lavoro'));
            $r->note('Valore di vendita = valore contrattuale della commessa. Costo del lavoro = moduli di intervento valorizzati. Costo totale sostenuto = valore − margine del gestionale (lavoro, acquisti, altri costi), mai inferiore al costo del lavoro.');
            $r->kpi([
                ['label' => 'Commesse WTS-MEG', 'value' => $num(count($rows))],
                ['label' => 'Valore di vendita', 'value' => $eur($tv), 'color' => '2563EB'],
                ['label' => 'Costo totale sostenuto', 'value' => $eur($tc), 'sub' => 'di cui lavoro ' . $eur($tl), 'color' => 'DC2626'],
                ['label' => 'Margine', 'value' => $eur($tv - $tc), 'sub' => $tv > 0 ? $num(($tv - $tc) / $tv * 100, 1) . ' %' : '', 'color' => '16A34A'],
            ]);
            $by = $rows; usort($by, fn($a, $b) => $b['costo_totale'] <=> $a['costo_totale']);
            $r->bars('Prime 15 commesse per costo sostenuto', array_map(fn($c) => [$c['commessa'] . ' · ' . mb_strimwidth((string)$c['cliente'], 0, 30, '…'), round($c['costo_totale'], 0)], array_slice($by, 0, 15)), '€', 'DC2626');
            $tr = array_map(fn($c) => array_merge($bv($c), [round((float)$c['valore'], 2), $c['costo_lavoro'], $c['costo_totale'], $c['margine'], $c['margine_pct']]), $rows);
            $tr[] = ['Totale', '', '', '', null, null, round($tv, 2), round($tl, 2), round($tc, 2), round($tv - $tc, 2), $tv > 0 ? round(($tv - $tc) / $tv * 100, 1) : null];
            $r->table('Commesse WTS-MEG', array_merge($base, ['Valore di vendita €', 'Costo del lavoro €', 'Costo totale €', 'Margine €', 'Margine %']), $tr, ['dec' => [6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 1], 'total' => true]);
            return $r;
        }

        if ($tab === 'nv') {
            $d = $this->nv($f);
            $r->note('Ore dei moduli di intervento nel periodo ' . $p['label'] . ($p['default'] ? ' (anno corrente)' : '') . '. Incidenza % = ore della tipologia / ore totali dei servizi erogati nel perimetro del filtro (tutte le tipologie). Quota NV % = ore della tipologia / ore NV_ totali.');
            $r->kpi([
                ['label' => 'Ore NV_', 'value' => $num($d['ore_nv'], 1) . ' h', 'color' => '7C3AED'],
                ['label' => 'Ore totali servizi erogati', 'value' => $num($d['ore_totali'], 1) . ' h', 'color' => '2563EB'],
                ['label' => 'Incidenza NV_', 'value' => $d['ore_totali'] > 0 ? $num($d['ore_nv'] / $d['ore_totali'] * 100, 2) . ' %' : '—', 'color' => 'EA580C'],
                ['label' => 'Tipologie', 'value' => (string)count($d['righe'])],
            ]);
            $r->bars('Incidenza % sulle ore totali per tipologia', array_map(fn($x) => [$x['tipologia'], (float)$x['incidenza_pct']], $d['righe']), '%', 'EA580C');
            $tr = array_map(fn($x) => [$x['tipologia'], (string)$x['descrizione'], (int)$x['commesse'], (int)$x['persone'], (int)$x['moduli'], $x['ore'], (int)$x['giorni_uomo'], $x['giornate'], $x['incidenza_pct'], $x['quota_nv_pct']], $d['righe']);
            $tr[] = ['Totale NV_', '', array_sum(array_column($d['righe'], 'commesse')), null, array_sum(array_column($d['righe'], 'moduli')), round($d['ore_nv'], 2), array_sum(array_column($d['righe'], 'giorni_uomo')), round($d['ore_nv'] / 8, 2), $d['ore_totali'] > 0 ? round($d['ore_nv'] / $d['ore_totali'] * 100, 2) : null, $d['ore_nv'] > 0 ? 100.0 : null];
            $r->table('Commesse NV_ per tipologia', ['Tipologia', 'Descrizione', 'Commesse', 'Persone', 'Moduli', 'Ore', 'Giorni uomo', 'Giornate equiv.', 'Incidenza % su totale servizi', 'Quota % su NV_'], $tr, ['dec' => [5 => 2, 7 => 2, 8 => 2, 9 => 1], 'total' => true]);
            return $r;
        }

        // moduli
        $d = $this->moduli($f);
        $r->note('Moduli di intervento nel periodo ' . $p['label'] . ($p['default'] ? ' (anno corrente)' : '') . ' sulle commesse del filtro. Giorni = giorni distinti con almeno un modulo; giornate equivalenti = ore / 8. Fascia come nella Relazione di Servizio IT (dall\'attività, altrimenti dedotta dall\'orario).');
        $tm = array_sum(array_column($d['tecnici'], 'moduli')); $to = array_sum(array_column($d['tecnici'], 'ore'));
        $r->kpi([
            ['label' => 'Tecnici', 'value' => $num(count($d['tecnici']))],
            ['label' => 'Moduli di intervento', 'value' => $num($tm), 'color' => '2563EB'],
            ['label' => 'Ore erogate', 'value' => $num($to, 1) . ' h', 'color' => '7C3AED'],
            ['label' => 'Giornate equivalenti', 'value' => $num($to / 8, 1), 'color' => '16A34A'],
        ]);
        $pal = ['2563EB', '16A34A', 'F59E0B', 'DC2626', '7C3AED', '0891B2', 'DB2777', '65A30D'];
        $segs = []; foreach ($d['fasce'] as $i => $fa) $segs[] = ['label' => 'Fascia ' . $fa, 'color' => $pal[$i % count($pal)]];
        $mx = [];
        foreach ($d['righe'] as $x) $mx[$x['tecnico']][$x['fascia']] = (float)$x['ore'];
        $st = [];
        foreach (array_slice($d['tecnici'], 0, 25) as $t) $st[] = [(string)$t['tecnico'], array_map(fn($fa) => $mx[$t['tecnico']][$fa] ?? 0.0, $d['fasce']), $num($t['ore'], 1) . ' h'];
        $r->stacked('Ore per tecnico e fascia (primi 25)', $st, $segs);
        $tt = array_map(fn($t) => [(string)$t['tecnico'], (int)$t['moduli'], (float)$t['ore'], (int)$t['giorni'], round((float)$t['ore'] / 8, 2), (int)$t['commesse']], $d['tecnici']);
        $tt[] = ['Totale', $tm, round($to, 2), null, round($to / 8, 2), null];
        $r->table('Totale per tecnico', ['Tecnico', 'Moduli', 'Ore', 'Giorni lavorati', 'Giornate equiv.', 'Commesse'], $tt, ['dec' => [2 => 2, 4 => 2], 'total' => true]);
        $r->table('Per tecnico e fascia', ['Tecnico', 'Fascia', 'Moduli', 'Ore', 'Giorni lavorati', 'Giornate equiv.', 'Commesse'],
            array_map(fn($x) => [(string)$x['tecnico'], (string)$x['fascia'], (int)$x['moduli'], (float)$x['ore'], (int)$x['giorni'], round((float)$x['ore'] / 8, 2), (int)$x['commesse']], $d['righe']),
            ['dec' => [3 => 2, 5 => 2]]);
        return $r;
    }
}
