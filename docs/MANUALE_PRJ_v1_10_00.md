# MANUALE — Progetti PRJ (v1.10.00)

## Amministratore
- Il motore di calcolo è installato, ma la release non aggiunge pagine.
- **Controllo calcoli:** `php tools/verify_v1_10_00.php --db=<database>` ricalcola gli 8 scenari ASPI e confronta i risultati con i valori attesi del foglio di dimensionamento.
- **Modifiche ai parametri:** cambiare un parametro (es. oneri) con una data di decorrenza crea una nuova versione. I calcoli con data precedente continuano a usare il valore precedente.
- **Calc run:** non si modificano e non si cancellano. Un progetto con calc run non può essere eliminato.

## Utente finale
- Nessuna nuova pagina. Dalla v1.10.01, nella scheda progetto, la sezione Scenari mostra:
  - per ogni scenario: FTE, costo del personale, costo aziendale, % sul canone, margine, ribasso massimo a pareggio;
  - il confronto affiancato tra scenari.
