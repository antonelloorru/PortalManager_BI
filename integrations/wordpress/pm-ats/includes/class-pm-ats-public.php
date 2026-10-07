<?php
/**
 * Frontend: shortcode, scheda posizione, modulo di candidatura, stile.
 *
 *   [pm_ats_jobs]                        elenco con filtri (da inserire nella pagina "Lavora con noi")
 *       per_page="12" layout="grid|list" filters="1" spontaneous="1"
 *   [pm_ats_apply job="123"]             modulo di candidatura (senza job: candidatura spontanea)
 *   [pm_ats_count]                       numero di posizioni aperte
 *
 * Template sovrascrivibili dal tema in <tema>/pm-ats/: jobs-list.php, job-card.php, job-single.php, apply-form.php.
 * Il modulo invia a admin-post.php (funziona senza JavaScript) con schema POST → redirect → GET.
 */
defined('ABSPATH') || exit;

final class PM_ATS_Public
{
    private static bool $assets = false;

    public static function init(): void
    {
        add_shortcode('pm_ats_jobs', [self::class, 'scJobs']);
        add_shortcode('pm_ats_apply', [self::class, 'scApply']);
        add_shortcode('pm_ats_count', static fn() => (string)PM_ATS_Jobs::count());
        add_filter('the_content', [self::class, 'singleContent'], 20);
        add_action('wp_head', [self::class, 'head'], 5);
        add_action('template_redirect', [self::class, 'redirectArchive']);
        add_action('template_redirect', [self::class, 'preview'], 0);   // v1.2.0
        add_action('wp_enqueue_scripts', [self::class, 'register']);
        add_action('admin_post_nopriv_pm_ats_apply', [self::class, 'handle']);
        add_action('admin_post_pm_ats_apply', [self::class, 'handle']);
        add_action('rest_api_init', static function (): void {
            register_rest_route(PM_ATS_Rest::NS, '/form-token', ['methods' => 'GET', 'permission_callback' => '__return_true',
                'callback' => static function () { $r = new WP_REST_Response(['token' => self::token()]); $r->header('Cache-Control', 'no-store, max-age=0'); return $r; }]);
        });
    }

    /* ── asset ───────────────────────────────────────────────────────── */

    public static function register(): void
    {
        wp_register_style('pm-ats', PM_ATS_URL . 'assets/pm-ats.css', [], PM_ATS_VERSION);
        wp_register_script('pm-ats', PM_ATS_URL . 'assets/pm-ats.js', [], PM_ATS_VERSION, true);
        if (is_singular(PM_ATS_Jobs::CPT)) self::enqueue();
    }

    public static function enqueue(): void
    {
        if (self::$assets) return;
        self::$assets = true;
        if (!wp_style_is('pm-ats', 'registered')) self::register();
        wp_enqueue_style('pm-ats');
        wp_add_inline_style('pm-ats', self::cssVars());
        wp_enqueue_script('pm-ats');
        wp_localize_script('pm-ats', 'PM_ATS', [
            'tokenUrl' => rest_url(PM_ATS_Rest::NS . '/form-token'),
            'maxBytes' => (int)PM_ATS_Settings::get('cv_max_mb') * 1048576,
            'types'    => explode(',', (string)PM_ATS_Settings::get('cv_types')),
            'i18n'     => ['tooLarge' => __('Il file supera la dimensione massima consentita.', 'pm-ats'),
                           'badType'  => __('Formato non ammesso.', 'pm-ats'),
                           'sending'  => __('Invio in corso…', 'pm-ats')],
        ]);
    }

    public static function cssVars(): string
    {
        $s = PM_ATS_Settings::all();
        $v = ['--pm-ats-primary' => $s['color_primary'], '--pm-ats-on-primary' => $s['color_primary_text'] ?: '#ffffff',
              '--pm-ats-text' => $s['color_text'], '--pm-ats-muted' => $s['color_muted'], '--pm-ats-card' => $s['color_card'],
              '--pm-ats-border' => $s['color_border'], '--pm-ats-radius' => (int)$s['radius'] . 'px', '--pm-ats-font' => $s['font_family']];
        $css = '.pm-ats{';
        foreach ($v as $k => $x) if ($x !== '' && $x !== null) $css .= $k . ':' . $x . ';';
        return $css . '}' . ($s['custom_css'] !== '' ? "\n" . $s['custom_css'] : '');
    }

    /* ── template ────────────────────────────────────────────────────── */

    public static function render(string $name, array $args = []): string
    {
        $file = locate_template('pm-ats/' . $name) ?: PM_ATS_DIR . 'templates/' . $name;
        ob_start();
        (static function (string $__f, array $args): void { extract($args, EXTR_SKIP); include $__f; })($file, $args);
        return (string)ob_get_clean();
    }

