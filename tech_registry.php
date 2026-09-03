<?php
/**
 * PortalManager — tech_registry.php  (v1.8.48)
 *
 * Anagrafica Tecnica: punto unico su chi opera nelle commesse, a prescindere
 * dal rapporto contrattuale.
 *
 * MODELLO
 * La pagina non duplica i dati anagrafici. Nome, cognome, email e recapiti
 * restano dove sono sempre stati — `employees` per gli interni,
 * `cm_professionals` per gli esterni — e cm_tech_profiles aggiunge soltanto la
 * classificazione operativa che prima non esisteva: unita organizzativa,
 * sotto-unita, seniority, reperibilita, turno, competenza principale.
 *
 * La conseguenza pratica e' che una correzione al nome fatta in Anagrafica
 * dipendenti si riflette qui senza allineamenti, e che disattivare un
 * dipendente non lascia un profilo tecnico orfano con dati stantii.
 *
 * STORICIZZAZIONE
 * Le variazioni vengono registrate **su richiesta**, spuntando l'apposita
 * casella al salvataggio. Storicizzare ogni salvataggio riempirebbe l'archivio
 * di correzioni di battitura, rendendo illeggibile la sequenza dei veri
 * cambi di assegnazione — che e' l'informazione per cui lo storico esiste.
 */
require_once('access_control.php');
require_once('functions.php');

