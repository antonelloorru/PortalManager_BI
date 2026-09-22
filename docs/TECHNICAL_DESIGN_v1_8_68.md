# Technical Design — PortalManager v1.8.68

## 1. Una dimensione in più su una mappa di calore

Una heatmap classica ha due dimensioni sugli assi e una — il colore — per il
valore. Qui le dimensioni da rappresentare sono quattro: giorno, ora, quantità e
**natura**.

La v1.8.67 aveva risolto le prime tre e affidato la quarta a un dettaglio
tipografico, il grassetto delle etichette. Non funziona: l'occhio che scorre la
griglia non torna al margine per ogni cella.

La soluzione è separare **tinta** e **intensità**: la tinta porta la natura, la
saturazione il volume.

```php
if ($ordinario && !$weekend) {
    $r = (int)round(226 - 189 * $t);   // #e2e8f0 → #2563eb  blu
    …
} else {
    $r = (int)round(254 -   9 * $t);   // #fef3c7 → #f59e0b  arancione
    …
}
```

Entrambe le scale partono da un grigio-chiaro quasi neutro e arrivano al colore
pieno. Una cella con poche ore resta leggibile come "poche ore", di qualunque
natura, e la natura si distingue solo quando c'è qualcosa da vedere.

I due colori non sono scelti liberamente: sono **quelli delle barre** del grafico
sopra. Due rappresentazioni della stessa grandezza che usassero codici diversi
costringerebbero a impararne due.

## 2. Il fine settimana entra nella condizione, non solo l'ora

```php
$natura = ($ord && !$we) ? 'ordinario' : 'reperibilità';
```

La regola della v1.8.53 è che sono ordinarie le fasce 09–13 e 14–18 **nei giorni
feriali**. Una cella delle 10:00 di sabato è reperibilità.

Colorarla di blu perché l'ora rientra nella fascia sarebbe stato un errore
sottile: il grafico contraddirebbe la classificazione usata per i calcoli, e
qualcuno prima o poi si fiderebbe del grafico.

La conseguenza visiva — colonne del fine settimana interamente arancioni — non è
un effetto collaterale da correggere: è la regola resa visibile.

Lo stesso criterio governa i totali per natura mostrati sopra la matrice, che
sono calcolati con la medesima condizione: se colore e totale divergessero, uno
dei due sarebbe sbagliato.

## 3. Verificare prima di correggere

La seconda segnalazione riguardava il grafico a colonne che «non si aggiorna».
Prima di modificarlo ho misurato:

```
month  bucket=1   ordinario=9595.7  reperibilità=1827.8
day    bucket=31  ordinario=9595.7  reperibilità=1827.8
```

Il grafico si aggiornava. E le porzioni arancioni erano visibili: altezza mediana
16,4 pixel su 150, **nessuna** sotto la soglia dei 2 pixel oltre la quale una
banda sparisce.

Il difetto era un altro: il titolo identico nelle due viste. Chi passa da mesi a
giorni vede cambiare le barre ma non ha conferma di essere nella vista giusta, e
in assenza di conferma l'impressione è che non sia successo nulla.

Correggere il grafico avrebbe risolto un problema inesistente lasciando quello
vero. Il titolo dichiara ora periodo, numero di barre e totale ore.

## 4. Perché i due grafici non danno lo stesso totale

| | Totale |
|---|---|
| Grafico a colonne | 11.423,5 h |
| Matrice oraria | 11.277,1 h |

Differenza di 146,4 ore, l'1,3%: sono gli interventi a cavallo della mezzanotte,
che la matrice esclude perché ripartirli richiederebbe di spezzarli su due
giorni.

La quota di reperibilità resta allineata — 16,0% contro 15,2% — perché
l'esclusione riguarda interventi in gran parte notturni, quindi toglie
proporzionalmente più reperibilità che ordinario.

È una divergenza attesa, misurata e dichiarata. Vale la pena registrarla: senza,
il primo che confronta i due totali la scambia per un difetto.
