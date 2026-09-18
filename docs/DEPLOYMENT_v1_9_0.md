# Deployment — PortalManager v1.9.0

## 1. Contenuto

```
VERSION                          1.9.0
app/Version.php                  PM_VERSION = 1.9.0
gli altri file                   invariati da v1.8.99
sql/migration_v1_9_0.sql         5 colonne + 20 aggiornamenti + 7 righe + 2 viste
sql/upgrade_1_7_56_to_1_9_0.sql  consolidato cumulativo
docs/                            questa documentazione
```

**Prerequisito**: v1.8.99 applicata (`cm_calc_reference` esistente).

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_0.sql` (da v1.8.99) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica

```sql
SELECT margin_type, formula, tm_cost_type, cost_basis, righe, esito
  FROM v_cm_calc_mappa;
```

Tutte le righe devono riportare **`coerente`**. Attese sei combinazioni:

| `margin_type` | Formula | Base | Righe |
|---|---|---|---|
| D | C | full_cost | 10 |
| V | B | fascia | 6 |
| D | C | fascia | 1 (NV_SC) |
| D | C | costi_a_zero | 1 (WTS-SOC) |
| F | A | costi_a_zero | 1 (WTS-GES) |
| T | D | fascia | 1 (WTS-CSS) |

```sql
SELECT regola_origine, COUNT(*) FROM v_cm_calc_regola GROUP BY regola_origine;
```

Le commesse su `predefinita` sono quelle la cui linea non ha regola attiva —
incluse le linee `NIS-*` in dismissione.

## 4. Cosa è cambiato

**I sei «Dinamico» hanno ora il codice vero** (`NV_SC`, `NV_DT`, `NV_EVENTI`,
`NV_FI`, `NV_GC`, `NV_GS`) e risolvono per corrispondenza diretta.

`is_dynamic` è tornato a 0 per tutti e sei: il ripiego sul gruppo resta come rete
per eventuali linee `NV_*` future.

## 5. La mappa del gestionale

Il gestionale codifica le stesse regole del vostro documento:

```sql
SELECT code_doc, descrizione, margin_type, formula, tm_cost_type, cost_basis
  FROM cm_calc_reference WHERE is_active = 1 ORDER BY sort_order;
```

Le colonne `margin_type` e `tm_cost_type` sono i codici originali; `formula` e
`cost_basis` la loro interpretazione. Tenerle entrambe permette di verificare la
mappa in qualunque momento.

## 6. I tipi in dismissione

Sette righe hanno `is_legacy = 1` e `is_active = 0`: sono i `NIS-*` che il
gestionale contrassegna «ELIMINARE E CONVERTIRE».

Non partecipano alla risoluzione: una commessa su quelle linee ricade sulla
predefinita e viene dichiarata. Se una di esse dovesse tornare in uso:

```sql
UPDATE cm_calc_reference SET is_active = 1, is_legacy = 0
 WHERE code_doc = 'NIS-PROJ-SYS';
```

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_calc_mappa;
DELETE FROM cm_calc_reference WHERE is_legacy = 1;
ALTER TABLE cm_calc_reference
  DROP COLUMN IF EXISTS margin_type, DROP COLUMN IF EXISTS tm_cost_type,
  DROP COLUMN IF EXISTS report_model, DROP COLUMN IF EXISTS f_at_cost,
  DROP COLUMN IF EXISTS is_legacy;
UPDATE app_settings SET setting_value='1.8.99'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Poi rieseguire `migration_v1_8_99.sql` per ripristinare la vista precedente.
