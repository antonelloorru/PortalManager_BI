# DEPLOYMENT — v1.9.88

Prerequisito: v1.9.87 installata (stesso modulo).

1. Backup file + DB.
2. `Expand-Archive -Path PortalManager_v1_9_88.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/ItServiceModel.php`, `app/it_service_print.php`, `it_service.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_88.sql` (SQL Runner). Idempotente. Ridefinisce `v_cm_it_giorni_base` (+ colonna `valorizzata`,
   nessuna linea esclusa), riallinea le viste di riepilogo, elimina `v_cm_it_giorni_tutte`, invalida la copia materializzata
   `snap_v_cm_it_giorni_base` (si ricostruisce al prossimo aggiornamento delle copie; nel frattempo si legge la vista).
4. Facoltativo: Console di sistema → aggiornamento copie dati, per rigenerare subito la copia della vista.
5. Verifica: Relazione di Servizio IT, un mese qualsiasi senza filtri → «Giorni lavorati: Ore» = KPI «Ore»;
   ore valorizzate + non valorizzate = ore; le ripartizioni per codice linea e area tecnologica sommano al totale.

Rollback: ripristinare i 3 file PHP e rieseguire la definizione della vista da `sql/upgrade_1_7_56_to_1_9_23.sql` (blocco v1.9.18).
