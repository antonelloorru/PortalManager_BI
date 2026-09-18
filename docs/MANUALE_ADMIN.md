# certV — Manuale Amministratore (v4.3)

Guida completa alla gestione del portale certV per il Super Admin.

## Indice

1. [Architettura del portale](#architettura)
2. [Sicurezza](#sicurezza)
3. [Gestione utenti e permessi](#utenti)
4. [Autenticazione a due fattori (2FA)](#2fa)
5. [Aggiornamenti del sistema](#aggiornamenti)
6. [Backup e ripristino](#backup)
7. [Troubleshooting](#troubleshooting)

---

## 1. Architettura del portale <a id="architettura"></a>

### Struttura cartelle

```
portalbrand/
├── app/                          # Moduli core di sicurezza
│   ├── bootstrap.php             # Entry point unico
│   ├── Csrf.php                  # Protezione CSRF
│   ├── EmailOtp.php              # 2FA via email
│   ├── Env.php                   # Variabili d'ambiente
│   ├── RateLimiter.php           # Rate limiting
│   ├── RecoveryCodes.php         # Codici recupero 2FA
│   ├── Router.php                # URL opachi
│   ├── Security.php              # Header HTTP sicurezza
│   ├── Session.php               # Sessione hardened
│   ├── Totp.php                  # TOTP RFC 6238
│   ├── TwoFactor.php             # Orchestratore 2FA
│   ├── UrlHelper.php             # url(), redirect()
│   └── XlsxWriter.php            # Export Excel
├── docs/                         # Documentazione
├── uploads/                      # File caricati dagli utenti
├── sql/                          # Migration SQL (post-install)
├── *.php                         # Pagine del portale
├── .env.php                      # Secret (NON committare)
├── .htaccess                     # Configurazione Apache
└── Config.php                    # Connessione DB
```

### Stack tecnologico

- PHP 8.2+
- MySQL/MariaDB
- Apache 2.4+ (mod_rewrite, mod_headers)
- Bootstrap di sicurezza centralizzato in `app/bootstrap.php`

---

## 2. Sicurezza <a id="sicurezza"></a>

### CSRF (Cross-Site Request Forgery)

Tutti i form POST richiedono token `_csrf` automaticamente verificato. Il bootstrap controlla ogni richiesta in entrata.

**Come usare nei form:**
```php
<form method="POST">
    <?= csrf_field() ?>
    <!-- altri campi -->
</form>
```

**Whitelist tool legacy** (file che saltano CSRF perché non possono averlo):
- `install.php`, `reset_admin.php`, `fix_password.php`
- `system_update.php`, `db_upgrade.php`, `schema_check_upgrade.php`, `health_check.php`
- Tool one-shot (`apply_csrf_patch.php`, `verify_integrity*.php`, `migrate_links.php`)

Modifica la lista in `app/Csrf.php` solo se sai cosa stai facendo.

### URL opachi (Router)

Per prevenire enumerazione, gli URL del portale usano slug HMAC al posto dei nomi file:
- `app/k7m2x9ab` → `r.php?r=k7m2x9ab` → `brand.php`

Slug deterministici via `URL_SECRET` in `.env.php`. Cambiare il secret = ruotare tutti gli URL del portale.

### Sessione hardened

- Cookie `HttpOnly`, `SameSite=Strict`, `Secure` (se HTTPS)
- Idle timeout: 30 min
- Lifetime assoluto: 8 ore
- Rigenerazione ID ogni 15 min
- Fingerprint binding (User-Agent)

### Rate limiting

Login limitato a:
- 20 tentativi per IP / 15 min → lockout 30 min
- 5 tentativi per email / 15 min → lockout 15 min

### Headers HTTP di sicurezza

Inviati automaticamente da `Security::sendHeaders()`:
- `Content-Security-Policy` con whitelist CDN
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security` (se HTTPS)
- `Permissions-Policy` con feature browser disabilitate

---

## 3. Gestione utenti e permessi <a id="utenti"></a>

### Ruoli del portale

| ID | Ruolo | Accesso |
|----|-------|---------|
| 1 | Super Admin | Tutto, incluso pannelli sistema |
| 2 | HR Director | Amministrazione utenti/dipendenti, recruiting completo |
| 3 | Brand Manager | Gestione brand e tecnologie, recruiting limitato |
| 4 | Team Leader | Pipeline candidati per la propria area |
| 5 | Recruiter | Recruiting standard, no eliminazioni |
| 6 | Dipendente | Solo proprio profilo, certificazioni |

### Pannelli admin nel menu

- **Accessi portale** (`manager_users.php`) — crea/modifica utenti
- **Anagrafica dipendenti** — dati anagrafici staff
- **Gestione 2FA Utenti** — autorizza singolarmente la 2FA
- **Ruoli** — definisce ruoli
- **Permessi** — granularità view/create/edit/delete/export per pagina

### Override permessi per singolo utente

In `manage_permissions.php` puoi sovrascrivere i permessi di ruolo per un utente specifico (tabella `user_permissions`).

---

## 4. Autenticazione a due fattori (2FA) <a id="2fa"></a>

### Modello "Admin authorize, User setup"

Solo il Super Admin può **autorizzare** la 2FA per un utente. L'utente abilitato fa il **setup pratico** (scansione QR del proprio telefono) da solo.

### Procedura admin

1. Menu **Amministrazione → Gestione 2FA Utenti**
2. Lista utenti con switch on/off per:
   - **TOTP** (app authenticator: Google, Microsoft, Authy)
   - **Email OTP** (codice via email)
3. Click su switch → autorizza/revoca
4. **Reset 2FA**: bottone rosso a destra, cancella tutto (autorizzazioni + secret + recovery codes). Da usare se l'utente è bloccato fuori.

### Procedura utente (dopo autorizzazione admin)

1. Menu **Sicurezza account** (visibile solo se l'admin ha autorizzato)
2. Click **⚙ Configura TOTP**
3. Scansione QR con app authenticator
4. Inserimento codice di verifica
5. **Salvataggio recovery codes** (mostrati una sola volta — stamparli o copiarli in password manager)

### Verifica al login

Dopo email + password, l'utente con 2FA attiva deve inserire un codice (TOTP / Email / Recovery). Pending login dura 5 min, poi torna alla password.

### Recupero in caso di lockout totale

Se un utente perde **app + email + recovery codes**:

```sql
DELETE FROM user_2fa WHERE user_id = <id>;
DELETE FROM user_2fa_recovery_codes WHERE user_id = <id>;
```

Poi l'utente può loggarsi con sola password e riconfigurare.

---

## 5. Aggiornamenti del sistema <a id="aggiornamenti"></a>

### system_update.php

Pannello in **Sistema → Aggiorna sistema**. Carica un file ZIP della patch.

**Importante**: la routine di estrazione (v4.3+) gestisce correttamente le sottocartelle. Se carichi uno zip con `app/`, `sql/`, `docs/`, vengono estratte nelle cartelle giuste.

**File protetti** (mai sovrascritti):
- `Config.php`
- `.htaccess`
- `.env.php`
- `installer_disabled.flag`
- Cartella `uploads/`

**Protezione zip-slip**: voci con `..`, percorsi assoluti o drive Windows vengono rifiutate.

### Migrazione DB

Script SQL post-update vanno applicati a mano via phpMyAdmin nella cartella `sql/`. Il sistema **non** esegue automaticamente le migration.

### Backup automatico pre-update

`system_update.php` esegue automaticamente:
1. Backup file (zip della cartella) in `uploads/backups/`
2. Backup DB (mysqldump) in `uploads/backups/`

Prima di applicare l'estrazione del nuovo zip.

### Verifica integrità

Tool `verify_integrity_v2.php`:
- Controlla che tutti i file linkati dal menu esistano
- Controlla che i 13 moduli `app/` siano presenti
- Identifica file orfani con backslash nel nome (estrazioni mal fatte)
- Propone pulizia con safeguard

Carica e apri `http://tuo-portale/verify_integrity_v2.php`. **Eliminare dopo l'uso.**

---

## 6. Backup e ripristino <a id="backup"></a>

### Backup completo manuale

```powershell
# File
cd C:\Data\SviluppoSoftware\xampp\htdocs
Compress-Archive portalbrand certv-backup-$(Get-Date -Format yyyyMMdd).zip

# DB
& "C:\xampp\mysql\bin\mysqldump.exe" -u root portal_manager > certv-db-$(Get-Date -Format yyyyMMdd).sql
```

### Backup automatico

`system_update.php` crea backup pre-update in `uploads/backups/`:
- `certv_files_<timestamp>.zip` (filesystem)
- `certv_db_portal_manager_<timestamp>.sql` (database)

### Ripristino

```powershell
# File
cd C:\Data\SviluppoSoftware\xampp\htdocs
Remove-Item portalbrand -Recurse
Expand-Archive certv-backup-YYYYMMDD.zip

# DB
& "C:\xampp\mysql\bin\mysql.exe" -u root portal_manager < certv-db-YYYYMMDD.sql
```

---

## 7. Troubleshooting <a id="troubleshooting"></a>

### "403 — Token di sicurezza non valido"

Il form non manda CSRF. Soluzioni:
1. Aggiungi `<?= csrf_field() ?>` dentro il form
2. O aggiungi il file alla whitelist `LEGACY_ADMIN_TOOLS` in `app/Csrf.php`

### "File non trovato" dopo system_update

L'estrazione del zip non ha gestito le sottocartelle. Soluzioni:
1. Verifica con `verify_integrity_v2.php`
2. Estrai a mano con PowerShell:
   ```powershell
   cd C:\Data\SviluppoSoftware\xampp\htdocs\portalbrand
   Expand-Archive -Path "C:\path\to\update.zip" -DestinationPath . -Force
   ```

### File orfani con backslash (`app\Csrf.php`)

Estrazione zip mal fatta. Tool `cleanup_orphans.php`:
1. Anteprima: `?preview=1`
2. Applica: `?apply=1`
3. Eliminare il file dopo l'uso

### Login blocca con "Sessione scaduta"

- Verifica orologio sistema sincronizzato (per TOTP)
- Cookie `Secure=1` se HTTP (deve essere 0 in dev)

Verifica `.env.php`:
```php
'COOKIE_SECURE' => '0',  // 1 solo se HTTPS
```

### Logo brand non appare in PDF/email

Il campo nel DB si chiama `b.logo_path`, non `logo_url`. Aggiorna eventuali query custom.

### Errore "Cannot find ZipArchive"

Su XAMPP Windows abilita estensione ZIP:
1. Apri `php.ini`
2. Decommenta `extension=zip`
3. Riavvia Apache

### Email OTP non arrivano

1. Verifica SMTP in **Amministrazione → Config SMTP**
2. Test "Invia email di test"
3. Controlla **Sistema → Log sistema** categoria `Email`
4. Per Gmail: usa **App Password** non la password account
