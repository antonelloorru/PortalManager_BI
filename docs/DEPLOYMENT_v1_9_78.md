# Deployment — v1.9.78 (richiede v1.9.77)
1. Copiare in root: `it_service.php`, `service_desk.php`, `dir_report.php`, `dgb_activities.php`.
2. Copiare in `app/`: `PmContractFilter.php` (nuovo), `ItServiceModel.php`, `it_service_print.php`,
   `SdModel.php`, `DirModel.php`, `dir_report_print.php`, `DgbModel.php`.
3. Eseguire `sql/migration_v1_9_78.sql` (idempotente: solo indici e versione).
4. Verifica:
   - Relazione di Servizio IT → Filtri → «Codice Contratto / PM Project»: scegliere una commessa, Applica;
   - aprire Service Desk, Report direzionale, Attività & Rendicontazione DGB: banner «Filtro contratto
     attivo» e dati ristretti; «Rimuovi filtro contratto» lo toglie da tutte le pagine;
   - XLSX: foglio «Filtri»; stampe: contratto nell'intestazione;
   - dalla scheda commessa, link DGB (`contract=<id>`): commessa preselezionata.
5. Rollback: ripristinare i file di v1.9.77 (gli indici possono restare).
