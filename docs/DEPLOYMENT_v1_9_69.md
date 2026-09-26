# Deployment — v1.9.69
1. Copiare in root: `sso_settings.php`, `auth_microsoft.php`, `login.php`, `r.php`,
   `access_control.php`, `manage_permissions.php`; in `app/`: `Env.php`,
   `Microsoft365Sso.php`, `Router.php`, `MenuManager.php`.
2. Eseguire `sql/migration_v1_9_69.sql` (idempotente, include il permesso di v1.9.68).
3. Sistema → SSO Microsoft 365 / MFA: salvare i dati Entra →
   **1. Diagnostica configurazione** (tutto OK) → **2. Test accesso con Microsoft (MFA)**
   (MFA eseguita, utente trovato) → spuntare "Abilita".
Requisiti: uscita HTTPS del server verso login.microsoftonline.com.
