# Release Checklist — PortalManager v1.8.57

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.56.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.57` |
| `sync_commesse.php` | ROOT, **modificato** | OK |
| `app/SyncDatasets.php` | **modificato** (3 dataset + ordine) | OK |
| `app/Version.php` | modificato | OK |
| altri 5 ROOT + 6 in `app/` | invariati da v1.8.56 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.57**
- [x] La migration crea tabelle vuote e viste: nessun dato esistente toccato

## 2. Analisi del dump

| Verifica | Esito |
|---|---|
| Dimensione | 3,5 GB |
| Tabelle | **102**, di cui **63 popolate** |
| FOREIGN KEY dichiarate | **0** — relazioni desunte dalla nomenclatura |
| Relazioni ricostruite | **245** |
| Entità più referenziata | `dgb_installation` (71 tabelle) |
| Tabelle lette dalla sincronizzazione prima | 9 |
| Tabelle lette dopo | **12** |

## 3. QA — validità dei dataset sullo schema reale

Query eseguite con `LIMIT 0` sul dump caricato.

| Dataset | Colonne | Mappatura |
|---|---|---|
| commesse | 30 | allineata |
| rapporti | 27 | allineata |
| professionisti | 8 | allineata |
| **tariffe** | 6 | allineata |
| **allocazioni** | 8 | allineata |
| **costi_fascia** | 7 | allineata |

- [x] Dataset con problemi: **0**

## 4. QA — assenza di fan-out e univocità delle chiavi

| Dataset | Sorgente | id distinti | Dataset | Fan-out | Chiave duplicata |
|---|---|---|---|---|---|
| tariffe | 34.400 | 34.400 | 24.305 | no | **0 gruppi** |
| allocazioni | 199.458 | **99.729** | 69.326 | no | **0 gruppi** |
| costi_fascia | 10 | 10 | 10 | no | **0 gruppi** |

## 5. Difetti dei dati sorgente, intercettati e corretti

**Duplicati esatti nelle allocazioni**

- [x] `dgb_operator_allocations_on_forms_contract` **non ha PRIMARY KEY**
- [x] 199.458 righe per 99.729 `id` distinti: ogni riga ripetuta due volte con
      tutti i campi identici
- [x] Verificato **sul file del dump**: la tupla vi compare due volte, quindi è
      la sorgente e non il caricamento
- [x] Corretto con `SELECT DISTINCT`: 99.729 righe, chiave tornata univoca
- [x] Individuato perché il conteggio non tornava: 277.304 righe prodotte dove la
      sorgente ne aveva 199.458

**Fan-out sulle tariffe nella vista di marginalità**

- [x] Una commessa ha in media **6** tariffe orarie di ricavo, una per tipo
      attività
- [x] Il join diretto portava le righe della vista da 67.723 a **379.247**, con
      ogni somma gonfiata di cinque volte
- [x] Corretto aggregando per commessa prima del join
- [x] **Media** e non minimo o massimo: senza sapere il tipo di attività è la
      stima meno distorta
- [x] Esposta `tariffe_disponibili`: chi legge sa se la stima è esatta o media
- [x] Individuato perché la copertura risultava **108,2%** su un massimo del 100%

## 6. QA — quadratura della vista di marginalità

| Verifica | Esito |
|---|---|
| Righe base vs vista | 67.723 = **67.723** |
| Ore base vs vista | 338.403,50 = **338.403,50** |

| Origine del ricavo | Rapporti | Importo |
|---|---|---|
| rilevato | 22.086 | 8.724.544,69 |
| **stimato da tariffa** | **15.170** | 6.377.798,98 |
| non disponibile | 30.467 | — |

- [x] Copertura del ricavo: **32,6% → 55,0%**
- [x] La vista non sostituisce mai un valore rilevato con uno stimato
- [x] L'origine del valore è sempre esposta accanto al valore

## 7. QA — sincronizzazione in un'unica azione

- [x] Ordine: `commesse → costi_fascia → professionisti → tariffe → allocazioni → rapporti`
- [x] Le commesse precedono tutti i dataset che vi si agganciano: **verificato**
- [x] Tutti i dataset presenti nell'ordine: **6 / 6**
- [x] I dataset non elencati finiscono in coda: aggiungerne uno non richiede di
      toccare l'ordine
- [x] Connessione alla sorgente aperta **una volta sola**
- [x] Un dataset in errore **non ferma** gli altri, e l'esito è riportato per
      ciascuno con righe, tempi ed eventuale messaggio
- [x] L'anteprima dichiara di essere limitata a 200 righe per dataset

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c57` fresco (132 tabelle) | 407 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c57` | 407 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c57` | 407 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **400 → 407**

## 9. Documentazione

- [x] `CHANGELOG.md` — analisi del dump, tre tabelle recuperate, due difetti
- [x] `TECHNICAL_DESIGN_v1_8_57.md` — ricostruzione delle relazioni, criterio di
      scelta, duplicati sorgente, fan-out, media contro minimo, ordine
- [x] `DEPLOYMENT_v1_8_57.md` — prima sincronizzazione, volumi attesi, controllo
      di fan-off da riverificare in produzione
- [x] `MANUALE_ADMIN_v1_8_57.md`, `MANUALE_UTENTE_v1_8_57.md`
- [x] `RELEASE_CHECKLIST_v1_8_57.md` — questo documento

## 10. Aperto, dichiarato

- **30.467 rapporti restano senza ricavo** (45%): sono su commesse le cui tariffe
  orarie di ricavo sono a zero, oppure con codice commessa non riconciliato. Il
  passo successivo è capire quale delle due cause prevalga.
- La vista `v_cm_effort_confronto` confronta la **presenza** di un'allocazione con
  il consuntivo, non un monte ore: le allocazioni indicano un periodo di
  assegnazione e non una quantità, che la sorgente non fornisce. Leggerla come
  scostamento orario sarebbe un errore, ed è dichiarato nel commento della vista.
- Le relazioni sono desunte dalla nomenclatura: una relazione che non segua la
  convenzione non è stata vista. Lo schema la rispetta con costanza, ma non è una
  garanzia formale.
