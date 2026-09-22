# Release Checklist — PortalManager v1.8.60

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.59.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.60` |
| `app/SyncDatasets.php` | **modificato** (7° dataset) | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.59 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.60**
- [x] Release additiva: nessun dato modificato

## 2. Classificazione delle basi di costo

| Base | Linee | Verificata |
|---|---|---|
| direzionale | WTS-GES, WTS-SOC | sì |
| full_cost | 10 linee (NV_* + WTS-AM, WTS-HD, WTS-PRES) | sì |
| fascia_o_direzionale | 8 linee | sì |

- [x] `cost_basis` è attributo del modello, indipendente da `revenue_basis`:
      costo e ricavo hanno regimi ortogonali
- [x] WTS-SOC e WTS-AM inserite con la loro base di costo, **modello contrattuale
      ancora `da_classificare`**: due informazioni indipendenti

## 3. QA — il full cost

| Verifica | Esito |
|---|---|
| Fonte individuata | `dgb_operator.full_cost` |
| Operatori con valore | **202 su 256** |
| Natura del dato | costo **annuo**, media 25.040 €, max 109.886 |
| Conversione | / 1.760 ore (8 h × 220 gg), parametro in `app_settings` |
| Costo orario risultante | media **18,03**, min 0, max 62,44 |
| Confronto con le fasce | fasce 31,25–68,75 €/h — **grandezze non interscambiabili** |

## 4. QA — il costo direzionale NON è nei dati

- [x] Ricerca su tutte le **102 tabelle** del dump per colonne *full*, *direz*,
      *overhead*, *struttura*: tre risultati, tutti relativi al full cost
- [x] **Non stimato**: WTS-GES e WTS-SOC valgono insieme ~2 M€, un margine su un
      costo inventato verrebbe usato per decidere
- [x] Struttura predisposta (`directional_cost_hour`), linee classificate, assenza
      dichiarata in `costo_origine`
- [x] 944 ore restano visibilmente scoperte

## 5. QA — copertura sui dati reali

| Origine del costo | Prestazioni | Ore | Costo |
|---|---|---|---|
| **rilevato** | 62.804 | 317.960 | 10.177.314 |
| SCOPERTO: né full cost né fascia | 3.422 | 16.959 | — |
| SCOPERTO: nessuna base | 1.059 | 3.010 | — |
| SCOPERTO: né direzionale né fascia | 563 | 944 | — |

- [x] Il costo **rilevato** copre il 94% delle ore e non viene mai sostituito da
      una stima
- [x] Scoperte **20.913 ore (6,2%)**

## 6. Difetto di etichettatura intercettato in collaudo

- [x] La prima stesura etichettava *«RIPIEGO su fascia»* casi con costo **zero**,
      perché mancava anche la fascia: l'etichetta descriveva un ripiego
      impossibile
- [x] Difetto insidioso: numero plausibile, etichetta plausibile, solo
      l'accostamento rivela l'incoerenza
- [x] Corretto condizionando il ripiego alla disponibilità della fascia e
      introducendo l'etichetta **SCOPERTO** con l'indicazione di che cosa manca

## 7. Terzo duplicato nella sorgente

- [x] `dgb_operator`: **512 righe per 256 id distinti**
- [x] Terzo caso dopo `dgb_operator_allocations_on_forms_contract` (v1.8.57)
- [x] `DISTINCT` nella query del dataset
- [x] La ricorrenza suggerisce una caratteristica del dump, non un incidente:
      il controllo `COUNT(*)` vs `COUNT(DISTINCT id)` va fatto su ogni nuova
      tabella importata

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 13 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 13 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 13 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c60` fresco | 433 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c60` | 433 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c60` | 433 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Ordine di sincronizzazione aggiornato: `costi_operatore` dopo
      `costi_fascia` e prima dei rapporti
- [x] Conteggio statement consolidato: **422 → 433**

## 9. Aperto

- **Il costo direzionale va fornito.** Finché manca, 944 ore su WTS-GES e WTS-SOC
  restano senza costo e il margine di quelle linee non è calcolabile.
- **20.913 ore scoperte** non dipendono solo dal costo direzionale: 16.959 sono su
  linee a full cost dove manca sia il full cost dell'operatore sia la fascia.
  Vanno indagate separatamente.
- Le **ore lavorabili annue** (1.760) sono in `app_settings` ma anche nella query
  del dataset: cambiarle richiede di aggiornare entrambi. È una duplicazione nota
  e documentata.
- Il **modello contrattuale** di WTS-SOC e WTS-AM resta da definire.
