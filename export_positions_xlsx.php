<?php
/**
 * certV — export_positions_xlsx.php
 * Esporta le posizioni aperte filtrate in un file Excel (.xlsx).
 *
 * STRUTTURA DEL FILE:
 *   - Sheet "Indice": una riga per posizione con i campi tabellari principali
 *   - Sheet "<id> - <titolo>": dettaglio completo per ciascuna posizione
 *     con descrizione, requisiti, JD, presentation_text, ecc.
 *
 * FILTRI ACCETTATI (via GET):
 *   - f_st: status (draft|open|paused|closed|cancelled|all)
 *   - f_br: brand_id (int)
 *   - f_pr: priority (Bassa|Media|Alta|Urgente)
 *
 * Sicurezza:
 *   - Richiede login (vedi access_control.php)
 *   - Richiede permesso 'view' su recruiting_posizioni.php
 *   - Salta CSRF perché è una GET di sola lettura
 */
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/app/XlsxWriter.php';

if (!can('view', 'recruiting_posizioni.php') && (int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    die('Permesso negato.');
}

// ─── Costruzione query con filtri (uguale a recruiting_posizioni.php) ───
$where = [];
$params = [];

$f_st = (string)($_GET['f_st'] ?? 'all');
$f_br = (int)($_GET['f_br'] ?? 0);
$f_pr = (string)($_GET['f_pr'] ?? '');

$valid_status = ['draft', 'open', 'paused', 'closed', 'cancelled'];
if (in_array($f_st, $valid_status, true)) {
    $where[] = 'p.status = ?';
    $params[] = $f_st;
}

if ($f_br > 0) {
    $where[] = 'p.brand_id = ?';
    $params[] = $f_br;
}

$valid_priority = ['Bassa', 'Media', 'Alta', 'Urgente'];
if (in_array($f_pr, $valid_priority, true)) {
    $where[] = 'p.priority = ?';
    $params[] = $f_pr;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT p.*,
               b.name AS brand_name,
               CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS team_leader_name,
               (SELECT COUNT(*) FROM candidate_applications a WHERE a.position_id = p.id) AS applications_count,
               (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
                  FROM position_clients pc JOIN clients c ON c.id = pc.client_id
                 WHERE pc.position_id = p.id) AS clients_list
          FROM job_positions p
          LEFT JOIN brands b ON b.id = p.brand_id
          LEFT JOIN users u ON u.id = p.team_leader_id
          LEFT JOIN employees e ON e.id = u.employee_id
        $where_sql
        ORDER BY
            FIELD(p.status, 'open', 'draft', 'paused', 'closed', 'cancelled'),
            FIELD(p.priority, 'Urgente', 'Alta', 'Media', 'Bassa'),
            p.opened_at DESC, p.id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $positions = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[export_positions_xlsx] ' . $e->getMessage());
    http_response_code(500);
    $debug = (class_exists('Env') && Env::isDebug()) || ((int)($_SESSION['role_id'] ?? 99) === 1);
    $msg = 'Errore lettura dati. Riprova o contatta l\'amministratore.';
    if ($debug) {
        $msg .= "\n\n[Debug — visibile solo a Super Admin]\n" . $e->getMessage();
    }
    die(nl2br(htmlspecialchars($msg)));
}

if (empty($positions)) {
    http_response_code(404);
    die('Nessuna posizione corrisponde ai filtri selezionati.');
}

// ─── Costruzione XLSX ─────────────────────────────────────────────
$writer = new XlsxWriter();

// Mappature label
$status_label = [
    'draft'     => 'Bozza',
    'open'      => 'Aperta',
    'paused'    => 'In pausa',
    'closed'    => 'Chiusa',
    'cancelled' => 'Annullata',
];
$contract_label = [
    'Indeterminato'   => 'Indeterminato',
    'Determinato'     => 'Determinato',
    'Somministrazione'=> 'Somministrazione',
    'Consulenza'      => 'Consulenza',
    'Stage'           => 'Stage',
];

// ── Sheet 1: INDICE TABELLARE ─────────────────────────────────────
$indexRows = [
    [
        'ID', 'Titolo', 'Brand', 'Clienti', 'Dipartimento', 'Status', 'Priorità',
        'Posizioni attese', 'Tipo contratto', 'Sede', 'Modalità',
        'RAL min', 'RAL max', 'Benefits',
        'Team Leader', 'Candidati', 'Data apertura', 'Target chiusura',
        'Data chiusura'
    ],
];

foreach ($positions as $p) {
    $indexRows[] = [
        (int)$p['id'],
        $p['title'] ?? '',
        $p['brand_name'] ?? '—',
        $p['clients_list'] ?? '—',
        $p['department'] ?? '',
        $status_label[$p['status']] ?? $p['status'],
        $p['priority'] ?? '',
        (int)($p['positions_expected'] ?? 1),
        $contract_label[$p['contract_type']] ?? ($p['contract_type'] ?? ''),
        $p['location'] ?? '',
        $p['remote_policy'] ?? '',
        $p['ral_min'] !== null ? (float)$p['ral_min'] : '',
        $p['ral_max'] !== null ? (float)$p['ral_max'] : '',
        $p['benefits'] ?? '',
        trim($p['team_leader_name'] ?? '') ?: '—',
        (int)$p['applications_count'],
        $p['opened_at'] ? date('d/m/Y', strtotime($p['opened_at'])) : '',
        $p['target_date'] ? date('d/m/Y', strtotime($p['target_date'])) : '',
        $p['closed_at'] ? date('d/m/Y', strtotime($p['closed_at'])) : '',
    ];
}

$writer->addSheet('Indice posizioni', $indexRows);

// ── Sheet 2..N: DETTAGLIO PER POSIZIONE ──────────────────────────
foreach ($positions as $p) {
    $sheetName = '#' . (int)$p['id'] . ' - ' . preg_replace('/\s+/', ' ', (string)$p['title']);
    if (strlen($sheetName) > 31) $sheetName = substr($sheetName, 0, 28) . '...';

    $detailRows = [
        ['Campo', 'Valore'],
        ['ID Posizione', (int)$p['id']],
        ['Titolo', $p['title'] ?? ''],
        ['Brand', $p['brand_name'] ?? '—'],
        ['Clienti', $p['clients_list'] ?? '—'],
        ['Dipartimento', $p['department'] ?? ''],
        ['Status', $status_label[$p['status']] ?? $p['status']],
        ['Priorità', $p['priority'] ?? ''],
        ['Posizioni attese', (int)($p['positions_expected'] ?? 1)],
        ['Tipo contratto', $contract_label[$p['contract_type']] ?? ($p['contract_type'] ?? '')],
        ['Sede', $p['location'] ?? ''],
        ['Modalità lavoro', $p['remote_policy'] ?? ''],
        ['RAL min', $p['ral_min'] !== null ? (float)$p['ral_min'] : ''],
        ['RAL max', $p['ral_max'] !== null ? (float)$p['ral_max'] : ''],
        ['Team Leader', trim($p['team_leader_name'] ?? '') ?: '—'],
        ['Candidati ricevuti', (int)$p['applications_count']],
        ['Data apertura', $p['opened_at'] ? date('d/m/Y', strtotime($p['opened_at'])) : ''],
        ['Data target chiusura', $p['target_date'] ? date('d/m/Y', strtotime($p['target_date'])) : ''],
        ['Data chiusura effettiva', $p['closed_at'] ? date('d/m/Y', strtotime($p['closed_at'])) : ''],
        ['', ''],
        ['── DESCRIZIONE ──', ''],
        ['Descrizione', $p['description'] ?? ''],
        ['Presentazione', $p['presentation_text'] ?? ''],
        ['', ''],
        ['── REQUISITI ──', ''],
        ['Hard skills', $p['hard_skills'] ?? ''],
        ['Soft skills', $p['soft_skills'] ?? ''],
        ['Required skills', $p['required_skills'] ?? ''],
        ['Nice to have', $p['nice_to_have'] ?? ''],
        ['', ''],
        ['── OFFERTA ──', ''],
        ['Benefits', $p['benefits'] ?? ''],
        ['Cosa offriamo', $p['we_offer'] ?? ''],
        ['Info aggiuntive', $p['offer_info'] ?? ''],
        ['Disclaimer parità di genere', $p['gender_disclaimer'] ?? ''],
    ];

    $writer->addSheet($sheetName, $detailRows);
}

// ─── Download ─────────────────────────────────────────────────────
$filter_label = '';
if ($f_st !== 'all' && in_array($f_st, $valid_status, true)) $filter_label .= '_' . $f_st;
if ($f_br > 0) $filter_label .= '_brand' . $f_br;
if (in_array($f_pr, $valid_priority, true)) $filter_label .= '_' . $f_pr;

$filename = 'posizioni' . $filter_label . '_' . date('Ymd_Hi') . '.xlsx';

if (function_exists('write_log')) {
    write_log('Recruiting', 'info',
        'Export XLSX posizioni: ' . count($positions) . ' record',
        $_SESSION['user_id'] ?? null,
        ['filters' => compact('f_st', 'f_br', 'f_pr')]);
}

$writer->download($filename);
exit;
