# CHANGELOG v1.10.05 — Sito web WordPress: posizioni aperte e candidature

Data: 2026-10-06 · Software 1.10.05 · Schema 1.10.05 · Upgrade `sql/migration_v1_10_05.sql` · Plugin `pm-ats` 1.0.0

## PortalManager
- Nuova pagina **Recruiting › Sito web (WordPress)** (`wp_ats_sync.php`): stato, test, invio posizioni, prelievo candidature, configurazione (Super Admin), pianificazione, registro.
- `app/WpAtsClient.php`: client HTTPS con firma HMAC-SHA256 (stesso schema di PublicApiAuth), TLS, CA, proxy.
- `app/WpAtsSync.php`: push delle posizioni aperte (vista `v_public_open_positions`), pull idempotente delle candidature con verifica del CV, conferma al sito, lock, registro.
- `cron_wp_ats.php`: esecuzione pianificata.
- `recruiting_posizioni.php`: invio immediato al sito dopo salva/approva/pausa/riapri/chiudi/elimina (se abilitato).
- Pubblicazioni: nuovo canale `wordpress` con URL della scheda pubblica.
- Migration: `wp_ats_sync_log`, `wp_ats_imports`, canale `wordpress`, impostazioni `wpats.*`, permessi `wp_ats_sync.php`.
- Segreto in `.env.php` (`PM_WPATS_SECRET`).

## Plugin WordPress `pm-ats` 1.0.0 (`integrations/wordpress/`)
- API REST `pm-ats/v1` firmate HMAC con IP consentiti, anti-replay, blocco.
- Posizioni (CPT `pm_job`) alimentate solo da PortalManager; dati strutturati JobPosting.
- Shortcode `[pm_ats_jobs]`, `[pm_ats_apply]`, `[pm_ats_count]`; scheda posizione con modulo; candidatura spontanea.
- Modulo accessibile, senza dipendenza da JavaScript, con anti-spam (honeypot, tempo minimo, limite per IP) e validazione CV sul contenuto.
- CV in cartella privata, eliminati dopo l'import; conservazione configurabile; strumenti GDPR.
- Aspetto configurabile e template sovrascrivibili dal tema; template a blocchi per i temi FSE.
- Amministrazione: Candidature, Impostazioni, Registro.
