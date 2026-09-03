# Manuale Utente — v1.8.73

## I dati DGB ora si aggiornano con la sincronizzazione

In *Attività & Rendicontazione DGB* i dati si fermavano a luglio anche dopo aver
lanciato *Sincronizza tutto*.

Il motivo: quella pagina legge tabelle che la sincronizzazione non aggiornava.
Venivano riempite da una procedura separata, che andava lanciata a parte.

Ora sono state incluse: **Sincronizza tutto aggiorna anche la pagina DGB**, e la
procedura separata non serve più.

## Cosa fare

Lanciate **Sincronizza tutto** una volta dopo l'aggiornamento. Richiederà qualche
minuto in più del solito, perché ci sono due tipi di dato nuovi con molte righe.

Al termine, la pagina DGB deve mostrare i dati fino a oggi.

## Se restasse indietro

Nell'esito della sincronizzazione controllate che le voci **Attività DGB** e
**Ore per operatore su attività DGB** risultino `ok`, con decine di migliaia di
righe lette.
