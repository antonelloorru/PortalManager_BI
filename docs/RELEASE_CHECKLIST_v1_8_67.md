# Release Checklist — PortalManager v1.8.67

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.66.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.67` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `sync_commesse.php` | ROOT, **modificato** | OK |
| `app/DgbModel.php` | **+ hourlyHeatmap()** | OK |
| `app/DatasetSync.php` | **+ previewAll()** | OK |
| `app/Version.php` | modificato | OK |
| 4 ROOT + 4 in `app/` | invariati da v1.8.66 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.67**
- [x] Release solo applicativa: nessuna variazione di schema né di dati

## 2. Anteprima integrale — il vincolo era la memoria

| Misura | Valore |
|---|---|
| Dataset *allocazioni* accumulato (`fetchAll`) | **36 MB** |
| Stessi dati in **streaming** (`fetch` in ciclo) | **6 MB** |
| Stima per 11 dataset con accumulo | ~100 MB |
| `memory_limit` tipico XAMPP | 128 MB |

- [x] Alzare il limite avrebbe rinviato il problema, non risolto
- [x] `previewAll()` legge in streaming: memoria **costante**, non cresce col volume
- [x] `closeCursor()` dopo ogni verifica: senza, il driver mantiene aperto il
      result set e la query successiva fallisce
- [x] Non riusa `writeRows($dryRun)`: quello riceve un array e avrebbe richiesto
      comunque l'accumulo, cioè il problema da evitare
- [x] Compromesso dichiarato: tempo (una `SELECT ... LIMIT 1` per riga) in cambio
      di memoria

## 3. Distribuzione sulle 24 ore — ripartire, non attribuire

| Ora | Attribuite all'inizio | Ripartite |
|---|---|---|
| 08 | 2.047,0 | 250,7 |
| 09 | **7.325,5** | 1.453,7 |
| 10 | 236,5 | 1.359,9 |
| 16 | 147,5 | 1.134,5 |

- [x] Con attribuzione all'inizio: **64%** delle ore sull'ora 9
- [x] Con ripartizione: **13%**
- [x] Il picco all'inizio non descrive il lavoro ma **come viene registrato**
      (inizio 09:00 per convenzione)
- [x] Stessa espressione della v1.8.53, applicata a 24 finestre invece che a due
- [x] Interventi a cavallo della mezzanotte (0,58%) esclusi, dichiarato

## 4. QA — quadratura e coerenza

| Controllo | Esito |
|---|---|
| Somma profilo orario = totale dichiarato | 11.277,15 = 11.277,15 |
| Somma celle giorno × ora = totale | **OK** |
| Ore in fascia ordinaria | 86,2% |
| Ore fuori fascia | **13,8%** |
| Confronto con la v1.8.53 (via indipendente) | **13,48%** |

- [x] Due misure costruite per vie diverse convergono entro 0,3 punti

## 5. QA — resa grafica

| Verifica | Esito |
|---|---|
| Celle valorizzate | 433 su 744 (58%) |
| Cella massima / media | **2,9** |
| Celle sotto il 15% del massimo | **52%** |

- [x] Scala del colore su **radice** e non lineare: con scala lineare il 52%
      delle celle risulterebbe quasi bianco
- [x] Il valore esatto è nel suggerimento di ogni cella: la scala serve alla
      forma, non alla lettura del numero
- [x] Fasce ordinarie in grassetto, fine settimana con intestazione chiara
- [x] Caricamento condizionato a `$gran === 'day'`: la vista mensile non paga il
      costo di una query che non userebbe

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c67` **collation mista** | 481 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c67` | 481 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c67` | 481 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Consolidato verificato sullo scenario a **collation mista** della v1.8.66
- [x] Conteggio statement consolidato: **480 → 481**

## 7. Aperto

- L'anteprima integrale esegue una query di verifica per riga: su volumi molto
  maggiori il tempo crescerebbe linearmente. Se diventasse un problema, la strada
  è caricare le chiavi esistenti in un insieme una volta sola — al costo di
  memoria proporzionale al numero di chiavi, non di righe.
- La matrice esclude gli interventi a cavallo della mezzanotte. Sono lo 0,58%:
  includerli richiederebbe di spezzarli su due giorni.
- Restano i punti della v1.8.63: collegamento fra divisione e tecnico, ed esame
  delle 137 commesse segnalate solo dal gestionale.
