# DEPLOYMENT — v1.10.18

Pacchetto `update_v1.10.18.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.17) + `integrations/wordpress/pm-ats-1.2.0.zip`.

1. PortalManager: Sistema › Console › Aggiornamento → `update_v1.10.18.zip` → Aggiornamento DB. In alternativa:
   ```powershell
   Expand-Archive -Path .\update_v1.10.18.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
   ```
   Poi in phpMyAdmin eseguire `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_18.sql`. Stop/Start Apache, Ctrl+F5.
2. WordPress: Plugin › Carica plugin → `pm-ats-1.2.0.zip` → Sostituisci.
3. Sito web (WordPress) › **Sincronizza tutto** (una volta): allinea lo stato di tutte le posizioni.
4. Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_18.php --db=demo_portalmanager --online` → `0 KO`.
