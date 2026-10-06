# DEPLOYMENT — v1.10.10

Pacchetto `update_v1.10.10.zip` cumulativo da 1.10.06 (include v1.10.07, v1.10.08, v1.10.09).

## Automatico
Sistema › Console › Aggiornamento → `update_v1.10.10.zip` → Aggiornamento DB (migrazioni mancanti 1.10.07 → 1.10.10).

## Manuale (Windows / XAMPP)
```powershell
Expand-Archive -Path .\update_v1.10.10.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin › `demo_portalmanager` › SQL, in ordine: `sql/migration_v1_10_07.sql`, `sql/migration_v1_10_08.sql`, `sql/migration_v1_10_09.sql`, `sql/migration_v1_10_10.sql` (idempotenti).
Stop/Start Apache, Ctrl+F5.

## Post-installazione
1. Utente DB SOC con SELECT su `tt_ticket` e `tt_category`.
2. Sincronizzazione gestionale › SOC › **Esegui ora**.
3. Verifica:
```
P:\xampp\php\php.exe tools\verify_v1_10_10.php --db=demo_portalmanager --source=1
```
Atteso `0 KO` e «DB SOC: categoria disponibile».
