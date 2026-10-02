# DEPLOYMENT — v1.9.90

Prerequisito: v1.9.89 installata.

1. Backup file + DB.
2. `Expand-Archive -Path PortalManager_v1_9_90.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/ItServiceModel.php`, `it_service.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_90.sql` (SQL Runner). Idempotente (solo indici e versione).
4. Ctrl+F5 sulla pagina.
5. Verifica: Relazione di Servizio IT → nessuna barra «Cerca in tutta la tabella / Filtri / Viste / Esporta» sopra le tabelle;
   impostare un filtro (es. Cliente) → Riepilogo per Codice Contratto e Dettaglio per commessa mostrano la riga «filtri del pannello in alto»
   e ore totali uguali ai KPI.

Note: `assets/js/pm-ui-boost.js` resta nel pacchetto per le altre pagine che lo includono; non è più caricato dalla Relazione IT.
Rollback: ripristinare i 2 file PHP della v1.9.89.
