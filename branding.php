<?php
/**
 * certV 5.03.00 — branding.php
 * Pannello personalizzazione del portale: logo, favicon, colori, font, template, copyright.
 * Solo Super Admin.
 */
require_once('access_control.php');
require_once __DIR__ . '/app/BrandingHelper.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);

if ($u_role !== 1) {
    header('Location: unauthorized.php'); exit();
}

// ─── Funzione helper per leggere/scrivere settings ─────────────────────
function bset(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = ?")
        ->execute([$key, $value, $value]);
}
function bget(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

// ─── HANDLE POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_identity') {
            bset($pdo, 'app_name',    trim($_POST['app_name'] ?? 'certV'));
            bset($pdo, 'app_tagline', trim($_POST['app_tagline'] ?? ''));
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Identità portale aggiornata.</div>";
        }

        elseif ($action === 'upload_logo') {
            // v1.7.12: supporta upload logo + scelta favicon (auto-genera | upload separato | mantieni esistente)
            $favicon_mode = $_POST['favicon_mode'] ?? 'auto';
            $logo_uploaded = !empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name']);
            $fav_uploaded  = !empty($_FILES['favicon_file']['tmp_name']) && is_uploaded_file($_FILES['favicon_file']['tmp_name']);

            if (!$logo_uploaded && !$fav_uploaded) {
                throw new Exception('Nessun file caricato (logo o favicon).');
            }

            $messages = [];
            $logoPath = bget($pdo, 'logo_path', '');
            $favPath  = bget($pdo, 'favicon_path', '');

            // ─── Logo (se caricato) ───
            if ($logo_uploaded) {
                $result = BrandingHelper::uploadLogo($_FILES['logo'], __DIR__);
                if (empty($result['logo_path'])) {
                    $err_detail = !empty($result['errors']) ? implode(' · ', $result['errors']) : 'errore sconosciuto durante upload logo';
                    throw new Exception("Errore upload logo: $err_detail");
                }
                // Rimuovi vecchio logo (non favicon)
                if ($logoPath && file_exists(__DIR__ . '/' . $logoPath) && $logoPath !== $result['logo_path']) {
                    @unlink(__DIR__ . '/' . $logoPath);
                }
                $logoPath = $result['logo_path'];
                bset($pdo, 'logo_path', $logoPath);
                $messages[] = 'Logo caricato.';

                // Se favicon_mode='auto', usa quello generato da uploadLogo()
                if ($favicon_mode === 'auto' && !empty($result['favicon_path'])) {
                    if ($favPath && file_exists(__DIR__ . '/' . $favPath) && $favPath !== $result['favicon_path']) {
                        @unlink(__DIR__ . '/' . $favPath);
                    }
                    $favPath = $result['favicon_path'];
                    bset($pdo, 'favicon_path', $favPath);
                    $messages[] = 'Favicon generata automaticamente dal logo.';
                }
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $w) $messages[] = '<em>(avviso) ' . h($w) . '</em>';
                }
            }

            // ─── Favicon upload separato ───
            if ($favicon_mode === 'upload' && $fav_uploaded) {
                $ff = $_FILES['favicon_file'];
                $fav_mime = '';
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $fav_mime = finfo_file($finfo, $ff['tmp_name']);
                    finfo_close($finfo);
                }
                $allowed_fav = ['image/x-icon','image/vnd.microsoft.icon','image/png','image/jpeg','image/svg+xml'];
                if (!in_array($fav_mime, $allowed_fav, true)) {
                    throw new Exception('Formato favicon non supportato (' . h($fav_mime) . '). Usa .ico, .png o .svg.');
                }
                if ($ff['size'] > 1024 * 1024) {
                    throw new Exception('Favicon troppo grande (max 1 MB).');
                }
                $ext = match ($fav_mime) {
                    'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
                    'image/png'                                => 'png',
                    'image/jpeg'                               => 'jpg',
                    'image/svg+xml'                            => 'svg',
                    default                                    => 'png',
                };
                $uploadDir = __DIR__ . '/uploads/branding/';
                if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
                    throw new Exception('Impossibile creare cartella uploads/branding/');
                }
                $fav_filename = 'favicon_' . time() . '.' . $ext;
                $fav_fullpath = $uploadDir . $fav_filename;
                if (!@move_uploaded_file($ff['tmp_name'], $fav_fullpath)) {
                    throw new Exception('Impossibile salvare il file favicon.');
                }
                @chmod($fav_fullpath, 0644);

                // Rimuovo vecchia favicon (ma non se coincide con logo)
                if ($favPath && file_exists(__DIR__ . '/' . $favPath) && $favPath !== $logoPath) {
                    @unlink(__DIR__ . '/' . $favPath);
                }
                $favPath = 'uploads/branding/' . $fav_filename;
                bset($pdo, 'favicon_path', $favPath);
                $messages[] = 'Favicon caricata manualmente.';
            }

            // ─── Favicon mode 'none' (rimuovi favicon esistente, solo logo) ───
            if ($favicon_mode === 'none') {
                if ($favPath && file_exists(__DIR__ . '/' . $favPath) && $favPath !== $logoPath) {
                    @unlink(__DIR__ . '/' . $favPath);
                }
                bset($pdo, 'favicon_path', '');
                $messages[] = 'Favicon rimossa.';
            }

            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> " . implode(' ', $messages) . "</div>";
        }

        elseif ($action === 'remove_logo') {
            foreach (['logo_path', 'favicon_path'] as $k) {
                $p = bget($pdo, $k);
                if ($p && file_exists(__DIR__ . '/' . $p)) @unlink(__DIR__ . '/' . $p);
                bset($pdo, $k, '');
            }
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Logo e favicon rimossi.</div>";
        }

        elseif ($action === 'save_colors') {
            bset($pdo, 'primary_color', BrandingHelper::validateHexColor($_POST['primary_color'] ?? '', '#0ea5e9'));
            bset($pdo, 'accent_color',  BrandingHelper::validateHexColor($_POST['accent_color']  ?? '', '#5b21b6'));
            bset($pdo, 'success_color', BrandingHelper::validateHexColor($_POST['success_color'] ?? '', '#10b981'));
            bset($pdo, 'warning_color', BrandingHelper::validateHexColor($_POST['warning_color'] ?? '', '#f59e0b'));
            bset($pdo, 'danger_color',  BrandingHelper::validateHexColor($_POST['danger_color']  ?? '', '#ef4444'));
            bset($pdo, 'sidebar_bg',    BrandingHelper::validateHexColor($_POST['sidebar_bg']    ?? '', '#0f172a'));
            bset($pdo, 'sidebar_text',  BrandingHelper::validateHexColor($_POST['sidebar_text']  ?? '', '#cbd5e1'));
            bset($pdo, 'sidebar_hover', BrandingHelper::validateHexColor($_POST['sidebar_hover'] ?? '', '#1e293b'));
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Palette colori aggiornata.</div>";
        }

        elseif ($action === 'save_font') {
            $font = $_POST['font_family'] ?? 'system';
            if (!isset(BrandingHelper::FONTS[$font])) $font = 'system';
            bset($pdo, 'font_family', $font);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Font aggiornato.</div>";
        }

        elseif ($action === 'save_template') {
            $tpl = $_POST['layout_template'] ?? 'modern';
            if (!isset(BrandingHelper::TEMPLATES[$tpl])) $tpl = 'modern';
            bset($pdo, 'layout_template', $tpl);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Template aggiornato.</div>";
        }

        elseif ($action === 'save_copyright') {
            bset($pdo, 'copyright_text',      trim($_POST['copyright_text'] ?? ''));
            bset($pdo, 'release_label',       trim($_POST['release_label']  ?? ''));
            bset($pdo, 'release_show_footer', !empty($_POST['release_show_footer']) ? '1' : '0');
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Copyright e release aggiornati.</div>";
        }

        elseif ($action === 'reset_defaults') {
            $defaults = [
                'primary_color'  => '#0ea5e9', 'accent_color' => '#5b21b6',
                'success_color'  => '#10b981', 'warning_color'=> '#f59e0b', 'danger_color' => '#ef4444',
                'sidebar_bg'     => '#0f172a', 'sidebar_text' => '#cbd5e1', 'sidebar_hover'=> '#1e293b',
                'font_family'    => 'system',  'layout_template' => 'modern',
            ];
            foreach ($defaults as $k => $v) bset($pdo, $k, $v);
            $_SESSION['flash_msg'] = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Tema ripristinato ai default.</div>";
        }

        write_log('Branding', 'success', "Aggiornato $action", $u_id);
    } catch (Throwable $e) {
        $_SESSION['flash_msg'] = "<div class='alert alert-danger'>" . h($e->getMessage()) . "</div>";
    }
    header('Location: ' . $_SERVER['PHP_SELF']
           . (!empty($_GET['r']) ? '?r=' . urlencode($_GET['r']) : ''));
    exit();
}

