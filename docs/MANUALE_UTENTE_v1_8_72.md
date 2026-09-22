# Manuale Utente — v1.8.72

## Risolto l'errore nella distribuzione oraria

I messaggi di avviso che comparivano sopra la griglia delle 24 ore sono stati
eliminati. Era un difetto tecnico nell'ordine con cui la pagina veniva costruita.

**Lo stesso difetto impediva l'export**: il testo dell'avviso finiva dentro il
file e lo rendeva illeggibile. Ora funziona.

## Analisi per divisione aziendale

Le commesse riportano ora la **divisione** a cui appartengono — Sistemistica,
Assistenza Tecnica, NIS, ANT, WeSecure.

Permette di vedere quante ore e quale margine produce ciascuna struttura: le ore
di una divisione sono quelle lavorate sulle sue commesse.

Alcune commesse risultano ancora *non assegnate*: sono quelle non ancora
allineate con il gestionale, e si riducono dopo ogni sincronizzazione.
