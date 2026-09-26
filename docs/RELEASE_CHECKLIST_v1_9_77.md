# Release Checklist — v1.9.77
- [x] VERSION = 1.9.77; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.77','migration_v1_9_77.sql')
- [x] Migration idempotente (RUN1/RUN2 err=0), nessun `;` nei commenti SQL
- [x] `php -l` su it_service.php, app/ItServiceModel.php, app/it_service_print.php
- [x] Filtro applicato a tutti i dataset (vista IT, costi, giorni, DGB, AJAX, stampa, DOCX, XLSX)
- [x] Parametri preparati; input validato (`normContratti`)
- [x] Nessuna nuova pagina: menu, Router, permessi invariati
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST
