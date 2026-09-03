# Deployment manuale — v1.7.84

Release **cumulativa 1.7.56 → 1.7.84**, in **fase unica**: lo ZIP contiene i file PHP completi
già patchati e **un unico script SQL nella root** (`upgrade_1_7_56_to_1_7_84.sql`), idempotente.
Supporta l'aggiornamento partendo da 1.7.56, 1.7.57, 1.7.58 o da una 1.7.59 pre-fix.

## A) Aggiornamento tramite `system_update.php` (consigliato)
1. Login come **Super Admin**.
2. Aprire **Impostazioni di sistema → Aggiornamento** (`system_update.php`).
3. Caricare `portalmanager_v1.7.84.zip`, avviare **Analizza** poi **Applica**.
   - Lo ZIP mantiene la gerarchia (`app/`, `sql/`, `docs/`); i file protetti (Config, .htaccess,
     uploads/) non vengono toccati.
   - Lo script esegue automaticamente `upgrade_1_7_56_to_1_7_84.sql` (unico SQL in root): idempotente. Sul DB già a
     1.7.58 gli statement "Duplicate/exists" sono ignorati.
4. **Riavviare Apache** (Stop+Start da XAMPP) per invalidare l'OPcache PHP.
5. Nel browser: **Ctrl+F5** (hard refresh).

## B) Aggiornamento manuale
1. **Backup** completo di file e database.
2. Estrarre lo ZIP nella root del portale (`P:\xampp\htdocs\portalmanager`), sovrascrivendo.
   PowerShell:
   ```powershell
   Expand-Archive -Path .\portalmanager_v1.7.84.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force
   ```
3. Eseguire la migration via **SQL Runner** o phpMyAdmin:
   `sql/migration_v1_7_56_to_1_7_84.sql` (consolidato idempotente; ri-eseguibile senza errori).
4. Verificare `VERSION` = `1.7.59`; al primo caricamento `app_settings.app_version` si allinea.
5. **Stop+Start Apache** + **Ctrl+F5**.

## Verifica post-deploy
- Menu **Gestione Commesse** visibile (Super Admin).
- `SELECT COUNT(*) FROM cm_company_prefix_map;` → 6 (WTS,NIS,ANT,MIPS,WEN,WEE).
- Aziende `Wenest SRL` (Marcon) e `Weenergy` (Montevarchi) presenti.
- `SELECT COUNT(*) FROM cm_rate_band_rates;` → 36.
- Modulo *Progetti & Referenze* invariato: `SELECT COUNT(*) FROM projects;` invariato rispetto al pre-upgrade.
- Pagina **Commesse / Progetti** si apre senza errori.
- Import di prova commesse e rapporti: nessun duplicato al reimport.

## Rollback
Ripristinare il backup file + database precedenti. Le nuove tabelle sono additive: in caso di
sospensione è sufficiente rimuovere la voce di menu (permessi) senza perdita di dati esistenti.


## Nota sulle tabelle rimosse
Il consolidato esegue `DROP TABLE IF EXISTS` su `intervention_reports`, `project_team`,
`project_presales_effort`, `intervention_import_batches`, `hourly_rate_band_rates`,
`hourly_rate_band_history`, `hourly_rate_bands`, `company_prefix_map`. Sono tabelle introdotte
dalla 1.7.59 pre-fix e mai operative (il modulo andava in errore per collisione con `projects`):
non contengono dati. Le tabelle `projects` e `clients` dei moduli preesistenti **non** vengono
toccate.

