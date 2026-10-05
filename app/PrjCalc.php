<?php
/**
 * PortalManager — app/PrjCalc.php  (v1.10.00)
 *
 * Motore di calcolo dell'Analisi Gara & Dimensionamento dei Progetti PRJ.
 * Classe PURA: nessun accesso al DB, nessuna dipendenza dalla UI. Riceve l'input
 * costruito da PrjRepo::input() (dati "as-of" di progetto, parametri e scenario)
 * e restituisce array di risultati. Importi interni in €; la UI li espone in k€.
 *
 * Formule (Technical Design v1.9.99 §5, decisioni del 05/10/2026):
 *
 *  5.1 Carico ticket per servizio
 *      ore_servizio        = Σ_gruppi Σ_tipo volume[g,t,anno] × quota[g,s] × AHT[t,s]   (AHT del servizio, altrimenti generale)
 *      fte_ticket          = ore_servizio / ore_utili_fte
 *      fte_stimato         = fte_ticket × (1 + uplift)
 *      fte_floor           = Σ n_minimo dei profili obbligatori del servizio
 *      fte_finale          = MAX(fte_stimato, Σ fte allocati ai profili del servizio)
 *
 *  5.2 Costo per profilo
 *      ral_rif   = min | ideale | (min+ideale)/2                         (ral_mode dello scenario)
 *      ral_zona  = ral_rif × indice_zona / indice_zona_base              (nearshore: × indice_paese / indice_zona_base)
 *      oneri     = oneri_pct (nearshore: oneri del paese)
 *      costo_az_fte = ral_zona × (1 + oneri) + (profilo H24 ? indennita_h24 : 0)
 *      costo_personale_profilo = fte × ral_zona · costo_aziendale_profilo = fte × costo_az_fte
 *      costo_giornaliero = costo_az_fte / giorni_fte
 *
 *  5.3 Costi strutturali
 *      dotazione_fte = Σ prezzo / anni_ammortamento
 *      affitto_fte   = mq × occupazione × affitto_zona × 12 × (1 + oneri_accessori)
 *      energia_fte   = kwh_postazione × prezzo_kwh
 *      ufficio_fte   = dotazione + affitto + energia · remoto_fte = dotazione
 *      remoto: FTE marcati remoti nello scenario e FTE in nearshore
 *      costi_sede    = n_sedi × Σ eur_mese × 12
 *
 *  5.4 Overhead = Σ voci fisse + Σ voci per_fte × fte_totali
 *
 *  5.5 Totali scenario
 *      costo_aziendale_personale = Σ ral + Σ oneri + Σ indennita
 *      costo_aziendale_totale    = costo_aziendale_personale + strutturali + overhead
 *      canone_medio = media canone anni · canone_netto = canone_medio × (1 − ribasso)
 *      pct_canone = costo_totale / canone_netto · margine = canone_netto − costo_totale · margine_pct = margine / canone_netto
 *      valore_punto_ribasso = canone_medio / 100 · ribasso_max_pareggio = 1 − costo_totale / canone_medio
 *      fte_finanziabili = canone_netto × (1 − margine_target) / (costo_totale / fte_totali)
 *      Sostenibile: obbligatori + fte_supporto_sostenibile ripartiti in proporzione sui supporto + governance.
 *      Completo: tutti i profili con gli FTE allocati.
 *
 *  5.6 Penali · 5.7 Punteggio · Conguaglio banda volumi ±20%
 */
declare(strict_types=1);

require_once __DIR__ . '/FormulaEval.php';

final class PrjCalc
{
    public const RAL_MODES = ['min', 'ideale', 'media'];

    /* ───────────────────────── 5.1 Carico ticket ───────────────────────── */

