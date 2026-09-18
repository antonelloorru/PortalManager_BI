# Guida Operativa Applicazione Hotfix — PortalManager v1.9.53

## 1. Riepilogo Hotfix v1.9.53

La release **v1.9.53** introduce la correzione di bug bloccanti e la messa in sicurezza urgente del portale, con particolare attenzione alla risoluzione della causa del **blocco/timeout del backup pre-aggiornamento**:

| Componente / File | Problema Rilevato | Soluzione Applicata |
|---|---|---|
| `app/UpdaterCore.php` | **Blocco Timeout Backup**: il backup ciclava su 124 viste SQL complesse e scansionava file ZIP ricorsivi | Filtrate solo le tabelle base (`WHERE Table_type = 'BASE TABLE'`), escluse viste SQL pesanti dal dump dati, esclusa cartella `Dump/` e archivi `.zip`, esteso timeout a 300s |
| `api_public_positions.php` | **Fatal Error**: include errato di `bootstrap.php`<br>**Crash SQL 500**: `$total` conteggiato senza binding su filtri | Include corretto su `app/bootstrap.php`; query di conteggio parametrizzata con `prepare()` e `$bind` |
| `api_public_check_email.php` | **Fatal Error**: include errato di `bootstrap.php` | Include corretto su `app/bootstrap.php` |
| `api_public_apply.php` | **Fatal Error**: include errato di `bootstrap.php`<br>Firma HMAC falliva su upload CV | Include corretto su `app/bootstrap.php`; aggiunto supporto verifica firma multipart in `PublicApiAuth.php` |
| `download_cv.php` | **Crash multipli**: funzione `require_login()` inesistente; query su tabelle inesistenti (`job_applications`, `candidate_cv_files`) | Riscritto completamente: controllo sessione nativo, allineamento a schema reale `candidate_applications` e `candidate_documents`, protezione path traversal |
| `.htaccess` | **Data Breach**: chiunque poteva scaricare dump ZIP completi del DB dal webroot | Blocco HTTP 403 immediato su `.zip`, `.tar`, `.gz`, `.7z` e su script di emergenza |
| `Config.php` | Leak informazioni interne e link a `diag.php` in caso di errore DB | Sanitizzato messaggio di errore e rimosso link a `diag.php` |
| `diag.php`, `reset_admin.php`, `fix_password.php` | Pagine aperte che consentivano il reset non autenticato della password di amministratore | Aggiunto blocco `Super Admin` (ruolo 1) e blocco HTTP da `.htaccess` |
| `.gitignore` & Indice Git | File `.env.php` e dump ZIP erano tracciati su GitHub | Rimosso `.env.php` e dump dall'indice Git; aggiornato `.gitignore` |
| `tools/cli_backup.php` / `tools/safe_backup.ps1` | Mancanza di un tool CLI per eseguire backup veloci senza i limiti del web server | **Nuovo**: tool CLI per backup istantaneo di filesystem e DB senza timeout |

---

## 2. Perché il Backup Pre-Aggiornamento andava in Blocco per Timeout?

L'analisi del codice di `UpdaterCore.php` (utilizzato sia da `system_console.php` che da `system_update.php`) ha rivelato che la procedura di backup eseguiva:

```php
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $st = $pdo->query("SELECT * FROM `$t`");
    ...
}
```

### Le 2 Cause del Blocco:
1. **Scansione delle 124 Viste SQL**: `SHOW TABLES` in MySQL restituisce sia le tabelle fisiche sia **tutte le 124 viste SQL**. Il ciclo eseguiva `SELECT * FROM` su ciascuna vista analitica (che calcolano riga per riga metriche di commessa, contratti e marginalità con 4-5 livelli di join). Questo causava una serie di Full Table Scan che esaurivano il `max_execution_time` di Apache/PHP (30-60 secondi), provocando il blocco 504 o la chiusura forzata del processo.
2. **Inclusione Ricorsiva dei File ZIP**: Lo scanner dei file non escludeva la cartella `Dump/` né i 4 file `.zip` già presenti nella root (~88 MB di archivi contenenti oltre 500 MB di dati), saturando la memoria e il disco durante la creazione dell'archivio zip.

### Come è stato Risolto:
1. **Nel codice di `UpdaterCore.php`**: la query ora filtra **soltanto le tabelle dati reali**:
   ```php
   $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN, 0);
   ```
   Inoltre la cartella `Dump/` e tutti i file `.zip`, `.tar`, `.sql` sono esplicitamente esclusi dallo zip dei sorgenti.
2. **Creato lo script CLI dedicato `tools/safe_backup.ps1`**: permette di effettuare il backup completo da terminale con tempo di esecuzione illimitato (`set_time_limit(0)`).

---

## 3. Procedura di Backup Pre-Aggiornamento (Zero-Timeout)

