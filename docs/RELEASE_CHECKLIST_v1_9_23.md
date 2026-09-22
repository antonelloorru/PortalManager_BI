# Release Checklist — PortalManager v1.9.23

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.22.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.23` |
| `pratix_orders.php` | ROOT, **NUOVA** | OK |
| `app/Router.php` | **modificato** | OK |
| `app/MenuManager.php` | **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 29 file restanti | invariati da v1.9.22 | OK |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.23**
- [x] Registrata in **Router** e **MenuManager**

## 2. Il difetto delle chiavi PHP

- [x] MariaDB raggruppa con collation `_ci`: **`a3992` e `A3992` insieme**
- [x] Un array PHP **distingue**: le righe non si trovavano
- [x] Effetto: **totale giusto, meno righe** — un elenco che non somma al proprio
      totale, senza errori visibili
- [x] **4 ordinativi su 300** in questa condizione
- [x] Chiave normalizzata in maiuscolo, come il raggruppamento SQL
- [x] **Stesso schema della v1.9.12**: due sistemi che confrontano stringhe con
      regole diverse

## 3. Una query sola per le righe

- [x] Con 300 ordinativi, una query ciascuno sarebbero **300 interrogazioni per
      una pagina**
- [x] Un solo `IN` sui codici mostrati

## 4. Ciò che la pagina dichiara di non poter fare

- [x] Validazione impossibile su **896 su 896**: totale dichiarato non
      sincronizzato
- [x] Dichiarato in un riquadro in testa, non nascosto in una colonna vuota
- [x] **Un cruscotto che espone una validazione mai eseguita fa credere che sia
      passata**

## 5. Le anomalie

- [x] **15 celle con più codici**, segnalate e non divise
- [x] Importi **previsti** marcati con P: sommarli ai consolidati darebbe un
      totale misto

## 6. QA — sette scenari

| Scenario | Ordinativi | Righe | Importo |
|---|---|---|---|
| tutti | 300 | 537 | 34.533.530,01 |
| codici multipli | 15 | 34 | 3.579.503,26 |
| anomalie | 51 | 75 | 3.590.062,26 |
| più commesse | 135 | 405 | 18.696.205,71 |
| ricerca ESTAR | 23 | 52 | 4.420.860,09 |
| cliente ESTAR | 23 | 52 | 4.420.860,09 |
| ricerca a vuoto | 0 | 0 | 0,00 |

- [x] In ogni scenario **la somma delle righe fa il totale**
- [x] Nessun ordinativo mostrato senza righe

## 7. Altri controlli

| Verifica | Esito |
|---|---|
| Avvisi durante il caricamento | **0** |
| `if`/`endif` | **15 = 15** |
| `foreach`/`endforeach` | **4 = 4** |
| `<div>` | **24 = 24** |
| Variabili solo nel catch | **0** |
| Migration RUN1/RUN2 | 4 stmt, **err=0** |
| **Consolidato completo** | **767 stmt, err=0** |
| `;` nei commenti SQL | **0** |

## 8. Due difetti del collaudo stesso

- [x] `extract()` creava `$ord` mentre il codice usa `$ordina`
- [x] `$righePer` si accumulava fra le iterazioni: l'inizializzazione sta **prima
      del try**, fuori dal blocco estratto. Il test la deve replicare

Entrambi nel test, non nel codice — ma senza correggerli il collaudo avrebbe
segnalato difetti inesistenti e nascosto quello vero.

## 9. Aperto

- **Il totale dichiarato non è sincronizzato**: serve il dataset per
  `forms_contract_main_order`.
- **Gli importi sono quasi tutti previsti**: il totale è una previsione.
- **Verifica a schermo non eseguita**: il comportamento è verificato eseguendo il
  blocco di caricamento su sette scenari, la resa no.
- Restano gli aperti precedenti: `fascia_letta_pct`, risincronizzazione dopo la
  v1.9.12, valorizzazione a costo (`CEH`).
