# Release Checklist — PortalManager v1.8.50

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 e v1.8.49.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.50` |
| `app/SyncDatasets.php` | **corretto** (grana, codici) | OK |
| `app/DgbModel.php` | **corretto** (filtri) | OK |
| `app/MenuManager.php` | **riordinato** | OK |
| `app/SourceDb.php`, `DatasetSync.php`, `Router.php`, `Version.php` | da v1.8.49 | OK |
| 5 file ROOT | da v1.8.49 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.50**, verificato su `pm_c50`

## 2. Diagnosi riprodotta prima di intervenire

| Segnalazione | Riproduzione |
|---|---|
| L'import duplica le ore | **8 ore → 16 ore** in laboratorio |
| Codici non di riferimento | suffisso `/<id operatore>` da v1.8.46 |
| I filtri non funzionano | KPI 328.629 → 48.500 ore, elenco invariato a 70.238 righe |

- [x] Esposizione misurata: **67.723 rapporti su 67.723** con `dgb_source_id` NULL,
      tutti sarebbero stati reinseriti alla prima sincronizzazione
- [x] Causa individuata: due chiavi naturali concorrenti, tre canali che
      deduplicano su chiavi diverse, grana non dichiarata

## 3. QA — la grana regge da ogni canale

Scenario: 1.800 righe di cui 300 duplicate artificialmente, 7.643 ore gonfiate.

| Verifica | Esito |
|---|---|
| Dopo la migration | **1.500 righe, 6.856,50 ore** |
| `grane_distinte` = `righe_totali` | 1.500 = 1.500 |
| `senza_grana` | **0** |
| `duplicati` | **0** |
| `codici_con_suffisso` | **0** |

Reinserimento della stessa prestazione da ciascun canale:

| Canale | Esito |
|---|---|
| import XLSX (`ON DUPLICATE` su `source_uid`) | righe e ore **invariate** |
| DgbSync (`ON DUPLICATE` su `source_uid`) | righe e ore **invariate** |
| DatasetSync (`SELECT` su `source_uid`) | righe e ore **invariate** |

## 4. QA — due tecnici sulla stessa attività

| Verifica | Esito |
|---|---|
| Due prestazioni con lo stesso `report_code` coesistono | **SI** (2 righe) |
| Il codice resta identico alla sorgente | **SI** |
| Reinserire una delle due | **respinto** dal vincolo |

È il caso che il vecchio `uq_report_code` vietava: un vincolo che impediva dati
validi.

## 5. QA — additività delle misure

| Verifica | Esito |
|---|---|
| Vista vs tabella: righe e ore | 1.502 / 6.863,50 in entrambe — **nessun fan-out** |
| Aggregando per mese | 6.863,50 ore **OK** |
| Aggregando per commessa | 6.863,50 ore **OK** |

- [x] La vista non espone percentuali: non sono additive e vanno ricalcolate a
      ogni livello di aggregazione

## 6. QA — filtri

| Verifica | Prima | Dopo |
|---|---|---|
| `whereActivities`: criteri applicati | 8 | **10** |
| Filtro "da remoto" sull'elenco | 70.238 righe (invariato) | **17.546 righe** |
| Coerenza con i KPI | assente | ripristinata |

- [x] Verifica estesa a tutte le pagine del modulo: nomi dei campi corrispondenti
      ai parametri letti in `manage_projects`, `professionals`, `timesheet`,
      `project_gantt`, `workload_overview`, `project_dashboard`
- [x] `EXISTS` invece di `JOIN`: gli attributi stanno sull'allocazione, un join
      avrebbe moltiplicato le righe dell'elenco

## 7. QA — menu

- [x] 15 voci, **tutte con chiave `page` valida** (nessuna scartata dal
      normalizzatore)
- [x] Ordine per flusso analitico: anagrafiche → acquisizione → analisi
- [x] Nessuna voce rimossa rispetto alla v1.8.49
- [x] Separatori valutati e **scartati**: `normalizeConfig()` accede a
      `$it['page']` senza controllo e li scarterebbe dopo un warning; il renderer
      è in `header.php`, fuori dal pacchetto e non testabile qui

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_g50` | 21 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_g50` | 21 stmt, **err=0** |
| Migration RUN3 | tokenizer reale | `pm_g50` | 21 stmt, **err=0** |
| Migration RUN4 | splitter naive | `pm_g50` | 21 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c50` fresco (132 tabelle) | 364 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c50` | 364 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c50` | 364 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **345 → 364**

## 9. Difetto d'ordine intercettato in collaudo

- [x] La prima stesura ripuliva `report_code` **prima** di rimuovere
      `uq_report_code`, e falliva con *Duplicate entry*. La sequenza è stata
      riordinata; l'errore è documentato nel Technical Design perché è la prova
      che il vecchio vincolo era incompatibile con la grana corretta.

## 10. Sicurezza e reversibilità

- [x] Migration **distruttiva sui dati**: elimina duplicati e modifica codici.
      Il backup è indicato come prerequisito non negoziabile nel deployment
- [x] Fornita la query per ispezionare i duplicati **prima** di aggiornare
- [x] Fornito il criterio di accettazione: le ore possono diminuire, mai aumentare
- [x] La tabella d'appoggio della deduplica è creata e distrutta nella migration
- [x] Nessuna modifica ai permessi né alle logiche di autorizzazione

## 11. Documentazione

- [x] `CHANGELOG.md` — le tre segnalazioni, riproduzione, causa comune
- [x] `TECHNICAL_DESIGN_v1_8_50.md` — grana, scelta del vincolo, ordine delle
      operazioni, additività, fan-out, coerenza dei filtri
- [x] `DEPLOYMENT_v1_8_50.md` — backup, totali da annotare, criterio di verifica
- [x] `MANUALE_ADMIN_v1_8_50.md`, `MANUALE_UTENTE_v1_8_50.md`
- [x] `RELEASE_CHECKLIST_v1_8_50.md` — questo documento

## 12. Aperto, dichiarato

- I duplicati eliminati non sono ricostruibili senza backup: la migration è
  irreversibile per la parte dati.
- `import_intervention_reports.php` e `DgbSync.php` **non** sono nel pacchetto:
  il vincolo su `source_uid` li protegge dal duplicare, ma continuano a
  deduplicare sulle vecchie chiavi e riceveranno una violazione di integrità
  invece di aggiornare. Allinearli a `source_uid` è il passo successivo — va
  fatto, ma richiede di rivedere il loro flusso di errore e merita una release
  dedicata anziché una modifica affrettata qui.
