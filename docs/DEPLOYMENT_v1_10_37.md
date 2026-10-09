# DEPLOYMENT — v1.10.37

Pacchetto `update_v1.10.37.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.36).

1. Sistema › Console › Aggiornamento → `update_v1.10.37.zip` → Aggiornamento DB (o `sql/migration_v1_10_37.sql`).
2. Installazione manuale PortalManager: `app/WpAtsConfig.php`, `app/Version.php`, `VERSION`, `integrations/wordpress/pm-ats-1.3.5.zip`.
3. WordPress: caricare `integrations/wordpress/pm-ats-1.3.5.zip`, sostituendo il plugin attuale. Le impostazioni sono conservate e la nuova chiave è aggiunta automaticamente.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_37.php --db=demo_portalmanager` → `0 KO`.
