# CHANGELOG — v1.10.35 (2026-10-09)

Software 1.10.35 · Schema 1.10.35 · Upgrade `sql/migration_v1_10_35.sql` (cumulativo da 1.10.06) · Plugin pm-ats 1.3.4 (invariato)

## Relazione Tecnici › nuova scheda «ServiceDesk»

### 1. Scheda
- Quinta scheda della Relazione Tecnici (`tab=servicedesk`).
- Contenuto:
  - KPI;
  - tabella «Metriche» con valore e formula SQL;
  - ticket per Unità Organizzativa;
  - dettaglio per contratto con link alla scheda commessa.
- Stampa ed export CSV / XLSX / DOCX / PDF.

### 2. Metriche aggregate
| Metrica | Query |
|---|---|
| Contratti WTS-SD | `COUNT(DISTINCT p.id)`: `service_line = 'WTS-SD'`, durata ∩ periodo o con moduli nel periodo |
| Valore totale contratti | `SUM(p.value_total)`, più la competenza nel periodo con pro-rata mensile |
| Media risorse per contratto | `AVG(COUNT(DISTINCT incaricato))` per contratto, sui contratti con moduli nel periodo |
| Ticket gestiti (totale) | `COUNT(DISTINCT ticket)`: codici ticket dei moduli WTS-SD (i riferimenti liberi non sono contati) |
| Ticket gestiti da altri team | `COUNT(DISTINCT ticket)` con almeno un modulo di una risorsa fuori dalle UO escluse |
| Quota % altri team | `100 × altri team / totale` |

### 3. Filtri di esclusione
- Pannello Filtri › **Esclusioni**: Unità Organizzative escluse, a scelta multipla.
- Valore predefinito «Service Desk»; si può scegliere «nessuna». Il valore si conserva con «Applica» e su stampa ed export.
- La tabella per Unità Organizzativa evidenzia le unità escluse e riporta i ticket lavorati solo da loro.
- Restano attivi tutti i filtri globali del pannello (periodo, contratto, Unità Organizzativa, tecnico…).

### Sicurezza
I valori economici (valore totale e di competenza) compaiono solo con il permesso virtuale «Relazione Tecnici: valori» (`tech_report_economics.php`). Senza il permesso sono rimossi lato server da vista ed export.
