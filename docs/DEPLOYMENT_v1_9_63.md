# Deployment — v1.9.63
1. Copiare in root: `cert_import_omnissa.php`, `manage_permissions.php`; in `app/`: `MenuManager.php`, `Router.php`.
2. Eseguire `sql/migration_v1_9_63.sql` (permessi + bump).
3. Competenze & Formazione → Import certificazioni Omnissa → carica il report .xlsx →
   verifica anteprima (mapping + match dipendente) → Esegui import.
