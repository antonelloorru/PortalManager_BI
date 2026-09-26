# PortalManager v1.9.67 — SSO Microsoft 365 (Entra ID) con MFA  [fix unico]

## Analisi flusso attuale
`login.php`: email+password → `password_verify` (rate-limit IP/email, CSRF) →
se 2FA attiva `TwoFactor::startPendingLogin()`, altrimenti `Session::onLogin()`.
Segreti in `.env.php` (fuori webroot) via `app/Env.php`. Match utente su `users.email`,
`status='active'`.

## SSO implementato (OIDC Authorization Code + PKCE, PHP nativo, zero dipendenze)
Nuovo `app/Microsoft365Sso.php` + endpoint `auth_microsoft.php`.

Flusso:
1. Login → "Accedi con Microsoft 365" → `auth_microsoft.php?action=start` →
   redirect a `login.microsoftonline.com/<tenant>/oauth2/v2.0/authorize`
   (PKCE S256, `state`, `nonce`, scope `openid profile email`).
2. Callback → verifica `state`; scambio `code` → `id_token` (token endpoint, client_secret + PKCE).
3. Verifica `id_token`: firma **RS256 via JWKS**, `iss`, `aud`, `exp/nbf`, `nonce`, `tid`, `amr`.
4. Match utente → `Session::onLogin()` (nessuna 2FA applicativa: MFA già assolta da Microsoft).

### Match utente
- `users.email` (case-insensitive, attivo);
- fallback su email aziendale del dipendente collegato:
  `users.employee_id → employees.business_email`.
Utente inesistente → accesso negato (auto-provisioning off di default).

### MFA (abilitazione/enforcement)
- `MS_REQUIRE_MFA=1` (default): l'`id_token` DEVE riportare `amr` contenente `mfa`;
  vengono rifiutati anche i token con `amr` **assente**.
- `MS_ACR_VALUE` (opzionale): id di un Authentication Context di Entra (es. `c1`)
  configurato per richiedere MFA; se valorizzato viene inviato nella richiesta di
  autorizzazione (`claims.id_token.acrs`) **forzando la MFA in fase di login**, anche
  senza Conditional Access.

### Sicurezza
`state` (anti-CSRF), `nonce` (anti-replay), PKCE (anti-intercettazione code),
verifica completa dell'`id_token`, `client_secret` solo in `.env.php` (mai in DB/repo).
`auth_microsoft` registrata come pagina **pubblica** (Router::PAGES, r.php, access_control).

## Setup Azure/Entra (una tantum)
1. Entra ID → App registrations → New registration.
2. Redirect URI (Web) = `MS_REDIRECT_URI` (es. `https://<host>/portalmanager/auth_microsoft.php`).
3. Certificates & secrets → New client secret → copiare il valore.
4. MFA: Conditional Access sull'app, oppure Authentication Context + `MS_ACR_VALUE`.
5. Compilare `.env.php` come in `docs/env.sso.example.php`.

## QA
- Verifica `id_token` (chiave RSA + JWKS + token coniato): valido → OK; firma manomessa /
  aud / issuer / scaduto / nonce / tenant errato → rifiutati.
- MFA: `amr=mfa` → OK; `amr` senza mfa → rifiuto; `amr` assente → rifiuto.
- `authorizeUrl`: PKCE(S256)+state+nonce; `claims/acrs` presente con `MS_ACR_VALUE`.
- Fallback `business_email` verificato su DB (utente trovato con `users.email` diversa).
- `php -l` OK su tutti i file; migration RUN1/RUN2 err=0; schema_version → 1.9.67.

## File
```
VERSION                     1.9.67
app/Microsoft365Sso.php     client OIDC (PKCE, verifica id_token RS256/JWKS, MFA stringente, ACR)
auth_microsoft.php          endpoint start + callback (match users.email + employees.business_email)
login.php                   bottone "Accedi con Microsoft 365" + messaggi SSO
app/Router.php, r.php,       registrazione pagina pubblica auth_microsoft
access_control.php
sql/migration_v1_9_67.sql   allineamento versione (nessun delta schema)
docs/env.sso.example.php     esempio configurazione .env.php
docs/
```
