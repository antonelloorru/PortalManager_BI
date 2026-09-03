# certV 4.0 — Patch di Sicurezza & Modularità

## Obiettivo

Rendere il portale **sicuro**, con **link opachi** alle pagine (che non rivelano i nomi dei file PHP), e di **architettura modulare**, senza riscrivere i 72 file esistenti.

---

## Cosa fa questa patch

### 1. Architettura modulare in `/app`

Il codice di sicurezza è isolato in una cartella `/app/` con un solo entry-point (`bootstrap.php`) che carica tutto in ordine corretto:

```
/app
├── bootstrap.php       Entry point unico — include tutti i moduli sotto
├── Env.php             Caricamento .env.php (secret persistiti, fuori dal repo)
├── Session.php         Sessione hardened (HttpOnly, SameSite=Strict, fingerprint)
├── Security.php        Headers HTTP di sicurezza (CSP, HSTS, X-Frame, ecc.)
├── Csrf.php            Token CSRF + auto-verify su tutte le POST
├── Router.php          Mappa pagine ↔ slug opachi (HMAC-SHA256)
├── UrlHelper.php       Funzioni globali url(), url_safe(), redirect(), csrf_field()
└── RateLimiter.php     Rate limit filesystem-based per login + endpoint sensibili
```

Ogni modulo è una classe autonoma, testabile e disattivabile singolarmente.

### 2. Link opachi (slug HMAC)

Tutti gli URL del portale ora usano slug deterministici al posto dei nomi dei file:

| Prima | Dopo (con mod_rewrite) | Dopo (senza) |
|---|---|---|
| `brand.php` | `/app/k7m2x9ab` | `r.php?r=k7m2x9ab` |
| `manage_employees.php?scheda=12` | `/app/a3f81c20?scheda=12` | `r.php?r=a3f81c20&scheda=12` |
| `recruiting_candidati.php?app_id=5` | `/app/b9e2d4f1?app_id=5` | `r.php?r=b9e2d4f1&app_id=5` |

Lo slug è generato come `HMAC-SHA256(nome_pagina, URL_SECRET)` troncato a 16 hex (64 bit di entropia). Stesso nome → stesso slug, ma cambiando `URL_SECRET` in `.env.php` tutti gli slug ruotano in blocco.

**Vantaggi:**
- Niente information disclosure sulla struttura del portale
- I bot di scansione non possono enumerare le pagine via URL guessing
- L'attaccante non sa nemmeno quali endpoint esistono
- I link sono comunque cacheable e bookmarkabili
- Compatibile con `mod_rewrite` per URL puliti `/app/<slug>`

