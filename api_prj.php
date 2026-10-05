<?php
/**
 * api_prj.php — endpoint JSON dei Progetti PRJ (v1.10.01). Accesso diretto (non in Router::PAGES).
 *
 * Azioni:
 *   sp_search  ?q=&prj_id=  ricerca commesse SP per il selettore di collegamento (codice commessa, nome, cliente,
 *                           commercial_ref), esclusi i segnaposto DGB-. Richiede prj_link.php (edit).
 *   suggest    ?prj_id=     suggerimenti di collegamento con punteggio. Richiede prj_link.php (edit).
 * Ogni azione verifica il permesso della pagina chiamante; sola lettura, nessuna scrittura.
 */
declare(strict_types=1);
require_once('access_control.php');
require_once('functions.php');
require_once(__DIR__ . '/app/PrjLink.php');

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };

$action = (string)($_GET['action'] ?? '');
$lnk = new PrjLink($pdo);
try {
    switch ($action) {
        case 'sp_search':
            if (!can('edit', 'prj_link.php')) $out(['error' => 'forbidden'], 403);
            $out(['rows' => $lnk->search((string)($_GET['q'] ?? ''), 20)]);
        case 'suggest':
            if (!can('edit', 'prj_link.php')) $out(['error' => 'forbidden'], 403);
            $out(['rows' => $lnk->suggestions((int)($_GET['prj_id'] ?? 0))]);
        default:
            $out(['error' => 'azione non valida'], 400);
    }
} catch (Throwable $e) {
    write_log('Commesse', 'error', 'api_prj: ' . $e->getMessage(), (int)($_SESSION['user_id'] ?? 0));
    $out(['error' => 'errore interno'], 500);
}
