# Technical Design — PortalManager v1.8.40

## 1. Scopo del modulo

Il modulo **Gestione Commesse** governa il ciclo di vita economico delle commesse
aziendali. La v1.8.40 ne uniforma la rappresentazione: la scheda a video, l'export
e l'import condividono ora un **unico standard a 29 campi**, quello del file
`export_lista_commesse.xlsx` prodotto dal gestionale di origine.

Il principio adottato è **un solo vocabolario**: le stesse 29 etichette compaiono
nell'intestazione della tabella a video, negli header dell'export XLSX/CSV e nella
mappa di import. Ogni disallineamento fra queste tre superfici è, per definizione,
un difetto.

## 2. Relazioni fra le viste

```
                     ┌──────────────────────────┐
                     │      cm_projects         │
                     │  (29 campi standard +    │
                     │   campi di servizio)     │
                     └────────────┬─────────────┘
                                  │
              ┌───────────────────┼───────────────────┐
              │                   │                   │
   ProjectModel::listAll()   DgbModel::           Gantt::portfolio()
   (37 campi, 11 filtri)     commessaRollup()     Workload::matrix()
              │                   │                   │
   ┌──────────┴─────────┐         │          ┌────────┴────────┐
   │ manage_projects    │◄────────┘          │ project_gantt   │
   │  · tabella 29 col. │                    │ workload_overv. │
   │  · filtri estesi   │                    │  (indicatori    │
   │  · export XLSX/CSV │                    │   allineati)    │
   └────────┬───────────┘                    └─────────────────┘
            │
            │ apre
            ▼
   project_dashboard.php (scheda commessa: team, rapporti, redditività, Gantt)
```

`import_commesse.php` scrive su `cm_projects` usando la stessa mappa header→colonna,
chiudendo il ciclo: **export → modifica esterna → import** è un round-trip senza
perdita di campi.

## 3. Schema ER (estratto rilevante)

```
companies ──1:N──┐
                 │ exec_company_id
clients ───1:N───┼──► cm_projects ──1:N──► cm_team ──► employees
                 │        │                          └─► cm_professionals
                 │        ├──1:N──► cm_intervention_reports
                 │        ├──1:N──► cm_presales
                 │        └──1:N──► cm_project_phases (Gantt)
                 │
      client_locations                dgb_contract_id ──► vw_dgb_* (rollup)
```

### `cm_projects` — colonne dello standard

| Gruppo | Colonne |
|---|---|
| Identificazione | `project_code` (UNIQUE), `name`, `abbr`, `external_link` |
| Classificazione | `service_line` ("tipo"), `project_type`, `commercial_ref` |
| Anagrafica | `client_id` → `clients`, `client_raw`, `exec_company_id` → `companies` |
| Descrittivi | `description`, `internal_description` |
| Stato | `operational_status`, `commercial_status`, `economic_status`, `economic_status_todate` |
| Compliance | `compliance_to_verify`, `compliance_preauth` |
| Anomalie | `anomalies_open`, `anomalies_blocking` |
| Temporali | `start_date`, `end_date`, `first_billing_date`, `billing_freq_months` |
| Economici | `value_total`, `value_todate`, `actual_cost`, `margin_total`, `margin_todate`, `residual_total`, `residual_todate`, `credit_on_value`, `credit_on_costs` |
| Interni | `material_costs`, `commercial_budget`, `loss_allocation`, `dgb_contract_id`, `import_batch_id` |

## 4. Logiche di calcolo

### 4.1 Doppia prospettiva "a oggi" / "totale"

Sei grandezze economiche esistono in coppia: valore, margine e residuo, ciascuna
con variante `_todate` e variante totale. La semantica è:

- **totale** = valore contrattuale sull'intera durata della commessa;
- **a oggi** = quota maturata alla data corrente, ossia la parte di contratto già
  "consumata" dal calendario.

Le due coincidono a commessa conclusa e divergono durante l'esecuzione. Entrambe
sono **importate dal gestionale di origine**, non ricalcolate dal portale: il
portale è qui sistema di consultazione, non di calcolo. Per le commesse gestite
internamente il calcolo previsionale resta in `ProjectModel::profitability()`.

### 4.2 Stato economico

`economic_status` / `economic_status_todate` assumono i valori `OK`, `CRITICO`,
`SFORATO`. Il rendering applica un semaforo: verde, ambra, rosso. La colonna "a
oggi" precede quella totale nell'ordine standard, perché è l'indicatore operativo
su cui si interviene.

### 4.3 Indicatori di record

Prima della v1.8.40 ogni pagina contava a modo suo. Ora la formula è unica —
`<insieme corrente> / <totale anagrafica>` — con `<totale anagrafica>` sempre
`SELECT COUNT(*) FROM cm_projects`:

| Pagina | Numeratore |
|---|---|
| `manage_projects.php` | righe restituite da `listAll()` con i filtri attivi |
| `project_gantt.php` | commesse in Gantt (cap 200) |
| `workload_overview.php` | commesse distinte presenti nella heatmap, oppure 1 se è filtrata una singola commessa |

Il denominatore comune permette di leggere immediatamente quanta parte del
portafoglio si sta osservando.

## 5. Sicurezza

- Tutti i filtri passano da **prepared statement** con placeholder posizionali;
  nessuna concatenazione di input in SQL. L'unica interpolazione (`IN (...)` per il
  rollup DGB) usa una lista di interi già castata con `array_map('intval', ...)`.
- L'ordinamento non accetta input libero: `sort` è validato contro una whitelist e
  risolto tramite mappa a stringhe costanti.
- Output HTML sempre attraverso `h()`; il campo `link` è emesso in `href` con
  `rel="noopener"` e `target="_blank"`.
- Autorizzazioni verificate su `manage_projects.php` sia per la vista sia,
  separatamente, per l'export e la creazione.
- L'endpoint di export ripulisce i buffer e disattiva `zlib.output_compression`
  prima di emettere il binario (regola consolidata dalla v1.8.39).

## 6. Indici

Gli otto indici aggiunti servono i filtri introdotti in questa release:
`idx_proj_abbr`, `idx_proj_econstatus`, `idx_proj_econtoday`, `idx_proj_commref`,
`idx_proj_compl_verify`, `idx_proj_compl_preauth`, `idx_proj_anom_open`,
`idx_proj_anom_block`. Su un portafoglio di ~1.900 record l'impatto in scrittura è
trascurabile rispetto al guadagno sui filtri di elenco.
