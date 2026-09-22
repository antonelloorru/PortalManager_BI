# certV 5.8.0 — Estensione dinamica ENUM con approvazione

## Problema risolto

Durante l'import massivo del catalogo certificazioni (e altre tabelle con campi ENUM stretti come `category`, `level`), valori non censiti come "Senior", "Master", "Fundamentals" venivano rifiutati con errore "Valore non ammesso". L'unica soluzione era modificare manualmente lo schema DB.

## Soluzione: Extensible ENUM con workflow di approvazione

### Comportamento

1. **Import**: il validator riconosce i campi marcati `extensible_enum` (es. `catalogo.level`)
2. **Valore noto** (esatto o case-insensitive): normalizzato al canonico, riga `valid`
3. **Valore mai visto**:
   - Riga marcata `partial` (Late Data Binding) — il record si salva senza il campo
   - Valore registrato in `enum_proposals` con incremento `occurrences` se ricorrente
4. **Admin** apre **Estensioni ENUM**, vede le proposte pendenti, decide:
   - **Approva** → `ALTER TABLE` estende l'ENUM con il nuovo valore + auto-completa righe LDB
   - **Mappa** → converte la proposta a un valore esistente (es. "Senior" → "Professional") + auto-completa
   - **Rifiuta** → ignora; le righe LDB restano incomplete

## Schema DB

### Tabella `enum_proposals`

| Campo | Tipo | Scopo |
|---|---|---|
| `id` | INT PK | |
| `target_table` | VARCHAR(60) | Tabella DB (es. `certifications`) |
| `target_column` | VARCHAR(60) | Colonna ENUM (es. `level`) |
| `proposed_value` | VARCHAR(100) | Nuovo valore proposto |
| `occurrences` | INT | Quante volte è apparso negli import |
| `status` | ENUM | `pending`, `approved`, `mapped`, `rejected` |
| `mapped_to` | VARCHAR(100) | Se mapped: valore canonico esistente |
| `first_seen_at` / `last_seen_at` | DATETIME | Tracking temporale |
| `first_source_ref` | INT | job_id del primo import |
| `decided_at` / `decided_by` | DATETIME / INT FK users | Audit decisione |

UNIQUE su `(target_table, target_column, proposed_value)` → idempotente: re-import della stessa proposta non duplica, incrementa `occurrences`.

## Whitelist di sicurezza

`EnumExtender::isWhitelisted()` autorizza solo:

```php
'certifications.level'
'certifications.category'
'employee_skills.level'
```

Estensione futura → aggiungere alla `getWhitelistedTargets()`. Anti-injection: nessuna ALTER TABLE può essere lanciata su tabella/colonna fuori whitelist.

## API

### `EnumExtender`

```php
getEnumValues($table, $column): array          // Legge ENUM corrente da information_schema (cache request)
isWhitelisted($table, $column): bool           // Solo whitelist autorizzati per estensione
resolve($table, $column, $value): array        // ['exact'=>?, 'fuzzy'=>?] match esatto o CI
recordProposal($table, $column, $value, $jobId): int     // Idempotente UPSERT (UNIQUE key)
approveProposal($id, $userId): array           // ALTER TABLE estende ENUM
mapProposal($id, $mappedTo, $userId): array    // status='mapped', mapped_to canonico
rejectProposal($id, $userId, $reason): array
listProposals($status, $tableFilter): array
```

### `ImportProcessor::applyEnumDecision($proposalId, $userId)`

Dopo Approva/Mappa: scansiona tutte le righe LDB con `__enum_proposals__` matching nel payload e le completa automaticamente con il valore canonico (chiama `completePartialField` per ognuna).

## Schema validator: nuovo tipo `extensible_enum`

```php
'level' => [
    'type' => 'extensible_enum',
    'extensible_target' => 'certifications.level',
    'label' => 'Livello',
    'hint' => 'Foundation/Associate/Professional/Expert/Specialty',
    'example' => 'Associate'
],
```

Differenze da `enum`:
- Lista valori letta a runtime da `information_schema` (no hardcode)
- Match fuzzy case-insensitive (es. `professional` → `Professional`)
- Valori sconosciuti → `partial` + proposta (in `validateRowPartial`)
- In `validateRow` strict: errore solo se NON c'è una mappatura `mapped` precedente

## Flusso end-to-end

```
[1] CSV catalogo:
    code,name,brand_name,level,category
    XYZ-001,New Cert,Cisco,Senior,tecnica

[2] validateJob() chiama validateRowPartial:
    - level="Senior" non in ('Foundation','Associate','Professional','Expert','Specialty')
    - resolveExtensibleEnum('certifications.level', 'Senior', allowProposal=true)
      → recordProposal('certifications', 'level', 'Senior', $jobId)
      → return ['_proposal' => 'Senior', '_target' => 'certifications.level']
    - missing_fields = ['level']
    - status = 'partial'
    - payload['__enum_proposals__']['level'] = ['target'=>...,'proposed_value'=>'Senior']

[3] L'utente approva la riga in modalità LDB → commit
    - commitSingleRow rimuove __enum_proposals__ dal payload
    - INSERT certifications (... level=NULL ...)
    - is_partial=1, missing_fields=['level']

[4] Admin va in "Estensioni ENUM":
    - Vede proposta "Senior" su certifications.level (1 occorrenza, pending)
    - Click "Approva" → ALTER TABLE certifications MODIFY level ENUM(...,'Senior')
    - applyEnumDecision: trova staging row partial con __enum_proposals__ matching
    - completePartialField($staging_id, 'level', 'Senior') → UPDATE certifications SET level='Senior'
    - Riga ora completa, missing_fields=NULL, is_partial=0

[Alt] Admin "Mappa" "Senior" → "Professional":
    - status='mapped', mapped_to='Professional'
    - applyEnumDecision completa il record con level='Professional'
    - Future occorrenze di "Senior" in nuovi import → auto-risolte a 'Professional'
```

## Sicurezza

- Whitelist hardcoded di tabelle/colonne estendibili (no SQL injection via input)
- ALTER TABLE solo via `approveProposal` con check whitelist
- Lettura ENUM da `information_schema` con prepared statements
- Permessi: solo Super Admin (ruolo 1) può approvare/mappare/rifiutare; ruolo 2 vede solo
- CSRF su tutti i form
- Cache enum invalidata dopo ogni ALTER

## File del pacchetto (6)

- `sql/migration_v5_8.sql` (NUOVO)
- `app/EnumExtender.php` (NUOVO)
- `app/ImportValidator.php` — schema con `extensible_enum`, helper `resolveExtensibleEnum`, allineamento DB reale (Specialty)
- `app/ImportProcessor.php` — `setJobIdForProposals` su validator, `applyEnumDecision` per auto-completamento LDB
- `app/Router.php` — slug `manage_enum_proposals`
- `header.php` — voce menu "Estensioni ENUM"
- `manage_enum_proposals.php` (NUOVO) — UI gestione proposte
- `docs/V5_8_TECHNICAL.md` — questa documentazione
