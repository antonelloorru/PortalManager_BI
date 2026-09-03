<?php
/**
 * PortalManager — merge_employees.php
 *
 * Verifica anagrafiche dipendenti, identifica duplicati e permette il merge
 * mantenendo un'unica anagrafica per persona.
 *
 * Riservato a Super Admin (1) e HR Director (2). Altri ruoli possono essere
 * autorizzati singolarmente via user_permissions.
 *
 * Workflow:
 *   Step 1 (list)    — rilevamento gruppi sospetti per CF/nome+data/email/matricola
 *   Step 2 (compare) — comparazione side-by-side di 2 record con radio per campo
 *   Step 3 (execute) — transazione atomica: merge dati + riassegnazione FK + DELETE duplicato
 *
 * Riassegnazione FK: aggiorna employee_id su 23+ tabelle correlate, gestendo
 * conflitti su UNIQUE constraints (employee_id, X) — in caso di duplicato,
 * il record di B viene cancellato (preserva A).
 */
require_once('access_control.php');
require_once('functions.php');

if (!can('view', 'merge_employees.php')) {
    $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Accesso negato. Funzionalità riservata HR/Super Admin.</div>";
    redirect('manage_employees');
}
$can_exec = can('edit', 'merge_employees.php') || can('delete', 'merge_employees.php');
$u_id = (int)$_SESSION['user_id'];

// ──────────────────────────────────────────────────────────────────────
// Whitelist FK su employees per la riassegnazione
// Formato: 'nome_tabella' => 'colonna_fk'
// Generato da: information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME='employees'
// ──────────────────────────────────────────────────────────────────────
$FK_TABLES = [
    // Pivot M:N
    'employee_brands'         => 'employee_id',
    'employee_skills'         => 'employee_id',
    // Anagrafica estesa
    'emp_education'           => 'employee_id',
    'emp_experiences'         => 'employee_id',
    'emp_languages'           => 'employee_id',
    'emp_cv_preferences'      => 'employee_id',
    // Certificazioni & formazione
    'user_certifications'     => 'employee_id',
    'planned_exams'           => 'employee_id',
    'training_plans'          => 'employee_id',
    // Devices
    'device_handovers'        => 'employee_id',
    'emp_devices_notebook'    => 'employee_id',
    'emp_devices_phone'       => 'employee_id',
    'emp_devices_sim'         => 'employee_id',
    'emp_devices_credit_card' => 'employee_id',
    'emp_devices_fuel_card'   => 'employee_id',
    'emp_devices_vehicle'     => 'employee_id',
    // Logistica & docs
    'logistics_requests'      => 'employee_id',
    'person_documents'        => 'employee_id',
    // Link integrazioni
    'employee_credly_link'    => 'employee_id',
    'employee_linkedin_link'  => 'employee_id',
    // Brand referents
    'brand_referents'         => 'employee_id',
    // User account
    'users'                   => 'employee_id',
    // Candidati convertiti (FK è converted_to_employee_id)
    'candidates'              => 'converted_to_employee_id',
];

