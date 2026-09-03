# Release Checklist — PortalManager v1.8.61

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.60.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.61` |
| `app/SyncDatasets.php` | **modificato** (8° dataset) | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.60 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.61**
- [x] Release additiva: nessun dato modificato

## 2. Correzione della v1.8.60

- [x] La v1.8.60 dichiarava il costo direzionale **assente dai dati**
- [x] **Era sbagliato**: è il tipo di operazione `FVCCD`, 1.794.924,71 € su 174
      operazioni in `forms_contract_operation`
- [x] Causa dell'errore: la ricerca era per **nome di colonna**, mentre il
      concetto è modellato come **riga di una tabella di tipi**
- [x] `cm_operator_costs.directional_cost_hour` (costo orario per operatore) è
      **superata**: il costo direzionale è un importo di commessa. Colonna
      lasciata nello schema ma dichiarata inutilizzata

## 3. QA — analisi della tabella

| Verifica | Esito |
|---|---|
| Righe | 3.889 |
| Id distinti | **3.889 — nessun duplicato** |
| Tipi di operazione | 6 |
| Commesse coinvolte | 953 |

- [x] Prima tabella importata **priva di duplicati**, dopo i casi di v1.8.57 e
      v1.8.60

## 4. QA — semantica dei campi, verificata sui dati

| Gruppo | Campo | Verifica |
|---|---|---|
| REC (COV, COR) | `revenue` | 1.106 ordini su 1.145 con `revenue`, **1 solo** con `cost` |
| FVC (FVCCD, FVCBGS) | `cost` | 170 su 174 con `cost`, **0** con `revenue` |
| REP (Storno) | `final_value` | 4 su 4 |
| INV (INVMEMO) | nessuno | 0 importi |

- [x] Importo **normalizzato in import** in `amount`: nessuna query a valle deve
      conoscere la semantica
- [x] `signed_amount` porta il segno: un saldo con i segni sbagliati è più
      insidioso di uno mancante

## 5. QA — i riporti negativi

- [x] **30 riporti su 85** hanno importo negativo
- [x] Il segno del tipo governa la direzione concettuale, il segno del valore
      resta quello del dato: i due si moltiplicano, non si sostituiscono
- [x] Trattare COV come sempre positivo avrebbe sovrastimato l'alimentazione

## 6. QA — dataset e quadro

| Verifica | Esito |
|---|---|
| Query valida, colonne | 15, mappatura **allineata** |
| Fan-out | **nessuno** (3.400 → 2.628) |
| Chiave duplicata | **0 gruppi** |
| Operazioni agganciate a commessa | **2.597 su 2.628** |

| Tipo | Operazioni | Importo con segno |
|---|---|---|
| COV | 85 | +688.036 |
| COR | 841 | +26.892.498 |
| REP | 4 | +57.376 |
| FVCCD | 145 | **−1.232.152** |
| FVCBGS | 872 | −2.355.600 |
| INVMEMO | 681 | 0 |

**Saldo complessivo: 24.050.158,28 €**

## 7. QA — saldo di commessa

| Commessa | Linea | Ordini | Beni | Costo ore | Saldo |
|---|---|---|---|---|---|
| WTS_3018 | NV_FI | 0 | 3.162 | 419.705 | −422.867 |
| WTS_3118 | WTS-AM | 0 | 76.600 | 104.403 | −181.003 |
| WTS_3228 | WTS-PRES | 127.500 | 10.361 | 179.654 | −62.515 |
| WTS_3184 | WTS-CSS | 34.000 | 4.461 | 82.139 | −52.600 |

- [x] Ore e addebiti **tenuti separati**: una perdita da acquisti è un problema di
      preventivazione, una da ore è un problema di esecuzione
- [x] WTS_3184 è segnalata da due indicatori indipendenti: sforata (v1.8.58) e
      saldo negativo (questa)
- [x] `valore_commessa` resta accanto a `totale_alimentazioni`: non sono la stessa
      cosa e la divergenza è informativa

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c61` fresco | 440 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c61` | 440 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c61` | 440 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** (un `;` in commento corretto in corsa)
- [x] Ordine di sincronizzazione: `operazioni` fra `allocazioni` e `rapporti`
- [x] Conteggio statement consolidato: **433 → 440**

## 9. Nota di metodo

L'errore della v1.8.60 non è stato una svista ma un limite del metodo: cercare un
concetto fra i **nomi di colonna** trova solo ciò che è modellato come attributo.

Nel dump ci sono almeno tre tabelle di classificazione — 34 tipi di attività, 6
tipi di operazione, tipi di contratto — i cui contenuti definiscono concetti che
nessun nome di colonna nomina. Vanno lette come dati, non cercate come struttura.

## 10. Aperto

- **772 operazioni escluse** perché il contratto non ha un `code`: non sono
  agganciabili a una commessa. Vanno verificate sul gestionale.
- **31 operazioni importate** non trovano la commessa nel portale: riguardano
  commesse non ancora sincronizzate.
- La colonna `directional_cost_hour` va rimossa in una release successiva.
- Con il costo direzionale ora disponibile **per commessa**, la base di costo
  `direzionale` della v1.8.60 — pensata per prestazione — va rivista: il costo
  direzionale non si ripartisce sulle ore, si somma al saldo della commessa.
