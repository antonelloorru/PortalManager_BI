# Deployment — PortalManager v1.8.85

**Correttiva urgente**: senza, la pagina Service Desk non si apre.

## 1. Contenuto

```
VERSION                          1.8.85
service_desk.php                 (ROOT)  correzione della chiamata
app/Version.php                  PM_VERSION = 1.8.85
gli altri file                   invariati da v1.8.84
sql/migration_v1_8_85.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_85.sql consolidato cumulativo (575 statement)
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`service_desk.php` in ROOT** e `app/Version.php`.
3. SQL Runner: `sql/migration_v1_8_85.sql` (da v1.8.84) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Se avete già applicato la v1.8.84, basta sovrascrivere i due file: la migration è
un semplice allineamento di versione.

## 3. Verifica

**Gestione Commesse → Service Desk**: la pagina deve aprirsi senza errori.

| Elemento | Atteso |
|---|---|
| Quattro schede in testa | ticket, presi in carico, tasso escalation, da presidiare |
| Form dei filtri | periodo, coda, livello, pulsanti Filtra / Azzera / XLSX |
| Filtro applicato | l'URL conserva lo slug della pagina |

Il terzo punto è quello corretto: premendo **Filtra** la pagina deve ricaricarsi
su sé stessa, non tornare all'elenco commesse.

## 4. Cosa era successo

La pagina invocava `Router::hiddenParams()`, un metodo che non esiste. Serviva a
conservare lo slug opaco della pagina quando il form invia i filtri via GET.

La funzione corretta è `route_slug_field()`, la stessa usata da *Carico &
Sovrapposizioni* e dalle altre pagine con filtri.

`php -l` non poteva rilevarlo: chiamare un metodo inesistente è sintatticamente
valido e fallisce solo all'esecuzione.

## 5. Rollback

Ripristinare `service_desk.php` dalla v1.8.84 — ma la pagina tornerebbe a non
aprirsi. Per disattivare la sezione, rimuovere la voce da `app/MenuManager.php`.

```sql
UPDATE app_settings SET setting_value='1.8.84'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
