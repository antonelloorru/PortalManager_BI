# Release Checklist — v1.9.80
- [x] VERSION = 1.9.80; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.80','migration_v1_9_80.sql')
- [x] Migration idempotente (RUN1/RUN2 err=0), nessun `;` nei commenti SQL
- [x] `php -l` ok; render verificato su 10 combinazioni di filtri e ruolo Dipendente
- [x] Compatibilità parametri storici (f_br, f_us, f_st)
- [x] Nessuna nuova pagina: menu, Router, permessi invariati
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST
