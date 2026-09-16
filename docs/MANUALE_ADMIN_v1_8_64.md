# Manuale Amministratore — v1.8.64

## Perché la sincronizzazione falliva su tutto

L'esito che avete inviato mostrava undici dataset su undici in errore. Sono due
difetti sommati.

**Il primo**: la tabella delle Divisioni non aveva una colonna tecnica
(`import_batch_id`) che la sincronizzazione scrive su ogni destinazione.
Mancava anche su altre quattro tabelle introdotte di recente. Da sola, questa
avrebbe fatto fallire un dataset.

**Il secondo, la vera causa**: quando un dataset falliva, la transazione del
database restava **aperta**. Tutti i dataset successivi la trovavano attiva e
fallivano a loro volta con *«There is already an active transaction»*.

C'è poi un terzo elemento: le Divisioni sono **in testa** all'ordine di
sincronizzazione. Se fossero state in fondo, dieci dataset sarebbero passati.
Un difetto banale è diventato totale per la combinazione delle tre cose.

## Cosa ho corretto

La sincronizzazione ora **chiude sempre la transazione** in caso di errore, a due
livelli: dentro il motore di scrittura e nel ciclo che scorre i dataset.

Il risultato è quello che la versione 1.8.57 prometteva e che questo difetto
impediva: **un dataset che fallisce non ferma gli altri**. Nel collaudo, su cinque
dataset di cui due difettosi, i tre sani sono andati a buon fine.

La colonna è stata aggiunta a tutte e nove le destinazioni, comprese le quattro
che già la avevano: un elenco delle sole mancanti andrebbe aggiornato a mano ogni
volta che nasce una tabella, ed è esattamente così che il difetto si è prodotto.

## Un controllo prima di sincronizzare

```sql
SELECT * FROM v_cm_sync_schema_check;
```

**Deve restituire zero righe.** Elenca le tabelle di destinazione a cui manca la
colonna tecnica: se ne compare una, la sincronizzazione fallirà su quel dataset.

Vale la pena eseguirlo dopo ogni aggiornamento che introduca nuovi dataset.

## Cosa aspettarsi ora

**Sincronizzazione gestionale → Sincronizza tutto**: undici dataset con esito
`ok`.

Se qualcosa fallisce ancora, il messaggio compare accanto al dataset interessato
e **gli altri vengono comunque aggiornati**.
