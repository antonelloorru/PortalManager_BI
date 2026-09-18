# certV 4.0 — Cheatsheet sviluppatore

Riferimento rapido per scrivere/modificare codice usando i nuovi moduli.

---

## In ogni file PHP della root

Sostituisci l'header esistente con:

```php
<?php
require_once __DIR__ . '/access_control.php';   // include automaticamente bootstrap
require_once __DIR__ . '/header.php';
```

`access_control.php` carica internamente `app/bootstrap.php`, che a sua volta carica
session, headers, CSRF, router, rate limiter e funzioni legacy.

---

## Generare URL nelle view

```php
// Link semplice
<a href="<?= url_safe('brand') ?>">Brand</a>

// Link con parametri
<a href="<?= url_safe('user_profile', ['emp_id' => 5]) ?>">Profilo</a>

// Per uso non-HTML (es. JS)
window.location = '<?= url('recruiting_candidati') ?>';

// Pagina fuori router (es. download.php) — usa path diretto
<a href="download.php?file=<?= urlencode($f) ?>">Scarica</a>
```

---

## Redirect lato server

```php
// PRIMA
header('Location: index.php'); exit();

// DOPO
redirect('index');

// Con parametri
redirect('brand', ['id' => $newId]);
```

---

## Form con CSRF

```html
<form method="POST" action="<?= url_safe('brand') ?>">
  <?= csrf_field() ?>
  <input name="name">
  <button>Salva</button>
</form>
```

La verifica del token avviene automaticamente dentro `bootstrap.php` su ogni POST.
Se vuoi disabilitarla per uno specifico endpoint (es. webhook esterno):

```php
<?php
define('CSRF_SKIP', true);            // PRIMA del require
require_once __DIR__ . '/access_control.php';
```

---

## AJAX / fetch

Il token CSRF viene iniettato automaticamente da `header.php` in tutti i `fetch()`.
Per chiamate jQuery:

```javascript
const csrf = document.querySelector('meta[name="csrf-token"]').content;
$.ajaxSetup({ headers: { 'X-CSRF-Token': csrf } });
```

Per `XMLHttpRequest` puro:

```javascript
xhr.setRequestHeader('X-CSRF-Token', csrf);
```

---

## Rate limiting su endpoint personalizzati

```php
$key = "myaction:user:" . $_SESSION['user_id'];

if (!RateLimiter::attempt($key, max: 10, window: 3600, lockout: 600)) {
    http_response_code(429);
    die('Troppe richieste, riprova tra 10 minuti.');
}

// ... logica dell'endpoint ...

// Opzionale: dopo successo, reset
RateLimiter::reset($key);
```

---

## Aggiungere una nuova pagina al router

1. Crea il file PHP nella root (es. `nuova_funzione.php`)
2. Aprire `app/Router.php` e aggiungere il nome a `PAGES`:

```php
public const PAGES = [
    // ...
    'nuova_funzione',
];
```

3. Aggiungerla anche all'array `$SKIP` di `migrate_links.php` se non vuoi
   che vengano modificati i suoi link.

4. Aggiungere la voce nel menu di `header.php`:

```php
<li><a href="<?= url_safe('nuova_funzione') ?>" class="<?= ia('nuova_funzione') ?>">
    <i class="fa-solid fa-star"></i>Nuova Funzione
</a></li>
```

---

## Pagine "restricted" (admin sensibili)

Per pagine come `install.php`, `reset_admin.php`, `system_update.php`:

- **NON** aggiungerle a `Router::PAGES`
- Aggiungerle a `Router::RESTRICTED` (sono già lì)
- Vanno accedute solo via path diretto, non via `?r=` slug
- Proteggerle con flag `installer_disabled.flag` quando non servono

---

## Output sicuro

```php
// Stringa generica → escape HTML
<?= h($titolo) ?>

// URL/attributo
<a href="<?= url_safe('brand') ?>">

// JSON in JS
<script>
const data = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

// In query string
?id=<?= urlencode($id) ?>
```

---

## Logging strutturato

```php
write_log(
    category: 'Brand',
    level:    'info',          // info | warning | error | critical
    message:  'Brand creato',
    user_id:  $_SESSION['user_id'],
    context:  ['brand_id' => $newId, 'name' => $name]
);
```

I log finiscono in `app_logs`. Si visualizzano da `view_logs.php`.

---

## Quando NON usare il router

Casi che richiedono path diretto:

- File API JSON (`api_*.php`) — generalmente chiamati da JS interni
- Endpoint download (`download.php`, `doc_download.php`) — già autenticati
- Cron job (`cron_notifications.php`) — eseguito via CLI/scheduler
- Health check (`health_check.php`) — chiamato da load balancer
- Tool admin (`install.php`, `reset_admin.php`) — accessi straordinari controllati

---

## Debug

Per modalità debug, in `.env.php`:

```php
'APP_ENV'   => 'development',
'APP_DEBUG' => '1',
```

Allora `Env::isDebug() === true` e:
- I messaggi di errore al login mostrano dettagli
- Si possono attivare log verbose nei moduli

**MAI in produzione.**
