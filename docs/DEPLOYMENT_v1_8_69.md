# Deployment — PortalManager v1.8.69

## 1. Contenuto

```
VERSION                          1.8.69
dgb_activities.php               (ROOT)  matrice a 4 nature, assenze, export XLSX
app/DgbModel.php                 hourlyHeatmap esteso
app/SyncDatasets.php             12° dataset: assenze
app/Version.php                  PM_VERSION = 1.8.69
gli altri file                   invariati da v1.8.68
sql/migration_v1_8_69.sql        2 tabelle + 2 viste
sql/upgrade_1_7_56_to_1_8_69.sql consolidato cumulativo (489 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i quattro file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_69.sql` (da v1.8.68) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto** (ora dodici dataset).

## 3. Verifica post-deploy

```sql
SELECT commitment_type, COUNT(*), ROUND(SUM(hours),1)
  FROM cm_operator_commitments GROUP BY commitment_type ORDER BY 2 DESC;
```

| Tipo | Righe attese | Ore |
|---|---|---|
| HOLIDAY | 3.298 | 26.186,0 |
| REMINDER | 1.366 | 7.881,0 |
| LEAVE | 833 | 3.442,5 |
| RECOVERY | 658 | 2.884,0 |
| SICK_LEAVE | 361 | 2.851,5 |

**Attività & Rendicontazione DGB** → **Giorni (mese)**:

| Controllo | Atteso |
|---|---|
| Legenda sopra la matrice | quattro nature con ore e percentuale |
| Celle | blu, arancione, **verde**, **rosso** secondo la natura prevalente |
| Banda sotto la griglia | una riga per tipo di assenza |
| Legenda assenze | ferie, permessi, recuperi, malattia con i totali |
| Pulsante **XLSX** | file a tre fogli |

Se le nature verde e rossa risultano **a zero**, il join alla commessa non
aggancia: verificare che `cm_projects.dgb_contract_id` sia popolato
(atteso ~1.050 su 1.062).

## 4. I numeri attesi su un mese di riferimento

Marzo 2026, a titolo di confronto:

| Natura | Ore | Quota |
|---|---|---|
| cliente ordinario | 7.058,0 | 62,6% |
| cliente reperibilità | 1.260,7 | 11,2% |
| interno ordinario | 2.510,3 | 22,3% |
| interno reperibilità | 448,4 | 4,0% |
| **assenze** (banda separata) | **835,5** | — |

Le assenze **non si sommano** al lavorato: sono ore non lavorate e vivono su un
piano diverso.

## 5. Promemoria e riunioni non sono assenze

`cm_commitment_types.is_absence` distingue le assenze vere dagli impegni di
agenda. Promemoria e riunioni — 7.988 ore — sono tempo lavorato segnato in
calendario, e non compaiono nella banda delle assenze.

Per riclassificare un tipo:

```sql
UPDATE cm_commitment_types SET is_absence = 0 WHERE code = 'SAINT_PATRON';
```

Nessuna release necessaria.

## 6. Rollback

```sql
DROP VIEW IF EXISTS v_cm_assenze_riepilogo;
DROP VIEW IF EXISTS v_cm_assenze_giorno;
DROP TABLE IF EXISTS cm_operator_commitments;
DROP TABLE IF EXISTS cm_commitment_types;
UPDATE app_settings SET setting_value='1.8.68'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare anche i quattro file dalla copia precedente.