    public static function listUrl(): string
    {
        $pid = (int)PM_ATS_Settings::get('list_page_id');
        return $pid && get_post_status($pid) === 'publish' ? (string)get_permalink($pid) : (string)get_post_type_archive_link(PM_ATS_Jobs::CPT);
    }

    public static function redirectArchive(): void
    {
        if (is_post_type_archive(PM_ATS_Jobs::CPT) && (int)PM_ATS_Settings::get('list_page_id')) {
            wp_safe_redirect(self::listUrl(), 301); exit;
        }
        // posizione ritirata: la scheda torna all'elenco invece del 404
        if (is_404()) {
            $slug = get_query_var('name');
            if ($slug && get_query_var('post_type') === PM_ATS_Jobs::CPT) {
                $p = get_page_by_path($slug, OBJECT, PM_ATS_Jobs::CPT);
                if ($p && $p->post_status === 'draft' && get_post_meta($p->ID, '_pm_web_status', true) !== 'draft') { wp_safe_redirect(add_query_arg('pm_ats_closed', 1, self::listUrl()), 302); exit; }
            }
        }
    }

    /**
     * v1.2.0 — Anteprima della scheda (?pm_ats_preview=<token>, generato da PortalManager con POST /sync/preview):
     * stessa resa della pagina pubblicata (template job-single, stili del plugin e del tema, colori), dati non salvati,
     * modulo di candidatura disattivato, noindex. Vale 30 minuti.
     */
    public static function preview(): void
    {
        $tok = isset($_GET['pm_ats_preview']) ? sanitize_key((string)wp_unslash($_GET['pm_ats_preview'])) : '';
        if ($tok === '') return;
        $p = PM_ATS_Jobs::previewData($tok);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        if ($p === null) { status_header(410); wp_die(esc_html__('Anteprima scaduta o non valida: generarne una nuova da PortalManager.', 'pm-ats'), esc_html__('Anteprima', 'pm-ats'), ['response' => 410]); }
        $job = $p['data'];
        $pid = PM_ATS_Jobs::postIdFor((int)$job['id']);
        $state = $pid ? (string)get_post_status($pid) : '';
        $label = ($p['web_status'] ?? '') === 'draft' ? __('bozza (non visibile sul sito)', 'pm-ats')
               : ($state === 'publish' ? __('pubblicata', 'pm-ats') : __('non pubblicata', 'pm-ats'));
        self::register(); self::enqueue();
        add_filter('wp_robots', 'wp_robots_no_robots');
        add_filter('document_title_parts', static fn($t) => ['title' => __('Anteprima', 'pm-ats') . ' — ' . $job['title']] + $t);
        $form = '<div class="pm-ats-form pm-ats-preview-form" id="pm-ats-form"><p><strong>' . esc_html__('Modulo di candidatura', 'pm-ats') . '</strong> — '
              . esc_html__('in anteprima l\'invio è disattivato.', 'pm-ats') . '</p></div>';
        $body = self::render('job-single.php', ['post_id' => $pid, 'job' => $job, 'list_url' => self::listUrl(), 'form' => PM_ATS_Settings::get('auto_form') ? $form : '']);
        status_header(200);
        ?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?>
<style>.pm-ats-preview-bar{position:sticky;top:0;z-index:99999;background:#1e293b;color:#fff;font:13px/1.4 system-ui,sans-serif;padding:8px 16px;display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.pm-ats-preview-bar b{background:#f59e0b;color:#1e293b;border-radius:4px;padding:1px 8px}.pm-ats-preview-main{max-width:960px;margin:0 auto;padding:32px 20px}</style></head>
<body <?php body_class('pm-ats-preview'); ?>><?php if (function_exists('wp_body_open')) wp_body_open(); ?>
<div class="pm-ats-preview-bar"><b><?php esc_html_e('ANTEPRIMA', 'pm-ats'); ?></b><span><?php echo esc_html(sprintf(__('Stato sul sito: %s', 'pm-ats'), $label)); ?></span>
<span><?php echo esc_html($job['code'] ?? ''); ?></span><?php if ($pid && $state === 'publish'): ?><a style="color:#93c5fd" href="<?php echo esc_url(get_permalink($pid)); ?>"><?php esc_html_e('Apri la pagina pubblicata', 'pm-ats'); ?></a><?php endif; ?></div>
<main class="pm-ats-preview-main"><h1 class="pm-ats-preview-title"><?php echo esc_html($job['title']); ?></h1><?php echo $body; // phpcs:ignore ?></main>
<?php wp_footer(); ?></body></html><?php
        exit;
    }

    public static function head(): void
    {
        if (is_singular(PM_ATS_Jobs::CPT)) echo PM_ATS_Jobs::jsonLd((int)get_queried_object_id()) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /* ── shortcode ───────────────────────────────────────────────────── */

    public static function scJobs($atts): string
    {
        self::enqueue();
        $s = PM_ATS_Settings::all();
        $a = shortcode_atts(['per_page' => $s['per_page'], 'layout' => $s['layout'], 'filters' => '1', 'spontaneous' => (string)$s['allow_spontaneous']], (array)$atts, 'pm_ats_jobs');
        $f = [
            'q' => sanitize_text_field(wp_unslash((string)($_GET['pmq'] ?? ''))),
            'location' => sanitize_text_field(wp_unslash((string)($_GET['pml'] ?? ''))),
            'contract_type' => sanitize_text_field(wp_unslash((string)($_GET['pmc'] ?? ''))),
            'department' => sanitize_text_field(wp_unslash((string)($_GET['pmd'] ?? ''))),
            'remote_policy' => sanitize_text_field(wp_unslash((string)($_GET['pmr'] ?? ''))),
        ];
        $page = max(1, (int)($_GET['pmp'] ?? 1));
        $res = PM_ATS_Jobs::query($f, max(1, (int)$a['per_page']), $page);
        $opts = [];
        foreach (['location', 'contract_type', 'department', 'remote_policy'] as $k) $opts[$k] = PM_ATS_Jobs::distinct($k);
        return self::render('jobs-list.php', [
            'jobs' => $res['items'], 'total' => $res['total'], 'pages' => $res['pages'], 'page' => $page,
            'filters' => $f, 'options' => $opts, 'show_filters' => $a['filters'] !== '0',
            'layout' => in_array($a['layout'], ['grid', 'list'], true) ? $a['layout'] : 'grid',
            'spontaneous' => $a['spontaneous'] !== '0' && !empty($s['allow_spontaneous']),
            'closed_notice' => !empty($_GET['pm_ats_closed']),
        ]);
    }

    public static function scApply($atts): string
    {
        self::enqueue();
        $a = shortcode_atts(['job' => '0', 'title' => ''], (array)$atts, 'pm_ats_apply');
        $job = (int)$a['job'];
        if ($job && (get_post_type($job) !== PM_ATS_Jobs::CPT || get_post_status($job) !== 'publish')) return '';
        if (!$job && !PM_ATS_Settings::get('allow_spontaneous')) return '';
        return self::form($job, (string)$a['title']);
    }

    public static function form(int $jobId, string $title = ''): string
    {
        $k = sanitize_key((string)($_GET['pm_ats_k'] ?? ''));
        $old = $k !== '' ? (array)get_transient('pm_ats_old_' . $k) : [];
        $err = sanitize_key((string)($_GET['pm_ats_err'] ?? ''));
        $okRef = sanitize_key((string)($_GET['pm_ats_ok'] ?? ''));
        $forThis = ((int)($_GET['pm_ats_job'] ?? -1)) === $jobId;
        return self::render('apply-form.php', [
            'job_id' => $jobId,
            'title' => $title !== '' ? $title : ($jobId ? get_the_title($jobId) : __('Candidatura spontanea', 'pm-ats')),
            'old' => $forThis ? $old : [], 'error' => $forThis ? $err : '', 'error_field' => $forThis ? sanitize_key((string)($_GET['pm_ats_f'] ?? '')) : '',
            'error_text' => $forThis && $err !== '' ? self::message($err) : '', 'success_ref' => $forThis ? strtoupper($okRef) : '',
            'settings' => PM_ATS_Settings::all(), 'token' => self::token(),
            'action' => admin_url('admin-post.php'),
        ]);
    }

    /** Scheda della posizione: sezioni + modulo, al posto del contenuto (vuoto) del post. */
    public static function singleContent(string $content): string
    {
        if (!is_singular(PM_ATS_Jobs::CPT) || !in_the_loop() || !is_main_query()) return $content;
        $id = (int)get_the_ID();
        self::enqueue();
        return self::render('job-single.php', [
            'post_id' => $id, 'job' => PM_ATS_Jobs::data($id), 'list_url' => self::listUrl(),
            'form' => PM_ATS_Settings::get('auto_form') ? self::form($id) : '',
        ]);
    }

    /* ── invio modulo ────────────────────────────────────────────────── */

    /** Token del modulo: istante di apertura firmato (vale 48 h, minimo min_fill_seconds). */
    public static function token(): string
    {
        $t = (string)time();
        return $t . '.' . substr(hash_hmac('sha256', 'pm-ats-form|' . $t, wp_salt('nonce')), 0, 32);
    }

    public static function tokenValid(string $tok): ?string
    {
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $tok, $m)) return 'expired';
        if (!hash_equals(substr(hash_hmac('sha256', 'pm-ats-form|' . $m[1], wp_salt('nonce')), 0, 32), $m[2])) return 'expired';
        $age = time() - (int)$m[1];
        if ($age > 2 * DAY_IN_SECONDS || $age < 0) return 'expired';
        if ($age < (int)PM_ATS_Settings::get('min_fill_seconds')) return 'too_fast';
        return null;
    }

