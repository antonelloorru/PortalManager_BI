# Technical Design — PortalManager v1.8.52

## 1. Il denominatore sbagliato

Il difetto centrale è statistico, non grafico: un rapporto calcolato con un
denominatore che non corrisponde al numeratore.

```
utilizzo = ore lavorate nel giorno / (ore standard × incaricati del PERIODO)
```

Il numeratore riguarda un giorno e le persone in servizio quel giorno. Il
denominatore riguarda tutte le persone comparse in dodici mesi. Il rapporto non
misura nulla di interpretabile, e il valore che produce — circa il 55% — è
sistematicamente basso.

In vista mensile lo stesso calcolo regge, perché su un mese la quasi totalità
dell'organico compare almeno una volta e la moltiplicazione per i giorni
lavorativi riporta le due grandezze sulla stessa scala.

È il motivo per cui il difetto non si notava: la vista usata più spesso era
quella corretta.

## 2. Il riferimento corretto

```sql
SELECT DATE(...) k, SUM(ao.hours), SUM(ao.extra_hours),
       COUNT(DISTINCT ao.id_operator) AS actives
  FROM ... GROUP BY k
```

`actives` è il numero di persone che hanno consuntivato ore quel giorno, e il
riferimento diventa `ore standard × actives`. Numeratore e denominatore parlano
ora della stessa popolazione.

Verificato su giugno 2023: lo scarto medio fra ore lavorate e riferimento passa da
258,52 a 8,52 ore, e l'utilizzo si colloca fra 92% e 101% — valori che descrivono
una giornata di lavoro normale.

### I giorni senza dati

Un giorno feriale senza attività registrate ha `actives = 0`, e il riferimento
sparirebbe. Si usa allora la **mediana** degli attivi nei giorni feriali del mese.

La mediana, non la media: un mese con due giorni di chiusura e un ponte ha una
media abbassata da quei giorni, mentre la mediana resta sul valore tipico. Con 30
bucket la differenza è concreta.

Questi punti sono marcati `estimated` e riportati come "stimato" nell'export: un
riferimento inferito non deve essere indistinguibile da uno misurato.

### I fine settimana

Il fine settimana ha riferimento **solo se ci sono ore**. Un turno di reperibilità
va confrontato con la capacità di chi era in turno; un sabato di chiusura non ha
un riferimento perché non c'era capacità pianificata.

La distinzione è fra "il valore è zero" e "il valore non esiste", ed è la stessa
che governa il punto seguente.

## 3. Interruzione della linea

Una polilinea che attraversa un punto a zero disegna una V. Se lo zero significa
"nessun riferimento" e non "riferimento pari a zero", quella V è una figura che
il grafico inventa.

```php
foreach ($b as $i => $r) {
    if ($r['baseline'] > 0) $seg[] = $punto;
    elseif ($seg) { $svg .= polilinea($seg); $seg = []; }
}
```

Ogni tratto continuo è un `<polyline>` separato. Un punto isolato — un fine
settimana con ore fra due giorni di chiusura — diventa un cerchietto, altrimenti
sparirebbe: una polilinea di un solo punto non disegna nulla.

Sui dati di giugno la linea risulta in quattro segmenti, uno per settimana
lavorativa.

## 4. Drill-down

La gerarchia mese → giorno esisteva già nei dati e nel modello. Mancava solo il
gesto per percorrerla.

```php
echo dgb_dist_svg($dist, fn($k) => $qs(['gran' => 'day', 'month' => substr($k, 0, 7)]));
```

Il generatore SVG riceve una funzione che, dato il mese, restituisce l'URL del
dettaglio. `$qs()` preserva i filtri attivi, quindi si scende nel giorno
mantenendo il contesto — un drill-down che resetta i filtri costringe a
reimpostarli e viene abbandonato.

Il parametro è opzionale e vale `null` negli export, dove un SVG con collegamenti
non ha senso.

L'area cliccabile è un rettangolo trasparente a tutta altezza, non la sola barra:
nei mesi con poche ore la barra è alta pochi pixel e sarebbe un bersaglio
impraticabile.

In vista giornaliera non ci sono collegamenti: sotto il giorno non c'è un livello.

## 5. Valori esatti

Ogni barra ha un `<title>` con ordinario, straordinario, totale, riferimento,
incaricati attivi e utilizzo percentuale. È il meccanismo nativo di SVG per i
suggerimenti, non richiede JavaScript e funziona anche sul file esportato.

Prima i valori si potevano solo stimare a occhio sull'asse — su un grafico con
trenta barre e una scala fino a 448, un errore di lettura di venti ore era
inevitabile.

## 6. Perché la percentuale sta nel suggerimento e non come misura

L'utilizzo è un rapporto, quindi non additivo: la percentuale del mese non è la
somma né la media di quelle giornaliere. Compare nel suggerimento della singola
barra, dove riguarda un solo bucket ed è corretta, e non fra le colonne
esportate, dove inviterebbe a essere sommata.

L'export riporta invece ore e incaricati attivi, che sono additivi e permettono di
ricalcolare il rapporto a qualunque livello di aggregazione.
