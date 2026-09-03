<?php
/**
 * system_errors.php — Diagnostica errori PHP (v1.9.21)
 *
 * Nasce da un caso concreto: la v1.9.19 aveva un `Warning: Undefined variable
 * $gOp`. Con gli avvisi visibili si e' notato subito; con `display_errors = Off`
 * e nessun log lo stesso difetto si sarebbe manifestato come un riquadro che non
 * compare, senza lasciare traccia da nessuna parte.
 *
 * La pagina risponde a tre domande:
 *
 *   1. la configurazione attuale e' adatta all'ambiente?
 *   2. dove finiscono gli errori, e ci si puo' arrivare?
 *   3. cosa e' successo di recente?
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PERCHE' SOLO IN LETTURA
 *
 * La pagina NON modifica la configurazione. `display_errors` e `log_errors` sono
 * direttive che in molte installazioni non sono modificabili a runtime
 * (PHP_INI_SYSTEM per alcune SAPI), e un pannello che offre un interruttore che
 * a volte non funziona e' peggio di nessun interruttore.
 *
 * Mostra invece il valore attuale, quello raccomandato, e la riga di php.ini da
 * scrivere.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PERCHE' SOLO IL SUPER ADMIN
 *
 * Il log degli errori contiene percorsi del filesystem, frammenti di query e a
 * volte valori dei parametri. E' materiale che aiuta un attaccante tanto quanto
 * aiuta chi ripara.
 */
