# Release Notes — PortalManager v1.9.49

Ambito: RBAC / Permessi (sessioni / middleware)
File: `access_control.php`, `r.php`, `app/Session.php`, nuovo `rbac_debug.php`

## Diagnosi (verificata sui dati reali)
Eseguendo la vera `can()` sul dump di produzione: i ruoli abilitati ottengono
correttamente ALLOW (es. `recruiting_posizioni.php` → ALLOW per ruoli 2–6), e DENY
solo dove manca la riga (es. ruolo 7). `user_permissions` è vuota (nessun override).
Conclusione: **con il ruolo di sessione corretto la RBAC non nega**. Il blocco a
runtime con "ruoli corretti a DB" può derivare solo dal fatto che il ruolo usato dal
controllo proviene dalla **snapshot di sessione** (`$_SESSION['role_id']`), fissata al
login e mai riletta: un cambio ruolo lato admin non ha effetto finché l'utente non
rifà login → deny pur con assegnazione corretta a DB.

## Correzione (middleware)
`Session::syncRole(PDO)` — invocato dal middleware a ogni richiesta (una volta per
richiesta), in `access_control.php` (prima del guard) e in `r.php` (prima del controllo
RBAC):
- rilegge `role_id`/`status` da `users` e **riallinea `$_SESSION['role_id']` al DB**
  → i cambi ruolo hanno effetto immediato, senza rifare login;
- se l'utente è rimosso o `status != 'active'`, azzera la sessione (→ login).

`$pdo` è globale (creato in `Config.php`) ed è in scope nei due punti di innesto, quindi
la sincronizzazione viene effettivamente eseguita in entrambi i flussi (accesso diretto
e front controller `r.php`).

## Strumento diagnostico: rbac_debug.php
Nuova pagina che mostra, per un utente e una pagina, il **ruolo in sessione vs quello a
DB**, i **permessi effettivi** (valore + sorgente user/role/default) e la **causa
probabile** dell'eventuale deny, distinguendo i tre casi:
1. ruolo di sessione stale (≠ DB) → risolto da `Session::syncRole`;
2. `role_permissions.can_view = 0` (deny esplicito di ruolo) o override utente a 0;
3. nessuna riga per quel ruolo/pagina (deny-by-default: pagina nuova da concedere in
   Gestione permessi).
Super Admin può ispezionare qualsiasi utente/pagina (`?uid=&page=`); gli altri vedono
la propria sessione. Accesso via `r.php` (registrare eventualmente la voce nei permessi).

## QA
- `php -l` OK su tutti i file.
- `can()` reale sui dati del dump: ALLOW/DENY coerenti con la matrice.
- `Session::syncRole`: ruolo 6→5 riallineato; utente disattivato/rimosso → sessione
  azzerata; una sola sync per richiesta.
- SQL migration RUN1/RUN2 err=0; `;` nei commenti = 0; schema_version → 1.9.49.

## File
```
VERSION                     1.9.49
access_control.php          Session::syncRole prima del guard
r.php                       Session::syncRole prima del controllo RBAC
app/Session.php             metodo syncRole (refresh ruolo + enforcement stato)
rbac_debug.php              diagnostica RBAC (sessione vs DB, permessi effettivi, causa)
sql/migration_v1_9_49.sql   allineamento versione (nessun delta schema)
docs/                       changelog, deployment
```
