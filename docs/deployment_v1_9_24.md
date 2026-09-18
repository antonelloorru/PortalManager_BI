# PortalManager v1.9.24 — Guida al Deployment

## Prerequisiti
- Apache 2.4.58 + PHP 8.2.12 (compat 8.1.25, 8.0.30) su stack HR (LAN interna).
- Apache 2.4.58 + PHP 8.2.12 su host portale esterno (DMZ). PHP con estensioni:
  `curl`, `openssl`, `fileinfo`, `json`, `mbstring`, `pdo_mysql`.
- MariaDB 10.4.32.
- OpenSSL 3.1.3, curl 8.4.0_6.
- HTTPS attivo su entrambi gli host, cert validi.
- (Raccomandato) reverse proxy con mTLS tra DMZ e LAN.

## Passi lato Gestionale HR
1. Backup DB e cartella applicativa.
2. Estrarre lo ZIP `portalmanager_v1_9_24.zip` in `P:\xampp\htdocs\demo_portalmanager`,
   mantenendo i file esistenti.
3. Accedere come Super Admin → **Configurazione → System Console → Aggiornamento**.
4. Selezionare la migration `sql/migration_v1_9_24.sql`. Eseguire.
5. Verificare via query:
   ```sql
   SELECT version, applied_at FROM pm_migration_sql ORDER BY id DESC LIMIT 3;
   ```
   Deve comparire `1.9.24`.
6. Menu → Sistema → **Chiavi API — Portale Careers** → crea chiave
   (annotare il secret UNA VOLTA SOLA).
7. Copiare `config/api_secrets.sample.php` in `config/api_secrets.php`
   (permessi 0600, non versionare). Inserire la coppia `client_id => secret`.
8. Creare la cartella `uploads/candidates/` con permessi scrittura per l'utente
   Apache; **posizionarla fuori webroot in produzione** e aggiornare
   `app_settings.careers.storage_path` di conseguenza. Il file `.htaccess`
   fornito nega comunque l'accesso diretto se resta sotto webroot.
9. Aggiornare `app_settings.careers.notify_email` con la mail HR.
10. Verificare l'accesso HR alle nuove voci di menu (Recruiting).

## Passi lato Portale Esterno (DMZ)
1. Deployare `public/careers/` sull'host DMZ come document root o sotto `/careers/`.
2. Copiare `bff_config.sample.php` in
   `C:\xampp\config\careers_bff_config.php` (fuori webroot). Popolare
   `PM_API_BASE`, `PM_CLIENT_ID`, `PM_CLIENT_SECRET`.
3. Consentire in outbound il solo host `hr-internal.<dominio>:443`.
4. Verificare `curl -I https://hr-internal.<dominio>/api_public_positions.php`
   (deve rispondere 401 senza header HMAC).

## Test post-installazione
- `GET https://careers.<dominio>/careers/bff.php?op=positions` → 200 con lista.
- `POST /careers/bff.php?op=check_email` con email nota → `known:true`.
- Invio candidatura di test → riferimento visibile, riga in
  `job_applications`, riga in `public_api_audit` con status 201.
- 6° tentativo di invio dallo stesso IP nella giornata → 429.
- File CV `.exe` rinominato in `.pdf` → 415 `cv_bad_mime`.

## Rollback
1. Ripristinare backup pre-upgrade del DB.
2. Revertire i file applicativi (git checkout tag precedente o restore ZIP
   precedente).
3. La migration `1.9.24` crea SOLO tabelle nuove e permessi: il rollback fisico
   può essere omesso. Se necessario:
   ```sql
   DROP TABLE IF EXISTS job_applications, candidate_cv_files, candidates,
     job_positions, public_api_clients, public_api_rate_limit, public_api_audit;
   DROP VIEW IF EXISTS v_public_open_positions;
   DELETE FROM permissions WHERE code IN
     ('manage_job_positions.php','manage_applications.php','manage_public_api_clients.php');
   DELETE FROM pm_migration_sql WHERE version='1.9.24';
   ```
