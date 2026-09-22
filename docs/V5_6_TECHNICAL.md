# certV 5.6.0 — Approvazione per riga + LDB on-demand

## Cosa cambia

### Prima (v5.5)
La modalità LDB era una scelta **fatta a monte** dell'upload: l'utente decideva se attivare il flag `allow_partial` PRIMA di caricare il CSV. Tutto-o-niente: o tutte le righe partial entravano in LDB, o nessuna.

### Ora (v5.6)
La modalità LDB è una scelta **per singola riga** fatta DOPO la validazione. L'utente vede tutte le righe e per ogni una decide:

- ✅ **Approva strict** (verde) — riga completa, va in commit normale
- 🧩 **Approva in LDB** (arancione) — riga con campi mancanti, accettati come da completare
- 🚫 **Rifiuta** (grigio) — esclude la riga dal commit
- 🔄 **Annulla approvazione** — ritorna allo stato precedente per cambiare idea

Oppure usa **bulk approve**:
- "Approva tutte le valide" (strict)
- "Approva parziali in LDB"
- "Approva tutte" (mix automatico: complete in strict, parziali in LDB)

## Flusso completo

```
   Upload CSV
       │
       ↓
   validateJob()  → SEMPRE partial-aware
       │           ─ righe complete → status='valid'
       │           ─ righe con campi mancanti ma formati OK → status='partial'
       │           ─ righe con errori di tipo/FK → status='invalid'
       ↓
   FASE 1: APPROVAZIONE PER RIGA
       │
       │  Per ogni riga, l'utente sceglie:
       │   ├─ approveRow(id, 'strict')  → status='approved', approved_as='strict'
       │   ├─ approveRow(id, 'ldb')     → status='approved', approved_as='ldb'  (anche se partial)
       │   ├─ rejectRow(id)             → status='rejected'  (escluso dal commit)
       │   └─ unapproveRow(id)          → torna a valid/partial
       │
       │  Bulk approve disponibile per scope: valid | partial | all | selected
       ↓
   FASE 2: COMMIT
       │
       │  commitJob() lavora SOLO su righe in stato 'approved'
       │  is_partial deriva da approved_as='ldb'
       ↓
   imported (con eventuali is_partial=1 → vanno in mass_upload_partials.php)
```

## State machine `import_staging_rows`

```
   pending
      │
      │ validateJob()
      ↓
  ┌───┼───┬─────────┐
  │   │   │         │
  ↓   ↓   ↓         ↓
valid invalid partial pending
  │     │      │
  │     │ correct
  │     ↓ inline (updateStagingRow)
  │  corrected
  │     │
  │     │   (anche partial restano partial dopo correzioni che non riempiono i missing)
  │     │
  │ ┌───┴───────────────┐
  │ │ approveRow(strict)│   approveRow(ldb)   rejectRow
  │ ↓                   ↓                     ↓
  │ approved────────────┘                  rejected
  │ │                                          │
  │ │ unapproveRow                             │ unapproveRow
  │ ↓                                          ↓
  │ valid o partial                       valid o partial
  │
  │ commitJob() (solo se status='approved')
  ↓
imported (is_partial=1 se approved_as='ldb')
  │
  │ completePartialField() multiplo
  ↓
imported (is_partial=0, missing_fields=NULL)
```

## Schema DB

### `import_staging_rows` — modifiche

| Campo | Tipo | Scopo |
|---|---|---|
| `status` enum | esteso | aggiunti `approved`, `rejected` |
| `approved_as` | ENUM('strict','ldb') | come è stata approvata |
| `approved_at` | DATETIME | quando |
| `approved_by` | INT | da chi |
| INDEX `idx_isr_approved` | (job_id, status, approved_as) | per query commit |

### `import_jobs` — modifiche

| Campo | Tipo | Scopo |
|---|---|---|
| `approved_rows` | INT | conta righe in stato `approved` (in attesa di commit) |
| `rejected_rows` | INT | conta righe esplicitamente rifiutate |

## API metodi

### `ImportProcessor::approveRow(int $stagingId, string $mode='strict', ?int $userId=null): array`

Approva una singola riga. Modalità:
- `'strict'` → solo se status='valid' o 'corrected'. Errore se 'partial'.
- `'ldb'` → accetta anche 'partial' (campi mancanti).

Idempotente: se la riga è già approved, ridichiara la modalità.

```php
return [
    'ok'             => bool,
    'new_status'     => 'approved',
    'approved_as'    => 'strict' | 'ldb',
    'missing_fields' => array,  // campi che dovranno essere completati dopo (solo se ldb)
];
```

