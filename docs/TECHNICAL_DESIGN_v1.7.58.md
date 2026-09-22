# Technical Design — v1.7.58

## Scopo
Sostituzione del campo free-text `employees.department` con un modello relazionale normalizzato (lookup + storico), per integrità referenziale, censimento controllato dei valori e tracciabilità delle modifiche al parametro.

## Moduli coinvolti
| Modulo | Ruolo |
|---|---|
| `manage_departments.php` | CRUD dipartimenti + storico. POST handler PRG prima di `header.php`. Auto-migration difensiva. |
| `manage_employees.php` | Form completo: `<select name="department_id">` + POST handler + sync testo legacy. |
| `employee_profile.php` | Vista dettaglio: select allineato al modal. |
| `app/MenuManager.php` | Voce "Dipartimenti / Unità Org." (sezione Amministrazione). |
| `manage_permissions.php` | `$page_map` → sezione "Anagrafica & HR". |
| `db_upgrade.php` | Metadata versioni + mapping `$UPGRADE_SQL['1.7.58']`. |
| `sql/migration_v1_7_58.sql` | Schema + FK + backfill + RBAC + allineamento versione. |

## Schema ER
```
departments (1) ────< (N) employees
   id (PK)                 department_id (FK, ON DELETE SET NULL, ON UPDATE CASCADE)
   name (UNIQUE)           department (testo legacy, sync col nome scelto)
   value_type ENUM
   is_active
   created_at / updated_at

departments (1) ────< (N) department_history
   id                      department_id (NULLable per DELETE)
                           action ENUM(CREATE,UPDATE,DELETE)
                           old_* / new_* (name, value_type, is_active)
                           changed_by (user_id), changed_at
```

## Logiche
- `value_type` obbligatorio: `Servizio a Valore` | `Non a Valore`. Validazione UI (`required`) + server-side (`in_array` strict).
- Storicizzazione: ogni CREATE/UPDATE/DELETE genera riga in `department_history` con snapshot old→new e autore. Su UPDATE si scrive solo se cambia almeno un campo.
- Integrità FK: eliminare un dipartimento non cancella i dipendenti; `department_id` → NULL.
- Sync legacy: al salvataggio dipendente, `employees.department` (testo) è aggiornato col nome del dipartimento selezionato, per retrocompatibilità con viste/export che leggono ancora il campo testuale (es. modal list, scheda).
- Validazione defensive: prima dell'UPDATE dipendente si verifica che `department_id` esista e sia attivo; se non valido → NULL.

## Migrazione dati (backfill)
Guarded via `information_schema.COLUMNS`: se esiste `employees.department`, ogni valore distinto non vuoto è censito in `departments` (default `Non a Valore`), poi si popola `employees.department_id` per join sul nome. Ri-eseguibile senza duplicazioni (`INSERT IGNORE` + `WHERE department_id IS NULL`).

## Sicurezza
- Permesso pagina `manage_departments.php`: `view` per lettura, `edit`/`create` per mutazioni. Seed RBAC per Super Admin (role_id=1) nella migration.
- CSRF su tutti i POST (`Csrf::verify()` / `csrf_field()`).
- PRG (`redirect_self()`), messaggistica via `$_SESSION['flash_msg']`.
- Audit: `write_log('Departments',...)` + storico dedicato `department_history`.
