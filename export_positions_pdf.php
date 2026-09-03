<?php
/**
 * certV — export_positions_pdf.php
 * Genera una pagina HTML stampabile con una scheda formattata per ciascuna
 * posizione filtrata. L'utente clicca "Stampa / Salva PDF" per ottenere il PDF.
 *
 * Approccio "headless": niente librerie PDF lato server, niente dipendenze.
 * Il browser fa da motore di rendering (e tutti i browser moderni hanno
 * "Salva come PDF" come destinazione di stampa).
 *
 * FILTRI ACCETTATI (via GET, gli stessi di recruiting_posizioni.php):
 *   - f_st: status
 *   - f_br: brand_id
 *   - f_pr: priority
 *
 * PARAMETRO speciale:
 *   - id: stampa solo una specifica posizione (override dei filtri)
 *   - autoprint: 1 = apre la finestra di stampa al caricamento
 */
require_once __DIR__ . '/access_control.php';

if (!can('view', 'recruiting_posizioni.php') && (int)($_SESSION['role_id'] ?? 99) !== 1) {
    http_response_code(403);
    die('Permesso negato.');
}

// ─── Filtri / selezione ───────────────────────────────────────────
$single_id = (int)($_GET['id'] ?? 0);
$autoprint = !empty($_GET['autoprint']);

$where = [];
$params = [];

if ($single_id > 0) {
    $where[] = 'p.id = ?';
    $params[] = $single_id;
} else {
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
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT p.*,
               b.name AS brand_name,
               b.logo_path AS brand_logo,
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
    error_log('[export_positions_pdf] ' . $e->getMessage());
    $debug = (class_exists('Env') && Env::isDebug()) || ((int)($_SESSION['role_id'] ?? 99) === 1);
    $msg = 'Errore lettura dati. Riprova o contatta l\'amministratore.';
    if ($debug) {
        $msg .= "\n\n[Debug — visibile solo a Super Admin]\n" . $e->getMessage();
    }
    die(nl2br(htmlspecialchars($msg)));
}

if (empty($positions)) {
    die('<div style="font-family:sans-serif;padding:40px;text-align:center">Nessuna posizione trovata.</div>');
}

$status_label = [
    'draft'     => 'Bozza',     'open'      => 'Aperta',
    'paused'    => 'In pausa',  'closed'    => 'Chiusa',
    'cancelled' => 'Annullata',
];
$status_color = [
    'draft'     => '#64748b',   'open'      => '#10b981',
    'paused'    => '#f59e0b',   'closed'    => '#0ea5e9',
    'cancelled' => '#ef4444',
];
$priority_color = [
    'Bassa' => '#94a3b8', 'Media' => '#3b82f6',
    'Alta'  => '#f59e0b', 'Urgente' => '#ef4444',
];

$settings = function_exists('load_settings') ? load_settings() : [];
$app_name = $settings['app_name'] ?? 'certV';
$primary  = $settings['primary_color'] ?? '#0ea5e9';

if (function_exists('write_log')) {
    write_log('Recruiting', 'info',
        'Export PDF posizioni: ' . count($positions) . ' record',
        $_SESSION['user_id'] ?? null);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Posizioni aperte — Stampa</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Helvetica', 'Arial', sans-serif;
    background: #f1f5f9;
    color: #1e293b;
    line-height: 1.5;
    font-size: 11pt;
}
/* ── Toolbar (NON stampata) ── */
.toolbar {
    position: sticky;
    top: 0;
    background: #0f172a;
    color: #fff;
    padding: 14px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
    z-index: 100;
    flex-wrap: wrap;
    gap: 10px;
}
.toolbar .info {
    font-size: 13px;
    color: #cbd5e1;
}
.toolbar .info strong { color: #fff; }
.toolbar .actions {
    display: flex;
    gap: 8px;
}
.toolbar button, .toolbar a {
    padding: 8px 16px;
    background: <?= htmlspecialchars($primary) ?>;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-family: inherit;
}
.toolbar button:hover, .toolbar a:hover { filter: brightness(1.1); }
.toolbar a.back {
    background: transparent;
    border: 1px solid #475569;
    color: #cbd5e1;
}
.toolbar a.back:hover { background: #1e293b; }

/* ── Schede posizione ── */
.cards-container {
    max-width: 210mm;
    margin: 24px auto;
    padding: 0 16px;
}
.card {
    background: #fff;
    border-radius: 12px;
    padding: 30px 36px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
    page-break-after: always;
    page-break-inside: avoid;
}
.card:last-child { page-break-after: auto; }

.card-head {
    border-bottom: 3px solid <?= htmlspecialchars($primary) ?>;
    padding-bottom: 16px;
    margin-bottom: 22px;
}
.card-app {
    font-size: 11px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
    margin-bottom: 6px;
}
.card-title {
    font-size: 22pt;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    margin-bottom: 8px;
}
.card-id {
    font-size: 10pt;
    color: #64748b;
    font-family: 'Courier New', monospace;
}

.card-meta {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px 24px;
    margin-bottom: 20px;
    padding: 14px 18px;
    background: #f8fafc;
    border-radius: 8px;
    border-left: 4px solid <?= htmlspecialchars($primary) ?>;
}
.meta-item { font-size: 10pt; }
.meta-item .lbl {
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 8pt;
    letter-spacing: 0.5px;
}
.meta-item .val {
    color: #0f172a;
    font-weight: 600;
    margin-top: 2px;
}

.badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}
.badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 14px;
    font-size: 9pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #fff;
}

