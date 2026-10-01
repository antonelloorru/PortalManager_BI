<?php
/**
 * PortalManager — app/PositionFilter.php  (v1.9.86)
 *
 * Filtri della pagina «Posizioni aperte» condivisi con gli export XLSX/PDF, così vista
 * ed export restituiscono sempre lo stesso insieme. Multi-valore via GET (array o CSV,
 * compatibile con i link storici ?f_st=open&f_br=3&f_pr=Alta), parametri preparati,
 * valori enum validati su whitelist.
 *
 *   $F = PositionFilter::parse();
 *   $params = [];
 *   $w  = PositionFilter::where($F, $params, 'jp');
 *   $w  = array_merge($w, PositionFilter::scope($roleId, $userId, 'jp'));
 */
declare(strict_types=1);

require_once __DIR__ . '/PmFilter.php';

final class PositionFilter
{
    public const STATUS   = ['draft' => 'Bozza', 'open' => 'Aperta', 'paused' => 'In pausa', 'closed' => 'Chiusa', 'cancelled' => 'Annullata'];
    public const PRIORITY = ['Urgente' => 'Urgente', 'Alta' => 'Alta', 'Media' => 'Media', 'Bassa' => 'Bassa'];
    public const CONTRACT = ['Indeterminato', 'Determinato', 'Somministrazione', 'Consulenza', 'Stage'];
    public const REMOTE   = ['In sede', 'Ibrido', 'Full Remote'];
    public const ND       = '(n.d.)';

    /** Chiavi multi-valore e chiavi singole (sì/no o soglie). */
    private const MULTI  = ['f_st', 'f_pr', 'f_br', 'f_dep', 'f_loc', 'f_ct', 'f_rem', 'f_tl', 'f_rq', 'f_cli', 'f_stage'];
    private const BOOL   = ['f_li', 'f_cand', 'f_fill', 'f_late', 'f_ral'];
    private const DATES  = ['op_from', 'op_to', 'tg_from', 'tg_to', 'cl_from', 'cl_to'];

    public static function parse(?array $src = null): array
    {
        $src ??= $_GET;
        $F = ['q' => mb_substr(trim((string)($src['q'] ?? '')), 0, 100)];
        foreach (self::MULTI as $k) $F[$k] = PmFilter::values($k, $src);
        // whitelist enum / interi
        $F['f_st']  = array_values(array_intersect($F['f_st'], array_keys(self::STATUS)));
        $F['f_pr']  = array_values(array_intersect($F['f_pr'], array_keys(self::PRIORITY)));
        $F['f_ct']  = array_values(array_intersect($F['f_ct'], array_merge(self::CONTRACT, [self::ND])));
        $F['f_rem'] = array_values(array_intersect($F['f_rem'], array_merge(self::REMOTE, [self::ND])));
        $F['f_stage'] = array_values(array_intersect($F['f_stage'], array_keys(self::stages())));
        // id interi; 0 = «non assegnato» / «nessun cliente» (f_br=0 storico = tutti)
        foreach (['f_br', 'f_tl', 'f_rq', 'f_cli'] as $k) {
            $F[$k] = array_values(array_unique(array_map('intval', array_filter($F[$k], 'ctype_digit'))));
        }
        $F['f_br'] = array_values(array_filter($F['f_br'], fn($v) => $v > 0));
        foreach (self::BOOL as $k) {
            $v = (string)($src[$k] ?? '');
            $F[$k] = in_array($v, ['1', '0'], true) ? $v : '';
        }
        foreach (self::DATES as $k) $F[$k] = PmFilter::date($k, $src);
        $age = (string)($src['age_min'] ?? '');
        $F['age_min'] = ctype_digit($age) && (int)$age > 0 ? min((int)$age, 3650) : 0;
        return $F;
    }