    public static function handle(): void
    {
        $back = wp_validate_redirect(wp_unslash((string)($_POST['_back'] ?? '')), self::listUrl());
        $back = remove_query_arg(['pm_ats_err', 'pm_ats_ok', 'pm_ats_k', 'pm_ats_f', 'pm_ats_job'], $back);
        $job = (int)($_POST['job_id'] ?? 0);
        $go = static function (array $q) use ($back, $job): void {
            wp_safe_redirect(add_query_arg($q + ['pm_ats_job' => $job], $back) . '#pm-ats-form', 303); exit;
        };
        $fail = static function (string $code, string $field = '') use ($go): void {
            $k = strtolower(wp_generate_password(12, false));
            $keep = array_intersect_key(wp_unslash($_POST), array_flip(['first_name', 'last_name', 'email', 'phone', 'city', 'linkedin_url', 'availability', 'salary_expectation', 'cover_letter', 'consent_marketing']));
            set_transient('pm_ats_old_' . $k, array_map(static fn($v) => is_string($v) ? mb_substr($v, 0, 5000) : '', $keep), 15 * MINUTE_IN_SECONDS);
            $go(['pm_ats_err' => $code, 'pm_ats_k' => $k, 'pm_ats_f' => $field]);
        };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $fail('invalid_request');
        if (!empty($_POST['website'])) $go(['pm_ats_ok' => substr(bin2hex(random_bytes(4)), 0, 8)]); // honeypot: esito finto
        if (($e = self::tokenValid((string)($_POST['_pm_ats_t'] ?? ''))) !== null) $fail($e);
        $r = PM_ATS_Applications::submit($_POST, $_FILES['cv'] ?? null);
        if (!$r['ok']) $fail($r['error'], $r['field'] ?? '');
        $go(['pm_ats_ok' => substr($r['uuid'], 0, 8)]);
    }

