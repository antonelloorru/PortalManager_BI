# Manuale Amministratore — v1.8.57

## Sincronizza tutto

In **Sincronizzazione gestionale** c'è ora il riquadro **Sincronizzazione
completa**: un pulsante aggiorna tutti e sei i dataset in un'unica operazione,
nell'ordine giusto, con la connessione aperta una volta sola.

L'ordine è: commesse → costi fascia → professionisti → tariffe → allocazioni →
rapporti. Non è casuale: rapporti, tariffe e allocazioni si agganciano alle
commesse per codice, quindi le commesse vanno lette per prime.

**Anteprima completa** mostra che cosa verrebbe fatto senza scrivere nulla, ed è
limitata a 200 righe per dataset: i numeri dell'anteprima non sono i volumi reali.

Se un dataset fallisce, gli altri proseguono. Il riepilogo mostra per ciascuno
righe lette, nuove, aggiornate e secondi impiegati, con l'eventuale errore
accanto.

## Tre nuove fonti di dati

L'analisi del dump ha individuato tre tabelle popolate che non venivano importate
e che servono alla valutazione economica.

**Tariffe di contratto** — 34.400 righe. Quanto vale un'ora su ciascuna commessa,
distinto per tipo di attività e per unità di misura (ora, giorno, mezza giornata,
ora extra). Tariffa oraria di ricavo media 46,57 €.

**Allocazioni pianificate** — chi era assegnato a quale commessa e per quale
periodo. Permette di confrontare l'assegnato con il consuntivato.

**Costi orari delle fasce** — il costo reale per fascia professionale: Junior
31,25, Senior 43,75, Master 68,75 €/ora. Le fasce erano già in anagrafica ma
senza importi.

## Che cosa cambia nell'analisi

Prima il 67% dei rapporti non aveva un ricavo: il consuntivo non lo riportava e
non c'era altro modo di ricavarlo.

Con le tariffe importate, **la copertura passa dal 32,6% al 55,0%**. La vista
`v_cm_marginalita` distingue sempre il valore **rilevato** da quello **stimato da
tariffa**: un dato inferito non deve confondersi con uno misurato.

```sql
SELECT commessa, unita_organizzativa,
       ROUND(SUM(ore),2)     AS ore,
       ROUND(SUM(ricavo),2)  AS ricavo,
       ROUND(SUM(costo),2)   AS costo,
       ROUND(SUM(margine),2) AS margine
  FROM v_cm_marginalita
 WHERE anno_mese >= '2026-01'
 GROUP BY commessa, unita_organizzativa;
```

## Due avvertenze sui dati del gestionale

**Le allocazioni sono duplicate alla fonte.** La tabella non ha chiave primaria e
ogni riga compare due volte: 199.458 righe per 99.729 allocazioni reali.
L'import usa `DISTINCT` e ne carica 99.729. Se contate 199.458 sul gestionale,
non è un errore del portale.

**Le tariffe sono più d'una per commessa** — in media sei, una per tipo attività.
La vista usa la media delle tariffe orarie di ricavo e indica in
`tariffe_disponibili` su quante è calcolata. Dove è 1 la stima è esatta, dove è 6
è una media: tenetene conto prima di usare il valore in una discussione con il
cliente.
