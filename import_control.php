<?php
/**
 * import_control.php — Controllo & Riconciliazione import rapporti (v1.7.67)
 *
 * Rende azionabili le righe importate ma non risolte (commessa / tecnico / fascia
 * non riconosciuti). Raggruppa le anomalie per valore grezzo, propone i candidati,
 * consente di esportarle in XLSX, ricaricare il file compilato e riapplicare la
 * risoluzione a tutte le righe già in archivio, senza reimportare l'export originale.
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/XlsxReader.php');
require_once(__DIR__ . '/app/UploadGuard.php');
require_once(__DIR__ . '/app/AliasStore.php');
require_once(__DIR__ . '/app/ProjectModel.php');

if (!can('view', 'import_control.php')) { redirect('manage_projects'); }
$can_edit = can('edit', 'import_control.php') || can('create', 'import_control.php');
$u_id  = (int)$_SESSION['user_id'];
$alias = new AliasStore($pdo);

/** Anomalie raggruppate per valore grezzo, escluse quelle già mappate o ignorate. */
function cm_unresolved(PDO $pdo, string $type, int $limit = 500): array {
    switch ($type) {
        case AliasStore::T_PROJECT:
            $sql = "SELECT ir.project_code AS raw_value, COUNT(*) AS occorrenze,
                           MIN(ir.report_date) AS dal, MAX(ir.report_date) AS al
                    FROM cm_intervention_reports ir
                    LEFT JOIN cm_alias_project a ON a.raw_code = ir.project_code
                    WHERE ir.project_id IS NULL AND ir.project_code IS NOT NULL AND ir.project_code <> ''
                      AND (a.id IS NULL OR (a.ignored = 0 AND a.project_id IS NULL))
                    GROUP BY ir.project_code ORDER BY occorrenze DESC, raw_value LIMIT $limit";
            break;
        case AliasStore::T_TECHNICIAN:
            $sql = "SELECT ir.technician_raw AS raw_value, COUNT(*) AS occorrenze,
                           MIN(ir.report_date) AS dal, MAX(ir.report_date) AS al
                    FROM cm_intervention_reports ir
                    LEFT JOIN cm_alias_technician a ON a.raw_name = ir.technician_raw
                    WHERE ir.technician_id IS NULL AND ir.technician_raw IS NOT NULL AND ir.technician_raw <> ''
                      AND (a.id IS NULL OR (a.ignored = 0 AND a.employee_id IS NULL))
                    GROUP BY ir.technician_raw ORDER BY occorrenze DESC, raw_value LIMIT $limit";
            break;
        default:
            // v1.7.89: una sigla può essere già mappata a livello di singola commessa.
            $sql = "SELECT ir.band_raw AS raw_value, COUNT(*) AS occorrenze,
                           MIN(ir.report_date) AS dal, MAX(ir.report_date) AS al
                    FROM cm_intervention_reports ir
                    LEFT JOIN cm_alias_band a  ON a.raw_band = ir.band_raw AND a.project_id = 0
                    LEFT JOIN cm_alias_band ap ON ap.raw_band = ir.band_raw AND ap.project_id = ir.project_id AND ap.project_id <> 0
                    WHERE ir.band_id IS NULL AND ir.band_raw IS NOT NULL AND ir.band_raw <> ''
                      AND (a.id IS NULL OR (a.ignored = 0 AND a.band_id IS NULL))
                      AND (ap.id IS NULL OR (ap.ignored = 0 AND ap.band_id IS NULL))
                    GROUP BY ir.band_raw ORDER BY occorrenze DESC, raw_value LIMIT $limit";
    }
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * v1.7.89: commesse in cui una sigla di fascia risulta ancora non risolta,
 * per consentire la mappatura specifica per singola commessa (es. fascia "E"
 * con valori diversi per commessa, o fascia "X" dedicata al professionista).
 */
function cm_band_projects(PDO $pdo, string $raw, int $limit = 50): array {
    $st = $pdo->prepare(
        "SELECT ir.project_id, COALESCE(p.project_code, ir.project_code, '—') AS codice,
                p.name AS nome, COUNT(*) AS occorrenze,
                MAX(ir.technician_professional_id) AS prof_id
           FROM cm_intervention_reports ir
           LEFT JOIN cm_projects p ON p.id = ir.project_id
           LEFT JOIN cm_alias_band ap ON ap.raw_band = ir.band_raw AND ap.project_id = ir.project_id AND ap.project_id <> 0
          WHERE ir.band_id IS NULL AND ir.band_raw = ? AND ir.project_id IS NOT NULL
            AND (ap.id IS NULL OR (ap.ignored = 0 AND ap.band_id IS NULL))
          GROUP BY ir.project_id, codice, nome
          ORDER BY occorrenze DESC LIMIT $limit"
    );
    $st->execute([$raw]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Suggerimento automatico: miglior candidato per similarità (aiuto all'operatore). */
function cm_suggest(string $raw, array $candidates): array {
    $lower = fn(string $v) => function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    // v1.7.88: oltre al confronto diretto si valuta la forma con token ordinati,
    // così 'Mario Rossi' e 'Rossi Mario' risultano equivalenti.
    $sorted = function (string $v) use ($lower) {
        $parts = preg_split('/[\s,]+/u', trim($lower($v)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($parts);
        return implode(' ', $parts);
    };
    $best = ['id' => null, 'label' => '', 'score' => 0];
    $raw_n = $lower($raw); $raw_s = $sorted($raw);
    foreach ($candidates as $id => $label) {
        $lab_n = $lower($label);
        similar_text($raw_n, $lab_n, $p1);
        similar_text($raw_s, $sorted($label), $p2);
        $pct = max($p1, $p2);
        if ($pct > $best['score']) $best = ['id' => $id, 'label' => $label, 'score' => round($pct)];
    }
    return $best['score'] >= 60 ? $best : ['id' => null, 'label' => '', 'score' => 0];
}

/** Riapplica la risoluzione a tutte le righe non risolte (set-based, senza reimport). */
// v1.7.88: candidati tecnico = dipendenti + professionisti. I professionisti collegati
// a un dipendente puntano all'id dipendente; gli ESTERNI usano la chiave 'P<id>' e
// vengono risolti su technician_professional_id. Le etichette restano "pulite" per non
// degradare il matching per similarità; $kinds riporta il tipo per il badge in UI.
function cm_tech_candidates(PDO $pdo, ?array &$kinds = null): array {
    $kinds = [];
    $c = $pdo->query("SELECT id, CONCAT(last_name,' ',first_name) AS l FROM employees ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($c as $id => $l) $kinds[(string)$id] = 'dipendente';
    try {
        $rows = $pdo->query("SELECT id, first_name, last_name, username, abbr, employee_id FROM cm_professionals WHERE deleted_src = 0 ORDER BY last_name, first_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $p) {
            $nome = trim(($p['last_name'] ?? '') . ' ' . ($p['first_name'] ?? ''));
            if ($nome === '') $nome = (string)($p['username'] ?: ($p['abbr'] ?: ('#' . $p['id'])));
            if (!empty($p['employee_id'])) {
                $eid = (int)$p['employee_id'];
                if (!isset($c[$eid])) { $c[$eid] = $nome; $kinds[(string)$eid] = 'dipendente'; }
            } else {
                $key = 'P' . (int)$p['id'];
                $c[$key] = $nome;
                $kinds[$key] = 'esterno';
            }
        }
    } catch (Throwable $e) { /* professionisti assenti */ }
    return $c;
}

/**
 * v1.7.85: Verifica dei tecnici non risolti contro l'anagrafica Dipendenti aggiornata
 * e l'anagrafica Professionisti, per allineare anche a posteriori. Classifica ogni
 * nome grezzo: 'dipendente' | 'professionista_unito' | 'professionista_esterno' | 'nessuno'.
 * Match esatto (case-insensitive) su nome, username, sigla e varianti invertite.
 */
function cm_tech_verify(PDO $pdo, int $limit = 5000): array {
    $rows = cm_unresolved($pdo, AliasStore::T_TECHNICIAN, $limit);
    $summary = ['dipendente' => 0, 'professionista_unito' => 0, 'professionista_esterno' => 0, 'nessuno' => 0];
    if (!$rows) return ['items' => [], 'summary' => $summary];

    $empIdx = [];
    foreach ($pdo->query("SELECT id, first_name, last_name FROM employees")->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $fn = mb_strtolower(trim($e['first_name'] ?? ''), 'UTF-8');
        $ln = mb_strtolower(trim($e['last_name'] ?? ''), 'UTF-8');
        if ($fn === '' && $ln === '') continue;
        $empIdx[trim("$ln $fn")] = (int)$e['id'];
        $empIdx[trim("$fn $ln")] = (int)$e['id'];
    }
    $profIdx = [];
    try {
        foreach ($pdo->query("SELECT id, username, abbr, first_name, last_name, employee_id FROM cm_professionals")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $keys = [];
            foreach (['username', 'abbr'] as $c) if (!empty($p[$c])) $keys[] = mb_strtolower(trim($p[$c]), 'UTF-8');
            $fn = mb_strtolower(trim($p['first_name'] ?? ''), 'UTF-8');
            $ln = mb_strtolower(trim($p['last_name'] ?? ''), 'UTF-8');
            if ($fn !== '' || $ln !== '') { $keys[] = trim("$fn $ln"); $keys[] = trim("$ln $fn"); }
            foreach (array_unique(array_filter($keys)) as $k) {
                if (!isset($profIdx[$k])) $profIdx[$k] = ['prof_id' => (int)$p['id'], 'employee_id' => $p['employee_id'] ? (int)$p['employee_id'] : null];
            }
        }
    } catch (Throwable $e) { /* professionisti assenti */ }

    $items = [];
    foreach ($rows as $u) {
        $raw = (string)$u['raw_value'];
        $key = mb_strtolower(trim($raw), 'UTF-8');
        $cls = 'nessuno'; $target = null;
        if (isset($empIdx[$key])) { $cls = 'dipendente'; $target = ['employee_id' => $empIdx[$key]]; }
        elseif (isset($profIdx[$key])) {
            if ($profIdx[$key]['employee_id']) { $cls = 'professionista_unito'; $target = ['employee_id' => $profIdx[$key]['employee_id'], 'prof_id' => $profIdx[$key]['prof_id']]; }
            else { $cls = 'professionista_esterno'; $target = ['prof_id' => $profIdx[$key]['prof_id']]; }
        }
        $summary[$cls]++;
        $items[] = ['raw' => $raw, 'occorrenze' => (int)$u['occorrenze'], 'class' => $cls, 'target' => $target];
    }
    return ['items' => $items, 'summary' => $summary];
}

function cm_reapply(PDO $pdo): array {
    $n = ['commesse' => 0, 'tecnici' => 0, 'fasce' => 0, 'ricalcolate' => 0];

    // Commessa: per codice esatto, poi per alias
    $n['commesse'] += $pdo->exec(
        "UPDATE cm_intervention_reports ir JOIN cm_projects p ON p.project_code = ir.project_code
         SET ir.project_id = p.id WHERE ir.project_id IS NULL");
    $n['commesse'] += $pdo->exec(
        "UPDATE cm_intervention_reports ir JOIN cm_alias_project a ON a.raw_code = ir.project_code
         SET ir.project_id = a.project_id
         WHERE ir.project_id IS NULL AND a.ignored = 0 AND a.project_id IS NOT NULL");

    // v1.7.83: semina alias tecnico dai professionisti collegati a un dipendente
    // (username, sigla, 'Nome Cognome', 'Cognome Nome'), così i rapporti si agganciano.
    try {
        $pdo->exec(
            "INSERT IGNORE INTO cm_alias_technician (raw_name, employee_id)
             SELECT x.raw_name, x.employee_id FROM (
                 SELECT username  AS raw_name, employee_id FROM cm_professionals WHERE employee_id IS NOT NULL AND username IS NOT NULL AND username<>''
                 UNION SELECT abbr, employee_id FROM cm_professionals WHERE employee_id IS NOT NULL AND abbr IS NOT NULL AND abbr<>''
                 UNION SELECT CONCAT(first_name,' ',last_name), employee_id FROM cm_professionals WHERE employee_id IS NOT NULL AND first_name IS NOT NULL AND last_name IS NOT NULL
                 UNION SELECT CONCAT(last_name,' ',first_name), employee_id FROM cm_professionals WHERE employee_id IS NOT NULL AND first_name IS NOT NULL AND last_name IS NOT NULL
             ) x");
    } catch (Throwable $e) { /* professionisti assenti */ }

    // Tecnico: 'Cognome Nome', 'Nome Cognome', poi alias
    $n['tecnici'] += $pdo->exec(
        "UPDATE cm_intervention_reports ir JOIN employees e
            ON CONCAT(e.last_name,' ',e.first_name) = ir.technician_raw
            OR CONCAT(e.first_name,' ',e.last_name) = ir.technician_raw
         SET ir.technician_id = e.id WHERE ir.technician_id IS NULL");
    $n['tecnici'] += $pdo->exec(
        "UPDATE cm_intervention_reports ir JOIN cm_alias_technician a ON a.raw_name = ir.technician_raw
         SET ir.technician_id = a.employee_id
         WHERE ir.technician_id IS NULL AND a.ignored = 0 AND a.employee_id IS NOT NULL");
    // v1.7.88: alias mappati su un PROFESSIONISTA. Se il professionista è collegato a un
    // dipendente si risolve su technician_id, altrimenti su technician_professional_id.
    try {
        $n['tecnici'] += $pdo->exec(
            "UPDATE cm_intervention_reports ir
                JOIN cm_alias_technician a ON a.raw_name = ir.technician_raw
                JOIN cm_professionals p    ON p.id = a.professional_id
             SET ir.technician_id = p.employee_id
             WHERE ir.technician_id IS NULL AND a.ignored = 0
               AND a.professional_id IS NOT NULL AND p.employee_id IS NOT NULL");
        $n['tecnici'] += $pdo->exec(
            "UPDATE cm_intervention_reports ir
                JOIN cm_alias_technician a ON a.raw_name = ir.technician_raw
                JOIN cm_professionals p    ON p.id = a.professional_id
             SET ir.technician_professional_id = p.id
             WHERE ir.technician_id IS NULL AND ir.technician_professional_id IS NULL
               AND a.ignored = 0 AND a.professional_id IS NOT NULL AND p.employee_id IS NULL");
    } catch (Throwable $e) { /* colonne assenti: ignora */ }
    // v1.7.83: risoluzione tramite Anagrafica Professionisti (stessa origine gestionale).
    // Un professionista collegato a un dipendente porta con sé il technician_id, con match su
    // username / abbr / 'Nome Cognome' / 'Cognome Nome'.
    try {
        $n['tecnici'] += $pdo->exec(
            "UPDATE cm_intervention_reports ir JOIN cm_professionals p
                ON p.employee_id IS NOT NULL AND (
                     p.username = ir.technician_raw
                  OR p.abbr = ir.technician_raw
                  OR CONCAT(p.first_name,' ',p.last_name) = ir.technician_raw
                  OR CONCAT(p.last_name,' ',p.first_name) = ir.technician_raw )
             SET ir.technician_id = p.employee_id
             WHERE ir.technician_id IS NULL");
    } catch (Throwable $e) { /* tabella professionisti assente: ignora */ }
    // v1.7.87: associa i professionisti ESTERNI (senza dipendente) al tecnico del rapporto,
    // valorizzando technician_professional_id (match su username / sigla / nome / nome invertito).
    try {
        $n['tecnici'] += $pdo->exec(
            "UPDATE cm_intervention_reports ir JOIN cm_professionals p
                ON p.employee_id IS NULL AND (
                     p.username = ir.technician_raw
                  OR p.abbr = ir.technician_raw
                  OR CONCAT(p.first_name,' ',p.last_name) = ir.technician_raw
                  OR CONCAT(p.last_name,' ',p.first_name) = ir.technician_raw )
             SET ir.technician_professional_id = p.id
             WHERE ir.technician_id IS NULL AND ir.technician_professional_id IS NULL");
    } catch (Throwable $e) { /* colonna assente: ignora */ }

    // Fascia: v1.7.89 prima l'alias specifico della COMMESSA (la stessa sigla può
    // valere diversamente per commessa), poi il nome, poi l'alias globale.
    try {
        $n['fasce'] += $pdo->exec(
            "UPDATE cm_intervention_reports ir JOIN cm_alias_band a
                ON a.raw_band = ir.band_raw AND a.project_id = ir.project_id AND a.project_id <> 0
             SET ir.band_id = a.band_id
             WHERE ir.band_id IS NULL AND a.ignored = 0 AND a.band_id IS NOT NULL");
    } catch (Throwable $e) { /* colonna project_id assente */ }
    $n['fasce'] += $pdo->exec(
        "UPDATE cm_intervention_reports ir JOIN cm_rate_bands b ON b.band_name = ir.band_raw
         SET ir.band_id = b.id WHERE ir.band_id IS NULL");
    $n['fasce'] += $pdo->exec(
        "UPDATE cm_intervention_reports ir JOIN cm_alias_band a ON a.raw_band = ir.band_raw
         SET ir.band_id = a.band_id
         WHERE ir.band_id IS NULL AND a.ignored = 0 AND a.band_id IS NOT NULL");

    // Ricalcolo dei costi/ricavi derivati dalla fascia (stessa regola di RateResolver:
    // regime Reperibilità con fallback su Ordinario se tariffa a zero/assente).
    // v1.7.89: priorità tariffa commessa+professionista → commessa → globale.
    $hasPbr = false;
    try { $hasPbr = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cm_project_band_rates'")->fetchColumn(); } catch (Throwable $e) {}

    foreach ([['Cliente','client_revenue_calc'], ['Aziendale','company_cost_calc'], ['Commerciale','commercial_cost_calc']] as [$type, $col]) {
        if ($hasPbr) {
            $st = $pdo->prepare(
                "UPDATE cm_intervention_reports ir
                   JOIN cm_rate_band_rates r_ord
                     ON r_ord.band_id = ir.band_id AND r_ord.cost_type = ? AND r_ord.regime = 'Ordinario'
                   LEFT JOIN cm_rate_band_rates r_rep
                     ON r_rep.band_id = ir.band_id AND r_rep.cost_type = ? AND r_rep.regime = 'Reperibilità'
                   LEFT JOIN cm_project_band_rates pp_ord
                     ON pp_ord.project_id = ir.project_id AND pp_ord.band_id = ir.band_id
                    AND pp_ord.professional_id = COALESCE(ir.technician_professional_id, 0)
                    AND pp_ord.professional_id <> 0
                    AND pp_ord.cost_type = ? AND pp_ord.regime = 'Ordinario'
                   LEFT JOIN cm_project_band_rates pp_rep
                     ON pp_rep.project_id = ir.project_id AND pp_rep.band_id = ir.band_id
                    AND pp_rep.professional_id = COALESCE(ir.technician_professional_id, 0)
                    AND pp_rep.professional_id <> 0
                    AND pp_rep.cost_type = ? AND pp_rep.regime = 'Reperibilità'
                   LEFT JOIN cm_project_band_rates pc_ord
                     ON pc_ord.project_id = ir.project_id AND pc_ord.band_id = ir.band_id
                    AND pc_ord.professional_id = 0 AND pc_ord.cost_type = ? AND pc_ord.regime = 'Ordinario'
                   LEFT JOIN cm_project_band_rates pc_rep
                     ON pc_rep.project_id = ir.project_id AND pc_rep.band_id = ir.band_id
                    AND pc_rep.professional_id = 0 AND pc_rep.cost_type = ? AND pc_rep.regime = 'Reperibilità'
                 SET ir.`$col` = ROUND(ir.quantity_hours *
                       IF(ir.on_call = 1,
                          COALESCE(NULLIF(pp_rep.rate_hour,0), NULLIF(pc_rep.rate_hour,0), NULLIF(r_rep.rate_hour,0),
                                   pp_ord.rate_hour, pc_ord.rate_hour, r_ord.rate_hour),
                          COALESCE(pp_ord.rate_hour, pc_ord.rate_hour, r_ord.rate_hour)), 2)
                 WHERE ir.band_id IS NOT NULL");
            $st->execute([$type, $type, $type, $type, $type, $type]);
        } else {
            $st = $pdo->prepare(
                "UPDATE cm_intervention_reports ir
                   JOIN cm_rate_band_rates r_ord
                     ON r_ord.band_id = ir.band_id AND r_ord.cost_type = ? AND r_ord.regime = 'Ordinario'
                   LEFT JOIN cm_rate_band_rates r_rep
                     ON r_rep.band_id = ir.band_id AND r_rep.cost_type = ? AND r_rep.regime = 'Reperibilità'
                 SET ir.`$col` = ROUND(ir.quantity_hours *
                       IF(ir.on_call = 1, COALESCE(NULLIF(r_rep.rate_hour, 0), r_ord.rate_hour), r_ord.rate_hour), 2)
                 WHERE ir.band_id IS NOT NULL");
            $st->execute([$type, $type]);
        }
        $n['ricalcolate'] += $st->rowCount();
    }
    return $n;
}

// ─────────────────────────── POST (PRG, prima di header.php) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (UploadGuard::postDiscarded()) { $_SESSION['flash_msg'] = UploadGuard::discardedMessage(); redirect_self(); }
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_map') {
        $saved = 0;
        foreach (AliasStore::types() as $t) {
            foreach (($_POST['map'][$t] ?? []) as $raw => $val) {
                $raw = (string)$raw; $val = trim((string)$val);
                if ($val === '') continue;
                if ($val === 'IGNORA') { $alias->set($t, $raw, null, true, $u_id); $saved++; }
                elseif (ctype_digit($val)) { $alias->set($t, $raw, (int)$val, false, $u_id); $saved++; }
                // v1.7.88: mappatura del tecnico su un PROFESSIONISTA (chiave 'P<id>')
                elseif ($t === AliasStore::T_TECHNICIAN && preg_match('/^P(\d+)$/', $val, $mm)) {
                    try {
                        $pdo->prepare(
                            "INSERT INTO cm_alias_technician (raw_name, employee_id, professional_id, ignored, created_by)
                             VALUES (?, NULL, ?, 0, ?)
                             ON DUPLICATE KEY UPDATE employee_id = NULL, professional_id = VALUES(professional_id), ignored = 0"
                        )->execute([$raw, (int)$mm[1], $u_id]);
                        $saved++;
                    } catch (Throwable $e) { /* colonna assente */ }
                }
            }
        }
        // v1.7.89: mappature di fascia specifiche per singola commessa
        foreach (($_POST['mapbp'] ?? []) as $raw => $perProj) {
            foreach ((array)$perProj as $projId => $val) {
                $val = trim((string)$val); $projId = (int)$projId;
                if ($val === '' || $projId <= 0) continue;
                $bandId = ctype_digit($val) ? (int)$val : null;
                $ign    = ($val === 'IGNORA') ? 1 : 0;
                if ($bandId === null && !$ign) continue;
                try {
                    $pdo->prepare(
                        "INSERT INTO cm_alias_band (raw_band, project_id, band_id, ignored, created_by)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE band_id = VALUES(band_id), ignored = VALUES(ignored)"
                    )->execute([(string)$raw, $projId, $bandId, $ign, $u_id]);
                    $saved++;
                } catch (Throwable $e) { /* colonna project_id assente */ }
            }
        }
        $r = cm_reapply($pdo);
        write_log('Interventions','success',"Riconciliazione: $saved mappature salvate; riapplicate commesse={$r['commesse']} tecnici={$r['tecnici']} fasce={$r['fasce']}",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>$saved mappature salvate. Righe aggiornate: "
            . "{$r['commesse']} commesse, {$r['tecnici']} tecnici, {$r['fasce']} fasce.</div>";
        redirect_self();
    }

    if ($action === 'reapply') {
        $r = cm_reapply($pdo);
        write_log('Interventions','success',"Riapplicazione risoluzione: commesse={$r['commesse']} tecnici={$r['tecnici']} fasce={$r['fasce']}",$u_id);
        $_SESSION['flash_msg'] = "<div class='alert alert-success'>Riapplicazione completata: {$r['commesse']} commesse, "
            . "{$r['tecnici']} tecnici, {$r['fasce']} fasce risolte; {$r['ricalcolate']} valori ricalcolati.</div>";
        redirect_self();
    }

    if ($action === 'import_map') {
        try {
            if ($err = UploadGuard::fileError($_FILES['file'] ?? null)) throw new Exception($err);
            $tmp = $_FILES['file']['tmp_name'];
            $saved = 0; $skipped = 0;
            foreach ([AliasStore::T_PROJECT => 'Commesse', AliasStore::T_TECHNICIAN => 'Tecnici', AliasStore::T_BAND => 'Fasce'] as $t => $sheet) {
                for ($idx = 0; $idx < 3; $idx++) {
                    $h = [];
                    try {
                        $rows = XlsxReader::read($tmp, $idx, ['header_hints' => ['tipo','valore_grezzo','mappa_a_id','ignora']]);
                    } catch (Throwable $e) { continue; }
                    foreach ($rows['rows'] as $r) {
                        $tipo = XlsxReader::norm((string)($r['tipo'] ?? ''));
                        if ($tipo !== $t) continue;
                        $raw = trim((string)($r['valore_grezzo'] ?? ''));
                        if ($raw === '') { $skipped++; continue; }
                        $ign = in_array(strtolower(trim((string)($r['ignora'] ?? ''))), ['1','si','sì','x','true','vero'], true);
                        $tid = trim((string)($r['mappa_a_id'] ?? ''));
                        if ($ign) { $alias->set($t, $raw, null, true, $u_id); $saved++; }
                        elseif ($tid !== '' && ctype_digit($tid)) { $alias->set($t, $raw, (int)$tid, false, $u_id); $saved++; }
                        // v1.7.88: id professionista nella forma 'P<id>'
                        elseif ($t === AliasStore::T_TECHNICIAN && preg_match('/^P(\d+)$/i', $tid, $mm)) {
                            try {
                                $pdo->prepare(
                                    "INSERT INTO cm_alias_technician (raw_name, employee_id, professional_id, ignored, created_by)
                                     VALUES (?, NULL, ?, 0, ?)
                                     ON DUPLICATE KEY UPDATE employee_id = NULL, professional_id = VALUES(professional_id), ignored = 0"
                                )->execute([$raw, (int)$mm[1], $u_id]);
                                $saved++;
                            } catch (Throwable $e) { $skipped++; }
                        }
                        else $skipped++;
                    }
                    break; // un solo foglio per tipo
                }
            }
            $r = cm_reapply($pdo);
            write_log('Interventions','success',"Import mappature: $saved salvate, $skipped ignorate",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Mappature importate: $saved salvate, $skipped senza indicazione. "
                . "Righe aggiornate: {$r['commesse']} commesse, {$r['tecnici']} tecnici, {$r['fasce']} fasce.</div>";
        } catch (Throwable $e) {
            $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Errore import mappature: " . h($e->getMessage()) . "</div>";
        }
        redirect_self();
    }
    redirect_self();
}

// ─────────────────────────── Export XLSX (GET, prima di header.php) ───────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    if (!can('export', 'import_control.php') && !$can_edit) { redirect_self(); }
    require_once(__DIR__ . '/XlsxWriter.php');
    $projects  = $pdo->query("SELECT id, CONCAT(project_code,' — ',name) AS l FROM cm_projects ORDER BY project_code")->fetchAll(PDO::FETCH_KEY_PAIR);
    $tech_kinds = [];
    $employees = cm_tech_candidates($pdo, $tech_kinds); // v1.7.88: include i professionisti
    $bands     = $pdo->query("SELECT id, band_name FROM cm_rate_bands ORDER BY band_name")->fetchAll(PDO::FETCH_KEY_PAIR);

    $w = new XlsxWriter();
    $sheets = [
        AliasStore::T_PROJECT    => ['Commesse non risolte', $projects],
        AliasStore::T_TECHNICIAN => ['Tecnici non risolti',  $employees],
        AliasStore::T_BAND       => ['Fasce non risolte',    $bands],
    ];
    foreach ($sheets as $type => [$title, $cands]) {
        $rows = [['tipo','valore_grezzo','occorrenze','dal','al','suggerimento','suggerimento_id','mappa_a_id','ignora']];
        foreach (cm_unresolved($pdo, $type, 5000) as $u) {
            $s = cm_suggest((string)$u['raw_value'], $cands);
            $rows[] = [$type, $u['raw_value'], (int)$u['occorrenze'], $u['dal'], $u['al'],
                       $s['label'] ? $s['label'] . ' (' . $s['score'] . '%)' : '', $s['id'] ?? '', '', ''];
        }
        $w->addSheet($title, $rows);
    }
    // fogli di riferimento con gli ID da usare in "mappa_a_id"
    $ref = [['id','commessa']]; foreach ($projects as $id => $l) $ref[] = [$id, $l];
    $w->addSheet('Rif. commesse', $ref);
    $ref = [['id','risorsa','tipo']]; foreach ($employees as $id => $l) $ref[] = [(string)$id, $l, ($tech_kinds[(string)$id] ?? 'dipendente')];
    $w->addSheet('Rif. dipendenti', $ref);
    $ref = [['id','fascia']]; foreach ($bands as $id => $l) $ref[] = [$id, $l];
    $w->addSheet('Rif. fasce', $ref);

    write_log('Interventions','info','Export anomalie import rapporti',$u_id);
    $w->download('controllo_import_rapporti_' . date('Ymd_His') . '.xlsx');
    exit;
}

// ─────────────────────────── Vista ───────────────────────────
$tot = $pdo->query(
    "SELECT COUNT(*) tot,
            SUM(project_id IS NULL) no_proj,
            SUM(technician_id IS NULL) no_tech,
            SUM(band_id IS NULL) no_band
     FROM cm_intervention_reports")->fetch(PDO::FETCH_ASSOC);

$unres = [];
foreach (AliasStore::types() as $t) $unres[$t] = cm_unresolved($pdo, $t, 200);
$verify = cm_tech_verify($pdo, 5000); // v1.7.85: verifica tecnici vs Dipendenti + Professionisti

$projects  = $pdo->query("SELECT id, CONCAT(project_code,' — ',name) AS l FROM cm_projects ORDER BY project_code")->fetchAll(PDO::FETCH_KEY_PAIR);
$tech_kinds = [];
$employees = cm_tech_candidates($pdo, $tech_kinds); // v1.7.88: include i professionisti
$bands     = $pdo->query("SELECT id, band_name FROM cm_rate_bands ORDER BY band_name")->fetchAll(PDO::FETCH_KEY_PAIR);
$batches   = $pdo->query("SELECT * FROM cm_import_batches ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
require_once('header.php');

$cands = [AliasStore::T_PROJECT => $projects, AliasStore::T_TECHNICIAN => $employees, AliasStore::T_BAND => $bands];
$titles = [AliasStore::T_PROJECT => 'Commesse non risolte', AliasStore::T_TECHNICIAN => 'Tecnici non risolti', AliasStore::T_BAND => 'Fasce non risolte'];
$pct = fn($p, $t) => $t > 0 ? round($p / $t * 100, 1) : 0;
?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start">
  <div>
    <h1><i class="fa-solid fa-clipboard-check"></i> Controllo & Riconciliazione import</h1>
    <p style="color:var(--muted);font-size:13px">Anomalie raggruppate per valore grezzo. Le mappature sono persistenti: valgono anche per i prossimi import.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn btn-sm" href="<?= url_safe('import_control', ['export' => 'xlsx']) ?>"><i class="fa-solid fa-file-export"></i> Esporta anomalie (XLSX)</a>
    <?php if ($can_edit): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Riapplicare la risoluzione a tutte le righe non risolte?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="reapply">
      <button class="btn btn-sm btn-primary"><i class="fa-solid fa-rotate"></i> Riapplica risoluzione</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?= $msg ?>

<div class="card" style="margin-bottom:14px">
  <table class="data-table" style="width:100%">
    <thead><tr><th>Rapporti in archivio</th><th>Senza commessa</th><th>Senza tecnico</th><th>Senza fascia</th></tr></thead>
    <tbody><tr>
      <td><strong><?= (int)$tot['tot'] ?></strong></td>
      <td><span style="color:<?= $tot['no_proj'] ? '#dc2626' : '#16a34a' ?>;font-weight:600"><?= (int)$tot['no_proj'] ?></span>
          <small style="color:var(--muted)">(<?= $pct((int)$tot['no_proj'], (int)$tot['tot']) ?>%)</small></td>
      <td><span style="color:<?= $tot['no_tech'] ? '#dc2626' : '#16a34a' ?>;font-weight:600"><?= (int)$tot['no_tech'] ?></span>
          <small style="color:var(--muted)">(<?= $pct((int)$tot['no_tech'], (int)$tot['tot']) ?>%)</small></td>
      <td><span style="color:<?= $tot['no_band'] ? '#dc2626' : '#16a34a' ?>;font-weight:600"><?= (int)$tot['no_band'] ?></span>
          <small style="color:var(--muted)">(<?= $pct((int)$tot['no_band'], (int)$tot['tot']) ?>%)</small></td>
    </tr></tbody>
  </table>
</div>

<?php
$vs = $verify['summary'];
$vs_tot = array_sum($vs);
$risolvibili = $vs['dipendente'] + $vs['professionista_unito'];
if ($vs_tot > 0):
?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="fa-solid fa-user-check"></i> Verifica anagrafiche (Dipendenti + Professionisti)</span>
    <?php if ($can_edit && $risolvibili > 0): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Allineare i tecnici risolvibili dalle anagrafiche?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="reapply">
      <button class="btn btn-sm btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Allinea ora (<?= $risolvibili ?>)</button>
    </form>
    <?php endif; ?>
  </div>
  <p style="color:var(--muted);font-size:12px;margin:6px 0 12px">Confronto dei tecnici non risolti con l'anagrafica Dipendenti aggiornata e con l'anagrafica Professionisti, per allineare anche a posteriori i rapporti già importati.</p>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
    <?php foreach ([
        ['dipendente','Corrispondono a Dipendenti','#16a34a','fa-id-badge'],
        ['professionista_unito','Professionisti già collegati','#7c3aed','fa-link'],
        ['professionista_esterno','Professionisti esterni (da promuovere)','#0891b2','fa-user-tie'],
        ['nessuno','Senza corrispondenza','#94a3b8','fa-circle-question'],
    ] as [$k,$lbl,$c,$ic]): ?>
      <div style="text-align:center;padding:12px;background:#f8fafc;border-radius:8px;border-top:3px solid <?=$c?>">
        <div style="font-size:22px;font-weight:800;color:<?=$c?>"><?= $vs[$k] ?></div>
        <div style="font-size:10px;color:var(--muted);font-weight:700"><i class="fa-solid <?=$ic?>"></i> <?=$lbl?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($vs['professionista_esterno'] > 0): ?>
    <p style="font-size:12px;color:#0e7490;background:#cffafe;border:1px solid #a5f3fc;border-radius:8px;padding:10px;margin-top:12px">
      <i class="fa-solid fa-circle-info"></i> <?= $vs['professionista_esterno'] ?> nomi corrispondono a <strong>professionisti esterni</strong> non presenti tra i dipendenti.
      Per agganciarne i rapporti, aprili in <a href="<?= url_safe('professionals', ['type' => 'esterni']) ?>">Anagrafica Professionisti</a> e usa <em>In Dipendenti</em> o <em>Unisci</em>, poi torna qui e premi <em>Allinea ora</em>.
    </p>
  <?php endif; ?>
  <?php
  $prev = array_values(array_filter($verify['items'], fn($i) => in_array($i['class'], ['dipendente','professionista_unito','professionista_esterno'], true)));
  if ($prev): ?>
  <details style="margin-top:10px">
    <summary style="cursor:pointer;font-size:12px;color:var(--muted)">Dettaglio corrispondenze (<?= count($prev) ?>)</summary>
    <table class="data-table" style="width:100%;font-size:12px;margin-top:8px">
      <thead><tr><th>Nome grezzo</th><th>Occorrenze</th><th>Corrispondenza</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($prev, 0, 200) as $it):
        $map = ['dipendente'=>['Dipendente','#16a34a'],'professionista_unito'=>['Professionista collegato','#7c3aed'],'professionista_esterno'=>['Professionista esterno','#0891b2']];
        [$tl,$tc] = $map[$it['class']]; ?>
        <tr><td style="font-weight:600"><?= h($it['raw']) ?></td><td><?= $it['occorrenze'] ?></td>
            <td><span style="color:<?=$tc?>;font-weight:700"><?= $tl ?></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-import"></i> Reimport delle mappature validate</span></div>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_map">
    <div class="form-group" style="margin:0"><label>File anomalie compilato (.xlsx)</label>
      <input type="file" name="file" accept=".xlsx" required>
      <small style="color:var(--muted)">Compilare <code>mappa_a_id</code> (ID dai fogli "Rif.") oppure <code>ignora</code> = 1. <?= UploadGuard::limitsNote() ?></small></div>
    <button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Importa mappature e riapplica</button>
  </form>
</div>
<?php endif; ?>

<form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save_map">
<?php foreach (AliasStore::types() as $t): $rows = $unres[$t]; ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title"><?= h($titles[$t]) ?> <small style="color:var(--muted)">(<?= count($rows) ?> valori distinti<?= count($rows) >= 200 ? ', primi 200' : '' ?>)</small></span></div>
  <?php if (!$rows): ?>
    <p style="color:#16a34a;padding:10px"><i class="fa-solid fa-check"></i> Nessuna anomalia.</p>
  <?php else: ?>
  <table class="data-table" style="width:100%">
    <thead><tr><th>Valore nel file</th><th>Occorrenze</th><th>Periodo</th><th>Suggerimento</th><th style="width:280px">Mappa a</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $s = cm_suggest((string)$r['raw_value'], $cands[$t]); ?>
      <tr>
        <td><code><?= h($r['raw_value']) ?></code></td>
        <td style="text-align:right"><strong><?= (int)$r['occorrenze'] ?></strong></td>
        <td style="color:var(--muted);font-size:11px"><?= h($r['dal'] ?? '—') ?> → <?= h($r['al'] ?? '—') ?></td>
        <td><?= $s['id'] ? '<span style="color:#0369a1">' . h($s['label']) . ' <small>(' . $s['score'] . '%)</small></span>' : '<span style="color:var(--muted)">—</span>' ?></td>
        <td>
          <select name="map[<?= h($t) ?>][<?= h($r['raw_value']) ?>]" <?= $can_edit ? '' : 'disabled' ?> style="width:100%">
            <option value="">— non mappare —</option>
            <option value="IGNORA">⊘ Ignora questo valore</option>
            <?php foreach ($cands[$t] as $id => $label):
              $sfx = ($t === AliasStore::T_TECHNICIAN && ($tech_kinds[(string)$id] ?? '') === 'esterno') ? '  [professionista esterno]' : ''; ?>
              <option value="<?= h((string)$id) ?>" <?= ((string)$s['id'] === (string)$id) ? 'selected' : '' ?>><?= h($label . $sfx) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <?php if ($t === AliasStore::T_BAND && $can_edit):
        $bp = cm_band_projects($pdo, (string)$r['raw_value']);
        if (count($bp) > 1 || (count($bp) === 1 && (int)$bp[0]['project_id'] > 0)): ?>
      <tr>
        <td colspan="5" style="background:#f8fafc;padding:6px 10px">
          <details>
            <summary style="cursor:pointer;font-size:12px;color:#0369a1">
              <i class="fa-solid fa-diagram-project"></i> Mappatura per singola commessa (<?= count($bp) ?>) —
              usa questa quando la stessa sigla vale diversamente a seconda della commessa
            </summary>
            <table style="width:100%;font-size:11px;margin-top:8px">
              <thead><tr><th style="text-align:left">Commessa</th><th style="text-align:right">Occorrenze</th><th style="width:260px">Mappa a (solo questa commessa)</th></tr></thead>
              <tbody>
              <?php foreach ($bp as $b): ?>
                <tr>
                  <td><code><?= h($b['codice']) ?></code> <span style="color:var(--muted)"><?= h(mb_strimwidth((string)($b['nome'] ?? ''), 0, 40, '…')) ?></span></td>
                  <td style="text-align:right"><strong><?= (int)$b['occorrenze'] ?></strong></td>
                  <td>
                    <select name="mapbp[<?= h($r['raw_value']) ?>][<?= (int)$b['project_id'] ?>]" style="width:100%">
                      <option value="">— usa la mappatura generale —</option>
                      <option value="IGNORA">⊘ Ignora su questa commessa</option>
                      <?php foreach ($cands[$t] as $id => $label): ?>
                        <option value="<?= (int)$id ?>"><?= h($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        </td>
      </tr>
      <?php endif; endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if ($can_edit): ?>
  <button class="btn btn-success" style="margin-bottom:16px"><i class="fa-solid fa-floppy-disk"></i> Salva mappature e riapplica</button>
<?php endif; ?>
</form>

<div class="card">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Ultimi import</span></div>
  <table class="data-table" style="width:100%">
    <thead><tr><th>#</th><th>File</th><th>Tipo</th><th>Righe</th><th>Importate</th><th>Non risolte</th><th>Data</th></tr></thead>
    <tbody>
    <?php if (!$batches): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:16px">Nessun import registrato.</td></tr>
    <?php else: foreach ($batches as $b): ?>
      <tr><td><?= (int)$b['id'] ?></td><td><?= h($b['filename']) ?></td><td><?= h($b['kind']) ?></td>
          <td style="text-align:right"><?= (int)$b['rows_total'] ?></td><td style="text-align:right"><?= (int)$b['rows_ok'] ?></td>
          <td style="text-align:right;color:<?= $b['rows_unmatched'] ? '#d97706' : 'inherit' ?>"><?= (int)$b['rows_unmatched'] ?></td>
          <td><?= h($b['created_at']) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php require_once('footer.php'); ?>
