# CHANGELOG — v1.9.87

Relazione di Servizio IT (`it_service.php`).

## 1. UI — filtro «Stato commessa»
- Nuovo multi-select con ricerca nel gruppo «Contratto e stato commessa»: **Aperta**, **Chiusa**, **Sospesa**, **Non chiusa**.
- Parametro GET `stato_commessa[]` (anche CSV), elenco chiuso. Conteggiato nel badge dei filtri attivi, mantenuto nei link
  di stampa / XLSX / Word e nel caricamento on-demand del «Dettaglio per commessa»; riportato nel foglio «Filtri» e nell'intestazione della stampa.
- Fonte: `cm_projects.operational_status`. «Chiusa» = Chiusa / Annullata / Persa; «Non chiusa» = tutte le altre
  (stessa definizione di `commessa_attiva` della sezione Giorni).

## 2. Bug — disallineamento dei dati tra sezioni
Ogni sezione ricostruiva i filtri a mano su una vista diversa, con un sottoinsieme diverso:

| Sezione | Filtri applicati prima | Ora |
|---|---|---|
| KPI, grafici, tabella, andamento | tutti | tutti |
| Riepilogo costi | periodo, incaricati, codice linea, cliente, contratto | **tutti** |
| Giorni per operatore / area / quadro | periodo, incaricati, codice linea, cliente, contratto | **tutti** |
| Riconciliazione giorni | periodo, incaricati, contratto | **tutti** |
| Riepilogo per contratto e Dettaglio per commessa (DGB) | periodo, incaricati, cliente, contratto | **tutti** |

Esempio (giugno 2026, Modalità = Smart working): KPI 86 interventi / 23 commesse, mentre costi, giorni e DGB
restavano sui valori dell'intero periodo.

## 3. Logica — perimetro unico
- `ItServiceModel::where()` resta l'unica definizione dei filtri. `perimetro()` calcola una volta per richiesta
  l'insieme dei rapportini (`report_id`) che la soddisfano (tabella temporanea MEMORY, fallback a sottoquery)
  e le sezioni su altre viste filtrano `report_id IN (perimetro)`.
- Sezioni DGB: periodo, incaricati, cliente, contratto e stato commessa applicati direttamente; le dimensioni
  esistenti solo sui rapportini (linea, codice linea, settore, azienda, modalità, fascia, durata, sede, natura, ricerca)
  tramite il rapportino collegato all'attività (`cm_intervention_reports.dgb_activity_id`).
- Giorni lavorati: senza filtro di stato restano le sole commesse attive (definizione storica, con riconciliazione);
  con il filtro lo stato scelto sostituisce quel vincolo e la riconciliazione non si applica.
- Riepilogo per contratto: le ore extra in reperibilità erano contate sia in «Straordinario» sia in «Reperibilità»
  (colonne con somma maggiore delle ore). Ora lo straordinario è solo fuori reperibilità e non supera le ore della riga.

## Verifica (giugno 2026)
| Filtro | Interventi KPI | Commesse KPI | Contratti DGB |
|---|---|---|---|
| nessuno | 2.484 | 202 | 203 |
| Aperta | 2.141 | 175 | 176 |
| Chiusa | 257 | 24 | 24 |
| Sospesa | 86 | 3 | 3 |
| Non chiusa | 2.227 | 178 | 179 |
| Smart working | 86 | 23 | 23 |

Aperta + Chiusa + Sospesa = totale; Non chiusa = Aperta + Sospesa. Costi con Smart working = verifica SQL indipendente (139,00 h).

## Nota di sequenza
Basata su `main` (v1.9.81); file non toccati dalle branch 1.9.82–1.9.86. Conflitto solo su `VERSION`.