**Importante:** lo slug **non** è un meccanismo di autorizzazione (l'RBAC continua a controllare ogni accesso). Serve per non *rivelare* la struttura. La sicurezza vera resta la verifica `can('view', $page)` lato server.

### 3. CSRF protection

- Token random 32-byte generato per sessione (TTL 2 ore)
- `Csrf::verify()` chiamato automaticamente da `bootstrap.php` su ogni POST
- Stampato nei form via `<?= csrf_field() ?>`
- Iniettato negli AJAX/fetch via header `X-CSRF-Token` (script in `header.php`)
- POST senza token valido → HTTP 403 + log

### 4. Sessione hardened

- Cookie `HttpOnly`, `SameSite=Strict`, `Secure` (se HTTPS)
- Idle timeout: 30 minuti
- Lifetime assoluto: 8 ore
- Rigenerazione ID ogni 15 minuti (riduce finestra fixation)
- Fingerprint binding (User-Agent + Accept-Language) HMAC con `SESSION_SECRET`
- `use_strict_mode=1` (impedisce session ID arbitrari da cookie injection)

### 5. Rate limiting login

- Per IP: 20 tentativi / 15 min → lockout 30 min
- Per email: 5 tentativi / 15 min → lockout 15 min
- Storage filesystem in `uploads/.ratelimit/` (no Redis/APCu richiesti)
- Reset automatico dopo login valido

### 6. Headers di sicurezza

Inviati sia da `Security::sendHeaders()` (PHP) che da `.htaccess` come fallback:

| Header | Valore | Protezione |
|---|---|---|
| `Content-Security-Policy` | restrittiva con CDN whitelistati | XSS, code injection |
| `X-Frame-Options` | `SAMEORIGIN` | Clickjacking |
| `X-Content-Type-Options` | `nosniff` | MIME confusion |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Privacy referrer |
| `Permissions-Policy` | feature disabilitate | API browser sensibili |
| `Strict-Transport-Security` | (HTTPS) `max-age=31536000` | SSL stripping |

### 7. Login con messaggi generici

Eliminati i messaggi che rivelavano:
- Esistenza dell'email ("Account disattivato" → ora "Credenziali non valide")
- Schema DB ("Unknown column", "Table doesn't exist" → ora "Servizio non disponibile")
- Tracce di brute force (delay random 200-500ms su login fallito)

### 8. Hardening file/path

`.htaccess` aggiornato:
- Blocco esecuzione PHP in `/app/` e `/uploads/`
- Blocco accesso a `.env.php`, `.sql`, `.md`, `.log`, `.bak`
- Blocco `install.php`, `reset_admin.php`, `fix_password.php` quando esiste `installer_disabled.flag`
- Header `X-Powered-By` rimosso
- Compressione + cache statici

---

## Come installare la patch

### Step 0 — Backup

```bash
cd /var/www/html
cp -r certV certV.backup-$(date +%Y%m%d)
```

### Step 1 — Estrarre la patch

Scompatta il file `certV-4.0-security-patch.zip` **sopra** la directory esistente, sovrascrivendo i file. I file sostituiti sono solo:

- `.htaccess`
- `access_control.php`
- `header.php`
- `footer.php`
- `login.php`
- `logout.php`
- `unauthorized.php`

I file aggiunti (nuovi) sono:

- `r.php` — front controller
- `migrate_links.php` — tool di migrazione (rimuovere dopo l'uso)
- `app/` — l'intera cartella dei moduli core

### Step 2 — Primo avvio

Apri il portale: la prima request crea automaticamente `.env.php` con i secret generati (CSRF, URL, session). Verifica che il file abbia permessi `0600`.

```bash
ls -la .env.php
# -rw------- 1 www-data www-data ... .env.php
```

### Step 3 — Migrazione link nelle pagine esistenti

I file di core (header, login, ecc.) sono già aggiornati. Per migrare i link nelle altre pagine PHP:

1. Login come Super Admin
2. Apri `http://tuo-portale/migrate_links.php?preview=1`
3. Verifica il report (numero di link che verranno modificati per ogni file)
4. Apri `http://tuo-portale/migrate_links.php?apply=1` per applicare
5. **Elimina il file:** `rm migrate_links.php`

Lo script salva backup di ogni file modificato in `uploads/.migration_backup/`.

I link con espressioni complesse (es. con concatenazioni JS o variabili annidate) **non** vengono migrati automaticamente. Vanno aggiornati a mano sostituendo:

```php
href="brand.php?id=<?= $b['id'] ?>"
```

con

```php
href="<?= url_safe('brand', ['id' => $b['id']]) ?>"
```

### Step 4 — Disabilitare installer

Una volta installato e funzionante:

```bash
touch installer_disabled.flag
```

Questo blocca `install.php`, `reset_admin.php`, `fix_password.php`, `schema_check_upgrade.php` via `.htaccess`.

### Step 5 — Attivare HTTPS (raccomandato)

In `.env.php` impostare `'COOKIE_SECURE' => '1'`. Decommentare in `.htaccess`:

```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

E in `Security.php` il blocco HSTS è già attivo automaticamente quando `$_SERVER['HTTPS']` è on.

---

## Compatibilità

- **PHP 8.1+** (richiesto: `random_bytes`, `password_verify`, `match`, `str_ends_with`, `never` return type)
- **MySQL/MariaDB** invariato
- **Apache 2.4+** con `mod_rewrite`, `mod_headers`, `mod_authz_core`
- **Nginx**: serve riscrivere le regole in formato Nginx (la sostanza non cambia)

---

## Cosa NON fa questa patch

Per onestà tecnica, ecco i limiti:

1. **Non riscrive le query SQL.** Il codice esistente usa già PDO con prepared statement: ho verificato `brand.php`, `recruiting_candidati.php`, `index.php`. Non ho trovato concatenazione di input utente in SQL nei file campionati. Una review sistematica di tutti i 72 file resta consigliata.

2. **Non rimuove `'unsafe-inline'` dalla CSP.** I file PHP del portale hanno tantissimi `<script>` e `<style>` inline (es. tutto `index.php`). Renderli compliant con CSP strict richiederebbe refactoring di ogni file. La CSP attuale è comunque molto più restrittiva di nessuna CSP.

3. **Non aggiunge SRI ai CDN.** Chart.js, FontAwesome e DataTables sono caricati senza `integrity=`. Per i pattern d'uso, si dovrebbero ospitare localmente o aggiungere SRI (richiede ricalcolo hash a ogni update).

4. **Non implementa 2FA.** Sarebbe il prossimo step logico — fattibile aggiungendo un modulo `app/Totp.php` con `OTPHP` (composer) o implementazione minimale di RFC 6238.

5. **Non riscrive `download.php` / `doc_download.php`.** Sono già abbastanza robusti (path traversal prevention via `realpath()`, controllo ruolo). Si potrebbe rendere il parametro `?file=` opaco anche lui (token firmato con scadenza).

6. **Non protegge gli endpoint API.** I file `api_*.php` non sono nel router e non hanno verifica CSRF (sono GET). Vanno valutati caso per caso.

---

## Audit pre-produzione consigliato

Prima di andare in produzione:

1. **Test funzionale completo**: login, navigazione, ogni modulo CRUD, upload file
2. **Verifica permessi RBAC** per ogni ruolo (admin, HR, brand mgr, recruiter, employee)
3. **Test rate limiting**: provare 6 login falliti consecutivi e verificare lockout
4. **Test CSRF**: provare una POST senza token → deve restituire 403
5. **Verifica `.env.php`**: permessi `0600`, NON in repository git (aggiungere a `.gitignore`)
6. **Verifica installer disabilitato**: `installer_disabled.flag` presente, accesso a `install.php` deve dare 403
7. **Scansione**: lanciare uno scan basic con `nikto` o `OWASP ZAP` per controllare header e endpoint esposti

---

## Rollback

Se qualcosa va storto:

```bash
cd /var/www/html
rm -rf certV
mv certV.backup-YYYYMMDD certV
```

I backup dei singoli file migrati restano in `uploads/.migration_backup/*.bak`.
