<?php
/**
 * PortalManager — app/SdReport.php  (v1.10.12)
 * Report del Service Desk (generale o del componente) dal SOLO filtro principale ($f di SdModel::normFilters):
 * quadro, classi di gestione, andamento, scheda del tecnico (con il filtro «Componente»), operatori, analisi del team,
 * moduli per codice linea, OBJ_2, ticket da presidiare. Stesso perimetro di pagina, stampa ed export dati XLSX.
 * Formati: PmReport (DOCX, XLSX, CSV, PDF).
 */
declare(strict_types=1);

require_once __DIR__ . '/PmReport.php';
require_once __DIR__ . '/SdModel.php';

final class SdReport
{
    /** Componenti con attività nel perimetro (ticket presi o moduli): nome → etichetta. */
    public static function tecnici(SdModel $sd, array $f): array
    {
        $out = [];
        try {
            foreach ($sd->teamDettaglio($f) as $d) {
                if ((int)$d['ticket_presi'] + (int)$d['moduli'] === 0) continue;
                $out[$d['tecnico']] = $d['tecnico'] . (($d['sotto_unita'] ?? '') !== '' ? ' — ' . $d['sotto_unita'] : '');
            }
        } catch (Throwable $e) {}
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    public static function filtri(array $f): string
    {
        $p = [];
        if ($f['contratti']) $p[] = 'Contratti: ' . implode(', ', $f['contratti']);
        foreach (['tec' => 'Componente', 'queue' => 'Coda', 'level' => 'Livello', 'gest' => 'Classe di gestione'] as $k => $l) if (($f[$k] ?? '') !== '') $p[] = "$l: " . $f[$k];
        return 'Filtri: ' . ($p ? implode(' · ', $p) : 'nessuno oltre al periodo');
    }

    public static function build(SdModel $sd, array $f, string $fmt): PmReport
    {
        $d = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';
        $nn = static fn($v) => number_format((float)$v, 0, ',', '.');
        $n1 = static fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');
        $tec = (string)$f['tec'];
        $r = new PmReport('Service Desk — ' . ($tec !== '' ? 'report del componente ' . $tec : 'report generale'),
                          'Periodo ' . $d($f['from']) . ' – ' . $d($f['to']) . ' · generato il ' . date('d/m/Y H:i'));
        $r->box(self::filtri($f));

        $h = $sd->headline($f);
        $r->heading('Quadro del periodo', 1);
        $r->kpi([
            ['label' => 'Ticket', 'value' => $nn($h['ticket'] ?? 0), 'color' => '334155', 'sub' => $nn($h['chiusi'] ?? 0) . ' chiusi'],
            ['label' => 'Presi in carico', 'value' => $nn($h['presi_in_carico'] ?? 0), 'color' => '2563EB', 'sub' => $nn($h['risolti_l1'] ?? 0) . ' risolti dal Service Desk'],
            ['label' => 'Escalation', 'value' => ($h['tasso_escalation'] ?? null) === null ? '—' : $n1($h['tasso_escalation']) . '%', 'color' => 'F59E0B', 'sub' => $nn($h['escalation'] ?? 0) . ' ticket'],
            ['label' => 'Diretti specialisti', 'value' => $nn($h['diretti'] ?? 0), 'color' => '7C3AED'],
            ['label' => 'Mai presi in carico', 'value' => $nn($h['mai_presi'] ?? 0), 'color' => '64748B'],
            ['label' => 'Da presidiare', 'value' => $nn($h['scoperti'] ?? 0), 'color' => 'DC2626'],
        ]);
        $r->note('Tasso di escalation sui ticket presi in carico dal Service Desk, non sul totale.');

        $brk = $sd->breakdown($f);
        $r->bars('Come sono stati gestiti', array_map(fn($b) => [(string)$b['gestione'], (float)$b['ticket']], $brk), 'ticket');
        $r->table('Classi di gestione', ['Classe di gestione', 'Ticket', 'Chiusi', 'Durata media (h)'],
            array_map(fn($b) => [$b['gestione'], (int)$b['ticket'], (int)$b['chiusi'], $b['durata_media'] !== null ? (float)$b['durata_media'] : null], $brk));
        $r->table('Andamento', ['Periodo', 'Ticket', 'Risolti L1', 'Escalation', 'Diretti specialisti', 'Mai presi', 'Tasso escalation %'],
            array_map(fn($t) => [$t['ym'], (int)$t['ticket'], (int)$t['risolti_l1'], (int)$t['escalation'], (int)$t['diretti'], (int)$t['mai_presi'], $t['tasso'] !== null ? (float)$t['tasso'] : null], $sd->trend($f, 12)));

        if ($tec !== '') {
            $sch = $sd->scheda($tec, $f);
            if ($sch) {
                $r->heading('Scheda del componente — ' . $tec, 1);
                $r->table('Esito dei ticket presi in carico', ['Presi in carico', 'Risolti', 'Scalati', 'Tasso escalation %', 'Ore prima risposta'],
                    [[(int)$sch['presi_in_carico'], (int)$sch['risolti'], (int)$sch['scalati'], $sch['tasso_escalation_pct'] !== null ? (float)$sch['tasso_escalation_pct'] : null, $sch['ore_prima_risposta'] !== null ? (float)$sch['ore_prima_risposta'] : null]]);
                $r->table('Attività complessiva', ['Messaggi', 'Risposte', 'Note interne', 'Ticket toccati', 'Code', 'Giorni attivi'],
                    [[(int)$sch['messaggi'], (int)$sch['risposte'], (int)$sch['note'], (int)$sch['ticket_toccati'], (int)$sch['code'], (int)$sch['giorni_attivi']]]);
                $mr = $sd->moduliRiepilogo($tec, $f);
                $r->kpi([
                    ['label' => 'Moduli di intervento', 'value' => $nn($mr['moduli'] ?? 0), 'color' => '334155'],
                    ['label' => 'Ore moduli', 'value' => $n1($mr['ore'] ?? 0) . ' h', 'color' => '2563EB'],
                    ['label' => 'Ore a ricavo', 'value' => $n1($mr['ore_ricavo'] ?? 0) . ' h', 'color' => '16A34A'],
                    ['label' => 'Ore interne', 'value' => $n1($mr['ore_interne'] ?? 0) . ' h', 'color' => '94A3B8'],
                ]);
                $r->table('Moduli per tipologia di contratto', ['Codice', 'Tipologia', 'Modello', 'Moduli', 'Ore', 'Ore extra', 'Commesse', 'Natura'],
                    array_map(fn($c) => [$c['codice'] ?? '', $c['contratto'], $c['modello'], (int)$c['moduli'], (float)$c['ore'], (float)$c['ore_extra'], (int)$c['commesse'], $c['ha_ricavo'] ? 'a ricavo' : 'interna'], $sd->moduliContratto($tec, $f)));
                $r->table('Code', ['Coda', 'Ticket', 'Presi in carico', 'Totale coda', 'Quota %'],
                    array_map(fn($c) => [$c['coda'], (int)$c['ticket'], (int)$c['presi_in_carico'], (int)$c['ticket_coda'], $c['quota_coda_pct'] !== null ? (float)$c['quota_coda_pct'] : null], $sd->codeDettaglio($tec, $f)));
                $r->table('Ticket presi in carico', ['Ticket', 'Oggetto', 'Coda', 'Stato', 'Esito', 'Aperto il', '1a risposta (h)', 'Messaggi'],
                    array_map(fn($t) => [$t['ticket'], $t['oggetto'], $t['coda'], $t['stato'], $t['gestione'], $t['aperto_il'], $t['ore_1a'] !== null ? (float)$t['ore_1a'] : null, (int)$t['messaggi']],
                        $sd->schedaTicket($tec, $f, in_array($fmt, ['docx', 'pdf'], true) ? 500 : 5000)));
            }
        }

        $r->heading('Team e operatori', 1);
        $team = $sd->teamDettaglio($f);
        if ($tec !== '') $team = array_values(array_filter($team, fn($x) => $x['tecnico'] === $tec));
        $r->table('Analisi del team', ['Componente', 'Sotto-unità', 'Ticket presi', 'Moduli', 'Ore', 'In orario', 'Fuori orario', '% fuori', 'Ore a ricavo', 'Commesse', 'Giornate', 'h/giorno'],
            array_map(fn($x) => [$x['tecnico'], $x['sotto_unita'], (int)$x['ticket_presi'], (int)$x['moduli'], (float)$x['ore'], (float)$x['ore_in_orario'], (float)$x['ore_fuori_orario'],
                $x['pct_fuori'] !== null ? (float)$x['pct_fuori'] : null, (float)$x['ore_a_ricavo'], (int)$x['commesse'], (int)$x['giornate'], $x['ore_per_giornata'] !== null ? (float)$x['ore_per_giornata'] : null], $team));
        $r->table('Operatori (messaggi sui ticket)', ['Tecnico', 'Livello', 'Sotto-unità', 'Messaggi', 'Risposte', 'Note', 'Ticket', 'Code'],
            array_map(fn($o) => [$o['tecnico'], $o['livello'], $o['sotto_unita'], (int)$o['messaggi'], (int)$o['risposte'], (int)$o['note'], (int)$o['ticket'], (int)$o['code']], $sd->operatori($f)));
        $r->table('Moduli per codice linea', ['Codice', 'Linea di servizio', 'Moduli', 'Ore', 'Tecnici', 'Commesse', 'Natura'],
            array_map(fn($c) => [$c['codice'], $c['etichetta'], (int)$c['moduli'], (float)$c['ore'], (int)$c['tecnici'], (int)$c['commesse'], $c['ha_ricavo'] ? 'a ricavo' : 'interna'], $sd->codiciLinea($f)));

        if ($tec === '') {
            $o2 = $sd->obj2Quadro($f);
            $r->heading('OBJ_2 — commesse e addetti', 1);
            $r->table('OBJ_2 quadro', ['Indicatore', 'Valore'], [
                ['Commesse nel perimetro', (int)($o2['commesse'] ?? 0)], ['di cui aperte', (int)($o2['commesse_aperte'] ?? 0)], ['Clienti', (int)($o2['clienti'] ?? 0)],
                ['Valore totale €', (float)($o2['valore_totale'] ?? 0)], ['Maturato €', (float)($o2['maturato'] ?? 0)], ['Costi €', (float)($o2['costi'] ?? 0)],
                ['Margine €', (float)($o2['margine'] ?? 0)], ['Margine %', isset($o2['margine_pct']) ? (float)$o2['margine_pct'] : null],
                ['Addetti distinti', (int)($o2['addetti_distinti'] ?? 0)], ['Equivalenti a tempo pieno', isset($o2['fte_equivalenti']) ? (float)$o2['fte_equivalenti'] : null],
                ['Ore totali', isset($o2['ore_totali']) ? (float)$o2['ore_totali'] : null], ['Tasso di escalation %', isset($o2['escalation_pct']) ? (float)$o2['escalation_pct'] : null],
            ]);
            $r->table('OBJ_2 per linea', ['Codice', 'Contratto', 'Modello', 'Commesse', 'Aperte', 'Valore €', 'Maturato €', 'Costi €', 'Margine €', 'Margine %', 'Clienti', 'Natura'],
                array_map(fn($x) => [$x['codice_linea'], $x['contratto'], $x['modello'], (int)$x['commesse'], (int)$x['aperte'], (float)$x['valore'], (float)$x['maturato'], (float)$x['costi'],
                    (float)$x['margine'], $x['margine_pct'] !== null ? (float)$x['margine_pct'] : null, (int)$x['clienti'], $x['ha_ricavo'] ? 'a ricavo' : 'interna'], $sd->obj2Linee($f)));
        }

        $r->table('Ticket da presidiare', ['Ticket', 'Oggetto', 'Coda', 'Stato', 'Aperto il', 'Giorni', 'Gestione', 'Presidio'],
            array_map(fn($s) => [$s['ticket'], $s['oggetto'], $s['coda'], $s['stato'], $s['aperto_il'], (int)$s['giorni'], $s['gestione'], $s['presidio']], $sd->scoperti($f, 200)));
        return $r;
    }
}
