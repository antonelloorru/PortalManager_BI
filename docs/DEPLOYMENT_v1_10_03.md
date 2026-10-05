# DEPLOYMENT — v1.10.03 (cumulativo da v1.10.00)

## Prerequisiti
- v1.10.00 installata: tabelle `cm_prj*` della v1.9.99 e motore di calcolo della v1.10.00.
- PHP 8.0–8.2 con estensione zip (ZipArchive), MariaDB 10.4.

## Aggiornamento da console
1. `system_console.php` → Aggiornamento → caricare `update_v1.10.03.zip`.
2. Console SQL: eseguire **in ordine** le migration. Sono idempotenti; quelle già applicate si possono rieseguire.
   1. `sql/migration_v1_10_01.sql`
   2. `sql/migration_v1_10_02.sql`
   3. `sql/migration_v1_10_03.sql`
3. Stop + Start di Apache, poi Ctrl+F5.
4. Verifica: `php tools/verify_v1_10_03.php --db=demo_portalmanager` → «39 OK, 0 KO».

Il pacchetto include anche `sql/migration_v1_9_99.sql` e `sql/migration_v1_10_00.sql`, idempotenti, per installare il modulo su un database che non li ha ancora: in quel caso eseguirli prima delle altre tre.

## Installazione manuale
1. `Expand-Archive -Path update_v1.10.03.zip -DestinationPath P:\xampp\htdocs\demo_portalmanager -Force`
2. phpMyAdmin → database → Importa le tre migration nell'ordine sopra.
3. Stop + Start di Apache, Ctrl+F5, verifica come sopra.

## Configurazione dopo l'installazione
- **Permessi** (Gestione permessi → Gestione Commesse):

  | Permesso | Abilita |
  |---|---|
  | Progetti PRJ (elenco) | elenco, creazione, export |
  | Scheda progetto PRJ | consultazione, modifica |
  | Calcolo scenari PRJ | calcoli salvati, aggiornamento consuntivi |
  | Collegamento PRJ - commessa SP | collegare e scollegare |
  | Costi reali dipendenti (PRJ) | costi reali nel confronto |
  | Parametri dimensionamento | parametri globali |
  | Scenari & confronti progetti | calcoli salvati di tutti i progetti |

- **Alert**: per inviare gli scostamenti attivare le regole `prj_scost_fte` e `prj_scost_costo` (`cm_alert_rules.is_active = 1`) e regolarne le soglie. Il destinatario è il commerciale della commessa, che deve essere censito in `cm_alert_recipients`.

## File del pacchetto
- **Pagine**: `prj_dashboard.php`, `prj_parameters.php`, `prj_history.php`, `api_prj.php`, `manage_projects.php`, `project_dashboard.php`, `access_control.php`.
- **app/**: Version, Router, MenuManager, PermissionCatalog, CommesseSync, AlertEngine, PmCharts, PrjCalc, PrjRepo, PrjLink, PrjUi, PrjActuals, PrjExport, prj_list.
- **Altro**:
  - `assets/pm-filters.css`;
  - `tools/verify_v1_10_01.php`, `tools/verify_v1_10_02.php`, `tools/verify_v1_10_03.php`;
  - `sql/migration_v1_10_01.sql`, `sql/migration_v1_10_02.sql`, `sql/migration_v1_10_03.sql`;
  - `docs/`, `VERSION`, `update_manifest.json`.

## Rollback
- Ripristinare i file della v1.10.00 ed eliminare i file nuovi.
- Lo schema aggiunto (`cm_prj_deviation`, vista, regole disattivate, indici) non interferisce con le altre funzioni.
- In alternativa: `DROP VIEW v_cm_prj_alert_da_rilevare`, `DROP TABLE cm_prj_deviation`, `DELETE FROM cm_alert_rules WHERE code LIKE 'prj_scost_%'`.
