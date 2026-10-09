<?php
/**
 * PortalManager — app/TechReport.php  (v1.10.25; v1.10.28: «Linea di servizio» a destra di «Codice linea» in tutte le tabelle)
 *
 * Relazione Tecnici: dati e report multi-formato (CSV, XLSX, DOCX, PDF e stampa HTML via PmReport) per le due schede
 *   tecnici  — riepilogo per tecnico × codice linea (attività, ticket, giorni lavorabili, giornate-uomo, ore, descrizione tariffa),
 *              metriche di dettaglio (ordinarie, fuori orario, reperibilità, extra, presso cliente, remoto, smart),
 *              moduli di intervento valorizzati / non valorizzati;
 *   rapporti — rapporti di intervento per tipologia di contratto e per commessa con la provenienza (ticket / riferimento
 *              libero / da commessa), dettaglio dei moduli e drill-down sulla singola commessa.
 *   reperibilita — v1.10.32 «Controllo Reperibilità»: interventi in reperibilità (inizio 18:01–08:59) del tecnico correlati al
 *              primo intervento ordinario (09:00–18:00) del giorno lavorativo successivo (ItServiceModel::controlloReperibilita).
 * Perimetro e filtri: ItServiceModel (gli stessi della Relazione di Servizio IT).
 * Valori economici (produzione teorica, valore addebitato) solo con $eco (permesso tech_report_economics.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/ItServiceModel.php';
require_once __DIR__ . '/PmReport.php';

final class TechReport
{
    public const TABS = ['tecnici' => 'Tecnici', 'rapporti' => 'Rapporti di intervento', 'reperibilita' => 'Controllo Reperibilità'];
    public const MAX_FILE = 50000;   // moduli nel dettaglio XLSX / CSV
    public const MAX_DOC  = 1500;    // moduli nel dettaglio DOCX / PDF / stampa

    public const H_MAIN = ['Tecnico', 'Codice linea', 'Linea di servizio', 'N. attività', 'N. ticket', 'GG lavorabili', 'GG uomo lavorati', 'N. ore lavorate', 'Descrizione tariffa'];
    public const H_DET  = ['Tecnico', 'Codice linea', 'Linea di servizio', 'Giornate-uomo', 'Ore cons.', 'Ordinarie', 'Fuori orario', 'Reperib.', 'Extra dich.', 'Presso cl.', 'Remoto', 'Smart'];

    /** v1.10.32 — colonne del Controllo Reperibilità (vista, filtri di colonna, export). */
    // v1.10.33 — Cliente, Codice Commessa e Tipo per ciascuno dei due interventi (reperibilità e giorno successivo)
    // v1.10.34 — intestazioni come richieste; i due gruppi si distinguono per colore (HREP_COLORI) e, nel CSV, per suffisso
    public const H_REP = ['Tecnico / Incaricato', 'Data/Ora Reperibilità', 'Rif. Modulo Intervento (reperibilità)', 'Cliente', 'Codice Commessa', 'Tipo',
                          'Data/Ora Giorno Succ.', 'Rif. Modulo Intervento (giorno succ.)', 'Cliente', 'Codice Commessa', 'Tipo'];
    public const C_NEUTRO = '475569';   // tecnico / incaricato
    public const C_REP    = 'A0442C';   // rosso mattone: intervento in reperibilità
    public const C_GS     = '15803D';   // verde: giorno successivo

    /** Gruppo di ogni colonna di H_REP: n = neutro, r = reperibilità, g = giorno successivo. */
    public const H_REP_GRUPPO = ['n', 'r', 'r', 'r', 'r', 'r', 'g', 'g', 'g', 'g', 'g'];

    public static function hRepColori(): array
    {
        return array_map(fn($g) => ['n' => self::C_NEUTRO, 'r' => self::C_REP, 'g' => self::C_GS][$g], self::H_REP_GRUPPO);
    }

    /** Intestazioni univoche (CSV, descrizione dei filtri di colonna): suffisso del gruppo sulle colonne ripetute. */
    public static function hRepEstese(): array
    {
        return array_map(fn($h, $g) => $g === 'n' || str_contains($h, '(') || str_starts_with($h, 'Data/Ora') ? $h : $h . ($g === 'r' ? ' (reperibilità)' : ' (giorno succ.)'), self::H_REP, self::H_REP_GRUPPO);
    }

    public function __construct(private ItServiceModel $m, private bool $eco = false) {}

    // ── dati ─────────────────────────────────────────────────────────
    public function data(string $tab, array $f, bool $dettaglio = false, ?string $commessa = null, int $maxModuli = 0): array
    {
        if ($tab === 'reperibilita') {
            $d = $this->m->controlloReperibilita($f);
            $d['cf'] = self::colFiltri($f['cf'] ?? []);
            $d['casi'] = count($d['righe']);   // prima dei filtri di colonna
            $d['righe'] = self::filtraColonne($d['righe'], $d['cf']);
            return $d;
        }
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
        return [$sub ? 'Totale ' . $x['tecnico'] : $x['tecnico'], $sub ? (int)$r['linee'] . ' linee' : (string)$r['codice_linea'], $sub ? '' : (string)($r['linea_label'] ?? ''), (int)$r['attivita'], (int)$r['ticket'],
                $gg, (int)$r['giornate_uomo'], (float)$r['ore'], (string)($r['descrizione_tariffa'] ?? '')];
    }

    public static function det(array $x): array
    {
        $r = $x['r']; $sub = $x['tipo'] === 'sub';
        return [$sub ? 'Totale ' . $x['tecnico'] : $x['tecnico'], $sub ? (int)$r['linee'] . ' linee' : (string)$r['codice_linea'], $sub ? '' : (string)($r['linea_label'] ?? ''), (int)$r['giornate_uomo'], (float)$r['ore'],
                (float)$r['ore_ordinarie'], (float)$r['ore_fuori_orario'], (float)$r['ore_reperibilita'], (float)$r['ore_extra'],
                (int)$r['presso_cliente'], (int)$r['da_remoto'], (int)$r['smart_working']];
    }

    /** v1.10.32 — riga del Controllo Reperibilità (valori in ordine di H_REP). */
    public static function rep(array $x): array
    {
        $dh = static fn($v) => $v ? date('d/m/Y H:i', strtotime((string)$v)) : '';
        $fh = static fn($v) => $v ? date('H:i', strtotime((string)$v)) : '';
        return [$x['tecnico'], $dh($x['rep_inizio']) . ($x['rep_fine'] ? '–' . $fh($x['rep_fine']) : ''), $x['rep_modulo'], $x['cliente'], $x['commessa'], $x['tipo'],
                $dh($x['succ_inizio']) . ($x['succ_fine'] ? '–' . $fh($x['succ_fine']) : ''), $x['succ_modulo'], $x['succ_cliente'], $x['succ_commessa'], $x['succ_tipo']];
    }

    /** v1.10.32 — filtri di colonna (cf[i], «contiene», senza distinzione di maiuscole e accenti) sulle righe di H_REP. */
    public static function colFiltri($raw): array
    {
        $out = [];
        foreach ((array)$raw as $i => $v) if (is_scalar($v) && ctype_digit((string)$i) && (int)$i < count(self::H_REP) && trim((string)$v) !== '') $out[(int)$i] = mb_substr(trim((string)$v), 0, 80);
        ksort($out);
        return $out;
    }

    public static function filtraColonne(array $righe, array $cf): array
    {
        if (!$cf) return $righe;
        $n = static function (string $s): string {
            $s = mb_strtolower($s, 'UTF-8');
            return class_exists('Normalizer') ? preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($s, Normalizer::FORM_D)) : $s;
        };
        $cfn = array_map($n, $cf);
        return array_values(array_filter($righe, function ($x) use ($cfn, $n) {
            $v = self::rep($x);
            foreach ($cfn as $i => $q) if (!str_contains($n((string)$v[$i]), $q)) return false;
            return true;
        }));
    }

    public static function pct($a, $b): ?float { return (float)$b > 0 ? round((float)$a / (float)$b * 100, 1) : null; }

    // ── report ───────────────────────────────────────────────────────
    public function build(string $tab, array $f, array $d, string $filtri, string $fmt, ?string $commessa = null): PmReport
    {
        $per = date('d/m/Y', strtotime($f['from'])) . ' – ' . date('d/m/Y', strtotime($f['to']));
        $n0 = static fn($v) => number_format((float)$v, 0, ',', '.');
        $n2 = static fn($v) => number_format((float)$v, 2, ',', '.');
        $title = $tab === 'tecnici' ? 'Relazione Tecnici' : ($tab === 'reperibilita' ? 'Controllo Reperibilità' : 'Rapporti di intervento' . ($commessa !== null ? ' — commessa ' . $commessa : ''));
        $r = new PmReport($title, 'Periodo ' . $per . ' · generato il ' . date('d/m/Y H:i'), 'L');
        $r->meta($filtri !== '' ? 'Filtri: ' . $filtri : 'Nessun filtro oltre al periodo');

        if ($tab === 'reperibilita') {
            $rr = $d['righe'];
            $r->kpi([
                ['label' => 'Interventi in reperibilità', 'value' => $n0($d['notturni']), 'color' => self::C_REP, 'sub' => $n0($d['tecnici_notte']) . ' tecnici · modalità Reperibilità, 18:01–08:59'],
                ['label' => 'Con attività il giorno succ.', 'value' => $n0($d['casi']), 'color' => self::C_GS, 'sub' => (($p = self::pct($d['casi'], $d['notturni'])) !== null ? number_format($p, 1, ',', '.') . '%' : '') . ($d['cf'] ? ' · ' . $n0(count($rr)) . ' con i filtri di colonna' : '')],
                ['label' => 'Tecnici', 'value' => $n0($d['tecnici']), 'color' => '2563EB', 'sub' => 'con almeno un caso'],
            ]);
            if ($d['cf']) $r->meta('Filtri di colonna: ' . implode(' · ', array_map(fn($i, $v) => self::hRepEstese()[$i] . ' contiene «' . $v . '»', array_keys($d['cf']), $d['cf'])));
            // v1.10.34 — intestazioni colorate per gruppo (HTML, XLSX, DOCX, PDF); nel CSV, senza colori, intestazioni con suffisso
            $r->table('Controllo Reperibilità', $fmt === 'csv' ? self::hRepEstese() : self::H_REP, array_map([self::class, 'rep'], $rr), ['hcolors' => self::hRepColori()]);
            $r->note('Reperibilità = modulo in modalità Reperibilità (come il filtro Modalità della pagina) con inizio fra le 18:01 e le 08:59; turno del giorno di inizio se dopo le 18:01, del giorno precedente se prima delle 09:00. Giorno succ. = primo giorno lavorativo (lun–ven, esclusi i festivi nazionali) dopo il giorno del turno; si riporta il primo modulo NON in reperibilità dello stesso tecnico con inizio fra le 09:00 e le 18:00 e non prima della fine dell\'intervento in reperibilità. Cliente, Codice Commessa e Tipo (linea di servizio) sono riportati per ciascuno dei due interventi; i filtri della pagina si applicano agli interventi in reperibilità.');
            return $r;
        }
        if ($tab === 'tecnici') {
            $tl = $d['tl']; $t = $tl['totale']; $gg = (int)$tl['gg_lavorabili'];
            $r->kpi([
                ['label' => 'Tecnici', 'value' => $n0($t['tecnici'] ?? 0), 'color' => '2563EB', 'sub' => $n0($t['linee'] ?? 0) . ' codici linea'],
                ['label' => 'Attività', 'value' => $n0($t['attivita'] ?? 0), 'color' => '0891B2', 'sub' => $n0($t['ticket'] ?? 0) . ' ticket'],
                ['label' => 'GG lavorabili', 'value' => $n0($gg), 'color' => '64748B', 'sub' => 'lun–ven esclusi i festivi'],
                ['label' => 'Giornate-uomo', 'value' => $n0($t['giornate_uomo'] ?? 0), 'color' => '7C3AED'],
                ['label' => 'Ore lavorate', 'value' => $n2($t['ore'] ?? 0), 'color' => '16A34A', 'sub' => $n2($t['ore_fuori_orario'] ?? 0) . ' h fuori orario'],
            ]);
            $tot = ['Totale', (int)($t['linee'] ?? 0) . ' linee', '', (int)($t['attivita'] ?? 0), (int)($t['ticket'] ?? 0), $gg, (int)($t['giornate_uomo'] ?? 0), (float)($t['ore'] ?? 0), ''];
            $r->table('Riepilogo per tecnico e codice linea', self::H_MAIN, array_merge(array_map(fn($x) => self::main($x, $gg), $d['righe']), $d['righe'] ? [$tot] : []),
                ['dec' => [3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 2], 'right' => [3, 4, 5, 6, 7], 'total' => (bool)$d['righe']]);
            $r->note('N. attività = moduli di intervento · N. ticket = riferimenti ticket distinti dei moduli · GG lavorabili = giorni lunedì–venerdì del periodo esclusi i festivi nazionali · GG uomo lavorati = giorni distinti con almeno un modulo · Descrizione tariffa = fascia oraria e unità dei moduli («Fascia C (Ora)»), come nel riepilogo costi della Relazione di Servizio IT. Le righe «Totale» del tecnico contano i giorni una sola volta anche se lavorati su più linee.');
            $totD = ['Totale', (int)($t['linee'] ?? 0) . ' linee', '', (int)($t['giornate_uomo'] ?? 0), (float)($t['ore'] ?? 0), (float)($t['ore_ordinarie'] ?? 0), (float)($t['ore_fuori_orario'] ?? 0),
                     (float)($t['ore_reperibilita'] ?? 0), (float)($t['ore_extra'] ?? 0), (int)($t['presso_cliente'] ?? 0), (int)($t['da_remoto'] ?? 0), (int)($t['smart_working'] ?? 0)];
            $r->table('Metriche di dettaglio', self::H_DET, array_merge(array_map([self::class, 'det'], $d['righe']), $d['righe'] ? [$totD] : []),
                ['dec' => [3 => 0, 4 => 2, 5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 0, 10 => 0, 11 => 0], 'right' => [3, 4, 5, 6, 7, 8, 9, 10, 11], 'total' => (bool)$d['righe']]);
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
        $rows = array_map(fn($x) => [(string)$x['commessa'], (string)($x['denominazione'] ?? ''), (string)$x['cliente'], (string)$x['codice_linea'], (string)($x['linea_label'] ?? ''), ItServiceModel::tipologia($x['tipologia']),
                                     (int)$x['moduli'], (int)$x['da_ticket'], (int)$x['da_testo'], (int)$x['da_commessa'], (int)$x['ticket'], (int)$x['tecnici'], (float)$x['ore'],
                                     $x['dal'] ? date('d/m/Y', strtotime($x['dal'])) : '', $x['al'] ? date('d/m/Y', strtotime($x['al'])) : ''], $cm);
        if (count($rows) > 1) $rows[] = ['Totale', count($cm) . ' commesse', '', '', '', '', (int)$sum($cm, 'moduli'), (int)$sum($cm, 'da_ticket'), (int)$sum($cm, 'da_testo'), (int)$sum($cm, 'da_commessa'), null, null, round($sum($cm, 'ore'), 2), '', ''];
        $r->table($commessa !== null ? 'Commessa' : 'Per commessa', ['Commessa', 'Denominazione', 'Cliente', 'Codice linea', 'Linea di servizio', 'Tipologia', 'Moduli', 'Ticket (codice)', 'Rif. libero', 'Da commessa', 'Ticket distinti', 'Tecnici', 'Ore', 'Dal', 'Al'],
            $rows, ['dec' => [12 => 2], 'right' => [6, 7, 8, 9, 10, 11, 12], 'total' => count($rows) > 1]);
        if ($d['moduli']) {
            $cap = in_array($fmt, ['docx', 'pdf', 'print'], true) ? self::MAX_DOC : self::MAX_FILE;
            $mm = array_slice($d['moduli'], 0, $cap);
            if ($d['n_moduli'] > count($mm)) $r->note('Dettaglio moduli: primi ' . $n0(count($mm)) . ' su ' . $n0($d['n_moduli']) . ($cap === self::MAX_DOC ? ' (elenco completo in XLSX e CSV).' : '.'));
            $r->table('Dettaglio moduli di intervento', ['Modulo', 'Data', 'Commessa', 'Cliente', 'Tecnico', 'Codice linea', 'Linea di servizio', 'Tipologia', 'Modalità', 'Ore', 'Provenienza', 'Ticket', 'Attività DGB'],
                array_map(fn($x) => [(string)$x['modulo'], $x['giorno'] ? date('d/m/Y', strtotime($x['giorno'])) : '', (string)$x['commessa'], (string)$x['cliente'], (string)$x['tecnico'],
                                     (string)$x['codice_linea'], (string)($x['linea_label'] ?? ''), ItServiceModel::tipologia($x['tipologia']), ItServiceModel::etichetta($x['modalita']), (float)$x['ore'],
                                     ItServiceModel::PROV[$x['provenienza']] ?? $x['provenienza'], mb_substr((string)$x['ticket'], 0, 80), (string)$x['attivita_dgb']], $mm),
                ['dec' => [9 => 2], 'right' => [9]]);
        }
        return $r;
    }
}
