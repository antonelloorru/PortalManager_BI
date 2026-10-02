# Release Checklist — v1.9.91
- [x] VERSION = 1.9.91; migration aggiorna app_version / schema_version / release_label
- [x] `pm_migration_sql` registra ('1.9.91','migration_v1_9_91.sql')
- [x] Migration idempotente: RUN1/RUN2 err=0 con `sql_split_statements`, nessun `;` nei commenti
- [x] `php -l` su tutti i file modificati
- [x] Test modello: senza filtri 314 attività / 1.818,5 h; cliente, stato commessa, codice linea applicati; dettaglio = totale per motivo
- [x] Test HTTP con login reale: sezione presente (122 righe), XLSX (foglio «DGB senza modulo»), Word, stampa; nessun warning
- [x] Prima/dopo: barra automatica presente su Service Desk, Report direzionale, Attività & Rendicontazione DGB → assente; pm-ui-boost rimosso dal Service Desk
- [x] Nessun cambio a permessi, menu, Router
- [x] Docs: CHANGELOG, DEPLOYMENT, TECHNICAL_DESIGN, MANUALE, RELEASE_CHECKLIST
