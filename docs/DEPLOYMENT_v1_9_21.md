# Deployment — PortalManager v1.9.21

## 1. Contenuto

```
VERSION                           1.9.21
system_errors.php                 (ROOT)  NUOVA — diagnostica errori PHP
app/Router.php                    pagina registrata
app/MenuManager.php               voce di menu
app/Version.php                   PM_VERSION = 1.9.21
gli altri file                    invariati da v1.9.20
sql/migration_v1_9_21.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_21.sql  consolidato cumulativo (757 statement)
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`system_errors.php` in ROOT** e i tre file in `app\`.
3. SQL Runner: `sql/migration_v1_9_21.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

## 3. Dove si trova

**Sistema → Diagnostica errori**, accanto alla Console di sistema.

Visibile **solo al super amministratore** (`role_id = 1`): il registro contiene
percorsi del filesystem e frammenti di query.

## 4. Cosa guardare per primo

La riga **`log_errors`**. Deve essere `On` in entrambi gli ambienti: senza, un
difetto che non si vede a schermo non lascia alcuna traccia, e quando un utente
segnala un problema non c'è nulla da consultare.

Se compare l'avvertenza rossa **«gli errori non vengono né mostrati né
registrati»**, siete nella configurazione in cui è più difficile capire cosa non
funziona.

## 5. La pagina non modifica la configurazione

`display_errors` e `log_errors` in molte installazioni non sono modificabili a
runtime. Un interruttore che a volte non funziona è peggio di nessun interruttore.

La pagina mostra le righe da scrivere in `php.ini`, con il percorso del portale
già inserito:

```ini
display_errors = Off
log_errors = On
error_log = "P:\xampp\htdocs\portalmanager/logs/php_error.log"
error_reporting = E_ALL & ~E_DEPRECATED
```

Dopo la modifica serve un **riavvio di Apache**. La cartella indicata in
`error_log` **deve esistere ed essere scrivibile** dall'utente del server web.

## 6. Se il registro non è leggibile

La pagina lo dice. Le cause abituali:

- `error_log` non impostato: gli errori finiscono nel log di Apache
- percorso configurato ma cartella inesistente
- permessi insufficienti

Impostare un percorso esplicito dentro il portale rende il registro consultabile
da questa pagina.

## 7. La ricerca nel registro

I sei riquadri in cima filtrano per tipo — fatale, sintassi, avviso, nota,
deprecato, altro. Il campo di ricerca cerca nel testo.

Vengono lette le ultime **400 righe** e mostrate fino a 300 dopo il filtro. Il file
è letto **dalla coda**: un log cresce in fondo, e le righe che interessano sono le
ultime.

## 8. Rollback

Rimuovere `system_errors.php` e ripristinare `app/Router.php` e
`app/MenuManager.php` dalla v1.9.20.

```sql
UPDATE app_settings SET setting_value='1.9.20'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
