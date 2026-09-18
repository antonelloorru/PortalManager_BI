# Deployment — PortalManager v1.8.73

## 1. Contenuto

```
VERSION                          1.8.73
app/SyncDatasets.php             + 2 dataset DGB (14 totali)
app/Version.php                  PM_VERSION = 1.8.73
gli altri file                   invariati da v1.8.72
sql/migration_v1_8_73.sql        import_batch_id sulle 2 tabelle + vista
sql/upgrade_1_7_56_to_1_8_73.sql consolidato cumulativo (509 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_73.sql` (da v1.8.72) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto** (ora **quattordici**
   dataset).

La sincronizzazione richiederà più tempo del solito: le due tabelle nuove hanno
circa 80.000 e 70.000 righe.

## 3. Verifica post-deploy

```sql
SELECT * FROM v_cm_allineamento_dgb ORDER BY mese DESC LIMIT 12;
```

| Colonna | Significato |
|---|---|
| `attivita_dgb` | attività nella pagina DGB |
| `rapporti_intervento` | rapporti sulla scheda commessa |
| `scarto` | differenza fra le due |
| `pct_copertura` | attività in percentuale sui rapporti |

**Prima** dell'aggiornamento, agosto mostrava 348 attività contro 714 rapporti.
**Dopo** la sincronizzazione i due numeri devono avvicinarsi.

Un residuo di scarto è normale: un'attività può avere più operatori, e le due
tabelle contano cose leggermente diverse. Quello che non deve esserci è un mese
recente con copertura vicina a zero.

## 4. Nella pagina

**Attività & Rendicontazione DGB**: i dati devono ora arrivare fino alla data
odierna, non fermarsi a luglio.

Se restano indietro, verificare nell'esito della sincronizzazione che i due
dataset **Attività DGB** e **Ore per operatore su attività DGB** riportino esito
`ok` e un numero di righe dell'ordine delle decine di migliaia.

## 5. L'import separato non serve più

`DgbSync` restava l'unico modo di aggiornare quelle tabelle. Ora che sono dentro
la sincronizzazione, *Sincronizza tutto* basta.

## 6. Rollback

```sql
DROP VIEW IF EXISTS v_cm_allineamento_dgb;
UPDATE app_settings SET setting_value='1.8.72'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Ripristinare `app/SyncDatasets.php`. Le colonne `import_batch_id` possono
restare: sono inerti.
