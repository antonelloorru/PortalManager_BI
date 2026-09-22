# PortalManager v1.9.25 — Hotfix Auto-apply

## Bug corretto
Warning in `employee_profile.php` dopo salvataggio dati:
```
Undefined variable $emp
Trying to access array offset on value of type null
```
Causa: assenza del pattern PRG dopo il ramo POST + fetch di `$emp` solo nel ramo GET.

## Contenuto pacchetto
- `tools/apply_v1_9_25_patch.php` — script auto-patch idempotente con backup automatico e validazione `php -l`.
- `sql/migration_v1_9_25.sql` — registrazione versione nel log migration (nessun DDL).
- `docs/README_v1_9_25.md` — questa guida.

## Applicazione
1. **Copia** la cartella `tools/` in `P:\xampp\htdocs\portalmanager\tools\`
   (o in qualunque path; lo script è auto-contenuto).
2. **Esegui** dal CLI XAMPP:
   ```powershell
   cd P:\xampp\htdocs\portalmanager
   P:\xampp\php\php.exe tools\apply_v1_9_25_patch.php employee_profile.php
   ```
   (In alternativa, senza argomento, cerca `..\employee_profile.php` relativo a `tools\`.)

3. **Verifica** l'output atteso:
   ```
   → Target: P:\xampp\htdocs\portalmanager\employee_profile.php (N byte)

   Modifiche:
     - FIX 1 — PRG inserito a fine ramo POST (offset X)
     - FIX 2 — Guard $emp inserito a offset Y (contesto ...)
   → Backup: employee_profile.php.bak_v1_9_25_YYYYMMDD_HHMMSS
   [OK] Patch v1.9.25 applicata. php -l pulito.
   ```

4. **Applica migration** via SqlRunner (o direttamente):
   ```sql
   INSERT INTO pm_migration_sql (version, filename, applied_at)
   VALUES ('1.9.25','migration_v1_9_25.sql',NOW())
   ON DUPLICATE KEY UPDATE applied_at = NOW();
   ```

## Opzioni script
- `--dry-run` — mostra cosa modificherà senza scrivere nulla.
- `<file>` — path esplicito del file da patchare (default: `../employee_profile.php`).

## Rollback
Lo script crea un backup timestampato accanto al file originale:
```powershell
copy employee_profile.php.bak_v1_9_25_YYYYMMDD_HHMMSS employee_profile.php
```

## Cosa modifica lo script

### FIX 1 — PRG
Al termine del blocco `if ($_SERVER['REQUEST_METHOD'] === 'POST') { ... }` inserisce:
```php
$__pm_eid = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($__pm_eid > 0 && !headers_sent()) {
    header('Location: employee_profile.php?id=' . $__pm_eid . '&saved=1');
    exit;
}
```

### FIX 2 — Guard `$emp`
Prima del primo uso di `$emp[...]` inserisce (con wrapping `<?php ?>` se in contesto HTML):
```php
if (!isset($emp) || !is_array($emp)) {
    $__pm_eid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $emp = [];
    if ($__pm_eid > 0 && isset($pdo) && $pdo instanceof PDO) {
        try {
            $__pm_stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ?');
            $__pm_stmt->execute([$__pm_eid]);
            $emp = $__pm_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $__pm_e) { $emp = []; }
    }
}
```

## Sicurezze
- **Marker `PM_V1_9_25_APPLIED`**: la patch non si riapplica.
- **Backup automatico** prima di ogni scrittura.
- **Validazione `php -l`** post-patch: se la sintassi non è valida, la patch viene ripristinata dal backup ed esce con codice 2.
- **Scrittura atomica** via `rename()` da file temporaneo.

## Se lo script non trova il ramo POST
Alcuni template scrivono il salvataggio in una funzione o in un `switch`. In quel
caso lo script applica solo FIX 2 (guard) — che è comunque sufficiente ad
eliminare i warning. Per introdurre PRG in casi non standard, incolla qui il
blocco POST del tuo `employee_profile.php` e viene fornita la patch mirata.
