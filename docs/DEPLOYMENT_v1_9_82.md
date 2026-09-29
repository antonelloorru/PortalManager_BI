# Deployment — v1.9.82 (richiede v1.9.81)
1. Copiare: `header.php`, `manage_permissions.php`, `menu_customizer.php` (root), `app/MenuManager.php`.
2. Eseguire `sql/migration_v1_9_82.sql`.
3. Verifica: accesso come Responsabile Commerciale → il menu non mostra Service Desk, Relazione IT, Report direzionale,
   Ordinativi Pratix, Organigramma, Tecnologie, Skill matrix, Import progetti; nel topbar «Responsabile Commerciale».
4. Amministrazione → Permessi → Responsabile Commerciale: le pagine compaiono non spuntate e si possono riassegnare.
5. Ripristino dei permessi azzerati (se necessario):
```sql
UPDATE role_permissions r JOIN role_permissions_backup b
    ON b.role_id = r.role_id AND b.page_name = r.page_name AND b.version = '1.9.82'
   SET r.can_view = b.can_view, r.can_create = b.can_create, r.can_edit = b.can_edit,
       r.can_delete = b.can_delete, r.can_export = b.can_export;
```
