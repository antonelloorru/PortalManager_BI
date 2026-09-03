<?php
/**
 * service_desk.php — Rendicontazione statistica del Service Desk (v1.8.84)
 *
 * Quattro indicatori in testa, la ripartizione nelle sei classi di gestione,
 * l'andamento mensile e la lista dei ticket che richiedono un intervento.
 *
 * La classificazione L1/L2 non e' calcolata qui: viene dalle viste della
 * v1.8.82/83, che leggono l'appartenenza all'unita' "Service Desk" definita in
 * Unita Organizzative Tecniche. Cambiare l'assegnazione di un tecnico si
 * riflette immediatamente su queste statistiche.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/SdModel.php');

// v1.8.93 — palette delle aziende esecutrici, distinta da quella delle classi
// di gestione: sono dimensioni diverse, e colori condivisi suggerirebbero un
// legame che non c'e'.
$COLAZ = ['#2563eb','#16a34a','#f59e0b','#7c3aed','#dc2626','#0891b2'];

if (!can('view', 'service_desk.php')) { redirect('manage_projects'); }
$u_id = (int)$_SESSION['user_id'];

$sd = new SdModel($pdo);
$f  = $sd->normFilters($_GET);

// ── dati ────────────────────────────────────────────────────────────────────
// Ogni blocco e' protetto singolarmente: se il modulo ticket non e' ancora
// sincronizzato, la pagina deve spiegarlo invece di produrre un errore.
$pronto = true; $errore = '';
try {
    $h    = $sd->headline($f);
    $brk  = $sd->breakdown($f);
    $trend = $sd->trend($f, 12);
    $scop = $sd->scoperti($f, 50);
    $ops  = $sd->operatori($f);
    $code = $sd->code($f);
    $team = $sd->team();
    // v1.9.5 — analisi di squadra: moduli, fasce, contratti
    $tQuadro = $sd->teamQuadro($f);
    $tDett   = $sd->teamDettaglio($f);
    $tFascia = $sd->teamFascia($f);
    $tContr  = $sd->teamContratto($f);
    $elencoCode = $sd->elencoCode();
    // v1.9.6 — elenco dei componenti per il filtro, ordinato per cognome
    $elencoTeam = $sd->elencoTeam();
    // v1.9.7 — assenze del team: ferie, permessi, recuperi, malattia
    $assQ = $sd->assenzeQuadro($f);
    $assT = $sd->assenzeTeam($f);
    $assM = $sd->assenzeMesi($f);
    // v1.8.88 — il tecnico e' un filtro dei dati, non solo un parametro di
    // visualizzazione: normFilters() lo normalizza e ogni riquadro lo rispetta.
    $tec = $f['tec'];
    $sch = $tec !== '' ? $sd->scheda($tec, $f) : [];
    $confronto = $sd->operativita($f);
    $codLin    = $sd->codiciLinea($f);   // v1.8.92 — moduli per codice linea
    $aziende   = $sd->aziendeEsecutrici($f);  // v1.8.93 — per azienda esecutrice
    // v1.9.10 — OBJ_2 (quadro economico e operativo) e OBJ_2.3 (ripartizione)
    $o2Q    = $sd->obj2Quadro();
    $o2Lin  = $sd->obj2Linee();
    $o2Add  = $sd->obj2Addetti(24);
    $o23Rip = $sd->obj23Ripartizione();
    $o23Cod = $sd->obj23Code(20);
    // v1.9.11 — OBJ_2.1/2.2: attività dai moduli, non dai ticket
    $o21Q   = $sd->obj21Quadro($f);
    $o21Fat = $sd->obj21Fatturabili($f);
    $o22Int = $sd->obj22Interne($f);
    $o23Tec = $sd->obj23Tecnici($f);
    $listino = $sd->listino();
    // v1.9.15 — costi per fascia e contratto, layout del template aziendale
    $cQ   = $sd->costiQuadro($f);
    $cRie = $sd->costiRiepilogo($f);
    $cCom = $sd->costiPerCommessa($f);
    $cTar = $sd->costiTariffe();
} catch (Throwable $e) {
    $pronto = false; $errore = $e->getMessage();
    $h = $brk = $trend = $scop = $ops = $code = $team = $elencoCode = $elencoTeam = [];
    $assQ = []; $assT = $assM = [];
    $tec = ''; $sch = []; $confronto = []; $codLin = []; $aziende = [];
    $tQuadro = []; $tDett = $tFascia = $tContr = [];
    $o2Q = []; $o2Lin = $o2Add = $o23Rip = $o23Cod = [];
    $o21Q = []; $o21Fat = $o22Int = $o23Tec = $listino = [];
    $cQ = []; $cRie = $cCom = $cTar = [];
}

// ── export XLSX ─────────────────────────────────────────────────────────────
if ($pronto && ($_GET['export'] ?? '') === 'xlsx') {
    require_once(__DIR__ . '/app/XlsxWriter.php');
    $w = new XlsxWriter();

    $r1 = [['Classe di gestione', 'Ticket', 'Chiusi', 'Durata media (h)']];
    foreach ($brk as $b) $r1[] = [$b['gestione'], (int)$b['ticket'], (int)$b['chiusi'], $b['durata_media']];
    $w->addSheet('Ripartizione', $r1);

    $r2 = [['Mese', 'Ticket', 'Risolti L1', 'Escalation', 'Diretti specialisti', 'Mai presi', 'Tasso escalation %']];
    foreach ($trend as $t) $r2[] = [$t['ym'], (int)$t['ticket'], (int)$t['risolti_l1'], (int)$t['escalation'],
                                    (int)$t['diretti'], (int)$t['mai_presi'], $t['tasso']];
    $w->addSheet('Andamento', $r2);

    $r3 = [['Ticket', 'Oggetto', 'Coda', 'Stato', 'Aperto il', 'Giorni', 'Gestione', 'Presidio']];
    foreach ($scop as $s) $r3[] = [$s['ticket'], $s['oggetto'], $s['coda'], $s['stato'],
                                   $s['aperto_il'], (int)$s['giorni'], $s['gestione'], $s['presidio']];
    $w->addSheet('Da presidiare', $r3);

    $r4 = [['Tecnico', 'Livello', 'Sotto-unità', 'Messaggi', 'Risposte', 'Note', 'Ticket', 'Code']];
    foreach ($ops as $o) $r4[] = [$o['tecnico'], $o['livello'], $o['sotto_unita'], (int)$o['messaggi'],
                                  (int)$o['risposte'], (int)$o['note'], (int)$o['ticket'], (int)$o['code']];
    $w->addSheet('Operatori', $r4);

    // v1.8.88 — con un tecnico selezionato l'export porta anche i suoi fogli:
    // i quattro precedenti riflettono gia' il filtro, perche' passano dalla
    // stessa clausola del pannello.
    if ($tec !== '' && $sch) {
        $mr = $sd->moduliRiepilogo($tec, $f);
        $mc = $sd->moduliContratto($tec, $f);
        $cd = $sd->codeDettaglio($tec, $f);
        $tk = $sd->schedaTicket($tec, $f, 500);

        $r5 = [['Voce', 'Valore']];
        foreach ([
            ['Presi in carico', $sch['presi_in_carico']], ['Risolti', $sch['risolti']],
            ['Scalati', $sch['scalati']], ['Tasso escalation %', $sch['tasso_escalation_pct']],
            ['Ore prima risposta', $sch['ore_prima_risposta']], ['Messaggi', $sch['messaggi']],
            ['Risposte', $sch['risposte']], ['Note interne', $sch['note']],
            ['Ticket toccati', $sch['ticket_toccati']], ['Code', $sch['code']],
            ['Giorni attivi', $sch['giorni_attivi']],
            ['Moduli intervento', $mr['moduli'] ?? 0], ['Ore moduli', $mr['ore'] ?? 0],
            ['Ore a ricavo', $mr['ore_ricavo'] ?? 0], ['Ore interne', $mr['ore_interne'] ?? 0],
        ] as $rr) $r5[] = $rr;
        $w->addSheet('Scheda', $r5);

        $r6 = [['Tipologia', 'Modello', 'Moduli', 'Ore', 'Ore extra', 'Commesse', 'Natura']];
        foreach ($mc as $c) $r6[] = [$c['contratto'], $c['modello'], (int)$c['moduli'],
            $c['ore'], $c['ore_extra'], (int)$c['commesse'], $c['ha_ricavo'] ? 'a ricavo' : 'interna'];
        $w->addSheet('Contratti', $r6);

        $r7 = [['Coda', 'Ticket', 'Presi in carico', 'Totale coda', 'Quota %']];
        foreach ($cd as $c) $r7[] = [$c['coda'], (int)$c['ticket'], (int)$c['presi_in_carico'],
            (int)$c['ticket_coda'], $c['quota_coda_pct']];
        $w->addSheet('Code', $r7);

        $r8 = [['Ticket', 'Oggetto', 'Coda', 'Stato', 'Esito', 'Aperto il', '1a risposta (h)', 'Messaggi']];
        foreach ($tk as $t) $r8[] = [$t['ticket'], $t['oggetto'], $t['coda'], $t['stato'],
            $t['gestione'], $t['aperto_il'], $t['ore_1a'], (int)$t['messaggi']];
        $w->addSheet('Ticket presi', $r8);
    }

    $rc = [['Codice', 'Linea di servizio', 'Moduli', 'Ore', 'Tecnici', 'Commesse', 'Natura']];
    foreach ($codLin as $c) $rc[] = [$c['codice'], $c['etichetta'], (int)$c['moduli'],
        $c['ore'], (int)$c['tecnici'], (int)$c['commesse'], $c['ha_ricavo'] ? 'a ricavo' : 'interna'];
    $w->addSheet('Codici linea', $rc);

    // v1.9.5 — l'analisi di squadra nell'export
    $rt = [['Componente','Sotto-unità','Ticket presi','Moduli','Ore','In orario',
            'Fuori orario','% fuori','Ore a ricavo','Commesse','Giornate','h/giorno']];
    foreach ($tDett as $d) $rt[] = [$d['tecnico'], $d['sotto_unita'], (int)$d['ticket_presi'],
        (int)$d['moduli'], $d['ore'], $d['ore_in_orario'], $d['ore_fuori_orario'],
        $d['pct_fuori'], $d['ore_a_ricavo'], (int)$d['commesse'], (int)$d['giornate'],
        $d['ore_per_giornata']];
    $w->addSheet('Team', $rt);

    // v1.9.10 — OBJ_2 e OBJ_2.3 nell'export
    $rq = [['Indicatore','Valore']];
    foreach ([
      ['Commesse nel perimetro', $o2Q['commesse'] ?? 0],
      ['di cui aperte', $o2Q['commesse_aperte'] ?? 0],
      ['Clienti', $o2Q['clienti'] ?? 0],
      ['Valore totale', $o2Q['valore_totale'] ?? 0],
      ['Valore commesse aperte', $o2Q['valore_aperte'] ?? 0],
      ['Maturato', $o2Q['maturato'] ?? 0],
      ['Costi', $o2Q['costi'] ?? 0],
      ['Margine', $o2Q['margine'] ?? 0],
      ['Margine %', $o2Q['margine_pct'] ?? null],
      ['Addetti distinti', $o2Q['addetti_distinti'] ?? 0],
      ['Addetti medi per mese', $o2Q['addetti_medi_mese'] ?? null],
      ['Picco addetti in un mese', $o2Q['addetti_picco'] ?? null],
      ['Equivalenti a tempo pieno', $o2Q['fte_equivalenti'] ?? null],
      ['Ore totali', $o2Q['ore_totali'] ?? null],
      ['Ticket totali', $o2Q['ticket'] ?? 0],
      ['Ticket presi in carico', $o2Q['ticket_presi'] ?? 0],
      ['Risolti dal Service Desk', $o2Q['ticket_risolti'] ?? 0],
      ['Escalati a specialisti', $o2Q['ticket_scalati'] ?? 0],
      ['Presi direttamente da specialisti', $o2Q['ticket_diretti'] ?? 0],
      ['Mai presi in carico', $o2Q['ticket_mai_presi'] ?? 0],
      ['Tasso di escalation % (sui presi in carico)', $o2Q['escalation_pct'] ?? null],
    ] as $rr) $rq[] = $rr;
    $w->addSheet('OBJ_2 quadro', $rq);

    $rl = [['Codice','Contratto','Modello','Commesse','Aperte','Valore','Maturato','Costi',
            'Margine','Margine %','Clienti','Quota valore %','Natura']];
    foreach ($o2Lin as $x) $rl[] = [$x['codice_linea'], $x['contratto'], $x['modello'],
        (int)$x['commesse'], (int)$x['aperte'], $x['valore'], $x['maturato'], $x['costi'],
        $x['margine'], $x['margine_pct'], (int)$x['clienti'], $x['quota_valore_pct'],
        $x['ha_ricavo'] ? 'a ricavo' : 'interna'];
    $w->addSheet('OBJ_2 per linea', $rl);

    $rad = [['Mese','Addetti','Moduli','Ore','Commesse']];
    foreach ($o2Add as $x) $rad[] = [$x['anno_mese'], (int)$x['addetti'], (int)$x['moduli'],
        $x['ore'], (int)$x['commesse']];
    $w->addSheet('OBJ_2 addetti', $rad);

    $rrip = [['Classe di gestione','Ticket','Quota %','Code','Messaggi medi','Durata media h']];
    foreach ($o23Rip as $x) $rrip[] = [$x['gestione'], (int)$x['ticket'], $x['quota_pct'],
        (int)$x['code'], $x['messaggi_medi'], $x['durata_media_ore']];
    $w->addSheet('OBJ_2.3 ripartizione', $rrip);

    $rcod = [['Coda','Ticket','Quota %','Risolti','Escalati','Diretti','Mai presi',
              'Escalation %','Durata media h']];
    foreach ($o23Cod as $x) $rcod[] = [$x['coda'], (int)$x['ticket'], $x['quota_pct'],
        (int)$x['risolti'], (int)$x['scalati'], (int)$x['diretti'], (int)$x['mai_presi'],
        $x['escalation_pct'], $x['durata_media_ore']];
    $w->addSheet('OBJ_2.3 per coda', $rcod);

    $rcm = [['Commessa','Denominazione','Cliente','Codice','Contratto','Agente','Azienda',
             'Stato','Valore','Maturato','Costi','Margine','Margine %','Aperta']];
    foreach ($sd->obj2Commesse(2000) as $x) $rcm[] = [$x['commessa'], $x['denominazione'],
        $x['cliente'], $x['codice_linea'], $x['contratto'], $x['agente'], $x['azienda'],
        $x['stato'], $x['valore'], $x['maturato'], $x['costi'], $x['margine'],
        $x['margine_pct'], $x['aperta'] ? 'si' : 'no'];
    $w->addSheet('OBJ_2 commesse', $rcm);

    // v1.9.15 — riepilogo costi, layout del template aziendale.
    //
    // Un blocco per contratto con intestazione, righe per fascia e riga TOTALE:
    // e' la forma che il destinatario riconosce, e riprodurla evita di dover
    // rimpaginare a mano quello che il portale esporta.
    $rc = [];
    $lineaPrec = null; $tOre = 0.0; $tVal = 0.0;
    foreach ($cRie as $x) {
        if ($lineaPrec !== null && $x['codice_linea'] !== $lineaPrec) {
            $rc[] = ['TOTALE', '', '', round($tOre, 2), round($tVal, 2)];
            $rc[] = ['', '', '', '', ''];
            $tOre = 0.0; $tVal = 0.0;
        }
        if ($x['codice_linea'] !== $lineaPrec) {
            $rc[] = ['RIEPILOGO PER TIPO CONTRATTO', $x['codice_linea'], $x['contratto'], '', ''];
            $rc[] = ['Descrizione tariffa', 'N.Interventi', 'In reperibilità',
                     'Totale Ore', 'Totale Valore'];
            $lineaPrec = $x['codice_linea'];
        }
        $rc[] = [$x['descrizione_tariffa'], (int)$x['interventi'], $x['reperibilita'],
                 $x['ore'], $x['valore']];
        $tOre += (float)$x['ore']; $tVal += (float)$x['valore'];
    }
    if ($lineaPrec !== null) $rc[] = ['TOTALE', '', '', round($tOre, 2), round($tVal, 2)];
    $w->addSheet('Riepilogo x fascia e contratto', $rc);

    $rcc = [];
    $comPrec = null; $cOre = 0.0; $cVal = 0.0;
    foreach ($cCom as $x) {
        if ($comPrec !== null && $x['commessa'] !== $comPrec) {
            $rcc[] = ['', '', 'TOTALE', '', '', round($cOre, 2), round($cVal, 2)];
            $rcc[] = ['', '', '', '', '', '', ''];
            $cOre = 0.0; $cVal = 0.0;
        }
        if ($x['commessa'] !== $comPrec) {
            $rcc[] = ['Commessa', 'Tipo Contratto', '', '', '', '', ''];
            $rcc[] = [$x['commessa'], $x['codice_linea'], 'Descrizione tariffa',
                      'N.Moduli di intervento', 'In reperibilità', 'Totale Ore', 'Totale Valore'];
            $comPrec = $x['commessa'];
        }
        $rcc[] = ['', '', $x['descrizione_tariffa'], (int)$x['interventi'],
                  $x['reperibilita'], $x['ore'], $x['valore']];
        $cOre += (float)$x['ore']; $cVal += (float)$x['valore'];
    }
    if ($comPrec !== null) $rcc[] = ['', '', 'TOTALE', '', '', round($cOre, 2), round($cVal, 2)];
    $w->addSheet('Elenco Contratti e fasce', $rcc);

    $rtar = [['Linea', 'Fascia', 'Scaglione', 'Descrizione tariffa', 'Tariffa oraria',
              'Valuta', 'Origine', 'Attiva']];
    foreach ($cTar as $x) $rtar[] = [$x['service_line'], $x['fascia'], $x['scaglione'],
        $x['etichetta'], $x['tariffa_ora'], $x['valuta'], $x['origine'],
        $x['is_active'] ? 'si' : 'no'];
    $w->addSheet('Tariffe per fascia', $rtar);

    // v1.9.11 — OBJ_2.1 e OBJ_2.2 nell'export
    $rf1 = [['Natura','Codice','Contratto','Modello','Interventi','Tecnici','Commesse','Clienti',
             'Giornate','Ore','Ore extra','Valore addebitato','Righe addebitate',
             'Valore a listino','Tariffa/ora','Quota ore %']];
    foreach ([['fatturabile', $o21Fat], ['interna', $o22Int]] as [$nat, $set])
        foreach ($set as $x) $rf1[] = [$nat, $x['codice_linea'], $x['contratto'],
            $x['modello'] ?? '', (int)$x['interventi'], (int)$x['tecnici'], (int)$x['commesse'],
            (int)($x['clienti'] ?? 0), (int)$x['giornate'], $x['ore'], $x['ore_extra'],
            $x['valore_addebitato'], (int)($x['righe_addebitate'] ?? 0), $x['valore_listino'],
            $x['tariffa_ora'], $x['quota_ore_pct']];
    $w->addSheet('OBJ_2.1-2.2 attivita', $rf1);

    $rt1 = [['Tecnico','Unità','Interventi','Ore','Ore fatturabili','Ore interne',
             '% fatturabile','Valore addebitato','Valore a listino','Commesse','Giornate']];
    foreach ($o23Tec as $x) $rt1[] = [$x['tecnico'], $x['unita'], (int)$x['interventi'],
        $x['ore'], $x['ore_fatturabili'], $x['ore_interne'], $x['quota_fatturabile_pct'],
        $x['valore_addebitato'], $x['valore_listino'], (int)$x['commesse'], (int)$x['giornate']];
    $w->addSheet('OBJ_2.3 per tecnico', $rt1);

    $rli = [['Linea','Etichetta','Tariffa oraria','Valuta','Attiva','Note']];
    foreach ($listino as $x) $rli[] = [$x['service_line'], $x['label'], $x['tariffa_ora'],
        $x['valuta'], $x['is_active'] ? 'si' : 'no', $x['note']];
    $w->addSheet('Listino', $rli);

    // v1.9.7 — le assenze nell'export
    $rass = [['Componente','Ferie','Permessi','Recuperi','Malattia','Altre','Visite','Totale','Giornate']];
    foreach ($assT as $xa) $rass[] = [$xa['tecnico'], $xa['ferie'], $xa['permessi'],
        $xa['recuperi'], $xa['malattia'], $xa['altre'], $xa['visite'], $xa['totale'], $xa['giornate']];
    $w->addSheet('Assenze', $rass);

    $rassm = [['Mese','Ferie','Permessi','Recuperi','Malattia','Totale']];
    foreach ($assM as $xm) $rassm[] = [$xm['ym'], $xm['ferie'], $xm['permessi'],
        $xm['recuperi'], $xm['malattia'], $xm['totale']];
    $w->addSheet('Assenze per mese', $rassm);

    $rf = [['Fascia oraria','Interventi','Ore','Ore extra','Tecnici','Giornate','Ore medie']];
    foreach ($tFascia as $x) $rf[] = [$x['fascia_oraria'], (int)$x['interventi'], $x['ore'],
        $x['ore_extra'], (int)$x['tecnici'], (int)$x['giornate'], $x['ore_medie']];
    $w->addSheet('Fasce orarie', $rf);

    $rc2 = [['Codice','Contratto','Modello','Interventi','Ore','In orario','Fuori orario',
             'Ore extra','Tecnici','Commesse','Natura']];
    foreach ($tContr as $x) $rc2[] = [$x['codice_linea'], $x['contratto'], $x['modello'],
        (int)$x['interventi'], $x['ore'], $x['ore_in'], $x['ore_fuori'], $x['ore_extra'],
        (int)$x['tecnici'], (int)$x['commesse'], $x['ha_ricavo'] ? 'a ricavo' : 'interna'];
    $w->addSheet('Contratti e fasce', $rc2);

    $ra = [['Azienda esecutrice', 'Moduli', 'Ore', 'Tecnici', 'Commesse', 'Linee']];
    foreach ($aziende as $a) $ra[] = [$a['azienda'], (int)$a['moduli'], $a['ore'],
        (int)$a['tecnici'], (int)$a['commesse'], (int)$a['linee']];
    $w->addSheet('Aziende', $ra);

    $suff = $tec !== '' ? '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $tec) : '';
    write_log('Projects', 'info',
        'Export Service Desk ' . ($tec !== '' ? "($tec) " : '') . "{$f['from']}..{$f['to']}", $u_id);
    $w->download("service_desk{$suff}_{$f['from']}_{$f['to']}.xlsx");
    exit;
}

/**
 * v1.9.13 — l'etichetta dell'asse temporale, adattata alla grana.
 *
 * 'AAAA-MM'    -> 'AA-MM'   (26-06)
 * 'AAAA-MM-GG' -> 'GG/MM'   (15/06)
 *
 * Sull'asse giornaliero l'anno non serve: un grafico giornaliero copre al
 * massimo tre mesi, e ripetere '26' su ogni etichetta toglie spazio al giorno,
 * che e' l'informazione che si cerca.
 */