// ──────────────────────────────────────────────────────────────────────
// Helper: rilevamento gruppi sospetti
// ──────────────────────────────────────────────────────────────────────
function find_duplicate_groups(PDO $pdo, string $method = 'cf'): array {
    $sql = match ($method) {
        'cf' => "
            SELECT GROUP_CONCAT(e.id ORDER BY e.id) AS ids, UPPER(e.fiscal_code) AS k,
                   COUNT(*) AS n
              FROM employees e
             WHERE e.fiscal_code IS NOT NULL AND e.fiscal_code <> ''
             GROUP BY UPPER(e.fiscal_code)
            HAVING n > 1
             ORDER BY n DESC, k",
        'name_dob' => "
            SELECT GROUP_CONCAT(e.id ORDER BY e.id) AS ids,
                   CONCAT(UPPER(e.last_name),'|',UPPER(e.first_name),'|',IFNULL(e.date_of_birth,'')) AS k,
                   COUNT(*) AS n
              FROM employees e
             WHERE e.first_name <> '' AND e.last_name <> ''
             GROUP BY UPPER(e.last_name), UPPER(e.first_name), e.date_of_birth
            HAVING n > 1
             ORDER BY n DESC, k",
        'email' => "
            SELECT GROUP_CONCAT(e.id ORDER BY e.id) AS ids,
                   LOWER(COALESCE(e.business_email, e.personal_email)) AS k,
                   COUNT(*) AS n
              FROM employees e
             WHERE (e.business_email <> '' OR e.personal_email <> '')
             GROUP BY LOWER(COALESCE(e.business_email, e.personal_email))
            HAVING n > 1
             ORDER BY n DESC, k",
        'matricola' => "
            SELECT GROUP_CONCAT(e.id ORDER BY e.id) AS ids,
                   UPPER(e.employee_code) AS k,
                   COUNT(*) AS n
              FROM employees e
             WHERE e.employee_code IS NOT NULL AND e.employee_code <> ''
             GROUP BY UPPER(e.employee_code)
            HAVING n > 1
             ORDER BY n DESC, k",
        // v1.7.84: nome "simile" — tollera secondi nomi e cognomi composti.
        // Raggruppa per la PRIMA parola di cognome e nome; mostra solo i gruppi in cui
        // i nomi completi differiscono (es. 'Mario Rossi' vs 'Mario Giuseppe Rossi',
        // oppure 'Rossi' vs 'Rossi Bianchi').
        'similar_name' => "
            SELECT GROUP_CONCAT(e.id ORDER BY e.id) AS ids,
                   CONCAT(UPPER(SUBSTRING_INDEX(TRIM(e.last_name),' ',1)),' ',UPPER(SUBSTRING_INDEX(TRIM(e.first_name),' ',1))) AS k,
                   COUNT(*) AS n
              FROM employees e
             WHERE e.first_name <> '' AND e.last_name <> ''
             GROUP BY UPPER(SUBSTRING_INDEX(TRIM(e.last_name),' ',1)),
                      UPPER(SUBSTRING_INDEX(TRIM(e.first_name),' ',1))
            HAVING n > 1
               AND COUNT(DISTINCT CONCAT(UPPER(TRIM(e.first_name)),'|',UPPER(TRIM(e.last_name)))) > 1
             ORDER BY n DESC, k",
        default => "SELECT NULL AS ids, '' AS k, 0 AS n WHERE 1=0",
    };
    return $pdo->query($sql)->fetchAll();
}

