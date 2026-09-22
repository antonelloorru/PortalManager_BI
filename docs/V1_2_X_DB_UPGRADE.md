# PortalManager — db_upgrade.php aggiornato (1.2.0)

## Sintesi

Il file `db_upgrade.php` è stato esteso per supportare tutte le versioni rilasciate dopo la **v2.4** (dove era fermo):

| Range | Versioni coperte |
|---|---|
| **certV legacy** | 2.0 → 2.4 → 4.0 |
| **certV evoluzioni** | 4.1 → 5.0 → 5.4 → 5.5 → 5.7 → 5.8 → 5.9 |
| **PortalManager** | 1.0.0 → 1.0.1 → 1.1.0 → 1.1.3 → 1.2.0 |

Totale: **16 blocchi di upgrade** SQL idempotenti, eseguibili in ordine cronologico.

## Cosa cambia rispetto alla versione precedente

### 1. Rebranding
Titolo, header, footer aggiornati a "PortalManager" mantenendo `legacy_codename='certV'` come dato storico nel DB.

### 2. Detection versione corrente esteso

```
N/A                                     → users non esiste
2.0 → 2.4                                → cascata di controlli colonne legacy
4.0                                      → role_permissions ha can_view ma manca user_2fa
4.1                                      → user_2fa esiste ma manca positions_expected
5.0                                      → positions_expected ma manca clients
5.4                                      → clients ma manca import_jobs
5.5                                      → import_jobs ma manca tech_categories
5.7                                      → tech_categories ma manca enum_proposals
5.8/5.9                                  → enum_proposals ma manca employee_credly_link / credly_template_id
1.1.0                                    → credly ma manca credly_auto_create_catalog
1.1.3                                    → credly_auto ma manca employee_linkedin_link
1.2.0                                    → tutto presente (target stabile)
```

Auto-recovery: se `app_settings.app_version` è valorizzato, viene usato direttamente; altrimenti si applica la cascata euristica.

### 3. Ordinamento versioni custom

⚠ **Problema importante risolto**: `version_compare()` di PHP considera `1.0.0` < `5.9` (perché lessicograficamente "1" < "5"), ma nel nostro caso `1.0.0` è la release **SUCCESSIVA** a `5.9` (rebrand). 

Per gestire questo, il file ora usa un ordinamento esplicito basato su array di indici:

```php
$version_order = [
    '2.2','2.3','2.4',
    '4.0','4.1',
    '5.0','5.4','5.5','5.7','5.8','5.9',
    '1.0.0','1.0.1','1.1.0','1.1.3','1.2.0'
];
$cmp = function($a, $b) use ($version_index) {
    return ($version_index[$a] ?? PHP_INT_MAX) - ($version_index[$b] ?? PHP_INT_MAX);
};
```

Tutti i confronti interni usano `$cmp()` invece di `version_compare()`.

### 4. Blocchi SQL nuovi

| Versione | Cosa fa |
|---|---|
| **4.1** | Crea `user_2fa`, `user_2fa_recovery_codes`, `user_2fa_attempts` + setting `mfa_enforced` |
| **5.0** | Crea `positions_expected` per posizioni multi-figura |
| **5.4** | Crea `clients`, `position_clients`, `branding_settings` |
| **5.5** | Crea `import_jobs`, `import_staging_rows`, `import_partial_completions` (workflow LDB) |
| **5.7** | Crea `tech_categories`, `tech_brands`, `tech_certifications`, `tech_user_certifications`, `tech_employee_skills`, `employee_skills`, `entity_change_log`. Estende `technologies` con `category_id, slug, icon, color, is_active` |
| **5.8** | Crea `enum_proposals` |
| **5.9** | Permessi RBAC per `system_backup.php` |
| **1.0.0** | Setting `app_name='PortalManager'`, bump `app_version` e `schema_version` a `1.0.0`, registra `legacy_codename='certV'` |
| **1.0.1** | Indice `idx_uc_cert_code` su `user_certifications` (autofill api_cert_codes) + permessi `api_cert_codes.php` per tutti i ruoli 1-7 |
| **1.1.0** | Crea `employee_credly_link`, aggiunge `certifications.credly_template_id` + indice, permessi `credly_sync.php` per ruoli 1-2 |
| **1.1.3** | Setting `credly_auto_create_catalog=1` |
| **1.2.0** | Crea `employee_linkedin_link`, settings LinkedIn, permessi `linkedin_sync.php` per ruoli 1-2 |

Tutti i blocchi sono **idempotenti**:
- `CREATE TABLE IF NOT EXISTS`
- `ALTER TABLE ADD COLUMN IF NOT EXISTS` dove supportato da MariaDB
- `CREATE INDEX` con check preventivo via `information_schema`
- `INSERT IGNORE` su tabelle con chiavi univoche
- `ON DUPLICATE KEY UPDATE` su `app_settings`

## Procedura

1. Estrai il pacchetto in webroot (sovrascrive `db_upgrade.php`)
2. Apri nel browser: `http://192.168.230.128/portalbrand/db_upgrade.php`
3. Il report mostra:
   - Versione corrente rilevata
   - Per ogni versione `2.0 → 1.2.0`: elenco di tabelle, colonne, indici, settings, permessi attesi vs presenti
   - Bottone **Applica upgrade necessari** se mancano elementi
4. Click **Applica** → esegue solo i blocchi `$UPGRADE_SQL[v]` necessari (idempotenti, sicuri da rieseguire)
5. Al termine: schermata di successo con badge "DB AGGIORNATO" e versione finale `1.2.0`

## Sicurezza

- File da rimuovere o proteggere via `.htaccess` dopo l'uso in produzione (best practice del progetto originale, invariata)
- Tutti i blocchi sono solo INSERT/CREATE/ALTER → nessun DROP distruttivo
- Backup automatico SQL prima dell'applicazione (funzionalità preesistente, mantenuta)

## Test

Lint PHP OK. Tutti i 16 blocchi SQL parsabili. Detection corretta per ogni stato intermedio del DB.
