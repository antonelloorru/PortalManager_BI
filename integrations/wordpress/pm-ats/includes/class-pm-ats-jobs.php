<?php
/**
 * Posizioni aperte: custom post type `pm_job`, alimentato SOLO da PortalManager (POST /sync/jobs).
 * In WordPress le posizioni non si creano a mano: le modifiche si fanno in PortalManager e arrivano col push.
 *
 * Meta: _pm_id (id job_positions), _pm_hash (impronta del contenuto ricevuto), _pm_data (array normalizzato).
 */
defined('ABSPATH') || exit;

final class PM_ATS_Jobs
{
    public const CPT = 'pm_job';

    /** Sezioni testuali nell'ordine di visualizzazione: chiave PortalManager => titolo. */
    public const SECTIONS = [
        'presentation_text' => 'Chi siamo',
        'description'       => 'La posizione',
        'required_skills'   => 'Requisiti',
        'hard_skills'       => 'Competenze tecniche',
        'soft_skills'       => 'Competenze trasversali',
        'nice_to_have'      => 'Costituisce titolo preferenziale',
        'we_offer'          => 'Cosa offriamo',
        'benefits'          => 'Benefit',
        'offer_info'        => 'Informazioni sull\'offerta',
        'gender_disclaimer' => '',
    ];
    public const META_FIELDS = ['department', 'location', 'contract_type', 'remote_policy', 'opened_at', 'target_date', 'positions_expected', 'code'];

    public static function init(): void
    {
        add_action('init', [self::class, 'register']);
    }

    public static function register(): void
    {
        $slug = (string)PM_ATS_Settings::get('jobs_slug');
        register_post_type(self::CPT, [
            'labels' => [
                'name' => __('Posizioni aperte', 'pm-ats'), 'singular_name' => __('Posizione', 'pm-ats'),
                'all_items' => __('Posizioni', 'pm-ats'), 'edit_item' => __('Posizione', 'pm-ats'),
                'view_item' => __('Vedi posizione', 'pm-ats'), 'search_items' => __('Cerca posizioni', 'pm-ats'),
                'not_found' => __('Nessuna posizione', 'pm-ats'),
            ],
            'public' => true, 'show_ui' => true, 'show_in_menu' => 'pm-ats', 'show_in_rest' => false,
            'has_archive' => $slug, 'rewrite' => ['slug' => $slug, 'with_front' => false],
            'supports' => ['title', 'excerpt'], 'menu_icon' => 'dashicons-id',
            'capability_type' => 'post', 'map_meta_cap' => true,
            'capabilities' => ['create_posts' => 'do_not_allow'],
        ]);
        // temi a blocchi: scheda posizione con intestazione e piè di pagina del tema, senza autore e "altri articoli"
        if (function_exists('register_block_template') && function_exists('wp_is_block_theme') && wp_is_block_theme()) {
            register_block_template('pm-ats//single-' . self::CPT, [
                'title' => __('Posizione aperta', 'pm-ats'), 'description' => __('Scheda della posizione con modulo di candidatura (PortalManager ATS).', 'pm-ats'),
                'post_types' => [self::CPT],
                'content' => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
                    . '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}}} --><main class="wp-block-group">'
                    . '<!-- wp:post-title {"level":1} /--><!-- wp:post-content {"layout":{"type":"constrained"}} /--></main><!-- /wp:group -->'
                    . '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
            ]);
        }
        if (get_option('pm_ats_flush_rewrite')) { delete_option('pm_ats_flush_rewrite'); flush_rewrite_rules(false); }
    }

    /* ── ricezione da PortalManager ─────────────────────────────────── */

