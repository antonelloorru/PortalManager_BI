# Deployment — v1.9.80 (richiede v1.9.79)
1. Copiare in root `report_certificazioni.php`; in `app/` `PmFilter.php` (invariato dalla v1.9.71, incluso per completezza).
2. Eseguire `sql/migration_v1_9_80.sql` (solo indici e versione).
3. Verifica — Competenze & Formazione → Report certificazioni: pannello Filtri con 4 gruppi, ricerca nelle select,
   badge filtri attivi, riepilogo cliccabile; export con i dati filtrati.
