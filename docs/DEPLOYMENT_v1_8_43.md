# Deployment — PortalManager v1.8.43

Ambiente: XAMPP per Windows · PHP 8.2 · Apache 2.4.58 · MariaDB 10.4.32.
Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto del pacchetto

```
VERSION                              1.8.43
import_employees_xlsx.php            (ROOT)  template + tracciato esteso
app/EmployeeImportSchema.php         NUOVO   definizione unica del tracciato
app/ListFilter.php                           export "tutti i record"
app/Version.php                              PM_VERSION = 1.8.43
sql/migration_v1_8_43.sql                    solo bump di versione
sql/upgrade_1_7_56_to_1_8_43.sql             consolidato cumulativo (315 statement)
docs/                                        questa documentazione
```

`import_employees_xlsx.php` va nella radice del portale; gli altri tre file in
`app\`. **`EmployeeImportSchema.php` è un file nuovo**: se manca,
`import_employees_xlsx.php` non funziona.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_43.sql` (da v1.8.42) oppure
   `sql/upgrade_1_7_56_to_1_8_43.sql` (da versione precedente o incerta).
4. **Stop + Start Apache**.
5. **Ctrl+F5** — necessario: le modifiche all'export sono JavaScript, e una
   pagina servita dalla cache mostrerebbe ancora il menu privo della casella.

## 3. Verifica post-deploy

### Export completo

| Passo | Esito atteso |
|---|---|
| Amministrazione → Anagrafica dipendenti | elenco visibile |
| Applicare un filtro qualsiasi | il contatore righe diminuisce |
| Esporta → il menu si apre | in testa compare "Tutti i record (ignora i filtri)" |
| Esportare in CSV **senza** spuntare | il file contiene le sole righe filtrate |
| Spuntare la casella ed esportare | il file contiene l'intero elenco, nome con suffisso `_completo` |

### Template e import

| Passo | Esito atteso |
|---|---|
| Amministrazione → Import dipendenti XLSX | il riquadro elenca 32 colonne (30 senza permesso Compensation) |
| **Scarica template XLSX** | file `template_import_dipendenti.xlsx`, apribile in Excel |
| Fogli del template | "Dipendenti" (intestazioni + riga di esempio) e "Istruzioni" |
| **Scarica template CSV** | stesse intestazioni, separatore `;`, accenti corretti |
| Compilare il template e ricaricarlo | l'anteprima riconosce le colonne e mostra le righe |
| Confermare l'import | conteggio di nuovi e aggiornati coerente |

Prova consigliata su un solo record prima di un caricamento massivo: scaricare il
template, compilare una riga con un codice fiscale già presente, importare e
verificare che i campi compilati risultino aggiornati e gli altri invariati.

## 4. Nota sui file dei tracciati precedenti

I file già in uso continuano a funzionare senza modifiche: le vecchie
intestazioni sono riconosciute come sinonimi. Non è necessario riconvertire nulla.

## 5. Rollback

Ripristinare i file dalla copia precedente — `app/EmployeeImportSchema.php` può
restare, è inerte senza il file che lo include — e riportare l'etichetta:

```sql
UPDATE app_settings SET setting_value='1.8.42'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Stop + Start Apache, Ctrl+F5.
