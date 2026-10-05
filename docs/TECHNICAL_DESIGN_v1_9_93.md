# TECHNICAL DESIGN — v1.9.93
`footer.php` chiama `ListFilter::renderAuto($page)` su ogni pagina che non usa ListFilter; la funzione esce se
`$GLOBALS['PM_NO_AUTOFILTER']` è vero. Impostato in `manage_projects.php` prima di `header.php`. Nessuna modifica a query,
filtri del pannello, export, schema.
Pagine con filtro unico: it_service, service_desk, dir_report, dgb_activities, manage_projects.
