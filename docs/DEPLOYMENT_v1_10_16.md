# DEPLOYMENT — v1.10.16

Pacchetto `update_v1.10.16.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.15) + plugin `integrations/wordpress/pm-ats-1.1.1.zip`.

## PortalManager
Sistema › Console › Aggiornamento → `update_v1.10.16.zip` → Aggiornamento DB (migrazioni mancanti fino a 1.10.16). Manuale:
```powershell
Expand-Archive -Path .\update_v1.10.16.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin: `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_16.sql` (idempotenti). Stop/Start Apache, Ctrl+F5.

## WordPress
Plugin › Aggiungi nuovo › Carica plugin → `pm-ats-1.1.1.zip` → «Sostituisci la versione installata». Impostazioni e segreto restano invariati.
Se il sito è bloccato (429) attendere 15 minuti o eliminare i transient `pm_ats_f_*`.

## Verifica
```
P:\xampp\php\php.exe tools\verify_v1_10_16.php --db=demo_portalmanager --online=25
```
Atteso `0 KO`: 25 test consecutivi riusciti dimostrano l'assenza del blocco 429.
