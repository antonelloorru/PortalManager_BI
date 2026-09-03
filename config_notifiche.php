<?php
/**
 * certV 2.0 — config_notifiche.php
 *
 * ORDINE CRITICO DEGLI INCLUDE:
 * 1. access_control.php → carica Config.php, avvia sessione,
 *    definisce check_ui_permission() usata da header.php
 * 2. header.php → può usare check_ui_permission() perché è già definita
 *
 * Se si inverte l'ordine → Fatal Error: undefined function check_ui_permission()
 */
require_once('access_control.php');
require_once('functions.php');

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && can('edit')) {
    $keys = [
        'notify_days_1','notify_days_2','notify_days_3','notify_days_4',
        'notify_msg_1', 'notify_msg_2', 'notify_msg_3', 'notify_msg_4',
        'notify_cc_1',  'notify_cc_2',  'notify_cc_3',  'notify_cc_4',
        'agency_contract_alert_days',
        'compliance_warning_pct','compliance_critical_pct',
        'notify_logistics_email','notify_logistics_cc',
        'notify_exam_days_1','notify_exam_days_2','notify_renewal_days',
    ];
    foreach ($keys as $k) {
        if (!isset($_POST[$k])) continue;
        $val = trim($_POST[$k]);
        if (in_array($k, ['notify_days_1','notify_days_2','notify_days_3','notify_days_4',
                           'agency_contract_alert_days','compliance_warning_pct','compliance_critical_pct',
                           'notify_exam_days_1','notify_exam_days_2','notify_renewal_days'])) {
            $val = (string) max(0, (int)$val);
        }
        save_setting($k, $val);
    }
    $settings = load_settings_fresh();
    write_log('Config', 'success', 'Configurazione notifiche aggiornata', $u_id);
    $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Configurazione salvata.</div>";
    redirect('config_notifiche');
}

require_once('header.php');
$msg = '';
if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }

if (!can('edit')) {
    echo "<div class='alert alert-danger'>Accesso non autorizzato.</div>";
    require_once('footer.php'); exit();
}

$s = $settings;

$levels = [
    1 => ['label'=>'1° Avviso — Anticipato', 'color'=>'#3b82f6', 'dd'=>90],
    2 => ['label'=>'2° Avviso — Attenzione', 'color'=>'#f59e0b', 'dd'=>60],
    3 => ['label'=>'3° Avviso — Urgente',    'color'=>'#ef4444', 'dd'=>30],
    4 => ['label'=>'4° Avviso — Critico',    'color'=>'#7c3aed', 'dd'=>7],
];
$placeholder_msg = [
    1 => "Gentile {DIPENDENTE},\nla certificazione {CERTIFICAZIONE} ({BRAND}) scadrà il {DATA_SCADENZA}.\nÈ il momento di pianificare il rinnovo.",
    2 => "Gentile {DIPENDENTE},\n{CERTIFICAZIONE} ({BRAND}) scade il {DATA_SCADENZA} — mancano 60 giorni.\nPrenotare l'esame di rinnovo.",
    3 => "URGENTE: {CERTIFICAZIONE} ({BRAND}) di {DIPENDENTE} scade il {DATA_SCADENZA}.\nAzione immediata richiesta.",
    4 => "⚠ CRITICO: {DIPENDENTE} — {CERTIFICAZIONE} ({BRAND}) scade il {DATA_SCADENZA}. 7 giorni rimasti.",
];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px">
  <div>
    <h1 style="font-size:20px;font-weight:800;margin-bottom:3px">
      <i class="fa-solid fa-bell-slash" style="color:var(--p);margin-right:10px"></i>Configurazione notifiche
    </h1>
    <p style="color:var(--muted);font-size:13px">Alert scadenze certificazioni, testi email e soglie compliance</p>
  </div>
</div>

<?=$msg?>

