# DEPLOYMENT — v1.10.15

Pacchetto `update_v1.10.15.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.14, plugin pm-ats 1.1.0).

## Automatico
Sistema › Console › Aggiornamento → `update_v1.10.15.zip` → Aggiornamento DB (migrazioni mancanti 1.10.07 → 1.10.15).

## Manuale (Windows / XAMPP)
```powershell
Expand-Archive -Path .\update_v1.10.15.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin › `demo_portalmanager` › SQL, in ordine: `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_15.sql` (idempotenti). Stop/Start Apache, Ctrl+F5.
Permessi: concedere **export** su *Report direzionale* ai ruoli che devono scaricare i file.

## Verifica
```
P:\xampp\php\php.exe tools\verify_v1_10_15.php --db=demo_portalmanager --out=P:\xampp\tmp\report_dir
```
Atteso `0 KO`.
