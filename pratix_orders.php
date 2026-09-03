<?php
/**
 * pratix_orders.php — Ordinativi Pratix (v1.9.23)
 *
 * Raggruppa le operazioni di commessa per ordinativo, con l'elenco delle
 * commesse collegate, l'importo aggregato e le due validazioni richieste.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COSA LA PAGINA NON PUO' ANCORA FARE
 *
 * La verifica "la somma dei singoli corrisponde al totale dichiarato" richiede
 * `forms_contract_main_order.amount`, che nel portale non e' sincronizzato.
 *
 * La pagina lo DICHIARA in testa invece di mostrare una colonna vuota: un
 * cruscotto che espone una validazione mai eseguita fa credere che sia passata.
 */
require_once('access_control.php');
require_once('functions.php');

$u_id = (int)$_SESSION['user_id'];

/* `$pdo` arriva da access_control.php, come nelle altre pagine: aprirne una
   seconda connessione qui avrebbe raddoppiato le connessioni per pagina. */

/* ── filtri ─────────────────────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$cliente = trim((string)($_GET['cliente'] ?? ''));
$solo    = (string)($_GET['solo'] ?? '');   // multi | anomalie | senza_importo
$ordina  = (string)($_GET['ord'] ?? 'importo');

$pronto = true; $errore = '';
$quadro = []; $ordinativi = []; $anomalie = []; $righePer = [];

try {
    $quadro = $pdo->query("SELECT * FROM `v_cm_pratix_quadro`")->fetch(PDO::FETCH_ASSOC) ?: [];

    /* le condizioni si accumulano: i filtri sono in AND fra loro */
    $w = ['1=1']; $a = [];
    if ($q !== '') {
        $w[] = "(o.`order_code` LIKE ? OR EXISTS (
                    SELECT 1 FROM `v_cm_pratix_righe` r2
                     WHERE r2.`order_code` = o.`order_code`
                       AND (r2.`commessa` LIKE ? OR r2.`cliente` LIKE ?
                            OR r2.`descrizione` LIKE ?)))";
        $lk = '%' . $q . '%';
        array_push($a, $lk, $lk, $lk, $lk);
    }
    if ($cliente !== '') {
        $w[] = "EXISTS (SELECT 1 FROM `v_cm_pratix_righe` r3
                         WHERE r3.`order_code` = o.`order_code` AND r3.`cliente` LIKE ?)";
        $a[] = '%' . $cliente . '%';
    }
    if ($solo === 'multi')          $w[] = "o.`ha_codici_multipli` = 1";
    if ($solo === 'senza_importo')  $w[] = "o.`righe_senza_importo` > 0";
    if ($solo === 'anomalie')       $w[] = "(o.`ha_codici_multipli` = 1
                                             OR o.`righe_senza_importo` > 0
                                             OR o.`esito_validazione` = 'scostamento')";
    if ($solo === 'multicommessa')  $w[] = "o.`commesse` > 1";

    $ordSQL = match ($ordina) {
        'codice'   => "o.`order_code`",
        'commesse' => "o.`commesse` DESC, o.`importo_totale` DESC",
        'data'     => "o.`al` DESC",
        default    => "o.`importo_totale` DESC",
    };

    $st = $pdo->prepare(
        "SELECT o.* FROM `v_cm_pratix_ordinativi` o
          WHERE " . implode(' AND ', $w) . "
          ORDER BY $ordSQL LIMIT 300");
    $st->execute($a);
    $ordinativi = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();

    /* Le righe di dettaglio in UNA query sola invece di una per ordinativo.
       Con 300 ordinativi a video, una query ciascuno sarebbero 300 interrogazioni
       per una pagina. */
    if ($ordinativi) {
        $codici = array_column($ordinativi, 'order_code');
        $ph = implode(',', array_fill(0, count($codici), '?'));
        $st = $pdo->prepare(
            "SELECT * FROM `v_cm_pratix_righe`
              WHERE `order_code` IN ($ph)
              ORDER BY `order_code`, `importo` DESC");
        $st->execute($codici);
        /* La chiave e' normalizzata in MAIUSCOLO.
           MariaDB raggruppa con una collation _ci: 'a3992' e 'A3992' finiscono
           nello stesso ordinativo. Un array PHP invece distingue, e le righe di
           'a3992' non si sarebbero trovate sotto 'A3992': l'ordinativo sarebbe
           comparso con il totale giusto e meno righe di quelle che lo compongono.
           E' lo stesso difetto della v1.9.12 sugli stati dei moduli. */
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
            $righePer[mb_strtoupper(trim((string)$r['order_code']))][] = $r;
        $st->closeCursor();
    }

    $anomalie = $pdo->query(
        "SELECT * FROM `v_cm_pratix_anomalie` ORDER BY `priorita`, `importo_totale` DESC LIMIT 200")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pronto = false; $errore = $e->getMessage();
    $quadro = []; $ordinativi = $anomalie = []; $righePer = [];
}

/* ── export ─────────────────────────────────────────────────────────────── */
if ($pronto && ($_GET['export'] ?? '') === 'xlsx') {
    require_once(__DIR__ . '/app/XlsxWriter.php');
    $w = new XlsxWriter();

    $r1 = [['Ordinativo','Operazioni','Commesse','Clienti','Commesse attive',
            'Importo totale','Importo fatturato','Codici multipli','Righe senza importo',
            'Totale dichiarato','Scostamento','Esito validazione','Dal','Al']];
    foreach ($ordinativi as $x) $r1[] = [$x['order_code'], (int)$x['operazioni'],
        (int)$x['commesse'], (int)$x['clienti'], (int)$x['commesse_attive'],
        $x['importo_totale'], $x['importo_fatturato'],
        $x['ha_codici_multipli'] ? 'SI' : 'NO', (int)$x['righe_senza_importo'],
        $x['totale_dichiarato'], $x['scostamento'], $x['esito_validazione'],
        $x['dal'], $x['al']];
    $w->addSheet('Ordinativi', $r1);

    $r2 = [['Ordinativo','Commessa','Denominazione','Cliente','Tipo contratto',
            'Descrizione','Importo','Origine importo','Fatturato','Stato commessa',
            'Codici multipli','Data operazione']];
    foreach ($righePer as $cod => $righe)
        foreach ($righe as $x) $r2[] = [$x['order_code'], $x['commessa'], $x['denominazione'],
            $x['cliente'], $x['tipo_contratto'], $x['descrizione'], $x['importo'],
            $x['origine_importo'], $x['importo_fatturato'], $x['stato_commessa'],
            $x['codici_multipli'] ? 'SI' : 'NO', $x['data_operazione']];
    $w->addSheet('Commesse collegate', $r2);

    $r3 = [['Ordinativo','Anomalia','Operazioni','Commesse','Importo','Nota']];
    foreach ($anomalie as $x) $r3[] = [$x['order_code'], $x['anomalia'],
        (int)$x['operazioni'], (int)$x['commesse'], $x['importo_totale'], $x['nota']];
    $w->addSheet('Anomalie', $r3);

    write_log('Projects', 'info', 'Export ordinativi Pratix', $u_id);
    $w->download('ordinativi_pratix_' . date('Ymd_His') . '.xlsx');
    exit;
}

require_once('header.php');

$qs = function (array $over = []) use ($q, $cliente, $solo, $ordina) {
    $p = array_filter(['q' => $q, 'cliente' => $cliente, 'solo' => $solo,
                       'ord' => $ordina === 'importo' ? '' : $ordina],
                      fn($v) => $v !== '');
    return url_safe('pratix_orders', array_merge($p, $over));
};
$n  = fn($v) => number_format((float)$v, 0, ',', '.');
$n2 = fn($v) => $v === null ? '—' : number_format((float)$v, 2, ',', '.');
$attivi = ($q !== '') + ($cliente !== '') + ($solo !== '');
?>

<div class="page-header">
  <h1><i class="fa-solid fa-file-invoice"></i> Ordinativi Pratix</h1>
  <p class="sub">Operazioni di commessa raggruppate per ordinativo, con le commesse
    collegate e l'importo aggregato.</p>
</div>

<?php if (!$pronto): ?>
  <div class="card"><p style="color:#dc2626"><?=h($errore)?></p></div>
<?php else: ?>

<?php // ── il quadro ─────────────────────────────────────────────────────── ?>
<div class="card" style="margin-bottom:14px">
  <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">
    <?php foreach ([
      ['Ordinativi', $n($quadro['ordinativi'] ?? 0), '#0f766e',
       $n($quadro['operazioni'] ?? 0) . ' operazioni'],
      ['Importo totale', $n2($quadro['importo_totale'] ?? 0), '#2563eb', ''],
      ['Commesse', $n($quadro['commesse'] ?? 0), '#334155',
       $n($quadro['clienti'] ?? 0) . ' clienti'],
      ['Su più commesse', $n($quadro['ordinativi_multi_commessa'] ?? 0), '#7c3aed',
       'relazione 1 a N'],
      ['Codici multipli', $n($quadro['con_codici_multipli'] ?? 0), '#dc2626',
       'in una cella sola'],
      ['Righe senza importo', $n($quadro['con_righe_vuote'] ?? 0), '#f59e0b',
       'ordinativi coinvolti'],
    ] as [$l, $v, $c, $sb]): ?>
      <div style="text-align:center;padding:11px;background:#f8fafc;border-radius:8px">
        <div style="font-size:16px;font-weight:800;color:<?=$c?>"><?=$v?></div>
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#334155"><?=h($l)?></div>
        <div style="font-size:10px;color:var(--muted)"><?=h($sb)?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php // la validazione senza termine di confronto: dichiarata, non nascosta ?>
  <?php if ((int)($quadro['senza_totale'] ?? 0) > 0): ?>
    <div style="background:#eff6ff;border-left:3px solid #2563eb;padding:10px 12px;
                border-radius:0 6px 6px 0;font-size:12px;margin-top:10px">
      <strong>La verifica «somma dei singoli contro totale dichiarato» non è ancora
      possibile</strong> su <?=$n($quadro['senza_totale'])?> ordinativi su
      <?=$n($quadro['ordinativi'] ?? 0)?>: l'importo totale dell'ordinativo
      (<code>forms_contract_main_order.amount</code>) non è sincronizzato nel portale.
      <br>La tabella <code>cm_pratix_orders</code> è pronta ad accoglierlo — servirebbe aggiungere
      il dataset di sincronizzazione. Finché è vuota, la colonna «esito» dice
      <strong>«totale non disponibile»</strong>, che non è un errore: è l'assenza del termine di
      confronto.
    </div>
  <?php endif; ?>

  <?php if ((int)($quadro['con_codici_multipli'] ?? 0) > 0): ?>
    <div style="background:#fef2f2;border-left:3px solid #dc2626;padding:10px 12px;
                border-radius:0 6px 6px 0;font-size:12px;margin-top:8px">
      <strong><?=$n($quadro['con_codici_multipli'])?> celle contengono più di un codice</strong>
      — per esempio <code>C2501 C2500 C2499 C1401</code> o <code>A0367 - A0489 - A0452</code>.
      Sono <strong>segnalate e non divise</strong>: ripartire l'importo fra i codici richiederebbe
      una scelta arbitraria — in parti uguali? tutto al primo? — che produrrebbe numeri precisi e
      inventati.
      <a href="<?=$qs(['solo' => 'multi'])?>" style="margin-left:6px">Vedi solo queste</a>
    </div>
  <?php endif; ?>
</div>

<?php // ── filtri ────────────────────────────────────────────────────────── ?>
<details class="pm-panel" <?= $attivi > 0 ? 'open' : '' ?>>
  <summary>
    <i class="fa-solid fa-chevron-right pm-chev"></i> Filtri
    <?php if ($attivi > 0): ?><span class="pm-badge"><?=$attivi?></span><?php endif; ?>
    <span class="pm-hint"><?=$n(count($ordinativi))?> ordinativi mostrati</span>
  </summary>
  <div class="pm-panel-body">
    <form method="get">
      <?= route_slug_field() ?>
      <div class="pm-grid-auto">
        <div class="form-group"><label>Cerca ovunque</label>
          <input type="text" name="q" value="<?=h($q)?>"
                 placeholder="ordinativo, commessa, cliente, descrizione"></div>
        <div class="form-group"><label>Cliente</label>
          <input type="text" name="cliente" value="<?=h($cliente)?>"
                 placeholder="parte della ragione sociale"></div>
        <div class="form-group"><label>Mostra</label>
          <select name="solo">
            <option value="">Tutti gli ordinativi</option>
            <option value="anomalie"      <?=$solo==='anomalie'?'selected':''?>>Solo con anomalie</option>
            <option value="multi"         <?=$solo==='multi'?'selected':''?>>Solo con codici multipli</option>
            <option value="multicommessa" <?=$solo==='multicommessa'?'selected':''?>>Solo su più commesse</option>
            <option value="senza_importo" <?=$solo==='senza_importo'?'selected':''?>>Solo con righe senza importo</option>
          </select></div>
        <div class="form-group"><label>Ordina per</label>
          <select name="ord">
            <option value="importo"  <?=$ordina==='importo'?'selected':''?>>Importo decrescente</option>
            <option value="commesse" <?=$ordina==='commesse'?'selected':''?>>Numero di commesse</option>
            <option value="codice"   <?=$ordina==='codice'?'selected':''?>>Codice ordinativo</option>
            <option value="data"     <?=$ordina==='data'?'selected':''?>>Data più recente</option>
          </select></div>
      </div>
      <div class="pm-actions">
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-filter"></i> Applica</button>
        <a class="btn btn-sm" href="<?=url_safe('pratix_orders')?>">Azzera</a>
        <a class="btn btn-sm" href="<?=$qs(['export'=>'xlsx'])?>">
          <i class="fa-solid fa-file-excel"></i> XLSX</a>
      </div>
    </form>
  </div>
</details>

<?php // ── l'elenco raggruppato ──────────────────────────────────────────── ?>
<?php if (!$ordinativi): ?>
  <div class="card"><p style="color:var(--muted);text-align:center;padding:24px">
    Nessun ordinativo corrisponde ai filtri.</p></div>
<?php else: ?>
  <?php foreach ($ordinativi as $o):
    $righe = $righePer[mb_strtoupper(trim((string)$o['order_code']))] ?? [];
    $multi = (int)$o['ha_codici_multipli'] === 1;
    $vuote = (int)$o['righe_senza_importo'] > 0;
    $bordo = $multi ? '#dc2626' : ($vuote ? '#f59e0b' : '#0f766e');
  ?>
    <div class="card" style="margin-bottom:12px;border-left:4px solid <?=$bordo?>">
      <div class="card-header" style="flex-wrap:wrap">
        <span class="card-title" style="font-family:monospace">
          <i class="fa-solid fa-hashtag"></i> <?=h($o['order_code'])?></span>

        <?php if ($multi): ?>
          <span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:9px;
                color:#fff;background:#dc2626;margin-left:8px">PIÙ CODICI</span>
        <?php endif; ?>
        <?php if ((int)$o['commesse'] > 1): ?>
          <span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:9px;
                color:#fff;background:#7c3aed;margin-left:5px">
            <?=$n($o['commesse'])?> COMMESSE</span>
        <?php endif; ?>
        <?php if ($vuote): ?>
          <span style="font-size:9px;font-weight:700;padding:2px 7px;border-radius:9px;
                color:#fff;background:#f59e0b;margin-left:5px">
            <?=$n($o['righe_senza_importo'])?> SENZA IMPORTO</span>
        <?php endif; ?>

        <span style="margin-left:auto;font-size:17px;font-weight:800;color:#0f766e">
          <?=$n2($o['importo_totale'])?> €</span>
      </div>

      <?php // la validazione, con il suo esito dichiarato ?>
      <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:11px;color:var(--muted);
                  margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #f1f5f9">
        <span><?=$n($o['operazioni'])?> operazioni</span>
        <span><?=$n($o['clienti'])?> clienti</span>
        <span><?=$n($o['commesse_attive'])?> commesse attive</span>
        <?php if ($o['dal']): ?>
          <span><?=date('d/m/Y', strtotime($o['dal']))?> – <?=date('d/m/Y', strtotime($o['al']))?></span>
        <?php endif; ?>
        <span style="margin-left:auto">
          <?php $esito = $o['esito_validazione'];
                $colE = ['coincide'=>'#16a34a','scostamento'=>'#dc2626'][$esito] ?? '#94a3b8'; ?>
          <strong style="color:<?=$colE?>">
            <?php if ($esito === 'coincide'): ?>
              <i class="fa-solid fa-check"></i> somma = totale dichiarato
            <?php elseif ($esito === 'scostamento'): ?>
              <i class="fa-solid fa-triangle-exclamation"></i>
              scostamento <?=$n2($o['scostamento'])?> € su <?=$n2($o['totale_dichiarato'])?>
            <?php else: ?>
              totale dichiarato non disponibile
            <?php endif; ?>
          </strong>
        </span>
      </div>

      <?php // le commesse collegate ?>
      <table class="data-table" style="width:100%;font-size:11px">
        <thead><tr><th>Commessa</th><th>Cliente</th><th>Tipo contratto</th>
          <th>Descrizione</th><th style="text-align:right">Importo</th>
          <th style="text-align:center">Stato</th><th style="width:36px"></th></tr></thead>
        <tbody>
        <?php foreach ($righe as $r): ?>
          <tr>
            <td style="font-family:monospace;font-weight:600"><?=h($r['commessa'])?></td>
            <td><?=h(mb_strimwidth((string)$r['cliente'], 0, 26, '…'))?></td>
            <td style="font-size:10px"><?=h(mb_strimwidth((string)$r['tipo_contratto'], 0, 22, '…'))?></td>
            <td style="font-size:10px;color:var(--muted)">
              <?= $r['descrizione'] !== null && $r['descrizione'] !== ''
                    ? h(mb_strimwidth((string)$r['descrizione'], 0, 42, '…'))
                    : '<em>' . h($r['nome_operazione'] ?? '—') . '</em>' ?></td>
            <td style="text-align:right;font-weight:700">
              <?=$n2($r['importo'])?>
              <?php if ($r['origine_importo'] === 'previsto'): ?>
                <span style="font-size:8px;color:#f59e0b;font-weight:700" title="Valore previsto, non consolidato">P</span>
              <?php elseif ($r['origine_importo'] === 'non valorizzato'): ?>
                <span style="font-size:8px;color:#dc2626;font-weight:700" title="Nessun importo">—</span>
              <?php endif; ?></td>
            <td style="text-align:center">
              <span style="font-size:9px;padding:1px 6px;border-radius:8px;color:#fff;
                    background:<?=$r['commessa_attiva'] ? '#16a34a' : '#94a3b8'?>">
                <?=h($r['stato_commessa'] ?? '—')?></span></td>
            <td style="text-align:center">
              <?php if ($r['commessa_id']): ?>
                <a href="<?=url_safe('project_dashboard', ['id' => (int)$r['commessa_id']])?>"
                   title="Apri la commessa"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
              <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
          <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #cbd5e1">
            <td colspan="4">TOTALE ORDINATIVO</td>
            <td style="text-align:right"><?=$n2($o['importo_totale'])?> €</td>
            <td colspan="2"></td>
          </tr>
        </tbody>
      </table>

      <?php if ($multi): ?>
        <p style="font-size:10px;color:#991b1b;margin:6px 0 0">
          <i class="fa-solid fa-triangle-exclamation"></i>
          La cella contiene più di un codice: l'importo di <?=$n2($o['importo_totale'])?> €
          <strong>non è attribuibile a un ordinativo solo</strong>.
        </p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (count($ordinativi) >= 300): ?>
    <p style="font-size:11px;color:var(--muted);text-align:center">
      Mostrati i primi 300 ordinativi. Restringete con i filtri per vedere gli altri.</p>
  <?php endif; ?>
<?php endif; ?>

<?php // ── le anomalie in elenco ─────────────────────────────────────────── ?>
<?php if ($anomalie && $solo === ''): ?>
  <div class="card" style="margin-top:14px;border-left:4px solid #f59e0b">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-triangle-exclamation"></i>
        Anomalie rilevate</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=$n(count($anomalie))?> segnalazioni</span>
    </div>
    <div class="pm-scroll" style="max-height:40vh">
      <table class="data-table" style="width:100%;font-size:11px">
        <thead><tr><th>Ordinativo</th><th>Anomalia</th>
          <th style="text-align:right">Operazioni</th><th style="text-align:right">Commesse</th>
          <th style="text-align:right">Importo</th><th>Nota</th></tr></thead>
        <tbody>
        <?php foreach ($anomalie as $x):
          $colAn = ['codici multipli nella cella' => '#dc2626',
                    'scostamento dal totale dichiarato' => '#b91c1c',
                    'operazioni senza importo' => '#f59e0b'][$x['anomalia']] ?? '#64748b'; ?>
          <tr>
            <td style="font-family:monospace;font-weight:600">
              <a href="<?=$qs(['q' => $x['order_code'], 'solo' => null])?>"><?=h($x['order_code'])?></a></td>
            <td><span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;
                  color:#fff;background:<?=$colAn?>"><?=h($x['anomalia'])?></span></td>
            <td style="text-align:right"><?=$n($x['operazioni'])?></td>
            <td style="text-align:right"><?=$n($x['commesse'])?></td>
            <td style="text-align:right;font-weight:700"><?=$n2($x['importo_totale'])?></td>
            <td style="font-size:10px;color:var(--muted)"><?=h($x['nota'])?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<p style="font-size:11px;color:var(--muted);margin-top:14px">
  La relazione è <code>forms_contract_main_order.code</code> =
  <code>forms_contract_operation.order_code</code>: un ordinativo, più commesse.
  L'importo di ogni riga è il <strong>valore consolidato</strong> quando c'è, quello
  <strong>previsto</strong> altrimenti — le righe previste sono marcate con
  <strong style="color:#f59e0b">P</strong>, perché sommare gli uni e gli altri
  indifferentemente darebbe un totale misto.
</p>

<?php endif; ?>
<?php require_once('footer.php'); ?>