    /**
     * @param array $in  chiavi: volumes[[group_id,year,tipo,quantita]], mapping[[group_id,service_id,quota]],
     *                   aht[[tipo,service_id,ore]], services[ent_id=>[codice,…]], productivity[ore_utili_fte,uplift],
     *                   alloc[service_id=>fte allocati], floor[service_id=>n_minimo obbligatori], year
     * @return array{rows:array<int,array>, totali:array}
     */
    public static function ticketLoad(array $in): array
    {
        $year = (int)($in['year'] ?? 0);
        $ore  = (float)($in['productivity']['ore_utili_fte'] ?? 1600);
        $up   = (float)($in['productivity']['uplift'] ?? 0);
        $ahtG = []; $ahtS = [];
        foreach ($in['aht'] ?? [] as $a) {
            if ((int)$a['service_id'] === 0) $ahtG[$a['tipo']] = (float)$a['ore'];
            else $ahtS[(int)$a['service_id']][$a['tipo']] = (float)$a['ore'];
        }
        $map = [];
        foreach ($in['mapping'] ?? [] as $m) $map[(int)$m['group_id']][(int)$m['service_id']] = (float)$m['quota'];

        $rows = [];
        foreach ($in['services'] ?? [] as $sid => $s) {
            $rows[(int)$sid] = ['service_id' => (int)$sid, 'codice' => $s['codice'], 'nome' => $s['nome'] ?? '',
                                'ticket' => 0.0, 'ticket_per_tipo' => ['CTASK' => 0.0, 'INC' => 0.0, 'SCTASK' => 0.0], 'ore' => 0.0];
        }
        $nonMappati = 0.0;
        foreach ($in['volumes'] ?? [] as $v) {
            if ($year && (int)$v['year'] !== $year) continue;
            $g = (int)$v['group_id']; $t = $v['tipo']; $q = (float)$v['quantita'];
            if (empty($map[$g])) { $nonMappati += $q; continue; }
            foreach ($map[$g] as $sid => $quota) {
                if (!isset($rows[$sid])) continue;
                $aht = $ahtS[$sid][$t] ?? $ahtG[$t] ?? 0.0;
                $rows[$sid]['ticket'] += $q * $quota;
                $rows[$sid]['ticket_per_tipo'][$t] += $q * $quota;
                $rows[$sid]['ore'] += $q * $quota * $aht;
            }
        }
        $tot = ['ticket' => 0.0, 'ore' => 0.0, 'fte_ticket' => 0.0, 'fte_uplift' => 0.0, 'fte_allocati' => 0.0, 'fte_floor' => 0.0, 'fte_finale' => 0.0,
                'ticket_non_mappati' => $nonMappati];
        foreach ($rows as $sid => &$r) {
            $r['fte_ticket']   = $ore > 0 ? $r['ore'] / $ore : 0.0;
            $r['fte_uplift']   = $r['fte_ticket'] * (1 + $up);
            $r['fte_allocati'] = (float)($in['alloc'][$sid] ?? 0);
            $r['fte_floor']    = (float)($in['floor'][$sid] ?? 0);
            $r['fte_finale']   = max($r['fte_uplift'], $r['fte_allocati']);
            $r['scostamento']  = $r['fte_allocati'] - $r['fte_uplift'];
            foreach (['ticket', 'ore', 'fte_ticket', 'fte_uplift', 'fte_allocati', 'fte_floor', 'fte_finale'] as $k) $tot[$k] += $r[$k];
        }
        unset($r);
        $tot['scostamento'] = $tot['fte_allocati'] - $tot['fte_uplift'];
        return ['rows' => array_values($rows), 'totali' => $tot];
    }

    /* ───────────────────────── 5.3 Strutturali ───────────────────────── */

