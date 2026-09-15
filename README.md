# PortalManager v1.9.27 — FIX FINALE employee_profile.php

## Diagnosi
Il repo dichiara `VERSION=1.9.27` ma **il file `employee_profile.php` non è stato patchato**:
non contiene il marker `PM_V1_9_27_APPLIED` e `$emp` è ancora assegnato una sola
volta alla riga ~520, dopo il ramo POST che lo usa alle righe 90-100.

Effetto:
- Warning `Undefined variable $emp` alle righe 90-100.
- **Data-loss silenzioso**: contract_type, hire_date, end_date, badge_number,
  badge_issue_date, gender, ccnl, qualification, contract_level, agency,
  part_time, part_time_pct, notes vengono AZZERATI ad ogni salvataggio Anagrafica.

## Fix — 3 modi di applicarlo (scegli uno)

### A. Auto-patch PHP (raccomandato)
```powershell
cd P:\xampp\htdocs\portalmanager
P:\xampp\php\php.exe tools\apply_v1_9_27_patch.php employee_profile.php
```
Output atteso:
```
→ Inserimento pre-fetch dopo la riga 32 (offset ~950)
→ Backup: employee_profile.php.bak_v1_9_27_YYYYMMDD_HHMMSS
[OK] Patch v1.9.27 applicata. php -l pulito.
```

### B. git apply (se lavori dal repo GitHub)
```powershell
cd P:\xampp\htdocs\portalmanager
git apply patch\employee_profile_v1_9_27.patch
git status                        # employee_profile.php modificato
git diff employee_profile.php     # rivedi la modifica
git add employee_profile.php
git commit -m "v1.9.27: pre-fetch \$emp per prevenire data-loss silenzioso"
git push origin main
```

### C. Modifica manuale (30 secondi)
Apri `employee_profile.php`, vai alla **riga 32** (subito dopo
`if (!$emp_id) { redirect('manage_employees'); }`), incolla:
```php

// [PM_V1_9_27_APPLIED] Pre-fetch $emp per il branch POST che preserva i campi
// non modificati dal form (evita data-loss silenzioso). Il fetch principale
// piu' in basso sovrascrive $emp con la versione arricchita di JOIN per il render.
try {
    $__pm_pre = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $__pm_pre->execute([$emp_id]);
    $emp = $__pm_pre->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $__pm_e) {
    $emp = [];
}
```

## Verifica installazione
```powershell
P:\xampp\php\php.exe tools\verify_v1_9_27.php employee_profile.php
```
Output atteso:
```
Marker presente:   SI
Pre-fetch riga:    41 (o simile)
POST branch riga:  53
Pre-fetch PRIMA di POST: SI

[OK] Patch v1.9.27 correttamente installata.
```

## Svuota OPcache (obbligatorio)
Dopo la patch, PHP-OPcache serve la versione precedente finché il worker Apache
non viene riciclato:
```powershell
net stop Apache2.4 ; net start Apache2.4
```
o via richiesta HTTP se hai `opcache_reset.php` esposto.

## Migration di log
```powershell
mysql -uroot portalmanager < sql\migration_v1_9_27.sql
```

## Test funzionale
1. Apri Anagrafica dipendente su un profilo con dati completi
   (contract_type, hire_date, badge_*, gender valorizzati).
2. Modifica SOLO il campo Nome o Cognome, salva.
3. Verifica che gli altri campi siano invariati:
```sql
SELECT id, first_name, contract_type, hire_date, end_date,
       badge_number, badge_issue_date, gender, ccnl, qualification, contract_level
FROM employees WHERE id = <ID_TEST>;
```

## Rollback
```powershell
copy employee_profile.php.bak_v1_9_27_YYYYMMDD_HHMMSS employee_profile.php
net stop Apache2.4 ; net start Apache2.4
```

## Recupero dati persi da salvataggi pre-fix
Se hai salvataggi dell'Anagrafica dopo il bug, i campi preservati potrebbero
essere stati azzerati. Ripristina dal dump DB precedente:
```sql
UPDATE employees e JOIN backup.employees b ON b.id = e.id
   SET e.contract_type = COALESCE(e.contract_type, b.contract_type),
       e.hire_date     = COALESCE(e.hire_date,     b.hire_date),
       e.end_date      = COALESCE(e.end_date,      b.end_date),
       e.badge_number  = COALESCE(e.badge_number,  b.badge_number),
       e.badge_issue_date = COALESCE(e.badge_issue_date, b.badge_issue_date),
       e.gender        = COALESCE(e.gender,        b.gender),
       e.ccnl          = COALESCE(e.ccnl,          b.ccnl),
       e.qualification = COALESCE(e.qualification, b.qualification),
       e.contract_level = COALESCE(e.contract_level, b.contract_level),
       e.agency        = COALESCE(e.agency,        b.agency),
       e.part_time     = COALESCE(NULLIF(e.part_time,0), b.part_time),
       e.part_time_pct = COALESCE(e.part_time_pct, b.part_time_pct)
 WHERE e.updated_at >= '<DATA_PRIMO_BUG>';
```

## Contenuto pacchetto
```
pm_v1_9_27_fix/
├── VERSION                                     1.9.27
├── README.md                                   questa guida
├── patch/
│   └── employee_profile_v1_9_27.patch          diff per git apply
├── tools/
│   ├── apply_v1_9_27_patch.php                 auto-patch PHP idempotente + backup + lint
│   └── verify_v1_9_27.php                      verifica stato installazione
└── sql/
    └── migration_v1_9_27.sql                   log migration + bump app_version
```

## Test in laboratorio effettuati
- `git apply` sul file layout GitHub attuale: OK
- `git apply` sul file ZIP originale (layout con header su riga 10): OK
- Auto-patch PHP idempotente: RUN2 rileva marker e salta
- Verify script: OK sul file patchato, KO sul file originale
- `php -l` pulito post-patch
- Rollback automatico su fallimento lint