Prima di effettuare qualsiasi modifica o aggiornamento in produzione, eseguire il backup seguendo **una delle seguenti modalità**.

### Modalità A (Consigliata via Terminale / PowerShell):
Apri PowerShell sul server (o nella cartella del progetto) ed esegui:

```powershell
cd "G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI"
powershell -ExecutionPolicy Bypass -File tools\safe_backup.ps1
```

Oppure direttamente con PHP:
```powershell
C:\xampp\php\php.exe tools\cli_backup.php
```

**Output atteso (completamento in 3-8 secondi senza timeout):**
```
=======================================================
  PortalManager — CLI Fast Backup Tool (Zero-Timeout)  
=======================================================
Root applicazione: G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI
Cartella backup:   G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI/uploads/backups/
Versione corrente: 1.9.53
Timestamp:         20260917_130500

1. Creazione backup filesystem in corso...
   [OK] File archiviati: 258 file in 1.4s (3.2 MB) -> backup_files_v1.9.53_20260917_130500.zip

2. Creazione backup database in corso...
   Tabelle dati da esportare: 166
   Viste SQL da preservare DDL: 124 (dati non esportati per evitare timeout)
   [OK] Database esportato: 3840 record in 2.1s (1.8 MB) -> backup_db_v1.9.53_20260917_130500.sql

=======================================================
  Backup completato con successo!
=======================================================
```

### Modalità B (Via Web Console dopo l'aggiornamento di `UpdaterCore.php`):
Accedi da browser come Super Admin su `system_console.php?tab=update`:
- Il backup pre-aggiornamento integrato utilizzerà il nuovo codice ottimizzato e si concluderà in pochi secondi senza andare in timeout.

---

## 4. Installazione degli Hotfix sul Server di Produzione

### Passo 1: Verifica File Modificati
Assicurarsi che i seguenti file aggiornati vengano distribuiti sul server (tramite Git, GitFTP Manager o copia diretta):
```
- VERSION
- .gitignore
- .htaccess
- Config.php
- api_public_positions.php
- api_public_check_email.php
- api_public_apply.php
- app/PublicApiAuth.php
- app/UpdaterCore.php
- download_cv.php
- diag.php
- reset_admin.php
- fix_password.php
- tools/cli_backup.php
- tools/safe_backup.ps1
```

### Passo 2: Verifica Sintassi PHP (Lint)
Sul server eseguire una verifica rapida per accertarsi che nessun file sia corrotto durante il trasferimento:
```powershell
C:\xampp\php\php.exe -l api_public_positions.php
C:\xampp\php\php.exe -l api_public_check_email.php
C:\xampp\php\php.exe -l api_public_apply.php
C:\xampp\php\php.exe -l download_cv.php
C:\xampp\php\php.exe -l app/UpdaterCore.php
```
Tutti i file devono restituire: `No syntax errors detected`.

### Passo 3: Ricarica Cache PHP / Apache (OPcache)
Poiché PHP utilizza spesso OPcache in produzione, ricaricare Apache per assicurarsi che i nuovi file vengano letti subito:
```powershell
# Riavvio servizio Apache (se servizio Windows)
net stop Apache2.4 ; net start Apache2.4

# Oppure dal pannello di controllo XAMPP: Stop -> Start su Apache
```

---

## 5. Verifica Post-Installazione (Checklist di Collaudo)

1. **Test Protezione File ZIP**:
   - Apri nel browser: `http://localhost/portalmanager/dump_portalmanagerBI.zip`
   - **Esito atteso**: Errore `403 Forbidden` (il download deve essere negato).
2. **Test Protezione Script di Reset**:
   - Apri nel browser: `http://localhost/portalmanager/diag.php`
   - **Esito atteso**: Errore `403 Forbidden` / `Accesso negato`.
3. **Test API Posizioni Aperte (con filtri)**:
   - Esegui una richiesta GET verso `api_public_positions.php?q=developer`
   - **Esito atteso**: Risposta HTTP 200/401 conforme (nessun errore 500 o crash SQL sintattico).
4. **Test Download CV**:
   - Da `manage_applications.php`, clicca sul link "Scarica CV" di una candidatura
   - **Esito atteso**: Download corretto del file PDF/DOCX (nessun crash per funzioni non definite).

---

## 6. Procedura di Rollback

Se per qualsiasi motivo si desidera ripristinare lo stato precedente:
1. **Ripristino File**:
   Estrarre lo zip creato da `safe_backup.ps1` (`uploads/backups/backup_files_v...zip`) nella root dell'applicazione.
2. **Ripristino Database**:
   Importare il dump generato:
   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -p portalmanager < uploads\backups\backup_db_v...sql
   ```
3. **Riavvio Apache**:
   Riavviare il servizio Apache per ripulire l'OPcache.
