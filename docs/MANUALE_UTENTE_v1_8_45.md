# Manuale Utente — v1.8.45

## Le commesse si aggiornano dal gestionale

Il portale può ora leggere le commesse direttamente dal gestionale, senza che
qualcuno debba esportare un file e caricarlo a mano.

In pratica, per chi consulta l'elenco, questo significa che codici, clienti,
valori, margini e stati sono quelli del gestionale, aggiornati all'ultima
sincronizzazione.

## Quando è stato aggiornato

In **Gestione Commesse → Import Commesse DB** è indicata la data dell'ultima
sincronizzazione e il suo esito, ad esempio quante commesse sono state lette e
quante aggiornate.

## Chi può lanciarla

La sincronizzazione richiede i permessi di amministrazione delle commesse. Se
notate dati non aggiornati, rivolgetevi a chi amministra il portale indicando
quale commessa vi sembra disallineata.

## I dati vengono modificati sul gestionale?

No. Il portale legge soltanto: non scrive né modifica nulla sul gestionale. Le
correzioni ai dati di origine vanno fatte lì, e alla sincronizzazione successiva
saranno riportate nel portale.

## Le commesse aggiunte a mano

Le commesse create direttamente nel portale e non presenti nel gestionale non
vengono toccate dalla sincronizzazione: restano dove sono.
