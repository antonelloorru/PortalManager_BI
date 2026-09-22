# PortalManager v1.9.24 — Technical Design
## Modulo: Careers Portal (Gestione Candidature)

### Obiettivo
Portale esterno per la raccolta di candidature, disaccoppiato dal gestionale HR interno, con sincronizzazione sicura in lettura (posizioni aperte, verifica email) e in scrittura (invio candidatura + CV).

### Architettura a tre livelli
```
[Browser candidato]  ──HTTPS──▶  [Careers Portal (DMZ)]        ──HTTPS + HMAC──▶  [PortalManager HR (LAN)]
      HTML/JS statico              index.html + bff.php                              api_public_*.php
                                   (Backend-for-Frontend)                            RBAC + audit
```

Motivazione: il browser NON può custodire un client_secret HMAC. Il BFF vive
sull'host del portale esterno (DMZ, isolato dal DB HR) e ha l'unico compito di
firmare le chiamate all'API interna. Nessun percorso diretto browser→HR.

### Strategia di comunicazione: HMAC-SHA256 su canonical string
- Header richiesti: `X-PM-Client`, `X-PM-Timestamp`, `X-PM-Nonce`, `X-PM-Signature`.
- Canonical: `METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256(BODY)`.
- Tolleranza clock ±300s, replay guard su nonce (TTL 600s), verifica CIDR IP,
  scopes granulari (`positions:read`, `candidates:check`, `applications:write`),
  rate limit sliding window per IP e per client.
- Secret salvato SOLO come SHA256 in DB; secret reale in ENV o file protetto
  fuori webroot.
- Trasporto: HTTPS obbligatorio; mTLS raccomandato per il canale BFF↔API.
- Segregazione: DB HR non è mai raggiungibile dal portale esterno; il portale
  esterno non ha credenziali applicative, ma solo il client_secret HMAC.

### Schema ER (delta v1.9.24)
```
job_positions ─┐
               ├─< job_applications >─ candidates ─< candidate_cv_files
               │
public_api_clients (config HMAC)
public_api_rate_limit (throttling + nonce replay guard)
public_api_audit      (registro immutabile chiamate)
app_settings          (limiti/mime/notifica)
```

Chiavi:
- `candidates.email_norm` (colonna GENERATED, `LOWER(TRIM(email))`) → UNIQUE:
  garantisce upsert case-insensitive.
- `job_applications (candidate_id, position_id)` → UNIQUE: idempotenza per invio
  duplicato, downgrade su stato terminale (`rejected`, `withdrawn`) consentito
  con nuova candidatura.
- `candidate_cv_files.stored_name` → UNIQUE: path random no-collision.
- FK con `ON DELETE CASCADE` su cv/applications per rispetto GDPR (right-to-erasure).

### Endpoint pubblici
| Method | Path | Scope | Descrizione |
|-------|------|-------|-------------|
| GET   | /api_public_positions.php   | positions:read       | Elenco posizioni open (view `v_public_open_positions`) |
| POST  | /api_public_check_email.php | candidates:check     | Verifica presenza email (risposta minimale) |
| POST  | /api_public_apply.php       | applications:write   | Multipart: candidatura + CV, upsert candidato, tx atomica |

Ogni endpoint:
1. CORS solo per origins whitelisted per client.
2. Autenticazione HMAC (fallisce a monte, before body parse).
3. Enforcement scope.
4. Rate limit doppio (IP + client).
5. Validazione input server-side (mai fiducia sul BFF).
6. Audit riga per tentativo (successo o errore) in `public_api_audit`.

### Endpoint interni
| File | RBAC | Descrizione |
|------|------|-------------|
| manage_job_positions.php     | manage_job_positions.php     | CRUD posizioni con stato/pubblicazione/scadenza |
| manage_applications.php      | manage_applications.php      | Kanban candidature, cambio stato, rating, promozione a dipendente |
| manage_public_api_clients.php| manage_public_api_clients.php| Emissione/revoca chiavi HMAC (secret in chiaro solo alla creazione) |
| download_cv.php              | manage_applications.php      | Servizio CV via readfile con controllo path traversal |

### Sicurezza applicata (Security-by-Design)
- **HMAC + nonce + clock skew** → immunità replay e MITM su corpo.
- **Path traversal difeso** con `realpath()` prefix check in download_cv.
- **Upload validato** con `finfo` (MIME reale), whitelist estensioni, size cap,
  storage con nome random in sottodir per candidato, permessi 0640, `.htaccess`
  che nega accesso diretto.
- **RBAC**: 3 nuovi permessi virtuali; assegnazione automatica ai ruoli 1/2/5.
- **CSRF token** su tutti i form interni; `SameSite=Lax` sui cookie sessione.
- **Anti-mass-assignment**: whitelist esplicita di campi in insert/update.
- **CORS**: allowlist configurabile per client (default: chiuso).
- **Rate limit**: 20/h verifica email per IP, 5/day invio per IP; 100/h e
  200/day per client.
- **Audit immutabile**: `public_api_audit`, `event_log` per ogni promozione a
  dipendente, download CV, cambio stato.
- **GDPR**: consenso privacy obbligatorio (blocco a monte), consenso marketing
  opzionale, timestamp+IP registrati; cascata di cancellazione su right-to-erasure.
- **Segregazione ambienti**: BFF in DMZ, API HR in LAN, DB HR non pubblicabile.

### Flusso invio candidatura (atomicità)
```
BEGIN
  UPSERT candidates            (email_norm UNIQUE)
  CHECK duplicate application  (candidate_id, position_id) → 409 se attiva
  MOVE_UPLOADED_FILE           (validazione MIME, sha256, sottodir/candidateId)
  INSERT candidate_cv_files    (metadata + hash)
  UPDATE cv is_active = 0 per i precedenti
  INSERT job_applications      (status='new', ip, ua, cv_file_id)
  INSERT event_log             (best-effort)
COMMIT
mail HR notify_email           (best-effort, no rollback)
```

### Metriche/QA obbligatorie prima del rilascio
- `php -l` clean su tutti i .php nuovi.
- Migration RUN1 = crea; RUN2 = idempotente, 0 warning.
- Test HMAC: skew fuori range → 401 `clock_skew`; nonce ripetuto → 401 `nonce_replay`.
- Test rate limit: 6° invio giornaliero da stesso IP → 429 `rate_limited`.
- Test upload: file 6MB → 413; file .exe rinominato .pdf → 415 `cv_bad_mime`.
- Test dedup: due invii stessa (email, position) → secondo 409.
- Test path traversal: `download_cv.php?app=` con record forgiato → 404.
- Test promozione: cambio stato → `hired` crea record `employees` e lega
  `candidates.linked_employee_id`.
