# Deployment — PortalManager v1.9.13

## 1. Contenuto

```
VERSION                           1.9.13
service_desk.php                  (ROOT)  etichette e grafici adattivi
app/SdModel.php                   granularità in trend() + 2 metodi ausiliari
app/Version.php                   PM_VERSION = 1.9.13
gli altri file                    invariati da v1.9.12
sql/migration_v1_9_13.sql         1 parametro
sql/upgrade_1_7_56_to_1_9_13.sql  consolidato cumulativo
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e i due file in `app\`.
3. SQL Runner: `sql/migration_v1_9_13.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Service Desk** → impostare un periodo di **un mese**: il grafico mostra un punto
per giorno, con etichette `GG/MM`.

Poi un periodo di **un anno**: il grafico torna mensile, etichette `AA-MM`.

Il riquadro dichiara quale raggruppamento è in uso.

## 4. La soglia

**92 giorni**, non 90.

| Periodo | Giorni | Grana |
|---|---|---|
| gennaio–marzo | 90 | giornaliera |
| maggio–luglio | 92 | giornaliera |
| maggio–1 agosto | 93 | mensile |

Con 90, gennaio-marzo sarebbe stato giornaliero e maggio-luglio mensile: due
periodi che chiamate entrambi «tre mesi» si sarebbero comportati in modo diverso.

Per cambiarla:

```sql
UPDATE app_settings SET setting_value = '120'
 WHERE setting_key = 'sd_trend_giorni_soglia';
```

## 5. Un limite da conoscere

Su base giornaliera il grafico può avere fino a 92 punti. È leggibile, ma
**i giorni senza ticket non compaiono**: l'asse mostra solo le date che hanno
almeno un ticket, quindi la spaziatura non è uniforme.

Su un servizio con attività quotidiana la differenza non si nota. Su uno con pochi
ticket sparsi, due punti adiacenti possono distare una settimana.

Se vi serve un asse a passo costante con gli zeri espliciti, ditemelo: richiede di
generare il calendario e unirlo ai dati, ed è una modifica circoscritta.

## 6. Rollback

Ripristinare `service_desk.php` e `app/SdModel.php` dalla v1.9.12.

```sql
DELETE FROM app_settings WHERE setting_key = 'sd_trend_giorni_soglia';
UPDATE app_settings SET setting_value='1.9.12'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
