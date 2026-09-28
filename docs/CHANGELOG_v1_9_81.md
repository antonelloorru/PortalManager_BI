# PortalManager v1.9.81 — Reimpostazione password (link via email)

## Funzionalità
- Login: link **«Password dimenticata?»** (visibile se `pwd_reset_enabled` = 1).
- Nuova pagina pubblica `password_reset.php` (stile del login), tre passi:
  1. **Richiesta** — email → messaggio sempre identico; se l'account esiste ed è attivo parte un'email con link.
  2. **Link** — `?t=<token>` verificato, spostato in sessione e rimosso dall'URL (redirect).
  3. **Nuova password** — policy con indicatore di robustezza, conferma, salvataggio.
- Email di conferma «Password modificata» con data e IP.
- Dopo la reimpostazione le **altre sessioni aperte vengono chiuse** (login con messaggio dedicato);
  la **2FA resta richiesta** al login successivo.

## Sicurezza
| Misura | Dettaglio |
|---|---|
| Token | 256 bit (`random_bytes(32)`), solo nell'email; a DB solo l'**hash SHA-256** |
| Validità | monouso, scadenza `pwd_reset_ttl_min` (default 60′), una nuova richiesta invalida le precedenti, tutte invalidate al cambio |
| Enumerazione utenti | stessa risposta e tempo di risposta uniformato (1,5–2 s) per email esistenti e non |
| Rate limit | richiesta: 10/h per IP (blocco 1 h), 3/h per email (silenzioso); verifica link: 20/15′ per IP |
| Host header injection | link da `app_public_url`; in mancanza schema + `SERVER_NAME` configurato in Apache + base, mai `HTTP_HOST` |
| Esposizione del token | `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, token tolto dall'URL dopo la verifica |
| Concorrenza | il token si consuma con `UPDATE … WHERE used = 0` in transazione: un solo invio vale |
| Policy | ≥ `pwd_min_length` (default 12), 3 classi su 4, niente nome account, diversa dall'attuale, ≤ 72 byte |
| Hash | `Security::hashPassword` (Argon2id, fallback bcrypt cost 12) |
| CSRF | su entrambi i form |
| Audit | `event_log` categoria Auth: richieste (esito, hash email troncato), link non validi, reimpostazioni, rate limit |

## Integrazioni
- `r.php`, `access_control.php`, `app/Router.php`: `password_reset` pagina pubblica.
- `app/Session.php`: `password_reset.php` trattata come il login; `syncRole()` chiude le sessioni avviate prima di
  `users.password_changed_at` (compatibile se la colonna non esiste ancora).
- Email tramite `send_certv_email()` (SMTP del portale, registrate nel log email).

## Revisione sul repository (antonelloorru/PortalManager_BI @ e001fcc, v1.9.80)
- Pacchetto ricostruito sui file del repository: `login.php`, `r.php`, `access_control.php`, `app/Router.php`
  coincidevano; **`app/Session.php` del repository è più recente** (cookie per istanza, path della sottocartella,
  SameSite configurabile, controllo `instance_hash`): la prima consegna v1.9.81 lo avrebbe riportato alla v1.9.51.
  Ora il fix è applicato alla versione del repository (+14/−3 righe, terminatori CRLF conservati).
- Nome nel testo delle email da `employees` (nome e cognome): `users.display_name` è valorizzato solo per gli
  account di servizio (11 utenti su 12 nel dump v1.9.80 lo hanno vuoto).
- Test ripetuti sul dump `Dump/Dump_19.80_DB.zip`.

## Nota
`app/EmailOtp.php` (2FA via email) chiama `SmtpMailer::send()` con argomenti che il metodo non accetta: l'invio
del codice 2FA via email non funziona. Non modificato in questa release (fuori scope), segnalato per la prossima.

## QA (dump 18/09, flusso completo in PHP CLI)
- Richiesta email esistente/inesistente: stesso messaggio; token 64 hex, a DB solo l'hash; link assoluto corretto.
- Link errato, scaduto, già usato; riuso della sessione dopo il cambio: rifiutati.
- Policy: corta, diverse, 2 classi, contiene l'account → errori; password valida → hash Argon2id, `password_verify` ok.
- Email «Reimpostazione» e «Password modificata» inviate; una sola richiesta aperta per utente.
- Rate limit email (3ª richiesta silenziosa) e IP (11ª bloccata); CSRF mancante → rifiutato.
- Sessione avviata prima del cambio → chiusa; successiva → attiva.
- Migration RUN1/RUN2 err=0; `php -l` su tutti i file.
