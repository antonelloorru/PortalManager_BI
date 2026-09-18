# Deployment — PortalManager v1.9.2

**Correttiva della v1.9.1**: sostituisce una formula ricostruita con il valore che
il gestionale già fornisce.

## 1. Contenuto

```
VERSION                          1.9.2
app/Version.php                  PM_VERSION = 1.9.2
gli altri file                   invariati da v1.9.1
sql/migration_v1_9_2.sql         3 viste (2 ridefinite, 1 nuova)
sql/upgrade_1_7_56_to_1_9_2.sql  consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_2.sql` (da v1.9.1) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica sugli esempi del vostro foglio

```sql
SELECT commessa, tipo_contratto, valore_commessa, costi_consuntivati,
       margine, margine_maturato
  FROM v_cm_margine_formula
 WHERE commessa IN ('WTS_3814','WTS_3925','WTS_4100','WTS_4042');
```

| Commessa | Foglio | Portale |
|---|---|---|
| WTS_3814 (SOC) | 37.126,00 | **37.126,00** |
| WTS_3925 (GES) | 1.350,00 | **1.350,00** |
| WTS_4100 (PRES) | 32.533,76 | 32.954,96 |
| WTS_4042 (SD) | 20.040,56 | 20.803,09 |

Le differenze su PRES e SD dipendono dalla **data di riferimento**: il vostro
foglio è al 26/08, il dump al 19/08.

## 4. Quanto sbagliava la ricostruzione

```sql
SELECT tipo_contratto, commesse, commesse_divergenti,
       ROUND(margine_ricostruito) AS ricostruito,
       ROUND(margine_gestionale) AS gestionale,
       ROUND(scarto) AS scarto
  FROM v_cm_margine_scostamento
 WHERE ABS(scarto) > 1000 ORDER BY ABS(scarto) DESC;
```

| Tipo | Diverse | Scarto |
|---|---|---|
| **Contr. Servizi Scalare** | 162 su 168 | **−1.870.326** |
| **Presidio** | 12 su 50 | **+609.310** |
| Servizio Gestito SOC | 1 su 16 | +195.000 |

**Totale: −1.295.318 su 194 commesse divergenti.**

## 5. Correzione della v1.9.1

Nella release precedente avevo annunciato uno scostamento di **+5,36 milioni**.
Era sbagliato: nasceva da una lettura errata della formula C.

Il valore corretto è **−1.295.318**, e non richiede alcun ricalcolo perché il
gestionale lo fornisce già.

Se avete guardato i numeri della v1.9.1, **scartateli**: quelli di questa release
li sostituiscono.

## 6. Le voci del vostro foglio

```sql
SELECT * FROM v_cm_voci_calcolo ORDER BY ordine;
```

| Voce | Colonna |
|---|---|
| Valore della commessa (A) | `value_total` |
| Ricavi maturati (D) | `value_todate` |
| Costi direzionali (G) | `actual_cost` |
| **Margine totale (M)** | **`margin_total`** |
| **Margine maturato (P)** | **`margin_todate`** |

## 7. Cosa resta della tabella di riferimento

`cm_calc_reference` non viene rimossa: sapere **come** il gestionale calcola una
commessa è utile anche quando il risultato lo si legge invece di ricalcolarlo.

Serve per spiegare perché due commesse con valori simili hanno margini diversi, e
per accorgersi se un tipo cambiasse regola.

## 8. Rollback

```sql
DROP VIEW IF EXISTS v_cm_voci_calcolo;
UPDATE app_settings SET setting_value='1.9.1'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Poi rieseguire `migration_v1_9_1.sql` — ma ripristinerebbe il calcolo errato.
