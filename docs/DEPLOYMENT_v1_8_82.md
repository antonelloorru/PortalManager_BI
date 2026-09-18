# Deployment — PortalManager v1.8.82

## 1. Contenuto

```
VERSION                          1.8.82
app/SyncDatasets.php             + dataset ticket_messaggi (16 totali)
app/Version.php                  PM_VERSION = 1.8.82
gli altri file                   invariati da v1.8.81
sql/migration_v1_8_82.sql        cm_sd_messages + 5 viste
sql/upgrade_1_7_56_to_1_8_82.sql consolidato cumulativo (567 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i due file in `app\`.
3. SQL Runner: `sql/migration_v1_8_82.sql` (da v1.8.81) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.
5. **Sincronizzazione gestionale → Sincronizza tutto** (ora sedici dataset).

## 3. Prerequisito: i tecnici assegnati all'unità

La classificazione L1/L2 si basa su **Unità Organizzative Tecniche → Service
Desk**. Verificare che il team sia assegnato:

```sql
SELECT * FROM v_cm_sd_team;
```

Attesi quattro tecnici. Se la vista è vuota, **tutti i ticket risulterebbero
gestiti da specialisti** e il tasso di escalation sarebbe privo di significato.

Aggiungere o togliere un tecnico dall'unità nel portale si riflette
immediatamente sulle statistiche: il team è letto in tempo reale, non copiato.

## 4. Le viste disponibili

| Vista | Contenuto |
|---|---|
| `v_cm_sd_team` | i tecnici di primo livello |
| `v_cm_sd_messaggi` | messaggi con livello L1/L2 |
| `v_cm_sd_ticket` | il ticket ricostruito, con stato e classe di gestione |
| `v_cm_sd_riepilogo` | quadro mensile con tasso di escalation |
| `v_cm_sd_operatori` | operatività per tecnico |

```sql
SELECT * FROM v_cm_sd_riepilogo ORDER BY anno_mese DESC LIMIT 12;
```

## 5. Come leggere le classi di gestione

| Classe | Significato |
|---|---|
| **risolto dal Service Desk** | solo messaggi L1 |
| **escalation di 2° livello** | iniziato da L1, proseguito da specialisti |
| **presa in carico diretta da specialisti** | nessun messaggio L1: nasce su coda specialistica |
| **senza risposta** | nessun messaggio di supporto |

**Il tasso di escalation è calcolato solo sui ticket presi in carico dal Service
Desk** — 105 su 1.470, il 3,0%. Includere le prese in carico dirette lo porterebbe
al 54%, un numero drammatico e privo di senso: quei ticket non sono mai passati
dal primo livello.

## 6. I ticket senza risposta

Sono 571, ma **553 sono chiusi**: notifiche automatiche o richieste risolte per
telefono. Non un problema di servizio.

```sql
SELECT * FROM v_cm_sd_ticket
 WHERE gestione = 'senza risposta' AND stato <> 'CLOSED';
```

Questi — attesi 18 — sono quelli da guardare.

## 7. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sd_operatori;
DROP VIEW IF EXISTS v_cm_sd_riepilogo;
DROP VIEW IF EXISTS v_cm_sd_ticket;
DROP VIEW IF EXISTS v_cm_sd_messaggi;
DROP VIEW IF EXISTS v_cm_sd_team;
DROP TABLE IF EXISTS cm_sd_messages;
UPDATE app_settings SET setting_value='1.8.81'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
