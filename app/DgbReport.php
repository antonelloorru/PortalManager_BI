<?php
/**
 * PortalManager — app/DgbReport.php  (v1.10.12)
 * Report di Attività & Rendicontazione DGB (generale o del singolo incaricato) dal SOLO filtro principale
 * ($f di DgbModel::normFilters): filtri, KPI attività, riepilogo ore per classe (stessa logica della Relazione di
 * Servizio IT), dettaglio aggregato col raggruppamento della pagina, per incaricato, per linea di servizio, per mese,
 * anomalie orarie, elenco attività. Formati: PmReport (DOCX, XLSX, CSV, PDF).
 */
declare(strict_types=1);

require_once __DIR__ . '/PmReport.php';
require_once __DIR__ . '/DgbModel.php';

final class DgbReport
{
    public static function tecnici(DgbModel $m, array $f): array
    {
        return $m->operatoriPerimetro($f);
    }

    public static function filtri(array $f, array $vCtr, array $ops): string
    {
        $p = [];
        if ($f['contratti']) $p[] = 'Contratti: ' . implode(', ', array_map(fn($v) => PmContractFilter::label($v, $vCtr), $f['contratti']));
        if ($f['operators']) $p[] = 'Incaricato: ' . implode(', ', array_map(fn($id) => $ops[(string)$id] ?? ('#' . $id), $f['operators']));
        $lab = ['stati' => 'Stato commessa', 'statuses' => 'Stato attività', 'report_types' => 'Tipo report', 'modes' => 'Modalità', 'schedules' => 'Orario',
                'linee' => 'Linea di servizio', 'codici' => 'Codice linea', 'settori' => 'Settore', 'aziende' => 'Azienda', 'sedi' => 'Sede', 'fasce' => 'Fascia oraria', 'durate' => 'Durata',
                'clienti' => 'Cliente (id)', 'tipi' => 'Tipo attività (id)'];
        foreach ($lab as $k => $l) if (!empty($f[$k])) $p[] = "$l: " . implode(', ', $f[$k]);
        foreach (['q' => 'Ricerca', 'cliente' => 'Cliente', 'ricavo' => 'Natura', 'rep' => 'In reperibilità', 'extra' => 'Straordinario', 'ticket' => 'Ticket', 'modulo' => 'Modulo', 'sforo' => 'Oltre il pianificato', 'oncall' => 'Reperibile'] as $k => $l)
            if (($f[$k] ?? '') !== '') $p[] = "$l: " . (in_array($f[$k], ['0', '1'], true) && $k !== 'q' && $k !== 'cliente' ? ($f[$k] === '1' ? 'sì' : 'no') : $f[$k]);
        return 'Filtri: ' . ($p ? implode(' · ', $p) : 'nessuno oltre al periodo');
    }

