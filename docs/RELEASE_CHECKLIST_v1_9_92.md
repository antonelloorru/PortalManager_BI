# Release Checklist — v1.9.92
- [x] VERSION = 1.9.92; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.92','migration_v1_9_92.sql')
- [x] Migration idempotente: RUN1/RUN2 err=0, nessun `;` nei commenti
- [x] `php -l` su app/ItServiceModel.php, it_service.php, manage_projects.php
- [x] XLSX generato e letto: foglio «Giorni per commessa» = Cliente | Descrizione | Commessa | Persone | …
- [x] Commesse / Progetti: intestazioni «Link SP», «Scheda Progetto»; 31 colonne in testata e nelle righe
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST (manuali invariati salvo etichette)
