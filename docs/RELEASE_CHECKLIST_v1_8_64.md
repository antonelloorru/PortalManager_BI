# Release Checklist — PortalManager v1.8.64

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.63.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.64` |
| `sync_commesse.php` | ROOT, **corretto** | OK |
| `app/DatasetSync.php` | **corretto** | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 6 in `app/` | invariati da v1.8.63 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.64**

## 2. Diagnosi dell'errore segnalato

Dall'esito allegato: **11 dataset su 11 in errore**.

| Dataset | Errore |
|---|---|
| Divisioni aziendali | `Unknown column 'import_batch_id' in 'field list'` |
| gli altri 10 | `There is already an active transaction` |

- [x] **Difetto 1**: `import_batch_id` mancante su **cinque** tabelle —
      `cm_divisions`, `cm_anomaly_types`, `cm_operator_costs`, `cm_band_costs`,
      `cm_operation_types`. Le altre quattro la avevano: aggiunta dove ci si era
      ricordati, non per regola
- [x] **Difetto 2 (causa dei 10 a cascata)**: `DatasetSync::writeRows()` apriva
      una transazione senza `rollBack()` in caso di errore
- [x] **Fattore amplificante**: `divisioni` è in testa all'ordine dalla v1.8.63.
      Se fosse stato ultimo, dieci dataset sarebbero passati

## 3. QA — riproduzione e correzione

Il collaudo esegue **entrambi** i comportamenti, non solo quello corretto.

| Scenario | Esito |
|---|---|
| Precedente: dataset difettoso | transazione **resta aperta** |
| Precedente: dataset successivo | `There is already an active transaction` |
| Corretto: 5 dataset di cui 2 difettosi | **3 riusciti su 5** (attesi 3) |
| Transazione aperta dopo ciascun dataset | **mai** |

- [x] Riprodurre il difetto prima di correggerlo verifica la diagnosi: un test
      che prova solo la correzione passerebbe anche con una diagnosi sbagliata

## 4. QA — schema allineato

- [x] `import_batch_id` aggiunta a **tutte e nove** le destinazioni, comprese le
      quattro che già la avevano
- [x] Motivazione: un elenco delle sole mancanti va aggiornato a mano a ogni
      nuova tabella, ed è così che il difetto si è prodotto
- [x] Indici sul batch per le cinque nuove
- [x] `v_cm_sync_schema_check` restituisce **0 righe** su `pm_demo` e su `pm_c64`

## 5. Correzione applicativa

- [x] `DatasetSync`: `try/catch` con `rollBack()` e `throw` successivo
- [x] `inTransaction()` prima del rollback: il metodo fa commit intermedi ogni
      500 righe, quindi potrebbe non esserci transazione attiva
- [x] `try/catch` **attorno al rollback**: se anche quello fallisce, l'eccezione
      che conta è quella originale
- [x] `sync_commesse.php`: rete di sicurezza nel ciclo, perché l'errore può
      arrivare da `openBatch()` o `readSource()`, fuori dal `try` di `DatasetSync`
- [x] Ridondanza voluta: il ciclo è l'unico punto che vede tutti i dataset

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 19 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 19 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 19 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c64` fresco | 465 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c64` | 465 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c64` | 465 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Controllo di schema verificato su DB fresco: **0 problemi**
- [x] Conteggio statement consolidato: **448 → 465**

## 7. Nota di metodo

Il difetto nasce da una colonna aggiunta caso per caso invece che per regola.
Nove tabelle di destinazione, quattro con la colonna e cinque senza, senza che
nulla segnalasse l'incoerenza.

`v_cm_sync_schema_check` è la risposta strutturale: la correzione risolve oggi,
il controllo segnala domani. Vale più della correzione stessa, a patto che
qualcuno lo guardi — ed è per questo che compare nella verifica post-deploy e nel
manuale amministratore.

## 8. Aperto

- L'elenco delle tabelle in `v_cm_sync_schema_check` è **cablato** e va aggiornato
  quando nasce un dataset. È una duplicazione rispetto a `SyncDatasets`: meno
  elegante di una derivazione automatica, ma verificabile a colpo d'occhio.
- Restano aperti i punti della v1.8.63: il collegamento fra divisione e tecnico,
  e l'esame delle 137 commesse segnalate solo dal gestionale.
