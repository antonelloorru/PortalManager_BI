<?php
/**
 * certV 2.4 — api_cert_search.php
 * Endpoint AJAX per ricerca smart certificazioni.
 * Supporta: codice esatto, nome completo, stringa parziale (LIKE), brand filter.
 *
 * GET ?q=AZ-305           → cerca per codice esatto poi per nome parziale
 * GET ?q=Azure&brand_id=1 → filtra anche per brand
 * GET ?brand_id=1          → tutte le cert attive del brand
 */
require_once __DIR__ . '/app/bootstrap.php';

if (empty($_SESSION['user_id'])) { http_response_code(403); echo '[]'; exit; }

header('Content-Type: application/json; charset=utf-8');

$q        = trim($_GET['q'] ?? '');
$brand_id = (int)($_GET['brand_id'] ?? 0);
$limit    = min(30, max(5, (int)($_GET['limit'] ?? 15)));

$results = [];

try {
    if ($q !== '') {
        // Step 1: match esatto per codice
        $where = ["c.is_active=1"];
        $params = [];

        if ($brand_id > 0) { $where[] = "c.brand_id=?"; $params[] = $brand_id; }

        // Cerca per codice esatto (priorità massima)
        $sql_code = "SELECT c.id, c.name, c.code, c.level, c.category, c.validity_months,
                            b.name AS brand_name
                     FROM certifications c
                     LEFT JOIN brands b ON c.brand_id=b.id
                     WHERE " . implode(' AND ', $where) . " AND c.code=?
                     LIMIT $limit";
        $s = $pdo->prepare($sql_code);
        $s->execute([...$params, $q]);
        $results = $s->fetchAll(PDO::FETCH_ASSOC);

        // Step 2: se pochi risultati, espandi con LIKE sul nome e codice
        if (count($results) < $limit) {
            $existing_ids = array_column($results, 'id');
            $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';

            $exclude = '';
            $extra_params = [...$params, $like, $like];
            if (!empty($existing_ids)) {
                $exclude = " AND c.id NOT IN(" . implode(',', array_fill(0, count($existing_ids), '?')) . ")";
                $extra_params = array_merge($extra_params, $existing_ids);
            }

            $sql_like = "SELECT c.id, c.name, c.code, c.level, c.category, c.validity_months,
                                b.name AS brand_name
                         FROM certifications c
                         LEFT JOIN brands b ON c.brand_id=b.id
                         WHERE " . implode(' AND ', $where) . "
                         AND (c.name LIKE ? OR c.code LIKE ?) $exclude
                         ORDER BY
                           CASE WHEN c.name LIKE ? THEN 0 ELSE 1 END,
                           b.name, c.name
                         LIMIT " . ($limit - count($results));
            $s2 = $pdo->prepare($sql_like);
            // Aggiungi il parametro per ORDER BY CASE
            $s2->execute([...$extra_params, $q . '%']);
            $results = array_merge($results, $s2->fetchAll(PDO::FETCH_ASSOC));
        }
    } else {
        // Nessuna query: lista filtrata per brand
        $where = ["c.is_active=1"];
        $params = [];
        if ($brand_id > 0) { $where[] = "c.brand_id=?"; $params[] = $brand_id; }

        $sql = "SELECT c.id, c.name, c.code, c.level, c.category, c.validity_months,
                       b.name AS brand_name
                FROM certifications c
                LEFT JOIN brands b ON c.brand_id=b.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY b.name, c.name LIMIT $limit";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $results = $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // Formatta label per autocomplete
    foreach ($results as &$r) {
        $label = '[' . ($r['brand_name'] ?? '?') . '] ' . $r['name'];
        if ($r['code']) $label .= ' (' . $r['code'] . ')';
        if ($r['level']) $label .= ' — ' . $r['level'];
        $r['label'] = $label;
        $r['ttl'] = $r['validity_months'] ? $r['validity_months'] . ' mesi' : 'Nessuna scadenza';
    }

    echo json_encode($results);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
