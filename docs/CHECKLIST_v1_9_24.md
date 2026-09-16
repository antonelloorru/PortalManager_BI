# PortalManager v1.9.24 — Release Checklist (Zero-Omission Policy)

## Componenti nel pacchetto ZIP
- [x] `VERSION` = `1.9.24`
- [x] `sql/migration_v1_9_24.sql` — nuove tabelle/viste/permessi/settings
- [x] `sql/upgrade_1_9_23_to_1_9_24.sql` — script upgrade v1.9.23 → v1.9.24 (additivo)
- [x] `app/PublicApiAuth.php` — HMAC + rate limit + audit
- [x] `app/ApiResponse.php` — output JSON + CORS + headers sicurezza
- [x] `app/CvUploadValidator.php` — validazione MIME/size/hash + storage
- [x] `api_public_positions.php` — endpoint elenco posizioni
- [x] `api_public_check_email.php` — endpoint verifica email
- [x] `api_public_apply.php` — endpoint invio candidatura
- [x] `manage_job_positions.php` — CRUD posizioni interno
- [x] `manage_applications.php` — kanban candidature + promozione dipendente
- [x] `manage_public_api_clients.php` — emissione chiavi HMAC
- [x] `download_cv.php` — download sicuro CV
- [x] `public/careers/index.html` — pagina candidato
- [x] `public/careers/assets/careers.js` — chiamate BFF
- [x] `public/careers/assets/careers.css` — stile
- [x] `public/careers/bff.php` — Backend-for-Frontend firma HMAC
- [x] `public/careers/bff_config.sample.php` — template config
- [x] `config/api_secrets.sample.php` — template segreti
- [x] `uploads/candidates/.htaccess` — deny direct access
- [x] `docs/technical_design_v1_9_24.md`
- [x] `docs/admin_manual_v1_9_24.md`
- [x] `docs/user_manual_v1_9_24.md`
- [x] `docs/deployment_v1_9_24.md`

## QA obbligatoria
- [x] `php -l` clean su tutti i file .php
- [x] Migration idempotente: `IF NOT EXISTS`, `INSERT IGNORE`, `CREATE OR REPLACE VIEW`
- [x] Nessun `;` nei commenti SQL
- [x] Compat MariaDB 10.4 (no clausole non supportate)
- [x] RBAC: 3 nuovi permessi + assegnazione ruoli 1/2/5
- [x] CSRF su tutti i form interni
- [x] Rate limit doppio (IP + client) su tutti gli endpoint pubblici
- [x] Audit riga per riga in `public_api_audit`
- [x] Path traversal difeso in `download_cv.php`
- [x] MIME reale via `finfo`, no fiducia sul client
- [x] Consenso privacy obbligatorio server-side
- [x] Deduplica candidato via colonna GENERATED `email_norm`
- [x] Deduplica candidatura via UNIQUE `(candidate_id, position_id)`
- [x] Storage CV fuori webroot (raccomandato) + `.htaccess` protettivo
- [x] Secret HMAC mai in DB (solo SHA256), caricato da ENV o file protetto
- [x] Nonce replay guard con TTL 600s
- [x] Clock skew ±300s

## Documentazione
- [x] Technical Design con schema ER, endpoint, flussi, security
- [x] Manuale Amministratore
- [x] Manuale Utente Candidato
- [x] Guida Deployment con rollback

## Versionamento coerente
- [x] `VERSION` = 1.9.24
- [x] Registro `pm_migration_sql` aggiornato dalla migration stessa
- [x] Sequenza upgrade v1.9.23 → v1.9.24 in `sql/upgrade_1_9_23_to_1_9_24.sql`
- [x] Nessuna interferenza con moduli esistenti (commesse/DGB/finance/anagrafica)

## Ultima verifica prima del rilascio
- [ ] Test end-to-end su ambiente staging
- [ ] Backup DB produzione
- [ ] Comunicazione alla direzione HR della URL portale esterno
- [ ] Revoca eventuali chiavi API di test
