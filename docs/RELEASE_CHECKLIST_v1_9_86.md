# Release Checklist — v1.9.86
- [x] VERSION = 1.9.86; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.86','migration_v1_9_86.sql')
- [x] Migration idempotente: RUN1/RUN2 err=0 con `sql_split_statements` (SqlConsole), nessun `;` nei commenti
- [x] `php -l` su app/PositionFilter.php, recruiting_posizioni.php, export_positions_xlsx.php, export_positions_pdf.php
- [x] Test SQL di tutti i filtri singoli e combinati, valori non validi scartati (whitelist), ruoli 1/4/5
- [x] Test HTTP (login reale): pagina 200 senza warning PHP, selezioni mantenute, link storici `f_st`/`f_br=0`/`f_pr`, export XLSX filtrato, PDF filtrato
- [x] Verifica visiva: pannello, multi-select con ricerca, conteggi
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE (utente + amministratore), RELEASE_CHECKLIST