    public static function message(string $code): string
    {
        $m = [
            'missing_first_name' => __('Indica il nome.', 'pm-ats'),
            'missing_last_name'  => __('Indica il cognome.', 'pm-ats'),
            'invalid_email'      => __('Indirizzo email non valido.', 'pm-ats'),
            'missing_phone'      => __('Indica un numero di telefono.', 'pm-ats'),
            'invalid_phone'      => __('Numero di telefono non valido.', 'pm-ats'),
            'invalid_linkedin'   => __('Il profilo LinkedIn deve essere un indirizzo https://…linkedin.com/…', 'pm-ats'),
            'privacy_required'   => __('Per inviare la candidatura è necessario accettare l\'informativa privacy.', 'pm-ats'),
            'position_closed'    => __('La posizione non è più disponibile.', 'pm-ats'),
            'rate_limited'       => __('Hai raggiunto il numero massimo di invii per oggi. Riprova domani.', 'pm-ats'),
            'already_applied'    => __('Abbiamo già ricevuto una tua candidatura per questa posizione.', 'pm-ats'),
            'cv_missing'         => __('Allega il tuo CV.', 'pm-ats'),
            'cv_too_large'       => sprintf(__('Il CV supera la dimensione massima di %d MB.', 'pm-ats'), (int)PM_ATS_Settings::get('cv_max_mb')),
            'cv_bad_type'        => sprintf(__('Formato del CV non ammesso. Formati accettati: %s.', 'pm-ats'), strtoupper(str_replace(',', ', ', (string)PM_ATS_Settings::get('cv_types')))),
            'upload_failed'      => __('Caricamento del file non riuscito. Riprova.', 'pm-ats'),
            'too_fast'           => __('Invio troppo rapido. Attendi qualche secondo e riprova.', 'pm-ats'),
            'expired'            => __('Il modulo è scaduto. Ricarica la pagina e riprova.', 'pm-ats'),
        ];
        return $m[$code] ?? __('Si è verificato un errore. Riprova più tardi.', 'pm-ats');
    }

    /** Etichetta leggibile per i valori PortalManager. */
    public static function chips(array $d): array
    {
        $o = [];
        if (!empty($d['location']))      $o['location'] = $d['location'];
        if (!empty($d['remote_policy'])) $o['remote_policy'] = $d['remote_policy'];
        if (!empty($d['contract_type'])) $o['contract_type'] = $d['contract_type'];
        if (!empty($d['department']))    $o['department'] = $d['department'];
        return $o;
    }
}
