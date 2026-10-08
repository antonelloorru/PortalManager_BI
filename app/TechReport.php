<?php
/**
 * PortalManager — app/TechReport.php  (v1.10.25)
 *
 * Relazione Tecnici: dati e report multi-formato (CSV, XLSX, DOCX, PDF e stampa HTML via PmReport) per le due schede
 *   tecnici  — riepilogo per tecnico × codice linea (attività, ticket, giorni lavorabili, giornate-uomo, ore, fascia di costo),
 *              metriche di dettaglio (ordinarie, fuori orario, reperibilità, extra, presso cliente, remoto, smart),
 *              moduli di intervento valorizzati / non valorizzati;
 *   rapporti — rapporti di intervento per tipologia di contratto e per commessa con la provenienza (ticket / riferimento
 *              libero / da commessa), dettaglio dei moduli e drill-down sulla singola commessa.
 * Perimetro e filtri: ItServiceModel (gli stessi della Relazione di Servizio IT).
 * Valori economici (produzione teorica, valore addebitato) solo con $eco (permesso tech_report_economics.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/ItServiceModel.php';
require_once __DIR__ . '/PmReport.php';

final class TechReport
{
    public const TABS = ['tecnici' => 'Tecnici', 'rapporti' => 'Rapporti di intervento'];
    public const MAX_FILE = 50000;   // moduli nel dettaglio XLSX / CSV
    public const MAX_DOC  = 1500;    // moduli nel dettaglio DOCX / PDF / stampa

    public const H_MAIN = ['Tecnico', 'Codice linea', 'N. attività', 'N. ticket', 'GG lavorabili', 'GG uomo lavorati', 'N. ore lavorate', 'Fascia di costo'];
    public const H_DET  = ['Tecnico', 'Codice linea', 'Giornate-uomo', 'Ore cons.', 'Ordinarie', 'Fuori orario', 'Reperib.', 'Extra dich.', 'Presso cl.', 'Remoto', 'Smart'];

    public function __construct(private ItServiceModel $m, private bool $eco = false) {}

    // ── dati ─────────────────────────────────────────────────────────
    public function data(string $tab, array $f, bool $dettaglio = false, ?string $commessa = null, int $maxModuli = 0): array
    {
        if ($tab === 'tecnici') {
            $tl = $this->m->tecniciLinea($f);
            return ['tl' => $tl, 'righe' => self::gruppi($tl), 'val' => $this->m->valorizzazione($f), 'valTec' => $this->m->valorizzazione($f, true)];
        }
        $d = ['tipologie' => $this->m->rapportiTipologia($f), 'commesse' => $commessa === null ? $this->m->rapportiCommessa($f) : [], 'moduli' => [], 'n_moduli' => 0];
        if ($commessa !== null) $d['commesse'] = array_values(array_filter($this->m->rapportiCommessa($f), fn($r) => (string)$r['commessa'] === $commessa));
        if ($dettaglio || $commessa !== null) {
            $d['n_moduli'] = $this->m->contaModuli($f, $commessa);
            $d['moduli'] = $this->m->rapportiModuli($f, $commessa, $maxModuli > 0 ? $maxModuli : self::MAX_FILE);
        }
        return $d;
    }

    /**
     * Righe della tabella principale: tecnico × codice linea, seguite dal totale del tecnico quando ha più linee.
     * @return array<int,array{tipo:string,tecnico:string,r:array}>  tipo = riga | sub
     */
    public static function gruppi(array $tl): array
    {
        $out = []; $cur = null; $n = 0;
        $flush = function () use (&$out, &$cur, &$n, $tl) {
            if ($cur !== null && $n > 1 && isset($tl['tecnici'][$cur])) $out[] = ['tipo' => 'sub', 'tecnico' => $cur, 'r' => $tl['tecnici'][$cur]];
        };
        foreach ($tl['righe'] as $r) {
            if ($r['tecnico'] !== $cur) { $flush(); $cur = (string)$r['tecnico']; $n = 0; }
            $out[] = ['tipo' => 'riga', 'tecnico' => $cur, 'r' => $r]; $n++;
        }
        $flush();
        return $out;
    }

    // ── valori riga ──────────────────────────────────────────────────
    public static function main(array $x, int $gg): array
    {
        $r = $x['r']; $sub = $x['tipo'] === 'sub';
        return [$sub ? 'Totale ' . $x['tecnico'] : $x['tecnico'], $sub ? (int)$r['linee'] . ' linee' : (string)$r['codice_linea'], (int)$r['attivita'], (int)$r['ticket'],
                $gg, (int)$r['giornate_uomo'], (float)$r['ore'], (string)($r['fascia_costo'] ?? '')];
    }

    public static function det(array $x): array
    {
        $r = $x['r']; $sub = $x['tipo'] === 'sub';
        return [$sub ? 'Totale ' . $x['tecnico'] : $x['tecnico'], $sub ? (int)$r['linee'] . ' linee' : (string)$r['codice_linea'], (int)$r['giornate_uomo'], (float)$r['ore'],
                (float)$r['ore_ordinarie'], (float)$r['ore_fuori_orario'], (float)$r['ore_reperibilita'], (float)$r['ore_extra'],
                (int)$r['presso_cliente'], (int)$r['da_remoto'], (int)$r['smart_working']];
    }

    public static function pct($a, $b): ?float { return (float)$b > 0 ? round((float)$a / (float)$b * 100, 1) : null; }

    // ── report ───────────────────────────────────────────────────────
    public function build(string $tab, array $f, array $d, string $filtri, string $fmt, ?string $commessa = null): PmReport
    {
        $per = date('d/m/Y', strtotime($f['from'])) . ' – ' . date('d/m/Y', strtotime($f['to']));
        $n0 = static fn($v) => number_format((float)$v, 0, ',', '.');
        $n2 = static fn($v) => number_format((float)$v, 2, ',', '.');
        $title = $tab === 'tecnici' ? 'Relazione Tecnici' : 'Rapporti di intervento' . ($commessa !== null ? ' — commessa ' . $commessa : '');
        $r = new PmReport($title, 'Periodo ' . $per . ' · generato il ' . date('d/m/Y H:i'), 'L');
        $r->meta($filtri !== '' ? 'Filtri: ' . $filtri : 'Nessun filtro oltre al periodo');

        if ($tab === 'tecnici') {
            $tl = $d['tl']; $t = $tl['totale']; $gg = (int)$tl['gg_lavorabili'];
            $r->kpi([
                ['label' => 'Tecnici', 'value' => $n0($t['tecnici'] ?? 0), 'color' => '2563EB', 'sub' => $n0($t['linee'] ?? 0) . ' codici linea'],
                ['label' => 'Attività', 'value' => $n0($t['attivita'] ?? 0), 'color' => '0891B2', 'sub' => $n0($t['ticket'] ?? 0) . ' ticket'],
                ['label' => 'GG lavorabili', 'value' => $n0($gg), 'color' => '64748B', 'sub' => 'lun–ven esclusi i festivi'],
                ['label' => 'Giornate-uomo', 'value' => $n0($t['giornate_uomo'] ?? 0), 'color' => '7C3AED'],
                ['label' => 'Ore lavorate', 'value' => $n2($t['ore'] ?? 0), 'color' => '16A34A', 'sub' => $n2($t['ore_fuori_orario'] ?? 0) . ' h fuori orario'],
            ]);
            $tot = ['Totale', (int)($t['linee'] ?? 0) . ' linee', (int)($t['attivita'] ?? 0), (int)($t['ticket'] ?? 0), $gg, (int)($t['giornate_uomo'] ?? 0), (float)($t['ore'] ?? 0), ''];
            $r->table('Riepilogo per tecnico e codice linea', self::H_MAIN, array_merge(array_map(fn($x) => self::main($x, $gg), $d['righe']), $d['righe'] ? [$tot] : []),
                ['dec' => [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 2], 'right' => [2, 3, 4, 5, 6], 'total' => (bool)$d['righe']]);
            $r->note('N. attività = moduli di intervento · N. ticket = riferimenti ticket distinti dei moduli · GG lavorabili = giorni lunedì–venerdì del periodo esclusi i festivi nazionali · GG uomo lavorati = giorni distinti con almeno un modulo · Fascia di costo = fascia del tecnico sui moduli. Le righe «Totale» del tecnico contano i giorni una sola volta anche se lavorati su più linee.');
            $totD = ['Totale', (int)($t['linee'] ?? 0) . ' linee', (int)($t['giornate_uomo'] ?? 0), (float)($t['ore'] ?? 0), (float)($t['ore_ordinarie'] ?? 0), (float)($t['ore_fuori_orario'] ?? 0),
                     (float)($t['ore_reperibilita'] ?? 0), (float)($t['ore_extra'] ?? 0), (int)($t['presso_cliente'] ?? 0), (int)($t['da_remoto'] ?? 0), (int)($t['smart_working'] ?? 0)];
            $r->table('Metriche di dettaglio', self::H_DET, array_merge(array_map([self::class, 'det'], $d['righe']), $d['righe'] ? [$totD] : []),
                ['dec' => [2 => 0, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2, 8 => 0, 9 => 0, 10 => 0], 'right' => [2, 3, 4, 5, 6, 7, 8, 9, 10], 'total' => (bool)$d['righe']]);
            $r->note('Ore in ore; Ordinarie + Fuori orario + Reperib. + non classificate = Ore cons. (stessa regola della Relazione di Servizio IT). Extra dich. = ore extra dichiarate sul modulo. Presso cl., Remoto, Smart = numero di interventi per modalità.');

            $v = $d['val']; $hv = ['Moduli di intervento', 'Moduli', 'Ore', 'Giornate-uomo', 'Tecnici', 'Commesse'];
            if ($this->eco) { $hv[] = 'Produzione teorica €'; $hv[] = 'Valore addebitato €'; }
            $rows = [];
            foreach (['val' => 'Valorizzati', 'nv' => 'Non valorizzati', 'tot' => 'Totale'] as $k => $l) {
                $x = $v[$k] ?? [];
                $row = [$l, (int)($x['moduli'] ?? 0), (float)($x['ore'] ?? 0), (int)($x['giornate_uomo'] ?? 0), (int)($x['tecnici'] ?? 0), (int)($x['commesse'] ?? 0)];
                if ($this->eco) { $row[] = $k === 'nv' ? null : (float)($x['produzione_teorica'] ?? 0); $row[] = (float)($x['valore_addebitato'] ?? 0); }
                $rows[] = $row;
            }
            $r->table('Moduli di intervento: valorizzati e non valorizzati', $hv, $rows, ['dec' => [1 => 0, 2 => 2, 3 => 0, 4 => 0, 5 => 0, 6 => 2, 7 => 2], 'right' => [1, 2, 3, 4, 5, 6, 7], 'total' => true]);
            $hp = ['Tecnico', 'Valorizzati', 'Ore valorizzate', 'Non valorizzati', 'Ore non valorizzate', '% ore non valorizzate'];
            if ($this->eco) $hp[] = 'Produzione teorica €';
            $rp = array_map(function ($x) {
                $row = [(string)$x['tecnico'], (int)$x['moduli_val'], (float)$x['ore_val'], (int)$x['moduli_nv'], (float)$x['ore_nv'], self::pct($x['ore_nv'], (float)$x['ore_val'] + (float)$x['ore_nv'])];
                if ($this->eco) $row[] = (float)$x['produzione_teorica'];
                return $row;
            }, $d['valTec']);
            $r->table('Moduli valorizzati e non valorizzati per tecnico', $hp, $rp, ['dec' => [1 => 0, 2 => 2, 3 => 0, 4 => 2, 5 => 1, 6 => 2], 'right' => [1, 2, 3, 4, 5, 6]]);
            $r->note('Valorizzati = moduli con tariffa di listino della commessa (produzione teorica calcolabile); non valorizzati = senza tariffa (commessa senza listino o combinazione fascia/unità non prevista). Perimetro: stessi filtri, base dei «Giorni per operatore» della Relazione di Servizio IT.');
            return $r;
        }

        // rapporti
        $tp = $d['tipologie'];
        $sum = static fn(array $rows, string $k) => array_sum(array_map(fn($x) => (float)$x[$k], $rows));
        $src = $commessa !== null ? $d['commesse'] : $tp;
        $mod = $sum($src, 'moduli');
        $r->kpi([
            ['label' => 'Moduli di intervento', 'value' => $n0($mod), 'color' => '2563EB', 'sub' => $n0($commessa !== null ? count($src) : $sum($src, 'commesse')) . ' commesse'],
            ['label' => 'Da ticket (codice)', 'value' => $n0($sum($src, 'da_ticket')), 'color' => 'DC2626', 'sub' => ($p = self::pct($sum($src, 'da_ticket'), $mod)) !== null ? number_format($p, 1, ',', '.') . '%' : ''],
            ['label' => 'Riferimento libero', 'value' => $n0($sum($src, 'da_testo')), 'color' => 'F59E0B'],
            ['label' => 'Da commessa', 'value' => $n0($sum($src, 'da_commessa')), 'color' => '16A34A', 'sub' => ($p = self::pct($sum($src, 'da_commessa'), $mod)) !== null ? number_format($p, 1, ',', '.') . '%' : ''],
            ['label' => 'Ore', 'value' => $n2($sum($src, 'ore')), 'color' => '0891B2'],
        ]);
        $r->note('Provenienza dal campo ticket del modulo: Ticket (codice) = riporta un codice ticket (es. WTS_000000070); Riferimento libero = campo ticket compilato con un testo; Da commessa = nessun ticket, modulo generato dalla commessa.');
        if ($commessa === null) {
            $rows = array_map(fn($x) => [ItServiceModel::tipologia($x['tipologia']), (int)$x['commesse'], (int)$x['moduli'], (int)$x['da_ticket'], (int)$x['da_testo'], (int)$x['da_commessa'],
                                         self::pct($x['da_ticket'], $x['moduli']), (int)$x['ticket'], (int)$x['tecnici'], (float)$x['ore']], $tp);
            if ($rows) $rows[] = ['Totale', (int)$sum($tp, 'commesse'), (int)$mod, (int)$sum($tp, 'da_ticket'), (int)$sum($tp, 'da_testo'), (int)$sum($tp, 'da_commessa'), self::pct($sum($tp, 'da_ticket'), $mod), null, null, round($sum($tp, 'ore'), 2)];
            $r->table('Per tipologia di contratto', ['Tipologia contratto', 'Commesse', 'Moduli', 'Ticket (codice)', 'Rif. libero', 'Da commessa', '% da ticket', 'Ticket distinti', 'Tecnici', 'Ore'], $rows,
                ['dec' => [6 => 1, 9 => 2], 'right' => [1, 2, 3, 4, 5, 6, 7, 8, 9], 'total' => (bool)$rows]);
        }
        $cm = $d['commesse'];
        $rows = array_map(fn($x) => [(string)$x['commessa'], (string)($x['denominazione'] ?? ''), (string)$x['cliente'], (string)$x['codice_linea'], ItServiceModel::tipologia($x['tipologia']),
                                     (int)$x['moduli'], (int)$x['da_ticket'], (int)$x['da_testo'], (int)$x['da_commessa'], (int)$x['ticket'], (int)$x['tecnici'], (float)$x['ore'],
                                     $x['dal'] ? date('d/m/Y', strtotime($x['dal'])) : '', $x['al'] ? date('d/m/Y', strtotime($x['al'])) : ''], $cm);
        if (count($rows) > 1) $rows[] = ['Totale', count($cm) . ' commesse', '', '', '', (int)$sum($cm, 'moduli'), (int)$sum($cm, 'da_ticket'), (int)$sum($cm, 'da_testo'), (int)$sum($cm, 'da_commessa'), null, null, round($sum($cm, 'ore'), 2), '', ''];
        $r->table($commessa !== null ? 'Commessa' : 'Per commessa', ['Commessa', 'Denominazione', 'Cliente', 'Codice linea', 'Tipologia', 'Moduli', 'Ticket (codice)', 'Rif. libero', 'Da commessa', 'Ticket distinti', 'Tecnici', 'Ore', 'Dal', 'Al'],
            $rows, ['dec' => [11 => 2], 'right' => [5, 6, 7, 8, 9, 10, 11], 'total' => count($rows) > 1]);
        if ($d['moduli']) {
            $cap = in_array($fmt, ['docx', 'pdf', 'print'], true) ? self::MAX_DOC : self::MAX_FILE;
            $mm = array_slice($d['moduli'], 0, $cap);
            if ($d['n_moduli'] > count($mm)) $r->note('Dettaglio moduli: primi ' . $n0(count($mm)) . ' su ' . $n0($d['n_moduli']) . ($cap === self::MAX_DOC ? ' (elenco completo in XLSX e CSV).' : '.'));
            $r->table('Dettaglio moduli di intervento', ['Modulo', 'Data', 'Commessa', 'Cliente', 'Tecnico', 'Codice linea', 'Tipologia', 'Modalità', 'Ore', 'Provenienza', 'Ticket', 'Attività DGB'],
                array_map(fn($x) => [(string)$x['modulo'], $x['giorno'] ? date('d/m/Y', strtotime($x['giorno'])) : '', (string)$x['commessa'], (string)$x['cliente'], (string)$x['tecnico'],
                                     (string)$x['codice_linea'], ItServiceModel::tipologia($x['tipologia']), ItServiceModel::etichetta($x['modalita']), (float)$x['ore'],
                                     ItServiceModel::PROV[$x['provenienza']] ?? $x['provenienza'], mb_substr((string)$x['ticket'], 0, 80), (string)$x['attivita_dgb']], $mm),
                ['dec' => [8 => 2], 'right' => [8]]);
        }
        return $r;
    }
}
