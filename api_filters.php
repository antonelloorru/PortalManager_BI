<?php
/**
 * PortalManager — api_filters.php
 * Endpoint AJAX centralizzato per Select dipendenti (Scope Filtering).
 * Restituisce JSON con record filtrati per appartenenza.
 *
 * Uso: GET api_filters.php?entity=locations&company_id=3
 *      → [{"id":1,"label":"Sede Milano"},{"id":2,"label":"Sede Roma"}]
 *
 * v1.3.1: FIX — usa bootstrap unificato per ereditare la sessione hardened
 *               (cookie 'certV_sid'). Prima usava session_start() puro che
 *               non vedeva il cookie → 401 → cascade rotta.
 */
require_once __DIR__ . '/app/bootstrap.php';

// Solo utenti autenticati
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$entity = $_GET['entity'] ?? '';
$result = [];

try {
    switch ($entity) {

        // ── SEDI filtrate per AZIENDA ────────────────────────────
        case 'locations':
            $company_id = (int)($_GET['company_id'] ?? 0);
            if ($company_id > 0) {
                $s = $pdo->prepare("SELECT id, location_name AS label FROM company_locations WHERE company_id=? ORDER BY location_name");
                $s->execute([$company_id]);
            } else {
                $s = $pdo->query("SELECT cl.id, CONCAT(cl.location_name,' (',c.name,')') AS label FROM company_locations cl JOIN companies c ON cl.company_id=c.id ORDER BY c.name, cl.location_name");
            }
            $result = $s->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ── CERTIFICAZIONI filtrate per BRAND ────────────────────
        case 'certifications':
            $brand_id = (int)($_GET['brand_id'] ?? 0);
            if ($brand_id > 0) {
                $s = $pdo->prepare("SELECT c.id, c.name AS label FROM certifications c WHERE c.brand_id=? AND c.is_active=1 ORDER BY c.name");
                $s->execute([$brand_id]);
            } else {
                $s = $pdo->query("SELECT c.id, CONCAT(b.name,' — ',c.name) AS label FROM certifications c JOIN brands b ON c.brand_id=b.id WHERE c.is_active=1 ORDER BY b.name, c.name");
            }
            $result = $s->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ── ESAMI PIANIFICATI filtrati per DIPENDENTE ─────────────
        case 'exams':
            $employee_id = (int)($_GET['employee_id'] ?? 0);
            $where = "WHERE pe.status='planned'";
            $params = [];
            if ($employee_id > 0) {
                $where .= " AND pe.employee_id=?";
                $params[] = $employee_id;
            }
            $s = $pdo->prepare(
                "SELECT pe.id, CONCAT(c.name,' — ',DATE_FORMAT(pe.planned_date,'%d/%m/%Y')) AS label,
                        pe.planned_date, c.brand_id
                 FROM planned_exams pe
                 JOIN certifications c ON pe.certification_id=c.id
                 $where
                 ORDER BY pe.planned_date"
            );
            $s->execute($params);
            $result = $s->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ── DISTRIBUTORI filtrati per BRAND ───────────────────────
        case 'distributors':
            $brand_id = (int)($_GET['brand_id'] ?? 0);
            if ($brand_id > 0) {
                $s = $pdo->prepare(
                    "SELECT d.id, CONCAT(d.name,' (',d.type,')') AS label
                     FROM distributors d
                     JOIN brand_distributors bd ON bd.distributor_id=d.id
                     WHERE bd.brand_id=? AND d.status='active'
                     ORDER BY d.name"
                );
                $s->execute([$brand_id]);
            } else {
                $s = $pdo->query("SELECT id, CONCAT(name,' (',type,')') AS label FROM distributors WHERE status='active' ORDER BY name");
            }
            $result = $s->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ── DIPENDENTI filtrati per AZIENDA ──────────────────────
        case 'employees':
            $company_id = (int)($_GET['company_id'] ?? 0);
            $where = "WHERE e.status='active'";
            $params = [];
            if ($company_id > 0) {
                $where .= " AND e.company_id=?";
                $params[] = $company_id;
            }
            $s = $pdo->prepare("SELECT e.id, CONCAT(e.last_name,' ',e.first_name) AS label FROM employees e $where ORDER BY e.last_name, e.first_name");
            $s->execute($params);
            $result = $s->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ── POSIZIONI filtrate per BRAND ─────────────────────────
        case 'positions':
            $brand_id = (int)($_GET['brand_id'] ?? 0);
            $where = "WHERE jp.status IN('open','draft')";
            $params = [];
            if ($brand_id > 0) {
                $where .= " AND jp.brand_id=?";
                $params[] = $brand_id;
            }
            $s = $pdo->prepare("SELECT jp.id, jp.title AS label FROM job_positions jp $where ORDER BY jp.title");
            $s->execute($params);
            $result = $s->fetchAll(PDO::FETCH_ASSOC);
            break;

        // ── BRAND per una CERTIFICAZIONE (reverse lookup) ────────
        case 'brand_for_cert':
            $cert_id = (int)($_GET['certification_id'] ?? 0);
            if ($cert_id > 0) {
                $s = $pdo->prepare("SELECT b.id, b.name AS label FROM certifications c JOIN brands b ON c.brand_id=b.id WHERE c.id=?");
                $s->execute([$cert_id]);
                $result = $s->fetchAll(PDO::FETCH_ASSOC);
            }
            break;

        default:
            http_response_code(400);
            $result = ['error' => "Entità '$entity' non supportata"];
    }
} catch (PDOException $e) {
    http_response_code(500);
    $result = ['error' => $e->getMessage()];
}

echo json_encode($result);
