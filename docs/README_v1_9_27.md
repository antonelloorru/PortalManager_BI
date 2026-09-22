# PortalManager v1.9.27 — Hotfix employee_profile.php

## Bug reale corretto (non solo cosmetico)

Nel file `employee_profile.php` il ramo POST di salvataggio anagrafica (righe 30-155)
usa i seguenti campi di `$emp` per **preservare i valori non modificati dal form**:
```
$emp['contract_type']         (linea 82)
$emp['agency']                (linea 83)
$emp['ccnl']                  (linea 84)
$emp['qualification']         (linea 85)
$emp['contract_level']        (linea 86)
$emp['part_time']             (linea 87)
$emp['part_time_pct']         (linea 88)
$emp['hire_date']             (linea 89)
$emp['end_date']              (linea 90)
$emp['apprenticeship_end_date'] (linea 91)
$emp['gender']                (linea 92)
$emp['badge_number']          (linea 93)
$emp['badge_issue_date']      (linea 94)
$emp['notes']                 (linea 80, se non can_hr)
```
Ma **`$emp` viene caricato solo alla linea 671**, dopo la chiusura del ramo POST.

### Conseguenze
- **Warning** (visibili): `Undefined variable $emp` alle righe 89-94.
- **Data-loss silenzioso** (invisibile, ben peggiore): all'UPDATE quei campi
  vengono impostati a `null` o al default. Ogni salvataggio dall'Anagrafica
  azzera contract_type, hire_date, end_date, badge_number, badge_issue_date,
  gender, ccnl, qualification, contract_level, agency, part_time%, notes (se
  non hr).

## Fix

Pre-fetch di `$emp` immediatamente **prima** del ramo POST (dopo la riga
`if (!$emp_id) { redirect('manage_employees'); }`):

```php
try {
    $__pm_pre = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $__pm_pre->execute([$emp_id]);
    $emp = $__pm_pre->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $__pm_e) { $emp = []; }
```

Il fetch grande alla riga 671 (con JOIN companies/locations/work_modes/users/roles)
resta invariato e sovrascrive `$emp` con la versione arricchita per il rendering.

## Contenuto pacchetto
- `tools/apply_v1_9_27_patch.php` — auto-patch idempotente, backup + validazione `php -l` + rollback.
- `sql/migration_v1_9_27.sql` — log versione + bump `app_version`.
- `docs/README_v1_9_27.md` — questa guida.

## Applicazione

### 1. Backup
```powershell
copy P:\xampp\htdocs\portalmanager\employee_profile.php P:\backup\employee_profile.bak
```

### 2. Auto-patch
Copia la cartella `tools/` sul server e lancia:
```powershell
cd P:\xampp\htdocs\portalmanager
P:\xampp\php\php.exe tools\apply_v1_9_27_patch.php employee_profile.php
```
Output atteso:
```
→ Target: employee_profile.php (158807 byte)
→ Inserimento pre-fetch dopo la riga 24 (offset 724)
→ Backup: employee_profile.bak_v1_9_27_YYYYMMDD_HHMMSS
[OK] Patch v1.9.27 applicata. php -l pulito.
```

### 3. Migration
```powershell
mysql -uroot portalmanager < P:\xampp\htdocs\portalmanager\sql\migration_v1_9_27.sql
```

### 4. Test funzionale
- Apri Anagrafica dipendente su un profilo con dati completi.
- Modifica solo il nome/cognome, salva.
- Verifica dal DB che `contract_type`, `hire_date`, `end_date`, `badge_number`,
  `badge_issue_date`, `gender`, `ccnl`, `qualification`, `contract_level`
  siano **rimasti invariati**.
```sql
SELECT id, first_name, contract_type, hire_date, end_date, badge_number, gender
FROM employees WHERE id = <ID_TEST>;
```

## Recupero dati persi dopo salvataggi pre-patch
Se il bug ha già azzerato dati storici, recuperare dal backup DB pre-hotfix
(fatto prima di questa release):
```sql
UPDATE employees e
JOIN backup_employees b ON b.id = e.id
SET e.contract_type = b.contract_type,
    e.hire_date     = b.hire_date,
    e.end_date      = b.end_date,
    e.badge_number  = b.badge_number,
    e.badge_issue_date = b.badge_issue_date,
    e.gender        = b.gender,
    e.ccnl          = b.ccnl,
    e.qualification = b.qualification,
    e.contract_level = b.contract_level,
    e.agency        = b.agency,
    e.part_time     = b.part_time,
    e.part_time_pct = b.part_time_pct
WHERE e.contract_type IS NULL AND b.contract_type IS NOT NULL;
```

## Rollback
```powershell
copy employee_profile.php.bak_v1_9_27_YYYYMMDD_HHMMSS employee_profile.php
```

## Marker
Lo script inserisce il commento `[PM_V1_9_27_APPLIED]` nel file per garantire
idempotenza. La seconda esecuzione rileva il marker e salta.
