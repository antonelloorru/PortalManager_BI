<?php
/**
 * dgb_api.php — Endpoint JSON parametrizzato per Attività DGB (v1.8.8)
 *
 * GET dgb_api.php?action=<filters|table|kpi|charts|anomalies>
 *     &from=YYYY-MM-DD&to=YYYY-MM-DD&operator=<id>&status=<str>&contract=<id>
 *     &limit=<n>&offset=<n>
 *
 * Restituisce:
 *  - filters  : sorgenti dropdown (incaricati, stati)
 *  - table    : righe tabella (ID, SLA, ore, costi) + totale
 *  - kpi      : consuntivo vs pianificato + SLA aggregato
 *  - charts   : gauge (consuntivo/pianificato) + distribuzione carico con baseline
 *  - anomalies: ticket orfani, piani vuoti
 */
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/DgbModel.php');

if (!can('view', 'dgb_api.php') && !can('view', 'dgb_activities.php')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$model  = new DgbModel($pdo);
$f      = DgbModel::normFilters($_GET);
$action = $_GET['action'] ?? 'kpi';
$limit  = max(1, min(1000, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$gran   = ($_GET['gran'] ?? 'month') === 'day' ? 'day' : 'month';
$month  = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : '';

try {
    switch ($action) {
        case 'filters':
            $out = ['operators' => $model->operators(), 'statuses' => $model->statuses(),
                    'report_types' => ['STD', 'R_ANTEA'], 'modes' => ['sede', 'remoto']];
            break;
        case 'hours':
            $out = ['filters' => $f, 'hours_breakdown' => $model->hoursBreakdown($f)];
            break;
        case 'distribution':
            $out = ['filters' => $f, 'hours_breakdown' => $model->hoursBreakdown($f),
                    'temporal_distribution' => $model->temporalDistribution($f, $gran, $month)];
            break;
        case 'table':
            $out = ['filters' => $f, 'total' => $model->count($f),
                    'limit' => $limit, 'offset' => $offset,
                    'rows' => $model->table($f, $limit, $offset)];
            break;
        case 'kpi':
            $out = ['filters' => $f, 'kpi' => $model->kpi($f)];
            break;
        case 'charts':
            $out = ['filters' => $f] + $model->chartsJson($f, $gran, $month);
            break;
        case 'anomalies':
            $out = ['filters' => $f] + $model->anomalies($f);
            break;
        default:
            http_response_code(400);
            $out = ['error' => 'unknown action', 'actions' => ['filters','table','kpi','hours','distribution','charts','anomalies']];
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
