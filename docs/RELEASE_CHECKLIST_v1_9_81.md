# Release Checklist — v1.9.81
- [x] VERSION = 1.9.81; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.81','migration_v1_9_81.sql')
- [x] Migration idempotente (RUN1/RUN2 err=0), nessun `;` nei commenti SQL
- [x] `php -l` su tutti i file
- [x] Pagina pubblica registrata in Router::PAGES, r.php, access_control, Session (nessuna voce di menu né permesso RBAC)
- [x] Test: flusso completo, token errato/scaduto/riusato, policy, rate limit, CSRF, chiusura sessioni
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE (utente + amministratore), RELEASE_CHECKLIST
