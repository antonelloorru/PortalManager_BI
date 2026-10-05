# DEPLOYMENT — v1.9.89

Prerequisito: v1.9.88 installata e migration v1.9.88 eseguita.

1. Backup file + DB.
2. `Expand-Archive -Path PortalManager_v1_9_89.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/ItServiceModel.php`, `app/it_service_print.php`, `it_service.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_89.sql` (SQL Runner). Idempotente.
4. Ricaricare la pagina con Ctrl+F5.
5. Verifica: Relazione di Servizio IT → Dettaglio raggruppato per «Linea di servizio»: la riga Totale delle «Ore reperibilità»
   coincide con il valore dell'andamento / KPI; in «Giorni lavorati per persona» Ordinarie + Fuori orario + Reperibilità = Ore totali;
   il riquadro «Dettaglio ore non valorizzate» somma alle ore non valorizzate del KPI.

Rollback: ripristinare i 3 file PHP della v1.9.88.