    /** Costo strutturale per FTE: dotazione, affitto, energia; ufficio e remoto per zona. */
    public static function structuralPerFte(array $equipment, array $params, float $affittoMqMese): array
    {
        $dot = 0.0;
        foreach ($equipment as $e) {
            $anni = (float)$e['anni_ammortamento'];
            $dot += $anni > 0 ? (float)$e['prezzo'] / $anni : (float)$e['prezzo'];
        }
        $aff = (float)($params['mq_postazione'] ?? 0) * (float)($params['occupazione_pct'] ?? 0) * $affittoMqMese * 12
             * (1 + (float)($params['oneri_accessori_pct'] ?? 0));
        $en  = (float)($params['kwh_postazione'] ?? 0) * (float)($params['prezzo_kwh'] ?? 0);
        return ['dotazione' => $dot, 'affitto' => $aff, 'energia' => $en, 'ufficio' => $dot + $aff + $en, 'remoto' => $dot];
    }

    /* ───────────────────────── 5.2 + 5.5 Scenario ───────────────────────── */

    /**
     * FTE dello scenario per riga profilo×servizio.
     * @param array $lines [[profile_id, service_id, tipo, fte, …]]
     */
    public static function scenarioFte(array $lines, array $sc): array
    {
        $tipo = $sc['tipo'] ?? 'completo';
        $sup  = 0.0;
        foreach ($lines as $l) if ($l['tipo'] === 'supporto') $sup += (float)$l['fte'];
        $target = (float)($sc['fte_supporto_sostenibile'] ?? 0);
        $out = [];
        foreach ($lines as $l) {
            $f = (float)$l['fte'];
            if ($tipo === 'sostenibile' && $l['tipo'] === 'supporto') $f = $sup > 0 ? $f * $target / $sup : 0.0;
            $ov = $sc['overrides'][$l['profile_id'] . ':' . $l['service_id']] ?? null;
            if ($ov) {
                if (!empty($ov['escluso'])) $f = 0.0;
                elseif ($ov['fte_override'] !== null && $ov['fte_override'] !== '') $f = (float)$ov['fte_override'];
            }
            $out[] = ['fte_scenario' => $f] + $l;
        }
        return $out;
    }

