# Manuale Amministratore — v1.9.40

## Relazione di Servizio IT — performance per persona
La pagina mostra, per ciascun tecnico e nel periodo scelto:
- **Giorni su commesse attive**, **Interventi**, conteggi **Fascia C**/**D** (e Altre/N/D),
  **Aree tecnologiche** toccate, **Ore** e **Produzione teorica** (a listino).
- Una seconda tabella dettaglia gli interventi/ore per ogni fascia A–F e N/D.

## Fascia professionale
Risolta automaticamente da tre fonti in cascata (band del rapportino → alias del testo
grezzo → fascia unica di listino della commessa). L'origine è tracciata nella vista
`v_rsi_report_fascia`. Gli interventi non risolti compaiono come **N/D** e non generano
produzione teorica: sono la misura della copertura del dato fascia.

## Stati "commessa attiva"
Di default è considerata attiva la sola commessa **APERTA**. Per includere anche
**SOSPESA**, modificare la costante `RSI_STATI_ATTIVI` in testa alla pagina.

## Copertura fascia
Se molti interventi risultano N/D, arricchire la mappatura: valorizzare `band_id` sui
rapportini, aggiungere alias in `cm_alias_band`, o completare le tariffe di listino
(`FASCIA_x`) sulle commesse.
