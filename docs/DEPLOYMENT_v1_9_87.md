# DEPLOYMENT — v1.9.87

1. Backup file + DB.
2. `Expand-Archive -Path PortalManager_v1_9_87.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/ItServiceModel.php`, `it_service.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_87.sql` (SQL Runner). Idempotente.
4. Requisito DB: l'utente applicativo deve poter creare tabelle temporanee (`CREATE TEMPORARY TABLES`, incluso in root/ALL).
   In assenza il modello ripiega automaticamente su sottoquery (stesso risultato, più lento).
5. Verifica: Gestione Commesse → Relazione di Servizio IT → Stato commessa = «Chiusa»: KPI, grafici, costi, giorni,
   riepilogo per contratto e dettaglio per commessa mostrano le sole commesse chiuse; Aperta + Chiusa + Sospesa = totale.

Rollback: ripristinare i 2 file PHP precedenti.
