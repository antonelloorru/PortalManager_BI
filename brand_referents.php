<?php
/**
 * certV 2.0 — brand_referents.php
 * Gestione referenti interni per brand e storico requisiti partnership
 * Ruoli ammessi: Admin (1), HR Director (2), Brand Manager (3), Team Leader (4) [sola lettura]
 */
require_once('access_control.php');
require_once('header.php');

$u_id    = (int)$_SESSION['user_id'];
$u_role  = (int)($_SESSION['role_id'] ?? 99);
$can_edit = can('edit');  // Solo Admin, HR, Brand Manager possono modificare
$msg     = '';
$oggi    = date('Y-m-d');

$sel_brand_id = (int)($_GET['brand_id'] ?? 0);

// ─── LOGICA: AGGIORNAMENTO REQUISITI PARTNERSHIP ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    // ── Salva requisiti brand ──
    if ($action === 'salva_requisiti') {
        $b_id = (int)$_POST['brand_id'];
        try {
            $pdo->beginTransaction();

            // 1. Chiudi il record corrente nello storico
            $pdo->prepare(
                "UPDATE brand_requirements_history SET end_date=? WHERE brand_id=? AND end_date IS NULL"
            )->execute([$oggi, $b_id]);

            // 2. Aggiorna i dati master del brand
            $pdo->prepare(
                "UPDATE brands SET partnership_level=?, req_company=?, req_commercial=?, req_technical=? WHERE id=?"
            )->execute([
                $_POST['partnership_level'],
                (int)$_POST['req_company'],
                (int)$_POST['req_commercial'],
                (int)$_POST['req_technical'],
                $b_id
            ]);

            // 3. Crea nuovo record storico
            $pdo->prepare(
                "INSERT INTO brand_requirements_history
                    (brand_id, partnership_level, req_company, req_commercial, req_technical, start_date, updated_by)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $b_id,
                $_POST['partnership_level'],
                (int)$_POST['req_company'],
                (int)$_POST['req_commercial'],
                (int)$_POST['req_technical'],
                $oggi, $u_id
            ]);

            $pdo->commit();
            write_log('Brand', 'success', "Requisiti brand #$b_id aggiornati", $u_id);

            // Notifica al team se i requisiti sono cambiati
            push_notification(
                'Requisiti partnership aggiornati',
                "I requisiti di partnership per il brand sono stati modificati. Verificare il gap analysis.",
                'brand', 'warning', null, 4,
                "gap_analysis.php"
            );

            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Requisiti aggiornati e storicizzati.</div>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        }
    }

    // ── Assegna referente ──
    if ($action === 'assegna_referente') {
        $b_id     = (int)$_POST['brand_id'];
        $user_ref = (int)$_POST['employee_id'];
        $role_type= $_POST['role_type'];
        $notes    = trim($_POST['notes'] ?? '');

        try {
            // Verifica che non esista già un referente attivo con lo stesso ruolo per quel brand
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM brand_referents WHERE brand_id=? AND employee_id=? AND role_type=? AND end_date IS NULL"
            );
            $check->execute([$b_id, $user_ref, $role_type]);
            if ($check->fetchColumn() > 0) {
                $msg = "<div class='alert alert-warning'>Questo utente è già referente con questo ruolo per il brand selezionato.</div>";
            } else {
                $pdo->prepare(
                    "INSERT INTO brand_referents (brand_id, employee_id, role_type, start_date, notes) VALUES (?,?,?,?,?)"
                )->execute([$b_id, $user_ref, $role_type, $oggi, $notes ?: null]);
                write_log('Brand', 'success', "Referente assegnato: user=$user_ref brand=$b_id ruolo=$role_type", $u_id);
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Referente assegnato.</div>";
            }
        } catch (Exception $e) {
            $msg = "<div class='alert alert-danger'>Errore: " . h($e->getMessage()) . "</div>";
        }
    }

    // ── Rimuovi referente ──
    if ($action === 'rimuovi_referente' && isset($_POST['ref_id'])) {
        $ref_id = (int)$_POST['ref_id'];
        $pdo->prepare("UPDATE brand_referents SET end_date=? WHERE id=?")->execute([$oggi, $ref_id]);
        write_log('Brand', 'info', "Referente #$ref_id terminato", $u_id);
        $msg = "<div class='alert alert-success'>Referente rimosso.</div>";
    }

    // ── Sincronizza referenti → Directory Brand ──
    // Aggiorna automaticamente i campi contatto nella tabella brands
    // usando i dati dall'anagrafica dipendente dei referenti attivi
    if ($action === 'sync_referents') {
        $sync_brand = (int)$_POST['brand_id'];
        try {
            $pdo->beginTransaction();

            // Mappa ruolo referente → campi brand
            $role_field_map = [
                'brand_manager'        => ['internal_bm_name','internal_bm_email','internal_bm_phone'],
                'account_commerciale'  => ['internal_sl_name','internal_sl_email','internal_sl_phone'],
            ];

            // Recupera referenti attivi con i dati dipendente
            $refs = $pdo->prepare(
                "SELECT br.role_type, e.first_name, e.last_name, e.phone, e.personal_email,
                        u.email as work_email
                 FROM brand_referents br
                 JOIN employees e ON br.employee_id = e.id
                 LEFT JOIN users u ON u.employee_id = e.id AND u.status='active'
                 WHERE br.brand_id = ? AND br.end_date IS NULL
                 ORDER BY br.start_date ASC"
            );
            $refs->execute([$sync_brand]);
            $active_refs = $refs->fetchAll();

            $updates = [];
            $synced = 0;
            foreach ($active_refs as $ref) {
                $rtype = $ref['role_type'];
                if (!isset($role_field_map[$rtype])) continue;

                [$f_name, $f_email, $f_phone] = $role_field_map[$rtype];
                $full_name = trim($ref['first_name'] . ' ' . $ref['last_name']);
                $email = $ref['work_email'] ?: $ref['personal_email'];
                $phone = $ref['phone'];

                // Solo il primo referente attivo per ruolo sovrascrive
                if (!isset($updates[$rtype])) {
                    $pdo->prepare("UPDATE brands SET `$f_name`=?, `$f_email`=?, `$f_phone`=? WHERE id=?")
                        ->execute([$full_name, $email, $phone, $sync_brand]);
                    $updates[$rtype] = $full_name;
                    $synced++;
                }
            }

            // Aggiorna timestamp sync
            $pdo->prepare("UPDATE brand_referents SET synced_at=NOW() WHERE brand_id=? AND end_date IS NULL")
                ->execute([$sync_brand]);

            $pdo->commit();
            write_log('Brand', 'success', "Sincronizzazione referenti brand #$sync_brand: $synced ruoli aggiornati", $u_id);

            if ($synced > 0) {
                $detail = implode(', ', array_map(fn($k,$v) => "$k → $v", array_keys($updates), $updates));
                $msg = "<div class='alert alert-success'><i class='fa-solid fa-arrows-rotate'></i> Sincronizzazione completata: $synced ruoli aggiornati nella Directory Brand ($detail).</div>";
            } else {
                $msg = "<div class='alert alert-info'><i class='fa-solid fa-circle-info'></i> Nessun referente da sincronizzare. Assegnare prima un Brand Manager o Account Commerciale.</div>";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
        }
    }
}

