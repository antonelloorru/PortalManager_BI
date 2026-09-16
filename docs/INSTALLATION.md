# certV 4.1 — Patch 2FA (Autenticazione a due fattori)

## Cosa fa

Aggiunge l'autenticazione a due fattori al portale, con tre metodi:

1. **TOTP** — codici a 6 cifre da app come Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden (RFC 6238)
2. **Email OTP** — codici a 6 cifre via email (usa il già presente `SmtpMailer.php`)
3. **Recovery codes** — 10 codici one-time per accesso d'emergenza

**Modalità: opzionale.** Ogni utente decide dal proprio profilo se attivare la 2FA.

## File inclusi

```
/app/
├── bootstrap.php              [SOSTITUIRE] aggiunto autoload moduli 2FA
├── Router.php                 [SOSTITUIRE] registrate pagine 2fa_verify e 2fa_settings
├── Totp.php                   [NUOVO] RFC 6238 implementation pura PHP
├── EmailOtp.php               [NUOVO] OTP via email
├── RecoveryCodes.php          [NUOVO] Gestione codici di recupero
└── TwoFactor.php              [NUOVO] Facade orchestratore

/  (root portale)
├── login.php                  [SOSTITUIRE] aggancio flusso 2FA dopo password
├── r.php                      [SOSTITUIRE] supporto pagine semi-pubbliche
├── 2fa_verify.php             [NUOVO] pagina verifica codice durante login
└── 2fa_settings.php           [NUOVO] pannello gestione 2FA utente

/sql/
└── migration_2fa.sql          [NUOVO] schema DB

/docs/
├── header_2fa_snippet.txt     [SNIPPET] aggiungere voce menu in header.php
└── INSTALLATION.md            [QUESTO FILE]
```

## Installazione

### Step 1 — Backup

```bash
cd /var/www/html/certV
zip -r ../certV-pre-2fa-backup-$(date +%Y%m%d).zip .
mysqldump -u root -p cert_management > ../cert_management-pre-2fa.sql
```

### Step 2 — Importare lo schema DB

```bash
mysql -u root -p cert_management < sql/migration_2fa.sql
```

Oppure da phpMyAdmin: importa `sql/migration_2fa.sql`.

Crea 3 tabelle:
- `user_2fa` — stato 2FA per utente
- `user_2fa_recovery_codes` — codici di recupero (hash)
- `user_2fa_attempts` — audit trail tentativi

### Step 3 — Estrarre i file

Decomprimi il pacchetto sopra la directory esistente, sovrascrivendo:

```bash
cd /var/www/html/certV
unzip -o ../certV-4.1-2fa-patch.zip
```

I file sostituiti sono:
- `app/bootstrap.php` — aggiunto autoload 2FA
- `app/Router.php` — aggiunte pagine al routing
- `login.php` — aggancio flusso 2FA
- `r.php` — gestione 2fa_verify come pagina semi-pubblica

I nuovi file:
- `app/Totp.php`, `app/EmailOtp.php`, `app/RecoveryCodes.php`, `app/TwoFactor.php`
- `2fa_verify.php`, `2fa_settings.php`

### Step 4 — Aggiungere voce menu in header.php

Aprire `header.php` e cercare la sezione (intorno alla riga 232):

```php
<ul class="smenu" style="margin-top:6px">
  <li><a href="<?= url_safe('index') ?>" ...>Dashboard</a></li>
  <li><a href="<?= h($emp_link) ?>" ...>Il mio dossier</a></li>
</ul>
```

Aggiungere la voce 2FA così:

```php
<ul class="smenu" style="margin-top:6px">
  <li><a href="<?= url_safe('index') ?>" ...>Dashboard</a></li>
  <li><a href="<?= h($emp_link) ?>" ...>Il mio dossier</a></li>
  <li><a href="<?= url_safe('2fa_settings') ?>" class="<?= ia('2fa_settings') ?>"><i class="fa-solid fa-shield-halved"></i>Sicurezza account</a></li>
</ul>
```

(Snippet completo in `docs/header_2fa_snippet.txt`.)

### Step 5 — Verificare SMTP per email OTP

L'invio email usa `SmtpMailer.php` se presente nel portale (lo è di default).
Se SMTP non è configurato, vai su **Admin → Config SMTP** e configura un mittente,
altrimenti l'email OTP non funzionerà (gli utenti potranno comunque usare TOTP e recovery codes).

### Step 6 — Test

