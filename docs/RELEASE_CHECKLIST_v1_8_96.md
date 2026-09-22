# Release Checklist — PortalManager v1.8.96

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.95.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.96` |
| `app/Version.php` | modificato | OK |
| 9 ROOT + 9 in `app/` | invariati da v1.8.95 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.96**
- [x] **Release parziale, dichiarata**: la pagina non è inclusa

## 2. I cinque KPI richiesti

| KPI | Valore |
|---|---|
| 1. Valore economico complessivo | **7.022.269 €** |
| 2. Headcount | **35** |
| 3. Giornate di copertura | **4.820** |
| 4. Dettaglio commesse | ricavo, costo, margine % su 48 righe |
| 5. Flag allocazione | 20 fisse, 5 miste, 16 rotazione |

Costo interno 3.479.331 €, margine 3.542.938 €, 28 commesse aperte su 48.

## 3. L'esclusività come soglia, non come vincolo binario

| Quota ore su presidio | Persone | Ore |
|---|---|---|
| 95–100% | 28 | 91.872 |
| 80–95% | 7 | 13.986 |
| 50–80% | 10 | 25.311 |
| sotto 50% | 36 | 13.251 |

- [x] La regola letterale escluderebbe **Balestrieri Paolo**: 99,7% di quota, 19
      ore fuori presidio in tutta la sua storia, ma due commesse
- [x] Soglia **80%**, in una zona poco popolata fra due gruppi netti
- [x] **In tabella**, non nel codice: è una convenzione aziendale

## 4. I due criteri restano distinti

| Classificazione | Segnala |
|---|---|
| presidio confermato | nell'unità e sopra soglia |
| presidio di fatto | **assegnazione mancante** |
| assegnato non operante | **anagrafica da aggiornare** |
| copertura | sostituzione occasionale |

- [x] Un flag booleano avrebbe perso le due segnalazioni di anagrafica
- [x] Sul DB di prova: 35 «di fatto», 0 «confermati» perché nessun profilo ha
      l'unità. **Sul server del cliente le assegnazioni ci sono**

## 5. Fissa o rotazione: quota, non conteggio

- [x] 12 commesse su 48 hanno una sola persona, ma il conteggio non basta
- [x] **WTS_3043**: 7 persone, 666 giornate di copertura, principale al 78,5% →
      **«fissa con sostituzioni»**. Contando le persone sarebbe stata «rotazione»
- [x] Soglie 80% / 60%, entrambe in tabella
- [x] `SUBSTRING_INDEX(GROUP_CONCAT(… ORDER BY ore DESC), ',', 1)` per la
      principale: MariaDB non ha primo-per-gruppo, e la sottoquery correlata
      sarebbe costata una scansione per commessa

## 6. Le giornate di copertura: due misure, entrambe esposte

| Metodo | Risultato |
|---|---|
| Giorni di calendario | **4.820** |
| Ore diviso 8 | **1.656** |

- [x] Una sostituzione di due ore occupa un giorno ma vale un quarto di giornata
- [x] **Domanda posta al responsabile**: quale delle due dipende da come viene
      fatturata

## 7. Parametri modificabili

| Parametro | Predefinito |
|---|---|
| `presidio_linee` | `WTS-PRES` |
| `presidio_soglia_esclusivita` | 80 |
| `presidio_soglia_fissa` | 80 |
| `presidio_soglia_rotazione` | 60 |
| `presidio_giornata_ore` | 8 |

- [x] `presidio_linee` è un **elenco**: i body rental oggi non hanno una linea
      propria ma potrebbero averla, e `FIND_IN_SET` accoglie l'aggiunta senza
      toccare le viste

## 8. Difetti intercettati in collaudo

- [x] `p.status` inesistente: la colonna è **`operational_status`** — due
      occorrenze
- [x] Tre `;` in commenti SQL
- [x] Corretti; entrambi gli splitter a **err=0**

## 9. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` (dati reali) | 10 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 10 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 10 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c96` fresco | 630 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c96` | 630 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c96` | 630 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **622 → 630**

## 10. Nota di metodo

La regola di business, applicata alla lettera, non funzionava sui dati. Non perché
fosse sbagliata, ma perché descriveva l'intenzione — «queste persone stanno su una
commessa sola» — con un criterio che i dati non soddisfano mai perfettamente.

Trasformare il vincolo binario in una misura con soglia conserva l'intenzione e la
rende applicabile. La soglia va però **dichiarata**, perché è una scelta e non un
fatto.

## 11. Aperto

- **La pagina non è inclusa**: filtri, grafici, export e stampa. Le viste sono
  verificate e rispondono a tutti e cinque i KPI.
- **Le giornate di copertura**: due misure possibili, serve sapere quale conta.
- **Le assegnazioni all'unità Presidio**: sul database di prova sono assenti e
  tutti risultano «di fatto». Il valore delle quattro categorie si vede solo con
  le assegnazioni reali.
- I **body rental** non hanno oggi una linea distinta: sono accorpati in
  `WTS-PRES` come richiesto. Se un domani ne avessero una, basta aggiungerla al
  parametro.
