# RELEASE CHECKLIST — v1.10.08

| Controllo | Esito |
|---|---|
| VERSION = 1.10.08, PM_VERSION = 1.10.08, app_version/schema_version/release_label = 1.10.08 | ✔ |
| migration_v1_10_08.sql idempotente (RUN1/RUN2 err=0 su pmrepo e pm1980), nessun `;` nei commenti | ✔ |
| `php -l` su tutti i PHP modificati | ✔ |
| verify_v1_10_08.php: 19 OK, 0 KO su pmrepo e pm1980 | ✔ |
| verify_v1_10_07.php: 28 OK, 2 KO attesi (solo i controlli del numero di versione 1.10.07) | ✔ |
| Stessa logica di connessione gestionale/SOC (SourceDb::configFromRow + connect) | ✔ |
| Prova: credenziali proprie errate → 1698/1045 con indicazione; eredità → connessione ok; database inesistente → 1044 con GRANT | ✔ |
| Pipeline CLI con credenziali ereditate: DB SOC ok, 425 righe | ✔ |
| Browser: opzione, campi in sola lettura, Salva, Test connessione, nessun errore PHP | ✔ |
| Password mai copiata né mostrata (solo cifrata in cm_source_db) | ✔ |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, RELEASE_CHECKLIST | ✔ |
| update_manifest.json cumulativo da 1.10.06 | ✔ |
