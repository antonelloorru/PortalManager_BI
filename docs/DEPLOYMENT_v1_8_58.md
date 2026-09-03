# Deployment — PortalManager v1.8.58

Release **additiva**: una tabella nuova e quattro viste. Nessun dato esistente
viene modificato.

## 1. Contenuto

```
VERSION                          1.8.58
app/Version.php                  PM_VERSION = 1.8.58
gli altri file                   invariati da v1.8.57
sql/migration_v1_8_58.sql        cm_contract_models + 4 viste
sql/upgrade_1_7_56_to_1_8_58.sql consolidato cumulativo (415 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_8_58.sql` (da v1.8.57) oppure
   `sql/upgrade_1_7_56_to_1_8_58.sql`.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica post-deploy

```sql
SELECT * FROM v_cm_quadro_modelli ORDER BY costo DESC;
```

Attesi sette modelli più `da_classificare`. Il presidio deve risultare il primo
per ore (~144.000), le attività interne il secondo (~80.000).

```sql
SELECT commessa, modello, valore_commessa, costo_consuntivato,
       consumo_valore_pct, allerta
  FROM v_cm_redditivita_commessa
 WHERE allerta IN ('SFORATA','prossima al limite')
 ORDER BY consumo_valore_pct DESC;
```

Attese circa 29 commesse fra sforate e prossime al limite.

## 4. Due cose da fare dopo l'aggiornamento

**Classificare WTS-SOC e WTS-AM.** Non erano nell'elenco fornito e restano
*da classificare*. WTS-SOC vale 1,66 milioni: finché non è classificata, i suoi
numeri non entrano in nessun modello.

```sql
UPDATE cm_contract_models SET model='canone', revenue_basis='canone'
 WHERE service_line='WTS-SOC';   -- da confermare
```

Oppure inserirla se assente. La tabella è modificabile senza release.

**Verificare l'anomalia dei canoni.** WTS-REP ha 2,17 milioni di valore su 54
commesse e **2 ore consuntivate**. Verosimilmente le ore di reperibilità sono
imputate alle commesse dove l'intervento avviene.

```sql
SELECT linea_servizio, COUNT(*) AS commesse,
       ROUND(SUM(valore_commessa)) AS valore,
       ROUND(SUM(ore_consuntivate)) AS ore
  FROM v_cm_redditivita_commessa
 WHERE modello = 'canone' GROUP BY linea_servizio;
```

Se confermato, il margine del 95,6% dei canoni non è utilizzabile: il ricavo è
reale ma il costo è altrove.

## 5. Rollback

```sql
DROP VIEW IF EXISTS v_cm_quadro_modelli;
DROP VIEW IF EXISTS v_cm_costo_interno;
DROP VIEW IF EXISTS v_cm_redditivita_commessa;
DROP VIEW IF EXISTS v_cm_marginalita_modello;
DROP TABLE IF EXISTS cm_contract_models;
UPDATE app_settings SET setting_value='1.8.57'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
