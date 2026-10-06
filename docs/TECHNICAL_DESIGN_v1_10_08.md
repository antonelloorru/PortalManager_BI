# TECHNICAL DESIGN — v1.10.08

## Connessioni a database esterni: un solo percorso
| Uso | Configurazione | Risoluzione parametri | Connessione |
|---|---|---|---|
| Gestionale (manuale, `import_commesse_db.php`, `sync_commesse.php`) | `cm_source_db` attiva | `SourceDb::configFromRow()` | `SourceDb::connect()` |
| Gestionale (giornaliera, `SyncRunner`) | `cm_source_db` attiva | `SourceDb::configFromRow()` | `SourceDb::connect()` |
| DB SOC (pipeline, test, anteprima) | `cm_soc_source_db` attiva | `SocIngest::resolveSource()` → `SourceDb::configFromRow()` | `SourceDb::connect()` |

`SourceDb::connect()`: DSN `mysql:host;port;dbname;charset=utf8mb4`, ERRMODE_EXCEPTION, timeout, prepares nativi, unbuffered, `SET SESSION TRANSACTION READ ONLY`, wait_timeout esteso.

## SocIngest::resolveSource(PDO, array $row): array
- `use_gestionale = 0` → parametri propri, `cred_origin = propria`.
- `use_gestionale = 1` → `driver, host, port, username, password_enc` da `cm_source_db` attiva; `dbname, source_schema, timeout, window_days, ticket_prefix, extract_sql` del DB SOC; `cred_origin = gestionale`. Gestionale assente o inattivo → eccezione esplicita.
- Nessuna copia della password: il valore cifrato resta in `cm_source_db`, letto a ogni esecuzione (un cambio password del gestionale vale subito anche per il SOC).

## SocIngest::connError(PDO, Throwable, array): string
| Codice | Significato | Indicazione |
|---|---|---|
| 1045 / 1698 | login rifiutato | origine gestionale → verificare la Connessione al gestionale; origine propria → attivare l'eredità |
| 1044 / 1049 | login ok, database non accessibile / inesistente | nome database o `GRANT SELECT ON db.* TO utente` |

## Schema ER (delta)
`cm_soc_source_db.use_gestionale` TINYINT(1) DEFAULT 0 → riferimento logico a `cm_source_db` (riga attiva più recente).