<form method="POST">
            <?= csrf_field() ?>

  <!-- 4 livelli alert -->
  <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px">
    Livelli di alert scadenza certificazioni
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px">
    <?php foreach ($levels as $i => $lv): ?>
    <div class="card" style="border-left:4px solid <?=$lv['color']?>">
      <div class="card-header" style="padding-bottom:12px;margin-bottom:14px">
        <span class="card-title" style="color:<?=$lv['color']?>;font-size:13px"><?=$lv['label']?></span>
        <span style="background:<?=$lv['color']?>22;color:<?=$lv['color']?>;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:800">
          Livello <?=$i?>
        </span>
      </div>
      <div class="form-group">
        <label>Giorni prima della scadenza</label>
        <input type="number" name="notify_days_<?=$i?>" min="1" max="365"
               value="<?=h($s["notify_days_$i"] ?? $lv['dd'])?>"
               style="border-color:<?=$lv['color']?>55">
      </div>
      <div class="form-group">
        <label>CC email aggiuntivo (opzionale)</label>
        <input type="email" name="notify_cc_<?=$i?>"
               value="<?=h($s["notify_cc_$i"] ?? '')?>"
               placeholder="manager@azienda.it">
      </div>
      <div class="form-group">
        <label>Testo messaggio email</label>
        <textarea name="notify_msg_<?=$i?>" rows="4"
                  placeholder="<?=h($placeholder_msg[$i])?>"
                  style="font-size:12px;resize:vertical"><?=h($s["notify_msg_$i"] ?? '')?></textarea>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Placeholder info -->
  <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:14px 18px;margin-bottom:20px;font-size:12px;color:#92400e">
    <div style="font-weight:700;margin-bottom:8px"><i class="fa-solid fa-circle-info"></i> Variabili disponibili nel testo email</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
      <div><code style="background:#fef9c3;padding:2px 6px;border-radius:4px">{DIPENDENTE}</code> Nome e cognome del collaboratore</div>
      <div><code style="background:#fef9c3;padding:2px 6px;border-radius:4px">{CERTIFICAZIONE}</code> Titolo della certificazione</div>
      <div><code style="background:#fef9c3;padding:2px 6px;border-radius:4px">{BRAND}</code> Nome del vendor</div>
      <div><code style="background:#fef9c3;padding:2px 6px;border-radius:4px">{DATA_SCADENZA}</code> Data scadenza (gg/mm/aaaa)</div>
    </div>
  </div>

  <!-- Contratti agenzie + Compliance -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px">
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-file-signature" style="color:var(--p)"></i> Alert contratti agenzie</span></div>
      <div class="form-group">
        <label>Giorni di preavviso</label>
        <input type="number" name="agency_contract_alert_days" min="1"
               value="<?=h($s['agency_contract_alert_days'] ?? 60)?>">
        <div style="font-size:11px;color:var(--muted);margin-top:4px">
          L'HR Director riceve notifica quando un contratto attivo scade entro questi giorni.
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-shield-check" style="color:var(--success)"></i> Soglie compliance brand</span></div>
      <div class="form-group">
        <label>Soglia gialla (%)</label>
        <input type="number" name="compliance_warning_pct" min="1" max="100"
               value="<?=h($s['compliance_warning_pct'] ?? 80)?>">
        <div style="font-size:11px;color:var(--muted);margin-top:3px">Sotto → badge giallo in gap analysis</div>
      </div>
      <div class="form-group">
        <label>Soglia rossa (%)</label>
        <input type="number" name="compliance_critical_pct" min="1" max="100"
               value="<?=h($s['compliance_critical_pct'] ?? 60)?>">
        <div style="font-size:11px;color:var(--muted);margin-top:3px">Sotto → badge rosso, rischio declassamento</div>
      </div>
    </div>
  </div>

  <!-- Segreteria & Logistica -->
  <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px">
    <i class="fa-solid fa-concierge-bell" style="color:#0369a1"></i> Alert Segreteria &amp; Logistica
  </div>
  <div class="card" style="border-left:4px solid #0369a1;margin-bottom:22px">
    <div class="card-header"><span class="card-title" style="color:#0369a1">Destinatari notifiche richieste logistiche</span></div>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:14px;font-size:12px;color:#1e40af">
      <i class="fa-solid fa-circle-info"></i>
      Quando un dipendente invia una nuova richiesta da <strong>Segreteria &amp; Logistica</strong>, il sistema invia un'email con il riepilogo completo all'indirizzo qui configurato.
      Quando lo stato viene aggiornato (approvata, prenotata, ecc.), il richiedente riceve una notifica automatica.
    </div>
    <div class="form-group">
      <label>Email destinatario principale *</label>
      <input type="email" name="notify_logistics_email"
             value="<?=h($s['notify_logistics_email'] ?? '')?>"
             placeholder="segreteria@azienda.it">
      <div style="font-size:10px;color:var(--muted);margin-top:4px">
        Se vuoto, le notifiche vengono inviate a tutti gli utenti con ruolo Admin/HR/Brand Manager.
      </div>
    </div>
    <div class="form-group">
      <label>CC aggiuntivo (opzionale)</label>
      <input type="email" name="notify_logistics_cc"
             value="<?=h($s['notify_logistics_cc'] ?? '')?>"
             placeholder="responsabile@azienda.it">
      <div style="font-size:10px;color:var(--muted);margin-top:4px">
        Copia conoscenza: riceve tutte le notifiche in parallelo al destinatario principale.
      </div>
    </div>
  </div>

  <!-- Esami pianificati -->
  <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px">
    <i class="fa-solid fa-calendar-check" style="color:#7c3aed"></i> Promemoria esami pianificati
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:22px">
    <div class="card" style="border-left:4px solid #f59e0b">
      <div class="card-header"><span class="card-title" style="color:#f59e0b;font-size:12px">1° Promemoria</span></div>
      <div class="form-group">
        <label>Giorni prima dell'evento</label>
        <input type="number" name="notify_exam_days_1" min="0" max="90"
               value="<?=h($s['notify_exam_days_1'] ?? 7)?>">
        <div style="font-size:10px;color:var(--muted);margin-top:3px">0 = disabilitato</div>
      </div>
    </div>
    <div class="card" style="border-left:4px solid #dc2626">
      <div class="card-header"><span class="card-title" style="color:#dc2626;font-size:12px">2° Promemoria</span></div>
      <div class="form-group">
        <label>Giorni prima dell'evento</label>
        <input type="number" name="notify_exam_days_2" min="0" max="90"
               value="<?=h($s['notify_exam_days_2'] ?? 1)?>">
        <div style="font-size:10px;color:var(--muted);margin-top:3px">0 = disabilitato</div>
      </div>
    </div>
    <div class="card" style="border-left:4px solid #059669">
      <div class="card-header"><span class="card-title" style="color:#059669;font-size:12px">Finestra rinnovo</span></div>
      <div class="form-group">
        <label>Giorni dopo scadenza</label>
        <input type="number" name="notify_renewal_days" min="0" max="365"
               value="<?=h($s['notify_renewal_days'] ?? 30)?>">
        <div style="font-size:10px;color:var(--muted);margin-top:3px">Alert rinnovo post-scadenza</div>
      </div>
    </div>
  </div>

  <!-- Info cron -->
  <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:16px 18px;margin-bottom:22px;font-size:12px;color:#1e40af">
    <div style="font-weight:700;margin-bottom:10px"><i class="fa-solid fa-clock"></i> Configurazione cron job</div>
    <div style="margin-bottom:6px"><strong>Linux / macOS</strong></div>
    <code style="display:block;background:#1e3a5f;color:#e2e8f0;padding:10px 14px;border-radius:7px;font-size:11px;margin-bottom:12px">0 7 * * * /usr/bin/php /var/www/html/certV/cron/cron_notifications.php >> /var/log/certv_cron.log 2>&1</code>
    <div style="margin-bottom:6px"><strong>Windows XAMPP — Task Scheduler</strong></div>
    <code style="display:block;background:#1e3a5f;color:#e2e8f0;padding:10px 14px;border-radius:7px;font-size:11px">
      Programma:  C:\xampp\php\php.exe<br>
      Argomenti:  C:\xampp\htdocs\certV\cron\cron_notifications.php
    </code>
    <div style="margin-top:12px;padding-top:10px;border-top:1px solid #bfdbfe">
      <strong>Test manuale:</strong>
      <code style="display:inline;background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:4px;font-size:11px">
        php cron/cron_notifications.php
      </code>
      &nbsp;·&nbsp; Poi verifica in
      <a href="view_logs.php" style="color:#1e40af;font-weight:700">Log di sistema</a> → categoria Cron
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary" style="padding:12px 30px;font-size:15px">
      <i class="fa-solid fa-floppy-disk"></i> Salva configurazione
    </button>
  </div>
</form>

<?php require_once('footer.php'); ?>
