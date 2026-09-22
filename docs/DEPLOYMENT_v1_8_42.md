# Deployment — PortalManager v1.8.42

Ambiente: XAMPP per Windows · PHP 8.2 · Apache 2.4.58 · MariaDB 10.4.32.
Release **solo applicativa**: nessuna variazione di schema né di dati.

## 1. Contenuto del pacchetto

```
VERSION                              1.8.42
timesheet.php                        (ROOT)  campo di rotta nei filtri
project_gantt.php                    (ROOT)  idem (parte da v1.8.40)
professionals.php                    (ROOT)  idem
export_employees.php                 (ROOT)  idem
project_dashboard.php                (ROOT)  idem (ricerca rapporti)
recruiting_posizioni.php             (ROOT)  idem
app/Version.php                              PM_VERSION = 1.8.42
sql/migration_v1_8_42.sql                    solo bump di versione
sql/upgrade_1_7_56_to_1_8_42.sql             consolidato cumulativo (314 statement)
docs/                                        questa documentazione
```

Tutti i file PHP vanno nella **radice** del portale, tranne `Version.php` in `app\`.
Sono completi e già patchati: si sovrascrivono, non si applicano diff.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Sovrascrivere i sei file di ROOT e `app\Version.php`.
3. SQL Runner: `sql/migration_v1_8_42.sql` (da v1.8.41) oppure
   `sql/upgrade_1_7_56_to_1_8_42.sql` (da versione precedente o incerta).
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

Il passo 5 non è formale: i file corretti cambiano solo l'HTML dei form, e una
pagina servita dalla cache del browser continuerebbe a mostrare il form vecchio,
riproducendo il 404 anche ad aggiornamento riuscito.

## 3. Verifica post-deploy

| Pagina | Azione | Esito atteso |
|---|---|---|
| Gestione Commesse → **Timesheet** | impostare un mese e premere il pulsante dei filtri | la pagina si ricarica filtrata |
| Gestione Commesse → **Gantt commesse** | applicare un filtro | idem |
| Gestione Commesse → **Anagrafica Professionisti** | cercare un nome | idem |
| Gestione Commesse → scheda commessa | ricerca nei rapporti di intervento | idem |
| Anagrafica → **Export dipendenti** | filtrare per azienda | idem |
| Recruiting → **Posizioni** | filtrare per stato | idem |
| Footer | — | `1.8.42` |

Se una di queste dà ancora "pagina non trovata", il file corrispondente non è
stato sovrascritto oppure la pagina proviene dalla cache: ripetere Ctrl+F5.

Controllo tecnico rapido: nel sorgente HTML della pagina, dentro il form dei
filtri deve comparire `<input type="hidden" name="r" value="...">`.

## 4. Rollback

Ripristinare i sette file dalla copia precedente e riportare l'etichetta:

```sql
UPDATE app_settings SET setting_value='1.8.41'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Stop + Start Apache, Ctrl+F5.
