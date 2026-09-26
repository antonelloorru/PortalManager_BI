# Deployment — v1.9.74 (richiede v1.9.73 installata)
1. Copiare `it_service.php` in root; `app/ItServiceModel.php` e `app/it_service_print.php` in `app/`.
2. Eseguire `sql/migration_v1_9_74.sql`.
3. Relazione di Servizio IT → "Andamento giornaliero — ore": i giorni feriali risultano in
   blu (ordinarie). Se compare l'avviso "Ore senza fascia oraria riconosciuta", i valori
   elencati indicano quale dato della vista v_cm_it_servizio va sistemato alla fonte.
