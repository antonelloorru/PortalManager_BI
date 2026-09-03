# Technical Design — PortalManager v1.8.45

## 1. Architettura

```
  cm_source_db (parametri, password cifrata)
        │
        ▼
  SourceDb ──── PDO in sola lettura ────► gestionale esterno (tabella contract)
        │                                          │
        │  columnsOf() / query()                   │
        ▼                                          ▼
  CommesseSync ── COLUMN_MAP ──► UPSERT su cm_projects
        │                              │
        │                              └─► assorbimento segnaposto DGB
        ▼
  cm_import_batches (tracciabilità) + event log
```

Due responsabilità separate: `SourceDb` sa collegarsi e proteggere la sorgente,
`CommesseSync` sa che cosa significano le sue colonne. La separazione permette di
riusare `SourceDb` per altre sorgenti future senza toccare la logica commesse.

## 2. Perché la password non può stare in chiaro

I parametri di connessione devono persistere fra le sessioni, quindi finiscono in
tabella. Ma la tabella finisce nei backup, e i backup circolano.

La cifratura è AES-256-GCM con chiave derivata da `APP_SECRET`:

```php
return hash('sha256', 'sourcedb|' . $secret, true);
```

`APP_SECRET` vive in `.env.php`, fuori dal database e fuori dal repository. Chi
ottenesse il solo dump non avrebbe la chiave. La derivazione con prefisso di
dominio evita che la stessa chiave serva a scopi diversi.

GCM è preferito a CBC perché è autenticato: un cifrato manomesso non produce un
testo spurio ma un fallimento esplicito, verificato in collaudo.

L'IV è casuale a ogni cifratura, quindi due salvataggi della stessa password
producono valori diversi: da un confronto fra record non si deduce che due
configurazioni condividono la credenziale.

## 3. Sola lettura, in tre strati

**Strato uno, la sessione.** Dove il dialetto lo consente si apre in read-only:

```php
if ($driver === 'mysql')     $conn->exec('SET SESSION TRANSACTION READ ONLY');
elseif ($driver === 'pgsql') $conn->exec('SET default_transaction_read_only = on');
```

In `try/catch`: se il privilegio non è concesso si prosegue, perché è un
irrobustimento e non un requisito.

**Strato due, le istruzioni.** `query()` esamina il testo dopo aver rimosso i
commenti iniziali e accetta solo `SELECT` o `WITH`, rifiutando anche i separatori
di statement seguiti da altro testo. Impedisce che una configurazione manomessa o
un errore trasformino la lettura in scrittura.

**Strato tre, gli identificatori.** Tabella e schema non possono essere legati
come parametri: sono validati con `/^[A-Za-z_][A-Za-z0-9_$]{0,63}$/` e quotati
secondo il dialetto — backtick per MySQL, parentesi quadre per SQL Server,
virgolette doppie altrove. Un valore come `contract; DROP TABLE x` viene respinto
prima di arrivare alla query.

Nessuno dei tre sostituisce la misura vera, che è un'utenza di sola lettura sul
gestionale: sono difesa in profondità.

## 4. Astrazione dei dialetti

Le differenze gestite sono tre: il DSN, il quoting degli identificatori e la
limitazione delle righe. Quest'ultima è la meno ovvia, perché SQL Server la
esprime come prefisso e gli altri come suffisso:

```php
return match ($this->driver) {
    'sqlsrv', 'dblib' => ['prefix' => "TOP $n ", 'suffix' => ''],
    default           => ['prefix' => '', 'suffix' => " LIMIT $n"],
};
```

`availableDrivers()` filtra sull'estensione PDO realmente caricata: la pagina non
propone opzioni che fallirebbero al primo tentativo, e se l'elenco è vuoto spiega
che cosa abilitare.

## 5. Tolleranza alle differenze di sorgente

`buildSelect()` interseca le colonne mappate con quelle realmente esposte:

```php
foreach (array_keys(self::COLUMN_MAP) as $c) {
    if (isset($availLower[strtolower($c)])) $use[] = $c;
}
```

Chiedere `SELECT *` avrebbe portato dentro colonne inutili; chiedere l'elenco
fisso avrebbe fatto fallire l'intera sincronizzazione per una colonna assente.
L'intersezione mantiene entrambe le proprietà. Solo `code` e `name` sono
indispensabili, e la loro mancanza produce un messaggio che indica dove
intervenire.

Il confronto è case-insensitive perché i dialetti differiscono nel preservare il
caso dei nomi di colonna.

## 6. Riconciliazione con i segnaposto DGB

La colonna `id` della sorgente è l'identificativo del contratto, quello che
`DgbSync` usa per costruire i segnaposto `DGB-<id>`. Scriverlo in
`dgb_contract_id` chiude il cerchio: dopo l'upsert si cercano i segnaposto con lo
stesso identificativo, si spostano i loro riferimenti sulla commessa reale e li si
elimina.

È la stessa logica introdotta in v1.8.41 per l'import XLSX. Averla anche qui
evita che la sincronizzazione diretta ricrei il problema che quella release aveva
risolto.

## 7. Anteprima come simulazione

`run()` accetta `$dryRun`: legge, valuta per ogni riga se produrrebbe un
inserimento o un aggiornamento, raccoglie le prime 25 per la tabella di anteprima
e non apre alcuna transazione. È la stessa funzione che poi esegue davvero, quindi
l'anteprima non può divergere dall'esecuzione — cosa che accadrebbe se fosse una
funzione separata scritta per l'occasione.

## 8. Memoria e transazioni

Per MySQL la query non è bufferizzata (`MYSQL_ATTR_USE_BUFFERED_QUERY = false`):
le righe arrivano una alla volta e un gestionale con decine di migliaia di
contratti non satura la memoria di PHP.

La transazione sul portale viene chiusa e riaperta ogni 500 righe: limita la
dimensione del log di undo mantenendo l'atomicità a blocchi.

## 9. Tracciabilità

Ogni esecuzione registra un batch in `cm_import_batches` con `kind` a
`commesse_db` e il nome della sorgente nella forma `driver@host/db.tabella`,
aggiorna `last_sync_at` e `last_sync_note` sulla configurazione, e scrive
nell'event log righe lette, nuove, aggiornate, saltate e segnaposto assorbiti.
`import_batch_id` sulle commesse consente di risalire da ogni record alla
sincronizzazione che lo ha scritto.
