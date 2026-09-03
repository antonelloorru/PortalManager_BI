# certV 5.5.0 — Documentazione tecnica

## Riepilogo modifiche

| # | Area | Componente |
|---|---|---|
| 1 | Navigation | Menu "Catalogo certificazioni" gerarchico con sotto-voci per Brand |
| 2 | Schema DB | `certifications.notes` (TEXT) — nuovo campo |
| 3 | Data Ingestion | Workflow asincrono (queue + worker) |
| 3 | Data Ingestion | Late Data Binding (LDB): bypass campi mancanti |
| 3 | Data Ingestion | UI completamento manuale post-import |

---

## 1. Filtro brand gerarchico nel menu

### Caratteristiche

- Voce "Catalogo certificazioni" diventa espandibile (chevron animata)
- Caricamento dinamico dei brand attivi che hanno almeno 1 cert attiva
- Click sul brand → filtra il catalogo via `?f_br=ID` (passa per Router opaco)
- Counter sul brand mostra il numero di certificazioni attive
- "Tutti i brand" come prima sotto-voce per resettare il filtro
- Stato "active" propagato automaticamente al brand selezionato

### Implementazione

`header.php` carica all'inizio:
```php
$nav_brands_sidebar = $pdo->query("
    SELECT b.id, b.name,
           (SELECT COUNT(*) FROM certifications c
             WHERE c.brand_id = b.id AND c.is_active = 1) AS cert_count
      FROM brands b
     WHERE b.is_active = 1
       AND EXISTS (SELECT 1 FROM certifications c2
                    WHERE c2.brand_id = b.id AND c2.is_active = 1)
     ORDER BY b.name
")->fetchAll();
```

Voce menu HTML (semplificato):
```html
<li class="has-sub open">
  <a href="catalogo_certificazioni" class="...">
    Catalogo certificazioni
    <i class="fa-chevron-down chev"></i>
  </a>
  <ul class="submenu">
    <li><a href=".../?f_br=0">Tutti i brand</a></li>
    <li><a href=".../?f_br=3">Cisco <span class="brand-count">15</span></a></li>
    ...
  </ul>
</li>
```

Toggle chevron via JavaScript inline (`onclick` sul tag `<a>` parent), CSS gestisce rotazione `transform: rotate(180deg)`.

---

## 2. Estensione `certifications`

### ALTER TABLE

```sql
ALTER TABLE `certifications`
  ADD COLUMN `notes` TEXT DEFAULT NULL
  COMMENT 'Note interne libere'
  AFTER `description`;
```

`description` era già presente; aggiungiamo solo `notes`. Migrazione con check IDEMPOTENTE su `information_schema.COLUMNS`.

### Differenza semantica

| Campo | Visibilità | Uso |
|---|---|---|
| `description` | Pubblica | Descrizione formale, prerequisiti, ambito (visibile in dropdown e PDF) |
| `notes` | Solo HR/Admin interno | Annotazioni operative: fornitori, esperienze pregresse, vendor di riferimento |

### UI

Modal in `catalogo_certificazioni.php` ha ora due textarea separate. Il campo `notes` è etichettato esplicitamente "Note interne (non pubbliche, solo HR)".

---

## 3. Data Ingestion — Workflow async + LDB

### 3.1 Modello dati esteso

#### `import_jobs` — nuovi campi

| Campo | Tipo | Scopo |
|---|---|---|
| `status` enum | esteso | aggiunti: `queued`, `processing`, `partial_lds` |
| `allow_partial` | TINYINT(1) | 1 = consenti record con campi mancanti |
| `queued_at` | DATETIME | quando il job è stato accodato |
| `processed_at` | DATETIME | quando il worker ha iniziato il commit |
| `processed_by` | INT | chi ha avviato il commit |
| `partial_rows` | INT | conta record importati con LDB |

#### `import_staging_rows` — nuovi campi

| Campo | Tipo | Scopo |
|---|---|---|
| `status` enum | esteso | aggiunto `partial` |
| `is_partial` | TINYINT(1) | flag: 1 se importata con LDB attivo |
| `missing_fields` | JSON | array dei campi obbligatori vuoti, da completare |

