# Release Checklist — PortalManager v1.8.58

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.57.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.58` |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 6 in `app/` | invariati da v1.8.57 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.58**
- [x] Release additiva: una tabella e quattro viste, nessun dato modificato

## 2. Classificazione implementata

| Modello | Linee | has_revenue | budget_overrun |
|---|---|---|---|
| interno | 9 linee NV_* e WTS-HD | **0** | 0 |
| canone | WTS-MON, REP, GES, SD | 1 | 0 |
| presidio | WTS-PRES | 1 | 0 |
| chiavi_mano | WTS-ACM | 1 | **1** |
| a_scalare | WTS-CSS | 1 | **1** |
| a_chiamata | WTS-CC | 1 | 0 |
| assistenza | WTS-MEG | 1 | 0 |

- [x] 18 linee classificate, in tabella e non nel codice
- [x] `INSERT IGNORE`: una riclassificazione manuale non viene sovrascritta

## 3. QA — il quadro sui dati reali

| Modello | Commesse | Ore | Valore | Costo | Margine | Sforate |
|---|---|---|---|---|---|---|
| presidio | 48 | 144.419 | 7.022.269 | 3.270.262 | 53,4% | 0 |
| interno | 76 | 80.666 | 30.900 | **2.230.167** | — | — |
| a_scalare | 158 | 48.322 | 6.035.884 | 2.163.434 | 64,2% | **5** |
| chiavi_mano | 332 | 37.107 | 8.786.811 | 1.563.937 | 82,2% | **10** |
| a_chiamata | 150 | 12.067 | 1.168.536 | 481.393 | 58,8% | 0 |
| canone | 180 | 6.421 | 5.963.665 | 265.205 | 95,6% | 0 |
| assistenza | 90 | 2.421 | 3.287.584 | 40.031 | 98,8% | 0 |
| da_classificare | 28 | 6.982 | 1.948.691 | 151.602 | 92,2% | 0 |

- [x] **15 commesse sforate**, 14 prossime al limite
- [x] Peggiore: WTS_3184, a scalare, consumo **241,6%**
- [x] L'allerta scatta solo dove `budget_overrun = 1`

## 4. Difetto di aggregazione intercettato

- [x] Il primo tentativo raggruppava per `modello, modello_label`: le nove linee
      interne comparivano come **nove righe** e il totale di 2,23 milioni — il
      dato per cui l'analisi esisteva — non era leggibile
- [x] Corretto raggruppando per solo `modello`, con `GROUP_CONCAT` delle linee

## 5. Scelte analitiche

- [x] **NULL invece di ricavo stimato** dove non è attribuibile alla prestazione:
      `ricavo_origine` spiega sempre il motivo
- [x] Due viste a grane diverse: prestazione per i modelli a consumo, commessa
      per canone, chiavi in mano, presidio e a scalare
- [x] Il **costo** resta calcolabile alla prestazione per ogni modello: non
      dipende da come si vende
- [x] `consumo_valore_pct` con soglia di allerta all'85%, applicata solo ai
      modelli in cui sforare è un problema

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 10 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 10 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 10 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c58` fresco | 415 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c58` | 415 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c58` | 415 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **407 → 415**

## 7. Aperto, da verificare con l'azienda

- **WTS-REP: 2.172.606 € di valore su 54 commesse, 2 ore consuntivate.**
  Implausibile. Verosimilmente le ore di reperibilità sono imputate alle commesse
  dove l'intervento avviene. Se confermato, il margine del 95,6% dei canoni è
  fittizio — ricavo reale, costo altrove. Richiede di conoscere la prassi di
  imputazione, che il portale non può dedurre.

- **WTS-SOC (1,66 M€, 15 commesse) e WTS-AM** non erano nella classificazione
  fornita e restano *da classificare*. Non sono state assegnate per somiglianza:
  su quella cifra un modello sbagliato distorce più di una riga dichiaratamente
  incompleta.

- Il margine dei modelli a canone e assistenza (95,6% e 98,8%) è calcolato come
  valore meno costo consuntivato. È corretto formalmente ma va letto sapendo che
  su quei modelli il costo consuntivato è basso — per i canoni forse perché
  imputato altrove, per l'assistenza perché il grosso è materiale e non ore.
