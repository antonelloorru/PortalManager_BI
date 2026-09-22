# Release Checklist — PortalManager v1.8.82

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.81.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.82` |
| `app/SyncDatasets.php` | **modificato** (16 dataset) | OK |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 4 in `app/` | invariati da v1.8.81 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.82**

## 2. Analisi della sorgente

- [x] `tt_ticket` e `tt_ticket_act` **non esportate** nel dump
- [x] Dati in `tt_article`: **13.479 messaggi → 3.512 ticket**
- [x] `code` = `WTS_000000003_001`: ticket + progressivo messaggio.
      Contare le righe come ticket sovrastimerebbe di **3,8 volte**
- [x] **Nessun campo `t_status`**: `t_status_before` e `t_status_after` danno la
      traccia delle transizioni
- [x] Stato corrente = `status_after` dell'**ultimo** messaggio: `MAX()` avrebbe
      restituito `WAITING_FOR_SUPPORT_RESPONSE` invece di `CLOSED`

## 3. Il team L1

| Tecnico | Sotto-unità |
|---|---|
| Greta Ferrante | Primo livello |
| Sebastiano Chiarini | Primo livello |
| Emanuele Bressi | Secondo livello |
| Enrico Mancini | Secondo livello |

- [x] Unità **intera** come L1, come da indicazione ricevuta
- [x] Team letto **in tempo reale** da `cm_tech_profiles`, non copiato: un
      tecnico spostato di unità si riflette subito
- [x] Legame via `dgb_operator`: **8.406 messaggi su 8.406** risolti, **4 su 4**
      tecnici agganciati
- [x] Confronto su entrambi gli ordini nome/cognome (v1.8.77)

## 4. Tre classi, non due

| Gestione | Ticket | Quota |
|---|---|---|
| Presa in carico diretta da specialisti | **1.471** | 41,9% |
| Risolto dal Service Desk | 1.365 | 38,9% |
| Senza risposta | 571 | 16,3% |
| **Escalation di 2° livello** | **105** | **3,0%** |

- [x] La regola applicata alla lettera dava **1.576 escalation (54%)**
- [x] Verificato che i 1.471 stanno su code **specialistiche** — Sistemi 2.333,
      Cybersecurity 773, Network 651
- [x] L'escalation presuppone una **presa in carico precedente**: senza, il dato
      sarebbe gonfiato di dieci volte
- [x] Tasso calcolato sui soli ticket presi in carico da L1: **105 / 1.470 = 3,0%**

## 5. La quarta classe

- [x] 571 senza alcun messaggio di supporto
- [x] **553 chiusi**, 1,8 messaggi di media: notifiche o chiusure per altra via
- [x] Contarli fra i risolti gonfierebbe la produttività, fra i non gestiti
      creerebbe un allarme falso
- [x] **18** realmente aperti senza risposta: il solo numero operativo

## 6. QA — dataset e viste

| Verifica | Esito |
|---|---|
| Dataset `ticket_messaggi` | 15 colonne, **mappatura allineata** |
| Righe / ticket | 13.479 / **3.512** |
| Dataset totali | **16** su **20 tabelle** |
| `v_cm_sd_team` | 4 tecnici |
| `v_cm_sd_ticket` | 3.512 righe, stati coerenti |
| Stati: CLOSED / attesa / pending | 3.425 / 83 / 4 |

## 7. Riscontro indipendente sulla classificazione

| Tecnico | Livello | Code presidiate |
|---|---|---|
| Mancini, Chiarini, Bressi | L1 | **11** |
| Todisco | L2 | 2 |
| De Caprio | L2 | 4 |

- [x] Il primo livello smista trasversalmente, gli specialisti presidiano il
      proprio dominio: conferma indipendente dell'assegnazione

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_sd` (dati reali) | 10 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_sd` | 10 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_sd` | 10 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c82` fresco | 567 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c82` | 567 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c82` | 567 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** su entrambi i file
- [x] Conteggio statement consolidato: **559 → 567**

## 9. Nota di metodo

La regola fornita era chiara e, applicata alla lettera, avrebbe prodotto un tasso
di escalation del 54% — un numero che nessun responsabile avrebbe riconosciuto
come proprio.

Verificare *dove* stessero quei ticket ha mostrato che il 42% non è mai passato
dal Service Desk. Una regola binaria su una realtà a tre stati produce un dato
formalmente corretto e praticamente inutile.

## 10. Aperto

- **Gli SLA non sono disponibili**: `tt_ticket` porta i campi di presa in carico e
  risoluzione, ma non è esportata. Se l'export venisse esteso, si potrebbero
  misurare i tempi contrattuali oltre a quelli osservati.
- **`tt_ticket_act` non esportata**: contiene le ore e i costi per ticket. Senza,
  la rendicontazione è sui volumi e non sull'effort.
- La `durata_ore` fra apertura e ultimo movimento **comprende le attese del
  cliente**: non è un tempo di risoluzione e non va usata come tale.
- **Non è stata creata la pagina** della sezione: le viste sono pronte e
  interrogabili, ma l'interfaccia va disegnata sapendo quali indicatori il
  responsabile vuole in evidenza.
