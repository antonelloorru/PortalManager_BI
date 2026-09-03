# Manuale Utente — v1.8.65

## Anteprima e sincronizzazione ora si distinguono

In **Sincronizzazione gestionale** ci sono due pulsanti:

- **Anteprima completa** — controlla che tutto funzioni senza modificare nulla
- **Sincronizza tutto** — aggiorna davvero i dati

Poteva capitare che premendo *Sincronizza tutto* venisse eseguita l'anteprima, e
comparisse il messaggio *«nessuna scrittura effettuata»*. Era un difetto tecnico
nel modo in cui i due pulsanti erano costruiti, ed è stato risolto.

## Come capire cosa è stato eseguito

Al termine, il titolo del riquadro mostra un'etichetta colorata:

- **ANTEPRIMA** in arancione — nessun dato è stato scritto
- **SCRITTURA** in verde — i dati sono stati aggiornati

Se vedete ANTEPRIMA e volevate aggiornare, premete *Sincronizza tutto*.

## A cosa serve l'anteprima

A verificare che il collegamento funzioni e che tutti i tipi di dato rispondano,
senza toccare nulla. È veloce perché legge solo 200 righe per tipo.

Per questo i numeri che mostra sono parziali: dicono che cosa succederebbe su
quelle righe, non quante ne verranno aggiornate in totale.
