# Release Checklist — PortalManager v1.8.59

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.58.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.59` |
| `dgb_activities.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 5 ROOT + 6 in `app/` | invariati da v1.8.58 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.59**
- [x] Release additiva: due colonne e tre viste, nessun dato modificato

## 2. Correzione di una lettura sbagliata della v1.8.58

- [x] La v1.8.58 segnalava come anomala l'assenza di ore su WTS-REP, ipotizzando
      ore imputate altrove e margine fittizio
- [x] **L'ipotesi era sbagliata**: l'assenza di ore è il comportamento corretto,
      perché il canone remunera la disponibilità e non l'intervento
- [x] Le 2 ore presenti non erano il sintomo di un problema più grande: erano
      esattamente due errori
- [x] Il margine del 95,6% dei canoni WTS-REP è **legittimo**
- [x] La correzione è documentata nel CHANGELOG e nel manuale amministratore,
      non silenziosamente sovrascritta

## 3. QA — le segnalazioni sui dati reali

| Rapporto | Data | Tecnico | Ore | Commessa errata | Suggerita | Alternative |
|---|---|---|---|---|---|---|
| SODA_23_005716 | 02/07/2023 | Sozzi David | 1,00 | WTS_3092 | WTS_3053 | 3 |
| SODA_23_009943 | 26/09/2023 | Sozzi David | 1,00 | WTS_3092 | WTS_3053 | 3 |

| Riepilogo | Valore |
|---|---|
| Segnalazioni | **2** |
| Commesse coinvolte | 1 |
| Tecnici | 1 |
| Ore | 2,00 |
| Con suggerimento | 2 |
| **Suggerimento univoco** | **0** — dichiarato, la scelta è umana |

## 4. QA — effetto sulle statistiche di effort

| Vista | Commesse a canone | Ore medie |
|---|---|---|
| `v_cm_redditivita_commessa` (tutte) | 180 | 35,7 |
| `v_cm_redditivita_operativa` | **126** | **50,9** |

- [x] Differenza del **43%**: usare la prima per dimensionare un team porterebbe
      a una sottostima di oltre un terzo
- [x] Il **valore** dei canoni resta nei totali di ricavo: è incassato davvero
- [x] Due viste per due domande: il denaro e l'effort non si filtrano allo stesso
      modo

## 5. Scelte di progetto

- [x] `allows_reports` e `operative_lines` sono **attributi del modello**, non un
      `WHERE service_line = 'WTS-REP'` nella vista: una nuova linea con la stessa
      regola non richiede una release
- [x] Il suggerimento cerca fra commesse **dello stesso cliente**, con linea
      ammessa, attive alla data, preferendo le aperte
- [x] **`commesse_candidate` è esposto**: un suggerimento presentato come certezza
      quando non lo è produce correzioni sbagliate, peggiori dell'errore
      originale perché sembrano risolte
- [x] **Criterio scartato**: la contiguità dei codici (WTS_3092 → WTS_3093) dava
      il risultato giusto su questo caso ma è una coincidenza di numerazione, non
      una relazione dichiarata. Un criterio che funziona per caso fallisce in
      silenzio
- [x] Il pannello è in testa alla scheda Anomalie e non in una scheda separata:
      chi controlla apre una pagina, non tre

## 6. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 9 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 9 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c59` fresco | 422 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c59` | 422 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c59` | 422 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] `ADD COLUMN IF NOT EXISTS`, `UPDATE` condizionato: rieseguibile
- [x] Conteggio statement consolidato: **415 → 422**

## 7. Nota di metodo

La v1.8.58 aveva dedotto dai soli dati che l'assenza di ore fosse un sintomo. La
deduzione assumeva che ogni contratto con un valore debba generare consuntivo —
vera per sei modelli su sette, falsa per un canone di disponibilità.

Il dato non conteneva l'informazione per distinguere i due casi: solo la
conoscenza del funzionamento contrattuale poteva farlo. È il limite di
un'analisi condotta sui soli numeri, e la ragione per cui una regola fornita
dall'azienda vale più di qualunque inferenza.

## 8. Aperto

- Le due segnalazioni riguardano il 2023 e vanno corrette sul gestionale. Nessun
  automatismo le sposta: il portale non decide su quale delle tre commesse
  candidate vada l'intervento.
- Resta da verificare se gli **altri** canoni — WTS-MON, WTS-GES, WTS-SD, che
  ricevono interventi regolarmente — abbiano una regola analoga o siano
  effettivamente operativi. Su di essi il dubbio della v1.8.58 non è stato
  sciolto.
