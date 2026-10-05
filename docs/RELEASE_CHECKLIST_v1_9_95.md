# Release Checklist — v1.9.95
- [x] VERSION = 1.9.95; migration aggiorna versione; `pm_migration_sql` ('1.9.95','migration_v1_9_95.sql'); RUN1/RUN2 err=0
- [x] `php -l` app/DirModel.php, dir_report.php
- [x] Test HTTP con login reale: «Commesse da presidiare» 13 colonne (Commessa, Link SP, Scheda Progetto, …), «Valore ordini per competenza» 11 colonne; intestazioni e celle allineate; nessun warning
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, RELEASE_CHECKLIST
