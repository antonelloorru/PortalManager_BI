# Deployment — PortalManager v1.9.52

1. Copiare `app/LinkedInApplicantImporter.php` in `app/`; `import_candidates_linkedin.php` in root.
2. SQL: `sql/migration_v1_9_52.sql` (idempotente). Le colonne vengono comunque create
   dall'importer al primo utilizzo.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Import di un Report candidati LinkedIn | tutti i campi (offerta, retribuzione, progetto, contratto, Q&A, sede, headline, ecc.) compaiono nella scheda candidato |
| Modifica/re-import di un candidato esistente | l'email del candidato resta invariata (mai quella dell'utente loggato) |
| `schema_version` | 1.9.52 |
