# TECHNICAL DESIGN — Progetti PRJ e Analisi Gara & Dimensionamento

Stato: **confermato il 05/10/2026** (risposte in §7). Fase 2 rilasciata in **v1.9.99** (schema, seed, permessi).
Base: repository `antonelloorru/PortalManager_BI`, branch `feature/v1.9.99-prj` (VERSION 1.9.99).

---

## 0. Allineamenti rispetto alla specifica

| # | Specifica | Stato reale del repository | Decisione proposta |
|---|---|---|---|
| A0.1 | Prima release v1.9.82 (dopo 1.9.81) | VERSION = **1.9.98** (v1.9.82–v1.9.98 già rilasciate) | Serie PRJ da **v1.9.99** (fasi 2–6 → v1.9.99…v1.9.103) |
| A0.2 | `PM_VERSION` = 1.9.23 vs VERSION | Confermato: `app/Version.php:16` → `'1.9.23'`; `autoBumpIfNeeded()` confronta con questo valore, quindi non riallinea mai `app_settings` oltre 1.9.23 | In v1.9.99 `PM_VERSION` ← VERSION e da allora allineati a ogni release |
| A0.3 | Dump QA `Dump/Dump_19.80_DB.zip` | Presente (131,8 MB, 1 file `.sql`) | Usato per RUN1/RUN2 |
| A0.4 | FK verso tabelle esistenti `ON DELETE RESTRICT` | `CommesseSync` cancella i segnaposto `DGB-%` (`app/CommesseSync.php:174`). Con RESTRICT la sync fallirebbe se un PRJ puntasse a un segnaposto | `cm_prj.sp_project_id` → `ON DELETE SET NULL` + controllo orfani (§2.5). RESTRICT resta per `clients`, `companies`, `technologies`, `certifications`, `employees` |
| A0.5 | `tools/verify_v1_9_*.php` | Presenti v1.9.27–v1.9.30 | `tools/verify_v1_9_100.php` (fase motore di calcolo) |
| A0.6 | Prefisso `cm_prj` | Nessuna occorrenza nel repository | Confermato |

---

## 1. Entità e relazioni

```
clients ─┐            companies ─┐
         │ (0..1)               │ (0..1)
         ▼                      ▼
      cm_prj ───(0..1)── sp_project_id ──► cm_projects   (commessa SP, sola lettura)
        │  ▲                                    ▲
        │  └─ scenario_riferimento_id ─┐        │ storico
        │                              │   cm_prj_link_history
        ├─ cm_prj_presales             │
        ├─ cm_prj_source ◄─────────────┼── source_id (param, profili, KPI…)
        ├─ cm_prj_tender_base (anno)   │
        ├─ cm_prj_rate_card            │
        ├─ cm_prj_area ─► cm_prj_service ─┬─► cm_prj_service_technology ─► technologies
        │                                 ├─► cm_prj_service_profile ◄─┐
        │                                 ├─► cm_prj_service_kpi ◄─ cm_prj_kpi
        │                                 └─► cm_prj_asset_metric
        ├─ cm_prj_ticket_group ─► cm_prj_ticket_mapping (gruppo→servizio, quota)
        │        └─► cm_prj_ticket_volume (anno, tipo)
        ├─ cm_prj_aht · cm_prj_productivity
        ├─ cm_prj_profile ─┬─► cm_prj_profile_req
        │                  ├─► cm_prj_profile_cert ─► certifications
        │                  ├─► cm_prj_salary_band ─► cm_prj_zone
        │                  └─► cm_prj_profile_assignment ─► employees | cm_professionals
        ├─ cm_prj_criterion ─► cm_prj_criterion_input
        ├─ cm_prj_scenario ─► cm_prj_scenario_profile
        │        └─► cm_prj_calc_run (immutabile) ─► cm_prj_calc_result
        └─ cm_prj_actual (anno, mese)

Globali (prj_id NULL): cm_prj_param, cm_prj_zone, cm_prj_nearshore, cm_prj_equipment,
                       cm_prj_site_cost, cm_prj_overhead, cm_prj_source
cm_prj_sequence (year → last_no): generazione codice PRJ-AAAA-NNNN
```

Cardinalità: 1 commessa SP ↔ N PRJ; 1 PRJ ↔ 0..1 commessa SP alla volta; storico in `cm_prj_link_history`.
I PRJ non sono mai scritti in `cm_projects`.

