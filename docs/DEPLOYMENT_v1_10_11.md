# DEPLOYMENT — v1.10.11

Pacchetto `update_v1.10.11.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.10).

## Automatico
Sistema › Console › Aggiornamento → `update_v1.10.11.zip` → Aggiornamento DB (migrazioni mancanti 1.10.07 → 1.10.11).

## Manuale (Windows / XAMPP)
```powershell
Expand-Archive -Path .\update_v1.10.11.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin › `demo_portalmanager` › SQL, in ordine: `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_11.sql` (idempotenti). Stop/Start Apache, Ctrl+F5.

## Verifica
```
P:\xampp\php\php.exe tools\verify_v1_10_11.php --db=demo_portalmanager
```
Atteso `0 KO` («Totali = Relazione di Servizio IT sugli stessi operatori»).
