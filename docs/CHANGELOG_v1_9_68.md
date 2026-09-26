# PortalManager v1.9.68 — SSO Microsoft 365 / MFA: pagina di configurazione

Include integralmente l'SSO v1.9.67 (OIDC + PKCE, verifica id_token, MFA stringente,
Authentication Context, match users.email + employees.business_email).

## Nuovo: Sistema → SSO Microsoft 365 / MFA (`sso_settings.php`, solo Super Admin)
- Abilitazione del pulsante "Accedi con Microsoft 365".
- **MFA**: "Richiedi MFA" (claim `amr`=`mfa` obbligatorio) e **Authentication Context ID**
  per forzare la MFA già al login.
- Registrazione Entra ID: tenant ID, client ID, client secret (campo write-only: vuoto =
  mantiene l'attuale), redirect URI (default calcolato, validato HTTPS), dominio consentito.
- Stato a colpo d'occhio: SSO attivo, configurazione completa, MFA, Authentication Context.
- Salvataggio in `.env.php` tramite nuovo `Env::persist()` (merge, scrittura atomica
  tmp+rename, chmod 0600, escape `var_export`): i segreti non finiscono nel DB.
- CSRF, audit log su ogni modifica.

## Registrazione
Voce di menu in **Sistema**, `Router::PAGES`, `manage_permissions`, permesso ruolo 1.

## QA
- `php -l` OK su tutti i file; `Env::persist` verificato (chiavi esistenti preservate,
  valori con apici/virgolette intatti, permessi 0600).
- Migration RUN1/RUN2 err=0; schema_version → 1.9.68.
