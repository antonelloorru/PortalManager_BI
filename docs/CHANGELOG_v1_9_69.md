# PortalManager v1.9.69 — SSO Microsoft 365 / MFA: test di verifica configurazione

Sistema → SSO Microsoft 365 / MFA → sezione **Test configurazione** (Super Admin).
Il pacchetto include l'intero modulo SSO (v1.9.67 + v1.9.68).

## 1. Diagnostica configurazione (senza login)
`Microsoft365Sso::diagnostics()` esegue ed espone con esito OK / ATTENZIONE / ERRORE / N/D:
- estensioni PHP OpenSSL e cURL;
- cookie di sessione `SameSite` (Strict rompe il ritorno da Microsoft);
- parametri obbligatori e formato Client ID (GUID);
- Redirect URI: HTTPS, termina con `/auth_microsoft.php`, host coerente con quello in uso;
- Richiesta MFA attiva/disattiva; Authentication Context (formato c1..c99);
- tenant: discovery OpenID raggiungibile, issuer;
- JWKS: chiavi di firma disponibili;
- **Client ID + secret**: validati con un token `client_credentials`, con diagnosi degli
  errori Entra più comuni (AADSTS7000215 secret errato, AADSTS7000222 secret scaduto,
  AADSTS700016 app non trovata, AADSTS90002 tenant non trovato).

## 2. Test accesso con Microsoft (MFA reale)
`auth_microsoft.php?action=test` (solo Super Admin già autenticato): esegue il flusso
reale con Microsoft, inclusa la MFA, ma **non modifica la sessione** e non effettua login.
Riporta: validità del token (firma, audience, issuer, nonce), account, **MFA eseguita**
(claim `amr`), Authentication Context soddisfatto (claim `acrs`), corrispondenza con un
utente del portale (via `users.email` o `employees.business_email`) e suo stato.
In modalità test la MFA viene misurata e riportata, non imposta.

Esiti di diagnostica e test registrati nell'event log (Security).

## QA
- Diagnostica con risposte Microsoft simulate: configurazione corretta → tutto OK; secret
  errato / MFA disattiva / SameSite Strict / tenant errato / redirect HTTP / parametri
  mancanti → segnalati con il messaggio corretto.
- Callback: in modalità test un token senza MFA viene riportato (non bloccato); in modalità
  normale lo stesso token viene rifiutato.
- `php -l` OK su tutti i file; migration RUN1/RUN2 err=0; schema_version → 1.9.69.
