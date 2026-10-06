<?php
/**
 * app/SocModel.php — v1.10.06
 * Letture della sezione Service SOC (pattern di SdModel): ticket del sistema di gestione SOC
 * (cm_soc_tickets / cm_soc_events) aggregati con i dati del portale:
 *   - moduli di intervento (cm_intervention_reports.ticket = codice ticket): ore, costo, ricavo, commesse PM;
 *   - dipendenti (cm_soc_people → employees) per il team e la quota di lavoro SOC sul totale dei moduli;
 *   - clienti (cm_soc_clients → clients) e filtro globale Codice Contratto / PM Project (PmContractFilter, tipo ticket).
 *
 * Perimetro del periodo: ticket con almeno un evento nel periodo («attivi»). Aperti = primo evento nel periodo,
 * chiusi = chiusura nel periodo, backlog = aperti entro la fine del periodo e non chiusi a quella data.
 */
declare(strict_types=1);

final class SocModel
{
    private ?PmContractFilter $cf = null;

    public function __construct(private PDO $pdo)
    {
        require_once __DIR__ . '/PmContractFilter.php';
    }

    public function setting(string $k, string $d): string
    {
        try { $v = $this->pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = " . $this->pdo->quote($k))->fetchColumn(); } catch (Throwable $e) { $v = false; }
        return is_string($v) && $v !== '' ? $v : $d;
    }

    public function ready(): bool
    {
        try { return (int)$this->pdo->query("SELECT COUNT(*) FROM cm_soc_tickets")->fetchColumn() > 0; } catch (Throwable $e) { return false; }
    }

    /** Periodo predefinito: dal primo giorno di due mesi prima fino al giorno dell'ultimo evento importato. */
    public function normFilters(array $q): array
    {
        $d = static fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
        $s = static fn($k, $max = 190) => mb_substr(trim((string)($q[$k] ?? '')), 0, $max);
        $f = ['from' => $d($q['from'] ?? ''), 'to' => $d($q['to'] ?? ''), 'cliente' => $s('cliente'), 'commessa' => $s('commessa', 60),
              'categoria' => $s('categoria', 120), 'tec' => $s('tec', 150), 'stato' => in_array($q['stato'] ?? '', ['aperti', 'chiusi', 'presidio'], true) ? $q['stato'] : '',
              'esito' => $s('esito', 60), 'q' => $s('q', 100), 'contratti' => PmContractFilter::fromRequest($q)];
        if ($f['from'] === '' || $f['to'] === '') {
            $max = null;
            try { $max = $this->pdo->query("SELECT MAX(last_event_at) FROM cm_soc_tickets")->fetchColumn(); } catch (Throwable $e) {}
            $end = $max ? substr((string)$max, 0, 10) : date('Y-m-d');
            $f['to'] = $f['to'] ?: $end;                                   // fino all'ultimo evento: nessun mese futuro vuoto
            $f['from'] = $f['from'] ?: date('Y-m-01', strtotime(substr($f['to'], 0, 7) . '-01 -2 months'));
        }
        if ($f['from'] > $f['to']) [$f['from'], $f['to']] = [$f['to'], $f['from']];
        return $f;
    }

    public function cf(array $f): PmContractFilter
    {
        $v = $f['contratti'] ?? [];
        if ($this->cf === null || $this->cf->values() !== PmContractFilter::norm($v)) $this->cf = new PmContractFilter($this->pdo, $v);
        return $this->cf;
    }

    public function valoriContratti(): array
    {
        try { return PmContractFilter::options($this->pdo); } catch (Throwable $e) { return []; }
    }

    public function closedSql(): string
    {
        require_once __DIR__ . '/SocIngest.php';
        return implode(',', array_map(fn($s) => $this->pdo->quote($s), SocIngest::closedStates($this->pdo) ?: ['CHIUSO']));
    }

    /**
     * Filtri sui ticket (alias t), escluso il periodo. $withPeriod = 'attivi' aggiunge il perimetro del periodo.
     * @return array{0:string,1:array}
     */
    public function where(array $f, string $withPeriod = 'attivi'): array
    {
        $w = ['1=1']; $a = [];
        if ($f['cliente'] !== '')   { $w[] = 't.client_name = ?'; $a[] = $f['cliente']; }
        if ($f['commessa'] !== '')  { $w[] = 't.soc_contract = ?'; $a[] = $f['commessa']; }
        if ($f['categoria'] !== '') { $w[] = 't.category = ?'; $a[] = $f['categoria']; }
        if ($f['tec'] !== '')       { $w[] = "(t.assignee_name = ? OR t.owner_name = ? OR EXISTS (SELECT 1 FROM cm_soc_events fe WHERE fe.ticket_code = t.ticket_code AND fe.author_name = ? AND fe.event_kind IN ('supporto', 'nota')))"; array_push($a, $f['tec'], $f['tec'], $f['tec']); }
        if ($f['esito'] !== '')     { $w[] = 't.resolution = ?'; $a[] = $f['esito']; }
        if ($f['stato'] === 'aperti') $w[] = 't.is_closed = 0';
        if ($f['stato'] === 'chiusi') $w[] = 't.is_closed = 1';
        if ($f['stato'] === 'presidio') { $w[] = $this->presidioCond(); }
        if ($f['q'] !== '')         { $w[] = '(t.ticket_code LIKE ? OR t.title LIKE ?)'; $a[] = '%' . $f['q'] . '%'; $a[] = '%' . $f['q'] . '%'; }
        if ($this->cf($f)->active()) $w[] = $this->cf($f)->sql('ticket', 't.ticket_code', $a);
        if ($withPeriod === 'attivi') {
            $w[] = 'EXISTS (SELECT 1 FROM cm_soc_events pe WHERE pe.ticket_code = t.ticket_code AND pe.event_at BETWEEN ? AND ?)';
            $a[] = $f['from'] . ' 00:00:00'; $a[] = $f['to'] . ' 23:59:59';
        }
        return [implode(' AND ', $w), $a];
    }

    /** Ticket aperti in attesa del supporto da oltre soc.presidio_ore (mai preso in carico o ultima parola al cliente). */
    public function presidioCond(): string
    {
        $h = max(1, (int)$this->setting('soc.presidio_ore', '24'));
        return "(t.is_closed = 0 AND (t.n_support = 0 OR t.last_kind = 'cliente') AND t.last_event_at < (NOW() - INTERVAL $h HOUR))";
    }

    private function rows(string $sql, array $a): array
    {
        $st = $this->pdo->prepare($sql); $st->execute($a);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function median(array $v): ?float
    {
        $v = array_values(array_filter($v, fn($x) => $x !== null));
        if (!$v) return null;
        sort($v); $n = count($v);
        return $n % 2 ? (float)$v[intdiv($n, 2)] : ((float)$v[$n / 2 - 1] + (float)$v[$n / 2]) / 2;
    }

    /* ── indicatori ──────────────────────────────────────────────────── */

    public function headline(array $f): array
    {
        [$w, $a] = $this->where($f);
        $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
        $sla = (float)$this->setting('soc.sla_risposta_ore', '4') * 60;
        $t = $this->rows("SELECT t.ticket_code, t.opened_at, t.closed_at, t.is_closed, t.resolution_min, t.n_reopen, t.resolution
                            FROM cm_soc_tickets t WHERE $w", $a);
        $o = ['attivi' => count($t), 'aperti' => 0, 'chiusi' => 0, 'risolti' => 0, 'riaperture' => 0, 'presa' => [], 'chiusura' => [], 'entro_sla' => 0, 'con_presa' => 0];
        // risposte del supporto ai messaggi del cliente ricevuti nel periodo
        foreach ($this->rows("SELECT e.reply_min, t.is_closed FROM cm_soc_events e JOIN cm_soc_tickets t ON t.ticket_code = e.ticket_code
                               WHERE $w AND e.event_kind = 'cliente' AND e.event_at BETWEEN ? AND ?", array_merge($a, [$from, $to])) as $r) {
            // senza risposta: conta solo sui ticket ancora aperti (l'ultimo messaggio del cliente su un ticket chiuso è un congedo)
            if ($r['reply_min'] === null) { if (!$r['is_closed']) $o['senza_risposta'] = ($o['senza_risposta'] ?? 0) + 1; continue; }
            $o['presa'][] = (int)$r['reply_min']; $o['con_presa']++; if ((int)$r['reply_min'] <= $sla) $o['entro_sla']++;
        }
        $o['senza_risposta'] = $o['senza_risposta'] ?? 0;
        foreach ($t as $r) {
            if ($r['opened_at'] >= $from && $r['opened_at'] <= $to) $o['aperti']++;
            if ($r['is_closed'] && $r['closed_at'] >= $from && $r['closed_at'] <= $to) {
                $o['chiusi']++; $o['chiusura'][] = (int)$r['resolution_min'];
                if (($r['resolution'] ?? '') === 'RISOLTO') $o['risolti']++;
            }
            $o['riaperture'] += (int)$r['n_reopen'];
        }
        [$w2, $a2] = $this->where($f, 'none');
        $a2[] = $to; $a2[] = $to;
        $o['backlog'] = (int)$this->rows("SELECT COUNT(*) n FROM cm_soc_tickets t WHERE $w2 AND t.opened_at <= ? AND (t.is_closed = 0 OR t.closed_at > ?)", $a2)[0]['n'];
        [$w3, $a3] = $this->where(['stato' => 'presidio'] + $f, 'none');
        $o['presidio'] = (int)$this->rows("SELECT COUNT(*) n FROM cm_soc_tickets t WHERE $w3", $a3)[0]['n'];
        $o['presa_mediana_h'] = ($m = self::median($o['presa'])) === null ? null : $m / 60;
        $o['chiusura_mediana_g'] = ($m = self::median($o['chiusura'])) === null ? null : $m / 1440;
        $o['sla_pct'] = $o['con_presa'] ? 100 * $o['entro_sla'] / $o['con_presa'] : null;
        $o['sla_ore'] = $sla / 60;
        // eventi del periodo
        $e = $this->rows("SELECT e.event_kind, COUNT(*) n FROM cm_soc_events e JOIN cm_soc_tickets t ON t.ticket_code = e.ticket_code
                           WHERE $w AND e.event_at BETWEEN ? AND ? GROUP BY e.event_kind", array_merge($a, [$from, $to]));
        $o['eventi'] = array_column($e, 'n', 'event_kind') + ['supporto' => 0, 'cliente' => 0, 'nota' => 0];
        // portale: moduli di intervento sui ticket del perimetro, nel periodo
        $m = $this->rows("SELECT COUNT(*) moduli, COUNT(DISTINCT ir.ticket) ticket, COALESCE(SUM(ir.quantity_hours),0) ore,
                                 COALESCE(SUM(ir.company_cost_import),0) costo, COALESCE(SUM(ir.client_revenue_import),0) ricavo
                            FROM cm_intervention_reports ir JOIN cm_soc_tickets t ON t.ticket_code = ir.ticket
                           WHERE $w AND ir.report_date BETWEEN ? AND ?", array_merge($a, [$f['from'], $f['to']]))[0];
        $o['moduli'] = $m;
        unset($o['presa'], $o['chiusura']);
        return $o;
    }

    /* ── ripartizioni ────────────────────────────────────────────────── */

    /** Per una dimensione del ticket: attivi, aperti, chiusi, risolti, presa media, eventi, ore moduli. */
    public function breakdown(array $f, string $dim): array
    {
        $col = ['categoria' => 't.category', 'cliente' => 't.client_name', 'commessa' => 't.soc_contract', 'stato' => 't.status_now',
                'esito' => "CASE WHEN t.is_closed = 1 THEN COALESCE(t.resolution, '(senza esito)') END", 'tipo' => 't.ticket_type',
                'incaricato' => 't.assignee_name', 'mese' => "DATE_FORMAT(t.opened_at, '%Y-%m')"][$dim] ?? 't.category';
        [$w, $a] = $this->where($f);
        $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
        return $this->rows("SELECT COALESCE($col, '(non indicato)') AS k, COUNT(*) attivi,
                                   SUM(t.opened_at BETWEEN ? AND ?) aperti,
                                   SUM(t.is_closed = 1 AND t.closed_at BETWEEN ? AND ?) chiusi,
                                   SUM(t.is_closed = 1 AND t.closed_at BETWEEN ? AND ? AND t.resolution = 'RISOLTO') risolti,
                                   SUM(t.is_closed = 0) ancora_aperti,
                                   ROUND(AVG(t.avg_reply_min) / 60, 1) risposta_media_h,
                                   ROUND(AVG(CASE WHEN t.is_closed = 1 THEN t.resolution_min END) / 1440, 1) chiusura_media_g,
                                   SUM(t.n_events) eventi,
                                   COALESCE(SUM(m.ore), 0) ore_moduli, COALESCE(SUM(m.costo), 0) costo, COALESCE(SUM(m.ricavo), 0) ricavo
                              FROM cm_soc_tickets t
                              LEFT JOIN (SELECT ticket, SUM(quantity_hours) ore, SUM(company_cost_import) costo, SUM(client_revenue_import) ricavo
                                           FROM cm_intervention_reports WHERE ticket IS NOT NULL AND report_date BETWEEN ? AND ? GROUP BY ticket) m ON m.ticket = t.ticket_code
                             WHERE $w GROUP BY k ORDER BY attivi DESC, k",
            array_merge([$from, $to, $from, $to, $from, $to, $f['from'], $f['to']], $a));
    }

    /** Andamento mensile: aperti, chiusi, backlog a fine mese, eventi per tipo. */
    public function trend(array $f, int $mesi = 12): array
    {
        [$w, $a] = $this->where($f, 'none');
        $end = new DateTimeImmutable(substr($f['to'], 0, 7) . '-01');
        $out = [];
        $tk = $this->rows("SELECT t.opened_at, t.closed_at, t.is_closed FROM cm_soc_tickets t WHERE $w", $a);
        $ev = $this->rows("SELECT DATE_FORMAT(e.event_at, '%Y-%m') ym, e.event_kind k, COUNT(*) n FROM cm_soc_events e JOIN cm_soc_tickets t ON t.ticket_code = e.ticket_code
                            WHERE $w GROUP BY ym, k", $a);
        $evm = []; foreach ($ev as $r) $evm[$r['ym']][$r['k']] = (int)$r['n'];
        for ($i = $mesi - 1; $i >= 0; $i--) {
            $m = $end->modify("-$i month"); $ym = $m->format('Y-m'); $a1 = $m->format('Y-m-01 00:00:00'); $b1 = $m->format('Y-m-t 23:59:59');
            $r = ['ym' => $ym, 'aperti' => 0, 'chiusi' => 0, 'backlog' => 0];
            foreach ($tk as $t) {
                if ($t['opened_at'] >= $a1 && $t['opened_at'] <= $b1) $r['aperti']++;
                if ($t['is_closed'] && $t['closed_at'] >= $a1 && $t['closed_at'] <= $b1) $r['chiusi']++;
                if ($t['opened_at'] <= $b1 && (!$t['is_closed'] || $t['closed_at'] > $b1)) $r['backlog']++;
            }
            $r += ['supporto' => $evm[$ym]['supporto'] ?? 0, 'cliente' => $evm[$ym]['cliente'] ?? 0, 'nota' => $evm[$ym]['nota'] ?? 0];
            $out[] = $r;
        }
        while ($out && !$out[0]['aperti'] && !$out[0]['backlog'] && !$out[0]['supporto'] && !$out[0]['cliente']) array_shift($out);
        return $out;
    }

    /** Eventi giornalieri del periodo (al più 92 giorni finali). */
    public function trendGiornaliero(array $f): array
    {
        require_once __DIR__ . '/PmCharts.php';
        [$from, $to] = PmCharts::window($f['from'], $f['to'], 92);
        [$w, $a] = $this->where($f, 'none');
        $rows = $this->rows("SELECT DATE(e.event_at) giorno, SUM(e.event_kind = 'supporto') supporto, SUM(e.event_kind = 'cliente') cliente,
                                    SUM(e.event_kind = 'nota') nota, SUM(e.event_kind IN ('apertura','altro')) altro
                               FROM cm_soc_events e JOIN cm_soc_tickets t ON t.ticket_code = e.ticket_code
                              WHERE $w AND e.event_at BETWEEN ? AND ? GROUP BY giorno", array_merge($a, [$from . ' 00:00:00', $to . ' 23:59:59']));
        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /* ── team: persone SOC + dati del portale ───────────────────────── */

    public function team(array $f): array
    {
        [$w, $a] = $this->where(['tec' => ''] + $f);
        $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
        $rows = $this->rows("SELECT t.assignee_name nome, MAX(t.assignee_employee_id) employee_id, COUNT(*) ticket, SUM(t.assignee_source = 'dedotto') dedotti,
                                    SUM(t.is_closed = 1 AND t.closed_at BETWEEN ? AND ?) chiusi, SUM(t.is_closed = 0) aperti,
                                    ROUND(AVG(t.avg_reply_min) / 60, 1) risposta_media_h
                               FROM cm_soc_tickets t WHERE $w AND COALESCE(t.assignee_name, '') <> '' GROUP BY t.assignee_name", array_merge([$from, $to], $a));
        // messaggi scritti dalla persona nel periodo (autore = nome come nel sistema SOC)
        // v1.10.09 — «seguiti»: ticket su cui la persona ha scritto risposte o note, anche se l'incaricato è un altro
        $msg = $this->rows("SELECT e.author_name n, SUM(e.event_kind = 'supporto') supporto, SUM(e.event_kind = 'nota') note,
                                   COUNT(DISTINCT CASE WHEN e.event_kind IN ('supporto', 'nota') THEN e.ticket_code END) seguiti
                              FROM cm_soc_events e JOIN cm_soc_tickets t ON t.ticket_code = e.ticket_code
                             WHERE $w AND e.event_at BETWEEN ? AND ? GROUP BY e.author_name", array_merge($a, [$from, $to]));
        $mm = []; foreach ($msg as $r) $mm[$r['n']] = $r;
        $emp = []; foreach ($this->rows("SELECT name, employee_id FROM cm_soc_people", []) as $p) $emp[$p['name']] = $p['employee_id'];
        // operatori che nel periodo hanno risposto o scritto note senza essere incaricati di alcun ticket
        $have = array_column($rows, 'nome');
        foreach ($mm as $nm => $m) {
            if ($nm === '' || in_array($nm, $have, true) || ((int)$m['supporto'] + (int)$m['note']) === 0) continue;
            $rows[] = ['nome' => $nm, 'employee_id' => $emp[$nm] ?? null, 'ticket' => 0, 'dedotti' => 0, 'chiusi' => 0, 'aperti' => 0, 'risposta_media_h' => null];
        }
        // portale: ore dei moduli di intervento (SOC = ticket del sistema SOC; totale = tutti i moduli) del dipendente nel periodo
        $ids = array_filter(array_map('intval', array_values($emp)));
        $ore = []; $nomi = [];
        if ($ids) {
            $in = implode(',', $ids);
            foreach ($this->rows("SELECT ir.technician_id id, SUM(ir.quantity_hours) tot,
                                         SUM(CASE WHEN ir.ticket IN (SELECT ticket_code FROM cm_soc_tickets) THEN ir.quantity_hours ELSE 0 END) soc,
                                         COUNT(DISTINCT ir.report_date) giorni
                                    FROM cm_intervention_reports ir WHERE ir.technician_id IN ($in) AND ir.report_date BETWEEN ? AND ? GROUP BY ir.technician_id", [$f['from'], $f['to']]) as $r)
                $ore[(int)$r['id']] = $r;
            $nomi = array_column($this->rows("SELECT id, CONCAT_WS(' ', last_name, first_name) n FROM employees WHERE id IN ($in)", []), 'n', 'id');
        }
        foreach ($rows as &$r) {
            $eid = (int)($emp[$r['nome']] ?? $r['employee_id'] ?? 0);
            $r['employee_id'] = $eid ?: null; $r['dipendente'] = $eid ? ($nomi[$eid] ?? null) : null;
            $r['msg_supporto'] = (int)($mm[$r['nome']]['supporto'] ?? 0); $r['note'] = (int)($mm[$r['nome']]['note'] ?? 0);
            $r['seguiti'] = (int)($mm[$r['nome']]['seguiti'] ?? 0); $r['dedotti'] = (int)($r['dedotti'] ?? 0);
            $r['ore_soc'] = (float)($ore[$eid]['soc'] ?? 0); $r['ore_tot'] = (float)($ore[$eid]['tot'] ?? 0);
            $r['quota_soc'] = $r['ore_tot'] > 0 ? 100 * $r['ore_soc'] / $r['ore_tot'] : null;
        }
        // v1.10.07 — Unità Organizzativa: unità di ciascun dipendente e componenti dell'unità SOC senza ticket nel periodo
        $code = $this->setting('soc.uo_code', 'SOC');
        $units = []; foreach ($this->rows("SELECT tp.employee_id, u.name, u.code FROM cm_tech_profiles tp JOIN cm_tech_units u ON u.id = tp.unit_id WHERE tp.is_active = 1", []) as $u) $units[(int)$u['employee_id']] = $u;
        $have = array_filter(array_map(fn($r) => (int)($r['employee_id'] ?? 0), $rows));
        foreach ($this->rows("SELECT e.id, CONCAT_WS(' ', e.last_name, e.first_name) n FROM cm_tech_profiles tp JOIN cm_tech_units u ON u.id = tp.unit_id
                               JOIN employees e ON e.id = tp.employee_id WHERE u.code = ? AND tp.is_active = 1", [$code]) as $m) {
            if (in_array((int)$m['id'], $have, true)) continue;
            $o = $this->rows("SELECT COALESCE(SUM(quantity_hours),0) t FROM cm_intervention_reports WHERE technician_id = ? AND report_date BETWEEN ? AND ?", [(int)$m['id'], $f['from'], $f['to']])[0]['t'];
            $rows[] = ['nome' => '(' . $m['n'] . ')', 'employee_id' => (int)$m['id'], 'dipendente' => $m['n'], 'ticket' => 0, 'dedotti' => 0, 'seguiti' => 0, 'chiusi' => 0, 'aperti' => 0, 'risposta_media_h' => null,
                       'msg_supporto' => 0, 'note' => 0, 'ore_soc' => 0.0, 'ore_tot' => (float)$o, 'quota_soc' => (float)$o > 0 ? 0.0 : null, 'senza_ticket' => true];
        }
        foreach ($rows as &$r) { $u = $units[(int)($r['employee_id'] ?? 0)] ?? null; $r['unita'] = $u['name'] ?? null; $r['in_uo_soc'] = $u && $u['code'] === $code; }
        unset($r);
        usort($rows, fn($x, $y) => [$y['ticket'], $y['seguiti']] <=> [$x['ticket'], $x['seguiti']]);
        return $rows;
    }

    /* ── clienti e commesse del portale ─────────────────────────────── */

    public function clienti(array $f): array
    {
        $b = $this->breakdown($f, 'cliente');
        $map = [];
        foreach ($this->rows("SELECT s.name, s.client_id, c.name cn FROM cm_soc_clients s LEFT JOIN clients c ON c.id = s.client_id", []) as $r) $map[$r['name']] = $r;
        foreach ($b as &$r) { $r['client_id'] = $map[$r['k']]['client_id'] ?? null; $r['cliente_pm'] = $map[$r['k']]['cn'] ?? null; }
        return $b;
    }

    /** Commesse PM dei moduli di intervento sui ticket del perimetro, nel periodo. */
    public function commessePm(array $f): array
    {
        [$w, $a] = $this->where($f);
        return $this->rows("SELECT COALESCE(p.project_code, ir.project_code, '(senza commessa)') codice, MAX(p.name) nome, MAX(p.client_raw) cliente,
                                   MAX(p.id) project_id, COUNT(*) moduli, COUNT(DISTINCT ir.ticket) ticket, SUM(ir.quantity_hours) ore,
                                   SUM(ir.company_cost_import) costo, SUM(ir.client_revenue_import) ricavo, COUNT(DISTINCT ir.technician_id) tecnici
                              FROM cm_intervention_reports ir JOIN cm_soc_tickets t ON t.ticket_code = ir.ticket
                              LEFT JOIN cm_projects p ON p.id = ir.project_id
                             WHERE $w AND ir.report_date BETWEEN ? AND ?
                             GROUP BY codice ORDER BY ore DESC", array_merge($a, [$f['from'], $f['to']]));
    }

    /* ── elenchi ─────────────────────────────────────────────────────── */

    public function tickets(array $f, int $limit = 500, string $order = 'recenti'): array
    {
        [$w, $a] = $this->where($f, $f['stato'] === 'presidio' ? 'none' : 'attivi');
        $ord = ['recenti' => 't.last_event_at DESC', 'vecchi' => 't.opened_at ASC', 'eventi' => 't.n_events DESC', 'ore' => 'ore DESC'][$order] ?? 't.last_event_at DESC';
        return $this->rows("SELECT t.*, COALESCE(m.ore, 0) ore, COALESCE(m.moduli, 0) moduli, m.commesse,
                                   TIMESTAMPDIFF(HOUR, t.last_event_at, NOW()) ore_da_ultimo
                              FROM cm_soc_tickets t
                              LEFT JOIN (SELECT ticket, COUNT(*) moduli, SUM(quantity_hours) ore, GROUP_CONCAT(DISTINCT project_code ORDER BY project_code SEPARATOR ', ') commesse
                                           FROM cm_intervention_reports WHERE ticket IS NOT NULL GROUP BY ticket) m ON m.ticket = t.ticket_code
                             WHERE $w ORDER BY $ord LIMIT " . max(1, min(20000, $limit)), $a);
    }

    public function countTickets(array $f): int
    {
        [$w, $a] = $this->where($f, $f['stato'] === 'presidio' ? 'none' : 'attivi');
        return (int)$this->rows("SELECT COUNT(*) n FROM cm_soc_tickets t WHERE $w", $a)[0]['n'];
    }

    public function presidio(array $f, int $limit = 50): array
    {
        return $this->tickets(['stato' => 'presidio'] + $f, $limit, 'vecchi');
    }

    public function ticket(string $code): ?array
    {
        $t = $this->rows("SELECT t.*, c.name cliente_pm, CONCAT_WS(' ', e.last_name, e.first_name) dipendente
                            FROM cm_soc_tickets t LEFT JOIN clients c ON c.id = t.client_id LEFT JOIN employees e ON e.id = t.assignee_employee_id
                           WHERE t.ticket_code = ?", [$code])[0] ?? null;
        if (!$t) return null;
        $t['eventi'] = $this->rows("SELECT * FROM cm_soc_events WHERE ticket_code = ? ORDER BY event_at, id", [$code]);
        $t['moduli'] = $this->rows("SELECT ir.report_date, ir.report_code, ir.project_code, p.name progetto, ir.technician_raw, ir.quantity_hours,
                                           ir.company_cost_import, ir.client_revenue_import, ir.on_call, ir.remote
                                      FROM cm_intervention_reports ir LEFT JOIN cm_projects p ON p.id = ir.project_id
                                     WHERE ir.ticket = ? ORDER BY ir.report_date, ir.id", [$code]);
        return $t;
    }

    /* ── valori per i filtri ─────────────────────────────────────────── */

    public function valori(string $col): array
    {
        $c = ['cliente' => 'client_name', 'commessa' => 'soc_contract', 'categoria' => 'category', 'esito' => 'resolution'][$col] ?? null;
        if ($c === null) {
            if ($col !== 'tec') return [];
            return array_column($this->rows("SELECT DISTINCT n FROM (SELECT assignee_name n FROM cm_soc_tickets UNION SELECT owner_name FROM cm_soc_tickets
                                               UNION SELECT author_name FROM cm_soc_events WHERE event_kind IN ('supporto', 'nota')) x
                                               WHERE n IS NOT NULL AND n <> '' ORDER BY n", []), 'n');
        }
        return array_column($this->rows("SELECT DISTINCT $c v FROM cm_soc_tickets WHERE $c IS NOT NULL AND $c <> '' ORDER BY v", []), 'v');
    }

    /* ── ingestion: stato ────────────────────────────────────────────── */

    public function batches(int $limit = 20): array
    {
        return $this->rows("SELECT b.*, COALESCE(NULLIF(TRIM(u.display_name), ''), u.email) utente FROM cm_soc_batches b LEFT JOIN users u ON u.id = b.user_id
                             ORDER BY b.id DESC LIMIT " . max(1, $limit), []);
    }

    public function archivio(): array
    {
        return $this->rows("SELECT (SELECT COUNT(*) FROM cm_soc_events) eventi, (SELECT COUNT(*) FROM cm_soc_tickets) ticket,
                                   (SELECT MIN(event_at) FROM cm_soc_events) dal, (SELECT MAX(event_at) FROM cm_soc_events) al,
                                   (SELECT COUNT(*) FROM cm_soc_events WHERE source = 'file') da_file, (SELECT COUNT(*) FROM cm_soc_events WHERE source = 'db') da_db,
                                   (SELECT COUNT(DISTINCT ir.ticket) FROM cm_intervention_reports ir JOIN cm_soc_tickets t ON t.ticket_code = ir.ticket) con_moduli", [])[0];
    }

    public function people(): array
    {
        return $this->rows("SELECT p.*, CONCAT_WS(' ', e.last_name, e.first_name) dipendente,
                                   (SELECT COUNT(*) FROM cm_soc_tickets t WHERE t.assignee_name = p.name OR t.owner_name = p.name
                                       OR EXISTS (SELECT 1 FROM cm_soc_events e WHERE e.ticket_code = t.ticket_code AND e.author_name = p.name AND e.event_kind IN ('supporto', 'nota'))) ticket
                              FROM cm_soc_people p LEFT JOIN employees e ON e.id = p.employee_id ORDER BY p.name", []);
    }

    public function clientMap(): array
    {
        return $this->rows("SELECT s.*, c.name cliente_pm, (SELECT COUNT(*) FROM cm_soc_tickets t WHERE t.client_name = s.name) ticket
                              FROM cm_soc_clients s LEFT JOIN clients c ON c.id = s.client_id ORDER BY s.name", []);
    }
}
