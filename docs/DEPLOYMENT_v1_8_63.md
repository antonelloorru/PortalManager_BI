# Deployment — PortalManager v1.8.63

## 1. Contenuto

```
VERSION                          1.8.63
import_commesse_db.php           (ROOT)  solo configurazione e verifica
sync_commesse.php                (ROOT)  riferimenti aggiornati
app/SyncDatasets.php             11 dataset (3 nuovi)
app/MenuManager.php, Router.php  invariati nella sostanza
app/Version.php                  PM_VERSION = 1.8.63
gli altri file                   invariati da v1.8.62
sql/migration_v1_8_63.sql        3 tabelle + 2 viste
sql/upgrade_1_7_56_to_1_8_63.sql consolidato cumulativo (448 statement)
docs/                            questa documentazione
```

**`app/CommesseSync.php` non è nel pacchetto**: va **eliminato dal server** dopo
l'aggiornamento. Nessun file lo richiede più.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. **Eliminare `app\CommesseSync.php` dal server.**
4. SQL Runner: `sql/migration_v1_8_63.sql` (da v1.8.62) oppure il consolidato.
5. **Stop + Start Apache**, **Ctrl+F5**.
6. **Sincronizzazione gestionale → Sincronizza tutto** (ora undici dataset).

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.63` |
| Connessione al gestionale → Prova connessione | **11 / 11 dataset utilizzabili** |
| La stessa pagina | non ha più pulsanti di import: solo parametri e verifica |
| Sincronizza tutto | undici dataset nella tabella dell'esito |

Volumi attesi sulla sorgente attuale:

| Dataset | Righe |
|---|---|
| divisioni | 8 |
| tipi anomalia | 8 |
| anomalie commessa | ~279 |
| professionisti | **256** (era 512 per un `DISTINCT` mancante) |

## 4. Le nuove analisi

```sql
SELECT * FROM v_cm_anomalie_gestionale ORDER BY gravita, segnalazioni DESC;
```

Le otto regole con segnalazioni totali e **aperte**. Attese ~203 aperte su 279.

```sql
SELECT convergenza, COUNT(*) FROM v_cm_commesse_allerta GROUP BY convergenza;
```

| Convergenza | Attese |
|---|---|
| solo gestionale | ~137 |
| **confermata da entrambi** | **~17** |
| solo portale | ~12 |

Le **confermate da entrambi** sono la priorità: due sistemi indipendenti che
segnalano la stessa commessa.

## 5. Le divisioni

```sql
SELECT * FROM cm_divisions;
```

Otto divisioni: Sistemistica, Assistenza Tecnica, Laboratorio, WeSecure, Antea,
NIS Group, WENEST, WeEnengys.

Sono la struttura aziendale reale, e non vanno confuse con le **unità
organizzative tecniche** (Presidio, SOC, Service Desk) della v1.8.48: quelle sono
una tassonomia di competenza da assegnare a mano, queste arrivano dal gestionale
già popolate.

Il collegamento fra divisione e tecnico non è ancora stabilito: è il passo
successivo.

## 6. Rollback

Ripristinare i file dalla copia precedente, **incluso `app/CommesseSync.php`**,
poi:

```sql
DROP VIEW IF EXISTS v_cm_commesse_allerta;
DROP VIEW IF EXISTS v_cm_anomalie_gestionale;
DROP TABLE IF EXISTS cm_contract_anomalies;
DROP TABLE IF EXISTS cm_anomaly_types;
DROP TABLE IF EXISTS cm_divisions;
UPDATE app_settings SET setting_value='1.8.62'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
