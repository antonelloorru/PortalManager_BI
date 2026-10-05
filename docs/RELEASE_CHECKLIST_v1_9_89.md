# Release Checklist — v1.9.89
- [x] VERSION = 1.9.89; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.89','migration_v1_9_89.sql')
- [x] Migration idempotente: RUN1/RUN2 err=0 con `sql_split_statements` (SqlConsole), nessun `;` nei commenti
- [x] `php -l` su app/ItServiceModel.php, app/it_service_print.php, it_service.php
- [x] Test: ago e set 2026, ore per classe KPI = dettaglio = giorni per persona; non valorizzate = somma del dettaglio
- [x] Test HTTP con login reale: pagina senza warning, XLSX e Word generati, stampa
- [x] Verifica visiva: dettaglio per linea di servizio, giorni per persona, dettaglio non valorizzate
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE (utente + amministratore), RELEASE_CHECKLIST
