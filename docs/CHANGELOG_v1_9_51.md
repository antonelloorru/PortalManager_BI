# Release Notes — PortalManager v1.9.51 — Fix RBAC DEFINITIVO

Caso: `debora.buzzichelli@wetechs.it` bloccata su **Gestione dispositivi**
(`device_manager.php`) nonostante la corretta configurazione in "permessi ruoli" e
"profilo".

## Causa radice (verificata sul dump)
`device_manager.php` (e altre pagine) gate-avano l'accesso con una **whitelist di ruoli
HARDCODED** che **ignora la RBAC**:
```
$can_edit = in_array($u_role, [1, 2], true);
$can_view = $can_edit || in_array($u_role, [4], true);
if (!$can_view) die('Accesso negato');
```
Nel dump, `role_permissions` concede invece `device_manager.php` **anche ai ruoli 10
(Coordinatore Tecnico) e 11 (Finance)** (`can_view=1`). Il profilo tecnico di debora
(ruolo 10) è quindi correttamente autorizzato nella matrice, ma la pagina lo nega perché
10 non è nell'elenco hardcoded [1,2,4]. Stesso mismatch su `device_handover`,
`device_print` (ruoli 8,10,11) e `employee_cv` (ruoli 6,8,10,11).

## Audit — pagine che gate-avano con ruoli hardcoded senza consultare can()
`device_manager.php`, `device_handover.php`, `device_import.php`, `device_export.php`,
`device_print.php`, `employee_cv.php`, `cv_import.php`, `credly_manual_import.php`.

## Fix definitivo (codice)
Tutte e 8 le pagine ora **delegano alla RBAC** (`can('view'|'edit'|'create'|'export', 'pagina.php')`),
preservando l'accesso "self" dove previsto (un dipendente vede/stampa il proprio
dispositivo o la propria scheda CV). La configurazione di "permessi ruoli" e "profilo"
diventa così effettivamente autoritativa. Super Admin (role 1) resta sempre consentito.

## Companion (inclusi, da v1.9.49/1.9.50)
- `Session::syncRole` (access_control.php, r.php, app/Session.php): riallinea il ruolo di
  sessione al DB a ogni richiesta (cambi ruolo effettivi senza re-login).
- `rbac_debug.php`: diagnostica RBAC (sessione vs DB, permessi effettivi, causa).
- `sql/migration_v1_9_51.sql`: seed idempotente delle pagine operative del menu prive di
  permessi (it_service, service_desk, dir_report, pratix_orders, …) + bump versione.

## QA (su matrice reale del dump)
- `device_manager.php`: ruoli 1,2,4,10,11 → ALLOW (prima 10/11 bloccati); 5,8 → DENY (matrice=0).
- `employee_cv.php`: 4,5,6,8,10,11 → ALLOW; 9 → DENY.
- `device_handover/print`: 8,10,11 → ALLOW.
- Non-regressione: `cv_import` {1,2,5} ALLOW, `credly` {1,2} ALLOW (invariati).
- `php -l` OK su tutti i file; migration RUN1/RUN2 err=0; `;` nei commenti = 0; schema_version → 1.9.51.

## File
```
VERSION                          1.9.51
device_manager.php               gate via can() (era hardcoded [1,2,4])
device_handover.php              gate via can()
device_import.php                gate via can()
device_export.php                gate via can() + accesso self
device_print.php                 gate via can() + accesso self
employee_cv.php                  gate via can() + accesso self
cv_import.php                    gate via can()
credly_manual_import.php         gate via can()
access_control.php, r.php,       Session::syncRole (ruolo sessione allineato al DB)
app/Session.php
rbac_debug.php                   diagnostica RBAC
sql/migration_v1_9_51.sql        seed pagine operative + bump versione
docs/                            changelog, deployment
```