## Verifica del versionamento (v1.7.84)
Dopo l'update, in *Amministrazione → Impostazioni* o via query:
```sql
SELECT setting_key, setting_value FROM app_settings
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
Attese: tutte e tre a **1.7.61** (una `release_label` personalizzata, non semver, viene
volutamente preservata).

Se una chiave risultasse indietro, **è sufficiente aprire una pagina qualsiasi del portale**:
dalla 1.7.61 `app/bootstrap.php` esegue l'auto-allineamento a `PM_VERSION` (una volta per
sessione). Non serve rieseguire l'SQL.

### Perché prima non si aggiornava
- L'auto-bump era demandato a `Config.php`, che è un **file protetto** e non viene mai
  sovrascritto dai pacchetti: la chiamata non è mai arrivata sulle installazioni (dalla v1.7.16).
- `system_update.php` aggiornava **solo `app_version`**, lasciando `schema_version` e
  `release_label` alla release precedente (stato osservato: 1.7.58 / 1.7.57 / 1.7.57).
- Entrambi i difetti sono corretti in 1.7.61.

## Limiti PHP per gli import XLSX (v1.7.84)
Il modulo legge gli XLSX in **streaming**: la dimensione del file non incide sulla memoria
(150.000 righe importate con picco di 2 MB). Restano però i limiti di PHP sul **caricamento**
del file, da alzare in `P:\xampp\php\php.ini`:

```ini
upload_max_filesize = 256M
post_max_size       = 300M   ; deve essere > upload_max_filesize
memory_limit        = 512M
max_execution_time  = 900
max_input_time      = 900
```
Poi **Stop + Start Apache** (il reload non basta).

### Perché un file troppo grande dava "403 — Token di sicurezza non valido"
Superato `post_max_size`, PHP **scarta l'intero body**: `$_POST` e `$_FILES` arrivano vuoti,
quindi manca il token CSRF e la verifica risponde 403, nascondendo la causa reale.
Dalla 1.7.62 `app/UploadGuard.php` intercetta la condizione **prima** di `Csrf::verify()` e
mostra dimensione inviata, limite attivo e come rimediare. I form di import indicano il limite
corrente. In alternativa all'aumento dei limiti, l'export può essere suddiviso: l'import è
UPSERT (su `report_code` / `codice_commessa`), quindi i file parziali si sommano senza duplicati.

## Versionamento: perché il DB regrediva a 1.7.57 (corretto in 1.7.63)
Due difetti concorrenti, entrambi in codice preesistente:

1. **`db_upgrade.php` — ordine versioni hardcoded fermo a `'1.7.57'`.** Le release successive
   erano sconosciute all'elenco: non entravano mai in `$to_apply`, e il target di default era
   letteralmente `'1.7.57'`, valore poi scritto in `app_settings.app_version`. Eseguendo
   l'upgrade a 1.7.61 il DB **regrediva a 1.7.57**. Una seconda lista hardcoded guidava un
   auto-bump con lo stesso effetto.
   → Dalla 1.7.63 l'ordine è **auto-manutenuto** (`pm_version_order()`): la parte storica resta
   esplicita (dopo la v5.9 il versioning è ripartito da 1.0.0, cosa che `version_compare()` non
   può sapere), mentre ogni nuova release 1.7.x viene accodata automaticamente. Aggiunte una
   **guardia anti-regressione** (mai scrivere una versione precedente a quella a DB, mai
   superare `PM_VERSION`) e l'allineamento di tutte e tre le chiavi.

2. **`system_update.php` — splitter SQL che scartava i chunk iniziati da commento.** Con
   `explode(';')`, il blocco di commenti che precede un'istruzione fa parte dello stesso chunk:
   la regola "salta se inizia con `--`" eliminava l'istruzione stessa, in silenzio.
   Misurato sui file reali: `migration_v1_7_61.sql` → **0 istruzioni su 1** eseguite;
   `migration_v1_7_58.sql` → **15 su 20** (5 perse, tra cui il bump di versione).
   → Ora i commenti vengono rimossi e si salta solo se non resta SQL.

Gli script `upgrade_*.sql` di root sono, per scelta, privi di commenti a livello di istruzione:
restano quindi eseguibili anche dagli esecutori non ancora aggiornati (40/40 istruzioni).

## Ere di versionamento (fix 1.7.64)
Il portale ha **due ere** di numerazione: la serie **2.x / 4.x / 5.x** (certV) e, dopo la rinomina
in PortalManager, la serie **1.x** ripartita da 1.0.0. Quindi **1.0.0 è successivo a 5.9**, e
`version_compare()` da solo sbaglia sempre il confronto a cavallo delle due ere.

Difetto corretto: l'ordine auto-manutenuto introdotto in 1.7.63 accodava ogni versione sconosciuta
ordinandola con `version_compare()`. Le chiavi storiche `2.0`/`2.1` presenti in `$VERSIONS` finivano
così **dopo** le 1.7.x, diventando "ultima versione": l'auto-bump portava `app_version` a **2.1**.

Dalla 1.7.64:
- `pm_version_order()` classifica per era: tutto ciò che non è `1.x` appartiene all'era precedente e
  viene collocato **prima** di `1.0.0`; dentro ciascuna era `version_compare()` è affidabile.
- L'auto-bump non può superare `PM_VERSION` né regredire rispetto al DB.
- `app/Version.php` è consapevole delle ere: un DB fermo su `2.x` viene riconosciuto come più
  vecchio del codice e **si autoripara** all'apertura di una pagina qualsiasi.
- La migration riscrive comunque le tre chiavi al valore corretto.

Verifica:
```sql
SELECT setting_key, setting_value FROM app_settings
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
Attese: tutte a **1.7.64**.
