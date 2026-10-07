# MANUALE AMMINISTRATORE — v1.10.15 · Report Direzionale per tipologia

- I file DOCX/XLSX/CSV/PDF richiedono il permesso **export** su *Report direzionale*: concederlo in Gestione permessi ai ruoli interessati. Vista e stampa richiedono solo **view**.
- La tolleranza In-Line ACM predefinita è `dir.acm_tolleranza_pct` in `app_settings` (5 = consumo tra 95% e 105%). Dalla pagina si può cambiare per la singola consultazione.
- Le schede usano la classificazione `service_line` delle commesse. Una commessa senza linea non compare in nessuna scheda di tipologia: correggerla in Commesse / Progetti.
- Il valore a listino richiede il listino della commessa (fasce `FASCIA_x` in `cm_contract_rates`). Le ore senza tariffa sono mostrate come «Ore non valorizzate»; se una commessa non ha consumo a listino l'esito è «Non valutabile».
