# certV — Patch v5.00.00 "Storicizzazione & Versionamento"

Data rilascio: 29/04/2026

## Cosa cambia

### 1. Storicizzazione posizioni

Ogni posizione ora mantiene uno **storico completo** di:

- **Cambi di stato** (draft/open/paused/closed/cancelled): chi ha cambiato cosa, quando, snapshot delle date al momento del cambio
- **Variazioni di compenso** (RAL min/max + benefits): chi ha modificato il compenso, prima/dopo, quando

Lo storico è visibile cliccando il bottone **🕐 Storico** sulle card delle posizioni in `recruiting_posizioni.php`. La nuova pagina `position_history.php` mostra:

- Statistiche (totale cambi, prima apertura, riaperture, chiusura)
- Timeline dei cambi di stato con grafica a "linea temporale"
- Timeline delle variazioni di compenso

### 2. Nuovo campo "Benefits"

Aggiunto il campo **`benefits`** (testuale, multilinea) a `job_positions`.

Tipici contenuti: "Auto aziendale, buoni pasto 7€/giorno, smart working 3gg/sett, formazione 2000€/anno".

UI: il modal di modifica posizione ha una nuova sezione "Compenso" con i campi RAL min, RAL max, Benefits — tutti **storicizzati automaticamente** ad ogni modifica.

### 3. Versionamento template

I template di posizione ora hanno **versionamento completo**:

- Ogni salvataggio di un template con stesso nome crea una **nuova versione** (v1, v2, v3...)
- Solo l'ultima versione è "corrente" (`is_current=1`)
- Le versioni precedenti sono mantenute come storico (per audit)
- Possibilità di **ripristinare** una versione precedente (crea automaticamente una nuova versione corrente con il content vecchio)
- **Soft delete**: disattivare un template senza perderlo

Tutto il flusso è gestito dal nuovo modulo `app/TemplateVersioning.php`.

### 4. Refactoring trigger JS anagrafica

I bottoni di modifica anagrafica (modifica dipendente, collega account, gestisci CV, gestisci contratto) ora usano **data attributes + event delegation** invece di `onclick=` inline.

**Vantaggi**:
- Il binding funziona anche dopo i refresh delle DataTables
- Codice più pulito (separazione markup/logica)
- Migliore gestione errori (se il JSON è malformato, mostra alert e non crasha)
- Più facile da estendere in futuro

**File interessato**: `manage_employees.php`. Il comportamento utente è invariato: clicca → si apre il modal di modifica come prima.

## Struttura del pacchetto

```
certV-5.00.00-refactor.zip
├── sql/
│   └── migration_v5.sql              ← DA APPLICARE A MANO via phpMyAdmin
├── app/
│   ├── PositionHistory.php           ← NUOVO modulo storicizzazione
│   └── TemplateVersioning.php        ← NUOVO modulo versionamento
├── recruiting_posizioni.php          ← AGGIORNATO: campo benefits, RAL UI, hooks history
├── manage_employees.php              ← AGGIORNATO: event delegation moderna
├── position_history.php              ← NUOVA pagina timeline
└── docs/
    └── RELEASE_NOTES_v5.md           ← questo file
```

## Schema DB modificato

### Nuove tabelle

| Tabella | Scopo |
|---|---|
| `position_status_history` | Cronologia cambi di stato delle posizioni |
| `position_compensation_history` | Cronologia variazioni RAL/benefits |
| `position_templates_history` | Audit log delle modifiche ai template |

### Tabelle estese

| Tabella | Nuove colonne |
|---|---|
| `job_positions` | `benefits` (TEXT) |
| `position_templates` | `version`, `is_current`, `superseded_at`, `superseded_by`, `notes` |

### Foreign keys

Tutte le nuove FK sono **`ON DELETE CASCADE`** verso `job_positions` (se una posizione viene cancellata, lo storico segue) e **`ON DELETE SET NULL`** verso `users` (se l'utente che ha fatto la modifica viene eliminato, l'audit resta ma con `changed_by=NULL`).

### Migrazione dati esistenti

Il SQL `migration_v5.sql` esegue automaticamente:
- Per ogni posizione esistente, crea una riga "seed" iniziale in `position_status_history` con il suo stato attuale
- Per ogni posizione esistente, crea una riga "seed" iniziale in `position_compensation_history` con il suo compenso attuale
- I template esistenti vengono marcati `version=1, is_current=1` (default)

Questo garantisce che ogni posizione abbia almeno una riga nello storico, partendo da oggi in avanti.

## Procedura di installazione

### 1. Backup

```powershell
cd C:\Data\SviluppoSoftware\xampp\htdocs
Compress-Archive portalbrand portalbrand-pre-v5-$(Get-Date -Format yyyyMMdd).zip
& "C:\xampp\mysql\bin\mysqldump.exe" -u root portal_manager > portal-pre-v5-$(Get-Date -Format yyyyMMdd).sql
```