    public static function build(PDO $pdo, DgbModel $m, array $f, string $fmt, array $vCtr = []): PmReport
    {
        $d = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '…';
        $nn = static fn($v) => number_format((float)$v, 0, ',', '.');
        $n1 = static fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');
        $eur = static fn($v) => number_format((float)$v, 0, ',', '.') . ' €';
        $ops = $m->operatoriPerimetro(['operators' => []] + $f);
        $one = count($f['operators']) === 1 ? ($ops[(string)$f['operators'][0]] ?? ('#' . $f['operators'][0])) : '';
        $lim = in_array($fmt, ['docx', 'pdf'], true) ? 1000 : 20000;
        $r = new PmReport('Attività & Rendicontazione DGB — ' . ($one !== '' ? 'report dell\'incaricato ' . $one : 'report generale'),
                          'Periodo (data lavoro) ' . $d($f['from']) . ' – ' . $d($f['to']) . ' · generato il ' . date('d/m/Y H:i'));
        $r->box(self::filtri($f, $vCtr, $ops));

        $k = $m->kpi($f); $ps = $m->periodSummary($f); $tot = $m->aggregaTotale($f);
        $r->kpi([
            ['label' => 'Attività', 'value' => $nn($k['activities'] ?? 0), 'color' => '334155', 'sub' => $nn($k['late'] ?? 0) . ' avviate oltre lo SLA'],
            ['label' => 'Ore consuntivate', 'value' => $n1($tot['ore'] ?? 0) . ' h', 'color' => '2563EB', 'sub' => $n1($k['planned_hours'] ?? 0) . ' h pianificate'],
            ['label' => 'Ordinarie', 'value' => $n1($tot['ore_ordinarie'] ?? 0) . ' h', 'color' => '16A34A'],
            ['label' => 'Fuori orario', 'value' => $n1($tot['ore_fuori_orario'] ?? 0) . ' h', 'color' => 'F59E0B'],
            ['label' => 'Reperibilità', 'value' => $n1($tot['ore_reperibilita'] ?? 0) . ' h', 'color' => '7C3AED'],
            ['label' => 'Giornate-uomo', 'value' => $nn($tot['giornate_uomo'] ?? 0), 'color' => '0891B2', 'sub' => $nn($ps['incaricati'] ?? 0) . ' incaricati'],
            ['label' => 'Costo', 'value' => $eur($tot['costo'] ?? 0), 'color' => 'DC2626'],
            ['label' => 'Ricavo', 'value' => $eur($tot['ricavo'] ?? 0), 'color' => '16A34A', 'sub' => 'margine ' . $eur($tot['margine'] ?? 0)],
            ['label' => 'Consuntivo / pianificato', 'value' => ($k['achievement_pct'] ?? null) === null ? '—' : $n1($k['achievement_pct']) . '%', 'color' => '334155', 'sub' => 'delta ' . $n1($k['delta_hours'] ?? 0) . ' h'],
        ]);
        $r->note('Ore per classe con la regola della Relazione di Servizio IT: reperibilità dall\'allocazione, ordinarie = sovrapposizione reale con le fasce ordinarie, fuori orario = differenza.');

        $met = ['Attività', 'Allocazioni', 'Giornate-uomo', 'Ore', 'Ordinarie', 'Fuori orario', 'Reperibilità', 'Extra', 'Viaggio', 'Costo €', 'Ricavo €', 'Margine €'];
        $mv = static fn($x) => [(int)$x['attivita'], (int)$x['allocazioni'], (int)$x['giornate_uomo'], (float)$x['ore'], (float)$x['ore_ordinarie'], (float)$x['ore_fuori_orario'],
                                (float)$x['ore_reperibilita'], (float)$x['ore_extra'], (float)$x['ore_viaggio'], (float)$x['costo'], (float)$x['ricavo'], (float)$x['margine']];
        $tab = function (string $title, array $gb) use ($m, $f, $met, $mv, $r): void {
            $rows = $m->aggrega(['gb' => $gb] + $f, 5000);
            $out = array_map(fn($x) => array_merge(array_map(fn($g) => DgbModel::etichetta($g, (string)$x[$g]), $gb), $mv($x)), $rows);
            if ($out) { $t = $m->aggregaTotale($f); $out[] = array_merge(['Totale'], array_fill(0, count($gb) - 1, ''), $mv($t)); }
            $r->table($title, array_merge(array_map(fn($g) => DgbModel::DIM[$g] ?? $g, $gb), $met), $out, ['total' => (bool)$out]);
        };
        $r->heading('Consuntivo', 1);
        $gbPage = $f['gb'] ?? ['incaricato', 'contratto'];
        $tab('Dettaglio — ' . implode(' × ', array_map(fn($g) => DgbModel::DIM[$g] ?? $g, $gbPage)), $gbPage);
        if ($gbPage !== ['incaricato']) $tab('Per incaricato', ['incaricato']);
        $tab('Per tipologia di contratto', ['linea_servizio', 'linea_label']);
        $tab('Per mese', ['anno_mese']);

        // anomalie orarie (stesso filtro principale)
        $r->heading('Anomalie di imputazione oraria', 1);
        try {
            [$wA, $aA] = $m->anomalieWhere($f);
            $st = $pdo->prepare("SELECT tipo, severita, COUNT(*) n, COUNT(DISTINCT operator_id) tecnici, ROUND(SUM(ore), 2) ore FROM v_dgb_anomalie_orario WHERE $wA GROUP BY tipo, severita ORDER BY FIELD(severita,'alta','media'), tipo");
            $st->execute($aA);
            $lbl = ['ore_duplicate' => 'Ore identiche su più commesse', 'ore_giornaliere' => 'Ore giornaliere fuori scala'];
            $r->table('Riepilogo anomalie', ['Tipo', 'Severità', 'Segnalazioni', 'Tecnici', 'Ore'], array_map(fn($x) => [$lbl[$x['tipo']] ?? $x['tipo'], $x['severita'], (int)$x['n'], (int)$x['tecnici'], (float)$x['ore']], $st->fetchAll(PDO::FETCH_ASSOC)));
            $st = $pdo->prepare("SELECT * FROM v_dgb_anomalie_orario WHERE $wA ORDER BY FIELD(severita,'alta','media'), giorno DESC, ore DESC LIMIT " . $lim);
            $st->execute($aA);
            $r->table('Segnalazioni', ['Severità', 'Tipo', 'Tecnico', 'Giorno', 'Ore', 'Righe', 'Commesse', 'Rilievo', 'Dettaglio'],
                array_map(fn($x) => [$x['severita'], $lbl[$x['tipo']] ?? $x['tipo'], $x['tecnico'] ?: '', $x['giorno'] ? date('d/m/Y', strtotime($x['giorno'])) : '', (float)$x['ore'], (int)$x['righe'], (int)$x['commesse_distinte'], $x['descrizione'] ?? '', $x['dettaglio'] ?? ''], $st->fetchAll(PDO::FETCH_ASSOC)));
        } catch (Throwable $e) { $r->note('Viste delle anomalie non disponibili.'); }

        $r->heading('Attività', 1);
        $n = $m->count($f);
        $rows = $m->table($f, $lim, 0);
        $r->table('Elenco attività', ['Codice', 'Ticket', 'Stato', 'Avvio effettivo', 'Avvio previsto', 'SLA (h)', 'Ore pianificate', 'Ore consuntivate', 'Delta ore', 'Costo €', 'Ricavo €'],
            array_map(fn($x) => [(string)$x['code'], (string)$x['ticket'], (string)$x['status'], $x['date_start'] ? date('d/m/Y H:i', strtotime($x['date_start'])) : '',
                ($x['planned_start'] ?? '') ? date('d/m/Y H:i', strtotime($x['planned_start'])) : '', $x['sla_hours'] !== null ? (float)$x['sla_hours'] : null,
                $x['planned_hours'] !== null ? (float)$x['planned_hours'] : null, $x['actual_hours'] !== null ? (float)$x['actual_hours'] : null, $x['delta_hours'] !== null ? (float)$x['delta_hours'] : null,
                $x['total_cost'] !== null ? (float)$x['total_cost'] : null, $x['total_revenue'] !== null ? (float)$x['total_revenue'] : null], $rows));
        if ($n > count($rows)) $r->note('Elenco limitato alle prime ' . count($rows) . ' attività su ' . $n . ($fmt === 'docx' || $fmt === 'pdf' ? ': XLSX e CSV arrivano a 20.000.' : '.'));
        return $r;
    }
}
