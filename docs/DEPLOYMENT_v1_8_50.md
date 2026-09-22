# Deployment — PortalManager v1.8.50

**Release correttiva su dati.** La migration elimina righe duplicate e modifica
i codici rapporto. Il backup del database non è una formalità.

## 1. Contenuto

```
VERSION                          1.8.50
app/SyncDatasets.php             chiave di grana source_uid, codici senza suffisso
app/DgbModel.php                 filtri modalità e tipo report anche sull'elenco
app/MenuManager.php              menu riordinato per flusso analitico
app/SourceDb.php, DatasetSync.php, Router.php, Version.php   da v1.8.49
sync_commesse.php, tech_registry.php, tech_units.php,
project_dashboard.php, dgb_activities.php                    da v1.8.49
sql/migration_v1_8_50.sql        grana canonica, deduplica, viste analitiche
sql/upgrade_1_7_56_to_1_8_50.sql consolidato cumulativo (364 statement)
docs/                            questa documentazione
```

## 2. Prima di aggiornare

**Esportare il database.** La migration:

- rimuove righe duplicate da `cm_intervention_reports`
- modifica `report_code`, togliendo il suffisso `/<id operatore>`
- rimuove i vincoli `uq_report_code` e `uq_cir_dgb_source`

Nessuna di queste operazioni è reversibile senza il backup.

**Annotare i totali attuali**, per confrontarli dopo:

```sql
SELECT COUNT(*) AS righe, ROUND(SUM(quantity_hours),2) AS ore
  FROM cm_intervention_reports;
```

## 3. Aggiornamento

1. Backup del database.
2. `system_console.php` → tab **Aggiornamento**.
3. Copiare i file: cinque in ROOT, sette in `app\`.
4. SQL Runner: `sql/migration_v1_8_50.sql` (da v1.8.49) oppure
   `sql/upgrade_1_7_56_to_1_8_50.sql`.
5. **Stop + Start Apache**.
6. **Ctrl+F5**.

La migration esegue diverse UPDATE su tutta la tabella dei rapporti. Su decine di
migliaia di righe richiede alcuni minuti: se il SQL Runner va in timeout,
aumentare `max_execution_time` o eseguire da phpMyAdmin → Importa.

## 4. Verifica post-deploy

Il controllo principale è il confronto dei totali:

```sql
SELECT * FROM v_cm_grana_check;
```

| Campo | Valore atteso |
|---|---|
| `righe_totali` | uguale o **minore** di prima |
| `grane_distinte` | uguale a `righe_totali` |
| `senza_grana` | **0** |
| `duplicati` | **0** |
| `codici_con_suffisso` | **0** |
| `ore_consuntivate` | uguale o **minore** di prima |

Se righe e ore diminuiscono, sono stati rimossi duplicati preesistenti: è
l'effetto voluto. Se restano invariate, non ce n'erano.

Un valore diverso da zero in `duplicati` **dopo** la migration indica che un
canale sta aggirando il vincolo: segnalarlo prima di fidarsi dei totali.

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.50` |
| Menu Gestione Commesse | ordine: anagrafiche, acquisizione, analisi |
| Scheda commessa → Consuntivo | codici senza suffisso `/` |
| Attività DGB → filtro "Da remoto" | **anche l'elenco si restringe**, non solo i totali |
| Sincronizzare due volte di seguito | la seconda non aggiunge righe |

L'ultima è la verifica che il problema segnalato è risolto.

## 5. Se i totali non tornano

Le ore possono legittimamente diminuire, mai aumentare. Se aumentano, fermarsi e
ripristinare il backup.

Per vedere che cosa è stato rimosso, prima di aggiornare:

```sql
SELECT SUBSTRING_INDEX(report_code,'/',1) AS codice, COUNT(*) AS righe
  FROM cm_intervention_reports
 GROUP BY codice HAVING COUNT(*) > 1
 ORDER BY righe DESC LIMIT 50;
```

## 6. Rollback

Ripristinare il database dal backup e i file dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.49'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Il ripristino del database è necessario: i duplicati eliminati e i codici
ripuliti non si ricostruiscono dai file.
