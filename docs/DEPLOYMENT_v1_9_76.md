# Deployment — v1.9.76 (richiede v1.9.75)
1. Copiare in root: `dgb_activities.php`, `it_service.php`; in `app/`: `DgbModel.php`,
   `ItServiceModel.php`, `it_service_print.php`.
2. Eseguire `sql/migration_v1_9_76.sql`.
3. Verifica:
   - Attività & Rendicontazione DGB → Distribuzione carico (vista giornaliera, luglio 2026):
     segmenti viola «reperibilità» nei giorni con interventi in disponibilità;
   - Relazione di Servizio IT: segmento viola nei grafici mensile e giornaliero; «Reperibilità»
     con l'accento nelle barre per modalità e nel filtro; colonna «Reper.» con «Sì» / «—».
