<?php
/**
 * certV 2.0 v2.2 — report_certificazioni.php
 * v2.2: uc.employee_id → employees, lista persone da employees
 * v1.9.80: pannello filtri come Relazione di Servizio IT (multi-select con ricerca, gruppi,
 *          badge filtri attivi) e 20 parametri filtrabili; stato calcolato dalla scadenza.
 */
require_once('access_control.php');
require_once(__DIR__ . '/app/RecycleBin.php');
// NB: header.php viene incluso DOPO i POST handler (save/delete) per non
// emettere output prima di redirect_self() — vedi fondo blocco PHP.

$u_role   = (int)($_SESSION['role_id'] ?? 99);
$u_id     = (int)$_SESSION['user_id'];
$u_emp_id = (int)($_SESSION['employee_id'] ?? 0);
$can_edit = can('edit');
$msg      = '';

// Dipendente vede solo il proprio (tramite employee_id)
$restrict_emp = ($u_role === 6) ? $u_emp_id : 0;

// ── Salvataggio ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['save_cert'])) {
    Csrf::verify();
    $id   = (int)$_POST['cert_id'];
    $exp  = $_POST['expiry_date'] ?: null;
    $note = $_POST['notes'] ?: null;
    $old  = $pdo->prepare("SELECT * FROM user_certifications WHERE id=?");
    $old->execute([$id]); $old_data = $old->fetch();
    if ($old_data) {
        $pdo->prepare("INSERT INTO brand_contacts_history (brand_id,archived_data,archived_by) VALUES (NULL,?,?)")
            ->execute([json_encode(['type'=>'uc_edit','old'=>$old_data]), $u_id]);
    }
    $pdo->prepare("UPDATE user_certifications SET expiry_date=?,status=?,notes=? WHERE id=?")
        ->execute([$exp, $exp ? cert_status_from_date($exp) : 'active', $note, $id]);
    write_log('Certifications','success',"Cert #$id aggiornata",$u_id);
    $msg = "<div class='alert alert-success'>Certificazione aggiornata.</div>";
}

// ── v1.7.20: Eliminazione certificazione caricata erroneamente ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['delete_cert'])) {
    Csrf::verify();
    $id = (int)$_POST['cert_id'];

    if ($id <= 0) {
        $msg = "<div class='alert alert-danger'>ID certificazione non valido.</div>";
    } else {
        // Carico la certificazione PRIMA di cancellare (per audit log e rimozione file)
        $stmt = $pdo->prepare(
            "SELECT uc.*, c.name AS cert_name, c.code AS cert_code,
                    e.first_name, e.last_name
               FROM user_certifications uc
               JOIN certifications c ON uc.certification_id = c.id
               JOIN employees e      ON uc.employee_id = e.id
              WHERE uc.id = ?"
        );
        $stmt->execute([$id]);
        $cert_data = $stmt->fetch();

        if (!$cert_data) {
            $msg = "<div class='alert alert-danger'>Certificazione non trovata.</div>";
        } else {
            // Dipendente (role 6) può eliminare SOLO le proprie certificazioni
            if ($u_role === 6 && (int)$cert_data['employee_id'] !== $u_emp_id) {
                $msg = "<div class='alert alert-danger'>Non autorizzato a eliminare questa certificazione.</div>";
                write_log('Certifications','warning',"Tentativo eliminazione non autorizzato cert #$id da utente $u_id",$u_id);
            } else {
                try {
                    $pdo->beginTransaction();

                    // 1) Audit log: salvo snapshot della cert eliminata
                    $pdo->prepare("INSERT INTO brand_contacts_history (brand_id,archived_data,archived_by) VALUES (NULL,?,?)")
                        ->execute([json_encode(['type'=>'uc_delete','data'=>$cert_data]), $u_id]);

                    // 2) Rimuovo file PDF allegato (se presente)
                    $doc_path = $cert_data['document_path'] ?? null;
                    if ($doc_path) {
                        $full_path = __DIR__ . '/' . ltrim($doc_path, '/');
                        if (is_file($full_path)) {
                            @unlink($full_path);
                        }
                    }

                    // 3) Cancello la riga
                    RecycleBin::capture($pdo, 'user_certifications', 'id=?', [$id], $u_id, 'report_certificazioni.php');

                    $pdo->commit();

                    $cert_label = $cert_data['cert_name'] . ($cert_data['cert_code'] ? ' (' . $cert_data['cert_code'] . ')' : '');
                    $emp_label  = $cert_data['last_name'] . ' ' . $cert_data['first_name'];
                    write_log('Certifications','success',"Cert #$id eliminata: $cert_label di $emp_label",$u_id);
                    $msg = "<div class='alert alert-success'><i class='fa-solid fa-trash'></i> Certificazione <strong>" . h($cert_label) . "</strong> di <strong>" . h($emp_label) . "</strong> eliminata con successo.</div>";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $msg = "<div class='alert alert-danger'>Errore durante l'eliminazione: " . h($e->getMessage()) . "</div>";
                    write_log('Certifications','error',"Errore eliminazione cert #$id: " . $e->getMessage(),$u_id);
                }
            }
        }
    }

    // PRG: redirect per evitare doppi submit
    if (function_exists('redirect_self')) {
        $_SESSION['flash_msg'] = $msg;
        redirect_self();
    }
}

