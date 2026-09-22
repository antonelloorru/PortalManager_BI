# Release Checklist — PortalManager v1.8.83

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.82.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.83` |
| `app/Version.php` | modificato | OK |
| 7 ROOT + 5 in `app/` | invariati da v1.8.82 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.83**
- [x] Nessuna risincronizzazione necessaria: la classificazione è ricalcolata
      dalle viste sui dati già presenti

## 2. Scomposizione dei 571 «senza risposta»

| Composizione | Ticket | Chiusi | Messaggi medi |
|---|---|---|---|
| solo note interne | **430** | 426 | 1,2 |
| cliente + note interne | **129** | 127 | 3,7 |
| solo messaggi del cliente | **12** | **0** | 1,1 |

- [x] I primi due gruppi sono **lavoro svolto**: 99% chiusi
- [x] Il terzo è **lavoro non svolto**: 0% chiusi
- [x] Tenerli insieme rendeva invisibile il 2% che conta dentro il 98% che non
      conta

## 3. Da chi non si è avuta risposta

Autori delle note sui 430 «lavorato senza risposta scritta»:

| Livello | Ticket | Note |
|---|---|---|
| L2 | 423 | 454 |
| L1 | 149 | 201 |

- [x] Nuovo campo **`presidio`**: `solo L1`, `solo L2`, `L1 e L2`, `nessuno`
- [x] Conta **tutti** i messaggi note comprese, mentre `gestione` guarda solo
      quelli di supporto: due campi per due domande diverse

## 4. Ordine delle condizioni

- [x] `mai preso in carico` valutato **per primo**: è il caso più restrittivo
      (senza note **e** senza risposte)
- [x] Invertendo l'ordine, i 12 ticket scoperti finirebbero fra i 129 e
      sparirebbero
- [x] `lavorato senza risposta scritta` è l'ultimo perché è il più ampio

## 5. QA — le sei classi sui dati reali

| Gestione | Ticket | Chiusi | Quota |
|---|---|---|---|
| Presa in carico diretta da specialisti | 1.471 | 1.446 | 41,9% |
| Risolto dal Service Desk | 1.365 | 1.326 | 38,9% |
| Lavorato senza risposta scritta | 430 | 426 | 12,2% |
| Cliente senza risposta scritta | 129 | 127 | 3,7% |
| Escalation di 2° livello | 105 | 100 | 3,0% |
| **Mai preso in carico** | **12** | **0** | 0,3% |

Totale **3.512**, invariato.

## 6. QA — la vista dell'azione

| Verifica | Esito |
|---|---|
| `v_cm_sd_scoperti` | **14 righe** |
| di cui mai presi in carico | 12 (0–210 giorni) |
| di cui cliente senza risposta e aperti | 2 (23–81 giorni) |
| Più vecchio | WTS_000001033, Voip, **210 giorni** |

- [x] Selettiva per scelta: includere i 559 chiusi renderebbe la lista
      inutilizzabile, ed è il motivo per cui i 12 erano rimasti nascosti
- [x] Filtro sullo stato per la seconda classe: un ticket chiuso senza risposta
      scritta è un rilievo di qualità, non un'emergenza

## 7. SLA predisposti e non popolati

- [x] `cm_sd_sla` accetta soglie per commessa o coda, `project_code` NULL = default
- [x] **Deliberatamente vuota**: popolarla con valori plausibili produrrebbe una
      percentuale di rispetto SLA che è un giudizio travestito da misura
- [x] `tt_sla` non è esportata nel dump; il portale non ha definizione propria
- [x] Motivo tecnico aggiuntivo: la `durata_ore` comprende le **attese del
      cliente**, quindi non è il tempo da confrontare con un SLA

## 8. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_sd` (dati reali) | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_sd` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_sd` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c83` fresco | 573 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c83` | 573 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c83` | 573 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** (un `;` in commento corretto in corsa)
- [x] Conteggio statement consolidato: **567 → 573**

## 9. Nota di metodo

Un aggregato che mescola casi legittimi e problemi non produce decisioni: 571
«senza risposta» non faceva agire nessuno, e i 12 ticket scoperti da oltre due
mesi restavano invisibili.

La domanda posta — *da chi non si è avuta risposta* — era la chiave: distinguere
chi ha lavorato senza scrivere da chi non ha lavorato affatto separa il 98% che
non richiede nulla dal 2% che richiede un intervento oggi.

## 10. Aperto

- **Il tempo netto di risoluzione** non è calcolato: servirebbe sottrarre gli
  intervalli in `WAITING_FOR_CUSTOMER_RESPONSE` dalla durata totale. È il
  prerequisito per qualunque confronto con un SLA, e va fatto insieme alla
  definizione delle soglie.
- **`tt_ticket` e `tt_ticket_act` restano non esportate**: senza, mancano gli SLA
  contrattuali e le ore per ticket.
- **La pagina della sezione non è stata creata**: le sette viste sono pronte e
  interrogabili. L'interfaccia va disegnata sapendo quali indicatori il
  Responsabile vuole in evidenza.
