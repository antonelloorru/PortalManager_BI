# Release Checklist — v1.9.85
- [x] VERSION = 1.9.85; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.85','migration_v1_9_85.sql')
- [x] Migration idempotente: RUN1/RUN2 err=0 con mysql CLI e con `sql_split_statements` (SqlConsole), nessun `;` nei commenti
- [x] `php -l` su app/*.php, recruiting_posizioni.php, import_candidates_linkedin.php
- [x] Test parse/analyze sul file `Job_SistemistaAncona.xlsx`: 102/102 associati (codici posizione in formato E9, URL, .0)
- [x] Test bonifica: codici `…E9` → cifre, candidati con ID scientifico ricollegati, candidati già collegati invariati
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE (utente + amministratore), RELEASE_CHECKLIST
