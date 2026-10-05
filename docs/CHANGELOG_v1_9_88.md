# CHANGELOG — v1.9.88

Relazione di Servizio IT. Due interventi distinti.

## A. Perimetro dati — tutto l'eseguito nel periodo, per data del modulo
- **Giorni lavorati**: eliminati il vincolo «solo commesse attive» (stato della commessa *oggi*) e l'esclusione delle
  linee non a produzione (`it_giorni_linee_escluse`: 16 linee, tra cui NV_*, WTS-PRES, WTS-SD, WTS-HD, WTS-MON).
  Perimetro = ogni modulo di intervento con data nel periodo. Il filtro facoltativo «Stato commessa» (v1.9.87) resta.
- Rimossa la **riconciliazione attive/chiuse** (pagina, stampa, Word, foglio XLSX) e la vista `v_cm_it_giorni_tutte`.
- **Sezioni DGB** (riepilogo per contratto, dettaglio per commessa): data = data del modulo (`report_date`), in mancanza
  l'inizio attività. Tolto il ripiego su data di completamento / chiusura.
- Viste storiche `v_cm_it_giorni_operatore`, `_area`, `_quadro` riallineate allo stesso perimetro.
- Impostazioni `it_giorni_solo_attive` (→ 0) e `it_giorni_linee_escluse` marcate «dismesse» (valori conservati).

Effetto (giugno 2026): Giorni lavorati da 2.093 h / 100 commesse a **11.050 h / 202 commesse**, identico ai KPI della pagina
(2.484 interventi, 1.437 giorni-uomo).

## B. Granularità e metriche — ore non valorizzate
- Nuova colonna di vista `valorizzata` (tariffa di listino trovata). Ore **valorizzate** / **non valorizzate** e giorni-uomo
  con ore non valorizzate in quadro, tabella per persona, ripartizioni ed export.
- Nuove ripartizioni (`ItServiceModel::giorniPer`): **Persona** (tabella esistente, + ore totali e non valorizzate),
  **Codice linea**, **Area tecnologica**, e dimensioni correlate **Linea di servizio**, **Cliente**, **Commessa**, **Mese**,
  **Fascia**, **Stato commessa**. Colonne: persone, giorni-uomo, interventi, ore, valorizzate, non valorizzate, quota, produzione teorica.
- Pagina: blocchi a scomparsa (Codice linea e Area tecnologica aperti). XLSX: un foglio per dimensione + colonne nuove in
  «Giorni per operatore». Word e stampa: tabelle per codice linea e area tecnologica.

Giugno 2026: 5.259 h valorizzate + 5.791 h non valorizzate = 11.050 h; ogni ripartizione somma al totale.

## Nota di sequenza
Basata su v1.9.87 (`feature/v1.9.87-itservice-stato-commessa`): merge dopo la 1.9.87. Conflitto solo su `VERSION`.
