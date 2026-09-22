# Deployment — v1.9.65
1. Copiare `manage_employees.php` ed `employee_profile.php` in root.
2. Eseguire `sql/migration_v1_9_65.sql` (bump).
3. Anagrafica → nuovo/modifica dipendente: l'Email aziendale non è più precompilata con
   l'email dell'utente loggato. Ctrl+F5 per svuotare la cache.
