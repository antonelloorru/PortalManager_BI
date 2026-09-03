# Deployment — PortalManager v1.8.59

Release **additiva**: due colonne su una tabella esistente e tre viste. Nessun
dato modificato.

## 1. Contenuto

```
VERSION                          1.8.59
dgb_activities.php               (ROOT)  pannello imputazioni errate
app/Version.php                  PM_VERSION = 1.8.59
gli altri file                   invariati da v1.8.58
sql/migration_v1_8_59.sql        allows_reports + 3 viste
sql/upgrade_1_7_56_to_1_8_59.sql consolidato cumulativo (422 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_59.sql` (da v1.8.58) oppure
   `sql/upgrade_1_7_56_to_1_8_59.sql`.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.59` |
| Attività & Rendicontazione DGB → Anomalie orarie | in testa, **Interventi imputati al contratto sbagliato** |
| Il pannello | 2 segnalazioni, entrambe su WTS_3092 |

```sql
SELECT * FROM v_cm_anomalia_imputazione_riepilogo;
```

Attesi: linea `WTS-REP`, 2 segnalazioni, 1 commessa, 1 tecnico, 2 ore.

## 4. Le due correzioni da fare sul gestionale

| Rapporto | Data | Da | A (suggerita) |
|---|---|---|---|
| SODA_23_005716 | 02/07/2023 | WTS_3092 | WTS_3053 |
| SODA_23_009943 | 26/09/2023 | WTS_3092 | WTS_3053 |

Entrambe hanno **3 commesse candidate** dello stesso cliente: il suggerimento è
indicativo, non obbligato. La scelta va fatta da chi conosce l'intervento.

Le correzioni si eseguono sul gestionale; alla successiva sincronizzazione la
segnalazione sparisce da sola.

## 5. Effetto sulle statistiche

Le commesse che non ammettono interventi escono dalle medie di effort:

```sql
SELECT 'tutte'     AS v, COUNT(*), ROUND(AVG(ore_consuntivate),1)
  FROM v_cm_redditivita_commessa  WHERE modello='canone'
UNION ALL
SELECT 'operative',      COUNT(*), ROUND(AVG(ore_consuntivate),1)
  FROM v_cm_redditivita_operativa WHERE modello='canone';
```

Atteso: 180 commesse con media 35,7 ore contro 126 con media **50,9**. Usare la
prima per dimensionare un team porterebbe a una sottostima del 43%.

Il **valore** dei canoni resta nei totali di ricavo: è incassato davvero.

## 6. Se un altro canone segue la stessa regola

```sql
UPDATE cm_contract_models
   SET allows_reports = 0, operative_lines = 'WTS-CC,WTS-CSS'
 WHERE service_line = '<linea>';
```

Nessuna release necessaria: viste e pannello leggono l'attributo.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_redditivita_operativa;
DROP VIEW IF EXISTS v_cm_anomalia_imputazione_riepilogo;
DROP VIEW IF EXISTS v_cm_anomalia_imputazione;
ALTER TABLE cm_contract_models
  DROP COLUMN IF EXISTS allows_reports,
  DROP COLUMN IF EXISTS operative_lines;
UPDATE app_settings SET setting_value='1.8.58'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
