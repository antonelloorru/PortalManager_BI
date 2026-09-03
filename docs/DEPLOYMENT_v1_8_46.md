# Deployment — PortalManager v1.8.46

## 1. Contenuto del pacchetto

```
VERSION                              1.8.46
sync_commesse.php                    (ROOT)  NUOVA pagina di sincronizzazione
import_commesse_db.php               (ROOT)  invariato da v1.8.45
import_employees_xlsx.php            (ROOT)  invariato da v1.8.43
app/SyncDatasets.php                 NUOVO   registro dei dataset
app/DatasetSync.php                  NUOVO   motore di sincronizzazione
app/MenuManager.php                  voce di menu
app/Router.php                       pagina routabile
app/SourceDb.php                     invariato da v1.8.45
app/CommesseSync.php                 invariato da v1.8.45
app/ListFilter.php                   invariato da v1.8.44
app/EmployeeImportSchema.php         invariato da v1.8.43
app/Version.php                      PM_VERSION = 1.8.46
sql/migration_v1_8_46.sql            permessi, indice, allineamento sorgente
sql/upgrade_1_7_56_to_1_8_46.sql     consolidato cumulativo (325 statement)
docs/                                questa documentazione
```

I file `.php` di primo livello nella radice del portale, quelli di `app/` in
`app\`. `SyncDatasets.php` e `DatasetSync.php` sono **nuovi**: senza di essi
`sync_commesse.php` non funziona.

## 2. Aggiornamento

1. `system_console.php` → tab **Aggiornamento**.
2. Copiare i file rispettando i percorsi.
3. SQL Runner: `sql/migration_v1_8_46.sql` (da v1.8.45) oppure
   `sql/upgrade_1_7_56_to_1_8_46.sql` (da versione precedente o incerta).
4. **Stop + Start Apache**.
5. **Ctrl+F5**.

La migration aggiorna anche la configurazione già salvata, correggendo la tabella
sorgente da `contract` a `forms_contract`.

## 3. Prerequisiti

Gli stessi della v1.8.45: driver PDO per il database del gestionale,
raggiungibilità di rete dal server del portale, utenza **dedicata di sola
lettura**, `.env.php` presente e stabile.

Le tabelle che devono essere leggibili dall'utenza:

```
forms_contract, forms_contract_type, forms_company, forms_place,
forms_activity, forms_activity_has_dgb_operator,
forms_cost_range, forms_tech_sector, dgb_operator
```

## 4. Verifica post-deploy

| Passo | Esito atteso |
|---|---|
| Footer | `1.8.46` |
| Menu Gestione Commesse | compare **Sincronizzazione gestionale** |
| Apertura della pagina | tre riquadri: commesse, rapporti, professionisti |
| Scarica tracciato CSV | file con le intestazioni attese |
| Anteprima dal gestionale (commesse) | righe lette e azioni previste, nessuna scrittura |
| Sincronizza dal gestionale (commesse) | riepilogo con nuove e aggiornate |
| Commesse / Progetti | codici e valori allineati al gestionale |
| Sincronizza rapporti | contatore "agganciate a commessa" maggiore di zero |
| Ripetere la sincronizzazione | nessun duplicato, solo aggiornamenti |

**Ordine consigliato**: prima le commesse, poi i rapporti, infine i
professionisti. I rapporti si agganciano alle commesse per codice, quindi
sincronizzare prima le commesse massimizza gli agganci.

## 5. Se qualcosa non va

**"Colonne obbligatorie assenti"** — la tabella indicata non è quella attesa:
verificare che la configurazione punti a `forms_contract`.

**"Nessuna intestazione riconosciuta"** nel CSV — il file non ha il tracciato
atteso. Scaricare il tracciato dal pulsante e confrontare le intestazioni.

**Contatore "agganciate a commessa" a zero** — le commesse non sono ancora state
sincronizzate, oppure i codici non corrispondono.

**Colonne del file ignorate** — segnalate nel riepilogo. Non è un errore: le
colonne non previste dal tracciato vengono semplicemente saltate.

## 6. Rollback

Rimuovere `sync_commesse.php` e ripristinare `app/MenuManager.php` e
`app/Router.php` dalla copia precedente. Poi:

```sql
DELETE FROM role_permissions WHERE page_name = 'sync_commesse.php';
UPDATE app_settings SET setting_value='1.8.45'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```

Stop + Start Apache, Ctrl+F5. I dati già sincronizzati restano: sono stati scritti
nelle tabelle normali del portale.
