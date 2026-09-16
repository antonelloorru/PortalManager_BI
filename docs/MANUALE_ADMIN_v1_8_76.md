# Manuale Amministratore — v1.8.76

## In home, lo stato della sincronizzazione

Due elementi, con funzioni diverse.

**La scheda «Ultima sincronia»**, fra gli indicatori in alto, c'è sempre. Mostra
quanto tempo è passato dall'ultimo aggiornamento riuscito — `5h fa`, `2g fa`,
oppure `mai` — con il colore che cambia:

| Colore | Ore dall'ultima riuscita |
|---|---|
| verde | fino a 26 |
| giallo | 26 – 36 |
| rosso | oltre 36, o mai |

Passandoci sopra il mouse compare la data e l'ora esatte.

**Il banner in testa** compare **solo quando c'è un problema**: sincronizzazione
in ritardo, ultima fallita, o mai eseguita. Riporta quando è avvenuta l'ultima
completa, quante righe aveva letto, e un pulsante che porta alla pagina di
sincronizzazione.

Quando tutto funziona non compare nulla. È voluto: un riquadro verde permanente
diventa invisibile dopo una settimana, e con lui l'informazione che dovrebbe
dare.

## Un dettaglio che conta

La data riportata è quella dell'ultima sincronizzazione **riuscita**, non
dell'ultimo tentativo.

Se stanotte la sincronizzazione è fallita e l'ultima riuscita è di ieri, l'avviso
dice *«l'ultima sincronizzazione è fallita»* e insieme *«ultima completa: 30 ore
fa»*. Sapete sia che c'è un problema sia quanto sono vecchi i dati.

Se citasse il tentativo fallito come «ultima sincronia», direbbe che i dati sono
freschi proprio quando non lo sono.

## Perché 26 e non 24 ore

Fra una sincronizzazione notturna e la successiva passano 24 ore più il tempo di
esecuzione. Una soglia a 24 avrebbe prodotto un giallo quasi ogni giorno, e una
segnalazione che scatta nel funzionamento normale è rumore.

## Chi lo vede

Solo i ruoli fino al 5. Per un dipendente sarebbe un avviso su una cosa che non
può risolvere, e quelli si imparano a ignorare — insieme a tutti gli altri.

## Se non compare nulla

Verificate che la v1.8.75 sia stata applicata: senza le tabelle della
pianificazione non c'è nulla da mostrare. L'avviso in quel caso non produce
errori, semplicemente non appare — la home è la prima pagina che tutti aprono e
non deve rompersi per un elemento accessorio.
