# Release Checklist — PortalManager v1.8.84

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.83.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.84` |
| `service_desk.php` | ROOT, **NUOVO** | OK |
| `app/SdModel.php` | **NUOVO** | OK |
| `app/MenuManager.php` | modificato — voce di menu | OK |
| `app/Router.php` | modificato — slug | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 5 in `app/` | invariati da v1.8.83 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.84**
- [x] `service_desk` in `Router::PAGES` per lo slug opaco

## 2. I quattro indicatori

| Indicatore | Agosto 2026 | Intero periodo |
|---|---|---|
| Ticket del periodo | 150 | 3.512 |
| Presi in carico da L1 | 55 | 1.470 |
| Tasso di escalation | 1,8% | **7,1%** |
| **Da presidiare** | 4 | **14** |

## 3. Il denominatore del tasso

- [x] Denominatore = risolti + escalation, **non** il totale ticket
- [x] Le prese in carico dirette (42%) escluse: non passate da L1, non
      scalabili
- [x] Includendole il tasso scenderebbe da **7,1% a 3,6%** — verificato in
      collaudo
- [x] `null` e non `0` quando il denominatore è zero: un tasso non calcolabile è
      diverso da un tasso pari a zero

## 4. QA — rendering con avvisi convertiti in eccezioni

| Verifica | Esito |
|---|---|
| Avvisi o errori PHP | **0** |
| Periodo predefinito | 2026-08-01 → 2026-08-31 (ultimo mese con dati) |
| Team L1 riconosciuto | 4 tecnici |
| Classi prodotte | 6 |
| Punti del grafico | 12 su 12 mesi |
| Ticket da presidiare | 4 (agosto), 14 (totale) |
| Operatori / code | 21 / 10 |

- [x] Metodo della v1.8.72: `set_error_handler` che solleva, perché `php -l` non
      rileva l'uso di variabili prima della definizione

## 5. QA — quadratura

| Verifica | Esito |
|---|---|
| Somma delle sei classi = totale ticket | **150 = 150** |
| Tasso calcolato = tasso atteso | **1,8% = 1,8%** |
| Filtro coda Voip | 43 ticket, 4 da presidiare |
| Filtro livello L1 | 1.470 ticket toccati |
| Totali sull'intero periodo | coincidono con l'analisi v1.8.83 |

## 6. Scelte di progetto

- [x] **Logica in SQL, non in PHP**: `SdModel` interroga le viste della
      v1.8.82/83. Due definizioni della stessa regola divergono, e alla prima
      divergenza entrambe le risposte sono difendibili
- [x] **Una sola clausola** per pannello, elenchi ed export (lezione v1.8.56)
- [x] Filtro livello sui ticket **toccati**, non su una proprietà esclusiva: un
      ticket lavorato da entrambi compare in entrambi i filtri
- [x] **Colori per significato**: le tre classi «senza risposta» in grigio, ambra
      e rosso — un gradiente le avrebbe fatte leggere come varianti della stessa
      cosa, l'errore corretto nella v1.8.83
- [x] **Due scale** nel grafico: il tasso (1,8–9,7%) su scala comune con i ticket
      (150–423) sarebbe una linea piatta
- [x] **Periodo predefinito = ultimo mese con dati**: il mese corrente
      produrrebbe un pannello vuoto che sembra un guasto
- [x] Degradazione dichiarata: viste mancanti o team vuoto producono un avviso
      con l'azione da compiere, non un errore

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_sd` | 4 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_sd` | 4 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_sd` | 4 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c84` fresco | 574 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c84` | 574 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c84` | 574 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **573 → 574**

## 8. Aperto

- **Gli SLA restano non definiti**: `cm_sd_sla` è predisposta e vuota, e la
  pagina non mostra percentuali di rispetto. Quando le soglie saranno definite
  sulle commesse, serve anche il **tempo netto** — sottraendo gli intervalli in
  attesa del cliente dalla durata totale.
- La `durata_ore` mostrata **comprende le attese del cliente**: è dichiarato sotto
  la tabella, ma resta un dato da leggere con cautela.
- **`tt_ticket` e `tt_ticket_act` non esportate**: senza, mancano gli SLA
  contrattuali e le ore per ticket. La rendicontazione è sui volumi, non
  sull'effort.
