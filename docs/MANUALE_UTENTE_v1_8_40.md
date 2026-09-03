# Manuale Utente — Commesse / Progetti (v1.8.40)

## Dove si trova

Menu **Gestione Commesse → Commesse / Progetti**.

## Come è fatta la pagina

Tre blocchi, dall'alto: creazione di una nuova commessa (se si hanno i permessi),
pannello dei filtri, elenco delle commesse.

L'elenco ha 29 colonne, le stesse del file Excel ufficiale. È più largo dello
schermo: si scorre orizzontalmente trascinando o con Maiusc + rotellina del mouse.

## Trovare una commessa

Il modo più rapido è il campo **Cerca**, che accetta indifferentemente codice,
nome o abbreviazione. Poi si preme **Applica filtri**.

Per ricerche più mirate ci sono i menu a tendina: commerciale, stato, cliente,
azienda, tipo, stato economico, compliance, anomalie, fascia di valore, periodo.
I filtri si sommano fra loro. Il pulsante **Azzera** li rimuove tutti.

## Leggere i colori

- **Stato**: verde APERTA, ambra SOSPESA, grigio CHIUSA.
- **Stato economico**: verde OK, ambra CRITICO, rosso SFORATO.
- **Anomalie bloccanti**: rosse quando sono più di zero.

Le colonne che finiscono con "a oggi" indicano quanto è maturato fino a questo
momento; quelle senza indicano il totale previsto sull'intero contratto.

## Quante commesse sto vedendo

In alto a destra c'è un contatore del tipo `128 / 1860 commesse`: il primo numero
è quello che si sta vedendo, il secondo il totale. Se compare "(filtrate)"
significa che c'è almeno un filtro attivo. Lo stesso contatore si trova nelle
pagine Gantt commesse e Carico risorse.

## Aprire il dettaglio

L'icona con il grafico, in fondo alla riga, apre la scheda completa della commessa:
team, rapporti di intervento, redditività e Gantt di dettaglio.

L'icona con la freccia nella colonna **link** apre invece il contratto sul
gestionale esterno, in una nuova scheda del browser.

## Scaricare l'elenco

I pulsanti **XLSX** e **CSV** scaricano esattamente quello che si sta vedendo,
filtri compresi, con tutte e 29 le colonne. Se il file scaricato sembra vuoto,
verificare di non avere filtri troppo restrittivi attivi.

## Problemi frequenti

**Le colonne economiche sono vuote.** I dati economici arrivano dall'import del
file del gestionale. Se mancano, occorre chiedere a un amministratore di eseguire
l'import.

**Il file scaricato non si apre.** Verificare di aver aggiornato la pagina con
Ctrl+F5 dopo l'ultimo aggiornamento del portale, poi riprovare.

**Non vedo la voce di menu.** Il modulo dipende dai permessi del proprio ruolo:
rivolgersi all'amministratore.
