# Deployment — PortalManager v1.8.75

## 1. Contenuto

```
VERSION                          1.8.75
cron_sync.php                    (ROOT)  NUOVO — script CLI della pianificazione
sync_commesse.php                (ROOT)  pannello di configurazione
app/Version.php                  PM_VERSION = 1.8.75
gli altri file                   invariati da v1.8.74
sql/migration_v1_8_75.sql        2 tabelle + vista di stato
sql/upgrade_1_7_56_to_1_8_75.sql consolidato cumulativo (520 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i tre file rispettando i percorsi. **`cron_sync.php` va nella ROOT**.
3. SQL Runner: `sql/migration_v1_8_75.sql` (da v1.8.74) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Configurare l'orario nel portale

**Sincronizzazione gestionale** → riquadro **Sincronizzazione giornaliera
pianificata**.

Impostare ora, giorni e finestra di recupero, spuntare *Pianificazione attiva* e
salvare.

Consigliato: **02:00, tutti i giorni, finestra 120 minuti**. La riconciliazione
va attivata solo dopo aver verificato manualmente che non produca rimozioni
inattese.

## 4. Creare l'attività su Windows — il passo che serve

Il portale decide *se* è il momento, ma non può avviarsi da solo.

**Utilità di pianificazione** → *Crea attività*:

| Scheda | Impostazione |
|---|---|
| Generale | Nome: `PortalManager - Sincronizzazione`<br>*Esegui anche se l'utente non ha effettuato l'accesso*<br>*Esegui con privilegi più elevati* |
| Attivazione | Giornaliero, ore 00:00<br>*Ripeti l'attività ogni*: **1 ora**, per **1 giorno** |
| Azioni | Programma: `P:\xampp\php\php.exe`<br>Argomenti: `P:\xampp\htdocs\portalmanager\cron_sync.php --quiet`<br>Inizio in: `P:\xampp\htdocs\portalmanager` |
| Condizioni | togliere *Avvia solo se il computer è alimentato* |

**Perché ogni ora e non alle 02:00.** Se l'attività girasse solo alle 02:00 e il
server fosse spento, la sincronizzazione salterebbe il giorno. Girando ogni ora,
lo script trova la finestra ancora aperta al primo avvio utile e recupera.

Fuori dalla finestra termina in meno di un secondo senza toccare nulla: l'attività
oraria non comporta alcun carico.

## 5. Verifica

Da prompt dei comandi, per provare senza aspettare:

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_sync.php --force --dry-run
```

`--dry-run` non scrive nulla e **non aggiorna** l'ultima esecuzione, quindi non
fa saltare la sincronizzazione vera del giorno.

Poi una esecuzione reale:

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_sync.php --force
```

Nel portale, il riquadro deve mostrare ultima esecuzione, esito e durata, e
l'elenco *Ultime esecuzioni* la riga corrispondente.

## 6. Controllo periodico

```sql
SELECT * FROM v_cm_sync_schedule_stato;
```

| Diagnosi | Significato |
|---|---|
| `regolare` | tutto a posto |
| **`IN RITARDO`** | attiva ma non gira da oltre 36 ore: l'attività di Windows è ferma o fallisce |
| `ultima esecuzione fallita` | verificare `last_note` |
| `mai eseguita` | l'attività di Windows non è stata creata |

Se risulta *IN RITARDO*, controllare nell'Utilità di pianificazione la cronologia
dell'attività e il codice di uscita dell'ultima esecuzione.

## 7. Se la sincronizzazione richiede troppo tempo

Lo script imposta `set_time_limit(0)` e `memory_limit` a 512M, quindi i limiti
del web server non si applicano. Su quindici dataset e volumi attuali servono
alcuni minuti.

Il lock scade dopo **3 ore**: se una esecuzione superasse quel tempo, una seconda
potrebbe partire. È un limite volutamente generoso, ma va tenuto presente se i
volumi crescessero molto.

## 8. Rollback

```sql
DROP VIEW IF EXISTS v_cm_sync_schedule_stato;
DROP TABLE IF EXISTS cm_sync_schedule_log;
DROP TABLE IF EXISTS cm_sync_schedule;
UPDATE app_settings SET setting_value='1.8.74'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

**Disattivare o eliminare l'attività nell'Utilità di pianificazione**, altrimenti
continuerebbe a invocare uno script che non trova più le sue tabelle.