// ─── CARICA VALORI CORRENTI ────────────────────────────────────────────
$cur = [
    'app_name'           => bget($pdo, 'app_name', 'certV'),
    'app_tagline'        => bget($pdo, 'app_tagline', ''),
    'logo_path'          => bget($pdo, 'logo_path', ''),
    'favicon_path'       => bget($pdo, 'favicon_path', ''),
    'primary_color'      => bget($pdo, 'primary_color', '#0ea5e9'),
    'accent_color'       => bget($pdo, 'accent_color',  '#5b21b6'),
    'success_color'      => bget($pdo, 'success_color', '#10b981'),
    'warning_color'      => bget($pdo, 'warning_color', '#f59e0b'),
    'danger_color'       => bget($pdo, 'danger_color',  '#ef4444'),
    'sidebar_bg'         => bget($pdo, 'sidebar_bg',    '#0f172a'),
    'sidebar_text'       => bget($pdo, 'sidebar_text',  '#cbd5e1'),
    'sidebar_hover'      => bget($pdo, 'sidebar_hover', '#1e293b'),
    'font_family'        => bget($pdo, 'font_family',   'system'),
    'layout_template'    => bget($pdo, 'layout_template','modern'),
    'copyright_text'     => bget($pdo, 'copyright_text',''),
    'release_label'      => bget($pdo, 'release_label', ''),
    'release_show_footer'=> bget($pdo, 'release_show_footer', '1'),
];

