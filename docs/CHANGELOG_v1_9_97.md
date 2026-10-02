# CHANGELOG — v1.9.97

## Attività & Rendicontazione DGB — pannello filtri come «Relazione di Servizio IT»
- Pannello a scomparsa con badge dei filtri attivi e riepilogo «N attività nel perimetro»; menu a selezione multipla con
  ricerca integrata (pm-multiselect), etichette con conteggi.
- Filtri (prima: incaricato, stato, tipo report, modalità, orario a valore singolo + reperibilità profilo):

| Gruppo | Filtri |
|---|---|
| Contratto | Codice contratto / PM Project (filtro globale, invariato) |
| Periodo e ricerca | Dal / Al (data lavoro) · Codice attività o ticket · Ore ordinarie/giorno |
| Attività | Stato (multi) · **Tipo attività / fascia** (multi) · **Cliente** (multi) · **Codice linea** (multi) · **Ticket** presente/assente · **Modulo di intervento** collegato/non collegato · **Consuntivo vs pianificato** |
| Incaricato ed erogazione | Incaricato (multi) · Modalità (multi: sede, remoto, **smart working**) · Tipo report (multi) · Orario (multi) · Reperibile da profilo · **Intervento in reperibilità** · **Straordinario** |

- Stessa clausola per tabella, KPI, carico, riepilogo orario, distribuzione temporale, matrice oraria, export (XLSX/CSV, foglio Filtri) e API
  (`DgbModel::whereActivities` / `whereDetail` con `activityConds` + `allocConds`). Gli attributi dell'incaricato sono valutati su UNA
  stessa allocazione (EXISTS unica).
- Compatibilità: i link a valore singolo (`operator=…`, `status=…`, `mode=…`) restano validi; le chiavi singole restano valorizzate quando è
  selezionato un solo valore (matrice oraria, assenze). Con più incaricati la matrice mostra le assenze di tutti.
- Nota: «sede» esclude ora anche lo smart working (prima solo «non remoto»), perché smart working è una modalità selezionabile a parte.