#### `import_partial_completions` — nuova tabella

Audit log dei completamenti manuali post-LDB:

| Campo | Tipo | Scopo |
|---|---|---|
| `id` | INT PK | |
| `staging_id` | INT FK | riferimento a `import_staging_rows` |
| `target_table` | VARCHAR(60) | tabella reale aggiornata (es. `employees`) |
| `target_id` | INT | ID record nella tabella reale |
| `field_name` | VARCHAR(80) | campo completato |
| `old_value` | TEXT | valore prima (NULL se prima compilazione) |
| `new_value` | TEXT | valore inserito |
| `completed_at` | DATETIME | quando |
| `completed_by` | INT FK users | chi |

### 3.2 Diagramma ER

```
       users ─────┐
                  │ (created_by, processed_by)
                  ↓
      ┌─── import_jobs (status, allow_partial, totals, dates)
      │ N
      │ ON DELETE CASCADE
      ↓ 1
   import_staging_rows (payload, missing_fields, is_partial, result_id)
      │ N
      │ ON DELETE CASCADE
      ↓ 1
   import_partial_completions (target_table, target_id, field, old/new)
                                                       ↓
                                              completed_by → users
```

### 3.3 State machine job

```
   uploaded
      │
      │ validateJob()
      ↓
   validated ─────────→ queued ──────→ processing
                          (async)         │
                                          ↓ commitJob()
                                    ┌─────┴─────┐
                                    ↓           ↓
                                imported    partial
                                            (errori parziali)
                                            partial_lds
                                            (importate ma con LDB)
                                            ↓
                                      [UI completamento manuale]
                                            ↓
                                    [tutti completati →
                                     is_partial=0 sulle righe]
```

### 3.4 State machine staging row

```
   pending
      │ validateRow() o validateRowPartial()
      ↓
   ┌──┴──┬──────┬────────┐
   ↓     ↓      ↓        ↓
 valid invalid partial   skipped
   │      │      │
   │      │ correct │
   │      ↓      │  manuale
   │  corrected  │  (LDB)
   │      │      │
   └──────┴──────┘
          │ commitJob()
          ↓
       imported (is_partial=1 se da partial)
          │
          │ completePartialField() multiplo
          ↓
       imported (is_partial=0, missing_fields=NULL)
```

### 3.5 Late Data Binding — flusso completo

**Esempio**: import 1.000 dipendenti, 200 senza `date_of_birth`.

#### Modalità classica (allow_partial = 0)

- 200 righe → status `invalid` (errore "campo obbligatorio mancante")
- 800 righe → status `valid`
- Commit: 800 importate, 200 in staging come errori

#### Modalità LDB (allow_partial = 1)

- 200 righe → status `partial`, `missing_fields = ["date_of_birth"]`
- 800 righe → status `valid`
- Commit con LDB attivo: **1.000 righe importate**
  - 800 con `is_partial = 0`
  - 200 con `is_partial = 1`, `result_id` valorizzato (record reale creato in `employees`)
- Job status → `partial_lds`
- L'utente apre **Completamento LDB** → vede 200 record con missing_fields
- Per ogni record: form di completamento singolo campo
  - `completePartialField()` valida il valore, fa UPDATE su `employees.date_of_birth`, rimuove il campo da `missing_fields`, salva audit
  - Quando `missing_fields` diventa vuoto → `is_partial = 0` → record sparisce dalla lista LDB

### 3.6 Workflow asincrono

#### Componenti

1. **`mass_upload.php`** — checkbox "Async (accoda)"
   - Se attiva: chiama `enqueueJob()` invece di mostrare review
   - Job passa a status `queued`, ritorna alla home

2. **`cron_import_worker.php`** — worker CLI
   - Schedulato ogni 1-5 min via Task Scheduler Windows
   - Acquisisce 1 job alla volta con lock atomico (`UPDATE ... WHERE status='queued' LIMIT 1` + `rowCount()` check)
   - Esegue `commitJob()`
   - Limiti: max 5 job per run, max 4 minuti di runtime
   - In caso di errore: riporta a `queued` con timestamp in `notes` per retry

