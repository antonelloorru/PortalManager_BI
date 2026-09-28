# Technical Design — v1.9.81 — Password reset

## Flusso
```
login ──► password_reset (email) ──► PasswordReset::request()
            │  users (email, status=active)? ──no──► nessuna azione (stessa risposta)
            │                                 └─si─► token = random_bytes(32) → hex
            │                                        password_resets(user_id, token = sha256, expires_at, ip, ua)
            │                                        send_certv_email(link = app_public_url / Router::url(password_reset, t))
link ?t= ──► PasswordReset::find(sha256(t)) ──► $_SESSION[pwreset_token] ──► redirect ?step=new
?step=new ─► validate(policy) ──► complete(): UPDATE token used=1 (WHERE used=0)
                                             UPDATE users password_hash, password_changed_at
                                             invalida altre richieste · email di conferma
richieste successive (r.php) ──► Session::syncRole(): password_changed_at > _sec.created → destroy → login?r=pwchanged
```
## Schema (ER)
`password_resets(id, email, user_id → users.id, token CHAR sha256, expires_at, used, used_at, request_ip, user_agent, created_at)`
`users.password_changed_at DATETIME NULL`
Pulizia: righe più vecchie di 30 giorni eliminate a ogni nuova richiesta.

## Impostazioni (`app_settings`)
| Chiave | Default | Significato |
|---|---|---|
| `pwd_reset_enabled` | 1 | funzione attiva |
| `pwd_reset_ttl_min` | 60 | validità link (10–1440) |
| `pwd_min_length` | 12 | lunghezza minima (8–64) |
| `app_public_url` | '' | base assoluta dei link nelle email |
