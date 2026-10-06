<?php
/**
 * app/SocIngest.php — v1.10.06
 * Ingestion multi-sorgente del Service SOC: eventi dei ticket del sistema di gestione SOC.
 *
 * Sorgenti (stesso tracciato, stessa normalizzazione, stessa chiave):
 *   file — export «lista eventi ticket» del gestionale (XLSX o CSV, intestazioni come nel gestionale);
 *   db   — sincronizzazione dal database SOC (stesso schema del gestionale, istanza e connessione separate,
 *          sola lettura, configurazione in cm_soc_source_db, password cifrata con APP_SECRET).
 *
 * Chiave evento = sha1(ticket | istante | tipo | stato prima | stato dopo | progressivo): un evento importato
 * dal file e lo stesso evento letto dal DB coincidono, quindi le due sorgenti si integrano senza doppioni.
 * Campi dell'evento (tipo, autore, oggetto, coda): vale il primo valore registrato. Campi del ticket (categoria, cliente,
 * incaricato, esito…): vale l'ultimo valore non vuoto ricevuto, da qualunque sorgente.
 * Dopo ogni import: ticket ricostruiti (cm_soc_tickets) e abbinamenti automatici persone/clienti.
 */
declare(strict_types=1);

final class SocIngest
{
    /** campo interno => intestazioni accettate (normalizzate: minuscole, spazi singoli). */
    public const COLS = [
        'event_at'      => ['data evento', 'data', 'data/ora', 'ricevuto il', 'received_at'],
        'ticket_code'   => ['codice', 'codice ticket', 'ticket', 'n. ticket'],
        'event_label'   => ['evento', 'tipo evento', 'type'],
        'status_before' => ['stato prima', 'stato precedente', 't_status_before'],
        'status_after'  => ['stato dopo', 'stato successivo', 't_status_after'],
        'author_name'   => ['autore', 'mittente', 'author'],
        'subject'       => ['titolo', 'oggetto', 'subject'],
        'queue_name'    => ['coda', 'queue'],
        'mailbox'       => ['casella di posta', 'casella', 'mailbox'],
        'owner_name'    => ['responsabile'],
        'assignee_name' => ['incaricato', 'assegnatario', 'assegnato a'],
        'ticket_type'   => ['tipo', 'tipo ticket'],
        'category'      => ['categoria'],
        'resolution'    => ['risoluzione'],
        'client_name'   => ['cliente'],
        'soc_contract'  => ['commessa'],
        'duration'      => ['durata'],
        'source_ref'    => ['id evento', 'id messaggio', 'id'],
    ];
    public const REQUIRED = ['event_at', 'ticket_code'];
    public const NOT_ASSIGNED = ['non assegnato', 'nessuno', '-', ''];

    /** Query predefinita sul DB SOC (schema del gestionale: tt_article, tt_queue, dgb_operator). */
    public const DEFAULT_SQL = "SELECT
    a.id AS `id evento`,
    a.received_at AS `data evento`,
    SUBSTRING_INDEX(a.code, '_', 2) AS `codice`,
    a.type AS `evento`,
    q.name AS `coda`,
    a.t_status_before AS `stato prima`,
    a.t_status_after AS `stato dopo`,
    LEFT(COALESCE(a.subject, ''), 400) AS `titolo`,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(o.second_name, ''), ' ', COALESCE(o.first_name, ''))), ''), a.from_name) AS `autore`
FROM tt_article a
LEFT JOIN dgb_operator o ON o.id = a.id_author
LEFT JOIN tt_queue q ON q.id = a.id_tt_queue
WHERE a.received_at >= ?
  AND a.code LIKE ?