    /** Condizioni WHERE (già senza '1=1'). $a = alias di job_positions. */
    public static function where(array $F, array &$params, string $a = 'jp'): array
    {
        $w = [];
        if ($F['q'] !== '') {
            $lk = '%' . addcslashes($F['q'], '%_\\') . '%';
            $cols = ['title', 'department', 'location', 'description', 'required_skills', 'nice_to_have', 'hard_skills', 'soft_skills', 'linkedin_code'];
            $w[] = '(' . implode(' OR ', array_map(fn($c) => "$a.`$c` LIKE ?", $cols))
                 . " OR EXISTS (SELECT 1 FROM position_clients pcq JOIN clients cq ON cq.id = pcq.client_id
                                 WHERE pcq.position_id = $a.id AND cq.name LIKE ?))";
            for ($i = 0; $i <= count($cols); $i++) $params[] = $lk;
        }
        $w[] = PmFilter::in("$a.status",   $F['f_st'], $params);
        $w[] = PmFilter::in("$a.priority", $F['f_pr'], $params);
        $w[] = PmFilter::in("$a.brand_id", $F['f_br'], $params);
        $w[] = PmFilter::in("COALESCE(NULLIF(TRIM($a.department),''),'" . self::ND . "')", $F['f_dep'], $params);
        $w[] = PmFilter::in("COALESCE(NULLIF(TRIM($a.location),''),'" . self::ND . "')",   $F['f_loc'], $params);
        $w[] = PmFilter::in("COALESCE(NULLIF($a.contract_type,''),'" . self::ND . "')",    $F['f_ct'],  $params);
        $w[] = PmFilter::in("COALESCE(NULLIF($a.remote_policy,''),'" . self::ND . "')",    $F['f_rem'], $params);
        $w[] = PmFilter::in("COALESCE($a.team_leader_id,0)", $F['f_tl'], $params);
        $w[] = PmFilter::in("COALESCE($a.requested_by,0)",   $F['f_rq'], $params);
        if ($F['f_cli']) {
            $real = array_values(array_filter($F['f_cli'], fn($v) => $v > 0));
            $c = [];
            if ($real) $c[] = "EXISTS (SELECT 1 FROM position_clients pcf WHERE pcf.position_id = $a.id AND "
                            . PmFilter::in('pcf.client_id', $real, $params) . ")";
            if (in_array(0, $F['f_cli'], true)) $c[] = "NOT EXISTS (SELECT 1 FROM position_clients pcn WHERE pcn.position_id = $a.id)";
            $w[] = '(' . implode(' OR ', $c) . ')';
        }
        if ($F['f_stage']) {
            $w[] = "EXISTS (SELECT 1 FROM candidate_applications cas WHERE cas.position_id = $a.id AND "
                 . PmFilter::in('cas.stage', $F['f_stage'], $params) . ")";
        }
        $w[] = PmFilter::range("$a.opened_at",   $F['op_from'], $F['op_to'], $params);
        $w[] = PmFilter::range("$a.target_date", $F['tg_from'], $F['tg_to'], $params);
        $w[] = PmFilter::range("$a.closed_at",   $F['cl_from'], $F['cl_to'], $params);
        if ($F['age_min']) {
            $w[] = "$a.opened_at IS NOT NULL AND DATEDIFF(COALESCE($a.closed_at, CURDATE()), $a.opened_at) >= ?";
            $params[] = $F['age_min'];
        }
        if ($F['f_li'] !== '')   $w[] = ($F['f_li'] === '1' ? '' : 'NOT ') . "(COALESCE($a.linkedin_code,'') <> '')";
        if ($F['f_ral'] !== '')  $w[] = ($F['f_ral'] === '1' ? '' : 'NOT ') . "(COALESCE($a.ral_min,0) > 0 OR COALESCE($a.ral_max,0) > 0)";
        if ($F['f_cand'] !== '') $w[] = ($F['f_cand'] === '1' ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM candidate_applications cac WHERE cac.position_id = $a.id)";
        if ($F['f_fill'] !== '') {
            $hired = "(SELECT COUNT(*) FROM candidate_applications cah WHERE cah.position_id = $a.id AND cah.stage = 'hired')";
            $w[] = $F['f_fill'] === '1' ? "$hired >= GREATEST(COALESCE($a.positions_expected,1),1)"
                                        : "$hired <  GREATEST(COALESCE($a.positions_expected,1),1)";
        }
        if ($F['f_late'] !== '') {
            $late = "($a.target_date IS NOT NULL AND $a.target_date < CURDATE() AND $a.status IN ('draft','open','paused'))";
            $w[] = $F['f_late'] === '1' ? $late : "NOT $late";
        }
        return array_values(array_filter($w, fn($c) => $c !== '1=1' && $c !== ''));
    }

    /** Visibilità per ruolo (stessa regola della pagina): TeamLeader → proprie, Recruiter → aperte/in pausa. */
    public static function scope(int $roleId, int $userId, string $a = 'jp'): array
    {
        if ($roleId === 4) return ["$a.team_leader_id = " . (int)$userId];
        if ($roleId === 5) return ["$a.status IN ('open','paused')"];
        return [];
    }

    /** Numero di filtri attivi (per badge). */
    public static function activeCount(array $F): int
    {
        $n = 0;
        foreach ($F as $v) $n += is_array($v) ? ($v ? 1 : 0) : (($v !== '' && $v !== null && $v !== 0) ? 1 : 0);
        return $n;
    }

    /** Query string dei soli filtri attivi (export, link). */
    public static function query(array $F): string
    {
        $q = [];
        foreach ($F as $k => $v) {
            if (is_array($v)) { if ($v) $q[$k] = array_values($v); }
            elseif ($v !== '' && $v !== null && $v !== 0) $q[$k] = $v;
        }
        return http_build_query($q);
    }

    public static function stages(): array
    {
        return ['cv_received' => 'CV ricevuto', 'screening' => 'Screening', 'tech_test' => 'Test tecnico',
                'hr_interview' => 'Colloquio HR', 'tech_interview' => 'Colloquio tecnico',
                'offer_sent' => 'Offerta inviata', 'hired' => 'Assunto', 'rejected' => 'Scartato'];
    }

    /**
     * Opzioni dei menu, limitate al perimetro visibile all'utente ($scope), con conteggio posizioni.
     * @return array<string, array<string,string>>
     */
    public static function options(PDO $pdo, array $scope = []): array
    {
        $sw = $scope ? ' WHERE ' . implode(' AND ', $scope) : '';
        $cnt = static function (string $expr) use ($pdo, $sw): array {
            $out = [];
            try {
                foreach ($pdo->query("SELECT $expr AS v, COUNT(*) n FROM job_positions jp$sw GROUP BY v ORDER BY v")->fetchAll(PDO::FETCH_NUM) as [$v, $n]) {
                    $out[(string)$v] = (int)$n;
                }
            } catch (Throwable $e) {}
            return $out;
        };
        $person = static function (string $col) use ($pdo, $sw): array {
            $out = [];
            try {
                $rows = $pdo->query(
                    "SELECT COALESCE(jp.$col,0) pid,
                            COALESCE(NULLIF(TRIM(CONCAT_WS(' ', e.last_name, e.first_name)),''), u.display_name, '" . self::ND . "') pname,
                            COUNT(*) n
                       FROM job_positions jp
                       LEFT JOIN users u ON u.id = jp.$col
                       LEFT JOIN employees e ON e.id = u.employee_id$sw
                      GROUP BY pid, pname ORDER BY (pid = 0), pname")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $out[(string)$r['pid']] = ((int)$r['pid'] === 0 ? '(non assegnato)' : $r['pname']) . ' (' . $r['n'] . ')';
            } catch (Throwable $e) {}
            return $out;
        };
        $O = [];
        $lab = static function (array $counts, ?array $labels = null): array {
            $out = [];
            if ($labels !== null) {
                foreach ($labels as $k => $l) if (isset($counts[$k])) $out[$k] = "$l ({$counts[$k]})";
            } else {
                foreach ($counts as $k => $n) $out[$k] = "$k ($n)";
            }
            return $out;
        };
        $O['f_st']  = $lab($cnt('jp.status'), self::STATUS);
        $O['f_pr']  = $lab($cnt('jp.priority'), self::PRIORITY);
        $O['f_dep'] = $lab($cnt("COALESCE(NULLIF(TRIM(jp.department),''),'" . self::ND . "')"));
        $O['f_loc'] = $lab($cnt("COALESCE(NULLIF(TRIM(jp.location),''),'" . self::ND . "')"));
        $O['f_ct']  = $lab($cnt("COALESCE(NULLIF(jp.contract_type,''),'" . self::ND . "')"));
        $O['f_rem'] = $lab($cnt("COALESCE(NULLIF(jp.remote_policy,''),'" . self::ND . "')"));
        $O['f_tl']  = $person('team_leader_id');
        $O['f_rq']  = $person('requested_by');
        $O['f_br'] = [];
        try {
            foreach ($pdo->query("SELECT b.id, b.name, COUNT(*) n FROM job_positions jp JOIN brands b ON b.id = jp.brand_id$sw
                                   GROUP BY b.id, b.name ORDER BY b.name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $O['f_br'][(string)$r['id']] = $r['name'] . ' (' . $r['n'] . ')';
            }
        } catch (Throwable $e) {}
        $O['f_cli'] = ['0' => '(nessun cliente)'];
        try {
            foreach ($pdo->query("SELECT c.id, c.name, COUNT(DISTINCT pc.position_id) n
                                    FROM clients c LEFT JOIN position_clients pc ON pc.client_id = c.id
                                   WHERE c.is_active = 1 OR pc.client_id IS NOT NULL
                                   GROUP BY c.id, c.name ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $O['f_cli'][(string)$r['id']] = $r['name'] . ((int)$r['n'] ? ' (' . $r['n'] . ')' : '');
            }
        } catch (Throwable $e) {}
        $O['f_stage'] = self::stages();
        return $O;
    }
}
