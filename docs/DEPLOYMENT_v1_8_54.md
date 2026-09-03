# Deployment — PortalManager v1.8.54

Release **solo additiva**: nessun dato viene modificato. La migration aggiunge
soglie e cinque viste.

## 1. Contenuto

```
VERSION                          1.8.54
dgb_activities.php               (ROOT)  scheda "Anomalie orarie"
app/Version.php                  PM_VERSION = 1.8.54
gli altri file                   invariati da v1.8.53
sql/migration_v1_8_54.sql        soglie + 5 viste
sql/upgrade_1_7_56_to_1_8_54.sql consolidato cumulativo (393 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_54.sql` (da v1.8.53) oppure
   `sql/upgrade_1_7_56_to_1_8_54.sql`.
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.54` |
| Attività & Rendicontazione DGB | compare la linguetta **Anomalie orarie** |
| Sulla linguetta | un contatore rosso con le segnalazioni gravi |
| Aprendo la scheda | schede di riepilogo per tipo ed elenco |
| Cliccando una scheda di riepilogo | l'elenco si filtra su quel tipo |

Controllo da SQL:

```sql
SELECT * FROM v_dgb_anomalie_riepilogo ORDER BY severita, tipo;
```

Sui dati attuali sono attese circa **1.459** segnalazioni di ore duplicate
(severità alta), **14** giornate oltre le 24 ore (alta) e **767** fra 12 e 24
(media).

## 4. Come leggere le segnalazioni

**Non sono errori accertati.** Il portale non conosce il contesto di ogni
intervento: alcune ore identiche su più commesse sono legittime, quando un
intervento serve davvero più commesse in parallelo.

Ordine di lavorazione suggerito:

1. **Ore giornaliere oltre 24 h** (14 casi): errori certi, si correggono subito.
2. **Ore identiche su più commesse** (1.459): partire dai casi con più ore
   coinvolte, che sono anche i più probabili errori di copia.
3. **Ore fra 12 e 24 h** (767): verificare a campione, molte saranno reperibilità
   notturne legittime.

Le anomalie sono calcolate in lettura: correggendo il dato sul gestionale e
risincronizzando, la segnalazione sparisce da sola senza operazioni aggiuntive.

## 5. Soglie

In `app_settings`: `anomaly_hours_day_max` (24), `anomaly_hours_day_warn` (12),
`anomaly_min_projects` (2).

Come per gli orari della v1.8.53, i valori sono presenti anche nelle viste SQL:
modificare il parametro documenta la nuova soglia ma richiede una piccola release
per applicarla.

## 6. Rollback

Ripristinare `dgb_activities.php` dalla copia precedente, poi:

```sql
DROP VIEW IF EXISTS v_dgb_anomalie_riepilogo;
DROP VIEW IF EXISTS v_dgb_anomalie_orario;
DROP VIEW IF EXISTS v_dgb_anomalia_ore_giorno;
DROP VIEW IF EXISTS v_dgb_anomalia_ore_duplicate;
UPDATE app_settings SET setting_value='1.8.53'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Nessun dato è stato modificato.
