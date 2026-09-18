# Guida Operativa Applicazione Hotfix — PortalManager v1.9.55

## 1. Riepilogo Hotfix v1.9.55 (Multi-Instance Isolation & Path/DB Decoupling)

La release **v1.9.55** risolve in modo definitivo l'isolamento multi-istanza, il disaccoppiamento della codebase dai percorsi fisici e dal nome del database, eliminando le collisioni di sessione e i blocchi in caso di clonazione del portale su host condivisi o sottocartelle distinte.

| Componente / File | Problema Risolto | Soluzione Applicata |
|---|---|---|
| `app/Session.php` | **Cross-Instance Auth Leak / Collisione Cookie**: cookie fisso `certV_sid` con path `'/'` e collisione di sessioni tra installazioni distinte sullo stesso server. | 1. **Nome cookie dinamico**: `PMSESS_<hash>` generato dall'impronta sha256 del path fisico dell'istanza o sovrascrivibile via `SESSION_COOKIE_NAME`.<br>2. **Cookie path dinamico**: ristretto alla cartella web effettiva (`Router::base() . '/'`), impedendo al browser di inviare il cookie ad altre subfolder.<br>3. **Instance Hash Guard**: impronta crittografica di `APP_BASE` + `DB_NAME`. Se una richiesta presenta un cookie proveniente da un'altra istanza, la sessione viene distrutta con redirect al login (`instance_mismatch`). |
| `app/Router.php` | Mancanza di un risolutore dinamico del percorso web relativo in caso di deployment in sottocartelle (`/portalmanager`, `/istanza_1`, ecc.). | Introdotto `Router::base()` che rileva la root URL web dell'applicazione e supporta reset dinamico della cache. |
| `header.php` | Percorsi relativi e link che fallivano se l'applicazione veniva spostata di cartella. | Inserito nel tag `<head>` l'elemento dinamico `<base href="<?= htmlspecialchars(Router::base() ? Router::base() . '/' : '/', ENT_QUOTES) ?>">`. |
| `Config.php` & `Config.php.dist` | Hardcoded configuration e dipendenza rigida da un singolo file locale. | Introdotta gerarchia a 3 livelli: `getenv()` (Docker / Apache SetEnv) > `.env.php` (array locale con credenziali) > fallback predefiniti. Costanti normalizzate: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`, `APP_ROOT`, `APP_BASE`, `UPLOAD_DIR`. Gestione graceful delle connessioni DB opzionali in CLI. |
| `.env.example` | Assenza di un file di configurazione d'esempio per deployment e ambienti container/multi-istanza. | Creato template documentato con parametri DB, chiavi crittografiche e override di isolamento sessioni. |
| `app/Env.php` | Crash in CLI se `APP_BASE` non era definita prima di invocare `Env::load()` e mancata lettura delle variabili d'ambiente di sistema. | Fallback a `dirname(__DIR__)`, supporto per variabili di sistema `getenv()` / `$_ENV` e verifica sicura dell'esistenza di `.env.php`. |
| `cert_management.sql` & `sql/cert_management.sql` | Istruzioni rigide `CREATE DATABASE IF NOT EXISTS cert_management;` e `USE cert_management;` che impedivano il restore su database con nome differente. | Rimosse e commentate le direttive vincolanti; lo schema SQL è ora ripristinabile su qualsiasi nome di database. |
| `reset_admin.php`, `db_upgrade.php`, `schema_check_upgrade.php` | Riferimenti hardcoded residui al nome database `cert_management`. | Rimossi tutti i valori cablati e sostituiti con `DB_NAME` dinamico e parametri configurabili. Corrette anomalie di parentesi residue. |
| `r.php` | Normalizzazione di `$_SERVER['PHP_SELF']` che poteva troncare il prefisso della subfolder web. | Aggiornato per preservare il prefisso calcolato da `Router::base()`. |
| `_nav_system.php`, `UpdateControl/_nav_system.php`, `saved_views_api.php` | Chiamate native a `session_start()` che potevano scavalcare i cookie params isolati. | Sostituite con `Session::start()`. |
| `config_notifiche.php` | Percorso CLI del cron job con path potenzialmente hardcoded. | Reso dinamico con `__DIR__ . '/cron_notifications.php'`. |
| `public/careers/index.html` | Collegamenti assoluti a `/careers/` che causavano 404 in installazioni in sottocartelle. | Convertiti in percorsi relativi (`./`, `privacy.html`, `cookies.html`). |
| `.htaccess` | Riferimento residuo a `/portalbrand/` nella regola installer. | Rimosso il path hardcoded; sostituito con verifica diretta `RewriteCond installer_disabled.flag -f`. |

---

## 2. Procedura di Backup Pre-Aggiornamento

Prima di applicare la release v1.9.55, eseguire il backup di sicurezza ad alte prestazioni da PowerShell:

```powershell
cd "G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI"
powershell -ExecutionPolicy Bypass -File tools\safe_backup.ps1
```

Oppure con PHP CLI:
```powershell
C:\xampp\php\php.exe tools\cli_backup.php
```

Il backup genererà:
- `uploads/backups/backup_files_v1.9.55_*.zip` (archivio file applicativo escluso upload e dump)
- `uploads/backups/backup_db_v1.9.55_*.sql` (dump DDL + dati del database in uso)

---

## 3. Installazione del Pacchetto `update_v1.9.55.zip`

### Opzione A: Tramite Console Web di Aggiornamento (Consigliata)
1. Esegui il login al portale come **Super Admin** (`role_id = 1`).
2. Naviga su **Console di Sistema** -> Scheda **Aggiornamento** (`system_console.php?tab=update`).
3. Carica il pacchetto `update_v1.9.55.zip`.
4. Il modulo `UpdaterCore` effettuerà il controllo di integrità del manifest ed applicherà le modifiche in sicurezza.

### Opzione B: Installazione Manuale / Estrazione Archivio
1. Estrai il contenuto di `update_v1.9.55.zip` direttamente nella root del portale (es. `G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI\`), confermando la sovrascrittura dei file.
2. Se necessario, crea un file `.env.php` partendo da `.env.example` per personalizzare le credenziali del database o i nomi dei cookie della specifica istanza:
   ```php
   <?php
   return [
       'DB_HOST' => 'localhost',
       'DB_PORT' => '3306',
       'DB_NAME' => 'portalmanager_istanza2',
       'DB_USER' => 'root',
       'DB_PASS' => '',
       // 'SESSION_COOKIE_NAME' => 'PMSESS_IST2', // Opzionale: override del nome cookie
   ];
   ```
3. Riavvia il server web / PHP per azzerare l'OPcache:
   ```powershell
   # XAMPP: Stop -> Start su Apache
   # Servizio Windows:
   net stop Apache2.4 ; net start Apache2.4
   ```

---

## 4. Test di Collaudo e Verifica Multi-Istanza

1. **Verifica Nome Cookie e Path**:
   - Apri gli Strumenti per Sviluppatori del browser (F12) -> Scheda **Applicazione** (o **Archiviazione**) -> **Cookie**.
   - Verifica che il cookie di sessione si chiami `PMSESS_<8_caratteri_esadecimali>` (e non più `certV_sid`).
   - Verifica che il campo `Path` del cookie corrisponda alla sottocartella web effettiva (es. `/portalmanager/` o `/` se in root).

2. **Test di Isolamento tra due Istanze sullo stesso host**:
   - Accedi all'Istanza 1 (es. `http://localhost/portal1/`).
   - Apri una nuova scheda e accedi all'Istanza 2 (es. `http://localhost/portal2/` con altro DB o stessa codebase clonata).
   - Verifica che le due sessioni rimangano distinte e che le azioni eseguite su una non interferiscano o autentichino automaticamente l'altra.
   - Prova a forzare un cookie da un'istanza all'altra: il sistema rileva il disallineamento dell'`instance_hash` e reindirizza immediatamente a `login.php?r=instance_mismatch`, distruggendo la sessione non valida.

3. **Verifica Dump SQL e Migrazioni**:
   - Esegui un'importazione di test del file `sql/cert_management.sql` su un database con nome arbitrario (es. `test_nuovo_db`): l'importazione andrà a buon fine senza errori di tipo `Unknown database 'cert_management'`.
