# Manuale Amministratore — v1.9.38

## Ordinativi Pratix: filtri ed export
Il pannello **Filtri** ora offre due menu a tendina:
- **Commerciale** e **Cliente**, con l'elenco completo dei valori presenti,
  ricavati dall'anagrafica commessa. Si combinano con gli altri filtri (ricerca,
  "Mostra", ordinamento).

Tre pulsanti di estrazione, sempre allineati ai filtri impostati:
- **XLSX**: cartella a tre fogli (ordinativi, commesse collegate, anomalie).
- **CSV**: elenco ordinativi, apribile direttamente in Excel.
- **PDF**: apre una pagina di stampa; da lì "Stampa" e "Salva come PDF".

## Prerequisito dati
I filtri Commerciale/Cliente si popolano dalla vista `v_cm_pratix_righe`. Se
dopo l'aggiornamento risultassero vuoti, verificare che la migration SQL della
release sia stata eseguita (tab Migrazioni DB / SQL Runner).
