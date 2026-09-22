# Manuale Utente — v1.8.51

## Le ore non si moltiplicano più

L'aggiornamento precedente aveva risolto solo in parte il problema delle ore
contate più volte: due delle procedure di caricamento dati continuavano a
duplicare, e anzi lo facevano a ogni esecuzione.

Ora il controllo è a livello di database e vale per tutte le procedure. Caricando
due volte lo stesso file, o ripetendo l'aggiornamento dal gestionale, i totali
restano gli stessi.

**I totali di ore potrebbero risultare più bassi di prima.** Non si è perso nulla:
sono state rimosse le righe che risultavano contate più volte.

## I codici dei rapporti dal gestionale

I rapporti arrivati dall'aggiornamento automatico avevano codici come `DGB-4521`,
che non corrispondevano a nulla sul gestionale.

Ora viene usato il codice vero. I rapporti già presenti mantengono il vecchio
codice fino al prossimo aggiornamento completo dei dati: se cercate un rapporto e
non lo trovate, provate con l'altro formato o chiedete a chi amministra il portale
di lanciare una sincronizzazione.

## Se un intervento ha più tecnici

Trovate una riga per ciascun tecnico, tutte con lo stesso codice di rapporto e le
ore di ciascuno. È corretto: sono prestazioni distinte sullo stesso intervento, e
sommandole ottenete il totale dell'intervento.
