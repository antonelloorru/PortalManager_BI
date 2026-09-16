# Deployment — PortalManager v1.9.4

## 1. Contenuto

```
VERSION                          1.9.4
sync_commesse.php                (ROOT)  registrazione dello stato
app/Version.php                  PM_VERSION = 1.9.4
gli altri file                   invariati da v1.9.3
sql/migration_v1_9_4.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_4.sql  consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`sync_commesse.php` in ROOT** e `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_4.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

**Gestione Commesse → Sincronizzazione Gestionale** → *Sincronizza tutto*.

Al termine, nel riquadro **Pianificazione**:

- **Ultima esecuzione** riporta ora e data appena trascorse
- **Esito** è `ok` (o `parziale` se qualche dataset è andato in errore)
- Nel registro compare una riga con origine **`manuale`**

E in **home** il riquadro «Ultima sincronia» torna verde, invece di restare su
«in ritardo» con dati appena aggiornati.

## 4. I tre stati

| Azione | Stato |
|---|---|
| Sincronizza tutto | `ok` / `parziale` / `errore` |
| Sincronizza un dataset | **`dataset`** |
| Import da CSV | **`dataset`** |

**`dataset` è deliberatamente diverso.** Il cron salta la giornata quando trova
`ok` o `parziale`: se avessi marcato così anche l'aggiornamento di una singola
tabella, un import di prova avrebbe **sospeso in silenzio la sincronizzazione
automatica di quel giorno**.

Per la stessa ragione `dataset` non fa tornare verde il riquadro in home: aver
aggiornato una tabella non è aver sincronizzato.

## 5. Il lock non viene toccato

Il lock che impedisce due esecuzioni automatiche sovrapposte **resta al cron**.
Una sincronizzazione manuale non lo prende: prenderlo bloccherebbe l'esecuzione
successiva per tre ore senza motivo.

Come conseguenza, **una sincronizzazione manuale e una automatica possono
sovrapporsi** se lanciate nello stesso momento. È un caso raro e preferibile
all'alternativa.

## 6. Il registro distingue l'origine

```sql
SELECT started_at, trigger_type, status, datasets_ok, rows_read, seconds, note
  FROM cm_sync_schedule_log ORDER BY id DESC LIMIT 20;
```

`trigger_type` vale `manuale` o quello usato dal cron. Serve a distinguere un
portale sincronizzato a mano ogni giorno da uno con la pianificazione
funzionante: sono situazioni diverse, e appiattirle nasconderebbe una
pianificazione guasta.

## 7. Se le tabelle della pianificazione non ci sono

La registrazione fallisce in silenzio: la sincronizzazione è comunque avvenuta, e
un errore qui la farebbe sembrare fallita.

Per attivare la pianificazione serve la migration **v1.8.75**.

## 8. Rollback

Ripristinare `sync_commesse.php` e `app/Version.php` dalla v1.9.3.

```sql
UPDATE app_settings SET setting_value='1.9.3'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