    /**
     * Calcolo completo di uno scenario.
     * @param array $in input di PrjRepo::input()
     */
    public static function scenario(array $in): array
    {
        $p   = $in['params'];
        $sc  = $in['scenario'];
        $mode = in_array($sc['ral_mode'] ?? 'media', self::RAL_MODES, true) ? $sc['ral_mode'] : 'media';
        $zones = $in['zones'];
        $zona  = $zones[(int)($sc['zona_id'] ?? 0)] ?? null;
        if (!$zona) throw new InvalidArgumentException('Zona dello scenario non definita.');
        $ns    = !empty($sc['nearshore_id']) && ($sc['nearshore_scope'] ?? 'nessuno') === 'supporto' ? ($in['nearshore'][(int)$sc['nearshore_id']] ?? null) : null;
        $oneri = (float)($p['oneri_pct'] ?? 0);
        $ind   = (float)($p['indennita_h24_eur'] ?? 0);
        $gg    = (float)($in['productivity']['giorni_fte'] ?? ($p['giorni_fte'] ?? 220));
        $strCache = [];
        $str = function (array $z) use (&$strCache, $in, $p): array {
            $k = $z['nome'];
            return $strCache[$k] ??= self::structuralPerFte($in['equipment'], $p, (float)$z['affitto_mq_mese']);
        };

        $righe = self::scenarioFte($in['lines'], $sc);
        $T = ['fte_totali' => 0.0, 'fte_ufficio' => 0.0, 'fte_remoto' => 0.0, 'fte_h24' => 0.0, 'fte_nearshore' => 0.0,
              'costo_personale' => 0.0, 'oneri' => 0.0, 'indennita' => 0.0, 'strutturali_fte' => 0.0];
        $out = [];
        foreach ($righe as $r) {
            $f = (float)$r['fte_scenario'];
            if ($f <= 0) continue;
            $ov    = $sc['overrides'][$r['profile_id'] . ':' . $r['service_id']] ?? [];
            $zr    = !empty($ov['zona_id']) && isset($zones[(int)$ov['zona_id']]) ? $zones[(int)$ov['zona_id']] : $zona;
            $base  = $zones[(int)($r['zona_base_id'] ?? 0)]['indice_ral'] ?? 1.0;
            $base  = (float)$base > 0 ? (float)$base : 1.0;
            $nsr   = null;
            if (!empty($ov['nearshore_id']) && isset($in['nearshore'][(int)$ov['nearshore_id']])) $nsr = $in['nearshore'][(int)$ov['nearshore_id']];
            elseif ($ns && $r['tipo'] === 'supporto' && !empty($r['nearshore_ammesso'])) $nsr = $ns;
            $ralRif = match ($mode) { 'min' => (float)$r['ral_min'], 'ideale' => (float)$r['ral_ideale'], default => ((float)$r['ral_min'] + (float)$r['ral_ideale']) / 2 };
            $idx    = $nsr ? (float)$nsr['indice_ral'] : (float)$zr['indice_ral'];
            $ralZ   = $ralRif * $idx / $base;
            $on     = $nsr ? (float)$nsr['oneri_pct'] : $oneri;
            $h24    = isset($ov['h24']) && $ov['h24'] !== null ? (int)$ov['h24'] : (int)$r['h24'];
            $azFte  = $ralZ * (1 + $on) + ($h24 ? $ind : 0.0);
            $remoto = !empty($ov['remoto']) || $nsr !== null;
            $s      = $str($zr);
            $strFte = $remoto ? $s['remoto'] : $s['ufficio'];
            $out[] = [
                'profile_id' => (int)$r['profile_id'], 'codice' => $r['codice'], 'nome' => $r['nome'], 'tipo' => $r['tipo'],
                'service_id' => (int)$r['service_id'], 'servizio' => $r['servizio'] ?? 'GOV',
                'fte' => $f, 'zona' => $nsr ? $nsr['paese'] : $zr['nome'], 'nearshore' => $nsr !== null, 'remoto' => $remoto, 'h24' => (bool)$h24,
                'ral_rif' => $ralRif, 'ral_zona' => $ralZ, 'oneri_pct' => $on, 'costo_az_fte' => $azFte,
                'costo_personale' => $f * $ralZ, 'costo_aziendale' => $f * $azFte, 'costo_giornaliero' => $gg > 0 ? $azFte / $gg : 0.0,
                'strutturale_fte' => $strFte, 'strutturale' => $f * $strFte,
            ];
            $T['fte_totali'] += $f;
            $T[$remoto ? 'fte_remoto' : 'fte_ufficio'] += $f;
            if ($h24) $T['fte_h24'] += $f;
            if ($nsr) $T['fte_nearshore'] += $f;
            $T['costo_personale'] += $f * $ralZ;
            $T['oneri']           += $f * $ralZ * $on;
            $T['indennita']       += $h24 ? $f * $ind : 0.0;
            $T['strutturali_fte'] += $f * $strFte;
        }
        $siteMese = 0.0; foreach ($in['site'] as $sc_) $siteMese += (float)$sc_['eur_mese'];
        $T['costi_sede'] = (float)($p['n_sedi'] ?? 1) * $siteMese * 12;
        $T['strutturali'] = $T['strutturali_fte'] + $T['costi_sede'];
        $ovh = 0.0; $ovhRows = [];
        foreach ($in['overhead'] as $o) {
            $v = $o['tipo'] === 'per_fte' ? (float)$o['importo'] * $T['fte_totali'] : (float)$o['importo'];
            $ovh += $v; $ovhRows[] = ['voce' => $o['voce'], 'tipo' => $o['tipo'], 'importo' => $v];
        }
        $T['overhead'] = $ovh;
        $T['costo_aziendale_personale'] = $T['costo_personale'] + $T['oneri'] + $T['indennita'];
        $T['costo_aziendale_totale']    = $T['costo_aziendale_personale'] + $T['strutturali'] + $T['overhead'];

        // economico
        $canoni = array_map(fn($t) => (float)$t['canone_eur'], $in['tender']);
        $T['canone_medio'] = $canoni ? array_sum($canoni) / count($canoni) : 0.0;
        $rib = (float)($sc['ribasso_pct'] ?? 0);
        $T['ribasso_pct']  = $rib;
        $T['canone_netto'] = $T['canone_medio'] * (1 - $rib);
        $ct = $T['costo_aziendale_totale'];
        $T['pct_canone']   = $T['canone_netto'] > 0 ? $ct / $T['canone_netto'] : null;
        $T['margine']      = $T['canone_netto'] - $ct;
        $T['margine_pct']  = $T['canone_netto'] > 0 ? $T['margine'] / $T['canone_netto'] : null;
        $T['valore_punto_ribasso'] = $T['canone_medio'] / 100;
        $T['ribasso_max_pareggio'] = $T['canone_medio'] > 0 ? 1 - $ct / $T['canone_medio'] : null;
        $T['costo_medio_fte']      = $T['fte_totali'] > 0 ? $ct / $T['fte_totali'] : null;
        $T['costo_giornaliero_medio'] = $T['costo_medio_fte'] !== null && $gg > 0 ? $T['costo_medio_fte'] / $gg : null;
        $mt = (float)($sc['margine_target_pct'] ?? ($p['margine_target_pct'] ?? 0));
        $T['margine_target_pct'] = $mt;
        $T['fte_finanziabili']   = $T['costo_medio_fte'] ? $T['canone_netto'] * (1 - $mt) / $T['costo_medio_fte'] : null;
        $T['peso_strutturali_pct'] = $ct > 0 ? $T['strutturali'] / $ct : null;
        $mesiOp = (int)($in['gara']['mesi_operativi'] ?? 0);
        $T['costo_totale_durata']  = $mesiOp > 0 ? $ct * $mesiOp / 12 : null;
        $T['canone_totale_durata'] = array_sum($canoni);

        // ticket e valore unitario
        $tl = self::ticketLoad([
            'year' => $in['year'], 'volumes' => $in['volumes'], 'mapping' => $in['mapping'], 'aht' => $in['aht'],
            'services' => $in['services'], 'productivity' => $in['productivity'],
            'alloc' => self::allocPerService($righe), 'floor' => self::floorPerService($in['lines']),
        ]);
        $T['ticket_anno'] = $tl['totali']['ticket'];
        $T['valore_unitario_ticket'] = $tl['totali']['ticket'] > 0 ? $T['canone_medio'] / $tl['totali']['ticket'] : null;

        // per anno di contratto: canone vs costo (servizi attivi dall'anno di avvio)
        $anni = [];
        $years = array_keys($in['tender']); sort($years);
        foreach ($years as $i => $y) {
            $n = $i + 1; $cy = 0.0; $fy = 0.0;
            foreach ($out as $r) {
                $avvio = (int)($in['services'][$r['service_id']]['avvio_anno'] ?? 1);
                if ($r['service_id'] && $avvio > $n) continue;
                $cy += $r['costo_aziendale'] + $r['strutturale']; $fy += $r['fte'];
            }
            $cy += $T['costi_sede'] + $ovh - ($T['fte_totali'] - $fy) * self::perFteOverhead($in['overhead']);
            $can = (float)$in['tender'][$y]['canone_eur'] * (1 - $rib);
            $anni[] = ['anno' => (int)$y, 'n' => $n, 'fte' => $fy, 'costo' => $cy, 'canone' => $can,
                       'uncommitted' => (float)$in['tender'][$y]['uncommitted_eur'], 'margine' => $can - $cy, 'pct_canone' => $can > 0 ? $cy / $can : null];
        }

        return ['totali' => $T, 'profili' => $out, 'ticket' => $tl, 'overhead' => $ovhRows, 'anni' => $anni,
                'strutturale_zona' => $str($zona), 'scenario' => ['nome' => $sc['nome'] ?? '', 'tipo' => $sc['tipo'] ?? '', 'zona' => $zona['nome'],
                'ral_mode' => $mode, 'nearshore' => $ns['paese'] ?? null]];
    }