// Recupero eventuale flash message dopo redirect
if (empty($msg) && !empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// ── Filtri (v1.9.80 — pannello come Relazione di Servizio IT) ─────────────────
// Multi-valore via GET (array o CSV), parametri preparati. Nomi storici f_br / f_us / f_st invariati.
require_once __DIR__ . '/app/PmFilter.php';
$F = [
    'q'        => trim((string)($_GET['q'] ?? '')),
    'f_br'     => PmFilter::values('f_br'),     // brand
    'f_tec'    => PmFilter::values('f_tec'),    // tecnologia
    'f_cat'    => PmFilter::values('f_cat'),    // categoria certificazione
    'f_lvl'    => PmFilter::values('f_lvl'),    // livello
    'f_cert'   => PmFilter::values('f_cert'),   // certificazione
    'f_plv'    => PmFilter::values('f_plv'),    // livello di partnership del brand
    'f_us'     => PmFilter::values('f_us'),     // collaboratore (employees.id)
    'f_az'     => PmFilter::values('f_az'),     // azienda
    'f_sede'   => PmFilter::values('f_sede'),   // sede
    'f_rep'    => PmFilter::values('f_rep'),    // reparto
    'f_job'    => PmFilter::values('f_job'),    // mansione
    'f_unit'   => PmFilter::values('f_unit'),   // unita organizzativa tecnica
    'f_empst'  => PmFilter::values('f_empst'),  // stato collaboratore
    'f_st'     => PmFilter::values('f_st'),     // stato certificazione (calcolato dalla scadenza)
    'iss_from' => PmFilter::date('iss_from'), 'iss_to' => PmFilter::date('iss_to'),
    'exp_from' => PmFilter::date('exp_from'), 'exp_to' => PmFilter::date('exp_to'),
    'exp_in'   => in_array((string)($_GET['exp_in'] ?? ''), ['30','60','90','180','365'], true) ? (int)$_GET['exp_in'] : 0,
    'f_perp'   => in_array((string)($_GET['f_perp'] ?? ''), ['1','0'], true) ? (string)$_GET['f_perp'] : '',
    'f_pdf'    => in_array((string)($_GET['f_pdf'] ?? ''), ['1','0'], true) ? (string)$_GET['f_pdf'] : '',
    'f_credly' => in_array((string)($_GET['f_credly'] ?? ''), ['1','0'], true) ? (string)$_GET['f_credly'] : '',
    'f_code'   => in_array((string)($_GET['f_code'] ?? ''), ['1','0'], true) ? (string)$_GET['f_code'] : '',
];
// compatibilita: i link storici arrivano senza stato collaboratore → tutti
$f_brands = $F['f_br']; $f_emps = $F['f_us']; $f_status = $F['f_st'];

// Stato calcolato dalla scadenza (stessa soglia di cert_status_from_date): lo stato
// memorizzato si aggiorna solo al salvataggio e invecchia.
$thr = (int)(load_settings()['notify_days_1'] ?? 90); if ($thr <= 0) $thr = 90;
$ST_SQL = "CASE WHEN uc.expiry_date IS NULL THEN 'active'
                WHEN uc.expiry_date < CURDATE() THEN 'expired'
                WHEN uc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $thr DAY) THEN 'expiring'
                ELSE 'active' END";