function etichettaAsse(string $v): string
{
    if (strlen($v) >= 10 && $v[4] === '-' && $v[7] === '-') {
        return substr($v, 8, 2) . '/' . substr($v, 5, 2);   // GG/MM
    }
    return substr($v, 2);                                    // AA-MM
}

/**
 * v1.9.14 — GRAFICO LINEARE, forma unica per l'andamento.
 *
 * L'andamento veniva disegnato in tre modi diversi: a linee nel riquadro
 * generale, a barre impilate nel report di stampa, a barre nella scheda
 * personale. Passando dalla schermata alla stampa, o dal generale al personale,
 * il grafico cambiava forma senza che i dati cambiassero natura.
 *
 * Le tre resi nascevano in momenti diversi (v1.8.84, v1.9.7, v1.8.86) e ciascuna
 * era ragionevole da sola. Il difetto e' nato dal non aver mai guardato le tre
 * insieme.
 *
 * LINEE E NON BARRE: le serie sono conteggi di ticket lungo il tempo, e la linea
 * dice che i punti appartengono alla stessa grandezza osservata in istanti
 * diversi. Le barre impilate dicevano un'altra cosa — che le serie si sommano in
 * un totale — vera per i ticket ma non per il tasso di escalation, che e' una
 * percentuale e nella pila non ha senso.
 *
 * `$serie` e' un elenco di [chiave, colore, etichetta, tratteggio].
 */
$svgLinee = function (array $dati, array $serie, string $chiaveX,
                      int $w = 700, int $h = 150, bool $perGiorno = false) {
    if (count($dati) < 2) return '';

    $mx = 0.01;
    foreach ($dati as $d)
        foreach ($serie as [$k, , , ]) $mx = max($mx, (float)($d[$k] ?? 0));

    $pL = 40; $pR = 10; $pT = 8; $pB = 20;
    $pw = $w - $pL - $pR; $ph = $h - $pT - $pB;
    $n  = max(1, count($dati) - 1);
    $xAt = fn($i) => $pL + ($i / $n) * $pw;
    $yAt = fn($v) => $pT + $ph - ($v / $mx) * $ph;

    $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto">';
    for ($g = 0; $g <= 2; $g++) {
        $y = $pT + $ph - $g * $ph / 2;
        $o .= '<line x1="' . $pL . '" y1="' . round($y, 1) . '" x2="' . ($w - $pR)
            . '" y2="' . round($y, 1) . '" stroke="#e2e8f0"/>'
            . '<text x="' . ($pL - 4) . '" y="' . round($y + 3, 1)
            . '" text-anchor="end" font-size="7" fill="#94a3b8">'
            . number_format(round($mx * $g / 2), 0, ',', '.') . '</text>';
    }

    foreach ($serie as [$k, $col, , $dash]) {
        $pts = [];
        foreach ($dati as $i => $d)
            $pts[] = round($xAt($i), 1) . ',' . round($yAt((float)($d[$k] ?? 0)), 1);
        $o .= '<polyline fill="none" stroke="' . $col . '" stroke-width="2"'
            . ($dash ? ' stroke-dasharray="4 3"' : '')
            . ' points="' . implode(' ', $pts) . '"/>';
    }

    // i marcatori si rarefanno con la densita': su 92 punti da 2,5 di raggio la
    // linea sparirebbe sotto i cerchi
    $r = count($dati) > 40 ? 1.4 : 2.5;
    foreach ($serie as [$k, $col, , ])
        foreach ($dati as $i => $d)
            $o .= '<circle cx="' . round($xAt($i), 1) . '" cy="'
                . round($yAt((float)($d[$k] ?? 0)), 1) . '" r="' . $r . '" fill="' . $col . '"/>';

    $ogni = max(1, intdiv(count($dati), $perGiorno ? 12 : 8));
    foreach ($dati as $i => $d) {
        if ($i % $ogni !== 0) continue;
        $o .= '<text x="' . round($xAt($i), 1) . '" y="' . ($h - 5)
            . '" text-anchor="middle" font-size="7" fill="#64748b">'
            . htmlspecialchars(etichettaAsse((string)$d[$chiaveX])) . '</text>';
    }
    return $o . '</svg>';
};

/**
 * v1.9.7 — barre impilate in SVG per i report di stampa.
 *
 * Gli SVG si stampano come vettori: escono a colori anche senza l'opzione
 * "Grafica di sfondo" del browser, che invece serve per le aree piene.
 *
 * Definita qui e non nel blocco di stampa perche' la usano entrambi i report,
 * generale e personale.
 */
$svgBarre = function (array $dati, array $serie, array $colori, string $chiaveX,
                      int $w = 700, int $h = 130) {
    if (count($dati) < 2) return '';
    $mx = 0.01;
    foreach ($dati as $d) {
        $t = 0; foreach ($serie as $k) $t += (float)($d[$k] ?? 0);
        $mx = max($mx, $t);
    }
    $pL = 42; $pR = 8; $pT = 6; $pB = 18;
    $pw = $w - $pL - $pR; $ph = $h - $pT - $pB;
    $nb = max(1, count($dati)); $bw = $pw / $nb;
    $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto">';
    for ($g = 0; $g <= 2; $g++) {
        $y = $pT + $ph - $g * $ph / 2;
        $o .= '<line x1="' . $pL . '" y1="' . round($y, 1) . '" x2="' . ($w - $pR)
            . '" y2="' . round($y, 1) . '" stroke="#e2e8f0"/>'
            . '<text x="' . ($pL - 3) . '" y="' . round($y + 3, 1)
            . '" text-anchor="end" font-size="7" fill="#94a3b8">'
            . number_format(round($mx * $g / 2), 0, ',', '.') . '</text>';
    }
    foreach ($dati as $i => $d) {
        $x0 = $pL + $i * $bw + $bw * 0.15; $bx = max(1.2, $bw * 0.7);
        $base = $pT + $ph;
        foreach ($serie as $k) {
            $hv = (float)($d[$k] ?? 0) / $mx * $ph;
            if ($hv <= 0) continue;
            $base -= $hv;
            $o .= '<rect x="' . round($x0, 1) . '" y="' . round($base, 1) . '" width="'
                . round($bx, 1) . '" height="' . round($hv, 1) . '" fill="'
                . ($colori[$k] ?? '#94a3b8') . '"/>';
        }
        if ($i % max(1, intdiv($nb, 10)) === 0) {
            $o .= '<text x="' . round($x0 + $bx / 2, 1) . '" y="' . ($h - 5)
                . '" text-anchor="middle" font-size="7" fill="#64748b">'
                . htmlspecialchars(etichettaAsse((string)$d[$chiaveX])) . '</text>';
        }
    }
    return $o . '</svg>';
};

