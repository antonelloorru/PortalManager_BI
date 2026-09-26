<?php
/**
 * PmContractFilter — filtro globale "Codice Contratto / PM Project" (v1.9.78).
 *
 * Un solo filtro condiviso da Relazione di Servizio IT, Service Desk, Report
 * direzionale e Attività & Rendicontazione DGB. I dati delle pagine usano
 * quattro chiavi fisiche diverse per la stessa commessa:
 *
 *   project_code      cm_projects.project_code  (viste rapportini, direzionale, SD moduli/costi)
 *   project id        cm_projects.id            (cm_intervention_reports.project_id)
 *   contratto DGB     dgb_forms_activity.id_contract = cm_projects.dgb_contract_id
 *   ticket            cm_sd_messages.ticket_code = dgb_forms_activity.ticket | cm_intervention_reports.ticket
 *
 * Il filtro accetta due forme di valore — `project_code` oppure `dgb:<id_contract>`
 * — e le risolve UNA volta per richiesta in quattro liste chiuse, poi ogni query
 * applica un semplice IN (...) con parametri preparati. Nessuna sottoquery sulle
 * viste annidate: su `v_cm_sd_ticket` l'ottimizzatore di MariaDB risolve male IN
 * ed EXISTS (v1.8.88), una lista di valori no.
 *
 * Ponte bidirezionale cm_projects.dgb_contract_id: un PM Project porta con sé il
 * suo contratto DGB, un contratto DGB le commesse che vi sono collegate.
 *
 * Persistenza: la selezione vale fra le pagine (sessione). Un parametro
 * `contratti` o `contratti_set` nella richiesta la sostituisce (anche vuota =
 * rimozione); in assenza si usa quella di sessione. Il parametro storico `contract`
 * (id DGB, link dalla scheda commessa) e' accettato come selezione esplicita.
 */

declare(strict_types=1);

final class PmContractFilter
{
    public const KEY = 'contratti';
    private const SESSION = 'pm_contratti';

    private PDO $pdo;
    /** @var string[] valori selezionati, normalizzati */
    private array $sel;
    private ?array $res = null;
    private ?array $tickets = null;

    public function __construct(PDO $pdo, array $values)
    {
        $this->pdo = $pdo;
        $this->sel = self::norm($values);
    }

    /* ── ingresso ────────────────────────────────────────────────────────── */

    /** Valori ammessi: `dgb:<intero>` o codice (<= 64 caratteri, senza caratteri di controllo). */
    public static function norm($raw): array
    {
        if (is_string($raw)) $raw = $raw === '' ? [] : explode(',', $raw);
        $out = [];
        foreach ((array)$raw as $x) {
            if (!is_scalar($x)) continue;
            $x = trim((string)$x);
            if ($x === '') continue;
            if (preg_match('/^dgb:(\d{1,10})$/', $x, $m)) { $out[] = 'dgb:' . (int)$m[1]; continue; }
            if (mb_strlen($x) <= 64 && !preg_match('/[\x00-\x1F\x7F]/u', $x)) $out[] = $x;
        }
        return array_values(array_unique($out));
    }