---

## 2. Tabelle (DDL logico)

Convenzioni: InnoDB, `utf8mb4_unicode_ci`, `id INT AUTO_INCREMENT`. Ogni tabella figlia ha `prj_id` con FK verso `cm_prj` `ON DELETE CASCADE`: la cancellazione passa da RecycleBin e quella fisica solo dal cestino.

**(v)** = colonne di versionamento:

```
valid_from DATE NOT NULL, valid_to DATE NULL, version_no INT NOT NULL DEFAULT 1,
is_current TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL,
created_at DATETIME DEFAULT current_timestamp(), change_note VARCHAR(255) NULL,
KEY (… , is_current), KEY (… , valid_from, valid_to)
```

### 2.1 Testata e collegamento

| Tabella | Colonne principali | Chiavi |
|---|---|---|
| `cm_prj` | `prj_code` VARCHAR(20), `nome`, `client_id`, `client_raw`, `exec_company_id`, `project_type` ENUM (stessi valori di `cm_projects.project_type`), `stato` ENUM(Bozza, In analisi, Offerta presentata, Aggiudicato, Perso, Ritirato, In esecuzione, Chiuso), `responsabile_user_id`, `sp_project_id`, `codice_gara`, `cig`, `stazione_appaltante`, `start_date`, `end_date`, `data_offerta`, `data_aggiudicazione`, `scenario_riferimento_id`, `note`, `created_by`, `created_at`, `updated_at`, `deleted_at` | UNIQUE `prj_code`; KEY `sp_project_id`, `client_id`, `stato`; CHECK `prj_code REGEXP '^PRJ-[0-9]{4}-[0-9]{4}$'`; FK `sp_project_id` → `cm_projects` ON DELETE SET NULL (A0.4) |
| `cm_prj_gara` (v) | `prj_id`, `durata_mesi`, `phase_in_giorni`, `phase_in_retribuito`, `handover_giorni`, `rinnovo_mesi`, `source_id` | Campi di gara versionati, separati dalla testata (che contiene il codice immutabile) |
| `cm_prj_sequence` | `year` SMALLINT PK, `last_no` INT | `SELECT … FOR UPDATE` in transazione; nessun riuso del numero |
| `cm_prj_link_history` | `prj_id`, `sp_project_id` NULL, `sp_project_code` (snapshot), `azione` ENUM(collegato, scollegato, sostituito, orfano_da_sync), `motivo`, `user_id`, `created_at` | KEY (`prj_id`, `created_at`), KEY `sp_project_id` |
| `cm_prj_presales` | `prj_id`, `cost_center` (stesso ENUM di `cm_presales_effort`), `hours`, `hourly_rate`, `notes` | UNIQUE (`prj_id`, `cost_center`) |
| `cm_prj_source` | `prj_id` NULL, `tipo` ENUM(documento_gara, mercato, stima_interna), `codice` (03/01/06…), `titolo`, `riferimento` (§/pag./tab.), `url`, `data_riferimento` | KEY `prj_id` |

### 2.2 Gara, servizi, volumi

| Tabella | Colonne |
|---|---|
| `cm_prj_tender_base` (v) | `prj_id`, `year`, `canone_eur`, `uncommitted_eur`, `source_id` |
| `cm_prj_rate_card` (v) | `prj_id`, `profilo_tariffa`, `eur_giorno`, `source_id` |
| `cm_prj_area` | `prj_id`, `codice` (ITO/NOM/OFI/ATC), `nome` |
| `cm_prj_service` (v) | `prj_id`, `area_id`, `codice` (SER01…), `nome`, `modalita` ENUM(remoto, ibrido, in_sede), `max_interventi_sede_anno`, `fuori_orario_remoto`, `fuori_orario_sede`, `fuori_orario_note`, `h24`, `avvio_anno`, `source_id` |
| `cm_prj_service_technology` | `service_id`, `technology_id` (FK `technologies`, NULL ammesso) + `technology_raw`, `versioni`, `h24` |
| `cm_prj_asset_metric` (v) | `prj_id`, `service_id` NULL, `metrica`, `valore`, `unita`, `year`, `source_id` |
| `cm_prj_ticket_group` | `prj_id`, `area`, `nome` |
| `cm_prj_ticket_mapping` (v) | `group_id`, `service_id`, `quota` DECIMAL(5,4) (somma per gruppo = 1, validata) |
| `cm_prj_ticket_volume` (v) | `group_id`, `year`, `tipo` ENUM(CTASK, INC, SCTASK), `quantita` |
| `cm_prj_aht` (v) | `prj_id`, `tipo`, `service_id` NULL, `ore` |
| `cm_prj_productivity` (v) | `prj_id`, `ore_utili_fte`, `giorni_fte`, `uplift`, `banda_volumi` (0,20) |

