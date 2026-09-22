# Deployment — PortalManager v1.8.83

Release **solo applicativa**: viste ricreate e una tabella nuova, vuota.

## 1. Contenuto

```
VERSION                          1.8.83
app/Version.php                  PM_VERSION = 1.8.83
gli altri file                   invariati da v1.8.82
sql/migration_v1_8_83.sql        3 viste + cm_sd_sla
sql/upgrade_1_7_56_to_1_8_83.sql consolidato cumulativo (573 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_8_83.sql` (da v1.8.82) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Nessuna risincronizzazione necessaria: la classificazione è ricalcolata dalle
viste sui dati già presenti.

## 3. Verifica

```sql
SELECT gestione, COUNT(*) AS ticket, SUM(stato='CLOSED') AS chiusi
  FROM v_cm_sd_ticket GROUP BY gestione ORDER BY ticket DESC;
```

| Gestione | Attesi |
|---|---|
| Presa in carico diretta da specialisti | 1.471 |
| Risolto dal Service Desk | 1.365 |
| Lavorato senza risposta scritta | 430 |
| Cliente senza risposta scritta | 129 |
| Escalation di 2° livello | 105 |
| **Mai preso in carico** | **12** |

## 4. La vista che richiede azione

```sql
SELECT * FROM v_cm_sd_scoperti ORDER BY giorni_aperto DESC;
```

Attesi **14 ticket**: i 12 mai presi in carico più 2 con cliente senza risposta e
ancora aperti.

Il più vecchio è aperto da **210 giorni** — WTS_000001033, coda Voip.

Le altre classi non compaiono qui di proposito: 559 ticket senza risposta scritta
sono lavoro svolto e chiuso, e includerli renderebbe la lista inutilizzabile.

## 5. Come leggere le tre classi «senza risposta»

| Classe | Significato | Azione |
|---|---|---|
| **lavorato senza risposta scritta** | note interne, nessun messaggio al cliente. Risoluzione telefonica o attività interna | nessuna |
| **cliente senza risposta scritta** | il cliente ha scritto, il supporto ha annotato ma non ha risposto | verificare quelli aperti |
| **mai preso in carico** | nessuno ha toccato il ticket | **intervenire** |

Il nuovo campo `presidio` dice chi ha toccato il ticket anche solo con note:
`solo L1`, `solo L2`, `L1 e L2`, `nessuno`.

## 6. Gli SLA

`cm_sd_sla` è predisposta e **vuota**. Accoglie soglie di presa in carico e di
risoluzione, per commessa (`project_code`) o per coda (`queue_name`); una riga con
`project_code` NULL vale come default.

Per definirne uno:

```sql
INSERT INTO cm_sd_sla (project_code, label, take_charge_min, resolution_min)
VALUES ('WTS_3670', 'SLA standard', 240, 2880);
```

Finché la tabella è vuota, le viste espongono i tempi **osservati** senza
confrontarli con soglie. La `durata_ore` fra apertura e ultimo movimento
**comprende le attese del cliente**: non è un tempo di risoluzione.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_scoperti;
DROP TABLE IF EXISTS cm_sd_sla;
UPDATE app_settings SET setting_value='1.8.82'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Le viste `v_cm_sd_ticket` e `v_cm_sd_riepilogo` tornano alla forma precedente
rieseguendo la migration v1.8.82.