// ─── RECUPERO DATI ────────────────────────────────────────────────────────────
$brands    = $pdo->query("SELECT id, name, partnership_level FROM brands ORDER BY name")->fetchAll();
$all_emps = $pdo->query(
    "SELECT e.id, e.first_name, e.last_name, u.role_id
    FROM employees e LEFT JOIN users u ON u.employee_id = e.id
    WHERE e.status='active' ORDER BY last_name, first_name"
)->fetchAll();

$brand_data = null;
$referents  = [];
$hist_req   = [];
$stats      = [];

if ($sel_brand_id > 0) {
    // Dati brand completi
    $s = $pdo->prepare(
        "SELECT b.*,
                (SELECT COUNT(*) FROM brand_referents br WHERE br.brand_id=b.id AND br.end_date IS NULL) ref_count,
                (SELECT COUNT(*) FROM user_certifications uc JOIN certifications c ON uc.certification_id=c.id WHERE c.brand_id=b.id AND uc.status='active') certs_active,
                (SELECT COUNT(*) FROM user_certifications uc JOIN certifications c ON uc.certification_id=c.id WHERE c.brand_id=b.id AND uc.status='expiring') certs_expiring
         FROM brands b WHERE b.id=?"
    );
    $s->execute([$sel_brand_id]);
    $brand_data = $s->fetch();

    // Referenti attivi
    $rs = $pdo->prepare(
        "SELECT br.*, e.first_name, e.last_name, usr.email, r.name role_name
         FROM brand_referents br
         JOIN employees e         ON br.employee_id = e.id
         LEFT JOIN users usr      ON usr.employee_id = e.id AND usr.status='active'
         LEFT JOIN roles r        ON usr.role_id = r.id
         WHERE br.brand_id=? AND br.end_date IS NULL
         ORDER BY br.role_type, e.last_name"
    );
    $rs->execute([$sel_brand_id]);
    $referents = $rs->fetchAll();

    // Referenti storici (terminati)
    $rsh = $pdo->prepare(
        "SELECT br.*, e.first_name, e.last_name
         FROM brand_referents br
         JOIN employees e ON br.employee_id = e.id
         WHERE br.brand_id=? AND br.end_date IS NOT NULL
         ORDER BY br.end_date DESC LIMIT 20"
    );
    $rsh->execute([$sel_brand_id]);
    $referents_history = $rsh->fetchAll();

    // Storico requisiti
    $hs = $pdo->prepare(
        "SELECT h.*, e.first_name, e.last_name
         FROM brand_requirements_history h
         LEFT JOIN users u  ON h.updated_by = u.id
         LEFT JOIN employees e ON u.employee_id = e.id
         WHERE h.brand_id=?
         ORDER BY h.start_date DESC"
    );
    $hs->execute([$sel_brand_id]);
    $hist_req = $hs->fetchAll();

    // Compliance corrente
    if ($brand_data) {
        $req = max(1, (int)$brand_data['req_technical']);
        $act = (int)$brand_data['certs_active'];
        $stats['pct']   = min(100, round($act / $req * 100));
        $stats['gap']   = max(0, $req - $act);
        $stats['color'] = $stats['pct'] >= 80 ? 'var(--success)' : ($stats['pct'] >= 60 ? 'var(--warning)' : 'var(--danger)');
    }
}

