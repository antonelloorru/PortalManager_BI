# Technical Design — v1.9.83 — Auto-sync RBAC

## Fonti di verità
| Dato | Fonte | Note |
|---|---|---|
| Voci di menu | `MenuManager::defaultMenu()` | codice |
| Pagine servite | `Router::PAGES` (anonimizzate), `Router::RESTRICTED` (percorso diretto) | whitelist di sicurezza, resta esplicita |
| Etichette curate | `PermissionCatalog::sections()` | facoltative |
| Blocchi codificati | `MenuManager::HARD_GATES` | allineati ai controlli nelle pagine |
| Catalogo effettivo | tabella `permissions` (`is_page = 1`) | scritta solo da RbacSync |
| Concessioni | `role_permissions`, `user_permissions` | scritte da Permessi, regole di seeding, ruolo modello |

## Schema (ER)
```
roles 1─N role_permissions(role_id, page_name → permissions.name, can_*)   [FK cascade]
users 1─N user_permissions(user_id, page_name, can_* NULL = eredita)       [FK cascade]
permissions(name, label, module=sezione, is_page, in_menu, in_router, in_curated, icon, sort_order,
            hard_gate_max_role, is_active, first_seen_at, last_seen_at)
rbac_seed_rules(role_id, scope all|section|page, target, can_*, is_active, note)
rbac_sync_log(run_at, trigger_type, mode, status, changes, warnings, report JSON, user_id)
app_settings: rbac_autosync_enabled, rbac_baseline_at, rbac_last_sync_at
uploads/.rbac_sync_signature   firma del codice all'ultima sincronizzazione riuscita
```
## Trigger
- `access_control.php` → `RbacSync::autoSync()`: SHA-256 del catalogo del codice + versione; se diversa dal file di
  firma, `register_shutdown_function` → `run(apply)` sotto `GET_LOCK('pm_rbac_sync', 0)`.
- `manage_roles.php` dopo creazione/eliminazione; `rbac_sync.php` a mano.
## Regole di seeding
Applicate a una pagina solo quando è **nuova** (assente dal catalogo e comparsa dopo `rbac_baseline_at`).
Combinazione con righe esistenti: `GREATEST` (le regole aggiungono, non tolgono). Regole in conflitto con
`HARD_GATES` non applicate e segnalate.
## Sicurezza
Default deny ovunque (righe esplicite a 0, DEFAULT 0 dalla v1.9.82); nessuna regola predefinita; Super Admin fuori
matrice; pagina di gestione solo Super Admin, CSRF su tutte le azioni, registro delle esecuzioni.
