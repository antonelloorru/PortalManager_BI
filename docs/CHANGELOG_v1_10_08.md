# CHANGELOG — v1.10.08 (2026-10-06)

Software 1.10.08 · Schema 1.10.08 · Upgrade `sql/migration_v1_10_08.sql` (cumulativo da 1.10.06)

## Verifica
La sincronizzazione dal DB SOC usava già la stessa logica di connessione della «Connessione al gestionale»:
`SourceDb::configFromRow()` (password AES-256-GCM con APP_SECRET) e `SourceDb::connect()` (stesso DSN, sessione in sola lettura, timeout).
Differivano solo i **parametri salvati**: il DB SOC aveva host, utente e password propri, digitati a parte.
L'errore `1045 Access denied for user 'wetechs-ro'@'…'` indica che la password o l'host salvati per il DB SOC non coincidono con quelli del gestionale.

## Novità
- **DB SOC con server e credenziali del gestionale**: opzione «Usa server e credenziali della Connessione al gestionale (cambia solo il database)».
  Driver, host, porta, utente e password sono letti a ogni esecuzione da `cm_source_db` attiva; del DB SOC restano propri database, schema, timeout, finestra, prefisso e query.
- La migrazione attiva l'opzione sulle connessioni SOC che usano lo stesso utente del gestionale (solo alla prima applicazione).
- **Messaggi d'errore guidati**: 1045/1698 (credenziali), 1044/1049 (database non accessibile, con il GRANT da eseguire).
- «Test connessione» mostra utente@host/database effettivi e l'origine delle credenziali.
- Riquadro DB SOC dello stato pipeline: host effettivo.

## File
`app/SocIngest.php` (resolveSource, connError), `app/soc_sync_actions.php`, `app/soc_sync_panel.php`, `sql/migration_v1_10_08.sql`, `tools/verify_v1_10_08.php`, `VERSION`, `app/Version.php`, docs.
Include tutti i file della v1.10.07.
