# Release Notes — PortalManager v1.9.48

Menu: Recruiting & Agenzie → **Importa candidati LinkedIn** (nuova voce)

## Import "Report candidati" LinkedIn (.xlsx)
Nuova funzione di upload/import del Job Applicant Report LinkedIn.

- **Mapping posizione**: ogni candidato è associato alla posizione tramite il campo
  **«ID offerta di lavoro»** del file = `job_positions.linkedin_code` (introdotto in v1.9.47).
- **Azione DB**: per ogni candidato associato viene generata un'**anagrafica completa**
  in `candidates` con tutti i dettagli estratti (nome, cognome, email, telefono, URL
  profilo, città, CAP, sommario/headline, qualifica e azienda attuale, titolo di studio
  e istituto, ID offerta, fase LinkedIn, data candidatura; retribuzione offerta,
  contratto, progetto di assunzione e Q&A finiscono in `notes`) e la **candidatura**
  (`candidate_applications`, stage `cv_received`) sulla posizione mappata.
- **Anteprima prima di scrivere**: dopo l'upload si vede il riepilogo (totali,
  associati, senza offerta, codice non trovato) e le associazioni per posizione; la
  scrittura avviene solo su conferma.
- **Idempotenza**: dedup per email (i candidati già presenti vengono arricchiti, non
  duplicati) e nessuna candidatura doppia sulla stessa posizione.
- **Opzione**: importare anche i candidati senza offerta (ID = 0 / non associabili)
  come candidati potenziali, senza posizione.
- **RBAC**: Super Admin, HR Director, Recruiter (voce registrata anche in Gestione permessi).

## Componenti
```
VERSION                                1.9.48
import_candidates_linkedin.php         pagina (upload, anteprima match, conferma, report)
app/LinkedInApplicantImporter.php      parser+mapping+match+import (usa app/XlsxReader.php già presente)
app/MenuManager.php                    voce menu "Importa candidati LinkedIn"
manage_permissions.php                 entry nel page_map (Recruiting & Agenzie)
sql/migration_v1_9_48.sql              colonne LinkedIn su candidates + linkedin_code (idempotente)
docs/                                  changelog, deployment, technical design
```
Dipendenza: `app/XlsxReader.php` (già nel sistema) e `job_positions.linkedin_code` (v1.9.47).

## QA (sul file reale fornito, 288 righe)
- parse → **285 candidati** mappati; analyze → **137 associati** (Sistemista 92 +
  Security Analyst 45), 148 senza offerta, 0 codice non trovato.
- import → 137 anagrafiche create, 137 candidature (92+45 per posizione), 0 errori;
  campi anagrafici popolati correttamente.
- re-import → 0 nuovi, 137 arricchiti, 0 candidature doppie (idempotenza verificata).
- `php -l` OK su tutti i file; migration RUN1/RUN2 err=0; schema_version → 1.9.48.
