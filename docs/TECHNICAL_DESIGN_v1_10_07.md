# Technical Design v1.10.07 — Pipeline Service SOC e Unità Organizzativa

## Flusso unico
```
 Inneschi                                       SocSync::run (GET_LOCK pm_soc_sync, cm_soc_sync_runs)
 ─────────                                      ─────────────────────────────────────────────────────
 CronlessScheduler::tick ─ isDue ─► worker task «soc» ─┐   1. cartella di arrivo  soc.inbox_dir/*.xlsx|csv
 SyncRunner::run (giornaliera, in coda) ──────────────┤      SocIngest::importFile (deferFinalize) → archivio/ | scartati/
 cron_soc_sync.php (Utilità di pianificazione) ───────┼─►  2. DB SOC  cm_soc_source_db attiva → SocIngest::importDb
 Sincronizzazione gestionale › SOC: Esegui ora ───────┤   3. SocIngest::rebuild (una volta) → autoMap → assignUnit
 Sincronizzazione gestionale › SOC: Carica file ──────┘      (enqueue nella cartella di arrivo, poi run)
```
Ogni sorgente scrive anche il proprio lotto in `cm_soc_batches`; l'esecuzione complessiva in `cm_soc_sync_runs`
(innesco, esito ok/parziale/errore, durata, file, stato DB, righe, ticket, tecnici assegnati, conflitti, dettaglio per sorgente).
Stato sintetico in app_settings `soc.last_run_at` (orologio del DB), `soc.last_status`, `soc.last_note`.

## isDue
`soc.sync_enabled = 1` · trascorsi `soc.interval_min` minuti da `soc.last_run_at` (TIMESTAMPDIFF sul DB) · almeno una sorgente (file in attesa o DB SOC attivo).
Lo scheduler senza cron controlla al più ogni 60 s; se la sincronizzazione giornaliera è dovuta ha la precedenza (e include la pipeline SOC).

## Unità Organizzativa SOC (SocSync::assignUnit)
| Situazione del dipendente | Azione |
|---|---|
| nessuna riga in cm_tech_profiles | INSERT (unit_id = SOC, valid_from oggi, nota) |
| scheda con unit_id NULL | UPDATE unit_id = SOC (nota accodata) |
| scheda già in SOC | nessuna |
| scheda in altra unità | nessuna, restituita in `conflicts` (forzabile con `$force`) |
Perimetro: `cm_soc_people.employee_id` non nullo e attività negli ultimi 12 mesi (assegnatario/responsabile di ticket, autore di eventi supporto o nota).
Unità: `cm_tech_units.code = soc.uo_code` (default SOC), creata se manca. `cm_tech_profiles` ha una sola unità per dipendente (`uq_tech_emp`).

## Moduli
`app/SocSync.php` (pipeline, isDue, cartella di arrivo, unità) · `app/soc_sync_panel.php` / `app/soc_sync_actions.php` (sottosezione SOC) ·
`sync_commesse.php` (schede Gestionale/SOC) · `app/CronlessScheduler.php`, `cronless_worker.php`, `app/SyncRunner.php` (inneschi) · `cron_soc_sync.php` ·
`app/SocIngest.php` (deferFinalize, trigger) · `service_soc.php`, `app/SocModel.php` (vista: Unità nel Team).
