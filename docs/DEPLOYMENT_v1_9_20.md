# Deployment — PortalManager v1.9.20

**Correttiva**: `Warning: Undefined variable $gOp`.

## 1. Contenuto

```
VERSION                           1.9.20
it_service.php                    (ROOT)  assegnazioni spostate nel try
app/Version.php                   PM_VERSION = 1.9.20
gli altri file                    invariati da v1.9.19
sql/migration_v1_9_20.sql         solo bump di versione
sql/upgrade_1_7_56_to_1_9_20.sql  consolidato cumulativo (756 statement)
docs/                             questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`it_service.php` in ROOT** e `app/Version.php`.
3. SQL Runner: `sql/migration_v1_9_20.sql` oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Sono sufficienti i due file: la migration è un allineamento di versione.

## 3. Verifica

**Relazione di Servizio IT**: la pagina deve caricarsi **senza avvisi**, e il
riquadro «Giorni lavorati per persona» deve comparire.

Se avete gli avvisi PHP disattivati in produzione, il sintomo era diverso: il
riquadro non compariva affatto, perché `$gOp` valeva `null`.

## 4. Cosa era successo

Le assegnazioni erano nel ramo `catch` invece che nel `try`:

- **nel percorso normale** le variabili non venivano mai assegnate
- **nel percorso di errore** si rifacevano le query appena fallite

Il ramo di errore ora **azzera** le variabili invece di ricalcolarle: se il
caricamento principale non è riuscito, rifare le stesse interrogazioni non può
riuscire.

## 5. Perché non l'ho visto prima

Il collaudo interrogava i **metodi del modello**, non il blocco di caricamento
della pagina. I metodi funzionavano, ed è per questo che tutte le quadrature
tornavano.

È il secondo difetto in due release nello stesso punto — il collegamento fra
modello e pagina. Ho aggiunto due controlli che eseguono davvero il blocco di
caricamento.

## 6. Rollback

Ripristinare `it_service.php` dalla v1.9.19 — ma l'avviso tornerebbe.

```sql
UPDATE app_settings SET setting_value='1.9.19'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
