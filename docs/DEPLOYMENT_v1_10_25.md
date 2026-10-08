# DEPLOYMENT — v1.10.25

Pacchetto `update_v1.10.25.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.24, plugin pm-ats 1.3.4).

1. PortalManager: Sistema › Console › Aggiornamento → `update_v1.10.25.zip` → Aggiornamento DB (o `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_25.sql`).
2. Installazione manuale: `tech_report.php`, `app/TechReport.php`, `app/ItServiceModel.php`, `app/Router.php`, `app/MenuManager.php`, `app/PermissionCatalog.php`, `app/Version.php`, `VERSION`; eseguire `sql/migration_v1_10_25.sql`.
3. Stop+Start Apache, Ctrl+F5.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_25.php --db=demo_portalmanager` → `0 KO`.
5. Controllo: Gestione Commesse › Relazione Tecnici → scheda Tecnici con righe tecnico × codice linea; scheda Rapporti di intervento con drill-down su una commessa ed export.
