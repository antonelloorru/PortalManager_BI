# Release Checklist — v1.9.93
- [x] VERSION = 1.9.93; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.93','migration_v1_9_93.sql'); RUN1/RUN2 err=0; nessun `;` nei commenti
- [x] `php -l` manage_projects.php
- [x] Test HTTP con login reale: barra automatica assente, pannello «Filtri di ricerca» presente, 1.144 righe, nessun warning
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST
