# Manuale Amministratore — v1.8.52

## Il grafico non è più un blocco chiuso

In **Attività & Rendicontazione DGB**, il grafico mensile mostrava dodici barre e
finiva lì. Il dettaglio giornaliero esisteva, ma per arrivarci bisognava sapere
che c'era un pulsante "Giorni" e poi scegliere il mese da un menu separato.

Ora **si fa clic sulla barra del mese** e si apre il suo dettaglio giornaliero,
con i filtri che avevate impostato ancora attivi. In vista giornaliera le frecce
‹ › spostano al mese precedente e successivo.

## La linea di riferimento era sbagliata

Nel dettaglio giornaliero la linea rossa indicava 448 ore al giorno: otto ore per
i 56 incaricati comparsi nell'intero periodo filtrato. Le barre riportavano invece
le ore di chi aveva lavorato quel giorno, in genere una trentina di persone.

Il confronto era fra grandezze diverse, e il risultato faceva sembrare che si
lavorasse al 55% della capacità.

Ora il riferimento segue **gli incaricati attivi in quella giornata**. Su giugno
2023 l'utilizzo passa da circa 55% apparente a 92-101% reale, e lo scarto medio
fra ore e riferimento scende da 258 a 8 ore.

**I totali delle ore non cambiano.** Cambia il metro con cui vengono confrontati.

### I giorni senza dati

Per i giorni feriali senza attività registrate si usa la mediana degli incaricati
attivi negli altri feriali del mese. La mediana e non la media, perché giornate di
chiusura o di presidio minimo abbasserebbero la media falsando il riferimento.

Questi punti sono marcati come **stimato** nell'export, così un riferimento
inferito non si confonde con uno misurato.

### I fine settimana

Un sabato di chiusura non ha riferimento e **la linea si interrompe**: prima
scendeva a zero e risaliva, disegnando dei picchi verso il basso che sembravano un
crollo della capacità.

Un fine settimana **con ore registrate** — un turno di reperibilità — ha invece il
suo riferimento, calcolato su chi era in turno.

## Valori esatti

Passando il mouse su una barra compaiono ordinario, straordinario, totale,
riferimento, incaricati attivi e percentuale di utilizzo. Prima i valori si
potevano solo stimare a occhio sull'asse.

## Export

Le esportazioni della distribuzione hanno due colonne in più: **Incaricati
attivi** e **Nota**. Senza il numero di attivi il riferimento giornaliero non è
ricostruibile fuori dal portale.

La percentuale di utilizzo non è fra le colonne di proposito: è un rapporto e non
si somma né si media, quindi la percentuale del mese non è la somma di quelle
giornaliere. Con ore e incaricati esportati potete ricalcolarla al livello che vi
serve, dividendo due somme.
