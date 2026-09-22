# certV 5.9.0 — Backup applicazione e database

## Funzionalità

Pagina dedicata per il **backup on-demand** del portale, accessibile solo al Super Admin (ruolo 1). Genera un singolo archivio ZIP scaricabile contenente:

- File del portale (PHP, HTML, CSS, JS, immagini, template)
- Dump SQL completo del database (schema + dati)
- Opzionalmente: file di configurazione `.env.php` (con avviso credenziali)
- File `MANIFEST.json` con metadati (data, autore, versione schema)

## Scelta del percorso destinazione

Il backup si salva sul **PC dell'utente** che naviga il portale, non sul server. Tre modalità supportate:

### 1. File System Access API (Chrome/Edge moderni)
Quando l'utente attiva il toggle **"Scelta percorso destinazione"**, il browser apre una vera finestra **"Salva con nome"** dove l'utente può navigare le cartelle del proprio computer e scegliere percorso + nome file. Funziona su Chrome/Edge 86+ e browser basati su Chromium recenti.

### 2. Configurazione browser (universale)
Per Firefox e altri browser: l'utente può configurare il browser per chiedere sempre dove salvare i file:
- **Chrome/Edge**: `Impostazioni → Download → "Chiedi dove salvare ogni file..."`
- **Firefox**: `Impostazioni → File e applicazioni → "Chiedi sempre dove salvare i file"`

### 3. Cartella Download (default)
Senza configurazione speciale, il file finisce nella cartella Download standard del browser.

## Sicurezza

- **Solo Super Admin** può accedere (RBAC ruolo 1)
- **Rate limit**: max 1 backup ogni 60 secondi per utente (file lock in `uploads/.ratelimit/backup_user_<id>.lock`)
- **`.env.php` escluso di default** (contiene chiavi crittografiche e credenziali DB); inclusione opzionale con warning
- **Path traversal neutralizzato**: `realpath` + check prefix su `__DIR__`
- **File temporaneo cancellato** automaticamente al termine del download (`register_shutdown_function`)
- **Audit completo**: ogni richiesta logged in `app_logs` con dimensione e contenuti
- **Cleanup buffer output** prima del download per evitare corruzione del file

## Esclusioni dal backup file

- `uploads/.ratelimit/` (lock files temporanei)
- `uploads/cache/`, `cache/`, `tmp/`
- `logs/`
- `backups/` (backup esistenti del db_upgrade)
- `.git/`, `.svn/`, `.idea/`, `.vscode/`, `node_modules/`
- `installer_disabled.flag`, `.htpasswd`
- File singoli > 100 MB (limite di sicurezza)

## API tecnica del dump SQL

Il dump viene generato **lato PHP via PDO** (no dipendenza da `mysqldump.exe`):

```php
foreach ($tables as $t) {
    fwrite($h, "DROP TABLE IF EXISTS `$t`;\n");
    fwrite($h, $pdo->query("SHOW CREATE TABLE `$t`")->fetch()['Create Table'] . ";\n");
    // INSERT in batch da 100 righe per limitare memoria
    foreach ($pdo->query("SELECT * FROM `$t`") as $row) {
        // quote() per ogni valore, NULL preservato
    }
}
```

Compatibile con phpMyAdmin → Importa per ripristino su nuovo server.

## Procedura di restore

1. Estrai lo ZIP scaricato in una directory web (es. `htdocs/portalbrand_restore/`)
2. La cartella `files/` contiene tutto il portale
3. Importa `database.sql` via phpMyAdmin sul DB target
4. Ricrea `.env.php` (oppure usalo se presente nel backup) con credenziali del nuovo ambiente
5. Verifica permessi su `uploads/` e directory writable

## File del pacchetto

- `system_backup.php` — UI + handler download
- `app/Router.php` — slug `system_backup`
- `header.php` — voce menu sotto Amministrazione
- `sql/migration_v5_9.sql` — permessi RBAC + version bump
- `docs/V5_9_TECHNICAL.md` — questa documentazione
