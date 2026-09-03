<?php
/**
 * PortalManager — app/SourceDb.php  (v1.8.45)
 *
 * Connessione in SOLA LETTURA a un database gestionale esterno, da cui
 * sincronizzare le commesse senza passare da un file esportato a mano.
 *
 * Principi di sicurezza applicati:
 *
 *   1. La password non è mai salvata in chiaro: viene cifrata con AES-256-GCM
 *      usando APP_SECRET, che risiede in .env.php fuori dal repository. Chi
 *      ottenesse un dump del database non otterrebbe la credenziale.
 *
 *   2. La password non torna mai al browser. Il form la mostra come segnaposto
 *      e, se lasciata vuota in modifica, resta quella già registrata.
 *
 *   3. Le query eseguite sulla sorgente sono vincolate a SELECT: query()
 *      rifiuta qualsiasi istruzione che non cominci per SELECT o WITH e che
 *      contenga separatori di statement. È una difesa in profondità, non un
 *      sostituto: l'utenza sulla sorgente deve essere di sola lettura.
 *
 *   4. Nome di tabella e schema sono validati contro un'espressione regolare
 *      restrittiva e quotati secondo il dialetto, perché non possono essere
 *      passati come parametro di una prepared statement.
 *
 * Driver supportati, se la relativa estensione PDO è caricata:
 * MySQL/MariaDB, SQL Server (sqlsrv o dblib), PostgreSQL.
 */
final class SourceDb
{
    public const DRIVERS = [
        'mysql'  => ['label' => 'MySQL / MariaDB', 'ext' => 'mysql',  'port' => 3306],
        'sqlsrv' => ['label' => 'SQL Server',      'ext' => 'sqlsrv', 'port' => 1433],
        'dblib'  => ['label' => 'SQL Server (FreeTDS/dblib)', 'ext' => 'dblib', 'port' => 1433],
        'pgsql'  => ['label' => 'PostgreSQL',      'ext' => 'pgsql',  'port' => 5432],
    ];

    private PDO $conn;
    private string $driver;

    private function __construct(PDO $conn, string $driver)
    {
        $this->conn = $conn;
        $this->driver = $driver;
    }

    /** Driver realmente utilizzabili su questa installazione. */
    public static function availableDrivers(): array
    {
        $have = PDO::getAvailableDrivers();
        $out = [];
        foreach (self::DRIVERS as $k => $d) {
            if (in_array($d['ext'], $have, true)) $out[$k] = $d;
        }
        return $out;
    }

    // ── Cifratura delle credenziali ─────────────────────────────────────────

    private static function key(): string
    {
        $secret = (string)(class_exists('Env') ? Env::get('APP_SECRET', '') : '');
        if ($secret === '') throw new RuntimeException('APP_SECRET non disponibile: impossibile cifrare le credenziali.');
        return hash('sha256', 'sourcedb|' . $secret, true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new RuntimeException('Cifratura della password non riuscita.');
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') return '';
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) return '';
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $out = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $out === false ? '' : $out;
    }

    // ── Connessione ─────────────────────────────────────────────────────────

    /**
     * @param array{driver:string,host:string,port:int,dbname:string,username:string,password:string,timeout?:int} $cfg
     */
    public static function connect(array $cfg): self
    {
        $driver = (string)($cfg['driver'] ?? '');
        if (!isset(self::DRIVERS[$driver])) throw new InvalidArgumentException('Driver non supportato: ' . $driver);
        if (!isset(self::availableDrivers()[$driver])) {
            throw new RuntimeException("L'estensione PDO per " . self::DRIVERS[$driver]['label']
                . " non è caricata su questo server. Abilitarla in php.ini e riavviare Apache.");
        }

        $host = trim((string)($cfg['host'] ?? ''));
        $port = (int)($cfg['port'] ?? self::DRIVERS[$driver]['port']);
        $db   = trim((string)($cfg['dbname'] ?? ''));
        $to   = max(3, min(60, (int)($cfg['timeout'] ?? 10)));
        if ($host === '' || $db === '') throw new InvalidArgumentException('Host e nome database sono obbligatori.');

        switch ($driver) {
            case 'mysql':
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4"; break;
            case 'sqlsrv':
                $dsn = "sqlsrv:Server=$host,$port;Database=$db;TrustServerCertificate=1;LoginTimeout=$to"; break;
            case 'dblib':
                $dsn = "dblib:host=$host:$port;dbname=$db;charset=UTF-8"; break;
            case 'pgsql':
                $dsn = "pgsql:host=$host;port=$port;dbname=$db;connect_timeout=$to"; break;
            default:
                throw new InvalidArgumentException('Driver non gestito.');
        }

        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => $to,
        ];
        if ($driver === 'mysql') {
            $opt[PDO::ATTR_EMULATE_PREPARES]   = false;
            $opt[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;   // dataset grandi senza saturare la memoria
        }

        $conn = new PDO($dsn, (string)($cfg['username'] ?? ''), (string)($cfg['password'] ?? ''), $opt);

        // Sessione di sola lettura dove il dialetto lo consente: se l'utenza
        // fosse per errore in scrittura, resta comunque impossibile modificare.
        try {
            if ($driver === 'mysql')      $conn->exec('SET SESSION TRANSACTION READ ONLY');
            elseif ($driver === 'pgsql')  $conn->exec('SET default_transaction_read_only = on');
        } catch (Throwable $e) { /* privilegio non concesso: si prosegue */ }

        return new self($conn, $driver);
    }

    public function driver(): string { return $this->driver; }

    /** Versione del server, a scopo diagnostico. */
    public function serverVersion(): string
    {
        try { return (string)$this->conn->getAttribute(PDO::ATTR_SERVER_VERSION); }
        catch (Throwable $e) { return 'n/d'; }
    }

