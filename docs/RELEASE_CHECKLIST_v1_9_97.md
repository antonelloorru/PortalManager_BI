# Release Checklist — v1.9.97
- [x] VERSION = 1.9.97; migration aggiorna versione; `pm_migration_sql` ('1.9.97','migration_v1_9_97.sql'); RUN1/RUN2 err=0; nessun `;` nei commenti
- [x] `php -l` app/DgbModel.php, dgb_activities.php
- [x] Test modello (settembre 2026): 17 casi, count = KPI per ogni filtro, valori non validi scartati
- [x] Test HTTP con login reale: 15 select con widget unico, badge filtri, export XLSX con i filtri nel link, vista giornaliera, nessun warning
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE, RELEASE_CHECKLIST
