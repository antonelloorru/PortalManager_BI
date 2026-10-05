# DEPLOYMENT — v1.9.86

1. Backup file + DB.
2. `Expand-Archive -Path PortalManager_v1_9_86.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
   File: `VERSION`, `app/PositionFilter.php` (nuovo), `recruiting_posizioni.php`, `export_positions_xlsx.php`, `export_positions_pdf.php`, `sql/`, `docs/`.
3. Eseguire `sql/migration_v1_9_86.sql` (SQL Runner). Idempotente: solo indici e versione.
4. Verifica: Recruiting → Posizioni aperte → «Filtri»: selezione multipla con ricerca; «Esporta XLSX» con filtri
   attivi esporta lo stesso elenco mostrato.
5. Dipendenze già presenti dalla v1.9.71: `app/PmFilter.php`, `assets/js/pm-multiselect.js`, `assets/css/pm-multiselect.css`, `assets/pm-filters.css`.

Rollback: ripristinare i 3 file PHP precedenti e rimuovere `app/PositionFilter.php`; gli indici possono restare.