    private static function perFteOverhead(array $ovh): float
    {
        $s = 0.0; foreach ($ovh as $o) if ($o['tipo'] === 'per_fte') $s += (float)$o['importo'];
        return $s;
    }
    private static function allocPerService(array $righe): array
    {
        $a = []; foreach ($righe as $r) if ((int)$r['service_id']) $a[(int)$r['service_id']] = ($a[(int)$r['service_id']] ?? 0) + (float)$r['fte_scenario'];
        return $a;
    }
    private static function floorPerService(array $lines): array
    {
        $a = []; foreach ($lines as $l) if ($l['tipo'] === 'obbligatorio' && (int)$l['service_id']) $a[(int)$l['service_id']] = ($a[(int)$l['service_id']] ?? 0) + (int)($l['n_minimo'] ?? 0);
        return $a;
    }

    /* ───────────────────────── Banda volumi ±20% ───────────────────────── */

    /** Conguaglio: ticket fuori banda valorizzati a canone / ticket stimati. */
    public static function conguaglio(float $ticketStimati, float $ticketReali, float $canone, float $banda = 0.20): array
    {
        $vu = $ticketStimati > 0 ? $canone / $ticketStimati : 0.0;
        $sup = $ticketStimati * (1 + $banda); $inf = $ticketStimati * (1 - $banda);
        $delta = $ticketReali > $sup ? $ticketReali - $sup : ($ticketReali < $inf ? -($inf - $ticketReali) : 0.0);
        return ['valore_unitario' => $vu, 'soglia_inf' => $inf, 'soglia_sup' => $sup, 'ticket_fuori_banda' => $delta, 'conguaglio' => $delta * $vu];
    }

