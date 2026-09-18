# Manuale Amministratore — v1.8.68

## La matrice ora distingue le due nature

Nella distribuzione sulle 24 ore, il colore della cella indica **la natura** delle
ore e non solo la quantità:

- **blu** — ore in fascia ordinaria
- **arancione** — ore in reperibilità

Sono gli stessi colori delle barre del grafico sopra, così i due si leggono con
lo stesso codice. Più la cella è intensa, più ore vi sono state lavorate.

Prima la tinta era una sola e la distinzione stava nel grassetto delle etichette
a sinistra: scorrendo la griglia non si notava, e due celle uguali in fasce
diverse apparivano identiche.

Sopra la matrice trovate ora i **totali per natura** con la percentuale.

## Il fine settimana è tutto arancione

Non è un difetto. La regola stabilisce che sono ordinarie le fasce 09–13 e 14–18
**dal lunedì al venerdì**: nel fine settimana anche quelle ore sono reperibilità.

Le colonne di sabato e domenica risultano quindi arancioni per intera. È la
regola resa visibile — la stessa usata per tutti i calcoli di reperibilità.

## Sul grafico a colonne

Ho verificato prima di modificarlo: **si aggiornava già** passando a *Giorni
(mese)*, con 31 barre e le porzioni di reperibilità correttamente visibili
(altezza mediana 16 pixel su 150).

Il difetto era un altro: il titolo restava identico nelle due viste, e l'unico
modo di accorgersi del cambio era contare le barre. Ora il titolo dichiara il
periodo, il numero di barre e il totale delle ore:

> *Distribuzione carico — ordinario vs reperibilità · giorni di marzo 2026 · 31
> barre · 11.423,5 h*

## I due grafici non danno lo stesso totale

È atteso, e conviene saperlo prima di notarlo:

| | Ordinario | Reperibilità | Totale |
|---|---|---|---|
| Grafico a colonne | 9.595,7 | 1.827,8 | 11.423,5 |
| Matrice oraria | 9.568,1 | 1.709,0 | 11.277,1 |

La differenza di 146 ore (**1,3%**) sono gli interventi che attraversano la
mezzanotte: la matrice li esclude, perché ripartirli richiederebbe di spezzarli
su due giorni.

La quota di reperibilità resta allineata — 16,0% contro 15,2% — e le due letture
si confermano a vicenda.
