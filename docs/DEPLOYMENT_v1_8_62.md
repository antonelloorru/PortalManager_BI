# Deployment — PortalManager v1.8.62

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto

```
VERSION                          1.8.62
import_commesse_db.php           (ROOT)  verifica su tutti i dataset
app/Version.php                  PM_VERSION = 1.8.62
gli altri file                   invariati da v1.8.61
sql/migration_v1_8_62.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_62.sql consolidato cumulativo (441 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `import_commesse_db.php` in ROOT e `app/Version.php` in `app\`.
3. SQL Runner: `sql/migration_v1_8_62.sql` (da v1.8.61) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica post-deploy

**Gestione Commesse → Connessione al gestionale → Prova connessione.**

Il riquadro deve ora mostrare quattro contatori e una tabella per dataset:

| | Atteso |
|---|---|
| Oggetti nello schema | ~102 |
| Tabelle usate dai dataset | 14 |
| **Dataset utilizzabili** | **8 / 8** |
| Tabelle assenti | 0 |

Tutti e otto i dataset devono riportare esito **ok** e il numero di colonne
prodotte: 30 per le commesse, 27 per i rapporti, 15 per le operazioni, e così via.

Il messaggio *«mancano colonne obbligatorie (code, name)»* non compare più: la
verifica non controlla più una singola tabella.

## 4. Se un dataset risulta in difetto

Il riquadro dice quale e perché:

- **tabelle mancanti** — la tabella non esiste nello schema indicato. Verificare
  il nome dello schema nei parametri di connessione, o se la tabella è stata
  rinominata sul gestionale.
- **query non eseguibile** — la tabella c'è ma la query fallisce. Il messaggio di
  errore del server è riportato.
- **colonne non prodotte** — la query gira ma restituisce nomi diversi da quelli
  attesi. Richiede un intervento sul dataset.

**Gli altri dataset funzionano comunque.** La sincronizzazione completa prosegue
su quelli validi e riporta l'errore solo per i difettosi: non serve attendere che
tutto sia a posto per aggiornare il resto.

## 5. Nota su `source_table`

Il parametro **non influenza più** l'esito della verifica. Resta nella
configurazione per la parte residua del vecchio flusso di import, ma non serve
correggerlo: qualunque valore abbia, la verifica controlla comunque tutte e
quattordici le tabelle.

## 6. Rollback

Ripristinare `import_commesse_db.php` dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.61'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Attenzione: tornare indietro significa riavere il messaggio di falso allarme.
