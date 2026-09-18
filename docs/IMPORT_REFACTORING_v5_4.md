# certV 5.4.0 — Refactoring Importazione Massiva

## Obiettivi raggiunti

- ✅ **Validazione** pre-import dichiarativa (tipo, formato, vincoli FK)
- ✅ **Staging errori** in tabella dedicata `import_staging_rows` (record bloccati con motivo per campo)
- ✅ **UI/UX** griglia editabile inline per correzione manuale + ri-validazione + commit
- ✅ **Logica atomica** con storicizzazione completa in `import_jobs`

## Flusso utente

```
[Upload CSV]
      ↓
mass_upload.php  (form per tipo)
      ↓
ImportProcessor::createJob()       → INSERT import_jobs (status='uploaded')
      ↓                               INSERT N × import_staging_rows (status='pending')
ImportProcessor::validateJob()     → ImportValidator.validateRow() per ogni riga
      ↓                               UPDATE staging (status='valid'|'invalid', errors=JSON)
                                       UPDATE jobs (status='validated', valid_rows, invalid_rows)
      ↓
mass_upload_review.php  (griglia editabile)
      ↓
       [Utente corregge inline]      → ImportProcessor::updateStagingRow()
                                        → ImportValidator.validateRow() del nuovo payload
                                        → UPDATE staging (status='corrected'|'invalid')
                                        → recalcJobStats()
      ↓
       [Bottone Commit]              → ImportProcessor::commitJob()
                                        → loop su righe valid|corrected
                                        → BEGIN/COMMIT per ogni riga
                                        → INSERT/UPDATE in tabella target
                                        → UPDATE staging (status='imported', result_id, result_action)
                                        → UPDATE jobs (status='imported'|'partial')
```

## Schema relazionale

### Tabella `import_jobs`

| Campo | Tipo | Descrizione |
|---|---|---|
| id | INT PK | Identificativo job |
| import_type | VARCHAR(40) | Tipo: dipendenti, brand, candidati, ... |
| original_name | VARCHAR(255) | Nome del CSV caricato |
| file_size | INT | Dimensione bytes |
| total_rows | INT | Righe totali nel CSV |
| valid_rows | INT | Righe valide (passano validazione) |
| invalid_rows | INT | Righe con errori |
| imported_rows | INT | Righe effettivamente importate |
| skipped_rows | INT | Righe saltate (es. duplicati identici) |
| status | ENUM | uploaded / validated / partial / imported / aborted / rolled_back |
| started_at | DATETIME | Quando creato |
| validated_at | DATETIME | Quando validato |
| imported_at | DATETIME | Quando committato |
| created_by | INT FK users | Utente che ha lanciato |
| notes | TEXT | Annotazioni libere |

**Indici**: `idx_imp_type_status`, `idx_imp_user`, `idx_imp_started`

### Tabella `import_staging_rows`

| Campo | Tipo | Descrizione |
|---|---|---|
| id | INT PK | Identificativo riga staging |
| job_id | INT FK import_jobs | ON DELETE CASCADE |
| row_number | INT | Numero riga nel CSV (1-based) |
| payload | JSON | Dati riga key→value (post normalizzazione FK) |
| status | ENUM | pending / valid / invalid / imported / skipped / corrected |
| errors | JSON | `{"campo": "messaggio errore"}` per UI |
| result_id | INT | ID record creato/aggiornato in caso di successo |
| result_action | ENUM | insert / update / skip |
| imported_at | DATETIME | Quando importata |
| last_edit_at | DATETIME | Ultima correzione manuale |
| last_edit_by | INT FK users | Chi ha corretto |

**Indici**: `idx_isr_job_status`, `idx_isr_row` (per ordinamento e filtri rapidi)

### Diagramma ER

```
        users                                 import_jobs
          │                                        │
          │ 1                                    1 │
          │                                        │
          │N (created_by)               (job_id) N │
          ↓                                        ↓
       (creates)                    import_staging_rows
                                           │ N
                                           │
                                  (last_edit_by) → users
```

## Modulo `ImportValidator`

### Schema dichiarativo per tipo

Esempio `dipendenti`:
```php
'fiscal_code' => ['type' => 'cf', 'unique_in' => 'employees.fiscal_code'],
'company_name' => ['fk' => 'companies:name', 'fk_target' => 'company_id'],
'contract_type' => ['type' => 'enum', 'enum' => ['Indeterminato','Determinato',...]],
```

### Tipi supportati

`string`, `int`, `decimal`, `email`, `url`, `date`, `bool`, `phone`, `cf` (codice fiscale), `piva` (partita IVA), `enum`

### Risoluzione FK SMART

`company_name = "Antea"` → query `SELECT id FROM companies WHERE name = "Antea"` → restituisce `company_id` numerico nel payload normalizzato. Whitelist tabelle/colonne hardcoded nel validator (anti-injection).

### Cache FK

Le risoluzioni FK vengono memorizzate per la durata del job (in proprietà `$fkCache`). 1000 righe con 5 FK ciascuna = 5000 lookup teorici → in pratica ~50 query (per 50 brand distinti, ecc.).

## Modulo `ImportProcessor`

### Atomicità

Ogni riga è importata in una transazione separata:

```php
foreach ($valid_rows as $row) {
    try {
        $pdo->beginTransaction();
        $r = $this->commitSingleRow($row, ...);  // INSERT/UPDATE
        $pdo->commit();
        // → status 'imported'
    } catch (Throwable $e) {
        $pdo->rollBack();
        // → status 'invalid' con error _db
    }
}
```

**Garanzia**: l'errore di una riga (es. unique constraint) **non blocca** le altre. Ogni fallimento è atomicamente riportato in staging per ulteriore correzione.

### Storicizzazione

- Ogni job rimane in DB con stato finale
- Ogni riga di staging mantiene il payload originale + payload normalizzato + errori finali + result_id + action
- Il log `write_log('Import', ...)` traccia tutti gli eventi macro (creazione, validazione, commit)

### Re-import dopo correzione

Lo status `corrected` viene assegnato dopo modifica manuale (se passa validazione). Al successivo commit le righe `corrected` vengono trattate come `valid`.

## File del modulo

| File | Ruolo |
|---|---|
| `app/ImportValidator.php` | Validazione dichiarativa con schemi per tipo |
| `app/ImportProcessor.php` | Orchestratore: createJob → validateJob → commitJob + handler per ogni tipo |
| `mass_upload.php` | Form di upload + lista job aperti + download template CSV |
| `mass_upload_review.php` | Griglia editabile per correzione errori + commit |
| `mass_upload_jobs.php` | Storico completo con filtri |
| `sql/migration_v5_4.sql` | Migrazione DB |

## Sicurezza

- CSRF su tutti i form POST
- Permessi: solo ruolo 1-2 possono accedere
- Validazione MIME e size del file CSV (max 10 MB)
- BOM UTF-8 stripping
- Auto-detect separatore `,` o `;`
- Whitelist tabelle FK (anti-injection)
- Skip righe completamente vuote
- Path filtri preserva `r=` del Router opaco
- Soft-delete via `aborted` invece di DELETE per audit trail
- Solo ruolo 1 (Super Admin) può eliminare definitivamente un job

## Estensione: aggiungere un nuovo tipo

1. Aggiungere chiave nello schema in `ImportValidator::getSchema()`
2. Aggiungere handler `commitXxx()` in `ImportProcessor`
3. Aggiungere riga in `$TYPES` di `mass_upload.php`

Tutto il resto (UI, validazione, storicizzazione) è **automatico** dallo schema dichiarativo.
