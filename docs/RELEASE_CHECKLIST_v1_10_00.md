# Release Checklist — v1.10.00
- [x] VERSION = 1.10.00, PM_VERSION = 1.10.00, migration aggiorna app_version / schema_version / release_label, `pm_migration_sql` ('1.10.00','migration_v1_10_00.sql')
- [x] Numerazione: 1.10.00 successiva a 1.9.99 (`version_compare` = 1)
- [x] Migration idempotente, nessun `;` nei commenti; RUN1/RUN2 err=0 (Dump 19.80 + v1.9.99)
- [x] `php -l`: app/PrjCalc.php, app/PrjRepo.php, app/Version.php, tools/verify_v1_10_00.php
- [x] `tools/verify_v1_10_00.php`: 43 OK, 0 KO. §9 completo entro ±1 k€: ticket 15.971, 34,7 FTE da ticket, 26,5/55,1 FTE, dotazione, strutturale 4 zone, canone medio, Sostenibile Roma/Firenze/Napoli/Romania, Completa Firenze/Milano
- [x] Prepared statement nativi (`ATTR_EMULATE_PREPARES = false`) usati nel test
- [x] Nessuna scrittura su cm_projects; test in transazione annullata
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE, RELEASE_CHECKLIST; `update_manifest.json`
