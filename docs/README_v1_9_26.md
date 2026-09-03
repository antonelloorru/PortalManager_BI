# PortalManager v1.9.26 — Careers Portal ALIGNED

## Cambiamento rispetto a v1.9.24/25
Il DB `portalmanager` **contiene già** un modulo recruiting completo:
`candidates`, `candidate_applications`, `candidate_documents`, `job_positions`,
`position_templates`, `position_publications`, ecc. Il release v1.9.24 stava
ricostruendo tabelle in parallelo con collisioni di FK e naming (`skey` vs
`setting_key`, `permission_code` vs `page_name`, tabella `permissions`
inesistente).

**v1.9.26 si aggancia allo schema reale**, aggiunge solo ciò che manca e
riscrive gli endpoint per scrivere sulle tabelle esistenti.

## Cosa aggiunge la migration `migration_v1_9_26.sql`
- **Tabelle nuove (isolate):**
  - `public_api_clients` — chiavi HMAC per portale esterno
  - `public_api_rate_limit` — sliding window + nonce guard
  - `public_api_audit` — registro chiamate API
- **Colonne additive su `candidates`:**
  - `submitted_ip`, `submitted_ua`, `submitted_ref`, `consent_marketing`
- **Colonne additive su `candidate_applications`:**
  - `source_channel` (`internal` | `careers_portal` | ...)
  - `submitted_ip`, `submitted_ua`, `api_request_id`
- **View `v_public_open_positions`** sui campi reali di `job_positions`
  (title, department, location, contract_type, remote_policy, description,
  required_skills, nice_to_have, hard_skills, soft_skills, benefits,
  we_offer, presentation_text, offer_info, positions_expected,
  hires_count, opened_at, target_date), filtrata per `status='open'` e
  finestra `opened_at`/`target_date`.
- **Settings** in `app_settings` con nomi colonna reali
  (`setting_key`, `setting_value`, `description`):
  `careers.cv_max_bytes`, `careers.cv_allowed_mime`,
  `careers.rate_email_check_per_hour`, `careers.rate_apply_per_day`,
  `careers.storage_path` (default `uploads/cv_imports` per allineamento con
  `candidate_documents.file_path`), `careers.notify_email`,
  `careers.public_source_tag` (default `Portale`).
- **RBAC** in `role_permissions` con schema reale
  (`page_name`, `can_view`, `can_create`, `can_edit`, `can_delete`,
  `can_export`): permesso `manage_public_api_clients.php` per Super Admin.
- **Bump versione**: `INSERT INTO app_settings VALUES ('app_version','1.9.26', ...)`.

## Cosa NON tocca
- Struttura di `candidates`, `job_positions`, `candidate_applications`,
  `candidate_documents` (solo colonne additive, mai ALTER destruttivi).
- I dati esistenti.
- Le pagine gestionali esistenti (`manage_positions.php`, `candidate_hire.php`, ecc.).

## Endpoint API riscritti
- `api_public_positions.php` — legge da `v_public_open_positions` (schema reale).
- `api_public_check_email.php` — cerca in `candidates.email` (case-insensitive
  via LOWER/TRIM); verifica `candidate_applications.stage` attive
  (`cv_received`, `screening`, `tech_test`, `hr_interview`, `tech_interview`,
  `offer_sent`).
- `api_public_apply.php`:
  - Upsert in `candidates` (source='Portale', gdpr_consent=1, gdpr_date=CURDATE()).
  - Inserisce CV in `candidate_documents` con `doc_type='cv'` e nome file
    `cand_<id>_cv_<ts>.<ext>` (allineato al pattern esistente).
  - Inserisce `candidate_applications` con `stage='cv_received'` e
    `source_channel='careers_portal'`. UNIQUE `(candidate_id, position_id)`.
  - Cover letter opzionale → `candidate_documents` con `doc_type='lettera'`.
- Naming e schema di `app_settings`/`role_permissions` allineati via
  helper `App\CareersSettings`.

## Installazione
1. Esegui via SqlRunner o mysql CLI:
   ```
   mysql -uroot portalmanager < sql/migration_v1_9_26.sql
   ```
2. Copia i file PHP nel root del gestionale (sovrascrive gli endpoint API
   e le classi in `app/`):
   ```
   api_public_positions.php
   api_public_check_email.php
   api_public_apply.php
   app/PublicApiAuth.php
   app/ApiResponse.php
   app/CvUploadValidator.php
   app/CareersSettings.php
   manage_public_api_clients.php
   config/api_secrets.sample.php  → rinomina in config/api_secrets.php (fuori repo)
   ```
3. Portale esterno (DMZ): copia `public/careers/` invariato.
4. Verifica:
   ```sql
   SELECT * FROM pm_migration_sql WHERE version='1.9.26';
   SELECT setting_key,setting_value FROM app_settings WHERE setting_key='app_version';
   SHOW COLUMNS FROM candidates LIKE 'submitted_%';
   SHOW COLUMNS FROM candidate_applications LIKE 'source_channel';
   SELECT COUNT(*) FROM v_public_open_positions;
   ```

## Verifica test end-to-end effettuati
- Migration RUN1: 12 statement OK
- Migration RUN2 (idempotenza): 0 modifiche
- View su `job_positions` con 1 riga `status='open'` → ritorna il record
- ALTER additivi su `candidates` e `candidate_applications`: colonne
  presenti come da schema atteso
- Bump `app_version = 1.9.26` in `app_settings`
- Riga in `pm_migration_sql`: `1.9.26 / migration_v1_9_26.sql`
