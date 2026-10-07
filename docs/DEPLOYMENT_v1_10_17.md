# DEPLOYMENT — v1.10.17

Pacchetto `update_v1.10.17.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.16, plugin `pm-ats-1.1.1.zip`).

Sistema › Console › Aggiornamento → `update_v1.10.17.zip` → Aggiornamento DB. In alternativa a mano:
```powershell
Expand-Archive -Path .\update_v1.10.17.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin: `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_17.sql`. Stop/Start Apache, Ctrl+F5.
WordPress: se non già fatto, caricare `pm-ats-1.1.1.zip`.

Verifica: `P:\xampp\php\php.exe tools\verify_v1_10_17.php --db=demo_portalmanager --online=25` → `0 KO` a sito raggiungibile. Senza `--online` vanno verificati solo i componenti.
