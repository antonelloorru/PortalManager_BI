# Deployment v1.10.04

Prerequisito: v1.10.03 installata.
1. Backup DB e cartella applicazione.
2. Estrarre `update_v1.10.04.zip` nella root (PowerShell: `Expand-Archive update_v1.10.04.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`) oppure Sistema › Aggiornamento.
3. Eseguire `sql/migration_v1_10_04.sql` (SQL Runner o phpMyAdmin). Idempotente.
4. Stop+Start Apache, Ctrl+F5.
5. `php tools/verify_v1_10_04.php --db=<database>` → 30 OK, 0 KO.

File: `VERSION`, `app/Version.php`, `app/DgbModel.php`, `app/DgbSync.php`, `dgb_activities.php`, `sql/migration_v1_10_04.sql`, `tools/verify_v1_10_04.php`, `docs/*_v1_10_04.md`, `update_manifest.json`.
Rollback: ripristino file v1.10.03; la migration non altera strutture (solo `end_at` NULL → fine attività).