    /**
     * @param array $items  posizioni (campi job_positions)
     * @param bool  $full   true = elenco completo delle aperte: quelle assenti vengono ritirate
     * @param int[] $closed id PortalManager da ritirare esplicitamente
     */
    public static function sync(array $items, bool $full, array $closed = []): array
    {
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'errors' => [], 'map' => []];
        $seen = [];
        foreach ($items as $raw) {
            $d = self::normalize(is_array($raw) ? $raw : []);
            if ($d === null) { $out['errors'][] = ['id' => $raw['id'] ?? null, 'error' => 'invalid_item']; continue; }
            $seen[] = $d['id'];
            $hash = hash('sha256', wp_json_encode($d));
            $pid = self::postIdFor($d['id']);
            if ($pid && get_post_meta($pid, '_pm_hash', true) === $hash && get_post_status($pid) === 'publish') {
                $out['unchanged']++; $out['map'][] = ['id' => $d['id'], 'post_id' => $pid, 'url' => get_permalink($pid)]; continue;
            }
            // estratto: il testo specifico della posizione prima della presentazione aziendale (uguale per tutte)
            $plain = '';
            foreach (['description', 'required_skills', 'hard_skills', 'offer_info', 'we_offer', 'presentation_text'] as $k) if ($d[$k] !== '') { $plain = $d[$k]; break; }
            $plain = trim(preg_replace('/^\s*(?:[-*•·▪◦]|\d+[.)])\s+/mu', '', wp_strip_all_tags($plain)));
            $post = [
                'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $d['title'],
                'post_content' => '', 'post_excerpt' => mb_substr(preg_replace('/\s+/', ' ', $plain), 0, 280),
                'comment_status' => 'closed', 'ping_status' => 'closed',
            ];
            if ($d['opened_at']) $post['post_date'] = $d['opened_at'] . ' 09:00:00';
            if ($pid) { $post['ID'] = $pid; $r = wp_update_post(wp_slash($post), true); }
            else { $post['post_name'] = sanitize_title($d['title'] . '-' . $d['id']); $r = wp_insert_post(wp_slash($post), true); }
            if (is_wp_error($r)) { $out['errors'][] = ['id' => $d['id'], 'error' => $r->get_error_code()]; continue; }
            $pid ? $out['updated']++ : $out['created']++;
            $pid = (int)$r;
            update_post_meta($pid, '_pm_id', $d['id']);
            update_post_meta($pid, '_pm_hash', $hash);
            update_post_meta($pid, '_pm_data', wp_slash($d));
            foreach (self::META_FIELDS as $k) update_post_meta($pid, '_pm_' . $k, wp_slash((string)$d[$k]));
            $out['map'][] = ['id' => $d['id'], 'post_id' => $pid, 'url' => get_permalink($pid)];
        }
        $toClose = array_map('intval', $closed);
        if ($full) {
            foreach (self::allPmIds() as $pmId => $pid) if (!in_array($pmId, $seen, true)) $toClose[] = $pmId;
        }
        foreach (array_unique($toClose) as $pmId) {
            $pid = self::postIdFor($pmId);
            if ($pid && get_post_status($pid) === 'publish') { wp_update_post(['ID' => $pid, 'post_status' => 'draft']); $out['withdrawn']++; }
        }
        update_option('pm_ats_last_jobs_sync', ['at' => time()] + array_diff_key($out, ['map' => 1]), false);
        return $out;
    }

    /** Normalizza e valida una posizione ricevuta. Testo semplice: nessun HTML accettato. */
    public static function normalize(array $r): ?array
    {
        $id = (int)($r['id'] ?? 0);
        $title = trim(wp_strip_all_tags((string)($r['title'] ?? '')));
        if ($id <= 0 || $title === '') return null;
        $txt = static fn($v, int $max = 20000) => mb_substr(trim(str_replace("\r\n", "\n", wp_strip_all_tags((string)$v))), 0, $max);
        $date = static fn($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', substr($v, 0, 10))) ? substr($v, 0, 10) : '';
        $d = ['id' => $id, 'title' => mb_substr($title, 0, 200)];
        foreach (array_keys(self::SECTIONS) as $k) $d[$k] = $txt($r[$k] ?? '');
        $d['department']    = $txt($r['department'] ?? '', 100);
        $d['location']      = $txt($r['location'] ?? '', 100);
        $d['contract_type'] = $txt($r['contract_type'] ?? '', 40);
        $d['remote_policy'] = $txt($r['remote_policy'] ?? '', 40);
        $d['code']          = $txt($r['code'] ?? ('POS-' . $id), 60);
        $d['opened_at']     = $date($r['opened_at'] ?? '');
        $d['target_date']   = $date($r['target_date'] ?? '');
        $d['positions_expected'] = max(1, (int)($r['positions_expected'] ?? 1));
        return $d;
    }

    public static function postIdFor(int $pmId): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_pm_id'
              WHERE p.post_type = %s AND m.meta_value = %d ORDER BY p.ID LIMIT 1", self::CPT, $pmId));
    }

    /** @return array<int,int> pm_id => post_id per le posizioni pubblicate */
    public static function allPmIds(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_value pm, p.ID pid FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_pm_id'
              WHERE p.post_type = %s AND p.post_status = 'publish'", self::CPT), ARRAY_A);
        $o = []; foreach ((array)$rows as $r) $o[(int)$r['pm']] = (int)$r['pid'];
        return $o;
    }

    /* ── lettura per il frontend ────────────────────────────────────── */

    public static function data(int $postId): array
    {
        $d = get_post_meta($postId, '_pm_data', true);
        return is_array($d) ? $d : [];
    }

    /** @return array{items:WP_Post[], total:int, pages:int} */
    public static function query(array $f, int $perPage = 20, int $page = 1): array
    {
        $meta = [];
        foreach (['location', 'contract_type', 'department', 'remote_policy'] as $k)
            if (!empty($f[$k])) $meta[] = ['key' => '_pm_' . $k, 'value' => (string)$f[$k]];
        $q = new WP_Query([
            'post_type' => self::CPT, 'post_status' => 'publish', 's' => (string)($f['q'] ?? ''),
            'posts_per_page' => $perPage, 'paged' => max(1, $page), 'meta_query' => $meta ?: null,
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'], 'no_found_rows' => false,
        ]);
        return ['items' => $q->posts, 'total' => (int)$q->found_posts, 'pages' => (int)$q->max_num_pages];
    }

    /** Valori distinti di un campo fra le posizioni pubblicate (per i filtri). */
    public static function distinct(string $k): array
    {
        global $wpdb;
        if (!in_array($k, ['location', 'contract_type', 'department', 'remote_policy'], true)) return [];
        $v = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
              WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish' AND m.meta_value <> '' ORDER BY m.meta_value",
            '_pm_' . $k, self::CPT));
        return array_values(array_filter((array)$v, 'strlen'));
    }

    public static function count(): int
    {
        $c = wp_count_posts(self::CPT);
        return (int)($c->publish ?? 0);
    }

    /**
     * Testo semplice → HTML sicuro: paragrafi, elenchi per righe che iniziano con -, *, •, ·, numero.
     */
    public static function format(string $t): string
    {
        $t = trim($t);
        if ($t === '') return '';
        $html = ''; $list = [];
        $flush = static function () use (&$list, &$html): void {
            if ($list) { $html .= '<ul>' . implode('', array_map(fn($x) => '<li>' . esc_html($x) . '</li>', $list)) . '</ul>'; $list = []; }
        };
        $para = [];
        foreach (preg_split('/\n/', $t) as $line) {
            $l = trim($line);
            if (preg_match('/^(?:[-*•·▪◦]|\d+[.)])\s+(.+)$/u', $l, $m)) {
                if ($para) { $html .= '<p>' . implode('<br>', array_map('esc_html', $para)) . '</p>'; $para = []; }
                $list[] = $m[1]; continue;
            }
            $flush();
            if ($l === '') { if ($para) { $html .= '<p>' . implode('<br>', array_map('esc_html', $para)) . '</p>'; $para = []; } continue; }
            $para[] = $l;
        }
        $flush();
        if ($para) $html .= '<p>' . implode('<br>', array_map('esc_html', $para)) . '</p>';
        return $html;
    }

    /** Dati strutturati schema.org/JobPosting (Google for Jobs). */
    public static function jsonLd(int $postId): string
    {
        $d = self::data($postId);
        if (!$d) return '';
        $desc = '';
        foreach (self::SECTIONS as $k => $h) if (($d[$k] ?? '') !== '') $desc .= ($h ? '<h3>' . esc_html($h) . '</h3>' : '') . self::format($d[$k]);
        $emp = ['Indeterminato' => 'FULL_TIME', 'Determinato' => 'TEMPORARY', 'Somministrazione' => 'TEMPORARY',
                'Consulenza' => 'CONTRACTOR', 'Stage' => 'INTERN'][$d['contract_type'] ?? ''] ?? 'OTHER';
        $org = array_filter(['@type' => 'Organization', 'name' => PM_ATS_Settings::get('company_name') ?: get_bloginfo('name'),
                             'sameAs' => home_url('/'), 'logo' => PM_ATS_Settings::get('company_logo') ?: null]);
        $ld = [
            '@context' => 'https://schema.org/', '@type' => 'JobPosting',
            'title' => $d['title'], 'description' => $desc,
            'identifier' => ['@type' => 'PropertyValue', 'name' => $org['name'], 'value' => $d['code']],
            'datePosted' => get_the_date('Y-m-d', $postId), 'employmentType' => $emp, 'hiringOrganization' => $org,
            'directApply' => true,
        ];
        if ($d['target_date'] && $d['target_date'] >= gmdate('Y-m-d')) $ld['validThrough'] = $d['target_date'] . 'T23:59';
        if (($d['remote_policy'] ?? '') === 'Full Remote') { $ld['jobLocationType'] = 'TELECOMMUTE'; $ld['applicantLocationRequirements'] = ['@type' => 'Country', 'name' => 'IT']; }
        if ($d['location']) $ld['jobLocation'] = ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $d['location'], 'addressCountry' => 'IT']];
        return '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
}
