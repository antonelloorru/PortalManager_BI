# Release Checklist — v1.9.83
- [x] VERSION = 1.9.83; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.83','migration_v1_9_83.sql')
- [x] Migration idempotente (RUN1/RUN2 err=0), nessun `;` nei commenti SQL
- [x] `php -l` su tutti i file; terminatori di riga originali conservati
- [x] Sincronizzazione idempotente (seconda esecuzione: 0 modifiche); permessi effettivi invariati alla prima
- [x] Pagina nuova Sistema → Sincronizzazione permessi: menu, router (RESTRICTED), catalogo, HARD_GATES = 1
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE, RELEASE_CHECKLIST
