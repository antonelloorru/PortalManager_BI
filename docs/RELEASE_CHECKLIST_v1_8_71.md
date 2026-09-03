# Release Checklist — PortalManager v1.8.71

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.70.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.71` |
| `sync_commesse.php` | ROOT, **modificato** | OK |
| `app/DatasetSync.php` | **+ reconcile()** | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 5 in `app/` | invariati da v1.8.70 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.71**
- [x] Release solo applicativa; la funzione introdotta può eliminare righe solo
      se lanciata deliberatamente

## 2. Il problema risolto

- [x] `writeRows()` fa `INSERT` e `UPDATE`, mai `DELETE`: il portale **accumula**
- [x] Caso estremo: 67.786 rapporti fantasma rimossi dalla v1.8.70 con pulizia
      mirata
- [x] Questa release rende il controllo **ripetibile su 12 dataset**

## 3. Progetto

- [x] **Chiavi in memoria, righe in streaming**: pochi MB contro i 36 che
      l'accumulo delle sole allocazioni costerebbe (misurato in v1.8.67)
- [x] Verifica di appartenenza in tempo costante con array associativo: nessuna
      query per riga del target
- [x] **`import_batch_id` come criterio di appartenenza**: solo le righe scritte
      da una sincronizzazione sono candidate
- [x] `columnExists()` verifica la colonna invece di presumerla; il ripiego
      `1=1` non si attiva perché dalla v1.8.64 tutte e 12 le destinazioni la
      hanno
- [x] **Due azioni separate**: il pulsante di rimozione compare solo dopo una
      verifica che abbia trovato orfane, e riporta il numero esatto
- [x] Campioni di chiave nell'esito: un conteggio dice quanto, non che cosa
- [x] Rimozione a blocchi da 500 in transazione con rollback (schema v1.8.64)

## 4. QA — collaudo funzionale

Scenario: 7 righe, di cui 5 da sincronizzazione (2 orfane) e 2 inserite a mano
con chiavi assenti dalla sorgente.

| Verifica | Esito |
|---|---|
| Anteprima: righe rimosse | **0** |
| Righe dopo l'anteprima | **7, invariate** |
| Righe manuali fra le orfane | **no — protette** |
| Applicazione: righe rimosse | **2**, esattamente le orfane |
| Orfane residue | **0** |
| Seconda esecuzione | 0 orfane, 0 rimosse — **idempotente** |

- [x] Le due righe manuali sopravvivono pur avendo chiavi che il gestionale non
      conosce: comportamento voluto e verificato

## 5. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c71` fresco | 497 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c71` | 497 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c71` | 497 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **496 → 497**

## 6. Limite dichiarato

- [x] La riconciliazione confronta **chiavi**: non riconosce righe duplicate
      sotto chiavi diverse
- [x] Nel caso della v1.8.70 funzionava perché i codici `DGB-<id>` non esistevano
      nella sorgente
- [x] Dichiarato in technical design e manuale: una funzione chiamata "riallinea"
      può dare l'impressione di garantire più di quanto garantisca

## 7. Aperto

- **Le commesse restano 1.518** contro le ~1.062 del gestionale, anche dopo la
  v1.8.70. La verifica di allineamento su `commesse` dirà quante sono orfane e
  con quali chiavi: è il primo uso concreto da fare dopo l'aggiornamento, e
  chiarirà l'origine dei 339 codici anomali (`AZIE`, `CLIE`).
- Dopo un riallineamento va rilanciata la sincronizzazione in scrittura per
  verificare che non reintroduca le righe rimosse. Se lo facesse, il difetto è
  nella query del dataset e non nei dati.
