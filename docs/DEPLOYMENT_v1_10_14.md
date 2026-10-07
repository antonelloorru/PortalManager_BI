# DEPLOYMENT — v1.10.14

Pacchetto `update_v1.10.14.zip` cumulativo da 1.10.06 (include v1.10.07 → v1.10.13) + plugin `integrations/wordpress/pm-ats-1.1.0.zip`.

## PortalManager — automatico
Sistema › Console › Aggiornamento → `update_v1.10.14.zip` → Aggiornamento DB (migrazioni mancanti 1.10.07 → 1.10.14).

## PortalManager — manuale (Windows / XAMPP)
```powershell
Expand-Archive -Path .\update_v1.10.14.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin › `demo_portalmanager` › SQL, in ordine: `sql/migration_v1_10_07.sql` … `sql/migration_v1_10_14.sql` (idempotenti). Stop/Start Apache, Ctrl+F5.
`.env.php` deve essere scrivibile dall'utente di Apache (segreto `PM_WPATS_SECRET`).

## WordPress
Plugin › Aggiungi nuovo › Carica plugin → `pm-ats-1.1.0.zip` → «Sostituisci la versione installata». Al primo caricamento: migrazione impostazioni 1.0.0 → 1.1.0 (storico in Versione e manutenzione). Nuova installazione: wizard automatico.
Manuale: copiare la cartella `pm-ats/` in `wp-content/plugins/` e attivare.

## Ordine consigliato
1. PortalManager 1.10.14 (compatibile anche con plugin 1.0.0). 2. Plugin 1.1.0. 3. PortalManager › Sito web — Impostazioni › Test connessione (compatibilità OK).

## Verifica
```
P:\xampp\php\php.exe tools\verify_v1_10_14.php --db=demo_portalmanager --online
```
Atteso `0 KO`.
