# DEPLOYMENT — v1.10.08

Pacchetto `update_v1.10.08.zip` cumulativo da 1.10.06 (contiene anche la v1.10.07).

## Automatico
Sistema › Console › Aggiornamento → carica `update_v1.10.08.zip` → applica → Aggiornamento DB (esegue `migration_v1_10_07.sql` se mancante, poi `migration_v1_10_08.sql`).

## Manuale (Windows / XAMPP)
```powershell
Expand-Archive -Path .\update_v1.10.08.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force
```
phpMyAdmin › `demo_portalmanager` › SQL: `sql/migration_v1_10_07.sql` (se non applicata), poi `sql/migration_v1_10_08.sql`. Idempotenti.

## Verifica
```
P:\xampp\php\php.exe tools\verify_v1_10_08.php --db=demo_portalmanager --connect=1
```
Atteso: `Esito: N OK, 0 KO` e «Connessione reale al DB SOC ✔».

## Configurazione post-installazione
Sincronizzazione gestionale › SOC › Connessione: spuntare «Usa server e credenziali della Connessione al gestionale», indicare il database SOC, Salva, Test connessione.
