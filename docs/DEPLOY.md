# certV — Guida di Installazione e Deploy

## Requisiti

- PHP 8.1+
- MySQL/MariaDB 10.4+
- Apache 2.4+ con `mod_rewrite`, `mod_headers`, `mod_authz_core`
- Estensioni PHP: `pdo_mysql`, `zip`, `mbstring`, `openssl`, `json`

## Installazione di questa patch (4.3.2)

### Step 1 — Backup preventivo

```powershell
# File system
cd C:\Data\SviluppoSoftware\xampp\htdocs
Compress-Archive portalbrand portalbrand-pre-432-$(Get-Date -Format yyyyMMdd).zip

# Database
& "C:\xampp\mysql\bin\mysqldump.exe" -u root portal_manager > portal-pre-432-$(Get-Date -Format yyyyMMdd).sql
```

### Step 2 — Applica via system_update.php

1. Login Super Admin
2. **Sistema → Aggiorna sistema**
3. Carica `certV-4.3.2-complete.zip`
4. Conferma estrazione

Il pacchetto contiene:
- Cartella `app/` con i 13 moduli core (sovrascrive eventuali orfani)
- `upload_certificato.php` con CSRF aggiunto
- `cleanup_orphans.php` per pulizia post-install
- `docs/` con manuali aggiornati

### Step 3 — Pulizia file orfani

Dopo il system_update, vai su `http://192.168.230.128/portalbrand/cleanup_orphans.php`:

1. Click **👁 Anteprima**
2. Verifica il report: dovresti vedere ~13 file orfani (`app\Csrf.php`, `app\bootstrap.php`, ecc.) con esito "da_rimuovere"
3. Click **🧹 Applica rimozione** — i file vengono spostati in backup
4. **Elimina** `cleanup_orphans.php` dal server

### Step 4 — Verifica integrità

Apri `http://192.168.230.128/portalbrand/verify_integrity_v2.php`:

Devi vedere:
- ✅ "1. Pagine linkate dal menu" → OK
- ✅ "2. Moduli core in /app" → OK (13 moduli)
- ✅ "3. File orfani da estrazione ZIP" → OK (nessuno)

Se tutto verde, **elimina** `verify_integrity_v2.php`.

### Step 5 — Test funzionale

1. Logout
2. Login normale
3. Verifica:
   - Menu visibile correttamente (con voci admin se Super Admin)
   - "Sicurezza account" visibile solo se autorizzata
   - Carica un certificato (form ora con CSRF)
   - Salva un template in Recruiting (form ora con CSRF)

## Installazione fresca (nuovo deploy)

### Step 1 — Database

```sql
CREATE DATABASE portal_manager
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Importa nell'ordine:
1. `install/schema.sql` (schema base v2.4)
2. `sql/migration_2fa_v2.sql` (tabelle 2FA)
3. `sql/migration_2fa_admin.sql` (autorizzazioni admin)

### Step 2 — File system

Estrai il pacchetto completo del portale in `C:\Data\SviluppoSoftware\xampp\htdocs\portalbrand`.

Verifica che esistano:
- `app/` con 13 file
- `Config.php` (da `Config.php.dist`)
- `.htaccess` (da pacchetto)

### Step 3 — Configurazione

Crea `Config.php` da `Config.php.dist`:

```php
<?php
$db_host = 'localhost';
$db_name = 'portal_manager';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user, $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('DB connection failed: ' . $e->getMessage());
}
```

Crea `.env.php` vuoto — al primo accesso il portale lo popola con secret generati.

### Step 4 — Permessi cartelle

```powershell
# Su Windows / XAMPP non strettamente necessario, ma su Linux:
chmod 755 portalbrand
chmod 700 portalbrand/.env.php
chmod 755 portalbrand/uploads
```

### Step 5 — Apache

Verifica `httpd.conf`:

```apache
<Directory "C:/Data/SviluppoSoftware/xampp/htdocs/portalbrand">
    AllowOverride All
    Require all granted
</Directory>
```

Riavvia Apache.

### Step 6 — Primo login

URL: `http://localhost/portalbrand/install.php`

Crea il primo utente Super Admin. Dopo il setup:

```powershell
# Disabilita installer
echo "" > installer_disabled.flag
```

## Aggiornamenti incrementali

Per ogni patch successiva (zip):

1. **Backup** sempre prima
2. **system_update.php** carica e estrae lo zip
3. Se la patch contiene SQL in `sql/`, applicarli a mano via phpMyAdmin
4. Verifica con `verify_integrity_v2.php`
5. Elimina i tool one-shot (`cleanup_orphans.php`, `verify_integrity*.php`)

## Hardening produzione

Quando il portale va in produzione:

### 1. HTTPS obbligatorio

In `.env.php`:
```php
'COOKIE_SECURE' => '1',
```

In `.htaccess` decommenta:
```apache
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

### 2. Disabilita debug

In `.env.php`:
```php
'APP_ENV'   => 'production',
'APP_DEBUG' => '0',
```

### 3. Disabilita installer

```powershell
echo "" > installer_disabled.flag
```

### 4. Permessi file restrittivi

```powershell
# .env.php solo per il proprietario
icacls .env.php /inheritance:r /grant:r "%USERNAME%":F
```

### 5. Log Apache attivi

Verifica in `httpd.conf`:
```apache
ErrorLog "logs/error.log"
CustomLog "logs/access.log" combined
LogLevel warn
```

### 6. Rate limit a livello firewall

Considerare `mod_evasive` o `fail2ban` per protezione anti-DDoS.

## Disinstallazione completa

Se vuoi rimuovere tutto:

```sql
DROP DATABASE portal_manager;
```

```powershell
Remove-Item C:\Data\SviluppoSoftware\xampp\htdocs\portalbrand -Recurse -Force
```

## Compatibilità

- **PHP 8.1, 8.2, 8.3** ✅
- **MySQL 5.7+, MariaDB 10.4+** ✅
- **Apache 2.4** ✅
- **Nginx**: serve riscrivere le rewrite rules in formato Nginx (non incluso)
- **PHP-FPM** ✅
- **Linux/macOS/Windows** ✅