// ── v1.8.88 — report stampabile ─────────────────────────────────────────────
//
// Pagina autonoma, senza menu ne' barre: si apre in una scheda nuova e si stampa.
// Non usa header.php perche' l'intestazione del portale in un documento
// consegnato a terzi e' rumore, e i menu laterali sprecano meta' foglio.
//
// Con un tecnico selezionato produce il report PERSONALE, altrimenti quello
// GENERALE con tutti i componenti.
if ($pronto && ($_GET['print'] ?? '') === '1') {
    $isPers = $tec !== '' && $sch;
    $rep    = $isPers ? [$sch] : $confronto;

    write_log('Projects', 'info',
        'Report Service Desk ' . ($isPers ? "personale ($tec)" : 'generale')
        . " {$f['from']}..{$f['to']}", $u_id);

    $nn  = fn($v) => number_format((float)$v, 0, ',', '.');
    $nn1 = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="it"><head><meta charset="utf-8">
<title>Report Service Desk<?= $isPers ? ' — ' . h($tec) : '' ?></title>
<style>
  /* Il foglio e' l'unita' di misura: margini in millimetri, corpo in punti.
     I colori di sfondo vengono stampati solo con print-color-adjust, che i
     browser disattivano per impostazione predefinita. */
  @page { size: A4; margin: 14mm 12mm; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; color: #1e293b;
         font-size: 10pt; line-height: 1.4; margin: 0; }
  h1 { font-size: 16pt; margin: 0 0 2mm; }
  h2 { font-size: 11pt; margin: 6mm 0 2mm; padding-bottom: 1mm;
       border-bottom: 1px solid #cbd5e1; }
  .meta { color: #64748b; font-size: 9pt; margin-bottom: 4mm; }
  table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 3mm; }
  th { background: #f1f5f9; text-align: left; padding: 1.5mm 2mm; font-size: 8pt;
       text-transform: uppercase; border-bottom: 1px solid #94a3b8; }
  td { padding: 1.5mm 2mm; border-bottom: 1px solid #e2e8f0; }
  .r { text-align: right; }
  .kpi { display: flex; gap: 3mm; margin-bottom: 4mm; }
  .kpi > div { flex: 1; border: 1px solid #cbd5e1; border-radius: 2mm;
               padding: 2.5mm; text-align: center; }
  .kpi .v { font-size: 15pt; font-weight: 800; }
  .kpi .l { font-size: 7.5pt; text-transform: uppercase; color: #475569; font-weight: 700; }
  .kpi .s { font-size: 7pt; color: #94a3b8; }
  .nota { font-size: 8pt; color: #64748b; margin: 2mm 0 4mm; }
  .barra { display: flex; height: 5mm; border-radius: 1mm; overflow: hidden; margin-bottom: 2mm; }
  /* evita che una sezione si spezzi fra due pagine lasciando il titolo orfano */
  .blocco { page-break-inside: avoid; }
  .pieno { page-break-before: always; }
  @media print { .nostampa { display: none; } }
  .nostampa { position: fixed; top: 8px; right: 8px; }
  .nostampa button { padding: 6px 14px; font-size: 12px; cursor: pointer;
                     border: 1px solid #2563eb; background: #2563eb; color: #fff; border-radius: 4px; }
</style></head><body>
<div class="nostampa"><button onclick="window.print()">Stampa</button></div>

<h1><?= $isPers ? 'Report personale — ' . h($tec) : 'Report Service Desk' ?></h1>
<div class="meta">
  Periodo <?=date('d/m/Y', strtotime($f['from']))?> – <?=date('d/m/Y', strtotime($f['to']))?>
  <?php if ($f['queue'] !== ''): ?> · coda <?=h($f['queue'])?><?php endif; ?>
  · generato il <?=date('d/m/Y H:i')?>
  <?php if ($isPers && $sch['sotto_unita']): ?> · <?=h($sch['sotto_unita'])?><?php endif; ?>
</div>

<div class="blocco">
  <h2>Quadro del periodo</h2>
  <div class="kpi">
    <div><div class="v"><?=$nn($h['ticket'])?></div><div class="l">Ticket</div>
      <div class="s"><?=$nn($h['chiusi'])?> chiusi</div></div>
    <div><div class="v"><?=$nn($h['presi_in_carico'])?></div><div class="l">Presi in carico</div>
      <div class="s"><?=$nn($h['risolti_l1'])?> risolti</div></div>
    <div><div class="v"><?=$h['tasso_escalation']===null?'—':$nn1($h['tasso_escalation']).'%'?></div>
      <div class="l">Escalation</div><div class="s"><?=$nn($h['escalation'])?> ticket</div></div>
    <div><div class="v"><?=$nn($h['scoperti'])?></div><div class="l">Da presidiare</div>
      <div class="s"><?= (int)$h['scoperti'] > 0 ? 'richiedono intervento' : 'nessuno' ?></div></div>
  </div>
  <p class="nota">Il tasso di escalation è calcolato sui soli ticket presi in carico
    (<?=$nn($h['presi_in_carico'])?>), non sul totale: i ticket nati su code specialistiche non sono
    mai passati dal primo livello e non possono essere stati scalati.</p>
</div>

<div class="blocco">
  <h2>Come sono stati gestiti</h2>
  <?php $tt = max(1, (int)$h['ticket']); ?>
  <div class="barra">
    <?php foreach ($brk as $b): $q = 100*(int)$b['ticket']/$tt; if ($q < 0.4) continue; ?>
      <div style="width:<?=round($q,2)?>%;background:<?=$colClasse[$b['gestione']] ?? '#94a3b8'?>"></div>
    <?php endforeach; ?>
  </div>
  <table>
    <thead><tr><th>Classe</th><th class="r">Ticket</th><th class="r">Quota</th>
      <th class="r">Chiusi</th><th class="r">Durata media</th></tr></thead>
    <tbody>
    <?php foreach ($brk as $b): ?>
      <tr><td><?=h($b['gestione'])?></td>
        <td class="r"><?=$nn($b['ticket'])?></td>
        <td class="r"><?=$nn1(100*(int)$b['ticket']/$tt)?>%</td>
        <td class="r"><?=$nn($b['chiusi'])?></td>
        <td class="r"><?=$b['durata_media']!==null?$nn1($b['durata_media']).' h':'—'?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php // v1.9.7 — l'andamento, come nella schermata. Il report riprende le stesse
      // voci del pannello: chi stampa deve ritrovare quello che ha guardato. ?>
<?php if (count($trend) > 1): ?>
  <div class="blocco">
    <h2>Andamento — <?=count($trend)?>
      <?= (($trend[0]['grana'] ?? 'mese') === 'giorno') ? 'giorni' : 'mesi' ?></h2>
    <?php // v1.9.14 — lineare, come nel riquadro a video. Le barre impilate
          // dicevano che le serie si sommano in un totale: vero per i ticket,
          // falso per il tasso di escalation, che è una percentuale. ?>
    <?= $svgLinee($trend, [
          ['risolti_l1', '#16a34a', 'risolti dal Service Desk', false],
          ['escalation', '#f59e0b', 'escalation', false],
          ['diretti',    '#2563eb', 'presa diretta da specialisti', false],
          ['mai_presi',  '#dc2626', 'mai presi in carico', false],
        ], 'ym', 700, 150, (($trend[0]['grana'] ?? 'mese') === 'giorno')) ?>
    <p class="nota">
      <?php // v1.9.14 — trattini e non quadretti: il grafico è a linee ?>
      <span style="display:inline-block;width:12px;height:2px;background:#16a34a;vertical-align:middle"></span> risolti dal Service Desk
      <span style="display:inline-block;width:12px;height:2px;background:#f59e0b;margin-left:8px;vertical-align:middle"></span> escalation
      <span style="display:inline-block;width:12px;height:2px;background:#2563eb;margin-left:8px;vertical-align:middle"></span> presa diretta da specialisti
      <span style="display:inline-block;width:12px;height:2px;background:#dc2626;margin-left:8px;vertical-align:middle"></span> mai presi in carico
    </p>
  </div>
<?php endif; ?>

<?php if ($isPers): ?>
  <div class="blocco">
    <h2>Esito dei ticket presi in carico</h2>
    <table>
      <thead><tr><th class="r">Presi</th><th class="r">Risolti</th><th class="r">Scalati</th>
        <th class="r">Escalation</th><th class="r">1ª risposta</th></tr></thead>
      <tbody><tr>
        <td class="r"><?=$nn($sch['presi_in_carico'])?></td>
        <td class="r"><?=$nn($sch['risolti'])?></td>
        <td class="r"><?=$nn($sch['scalati'])?></td>
        <td class="r"><?=$nn1($sch['tasso_escalation_pct'])?>%</td>
        <td class="r"><?=$nn1($sch['ore_prima_risposta'])?> h</td>
      </tr></tbody>
    </table>
    <p class="nota">Il tempo di prima risposta va letto insieme al volume: chi prende in carico più
      ticket ha naturalmente tempi più alti.</p>

    <h2>Attività complessiva</h2>
    <table>
      <thead><tr><th class="r">Messaggi</th><th class="r">Risposte</th><th class="r">Note interne</th>
        <th class="r">Ticket toccati</th><th class="r">Code</th><th class="r">Giorni attivi</th></tr></thead>
      <tbody><tr>
        <td class="r"><?=$nn($sch['messaggi'])?></td><td class="r"><?=$nn($sch['risposte'])?></td>
        <td class="r"><?=$nn($sch['note'])?></td><td class="r"><?=$nn($sch['ticket_toccati'])?></td>
        <td class="r"><?=$nn($sch['code'])?></td><td class="r"><?=$nn($sch['giorni_attivi'])?></td>
      </tr></tbody>
    </table>
    <p class="nota">L'attività comprende ogni messaggio, anche su ticket presi in carico da altri:
      a differenza dell'esito, non è attribuibile a questa persona.</p>
  </div>

  <?php $mr = $sd->moduliRiepilogo($tec, $f); $mc = $sd->moduliContratto($tec, $f); ?>
  <?php if (($mr['moduli'] ?? 0) > 0): ?>
    <div class="blocco">
      <h2>Moduli di intervento per tipologia di contratto</h2>
      <div class="kpi">
        <div><div class="v"><?=$nn($mr['moduli'])?></div><div class="l">Moduli</div>
          <div class="s"><?=$nn($mr['commesse'])?> commesse</div></div>
        <div><div class="v"><?=$nn1($mr['ore'])?> h</div><div class="l">Ore</div>
          <div class="s"><?=$nn($mr['modelli'])?> tipologie</div></div>
        <div><div class="v"><?=$nn1($mr['ore_ricavo'])?> h</div><div class="l">A ricavo</div>
          <div class="s"><?=$nn1($mr['pct_ricavo'])?>%</div></div>
        <div><div class="v"><?=$nn1($mr['ore_interne'])?> h</div><div class="l">Interne</div>
          <div class="s">senza ricavo</div></div>
      </div>
      <table>
        <thead><tr><th>Codice</th><th>Tipologia</th><th>Modello</th><th class="r">Moduli</th>
          <th class="r">Ore</th><th class="r">Commesse</th><th>Natura</th></tr></thead>
        <tbody>
        <?php foreach ($mc as $c): ?>
          <tr><td style="font-family:monospace"><?=h($c['codice'] ?? '')?></td>
            <td><?=h($c['contratto'])?></td>
            <td><?=h($c['modello'])?></td>
            <td class="r"><?=$nn($c['moduli'])?></td>
            <td class="r"><?=$nn1($c['ore'])?> h</td>
            <td class="r"><?=$nn($c['commesse'])?></td>
            <td><?=$c['ha_ricavo'] ? 'a ricavo' : 'interna'?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nota">Ticket e moduli non si sommano: un ticket può generare un modulo di intervento.</p>
    </div>
  <?php endif; ?>

  <?php // v1.9.5 — analisi del Team nel report di stampa ?>
  <?php if ($tDett): ?>
    <div class="blocco">
      <h2>Analisi del Team</h2>
      <div class="kpi">
        <?php foreach ([
          ['Moduli', $nn($tQuadro['moduli'] ?? 0), '#0891b2'],
          ['Ore', $nn1($tQuadro['ore'] ?? 0), '#2563eb'],
          ['A ricavo', $nn1($tQuadro['ore_ricavo'] ?? 0), '#16a34a'],
          ['Fuori orario', $nn1($tQuadro['ore_fuori'] ?? 0), '#f59e0b'],
          ['Giornate-uomo', $nn($tQuadro['giornate_uomo'] ?? 0), '#7c3aed'],
        ] as [$lb, $vl, $cl]): ?>
          <div style="border-top-color:<?=$cl?>">
            <div class="v" style="color:<?=$cl?>"><?=$vl?></div>
            <div class="l"><?=h($lb)?></div></div>
        <?php endforeach; ?>
      </div>
      <table>
        <thead><tr><th>Componente</th><th>Sotto-unità</th><th class="r">Ticket</th>
          <th class="r">Moduli</th><th class="r">Ore</th><th class="r">In orario</th>
          <th class="r">Fuori</th><th class="r">% fuori</th><th class="r">Giornate</th></tr></thead>
        <tbody>
        <?php foreach ($tDett as $d): ?>
          <tr><td><?=h($d['tecnico'])?></td><td><?=h($d['sotto_unita'] ?? '—')?></td>
            <td class="r"><?=$nn($d['ticket_presi'])?></td>
            <td class="r"><?=$nn($d['moduli'])?></td>
            <td class="r" style="font-weight:700"><?=$nn1($d['ore'])?></td>
            <td class="r"><?=$nn1($d['ore_in_orario'])?></td>
            <td class="r"><?=$nn1($d['ore_fuori_orario'])?></td>
            <td class="r"><?=$nn1($d['pct_fuori'])?>%</td>
            <td class="r"><?=$nn($d['giornate'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nota">Ticket e moduli sono attività distinte e non si sommano: un ticket può
        generare un modulo, e la sovrapposizione non è quantificabile.</p>
    </div>

    <?php if ($tContr): ?>
      <div class="blocco">
        <h2>Interventi e ore per tipologia di contratto</h2>
        <table>
          <thead><tr><th>Codice</th><th>Contratto</th><th class="r">Interventi</th>
            <th class="r">Ore</th><th class="r">In orario</th><th class="r">Fuori orario</th>
            <th class="r">Tecnici</th><th>Natura</th></tr></thead>
          <tbody>
          <?php foreach ($tContr as $x): ?>
            <tr><td><?=h($x['codice_linea'])?></td>
              <td><?=h(mb_strimwidth((string)$x['contratto'], 0, 30, '…'))?></td>
              <td class="r"><?=$nn($x['interventi'])?></td>
              <td class="r" style="font-weight:700"><?=$nn1($x['ore'])?></td>
              <td class="r"><?=$nn1($x['ore_in'])?></td>
              <td class="r"><?=$nn1($x['ore_fuori'])?></td>
              <td class="r"><?=$nn($x['tecnici'])?></td>
              <td><?=$x['ha_ricavo'] ? 'a ricavo' : 'interna'?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="nota">Fasce 09–13 e 14–18 nei feriali. Sabato e domenica sono fuori orario
          <strong>per costruzione</strong>, prima ancora di guardare l'ora.</p>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php $cd = $sd->codeDettaglio($tec, $f); if ($cd): ?>
    <div class="blocco">
      <h2>Code seguite</h2>
      <table>
        <thead><tr><th>Coda</th><th class="r">Ticket</th><th class="r">Presi in carico</th>
          <th class="r">Totale coda</th><th class="r">Quota</th></tr></thead>
        <tbody>
        <?php foreach ($cd as $c): ?>
          <tr><td><?=h($c['coda'])?></td>
            <td class="r"><?=$nn($c['ticket'])?></td>
            <td class="r"><?=$nn($c['presi_in_carico'])?></td>
            <td class="r"><?=$nn($c['ticket_coda'])?></td>
            <td class="r"><?=$nn1($c['quota_coda_pct'])?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nota">La quota distingue il presidio dal transito: la stessa quantità di ticket ha
        significati opposti su una coda grande o piccola.</p>
    </div>
  <?php endif; ?>

<?php else: ?>
  <div class="blocco">
    <h2>I componenti del Service Desk</h2>
    <table>
      <thead><tr><th>Componente</th><th>Sotto-unità</th><th class="r">Presi</th>
        <th class="r">Risolti</th><th class="r">Scalati</th><th class="r">Escalation</th>
        <th class="r">1ª risposta</th><th class="r">Moduli</th><th class="r">Ore</th>
        <th class="r">A ricavo</th></tr></thead>
      <tbody>
      <?php foreach ($rep as $c): ?>
        <tr><td><?=h($c['tecnico'])?></td>
          <td><?=h($c['sotto_unita'] ?? '—')?></td>
          <td class="r"><?=$nn($c['presi_in_carico'])?></td>
          <td class="r"><?=$nn($c['risolti'])?></td>
          <td class="r"><?=$nn($c['scalati'])?></td>
          <td class="r"><?=$nn1($c['tasso_escalation_pct'])?>%</td>
          <td class="r"><?=$nn1($c['ore_prima_risposta'])?> h</td>
          <td class="r"><?=$nn($c['moduli_intervento'] ?? 0)?></td>
          <td class="r"><?=$nn1($c['ore_moduli'] ?? 0)?> h</td>
          <td class="r"><?=$nn1($c['pct_a_ricavo'])?>%</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="nota">Valori del <strong>periodo selezionato</strong>. <strong>Ticket e moduli non si sommano</strong>: chi ha
      meno ticket può avere più ore consuntivate.</p>
  </div>

  <?php // v1.9.6 — riepilogo delle attivita' del PERIODO.
        //
        // La tabella sopra e' sull'intero archivio: dice chi sono i componenti e
        // quanto pesano storicamente. Un report di periodo senza il riepilogo del
        // periodo costringe a confrontare a mente due grandezze diverse. ?>
  <?php if ($tDett): ?>
    <div class="blocco">
      <h2>Riepilogo attività del periodo
        <span style="font-weight:400;font-size:9pt;color:#64748b">
          <?=date('d/m/Y', strtotime($f['from']))?> – <?=date('d/m/Y', strtotime($f['to']))?></span></h2>

      <div class="kpi">
        <?php foreach ([
          ['Ticket presi', $nn(array_sum(array_column($tDett, 'ticket_presi'))), '#2563eb'],
          ['Moduli', $nn($tQuadro['moduli'] ?? 0), '#0891b2'],
          ['Ore', $nn1($tQuadro['ore'] ?? 0), '#16a34a'],
          ['Fuori orario', $nn1($tQuadro['ore_fuori'] ?? 0), '#f59e0b'],
          ['Giornate-uomo', $nn($tQuadro['giornate_uomo'] ?? 0), '#7c3aed'],
        ] as [$lb2, $vl2, $cl2]): ?>
          <div style="border-top-color:<?=$cl2?>">
            <div class="v" style="color:<?=$cl2?>"><?=$vl2?></div>
            <div class="l"><?=h($lb2)?></div></div>
        <?php endforeach; ?>
      </div>

      <table>
        <thead>
          <tr style="font-size:6.5pt">
            <th colspan="2"></th>
            <th style="text-align:center">TICKET</th>
            <th colspan="6" style="text-align:center">MODULI DI INTERVENTO</th>
          </tr>
          <tr><th>Componente</th><th>Sotto-unità</th><th class="r">Presi</th>
            <th class="r">Moduli</th><th class="r">Ore</th><th class="r">In orario</th>
            <th class="r">Fuori</th><th class="r">% fuori</th><th class="r">Giornate</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tDett as $d): ?>
          <tr><td><?=h($d['tecnico'])?></td>
            <td><?=h($d['sotto_unita'] ?? '—')?></td>
            <td class="r"><?=$nn($d['ticket_presi'])?></td>
            <td class="r"><?=$nn($d['moduli'])?></td>
            <td class="r" style="font-weight:700"><?=$nn1($d['ore'])?></td>
            <td class="r"><?=$nn1($d['ore_in_orario'])?></td>
            <td class="r"><?=$nn1($d['ore_fuori_orario'])?></td>
            <td class="r"><?=$nn1($d['pct_fuori'])?>%</td>
            <td class="r"><?=$nn($d['giornate'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nota">Riferito al <strong>periodo selezionato</strong>, come la tabella
        precedente. Un componente con zero righe qui non ha lavorato nel
        periodo, non è uscito dalla squadra.</p>
    </div>
  <?php endif; ?>

  <?php if ($tContr): ?>
    <div class="blocco">
      <h2>Attività del team per tipologia di contratto</h2>
      <table>
        <thead><tr><th>Codice</th><th>Contratto</th><th class="r">Interventi</th>
          <th class="r">Ore</th><th class="r">In orario</th><th class="r">Fuori orario</th>
          <th class="r">Tecnici</th><th>Natura</th></tr></thead>
        <tbody>
        <?php foreach ($tContr as $x2): ?>
          <tr><td><?=h($x2['codice_linea'])?></td>
            <td><?=h(mb_strimwidth((string)$x2['contratto'], 0, 30, '…'))?></td>
            <td class="r"><?=$nn($x2['interventi'])?></td>
            <td class="r" style="font-weight:700"><?=$nn1($x2['ore'])?></td>
            <td class="r"><?=$nn1($x2['ore_in'])?></td>
            <td class="r"><?=$nn1($x2['ore_fuori'])?></td>
            <td class="r"><?=$nn($x2['tecnici'])?></td>
            <td><?=$x2['ha_ricavo'] ? 'a ricavo' : 'interna'?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nota">Fasce 09–13 e 14–18 nei feriali. Sabato e domenica sono fuori orario per
        costruzione, prima ancora di guardare l'ora.</p>
    </div>
  <?php endif; ?>

  <div class="blocco">
  </div>

  <?php if ($scop): ?>
    <div class="blocco">
      <h2>Ticket da presidiare (<?=count($scop)?>)</h2>
      <table>
        <thead><tr><th>Ticket</th><th>Oggetto</th><th>Coda</th><th class="r">Giorni</th>
          <th>Situazione</th></tr></thead>
        <tbody>
        <?php foreach ($scop as $sx): ?>
          <tr><td><?=h($sx['ticket'])?></td>
            <td><?=h(mb_strimwidth((string)$sx['oggetto'], 0, 50, '…'))?></td>
            <td><?=h($sx['coda'])?></td>
            <td class="r"><?=$nn($sx['giorni'])?></td>
            <td><?=h($sx['gestione'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php // v1.9.15 — riepilogo costi nel report di stampa, layout del template ?>
<?php // v1.9.17 — anche nel report PERSONALE. I costi filtrati sul singolo
      // tecnico rispondono a una domanda diversa dal totale di squadra: quanto
      // vale il lavoro di quella persona. Escluderli dal personale lasciava la
      // domanda senza risposta stampabile. ?>
<?php if ($cRie): ?>
  <?php $perLin2 = []; foreach ($cRie as $x) $perLin2[$x['codice_linea']][] = $x; ?>
  <div class="blocco pieno">
    <h2>Riepilogo costi per fascia e contratto<?= $isPers ? ' — ' . h($tec) : '' ?>
      <span style="font-weight:400;font-size:9pt;color:#64748b">
        <?=date('d/m/Y', strtotime($f['from']))?> – <?=date('d/m/Y', strtotime($f['to']))?></span></h2>

    <div class="kpi">
      <?php foreach ([
        ['Interventi', $nn($cQ['interventi'] ?? 0), '#065f46'],
        ['Ore', $nn1($cQ['ore'] ?? 0), '#334155'],
        ['Valore totale', $nn1($cQ['valore'] ?? 0), '#0f766e'],
        ['Orario ordinario', $nn1($cQ['valore_ordinario'] ?? 0), '#16a34a'],
        ['Extra-orario', $nn1($cQ['valore_extra'] ?? 0), '#f59e0b'],
        ['Commesse', $nn($cQ['commesse'] ?? 0), '#7c3aed'],
      ] as [$lc, $vc, $cc]): ?>
        <div style="border-top-color:<?=$cc?>">
          <div class="v" style="color:<?=$cc?>"><?=$vc?></div>
          <div class="l"><?=h($lc)?></div></div>
      <?php endforeach; ?>
    </div>

    <?php foreach ($perLin2 as $cod2 => $righe2): ?>
      <?php $so = 0; $sv = 0; foreach ($righe2 as $x) { $so += (float)$x['ore']; $sv += (float)$x['valore']; } ?>
      <h2 style="font-size:10pt;margin-top:4mm">RIEPILOGO PER TIPO CONTRATTO —
        <?=h($cod2)?> <span style="font-weight:400"><?=h($righe2[0]['contratto'])?></span></h2>
      <table>
        <thead><tr><th>Descrizione tariffa</th><th class="r">N. interventi</th>
          <th>In reperibilità</th><th class="r">Totale ore</th><th class="r">Tariffa</th>
          <th class="r">Totale valore</th></tr></thead>
        <tbody>
        <?php foreach ($righe2 as $x): ?>
          <tr><td><?=h($x['descrizione_tariffa'])?></td>
            <td class="r"><?=$nn($x['interventi'])?></td>
            <td><?=h($x['reperibilita'])?></td>
            <td class="r"><?=$nn1($x['ore'])?></td>
            <td class="r"><?=$x['tariffa_ora']!==null?$nn1($x['tariffa_ora']):'—'?></td>
            <td class="r" style="font-weight:700">
              <?=$x['valore']!==null?$nn1($x['valore']):'—'?></td></tr>
        <?php endforeach; ?>
          <tr style="font-weight:700;background:#f1f5f9">
            <td>TOTALE</td><td></td><td></td>
            <td class="r"><?=$nn1($so)?></td><td></td>
            <td class="r"><?=$nn1($sv)?></td></tr>
        </tbody>
      </table>
    <?php endforeach; ?>

    <p class="nota">Gli scaglioni dipendono dalla <strong>durata del singolo intervento</strong>:
      fino a 4 ore la tariffa oraria, oltre la mezza giornata, da 8 ore la giornata. Il valore è
      <strong>ore × tariffa</strong>, non un pacchetto forfetario. Fascia C è l'orario ordinario,
      fascia D l'extra-orario; sabato e domenica sono fascia D per costruzione.</p>
  </div>
<?php endif; ?>

<?php // v1.9.11 — OBJ_2.1 e OBJ_2.2 nel report di stampa ?>
<?php if ($o21Fat || $o22Int): ?>
  <div class="blocco pieno">
    <h2>Attività del Service Desk: fatturabile e interna
      <span style="font-weight:400;font-size:9pt;color:#64748b">
        <?=date('d/m/Y', strtotime($f['from']))?> – <?=date('d/m/Y', strtotime($f['to']))?></span></h2>

    <div class="kpi">
      <?php foreach ([
        ['Interventi', $nn($o21Q['interventi'] ?? 0), '#334155'],
        ['Ore totali', $nn1($o21Q['ore'] ?? 0), '#334155'],
        ['Ore fatturabili', $nn1($o21Q['ore_fatt'] ?? 0), '#16a34a'],
        ['Ore interne', $nn1($o21Q['ore_int'] ?? 0), '#dc2626'],
        ['Addebitato', $nn1($o21Q['valore_addebitato'] ?? 0), '#0891b2'],
        ['Quota interna', $nn1($o21Q['quota_interna_pct'] ?? 0) . '%', '#b45309'],
      ] as [$la, $va, $ca]): ?>
        <div style="border-top-color:<?=$ca?>">
          <div class="v" style="color:<?=$ca?>"><?=$va?></div>
          <div class="l"><?=h($la)?></div></div>
      <?php endforeach; ?>
    </div>

    <h2>OBJ_2.1 — Attività fatturabile per contratto</h2>
    <table>
      <thead><tr><th>Codice</th><th>Contratto</th><th class="r">Interventi</th>
        <th class="r">Ore</th><th class="r">Quota</th><th class="r">Addebitato</th>
        <th class="r">A listino</th><th class="r">Tariffa</th><th class="r">Commesse</th></tr></thead>
      <tbody>
      <?php foreach ($o21Fat as $x): ?>
        <tr><td><?=h($x['codice_linea'])?></td>
          <td><?=h(mb_strimwidth((string)$x['contratto'], 0, 26, '…'))?></td>
          <td class="r"><?=$nn($x['interventi'])?></td>
          <td class="r" style="font-weight:700"><?=$nn1($x['ore'])?></td>
          <td class="r"><?=$nn1($x['quota_ore_pct'])?>%</td>
          <td class="r"><?=$x['valore_addebitato']!==null?$nn1($x['valore_addebitato']):'—'?></td>
          <td class="r"><?=$x['valore_listino']!==null?$nn1($x['valore_listino']):'—'?></td>
          <td class="r"><?=$x['tariffa_ora']!==null?$nn1($x['tariffa_ora']):'—'?></td>
          <td class="r"><?=$nn($x['commesse'])?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($o22Int): ?>
      <h2>OBJ_2.2 — Attività interna, non retribuita</h2>
      <table>
        <thead><tr><th>Codice</th><th>Contratto</th><th class="r">Interventi</th>
          <th class="r">Ore</th><th class="r">A listino</th><th class="r">Tariffa</th>
          <th class="r">Commesse</th></tr></thead>
        <tbody>
        <?php foreach ($o22Int as $x): ?>
          <tr><td><?=h($x['codice_linea'])?></td>
            <td><?=h(mb_strimwidth((string)$x['contratto'], 0, 26, '…'))?></td>
            <td class="r"><?=$nn($x['interventi'])?></td>
            <td class="r" style="font-weight:700"><?=$nn1($x['ore'])?></td>
            <td class="r"><?=$x['valore_listino']!==null?$nn1($x['valore_listino']):'—'?></td>
            <td class="r"><?=$x['tariffa_ora']!==null?$nn1($x['tariffa_ora']):'—'?></td>
            <td class="r"><?=$nn($x['commesse'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="nota">Sono le commesse senza ricavo: il lavoro c'è, l'addebito no. Il valore a
        listino ne quantifica il costo opportunità.</p>
    <?php endif; ?>

    <?php if ($o23Tec): ?>
      <h2>OBJ_2.3 — Ripartizione per tecnico dell'unità</h2>
      <table>
        <thead><tr><th>Tecnico</th><th>Unità</th><th class="r">Interventi</th><th class="r">Ore</th>
          <th class="r">Fatturabili</th><th class="r">Interne</th><th class="r">% fatt.</th>
          <th class="r">Addebitato</th><th class="r">A listino</th></tr></thead>
        <tbody>
        <?php foreach ($o23Tec as $x): ?>
          <tr><td><?=h($x['tecnico'])?></td><td><?=h($x['unita'])?></td>
            <td class="r"><?=$nn($x['interventi'])?></td>
            <td class="r" style="font-weight:700"><?=$nn1($x['ore'])?></td>
            <td class="r"><?=$nn1($x['ore_fatturabili'])?></td>
            <td class="r"><?=$nn1($x['ore_interne'])?></td>
            <td class="r"><?=$x['quota_fatturabile_pct']!==null?$nn1($x['quota_fatturabile_pct']).'%':'—'?></td>
            <td class="r"><?=$nn1($x['valore_addebitato'])?></td>
            <td class="r"><?=$nn1($x['valore_listino'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <p class="nota">I dati vengono dai <strong>moduli di intervento</strong>, non dai ticket: il
      modulo porta il riferimento alla commessa, il ticket no. Il conteggio è di
      <strong>interventi</strong> e non di ticket. Fatturabile o interna dipende dalla natura della
      commessa. I tecnici sono quelli assegnati alle unità organizzative dichiarate.</p>
  </div>
<?php endif; ?>

<?php // v1.9.10 — OBJ_2 e OBJ_2.3 nel report di stampa ?>
<?php if (!$isPers && $o2Lin): ?>
  <div class="blocco pieno">
    <h2>Quadro del perimetro Service Desk</h2>
    <div class="kpi">
      <?php foreach ([
        ['Valore totale', $nn1(($o2Q['valore_totale'] ?? 0)/1000) . 'k', '#0f766e'],
        ['Valore aperte', $nn1(($o2Q['valore_aperte'] ?? 0)/1000) . 'k', '#2563eb'],
        ['Margine', $nn1(($o2Q['margine'] ?? 0)/1000) . 'k', '#16a34a'],
        ['Margine %', $nn1($o2Q['margine_pct'] ?? 0) . '%', '#16a34a'],
        ['Commesse', $nn($o2Q['commesse'] ?? 0), '#334155'],
        ['Clienti', $nn($o2Q['clienti'] ?? 0), '#7c3aed'],
      ] as [$lo, $vo, $co]): ?>
        <div style="border-top-color:<?=$co?>">
          <div class="v" style="color:<?=$co?>"><?=$vo?></div>
          <div class="l"><?=h($lo)?></div></div>
      <?php endforeach; ?>
    </div>

    <table>
      <thead><tr><th>Codice</th><th>Contratto</th><th class="r">Commesse</th>
        <th class="r">Aperte</th><th class="r">Valore</th><th class="r">Quota</th>
        <th class="r">Costi</th><th class="r">Margine</th><th class="r">Marg. %</th>
        <th class="r">Clienti</th><th>Natura</th></tr></thead>
      <tbody>
      <?php foreach ($o2Lin as $x): ?>
        <tr><td><?=h($x['codice_linea'])?></td>
          <td><?=h(mb_strimwidth((string)$x['contratto'], 0, 26, '…'))?></td>
          <td class="r"><?=$nn($x['commesse'])?></td>
          <td class="r"><?=$nn($x['aperte'])?></td>
          <td class="r" style="font-weight:700"><?=$nn1($x['valore'])?></td>
          <td class="r"><?=$nn1($x['quota_valore_pct'])?>%</td>
          <td class="r"><?=$nn1($x['costi'])?></td>
          <td class="r" style="font-weight:700"><?=$nn1($x['margine'])?></td>
          <td class="r"><?=$nn1($x['margine_pct'])?>%</td>
          <td class="r"><?=$nn($x['clienti'])?></td>
          <td><?=$x['ha_ricavo'] ? 'a ricavo' : 'interna'?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p class="nota"><strong>Addetti</strong>: <?=$nn($o2Q['addetti_distinti'] ?? 0)?> persone
      distinte, <?=$nn1($o2Q['addetti_medi_mese'] ?? 0)?> di media mensile,
      <?=$nn1($o2Q['fte_equivalenti'] ?? 0)?> equivalenti a tempo pieno. Tre misure diverse: la
      prima sovrastima chi ha fatto un intervento solo, la terza dice quanto lavoro c'è stato.</p>

    <?php if ((int)($o2Q['ticket'] ?? 0) > 0): ?>
      <h2>Ticket gestiti ed escalati</h2>
      <div class="kpi">
        <?php foreach ([
          ['Ticket', $nn($o2Q['ticket']), '#334155'],
          ['Presi in carico', $nn($o2Q['ticket_presi']), '#2563eb'],
          ['Risolti dal SD', $nn($o2Q['ticket_risolti']), '#16a34a'],
          ['Escalati', $nn($o2Q['ticket_scalati']), '#f59e0b'],
          ['Escalation %', $nn1($o2Q['escalation_pct']) . '%', '#dc2626'],
        ] as [$lt, $vt, $ct]): ?>
          <div style="border-top-color:<?=$ct?>">
            <div class="v" style="color:<?=$ct?>"><?=$vt?></div>
            <div class="l"><?=h($lt)?></div></div>
        <?php endforeach; ?>
      </div>
      <p class="nota">Il tasso di escalation è calcolato sui soli ticket <strong>presi in
        carico</strong>: un ticket mai preso non è stato né risolto né scalato, e includerlo al
        denominatore abbasserebbe il tasso per una ragione che non riguarda la capacità del primo
        livello.</p>
    <?php endif; ?>

    <?php if ($o23Rip): ?>
      <h2>Ripartizione dei ticket per classe di gestione</h2>
      <table>
        <thead><tr><th>Classe di gestione</th><th class="r">Ticket</th><th class="r">Quota</th>
          <th class="r">Code</th><th class="r">Msg medi</th><th class="r">Durata media</th></tr></thead>
        <tbody>
        <?php foreach ($o23Rip as $x): ?>
          <tr><td><?=h($x['gestione'])?></td>
            <td class="r" style="font-weight:700"><?=$nn($x['ticket'])?></td>
            <td class="r"><?=$nn1($x['quota_pct'])?>%</td>
            <td class="r"><?=$nn($x['code'])?></td>
            <td class="r"><?=$nn1($x['messaggi_medi'])?></td>
            <td class="r"><?=$nn1($x['durata_media_ore'])?> h</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <p class="nota"><strong>Perimetro</strong>:
      <?=h(implode(', ', array_column($o2Lin, 'codice_linea')))?> — parametro modificabile.
      Il margine viene dal gestionale, non ricostruito.
      <strong>La ripartizione fra ticket fatturabili e interni non è disponibile</strong>: il
      ticket non porta un riferimento alla commessa.</p>
  </div>
<?php endif; ?>

<?php // v1.9.7 — assenze: ferie, permessi, recuperi, malattia.
      //
      // In coda a ENTRAMBI i report: nel personale riguarda la persona, nel
      // generale tutta la squadra. La query e' la stessa, filtrata o no. ?>
<?php if ($assT): ?>
  <?php $cA = ['ferie'=>'#7c3aed','permessi'=>'#0891b2','recuperi'=>'#f59e0b',
               'malattia'=>'#dc2626','visite'=>'#db2777']; ?>
  <div class="blocco">
    <h2>Assenze<?= $isPers ? '' : ' del team' ?>
      <span style="font-weight:400;font-size:9pt;color:#64748b">
        <?=date('d/m/Y', strtotime($f['from']))?> – <?=date('d/m/Y', strtotime($f['to']))?></span></h2>

    <div class="kpi">
      <?php foreach ([
        ['Totale', $nn1($assQ['totale'] ?? 0) . ' h', '#334155'],
        ['Ferie', $nn1($assQ['ferie'] ?? 0) . ' h', $cA['ferie']],
        ['Permessi', $nn1($assQ['permessi'] ?? 0) . ' h', $cA['permessi']],
        ['Recupero ore', $nn1($assQ['recuperi'] ?? 0) . ' h', $cA['recuperi']],
        ['Malattia', $nn1($assQ['malattia'] ?? 0) . ' h', $cA['malattia']],
        ['Giornate', $nn1($assQ['giornate'] ?? 0), '#7c3aed'],
      ] as [$lA, $vA, $cc]): ?>
        <div style="border-top-color:<?=$cc?>">
          <div class="v" style="color:<?=$cc?>"><?=$vA?></div>
          <div class="l"><?=h($lA)?></div></div>
      <?php endforeach; ?>
    </div>

    <?php if (!$isPers && count($assT) > 1): ?>
      <table>
        <thead><tr><th>Componente</th><th class="r">Ferie</th><th class="r">Permessi</th>
          <th class="r">Recuperi</th><th class="r">Malattia</th><th class="r">Altre</th>
          <th class="r">Visite</th><th class="r">Totale</th><th class="r">Giornate</th></tr></thead>
        <tbody>
        <?php foreach ($assT as $xa): ?>
          <tr><td><?=h($xa['tecnico'])?></td>
            <td class="r"><?=$nn1($xa['ferie'])?></td>
            <td class="r"><?=$nn1($xa['permessi'])?></td>
            <td class="r"><?=$nn1($xa['recuperi'])?></td>
            <td class="r"><?=$nn1($xa['malattia'])?></td>
            <td class="r"><?=$nn1($xa['altre'])?></td>
            <td class="r"><?=$nn1($xa['visite'])?></td>
            <td class="r" style="font-weight:700"><?=$nn1($xa['totale'])?> h</td>
            <td class="r"><?=$nn1($xa['giornate'])?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if (count($assM) > 1): ?>
      <?= $svgBarre($assM, ['ferie','permessi','recuperi','malattia'], $cA, 'ym', 700, 120) ?>
      <p class="nota">
        <span style="display:inline-block;width:9px;height:6px;background:<?=$cA['ferie']?>"></span> ferie
        <span style="display:inline-block;width:9px;height:6px;background:<?=$cA['permessi']?>;margin-left:8px"></span> permessi
        <span style="display:inline-block;width:9px;height:6px;background:<?=$cA['recuperi']?>;margin-left:8px"></span> recupero ore
        <span style="display:inline-block;width:9px;height:6px;background:<?=$cA['malattia']?>;margin-left:8px"></span> malattia
      </p>
    <?php endif; ?>

    <p class="nota">Le <strong>visite</strong> sono contate a parte e non entrano nel totale: le
      loro ore sono già comprese nelle altre voci, perché nel gestionale non esiste un tipo dedicato.
      Le giornate sono calcolate su 8 ore.</p>
  </div>
<?php endif; ?>

<p class="nota" style="margin-top:6mm;border-top:1px solid #cbd5e1;padding-top:2mm">
  PortalManager <?=h(PM_VERSION)?> — Il primo livello è l'unità <strong>Service Desk</strong>
  definita in Unità Organizzative Tecniche. La durata dei ticket comprende le attese del cliente
  e non è un tempo di risoluzione. Nessun SLA è definito: i tempi indicati sono osservati.
</p>
</body></html><?php
    exit;
}

require_once('header.php');

$qs = function (array $over = []) use ($f, $tec) {
    $p = array_filter(['from' => $f['from'], 'to' => $f['to'], 'queue' => $f['queue'],
                       'level' => $f['level'], 'gest' => $f['gest'],
                       'tec' => $tec], fn($v) => $v !== '');
    return url_safe('service_desk', array_merge($p, $over));
};
$n  = fn($v) => number_format((float)$v, 0, ',', '.');
$n1 = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');

// colore per classe di gestione: le tre "senza risposta" hanno tinte distinte
// perche' hanno significati diversi — lavoro svolto, qualita' percepita, scoperto
$colClasse = [
    'risolto dal Service Desk'                   => '#16a34a',
    'escalation di 2 livello verso specialisti'  => '#f59e0b',
    'presa in carico diretta da specialisti'     => '#2563eb',
    'lavorato senza risposta scritta'            => '#94a3b8',
    'cliente senza risposta scritta'             => '#d97706',
    'mai preso in carico'                        => '#dc2626',
];
?>

<div style="margin-bottom:16px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-headset"></i> Service Desk</h1>
  <p style="color:var(--muted);font-size:12px;margin-top:2px">
    Rendicontazione dell'operatività. Il primo livello è l'unità <strong>Service Desk</strong>
    definita in Unità Organizzative Tecniche<?php if ($team): ?> —
    <?=h(implode(', ', array_column($team, 'nome')))?><?php endif; ?>.
  </p>
</div>

<?php if (!$pronto): ?>
  <div class="alert alert-warning">
    <strong>Dati del Service Desk non disponibili.</strong>
    Eseguire la migration v1.8.82 e sincronizzare il dataset
    <em>Service Desk — messaggi ticket</em>.
    <div style="font-size:11px;color:var(--muted);margin-top:4px"><?=h($errore)?></div>
  </div>
  <?php require_once('footer.php'); exit; ?>
<?php endif; ?>

<?php if (!$team): ?>
  <div class="alert alert-danger" style="font-size:12px">
    <strong>Nessun tecnico assegnato all'unità Service Desk.</strong>
    Senza assegnazione ogni ticket risulta gestito da specialisti e il tasso di escalation
    non ha significato. Assegnare i tecnici in <em>Unità Organizzative Tecniche</em>.
  </div>
<?php endif; ?>

<!-- ── filtri ───────────────────────────────────────────────────────────── -->
<?php // v1.9.8 — pannello uniformato al template di Commesse/Progetti ?>
<?php
  $attivi = ($tec !== '') + ($f['queue'] !== '') + ($f['level'] !== '') + ($f['gest'] !== '');
?>
<details class="pm-panel" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary>
    <i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?=$attivi?></span><?php endif; ?>
    <span class="pm-hint"><?=$n($h['ticket'] ?? 0)?> ticket nel periodo</span>
  </summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?>

      <div class="pm-group">
        <h4>Periodo</h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Dal</label>
            <input type="date" name="from" value="<?=h($f['from'])?>"></div>
          <div class="form-group"><label>Al</label>
            <input type="date" name="to" value="<?=h($f['to'])?>"></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Selezione</h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Componente del team</label>
            <select name="tec"><option value="">— tutta la squadra —</option>
              <?php foreach ($elencoTeam as $t): ?>
                <option value="<?=h($t['nome'])?>" <?=$tec===$t['nome']?'selected':''?>>
                  <?=h($t['etichetta'])?></option>
              <?php endforeach; ?>
              <?php if ($tec !== '' && !in_array($tec, array_column($elencoTeam,'nome'), true)): ?>
                <option value="<?=h($tec)?>" selected><?=h($tec)?> (fuori squadra)</option>
              <?php endif; ?>
            </select></div>
          <div class="form-group"><label>Coda</label>
            <select name="queue"><option value="">— tutte —</option>
              <?php foreach ($elencoCode as $c): ?>
                <option value="<?=h($c)?>" <?=$f['queue']===$c?'selected':''?>><?=h($c)?></option>
              <?php endforeach; ?></select></div>
          <div class="form-group"><label>Livello coinvolto</label>
            <select name="level"><option value="">— tutti —</option>
              <option value="L1" <?=$f['level']==='L1'?'selected':''?>>L1 — Service Desk</option>
              <option value="L2" <?=$f['level']==='L2'?'selected':''?>>L2 — specialisti</option>
            </select></div>
          <?php // v1.9.8 — la classe di gestione esisteva nel modello ma non nel
                // pannello: si poteva filtrare solo modificando l'URL a mano ?>
          <div class="form-group"><label>Classe di gestione</label>
            <select name="gest"><option value="">— tutte —</option>
              <?php foreach ([
                'risolto dal Service Desk',
                'escalation di 2 livello verso specialisti',
                'presa in carico diretta da specialisti',
                'lavorato senza risposta scritta',
                'cliente senza risposta scritta',
                'mai preso in carico',
              ] as $g): ?>
                <option value="<?=h($g)?>" <?=$f['gest']===$g?'selected':''?>><?=h($g)?></option>
              <?php endforeach; ?></select></div>
        </div>
      </div>

      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('service_desk')?>">Azzera</a>
        <a class="btn btn-sm" href="<?=$qs(['export'=>'xlsx'])?>">
          <i class="fa-solid fa-file-excel"></i> XLSX</a>
        <a class="btn btn-sm" href="<?=$qs(['print'=>'1'])?>" target="_blank">
          <i class="fa-solid fa-print"></i> <?= $tec !== '' ? 'Report personale' : 'Report generale' ?></a>
      </div>
    </form>
  </div>
</details>

<!-- ── i quattro indicatori ─────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">
  <?php
    $sc = (int)$h['scoperti'];
    foreach ([
      ['Ticket del periodo', $n($h['ticket']), '#334155',
       $n($h['chiusi']) . ' chiusi · ' . $n1($h['messaggi_medi']) . ' messaggi medi'],
      ['Presi in carico da L1', $n($h['presi_in_carico']), '#16a34a',
       $n($h['risolti_l1']) . ' risolti · ' . $n($h['escalation']) . ' scalati'],
      ['Tasso di escalation', ($h['tasso_escalation'] === null ? '—' : $n1($h['tasso_escalation']) . '%'), '#f59e0b',
       'sui soli ticket presi in carico dal Service Desk'],
      ['Da presidiare', $n($sc), $sc > 0 ? '#dc2626' : '#16a34a',
       $sc > 0 ? 'richiedono un intervento' : 'nessun ticket scoperto'],
    ] as [$lbl, $val, $col, $sub]): ?>
    <div class="card" style="text-align:center;padding:14px;border-top:3px solid <?=$col?>">
      <div style="font-size:26px;font-weight:800;color:<?=$col?>"><?=$val?></div>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($lbl)?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:3px"><?=h($sub)?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php // ── v1.8.86 — scheda del singolo componente ──────────────────────── ?>
<?php if ($tec !== '' && $sch): ?>
  <?php
    $mesi   = $sd->schedaMesi($tec, $f, 12);
    $codeT  = $sd->codeDettaglio($tec, $f);        // v1.8.87 — con quota sulla coda
    $modRie = $sd->moduliRiepilogo($tec, $f);  // v1.8.87 — moduli di intervento
    $modCon = $sd->moduliContratto($tec, $f);  // v1.8.87 — per tipologia contratto
    $tks    = $sd->schedaTicket($tec, $f, 100);
    $pe     = $sch['periodo_esito'] ?? [];
    $pd     = $sch['periodo'] ?? [];
    // media dei pari livello, per dare una scala ai numeri del singolo: un
    // valore isolato non dice se sia alto o basso
    $pari = array_values(array_filter($confronto, fn($c) => $c['tecnico'] !== $tec));
    $mediaOre  = $pari ? array_sum(array_map(fn($c)=>(float)$c['ore_prima_risposta'], $pari)) / count($pari) : null;
    $mediaTasso= $pari ? array_sum(array_map(fn($c)=>(float)$c['tasso_escalation_pct'], $pari)) / count($pari) : null;
  ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #7c3aed">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-user"></i> <?=h($sch['tecnico'])?></span>
      <span style="background:<?=$sch['livello']==='L1'?'#16a34a':'#2563eb'?>;color:#fff;
            border-radius:10px;padding:2px 10px;font-size:11px;font-weight:700;margin-left:8px">
        <?=h($sch['livello'])?><?= $sch['sotto_unita'] ? ' · ' . h($sch['sotto_unita']) : '' ?></span>
      <a class="btn btn-sm" style="margin-left:auto" href="<?=$qs(['tec'=>null])?>">
        <i class="fa-solid fa-xmark"></i> Chiudi scheda</a>
    </div>

    <?php // ESITO: attribuibile, perché calcolato sui ticket presi in carico ?>
    <div style="font-size:12px;font-weight:700;margin-bottom:8px">
      Ticket presi in carico <span style="font-weight:400;color:var(--muted)">
      — di cui ha scritto la prima risposta, quindi a lui attribuibili</span></div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px">
      <?php foreach ([
        ['Presi in carico', $n($sch['presi_in_carico']), '#334155',
         $n($pe['presi'] ?? 0) . ' nel periodo'],
        ['Risolti', $n($sch['risolti']), '#16a34a',
         $sch['presi_in_carico'] > 0
           ? $n1(100 * (int)$sch['risolti'] / (int)$sch['presi_in_carico']) . '% dei presi' : ''],
        ['Scalati a specialisti', $n($sch['scalati']), '#f59e0b',
         $sch['tasso_escalation_pct'] !== null ? $n1($sch['tasso_escalation_pct']) . '% di escalation' : ''],
        ['Tasso di escalation', ($sch['tasso_escalation_pct'] === null ? '—' : $n1($sch['tasso_escalation_pct']).'%'),
         ($mediaTasso !== null && (float)$sch['tasso_escalation_pct'] > $mediaTasso * 1.4) ? '#dc2626' : '#334155',
         $mediaTasso !== null ? 'media pari livello ' . $n1($mediaTasso) . '%' : ''],
        ['Prima risposta', ($sch['ore_prima_risposta'] === null ? '—' : $n1($sch['ore_prima_risposta']).' h'),
         '#2563eb', $mediaOre !== null ? 'media pari livello ' . $n1($mediaOre) . ' h' : ''],
      ] as [$lbl, $val, $col, $sub]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:19px;font-weight:800;color:<?=$col?>"><?=$val?></div>
          <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($lbl)?></div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px"><?=h($sub)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php // ATTIVITÀ: non attribuibile, perché comprende interventi su ticket altrui ?>
    <div style="font-size:12px;font-weight:700;margin-bottom:8px">
      Attività complessiva <span style="font-weight:400;color:var(--muted)">
      — comprende gli interventi su ticket presi in carico da altri</span></div>
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:12px">
      <?php foreach ([
        ['Messaggi', $n($sch['messaggi']), $n($pd['messaggi'] ?? 0) . ' nel periodo'],
        ['Risposte', $n($sch['risposte']), 'al cliente'],
        ['Note interne', $n($sch['note']),
         $sch['rapporto_risposte_note'] !== null ? 'rapporto ' . $n1($sch['rapporto_risposte_note']) . ':1' : ''],
        ['Ticket toccati', $n($sch['ticket_toccati']), 'anche solo con note'],
        ['Code presidiate', $n($sch['code']), 'ampiezza del ruolo'],
        ['Giorni attivi', $n($sch['giorni_attivi']),
         $sch['messaggi_al_giorno'] !== null ? $n1($sch['messaggi_al_giorno']) . ' msg/giorno' : ''],
      ] as [$lbl, $val, $sub]): ?>
        <div style="padding:9px;border-left:3px solid #cbd5e1;background:#fafafa;border-radius:4px">
          <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase"><?=h($lbl)?></div>
          <div style="font-size:16px;font-weight:800;color:#334155"><?=$val?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($sub)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <p style="font-size:11px;color:var(--muted);margin-bottom:14px">
      <strong>I due gruppi non vanno confusi.</strong> L'esito riguarda i ticket di cui ha scritto la
      prima risposta e gli è attribuibile; l'attività comprende ogni messaggio, anche su ticket presi
      in carico da altri. Contare i messaggi misura quanto scrive, non quanto risolve.
    </p>

    <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:14px">
      <?php if (count($mesi) > 1): ?>
      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px">Andamento mensile</div>
        <?php
          $mx = 1; foreach ($mesi as $mm) $mx = max($mx, (int)$mm['messaggi']);
          $W2=520; $H2=150; $pL=34; $pR=8; $pT=8; $pB=22;
          $pw2=$W2-$pL-$pR; $ph2=$H2-$pT-$pB; $nb2=max(1,count($mesi));
          $bw = $pw2 / $nb2;
        ?>
        <svg viewBox="0 0 <?=$W2?> <?=$H2?>" style="width:100%;height:auto;font-family:inherit">
          <?php for ($g=0; $g<=2; $g++): $y=$pT+$ph2-$g*$ph2/2; ?>
            <line x1="<?=$pL?>" y1="<?=round($y,1)?>" x2="<?=$W2-$pR?>" y2="<?=round($y,1)?>"
                  stroke="#e2e8f0"/>
            <text x="<?=$pL-4?>" y="<?=round($y+3,1)?>" text-anchor="end" font-size="8"
                  fill="#94a3b8"><?=round($mx*$g/2)?></text>
          <?php endfor; ?>
          <?php // v1.9.14 — lineare, come l'andamento generale. Prima era a barre
                // impilate: passando dal riquadro generale alla scheda personale
                // il grafico cambiava forma senza che i dati cambiassero natura.
                $xAt2 = fn($i) => $pL + ($i / max(1, $nb2 - 1)) * ($W2 - $pL - $pR);
                $yAt2 = fn($v) => $pT + $ph2 - ($mx > 0 ? ($v / $mx) * $ph2 : 0);
                $ptsR = $ptsN = [];
                foreach ($mesi as $i => $mm) {
                    $ptsR[] = round($xAt2($i),1) . ',' . round($yAt2((int)$mm['risposte']),1);
                    $ptsN[] = round($xAt2($i),1) . ',' . round($yAt2((int)$mm['note']),1);
                }
                $rP = $nb2 > 40 ? 1.4 : 2.5; ?>
          <polyline fill="none" stroke="#2563eb" stroke-width="2" points="<?=implode(' ',$ptsR)?>"/>
          <polyline fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="4 3"
                    points="<?=implode(' ',$ptsN)?>"/>
          <?php foreach ($mesi as $i => $mm): ?>
            <circle cx="<?=round($xAt2($i),1)?>" cy="<?=round($yAt2((int)$mm['risposte']),1)?>"
                    r="<?=$rP?>" fill="#2563eb">
              <title><?=h($mm['anno_mese'])?>: <?=$n($mm['risposte'])?> risposte</title></circle>
            <circle cx="<?=round($xAt2($i),1)?>" cy="<?=round($yAt2((int)$mm['note']),1)?>"
                    r="<?=$rP?>" fill="#94a3b8">
              <title><?=h($mm['anno_mese'])?>: <?=$n($mm['note'])?> note</title></circle>
            <?php if ($i % max(1,intdiv($nb2,6)) === 0): ?>
              <text x="<?=round($xAt2($i),1)?>" y="<?=$H2-6?>" text-anchor="middle" font-size="8"
                    fill="#64748b"><?=h(etichettaAsse((string)$mm['anno_mese']))?></text>
            <?php endif; ?>
          <?php endforeach; ?>
        </svg>
        <div style="font-size:10px;color:var(--muted)">
          <span style="display:inline-block;width:10px;height:8px;background:#2563eb"></span> risposte
          <span style="display:inline-block;width:10px;height:8px;background:#94a3b8;margin-left:10px"></span> note interne
        </div>
      </div>
      <?php endif; ?>

      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px">Code seguite</div>
        <?php // v1.8.87 — la QUOTA sul totale della coda distingue il presidio dal
              // transito: 5 ticket su 800 e 5 su 6 sono numeri uguali con
              // significati opposti, e la sola colonna "ticket" li confonde. ?>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Coda</th><th style="text-align:right">Ticket</th>
            <th style="text-align:right">Presi</th><th style="text-align:right">Quota coda</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($codeT, 0, 12) as $c):
            $qc = (float)($c['quota_coda_pct'] ?? 0); ?>
            <tr>
              <td><?=h($c['coda'])?></td>
              <td style="text-align:right"><?=$n($c['ticket'])?></td>
              <td style="text-align:right;color:#16a34a"><?=$n($c['presi_in_carico'])?></td>
              <td style="text-align:right">
                <div style="display:flex;align-items:center;gap:5px;justify-content:flex-end">
                  <div style="width:44px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                    <div style="width:<?=round(min(100,$qc),1)?>%;height:100%;
                         background:<?=$qc>=40?'#16a34a':($qc>=15?'#f59e0b':'#cbd5e1')?>"></div>
                  </div>
                  <span style="font-weight:<?=$qc>=40?'700':'400'?>"><?=$n1($qc)?>%</span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:10px;color:var(--muted);margin-top:5px">
          <strong>Quota coda</strong>: percentuale dei ticket di quella coda su cui è intervenuto.
          Verde sopra il 40% (presidio), ambra sopra il 15%.
        </p>
      </div>
    </div>

    <?php // ── v1.8.87 — moduli di intervento per tipologia di contratto ──── ?>
    <?php if (($modRie['moduli'] ?? 0) > 0): ?>
      <div style="margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px">
        <div style="font-size:12px;font-weight:700;margin-bottom:8px">
          Moduli di intervento nel periodo
          <span style="font-weight:400;color:var(--muted)">— attività distinta dai ticket,
          non sommabile: un ticket può generare un modulo</span></div>

        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:12px">
          <?php foreach ([
            ['Moduli', $n($modRie['moduli']), '#334155', $n($modRie['commesse']) . ' commesse'],
            ['Ore consuntivate', $n1($modRie['ore']) . ' h', '#2563eb',
             $n($modRie['modelli']) . ' tipologie di contratto'],
            ['Ore a ricavo', $n1($modRie['ore_ricavo']) . ' h', '#16a34a',
             $modRie['pct_ricavo'] !== null ? $n1($modRie['pct_ricavo']) . '% del totale' : ''],
            ['Ore su commesse interne', $n1($modRie['ore_interne']) . ' h', '#94a3b8',
             'senza ricavo'],
            ['Di cui extra', $n1($modRie['ore_extra']) . ' h', '#f59e0b', 'fuori orario dichiarato'],
          ] as [$lbl, $val, $col, $sub]): ?>
            <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
              <div style="font-size:17px;font-weight:800;color:<?=$col?>"><?=$val?></div>
              <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($lbl)?></div>
              <div style="font-size:10px;color:var(--muted)"><?=h($sub)?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php // barra: quota a ricavo contro interna ?>
        <?php $tOre = max(0.01, (float)$modRie['ore']); ?>
        <div style="display:flex;height:20px;border-radius:5px;overflow:hidden;margin-bottom:12px">
          <div style="width:<?=round(100*(float)$modRie['ore_ricavo']/$tOre,2)?>%;background:#16a34a"
               title="A ricavo: <?=$n1($modRie['ore_ricavo'])?> h"></div>
          <div style="width:<?=round(100*(float)$modRie['ore_interne']/$tOre,2)?>%;background:#cbd5e1"
               title="Interne: <?=$n1($modRie['ore_interne'])?> h"></div>
        </div>

        <?php // v1.8.92 — il CODICE della linea accanto all'etichetta: chi
              // riscontra un tabulato del gestionale cerca "WTS-ACM", non
              // "Chiavi in mano". Sono la stessa cosa detta per compiti diversi. ?>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Codice</th><th>Tipologia di contratto</th><th>Modello</th>
            <th style="text-align:right">Moduli</th><th style="text-align:right">Ore</th>
            <th style="text-align:right">Quota</th><th style="text-align:right">Commesse</th>
            <th>Natura</th></tr></thead>
          <tbody>
          <?php foreach ($modCon as $c): $qo = 100 * (float)$c['ore'] / $tOre; ?>
            <tr>
              <td style="font-family:monospace;font-size:10px;font-weight:700"><?=h($c['codice'] ?? '')?></td>
              <td><strong><?=h($c['contratto'])?></strong></td>
              <td style="font-size:10px;color:var(--muted)"><?=h($c['modello'])?></td>
              <td style="text-align:right"><?=$n($c['moduli'])?></td>
              <td style="text-align:right;font-weight:700"><?=$n1($c['ore'])?> h</td>
              <td style="text-align:right"><?=$n1($qo)?>%</td>
              <td style="text-align:right;color:var(--muted)"><?=$n($c['commesse'])?></td>
              <td><span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:8px;color:#fff;
                    background:<?=$c['ha_ricavo']?'#16a34a':'#94a3b8'?>">
                <?=$c['ha_ricavo'] ? 'a ricavo' : 'interna'?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p style="font-size:11px;color:var(--muted);margin-top:12px">
        Nessun modulo di intervento nel periodo per questo componente.
      </p>
    <?php endif; ?>

    <?php if ($tks): ?>
      <details style="margin-top:12px">
        <summary style="cursor:pointer;font-size:12px;font-weight:600">
          Ticket presi in carico nel periodo (<?=count($tks)?>)</summary>
        <div style="overflow-x:auto;margin-top:8px">
          <table class="data-table" style="width:100%;font-size:11px">
            <thead><tr><th>Ticket</th><th>Oggetto</th><th>Coda</th><th>Esito</th>
              <th style="text-align:right">1ª risposta</th><th style="text-align:right">Messaggi</th></tr></thead>
            <tbody>
            <?php foreach ($tks as $t): ?>
              <tr>
                <td style="font-family:monospace"><?=h($t['ticket'])?></td>
                <td><?=h(mb_strimwidth((string)$t['oggetto'], 0, 46, '…'))?></td>
                <td><?=h($t['coda'])?></td>
                <td><span style="color:<?=$colClasse[$t['gestione']] ?? '#64748b'?>;font-weight:600">
                  <?=h($t['gestione'])?></span></td>
                <td style="text-align:right"><?=$t['ore_1a'] !== null ? $n1($t['ore_1a']).' h' : '—'?></td>
                <td style="text-align:right"><?=$n($t['messaggi'])?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php // ── confronto fra i componenti del primo livello ──────────────────── ?>
<?php if ($confronto && $tec === ''): ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-header"><span class="card-title">
      <i class="fa-solid fa-user-group"></i> I componenti del Service Desk</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        clic sul nome per la scheda completa</span></div>
    <table class="data-table" style="width:100%;font-size:12px">
      <?php // v1.8.87 — ticket e moduli affiancati, MAI sommati: un ticket può
            // generare un modulo, e un totale unico conterebbe lo stesso lavoro
            // due volte. Le due metà della tabella sono separate visivamente. ?>
      <thead>
        <tr style="font-size:10px;color:var(--muted)">
          <th colspan="2"></th>
          <th colspan="5" style="text-align:center;border-bottom:2px solid #2563eb">TICKET</th>
          <th colspan="4" style="text-align:center;border-bottom:2px solid #7c3aed">MODULI DI INTERVENTO</th>
        </tr>
        <tr><th>Componente</th><th>Sotto-unità</th>
        <th style="text-align:right">Presi in carico</th><th style="text-align:right">Risolti</th>
        <th style="text-align:right">Scalati</th><th style="text-align:right">Escalation</th>
        <th style="text-align:right">1ª risposta</th>
        <th style="text-align:right">Moduli</th><th style="text-align:right">Ore</th>
        <th style="text-align:right">A ricavo</th><th style="text-align:right">Commesse</th></tr></thead>
      <tbody>
      <?php foreach ($confronto as $c): ?>
        <tr>
          <td><a href="<?=$qs(['tec'=>$c['tecnico']])?>" style="font-weight:600"><?=h($c['tecnico'])?></a></td>
          <td style="font-size:11px;color:var(--muted)"><?=h($c['sotto_unita'] ?? '—')?></td>
          <td style="text-align:right;font-weight:700"><?=$n($c['presi_in_carico'])?></td>
          <td style="text-align:right;color:#16a34a"><?=$n($c['risolti'])?></td>
          <td style="text-align:right;color:#f59e0b"><?=$n($c['scalati'])?></td>
          <td style="text-align:right"><?=$c['tasso_escalation_pct'] !== null ? $n1($c['tasso_escalation_pct']).'%' : '—'?></td>
          <td style="text-align:right"><?=$c['ore_prima_risposta'] !== null ? $n1($c['ore_prima_risposta']).' h' : '—'?></td>
          <td style="text-align:right;border-left:1px solid #e2e8f0"><?=$n($c['moduli_intervento'] ?? 0)?></td>
          <td style="text-align:right;font-weight:700"><?=$n1($c['ore_moduli'] ?? 0)?> h</td>
          <td style="text-align:right;color:#16a34a">
            <?=$c['pct_a_ricavo'] !== null ? $n1($c['pct_a_ricavo']).'%' : '—'?></td>
          <td style="text-align:right;color:var(--muted)"><?=$n($c['commesse'] ?? 0)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="font-size:11px;color:var(--muted);margin-top:8px">
      Tutti i valori si riferiscono al <strong>periodo selezionato</strong>. <strong>Il tempo di prima risposta
      va letto insieme al volume</strong>: chi prende in carico più ticket ha naturalmente tempi più
      alti, e un tempo isolato descrive una persona lenta invece di una persona occupata.
      <br><strong>Ticket e moduli non si sommano</strong>: un ticket può generare un modulo di
      intervento, e un totale unico conterebbe lo stesso lavoro due volte. Sono due attività
      affiancate — chi ha meno ticket può avere più ore consuntivate.
    </p>
  </div>
<?php endif; ?>

<!-- ── ripartizione nelle sei classi ────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title">
    <i class="fa-solid fa-layer-group"></i> Come sono stati gestiti</span></div>

  <?php $tot = max(1, (int)$h['ticket']); ?>
  <div style="display:flex;height:26px;border-radius:6px;overflow:hidden;margin-bottom:12px">
    <?php foreach ($brk as $b): $q = 100 * (int)$b['ticket'] / $tot; if ($q < 0.4) continue; ?>
      <div style="width:<?=round($q,2)?>%;background:<?=$colClasse[$b['gestione']] ?? '#94a3b8'?>"
           title="<?=h($b['gestione'])?>: <?=$n($b['ticket'])?> (<?=$n1($q)?>%)"></div>
    <?php endforeach; ?>
  </div>

  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Classe</th><th style="text-align:right">Ticket</th>
      <th style="text-align:right">Quota</th><th style="text-align:right">Chiusi</th>
      <th style="text-align:right">Durata media</th><th>Significato</th></tr></thead>
    <tbody>
    <?php
      $spiega = [
        'risolto dal Service Desk'                  => 'chiuso dal primo livello senza coinvolgere specialisti',
        'escalation di 2 livello verso specialisti' => 'preso in carico da L1 e passato agli specialisti',
        'presa in carico diretta da specialisti'    => 'nato su coda specialistica, mai passato dal Service Desk',
        'lavorato senza risposta scritta'           => 'note interne, nessun messaggio al cliente: risolto per altra via',
        'cliente senza risposta scritta'            => 'il cliente ha scritto, nessuna risposta scritta è stata inviata',
        'mai preso in carico'                       => 'nessuno lo ha toccato',
      ];
      foreach ($brk as $b): $q = 100 * (int)$b['ticket'] / $tot; ?>
      <tr>
        <td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;
              background:<?=$colClasse[$b['gestione']] ?? '#94a3b8'?>;margin-right:6px"></span>
          <strong><?=h($b['gestione'])?></strong></td>
        <td style="text-align:right;font-weight:700"><?=$n($b['ticket'])?></td>
        <td style="text-align:right"><?=$n1($q)?>%</td>
        <td style="text-align:right"><?=$n($b['chiusi'])?></td>
        <td style="text-align:right"><?=$b['durata_media'] !== null ? $n1($b['durata_media']).' h' : '—'?></td>
        <td style="font-size:11px;color:var(--muted)"><?=h($spiega[$b['gestione']] ?? '')?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p style="font-size:11px;color:var(--muted);margin-top:8px">
    <strong>Il tasso di escalation si calcola solo sui ticket presi in carico dal Service Desk</strong>
    (<?=$n($h['presi_in_carico'])?> nel periodo). Le prese in carico dirette da specialisti non vi
    rientrano: non essendo mai passate dal primo livello, non possono essere state scalate.
    La durata è fra apertura e ultimo movimento e <strong>comprende le attese del cliente</strong>:
    non è un tempo di risoluzione.
  </p>
</div>

<!-- ── andamento mensile ────────────────────────────────────────────────── -->
<?php if (count($trend) > 1): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title">
    <i class="fa-solid fa-chart-line"></i> Andamento — <?=count($trend)?>
      <?= (($trend[0]['grana'] ?? 'mese') === 'giorno') ? 'giorni' : 'mesi' ?></span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">
      <?= (($trend[0]['grana'] ?? 'mese') === 'giorno')
          ? 'raggruppamento giornaliero — periodo fino a 3 mesi'
          : 'raggruppamento mensile — periodo oltre 3 mesi' ?></span></div>
  <?php
    $maxT = 1; foreach ($trend as $t) $maxT = max($maxT, (int)$t['ticket']);
    $W = 900; $H = 220; $padL = 44; $padR = 44; $padT = 14; $padB = 30;
    $pw = $W - $padL - $padR; $ph = $H - $padT - $padB;
    $nb = max(1, count($trend));
    $xAt = fn($i) => $padL + ($nb > 1 ? $i * $pw / ($nb - 1) : $pw / 2);
    $yAt = fn($v) => $padT + $ph - ($v / $maxT) * $ph;
    $ptsT = $ptsE = [];
    foreach ($trend as $i => $t) {
        $ptsT[] = round($xAt($i),1) . ',' . round($yAt((int)$t['ticket']),1);
        // il tasso ha una scala propria: 0-100% mappato sull'altezza del grafico
        $ptsE[] = round($xAt($i),1) . ',' . round($padT + $ph - (min(100,(float)($t['tasso'] ?? 0))/100)*$ph, 1);
    }
  ?>
  <svg viewBox="0 0 <?=$W?> <?=$H?>" style="width:100%;min-width:640px;height:auto;font-family:inherit">
    <?php for ($g = 0; $g <= 4; $g++): $y = $padT + $ph - $g*$ph/4; ?>
      <line x1="<?=$padL?>" y1="<?=round($y,1)?>" x2="<?=$W-$padR?>" y2="<?=round($y,1)?>"
            stroke="#e2e8f0" stroke-width="1"/>
      <text x="<?=$padL-6?>" y="<?=round($y+3,1)?>" text-anchor="end" font-size="9" fill="#94a3b8">
        <?=round($maxT*$g/4)?></text>
      <text x="<?=$W-$padR+6?>" y="<?=round($y+3,1)?>" font-size="9" fill="#f59e0b"><?=25*$g?>%</text>
    <?php endfor; ?>
    <polyline fill="none" stroke="#2563eb" stroke-width="2.5" points="<?=implode(' ',$ptsT)?>"/>
    <polyline fill="none" stroke="#f59e0b" stroke-width="2" stroke-dasharray="5 3"
              points="<?=implode(' ',$ptsE)?>"/>
    <?php
      // v1.9.13 — su base giornaliera i punti sono fino a 92: marcatori piccoli e
      // meno etichette, altrimenti si sovrappongono e la linea sparisce sotto i
      // cerchi. Su base mensile restano come prima.
      $perG = (($trend[0]['grana'] ?? 'mese') === 'giorno');
      $rPt  = $perG ? 1.6 : 3;
      $ogni = max(1, intdiv($nb, $perG ? 12 : 8));
    ?>
    <?php foreach ($trend as $i => $t): ?>
      <circle cx="<?=round($xAt($i),1)?>" cy="<?=round($yAt((int)$t['ticket']),1)?>" r="<?=$rPt?>" fill="#2563eb">
        <title><?=h($t['ym'])?>: <?=$n($t['ticket'])?> ticket · escalation <?=$n1($t['tasso'])?>%</title></circle>
      <?php if ($i % $ogni === 0): ?>
        <text x="<?=round($xAt($i),1)?>" y="<?=$H-8?>" text-anchor="middle" font-size="9" fill="#64748b">
          <?=h(etichettaAsse((string)$t['ym']))?></text>
      <?php endif; ?>
    <?php endforeach; ?>
  </svg>
  <div style="display:flex;gap:16px;font-size:11px;color:var(--muted);margin-top:4px">
    <span><span style="display:inline-block;width:14px;height:3px;background:#2563eb"></span> ticket aperti (scala sinistra)</span>
    <span><span style="display:inline-block;width:14px;border-top:3px dotted #f59e0b"></span> tasso di escalation (scala destra)</span>
  </div>
</div>
<?php endif; ?>

<!-- ── ticket da presidiare ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px;<?= $scop ? 'border-left:4px solid #dc2626' : '' ?>">
  <div class="card-header"><span class="card-title">
    <i class="fa-solid fa-triangle-exclamation"></i> Ticket da presidiare</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">
      mai presi in carico, e cliente senza risposta ancora aperti</span></div>

  <?php if (!$scop): ?>
    <p style="color:#16a34a;font-size:12px;margin:0">
      <i class="fa-solid fa-check"></i> Nessun ticket scoperto nel periodo.</p>
  <?php else: ?>
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Ticket</th><th>Oggetto</th><th>Coda</th><th>Stato</th>
        <th style="text-align:right">Giorni</th><th>Situazione</th><th>Toccato da</th></tr></thead>
      <tbody>
      <?php foreach ($scop as $s): ?>
        <tr<?= (int)$s['giorni'] > 30 ? ' style="background:#fef2f2"' : '' ?>>
          <td style="font-family:monospace;font-size:11px"><?=h($s['ticket'])?></td>
          <td style="font-size:11px"><?=h(mb_strimwidth((string)$s['oggetto'], 0, 60, '…')) ?: '<em style="color:var(--muted)">(senza oggetto)</em>'?></td>
          <td><?=h($s['coda'])?></td>
          <td style="font-size:10px;color:var(--muted)"><?=h($s['stato'])?></td>
          <td style="text-align:right;font-weight:700;color:<?=(int)$s['giorni']>30?'#dc2626':'#334155'?>">
            <?=$n($s['giorni'])?></td>
          <td style="font-size:11px"><?=h($s['gestione'])?></td>
          <td style="font-size:11px;color:<?=$s['presidio']==='nessuno'?'#dc2626':'var(--muted)'?>">
            <?=h($s['presidio'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php // ── v1.9.5 — analisi del Team ──────────────────────────────────────── ?>
<?php if ($tDett): ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #0891b2">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-users-gear"></i> Analisi del Team</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=$n(count($tDett))?> componenti<?= $tec !== '' ? ' — vista ristretta a ' . h($tec) : '' ?>
        · ticket e moduli sono attività distinte, non sommabili</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:12px">
      <?php foreach ([
        ['Moduli di intervento', $n($tQuadro['moduli'] ?? 0), '#0891b2',
         $n($tQuadro['commesse'] ?? 0) . ' commesse'],
        ['Ore', $n1($tQuadro['ore'] ?? 0), '#2563eb',
         $tQuadro['ore_per_giornata'] !== null ? $n1($tQuadro['ore_per_giornata']) . ' h/giornata' : ''],
        ['Ore a ricavo', $n1($tQuadro['ore_ricavo'] ?? 0), '#16a34a',
         ((float)($tQuadro['ore'] ?? 0)) > 0
           ? $n1(100*(float)$tQuadro['ore_ricavo']/(float)$tQuadro['ore']) . '%' : ''],
        ['Fuori orario', $n1($tQuadro['ore_fuori'] ?? 0), '#f59e0b',
         $tQuadro['pct_fuori'] !== null ? $n1($tQuadro['pct_fuori']) . '% delle ore' : ''],
        ['Giornate-uomo', $n($tQuadro['giornate_uomo'] ?? 0), '#7c3aed',
         'incaricato + giorno'],
        ['Linee di servizio', $n($tQuadro['linee'] ?? 0), '#334155',
         $n($tQuadro['tecnici'] ?? 0) . ' con moduli'],
      ] as [$l, $v, $c, $sb]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:17px;font-weight:800;color:<?=$c?>"><?=$v?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php // il dettaglio della squadra: ticket e moduli affiancati ?>
    <div style="font-size:12px;font-weight:700;margin-bottom:6px">Dettaglio della squadra</div>
    <table class="data-table" style="width:100%;font-size:11px">
      <thead>
        <tr style="font-size:9px;color:var(--muted)">
          <th colspan="2"></th>
          <th style="text-align:center;border-bottom:2px solid #2563eb">TICKET</th>
          <th colspan="7" style="text-align:center;border-bottom:2px solid #0891b2">MODULI DI INTERVENTO</th>
        </tr>
        <tr><th>Componente</th><th>Sotto-unità</th>
          <th style="text-align:right">Presi</th>
          <th style="text-align:right">Moduli</th><th style="text-align:right">Ore</th>
          <th style="text-align:right">In orario</th><th style="text-align:right">Fuori</th>
          <th style="text-align:right">% fuori</th><th style="text-align:right">Giornate</th>
          <th style="text-align:right">h/giorno</th></tr>
      </thead>
      <tbody>
      <?php foreach ($tDett as $d): $pf = (float)($d['pct_fuori'] ?? 0); ?>
        <tr>
          <td><a href="<?=$qs(['tec'=>$d['tecnico']])?>" style="font-weight:600"><?=h($d['tecnico'])?></a></td>
          <td style="font-size:10px;color:var(--muted)"><?=h($d['sotto_unita'] ?? '—')?></td>
          <td style="text-align:right;border-right:1px solid #e2e8f0"><?=$n($d['ticket_presi'])?></td>
          <td style="text-align:right"><?=$n($d['moduli'])?></td>
          <td style="text-align:right;font-weight:700"><?=$n1($d['ore'])?></td>
          <td style="text-align:right;color:#16a34a"><?=$n1($d['ore_in_orario'])?></td>
          <td style="text-align:right;color:#f59e0b"><?=$n1($d['ore_fuori_orario'])?></td>
          <td style="text-align:right;font-weight:<?=$pf>=30?'700':'400'?>;
                color:<?=$pf>=30?'#dc2626':'#334155'?>"><?=$n1($d['pct_fuori'])?>%</td>
          <td style="text-align:right"><?=$n($d['giornate'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$n1($d['ore_per_giornata'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:14px;margin-top:14px">
      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px">Per fascia oraria</div>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Fascia</th><th style="text-align:right">Interventi</th>
            <th style="text-align:right">Ore</th><th style="text-align:right">Media</th></tr></thead>
          <tbody>
          <?php $colF = ['in orario'=>'#16a34a','fuori orario'=>'#f59e0b','non rilevata'=>'#94a3b8'];
                foreach ($tFascia as $x): ?>
            <tr>
              <td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                    background:<?=$colF[$x['fascia_oraria']] ?? '#94a3b8'?>;margin-right:5px"></span>
                <?=h($x['fascia_oraria'])?></td>
              <td style="text-align:right"><?=$n($x['interventi'])?></td>
              <td style="text-align:right;font-weight:700"><?=$n1($x['ore'])?></td>
              <td style="text-align:right;color:var(--muted)"><?=$n1($x['ore_medie'])?> h</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:10px;color:var(--muted);margin-top:5px">
          Fasce 09–13 e 14–18 nei feriali. Sabato e domenica sono fuori orario
          <strong>per costruzione</strong>, prima ancora di guardare l'ora.
        </p>
      </div>

      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px">Per tipologia di contratto</div>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Codice</th><th>Contratto</th>
            <th style="text-align:right">Interv.</th><th style="text-align:right">Ore</th>
            <th style="text-align:right">In orario</th><th style="text-align:right">Fuori</th>
            <th style="text-align:right">Tecnici</th><th>Natura</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($tContr, 0, 12) as $x): ?>
            <tr>
              <td style="font-family:monospace;font-size:10px;font-weight:700"><?=h($x['codice_linea'])?></td>
              <td><?=h(mb_strimwidth((string)$x['contratto'], 0, 26, '…'))?></td>
              <td style="text-align:right"><?=$n($x['interventi'])?></td>
              <td style="text-align:right;font-weight:700"><?=$n1($x['ore'])?></td>
              <td style="text-align:right;color:#16a34a"><?=$n1($x['ore_in'])?></td>
              <td style="text-align:right;color:#f59e0b"><?=$n1($x['ore_fuori'])?></td>
              <td style="text-align:right;color:var(--muted)"><?=$n($x['tecnici'])?></td>
              <td><span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;color:#fff;
                    background:<?=$x['ha_ricavo']?'#16a34a':'#94a3b8'?>">
                <?=$x['ha_ricavo'] ? 'a ricavo' : 'interna'?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php // ── v1.9.15 — costi per fascia e contratto ──────────────────────────── ?>
<?php if ($cRie): ?>
  <?php
    $colF2 = ['C' => '#16a34a', 'D' => '#f59e0b'];
    $senzaTar = (int)($cQ['righe_senza_tariffa'] ?? 0);
    // raggruppo per linea, come il template
    $perLinea = [];
    foreach ($cRie as $x) $perLinea[$x['codice_linea']][] = $x;
  ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #065f46">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-calculator"></i>
        Riepilogo costi per fascia e contratto</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=h(implode(', ', array_keys($perLinea)))?> ·
        dai moduli di intervento, valorizzati a scaglioni di durata</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:10px">
      <?php foreach ([
        ['Interventi', $n($cQ['interventi'] ?? 0), '#065f46', $n1($cQ['ore'] ?? 0) . ' ore'],
        ['Valore totale', $n1($cQ['valore'] ?? 0), '#0f766e', ''],
        ['Orario ordinario', $n1($cQ['valore_ordinario'] ?? 0), '#16a34a',
         $n1($cQ['ore_ordinario'] ?? 0) . ' h · fascia C'],
        ['Extra-orario', $n1($cQ['valore_extra'] ?? 0), '#f59e0b',
         $n1($cQ['ore_extra'] ?? 0) . ' h · fascia D'],
        ['In reperibilità', $n($cQ['interventi_reperibilita'] ?? 0), '#7c3aed', 'interventi'],
        ['Commesse', $n($cQ['commesse'] ?? 0), '#334155',
         $n($cQ['tecnici'] ?? 0) . ' tecnici'],
      ] as [$l, $v, $c, $sb]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:16px;font-weight:800;color:<?=$c?>"><?=$v?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($senzaTar > 0): ?>
      <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 11px;
                  border-radius:0 6px 6px 0;font-size:11px;margin-bottom:10px">
        <strong><?=$n($senzaTar)?> interventi senza tariffa</strong>: il valore complessivo è
        parziale. Le combinazioni fascia × scaglione non ancora stabilite sono in
        <code>cm_sd_tariffe</code> con tariffa <strong>NULL</strong>, che significa «da stabilire» —
        zero avrebbe significato «gratis».
      </div>
    <?php endif; ?>

    <?php // un blocco per contratto, come nel template ?>
    <?php foreach ($perLinea as $cod => $righe): ?>
      <?php $sOre = 0; $sVal = 0; foreach ($righe as $x) { $sOre += (float)$x['ore']; $sVal += (float)$x['valore']; } ?>
      <div style="margin-bottom:12px">
        <div style="font-size:12px;font-weight:700;padding:6px 9px;background:#f1f5f9;
                    border-radius:6px 6px 0 0;border-bottom:2px solid #065f46">
          RIEPILOGO PER TIPO CONTRATTO —
          <span style="font-family:monospace"><?=h($cod)?></span>
          <span style="font-weight:400;color:var(--muted)"><?=h($righe[0]['contratto'])?></span>
        </div>
        <table class="data-table" style="width:100%;font-size:11px;margin:0">
          <thead><tr><th>Descrizione tariffa</th>
            <th style="text-align:right">N. interventi</th>
            <th style="text-align:center">In reperibilità</th>
            <th style="text-align:right">Totale ore</th>
            <th style="text-align:right">Tariffa</th>
            <th style="text-align:right">Totale valore</th></tr></thead>
          <tbody>
          <?php foreach ($righe as $x): ?>
            <tr>
              <td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                    background:<?=$colF2[$x['fascia']] ?? '#94a3b8'?>;margin-right:5px"></span>
                <?=h($x['descrizione_tariffa'])?></td>
              <td style="text-align:right"><?=$n($x['interventi'])?></td>
              <td style="text-align:center;color:var(--muted)"><?=h($x['reperibilita'])?></td>
              <td style="text-align:right"><?=$n1($x['ore'])?></td>
              <td style="text-align:right;color:var(--muted)">
                <?=$x['tariffa_ora']!==null?$n1($x['tariffa_ora']):'—'?></td>
              <td style="text-align:right;font-weight:700">
                <?=$x['valore']!==null?$n1($x['valore']):'—'?></td>
            </tr>
          <?php endforeach; ?>
            <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #cbd5e1">
              <td>TOTALE</td><td></td><td></td>
              <td style="text-align:right"><?=$n1($sOre)?></td><td></td>
              <td style="text-align:right"><?=$n1($sVal)?></td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:8px;padding-top:8px;
              border-top:1px solid #f1f5f9">
      <strong>Gli scaglioni dipendono dalla durata del singolo intervento</strong>: fino a 4 ore la
      tariffa oraria, oltre la mezza giornata, da 8 ore la giornata. Il valore è
      <strong>ore × tariffa</strong>, non un pacchetto forfetario — tre mezze giornate valgono
      14 h × 87,50 = 1.225,00 e non 3 × 350,00.
      <strong>Fascia C</strong> è l'orario ordinario (09–13 e 14–18 nei feriali),
      <strong>fascia D</strong> l'extra-orario; sabato e domenica sono fascia D per costruzione.
      Le tariffe sono <strong>dedotte dal template</strong> di riferimento, non dichiarate: se le
      condizioni contrattuali differiscono, si correggono in <code>cm_sd_tariffe</code>.
    </p>
  </div>
<?php endif; ?>

<?php // ── v1.9.11 — OBJ_2.1/2.2: attività dai moduli di intervento ───────── ?>
<?php if ($o21Fat || $o22Int): ?>
  <?php
    $oreTot = max(0.01, (float)($o21Q['ore'] ?? 0));
    $senzaTariffa = (int)($o21Q['linee_listino'] ?? 0) - (int)($o21Q['linee_a_listino'] ?? 0);
  ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #b45309">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-file-invoice-dollar"></i>
        Attività del Service Desk: fatturabile e interna</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=$n($o21Q['tecnici_uo'] ?? 0)?> tecnici dell'unità organizzativa ·
        dai <strong>moduli di intervento</strong>, non dai ticket</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:10px">
      <?php foreach ([
        ['Interventi', $n($o21Q['interventi'] ?? 0), '#334155',
         $n1($o21Q['ore'] ?? 0) . ' ore'],
        ['Ore fatturabili', $n1($o21Q['ore_fatt'] ?? 0), '#16a34a',
         $n1(100 - (float)($o21Q['quota_interna_pct'] ?? 0)) . '% del totale'],
        ['Ore interne', $n1($o21Q['ore_int'] ?? 0), '#dc2626',
         $n1($o21Q['quota_interna_pct'] ?? 0) . '% del totale'],
        ['Valore addebitato', $n1($o21Q['valore_addebitato'] ?? 0), '#0891b2', 'dal gestionale'],
        ['A listino, fatturabile', $n1($o21Q['valore_listino_fatt'] ?? 0), '#7c3aed', 'ore × tariffa'],
        ['A listino, interna', $n1($o21Q['valore_listino_int'] ?? 0), '#b45309',
         'costo non addebitato'],
      ] as [$l, $v, $c, $sb]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:16px;font-weight:800;color:<?=$c?>"><?=$v?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($senzaTariffa > 0): ?>
      <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:8px 11px;
                  border-radius:0 6px 6px 0;font-size:11px;margin-bottom:10px">
        <strong><?=$n($senzaTariffa)?> linee su <?=$n($o21Q['linee_listino'] ?? 0)?> non hanno una
        tariffa di listino</strong>: i loro interventi non contribuiscono al valore a listino, che
        è quindi parziale. Le tariffe si compilano in <code>cm_sd_listino</code> — sono
        <strong>NULL e non zero</strong>, perché «non stabilita» e «gratis» sono cose diverse.
      </div>
    <?php endif; ?>

    <?php // la composizione fatturabile / interna ?>
    <div style="display:flex;height:22px;border-radius:5px;overflow:hidden;margin-bottom:10px">
      <?php $qf = 100*(float)($o21Q['ore_fatt'] ?? 0)/$oreTot;
            $qi = 100*(float)($o21Q['ore_int'] ?? 0)/$oreTot; ?>
      <?php if ($qf > 0.4): ?>
        <div style="width:<?=round($qf,2)?>%;background:#16a34a"
             title="Fatturabile: <?=$n1($o21Q['ore_fatt'])?> h (<?=$n1($qf)?>%)"></div>
      <?php endif; ?>
      <?php if ($qi > 0.4): ?>
        <div style="width:<?=round($qi,2)?>%;background:#dc2626"
             title="Interna: <?=$n1($o21Q['ore_int'])?> h (<?=$n1($qi)?>%)"></div>
      <?php endif; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px;color:#16a34a">
          OBJ_2.1 — Attività fatturabile</div>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Codice</th><th style="text-align:right">Interv.</th>
            <th style="text-align:right">Ore</th><th style="text-align:right">Quota</th>
            <th style="text-align:right">Addebitato</th><th style="text-align:right">A listino</th>
            <th style="text-align:right">Tariffa</th></tr></thead>
          <tbody>
          <?php foreach ($o21Fat as $x): ?>
            <tr>
              <td style="font-family:monospace;font-size:10px;font-weight:700"><?=h($x['codice_linea'])?></td>
              <td style="text-align:right"><?=$n($x['interventi'])?></td>
              <td style="text-align:right;font-weight:700"><?=$n1($x['ore'])?></td>
              <td style="text-align:right;color:var(--muted)"><?=$n1($x['quota_ore_pct'])?>%</td>
              <td style="text-align:right"><?=$x['valore_addebitato']!==null?$n1($x['valore_addebitato']):'—'?></td>
              <td style="text-align:right;color:#7c3aed"><?=$x['valore_listino']!==null?$n1($x['valore_listino']):'—'?></td>
              <td style="text-align:right;color:var(--muted)"><?=$x['tariffa_ora']!==null?$n1($x['tariffa_ora']):'—'?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:10px;color:var(--muted);margin-top:4px">
          «Addebitato» è ciò che il gestionale ha valorizzato; «a listino» è ore × tariffa. Dove il
          primo manca resta solo il secondo.</p>
      </div>

      <div>
        <div style="font-size:12px;font-weight:700;margin-bottom:6px;color:#dc2626">
          OBJ_2.2 — Attività interna (non retribuita)</div>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Codice</th><th>Contratto</th><th style="text-align:right">Interv.</th>
            <th style="text-align:right">Ore</th><th style="text-align:right">A listino</th>
            <th style="text-align:right">Tariffa</th></tr></thead>
          <tbody>
          <?php foreach ($o22Int as $x): ?>
            <tr>
              <td style="font-family:monospace;font-size:10px;font-weight:700"><?=h($x['codice_linea'])?></td>
              <td><?=h(mb_strimwidth((string)$x['contratto'], 0, 20, '…'))?></td>
              <td style="text-align:right"><?=$n($x['interventi'])?></td>
              <td style="text-align:right;font-weight:700"><?=$n1($x['ore'])?></td>
              <td style="text-align:right;color:#b45309"><?=$x['valore_listino']!==null?$n1($x['valore_listino']):'—'?></td>
              <td style="text-align:right;color:var(--muted)"><?=$x['tariffa_ora']!==null?$n1($x['tariffa_ora']):'—'?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$o22Int): ?>
            <tr><td colspan="6" style="color:var(--muted);text-align:center;padding:10px">
              Nessuna attività interna nel periodo</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
        <p style="font-size:10px;color:var(--muted);margin-top:4px">
          Sono le commesse senza ricavo: il lavoro c'è, l'addebito no. Il valore a listino ne
          quantifica il costo opportunità.</p>
      </div>
    </div>

    <?php if ($o23Tec): ?>
      <div style="font-size:12px;font-weight:700;margin:14px 0 6px">
        OBJ_2.3 — Ripartizione per tecnico dell'unità</div>
      <table class="data-table" style="width:100%;font-size:11px">
        <thead><tr><th>Tecnico</th><th>Unità</th><th style="text-align:right">Interv.</th>
          <th style="text-align:right">Ore</th><th style="text-align:right">Fatturabili</th>
          <th style="text-align:right">Interne</th><th style="text-align:right">% fatt.</th>
          <th style="text-align:right">Addebitato</th><th style="text-align:right">A listino</th>
          <th style="text-align:right">Commesse</th></tr></thead>
        <tbody>
        <?php foreach ($o23Tec as $x): $qp = $x['quota_fatturabile_pct']; ?>
          <tr>
            <td><a href="<?=$qs(['tec'=>$x['tecnico']])?>" style="font-weight:600"><?=h($x['tecnico'])?></a></td>
            <td style="font-size:10px;color:var(--muted)"><?=h($x['unita'])?></td>
            <td style="text-align:right"><?=$n($x['interventi'])?></td>
            <td style="text-align:right;font-weight:700"><?=$n1($x['ore'])?></td>
            <td style="text-align:right;color:#16a34a"><?=$n1($x['ore_fatturabili'])?></td>
            <td style="text-align:right;color:#dc2626"><?=$n1($x['ore_interne'])?></td>
            <td style="text-align:right;font-weight:<?=($qp!==null&&(float)$qp<50)?'700':'400'?>;
                  color:<?=($qp!==null&&(float)$qp<50)?'#dc2626':'#334155'?>">
              <?=$qp!==null?$n1($qp).'%':'—'?></td>
            <td style="text-align:right"><?=$n1($x['valore_addebitato'])?></td>
            <td style="text-align:right;color:#7c3aed"><?=$n1($x['valore_listino'])?></td>
            <td style="text-align:right;color:var(--muted)"><?=$n($x['commesse'])?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:10px;padding-top:8px;
              border-top:1px solid #f1f5f9">
      I dati vengono dai <strong>moduli di intervento</strong>, non dai ticket: il modulo porta il
      riferimento alla commessa, il ticket no. Il conteggio è di <strong>interventi</strong> e non
      di ticket — un ticket può generare più moduli e un modulo coprire più ticket.
      Fatturabile o interna dipende dalla <strong>natura della commessa</strong>
      (<code>has_revenue</code>), non dal ticket. I tecnici sono quelli assegnati alle unità
      organizzative <em><?=h(implode(', ', array_unique(array_column($o23Tec, 'unita'))))?></em>.
    </p>
  </div>
<?php endif; ?>

<?php // ── v1.9.10 — OBJ_2: quadro del perimetro · OBJ_2.3: ripartizione ──── ?>
<?php if ($o2Lin): ?>
  <?php
    $colG = [
      'risolto dal Service Desk'                  => '#16a34a',
      'escalation di 2 livello verso specialisti' => '#f59e0b',
      'presa in carico diretta da specialisti'    => '#2563eb',
      'lavorato senza risposta scritta'           => '#7c3aed',
      'cliente senza risposta scritta'            => '#db2777',
      'mai preso in carico'                       => '#dc2626',
    ];
    $vTot = max(0.01, (float)($o2Q['valore_totale'] ?? 0));
  ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #0f766e">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-chart-pie"></i> Quadro del perimetro Service Desk</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=$n($o2Q['commesse'] ?? 0)?> commesse su <?=$n(count($o2Lin))?> linee di servizio ·
        perimetro configurabile</span>
    </div>

    <?php // OBJ_2 — gli indicatori economici ?>
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:12px">
      <?php foreach ([
        ['Valore totale', $n1(($o2Q['valore_totale'] ?? 0)/1000) . 'k', '#0f766e',
         $n($o2Q['commesse'] ?? 0) . ' commesse'],
        ['Valore aperte', $n1(($o2Q['valore_aperte'] ?? 0)/1000) . 'k', '#2563eb',
         $n($o2Q['commesse_aperte'] ?? 0) . ' aperte'],
        ['Margine', $n1(($o2Q['margine'] ?? 0)/1000) . 'k', '#16a34a',
         ($o2Q['margine_pct'] ?? null) !== null ? $n1($o2Q['margine_pct']) . '%' : ''],
        ['Clienti', $n($o2Q['clienti'] ?? 0), '#7c3aed', 'serviti'],
        ['Addetti', $n($o2Q['addetti_distinti'] ?? 0), '#f59e0b',
         ($o2Q['addetti_medi_mese'] ?? null) !== null
           ? $n1($o2Q['addetti_medi_mese']) . ' medi/mese' : 'nessun modulo'],
        ['Equivalenti T.P.', $n1($o2Q['fte_equivalenti'] ?? 0), '#334155',
         ($o2Q['ore_totali'] ?? null) !== null ? $n1($o2Q['ore_totali']) . ' h' : ''],
      ] as [$l, $v, $c, $sb]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:16px;font-weight:800;color:<?=$c?>"><?=$v?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <p style="font-size:11px;color:var(--muted);margin:-4px 0 10px">
      <strong>«Addetti»</strong> ha tre misure, tutte esposte: <strong><?=$n($o2Q['addetti_distinti'] ?? 0)?></strong>
      persone distinte, <strong><?=$n1($o2Q['addetti_medi_mese'] ?? 0)?></strong> di media mensile,
      <strong><?=$n1($o2Q['fte_equivalenti'] ?? 0)?></strong> equivalenti a tempo pieno.
      La prima sovrastima chi ha fatto un intervento solo, la terza dice quanto lavoro c'è stato.
    </p>

    <?php // OBJ_2 — la ripartizione del valore per linea ?>
    <div style="display:flex;height:22px;border-radius:5px;overflow:hidden;margin-bottom:8px">
      <?php $cL = ['#0f766e','#2563eb','#16a34a','#f59e0b','#7c3aed','#db2777','#334155'];
            foreach ($o2Lin as $i => $x): $qv = 100*(float)$x['valore']/$vTot;
              if ($qv < 0.4) continue; ?>
        <div style="width:<?=round($qv,2)?>%;background:<?=$cL[$i % count($cL)]?>"
             title="<?=h($x['codice_linea'])?>: <?=$n1($x['valore'])?> € (<?=$n1($qv)?>%)"></div>
      <?php endforeach; ?>
    </div>

    <table class="data-table" style="width:100%;font-size:11px">
      <thead><tr><th>Codice</th><th>Contratto</th>
        <th style="text-align:right">Commesse</th><th style="text-align:right">Aperte</th>
        <th style="text-align:right">Valore</th><th style="text-align:right">Quota</th>
        <th style="text-align:right">Costi</th><th style="text-align:right">Margine</th>
        <th style="text-align:right">Marg. %</th><th style="text-align:right">Clienti</th>
        <th>Natura</th></tr></thead>
      <tbody>
      <?php foreach ($o2Lin as $i => $x): $mp = $x['margine_pct']; ?>
        <tr>
          <td style="font-family:monospace;font-size:10px;font-weight:700">
            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;
                  background:<?=$cL[$i % count($cL)]?>;margin-right:5px"></span><?=h($x['codice_linea'])?></td>
          <td><?=h(mb_strimwidth((string)$x['contratto'], 0, 26, '…'))?></td>
          <td style="text-align:right"><?=$n($x['commesse'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$n($x['aperte'])?></td>
          <td style="text-align:right;font-weight:700"><?=$n1($x['valore'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$n1($x['quota_valore_pct'])?>%</td>
          <td style="text-align:right"><?=$n1($x['costi'])?></td>
          <td style="text-align:right;font-weight:700;
                color:<?=((float)$x['margine'])<0?'#dc2626':'#16a34a'?>"><?=$n1($x['margine'])?></td>
          <td style="text-align:right;font-weight:<?=($mp!==null&&(float)$mp<20)?'700':'400'?>;
                color:<?=($mp!==null&&(float)$mp<20)?'#dc2626':'#334155'?>"><?=$n1($mp)?>%</td>
          <td style="text-align:right;color:var(--muted)"><?=$n($x['clienti'])?></td>
          <td><span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;color:#fff;
                background:<?=$x['ha_ricavo']?'#16a34a':'#94a3b8'?>">
            <?=$x['ha_ricavo'] ? 'a ricavo' : 'interna'?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php // OBJ_2 — ticket gestiti contro escalati ?>
    <?php if ((int)($o2Q['ticket'] ?? 0) > 0): ?>
      <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:12px">
        <?php foreach ([
          ['Ticket del perimetro', $n($o2Q['ticket']), '#334155', ''],
          ['Presi in carico', $n($o2Q['ticket_presi']), '#2563eb',
           $n1(100*(float)$o2Q['ticket_presi']/max(1,(float)$o2Q['ticket'])) . '% del totale'],
          ['Risolti dal SD', $n($o2Q['ticket_risolti']), '#16a34a', ''],
          ['Escalati a specialisti', $n($o2Q['ticket_scalati']), '#f59e0b', ''],
          ['Tasso di escalation', $n1($o2Q['escalation_pct']) . '%', '#dc2626',
           'sui presi in carico'],
        ] as [$l2, $v2, $c2, $sb2]): ?>
          <div style="text-align:center;padding:10px;background:#f8fafc;border-radius:8px">
            <div style="font-size:16px;font-weight:800;color:<?=$c2?>"><?=$v2?></div>
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l2)?></div>
            <div style="font-size:10px;color:var(--muted)"><?=h($sb2)?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p style="font-size:11px;color:var(--muted);margin-top:6px">
        Il tasso di escalation è calcolato sui soli ticket <strong>presi in carico</strong>, non sul
        totale: un ticket mai preso non è stato né risolto né scalato, e includerlo al denominatore
        abbasserebbe il tasso per una ragione che non riguarda la capacità del primo livello.
      </p>
    <?php endif; ?>

    <?php // OBJ_2.3 — la ripartizione dei ticket ?>
    <?php if ($o23Rip): ?>
      <?php $tkTot = max(1, array_sum(array_map(fn($x)=>(int)$x['ticket'], $o23Rip))); ?>
      <div style="margin-top:14px">
        <div style="font-size:12px;font-weight:700;margin-bottom:6px">
          Ripartizione dei ticket per classe di gestione</div>
        <div style="display:flex;height:22px;border-radius:5px;overflow:hidden;margin-bottom:8px">
          <?php foreach ($o23Rip as $x): $qq = 100*(int)$x['ticket']/$tkTot; if ($qq<0.4) continue; ?>
            <div style="width:<?=round($qq,2)?>%;background:<?=$colG[$x['gestione']] ?? '#94a3b8'?>"
                 title="<?=h($x['gestione'])?>: <?=$n($x['ticket'])?> (<?=$n1($qq)?>%)"></div>
          <?php endforeach; ?>
        </div>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Classe di gestione</th><th style="text-align:right">Ticket</th>
            <th style="text-align:right">Quota</th><th style="text-align:right">Code</th>
            <th style="text-align:right">Msg medi</th>
            <th style="text-align:right">Durata media</th></tr></thead>
          <tbody>
          <?php foreach ($o23Rip as $x): ?>
            <tr>
              <td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                    background:<?=$colG[$x['gestione']] ?? '#94a3b8'?>;margin-right:5px"></span>
                <?=h($x['gestione'])?></td>
              <td style="text-align:right;font-weight:700"><?=$n($x['ticket'])?></td>
              <td style="text-align:right"><?=$n1($x['quota_pct'])?>%</td>
              <td style="text-align:right;color:var(--muted)"><?=$n($x['code'])?></td>
              <td style="text-align:right;color:var(--muted)"><?=$n1($x['messaggi_medi'])?></td>
              <td style="text-align:right;color:var(--muted)"><?=$n1($x['durata_media_ore'])?> h</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:10px;padding-top:8px;
              border-top:1px solid #f1f5f9">
      <strong>Perimetro</strong>: <?=h(implode(', ', array_column($o2Lin, 'codice_linea')))?>.
      È un parametro modificabile (<code>sd_linee_perimetro</code>): quali contratti siano
      «Service Desk» è una domanda aziendale, non tecnica.
      Il margine viene dal gestionale (<code>margin_total</code>), non ricostruito.
      <strong>La ripartizione fra ticket fatturabili e interni non è ancora disponibile</strong>:
      il ticket non porta un riferimento alla commessa, e senza una regola di raccordo
      l'attribuzione sarebbe inventata.
    </p>
  </div>
<?php endif; ?>

<?php // ── v1.9.7 — assenze del team ──────────────────────────────────────── ?>
<?php if ($assT): ?>
  <?php
    $colAss = ['ferie'=>'#7c3aed','permessi'=>'#0891b2','recuperi'=>'#f59e0b',
               'malattia'=>'#dc2626','visite'=>'#db2777'];
    $tAss = max(0.01, (float)($assQ['totale'] ?? 0));
  ?>
  <div class="card" style="margin-bottom:14px;border-left:4px solid #7c3aed">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-calendar-xmark"></i> Assenze del team</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=$n($assQ['persone'] ?? 0)?> persone<?= $tec !== '' ? ' — ' . h($tec) : '' ?>
        · le visite sono già comprese nelle altre voci</span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:10px">
      <?php foreach ([
        ['Totale assenze', $n1($assQ['totale'] ?? 0) . ' h', '#334155',
         $n1($assQ['giornate'] ?? 0) . ' giornate'],
        ['Ferie', $n1($assQ['ferie'] ?? 0) . ' h', $colAss['ferie'],
         $n1(100*(float)($assQ['ferie'] ?? 0)/$tAss) . '%'],
        ['Permessi', $n1($assQ['permessi'] ?? 0) . ' h', $colAss['permessi'],
         $n1(100*(float)($assQ['permessi'] ?? 0)/$tAss) . '%'],
        ['Recupero ore', $n1($assQ['recuperi'] ?? 0) . ' h', $colAss['recuperi'],
         $n1(100*(float)($assQ['recuperi'] ?? 0)/$tAss) . '%'],
        ['Malattia', $n1($assQ['malattia'] ?? 0) . ' h', $colAss['malattia'],
         $n1(100*(float)($assQ['malattia'] ?? 0)/$tAss) . '%'],
        ['Altre / non classif.', $n1($assQ['altre'] ?? 0) . ' h', '#64748b',
         ((float)($assQ['altre'] ?? 0)) > 0 ? 'tipo non riconosciuto' : ''],
      ] as [$l, $v, $c, $sb]): ?>
        <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
          <div style="font-size:16px;font-weight:800;color:<?=$c?>"><?=$v?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
          <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php // barra di composizione: le visite escluse perche' gia' contate ?>
    <div style="display:flex;height:20px;border-radius:5px;overflow:hidden;margin-bottom:10px">
      <?php foreach (['ferie','permessi','recuperi','malattia'] as $k):
        $qv = 100 * (float)($assQ[$k] ?? 0) / $tAss; if ($qv < 0.4) continue; ?>
        <div style="width:<?=round($qv,2)?>%;background:<?=$colAss[$k]?>"
             title="<?=h(ucfirst($k))?>: <?=$n1($assQ[$k])?> h (<?=$n1($qv)?>%)"></div>
      <?php endforeach; ?>
    </div>

    <table class="data-table" style="width:100%;font-size:11px">
      <thead><tr><th>Componente</th><th style="text-align:right">Ferie</th>
        <th style="text-align:right">Permessi</th><th style="text-align:right">Recuperi</th>
        <th style="text-align:right">Malattia</th><th style="text-align:right">Altre</th>
        <th style="text-align:right">Visite</th>
        <th style="text-align:right">Totale</th><th style="text-align:right">Giornate</th></tr></thead>
      <tbody>
      <?php foreach ($assT as $x): ?>
        <tr>
          <td><a href="<?=$qs(['tec'=>$x['tecnico']])?>" style="font-weight:600"><?=h($x['tecnico'])?></a></td>
          <td style="text-align:right;color:<?=$colAss['ferie']?>"><?=$n1($x['ferie'])?></td>
          <td style="text-align:right;color:<?=$colAss['permessi']?>"><?=$n1($x['permessi'])?></td>
          <td style="text-align:right;color:<?=$colAss['recuperi']?>"><?=$n1($x['recuperi'])?></td>
          <td style="text-align:right;color:<?=$colAss['malattia']?>;
                font-weight:<?=((float)$x['malattia'])>0?'700':'400'?>"><?=$n1($x['malattia'])?></td>
          <td style="text-align:right;color:#64748b"><?=$n1($x['altre'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$n1($x['visite'])?></td>
          <td style="text-align:right;font-weight:700"><?=$n1($x['totale'])?> h</td>
          <td style="text-align:right;color:var(--muted)"><?=$n1($x['giornate'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (count($assM) > 1): ?>
      <?php
        $mxA = 0.01; foreach ($assM as $x) $mxA = max($mxA, (float)$x['totale']);
        $WA=900; $HA=140; $pLA=46; $pRA=10; $pTA=8; $pBA=22;
        $pwA=$WA-$pLA-$pRA; $phA=$HA-$pTA-$pBA; $nbA=max(1,count($assM)); $bwA=$pwA/$nbA;
      ?>
      <div style="margin-top:12px">
        <div style="font-size:12px;font-weight:700;margin-bottom:4px">Andamento mensile</div>
        <svg viewBox="0 0 <?=$WA?> <?=$HA?>" style="width:100%;min-width:560px;height:auto;font-family:inherit">
          <?php for($g=0;$g<=2;$g++): $y=$pTA+$phA-$g*$phA/2; ?>
            <line x1="<?=$pLA?>" y1="<?=round($y,1)?>" x2="<?=$WA-$pRA?>" y2="<?=round($y,1)?>" stroke="#e2e8f0"/>
            <text x="<?=$pLA-4?>" y="<?=round($y+3,1)?>" text-anchor="end" font-size="8" fill="#94a3b8">
              <?=$n(round($mxA*$g/2))?></text>
          <?php endfor; ?>
          <?php foreach($assM as $i=>$x):
            $x0=$pLA+$i*$bwA+$bwA*0.15; $bx=max(1.5,$bwA*0.7); $base=$pTA+$phA;
            foreach (['ferie','permessi','recuperi','malattia'] as $k):
              $hv=(float)($x[$k] ?? 0)/$mxA*$phA; if ($hv<=0) continue; $base-=$hv; ?>
              <rect x="<?=round($x0,1)?>" y="<?=round($base,1)?>" width="<?=round($bx,1)?>"
                    height="<?=round($hv,1)?>" fill="<?=$colAss[$k]?>">
                <title><?=h($x['ym'])?> — <?=h(ucfirst($k))?>: <?=$n1($x[$k])?> h</title></rect>
            <?php endforeach; ?>
            <?php if($i % max(1,intdiv($nbA,10))===0): ?>
              <text x="<?=round($x0+$bx/2,1)?>" y="<?=$HA-6?>" text-anchor="middle" font-size="8" fill="#64748b">
                <?=h(substr((string)$x['ym'],2))?></text>
            <?php endif; ?>
          <?php endforeach; ?>
        </svg>
        <div style="font-size:10px;color:var(--muted)">
          <?php foreach (['ferie'=>'Ferie','permessi'=>'Permessi','recuperi'=>'Recupero ore','malattia'=>'Malattia'] as $k=>$lb): ?>
            <span style="display:inline-block;width:10px;height:8px;background:<?=$colAss[$k]?>;margin-left:<?=$k==='ferie'?'0':'10px'?>"></span> <?=h($lb)?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:8px">
      Le <strong>visite</strong> sono contate a parte e <strong>non entrano nel totale</strong>: le
      loro ore sono già comprese nelle altre voci, perché nel gestionale non esiste un tipo dedicato
      e vengono riconosciute dalla descrizione. Sommarle conterebbe due volte le stesse ore.
      <strong>Altre</strong> è la parte di totale che le quattro voci non spiegano — un tipo di
      assenza non classificato: esposta invece che lasciata implicita, perché una differenza
      silenziosa fra totale e somma fa sospettare un errore di calcolo dove c'è solo una categoria in
      più. Le giornate sono calcolate su 8 ore.
    </p>
  </div>
<?php endif; ?>

<?php // ── v1.8.92 — moduli del Service Desk per codice di linea ─────────── ?>
<?php if ($codLin): ?>
  <?php $totOreL = 0; foreach ($codLin as $c) $totOreL += (float)$c['ore']; $totOreL = max(0.01, $totOreL); ?>
  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-diagram-project"></i>
        Moduli di intervento per codice linea</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?= $tec !== '' ? h($tec) : 'tutto il Service Desk' ?> —
        attività distinta dai ticket, non sommabile</span>
    </div>

    <div style="display:flex;height:22px;border-radius:5px;overflow:hidden;margin-bottom:10px">
      <?php foreach ($codLin as $i => $c): $q = 100*(float)$c['ore']/$totOreL; if ($q < 0.5) continue; ?>
        <div style="width:<?=round($q,2)?>%;background:<?=$c['ha_ricavo'] ? '#16a34a' : '#94a3b8'?>;
             border-right:1px solid #fff"
             title="<?=h($c['codice'])?> — <?=h($c['etichetta'])?>: <?=$n1($c['ore'])?> h"></div>
      <?php endforeach; ?>
    </div>

    <table class="data-table" style="width:100%;font-size:11px">
      <thead><tr><th>Codice</th><th>Linea di servizio</th>
        <th style="text-align:right">Moduli</th><th style="text-align:right">Ore</th>
        <th style="text-align:right">Quota</th><th style="text-align:right">Tecnici</th>
        <th style="text-align:right">Commesse</th><th>Natura</th></tr></thead>
      <tbody>
      <?php foreach ($codLin as $c): $q = 100*(float)$c['ore']/$totOreL; ?>
        <tr>
          <td style="font-family:monospace;font-weight:700"><?=h($c['codice'])?></td>
          <td><?=h($c['etichetta'])?></td>
          <td style="text-align:right"><?=$n($c['moduli'])?></td>
          <td style="text-align:right;font-weight:700"><?=$n1($c['ore'])?> h</td>
          <td style="text-align:right"><?=$n1($q)?>%</td>
          <td style="text-align:right;color:var(--muted)"><?=$n($c['tecnici'])?></td>
          <td style="text-align:right;color:var(--muted)"><?=$n($c['commesse'])?></td>
          <td><span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:8px;color:#fff;
                background:<?=$c['ha_ricavo']?'#16a34a':'#94a3b8'?>">
            <?=$c['ha_ricavo'] ? 'a ricavo' : 'interna'?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="font-size:11px;color:var(--muted);margin-top:6px">
      Il <strong>codice</strong> è quello che compare sui documenti e nel gestionale;
      l'etichetta è la stessa cosa in forma leggibile. Verde le linee a ricavo, grigio quelle interne.
    </p>

    <?php // v1.8.93 — azienda esecutrice, dal prefisso del codice commessa ?>
    <?php if (count($aziende) > 1): ?>
      <div style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:10px">
        <div style="font-size:12px;font-weight:700;margin-bottom:6px">Per azienda esecutrice
          <span style="font-weight:400;color:var(--muted)">— dal prefisso del codice commessa</span></div>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Azienda</th><th style="text-align:right">Moduli</th>
            <th style="text-align:right">Ore</th><th style="text-align:right">Quota</th>
            <th style="text-align:right">Tecnici</th><th style="text-align:right">Commesse</th>
            <th style="text-align:right">Linee</th></tr></thead>
          <tbody>
          <?php $tAz = 0; foreach ($aziende as $a) $tAz += (float)$a['ore']; $tAz = max(0.01, $tAz); ?>
          <?php foreach ($aziende as $i => $a): $q = 100*(float)$a['ore']/$tAz; ?>
            <tr>
              <td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                    background:<?=$COLAZ[$i % count($COLAZ)]?>;margin-right:6px"></span>
                <strong><?=h($a['azienda'])?></strong></td>
              <td style="text-align:right"><?=$n($a['moduli'])?></td>
              <td style="text-align:right;font-weight:700"><?=$n1($a['ore'])?> h</td>
              <td style="text-align:right"><?=$n1($q)?>%</td>
              <td style="text-align:right;color:var(--muted)"><?=$n($a['tecnici'])?></td>
              <td style="text-align:right;color:var(--muted)"><?=$n($a['commesse'])?></td>
              <td style="text-align:right;color:var(--muted)"><?=$n($a['linee'])?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ── operatori e code ─────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1.3fr 1fr;gap:14px">
  <div class="card">
    <div class="card-header"><span class="card-title">
      <i class="fa-solid fa-users"></i> Operatività per tecnico</span></div>
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Tecnico</th><th>Livello</th><th style="text-align:right">Messaggi</th>
        <th style="text-align:right">Risposte</th><th style="text-align:right">Ticket</th>
        <th style="text-align:right">Code</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($ops, 0, 15) as $o): ?>
        <tr>
          <td><a href="<?=$qs(['tec'=>$o['tecnico']])?>" style="font-weight:600"><?=h($o['tecnico'])?></a>
            <?php if ($o['sotto_unita']): ?>
              <span style="font-size:10px;color:var(--muted)">· <?=h($o['sotto_unita'])?></span>
            <?php endif; ?></td>
          <td><span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:8px;color:#fff;
                background:<?=$o['livello']==='L1'?'#16a34a':'#2563eb'?>"><?=h($o['livello'])?></span></td>
          <td style="text-align:right"><?=$n($o['messaggi'])?></td>
          <td style="text-align:right"><?=$n($o['risposte'])?></td>
          <td style="text-align:right"><?=$n($o['ticket'])?></td>
          <td style="text-align:right;font-weight:<?=(int)$o['code']>6?'700':'400'?>"><?=$n($o['code'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="font-size:11px;color:var(--muted);margin-top:6px">
      Il numero di <strong>code</strong> presidiate distingue i ruoli: il primo livello smista
      trasversalmente su molte code, gli specialisti presidiano il proprio dominio.
    </p>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">
      <i class="fa-solid fa-inbox"></i> Volumi per coda</span></div>
    <table class="data-table" style="width:100%;font-size:12px">
      <thead><tr><th>Coda</th><th style="text-align:right">Ticket</th>
        <th style="text-align:right">Con L1</th><th style="text-align:right">Scoperti</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($code, 0, 15) as $c): ?>
        <tr>
          <td><?=h($c['coda'])?></td>
          <td style="text-align:right"><?=$n($c['ticket'])?></td>
          <td style="text-align:right;color:#16a34a"><?=$n($c['con_l1'])?></td>
          <td style="text-align:right;color:<?=(int)$c['scoperti']>0?'#dc2626':'var(--muted)'?>">
            <?=$n($c['scoperti'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once('footer.php'); ?>
