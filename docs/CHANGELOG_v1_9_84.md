# CHANGELOG — v1.9.84

## Fix — Sincronizzazione automatica: «MySQL server has gone away» (2006) in riconciliazione

**Sintomo**: la sincronizzazione pianificata termina con tutte le riconciliazioni in errore
(`riconciliazione divisioni/clienti/commesse/costi_fascia/…: SQLSTATE[HY000]: General error: 2006`),
mentre la stessa operazione manuale va a buon fine.

**Causa**: `SyncRunner` apre UNA connessione al gestionale e la riusa per tutto il ciclo.
Dopo la lettura di ogni dataset il portale scrive le righe in locale (≈410.000 righe, minuti):
la connessione verso la sorgente resta inattiva oltre il `wait_timeout` del server remoto, che la chiude.
Le letture successive (in particolare la fase di riconciliazione, eseguita dopo l'ultimo dataset)
trovano la connessione chiusa. La sincronizzazione manuale (`sync_commesse.php`) esegue ogni dataset
in una richiesta HTTP separata con connessione nuova, quindi non supera mai il timeout.

**Correzione**
- `app/SourceDb.php`
  - sessione MySQL/MariaDB sorgente: `wait_timeout = 28800`, `net_read_timeout = 600`, `net_write_timeout = 600`
    (best-effort: se non consentito si prosegue);
  - nuovo metodo `alive()` (ping `SELECT 1`).
- `app/SyncRunner.php`
  - verifica/riconnessione (`$fresh`) prima di ogni `readSource()` e di ogni `reconcile()`;
  - riconciliazione: su errore di connessione persa (2006/2013/«gone away»/«Lost connection») rollback,
    riconnessione e **un solo** nuovo tentativo; gli altri errori restano segnalati come prima;
  - stessi timeout di sessione sulla connessione del portale.
- Il log dell'esecuzione riporta le riconnessioni effettuate.

**Schema DB**: invariato (migration solo versione).

## Nota di sequenza
Basata su `main` (v1.9.81). Le branch v1.9.82 (fix menu RBAC) e v1.9.83 (auto-sync RBAC) toccano file diversi:
merge nell'ordine 1.9.82 → 1.9.83 → 1.9.84 senza conflitti su codice (conflitto solo su `VERSION`: tenere 1.9.84).