require_once('access_control.php');
require_once('functions.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
if ($u_role !== 1) { header('Location: unauthorized.php'); exit(); }

$app_root = __DIR__;

/* ──────────────────────────────────────────────────────────────────────────
 * La configurazione attuale.
 *
 * `ini_get` restituisce stringhe: '1', '0', '' oppure 'stderr'. La conversione
 * a booleano va fatta a mano perche' (bool)'0' e' false ma (bool)'Off' sarebbe
 * true, e alcune installazioni scrivono 'Off' nel php.ini.
 * ────────────────────────────────────────────────────────────────────────── */
$flag = function (string $chiave): ?bool {
    $v = ini_get($chiave);
    if ($v === false) return null;
    $v = strtolower(trim((string)$v));
    if (in_array($v, ['1', 'on', 'true', 'yes'], true))  return true;
    if (in_array($v, ['0', 'off', 'false', 'no', ''], true)) return false;
    return (bool)$v;
};

$display   = $flag('display_errors');
$displayS  = $flag('display_startup_errors');
$logErr    = $flag('log_errors');
$logFile   = trim((string)ini_get('error_log'));
$reporting = (int)ini_get('error_reporting');
$maxLen    = (int)ini_get('log_errors_max_len');
$sapi      = PHP_SAPI;

/* la destinazione reale degli errori: `error_log` vuoto significa "il log del
   server web", che su XAMPP e' apache/logs/error.log */
$logReale = $logFile !== '' ? $logFile : '(log del server web)';
$logPath  = $logFile !== '' ? $logFile : '';
if ($logPath === '') {
    foreach ([dirname($app_root, 2) . '/apache/logs/error.log',
              dirname($app_root, 2) . '/logs/php_error_log',
              '/var/log/apache2/error.log'] as $c) {
        if (@is_readable($c)) { $logPath = $c; break; }
    }
}
$logLeggibile = $logPath !== '' && @is_readable($logPath);
$logDim       = $logLeggibile ? (int)@filesize($logPath) : 0;

/* ──────────────────────────────────────────────────────────────────────────
 * La configurazione raccomandata.
 *
 * Non esiste una risposta buona per tutti gli ambienti: in sviluppo gli errori
 * vanno visti, in produzione vanno registrati e non mostrati. Il portale non
 * sa in quale dei due si trova, quindi mostra entrambe le colonne e lascia
 * decidere.
 * ────────────────────────────────────────────────────────────────────────── */
$racc = [
    ['display_errors', $display, false, true,
     'In produzione Off: un avviso a schermo espone percorsi e struttura del codice a chiunque apra la pagina.'],
    ['log_errors', $logErr, true, true,
     'Sempre On, in entrambi gli ambienti. Senza, un difetto che non si vede a schermo non lascia alcuna traccia.'],
    ['display_startup_errors', $displayS, false, true,
     'Riguarda gli errori di avvio di PHP, prima che il codice venga eseguito.'],
];

/* ──────────────────────────────────────────────────────────────────────────
 * Il contenuto del log.
 *
 * Si legge dalla FINE: un log di errori cresce in coda, e le righe che
 * interessano sono le ultime. Leggerlo dall'inizio su un file da decine di MB
 * caricherebbe in memoria mesi di righe per mostrarne venti.
 * ────────────────────────────────────────────────────────────────────────── */
$codaLog = function (string $path, int $righe = 200, int $maxByte = 524288): array {
    if (!@is_readable($path)) return [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    $dim = filesize($path);
    $da  = max(0, $dim - $maxByte);
    fseek($fh, $da);
    $buf = stream_get_contents($fh);
    fclose($fh);
    // se abbiamo tagliato a meta' riga, la prima e' incompleta e va scartata
    if ($da > 0) { $p = strpos($buf, "\n"); if ($p !== false) $buf = substr($buf, $p + 1); }
    $tutte = array_filter(explode("\n", $buf), fn($r) => trim($r) !== '');
    return array_slice(array_reverse($tutte), 0, $righe);
};

$q     = trim((string)($_GET['q'] ?? ''));
$tipo  = (string)($_GET['tipo'] ?? '');
$righe = $logLeggibile ? $codaLog($logPath, 400) : [];

/* classificazione delle righe: il tipo sta fra le prime parole */
$classifica = function (string $r): string {
    if (stripos($r, 'Fatal error') !== false || stripos($r, 'Uncaught') !== false) return 'fatale';
    if (stripos($r, 'Parse error') !== false)   return 'sintassi';
    if (stripos($r, 'Warning') !== false)       return 'avviso';
    if (stripos($r, 'Notice') !== false)        return 'nota';
    if (stripos($r, 'Deprecated') !== false)    return 'deprecato';
    return 'altro';
};

$conta = ['fatale'=>0,'sintassi'=>0,'avviso'=>0,'nota'=>0,'deprecato'=>0,'altro'=>0];
$filtrate = [];
foreach ($righe as $r) {
    $c = $classifica($r);
    $conta[$c]++;
    if ($tipo !== '' && $c !== $tipo) continue;
    if ($q !== '' && stripos($r, $q) === false) continue;
    $filtrate[] = ['riga' => $r, 'classe' => $c];
}

/* le righe che riguardano il portale, distinte da quelle di altri applicativi */
$nostre = 0;
foreach ($righe as $r) if (stripos($r, 'portalmanager') !== false) $nostre++;

require_once('header.php');

$qs = function (array $over = []) use ($q, $tipo) {
    $p = array_filter(['q' => $q, 'tipo' => $tipo], fn($v) => $v !== '');
    return url_safe('system_errors', array_merge($p, $over));
};
$n = fn($v) => number_format((float)$v, 0, ',', '.');
$peso = function (int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return number_format($b / 1024, 1, ',', '.') . ' KB';
    return number_format($b / 1048576, 1, ',', '.') . ' MB';
};
$colC = ['fatale'=>'#dc2626','sintassi'=>'#b91c1c','avviso'=>'#f59e0b',
         'nota'=>'#0891b2','deprecato'=>'#7c3aed','altro'=>'#64748b'];
?>

<div class="page-header">
  <h1><i class="fa-solid fa-bug"></i> Diagnostica errori PHP</h1>
  <p class="sub">Configurazione, destinazione e contenuto del registro degli errori.
    Pagina riservata al super amministratore.</p>
</div>

<?php // ── la configurazione attuale contro quella raccomandata ─────────────── ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-sliders"></i> Configurazione</span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">
      PHP <?=h(PHP_VERSION)?> · SAPI <?=h($sapi)?></span>
  </div>

  <table class="data-table" style="width:100%;font-size:12px">
    <thead><tr><th>Direttiva</th><th style="text-align:center">Valore attuale</th>
      <th style="text-align:center">Produzione</th><th style="text-align:center">Sviluppo</th>
      <th>Perché</th></tr></thead>
    <tbody>
    <?php foreach ($racc as [$nome, $att, $prod, $svil, $perche]):
      $okProd = ($att === $prod); ?>
      <tr>
        <td style="font-family:monospace;font-weight:600"><?=h($nome)?></td>
        <td style="text-align:center">
          <span style="font-weight:700;padding:2px 9px;border-radius:9px;color:#fff;
                background:<?= $att === null ? '#94a3b8' : ($att ? '#16a34a' : '#64748b') ?>">
            <?= $att === null ? 'non leggibile' : ($att ? 'On' : 'Off') ?></span>
        </td>
        <td style="text-align:center;color:<?=$okProd?'#16a34a':'#dc2626'?>;font-weight:600">
          <?= $prod ? 'On' : 'Off' ?>
          <?php if (!$okProd): ?><i class="fa-solid fa-triangle-exclamation"></i><?php endif; ?>
        </td>
        <td style="text-align:center;color:var(--muted)"><?= $svil ? 'On' : 'Off' ?></td>
        <td style="font-size:11px;color:var(--muted)"><?=h($perche)?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php // il caso peggiore: errori né mostrati né registrati ?>
  <?php if ($display === false && $logErr === false): ?>
    <div style="background:#fef2f2;border-left:3px solid #dc2626;padding:10px 12px;
                border-radius:0 6px 6px 0;font-size:12px;margin-top:10px">
      <strong>Gli errori non vengono né mostrati né registrati.</strong>
      Un difetto si manifesta come una pagina incompleta o un riquadro che non compare,
      <strong>senza lasciare alcuna traccia</strong>. È la configurazione in cui è più difficile
      capire cosa non funziona.
    </div>
  <?php elseif ($logErr === false): ?>
    <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:10px 12px;
                border-radius:0 6px 6px 0;font-size:12px;margin-top:10px">
      <strong><code>log_errors</code> è disattivato.</strong> Gli errori che non vedete a schermo
      non vengono registrati da nessuna parte: quando un utente segnala un problema, non c'è nulla
      da consultare.
    </div>
  <?php endif; ?>

  <?php if ($display === true): ?>
    <div style="background:#fffbeb;border-left:3px solid #f59e0b;padding:10px 12px;
                border-radius:0 6px 6px 0;font-size:12px;margin-top:8px">
      <strong><code>display_errors</code> è attivo.</strong> Va bene in sviluppo. In produzione un
      avviso a schermo espone percorsi del filesystem e struttura del codice a chiunque apra la
      pagina — compreso chi non dovrebbe.
    </div>
  <?php endif; ?>

  <div style="margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9">
    <div style="font-size:12px;font-weight:700;margin-bottom:5px">Livello di segnalazione</div>
    <div style="font-size:11px;color:var(--muted)">
      <code>error_reporting</code> = <strong><?=$reporting?></strong>
      <?php
        $livelli = ['E_ERROR'=>E_ERROR,'E_WARNING'=>E_WARNING,'E_PARSE'=>E_PARSE,
                    'E_NOTICE'=>E_NOTICE,'E_DEPRECATED'=>E_DEPRECATED,'E_STRICT'=>2048];
        $attivi = [];
        foreach ($livelli as $lb => $vl) if ($reporting & $vl) $attivi[] = $lb;
      ?>
      — <?= $attivi ? h(implode(', ', $attivi)) : 'nessun livello attivo' ?>
      <?php if (!($reporting & E_WARNING)): ?>
        <br><strong style="color:#dc2626">E_WARNING non è attivo</strong>: avvisi come
        «Undefined variable» non vengono segnalati né a schermo né nel log.
      <?php endif; ?>
    </div>
  </div>
</div>

<?php // ── dove finiscono gli errori ────────────────────────────────────────── ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-file-lines"></i> Destinazione del registro</span>
  </div>

  <table class="data-table" style="width:100%;font-size:12px">
    <tbody>
      <tr><td style="width:200px;font-weight:600">Percorso configurato</td>
        <td style="font-family:monospace;font-size:11px">
          <?= $logFile !== '' ? h($logFile) : '<em style="color:var(--muted)">non impostato — usa il log del server web</em>' ?></td></tr>
      <tr><td style="font-weight:600">File individuato</td>
        <td style="font-family:monospace;font-size:11px">
          <?= $logPath !== '' ? h($logPath) : '<em style="color:#dc2626">nessuno</em>' ?></td></tr>
      <tr><td style="font-weight:600">Leggibile dal portale</td>
        <td><span style="font-weight:700;padding:2px 9px;border-radius:9px;color:#fff;
              background:<?=$logLeggibile?'#16a34a':'#dc2626'?>">
          <?= $logLeggibile ? 'sì' : 'no' ?></span>
          <?php if ($logLeggibile): ?>
            <span style="color:var(--muted);margin-left:8px"><?=$peso($logDim)?></span>
          <?php endif; ?></td></tr>
      <tr><td style="font-weight:600">Troncamento righe</td>
        <td><?= $maxLen > 0 ? $n($maxLen) . ' caratteri' : 'nessuno' ?>
          <?php if ($maxLen > 0 && $maxLen < 2048): ?>
            <span style="color:#f59e0b;font-size:11px;margin-left:6px">
              — una traccia di stack lunga viene tagliata</span>
          <?php endif; ?></td></tr>
    </tbody>
  </table>

  <?php if (!$logLeggibile): ?>
    <p style="font-size:11px;color:var(--muted);margin-top:8px">
      Il portale non riesce a leggere il file di log. Può dipendere dal percorso, dai permessi,
      oppure dal fatto che gli errori finiscono nel log del server web anziché in un file dedicato.
      Impostare un percorso esplicito rende il registro consultabile da qui.
    </p>
  <?php endif; ?>
</div>

<?php // ── le righe da scrivere in php.ini ──────────────────────────────────── ?>
<div class="card" style="margin-bottom:14px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-code"></i> Righe per <code>php.ini</code></span>
    <span style="font-size:11px;color:var(--muted);margin-left:8px">
      questa pagina non modifica la configurazione</span>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <div>
      <div style="font-size:12px;font-weight:700;margin-bottom:5px;color:#16a34a">Produzione</div>
      <pre style="background:#f8fafc;padding:10px;border-radius:6px;font-size:11px;margin:0;
                  overflow-x:auto">display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = "<?=h($app_root)?>/logs/php_error.log"
error_reporting = E_ALL &amp; ~E_DEPRECATED</pre>
    </div>
    <div>
      <div style="font-size:12px;font-weight:700;margin-bottom:5px;color:#2563eb">Sviluppo</div>
      <pre style="background:#f8fafc;padding:10px;border-radius:6px;font-size:11px;margin:0;
                  overflow-x:auto">display_errors = On
display_startup_errors = On
log_errors = On
error_log = "<?=h($app_root)?>/logs/php_error.log"
error_reporting = E_ALL</pre>
    </div>
  </div>

  <p style="font-size:11px;color:var(--muted);margin-top:10px">
    <strong>La pagina non offre un interruttore</strong>: <code>display_errors</code> e
    <code>log_errors</code> in molte installazioni non sono modificabili a runtime, e un pannello
    con un comando che a volte non funziona è peggio di nessun comando.
    Dopo aver modificato <code>php.ini</code> serve un <strong>riavvio di Apache</strong>.
    La cartella indicata in <code>error_log</code> deve esistere ed essere scrivibile dall'utente
    del server web.
  </p>
</div>

<?php // ── il contenuto del registro ─────────────────────────────────────────── ?>
<?php if ($logLeggibile): ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-list"></i> Ultime righe del registro</span>
      <span style="font-size:11px;color:var(--muted);margin-left:8px">
        <?=$n(count($righe))?> righe lette dalla coda del file<?php
        if ($nostre > 0): ?> · <?=$n($nostre)?> riguardano il portale<?php endif; ?></span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:10px">
      <?php foreach ($conta as $c => $v): ?>
        <a href="<?=$qs(['tipo' => $tipo === $c ? null : $c])?>"
           style="text-decoration:none;text-align:center;padding:9px;border-radius:8px;
                  background:<?=$tipo === $c ? $colC[$c] : '#f8fafc'?>;
                  color:<?=$tipo === $c ? '#fff' : 'inherit'?>">
          <div style="font-size:16px;font-weight:800;
                color:<?=$tipo === $c ? '#fff' : $colC[$c]?>"><?=$n($v)?></div>
          <div style="font-size:9px;font-weight:700;text-transform:uppercase"><?=h($c)?></div>
        </a>
      <?php endforeach; ?>
    </div>

    <form method="get" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:10px">
      <?= route_slug_field() ?>
      <?php if ($tipo !== ''): ?><input type="hidden" name="tipo" value="<?=h($tipo)?>"><?php endif; ?>
      <div class="form-group" style="margin:0;flex:1">
        <label>Cerca nel registro</label>
        <input type="text" name="q" value="<?=h($q)?>"
               placeholder="nome file, variabile, messaggio"></div>
      <button class="btn btn-primary btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Cerca</button>
      <a class="btn btn-sm" href="<?=url_safe('system_errors')?>">Azzera</a>
    </form>

    <?php if (!$filtrate): ?>
      <p style="color:var(--muted);text-align:center;padding:20px;font-size:12px">
        <?= $q !== '' || $tipo !== '' ? 'Nessuna riga corrisponde ai filtri.'
                                      : 'Il registro non contiene righe.' ?></p>
    <?php else: ?>
      <div class="pm-scroll" style="max-height:50vh">
        <table class="data-table" style="width:100%;font-size:10.5px">
          <thead><tr><th style="width:80px">Tipo</th><th>Messaggio</th></tr></thead>
          <tbody>
          <?php foreach (array_slice($filtrate, 0, 300) as $r): ?>
            <tr>
              <td><span style="font-size:9px;font-weight:700;padding:1px 6px;border-radius:8px;
                    color:#fff;background:<?=$colC[$r['classe']]?>"><?=h($r['classe'])?></span></td>
              <td style="font-family:monospace;word-break:break-all;
                    color:<?=in_array($r['classe'],['fatale','sintassi'],true)?'#991b1b':'inherit'?>">
                <?=h(mb_strimwidth($r['riga'], 0, 400, '…'))?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($filtrate) > 300): ?>
        <p style="font-size:11px;color:var(--muted);margin-top:6px">
          Mostrate le prime 300 righe su <?=$n(count($filtrate))?>. Restringete con la ricerca
          per vedere le altre.</p>
      <?php endif; ?>
    <?php endif; ?>

    <p style="font-size:11px;color:var(--muted);margin-top:10px;padding-top:8px;
              border-top:1px solid #f1f5f9">
      Il file è letto <strong>dalla coda</strong>, non dall'inizio: un registro di errori cresce in
      fondo, e le righe che interessano sono le ultime. Su un file di decine di megabyte, leggerlo
      dall'inizio caricherebbe in memoria mesi di righe per mostrarne venti.
      <strong>Il registro può contenere percorsi del filesystem e frammenti di query</strong>: è il
      motivo per cui questa pagina è riservata al super amministratore.
    </p>
  </div>
<?php endif; ?>

<?php require_once('footer.php'); ?>
