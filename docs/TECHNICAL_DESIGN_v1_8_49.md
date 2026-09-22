# Technical Design — PortalManager v1.8.49

## 1. Leggere i dati non è conoscere lo schema

La sincronizzazione sapeva leggere nove tabelle. Non sapeva nulla delle altre
cento, e questo è un problema diverso da quello che risolveva.

Un'integrazione verso un sistema di terzi convive con uno schema che cambia senza
preavviso. Le modalità sono tre: una tabella viene rinominata, una viene rimossa,
una viene aggiunta. Le prime due rompono la sincronizzazione; la terza no, ma
nasconde un'opportunità.

Con la sola lettura dei dataset, tutte e tre si manifestano nello stesso modo:
un errore in fase di import, oppure niente. L'inventario le rende visibili prima.

## 2. `information_schema` invece di `SHOW TABLES`

```sql
SELECT t.TABLE_NAME, t.TABLE_TYPE, t.TABLE_ROWS,
       (SELECT COUNT(*) FROM information_schema.COLUMNS c
         WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME)
  FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = ?
```

Tre ragioni per questa forma.

È una `SELECT`, quindi supera il vincolo di sola lettura di `SourceDb::query()`
senza eccezioni al controllo. `SHOW TABLES` sarebbe stato rifiutato.

Distingue tabelle da viste, che è l'informazione che ha rivelato le nove viste di
export del nuovo dump.

Usa `TABLE_ROWS`, una **stima** del motore, non `COUNT(*)`. Su un centinaio di
tabelle un conteggio esatto significherebbe cento scansioni complete: per capire
l'ordine di grandezza la stima basta, e la pagina lo dichiara.

Le varianti per PostgreSQL (`pg_class.reltuples`) e SQL Server sono previste
nello stesso metodo, coerentemente con l'astrazione di dialetto già presente.

## 3. Le tabelle richieste si estraggono dalle query

```php
preg_match_all('/\b(?:FROM|JOIN)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $sql, $m);
```

L'alternativa sarebbe stata elencarle in un campo del registro dei dataset. È
stata scartata: quell'elenco diventerebbe una seconda verità, e alla prima
modifica di una query smetterebbe di corrispondere.

L'ironia sarebbe stata doppia, perché lo scopo di questa analisi è proprio
individuare le divergenze fra ciò che serve e ciò che c'è. Un controllo basato su
una dichiarazione che può divergere non controllerebbe granché.

L'espressione regolare è sufficiente per query che il progetto controlla:
`FROM` e `JOIN` seguiti da un identificatore semplice, senza sottoquery nella
clausola FROM. Verificata sui tre dataset, restituisce esattamente le nove
tabelle attese.

## 4. I tre esiti della copertura

| Esito | Significato | Azione |
|---|---|---|
| `used` | richiesta e presente | nessuna |
| `missing` | richiesta ma assente | **la sincronizzazione fallirebbe** |
| `unused` | presente ma non richiesta | candidata per nuovi dataset |

`missing` è il motivo per cui l'analisi esiste, e la pagina lo tratta di
conseguenza: bordo rosso e messaggio che invita a verificare se la tabella è
stata rinominata o spostata di schema.

`unused` non è rumore da nascondere. Le nove viste del nuovo dump stanno lì, e
`v_contract_export_list` espone tredici colonne già nel formato dell'export
commesse: è esattamente il tipo di cosa che questa analisi deve far notare.

## 5. Pagine routabili senza voce di menu

`dgb_activities` era in `Router::PAGES`, il file esisteva, la pagina funzionava —
ma nessuna voce di menu vi portava. Un difetto che non produce errori: produce una
funzionalità che nessuno usa perché nessuno sa che c'è.

Il controllo è stato generalizzato confrontando l'insieme delle pagine routabili
con quello delle pagine referenziate dal menu. Delle 92 routabili, 16 restano
senza voce ed è corretto così:

- **autenticazione**: `login`, `logout`, `2fa_verify`, `unauthorized`;
- **pagine di dettaglio** raggiunte da un elenco: `project_dashboard`,
  `employee_profile`, `employee_cv`, `employee_compensation`, `position_history`;
- **sotto-pagine di flusso**: `mass_upload_jobs`, `mass_upload_partials`,
  `mass_upload_review`, `import_economics_xlsx`, `finance_compare`,
  `hr_economic_years`, `manage_users_2fa`.

Il controllo inverso — voci di menu che puntano a pagine non routabili — dà dieci
risultati, tutti in `Router::RESTRICTED`: sono le pagine di sistema che per
progetto non vengono anonimizzate.

## 6. La regressione, e perché è successa

La v1.8.48 modificava `MenuManager.php` e `Router.php` partendo dalle copie di
`prod_files`, che erano anteriori alla v1.8.46. Il risultato sarebbe stato la
scomparsa della voce "Sincronizzazione gestionale" e della sua rotta.

La causa è nota e prevedibile: quando una release tocca un file già modificato da
una release precedente non ancora installata, deve partire dall'ultima versione
prodotta, non dall'ultima installata.

La verifica di coerenza fra menu e rotte è ora una voce della checklist, ed è
automatizzabile — è la stessa che ha trovato `dgb_activities`.
