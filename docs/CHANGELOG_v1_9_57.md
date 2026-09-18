# Release Notes — PortalManager v1.9.57 — Fix routing "Personalizza menu"

File: `app/UrlHelper.php` (funzione `qs_self()`)

## Bug (Redirect Errato)
In "Personalizza menu", selezionare un profilo/ruolo non caricava i dati ma portava alla Home.

## Causa
`header.php` imposta `<base href=".../portalmanager/">`. In modalità pretty-URL il parametro
opaco `r` non è in `$_GET`, quindi `qs_self(['scope_type'=>'role',...])` restituiva un link a
sola querystring (`?scope_type=role&scope_id=N`). Il browser risolve un link `?query` CONTRO la
`<base>` (= Home), non contro la pagina corrente: il click finiva su `/portalmanager/?...` = Home.
Non era un redirect PHP, ma la risoluzione dell'URL relativo rispetto alla `<base>`.

## Fix
`qs_self()` costruisce ora un URL COMPLETO (con path) alla pagina corrente via
`Router::url(current_page(), $params)`: pretty → `app/<slug>?scope_type=role&scope_id=N`;
opaco → `r.php?r=<slug>&scope_type=role&scope_id=N`. Entrambi con path → risolti correttamente
rispetto alla `<base>`. Fallback legacy invariato. Il fix elimina lo stesso difetto latente su
tutte le pagine che usano `qs_self()` (link `?tab=`, `?scope_id=`), senza regressioni.

## QA
- `php -l` OK; output verificato (pretty/opaco); migration RUN1/RUN2 err=0; schema_version → 1.9.57.