require_once('header.php');
?>

<style>
.brand-grid{display:grid;grid-template-columns:1fr;gap:18px;max-width:1100px;margin:0 auto}
.brand-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);overflow:hidden}
.brand-card-h{padding:14px 18px;background:#f8fafc;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.brand-card-h h3{margin:0;font-size:14px;font-weight:800}
.brand-card-h .ic{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff}
.brand-card-b{padding:18px}
.color-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.color-pick{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid var(--border);border-radius:8px;background:#fafbfc}
.color-pick label{font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;flex:1;margin:0}
.color-pick input[type=color]{width:40px;height:40px;border:none;border-radius:6px;cursor:pointer;padding:0;background:none}
.color-pick input[type=text]{width:80px;font-family:monospace;font-size:11px;padding:6px;border:1px solid var(--border);border-radius:5px}
.logo-preview{background:repeating-conic-gradient(#f1f5f9 0% 25%, #fff 0% 50%) 50% / 16px 16px;border:2px dashed var(--border);border-radius:10px;padding:24px;text-align:center;min-height:140px;display:flex;align-items:center;justify-content:center}
.logo-preview img{max-height:120px;max-width:100%;object-fit:contain}
.font-radio,.tpl-radio{display:flex;flex-wrap:wrap;gap:10px}
.font-radio label,.tpl-radio label{flex:1;min-width:160px;padding:14px;border:2px solid var(--border);border-radius:10px;cursor:pointer;background:#fafbfc;transition:all .15s}
.font-radio label:hover,.tpl-radio label:hover{border-color:var(--p);background:#fff}
.font-radio input,.tpl-radio input{display:none}
.font-radio label.sel,.tpl-radio label.sel{border-color:var(--p);background:#eff6ff;box-shadow:0 0 0 3px rgba(14,165,233,.1)}
.font-radio .preview{font-size:18px;font-weight:700;margin-bottom:4px}
.font-radio .lbl{font-size:11px;color:var(--muted)}
.tpl-radio .lbl{font-weight:700;font-size:13px}
.tpl-radio .desc{font-size:11px;color:var(--muted);margin-top:4px}
</style>

<div style="max-width:1100px;margin:0 auto">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
        <i class="fa-solid fa-palette" style="color:var(--p)"></i> Personalizzazione portale
      </h1>
      <div style="color:var(--muted);font-size:13px">Identità, colori, font, template, copyright e release</div>
    </div>
    <form method="POST" onsubmit="return confirm('Ripristinare i valori di default per colori, font e template? Logo e copyright restano invariati.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_defaults">
      <button type="submit" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border-color:#fde68a">
        <i class="fa-solid fa-rotate-left"></i> Ripristina default
      </button>
    </form>
  </div>

  <?php if (!empty($_SESSION['flash_msg'])): ?>
    <?= $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); ?>
  <?php endif; ?>

  <div class="brand-grid">

    <!-- ═══ IDENTITÀ ═══════════════════════════════════════════ -->
    <div class="brand-card">
      <div class="brand-card-h">
        <span class="ic" style="background:#0ea5e9"><i class="fa-solid fa-id-badge"></i></span>
        <h3>Identità portale</h3>
      </div>
      <div class="brand-card-b">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_identity">
          <div class="form-group">
            <label>Nome del portale *</label>
            <input type="text" name="app_name" value="<?=h($cur['app_name'])?>" maxlength="50" required>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Mostrato nella sidebar e nel tag &lt;title&gt; del browser</div>
          </div>
          <div class="form-group">
            <label>Tagline / Sottotitolo</label>
            <input type="text" name="app_tagline" value="<?=h($cur['app_tagline'])?>" maxlength="200">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Frase descrittiva del portale (visibile in alcune pagine pubbliche)</div>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
        </form>
      </div>
    </div>

    <!-- ═══ LOGO & FAVICON ═════════════════════════════════════ -->
    <div class="brand-card">
      <div class="brand-card-h">
        <span class="ic" style="background:#8b5cf6"><i class="fa-solid fa-image"></i></span>
        <h3>Logo e favicon</h3>
      </div>
      <div class="brand-card-b">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
          <div>
            <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:8px">Logo attuale</div>
            <div class="logo-preview">
              <?php if ($cur['logo_path'] && file_exists(__DIR__ . '/' . $cur['logo_path'])): ?>
                <img src="<?=h($cur['logo_path'])?>?v=<?=time()?>" alt="Logo">
              <?php else: ?>
                <div style="color:var(--muted);font-size:12px"><i class="fa-solid fa-image" style="font-size:32px;display:block;margin-bottom:8px;opacity:.4"></i>Nessun logo caricato</div>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:8px">Favicon (auto)</div>
            <div class="logo-preview" style="min-height:80px">
              <?php if ($cur['favicon_path'] && file_exists(__DIR__ . '/' . $cur['favicon_path'])): ?>
                <img src="<?=h($cur['favicon_path'])?>?v=<?=time()?>" alt="Favicon" style="max-height:48px;max-width:48px">
              <?php else: ?>
                <div style="color:var(--muted);font-size:11px">Caricando un logo viene generata automaticamente</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <hr style="margin:18px 0;border:none;border-top:1px solid var(--border)">

        <form method="POST" enctype="multipart/form-data" style="margin-bottom:10px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_logo">

          <div class="form-group">
            <label>Carica nuovo logo</label>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">PNG, JPG, WEBP, SVG · Max 2 MB</div>
          </div>

          <!-- v1.7.12: scelta favicon -->
          <div class="form-group" style="background:#f8fafc;padding:10px 12px;border-radius:6px;border:1px solid var(--border)">
            <label style="font-weight:700;margin-bottom:8px;display:block">
              <i class="fa-solid fa-image" style="color:#7c3aed"></i> Favicon
            </label>

            <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer">
              <input type="radio" name="favicon_mode" value="auto" checked onchange="toggleFavInput()">
              <span style="font-size:12px"><strong>Genera automaticamente</strong> dal logo (richiede GD)</span>
            </label>

            <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer">
              <input type="radio" name="favicon_mode" value="upload" onchange="toggleFavInput()">
              <span style="font-size:12px"><strong>Carica file separato</strong> (più controllo qualità)</span>
            </label>

            <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer">
              <input type="radio" name="favicon_mode" value="none" onchange="toggleFavInput()">
              <span style="font-size:12px"><strong>Nessuna favicon</strong></span>
            </label>

            <div id="favFileInput" style="display:none;margin-top:8px;padding-top:8px;border-top:1px dashed var(--border)">
              <input type="file" name="favicon_file" accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml"
                     style="font-size:12px">
              <div style="font-size:10px;color:var(--muted);margin-top:4px">.ico, .png o .svg · Max 1 MB · Dimensione consigliata 32×32 o 64×64</div>
            </div>
          </div>

          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-cloud-arrow-up"></i> Salva logo e favicon</button>
        </form>

        <script>
        function toggleFavInput() {
          const mode = document.querySelector('input[name="favicon_mode"]:checked').value;
          document.getElementById('favFileInput').style.display = (mode === 'upload') ? 'block' : 'none';
        }
        </script>

        <?php if ($cur['logo_path']): ?>
          <form method="POST" onsubmit="return confirm('Rimuovere logo e favicon attuali?')" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_logo">
            <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i> Rimuovi logo e favicon</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══ PALETTE COLORI ═════════════════════════════════════ -->
    <div class="brand-card">
      <div class="brand-card-h">
        <span class="ic" style="background:#10b981"><i class="fa-solid fa-fill-drip"></i></span>
        <h3>Palette colori</h3>
      </div>
      <div class="brand-card-b">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_colors">

          <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:10px">🎨 Colori principali</div>
          <div class="color-row" style="margin-bottom:18px">
            <?php foreach ([
              'primary_color' => 'Primario',
              'accent_color'  => 'Accent',
              'success_color' => 'Successo',
              'warning_color' => 'Warning',
              'danger_color'  => 'Danger',
            ] as $key => $label): ?>
              <div class="color-pick">
                <label><?=$label?></label>
                <input type="color" value="<?=h($cur[$key])?>" oninput="document.getElementById('hex_<?=$key?>').value=this.value">
                <input type="text" id="hex_<?=$key?>" name="<?=$key?>" value="<?=h($cur[$key])?>" maxlength="7" pattern="^#[0-9a-fA-F]{6}$">
              </div>
            <?php endforeach; ?>
          </div>

          <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:10px">🌑 Sidebar (menu laterale)</div>
          <div class="color-row" style="margin-bottom:18px">
            <?php foreach ([
              'sidebar_bg'    => 'Sfondo sidebar',
              'sidebar_text'  => 'Testo sidebar',
              'sidebar_hover' => 'Hover sidebar',
            ] as $key => $label): ?>
              <div class="color-pick">
                <label><?=$label?></label>
                <input type="color" value="<?=h($cur[$key])?>" oninput="document.getElementById('hex_<?=$key?>').value=this.value">
                <input type="text" id="hex_<?=$key?>" name="<?=$key?>" value="<?=h($cur[$key])?>" maxlength="7" pattern="^#[0-9a-fA-F]{6}$">
              </div>
            <?php endforeach; ?>
          </div>

          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva colori</button>
        </form>
      </div>
    </div>

    <!-- ═══ FONT ═══════════════════════════════════════════════ -->
    <div class="brand-card">
      <div class="brand-card-h">
        <span class="ic" style="background:#f59e0b"><i class="fa-solid fa-font"></i></span>
        <h3>Tipografia</h3>
      </div>
      <div class="brand-card-b">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_font">
          <div class="font-radio">
            <?php foreach (BrandingHelper::FONTS as $key => $info):
              $sel = $cur['font_family'] === $key;
            ?>
              <label class="<?=$sel?'sel':''?>" onclick="document.querySelectorAll('.font-radio label').forEach(l=>l.classList.remove('sel'));this.classList.add('sel')">
                <input type="radio" name="font_family" value="<?=h($key)?>" <?=$sel?'checked':''?>>
                <div class="preview" style="font-family:<?=h($info['css'])?>">Aa <?=h($info['label'])?></div>
                <div class="lbl"><?=h($info['label'])?></div>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:14px"><i class="fa-solid fa-floppy-disk"></i> Salva font</button>
        </form>
      </div>
    </div>

    <!-- ═══ TEMPLATE LAYOUT ════════════════════════════════════ -->
    <div class="brand-card">
      <div class="brand-card-h">
        <span class="ic" style="background:#5b21b6"><i class="fa-solid fa-table-cells-large"></i></span>
        <h3>Template layout</h3>
      </div>
      <div class="brand-card-b">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_template">
          <div class="tpl-radio">
            <?php foreach (BrandingHelper::TEMPLATES as $key => $info):
              $sel = $cur['layout_template'] === $key;
            ?>
              <label class="<?=$sel?'sel':''?>" onclick="document.querySelectorAll('.tpl-radio label').forEach(l=>l.classList.remove('sel'));this.classList.add('sel')">
                <input type="radio" name="layout_template" value="<?=h($key)?>" <?=$sel?'checked':''?>>
                <div class="lbl"><?=h($info['label'])?></div>
                <div class="desc"><?=h($info['desc'])?></div>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:14px"><i class="fa-solid fa-floppy-disk"></i> Salva template</button>
        </form>
      </div>
    </div>

    <!-- ═══ COPYRIGHT & RELEASE ════════════════════════════════ -->
    <div class="brand-card">
      <div class="brand-card-h">
        <span class="ic" style="background:#64748b"><i class="fa-solid fa-copyright"></i></span>
        <h3>Copyright e release</h3>
      </div>
      <div class="brand-card-b">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_copyright">
          <div class="form-group">
            <label>Testo copyright</label>
            <input type="text" name="copyright_text" value="<?=h($cur['copyright_text'])?>" maxlength="200" placeholder="© 2026 La Mia Azienda · Tutti i diritti riservati">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Mostrato nel footer di tutte le pagine</div>
          </div>
          <div class="form-group">
            <label>Etichetta release / versione</label>
            <input type="text" name="release_label" value="<?=h($cur['release_label'])?>" maxlength="50" placeholder="v5.03.00">
            <div style="font-size:11px;color:var(--muted);margin-top:4px">Versione corrente del software, mostrata accanto al copyright</div>
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px">
            <label style="margin:0;display:flex;align-items:center;gap:6px;cursor:pointer">
              <input type="checkbox" name="release_show_footer" value="1" <?=$cur['release_show_footer']==='1'?'checked':''?>>
              <span>Mostra release nel footer</span>
            </label>
          </div>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Salva</button>
        </form>
      </div>
    </div>

  </div>

  <div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:8px;margin-top:24px;font-size:12px;color:#92400e">
    <i class="fa-solid fa-circle-info"></i> <strong>Nota:</strong> Le modifiche sono visibili immediatamente per tutti gli utenti. Per applicarle alla tua sessione corrente, ricarica la pagina (F5 o Ctrl+F5 per forzare lo scarico della cache).
  </div>
</div>

<script>
// Sync color picker e text input bidirezionale
document.querySelectorAll('input[type=color]').forEach(cp => {
  cp.addEventListener('input', e => {
    const text = cp.parentElement.querySelector('input[type=text]');
    if (text) text.value = e.target.value;
  });
});
document.querySelectorAll('input[type=text][pattern="^#[0-9a-fA-F]{6}$"]').forEach(tx => {
  tx.addEventListener('input', e => {
    const cp = tx.parentElement.querySelector('input[type=color]');
    if (cp && /^#[0-9a-fA-F]{6}$/.test(e.target.value)) cp.value = e.target.value;
  });
});
</script>

<?php require_once('footer.php'); ?>
