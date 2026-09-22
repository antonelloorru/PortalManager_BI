# PortalManager v1.9.59 — Import Pratix: voce di menu + accesso

## Problema
La funzione di import del report Pratix (`pratix_import.php`, introdotta con l'ETL v1.9.58)
non era registrata: non compariva nel menu e non era raggiungibile via router.

## Fix
- `app/MenuManager.php`: nuova voce **Import Pratix** (`fa-file-import`) nel gruppo
  acquisizione della sezione "Commesse / Progetti", vicino agli altri import.
- `app/Router.php`: aggiunto `pratix_import` alla whitelist `PAGES` (pagina instradabile).
- `manage_permissions.php`: aggiunta la voce `pratix_import.php` al `$page_map`
  (configurabile da "Permessi ruoli").
- `sql/migration_v1_9_59.sql`: seed `role_permissions` per `pratix_import.php`
  (ruoli 1 Super Admin, 2 HR Director, 11 Finance) così la voce è visibile/accessibile;
  include (idempotente) anche `cm_pratix_ext` + viste `_ext` dell'ETL. Bump versione.

Il gate della pagina consente 1/2/11 (o `can('view')`); per altri profili basta concedere
il permesso da "Permessi ruoli".

## QA
- `php -l` OK su MenuManager, Router, manage_permissions, pratix_import, pratix_orders, PratixImporter.
- Migration RUN1/RUN2 err=0; `;` nei commenti = 0; schema_version → 1.9.59;
  permessi `pratix_import.php` presenti per 1/2/11.

## Hotfix migration
Corretto errore SQL `Unknown column 'project_code'` nella vista
`v_cm_pratix_commessa_ext`: la colonna della commessa in `v_cm_pratix_righe` si chiama
`commessa` (non `project_code`). La subquery ora usa `commessa AS project_code` per il
join a `cm_projects.project_code`. Migration idempotente: ri-eseguibile in sicurezza.
