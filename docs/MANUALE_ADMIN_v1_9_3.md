# Manuale Amministratore — v1.9.3

## Le tabelle ora scorrono sotto l'intestazione

Su tutte le viste — Commesse, Service Desk, Relazione IT, Report direzionale,
Carico & Sovrapposizioni — l'intestazione delle colonne **resta visibile** mentre
si scorrono le righe.

Se la tabella è più larga della finestra, anche la **prima colonna resta ferma**
durante lo scorrimento orizzontale: senza, si finisce per leggere numeri senza
sapere di quale riga siano.

## Si adatta allo schermo

| Schermo | Altezza tabella |
|---|---|
| Alto (≥1000px) | 70% |
| Normale | 62% |
| Basso (≤700px) | 55% |
| Molto basso | 48% |

Su finestre strette il corpo del testo cala e le celle si stringono: ho preferito
questo al troncamento delle colonne, perché **su una tabella economica un numero
troncato è peggio di un numero piccolo**.

## Dove non si attiva, di proposito

- tabelle con **meno di 8 righe** — l'intestazione non esce mai dalla vista, e il
  bordo aggiunto sarebbe solo rumore
- **report di stampa** — hanno un impaginato proprio

## La stampa era il rischio

Un contenitore scorrevole **stampa solo la porzione visibile**: chi stampa un
elenco di duecento commesse riceverebbe venti righe senza alcun segnale che ne
mancano centottanta.

Nei report la tabella si stampa per intero, con l'intestazione **ripetuta a ogni
pagina**.

## Da fare in installazione

**Serve creare la cartella `assets\`** in ROOT: è nuova. Se non esiste, i due file
non vengono trovati e le tabelle restano come prima — senza errori, ma senza il
miglioramento.

## I nomi invertiti

Li ho trovati: erano nei **menu a tendina** di Attività & Rendicontazione DGB.

La correzione della v1.8.91 riguardava l'**ordinamento** degli elenchi, non la
**forma** in cui il nome viene costruito. `DgbModel` concatenava nome + cognome in
tre punti, producendo `Enrico Mancini` dove i moduli di intervento mostrano
`Mancini Enrico`.

La stessa persona compariva in due modi a seconda della schermata.

| Prima | Dopo |
|---|---|
| Alessandro Abignente | **Abignente Alessandro** |
| Matteo Aloisio | **Aloisio Matteo** |

**Se ne trovate altri, ditemi in quale schermata**: la forma dipende da dove il
nome viene costruito, e ogni classe lo fa per conto proprio. Non c'è un punto
unico da correggere — è il motivo per cui questo è sfuggito.

## Regolare le altezze

```css
/* assets/pm-tables.css */
.pm-scroll { max-height: 62vh; }
```

`vh` è la percentuale dell'altezza della finestra: alzandolo si vede più tabella e
meno del resto della pagina.