    /* ───────────────────────── 5.6 Penali ───────────────────────── */

    /**
     * @param array $kpis   [[codice, penale_importo, penale_importi_priorita, penale_unita, blocco, penale_formula]]
     * @param array $input  codice => quantita | [A=>n, M=>n, B=>n] | [critiche=>punti, non_critiche=>punti]
     * @param float $canone canone di riferimento del periodo (mese o anno) per la % sul canone
     */
    public static function penalties(array $kpis, array $input, float $canone): array
    {
        $rows = []; $tot = 0.0;
        foreach ($kpis as $k) {
            $c = $k['codice'];
            if (!array_key_exists($c, $input)) continue;
            $q = $input[$c];
            $imp = $k['penale_importo'] !== null ? (float)$k['penale_importo'] : 0.0;
            $pri = $k['penale_importi_priorita'] ? array_map('floatval', explode('/', $k['penale_importi_priorita'])) : [];
            $bl  = max(1, (int)($k['blocco'] ?? 1));
            $v = 0.0;
            if (!empty($k['penale_formula'])) {
                $vars = ['importo' => $imp, 'blocco' => $bl];
                if (is_array($q)) { foreach ($q as $kk => $vv) $vars['qty_' . strtolower((string)$kk)] = (float)$vv; $vars['qty'] = array_sum(array_map('floatval', $q)); }
                else $vars['qty'] = (float)$q;
                $v = FormulaEval::evaluate($k['penale_formula'], $vars);
            } else switch ($k['penale_unita']) {
                case 'blocco_ticket':
                    if (is_array($q) && $pri) { $i = 0; foreach (['A', 'M', 'B'] as $p) { $v += floor((float)($q[$p] ?? 0) / $bl) * ($pri[$i] ?? end($pri)); $i++; } }
                    else $v = floor((float)(is_array($q) ? array_sum($q) : $q) / $bl) * ($imp ?: ($pri[0] ?? 0));
                    break;
                case 'punto_pct':
                    if (is_array($q) && $pri) $v = (float)($q['critiche'] ?? 0) * $pri[0] + (float)($q['non_critiche'] ?? 0) * ($pri[1] ?? $pri[0]);
                    else $v = (float)(is_array($q) ? array_sum($q) : $q) * ($imp ?: ($pri[0] ?? 0));
                    break;
                case 'pct_sforamento': $v = (float)$q * $imp; break;          // q = sforamento in €
                case 'una_tantum':     $v = (float)$q > 0 ? $imp : 0.0; break;
                case 'nessuna':        $v = 0.0; break;
                default:               $v = (float)(is_array($q) ? array_sum($q) : $q) * $imp;   // giorno, risorsa, minuto, finestra, rilascio, rollback, ticket, settimana
            }
            $rows[] = ['codice' => $c, 'quantita' => $q, 'penale' => $v];
            $tot += $v;
        }
        return ['righe' => $rows, 'totale' => $tot, 'pct_canone' => $canone > 0 ? $tot / $canone : null];
    }

