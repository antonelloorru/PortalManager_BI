# PortalManager v1.9.72 — Sincronizzazione pianificata: errore 1045 (password non passata)

## Sintomo
La sincronizzazione giornaliera pianificata falliva con
`SQLSTATE[HY000] [1045] Access denied … (using password: NO)`, mentre la sincronizzazione
completa avviata a mano funzionava.

## Causa
La password del gestionale è salvata **cifrata** nella colonna `cm_source_db.password_enc`.
La pagina manuale la decifrava con `SourceDb::decrypt()`; lo script automatico
(`SyncRunner`, e prima ancora `cron_sync.php`) leggeva `$src['password']`, colonna che
non esiste: alla connessione arrivava una password vuota.

Dal confronto dei due percorsi sono emerse altre differenze, corrette insieme:
- la pianificata usava l'ultima connessione salvata anche se disattivata; la manuale solo
  quella attiva (`is_active = 1`);
- la pianificata creava `DatasetSync` senza `ProjectModel` e `PrefixResolver`: clienti e
  azienda esecutrice delle commesse non venivano agganciati;
- con la riconciliazione attiva, `DatasetSync` chiama `write_log()`, definita in
  `functions.php` che worker e CLI non caricano: errore fatale.

## Correzione
- Nuovo `SourceDb::configFromRow()`: **unico** punto che costruisce le credenziali dalla
  riga salvata (decifra `password_enc`). Lo usano sia la sincronizzazione manuale
  (`sync_commesse.php`) sia quella pianificata (`SyncRunner`): i due percorsi non possono
  più divergere.
- Se la password salvata non si decifra (APP_SECRET assente o diverso) viene segnalato un
  errore esplicito invece di tentare l'accesso senza password.
- `SyncRunner`: connessione attiva, `DatasetSync` con `ProjectModel` e `PrefixResolver`,
  `write_log()` di riserva quando `functions.php` non è caricato, esito registrato anche
  su `cm_source_db` come nella sincronizzazione manuale.

## QA
Gestionale simulato con MariaDB e autenticazione attiva (utente con password), password
salvata cifrata come dalla pagina di configurazione:
- versione precedente: `1045 … (using password: NO)` — difetto riprodotto;
- v1.9.72: sincronizzazione riuscita (dati letti dal gestionale), riconciliazione e log ok,
  dipendenze come la manuale, esito su `cm_sync_schedule_log` e `cm_source_db`;
- `cron_sync.php --force` (CLI): ok, exit 0;
- APP_SECRET diverso: errore esplicito;
- percorso manuale con la funzione condivisa: invariato.
`php -l` OK; migration RUN1/RUN2 err=0; schema_version → 1.9.72.