// ──────────────────────────────────────────────────────────────────────
// POST: ESEGUI MERGE
// ──────────────────────────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute_merge') {
    Csrf::verify();
    if (!$can_exec) {
        $msg = "<div class='alert alert-danger'>Privilegi insufficienti per eseguire il merge.</div>";
    } else {
        $keep_id = (int)($_POST['keep_id'] ?? 0);
        $del_id  = (int)($_POST['del_id']  ?? 0);
        $chosen  = $_POST['field'] ?? []; // array: campo => 'A' | 'B'

        if ($keep_id <= 0 || $del_id <= 0 || $keep_id === $del_id) {
            $msg = "<div class='alert alert-danger'>ID non validi.</div>";
        } else {
            try {
                $pdo->beginTransaction();

                // Carico entrambi i record per snapshot audit
                $eA = $pdo->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
                $eA->execute([$keep_id]); $recA = $eA->fetch();
                $eB = $pdo->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
                $eB->execute([$del_id]); $recB = $eB->fetch();
                if (!$recA || !$recB) throw new RuntimeException("Uno dei dipendenti non esiste");

                // v1.7.51: NUOVO ORDINE OPERAZIONI per evitare violazioni UNIQUE
                // su employees.employee_code, fiscal_code, business_email (quando il
                // master prende un valore dal duplicato che è ancora vivo).
                //
                // Sequenza corretta:
                //   1. Calcolo i campi da unire (NON eseguo subito UPDATE master)
                //   2. NEUTRALIZZO i campi UNIQUE sul duplicato (employee_code,
                //      fiscal_code, business_email → NULL) per liberare i valori
                //   3. UPDATE master con i campi scelti (ora sicuro)
                //   4. Riassegnazione FK
                //   5. DELETE duplicato

                // 1. Calcolo campi master da merge
                $editable_fields = ['first_name','last_name','employee_code','fiscal_code',
                    'date_of_birth','gender','company_id','location_id','work_mode_id',
                    'job_title','department','hire_date','end_date','status',
                    'contract_type','ccnl','part_time','part_time_pct','apprenticeship_end_date',
                    'qualification','contract_level',
                    'phone','phone_personal','personal_email','business_email',
                    'credly_url','linkedin_url','bio','technical_skills','soft_skills','notes',
                    'badge_number','badge_issue_date',
                    'ral','premio_concordato','km_concordati','km_effettivi','fuori_sede','fuori_sede_amount',
                ];
                $merged = []; $params = [];
                foreach ($editable_fields as $f) {
                    $choice = $chosen[$f] ?? 'A';
                    $val = $choice === 'B' ? ($recB[$f] ?? null) : ($recA[$f] ?? null);
                    $merged[] = "`$f` = ?";
                    $params[] = $val;
                }

                // 2. Neutralizza campi UNIQUE sul duplicato (libera vincoli)
                //    Solo campi che hanno UNIQUE constraint in employees
                $pdo->prepare("UPDATE employees SET
                       employee_code = NULL,
                       fiscal_code   = NULL,
                       business_email = NULL,
                       personal_email = NULL,
                       badge_number  = NULL
                     WHERE id = ?")->execute([$del_id]);

                // 3. UPDATE master con i campi scelti (ora niente conflitti UNIQUE)
                $params[] = $keep_id;
                $pdo->prepare("UPDATE employees SET " . implode(',', $merged) . " WHERE id = ?")
                    ->execute($params);

                // 4. RIASSEGNAZIONE FK: aggiorna employee_id su tutte le tabelle correlate
                // v1.7.50: pre-validazione defensive — controllo che la colonna esista
                //          nell'information_schema prima di eseguire UPDATE
                global $FK_TABLES;
                $valid_fk = [];
                $vs = $pdo->prepare("
                    SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = ?
                       AND COLUMN_NAME  = ?
                ");
                foreach ($FK_TABLES as $tbl => $col) {
                    $vs->execute([$tbl, $col]);
                    if ((int)$vs->fetchColumn() > 0) $valid_fk[$tbl] = $col;
                }

                // v1.7.52: GESTIONE GRANULARE CONFLITTI UNIQUE
                // Per ogni tabella FK:
                //   - Tentativo UPDATE diretto in massa
                //   - Se 23000 (UNIQUE violation):
                //     1. Identifica via information_schema.STATISTICS la UNIQUE key
                //        che include la colonna FK + le ALTRE colonne univoche
                //     2. DELETE solo i record di B che hanno la stessa combinazione
                //        di "altre colonne" di un record di A (con JOIN)
                //        → preserva i record di B che NON sono presenti in A
                //          (es. certificazioni non condivise)
                //     3. Riprova UPDATE: i record sopravvissuti vengono trasferiti
                //
                // Esempio user_certifications UNIQUE(employee_id,certification_id,issue_date):
                //   Master: cert_A 2024, cert_B 2023
                //   Dup:    cert_A 2024, cert_B 2023, cert_C 2025, cert_D 2026
                //   → DELETE (dup, cert_A 2024) e (dup, cert_B 2023) (conflict)
                //   → UPDATE rimanenti: cert_C e cert_D ora puntano al master
                //   → Master finale: cert_A 2024, cert_B 2023, cert_C 2025, cert_D 2026

                $reassigned = []; $dedup_skipped = [];
                $idx_q = $pdo->prepare("
                    SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
                      FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = ?
                       AND NON_UNIQUE   = 0
                       AND INDEX_NAME   != 'PRIMARY'
                     GROUP BY INDEX_NAME
                ");

                foreach ($valid_fk as $tbl => $col) {
                    try {
                        // Tentativo UPDATE diretto (caso comune: nessun conflitto)
                        $stm = $pdo->prepare("UPDATE `$tbl` SET `$col` = ? WHERE `$col` = ?");
                        $stm->execute([$keep_id, $del_id]);
                        $n = $stm->rowCount();
                        if ($n > 0) $reassigned[$tbl] = $n;
                    } catch (PDOException $e) {
                        if ($e->getCode() !== '23000') throw $e;

                        // UNIQUE violation: gestione granulare
                        // 1. Trova UNIQUE keys che includono $col
                        $idx_q->execute([$tbl]);
                        $other_cols = [];
                        foreach ($idx_q->fetchAll() as $idx) {
                            $cols = explode(',', $idx['cols']);
                            if (in_array($col, $cols, true)) {
                                $other_cols = array_values(array_diff($cols, [$col]));
                                break;
                            }
                        }

                        if (empty($other_cols)) {
                            // Edge case: UNIQUE su solo $col → nessun altro campo da matchare
                            // → tutti i record di B sono "duplicati" rispetto al master
                            // → DELETE diretto (caso eccezionale)
                            $del = $pdo->prepare("DELETE FROM `$tbl` WHERE `$col` = ?");
                            $del->execute([$del_id]);
                            $dedup_skipped[$tbl] = $del->rowCount();
                            continue;
                        }

                        // 2. DELETE granulare: solo i record di B che esistono già in A
                        //    (matching su tutte le "altre colonne" della UNIQUE)
                        $on_clauses = [];
                        foreach ($other_cols as $oc) {
                            // NULL-safe equality per gestire colonne nullable
                            $on_clauses[] = "(B.`$oc` <=> A.`$oc`)";
                        }
                        $on_join = implode(' AND ', $on_clauses);
                        $del_sql = "
                            DELETE B FROM `$tbl` B
                              INNER JOIN `$tbl` A ON $on_join
                             WHERE B.`$col` = ? AND A.`$col` = ?
                        ";
                        $del = $pdo->prepare($del_sql);
                        $del->execute([$del_id, $keep_id]);
                        $dedup_skipped[$tbl] = $del->rowCount();

                        // 3. UPDATE rimanenti (record di B NON presenti in A)
                        $stm = $pdo->prepare("UPDATE `$tbl` SET `$col` = ? WHERE `$col` = ?");
                        $stm->execute([$keep_id, $del_id]);
                        $n = $stm->rowCount();
                        if ($n > 0) $reassigned[$tbl] = $n;
                    }
                }

                // 5. DELETE record duplicato
                $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$del_id]);

                // 6. Audit log
                $audit_msg = "Merge dipendenti: mantenuto #$keep_id, eliminato #$del_id. "
                           . "Tabelle riassegnate: " . count($reassigned) . " (" . array_sum($reassigned) . " righe). "
                           . "Conflitti UNIQUE risolti: " . count($dedup_skipped) . " (" . array_sum($dedup_skipped) . " righe).";
                write_log('Anagrafica', 'success', $audit_msg, $u_id);
                try {
                    $pdo->prepare("INSERT INTO entity_change_log (entity_type, entity_id, action, old_values, new_values, user_id) VALUES ('employees',?,'merge',?,?,?)")
                        ->execute([$keep_id, json_encode(['merged_from' => $recB], JSON_UNESCAPED_UNICODE), json_encode(['master' => $recA, 'choices' => $chosen], JSON_UNESCAPED_UNICODE), $u_id]);
                } catch (Throwable $e) { /* tabella audit non disponibile */ }

                $pdo->commit();

                $detail = '';
                foreach ($reassigned as $t => $n) $detail .= "<li><code>$t</code>: <strong>$n</strong> riassegnati</li>";
                foreach ($dedup_skipped as $t => $n) $detail .= "<li><code>$t</code>: <strong>$n</strong> duplicati rimossi (conflitto UNIQUE)</li>";

                $_SESSION['flash_msg'] = "<div class='alert alert-success'>
                    <i class='fa-solid fa-circle-check'></i> <strong>Merge completato!</strong><br>
                    Mantenuto record <strong>#$keep_id — " . h($recA['last_name'] . ' ' . $recA['first_name']) . "</strong>.<br>
                    Eliminato record duplicato <strong>#$del_id</strong>.
                    <details style='margin-top:8px'><summary>Dettaglio riassegnazioni (" . (count($reassigned)+count($dedup_skipped)) . " tabelle)</summary><ul style='margin-top:5px'>$detail</ul></details>
                </div>";
                redirect('merge_employees');
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = "<div class='alert alert-danger'><i class='fa-solid fa-xmark'></i> Errore durante il merge: " . h($e->getMessage()) . "</div>";
            }
        }
    }
}

