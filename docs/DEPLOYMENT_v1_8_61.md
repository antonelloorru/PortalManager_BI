# Deployment — PortalManager v1.8.61

Release **additiva**: due tabelle e due viste. Nessun dato modificato.

## 1. Contenuto

```
VERSION                          1.8.61
app/SyncDatasets.php             + dataset "Operazioni economiche di commessa"
app/Version.php                  PM_VERSION = 1.8.61
gli altri file                   invariati da v1.8.60
sql/migration_v1_8_61.sql        cm_operation_types + cm_project_operations + 2 viste
sql/upgrade_1_7_56_to_1_8_61.sql consolidato cumulativo (440 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_61.sql` (da v1.8.60) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto** (ora otto dataset).

## 3. Verifica post-deploy

```sql
SELECT * FROM v_cm_operazioni_quadro;
```

| Codice | Operazioni attese | Importo con segno |
|---|---|---|
| COV | 85 | +688.036 |
| COR | 841 | +26.892.498 |
| REP | 4 | +57.376 |
| FVCCD | 145 | **−1.232.152** |
| FVCBGS | 872 | −2.355.600 |
| INVMEMO | 681 | 0 |

Il **costo direzionale** è FVCCD: è il dato che la v1.8.60 dichiarava assente.

```sql
SELECT commessa, linea_servizio, ROUND(ordini_cliente), ROUND(costo_direzionale),
       ROUND(acquisto_beni_servizi), ROUND(costo_ore), ROUND(saldo_finale)
  FROM v_cm_saldo_commessa
 WHERE n_operazioni > 0 ORDER BY saldo_finale ASC LIMIT 10;
```

Attese in testa WTS_3018 (−422.867) e WTS_3118 (−181.003).

## 4. Come leggere il saldo

Il saldo finale è: **alimentazioni − addebiti − costo delle ore**.

Le tre voci restano separate di proposito. Una commessa in perdita per acquisti
di beni ha un problema di preventivazione; una in perdita per ore eccedenti ha un
problema di esecuzione. Sono situazioni diverse e richiedono rimedi diversi.

Un saldo negativo su una linea **interna** (NV_*) è atteso: è costo di struttura,
non una perdita. Su una linea a ricavo va invece guardato.

## 5. Nota sulla v1.8.60

La colonna `cm_operator_costs.directional_cost_hour`, prevista dalla v1.8.60 per
un costo direzionale orario per operatore, **è superata**: il costo direzionale è
un importo di commessa, non un costo orario di persona.

La colonna resta nello schema ma inutilizzata. Non è stata rimossa per non
introdurre una modifica distruttiva su una struttura appena creata; può essere
eliminata in una release successiva.

## 6. Copertura

Il dataset importa 2.628 operazioni su 3.400 non cancellate. Le 772 escluse
riguardano contratti privi di codice, non agganciabili a una commessa.

Delle importate, 2.597 su 2.628 trovano la commessa nel portale.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_operazioni_quadro;
DROP VIEW IF EXISTS v_cm_saldo_commessa;
DROP TABLE IF EXISTS cm_project_operations;
DROP TABLE IF EXISTS cm_operation_types;
UPDATE app_settings SET setting_value='1.8.60'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
