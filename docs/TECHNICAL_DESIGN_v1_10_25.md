# TECHNICAL DESIGN — v1.10.25 · Relazione Tecnici

## Componenti
| File | Ruolo |
|---|---|
| `tech_report.php` | Pagina: pannello filtri (pattern Relazione IT), schede, KPI, tabelle, drill-down AJAX, stampa ed export |
| `app/TechReport.php` | Dati per scheda, righe raggruppate (tecnico × linea + totale tecnico), report PmReport (CSV/XLSX/DOCX/PDF/HTML) |
| `app/ItServiceModel.php` | Nuovi filtri `tipologie`, `prov`; nuove letture `tecniciLinea`, `valorizzazione`, `rapportiTipologia`, `rapportiCommessa`, `rapportiModuli`, `contaModuli`, `valoriTipologie`; `giorniLavorabili` |
| Router / MenuManager / PermissionCatalog | Slug, voce di menu dopo «Relazione di Servizio IT», permessi |

## Perimetro
È lo stesso della Relazione di Servizio IT. `where()` lavora su `v_cm_it_servizio` (una riga per modulo, `report_id`) e `perimetro()` sulle viste dei giorni.
- Il rapportino si aggancia per id: `LEFT JOIN cm_intervention_reports ir ON ir.id = s.report_id`.
- La fascia di costo arriva da `LEFT JOIN cm_rate_bands rbx ON rbx.id = ir.band_id`, con ripiego su `ir.band_raw`.

## Metriche (scheda Tecnici)
| Colonna | Definizione |
|---|---|
| N. attività | `COUNT(DISTINCT s.report_id)` |
| N. ticket | `COUNT(DISTINCT NULLIF(TRIM(ir.ticket),''))` |
| GG lavorabili | `ItServiceModel::giorniLavorabili(from, to)`: lun–ven esclusi i festivi nazionali (1/1, 6/1, Pasquetta calcolata con l'algoritmo di Meeus, 25/4, 1/5, 2/6, 15/8, 1/11, 8/12, 25/12, 26/12) |
| GG uomo lavorati / Giornate-uomo | `COUNT(DISTINCT incaricato\|giorno)` |
| N. ore lavorate / Ore cons. | `SUM(s.ore)` |
| Ordinarie, Fuori orario, Reperib. | `oreClassi()`: la stessa regola di KPI, andamento e dettaglio della Relazione IT (PmOrario) |
| Extra dich. | `SUM(s.ore_extra)` |
| Presso cl., Remoto, Smart | numero di interventi con `modalita` = presso cliente, da remoto, smart working |
| Fascia di costo | `GROUP_CONCAT(DISTINCT COALESCE(rbx.band_name, ir.band_raw))` |

**Righe di totale.** Il totale del tecnico (`tecniciLinea()['tecnici']`) e il totale generale vengono da query separate e non sommano le righe per linea: un giorno lavorato su due linee conta una volta.

**Valorizzati e non valorizzati** (`valorizzazione()`):
- fonte: `v_cm_it_giorni_base` con il perimetro unico (`giorniQuery`);
- condizione: colonna `valorizzata`; nelle viste che non la espongono, `produzione_teorica IS NOT NULL` (`valExpr()`).

## Provenienza (scheda Rapporti)
`ItServiceModel::provSql()` classifica ogni modulo in base al campo `ticket` del rapportino:
- `commessa`: ticket vuoto, quindi modulo generato dalla commessa;
- `ticket`: il campo contiene un codice ticket (`REGEXP '[A-Za-z]{2,4}_[0-9]{6,}'`, ad esempio WTS_000000070 o WES_000000347);
- `testo`: il campo contiene un riferimento libero, ad esempio «Presidio».

Filtro `prov`: sottoquery correlata su `cm_intervention_reports` per `report_id`.
Filtro `tipologie`: `COALESCE(NULLIF(s.modello_contratto,''),'da_classificare') IN (...)`.

**Per commessa**:
- `project_id` = `MIN(cm_projects.id)` per `project_code`; serve per il link a `project_dashboard`;
- `denominazione` = descrizione, oppure nome della commessa.

**Drill-down**:
- vista: `?ajax=moduli&commessa=…` restituisce un frammento HTML con al massimo 300 moduli e il conteggio totale;
- export: `?rep=fmt&commessa=…` produce il report della sola commessa con l'elenco completo dei moduli.

## Stampa ed export
- `TechReport::build()` costruisce un `PmReport` orizzontale, con meta dei filtri, KPI, tabelle con totale e note di definizione.
- `print=1` restituisce `toHtml(true)`; `rep=csv|xlsx|docx|pdf` passa per `send()`.
- Ogni stampa ed export viene registrata in `write_log('TechReport', …)` con scheda, formato, periodo, commessa e dettaglio.

## Permessi
| Permesso | Effetto | Assegnazione iniziale |
|---|---|---|
| `tech_report.php` view / export | pagina, stampa, export | ruoli con view su `it_service.php` (export = il loro `can_export`) e Super Admin |
| `tech_report_economics.php` view | produzione teorica e valore addebitato | ruoli con view su `dir_report.php` e Super Admin |
| `project_dashboard.php` view | link della commessa | invariato |
