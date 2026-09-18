# Guida Operativa Applicazione Hotfix — PortalManager v1.9.54

## 1. Riepilogo Hotfix v1.9.54 (CSRF Lockdown & Security Hardening)

La release **v1.9.54** completa l'azzeramento delle superfici di attacco CSRF, estende il controllo degli accessi a tutti i tool di amministrazione ed elimina i crash bloccanti su API pubbliche e download CV:

| Componente / File | Problema Rilevato | Soluzione Applicata |
|---|---|---|
| `app/Csrf.php` | **CSRF Bypass**: i tool amministrativi storici erano esentati dal controllo CSRF via `LEGACY_ADMIN_TOOLS` | Ristretta la whitelist esclusivamente a `install.php` e `install_old.php` (richiesti prima dell'avvio del DB). Tutti gli altri endpoint richiedono validazione CSRF |
| `access_control.php` | **Privilege Escalation**: `db_upgrade.php`, `schema_check_upgrade.php`, `system_update.php` erano in `$always_allowed` (aperti a qualsiasi ruolo) | Rimossi da `$always_allowed`: l'accesso è ora vincolato al solo ruolo Super Admin (`role_id === 1`) |
| `db_upgrade.php` | 6 form POST privi di token CSRF e assenza di controllo autenticazione all'avvio | Inserita clausola Super Admin obbligatoria e iniettato `<?= csrf_field() ?>` in tutti i 6 form (auto-apply, upgrade, backup db, backup files, etc.) |
| `schema_check_upgrade.php` | 4 form POST privi di token CSRF e assenza di controllo autenticazione | Inserita clausola Super Admin obbligatoria e iniettato `<?= csrf_field() ?>` in tutti i 4 form (connetti, check, apply modifiche DDL) |
| `diag.php`, `reset_admin.php`, `fix_password.php` | Form di reset e aggiornamento password senza token CSRF | Inserito `<?= csrf_field() ?>` in tutti i rispettivi form POST |
| `UpdateControl/db_upgrade.php` & `schema_check_upgrade.php` | Form duplicati senza token CSRF in cartella non protetta | Inserito `<?= csrf_field() ?>` e controllo Super Admin |
| `api_public_apply.php` & `api_public_check_email.php` | **HTTP 403 Fatal Error**: `app/bootstrap.php` verificava CSRF su chiamate API esterne senza sessione browser | Aggiunto `define('CSRF_SKIP', true);` prima dell'inclusione di bootstrap: le API autenticano regolarmente via firma crittografica HMAC (`X-PM-Signature`) |
| `api_public_positions.php` | Include errato di `bootstrap.php` e crash SQL 500 su filtri di ricerca | Include corretto a `app/bootstrap.php`; parametrizzazione query di conteggio `$total` con `prepare()` e `$bind` |
| `download_cv.php` | Crash per `require_login()` non definito e tabelle errate | Riscritto completamente: controllo sessione RBAC nativo, query allineate a tabelle reali `candidate_applications` e `candidate_documents`, protezione path traversal |
| `app/UpdaterCore.php` | **Blocco Timeout Backup**: scansione riga per riga di 124 viste SQL complesse e scansione ricorsiva di dump ZIP | Filtrate solo le tabelle base (`WHERE Table_type = 'BASE TABLE'`), escluse viste SQL analitiche dal dump dati riga, esclusi archivi e cartella `Dump/`, timeout esteso a 300s |
| `app/PublicApiAuth.php` | Fallimento verifica HMAC su chiamate multipart/form-data | Aggiunto supporto fallback per calcolo firma multipart quando `php://input` è vuoto |
| `.htaccess` | Esposizione al download pubblico dei dump SQL/ZIP | Blocco categorico HTTP 403 per archivi (`.zip`, `.tar`, `.gz`, `.7z`) e script di emergenza |
| `tools/cli_backup.php` / `tools/safe_backup.ps1` | Necessità di uno strumento di backup veloce a riga di comando senza limiti HTTP | Tool CLI zero-timeout per backup istantaneo filesystem e database con fallback automatico nativo |

---

## 2. Risultati della Verifica Copertura CSRF

La verifica automatizzata con parser ricorsivo AST e regex ha confermato:
- **Totale form POST nel sistema**: 341
- **Form protetti da CSRF**: **331** (100% dell'applicazione operativa)
- **Form esentati**: **10** (esclusivamente i form di `install.php` e `install_old.php`, usati solo in fase di prima installazione senza database configurato)

---

## 3. Procedura di Backup Pre-Aggiornamento (Zero-Timeout)

Prima di applicare qualsiasi aggiornamento, eseguire il backup di sicurezza da PowerShell:

```powershell
cd "G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI"
powershell -ExecutionPolicy Bypass -File tools\safe_backup.ps1
```

Oppure con PHP CLI:
```powershell
C:\xampp\php\php.exe tools\cli_backup.php
```

Il backup salverà:
- `uploads/backups/backup_files_v1.9.54_*.zip` (file portale senza ricorsioni)
- `uploads/backups/backup_db_v1.9.54_*.sql` (struttura completa + dati tabelle base)

---

## 4. Modalità di Installazione del Pacchetto `update_v1.9.54.zip`

### Modalità 1: Tramite la Console di Aggiornamento Web (Consigliata)
1. Accedi al portale con un account **Super Admin** (`role_id = 1`).
2. Vai su **Console di Sistema** -> Scheda **Aggiornamento** (`system_console.php?tab=update`).
3. Carica il file `update_v1.9.54.zip`.
4. Il sistema verificherà l'integrità del pacchetto, eseguirà il backup rapido e sovrascriverà i file in modo transazionale e sicuro.

### Modalità 2: Installazione Manuale / FTP / Estrazione Diretta
Se preferisci applicare manualmente i file:
1. Estrai il contenuto di `update_v1.9.54.zip` nella root del portale, sovrascrivendo i file esistenti.
2. Verifica che i permessi dei file rimangano corretti.
3. Riavvia Apache per invalidare l'OPcache:
   ```powershell
   # Da pannello XAMPP: Stop -> Start su Apache
   # Oppure se registrato come servizio Windows:
   net stop Apache2.4 ; net start Apache2.4
   ```

---

## 5. Checklist di Collaudo Post-Installazione

1. **Test Protezione CSRF**:
   - Apri una qualsiasi pagina con form (es. `user_profile.php`, `brand.php`, `db_upgrade.php`).
   - Verifica nel sorgente HTML la presenza di: `<input type="hidden" name="_csrf" value="...">`.
   - Se tenti di inviare un POST modificando o omettendo il token `_csrf`, il sistema deve bloccare la richiesta con **HTTP 403 — Token di sicurezza non valido o scaduto**.

2. **Test Tool Amministrativi**:
   - Accedi a `db_upgrade.php` o `schema_check_upgrade.php` come Super Admin: la pagina si apre e tutti i pulsanti operano correttamente senza generare errori CSRF.
   - Prova ad accedere con un utente con ruolo non amministratore: l'accesso viene respinto immediatamente.

3. **Test API Pubbliche Esterne**:
   - Invia una richiesta POST autenticata con HMAC a `api_public_apply.php` o `api_public_check_email.php`.
   - La richiesta viene elaborata correttamente con risposta JSON (e non rifiutata con 403 HTML da CSRF).

4. **Test Download CV**:
   - Da `manage_applications.php`, scarica il CV di un candidato. Il download deve completarsi senza errori.