# Release Checklist — v1.9.96
- [x] VERSION = 1.9.96; migration solo versione; `pm_migration_sql` ('1.9.96','migration_v1_9_96.sql'); RUN1/RUN2 err=0
- [x] `php -l` dir_report.php, password_reset.php
- [x] Test HTTP (USE_PRETTY_URLS=0, login reale): Scheda commerciale «Alessandro Turchi», pulsante Scheda Progetto da entrambe le tabelle → pagina della commessa della riga
- [x] Ricerca nel codice di altre doppie codifiche di url_safe: nessuna residua
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST
