<?php
/**
 * Amministrazione WordPress: menu «Lavora con noi» → Candidature, Posizioni, Impostazioni, Registro.
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
        add_action('admin_post_pm_ats_app', [self::class, 'appAction']);
        add_filter('manage_' . PM_ATS_Jobs::CPT . '_posts_columns', [self::class, 'cols']);
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
        if (str_contains($hook, 'pm-ats-settings')) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".pm-ats-color").wpColorPicker();});');
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
        if (($_POST['op'] ?? '') === 'delete') { PM_ATS_Settings::deleteSecret(); $q = 'deleted'; }
        else { set_transient('pm_ats_show_secret_' . get_current_user_id(), PM_ATS_Settings::rotateSecret(), 120); $q = 'rotated'; }
        wp_safe_redirect(admin_url('admin.php?page=pm-ats-settings&secret=' . $q . '#pm-ats-conn')); exit;
    }

    public static function pageSettings(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Accesso negato.', 'pm-ats'));
        $s = PM_ATS_Settings::all(); $n = PM_ATS_Settings::OPTION;
        $show = get_transient('pm_ats_show_secret_' . get_current_user_id());
        if ($show) delete_transient('pm_ats_show_secret_' . get_current_user_id());
        $src = PM_ATS_Settings::secretSource();
        $f = static fn(string $k) => esc_attr($n . '[' . $k . ']');
        $txt = static function (string $k, string $type = 'text', string $extra = '') use ($s, $f): void {
            printf('<input type="%s" name="%s" value="%s" class="regular-text" %s>', esc_attr($type), $f($k), esc_attr((string)$s[$k]), $extra); // phpcs:ignore
        };
        $chk = static function (string $k, string $label) use ($s, $f): void {
            printf('<label><input type="checkbox" name="%s" value="1" %s> %s</label>', $f($k), checked(!empty($s[$k]), true, false), esc_html($label)); // phpcs:ignore
        };
        $base = rest_url(PM_ATS_Rest::NS);
        ?>
        <div class="wrap pm-ats-admin">
          <h1><?php esc_html_e('Lavora con noi — Impostazioni', 'pm-ats'); ?></h1>

          <h2 id="pm-ats-conn"><?php esc_html_e('Connessione con PortalManager', 'pm-ats'); ?></h2>
          <p><?php esc_html_e('La sincronizzazione è avviata da PortalManager: invia le posizioni aperte e preleva le candidature. WordPress non chiama mai PortalManager.', 'pm-ats'); ?></p>
          <table class="form-table" role="presentation">
            <tr><th><?php esc_html_e('URL di base API', 'pm-ats'); ?></th><td><code><?php echo esc_html($base); ?></code><p class="description"><?php esc_html_e('Da inserire in PortalManager › Recruiting › Sito web (WordPress).', 'pm-ats'); ?></p></td></tr>
            <tr><th><?php esc_html_e('Segreto condiviso', 'pm-ats'); ?></th><td>
              <?php if ($show): ?>
                <p><strong><?php esc_html_e('Copia ora il segreto: non sarà più mostrato.', 'pm-ats'); ?></strong></p>
                <p><code class="pm-ats-secret"><?php echo esc_html($show); ?></code></p>
                <p class="description"><?php esc_html_e('In PortalManager va in .env.php: PM_WPATS_SECRET. In alternativa, qui, in wp-config.php: define(\'PM_ATS_SECRET\', \'…\');', 'pm-ats'); ?></p>
              <?php endif; ?>
              <p><?php echo esc_html(['wp-config' => __('Configurato in wp-config.php (PM_ATS_SECRET).', 'pm-ats'), 'database' => __('Configurato (cifrato nel database).', 'pm-ats'), 'none' => __('Non configurato.', 'pm-ats')][$src]); ?></p>
              <?php if ($src !== 'wp-config'): ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <?php wp_nonce_field('pm_ats_rotate'); ?><input type="hidden" name="action" value="pm_ats_rotate">
                <button class="button" onclick="return <?php echo $src === 'none' ? 'true' : "confirm('" . esc_js(__('Il segreto attuale smetterà di funzionare. Continuare?', 'pm-ats')) . "')"; ?>"><?php echo $src === 'none' ? esc_html__('Genera segreto', 'pm-ats') : esc_html__('Rigenera segreto', 'pm-ats'); ?></button>
              </form>
              <?php endif; ?>
            </td></tr>
          </table>

          <form method="post" action="options.php">
            <?php settings_fields('pm_ats'); ?>
            <table class="form-table" role="presentation">
              <tr><th><?php esc_html_e('Client ID', 'pm-ats'); ?></th><td><?php $txt('client_id'); ?><p class="description"><?php esc_html_e('Deve coincidere con quello configurato in PortalManager.', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('IP consentiti', 'pm-ats'); ?></th><td><?php $txt('allowed_ips', 'text', 'placeholder="203.0.113.10, 2001:db8::/48"'); ?><p class="description"><?php esc_html_e('IP pubblico (o CIDR) da cui esce PortalManager. Vuoto = nessuna restrizione.', 'pm-ats'); ?></p></td></tr>
              <tr><th><?php esc_html_e('Origine IP client', 'pm-ats'); ?></th><td><select name="<?php echo $f('ip_source'); ?>">
                <?php foreach (['REMOTE_ADDR' => 'REMOTE_ADDR (diretto)', 'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For (proxy)', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)'] as $k => $l): ?>
                  <option value="<?php echo esc_attr($k); ?>" <?php selected($s['ip_source'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
                <p class="description"><?php esc_html_e('Usare un\'intestazione proxy solo se il sito è dietro un proxy affidabile.', 'pm-ats'); ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('Pagina e modulo', 'pm-ats'); ?></h2>
            <table class="form-table" role="presentation">
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
            </table>

            <h2><?php esc_html_e('Aspetto', 'pm-ats'); ?></h2>
            <p class="description"><?php esc_html_e('Lasciare vuoti testo e font per ereditarli dal tema del sito.', 'pm-ats'); ?></p>
            <table class="form-table" role="presentation">
              <?php foreach (['color_primary' => __('Colore principale', 'pm-ats'), 'color_primary_text' => __('Testo sui pulsanti', 'pm-ats'), 'color_text' => __('Testo', 'pm-ats'),
                              'color_muted' => __('Testo secondario', 'pm-ats'), 'color_card' => __('Sfondo schede', 'pm-ats'), 'color_border' => __('Bordi', 'pm-ats')] as $k => $l): ?>
                <tr><th><?php echo esc_html($l); ?></th><td><input type="text" class="pm-ats-color" name="<?php echo $f($k); ?>" value="<?php echo esc_attr($s[$k]); ?>"></td></tr>
              <?php endforeach; ?>
              <tr><th><?php esc_html_e('Arrotondamento', 'pm-ats'); ?></th><td><input type="number" min="0" max="30" name="<?php echo $f('radius'); ?>" value="<?php echo (int)$s['radius']; ?>" class="small-text"> px</td></tr>
              <tr><th><?php esc_html_e('Font', 'pm-ats'); ?></th><td><?php $txt('font_family', 'text', 'placeholder="es. Poppins, sans-serif"'); ?></td></tr>
              <tr><th><?php esc_html_e('Elenco', 'pm-ats'); ?></th><td><select name="<?php echo $f('layout'); ?>"><option value="grid" <?php selected($s['layout'], 'grid'); ?>><?php esc_html_e('Griglia di schede', 'pm-ats'); ?></option><option value="list" <?php selected($s['layout'], 'list'); ?>><?php esc_html_e('Lista', 'pm-ats'); ?></option></select>
                <input type="number" min="1" max="100" name="<?php echo $f('per_page'); ?>" value="<?php echo (int)$s['per_page']; ?>" class="small-text"> <?php esc_html_e('per pagina', 'pm-ats'); ?></td></tr>
              <tr><th><?php esc_html_e('CSS aggiuntivo', 'pm-ats'); ?></th><td><textarea name="<?php echo $f('custom_css'); ?>" rows="5" class="large-text code"><?php echo esc_textarea($s['custom_css']); ?></textarea></td></tr>
            </table>

            <h2><?php esc_html_e('Dati e conservazione', 'pm-ats'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th><?php esc_html_e('Azienda (dati strutturati)', 'pm-ats'); ?></th><td><?php $txt('company_name', 'text', 'placeholder="' . esc_attr(get_bloginfo('name')) . '"'); ?><br><?php $txt('company_logo', 'url', 'placeholder="https://…/logo.png"'); ?></td></tr>
              <tr><th><?php esc_html_e('Dopo l\'import', 'pm-ats'); ?></th><td><?php $chk('purge_after_ack', __('Elimina dal sito CV e dati non necessari quando PortalManager conferma l\'import', 'pm-ats')); ?></td></tr>
              <tr><th><?php esc_html_e('Conservazione', 'pm-ats'); ?></th><td>
                <input type="number" min="1" max="3650" name="<?php echo $f('retention_synced'); ?>" value="<?php echo (int)$s['retention_synced']; ?>" class="small-text"> <?php esc_html_e('giorni per le candidature importate', 'pm-ats'); ?><br>
                <input type="number" min="7" max="3650" name="<?php echo $f('retention_pending'); ?>" value="<?php echo (int)$s['retention_pending']; ?>" class="small-text"> <?php esc_html_e('giorni per quelle mai importate', 'pm-ats'); ?></td></tr>
              <tr><th><?php esc_html_e('Disinstallazione', 'pm-ats'); ?></th><td><?php $chk('remove_on_uninstall', __('Elimina tabelle, CV, posizioni e impostazioni alla disinstallazione', 'pm-ats')); ?></td></tr>
            </table>
            <?php submit_button(); ?>
          </form>

          <h2><?php esc_html_e('Shortcode', 'pm-ats'); ?></h2>
          <p><code>[pm_ats_jobs]</code> <code>[pm_ats_jobs layout="list" per_page="20" filters="0" spontaneous="0"]</code> <code>[pm_ats_apply]</code> <code>[pm_ats_apply job="ID"]</code> <code>[pm_ats_count]</code></p>
          <p class="description"><?php esc_html_e('Template personalizzabili copiando i file di templates/ in wp-content/themes/<tema>/pm-ats/.', 'pm-ats'); ?></p>
        </div>
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
