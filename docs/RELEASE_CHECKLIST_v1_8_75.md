# Release Checklist — PortalManager v1.8.75

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.74.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.75` |
| `cron_sync.php` | ROOT, **NUOVO** | OK |
| `sync_commesse.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 6 ROOT + 5 in `app/` | invariati da v1.8.74 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.75**

## 2. Architettura

- [x] **Decisione nello script, avvio in Windows**: l'attività di pianificazione
      è un battito regolare, la logica sta dove si cambia da interfaccia
- [x] L'orario si modifica dal portale senza toccare Windows
- [x] Scartato il trigger sul caricamento pagina: bloccherebbe la pagina
      dell'utente per minuti, e in un giorno senza accessi non girerebbe

## 3. QA — logica decisionale

| Scenario | Esito |
|---|---|
| Ore 01:30, previsto 02:00 | troppo presto |
| Ore 02:00 / 03:30 / 04:00 (finestra 120) | **esegue** |
| Ore 04:30 / 23:00 | finestra chiusa |
| Domenica con giorni feriali | giorno non previsto |
| Lunedì con giorni feriali | esegue |
| Già eseguita **ok** oggi | non riesegue |
| **Fallita** oggi, ora nella finestra | **riprova** |
| Eseguita ieri, oggi nella finestra | esegue |
| Disattivata, dentro finestra | disattivata |
| Disattivata + `--force` | esegue |
| Fuori finestra + `--force` | esegue |

- [x] La condizione di ripetizione include lo **stato**, non solo la data: un
      errore transitorio non richiede intervento manuale
- [x] `parziale` trattato come successo: ripetere tutto per un dataset costerebbe
      minuti con guadagno incerto

## 4. QA — lock

| Verifica | Esito |
|---|---|
| Primo processo acquisisce | **SI** |
| Secondo processo acquisisce | **NO — bloccato** |
| Dopo la scadenza | **SI — non resta bloccato** |

- [x] Acquisizione con `UPDATE` condizionato: atomica senza transazioni esplicite
- [x] **Scadenza a 3 ore**: un processo ucciso non congela la pianificazione
- [x] `register_shutdown_function` rilascia anche su errore fatale

## 5. Scelte di progetto

- [x] `unset($rows)` fra un dataset e l'altro: senza, il picco sarebbe la somma
      invece del massimo (36 MB per le sole allocazioni, misurati in v1.8.67)
- [x] Rollback su ogni errore di dataset: una transazione appesa farebbe fallire
      tutti i successivi (difetto della v1.8.64)
- [x] `--dry-run` **non aggiorna** l'ultima esecuzione: una prova non deve far
      saltare la sincronizzazione vera del giorno
- [x] Codice di uscita **0** per «non era il momento»: è la situazione normale in
      23 esecuzioni su 24, segnalarla come errore riempirebbe la cronologia di
      falsi allarmi
- [x] `PHP_SAPI !== 'cli'` → 403: il file sta nella ROOT ed è raggiungibile via
      web, senza il controllo chiunque potrebbe avviare la sincronizzazione

## 6. Diagnosi

- [x] `v_cm_sync_schedule_stato` con diagnosi in chiaro
- [x] **IN RITARDO** oltre 36 ore dall'ultima esecuzione: una pianificazione rotta
      va segnalata, non scoperta guardando dati vecchi
- [x] Storico delle ultime dieci esecuzioni nel riquadro
- [x] Errori degli ultimi 30 giorni come contatore

## 7. Quality Assurance SQL

| Test | Strumento | DB | Esito |
|---|---|---|---|
| Migration RUN1 | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN2 (idempotenza) | tokenizer reale | `pm_demo` | 8 stmt, **err=0** |
| Migration RUN3 | splitter naive | `pm_demo` | 8 stmt, **err=0** |
| Consolidato RUN1 | splitter naive | `pm_c75` fresco | 520 stmt, **err=0** |
| Consolidato RUN2 (idempotenza) | splitter naive | `pm_c75` | 520 stmt, **err=0** |
| Consolidato RUN3 | tokenizer reale | `pm_c75` | 520 stmt, **err=0** |

- [x] `grep -c '^[[:space:]]*--.*;'` = **0** (un `;` in commento corretto in corsa)
- [x] Conteggio statement consolidato: **514 → 520**

## 8. Aperto

- **Il lock scade dopo 3 ore.** Se i volumi crescessero al punto che una
  sincronizzazione superi quel tempo, una seconda potrebbe partire. Va rivisto se
  la durata tipica si avvicinasse alle 2 ore.
- Non c'è **notifica** in caso di fallimento: la diagnosi va guardata. Una email
  o un avviso in home sarebbe il passo successivo.
- L'attività di Windows va creata a mano: non esiste un modo per il portale di
  configurarla da solo senza privilegi amministrativi sul server.