// v1.7.92: valorizzazione dell'email aziendale dalla personale, solo sui record scelti
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fill_business_email') {
    Csrf::verify();
    if (!can('edit', 'merge_employees.php')) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>";
        redirect('merge_employees', ['m' => 'email_az']);
    }
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['emp'] ?? []))));
    $done = 0;
    if ($ids) {
        $in = implode(',', $ids);
        // copia solo dove l'aziendale è ancora vuota e la personale è valorizzata
        $done = $pdo->exec(
            "UPDATE employees
                SET business_email = personal_email
              WHERE id IN ($in)
                AND (business_email IS NULL OR business_email = '')
                AND personal_email IS NOT NULL AND personal_email <> ''"
        );
        write_log('HR', 'success', "Email aziendale valorizzata dalla personale su $done dipendenti", (int)$_SESSION['user_id']);
    }
    $_SESSION['flash_msg'] = "<div class='alert alert-" . ($done ? 'success' : 'warning') . "'>"
        . ($done ? "Email aziendale valorizzata su <strong>$done</strong> dipendenti." : "Nessun record aggiornato.")
        . "</div>";
    redirect('merge_employees', ['m' => 'email_az']);
}

// Detection method da query string
$method = $_GET['m'] ?? 'cf';
$compare_a = (int)($_GET['a'] ?? 0);
$compare_b = (int)($_GET['b'] ?? 0);