$UUID_RE = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';

$where  = ["1=1"]; $params = [];
if ($restrict_emp) { $where[] = "uc.employee_id=?"; $params[] = $restrict_emp; }
if ($F['q'] !== '') {
    $where[] = "(cert.name LIKE ? OR cert.code LIKE ? OR uc.certificate_code LIKE ? OR uc.notes LIKE ?
                 OR CONCAT_WS(' ', e.last_name, e.first_name) LIKE ? OR CONCAT_WS(' ', e.first_name, e.last_name) LIKE ?)";
    $lk = '%' . $F['q'] . '%'; array_push($params, $lk, $lk, $lk, $lk, $lk, $lk);
}
$where[] = PmFilter::in('cert.brand_id',        $F['f_br'],   $params);
$where[] = PmFilter::in('cert.technology_id',   $F['f_tec'],  $params);
$where[] = PmFilter::in("COALESCE(NULLIF(cert.category,''),'(n.d.)')", $F['f_cat'], $params);
$where[] = PmFilter::in("COALESCE(NULLIF(cert.level,''),'(n.d.)')",    $F['f_lvl'], $params);
$where[] = PmFilter::in('cert.id',              $F['f_cert'], $params);
$where[] = PmFilter::in("COALESCE(NULLIF(b.partnership_level,''),'(n.d.)')", $F['f_plv'], $params);
if (!$restrict_emp) {
    $where[] = PmFilter::in('uc.employee_id',   $F['f_us'],   $params);
    $where[] = PmFilter::in('e.company_id',     $F['f_az'],   $params);
    $where[] = PmFilter::in('e.location_id',    $F['f_sede'], $params);
    $where[] = PmFilter::in("COALESCE(dp.name, NULLIF(e.department,''), '(n.d.)')", $F['f_rep'], $params);
    $where[] = PmFilter::in("COALESCE(NULLIF(e.job_title,''),'(n.d.)')", $F['f_job'], $params);
    $where[] = PmFilter::in('e.status',         $F['f_empst'], $params);
    if ($F['f_unit']) {
        $where[] = "EXISTS (SELECT 1 FROM cm_tech_profiles tp WHERE tp.employee_id = e.id AND tp.is_active = 1 AND "
                 . PmFilter::in('tp.unit_id', $F['f_unit'], $params) . ")";
    }
}
$where[] = PmFilter::in("($ST_SQL)", $F['f_st'], $params);
$where[] = PmFilter::range('uc.issue_date',  $F['iss_from'], $F['iss_to'], $params);
$where[] = PmFilter::range('uc.expiry_date', $F['exp_from'], $F['exp_to'], $params);
if ($F['exp_in'])        { $where[] = "uc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)"; $params[] = $F['exp_in']; }
if ($F['f_perp'] !== '') $where[] = $F['f_perp'] === '1' ? "uc.expiry_date IS NULL" : "uc.expiry_date IS NOT NULL";
if ($F['f_pdf'] !== '')  $where[] = $F['f_pdf'] === '1' ? "COALESCE(uc.document_path,'') <> ''" : "COALESCE(uc.document_path,'') = ''";
if ($F['f_code'] !== '') $where[] = $F['f_code'] === '1' ? "COALESCE(uc.certificate_code,'') <> ''" : "COALESCE(uc.certificate_code,'') = ''";
if ($F['f_credly'] !== '') {
    // stesso criterio del link in tabella: badge (codice UUID) oppure profilo Credly del collaboratore
    $cr = "(uc.certificate_code REGEXP '$UUID_RE' OR COALESCE(e.credly_url,'') <> '')";
    $where[] = $F['f_credly'] === '1' ? $cr : "NOT $cr";
}
$where = array_values(array_filter($where, fn($w) => $w !== '1=1'));
if (!$where) $where = ['1=1'];

