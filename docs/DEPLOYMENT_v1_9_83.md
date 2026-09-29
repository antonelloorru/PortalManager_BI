# Deployment — v1.9.83 (richiede v1.9.82)
1. Copiare in root: `access_control.php`, `manage_permissions.php`, `manage_roles.php`, `rbac_sync.php` (nuovo).
2. Copiare in `app/`: `PermissionCatalog.php` (nuovo), `RbacSync.php` (nuovo), `MenuManager.php`, `Router.php`.
3. Eseguire `sql/migration_v1_9_83.sql`.
4. Aprire **Sistema → Sincronizzazione permessi** (`rbac_sync.php`): «Simula», leggere il report, «Sincronizza ora».
   In alternativa la prima sincronizzazione parte da sola alla prima pagina aperta.
5. Definire le regole di seeding per i ruoli che devono ricevere in automatico le pagine nuove (es. Direttore IT →
   sezione «Gestione Commesse» → vista). Senza regole le pagine nuove restano visibili solo al Super Admin.
6. Rivedere gli avvisi «Permesso senza effetto» in Permessi (pagine bloccate nel codice per quel ruolo).
Rollback: ripristinare i file di v1.9.82. Tabelle e colonne nuove non interferiscono con la versione precedente.
