# Deployment — PortalManager v1.9.57
1. Copiare `app/UrlHelper.php` in `app/`.
2. SQL: `sql/migration_v1_9_57.sql` (solo allineamento versione).

## Verifica
- Personalizza menu → clic su un ruolo: carica la config del ruolo, resta sulla pagina (niente Home).
- Toggle Utente/Ruolo: URL resta su menu_customizer con scope_type/scope_id.
- schema_version = 1.9.57.
