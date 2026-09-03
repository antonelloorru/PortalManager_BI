# Manuale Utente — v1.9.12

## Moduli mancanti: risolto

Alcuni moduli di intervento presenti nel gestionale non arrivavano nel portale.

La causa era il filtro sullo stato del modulo, che distingueva fra maiuscole e
minuscole: gli stati scritti in minuscolo dal gestionale venivano ignorati.

## Dopo l'aggiornamento

**Serve una sincronizzazione completa** perché i moduli mancanti entrino: senza,
il conteggio resta quello di prima.

Gestione Commesse → Sincronizzazione Gestionale → **Sincronizza tutto**.

## Una novità

Il portale conserva ora lo **stato** di ciascun modulo. Serve a verificare che
nulla venga più perso per strada.
