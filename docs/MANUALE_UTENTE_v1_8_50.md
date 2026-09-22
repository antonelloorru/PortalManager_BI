# Manuale Utente — v1.8.50

## Le ore ora sono contate una volta sola

Il portale poteva contare due volte le stesse ore quando la stessa prestazione
arrivava da due strade diverse — un file caricato a mano e l'aggiornamento
automatico dal gestionale.

Il problema è stato risolto alla radice: ora il sistema riconosce che si tratta
della stessa prestazione e aggiorna quella esistente invece di aggiungerne
un'altra.

**I totali di ore potrebbero risultare più bassi di prima.** Non si è perso
nulla: sono state rimosse le righe contate due volte.

## I codici di rapporto sono quelli del gestionale

Alcuni codici avevano un numero aggiunto in fondo dopo una barra, come
`WTS_24_000123/21`. Cercando il codice sul gestionale non si trovava
corrispondenza.

Ora il codice è identico a quello del gestionale. Se un intervento è stato svolto
da due tecnici, trovate due righe con lo stesso codice e nomi diversi: è corretto,
sono due prestazioni distinte sullo stesso intervento.

## I filtri funzionano su tutto

In Attività & Rendicontazione DGB, filtrando per "da remoto" o per tipo di
report, cambiavano solo i totali in alto mentre la tabella restava uguale. Ora
cambiano entrambi.

## Il menu è riordinato

Le voci di Gestione Commesse sono nello stesso posto ma in ordine diverso,
raggruppate per tipo di attività:

- prima le **anagrafiche**: commesse, tecnici, unità organizzative, professionisti,
  fasce di costo
- poi l'**acquisizione dei dati**: sincronizzazione e import
- infine l'**analisi**: attività DGB, timesheet, carico di lavoro, Gantt

Due voci hanno cambiato nome perché il precedente traeva in inganno: "Import
Commesse DB" si chiama ora "Connessione al gestionale", perché serve a
configurare il collegamento e non a importare; gli import da file lo dicono nel
nome.

Nessuna voce è stata rimossa.
