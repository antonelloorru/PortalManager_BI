# Manuale Amministratore — v1.8.54

## La nuova scheda Anomalie orarie

In **Attività & Rendicontazione DGB** compare la linguetta **Anomalie orarie**,
con accanto un contatore rosso delle segnalazioni gravi. Il contatore è fuori
apposta: un controllo che va aperto per sapere se c'è qualcosa da vedere viene
dimenticato dopo poche settimane.

## Che cosa viene controllato

**Ore identiche su più commesse** — severità alta. Lo stesso tecnico ha imputato
la stessa quantità di ore a commesse diverse, nello stesso giorno e con lo stesso
orario di inizio. È il segno tipico della compilazione per copia: si duplica una
riga cambiando la commessa senza rivedere le ore.

Sui dati attuali: **1.459 segnalazioni**, 51 tecnici, 7.268 ore. Il caso più
grave è un tecnico con 40 ore in una giornata — cinque righe da 8 ore su due
commesse, tutte con inizio alle 09:00.

L'orario di inizio fa parte del controllo: due interventi da due ore, uno la
mattina e uno il pomeriggio, sono normali e non vengono segnalati.

**Ore giornaliere fuori scala** — oltre 24 ore in un giorno è severità alta
(errore certo, 14 casi); fra 12 e 24 è media (da verificare, 767 casi), perché
una giornata lunga con reperibilità notturna è possibile.

## Che cosa NON viene controllato, e perché

La sovrapposizione fra due interventi dello stesso tecnico sembrerebbe il
controllo più ovvio, e produrrebbe 14.058 segnalazioni.

È stata esclusa perché su questi dati l'orario di fine è spesso la **finestra
entro cui l'intervento andava svolto**, non l'orario in cui è finito: il 46%
delle attività dichiara durate superiori alle otto ore. Due interventi assegnati
alla stessa giornata risultano quindi quasi sempre "sovrapposti" senza che nulla
di anomalo sia successo.

Attivarla avrebbe riempito l'elenco di falsi allarmi, e l'effetto sarebbe stato
far ignorare anche le 1.459 anomalie vere.

## Come lavorare le segnalazioni

**Non sono errori accertati.** Il portale non conosce il contesto: alcune ore
identiche su più commesse sono legittime, quando un intervento serve davvero più
commesse in parallelo.

Ordine suggerito:

1. **Oltre 24 ore** (14 casi) — errori certi, si correggono subito.
2. **Ore identiche** (1.459) — partire dai casi con più ore, che sono i più
   probabili errori di copia.
3. **Fra 12 e 24 ore** (767) — verificare a campione.

Cliccando una scheda di riepilogo l'elenco si filtra su quel tipo.

## Le correzioni si riflettono da sole

Le anomalie sono ricalcolate a ogni apertura della scheda. Correggendo il dato sul
gestionale e risincronizzando, la segnalazione sparisce senza operazioni
aggiuntive: non c'è nulla da "chiudere" o marcare come risolto.

## Soglie

In `app_settings`: `anomaly_hours_day_max` (24), `anomaly_hours_day_warn` (12),
`anomaly_min_projects` (2).

Come per gli orari, i valori sono presenti anche nelle viste SQL: modificarli nei
parametri documenta la nuova soglia ma richiede una piccola release per
applicarla. Segnalatelo se volete cambiarle.
