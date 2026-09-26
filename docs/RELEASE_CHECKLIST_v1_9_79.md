# Release Checklist — v1.9.79
- [x] VERSION = 1.9.79; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.79','migration_v1_9_79.sql')
- [x] Migration idempotente (RUN1/RUN2 err=0), nessun `;` nei commenti SQL
- [x] `php -l` su tutti i file
- [x] Causa corretta alla fonte (SyncDatasets) + recupero storico + collegamento automatico post-sync/import
- [x] Verifica su dump di produzione: settembre smart working 0 → 41, reperibilità 0 → 9, fascia non rilevata 1.356 → 0
- [x] Nessuna nuova pagina: menu, Router, permessi invariati
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST
