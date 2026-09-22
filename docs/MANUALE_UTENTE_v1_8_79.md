# Manuale Utente — v1.8.79

## L'errore durante la sincronizzazione

Se durante *Sincronizza tutto* è comparso un messaggio con *Duplicate entry*, era
il controllo introdotto per impedire le ore doppie che segnalava un dato ripetuto
nel gestionale.

Non era un guasto: il controllo faceva il suo lavoro. Ora il portale scarta i
duplicati prima di importarli, quindi l'errore non si presenta più.

## Cosa fare

Dopo l'aggiornamento, lanciate **Sincronizza tutto** una volta. Tutti i tipi di
dato devono risultare `ok`.

## I valori economici possono variare leggermente

Su alcune attività la ripartizione dei costi e dei ricavi fra gli operatori era
calcolata su un numero di persone più alto del reale, a causa delle righe
ripetute. Ora è corretta: le quote per operatore possono risultare leggermente
più alte.