ORDER BY a.id";

    private array $closed;
    /** v1.10.07 — pipeline SocSync: più sorgenti in un'esecuzione, ricostruzione ticket una sola volta alla fine. */
    public bool $deferFinalize = false;
    public string $trigger = 'manuale';
    /** v1.10.10 — origine della categoria nell'ultima lettura dal DB SOC */
    public string $schemaNote = '';

    public function __construct(private PDO $pdo)
    {
        $this->closed = self::closedStates($pdo);
    }

    public static function closedStates(PDO $pdo): array
    {
        $v = 'CHIUSO,CHIUSO DAL CLIENTE';
        try { $x = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='soc.closed_states'")->fetchColumn(); if (is_string($x) && trim($x) !== '') $v = $x; } catch (Throwable $e) {}
        return array_values(array_filter(array_map(fn($s) => strtoupper(trim($s)), explode(',', $v))));
    }

    /* ── normalizzazione ─────────────────────────────────────────────── */

    public static function normHeader(string $h): string
    {
        $h = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $h)));
        return mb_strtolower($h, 'UTF-8');
    }

    /** Mappa intestazione → campo. @return array<string,string> header originale => campo */
    public static function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $h) {
            $n = self::normHeader((string)$h);
            foreach (self::COLS as $field => $aliases) if (in_array($n, $aliases, true) && !in_array($field, $map, true)) { $map[(string)$h] = $field; break; }
        }
        return $map;
    }

    public static function kind(string $label): string
    {
        $l = mb_strtolower(trim($label), 'UTF-8');
        if ($l === '') return 'altro';
        if (str_contains($l, 'support') || str_contains($l, 'agent')) return 'supporto';
        if (str_contains($l, 'client') || str_contains($l, 'customer')) return 'cliente';
        if (str_contains($l, 'annotaz') || str_contains($l, 'nota') || str_contains($l, 'note') || str_contains($l, 'internal')) return 'nota';
        if ($l === 'new' || str_contains($l, 'apert') || str_contains($l, 'open') || str_contains($l, 'creat') || str_contains($l, 'nuovo')) return 'apertura';
        return 'altro';
    }

    public static function parseDateTime($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        if (is_numeric($s) && (float)$s > 20000 && (float)$s < 80000) {           // seriale Excel
            $ts = (int)round(((float)$s - 25569) * 86400);
            return gmdate('Y-m-d H:i:s', $ts);
        }
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'd-m-Y H:i:s', 'd.m.Y H:i:s', 'd/m/Y', 'Y-m-d'] as $fmt) {
            $d = DateTime::createFromFormat('!' . $fmt, $s);
            if ($d && $d->format($fmt) === $s) return $d->format('Y-m-d H:i:s');
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /** «292g 19h 49m 5s», «3d 4h», «HH:MM:SS», minuti → minuti. */
    public static function parseDuration($v): ?int
    {
        $s = mb_strtolower(trim((string)$v), 'UTF-8');
        if ($s === '') return null;
        if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $s, $m)) return (int)$m[1] * 60 + (int)$m[2];
        if (is_numeric($s)) return (int)round((float)$s);
        $tot = 0.0; $hit = false;
        foreach (['/(\d+)\s*(g|d|gg|giorni?)\b/u' => 1440, '/(\d+)\s*(h|ore?)\b/u' => 60, '/(\d+)\s*(m|min|minuti?)\b/u' => 1, '/(\d+)\s*(s|sec|secondi?)\b/u' => 1 / 60] as $re => $mul)
            if (preg_match($re, $s, $m)) { $tot += (int)$m[1] * $mul; $hit = true; }
        return $hit ? (int)round($tot) : null;
    }

    /** Riga grezza (campo => valore) → evento normalizzato o null. */
    public function normalize(array $r): ?array
    {
        $t = static fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($r[$k] ?? ''))), 0, $max);
        $at = self::parseDateTime($r['event_at'] ?? null);
        $code = strtoupper($t('ticket_code', 60));
        if ($code === '' && preg_match('/\b([A-Z]{2,6}_\d{6,})\b/', (string)($r['subject'] ?? ''), $m)) $code = $m[1];
        if (preg_match('/^([A-Z]{2,6}_\d{6,})_\d+$/', $code, $m)) $code = $m[1];      // codice messaggio → ticket
        if ($at === null || $code === '') return null;
        $label = $t('event_label', 80);
        $e = [
            'event_at' => $at, 'ticket_code' => $code, 'event_label' => $label, 'event_kind' => self::kind($label),
            'status_before' => strtoupper($t('status_before', 40)), 'status_after' => strtoupper($t('status_after', 40)),
            'author_name' => $t('author_name', 190), 'subject' => $t('subject', 400), 'queue_name' => $t('queue_name', 120),
            'mailbox' => $t('mailbox', 120), 'owner_name' => $t('owner_name', 150), 'assignee_name' => $t('assignee_name', 150),
            'ticket_type' => $t('ticket_type', 20), 'category' => $t('category', 120), 'resolution' => strtoupper($t('resolution', 60)),
            'client_name' => $t('client_name', 190), 'soc_contract' => $t('soc_contract', 60),
            'duration_min' => self::parseDuration($r['duration'] ?? ''), 'source_ref' => $t('source_ref', 40),
        ];
        if ($e['event_kind'] === 'altro' && $e['status_before'] === '' && $label !== '' && strtoupper($label) === $e['status_after']) $e['event_kind'] = 'apertura';
        return $e;
    }

    public static function baseKey(array $e): string
    {
        return $e['ticket_code'] . '|' . $e['event_at'] . '|' . $e['event_kind'] . '|' . $e['status_before'] . '|' . $e['status_after'];
    }

    /* ── import ──────────────────────────────────────────────────────── */

    /** Import da file XLSX o CSV. */
    public function importFile(string $path, string $origName, ?int $userId): array
    {
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $batch = $this->openBatch('file', $origName, $this->trigger, $userId);
        $rows = []; $headers = [];
        try {
            if ($ext === 'xlsx') {
                require_once __DIR__ . '/XlsxReader.php';
                XlsxReader::each($path, function (array $row) use (&$rows) { $rows[] = $row; }, 0, $headers,
                    ['header_hints' => ['data evento', 'codice', 'evento', 'stato prima', 'stato dopo', 'titolo', 'autore']]);
            } elseif ($ext === 'csv' || $ext === 'txt') {
                [$headers, $rows] = self::readCsv($path);
            } else throw new RuntimeException('Formato non supportato: usare .xlsx o .csv');
        } catch (Throwable $e) {
            return $this->closeBatch($batch, 'error', ['message' => 'Lettura non riuscita: ' . $e->getMessage()]);
        }
        $map = self::mapHeaders($headers);
        $missing = array_diff(self::REQUIRED, array_values($map));
        if ($missing) return $this->closeBatch($batch, 'error', ['message' => 'Colonne obbligatorie mancanti: ' . implode(', ', array_map(fn($f) => self::COLS[$f][0], $missing))
            . '. Intestazioni trovate: ' . implode(', ', array_slice($headers, 0, 20))]);
        $mapped = [];
        foreach ($rows as $row) { $o = []; foreach ($map as $h => $f) $o[$f] = $row[$h] ?? null; $mapped[] = $o; }
        return $this->ingest($batch, 'file', $mapped);
    }

    /** @return array{0:string[],1:array} */
    public static function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) throw new RuntimeException('File non leggibile');
        $first = (string)fgets($fh); rewind($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $headers = null; $rows = [];
        while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            if ($headers === null) { $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$r[0]); $headers = array_map('trim', $r); continue; }
            if (count(array_filter($r, fn($x) => trim((string)$x) !== '')) === 0) continue;
            $enc = static fn($x) => mb_check_encoding((string)$x, 'UTF-8') ? (string)$x : mb_convert_encoding((string)$x, 'UTF-8', 'Windows-1252');
            $rows[] = array_combine($headers, array_map($enc, array_pad(array_slice($r, 0, count($headers)), count($headers), '')));
        }
        fclose($fh);
        return [$headers ?? [], $rows];
    }

    /** Sincronizzazione dal DB SOC configurato. */
    public function importDb(?int $userId, string $trigger = 'manuale', ?int $days = null): array
    {
        require_once __DIR__ . '/SourceDb.php';
        require_once __DIR__ . '/Env.php';
        $cfgRow = $this->pdo->query("SELECT * FROM cm_soc_source_db WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $batch = $this->openBatch('db', $cfgRow ? ($cfgRow['label'] . ' — ' . (!empty($cfgRow['use_gestionale']) ? 'server del gestionale' : $cfgRow['host']) . '/' . $cfgRow['dbname']) : 'DB SOC', $trigger, $userId);
        if (!$cfgRow) return $this->closeBatch($batch, 'error', ['message' => 'Connessione al DB SOC non configurata']);
        try {
            $cfgRow = self::resolveSource($this->pdo, $cfgRow);
            $src = SourceDb::connect(SourceDb::configFromRow($cfgRow));
            [$sql, $params] = self::extractQuery($cfgRow, $days, $src);
            $this->schemaNote = trim((string)($cfgRow['extract_sql'] ?? '')) !== '' ? 'query personalizzata' : self::defaultSql($src)[1];
            $st = $src->query($sql, $params);
            $raw = [];
            while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                $o = []; $map = $map ?? self::mapHeaders(array_keys($r));
                foreach ($map as $h => $f) $o[$f] = $r[$h];
                $raw[] = $o;
                if (count($raw) > 500000) throw new RuntimeException('Oltre 500.000 eventi: ridurre la finestra di sincronizzazione');
            }
            if (isset($map) && ($miss = array_diff(self::REQUIRED, array_values($map))))
                throw new RuntimeException('La query non restituisce le colonne: ' . implode(', ', array_map(fn($f) => self::COLS[$f][0], $miss)));
        } catch (Throwable $e) {
            $err = self::connError($this->pdo, $e, $cfgRow);
            $this->pdo->prepare("UPDATE cm_soc_source_db SET last_sync_at = NOW(), last_sync_note = ? WHERE id = ?")->execute([mb_substr('Errore: ' . $err, 0, 255), $cfgRow['id']]);
            return $this->closeBatch($batch, 'error', ['message' => 'DB SOC: ' . $err]);
        }
        $res = $this->ingest($batch, 'db', $raw);
        if ($this->schemaNote !== '') {
            $res['message'] = ($res['message'] ?? '') . ' · ' . $this->schemaNote;
            $this->pdo->prepare("UPDATE cm_soc_batches SET message = LEFT(CONCAT(COALESCE(message, ''), ' · ', ?), 1000) WHERE id = ?")->execute([$this->schemaNote, $batch]);
        }
        $this->pdo->prepare("UPDATE cm_soc_source_db SET last_sync_at = NOW(), last_sync_note = ? WHERE id = ?")->execute([mb_substr($res['message'], 0, 255), $cfgRow['id']]);
        return $res;
    }

    /**
     * v1.10.08 — Parametri effettivi della connessione al DB SOC.
     * Con `use_gestionale` = 1 driver, host, porta, utente e password sono quelli della «Connessione al
     * gestionale» (cm_source_db attiva, stessa password cifrata con APP_SECRET, stesso SourceDb::connect):
     * del DB SOC restano propri solo database, schema, timeout, finestra, prefisso e query.
     */
    public static function resolveSource(PDO $pdo, array $row): array
    {
        if (empty($row['use_gestionale'])) { $row['cred_origin'] = 'propria'; return $row; }
        $g = $pdo->query("SELECT * FROM cm_source_db WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$g) throw new RuntimeException('Il DB SOC usa server e credenziali della «Connessione al gestionale», che non è configurata o non è attiva.');
        foreach (['driver', 'host', 'port', 'username', 'password_enc'] as $k) $row[$k] = $g[$k];
        $row['cred_origin'] = 'gestionale';
        return $row;
    }

    /** v1.10.08 — Messaggio d'errore di connessione con l'indicazione utile a risolverlo. */
    public static function connError(PDO $pdo, Throwable $e, array $row): string
    {
        $m = $e->getMessage();
        $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
        if (!$code && preg_match('/\[(\d{4})\]/', $m, $x)) $code = (int)$x[1];
        $origin = $row['cred_origin'] ?? (!empty($row['use_gestionale']) ? 'gestionale' : 'propria');
        if ($code === 1045 || $code === 1698) {
            if ($origin === 'gestionale') return $m . ' — Sono le credenziali della «Connessione al gestionale»: verificarle lì (Test connessione).';
            $g = null;
            try { $g = $pdo->query("SELECT username, host FROM cm_source_db WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Throwable $t) {}
            return $m . ($g ? ' — Se server e credenziali coincidono con quelli del gestionale (' . $g['username'] . '@' . $g['host'] . '), attivare «Usa server e credenziali della Connessione al gestionale».'
                             : ' — Utente o password errati, oppure utente non abilitato dall\'host del portale.');
        }
        if ($code === 1044 || $code === 1049) {
            return $m . ' — Il login riesce ma il database «' . ($row['dbname'] ?? '') . '» non è accessibile: verificarne il nome oppure concedere GRANT SELECT ON `' . ($row['dbname'] ?? '') . '`.* all\'utente ' . (preg_match("/user '([^']+)'/", $m, $u) ? $u[1] : ($row['username'] ?? '')) . '.';
        }
        return $m;
    }

    /**
     * v1.10.10 — Query predefinita adattata allo schema del DB SOC: la categoria del ticket è in
     * tt_ticket.id_tt_category → tt_category (tt_article porta solo i messaggi). Le colonne si verificano sulla
     * sorgente: se tt_ticket o tt_category mancano resta la query base, senza errori. Con una colonna padre in
     * tt_category la categoria diventa «Padre › Figlia».
     * @return array{0:string,1:string} [sql, nota]
     */
    public static function defaultSql(?SourceDb $src): array
    {
        if ($src === null) return [self::DEFAULT_SQL, 'query base'];
        $cols = static function (string $t) use ($src): array { try { return array_map('strtolower', $src->columnsOf($t)); } catch (Throwable $e) { return []; } };
        $art = $cols('tt_article'); $tk = $cols('tt_ticket'); $cat = $cols('tt_category');
        if (!in_array('id_tt_ticket', $art, true) || !in_array('id', $tk, true) || !in_array('id_tt_category', $tk, true)) return [self::DEFAULT_SQL, 'categoria non disponibile: tt_ticket.id_tt_category assente'];
        $name = null; foreach (['name', 'description', 'title', 'label', 'descrizione', 'nome', 'code'] as $c) if (in_array($c, $cat, true)) { $name = $c; break; }
        if (!in_array('id', $cat, true) || $name === null) return [self::DEFAULT_SQL, 'categoria non disponibile: tt_category senza colonna descrittiva'];
        $par = null; foreach (['id_parent', 'parent_id', 'id_tt_category_parent', 'id_tt_category'] as $c) if (in_array($c, $cat, true)) { $par = $c; break; }
        $q = fn(string $i) => $src->quoteIdent($i);
        $expr = "NULLIF(TRIM(c.{$q($name)}), '')";
        $join = "LEFT JOIN tt_ticket tk ON tk.id = a.id_tt_ticket\nLEFT JOIN tt_category c ON c.id = tk.id_tt_category";
        if ($par !== null) {
            $expr = "CASE WHEN cp.id IS NULL THEN $expr ELSE CONCAT(TRIM(cp.{$q($name)}), ' › ', TRIM(c.{$q($name)})) END";
            $join .= "\nLEFT JOIN tt_category cp ON cp.id = c.{$q($par)}";
        }
        $sql = str_replace("FROM tt_article a", "    , $expr AS `categoria`\nFROM tt_article a", self::DEFAULT_SQL);
        $sql = str_replace("LEFT JOIN tt_queue q ON q.id = a.id_tt_queue", "LEFT JOIN tt_queue q ON q.id = a.id_tt_queue\n$join", $sql);
        return [$sql, "categoria da tt_ticket.id_tt_category → tt_category.$name" . ($par ? " (gerarchia $par)" : '')];
    }

    /** Query di estrazione e parametri: ? n.1 = data minima, ? n.2 = prefisso ticket (LIKE). */
    public static function extractQuery(array $cfg, ?int $days = null, ?SourceDb $src = null): array
    {
        $sql = trim((string)($cfg['extract_sql'] ?? '')) ?: self::defaultSql($src)[0];
        $days = $days ?? (int)($cfg['window_days'] ?? 30);
        $from = $days > 0 ? date('Y-m-d 00:00:00', strtotime("-$days days")) : '1970-01-01 00:00:00';
        $prefix = trim((string)($cfg['ticket_prefix'] ?? ''));
        $all = [$from, ($prefix !== '' ? $prefix : '') . '%'];
        $n = substr_count(preg_replace("/'[^']*'/", '', $sql), '?');
        return [$sql, array_slice($all, 0, min(2, $n))];
    }

    /** Anteprima (prime righe normalizzate) dal DB SOC, senza scrivere. */
    public function previewDb(array $cfgRow, int $limit = 10): array
    {
        require_once __DIR__ . '/SourceDb.php';
        $src = SourceDb::connect(SourceDb::configFromRow($cfgRow));
        [$sql, $params] = self::extractQuery($cfgRow, 7, $src);
        $st = $src->query($sql, $params);
        $out = []; $map = null; $n = 0;
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $map = $map ?? self::mapHeaders(array_keys($r));
            $n++;
            if (count($out) < $limit) { $o = []; foreach ($map as $h => $f) $o[$f] = $r[$h]; $out[] = $this->normalize($o) ?? ['_scartata' => true] + $o; }
        }
        return ['rows' => $out, 'count' => $n, 'columns' => $map ? array_values($map) : [], 'note' => trim((string)($cfgRow['extract_sql'] ?? '')) !== '' ? 'query personalizzata' : self::defaultSql($src)[1]];
    }

    /** Scrittura degli eventi normalizzati + ricostruzione dei ticket. */
    private function ingest(int $batch, string $source, array $rows): array
    {
        $ins = 0; $upd = 0; $same = 0; $skip = 0; $seen = []; $min = null; $max = null;
        $sql = "INSERT INTO cm_soc_events (event_key, source, source_ref, batch_id, event_at, ticket_code, event_label, event_kind, status_before, status_after,
                    author_name, subject, queue_name, mailbox, owner_name, assignee_name, ticket_type, category, resolution, client_name, soc_contract, duration_min)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    source_ref = COALESCE(NULLIF(VALUES(source_ref), ''), source_ref),
                    event_label = COALESCE(event_label, NULLIF(VALUES(event_label), '')),
                    author_name = COALESCE(author_name, NULLIF(VALUES(author_name), '')),
                    subject = COALESCE(subject, NULLIF(VALUES(subject), '')),
                    queue_name = COALESCE(queue_name, NULLIF(VALUES(queue_name), '')),
                    mailbox = COALESCE(NULLIF(VALUES(mailbox), ''), mailbox),
                    owner_name = COALESCE(NULLIF(VALUES(owner_name), ''), owner_name),
                    assignee_name = COALESCE(NULLIF(VALUES(assignee_name), ''), assignee_name),
                    ticket_type = COALESCE(NULLIF(VALUES(ticket_type), ''), ticket_type),
                    category = COALESCE(NULLIF(VALUES(category), ''), category),
                    resolution = COALESCE(NULLIF(VALUES(resolution), ''), resolution),
                    client_name = COALESCE(NULLIF(VALUES(client_name), ''), client_name),
                    soc_contract = COALESCE(NULLIF(VALUES(soc_contract), ''), soc_contract),
                    duration_min = COALESCE(VALUES(duration_min), duration_min)";
        $st = $this->pdo->prepare($sql);
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $i => $raw) {
                $e = $this->normalize($raw);
                if ($e === null) { $skip++; continue; }
                $b = self::baseKey($e); $seen[$b] = ($seen[$b] ?? 0) + 1;
                $key = sha1($b . '|' . $seen[$b]);
                $nz = static fn($v) => $v === '' ? null : $v;
                $st->execute([$key, $source, $nz($e['source_ref']), $batch, $e['event_at'], $e['ticket_code'], $nz($e['event_label']), $e['event_kind'],
                    $nz($e['status_before']), $nz($e['status_after']), $nz($e['author_name']), $nz($e['subject']), $nz($e['queue_name']), $nz($e['mailbox']),
                    $nz($e['owner_name']), $nz($e['assignee_name']), $nz($e['ticket_type']), $nz($e['category']), $nz($e['resolution']),
                    $nz($e['client_name']), $nz($e['soc_contract']), $e['duration_min']]);
                $rc = $st->rowCount();
                $rc === 1 ? $ins++ : ($rc === 2 ? $upd++ : $same++);
                $min = $min === null || $e['event_at'] < $min ? $e['event_at'] : $min;
                $max = $max === null || $e['event_at'] > $max ? $e['event_at'] : $max;
                if ($i % 1000 === 999) { $this->pdo->commit(); $this->pdo->beginTransaction(); }
            }
            $this->pdo->commit();
        } catch (Throwable $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[SocIngest] ' . $ex->getMessage());
            return $this->closeBatch($batch, 'error', ['rows_read' => count($rows), 'rows_inserted' => $ins, 'rows_updated' => $upd,
                'message' => 'Errore in scrittura dopo ' . ($ins + $upd + $same) . ' righe: ' . $ex->getMessage()]);
        }
        try { $nt = $this->deferFinalize ? (int)$this->pdo->query("SELECT COUNT(DISTINCT ticket_code) FROM cm_soc_events")->fetchColumn() : $this->rebuild(); if (!$this->deferFinalize) $this->autoMap(); }
        catch (Throwable $ex) {
            error_log('[SocIngest::rebuild] ' . $ex->getMessage());
            return $this->closeBatch($batch, 'error', ['rows_read' => count($rows), 'rows_inserted' => $ins, 'rows_updated' => $upd, 'rows_unchanged' => $same,
                'message' => 'Eventi salvati, ricostruzione dei ticket non riuscita: ' . $ex->getMessage()]);
        }
        $msg = sprintf('%s: lette %d righe — nuove %d, aggiornate %d, invariate %d, scartate %d · %d ticket in archivio',
            $source === 'file' ? 'File' : 'DB SOC', count($rows), $ins, $upd, $same, $skip, $nt);
        return $this->closeBatch($batch, $skip > 0 && $ins + $upd + $same > 0 ? 'warn' : ($ins + $upd + $same > 0 || !$rows ? 'ok' : 'error'), [
            'rows_read' => count($rows), 'rows_inserted' => $ins, 'rows_updated' => $upd, 'rows_unchanged' => $same, 'rows_skipped' => $skip,
            'tickets' => $nt, 'date_from' => $min, 'date_to' => $max, 'message' => $msg]);
    }

    /* ── ricostruzione ticket ────────────────────────────────────────── */

    /** Rigenera cm_soc_tickets dagli eventi. @return int ticket */
    public function rebuild(): int
    {
        $cl = implode(',', array_map(fn($s) => $this->pdo->quote($s), $this->closed ?: ['CHIUSO']));
        $last = static fn(string $c) => "NULLIF(SUBSTRING(MAX(CASE WHEN COALESCE(e.$c,'') <> '' THEN CONCAT(DATE_FORMAT(e.event_at,'%Y%m%d%H%i%s'), LPAD(e.id,11,'0'), e.$c) END), 26), '')";
        $sql = "INSERT INTO cm_soc_tickets (ticket_code, title, opened_at, first_support_at, last_event_at, last_kind, closed_at, status_now, is_closed, resolution,
                    category, ticket_type, queue_name, mailbox, client_name, soc_contract, owner_name, assignee_name, n_events, n_support, n_customer, n_notes,
                    n_reopen, first_response_min, resolution_min, duration_min, rebuilt_at)
                SELECT g.*, TIMESTAMPDIFF(MINUTE, g.opened_at, g.first_support_at),
                       CASE WHEN g.is_closed = 1 THEN TIMESTAMPDIFF(MINUTE, g.opened_at, g.closed_at) END, g2.duration_min, NOW()
                  FROM (
                    SELECT e.ticket_code,
                           SUBSTRING(MIN(CONCAT(DATE_FORMAT(e.event_at,'%Y%m%d%H%i%s'), LPAD(e.id,11,'0'), COALESCE(e.subject,''))), 26) AS title,
                           MIN(e.event_at) AS opened_at,
                           MIN(CASE WHEN e.event_kind = 'supporto' THEN e.event_at END) AS first_support_at,
                           MAX(e.event_at) AS last_event_at,
                           SUBSTRING(MAX(CONCAT(DATE_FORMAT(e.event_at,'%Y%m%d%H%i%s'), LPAD(e.id,11,'0'), e.event_kind)), 26) AS last_kind,
                           NULL AS closed_at,
                           " . $last('status_after') . " AS status_now,
                           0 AS is_closed,
                           " . $last('resolution') . " AS resolution,
                           " . $last('category') . " AS category, " . $last('ticket_type') . " AS ticket_type,
                           " . $last('queue_name') . " AS queue_name, " . $last('mailbox') . " AS mailbox,
                           " . $last('client_name') . " AS client_name, " . $last('soc_contract') . " AS soc_contract,
                           " . $last('owner_name') . " AS owner_name, " . $last('assignee_name') . " AS assignee_name,
                           COUNT(*) AS n_events, SUM(e.event_kind = 'supporto') AS n_support, SUM(e.event_kind = 'cliente') AS n_customer,
                           SUM(e.event_kind = 'nota') AS n_notes,
                           COALESCE(SUM(COALESCE(e.status_before,'') IN ($cl) AND COALESCE(e.status_after,'') NOT IN ($cl) AND COALESCE(e.status_after,'') <> ''), 0) AS n_reopen
                      FROM cm_soc_events e GROUP BY e.ticket_code) g
                  JOIN (SELECT ticket_code, MAX(duration_min) duration_min FROM cm_soc_events GROUP BY ticket_code) g2 ON g2.ticket_code = g.ticket_code";
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("DELETE FROM cm_soc_tickets");
            $this->pdo->exec($sql);
            // chiusura: stato attuale chiuso → istante dell'ultimo passaggio a chiuso
            $this->pdo->exec("UPDATE cm_soc_tickets t
                                 SET t.is_closed = (t.status_now IN ($cl)),
                                     t.closed_at = CASE WHEN t.status_now IN ($cl) THEN COALESCE(
                                         (SELECT MAX(e.event_at) FROM cm_soc_events e WHERE e.ticket_code = t.ticket_code
                                             AND e.status_after IN ($cl) AND COALESCE(e.status_before,'') NOT IN ($cl)), t.last_event_at) END");
            // tempi di risposta: ogni messaggio del cliente → primo messaggio del supporto successivo
            $this->pdo->exec("UPDATE cm_soc_events SET reply_min = NULL WHERE reply_min IS NOT NULL");
            $this->pdo->exec("UPDATE cm_soc_events e JOIN (SELECT c.id, TIMESTAMPDIFF(MINUTE, c.event_at, MIN(s.event_at)) m FROM cm_soc_events c
                                   JOIN cm_soc_events s ON s.ticket_code = c.ticket_code AND s.event_kind = 'supporto' AND s.event_at > c.event_at
                                  WHERE c.event_kind = 'cliente' GROUP BY c.id) x ON x.id = e.id SET e.reply_min = x.m");
            $this->pdo->exec("UPDATE cm_soc_tickets t JOIN (SELECT ticket_code, ROUND(AVG(reply_min)) m,
                                     SUBSTRING(MIN(CONCAT(DATE_FORMAT(event_at,'%Y%m%d%H%i%s'), LPAD(id,11,'0'), event_kind)), 26) k
                                     FROM cm_soc_events GROUP BY ticket_code) x ON x.ticket_code = t.ticket_code
                                 SET t.avg_reply_min = x.m, t.opened_by = x.k");
            $this->pdo->exec("UPDATE cm_soc_tickets SET resolution_min = CASE WHEN is_closed = 1 THEN TIMESTAMPDIFF(MINUTE, opened_at, closed_at) END,
                                     title = TRIM(REGEXP_REPLACE(title, '^((re|r|fw|fwd|i|aw)\\\\s*:\\\\s*)+', ''))");
            // v1.10.09 — incaricato: dalla sorgente (export «Incaricato» o query personalizzata) oppure, se assente
            // (query predefinita sul DB SOC: tt_article non porta l'assegnazione), dedotto dai messaggi: il primo operatore
            // che risponde al cliente o scrive una nota interna sul ticket (presa in carico). Confronto con l'export
            // dello stesso periodo: 92% di corrispondenze (più messaggi 90%, ultimo messaggio 84%).
            $na = implode(',', array_map(fn($x) => $this->pdo->quote($x), self::NOT_ASSIGNED));
            $this->pdo->exec("UPDATE cm_soc_tickets SET assignee_name = NULL WHERE LOWER(TRIM(COALESCE(assignee_name, ''))) IN ($na)");
            $this->pdo->exec("UPDATE cm_soc_tickets SET assignee_source = 'sorgente' WHERE assignee_name IS NOT NULL");
            $this->pdo->exec("UPDATE cm_soc_tickets t JOIN (
                                   SELECT ticket_code, SUBSTRING(MIN(CONCAT(DATE_FORMAT(event_at, '%Y%m%d%H%i%s'), LPAD(id, 11, '0'), author_name)), 26) who
                                     FROM cm_soc_events
                                    WHERE event_kind IN ('supporto', 'nota') AND COALESCE(author_name, '') <> ''
                                      AND LOWER(TRIM(author_name)) NOT IN ($na)
                                    GROUP BY ticket_code) x ON x.ticket_code = t.ticket_code
                                 SET t.assignee_name = x.who, t.assignee_source = 'dedotto'
                               WHERE t.assignee_name IS NULL");
            $this->pdo->exec("UPDATE cm_soc_tickets t LEFT JOIN cm_soc_people p ON p.name = t.assignee_name LEFT JOIN cm_soc_people o ON o.name = t.owner_name
                                 LEFT JOIN cm_soc_clients c ON c.name = t.client_name
                                 SET t.assignee_employee_id = p.employee_id, t.owner_employee_id = o.employee_id, t.client_id = c.client_id");
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        return (int)$this->pdo->query("SELECT COUNT(*) FROM cm_soc_tickets")->fetchColumn();
    }

    /* ── abbinamenti ─────────────────────────────────────────────────── */

    public static function tokens(string $s): array
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i', 'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u', "'" => ' ', '’' => ' ']);
        $t = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_diff($t, ['spa', 's', 'p', 'a', 'srl', 'r', 'l', 'toscana', 'di', 'del', 'della', 'e', 'the'])));
    }

    /** Abbina persone e clienti nuovi (gli abbinamenti manuali non si toccano). */
    public function autoMap(): array
    {
        $o = ['people' => 0, 'clients' => 0];
        // persone
        // v1.10.09 — anche gli autori di risposte e note (operatori SOC): con la sola sorgente DB incaricato e
        // responsabile non arrivano dalla query predefinita e gli operatori restavano senza abbinamento
        $names = $this->pdo->query("SELECT DISTINCT n FROM (SELECT assignee_name n FROM cm_soc_tickets UNION SELECT owner_name FROM cm_soc_tickets
                                     UNION SELECT author_name FROM cm_soc_events WHERE event_kind IN ('supporto', 'nota')) x WHERE n IS NOT NULL AND n <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $emps = $this->pdo->query("SELECT id, CONCAT_WS(' ', first_name, last_name) n FROM employees")->fetchAll(PDO::FETCH_KEY_PAIR);
        $et = []; foreach ($emps as $id => $n) $et[(int)$id] = self::tokens((string)$n);
        $st = $this->pdo->prepare("INSERT INTO cm_soc_people (name, employee_id, is_manual) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE employee_id = IF(is_manual = 1, employee_id, VALUES(employee_id))");
        foreach ($names as $n) {
            if (in_array(mb_strtolower(trim($n)), self::NOT_ASSIGNED, true)) continue;
            $tk = self::tokens($n); $hit = [];
            if (count($tk) >= 2) foreach ($et as $id => $t) if (!array_diff($tk, $t)) $hit[] = $id;
            $st->execute([$n, count($hit) === 1 ? $hit[0] : null]);
            if (count($hit) === 1) $o['people']++;
        }
        // clienti
        $cn = $this->pdo->query("SELECT DISTINCT client_name FROM cm_soc_tickets WHERE client_name IS NOT NULL AND client_name <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $cls = $this->pdo->query("SELECT id, name FROM clients")->fetchAll(PDO::FETCH_KEY_PAIR);
        $ct = []; foreach ($cls as $id => $n) $ct[(int)$id] = self::tokens((string)$n);
        $st = $this->pdo->prepare("INSERT INTO cm_soc_clients (name, client_id, is_manual) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE client_id = IF(is_manual = 1, client_id, VALUES(client_id))");
        foreach ($cn as $n) {
            $tk = self::tokens($n); $best = null; $bestScore = 0; $tie = false;
            foreach ($ct as $id => $t) {
                if (!$t || array_diff($t, $tk)) continue;                         // ogni parola del cliente PM nel nome SOC
                $score = count($t) * 10 - (count($tk) - count($t));
                if ($score > $bestScore) { $best = $id; $bestScore = $score; $tie = false; } elseif ($score === $bestScore) $tie = true;
            }
            $st->execute([$n, $tie ? null : $best]);
            if ($best && !$tie) $o['clients']++;
        }
        $this->pdo->exec("UPDATE cm_soc_tickets t LEFT JOIN cm_soc_people p ON p.name = t.assignee_name LEFT JOIN cm_soc_people o ON o.name = t.owner_name
                             LEFT JOIN cm_soc_clients c ON c.name = t.client_name
                             SET t.assignee_employee_id = p.employee_id, t.owner_employee_id = o.employee_id, t.client_id = c.client_id");
        return $o;
    }

    /* ── lotti ───────────────────────────────────────────────────────── */

    private function openBatch(string $source, string $origin, string $trigger, ?int $userId): int
    {
        $this->pdo->prepare("INSERT INTO cm_soc_batches (source, origin, trigger_type, status, user_id, started_at) VALUES (?, ?, ?, 'running', ?, NOW())")
            ->execute([$source, mb_substr($origin, 0, 255), $trigger === 'pianificata' ? 'pianificata' : 'manuale', $userId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function closeBatch(int $id, string $status, array $d): array
    {
        $cols = ['rows_read', 'rows_inserted', 'rows_updated', 'rows_unchanged', 'rows_skipped', 'tickets', 'date_from', 'date_to'];
        $set = ['status = ?', 'message = ?', 'finished_at = NOW()']; $args = [$status, mb_substr((string)($d['message'] ?? ''), 0, 1000)];
        foreach ($cols as $c) if (array_key_exists($c, $d)) { $set[] = "$c = ?"; $args[] = $d[$c]; }
        $args[] = $id;
        $this->pdo->prepare("UPDATE cm_soc_batches SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
        return ['ok' => in_array($status, ['ok', 'warn'], true), 'status' => $status, 'batch_id' => $id, 'message' => (string)($d['message'] ?? '')] + $d;
    }
}
