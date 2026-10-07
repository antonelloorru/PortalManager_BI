<?php
/**
 * PortalManager — app/it_service_report.php  (v1.10.13)
 * Relazione di Servizio IT come report multi-formato (DOCX, XLSX, CSV, PDF) dal SOLO filtro principale ($f di
 * ItServiceModel::normFilters) e dalle sezioni scelte («Dettagli da includere»). Contenuto e logica sono quelli
 * del report Word della v1.9.46 (stessa struttura della stampa), registrati tramite PmReportDoc: un solo testo
 * del report per tutti i formati, per il report generale e per i report singoli per incaricato (filtro incaricati = [nome]).
 */
declare(strict_types=1);

require_once __DIR__ . '/ItServiceModel.php';
require_once __DIR__ . '/PmReportDoc.php';

/** Incaricati del perimetro (report singoli): nome sul modulo → nome. */
function it_service_report_tecnici(ItServiceModel $it, array $f): array
{
    $n = array_keys($it->incaricatiDipendenti($f));
    sort($n, SORT_NATURAL | SORT_FLAG_CASE);
    return array_combine($n, $n) ?: [];
}

function it_service_report(ItServiceModel $it, array $f, array $vCtr, array $inc, string $fmt, ?float $targetOverride = null): PmReport
{
    $incOn = fn(string $k): bool => in_array($k, $inc, true);
    $hh  = fn($v) => number_format((float)$v, 0, ',', '.');
    $hh1 = fn($v) => $v === null ? '—' : number_format((float)$v, 1, ',', '.');
    $_GET_target = $targetOverride;

    // dati: stesse letture della pagina, sullo stesso filtro
    $tot   = $it->totali($f);
    $righe = $it->aggrega($f, in_array($fmt, ['xlsx', 'csv'], true) ? 20000 : 500);
    $trend = $it->andamento($f);
    $km    = $it->statoKm($f);
    $gMod  = $it->perDimensione($f, 'modalita');
    $gLin  = $it->perDimensione($f, 'linea_label', 10);
    $gSet  = $it->perDimensione($f, 'settore', 10);
    $gDur  = $it->perDimensione($f, 'durata');
    $gFas  = $it->perDimensione($f, 'fascia_oraria');
    $cQ2   = $it->costiQuadro($f);
    $cRie2 = $it->costiRiepilogo($f);
    $gQ    = $it->giorniQuadro($f);
    $gOp   = $it->giorniOperatore($f);
    $gAr   = $it->giorniArea($f);
    $gCls  = $it->classiPerPersona($f);
    $gNv   = $it->nonValorizzate($f);
    $gDim  = [];
    foreach (array_keys(ItServiceModel::GIORNI_DIM) as $gd) $gDim[$gd] = $it->giorniPer($f, $gd);
    $dettCommessa  = $incOn('commesse') ? $it->dettaglioCommessa($f) : [];
    $riepContratto = $it->riepilogoContratto($f);
    $nSm    = $it->attivitaSenzaModulo($f);
    $smDett = $incOn('senzamodulo') ? $it->attivitaSenzaModuloDettaglio($f) : [];

    // stessa logica del report di stampa: scheda personale + filtri applicati
    $isPers  = (count($f['incaricati'] ?? []) === 1);
    $persona = $isPers ? $f['incaricati'][0] : '';
    $filtri = $it->descrizioneFiltri($f, $vCtr);   // v1.9.77

    $eur   = fn($v) => '€ ' . number_format((float)$v, 2, ',', '.');
    $gbLbl = implode(' × ', array_map(fn($g) => ItServiceModel::DIM[$g], $f['gb']));

    $doc = new PmReportDoc('Relazione di Servizio IT' . ($isPers ? ' — scheda personale' : ''), $fmt);
    if ($isPers) $doc->paragraph($persona, ['size'=>26,'bold'=>true,'color'=>'0F766E','after'=>120]);
    $doc->meta('Periodo ' . date('d/m/Y', strtotime($f['from'])) . ' – ' . date('d/m/Y', strtotime($f['to']))
        . '   ·   generato il ' . date('d/m/Y H:i') . '   ·   raggruppamento: ' . $gbLbl);
    if ($filtri) $doc->box('Filtri applicati: ' . implode('   ·   ', $filtri));

    // QUADRO — KPI + Ripartizione dell'operatività
    if ($incOn('quadro')) {
        $oreTot = (float)($tot['ore'] ?? 0);
        $doc->kpi([
            ['label'=>'Interventi','value'=>$hh($tot['interventi'] ?? 0),'color'=>'334155','sub'=>$hh($tot['commesse'] ?? 0).' commesse'],
            ['label'=>'Giornate-uomo','value'=>$hh($tot['giornate_uomo'] ?? 0),'color'=>'2563EB','sub'=>$hh($tot['incaricati'] ?? 0).' incaricati'],
            ['label'=>'Ore','value'=>$hh1($tot['ore'] ?? 0),'color'=>'16A34A','sub'=>(($tot['ore_medie_giornata'] ?? null)!==null ? $hh1($tot['ore_medie_giornata']).' h/giornata' : '')],
            ['label'=>'Ore a ricavo','value'=>$hh1($tot['ore_ricavo'] ?? 0),'color'=>'0D9488','sub'=>($oreTot>0 ? $hh1(100*(float)($tot['ore_ricavo'] ?? 0)/$oreTot).'%' : '')],
            ['label'=>'Ore di viaggio','value'=>$hh1($tot['ore_viaggio'] ?? 0),'color'=>'F59E0B','sub'=>$hh($km['trasferte'] ?? 0).' trasferte'],
            ['label'=>'Km percorsi','value'=>((float)($tot['km'] ?? 0)>0 ? $hh1($tot['km']) : '—'),'color'=>'7C3AED','sub'=>(($km['copertura_pct'] ?? null)!==null ? 'copertura '.$hh1($km['copertura_pct']).'%' : 'non rilevati')],
        ]);
        if ((int)($km['trasferte'] ?? 0) > 0 && (int)($km['con_km'] ?? 0) === 0) {
            $doc->box('Chilometri non rilevati per le ' . $hh($km['trasferte']) . ' trasferte del periodo. Registrate ' . $hh1($km['ore_viaggio']) . ' ore di viaggio, che misurano lo stesso fenomeno con un dato reale.', 'F59E0B', 'FFFBEB');
        }
        $doc->heading('Ripartizione dell\'operatività', 1);
        $mkOre = fn($rows) => array_map(fn($d) => [(string)$d['voce'], (float)$d['ore'], $hh1($d['ore']).' h'], $rows);
        $mkInt = fn($rows) => array_map(fn($d) => [(string)$d['voce'], (float)$d['interventi'], $hh($d['interventi'])], $rows);
        if ($gMod) $doc->bars($mkOre($gMod), ['title'=>'Ore per modalità','color'=>'2563EB']);
        if ($gLin) $doc->bars($mkOre($gLin), ['title'=>'Ore per linea di servizio','color'=>'0D9488']);
        if ($gSet) $doc->bars($mkOre($gSet), ['title'=>'Ore per settore tecnologico','color'=>'7C3AED']);
        if ($gDur) $doc->bars($mkInt($gDur), ['title'=>'Interventi per durata','color'=>'2563EB']);
        if ($gFas) $doc->bars($mkInt($gFas), ['title'=>'Interventi per fascia oraria','color'=>'16A34A']);
    }

    // ANDAMENTO MENSILE
    if ($incOn('andamento') && count($trend) > 1) {
        $doc->heading('Andamento mensile', 1);
        $sO=0.0; foreach ($trend as $t) $sO += (float)($t['ore_ordinarie'] ?? 0);
        $target = $_GET_target !== null ? $_GET_target : round($sO / max(1,count($trend)));
        $doc->note('Target «ore ordinarie lavorative»: ' . $hh($target) . ' h/mese (media del periodo, salvo override).');
        $segRows = array_map(function ($t) use ($hh1) {
            $ore = (float)$t['ore']; $rep = (float)($t['ore_reperibilita'] ?? 0);
            $fuori = min((float)$t['ore_fuori'], max(0.0, $ore - $rep));
            $ord = max(0.0, $ore - $rep - $fuori);
            return [(string)$t['ym'], [$ord, $fuori, $rep], $hh1($ore).' h'];
        }, $trend);
        $doc->stackedbars($segRows, [
            ['label'=>'ore ordinarie','color'=>'2563EB'],
            ['label'=>'fuori orario','color'=>'F59E0B'],
            ['label'=>'reperibilità','color'=>'7C3AED'],
        ]);
        $rr = [];
        foreach ($trend as $t) $rr[] = [$t['ym'], $hh1($t['ore']), $hh1($t['ore_ordinarie'] ?? 0), $hh1($t['ore_reperibilita'] ?? 0), $hh1($t['ore_fuori']), $hh($t['giornate_uomo'])];
        $doc->table(['Mese','Ore','Ore ordinarie','Reperibilità','Fuori orario','Giornate-uomo'], $rr, ['right'=>[1,2,3,4,5]]);
    }

    // DETTAGLIO (pivot) — nuova pagina, come nel PDF
    if ($incOn('dettaglio')) {
        $doc->pageBreak();
        $doc->heading('Dettaglio — ' . $gbLbl, 1);
        $hdr = array_map(fn($g) => ItServiceModel::DIM[$g], $f['gb']);
        $hdr = array_merge($hdr, ['Interv.','Gg-uomo','Ore','Ordin.','F.orario h','Reperib. h','N. rep.','Extra','Viaggio','Km','N. cliente','N. remoto','N. smart']);
        $rr = [];
        foreach (($fmt === 'xlsx' || $fmt === 'csv') ? $righe : array_slice($righe, 0, 300) as $r) {
            $row = [];
            foreach ($f['gb'] as $g) $row[] = mb_strimwidth((string)$r[$g], 0, 30, '…');
            $row = array_merge($row, [$hh($r['interventi']),$hh($r['giornate_uomo']),$hh1($r['ore']),$hh1($r['ore_ordinarie']),$hh1($r['ore_fuori_orario']),
                $hh1($r['ore_reperibilita']),$hh($r['reperibilita']),$hh1($r['ore_extra']),$hh1($r['ore_viaggio']),
                ((float)$r['km']>0?$hh1($r['km']):'—'),$hh($r['presso_cliente']),$hh($r['da_remoto']),$hh($r['smart_working'])]);
            $rr[] = $row;
        }
        $ng = count($f['gb']);
        $doc->table($hdr, $rr, ['right'=>range($ng, $ng+12)]);
        if (count($righe) > 300 && $fmt !== 'xlsx' && $fmt !== 'csv') $doc->note('Mostrate le prime 300 righe di ' . $hh(count($righe)) . '. XLSX e CSV le contengono tutte.');
    }

    // GIORNI LAVORATI
    if ($incOn('giorni') && $gOp) {
        $doc->heading('Giorni lavorati' . ($isPers ? ' — '.$persona : ' per persona'), 1);
        $doc->kpi([
            ['label'=>'Operatori','value'=>$hh($gQ['operatori'] ?? 0),'color'=>'0F766E'],
            ['label'=>'Giorni-uomo','value'=>$hh($gQ['giorni_uomo'] ?? 0),'color'=>'2563EB'],
            ['label'=>'Ore','value'=>$hh1($gQ['ore'] ?? 0),'color'=>'334155'],
            ['label'=>'Ore non valorizzate','value'=>$hh1($gQ['ore_non_valorizzate'] ?? 0),'color'=>'64748B'],
            ['label'=>'Ore fuori orario','value'=>$hh1($tot['ore_fuori_orario'] ?? 0),'color'=>'B45309'],
            ['label'=>'Ore reperibilità','value'=>$hh1($tot['ore_reperibilita'] ?? 0),'color'=>'7C3AED'],
        ]);
        $rr = [];
        foreach ($gOp as $x) { $cl = $gCls[$x['operatore']] ?? [];
            $rr[] = [$x['operatore'],$hh($x['giorni_lavorati']),$hh1($x['ore']),$hh1($cl['ore_ordinarie'] ?? 0),$hh1($cl['ore_fuori_orario'] ?? 0),
            $hh1($cl['ore_reperibilita'] ?? 0),$hh1($x['ore_valorizzate']),$hh1($x['ore_non_valorizzate']),
            ($x['produzione_teorica']!==null?$hh1($x['produzione_teorica']):'—')]; }
        $doc->table(['Operatore','Giorni','Ore totali','Ordinarie','Fuori orario','Reperibilità','Valorizzate','Non valoriz.','Produzione teorica'], $rr, ['right'=>[1,2,3,4,5,6,7,8]]);
        if ($gNv) {
            $doc->heading('Ore non valorizzate', 2);
            $rr = []; foreach (array_slice($gNv, 0, 300) as $x) $rr[] = [$x['codice_linea'], $x['commessa'], $x['operatore'], $hh($x['giorni']), $hh1($x['ore']), $x['motivo']];
            $doc->table(['Codice linea','Commessa','Persona','Giorni','Ore','Motivo'], $rr, ['right'=>[3,4]]);
        }
        foreach (['codice_linea', 'area_tecnologica'] as $gd) {
            if (empty($gDim[$gd])) continue;
            $doc->heading('Giorni per ' . mb_strtolower(ItServiceModel::GIORNI_DIM[$gd]), 2);
            $rr = []; foreach ($gDim[$gd] as $x) $rr[] = [ItServiceModel::etichetta((string)$x['voce']), $hh($x['persone']), $hh($x['giorni_uomo']),
                $hh1($x['ore']), $hh1($x['ore_valorizzate']), $hh1($x['ore_non_valorizzate']), ($x['produzione_teorica']!==null?$hh1($x['produzione_teorica']):'—')];
            $doc->table([ItServiceModel::GIORNI_DIM[$gd],'Persone','Giorni-uomo','Ore','Valorizzate','Non valorizzate','Produzione teorica'], $rr, ['right'=>[1,2,3,4,5,6]]);
        }
        if ($gAr) {
            $doc->heading('Ripartizione per area tecnologica', 2);
            $rr=[]; foreach ($gAr as $x) $rr[]=[$x['operatore'],$x['area_tecnologica'],$hh($x['giorni']),$hh($x['interventi']),$hh1($x['ore']),$hh1($x['quota_ore_pct']).'%',($x['produzione_teorica']!==null?$hh1($x['produzione_teorica']):'—')];
            $doc->table(['Operatore','Area tecnologica','Giorni','Interventi','Ore','Quota','Produzione teorica'], $rr, ['right'=>[2,3,4,5,6]]);
        }
        $doc->note('«Giorni lavorati» sono giorni distinti: due interventi nello stesso giorno contano una volta. Ore per classe: ordinarie + fuori orario + reperibilità = ore totali (stessa regola del resto della relazione). Produzione teorica = ore × listino. Perimetro: tutto l\'eseguito nel periodo per data del modulo, ore non valorizzate (senza tariffa) comprese.');
    }

    // COSTI
    if ($incOn('costi') && $cRie2) {
        $doc->heading('Riepilogo costi per fascia e contratto' . ($isPers ? ' — '.$persona : ''), 1);
        $doc->kpi([
            ['label'=>'Interventi','value'=>$hh($cQ2['interventi'] ?? 0),'color'=>'065F46'],
            ['label'=>'Ore','value'=>$hh1($cQ2['ore'] ?? 0),'color'=>'334155'],
            ['label'=>'Valore totale','value'=>$hh1($cQ2['valore'] ?? 0),'color'=>'0F766E'],
            ['label'=>'Orario ordinario','value'=>$hh1($cQ2['valore_ordinario'] ?? 0),'color'=>'16A34A','sub'=>$hh1($cQ2['ore_ordinario'] ?? 0).' h'],
            ['label'=>'Extra-orario','value'=>$hh1($cQ2['valore_extra'] ?? 0),'color'=>'F59E0B','sub'=>$hh1($cQ2['ore_extra'] ?? 0).' h'],
            ['label'=>'Commesse','value'=>$hh($cQ2['commesse'] ?? 0),'color'=>'7C3AED'],
        ]);
        $rr=[]; foreach ($cRie2 as $x) $rr[]=[$x['codice_linea'],$x['descrizione_tariffa'],ItServiceModel::reperibilitaTesto($x['reperibilita']),$hh($x['interventi']),$hh1($x['ore']),($x['tariffa_ora']!==null?$eur($x['tariffa_ora']):'—'),$eur($x['valore'])];
        $doc->table(['Linea','Tariffa','Reper.','Interv.','Ore','Tariffa/ora','Valore'], $rr, ['right'=>[3,4,5,6]]);
    }

    // RIEPILOGO PER CODICE CONTRATTO
    if ($incOn('contratti') && $riepContratto) {
        $doc->heading('Riepilogo per Codice Contratto', 1);
        $rr=[]; foreach ($riepContratto as $x) $rr[]=[$x['codice_contratto'],(string)($x['pm_project_code'] ?? ''),$hh1($x['ore_ordinarie']),$hh1($x['ore_straordinario']),$hh1($x['ore_reperibilita']),$hh($x['giorni_uomo']),$eur($x['costo_contratto']),$eur($x['tot_costo_tab'])];
        $doc->table(['Codice contratto','PM','Ord.','Str.','Rep.','Gg-uomo','Costo','Tabella'], $rr, ['right'=>[2,3,4,5,6,7]]);
    }

    // DETTAGLIO PER COMMESSA — raggruppato per contratto, come nel PDF
    if ($incOn('commesse') && $dettCommessa) {
        $doc->heading('Dettaglio per Commessa', 1);
        $byC = [];
        foreach ($dettCommessa as $r) $byC[$r['contract_id']][] = $r;
        foreach ($byC as $rows) {
            $first  = $rows[0];
            $intest = implode(' | ', array_filter([$first['contract_code'] ?? '', $first['code_x_installation'] ?? '', $first['customer_name'] ?? '', $first['contract_description'] ?? ''], fn($v) => $v !== null && $v !== ''));
            $tOre = 0.0; $tTab = 0.0; foreach ($rows as $r) { $tOre += (float)$r['ore']; $tTab += (float)$r['tot_costo_tab']; }
            $doc->heading(($intest !== '' ? $intest : 'Contratto') . '   —   ' . count($rows) . ' · ' . $hh1($tOre) . ' h · ' . $eur($tTab), 3);
            $rr = [];
            foreach ($rows as $r) $rr[] = [(string)$r['report_date'], $r['operator_name'], ((string)($r['ticket'] ?? '') ?: '—'), $r['fascia'], $r['regime'], $hh1($r['ore']), $eur($r['costo_contratto']), $eur($r['tot_costo_tab'])];
            $doc->table(['Data','Operatore','Ticket','Fascia','Regime','Ore','Costo','Tabella'], $rr, ['right'=>[5,6,7]]);
        }
    }

    // ATTIVITA' DGB SENZA MODULO — v1.9.91
    if ($incOn('senzamodulo') && $smDett) {
        $doc->heading('Attività DGB senza modulo di intervento', 1);
        $rr = []; foreach ($nSm['motivi'] as $x) $rr[] = [$x['motivo'], $hh($x['attivita']), $hh1($x['ore']), $hh($x['contratti']), $hh($x['operatori'])];
        $doc->table(['Motivo','Attività','Ore','Contratti','Operatori'], $rr, ['right'=>[1,2,3,4]]);
        $rr = []; foreach (array_slice($smDett, 0, 300) as $x) $rr[] = [$x['contratto'], (string)$x['codice_linea'], $x['operatore'], $x['motivo'], $hh($x['attivita']), $hh1($x['ore']),
            date('d/m', strtotime($x['dal'])) . '–' . date('d/m', strtotime($x['al']))];
        $doc->table(['Contratto','Linea','Operatore','Motivo','Attività','Ore','Periodo'], $rr, ['right'=>[4,5]]);
        $doc->note('Attività del periodo (data attività) senza modulo di intervento: non entrano nei totali della relazione. Ore = allocate o pianificate.');
    }

    return $doc->report();
}
