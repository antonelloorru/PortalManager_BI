# Deployment v1.10.05

Prerequisito: PortalManager v1.10.04 installato; WordPress ≥ 6.0 con PHP ≥ 8.0 sul sito; HTTPS sul sito.

## A. PortalManager
1. Backup DB e cartella applicazione.
2. Estrarre `update_v1.10.05.zip` nella root (Sistema › Aggiornamento oppure PowerShell
   `Expand-Archive update_v1.10.05.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`).
3. Eseguire `sql/migration_v1_10_05.sql` (SQL Runner o phpMyAdmin). Idempotente.
4. Stop+Start Apache, Ctrl+F5.
5. Verificare che PHP abbia `curl` e `openssl` (XAMPP: attivi) e i certificati CA (`curl.cainfo` in php.ini o File CA nella pagina).
6. `php tools/verify_v1_10_05.php --db=<database>` → nessun KO.

## B. Sito WordPress
1. Caricare e attivare `integrations/wordpress/pm-ats-1.0.0.zip` (Plugin › Carica plugin).
2. Impostazioni › Permalink: qualunque struttura diversa da «Semplice» (altrimenti usare l'URL `…/?rest_route=/pm-ats/v1`).
3. Lavora con noi › Impostazioni: generare il segreto, IP consentiti, pagina elenco, privacy, notifiche.
4. Nella pagina «Lavora con noi» aggiungere `[pm_ats_jobs]`.
5. Se il sito usa cache di pagina: escludere `/wp-json/pm-ats/` e `wp-admin/admin-post.php` (le pagine pubbliche possono restare in cache: il token del modulo viene rinnovato via JavaScript). Se c'è un WAF, consentire gli header `X-PM-*` e il metodo POST verso `/wp-json/pm-ats/v1/sync/*`.

## C. Collegamento
1. PortalManager › Recruiting › Sito web (WordPress) › Configurazione: URL, client ID, segreto, Sincronizzazione attiva → Salva.
2. Test connessione → Sincronizza tutto → verificare le posizioni sul sito.
3. Pianificare `cron_wp_ats.php` ogni 15 minuti (vedi Manuale Amministratore §3).
4. `php tools/verify_v1_10_05.php --db=<database> --live`.

## Rete
Solo traffico in uscita da PortalManager verso il sito (443). Nessuna porta in ingresso.

## Rollback
PortalManager: ripristino file v1.10.04 (le tabelle `wp_ats_*` possono restare). Sito: disattivare il plugin (i dati restano finché non si disinstalla con «Elimina… alla disinstallazione»).