### 2.3 Profili e costi

| Tabella | Colonne |
|---|---|
| `cm_prj_profile` | `prj_id`, `codice`, `nome`, `seniority`, `tipo` ENUM(obbligatorio, supporto, governance), `h24` (vedi Q4), `nearshore_ammesso` |
| `cm_prj_profile_req` (v) | `profile_id`, `anni_min`, `titolo_min`, `lingue`, `responsabilita`, `source_id` |
| `cm_prj_profile_cert` | `profile_id`, `certification_id` (FK `certifications`), `premiante` |
| `cm_prj_service_profile` (v) | `service_id` NULL (GOV), `profile_id`, `n_minimo`, `fte` |
| `cm_prj_salary_band` (v) | `profile_id`, `ral_min`, `ral_ideale`, `zona_base_id`, `source_id` |
| `cm_prj_profile_assignment` | `profile_id`, `employee_id` NULL, `professional_id` NULL, `pct_allocazione`, `dal`, `al` (CHECK: esattamente uno dei due id) |
| `cm_prj_param` (v) | `prj_id` NULL, `chiave`, `valore` DECIMAL(18,6), `unita`, `source_id` |
| `cm_prj_zone` (v) | `prj_id` NULL, `nome`, `indice_ral`, `affitto_mq_mese`, `source_id` |
| `cm_prj_nearshore` (v) | `prj_id` NULL, `paese`, `indice_ral`, `oneri_pct`, `source_id` |
| `cm_prj_equipment` (v) | `prj_id` NULL, `voce`, `prezzo`, `anni_ammortamento` |
| `cm_prj_site_cost` (v) | `prj_id` NULL, `voce`, `eur_mese` |
| `cm_prj_overhead` (v) | `prj_id` NULL, `voce`, `tipo` ENUM(fisso, per_fte), `importo` |

### 2.4 KPI, punteggio, scenari, consuntivo

| Tabella | Colonne |
|---|---|
| `cm_prj_kpi` (v) | `prj_id`, `codice` KPI_01…, `indicatore`, `livello_atteso`, `formula_penale` (FormulaEval), `unita_penale`, `source_id` |
| `cm_prj_service_kpi` | `service_id`, `kpi_id` |
| `cm_prj_criterion` (v) | `prj_id`, `codice` (A.1…I), `tipo` ENUM(Q, D, T, E), `punti_max`, `formula`, `flag_incongruenza`, `nota_incongruenza` |
| `cm_prj_criterion_input` | `criterion_id`, `scenario_id` NULL, `chiave`, `valore` |
| `cm_prj_scenario` | `prj_id`, `nome`, `tipo` ENUM(sostenibile, completo, custom), `zona_id`, `ral_mode` ENUM(min, ideale, media), `ribasso_pct`, `margine_target_pct`, `nearshore_id` NULL, `cloned_from_id`, `note` |
| `cm_prj_scenario_profile` | `scenario_id`, `profile_id`, `service_id`, `fte_override` NULL, `zona_id` NULL, `nearshore_id` NULL, `remoto` |
| `cm_prj_calc_run` | `prj_id`, `scenario_id`, `sp_project_id` (al momento del calcolo), `as_of` DATE, `params_hash` CHAR(64), `input_json` LONGTEXT, `app_version`, `schema_version`, `user_id`, `created_at`. Immutabile: nessun UPDATE o DELETE applicativo |
| `cm_prj_calc_result` | `run_id`, `ambito` ENUM(totale, servizio, profilo, anno, zona), `ambito_ref`, `metrica`, `valore` |
| `cm_prj_actual` | `prj_id`, `year`, `month`, `service_id` NULL, `metrica`, `valore`, `origine` ENUM(dgb, timesheet, report, manuale), `computed_at` |

**Lettura as-of** (helper `PrjRepo::asOf($table, $prjId, $date)`):

```
WHERE valid_from <= :d AND (valid_to IS NULL OR valid_to >= :d)
```