### 2. Applicazione via system_update.php

1. Login Super Admin
2. **Sistema → Aggiorna sistema**
3. Carica `certV-5.00.00-refactor.zip`
4. Conferma

Il pacchetto contiene `sql/`, `app/`, e i file PHP modificati. `system_update.php` (versione fixed v4.2.1+) gestisce correttamente le sottocartelle.

### 3. Migrazione DB (ESSENZIALE)

phpMyAdmin → DB `portal_manager` → tab **Importa** → seleziona `sql/migration_v5.sql` → **Esegui**.

Lo script è idempotente: se le colonne esistono già, le salta.

### 4. Verifica

1. Logout / Login
2. **Recruiting → Posizioni aperte**
3. Verifica:
   - Le card delle posizioni mostrano un nuovo bottone **🕐** (storico)
   - Cliccando "Modifica posizione" appare la nuova sezione "Compenso" con RAL min, RAL max, Benefits
   - Salvando una posizione, lo storico si popola
4. **Anagrafica dipendenti**:
   - Cliccando il bottone "Modifica" si apre il modal correttamente (nuovo binding via data-attribute)
5. **Salvare un template** in Recruiting → modifica un campo → ri-salvare. Vai in DB:
   ```sql
   SELECT id, template_type, name, version, is_current, content
     FROM position_templates
    ORDER BY template_type, name, version;
   ```
   Devi vedere la versione precedente con `is_current=0` e la nuova con `is_current=1`.

## Compatibilità

- DB schema: richiede MySQL 5.7+ / MariaDB 10.4+ (uso di `INFORMATION_SCHEMA` nei controlli idempotenti)
- Tutte le nuove tabelle sono `InnoDB` con utf8mb4
- Foreign keys richiedono che `users` e `job_positions` siano InnoDB (lo sono in certV)

## Rollback

Se necessario, per tornare a v4.x:

1. Ripristina file da backup pre-v5
2. Drop nuove tabelle:
   ```sql
   DROP TABLE IF EXISTS position_status_history;
   DROP TABLE IF EXISTS position_compensation_history;
   DROP TABLE IF EXISTS position_templates_history;
   ALTER TABLE job_positions DROP COLUMN benefits;
   ALTER TABLE position_templates
     DROP COLUMN version,
     DROP COLUMN is_current,
     DROP COLUMN superseded_at,
     DROP COLUMN superseded_by,
     DROP COLUMN notes;
   ```

⚠ Il rollback **distrugge** lo storico accumulato — non è reversibile.

## Note sviluppatore

### Hook history nei salvataggi

Quando salvi una posizione esistente, il flusso ora è:

```php
// Snapshot prima del save
$previous = $pdo->prepare("SELECT * FROM job_positions WHERE id = ?")->execute([$id]);

// UPDATE
$pdo->prepare("UPDATE job_positions SET ... WHERE id = ?")->execute([...]);

// Hook v5
PositionHistory::recordIfStatusChanged($pdo, $previous, $new, $userId);
PositionHistory::recordIfCompensationChanged($pdo, $previous, $new, $userId);
```

Le funzioni `recordIfXxxChanged()` confrontano i dati prima/dopo e registrano una riga **solo se** c'è stata una modifica reale. Niente rumore nello storico per save che non cambiano nulla.

### Versionamento template

```php
// Crea o aggiorna (auto-incrementa versione)
$newId = TemplateVersioning::createVersion($pdo, 'hard_skills', 'PHP Senior',
                                          'Contenuto template...', $userId, 'Note opzionali');

// Lista tutte le versioni
$versions = TemplateVersioning::listVersions($pdo, 'hard_skills', 'PHP Senior');

// Versione corrente per uso runtime
$current = TemplateVersioning::getCurrent($pdo, 'hard_skills', 'PHP Senior');

// Ripristina versione 2 come nuova corrente (crea v3 con content di v2)
$restoredId = TemplateVersioning::restore($pdo, $version2Id, $userId);

// Soft delete (mantiene per audit)
TemplateVersioning::softDelete($pdo, $templateId, $userId);
```

### Event delegation pattern

Per estendere altri bottoni con lo stesso pattern di `manage_employees.php`:

```html
<button class="js-my-action"
        data-payload='<?=htmlspecialchars(json_encode($obj),ENT_QUOTES,"UTF-8")?>'>
  Click me
</button>

<script>
document.addEventListener('click', function(ev) {
    var btn = ev.target.closest('.js-my-action');
    if (btn) {
        ev.preventDefault();
        var data = JSON.parse(btn.getAttribute('data-payload'));
        myActionHandler(data);
    }
});
</script>
```

Funziona anche dopo refresh delle DataTables / SPA, perché l'handler è sul `document` (delegation).
