# Deployment — v1.9.59
1. Copiare: `app/MenuManager.php`, `app/Router.php`, `app/PratixImporter.php` in `app/`;
   `manage_permissions.php`, `pratix_import.php`, `pratix_orders.php` in root.
2. Eseguire `sql/migration_v1_9_59.sql` (idempotente: DDL+viste+permessi+bump).
3. Ctrl+F5. La voce **Commesse / Progetti → Import Pratix** compare per Super Admin,
   HR Director, Finance. Per altri ruoli: "Permessi ruoli" → abilitare `pratix_import.php`.

Nota: il file .xls richiede PhpSpreadsheet; in alternativa esportare in .xlsx.