require_once('header.php');
if (!empty($_SESSION['flash_msg'])) { echo $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
?>

<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <div>
    <h2 style="margin:0;font-size:21px;color:#0f172a">
      <i class="fa-solid fa-people-arrows" style="color:#7c3aed"></i> Verifica & Merge Anagrafiche Dipendenti
    </h2>
    <div style="font-size:12px;color:#64748b;margin-top:3px">
      Identifica e unifica duplicati nell'anagrafica.
      <span style="background:#fee2e2;color:#991b1b;padding:1px 8px;border-radius:10px;font-size:10px;margin-left:6px"><i class="fa-solid fa-lock"></i> RISERVATO</span>
    </div>
  </div>
</div>

<?= $msg ?>

<?php if ($compare_a > 0 && $compare_b > 0):
    // ════════════════════════════════════════════════════════════════
    // STEP 2: COMPARAZIONE SIDE-BY-SIDE
    // ════════════════════════════════════════════════════════════════
    $q = $pdo->prepare("SELECT * FROM employees WHERE id IN (?, ?) ORDER BY id");
    $q->execute([$compare_a, $compare_b]);
    $rows = $q->fetchAll();
    if (count($rows) < 2) { echo "<div class='alert alert-danger'>Uno dei record non esiste</div>"; require_once('footer.php'); exit; }
    [$recA, $recB] = $rows;
    if ($recA['id'] != $compare_a) { [$recA, $recB] = [$recB, $recA]; }

    $field_groups = [
        'Identificativi' => ['first_name','last_name','employee_code','fiscal_code','date_of_birth','gender'],
        'Organizzazione' => ['company_id','location_id','work_mode_id','job_title','department','hire_date','end_date','status'],
        'Contatti' => ['business_email','personal_email','phone','phone_personal'],
        'Inquadramento' => ['contract_type','ccnl','part_time','part_time_pct','apprenticeship_end_date','qualification','contract_level'],
        'Compensation (HR)' => ['ral','premio_concordato','km_concordati','km_effettivi','fuori_sede','fuori_sede_amount'],
        'Badge' => ['badge_number','badge_issue_date'],
        'Profilo' => ['credly_url','linkedin_url','bio','technical_skills','soft_skills','notes'],
    ];
?>
<div class="card" style="padding:14px;background:#fef3c7;border:1px solid #fde68a;margin-bottom:14px">
  <strong style="color:#92400e"><i class="fa-solid fa-triangle-exclamation"></i> Operazione irreversibile</strong>
  <div style="font-size:11px;color:#78350f;margin-top:4px">
    Il merge eliminerà uno dei 2 record. Tutte le relazioni FK (brand, certificazioni, dispositivi, ecc.) verranno automaticamente trasferite al record MASTER. Conflitti UNIQUE saranno risolti rimuovendo il record duplicato del record da eliminare.
  </div>
</div>

<form method="POST">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="execute_merge">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
    <div class="card" style="padding:12px;border:2px solid #16a34a;background:#f0fdf4">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <strong style="color:#166534;font-size:14px"><i class="fa-solid fa-shield-halved"></i> RECORD A — MASTER</strong>
          <div style="font-size:11px;color:#15803d">ID #<?= (int)$recA['id'] ?> · Da MANTENERE</div>
        </div>
        <label style="cursor:pointer">
          <input type="radio" name="master_choice" value="A" checked onchange="setMaster('A')">
          <span style="background:#16a34a;color:#fff;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700">MASTER</span>
        </label>
      </div>
      <div style="font-size:13px;color:#0f172a"><?= h($recA['last_name'] . ' ' . $recA['first_name']) ?></div>
      <div style="font-size:10px;color:#64748b;font-family:Consolas,monospace"><?= h($recA['fiscal_code'] ?: '—') ?></div>
    </div>
    <div class="card" style="padding:12px;border:2px solid #dc2626;background:#fef2f2">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <div>
          <strong style="color:#991b1b;font-size:14px"><i class="fa-solid fa-trash"></i> RECORD B — DUPLICATO</strong>
          <div style="font-size:11px;color:#b91c1c">ID #<?= (int)$recB['id'] ?> · Da ELIMINARE</div>
        </div>
        <label style="cursor:pointer">
          <input type="radio" name="master_choice" value="B" onchange="setMaster('B')">
          <span style="background:#e2e8f0;color:#64748b;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700">Inverti</span>
        </label>
      </div>
      <div style="font-size:13px;color:#0f172a"><?= h($recB['last_name'] . ' ' . $recB['first_name']) ?></div>
      <div style="font-size:10px;color:#64748b;font-family:Consolas,monospace"><?= h($recB['fiscal_code'] ?: '—') ?></div>
    </div>
  </div>
  <input type="hidden" name="keep_id" id="keep_id" value="<?= $compare_a ?>">
  <input type="hidden" name="del_id"  id="del_id"  value="<?= $compare_b ?>">

  <?php foreach ($field_groups as $title => $fields): ?>
  <div class="card" style="padding:12px;margin-bottom:10px">
    <h3 style="margin:0 0 10px 0;font-size:13px;color:#7c3aed"><?= h($title) ?></h3>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
        <th style="padding:5px 10px;text-align:left;font-size:10px;text-transform:uppercase;color:#64748b;width:30%">Campo</th>
        <th style="padding:5px 10px;text-align:left;font-size:10px;color:#166534;width:32%">Valore A (master)</th>
        <th style="padding:5px 10px;text-align:left;font-size:10px;color:#991b1b;width:32%">Valore B (duplicato)</th>
        <th style="padding:5px 10px;text-align:center;font-size:10px;color:#64748b;width:6%">Tieni</th>
      </tr></thead>
      <tbody>
        <?php foreach ($fields as $f):
          $vA = $recA[$f] ?? null; $vB = $recB[$f] ?? null;
          $same = (string)$vA === (string)$vB;
          // Suggerimento default: tieni il valore non vuoto, oppure B se entrambi valorizzati e A è vuoto
          $default = (!$same && empty($vA) && !empty($vB)) ? 'B' : 'A';
        ?>
        <tr style="border-bottom:1px solid #f1f5f9;<?= $same ? 'opacity:0.5' : '' ?>">
          <td style="padding:6px 10px;font-weight:600;color:#475569;font-size:11px"><?= h($f) ?></td>
          <td style="padding:6px 10px;background:#f0fdf4;font-size:11px;<?= $same ? 'color:#94a3b8' : 'color:#166534' ?>">
            <?= $vA !== null && $vA !== '' ? h((string)$vA) : '<em style="color:#cbd5e1">vuoto</em>' ?>
          </td>
          <td style="padding:6px 10px;background:#fef2f2;font-size:11px;<?= $same ? 'color:#94a3b8' : 'color:#991b1b' ?>">
            <?= $vB !== null && $vB !== '' ? h((string)$vB) : '<em style="color:#cbd5e1">vuoto</em>' ?>
          </td>
          <td style="padding:6px 10px;text-align:center">
            <?php if ($same): ?>
              <span style="font-size:10px;color:#94a3b8">=</span>
              <input type="hidden" name="field[<?= $f ?>]" value="A">
            <?php else: ?>
              <label style="cursor:pointer;font-size:11px"><input type="radio" name="field[<?= $f ?>]" value="A" <?= $default==='A' ? 'checked' : '' ?>> A</label>
              <label style="cursor:pointer;font-size:11px;margin-left:8px"><input type="radio" name="field[<?= $f ?>]" value="B" <?= $default==='B' ? 'checked' : '' ?>> B</label>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>

  <div class="card" style="padding:14px;background:#fef3c7;border:2px solid #fbbf24;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <strong style="color:#92400e">⚠ Confermi l'esecuzione del merge?</strong>
      <div style="font-size:11px;color:#78350f">Verrà eliminato il record marcato come DUPLICATO e tutte le sue relazioni (brand, cert, devices…) verranno trasferite al MASTER.</div>
    </div>
    <div style="display:flex;gap:8px">
      <a href="<?= url_safe('merge_employees', ['m' => $method]) ?>" class="btn">Annulla</a>
      <?php if ($can_exec): ?>
      <button type="submit" class="btn btn-primary" style="background:#dc2626" onclick="return confirm('Confermi il MERGE? L\'operazione è IRREVERSIBILE.\n\nClick OK per procedere.')">
        <i class="fa-solid fa-people-arrows"></i> Esegui Merge
      </button>
      <?php endif; ?>
    </div>
  </div>
</form>

<script>
function setMaster(choice) {
  const a = <?= $compare_a ?>, b = <?= $compare_b ?>;
  if (choice === 'B') {
    document.getElementById('keep_id').value = b;
    document.getElementById('del_id').value  = a;
  } else {
    document.getElementById('keep_id').value = a;
    document.getElementById('del_id').value  = b;
  }
}
</script>

<?php else:
    // ════════════════════════════════════════════════════════════════
    // STEP 1: LISTA GRUPPI SOSPETTI
    // ════════════════════════════════════════════════════════════════
    $methods = [
        'cf'        => ['Codice fiscale identico',   '#dc2626', 'Match più affidabile'],
        'matricola' => ['Stessa matricola',          '#0ea5e9', 'Match alto: stesso employee_code'],
        'name_dob'  => ['Stesso nome + data nascita','#7c3aed', 'Match buono se data nascita coincide'],
        'similar_name' => ['Stesso nome (simile)',   '#f59e0b', 'Tollera secondi nomi / cognomi composti mancanti'],
        'email_az'  => ['Email aziendale mancante',  '#0891b2', 'Valorizza l\'email aziendale dalla personale'],
        'email'     => ['Stessa email',              '#16a34a', 'Match medio: stessa business o personal email'],
    ];
    $groups = find_duplicate_groups($pdo, $method);
?>

<div style="margin-bottom:12px;display:flex;gap:6px;flex-wrap:wrap">
  <?php foreach ($methods as $key => [$lbl, $color, $desc]): ?>
  <a href="<?= url_safe('merge_employees', ['m' => $key]) ?>"
     class="btn btn-sm"
     style="background:<?= $method===$key ? $color : '#f1f5f9' ?>;color:<?= $method===$key ? '#fff' : '#64748b' ?>;border:1px solid <?= $color ?>40">
    <?= h($lbl) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="card" style="padding:10px 14px;margin-bottom:14px;background:#f0f9ff;border:1px solid #93c5fd;font-size:12px;color:#0c4a6e">
  <strong>Metodo di rilevamento attivo: <?= h($methods[$method][0]) ?></strong> · <?= h($methods[$method][2]) ?>
</div>

<?php if ($method === 'email_az'):
  // v1.7.92: dipendenti senza email aziendale ma con email personale valorizzata
  $miss = $pdo->query(
      "SELECT id, first_name, last_name, employee_code, personal_email, status
         FROM employees
        WHERE (business_email IS NULL OR business_email = '')
          AND personal_email IS NOT NULL AND personal_email <> ''
        ORDER BY last_name, first_name"
  )->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card" style="padding:14px">
  <p style="font-size:12px;color:#0c4a6e;background:#f0f9ff;border:1px solid #93c5fd;border-radius:8px;padding:10px;margin:0 0 12px">
    <i class="fa-solid fa-circle-info"></i> Per questi dipendenti l'<strong>email aziendale</strong> è vuota mentre è presente quella <strong>personale</strong>.
    Seleziona i record su cui vuoi copiare la personale nell'email aziendale: <em>nessuna modifica viene applicata senza la tua selezione</em>.
    I record già valorizzati non sono toccati.
  </p>
  <?php if (!$miss): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-style:italic">
      <i class="fa-solid fa-circle-check" style="font-size:30px;color:#16a34a;display:block;margin-bottom:8px"></i>
      Nessun dipendente da sistemare: tutte le email aziendali sono valorizzate. ✓
    </div>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="fill_business_email">
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px">
      <label style="font-size:12px;display:flex;gap:6px;align-items:center">
        <input type="checkbox" onclick="document.querySelectorAll('.emp-chk').forEach(c=>c.checked=this.checked)"> seleziona tutti
      </label>
      <span style="color:var(--muted);font-size:12px"><strong><?= count($miss) ?></strong> dipendenti senza email aziendale</span>
      <?php if (can('edit', 'merge_employees.php')): ?>
        <button class="btn btn-sm btn-primary" style="background:#0891b2" onclick="return confirm('Copiare l\'email personale nell\'email aziendale per i record selezionati?')">
          <i class="fa-solid fa-envelope-circle-check"></i> Applica ai selezionati
        </button>
      <?php endif; ?>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr style="background:#1e293b;color:#fff">
        <th style="padding:8px;width:34px"></th>
        <th style="padding:8px;text-align:left">Dipendente</th>
        <th style="padding:8px;text-align:left">Matricola</th>
        <th style="padding:8px;text-align:left">Email personale (verrà copiata)</th>
        <th style="padding:8px;text-align:left">Stato</th>
      </tr></thead>
      <tbody>
      <?php foreach ($miss as $m): ?>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:6px;text-align:center"><input class="emp-chk" type="checkbox" name="emp[]" value="<?= (int)$m['id'] ?>"></td>
          <td style="padding:6px;font-weight:600"><?= h(trim($m['last_name'] . ' ' . $m['first_name'])) ?></td>
          <td style="padding:6px;color:var(--muted)"><?= h($m['employee_code'] ?: '—') ?></td>
          <td style="padding:6px"><code><?= h($m['personal_email']) ?></code></td>
          <td style="padding:6px;color:var(--muted)"><?= h($m['status'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </form>
  <?php endif; ?>
</div>

<?php elseif (empty($groups)): ?>
<div class="card" style="padding:30px;text-align:center;color:#94a3b8;font-style:italic">
  <i class="fa-solid fa-circle-check" style="font-size:32px;color:#16a34a;display:block;margin-bottom:8px"></i>
  Nessun duplicato trovato con questo metodo. ✓
</div>
<?php else: ?>
<div class="card" style="padding:14px">
  <h3 style="margin:0 0 10px 0;font-size:13px;color:#7c3aed">
    <?= count($groups) ?> grupp<?= count($groups)===1?'o':'i' ?> sospett<?= count($groups)===1?'o':'i' ?> trovat<?= count($groups)===1?'o':'i' ?>
  </h3>
  <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr style="background:#1e293b;color:#fff">
      <th style="padding:8px;text-align:left">Chiave di match</th>
      <th style="padding:8px;text-align:center">Record</th>
      <th style="padding:8px;text-align:left">Dettagli dipendenti</th>
      <th style="padding:8px;text-align:center">Azione</th>
    </tr></thead>
    <tbody>
    <?php foreach ($groups as $g):
      $ids = array_map('intval', explode(',', $g['ids']));
      $q = $pdo->prepare("SELECT id, first_name, last_name, fiscal_code, employee_code, business_email, hire_date, status FROM employees WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
      $q->execute($ids);
      $details = $q->fetchAll();
    ?>
    <tr style="border-bottom:1px solid #f1f5f9">
      <td style="padding:8px;font-family:Consolas,monospace;font-size:11px;font-weight:700;color:#7c3aed"><?= h($g['k']) ?></td>
      <td style="padding:8px;text-align:center"><span style="background:#dc2626;color:#fff;padding:3px 10px;border-radius:10px;font-size:11px;font-weight:700"><?= (int)$g['n'] ?></span></td>
      <td style="padding:8px">
        <?php foreach ($details as $d): ?>
        <div style="font-size:11px;padding:3px 0;border-bottom:1px dashed #f1f5f9">
          <strong>#<?= (int)$d['id'] ?></strong>
          <?= h($d['last_name'] . ' ' . $d['first_name']) ?>
          <?php if ($d['employee_code']): ?> · <span style="font-family:Consolas,monospace;color:#64748b">matr. <?= h($d['employee_code']) ?></span><?php endif; ?>
          <?php if ($d['business_email']): ?> · <span style="color:#3b82f6"><?= h($d['business_email']) ?></span><?php endif; ?>
          <?php if ($d['hire_date']): ?> · <span style="color:#64748b">ass. <?= date('d/m/Y', strtotime($d['hire_date'])) ?></span><?php endif; ?>
          <span style="background:<?= $d['status']==='active'?'#16a34a15':'#fee2e2' ?>;color:<?= $d['status']==='active'?'#166534':'#991b1b' ?>;padding:1px 6px;border-radius:8px;font-size:9px;margin-left:4px"><?= h($d['status']) ?></span>
        </div>
        <?php endforeach; ?>
      </td>
      <td style="padding:8px;text-align:center">
        <?php if (count($ids) === 2): ?>
        <a href="<?= url_safe('merge_employees', ['m' => $method, 'a' => $ids[0], 'b' => $ids[1]]) ?>" class="btn btn-sm btn-primary" style="background:#7c3aed">
          <i class="fa-solid fa-arrows-to-circle"></i> Confronta
        </a>
        <?php else: ?>
        <span style="font-size:10px;color:#94a3b8">+<?= count($ids)-2 ?> da unire<br>uno alla volta:</span>
        <div style="display:flex;gap:4px;justify-content:center;margin-top:4px;flex-wrap:wrap">
          <?php for ($i = 1; $i < count($ids); $i++): ?>
          <a href="<?= url_safe('merge_employees', ['m' => $method, 'a' => $ids[0], 'b' => $ids[$i]]) ?>" class="btn btn-sm" style="font-size:10px">
            #<?= $ids[0] ?> ↔ #<?= $ids[$i] ?>
          </a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once('footer.php'); ?>
