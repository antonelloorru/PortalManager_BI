# Deployment — PortalManager v1.8.77

**Release correttiva sui dati**: aggiorna 69.074 righe. Fare un backup.

## 1. Contenuto

```
VERSION                          1.8.77
app/DatasetSync.php              risoluzione del tecnico
app/SyncDatasets.php             link_technician sul dataset rapporti
app/Version.php                  PM_VERSION = 1.8.77
gli altri file                   invariati da v1.8.76
sql/migration_v1_8_77.sql        riparazione + controllo
sql/upgrade_1_7_56_to_1_8_77.sql consolidato cumulativo (530 statement)
docs/                            questa documentazione
```

## 2. Prima di aggiornare

**Backup del database.** Poi annotare lo stato:

```sql
SELECT COUNT(*) AS rapporti,
       SUM(technician_id IS NOT NULL) AS con_dipendente,
       SUM(technician_professional_id IS NOT NULL) AS con_professionista,
       SUM(technician_id IS NULL AND technician_professional_id IS NULL) AS scollegati
  FROM cm_intervention_reports;
```

Attesi: 69.074 rapporti, **69.074 scollegati**.

## 3. Aggiornamento

1. Backup.
2. `system_console.php` → tab **Aggiornamento**.
3. Copiare i tre file in `app\`.
4. SQL Runner: `sql/migration_v1_8_77.sql` (da v1.8.76) oppure il consolidato.
5. **Stop + Start Apache**, **Ctrl+F5**.

Gli `UPDATE` toccano 69.074 righe: richiedono qualche minuto. Se il SQL Runner va
in timeout, aumentare `max_execution_time` o eseguire da phpMyAdmin.

## 4. Verifica

```sql
SELECT COUNT(*) AS rapporti,
       SUM(technician_id IS NOT NULL) AS con_dipendente,
       SUM(technician_professional_id IS NOT NULL) AS con_professionista,
       SUM(technician_id IS NULL AND technician_professional_id IS NULL) AS scollegati
  FROM cm_intervention_reports;
```

Attesi: **69.074 con professionista**, ~66.939 con dipendente, **0 scollegati**.

Il caso segnalato:

```sql
SELECT COUNT(*), ROUND(SUM(quantity_hours),2), technician_id, technician_professional_id
  FROM cm_intervention_reports
 WHERE project_code = 'WTS_3670' AND technician_raw = 'Nushi Irni'
 GROUP BY technician_id, technician_professional_id;
```

Atteso: 34 moduli, 66 ore, `technician_id = 86`, `technician_professional_id = 179`.

Poi aprire **Attività & Rendicontazione DGB**, **Carico & Sovrapposizioni** e la
scheda della commessa WTS_3670: Nushi Irni deve comparire ovunque.

## 5. Il controllo da tenere

```sql
SELECT * FROM v_cm_tecnici_scollegati;
```

**Zero righe.** Elenca i tecnici che non trovano corrispondenza in anagrafica,
con moduli, ore e commesse.

Se dopo una sincronizzazione futura comparissero righe, sono persone nuove non
ancora presenti in `cm_professionals` o `employees`: vanno anagrafate, e alla
sincronizzazione successiva si collegano da sole.

## 6. I rapporti con professionista ma senza dipendente

Circa 2.135 rapporti hanno il professionista ma non il dipendente. Sono persone
non ancora riconciliate fra le due anagrafiche — quasi tutti di tipo `INTERNAL`.

**Restano visibili** in tutti i prospetti, perché il collegamento al
professionista basta. Riconciliarli in *Anagrafica Tecnica* completerà il quadro.

## 7. Rollback

Ripristinare il database dal backup e i tre file. La migration modifica dati:
senza backup i collegamenti non si annullano selettivamente.

```sql
UPDATE app_settings SET setting_value='1.8.76'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
