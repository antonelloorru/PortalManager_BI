# DEPLOYMENT — v1.10.38

Pacchetto `update_v1.10.38.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.37).

1. Sistema › Console › Aggiornamento → `update_v1.10.38.zip` → Aggiornamento DB (o `sql/migration_v1_10_38.sql`).
2. Installazione manuale PortalManager: `app/WpAtsConfig.php`, `app/Version.php`, `VERSION`, `integrations/wordpress/pm-ats-1.3.6.zip`.
3. WordPress: caricare `pm-ats-1.3.6.zip` sostituendo il plugin attuale. Le impostazioni sono conservate e migrate allo schema 9.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_38.php --db=demo_portalmanager` → `0 KO`.
