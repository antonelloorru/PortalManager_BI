# DEPLOYMENT — v1.10.00
Prerequisito: v1.9.99 installata (tabelle `cm_prj*` e dati ASPI).
1. `system_console.php` → Aggiornamento → `update_v1.10.00.zip`.
   In alternativa: `Expand-Archive -Path update_v1.10.00.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `update_manifest.json`, `app/Version.php`, `app/PrjCalc.php`, `app/PrjRepo.php`, `tools/verify_v1_10_00.php`, `sql/`, `docs/`.
2. Eseguire subito `sql/migration_v1_10_00.sql` (indice + versione, idempotente).
3. Stop + Start di Apache, poi Ctrl+F5.
4. Verifica: `php tools/verify_v1_10_00.php --db=demo_portalmanager` → atteso «43 OK, 0 KO». Il test non scrive dati: usa transazioni annullate. Con `--save` registra i calc run di prova.
Rollback: rimuovere `app/PrjCalc.php` e `app/PrjRepo.php`, ripristinare `VERSION` e `app/Version.php` della v1.9.99.
