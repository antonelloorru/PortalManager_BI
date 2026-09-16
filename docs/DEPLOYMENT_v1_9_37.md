# Deployment — PortalManager v1.9.37

Da applicare all'installazione in produzione (webroot `P:\xampp\htdocs\portalmanager`).

## Via system_console.php (consigliata)
1. `system_console.php` → tab **Aggiornamento (ZIP)**.
2. Caricare `PortalManager_v1_9_37.zip` → **Analizza** → **Applica**.
   Il backup automatico di file e DB ora non va più in timeout.
3. **Stop + Start Apache**, poi **Ctrl+F5**.

> Nota: `system_console.php` è tra i file aggiornati dal pacchetto. Il fix del
> timeout è attivo dalla release *successiva* a questa applicazione: se il backup
> di *questa* installazione dovesse ancora fermarsi ai 300s, applicare prima il
> solo `system_console.php` (vedi manuale), poi rilanciare l'aggiornamento.

## Via PowerShell + SQL Runner (alternativa)
1. `Expand-Archive PortalManager_v1_9_37.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force`
2. SQL Runner: eseguire `sql/migration_v1_9_37.sql`
   (da versione precedente/incerta: `sql/upgrade_1_9_35_to_1_9_37.sql`).
3. Stop + Start Apache, Ctrl+F5.

## Fallback lato server (se il backup resta lungo)
Il fix rimuove il limite PHP. Se restano timeout a livello web server, verificare
in Apache/FastCGI: `TimeOut`, `ProxyTimeout`, `FcgidIOTimeout` — portarli a un
valore adeguato alla dimensione del DB. Non è richiesto nella maggior parte dei casi.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Footer / Console | Versione 1.9.37 |
| Aggiornamento → Applica su pacchetto grande | backup completato, nessun timeout |
| `SELECT setting_value FROM app_settings WHERE setting_key='schema_version'` | 1.9.37 |
| `SELECT commerciale FROM v_cm_pratix_righe LIMIT 1` | valore da commercial_ref o '(non indicato)' |

## Rollback
Ripristinare `system_console.php` dalla copia di backup creata dall'updater.
Lo schema non richiede rollback (sola ridefinizione vista). Per riportare le etichette:
```sql
UPDATE app_settings SET setting_value='1.9.36'
 WHERE setting_key IN ('app_version','schema_version','release_label');
```
