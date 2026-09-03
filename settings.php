<?php
/**
 * certV 2.0 — settings.php
 * FIX BUG #13: salva prima di header.php, poi load_settings_fresh() per aggiornare CSS immediatamente
 */
require_once('access_control.php');
require_once('functions.php');   // FIX: necessario prima del POST handler (save_setting, write_log, load_settings_fresh)
$_role=(int)($_SESSION['role_id']??99);
if($_role>2){header("Location: unauthorized.php");exit();}

$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['save'])){
    $keys=['app_name','primary_color','mail_from','mail_from_name',
           'notify_days_1','notify_days_2','notify_days_3','notify_days_4',
           'agency_contract_alert_days','compliance_warning_pct','compliance_critical_pct'];
    foreach($keys as $k){
        if(!isset($_POST[$k])) continue;
        $val=trim($_POST[$k]);
        if($k==='primary_color'&&!preg_match('/^#[0-9a-fA-F]{6}$/',$val)) continue;
        if(in_array($k,['notify_days_1','notify_days_2','notify_days_3','notify_days_4',
                         'agency_contract_alert_days','compliance_warning_pct','compliance_critical_pct']))
            $val=(string)max(1,(int)$val);
        save_setting($k,$val);
    }
    write_log('Settings','success','Impostazioni aggiornate',(int)$_SESSION['user_id']);
    $settings=load_settings_fresh(); // FIX: aggiorna valori per header.php che segue
    $msg="<div class='alert alert-success'><i class='fa-solid fa-check'></i> Impostazioni salvate.</div>";
}
require_once('header.php');
$s=$settings;
?>
<div style="margin-bottom:22px">
  <h1 style="font-size:20px;font-weight:800"><i class="fa-solid fa-gear" style="color:var(--p);margin-right:10px"></i>Impostazioni applicazione</h1>
  <p style="color:var(--muted);font-size:13px">HR Director e Super Admin</p>
</div>
<?=$msg?>
<form method="POST">
            <?= csrf_field() ?>
  <input type="hidden" name="save" value="1">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px">
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-palette" style="color:var(--p)"></i> Identità visiva</span></div>
      <div class="form-group"><label>Nome applicazione</label><input type="text" name="app_name" value="<?=h($s['app_name']??'certV')?>"></div>
      <div class="form-group"><label>Colore primario</label>
        <div style="display:flex;gap:12px;align-items:center">
          <input type="color" name="primary_color" value="<?=h($s['primary_color']??'#0ea5e9')?>" style="width:56px;height:44px;padding:2px;cursor:pointer;border:1px solid var(--border);border-radius:8px" oninput="document.getElementById('hx').textContent=this.value.toUpperCase()">
          <div><div style="font-weight:600;font-size:13px" id="hx"><?=h(strtoupper($s['primary_color']??'#0EA5E9'))?></div><div style="font-size:11px;color:var(--muted)">Sidebar, pulsanti, link attivi</div></div>
        </div>
      </div>
      <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
        <div style="font-weight:700;font-size:13px;margin-bottom:12px"><i class="fa-solid fa-envelope" style="color:var(--p);margin-right:6px"></i>Email notifiche</div>
        <div class="form-group"><label>Mittente email</label><input type="email" name="mail_from" value="<?=h($s['mail_from']??'certv@example.com')?>"></div>
        <div class="form-group"><label>Mittente nome</label><input type="text" name="mail_from_name" value="<?=h($s['mail_from_name']??'certV System')?>"></div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-bell" style="color:var(--warning)"></i> Alert scadenze</span></div>
      <?php for($i=1;$i<=4;$i++):$lbl=['','1° avviso (informativo)','2° avviso (attenzione)','3° avviso (urgente)','Alert critico'][$i]; ?>
      <div class="form-group"><label><?=$lbl?></label><input type="number" name="notify_days_<?=$i?>" min="1" max="365" value="<?=h($s["notify_days_$i"]??[90,60,30,7][$i-1])?>" style="<?=$i===4?'border-color:var(--danger)':''?>"></div>
      <?php endfor; ?>
      <div class="form-group"><label>Alert contratti agenzie (giorni prima)</label><input type="number" name="agency_contract_alert_days" min="1" value="<?=h($s['agency_contract_alert_days']??60)?>"></div>
      <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border)">
        <div style="font-weight:700;font-size:13px;margin-bottom:12px"><i class="fa-solid fa-shield-check" style="color:var(--success);margin-right:6px"></i>Soglie compliance</div>
        <div class="grid-2">
          <div class="form-group"><label>Warning (%)</label><input type="number" name="compliance_warning_pct" min="1" max="100" value="<?=h($s['compliance_warning_pct']??80)?>"><div style="font-size:10px;color:var(--muted);margin-top:2px">Sotto → badge giallo</div></div>
          <div class="form-group"><label>Critica (%)</label><input type="number" name="compliance_critical_pct" min="1" max="100" value="<?=h($s['compliance_critical_pct']??60)?>"><div style="font-size:10px;color:var(--muted);margin-top:2px">Sotto → badge rosso</div></div>
        </div>
      </div>
    </div>
  </div>
  <div style="margin-top:20px;display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary" style="padding:12px 30px;font-size:15px"><i class="fa-solid fa-floppy-disk"></i> Salva impostazioni</button>
  </div>
</form>
<div class="card" style="margin-top:22px">
  <div class="card-header"><span class="card-title"><i class="fa-solid fa-circle-info" style="color:var(--p)"></i> Info sistema</span></div>
  <div class="grid-4">
    <?php foreach([['Versione','2.4'],['PHP',PHP_VERSION],['DB',DB_NAME],['Server',$_SERVER['SERVER_SOFTWARE']??'n/d']] as[$l,$v]): ?>
    <div style="background:#f8fafc;padding:14px;border-radius:9px;text-align:center"><div style="font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-bottom:4px"><?=$l?></div><div style="font-size:13px;font-weight:700;color:var(--p)"><?=h($v)?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once('footer.php'); ?>
