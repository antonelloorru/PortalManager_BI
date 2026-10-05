# Release Checklist — v1.9.98
- [x] VERSION = 1.9.98; migration aggiorna versione; `pm_migration_sql` ('1.9.98','migration_v1_9_98.sql'); RUN1/RUN2 err=0; nessun `;` nei commenti
- [x] `php -l` app/DgbModel.php, dgb_activities.php
- [x] Test modello: 7 combinazioni, Σ ore dettaglio = hoursBreakdown; Σ attività ≤ KPI (attività senza incaricati escluse)
- [x] Test HTTP con login reale: 4 combinazioni filtri, totali, export XLSX dettaglio, nessun warning PHP
- [x] Link v1.9.97 (mode=sede/remoto/smart, linee=codice) compatibili
- [x] Nessun cambio a permessi, menu, Router, schema
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE, RELEASE_CHECKLIST
