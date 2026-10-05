<?php
/**
 * PortalManager — app/PrjExport.php  (v1.10.03)
 *
 * Export della scheda progetto PRJ:
 *  - XLSX (XlsxWriter): Riepilogo, Scenari, Costi per profilo, Carico ticket, Anni, Stimato vs consuntivo, Info;
 *  - DOCX (DocxWriter): relazione di dimensionamento con indicatori, confronto scenari, composizione del costo,
 *    andamento per anno, carico per servizio, profili e (se collegato) stimato vs consuntivo.
 * I costi reali del consuntivo sono esportati solo con il permesso prj_costs_real.php (can_real).
 * Pattern export binario: buffer puliti, zlib disattivato, nessun Content-Length fisso nell'XLSX, exit.
 */
declare(strict_types=1);

final class PrjExport
{
    private const MET = [
        'fte_totali' => 'FTE', 'costo_personale' => 'Costo personale (RAL) €', 'oneri' => 'Oneri €', 'indennita' => 'Indennità H24 €',
        'costo_aziendale_personale' => 'Costo aziendale personale €', 'strutturali' => 'Strutturali €', 'overhead' => 'Overhead €',
        'costo_aziendale_totale' => 'Costo aziendale totale €', 'canone_medio' => 'Canone medio €', 'canone_netto' => 'Canone netto €',
        'pct_canone' => '% canone', 'margine' => 'Margine €', 'margine_pct' => 'Margine %', 'valore_punto_ribasso' => 'Valore punto di ribasso €',
        'ribasso_max_pareggio' => 'Ribasso max a pareggio', 'fte_finanziabili' => 'FTE finanziabili', 'costo_medio_fte' => 'Costo medio per FTE €',
        'costo_giornaliero_medio' => 'Costo giornaliero medio €', 'peso_strutturali_pct' => 'Peso strutturali', 'valore_unitario_ticket' => 'Valore unitario ticket €',
        'costo_totale_durata' => 'Costo sulla durata operativa €', 'canone_totale_durata' => 'Canone sulla durata €',
    ];
    private const PCT = ['pct_canone', 'margine_pct', 'ribasso_max_pareggio', 'peso_strutturali_pct'];

    private static function r($v, int $d = 2) { return $v === null ? '' : round((float)$v, $d); }
    private static function ref(array $c): ?array { return $c['calc'][$c['refId']] ?? (reset($c['calc']) ?: null); }
    private static function name(array $c): string { return preg_replace('/[^A-Za-z0-9_-]+/', '_', $c['prj']['prj_code']); }

