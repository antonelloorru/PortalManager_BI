# Deployment — PortalManager v1.8.74

## 1. Contenuto

```
VERSION                          1.8.74
app/SyncDatasets.php             + dataset clienti (15 totali)
app/Version.php                  PM_VERSION = 1.8.74
gli altri file                   invariati da v1.8.73
sql/migration_v1_8_74.sql        import_batch_id su clients + quadro copertura
sql/upgrade_1_7_56_to_1_8_74.sql consolidato cumulativo (514 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_74.sql` (da v1.8.73) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto** (ora **quindici** dataset).

## 3. Verifica post-deploy

```sql
SELECT * FROM v_cm_copertura_sync ORDER BY stato, tabella;
```

| Stato | Tabelle attese |
|---|---|
| sincronizzata | **15** |
| anagrafica interna | 8 |
| registro tecnico | 1 |

Dopo la sincronizzazione:

```sql
SELECT COUNT(*) AS clienti,
       SUM(vat_number IS NOT NULL AND vat_number <> '') AS con_partita_iva
  FROM clients;
```

Attesi circa **331 clienti** (erano 305) e **137 con partita IVA** (erano zero).

## 4. Che cosa non viene toccato

| Tabella | Perché |
|---|---|
| `cm_tech_units`, `cm_tech_subunits` | tassonomia assegnata a mano |
| `cm_tech_profiles`, `cm_tech_history` | assegnazioni e storico del portale |
| `cm_rate_bands` e collegate | fasce definite dall'azienda |
| `employees` | contratti e retribuzioni non esistono nel gestionale |

**Le vostre assegnazioni di unità organizzativa e le fasce restano intatte.** Il
legame con il gestionale è mantenuto: `cm_tech_profiles` punta a `employees` o
`cm_professionals`, e i professionisti sono sincronizzati.

## 5. Sui sette clienti duplicati

Sette aziende sono registrate due volte nel gestionale con lo stesso nome, una
con partita IVA e una senza. Il dataset le consolida tenendo il valore più
informativo: 338 righe della sorgente diventano 331 nel portale.

Non è una perdita: sono la stessa azienda registrata due volte alla fonte.

## 6. Rollback

```sql
DROP VIEW IF EXISTS v_cm_copertura_sync;
UPDATE app_settings SET setting_value='1.8.73'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare `app/SyncDatasets.php`. La colonna `import_batch_id` su `clients`
può restare: è inerte.
