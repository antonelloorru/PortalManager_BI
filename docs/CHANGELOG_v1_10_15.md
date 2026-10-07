# CHANGELOG — v1.10.15 (2026-10-07)

Software 1.10.15 · Schema 1.10.15 · Upgrade `sql/migration_v1_10_15.sql` (pacchetto cumulativo da 1.10.06)

## Report Direzionale — schede per tipologia di commessa
Nuove schede accanto a «Portafoglio». Ognuna ha KPI, grafici, una tabella, la stampa e l'export **DOCX · XLSX · CSV · PDF**. Tutte usano solo il filtro principale: Commerciale, Cliente, Ricerca, Perimetro, Stato, Linee, Aziende, Contratto, Da–A.

| Scheda | Contenuto |
|---|---|
| **ACM** | Per ogni commessa: commerciale, cliente, codice commessa, descrizione e date dal/al.<br>Venduto, consumato a listino, residuo, giornate a listino, tariffa media €/gg, giornate vendute al listino, ore, ore non valorizzate.<br>**Consumo %**, **Performance %** ed **Esito** (In-Line / Over-performance / Under-performance / Non valutabile).<br>Filtri dedicati: Esito e Tolleranza In-Line. |
| **WTS-CSS** | Colonne base + per anno: moduli, ore, giorni uomo, giornate equivalenti, valore addebitato al cliente, valore a listino |
| **WTS-CC** | Come WTS-CSS + ordini cliente dell'anno, **fatturato effettivo** e «Fatture registrate» (Sì/No con il numero) |
| **WTS-MEG** | Colonne base + valore di vendita, costo del lavoro, costo totale sostenuto, margine e margine %, per commessa e in totale |
| **NV_** | Per sottotipologia (NV_AI, NV_SC, NV_GS …): commesse, persone, moduli, ore, giorni uomo, **incidenza % sulle ore totali dei servizi erogati** e quota % su NV_ |
| **Moduli di intervento** | Per tecnico e per fascia: numero di moduli, ore, giorni lavorati, giornate equivalenti, commesse. Grafico ore per tecnico divise per fascia. |

## Regole di calcolo ACM
- Consumato a listino = Σ ore × tariffa oraria della fascia nel listino della commessa (come nella Relazione di Servizio IT). È cumulato fino alla data «A», o fino a oggi.
- Consumo % = consumato / venduto × 100. Performance % = 100 − Consumo %.
- Esito:
  - **In-Line** se il consumo è 100% ± tolleranza (predefinita 5%, impostazione `dir.acm_tolleranza_pct`);
  - **Over-performance** se il consumo è inferiore;
  - **Under-performance** se è superiore;
  - **Non valutabile** senza venduto o senza consumo a listino.

## Tecnico
- `app/DirTipologie.php`: query per scheda e un solo costruttore del report per tutti i formati.
- `PmReport::toHtml()`: vista a schermo e pagina di stampa con lo stesso contenuto dei file.
- `DirModel::whereSql()` / `viewCommessa()`: il filtro principale è riusato senza duplicarlo.
- La scheda Portafoglio calcola i propri dati solo quando è aperta.
