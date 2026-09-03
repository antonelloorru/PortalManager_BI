<?php
/**
 * PortalManager 1.7.9 — menu_customizer.php
 *
 * Pagina di personalizzazione del menu utente o di ruolo (super admin):
 *  - Drag&drop riordino voci entro sezioni e sezioni stesse
 *  - Toggle visibilità (occhio/occhio-barrato) per ogni voce/sezione
 *  - Salva preferenze per utente corrente o per un ruolo (admin only)
 *  - Reset al default
 *
 * Drag&drop implementato con HTML5 nativo (no librerie esterne).
 */

require_once('access_control.php');
require_once __DIR__ . '/app/MenuManager.php';

$u_id   = (int)$_SESSION['user_id'];
$u_role = (int)($_SESSION['role_id'] ?? 99);
$is_admin = ($u_role === 1);

$mgr = new MenuManager($pdo);
$msg = '';

// ── Determino scope: 'user' di default, 'role' se admin con parametro ──
$scope_type = $_GET['scope_type'] ?? 'user';
$scope_id   = (int)($_GET['scope_id'] ?? $u_id);

if ($scope_type === 'role' && !$is_admin) {
    $scope_type = 'user';
    $scope_id   = $u_id;
}
if ($scope_type === 'user' && $scope_id !== $u_id && !$is_admin) {
    $scope_id = $u_id;
}

