# Deployment — PortalManager v1.8.98

**Correttiva**: una riga in un file.

## 1. Contenuto

```
VERSION                          1.8.98
project_dashboard.php            (ROOT)  correzione del form di ricerca
app/Version.php                  PM_VERSION = 1.8.98
gli altri file                   invariati da v1.8.97
sql/migration_v1_8_98.sql        solo bump di versione
sql/upgrade_1_7_56_to_1_8_98.sql consolidato cumulativo
docs/                            questa documentazione
```

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare **`project_dashboard.php` in ROOT** e `app/Version.php`.
3. SQL Runner: `sql/migration_v1_8_98.sql` (da v1.8.97) oppure il consolidato.
4. **Stop + Start Apache**, **Ctrl+F5**.

Sono sufficienti i due file: la migration è un semplice allineamento di versione.

## 3. Verifica

**Commesse / Progetti** → aprire una commessa → tab **Consuntivo** → digitare
qualcosa nel campo **Cerca** → premere Cerca.

La pagina deve **ricaricarsi sulla stessa scheda** con i risultati filtrati,
invece di rispondere «pagina non trovata».

Provare anche gli altri filtri della stessa barra — Approvato, tecnico — che
usano lo stesso form.

## 4. Cosa era successo

Il form di ricerca inviava `id` e `q` ma non lo slug della pagina. L'URL risultante
non diceva al router quale pagina aprire.

È lo stesso genere di difetto della v1.8.85 su `service_desk.php`: lì il metodo
invocato non esisteva, qui la riga mancava del tutto. Il controllo introdotto
allora cercava le chiamate sbagliate e non poteva vedere una riga assente.

Ora verifico **ogni form GET** della release: `project_dashboard.php` era l'unico
difettoso.

## 5. Nota sulla verifica

Il database sandbox non era disponibile durante la preparazione: **i test SQL su
database reale non sono stati rieseguiti**.

La migration contiene una sola istruzione — l'aggiornamento della versione in
`app_settings` — identica a quelle delle release precedenti già collaudate.

Verificati comunque: `php -l` su tutti i file, zero form GET privi di slug, zero
`;` nei commenti SQL, zero istruzioni DDL.

## 6. Rollback

Ripristinare `project_dashboard.php` dalla v1.8.97 — ma il difetto tornerebbe.

```sql
UPDATE app_settings SET setting_value='1.8.97'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
