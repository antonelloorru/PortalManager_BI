# Release Checklist — v1.9.90
- [x] VERSION = 1.9.90; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.90','migration_v1_9_90.sql')
- [x] Migration idempotente: RUN1/RUN2 err=0 con `sql_split_statements`, nessun `;` nei commenti
- [x] `php -l` su app/ItServiceModel.php, it_service.php
- [x] Test modello: 15 filtri, riepilogo per contratto e dettaglio allineati ai KPI (ore e contratti)
- [x] Test HTTP con login reale: nessuna barra ListFilter, nessun pm-ui-boost, 13 select con un solo widget, riepilogo e dettaglio filtrati (Cliente), caricamento righe on-demand, nessun warning
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE, RELEASE_CHECKLIST
