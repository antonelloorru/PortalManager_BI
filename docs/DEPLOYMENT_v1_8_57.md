# Deployment — PortalManager v1.8.57

## 1. Contenuto

```
VERSION                          1.8.57
sync_commesse.php                (ROOT)  sincronizzazione completa in un'azione
app/SyncDatasets.php             3 nuovi dataset + ordine di sincronizzazione
app/Version.php                  PM_VERSION = 1.8.57
gli altri file                   invariati da v1.8.56
sql/migration_v1_8_57.sql        3 tabelle + 2 viste analitiche
sql/upgrade_1_7_56_to_1_8_57.sql consolidato cumulativo (407 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_57.sql` (da v1.8.56) oppure
   `sql/upgrade_1_7_56_to_1_8_57.sql`.
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

La migration crea tre tabelle vuote e due viste: nessun dato esistente viene
toccato.

## 3. Prima sincronizzazione completa

**Gestione Commesse → Sincronizzazione gestionale** → riquadro
**Sincronizzazione completa**.

Consigliato: prima **Anteprima completa**, che non scrive nulla ed è limitata a
200 righe per dataset — serve a verificare che tutti e sei i dataset rispondano
senza errori.

Poi **Sincronizza tutto**. Volumi attesi sulla sorgente attuale:

| Dataset | Righe attese |
|---|---|
| commesse | ~1.060 |
| costi fascia | 10 |
| professionisti | ~250 |
| tariffe | ~24.300 |
| allocazioni | ~69.300 |
| rapporti | ~68.000 |

L'operazione richiede alcuni minuti. Se il tempo di esecuzione PHP è
insufficiente, aumentare `max_execution_time` in `php.ini`.

## 4. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.57` |
| Sincronizzazione gestionale | compare **Sincronizzazione completa** |
| Anteprima completa | tabella con sei righe, tutte con esito *ok* |
| Dopo Sincronizza tutto | totali coerenti con la tabella sopra |

Controllo della copertura economica:

```sql
SELECT ricavo_origine, COUNT(*) AS rapporti, ROUND(SUM(ricavo),2) AS ricavo
  FROM v_cm_marginalita GROUP BY ricavo_origine;
```

Atteso: una quota **rilevata** e una **stimata da tariffa**. Prima di questa
release esisteva solo la prima, e i rapporti senza ricavo erano il 67%.

Controllo di assenza di fan-out — è il difetto che questa release ha dovuto
correggere e va riverificato in produzione:

```sql
SELECT (SELECT COUNT(*) FROM cm_intervention_reports) AS base,
       (SELECT COUNT(*) FROM v_cm_marginalita)        AS vista;
```

**I due numeri devono coincidere.** Se la vista ne ha di più, un join sta
moltiplicando e ogni somma è gonfiata.

## 5. Note sui dati sorgente

**Le allocazioni contengono righe duplicate.** La tabella del gestionale non ha
chiave primaria e ogni riga compare due volte: 199.458 righe per 99.729
allocazioni reali. La query del dataset usa `DISTINCT`, quindi il portale ne
importa 99.729 — se ne contaste 199.458 sulla sorgente, non è un errore
dell'import.

**Le tariffe sono per commessa e tipo attività.** Una commessa ne ha in media
sei. La vista di marginalità usa la **media** delle tariffe orarie di ricavo
della commessa, ed espone in `tariffe_disponibili` su quante è calcolata: con una
sola è esatta, con sei è una media.

## 6. Rollback

Ripristinare `sync_commesse.php` e `app/SyncDatasets.php` dalla copia precedente,
poi:

```sql
DROP VIEW IF EXISTS v_cm_effort_confronto;
DROP VIEW IF EXISTS v_cm_marginalita;
UPDATE app_settings SET setting_value='1.8.56'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Le tre tabelle nuove possono restare: sono inerti senza i dataset che le
alimentano. Per rimuoverle:

```sql
DROP TABLE cm_band_costs, cm_project_allocations, cm_contract_rates;
```
