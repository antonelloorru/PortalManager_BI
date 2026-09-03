# Release Checklist — PortalManager v1.8.78

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.77.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.78` |
| `app/DgbModel.php` | **modificato** — extra non sommate | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 5 in `app/` | invariati da v1.8.77 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.78**
- [x] **Release che elimina dati**: 77 righe, backup dichiarato

## 2. Diagnosi del caso segnalato

- [x] WTS_VAMI_26_019703: **due allocazioni** per l'operatore 2458, valori
      identici (9,00 h / 4,00 extra), create il 17/07 e il **13/08**
- [x] Giornata corretta 13 ore (9 + 2 + 0,50×4); duplicato +9 → **22**
- [x] Causa: **assenza di vincolo di unicità** su `(id_activity, id_operator)`

## 3. Ampiezza, su tutti i moduli e tutti i tecnici

| | |
|---|---|
| Gruppi duplicati | **77** |
| Righe in eccesso | 77 |
| **Ore gonfiate** | **362,50** |
| Gruppi con valori identici | **77 / 77** — copie, non revisioni |
| Creati il 13/08/2026 | **64 / 77** — singola risincronizzazione |

## 4. QA — deduplica sul database reale (dump 19/08 ore 21:58)

| | Prima | Dopo |
|---|---|---|
| Allocazioni | 71.294 | **71.217** |
| Ore | 344.738,0 | **344.375,5** |
| `v_dgb_allocazioni_duplicate` | 77 | **0** |
| `cm_cleanup_log` | — | 77 righe, 362,50 ore |

- [x] Migration RUN2 e RUN3: **0 righe rimosse**, log non duplicato — idempotente
- [x] Conservato l'**id più alto**: sui dati indifferente (valori identici), ma la
      regola regge il caso di revisione anziché copia
- [x] Tabella d'appoggio con `CREATE ... AS SELECT` — lezione della v1.8.66
- [x] Vincolo applicato **dopo** la deduplica: prima fallirebbe

## 5. `extra_hours` compreso in `hours`

- [x] Indicazione dell'azienda: 9 ore = 5 ordinarie + 4 extra
- [x] `hoursBreakdown()` sommava i due campi → extra contate due volte
- [x] Corretto: `ordinary` per differenza, `total_hours` esposto a parte
- [x] I tre valori ora coerenti: `ordinary + overtime = total_hours`

## 6. QA — il caso segnalato dopo la correzione

| Modulo | Ore | Ordinarie | Extra | In orario | Fuori |
|---|---|---|---|---|---|
| 019706 | 2,00 | 2,00 | — | 2,00 | — |
| 019707/09/10/11 | 0,50 ×4 | 0,50 ×4 | — | 0,50 ×4 | — |
| **019703** | 9,00 | 5,00 | 4,00 | 7,20 | 1,80 |
| **Totale giornata** | **13,00** | 9,00 | 4,00 | 11,20 | 1,80 |

- [x] **13 ore**, come le pianificate

## 7. QA — nuove colonne su tutti i tecnici

| | |
|---|---|
| Allocazioni | 69.106 |
| Ore consuntivate | 334.416,00 |
| In orario | 289.234,51 |
| Fuori orario | 45.181,49 |
| **Scarto** | **0,00** |
| Quota fuori orario | **13,5%** |

- [x] Fuori orario **per differenza**: `in + fuori = consuntivate` vero per
      costruzione, nessuno scarto di arrotondamento
- [x] **Riscontro indipendente**: 13,5% contro il **13,48%** della v1.8.53,
      ottenuto per altra via
- [x] Due assi distinti — ordinarie/extra (contrattuale) e in/fuori orario
      (temporale) — esposti entrambi e mai sommati fra loro

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_n` (dump reale) | 14 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_n` | 14 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_n` | 14 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c78` fresco | 542 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c78` | 542 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c78` | 542 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** (un `;` in commento corretto in corsa)
- [x] Conteggio statement consolidato: **530 → 542**

## 9. Nota di metodo

Il caso segnalato riguardava un modulo. La verifica ne ha trovati **77**, e la
concentrazione delle date di creazione ha indicato l'origine: una singola
risincronizzazione, plausibilmente quella successiva alla v1.8.73 che ha portato
le tabelle DGB nella sincronizzazione.

Correggere il solo modulo segnalato avrebbe lasciato 76 casi identici e nessuna
protezione contro il successivo.

## 10. Aperto

- Il duplicato nasce a valle: la **sorgente** potrebbe contenere essa stessa due
  righe per la stessa coppia. Il vincolo ora le respinge, ma la sincronizzazione
  segnalerà un errore su quel dataset invece di ignorarle silenziosamente —
  comportamento corretto, ma da verificare al primo giro.
- Le nuove colonne sono esposte come **viste**: l'inserimento nei prospetti a
  video di *Timesheet* e *Carico & Sovrapposizioni* è il passo successivo, se
  serve vederle senza interrogare il database.