.section {
    margin-bottom: 18px;
    page-break-inside: avoid;
}
.section h2 {
    font-size: 12pt;
    font-weight: 700;
    color: <?= htmlspecialchars($primary) ?>;
    text-transform: uppercase;
    letter-spacing: 1px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 4px;
    margin-bottom: 8px;
}
.section .body {
    font-size: 10.5pt;
    color: #334155;
    line-height: 1.6;
    white-space: pre-wrap;
    word-wrap: break-word;
}
.section.empty .body {
    color: #94a3b8;
    font-style: italic;
}

.card-foot {
    margin-top: 24px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    font-size: 9pt;
    color: #94a3b8;
}

/* ── Stile stampa ── */
@media print {
    body { background: #fff; }
    .toolbar, .no-print { display: none !important; }
    .cards-container { margin: 0; padding: 0; max-width: none; }
    .card {
        margin: 0;
        padding: 18mm 16mm;
        box-shadow: none;
        border-radius: 0;
        min-height: 0;
    }
    @page {
        size: A4;
        margin: 0;
    }
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <div class="info">
        📄 <strong><?= count($positions) ?></strong> posizioni pronte per la stampa &nbsp;·&nbsp;
        <span style="font-size:11px;color:#94a3b8">Clicca "Stampa / Salva PDF" e seleziona "Salva come PDF" come destinazione</span>
    </div>
    <div class="actions">
        <a href="<?= function_exists('url_safe') ? url_safe('recruiting_posizioni') : 'recruiting_posizioni.php' ?>" class="back">
            &larr; Indietro
        </a>
        <button type="button" onclick="window.print()">
            🖨 Stampa / Salva PDF
        </button>
    </div>
</div>

<div class="cards-container">

<?php foreach ($positions as $p):
    $sc = $status_color[$p['status']] ?? '#64748b';
    $pc = $priority_color[$p['priority']] ?? '#94a3b8';
?>
<div class="card">
    <div class="card-head">
        <div class="card-app"><?= htmlspecialchars($app_name) ?> &nbsp;·&nbsp; Scheda Posizione</div>
        <div class="card-title"><?= htmlspecialchars($p['title'] ?? '—') ?></div>
        <div class="card-id">ID: #<?= (int)$p['id'] ?>
            <?php if ($p['brand_name']): ?>&nbsp;·&nbsp; <?= htmlspecialchars($p['brand_name']) ?><?php endif; ?>
            <?php if ($p['department']): ?>&nbsp;·&nbsp; <?= htmlspecialchars($p['department']) ?><?php endif; ?>
        </div>
    </div>

    <div class="badges">
        <span class="badge" style="background:<?= $sc ?>"><?= htmlspecialchars($status_label[$p['status']] ?? $p['status']) ?></span>
        <span class="badge" style="background:<?= $pc ?>">Priorità: <?= htmlspecialchars($p['priority']) ?></span>
        <?php if ($p['contract_type']): ?>
        <span class="badge" style="background:#1e293b"><?= htmlspecialchars($p['contract_type']) ?></span>
        <?php endif; ?>
        <?php if ($p['remote_policy']): ?>
        <span class="badge" style="background:#475569"><?= htmlspecialchars($p['remote_policy']) ?></span>
        <?php endif; ?>
    </div>

    <div class="card-meta">
        <div class="meta-item">
            <div class="lbl">Sede</div>
            <div class="val"><?= htmlspecialchars($p['location'] ?: '—') ?></div>
        </div>
        <div class="meta-item">
            <div class="lbl">Team Leader</div>
            <div class="val"><?= htmlspecialchars(trim($p['team_leader_name']) ?: '—') ?></div>
        </div>
        <div class="meta-item">
            <div class="lbl">RAL</div>
            <div class="val">
                <?php if ($p['ral_min'] !== null && $p['ral_max'] !== null): ?>
                    € <?= number_format((float)$p['ral_min'], 0, ',', '.') ?> –
                    € <?= number_format((float)$p['ral_max'], 0, ',', '.') ?>
                <?php elseif ($p['ral_min'] !== null): ?>
                    da € <?= number_format((float)$p['ral_min'], 0, ',', '.') ?>
                <?php elseif ($p['ral_max'] !== null): ?>
                    fino a € <?= number_format((float)$p['ral_max'], 0, ',', '.') ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </div>
        </div>
        <div class="meta-item">
            <div class="lbl">Candidati ricevuti</div>
            <div class="val"><?= (int)$p['applications_count'] ?></div>
        </div>
        <div class="meta-item">
            <div class="lbl">Posizioni attese</div>
            <div class="val">
                <?= (int)($p['positions_expected'] ?? 1) ?>
                <?php if ((int)($p['positions_expected'] ?? 1) > 1): ?>
                    <span style="font-size:9pt;color:#64748b;font-weight:400">figure</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($p['clients_list'])): ?>
        <div class="meta-item" style="grid-column: span 2; background: #eff6ff; padding: 8px 12px; border-left: 3px solid #2563eb; border-radius: 4px">
            <div class="lbl" style="color: #1e40af">🏢 Clienti per cui è aperta la selezione</div>
            <div class="val" style="color: #1e3a8a"><?= htmlspecialchars($p['clients_list']) ?></div>
        </div>
        <?php endif; ?>
        <div class="meta-item">
            <div class="lbl">Aperta il</div>
            <div class="val"><?= $p['opened_at'] ? date('d/m/Y', strtotime($p['opened_at'])) : '—' ?></div>
        </div>
        <div class="meta-item">
            <div class="lbl">Target chiusura</div>
            <div class="val"><?= $p['target_date'] ? date('d/m/Y', strtotime($p['target_date'])) : '—' ?></div>
        </div>
    </div>

    <?php
    $sections = [
        'Presentazione'                  => $p['presentation_text'] ?? '',
        'Descrizione del ruolo'          => $p['description'] ?? '',
        'Hard skills richieste'          => $p['hard_skills'] ?? '',
        'Soft skills'                    => $p['soft_skills'] ?? '',
        'Requisiti'                      => $p['required_skills'] ?? '',
        'Nice to have'                   => $p['nice_to_have'] ?? '',
        'Benefits'                       => $p['benefits'] ?? '',
        'Cosa offriamo'                  => $p['we_offer'] ?? '',
        'Informazioni offerta'           => $p['offer_info'] ?? '',
    ];
    foreach ($sections as $title => $body):
        $body = trim((string)$body);
        if ($body === '') continue;
    ?>
    <div class="section">
        <h2><?= htmlspecialchars($title) ?></h2>
        <div class="body"><?= htmlspecialchars($body) ?></div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($p['gender_disclaimer'])): ?>
    <div class="section" style="margin-top:24px;padding:12px 16px;background:#f8fafc;border-radius:8px;border-left:3px solid #94a3b8">
        <div class="body" style="font-size:9pt;color:#64748b;font-style:italic">
            <?= htmlspecialchars($p['gender_disclaimer']) ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-foot">
        <div>Documento generato il <?= date('d/m/Y H:i') ?></div>
        <div><?= htmlspecialchars($app_name) ?> · Posizione #<?= (int)$p['id'] ?></div>
    </div>
</div>
<?php endforeach; ?>

</div>

<?php if ($autoprint): ?>
<script>
window.addEventListener('load', () => setTimeout(() => window.print(), 300));
</script>
<?php endif; ?>

</body>
</html>
