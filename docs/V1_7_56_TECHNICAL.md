# PortalManager v1.7.56 — Technical Design

## Scopo
Estende `cert_import_cisco.php` (v1.7.55) per consentire l'import di certificazioni Cisco **prive di data di conseguimento** (tracce/esami non ancora acquisiti).

## Schema DB (delta)
`user_certifications.issue_date`: `DATE NOT NULL` → `DATE DEFAULT NULL`.
Migration idempotente `sql/migration_v1_7_56.sql` (`MODIFY COLUMN`).

## Logica
- **Preview**: righe con Cert Date pre-selezionate; righe senza data (con dipendente riconosciuto) rese selezionabili dal toggle *"Includi certificazioni senza data"* (classe CSS `nodate`, abilitazione JS). Righe senza match dipendente restano non importabili.
- **Execute**: rimosso lo skip delle righe senza data; `issue_date` inserito come `NULL`.
- **UPSERT difensivo**: in aggiornamento di una cert già presente, i campi provenienti da una riga senza data non sovrascrivono valori esistenti — `issue_date=COALESCE(?, issue_date)`, idem `expiry_date` e `certificate_code`. Evita la perdita di date acquisite da import precedenti.
- **Stato**: righe senza expiry → `active`.

## File toccati
- `cert_import_cisco.php`
- `sql/migration_v1_7_56.sql` (nuovo)
- `app/Version.php` (1.7.55 → 1.7.56), `db_upgrade.php`
- `CHANGELOG.md`, `VERSION`

## Deployment
1. Backup DB.
2. Sovrascrivere i file (struttura cartelle `app/`, `sql/`).
3. Login Super Admin (auto-bump) o applicare `sql/migration_v1_7_56.sql`.
4. Verifica: `SHOW COLUMNS FROM user_certifications LIKE 'issue_date'` → Null=YES; `schema_version = 1.7.56`.

## Note manuali
- **Amministratore**: per importare anche gli esami/tracce senza data, spuntare *"Includi certificazioni senza data"* prima di importare. Le cert senza data risultano attive senza scadenza; una successiva reimportazione con data ne aggiorna il conseguimento.
