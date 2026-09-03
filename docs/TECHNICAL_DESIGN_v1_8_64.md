# Technical Design — PortalManager v1.8.64

## 1. Un difetto piccolo con effetto totale

La colonna mancante su `cm_divisions` era un difetto locale: avrebbe dovuto far
fallire un dataset su undici.

Ne ha fatti fallire undici, per due ragioni che si sono sommate.

La prima è la transazione non rilasciata: senza `rollBack()`, l'errore del primo
dataset lascia la connessione in stato transazionale e ogni `beginTransaction()`
successivo solleva *There is already an active transaction*.

La seconda è l'ordine: la v1.8.63 ha messo `divisioni` in testa alla sequenza,
perché è una dimensione pura senza dipendenze. Una scelta corretta che ha
trasformato un difetto parziale in totale — se `divisioni` fosse stato ultimo,
dieci dataset sarebbero passati.

È una combinazione istruttiva: nessuna delle due scelte era sbagliata in sé, ma
insieme hanno amplificato un difetto banale fino a bloccare tutto.

## 2. Il rollback va dove la transazione viene aperta

```php
if (!$dryRun) $this->pdo->beginTransaction();
try {
    …
    if (!$dryRun && $this->pdo->inTransaction()) $this->pdo->commit();
} catch (Throwable $e) {
    if ($this->pdo->inTransaction()) {
        try { $this->pdo->rollBack(); } catch (Throwable $ignored) {}
    }
    throw $e;
}
```

Tre dettagli non ovvi.

`inTransaction()` prima del rollback: il metodo fa commit intermedi ogni 500
righe e riapre la transazione, quindi al momento dell'errore potrebbe non
essercene una attiva. Un `rollBack()` senza transazione solleva a sua volta
un'eccezione, mascherando quella originale.

Il `try/catch` **attorno al rollback**: se anche il rollback fallisce — connessione
caduta, per esempio — l'eccezione che conta è quella di partenza, non quella del
tentativo di rimedio.

`throw $e` dopo il rollback: la pulizia non deve nascondere l'errore. Il chiamante
deve sapere che quel dataset è fallito, altrimenti lo riporta come riuscito con
zero righe.

## 3. Due livelli di difesa

Il rollback in `DatasetSync` copre la scrittura. Ma nel ciclo di
`sync_commesse.php` l'errore può arrivare anche da `openBatch()` — che scrive su
`cm_import_batches` — o da `readSource()`, entrambi fuori da quel `try`.

```php
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
    }
    …
}
```

La ridondanza è voluta. Il ciclo non sa quale componente ha aperto una
transazione né se l'ha chiusa: verifica e chiude. È l'unico punto che vede tutti
i dataset e può garantire che nessuno inquini il successivo.

## 4. Allineare tutte, non solo le mancanti

```sql
ALTER TABLE cm_divisions       ADD COLUMN IF NOT EXISTS import_batch_id int(11) DEFAULT NULL;
ALTER TABLE cm_contract_rates  ADD COLUMN IF NOT EXISTS import_batch_id int(11) DEFAULT NULL;
…
```

Nove `ALTER`, comprese le quattro tabelle che già hanno la colonna. Ridondante
oggi, e deliberato: un elenco delle sole mancanti richiede di sapere quali sono, e
quel sapere invecchia alla prossima tabella.

`IF NOT EXISTS` rende le quattro ridondanti innocue.

## 5. Il controllo che rende il difetto non ripetibile

`v_cm_sync_schema_check` interroga `information_schema` per le destinazioni dei
dataset prive della colonna. Deve restituire zero righe.

Un controllo del genere vale più della correzione che lo accompagna: la
correzione risolve oggi, il controllo segnala domani. Chi introdurrà la
dodicesima tabella lo vedrà prima che la sincronizzazione fallisca — a patto di
guardarlo, ed è per questo che è documentato nella verifica post-deploy.

L'elenco delle tabelle nella vista è cablato. È una duplicazione rispetto a
`SyncDatasets`, e va aggiornata quando nasce un dataset: meno elegante di una
derivazione automatica, ma verificabile a colpo d'occhio.

## 6. Perché il collaudo riproduce il difetto prima di correggerlo

Il test esegue **entrambi** i comportamenti: prima quello senza rollback, per
verificare di aver capito il meccanismo, poi quello corretto.

Riprodurre il difetto è la parte che dà valore al test. Un test che verifica solo
la correzione dimostra che il codice nuovo funziona, non che risolve il problema
osservato: se la diagnosi fosse stata sbagliata, passerebbe comunque.
