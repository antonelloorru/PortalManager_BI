# Deployment — PortalManager v1.8.76

Release **solo applicativa**: nessuna variazione di schema né di dati. Usa le
tabelle introdotte dalla v1.8.75.

## 1. Contenuto

```
VERSION                          1.8.76
index.php                        (ROOT)  avviso e scheda KPI
app/Version.php                  PM_VERSION = 1.8.76
gli altri file                   invariati da v1.8.75
sql/migration_v1_8_76.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_76.sql consolidato cumulativo (521 statement)
docs/                            questa documentazione
```

**Prerequisito**: la v1.8.75 deve essere già applicata. Senza le tabelle
`cm_sync_schedule` e `cm_sync_schedule_log` l'avviso non compare — non produce
errori, semplicemente non c'è nulla da mostrare.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare `index.php` in ROOT e `app/Version.php` in `app\`.
3. SQL Runner: `sql/migration_v1_8_76.sql` (da v1.8.75) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

Aprire la **home** con un utente di ruolo ≤ 5.

| Elemento | Quando compare |
|---|---|
| Scheda **Ultima sincronia** fra i KPI | sempre |
| Banner in testa | solo se in ritardo, fallita o mai eseguita |

Se la sincronizzazione pianificata non è ancora mai stata eseguita, il banner
apparirà con *«Sincronizzazione con il gestionale mai eseguita»*: è corretto, e
sparirà dopo la prima esecuzione riuscita.

Per provarlo subito:

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_sync.php --force
```

Ricaricando la home, il banner deve sparire e la scheda mostrare `ora` o `0h fa`.

## 4. Le soglie

| Colore della scheda | Ore dall'ultima riuscita |
|---|---|
| verde | fino a 26 |
| giallo | 26 – 36 |
| rosso + banner | oltre 36, o mai |

**26 ore** e non 24: una sincronizzazione notturna che slitti di un'ora non deve
far comparire un avviso.

**36 ore** per il banner: oltre un giorno e mezzo significa che almeno
un'esecuzione è saltata.

## 5. Nota per utenti con ruolo superiore a 5

Non vedono né la scheda né il banner. È deliberato: non hanno accesso alla pagina
di sincronizzazione e non potrebbero agire.

## 6. Rollback

Ripristinare `index.php` dalla copia precedente, poi:

```sql
UPDATE app_settings SET setting_value='1.8.75'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