Errori:
- riga in `imported` o `skipped` → eccezione (già committata)
- riga in `invalid` → eccezione (correggere prima)
- riga in `partial` con mode=`strict` → eccezione (usare LDB o completare)

### `ImportProcessor::rejectRow(int $stagingId, ?int $userId=null): array`

Marca la riga come `rejected`. Sarà esclusa dal commit. Reversibile con `unapproveRow`.

### `ImportProcessor::unapproveRow(int $stagingId, ?int $userId=null): array`

Annulla l'approvazione/rifiuto. La riga torna a `valid` (se non aveva missing) o `partial` (se aveva missing).

### `ImportProcessor::approveBulk(int $jobId, string $scope, array $stagingIds=[], ?int $userId=null): array`

Approvazione di gruppo. Scopes:
- `'valid'` → solo righe `valid`/`corrected` (modalità strict)
- `'partial'` → solo righe `partial` (modalità ldb)
- `'all'` → entrambe (strict per le valid, ldb per le partial)
- `'selected'` → array di staging_id specifici (modalità auto in base allo status)

```php
return [
    'approved_strict' => int,
    'approved_ldb'    => int,
    'skipped'         => int,  // righe non approvabili (già imported, ecc.)
];
```

### `ImportProcessor::commitJob(int $jobId, ?int $userId=null): array`

**v5.6 — comportamento cambiato**: lavora SOLO su righe `status='approved'`. Le righe `valid` non approvate restano in staging.

`is_partial` viene impostato a 1 se `approved_as='ldb'`.

## UI nuova

### Pannello azioni a 2 fasi

**Fase 1 — Approvazione** (box azzurro)
- Bottone "Approva tutte le valide (N)"
- Bottone "Approva parziali in LDB (N)"
- Bottone "Approva tutte (N)" — mix auto

**Fase 2 — Commit** (box verde se >0 approvate, grigio altrimenti)
- Bottone "Commit (N righe approvate)" — disabilitato se 0
- Indicatore "N rifiutate (escluse)"

### Bottoni per riga

Nella colonna Azioni di ogni riga della griglia:

| Stato riga | Bottoni disponibili |
|---|---|
| `valid` / `corrected` | 💾 Salva, ✅ Approva strict (verde), 🚫 Rifiuta |
| `partial` | 💾 Salva, 🧩 Approva LDB (arancione), 🚫 Rifiuta |
| `invalid` | 💾 Salva (correggere prima) |
| `approved` | 🔄 Annulla approvazione |
| `rejected` | 🔄 Annulla rifiuto |
| `imported` | (nessun bottone, sola lettura) |

I bottoni di approvazione usano `<button form="...">` HTML5 per inviare a form ausiliari **fuori dal form di update**, evitando conflitti.

### Cella stato arricchita

Mostra:
- Badge stato colorato
- Se approvata: badge `STRICT` (verde) o `LDB` (giallo)
- Se partial: contatore "N campi mancanti"
- Se imported: ID record creato e azione (insert/update)

## Backward compatibility

- Il flag `allow_partial` su `import_jobs` è ancora presente ma **non viene più letto**: il comportamento è uniforme (sempre partial-aware).
- I job creati in v5.5 con `allow_partial=1` continuano a funzionare; il loro flusso è invariato fino al commit, dove ora richiedono approvazione esplicita prima.
- I job in stato `validated`/`partial` esistenti possono essere riaperti e processati con il nuovo flusso.

## Sicurezza

- CSRF su tutti i form (anche quelli ausiliari di approvazione)
- Permessi: ruoli 1-2 come prima
- `approveRow` rifiuta strict su partial (non si può "saltare" la validazione formale)
- `rejectRow` non elimina la riga, la marca solo: completamente reversibile
- Tutti gli UPDATE sono prepared statements

## File modificati (5)

- `sql/migration_v5_6.sql` (NUOVO) — alter idempotente
- `app/ImportProcessor.php` — `validateJob` partial-aware, `commitJob` solo `approved`, nuovi metodi `approveRow`/`rejectRow`/`unapproveRow`/`approveBulk`, `recalcJobStats` con contatori
- `mass_upload.php` — rimossi toggle pre-upload (non servono più)
- `mass_upload_review.php` — UI a 2 fasi, bottoni per riga, filtri estesi, statistiche con approved/rejected
- `docs/V5_6_TECHNICAL.md` (NUOVO) — questa documentazione
