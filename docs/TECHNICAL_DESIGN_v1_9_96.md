# TECHNICAL DESIGN — v1.9.96
Regola: `url_safe()` (app/UrlHelper.php) = `htmlspecialchars(Router::url(...))` → già pronto per attributi HTML; non va ricodificato
(`h()` / `htmlspecialchars()`). `Router::url()` restituisce l'URL grezzo per header Location o per codifica esplicita.
Con `USE_PRETTY_URLS = 0` l'URL è `r.php?r=<slug>&<parametri>`: una doppia codifica rompe ogni parametro dopo lo slug.
Il parametro `r` è riservato allo slug: per passare un motivo alla pagina di login si usa il path diretto `login.php?r=<motivo>`.
Controllo eseguito: nessun altro `h(url_safe(` / `htmlspecialchars(url_safe(` nel codice.
