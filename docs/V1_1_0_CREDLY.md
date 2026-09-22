# PortalManager 1.1.0 — Integrazione Credly

## Sintesi

Funzionalità di **importazione e aggiornamento certificazioni** dei dipendenti dai loro profili pubblici Credly.

### Endpoint usato

```
GET https://www.credly.com/users/<username>/badges.json?page=1&page_size=48
```

Endpoint **pubblico** e **non autenticato** che Credly espone per rendering interno dei profili. Non richiede API key (l'API key ufficiale Credly è riservata alle organizzazioni con account business). Funziona da anni ed è stabile.

### Flusso end-to-end

```
Admin                        Portale                     Credly
  │                             │                          │
  │ Inserisce URL profilo      │                          │
  ├─────────────────────────►│                          │
  │                             │ INSERT employee_credly_link
  │                             │                          │
  │ Click "Sync"               │                          │
  ├─────────────────────────►│ GET /users/<u>/badges.json
  │                             ├─────────────────────►│
  │                             │ ◄────────── JSON badges │
  │                             │                          │
  │                             │ Per ogni badge:          │
  │                             │  1. matchBadgeToCertification
  │                             │     - by credly_template_id
  │                             │     - by name+brand exact
  │                             │     - by fuzzy LIKE
  │                             │  2. INSERT/UPDATE user_certifications
  │                             │  3. audit entity_change_log
  │                             │  4. badge senza match → import_staging_rows
  │                             │                          │
  │ Esito sync                 │                          │
  │◄────────────────────────┤                          │
```

## Tabelle coinvolte

### Nuova tabella `employee_credly_link`

| Colonna | Tipo | Note |
|---|---|---|
| `employee_id` | INT PK | FK employees.id |
| `credly_username` | VARCHAR(150) | Slug Credly (es. `lorenzo-buschi`) o UUID |
| `last_sync_at` | DATETIME | Timestamp ultima sync |
| `last_sync_imported` | INT | Contatore nuove cert importate |
| `last_sync_updated` | INT | Contatore cert aggiornate |
| `last_sync_unmatched` | INT | Contatore badge senza match |
| `created_by`, `created_at`, `updated_at` | — | Audit |

### Estensione `certifications`

Nuova colonna `credly_template_id VARCHAR(64)` per mapping diretto con `badge_template.id` di Credly. Si popola automaticamente al primo match per nome+brand riuscito.

## Strategia di matching

L'algoritmo `CredlyImporter::matchBadgeToCertification()` prova in ordine:

| Priorità | Strategia | Affidabilità |
|---|---|---|
| 1 | Esatto su `certifications.credly_template_id` | Massima |
| 2 | Esatto su `certifications.name` + `brands.name` (lowercase, trim) | Alta |
| 3 | Fuzzy `LIKE %name%` AND brand esatto, primo match più corto | Media |

Se nessuna strategia produce match → il badge finisce in `import_staging_rows` con `status='partial'` e `job_type='credly_badges'` per intervento admin.

## Stati possibili per badge importato

| Esito | Significato |
|---|---|
| `imported` | Nuova `user_certifications` creata |
| `updated` | Esistente, modificate date/status |
| `unchanged` | Esistente, nessuna differenza |
| `unmatched` | Nessun match nel catalogo → in staging |
| `error` | Errore tecnico (DB, network) |

## Sicurezza

- **Accesso UI**: Super Admin (1) e HR Director (2)
- **Batch "Sync tutti"**: solo Super Admin
- **Rate limit**: 30 secondi tra batch consecutivi
- **HTTP**: SSL verify peer/host attivo, timeout 20s, follow redirect max 3
- **User-Agent**: identificato come `PortalManager/1.1 (+credly-importer)`
- **Audit completo**: ogni cambiamento in `entity_change_log` con `source='credly'`
- **Logging**: ogni sync in `app_logs`

## Pagina UI (`credly_sync.php`)

Funzioni offerte:
- Elenco dipendenti attivi con stato collegamento Credly
- Modal "Collega" per inserire URL profilo (accetta URL completo o solo username)
- Bottone "Sync" per singolo dipendente
- Bottone "Sync tutti" per batch (Super Admin)
- Dettaglio esito per ogni badge importato
- Vista contatori cert per dipendente

## Cron worker schedulato (opzionale)

Lo script `credly_cron_sync.php` permette sync automatica giornaliera.

### Abilitazione

```sql
UPDATE app_settings SET setting_value = '1' WHERE setting_key = 'credly_auto_sync_cron';
```

### Schedulazione Linux (cron)

```cron
# /etc/cron.d/portalmanager-credly
# Sync giornaliera ore 03:00
0 3 * * * www-data /usr/bin/php /var/www/html/portalbrand/credly_cron_sync.php >> /var/log/portalmanager/credly-cron.log 2>&1
```

### Schedulazione Windows (Task Scheduler)

```powershell
# Da PowerShell come Amministratore
$Action  = New-ScheduledTaskAction `
    -Execute "C:\xampp\php\php.exe" `
    -Argument "C:\xampp\htdocs\portalbrand\credly_cron_sync.php" `
    -WorkingDirectory "C:\xampp\htdocs\portalbrand"

$Trigger = New-ScheduledTaskTrigger -Daily -At 3:00am

$Principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -RunLevel Highest

Register-ScheduledTask `
    -TaskName "PortalManager-CredlySync" `
    -Action $Action `
    -Trigger $Trigger `
    -Principal $Principal `
    -Description "Sincronizzazione giornaliera certificazioni Credly"
```

### Caratteristiche worker

- Esegue **solo da CLI** (rifiuta accesso web)
- Lock file (`uploads/.ratelimit/credly_cron.lock`) → no esecuzioni sovrapposte
- Verifica setting `credly_enabled=1 AND credly_auto_sync_cron=1` prima di partire
- Pausa 500ms tra una sync e l'altra (rispetto verso Credly)
- Esito completo in `app_logs` + stdout

## App settings

| Chiave | Default | Effetto |
|---|---|---|
| `credly_enabled` | `1` | Master switch funzionalità |
| `credly_auto_sync_cron` | `0` | Abilita worker schedulato |
| `credly_match_fuzzy` | `1` | Abilita matching fuzzy step 3 |

## Limitazioni

- **Endpoint non documentato**: Credly potrebbe modificarlo. Funziona stabilmente da anni ma non c'è SLA.
- **Solo badge pubblici**: il dipendente deve avere visibilità pubblica del profilo/badge sul suo account Credly.
- **No webhook real-time**: aggiornamenti via polling (manuale o cron giornaliero).
- **Rate limit Credly**: il worker tiene 0.5s di pausa tra richieste. Per ~100 dipendenti = ~50s totali, accettabile.

## Procedura installazione

```powershell
cd C:\xampp\htdocs\portalbrand
Expand-Archive -Path "PortalManager-1.1.0-credly.zip" -DestinationPath . -Force
```

phpMyAdmin → Importa → `sql/migration_v1_1_0.sql`

Voce **Import Credly** comparirà nel menu sotto "Import massivo".

## Test rapido

1. Login Super Admin → menu *Import Credly*
2. Click *Collega* sul dipendente target
3. Incolla URL profilo (es. `https://www.credly.com/users/lorenzo-buschi/badges`)
4. Salva → click *Sync*
5. Risultato: contatore nuove/aggiornate/da mappare con dettaglio per ogni badge
6. Badge senza match → vai a *Completamento LDB* per mappare manualmente al catalogo

## File pacchetto

- `app/CredlyImporter.php` (NUOVO) — Classe helper fetch + match + import
- `app/Router.php` — Whitelist slug `credly_sync`
- `credly_sync.php` (NUOVO) — UI pagina admin
- `credly_cron_sync.php` (NUOVO) — Worker CLI schedulabile
- `header.php` — Voce menu sotto "Import massivo"
- `sql/migration_v1_1_0.sql` — Schema + RBAC + settings + bump versione
- `docs/V1_1_0_CREDLY.md` — Questa documentazione