**Scrittura versionata** (in transazione):

1. `UPDATE … SET valid_to = :d - 1, is_current = 0` sulla versione vigente.
2. `INSERT` della nuova versione con `version_no + 1`.
3. `EntityChangeLog::diffAndLog()`.

### 2.5 Collegamento PRJ ↔ commessa SP

- **Selettore** (`api_prj.php?action=sp_search`): cerca in `cm_projects` per `project_code`, `name`, `client_raw`/`clients.name` e `commercial_ref`. Il filtro è `project_code NOT LIKE 'DGB-%'`, più il filtro "SP" (Q2).
- **Suggerimenti**, punteggio 0–100:

  | Criterio | Punti |
  |---|---|
  | Stesso `client_id` | 30 |
  | `commercial_ref` contiene `prj_code` o il CIG | 30 |
  | Similarità del nome (trigrammi in PHP) | ≤ 15 |
  | Sovrapposizione di date | ≤ 15 |
  | Stessa `exec_company_id` | 10 |

- **Auto-collegamento alla sincronizzazione**: hook post-sync in `CommesseSync`. Se `commercial_ref` contiene un `PRJ-AAAA-NNNN` esistente e il PRJ non è collegato, il collegamento viene creato con `azione=collegato` e `motivo='commercial_ref (sync)'`. Un PRJ già collegato non viene mai sovrascritto.
- **Orfani**: le FK SET NULL non scrivono lo storico. Lo stesso hook post-sync confronta l'ultimo `cm_prj_link_history` "collegato" con `sp_project_id IS NULL` e registra `orfano_da_sync` + `write_log('Commesse','warning',…)`.

---

## 3. Punti di aggancio nel codice esistente