1. Login come utente normale
2. Vai su **Sicurezza account** dal menu
3. Configura TOTP scansionando il QR con Google/Microsoft Authenticator
4. Salva i recovery codes generati
5. Logout
6. Login: dopo password, deve apparire la schermata 2FA verify
7. Inserisci il codice TOTP corrente → accesso OK

## Schema flusso login

```
Email + Password (CSRF + rate limit)
         │
         ▼
   Password OK?
       │ no → "Credenziali non valide"
       │ sì
       ▼
   2FA attiva?
       │ no  → Session::onLogin() → /index
       │ sì
       ▼
   Stato pending in sessione
   (NO user_id ancora settato)
         │
         ▼
   Redirect a 2fa_verify
         │
         ▼
   Utente sceglie: TOTP / Email / Recovery
         │
         ▼
   Codice valido?
       │ no  → contatore tentativi, retry
       │ sì
       ▼
   Session::onLogin() → /index
```

## Sicurezza implementata

- **Rate limiting**: 10 tentativi 2FA/IP ogni 10 min, lockout 10 min
- **Pending TTL**: 5 minuti per completare la 2FA dopo password OK
- **Email OTP TTL**: 10 minuti, max 5 tentativi per codice
- **Email re-send cooldown**: 60 sec fra due richieste di codice
- **TOTP window**: ±1 step (90 sec totali) per tollerare clock drift
- **Confronto in tempo costante** (`hash_equals`) sia per TOTP che per code/CSRF
- **Recovery codes**: bcrypt cost 10, one-time use, eliminati dopo uso
- **Audit log**: ogni tentativo 2FA è loggato in `app_logs` (tramite `write_log`)
- **Conferma per disattivazione TOTP**: serve un codice valido per disattivare

## Recovery in caso di lockout totale

Se un utente perde **app TOTP**, **accesso email** e **recovery codes**:

L'admin può resettare la 2FA via SQL:

```sql
DELETE FROM user_2fa WHERE user_id = <id_utente>;
DELETE FROM user_2fa_recovery_codes WHERE user_id = <id_utente>;
```

Suggerimento: in futuro creare una pagina admin `manage_users_2fa.php` con un pulsante "Reset 2FA" per utente. Per ora la procedura SQL è sufficiente come escape hatch.

## Compatibilità app TOTP

L'implementazione segue strettamente RFC 6238 con i parametri default più comuni:
- HMAC-SHA1, 6 digits, 30 sec period

Funziona con: Google Authenticator, Microsoft Authenticator, Authy, FreeOTP, FreeOTP+, Aegis, andOTP, 1Password, Bitwarden, KeePassXC, ente Auth, Yubico Authenticator.

## Limiti noti

1. **QR code via API esterna** (`api.qrserver.com`): pratico ma dipende da un servizio esterno. In ambiente air-gapped si può sostituire con una libreria locale (es. `endroid/qr-code` via Composer) modificando `Totp::qrCodeUrl()`. La chiave segreta resta sempre disponibile come testo per inserimento manuale, quindi il QR è solo una comodità.

2. **TOTP secret in chiaro nel DB**: necessario per calcolare HMAC. Se richiesta cifratura at-rest, considerare:
   - cifratura della colonna con `AES_ENCRYPT/AES_DECRYPT` MySQL + chiave da `.env.php`
   - o cifratura applicativa con `openssl_encrypt`

3. **Email OTP dipende da SMTP**: se SMTP non funziona, gli utenti che hanno SOLO email OTP attivo restano bloccati. Per questo è raccomandato attivare anche TOTP o tenere recovery codes.

4. **Nessuna gestione "trust this device"**: ogni login richiede 2FA. Se utile, si potrebbe aggiungere un cookie firmato con TTL 30gg per dispositivi fidati.

5. **No 2FA forced policy**: la patch non implementa "obbligatorio per tutti" o "obbligatorio per ruolo". Se serve in futuro, basta aggiungere un controllo in `access_control.php` che redirige a `2fa_settings` se l'utente non ha 2FA attiva (per i ruoli configurati).

## Rollback

```bash
# 1. Ripristina i file
cd /var/www/html
rm -rf certV
unzip ../certV-pre-2fa-backup-YYYYMMDD.zip -d certV

# 2. Rimuovi le tabelle (opzionale: i dati 2FA vengono persi)
mysql -u root -p cert_management <<EOF
DROP TABLE IF EXISTS user_2fa_attempts;
DROP TABLE IF EXISTS user_2fa_recovery_codes;
DROP TABLE IF EXISTS user_2fa;
DELETE FROM role_permissions WHERE page_name = '2fa_settings.php';
EOF
```
