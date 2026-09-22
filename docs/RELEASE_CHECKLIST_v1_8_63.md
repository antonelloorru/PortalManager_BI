# Release Checklist — PortalManager v1.8.63

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.62.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.63` |
| `import_commesse_db.php` | ROOT, **sfoltito** | OK |
| `sync_commesse.php` | ROOT, riferimenti aggiornati | OK |
| `app/SyncDatasets.php` | **modificato** (11 dataset) | OK |
| `app/MenuManager.php`, `Router.php` | verificati | OK |
| `app/Version.php` | modificato | OK |
| 4 ROOT + 4 in `app/` | invariati da v1.8.62 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] **`app/CommesseSync.php` rimosso dal pacchetto**; nessun file lo richiede
- [x] Verificato: 0 riferimenti residui a `CommesseSync` nel codice
- [x] La pagina `import_commesse_db` resta in menu e rotte come *Connessione al
      gestionale*: i parametri servono comunque alla sincronizzazione
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.63**

## 2. Dismissione del vecchio flusso

- [x] Rimosse le azioni di import da tabella singola (`sync_db`, `preview_db`,
      `import`, `preview`)
- [x] La pagina conserva **configurazione e verifica**, che sono l'unica parte
      con ragione di esistere separatamente
- [x] Motivazione documentata: due meccanismi paralleli per diciotto release
      avevano già prodotto il difetto corretto in v1.8.62

## 3. QA — gli 11 dataset sul dump reale

| Dataset | Colonne | Righe | Esito |
|---|---|---|---|
| Divisioni aziendali | 3 | 8 | ok |
| Commesse / Progetti | 30 | 1.494 | ok |
| Costi orari delle fasce | 7 | 10 | ok |
| Full cost per operatore | 5 | 256 | ok |
| Anagrafica professionisti | 8 | **256** | ok |
| Tariffe di contratto | 6 | 24.305 | ok |
| Allocazioni pianificate | 8 | 69.326 | ok |
| Operazioni economiche | 15 | 2.628 | ok |
| Tipi di anomalia | 7 | 8 | ok |
| Anomalie di commessa | 10 | 279 | ok |
| Rapporti di intervento | 27 | — | ok |

- [x] **Dataset con problemi: 0 su 11**
- [x] Tabelle sorgente: **17**

## 4. Difetto corretto in collaudo

- [x] `professionisti` restituiva **512 righe** per 256 operatori: mancava il
      `DISTINCT` su `dgb_operator`
- [x] Il `DISTINCT` era stato aggiunto in v1.8.60 al dataset dei **full cost**,
      che legge la stessa tabella, ma non a questo
- [x] Il vincolo UNIQUE lo mascherava: nessun duplicato in uscita, ma doppio
      lavoro e conteggio di righe lette non veritiero
- [x] Corretto: **512 → 256**
- [x] Regola derivata: quando due dataset leggono la stessa tabella, un difetto
      della sorgente li riguarda **entrambi**

## 5. QA — anomalie del gestionale

| Regola | Gravità | Segnalazioni | Aperte |
|---|---|---|---|
| RNS_10 tariffa fuori standard | BLOCK | 57 | 41 |
| MUT_10 margine ≤10% | BLOCK | 56 | 34 |
| INV_3 fatturazione +3 gg | ALARM | 51 | 38 |
| RUT_25 residuo ≤25% | ALARM | 36 | 27 |
| RUT_0 residuo esaurito | BLOCK | 32 | 28 |
| INV_10 fatturazione +10 gg | BLOCK | 31 | 24 |
| RUT_10 residuo ≤10% | ALARM | 9 | 8 |
| MUT_5 margine ≤5% | BLOCK | 7 | 3 |

- [x] Le anomalie sono **importate, non ricalcolate**: le soglie sono decisioni
      aziendali e il gestionale conosce dati che il portale non ha
- [x] `resolved_at` distingue aperte da chiuse: 203 su 279 ancora aperte

## 6. QA — convergenza dei due sistemi

| Convergenza | Commesse |
|---|---|
| solo gestionale | 137 |
| **confermata da entrambi** | **17** |
| solo portale | 12 |
| nessuna allerta | 63 |

- [x] Le 17 confermate sono la priorità di intervento
- [x] Le divergenze sono informative, non rumore: i due sistemi misurano
      grandezze complementari

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c63` fresco | 448 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c63` | 448 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c63` | 448 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Un riferimento errato (`r.saldo_finale` invece di `sa.saldo_finale`)
      intercettato al primo RUN e corretto
- [x] Conteggio statement consolidato: **441 → 448**

## 8. Aperto

- **Il collegamento fra divisione e tecnico non è stabilito.** Le divisioni sono
  importate ma nessuna tabella le lega alle persone: è il passo che completerebbe
  l'analisi per struttura, e richiede di capire dove il gestionale tenga
  quell'associazione.
- Le **137 commesse segnalate solo dal gestionale** e le **12 solo dal portale**
  non sono state analizzate: la divergenza fra due sistemi è quasi sempre
  informativa e merita un esame.
- `forms_activity_planning` (441 righe, pianificazione ricorrente con modalità
  remota, smart working e reperibilità pianificata) resta non importata. È la
  candidata più promettente per la prossima estensione.