    // ── Interrogazione ──────────────────────────────────────────────────────

    /** Quota un identificatore secondo il dialetto, dopo averlo validato. */
    public function quoteIdent(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,63}$/', $name)) {
            throw new InvalidArgumentException("Nome non valido: '$name'. Ammessi lettere, cifre e underscore.");
        }
        return match ($this->driver) {
            'mysql'           => "`$name`",
            'sqlsrv', 'dblib' => "[$name]",
            default           => "\"$name\"",
        };
    }

    /** Nome completo di tabella, con schema opzionale. */
    public function qualify(string $table, string $schema = ''): string
    {
        $t = $this->quoteIdent($table);
        return $schema !== '' ? $this->quoteIdent($schema) . '.' . $t : $t;
    }

    /**
     * Esegue una SELECT. Rifiuta qualunque altra istruzione e i separatori di
     * statement, per impedire che una configurazione errata o manomessa possa
     * scrivere sulla sorgente.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $probe = ltrim(preg_replace('#^\s*(/\*.*?\*/|--[^\n]*\n)+#s', '', $sql));
        if (!preg_match('/^(SELECT|WITH)\b/i', $probe)) {
            throw new RuntimeException('Sulla sorgente sono ammesse solo istruzioni SELECT.');
        }
        if (preg_match('/;\s*\S/', $sql)) {
            throw new RuntimeException('Query multiple non ammesse.');
        }
        $st = $this->conn->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** Clausola di limitazione righe secondo il dialetto. */
    public function limitClause(int $n): array
    {
        $n = max(1, min(100000, $n));
        return match ($this->driver) {
            'sqlsrv', 'dblib' => ['prefix' => "TOP $n ", 'suffix' => ''],
            default           => ['prefix' => '', 'suffix' => " LIMIT $n"],
        };
    }

    /** Colonne della tabella sorgente, per la diagnostica di mappatura. */
    public function columnsOf(string $table, string $schema = ''): array
    {
        $lim = $this->limitClause(1);
        $sql = "SELECT {$lim['prefix']}* FROM " . $this->qualify($table, $schema) . $lim['suffix'];
        $st  = $this->query($sql);
        $out = [];
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $m = $st->getColumnMeta($i);
            if (is_array($m) && isset($m['name'])) $out[] = (string)$m['name'];
        }
        $st->closeCursor();
        return $out;
    }

    /**
     * v1.8.49 — Inventario COMPLETO della sorgente.
     *
     * L'importazione non deve limitarsi alle tabelle dei dataset configurati:
     * prima di sincronizzare occorre sapere che cosa la sorgente contiene
     * davvero. Una tabella nuova, una vista di export aggiunta dal fornitore o
     * una rinominata passerebbero altrimenti inosservate, e il primo segnale
     * sarebbe una sincronizzazione che smette di funzionare.
     *
     * Legge da information_schema, quindi non tocca i dati: restituisce nome,
     * tipo (tabella o vista), numero di colonne e righe stimate per OGNI
     * oggetto dello schema.
     *
     * @return array<int,array{name:string,type:string,columns:int,rows:int}>
     */
    public function inventory(string $schema = ''): array
    {
        $db = $schema !== '' ? $schema : $this->currentSchema();
        if ($db === '') return [];

        switch ($this->driver) {
            case 'mysql':
                $sql = "SELECT t.TABLE_NAME AS name,
                               CASE WHEN t.TABLE_TYPE = 'VIEW' THEN 'vista' ELSE 'tabella' END AS type,
                               COALESCE(t.TABLE_ROWS, 0) AS rows_est,
                               (SELECT COUNT(*) FROM information_schema.COLUMNS c
                                 WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME) AS cols
                          FROM information_schema.TABLES t
                         WHERE t.TABLE_SCHEMA = ?
                         ORDER BY t.TABLE_NAME";
                break;
            case 'pgsql':
                $sql = "SELECT c.relname AS name,
                               CASE WHEN c.relkind = 'v' THEN 'vista' ELSE 'tabella' END AS type,
                               GREATEST(c.reltuples::bigint, 0) AS rows_est,
                               (SELECT COUNT(*) FROM information_schema.columns k
                                 WHERE k.table_schema = n.nspname AND k.table_name = c.relname) AS cols
                          FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                         WHERE n.nspname = ? AND c.relkind IN ('r','v','m')
                         ORDER BY c.relname";
                break;
            default: // SQL Server
                $sql = "SELECT t.TABLE_NAME AS name,
                               CASE WHEN t.TABLE_TYPE = 'VIEW' THEN 'vista' ELSE 'tabella' END AS type,
                               0 AS rows_est,
                               (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS c
                                 WHERE c.TABLE_NAME = t.TABLE_NAME) AS cols
                          FROM INFORMATION_SCHEMA.TABLES t
                         WHERE t.TABLE_CATALOG = ?
                         ORDER BY t.TABLE_NAME";
        }

        $st = $this->query($sql, [$db]);
        $out = [];
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[] = [
                'name'    => (string)$r['name'],
                'type'    => (string)$r['type'],
                'columns' => (int)$r['cols'],
                'rows'    => (int)$r['rows_est'],
            ];
        }
        $st->closeCursor();
        return $out;
    }

    /** Schema corrente della connessione, usato come default dall'inventario. */
    public function currentSchema(): string
    {
        try {
            $sql = match ($this->driver) {
                'mysql'           => 'SELECT DATABASE()',
                'pgsql'           => 'SELECT current_schema()',
                default           => 'SELECT DB_NAME()',
            };
            $st = $this->query($sql);
            $v = (string)$st->fetchColumn();
            $st->closeCursor();
            return $v;
        } catch (Throwable $e) { return ''; }
    }
}
