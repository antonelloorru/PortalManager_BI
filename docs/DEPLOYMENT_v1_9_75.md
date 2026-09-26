# Deployment — v1.9.75 (richiede v1.9.74)
1. Copiare in `app/`: `PmOrario.php` (nuovo), `DgbModel.php`, `ItServiceModel.php`, `SdModel.php`;
   in root: `perf_center.php`.
2. Eseguire `sql/migration_v1_9_75.sql`.
3. Verifica sul caso Imbrosciano, 16/09/2026:
   - Attività & Rendicontazione DGB (incaricato, 16/09): 8 h in orario, 6 h fuori orario, 6 h extra;
   - Relazione di Servizio IT e Service Desk: 8 h ordinarie, 7 h fuori orario (6 se `ore` esclude il viaggio).
4. Sistema → Prestazioni → «Ripartizione oraria»: se Service Desk indica «fascia del modulo
   intero», la vista `v_cm_sd_moduli` non espone il codice del modulo: aggiungere la colonna
   `modulo` (codice del rapportino) alla vista per attivare la ripartizione.
