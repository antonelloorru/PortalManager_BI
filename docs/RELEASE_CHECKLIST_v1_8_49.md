# Release Checklist — PortalManager v1.8.49

Policy **zero-omission**: ogni voce verificata con evidenza.
Pacchetto **cumulativo**: comprende la v1.8.48.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.49` |
| `sync_commesse.php` | ROOT, esteso | OK |
| `tech_registry.php`, `tech_units.php` | ROOT, da v1.8.48 | OK |
| `project_dashboard.php`, `dgb_activities.php` | ROOT, da v1.8.48 | OK |
| `app/SourceDb.php` | esteso | OK |
| `app/SyncDatasets.php` | esteso | OK |
| `app/MenuManager.php`, `app/Router.php` | **corretti** | OK |
| `app/DatasetSync.php`, `app/DgbModel.php`, `app/Version.php` | — | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso dagli output
- [x] File completi e già patchati

## 2. Versionamento coeso

- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.49**, verificato su `pm_c48`

## 3. Regressione corretta

- [x] Individuato che la v1.8.48 partiva da `MenuManager.php` e `Router.php`
      anteriori alla v1.8.46, e avrebbe **rimosso** la voce "Sincronizzazione
      gestionale" con la relativa rotta
- [x] I file di questa release ripartono dalla v1.8.46
- [x] Verificato che tutte e quattro le voci convivano:

| Pagina | routabile | in menu |
|---|---|---|
| `sync_commesse` | sì | sì |
| `tech_registry` | sì | sì |
| `tech_units` | sì | sì |
| `dgb_activities` | sì | **sì** (era assente) |

## 4. Coerenza menu / rotte

- [x] Confronto sistematico fra `Router::PAGES` e le voci di menu
- [x] 92 pagine routabili, 76 con voce di menu
- [x] Le 16 senza voce sono legittime: autenticazione (4), pagine di dettaglio
      raggiunte da link (5), sotto-pagine di flusso (7)
- [x] Controllo inverso: 10 voci di menu senza rotta, tutte in
      `Router::RESTRICTED`, corrette per progetto

## 5. QA — analisi completa della sorgente

Eseguita su entrambi i dump forniti, con le funzioni del file di release.

| Verifica | dump 27/07 | dump 10/08 |
|---|---|---|
| Oggetti analizzati | **111** (102 tabelle, 9 viste) | 102 (0 viste) |
| Schema rilevato automaticamente | sì | sì |
| Tabelle usate dai dataset | 9 | 9 |
| Mancanti | **0** | **0** |
| Non utilizzate | 102 | 93 |

| Verifica | Esito |
|---|---|
| Differenze fra i due dump rilevate | **9 viste**, elencate |
| Tabella richiesta rimossa dall'inventario → segnalata fra le mancanti | **OK** |
| Inventario eseguito rispettando il vincolo di sola lettura | **OK** |
| Tabelle estratte dalle query dei dataset | 9, corrispondenti a quelle attese |

- [x] L'inventario legge da `information_schema`: è una `SELECT`, quindi supera
      il controllo di sola lettura di `SourceDb::query()`
- [x] Le tabelle richieste sono **estratte dalle query**, non dichiarate: una
      dichiarazione separata divergerebbe, ed è la divergenza che si vuole rilevare
- [x] Varianti di dialetto previste per PostgreSQL e SQL Server

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_lite` | 6 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_lite` | 6 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_lite` | 6 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c48` | 345 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c48` | 345 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c48` | 345 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Permessi `dgb_activities.php` propagati: 5 righe
- [x] Conteggio statement consolidato: **342 → 345**

## 7. Sicurezza

- [x] L'inventario non legge dati applicativi, solo cataloghi di sistema
- [x] Il nome dello schema passa come **parametro** di prepared statement, non
      interpolato
- [x] Nessuna deroga al vincolo di sola lettura: la query dell'inventario è una
      `SELECT` e supera il controllo esistente senza eccezioni
- [x] Permessi della pagina DGB derivati da Import Commesse DB: nessun accesso
      allargato
- [x] CSRF sul form di analisi; esito registrato nell'event log

## 8. Documentazione

- [x] `CHANGELOG.md`, `TECHNICAL_DESIGN_v1_8_49.md`, `DEPLOYMENT_v1_8_49.md`,
      `MANUALE_ADMIN_v1_8_49.md`, `MANUALE_UTENTE_v1_8_49.md`, questa checklist
- [x] Nel deployment è evidenziato che `MenuManager.php` e `Router.php` vanno
      presi da **questo** pacchetto e non dalla v1.8.48

## 9. Aperto, dichiarato

Le nove viste della sorgente non sono ancora usate da alcun dataset.
`v_contract_export_list` (13 colonne) è vicina al tracciato commesse già in uso e
`v_contract_report_rows` (49 colonne) è la più ricca; valutarne l'adozione
richiede però di decidere se preferirle alle query con join attuali, che sono
verificate e funzionanti. È una scelta da concordare, non un residuo da chiudere
in fretta.
