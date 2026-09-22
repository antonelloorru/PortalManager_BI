# PortalManager v1.7.55 — Technical Design

## Scopo
Importer dedicato al report XLSX Cisco *Certifications by Individual*
(`cpapp_admin_cnt_xls_report_CertInd.xlsx`) per aggiornare le certificazioni
possedute dai dipendenti.

## Modulo
`cert_import_cisco.php` — flusso a 3 step (upload → preview → execute), stato in `$_SESSION`.

### Mapping colonne XLSX
| Col | Header | Uso |
|---|---|---|
| E/G | First/Last Name | match dipendente (fallback) |
| H | Email | match dipendente (primario) |
| I | CCO Login Id | note |
| J | Training ID | `user_certifications.certificate_code` + note |
| K | Certification | `certifications.code` (match catalogo) |
| L | Certification Description | `certifications.name` |
| M | Cert Date | `issue_date` (obbligatoria per import) |
| N | Expiry Date | `expiry_date` + calcolo stato |
| O | Re-Cert Date | note |

Header risolto case-insensitive con sinonimi. Date `d-M-Y` (mesi EN) → ISO via `DateTime::createFromFormat`.

## Logiche di calcolo
- **Match dipendente**: `emp_by_email` (business+personal, lower) → `emp_by_name` (first+last, lower). Righe non risolte: escluse e segnalate.
- **Filtro acquisite**: importate solo righe con Cert Date valorizzata; le tracce d'esame (senza data) sono elencate ma escluse.
- **Stato**: `expired` se Expiry < oggi; `expiring` se ≤ 90 giorni; altrimenti `active`.
- **Livello/Categoria** cert catalogo: inferenza euristica (CCNA→Associate, CCNP→Professional, CCIE→Expert, Specialist; serie 700-xxx/Sales→commerciale, altrimenti tecnica).

## Relazioni tra viste / tabelle
- `brands` — brand fisso "Cisco" (auto-create, colore `#049fd9`).
- `technologies` — fallback "Generic" (vincolo `certifications.technology_id` NOT NULL).
- `certifications` — UPSERT logico per (brand_id, code) → (brand_id, name); auto-create se assente.
- `user_certifications` — **UPSERT** per (employee_id, certification_id): se esiste aggiorna `issue_date/expiry_date/status/certificate_code/notes`, altrimenti INSERT.

Idempotenza: re-import dello stesso file aggiorna (non duplica) i record esistenti. Opzione "salta esistenti" per import puramente additivo. Operazione in transazione atomica con `write_log` finale.

## Schema DB
Nessuna modifica strutturale (usa tabelle esistenti). Migration `sql/migration_v1_7_55.sql`: solo registrazione permessi `role_permissions` (INSERT IGNORE) + bump `app_settings`.

## Permessi
`cert_import_cisco.php` — default view/create: Super Admin, HR Director, Brand Manager, Direttore IT.

## File toccati
- `cert_import_cisco.php` (nuovo)
- `sql/migration_v1_7_55.sql` (nuovo)
- `app/Version.php` (1.7.54 → 1.7.55)
- `db_upgrade.php`, `app/MenuManager.php`, `manage_permissions.php`
- `CHANGELOG.md`, `VERSION`

## Note manuali
- **Amministratore**: menu *Competenze & Formazione → Import certificazioni Cisco*. Caricare il file XLSX esportato dal portale Cisco; verificare in anteprima i match; importare. I dipendenti non riconosciuti vanno prima anagrafati o allineati per email.
- **Utente finale**: le certificazioni importate compaiono in *Report certificazioni* e nella scheda dipendente.

## Deployment
1. Backup DB.
2. Sovrascrivere i file mantenendo la struttura cartelle (`app/`, `sql/`).
3. Login Super Admin → auto-bump esegue `migration_v1_7_55.sql`, o applicarlo manualmente.
4. Verifica: pagina raggiungibile, permessi presenti (`SELECT * FROM role_permissions WHERE page_name='cert_import_cisco.php'`), `schema_version = 1.7.55`.