    /** Selezione dalla richiesta, con persistenza di sessione fra le pagine. */
    public static function fromRequest(array $q): array
    {
        $legacy = (int)($q['contract'] ?? 0);
        if (array_key_exists(self::KEY, $q) || isset($q['contratti_set']) || $legacy > 0) {
            $v = self::norm($q[self::KEY] ?? []);
            if ($legacy > 0) $v = array_values(array_unique(array_merge($v, ['dgb:' . $legacy])));
            if (session_status() === PHP_SESSION_ACTIVE) $_SESSION[self::SESSION] = $v;
            return $v;
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESSION]) && is_array($_SESSION[self::SESSION])) {
            return self::norm($_SESSION[self::SESSION]);
        }
        return [];
    }

    /**
     * Forma canonica: `dgb:<id>` diventa il/i PM Project collegati, se esistono
     * (stessa risoluzione, ma l'opzione del menu risulta selezionata). Serve ai link
     * storici con `contract=<id>`. Aggiorna anche la selezione in sessione.
     */
    public static function canonical(PDO $pdo, array $sel): array
    {
        $out = [];
        foreach (self::norm($sel) as $x) {
            if (strncmp($x, 'dgb:', 4) !== 0) { $out[] = $x; continue; }
            $codes = [];
            try {
                $st = $pdo->prepare("SELECT project_code FROM cm_projects WHERE dgb_contract_id = ? AND project_code IS NOT NULL AND project_code <> ''");
                $st->execute([(int)substr($x, 4)]);
                $codes = $st->fetchAll(PDO::FETCH_COLUMN); $st->closeCursor();
            } catch (Throwable $e) {}
            foreach ($codes ?: [$x] as $c) $out[] = (string)$c;
        }
        $out = array_values(array_unique($out));
        if ($out !== $sel && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION[self::SESSION])) $_SESSION[self::SESSION] = $out;
        return $out;
    }

    /* ── risoluzione ─────────────────────────────────────────────────────── */

    public function active(): bool { return $this->sel !== []; }
    public function values(): array { return $this->sel; }

    private function resolve(): array
    {
        if ($this->res !== null) return $this->res;
        $codes = $ids = [];
        foreach ($this->sel as $x) {
            if (strncmp($x, 'dgb:', 4) === 0) $ids[] = (int)substr($x, 4); else $codes[] = $x;
        }
        $pids = [];
        if ($codes || $ids) {
            $w = []; $a = [];
            if ($codes) { $w[] = 'project_code IN (' . self::ph($codes) . ')'; array_push($a, ...$codes); }
            if ($ids)   { $w[] = 'dgb_contract_id IN (' . self::ph($ids) . ')'; array_push($a, ...$ids); }
            try {
                $st = $this->pdo->prepare('SELECT id, project_code, dgb_contract_id FROM cm_projects WHERE ' . implode(' OR ', $w));
                $st->execute($a);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $pids[] = (int)$r['id'];
                    if ((string)$r['project_code'] !== '') $codes[] = (string)$r['project_code'];
                    if ($r['dgb_contract_id'] !== null)   $ids[] = (int)$r['dgb_contract_id'];
                }
                $st->closeCursor();
            } catch (Throwable $e) {}
        }
        return $this->res = [
            'codes' => array_values(array_unique($codes)),
            'ids'   => array_values(array_unique($ids)),
            'pids'  => array_values(array_unique($pids)),
        ];
    }

    public function codes(): array { return $this->resolve()['codes']; }
    public function ids(): array   { return $this->resolve()['ids']; }
    public function pids(): array  { return $this->resolve()['pids']; }

    /** Ticket del Service Desk collegati: attività DGB del contratto o rapportini della commessa. */
    public function tickets(): array
    {
        if ($this->tickets !== null) return $this->tickets;
        $t = [];
        $r = $this->resolve();
        try {
            if ($r['ids']) {
                $st = $this->pdo->prepare('SELECT DISTINCT ticket FROM dgb_forms_activity
                                            WHERE id_contract IN (' . self::ph($r['ids']) . ')
                                              AND ticket IS NOT NULL AND ticket <> \'\'');
                $st->execute($r['ids']);
                $t = array_merge($t, $st->fetchAll(PDO::FETCH_COLUMN));
                $st->closeCursor();
            }
            if ($r['codes']) {
                $st = $this->pdo->prepare('SELECT DISTINCT ticket FROM cm_intervention_reports
                                            WHERE project_code IN (' . self::ph($r['codes']) . ')
                                              AND ticket IS NOT NULL AND ticket <> \'\'');
                $st->execute($r['codes']);
                $t = array_merge($t, $st->fetchAll(PDO::FETCH_COLUMN));
                $st->closeCursor();
            }
        } catch (Throwable $e) {}
        return $this->tickets = array_values(array_unique(array_map('strval', $t)));
    }

    /**
     * Condizione SQL per una colonna.
     * $kind: code | id | pid | ticket. Filtro inattivo → '1=1'; attivo ma senza
     * corrispondenze → '0=1' (nessuna riga, mai "tutte").
     */
    public function sql(string $kind, string $col, array &$args): string
    {
        if (!$this->active()) return '1=1';
        switch ($kind) {
            case 'code':   $v = $this->codes(); break;
            case 'id':     $v = $this->ids(); break;
            case 'pid':    $v = $this->pids(); break;
            case 'ticket': $v = $this->tickets(); break;
            default: throw new InvalidArgumentException('Tipo filtro contratto non valido: ' . $kind);
        }
        if (!$v) return '0=1';
        foreach ($v as $x) $args[] = $x;
        return "$col IN (" . self::ph($v) . ')';
    }

    /** Come sql(), preceduto da " AND " se il filtro e' attivo; stringa vuota altrimenti. */
    public function andSql(string $kind, string $col, array &$args): string
    {
        return $this->active() ? ' AND ' . $this->sql($kind, $col, $args) : '';
    }

    private static function ph(array $v): string { return implode(',', array_fill(0, count($v), '?')); }

    /* ── opzioni, etichette, UI ──────────────────────────────────────────── */

    /**
     * value => etichetta. `$scopeSql` (opzionale) e' una SELECT di codici commessa
     * che restringe l'elenco a quelli rilevanti per la pagina; i contratti DGB senza
     * PM Project sono sempre in coda.
     */
    public static function options(PDO $pdo, ?string $scopeSql = null): array
    {
        $out = [];
        try {
            $src = $scopeSql !== null
                ? "SELECT DISTINCT s.code FROM ($scopeSql) s WHERE s.code IS NOT NULL AND s.code <> ''"
                : "SELECT project_code AS code FROM cm_projects WHERE project_code IS NOT NULL AND project_code <> ''";
            $st = $pdo->query(
                "SELECT x.code, MAX(p.dgb_contract_id) AS cid, MAX(NULLIF(c.code,'')) AS dgb_code,
                        MAX(COALESCE(cl.name, p.client_raw)) AS cliente
                   FROM ($src) x
                   LEFT JOIN cm_projects p ON p.project_code = x.code
                   LEFT JOIN dgb_forms_contract c ON c.id = p.dgb_contract_id
                   LEFT JOIN clients cl ON cl.id = p.client_id
                  GROUP BY x.code ORDER BY x.code");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $l = (string)$r['code'];
                if ($r['dgb_code'] !== null)  $l .= ' · ' . $r['dgb_code'];
                elseif ($r['cid'] !== null)   $l .= ' · Contratto #' . (int)$r['cid'];
                if ((string)$r['cliente'] !== '') $l .= ' · ' . $r['cliente'];
                $out[(string)$r['code']] = $l;
            }
        } catch (Throwable $e) {}
        try {
            $st = $pdo->query(
                "SELECT x.id_contract AS cid, NULLIF(c.code,'') AS dgb_code, cli.name AS cliente
                   FROM (SELECT DISTINCT id_contract FROM dgb_forms_activity
                          WHERE id_contract IS NOT NULL AND COALESCE(deleted,0) <> 1) x
                   LEFT JOIN cm_projects p ON p.dgb_contract_id = x.id_contract
                   LEFT JOIN dgb_forms_contract c ON c.id = x.id_contract
                   LEFT JOIN clients cli ON cli.id = c.id_customer_comp
                  WHERE p.id IS NULL
                  ORDER BY dgb_code IS NULL, dgb_code, x.id_contract");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $l = ($r['dgb_code'] ?? ('Contratto #' . (int)$r['cid'])) . ' · DGB senza PM Project';
                if ((string)($r['cliente'] ?? '') !== '') $l .= ' · ' . $r['cliente'];
                $out['dgb:' . (int)$r['cid']] = $l;
            }
        } catch (Throwable $e) {}
        return $out;
    }

    public static function label(string $v, array $opts): string
    {
        return $opts[$v] ?? (strncmp($v, 'dgb:', 4) === 0 ? 'Contratto #' . substr($v, 4) : $v);
    }

    /** "Contratto / PM Project: a, b" oppure stringa vuota. */
    public static function describe(array $sel, array $opts): string
    {
        return $sel ? 'Contratto / PM Project: ' . implode(', ', array_map(fn($v) => self::label($v, $opts), $sel)) : '';
    }

    /** Query string da aggiungere ai link (stampa, export, drill-down). */
    public static function query(array $sel): array
    {
        return $sel ? [self::KEY => implode(',', $sel)] : [];
    }

    private static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    /**
     * Campo del form: select multipla con ricerca (pm-ms) + marcatore che rende
     * esplicita la selezione, anche vuota (una select multipla senza voci scelte
     * non invia nulla: senza marcatore la rimozione non arriverebbe al server).
     */
    public static function field(array $opts, array $sel, string $hint = ''): string
    {
        $h = '<div class="form-group" style="grid-column:1/-1"><label>Codice Contratto / PM Project '
           . '<span class="pm-multi">(multipla · filtro globale' . ($hint !== '' ? ' · ' . self::e($hint) : '') . ')</span></label>'
           . '<input type="hidden" name="contratti_set" value="1">'
           . '<select name="contratti[]" multiple size="4" class="pm-ms" data-placeholder="Tutti i contratti">';
        foreach ($opts as $v => $l) {
            $v = (string)$v;
            $h .= '<option value="' . self::e($v) . '"' . (in_array($v, $sel, true) ? ' selected' : '') . '>' . self::e($l) . '</option>';
        }
        foreach (array_diff($sel, array_map('strval', array_keys($opts))) as $v) {
            $h .= '<option value="' . self::e($v) . '" selected>' . self::e(self::label($v, $opts)) . ' · (fuori elenco)</option>';
        }
        return $h . '</select></div>';
    }

    /** Avviso visibile quando il filtro e' attivo (anche se ereditato da un'altra pagina). */
    public static function banner(array $sel, array $opts, string $clearUrl, string $note = ''): string
    {
        if (!$sel) return '';
        $lbl = implode(', ', array_map(fn($v) => self::label($v, $opts), array_slice($sel, 0, 6)))
             . (count($sel) > 6 ? ' e altri ' . (count($sel) - 6) : '');
        return '<div class="alert alert-info" style="font-size:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
             . '<i class="fa-solid fa-file-contract"></i><span><strong>Filtro contratto attivo</strong> (vale su tutte le sezioni e le pagine collegate): '
             . self::e($lbl) . ($note !== '' ? ' <span style="color:var(--muted)">— ' . self::e($note) . '</span>' : '') . '</span>'
             . '<a class="btn btn-sm" style="margin-left:auto" href="' . self::e($clearUrl) . '">Rimuovi filtro contratto</a></div>';
    }
}
