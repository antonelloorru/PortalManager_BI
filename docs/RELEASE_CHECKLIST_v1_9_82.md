# Release Checklist — v1.9.82
- [x] VERSION = 1.9.82; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.82','migration_v1_9_82.sql'); backup dei permessi azzerati
- [x] Migration idempotente (RUN1/RUN2 err=0), nessun `;` nei commenti SQL; non revoca riassegnazioni successive
- [x] `php -l` su tutti i file; terminatori di riga originali conservati (CRLF)
- [x] Menu ruolo 9 verificato: nessuna voce senza permesso effettivo
- [x] Docs: CHANGELOG, DEPLOYMENT (con ripristino), TECHNICAL_DESIGN (audit altri ruoli), RELEASE_CHECKLIST
