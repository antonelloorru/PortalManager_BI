# CHANGELOG — v1.10.32 (2026-10-09)

Software 1.10.32 · Schema 1.10.32 · Upgrade `sql/migration_v1_10_32.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Relazione Tecnici › nuova scheda «Controllo Reperibilità»
Elenco dei tecnici con un intervento in reperibilità e un'attività lavorativa ordinaria nel giorno lavorativo successivo.

### 1. Scheda
- Terza scheda della Relazione Tecnici (`tab=reperibilita`), accanto a Tecnici e Rapporti di intervento.
- KPI:
  - interventi in reperibilità, con il numero di tecnici;
  - casi con attività il giorno successivo, in percentuale sugli interventi in reperibilità;
  - tecnici con almeno un caso.
- Stampa ed export CSV / XLSX / DOCX / PDF dallo stesso report.

### 2. Correlazione temporale
| Fase | Regola |
|---|---|
| Reperibilità | modulo con inizio 18:01–08:59 |
| Turno | inizio 18:01–23:59 → giorno stesso · inizio 00:00–08:59 → giorno precedente |
| Giorno successivo | primo giorno lavorativo dopo il turno (lun–ven, esclusi i festivi nazionali e Pasquetta) |
| Correlazione | primo modulo dello stesso tecnico (dipendente o professionista) con inizio 09:00–18:00, non prima della fine dell'intervento in reperibilità |

### 3. Colonne filtrabili
- Colonne: Tecnico / Incaricato · Data/Ora Reperibilità · Rif. Modulo Intervento (reperibilità) · Data/Ora Giorno Succ. · Rif. Modulo Intervento (giorno succ.) · Cliente · Codice Commessa · Tipo (linea di servizio).
- Filtri globali del pannello: si applicano agli interventi in reperibilità (contratto, periodo, unità organizzativa, tecnico, modalità…).
- Filtri di colonna: una riga di campi «contiene» sotto l'intestazione, senza distinzione di maiuscole e accenti. Nella vista filtrano al volo; si riportano su stampa ed export (`cf[i]`) e si conservano con «Applica».
