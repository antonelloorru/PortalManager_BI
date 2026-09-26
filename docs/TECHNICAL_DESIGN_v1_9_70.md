# Technical Design — Scheduler senza cron (v1.9.70)

## Componenti
| Componente | Ruolo |
|---|---|
| `app/SyncRunner.php` | Decisione `isDue()`, `nextRunAt()`, esecuzione `run()` con lock e log. Unico codice di sincronizzazione per worker, CLI e avvio manuale. |
| `app/CronlessScheduler.php` | `tick()` a fine richiesta (throttle 60 s su file temporaneo), `dispatch()` loopback HMAC non bloccante, fallback in-process. |
| `cronless_worker.php` | Endpoint firmato: 202 immediato, poi `SyncRunner::run('cronless')` con `ignore_user_abort`. |
| `app/bootstrap.php` | `register_shutdown_function(['CronlessScheduler','tick'])` per le richieste web. |
| `cron_sync.php` | Wrapper CLI opzionale (modalità "Attività esterna"). |

## Flusso
richiesta utente → pagina inviata → shutdown: tick → (throttle) → isDue → dispatch
→ POST 127.0.0.1/cronless_worker.php (ts, force, sig) → 202 → run(): lock → dataset → log → rilascio lock.

## Schema
`cm_sync_schedule` + `exec_mode` (cronless|os_task), `catchup`, `last_tick_at`,
`last_dispatch_at`, `last_dispatch_note`. Vista `v_cm_sync_schedule_stato`:
`diagnosi` (disattivata / ultima esecuzione fallita / IN RITARDO / mai eseguita / regolare),
`in_esecuzione`, `errori_30gg`.

## Garanzie
Idempotenza (una esecuzione riuscita al giorno), mutua esclusione (lock atomico con
scadenza), backoff dopo errore (30 min), nessun impatto sui tempi di risposta delle pagine
(salvo fallback segnalato), nessun segreto esposto (firma con chiave in `.env.php`).