if (!can('view', 'tech_registry.php')) { redirect('dashboard'); }
$can_edit = can('edit', 'tech_registry.php') || can('create', 'tech_registry.php');
$u_id     = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    if (!$can_edit) { $_SESSION['flash_msg'] = "<div class='alert alert-danger'>Privilegi insufficienti.</div>"; redirect_self(); }

    try {
        if (($_POST['action'] ?? '') === 'save') {
            $empId  = (int)($_POST['employee_id'] ?? 0) ?: null;
            $profId = (int)($_POST['professional_id'] ?? 0) ?: null;
            if (!$empId && !$profId) throw new Exception('Selezionare la persona da classificare.');
            if ($empId && $profId)  throw new Exception('Una persona è interna oppure esterna, non entrambe.');

            $unitId    = (int)($_POST['unit_id'] ?? 0) ?: null;
            $subunitId = (int)($_POST['subunit_id'] ?? 0) ?: null;

            // la sotto-unita deve appartenere all'unita scelta: una combinazione
            // incoerente falserebbe ogni analisi successiva
            if ($subunitId) {
                $chk = $pdo->prepare("SELECT unit_id FROM cm_tech_subunits WHERE id=?");
                $chk->execute([$subunitId]);
                $owner = (int)$chk->fetchColumn();
                if (!$unitId || $owner !== $unitId) throw new Exception('La sotto-unità non appartiene all\'unità selezionata.');
            }

            $fields = [
                'unit_id'        => $unitId,
                'subunit_id'     => $subunitId,
                'seniority'      => trim((string)($_POST['seniority'] ?? '')) ?: null,
                'on_call'        => isset($_POST['on_call']) ? 1 : 0,
                'on_call_h24'    => isset($_POST['on_call_h24']) ? 1 : 0,
                'shift_pattern'  => trim((string)($_POST['shift_pattern'] ?? '')) ?: null,
                'main_skill'     => trim((string)($_POST['main_skill'] ?? '')) ?: null,
                'certifications' => trim((string)($_POST['certifications'] ?? '')) ?: null,
                'notes'          => trim((string)($_POST['notes'] ?? '')) ?: null,
                'valid_from'     => ($_POST['valid_from'] ?? '') ?: null,
                'is_active'      => isset($_POST['is_active']) ? 1 : 0,
            ];

            // profilo esistente per questa persona?
            $q = $pdo->prepare($empId
                ? "SELECT * FROM cm_tech_profiles WHERE employee_id=?"
                : "SELECT * FROM cm_tech_profiles WHERE professional_id=?");
            $q->execute([$empId ?: $profId]);
            $prev = $q->fetch(PDO::FETCH_ASSOC);

            if ($prev) {
                $set = []; $args = [];
                foreach ($fields as $k => $v) { $set[] = "`$k`=?"; $args[] = $v; }
                $args[] = (int)$prev['id'];
                $pdo->prepare("UPDATE cm_tech_profiles SET " . implode(',', $set) . " WHERE id=?")->execute($args);
                $profileId = (int)$prev['id'];
            } else {
                $cols = array_merge(['employee_id','professional_id','created_by'], array_keys($fields));
                $vals = array_merge([$empId, $profId, $u_id], array_values($fields));
                $pdo->prepare("INSERT INTO cm_tech_profiles (`" . implode('`,`', $cols) . "`) VALUES ("
                    . implode(',', array_fill(0, count($cols), '?')) . ")")->execute($vals);
                $profileId = (int)$pdo->lastInsertId();
            }

            // ── Storicizzazione su richiesta ────────────────────────────────
            if (!empty($_POST['historize'])) {
                $from = $fields['valid_from'] ?: date('Y-m-d');
                // chiudo l'assegnazione precedente il giorno prima della nuova
                $pdo->prepare("UPDATE cm_tech_history SET valid_to = DATE_SUB(?, INTERVAL 1 DAY)
                               WHERE profile_id=? AND valid_to IS NULL AND valid_from < ?")
                    ->execute([$from, $profileId, $from]);
                // etichette congelate: se l'unità venisse rinominata, lo storico
                // deve continuare a dire com'era chiamata allora
                $lbl = $pdo->prepare("SELECT (SELECT name FROM cm_tech_units WHERE id=?) AS u,
                                             (SELECT name FROM cm_tech_subunits WHERE id=?) AS s");
                $lbl->execute([$unitId, $subunitId]);
                $names = $lbl->fetch(PDO::FETCH_ASSOC) ?: ['u'=>null,'s'=>null];

                $pdo->prepare("INSERT INTO cm_tech_history
                    (profile_id,unit_id,subunit_id,unit_label,subunit_label,seniority,on_call,on_call_h24,
                     valid_from,change_reason,changed_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$profileId, $unitId, $subunitId, $names['u'], $names['s'],
                        $fields['seniority'], $fields['on_call'], $fields['on_call_h24'], $from,
                        trim((string)($_POST['change_reason'] ?? '')) ?: null, $u_id]);
            }

            write_log('TechRegistry','success',"Profilo tecnico #$profileId salvato"
                . (!empty($_POST['historize']) ? ' con registrazione a storico' : ''), $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Profilo tecnico salvato"
                . (!empty($_POST['historize']) ? ", variazione registrata a storico" : "") . ".</div>";
        }

        // ── v1.8.55: proposta di reperibilità dai consuntivi ─────────────
        // Applica il flag a chi risulta operare abitualmente fuori orario. Non
        // avviene in automatico a ogni sincronizzazione: un flag che cambia da
        // solo sovrascriverebbe le decisioni prese da chi conosce
        // l'organizzazione, e il consuntivo non le conosce — un tecnico in turno
        // di reperibilità che non riceve chiamate non lascia alcuna traccia.
        if (($_POST['action'] ?? '') === 'apply_oncall') {
            $soloProposti = !empty($_POST['solo_proposti']);
            $creaProfili  = !empty($_POST['crea_profili']);

            $rows = $pdo->query("SELECT * FROM v_tech_oncall_proposta WHERE on_call_proposto = 1")
                        ->fetchAll(PDO::FETCH_ASSOC);
            $creati = 0; $aggiornati = 0; $invariati = 0; $saltati = 0;

            $pdo->beginTransaction();
            foreach ($rows as $r) {
                // Un tecnico può risultare sia come dipendente sia come
                // professionista esterno: l'identità interna ha la precedenza,
                // perché cm_tech_profiles ammette una sola delle due colonne.
                $empId  = (int)($r['employee_id'] ?? 0) ?: null;
                $profId = $empId ? null : ((int)($r['professional_id'] ?? 0) ?: null);
                if (!$empId && !$profId) { $saltati++; continue; }

                $q = $pdo->prepare($empId
                    ? "SELECT id, on_call, on_call_h24 FROM cm_tech_profiles WHERE employee_id = ?"
                    : "SELECT id, on_call, on_call_h24 FROM cm_tech_profiles WHERE professional_id = ?");
                $q->execute([$empId ?: $profId]);
                $prev = $q->fetch(PDO::FETCH_ASSOC);

                $wantOn  = 1;
                $wantH24 = (int)$r['on_call_h24_proposto'];

                if (!$prev) {
                    if (!$creaProfili) { $saltati++; continue; }
                    $pdo->prepare("INSERT INTO cm_tech_profiles
                            (employee_id, professional_id, on_call, on_call_h24, valid_from, created_by)
                            VALUES (?,?,?,?,CURDATE(),?)")
                        ->execute([$empId, $profId, $wantOn, $wantH24, $u_id]);
                    $creati++;
                    continue;
                }

                // il flag H24 non viene mai tolto in automatico: un periodo senza
                // interventi notturni non prova che il ruolo sia cessato
                $newH24 = max((int)$prev['on_call_h24'], $wantH24);
                if ((int)$prev['on_call'] === $wantOn && (int)$prev['on_call_h24'] === $newH24) {
                    $invariati++;
                    continue;
                }
                $pdo->prepare("UPDATE cm_tech_profiles SET on_call = ?, on_call_h24 = ? WHERE id = ?")
                    ->execute([$wantOn, $newH24, (int)$prev['id']]);
                $aggiornati++;
            }
            $pdo->commit();

            write_log('TechRegistry', 'success', sprintf(
                'Reperibilità dai consuntivi: %d profili creati, %d aggiornati, %d già corretti, %d senza corrispondenza',
                $creati, $aggiornati, $invariati, $saltati), $u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>"
                . "<strong>$creati</strong> profili creati, <strong>$aggiornati</strong> aggiornati, "
                . "$invariati già corretti"
                . ($saltati ? ", <strong>$saltati</strong> senza corrispondenza in anagrafica" : '')
                . ".</div>";
            redirect_self();
        }

        if (($_POST['action'] ?? '') === 'delete' && can('delete', 'tech_registry.php')) {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE cm_tech_profiles SET is_active=0 WHERE id=?")->execute([$id]);
            write_log('TechRegistry','info',"Profilo tecnico #$id disattivato",$u_id);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'>Profilo disattivato. Lo storico resta consultabile.</div>";
        }
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
    redirect_self();
}

// ── Filtri ──────────────────────────────────────────────────────────────────
$f = [
    'q'       => trim($_GET['q'] ?? ''),
    'unit'    => (int)($_GET['unit'] ?? 0),
    'subunit' => (int)($_GET['subunit'] ?? 0),
    'kind'    => trim($_GET['kind'] ?? ''),      // interno | esterno
    'oncall'  => $_GET['oncall'] ?? '',
    'stato'   => $_GET['stato'] ?? 'attivi',
    'unclass' => !empty($_GET['unclass']) ? 1 : 0,
];

// L'elenco unisce le due anagrafiche. UNION ALL e non UNION: le due sorgenti
// non possono avere duplicati fra loro e la deduplicazione costerebbe soltanto.
$sql = "
SELECT * FROM (
    SELECT p.id AS profile_id, 'interno' AS kind, e.id AS person_id,
           TRIM(CONCAT(COALESCE(e.last_name,''),' ',COALESCE(e.first_name,''))) AS full_name,
           e.business_email AS email, e.employee_code AS code, e.job_title AS role_raw,
           co.name AS company_name,
           p.unit_id, p.subunit_id, p.seniority, p.on_call, p.on_call_h24,
           p.shift_pattern, p.main_skill, p.is_active AS profile_active, p.valid_from
      FROM employees e
      LEFT JOIN cm_tech_profiles p ON p.employee_id = e.id
      LEFT JOIN companies co ON co.id = e.company_id
     WHERE e.status <> 'terminated'
    UNION ALL
    SELECT p.id AS profile_id, 'esterno' AS kind, pr.id AS person_id,
           TRIM(CONCAT(COALESCE(pr.last_name,''),' ',COALESCE(pr.first_name,''))) AS full_name,
           pr.email, pr.abbr AS code, pr.operator_type AS role_raw,
           co.name AS company_name,
           p.unit_id, p.subunit_id, p.seniority, p.on_call, p.on_call_h24,
           p.shift_pattern, p.main_skill, p.is_active AS profile_active, p.valid_from
      FROM cm_professionals pr
      LEFT JOIN cm_tech_profiles p ON p.professional_id = pr.id
      LEFT JOIN companies co ON co.id = pr.exec_company_id
     WHERE pr.active = 1 AND pr.deleted_src = 0 AND pr.employee_id IS NULL
) t WHERE 1=1";
$args = [];
if ($f['q'] !== '')   { $sql .= " AND (t.full_name LIKE ? OR t.email LIKE ? OR t.code LIKE ?)"; for($i=0;$i<3;$i++) $args[]="%{$f['q']}%"; }
if ($f['unit'])       { $sql .= " AND t.unit_id = ?";    $args[] = $f['unit']; }
if ($f['subunit'])    { $sql .= " AND t.subunit_id = ?"; $args[] = $f['subunit']; }
if ($f['kind'] !== ''){ $sql .= " AND t.kind = ?";       $args[] = $f['kind']; }
if ($f['oncall'] === '1')   { $sql .= " AND (t.on_call = 1 OR t.on_call_h24 = 1)"; }
if ($f['oncall'] === 'h24') { $sql .= " AND t.on_call_h24 = 1"; }
if ($f['unclass'])          { $sql .= " AND t.unit_id IS NULL"; }
if ($f['stato'] === 'attivi')      { $sql .= " AND (t.profile_active = 1 OR t.profile_id IS NULL)"; }
elseif ($f['stato'] === 'inattivi'){ $sql .= " AND t.profile_active = 0"; }
$sql .= " ORDER BY t.full_name";

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$units = $pdo->query("SELECT * FROM cm_tech_units WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$subs  = $pdo->query("SELECT * FROM cm_tech_subunits WHERE is_active=1 ORDER BY unit_id, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
$unitById = []; foreach ($units as $u) $unitById[(int)$u['id']] = $u;
$subById  = []; foreach ($subs as $s)  $subById[(int)$s['id']]  = $s;

// scheda in modifica
$edit = null; $history = [];
$ek = (string)($_GET['edit_kind'] ?? ''); $ep = (int)($_GET['edit_person'] ?? 0);
if ($ep && in_array($ek, ['interno','esterno'], true)) {
    foreach ($rows as $r) if ($r['kind'] === $ek && (int)$r['person_id'] === $ep) $edit = $r;
    if ($edit) {
        $q = $pdo->prepare($ek === 'interno'
            ? "SELECT * FROM cm_tech_profiles WHERE employee_id=?"
            : "SELECT * FROM cm_tech_profiles WHERE professional_id=?");
        $q->execute([$ep]);
        $edit = array_merge($edit, $q->fetch(PDO::FETCH_ASSOC) ?: []);
        if (!empty($edit['profile_id'])) {
            $h = $pdo->prepare("SELECT * FROM cm_tech_history WHERE profile_id=? ORDER BY valid_from DESC, id DESC");
            $h->execute([(int)$edit['profile_id']]);
            $history = $h->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// riepilogo per unità: è l'analisi per cui la classificazione esiste
$byUnit = $pdo->query("
    SELECT u.id, u.name, u.color,
           COUNT(p.id) AS n,
           SUM(CASE WHEN p.on_call=1 OR p.on_call_h24=1 THEN 1 ELSE 0 END) AS n_oncall
      FROM cm_tech_units u
      LEFT JOIN cm_tech_profiles p ON p.unit_id = u.id AND p.is_active = 1
     WHERE u.is_active = 1
     GROUP BY u.id ORDER BY u.sort_order, u.name")->fetchAll(PDO::FETCH_ASSOC);
$unclassified = 0;
foreach ($rows as $r) if (!$r['unit_id']) $unclassified++;

// v1.8.55: proposta di reperibilità ricavata dai consuntivi
$oncall = ['righe' => [], 'da_attivare' => 0, 'senza_profilo' => 0, 'h24' => 0];
try {
    $oncall['righe'] = $pdo->query("SELECT * FROM v_tech_oncall_proposta
                                     WHERE on_call_proposto = 1
                                     ORDER BY giornate_reperibilita DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($oncall['righe'] as $r) {
        if ($r['azione'] === 'da attivare')    $oncall['da_attivare']++;
        if ($r['azione'] === 'profilo assente') $oncall['senza_profilo']++;
        if ((int)$r['on_call_h24_proposto'])   $oncall['h24']++;
    }
} catch (Throwable $e) { /* migration non ancora eseguita */ }

$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
$active = ($f['q'] !== '' || $f['unit'] || $f['subunit'] || $f['kind'] !== '' || $f['oncall'] !== ''
        || $f['unclass'] || $f['stato'] !== 'attivi');

require_once('header.php');
?>
<style>
.tr-panel { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; overflow:hidden }
.tr-panel > summary { list-style:none; cursor:pointer; padding:11px 14px; font-weight:700; font-size:13px;
  display:flex; align-items:center; gap:9px; background:#f8fafc; user-select:none }
.tr-panel > summary::-webkit-details-marker { display:none }
.tr-panel > summary:hover { background:#f1f5f9 }
.tr-panel[open] > summary { border-bottom:1px solid #e2e8f0 }
.tr-panel > summary .chev { transition:transform .15s ease; color:var(--muted); font-size:11px }
.tr-panel[open] > summary .chev { transform:rotate(90deg) }
.tr-body { padding:14px }
.tr-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px }
.tr-grid .form-group { margin:0 }
.tr-grid label { font-size:11px; color:#475569; font-weight:600 }
.tr-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; margin-bottom:14px }
.tr-card { border:1px solid #e2e8f0; border-radius:8px; padding:10px; background:#fff; border-left-width:4px }
.tr-card .n { font-size:22px; font-weight:800; line-height:1 }
.tr-card .l { font-size:11px; color:var(--muted); font-weight:700; margin-top:4px }
.tr-card .o { font-size:10px; color:#f59e0b; margin-top:2px }
.tr-dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:5px; vertical-align:middle }
.tr-chip { font-size:10px; padding:1px 7px; border-radius:9px; font-weight:700 }
.tr-int { background:#dbeafe; color:#1e40af }
.tr-ext { background:#fef3c7; color:#92400e }
.tr-badge { background:#3b82f6; color:#fff; border-radius:10px; padding:1px 8px; font-size:11px; font-weight:700 }
.tr-hint { font-weight:400; color:var(--muted); font-size:11px; margin-left:auto }
@media (max-width:900px){ .tr-grid { grid-template-columns:repeat(2,1fr) } }
</style>

<div class="page-header">
  <h1><i class="fa-solid fa-user-gear"></i> Anagrafica Tecnica</h1>
  <p style="color:var(--muted);font-size:13px">
    Tecnici interni e professionisti esterni in un unico elenco, classificati per unità organizzativa.
    I dati anagrafici restano nelle rispettive schede: qui si aggiunge la classificazione operativa.
  </p>
</div>
<?= $msg ?>

<div class="tr-cards">
  <?php foreach ($byUnit as $b): ?>
    <a class="tr-card" style="border-left-color:<?=h($b['color'] ?: '#94a3b8')?>;text-decoration:none;color:inherit"
       href="<?=url_safe('tech_registry',['unit'=>(int)$b['id']])?>">
      <div class="n"><?=(int)$b['n']?></div>
      <div class="l"><?=h($b['name'])?></div>
      <?php if ((int)$b['n_oncall']): ?><div class="o"><i class="fa-solid fa-phone-volume"></i> <?=(int)$b['n_oncall']?> reperibili</div><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if ($unclassified): ?>
    <a class="tr-card" style="border-left-color:#dc2626;text-decoration:none;color:inherit"
       href="<?=url_safe('tech_registry',['unclass'=>1])?>">
      <div class="n" style="color:#dc2626"><?=$unclassified?></div>
      <div class="l">Da classificare</div>
    </a>
  <?php endif; ?>
</div>

<?php if ($can_edit && $oncall['righe']): ?>
<details class="tr-panel">
  <summary>
    <i class="fa-solid fa-chevron-right chev"></i>
    <i class="fa-solid fa-phone-volume" style="color:#f59e0b"></i>
    Reperibilità rilevata dai consuntivi
    <span class="tr-badge" style="background:#f59e0b"><?=count($oncall['righe'])?></span>
    <span class="tr-hint">
      <?=$oncall['da_attivare']?> da attivare · <?=$oncall['senza_profilo']?> senza profilo · <?=$oncall['h24']?> con H24
    </span>
  </summary>
  <div class="tr-body">
    <p style="font-size:12px;color:var(--muted);margin:0 0 10px">
      Elenco di chi ha svolto interventi classificati in reperibilità — fuori dalla fascia
      lun-ven 09:00–18:00 — in <strong>almeno 5 giornate distinte negli ultimi 12 mesi</strong>.
      La soglia esclude gli episodi isolati: un guasto risolto una sera non fa di un tecnico un reperibile.
      Il flag <strong>H24</strong> è proposto a chi ha almeno 5 giornate con intervento notturno o festivo.
    </p>

    <div style="overflow-x:auto;max-height:340px;overflow-y:auto">
      <table class="data-table" style="width:100%;font-size:11px">
        <thead><tr>
          <th>Tecnico</th><th style="text-align:right">Giornate</th><th style="text-align:right">Ore</th>
          <th style="text-align:right">Notturne</th><th style="text-align:right">Festive</th>
          <th style="text-align:center">Propone</th><th>Stato attuale</th>
        </tr></thead>
        <tbody>
        <?php foreach ($oncall['righe'] as $r): ?>
          <tr>
            <td style="font-weight:600"><?=h($r['tecnico'] ?: '—')?></td>
            <td style="text-align:right"><?=(int)$r['giornate_reperibilita']?></td>
            <td style="text-align:right"><?=number_format((float)$r['ore_reperibilita'],2,',','.')?></td>
            <td style="text-align:right"><?=(int)$r['giornate_notturne'] ?: '—'?></td>
            <td style="text-align:right"><?=(int)$r['giornate_festive'] ?: '—'?></td>
            <td style="text-align:center">
              <span style="color:#f59e0b;font-weight:700">reperibile</span>
              <?php if ((int)$r['on_call_h24_proposto']): ?>
                <span style="color:#b45309;font-weight:700"> + H24</span>
              <?php endif; ?>
            </td>
            <td><?php
              $az = $r['azione'];
              $col = $az === 'gia corretto' ? '#16a34a' : ($az === 'profilo assente' ? '#64748b' : '#dc2626');
              echo '<span style="color:' . $col . '">' . h($az) . '</span>';
            ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" style="margin-top:12px;display:flex;gap:14px;align-items:center;flex-wrap:wrap"
          onsubmit="return confirm('Applicare il flag di reperibilità ai tecnici elencati?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="apply_oncall">
      <label style="display:flex;gap:6px;align-items:center;font-size:12px">
        <input type="checkbox" name="crea_profili" value="1" checked>
        Crea il profilo tecnico dove manca</label>
      <button class="btn btn-primary btn-sm"><i class="fa-solid fa-wand-magic-sparkles"></i> Applica il flag</button>
      <span style="color:var(--muted);font-size:11px;margin-left:auto">
        L'operazione è tracciata nell'event log e ripetibile.
      </span>
    </form>

    <p style="color:var(--muted);font-size:11px;margin-top:8px">
      Il flag <strong>non viene mai rimosso</strong> in automatico: un periodo senza chiamate non prova che il
      ruolo sia cessato, e un tecnico in turno di reperibilità che non riceve interventi non lascia traccia nei
      consuntivi. Le disattivazioni restano una decisione manuale.
    </p>
  </div>
</details>
<?php endif; ?>

<?php if ($can_edit && $edit): ?>
<details class="tr-panel" open>
  <summary><i class="fa-solid fa-chevron-right chev"></i><i class="fa-solid fa-user-pen" style="color:#3b82f6"></i>
    Classificazione di <?=h($edit['full_name'])?>
    <span class="tr-chip <?= $edit['kind']==='interno'?'tr-int':'tr-ext' ?>"><?=h($edit['kind'])?></span>
    <span class="tr-hint"><?=h($edit['email'] ?? '')?></span></summary>
  <div class="tr-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <input type="hidden" name="<?= $edit['kind']==='interno' ? 'employee_id' : 'professional_id' ?>" value="<?=(int)$edit['person_id']?>">

      <div class="tr-grid">
        <div class="form-group"><label>Unità organizzativa</label>
          <select name="unit_id" id="unitSel" onchange="filterSubs()">
            <option value="">— non classificato —</option>
            <?php foreach ($units as $u): ?>
              <option value="<?=(int)$u['id']?>" <?= (int)($edit['unit_id'] ?? 0)===(int)$u['id']?'selected':'' ?>><?=h($u['name'])?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label>Sotto-unità</label>
          <select name="subunit_id" id="subSel">
            <option value="">— nessuna —</option>
            <?php foreach ($subs as $s): ?>
              <option value="<?=(int)$s['id']?>" data-unit="<?=(int)$s['unit_id']?>"
                <?= (int)($edit['subunit_id'] ?? 0)===(int)$s['id']?'selected':'' ?>><?=h($s['name'])?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label>Seniority</label>
          <select name="seniority">
            <?php foreach (['', 'Junior', 'Intermedio', 'Senior', 'Specialist', 'Lead'] as $s): ?>
              <option value="<?=h($s)?>" <?= ($edit['seniority'] ?? '')===$s?'selected':'' ?>><?= $s === '' ? '— non indicata —' : h($s) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label>Turno / pattern</label>
          <input type="text" name="shift_pattern" maxlength="60" value="<?=h($edit['shift_pattern'] ?? '')?>" placeholder="Es. L-V 9-18"></div>
        <div class="form-group" style="grid-column:span 2"><label>Competenza principale</label>
          <input type="text" name="main_skill" maxlength="120" value="<?=h($edit['main_skill'] ?? '')?>" placeholder="Es. VMware vSphere"></div>
        <div class="form-group"><label>In vigore dal</label>
          <input type="date" name="valid_from" value="<?=h($edit['valid_from'] ?? date('Y-m-d'))?>"></div>
        <div class="form-group" style="display:flex;align-items:flex-end;gap:14px">
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;padding-bottom:8px">
            <input type="checkbox" name="on_call" value="1" <?= !empty($edit['on_call'])?'checked':'' ?>> Reperibile</label>
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;padding-bottom:8px">
            <input type="checkbox" name="on_call_h24" value="1" <?= !empty($edit['on_call_h24'])?'checked':'' ?>> H24</label>
        </div>
        <div class="form-group" style="grid-column:span 2"><label>Certificazioni</label>
          <input type="text" name="certifications" value="<?=h($edit['certifications'] ?? '')?>" placeholder="Separate da virgola"></div>
        <div class="form-group" style="grid-column:span 2"><label>Note</label>
          <input type="text" name="notes" value="<?=h($edit['notes'] ?? '')?>"></div>
      </div>

      <div style="background:#f0f9ff;border:1px solid #93c5fd;border-radius:8px;padding:10px;margin-top:12px">
        <label style="display:flex;gap:8px;align-items:center;font-size:12px;font-weight:600;cursor:pointer">
          <input type="checkbox" name="historize" value="1" onchange="document.getElementById('reasonBox').style.display=this.checked?'block':'none'">
          <i class="fa-solid fa-clock-rotate-left" style="color:#0369a1"></i>
          Registra questa variazione nello storico
        </label>
        <div id="reasonBox" style="display:none;margin-top:8px">
          <input type="text" name="change_reason" maxlength="255" style="width:100%"
                 placeholder="Motivo della variazione, es. passaggio da Help Desk a Sistemista Infrastruttura">
          <small style="color:var(--muted);font-size:10px">
            L'assegnazione precedente viene chiusa il giorno prima della data di decorrenza.
            Spuntare solo per i veri cambi di assegnazione, non per correggere un refuso.
          </small>
        </div>
      </div>

      <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
        <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500">
          <input type="checkbox" name="is_active" value="1" <?= (!isset($edit['is_active']) || $edit['is_active'])?'checked':'' ?>> Profilo attivo</label>
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Salva classificazione</button>
        <a class="btn btn-sm" href="<?=url_safe('tech_registry')?>">Annulla</a>
      </div>
    </form>

    <?php if ($history): ?>
      <div style="margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px">
        <h4 style="margin:0 0 8px;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.5px">
          <i class="fa-solid fa-clock-rotate-left"></i> Storico delle assegnazioni</h4>
        <table class="data-table" style="width:100%;font-size:11px">
          <thead><tr><th>Dal</th><th>Al</th><th>Unità</th><th>Sotto-unità</th><th>Seniority</th>
            <th style="text-align:center">Reper.</th><th>Motivo</th></tr></thead>
          <tbody>
          <?php foreach ($history as $hh): ?>
            <tr>
              <td><?=date('d/m/Y', strtotime($hh['valid_from']))?></td>
              <td><?= $hh['valid_to'] ? date('d/m/Y', strtotime($hh['valid_to']))
                    : '<span style="color:#16a34a;font-weight:600">in corso</span>' ?></td>
              <td><?=h($hh['unit_label'] ?? '—')?></td>
              <td><?=h($hh['subunit_label'] ?? '—')?></td>
              <td><?=h($hh['seniority'] ?? '—')?></td>
              <td style="text-align:center"><?= ((int)$hh['on_call_h24']) ? 'H24' : (((int)$hh['on_call']) ? 'sì' : '—') ?></td>
              <td style="color:var(--muted)"><?=h($hh['change_reason'] ?? '')?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p style="color:var(--muted);font-size:10px;margin-top:6px">
          Le denominazioni sono quelle in vigore al momento della registrazione: se un'unità viene rinominata,
          lo storico continua a riportare il nome di allora.
        </p>
      </div>
    <?php endif; ?>
  </div>
</details>
<script>
function filterSubs(){
  var u = document.getElementById('unitSel').value, s = document.getElementById('subSel');
  // la sotto-unità deve appartenere all'unità scelta, altrimenti il salvataggio la rifiuta
  for (var i=0; i<s.options.length; i++){
    var o = s.options[i];
    if (!o.value) continue;
    var show = (o.getAttribute('data-unit') === u);
    o.hidden = !show;
    if (!show && o.selected) s.value = '';
  }
}
filterSubs();
</script>
<?php endif; ?>

<details class="tr-panel" <?= $active ? 'open' : '' ?>>
  <summary><i class="fa-solid fa-chevron-right chev"></i><i class="fa-solid fa-filter" style="color:#3b82f6"></i>
    Filtri di ricerca <?php if ($active): ?><span class="tr-badge">attivi</span><?php endif; ?>
    <span class="tr-hint">interni ed esterni nello stesso elenco</span></summary>
  <div class="tr-body">
    <form method="get">
      <?= route_slug_field() ?>
      <div class="tr-grid">
        <div class="form-group"><label>Cerca</label>
          <input type="text" name="q" value="<?=h($f['q'])?>" placeholder="nome, email, matricola o sigla"></div>
        <div class="form-group"><label>Unità organizzativa</label>
          <select name="unit"><option value="">— tutte —</option>
            <?php foreach ($units as $u): ?><option value="<?=(int)$u['id']?>" <?=$f['unit']===(int)$u['id']?'selected':''?>><?=h($u['name'])?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Sotto-unità</label>
          <select name="subunit"><option value="">— tutte —</option>
            <?php foreach ($subs as $s): ?><option value="<?=(int)$s['id']?>" <?=$f['subunit']===(int)$s['id']?'selected':''?>>
              <?=h(($unitById[(int)$s['unit_id']]['name'] ?? '') . ' › ' . $s['name'])?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Tipo di rapporto</label>
          <select name="kind"><option value="">— tutti —</option>
            <option value="interno" <?=$f['kind']==='interno'?'selected':''?>>Tecnico interno</option>
            <option value="esterno" <?=$f['kind']==='esterno'?'selected':''?>>Professionista esterno</option></select></div>
        <div class="form-group"><label>Reperibilità</label>
          <select name="oncall"><option value="">— indifferente —</option>
            <option value="1"   <?=$f['oncall']==='1'?'selected':''?>>Reperibili</option>
            <option value="h24" <?=$f['oncall']==='h24'?'selected':''?>>Solo H24</option></select></div>
        <div class="form-group"><label>Stato del profilo</label>
          <select name="stato">
            <option value="attivi"   <?=$f['stato']==='attivi'?'selected':''?>>Attivi</option>
            <option value="inattivi" <?=$f['stato']==='inattivi'?'selected':''?>>Disattivati</option>
            <option value="tutti"    <?=$f['stato']==='tutti'?'selected':''?>>Tutti</option></select></div>
        <div class="form-group" style="display:flex;align-items:flex-end">
          <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:500;padding-bottom:8px">
            <input type="checkbox" name="unclass" value="1" <?=$f['unclass']?'checked':''?>> Solo da classificare</label></div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
        <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Applica</button>
        <?php if ($active): ?><a class="btn btn-sm" href="<?=url_safe('tech_registry')?>"><i class="fa-solid fa-eraser"></i> Azzera</a><?php endif; ?>
        <span style="color:var(--muted);font-size:12px;margin-left:auto"><strong><?=count($rows)?></strong> persone</span>
      </div>
    </form>
  </div>
</details>

<div class="card" style="overflow-x:auto">
  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr>
      <th>Nominativo</th><th>Tipo</th><th>Codice</th><th>Azienda</th>
      <th>Unità organizzativa</th><th>Sotto-unità</th><th>Seniority</th>
      <th style="text-align:center">Reperibilità</th><th>Competenza</th><th>Ruolo di origine</th>
      <?php if ($can_edit): ?><th style="width:40px"></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:20px">Nessuna persona corrisponde ai filtri.</td></tr>
    <?php else: foreach ($rows as $r):
        $un = $unitById[(int)($r['unit_id'] ?? 0)] ?? null;
        $sb = $subById[(int)($r['subunit_id'] ?? 0)] ?? null;
    ?>
      <tr<?= ($r['profile_id'] && !$r['profile_active']) ? ' style="opacity:.55"' : '' ?>>
        <td style="font-weight:600"><?=h($r['full_name'] ?: '—')?></td>
        <td><span class="tr-chip <?= $r['kind']==='interno'?'tr-int':'tr-ext' ?>"><?=h($r['kind'])?></span></td>
        <td><code><?=h($r['code'] ?? '')?></code></td>
        <td><?=h($r['company_name'] ?? '—')?></td>
        <td><?php if ($un): ?><span class="tr-dot" style="background:<?=h($un['color'] ?: '#94a3b8')?>"></span><?=h($un['name'])?>
            <?php else: ?><span style="color:#dc2626;font-size:11px">da classificare</span><?php endif; ?></td>
        <td><?=h($sb['name'] ?? '—')?></td>
        <td><?=h($r['seniority'] ?? '—')?></td>
        <td style="text-align:center"><?php
          if ((int)($r['on_call_h24'] ?? 0)) echo '<span style="color:#b45309;font-weight:700" title="Reperibile H24">H24</span>';
          elseif ((int)($r['on_call'] ?? 0)) echo '<i class="fa-solid fa-phone-volume" style="color:#f59e0b" title="Reperibile"></i>';
          else echo '—'; ?></td>
        <td><?=h($r['main_skill'] ?? '—')?></td>
        <td style="color:var(--muted)"><?=h($r['role_raw'] ?? '—')?></td>
        <?php if ($can_edit): ?>
          <td><a class="btn btn-sm btn-blue" title="Classifica"
                 href="<?=url_safe('tech_registry',['edit_kind'=>$r['kind'],'edit_person'=>(int)$r['person_id']])?>">
              <i class="fa-solid fa-user-pen"></i></a></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <p style="color:var(--muted);font-size:11px;margin-top:8px">
    I <strong>tecnici interni</strong> provengono dall'Anagrafica dipendenti, i <strong>professionisti esterni</strong>
    dall'Anagrafica Professionisti; gli esterni già associati a un dipendente non compaiono due volte.
    La tassonomia delle unità si gestisce in <a href="<?=url_safe('tech_units')?>">Unità Organizzative Tecniche</a>.
  </p>
</div>
<?php require_once('footer.php'); ?>
