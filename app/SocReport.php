<?php
/**
 * PortalManager — app/SocReport.php  (v1.10.12)
 * Report del Service SOC (generale o del singolo componente) dal SOLO filtro principale ($f di SocModel::normFilters):
 * filtri, indicatori, ripartizioni per categoria / esito / stato, team, componenti × categoria, consuntivo attività
 * dei componenti dell'Unità Organizzativa SOC (modello Relazione di Servizio IT), ticket da presidiare, elenco ticket.
 * Formati: PmReport (DOCX, XLSX, CSV, PDF). DOCX/PDF limitano l'elenco ticket alle prime 1.000 righe.
 */
declare(strict_types=1);

require_once __DIR__ . '/PmReport.php';
require_once __DIR__ . '/SocModel.php';

final class SocReport
{
    /** Risorse per i report singoli: nome nel sistema SOC → etichetta (incaricati e autori del perimetro). */
    public static function tecnici(SocModel $soc, array $f): array
    {
        $out = [];
        foreach ($soc->team($f) as $t) {
            if (!empty($t['senza_ticket']) || str_starts_with((string)$t['nome'], '(')) continue;
            if ((int)$t['ticket'] + (int)($t['seguiti'] ?? 0) === 0) continue;
            $out[$t['nome']] = $t['nome'] . ($t['dipendente'] && $t['dipendente'] !== $t['nome'] ? ' (' . $t['dipendente'] . ')' : '');
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public static function filtri(array $f): string
    {
        $p = [];
        if ($f['contratti']) $p[] = 'Contratti: ' . implode(', ', $f['contratti']);
        if ($f['categoria']) $p[] = 'Categoria: ' . implode(', ', $f['categoria']);
        foreach (['cliente' => 'Cliente', 'commessa' => 'Commessa SOC', 'tec' => 'Componente', 'esito' => 'Esito', 'q' => 'Ricerca'] as $k => $l) if ($f[$k] !== '') $p[] = "$l: " . $f[$k];
        if ($f['stato'] !== '') $p[] = 'Stato: ' . ['aperti' => 'ancora aperti', 'chiusi' => 'chiusi', 'presidio' => 'da presidiare'][$f['stato']];
        return 'Filtri: ' . ($p ? implode(' · ', $p) : 'nessuno oltre al periodo');
    }

    public static function build(PDO $pdo, SocModel $soc, array $f, string $fmt): PmReport
    {
        $d = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';
        $n1 = static fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');
        $nn = static fn($v) => number_format((float)$v, 0, ',', '.');
        $lim = in_array($fmt, ['docx', 'pdf'], true) ? 1000 : 20000;
        $r = new PmReport('Service SOC — ' . ($f['tec'] !== '' ? 'report del componente ' . $f['tec'] : 'report generale'),
                          'Periodo ' . $d($f['from']) . ' – ' . $d($f['to']) . ' · generato il ' . date('d/m/Y H:i'));
        $r->box(self::filtri($f));

        $hl = $soc->headline($f);
        $r->kpi([
            ['label' => 'Ticket del periodo', 'value' => $nn($hl['attivi']), 'color' => '334155', 'sub' => $nn($hl['aperti']) . ' aperti · ' . $nn($hl['chiusi']) . ' chiusi'],
            ['label' => 'Risposta al cliente', 'value' => $hl['presa_mediana_h'] === null ? '—' : $n1($hl['presa_mediana_h']) . ' h', 'color' => '2563EB',
             'sub' => ($hl['sla_pct'] === null ? '—' : $n1($hl['sla_pct']) . '%') . ' entro ' . $nn($hl['sla_ore']) . ' h'],
            ['label' => 'Tempo di chiusura', 'value' => $hl['chiusura_mediana_g'] === null ? '—' : $n1($hl['chiusura_mediana_g']) . ' gg', 'color' => '16A34A', 'sub' => $nn($hl['risolti']) . ' risolti'],
            ['label' => 'Backlog a fine periodo', 'value' => $nn($hl['backlog']), 'color' => 'F59E0B', 'sub' => 'aperti al ' . $d($f['to'])],
            ['label' => 'Da presidiare', 'value' => $nn($hl['presidio']), 'color' => 'DC2626', 'sub' => 'attesa supporto'],
            ['label' => 'Moduli di intervento', 'value' => $n1($hl['moduli']['ore']) . ' h', 'color' => '7C3AED', 'sub' => $nn($hl['moduli']['moduli']) . ' moduli'],
        ]);

        $hdr = ['Voce', 'Ticket', 'Aperti nel periodo', 'Chiusi', 'Risolti', 'Ancora aperti', 'Risposta media (h)', 'Chiusura media (gg)', 'Ore moduli'];
        $bk = static fn(array $rows) => array_map(fn($b) => [$b['k'], (int)$b['attivi'], (int)$b['aperti'], (int)$b['chiusi'], (int)$b['risolti'], (int)$b['ancora_aperti'],
            $b['risposta_media_h'] !== null ? (float)$b['risposta_media_h'] : null, $b['chiusura_media_g'] !== null ? (float)$b['chiusura_media_g'] : null, (float)$b['ore_moduli']], $rows);
        $cat = $soc->breakdown($f, 'categoria');
        $r->heading('Ripartizioni', 1);
        $r->bars('Ticket per categoria', array_map(fn($b) => [$b['k'], (float)$b['attivi']], array_slice($cat, 0, 15)), 'ticket');
        $r->table('Per categoria', $hdr, $bk($cat));
        $r->table('Esito dei ticket chiusi', $hdr, $bk(array_values(array_filter($soc->breakdown($f, 'esito'), fn($x) => $x['k'] !== '(non indicato)'))));
        $r->table('Stato attuale', $hdr, $bk($soc->breakdown($f, 'stato')));

        $r->heading('Team SOC', 1);
        $team = $soc->team($f);
        if ($f['tec'] !== '') $team = array_values(array_filter($team, fn($t) => $t['nome'] === $f['tec']));
        $r->table('Componenti', ['Componente SOC', 'Dipendente', 'Unità', 'Incaricato', 'di cui dedotti', 'Seguiti', 'Chiusi', 'Aperti', 'Msg supporto', 'Note', 'Risposta media (h)', 'Ore SOC', 'Ore totali'],
            array_map(fn($t) => [$t['nome'], $t['dipendente'] ?? '', $t['unita'] ?? '', (int)$t['ticket'], (int)($t['dedotti'] ?? 0), (int)($t['seguiti'] ?? 0), (int)$t['chiusi'], (int)$t['aperti'],
                (int)$t['msg_supporto'], (int)$t['note'], $t['risposta_media_h'] !== null ? (float)$t['risposta_media_h'] : null, (float)$t['ore_soc'], (float)$t['ore_tot']], $team));
        $tc = $soc->teamCategorie($f);
        if ($tc['rows']) {
            $cats = $tc['cats'];
            $rows = array_map(fn($x) => array_merge([$x['nome']], array_map(fn($c) => (int)($x['c'][$c] ?? 0), $cats), [(int)$x['tot']]), $tc['rows']);
            $rows[] = array_merge(['Totale'], array_map(fn($c) => (int)$tc['tot'][$c], $cats), [(int)array_sum($tc['tot'])]);
            $r->table('Componenti x categoria', array_merge(['Incaricato'], $cats, ['Totale']), $rows, ['total' => true]);
        }

        // consuntivo attività (modello Relazione di Servizio IT)
        require_once __DIR__ . '/ItServiceModel.php';
        $it = new ItServiceModel($pdo);
        [$cf, $info] = $soc->consuntivoFiltri($f, $it);
        $t = $it->totali($cf);
        $r->heading('Consuntivo attività — Unità Organizzativa SOC', 1);
        $r->meta('Moduli di intervento ' . ($f['tec'] !== '' ? 'del componente ' . $f['tec'] . ($info['tec'] ? ' (' . $info['tec'] . ')' : ' (non abbinato)') : 'dei ' . count($info['membri']) . ' componenti dell\'unità SOC')
               . ($info['ticket_filtrati'] !== null ? ' che riportano i ' . $info['ticket_filtrati'] . ' ticket dei filtri' : '')
               . '. Ordinarie + fuori orario + reperibilità (+ non classificate) = ore totali; giornate-uomo = operatore + giorno.');
        $r->kpi([
            ['label' => 'Ore totali', 'value' => $n1($t['ore'] ?? 0) . ' h', 'color' => '334155', 'sub' => $nn($t['interventi'] ?? 0) . ' interventi'],
            ['label' => 'Ordinarie', 'value' => $n1($t['ore_ordinarie'] ?? 0) . ' h', 'color' => '16A34A'],
            ['label' => 'Fuori orario', 'value' => $n1($t['ore_fuori_orario'] ?? 0) . ' h', 'color' => 'F59E0B'],
            ['label' => 'Reperibilità', 'value' => $n1($t['ore_reperibilita'] ?? 0) . ' h', 'color' => '7C3AED'],
            ['label' => 'Non classificate', 'value' => $n1($t['ore_non_classificate'] ?? 0) . ' h', 'color' => '94A3B8'],
            ['label' => 'Giornate-uomo', 'value' => $nn($t['giornate_uomo'] ?? 0), 'color' => '2563EB', 'sub' => $nn($t['incaricati'] ?? 0) . ' operatori'],
        ]);
        $ch = ['Interventi', 'Giornate-uomo', 'Ore totali', 'Ordinarie', 'Fuori orario', 'Reperibilità', 'N. interv. reperib.', 'Non classificate', 'Ore a ricavo'];
        $cv = static fn($x) => [(int)$x['interventi'], (int)$x['giornate_uomo'], (float)$x['ore'], (float)$x['ore_ordinarie'], (float)$x['ore_fuori_orario'], (float)$x['ore_reperibilita'],
                                (int)$x['reperibilita'], (float)$x['ore_non_classificate'], (float)$x['ore_ricavo']];
        $r->table('Consuntivo per tipologia di contratto', array_merge(['Codice', 'Tipologia di contratto', 'Modello'], $ch),
            array_map(fn($x) => array_merge([$x['linea_servizio'], $x['linea_label'], ItServiceModel::etichetta($x['modello_contratto'])], $cv($x)), $it->aggrega(['gb' => ['linea_servizio', 'linea_label', 'modello_contratto']] + $cf, 500)));
        $r->table('Consuntivo per operatore', array_merge(['Operatore'], $ch), array_map(fn($x) => array_merge([$x['incaricato']], $cv($x)), $it->aggrega(['gb' => ['incaricato']] + $cf, 500)));
        $r->table('Operatore x tipologia', array_merge(['Operatore', 'Tipologia di contratto'], $ch),
            array_map(fn($x) => array_merge([$x['incaricato'], $x['linea_label']], $cv($x)), $it->aggrega(['gb' => ['incaricato', 'linea_label']] + $cf, 5000)));

        $r->heading('Ticket', 1);
        $tk = static fn(array $rows) => array_map(fn($x) => [$x['ticket_code'], $x['title'], $x['client_name'], $x['category'], $x['assignee_name'] . (($x['assignee_source'] ?? '') === 'dedotto' ? ' (ded.)' : ''),
            $x['opened_at'] ? date('d/m/Y H:i', strtotime($x['opened_at'])) : '', $x['last_event_at'] ? date('d/m/Y H:i', strtotime($x['last_event_at'])) : '', $x['status_now'], $x['resolution'], (int)$x['n_events'],
            $x['avg_reply_min'] !== null ? round($x['avg_reply_min'] / 60, 1) : null, (float)$x['ore']], $rows);
        $th = ['Ticket', 'Titolo', 'Cliente', 'Categoria', 'Incaricato', 'Aperto', 'Ultimo evento', 'Stato', 'Esito', 'Eventi', 'Risposta media (h)', 'Ore moduli'];
        $r->table('Da presidiare', $th, $tk($soc->presidio($f, 200)));
        $tot = $soc->countTickets($f);
        $list = $soc->tickets($f, $lim);
        $r->table('Elenco ticket', $th, $tk($list));
        if ($tot > count($list)) $r->note('Elenco limitato alle prime ' . count($list) . ' righe su ' . $tot . ': XLSX e CSV riportano l\'elenco completo.');
        return $r;
    }
}