| File | Modifica |
|---|---|
| `app/Router.php::PAGES` (riga 54) | + `prj_dashboard`, `prj_parameters`, `prj_history`. `api_prj.php` è ad accesso diretto, fuori da PAGES |
| `app/MenuManager.php` (gruppo `commesse`) | + `prj_parameters` dopo `manage_rate_bands`; + `prj_history` dopo `dir_report`. Il merge del MenuManager le rende visibili anche nei menu personalizzati |
| `manage_permissions.php` (gruppo 'Gestione Commesse', riga 147) | + `prj_dashboard.php` (↳ Scheda progetto PRJ), `prj_parameters.php`, `prj_history.php`, `manage_projects_prj.php` (permesso virtuale per `?view=prj`), `prj_link.php` (permesso virtuale per l'azione link), `prj_costs_real.php` (permesso virtuale per i costi reali dei dipendenti) |
| `manage_projects.php` | Schede «Commesse SP» / «Progetti PRJ» (`?view=prj`). Elenco commesse invariato, + colonna nascosta «Progetti PRJ collegati» (`COUNT` da `cm_prj`). Filtro unico server-side, senza barra automatica (pattern v1.9.93) |
| `project_dashboard.php` | + tab `data-tab="prj"` con badge (pattern DGB/Pratix, riga 455), elenco PRJ collegati, stimato vs consuntivo, «Collega progetto PRJ» |
| `app/CommesseSync.php` | Hook post-sync `PrjLink::afterSync()`, read-only su `cm_projects` (§2.5) |
| Nuovi: `app/PrjCalc.php`, `app/PrjRepo.php`, `app/PrjLink.php`, `prj_dashboard.php`, `prj_parameters.php`, `prj_history.php`, `api_prj.php`, `tools/verify_v1_9_100.php` | — |

Riuso: `FormulaEval` (formule penali, criterio A.1, PE), `EntityChangeLog`, `write_log()`, `RecycleBin`, `PmCharts`, `XlsxWriter`/`DocxWriter`, `ListFilter` + `saved_views_api.php`, `Workload::monthlyCapacity()`, `DgbModel::rollupForContract()`, `RateResolver`, `cm_employee_cost_year`, `cm_cost_year_params` (giorni/ore al posto di 220/1.600 quando valorizzati), `CostModel` (default `oneri_pct` = `moltiplicatore_fc` − 1 dell'anno, con il seed 0,40 come fallback).

---

## 4. Permessi

| Pagina | Azioni | Ruoli proposti (da confermare, Q5) |
|---|---|---|
| `manage_projects.php?view=prj` → `manage_projects_prj.php` | view, create, export | Super Admin, Resp. Commerciale, Direttore IT, Coordinatore Tecnico, Finance (view) |
| `prj_dashboard.php` | view, edit, calc, export | idem; edit/calc: Super Admin, Resp. Commerciale, Direttore IT |
| `prj_link.php` (virtuale) | link | Super Admin, Resp. Commerciale |
| `prj_parameters.php` | view, edit | Super Admin, Finance (edit); Resp. Commerciale (view) |
| `prj_history.php` | view, export | Super Admin, Resp. Commerciale, Direttore IT, Finance |
| `prj_costs_real.php` (virtuale) | view | Stesso controllo di `employee_compensation.php` |
| `api_prj.php` | — | Verifica `can()` sulla pagina chiamante per ogni action |

---

## 5. Motore `app/PrjCalc.php`

`final class PrjCalc`, `declare(strict_types=1)`, funzioni pure: input array → output array, nessun accesso al DB.
`PrjRepo` costruisce l'input as-of, `PrjCalc` calcola, `PrjRepo::saveRun()` scrive run e risultati.
Gli importi sono in € internamente ed esposti in k€ con 1 decimale.

```
ticketLoad(volumi, mapping, aht, prod)        → per servizio: ticket, ore, fte_ticket, fte_uplift, fte_alloc, gap
profileCost(band, zona|paese, ral_mode, oneri, h24, indennita, giorni_fte)
structural(equipment, zona, mq, occ, oneri_acc, kwh, prezzo_kwh, site, n_sedi, fte_uff, fte_rem)
overhead(voci, fte_tot)
scenario(profili, fte_override, supporto_sostenibile) → totali §5.5 della specifica
penalties(kpi, input_mese) · score(criteri, input) · conguaglio(volumi, banda, canone)
```

Formule: quelle della specifica, §5.1–5.7, senza variazioni. `FormulaEval` valuta penali, A.1 e PE (w, n1, n2 come parametri).

### 5.1 Verifica preliminare dei valori attesi (§9 della specifica) con i seed forniti

| Caso | Atteso | Ricalcolo | Esito |
|---|---|---|---|
| Ticket 2025 | 15.971 | 15.971 | ✔ |
| FTE obbligatori / totali | 26,5 / 55,1 | 26,5 / 55,1 | ✔ |
| Dotazione/FTE | 1.184 € | 1.184,01 € | ✔ |
| Strutturale/FTE ufficio Milano / Roma / Firenze / Napoli | 3.532 / 2.985 / 2.646 / 2.402 | 3.531,9 / 2.984,7 / 2.646,3 / 2.401,5 | ✔ |
| Canone medio | 2.351,9 k€ | 2.351,9 k€ | ✔ |
| Sostenibile Roma: RAL | 1.517 k€ | 1.517 k€ con `ral_mode = media` | ✔ |
| Sostenibile Roma: costo az. personale | 2.207 | **2.211** | Δ +4 |
| Sostenibile Roma: totale / % | 2.372 / 101% | **2.376** / 101% | Δ +4 |
| Sostenibile Firenze | 2.233 / 95% | 2.237 / 95% | Δ +4 |
| Sostenibile Napoli | 2.012 / 86% | 2.016 / 86% | Δ +4 |
| Completa Firenze | 3.389 / 144% | 3.393 / 144% | Δ +4 |
| Completa Milano | 4.014 / 171% | 4.018 / 171% | Δ +4 |
| Sostenibile Firenze + supporto Romania | 2.083 / 89% | 2.083 / 89% (supporto nearshore = remoto: solo dotazione; oneri 3%; indice 0,65) | ✔ |
| FTE da ticket | 34,7 | 34,74 (55.579,5 h / 1.600 h) con AHT CTASK 12 h, INC 3 h, SCTASK 1,5 h | ✔ |

Regole confermate (05/10/2026) e implementate nel seed v1.9.99:

- `ral_mode = media`;
- sostenibile = obbligatori + 6 FTE di supporto ripartiti in proporzione + governance (34,0 FTE);
- overhead incluso;
- costo strutturale: **remoto = solo dotazione per gli FTE marcati remoti nello scenario** (`cm_prj_scenario_profile.remoto = 1`) e per gli FTE in nearshore; gli altri FTE hanno il costo da ufficio della zona. Negli scenari seed nessun FTE è marcato remoto, quindi i valori attesi coincidono con il costo da ufficio;
- indennità H24 **solo sugli FTE che svolgono H24**: flag `cm_prj_profile.h24` (override per scenario `cm_prj_scenario_profile.h24`).

Lo scostamento di +4 k€ si annulla con 1,0 FTE obbligatorio fuori dall'H24. Nel seed il profilo senza H24 è **P12 «Sistemista Senior Telecomunicazioni» (SER06)**: è l'unico profilo obbligatorio da 1,0 FTE di un servizio H24 compatibile con i valori attesi, insieme a P10 (SER05) e P25 (SER12, ServiceNow). La scelta tra i tre non cambia nessun valore atteso; si modifica dal flag del profilo.

AHT (ore medie per ticket), stima interna confermata:

| Tipo | Ticket 2025 | % ticket | Ore/ticket | Ore | % ore |
|---|---|---|---|---|---|
| CTASK | 2.142 | 13% | 12 | 25.704 | 46% |
| INC | 6.088 | 38% | 3 | 18.264 | 33% |
| SCTASK | 7.741 | 48% | 1,5 | 11.611,5 | 21% |
| Totale | 15.971 | | | 55.579,5 | 34,7 FTE |

- `cm_prj_aht` ha `service_id` (0 = tutti i servizi): si possono definire ore medie per tipo **e per servizio**, che prevalgono sul valore generale del tipo.
- Sensibilità: ±1 h sul CTASK vale circa ±1,3 FTE. Con tempi bassi (SCTASK 1, INC 2, CTASK 8) il carico è 23,2 FTE, con tempi alti (2 / 4 / 16) circa 46 FTE.
- Sostituzione con dati reali: ore per ticket del fornitore uscente durante il Phase In [03 §3.8.1 p.109], oppure ore medie ricavate da rapporti di intervento, timesheet e attività DGB delle commesse di Managed Service in esecuzione, abbinate ai ticket del Service Desk (fase 6).

---

## 6. Piano di rilascio

| Release | Fase | Contenuto |
|---|---|---|
| — | 1 | Questo Technical Design → conferma |
| v1.9.99 | 2 | Migrazione di tutte le `cm_prj*` + seed globali + seed PRJ-2026-0001 (ASPI, non collegato) + Router + catalogo permessi + `PM_VERSION` allineato. Le voci di menu entrano con le pagine (v1.9.101 e v1.9.102), per non pubblicare link a pagine non ancora presenti |
| v1.9.100 | 3 | `PrjCalc`, `PrjRepo` + `tools/verify_v1_9_100.php` (test §9) |
| v1.9.101 | 4 | `?view=prj`, `prj_dashboard` (tab Anagrafica…Scenari), collegamento con storico e suggerimenti, `prj_parameters` |
| v1.9.102 | 5 | KPI & penali, Punteggio, `prj_history`, tab «Progetti PRJ» nella commessa, colonna PRJ nell'elenco commesse |
| v1.9.103 | 6 | Stimato vs Consuntivo, export XLSX/DOCX, manuali completi, checklist |

Ogni release contiene:

- ZIP completo con `update_manifest.json`;
- `sql/migration_v1_9_N.sql`, idempotente, con seed in una sezione separata;
- RUN1/RUN2 su Dump 19.80;
- `php -l` su tutti i file;
- documentazione: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE Admin/Utente, RELEASE_CHECKLIST.

---

## 7. Decisioni (05/10/2026)

| # | Punto | Decisione |
|---|---|---|
| Q1 | Numerazione | Da **v1.9.99** |
| Q2 | «SP» | È il **codice commessa** (`cm_projects.project_code`, colonna Codice di «Commesse / Progetti»). Il selettore cerca su codice, nome, cliente e `commercial_ref`, escludendo i segnaposto `DGB-%` |
| Q3 | AHT | CTASK 12 h, INC 3 h, SCTASK 1,5 h (generali), con possibilità di valori per servizio |
| Q4 | Indennità H24 | Solo gli FTE che svolgono H24: flag per profilo (seed: tutti i profili dei servizi H24 tranne P12) |
| Q5 | Permessi | Tabella §4, applicata nella migration v1.9.99 (modificabile da Gestione permessi) |
| Q6 | Strutturale remoto | Solo dotazione per gli FTE marcati remoti nello scenario e per i nearshore; ufficio per gli altri |
| Q7 | Collegamento | `ON DELETE SET NULL` + registro orfani |
