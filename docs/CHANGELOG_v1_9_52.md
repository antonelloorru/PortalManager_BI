# Release Notes — PortalManager v1.9.52 — Import Candidati LinkedIn (fix)

File: `app/LinkedInApplicantImporter.php`, `import_candidates_linkedin.php`

## Issue 1 — Data Mapping incompleto (RISOLTO)
Molti campi del file XLSX LinkedIn non venivano scritti nella scheda candidato: solo un
sottoinsieme aveva una colonna dedicata, gli altri finivano solo in `notes`, e i campi
la cui colonna non esisteva venivano scartati silenziosamente.

Ora è implementato il **full mapping di tutti i 28 campi XLSX** su colonne dedicate di
`candidates` (create in modo idempotente da `ensureSchema()` e dalla migration):
Nome, Cognome, Email, Telefono, Località→city, CAP→postal_code, Sommario→headline,
Qualifica attuale→current_title, Azienda attuale→current_company, Data inizio→current_since,
Titolo di studio→education_level, Istituto→education_institute, URL profilo→linkedin_url,
Data candidatura→applied_at, Fase→li_stage, ID offerta→li_job_id, Qualifica(offerta)→offer_title,
URL offerta→offer_url, ID ATS→li_ats_id, Retribuzione min/max→pay_min/pay_max,
Valuta→pay_currency, Periodo→pay_period, ID/Titolo progetto→hiring_project_id/hiring_project_title,
ID/Nome contratto→li_contract_id/li_contract_name, Domande selezione→screening_qa.

Verifica sul file reale (285 candidati): tutte le colonne popolate dove il file ha dati
(es. headline 285/285, city 285/285, offer_title 137/137 sui candidati con offerta,
screening_qa dove presenti); nessun campo perso.

## Issue 2 — Update Bug: email sovrascritta (RISOLTO)
In modifica/arricchimento candidato l'email poteva essere sovrascritta con quella
dell'utente in sessione. L'update del candidato (`enrich()`) ora **isola esplicitamente
l'email ed è escluso dal SET** (doppia salvaguardia): l'email è un dato del candidato,
proviene solo dal file XLSX in fase di INSERT e non viene mai toccata in UPDATE, né
derivata dalla sessione. L'importer riceve solo lo *user id* attore (per `added_by`),
mai un'email.

Verifica: 0 candidati con email = utente sessione; re-import (enrich) lascia l'email
INVARIATA.

## QA
- `php -l` OK; migration RUN1/RUN2 err=0; `;` nei commenti = 0; schema_version → 1.9.52.
- Import completo sul file di produzione: 285 candidati mappati, 137 candidature; tutte
  le colonne LinkedIn presenti e valorizzate.

## File
```
VERSION                              1.9.52
app/LinkedInApplicantImporter.php    full mapping (28 campi) + email isolation
import_candidates_linkedin.php       pagina import (invariata, inclusa)
sql/migration_v1_9_52.sql            ADD COLUMN IF NOT EXISTS (23 colonne) + bump
docs/                                changelog, deployment
```