    public static function xlsx(array $c): void
    {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @ini_set('zlib.output_compression', '0');
        require_once dirname(__DIR__) . '/XlsxWriter.php';
        $p = $c['prj']; $R = self::ref($c);
        $w = new XlsxWriter();
        $sum = [['Voce', 'Valore'], ['Codice PRJ', $p['prj_code']], ['Progetto', $p['nome']], ['Stato', $p['stato']],
                ['Cliente', (string)($p['client_raw'] ?? '')], ['CIG', (string)($p['cig'] ?? '')], ['Commessa SP', $c['sp'] ? $c['sp']['project_code'] . ' — ' . $c['sp']['name'] : 'non collegato'],
                ['Scenario di riferimento', $R ? $R['scenario']['nome'] : ''], ['Zona / RAL', $R ? $R['scenario']['zona'] . ' / ' . $R['scenario']['ral_mode'] : ''],
                ['Durata (mesi) / operativi', ($c['gara']['durata_mesi'] ?? '') . ' / ' . ($c['gara']['mesi_operativi'] ?? '')]];
        if ($R) foreach (self::MET as $k => $l) $sum[] = [$l, in_array($k, self::PCT, true) ? self::r(($R['totali'][$k] ?? null) !== null ? $R['totali'][$k] * 100 : null, 2) : self::r($R['totali'][$k] ?? null)];
        $w->addSheet('Riepilogo', $sum);

        $sc = [array_merge(['Indicatore'], array_map(fn($s) => $s['nome'] . ((int)$s['id'] === $c['refId'] ? ' (rif.)' : ''), $c['scenarios']))];
        foreach (self::MET as $k => $l) {
            $row = [$l . (in_array($k, self::PCT, true) ? ' (%)' : '')];
            foreach ($c['scenarios'] as $s) { $v = $c['calc'][(int)$s['id']]['totali'][$k] ?? null; $row[] = in_array($k, self::PCT, true) ? self::r($v !== null ? $v * 100 : null) : self::r($v); }
            $sc[] = $row;
        }
        $w->addSheet('Scenari', $sc);

        if ($R) {
            $pr = [['Profilo', 'Nome', 'Tipo', 'Servizio', 'FTE', 'Zona', 'Remoto', 'H24', 'RAL riferimento €', 'RAL zona €', 'Oneri %', 'Costo az./FTE €', 'Costo giornaliero €', 'Costo aziendale €', 'Strutturale €']];
            foreach ($R['profili'] as $x) $pr[] = [$x['codice'], $x['nome'], $x['tipo'], $x['servizio'], self::r($x['fte'], 3), $x['zona'], $x['remoto'] ? 'sì' : '', $x['h24'] ? 'sì' : '',
                self::r($x['ral_rif']), self::r($x['ral_zona']), self::r($x['oneri_pct'] * 100), self::r($x['costo_az_fte']), self::r($x['costo_giornaliero']), self::r($x['costo_aziendale']), self::r($x['strutturale'])];
            $w->addSheet('Costi per profilo', $pr);
            $tk = [['Servizio', 'Nome', 'CTASK', 'INC', 'SCTASK', 'Ticket', 'Ore', 'FTE da ticket', 'FTE con uplift', 'FTE allocati', 'Scostamento']];
            foreach ($R['ticket']['rows'] as $x) $tk[] = [$x['codice'], $x['nome'], self::r($x['ticket_per_tipo']['CTASK'], 1), self::r($x['ticket_per_tipo']['INC'], 1), self::r($x['ticket_per_tipo']['SCTASK'], 1),
                self::r($x['ticket'], 1), self::r($x['ore'], 1), self::r($x['fte_ticket'], 3), self::r($x['fte_uplift'], 3), self::r($x['fte_allocati'], 3), self::r($x['scostamento'], 3)];
            $w->addSheet('Carico ticket', $tk);
            $an = [['Anno', 'N', 'FTE', 'Costo €', 'Canone netto €', 'Uncommitted €', 'Margine €', '% canone']];
            foreach ($R['anni'] as $x) $an[] = [$x['anno'], $x['n'], self::r($x['fte'], 2), self::r($x['costo']), self::r($x['canone']), self::r($x['uncommitted']), self::r($x['margine']), self::r(($x['pct_canone'] ?? 0) * 100)];
            $w->addSheet('Anni', $an);
        }
        if ($c['cv']) {
            $cv = [['Mese', 'Ore rapporti', 'Ore timesheet', 'Ore DGB', 'Ore totali', 'Ticket', 'FTE stimati', 'FTE reali', 'Scost. FTE %', 'Costo stimato €', 'Costo reale €', 'Scost. costo %']];
            foreach ($c['cv']['mesi'] as $m) $cv[] = [$m['ym'], self::r($m['ore_report'], 1), self::r($m['ore_timesheet'], 1), self::r($m['ore_dgb'], 1), self::r($m['ore'], 1), self::r($m['ticket'], 0),
                self::r($m['stimato']['fte'], 3), self::r($m['consuntivo']['fte'], 3), $m['ore'] > 0 ? self::r(($m['scost_fte'] ?? 0) * 100, 1) : '',
                self::r($m['stimato']['costo']), $c['can_real'] ? self::r($m['consuntivo']['costo']) : 'riservato', $m['ore'] > 0 && $c['can_real'] ? self::r(($m['scost_costo'] ?? 0) * 100, 1) : ''];
            $w->addSheet('Stimato vs consuntivo', $cv);
        }
        $w->addSheet('Info', [['Voce', 'Valore'], ['Calcolo alla data', date('d/m/Y')], ['Generato il', date('d/m/Y H:i')], ['Versione', defined('PM_VERSION') ? PM_VERSION : ''],
                              ['Note', 'Importi annui in €; % in punti percentuali. Costi reali riservati senza il permesso «Costi reali dipendenti (PRJ)».']]);
        $w->download('prj_' . self::name($c) . '_' . date('Ymd_Hi') . '.xlsx');
    }