    /* ───────────────────────── 5.7 Punteggio ───────────────────────── */

    /** Punteggio economico: PE = K1·(1−((1−s1)/(1+w·s1))^n1) + K2·(1−((1−s2)/(1+w·s2))^n2). */
    public static function economicScore(float $s1, float $s2, float $w, float $n1, float $n2, float $k1 = 20.0, float $k2 = 10.0): array
    {
        $f = fn(float $s, float $n) => 1 - (((1 - $s) / (1 + $w * $s)) ** $n);
        $p1 = $k1 * $f($s1, $n1); $p2 = $k2 * $f($s2, $n2);
        return ['k1' => $p1, 'k2' => $p2, 'totale' => $p1 + $p2];
    }

    /**
     * Punteggio tecnico.
     * @param array $crit  [[codice, gruppo, tipo, punti_max, formula]]
     * @param array $in    C=>[Pi…], D=>[Pi…], E=>[[S,C]…], E_n, F=>[1|0.5…], G=>rtnc, H=>1|0.5,
     *                     coeff=>[codice=>0..1] per i criteri discrezionali/quantitativi, A.1 via formula configurabile
     */
    public static function technicalScore(array $crit, array $in): array
    {
        $rows = []; $tot = 0.0;
        foreach ($crit as $c) {
            if ($c['tipo'] === 'E') continue;
            $max = (float)$c['punti_max']; $v = 0.0;
            switch ($c['gruppo']) {
                case 'C': $v = array_sum($in['C'] ?? []) / 63 * $max; break;
                case 'D': $v = array_sum($in['D'] ?? []) / 144 * $max; break;
                case 'E':
                    $n = (int)($in['E_n'] ?? 11); $s = 0.0;
                    foreach ($in['E'] ?? [] as $e) $s += 0.60 * (float)$e[0] + 0.40 * (float)$e[1];
                    $v = $n > 0 ? $s / $n * $max : 0.0; break;
                case 'F': $v = array_sum(array_map('floatval', $in['F'] ?? [])); break;
                case 'G': $v = (float)($in['G'] ?? 0) / 100 * $max; break;
                case 'H': $v = (float)($in['H'] ?? 0); break;
                default:
                    if (!empty($c['formula']) && isset($in['vars'][$c['codice']])) $v = FormulaEval::evaluate($c['formula'], $in['vars'][$c['codice']]);
                    else $v = (float)($in['coeff'][$c['codice']] ?? 0) * $max;
            }
            $v = max(0.0, min($max, $v));
            $rows[] = ['codice' => $c['codice'], 'punti_max' => $max, 'punti' => $v, 'flag_incongruenza' => (bool)($c['flag_incongruenza'] ?? false)];
            $tot += $v;
        }
        return ['righe' => $rows, 'totale' => $tot];
    }

    /** Hash SHA-256 deterministico dell'input (chiavi ordinate). */
    public static function hash(array $in): string
    {
        $norm = function ($v) use (&$norm) {
            if (!is_array($v)) return $v;
            if (array_keys($v) !== range(0, count($v) - 1)) ksort($v);
            return array_map($norm, $v);
        };
        return hash('sha256', json_encode($norm($in), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