$sql = "SELECT uc.*, ($ST_SQL) AS status_eff, cert.name cert_name, cert.code cert_code, b.name brand_name,
               e.credly_url emp_credly_url, e.linkedin_url emp_linkedin_url,
               e.first_name, e.last_name, t.name tech_name
        FROM user_certifications uc
        JOIN certifications cert ON uc.certification_id = cert.id
        JOIN brands b            ON cert.brand_id = b.id
        JOIN employees e         ON uc.employee_id = e.id
        JOIN technologies t      ON cert.technology_id = t.id
        LEFT JOIN departments dp ON dp.id = e.department_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY uc.expiry_date ASC";
$s = $pdo->prepare($sql);
$s->execute($params);
$results = $s->fetchAll();

// ── Opzioni dei filtri (solo valori presenti nelle certificazioni) ─────────────
$opt = static function (PDO $pdo, string $sql): array {
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { return []; }
};
$BASE = "FROM user_certifications uc JOIN certifications cert ON cert.id = uc.certification_id
         JOIN brands b ON b.id = cert.brand_id JOIN employees e ON e.id = uc.employee_id
         LEFT JOIN departments dp ON dp.id = e.department_id";
$O = [
    'f_br'   => $opt($pdo, "SELECT DISTINCT b.id, b.name $BASE ORDER BY b.name"),
    'f_tec'  => $opt($pdo, "SELECT DISTINCT t.id, t.name $BASE JOIN technologies t ON t.id = cert.technology_id ORDER BY t.name"),
    'f_cat'  => $opt($pdo, "SELECT DISTINCT COALESCE(NULLIF(cert.category,''),'(n.d.)') k, COALESCE(NULLIF(cert.category,''),'(n.d.)') $BASE ORDER BY 1"),
    'f_lvl'  => $opt($pdo, "SELECT DISTINCT COALESCE(NULLIF(cert.level,''),'(n.d.)') k, COALESCE(NULLIF(cert.level,''),'(n.d.)') $BASE ORDER BY 1"),
    'f_cert' => $opt($pdo, "SELECT DISTINCT cert.id, CONCAT(cert.name, IF(COALESCE(cert.code,'')<>'', CONCAT(' (', cert.code, ')'), ''), ' · ', b.name) $BASE ORDER BY 2"),
    'f_plv'  => $opt($pdo, "SELECT DISTINCT COALESCE(NULLIF(b.partnership_level,''),'(n.d.)') k, COALESCE(NULLIF(b.partnership_level,''),'(n.d.)') $BASE ORDER BY 1"),
    'f_us'   => $opt($pdo, "SELECT DISTINCT e.id, CONCAT(e.last_name, ' ', e.first_name, IF(e.status <> 'active', CONCAT(' (', e.status, ')'), '')) $BASE ORDER BY 2"),
    'f_az'   => $opt($pdo, "SELECT DISTINCT c.id, c.name $BASE JOIN companies c ON c.id = e.company_id ORDER BY c.name"),
    'f_sede' => $opt($pdo, "SELECT DISTINCT l.id, l.location_name $BASE JOIN company_locations l ON l.id = e.location_id ORDER BY l.location_name"),
    'f_rep'  => $opt($pdo, "SELECT DISTINCT COALESCE(dp.name, NULLIF(e.department,''), '(n.d.)') k, COALESCE(dp.name, NULLIF(e.department,''), '(n.d.)') $BASE ORDER BY 1"),
    'f_job'  => $opt($pdo, "SELECT DISTINCT COALESCE(NULLIF(e.job_title,''),'(n.d.)') k, COALESCE(NULLIF(e.job_title,''),'(n.d.)') $BASE ORDER BY 1"),
    'f_unit' => $opt($pdo, "SELECT DISTINCT u.id, u.name $BASE JOIN cm_tech_profiles tp ON tp.employee_id = e.id AND tp.is_active = 1
                             JOIN cm_tech_units u ON u.id = tp.unit_id ORDER BY u.name"),
    'f_empst'=> $opt($pdo, "SELECT DISTINCT e.status, e.status $BASE ORDER BY 1"),
    'f_st'   => ['active' => 'Attiva', 'expiring' => 'In scadenza', 'expired' => 'Scaduta'],
];
$all_brands = []; foreach ($O['f_br'] as $k => $v) $all_brands[] = ['id' => $k, 'name' => $v];   // compatibilita

// riepilogo del perimetro filtrato
$kpi = ['tot' => count($results), 'active' => 0, 'expiring' => 0, 'expired' => 0, 'persone' => [], 'pdf' => 0];
foreach ($results as $r) {
    $kpi[$r['status_eff']] = ($kpi[$r['status_eff']] ?? 0) + 1;
    $kpi['persone'][$r['employee_id']] = true;
    if (!empty($r['document_path'])) $kpi['pdf']++;
}

/** Link che conserva i filtri correnti. */
$qsCert = function (array $over = []) use ($F): string {
    $p = [];
    foreach ($F as $k => $v) {
        if (is_array($v)) { if ($v) $p[$k] = implode(',', $v); }
        elseif ($v !== '' && $v !== null && $v !== 0) $p[$k] = $v;
    }
    return url_safe('report_certificazioni', array_merge($p, $over));
};

$edit_cert = null;
if (isset($_GET['edit'])) {
    $es = $pdo->prepare("SELECT uc.*,cert.name cert_name FROM user_certifications uc JOIN certifications cert ON uc.certification_id=cert.id WHERE uc.id=?");
    $es->execute([(int)$_GET['edit']]);
    $edit_cert = $es->fetch();
}

// Header incluso qui: tutti i POST handler (con eventuale redirect_self) sono già eseguiti
require_once('header.php');
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px"><i class="fa-solid fa-chart-pie" style="color:var(--p);margin-right:10px"></i>Report certificazioni</h1>
    <p style="color:var(--muted);font-size:13px"><?=count($results)?> record trovati</p>
  </div>
  <div style="display:flex;gap:8px" class="no-print">
    <button onclick="window.print()" class="btn btn-sm"><i class="fa-solid fa-print"></i></button>
    <?php if(check_ui_permission('upload_certificato.php')): ?>
    <a href="upload_certificato.php" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Aggiungi</a>
    <?php endif; ?>
  </div>
</div>

<?=$msg?>

<?php
  // v1.9.80 — pannello filtri uniformato alla Relazione di Servizio IT
  $attivi = 0;
  foreach ($F as $k => $v) $attivi += is_array($v) ? (count($v) > 0 ? 1 : 0) : (($v !== '' && $v !== null && $v !== 0) ? 1 : 0);
  $ms = function (string $k, string $lbl) use ($O, $F): string {
      $h = '<div class="form-group"><label>' . h($lbl) . ' <span class="pm-multi">(multipla)</span></label>'
         . '<select name="' . $k . '[]" multiple size="3" class="pm-ms" data-placeholder="Tutti">';
      foreach ($O[$k] as $v => $l) {
          $h .= '<option value="' . h((string)$v) . '"' . (in_array((string)$v, $F[$k], true) ? ' selected' : '') . '>' . h((string)$l) . '</option>';
      }
      return $h . '</select></div>';
  };
  $sn = function (string $k, string $lbl, string $si, string $no) use ($F): string {
      return '<div class="form-group"><label>' . h($lbl) . '</label><select name="' . $k . '" class="pm-ms">'
           . '<option value="">— tutte —</option>'
           . '<option value="1"' . ($F[$k] === '1' ? ' selected' : '') . '>' . h($si) . '</option>'
           . '<option value="0"' . ($F[$k] === '0' ? ' selected' : '') . '>' . h($no) . '</option></select></div>';
  };
?>
<details class="pm-panel no-print" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary>
    <i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?=$attivi?></span><?php endif; ?>
    <span class="pm-hint"><?=count($results)?> certificazioni · <?=count($kpi['persone'])?> collaboratori</span>
  </summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= function_exists('route_slug_field') ? route_slug_field() : (!empty($_GET['r']) ? '<input type="hidden" name="r" value="' . h((string)$_GET['r']) . '">' : '') ?>

      <div class="pm-group">
        <h4>Ricerca e date</h4>
        <div class="pm-grid-auto">
          <div class="form-group"><label>Cerca ovunque</label>
            <input type="text" name="q" value="<?=h($F['q'])?>" placeholder="certificazione, codice, collaboratore, note"></div>
          <div class="form-group"><label>Conseguita dal</label><input type="date" name="iss_from" value="<?=h((string)$F['iss_from'])?>"></div>
          <div class="form-group"><label>Conseguita al</label><input type="date" name="iss_to" value="<?=h((string)$F['iss_to'])?>"></div>
          <div class="form-group"><label>Scadenza dal</label><input type="date" name="exp_from" value="<?=h((string)$F['exp_from'])?>"></div>
          <div class="form-group"><label>Scadenza al</label><input type="date" name="exp_to" value="<?=h((string)$F['exp_to'])?>"></div>
          <div class="form-group"><label>Scade entro</label>
            <select name="exp_in" class="pm-ms"><option value="">— qualsiasi —</option>
              <?php foreach ([30, 60, 90, 180, 365] as $g): ?>
                <option value="<?=$g?>" <?=$F['exp_in'] === $g ? 'selected' : ''?>><?=$g?> giorni</option>
              <?php endforeach; ?></select></div>
        </div>
      </div>

      <div class="pm-group">
        <h4>Certificazione</h4>
        <div class="pm-grid-auto">
          <?= $ms('f_br', 'Brand / Vendor') ?>
          <?= $ms('f_plv', 'Partnership brand') ?>
          <?= $ms('f_tec', 'Tecnologia') ?>
          <?= $ms('f_cat', 'Categoria') ?>
          <?= $ms('f_lvl', 'Livello') ?>
          <?= $ms('f_cert', 'Certificazione') ?>
        </div>
      </div>

      <?php if (!$restrict_emp): ?>
      <div class="pm-group">
        <h4>Collaboratore</h4>
        <div class="pm-grid-auto">
          <?= $ms('f_us', 'Collaboratore') ?>
          <?= $ms('f_empst', 'Stato collaboratore') ?>
          <?= $ms('f_az', 'Azienda') ?>
          <?= $ms('f_sede', 'Sede') ?>
          <?= $ms('f_rep', 'Reparto') ?>
          <?= $ms('f_job', 'Mansione') ?>
          <?= $ms('f_unit', 'Unità tecnica') ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="pm-group">
        <h4>Stato ed evidenze</h4>
        <div class="pm-grid-auto">
          <?= $ms('f_st', 'Stato certificazione') ?>
          <?= $sn('f_perp', 'Validità', 'Perpetua (senza scadenza)', 'Con scadenza') ?>
          <?= $sn('f_pdf', 'Attestato PDF', 'Presente', 'Mancante') ?>
          <?= $sn('f_credly', 'Credly', 'Badge o profilo presente', 'Assente') ?>
          <?= $sn('f_code', 'Codice certificato', 'Presente', 'Mancante') ?>
        </div>
      </div>

      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('report_certificazioni')?>">Azzera</a>
        <button type="button" onclick="window.print()" class="btn btn-sm"><i class="fa-solid fa-print"></i> Stampa</button>
      </div>
    </form>
  </div>
</details>

<div class="no-print" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:0 0 14px">
  <?php foreach ([['Certificazioni', $kpi['tot'], '#334155', 'f_st', null], ['Attive', $kpi['active'], '#16a34a', 'f_st', 'active'],
                  ['In scadenza', $kpi['expiring'], '#d97706', 'f_st', 'expiring'], ['Scadute', $kpi['expired'], '#dc2626', 'f_st', 'expired'],
                  ['Collaboratori', count($kpi['persone']), '#2563eb', null, null], ['Con PDF', $kpi['pdf'], '#e11d48', null, null]] as [$l, $v, $c, $k, $val]): ?>
    <?php $inner = '<div style="font-size:20px;font-weight:800;color:' . $c . '">' . (int)$v . '</div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;font-weight:700">' . h($l) . '</div>'; ?>
    <?php if ($val !== null): ?>
      <a class="card" style="padding:10px 12px;border-top:3px solid <?=$c?>;text-decoration:none" href="<?=h($qsCert(['f_st' => $val]))?>" title="Filtra: <?=h($l)?>"><?=$inner?></a>
    <?php else: ?>
      <div class="card" style="padding:10px 12px;border-top:3px solid <?=$c?>"><?=$inner?></div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if($edit_cert && $can_edit): ?>
<div class="card" style="margin-bottom:20px;border-color:var(--p)">
  <div class="card-header"><span class="card-title">Modifica scadenza — <?=h($edit_cert['cert_name'])?></span></div>
  <form method="POST" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
            <?= csrf_field() ?>
    <input type="hidden" name="save_cert" value="1">
    <input type="hidden" name="cert_id" value="<?=$edit_cert['id']?>">
    <div class="fg" style="margin:0;flex:1;min-width:150px">
      <label>Nuova data scadenza</label>
      <input type="date" name="expiry_date" value="<?=h($edit_cert['expiry_date']??'')?>">
    </div>
    <div class="fg" style="margin:0;flex:2;min-width:200px">
      <label>Note</label>
      <input type="text" name="notes" value="<?=h($edit_cert['notes']??'')?>" placeholder="Note opzionali...">
    </div>
    <div style="display:flex;gap:8px">
      <button type="submit" class="btn btn-primary">Salva</button>
      <a href="report_certificazioni.php" class="btn">Annulla</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="overflow-x:auto">
<?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('report_certificazioni', '#tCert', ['export_filename' => 'report_certificazioni', 'title' => 'Report certificazioni']); ?>
<table class="data-table" id="tCert">
  <thead>
    <tr>
      <th>Collaboratore</th><th>Certificazione</th><th>Brand</th><th>Tecnologia</th>
      <th>Conseguimento</th><th>Scadenza</th>
      <th style="text-align:center">Credly</th><th style="text-align:center">LinkedIn</th>
      <th style="text-align:center">PDF</th><th style="text-align:center">Stato</th>
      <?php if($can_edit): ?><th style="text-align:center">Azioni</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php if(empty($results)): ?>
  <tr><td colspan="11" style="text-align:center;padding:40px;color:var(--muted)">Nessun certificato trovato.</td></tr>
  <?php endif; ?>
  <?php foreach($results as $r): ?>
  <?php
    // Link Credly: badge specifico (certificate_code UUID) -> template cert -> profilo dip.
    $cc = trim((string)($r['certificate_code'] ?? ''));
    $credly = '';
    if ($cc !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cc)) {
        $credly = 'https://www.credly.com/badges/' . $cc;
    } elseif (!empty($r['emp_credly_url'])) {
        $credly = $r['emp_credly_url'];
    }
    // Link LinkedIn: "Aggiungi al profilo" per la certificazione (con date e cert URL)
    $linkedin = '';
    if (!empty($r['cert_name'])) {
        $q = ['startTask' => 'CERTIFICATION_NAME', 'name' => $r['cert_name']];
        if (!empty($r['issue_date']))  { $q['issueYear']  = date('Y', strtotime($r['issue_date']));  $q['issueMonth']  = date('n', strtotime($r['issue_date'])); }
        if (!empty($r['expiry_date'])) { $q['expirationYear'] = date('Y', strtotime($r['expiry_date'])); $q['expirationMonth'] = date('n', strtotime($r['expiry_date'])); }
        if ($credly !== '') $q['certUrl'] = $credly;
        if ($cc !== '')     $q['certId']  = $cc;
        $linkedin = 'https://www.linkedin.com/profile/add?' . http_build_query($q);
    } elseif (!empty($r['emp_linkedin_url'])) {
        $linkedin = $r['emp_linkedin_url'];
    }
  ?>
  <tr>
    <td><strong><?=h($r['last_name'].' '.$r['first_name'])?></strong></td>
    <td><?=h($r['cert_name'])?><?php if($r['cert_code']): ?><br><code style="font-size:10px;color:var(--muted)"><?=h($r['cert_code'])?></code><?php endif; ?></td>
    <td><span class="badge badge-neutral"><?=h($r['brand_name'])?></span></td>
    <td style="font-size:12px;color:var(--muted)"><?=h($r['tech_name'])?></td>
    <td><?=format_date($r['issue_date'])?></td>
    <td><?=$r['expiry_date']?format_date($r['expiry_date']):'Perpetua'?></td>
    <td style="text-align:center">
      <?php if($credly): ?><a href="<?=h($credly)?>" target="_blank" rel="noopener" title="Credly"><i class="fa-solid fa-award" style="color:#ff6b00;font-size:15px"></i></a><span style="display:none"><?=h($credly)?></span><?php else: ?>&mdash;<?php endif; ?>
    </td>
    <td style="text-align:center">
      <?php if($linkedin): ?><a href="<?=h($linkedin)?>" target="_blank" rel="noopener" title="Aggiungi a LinkedIn"><i class="fa-brands fa-linkedin" style="color:#0a66c2;font-size:15px"></i></a><span style="display:none"><?=h($linkedin)?></span><?php else: ?>&mdash;<?php endif; ?>
    </td>
    <td style="text-align:center">
      <?php if($r['document_path']): ?>
      <a href="download.php?file=<?=urlencode($r['document_path'])?>" target="_blank" style="color:#e11d48"><i class="fa-solid fa-file-pdf" style="font-size:16px"></i></a>
      <?php else: ?><i class="fa-solid fa-file-circle-xmark" style="color:#cbd5e1"></i><?php endif; ?>
    </td>
    <td style="text-align:center"><?=status_badge($r['status_eff'] ?? $r['status'])?></td>
    <?php if($can_edit): ?>
    <td style="text-align:center;white-space:nowrap">
      <a href="<?= qs_self_safe(['edit'=>''.($r['id']).'']) ?>"
         class="btn btn-blue btn-sm"
         title="Modifica"><i class="fa-solid fa-pen"></i></a>
      <form method="POST" style="display:inline-block;margin-left:4px"
            onsubmit="return confirm('Eliminare definitivamente la certificazione di <?= h(addslashes($r['last_name'].' '.$r['first_name'])) ?>?\n\n<?= h(addslashes($r['cert_name'])) ?>\n\nQuesta azione è irreversibile.');">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_cert" value="1">
        <input type="hidden" name="cert_id" value="<?= (int)$r['id'] ?>">
        <button type="submit" class="btn btn-sm"
                style="background:#dc2626;color:#fff;border:0"
                title="Elimina certificazione (irreversibile)">
          <i class="fa-solid fa-trash"></i>
        </button>
      </form>
    </td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<script>$('#tCert').DataTable({language:{search:"Cerca:"},pageLength:25,order:[[5,'asc']]});</script>
<?php require_once('footer.php'); ?>