    public static function docx(array $c): void
    {
        require_once __DIR__ . '/DocxWriter.php';
        $p = $c['prj']; $R = self::ref($c);
        $k = fn($v) => $v === null ? '—' : number_format((float)$v / 1000, 1, ',', '.') . ' k€';
        $e = fn($v) => $v === null ? '—' : number_format((float)$v, 0, ',', '.') . ' €';
        $pc = fn($v, $d = 0) => $v === null ? '—' : number_format((float)$v * 100, $d, ',', '.') . '%';
        $n = fn($v, $d = 1) => $v === null ? '—' : number_format((float)$v, $d, ',', '.');
        $d = new DocxWriter('Analisi gara e dimensionamento');
        $d->meta($p['prj_code'] . ' — ' . $p['nome'] . ' · stato ' . $p['stato'] . ' · ' . ($c['sp'] ? 'commessa SP ' . $c['sp']['project_code'] : 'non collegato a commessa SP')
               . ' · calcolo al ' . date('d/m/Y'));
        if ($R) {
            $T = $R['totali'];
            $d->kpi([
                ['label' => 'FTE', 'value' => $n($T['fte_totali']), 'sub' => $R['scenario']['nome']],
                ['label' => 'Costo aziendale totale', 'value' => $k($T['costo_aziendale_totale']), 'sub' => 'annuo'],
                ['label' => 'Canone medio', 'value' => $k($T['canone_medio'])],
                ['label' => '% canone', 'value' => $pc($T['pct_canone']), 'color' => ($T['pct_canone'] ?? 0) > 1 ? 'DC2626' : '16A34A'],
                ['label' => 'Ribasso max a pareggio', 'value' => $pc($T['ribasso_max_pareggio'], 1)],
            ]);
            $d->box('Scenario di riferimento «' . $R['scenario']['nome'] . '»: zona ' . $R['scenario']['zona'] . ', RAL ' . $R['scenario']['ral_mode']
                    . ($R['scenario']['nearshore'] ? ', supporto in ' . $R['scenario']['nearshore'] : '') . '. Importi annui.');
            $d->heading('Confronto scenari', 1);
            $rows = [];
            foreach (['fte_totali', 'costo_aziendale_personale', 'strutturali', 'overhead', 'costo_aziendale_totale', 'canone_netto', 'pct_canone', 'margine', 'ribasso_max_pareggio', 'fte_finanziabili'] as $m) {
                $row = [self::MET[$m]];
                foreach ($c['scenarios'] as $s) { $v = $c['calc'][(int)$s['id']]['totali'][$m] ?? null; $row[] = in_array($m, self::PCT, true) ? $pc($v, 1) : (str_starts_with($m, 'fte') ? $n($v) : $k($v)); }
                $rows[] = $row;
            }
            $d->table(array_merge(['Indicatore'], array_map(fn($s) => $s['nome'], $c['scenarios'])), $rows, ['right' => range(1, count($c['scenarios'])), 'zebra' => true]);
            $d->heading('Composizione del costo — ' . $R['scenario']['nome'], 1);
            $d->table(['Voce', 'Importo'], [['Costo del personale (RAL)', $k($T['costo_personale'])], ['Oneri', $k($T['oneri'])], ['Indennità H24 (' . $n($T['fte_h24'], 2) . ' FTE)', $k($T['indennita'])],
                ['Costo aziendale personale', $k($T['costo_aziendale_personale'])], ['Strutturali postazioni', $k($T['strutturali_fte'])], ['Costi di sede', $k($T['costi_sede'])],
                ['Overhead', $k($T['overhead'])], ['Costo aziendale totale', $k($T['costo_aziendale_totale'])]], ['right' => [1], 'zebra' => true]);
            $d->heading('Andamento per anno di contratto', 1);
            $d->table(['Anno', 'FTE', 'Costo', 'Canone netto', 'Margine', '% canone'],
                array_map(fn($a) => [(string)$a['anno'], $n($a['fte']), $k($a['costo']), $k($a['canone']), $k($a['margine']), $pc($a['pct_canone'])], $R['anni']), ['right' => [1, 2, 3, 4, 5], 'zebra' => true]);
            $d->heading('Carico da ticket per servizio', 1);
            $d->bars(array_map(fn($r) => [$r['codice'], (float)$r['fte_uplift'], $n($r['fte_uplift'], 2) . ' FTE'], $R['ticket']['rows']), ['color' => '2563EB']);
            $tt = $R['ticket']['totali'];
            $d->note('Ticket ' . $n($tt['ticket'], 0) . ' · ore ' . $n($tt['ore'], 0) . ' · FTE da ticket ' . $n($tt['fte_ticket'], 2) . ' · con uplift ' . $n($tt['fte_uplift'], 2) . ' · allocati ' . $n($tt['fte_allocati'], 2));
            $d->heading('Profili', 1);
            $d->table(['Profilo', 'Servizio', 'Tipo', 'FTE', 'Zona', 'Costo az./FTE', 'Costo aziendale'],
                array_map(fn($x) => [$x['codice'] . ' ' . $x['nome'], $x['servizio'], $x['tipo'], $n($x['fte'], 2), $x['zona'] . ($x['remoto'] ? ' (remoto)' : ''), $e($x['costo_az_fte']), $k($x['costo_aziendale'])], $R['profili']),
                ['right' => [3, 5, 6], 'zebra' => true]);
        }
        if ($c['cv']) {
            $cv = $c['cv']; $TV = $cv['totali'];
            $d->pageBreak();
            $d->heading('Stimato vs consuntivo — commessa ' . $cv['context']['project_code'], 1);
            $d->meta('Periodo ' . date('m/Y', strtotime($cv['context']['from'])) . ' → ' . date('m/Y', strtotime($cv['context']['to']))
                     . ' · valore sincronizzato ' . $e($cv['context']['value_total']) . ' · costo sincronizzato ' . $e($cv['context']['actual_cost']) . ' · margine ' . $e($cv['context']['margin_total']));
            $d->table(['Mese', 'Ore', 'FTE stimati', 'FTE reali', 'Costo stimato', 'Costo reale'],
                array_map(fn($m) => [date('m/Y', strtotime($m['ym'] . '-01')), $n($m['ore']), $n($m['stimato']['fte'], 2), $n($m['consuntivo']['fte'], 2), $e($m['stimato']['costo']),
                                     $c['can_real'] ? $e($m['consuntivo']['costo']) : 'riservato'], $cv['mesi']), ['right' => [1, 2, 3, 4, 5], 'zebra' => true]);
            $d->note('Totale periodo: ore ' . $n($TV['ore']) . ', costo stimato ' . $e($TV['costo_stimato']) . ($c['can_real'] ? ', costo reale ' . $e($TV['costo']) . ' (' . $pc($TV['scost_costo']) . ')' : '')
                     . ', FTE medi stimati ' . $n($TV['fte_stimato_medio'], 2) . ' / reali ' . $n($TV['fte_medio'], 2) . '.');
        }
        $d->note('Generato da PortalManager ' . (defined('PM_VERSION') ? PM_VERSION : '') . ' il ' . date('d/m/Y H:i') . '.');
        $d->download('prj_' . self::name($c) . '_' . date('Ymd_Hi') . '.docx');
    }
}
