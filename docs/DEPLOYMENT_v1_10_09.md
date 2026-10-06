# DEPLOYMENT — v1.10.09

Pacchetto `update_v1.10.09.zip` cumulativo da 1.10.06 (include v1.10.07 e v1.10.08).

## Automatico
Sistema › Console › Aggiornamento → `update_v1.10.09.zip` → Aggiornamento DB (migrazioni mancanti fra `migration_v1_10_07.sql`, `migration_v1_10_08.sql`, `migration_v1_10_09.sql`).

## Manuale (Windows / XAMPP)
```powershell
Expand-Archive -Path .\update_v1.10.09.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin › `demo_portalmanager` › SQL, in ordine: `sql/migration_v1_10_07.sql`, `sql/migration_v1_10_08.sql`, `sql/migration_v1_10_09.sql` (idempotenti).
Stop/Start Apache, Ctrl+F5.

## Post-installazione
1. Sincronizzazione gestionale › SOC › **Esegui ora**.
2. Verifica:
```
P:\xampp\php\php.exe tools\verify_v1_10_09.php --db=demo_portalmanager
```
Atteso `0 KO`. In alternativa alla pipeline: `--rebuild=1` ricostruisce ticket e abbinamenti.
