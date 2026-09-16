# Release Notes — PortalManager v1.9.50 — Fix RBAC DEFINITIVO

Ambito: RBAC / Permessi. Caso: `debora.buzzichelli@wetechs.it` (profilo tecnico) e, più
in generale, TUTTI i profili non Super Admin.

## Analisi (sul dump di produzione)
1. `page_name` in `role_permissions` sono coerenti (tutti con `.php`); `user_permissions`
   è vuota (nessun override). Eseguendo la vera `can()` sui dati reali, i ruoli abilitati
   ottengono correttamente ALLOW: **con permessi presenti la logica non nega**.
2. La verifica di visibilità del menu (`MenuManager::userCanSee`) e il gate runtime
   (`can()`) usano la STESSA regola (ruolo→role_permissions, deny se manca la riga).
3. **Causa radice**: 8 pagine operative del menu erano **prive di righe in
   `role_permissions`** (0 righe = deny-by-default per tutti i profili tranne Super
   Admin), quindi nascoste/negate a tutti gli altri ruoli:
   `it_service.php`, `relazione_servizio_it.php`, `dir_report.php`, `service_desk.php`,
   `pratix_orders.php`, `manage_job_positions.php`, `manage_applications.php`,
   `organigramma.php`. Un profilo tecnico (Direttore IT / Coordinatore Tecnico / Resp.
   Commerciale) veniva così bloccato su Relazione di Servizio IT, Service Desk, Report
   direzionale, Ordinativi Pratix.

## Fix (definitivo, su TUTTI i profili)
### Dati — `sql/migration_v1_9_50.sql`
Seed idempotente dei permessi mancanti, **ereditando la visibilità dai fratelli
gestiti della stessa sezione di menu** (nessuna invenzione di policy):
- pagine delivery/report → ruoli 8 (Direttore IT), 9 (Resp. Commerciale), 10
  (Coordinatore Tecnico) [+ Super Admin]: `can_view=1`, `can_export=1` (report in sola
  lettura; `pratix_orders` eredita anche l'edit dai fratelli);
- `manage_job_positions` / `manage_applications` → set Recruiting (2,3,4,5,6,8,9,10,11)
  con le stesse tuple dei fratelli recruiting;
- `organigramma` → set HR (1,2,3,5,7,8,9,10,11) in sola lettura.
Idempotente (`PRIMARY KEY(role_id,page_name)` → `ON DUPLICATE KEY UPDATE`).

### Logica — middleware (già in v1.9.49, incluso qui)
`Session::syncRole(PDO)` riallinea a ogni richiesta `$_SESSION['role_id']`
all'assegnazione corrente a DB (i cambi ruolo hanno effetto immediato; utente
disattivato/rimosso → logout). Innesto in `access_control.php` e `r.php`.
`rbac_debug.php`: diagnostica runtime (ruolo sessione vs DB, permessi effettivi, causa).

## QA
- `can()` reale sul dump + seed: ruoli 8/9/10 → ALLOW su it_service/service_desk/
  dir_report/pratix_orders/relazione_servizio_it; recruiting/HR → ALLOW; controllo
  negativo role 6 (Dipendente) → DENY (nessuna sovra-abilitazione).
- Migration RUN1/RUN2 err=0; `;` nei commenti = 0; 49 righe seedate; schema_version → 1.9.50.
- `php -l` OK su tutti i file.

## File
```
VERSION                       1.9.50
sql/migration_v1_9_50.sql     seed permessi pagine operative (dati) + bump versione
access_control.php            Session::syncRole prima del guard (logica)
r.php                         Session::syncRole prima del controllo RBAC
app/Session.php               metodo syncRole
rbac_debug.php                diagnostica RBAC
docs/                         changelog, deployment
```

## Nota su debora
Nel dump fornito l'utente `debora.buzzichelli@wetechs.it` non è presente nella tabella
`users` (solo l'anagrafica `employees`). Verificare che abbia un account in `users` con
il ruolo operativo corretto (Direttore IT / Coordinatore Tecnico): con questo ruolo, dopo
il seed, accede regolarmente alle pagine del suo profilo. Se il suo ruolo effettivo è
diverso, `rbac_debug.php?uid=<id>&page=it_service.php` mostra ruolo e causa esatta.
