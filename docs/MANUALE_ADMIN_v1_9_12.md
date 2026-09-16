# Manuale Amministratore — v1.9.12

## Il difetto confermato

La sincronizzazione filtrava con:

```sql
AND a.status IN ('APPROVED','CLOSED','COMPLETED')
```

Il gestionale usa gli stessi stati in **due grafie**: `completed` 70.833 volte e
`COMPLETED` in maiuscolo, `closed` 5.675 e `CLOSED` 4.514.

Le righe minuscole non corrispondevano e venivano **scartate in silenzio**.

I moduli di Bressi erano tutti in stato **`assigned`**, che non compariva
nell'elenco in nessuna grafia.

## Un dettaglio che dice molto

**`APPROVED` non esiste nel gestionale**: zero occorrenze su tutto il dump.

Uno dei tre stati del filtro originale era un valore inventato. Non ha causato il
difetto, ma dice come l'elenco era stato costruito: per plausibilità, non
guardando i dati.

## Cosa ho fatto

Il confronto è ora `UPPER(TRIM(...))`, indifferente alla grafia e agli spazi, e
l'elenco è quello degli undici stati che mi avete indicato.

**Il collaudo copre 12 casi**: con il vecchio filtro ne passavano 4, con il nuovo
8 — e i 4 scartati sono quelli che devono esserlo.

## Due cose da fare dopo l'aggiornamento

**1. Risincronizzare.** Senza, non succede nulla di visibile: i moduli mai
importati entrano solo alla prossima sincronizzazione.

**2. Guardare la ripartizione per stato:**

```sql
SELECT stato, moduli, quota_pct, ore FROM v_cm_ir_stati ORDER BY moduli DESC;
```

**Attenzione a `OPEN`**: vale 327.892 righe sull'intero gestionale. Se risultasse
pesante, il portale starebbe contabilizzando lavoro non ancora svolto. È la
ragione per cui questa vista esiste.

## Perché non ve ne eravate accorti prima

**Il portale non conservava lo stato del modulo.** Una riga scartata non lasciava
traccia: non si poteva sapere né quante ne mancassero né perché.

L'unico modo di accorgersene era confrontare un export del gestionale con il
portale — che è esattamente quello che avete fatto.

Ora lo stato è in tabella, e `v_cm_ir_copertura_tecnico` permette di ripetere quel
confronto senza uscire dal portale.

## Se voleste escludere REJECTED

L'avete incluso, quindi il lavoro respinto viene contabilizzato. Se non fosse
voluto, il parametro `sync_stati_moduli` documenta l'elenco — ma **il filtro
operativo è nella query di `app/SyncDatasets.php`**, e va cambiato lì.

La colonna `ammesso` di `v_cm_ir_stati` segnala se i due divergono.