// Etichette tipi referente
$role_type_labels = [
    'brand_manager'       => ['Brand Manager',      'badge-info',   'fa-star'],
    'account_commerciale' => ['Account Commerciale', 'badge-success','fa-briefcase'],
    'referente_formazione'=> ['Ref. Formazione',     'badge-purple', 'fa-graduation-cap'],
    'tecnico'             => ['Tecnico',              'badge-neutral','fa-wrench'],
];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-user-shield" style="color:var(--p);margin-right:10px"></i>Referenti &amp; Requisiti Partnership
    </h1>
    <p style="color:var(--muted);font-size:13px">Gestione referenti interni, requisiti vendor e storico modifiche</p>
  </div>
  <div style="display:flex;gap:8px">
    <button onclick="exportRefCSV()" class="btn btn-sm no-print"><i class="fa-solid fa-file-csv"></i> Esporta CSV</button>
    <button onclick="window.print()" class="btn btn-sm no-print"><i class="fa-solid fa-print"></i> Stampa</button>
  </div>
</div>

<?=$msg?>

<div style="display:grid;grid-template-columns:260px 1fr;gap:24px">

  <!-- ── SIDEBAR BRAND ── -->
  <div class="card" style="padding:0;height:fit-content;position:sticky;top:80px">
    <div style="padding:10px 12px;background:#f8fafc;border-bottom:1px solid var(--border)">
      <!-- Riga 1: input ricerca -->
      <div style="position:relative;margin-bottom:6px">
        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:11px;pointer-events:none"></i>
        <input type="search" id="brandSearch" placeholder="Cerca brand..." autocomplete="off"
               style="width:100%;box-sizing:border-box;padding:6px 24px 6px 26px;border:1px solid var(--border);border-radius:5px;font-size:12px;background:#fff;outline:none">
      </div>
      <!-- Riga 2: due select affiancate -->
      <div style="display:flex;gap:5px;margin-bottom:6px">
        <select id="filterPartnership" style="flex:1;min-width:0;padding:5px 4px;border:1px solid var(--border);border-radius:5px;font-size:10px;background:#fff;outline:none">
          <option value="">Livello: tutti</option>
          <?php
            $levels = [];
            foreach ($brands as $b_lv) {
                $lv = trim($b_lv['partnership_level'] ?? '');
                if ($lv !== '') $levels[$lv] = true;
            }
            ksort($levels);
            foreach (array_keys($levels) as $lv): ?>
            <option value="<?=h($lv)?>"><?=h($lv)?></option>
          <?php endforeach; ?>
        </select>
        <select id="filterCompliance" style="flex:1;min-width:0;padding:5px 4px;border:1px solid var(--border);border-radius:5px;font-size:10px;background:#fff;outline:none">
          <option value="">Stato: tutti</option>
          <option value="safe">Safe ≥80%</option>
          <option value="warning">Att. 60-79%</option>
          <option value="danger">Rischio &lt;60%</option>
        </select>
      </div>
      <!-- Riga 3: contatore + reset -->
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);display:flex;justify-content:space-between;align-items:center;line-height:1">
        <span><span id="brandCountVisible"><?=count($brands)?></span>/<?=count($brands)?> brand</span>
        <button type="button" id="resetFilters" style="background:none;border:0;color:var(--p);cursor:pointer;font-size:10px;font-weight:700;padding:2px 4px;text-transform:uppercase;display:none">
          <i class="fa-solid fa-rotate-left" style="font-size:9px"></i> Reset
        </button>
      </div>
    </div>
    <div style="overflow-y:auto;max-height:calc(100vh - 200px)">
      <?php foreach($brands as $b):
        $is_sel = ($sel_brand_id == $b['id']);
        $cpct   = brand_compliance_pct($b['id']);
        $cpct_col = $cpct>=80?'var(--success)':($cpct>=60?'var(--warning)':'var(--danger)');
      ?>
      <?php $cstatus = $cpct >= 80 ? 'safe' : ($cpct >= 60 ? 'warning' : 'danger'); ?>
      <a href="<?= qs_self_safe(['brand_id'=>''.($b['id']).'']) ?>"
         class="brand-row"
         data-name="<?=h(strtolower($b['name']))?>"
         data-partnership="<?=h($b['partnership_level'] ?? '')?>"
         data-compliance="<?=$cstatus?>"
         style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;text-decoration:none;color:#1e293b;border-bottom:1px solid #f8fafc;transition:.15s;<?=$is_sel?'background:#e0f2fe;border-left:3px solid var(--p);':''?>">
        <div>
          <div style="font-weight:<?=$is_sel?'700':'600'?>;font-size:13px;color:<?=$is_sel?'#0369a1':'#1e293b'?>"><?=h($b['name'])?></div>
          <div style="font-size:10px;color:var(--muted);margin-top:2px"><?=h($b['partnership_level'])?></div>
        </div>
        <div style="text-align:right;flex-shrink:0;margin-left:8px">
          <div style="font-size:12px;font-weight:800;color:<?=$cpct_col?>"><?=$cpct?>%</div>
          <div style="font-size:9px;color:var(--muted)">compliance</div>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if(empty($brands)): ?>
      <div style="padding:24px;text-align:center;color:var(--muted);font-size:13px">
        Nessun brand configurato.<br>
        <a href="brand.php" style="color:var(--p);font-weight:600">Vai a Directory Brand →</a>
      </div>
      <?php endif; ?>
      <div id="noResults" style="padding:20px;text-align:center;color:var(--muted);font-size:11px;display:none">
        <i class="fa-solid fa-magnifying-glass" style="font-size:20px;opacity:.3;display:block;margin-bottom:6px"></i>
        Nessun brand corrisponde ai filtri
      </div>
    </div>
  </div>

  <!-- ── CONTENUTO PRINCIPALE ── -->
  <div>
    <?php if (!$brand_data): ?>
    <!-- Stato vuoto -->
    <div style="text-align:center;padding:80px;background:#fff;border-radius:12px;border:1px dashed var(--border);color:var(--muted)">
      <i class="fa-solid fa-hand-pointer" style="font-size:48px;margin-bottom:16px;display:block;opacity:.3"></i>
      <h3 style="margin-bottom:8px;color:#1e293b">Seleziona un brand</h3>
      <p style="font-size:13px">Scegli un brand dalla lista per visualizzare i dettagli, gestire i referenti e modificare i requisiti di partnership.</p>
    </div>

    <?php else: ?>

    <!-- ── HEADER BRAND ── -->
    <div class="card" style="margin-bottom:20px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid var(--border)">
        <div>
          <h2 style="margin:0;font-size:19px;display:inline-block"><?=h($brand_data['name'])?></h2>
          <a href="<?= url_safe('brand_overview', ['id' => $sel_brand_id]) ?>" class="btn btn-sm" style="background:#7c3aed;color:#fff;margin-left:10px;vertical-align:middle"><i class="fa-solid fa-eye"></i> Vista 360°</a>
          <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
            <span class="badge badge-info"><?=h($brand_data['partnership_level'])?></span>
            <span class="badge badge-neutral"><?=$brand_data['ref_count']?> referenti attivi</span>
            <span class="badge <?=$brand_data['certs_active']>0?'badge-success':'badge-neutral'?>"><?=$brand_data['certs_active']?> cert. attive</span>
            <?php if($brand_data['certs_expiring']>0): ?>
            <span class="badge badge-warning"><?=$brand_data['certs_expiring']?> in scadenza</span>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <?php if($can_edit): ?>
          <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_referents">
            <input type="hidden" name="brand_id" value="<?=$sel_brand_id?>">
            <button type="submit" class="btn btn-sm" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe" title="Sincronizza i dati dei referenti attivi nella Directory Brand">
              <i class="fa-solid fa-arrows-rotate"></i> Sincronizza → Directory
            </button>
          </form>
          <?php endif; ?>
          <a href="brand.php" class="btn btn-sm"><i class="fa-solid fa-arrow-left"></i> Directory</a>
        </div>
      </div>

      <!-- KPI requisiti -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
        <div style="background:#f8fafc;padding:14px;border-radius:10px;text-align:center">
          <div style="font-size:22px;font-weight:800;color:var(--p)"><?=$brand_data['req_technical']?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:3px">Req. tecnico</div>
        </div>
        <div style="background:#f8fafc;padding:14px;border-radius:10px;text-align:center">
          <div style="font-size:22px;font-weight:800;color:var(--p)"><?=$brand_data['req_commercial']?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:3px">Req. commerciale</div>
        </div>
        <div style="background:#f8fafc;padding:14px;border-radius:10px;text-align:center">
          <div style="font-size:22px;font-weight:800;color:var(--p)"><?=$brand_data['req_company']?></div>
          <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:3px">Req. aziende</div>
        </div>
        <div style="background:#f8fafc;padding:14px;border-radius:10px;text-align:center;border:2px solid <?=$stats['color']??'var(--border)'?>">
          <div style="font-size:22px;font-weight:800;color:<?=$stats['color']??'var(--muted)'?>"><?=$stats['pct']??'—'?>%</div>
          <div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:3px">Compliance</div>
          <?php if(($stats['gap']??0)>0): ?>
          <div style="font-size:10px;color:var(--danger);margin-top:2px">-<?=$stats['gap']?> da raggiungere</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── MODIFICA REQUISITI ── -->
    <?php if($can_edit): ?>
    <div class="card" style="margin-bottom:20px;border-color:#bae6fd;background:#f0f9ff">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-pen-to-square" style="color:#0369a1"></i> Modifica requisiti partnership</span>
      </div>
      <form method="POST">
            <?= csrf_field() ?>
        <input type="hidden" name="action" value="salva_requisiti">
        <input type="hidden" name="brand_id" value="<?=$sel_brand_id?>">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:14px;align-items:flex-end">
          <div class="form-group" style="margin:0">
            <label>Livello partnership</label>
            <input type="text" name="partnership_level" value="<?=h($brand_data['partnership_level'])?>" placeholder="Es. Gold, Silver...">
          </div>
          <div class="form-group" style="margin:0">
            <label>Req. aziende</label>
            <input type="number" name="req_company" value="<?=(int)$brand_data['req_company']?>" min="0">
          </div>
          <div class="form-group" style="margin:0">
            <label>Req. commerciale</label>
            <input type="number" name="req_commercial" value="<?=(int)$brand_data['req_commercial']?>" min="0">
          </div>
          <div class="form-group" style="margin:0">
            <label>Req. tecnico</label>
            <input type="number" name="req_technical" value="<?=(int)$brand_data['req_technical']?>" min="0">
          </div>
          <button type="submit" class="btn btn-primary" style="white-space:nowrap">
            <i class="fa-solid fa-floppy-disk"></i> Salva
          </button>
        </div>
        <div style="margin-top:10px;font-size:11px;color:#0369a1;background:#e0f2fe;padding:8px 12px;border-radius:6px">
          <i class="fa-solid fa-circle-info" style="margin-right:5px"></i>
          La modifica viene storicizzata automaticamente con data e utente. Lo storico è visibile nella sezione in basso.
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- ── REFERENTI ATTIVI ── -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-users-rectangle" style="color:var(--p)"></i> Referenti incaricati (<?=count($referents)?>)</span>
      </div>

      <?php if(empty($referents)): ?>
      <div style="text-align:center;padding:28px;color:var(--muted);background:#f8fafc;border-radius:8px">
        <i class="fa-solid fa-user-slash" style="font-size:28px;margin-bottom:8px;display:block;opacity:.4"></i>
        Nessun referente assegnato a questo brand.
      </div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:16px">
        <?php foreach($referents as $r):
          [$rl, $rb, $ri] = $role_type_labels[$r['role_type']] ?? [$r['role_type'],'badge-neutral','fa-user'];
        ?>
        <div style="background:#f8fafc;border-radius:10px;padding:16px;border:1px solid var(--border)">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
            <span class="badge <?=$rb?>" style="font-size:9px">
              <i class="fa-solid <?=$ri?>" style="margin-right:4px"></i><?=$rl?>
            </span>
            <?php if($can_edit): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Rimuovere questo referente?')">
            <?= csrf_field() ?>
              <input type="hidden" name="action" value="rimuovi_referente">
              <input type="hidden" name="ref_id" value="<?=$r['id']?>">
              <input type="hidden" name="brand_id" value="<?=$sel_brand_id?>">
              <button type="submit" class="btn btn-danger btn-sm" style="padding:3px 7px;font-size:10px">
                <i class="fa-solid fa-user-minus"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
          <div style="font-weight:700;font-size:14px;margin-bottom:3px"><?=h($r['last_name'].' '.$r['first_name'])?></div>
          <div style="font-size:11px;color:var(--muted);margin-bottom:6px"><?=h($r['role_name'])?></div>
          <div style="font-size:12px">
            <i class="fa-solid fa-envelope" style="width:14px;color:var(--muted)"></i>
            <a href="mailto:<?=h($r['email'])?>" style="color:var(--p)"><?=h($r['email'])?></a>
          </div>
          <div style="font-size:11px;color:var(--muted);margin-top:6px">
            Incaricato dal <?=format_date($r['start_date'])?>
            <?php if($r['notes']): ?><br><em><?=h($r['notes'])?></em><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Form assegnazione referente -->
      <?php if($can_edit): ?>
      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:18px;margin-top:<?=empty($referents)?'0':'8px'?>">
        <div style="font-size:11px;font-weight:700;color:#0369a1;text-transform:uppercase;margin-bottom:12px">
          <i class="fa-solid fa-user-plus" style="margin-right:6px"></i>Assegna nuovo referente
        </div>
        <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <?= csrf_field() ?>
          <input type="hidden" name="action" value="assegna_referente">
          <input type="hidden" name="brand_id" value="<?=$sel_brand_id?>">
          <div class="form-group" style="margin:0;flex:2;min-width:180px">
            <label>Collaboratore</label>
            <select name="employee_id" required>
              <option value="">Seleziona...</option>
              <?php foreach($all_emps as $u): ?>
              <option value="<?=$u['id']?>"><?=h($u['last_name'].' '.$u['first_name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0;flex:1;min-width:160px">
            <label>Ruolo nel brand</label>
            <select name="role_type" required>
              <?php foreach($role_type_labels as $val => [$lbl]): ?>
              <option value="<?=$val?>"><?=$lbl?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0;flex:2;min-width:180px">
            <label>Note (opzionale)</label>
            <input type="text" name="notes" placeholder="Es. Referente tecnico Azure...">
          </div>
          <button type="submit" class="btn btn-primary" style="align-self:flex-end">
            <i class="fa-solid fa-user-plus"></i> Assegna
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── STORICO REFERENTI ── -->
    <?php if(!empty($referents_history)): ?>
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-users" style="color:var(--muted)"></i> Referenti precedenti</span>
        <span style="font-size:12px;color:var(--muted)"><?=count($referents_history)?> record</span>
      </div>
      <?php require_once __DIR__ . '/app/ListFilter.php'; ListFilter::render('brand_referents', '#lf-table-brand_referents', ['export_filename' => 'brand_referents', 'title' => 'Referenti brand']); ?>
<table id="lf-table-brand_referents" class="data-table">
        <thead>
          <tr>
            <th>Collaboratore</th><th>Ruolo nel brand</th><th>Dal</th><th>Al</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($referents_history as $rh):
          [$rl, $rb] = $role_type_labels[$rh['role_type']] ?? [$rh['role_type'],'badge-neutral'];
        ?>
        <tr style="opacity:.7">
          <td><?=h($rh['last_name'].' '.$rh['first_name'])?></td>
          <td><span class="badge <?=$rb?>" style="font-size:9px"><?=$rl?></span></td>
          <td style="font-size:12px;color:var(--muted)"><?=format_date($rh['start_date'])?></td>
          <td style="font-size:12px;color:var(--muted)"><?=format_date($rh['end_date'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- ── STORICO REQUISITI ── -->
    <div class="card">
      <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--p)"></i> Registro storico requisiti</span>
        <span style="font-size:12px;color:var(--muted)"><?=count($hist_req)?> versioni</span>
      </div>
      <?php if(empty($hist_req)): ?>
      <div style="text-align:center;padding:28px;color:var(--muted);font-size:13px">
        Nessuna modifica ai requisiti registrata.<br>
        <span style="font-size:12px">Le modifiche verranno tracciate a partire dal primo salvataggio.</span>
      </div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Periodo validità</th>
            <th>Livello partnership</th>
            <th style="text-align:center">Req. az.</th>
            <th style="text-align:center">Req. com.</th>
            <th style="text-align:center">Req. tec.</th>
            <th>Modificato da</th>
            <th style="text-align:center">Stato</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($hist_req as $h_row):
          $is_current = ($h_row['end_date'] === null);
        ?>
        <tr style="<?=$is_current?'background:#f0fdf4;font-weight:600;':''?>">
          <td>
            <code style="font-size:11px"><?=format_date($h_row['start_date'])?></code>
            <span style="color:var(--muted);margin:0 4px">→</span>
            <code style="font-size:11px"><?=$h_row['end_date']?format_date($h_row['end_date']):'In corso'?></code>
          </td>
          <td><span class="badge badge-info" style="font-size:9px"><?=h($h_row['partnership_level']??'—')?></span></td>
          <td style="text-align:center"><?=(int)$h_row['req_company']?></td>
          <td style="text-align:center"><?=(int)$h_row['req_commercial']?></td>
          <td style="text-align:center;font-weight:<?=$is_current?'800':'400'?>;color:<?=$is_current?'var(--p)':'inherit'?>"><?=(int)$h_row['req_technical']?></td>
          <td style="font-size:12px;color:var(--muted)">
            <?=$h_row['last_name']
              ? h($h_row['first_name'][0].'. '.$h_row['last_name'])
              : '<em>Sistema</em>'?>
          </td>
          <td style="text-align:center">
            <?=$is_current
              ? '<span class="badge badge-success">Corrente</span>'
              : '<span class="badge badge-neutral">Archiviato</span>'?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; // fine $brand_data ?>
  </div><!-- fine contenuto principale -->
</div>

<script>
function exportRefCSV(){
  let rows = "\uFEFF" + "Brand,Livello Partnership,Tipo Referente,Nome,Cognome,Email,Telefono,Data Inizio,Stato\n";
  <?php
  // Query tutti i referenti per export
  $all_refs_exp = $pdo->query(
    "SELECT b.name brand_name, b.partnership_level, br.role_type, e.first_name, e.last_name,
            COALESCE(u.email, e.personal_email) email, e.phone, br.start_date, br.end_date
     FROM brand_referents br
     JOIN brands b ON br.brand_id = b.id
     JOIN employees e ON br.employee_id = e.id
     LEFT JOIN users u ON u.employee_id = e.id AND u.status='active'
     ORDER BY b.name, br.role_type, e.last_name"
  )->fetchAll();
  $rtl = ['brand_manager'=>'Brand Manager','account_commerciale'=>'Account Commerciale','referente_formazione'=>'Ref. Formazione','tecnico'=>'Tecnico'];
  foreach ($all_refs_exp as $rx):
    $stato = $rx['end_date'] ? 'Cessato' : 'Attivo';
    $tipo = $rtl[$rx['role_type']] ?? $rx['role_type'];
  ?>
  rows += <?=json_encode(
    '"'.addslashes($rx['brand_name']).'","'.addslashes($rx['partnership_level']??'').'",'
    .'"'.addslashes($tipo).'","'.addslashes($rx['first_name']).'","'.addslashes($rx['last_name']).'",'
    .'"'.addslashes($rx['email']??'').'","'.addslashes($rx['phone']??'').'",'
    .'"'.($rx['start_date'] ? date('d/m/Y', strtotime($rx['start_date'])) : '').'",'
    .'"'.$stato.'"'
  )?> + "\n";
  <?php endforeach; ?>
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(rows);
  a.download = 'referenti_brand_<?=date('Y-m-d')?>.csv';
  a.click();
}


// ─── FILTRAGGIO LIVE BRAND (v1.7.1) ───
(function() {
  const searchInput   = document.getElementById('brandSearch');
  const filterPartner = document.getElementById('filterPartnership');
  const filterCompl   = document.getElementById('filterCompliance');
  const resetBtn      = document.getElementById('resetFilters');
  const countSpan     = document.getElementById('brandCountVisible');
  const noResults     = document.getElementById('noResults');
  const rows          = document.querySelectorAll('.brand-row');

  if (!searchInput || rows.length === 0) return;

  function applyFilters() {
    const q       = (searchInput.value || '').trim().toLowerCase();
    const partner = filterPartner ? filterPartner.value : '';
    const compl   = filterCompl ? filterCompl.value : '';
    let visible = 0;

    rows.forEach(row => {
      const ok = (!q || (row.dataset.name || '').indexOf(q) !== -1)
              && (!partner || row.dataset.partnership === partner)
              && (!compl || row.dataset.compliance === compl);
      row.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });

    if (countSpan) countSpan.textContent = visible;
    if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
    if (resetBtn) {
      const active = q !== '' || partner !== '' || compl !== '';
      resetBtn.style.display = active ? 'inline-block' : 'none';
    }
  }

  searchInput.addEventListener('input', applyFilters);
  if (filterPartner) filterPartner.addEventListener('change', applyFilters);
  if (filterCompl)   filterCompl.addEventListener('change', applyFilters);

  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      searchInput.value = '';
      if (filterPartner) filterPartner.value = '';
      if (filterCompl)   filterCompl.value = '';
      applyFilters();
    });
  }

  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Escape') { searchInput.value = ''; applyFilters(); }
  });

  // Persistenza sessione
  try {
    const saved = JSON.parse(sessionStorage.getItem('br_filters') || '{}');
    if (saved.q) searchInput.value = saved.q;
    if (saved.p && filterPartner) filterPartner.value = saved.p;
    if (saved.c && filterCompl)   filterCompl.value = saved.c;
  } catch (e) {}

  function saveFilters() {
    try {
      sessionStorage.setItem('br_filters', JSON.stringify({
        q: searchInput.value,
        p: filterPartner ? filterPartner.value : '',
        c: filterCompl ? filterCompl.value : '',
      }));
    } catch (e) {}
  }
  ['input','change'].forEach(ev => {
    searchInput.addEventListener(ev, saveFilters);
    if (filterPartner) filterPartner.addEventListener(ev, saveFilters);
    if (filterCompl)   filterCompl.addEventListener(ev, saveFilters);
  });

  applyFilters();
})();

</script>
<?php require_once('footer.php'); ?>
