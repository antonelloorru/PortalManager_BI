# Technical Design v1.10.05 — Sito web WordPress: posizioni aperte e candidature

Software 1.10.05 · Schema 1.10.05 · Upgrade `sql/migration_v1_10_05.sql` · Plugin WordPress `pm-ats` 1.0.0

## 1. Requisiti

| Area | Requisito |
|---|---|
| Pubblicazione | Le posizioni aperte di PortalManager compaiono sul sito pubblico; chiusura/pausa/scadenza le ritira |
| Candidatura | Modulo sul sito per posizione e spontaneo, con CV (PDF/DOC/DOCX), consenso privacy, consenso conservazione |
| Import | Le candidature entrano in PortalManager come candidato + documento CV + candidatura (stage `cv_received`) |
| Rete | PortalManager è un server locale: nessuna connessione in ingresso verso la rete aziendale |
| Sicurezza | Firma HMAC, anti-replay, IP consentiti, segreti fuori dal DB, CV fuori dalla web root pubblica, minimizzazione |
| UI | Elenco con filtri, scheda posizione, modulo integrati nel tema del sito; colori/font/raggio configurabili; template sovrascrivibili |

## 2. Architettura

```
 PortalManager (LAN, XAMPP)                                   Sito pubblico (WordPress + pm-ats)
 ─────────────────────────────                                ──────────────────────────────────
 recruiting_posizioni.php ── modifica ──┐
 wp_ats_sync.php (manuale) ─────────────┼─► WpAtsSync ─► WpAtsClient ══ HTTPS + HMAC ══► REST pm-ats/v1
 cron_wp_ats.php (pianificata) ─────────┘      │                                         │
                                               │  push  POST /sync/jobs ───────────────► CPT pm_job (pubblica / ritira)
                                               │  pull  GET  /sync/applications ◄─────── {prefix}pm_ats_applications
                                               │        GET  /sync/applications/{uuid}/cv ◄── cartella privata uploads/pm-ats-private-*
                                               │        POST /sync/ack ────────────────► synced + minimizzazione (CV eliminato)
                                               ▼
       candidates · candidate_documents · candidate_applications · position_publications · wp_ats_imports · wp_ats_sync_log
```

Tutte le chiamate partono da PortalManager. WordPress non conosce l'indirizzo di PortalManager.

## 3. Flussi

### 3.1 Invio posizioni (push)
1. `WpAtsSync::openPositions()` legge `v_public_open_positions` (status `open`, `opened_at <= oggi`, `target_date` nulla o futura).
2. `POST /sync/jobs` con `mode=full`: il plugin crea/aggiorna i `pm_job` (impronta SHA-256 del contenuto: invariati se identici) e porta in bozza quelli non più presenti.
3. La risposta contiene `map[] = {id, post_id, url}` → `position_publications` canale `wordpress` (`published` con URL; `removed` per le ritirate).
4. Trigger: pulsante, pianificazione, e a fine richiesta dopo salva/approva/pausa/riapri/chiudi/elimina in `recruiting_posizioni.php` (`wpats.push_on_change`, timeout 8 s).

### 3.2 Prelievo candidature (pull)
1. `GET /sync/applications?limit=N&after=ID` (pagine; il sito incrementa `attempts`, imposta `fetched_at`).
2. Per ciascuna: se `uuid` è già in `wp_ats_imports` → riconferma con gli stessi id (idempotenza).
3. `GET /sync/applications/{uuid}/cv` → verifica dimensione, SHA-256 (metadato + header `X-PM-SHA256`), finfo e firma iniziale del file.
4. Transazione: candidato (match email case-insensitive, non cancellato; aggiorna i campi non vuoti), file in `careers.storage_path` (`cand_<id>_cv_<ts>_<uuid6>.<ext>`), `candidate_documents` (cv, lettera), `candidates.cv_path`, candidatura (`candidate_applications`: invariata se già in selezione, riattivata se chiusa, nuova altrimenti), nota sul candidato, `wp_ats_imports`.
5. `POST /sync/ack` con esito per uuid → il sito segna `synced` (o `error` con motivo) ed elimina CV e dati non necessari.
6. Errori temporanei (rete, download incompleto, DB) → nessun ack: la candidatura resta sul sito e si riprova.

### 3.3 Candidatura sul sito
Modulo → `admin-post.php?action=pm_ats_apply` (funziona senza JS) → validazioni → riga `pending` + CV con nome casuale → email a HR e conferma al candidato → redirect 303 con esito (PRG).

## 4. Logiche di controllo

