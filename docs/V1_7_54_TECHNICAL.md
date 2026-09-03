# PortalManager v1.7.54 — Technical Design

## Scopo
Aggiunta del campo **Classificazione Finanziaria** (Diretto / Indiretto) alla sezione riservata HR *Compensation & Benefit* della scheda dipendente.

## Schema DB (delta)
Tabella `employees`:

| Colonna | Tipo | Default | Posizione |
|---|---|---|---|
| `classificazione_finanziaria` | `ENUM('Diretto','Indiretto')` | `NULL` | dopo `premio_concordato` |

Migration idempotente: `sql/migration_v1_7_54.sql` (`ADD COLUMN IF NOT EXISTS`).

## Relazioni tra viste
Il campo è editabile da due viste sincronizzate sulla stessa colonna:
- `manage_employees.php` — modale anagrafica (form completo, auto-fill JS via mappa `clf → classificazione_finanziaria`, id `e_clf`).
- `employee_profile.php` — scheda dettaglio drill-down (select con `selected` server-side).

Entrambe scrivono via UPDATE compensation separato, eseguito solo con permesso.

## Logica di sicurezza (invariata da v1.7.48/53)
1. **Rendering condizionale**: blocco Compensation reso solo se `can('view','manage_employees_compensation.php')`.
2. **Anti-leak**: se non autorizzato, `classificazione_finanziaria` viene rimosso (`unset`) dall'array dati prima dell'invio al client (DOM/JSON).
3. **Anti mass-assignment**: valore accettato solo se in whitelist `['Diretto','Indiretto']` (`in_array` strict), altrimenti `NULL`.
4. **Update separato**: colonna non presente nell'UPDATE/INSERT base; scritta solo nel blocco compensation guardato dal permesso.

## Logica di calcolo
Nessuna. Campo puramente classificatorio (dimensione di reportistica costi diretti/indiretti).

## File toccati
- `sql/migration_v1_7_54.sql` (nuovo)
- `app/Version.php` (1.7.53 → 1.7.54)
- `db_upgrade.php` (registrazione migration, array versioni, target_ver)
- `manage_employees.php`
- `employee_profile.php`
- `CHANGELOG.md`, `VERSION`

## Note manuali
- **Manuale Amministratore**: il campo appare nel blocco "RISERVATO HR"; visibile/modificabile solo da ruoli con permesso `manage_employees_compensation.php` (default: Super Admin, HR Director).
- **Manuale Utente Finale**: nessun impatto (dato non esposto ai ruoli non-HR).

## Deployment
1. Backup DB.
2. Sovrascrivere i file elencati mantenendo la struttura cartelle (`app/`, `sql/`).
3. Login come Super Admin → auto-bump esegue `migration_v1_7_54.sql`, oppure applicare manualmente lo script SQL.
4. Verifica: colonna presente (`SHOW COLUMNS FROM employees LIKE 'classificazione_finanziaria'`), `app_settings.schema_version = 1.7.54`.
