# DEPLOYMENT — v1.9.96
Prerequisito: v1.9.95 installata.
1. `Expand-Archive -Path PortalManager_v1_9_96.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `dir_report.php`, `password_reset.php`, `sql/`, `docs/`.
2. Eseguire `sql/migration_v1_9_96.sql` (solo versione).
3. Ctrl+F5. Verifica: Report direzionale → selezionare un agente → «Scheda Progetto» apre la commessa della riga;
   Reimpostazione password: dal link ricevuto via email il modulo «nuova password» salva correttamente.
Rollback: ripristinare i 2 file PHP della v1.9.95.
