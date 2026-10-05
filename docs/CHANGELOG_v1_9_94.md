# CHANGELOG — v1.9.94

Report direzionale (`dir_report.php`). Tre interventi separati.

## A. Filtri di interfaccia
- Nuovo gruppo **Periodo**: **Data Inizio** / **Data Fine** (`from`, `to`, formato data; invertite se Da > A).
- Effetto sul perimetro (`DirModel::where`, anche `attenzione`): commesse la cui durata interseca il periodo
  (`end_date >= Data Inizio` e `start_date <= Data Fine`).
- Andamento: i mesi del periodo invece degli ultimi 12.
- Competenza: periodo di calcolo (senza date: intera durata delle commesse).
- Filtro mantenuto in link, badge filtri attivi, stampa (riga Filtri) ed export (foglio Filtri).

## B. Formattazione visiva
- Suffissi espliciti: **€** su importi (KPI, agenti, da presidiare, grafici a barre, competenza a 2 decimali), **h** su ore
  (agenti, costo del lavoro, asse dell'andamento), **gg** sui giorni a scadenza (era «g»).
- **Fido**: badge «FIDO» (tooltip con importi su valore / su costi) in «Commesse da presidiare» e nella competenza; conteggio
  «con fido» nel KPI Commesse; colonne Fido / Fido su valore € / Fido su costi € nel foglio XLSX «Commesse».
  Fonte: `cm_projects.credit_on_value`, `credit_on_costs` (≠ 0).

## C. Logica economica — pro-rata temporis mensile per competenza
- Nuova classe pura `app/ProRata.php` e `DirModel::competenza()`.
- Importo: ordini cliente della commessa (`cm_project_operations` COR «Ordine cliente» e COV «Riporto da contratto precedente»,
  `revenue`, data `op_date`); commesse a ricavo senza ordini: valore della commessa con data = inizio.
- Durata: mesi di calendario da max(inizio commessa, data ordine) a fine commessa, estremi inclusi
  (`PERIOD_DIFF(fine, inizio) + 1`); ordine successivo alla fine → tutto nel mese dell'ordine.
- Quota mensile = importo / mesi; competenza = quota × mesi nel periodo, raggruppata per anno; residuo di arrotondamento
  sull'ultimo anno (somma sull'intera durata = importo esatto).
- Verifica sull'esempio: 100.000 € su 28 mesi 06/2024–09/2026 → 2024 = 7 mesi = 25.000,00 € · 2025 = 12 mesi = 42.857,14 € ·
  2026 = 9 mesi = 32.142,86 €.
- Pagina: sezione «Valore ordini per competenza» (totali per anno, tabella per commessa con € e mesi per anno, calcolo di ogni ordine
  apribile sul codice commessa). XLSX: fogli «Competenza per anno», «Competenza per commessa», «Competenza per ordine». Stampa: totali per anno.

Dati di test (perimetro predefinito, solo aperte): 450 commesse, 2023–2031; 2025 = 4.578.787,18 € · 2026 = 11.098.331,67 €.
