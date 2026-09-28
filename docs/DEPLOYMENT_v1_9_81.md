# Deployment — v1.9.81 (richiede v1.9.80)
1. Copiare in root: `password_reset.php` (nuovo), `login.php`, `r.php`, `access_control.php`.
2. Copiare in `app/`: `PasswordReset.php` (nuovo), `Router.php`, `Session.php`.
3. Eseguire `sql/migration_v1_9_81.sql` (idempotente).
4. Impostare **`app_public_url`** con l'indirizzo pubblico del portale, es. `https://portal.azienda.it/demo_portalmanager`
   (SQL Runner: `UPDATE app_settings SET setting_value='https://…' WHERE setting_key='app_public_url'`).
   Se vuoto, il link usa `SERVER_NAME` di Apache: verificare che sia il nome pubblico.
5. Verificare che l'SMTP sia attivo (Impostazioni → SMTP → invio di prova).
6. Verifica: Login → «Password dimenticata?» → email → link → nuova password → accesso.
7. Per disattivare: `pwd_reset_enabled = 0` (il link sparisce dal login, la pagina mostra un avviso).
