# Technical Design — PortalManager v1.9.18

## 1. Esclusione e non inclusione

Il parametro elenca le sedici linee **escluse**. Elencare le quattro incluse
sarebbe stato più corto e più fragile: ogni linea creata dopo sarebbe rimasta
fuori in silenzio, e il perimetro sarebbe invecchiato senza segnali.

Con l'esclusione, una linea nuova entra e si vede. Se non doveva entrarci,
qualcuno se ne accorge guardando i numeri — che è meglio del contrario.

## 2. Giorni distinti e giornate equivalenti

`COUNT(DISTINCT giorno)` e `SUM(ore)/8` misurano cose diverse, e la differenza è
grande: due ore al giorno per venti giorni danno 20 e 5.

La richiesta diceva «giorni lavorati». Esporre solo quella misura avrebbe però
lasciato credere che 20 giorni siano un mese pieno di lavoro.

È lo stesso principio delle tre misure di «addetti» nella v1.9.10: quando una
parola ammette letture diverse, esporle tutte costa colonne e rende la scelta
esplicita.

## 3. Il conteggio per fascia in giorni

`COUNT(DISTINCT CASE WHEN fascia = 'C' THEN giorno END)`.

Un giorno con interventi in fascia C e D conta in entrambe, quindi la somma delle
fasce può superare i giorni totali.

L'alternativa — attribuire ogni giorno a una fascia sola — avrebbe richiesto una
regola per i giorni misti: la fascia con più ore? la prima? l'ultima? Ogni scelta
sarebbe stata arbitraria e avrebbe nascosto che il giorno è misto.

Le colonne `ore_C` e `ore_D` accanto danno la ripartizione esclusiva, perché le
ore si dividono senza ambiguità.

## 4. La sottoquery correlata rifiutata

```sql
-- non funziona: la vista non può riferirsi a sé stessa
WHERE b.operatore = v_cm_it_giorni_area.operatore
```

MariaDB rifiuta il riferimento alla vista in corso di definizione.

Sostituita con un `JOIN` su una sottoquery aggregata. È anche la forma che
l'ottimizzatore gestisce correttamente sulle viste annidate — la v1.8.88 aveva
trovato `IN` ed `EXISTS` che restituivano 2 righe su 520.

## 5. Il filtro sulle attive e la sua conseguenza temporale

`commessa_attiva = 1` guarda lo stato **oggi**, non quello alla data del modulo.

La conseguenza è che i totali cambiano quando una commessa viene chiusa: un report
ristampato mesi dopo dà numeri diversi senza che nulla sia cambiato nei moduli.

`v_cm_it_giorni_tutte` espone entrambe le letture affiancate. Non risolve
l'ambiguità — nessuna vista può — ma la rende misurabile: chi si accorge di una
differenza può quantificarla invece di dubitare dei dati.

## 6. La produzione «teorica» nel nome

`produzione_teorica` e non `produzione`: il nome porta l'avvertenza.

Ore × listino è ciò che il lavoro varrebbe. `valore_addebitato` accanto è ciò che
è stato messo a carico. Chiamare la prima «produzione» avrebbe fatto sommare due
grandezze diverse in un totale che non significa nulla.

## 7. `(non indicata)` invece di NULL

I moduli senza `tech_sector` compaiono in un'area chiamata `(non indicata)`.

Un `NULL` in un raggruppamento produce una riga senza etichetta che sembra un
difetto di rendering. Una riga nominata dice che il dato manca ed è quantificabile:
se cresce, il campo si sta degradando.
