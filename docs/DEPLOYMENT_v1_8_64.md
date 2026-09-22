# Deployment — PortalManager v1.8.64

**Release correttiva**: senza, la sincronizzazione completa fallisce su tutti i
dataset.

## 1. Contenuto

```
VERSION                          1.8.64
sync_commesse.php                (ROOT)  rete di sicurezza sulle transazioni
app/DatasetSync.php              rollback in caso di errore
app/Version.php                  PM_VERSION = 1.8.64
gli altri file                   invariati da v1.8.63
sql/migration_v1_8_64.sql        import_batch_id su 9 tabelle + controllo
sql/upgrade_1_7_56_to_1_8_64.sql consolidato cumulativo (465 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i tre file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_64.sql` (da v1.8.63) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica post-deploy

Prima di risincronizzare, il controllo di schema:

```sql
SELECT * FROM v_cm_sync_schema_check;
```

**Deve restituire zero righe.** Se ne restituisce, una tabella di destinazione è
priva di `import_batch_id` e la sincronizzazione fallirà su quel dataset.

Poi **Sincronizzazione gestionale → Sincronizza tutto**.

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.64` |
| Esito della sincronizzazione | **11 dataset con esito `ok`** |
| Divisioni aziendali | 8 righe, non più *Unknown column* |
| Gli altri dieci | non più *There is already an active transaction* |

Volumi attesi: divisioni 8, tipi anomalia 8, anomalie ~279, professionisti 256,
costi fascia 10, full cost 256, commesse ~1.060, tariffe ~24.300, allocazioni
~69.300, operazioni ~2.600, rapporti ~68.000.

## 4. Se un dataset fallisce ancora

Ora **gli altri proseguono**: era il difetto principale. Un fallimento parziale è
riportato nella tabella dell'esito con il messaggio di errore accanto al dataset,
e i restanti vengono comunque aggiornati.

Prima di questa release un solo errore bloccava tutti, perché lasciava aperta una
transazione che avvelenava i dataset successivi.

## 5. Perché era fallito tutto

La colonna `import_batch_id` mancava su cinque tabelle introdotte fra la v1.8.57
e la v1.8.63. Da sola avrebbe fatto fallire un dataset.

Ne ha fatti fallire undici perché `DatasetSync` non faceva rollback: la
transazione restava aperta e ogni dataset successivo la trovava attiva. E perché
le Divisioni sono in **testa** all'ordine di sincronizzazione — se fossero state
ultime, dieci dataset sarebbero passati.

## 6. Rollback

Ripristinare i tre file dalla copia precedente, poi:

```sql
DROP VIEW IF EXISTS v_cm_sync_schema_check;
UPDATE app_settings SET setting_value='1.8.63'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Le colonne `import_batch_id` possono restare: sono inerti e la loro assenza è ciò
che causava il difetto.
