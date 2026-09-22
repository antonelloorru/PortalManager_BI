# Release Checklist — PortalManager v1.8.46

Policy **zero-omission**: ogni voce verificata con evidenza.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.46` |
| `sync_commesse.php` | ROOT, **nuovo** | OK |
| `app/SyncDatasets.php` | **nuovo** | OK |
| `app/DatasetSync.php` | **nuovo** | OK |
| `app/MenuManager.php` | modificato | OK |
| `app/Router.php` | modificato | OK |
| `app/Version.php` | modificato | OK |
| `import_commesse_db.php` | invariato da v1.8.45 | OK |
| `import_employees_xlsx.php` | invariato da v1.8.43 | OK |
| `app/SourceDb.php`, `CommesseSync.php` | invariati da v1.8.45 | OK |
| `app/ListFilter.php` | invariato da v1.8.44 | OK |
| `app/EmployeeImportSchema.php` | invariato da v1.8.43 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] Pacchetto cumulativo e auto-consistente
- [x] Dipendenze dichiarate nel deployment
- [x] `sync_commesse` aggiunta a `Router::PAGES` e al menu
- [x] Il form GET della pagina non esiste, quindi non serve `route_slug_field()`
      (regola v1.8.42 rispettata: i link usano `url_safe()`)
- [x] ZIP forward-slash; ZIP precedente rimosso

## 2. Versionamento coeso

- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.46**, verificato su `pm_v2`

## 3. QA — validazione delle query contro lo schema reale

Dump `dump-sp-wetechs-202608101002.sql` caricato (102 tabelle), query eseguite con
`LIMIT 0` per validare sintassi, tabelle e colonne.

| Dataset | Query | Mappatura | Campi destinazione |
|---|---|---|---|
| commesse | valida, 30 colonne | allineata | 0 inesistenti |
| rapporti | valida, 26 colonne | allineata | 0 inesistenti |
| professionisti | valida, 8 colonne | allineata | 0 inesistenti |

**Difetti intercettati e corretti prima del rilascio:**
- [x] `ao.planned_quantity` inesistente → `a.planned_hours`
- [x] `cm_professionals.band_raw` inesistente → fascia conservata in `notes`

## 4. QA — sincronizzazione end-to-end

Gestionale popolato sullo schema reale: 3 contratti (1 eliminato), 2 attività
(1 non approvata), 3 allocazioni, 2 operatori. Destinazione: dump reale
`portalmanager_db.sql` con 778 segnaposto DGB.

| Verifica | Esito |
|---|---|
| Commesse: lette / nuove / segnaposto assorbiti | 2 / 2 / **2** |
| Rapporti: lette / nuove / **agganciate a commessa** | 2 / 2 / **2** |
| Professionisti: lette / nuove | 2 / 2 |
| Campi commessa verificati | 21, **discordanti 0** |
| Commessa eliminata esclusa | **SI** |
| `CLOSED` tradotto in `CHIUSA` | **SI** |
| Cliente creato in anagrafica | **SI** |
| Segnaposto `DGB-77` assorbito | **SI** |
| Attività non approvata esclusa | **SI** |
| Codice rapporto con più tecnici | `MAMT_23_000790/21` e `/20`, distinti |
| Chiave `dgb_source_id` valorizzata | 400, 401 |

## 5. QA — equivalenza fra le due origini

Due portali gemelli, uno sincronizzato dalla connessione diretta e uno dal CSV
esportato con le stesse query.

| Dataset | Righe A | Righe B | Contenuto identico |
|---|---|---|---|
| commesse | 778 | 778 | **SI** |
| rapporti | 2 | 2 | **SI** |
| professionisti | 248 | 248 | **SI** |

- [x] Intestazioni CSV riconosciute: 30/30, 26/26, 8/8 — **0 ignorate**
- [x] Il confronto è campo per campo su tutti i campi mappati

## 6. QA — idempotenza e anteprima

| Verifica | Esito |
|---|---|
| Commesse: 2ª esecuzione | 0 nuove, 2 aggiornate — totale 778 → 778 |
| Rapporti: 2ª esecuzione | 0 nuove, 2 aggiornate — totale 2 → 2 |
| Professionisti: 2ª esecuzione | 0 nuove, 2 aggiornate — totale 248 → 248 |
| Anteprima: righe mostrate | 2 |
| Anteprima: nessuna scrittura | **SI** |

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_v1` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_v1` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_v1` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_v2` fresco | 325 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_v2` | 325 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_v2` | 325 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Permessi propagati da `import_commesse_db.php`, verificati su `pm_v2`
- [x] Conteggio statement consolidato: **320 → 325**

## 8. Sicurezza

- [x] Sicurezza della connessione ereditata da `SourceDb` (v1.8.45): password
      cifrata AES-256-GCM, sessione read-only, sole `SELECT`, identificatori validati
- [x] Le query dei dataset sono costanti nel codice, mai costruite da input utente
- [x] Permessi derivati da `import_commesse_db.php`: nessun accesso allargato
- [x] CSRF su tutti i form; azioni riservate a chi ha il permesso di creazione
- [x] Endpoint del tracciato CSV: buffer svuotati e `zlib` disattivato
- [x] Ogni operazione registrata nell'event log

## 9. Robustezza

- [x] Rilevamento automatico del delimitatore CSV (`;`, `,`, `|`, tabulazione) e del BOM
- [x] Colonne non riconosciute ignorate e segnalate, non bloccanti
- [x] Date accettate in formato italiano e ISO; decimali con virgola o punto
- [x] Stati accettati sia in inglese sia già tradotti
- [x] Celle vuote non sovrascrivono valori esistenti
- [x] Commit ogni 500 righe
- [x] Anteprima e sincronizzazione usano la **stessa** funzione di scrittura

## 10. Documentazione

- [x] `CHANGELOG.md` — con la correzione rispetto alla v1.8.45 dichiarata apertamente
- [x] `TECHNICAL_DESIGN_v1_8_46.md` — fonte unica, schema reale, granularità,
      conversioni, filtri, aggancio
- [x] `DEPLOYMENT_v1_8_46.md` — percorsi, tabelle da rendere leggibili, ordine
      consigliato, diagnostica
- [x] `MANUALE_ADMIN_v1_8_46.md`, `MANUALE_UTENTE_v1_8_46.md`
- [x] `RELEASE_CHECKLIST_v1_8_46.md` — questo documento

## 11. Note e limiti dichiarati

- Il dump fornito è **schema-only**: la validazione ha potuto verificare struttura
  e colonne, non il comportamento su volumi reali. I dati di prova sono stati
  costruiti sullo schema vero.
- I dataset `ticket` ed `eventi_ticket` degli exporter **non** sono stati inclusi:
  il portale non ha tabelle di destinazione per l'helpdesk. Aggiungerli richiede
  prima di decidere se e come modellare i ticket, che è una scelta funzionale.
- La sincronizzazione resta **manuale**, come in v1.8.45. L'esecuzione pianificata
  va concordata a parte perché comporta una superficie di accesso non presidiata
  da sessione utente.
