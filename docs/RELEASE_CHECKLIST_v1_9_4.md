# Release Checklist — PortalManager v1.9.4

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.9.3.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.9.4` |
| `sync_commesse.php` | ROOT, **modificato** | OK |
| `app/Version.php` | modificato | OK |
| 32 file restanti | invariati da v1.9.3 | OK |
| `sql/` × 2, `docs/` × 6 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.9.4**

## 2. Il difetto

- [x] Solo `cron_sync.php` aggiornava `cm_sync_schedule`
- [x] Il riquadro in home segnalava **«IN RITARDO» su dati appena aggiornati**
- [x] Ora tutte e tre le azioni manuali registrano l'esecuzione

## 3. Tre stati, per evitare un guasto silenzioso

| Stato | Cron | Banner home |
|---|---|---|
| `ok` | salta | verde |
| `parziale` | salta | verde |
| **`dataset`** | **esegue** | **invariato** |
| `errore` | esegue | rosso |

- [x] Marcare `parziale` un import di un solo dataset avrebbe **sospeso in
      silenzio la sincronizzazione automatica di quel giorno**
- [x] Nessuna modifica al cron: è la **scelta del valore scritto** a risolvere,
      non una condizione aggiunta
- [x] Verificato contro il codice reale del cron e la query del banner

## 4. Il lock resta al cron

- [x] `registraEsecuzione` **non tocca `lock_owner`**
- [x] Il lock scade dopo 3 ore: una pagina chiusa a metà lascerebbe il cron
      bloccato per un'esecuzione che nessuno segue
- [x] **Rischio accettato e dichiarato**: manuale e automatica possono
      sovrapporsi. Il danno è una doppia lettura, non una corruzione —
      `INSERT ... ON DUPLICATE KEY`

## 5. Difetto intercettato in collaudo

- [x] **`$t0` definito solo in `sync_all`**, non in `sync_db` e `sync_csv`
- [x] `php -l` non lo vede: variabile non definita è sintatticamente valida, e
      avrebbe prodotto una durata negativa di 46 anni
- [x] Il controllo del rendering (v1.8.72) non l'avrebbe visto: questi blocchi
      sono nella parte POST, non nel template
- [x] Corretto; riverificato: **3 blocchi su 3** definiscono `$t0`

## 6. QA sui dati reali

| Verifica | Esito |
|---|---|
| `last_run_at` aggiornato | da «mai» a **2026-08-26 09:43** |
| `last_status` / `last_seconds` | `ok` / **12,7s** |
| `trigger_type` | **`manuale`** |
| Riga di log creata | dataset 16, lette 71.294, nuove 120 |
| Lock dopo l'esecuzione | **NULL — non toccato** |
| Vista `v_cm_sync_schedule_stato` | riflette il nuovo valore |
| `dataset` blocca il cron | **no** |
| `dataset` conta come riuscita | **no** |
| `ok` conta come riuscita | **sì** |
| Tabelle v1.8.75 assenti | **nessuna eccezione** |
| Migration RUN1/RUN2 | 4 stmt, **err=0**, idempotente |
| `;` nei commenti SQL | **0** |

## 7. Nota di metodo

Il difetto principale era l'assenza di un aggiornamento. Il difetto secondario —
lo stato che avrebbe bloccato il cron — sarebbe nato dalla correzione stessa: una
scelta apparentemente ovvia (`parziale` per un aggiornamento parziale) avrebbe
introdotto un guasto peggiore di quello corretto.

Vale la pena chiedersi, di ogni valore scritto, **chi altro lo legge**.

## 8. Aperto

- **Verifica a schermo non eseguita**: il comportamento è verificato sul database
  reale e contro il codice del cron, non aprendo la pagina in un browser.
- Restano gli aperti precedenti: viste dei pannelli su `value_total - actual_cost`
  invece di `margin_total`, pagine mancanti per presidi e redditività, riepiloghi
  cadenzati non implementati.
