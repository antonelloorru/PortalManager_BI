# Release Checklist — v1.9.87
- [x] VERSION = 1.9.87; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.87','migration_v1_9_87.sql')
- [x] Migration idempotente: RUN1/RUN2 err=0 con `sql_split_statements` (SqlConsole), nessun `;` nei commenti
- [x] `php -l` su app/ItServiceModel.php, it_service.php
- [x] Test modello su giugno 2026: 10 combinazioni (stati singoli e multipli, smart working, reperibilità + chiusa, ricerca, valore non valido scartato), partizione Aperta+Chiusa+Sospesa = totale, verifica SQL indipendente dei costi
- [x] Test HTTP con login reale: pagina 200 senza warning PHP, ajax dettaglio commessa, XLSX e Word generati, stampa con «Stato commessa» in intestazione
- [x] Verifica visiva del filtro
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE (utente + amministratore), RELEASE_CHECKLIST
