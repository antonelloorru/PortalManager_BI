# TECHNICAL DESIGN — v1.9.84

## Flusso SyncRunner (pianificato)
```
connect(source) ─┬─ per ogni dataset: fresh() → readSource → writeRows (locale, lungo)
                 └─ se reconcile=1, per ogni dataset: fresh() → reconcile
                                        └─ errore «connessione persa» → rollback → connect → 1 retry
```
- `fresh()`: `SourceDb::alive()`; se falso riconnette con la stessa configurazione (`configFromRow`, password decifrata una volta).
- `$lost(e)`: regex su messaggio `gone away | Lost connection | 2006 | 2013 | server closed`.
- La connessione sorgente resta in sola lettura (impostazioni di sessione riapplicate a ogni `connect`).

## Componenti
| File | Modifica |
|---|---|
| app/SourceDb.php | timeout di sessione MySQL; `alive(): bool` |
| app/SyncRunner.php | riconnessione prima di lettura/riconciliazione, retry singolo, timeout sessione portale |

## Schema ER
Invariato.

## Test
- `alive()` su sessione con `wait_timeout=2` dopo 4 s → `false`; nuova `connect()` → `true`; timeout di sessione applicato = 28800.