| Controllo | Dove | Regola |
|---|---|---|
| Firma | plugin `PM_ATS_Auth` | `hex(HMAC-SHA256(segreto, METODO\nROTTA\nTS\nNONCE\nsha256(corpo)))`, confronto a tempo costante |
| Finestra | plugin | `|now − TS| ≤ 300 s` |
| Anti-replay | plugin | nonce 32 hex monouso (transient 600 s) |
| IP | plugin | lista CIDR v4/v6; origine IP configurabile (REMOTE_ADDR / X-Forwarded-For / CF) |
| Blocco | plugin | ≥ 20 fallimenti per IP in 15 min → 429 |
| Token modulo | plugin | `ts.hmac(wp_salt)`, validità 48 h, minimo `min_fill_seconds`; rinnovato via `/form-token` (pagine in cache) |
| Anti-spam | plugin | honeypot, `rate_per_day` per IP, stessa email + posizione entro 30 gg rifiutata |
| CV | plugin + PM | estensione ammessa, dimensione, finfo, firma `%PDF-` / `PK\3\4` / `D0CF11E0`; PM ricontrolla e verifica SHA-256 |
| Testi posizioni | plugin | solo testo (tag rimossi), formattazione in HTML con escape (paragrafi, elenchi) |
| Lock | PM | `GET_LOCK('pm_wpats_sync')`: mai due sincronizzazioni contemporanee |

## 5. Schema dati

### PortalManager (nuovo)
```
wp_ats_sync_log (id PK, operation[test|push|pull], trigger_type[manuale|pianificata|modifica], status[running|ok|warn|error],
                 started_at, finished_at, jobs_sent, jobs_created, jobs_updated, jobs_withdrawn,
                 apps_fetched, apps_imported, apps_failed, message, user_id)
wp_ats_imports  (wp_uuid PK, wp_app_id, candidate_id FK→candidates SET NULL, application_id, position_id, document_id,
                 status[imported|failed], error, imported_at, acked_at)
position_publications.channel  + 'wordpress'   (api_post_id = 'wp:<post_id>', channel_url = scheda sul sito)
app_settings  wpats.enabled · base_url · client_id · verify_tls · ca_file · proxy · timeout · push_on_change · pull_batch
.env.php      PM_WPATS_SECRET
```
ER:
```
job_positions 1─N position_publications (channel=wordpress)
job_positions 1─N candidate_applications N─1 candidates 1─N candidate_documents
candidates 1─N wp_ats_imports ── (wp_uuid) ── pm_ats_applications.uuid  [sito]
```

### WordPress (plugin)
```
{prefix}posts (post_type=pm_job) + postmeta _pm_id, _pm_hash, _pm_data, _pm_location, _pm_contract_type, _pm_department, _pm_remote_policy, …
{prefix}pm_ats_applications (id, uuid UNIQUE, job_post_id, pm_position_id, position_title, dati candidato, consensi + versione informativa,
                             cv_file/cv_name/cv_mime/cv_size/cv_sha256, ip, user_agent, status[pending|synced|error], attempts,
                             last_error, pm_candidate_id, pm_application_id, created_at, fetched_at, synced_at, purged_at)
{prefix}pm_ats_log (id, created_at, action, http_status, ip, detail)        — nessun dato personale
options: pm_ats_settings, pm_ats_secret_enc (AES-256-GCM, chiave dalle salt), pm_ats_private_dir, pm_ats_last_contact, pm_ats_last_jobs_sync
```

## 6. Moduli

| Modulo | Scopo |
|---|---|
| `app/WpAtsClient.php` | HTTP + firma, normalizzazione URL, messaggi d'errore leggibili |
| `app/WpAtsSync.php` | push, pull, import idempotente, registro, lock, `pushOnChange()` |
| `wp_ats_sync.php` | stato, azioni, configurazione (Super Admin), pianificazione, registro |
| `cron_wp_ats.php` | esecuzione pianificata (CLI), uscite 0/1/2 |
| plugin `class-pm-ats-auth` | verifica firma, IP, nonce, blocco |
| plugin `class-pm-ats-jobs` | CPT, sync posizioni, filtri, formattazione testi, JSON-LD JobPosting, template a blocchi |
| plugin `class-pm-ats-applications` | tabella, invio, validazione CV, cartella privata, prelievo, ack, minimizzazione, conservazione (cron giornaliero) |
| plugin `class-pm-ats-rest` | rotte `pm-ats/v1` |
| plugin `class-pm-ats-public` | shortcode, scheda, modulo, token, stile |
| plugin `class-pm-ats-admin` | candidature, impostazioni, registro, colonne posizioni |
| plugin `class-pm-ats-privacy` | esportazione/cancellazione GDPR, testo informativa |

## 7. Viste (frontend)
`[pm_ats_jobs]` → `jobs-list.php` (filtri: testo, sede, contratto, area, modalità — mostrati se ≥ 2 valori) → `job-card.php` ×N → candidatura spontanea `apply-form.php`.
Scheda `pm_job`: `the_content` → `job-single.php` (chip, sezioni nell'ordine Chi siamo · La posizione · Requisiti · Competenze tecniche · Competenze trasversali · Titolo preferenziale · Cosa offriamo · Benefit · Informazioni sull'offerta · nota di genere) → `apply-form.php`.
Archivio `posizioni-aperte` → 301 alla pagina elenco; posizione ritirata → 302 all'elenco con avviso.
