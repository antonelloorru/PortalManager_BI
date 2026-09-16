# Deployment — PortalManager v1.9.16

## 1. Contenuto

```
VERSION                           1.9.16
app/Version.php                   PM_VERSION = 1.9.16
gli altri file                    invariati da v1.9.15
sql/migration_v1_9_16.sql         2 tabelle di mappatura + 3 viste ridefinite
sql/upgrade_1_7_56_to_1_9_16.sql  consolidato cumulativo
docs/                             questa documentazione
```

**Nessun file applicativo modificato**: le viste cambiano, le pagine no.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_16.sql` (da v1.9.15) oppure il consolidato.
4. **Stop + Start Apache**.

## 3. Verifica

```sql
SELECT fascia, um, tipo, commesse, valorizzate, copertura_pct, minima, media, massima
  FROM v_cm_rate_disponibili ORDER BY fascia, um;
```

Attesa fascia C fra il **59,5% e il 65,1%** di copertura secondo l'unità.

```sql
SELECT descrizione_tariffa, COUNT(*) AS n, ROUND(SUM(ore),2) AS ore,
       ROUND(SUM(valore),2) AS valore, MAX(tariffa_origine) AS origine
  FROM v_cm_sd_costi_valorizzati
 GROUP BY descrizione_tariffa ORDER BY descrizione_tariffa;
```

`tariffa_origine` deve valere **`contratto`** dove il listino esiste.

## 4. Le tariffe erano già sincronizzate

`cm_contract_rates` conteneva già **35.335 righe su 1.173 commesse**, dal dataset
`tariffe`.

**Stavo per aggiungere una seconda tabella con gli stessi dati**: me ne sono
accorto perché la chiave del dataset risultava già presente. Se l'aveste applicata,
avreste avuto due listini da tenere allineati.

Non serve risincronizzare: i dati ci sono già.

## 5. Sei fasce, e la fascia si legge dal modulo

| `id_activitytype` | Fascia |
|---|---|
| 1 | A |
| 2 | B |
| 3 | C |
| 4 | D |
| 5 | E |
| 6 | X |

La fascia è un **attributo dell'attività**, non una deduzione dall'orario. Il
calcolo la legge da `dgb_forms_activity.id_activitytype` quando c'è.

Dove l'attività non è collegata, ricade sulla deduzione da orario e
`fascia_origine` lo dichiara:

```sql
SELECT fascia_origine, COUNT(*) FROM v_cm_sd_costi_valorizzati GROUP BY fascia_origine;
```

**Se «dedotta da orario» è alto**, molti moduli non hanno l'attività collegata: le
loro fasce sono supposte, non lette.

## 6. `CEH` è un costo, non un ricavo

Non era nella mappatura che mi avete dato, ma ha valori reali su 3.794 righe.

`rate_nature` vale `C` per `CEH` e `R` per le altre quattro. **Il calcolo usa solo
le `R`**: sommarle darebbe un numero che non è né ricavo né costo.

Se vi serve anche la valorizzazione a costo, ditemelo — la struttura la accoglie
già.

## 7. Il 43% delle tariffe è a zero

Zero significa **«combinazione non prevista dal contratto»**, non «gratis».

Un modulo su una combinazione a zero non riceve valore, e `tariffa_origine` vale
`assente`. Il ripiego sulle tariffe dedotte della v1.9.15 resta attivo dove il
contratto non ha il listino.

## 8. Rollback

```sql
DROP VIEW IF EXISTS v_cm_rate_disponibili;
DROP TABLE IF EXISTS cm_um_tempi;
DROP TABLE IF EXISTS cm_um_fasce;
UPDATE app_settings SET setting_value='1.9.15'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Poi rieseguire `migration_v1_9_15.sql` per ripristinare le viste precedenti.
`cm_contract_rates` **non va toccata**: esisteva già prima di questa release.
