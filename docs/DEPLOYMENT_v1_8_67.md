# Deployment — PortalManager v1.8.67

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.67
dgb_activities.php               (ROOT)  matrice giorno × ora
sync_commesse.php                (ROOT)  pulsante Anteprima integrale
app/DgbModel.php                 + hourlyHeatmap()
app/DatasetSync.php              + previewAll() in streaming
app/Version.php                  PM_VERSION = 1.8.67
gli altri file                   invariati da v1.8.66
sql/migration_v1_8_67.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_67.sql consolidato cumulativo (481 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i cinque file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_67.sql` (da v1.8.66) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica — anteprima integrale

**Sincronizzazione gestionale**: i pulsanti sono ora tre.

| Pulsante | Righe | Scrive | Tempo |
|---|---|---|---|
| Anteprima completa | 200 per dataset | no | secondi |
| **Anteprima integrale** | tutte | no | **alcuni minuti** |
| Sincronizza tutto | tutte | **sì** | alcuni minuti |

Lanciando l'anteprima integrale, il titolo dell'esito mostra **ANTEPRIMA
INTEGRALE** e i conteggi sono quelli reali: decine di migliaia di righe per
allocazioni e rapporti, non 200.

L'operazione non scrive nulla: si può lanciare in sicurezza per sapere che cosa
farebbe la sincronizzazione.

**Se va in timeout**, aumentare `max_execution_time` in `php.ini`. La memoria non
è un problema: la lettura è in streaming e non cresce con il volume.

## 4. Verifica — distribuzione sulle 24 ore

**Attività & Rendicontazione DGB** → vista **Giorni (mese)**.

Sotto il grafico compare la matrice: 24 righe per le ore, una colonna per giorno.

| Controllo | Atteso |
|---|---|
| Ore in grassetto a sinistra | 09, 10, 11, 12, 14, 15, 16, 17 (fasce ordinarie) |
| Colonne con intestazione chiara | sabati e domeniche |
| Celle più scure | fra le 9 e le 17 |
| Passando sul mouse su una cella | giorno, ora e ore esatte |

La matrice compare **solo in vista giornaliera**: su dodici barre mensili una
distribuzione oraria non avrebbe un asse su cui svilupparsi.

## 5. Nota sui numeri

Le ore mostrate nella matrice sono **ripartite sulle fasce attraversate**, non
attribuite all'orario di inizio.

Se confrontate con un conteggio fatto per orario di inizio troverete valori molto
diversi: quello attribuisce il 64% delle ore alle 09:00, perché è l'orario con
cui la maggior parte degli interventi viene registrata. La ripartizione mostra
dove il lavoro è stato realmente svolto.

Verifica di coerenza: le ore fuori fascia ordinaria risultano il **13,8%**,
contro il 13,48% che la classificazione della v1.8.53 calcola per altra via.

## 6. Rollback

Ripristinare i quattro file dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.66'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
