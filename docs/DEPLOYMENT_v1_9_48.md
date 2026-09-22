# Deployment — PortalManager v1.9.48

## File
- `import_candidates_linkedin.php` → root del webroot
- `app/LinkedInApplicantImporter.php` → cartella `app/`
- `app/MenuManager.php` → sostituisce l'esistente (aggiunge la voce di menu)
- `manage_permissions.php` → sostituisce l'esistente (aggiunge l'entry permessi)
- `sql/migration_v1_9_48.sql` → SQL Runner (idempotente; la pagina auto-crea comunque le colonne)

Requisiti runtime: estensioni PHP **zip**, **XMLReader** (usate da `app/XlsxReader.php`).
Prerequisito dati: `job_positions.linkedin_code` (v1.9.47) valorizzato con l'ID offerta LinkedIn della posizione.

Via system_console.php: Aggiornamento (ZIP) → `PortalManager_v1_9_48.zip` → Analizza → Applica.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Menu Recruiting & Agenzie | compare "Importa candidati LinkedIn" |
| Carica il Report candidati .xlsx → Analizza | riepilogo con associati/senza offerta e associazioni per posizione |
| Conferma import | anagrafiche create + candidature sulle posizioni mappate |
| Pipeline candidati | i candidati importati sono presenti e collegati alla posizione |
| Re-import stesso file | nessun duplicato (dedup per email) |