// ── POST: salva configurazione ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_config') {
            $cfg_json = $_POST['menu_config'] ?? '[]';
            $cfg = json_decode($cfg_json, true);
            if (!is_array($cfg)) throw new RuntimeException('Configurazione non valida.');
            $ok = $mgr->savePreference($scope_type, $scope_id, $cfg);
            if (!$ok) throw new RuntimeException('Salvataggio fallito.');
            if (function_exists('write_log')) {
                write_log('MenuCustomizer', 'success',
                    "Config salvata: scope=$scope_type id=$scope_id", $u_id);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Configurazione salvata. Ricarica la pagina per vedere il nuovo menu.</div>";
        }
        elseif ($action === 'reset_to_default') {
            $mgr->deletePreference($scope_type, $scope_id);
            if (function_exists('write_log')) {
                write_log('MenuCustomizer', 'success',
                    "Config eliminata (reset al default): scope=$scope_type id=$scope_id", $u_id);
            }
            $msg = "<div class='alert alert-success'><i class='fa-solid fa-check'></i> Configurazione rimossa. Il menu tornerà al default.</div>";
        }
    } catch (Throwable $e) {
        $msg = "<div class='alert alert-danger'><i class='fa-solid fa-triangle-exclamation'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ── Carica configurazione corrente (o default) ──
$current_config = $mgr->loadPreference($scope_type, $scope_id);
if ($current_config === null) {
    // Niente preferenza salvata: prendo il default e lo mostro
    $current_config = MenuManager::defaultMenu();
    // Devo passare attraverso loadMenuFor per merge+filter? No, qui voglio mostrare TUTTE
    // le voci disponibili per il ruolo (anche quelle nascoste), quindi uso direttamente il default
    // filtrato per permessi
}

// ── Voglio mostrare TUTTE le voci che l'utente potrebbe vedere (anche se attualmente nascoste) ──
// Quindi filtro per permessi RBAC ma NON per il flag visible (che è quello che modifichiamo).
$role_for_filter = ($scope_type === 'role') ? $scope_id : $u_role;
$default_full = MenuManager::defaultMenu();

// Costruisco la lista finale: prendo il current_config (che include flag visible) e
// se manca qualcosa (perché è una config "vecchia") aggiungo dal default
$display_config = [];
$cfg_by_key = [];
foreach ($current_config as $sec) {
    $cfg_by_key[$sec['key']] = $sec;
}
foreach ($default_full as $base_sec) {
    if (isset($cfg_by_key[$base_sec['key']])) {
        $sec_data = $cfg_by_key[$base_sec['key']];
        // assicuro che ogni item abbia flag visible
        $items_by_page = [];
        foreach ($sec_data['items'] ?? [] as $it) $items_by_page[$it['page']] = $it;
        $final_items = [];
        // Mantengo l'ordine della config
        foreach ($sec_data['items'] ?? [] as $it) {
            // Cerco la voce nel default per recuperare always_visible / label aggiornato
            $base_it = null;
            foreach ($base_sec['items'] as $bi) if ($bi['page'] === $it['page']) { $base_it = $bi; break; }
            if (!$base_it) continue;
            // Filtra per permessi RBAC
            if (!user_role_can_see($pdo, $base_it['page'], $role_for_filter, $u_role)) {
                if (empty($base_it['always_visible'])) continue;
            }
            $final_items[] = [
                'page'    => $base_it['page'],
                'label'   => $base_it['label'],
                'icon'    => $base_it['icon'],
                'visible' => !empty($base_it['always_visible']) || (isset($it['visible']) ? (bool)$it['visible'] : true),
                'always_visible' => !empty($base_it['always_visible']),
            ];
        }
        // Aggiungi voci del default mancanti nella config (nuove voci dopo aggiornamento)
        foreach ($base_sec['items'] as $base_it) {
            if (!isset($items_by_page[$base_it['page']])) {
                if (!user_role_can_see($pdo, $base_it['page'], $role_for_filter, $u_role)) {
                    if (empty($base_it['always_visible'])) continue;
                }
                $final_items[] = [
                    'page'    => $base_it['page'],
                    'label'   => $base_it['label'],
                    'icon'    => $base_it['icon'],
                    'visible' => true,
                    'always_visible' => !empty($base_it['always_visible']),
                ];
            }
        }
        $display_config[] = [
            'key'     => $base_sec['key'],
            'label'   => $base_sec['label'],
            'icon'    => $base_sec['icon'],
            'visible' => isset($sec_data['visible']) ? (bool)$sec_data['visible'] : true,
            'items'   => $final_items,
        ];
    } else {
        // Sezione nuova: aggiungo con tutti gli items (filtrati per permessi)
        $items = [];
        foreach ($base_sec['items'] as $base_it) {
            if (!user_role_can_see($pdo, $base_it['page'], $role_for_filter, $u_role)) {
                if (empty($base_it['always_visible'])) continue;
            }
            $items[] = [
                'page'    => $base_it['page'],
                'label'   => $base_it['label'],
                'icon'    => $base_it['icon'],
                'visible' => true,
                'always_visible' => !empty($base_it['always_visible']),
            ];
        }
        if (!empty($items)) {
            $display_config[] = [
                'key'     => $base_sec['key'],
                'label'   => $base_sec['label'],
                'icon'    => $base_sec['icon'],
                'visible' => true,
                'items'   => $items,
            ];
        }
    }
}

function user_role_can_see(PDO $pdo, string $page, int $check_role, int $session_role): bool {
    // Super admin può sempre vedere tutto durante customizzazione
    if ($session_role === 1) return true;
    try {
        $s = $pdo->prepare("SELECT can_view FROM role_permissions WHERE role_id=? AND page_name=? LIMIT 1");
        $s->execute([$check_role, $page . '.php']);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

require_once('header.php');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Lista ruoli per dropdown admin ──
$roles_list = [];
if ($is_admin) {
    $roles_list = [
        1 => 'Super Admin', 2 => 'HR Director', 3 => 'Brand Manager',
        4 => 'Team Leader', 5 => 'Recruiter',   6 => 'Dipendente',
    ];
}

$has_saved_pref = ($mgr->loadPreference($scope_type, $scope_id) !== null);
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0">
      <i class="fa-solid fa-bars-staggered" style="color:#7c3aed"></i> Personalizza menu
    </h1>
    <div style="font-size:12px;color:var(--muted);margin-top:4px">
      Trascina le voci per riordinarle. Clicca sull'icona occhio per nasconderle. Le voci "fondamentali" non possono essere nascoste.
    </div>
  </div>
</div>

<?= $msg ?>

<!-- ═══ SCOPE SELECTOR ═══ -->
<div class="card" style="margin-bottom:14px">
  <div class="card-header">
    <span class="card-title"><i class="fa-solid fa-user-cog" style="color:#0ea5e9"></i> Cosa stai personalizzando</span>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <a href="<?= qs_self_safe(['scope_type'=>'user', 'scope_id'=>$u_id]) ?>"
       class="btn btn-sm <?= $scope_type === 'user' ? 'btn-primary' : '' ?>"
       style="<?= $scope_type === 'user' ? 'background:#7c3aed;color:#fff;border:0' : '' ?>">
      <i class="fa-solid fa-user"></i> Il mio menu personale
    </a>
    <?php if ($is_admin): ?>
      <span style="color:var(--muted);font-size:11px">Admin: default per ruolo →</span>
      <?php foreach ($roles_list as $rid => $rlabel): ?>
        <a href="<?= qs_self_safe(['scope_type'=>'role', 'scope_id'=>$rid]) ?>"
           class="btn btn-sm <?= ($scope_type === 'role' && $scope_id === $rid) ? 'btn-primary' : '' ?>"
           style="<?= ($scope_type === 'role' && $scope_id === $rid) ? 'background:#dc2626;color:#fff;border:0' : '' ?>">
          <?= $h($rlabel) ?>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div style="margin-top:12px;padding:10px 14px;background:#f8fafc;border-radius:6px;font-size:12px;color:#475569">
    <?php if ($scope_type === 'user'): ?>
      <i class="fa-solid fa-info-circle" style="color:#0ea5e9"></i>
      Stai modificando il <strong>tuo menu personale</strong>. Le modifiche valgono solo per te e sovrascrivono il default del tuo ruolo.
    <?php else: ?>
      <i class="fa-solid fa-shield-halved" style="color:#dc2626"></i>
      Stai modificando il <strong>menu di default per il ruolo "<?= $h($roles_list[$scope_id] ?? '?') ?>"</strong>.
      Si applica a tutti gli utenti di quel ruolo che non hanno una personalizzazione personale.
    <?php endif; ?>
    <?php if ($has_saved_pref): ?>
      <br><i class="fa-solid fa-circle-check" style="color:#16a34a"></i> Configurazione salvata presente.
    <?php else: ?>
      <br><i class="fa-solid fa-circle" style="color:var(--muted)"></i> Nessuna configurazione salvata: il menu segue il default.
    <?php endif; ?>
  </div>
</div>

<!-- ═══ EDITOR ═══ -->
<form method="POST" id="menuForm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_config">
  <input type="hidden" name="menu_config" id="menuConfigField" value="">

  <div class="card" style="margin-bottom:14px">
    <div class="card-header">
      <span class="card-title"><i class="fa-solid fa-list" style="color:#7c3aed"></i> Sezioni e voci</span>
      <span style="font-size:11px;color:var(--muted)">Trascina con <i class="fa-solid fa-grip-vertical"></i> per riordinare</span>
    </div>

    <div id="sectionsContainer">
      <?php foreach ($display_config as $sec): ?>
      <div class="mc-section" data-key="<?= $h($sec['key']) ?>" data-visible="<?= $sec['visible'] ? '1' : '0' ?>"
           style="background:#fafbfc;border:1px solid var(--border);border-radius:8px;margin-bottom:10px;<?= $sec['visible'] ? '' : 'opacity:.55' ?>">
        <div class="mc-section-header" draggable="true"
             style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f1f5f9;border-radius:8px 8px 0 0;cursor:move">
          <i class="fa-solid fa-grip-vertical" style="color:#94a3b8"></i>
          <i class="fa-solid <?= $h($sec['icon']) ?>" style="color:var(--p)"></i>
          <strong style="font-size:13px;flex:1"><?= $h($sec['label']) ?></strong>
          <span style="font-size:10px;color:var(--muted)"><?= count($sec['items']) ?> voci</span>
          <button type="button" class="mc-toggle-sec" title="Mostra/Nascondi sezione"
                  style="background:none;border:0;cursor:pointer;color:<?= $sec['visible'] ? '#16a34a' : '#94a3b8' ?>;font-size:14px;padding:4px 8px">
            <i class="fa-solid fa-<?= $sec['visible'] ? 'eye' : 'eye-slash' ?>"></i>
          </button>
        </div>
        <ul class="mc-items" style="list-style:none;padding:8px;margin:0">
          <?php foreach ($sec['items'] as $it): ?>
          <li class="mc-item" draggable="true"
              data-page="<?= $h($it['page']) ?>"
              data-visible="<?= $it['visible'] ? '1' : '0' ?>"
              data-always="<?= $it['always_visible'] ? '1' : '0' ?>"
              style="display:flex;align-items:center;gap:10px;padding:7px 12px;background:#fff;border:1px solid var(--border);border-radius:6px;margin-bottom:4px;cursor:move;<?= $it['visible'] ? '' : 'opacity:.55' ?>">
            <i class="fa-solid fa-grip-vertical" style="color:#cbd5e1;font-size:11px"></i>
            <i class="fa-solid <?= $h($it['icon']) ?>" style="color:var(--muted);font-size:12px;width:14px;text-align:center"></i>
            <span style="flex:1;font-size:12.5px"><?= $h($it['label']) ?></span>
            <?php if ($it['always_visible']): ?>
              <span style="font-size:9px;background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:8px;font-weight:700">SEMPRE VISIBILE</span>
            <?php else: ?>
              <button type="button" class="mc-toggle-item" title="Mostra/Nascondi voce"
                      style="background:none;border:0;cursor:pointer;color:<?= $it['visible'] ? '#16a34a' : '#94a3b8' ?>;font-size:13px;padding:4px 6px">
                <i class="fa-solid fa-<?= $it['visible'] ? 'eye' : 'eye-slash' ?>"></i>
              </button>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- AZIONI -->
  <div style="display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;align-items:center">
    <button type="button" id="resetBtn" class="btn btn-sm" style="background:#fee2e2;color:#991b1b;border:0"
            onclick="if(confirm('Eliminare la configurazione personalizzata? Il menu tornerà al default.')) {
                document.getElementById('actionField').value='reset_to_default';
                document.getElementById('menuForm').submit();
            }">
      <i class="fa-solid fa-rotate-left"></i> Reset al default
    </button>
    <div style="display:flex;gap:10px">
      <a href="<?= function_exists('url_safe') ? url_safe('index') : 'index.php' ?>" class="btn btn-sm">
        <i class="fa-solid fa-xmark"></i> Annulla
      </a>
      <button type="submit" class="btn btn-primary" id="saveBtn"
              style="background:#16a34a;border:0;padding:10px 20px;font-weight:700">
        <i class="fa-solid fa-floppy-disk"></i> Salva configurazione
      </button>
    </div>
  </div>

  <input type="hidden" name="action" id="actionField" value="save_config">
</form>

<script>
// ─── Drag&drop nativo HTML5 ───
(function() {
  let dragSrc = null;
  let dragType = null; // 'section' o 'item'

  function setupSectionDrag(el) {
    el.addEventListener('dragstart', function(e) {
      dragSrc = el.parentElement; // .mc-section
      dragType = 'section';
      el.style.opacity = '.4';
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', 'section');
    });
    el.addEventListener('dragend', function(e) {
      el.style.opacity = '';
      dragSrc = null;
      dragType = null;
    });
  }

  function setupItemDrag(el) {
    el.addEventListener('dragstart', function(e) {
      dragSrc = el; // .mc-item
      dragType = 'item';
      el.style.opacity = '.4';
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', 'item');
      e.stopPropagation();
    });
    el.addEventListener('dragend', function(e) {
      el.style.opacity = '';
      dragSrc = null;
      dragType = null;
    });
    el.addEventListener('dragover', function(e) {
      if (dragType !== 'item') return;
      e.preventDefault();
      e.stopPropagation();
      e.dataTransfer.dropEffect = 'move';
    });
    el.addEventListener('drop', function(e) {
      if (dragType !== 'item' || !dragSrc || dragSrc === el) return;
      e.preventDefault();
      e.stopPropagation();
      // Inserisco prima o dopo in base alla posizione del cursore
      const rect = el.getBoundingClientRect();
      const after = (e.clientY - rect.top) > rect.height / 2;
      if (after) el.parentNode.insertBefore(dragSrc, el.nextSibling);
      else el.parentNode.insertBefore(dragSrc, el);
    });
  }

  function setupSectionContainerDrag() {
    const container = document.getElementById('sectionsContainer');
    container.addEventListener('dragover', function(e) {
      if (dragType !== 'section') return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });
    container.addEventListener('drop', function(e) {
      if (dragType !== 'section' || !dragSrc) return;
      e.preventDefault();
      // Trova la sezione su cui è stato rilasciato
      const target = e.target.closest('.mc-section');
      if (!target || target === dragSrc) return;
      const rect = target.getBoundingClientRect();
      const after = (e.clientY - rect.top) > rect.height / 2;
      if (after) container.insertBefore(dragSrc, target.nextSibling);
      else container.insertBefore(dragSrc, target);
    });
  }

  function setupULItemDrop(ul) {
    ul.addEventListener('dragover', function(e) {
      if (dragType !== 'item') return;
      e.preventDefault();
    });
    ul.addEventListener('drop', function(e) {
      if (dragType !== 'item' || !dragSrc) return;
      // Se rilascio su una UL diversa (cambio sezione)
      if (ul !== dragSrc.parentNode) {
        e.preventDefault();
        ul.appendChild(dragSrc);
      }
    });
  }

  // ─── Toggle visibilità ───
  function setupToggleSection(btn) {
    btn.addEventListener('click', function() {
      const sec = btn.closest('.mc-section');
      const visible = sec.dataset.visible === '1';
      sec.dataset.visible = visible ? '0' : '1';
      sec.style.opacity = visible ? '.55' : '';
      btn.querySelector('i').className = 'fa-solid fa-' + (visible ? 'eye-slash' : 'eye');
      btn.style.color = visible ? '#94a3b8' : '#16a34a';
    });
  }
  function setupToggleItem(btn) {
    btn.addEventListener('click', function() {
      const item = btn.closest('.mc-item');
      if (item.dataset.always === '1') return;
      const visible = item.dataset.visible === '1';
      item.dataset.visible = visible ? '0' : '1';
      item.style.opacity = visible ? '.55' : '';
      btn.querySelector('i').className = 'fa-solid fa-' + (visible ? 'eye-slash' : 'eye');
      btn.style.color = visible ? '#94a3b8' : '#16a34a';
    });
  }

  // Setup
  document.querySelectorAll('.mc-section-header').forEach(setupSectionDrag);
  document.querySelectorAll('.mc-item').forEach(setupItemDrag);
  document.querySelectorAll('.mc-items').forEach(setupULItemDrop);
  document.querySelectorAll('.mc-toggle-sec').forEach(setupToggleSection);
  document.querySelectorAll('.mc-toggle-item').forEach(setupToggleItem);
  setupSectionContainerDrag();

  // ─── Serializza prima del submit ───
  document.getElementById('menuForm').addEventListener('submit', function(e) {
    if (document.getElementById('actionField').value === 'reset_to_default') return;
    const config = [];
    document.querySelectorAll('#sectionsContainer .mc-section').forEach(sec => {
      const items = [];
      sec.querySelectorAll('.mc-item').forEach(it => {
        items.push({
          page: it.dataset.page,
          visible: it.dataset.visible === '1',
        });
      });
      config.push({
        key: sec.dataset.key,
        visible: sec.dataset.visible === '1',
        items: items,
      });
    });
    document.getElementById('menuConfigField').value = JSON.stringify(config);
  });
})();
</script>

<?php require_once('footer.php'); ?>
