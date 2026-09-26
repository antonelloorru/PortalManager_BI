# Deployment — v1.9.68
1. Copiare in root: `sso_settings.php`, `auth_microsoft.php`, `login.php`, `r.php`,
   `access_control.php`, `manage_permissions.php`; in `app/`: `Env.php`,
   `Microsoft365Sso.php`, `Router.php`, `MenuManager.php`.
2. Eseguire `sql/migration_v1_9_68.sql`.
3. Ctrl+F5 → **Sistema → SSO Microsoft 365 / MFA**: compilare i dati Entra, spuntare
   "Richiedi MFA", salvare, poi spuntare "Abilita".
4. La cartella dell'applicazione deve essere scrivibile da Apache (per aggiornare `.env.php`).
