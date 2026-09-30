# Release Checklist — v1.9.84
- [x] VERSION = 1.9.84; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.84','migration_v1_9_84.sql')
- [x] Migration idempotente (RUN1/RUN2 err=0), nessun `;` nei commenti SQL
- [x] `php -l` su app/SourceDb.php, app/SyncRunner.php
- [x] Test riconnessione (`alive()` false dopo timeout, true dopo `connect`)
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST (manuali invariati: nessun cambio funzionale lato utente)
