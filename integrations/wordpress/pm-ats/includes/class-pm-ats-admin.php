<?php
/**
 * Amministrazione WordPress: menu «Lavora con noi» → Candidature, Posizioni, Impostazioni, Configurazione guidata, Registro.
 * v1.1.0 — Impostazioni a schede (Connessione, Pagina e modulo, Aspetto, Dati e conservazione, Versione e manutenzione)
 *          con salvataggio per scheda; codice di connessione; export/import impostazioni; ripristino; storico versioni.
 * Capability: manage_options (configurazione), pm_ats_view (candidature; assegnata agli amministratori,
 * attribuibile ad altri ruoli con un plugin di gestione ruoli).
 */
defined('ABSPATH') || exit;

final class PM_ATS_Admin
{
    public const CAP_VIEW = 'pm_ats_view';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'settings']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_pm_ats_rotate', [self::class, 'rotate']);
        add_action('admin_post_pm_ats_maint', [self::class, 'maintenance']);   // v1.1.0
        add_action('admin_post_pm_ats_app', [self::class, 'appAction']);
        add_filter('manage_' . PM_ATS_Jobs::CPT . '_posts_columns', [self::class, 'cols']);
        add_filter('admin_footer_text', [self::class, 'footerText']);   // v1.3.4 — riferimenti di creazione
        add_action('manage_' . PM_ATS_Jobs::CPT . '_posts_custom_column', [self::class, 'col'], 10, 2);
        add_action('admin_notices', [self::class, 'notices']);
        $role = get_role('administrator');
        if ($role && !$role->has_cap(self::CAP_VIEW)) $role->add_cap(self::CAP_VIEW);
    }

    public static function menu(): void
    {
        add_menu_page(__('Lavora con noi', 'pm-ats'), __('Lavora con noi', 'pm-ats'), self::CAP_VIEW, 'pm-ats', [self::class, 'pageApps'], 'dashicons-id-alt', 26);
        add_submenu_page('pm-ats', __('Candidature', 'pm-ats'), __('Candidature', 'pm-ats'), self::CAP_VIEW, 'pm-ats', [self::class, 'pageApps']);
        add_submenu_page('pm-ats', __('Impostazioni', 'pm-ats'), __('Impostazioni', 'pm-ats'), 'manage_options', 'pm-ats-settings', [self::class, 'pageSettings']);
        add_submenu_page('pm-ats', __('Registro sincronizzazioni', 'pm-ats'), __('Registro', 'pm-ats'), 'manage_options', 'pm-ats-log', [self::class, 'pageLog']);
    }

    public static function assets(string $hook): void
    {
        if (!str_contains($hook, 'pm-ats') && get_current_screen()?->post_type !== PM_ATS_Jobs::CPT) return;
        wp_enqueue_style('pm-ats-admin', PM_ATS_URL . 'assets/admin.css', [], PM_ATS_VERSION);
        if (str_contains($hook, 'pm-ats-settings') || str_contains($hook, 'pm-ats-setup')) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".pm-ats-color").wpColorPicker();});');
            wp_enqueue_media();   // v1.3.4 — immagine di testata dalla Libreria media
            wp_enqueue_script('pm-ats-admin', PM_ATS_URL . 'assets/pm-ats-admin.js', ['jquery'], PM_ATS_VERSION, true);
        }
    }

    public static function settings(): void
    {
        register_setting('pm_ats', PM_ATS_Settings::OPTION, ['type' => 'array', 'sanitize_callback' => [PM_ATS_Settings::class, 'sanitize']]);
    }

    public static function notices(): void
    {
        if (!current_user_can('manage_options')) return;
        $scr = get_current_screen();
        if (!$scr || !str_contains((string)$scr->id, 'pm-ats')) return;
        if (PM_ATS_Settings::secretSource() === 'none')
            echo '<div class="notice notice-warning"><p>' . esc_html__('Segreto condiviso non configurato: PortalManager non può sincronizzare. Generalo in Impostazioni › Connessione.', 'pm-ats') . '</p></div>';
        if (PM_ATS_Settings::get('allowed_ips') === '')
            echo '<div class="notice notice-info"><p>' . esc_html__('Suggerito: limitare le chiamate di sincronizzazione all\'IP pubblico di PortalManager (Impostazioni › Connessione › IP consentiti).', 'pm-ats') . '</p></div>';
    }

    /* ── colonne elenco posizioni ────────────────────────────────────── */

    public static function cols(array $c): array
    {
        unset($c['date']);
        return $c + ['pm_id' => 'ID PortalManager', 'pm_loc' => __('Sede', 'pm-ats'), 'pm_ct' => __('Contratto', 'pm-ats'), 'pm_apps' => __('Candidature', 'pm-ats'), 'date' => __('Data', 'pm-ats')];
    }

    public static function col(string $c, int $id): void
    {
        global $wpdb;
        if ($c === 'pm_id')  echo (int)get_post_meta($id, '_pm_id', true);
        if ($c === 'pm_loc') echo esc_html((string)get_post_meta($id, '_pm_location', true));
        if ($c === 'pm_ct')  echo esc_html((string)get_post_meta($id, '_pm_contract_type', true));
        if ($c === 'pm_apps') echo (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . PM_ATS_Applications::table() . ' WHERE job_post_id = %d', $id));
    }

    /* ── candidature ─────────────────────────────────────────────────── */

    public static function pageApps(): void
    {
        if (!current_user_can(self::CAP_VIEW)) wp_die(esc_html__('Accesso negato.', 'pm-ats'));
        $st = sanitize_key((string)($_GET['status'] ?? ''));
        if (!in_array($st, ['', 'pending', 'synced', 'error'], true)) $st = '';
        $paged = max(1, (int)($_GET['paged'] ?? 1)); $per = 50;
        $rows = PM_ATS_Applications::list($st, $per, ($paged - 1) * $per);
        $cnt = PM_ATS_Applications::counts();
        $last = get_option('pm_ats_last_contact');
        $stLbl = ['pending' => __('Da importare', 'pm-ats'), 'synced' => __('In PortalManager', 'pm-ats'), 'error' => __('Errore import', 'pm-ats')];
        $msg = sanitize_key((string)($_GET['done'] ?? ''));
        ?>
        <div class="wrap pm-ats-admin">
          <h1><?php esc_html_e('Candidature', 'pm-ats'); ?></h1>
          <?php if ($msg): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(['deleted' => __('Candidatura eliminata.', 'pm-ats'), 'retry' => __('Candidatura rimessa in coda per PortalManager.', 'pm-ats')][$msg] ?? ''); ?></p></div><?php endif; ?>
          <div class="pm-ats-cards">
            <div class="pm-ats-kpi"><b><?php echo (int)PM_ATS_Jobs::count(); ?></b><span><?php esc_html_e('Posizioni pubblicate', 'pm-ats'); ?></span></div>
            <div class="pm-ats-kpi"><b><?php echo (int)$cnt['pending']; ?></b><span><?php echo esc_html($stLbl['pending']); ?></span></div>
            <div class="pm-ats-kpi"><b><?php echo (int)$cnt['synced']; ?></b><span><?php echo esc_html($stLbl['synced']); ?></span></div>
            <div class="pm-ats-kpi"><b><?php echo (int)$cnt['error']; ?></b><span><?php echo esc_html($stLbl['error']); ?></span></div>
            <div class="pm-ats-kpi"><b><?php echo $last ? esc_html(human_time_diff((int)$last['at'])) : '—'; ?></b><span><?php esc_html_e('Ultimo contatto PortalManager', 'pm-ats'); ?></span></div>
          </div>
          <ul class="subsubsub">
            <?php $tabs = ['' => __('Tutte', 'pm-ats')] + $stLbl; $i = 0; foreach ($tabs as $k => $l): ?>
              <li><a href="<?php echo esc_url(add_query_arg(['page' => 'pm-ats', 'status' => $k], admin_url('admin.php'))); ?>" class="<?php echo $st === $k ? 'current' : ''; ?>"><?php echo esc_html($l); ?></a><?php echo ++$i < count($tabs) ? ' |' : ''; ?></li>
            <?php endforeach; ?>
          </ul>
          <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Ricevuta', 'pm-ats'); ?></th><th><?php esc_html_e('Candidato', 'pm-ats'); ?></th><th><?php esc_html_e('Posizione', 'pm-ats'); ?></th>
              <th><?php esc_html_e('CV', 'pm-ats'); ?></th><th><?php esc_html_e('Stato', 'pm-ats'); ?></th><th><?php esc_html_e('Azioni', 'pm-ats'); ?></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="6"><?php esc_html_e('Nessuna candidatura.', 'pm-ats'); ?></td></tr><?php endif; ?>
            <?php foreach ($rows as $r):
                $act = static fn(string $do) => wp_nonce_url(admin_url('admin-post.php?action=pm_ats_app&do=' . $do . '&id=' . (int)$r['id']), 'pm_ats_app_' . (int)$r['id']); ?>
              <tr>
                <td><?php echo esc_html(get_date_from_gmt($r['created_at'], 'd/m/Y H:i')); ?><br><code><?php echo esc_html(strtoupper(substr($r['uuid'], 0, 8))); ?></code></td>
                <td><strong><?php echo esc_html($r['first_name'] . ' ' . $r['last_name']); ?></strong><br><?php echo esc_html($r['email']); ?></td>
                <td><?php echo esc_html($r['position_title']); ?></td>
                <td><?php if ($r['cv_file'] !== '' && current_user_can('manage_options')): ?><a href="<?php echo esc_url($act('cv')); ?>"><?php echo esc_html($r['cv_name'] ?: 'CV'); ?></a><?php else: echo $r['purged_at'] ? esc_html__('rimosso dopo l\'import', 'pm-ats') : '—'; endif; ?></td>
                <td><span class="pm-ats-st pm-ats-st-<?php echo esc_attr($r['status']); ?>"><?php echo esc_html($stLbl[$r['status']] ?? $r['status']); ?></span>
                  <?php if ($r['status'] === 'synced' && $r['pm_candidate_id']): ?><br><small><?php echo esc_html(sprintf(__('Candidato #%d', 'pm-ats'), (int)$r['pm_candidate_id'])); ?></small><?php endif; ?>
                  <?php if ($r['last_error'] !== ''): ?><br><small><?php echo esc_html($r['last_error']); ?></small><?php endif; ?></td>
                <td><?php if (current_user_can('manage_options')): ?>
                  <?php if ($r['status'] === 'error'): ?><a href="<?php echo esc_url($act('retry')); ?>"><?php esc_html_e('Riprova', 'pm-ats'); ?></a> · <?php endif; ?>
                  <a href="<?php echo esc_url($act('delete')); ?>" onclick="return confirm('<?php echo esc_js(__('Eliminare definitivamente candidatura e CV?', 'pm-ats')); ?>')" style="color:#b32d2e"><?php esc_html_e('Elimina', 'pm-ats'); ?></a>
                <?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($rows) === $per || $paged > 1): ?>
            <p><?php if ($paged > 1): ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $paged - 1)); ?>">&larr;</a><?php endif; ?>
               <?php if (count($rows) === $per): ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $paged + 1)); ?>">&rarr;</a><?php endif; ?></p>
          <?php endif; ?>
          <p class="description"><?php esc_html_e('Le candidature restano qui fino al prelievo da parte di PortalManager; dopo l\'import CV e dati non necessari vengono rimossi dal sito.', 'pm-ats'); ?></p>
        </div>
        <?php
    }

    public static function appAction(): void
    {
        $id = (int)($_GET['id'] ?? 0); $do = sanitize_key((string)($_GET['do'] ?? ''));
        if (!current_user_can('manage_options') || !check_admin_referer('pm_ats_app_' . $id)) wp_die(esc_html__('Accesso negato.', 'pm-ats'), 403);
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . PM_ATS_Applications::table() . ' WHERE id = %d', $id), ARRAY_A);
        if (!$r) wp_die(esc_html__('Candidatura non trovata.', 'pm-ats'), 404);
        if ($do === 'cv') {
            $p = PM_ATS_Applications::cvPath($r);
            if (!$p) wp_die(esc_html__('File non disponibile.', 'pm-ats'), 404);
            nocache_headers();
            header('Content-Type: ' . $r['cv_mime']);
            header('Content-Length: ' . filesize($p));
            header('Content-Disposition: attachment; filename="' . rawurlencode($r['cv_name'] ?: basename($p)) . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($p); exit;
        }
        if ($do === 'delete') { PM_ATS_Applications::delete($id); $done = 'deleted'; }
        elseif ($do === 'retry') { $wpdb->update(PM_ATS_Applications::table(), ['status' => 'pending', 'last_error' => ''], ['id' => $id]); $done = 'retry'; }
        else wp_die(esc_html__('Azione non valida.', 'pm-ats'), 400);
        wp_safe_redirect(admin_url('admin.php?page=pm-ats&done=' . $done)); exit;
    }

    /* ── impostazioni ────────────────────────────────────────────────── */

    public static function rotate(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('pm_ats_rotate')) wp_die(esc_html__('Accesso negato.', 'pm-ats'), 403);
        if (($_POST['op'] ?? '') === 'delete') { PM_ATS_Settings::deleteSecret(); $q = 'deleted'; PM_ATS_Log::add('secret', 200, 'eliminato'); }
        else {
            $sec = PM_ATS_Settings::rotateSecret();
            set_transient('pm_ats_show_secret_' . get_current_user_id(), $sec . '|' . PM_ATS_Settings::connectionCode($sec), 120);
            $q = 'rotated'; PM_ATS_Log::add('secret', 200, 'rigenerato');
        }
        wp_safe_redirect(admin_url('admin.php?page=pm-ats-settings&tab=connessione&secret=' . $q)); exit;
    }

    /** v1.1.0 — manutenzione: esporta / importa impostazioni (senza segreto), ripristino, nuova configurazione guidata. */
    public static function maintenance(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('pm_ats_maint')) wp_die(esc_html__('Accesso negato.', 'pm-ats'), 403);
        $op = sanitize_key((string)($_POST['op'] ?? ''));
        $back = admin_url('admin.php?page=pm-ats-settings&tab=manutenzione');
        if ($op === 'export') {
            $json = wp_json_encode(['plugin' => 'pm-ats', 'version' => PM_ATS_VERSION, 'settings_version' => PM_ATS_SETTINGS_VERSION, 'exported_at' => gmdate('c'),
                                    'site' => home_url('/'), 'settings' => PM_ATS_Settings::all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="pm-ats-impostazioni-' . gmdate('Ymd-His') . '.json"');
            echo $json; exit;
        }
        if ($op === 'import') {
            $raw = isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) ? (string)file_get_contents($_FILES['file']['tmp_name'], false, null, 0, 262144) : '';
            $d = json_decode($raw, true);
            if (!is_array($d) || ($d['plugin'] ?? '') !== 'pm-ats' || !is_array($d['settings'] ?? null)) { wp_safe_redirect($back . '&m=import_ko'); exit; }
            update_option(PM_ATS_Settings::OPTION, PM_ATS_Settings::sanitize(array_intersect_key($d['settings'], PM_ATS_Settings::defaults()) + PM_ATS_Settings::all()));
            PM_ATS_Log::add('settings', 200, 'importate da file (v' . sanitize_text_field((string)($d['version'] ?? '?')) . ')');
            wp_safe_redirect($back . '&m=import_ok'); exit;
        }
        if ($op === 'reset') {
            $keep = array_intersect_key(PM_ATS_Settings::all(), array_flip(['client_id', 'allowed_ips', 'ip_source', 'list_page_id', 'jobs_slug']));
            update_option(PM_ATS_Settings::OPTION, PM_ATS_Settings::sanitize($keep + PM_ATS_Settings::defaults()));
            PM_ATS_Log::add('settings', 200, 'ripristino valori predefiniti');
            wp_safe_redirect($back . '&m=reset_ok'); exit;
        }
        if ($op === 'wizard') { PM_ATS_Upgrade::restart(); wp_safe_redirect(PM_ATS_Setup::url(1)); exit; }
        wp_die(esc_html__('Operazione non valida.', 'pm-ats'), 400);
    }

    /** v1.3.4 — Riferimenti di creazione in fondo alle impostazioni e nel piè di pagina delle schermate del plugin. */
    public const CREDITS = 'Ideatore del plugin per WordPress: Antonello Orrù © 2026 · componente PortalManager_BI';

    public static function credits(): void
    {
        echo '<p class="pm-ats-credits" style="margin:24px 0 0;padding-top:12px;border-top:1px solid #dcdcde;color:#646970;font-size:12px">'
           . esc_html(self::CREDITS) . ' · pm-ats ' . esc_html(PM_ATS_VERSION) . '</p>';
    }

    public static function footerText($text)
    {
        $scr = function_exists('get_current_screen') ? get_current_screen() : null;
        return $scr && str_contains((string)$scr->id, 'pm-ats') ? esc_html(self::CREDITS) : $text;
    }

    public static function pageSettings(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Accesso negato.', 'pm-ats'));
        $tabs = ['connessione' => __('Connessione', 'pm-ats'), 'pagina' => __('Pagina e modulo', 'pm-ats'), 'aspetto' => __('Aspetto', 'pm-ats'),
                 'dati' => __('Dati e conservazione', 'pm-ats'), 'manutenzione' => __('Versione e manutenzione', 'pm-ats')];
        $tab = sanitize_key((string)($_GET['tab'] ?? 'connessione'));
        if (!isset($tabs[$tab])) $tab = 'connessione';
        $s = PM_ATS_Settings::all(); $n = PM_ATS_Settings::OPTION;
        $f = static fn(string $k) => esc_attr($n . '[' . $k . ']');
        $txt = static function (string $k, string $type = 'text', string $extra = '') use ($s, $f): void {
            printf('<input type="%s" name="%s" value="%s" class="regular-text" %s>', esc_attr($type), $f($k), esc_attr((string)$s[$k]), $extra); // phpcs:ignore
        };
        $chk = static function (string $k, string $label) use ($s, $f): void {
            printf('<label><input type="checkbox" name="%s" value="1" %s> %s</label>', $f($k), checked(!empty($s[$k]), true, false), esc_html($label)); // phpcs:ignore
        };
        $ob = PM_ATS_Upgrade::onboarding();
        $msg = sanitize_key((string)($_GET['m'] ?? ''));
        ?>
        <div class="wrap pm-ats-admin">
          <h1><?php esc_html_e('Lavora con noi — Impostazioni', 'pm-ats'); ?> <span class="pm-ats-ver">v<?php echo esc_html(PM_ATS_VERSION); ?></span>
            <a class="page-title-action" href="<?php echo esc_url(PM_ATS_Setup::url()); ?>"><?php esc_html_e('Configurazione guidata', 'pm-ats'); ?></a></h1>
          <?php if (!empty($_GET['setup'])): ?><div class="notice notice-success"><p><?php esc_html_e('Configurazione guidata completata.', 'pm-ats'); ?></p></div><?php endif; ?>
          <?php if (!empty($_GET['settings-updated'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Impostazioni salvate.', 'pm-ats'); ?></p></div><?php endif; ?>
          <?php if ($msg): ?><div class="notice notice-<?php echo str_ends_with($msg, '_ko') ? 'error' : 'success'; ?>"><p><?php echo esc_html(['import_ok' => __('Impostazioni importate.', 'pm-ats'), 'import_ko' => __('File non valido: atteso un export pm-ats (JSON).', 'pm-ats'), 'reset_ok' => __('Valori predefiniti ripristinati (connessione e pagina conservate).', 'pm-ats')][$msg] ?? ''); ?></p></div><?php endif; ?>
          <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $k => $l): ?><a href="<?php echo esc_url(admin_url('admin.php?page=pm-ats-settings&tab=' . $k)); ?>" class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($l); ?></a><?php endforeach; ?>
          </nav>

          <?php if ($tab === 'connessione'): self::tabConnection($s, $f); endif; ?>

          <?php if (in_array($tab, ['connessione', 'pagina', 'aspetto', 'dati'], true)): ?>
          <form method="post" action="options.php">
            <?php settings_fields('pm_ats'); ?>
            <input type="hidden" name="<?php echo $f('_tab'); ?>" value="<?php echo esc_attr($tab); ?>">
            <table class="form-table" role="presentation">
            <?php if ($tab === 'connessione'): ?>
              <tr><th><?php esc_html_e('Client ID', 'pm-ats'); ?></th><td><?php $txt('client_id'); ?><p class="description"><?php esc_html_e('Deve coincidere con quello configurato in PortalManager.', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('IP consentiti', 'pm-ats'); ?></th><td><?php $txt('allowed_ips', 'text', 'placeholder="203.0.113.10, 2001:db8::/48"'); ?><p class="description"><?php esc_html_e('IP pubblico (o CIDR) da cui esce PortalManager. Vuoto = nessuna restrizione.', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('Origine IP client', 'pm-ats'); ?></th><td><select name="<?php echo $f('ip_source'); ?>">
                <?php foreach (['REMOTE_ADDR' => 'REMOTE_ADDR (diretto)', 'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For (proxy)', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)'] as $k => $l): ?>
                  <option value="<?php echo esc_attr($k); ?>" <?php selected($s['ip_source'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
                <p class="description"><?php esc_html_e('Usare un\'intestazione proxy solo se il sito è dietro un proxy affidabile.', 'pm-ats'); ?></p></td></tr>
            <?php elseif ($tab === 'pagina'): ?>
              <tr><th><?php esc_html_e('Pagina elenco posizioni', 'pm-ats'); ?></th><td>
                <?php wp_dropdown_pages(['name' => $n . '[list_page_id]', 'selected' => (int)$s['list_page_id'], 'show_option_none' => __('— archivio del plugin —', 'pm-ats'), 'option_none_value' => '0']); // phpcs:ignore ?>
                <p class="description"><?php esc_html_e('Pagina che contiene lo shortcode [pm_ats_jobs] (es. «Lavora con noi»). L\'archivio delle posizioni vi reindirizza.', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('Slug delle posizioni', 'pm-ats'); ?></th><td><?php $txt('jobs_slug'); ?><p class="description"><code><?php echo esc_html(home_url('/' . $s['jobs_slug'] . '/titolo-posizione/')); ?></code></p></td></tr>
              <tr><th><?php esc_html_e('Modulo', 'pm-ats'); ?></th><td>
                <?php $chk('auto_form', __('Modulo di candidatura in fondo a ogni posizione', 'pm-ats')); ?><br>
                <?php $chk('allow_spontaneous', __('Candidatura spontanea', 'pm-ats')); ?><br>
                <?php $chk('phone_required', __('Telefono obbligatorio', 'pm-ats')); ?><br>
                <?php $chk('show_salary', __('Campo RAL desiderata', 'pm-ats')); ?></td></tr>
              <tr><th><?php esc_html_e('Informativa privacy', 'pm-ats'); ?></th><td><?php $txt('privacy_url', 'url'); ?> <?php esc_html_e('versione', 'pm-ats'); ?> <input type="text" name="<?php echo $f('privacy_version'); ?>" value="<?php echo esc_attr($s['privacy_version']); ?>" class="small-text"></td></tr>
              <tr><th><?php esc_html_e('CV', 'pm-ats'); ?></th><td><?php $txt('cv_types'); ?> <input type="number" min="1" max="20" name="<?php echo $f('cv_max_mb'); ?>" value="<?php echo (int)$s['cv_max_mb']; ?>" class="small-text"> MB
                <p class="description"><?php echo esc_html(sprintf(__('Formati ammessi: pdf, doc, docx. Limite del server: %s.', 'pm-ats'), size_format(wp_max_upload_size()))); ?></p></td></tr>
              <tr><th><?php esc_html_e('Anti-abuso', 'pm-ats'); ?></th><td><input type="number" min="1" max="100" name="<?php echo $f('rate_per_day'); ?>" value="<?php echo (int)$s['rate_per_day']; ?>" class="small-text"> <?php esc_html_e('invii per IP al giorno', 'pm-ats'); ?> ·
                <input type="number" min="0" max="60" name="<?php echo $f('min_fill_seconds'); ?>" value="<?php echo (int)$s['min_fill_seconds']; ?>" class="small-text"> <?php esc_html_e('secondi minimi di compilazione', 'pm-ats'); ?></td></tr>
              <tr><th><?php esc_html_e('Notifiche', 'pm-ats'); ?></th><td><?php $txt('notify_email', 'email', 'placeholder="hr@azienda.it"'); ?><br><?php $chk('confirm_candidate', __('Email di conferma al candidato', 'pm-ats')); ?></td></tr>
            <?php elseif ($tab === 'aspetto'): ?>
              <?php foreach (['color_primary' => __('Colore principale', 'pm-ats'), 'color_primary_text' => __('Testo sui pulsanti', 'pm-ats'), 'color_text' => __('Testo', 'pm-ats'),
                              'color_muted' => __('Testo secondario', 'pm-ats'), 'color_card' => __('Sfondo schede', 'pm-ats'), 'color_border' => __('Bordi', 'pm-ats')] as $k => $l): ?>
                <tr><th><?php echo esc_html($l); ?></th><td><input type="text" class="pm-ats-color" name="<?php echo $f($k); ?>" value="<?php echo esc_attr($s[$k]); ?>"></td></tr>
              <?php endforeach; ?>
              <tr><th><?php esc_html_e('Arrotondamento', 'pm-ats'); ?></th><td><input type="number" min="0" max="30" name="<?php echo $f('radius'); ?>" value="<?php echo (int)$s['radius']; ?>" class="small-text"> px</td></tr>
              <tr><th><?php esc_html_e('Font', 'pm-ats'); ?></th><td><?php $txt('font_family', 'text', 'placeholder="es. Poppins, sans-serif"'); ?><p class="description"><?php esc_html_e('Vuoto (come testo e colori vuoti) = ereditato dal tema.', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('Elenco', 'pm-ats'); ?></th><td><select name="<?php echo $f('layout'); ?>"><option value="grid" <?php selected($s['layout'], 'grid'); ?>><?php esc_html_e('Griglia di schede', 'pm-ats'); ?></option><option value="list" <?php selected($s['layout'], 'list'); ?>><?php esc_html_e('Lista', 'pm-ats'); ?></option>
                <option value="accordion" <?php selected($s['layout'], 'accordion'); ?>><?php esc_html_e('Lavora con noi: elenco posizioni e modulo a destra', 'pm-ats'); ?></option></select>
                <input type="number" min="1" max="100" name="<?php echo $f('per_page'); ?>" value="<?php echo (int)$s['per_page']; ?>" class="small-text"> <?php esc_html_e('per pagina', 'pm-ats'); ?></td></tr>
              <tr><th><?php esc_html_e('Barra laterale del tema', 'pm-ats'); ?></th><td><?php $chk('hide_sidebar', __('Nascondi la colonna laterale del tema (widget, articoli recenti) nelle pagine delle posizioni e «Lavora con noi»', 'pm-ats')); ?></td></tr>
              <tr><th><?php esc_html_e('Titolo della pagina', 'pm-ats'); ?></th><td><select name="<?php echo $f('page_title_mode'); ?>" id="pm-ats-ptm">
                <option value="show" <?php selected($s['page_title_mode'], 'show'); ?>><?php esc_html_e('Mostra il titolo della pagina (tema)', 'pm-ats'); ?></option>
                <option value="hide" <?php selected($s['page_title_mode'], 'hide'); ?>><?php esc_html_e('Nascondi il titolo', 'pm-ats'); ?></option>
                <option value="custom" <?php selected($s['page_title_mode'], 'custom'); ?>><?php esc_html_e('Mostra un testo personalizzato', 'pm-ats'); ?></option></select>
                <?php $txt('page_title_text', 'text', 'maxlength="150" placeholder="' . esc_attr__('es. Lavora con noi', 'pm-ats') . '"'); ?>
                <p class="description"><?php esc_html_e('Titolo stampato dal tema sopra il contenuto (es. Divi «h1.entry-title.main_title») nelle pagine «Lavora con noi» e con gli shortcode del plugin. La scheda della posizione mantiene il titolo della posizione; titolo del browser e SEO non cambiano. Testo vuoto = titolo della pagina.', 'pm-ats'); ?></p></td></tr>
              <tr><th colspan="2"><h3 style="margin:8px 0 0"><?php esc_html_e('Layout «Lavora con noi»', 'pm-ats'); ?></h3></th></tr>
              <tr><th><?php esc_html_e('Elenco posizioni', 'pm-ats'); ?></th><td><select name="<?php echo $f('wt_list_mode'); ?>">
                <option value="link" <?php selected($s['wt_list_mode'], 'link'); ?>><?php esc_html_e('Titolo cliccabile che apre la scheda della posizione', 'pm-ats'); ?></option>
                <option value="accordion" <?php selected($s['wt_list_mode'], 'accordion'); ?>><?php esc_html_e('Dettaglio a fisarmonica nella pagina', 'pm-ats'); ?></option></select></td></tr>
              <?php foreach (['color_title' => __('Colore titoli', 'pm-ats'), 'color_accent' => __('Colore d\'accento (evidenza, riquadro modulo)', 'pm-ats')] as $k => $l): ?>
                <tr><th><?php echo esc_html($l); ?></th><td><input type="text" class="pm-ats-color" name="<?php echo $f($k); ?>" value="<?php echo esc_attr($s[$k]); ?>"></td></tr>
              <?php endforeach; ?>
              <tr><th><?php esc_html_e('Sezione di testata', 'pm-ats'); ?></th><td><?php $chk('wt_hero', __('Mostra la testata con titolo e immagine (disattivare se la pagina ne ha già una)', 'pm-ats')); ?><br>
                <?php $txt('wt_hero_title', 'text'); ?><br><?php $hid = (int)$s['wt_hero_image_id']; $hp = $hid > 0 ? (string)wp_get_attachment_image_url($hid, 'medium') : ''; if ($hp === '') $hp = (string)$s['wt_hero_image']; ?>
                <span class="pm-ats-media" data-title="<?php esc_attr_e('Immagine della testata', 'pm-ats'); ?>" data-button="<?php esc_attr_e('Usa questa immagine', 'pm-ats'); ?>">
                  <input type="url" class="regular-text pm-ats-media-url" name="<?php echo $f('wt_hero_image'); ?>" value="<?php echo esc_attr($s['wt_hero_image']); ?>" placeholder="https://…/immagine.jpg">
                  <input type="hidden" class="pm-ats-media-id" name="<?php echo $f('wt_hero_image_id'); ?>" value="<?php echo $hid; ?>">
                  <button type="button" class="button pm-ats-media-pick"><?php esc_html_e('Scegli dalla Libreria media', 'pm-ats'); ?></button>
                  <button type="button" class="button-link button-link-delete pm-ats-media-del"<?php echo $s['wt_hero_image'] === '' ? ' hidden' : ''; ?>><?php esc_html_e('Rimuovi', 'pm-ats'); ?></button>
                  <span class="pm-ats-media-prev"><?php if ($hp !== ''): ?><img src="<?php echo esc_url($hp); ?>" alt=""><?php endif; ?></span>
                </span>
                <select name="<?php echo $f('wt_hero_fit'); ?>">
                  <option value="band" <?php selected($s['wt_hero_fit'], 'band'); ?>><?php esc_html_e('Fascia ad altezza fissa: l\'immagine si adatta solo in larghezza', 'pm-ats'); ?></option>
                  <option value="scale" <?php selected($s['wt_hero_fit'], 'scale'); ?>><?php esc_html_e('Immagine intera, ridimensionata con la larghezza della finestra', 'pm-ats'); ?></option>
                  <option value="cover" <?php selected($s['wt_hero_fit'], 'cover'); ?>><?php esc_html_e('Fascia proporzionale alla finestra, immagine ritagliata', 'pm-ats'); ?></option></select>
<br><label><?php esc_html_e('Altezza della fascia', 'pm-ats'); ?> <input type="number" min="150" max="900" step="10" name="<?php echo $f('wt_hero_height'); ?>" value="<?php echo (int)$s['wt_hero_height']; ?>" class="small-text"> px</label>
                <label style="margin-left:10px"><?php esc_html_e('Parte visibile dell\'immagine', 'pm-ats'); ?>
                <select name="<?php echo $f('wt_hero_pos'); ?>">
                  <option value="top" <?php selected($s['wt_hero_pos'], 'top'); ?>><?php esc_html_e('alto', 'pm-ats'); ?></option>
                  <option value="center" <?php selected($s['wt_hero_pos'], 'center'); ?>><?php esc_html_e('centro', 'pm-ats'); ?></option>
                  <option value="bottom" <?php selected($s['wt_hero_pos'], 'bottom'); ?>><?php esc_html_e('basso', 'pm-ats'); ?></option></select></label><br>
                <select name="<?php echo $f('wt_hero_width'); ?>">
                  <option value="window" <?php selected($s['wt_hero_width'], 'window'); ?>><?php esc_html_e('Larghezza: tutta la finestra del browser', 'pm-ats'); ?></option>
                  <option value="container" <?php selected($s['wt_hero_width'], 'container'); ?>><?php esc_html_e('Larghezza: contenitore della pagina (riga/colonna del tema)', 'pm-ats'); ?></option></select>
                <p class="description"><?php esc_html_e('«Fascia ad altezza fissa»: la testata è larga quanto la finestra e alta quanto indicato; allargando o stringendo la finestra l\'immagine si adatta in larghezza e l\'altezza non cambia. Carica o scegli l\'immagine dalla Libreria media: il browser scarica la dimensione adatta allo schermo (srcset).', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('Titolo', 'pm-ats'); ?></th><td><?php $txt('wt_title', 'text'); ?><p class="description"><?php esc_html_e('Il testo fra parentesi graffe {…} è evidenziato con il colore d\'accento.', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('Introduzione', 'pm-ats'); ?></th><td><textarea name="<?php echo $f('wt_intro'); ?>" rows="3" class="large-text"><?php echo esc_textarea($s['wt_intro']); ?></textarea></td></tr>
              <tr><th><?php esc_html_e('Titoli elenco / modulo', 'pm-ats'); ?></th><td><?php $txt('wt_list_title', 'text'); ?> <?php $txt('wt_form_title', 'text'); ?></td></tr>
              <tr><th><?php esc_html_e('CSS aggiuntivo', 'pm-ats'); ?></th><td><textarea name="<?php echo $f('custom_css'); ?>" rows="5" class="large-text code"><?php echo esc_textarea($s['custom_css']); ?></textarea></td></tr>
            <?php elseif ($tab === 'dati'): ?>
              <tr><th><?php esc_html_e('Azienda (dati strutturati)', 'pm-ats'); ?></th><td><?php $txt('company_name', 'text', 'placeholder="' . esc_attr(get_bloginfo('name')) . '"'); ?><br><?php $txt('company_logo', 'url', 'placeholder="https://…/logo.png"'); ?></td></tr>
              <tr><th><?php esc_html_e('Dopo l\'import', 'pm-ats'); ?></th><td><?php $chk('purge_after_ack', __('Elimina dal sito CV e dati non necessari quando PortalManager conferma l\'import', 'pm-ats')); ?></td></tr>
              <tr><th><?php esc_html_e('Conservazione', 'pm-ats'); ?></th><td>
                <input type="number" min="1" max="3650" name="<?php echo $f('retention_synced'); ?>" value="<?php echo (int)$s['retention_synced']; ?>" class="small-text"> <?php esc_html_e('giorni per le candidature importate', 'pm-ats'); ?><br>
                <input type="number" min="7" max="3650" name="<?php echo $f('retention_pending'); ?>" value="<?php echo (int)$s['retention_pending']; ?>" class="small-text"> <?php esc_html_e('giorni per quelle mai importate', 'pm-ats'); ?></td></tr>
              <tr><th><?php esc_html_e('Disinstallazione', 'pm-ats'); ?></th><td><?php $chk('remove_on_uninstall', __('Elimina tabelle, CV, posizioni e impostazioni alla disinstallazione', 'pm-ats')); ?></td></tr>
            <?php endif; ?>
            </table>
            <?php submit_button(); ?>
          </form>
          <?php if ($tab === 'pagina'): ?>
            <h2><?php esc_html_e('Shortcode', 'pm-ats'); ?></h2>
            <p><code>[pm_ats_jobs]</code> <code>[pm_ats_jobs layout="list" per_page="20" filters="0" spontaneous="0"]</code> <code>[pm_ats_apply]</code> <code>[pm_ats_apply job="ID"]</code> <code>[pm_ats_count]</code></p>
          <?php endif; ?>
          <?php endif; ?>

          <?php if ($tab === 'manutenzione') self::tabMaintenance($ob); ?>
          <?php self::credits(); ?>
        </div>
        <?php
    }

    private static function tabConnection(array $s, callable $f): void
    {
        $show = get_transient('pm_ats_show_secret_' . get_current_user_id());
        if ($show) delete_transient('pm_ats_show_secret_' . get_current_user_id());
        [$sec, $code] = $show ? explode('|', (string)$show, 2) + [1 => ''] : ['', ''];
        $src = PM_ATS_Settings::secretSource();
        $last = get_option('pm_ats_last_contact');
        ?>
        <p><?php esc_html_e('La sincronizzazione è avviata da PortalManager: invia le posizioni aperte e preleva le candidature. WordPress non chiama mai PortalManager.', 'pm-ats'); ?></p>
        <table class="form-table" role="presentation">
          <tr><th><?php esc_html_e('URL di base API', 'pm-ats'); ?></th><td><code><?php echo esc_html(rest_url(PM_ATS_Rest::NS)); ?></code><p class="description"><?php esc_html_e('Da inserire in PortalManager › Recruiting › Sito web › Impostazioni (o con il codice di connessione).', 'pm-ats'); ?></p></td></tr>
          <tr><th><?php esc_html_e('Ultimo contatto', 'pm-ats'); ?></th><td><?php echo $last ? esc_html(sprintf(__('%1$s fa da %2$s', 'pm-ats'), human_time_diff((int)$last['at']), $last['ip'])) : '—'; ?></td></tr>
          <?php $fail = PM_ATS_Log::lastAuthFailure(); if ($fail): ?>
          <tr><th><?php esc_html_e('Ultimo accesso rifiutato', 'pm-ats'); ?></th><td><?php echo esc_html(sprintf(__('%1$s fa — HTTP %2$d %3$s', 'pm-ats'), human_time_diff((int)$fail['at']), (int)$fail['status'], (string)$fail['detail'])); ?>
            <p class="description"><?php esc_html_e('Dettaglio completo in Lavora con noi › Registro.', 'pm-ats'); ?></p></td></tr>
          <?php endif; ?>
          <tr><th><?php esc_html_e('Segreto condiviso', 'pm-ats'); ?></th><td>
            <?php if ($sec !== ''): ?>
              <div class="pm-ats-code"><p><strong><?php esc_html_e('Copia ora: non sarà più mostrato.', 'pm-ats'); ?></strong></p>
                <p><?php esc_html_e('Codice di connessione (PortalManager › Configurazione guidata):', 'pm-ats'); ?></p>
                <textarea readonly rows="3" class="large-text code" onclick="this.select()"><?php echo esc_textarea($code); ?></textarea>
                <p><?php esc_html_e('Solo segreto (configurazione manuale, PM_WPATS_SECRET):', 'pm-ats'); ?> <code class="pm-ats-secret"><?php echo esc_html($sec); ?></code></p></div>
            <?php endif; ?>
            <p><?php echo esc_html(['wp-config' => __('Configurato in wp-config.php (PM_ATS_SECRET).', 'pm-ats'), 'database' => __('Configurato (cifrato nel database).', 'pm-ats'), 'none' => __('Non configurato.', 'pm-ats')][$src]); ?>
              <?php if ($src !== 'none'): ?><br><?php esc_html_e('Impronta:', 'pm-ats'); ?> <code><?php echo esc_html(PM_ATS_Settings::fingerprint()); ?></code>
              <span class="description"><?php esc_html_e('deve coincidere con quella in PortalManager › Sito web — Impostazioni (diagnostica)', 'pm-ats'); ?></span><?php endif; ?></p>
            <?php if ($src !== 'wp-config'): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
              <?php wp_nonce_field('pm_ats_rotate'); ?><input type="hidden" name="action" value="pm_ats_rotate">
              <button class="button" onclick="return <?php echo $src === 'none' ? 'true' : "confirm('" . esc_js(__('Il segreto attuale smetterà di funzionare. Continuare?', 'pm-ats')) . "')"; ?>"><?php echo $src === 'none' ? esc_html__('Genera segreto', 'pm-ats') : esc_html__('Rigenera segreto', 'pm-ats'); ?></button>
            </form>
            <?php endif; ?>
          </td></tr>
        </table>
        <?php
    }

    private static function tabMaintenance(array $ob): void
    {
        $hist = array_reverse((array)get_option(PM_ATS_Upgrade::OPT_HISTORY, []));
        $ovr = PM_ATS_Upgrade::templateOverrides();
        $jl = get_option('pm_ats_last_jobs_sync');
        $rows = [
            [__('Plugin', 'pm-ats'), PM_ATS_VERSION], [__('Protocollo API', 'pm-ats'), 'pm-ats/v' . PM_ATS_API_VERSION],
            [__('Schema tabelle', 'pm-ats'), PM_ATS_DB_VERSION . ' (' . __('installato', 'pm-ats') . ' ' . get_option('pm_ats_db_version', '—') . ')'],
            [__('Schema impostazioni', 'pm-ats'), PM_ATS_SETTINGS_VERSION . ' (' . __('installato', 'pm-ats') . ' ' . get_option(PM_ATS_Upgrade::OPT_SETTINGS, '—') . ')'],
            [__('Template', 'pm-ats'), PM_ATS_TEMPLATE_VERSION], [__('PortalManager consigliato', 'pm-ats'), '≥ ' . PM_ATS_MIN_PM],
            ['WordPress / PHP', get_bloginfo('version') . ' / ' . PHP_VERSION],
            [__('Configurazione guidata', 'pm-ats'), $ob['status'] === 'done' ? sprintf(__('completata il %s', 'pm-ats'), $ob['completed_at'] ? wp_date('d/m/Y H:i', $ob['completed_at']) : '—') : sprintf(__('da completare (passo %d)', 'pm-ats'), $ob['step'])],
            [__('Ultimo invio posizioni', 'pm-ats'), $jl ? human_time_diff((int)$jl['at']) . ' fa' : '—'],
        ];
        $nonce = static fn() => wp_nonce_field('pm_ats_maint', '_wpnonce', true, false) . '<input type="hidden" name="action" value="pm_ats_maint">';
        ?>
        <h2><?php esc_html_e('Versioni', 'pm-ats'); ?></h2>
        <table class="widefat striped" style="max-width:720px"><tbody>
          <?php foreach ($rows as [$l, $v]): ?><tr><th style="width:240px"><?php echo esc_html($l); ?></th><td><?php echo esc_html((string)$v); ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2><?php esc_html_e('Storico aggiornamenti', 'pm-ats'); ?></h2>
        <table class="widefat striped" style="max-width:720px"><thead><tr><th><?php esc_html_e('Data', 'pm-ats'); ?></th><th><?php esc_html_e('Da', 'pm-ats'); ?></th><th><?php esc_html_e('A', 'pm-ats'); ?></th></tr></thead><tbody>
          <?php if (!$hist): ?><tr><td colspan="3">—</td></tr><?php endif; ?>
          <?php foreach ($hist as $h): ?><tr><td><?php echo esc_html(wp_date('d/m/Y H:i', (int)$h['at'])); ?></td><td><?php echo esc_html((string)($h['from'] ?? __('nuova installazione', 'pm-ats'))); ?></td><td><?php echo esc_html((string)$h['to']); ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2><?php esc_html_e('Template sovrascritti dal tema', 'pm-ats'); ?></h2>
        <?php if (!$ovr): ?><p><?php esc_html_e('Nessuno: il plugin usa i propri template.', 'pm-ats'); ?></p><?php else: ?>
        <table class="widefat striped" style="max-width:720px"><thead><tr><th>File</th><th><?php esc_html_e('Tema', 'pm-ats'); ?></th><th><?php esc_html_e('Plugin', 'pm-ats'); ?></th><th></th></tr></thead><tbody>
          <?php foreach ($ovr as $o): ?><tr><td><code><?php echo esc_html($o['file']); ?></code></td><td><?php echo esc_html($o['theme_version']); ?></td><td><?php echo esc_html($o['plugin_version']); ?></td>
            <td><?php echo $o['outdated'] ? '<span class="pm-ats-st pm-ats-st-error">' . esc_html__('da aggiornare', 'pm-ats') . '</span>' : '<span class="pm-ats-st pm-ats-st-synced">OK</span>'; ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>

        <h2><?php esc_html_e('Manutenzione', 'pm-ats'); ?></h2>
        <div class="pm-ats-maint">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php echo $nonce(); // phpcs:ignore ?><input type="hidden" name="op" value="export">
            <button class="button"><?php esc_html_e('Esporta impostazioni (JSON, senza segreto)', 'pm-ats'); ?></button></form>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"><?php echo $nonce(); // phpcs:ignore ?><input type="hidden" name="op" value="import">
            <input type="file" name="file" accept="application/json,.json" required> <button class="button"><?php esc_html_e('Importa impostazioni', 'pm-ats'); ?></button></form>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php echo $nonce(); // phpcs:ignore ?><input type="hidden" name="op" value="reset">
            <button class="button" onclick="return confirm('<?php echo esc_js(__('Ripristinare i valori predefiniti? Connessione e pagina restano invariate.', 'pm-ats')); ?>')"><?php esc_html_e('Ripristina valori predefiniti', 'pm-ats'); ?></button></form>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php echo $nonce(); // phpcs:ignore ?><input type="hidden" name="op" value="wizard">
            <button class="button button-primary"><?php esc_html_e('Ripeti la configurazione guidata', 'pm-ats'); ?></button></form>
        </div>
        <p class="description"><?php printf(esc_html__('Registro delle operazioni: %s.', 'pm-ats'), '<a href="' . esc_url(admin_url('admin.php?page=pm-ats-log')) . '">' . esc_html__('Lavora con noi › Registro', 'pm-ats') . '</a>'); ?></p>
        <?php
    }

    public static function pageLog(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Accesso negato.', 'pm-ats'));
        $rows = PM_ATS_Log::recent(200);
        $jl = get_option('pm_ats_last_jobs_sync');
        ?>
        <div class="wrap pm-ats-admin">
          <h1><?php esc_html_e('Registro sincronizzazioni', 'pm-ats'); ?></h1>
          <?php if ($jl): ?><p><?php echo esc_html(sprintf(__('Ultimo invio posizioni: %1$s fa — nuove %2$d, aggiornate %3$d, invariate %4$d, ritirate %5$d.', 'pm-ats'),
              human_time_diff((int)$jl['at']), (int)$jl['created'], (int)$jl['updated'], (int)$jl['unchanged'], (int)$jl['withdrawn'])); ?></p><?php endif; ?>
          <table class="widefat striped"><thead><tr><th><?php esc_html_e('Data (UTC)', 'pm-ats'); ?></th><th><?php esc_html_e('Operazione', 'pm-ats'); ?></th><th>HTTP</th><th>IP</th><th><?php esc_html_e('Dettaglio', 'pm-ats'); ?></th></tr></thead><tbody>
          <?php if (!$rows): ?><tr><td colspan="5"><?php esc_html_e('Nessuna operazione registrata.', 'pm-ats'); ?></td></tr><?php endif; ?>
          <?php foreach ($rows as $r): ?><tr><td><?php echo esc_html($r['created_at']); ?></td><td><?php echo esc_html($r['action']); ?></td><td><?php echo (int)$r['http_status']; ?></td><td><?php echo esc_html($r['ip']); ?></td><td><?php echo esc_html($r['detail']); ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
        <?php
    }
}
