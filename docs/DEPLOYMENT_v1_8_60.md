# Deployment — PortalManager v1.8.60

Release **additiva**: due colonne su `cm_contract_models`, una tabella nuova, due
viste. Nessun dato modificato.

## 1. Contenuto

```
VERSION                          1.8.60
app/SyncDatasets.php             + dataset "Full cost per operatore"
app/Version.php                  PM_VERSION = 1.8.60
gli altri file                   invariati da v1.8.59
sql/migration_v1_8_60.sql        cost_basis + cm_operator_costs + 2 viste
sql/upgrade_1_7_56_to_1_8_60.sql consolidato cumulativo (433 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_60.sql` (da v1.8.59) oppure
   `sql/upgrade_1_7_56_to_1_8_60.sql`.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto**, per popolare il nuovo
   dataset dei full cost.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.60` |
| Sincronizzazione completa | sette dataset, incluso **Full cost per operatore** |

```sql
SELECT COUNT(*) AS operatori,
       SUM(full_cost_hour > 0) AS con_costo_orario,
       ROUND(AVG(full_cost_hour), 2) AS medio_ora
  FROM cm_operator_costs;
```

Attesi ~256 operatori, ~148 con costo orario, media intorno a **18,03 €/ora**.

```sql
SELECT costo_origine, SUM(prestazioni), ROUND(SUM(ore)), ROUND(SUM(costo))
  FROM v_cm_copertura_costi GROUP BY costo_origine ORDER BY 3 DESC;
```

Attesi: **rilevato** ~317.960 ore, e circa **20.913 ore SCOPERTE**.

## 4. Le ore scoperte

| Motivo | Ore |
|---|---|
| né full cost né fascia | 16.959 |
| nessuna base di costo | 3.010 |
| né costo direzionale né fascia | 944 |

Sono il **6,2%** del consuntivo. Per il restante 94% il gestionale fornisce già
il costo rilevato, che non viene mai sostituito da una stima.

Le 944 ore su base direzionale resteranno scoperte finché il costo direzionale
non sarà disponibile.

## 5. Il costo direzionale non è nei dati

Non esiste nel dump del gestionale: nessuna colonna lo contiene, né per operatore
né per commessa. La struttura è predisposta e le linee sono classificate, ma il
valore va fornito.

Quando sarà disponibile:

```sql
UPDATE cm_operator_costs
   SET directional_cost_hour = <valore>
 WHERE source_id = <id operatore>;
```

Le viste lo useranno automaticamente per WTS-GES e WTS-SOC.

Non è stato stimato di proposito: quelle due linee valgono insieme circa due
milioni di euro, e un margine costruito su un costo inventato verrebbe usato per
decidere.

## 6. Ore lavorabili annue

Il full cost è annuo e viene diviso per `cost_workable_hours_year` (1.760 =
8 h × 220 giorni), coerente con l'orario ordinario della v1.8.53.

Il valore è in `app_settings`, ma è usato anche nella query del dataset: per
cambiarlo occorre aggiornare entrambi. Segnalatelo se la convenzione aziendale è
diversa.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_copertura_costi;
DROP VIEW IF EXISTS v_cm_costo_prestazione;
DROP TABLE IF EXISTS cm_operator_costs;
ALTER TABLE cm_contract_models
  DROP COLUMN IF EXISTS cost_basis, DROP COLUMN IF EXISTS cost_note;
UPDATE app_settings SET setting_value='1.8.59'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
