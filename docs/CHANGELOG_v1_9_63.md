# PortalManager v1.9.63 — Import certificazioni Omnissa

## Pagina
Nuova `cert_import_omnissa.php` (Competenze & Formazione → **Import certificazioni Omnissa**).

## Mapping (calibrato sul report reale ReportCertificationOmnissa.xlsx, 18 colonne)
- Codice certificazione ← `Certification & Accreditation: ID` (es. CA-228032)
- Nome certificazione   ← `Record #: Full Name` (es. "VTSP Mobility Management 2020")
- Dipendente            ← `Full Name` + `Email` (match email → nome)
- Categoria             ← `Type` (VTSP / VSP / VOP-SE / VCP …)
- Date                  ← `Date Received` (issue), `Expiration Date`, `Last Renewal Date`
- Stato                 ← `Status` del report (Expired → expired), altrimenti derivato da scadenza
Header risolti tramite alias case-insensitive (regge anche varianti dei nomi colonna).

## ETL
- Parser XLSX nativo (ZipArchive + SimpleXML, zero dipendenze) con conversione delle
  **date seriali Excel** (base 1899-12-30).
- Brand fisso **Omnissa** (auto-creato); technology fallback "Generic".
- Catalogo `certifications`: match (brand_id, code) → (brand_id, name); auto-create.
- **UPSERT** `user_certifications` per (employee_id, certification_id): esiste → UPDATE
  (issue/expiry/status/certificate_code, **COALESCE** sulle date); non esiste → INSERT.
  Transazione ACID; dipendenti non trovati → skip.
- Flow: upload → anteprima (mapping + match) → esecuzione.

## Registrazione
Voce di menu, `Router::PAGES`, `manage_permissions`, seed `role_permissions` (ruoli 1,2).

## QA (file reale)
- Colonne rilevate correttamente; **36/36 date convertite**; 12 dipendenti nel report.
- Logica upsert verificata su schema reale (import/update/skip, auto-create catalogo,
  COALESCE preserva issue_date, stato da scadenza). `php -l` OK; migration RUN1/RUN2 err=0;
  schema_version → 1.9.63.

## File
```
VERSION                       1.9.63
cert_import_omnissa.php        importer calibrato sul report Omnissa
app/MenuManager.php            voce di menu
app/Router.php                 whitelist PAGES
manage_permissions.php         page_map
sql/migration_v1_9_63.sql      permessi + bump
docs/
```
