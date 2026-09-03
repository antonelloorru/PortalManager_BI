# PortalManager v1.9.24 — Manuale Amministratore
## Modulo Careers

### 1. Emissione chiave API per il portale esterno
1. Menu → Sistema → **Chiavi API — Portale Careers**.
2. "Genera chiave": inserire `client_id` (es. `careers_portal_prod`),
   etichetta, origins consentite (URL portale esterno), IP/CIDR consentiti.
3. Il **client_secret** viene mostrato UNA SOLA VOLTA. Copiarlo e:
   - inserirlo in `config/api_secrets.php` (creare da sample), oppure
   - impostarlo come variabile d'ambiente `PM_API_SECRET_CAREERS_PORTAL_PROD`.
4. Nella macchina del portale esterno, definire in
   `config/careers_bff_config.php` le costanti `PM_API_BASE`, `PM_CLIENT_ID`,
   `PM_CLIENT_SECRET`.
5. Verificare `last_used_at` dopo la prima chiamata dal portale.

### 2. Pubblicazione posizione
1. Menu → Recruiting → **Posizioni aperte**.
2. Compilare titolo, reparto, sede, contratto, seniority, descrizione.
3. Stato = `open` per pubblicare. Impostare `Pubblicazione` e `Scadenza`
   (opzionali).
4. La vista `v_public_open_positions` filtra automaticamente per data.
5. Per ritirare: stato `closed` (resta storicizzata) o `archived` (nascosta).

### 3. Gestione candidature
1. Menu → Recruiting → **Candidature**.
2. Filtri per posizione/stato.
3. Cliccare "Apri" per il dettaglio: scarica CV, cambia stato, aggiungi voto.
4. Stato `hired` → il candidato viene automaticamente promosso a `employees`
   (se non già collegato) e `candidates.linked_employee_id` viene valorizzato.

### 4. Notifica HR
Impostare `careers.notify_email` in Settings → Configurazione applicativa. Alla
ricezione di una candidatura viene inviata una mail sintetica (best-effort).

### 5. Rate limit e sicurezza
- `careers.rate_email_check_per_hour`: default 20/IP.
- `careers.rate_apply_per_day`: default 5/IP.
- `careers.cv_max_bytes`: default 5 MB.
- `careers.cv_allowed_mime`: default `pdf, doc, docx`.
Modificabili in Settings; auditate in `public_api_audit`.

### 6. Audit
- `public_api_audit`: elenco chiamate API con status, error_code, request_id.
- `event_log` (area `careers`): eventi applicativi (nuova candidatura, promozione).
- Download CV: tracciato con `write_log('download_cv', ...)`.

### 7. Manutenzione ordinaria
- Purge audit oltre 12 mesi: `DELETE FROM public_api_audit WHERE created_at < NOW() - INTERVAL 12 MONTH`.
- Purge rate_limit oltre 24h: `DELETE FROM public_api_rate_limit WHERE hit_at < NOW() - INTERVAL 24 HOUR`.
- Rotazione secret: creare nuovo `client_id`, aggiornare portale, disattivare
  vecchio.
