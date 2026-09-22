# Deployment — PortalManager v1.9.14

**Correttiva di interfaccia**: il grafico dell'andamento aveva tre forme diverse.

## 1. Contenuto

```
VERSION                           1.9.14
service_desk.php                  (ROOT)  grafici uniformati a linee
app/Version.php                   PM_VERSION = 1.9.14
gli altri file                    invariati da v1.9.13
sql/migration_v1_9_14.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_14.sql  consolidato cumulativo
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_14.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Verifica

L'andamento deve essere **a linee** in tutti e tre i punti:

| Dove | Prima | Ora |
|---|---|---|
| Riquadro generale a video | linee | linee |
| **Report di stampa** | **barre** | **linee** |
| **Scheda personale** | **barre** | **linee** |

Aprite il Report generale e la scheda di un componente: il grafico deve avere la
stessa forma della schermata.

## 4. Le assenze restano a barre — è voluto

Il riquadro delle assenze continua a usare le barre impilate.

Ferie, permessi, recuperi e malattia **si sommano davvero** in un totale, e la
pila lo dice meglio di quattro linee separate. Uniformarle avrebbe scambiato la
coerenza formale per correttezza.

## 5. Cosa era successo

Le tre resi nascevano da tre release diverse — v1.8.84, v1.8.86, v1.9.7 — e
ciascuna era ragionevole guardata da sola.

Il difetto è nato dal non aver mai guardato le tre insieme: nessuna delle tre
release aveva motivo di aprire le altre due.

## 6. Rollback

Ripristinare `service_desk.php` dalla v1.9.13.

```sql
UPDATE app_settings SET setting_value='1.9.13'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
