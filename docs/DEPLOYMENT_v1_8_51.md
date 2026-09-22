# Deployment — PortalManager v1.8.51

**Release correttiva urgente.** Se la v1.8.50 è già installata, i canali di
import stanno duplicando le ore a ogni esecuzione: applicare questa release prima
del prossimo import o sincronizzazione.

## 1. Contenuto

```
VERSION                              1.8.51
import_intervention_reports.php      (ROOT)  grana esplicita, canale dichiarato
app/DgbSync.php                      grana esplicita, codice di riferimento
app/SyncDatasets.php, DgbModel.php, MenuManager.php, SourceDb.php,
DatasetSync.php, Router.php, Version.php                     da v1.8.50
sync_commesse.php, tech_registry.php, tech_units.php,
project_dashboard.php, dgb_activities.php                    da v1.8.50
sql/migration_v1_8_51.sql            trigger, colonna NOT NULL, viste di controllo
sql/upgrade_1_7_56_to_1_8_51.sql     consolidato cumulativo (379 statement)
docs/                                questa documentazione
```

## 2. Prima di aggiornare

**Esportare il database.** La migration elimina righe duplicate.

**Annotare i totali**, per il confronto successivo:

```sql
SELECT COUNT(*) AS righe, ROUND(SUM(quantity_hours),2) AS ore
  FROM cm_intervention_reports;
```

**Non eseguire import o sincronizzazioni** fra il backup e l'aggiornamento: con
la v1.8.50 installata ogni esecuzione aggiunge righe.

## 3. Aggiornamento

1. Backup del database.
2. `system_console.php` → tab **Aggiornamento**.
3. Copiare i file: sei in ROOT, otto in `app\`.
4. SQL Runner: `sql/migration_v1_8_51.sql` (da v1.8.50) oppure
   `sql/upgrade_1_7_56_to_1_8_51.sql`.
5. **Stop + Start Apache**.
6. **Ctrl+F5**.

### Nota sui trigger

La migration crea due trigger su `cm_intervention_reports`. Richiede il privilegio
`TRIGGER` sull'utenza del portale: su XAMPP l'utenza è normalmente `root` e lo ha.
Se il SQL Runner segnala *access denied*, eseguire la migration da phpMyAdmin con
un'utenza amministrativa.

I trigger sono visibili con:

```sql
SHOW TRIGGERS LIKE 'cm_intervention_reports';
```

Devono comparire `trg_ir_grana_ins` e `trg_ir_grana_upd`.

## 4. Verifica post-deploy

```sql
SELECT * FROM v_cm_grana_check;
```

| Campo | Atteso |
|---|---|
| `grane_distinte` | uguale a `righe_totali` |
| `senza_grana` | **0** |
| `duplicati` | **0** |
| `codici_con_suffisso` | **0** |
| `tecnico_non_identificato` | il più basso possibile |
| `ore_consuntivate` | uguale o **minore** di prima |

E la ripartizione per provenienza:

```sql
SELECT * FROM v_cm_grana_per_canale;
```

La colonna `eccedenza` deve essere **0 su ogni riga**. Un valore diverso indica
che quel canale sta scrivendo più righe che prestazioni distinte.

### La prova che il difetto è chiuso

Eseguire **due volte di seguito** lo stesso import da file, oppure due
sincronizzazioni consecutive senza modifiche sulla sorgente. Il numero di righe e
il totale ore devono restare invariati fra la prima e la seconda.

È l'unica verifica che conta: prima di questa release il totale cresceva a ogni
ripetizione.

## 5. Un effetto visibile: i codici dei rapporti DGB cambiano

I rapporti importati dalla sincronizzazione avevano codice `DGB-<numero>`. Dopo
questa release la sincronizzazione scrive il codice reale del gestionale.

I rapporti **già presenti** conservano il vecchio codice finché non vengono
risincronizzati. Per allinearli tutti, eseguire una sincronizzazione completa dopo
l'aggiornamento: le righe esistenti vengono riconosciute per grana e il codice
aggiornato, senza creare duplicati.

## 6. Se i totali non tornano

Le ore possono diminuire, mai aumentare. Se aumentano, fermarsi e ripristinare il
backup.

Per vedere in anticipo che cosa verrà consolidato:

```sql
SELECT SUBSTRING_INDEX(report_code,'/',1) AS codice,
       COALESCE(technician_raw,'')        AS tecnico,
       COUNT(*)                           AS righe
  FROM cm_intervention_reports
 GROUP BY codice, tecnico
HAVING COUNT(*) > 1
 ORDER BY righe DESC
 LIMIT 50;
```

## 7. Rollback

Ripristinare il database dal backup e i file dalla copia precedente, poi:

```sql
DROP TRIGGER IF EXISTS trg_ir_grana_ins;
DROP TRIGGER IF EXISTS trg_ir_grana_upd;
UPDATE app_settings SET setting_value='1.8.50'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Attenzione: tornare alla v1.8.50 significa tornare alla situazione in cui i canali
duplicano a ogni esecuzione. Il rollback ha senso solo verso un backup precedente
alla v1.8.50.
