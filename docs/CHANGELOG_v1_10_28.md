# CHANGELOG — v1.10.28 (2026-10-09)

Software 1.10.28 · Schema 1.10.28 · Upgrade `sql/migration_v1_10_28.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Relazione Tecnici — colonna «Linea di servizio»
Nuova colonna **Linea di servizio**, subito a destra di **Codice linea**, in entrambe le schede: a schermo, in stampa e negli export CSV, XLSX, DOCX e PDF. La linea è l'etichetta leggibile del codice: ad esempio WTS-ACM corrisponde a «Chiavi in mano», NV_AI a «Nval - Attività Interne - AI».

| Scheda | Tabelle |
|---|---|
| Tecnici | Riepilogo per tecnico e codice linea · Metriche di dettaglio. Nelle righe «Totale <tecnico>» e nel totale generale la cella resta vuota, perché le linee sono più di una. |
| Rapporti di intervento | Per commessa · drill-down dei moduli · Dettaglio moduli di intervento (stampa ed export) · export della singola commessa |
