# Deployment — v1.9.67 (SSO Microsoft 365, fix unico)
1. Copiare: `app/Microsoft365Sso.php` (in app/), `auth_microsoft.php`, `login.php`,
   `app/Router.php`, `r.php`, `access_control.php`.
2. Eseguire `sql/migration_v1_9_67.sql`.
3. Registrare l'app in Entra ID e compilare `.env.php` (vedi `docs/env.sso.example.php`):
   `SSO_MS_ENABLED=1`, tenant/client/secret/redirect, `MS_REQUIRE_MFA=1`, (opz.) `MS_ACR_VALUE`.
4. Requisiti: PHP openssl + cURL (XAMPP), HTTPS. Match su `users.email` e `employees.business_email`.
