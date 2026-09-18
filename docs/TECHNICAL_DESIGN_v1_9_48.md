# Technical Design — v1.9.48

## Flusso
`import_candidates_linkedin.php` (upload .xlsx → anteprima in sessione → conferma) usa
`LinkedInApplicantImporter`:
- `parse($path)`: legge il foglio "Candidati" via `XlsxReader` (rilevamento header per
  hint; ripiego sugli altri fogli) e mappa le 28 colonne LinkedIn su chiavi canoniche.
- `analyze($rows)`: risolve le posizioni con una sola query
  `job_positions WHERE linkedin_code IN (...)` e classifica ogni riga
  (associato / senza offerta / codice non trovato).
- `import($rows,$potential)`: in transazione, per ogni riga costruisce l'anagrafica
  (intersecando i campi con le colonne reali di `candidates`), deduplica per email
  (COALESCE-enrich sui campi vuoti), inserisce e collega `candidate_applications`
  (nessun doppione: check esistenza prima dell'INSERT).

## Mapping chiave
«ID offerta di lavoro» (col. file) → `job_positions.linkedin_code` → `position_id`.
`0`/vuoto/`N/A` = senza offerta. Codice normalizzato (rimozione `.0` da valori numerici).

## Schema
`candidates` +city, +postal_code, +headline, +current_title, +current_company,
+current_since, +li_job_id, +li_stage, +applied_at, (+education_level, +education_institute
se assenti). Auto-migration nella pagina (`ensureSchema`) e migration SQL idempotente.
Nessuna modifica distruttiva.