3. **`mass_upload_jobs.php`** — UI esistente, mostra anche stati `queued`, `processing`, `partial_lds`

#### Configurazione Task Scheduler (Windows)

```cmd
schtasks /Create /TN "certV Import Worker" /TR "php D:\portalbrand\cron_import_worker.php >> D:\portalbrand\logs\worker.log 2>&1" /SC MINUTE /MO 5 /RU "SYSTEM"
```

Esegue ogni 5 minuti come SYSTEM. Output redirect a `logs\worker.log` per debug.

#### Lock concorrente

Il worker usa `SELECT ... FOR UPDATE` dentro una transazione, poi `UPDATE WHERE status = 'queued'` con check di `rowCount()`. Se due worker concorrenti tentano lo stesso job, solo uno avrà rowCount=1; l'altro vede 0 e passa al prossimo. Garantisce **at-most-once execution**.

### 3.7 API metodi nuovi

#### `ImportValidator::validateRowPartial(array $row): array`

```php
return [
    'valid'          => bool,    // true se non ci sono errori di tipo/formato
    'errors'         => array,   // errori sui campi presenti
    'missing_fields' => array,   // campi obbligatori mancanti (NON errori)
    'normalized'     => array,   // dati normalizzati con FK risolti dove possibile
];
```

I campi richiesti vuoti **non sono errori** ma vengono solo elencati. I campi presenti malformati restano errori bloccanti.

#### `ImportProcessor::enqueueJob(int $jobId): bool`

Mette il job in coda con status `queued` e timestamp `queued_at`.

#### `ImportProcessor::completePartialField(int $stagingId, string $field, $value, ?int $userId): array`

```php
return [
    'ok'        => bool,
    'completed' => bool,    // true se TUTTI i missing sono ora compilati
    'remaining' => int,     // campi ancora mancanti
];
```

Operazioni atomiche dentro transazione:
1. Validazione singolo campo (riusa schema completo)
2. UPDATE record reale nella tabella target
3. Aggiorna `missing_fields` rimuovendo il campo
4. Aggiorna `payload` con il nuovo valore
5. Inserisce riga in `import_partial_completions` per audit
6. Se missing diventa vuoto: `is_partial = 0`

Se uno step fallisce, rollback completo.

#### `ImportProcessor::listPartialRecords(PDO, ?string $type, ?int $userId, int $limit): array`

Lista record con LDB attivo per la pagina di completamento. Filtri opzionali per tipo import e proprietario del job.

### 3.8 Sicurezza LDB

- `completePartialField()` rifiuta campi non in `missing_fields` (no injection)
- Validazione singolo campo riusa lo stesso schema dello import (tipo, FK, enum, max_length)
- `target_table` derivato da mapping interno hardcoded (`getTargetTable()`), no input utente
- Tutti gli UPDATE usano prepared statements
- Nome colonna in UPDATE: gestito dallo schema (è `fk_target` o `field`), validato in `getFullSchema()`
- CSRF su tutti i form
- Permessi: solo ruolo 1-2

---

## File del pacchetto

| File | Tipo | Ruolo |
|---|---|---|
| `sql/migration_v5_5.sql` | SQL | Migrazione idempotente |
| `app/ImportValidator.php` | PHP class | + `validateRowPartial()` |
| `app/ImportProcessor.php` | PHP class | + `enqueueJob()`, `completePartialField()`, `listPartialRecords()`, `getTargetTable()` |
| `app/Router.php` | PHP class | Whitelist + `mass_upload_partials` |
| `header.php` | PHP/HTML | Menu gerarchico Catalogo + voce Completamento LDB |
| `catalogo_certificazioni.php` | PHP/HTML | Campo `notes` nel modal e nel CRUD |
| `mass_upload.php` | PHP/HTML | Toggle Async + Allow partial |
| `mass_upload_review.php` | PHP/HTML | Stato `partial` + commit con bypass |
| `mass_upload_partials.php` | PHP/HTML | UI completamento LDB (NEW) |
| `cron_import_worker.php` | PHP CLI | Worker async per coda |
| `docs/V5_5_TECHNICAL.md` | MD | Questa documentazione |
