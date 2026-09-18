# Deployment — PortalManager v1.8.70

**Release correttiva su dati: ELIMINA RIGHE.** Fare un backup prima di eseguirla.

## 1. Contenuto

```
VERSION                          1.8.70
app/Version.php                  PM_VERSION = 1.8.70
gli altri file                   invariati da v1.8.69
sql/migration_v1_8_70.sql        pulizia dei residui + log + controllo
sql/upgrade_1_7_56_to_1_8_70.sql consolidato cumulativo (496 statement)
docs/                            questa documentazione
```

## 2. Prima di aggiornare

**Esportare il database.** Poi annotare i totali:

```sql
SELECT COUNT(*) AS rapporti, ROUND(SUM(quantity_hours),2) AS ore
  FROM cm_intervention_reports;
SELECT COUNT(*) AS commesse FROM cm_projects;
```

Attesi, sul backup del 18/08: **136.828 rapporti**, **673.024,50 ore**, 1.518
commesse.

E per vedere in anticipo che cosa verrà rimosso:

```sql
SELECT COUNT(*) AS righe, ROUND(SUM(quantity_hours),2) AS ore
  FROM cm_intervention_reports
 WHERE report_code LIKE 'DGB-%' AND dgb_activity_id IS NULL;
```

Attesi: **67.786 righe**, **328.629,00 ore**.

## 3. Aggiornamento

1. **Backup del database.**
2. `system_console.php` → tab **Aggiornamento**.
3. Copiare `app/Version.php`.
4. SQL Runner: `sql/migration_v1_8_70.sql` (da v1.8.69) oppure il consolidato.
5. **Stop + Start Apache**, **Ctrl+F5**.

La `DELETE` su 67.786 righe richiede qualche minuto. Se il SQL Runner va in
timeout, aumentare `max_execution_time` o eseguire da phpMyAdmin.

## 4. Verifica post-deploy

```sql
SELECT COUNT(*) AS rapporti, ROUND(SUM(quantity_hours),2) AS ore
  FROM cm_intervention_reports;
```

Attesi: **69.042 rapporti**, **344.395,50 ore**.

```sql
SELECT * FROM cm_cleanup_log;
```

Deve riportare `rows_removed = 67786` e `hours_removed = 328629.00`.

```sql
SELECT * FROM v_cm_residui_import;
```

**Zero righe.**

## 5. Le ore caleranno del 49%

**È l'effetto voluto.** Erano ore contate due volte: lo stesso intervento
importato una volta con il codice inventato `DGB-<id>` (prima della v1.8.51) e
una volta con il codice reale.

Le ore possono solo **diminuire**. Se dovessero aumentare, ripristinare il
backup.

Tutti i prospetti che usano il consuntivo — marginalità, saldo commessa,
distribuzione oraria, anomalie — mostreranno valori dimezzati. Sono quelli
corretti: i precedenti erano gonfiati.

## 6. Perché il vincolo di unicità non l'aveva impedito

Il vincolo `uq_ir_source_uid` funziona e impedisce di importare due volte lo
stesso codice rapporto. Ma la grana è `<codice rapporto>#<tecnico>`, e i due
gruppi hanno **codici diversi** per lo stesso intervento: `DGB-14470` e il codice
reale del gestionale.

Nessun vincolo di database può accorgersi che due codici diversi denotano lo
stesso fatto. Serviva una pulizia mirata, che è questa.

## 7. Rollback

Ripristinare il database dal backup: le righe eliminate non si ricostruiscono.

```sql
UPDATE app_settings SET setting_value='1.8.69'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
