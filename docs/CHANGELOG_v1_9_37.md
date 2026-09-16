# Release Notes — PortalManager v1.9.37

Data: 2026-09-07
Allineamento versioni: Software 1.9.37 · Schema 1.9.37 · Upgrade 1.9.37

## Priorità — Fix timeout backup aggiornamento
`system_console.php`, fase **Aggiornamento (ZIP) → Applica**.

Il backup pre-aggiornamento include le tabelle DGB (centinaia di MB). Il guard
`set_time_limit(300)` interrompeva il backup a metà, lasciando l'aggiornamento
bloccato. Correzione nella sola pagina `system_console.php`:

- `set_time_limit(0)` + `ini_set('max_execution_time','0')` — rimosso il cap dei 300s.
- `ignore_user_abort(true)` — un reset di browser/proxy non aborta un backup in corso.
- `ini_set('memory_limit','1024M')` — margine per il backup delle tabelle grandi.
- Flush dei buffer di output residui prima di avviare l'applicazione.

Nessuna modifica alla logica di `apply_update()` / `UpdaterCore` (backup DB già in
streaming): l'intervento rimuove il vincolo che causava l'interruzione.

## Schema — Ordinativi Pratix: dimensione `commerciale`
`v_cm_pratix_righe` espone due dimensioni derivate **direttamente dall'entità
commessa** (`cm_projects`):

- `cliente` — `coalesce(clients.name, cm_projects.client_raw, '(non indicato)')` (già presente).
- `commerciale` — `coalesce(cm_projects.commercial_ref, '(non indicato)')` (**nuovo**).

Predispone i filtri statici Commerciale/Cliente in Ordinativi Pratix. La vista
passa da 20 a 21 colonne. Ridefinizione idempotente, nessun dato toccato.

## In arrivo (alla consegna del sorgente pagina)
Patch UI di `ordinativi_pratix.php` + model: i due `<select>` statici
(Commerciale, Cliente) nel pannello filtri, la WHERE condivisa video/export via
`EXISTS` su `v_cm_pratix_righe`, e i pulsanti export server-side `?export=xlsx|csv|pdf`
coerenti coi filtri. Richiede il sorgente reale della pagina e del suo model.

## Contenuto pacchetto
```
VERSION                              1.9.37
system_console.php                   (ROOT)  fix timeout backup
sql/migration_v1_9_37.sql            vista + bump versione + registrazione
sql/upgrade_1_9_35_to_1_9_37.sql     consolidato ultime 2 versioni -> 1.9.37
docs/                                changelog, manuali, deployment, technical design
```

## QA
- `system_console.php`: `php -l` OK.
- `migration_v1_9_37.sql`: RUN1/RUN2 err=0, idempotente.
- `upgrade_1_9_35_to_1_9_37.sql`: RUN1/RUN2 err=0 su DB fermo a schema 1.9.23.
- `;` nei commenti SQL: 0 (compatibile con splitter naive).
- Post-upgrade: `app_version`/`schema_version`/`release_label` = 1.9.37; `commerciale` risolta.
