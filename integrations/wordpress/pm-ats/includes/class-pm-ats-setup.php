<?php
/**
 * Configurazione guidata (onboarding) — v1.1.0.
 * Si apre all'attivazione del plugin e resta disponibile in «Lavora con noi › Configurazione guidata».
 *   1 Requisiti   PHP, OpenSSL, permalink, HTTPS, cartella upload, API REST
 *   2 Connessione client ID, IP consentiti, origine IP, segreto → codice di connessione per PortalManager (mostrato una volta)
 *   3 Pagina      pagina «Lavora con noi» (esistente o creata con [pm_ats_jobs]), slug, privacy, notifiche
 *   4 Aspetto     colore principale, griglia/lista, posizioni per pagina
 *   5 Verifica    riepilogo, primo contatto da PortalManager, completamento
 * Ogni passo salva solo i propri campi (PM_ATS_Settings::save con elenco chiavi); POST via admin-post con nonce.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Setup
{
    public const PAGE = 'pm-ats-setup';
    public const STEPS = [1 => 'Requisiti', 2 => 'Connessione', 3 => 'Pagina e modulo', 4 => 'Aspetto', 5 => 'Verifica'];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('admin_init', [self::class, 'redirect']);
        add_action('admin_post_pm_ats_setup', [self::class, 'save']);
        add_action('admin_notices', [self::class, 'notice']);
        add_filter('plugin_action_links_' . plugin_basename(PM_ATS_FILE), [self::class, 'links']);
    }

    public static function menu(): void
    {
        add_submenu_page('pm-ats', __('Configurazione guidata', 'pm-ats'), __('Configurazione guidata', 'pm-ats'), 'manage_options', self::PAGE, [self::class, 'page']);
    }

    public static function url(int $step = 0): string
    {
        return admin_url('admin.php?page=' . self::PAGE . ($step ? '&step=' . $step : ''));
    }

    public static function links(array $l): array
    {
        array_unshift($l, '<a href="' . esc_url(admin_url('admin.php?page=pm-ats-settings')) . '">' . esc_html__('Impostazioni', 'pm-ats') . '</a>',
                          '<a href="' . esc_url(self::url()) . '">' . esc_html__('Configurazione guidata', 'pm-ats') . '</a>');
        return $l;
    }

    /** Dopo l'attivazione (non in blocco, non da rete) si apre il wizard. */
    public static function redirect(): void
    {
        if (!get_transient('pm_ats_activation_redirect')) return;
        delete_transient('pm_ats_activation_redirect');
        if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi']) || !current_user_can('manage_options')) return;
        wp_safe_redirect(self::url(1)); exit;
    }

    public static function notice(): void
    {
        if (!current_user_can('manage_options') || PM_ATS_Upgrade::onboarding()['status'] === 'done') return;
        $scr = get_current_screen();
        if ($scr && str_contains((string)$scr->id, self::PAGE)) return;
        echo '<div class="notice notice-info"><p><strong>PortalManager ATS</strong> — ' . esc_html__('configurazione non completata.', 'pm-ats')
           . ' <a class="button button-primary" style="margin-left:8px" href="' . esc_url(self::url()) . '">' . esc_html__('Avvia la configurazione guidata', 'pm-ats') . '</a></p></div>';
    }

    /** @return array<int,array{0:string,1:string,2:string}> [esito ok|warn|ko, requisito, dettaglio] */
    public static function checks(): array
    {
        $up = wp_upload_dir(null, false);
        $c = [];
        $c[] = [version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'ko', 'PHP ≥ 8.0', PHP_VERSION];
        $c[] = [version_compare(get_bloginfo('version'), '6.0', '>=') ? 'ok' : 'ko', 'WordPress ≥ 6.0', get_bloginfo('version')];
        $c[] = [function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true) ? 'ok' : 'ko', 'OpenSSL AES-256-GCM', __('cifratura del segreto', 'pm-ats')];
        $c[] = [function_exists('hash_hmac') ? 'ok' : 'ko', 'HMAC-SHA256', __('firma delle chiamate', 'pm-ats')];
        $c[] = [get_option('permalink_structure') ? 'ok' : 'warn', __('Permalink leggibili', 'pm-ats'), get_option('permalink_structure') ?: __('«Semplice»: le API funzionano con ?rest_route=, consigliato «Nome articolo»', 'pm-ats')];
        $c[] = [is_ssl() || str_starts_with(home_url(), 'https://') ? 'ok' : 'warn', 'HTTPS', home_url('/')];
        $c[] = [wp_is_writable($up['basedir']) ? 'ok' : 'ko', __('Cartella upload scrivibile', 'pm-ats'), __('CV in cartella privata', 'pm-ats')];
        $c[] = [PM_ATS_Settings::secretSource() !== 'none' ? 'ok' : 'warn', __('Segreto condiviso', 'pm-ats'), ['wp-config' => 'wp-config.php', 'database' => __('cifrato nel database', 'pm-ats'), 'none' => __('da generare al passo 2', 'pm-ats')][PM_ATS_Settings::secretSource()]];
        $c[] = ['ok', __('API REST', 'pm-ats'), rest_url(PM_ATS_Rest::NS)];
        return $c;
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer('pm_ats_setup')) wp_die(esc_html__('Accesso negato.', 'pm-ats'), 403);
        $step = max(1, min(5, (int)($_POST['step'] ?? 1)));
        $in = wp_unslash((array)($_POST[PM_ATS_Settings::OPTION] ?? []));
        $next = $step + 1;
        switch ($step) {
            case 1:
                if (in_array('ko', array_column(self::checks(), 0), true) && empty($_POST['force'])) { wp_safe_redirect(add_query_arg('err', 'req', self::url(1))); exit; }
                break;
            case 2:
                PM_ATS_Settings::save($in, ['client_id', 'allowed_ips', 'ip_source']);
                $src = PM_ATS_Settings::secretSource();
                if (!empty($_POST['gen_secret']) && $src !== 'wp-config') {
                    if ($src === 'none' || !empty($_POST['confirm_rotate'])) {
                        $sec = PM_ATS_Settings::rotateSecret();
                        set_transient('pm_ats_show_code_' . get_current_user_id(), PM_ATS_Settings::connectionCode($sec) . '|' . $sec, 300);
                        PM_ATS_Log::add('secret', 200, 'generato dal wizard');
                        $next = 2;   // resta sul passo per copiare il codice
                    }
                } elseif ($src === 'none') { wp_safe_redirect(add_query_arg('err', 'secret', self::url(2))); exit; }
                break;
            case 3:
                $keys = ['list_page_id', 'jobs_slug', 'privacy_url', 'notify_email', 'allow_spontaneous', 'confirm_candidate'];
                if (!empty($_POST['create_page'])) {
                    $title = sanitize_text_field((string)($_POST['page_title'] ?? '')) ?: __('Lavora con noi', 'pm-ats');
                    $pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<!-- wp:shortcode -->[pm_ats_jobs]<!-- /wp:shortcode -->'], true);
                    if (!is_wp_error($pid)) $in['list_page_id'] = (int)$pid;
                }
                PM_ATS_Settings::save($in, $keys);
                break;
            case 4:
                PM_ATS_Settings::save($in, ['color_primary', 'layout', 'per_page']);
                break;
            case 5:
                PM_ATS_Upgrade::setStep(5, true);
                PM_ATS_Log::add('setup', 200, 'configurazione guidata completata');
                wp_safe_redirect(admin_url('admin.php?page=pm-ats-settings&setup=done')); exit;
        }
        PM_ATS_Upgrade::setStep($next);
        wp_safe_redirect(self::url($next)); exit;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Accesso negato.', 'pm-ats'));
        $ob = PM_ATS_Upgrade::onboarding();
        $step = max(1, min(5, (int)($_GET['step'] ?? ($ob['status'] === 'done' ? 1 : $ob['step']))));
        $s = PM_ATS_Settings::all(); $n = PM_ATS_Settings::OPTION;
        $f = static fn(string $k) => esc_attr($n . '[' . $k . ']');
        $err = sanitize_key((string)($_GET['err'] ?? ''));
        ?>
        <div class="wrap pm-ats-admin pm-ats-setup">
          <h1><?php esc_html_e('PortalManager ATS — Configurazione guidata', 'pm-ats'); ?> <span class="pm-ats-ver">v<?php echo esc_html(PM_ATS_VERSION); ?></span></h1>
          <ol class="pm-ats-steps">
            <?php foreach (self::STEPS as $i => $l): ?>
              <li class="<?php echo $i === $step ? 'is-current' : ($i < $step || $ob['status'] === 'done' || $i <= $ob['step'] - 1 ? 'is-done' : ''); ?>">
                <a href="<?php echo esc_url(self::url($i)); ?>"><span><?php echo (int)$i; ?></span> <?php echo esc_html__($l, 'pm-ats'); ?></a></li>
            <?php endforeach; ?>
          </ol>
          <?php if ($err === 'req'): ?><div class="notice notice-error"><p><?php esc_html_e('Alcuni requisiti obbligatori non sono soddisfatti.', 'pm-ats'); ?></p></div><?php endif; ?>
          <?php if ($err === 'secret'): ?><div class="notice notice-error"><p><?php esc_html_e('Generare il segreto condiviso (o definire PM_ATS_SECRET in wp-config.php) prima di proseguire.', 'pm-ats'); ?></p></div><?php endif; ?>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pm-ats-setup-card">
            <?php wp_nonce_field('pm_ats_setup'); ?><input type="hidden" name="action" value="pm_ats_setup"><input type="hidden" name="step" value="<?php echo (int)$step; ?>">
            <?php $m = 'step' . $step; self::$m($s, $f); ?>
          </form>
          <p class="description"><?php printf(esc_html__('Ogni impostazione resta modificabile in %s.', 'pm-ats'), '<a href="' . esc_url(admin_url('admin.php?page=pm-ats-settings')) . '">' . esc_html__('Lavora con noi › Impostazioni', 'pm-ats') . '</a>'); ?></p>
        </div>
        <?php
    }

    private static function nav(int $step, string $label = ''): void
    {
        echo '<p class="pm-ats-setup-nav">';
        if ($step > 1) echo '<a class="button" href="' . esc_url(self::url($step - 1)) . '">&larr; ' . esc_html__('Indietro', 'pm-ats') . '</a> ';
        echo '<button class="button button-primary">' . esc_html($label ?: __('Salva e continua', 'pm-ats')) . ' &rarr;</button></p>';
    }

    private static function step1(array $s, callable $f): void
    {
        $ck = self::checks(); $ico = ['ok' => '✔', 'warn' => '!', 'ko' => '✘'];
        echo '<h2>' . esc_html__('1. Requisiti', 'pm-ats') . '</h2><p>' . esc_html__('Il plugin pubblica le posizioni aperte inviate da PortalManager e raccoglie le candidature con CV. La connessione parte sempre da PortalManager: il sito non chiama mai la rete aziendale.', 'pm-ats') . '</p>';
        echo '<table class="widefat striped pm-ats-checks"><tbody>';
        foreach ($ck as [$st, $l, $d]) echo '<tr><td class="pm-ats-ck-' . esc_attr($st) . '">' . esc_html($ico[$st]) . '</td><td><strong>' . esc_html($l) . '</strong></td><td>' . esc_html($d) . '</td></tr>';
        echo '</tbody></table>';
        if (in_array('ko', array_column($ck, 0), true)) echo '<p><label><input type="checkbox" name="force" value="1"> ' . esc_html__('Prosegui comunque (sconsigliato)', 'pm-ats') . '</label></p>';
        self::nav(1, __('Continua', 'pm-ats'));
    }

    private static function step2(array $s, callable $f): void
    {
        $src = PM_ATS_Settings::secretSource();
        $show = get_transient('pm_ats_show_code_' . get_current_user_id());
        if ($show) delete_transient('pm_ats_show_code_' . get_current_user_id());
        [$code, $sec] = $show ? explode('|', (string)$show, 2) + [1 => ''] : ['', ''];
        echo '<h2>' . esc_html__('2. Connessione con PortalManager', 'pm-ats') . '</h2>';
        if ($code !== '') {
            echo '<div class="pm-ats-code"><p><strong>' . esc_html__('Codice di connessione — copialo ora, non sarà più mostrato.', 'pm-ats') . '</strong></p>'
               . '<textarea readonly rows="3" class="large-text code" onclick="this.select()">' . esc_textarea($code) . '</textarea>'
               . '<p class="description">' . esc_html__('In PortalManager: Recruiting › Sito web › Configurazione guidata › incolla il codice. Contiene URL, client ID e segreto.', 'pm-ats') . '</p>'
               . '<details><summary>' . esc_html__('Valori separati (configurazione manuale)', 'pm-ats') . '</summary><p>URL API: <code>' . esc_html(rest_url(PM_ATS_Rest::NS)) . '</code><br>Client ID: <code>' . esc_html($s['client_id']) . '</code><br>'
               . esc_html__('Segreto', 'pm-ats') . ': <code class="pm-ats-secret">' . esc_html($sec) . '</code></p></details></div>';
        }
        ?>
        <table class="form-table" role="presentation">
          <tr><th><?php esc_html_e('URL API', 'pm-ats'); ?></th><td><code><?php echo esc_html(rest_url(PM_ATS_Rest::NS)); ?></code></td></tr>
          <tr><th><?php esc_html_e('Client ID', 'pm-ats'); ?></th><td><input type="text" name="<?php echo $f('client_id'); ?>" value="<?php echo esc_attr($s['client_id']); ?>" class="regular-text">
            <p class="description"><?php esc_html_e('Identifica PortalManager; deve coincidere nei due sistemi (il codice di connessione lo riporta).', 'pm-ats'); ?></p></td></tr>
          <tr><th><?php esc_html_e('IP consentiti', 'pm-ats'); ?></th><td><input type="text" name="<?php echo $f('allowed_ips'); ?>" value="<?php echo esc_attr($s['allowed_ips']); ?>" class="regular-text" placeholder="203.0.113.10">
            <p class="description"><?php esc_html_e('IP pubblico di uscita di PortalManager (o CIDR). Vuoto = nessuna restrizione (sconsigliato in produzione).', 'pm-ats'); ?></p></td></tr>
          <tr><th><?php esc_html_e('Origine IP client', 'pm-ats'); ?></th><td><select name="<?php echo $f('ip_source'); ?>">
            <?php foreach (['REMOTE_ADDR' => 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP'] as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($s['ip_source'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></td></tr>
          <tr><th><?php esc_html_e('Segreto condiviso', 'pm-ats'); ?></th><td>
            <?php if ($src === 'wp-config'): ?>
              <p><?php esc_html_e('Definito in wp-config.php (PM_ATS_SECRET): inserirlo in PortalManager manualmente.', 'pm-ats'); ?></p>
            <?php else: ?>
              <p><?php echo esc_html($src === 'database' ? __('Già generato (cifrato nel database).', 'pm-ats') : __('Non ancora generato.', 'pm-ats')); ?></p>
              <label><input type="checkbox" name="gen_secret" value="1" <?php checked($src === 'none'); ?>> <?php echo esc_html($src === 'none' ? __('Genera il segreto e il codice di connessione', 'pm-ats') : __('Rigenera il segreto e il codice di connessione', 'pm-ats')); ?></label>
              <?php if ($src === 'database'): ?><br><label><input type="checkbox" name="confirm_rotate" value="1"> <?php esc_html_e('Confermo: il segreto attuale smetterà di funzionare finché PortalManager non riceve il nuovo', 'pm-ats'); ?></label><?php endif; ?>
            <?php endif; ?></td></tr>
        </table>
        <?php
        self::nav(2);
    }

    private static function step3(array $s, callable $f): void
    {
        ?>
        <h2><?php esc_html_e('3. Pagina e modulo', 'pm-ats'); ?></h2>
        <table class="form-table" role="presentation">
          <tr><th><?php esc_html_e('Pagina «Lavora con noi»', 'pm-ats'); ?></th><td>
            <?php wp_dropdown_pages(['name' => PM_ATS_Settings::OPTION . '[list_page_id]', 'selected' => (int)$s['list_page_id'], 'show_option_none' => __('— archivio del plugin —', 'pm-ats'), 'option_none_value' => '0']); // phpcs:ignore ?>
            <p><label><input type="checkbox" name="create_page" value="1" <?php checked((int)$s['list_page_id'] === 0); ?>> <?php esc_html_e('Crea una nuova pagina con lo shortcode [pm_ats_jobs]:', 'pm-ats'); ?></label>
               <input type="text" name="page_title" value="<?php esc_attr_e('Lavora con noi', 'pm-ats'); ?>" class="regular-text"></p></td></tr>
          <tr><th><?php esc_html_e('Slug delle posizioni', 'pm-ats'); ?></th><td><input type="text" name="<?php echo $f('jobs_slug'); ?>" value="<?php echo esc_attr($s['jobs_slug']); ?>" class="regular-text"></td></tr>
          <tr><th><?php esc_html_e('Informativa privacy (URL)', 'pm-ats'); ?></th><td><input type="url" name="<?php echo $f('privacy_url'); ?>" value="<?php echo esc_attr($s['privacy_url'] ?: get_privacy_policy_url()); ?>" class="regular-text"></td></tr>
          <tr><th><?php esc_html_e('Email notifiche HR', 'pm-ats'); ?></th><td><input type="email" name="<?php echo $f('notify_email'); ?>" value="<?php echo esc_attr($s['notify_email']); ?>" class="regular-text" placeholder="hr@azienda.it"></td></tr>
          <tr><th><?php esc_html_e('Opzioni', 'pm-ats'); ?></th><td>
            <label><input type="checkbox" name="<?php echo $f('allow_spontaneous'); ?>" value="1" <?php checked(!empty($s['allow_spontaneous'])); ?>> <?php esc_html_e('Candidatura spontanea', 'pm-ats'); ?></label><br>
            <label><input type="checkbox" name="<?php echo $f('confirm_candidate'); ?>" value="1" <?php checked(!empty($s['confirm_candidate'])); ?>> <?php esc_html_e('Email di conferma al candidato', 'pm-ats'); ?></label></td></tr>
        </table>
        <?php
        self::nav(3);
    }

    private static function step4(array $s, callable $f): void
    {
        ?>
        <h2><?php esc_html_e('4. Aspetto', 'pm-ats'); ?></h2>
        <p><?php esc_html_e('Testo e font sono ereditati dal tema; il resto è nella scheda «Aspetto» delle Impostazioni.', 'pm-ats'); ?></p>
        <table class="form-table" role="presentation">
          <tr><th><?php esc_html_e('Colore principale', 'pm-ats'); ?></th><td><input type="text" class="pm-ats-color" name="<?php echo $f('color_primary'); ?>" value="<?php echo esc_attr($s['color_primary']); ?>"></td></tr>
          <tr><th><?php esc_html_e('Elenco', 'pm-ats'); ?></th><td><select name="<?php echo $f('layout'); ?>"><option value="grid" <?php selected($s['layout'], 'grid'); ?>><?php esc_html_e('Griglia di schede', 'pm-ats'); ?></option><option value="list" <?php selected($s['layout'], 'list'); ?>><?php esc_html_e('Lista', 'pm-ats'); ?></option><option value="accordion" <?php selected($s['layout'], 'accordion'); ?>><?php esc_html_e('Lavora con noi: elenco posizioni e modulo a destra', 'pm-ats'); ?></option></select>
            <input type="number" min="1" max="100" name="<?php echo $f('per_page'); ?>" value="<?php echo (int)$s['per_page']; ?>" class="small-text"> <?php esc_html_e('per pagina', 'pm-ats'); ?></td></tr>
        </table>
        <?php
        self::nav(4);
    }

    private static function step5(array $s, callable $f): void
    {
        $last = get_option('pm_ats_last_contact');
        $page = (int)$s['list_page_id'];
        $rows = [
            [__('Segreto condiviso', 'pm-ats'), PM_ATS_Settings::secretSource() !== 'none'],
            [__('IP consentiti', 'pm-ats'), $s['allowed_ips'] !== ''],
            [__('Pagina «Lavora con noi»', 'pm-ats'), $page > 0 && get_post_status($page) === 'publish'],
            [__('Informativa privacy', 'pm-ats'), $s['privacy_url'] !== ''],
            [__('Primo contatto da PortalManager', 'pm-ats'), (bool)$last],
        ];
        echo '<h2>' . esc_html__('5. Verifica', 'pm-ats') . '</h2><table class="widefat striped pm-ats-checks"><tbody>';
        foreach ($rows as [$l, $ok]) echo '<tr><td class="pm-ats-ck-' . ($ok ? 'ok' : 'warn') . '">' . ($ok ? '✔' : '!') . '</td><td>' . esc_html($l) . '</td></tr>';
        echo '</tbody></table>';
        if ($last) echo '<p>' . esc_html(sprintf(__('Ultimo contatto: %1$s fa da %2$s (%3$s).', 'pm-ats'), human_time_diff((int)$last['at']), $last['ip'], $last['route'])) . '</p>';
        else echo '<p>' . esc_html__('In PortalManager: Recruiting › Sito web › Configurazione guidata → incollare il codice ed eseguire «Test connessione». Poi aggiornare questa pagina.', 'pm-ats')
             . ' <a class="button" href="' . esc_url(self::url(5)) . '">' . esc_html__('Aggiorna', 'pm-ats') . '</a></p>';
        if ($page > 0) echo '<p><a href="' . esc_url(get_permalink($page)) . '" target="_blank" rel="noopener">' . esc_html__('Apri la pagina pubblica', 'pm-ats') . ' ↗</a></p>';
        self::nav(5, __('Completa la configurazione', 'pm-ats'));
    }
}
