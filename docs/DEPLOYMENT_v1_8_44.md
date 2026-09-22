# Deployment — PortalManager v1.8.44

Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto del pacchetto

```
VERSION                              1.8.44
app/ListFilter.php                   CORRETTO  export e filtri su tutte le righe
import_employees_xlsx.php            (ROOT)    invariato da v1.8.43
app/EmployeeImportSchema.php                   invariato da v1.8.43
app/Version.php                                PM_VERSION = 1.8.44
sql/migration_v1_8_44.sql                      solo bump di versione
sql/upgrade_1_7_56_to_1_8_44.sql               consolidato cumulativo (316 statement)
docs/                                          questa documentazione
```

Il pacchetto è cumulativo: include anche i file della v1.8.43, invariati.
Sovrascriverli è innocuo se quella release è già stata applicata.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. `import_employees_xlsx.php` nella radice; gli altri tre file in `app\`.
3. SQL Runner: `sql/migration_v1_8_44.sql` (da v1.8.43) oppure
   `sql/upgrade_1_7_56_to_1_8_44.sql` (da versione precedente o incerta).
4. **Stop + Start Apache**.
5. **Ctrl+F5** — indispensabile: la correzione è JavaScript dentro
   `ListFilter.php`, e una pagina servita dalla cache continuerebbe a esportare
   25 righe anche ad aggiornamento riuscito.

## 3. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Amministrazione → Anagrafica dipendenti | in fondo alla tabella la paginazione mostra il totale, es. "1 di 12 pagine" |
| Esporta → spuntare "Tutti i record" → CSV | il file contiene **tutti** i dipendenti, non 25 |
| Contare le righe del file scaricato | pari al totale dell'anagrafica, più la riga di intestazione |
| Esportare **senza** spuntare la casella | solo le righe che superano i filtri, di nuovo su tutte le pagine e non solo sulla prima |
| Passare a pagina 2 ed esportare | il risultato non cambia: l'export non dipende dalla pagina visualizzata |

La correzione è nel componente, quindi vale anche per gli altri elenchi paginati:
Utenti, Tecnologie brand, Catalogo certificazioni, Documenti, Candidati,
Contratti recruiting, Report certificazioni, Log di sistema, Storico.

## 4. Rollback

Ripristinare `app/ListFilter.php` dalla copia precedente e riportare l'etichetta:

```sql
UPDATE app_settings SET setting_value='1.8.43'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Stop + Start Apache, Ctrl+F5.
