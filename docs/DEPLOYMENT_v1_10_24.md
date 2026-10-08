# DEPLOYMENT — v1.10.24

Pacchetto `update_v1.10.24.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.23, plugin pm-ats 1.3.4).

1. PortalManager: Sistema › Console › Aggiornamento → `update_v1.10.24.zip` → Aggiornamento DB (o `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_24.sql` dal SQL Runner).
2. Installazione manuale: copiare `cm_search.php`, `app/CmSearch.php`, `app/Router.php`, `app/MenuManager.php`, `app/PermissionCatalog.php`, `app/Version.php`, `VERSION`; eseguire `sql/migration_v1_10_24.sql`.
3. Stop+Start Apache, Ctrl+F5.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_24.php --db=demo_portalmanager` → `0 KO`.
5. Controllo: Gestione Commesse › Ricerca → «Tutto il database» mostra un riquadro per archivio; un archivio esporta in CSV/XLSX/DOCX/PDF.
