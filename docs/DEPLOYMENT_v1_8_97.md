# Deployment — PortalManager v1.8.97

## 1. Contenuto

```
VERSION                            1.8.97
cron_cost_consolidate.php          (ROOT)  NUOVO — consolidamento costi
app/CostModel.php                  invariato, incluso per dipendenza
app/FormulaEval.php                invariato, incluso per dipendenza
app/Version.php                    PM_VERSION = 1.8.97
gli altri file                     invariati da v1.8.96
sql/migration_v1_8_97.sql          2 tabelle + 4 viste
sql/upgrade_1_7_56_to_1_8_97.sql   consolidato cumulativo (640 statement)
docs/                              questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `cron_cost_consolidate.php` in ROOT e i tre file in `app\`.
3. SQL Runner: `sql/migration_v1_8_97.sql` (da v1.8.96) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Il consolidamento — primo avvio

Dopo la migration, `cm_employee_cost_year` è **vuota**: va popolata.

**Prova senza scrivere:**

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_cost_consolidate.php --year=2025 --dry-run
```

**Consolidamento reale:**

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_cost_consolidate.php --year=2025
```

Attesi circa **189 dipendenti** consolidati e 97 senza dati economici.

Per tutti gli esercizi configurati: `--all-years`.

## 4. Verifica

```sql
SELECT * FROM v_cm_costo_stato\G
```

```sql
SELECT e.last_name, e.first_name, c.tot_costo_tab, c.costo_giorno, c.costo_ora
  FROM cm_employee_cost_year c JOIN employees e ON e.id = c.employee_id
 WHERE c.year = 2025 ORDER BY c.costo_ora DESC LIMIT 10;
```

La formula: `TotCostoTab / giorni_lavorativi / 8`. Esempio verificato:
`107.298,84 / 220 / 8 = 60,97 €/ora`.

## 5. Quando rieseguirlo

- dopo l'aggiornamento dei **dati finanziari** di un esercizio
- quando cambiano **RAL, overhead** o altri parametri di calcolo
- all'apertura di un **nuovo esercizio**

Non serve eseguirlo ogni giorno: i costi cambiano per esercizio, non
quotidianamente.

## 6. I giorni lavorativi

```sql
SELECT * FROM cm_cost_year_params;
UPDATE cm_cost_year_params SET working_days = 254 WHERE year = 2026;
```

Predefinito **220** per tutti gli anni configurati. Dopo averlo cambiato,
rieseguire il consolidamento per quell'anno.

**Chiudere un esercizio** ne protegge i costi dal ricalcolo:

```sql
UPDATE cm_cost_year_params SET is_closed = 1 WHERE year = 2025;
```

I margini di un esercizio chiuso sono già stati usati in report consegnati:
riscriverli li renderebbe irriproducibili. Con `--force` si forza comunque.

## 7. Il ripiego sull'esercizio precedente

Se manca il dato dell'anno dell'intervento, si usa **l'ultimo esercizio
consolidato precedente**:

| Origine | Interventi |
|---|---|
| consolidato | 18.224 |
| stimato da 2025 | 12.319 |
| non disponibile | 36.071 |

I 12.319 del 2026 usano il consolidato 2025. **Caricando il dato finanziario 2026
e rieseguendo lo script, passano automaticamente a "consolidato".**

I 36.071 «non disponibile» sono interventi di dipendenti senza dati economici,
oppure precedenti al primo esercizio consolidato.

## 8. Redditività a costo reale

```sql
SELECT commessa, cliente, ricavo, costo_aziendale, costo_vendita,
       margine_aziendale_pct, scarto_costo_pct, copertura_pct
  FROM v_cm_redditivita_costo_reale
 WHERE ha_ricavo = 1 ORDER BY ricavo DESC LIMIT 20;
```

Due grandezze affiancate:

- **costo aziendale**: quanto costa erogare il servizio — dal TotCostoTab
- **costo di vendita**: quanto è stato addebitato — dal gestionale

Sui dati: 3.140.390 € contro 7.935.863 €. `scarto_costo_pct` misura la divergenza
per commessa.

**`copertura_pct` va guardata sempre**: dice quale quota degli interventi ha un
costo consolidato. Un margine calcolato su una copertura del 20% è indicativo, non
definitivo.

## 9. Rollback

```sql
DROP VIEW IF EXISTS v_cm_costo_stato;
DROP VIEW IF EXISTS v_cm_redditivita_costo_reale;
DROP VIEW IF EXISTS v_cm_costo_intervento;
DROP VIEW IF EXISTS v_cm_costo_dipendente_anno;
DROP TABLE IF EXISTS cm_employee_cost_year;
DROP TABLE IF EXISTS cm_cost_year_params;
UPDATE app_settings SET setting_value='1.8.96'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
