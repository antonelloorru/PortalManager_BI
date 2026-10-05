# Release Checklist — v1.9.94
- [x] VERSION = 1.9.94; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.94','migration_v1_9_94.sql'); RUN1/RUN2 err=0; nessun `;` nei commenti
- [x] `php -l` su app/ProRata.php, app/DirModel.php, app/dir_report_print.php, dir_report.php
- [x] ProRata: esempio 100.000 € / 28 mesi = 25.000,00 + 42.857,14 + 32.142,86; periodo parziale, ordine successivo all'inizio, ordine dopo la fine, arrotondamento
- [x] Modello: competenza per anno = somma per commessa; filtro date su perimetro, andamento, attenzione
- [x] Test HTTP con login reale: pagina senza warning, 10 badge FIDO, XLSX (fogli competenza), stampa con competenza
- [x] Verifica visiva della sezione competenza
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE, RELEASE_CHECKLIST
