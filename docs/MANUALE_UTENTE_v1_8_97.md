# Manuale Utente — v1.8.97

## Il costo orario del personale

Il portale calcola ora il costo orario di ogni dipendente a partire dal
**TotCostoTab** della sezione Compensation & Benefit:

```
costo orario = TotCostoTab ÷ giorni lavorativi ÷ 8
```

I giorni lavorativi sono 220 per impostazione predefinita, modificabili per anno.

## Costo aziendale e costo di vendita

Nelle analisi di redditività trovate due valori distinti:

- **costo aziendale** — quanto costa all'azienda erogare il servizio
- **costo di vendita** — quanto è stato addebitato al cliente

Sono due cose diverse: la differenza è il ricarico applicato.

## Il costo segue l'anno dell'intervento

Un intervento del 2024 usa il costo del 2024, non quello di oggi. Così i margini
delle commesse passate non cambiano quando gli stipendi vengono aggiornati.

Se manca il dato di un anno, viene usato quello dell'ultimo esercizio disponibile,
e la riga è segnalata come **stimata**.

## La copertura

Ogni commessa riporta una **copertura**: la quota di interventi per cui il costo è
consolidato. Più è alta, più il margine è attendibile.
